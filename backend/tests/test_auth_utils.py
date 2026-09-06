"""Unit tests for auth helpers that need no external service.

Covers JWT payload decoding and building a lightweight session from a Bearer
token - the logic behind the PHP bridge -> FastAPI Bearer auth flow.
"""

import time

import pytest

from app.api.deps import _decode_jwt_payload, _session_from_bearer
from app.core.errors import APIError


def _make_jwt(payload: dict) -> str:
    import base64
    import json

    def b64(data: str) -> str:
        return base64.urlsafe_b64encode(data.encode()).rstrip(b"=").decode()

    body = json.dumps(payload)
    # header.payload.signature (signature ignored for decode)
    return f"{b64(json.dumps({'alg': 'none'}))}.{b64(body)}.fakesig"


def test_decode_jwt_payload_roundtrip():
    claims = {"sub": "u-123", "email": "x@y.com", "exp": int(time.time()) + 3600}
    token = _make_jwt(claims)
    assert _decode_jwt_payload(token)["sub"] == "u-123"


def test_decode_jwt_payload_invalid():
    assert _decode_jwt_payload("not-a-jwt") is None
    assert _decode_jwt_payload("a.b") is None  # not enough segments


def test_session_from_bearer_valid():
    claims = {
        "sub": "u-1",
        "email": "u@example.com",
        "user_metadata": {"full_name": "Test User"},
        "exp": int(time.time()) + 3600,
    }
    session = _session_from_bearer(_make_jwt(claims))
    assert session.user["id"] == "u-1"
    assert session.logged_in


def test_session_from_bearer_invalid_token():
    with pytest.raises(APIError) as exc:
        _session_from_bearer("garbage")
    assert exc.value.status_code == 401


def test_session_from_bearer_expired():
    claims = {"sub": "u-1", "email": "u@example.com", "exp": int(time.time()) - 5}
    with pytest.raises(APIError) as exc:
        _session_from_bearer(_make_jwt(claims))
    assert exc.value.status_code == 401
