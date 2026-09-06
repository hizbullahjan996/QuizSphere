"""Auth request/response schemas.

Request bodies are parsed as raw dicts in the routes so validation messages
match the PHP contract exactly; these models document the payloads and type
the responses. The CSRF token travels as `_csrf` in the body (or the
X-CSRF-Token header) and is validated separately.
"""

from pydantic import BaseModel


class SignupRequest(BaseModel):
    """POST /api/auth/signup body (plus `_csrf`)."""

    fullname: str = ""
    email: str = ""
    password: str = ""
    confirm_password: str = ""


class LoginRequest(BaseModel):
    """POST /api/auth/login body (plus `_csrf`)."""

    email: str = ""
    password: str = ""


class UserOut(BaseModel):
    id: str
    email: str
    full_name: str = ""
    avatar: str | None = None


class AuthEnvelope(BaseModel):
    """Mirrors the PHP JSON contract: {error, message, redirect?}."""

    error: str | None = None
    message: str
    redirect: str | None = None


class MeResponse(BaseModel):
    error: str | None = None
    user: UserOut


class CsrfResponse(BaseModel):
    error: str | None = None
    csrf_token: str
