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
