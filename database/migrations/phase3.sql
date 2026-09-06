-- ============================================================================
-- QuizSphere - Phase 3 migration
-- Adds AI-generation support columns to the existing Phase 2 schema.
-- Run this in the Supabase SQL Editor AFTER running database/schema.sql.
-- ============================================================================

-- Quizzes: track which provider generated it and the question format.
alter table public.quizzes
  add column if not exists ai_provider   text default 'gemini';
alter table public.quizzes
  add column if not exists question_type text default 'multiple_choice';

-- Questions: each generated question carries a human explanation.
alter table public.questions
  add column if not exists explanation   text;

-- Attempts: remember the provider to keep audits honest (nullable, additive).
alter table public.quiz_attempts
  add column if not exists ai_provider   text;
