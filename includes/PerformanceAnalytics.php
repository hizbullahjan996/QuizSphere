<?php
/**
 * QuizSphere - Performance analytics & adaptive recommendation engine
 * -------------------------------------------------------------
 * Transparent, rule-based analysis of a learner's performance. No ML —
 * every recommendation is derived from explicit thresholds so the behaviour
 * is predictable and explainable.
 *
 * Mastery classification (per concept):
 *   accuracy < 60%                  -> weak
 *   accuracy 60% .. 79%             -> developing
 *   accuracy >= 80%                 -> strong
 *
 * Adaptive difficulty suggestion (based on recent mastery):
 *   mastery >= 85%  -> bump difficulty one step (maintains at max)
 *   mastery 60..84% -> keep difficulty
 *   mastery < 60%   -> ease difficulty one step (maintains at min)
 *
 * The service reads data via QuizRepository so it stays RLS-aware and is
 * deliberately free of any client/AI dependency.
 */

declare(strict_types=1);

class PerformanceAnalytics
{
    public const WEAK_MAX = 60;        // < 60% => weak
    public const STRONG_MIN = 80;      // >= 80% => strong
    public const BUMP_THRESHOLD = 85;  // >= 85% mastery => harder
    public const LEVEL_STEP = 60;      // < 60% mastery => easier

    private QuizRepository $repo;

    public function __construct(QuizRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Build the full analytics payload for the current user.
     *
     * @return array{
     *   total_attempts: int, average_score: float, best_score: float,
     *   trend: array[], concepts: array[], weak: array[], developing: array[],
     *   strong: array[], recommendations: array, insights: string[]
     * }
     */
    public function build(string $userId, ?string $token, int $weeks = 6): array
    {
        $attempts = [];
        $concepts = [];
        try {
            $attempts = $this->repo->listUserAttempts($userId, $token);
            $concepts = $this->repo->listConceptPerformance($userId, $token);
        } catch (SupabaseException $e) {
            // Treat as empty so the dashboard still renders with a friendly state.
        }

        $scores = array_map(
            static fn (array $a): float => (float) ($a['percent'] ?? 0),
            $attempts
        );

        $totalAttempts = count($attempts);
        $averageScore  = $totalAttempts > 0 ? round(array_sum($scores) / $totalAttempts, 2) : 0.0;
        $bestScore     = $scores !== [] ? round(max($scores), 2) : 0.0;

        $trend   = $this->weeklyTrend($attempts, $weeks);
        $concept = $this->buildConcepts($concepts);

        return [
            'total_attempts'   => $totalAttempts,
            'average_score'    => $averageScore,
            'best_score'       => $bestScore,
            'trend'            => $trend,
            'concepts'         => $concept['all'],
            'weak'             => $concept['weak'],
            'developing'       => $concept['developing'],
            'strong'          => $concept['strong'],
            'recommendations'  => $this->recommendations($concept, $averageScore, $totalAttempts),
            'insights'         => $this->insights($concept, $averageScore, $totalAttempts),
        ];
    }

    /**
     * Group completed attempts into a weekly line-chart series.
     *
     * @param array[] $attempts
     * @return array[] [{ label: string, score: float }]
     */
    private function weeklyTrend(array $attempts, int $weeks): array
    {
        // Bucket by day-of-week so the trend is dense even with few attempts.
        $grain = [];
        foreach ($attempts as $a) {
            $ts = strtotime((string) ($a['completed_at'] ?? 'now'));
            if ($ts === false) {
                $ts = time();
            }
            $day = date('Y-m-d', $ts);
            if (!isset($grain[$day])) {
                $grain[$day] = ['sum' => 0.0, 'n' => 0];
            }
            $grain[$day]['sum'] += (float) ($a['percent'] ?? 0);
            $grain[$day]['n']++;
        }

        $series = [];
        $ordered = array_keys($grain);
        sort($ordered);
        // Take up to the last `$weeks * 7` days worth of buckets, oldest first.
        $bucketKeys = array_slice($ordered, -($weeks * 7));
        foreach ($bucketKeys as $day) {
            $series[] = [
                'label' => date('M j', strtotime($day)),
                'score' => round($grain[$day]['sum'] / $grain[$day]['n'], 2),
            ];
        }
        return $series;
    }

    /**
     * Classify every concept and split into weak / developing / strong lists.
     *
     * @param array[] $concepts Rows from concept_performance.
     * @return array{all: array[], weak: array[], developing: array[], strong: array[]}
     */
    private function buildConcepts(array $concepts): array
    {
        $all = [];
        $weak = $developing = $strong = [];

        foreach ($concepts as $c) {
            $name    = (string) ($c['concept'] ?? 'General');
            $attempts = (int) ($c['attempts'] ?? 0);
            $correct  = (int) ($c['correct'] ?? 0);
            $mastery  = (float) ($c['mastery'] ?? 0);

            $status = $this->classify($mastery);
            $row = [
                'concept'       => $name,
                'attempts'      => $attempts,
                'correct'       => $correct,
                'mastery'       => $mastery,
                'status'        => $status,
                'suggested_difficulty' => $this->suggestedDifficulty($mastery),
            ];

            $all[] = $row;
            if ($status === 'weak') {
                $weak[] = $row;
            } elseif ($status === 'developing') {
                $developing[] = $row;
            } else {
                $strong[] = $row;
            }
        }

        return [
            'all'        => $all,
            'weak'       => $weak,
            'developing' => $developing,
            'strong'     => $strong,
        ];
    }

    /** Rule-based mastery level classification. */
    public function classify(float $mastery): string
    {
        if ($mastery < self::WEAK_MAX) {
            return 'weak';
        }
        if ($mastery < self::STRONG_MIN) {
            return 'developing';
        }
        return 'strong';
    }

    /**
     * Adaptive difficulty suggestion for a concept based on its mastery.
     * Returns one of easy|medium|hard.
     */
    public function suggestedDifficulty(float $mastery): string
    {
        if ($mastery >= self::BUMP_THRESHOLD) {
            return 'hard';
        }
        if ($mastery < self::LEVEL_STEP) {
            return 'easy';
        }
        return 'medium';
    }

    /**
     * Build recommendations: which concept to practice and at what difficulty.
     *
     * @param array{weak: array[], developing: array[], strong: array[]} $concept
     * @return array{suggestion: string|null, practice_concept: string|null,
     *               suggested_difficulty: string|null, reason: string}
     */
    private function recommendations(array $concept, float $averageScore, int $totalAttempts): array
    {
        // Highest-priority practice target: weakest concept with attempts.
        $target = null;
        foreach ($concept['weak'] as $c) {
            $target = $c;
            break;
        }
        if ($target === null && $concept['developing'] !== []) {
            $target = $concept['developing'][0];
        }

        if ($target === null) {
            return [
                'suggestion'          => $totalAttempts === 0 ? 'start' : 'maintain',
                'practice_concept'    => null,
                'suggested_difficulty'=> 'medium',
                'reason'              => $totalAttempts === 0
                    ? 'Take your first quiz to unlock personalized practice recommendations.'
                    : 'Great progress! Keep practising to keep every concept sharp.',
            ];
        }

        return [
            'suggestion'           => 'practice',
            'practice_concept'     => $target['concept'],
            'suggested_difficulty' => $target['suggested_difficulty'],
            'reason'               => $target['status'] === 'weak'
                ? 'Focus on this weak area to build a solid foundation.'
                : 'This concept is developing — practice to push it into a strength.',
        ];
    }

    /**
     * Short, human-readable learning insights.
     *
     * @param array{weak: array[], developing: array[], strong: array[]} $concept
     * @return string[]
     */
    private function insights(array $concept, float $averageScore, int $totalAttempts): array
    {
        $out = [];

        if ($totalAttempts === 0) {
            $out[] = 'Complete your first quiz to start tracking your progress.';
            return $out;
        }

        if ($averageScore >= self::STRONG_MIN) {
            $out[] = 'Your average score (' . round($averageScore) . '%) is strong — try harder material to keep growing.';
        } elseif ($averageScore >= self::WEAK_MAX) {
            $out[] = 'Your average score (' . round($averageScore) . '%) is solid — a little targeted practice will unlock your strongest results.';
        } else {
            $out[] = 'Your average score (' . round($averageScore) . '%) suggests focusing on foundational concepts first.';
        }

        if (count($concept['strong']) > count($concept['weak']) && $concept['strong'] !== []) {
            $out[] = 'You have ' . count($concept['strong']) . ' strong area(s) — you can safely level up difficulty there.';
        }
        if ($concept['weak'] !== []) {
            $out[] = 'You have ' . count($concept['weak']) . ' weak area(s) — start with "'
                . $concept['weak'][0]['concept'] . '" to improve fastest.';
        }

        return $out;
    }
}
