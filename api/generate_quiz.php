<?php
/**
 * QuizSphere - Generate quiz (AI) API
 * -------------------------------------------------------------
 * POST only. Authenticated. Rate-limited. Orchestrates:
 *   validate input -> rate limit -> AI service (Gemini + Groq fallback)
 *   -> validate response -> save to Supabase -> return quiz for the UI.
 *
 * All AI keys remain server-side; clients never touch provider APIs.
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

// Rate limiting: per user + IP.
if (!RateLimiter::hit(RateLimiter::actor($userId, RateLimiter::clientIp()))) {
    Security::json([
        'error'   => 'rate_limited',
        'message' => 'You have reached the limit for generating quizzes right now. Please try again later.',
    ], 429);
}

$input   = Security::readJsonInput();
$topic   = trim((string) ($input['topic'] ?? ''));
$diffRaw = (string) ($input['difficulty'] ?? 'medium');
$count   = (int) ($input['question_count'] ?? 5);
$type    = (string) ($input['question_type'] ?? 'multiple_choice');

api_require_csrf($input);

$request = new QuizRequest($topic, $diffRaw, $count, $type);
$errors  = $request->errors();
if ($errors) {
    Security::json(['error' => 'validation', 'message' => implode(' ', $errors)], 422);
}

if (!ai_configured()) {
    Security::json([
        'error'   => 'ai_not_configured',
        'message' => 'AI quiz generation is not configured yet. Please add your AI API keys in config/env.php.',
    ], 503);
}

$ai = new AiService();

try {
    $result = $ai->generateQuiz($request);
} catch (AiProviderException $e) {
    AppLogger::error('AI generation failed for user ' . $userId, [
        'providers' => $ai->attemptedProviders(),
        'reason'    => $e->getMessage(),
    ], 'ai');
    Security::json([
        'error'   => 'ai_unavailable',
        'message' => $e->retryable
            ? 'The AI service is temporarily unavailable. Please try again in a moment.'
            : 'Unable to generate a quiz right now. Please try again later.',
    ], 502);
} catch (Throwable $t) {
    AppLogger::error('Unexpected error during AI generation', ['exception' => $t->getMessage()], 'ai');
    Security::json([
        'error'   => 'ai_unavailable',
        'message' => 'Something went wrong while generating your quiz. Please try again.',
    ], 500);
}

$questions = $result['questions'];
$provider  = $result['provider'];

// Title: derive a short readable title from the topic + difficulty.
$title = ucfirst(substr($topic, 0, 40)) . ' Quiz';

try {
    $saved = $repo->saveGeneratedQuiz(
        $userId,
        $title,
        $topic,
        $request->difficulty(),
        $request->questionType(),
        $provider,
        $questions,
        $token
    );
} catch (SupabaseException $e) {
    AppLogger::error('Failed to save generated quiz', [
        'user_id'  => $userId,
        'provider' => $provider,
        'message'  => $e->getMessage(),
    ], 'quiz');
    Security::json([
        'error'   => 'save_failed',
        'message' => 'Your quiz was generated but could not be saved. Please try again.',
    ], 500);
}

$quizId = $saved['quiz']['id'] ?? null;
if (!$quizId) {
    Security::json(['error' => 'save_failed', 'message' => 'Could not save your quiz.'], 500);
}

Security::json([
    'error'   => null,
    'message' => 'Quiz generated successfully!',
    'quiz'    => [
        'id'          => $quizId,
        'title'       => $title,
        'topic'       => $topic,
        'difficulty'  => $request->difficulty(),
        'question_count' => count($saved['questions']),
        'provider'    => $provider,
    ],
], 200);
