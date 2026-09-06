"""Repositories package: Supabase data-access ports.

Routes -> services -> repositories -> Supabase. Repositories contain only
data access (table/column names, filters, ordering preserved from PHP);
business rules live in services.
"""

from app.repositories.certificate_repository import CertificateRepository
from app.repositories.profile_repository import ProfileRepository
from app.repositories.quiz_repository import QuizRepository

__all__ = ["QuizRepository", "CertificateRepository", "ProfileRepository"]
