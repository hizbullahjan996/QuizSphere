<?php
/**
 * QuizSphere - AI Service (orchestrator / facade)
 * -------------------------------------------------------------
 * High-level entry point for AI quiz generation. Responsibilities:
 *
 *   1. Try the primary provider (Gemini).
 *   2. Safely retry a provider a limited number of times to ride out
 *      transient parse/validation issues.
 *   3. Fall back to the secondary provider (Groq) when the primary fails.
 *   4. Validate every response; never trust AI output blindly.
 *   5. Log technical failures (sanitized) while surfacing friendly errors.
 *
 * The rest of the app talks ONLY to this service — it never touches a
 * specific provider directly.
 */

declare(strict_types=1);

class AiService
{
    private const MAX_PROVIDER_RETRIES = 2;

    /** @var AiProviderInterface[] */
    private array $providers;

    /** @var string[] provider names that were attempted (for diagnostics). */
    private array $attempted = [];

    public function __construct()
    {
        $this->providers = [];
        if (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '') {
            $this->providers[] = new GeminiProvider();
        }
        if (defined('GROQ_API_KEY') && GROQ_API_KEY !== '') {
            $this->providers[] = new GroqProvider();
        }
    }

    public function hasAnyProvider(): bool
    {
        return count($this->providers) > 0;
    }

    public function attemptedProviders(): array
    {
        return $this->attempted;
    }

    /**
     * Generate a quiz from the request.
     *
     * @return array{questions: array[], provider: string}
     * @throws AiProviderException when generation ultimately fails.
     */
    public function generateQuiz(QuizRequest $request): array
    {
        if (!$this->hasAnyProvider()) {
            throw new AiProviderException(
                'AI quiz generation is not configured yet. Please add AI keys in config/env.php.'
            );
        }

        $lastError = null;
        foreach ($this->providers as $provider) {
            $this->attempted[] = $provider->name();
            try {
                $questions = $this->generateFromProvider($provider, $request);
                // Double-check validation before trusting the provider.
                $problems = AiResponseValidator::validate($questions, $request->numQuestions());
                if ($problems !== []) {
                    AppLogger::warning('Provider returned invalid questions', [
                        'provider' => $provider->name(),
                        'problems' => $problems,
                    ], 'ai');
                    throw new AiProviderException('AI returned invalid questions.', true);
                }
                return [
                    'questions' => $questions,
                    'provider'  => $provider->name(),
                ];
            } catch (AiProviderException $e) {
                $lastError = $e;
                // Continue to the next provider (fallback) or fail if none remain.
            }
        }

        throw new AiProviderException(
            $lastError ? $lastError->getMessage() : 'Unable to generate a quiz right now. Please try again later.',
            false
        );
    }

    /**
     * Attempt to get a valid, well-formed response from a single provider,
     * retrying a bounded number of times for transient failures.
     */
    private function generateFromProvider(AiProviderInterface $provider, QuizRequest $request): array
    {
        for ($attempt = 1; $attempt <= self::MAX_PROVIDER_RETRIES; $attempt++) {
            try {
                $raw = $provider->generateQuiz($request);

                $problems = AiResponseValidator::validate($raw, $request->numQuestions());
                if ($problems !== []) {
                    AppLogger::warning('Provider validation failed (attempt ' . $attempt . ')', [
                        'provider' => $provider->name(),
                        'problems' => $problems,
                    ], 'ai');
                    if ($attempt < self::MAX_PROVIDER_RETRIES) {
                        usleep(300000); // small backoff before retrying
                        continue;
                    }
                    // Out of retries — surface as retryable so the orchestrator
                    // can try the fallback provider.
                    throw new AiProviderException('AI returned invalid questions.', true);
                }

                return AiResponseValidator::sanitize($raw);
            } catch (AiProviderException $e) {
                AppLogger::warning('Provider call failed (attempt ' . $attempt . ')', [
                    'provider'  => $provider->name(),
                    'retryable' => $e->retryable,
                ], 'ai');
                if ($e->retryable && $attempt < self::MAX_PROVIDER_RETRIES) {
                    usleep(400000);
                    continue;
                }
                throw $e; // let orchestrator decide (fallback / fail)
            }
        }

        throw new AiProviderException('AI generation failed.', true);
    }
}
