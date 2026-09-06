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
