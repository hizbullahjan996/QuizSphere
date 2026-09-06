"""Dashboard-family endpoints - ports of PHP analytics/gamification/
leaderboard/coach APIs. Response shapes match the PHP JSON contract.
"""

from fastapi import APIRouter, Request

from app.api.deps import authed_with_token, require_csrf
from app.core.config import get_settings
from app.core.errors import APIError
from app.repositories.profile_repository import ProfileRepository
from app.repositories.quiz_repository import QuizRepository
from app.services.gamification import GamificationService, level_info
from app.services.learning_coach import LearningCoach, build_context
from app.services.performance_analytics import PerformanceAnalytics
from app.utils.rate_limiter import RateLimiter
from app.utils.supabase import get_supabase

router = APIRouter(tags=["dashboard"])


@router.get("/analytics")
async def analytics(request: Request) -> dict:
    session, token = await authed_with_token(request)
    repo = QuizRepository(get_supabase())
    payload = await PerformanceAnalytics(repo).build(session.user["id"], token)
    return {
        "error": None,
        "stats": {
            "total_attempts": int(payload["total_attempts"]),
            "average_score": float(payload["average_score"]),
            "best_score": float(payload["best_score"]),
        },
        "trend": payload["trend"],
        "concepts": payload["concepts"],
        "weak": payload["weak"],
        "developing": payload["developing"],
        "strong": payload["strong"],
        "recommendations": payload["recommendations"],
        "insights": payload["insights"],
    }


@router.get("/gamification")
async def gamification(request: Request) -> dict:
    session, token = await authed_with_token(request)
    db = get_supabase()
    repo = QuizRepository(db)
    g = GamificationService(db, repo)

    profile = await ProfileRepository(db).get(session.user["id"], token) or {}
    xp = int(profile.get("xp") or 0)
    streak = int(profile.get("streak") or 0)
    info = level_info(xp)

    rank = None
    try:
        me = await db.rpc("get_user_rank", {"p_uid": session.user["id"]}, token=token)
        if isinstance(me, list) and me and isinstance(me[0], dict) and me[0].get("rank") is not None:
            rank = int(me[0]["rank"])
    except Exception:
        rank = None

    achievements = await g.catalogue_with_state(session.user["id"], token)

    try:
        attempts = await repo.list_user_attempts(session.user["id"], token, 10)
    except Exception:
        attempts = []

    return {
        "error": None,
        "profile": {
            "name": str(profile.get("full_name") or session.user.get("full_name") or "Learner"),
            "xp": xp,
            "level": int(profile.get("level") or info["level"]),
            "streak": streak,
            "level_info": info,
            "rank": rank,
        },
        "achievements": {
            "all": achievements["all"],
            "unlocked": achievements["unlocked"],
            "unlocked_count": len(achievements["unlocked"]),
            "total": len(achievements["all"]),
        },
        "recent_attempts": [
            {
                "percent": float(a.get("percent") or 0),
                "score": int(a.get("score") or 0),
                "total": int(a.get("total") or 0),
                "date": str(a.get("completed_at") or ""),
            }
            for a in attempts
        ],
    }


@router.get("/leaderboard")
async def leaderboard(request: Request) -> dict:
    session, token = await authed_with_token(request)
    db = get_supabase()
    try:
        rows = await db.rpc("get_leaderboard", {}, token=token)
        me = await db.rpc("get_user_rank", {"p_uid": session.user["id"]}, token=token)
    except Exception:
        raise APIError("Could not load the leaderboard right now.", 500, "load_failed")

    def shape(r: dict) -> dict:
        return {
            "user_id": str(r.get("user_id") or ""),
            "full_name": str(r.get("full_name") or "Learner"),
            "xp": int(r.get("xp") or 0),
            "level": int(r.get("level") or 1),
            "quizzes_completed": int(r.get("quizzes_completed") or 0),
            "rank": int(r["rank"]) if r.get("rank") is not None else None,
        }

    list_rows = [shape(r) for r in rows] if isinstance(rows, list) else []
    me_row = shape(me[0]) if isinstance(me, list) and me and isinstance(me[0], dict) else None
    return {"error": None, "leaderboard": list_rows, "me": me_row}


@router.post("/coach")
async def coach(request: Request) -> dict:
    session, token = await authed_with_token(request)
    body = await request.json() if await request.body() else {}
    if not isinstance(body, dict):
        body = {}
    require_csrf(request, session, body)

    settings = get_settings()
    rl = request.app.state.rate_limiter
    if not rl.hit(RateLimiter.actor("coach-" + session.user["id"], RateLimiter.client_ip(request))):
        raise APIError(
            "You are sending messages too quickly. Please wait a moment and try again.",
            429,
            "rate_limited",
        )

    message = str(body.get("message") or "").strip()
    if message == "" or len(message) > 1000:
        raise APIError("Please enter a question (up to 1000 characters).", 422, "validation")

    history = []
    raw_history = body.get("history")
    if isinstance(raw_history, list):
        for turn in raw_history[-6:]:
            if not isinstance(turn, dict):
                continue
            role = "coach" if turn.get("role") == "coach" else "user"
            content = str(turn.get("content") or "").strip()
            if content:
                history.append({"role": role, "content": content[:1000]})

    db = get_supabase()
    repo = QuizRepository(db)
    try:
        analytics = await PerformanceAnalytics(repo).build(session.user["id"], token)
    except Exception:
        analytics = {}
    profile = await ProfileRepository(db).get(session.user["id"], token) or {}
    context = build_context(analytics, profile)

    from app.services.ai_service import AiProviderError

    try:
        result = await LearningCoach(settings, request.app.state.http).respond(context, message, history)
    except AiProviderError as exc:
        status = 502 if exc.retryable else 500
        raise APIError(
            exc.message
            if not exc.retryable
            else "The AI coach is temporarily unavailable. Please try again in a moment.",
            status,
            "ai_unavailable",
        ) from exc

    return {"error": None, "message": result["reply"], "provider": result["provider"]}
