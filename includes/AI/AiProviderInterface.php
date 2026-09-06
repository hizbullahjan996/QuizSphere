<?php
/**
 * QuizSphere - AI Provider interface
 * -------------------------------------------------------------
 * Every AI provider the app integrates with implements this contract so
 * the rest of the application never depends on a specific vendor's API.
 *
 * Implementations: GeminiProvider, GroqProvider (see AI/).
 */

declare(strict_types=1);

interface AiProviderInterface
{
    /** Human-readable provider name, e.g. 'gemini' or 'groq'. */
    public function name(): string;

    /**
     * Generate a structured quiz for the given request.
     *
     * Must return a validated array of questions, each:
     *   [
     *     'question'  => string,
     *     'options'   => string[4],
     *     'correct'   => int  (0-3 index into options),
     *     'explanation'=> string,
     *     'concept'   => string,
     *   ]
     *
     * @throws AiProviderException if the provider cannot fulfil the request.
     */
    public function generateQuiz(QuizRequest $request): array;
}
