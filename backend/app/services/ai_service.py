"""AI service - port of PHP `AiService` + `GeminiProvider` + `GroqProvider`.

Gemini primary, Groq fallback, bounded retries, validation on every response.
Keys are injected from settings server-side and never leave the server.
"""

import json
import logging
import re
from typing import Any

import httpx

from app.core.config import Settings
from app.services import ai_prompt_builder, ai_response_validator

logger = logging.getLogger(__name__)

MAX_PROVIDER_RETRIES = 2
AI_TIMEOUT = 25.0
OVERALL_TIMEOUT = 60.0
_RETRYABLE = {429, 500, 502, 503, 504}


class AiProviderError(Exception):
    def __init__(self, message: str, retryable: bool = False, timed_out: bool = False):
        super().__init__(message)
        self.message = message
        self.retryable = retryable
        self.timed_out = timed_out


def _parse_questions(text: str) -> list:
    text = text.strip()
    try:
        payload = json.loads(text)
    except ValueError:
        payload = None
    if not isinstance(payload, list):
        m = re.search(r"\[.*\]", text, re.S)
        if m:
            try:
                payload = json.loads(m.group(0))
            except ValueError:
                payload = None
    if not isinstance(payload, list):
        logger.error("AI returned unparseable JSON: %.500s", text)
        raise AiProviderError("The AI returned an invalid response. Please try again.", True)
    return payload


class GeminiProvider:
    name = "gemini"

    def __init__(self, settings: Settings, http: httpx.AsyncClient):
        self._settings = settings
        self._http = http

    async def generate_quiz(self, request: dict, timeout: float = AI_TIMEOUT) -> list:
        settings = self._settings
        if not settings.GEMINI_API_KEY:
            raise AiProviderError("Gemini is not configured.", False)

        url = (
            "https://generativelanguage.googleapis.com/v1beta/models/"
            + settings.GEMINI_MODEL
            + ":generateContent?key="
            + settings.GEMINI_API_KEY
        )
        prompt = ai_prompt_builder.build_prompt(
            request["topic"],
            request["difficulty"],
            request["num_questions"],
            request.get("source_text"),
        )
        payload = {
            "contents": [{"role": "user", "parts": [{"text": prompt}]}],
            "generationConfig": {
                "temperature": 0.7,
                "maxOutputTokens": 8192,
                "responseMimeType": "application/json",
            },
        }
        try:
            resp = await self._http.post(
                url, json=payload, headers={"Content-Type": "application/json"}, timeout=timeout
            )
        except httpx.HTTPError as exc:
            raise AiProviderError("AI service is temporarily unavailable.", True, timed_out=True) from exc

        if not (200 <= resp.status_code < 300):
            retryable = resp.status_code in _RETRYABLE
            logger.error("Gemini request failed: %s %.300s", resp.status_code, resp.text)
            raise AiProviderError(
                "The AI quiz generator is busy right now. Please try again shortly.", retryable
            )

        body = resp.json()
        text = None
        for candidate in body.get("candidates") or []:
            for part in (candidate.get("content") or {}).get("parts") or []:
                if part.get("text"):
                    text = part["text"]
                    break
            if text:
                break
        if not text:
            logger.error("Gemini returned no usable text")
            raise AiProviderError("The AI returned an empty response. Please retry.", True)
        return _parse_questions(text)


class GroqProvider:
    name = "groq"

    def __init__(self, settings: Settings, http: httpx.AsyncClient):
        self._settings = settings
        self._http = http

    async def generate_quiz(self, request: dict, timeout: float = AI_TIMEOUT) -> list:
        settings = self._settings
        if not settings.GROQ_API_KEY:
            raise AiProviderError("Groq fallback is not configured.", False)

        prompt = ai_prompt_builder.build_prompt(
            request["topic"],
            request["difficulty"],
            request["num_questions"],
            request.get("source_text"),
        )
        payload = {
            "model": settings.GROQ_MODEL,
            "messages": [
                {"role": "system", "content": "You output ONLY valid JSON arrays and nothing else."},
                {"role": "user", "content": prompt},
            ],
            "temperature": 0.7,
            "max_tokens": 3072,
        }
        try:
            resp = await self._http.post(
                "https://api.groq.com/openai/v1/chat/completions",
                json=payload,
                headers={
                    "Content-Type": "application/json",
                    "Authorization": "Bearer " + settings.GROQ_API_KEY,
                },
                timeout=timeout,
            )
        except httpx.HTTPError as exc:
            raise AiProviderError("AI service is temporarily unavailable.", True, timed_out=True) from exc

        if not (200 <= resp.status_code < 300):
            retryable = resp.status_code in _RETRYABLE
            logger.error("Groq request failed: %s %.300s", resp.status_code, resp.text)
            raise AiProviderError(
                "The AI quiz generator is busy right now. Please try again shortly.", retryable
            )

        body = resp.json()
        content = ((body.get("choices") or [{}])[0].get("message") or {}).get("content")
        if not isinstance(content, str) or content.strip() == "":
            logger.error("Groq returned no content")
            raise AiProviderError("The AI returned an empty response. Please retry.", True)
        return _parse_questions(content)


class AiService:
    def __init__(self, settings: Settings, http: httpx.AsyncClient):
        self._settings = settings
        self._http = http
        self.attempted: list[str] = []

    def _providers(self):
        providers = []
        if self._settings.GEMINI_API_KEY:
            providers.append(GeminiProvider(self._settings, self._http))
        if self._settings.GROQ_API_KEY:
            providers.append(GroqProvider(self._settings, self._http))
        return providers

    async def generate_quiz(self, request: dict) -> dict:
        providers = self._providers()
        if not providers:
            raise AiProviderError(
                "AI quiz generation is not configured yet. Please add your AI API keys in config/env.php."
            )

        # Shared retry budget that ROTATES across providers: instead of exhausting
        # every retry on a slow/hung primary (which used to block for up to
        # MAX_PROVIDER_RETRIES * AI_TIMEOUT before even trying the fallback), each
        # attempt tries the next provider in turn. Combined with the overall
        # deadline this guarantees a bounded, near-best-case generation time.
        import time

        attempts = MAX_PROVIDER_RETRIES * len(providers)
        deadline = time.monotonic() + OVERALL_TIMEOUT
        last_error: AiProviderError | None = None

        for i in range(attempts):
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                break
            provider = providers[i % len(providers)]
            self.attempted.append(provider.name)
            try:
                questions = await self._generate_from_provider(
                    provider, request, timeout=min(AI_TIMEOUT, remaining)
                )
                problems = ai_response_validator.validate(questions, request["num_questions"])
                if problems:
                    logger.warning("Provider returned invalid questions: %s", problems)
                    last_error = AiProviderError("AI returned invalid questions.", True)
                    continue
                return {"questions": questions, "provider": provider.name}
            except AiProviderError as exc:
                last_error = exc

        raise AiProviderError(
            last_error.message if last_error else "Unable to generate a quiz right now. Please try again later.",
            False,
        )

    async def _generate_from_provider(self, provider, request: dict, timeout: float) -> list:
        import asyncio

        for attempt in range(1, MAX_PROVIDER_RETRIES + 1):
            try:
                raw = await provider.generate_quiz(request, timeout=timeout)
                problems = ai_response_validator.validate(raw, request["num_questions"])
                if problems:
                    logger.warning("Provider validation failed (attempt %s): %s", attempt, problems)
                    if attempt < MAX_PROVIDER_RETRIES:
                        await asyncio.sleep(0.3)
                        continue
                    raise AiProviderError("AI returned invalid questions.", True)
                return ai_response_validator.sanitize(raw)
            except AiProviderError as exc:
                logger.warning("Provider call failed (attempt %s): %s", attempt, exc.retryable)
                # A timed-out (hung) provider should not be retried in place - retrying
                # would just block another full timeout. Fail over to the next provider.
                if exc.retryable and not exc.timed_out and attempt < MAX_PROVIDER_RETRIES:
                    await asyncio.sleep(0.4)
                    continue
                raise
        raise AiProviderError("AI generation failed.", True)
