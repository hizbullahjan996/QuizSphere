"""Performance analytics - port of PHP `PerformanceAnalytics`.

Rule-based, explainable analysis: mastery classification, weekly trend,
adaptive difficulty suggestions, recommendations and insights.
"""

from datetime import date, datetime, timezone
from typing import Any

from app.repositories.quiz_repository import QuizRepository

WEAK_MAX = 60.0
STRONG_MIN = 80.0
BUMP_THRESHOLD = 85.0
LEVEL_STEP = 60.0


def _month_day_label(d: date) -> str:
    import calendar

    return f"{calendar.month_abbr[d.month]} {d.day}"


def _parse_dt(value: Any) -> datetime:
    try:
        return datetime.fromisoformat(str(value).replace("Z", "+00:00"))
    except Exception:
        return datetime.now(timezone.utc)


class PerformanceAnalytics:
    def __init__(self, repo: QuizRepository):
        self._repo = repo

    async def build(self, user_id: str, token: str | None, weeks: int = 6) -> dict[str, Any]:
        attempts: list[dict] = []
        concepts: list[dict] = []
        try:
            attempts = await self._repo.list_user_attempts(user_id, token)
            concepts = await self._repo.list_concept_performance(user_id, token)
        except Exception:
            pass

        scores = [float(a.get("percent") or 0) for a in attempts]
        total_attempts = len(attempts)
        average_score = round(sum(scores) / total_attempts, 2) if total_attempts else 0.0
        best_score = round(max(scores), 2) if scores else 0.0

        trend = self._weekly_trend(attempts, weeks)
        concept = self._build_concepts(concepts)

        return {
            "total_attempts": total_attempts,
            "average_score": average_score,
            "best_score": best_score,
            "trend": trend,
            "concepts": concept["all"],
            "weak": concept["weak"],
            "developing": concept["developing"],
            "strong": concept["strong"],
            "recommendations": self._recommendations(concept, total_attempts),
            "insights": self._insights(concept, average_score, total_attempts),
        }

    def _weekly_trend(self, attempts: list[dict], weeks: int) -> list[dict]:
        grain: dict[str, dict] = {}
        for a in attempts:
            day = _parse_dt(a.get("completed_at")).date().isoformat()
            g = grain.setdefault(day, {"sum": 0.0, "n": 0})
            g["sum"] += float(a.get("percent") or 0)
            g["n"] += 1

        ordered = sorted(grain.keys())
        series = []
        for day in ordered[-(weeks * 7):]:
            d = date.fromisoformat(day)
            series.append(
                {"label": _month_day_label(d), "score": round(grain[day]["sum"] / grain[day]["n"], 2)}
            )
        return series

    def _build_concepts(self, concepts: list[dict]) -> dict[str, list]:
        all_rows, weak, developing, strong = [], [], [], []
        for c in concepts:
            name = str(c.get("concept") or "General")
            attempts = int(c.get("attempts") or 0)
            correct = int(c.get("correct") or 0)
            mastery = float(c.get("mastery") or 0)
            status = self.classify(mastery)
            row = {
                "concept": name,
                "attempts": attempts,
                "correct": correct,
                "mastery": mastery,
                "status": status,
                "suggested_difficulty": self.suggested_difficulty(mastery),
            }
            all_rows.append(row)
            (weak if status == "weak" else developing if status == "developing" else strong).append(row)
        return {"all": all_rows, "weak": weak, "developing": developing, "strong": strong}

    @staticmethod
    def classify(mastery: float) -> str:
        if mastery < WEAK_MAX:
            return "weak"
        if mastery < STRONG_MIN:
            return "developing"
        return "strong"

    @staticmethod
    def suggested_difficulty(mastery: float) -> str:
        if mastery >= BUMP_THRESHOLD:
            return "hard"
        if mastery < LEVEL_STEP:
            return "easy"
        return "medium"

    @staticmethod
    def _recommendations(concept: dict, total_attempts: int) -> dict:
        target = concept["weak"][0] if concept["weak"] else (
            concept["developing"][0] if concept["developing"] else None
        )
        if target is None:
            return {
                "suggestion": "start" if total_attempts == 0 else "maintain",
                "practice_concept": None,
                "suggested_difficulty": "medium",
                "reason": (
                    "Take your first quiz to unlock personalized practice recommendations."
                    if total_attempts == 0
                    else "Great progress! Keep practising to keep every concept sharp."
                ),
            }
        return {
            "suggestion": "practice",
            "practice_concept": target["concept"],
            "suggested_difficulty": target["suggested_difficulty"],
            "reason": (
                "Focus on this weak area to build a solid foundation."
                if target["status"] == "weak"
                else "This concept is developing — practice to push it into a strength."
            ),
        }

    @staticmethod
    def _insights(concept: dict, average_score: float, total_attempts: int) -> list[str]:
        if total_attempts == 0:
            return ["Complete your first quiz to start tracking your progress."]
        out = []
        if average_score >= STRONG_MIN:
            out.append(
                f"Your average score ({round(average_score)}%) is strong — try harder material to keep growing."
            )
        elif average_score >= WEAK_MAX:
            out.append(
                f"Your average score ({round(average_score)}%) is solid — a little targeted practice will unlock your strongest results."
            )
        else:
            out.append(
                f"Your average score ({round(average_score)}%) suggests focusing on foundational concepts first."
            )
        if len(concept["strong"]) > len(concept["weak"]) and concept["strong"]:
            out.append(
                f"You have {len(concept['strong'])} strong area(s) — you can safely level up difficulty there."
            )
        if concept["weak"]:
            out.append(
                f"You have {len(concept['weak'])} weak area(s) — start with "
                f"\"{concept['weak'][0]['concept']}\" to improve fastest."
            )
        return out
