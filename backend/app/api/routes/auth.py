"""Auth endpoints - behavioral port of PHP `api/auth.php`.

Same JSON contract ({error, message, redirect?}), same status codes, same
friendly messages. The frontend migrates to calling these endpoints directly;
after a successful login/signup the returned tokens are kept in browser memory
and forwarded as `Authorization: Bearer <token>` on all API calls, and the
client seeds the PHP session via `api/session_bridge.php` so protected pages
keep working during the transition.
"""

import re
import time
from typing import Any

from fastapi import APIRouter, Request, Response

from app.api.deps import (
    _extract_bearer_token,
    get_or_create_session,
    get_store,
    require_csrf,
    require_user,
)
from app.core.config import get_settings
from app.core.errors import APIError
from app.core.session import COOKIE_NAME
from app.schemas.auth import CsrfResponse, MeResponse, UserOut
from app.services.auth_service import AuthService
from app.utils.supabase import get_supabase

router = APIRouter(prefix="/auth", tags=["auth"])

EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")


def _auth_service() -> AuthService:
    return AuthService(get_supabase(), get_settings())


def _adopt(session, user: dict, tokens: dict) -> None:
    session.user = {
        "id": user.get("id"),
        "email": user.get("email") or "",
        "full_name": (user.get("user_metadata") or {}).get("full_name") or "",
        "avatar": (user.get("user_metadata") or {}).get("avatar_url"),
    }
    session.auth = {
        "access_token": tokens.get("access_token") or "",
        "refresh_token": tokens.get("refresh_token") or "",
    }
    session.logged_in_at = time.time()


def _tokens_from_session(session) -> dict[str, Any]:
    auth = session.auth or {}
    return {
        "access_token": auth.get("access_token") or "",
        "refresh_token": auth.get("refresh_token") or "",
    }


@router.get("/csrf", response_model=CsrfResponse)
async def csrf(request: Request, response: Response) -> CsrfResponse:
    _sid, session = await get_or_create_session(request, response)
    return CsrfResponse(error=None, csrf_token=session.csrf)


@router.post("/signup")
async def signup(request: Request, response: Response) -> dict:
    body = await request.json() if await request.body() else {}
    if not isinstance(body, dict):
        body = {}

    full_name = str(body.get("fullname") or "").strip()
    email = str(body.get("email") or "").strip().lower()
    password = str(body.get("password") or "")
    confirm = str(body.get("confirm_password") or "")

    errors = []
    if full_name == "":
        errors.append("Please enter your full name.")
    if not EMAIL_RE.match(email):
        errors.append("Please enter a valid email address.")
    if len(password) < 6:
        errors.append("Password must be at least 6 characters.")
    if password != confirm:
        errors.append("Passwords do not match.")
    if errors:
        raise APIError(" ".join(errors), 422, "validation")

    # Signup/login are public entry points that BOTH create and consume the
    # session in one request. The FastAPI cookie+CSRF cannot be delivered
    # cross-origin over local http (frontend localhost:8000 -> API 127.0.0.1:8001),
    # so the browser supplies no matching token here. CSRF is therefore relaxed
    # for these two endpoints; authenticated mutations keep full CSRF (and are
    # skipped automatically when a valid Bearer token is present).
    _sid, session = await get_or_create_session(request, response)

    service = _auth_service()
    result = await service.register(session, full_name, email, password)

    got = result.get("session")
    user = result.get("user") or {}
    if isinstance(got, dict) and got.get("access_token"):
        _adopt(session, user, got)
        return {
            "error": None,
            "message": "Welcome to QuizSphere!",
            "redirect": "/pages/dashboard.php",
            "tokens": _tokens_from_session(session),
        }

    # GoTrue answers signup for an EXISTING account with a session-less 200
    # and an empty identities list (user-enumeration protection); a genuinely
    # new signup always carries the email identity.
    identities = user.get("identities") if isinstance(user, dict) else None
    if isinstance(identities, list) and len(identities) == 0:
        try:
            await service.login(session, email, password)
            return {
                "error": None,
                "message": "Welcome to QuizSphere!",
                "redirect": "/pages/dashboard.php",
                "tokens": _tokens_from_session(session),
            }
        except APIError:
            raise APIError(
                "An account with this email already exists. Try logging in instead.",
                422,
                "user_already_exists",
            )

    return {
        "error": "confirm_required",
        "message": "Account created! Please check your email to confirm your address, then sign in.",
    }


@router.post("/login")
async def login(request: Request, response: Response) -> dict:
    body = await request.json() if await request.body() else {}
    if not isinstance(body, dict):
        body = {}
    email = str(body.get("email") or "").strip().lower()
    password = str(body.get("password") or "")

    if email == "" or password == "":
        raise APIError("Please enter your email and password.", 422, "validation")

    _sid, session = await get_or_create_session(request, response)

    await _auth_service().login(session, email, password)
    return {
        "error": None,
        "message": "Successfully signed in.",
        "redirect": "/pages/dashboard.php",
        "tokens": _tokens_from_session(session),
    }


@router.post("/logout")
async def logout(request: Request, response: Response) -> dict:
    body = await request.json() if await request.body() else {}
    if not isinstance(body, dict):
        body = {}

    service = _auth_service()
    bearer = _extract_bearer_token(request)
    if bearer:
        # Frontend Bearer-token logout (no FastAPI session cookie involved)
        await service.logout_token(bearer)
        return {"error": None, "message": "Signed out.", "redirect": "/index.php"}

    store = get_store(request)
    sid = request.cookies.get(COOKIE_NAME)
    session = await store.get(sid)
    require_csrf(request, session, body)

    if session is not None and session.logged_in:
        await service.logout(session)
    await store.delete(sid)
    response.delete_cookie(COOKIE_NAME, path="/")
    return {"error": None, "message": "Signed out.", "redirect": "/index.php"}


@router.get("/me", response_model=MeResponse)
async def me(request: Request) -> MeResponse:
    session = await require_user(request)
    service = _auth_service()
    token = await service.valid_access_token(session)
    if token is None or not session.logged_in:
        raise APIError("Your session expired. Please log in again.", 401, "unauthenticated")
    return MeResponse(error=None, user=UserOut(**session.user))
