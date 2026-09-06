"""GoTrue error normalisation - port of PHP mapAuthError/extractAuthError.

Produces friendly, client-safe messages; raw provider detail never reaches
the browser.
"""

from typing import Any

from app.core.errors import APIError


def extract_auth_error(body: Any, status: int) -> dict[str, str] | None:
    if not isinstance(body, dict):
        return {"code": "", "message": f"HTTP {status}"} if status >= 400 else None
    if "error" in body:
        err = body["error"]
        if isinstance(err, dict):
            return err
        return {
            "code": str(body.get("error_code") or body.get("code") or ""),
            "message": str(err),
        }
    if "error_code" in body or "msg" in body:
        return {
            "code": str(body.get("error_code") or ""),
            "message": str(body.get("msg") or body.get("message") or ""),
        }
    if status >= 400 and "user" not in body and "id" not in body:
        return {
            "code": str(body.get("code") or ""),
            "message": str(body.get("message") or f"HTTP {status}"),
        }
    return None


def map_auth_error(error: dict[str, Any], status: int) -> APIError:
    code = str(error.get("code") or "")
    message = str(error.get("message") or "Authentication failed.")

    messages = {
        "user_already_exists": "An account with this email already exists. Try logging in instead.",
        "invalid_credentials": "Invalid email or password. Please try again.",
        "email_not_confirmed": "Please confirm your email before logging in.",
        "email_address_invalid": "That email address looks invalid. Please check it and try again.",
        "weak_password": "That password is too weak. Use at least 6 characters with a mix of letters and numbers.",
        "over_request_rate_limit": "Too many attempts. Please wait a moment and try again.",
        "over_email_send_rate_limit": "Too many verification emails have been requested. Please wait and try again later.",
    }
    if code in messages:
        msg = messages[code]
    elif "password" in message.lower() and "6" in message:
        msg = "Password must be at least 6 characters long."
    else:
        # Never surface unknown raw provider messages verbatim.
        msg = "Authentication failed. Please try again."

    if error.get("name") == "RateLimitError" and error.get("hint"):
        msg = "Too many attempts. Please wait a moment and try again."

    return APIError(msg, status or 400, code or None)
