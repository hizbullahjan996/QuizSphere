"""Certificate endpoints - behavioral ports of the PHP certificate APIs.

GET  /certificates            list own certificates
GET  /certificates?id=        single own certificate
POST /certificates            request a certificate for a qualifying attempt
GET  /verify-certificate?id=  PUBLIC verification (no auth, public-safe fields)

Certificate PDF "download" is client-side in the existing frontend (jsPDF +
QRCode.js on the view page) - there is no server-side download endpoint in
PHP, so none is added here; the data contract it consumes is preserved.
"""

import re

from fastapi import APIRouter, Request
from fastapi.responses import JSONResponse

from app.api.deps import authed_with_token, require_csrf
from app.core.errors import APIError
from app.repositories.certificate_repository import CertificateRepository
from app.repositories.profile_repository import ProfileRepository
from app.repositories.quiz_repository import QuizRepository
from app.services.certificate_service import CertificateService, MIN_PERCENT
from app.utils.supabase import get_supabase

router = APIRouter(tags=["certificates"])

UUID_RE = re.compile(r"^[0-9a-fA-F-]{36}$")


@router.get("/certificates")
async def certificates(request: Request, id: str = "") -> dict:
    session, token = await authed_with_token(request)
    repo = CertificateRepository(get_supabase())

    if id:
        if not UUID_RE.match(id):
            raise APIError("Invalid certificate id.", 400, "bad_request")
        row = await repo.find_for_user(session.user["id"], id, token)
        if row is None:
            raise APIError("Certificate not found.", 404, "not_found")
        return {"error": None, "certificate": row}

    rows = await repo.list_for_user(session.user["id"], token)
    return {"error": None, "certificates": rows}


@router.post("/certificates")
async def create_certificate(request: Request) -> dict:
    session, token = await authed_with_token(request)
    body = await request.json() if await request.body() else {}
    if not isinstance(body, dict):
        body = {}
    require_csrf(request, session, body)

    attempt_id = str(body.get("attempt_id") or "")
    if not UUID_RE.match(attempt_id):
        raise APIError("A valid attempt id is required.", 400, "bad_request")

    db = get_supabase()
    quiz_repo = QuizRepository(db)
    found = await quiz_repo.find_attempt_for_user(session.user["id"], attempt_id, token)
    if found is None:
        raise APIError("That attempt could not be found.", 404, "not_found")

    attempt = found["attempt"]
    percent = float(attempt.get("percent") or 0)
    quiz_id = str(attempt.get("quiz_id") or "")

    quiz_title = "Completed Quiz"
    if quiz_id:
        quiz = await quiz_repo.find_quiz_for_user(session.user["id"], quiz_id, token)
        if quiz:
            title = str(quiz["quiz"].get("title") or "").strip()
            if title:
                quiz_title = title

    profile = await ProfileRepository(db).get(session.user["id"], token)
    name = str(
        (profile or {}).get("full_name")
        or session.user.get("full_name")
        or "Learner"
    )

    created = await CertificateService(db).create_for_attempt(
        session.user["id"], token, attempt_id, quiz_id, quiz_title, percent, name
    )
    if created is None:
        raise APIError(
            f"Certificates are awarded for scores of {int(MIN_PERCENT)}% or higher on a completed quiz.",
            422,
            "ineligible",
        )
    return JSONResponse(status_code=201, content={"error": None, "certificate": created})


@router.get("/verify-certificate")
async def verify_certificate(request: Request, id: str = "") -> dict:
    """Public verification - no auth; returns only public-safe fields."""
    if not UUID_RE.match(id or ""):
        raise APIError("Invalid certificate id.", 400, "bad_request")

    row = await CertificateRepository(get_supabase()).verify_public(id)
    if row is None:
        return JSONResponse(
            status_code=404,
            content={
                "error": "not_found",
                "verified": False,
                "message": "This certificate could not be verified.",
            },
        )

    return {"error": None, "verified": True, "certificate": row}
