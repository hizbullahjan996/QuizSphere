"""Smoke test: FastAPI Supabase layer against the live project.

Read-only checks (no data mutation):
  1. PostgREST reachable with the publishable key (health service).
  2. Service-role select on the seeded achievements catalogue.
  3. Service-role select on quizzes (table + columns intact).
  4. CertificateRepository.verify_public with a random UUID (expect None).

Run:  .venv/Scripts/python.exe scripts/smoke_supabase.py
"""

import asyncio
import sys
import uuid
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import httpx

from app.core.config import get_settings
from app.repositories import CertificateRepository
from app.services.supabase_health import SupabaseHealthService
from app.utils.supabase import SupabaseClient


async def main() -> int:
    settings = get_settings()
    async with httpx.AsyncClient() as http:
        db = SupabaseClient(settings, http)

        health = await SupabaseHealthService(settings, http).check()
        print("health:", health)
        if not health["connected"]:
            return 1

        catalogue = await db.select(
            "achievements", columns="id, code, name", limit=3, service_role=True
        )
        print("achievements catalogue rows:", len(catalogue))

        quizzes = await db.select("quizzes", columns="id, title", limit=2, service_role=True)
        print("quizzes readable (service role):", len(quizzes))

        verify = await CertificateRepository(db).verify_public(str(uuid.uuid4()))
        print("verify_public(random uuid) ->", verify)
        if verify is not None:
            return 1

        print("SMOKE OK")
        return 0


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main()))
