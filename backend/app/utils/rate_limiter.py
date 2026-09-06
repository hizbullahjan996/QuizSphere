"""Rate limiter - port of PHP `RateLimiter` (in-memory, same limits)."""

import hashlib
import time

from fastapi import Request

from app.core.config import Settings

DEFAULT_MAX = 10
DEFAULT_WINDOW = 3600


class RateLimiter:
    def __init__(self, settings: Settings):
        self._max = settings.AI_RATE_LIMIT_MAX or DEFAULT_MAX
        self._window = settings.AI_RATE_LIMIT_WINDOW or DEFAULT_WINDOW
        self._hits: dict[str, list[float]] = {}

    @staticmethod
    def client_ip(request: Request) -> str:
        fwd = request.headers.get("X-Forwarded-For")
        if fwd and "," in fwd:
            fwd = fwd.split(",")[0].strip()
        ip = fwd or (request.client.host if request.client else "unknown")
        return ip[:64]

    @staticmethod
    def actor(user_id: str | None, ip: str | None) -> str:
        return f"{user_id or 'anon'}|{ip or 'unknown'}"

    def hit(self, actor: str) -> bool:
        key = hashlib.sha256(actor.encode()).hexdigest()
        now = time.time()
        hits = [t for t in self._hits.get(key, []) if now - t < self._window]
        if len(hits) >= self._max:
            self._hits[key] = hits
            return False
        hits.append(now)
        self._hits[key] = hits
        return True
