<?php
/**
 * QuizSphere - Session clear (FastAPI logout -> PHP->session)
 * ---------------------------------------------------
 * Called by the frontend AFTER a successful FastAPI logout. Clears the PHP
 * server-side session so protected pages immediately treat the user as
 * logged out (navbar/sidebar update, pages redirect to login).
 *
 * Request:  POST  {}  (any body is ignored; identity comes from the session)
 * Response: { ok: true }
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $p['path'],
        $p['domain'],
        $p['secure'],
        $p['httponly']
    );
}
session_destroy();

echo json_encode(['ok' => true]);
