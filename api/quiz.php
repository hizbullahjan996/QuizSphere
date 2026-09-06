<?php
/**
 * QuizSphere - Fetch a quiz API
 * -------------------------------------------------------------
 * GET ?id=<quiz_uuid>. Authenticated. Returns the quiz metadata and its
 * questions. Correct answers are deliberately NOT included — grading is
 * done server-side on submit.
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
    Security::json(['error' => 'unauthenticated', 'message' => 'Your session expired. Please log in again.'], 401);
}

$quizId = (string) ($_GET['id'] ?? '');
if ($quizId === '' || !preg_match('/^[0-9a-fA-F-]{36}$/', $quizId)) {
    Security::json(['error' => 'bad_request', 'message' => 'A valid quiz id is required.'], 400);
}

try {
    $data = $repo->findQuizForUser($userId, $quizId, $token);
} catch (SupabaseException $e) {
    Security::json(['error' => 'db_error', 'message' => 'Could not load the quiz right now.'], 500);
}

if ($data === null) {
    Security::json(['error' => 'not_found', 'message' => 'Quiz not found or you do not have access to it.'], 404);
}

Security::json([
    'error' => null,
    'quiz'  => [
        'id'             => $data['quiz']['id'],
        'title'          => $data['quiz']['title'],
        'topic'          => $data['quiz']['topic'],
        'difficulty'     => $data['quiz']['difficulty'],
        'question_count' => $data['quiz']['question_count'],
        'question_type'  => $data['quiz']['question_type'],
        'provider'       => $data['quiz']['ai_provider'],
    ],
    'questions' => $data['questions'],
], 200);
