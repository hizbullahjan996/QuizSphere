<?php
/**
 * QuizSphere - AI Learning Coach API
 * -------------------------------------------------------------
 * POST. Authenticated, CSRF-protected, rate-limited. Accepts the student's
 * message (plus optional short conversation history), assembles their real
 * performance context, and returns a personalized, data-grounded coaching
 * reply via the existing AI service layer. Keys stay server-side.
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

$input = Security::readJsonInput();
api_require_csrf($input);

if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') {
    Security::json([
        'error'   => 'ai_not_configured',
        'message' => 'The AI Learning Coach requires Gemini. Please add your GEMINI_API_KEY in config/env.php.',
    ], 503);
}

if (!RateLimiter::hit(RateLimiter::actor('coach-' . $userId, RateLimiter::clientIp()))) {
    Security::json([
        'error'   => 'rate_limited',
        'message' => 'You are sending messages too quickly. Please wait a moment and try again.',
    ], 429);
}

$message = trim((string) ($input['message'] ?? ''));
if ($message === '' || mb_strlen($message) > 1000) {
    Security::json(['error' => 'validation', 'message' => 'Please enter a question (up to 1000 characters).'], 422);
}

// Normalise optional conversation history (only recent, safe turns).
$history = [];
$maxHistory = defined('AI_COACH_MAX_HISTORY') ? (int) AI_COACH_MAX_HISTORY : 6;
if (isset($input['history']) && is_array($input['history'])) {
    foreach (array_slice($input['history'], -$maxHistory) as $turn) {
        $role = ($turn['role'] ?? '') === 'coach' ? 'coach' : 'user';
        $content = trim((string) ($turn['content'] ?? ''));
        if ($content !== '') {
            $history[] = ['role' => $role, 'content' => mb_substr($content, 0, 800)];
        }
    }
}

// Assemble the grounding context via fast lightweight queries and session caching.
try {
    $context = LearningCoach::getGroundingContext($repo, $auth, $userId, $token);
} catch (Throwable $e) {
    $context = "Recent attempts: 0\nAverage score: 0%\nLevel: 1\nXP: 0\nWeak concepts: none recorded\nDeveloping concepts: none recorded\nStrong concepts: none recorded\nRecommended practice: General";
}

$coach = new LearningCoach();
try {
    $result = $coach->respond($context, $message, $history);
} catch (AiProviderException $e) {
    AppLogger::error('Coach request failed', ['user_id' => $userId, 'message' => $e->getMessage()], 'ai');
    Security::json([
        'error'   => 'ai_unavailable',
        'message' => $e->retryable
            ? 'The AI coach is temporarily unavailable. Please try again in a moment.'
            : 'The AI coach is unavailable right now. Please try again later.',
    ], 502);
} catch (Throwable $t) {
    AppLogger::error('Unexpected coach failure', ['message' => $t->getMessage()], 'ai');
    Security::json(['error' => 'ai_unavailable', 'message' => 'Something went wrong. Please try again.'], 500);
}

Security::json([
    'error'    => null,
    'message'  => $result['reply'],
    'provider' => $result['provider'],
], 200);
