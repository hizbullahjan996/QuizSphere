<?php
/**
 * QuizSphere - Application Configuration
 * -------------------------------------------------------------
 * Loads environment/secret configuration from config/env.php.
 *
 * SECURITY: Secrets (Supabase keys) live ONLY in config/env.php which is
 * git-ignored. They are never placed in frontend JavaScript.
 */

declare(strict_types=1);

// --------------------------------------------------------------------
// Base settings
// --------------------------------------------------------------------
define('APP_NAME', 'QuizSphere');
define('APP_TAGLINE', 'AI-Powered Adaptive Learning');
define('APP_VERSION', '2.0.0');

// Base URL used for building links. Leave empty if running from the
// web root of the project.
define('BASE_URL', '');

// --------------------------------------------------------------------
// Environment / secrets (config/env.php is git-ignored)
// --------------------------------------------------------------------
if (file_exists(__DIR__ . '/env.php')) {
    require_once __DIR__ . '/env.php';
    // Ensure AI keys are always defined even if env.example (older) lacked them.
    foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'GROQ_API_KEY', 'GROQ_MODEL'] as $k) {
        if (!defined($k)) {
            define($k, $k === 'GEMINI_MODEL' ? 'gemini-3.6-flash' : ($k === 'GROQ_MODEL' ? 'openai/gpt-oss-20b' : ''));
        }
    }
} else {
    // Safe defaults so the UI can still render without a backend.
    define('SUPABASE_URL', '');
    define('SUPABASE_ANON_KEY', '');
    define('SUPABASE_SERVICE_ROLE_KEY', '');
    define('SUPABASE_SCHEMA', 'public');
    define('APP_HOST_URL', BASE_URL);
    define('GEMINI_API_KEY', '');
    define('GEMINI_MODEL', 'gemini-3.6-flash');
    define('GROQ_API_KEY', '');
    define('GROQ_MODEL', 'openai/gpt-oss-20b');
}

// When Supabase cannot send the signup confirmation email (hourly email
// quota exhausted), complete registration server-side with an admin-
// confirmed account instead of failing. Set to false in config/env.php
// to always require the email-confirmation flow.
if (!defined('AUTH_QUOTA_FALLBACK')) {
    define('AUTH_QUOTA_FALLBACK', true);
}

// When true, new signups are created admin-confirmed so no email
// verification (e.g. Gmail confirmation) is required to log in.
if (!defined('DISABLE_EMAIL_VERIFICATION')) {
    define('DISABLE_EMAIL_VERIFICATION', false);
}

// --------------------------------------------------------------------
// Runtime helpers
// --------------------------------------------------------------------

/**
 * Whether the Supabase backend has been configured (env.php present with keys).
 */
function supabase_configured(): bool
{
    return defined('SUPABASE_URL') && SUPABASE_URL !== '' &&
           defined('SUPABASE_ANON_KEY') && SUPABASE_ANON_KEY !== '';
}

/**
 * Whether at least one AI provider (Gemini and/or Groq) is configured.
 */
function ai_configured(): bool
{
    return (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '') ||
           (defined('GROQ_API_KEY') && GROQ_API_KEY !== '');
}

/**
 * Mask a secret for display/debugging (never echoes raw keys).
 */
function mask_secret(string $value): string
{
    if ($value === '') {
        return '(not set)';
    }
    return substr($value, 0, 6) . '…' . substr($value, -4);
}

// --------------------------------------------------------------------
// Session (single, centralised start)
// --------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}
