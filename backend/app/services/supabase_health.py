"""Supabase connectivity probe.

Returns a safe status payload: reachability + latency only. Never includes
credentials, keys, or internal URLs.
"""

import time
from typing import Any

import httpx

from app.core.config import Settings


class SupabaseHealthService:
    def __init__(self, settings: Settings, http: httpx.AsyncClient):
        self._settings = settings
        self._http = http

    async def check(self) -> dict[str, Any]:
        settings = self._settings
        if not settings.supabase_configured:
            return {"connected": False, "detail": "Supabase is not configured."}

        url = settings.SUPABASE_URL.rstrip("/")
        # This project's publishable key is rejected for anon-only REST reads
        # (the PHP backend always pairs it with a user JWT), so probe with the
        # server-side secret key when present; otherwise the publishable key.
        key = settings.SUPABASE_SECRET_KEY or settings.SUPABASE_PUBLISHABLE_KEY
        started = time.perf_counter()
        try:
            # PostgREST root returns its OpenAPI doc for a valid key without
            # touching any table data or RLS policies.
            resp = await self._http.get(
                f"{url}/rest/v1/",
                headers={"apikey": key, "Authorization": f"Bearer {key}"},
                timeout=8.0,
            )
            latency_ms = int((time.perf_counter() - started) * 1000)
            if resp.status_code < 400:
                return {
                    "connected": True,
                    "latency_ms": latency_ms,
                    "detail": "Supabase is reachable and the server-side key is accepted.",
                }
            if resp.status_code in (401, 403):
                detail = "Supabase rejected the configured key."
            else:
                detail = f"Supabase responded with HTTP {resp.status_code}."
            return {"connected": False, "latency_ms": latency_ms, "detail": detail}
        except httpx.HTTPError:
            return {
                "connected": False,
                "detail": "Supabase is unreachable from this server.",
            }
