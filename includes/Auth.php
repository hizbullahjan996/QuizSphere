<?php
/**
 * QuizSphere - Auth service
 * -------------------------------------------------------------
 * Centralises authentication: signup, login, logout, session
 * persistence and user access protection. Uses the server-side PHP
 * session to store the Supabase session so tokens never live in
 * frontend JavaScript.
 *
 * The login page flow for local development is documented in the README
 * (email confirmation can be disabled in Supabase settings).
 */

declare(strict_types=1);

class Auth
{
    private SupabaseClient $client;

    public function __construct(SupabaseClient $client)
    {
        $this->client = $client;
    }

    /** True when a user session exists in the PHP session. */
    public function check(): bool
    {
        return !empty($_SESSION['user'] ?? null);
    }

    /** Returns the current user array, or null. */
    public function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    /** Returns the current user id, or null. */
    public function id(): ?string
    {
        return $_SESSION['user']['id'] ?? null;
    }

    /**
     * Register a new user, then create their Supabase profile record.
     *
     * When signup is blocked by Supabase's email-send quota and
     * AUTH_QUOTA_FALLBACK is enabled, the account is created + confirmed
     * server-side via the GoTrue admin API and the user is signed in, so
     * registration still completes without a verification email.
     *
     * @return array{user: array, session: array|null}
     * @throws SupabaseException on any failure.
     */
    public function register(string $fullName, string $email, string $password): array
    {
        $noEmailVerification = defined('DISABLE_EMAIL_VERIFICATION') && DISABLE_EMAIL_VERIFICATION;

        try {
            if ($noEmailVerification) {
                // Create the account admin-confirmed directly: no
                // verification email is sent and the user can sign in
                // immediately after registering.
                $this->client->adminEnsureConfirmedUser($email, $password, ['full_name' => $fullName]);
                $session = $this->client->signInWithPassword($email, $password);
                $result = [
                    'user'    => $session['user'] ?? [],
                    'session' => $session,
                ];
            } else {
                $result = $this->client->signUp($email, $password, ['full_name' => $fullName]);
            }
        } catch (SupabaseException $e) {
            $fallbackOn = !defined('AUTH_QUOTA_FALLBACK') || AUTH_QUOTA_FALLBACK;
            if (!$noEmailVerification && $e->supabaseCode === 'over_email_send_rate_limit' && $fallbackOn) {
                if (class_exists('AppLogger')) {
                    AppLogger::warning('Signup email quota exhausted; using admin-confirmed fallback', [], 'auth');
                }
                $this->client->adminEnsureConfirmedUser($email, $password, ['full_name' => $fullName]);
                $session = $this->client->signInWithPassword($email, $password);
                $result = [
                    'user'    => $session['user'] ?? [],
                    'session' => $session,
                ];
            } else {
                throw $e;
            }
        }

        // Create a profile row. The DB trigger normally handles this, but we
        // also ensure via service role so it works when triggers are disabled.
        $uid = $result['user']['id'] ?? null;
        if ($uid) {
            try {
                $this->client->insert('profiles', [
                    'id'        => $uid,
                    'email'     => $email,
                    'full_name' => $fullName,
                ], null, true); // service role
            } catch (SupabaseException $e) {
                // If the profile already exists (trigger ran), ignore.
            }
        }

        return $result;
    }

    /**
     * Log a user in; stores the session. Returns the user on success.
     *
     * @throws SupabaseException on invalid credentials or network error.
     */
    public function login(string $email, string $password): array
    {
        $session = $this->client->signInWithPassword($email, $password);
        $access  = $session['access_token'] ?? '';
        $user    = $session['user'] ?? $this->client->getUser($access);

        if (!$user) {
            throw new SupabaseException('Unable to retrieve your account. Please try again.', 400);
        }

        // Persist only safe fields plus the tokens needed for refresh.
        $_SESSION['user'] = [
            'id'        => $user['id'],
            'email'     => $user['email'] ?? '',
            'full_name' => $user['user_metadata']['full_name'] ?? '',
            'avatar'    => $user['user_metadata']['avatar_url'] ?? null,
        ];
        $_SESSION['auth'] = [
            'access_token'  => $access,
            'refresh_token' => $session['refresh_token'] ?? '',
        ];
        $_SESSION['logged_in_at'] = time();

        return $user;
    }

    /**
     * Adopt an already-issued session (e.g. returned by signup when email
     * confirmation is disabled) without another network round-trip.
     */
    public function adoptSession(array $session): void
    {
        $user   = $session['user'] ?? null;
        $access = (string) ($session['access_token'] ?? '');
        if (!is_array($user) || empty($user['id']) || $access === '') {
            throw new SupabaseException('Unexpected session payload.', 500);
        }
        $_SESSION['user'] = [
            'id'        => $user['id'],
            'email'     => $user['email'] ?? '',
            'full_name' => $user['user_metadata']['full_name'] ?? '',
            'avatar'    => $user['user_metadata']['avatar_url'] ?? null,
        ];
        $_SESSION['auth'] = [
            'access_token'  => $access,
            'refresh_token' => (string) ($session['refresh_token'] ?? ''),
        ];
        $_SESSION['logged_in_at'] = time();
    }

    /** Log the current user out (client + local session). */
    public function logout(): void
    {
        $token = $_SESSION['auth']['access_token'] ?? null;
        try {
            $this->client->signOut($token);
        } catch (SupabaseException $e) {
            // Ignore network errors; always clear local session.
        }
        $this->destroySession();
    }

    public function destroySession(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /**
     * Returns the authenticated user or redirects to the login page.
     */
    public function requireUser(string $redirectTo = '/login.php'): array
    {
        if (!$this->check()) {
            set_flash('error', 'Please log in to continue.');
            redirect($redirectTo);
        }
        return $this->user();
    }

    /**
     * Fetch the user's profile row from the database (full profile data).
     * Falls back to session metadata on database error.
     */
    public function profile(): ?array
    {
        $uid = $this->id();
        $token = $this->accessToken();
        if (!$uid) {
            return null;
        }
        try {
            $rows = $this->client->select('profiles', [
                'columns' => '*',
                'filter'  => ['id' => "eq.$uid"],
                'limit'   => 1,
            ], $token);
            return $rows[0] ?? null;
        } catch (SupabaseException $e) {
            return null;
        }
    }

    /** Current valid access token, refreshed if possible. */
    public function accessToken(): ?string
    {
        $auth = $_SESSION['auth'] ?? [];
        $token = $auth['access_token'] ?? null;
        $refresh = $auth['refresh_token'] ?? null;

        // Guard against an obviously expired token by checking the JWT exp claim.
        if ($token && $this->isExpiredJwt($token) && $refresh) {
            $res = $this->client->refreshToken($refresh);
            if ($res && !empty($res['access_token'])) {
                $_SESSION['auth']['access_token'] = $res['access_token'];
                $_SESSION['auth']['refresh_token'] = $res['refresh_token'] ?? $refresh;
                return $res['access_token'];
            }
            // Refresh failed -> force re-login.
            $this->destroySession();
            return null;
        }
        return $token;
    }

    /** Lightweight JWT expiry check without external libraries. */
    private function isExpiredJwt(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return true;
        }
        $payload = base64_decode(strtr($parts[1], '-_', '+/'));
        $data = json_decode((string) $payload, true);
        if (!isset($data['exp'])) {
            return false;
        }
        return (time() + 30) >= (int) $data['exp'];
    }
}

/* ==========================================================================
   Flash messaging helpers
   ========================================================================== */

function flash_exists(string $key): bool
{
    return !empty($_SESSION['_flash'][$key] ?? null);
}

function set_flash(string $type, string $message): void
{
    $_SESSION['_flash'][$type] = $message;
}

function get_flash(string $type): ?string
{
    if (flash_exists($type)) {
        $msg = $_SESSION['_flash'][$type];
        unset($_SESSION['_flash'][$type]);
        return $msg;
    }
    return null;
}

/** Redirect and terminate. */
function redirect(string $to): never
{
    header('Location: ' . url($to));
    exit;
}
