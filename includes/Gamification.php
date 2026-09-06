<?php
/**
 * QuizSphere - Gamification service
 * -------------------------------------------------------------
 * Transparent XP, level, streak and achievement rules for the platform.
 *
 * All rules are deliberately simple and data-driven so they are easy to
 * reason about, test and modify. AI is never involved here.
 *
 * LEVELS
 *   XP thresholds are defined in LEVEL_THRESHOLDS. The user's level is the
 *   highest index whose threshold is met (first entry is Level 1 at 0 XP).
 *   When XP passes the final threshold, the last gap is reused to keep
 *   levelling smooth at high values.
 *
 * XP PER COMPLETED QUIZ (each completed attempt, awarded once)
 *   + 10          base completion
 *   + 1 per %     accuracy (rounded), 0..100
 *   + 15          perfect score bonus (100%)
 *   + 5           first attempt of the day (streak day)
 *   + 15 per      concept improved from weak (<60%) to >=60% this attempt
 *
 * STREAK
 *   Consecutive days with at least one completed attempt. Repeats on the
 *   same day do not reset the streak. Inactivity resets it to 1.
 *
 * ACHIEVEMENTS
 *   Granted only when the actual criteria are met (see the catalogue seeds in
 *   database/migrations/phase6.sql). Newly-unlocked badges are returned so
 *   the UI can celebrate them.
 */

declare(strict_types=1);

class GamificationService
{
    /** XP needed to reach each level (index 0 => Level 1 at 0 XP). */
    public const LEVEL_THRESHOLDS = [0, 500, 1000, 1500, 2500, 4000, 6000, 8000, 10000, 12500, 15000];

    // XP rewards (kept together so the rules are obvious).
    public const XP_BASE          = 10;
    public const XP_PER_PERCENT   = 1;
    public const XP_PERFECT_BONUS = 15;
    public const XP_DAILY_BONUS   = 5;
    public const XP_IMPROVE_BONUS = 15;

    public const MASTERY_IMPROVED = 60; // >=60% = no longer weak

    private SupabaseClient $db;
    private QuizRepository $repo;

    public function __construct(SupabaseClient $db, QuizRepository $repo)
    {
        $this->db = $db;
        $this->repo = $repo;
    }

    /**
     * Calculate the level for a given XP total.
     *
     * @return array{level:int, xp_into:int, xp_for_level:int, xp_next:int, progress:float}
     *               xp_into = XP earned within the current level,
     *               xp_for_level = total XP required to reach current level,
     *               xp_next = remaining XP to the next level,
     *               progress = 0..1 within the current level.
     */
    public function levelInfo(int $xp): array
    {
        $t = self::LEVEL_THRESHOLDS;
        $level = 1;
        for ($i = 0; $i < count($t) - 1; $i++) {
            if ($xp >= $t[$i + 1]) {
                $level = $i + 2;
            } else {
                break;
            }
        }

        $lastIdx = count($t) - 1;
        $gap = 0;
        if ($level > $lastIdx + 1) {
            // Beyond the defined table: reuse the final gap.
            $gap = $t[$lastIdx] - $t[$lastIdx - 1];
            $floor = $t[$lastIdx] + (($level - ($lastIdx + 1)) * $gap);
        } else {
            $floor = $t[$level - 1];
            $next = $t[$level] ?? ($t[$lastIdx] + ($t[$lastIdx] - $t[$lastIdx - 1]));
            $gap = max(1, $next - $floor);
        }

        $xpInto = max(0, $xp - $floor);
        $xpNeeded = max(1, $gap);

        return [
            'level'        => $level,
            'xp_into'      => $xpInto,
            'xp_for_level' => $floor,
            'xp_next'      => max(0, $xpNeeded - $xpInto),
            'progress'     => $xpNeeded > 0 ? min(1.0, $xpInto / $xpNeeded) : 1.0,
        ];
    }

    /**
     * Transparent XP calculation for a completed attempt.
     *
     * @return array{total:int, breakdown:array<string,int>}
     */
    public function computeAttemptXp(int $score, int $total, int $numImproved, int $newStreak, bool $streakIncreased): array
    {
        $percent = $total > 0 ? round(($score / $total) * 100) : 0;
        $breakdown = [];
        $breakdown['base'] = self::XP_BASE;
        $breakdown['accuracy'] = (int) $percent;
        if ($total > 0 && $percent >= 100) {
            $breakdown['perfect'] = self::XP_PERFECT_BONUS;
        }
        if ($streakIncreased) {
            $breakdown['daily'] = self::XP_DAILY_BONUS;
        }
        if ($numImproved > 0) {
            $breakdown['improvement'] = $numImproved * self::XP_IMPROVE_BONUS;
        }

        return ['total' => (int) array_sum($breakdown), 'breakdown' => $breakdown];
    }

    /**
     * Compute the next streak given the profile's last_active date.
     *
     * @param string|null $lastActive Y-m-d or null.
     * @return array{streak:int, increased:bool}
     */
    public function nextStreak(?string $lastActive): array
    {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        if ($lastActive === $today) {
            return ['streak' => 0, 'increased' => false]; // streak stays as-is (same day)
        }
        if ($lastActive === $yesterday) {
            return ['streak' => 1, 'increased' => true];
        }
        // New streak (first attempt or after a gap).
        return ['streak' => 1, 'increased' => true];
    }

    /**
     * Main reward flow for a completed, persisted attempt.
     *
     * @param array $attempt      {score:int, total:int, percent:float}
     * @param array $profile      Current profiles row (xp, level, streak, last_active).
     * @param int   $attemptCount Total completed attempts, this one included.
     * @param array $conceptBefore Concept performance rows BEFORE this attempt
     *                            (from listConceptPerformance) for improvement detection.
     * @return array{xp_earned:int, xp_breakdown:array, level:int, leveled_up:bool,
     *               level_info:array, streak:int, streak_increased:bool,
     *               new_achievements:array}
     */
    public function awardAttempt(string $userId, string $token, array $attempt, array $profile, int $attemptCount, array $conceptBefore): array
    {
        $currentXp = (int) ($profile['xp'] ?? 0);
        $currentLevel = (int) ($profile['level'] ?? 1);
        $currentStreak = (int) ($profile['streak'] ?? 0);
        $score = (int) ($attempt['score'] ?? 0);
        $total = (int) ($attempt['total'] ?? 0);
        $percent = (float) ($attempt['percent'] ?? 0);

        // 1. Streak.
        $streakInfo = $this->nextStreak(isset($profile['last_active']) ? (string) $profile['last_active'] : null);
        // Same-day repeat: keep the current streak (no reset, no bonus).
        $newStreak = $streakInfo['increased'] ? $currentStreak + 1 : $currentStreak;

        // 2. Weak-concept improvement this attempt.
        $numImproved = $this->countImprovedConcepts($userId, $token, $conceptBefore);

        // 3. XP.
        $xp = $this->computeAttemptXp($score, $total, $numImproved, $newStreak, $streakInfo['increased']);
        $newXp = $currentXp + $xp['total'];
        $levelInfo = $this->levelInfo($newXp);
        $leveledUp = $levelInfo['level'] > $currentLevel;

        // 4. Persist profile updates (RLS lets the user update their own row).
        try {
            $this->db->update('profiles', [
                'xp'          => $newXp,
                'level'       => $levelInfo['level'],
                'streak'      => $newStreak,
                'last_active' => date('Y-m-d'),
            ], ['id' => "eq.$userId"], $token);
        } catch (SupabaseException $e) {
            AppLogger::warning('Could not persist gamification update', ['user_id' => $userId, 'message' => $e->getMessage()], 'gamification');
        }

        // 5. Achievements.
        $state = [
            'attempt_count' => $attemptCount,
            'this_percent'  => $percent,
            'score'         => $score,
            'total'         => $total,
            'streak'        => $newStreak,
            'num_improved'  => $numImproved,
        ];
        $newAchievements = $this->grantEligible($userId, $token, $state);

        return [
            'xp_earned'        => $xp['total'],
            'xp_breakdown'     => $xp['breakdown'],
            'level'            => $levelInfo['level'],
            'leveled_up'       => $leveledUp,
            'level_info'       => $levelInfo,
            'streak'           => $newStreak,
            'streak_increased' => $streakInfo['increased'],
            'num_improved'     => $numImproved,
            'new_achievements' => $newAchievements,
        ];
    }

    /**
     * Count how many previously-weak concepts the user improved in this
     * attempt by comparing cumulative mastery before vs after.
     */
    private function countImprovedConcepts(string $userId, string $token, array $conceptBefore): int
    {
        try {
            $after = $this->repo->listConceptPerformance($userId, $token);
        } catch (SupabaseException $e) {
            return 0;
        }

        if ($conceptBefore === []) {
            return 0;
        }

        $beforeByConcept = [];
        foreach ($conceptBefore as $c) {
            $name = (string) ($c['concept'] ?? 'General');
            $beforeByConcept[$name] = [
                'attempts' => (int) ($c['attempts'] ?? 0),
                'mastery'  => (float) ($c['mastery'] ?? 0),
            ];
        }

        $improved = 0;
        foreach ($after as $c) {
            $name = (string) ($c['concept'] ?? 'General');
            $prev = $beforeByConcept[$name] ?? null;
            if ($prev === null) {
                continue; // brand-new concept, not an "improvement"
            }
            $wasWeak = $prev['mastery'] < self::MASTERY_IMPROVED;
            $nowStrong = (float) ($c['mastery'] ?? 0) >= self::MASTERY_IMPROVED;
            $touched = (int) ($c['attempts'] ?? 0) > $prev['attempts'];
            if ($wasWeak && $nowStrong && $touched) {
                $improved++;
            }
        }
        return $improved;
    }

    /**
     * Evaluate and grant every achievement whose criteria are now met.
     *
     * @return array[] Newly granted achievements [{code,name,icon,description}]
     */
    public function grantEligible(string $userId, string $token, array $state): array
    {
        $catalogue = [];
        try {
            $catalogue = $this->db->select('achievements', ['columns' => 'id, code, name, icon, description'], $token);
        } catch (SupabaseException $e) {
            return [];
        }

        // Map achievement id -> code from the catalogue.
        $idToCode = [];
        foreach ($catalogue as $a) {
            $idToCode[(string) ($a['id'] ?? '')] = (string) ($a['code'] ?? '');
        }

        // Already-owned codes (own rows via RLS).
        $owned = [];
        try {
            $granted = $this->db->select('user_achievements', [
                'columns' => 'achievement_id',
                'filter'  => ['user_id' => "eq.$userId"],
            ], $token);
            foreach ($granted as $g) {
                $code = $idToCode[(string) ($g['achievement_id'] ?? '')] ?? null;
                if (is_string($code) && $code !== '') {
                    $owned[$code] = true;
                }
            }
        } catch (SupabaseException $e) {
            // proceed empty
        }

        $newOnes = [];
        foreach ($catalogue as $a) {
            $code = (string) ($a['code'] ?? '');
            if ($code === '' || isset($owned[$code])) {
                continue;
            }
            if (!$this->isMet($code, $state)) {
                continue;
            }
            // Grant.
            try {
                $this->db->insert('user_achievements', [
                    'user_id'        => $userId,
                    'achievement_id' => $a['id'],
                ], $token);
            } catch (SupabaseException $e) {
                continue; // already granted concurrently; skip
            }
            $newOnes[] = [
                'code'        => $code,
                'name'        => (string) ($a['name'] ?? $code),
                'icon'        => (string) ($a['icon'] ?? 'bi-trophy'),
                'description' => (string) ($a['description'] ?? ''),
            ];
        }
        return $newOnes;
    }

    /**
     * Grant a single achievement by code (used for non-attempt events such as
     * earning a certificate). Returns the achievement row or null.
     */
    public function grantByCode(string $userId, string $token, string $code): ?array
    {
        $catalogue = [];
        try {
            $catalogue = $this->db->select('achievements', ['columns' => 'id, code, name, icon, description'], $token);
        } catch (SupabaseException $e) {
            return null;
        }
        foreach ($catalogue as $a) {
            if ((string) ($a['code'] ?? '') !== $code) {
                continue;
            }
            try {
                $this->db->insert('user_achievements', [
                    'user_id'        => $userId,
                    'achievement_id' => $a['id'],
                ], $token);
            } catch (SupabaseException $e) {
                return null; // already owned
            }
            return [
                'code'        => $code,
                'name'        => (string) ($a['name'] ?? $code),
                'icon'        => (string) ($a['icon'] ?? 'bi-trophy'),
                'description' => (string) ($a['description'] ?? ''),
            ];
        }
        return null;
    }

    /**
     * Return all achievements with the user's unlock state, for the UI.
     *
     * @return array{all: array[], unlocked: array[]}
     */
    public function catalogueWithState(string $userId, string $token): array
    {
        $all = [];
        try {
            $all = $this->db->select('achievements', ['columns' => 'id, code, name, icon, description', 'order' => 'created_at.asc'], $token);
        } catch (SupabaseException $e) {
            return ['all' => [], 'unlocked' => []];
        }

        $idToCode = [];
        foreach ($all as $a) {
            $idToCode[(string) ($a['id'] ?? '')] = (string) ($a['code'] ?? '');
        }

        $owned = [];
        try {
            $granted = $this->db->select('user_achievements', [
                'columns' => 'achievement_id',
                'filter'  => ['user_id' => "eq.$userId"],
            ], $token);
            foreach ($granted as $g) {
                $code = $idToCode[(string) ($g['achievement_id'] ?? '')] ?? null;
                if (is_string($code) && $code !== '') {
                    $owned[$code] = true;
                }
            }
        } catch (SupabaseException $e) {
            // ignore
        }

        $unlocked = [];
        foreach ($all as $a) {
            $a['unlocked'] = isset($owned[(string) ($a['code'] ?? '')]);
            if ($a['unlocked']) {
                $unlocked[] = $a;
            }
        }
        return ['all' => $all, 'unlocked' => $unlocked];
    }

    /** Evaluate a single achievement code against the current state. */
    private function isMet(string $code, array $state): bool
    {
        switch ($code) {
            case 'first_quiz':
                return (int) ($state['attempt_count'] ?? 0) >= 1;
            case 'quiz_master':
                return (int) ($state['attempt_count'] ?? 0) >= 10;
            case 'percent_90':
                return (float) ($state['this_percent'] ?? 0) >= 90;
            case 'perfect_score':
                return (int) ($state['score'] ?? 0) > 0 && (float) ($state['this_percent'] ?? 0) >= 100;
            case 'streak_7':
                return (int) ($state['streak'] ?? 0) >= 7;
            case 'improvement_champion':
                return (int) ($state['num_improved'] ?? 0) >= 1;
            default:
                return false;
        }
    }
}
