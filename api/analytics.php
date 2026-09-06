<?php
/**
 * QuizSphere - Performance analytics API
 * -------------------------------------------------------------
 * GET. Authenticated. Returns the learner's aggregate statistics, score
 * trend, per-concept mastery (weak/developing/strong) and rule-based
 * adaptive recommendations for the dashboard.
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

$analytics = new PerformanceAnalytics($repo);
$payload   = $analytics->build($userId, $token);

Security::json([
    'error' => null,
    'stats' => [
        'total_attempts' => (int) $payload['total_attempts'],
        'average_score'  => (float) $payload['average_score'],
        'best_score'     => (float) $payload['best_score'],
    ],
    'trend' => $payload['trend'],
    'concepts' => $payload['concepts'],
    'weak'  => $payload['weak'],
    'developing' => $payload['developing'],
    'strong' => $payload['strong'],
    'recommendations' => $payload['recommendations'],
    'insights' => $payload['insights'],
], 200);
