<?php
/**
 * QuizSphere - List my quizzes API
 * -------------------------------------------------------------
 * GET. Authenticated. Returns the authenticated user's quizzes (most
 * recent first) plus simple aggregate stats used by the dashboard.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Security::json(['error' => 'method_not_allowed', 'message' => 'Use GET.'], 405);
}

$services = api_services();
if (!$services) {
    exit;
}
['client' => $client, 'auth' => $auth, 'repo' => $repo] = $services;

api_require_auth($auth);
$userId = $auth->id();
$token  = $auth->accessToken();
if (!$token) {
    Security::json(['error' => 'unauthenticated', 'message' => 'Your session expired.'], 401);
}

try {
    $quizzes = $repo->listUserQuizzes($userId, $token, 50);
    $profile = $auth->profile();
} catch (SupabaseException $e) {
    Security::json(['error' => 'db_error', 'message' => 'Could not load your data right now.'], 500);
}

Security::json([
    'error'   => null,
    'quizzes' => $quizzes,
    'profile' => $profile ?: [
        'full_name' => $auth->user()['full_name'] ?? '',
        'email'     => $auth->user()['email'] ?? '',
        'xp'        => 0,
        'level'     => 1,
        'streak'    => 0,
    ],
], 200);
