<?php
/**
 * QuizSphere - AI Learning Coach
 * -------------------------------------------------------------
 * A supportive, data-grounded coaching agent. It receives the learner's
 * aggregated performance context (recent results, weak/strong concepts,
 * level, recommendations) along with the student's message, and produces a
 * personalized, plain-language response using the existing AI service layer
 * (Gemini primary -> Groq fallback).
 *
 * SAFETY: The model is instructed to base every claim strictly on the
 * provided context and to admit when there is not enough data. This keeps
 * the coach from inventing facts (e.g. "you are weak in SQL joins") that are
 * not present in the learner's actual records.
 *
 * AI keys remain server-side only.
 */

declare(strict_types=1);

class LearningCoach
{
    private const SYSTEM = <<<SYS
You are the AI Learning Coach for an adaptive quiz platform called QuizSphere.
You help students figure out what to study, which concepts need improvement,
how to plan their practice, and you can explain difficult concepts.

IMPORTANT GROUNDING RULES:
- Base every claim ONLY on the learner profile provided below.
- NEVER state facts about the learner that are not present in that profile.
- If the profile has little or no performance data, say so clearly and give a
  general, helpful recommendation instead of inventing specifics.
- Do not claim the student is weak or strong in any concept unless the data
  explicitly shows it (use the mastery percentages provided).
- Keep advice practical, encouraging and actionable. Use short paragraphs or
  bullet lists. Be concise (roughly 120-200 words).
SYS;

    /**
     * Produce a coaching response.
     *
     * @param string    $contextText  Grounding performance profile.
     * @param string    $userMessage  The student's question.
     * @param array[]   $history      Optional prior turns [{role, content}].
     * @return array{reply: string, provider: string}
     * @throws AiProviderException when all providers fail.
     */
    public function respond(string $contextText, string $userMessage, array $history = []): array
    {
        $providers = [];
        if (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '') {
            $providers[] = 'gemini';
        }
        if (defined('GROQ_API_KEY') && GROQ_API_KEY !== '') {
            $providers[] = 'groq';
        }
        if ($providers === []) {
            throw new AiProviderException('AI is not configured. Cannot start the coach.');
        }

        $lastError = null;
        foreach ($providers as $provider) {
            try {
                $reply = $this->call($provider, $contextText, $userMessage, $history);
                $reply = trim((string) $reply);
                if ($reply === '') {
                    throw new AiProviderException('The coach returned an empty response.', true);
                }
                return ['reply' => $reply, 'provider' => $provider];
            } catch (AiProviderException $e) {
                $lastError = $e;
            }
        }

        throw $lastError ?? new AiProviderException('The coach is unavailable right now.', false);
    }

    /** Route a single request to a specific provider. */
    private function call(string $provider, string $contextText, string $userMessage, array $history): string
    {
        return $provider === 'gemini'
            ? $this->callGemini($contextText, $userMessage, $history)
            : $this->callGroq($contextText, $userMessage, $history);
    }

    /** Wrap conversation + context into one prompt string. */
    private function buildPrompt(string $contextText, string $userMessage, array $history): string
    {
        $dialogue = '';
        foreach ($history as $turn) {
            $role = ($turn['role'] ?? 'user') === 'coach' ? 'Coach' : 'Student';
            $dialogue .= $role . ": " . (string) ($turn['content'] ?? '') . "\n";
        }

        return "[LEARNER PROFILE]\n" . $contextText . "\n[/LEARNER PROFILE]\n\n"
             . "You are the QuizSphere AI Learning Coach. Use ONLY the learner profile above.\n"
             . ($dialogue !== '' ? "Prior conversation:\n" . $dialogue . "\n" : '')
             . "Student: " . $userMessage . "\n"
             . "Coach:";
    }

    private function callGemini(string $contextText, string $userMessage, array $history): string
    {
        $model = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-3.6-flash';
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
             . rawurlencode($model)
             . ':generateContent?key=' . GEMINI_API_KEY;

        $prompt = $this->buildPrompt($contextText, $userMessage, $history);

        $payload = [
            'systemInstruction' => ['parts' => [['text' => self::SYSTEM]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature'     => 0.6,
                'maxOutputTokens' => 1024,
            ],
        ];

        $res = AiHttp::postJson($url, ['Content-Type: application/json'], $payload, 45.0);
        $status = (int) $res['status'];
        if ($status < 200 || $status >= 300) {
            throw new AiProviderException('The coach is busy right now. Please try again shortly.', in_array($status, [429, 500, 502, 503, 504], true));
        }

        $body = $res['body'];
        foreach ($body['candidates'] ?? [] as $candidate) {
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (isset($part['text']) && is_string($part['text']) && $part['text'] !== '') {
                    return $part['text'];
                }
            }
        }
        throw new AiProviderException('The coach returned no response.', true);
    }

    private function callGroq(string $contextText, string $userMessage, array $history): string
    {
        $model = defined('GROQ_MODEL') ? GROQ_MODEL : 'openai/gpt-oss-20b';
        $url = 'https://api.groq.com/openai/v1/chat/completions';

        $messages = [['role' => 'system', 'content' => self::SYSTEM . "\n\n" . "[LEARNER PROFILE]\n" . $contextText . "\n[/LEARNER PROFILE]"]];
        foreach ($history as $turn) {
            $role = ($turn['role'] ?? 'user') === 'coach' ? 'assistant' : 'user';
            $messages[] = ['role' => $role, 'content' => (string) ($turn['content'] ?? '')];
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => 0.6,
            'max_tokens'  => 1024,
        ];

        $res = AiHttp::postJson($url, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROQ_API_KEY,
        ], $payload, 45.0);

        $status = (int) $res['status'];
        if ($status < 200 || $status >= 300) {
            throw new AiProviderException('The coach is busy right now. Please try again shortly.', in_array($status, [429, 500, 502, 503, 504], true));
        }

        $content = $res['body']['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new AiProviderException('The coach returned no response.', true);
        }
        return $content;
    }

    /**
     * Build a compact grounding context from the performance analytics payload
     * and the learner's profile. This is what the coach is allowed to rely on.
     *
     * @param array $analytics PerformanceAnalytics::build() result.
     * @param array $profile  Profiles row (or empty array).
     * @return string
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
