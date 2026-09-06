"""Structured error handling.

All API errors are returned as JSON: {"error": <code>, "message": <text>}.
Internal exception details are logged server-side and never echoed to clients.
"""

import logging

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.exceptions import HTTPException as StarletteHTTPException

logger = logging.getLogger(__name__)


class APIError(Exception):
    """Application-level error with a safe, client-facing message."""

    def __init__(self, message: str, status_code: int = 400, code: str | None = None):
        super().__init__(message)
        self.message = message
        self.status_code = status_code
        self.code = code or self._default_code(status_code)

    @staticmethod
    def _default_code(status_code: int) -> str:
        return {
            400: "bad_request",
            401: "unauthenticated",
            403: "forbidden",
            404: "not_found",
            405: "method_not_allowed",
            409: "conflict",
            419: "invalid_csrf",
            422: "validation",
            429: "rate_limited",
            500: "server_error",
            502: "upstream_error",
            503: "service_unavailable",
        }.get(status_code, "error")


def register_error_handlers(app: FastAPI) -> None:
    @app.exception_handler(APIError)
    async def api_error_handler(_: Request, exc: APIError) -> JSONResponse:
        return JSONResponse(
            status_code=exc.status_code,
            content={"error": exc.code, "message": exc.message},
        )

    @app.exception_handler(StarletteHTTPException)
    async def http_exception_handler(_: Request, exc: StarletteHTTPException) -> JSONResponse:
        message = exc.detail if isinstance(exc.detail, str) else "Request could not be processed."
        return JSONResponse(
            status_code=exc.status_code,
            content={"error": APIError._default_code(exc.status_code), "message": message},
        )

    @app.exception_handler(RequestValidationError)
    async def validation_handler(_: Request, exc: RequestValidationError) -> JSONResponse:
        return JSONResponse(
            status_code=422,
            content={"error": "validation", "message": "Invalid request payload."},
        )

    @app.exception_handler(Exception)
    async def unhandled_handler(_: Request, exc: Exception) -> JSONResponse:
        # Log the real detail; return a generic message to the client.
        logger.exception("Unhandled error: %s", exc)
        return JSONResponse(
            status_code=500,
            content={"error": "server_error", "message": "Something went wrong. Please try again."},
        )
