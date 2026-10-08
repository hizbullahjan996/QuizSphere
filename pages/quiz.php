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

      <!-- Quiz header & metadata -->
      <div class="card p-3 p-md-4 mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
          <div class="d-flex align-items-center gap-3">
            <a href="<?php echo url('/pages/dashboard.php'); ?>" class="btn btn-sm btn-outline-brand rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 2.2rem; height: 2.2rem;" title="Back to dashboard">
              <i class="bi bi-arrow-left"></i>
            </a>
            <div>
              <div class="quiz-meta mb-1">
                <span class="badge-soft"><i class="bi bi-tag-fill me-1 text-brand"></i><span id="quiz-topic">…</span></span>
                <span class="badge-soft" id="quiz-difficulty">…</span>
              </div>
              <h1 class="h5 mb-0 fw-bold text-ink-900" id="quiz-title">Loading quiz...</h1>
            </div>
          </div>
          <div class="d-flex align-items-center gap-2">
            <span class="timer-chip" id="quiz-timer">
              <i class="bi bi-clock-history text-brand"></i><span>00:00</span>
            </span>
          </div>
        </div>
      </div>

      <!-- Progress bar -->
      <div class="card p-3 px-md-4 py-md-3 mb-4">
        <div class="d-flex justify-content-between align-items-center small text-muted-ink mb-2">
          <span class="fw-semibold text-ink-700" id="question-number">Question 1 of N</span>
          <span class="fw-semibold text-brand"><i class="bi bi-check2-circle me-1"></i>Assessment Progress</span>
        </div>
        <div class="progress" role="progressbar" aria-label="Quiz progress" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="height: 8px;">
          <div class="progress-bar" id="quiz-progress" style="width:0%"></div>
        </div>
      </div>

      <!-- Loader -->
      <div class="card p-5 text-center" id="quiz-loading">
        <div class="spinner-border text-brand mb-3" role="status"></div>
        <h6 class="fw-semibold text-ink-800 mb-1">Loading your quiz</h6>
        <p class="text-muted-ink small mb-0">Preparing questions and interactive options...</p>
      </div>

      <!-- Question card (hidden until loaded) -->
      <div class="card question-card p-4 p-md-5 d-none" id="quiz-body">
        <div class="d-flex align-items-center gap-2 text-brand small fw-semibold text-uppercase tracking-wider mb-2">
          <i class="bi bi-question-circle"></i> Multiple Choice Question
        </div>
        <h2 class="h4 fw-bold text-ink-900 mb-4 lh-base" id="question-text"></h2>
        <div class="d-flex flex-column gap-2" id="question-options"></div>
      </div>

      <!-- Error state -->
      <div class="card p-5 text-center d-none" id="quiz-error">
        <div class="stat-icon-wrap stat-icon-amber mx-auto mb-3" style="width: 3.5rem; height: 3.5rem; font-size: 1.6rem;">
          <i class="bi bi-exclamation-triangle text-danger"></i>
        </div>
        <h5 class="fw-bold text-ink-900 mb-1">Couldn't load this quiz</h5>
        <p class="text-muted-ink mb-4" id="quiz-error-message"></p>
        <div>
          <a href="<?php echo url('/pages/dashboard.php'); ?>" class="btn btn-outline-brand">
            <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
          </a>
        </div>
      </div>

      <!-- Navigation buttons -->
      <div class="d-flex justify-content-between align-items-center mt-4 d-none" id="quiz-nav">
        <button class="btn btn-outline-brand px-3 px-md-4" id="prev-btn">
          <i class="bi bi-arrow-left me-1"></i>Previous
        </button>
        <button class="btn btn-brand px-3 px-md-4" id="next-btn">
          Next<i class="bi bi-arrow-right ms-1"></i>
        </button>
        <button class="btn btn-brand px-4 d-none shadow-sm" id="submit-btn">
          <i class="bi bi-check-circle-fill me-2"></i>Submit Quiz
        </button>
      </div>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
