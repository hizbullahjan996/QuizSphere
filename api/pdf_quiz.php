<?php
/**
 * QuizSphere - PDF to AI Quiz API
 * -------------------------------------------------------------
 * POST (multipart/form-data). Authenticated, CSRF-protected, rate-limited.
 *
 * Flow: validate & securely store the uploaded PDF in Supabase Storage,
 * extract its text server-side, generate questions strictly from that text
 * via the existing AI service layer, then save and return the new quiz.
 *
 * Correct answers and AI keys are never exposed to the browser.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const PDF_UPLOAD_MAX_BYTES = 10 * 1024 * 1024; // 10 MB

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Security::json(['error' => 'method_not_allowed', 'message' => 'Use POST.'], 405);
}

$services = api_services();
if (!$services) {
    exit;
}
['auth' => $auth, 'repo' => $repo] = $services;

api_require_auth($auth);
$userId = $auth->id();
$token  = $auth->accessToken();
if (!$token) {
    Security::json(['error' => 'unauthenticated', 'message' => 'Your session expired. Please log in again.'], 401);
}

// CSRF (multipart form posts the token as a field).
$csrf = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!Security::csrfValidate($csrf)) {
    Security::json([
        'error'   => 'invalid_csrf',
        'message' => 'Your session token is invalid. Please reload the page and try again.',
    ], 419);
}

if (!RateLimiter::hit(RateLimiter::actor($userId, RateLimiter::clientIp()))) {
    Security::json([
        'error'   => 'rate_limited',
        'message' => 'You have reached the limit for generating quizzes right now. Please try again later.',
    ], 429);
}

// -----------------------------------------------------------
// 1. Validate the uploaded file
// -----------------------------------------------------------
$file = $_FILES['pdf'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $code = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
    $errMsg = match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The PDF is too large to upload.',
        UPLOAD_ERR_NO_FILE => 'Please choose a PDF file to upload.',
        UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
        default => 'The file could not be uploaded. Please try again.',
    };
    Security::json(['error' => 'upload', 'message' => $errMsg], 422);
}

$size = (int) ($file['size'] ?? 0);
if ($size <= 0 || $size > PDF_UPLOAD_MAX_BYTES) {
    Security::json(['error' => 'upload', 'message' => 'The PDF must be between 1 byte and 10 MB.'], 422);
}

$tmpName = (string) ($file['tmp_name'] ?? '');
if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    Security::json(['error' => 'upload', 'message' => 'The PDF could not be read. Please try again.'], 422);
}

// Reject anything that is not a genuine PDF (blocks executable disguises).
$finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
if ($finfo !== false) {
    $mime = finfo_file($finfo, $tmpName);
    finfo_close($finfo);
    if (!is_string($mime) || !preg_match('#application/pdf#i', $mime)) {
        Security::json([
            'error'   => 'upload',
            'message' => 'Only text-based PDF files are allowed.',
        ], 422);
    }
} else {
    // Fallback: verify the PDF header signature directly.
    $head = @fread(fopen($tmpName, 'rb'), 1024);
    if (!is_string($head) || stripos($head, '%PDF') === false) {
        Security::json([
            'error'   => 'upload',
            'message' => 'Only text-based PDF files are allowed.',
        ], 422);
    }
}

// Guard against a directory path in the provided name.
$original = basename((string) ($file['name'] ?? 'upload.pdf'));
$safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', $original) ?: 'study-material.pdf';
if (!str_ends_with(strtolower($safeBase), '.pdf')) {
    $safeBase .= '.pdf';
}

$count = (int) ($_POST['question_count'] ?? 10);
if ($count < 1 || $count > 15) {
    Security::json(['error' => 'validation', 'message' => 'Number of questions must be between 1 and 15.'], 422);
}
$difficulty = strtolower(trim((string) ($_POST['difficulty'] ?? 'medium')));
if (!in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
    $difficulty = 'medium';
}

if (!ai_configured()) {
    Security::json([
        'error'   => 'ai_not_configured',
        'message' => 'AI quiz generation is not configured yet. Please add your AI API keys in config/env.php.',
    ], 503);
}

$client = SupabaseClient::fromConfig();

// -----------------------------------------------------------
// 2. Securely store the PDF in Supabase Storage
// -----------------------------------------------------------
$storagePath = $userId . '/' . bin2hex(random_bytes(8)) . '-' . $safeBase;
try {
    $content = file_get_contents($tmpName);
    if ($content === false) {
        throw new SupabaseException('Could not read the uploaded PDF.', 500);
    }
    $client->uploadObject('study-materials', $storagePath, $content, 'application/pdf', $token);
} catch (SupabaseException $e) {
    if ($e->status === 404 || stripos((string) $e->getMessage(), 'storage') !== false) {
        Security::json([
            'error'   => 'storage',
            'message' => 'Could not store the PDF. Please ensure the "study-materials" storage bucket exists and is configured.',
        ], 503);
    }
    Security::json(['error' => 'storage', 'message' => 'Could not store your PDF. Please try again.'], 500);
}

// -----------------------------------------------------------
// 3. Extract text from the PDF
// -----------------------------------------------------------
try {
    $extractor = new PdfTextExtractor();
    $sourceText = $extractor->extract($tmpName);
} catch (Throwable $e) {
    AppLogger::warning('PDF text extraction failed', ['message' => $e->getMessage()], 'pdf');
    Security::json([
        'error'   => 'pdf_unreadable',
        'message' => 'This PDF could not be processed. Please upload a text-based PDF.',
    ], 422);
}

// -----------------------------------------------------------
// 4. Generate a quiz strictly from the extracted material
// -----------------------------------------------------------
$topic = preg_replace('/\.pdf$/i', '', $safeBase) ?: 'Study Material';
$topic = ucfirst(mb_substr($topic, 0, 80));

$generator = new QuizGenerator();
try {
    $result = $generator->generateAndSave($userId, $topic, $difficulty, $count, 'multiple_choice', $token, $sourceText);
} catch (AiProviderException $e) {
    AppLogger::error('PDF quiz generation failed', ['user_id' => $userId, 'message' => $e->getMessage()], 'ai');
    Security::json([
        'error'   => 'ai_unavailable',
        'message' => $e->retryable
            ? 'The AI service is temporarily unavailable. Please try again in a moment.'
            : 'Unable to generate a quiz from this PDF right now. Please try again later.',
    ], 502);
} catch (InvalidArgumentException $e) {
    Security::json(['error' => 'validation', 'message' => $e->getMessage()], 422);
} catch (Throwable $t) {
    AppLogger::error('PDF quiz persistence failed', ['message' => $t->getMessage()], 'quiz');
    Security::json([
        'error'   => 'save_failed',
        'message' => 'Your quiz was generated but could not be saved. Please try again.',
    ], 500);
}

$quiz = $result['quiz'];
Security::json([
    'error'   => null,
    'message' => 'Quiz generated from your PDF!',
    'quiz'    => [
        'id'             => $quiz['id'],
        'title'          => $result['title'],
        'topic'          => $topic,
        'difficulty'     => $difficulty,
        'question_count' => $quiz['question_count'],
        'provider'       => $result['provider'],
    ],
], 200);
