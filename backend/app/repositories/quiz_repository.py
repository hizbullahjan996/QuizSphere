"""Quiz repository - Python port of PHP `QuizRepository`.

All quiz / question / attempt / answer data access against Supabase
(PostgREST). The caller passes the authenticated user's access token so Row
Level Security applies to the user's own rows; privileged bootstrap writes
use the service role, exactly as in PHP.

Table names, column names, filters, ordering and business rules (server-side
grading, correct answers never returned to clients, cumulative concept
performance) are preserved 1:1.
"""

from datetime import datetime, timezone
from typing import Any

from app.utils.supabase import SupabaseClient

UUID_RE_LEN = 36  # matches PHP's /^[0-9a-fA-F-]{36}$/ pre-checks done in routes


class QuizRepository:
    def __init__(self, db: SupabaseClient):
        self._db = db

    # ------------------------------------------------------------------
    # Write side
    # ------------------------------------------------------------------

    async def save_generated_quiz(
        self,
        user_id: str,
        title: str,
        topic: str,
        difficulty: str,
        question_type: str,
        ai_provider: str,
        questions: list[dict[str, Any]],
        token: str | None = None,
    ) -> dict[str, Any]:
        """Persist a generated quiz and its questions (RLS-aware)."""
        quiz = await self._db.insert(
            "quizzes",
            {
                "user_id": user_id,
                "title": title,
                "topic": topic,
                "difficulty": difficulty,
                "question_count": len(questions),
                "question_type": question_type,
                "ai_provider": ai_provider,
                "is_public": False,
            },
            token=token,
        )
        quiz_id = quiz.get("id")
        if not quiz_id:
            raise ValueError("Could not save the quiz. Please try again.")

        saved_questions = []
        for i, q in enumerate(questions):
            row = await self._db.insert(
                "questions",
                {
                    "quiz_id": quiz_id,
                    "position": i,
                    "body": q["question"],
                    "options": q["options"],
                    "correct_index": q["correct"],
                    "concept": q.get("concept") or "General",
                    "explanation": q.get("explanation") or "",
                },
                token=token,
            )
            saved_questions.append(
                {
                    "id": row.get("id"),
                    "position": i,
                    "body": q["question"],
                    "options": q["options"],
                    "concept": q.get("concept") or "General",
                    "explanation": q.get("explanation") or "",
                }
            )

        return {"quiz": quiz, "questions": saved_questions}

    async def submit_attempt(
        self,
        user_id: str,
        quiz_id: str,
        ai_provider: str,
        answers: list[dict[str, Any]],
        token: str | None = None,
    ) -> dict[str, Any]:
        """Grade server-side and persist attempt + answers + concept stats."""
        source = await self._load_quiz_for_grading(user_id, quiz_id, token)
        if source is None:
            raise LookupError("Quiz not found.")
        questions = source["questions"]
        total = len(questions)

        score = 0
        correct: list[str] = []
        incorrect: list[str] = []
        normalised: list[dict[str, Any]] = []

        for ans in answers:
            qid = ans.get("question_id")
            selected = int(ans.get("selected", -1))
            q = questions.get(qid)
            if not q:
                continue
            is_correct = selected == q["correct_index"]
            normalised.append(
                {"question_id": qid, "selected_index": selected, "is_correct": is_correct}
            )
            if is_correct:
                score += 1
                correct.append(qid)
            else:
                incorrect.append(qid)

        percent = round((score / total) * 100, 2) if total > 0 else 0.0

        attempt = await self._db.insert(
            "quiz_attempts",
            {
                "quiz_id": quiz_id,
                "user_id": user_id,
                "score": score,
                "total": total,
                "percent": percent,
                "ai_provider": ai_provider,
                "completed_at": datetime.now(timezone.utc).isoformat(),
            },
            token=token,
        )
        attempt_id = attempt.get("id")
        if not attempt_id:
            raise ValueError("Could not save your attempt. Please try again.")

        for a in normalised:
            await self._db.insert(
                "answers",
                {
                    "attempt_id": attempt_id,
                    "question_id": a["question_id"],
                    "user_id": user_id,
                    "selected_index": a["selected_index"],
                    "is_correct": a["is_correct"],
                },
                token=token,
            )

        await self._update_concept_performance(user_id, questions, answers, token)

        return {
            "attempt": attempt,
            "score": score,
            "total": total,
            "percent": percent,
            "correct": correct,
            "incorrect": incorrect,
        }

    # ------------------------------------------------------------------
    # Read side
    # ------------------------------------------------------------------

    async def find_quiz_for_user(
        self, user_id: str, quiz_id: str, token: str | None = None
    ) -> dict[str, Any] | None:
        """Quiz + questions WITHOUT correct answers (client-safe)."""
        quizzes = await self._db.select(
            "quizzes",
            columns="*",
            filters={"id": f"eq.{quiz_id}", "user_id": f"eq.{user_id}"},
            limit=1,
            token=token,
        )
        if not quizzes:
            return None
        quiz = quizzes[0]

        rows = await self._db.select(
            "questions",
            columns="id, position, body, options, concept",
            filters={"quiz_id": f"eq.{quiz_id}"},
            order="position.asc",
            token=token,
        )
        questions = [
            {
                "id": r.get("id"),
                "position": int(r.get("position") or 0),
                "body": r.get("body") or "",
                "options": r.get("options") or [],
                "concept": r.get("concept") or "General",
            }
            for r in rows
        ]
        return {"quiz": quiz, "questions": questions}

    async def list_user_quizzes(
        self, user_id: str, token: str | None = None, limit: int = 20
    ) -> list[dict[str, Any]]:
        return await self._db.select(
            "quizzes",
            columns=(
                "id, title, topic, difficulty, question_count, question_type, "
                "ai_provider, created_at, updated_at"
            ),
            filters={"user_id": f"eq.{user_id}"},
            order="created_at.desc",
            limit=limit,
            token=token,
        )

    async def list_user_attempts(
        self, user_id: str, token: str | None = None, limit: int = 200
    ) -> list[dict[str, Any]]:
        return await self._db.select(
            "quiz_attempts",
            columns="id, quiz_id, percent, score, total, completed_at",
            filters={"user_id": f"eq.{user_id}"},
            order="completed_at.desc",
            limit=limit,
            token=token,
        )

    async def list_concept_performance(
        self, user_id: str, token: str | None = None
    ) -> list[dict[str, Any]]:
        return await self._db.select(
            "concept_performance",
            columns="id, concept, attempts, correct, mastery, updated_at",
            filters={"user_id": f"eq.{user_id}"},
            order="mastery.asc",
            token=token,
        )

    async def find_attempt_for_user(
        self, user_id: str, attempt_id: str, token: str | None = None
    ) -> dict[str, Any] | None:
        attempts = await self._db.select(
            "quiz_attempts",
            columns="*",
            filters={"id": f"eq.{attempt_id}", "user_id": f"eq.{user_id}"},
            limit=1,
            token=token,
        )
        if not attempts:
            return None
        attempt = attempts[0]

        answer_rows = await self._db.select(
            "answers",
            columns="question_id, selected_index, is_correct",
            filters={"attempt_id": f"eq.{attempt_id}"},
            token=token,
        )
        answers = {
            r["question_id"]: {
                "selected_index": int(r.get("selected_index") or 0),
                "is_correct": bool(r.get("is_correct")),
            }
            for r in answer_rows
        }
        return {"attempt": attempt, "quiz": None, "answers": answers}

    async def load_full_detail_for_review(
        self, user_id: str, result: dict[str, Any], token: str | None = None
    ) -> dict[str, Any] | None:
        quiz_id = (result.get("attempt") or {}).get("quiz_id")
        attempt_id = (result.get("attempt") or {}).get("id")
        if not quiz_id or not attempt_id:
            return None

        source = await self._load_quiz_for_grading(user_id, quiz_id, token)
        if source is None:
            return None

        answer_rows = await self._db.select(
            "answers",
            columns="question_id, selected_index, is_correct",
            filters={"attempt_id": f"eq.{attempt_id}"},
            token=token,
        )
        by_question = {r["question_id"]: int(r.get("selected_index") or 0) for r in answer_rows}

        detail = []
        for q in source["questions"].values():
            selected = by_question.get(q["id"], -1)
            detail.append(
                {
                    "question": q["body"],
                    "options": q["options"],
                    "selected": selected,
                    "correct": q["correct_index"],
                    "is_correct": selected == q["correct_index"],
                    "explanation": q.get("explanation") or "",
                    "concept": q.get("concept") or "General",
                }
            )
        return {
            "quiz_title": source["quiz"].get("title") or "Quiz",
            "topic": source["quiz"].get("topic") or "",
            "detail": detail,
        }

    async def load_detail_for_attempt(
        self,
        user_id: str,
        attempt_id: str,
        quiz_id: str,
        token: str | None = None,
    ) -> dict[str, Any] | None:
        source = await self._load_quiz_for_grading(user_id, quiz_id, token)
        if source is None:
            return None

        answer_rows = await self._db.select(
            "answers",
            columns="question_id, selected_index",
            filters={"attempt_id": f"eq.{attempt_id}"},
            token=token,
        )
        by_question = {r["question_id"]: int(r.get("selected_index") or 0) for r in answer_rows}

        detail = []
        for q in source["questions"].values():
            selected = by_question.get(q["id"], -1)
            detail.append(
                {
                    "question": q["body"],
                    "options": q["options"],
                    "selected": selected,
                    "correct": q["correct_index"],
                    "is_correct": selected == q["correct_index"],
                    "explanation": q.get("explanation") or "",
                    "concept": q.get("concept") or "General",
                }
            )
        return {
            "quiz_title": source["quiz"].get("title") or "Quiz",
            "topic": source["quiz"].get("topic") or "",
            "detail": detail,
        }

    # ------------------------------------------------------------------
    # Internals
    # ------------------------------------------------------------------

    async def _load_quiz_for_grading(
        self, user_id: str, quiz_id: str, token: str | None
    ) -> dict[str, Any] | None:
        """Questions WITH correct answers - server-side grading only."""
        quizzes = await self._db.select(
            "quizzes",
            columns="*",
            filters={"id": f"eq.{quiz_id}", "user_id": f"eq.{user_id}"},
            limit=1,
            token=token,
        )
        if not quizzes:
            return None
        rows = await self._db.select(
            "questions",
            columns="id, quiz_id, position, body, options, correct_index, concept, explanation",
            filters={"quiz_id": f"eq.{quiz_id}"},
            order="position.asc",
            token=token,
        )
        questions = {
            r["id"]: {
                "id": r["id"],
                "body": r.get("body"),
                "options": r.get("options"),
                "correct_index": int(r.get("correct_index") or 0),
                "concept": r.get("concept"),
                "explanation": r.get("explanation"),
            }
            for r in rows
        }
        return {"quiz": quizzes[0], "questions": questions}

    async def _update_concept_performance(
        self,
        user_id: str,
        questions: dict[str, dict[str, Any]],
        answers: list[dict[str, Any]],
        token: str | None,
    ) -> None:
        answered = {a.get("question_id"): int(a.get("selected", -1)) for a in answers}

        by_concept: dict[str, dict[str, int]] = {}
        for qid, q in questions.items():
            concept = q.get("concept") or "General"
            stats = by_concept.setdefault(concept, {"attempts": 0, "correct": 0})
            stats["attempts"] += 1
            if answered.get(qid, -1) == q["correct_index"]:
                stats["correct"] += 1

        for concept, stats in by_concept.items():
            rows = await self._db.select(
                "concept_performance",
                columns="id, attempts, correct",
                filters={"user_id": f"eq.{user_id}", "concept": f"eq.{concept}"},
                limit=1,
                token=token,
            )
            prev_attempts = int(rows[0]["attempts"]) if rows else 0
            prev_correct = int(rows[0]["correct"]) if rows else 0

            total_attempts = prev_attempts + stats["attempts"]
            total_correct = prev_correct + stats["correct"]
            mastery = round((total_correct / total_attempts) * 100, 2) if total_attempts else 0

            if rows:
                await self._db.update(
                    "concept_performance",
                    {"attempts": total_attempts, "correct": total_correct, "mastery": mastery},
                    {"user_id": f"eq.{user_id}", "concept": f"eq.{concept}"},
                    token=token,
                )
            else:
                await self._db.insert(
                    "concept_performance",
                    {
                        "user_id": user_id,
                        "concept": concept,
                        "attempts": total_attempts,
                        "correct": total_correct,
                        "mastery": mastery,
                    },
                    token=token,
                )
