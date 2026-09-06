"""Domain models package.

QuizSphere persists everything in Supabase (PostgREST); there is no local
ORM. Ported business entities (quiz, attempt, certificate, ...) will be
represented as typed dataclasses/pydantic models here in later phases.
"""
