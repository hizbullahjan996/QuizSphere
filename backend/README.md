# QuizSphere FastAPI Backend

New Python backend for QuizSphere, built alongside the existing PHP backend
(strangler migration). The PHP backend, frontend, database and Supabase data
are untouched.

## Structure

```
backend/
├── app/
│   ├── main.py            # Entry point (uvicorn app.main:app)
│   ├── core/              # config, logging (secret-redacting), errors
│   ├── api/               # routers; all routes under /api
│   │   └── routes/        # health (GET /api/health)
│   ├── models/            # domain models (later phases)
│   ├── schemas/           # pydantic request/response schemas
│   ├── services/          # business logic ports (later phases)
│   ├── repositories/      # data-access ports (later phases)
│   ├── middleware/        # request-id + security headers
│   └── utils/             # async Supabase client (httpx)
├── requirements.txt
├── .env.example
└── README.md
```

## Setup

```bash
cd backend
python -m venv .venv
# Windows:
.venv\Scripts\activate
# Linux/macOS:
source .venv/bin/activate

pip install -r requirements.txt
copy .env.example .env        # then fill in values (never commit .env)
```

## Run

```bash
uvicorn app.main:app --host 127.0.0.1 --port 8001
```

Verify:

```bash
curl http://127.0.0.1:8001/api/health
# {"status":"ok"}
```

Interactive docs: http://127.0.0.1:8001/api/docs

## Security notes

- All secrets (Supabase keys, AI keys, session secret) are read from
  environment variables / `.env` only; they are never returned in responses.
- The logging pipeline redacts any configured secret value that would
  otherwise appear in a log line.
- CORS is restricted to the PHP frontend origins via `CORS_ORIGINS`.
- Errors are returned as `{"error": <code>, "message": <safe text>}`; internal
  exception details are logged server-side only.

## Migration status

Substantially complete. All PHP business logic is ported to FastAPI and the
frontend routes its API calls to this backend. Auth (login/signup/logout) is
now fully on FastAPI: the frontend calls `/api/auth/login`, `/api/auth/signup`
and `/api/auth/logout`, stores the returned Supabase access token in browser
memory, and forwards it as a `Bearer` token on all API calls.

To keep the server-rendered PHP pages protected during the transition:
- After login/signup the frontend forwards the tokens to `api/session_bridge.php`
  (a PHP endpoint that validates the access token against Supabase server-side,
  then seeds the PHP `$_SESSION`). PHP pages that call `Auth::check()` keep working.
- On logout the frontend revokes the token on FastAPI and clears the PHP session
  via `api/session_clear.php`.
- On fresh page loads the access token is recovered from the PHP session via
  `api/session_info.php` so the user stays logged in across reloads.

Ported features:
- Auth (login/signup/logout/me) via Supabase Bearer tokens + CSRF for cookie flow
- Session bridging (PHP bridge/clear/info endpoints)
- Quiz generation (topic + PDF via Gemini/Groq)
- Quiz CRUD / listing
- Quiz submission + grading + gamification (XP/badges) + certificates
- Results / attempt detail
- Adaptive practice
- Analytics
- Gamification (XP/levels/streaks/achievements)
- Leaderboard
- Certificates (list/view/verify)
- AI learning coach

The PHP backend remains available as a fallback until switching traffic is
fully verified. Auth redirects still point to PHP pages (`/pages/dashboard.php`).

## Tests (Phase 3)

The suite lives in `backend/tests`. Install the test dependencies first:

```bash
pip install pytest pytest-asyncio
```

Run the full suite from `backend/`:

```bash
python -m pytest -q
```

There are two groups:

- **Unit tests (`tests/test_*.py`)** - self-contained; no external services or
  secrets. They cover the session store backends and the Bearer-token auth
  helpers (JWT decode, session-from-token, expiry).
- **Live integration tests (`tests/live/`)** - exercise the running FastAPI
  server against the real Supabase project. They are **skipped automatically**
  when no token is configured, so `pytest` still succeeds on developer machines:

  ```bash
  python -m pytest tests/live -q
  ```

  To run them, the FastAPI server must be up (`uvicorn app.main:app --port 8001`)
  and a Supabase access token must be available via the `TEST_TOKEN` path/env
  (default: `C:\Users\<you>\AppData\Local\Temp\opencode\test_token.txt`).

## Session store (Phase 2)

The FastAPI server keeps its own server-side session store for CSRF and the
optional cookie-session path. Two backends are supported:

- **In-memory** (default) - used for local dev and automated tests.
- **Redis** (production) - shared sessions across multiple app instances and
  survives restarts. Enable it by setting `SESSION_STORE=redis` and `REDIS_URL`
  in the backend `.env` (see `.env.example`). Requires `pip install redis`.

The store is selected automatically by `create_session_store()` in
`app/core/session.py`. If `REDIS_URL` is unset (or the `redis` package is
unavailable) it gracefully falls back to the in-memory store so local dev
never breaks.

Production configuration:

```env
SESSION_STORE=redis
REDIS_URL=redis://user:pass@host:6379/0
SESSION_TTL_SECONDS=43200     # 12 hours, matches the session cookie lifetime
```

> Note: the primary auth path is Supabase **Bearer tokens** (stateless - Supabase
> is the session authority, no server storage). The Redis store secures the
> server-side CSRF/cookie session records only; tokens still never live in the
> browser.

## Frontend integration

The PHP pages emit `window.API_URL` (default `http://localhost:8001`) in
`includes/head.php`. `assets/js/app.js` calls the FastAPI backend for all APIs,
including auth. The Supabase access token is kept in JS memory (never
localStorage/cookies) and is recovered from the PHP session on full page loads.
The `/api/auth/logout` endpoint accepts either a Bearer token (frontend flow) or
a cookie session with CSRF.
