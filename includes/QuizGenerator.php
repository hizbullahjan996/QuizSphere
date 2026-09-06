<?php
/**
 * QuizSphere - Quiz generation service (AI + persist)
 * -------------------------------------------------------------
 * Encapsulates the "generate a quiz with AI, then save it" flow so both the
 * direct generation endpoint and the adaptive practice flow share one code
 * path. AI keys remain server-side only.
 */

declare(strict_types=1);

class QuizGenerator
{
    /**
     * Generate and persist a quiz.
     *
     * @return array{quiz: array, title: string, provider: string}
     * @throws AiProviderException when AI generation fails.
     * @throws SupabaseException   when persistence fails.
     */
    public function generateAndSave(
        string $userId,
        string $topic,
        string $difficulty,
        int $count,
        string $questionType,
        string $token,
        ?string $sourceText = null
    ): array {
        // Clean uploaded/pasted study material and refuse to generate from
        // corrupted or meaningless content instead of producing fake questions.
        if ($sourceText !== null && trim($sourceText) !== '') {
            $cleaner = new StudyMaterialCleaner();
            $sourceText = $cleaner->clean($sourceText);
            if (!$cleaner->isMeaningful($sourceText)) {
                throw new InvalidArgumentException(
                    'Not enough readable study material to generate high-quality questions. Please upload a clearer, text-based document.'
                );
            }
        }

        $request = new QuizRequest($topic, $difficulty, $count, $questionType, $sourceText);
        $errors  = $request->errors();
        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        if (!ai_configured()) {
            throw new AiProviderException(
                'AI quiz generation is not configured yet. Please add your AI API keys in config/env.php.'
            );
        }

        $ai = new AiService();
        $result = $ai->generateQuiz($request);

        $title = ucfirst(substr(trim($topic), 0, 40)) . ' Quiz';

        $client = SupabaseClient::fromConfig();
        $repo = new QuizRepository($client);
        $saved = $repo->saveGeneratedQuiz(
            $userId,
            $title,
            $request->topic(),
            $request->difficulty(),
            $request->questionType(),
            $result['provider'],
            $result['questions'],
            $token
        );

        return [
            'quiz'     => isset($saved['quiz']['id']) ? $saved['quiz'] : $saved,
            'title'    => $title,
            'provider' => $result['provider'],
        ];
    }
}
