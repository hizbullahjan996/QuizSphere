<?php
/**
 * QuizSphere - Session bridge (FastAPI -> PHP)
 * ---------------------------------------------------
 * Called by the frontend AFTER a successful FastAPI login/signup. It seeds the
 * PHP server-side session (used by protected pages via Auth::check()) with the
 * user identity derived from the Supabase access token.
 *
 * SECURITY: Never trusts client-supplied identity. It validates the access
 * token against Supabase (GET /auth/v1/user) and derives the user server-side,
 * so a forged token cannot create an authenticated session.
 *
 * Request:  POST  { access_token, refresh_token? }
 * Response: { ok: true, user: {id, email, full_name} }
 *           { ok: false, message }
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($input)) {
    $input = [];
}

$access = (string) ($input['access_token'] ?? '');
$refresh = (string) ($input['refresh_token'] ?? '');

if ($access === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Missing access token.']);
    exit;
}

try {
    $client = SupabaseClient::fromConfig();
} catch (SupabaseException $e) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'The backend is not configured yet.']);
    exit;
}

// Validate the token against Supabase to derive the user server-side.
try {
    $user = $client->getUser($access);
} catch (SupabaseException $e) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Invalid or expired token.']);
    exit;
}

if (!is_array($user) || empty($user['id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Invalid token.']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['user'] = [
    'id'        => $user['id'],
    'email'     => $user['email'] ?? '',
    'full_name' => $user['user_metadata']['full_name'] ?? '',
    'avatar'    => $user['user_metadata']['avatar_url'] ?? null,
];
$_SESSION['auth'] = [
    'access_token'  => $access,
    'refresh_token' => $refresh,
];
$_SESSION['logged_in_at'] = time();

echo json_encode([
    'ok'   => true,
    'user' => [
        'id'        => $user['id'],
        'email'     => $user['email'] ?? '',
        'full_name' => $user['user_metadata']['full_name'] ?? '',
    ],
]);
