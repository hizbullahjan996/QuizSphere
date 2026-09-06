<?php
/**
 * QuizSphere - Gemini AI provider
 * -------------------------------------------------------------
 * Primary AI provider. Calls the Google Gemini generateContent REST API.
 * All Gemini-specific code lives here (not scattered through the app).
 *
 * The API key is read server-side only and never exposed to the client.
 */

declare(strict_types=1);

class GeminiProvider implements AiProviderInterface
{
    public function name(): string
    {
        return 'gemini';
    }

    /**
     * Generate a validated quiz via Gemini.
     *
     * @return array[] Canonical questions (see AiProviderInterface).
     * @throws AiProviderException
     */
    public function generateQuiz(QuizRequest $request): array
    {
        if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') {
            throw new AiProviderException('Gemini is not configured.', false);
        }

        $model = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-3.6-flash';
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
             . rawurlencode($model)
             . ':generateContent?key=' . GEMINI_API_KEY;

        $prompt = (new AiPromptBuilder())->build($request);

        $payload = [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $prompt]],
                ],
            ],
            'generationConfig' => [
                'temperature'          => 0.7,
                'maxOutputTokens'      => 8192,
                'responseMimeType'     => 'application/json',
            ],
        ];

        try {
            $res = AiHttp::postJson($url, ['Content-Type: application/json'], $payload, 45.0);
        } catch (AiProviderException $e) {
            throw new AiProviderException('AI service is temporarily unavailable.', true, 0, $e);
        }

        $status = $res['status'];
        // 429 = rate limit, 5xx = temporary. Anything else: fail.
        if ($status < 200 || $status >= 300) {
            $retryable = in_array($status, [429, 500, 502, 503, 504], true);
            AppLogger::error('Gemini request failed', [
                'status'          => $status,
                'body'            => AppLogger::sanitize(json_encode($res['body'])),
            ], 'ai');
            throw new AiProviderException(
                'The AI quiz generator is busy right now. Please try again shortly.',
                $retryable
            );
        }

        $text = $this->extractText($res['body']);
        if ($text === null || $text === '') {
            AppLogger::error('Gemini returned no usable text', [
                'body' => AppLogger::sanitize(json_encode($res['body'])),
            ], 'ai');
            throw new AiProviderException('The AI returned an empty response. Please retry.', true);
        }

        return $this->parseQuestions($text, $request->numQuestions());
    }

    /** Extract the generated text from a Gemini generateContent response. */
    private function extractText(mixed $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }
        foreach ($body['candidates'] ?? [] as $candidate) {
            $parts = $candidate['content']['parts'] ?? [];
            foreach ($parts as $part) {
                if (isset($part['text']) && is_string($part['text']) && $part['text'] !== '') {
                    return $part['text'];
                }
            }
        }
        return null;
    }

    /** Parse and validate the returned JSON into canonical questions. */
    private function parseQuestions(string $text, int $expected): array
    {
        $payload = json_decode(trim($text), true);

        if (!is_array($payload)) {
            // Try to salvage a JSON array embedded in prose.
            if (preg_match('/\[.*\]/s', $text, $m)) {
                $payload = json_decode($m[0], true);
            }
        }

        if (!is_array($payload)) {
            AppLogger::error('Gemini returned unparseable JSON', [
                'preview' => AppLogger::sanitize(mb_substr($text, 0, 500)),
            ], 'ai');
            throw new AiProviderException('The AI returned an invalid response. Please try again.', true);
        }

        return $payload;
    }
}
