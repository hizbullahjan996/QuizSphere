"""Quiz endpoints - behavioral ports of the PHP quiz APIs.

POST /generate_quiz, POST /pdf_quiz, GET /quiz, GET /my_quizzes,
POST /submit_attempt, GET /attempt, POST /practice.

Response shapes match the PHP JSON contract so the existing frontend works
unchanged. Gamification/certificate awards remain on the PHP backend until a
later migration phase (fields are returned as null here).
"""

import re
from typing import Any

from fastapi import APIRouter, File, Form, Request, UploadFile

from app.api.deps import require_csrf, require_user
from app.core.config import get_settings
from app.core.errors import APIError
from app.repositories.profile_repository import ProfileRepository
from app.repositories.quiz_repository import QuizRepository
from app.services.ai_service import AiService
from app.services.auth_service import AuthService
from app.services.pdf_text_extractor import PdfExtractionError, PdfTextExtractor
from app.services.certificate_service import CertificateService, MIN_PERCENT
from app.services.gamification import GamificationService
from app.services.quiz_service import QuizService, suggested_difficulty
from app.utils.rate_limiter import RateLimiter
from app.utils.supabase import get_supabase

router = APIRouter(tags=["quiz"])

UUID_RE = re.compile(r"^[0-9a-fA-F-]{36}$")
PDF_UPLOAD_MAX_BYTES = 10 * 1024 * 1024


async def _authed(request: Request):
    session = await require_user(request)
    token = await AuthService(get_supabase(), get_settings()).valid_access_token(session)
    if not token:
        raise APIError("Your session expired. Please log in again.", 401, "unauthenticated")
    return session, token


def _ai(request: Request) -> AiService:
    return AiService(get_settings(), request.app.state.http)


def _rl(request: Request) -> RateLimiter:
    return request.app.state.rate_limiter


@router.post("/generate_quiz")
async def generate_quiz(request: Request) -> dict:
    session, token = await _authed(request)
    body = await request.json() if await request.body() else {}
    if not isinstance(body, dict):
        body = {}

    rl = _rl(request)
    if not rl.hit(RateLimiter.actor(session.user["id"], RateLimiter.client_ip(request))):
        raise APIError(
            "You have reached the limit for generating quizzes right now. Please try again later.",
            429,
            "rate_limited",
        )

    topic = str(body.get("topic") or "").strip()
    difficulty = str(body.get("difficulty") or "medium").lower()
    count = int(body.get("question_count") or 10)
    qtype = str(body.get("question_type") or "multiple_choice")
    require_csrf(request, session, body)

    service = QuizService(get_supabase(), _ai(request))
    result = await service.generate_and_save(
        session.user["id"], topic, difficulty, count, qtype, token
    )
    return {"error": None, "message": "Quiz generated successfully!", "quiz": result["quiz"]}


@router.post("/pdf_quiz")
async def pdf_quiz(
    request: Request,
    pdf: UploadFile = File(...),
    question_count: str = Form("10"),
    difficulty: str = Form("medium"),
    csrf_token: str | None = Form(None, alias="_csrf"),
) -> dict:
    session, token = await _authed(request)
    require_csrf(request, session, {"_csrf": csrf_token})

    rl = _rl(request)
    if not rl.hit(RateLimiter.actor(session.user["id"], RateLimiter.client_ip(request))):
        raise APIError(
            "You have reached the limit for generating quizzes right now. Please try again later.",
            429,
            "rate_limited",
        )

    content = await pdf.read()
    if not content:
        raise APIError("Please choose a PDF file to upload.", 422, "upload")
    if len(content) > PDF_UPLOAD_MAX_BYTES:
        raise APIError("The PDF must be between 1 byte and 10 MB.", 422, "upload")
    mime = (pdf.content_type or "").lower()
    if "application/pdf" not in mime and not content[:1024].lstrip().startswith(b"%PDF"):
        raise APIError("Only text-based PDF files are allowed.", 422, "upload")

    count = int(question_count or 10)
    diff = (difficulty or "medium").lower()

    # Store privately in Supabase Storage (RLS-aware user token).
    original = (pdf.filename or "upload.pdf").rsplit("/", 1)[-1].rsplit("\\", 1)[-1]
    safe = re.sub(r"[^A-Za-z0-9._-]", "_", original) or "study-material.pdf"
    if not safe.lower().endswith(".pdf"):
        safe += ".pdf"
    import secrets as _secrets

    path = f"{session.user['id']}/{_secrets.token_hex(8)}-{safe}"
    await get_supabase().upload_object(
        "study-materials", path, content, "application/pdf", token=token
    )

    try:
        source = PdfTextExtractor().extract(content)
    except PdfExtractionError:
        raise APIError(
            "This PDF could not be processed. Please upload a text-based PDF.",
            422,
            "pdf_unreadable",
        )

    service = QuizService(get_supabase(), _ai(request))
    result = await service.generate_and_save(
        session.user["id"], safe, diff, count, "multiple_choice", token, source_text=source
    )
    return {"error": None, "message": "Quiz generated from your PDF!", "quiz": result["quiz"]}


@router.get("/quiz")
async def get_quiz(request: Request, id: str = "") -> dict:
    session, token = await _authed(request)
    if not UUID_RE.match(id or ""):
        raise APIError("A valid quiz id is required.", 400, "bad_request")
    repo = QuizRepository(get_supabase())
    data = await repo.find_quiz_for_user(session.user["id"], id, token)
    if data is None:
        raise APIError("Quiz not found or you do not have access to it.", 404, "not_found")
    q = data["quiz"]
    return {
        "error": None,
        "quiz": {
            "id": q.get("id"),
            "title": q.get("title"),
            "topic": q.get("topic"),
            "difficulty": q.get("difficulty"),
            "question_count": q.get("question_count"),
            "question_type": q.get("question_type"),
            "provider": q.get("ai_provider"),
        },
        "questions": data["questions"],
    }


@router.get("/my_quizzes")
async def my_quizzes(request: Request) -> dict:
    session, token = await _authed(request)
    repo = QuizRepository(get_supabase())
    quizzes = await repo.list_user_quizzes(session.user["id"], token, 50)
    profile = await ProfileRepository(get_supabase()).get(session.user["id"], token)
    return {
        "error": None,
        "quizzes": quizzes,
        "profile": profile
        or {
            "full_name": session.user.get("full_name") or "",
            "email": session.user.get("email") or "",
            "xp": 0,
            "level": 1,
            "streak": 0,
        },
    }


@router.post("/submit_attempt")
async def submit_attempt(request: Request) -> dict:
    session, token = await _authed(request)
    body = await request.json() if await request.body() else {}
    if not isinstance(body, dict):
        body = {}
    quiz_id = str(body.get("quiz_id") or "")
    provider = str(body.get("provider") or "gemini")
    answers = body.get("answers") or []
    require_csrf(request, session, body)

    if not UUID_RE.match(quiz_id):
        raise APIError("A valid quiz id is required.", 400, "bad_request")
    if not isinstance(answers, list) or not answers:
        raise APIError("Please provide your answers.", 400, "bad_request")

    normalised = []
    for a in answers:
        if not isinstance(a, dict):
            continue
        qid = a.get("question_id")
        sel = int(a.get("selected", -1)) if a.get("selected") is not None else -1
        if isinstance(qid, str) and sel >= 0:
            normalised.append({"question_id": qid, "selected": sel})
    if not normalised:
        raise APIError("Your answers are not valid.", 400, "bad_request")

    repo = QuizRepository(get_supabase())

    # Snapshot concept performance BEFORE grading for improvement detection.
    concept_before: list = []
    try:
        concept_before = await repo.list_concept_performance(session.user["id"], token)
    except APIError:
        concept_before = []

    try:
        result = await repo.submit_attempt(
            session.user["id"], quiz_id, provider, normalised, token
        )
    except LookupError:
        raise APIError("Quiz not found.", 404, "not_found")

    detail_payload = await repo.load_full_detail_for_review(
        session.user["id"], result, token
    )
    detail = (detail_payload or {}).get("detail") or []

    # Rewards: gamification (XP/streak/level/badges) + certificate,
    # awarded only after the attempt is successfully persisted.
    db = get_supabase()
    gamification = None
    certificate = None
    try:
        profile = await ProfileRepository(db).get(session.user["id"], token) or {}
        try:
            attempt_count = len(await repo.list_user_attempts(session.user["id"], token, 500)) + 1
        except APIError:
            attempt_count = 1

        g = GamificationService(db, repo)
        gamification = await g.award_attempt(
            session.user["id"], token, result, profile, attempt_count, concept_before
        )

        if float(result["percent"]) >= MIN_PERCENT:
            quiz_title = "Completed Quiz"
            try:
                qz = await repo.find_quiz_for_user(session.user["id"], quiz_id, token)
                if qz:
                    t = str(qz["quiz"].get("title") or "").strip()
                    if t:
                        quiz_title = t
            except APIError:
                pass
            name = str(profile.get("full_name") or session.user.get("full_name") or "Learner")
            created = await CertificateService(db).create_for_attempt(
                session.user["id"],
                token,
                str(result["attempt"].get("id") or ""),
                quiz_id,
                quiz_title,
                float(result["percent"]),
                name,
            )
            if created is not None:
                certificate = {
                    "id": str(created.get("id") or ""),
                    "title": "Certificate of Achievement",
                }
                badge = await g.grant_by_code(session.user["id"], token, "certificate_earned")
                if badge is not None:
                    gamification.setdefault("new_achievements", []).append(badge)
    except Exception as exc:
        import logging

        logging.getLogger(__name__).warning("Gamification award failed (attempt saved): %s", exc)

    attempt = result["attempt"]
    return {
        "error": None,
        "attempt": {
            "id": attempt.get("id"),
            "quiz_id": quiz_id,
            "score": result["score"],
            "total": result["total"],
            "percent": result["percent"],
        },
        "correct": result["score"],
        "incorrect": result["total"] - result["score"],
        "total": result["total"],
        "percent": result["percent"],
        "detail": detail,
        "gamification": gamification,
        "certificate": certificate,
    }


@router.get("/attempt")
async def get_attempt(request: Request, id: str = "") -> dict:
    session, token = await _authed(request)
    if not UUID_RE.match(id or ""):
        raise APIError("A valid attempt id is required.", 400, "bad_request")
    repo = QuizRepository(get_supabase())
    data = await repo.find_attempt_for_user(session.user["id"], id, token)
    if data is None:
        raise APIError("Attempt not found.", 404, "not_found")
    attempt = data["attempt"]
    detail_payload = await repo.load_detail_for_attempt(
        session.user["id"], id, attempt["quiz_id"], token
    )
    return {
        "error": None,
        "attempt": {
            "id": attempt.get("id"),
            "quiz_id": attempt.get("quiz_id"),
            "score": int(attempt.get("score") or 0),
            "total": int(attempt.get("total") or 0),
            "percent": float(attempt.get("percent") or 0),
            "completed_at": attempt.get("completed_at"),
        },
        "correct": int(attempt.get("score") or 0),
        "incorrect": int(attempt.get("total") or 0) - int(attempt.get("score") or 0),
        "total": int(attempt.get("total") or 0),
        "percent": float(attempt.get("percent") or 0),
        "detail": (detail_payload or {}).get("detail") or [],
        "rewards": None,
    }


@router.post("/practice")
async def practice(request: Request) -> dict:
    session, token = await _authed(request)
    body = await request.json() if await request.body() else {}
    if not isinstance(body, dict):
        body = {}
    require_csrf(request, session, body)

    rl = _rl(request)
    if not rl.hit(RateLimiter.actor(session.user["id"], RateLimiter.client_ip(request))):
        raise APIError(
            "You have reached the limit for generating quizzes right now. Please try again later.",
            429,
            "rate_limited",
        )

    concept = str(body.get("concept") or "").strip()
    count = int(body.get("question_count") or 8)
    qtype = str(body.get("question_type") or "multiple_choice")

    if concept == "" or len(concept) > 200:
        raise APIError("Please provide a concept to practise.", 422, "validation")
    if not (1 <= count <= 15):
        raise APIError("Number of questions must be between 1 and 15.", 422, "validation")

    repo = QuizRepository(get_supabase())
    suggested = "medium"
    try:
        concepts = await repo.list_concept_performance(session.user["id"], token)
        for c in concepts:
            if str(c.get("concept") or "").lower() == concept.lower():
                suggested = suggested_difficulty(float(c.get("mastery") or 0))
                break
    except APIError:
        suggested = "medium"

    override = str(body.get("difficulty") or "").strip().lower()
    difficulty = override if override in ("easy", "medium", "hard") else suggested

    service = QuizService(get_supabase(), _ai(request))
    result = await service.generate_and_save(
        session.user["id"], concept, difficulty, count, qtype, token
    )
    return {
        "error": None,
        "message": "Practice quiz generated!",
        "quiz": result["quiz"],
        "difficulty": difficulty,
        "was_adaptive": override not in ("easy", "medium", "hard"),
    }
