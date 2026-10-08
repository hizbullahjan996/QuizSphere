<?php
/**
 * QuizSphere - AI Service (orchestrator / facade)
 * -------------------------------------------------------------
 * High-level entry point for AI quiz generation. Responsibilities:
 *
 *   1. Try the primary provider (OpenAI by default).
 *   2. Gracefully fall back to Gemini, then Groq when preceding providers fail.
 *   3. Automatically skip providers with unconfigured API keys.
 *   4. Perform single-stage validation and sanitization.
 *   5. Log diagnostics and execution timings without leaking credentials.
 *   6. Provide user-friendly errors when all providers fail.
 *
 * The rest of the app talks ONLY to this service — it never touches a
 * specific provider directly.
 */

declare(strict_types=1);

class AiService
{
    private const MAX_PROVIDER_RETRIES = 1;

    /** @var AiProviderInterface[] */
    private array $providers = [];

    /** @var string[] provider names that were attempted (for diagnostics). */
    private array $attempted = [];

    /** @var array<string, int> execution duration in ms per provider. */
    private array $timings = [];

    public function __construct(?array $providerOrder = null)
    {
        $order = $providerOrder ?? [
            defined('AI_PRIMARY_PROVIDER') ? AI_PRIMARY_PROVIDER : 'openai',
            defined('AI_FALLBACK_PROVIDER') ? AI_FALLBACK_PROVIDER : 'gemini',
            defined('AI_SECOND_FALLBACK') ? AI_SECOND_FALLBACK : 'groq',
        ];

        $registry = [
            'openai' => fn () => (defined('OPENAI_API_KEY') && OPENAI_API_KEY !== '') ? new OpenAIProvider() : null,
            'gemini' => fn () => (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '') ? new GeminiProvider() : null,
            'groq'   => fn () => (defined('GROQ_API_KEY') && GROQ_API_KEY !== '') ? new GroqProvider() : null,
        ];

        $seen = [];
        foreach ($order as $providerName) {
            $name = strtolower(trim((string) $providerName));
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            if (isset($registry[$name])) {
                $instance = $registry[$name]();
                if ($instance !== null) {
                    $this->providers[] = $instance;
                }
            }
        }
    }

    public function hasAnyProvider(): bool
    {
        return count($this->providers) > 0;
    }

    /**
     * Names of providers attempted during the last generateQuiz run.
     *
     * @return string[]
     */
    public function attemptedProviders(): array
    {
        return $this->attempted;
    }

    /**
     * Execution timings (in milliseconds) per provider for diagnostics.
     *
     * @return array<string, int>
     */
    public function timings(): array
    {
        return $this->timings;
    }

    /**
     * Generate a quiz from the request.
     *
     * @return array{questions: array[], provider: string, duration_ms: int}
     * @throws AiProviderException when generation ultimately fails.
     */
    public function generateQuiz(QuizRequest $request): array
    {
        if (!$this->hasAnyProvider()) {
            throw new AiProviderException(
                'AI quiz generation is not configured yet. Please add your AI API keys in config/env.php.'
            );
        }

        $this->attempted = [];
        $this->timings = [];
        $lastError = null;
        $totalStart = microtime(true);

        foreach ($this->providers as $provider) {
            $providerName = $provider->name();
            $this->attempted[] = $providerName;
            $start = microtime(true);

            try {
                $questions = $this->generateFromProvider($provider, $request);
                $durationMs = (int) round((microtime(true) - $start) * 1000);
                $this->timings[$providerName] = $durationMs;

                AppLogger::info('AI quiz generated successfully', [
                    'provider'    => $providerName,
                    'duration_ms' => $durationMs,
                    'count'       => count($questions),
                ], 'ai');

                return [
                    'questions'   => $questions,
                    'provider'    => $providerName,
                    'duration_ms' => $durationMs,
                ];
            } catch (AiProviderException $e) {
                $durationMs = (int) round((microtime(true) - $start) * 1000);
                $this->timings[$providerName] = $durationMs;
                $lastError = $e;

                AppLogger::warning('Provider failed, attempting next fallback', [
                    'provider'    => $providerName,
                    'duration_ms' => $durationMs,
                    'error'       => $e->getMessage(),
                    'retryable'   => $e->retryable,
                ], 'ai');
            }
        }

        $totalDurationMs = (int) round((microtime(true) - $totalStart) * 1000);
        AppLogger::error('All AI providers exhausted', [
            'attempted'        => $this->attempted,
            'timings'          => $this->timings,
            'total_duration_ms'=> $totalDurationMs,
            'last_error'       => $lastError ? $lastError->getMessage() : 'None',
        ], 'ai');

        throw new AiProviderException(
            $lastError ? $lastError->getMessage() : 'Unable to generate a quiz right now. Please try again later.',
            false
        );
    }

    /**
     * Generate from a single provider with single-stage validation and sanitization.
     * Retries bounded number of times (at most once for transient retryable issues).
     */
    private function generateFromProvider(AiProviderInterface $provider, QuizRequest $request): array
    {
        $maxAttempts = self::MAX_PROVIDER_RETRIES;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $raw = $provider->generateQuiz($request);

                // Single-stage validation
                $problems = AiResponseValidator::validate($raw, $request->numQuestions());
                if ($problems !== []) {
                    AppLogger::warning('Provider response validation failed', [
                        'provider' => $provider->name(),
                        'attempt'  => $attempt,
                        'problems' => $problems,
                    ], 'ai');

                    if ($attempt < $maxAttempts) {
                        usleep(200000);
                        continue;
                    }
                    throw new AiProviderException('AI returned invalid question format.', true);
                }

                // Sanitize and return immediately (no duplicate validation)
                return AiResponseValidator::sanitize($raw);
            } catch (AiProviderException $e) {
                if ($e->retryable && $attempt < $maxAttempts) {
                    AppLogger::warning('Provider call transient error, retrying once', [
                        'provider' => $provider->name(),
                        'attempt'  => $attempt,
                        'error'    => $e->getMessage(),
                    ], 'ai');
                    usleep(300000);
                    continue;
                }
                throw $e;
            }
        }

        throw new AiProviderException('AI generation failed.', true);
    }
}
