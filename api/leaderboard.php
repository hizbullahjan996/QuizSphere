<?php
/**
 * QuizSphere - Leaderboard API
 * -------------------------------------------------------------
 * GET. Authenticated. Returns the global top learners (rank, display name,
 * XP, level, quizzes completed) plus the caller's own rank even if it falls
 * outside the listed top set. Uses security-definer Postgres functions so the
 * view is computed server-side; only lightweight display fields are exposed.
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
['client' => $client, 'auth' => $auth] = $services;

api_require_auth($auth);
$userId = $auth->id();
$token  = $auth->accessToken();
if (!$token) {
    Security::json(['error' => 'unauthenticated', 'message' => 'Your session expired.'], 401);
}

try {
    $rows = $client->rpc('get_leaderboard', new stdClass(), $token);
    $me = $client->rpc('get_user_rank', ['p_uid' => $userId], $token);
} catch (SupabaseException $e) {
    AppLogger::warning('Leaderboard load failed', ['message' => $e->getMessage()], 'leaderboard');
    Security::json(['error' => 'load_failed', 'message' => 'Could not load the leaderboard right now.'], 500);
}

$list = is_array($rows) ? $rows : [];
$meRow = (is_array($me) && isset($me[0])) ? $me[0] : null;

Security::json([
    'error'     => null,
    'leaderboard' => array_map(static function (array $r): array {
        return [
            'user_id'          => (string) ($r['user_id'] ?? ''),
            'full_name'        => (string) ($r['full_name'] ?? 'Learner'),
            'xp'               => (int) ($r['xp'] ?? 0),
            'level'            => (int) ($r['level'] ?? 1),
            'quizzes_completed'=> (int) ($r['quizzes_completed'] ?? 0),
            'rank'             => isset($r['rank']) ? (int) $r['rank'] : null,
        ];
    }, $list),
    'me'        => $meRow ? [
        'user_id'          => (string) ($meRow['user_id'] ?? $userId),
        'full_name'        => (string) ($meRow['full_name'] ?? 'Learner'),
        'xp'               => (int) ($meRow['xp'] ?? 0),
        'level'            => (int) ($meRow['level'] ?? 1),
        'quizzes_completed'=> (int) ($meRow['quizzes_completed'] ?? 0),
        'rank'             => isset($meRow['rank']) ? (int) $meRow['rank'] : null,
    ] : null,
], 200);
