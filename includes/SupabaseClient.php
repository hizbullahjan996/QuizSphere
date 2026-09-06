<?php
/**
 * QuizSphere - Supabase REST client (PHP)
 * -------------------------------------------------------------
 * A minimal, dependency-free client for the Supabase platform:
 *   - GoTrue  (Authentication) : /auth/v1/...
 *   - PostgREST (Database)     : /rest/v1/...
 *
 * No Composer packages are required. Uses cURL when available and
 * falls back to stream contexts otherwise.
 *
 * SECURITY: Only SUPABASE_ANON_KEY is used for client-facing requests.
 * The service-role key is kept server-side and only ever used with the
 * dedicated service-role calls (e.g. server-triggered profile creation),
 * never sent to the browser.
 *
 * @throws RuntimeException if Supabase is not configured.
 */

declare(strict_types=1);

class SupabaseException extends RuntimeException
{
    /** @var int HTTP status code from Supabase, if any */
    public int $status = 0;
    /** @var string Supabase/GoTrue error code (for server-side logging only) */
    public string $supabaseCode = '';

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->status = $code;
    }
}

class SupabaseClient
{
    private string $url;
    private string $anonKey;
    private string $serviceRoleKey;
    private string $schema;
    /** Optional "host:port:ip" pin used to bypass broken DNS/route entries. */
    private string $resolve;
    /** Persistent handle so calls within one request reuse the connection. */
    private ?\CurlHandle $curlHandle = null;

    public function __construct(
        string $url,
        string $anonKey,
        string $serviceRoleKey,
        string $schema = 'public',
        string $resolve = ''
    ) {
        $this->url = rtrim($url, '/');
        $this->anonKey = $anonKey;
        $this->serviceRoleKey = $serviceRoleKey;
        $this->schema = $schema;
        $this->resolve = $resolve;
    }

    public function __destruct()
    {
        if ($this->curlHandle !== null) {
            curl_close($this->curlHandle);
        }
    }

    /** Get a reset, reusable curl handle (keep-alive across calls). */
    private function curl(): \CurlHandle
    {
        if ($this->curlHandle === null) {
            $this->curlHandle = curl_init();
        } else {
            curl_reset($this->curlHandle);
        }
        return $this->curlHandle;
    }

    public static function fromConfig(): self
    {
        if (!supabase_configured()) {
            throw new SupabaseException(
                'Supabase is not configured. Copy config/env.example.php to config/env.php and add your keys.',
                500
            );
        }
        return new self(
            SUPABASE_URL,
            SUPABASE_ANON_KEY,
            SUPABASE_SERVICE_ROLE_KEY,
            defined('SUPABASE_SCHEMA') ? SUPABASE_SCHEMA : 'public',
            defined('SUPABASE_RESOLVE') ? SUPABASE_RESOLVE : ''
        );
    }

    /* ======================================================================
       Authentication (GoTrue)
       ====================================================================== */

    /**
     * Register a new user. Returns the auth session/user on success.
     *
     * @return array{user: array, session: array|null}
     * @throws SupabaseException on failure.
     */
    public function signUp(string $email, string $password, array $metadata = []): array
    {
        $res = $this->request('POST', '/auth/v1/signup', $this->anonKey, [
            'email'    => $email,
            'password' => $password,
            'data'     => $metadata,
        ]);
        $error = $this->extractAuthError($res['body'], $res['status']);
        if ($error !== null) {
            throw $this->mapAuthError($error, $res['status']);
        }
        $user = $res['body']['user'] ?? $res['body'] ?? [];
        return [
            'user'    => $user,
            'session' => $res['body']['session'] ?? null,
        ];
    }

    /**
     * Normalise any GoTrue error shape into ['code' => ..., 'message' => ...].
     * Handles: {error:{code,message}}, {error:"..."}, {error_code,msg},
     * and non-2xx bodies with {code,message} but no user object.
     */
    private function extractAuthError($body, int $status): ?array
    {
        if (!is_array($body)) {
            return $status >= 400 ? ['code' => '', 'message' => 'HTTP ' . $status] : null;
        }
        if (isset($body['error'])) {
            if (is_array($body['error'])) {
                return $body['error'];
            }
            return [
                'code'    => (string) ($body['error_code'] ?? ($body['code'] ?? '')),
                'message' => (string) $body['error'],
            ];
        }
        if (isset($body['error_code']) || isset($body['msg'])) {
            return [
                'code'    => (string) ($body['error_code'] ?? ''),
                'message' => (string) ($body['msg'] ?? ($body['message'] ?? '')),
            ];
        }
        if ($status >= 400 && !isset($body['user']) && !isset($body['id'])) {
            return [
                'code'    => (string) ($body['code'] ?? ''),
                'message' => (string) ($body['message'] ?? ('HTTP ' . $status)),
            ];
        }
        return null;
    }

    /**
     * Ensure a confirmed user exists for the email, via the GoTrue admin API.
     *
     * Server-side only (service-role key). Fallback for when signup cannot
     * send a confirmation email (email-send quota exhausted). Creates the
     * user confirmed; if the email is already registered, the existing
     * account is confirmed when needed (its password is never changed).
     *
     * @return array The user object.
     * @throws SupabaseException with a sanitized message on failure.
     */
    public function adminEnsureConfirmedUser(string $email, string $password, array $metadata = []): array
    {
        $fail = function (string $context, int $status, $body): void {
            $detail = is_array($body)
                ? (string) ($body['message'] ?? ($body['msg'] ?? json_encode($body)))
                : (string) $body;
            if (class_exists('AppLogger')) {
                AppLogger::warning($context, ['status' => $status, 'detail' => $detail], 'auth');
            }
            throw new SupabaseException('Registration failed. Please try again.', $status ?: 500);
        };

        // 1) Try to create the user already confirmed.
        $res = $this->request('POST', '/auth/v1/admin/users', $this->serviceRoleKey, [
            'email'         => $email,
            'password'      => $password,
            'email_confirm' => true,
            'user_metadata' => $metadata,
        ], null, true);

        $body = $res['body'];
        if ($res['status'] >= 200 && $res['status'] < 300 && is_array($body) && isset($body['id'])) {
            return $body;
        }

        $detail = is_array($body)
            ? (string) ($body['message'] ?? ($body['msg'] ?? ''))
            : (string) $body;
        $duplicate = $res['status'] === 422 && stripos($detail, 'already') !== false;
        if (!$duplicate) {
            $fail('Admin user creation failed', $res['status'], $body);
        }

        // 2) Email already registered: find the existing user.
        $list = $this->request(
            'GET',
            '/auth/v1/admin/users?email=' . rawurlencode($email),
            $this->serviceRoleKey,
            null,
            null,
            true
        );
        $user = null;
        foreach (($list['body']['users'] ?? []) as $u) {
            if (is_array($u) && strcasecmp((string) ($u['email'] ?? ''), $email) === 0) {
                $user = $u;
                break;
            }
        }
        if ($user === null) {
            $fail('Admin user lookup failed', $list['status'], $list['body']);
        }

        // 3) Confirm it when still unconfirmed (never touches the password).
        if (empty($user['email_confirmed_at'])) {
            $conf = $this->request(
                'PUT',
                '/auth/v1/admin/users/' . rawurlencode((string) $user['id']),
                $this->serviceRoleKey,
                ['email_confirm' => true],
                null,
                true
            );
            if ($conf['status'] < 200 || $conf['status'] >= 300) {
                $fail('Admin user confirmation failed', $conf['status'], $conf['body']);
            }
            if (is_array($conf['body']) && isset($conf['body']['id'])) {
                $user = $conf['body'];
            }
        }

        return $user;
    }

    /**
     * Sign in with email + password. Returns access/refresh tokens.
     */
    public function signInWithPassword(string $email, string $password): array
    {
        $res = $this->request('POST', '/auth/v1/token?grant_type=password', $this->anonKey, [
            'email'    => $email,
            'password' => $password,
        ]);
        $error = $this->extractAuthError($res['body'], $res['status']);
        if ($error !== null) {
            throw $this->mapAuthError($error, $res['status']);
        }
        if (empty($res['body']['access_token'])) {
            throw new SupabaseException(
                'Unexpected response from the authentication service. Please try again. (Status: ' . $res['status'] . ')',
                $res['status'] ?: 500
            );
        }
        return $res['body'];
    }

    /**
     * Refresh an expired access token using a refresh token.
     */
    public function refreshToken(string $refreshToken): ?array
    {
        $res = $this->request('POST', '/auth/v1/token?grant_type=refresh_token', $this->anonKey, [
            'refresh_token' => $refreshToken,
        ]);
        if (isset($res['body']['error'])) {
            return null;
        }
        return $res['body'];
    }

    /**
     * Get the user record for a valid access token.
     */
    public function getUser(string $accessToken): ?array
    {
        $res = $this->request('GET', '/auth/v1/user', $this->anonKey, null, $accessToken);
        if ($res['status'] === 200 && isset($res['body']['id'])) {
            return $res['body'];
        }
        return null;
    }

    /**
     * Sign out (server side). Optionally provides the access token to revoke.
     */
    public function signOut(?string $accessToken = null): void
    {
        if ($accessToken !== null) {
            try {
                // Best-effort and fast: never stall logout on a slow network.
                $this->request('POST', '/auth/v1/logout', $this->anonKey, [], $accessToken, false, false, 6);
            } catch (SupabaseException $e) {
                // Best-effort: local session is cleared regardless.
            }
        }
    }

    /* ======================================================================
       Database (PostgREST)
       ====================================================================== */

    public function select(string $table, array $opts = [], ?string $token = null, bool $serviceRole = false): array
    {
        $params = [];
        $params['select'] = $opts['columns'] ?? '*';
        if (!empty($opts['filter'])) {
            foreach ($opts['filter'] as $k => $v) {
                $params[$k] = $v;
            }
        }
        if (!empty($opts['order'])) {
            $params['order'] = $opts['order'];
        }
        if (!empty($opts['limit'])) {
            $params['limit'] = (string) $opts['limit'];
        }
        $query = http_build_query($params);
        $key = $serviceRole ? $this->serviceRoleKey : $this->anonKey;
        $res = $this->request('GET', "/rest/v1/$table" . ($query ? "?$query" : ''), $key, null, $token, $serviceRole);
        // PostgREST returns 200 for an array body even if empty.
        return is_array($res['body']) ? $res['body'] : [];
    }

    public function insert(string $table, array $payload, ?string $token = null, bool $serviceRole = false): array
    {
        $key = $serviceRole ? $this->serviceRoleKey : $this->anonKey;
        $res = $this->request('POST', "/rest/v1/$table", $key, $payload, $token, $serviceRole);
        $body = $res['body'];
        if (is_array($body) && isset($body['code']) && isset($body['message'])) {
            throw new SupabaseException('Database error: ' . ($body['message'] ?? 'Unknown'), (int) ($res['status'] ?? 400));
        }
        return is_array($body) ? $body : [];
    }

    public function update(string $table, array $payload, array $query, ?string $token = null): array
    {
        $params = http_build_query($query);
        $res = $this->request('PATCH', "/rest/v1/$table" . ($params ? "?$params" : ''), $this->anonKey, $payload, $token);
        return is_array($res['body']) ? $res['body'] : [];
    }

    public function delete(string $table, array $query, ?string $token = null): array
    {
        $params = http_build_query($query);
        $res = $this->request('DELETE', "/rest/v1/$table" . ($params ? "?$params" : ''), $this->anonKey, null, $token);
        return is_array($res['body']) ? $res['body'] : [];
    }

    /**
     * Call a Postgres function exposed via PostgREST (RPC).
     *
     * Useful for security-definer functions that must read across users
     * (e.g. the public leaderboard) while still being callable by an
     * authenticated user. The optional args are sent as the JSON body.
     *
     * @param mixed  $args       Positional/named arguments for the function.
     * @param string|null $token Authentication token (RLS-aware).
     * @param bool   $serviceRole Use the privileged service role.
     * @return mixed Body returned by the function.
     * @throws SupabaseException on non-2xx or network failure.
     */
    public function rpc(string $function, $args = null, ?string $token = null, bool $serviceRole = false)
    {
        $key = $serviceRole ? $this->serviceRoleKey : $this->anonKey;
        $url = $this->url . '/rest/v1/rpc/' . rawurlencode($function);
        $payload = ($args === null || $args === []) ? new \stdClass() : $args;
        $res = $this->request('POST', '/rest/v1/rpc/' . rawurlencode($function), $key, $payload, $token, $serviceRole);
        $body = $res['body'];
        if (is_array($body) && isset($body['code']) && isset($body['message'])) {
            throw new SupabaseException('RPC error: ' . ($body['message'] ?? 'Unknown'), (int) ($res['status'] ?? 400));
        }
        return $body;
    }

    /* ======================================================================
       Storage (Supabase Storage API)
       ====================================================================== */

    /**
     * Upload a raw file to a Storage bucket.
     *
     * @param string $bucket       Bucket name, e.g. 'study-materials'.
     * @param string $path         Object path within the bucket.
     * @param string $content      Raw file bytes.
     * @param string $mime         Content/MIME type of the file.
     * @param string|null $token   User access token (RLS-aware).
     * @param bool    $serviceRole Whether to use the privileged service role.
     * @return array{key: string}
     * @throws SupabaseException on network or non-2xx responses.
     */
    public function uploadObject(
        string $bucket,
        string $path,
        string $content,
        string $mime = 'application/octet-stream',
        ?string $token = null,
        bool $serviceRole = false
    ): array {
        $key = $serviceRole ? $this->serviceRoleKey : $this->anonKey;
        $url = $this->url . "/storage/v1/object/$bucket/" . ltrim($path, '/');

        $headers = [
            'apikey: ' . $key,
            'Authorization: Bearer ' . ($token ?? ($serviceRole ? $this->serviceRoleKey : $this->anonKey)),
            'Content-Type: ' . $mime,
            'x-upsert: false',
            'Accept: application/json',
        ];

        if (function_exists('curl_init')) {
            $caInfo = $this->resolveCaInfo();
            $opts = [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_POSTFIELDS     => $content,
                CURLOPT_TIMEOUT        => 45,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            ];
            if ($caInfo !== null) {
                $opts[CURLOPT_CAINFO] = $caInfo;
            } else {
                $opts[CURLOPT_SSL_VERIFYPEER] = false;
            }
            if ($this->resolve !== '') {
                $opts[CURLOPT_RESOLVE] = [$this->resolve];
            }
            $ch = $this->curl();
            curl_setopt_array($ch, $opts);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            if ($body === false) {
                throw new SupabaseException('Network failure uploading to storage: ' . $error, 503);
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => implode("\r\n", $headers),
                    'content'       => $content,
                    'ignore_errors' => true,
                    'timeout'       => 45,
                ],
            ]);
            $body = @file_get_contents($url, false, $context);
            if ($body === false) {
                throw new SupabaseException('Network failure uploading to storage.', 503);
            }
            $status = 200;
            $responseHeaders = http_get_last_response_headers();
            if (is_array($responseHeaders) && isset($responseHeaders[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $responseHeaders[0], $m)) {
                $status = (int) $m[1];
            }
        }

        if ($status < 200 || $status >= 300) {
            throw new SupabaseException(
                'Storage upload failed (HTTP ' . $status . '). Please configure the storage bucket.',
                $status
            );
        }

        $decoded = json_decode((string) $body, true);
        if (is_array($decoded) && isset($decoded['Key'])) {
            return ['key' => (string) $decoded['Key']];
        }
        // Fall back: assume the object was stored at the requested path.
        return ['key' => $bucket . '/' . ltrim($path, '/')];
    }

    /**
     * Build a public URL for an object in a bucket (works for public buckets).
     */
    public function publicObjectUrl(string $bucket, string $path): string
    {
        return $this->url . '/storage/v1/object/public/' . $bucket . '/' . ltrim($path, '/');
    }

    /* ======================================================================
       Low-level request transport
       ====================================================================== */

    /**
     * Resolve a CA certificate bundle path for TLS verification.
     *
     * Priority:
     *   1. A php.ini-configured curl.cainfo / openssl.cafile if that file exists.
     *   2. The cacert.pem shipped with the app (config/cacert.pem).
     *   3. null -> caller may decide to disable peer verification as a last resort.
     *
     * @return string|null Absolute CA bundle path, or null if none is available.
     */
    private function resolveCaInfo(): ?string
    {
        $configured = (string) (ini_get('curl.cainfo') ?: ini_get('openssl.cafile'));
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }
        $bundled = __DIR__ . '/../config/cacert.pem';
        if (is_file($bundled)) {
            return realpath($bundled) ?: $bundled;
        }
        return null;
    }

    /**
     * @param mixed $payload JSON-encodable body, or null.
     * @param bool  $retry   Retry transient connection failures (set false for
     *                       best-effort calls like logout).
     * @param int   $timeout Per-attempt total timeout in seconds.
     * @return array{status:int, body:mixed}
     */
    private function request(
        string $method,
        string $path,
        string $key,
        $payload = null,
        ?string $authToken = null,
        bool $serviceRole = false,
        bool $retry = true,
        int $timeout = 6
    ): array {
        $url = $this->url . $path;

        $json = ($payload !== null) ? json_encode($payload) : null;

        $headers = [
            'apikey: ' . $key,
            'Authorization: Bearer ' . ($authToken ?? ($serviceRole ? $this->serviceRoleKey : $this->anonKey)),
        ];
        if ($method !== 'GET' && $json !== null) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($json);
        }
        if (str_starts_with($path, '/rest/')) {
            $headers[] = 'Accept-Profile: ' . $this->schema;
            $headers[] = 'Content-Profile: ' . $this->schema;
        }
        if (str_contains($path, '/rest/') && ($method === 'POST' || $method === 'PATCH')) {
            $headers[] = 'Prefer: return=representation';
        }

        // Resolve a CA bundle so TLS verification works even when the local
        // php.ini has no reliable curl.cainfo/openssl.cafile set (common on
        // Windows). We only trust a configured bundle if that file actually
        // exists; otherwise fall back to the cacert.pem shipped with the app.
        // As an absolute last resort (no CA bundle at all) we disable peer
        // verification so HTTPS never hard-fails on a dev box. The privacy-
        // sensitive data we send is still TLS-encrypted either way.
        $caInfo = $this->resolveCaInfo();

        $curl = function_exists('curl_init');
        if ($curl) {
            $opts = [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                // The local network has no working IPv6 route; forcing v4
                // avoids hangs on dead v6 addresses.
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                // Reuse the connection between calls in the same request,
                // and probe idle connections so dead ones fail fast.
                CURLOPT_FORBID_REUSE   => false,
                CURLOPT_FRESH_CONNECT  => false,
                CURLOPT_TCP_KEEPALIVE  => 1,
                CURLOPT_TCP_KEEPIDLE   => 5,
                CURLOPT_TCP_KEEPINTVL  => 3,
            ];
            if ($caInfo !== null) {
                $opts[CURLOPT_CAINFO] = $caInfo;
            } else {
                $opts[CURLOPT_SSL_VERIFYPEER] = false;
            }
            if ($json !== null) {
                $opts[CURLOPT_POSTFIELDS] = $json;
            }
            // Retry transient connection failures (this network stalls some
            // connections at TCP/TLS); non-2xx HTTP responses are NOT retried.
            // The resolve pin is a first-attempt preference only: which of the
            // host's IPs is reachable changes over time, so retries use DNS.
            $body = false;
            $error = '';
            $status = 0;
            $maxAttempts = $retry ? 3 : 1;
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                if ($this->resolve !== '' && $attempt === 1) {
                    $opts[CURLOPT_RESOLVE] = [$this->resolve];
                } else {
                    unset($opts[CURLOPT_RESOLVE]);
                }
                $ch = $this->curl();
                curl_setopt_array($ch, $opts);
                $body = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $error = curl_error($ch);
                if ($body !== false) {
                    break;
                }
                if ($attempt < $maxAttempts) {
                    usleep(250000 * $attempt);
                }
            }
            if ($body === false) {
                throw new SupabaseException(
                    'Network failure contacting Supabase. Error: ' . ($error ?: 'unknown network error')
                    . ' (after ' . $maxAttempts . ' attempt' . ($maxAttempts > 1 ? 's' : '') . ')',
                    503
                );
            }
        } else {
            // Stream-context fallback
            $context = stream_context_create([
                'http' => [
                    'method'        => $method,
                    'header'        => implode("\r\n", $headers),
                    'content'       => $json,
                    'ignore_errors' => true,
                    'timeout'       => 30,
                ],
            ]);
            $body = @file_get_contents($url, false, $context);
            if ($body === false) {
                $last = error_get_last();
                throw new SupabaseException(
                    'Network failure contacting Supabase. Error: '
                    . ($last['message'] ?? 'stream request failed (HTTPS requires the openssl PHP extension)'),
                    503
                );
            }
            $status = 200;
            $responseHeaders = http_get_last_response_headers();
            if (is_array($responseHeaders) && isset($responseHeaders[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $responseHeaders[0], $m)) {
                $status = (int) $m[1];
            }
        }

        $decoded = json_decode((string) $body, true);
        $data = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $body;
        return ['status' => $status, 'body' => $data];
    }

    /* ======================================================================
       Error mapping
       ====================================================================== */

    private function mapAuthError(array $error, int $status): SupabaseException
    {
        $code = $error['code'] ?? '';
        $message = $error['message'] ?? 'Authentication failed.';
        $hint = $error['hint'] ?? '';

        switch ($code) {
            case 'user_already_exists':
                $msg = 'An account with this email already exists. Try logging in instead.';
                break;
            case 'invalid_credentials':
                $msg = 'Invalid email or password. Please try again.';
                break;
            case 'email_not_confirmed':
                $msg = 'Please confirm your email before logging in.';
                break;
            case 'email_address_invalid':
                $msg = 'That email address looks invalid. Please check it and try again.';
                break;
            case 'weak_password':
                $msg = 'That password is too weak. Use at least 6 characters with a mix of letters and numbers.';
                break;
            case 'over_request_rate_limit':
                $msg = 'Too many attempts. Please wait a moment and try again.';
                break;
            case 'over_email_send_rate_limit':
                $msg = 'Too many verification emails have been requested. Please wait and try again later.';
                break;
            default:
                if (stripos($message, 'password') !== false && stripos($message, '6') !== false) {
                    $msg = 'Password must be at least 6 characters long.';
                } else {
                    // Never surface unknown raw provider messages verbatim.
                    $msg = 'Authentication failed. Please try again.';
                }
        }

        if ($hint !== '' && isset($error['name']) && $error['name'] === 'RateLimitError') {
            $msg = 'Too many attempts. Please wait a moment and try again.';
        }

        $e = new SupabaseException($msg, $status);
        $e->status = $status;
        $e->supabaseCode = (string) $code;
        return $e;
    }
}
