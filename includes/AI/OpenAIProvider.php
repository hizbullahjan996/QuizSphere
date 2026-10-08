<?php
/**
 * QuizSphere - OpenAI AI provider (Primary)
 * -------------------------------------------------------------
 * Primary AI provider. Calls the OpenAI chat completions REST API.
 * All OpenAI-specific code lives here.
 *
 * The API key is read server-side only and never exposed to the client.
 */

declare(strict_types=1);

class OpenAIProvider implements AiProviderInterface
{
    public function name(): string
    {
        return 'openai';
    }

    /**
     * Generate a validated quiz via OpenAI.
     *
     * @return array[] Canonical questions (see AiProviderInterface).
     * @throws AiProviderException
     */
    public function generateQuiz(QuizRequest $request): array
    {
        if (!defined('OPENAI_API_KEY') || OPENAI_API_KEY === '') {
            throw new AiProviderException('OpenAI is not configured.', false);
        }

        $model = defined('OPENAI_MODEL') && OPENAI_MODEL !== '' ? OPENAI_MODEL : 'gpt-4o-mini';
        $timeout = defined('OPENAI_TIMEOUT') ? (float) OPENAI_TIMEOUT : 30.0;
        $url = 'https://api.openai.com/v1/chat/completions';

        $prompt = (new AiPromptBuilder())->build($request);

        $payload = [
            'model'       => $model,
            'messages'    => [
                [
                    'role'    => 'system',
                    'content' => 'You are an experienced assessment designer. You output ONLY valid JSON arrays of questions matching the requested schema. No conversational text, no markdown wrapping.',
                ],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.7,
            'max_tokens'  => 4096,
        ];

        try {
            $res = AiHttp::postJson($url, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . OPENAI_API_KEY,
            ], $payload, $timeout);
        } catch (AiProviderException $e) {
            throw new AiProviderException('AI service is temporarily unavailable.', true, 0, $e);
        }

        $status = $res['status'];
        if ($status < 200 || $status >= 300) {
            $retryable = in_array($status, [429, 500, 502, 503, 504], true);
            AppLogger::error('OpenAI request failed', [
                'status' => $status,
                'body'   => AppLogger::sanitize(is_string($res['body']) ? $res['body'] : json_encode($res['body'])),
            ], 'ai');
            throw new AiProviderException(
                'The OpenAI quiz generator is busy right now. Please try again shortly.',
                $retryable
            );
        }

        $content = $res['body']['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            AppLogger::error('OpenAI returned no content', [
                'body' => AppLogger::sanitize(json_encode($res['body'])),
            ], 'ai');
            throw new AiProviderException('The AI returned an empty response. Please retry.', true);
        }

        return $this->parseQuestions($content, $request->numQuestions());
    }

    /**
     * Parse the returned JSON into questions array.
     * Supports root JSON arrays or object envelopes like {"questions": [...]}.
     */
    private function parseQuestions(string $text, int $expected): array
    {
        $payload = json_decode(trim($text), true);

        if (!is_array($payload)) {
            // Attempt to salvage a JSON array or object embedded in markdown or prose
            if (preg_match('/\[\s*\{.*\}\s*\]/s', $text, $m)) {
                $payload = json_decode($m[0], true);
            } elseif (preg_match('/\{[\s\S]*\}/s', $text, $m)) {
                $payload = json_decode($m[0], true);
            }
        }

        if (is_array($payload)) {
            // Root is already a list of questions
            if (array_is_list($payload)) {
                return $payload;
            }
            // Check for standard wrapper keys
            if (isset($payload['questions']) && is_array($payload['questions'])) {
                return $payload['questions'];
            }
            if (isset($payload['quiz']) && is_array($payload['quiz'])) {
                return $payload['quiz'];
            }
            // Check if any property in the object contains a list of questions
            foreach ($payload as $val) {
                if (is_array($val) && array_is_list($val)) {
                    return $val;
                }
            }
        }

        AppLogger::error('OpenAI returned unparseable JSON', [
            'preview' => AppLogger::sanitize(mb_substr($text, 0, 500)),
        ], 'ai');
        throw new AiProviderException('The AI returned an invalid response. Please try again.', true);
    }
}
