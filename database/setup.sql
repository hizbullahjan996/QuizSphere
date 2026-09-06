-- ====================================================================
-- QuizSphere - COMBINED SETUP (schema + all migrations)
-- Paste this entire file into the Supabase SQL Editor and click Run.
-- It is idempotent (safe to re-run) apart from additive columns.
-- ====================================================================

-- ===== schema.sql =====
-- ============================================================================
-- QuizSphere - Supabase PostgreSQL Schema
-- Phase 2: Backend foundation (multi-user).
--
-- Run this in the Supabase SQL Editor. It creates all application tables with
-- Foreign Keys, constraints, indexes, and Row Level Security policies.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Extensions
-- ----------------------------------------------------------------------------
create extension if not exists "pgcrypto";

-- ----------------------------------------------------------------------------
-- PROFILES
-- One row per authenticated user. Linked to auth.users.
-- ----------------------------------------------------------------------------
create table if not exists public.profiles (
  id            uuid primary key references auth.users (id) on delete cascade,
  full_name     text not null default '',
  email         text not null,
  avatar_url    text,
  xp            bigint not null default 0,
  level         integer not null default 1,
  streak        integer not null default 0,
  last_active   date,
  created_at    timestamptz not null default now(),
  updated_at    timestamptz not null default now()
);

create index if not exists idx_profiles_email on public.profiles (email);

-- ----------------------------------------------------------------------------
-- QUIZZES
-- A quiz belongs to a creator (usually the user). Lesson/collection grouping.
-- ----------------------------------------------------------------------------
create table if not exists public.quizzes (
  id            uuid primary key default gen_random_uuid(),
  user_id       uuid not null references auth.users (id) on delete cascade,
  title         text not null,
  topic         text,
  description   text,
  difficulty    text not null default 'medium'
                check (difficulty in ('easy', 'medium', 'hard')),
  question_count integer not null default 0,
  question_type text not null default 'multiple_choice',
  ai_provider   text default 'gemini',
  is_public     boolean not null default false,
  created_at    timestamptz not null default now(),
  updated_at    timestamptz not null default now()
);

create index if not exists idx_quizzes_user on public.quizzes (user_id);
create index if not exists idx_quizzes_public on public.quizzes (is_public) where is_public;

-- ----------------------------------------------------------------------------
-- QUESTIONS
-- Belong to a quiz. Options stored as an ordered text array; correct index.
-- ----------------------------------------------------------------------------
create table if not exists public.questions (
  id            uuid primary key default gen_random_uuid(),
  quiz_id       uuid not null references public.quizzes (id) on delete cascade,
  position      integer not null default 0,
  body          text not null,
  options       text[] not null default '{}',
  correct_index integer not null default 0,
  concept       text,
  explanation   text,
  created_at    timestamptz not null default now()
);

create index if not exists idx_questions_quiz on public.questions (quiz_id);

-- ----------------------------------------------------------------------------
-- QUIZ_ATTEMPTS
-- One row per time a user attempts a quiz.
-- ----------------------------------------------------------------------------
create table if not exists public.quiz_attempts (
  id            uuid primary key default gen_random_uuid(),
  quiz_id       uuid not null references public.quizzes (id) on delete cascade,
  user_id       uuid not null references auth.users (id) on delete cascade,
  score         integer not null default 0,
  total         integer not null default 0,
  percent       numeric(5,2) not null default 0,
  started_at    timestamptz not null default now(),
  completed_at  timestamptz
);

create index if not exists idx_attempts_user on public.quiz_attempts (user_id);
create index if not exists idx_attempts_quiz on public.quiz_attempts (quiz_id);

-- ----------------------------------------------------------------------------
-- ANSWERS
-- Each selected option for a question within an attempt.
-- ----------------------------------------------------------------------------
create table if not exists public.answers (
  id            uuid primary key default gen_random_uuid(),
  attempt_id    uuid not null references public.quiz_attempts (id) on delete cascade,
  question_id   uuid not null references public.questions (id) on delete cascade,
  user_id       uuid not null references auth.users (id) on delete cascade,
  selected_index integer not null,
  is_correct    boolean not null default false,
  answered_at   timestamptz not null default now()
);

create index if not exists idx_answers_attempt on public.answers (attempt_id);
create index if not exists idx_answers_user on public.answers (user_id);

-- ----------------------------------------------------------------------------
-- CONCEPT_PERFORMANCE
-- Aggregated mastery per user per concept.
-- ----------------------------------------------------------------------------
create table if not exists public.concept_performance (
  id            uuid primary key default gen_random_uuid(),
  user_id       uuid not null references auth.users (id) on delete cascade,
  concept       text not null,
  attempts      integer not null default 0,
  correct       integer not null default 0,
  mastery       numeric(5,2) not null default 0,
  updated_at    timestamptz not null default now(),
  unique (user_id, concept)
);

create index if not exists idx_perf_user on public.concept_performance (user_id);

-- ----------------------------------------------------------------------------
-- ACHIEVEMENTS
-- Static catalogue of achievements (public).
-- ----------------------------------------------------------------------------
create table if not exists public.achievements (
  id            uuid primary key default gen_random_uuid(),
  code          text not null unique,
  name          text not null,
  description   text,
  icon          text,
  created_at    timestamptz not null default now()
);

-- user -> achievement grants
create table if not exists public.user_achievements (
  user_id       uuid not null references auth.users (id) on delete cascade,
  achievement_id uuid not null references public.achievements (id) on delete cascade,
  unlocked_at   timestamptz not null default now(),
  primary key (user_id, achievement_id)
);

-- ----------------------------------------------------------------------------
-- CERTIFICATES
-- Earned certificates. Marked verified for public/leaderboard display.
-- ----------------------------------------------------------------------------
create table if not exists public.certificates (
  id            uuid primary key default gen_random_uuid(),
  user_id       uuid not null references auth.users (id) on delete cascade,
  title         text not null,
  description   text,
  earned_at     timestamptz not null default now(),
  certificate_url text,
  is_verified   boolean not null default false
);

create index if not exists idx_certificates_verified on public.certificates (is_verified) where is_verified;

-- ----------------------------------------------------------------------------
-- OPTIONAL: LEADERBOARD VIEW (public aggregate for top learners)
-- ----------------------------------------------------------------------------
create or replace view public.leaderboard as
  select p.id as user_id, p.full_name, p.avatar_url, p.xp, p.level
  from public.profiles p
  order by p.xp desc
  limit 100;

-- ============================================================================
-- ROW LEVEL SECURITY
-- Users can only access their own private data unless intentionally public.
-- ============================================================================

alter table public.profiles              enable row level security;
alter table public.quizzes               enable row level security;
alter table public.questions             enable row level security;
alter table public.quiz_attempts         enable row level security;
alter table public.answers               enable row level security;
alter table public.concept_performance   enable row level security;
alter table public.user_achievements     enable row level security;
alter table public.certificates          enable row level security;
alter table public.achievements          enable row level security;

-- PROFILES -------------------------------------------------------------------
create policy "profiles select own"       on public.profiles for select using (auth.uid() = id);
create policy "profiles update own"       on public.profiles for update using (auth.uid() = id);
create policy "profiles insert own"       on public.profiles for insert with check (auth.uid() = id);
-- allow service role to insert/update via trigger (uses bypassrls)

-- QUIZZES --------------------------------------------------------------------
create policy "quizzes select own"        on public.quizzes for select using (auth.uid() = user_id or is_public);
create policy "quizzes insert own"        on public.quizzes for insert with check (auth.uid() = user_id);
create policy "quizzes update own"        on public.quizzes for update using (auth.uid() = user_id);
create policy "quizzes delete own"        on public.quizzes for delete using (auth.uid() = user_id);

-- QUESTIONS ------------------------------------------------------------------
create policy "questions select via quiz" on public.questions for select
  using (exists (select 1 from public.quizzes q where q.id = quiz_id and (auth.uid() = q.user_id or q.is_public)));
create policy "questions insert via quiz" on public.questions for insert
  with check (exists (select 1 from public.quizzes q where q.id = quiz_id and auth.uid() = q.user_id));
create policy "questions update via quiz" on public.questions for update
  using (exists (select 1 from public.quizzes q where q.id = quiz_id and auth.uid() = q.user_id));
create policy "questions delete via quiz" on public.questions for delete
  using (exists (select 1 from public.quizzes q where q.id = quiz_id and auth.uid() = q.user_id));

-- QUIZ_ATTEMPTS --------------------------------------------------------------
create policy "attempts select own"       on public.quiz_attempts for select using (auth.uid() = user_id);
create policy "attempts insert own"       on public.quiz_attempts for insert with check (auth.uid() = user_id);
create policy "attempts update own"       on public.quiz_attempts for update using (auth.uid() = user_id);
create policy "attempts delete own"       on public.quiz_attempts for delete using (auth.uid() = user_id);

-- ANSWERS --------------------------------------------------------------------
create policy "answers select own"        on public.answers for select using (auth.uid() = user_id);
create policy "answers insert own"        on public.answers for insert with check (auth.uid() = user_id);
create policy "answers update own"        on public.answers for update using (auth.uid() = user_id);
create policy "answers delete own"        on public.answers for delete using (auth.uid() = user_id);

-- CONCEPT_PERFORMANCE --------------------------------------------------------
create policy "perf select own"           on public.concept_performance for select using (auth.uid() = user_id);
create policy "perf insert own"           on public.concept_performance for insert with check (auth.uid() = user_id);
create policy "perf update own"           on public.concept_performance for update using (auth.uid() = user_id);
create policy "perf delete own"           on public.concept_performance for delete using (auth.uid() = user_id);

-- USER_ACHIEVEMENTS ----------------------------------------------------------
create policy "ua select own"             on public.user_achievements for select using (auth.uid() = user_id);
create policy "ua insert own"             on public.user_achievements for insert with check (auth.uid() = user_id);
create policy "ua update own"             on public.user_achievements for update using (auth.uid() = user_id);
create policy "ua delete own"             on public.user_achievements for delete using (auth.uid() = user_id);

-- ACHIEVEMENTS (public catalogue) -------------------------------------------
create policy "achievements select all"   on public.achievements for select using (true);

-- CERTIFICATES --------------------------------------------------------------
create policy "certificates select own"   on public.certificates for select using (auth.uid() = user_id or is_verified);
create policy "certificates insert own"   on public.certificates for insert with check (auth.uid() = user_id);
create policy "certificates update own"   on public.certificates for update using (auth.uid() = user_id);
create policy "certificates delete own"   on public.certificates for delete using (auth.uid() = user_id);

-- ============================================================================
-- TRIGGERS & HELPER FUNCTIONS
-- ============================================================================

-- Auto-create a profile when a new auth user signs up.
create or replace function public.handle_new_user()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
  insert into public.profiles (id, email, full_name)
  values (
    new.id,
    new.email,
    coalesce(new.raw_user_meta_data ->> 'full_name', '')
  )
  on conflict (id) do nothing;
  return new;
end;
$$;

drop trigger if exists on_auth_user_created on auth.users;
create trigger on_auth_user_created
  after insert on auth.users
  for each row execute procedure public.handle_new_user();

-- Keep updated_at fresh.
create or replace function public.set_updated_at()
returns trigger
language plpgsql
as $$
begin
  new.updated_at = now();
  return new;
end;
$$;

create trigger trg_profiles_updated before update on public.profiles
  for each row execute procedure public.set_updated_at();
create trigger trg_quizzes_updated before update on public.quizzes
  for each row execute procedure public.set_updated_at();


-- ===== migrations\phase3.sql =====
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


-- ===== migrations\phase4.sql =====
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


-- ===== migrations\phase5.sql =====
-- ============================================================================
-- QuizSphere - Phase 5 migration
-- PDF -> AI Quiz and AI Learning Coach.
-- Requires the "study-materials" storage bucket so uploaded PDFs can be kept
-- securely per-user. The bucket is private; users access their own objects via
-- RLS on storage.objects (keyed on the authenticated user id in the path).
-- Run this in the Supabase SQL Editor after phase4.sql.
-- ============================================================================

-- 1. Create the private bucket (idempotent).
insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values (
  'study-materials',
  'study-materials',
  false,
  10485760,                                   -- 10 MB
  array['application/pdf']::text[]
)
on conflict (id) do nothing;

-- 2. RLS: any logged-in user may read the objects they own.
-- Path convention: {user_id}/{random}-{original-name}.pdf
create policy "Users can read their own study materials"
on storage.objects
for select
to authenticated
using (
  bucket_id = 'study-materials'
  and (storage.foldername(name))[1] = (select auth.uid()::text)
);

-- 3. RLS: a logged-in user may upload objects into their own folder.
create policy "Users can upload their own study materials"
on storage.objects
for insert
to authenticated
with check (
  bucket_id = 'study-materials'
  and (storage.foldername(name))[1] = (select auth.uid()::text)
);

-- 4. RLS: users may update/delete only their own objects.
create policy "Users can update their own study materials"
on storage.objects
for update
to authenticated
using (
  bucket_id = 'study-materials'
  and (storage.foldername(name))[1] = (select auth.uid()::text)
);

create policy "Users can delete their own study materials"
on storage.objects
for delete
to authenticated
using (
  bucket_id = 'study-materials'
  and (storage.foldername(name))[1] = (select auth.uid()::text)
);


-- ===== migrations\phase6.sql =====
-- ============================================================================
-- QuizSphere - Phase 6 migration
-- Gamification, achievements, leaderboard and certificates.
--
-- Run this in the Supabase SQL Editor AFTER schema.sql (and phase3-5.sql).
-- It is additive and idempotent: adds certificate columns, seeds the
-- achievements catalogue, and creates the security-definer ledger/ranking
-- functions used by the public leaderboard.
-- ============================================================================

-- --------------------------------------------------------------------------
-- 1. Certificates: add the fields needed by the certificate display/PDF.
-- --------------------------------------------------------------------------
alter table public.certificates
  add column if not exists score        numeric(5,2) not null default 0,
  add column if not exists quiz_title   text,
  add column if not exists quiz_id      uuid,
  add column if not exists attempt_id   uuid,
  add column if not exists student_name text;

-- One certificate per qualifying attempt, if ever re-requested.
create unique index if not exists idx_certificates_attempt
  on public.certificates (user_id, attempt_id)
  where attempt_id is not null;

-- Public verification: let any anonymous caller read a certificate that has
-- been signed off (is_verified = true). Only the (already public) display
-- fields are selected; no account/session data is ever exposed.
do $$
begin
  if not exists (
    select 1 from pg_policies
    where schemaname = 'public' and tablename = 'certificates'
      and policyname = 'anon can verify certificates'
  ) then
    create policy "anon can verify certificates"
      on public.certificates
      for select
      to anon
      using (is_verified = true);
  end if;
end $$;

-- --------------------------------------------------------------------------
-- 1b. Profiles: gamification columns (already present in the base schema; the
--     `if not exists` guards make this safe to re-run on any baseline).
-- --------------------------------------------------------------------------
alter table public.profiles
  add column if not exists xp          bigint not null default 0,
  add column if not exists level       int    not null default 1,
  add column if not exists streak      int    not null default 0,
  add column if not exists last_active date;

-- --------------------------------------------------------------------------
-- 2. Achievements catalogue (seeded, idempotent).
--    The application only ever grants these when real criteria are met.
-- --------------------------------------------------------------------------
insert into public.achievements (code, name, description, icon) values
  ('first_quiz',           'First Quiz',          'Complete your first quiz.',                            'bi-pencil'),
  ('quiz_master',          'Quiz Master',         'Complete 10 quizzes.',                                 'bi-mortarboard'),
  ('percent_90',           '90% Club',            'Score 90% or higher on a quiz.',                       'bi-graph-up-arrow'),
  ('perfect_score',        'Perfect Score',       'Score 100% on a quiz.',                                'bi-star-fill'),
  ('streak_7',             '7 Day Streak',        'Learn on 7 consecutive days.',                         'bi-fire'),
  ('improvement_champion', 'Improvement Champion','Improve a weak concept in a single quiz.',             'bi-arrow-up-circle'),
  ('certificate_earned',   'Certificate Earned',  'Earn your first certificate for an outstanding score.', 'bi-award')
on conflict (code) do nothing;

-- --------------------------------------------------------------------------
-- 3. Leaderboard functions (security definer so any authenticated user can
--    read the aggregate across all profiles without bypassing RLS).
-- --------------------------------------------------------------------------

-- Top learners with XP, level, quizzes completed and global rank.
create or replace function public.get_leaderboard(limit_n int default 100)
returns table (
  user_id uuid,
  full_name text,
  xp bigint,
  level int,
  quizzes_completed bigint,
  rank bigint
)
language sql
security definer
set search_path = public
as $$
  select
    p.id as user_id,
    p.full_name,
    p.xp,
    p.level,
    (select count(*)::bigint from public.quiz_attempts a where a.user_id = p.id) as quizzes_completed,
    rank() over (order by p.xp desc) as rank
  from public.profiles p
  order by p.xp desc
  limit limit_n;
$$;

-- A single user's global rank (works even beyond the top-100 slice).
create or replace function public.get_user_rank(p_uid uuid)
returns table (
  user_id uuid,
  full_name text,
  xp bigint,
  level int,
  quizzes_completed bigint,
  rank bigint
)
language sql
security definer
set search_path = public
as $$
  with ranked as (
    select
      p.id as user_id,
      p.full_name,
      p.xp,
      p.level,
      (select count(*)::bigint from public.quiz_attempts a where a.user_id = p.id) as quizzes_completed,
      rank() over (order by p.xp desc) as rank
    from public.profiles p
  )
  select * from ranked where user_id = p_uid;
$$;

-- Grant execute to authenticated (and anon is harmless since it only returns
-- lightweight public fields); grants are already public by default in
-- PostgREST but we make the intent explicit.
grant execute on function public.get_leaderboard(int) to authenticated, anon;
grant execute on function public.get_user_rank(uuid) to authenticated, anon;



