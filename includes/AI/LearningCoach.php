<?php
/**
 * QuizSphere - AI Learning Coach
 * -------------------------------------------------------------
 * A supportive, data-grounded coaching mentor. Strictly powered by Google
 * Gemini. It receives the learner's concise performance profile and the
 * recent conversation history, producing actionable, personalized guidance.
 *
 * PERFORMANCE & OPTIMIZATIONS:
 * - Gemini ONLY (no multi-provider hopping or external AI dependencies).
 * - Fast Gemini Flash model (gemini-2.5-flash primary, gemini-flash-latest fallback).
 * - Compact generation target (~120-180 words, max 400 tokens) for sub-3s response.
 * - Native Gemini multi-turn payload with strict role validation.
 * - Lightweight grounding context (session-cached, no heavy 200-row table scans).
 * - Server-side bounded timeouts & minimal retries.
 * - API keys remain server-side only.
 */

declare(strict_types=1);

class LearningCoach
{
    private const SYSTEM = <<<SYS
You are the AI Learning Coach for QuizSphere, an adaptive learning platform.
You help students decide what to study, master difficult concepts, and improve weak areas.

GROUNDING & SAFETY RULES:
- Base every claim strictly on the learner profile provided below.
- NEVER state facts or assumptions about the learner that are not present in that profile.
- If data is minimal or absent, state so clearly and give practical, general study advice.
- Refer accurately to recorded mastery percentages when mentioning concepts.
- Keep advice practical, encouraging, and actionable. Use bullet points or short paragraphs.
- Be concise (roughly 100 to 180 words).
SYS;

    /**
     * Produce a personalized coaching response via Google Gemini.
     *
     * @param string    $contextText  Grounding performance profile.
     * @param string    $userMessage  The student's question.
     * @param array[]   $history      Optional prior turns [{role, content}].
     * @return array{reply: string, provider: string}
     * @throws AiProviderException when Gemini fails.
     */
    public function respond(string $contextText, string $userMessage, array $history = []): array
    {
        if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') {
            throw new AiProviderException(
                'The AI Learning Coach requires Gemini, which is not configured yet. Please configure GEMINI_API_KEY.',
                false
            );
        }

        $reply = $this->callGemini($contextText, $userMessage, $history);
        $reply = trim((string) $reply);
        if ($reply === '') {
            throw new AiProviderException('The coach returned an empty response. Please try again.', true);
        }

        return ['reply' => $reply, 'provider' => 'gemini'];
    }

    /**
     * Call Gemini generateContent API with fast model settings and native multi-turn turns.
     */
    private function callGemini(string $contextText, string $userMessage, array $history): string
    {
        $primaryModel = defined('AI_COACH_MODEL') && AI_COACH_MODEL !== ''
            ? AI_COACH_MODEL
            : (defined('GEMINI_MODEL') && GEMINI_MODEL !== '' ? GEMINI_MODEL : 'gemini-2.5-flash');

        $fallbackModel = defined('AI_COACH_FALLBACK_MODEL') && AI_COACH_FALLBACK_MODEL !== ''
            ? AI_COACH_FALLBACK_MODEL
            : 'gemini-flash-latest';

        $models = [$primaryModel];
        if ($fallbackModel !== $primaryModel && $fallbackModel !== '') {
            $models[] = $fallbackModel;
        }

        $maxTokens   = defined('AI_COACH_MAX_OUTPUT_TOKENS') ? (int) AI_COACH_MAX_OUTPUT_TOKENS : 600;
        $temperature = defined('AI_COACH_TEMPERATURE') ? (float) AI_COACH_TEMPERATURE : 0.5;
        $timeout     = defined('AI_COACH_TIMEOUT') ? (float) AI_COACH_TIMEOUT : 15.0;
        $maxHistory  = defined('AI_COACH_MAX_HISTORY') ? (int) AI_COACH_MAX_HISTORY : 6;

        $contents = self::formatGeminiContents($history, $userMessage, $maxHistory);

        $payload = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => self::SYSTEM . "\n\n[LEARNER PROFILE]\n" . $contextText . "\n[/LEARNER PROFILE]"],
                ],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature'     => $temperature,
                'maxOutputTokens' => $maxTokens,
                'thinkingConfig'  => [
                    'thinkingBudget' => 0,
                ],
            ],
        ];

        $lastException = null;

        foreach ($models as $model) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                 . rawurlencode($model)
                 . ':generateContent?key=' . GEMINI_API_KEY;

            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    $res = AiHttp::postJson($url, ['Content-Type: application/json'], $payload, $timeout);
                } catch (Throwable $e) {
                    $lastException = new AiProviderException('The coach is temporarily unavailable.', true);
                    break; // try next model on network exception
                }

                $status = (int) ($res['status'] ?? 0);

                if ($status >= 200 && $status < 300) {
                    $body = $res['body'] ?? [];
                    foreach ($body['candidates'] ?? [] as $candidate) {
                        foreach ($candidate['content']['parts'] ?? [] as $part) {
                            if (isset($part['text']) && is_string($part['text']) && $part['text'] !== '') {
                                return $part['text'];
                            }
                        }
                    }
                    $lastException = new AiProviderException('The coach returned an empty response.', true);
                    break;
                }

                // If transient 429 (rate limit) or 503 (busy), pause slightly and retry once
                if (in_array($status, [429, 503], true) && $attempt === 0) {
                    usleep(350000); // 350ms backoff
                    continue;
                }

                $retryable = in_array($status, [429, 500, 502, 503, 504], true);
                AppLogger::warning('Gemini coach request failed on model ' . $model, [
                    'status' => $status,
                    'model'  => $model,
                ], 'ai');

                $lastException = new AiProviderException(
                    'The AI coach is temporarily busy. Please try again shortly.',
                    $retryable
                );
                break; // proceed to fallback model
            }
        }

        throw $lastException ?? new AiProviderException('The coach is unavailable right now.', true);
    }

    /**
     * Format conversation history into valid alternating Gemini contents.
     * Rules: Roles must alternate (user/model), first role must be 'user', last role 'user'.
     *
     * @param array[] $history
     * @param string  $userMessage
     * @param int     $maxHistory
     * @return array[]
     */
    public static function formatGeminiContents(array $history, string $userMessage, int $maxHistory = 6): array
    {
        $turns = [];
        $sliced = array_slice($history, -$maxHistory);

        foreach ($sliced as $turn) {
            $role = ($turn['role'] ?? 'user') === 'coach' ? 'model' : 'user';
            $content = trim((string) ($turn['content'] ?? ''));
            if ($content !== '') {
                $turns[] = [
                    'role'  => $role,
                    'parts' => [['text' => mb_substr($content, 0, 800)]],
                ];
            }
        }

        // Append current prompt as the latest user message
        $turns[] = [
            'role'  => 'user',
            'parts' => [['text' => mb_substr($userMessage, 0, 1000)]],
        ];

        // Ensure alternating sequence and that first turn is 'user'
        $valid = [];
        foreach ($turns as $t) {
            if ($valid === [] && $t['role'] !== 'user') {
                continue; // Gemini contents cannot start with 'model'
            }
            if ($valid !== [] && end($valid)['role'] === $t['role']) {
                $lastIndex = count($valid) - 1;
                $valid[$lastIndex]['parts'][0]['text'] .= "\n\n" . $t['parts'][0]['text'];
            } else {
                $valid[] = $t;
            }
        }

        if ($valid === []) {
            $valid[] = [
                'role'  => 'user',
                'parts' => [['text' => mb_substr($userMessage, 0, 1000)]],
            ];
        }

        return $valid;
    }

    /**
     * Assemble a fast, compact grounding context from real learner performance.
     * Caches in session for rapid follow-up messages during a chat session.
     */
    public static function getGroundingContext(
        QuizRepository $repo,
        Auth $auth,
        string $userId,
        ?string $token
    ): string {
        $ttl = defined('AI_COACH_CONTEXT_TTL') ? (int) AI_COACH_CONTEXT_TTL : 180;

        // Session cache check
        if (isset($_SESSION['coach_context_cache']) && is_array($_SESSION['coach_context_cache'])) {
            $cache = $_SESSION['coach_context_cache'];
            if (($cache['user_id'] ?? '') === $userId
                && (time() - ($cache['time'] ?? 0)) < $ttl
                && !empty($cache['text'])
            ) {
                return (string) $cache['text'];
            }
        }

        // Lightweight data gathering: only recent 5 attempts and top 15 concepts
        try {
            $conceptRows = $repo->listConceptPerformance($userId, $token);
        } catch (Throwable $e) {
            $conceptRows = [];
        }

        try {
            $recentAttempts = $repo->listRecentAttemptScores($userId, $token, 5);
        } catch (Throwable $e) {
            $recentAttempts = [];
        }

        $recentMistakes = [];
        if (!empty($recentAttempts)) {
            $latest = $recentAttempts[0];
            if (isset($latest['id'], $latest['quiz_id'])) {
                try {
                    $detail = $repo->loadDetailForAttempt($userId, $latest['id'], $latest['quiz_id'], $token);
                    if ($detail) {
                        foreach ($detail as $q) {
                            if (isset($q['is_correct']) && !$q['is_correct']) {
                                $qText = $q['question'] ?? 'Unknown question';
                                $opts = $q['options'] ?? [];
                                $userAnsIdx = $q['selected'] ?? -1;
                                $corrAnsIdx = $q['correct'] ?? -1;
                                
                                $userAns = $userAnsIdx >= 0 && isset($opts[$userAnsIdx]) ? $opts[$userAnsIdx] : 'No answer/Unknown';
                                $corrAns = $corrAnsIdx >= 0 && isset($opts[$corrAnsIdx]) ? $opts[$corrAnsIdx] : 'Unknown';
                                
                                $recentMistakes[] = "Q: $qText (Answered: $userAns | Correct: $corrAns)";
                            }
                        }
                    }
                } catch (Throwable $e) {
                    // Ignore
                }
            }
        }

        $profile = $_SESSION['user_profile_cache'] ?? null;
        if (!is_array($profile) || ($profile['id'] ?? '') !== $userId) {
            $profile = $auth->profile() ?: [];
            if ($profile) {
                $_SESSION['user_profile_cache'] = $profile;
            }
        }

        $context = self::formatGroundingSummary($conceptRows, $recentAttempts, $profile, $recentMistakes);

        $_SESSION['coach_context_cache'] = [
            'user_id' => $userId,
            'time'    => time(),
            'text'    => $context,
        ];

        return $context;
    }

    /**
     * Format compact grounding profile text for prompt injection (~200 characters).
     */
    public static function formatGroundingSummary(
        array $conceptRows,
        array $recentAttempts,
        array $profile,
        array $recentMistakes = []
    ): string {
        $totalRecent = count($recentAttempts);
        $scores = array_map(static fn($a) => (float) ($a['percent'] ?? 0), $recentAttempts);
        $avgScore = $totalRecent > 0 ? round(array_sum($scores) / $totalRecent) : 0;
        $bestScore = $scores !== [] ? round(max($scores)) : 0;

        $weak   = [];
        $dev    = [];
        $strong = [];

        foreach ($conceptRows as $c) {
            $name = trim((string) ($c['concept'] ?? 'General'));
            if ($name === '') {
                continue;
            }
            $mastery = round((float) ($c['mastery'] ?? 0));
            if ($mastery < 60) {
                $weak[] = "$name ($mastery%)";
            } elseif ($mastery < 80) {
                $dev[] = "$name ($mastery%)";
            } else {
                $strong[] = "$name ($mastery%)";
            }
        }

        $lines = [
            'Recent attempts: ' . $totalRecent,
            'Average score: ' . $avgScore . '%',
            'Best score: ' . $bestScore . '%',
            'Level: ' . (int) ($profile['level'] ?? 1),
            'XP: ' . (int) ($profile['xp'] ?? 0),
            'Weak concepts: ' . ($weak ? implode(', ', array_slice($weak, 0, 5)) : 'none recorded'),
            'Developing concepts: ' . ($dev ? implode(', ', array_slice($dev, 0, 5)) : 'none recorded'),
            'Strong concepts: ' . ($strong ? implode(', ', array_slice($strong, 0, 5)) : 'none recorded'),
        ];

        if (!empty($recentMistakes)) {
            $lines[] = "Recent Quiz Mistakes:";
            foreach (array_slice($recentMistakes, 0, 5) as $m) { // limit to 5 to save tokens
                $lines[] = "- " . $m;
            }
        }

        $practice = !empty($weak) ? $weak[0] : (!empty($dev) ? $dev[0] : 'General');
        $lines[] = 'Recommended practice: ' . preg_replace('/\s*\(.*?\)/', '', $practice);

        return implode("\n", $lines);
    }

    /**
     * Backward-compatible context builder from full analytics payload.
     */
    public static function buildContext(array $analytics, array $profile): string
    {
        $lines = [];
        $lines[] = 'Total attempts: ' . (int) ($analytics['total_attempts'] ?? 0);
        $lines[] = 'Average score: ' . round((float) ($analytics['average_score'] ?? 0)) . '%';
        $lines[] = 'Best score: ' . round((float) ($analytics['best_score'] ?? 0)) . '%';
        $lines[] = 'Level: ' . (int) ($profile['level'] ?? 1);
        $lines[] = 'XP: ' . (int) ($profile['xp'] ?? 0);

        $weakNames = array_map(static fn ($c) => $c['concept'] . ' (' . round((float) $c['mastery']) . '%)', $analytics['weak'] ?? []);
        $devNames  = array_map(static fn ($c) => $c['concept'] . ' (' . round((float) $c['mastery']) . '%)', $analytics['developing'] ?? []);
        $strongNames = array_map(static fn ($c) => $c['concept'] . ' (' . round((float) $c['mastery']) . '%)', $analytics['strong'] ?? []);

        $lines[] = 'Weak concepts: ' . ($weakNames ? implode(', ', $weakNames) : 'none recorded');
        $lines[] = 'Developing concepts: ' . ($devNames ? implode(', ', $devNames) : 'none recorded');
        $lines[] = 'Strong concepts: ' . ($strongNames ? implode(', ', $strongNames) : 'none recorded');

        $rec = $analytics['recommendations'] ?? [];
        $lines[] = 'Recommended practice: ' . (($rec['practice_concept'] ?? '') !== '' ? $rec['practice_concept'] : 'not yet available');

        return implode("\n", $lines);
    }
}
