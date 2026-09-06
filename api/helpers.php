<?php
/**
 * QuizSphere - API shared utilities
 * -------------------------------------------------------------
 * Loaded by every api/*.php endpoint. Provides centralised superset
 * requirement (bootstrap), a JSON responder, and an auth gate.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

/**
 * Resolve a SupabaseClient + Auth + QuizRepository trio for an endpoint.
 * Returns null and emits a JSON error when Supabase is not configured.
 */
function api_services(): ?array
{
    try {
        $client = SupabaseClient::fromConfig();
    } catch (SupabaseException $e) {
        Security::json([
            'error'   => 'server_not_configured',
            'message' => 'The backend is not configured yet.',
        ], 503);
        return null;
    }

    $auth = new Auth($client);
    $repo = new QuizRepository($client);
    return ['client' => $client, 'auth' => $auth, 'repo' => $repo];
}

/**
 * Guard an API endpoint: requires an authenticated session.
 * Returns the Auth service on success; otherwise emits JSON 401 and stops.
 */
function api_require_auth(Auth $auth): void
{
    if (!$auth->check() || $auth->id() === null) {
        Security::json([
            'error'   => 'unauthenticated',
            'message' => 'You must be logged in to do that.',
        ], 401);
    }
    // Ensure we have a usable token (may redirect/clear on refresh failure).
    $auth->accessToken();
}

/**
 * Guard state-changing requests with CSRF protection. Reads the token from
 * the parsed JSON body ($input) or the X-CSRF-Token header.
 */
function api_require_csrf(array $input): void
{
    $token = $input['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!Security::csrfValidate($token)) {
        Security::json([
            'error'   => 'invalid_csrf',
            'message' => 'Your session token is invalid. Please reload the page and try again.',
        ], 419);
    }
}

/**
 * Respond with a generic error (used for Supabase/AI outages).
 */
function api_fail(Throwable $e, string $friendly): void
{
    $status = $e instanceof SupabaseException ? $e->status : 500;
    if ($status < 400) {
        $status = 500;
    }
    Security::json([
        'error'   => 'request_failed',
        'message' => $friendly,
    ], $status);
}
