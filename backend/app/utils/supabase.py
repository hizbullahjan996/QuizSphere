"""Async Supabase client (GoTrue + PostgREST + Storage) over httpx.

Python port of the PHP `SupabaseClient`. Mirrors its surface and security
model so repositories/business logic port 1:1:

- The publishable (anon) key is the default for client-facing calls and is
  combined with the *user's* access token so Row Level Security applies.
- The secret (service-role) key bypasses RLS and is used ONLY server-side for
  privileged writes (profile bootstrap, public certificate verification, ...).
- Keys are injected from settings; they never appear in responses or logs
  (the logging layer additionally redacts them).
"""

from typing import Any

import httpx

from app.core.config import Settings
from app.core.errors import APIError

DEFAULT_TIMEOUT = 12.0
MAX_ATTEMPTS = 3


class SupabaseClient:
    """Thin async wrapper around Supabase REST/Auth/Storage endpoints."""

    def __init__(self, settings: Settings, http: httpx.AsyncClient):
        self._settings = settings
        self._http = http
        self._url = settings.SUPABASE_URL.rstrip("/")

    # -- low level ---------------------------------------------------------

    def _headers(
        self,
        key: str,
        auth_token: str | None = None,
        rest: bool = False,
        prefer_representation: bool = False,
    ) -> dict[str, str]:
        headers = {
            "apikey": key,
            "Authorization": f"Bearer {auth_token or key}",
        }
        if rest:
            headers["Accept-Profile"] = self._settings.SUPABASE_SCHEMA
            headers["Content-Profile"] = self._settings.SUPABASE_SCHEMA
            if prefer_representation:
                headers["Prefer"] = "return=representation"
        return headers

    async def request(
        self,
        method: str,
        path: str,
        *,
        key: str | None = None,
        auth_token: str | None = None,
        json_body: Any = None,
        rest: bool = False,
        prefer_representation: bool = False,
        timeout: float = DEFAULT_TIMEOUT,
    ) -> tuple[int, Any]:
        if not self._settings.supabase_configured:
            raise APIError("Supabase is not configured.", 503, "server_not_configured")
        headers = self._headers(
            key or self._settings.SUPABASE_PUBLISHABLE_KEY,
            auth_token,
            rest,
            prefer_representation,
        )

        last_exc: httpx.HTTPError | None = None
        for _attempt in range(MAX_ATTEMPTS):
            try:
                resp = await self._http.request(
                    method,
                    self._url + path,
                    headers=headers,
                    json=json_body,
                    timeout=timeout,
                )
                last_exc = None
                break
            except httpx.HTTPError as exc:  # transport-level failure: retry
                last_exc = exc
        if last_exc is not None:
            raise APIError(
                "Cannot reach the service right now. Please try again in a moment.",
                503,
                "network_error",
            ) from last_exc

        try:
            body = resp.json()
        except ValueError:
            body = resp.text
        return resp.status_code, body

    def _raise_db(self, status: int, verb: str) -> None:
        if status == 404:
            raise APIError(f"{verb} failed: not found.", 404, "not_found")
        raise APIError(f"{verb} failed. Please try again.", status or 500, "db_error")

    # -- PostgREST ---------------------------------------------------------

    async def select(
        self,
        table: str,
        *,
        columns: str = "*",
        filters: dict[str, str] | None = None,
        order: str | None = None,
        limit: int | None = None,
        token: str | None = None,
        service_role: bool = False,
    ) -> list[dict[str, Any]]:
        params: dict[str, Any] = {"select": columns}
        if filters:
            params.update(filters)
        if order:
            params["order"] = order
        if limit:
            params["limit"] = limit
        query = "&".join(f"{k}={httpx.QueryParams({k: v})[k]}" for k, v in params.items())
        key = self._settings.SUPABASE_SECRET_KEY if service_role else None
        status, body = await self.request(
            "GET",
            f"/rest/v1/{table}?{query}",
            key=key,
            auth_token=token,
            rest=True,
        )
        if status >= 400:
            self._raise_db(status, "Query")
        return body if isinstance(body, list) else []

    async def insert(
        self,
        table: str,
        payload: dict[str, Any],
        *,
        token: str | None = None,
        service_role: bool = False,
    ) -> dict[str, Any]:
        key = self._settings.SUPABASE_SECRET_KEY if service_role else None
        status, body = await self.request(
            "POST",
            f"/rest/v1/{table}",
            key=key,
            auth_token=token,
            json_body=payload,
            rest=True,
            prefer_representation=True,
        )
        if status >= 400:
            self._raise_db(status, "Insert")
        if isinstance(body, list):
            return body[0] if body else {}
        return body if isinstance(body, dict) else {}

    async def update(
        self,
        table: str,
        payload: dict[str, Any],
        filters: dict[str, str],
        *,
        token: str | None = None,
    ) -> list[dict[str, Any]]:
        query = "&".join(f"{k}={httpx.QueryParams({k: v})[k]}" for k, v in filters.items())
        status, body = await self.request(
            "PATCH",
            f"/rest/v1/{table}?{query}",
            auth_token=token,
            json_body=payload,
            rest=True,
            prefer_representation=True,
        )
        if status >= 400:
            self._raise_db(status, "Update")
        return body if isinstance(body, list) else []

    async def delete(
        self,
        table: str,
        filters: dict[str, str],
        *,
        token: str | None = None,
    ) -> None:
        query = "&".join(f"{k}={httpx.QueryParams({k: v})[k]}" for k, v in filters.items())
        status, _body = await self.request(
            "DELETE",
            f"/rest/v1/{table}?{query}",
            auth_token=token,
            rest=True,
        )
        if status >= 400:
            self._raise_db(status, "Delete")

    async def rpc(
        self,
        function: str,
        args: dict[str, Any] | None = None,
        *,
        token: str | None = None,
        service_role: bool = False,
    ) -> Any:
        key = self._settings.SUPABASE_SECRET_KEY if service_role else None
        status, body = await self.request(
            "POST",
            f"/rest/v1/rpc/{function}",
            key=key,
            auth_token=token,
            json_body=args or {},
            rest=True,
        )
        if status >= 400:
            self._raise_db(status, "Database function")
        return body

    # -- GoTrue ------------------------------------------------------------

    async def auth_signup(
        self, email: str, password: str, metadata: dict[str, Any] | None = None
    ) -> tuple[int, Any]:
        return await self.request(
            "POST",
            "/auth/v1/signup",
            json_body={"email": email, "password": password, "data": metadata or {}},
        )

    async def auth_token_password(self, email: str, password: str) -> tuple[int, Any]:
        return await self.request(
            "POST",
            "/auth/v1/token?grant_type=password",
            json_body={"email": email, "password": password},
        )

    async def auth_refresh(self, refresh_token: str) -> tuple[int, Any]:
        return await self.request(
            "POST",
            "/auth/v1/token?grant_type=refresh_token",
            json_body={"refresh_token": refresh_token},
        )

    async def auth_user(self, access_token: str) -> tuple[int, Any]:
        return await self.request("GET", "/auth/v1/user", auth_token=access_token)

    async def auth_logout(self, access_token: str) -> None:
        # Best-effort; local session is cleared by the caller regardless.
        try:
            await self.request(
                "POST", "/auth/v1/logout", auth_token=access_token, json_body={}, timeout=6.0
            )
        except APIError:
            pass

    async def admin_create_confirmed_user(
        self, email: str, password: str, metadata: dict[str, Any] | None = None
    ) -> tuple[int, Any]:
        """Service-role only: create a user with email pre-confirmed."""
        return await self.request(
            "POST",
            "/auth/v1/admin/users",
            key=self._settings.SUPABASE_SECRET_KEY,
            json_body={
                "email": email,
                "password": password,
                "email_confirm": True,
                "user_metadata": metadata or {},
            },
        )

    async def admin_list_users(self, email: str) -> tuple[int, Any]:
        """Service-role only: look up users by exact email (GoTrue admin)."""
        return await self.request(
            "GET",
            f"/auth/v1/admin/users?email={httpx.QueryParams({'email': email})['email']}",
            key=self._settings.SUPABASE_SECRET_KEY,
        )

    async def admin_update_user(self, user_id: str, payload: dict[str, Any]) -> tuple[int, Any]:
        """Service-role only: update a user (e.g. email_confirm)."""
        return await self.request(
            "PUT",
            f"/auth/v1/admin/users/{user_id}",
            key=self._settings.SUPABASE_SECRET_KEY,
            json_body=payload,
        )

    # -- Storage -----------------------------------------------------------

    async def upload_object(
        self,
        bucket: str,
        path: str,
        content: bytes,
        mime: str,
        *,
        token: str | None = None,
    ) -> dict[str, Any]:
        headers = self._headers(self._settings.SUPABASE_PUBLISHABLE_KEY, token)
        headers.update({"Content-Type": mime, "x-upsert": "false"})
        try:
            resp = await self._http.post(
                f"{self._url}/storage/v1/object/{bucket}/{path}",
                headers=headers,
                content=content,
                timeout=45.0,
            )
        except httpx.HTTPError as exc:
            raise APIError("Could not store your file. Please try again.", 503, "storage") from exc
        if resp.status_code >= 400:
            raise APIError("Could not store your file. Please try again.", resp.status_code, "storage")
        try:
            body = resp.json()
        except ValueError:
            body = {}
        return body if isinstance(body, dict) else {}


_client: SupabaseClient | None = None


def init_supabase(settings: Settings, http: httpx.AsyncClient) -> SupabaseClient:
    global _client
    _client = SupabaseClient(settings, http)
    return _client


def get_supabase() -> SupabaseClient:
    if _client is None:
        raise APIError("Supabase client is not initialised.", 503, "server_not_configured")
    return _client
