"""Certificate repository - Python port of the PHP certificate data access.

Preserves table/column names and the privacy model:
- user-scoped reads use the caller's access token (RLS);
- public verification uses the service-role key server-side and selects ONLY
  public-safe display fields, requiring is_verified = true.
"""

from typing import Any

from app.utils.supabase import SupabaseClient

MIN_PERCENT = 80.0  # business rule preserved from PHP CertificateService

_LIST_COLUMNS = "id, title, quiz_title, student_name, score, earned_at, is_verified"
_DETAIL_COLUMNS = "id, title, description, quiz_title, student_name, score, earned_at, is_verified"


class CertificateRepository:
    def __init__(self, db: SupabaseClient):
        self._db = db

    async def exists_for_attempt(
        self, user_id: str, attempt_id: str, token: str
    ) -> dict[str, Any] | None:
        rows = await self._db.select(
            "certificates",
            columns="id, title",
            filters={"user_id": f"eq.{user_id}", "attempt_id": f"eq.{attempt_id}"},
            limit=1,
            token=token,
        )
        return rows[0] if rows else None

    async def create_for_attempt(
        self,
        user_id: str,
        token: str,
        attempt_id: str,
        quiz_id: str,
        quiz_title: str,
        percent: float,
        student_name: str,
    ) -> dict[str, Any] | None:
        """Insert a certificate row for a qualifying attempt (RLS-aware)."""
        achievement = quiz_title.strip() or "Completed Quiz"
        return await self._db.insert(
            "certificates",
            {
                "user_id": user_id,
                "title": "Certificate of Achievement",
                "description": achievement,
                "score": round(percent, 2),
                "quiz_title": achievement,
                "quiz_id": quiz_id,
                "attempt_id": attempt_id,
                "student_name": student_name,
                "is_verified": True,
            },
            token=token,
        )

    async def find_for_user(
        self, user_id: str, cert_id: str, token: str
    ) -> dict[str, Any] | None:
        rows = await self._db.select(
            "certificates",
            columns=_DETAIL_COLUMNS,
            filters={"id": f"eq.{cert_id}", "user_id": f"eq.{user_id}"},
            limit=1,
            token=token,
        )
        return rows[0] if rows else None

    async def list_for_user(
        self, user_id: str, token: str, limit: int = 50
    ) -> list[dict[str, Any]]:
        return await self._db.select(
            "certificates",
            columns=_LIST_COLUMNS,
            filters={"user_id": f"eq.{user_id}"},
            order="earned_at.desc",
            limit=limit,
            token=token,
        )

    async def verify_public(self, cert_id: str) -> dict[str, Any] | None:
        """Public verification (service-role, server-side; public-safe fields only)."""
        rows = await self._db.select(
            "certificates",
            columns=_LIST_COLUMNS,
            filters={"id": f"eq.{cert_id}", "is_verified": "eq.true"},
            limit=1,
            service_role=True,
        )
        row = rows[0] if rows else None
        if row is None or not bool(row.get("is_verified")):
            return None
        return {
            "id": str(row.get("id") or cert_id),
            "title": str(row.get("title") or "Certificate of Achievement"),
            "quiz_title": str(row.get("quiz_title") or ""),
            "student_name": str(row.get("student_name") or ""),
            "score": float(row.get("score") or 0),
            "earned_at": str(row.get("earned_at") or ""),
        }
