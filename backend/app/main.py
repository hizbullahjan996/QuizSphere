"""QuizSphere FastAPI backend - application entry point.

Run with:
    uvicorn app.main:app --host 127.0.0.1 --port 8001

The PHP backend remains untouched and continues serving the frontend and the
current /api/* endpoints until later migration phases switch traffic over.
"""

from contextlib import asynccontextmanager

import httpx
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app.api.router import api_router
from app.core.config import get_settings
from app.core.errors import register_error_handlers
from app.core.logging import configure_logging, get_logger
from app.core.session import create_session_store
from app.middleware.security import SecurityHeadersMiddleware
from app.services.supabase_health import SupabaseHealthService
from app.utils.rate_limiter import RateLimiter
from app.utils.supabase import init_supabase


@asynccontextmanager
async def lifespan(app: FastAPI):
    settings = get_settings()
    configure_logging(settings)
    logger = get_logger("quizsphere.startup")
    logger.info(
        "Starting %s v%s (env=%s, supabase=%s, ai=%s)",
        settings.APP_NAME,
        settings.APP_VERSION,
        settings.ENVIRONMENT,
        "configured" if settings.supabase_configured else "not configured",
        "configured" if settings.ai_configured else "not configured",
    )
    http = httpx.AsyncClient()
    init_supabase(settings, http)
    app.state.http = http
    app.state.rate_limiter = RateLimiter(settings)
    app.state.supabase_health = SupabaseHealthService(settings, http)
    store_backend = "redis" if settings.REDIS_URL else "memory"
    app.state.session_store = create_session_store(
        ttl=settings.SESSION_TTL_SECONDS, redis_url=settings.REDIS_URL
    )
    logger.info("Session store backend: %s", store_backend)
    yield
    await app.state.session_store.aclose()
    await http.aclose()
    logger.info("Shutdown complete")


def create_app() -> FastAPI:
    settings = get_settings()
    app = FastAPI(
        title=settings.APP_NAME,
        version=settings.APP_VERSION,
        docs_url="/api/docs",
        openapi_url="/api/openapi.json",
        lifespan=lifespan,
    )

    app.add_middleware(
        CORSMiddleware,
        allow_origins=settings.cors_origins_list,
        allow_credentials=True,
        allow_methods=["*"],
        allow_headers=["*"],
    )
    app.add_middleware(SecurityHeadersMiddleware)

    register_error_handlers(app)
    app.include_router(api_router)
    return app


app = create_app()
