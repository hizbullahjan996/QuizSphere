<?php
/**
 * QuizSphere - Quiz interface (real generated quiz)
 * -------------------------------------------------------------
 * Authenticated. Loads a quiz via api/quiz.php using the ?id= parameter and
 * submits answers server-side via api/submit_attempt.php. Correct answers
 * are never sent to the browser; grading happens on the server.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

try {
    $client = SupabaseClient::fromConfig();
    $auth = new Auth($client);
} catch (SupabaseException $e) {
    $auth = null;
}

if (!$auth || !$auth->check()) {
    set_flash('error', 'Please log in to take a quiz.');
    redirect('/login.php');
}

$quizId = (string) ($_GET['id'] ?? '');
if ($quizId === '' || !preg_match('/^[0-9a-fA-F-]{36}$/', $quizId)) {
    set_flash('error', 'Please choose a quiz to begin.');
    redirect('/pages/dashboard.php');
}

$page_title = 'Quiz';
$active_page = 'pages';
require __DIR__ . '/../includes/head.php';
?>
<body>
<div class="dashboard-shell">
  <?php $dashboard_active = 'quizzes'; require __DIR__ . '/../includes/sidebar.php'; ?>

  <main class="dashboard-main">
    <div class="quiz-shell" id="quiz-app" data-quiz-id="<?php echo e($quizId); ?>">

      <!-- Quiz header -->
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
        <div>
          <a href="<?php echo url('/pages/dashboard.php'); ?>" class="text-muted-ink small mb-2 d-inline-block">
            <i class="bi bi-arrow-left me-1"></i>Back to dashboard
          </a>
          <h1 class="h3 mb-1" id="quiz-title">Loading quiz...</h1>
          <div class="quiz-meta">
            <span class="badge-soft"><i class="bi bi-tag me-1"></i><span id="quiz-topic">…</span></span>
            <span class="badge-soft" id="quiz-difficulty">…</span>
          </div>
        </div>
        <span class="timer-chip" id="quiz-timer">
          <i class="bi bi-clock"></i><span>00:00</span>
        </span>
      </div>

      <!-- Progress bar -->
      <div class="card p-4 mb-4">
        <div class="d-flex justify-content-between small text-muted-ink mb-2">
          <span id="question-number">Question 1 of N</span><span>Progress</span>
        </div>
        <div class="progress" role="progressbar" aria-label="Quiz progress" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
          <div class="progress-bar" id="quiz-progress" style="width:0%"></div>
        </div>
      </div>

      <!-- Loader -->
      <div class="text-center py-5" id="quiz-loading">
        <div class="spinner-border text-brand mb-3" role="status"></div>
        <p class="text-muted-ink mb-0">Loading your quiz...</p>
      </div>

      <!-- Question card (hidden until loaded) -->
      <div class="card question-card p-4 p-md-5 d-none" id="quiz-body">
        <h2 class="h4 mb-4" id="question-text"></h2>
        <div class="d-flex flex-column" id="question-options"></div>
      </div>

      <!-- Error state -->
      <div class="text-center py-5 d-none" id="quiz-error">
        <i class="bi bi-exclamation-triangle text-danger" style="font-size:2rem;"></i>
        <h5 class="mt-3 mb-1">Couldn't load this quiz</h5>
        <p class="text-muted-ink mb-3" id="quiz-error-message"></p>
        <a href="<?php echo url('/pages/dashboard.php'); ?>" class="btn btn-outline-brand">Back to Dashboard</a>
      </div>

      <!-- Navigation -->
      <div class="d-flex justify-content-between align-items-center mt-4 d-none" id="quiz-nav">
        <button class="btn btn-outline-brand" id="prev-btn">
          <i class="bi bi-arrow-left me-1"></i>Previous
        </button>
        <button class="btn btn-brand" id="next-btn">
          Next<i class="bi bi-arrow-right ms-1"></i>
        </button>
        <button class="btn btn-brand d-none" id="submit-btn">
          <i class="bi bi-check-lg me-1"></i>Submit Quiz
        </button>
      </div>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
