<?php
/**
 * QuizSphere - AI response validator
 * -------------------------------------------------------------
 * Never trust AI output blindly. This class validates the parsed quiz
 * response: JSON structure, question count, four options per question,
 * a valid correct answer index, and all required fields.
 *
 * It returns an array of human-friendly validation problems (empty = valid)
 * and a sanitizer that normalizes/normalises the raw payload into the
 * canonical shape the app expects.
 */

declare(strict_types=1);

class AiResponseValidator
{
    public const OPTION_COUNT = 4;

    /**
     * Validate a parsed question-array payload.
     *
     * @param array  $payload  Decoded JSON (expects a list of questions).
     * @param int    $expected Expected number of questions.
     * @return string[] List of validation problems (empty when valid).
     */
    public static function validate(array $payload, int $expected): array
    {
        $problems = [];

        if (!array_is_list($payload)) {
            return ['The AI response did not contain a list of questions.'];
        }

        if (count($payload) !== $expected) {
            $problems[] = 'Expected ' . $expected . ' questions but received ' . count($payload) . '.';
        }

        $seenQuestions = [];

        foreach ($payload as $i => $q) {
            $n = $i + 1;
            if (!is_array($q)) {
                $problems[] = "Question $n is not a valid object.";
                continue;
            }

            $qText = is_string($q['question'] ?? null) ? trim((string) $q['question']) : '';

            if ($qText === '') {
                $problems[] = "Question $n is missing its text.";
            } elseif (!StudyMaterialCleaner::isCleanText($qText)) {
                $problems[] = "Question $n contains corrupted or meaningless text.";
            } elseif (StudyMaterialCleaner::isPatternQuestion($qText)) {
                $problems[] = "Question $n asks about text patterns or formatting instead of knowledge.";
            }

            // Duplicate question detection (same wording, normalized).
            $norm = preg_replace('/[^a-z0-9]+/i', '', strtolower($qText));
            if ($norm !== '' && isset($seenQuestions[$norm])) {
                $problems[] = "Question $n duplicates an earlier question.";
            }
            $seenQuestions[$norm] = true;

            $options = $q['options'] ?? null;
            if (!is_array($options) || count($options) !== self::OPTION_COUNT) {
                $problems[] = "Question $n must have exactly " . self::OPTION_COUNT . ' options.';
            } else {
                $allText = true;
                $seenOpts = [];
                foreach ($options as $opt) {
                    if (!is_string($opt) || trim($opt) === '') {
                        $allText = false;
                        break;
                    }
                    if (!StudyMaterialCleaner::isCleanText($opt)) {
                        $allText = false;
                        $problems[] = "Question $n has a corrupted or meaningless option.";
                        break;
                    }
                    $optNorm = strtolower(trim($opt));
                    if (isset($seenOpts[$optNorm])) {
                        $problems[] = "Question $n has duplicate options.";
                        break;
                    }
                    $seenOpts[$optNorm] = true;
                }
                if (!$allText) {
                    $problems[] = "Question $n has empty or non-text options.";
                }
            }

            if (!isset($q['correct']) || !is_int($q['correct']) || $q['correct'] < 0 || $q['correct'] > self::OPTION_COUNT - 1) {
                $problems[] = "Question $n has an invalid correct answer.";
            }

            if (empty($q['explanation']) || !is_string($q['explanation'])) {
                $problems[] = "Question $n is missing an explanation.";
            }

            if (empty($q['concept']) || !is_string($q['concept'])) {
                $problems[] = "Question $n is missing a concept.";
            }
        }

        return $problems;
    }

    /**
     * Normalise a raw payload into the canonical question shape. Any invalid
     * questions are dropped; the result is re-indexed.
     *
     * @return array[] Normalised list of questions.
     */
    public static function sanitize(array $payload): array
    {
        $clean = [];
        $seenQuestions = [];
        foreach ($payload as $q) {
            if (!is_array($q)) {
                continue;
            }
            $qText = trim((string) ($q['question'] ?? ''));
            if ($qText === '' || !StudyMaterialCleaner::isCleanText($qText)
                || StudyMaterialCleaner::isPatternQuestion($qText)) {
                continue;
            }
            $norm = preg_replace('/[^a-z0-9]+/i', '', strtolower($qText));
            if (isset($seenQuestions[$norm])) {
                continue;
            }
            $seenQuestions[$norm] = true;

            $options = $q['options'] ?? [];
            $options = array_values(array_filter(
                $options,
                fn ($o) => is_string($o) && trim($o) !== '' && StudyMaterialCleaner::isCleanText($o)
            ));
            if (count(array_unique(array_map('strtolower', $options))) !== count($options)) {
                continue; // duplicate options
            }

            $clean[] = [
                'question'    => $qText,
                'options'     => $options,
                'correct'     => (int) ($q['correct'] ?? 0),
                'explanation' => (string) ($q['explanation'] ?? ''),
                'concept'     => (string) ($q['concept'] ?? ''),
            ];
        }
        return $clean;
    }
}
