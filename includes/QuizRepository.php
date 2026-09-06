<?php
/**
 * QuizSphere - Quiz persistence repository
 * -------------------------------------------------------------
 * All quiz / question / attempt / answer data access against Supabase
 * (PostgREST) runs through this class. The caller passes an authenticated
 * access token so Row Level Security applies to the user's own rows.
 */

declare(strict_types=1);

class QuizRepository
{
    private SupabaseClient $db;

    public function __construct(SupabaseClient $db)
    {
        $this->db = $db;
    }

    /**
     * Persist a generated quiz and its questions.
     *
     * @param string  $userId       Authenticated user id.
     * @param string  $title        Quiz title.
     * @param string  $topic        Topic string.
     * @param string  $difficulty   easy|medium|hard
     * @param string  $questionType question type key.
     * @param string  $aiProvider   'gemini'|'groq'
     * @param array[] $questions    Canonical questions (see AiProviderInterface).
     * @param string  $token        User access token (RLS aware).
     * @return array{quiz: array, questions: array[]}
     */
    public function saveGeneratedQuiz(
        string $userId,
        string $title,
        string $topic,
        string $difficulty,
        string $questionType,
        string $aiProvider,
        array $questions,
        ?string $token = null
    ): array {
        $quiz = $this->db->insert('quizzes', [
            'user_id'        => $userId,
            'title'          => $title,
            'topic'          => $topic,
            'difficulty'     => $difficulty,
            'question_count' => count($questions),
            'question_type'  => $questionType,
            'ai_provider'    => $aiProvider,
            'is_public'      => false,
        ], $token);

        $quizId = $quiz[0]['id'] ?? ($quiz['id'] ?? null);
        if (!$quizId) {
            throw new SupabaseException('Could not save the quiz. Please try again.', 500);
        }

        $savedQuestions = [];
        foreach ($questions as $i => $q) {
            $row = $this->db->insert('questions', [
                'quiz_id'       => $quizId,
                'position'      => $i,
                'body'          => $q['question'],
                'options'       => $q['options'],
                'correct_index' => $q['correct'],
                'concept'       => $q['concept'] ?? 'General',
                'explanation'   => $q['explanation'] ?? '',
            ], $token);

            $qid = $row[0]['id'] ?? ($row['id'] ?? null);
            $savedQuestions[] = [
                'id'          => $qid,
                'position'    => $i,
                'body'        => $q['question'],
                'options'     => $q['options'],
                'concept'     => $q['concept'] ?? 'General',
                'explanation' => $q['explanation'] ?? '',
            ];
        }

        return [
            'quiz'      => $quiz[0] ?? $quiz,
            'questions' => $savedQuestions,
        ];
    }

    /**
     * Load a quiz that belongs to the given user.
     *
     * @return array{quiz: array, questions: array[]}|null
     */
    public function findQuizForUser(string $userId, string $quizId, ?string $token = null): ?array
    {
        $quizzes = $this->db->select('quizzes', [
            'columns' => '*',
            'filter'  => ['id' => "eq.$quizId", 'user_id' => "eq.$userId"],
            'limit'   => 1,
        ], $token);

        if (!$quizzes) {
            return null;
        }
        $quiz = $quizzes[0];

        $rows = $this->db->select('questions', [
            'columns' => 'id, position, body, options, concept',
            'filter'  => ['quiz_id' => "eq.$quizId"],
            'order'   => 'position.asc',
        ], $token);

        // Note: 'options' is a Postgres text[] — PostgREST returns it as an
        // array. Normalise each question and exclude correct_index so answers
        // are graded server-side, not exposed to the browser.
        $questions = array_map(function ($r) {
            return [
                'id'      => $r['id'] ?? null,
                'position'=> (int) ($r['position'] ?? 0),
                'body'    => $r['body'] ?? '',
                'options' => $r['options'] ?? [],
                'concept' => $r['concept'] ?? 'General',
            ];
        }, $rows);

        return ['quiz' => $quiz, 'questions' => $questions];
    }

    /**
     * List the user's quizzes, most recent first.
     */
    public function listUserQuizzes(string $userId, ?string $token = null, int $limit = 20): array
    {
        return $this->db->select('quizzes', [
            'columns' => 'id, title, topic, difficulty, question_count, question_type, ai_provider, created_at, updated_at',
            'filter'  => ['user_id' => "eq.$userId"],
            'order'   => 'created_at.desc',
            'limit'   => $limit,
        ], $token);
    }

    /**
     * List the user's quiz attempts (completed), most recent first. Used by the
     * analytics engine to build score trends and aggregate statistics.
     */
    public function listUserAttempts(string $userId, ?string $token = null, int $limit = 200): array
    {
        return $this->db->select('quiz_attempts', [
            'columns' => 'id, quiz_id, percent, score, total, completed_at',
            'filter'  => ['user_id' => "eq.$userId"],
            'order'   => 'completed_at.desc',
            'limit'   => $limit,
        ], $token);
    }

    /**
     * List the user's accumulated concept-performance rows (mastery per concept).
     */
    public function listConceptPerformance(string $userId, ?string $token = null): array
    {
        return $this->db->select('concept_performance', [
            'columns' => 'id, concept, attempts, correct, mastery, updated_at',
            'filter'  => ['user_id' => "eq.$userId"],
            'order'   => 'mastery.asc',
        ], $token);
    }

    /**
     * Grade and persist a quiz attempt plus the individual answers, and
     * update concept performance.
     *
     * @param array{question_id:string, selected:int}[] $answers
     * @return array{attempt: array, score:int, total:int, percent:float, correct:array, incorrect:array}
     */
    public function submitAttempt(
        string $userId,
        string $quizId,
        string $aiProvider,
        array $answers,
        ?string $token = null
    ): array {
        // Load the authoritative quiz/questions (with correct answers) to grade.
        $source = $this->loadQuizForGrading($userId, $quizId, $token);
        if ($source === null) {
            throw new SupabaseException('Quiz not found.', 404);
        }
        $questions = $source['questions']; // keyed by id with correct_index
        $total = count($questions);

        $score = 0;
        $correct = [];
        $incorrect = [];
        $normalisedAnswers = [];

        foreach ($answers as $ans) {
            $qid = $ans['question_id'] ?? null;
            $selected = (int) ($ans['selected'] ?? -1);
            $q = $questions[$qid] ?? null;
            if (!$q) {
                continue;
            }
            $isCorrect = ($selected === $q['correct_index']);

            $normalisedAnswers[] = [
                'question_id'    => $qid,
                'selected_index' => $selected,
                'is_correct'     => $isCorrect,
            ];
            if ($isCorrect) {
                $score++;
                $correct[] = $qid;
            } else {
                $incorrect[] = $qid;
            }
        }

        $percent = $total > 0 ? round(($score / $total) * 100, 2) : 0.0;

        $attemptRow = $this->db->insert('quiz_attempts', [
            'quiz_id'      => $quizId,
            'user_id'      => $userId,
            'score'        => $score,
            'total'        => $total,
            'percent'      => $percent,
            'ai_provider'  => $aiProvider,
            'completed_at' => gmdate('c'),
        ], $token);

        $attemptId = $attemptRow[0]['id'] ?? ($attemptRow['id'] ?? null);
        if (!$attemptId) {
            throw new SupabaseException('Could not save your attempt. Please try again.', 500);
        }

        foreach ($normalisedAnswers as $a) {
            $this->db->insert('answers', [
                'attempt_id'     => $attemptId,
                'question_id'    => $a['question_id'],
                'user_id'        => $userId,
                'selected_index' => $a['selected_index'],
                'is_correct'     => $a['is_correct'],
            ], $token);
        }

        $this->updateConceptPerformance($userId, $questions, $answers, $token);

        return [
            'attempt'   => $attemptRow[0] ?? $attemptRow,
            'score'     => $score,
            'total'     => $total,
            'percent'   => $percent,
            'correct'   => $correct,
            'incorrect' => $incorrect,
        ];
    }

    /**
     * Load a quiz's questions WITH their correct answers for server-side grading.
     */
    private function loadQuizForGrading(string $userId, string $quizId, ?string $token): ?array
    {
        $quizzes = $this->db->select('quizzes', [
            'columns' => '*',
            'filter'  => ['id' => "eq.$quizId", 'user_id' => "eq.$userId"],
            'limit'   => 1,
        ], $token);
        if (!$quizzes) {
            return null;
        }
        $rows = $this->db->select('questions', [
            'columns' => 'id, quiz_id, position, body, options, correct_index, concept, explanation',
            'filter'  => ['quiz_id' => "eq.$quizId"],
            'order'   => 'position.asc',
        ], $token);

        $questions = [];
        foreach ($rows as $r) {
            $questions[$r['id']] = [
                'id'            => $r['id'],
                'body'          => $r['body'],
                'options'       => $r['options'],
                'correct_index' => (int) $r['correct_index'],
                'concept'       => $r['concept'],
                'explanation'   => $r['explanation'],
            ];
        }
        return ['quiz' => $quizzes[0], 'questions' => $questions];
    }

    /**
     * Update (or create) per-concept performance rows after an attempt, so the
     * dashboard weak/strong areas reflect real data.
     */
    private function updateConceptPerformance(string $userId, array $questions, array $answers, ?string $token): void
    {
        $answeredByQuestion = [];
        foreach ($answers as $a) {
            $answeredByQuestion[$a['question_id'] ?? null] = (int) ($a['selected'] ?? -1);
        }

        // Group results by concept.
        $byConcept = [];
        foreach ($questions as $qid => $q) {
            $concept = $q['concept'] !== '' ? $q['concept'] : 'General';
            if (!isset($byConcept[$concept])) {
                $byConcept[$concept] = ['attempts' => 0, 'correct' => 0];
            }
            $byConcept[$concept]['attempts']++;
            if (($answeredByQuestion[$qid] ?? -1) === $q['correct_index']) {
                $byConcept[$concept]['correct']++;
            }
        }

        foreach ($byConcept as $concept => $stats) {
            // Accumulate across attempts: read the current totals (if any) so a
            // retake adds to, rather than overwrites, historical performance.
            $rows = $this->db->select('concept_performance', [
                'columns' => 'id, attempts, correct',
                'filter'  => ['user_id' => "eq.$userId", 'concept' => "eq.$concept"],
                'limit'   => 1,
            ], $token);

            $prevAttempts = 0;
            $prevCorrect  = 0;
            if ($rows) {
                $prevAttempts = (int) ($rows[0]['attempts'] ?? 0);
                $prevCorrect  = (int) ($rows[0]['correct'] ?? 0);
            }

            $totalAttempts = $prevAttempts + $stats['attempts'];
            $totalCorrect  = $prevCorrect + $stats['correct'];
            $mastery       = $totalAttempts > 0 ? round(($totalCorrect / $totalAttempts) * 100, 2) : 0;

            if ($rows) {
                $this->db->update('concept_performance', [
                    'attempts' => $totalAttempts,
                    'correct'  => $totalCorrect,
                    'mastery'  => $mastery,
                ], ['user_id' => "eq.$userId", 'concept' => "eq.$concept"], $token);
            } else {
                $this->db->insert('concept_performance', [
                    'user_id'  => $userId,
                    'concept'  => $concept,
                    'attempts' => $totalAttempts,
                    'correct'  => $totalCorrect,
                    'mastery'  => $mastery,
                ], $token);
            }
        }
    }

    /**
     * Fetch a completed attempt with its answers for the results page.
     */
    public function findAttemptForUser(string $userId, string $attemptId, ?string $token = null): ?array
    {
        $attempts = $this->db->select('quiz_attempts', [
            'columns' => '*',
            'filter'  => ['id' => "eq.$attemptId", 'user_id' => "eq.$userId"],
            'limit'   => 1,
        ], $token);
        if (!$attempts) {
            return null;
        }
        $attempt = $attempts[0];

        $answerRows = $this->db->select('answers', [
            'columns' => 'question_id, selected_index, is_correct',
            'filter'  => ['attempt_id' => "eq.$attemptId"],
        ], $token);

        $answers = [];
        foreach ($answerRows as $r) {
            $answers[$r['question_id']] = [
                'selected_index' => (int) $r['selected_index'],
                'is_correct'     => (bool) $r['is_correct'],
            ];
        }

        return [
            'attempt' => $attempt,
            'quiz'    => null, // populated by caller if needed
            'answers' => $answers,
        ];
    }

    /**
     * Build a fully self-contained "review" payload for a just-submitted
     * attempt: each question with options, the user's selection, correctness,
     * the correct answer and a human explanation. Used by the results page.
     */
    public function loadFullDetailForReview(string $userId, array $result, ?string $token): ?array
    {
        $quizId = $result['attempt']['quiz_id'] ?? null;
        $attemptId = $result['attempt']['id'] ?? null;
        if (!$quizId || !$attemptId) {
            return null;
        }

        $source = $this->loadQuizForGrading($userId, $quizId, $token);
        if ($source === null) {
            return null;
        }

        $answerRows = $this->db->select('answers', [
            'columns' => 'question_id, selected_index, is_correct',
            'filter'  => ['attempt_id' => "eq.$attemptId"],
        ], $token);

        $byQuestion = [];
        foreach ($answerRows as $r) {
            $byQuestion[$r['question_id']] = ['selected' => (int) $r['selected_index']];
        }

        $detail = [];
        foreach ($source['questions'] as $q) {
            $selected = $byQuestion[$q['id']]['selected'] ?? -1;
            $detail[] = [
                'question'       => $q['body'],
                'options'        => $q['options'],
                'selected'       => $selected,
                'correct'        => $q['correct_index'],
                'is_correct'     => $selected === $q['correct_index'],
                'explanation'    => $q['explanation'] ?? '',
                'concept'        => $q['concept'] ?? 'General',
            ];
        }

        return [
            'quiz_title' => $source['quiz']['title'] ?? 'Quiz',
            'topic'      => $source['quiz']['topic'] ?? '',
            'detail'     => $detail,
        ];
    }

    /**
     * Build a review detail payload for an existing attempt (used by the
     * results page when viewing a previously-saved attempt).
     */
    public function loadDetailForAttempt(string $userId, string $attemptId, string $quizId, ?string $token): ?array
    {
        $source = $this->loadQuizForGrading($userId, $quizId, $token);
        if ($source === null) {
            return null;
        }

        $answerRows = $this->db->select('answers', [
            'columns' => 'question_id, selected_index',
            'filter'  => ['attempt_id' => "eq.$attemptId"],
        ], $token);

        $byQuestion = [];
        foreach ($answerRows as $r) {
            $byQuestion[$r['question_id']] = ['selected' => (int) $r['selected_index']];
        }

        $detail = [];
        foreach ($source['questions'] as $q) {
            $selected = $byQuestion[$q['id']]['selected'] ?? -1;
            $detail[] = [
                'question'    => $q['body'],
                'options'     => $q['options'],
                'selected'    => $selected,
                'correct'     => $q['correct_index'],
                'is_correct'  => $selected === $q['correct_index'],
                'explanation' => $q['explanation'] ?? '',
                'concept'     => $q['concept'] ?? 'General',
            ];
        }

        return [
            'quiz_title' => $source['quiz']['title'] ?? 'Quiz',
            'topic'      => $source['quiz']['topic'] ?? '',
            'detail'     => $detail,
        ];
    }
}
