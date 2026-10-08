"""AI Learning Coach - High Performance Gemini-Only Architecture.

Data-grounded study coaching: every claim must come strictly from the provided
learner profile. Strictly uses Google Gemini (no multi-provider hopping or external AI).
Keys stay server-side.
"""

import asyncio
import logging
from typing import Any

import httpx

from app.core.config import Settings
from app.services.ai_service import AiProviderError

logger = logging.getLogger(__name__)

SYSTEM = """You are the AI Learning Coach for QuizSphere, an adaptive learning platform.
You help students decide what to study, master difficult concepts, and improve weak areas.

GROUNDING & SAFETY RULES:
- Base every claim strictly on the learner profile provided below.
- NEVER state facts or assumptions about the learner that are not present in that profile.
- If data is minimal or absent, state so clearly and give practical, general study advice.
- Refer accurately to recorded mastery percentages when mentioning concepts.
- Keep advice practical, encouraging, and actionable. Use bullet points or short paragraphs.
- Be concise (roughly 100 to 180 words)."""

_RETRYABLE = {429, 500, 502, 503, 504}


def format_gemini_contents(history: list[dict], user_message: str, max_history: int = 6) -> list[dict]:
    """Format conversation turns into valid alternating Gemini contents."""
    turns: list[dict] = []
    sliced = history[-max_history:] if history else []

    for turn in sliced:
        role = "model" if turn.get("role") == "coach" else "user"
        content = str(turn.get("content") or "").strip()
        if content:
            turns.append({"role": role, "parts": [{"text": content[:800]}]})

    turns.append({"role": "user", "parts": [{"text": user_message[:1000]}]})

    valid: list[dict] = []
    for t in turns:
        if not valid and t["role"] != "user":
            continue
        if valid and valid[-1]["role"] == t["role"]:
            valid[-1]["parts"][0]["text"] += "\n\n" + t["parts"][0]["text"]
        else:
            valid.append(t)

    if not valid:
        valid.append({"role": "user", "parts": [{"text": user_message[:1000]}]})

    return valid


def build_lightweight_context(concept_rows: list[dict], recent_attempts: list[dict], profile: dict) -> str:
    """Fast, compact context builder from lightweight DB rows (<250 characters)."""
    total_recent = len(recent_attempts)
    scores = [float(a.get("percent") or 0) for a in recent_attempts]
    avg_score = round(sum(scores) / total_recent) if total_recent else 0
    best_score = round(max(scores)) if scores else 0

    weak: list[str] = []
    dev: list[str] = []
    strong: list[str] = []

    for c in concept_rows:
        name = str(c.get("concept") or "General").strip()
        if not name:
            continue
        mastery = round(float(c.get("mastery") or 0))
        if mastery < 60:
            weak.append(f"{name} ({mastery}%)")
        elif mastery < 80:
            dev.append(f"{name} ({mastery}%)")
        else:
            strong.append(f"{name} ({mastery}%)")

    weak_text = ", ".join(weak[:5]) or "none recorded"
    dev_text = ", ".join(dev[:5]) or "none recorded"
    strong_text = ", ".join(strong[:5]) or "none recorded"

    practice = weak[0] if weak else (dev[0] if dev else "General")
    if "(" in practice:
        practice = practice.split("(")[0].strip()

    return "\n".join(
        [
            f"Recent attempts: {total_recent}",
            f"Average score: {avg_score}%",
            f"Best score: {best_score}%",
            f"Level: {int(profile.get('level') or 1)}",
            f"XP: {int(profile.get('xp') or 0)}",
            f"Weak concepts: {weak_text}",
            f"Developing concepts: {dev_text}",
            f"Strong concepts: {strong_text}",
            f"Recommended practice: {practice}",
        ]
    )


def build_context(analytics: dict, profile: dict) -> str:
    """Backward-compatible full analytics context builder."""
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


class LearningCoach:
    def __init__(self, settings: Settings, http: httpx.AsyncClient):
        self._settings = settings
        self._http = http

    async def respond(
        self, context_text: str, user_message: str, history: list[dict] | None = None
    ) -> dict[str, Any]:
        """Produce a coaching response using Google Gemini exclusively."""
        if not self._settings.GEMINI_API_KEY:
            raise AiProviderError("Gemini is not configured. Please add GEMINI_API_KEY.", False)

        reply = await self._call_gemini(context_text, user_message, history or [])
        reply = reply.strip()
        if not reply:
            raise AiProviderError("The coach returned an empty response.", True)

        return {"reply": reply, "provider": "gemini"}

    async def _call_gemini(self, context_text: str, user_message: str, history: list[dict]) -> str:
        primary_model = self._settings.AI_COACH_MODEL or "gemini-2.5-flash"
        fallback_model = self._settings.AI_COACH_FALLBACK_MODEL or "gemini-flash-latest"

        models = [primary_model]
        if fallback_model and fallback_model != primary_model:
            models.append(fallback_model)

        max_tokens = self._settings.AI_COACH_MAX_OUTPUT_TOKENS or 600
        temperature = self._settings.AI_COACH_TEMPERATURE or 0.5
        timeout = self._settings.AI_COACH_TIMEOUT or 15.0
        max_history = self._settings.AI_COACH_MAX_HISTORY or 6

        contents = format_gemini_contents(history, user_message, max_history)

        payload = {
            "systemInstruction": {
                "parts": [{"text": SYSTEM + "\n\n[LEARNER PROFILE]\n" + context_text + "\n[/LEARNER PROFILE]"}]
            },
            "contents": contents,
            "generationConfig": {
                "temperature": temperature,
                "maxOutputTokens": max_tokens,
                "thinkingConfig": {
                    "thinkingBudget": 0,
                },
            },
        }

        last_exc: Exception | None = None
        for model in models:
            url = (
                "https://generativelanguage.googleapis.com/v1beta/models/"
                + model
                + ":generateContent?key="
                + self._settings.GEMINI_API_KEY
            )

            for attempt in range(2):
                try:
                    resp = await self._http.post(
                        url,
                        json=payload,
                        headers={"Content-Type": "application/json"},
                        timeout=timeout,
                    )
                except httpx.HTTPError as exc:
                    logger.warning("Network error calling Gemini %s: %s", model, exc)
                    last_exc = AiProviderError("The coach is temporarily unavailable.", True)
                    break

                if 200 <= resp.status_code < 300:
                    body = resp.json()
                    for candidate in body.get("candidates") or []:
                        for part in (candidate.get("content") or {}).get("parts") or []:
                            text = part.get("text")
                            if text and text.strip():
                                return text.strip()
                    last_exc = AiProviderError("The coach returned an empty response.", True)
                    break

                if resp.status_code in {429, 503} and attempt == 0:
                    await asyncio.sleep(0.35)
                    continue

                logger.warning("Gemini coach request failed on %s with status %s", model, resp.status_code)
                last_exc = AiProviderError(
                    "The coach is busy right now. Please try again shortly.",
                    resp.status_code in _RETRYABLE,
                )
                break

        if last_exc:
            if isinstance(last_exc, AiProviderError):
                raise last_exc
            raise AiProviderError("The coach is temporarily unavailable.", True) from last_exc

        raise AiProviderError("The coach returned no response.", True)
