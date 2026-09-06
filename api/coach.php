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

if (!ai_configured()) {
    Security::json([
        'error'   => 'ai_not_configured',
        'message' => 'The AI Learning Coach is not configured yet. Please add your AI API keys in config/env.php.',
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
if (isset($input['history']) && is_array($input['history'])) {
    foreach (array_slice($input['history'], -6) as $turn) {
        $role = ($turn['role'] ?? '') === 'coach' ? 'coach' : 'user';
        $content = trim((string) ($turn['content'] ?? ''));
        if ($content !== '') {
            $history[] = ['role' => $role, 'content' => mb_substr($content, 0, 1000)];
        }
    }
}

// Assemble the grounding context from real learner data.
try {
    $analytics = (new PerformanceAnalytics($repo))->build($userId, $token);
} catch (Throwable $e) {
    $analytics = []; // fall back to minimal context rather than failing
}
$profile = $auth->profile() ?: [];
$context = LearningCoach::buildContext($analytics, $profile);

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
