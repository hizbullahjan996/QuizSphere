<?php
/**
 * QuizSphere - Certificates API
 * -------------------------------------------------------------
 * GET  (auth)            -> list the user's certificates, or a single one ?id=
 * POST (auth, CSRF)      -> request a certificate for a specific quiz attempt.
 *
 * Eligibility (>=80%) and the data shown are always derived server-side from
 * the persisted attempt — never trusted from the client.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    Security::json(['error' => 'method_not_allowed', 'message' => 'Use GET or POST.'], 405);
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

$cert = new CertificateService($client);

// ---------------------------------------------------------------
// POST: request a certificate for a completed attempt.
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = Security::readJsonInput();
    api_require_csrf($input);

    $attemptId = (string) ($input['attempt_id'] ?? '');
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', $attemptId)) {
        Security::json(['error' => 'bad_request', 'message' => 'A valid attempt id is required.'], 400);
    }

    try {
        $found = $repo->findAttemptForUser($userId, $attemptId, $token);
    } catch (SupabaseException $e) {
        $found = null;
    }
    if (!$found) {
        Security::json(['error' => 'not_found', 'message' => 'That attempt could not be found.'], 404);
    }

    $attempt = $found['attempt'] ?? [];
    $percent = (float) ($attempt['percent'] ?? 0);
    $quizId  = (string) ($attempt['quiz_id'] ?? '');

    // Resolve quiz title server-side.
    $quizTitle = 'Completed Quiz';
    if ($quizId !== '') {
        $quiz = $repo->findQuizForUser($userId, $quizId, $token);
        if ($quiz) {
            $quizTitle = trim((string) ($quiz['quiz']['title'] ?? '')) !== '' ? $quiz['quiz']['title'] : $quizTitle;
        }
    }

    $name = (string) ($auth->profile()['full_name'] ?? ($auth->user()['full_name'] ?? 'Learner'));
    $created = $cert->createForAttempt($userId, $token, $attemptId, $quizId, $quizTitle, $percent, $name);

    if ($created === null) {
        Security::json([
            'error'   => 'ineligible',
            'message' => 'Certificates are awarded for scores of ' . CertificateService::MIN_PERCENT . '% or higher on a completed quiz.',
        ], 422);
    }

    Security::json(['error' => null, 'certificate' => $created], 201);
}

// ---------------------------------------------------------------
// GET: single by ?id=, else list.
// ---------------------------------------------------------------
$id = (string) ($_GET['id'] ?? '');
if ($id !== '') {
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', $id)) {
        Security::json(['error' => 'bad_request', 'message' => 'Invalid certificate id.'], 400);
    }
    $row = $cert->findForUser($userId, $id, $token);
    if (!$row) {
        Security::json(['error' => 'not_found', 'message' => 'Certificate not found.'], 404);
    }
    Security::json(['error' => null, 'certificate' => $row], 200);
}

$list = $cert->listForUser($userId, $token);
Security::json(['error' => null, 'certificates' => $list], 200);
