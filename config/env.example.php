<?php
/**
 * QuizSphere - Environment configuration (EXAMPLE)
 * -------------------------------------------------------------
 * COPY this file to config/env.php and fill in your real values.
 * config/env.php is the ONLY place secrets live (it should be gitignored
 * and never deployed to a public web root).
 *
 * You will NOT find these values by reading this repo — they are
 * generated in your Supabase project dashboard.
 */

// Your Supabase project URL, e.g. https://abcd1234.supabase.co
define('SUPABASE_URL', '');

// The public "anon" key. It is safe to expose to server-side PHP and is
// the only key the PHP backend uses for client-facing requests.
define('SUPABASE_ANON_KEY', '');

// The "service_role" key. THIS IS PRIVILEGED. It bypasses Row Level
// Security and MUST NEVER be placed in frontend JavaScript.
define('SUPABASE_SERVICE_ROLE_KEY', '');

// Reference / default schema used by PostgREST.
define('SUPABASE_SCHEMA', 'public');

// Optional: pin the Supabase host to a specific IP ("host:port:ip") when a
// DNS entry/route is unreachable from your network. Leave '' to use DNS.
// Example: 'abcd1234.supabase.co:443:203.0.113.10'
define('SUPABASE_RESOLVE', '');

// Optional (defaults to true in config/config.php). When Supabase's hourly
// email quota blocks the signup confirmation email, registration completes
// via a server-side admin-confirmed account instead of failing. Set to
// false to always require the email-confirmation flow.
// define('AUTH_QUOTA_FALLBACK', false);

// ---------------------------------------------------------------------------
// AI PROVIDERS (3-Tier Cascade)
// 1. OpenAI (Primary) -> 2. Gemini (First Fallback) -> 3. Groq (Second Fallback)
// Keys are used ONLY server-side by the AI service layer. They are NEVER
// sent to or embedded in the frontend JavaScript.
// ---------------------------------------------------------------------------

// Configurable provider priority (default: openai -> gemini -> groq)
define('AI_PRIMARY_PROVIDER', 'openai');
define('AI_FALLBACK_PROVIDER', 'gemini');
define('AI_SECOND_FALLBACK', 'groq');

// 1. OpenAI (Primary)
define('OPENAI_API_KEY', '');
define('OPENAI_MODEL', 'gpt-4o-mini');
define('OPENAI_TIMEOUT', 30.0);

// 2. Google Gemini (First Fallback)
define('GEMINI_API_KEY', '');
define('GEMINI_MODEL', 'gemini-2.5-flash');
define('GEMINI_TIMEOUT', 30.0);

// 3. Groq (Second Fallback)
define('GROQ_API_KEY', '');
define('GROQ_MODEL', 'openai/gpt-oss-20b');
define('GROQ_TIMEOUT', 30.0);

// ---- Optional SMTP-less password-reset redirect (set to your base URL) ----
define('APP_HOST_URL', BASE_URL ?? '');

// Optional: FastAPI backend URL (defaults to http://localhost:8001 if omitted)
// define('API_URL', 'http://localhost:8081');

