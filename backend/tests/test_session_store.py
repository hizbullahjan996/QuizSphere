"""Unit tests for the session store (Phase 2).

These tests are self-contained and run without any external service or real
secrets (Redis is optional; if not installed the store falls back to memory).
"""

import asyncio

import pytest

from app.core.session import (
    COOKIE_NAME,
    SessionData,
    create_session_store,
)


@pytest.mark.asyncio
async def test_memory_store_create_get_roundtrip():
    store = create_session_store()
    sid, session = await store.create()
    assert sid
    assert isinstance(session, SessionData)
    got = await store.get(sid)
    assert got is not None
    assert got.csrf == session.csrf


@pytest.mark.asyncio
async def test_memory_store_get_unknown_returns_none():
    store = create_session_store()
    assert await store.get("does-not-exist") is None
    assert await store.get(None) is None


@pytest.mark.asyncio
async def test_memory_store_delete():
    store = create_session_store()
    sid, _ = await store.create()
    await store.delete(sid)
    assert await store.get(sid) is None


@pytest.mark.asyncio
async def test_memory_store_ttl_expiry():
    store = create_session_store(ttl=-1)  # already expired
    sid, _ = await store.create()
    assert await store.get(sid) is None


@pytest.mark.asyncio
async def test_auth_roundtrip_through_to_dict():
    s = SessionData()
    s.user = {"id": "u1", "email": "a@b.com"}
    loaded = SessionData.from_dict(s.to_dict())
    assert loaded.user == s.user
    assert loaded.csrf == s.csrf


def test_cookie_name_is_opaque():
    # Ensure the cookie name is stable across backends
    assert COOKIE_NAME == "quizsphere_session"


@pytest.mark.asyncio
async def test_redis_falls_back_when_unavailable():
    # REDIS_URL pointing to a nothing listener should fall back to memory
    # rather than raising, so dev/test runs never hard-fail.
    store = create_session_store(redis_url="redis://127.0.0.1:1/0")
    sid, _ = await store.create()
    assert await store.get(sid) is not None
    await store.aclose()


if __name__ == "__main__":
    asyncio.run(test_memory_store_create_get_roundtrip())
