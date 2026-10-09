<?php
/**
 * QuizSphere - Central bootstrap loader.
 * -------------------------------------------------------------
 * Include this at the top of every page/endpoint. It loads configuration,
 * shared helpers and core service classes in dependency order.
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/bootstrap.php';
 */

declare(strict_types=1);

// Handle CORS for decoupled deployments
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400'); // cache for 1 day
}

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    }
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    }
    exit(0);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/SupabaseClient.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/AppLogger.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/QuizRequest.php';
require_once __DIR__ . '/StudyMaterialCleaner.php';
require_once __DIR__ . '/AI/AiProviderInterface.php';
require_once __DIR__ . '/AI/AiProviderException.php';
require_once __DIR__ . '/AI/AiHttp.php';
require_once __DIR__ . '/AI/AiPromptBuilder.php';
require_once __DIR__ . '/AI/AiResponseValidator.php';
require_once __DIR__ . '/AI/OpenAIProvider.php';
require_once __DIR__ . '/AI/GeminiProvider.php';
require_once __DIR__ . '/AI/GroqProvider.php';
require_once __DIR__ . '/AI/AiService.php';
require_once __DIR__ . '/QuizRepository.php';
require_once __DIR__ . '/PerformanceAnalytics.php';
require_once __DIR__ . '/QuizGenerator.php';
require_once __DIR__ . '/PdfTextExtractor.php';
require_once __DIR__ . '/Gamification.php';
require_once __DIR__ . '/CertificateService.php';
require_once __DIR__ . '/AI/LearningCoach.php';
require_once __DIR__ . '/Auth.php';
