-- ============================================================================
-- QuizSphere - Phase 4 migration
-- Adaptive learning & personalized practice.
-- The core concept-performance accumulation uses the existing
-- concept_performance table (attempts / correct / mastery); no structural
-- change is required there. Below is an additive index to speed up the
-- analytics score-trend queries.
-- Run this in the Supabase SQL Editor after phase3.sql.
-- ============================================================================

-- Speed up analytics queries that bucket attempts by completion date.
create index if not exists idx_attempts_completed
  on public.quiz_attempts (completed_at)
  where completed_at is not null;
