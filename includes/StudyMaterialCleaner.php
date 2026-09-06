<?php
/**
 * QuizSphere - Study material cleaner
 * -------------------------------------------------------------
 * Context-aware cleaning for extracted study material (PDF text or pasted
 * notes) BEFORE it is sent to an AI provider. Removes corrupted characters,
 * random symbol sequences, broken PDF artifacts and encoding garbage while
 * preserving meaningful words, sentences, formulas, code, SQL and technical
 * terminology.
 *
 * Also provides a readability gate: when the cleaned material does not
 * contain enough meaningful educational content, generation must be refused
 * instead of producing fake or pattern-matching questions.
 */

declare(strict_types=1);

class StudyMaterialCleaner
{
    /** Minimum meaningful words required before generation is allowed. */
    public const MIN_MEANINGFUL_WORDS = 40;

    /** Minimum ratio of word-like tokens to all tokens. */
    public const MIN_WORD_RATIO = 0.5;

    /**
     * Clean study material context-aware.
     */
    public function clean(string $text): string
    {
        // 1. Drop control characters, BOM and replacement characters, but keep
        //    newlines/tabs so line structure (when present) survives.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\x{FEFF}|\x{FFFD}/u', ' ', $text) ?? $text;

        // 2. Collapse runs of 3+ symbols (e.g. "[[[[", "****", "?!?!") — real
        //    prose, formulas and code never need them.
        $text = preg_replace('/[^\p{L}\p{N}\s]{3,}/u', ' ', $text) ?? $text;

        // 3. Drop unmapped-byte artifacts ("?????") left by lossy decoding.
        $text = preg_replace('/\?{2,}/', ' ', $text) ?? $text;

        // 4. Token-level filtering: keep words, numbers, formulas and code;
        //    drop garbage tokens like "!„BA", "?*CX", "#~DZ", "@$".
        $text = preg_replace_callback('/\S+/u', function (array $m): string {
            return $this->keepToken($m[0]) ? $m[0] : ' ';
        }, $text) ?? $text;

        // 5. Line-level filtering (only relevant when lines exist): drop lines
        //    that are almost pure symbols or have no real words (page numbers,
        //    headers/footers, leftover artifacts).
        if (str_contains($text, "\n")) {
            $lines = explode("\n", $text);
            $lines = array_map(fn (string $line): string => $this->keepLine($line) ? $line : '', $lines);
            $text = implode("\n", $lines);
        }

        // 6. Normalize whitespace.
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ ?\n ?/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $text = preg_replace('/ {2,}/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Whether the cleaned material contains enough meaningful educational
     * content to generate high-quality questions.
     */
    public function isMeaningful(string $cleaned): bool
    {
        $stats = $this->stats($cleaned);
        return $stats['words'] >= self::MIN_MEANINGFUL_WORDS
            && $stats['ratio'] >= self::MIN_WORD_RATIO;
    }

    /**
     * Quality check for a single piece of AI-generated text (a question or an
     * option). Used by the response validator to reject gibberish.
     */
    public static function isCleanText(string $s): bool
    {
        $s = trim($s);
        if ($s === '') {
            return false;
        }
        // Control / replacement characters or long symbol runs = corrupted.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\x{FFFD}/u', $s)) {
            return false;
        }
        if (preg_match('/[^\p{L}\p{N}\s]{3,}/u', $s)) {
            return false;
        }
        $len = mb_strlen($s);
        $alnum = preg_match_all('/[\p{L}\p{N}]/u', $s);
        if ($len === 0 || $alnum < 2) {
            return false;
        }
        // More than a third symbols = garbage.
        if (($len - $alnum) / $len > 0.35) {
            return false;
        }
        return true;
    }

    /**
     * Detect "document pattern-matching" questions that test formatting or
     * character sequences instead of knowledge (e.g. "Which letter sequence
     * appears in the text?").
     */
    public static function isPatternQuestion(string $question): bool
    {
        $q = $question;
        if (preg_match('/\b(letter|character|symbol|glyph|sequence|pattern|bracket|punctuation)\b.{0,60}\b(appears?|occurs?|found|repeats?|follows?|present|contains?)\b/iu', $q)) {
            return true;
        }
        if (preg_match('/\b(appears?|found|occurs?|repeats?)\b.{0,60}\b(throughout|in)\b.{0,40}\b(text|document|material|passage|page)\b/iu', $q)
            && preg_match('/\b(letter|character|symbol|sequence|pattern|bracket|word\s+pattern)\b/iu', $q)) {
            return true;
        }
        return false;
    }

    /* ------------------------------------------------------------------
       Internals
       ------------------------------------------------------------------ */

    /** @return array{words:int, ratio:float} */
    private function stats(string $text): array
    {
        $tokens = preg_split('/\s+/u', trim($text)) ?: [];
        $tokens = array_filter($tokens, fn ($t) => $t !== '');
        $total = count($tokens);
        if ($total === 0) {
            return ['words' => 0, 'ratio' => 0.0];
        }
        $words = 0;
        foreach ($tokens as $t) {
            if (preg_match('/\p{L}{2,}/u', $t)) {
                $words++;
            }
        }
        return ['words' => $words, 'ratio' => $words / $total];
    }

    /** Decide whether a whitespace-delimited token is meaningful. */
    private function keepToken(string $t): bool
    {
        // Plain words (with ordinary trailing/inner punctuation): keep.
        if (preg_match('/^[\p{L}\p{N}]+(?:[’\'`.\-–—:°±][\p{L}\p{N}]+)*[.,;:!?%°)\]\}"]{0,3}$/u', $t)) {
            return true;
        }
        // Single alphanumerics (code variables, list markers, "a", "I"): keep.
        if (preg_match('/^[\p{L}\p{N}]$/u', $t)) {
            return true;
        }
        // Common short punctuation used in prose/tables: keep.
        if (preg_match('/^[,.;:!?()\-–—%&+=*\/<>\"\'“”‘’]{1,2}$/u', $t)) {
            return true;
        }
        // Formulas / code / SQL-ish tokens: must start alnum-ish and must not
        // be symbol-dominated (keeps "x=y+1", "SELECT*", "O(n)", "H2O").
        if (preg_match('/^[\p{L}\p{N}_$#(][\p{L}\p{N}_$#=.+\-*\/^%<>!&|@(),\[\]{}°±×÷;:]*$/u', $t)) {
            $len = mb_strlen($t);
            $alnum = preg_match_all('/[\p{L}\p{N}]/u', $t);
            if ($alnum >= 2 && ($len - $alnum) / $len <= 0.5) {
                return true;
            }
        }
        return false;
    }

    /** Decide whether a line carries meaningful content. */
    private function keepLine(string $line): bool
    {
        $line = trim($line);
        if ($line === '') {
            return false;
        }
        $stats = $this->stats($line);
        if ($stats['words'] < 2) {
            return false; // page numbers, isolated markers
        }
        $len = mb_strlen($line);
        $alnum = preg_match_all('/[\p{L}\p{N}]/u', $line);
        if ($len > 0 && ($len - $alnum) / $len > 0.4) {
            return false; // symbol-dominated line
        }
        return true;
    }
}
