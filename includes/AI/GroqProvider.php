<?php
/**
 * QuizSphere - Groq AI provider (fallback)
 * -------------------------------------------------------------
 * Fallback provider used when the primary (Gemini) fails. Calls the
 * Groq OpenAI-compatible chat completions endpoint.
 *
 * The API key is read server-side only and never exposed to the client.
 */

declare(strict_types=1);

class GroqProvider implements AiProviderInterface
{
    public function name(): string
    {
        return 'groq';
    }

    /**
     * Generate a validated quiz via Groq.
     *
     * @return array[] Canonical questions (see AiProviderInterface).
     * @throws AiProviderException
     */
    public function generateQuiz(QuizRequest $request): array
    {
        if (!defined('GROQ_API_KEY') || GROQ_API_KEY === '') {
            throw new AiProviderException('Groq fallback is not configured.', false);
        }

        $model = defined('GROQ_MODEL') ? GROQ_MODEL : 'openai/gpt-oss-20b';
        $url = 'https://api.groq.com/openai/v1/chat/completions';

        $prompt = (new AiPromptBuilder())->build($request);

        $payload = [
            'model'       => $model,
            'messages'    => [
                [
                    'role'    => 'system',
                    'content' => 'You output ONLY valid JSON arrays and nothing else.',
                ],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.7,
            'max_tokens'  => 3072,
        ];

        try {
            $res = AiHttp::postJson($url, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . GROQ_API_KEY,
            ], $payload, 45.0);
        } catch (AiProviderException $e) {
            throw new AiProviderException('AI service is temporarily unavailable.', true, 0, $e);
        }

        $status = $res['status'];
        if ($status < 200 || $status >= 300) {
            $retryable = in_array($status, [429, 500, 502, 503, 504], true);
            AppLogger::error('Groq request failed', [
                'status' => $status,
                'body'   => AppLogger::sanitize(json_encode($res['body'])),
            ], 'ai');
            throw new AiProviderException(
                'The AI quiz generator is busy right now. Please try again shortly.',
                $retryable
            );
        }

        $content = $res['body']['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            AppLogger::error('Groq returned no content', [
                'body' => AppLogger::sanitize(json_encode($res['body'])),
            ], 'ai');
            throw new AiProviderException('The AI returned an empty response. Please retry.', true);
        }

        return $this->parseQuestions($content, $request->numQuestions());
    }

    /** Parse and validate the returned JSON into canonical questions. */
    private function parseQuestions(string $text, int $expected): array
    {
        $payload = json_decode(trim($text), true);
        if (!is_array($payload) && preg_match('/\[.*\]/s', $text, $m)) {
            $payload = json_decode($m[0], true);
        }
        if (!is_array($payload)) {
            AppLogger::error('Groq returned unparseable JSON', [
                'preview' => AppLogger::sanitize(mb_substr($text, 0, 500)),
            ], 'ai');
            throw new AiProviderException('The AI returned an invalid response. Please try again.', true);
        }
        return $payload;
    }
}
