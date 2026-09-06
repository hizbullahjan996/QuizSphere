<?php
/**
 * QuizSphere - Fetch an attempt (results) API
 * -------------------------------------------------------------
 * GET ?id=<attempt_uuid>. Authenticated. Returns the attempt summary plus
 * the full per-question review (including explanations) for display on the
 * results page.
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
['auth' => $auth, 'repo' => $repo] = $services;

api_require_auth($auth);
$userId = $auth->id();
$token  = $auth->accessToken();
if (!$token) {
    Security::json(['error' => 'unauthenticated', 'message' => 'Your session expired.'], 401);
}

$attemptId = (string) ($_GET['id'] ?? '');
if (!preg_match('/^[0-9a-fA-F-]{36}$/', $attemptId)) {
    Security::json(['error' => 'bad_request', 'message' => 'A valid attempt id is required.'], 400);
}

try {
    $data = $repo->findAttemptForUser($userId, $attemptId, $token);
} catch (SupabaseException $e) {
    Security::json(['error' => 'db_error', 'message' => 'Could not load your results right now.'], 500);
}

if ($data === null) {
    Security::json(['error' => 'not_found', 'message' => 'Attempt not found.'], 404);
}

$attempt = $data['attempt'];
// Build the review detail from the attempt's persisted answers.
$detailRows = $repo->loadDetailForAttempt($userId, $attemptId, $attempt['quiz_id'], $token) ?? [];

Security::json([
    'error'   => null,
    'attempt' => [
        'id'      => $attempt['id'],
        'quiz_id' => $attempt['quiz_id'],
        'score'   => (int) $attempt['score'],
        'total'   => (int) $attempt['total'],
        'percent' => (float) $attempt['percent'],
        'completed_at' => $attempt['completed_at'] ?? null,
    ],
    'correct'   => (int) $attempt['score'],
    'incorrect' => (int) $attempt['total'] - (int) $attempt['score'],
    'total'     => (int) $attempt['total'],
    'percent'   => (float) $attempt['percent'],
    'detail'    => $detailRows,
    'rewards'   => ($_SESSION['_rewards'] ?? null),
], 200);
// Rewards are surfaced once; clear for the next visit.
unset($_SESSION['_rewards']);
