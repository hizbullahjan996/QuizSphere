<?php
/**
 * QuizSphere - Shared head fragment.
 * Expects: $page_title (string), $active (string) nav key.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$page_title   = $page_title ?? 'QuizSphere';
$doc_title    = $page_title !== 'QuizSphere' ? $page_title . ' · QuizSphere' : 'QuizSphere — AI-Powered Adaptive Learning';
$active_page  = $active_page ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#4f46e5">
  <title><?php echo e($doc_title); ?></title>
  <meta name="description" content="QuizSphere turns any topic into an intelligent learning experience with AI-generated quizzes, adaptive practice, performance analytics and certificates.">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="stylesheet" href="<?php echo url('/assets/css/style.css'); ?>">
  <script>
    window.APP_URL = <?php echo json_encode(url('/')); ?>;
    window.API_URL = 'http://localhost:8001';
  </script>
</head>
