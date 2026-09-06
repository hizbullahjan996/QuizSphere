<?php
/**
 * QuizSphere - Security helpers
 * -------------------------------------------------------------
 * CSRF token generation/validation and a small JSON I/O helper used by
 * the API endpoints. No framework dependencies.
 */

declare(strict_types=1);

class Security
{
    /**
     * Get (or create) the CSRF token for the current session.
     */
    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    /**
     * Validate a supplied CSRF token against the session token.
     */
    public static function csrfValidate(?string $token): bool
    {
        return !empty($_SESSION['_csrf'])
            && is_string($token)
            && hash_equals($_SESSION['_csrf'], $token);
    }

    /**
     * Output a hidden CSRF input field.
     */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::csrfToken()) . '">';
    }

    /* ----------------------------------------------------------------------
       JSON request helpers (for API endpoints)
       ---------------------------------------------------------------------- */

    /**
     * Parse the JSON request body into an array.
     */
    public static function readJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode((string) $raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Send a JSON response and terminate.
     */
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
}
