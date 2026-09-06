<?php
/**
 * QuizSphere - AI provider exception
 * -------------------------------------------------------------
 * Thrown by AI providers / the AI service. Carries a safe, user-friendly
 * message plus (in the context log) technical details.
 */

declare(strict_types=1);

class AiProviderException extends RuntimeException
{
    /** True when this was a transient/retryable failure. */
    public bool $retryable;

    public function __construct(string $message, bool $retryable = false, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->retryable = $retryable;
    }
}
