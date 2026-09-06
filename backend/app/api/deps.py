"""Auth dependencies: session resolution, CSRF validation, login guard.

Ports the PHP helpers (api_require_csrf / api_require_auth) as FastAPI
dependencies. Sessions live server-side; the cookie is an opaque id only.

Supports two auth modes:
  1. Session-based (FastAPI native): cookie -> session -> user
  2. Bearer token (PHP bridge): Authorization header -> Supabase JWT -> user
     Used when the PHP frontend forwards the Supabase access token so
     FastAPI can make RLS-aware Supabase calls without its own session.
"""

import base64
import hmac
import json
import time
from typing import Any

from fastapi import Request, Response

from app.core.errors import APIError
from app.core.session import COOKIE_NAME, SessionData, SessionStore


def get_store(request: Request) -> SessionStore:
    return request.app.state.session_store


def set_session_cookie(response: Response, sid: str) -> None:
    response.set_cookie(
        COOKIE_NAME,
        sid,
        httponly=True,
        samesite="lax",
        path="/",
        secure=False,  # local dev over http; set True behind HTTPS
    )


async def get_or_create_session(
    request: Request, response: Response | None = None
) -> tuple[str, SessionData]:
    store = get_store(request)
    sid = request.cookies.get(COOKIE_NAME)
    session = await store.get(sid)
    if session is None:
        sid, session = await store.create()
        if response is not None:
            set_session_cookie(response, sid)
    return sid or "", session


def provided_csrf(request: Request, body: Any) -> str | None:
    if isinstance(body, dict):
        token = body.get("_csrf")
    else:
        token = getattr(body, "_csrf", None)
    if not token:
        token = request.headers.get("X-CSRF-Token")
    return token or None


def require_csrf(request: Request, session: SessionData | None, body: Any) -> None:
    """Validate CSRF. Skipped when Bearer token auth is used."""
    if _extract_bearer_token(request):
        return
    token = provided_csrf(request, body)
    if (
        session is None
        or not token
        or not isinstance(token, str)
        or not hmac.compare_digest(session.csrf, token)
    ):
        raise APIError(
            "Your session token is invalid. Please reload the page and try again.",
            419,
            "invalid_csrf",
        )


def _extract_bearer_token(request: Request) -> str | None:
    """Extract a Supabase access token from the Authorization header."""
    auth = request.headers.get("Authorization", "")
    if auth.lower().startswith("bearer "):
        token = auth[7:].strip()
        if token:
            return token
    return None


def _decode_jwt_payload(token: str) -> dict[str, Any] | None:
    """Decode the payload segment of a JWT without verifying the signature.
    Used only to extract user identity from a Supabase-issued access token.
    """
    parts = token.split(".")
    if len(parts) < 2:
        return None
    payload = parts[1]
    # Fix base64 padding
    payload += "=" * (-len(payload) % 4)
    try:
        return json.loads(base64.urlsafe_b64decode(payload))
    except Exception:
        return None


def _session_from_bearer(token: str) -> SessionData:
    """Build a lightweight SessionData from a Supabase access token JWT."""
    claims = _decode_jwt_payload(token)
    if claims is None:
        raise APIError("Invalid authentication token.", 401, "unauthenticated")
    exp = claims.get("exp")
    if exp and (time.time() + 30) >= int(exp):
        raise APIError("Your session expired. Please log in again.", 401, "unauthenticated")
    uid = claims.get("sub") or ""
    email = claims.get("email") or ""
    meta = claims.get("user_metadata") or {}
    session = SessionData()
    session.user = {
        "id": uid,
        "email": email,
        "full_name": meta.get("full_name") or "",
        "avatar": meta.get("avatar_url"),
    }
    session.auth = {"access_token": token, "refresh_token": ""}
    session.logged_in_at = time.time()
    return session


async def require_user(request: Request) -> SessionData:
    """Require an authenticated user. Accepts either session cookie or Bearer token."""
    # Try Bearer token first (PHP bridge flow)
    bearer = _extract_bearer_token(request)
    if bearer:
        return _session_from_bearer(bearer)
    # Fall back to FastAPI session cookie
    store = get_store(request)
    session = await store.get(request.cookies.get(COOKIE_NAME))
    if session is None or not session.logged_in:
        raise APIError("You must be logged in to do that.", 401, "unauthenticated")
    return session


async def authed_with_token(request: Request) -> tuple[SessionData, str]:
    """Logged-in session plus a valid Supabase access token (RLS-aware calls).
    Accepts either session cookie or Bearer token.
    """
    # Bearer token path (PHP bridge)
    bearer = _extract_bearer_token(request)
    if bearer:
        session = _session_from_bearer(bearer)
        return session, bearer

    # FastAPI session path
    from app.core.config import get_settings
    from app.services.auth_service import AuthService
    from app.utils.supabase import get_supabase

    session = await require_user(request)
    token = await AuthService(get_supabase(), get_settings()).valid_access_token(session)
    if not token:
        raise APIError("Your session expired. Please log in again.", 401, "unauthenticated")
    return session, token
