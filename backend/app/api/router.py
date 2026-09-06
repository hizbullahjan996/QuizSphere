"""Top-level API router. All routes live under /api to mirror the PHP URLs."""

from fastapi import APIRouter

from app.api.routes import auth, certificates, dashboard_api, health, quiz

api_router = APIRouter(prefix="/api")
api_router.include_router(health.router)
api_router.include_router(auth.router)
api_router.include_router(quiz.router)
api_router.include_router(certificates.router)
api_router.include_router(dashboard_api.router)
