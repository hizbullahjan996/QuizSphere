"""Profile repository - Python port of the PHP profile data access.

`profiles` rows are bootstrapped with the service-role key (mirrors PHP
Auth::register); reads use the caller's token so RLS applies.
"""

from typing import Any

from app.utils.supabase import SupabaseClient


class ProfileRepository:
    def __init__(self, db: SupabaseClient):
        self._db = db

    async def get(self, user_id: str, token: str | None = None) -> dict[str, Any] | None:
        rows = await self._db.select(
            "profiles",
            columns="*",
            filters={"id": f"eq.{user_id}"},
            limit=1,
            token=token,
        )
        return rows[0] if rows else None

    async def ensure_exists(
        self, user_id: str, email: str, full_name: str
    ) -> None:
        """Service-role bootstrap insert; duplicates are ignored (trigger may
        have created the row already) - same semantics as PHP."""
        try:
            await self._db.insert(
                "profiles",
                {"id": user_id, "email": email, "full_name": full_name},
                service_role=True,
            )
        except Exception:
            pass
