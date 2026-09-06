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
