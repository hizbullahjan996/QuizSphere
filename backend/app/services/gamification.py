"""Gamification service - port of PHP `GamificationService`.

Transparent XP / level / streak / achievement rules. Data-driven, no AI.
"""

import logging
from datetime import date, timedelta
from typing import Any

from app.repositories.quiz_repository import QuizRepository
from app.utils.supabase import SupabaseClient

logger = logging.getLogger(__name__)

LEVEL_THRESHOLDS = [0, 500, 1000, 1500, 2500, 4000, 6000, 8000, 10000, 12500, 15000]

XP_BASE = 10
XP_PER_PERCENT = 1
XP_PERFECT_BONUS = 15
XP_DAILY_BONUS = 5
XP_IMPROVE_BONUS = 15

MASTERY_IMPROVED = 60.0


def level_info(xp: int) -> dict[str, Any]:
    t = LEVEL_THRESHOLDS
    level = 1
    for i in range(len(t) - 1):
        if xp >= t[i + 1]:
            level = i + 2
        else:
            break

    last_idx = len(t) - 1
    if level > last_idx + 1:
        gap = t[last_idx] - t[last_idx - 1]
        floor = t[last_idx] + ((level - (last_idx + 1)) * gap)
    else:
        floor = t[level - 1]
        nxt = t[level] if level < len(t) else (t[last_idx] + (t[last_idx] - t[last_idx - 1]))
        gap = max(1, nxt - floor)

    xp_into = max(0, xp - floor)
    xp_needed = max(1, gap)
    return {
        "level": level,
        "xp_into": xp_into,
        "xp_for_level": floor,
        "xp_next": max(0, xp_needed - xp_into),
        "progress": min(1.0, xp_into / xp_needed) if xp_needed > 0 else 1.0,
    }


def compute_attempt_xp(score: int, total: int, num_improved: int, streak_increased: bool) -> dict:
    percent = round((score / total) * 100) if total > 0 else 0
    breakdown = {"base": XP_BASE, "accuracy": int(percent)}
    if total > 0 and percent >= 100:
        breakdown["perfect"] = XP_PERFECT_BONUS
    if streak_increased:
        breakdown["daily"] = XP_DAILY_BONUS
    if num_improved > 0:
        breakdown["improvement"] = num_improved * XP_IMPROVE_BONUS
    return {"total": int(sum(breakdown.values())), "breakdown": breakdown}


def next_streak(last_active: str | None) -> dict:
    today = date.today()
    if last_active == today.isoformat():
        return {"streak": 0, "increased": False}
    return {"streak": 1, "increased": True}


class GamificationService:
    def __init__(self, db: SupabaseClient, repo: QuizRepository):
        self._db = db
        self._repo = repo

    async def award_attempt(
        self,
        user_id: str,
        token: str,
        attempt: dict,
        profile: dict,
        attempt_count: int,
        concept_before: list[dict],
    ) -> dict[str, Any]:
        current_xp = int(profile.get("xp") or 0)
        current_level = int(profile.get("level") or 1)
        current_streak = int(profile.get("streak") or 0)
        score = int(attempt.get("score") or 0)
        total = int(attempt.get("total") or 0)
        percent = float(attempt.get("percent") or 0)

        streak_info = next_streak(str(profile["last_active"]) if profile.get("last_active") else None)
        new_streak = current_streak + 1 if streak_info["increased"] else current_streak

        num_improved = await self._count_improved_concepts(user_id, token, concept_before)

        xp = compute_attempt_xp(score, total, num_improved, streak_info["increased"])
        new_xp = current_xp + xp["total"]
        info = level_info(new_xp)
        leveled_up = info["level"] > current_level

        try:
            await self._db.update(
                "profiles",
                {
                    "xp": new_xp,
                    "level": info["level"],
                    "streak": new_streak,
                    "last_active": date.today().isoformat(),
                },
                {"id": f"eq.{user_id}"},
                token=token,
            )
        except Exception as exc:
            logger.warning("Could not persist gamification update for %s: %s", user_id, exc)

        state = {
            "attempt_count": attempt_count,
            "this_percent": percent,
            "score": score,
            "total": total,
            "streak": new_streak,
            "num_improved": num_improved,
        }
        new_achievements = await self.grant_eligible(user_id, token, state)

        return {
            "xp_earned": xp["total"],
            "xp_breakdown": xp["breakdown"],
            "level": info["level"],
            "leveled_up": leveled_up,
            "level_info": info,
            "streak": new_streak,
            "streak_increased": streak_info["increased"],
            "num_improved": num_improved,
            "new_achievements": new_achievements,
        }

    async def _count_improved_concepts(
        self, user_id: str, token: str, concept_before: list[dict]
    ) -> int:
        try:
            after = await self._repo.list_concept_performance(user_id, token)
        except Exception:
            return 0
        if not concept_before:
            return 0

        before = {
            str(c.get("concept") or "General"): {
                "attempts": int(c.get("attempts") or 0),
                "mastery": float(c.get("mastery") or 0),
            }
            for c in concept_before
        }

        improved = 0
        for c in after:
            name = str(c.get("concept") or "General")
            prev = before.get(name)
            if prev is None:
                continue
            was_weak = prev["mastery"] < MASTERY_IMPROVED
            now_strong = float(c.get("mastery") or 0) >= MASTERY_IMPROVED
            touched = int(c.get("attempts") or 0) > prev["attempts"]
            if was_weak and now_strong and touched:
                improved += 1
        return improved

    async def _catalogue(self, token: str, order: str | None = None) -> list[dict]:
        try:
            return await self._db.select(
                "achievements",
                columns="id, code, name, icon, description",
                **({"order": order} if order else {}),
                token=token,
            )
        except Exception:
            return []

    async def _owned_codes(self, user_id: str, token: str, catalogue: list[dict]) -> dict[str, bool]:
        id_to_code = {str(a.get("id") or ""): str(a.get("code") or "") for a in catalogue}
        owned: dict[str, bool] = {}
        try:
            granted = await self._db.select(
                "user_achievements",
                columns="achievement_id",
                filters={"user_id": f"eq.{user_id}"},
                token=token,
            )
        except Exception:
            return owned
        for g in granted:
            code = id_to_code.get(str(g.get("achievement_id") or ""))
            if code:
                owned[code] = True
        return owned

    async def grant_eligible(self, user_id: str, token: str, state: dict) -> list[dict]:
        catalogue = await self._catalogue(token)
        owned = await self._owned_codes(user_id, token, catalogue)

        new_ones = []
        for a in catalogue:
            code = str(a.get("code") or "")
            if code == "" or code in owned:
                continue
            if not self._is_met(code, state):
                continue
            try:
                await self._db.insert(
                    "user_achievements",
                    {"user_id": user_id, "achievement_id": a.get("id")},
                    token=token,
                )
            except Exception:
                continue
            new_ones.append(
                {
                    "code": code,
                    "name": str(a.get("name") or code),
                    "icon": str(a.get("icon") or "bi-trophy"),
                    "description": str(a.get("description") or ""),
                }
            )
        return new_ones

    async def grant_by_code(self, user_id: str, token: str, code: str) -> dict | None:
        catalogue = await self._catalogue(token)
        for a in catalogue:
            if str(a.get("code") or "") != code:
                continue
            try:
                await self._db.insert(
                    "user_achievements",
                    {"user_id": user_id, "achievement_id": a.get("id")},
                    token=token,
                )
            except Exception:
                return None
            return {
                "code": code,
                "name": str(a.get("name") or code),
                "icon": str(a.get("icon") or "bi-trophy"),
                "description": str(a.get("description") or ""),
            }
        return None

    async def catalogue_with_state(self, user_id: str, token: str) -> dict:
        all_rows = await self._catalogue(token, order="created_at.asc")
        if not all_rows:
            return {"all": [], "unlocked": []}
        owned = await self._owned_codes(user_id, token, all_rows)
        unlocked = []
        for a in all_rows:
            a = dict(a)
            a["unlocked"] = bool(owned.get(str(a.get("code") or "")))
            if a["unlocked"]:
                unlocked.append(a)
        return {"all": all_rows, "unlocked": unlocked}

    @staticmethod
    def _is_met(code: str, state: dict) -> bool:
        if code == "first_quiz":
            return int(state.get("attempt_count") or 0) >= 1
        if code == "quiz_master":
            return int(state.get("attempt_count") or 0) >= 10
        if code == "percent_90":
            return float(state.get("this_percent") or 0) >= 90
        if code == "perfect_score":
            return int(state.get("score") or 0) > 0 and float(state.get("this_percent") or 0) >= 100
        if code == "streak_7":
            return int(state.get("streak") or 0) >= 7
        if code == "improvement_champion":
            return int(state.get("num_improved") or 0) >= 1
        return False
