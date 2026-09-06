"""Certificate service - port of PHP `CertificateService` business rules.

Eligibility (>= 80%) and data shown are always derived server-side from the
persisted attempt. One certificate per attempt (dedupe by attempt_id).
Certificates are created with is_verified=true so genuine records are
publicly verifiable; verification only ever returns public-safe fields.
"""

import logging
from typing import Any

from app.core.errors import APIError
from app.repositories.certificate_repository import CertificateRepository
from app.utils.supabase import SupabaseClient

logger = logging.getLogger(__name__)

MIN_PERCENT = 80.0


class CertificateService:
    def __init__(self, db: SupabaseClient):
        self._db = db
        self._repo = CertificateRepository(db)

    async def create_for_attempt(
        self,
        user_id: str,
        token: str | None,
        attempt_id: str,
        quiz_id: str,
        quiz_title: str,
        percent: float,
        student_name: str,
    ) -> dict[str, Any] | None:
        if percent < MIN_PERCENT or not token:
            return None

        existing = await self._repo.exists_for_attempt(user_id, attempt_id, token)
        if existing:
            return existing

        try:
            return await self._repo.create_for_attempt(
                user_id, token, attempt_id, quiz_id, quiz_title, percent, student_name
            )
        except APIError as exc:
            logger.warning(
                "Certificate creation failed for %s: %s", user_id, exc.message
            )
            return None
