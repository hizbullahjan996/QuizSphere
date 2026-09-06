<?php
/**
 * QuizSphere - Submit quiz attempt API
 * -------------------------------------------------------------
 * POST. Authenticated. Accepts the quiz id and the user's selected answers,
 * grades them server-side against the authoritative question data, persists
 * the attempt + answers, updates concept performance, and returns the result
 * (including explanations for review on the results page).
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Security::json(['error' => 'method_not_allowed', 'message' => 'Use POST.'], 405);
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
    Security::json(['error' => 'unauthenticated', 'message' => 'Your session expired. Please log in again.'], 401);
}

$input = Security::readJsonInput();
$quizId = (string) ($input['quiz_id'] ?? '');
$provider = (string) ($input['provider'] ?? 'gemini');
$answers = $input['answers'] ?? [];

if (!preg_match('/^[0-9a-fA-F-]{36}$/', $quizId)) {
    Security::json(['error' => 'bad_request', 'message' => 'A valid quiz id is required.'], 400);
}
if (!is_array($answers) || $answers === []) {
    Security::json(['error' => 'bad_request', 'message' => 'Please provide your answers.'], 400);
}

api_require_csrf($input);

// Normalise answers to question_id => selected index.
$normalised = [];
foreach ($answers as $a) {
    $qid = $a['question_id'] ?? null;
    $sel = isset($a['selected']) ? (int) $a['selected'] : -1;
    if (is_string($qid) && $sel >= 0) {
        $normalised[] = ['question_id' => $qid, 'selected' => $sel];
    }
}

if ($normalised === []) {
    Security::json(['error' => 'bad_request', 'message' => 'Your answers are not valid.'], 400);
}

// Snapshot concept performance BEFORE grading so we can detect improvement
// (a concept moving from weak to strong in this attempt) for XP/badges.
$conceptBefore = [];
try {
    $conceptBefore = $repo->listConceptPerformance($userId, $token);
} catch (SupabaseException $e) {
    $conceptBefore = [];
}

try {
    $result = $repo->submitAttempt($userId, $quizId, $provider, $normalised, $token);
} catch (SupabaseException $e) {
    if ($e->status === 404) {
        Security::json(['error' => 'not_found', 'message' => 'Quiz not found.'], 404);
    }
    AppLogger::error('Attempt submission failed', ['user_id' => $userId, 'message' => $e->getMessage()], 'quiz');
    Security::json(['error' => 'db_error', 'message' => 'Could not save your results. Please try again.'], 500);
}

// Gather per-question correctness + explanations for the results review.
$attemptId = $result['attempt']['id'] ?? null;
$detail = $repo->loadFullDetailForReview($userId, $result, $token) ?? [];

// ---------------------------------------------------------------
// Gamification: XP, streak, level, achievements, certificate.
// Awarded only after the attempt is successfully persisted.
// ---------------------------------------------------------------
$gamification = null;
$certificate = null;

try {
    $profile = $auth->profile() ?: [];
    $attemptCount = 0;
    try {
        $attemptCount = count($repo->listUserAttempts($userId, $token, 500)) + 1;
    } catch (SupabaseException $e) {
        $attemptCount = 1;
    }

    $g = new GamificationService($client, $repo);
    $gamification = $g->awardAttempt(
        $userId,
        $token,
        $result,
        $profile,
        $attemptCount,
        $conceptBefore
    );

    // Certificate for high scores (>= 80%).
    $percent = (float) $result['percent'];
    if ($percent >= CertificateService::MIN_PERCENT) {
        $quizTitle = 'Completed Quiz';
        try {
            $quiz = $repo->findQuizForUser($userId, $quizId, $token);
            if ($quiz) {
                $t = trim((string) ($quiz['quiz']['title'] ?? ''));
                if ($t !== '') {
                    $quizTitle = $t;
                }
            }
        } catch (SupabaseException $e) {
            // keep default
        }
        $name = (string) ($profile['full_name'] ?? ($auth->user()['full_name'] ?? 'Learner'));
        $cert = new CertificateService($client);
        $created = $cert->createForAttempt($userId, $token, (string) $attemptId, $quizId, $quizTitle, $percent, $name);
        if ($created !== null) {
            $certificate = [
                'id'    => (string) ($created['id'] ?? ''),
                'title' => 'Certificate of Achievement',
            ];
            // Award the Certificate Earned badge only the first time.
            $badge = $g->grantByCode($userId, $token, 'certificate_earned');
            if ($badge !== null) {
                $gamification['new_achievements'][] = $badge;
            }
        }
    }
} catch (Throwable $ex) {
    AppLogger::warning('Gamification award failed (attempt saved)', ['user_id' => $userId, 'message' => $ex->getMessage()], 'gamification');
}

// Stash the reward summary so the results page can celebrate XP/badges/cert.
if (is_array($gamification) || $certificate !== null) {
    $_SESSION['_rewards'] = [
        'gamification' => $gamification,
        'certificate'  => $certificate,
    ];
}

Security::json([
    'error'      => null,
    'attempt'    => [
        'id'      => $attemptId,
        'quiz_id' => $quizId,
        'score'   => $result['score'],
        'total'   => $result['total'],
        'percent' => $result['percent'],
    ],
    'correct'    => $result['score'],
    'incorrect'  => $result['total'] - $result['score'],
    'total'      => $result['total'],
    'percent'    => $result['percent'],
    'detail'     => $detail,
    'gamification' => $gamification,
    'certificate'  => $certificate,
], 200);
