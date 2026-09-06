<?php
/**
 * QuizSphere - Certificate service
 * -------------------------------------------------------------
 * Handles creation and public verification of achievement certificates.
 *
 * A certificate is earned when the user scores at least MIN_PERCENT on a
 * completed quiz attempt. Each qualifying attempt yields at most one
 * certificate (uniqueness enforced by attempt_id). The certificate's `id`
 * (a UUID) is both the display ID and the public verification key.
 *
 * Verification is public: the certificates RLS policy already allows any
 * anonymous caller to `select` rows where is_verified = true. We set
 * is_verified = true on creation so genuine records are verifiable, and the
 * public verification endpoint never exposes any account/session data — only
 * the student name, achievement title, score and issue date.
 */

declare(strict_types=1);

class CertificateService
{
    /** Minimum score (%) required to earn a certificate. */
    public const MIN_PERCENT = 80;

    private SupabaseClient $db;

    public function __construct(SupabaseClient $db)
    {
        $this->db = $db;
    }

    /**
     * Create a certificate for a qualifying attempt (own user, RLS-aware).
     *
     * @return array|null The created certificate row, or null if ineligible
     *                    or if a certificate for this attempt already exists.
     */
    public function createForAttempt(
        string $userId,
        string $token,
        string $attemptId,
        string $quizId,
        string $quizTitle,
        float $percent,
        string $studentName
    ): ?array {
        // Eligibility gate.
        if ($percent < self::MIN_PERCENT) {
            return null;
        }
        if ($token === null || $token === '') {
            return null;
        }

        // Skip if already created for this attempt.
        try {
            $existing = $this->db->select('certificates', [
                'columns' => 'id, title',
                'filter'  => ['user_id' => "eq.$userId", 'attempt_id' => "eq.$attemptId"],
                'limit'   => 1,
            ], $token);
            if ($existing) {
                return $existing[0];
            }
        } catch (SupabaseException $e) {
            return null;
        }

        $title = 'Certificate of Achievement';
        $achievementTitle = trim((string) $quizTitle) !== '' ? $quizTitle : 'Completed Quiz';

        try {
            $rows = $this->db->insert('certificates', [
                'user_id'        => $userId,
                'title'          => $title,
                'description'    => $achievementTitle,
                'score'          => round($percent, 2),
                'quiz_title'     => $achievementTitle,
                'quiz_id'        => $quizId,
                'attempt_id'     => $attemptId,
                'student_name'   => $studentName,
                'is_verified'    => true,
            ], $token);
        } catch (SupabaseException $e) {
            AppLogger::warning('Certificate creation failed', ['user_id' => $userId, 'message' => $e->getMessage()], 'certificate');
            return null;
        }

        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }
        return $row;
    }

    /**
     * Fetch a single certificate owned by the user (RLS-aware).
     *
     * @return array|null
     */
    public function findForUser(string $userId, string $id, string $token): ?array
    {
        try {
            $rows = $this->db->select('certificates', [
                'columns' => 'id, title, description, quiz_title, student_name, score, earned_at, is_verified',
                'filter'  => ['id' => "eq.$id", 'user_id' => "eq.$userId"],
                'limit'   => 1,
            ], $token);
        } catch (SupabaseException $e) {
            return null;
        }
        return $rows[0] ?? null;
    }

    /**
     * List the user's certificates, most recent first.
     */
    public function listForUser(string $userId, string $token, int $limit = 50): array
    {
        try {
            return $this->db->select('certificates', [
                'columns' => 'id, title, quiz_title, student_name, score, earned_at, is_verified',
                'filter'  => ['user_id' => "eq.$userId"],
                'order'   => 'earned_at.desc',
                'limit'   => $limit,
            ], $token);
        } catch (SupabaseException $e) {
            return [];
        }
    }

    /**
     * Public verification by certificate id.
     *
     * The new Supabase key model (`sb_publishable_...`) rejects anon-only REST
     * reads, so this uses the service-role (secret) key. That key stays
     * entirely server-side in PHP and is NEVER exposed to the frontend.
     *
     * Privacy is preserved two ways:
     *  - We select ONLY public-safe display fields, never emails or account data.
     *  - We require both the caller-supplied certificate UUID (which they must
     *    already have from a real certificate) AND `is_verified = true`, and we
     *    still re-check `is_verified` in PHP before returning anything.
     *
     * Querying by an unknown UUID yields no rows -> null (unverifiable).
     *
     * @return array|null Public-safe certificate data, or null if not found/verified.
     */
    public function verifyPublic(string $id): ?array
    {
        try {
            $rows = $this->db->select('certificates', [
                'columns' => 'id, title, quiz_title, student_name, score, earned_at, is_verified',
                'filter'  => ['id' => "eq.$id", 'is_verified' => 'eq.true'],
                'limit'   => 1,
            ], null, true); // null token + serviceRole=true (server-side secret key)
        } catch (SupabaseException $e) {
            return null;
        }
        $row = $rows[0] ?? null;
        if ($row === null || !((bool) ($row['is_verified'] ?? false))) {
            return null;
        }
        return [
            'id'           => (string) ($row['id'] ?? $id),
            'title'        => (string) ($row['title'] ?? 'Certificate of Achievement'),
            'quiz_title'   => (string) ($row['quiz_title'] ?? ''),
            'student_name' => (string) ($row['student_name'] ?? ''),
            'score'        => (float) ($row['score'] ?? 0),
            'earned_at'    => (string) ($row['earned_at'] ?? ''),
        ];
    }
}
