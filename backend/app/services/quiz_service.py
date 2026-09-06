"""Quiz service - port of PHP `QuizGenerator` + `QuizRequest` + practice rules.

Orchestrates: source cleaning + readability gate -> request validation ->
AI generation (Gemini/Groq) -> persistence via QuizRepository.
"""

from typing import Any

from app.core.errors import APIError
from app.repositories.quiz_repository import QuizRepository
from app.services.ai_service import AiProviderError, AiService
from app.services.study_material_cleaner import StudyMaterialCleaner
from app.utils.supabase import SupabaseClient

MIN_QUESTIONS = 1
MAX_QUESTIONS = 15


def validate_request(
    topic: str,
    difficulty: str,
    num_questions: int,
    question_type: str,
    has_source: bool,
    source_len: int = 0,
) -> list[str]:
    errors: list[str] = []
    if has_source:
        if source_len < 80:
            errors.append("The study material is too short to generate a quiz (need at least 80 characters).")
        elif source_len > 60000:
            errors.append("The study material is too large (max 60000 characters).")
    else:
        if topic == "":
            errors.append("Please enter a topic.")
        elif len(topic) > 200:
            errors.append("Topic is too long (max 200 characters).")
    if difficulty not in ("easy", "medium", "hard"):
        errors.append("Please choose a valid difficulty.")
    if not (MIN_QUESTIONS <= num_questions <= MAX_QUESTIONS):
        errors.append(
            f"Number of questions must be between {MIN_QUESTIONS} and {MAX_QUESTIONS}."
        )
    if question_type != "multiple_choice":
        errors.append("Only multiple choice questions are supported right now.")
    return errors


def suggested_difficulty(mastery: float) -> str:
    """Adaptive rule preserved from PHP PerformanceAnalytics."""
    if mastery >= 85:
        return "hard"
    if mastery >= 60:
        return "medium"
    return "easy"


class QuizService:
    def __init__(self, db: SupabaseClient, ai: AiService):
        self._db = db
        self._ai = ai
        self._repo = QuizRepository(db)

    async def generate_and_save(
        self,
        user_id: str,
        topic: str,
        difficulty: str,
        count: int,
        question_type: str,
        token: str | None,
        source_text: str | None = None,
    ) -> dict[str, Any]:
        cleaner = StudyMaterialCleaner()
        if source_text is not None and source_text.strip() != "":
            source_text = cleaner.clean(source_text)
            if not cleaner.is_meaningful(source_text):
                raise APIError(
                    "Not enough readable study material to generate high-quality "
                    "questions. Please upload a clearer, text-based document.",
                    422,
                    "validation",
                )
        else:
            source_text = None

        errors = validate_request(
            topic.strip(), difficulty, count, question_type,
            has_source=source_text is not None,
            source_len=len(source_text or ""),
        )
        if errors:
            raise APIError(" ".join(errors), 422, "validation")

        request = {
            "topic": topic.strip(),
            "difficulty": difficulty,
            "num_questions": count,
            "question_type": question_type,
            "source_text": source_text,
        }

        try:
            result = await self._ai.generate_quiz(request)
        except AiProviderError as exc:
            status = 502
            if "not configured" in exc.message:
                status = 503
            raise APIError(
                exc.message
                if not exc.retryable
                else "The AI service is temporarily unavailable. Please try again in a moment.",
                status,
                "ai_unavailable" if status == 502 else "ai_not_configured",
            ) from exc

        title = (topic.strip()[:40].capitalize() if topic.strip() else "") + " Quiz"
        saved = await self._repo.save_generated_quiz(
            user_id,
            title,
            request["topic"],
            difficulty,
            question_type,
            result["provider"],
            result["questions"],
            token=token,
        )

        quiz = saved["quiz"]
        return {
            "quiz": {
                "id": quiz.get("id"),
                "title": title,
                "topic": request["topic"],
                "difficulty": difficulty,
                "question_count": quiz.get("question_count") or len(result["questions"]),
                "provider": result["provider"],
            },
            "title": title,
            "provider": result["provider"],
        }
