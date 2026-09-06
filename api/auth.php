<?php
/**
 * QuizSphere - Authentication API
 * -------------------------------------------------------------
 * Handles signup / login / logout using the server-side Auth service.
 * Only ever called over POST (via the login & register forms). Errors are
 * returned as JSON with friendly messages; on success we return a redirect
 * target the client navigates to (or performs a server redirect when the
 * request is a plain form post).
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$services = api_services();
if (!$services) {
    exit;
}
$auth = $services['auth'];

$isJson = (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json'));
$input = $isJson ? Security::readJsonInput() : $_POST;

$action = $input['action'] ?? '';

// All auth actions change state -> require CSRF.
api_require_csrf($input);

try {
    switch ($action) {
        case 'signup':
            $fullName = trim((string) ($input['fullname'] ?? ''));
            $email    = strtolower(trim((string) ($input['email'] ?? '')));
            $password = (string) ($input['password'] ?? '');
            $confirm  = (string) ($input['confirm_password'] ?? '');

            $errors = [];
            if ($fullName === '') { $errors[] = 'Please enter your full name.'; }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Please enter a valid email address.'; }
            if (strlen($password) < 6) { $errors[] = 'Password must be at least 6 characters.'; }
            if ($password !== $confirm) { $errors[] = 'Passwords do not match.'; }

            if ($errors) {
                Security::json(['error' => 'validation', 'message' => implode(' ', $errors)], 422);
            }

            // One signup per session per few seconds: a double click must never
            // produce two Supabase calls (each one consumes email quota).
            $now = time();
            if (!empty($_SESSION['_signup_lock']) && ($now - (int) $_SESSION['_signup_lock']) < 5) {
                Security::json([
                    'error'   => 'too_fast',
                    'message' => 'Please wait a few seconds before trying again.',
                ], 429);
            }
            $_SESSION['_signup_lock'] = $now;

            $result = $auth->register($fullName, $email, $password);
            $session = $result['session'] ?? null;
            if (is_array($session) && !empty($session['access_token'])) {
                // Email confirmation disabled: signup already issued a session.
                $auth->adoptSession($session);
                Security::json([
                    'error'   => null,
                    'message' => 'Welcome to QuizSphere!',
                    'redirect'=> url('/pages/dashboard.php'),
                ], 200);
            }

            // GoTrue answers signup for an EXISTING account with a fake
            // session-less 200 (user-enumeration protection). Its tell: an
            // empty identities array — a genuine signup always includes the
            // email identity.
            $signedUpUser = $result['user'] ?? [];
            $identities = $signedUpUser['identities'] ?? null;
            if (isset($signedUpUser['id']) && is_array($identities) && count($identities) === 0) {
                try {
                    $auth->login($email, $password);
                    Security::json([
                        'error'   => null,
                        'message' => 'Welcome to QuizSphere!',
                        'redirect'=> url('/pages/dashboard.php'),
                    ], 200);
                } catch (SupabaseException $e) {
                    Security::json([
                        'error'   => 'user_already_exists',
                        'message' => 'An account with this email already exists. Try logging in instead.',
                    ], 422);
                }
            }

            // No session means email confirmation is required.
            Security::json([
                'error'   => 'confirm_required',
                'message' => 'Account created! Please check your email to confirm your address, then sign in.',
            ], 200);
            // no break

        case 'login':
            $email    = strtolower(trim((string) ($input['email'] ?? '')));
            $password = (string) ($input['password'] ?? '');

            if ($email === '' || $password === '') {
                Security::json(['error' => 'validation', 'message' => 'Please enter your email and password.'], 422);
            }

            try {
                $auth->login($email, $password);
            } catch (SupabaseException $e) {
                if ($e->status === 503) {
                    Security::json([
                        'error'   => 'network_error',
                        'message' => 'Cannot reach the authentication service right now. Please try again in a moment.',
                    ], 503);
                }
                Security::json(['error' => 'invalid_credentials', 'message' => $e->getMessage()], 401);
            }
            Security::json([
                'error'   => null,
                'message' => 'Successfully signed in.',
                'redirect'=> url('/pages/dashboard.php'),
            ], 200);
            // no break

        case 'logout':
            if ($auth->check()) {
                $auth->logout();
            }
            Security::json([
                'error'   => null,
                'message' => 'Signed out.',
                'redirect'=> url('/index.php'),
            ], 200);
            // no break

        default:
            Security::json(['error' => 'bad_request', 'message' => 'Unknown action.'], 400);
    }
} catch (SupabaseException $e) {
    AppLogger::warning('Auth API Supabase error', [
        'action' => $action,
        'status' => $e->status,
        'code'   => $e->supabaseCode,
        'error'  => $e->getMessage(),
    ], 'auth');
    $httpStatus = $e->status >= 400 && $e->status < 500 ? $e->status : ($e->status >= 500 ? $e->status : 500);
    $message = $e->getMessage();
    if ($e->status === 503) {
        $message = 'Cannot reach the authentication service right now. Please try again in a moment.';
    }
    Security::json([
        'error'   => $httpStatus < 500 ? 'auth_error' : 'server_error',
        'message' => $message,
    ], $httpStatus);
} catch (Throwable $t) {
    AppLogger::error('Auth API unexpected error', ['exception' => $t->getMessage()], 'auth');
    Security::json(['error' => 'server_error', 'message' => 'Something went wrong. Please try again.'], 500);
}
