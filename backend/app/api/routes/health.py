"""Health endpoints."""

from fastapi import APIRouter, Request

from app.schemas.common import HealthResponse
from app.services.supabase_health import SupabaseHealthService

router = APIRouter(tags=["health"])


@router.get("/health", response_model=HealthResponse)
async def health() -> HealthResponse:
    return HealthResponse(status="ok")


@router.get("/health/supabase")
async def health_supabase(request: Request) -> dict:
    """Safe Supabase connectivity probe (no credentials in the response)."""
    service: SupabaseHealthService = request.app.state.supabase_health
    result = await service.check()
    return {"status": "ok" if result["connected"] else "degraded", "supabase": result}
