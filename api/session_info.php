<?php
/**
 * QuizSphere - Session info endpoint (FastAPI bridge)
 * ---------------------------------------------------
 * Returns the current user's session data so the frontend JS can forward
 * the Supabase access token to the FastAPI backend. This endpoint is
 * called once on page load and the token is kept in JS memory only.
 *
 * Response: { user: {id, email, full_name}, access_token, csrf_token }
 * Or:       { user: null } when not logged in.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user = $_SESSION['user'] ?? null;
$token = $_SESSION['auth']['access_token'] ?? null;
$csrf = $_SESSION['csrf_token'] ?? null;

if (!$csrf) {
    $csrf = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrf;
}

if (!$user || !$token) {
    echo json_encode(['user' => null, 'access_token' => null, 'csrf_token' => $csrf]);
    exit;
}

echo json_encode([
    'user' => [
        'id' => $user['id'] ?? '',
        'email' => $user['email'] ?? '',
        'full_name' => $user['full_name'] ?? '',
    ],
    'access_token' => $token,
    'csrf_token' => $csrf,
]);
