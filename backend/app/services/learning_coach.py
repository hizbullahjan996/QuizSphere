"""AI Learning Coach - port of PHP `LearningCoach`.

Data-grounded coaching: every claim must come from the provided learner
profile. Gemini primary, Groq fallback. Keys stay server-side.
"""

import logging
from typing import Any

import httpx

from app.core.config import Settings
from app.services.ai_service import AiProviderError

logger = logging.getLogger(__name__)

SYSTEM = """You are the AI Learning Coach for an adaptive quiz platform called QuizSphere.
You help students figure out what to study, which concepts need improvement,
how to plan their practice, and you can explain difficult concepts.

IMPORTANT GROUNDING RULES:
- Base every claim ONLY on the learner profile provided below.
- NEVER state facts about the learner that are not present in that profile.
- If the profile has little or no performance data, say so clearly and give a
  general, helpful recommendation instead of inventing specifics.
- Do not claim the student is weak or strong in any concept unless the data
  explicitly shows it (use the mastery percentages provided).
- Keep advice practical, encouraging and actionable. Use short paragraphs or
  bullet lists. Be concise (roughly 120-200 words)."""

_RETRYABLE = {429, 500, 502, 503, 504}


def build_context(analytics: dict, profile: dict) -> str:
    def names(rows):
        return ", ".join(f"{c['concept']} ({round(float(c['mastery']))}%)" for c in rows) or "none recorded"

    rec = analytics.get("recommendations") or {}
    practice = rec.get("practice_concept") or "not yet available"
    return "\n".join(
        [
            f"Total attempts: {int(analytics.get('total_attempts') or 0)}",
            f"Average score: {round(float(analytics.get('average_score') or 0))}%",
            f"Best score: {round(float(analytics.get('best_score') or 0))}%",
            f"Level: {int(profile.get('level') or 1)}",
            f"XP: {int(profile.get('xp') or 0)}",
            f"Weak concepts: {names(analytics.get('weak') or [])}",
            f"Developing concepts: {names(analytics.get('developing') or [])}",
            f"Strong concepts: {names(analytics.get('strong') or [])}",
            f"Recommended practice: {practice}",
        ]
    )


def _build_prompt(context_text: str, user_message: str, history: list[dict]) -> str:
    dialogue = ""
    for turn in history:
        role = "Coach" if (turn.get("role") or "user") == "coach" else "Student"
        dialogue += f"{role}: {turn.get('content') or ''}\n"
    return (
        f"[LEARNER PROFILE]\n{context_text}\n[/LEARNER PROFILE]\n\n"
        "You are the QuizSphere AI Learning Coach. Use ONLY the learner profile above.\n"
        + (f"Prior conversation:\n{dialogue}\n" if dialogue else "")
        + f"Student: {user_message}\nCoach:"
    )


class LearningCoach:
    def __init__(self, settings: Settings, http: httpx.AsyncClient):
        self._settings = settings
        self._http = http

    async def respond(
        self, context_text: str, user_message: str, history: list[dict] | None = None
    ) -> dict[str, Any]:
        history = history or []
        last_error: AiProviderError | None = None
        if self._settings.GEMINI_API_KEY:
            try:
                return {"reply": await self._call_gemini(context_text, user_message, history), "provider": "gemini"}
            except AiProviderError as exc:
                last_error = exc
        if self._settings.GROQ_API_KEY:
            try:
                return {"reply": await self._call_groq(context_text, user_message, history), "provider": "groq"}
            except AiProviderError as exc:
                last_error = exc
        if last_error is None:
            raise AiProviderError("AI is not configured. Cannot start the coach.")
        raise last_error

    async def _call_gemini(self, context_text: str, user_message: str, history: list[dict]) -> str:
        url = (
            "https://generativelanguage.googleapis.com/v1beta/models/"
            + self._settings.GEMINI_MODEL
            + ":generateContent?key="
            + self._settings.GEMINI_API_KEY
        )
        payload = {
            "systemInstruction": {"parts": [{"text": SYSTEM}]},
            "contents": [{"role": "user", "parts": [{"text": _build_prompt(context_text, user_message, history)}]}],
            "generationConfig": {"temperature": 0.6, "maxOutputTokens": 1024},
        }
        try:
            resp = await self._http.post(url, json=payload, headers={"Content-Type": "application/json"}, timeout=45.0)
        except httpx.HTTPError as exc:
            raise AiProviderError("The coach is temporarily unavailable.", True) from exc
        if not (200 <= resp.status_code < 300):
            raise AiProviderError(
                "The coach is busy right now. Please try again shortly.",
                resp.status_code in _RETRYABLE,
            )
        body = resp.json()
        for candidate in body.get("candidates") or []:
            for part in (candidate.get("content") or {}).get("parts") or []:
                if part.get("text"):
                    reply = part["text"].strip()
                    if reply:
                        return reply
        raise AiProviderError("The coach returned no response.", True)

    async def _call_groq(self, context_text: str, user_message: str, history: list[dict]) -> str:
        messages = [
            {"role": "system", "content": SYSTEM + "\n\n[LEARNER PROFILE]\n" + context_text + "\n[/LEARNER PROFILE]"}
        ]
        for turn in history:
            role = "assistant" if (turn.get("role") or "user") == "coach" else "user"
            messages.append({"role": role, "content": str(turn.get("content") or "")})
        messages.append({"role": "user", "content": user_message})

        payload = {
            "model": self._settings.GROQ_MODEL,
            "messages": messages,
            "temperature": 0.6,
            "max_tokens": 1024,
        }
        try:
            resp = await self._http.post(
                "https://api.groq.com/openai/v1/chat/completions",
                json=payload,
                headers={
                    "Content-Type": "application/json",
                    "Authorization": "Bearer " + self._settings.GROQ_API_KEY,
                },
                timeout=45.0,
            )
        except httpx.HTTPError as exc:
            raise AiProviderError("The coach is temporarily unavailable.", True) from exc
        if not (200 <= resp.status_code < 300):
            raise AiProviderError(
                "The coach is busy right now. Please try again shortly.",
                resp.status_code in _RETRYABLE,
            )
        body = resp.json()
        content = ((body.get("choices") or [{}])[0].get("message") or {}).get("content")
        if not isinstance(content, str) or not content.strip():
            raise AiProviderError("The coach returned no response.", True)
        return content.strip()
