<?php
/**
 * QuizSphere - Gamification status API
 * -------------------------------------------------------------
 * GET. Authenticated. Aggregates the user's XP / level progress, streak,
 * achievements (with unlock state), leaderboard position and recent activity
 * for the dashboard's gamification sections.
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
['client' => $client, 'auth' => $auth, 'repo' => $repo] = $services;

api_require_auth($auth);
$userId = $auth->id();
$token  = $auth->accessToken();
if (!$token) {
    Security::json(['error' => 'unauthenticated', 'message' => 'Your session expired.'], 401);
}

$g = new GamificationService($client, $repo);
$profile = $auth->profile() ?: [];

$xp = (int) ($profile['xp'] ?? 0);
$streak = (int) ($profile['streak'] ?? 0);
$levelInfo = $g->levelInfo($xp);

// Leaderboard rank (may be null if the function is missing).
$rank = null;
try {
    $me = $client->rpc('get_user_rank', ['p_uid' => $userId], $token);
    if (is_array($me) && isset($me[0]) && isset($me[0]['rank'])) {
        $rank = (int) $me[0]['rank'];
    }
} catch (SupabaseException $e) {
    $rank = null;
}

$achievements = $g->catalogueWithState($userId, $token);
$unlockedCount = count($achievements['unlocked']);

try {
    $attempts = $repo->listUserAttempts($userId, $token, 10);
} catch (SupabaseException $e) {
    $attempts = [];
}

Security::json([
    'error'      => null,
    'profile'    => [
        'name'         => (string) ($profile['full_name'] ?? ($auth->user()['full_name'] ?? 'Learner')),
        'xp'           => $xp,
        'level'        => (int) ($profile['level'] ?? $levelInfo['level']),
        'streak'       => $streak,
        'level_info'   => $levelInfo,
        'rank'         => $rank,
    ],
    'achievements' => [
        'all'      => $achievements['all'],
        'unlocked' => $achievements['unlocked'],
        'unlocked_count' => $unlockedCount,
        'total'    => count($achievements['all']),
    ],
    'recent_attempts' => array_map(static function (array $a): array {
        return [
            'percent' => (float) ($a['percent'] ?? 0),
            'score'   => (int) ($a['score'] ?? 0),
            'total'   => (int) ($a['total'] ?? 0),
            'date'    => (string) ($a['completed_at'] ?? ''),
        ];
    }, $attempts),
], 200);
