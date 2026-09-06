"""Auth service - port of PHP `Auth` + `api/auth.php` behavior.

Registration (with the email-quota fallback), login, logout, current user and
token refresh. Supabase tokens are kept in the server-side session only.
"""

import base64
import json
import time
from typing import Any

from app.core.config import Settings
from app.core.errors import APIError
from app.core.session import SessionData
from app.repositories.profile_repository import ProfileRepository
from app.services.auth_errors import extract_auth_error, map_auth_error
from app.utils.supabase import SupabaseClient

AUTH_QUOTA_FALLBACK_DEFAULT = True


def _is_expired_jwt(token: str) -> bool:
    parts = token.split(".")
    if len(parts) < 2:
        return True
    payload = parts[1] + "=" * (-len(parts[1]) % 4)
    try:
        data = json.loads(base64.urlsafe_b64decode(payload))
    except Exception:
        return True
    if "exp" not in data:
        return False
    return (time.time() + 30) >= int(data["exp"])


class AuthService:
    def __init__(self, db: SupabaseClient, settings: Settings):
        self._db = db
        self._settings = settings
        self._profiles = ProfileRepository(db)

    # ------------------------------------------------------------------
    # Registration
    # ------------------------------------------------------------------

    async def register(
        self, session: SessionData, full_name: str, email: str, password: str
    ) -> dict[str, Any]:
        """Returns {"user": ..., "session": ...|None} like PHP Auth::register."""
        now = time.time()
        if now - session.signup_lock < 5:
            raise APIError("Please wait a few seconds before trying again.", 429, "too_fast")
        session.signup_lock = now

        if self._settings.DISABLE_EMAIL_VERIFICATION:
            # Create the account admin-confirmed directly: no verification
            # email is sent and the user can sign in immediately.
            user, got_session = await self._quota_fallback(full_name, email, password)
        else:
            try:
                status, body = await self._db.auth_signup(email, password, {"full_name": full_name})
                error = extract_auth_error(body, status)
                if error is not None:
                    raise map_auth_error(error, status)
                user = body.get("user") or body
                got_session = body.get("session")
            except APIError as exc:
                fallback_on = AUTH_QUOTA_FALLBACK_DEFAULT
                if exc.code == "over_email_send_rate_limit" and fallback_on:
                    user, got_session = await self._quota_fallback(full_name, email, password)
                else:
                    raise

        uid = user.get("id") if isinstance(user, dict) else None
        if uid:
            await self._profiles.ensure_exists(str(uid), email, full_name)

        return {"user": user, "session": got_session}

    async def _quota_fallback(
        self, full_name: str, email: str, password: str
    ) -> tuple[dict[str, Any], dict[str, Any] | None]:
        """Port of PHP adminEnsureConfirmedUser + sign-in."""
        status, body = await self._db.admin_create_confirmed_user(
            email, password, {"full_name": full_name}
        )
        if status >= 400:
            detail = body.get("message") if isinstance(body, dict) else str(body)
            if not (isinstance(detail, str) and "already" in detail.lower()):
                raise APIError("Registration failed. Please try again.", status or 500)
            # Existing (unconfirmed) account: confirm it server-side.
            lst_status, lst = await self._db.admin_list_users(email)
            found = None
            if lst_status < 400 and isinstance(lst, dict):
                for u in lst.get("users") or []:
                    if str(u.get("email", "")).lower() == email.lower():
                        found = u
                        break
            if found is None:
                raise APIError("Registration failed. Please try again.", 500)
            if not found.get("email_confirmed_at"):
                up_status, _ = await self._db.admin_update_user(
                    str(found["id"]), {"email_confirm": True}
                )
                if up_status >= 400:
                    raise APIError("Registration failed. Please try again.", 500)

        st, sb = await self._db.auth_token_password(email, password)
        error = extract_auth_error(sb, st)
        if error is not None:
            raise map_auth_error(error, st)
        return sb.get("user") or {}, sb

    # ------------------------------------------------------------------
    # Login / logout / me
    # ------------------------------------------------------------------

    async def login(self, session: SessionData, email: str, password: str) -> dict[str, Any]:
        status, body = await self._db.auth_token_password(email, password)
        error = extract_auth_error(body, status)
        if error is not None:
            mapped = map_auth_error(error, status)
            if status == 503:
                raise APIError(
                    "Cannot reach the authentication service right now. "
                    "Please try again in a moment.",
                    503,
                    "network_error",
                )
            raise APIError(mapped.message, 401, mapped.code)

        access = body.get("access_token") or ""
        user = body.get("user")
        if not user and access:
            u_status, u_body = await self._db.auth_user(access)
            user = u_body if u_status == 200 and isinstance(u_body, dict) else None
        if not user:
            raise APIError("Unable to retrieve your account. Please try again.", 400)

        session.user = {
            "id": user.get("id"),
            "email": user.get("email") or "",
            "full_name": (user.get("user_metadata") or {}).get("full_name") or "",
            "avatar": (user.get("user_metadata") or {}).get("avatar_url"),
        }
        session.auth = {
            "access_token": access,
            "refresh_token": body.get("refresh_token") or "",
        }
        session.logged_in_at = time.time()
        return user

    async def logout(self, session: SessionData) -> None:
        token = (session.auth or {}).get("access_token")
        if token:
            await self._db.auth_logout(token)
        session.user = None
        session.auth = None

    async def logout_token(self, access_token: str) -> None:
        """Best-effort revocation of a Supabase access token (Bearer flow)."""
        if access_token:
            await self._db.auth_logout(access_token)

    def access_token(self, session: SessionData) -> str | None:
        """Port of PHP Auth::accessToken with refresh-on-expiry."""
        auth = session.auth or {}
        token = auth.get("access_token")
        refresh = auth.get("refresh_token")
        if token and _is_expired_jwt(token) and refresh:
            return None  # caller refreshes via refresh_session()
        return token

    async def refresh_session(self, session: SessionData) -> str | None:
        auth = session.auth or {}
        refresh = auth.get("refresh_token")
        if not refresh:
            return None
        status, body = await self._db.auth_refresh(refresh)
        if status < 400 and isinstance(body, dict) and body.get("access_token"):
            session.auth = {
                "access_token": body["access_token"],
                "refresh_token": body.get("refresh_token") or refresh,
            }
            return body["access_token"]
        session.user = None
        session.auth = None
        return None

    async def valid_access_token(self, session: SessionData) -> str | None:
        token = self.access_token(session)
        if token:
            return token
        if (session.auth or {}).get("refresh_token"):
            return await self.refresh_session(session)
        return None
