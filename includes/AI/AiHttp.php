<?php
/**
 * QuizSphere - Minimal HTTP transport for AI providers
 * -------------------------------------------------------------
 * Shared by GeminiProvider and GroqProvider. Encapsulates a POST request
 * with JSON body, timeout handling and safe parsing. Uses cURL when
 * available and falls back to stream contexts.
 */

declare(strict_types=1);

class AiHttp
{
    /**
     * Perform a JSON POST request.
     *
     * @return array{status:int, body:mixed}
     * @throws AiProviderException on network/timeout errors.
     */
    public static function postJson(string $url, array $headers, array $payload, float $timeout = 30.0): array
    {
        $json = json_encode($payload);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT_MS     => (int) round($timeout * 1000),
                CURLOPT_CONNECTTIMEOUT => 8,
                // No working IPv6 route on some local networks; v4 avoids hangs.
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_POSTFIELDS     => $json,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                throw new AiProviderException(
                    'Network error while contacting the AI service.',
                    true
                );
            }
            return ['status' => $status, 'body' => self::decode($body)];
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $json,
                'ignore_errors' => true,
                'timeout'       => $timeout,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new AiProviderException('Network error while contacting the AI service.', true);
        }
        $status = 200;
        $responseHeaders = http_get_last_response_headers();
        if (is_array($responseHeaders) && isset($responseHeaders[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $responseHeaders[0], $m)) {
            $status = (int) $m[1];
        }
        return ['status' => $status, 'body' => self::decode($body)];
    }

    /** Decode JSON body, tolerant to a leading code-fence when present. */
    private static function decode(string $body): mixed
    {
        $trimmed = trim($body);
        // Some models wrap output in ```json ... ```.
        if (preg_match('/```(?:json)?\s*(\{.*\}|\[.*\])\s*```/s', $trimmed, $m)) {
            $trimmed = trim($m[1]);
        }
        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return $body; // leave as raw string; validator/providers will handle it
    }
}
