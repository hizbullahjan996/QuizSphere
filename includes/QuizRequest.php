<?php
/**
 * QuizSphere - Quiz generation request (value object)
 * -------------------------------------------------------------
 * Immutable description of what the user wants generated. Encapsulates
 * validation of the user-supplied parameters (topic, difficulty, count,
 * question type).
 */

declare(strict_types=1);

class QuizRequest
{
    public const MIN_QUESTIONS = 1;
    public const MAX_QUESTIONS = 15;

    private string $topic;
    private string $difficulty;
    private int $numQuestions;
    private string $questionType;

    /** Optional source study material (e.g. extracted PDF text) to generate from. */
    private ?string $sourceText;

    public function __construct(
        string $topic,
        string $difficulty,
        int $numQuestions,
        string $questionType = 'multiple_choice',
        ?string $sourceText = null
    ) {
        $this->topic = trim($topic);
        $this->difficulty = strtolower(trim($difficulty));
        $this->numQuestions = $numQuestions;
        $this->questionType = strtolower(trim($questionType));
        $this->sourceText = ($sourceText !== null && trim($sourceText) !== '') ? trim($sourceText) : null;
    }

    /**
     * Validate the request. Returns array of human-friendly error messages
     * (empty when valid).
     */
    public function errors(): array
    {
        $errors = [];

        if ($this->hasSource()) {
            // For source-based generation the topic is a label only; enforce a
            // minimum amount of source material so the AI has something to work with.
            if (mb_strlen($this->sourceText) < 80) {
                $errors[] = 'The study material is too short to generate a quiz (need at least 80 characters).';
            } elseif (mb_strlen($this->sourceText) > 60000) {
                $errors[] = 'The study material is too large (max 60000 characters).';
            }
        } else {
            if ($this->topic === '') {
                $errors[] = 'Please enter a topic.';
            } elseif (mb_strlen($this->topic) > 200) {
                $errors[] = 'Topic is too long (max 200 characters).';
            }
        }

        if (!in_array($this->difficulty, ['easy', 'medium', 'hard'], true)) {
            $errors[] = 'Please choose a valid difficulty.';
        }

        if ($this->numQuestions < self::MIN_QUESTIONS || $this->numQuestions > self::MAX_QUESTIONS) {
            $errors[] = 'Number of questions must be between ' . self::MIN_QUESTIONS . ' and ' . self::MAX_QUESTIONS . '.';
        }

        if ($this->questionType !== 'multiple_choice') {
            $errors[] = 'Only multiple choice questions are supported right now.';
        }

        return $errors;
    }

    public function isValid(): bool
    {
        return $this->errors() === [];
    }

    public function topic(): string { return $this->topic; }
    public function difficulty(): string { return $this->difficulty; }
    public function numQuestions(): int { return $this->numQuestions; }
    public function questionType(): string { return $this->questionType; }
    public function hasSource(): bool { return $this->sourceText !== null; }
    public function sourceText(): ?string { return $this->sourceText; }
}
