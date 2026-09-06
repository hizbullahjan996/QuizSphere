<?php
/**
 * QuizSphere - Adaptive practice API
 * -------------------------------------------------------------
 * POST. Authenticated, CSRF-protected, rate-limited. Generates a focused,
 * targeted quiz for a specific concept/topic and selects difficulty
 * adaptively based on the learner's mastery of that concept (rule-based):
 *   mastery >= 85% -> harder, 60..84% -> maintain, < 60% -> easier.
 *
 * The learner can override difficulty explicitly; otherwise the adaptive
 * suggestion is used.
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
['auth' => $auth, 'repo' => $repo] = $services;

api_require_auth($auth);
$userId = $auth->id();
$token  = $auth->accessToken();
if (!$token) {
    Security::json(['error' => 'unauthenticated', 'message' => 'Your session expired. Please log in again.'], 401);
}

if (!RateLimiter::hit(RateLimiter::actor($userId, RateLimiter::clientIp()))) {
    Security::json([
        'error'   => 'rate_limited',
        'message' => 'You have reached the limit for generating quizzes right now. Please try again later.',
    ], 429);
}

$input  = Security::readJsonInput();
$concept = trim((string) ($input['concept'] ?? ''));
$count  = (int) ($input['question_count'] ?? 8);
$type   = (string) ($input['question_type'] ?? 'multiple_choice');

api_require_csrf($input);

if ($concept === '' || mb_strlen($concept) > 200) {
    Security::json(['error' => 'validation', 'message' => 'Please provide a concept to practise.'], 422);
}
if ($count < 1 || $count > 15) {
    Security::json(['error' => 'validation', 'message' => 'Number of questions must be between 1 and 15.'], 422);
}

// Adaptive difficulty selection (rule-based).
$analytics = new PerformanceAnalytics($repo);
$suggested = 'medium';
try {
    $concepts = $repo->listConceptPerformance($userId, $token);
    foreach ($concepts as $c) {
        if (mb_strtolower((string) ($c['concept'] ?? '')) === mb_strtolower($concept)) {
            $suggested = $analytics->suggestedDifficulty((float) ($c['mastery'] ?? 0));
            break;
        }
    }
} catch (SupabaseException $e) {
    $suggested = 'medium';
}

// Explicit override wins, but constrain to valid difficulties.
$override = strtolower(trim((string) ($input['difficulty'] ?? '')));
$difficulty = in_array($override, ['easy', 'medium', 'hard'], true) ? $override : $suggested;

if (!ai_configured()) {
    Security::json([
        'error'   => 'ai_not_configured',
        'message' => 'AI quiz generation is not configured yet. Please add your AI API keys in config/env.php.',
    ], 503);
}

$generator = new QuizGenerator();
try {
    $result = $generator->generateAndSave($userId, $concept, $difficulty, $count, $type, $token);
} catch (AiProviderException $e) {
    AppLogger::error('Practice generation failed for user ' . $userId, ['message' => $e->getMessage()], 'ai');
    Security::json([
        'error'   => 'ai_unavailable',
        'message' => $e->retryable
            ? 'The AI service is temporarily unavailable. Please try again in a moment.'
            : 'Unable to generate a practice quiz right now. Please try again later.',
    ], 502);
} catch (InvalidArgumentException $e) {
    Security::json(['error' => 'validation', 'message' => $e->getMessage()], 422);
} catch (Throwable $t) {
    AppLogger::error('Practice quiz persistence failed', ['message' => $t->getMessage()], 'quiz');
    Security::json([
        'error'   => 'save_failed',
        'message' => 'Your practice quiz was generated but could not be saved. Please try again.',
    ], 500);
}

$quiz = $result['quiz'];
Security::json([
    'error'       => null,
    'message'     => 'Practice quiz generated!',
    'quiz'        => [
        'id'          => $quiz['id'],
        'title'       => $result['title'],
        'topic'       => $concept,
        'difficulty'  => $difficulty,
        'question_count' => $quiz['question_count'],
        'provider'    => $result['provider'],
    ],
    'difficulty'  => $difficulty,
    'was_adaptive'=> ($override === '' || !in_array($override, ['easy', 'medium', 'hard'], true)),
], 200);
