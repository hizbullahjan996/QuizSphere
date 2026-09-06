# QuizSphere — Final Project Report

**AI-powered adaptive learning platform** — a hackathon project now complete
through **Phase 6** (final polish: gamification, leaderboard, certificates,
QR verification, security/performance review, and documentation).

---

## 1. What was built (by phase)

| Phase | Scope | Status |
|-------|-------|--------|
| 1 | Landing, login/register UI, dashboard UI, quiz UI, results UI | Complete |
| 2 | Supabase (GoTrue) auth, relational schema + RLS, server-side grading | Complete |
| 3 | AI quiz generation (topic), Gemini→Groq fallback, rate-limit + CSRF | Complete |
| 4 | Concept mastery, adaptive practice, performance analytics | Complete |
| 5 | PDF → AI quiz + AI Learning Coach, private storage bucket | Complete |
| 6 | XP/levels/streaks, achievements, leaderboard, certificates + QR verify, final dashboard, README/report | Complete |

### Phase 6 feature summary
- **XP / levels / streaks**: transparent rules in `includes/Gamification.php`
  (`+10` base, `+1/percent`, `+15` perfect, `+5` daily, `+15` per improved weak
  concept). Persisted on every completed attempt.
- **Achievements**: seeded catalogue; badge granted only when real criteria are
  met (`first_quiz`, `quiz_master`, `percent_90`, `perfect_score`, `streak_7`,
  `improvement_champion`, `certificate_earned`).
- **Leaderboard**: `get_leaderboard(limit)` + `get_user_rank(uid)`
  security-definer Postgres functions called via `SupabaseClient::rpc()`; only
  lightweight public fields exposed.
- **Certificates**: awarded for ≥ 80%, generated client-side (jsPDF + QRCode.js),
  downloadable PDF with a QR linking to the public verification page.
- **Public verification**: `verify-certificate.php` + `api/verify-certificate.php`
  confirm authenticity without leaking account data.

---

## 2. Key files added/changed in Phase 6

**Backend services**
- `includes/Gamification.php` — XP/level/streak/achievement rules.
- `includes/CertificateService.php` — certificate create/list/public-verify.
- `includes/SupabaseClient.php` — new `rpc()` method (PostgREST function call).

**API endpoints** (`api/`)
- `gamification.php` — profile/level/achievements/rank/recent attempts.
- `leaderboard.php` — top learners + own rank.
- `certificates.php` — GET list/single, POST request-by-attempt (CSRF).
- `verify-certificate.php` — public verification (no auth).
- `submit_attempt.php` — awards XP/streak/achievements/certificate; stashes
  `$_SESSION['_rewards']`.
- `attempt.php` — surfaces + clears `_rewards` for the results page.

**Pages**
- `pages/leaderboard.php`, `pages/certificates.php`,
  `pages/view-certificate.php` (QR + PDF), `verify-certificate.php` (public).
- `pages/dashboard.php` — level progress, achievements grid, certificates card,
  leadership rank hint + quick-action buttons.
- `pages/results.php` — rewards banner.

**Frontend**: `assets/js/app.js` (handlers 16–20 + `renderRewards`; wired in
DOMContentLoaded), `assets/css/style.css` (Phase 6 styles), `includes/sidebar.php`
(Leaderboard / Certificates links), `includes/footer.php` (Verify Certificate link).

**Database**: `database/migrations/phase6.sql` (certificate columns, achievement
seeds, anon-verify guard, leaderboard functions; base schema already carries the
gamification columns and anon-visible verified-certificate policy).

---

## 3. API surface (auth-gated unless noted)

| Endpoint | Method | Auth | Purpose |
|----------|--------|------|---------|
| `api/gamification.php` | GET | yes | Gamification status for dashboard |
| `api/leaderboard.php` | GET | yes | Ranked top learners + own rank |
| `api/certificates.php` | GET/POST | yes | List/single cert, request cert by attempt |
| `api/verify-certificate.php` | GET | **no** | Public cert verification |
| `api/submit_attempt.php` | POST | yes | Grade + award XP/streak/badges/cert |
| `api/attempt.php` | GET | yes | Attempt detail + one-time rewards |

All mutations require CSRF; sensitive endpoints are rate-limited.

---

## 4. Gamification rules (source of truth)

- `GamificationService::LEVEL_THRESHOLDS`
  `[0,500,1000,1500,2500,4000,6000,8000,10000,12500,15000]`
- XP: `+10` base, `+1/percent` accuracy, `+15` perfect (100%),
  `+5` first attempt of the day, `+15` per concept improved from weak (<60%).
- Streak: consecutive days with an attempt; same-day repeat keeps the streak
  (no reset/bonus); a gap resets to 1.
- Certificate eligibility: ≥ `MIN_PERCENT` (80%) on a completed attempt;
  uniqueness enforced via `(user_id, attempt_id)`.

---

## 5. Security & privacy

- No API keys ever reach the browser — kept server-side in `config/env.php`
  (git-ignored).
- Row Level Security on every table; users only touch their own rows.
- Leaderboard via security-definer functions returning only public fields.
- Public verification exposes only signed-off certificate metadata.
- CSRF on all mutations, rate limiting on auth/AI/upload, server-side grading,
  constant-time UUID validation, `e()`/`escapeHtml()` output escaping.
- Reviewed and fixed: owned-achievement detection (previously could re-grant
  badges), repeat-certificate badge spam, missing `rank` → 0 coercion.

## 6. Performance notes

- Achievement lookup avoids table embeds (maps ids to codes in PHP).
- Removed an unused 500-row `bestScoreIncluding` scan on every submit.
- Leaderboard ranking is computed in Postgres (single RPC), not in PHP.

---

## 7. Known limitations

- No PHP CLI / Node in the build environment, so PHP/JS are **not** machine
  linted and there is **no automated end-to-end test run** — verification is
  by manual review + brace/paren balance checks. Run the Demo Flow below against
  a live Supabase project before presenting.
- AI/PDF depend on internet (Gemini/Groq keys, jsPDF/QRCode CDNs).
- Certificate PDF is client-generated; to add a server-side DPI-safe copy you
  would need a PDF backend (out of scope).

---

## 8. How to run

1. PHP server: `php -S localhost:8000` (or XAMPP/WAMP).
2. Supabase: run `database/schema.sql`, then `phase3.sql` → `phase4.sql` →
   `phase5.sql` → `phase6.sql`.
3. Copy `config/env.example.php` → `config/env.php`; fill keys (`SUPABASE_URL`,
   `SUPABASE_ANON_KEY`, `GEMINI_API_KEY`, `GROQ_API_KEY`, `SESSION_SECRET`,
   `APP_URL`).
4. Set `BASE_URL` in `config/config.php` if under a sub-path.

See `README.md` for full setup + Security + Deployment notes.

---

## 9. Demo sequence (60–90 seconds)

1. Register / log in.
2. Dashboard: stats, trend, mastery, quick actions, level bar, achievements,
   certificates, rank hint.
3. Generate a quiz from a topic (or upload a PDF, or press a Practice button).
4. Take the quiz → results page shows an **XP/badge/certificate rewards banner**.
5. Open `My Certificates` → `view-certificate.php` → download PDF + show the QR.
6. Scan/click the QR → `verify-certificate.php` confirms authenticity (public).
7. Open `Leaderboard` to see ranking and your position.
8. (Optional) Ask the AI Learning Coach for guidance.

---

*Phase 6 closes the roadmap — all planned phases are implemented. This report
and `README.md` are the final deliverables alongside the code.*

---

# Part B — FastAPI Migration (phases 1–6)

The original PHP backend is being replaced by a FastAPI backend
(`backend/`) via a strangler migration. The frontend now talks to FastAPI for
**all** APIs (including auth); the PHP pages are kept as the server-rendered UI
shell and continue to work because the PHP server-side session is seeded from
FastAPI's successful login. PHP remains as a fallback and must **not** be
deleted until the checklist below is fully green.

## AUTH MIGRATION (Phase 1) — DONE

Hybrid design: FastAPI is the auth authority; the PHP protected-page session is
seeded via a validated bridge.

- FastAPI owns **login / signup / logout / me**:
  `POST /api/auth/login`, `POST /api/auth/signup`,
  `POST /api/auth/logout`, `GET /api/auth/me`.
- Login/signup return `{error, message, redirect, tokens:{access_token, refresh_token}}`.
- The frontend (`assets/js/app.js`) keeps the tokens in **JS memory only** (never
  localStorage/cookies) and forwards them as `Authorization: Bearer <token>`.
- After login/signup the frontend POSTs the tokens to **`api/session_bridge.php`**,
  which validates the access token against Supabase server-side (`getUser`) and
  seeds `$_SESSION` — so `Auth::check()` on protected pages keeps working.
- On logout the frontend revokes the token on FastAPI (`/api/auth/logout`) and
  clears the PHP session via **`api/session_clear.php`**.
- On page reload the token is recovered from the PHP session via
  **`api/session_info.php`** (already existing) so the user stays logged in.

Security: the bridge never trusts client-supplied identity — it derives the user
from a token validated against Supabase. `deps.py` handles both Bearer-token and
cookie+CSRF auth; CSRF is skipped automatically when a valid Bearer token is
present.

Verified end-to-end: bridge seeds session → `session_info.php` exposes token →
`pages/dashboard.php` returns 200 → `session_clear.php` wipes it (user=null).

The PHP `api/auth.php` is no longer called by the frontend (grep of `assets/`
returns nothing) but is retained as the fallback.

## SESSION MANAGEMENT (Phase 2) — DONE, Redis-ready

- `backend/app/core/session.py` refactored to a pluggable store:
  - `InMemorySessionStore` — default (dev/tests).
  - `RedisSessionStore` — production (shared, survives restarts), enabled when
    `SESSION_STORE=redis` + `REDIS_URL`; falls back to memory gracefully.
  - `create_session_store()` selects the backend; routes call async
    `store.get/create/delete`.
- Config: `SESSION_STORE`, `REDIS_URL`, `SESSION_TTL_SECONDS` added to
  `config.py` and `.env.example`.
- Primary auth remains Supabase **Bearer tokens** (stateless); the server-side
  store only secures the CSRF/cookie-session records.
- Documented in `backend/README.md`.

## AUTOMATED TESTS (Phase 3) — DONE

`backend/tests/` (run with `python -m pytest -q` from `backend/`; needs
`pip install pytest pytest-asyncio`):

- **Unit (`tests/test_*.py`)** — no external services/secrets:
  - `test_session_store.py` (8 tests) — both store backends, TTL, serialisation.
  - `test_auth_utils.py` (5 tests) — JWT decode, session-from-bearer, expiry.
  - `test_pdf_extractor.py` (4 tests) — real text PDF extraction + rejection.
- **Live integration (`tests/live/test_live_api.py`)** — 13 tests against the
  running server + real Supabase token; auto-skipped if no token configured.
  Covers health, auth/me, 401s, quizzes, analytics, gamification, leaderboard,
  certificates, verify, practice.

Result: **29 passed** on the live environment.
(Also `node --check` on `app.js` and `php -l` on all PHP bridge endpoints pass.)

## PDF TEST (Phase 4) — DONE (real text-based PDF)

- Generated a valid, standards-compliant text-based PDF (BT/Tf/Td/Tj content
  stream + correct xref).
- `POST /api/pdf_quiz` (multipart) with auth + `question_count=8`:
  returns **200**, extracts text, stores privately in Supabase Storage, and asks
  Gemini to generate a quiz → `quiz id 4a4c4261-...`, retrievable via
  `/api/quiz?id=` with 8 questions.
- Uploaded an image/scanned-only (non-text) PDF: rejected with
  **422 `pdf_unreadable`** — "This PDF could not be processed. Please upload a
  text-based PDF." (both extractor unit-level and HTTP-level confirmed).
- The earlier 422 was a malformed hand-built test PDF, not a backend bug.

## END-TO-END TEST (Phase 5) — DONE

Drove the real learner lifecycle through the live FastAPI + Supabase backend
(`C:\...\Temp\opencode\e2e.py`) — **15/15 checks passed**:

1. `auth/me` returns the user.
2. Dashboard `/api/analytics`.
3. `my_quizzes` → load a quiz with questions.
4. `submit_attempt` (all correct) → returns percent + `gamification.xp_earned`.
5. Attempt detail retrievable.
6. Certificate eligibility respected (201/422); public verify when granted.
7. `/api/practice` generates a quiz.
8. `/api/leaderboard` + `/api/gamification` profile.
9. `/api/coach` (AI Learning Coach) responds.
10. Invalid token → **401**.

## PHP STILL REQUIRED (Phase 6)

Do **not** delete PHP yet. The following remain PHP responsibilities:

- **Server-rendered pages** (`pages/*.php`, `login.php`, `register.php`,
  `index.php`) — the UI shell. The frontend JS talks to FastAPI; the pages just
  render.
- **`includes/Auth.php` + `includes/bootstrap.php`** — PHP session + protected
  page guard (`Auth::check()` uses `$_SESSION['user']` seeded by the bridge).
- **Session bridge endpoints** — `api/session_info.php`, `api/session_bridge.php`,
  `api/session_clear.php` (the cross-boundary glue).
- **SupabaseClient.php / SupabaseException** — used by the bridge.

## PHP RETIREMENT CHECKLIST (Phase 6)

Legend: `[x]` = migrated + tested on FastAPI; `[ ]` = still PHP-only (keep).

| PHP feature (api/) | FastAPI equivalent | Frontend migrated | Tested | Retire when |
|--------------------|--------------------|:---:|:---:|------|
| `auth.php` (login/signup/logout/me) | `/api/auth/*` | [x] | [x] | pages stop using `Auth::check()` |
| `session_info.php` | — (PHP bridge) | [x] | [x] | **keep** (recovery on reload) |
| `session_bridge.php` | — (PHP bridge) | [x] | [x] | **keep** (seeds PHP session) |
| `session_clear.php` | — (PHP bridge) | [x] | [x] | **keep** (clears on logout) |
| `generate_quiz.php` | `/api/generate_quiz` | [x] | [x] | API traffic cutover |
| `pdf_quiz.php` | `/api/pdf_quiz` | [x] | [x] | API traffic cutover |
| `quiz.php` (load) | `/api/quiz` | [x] | [x] | API traffic cutover |
| `my_quizzes.php` | `/api/my_quizzes` | [x] | [x] | API traffic cutover |
| `submit_attempt.php` | `/api/submit_attempt` | [x] | [x] | API traffic cutover |
| `attempt.php` | `/api/attempt` | [x] | [x] | API traffic cutover |
| `practice.php` | `/api/practice` | [x] | [x] | API traffic cutover |
| `analytics.php` | `/api/analytics` | [x] | [x] | API traffic cutover |
| `gamification.php` | `/api/gamification` | [x] | [x] | API traffic cutover |
| `leaderboard.php` | `/api/leaderboard` | [x] | [x] | API traffic cutover |
| `certificates.php` | `/api/certificates` | [x] | [x] | API traffic cutover |
| `verify-certificate.php` | `/api/verify-certificate` | [x] | [x] | API traffic cutover |
| `coach.php` | `/api/coach` | [x] | [x] | API traffic cutover |
| `helpers.php` | (shared utils) | — | — | after above cutover |

PHP includes (services): `QuizRepository.php`, `QuizGenerator.php`,
`PdfTextExtractor.php`, `PerformanceAnalytics.php`, `Gamification.php`,
`CertificateService.php`, `RateLimiter.php`, `StudyMaterialCleaner.php` are now
superseded by FastAPI services — retire alongside the corresponding endpoint
cutover. `Auth.php`, `bootstrap.php`, `SupabaseClient.php` stay until protected
pages are served by the app (not the PHP shell).

### Safe-to-remove gating criteria
1. All frontend API calls hit FastAPI (auth + data) — verified by grep.
2. The full test suite (`pytest`) and the e2e flow pass.
3. A real user completes: register → login → quiz → results → certificate →
   verify → practice → coach → logout → login (no PHP auth endpoints hit).
4. Redirect clients (`/pages/...`) are served, not proxied to PHP.
5. No PHP logs show auth traffic and the bridge endpoints are only used
   transiently (login/logout).
6. Rollback plan: keep a copy; a single env flag can repoint the frontend
   `API_URL`/auth calls back to PHP.

## REMAINING ISSUES

- `login.php` / `register.php` header doc comments still reference
  `api/auth.php`; behavior is migrated but the comments are stale (cosmetic).
- The FastAPI startup log repeats interpolated values 4×
  (`"Starting X X X X ..."`) — cosmetic; pre-existing logging quirk. The
  `logger.info("Session store backend: %s", ...)` is unaffected.
- Login/signup live-authentication tests require real user credentials and are
  therefore not in the auto-run suite (the Bearer-token path and the PHP bridge
  are covered end-to-end instead).
- CORS is restricted to `http://localhost:8000`/`127.0.0.1:8000`; production
  origins must be updated in `backend/.env`.
- Redis store is implemented and unit-tested but not deployed; run the
  `test_live_api.py` suite after enabling `SESSION_STORE=redis` in production.

---
*Part A above is the original PHP project report. Part B (this section) is the
FastAPI migration record. Both are kept on purpose: PHP is the fallback until
the retirement checklist is fully green.*
