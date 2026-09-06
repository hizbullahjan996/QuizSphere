"""Server-side session store with pluggable backends.

Mirrors the PHP session model: an opaque HttpOnly cookie references a
server-side record that holds the safe user fields AND the Supabase
access/refresh tokens, so tokens never live in the browser.

Two backends:
  * InMemorySessionStore - default, used for local dev and tests.
  * RedisSessionStore    - production-ready, enabled when SESSION_STORE=redis
                           and REDIS_URL is set. Uses the `redis.asyncio`
                           Redis client (JSON-serialised session records).

The store is chosen by `create_session_store(settings)`. Redis is optional at
runtime: if SESSION_STORE=redis but REDIS_URL is unset or the redis package is
not installed, it falls back to the in-memory store so local dev never breaks.

Production configuration (documented in backend/README.md):
  SESSION_STORE=redis
  REDIS_URL=redis://user:pass@host:6379/0
  SESSION_TTL_SECONDS=43200
"""

import asyncio
import json
import secrets
import time
from dataclasses import asdict, dataclass, field
from typing import Any, Protocol, runtime_checkable

COOKIE_NAME = "quizsphere_session"
SESSION_TTL_SECONDS = 12 * 60 * 60  # 12h


@dataclass
class SessionData:
    user: dict[str, Any] | None = None
    auth: dict[str, Any] | None = None
    csrf: str = field(default_factory=lambda: secrets.token_hex(32))
    signup_lock: float = 0.0
    logged_in_at: float = 0.0
    created_at: float = field(default_factory=time.time)
    last_seen: float = field(default_factory=time.time)

    @property
    def logged_in(self) -> bool:
        return self.user is not None

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)

    @classmethod
    def from_dict(cls, data: dict[str, Any] | None) -> "SessionData":
        if not data:
            return SessionData()
        return SessionData(**{k: v for k, v in data.items() if k in cls.__dataclass_fields__})


@runtime_checkable
class SessionStore(Protocol):
    def create(self) -> tuple[str, SessionData]: ...
    def get(self, sid: str | None) -> SessionData | None: ...
    def delete(self, sid: str | None) -> None: ...
    async def aclose(self) -> None: ...


class InMemorySessionStore:
    """Thread-safe in-memory store with TTL (default for dev/tests)."""

    def __init__(self, ttl: int = SESSION_TTL_SECONDS):
        self._ttl = ttl
        self._sessions: dict[str, SessionData] = {}
        self._lock = asyncio.Lock()

    async def create(self) -> tuple[str, SessionData]:
        async with self._lock:
            self._cleanup()
            sid = secrets.token_urlsafe(32)
            session = SessionData()
            self._sessions[sid] = session
            return sid, session

    async def get(self, sid: str | None) -> SessionData | None:
        if not sid:
            return None
        async with self._lock:
            session = self._sessions.get(sid)
            if session is None:
                return None
            if time.time() - session.last_seen > self._ttl:
                del self._sessions[sid]
                return None
            session.last_seen = time.time()
            return session

    async def delete(self, sid: str | None) -> None:
        if sid:
            async with self._lock:
                self._sessions.pop(sid, None)

    async def aclose(self) -> None:
        pass

    def _cleanup(self) -> None:
        now = time.time()
        expired = [k for k, v in self._sessions.items() if now - v.last_seen > self._ttl]
        for k in expired:
            del self._sessions[k]


class RedisSessionStore:
    """Redis-backed store: shared, survives restarts, production-ready.

    Only instantiated when SESSION_STORE=redis and a Redis client can be
    created. The `redis` package is imported lazily so it need not be present
    for local dev or tests.
    """

    def __init__(self, redis_url: str, ttl: int = SESSION_TTL_SECONDS):
        try:
            import redis.asyncio as aioredis  # type: ignore
        except ImportError as exc:  # pragma: no cover - local dev without redis
            raise RuntimeError(
                "SESSION_STORE=redis requires the 'redis' package: pip install redis"
            ) from exc
        self._ttl = ttl
        self._redis = aioredis.from_url(redis_url, decode_responses=True)

    async def create(self) -> tuple[str, SessionData]:
        sid = secrets.token_urlsafe(32)
        session = SessionData()
        await self._redis.set(
            f"quizsphere:session:{sid}",
            json.dumps(session.to_dict()),
            ex=self._ttl,
        )
        return sid, session

    async def get(self, sid: str | None) -> SessionData | None:
        if not sid:
            return None
        raw = await self._redis.get(f"quizsphere:session:{sid}")
        if raw is None:
            return None
        session = SessionData.from_dict(json.loads(raw))
        session.last_seen = time.time()
        await self._redis.set(
            f"quizsphere:session:{sid}",
            json.dumps(session.to_dict()),
            ex=self._ttl,
        )
        return session

    async def delete(self, sid: str | None) -> None:
        if sid:
            await self._redis.delete(f"quizsphere:session:{sid}")

    async def aclose(self) -> None:
        await self._redis.aclose()


def create_session_store(ttl: int = SESSION_TTL_SECONDS, redis_url: str = "") -> Any:
    """Return the configured store: Redis when possible, else in-memory."""
    if redis_url:
        try:
            return RedisSessionStore(redis_url, ttl)
        except Exception:
            return InMemorySessionStore(ttl)
    return InMemorySessionStore(ttl)
