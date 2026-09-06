"""Live integration tests against the running QuizSphere FastAPI backend.

These require:
  * The FastAPI server running on the API_URL (default http://127.0.0.1:8001)
  * A valid Supabase access token for the live/anonymous test user.

The token is read from the TEST_TOKEN path (or the TEST_TOKEN env var). If no
token is available the suite is skipped, so `pytest` still succeeds locally on
developer machines without secrets.

Run with:
  python -m pytest tests/live -q

These tests validate the migrated API (auth + RLS-aware reads) end-to-end.
They are skipped when a token is not configured.
"""

import json
import os
from pathlib import Path

import httpx
import pytest

API_URL = os.environ.get("TEST_API_URL", "http://127.0.0.1:8001")

_TOKEN_PATHS = [
    Path(os.environ.get("TEST_TOKEN", "C:\\Users\\ABDULL~1\\AppData\\Local\\Temp\\opencode\\test_token.txt")),
    Path(os.pardir) / ".test_token",
]

DEFAULT_TOKEN = os.environ.get("TEST_TOKEN_VALUE", "")


def _load_token() -> str:
    env = os.environ.get("TEST_TOKEN", "")
    if env and "://" not in env and Path(env).exists():
        return Path(env).read_text().strip()
    if Path(_TOKEN_PATHS[0]).exists():
        return Path(_TOKEN_PATHS[0]).read_text().strip()
    return DEFAULT_TOKEN


TOKEN = _load_token()
HEADERS = {"Authorization": f"Bearer {TOKEN}"} if TOKEN else {}


pytestmark = pytest.mark.skipif(
    not TOKEN, reason="No TEST_TOKEN configured - live tests need a token"
)


def client() -> httpx.Client:
    return httpx.Client(base_url=API_URL, timeout=60)


def test_health():
    with client() as c:
        r = c.get("/api/health")
    assert r.status_code == 200
    assert r.json()["status"] == "ok"


def test_auth_me_with_bearer():
    with client() as c:
        r = c.get("/api/auth/me", headers=HEADERS)
    assert r.status_code == 200
    body = r.json()
    assert body["error"] is None
    assert body["user"]["email"]


def test_unauthenticated_quiz_returns_401():
    with client() as c:
        r = c.get("/api/my_quizzes")
    assert r.status_code == 401


def test_unauthenticated_me_returns_401():
    with client() as c:
        r = c.get("/api/auth/me")
    assert r.status_code == 401


def test_my_quizzes_returns_ok():
    with client() as c:
        r = c.get("/api/my_quizzes", headers=HEADERS)
    assert r.status_code == 200
    body = r.json()
    assert body["error"] is None
    assert "quizzes" in body
    assert "profile" in body


def test_analytics_returns_ok():
    with client() as c:
        r = c.get("/api/analytics", headers=HEADERS)
    assert r.status_code == 200
    body = r.json()
    assert body["error"] is None
    assert "stats" in body


def test_gamification_returns_ok():
    with client() as c:
        r = c.get("/api/gamification", headers=HEADERS)
    assert r.status_code == 200
    body = r.json()
    assert body["error"] is None
    assert "profile" in body
    assert "xp" in body["profile"]


def test_leaderboard_returns_ok():
    with client() as c:
        r = c.get("/api/leaderboard", headers=HEADERS)
    assert r.status_code == 200


def test_certificates_list_returns_ok():
    with client() as c:
        r = c.get("/api/certificates", headers=HEADERS)
    assert r.status_code == 200
    assert r.json()["error"] is None


def test_verify_certificate_missing_returns_400():
    with client() as c:
        r = c.get("/api/verify-certificate", params={"id": ""})
    assert r.status_code == 400


def test_invalid_quiz_id_returns_400():
    with client() as c:
        r = c.get("/api/quiz", params={"id": "not-a-uuid"}, headers=HEADERS)
    assert r.status_code == 400


def test_practice_invalid_concept_returns_422():
    with client() as c:
        r = c.post("/api/practice", json={"concept": "", "question_count": 8}, headers=HEADERS)
    assert r.status_code == 422


def test_invalid_token_rejected():
    with client() as c:
        r = c.get("/api/my_quizzes", headers={"Authorization": "Bearer invalid.token.here"})
    assert r.status_code == 401
