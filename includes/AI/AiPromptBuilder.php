<?php
/**
 * QuizSphere - AI prompt builder
 * -------------------------------------------------------------
 * Builds the strict-JSON prompt shared by all AI providers so the exact
 * output contract stays consistent regardless of the provider used.
 *
 * The prompts cast the model as an experienced university teacher and
 * professional assessment designer: questions must test understanding of the
 * material's concepts — never its formatting, structure or corrupted text.
 */

declare(strict_types=1);

class AiPromptBuilder
{
    public function build(QuizRequest $request): string
    {
        // When the request is based on uploaded study material (e.g. a PDF),
        // generate strictly from that material instead of a free-form topic.
        if ($request->hasSource()) {
            return $this->buildFromSource($request);
        }

        $difficultyLabel = ucfirst($request->difficulty());

        return <<<PROMPT
You are an experienced university teacher and professional assessment designer.

Topic: {$request->topic()}
Difficulty: {$difficultyLabel}
Number of multiple-choice questions: {$request->numQuestions()}

FIRST, mentally outline the main concepts, facts, definitions, relationships,
processes and examples a good course on this topic would cover. THEN write
{$request->numQuestions()} multiple-choice questions that test those concepts.

{$this->difficultyRules($request->difficulty())}

{$this->qualityRules()}

Each question must be a JSON object with EXACTLY these keys:
- "question": the question text (string)
- "options": an array of EXACTLY 4 strings (the answer choices)
- "correct": the 0-based index (0 to 3) of the correct option
- "explanation": a short, clear explanation of the correct answer (string)
- "concept": a short concept/topic label this question tests (string)

Return ONLY a single JSON array of question objects. No markdown, no extra
text, no trailing commas. Ensure exactly {$request->numQuestions()} objects.
Correct answers must index into the options array correctly.
PROMPT;
    }

    /**
     * Build a prompt that creates questions based ONLY on provided study
     * material. The AI must treat the material as a knowledge source and test
     * understanding — never pattern-match its text or formatting.
     */
    public function buildFromSource(QuizRequest $request): string
    {
        $difficultyLabel = ucfirst($request->difficulty());

        return <<<PROMPT
You are an experienced university teacher and professional assessment designer.

A student uploaded the study material below. Read it for UNDERSTANDING:
identify its main concepts, definitions, important facts, relationships,
processes, examples and applications. Then write multiple-choice questions
that test a student's understanding of that knowledge.

The material is a KNOWLEDGE SOURCE, not text to pattern-match. Every question
and every answer must be grounded in what the material TEACHES.

Study material ("{$request->topic()}"):
----------------------------------------
{$request->sourceText()}
----------------------------------------

STRICT RULES ABOUT THE SOURCE:
- NEVER ask about the document's formatting, layout, structure, page numbers,
  headers/footers or metadata.
- NEVER ask which letters, characters, symbols, brackets or sequences "appear",
  "occur", "repeat" or are "found" in the text.
- IGNORE any corrupted fragments, random symbols, gibberish strings or
  encoding artifacts in the material; they are extraction noise, not content.
- Do NOT introduce facts, terms or ideas that are not present in the material.
- If the material does not contain enough meaningful educational content to
  write real knowledge questions, return an empty JSON array: []

Difficulty: {$difficultyLabel}
Number of multiple-choice questions: {$request->numQuestions()}

{$this->difficultyRules($request->difficulty())}

{$this->qualityRules()}

Each question must be a JSON object with EXACTLY these keys:
- "question": the question text (string)
- "options": an array of EXACTLY 4 strings (the answer choices)
- "correct": the 0-based index (0 to 3) of the correct option
- "explanation": a short, clear explanation of the correct answer (string)
- "concept": a short concept/topic label this question tests (string)

Return ONLY a single JSON array of question objects. No markdown, no extra
text, no trailing commas. Ensure exactly {$request->numQuestions()} objects.
Correct answers must index into the options array correctly.
PROMPT;
    }

    /** Difficulty definitions shared by both prompts. */
    private function difficultyRules(string $difficulty): string
    {
        switch ($difficulty) {
            case 'easy':
                return 'DIFFICULTY (Easy): test basic concepts, definitions, terminology and fundamental understanding. Use clear, direct wording.';
            case 'hard':
                return 'DIFFICULTY (Hard): test analysis, reasoning, multi-step problems, edge cases and deeper understanding. Challenge the student through substance — never through confusing or trick wording.';
            default:
                return 'DIFFICULTY (Medium): test application, comparisons, relationships, examples and short scenarios.';
        }
    }

    /** Question/distractor quality rules shared by both prompts. */
    private function qualityRules(): string
    {
        return <<<'RULES'
QUESTION QUALITY (apply to EVERY question before outputting it):
- Exactly one option is correct; the other three are plausible distractors.
- Distractors must be related to the topic, similar in style and length to the
  correct answer, and clearly wrong only to someone who lacks understanding.
- Never use random letters, symbols, gibberish or obviously silly options.
- Wording must be clear and free of corrupted text, stray symbols, broken
  brackets and excessive punctuation.
- No two questions may test the same fact; no duplicate answer options.
- Questions must be educational and answerable from the knowledge at hand.
If any question fails these checks after your self-review, rewrite it.
RULES;
    }
}
