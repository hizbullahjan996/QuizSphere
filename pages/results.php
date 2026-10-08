<?php
/**
 * QuizSphere - Quiz results page (real attempt)
 * -------------------------------------------------------------
 * Authenticated. Loads a saved attempt via api/attempt.php using the
 * ?attempt= parameter and renders the score, summary and full question-by-
 * question review with explanations.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

try {
    $client = SupabaseClient::fromConfig();
    $auth = new Auth($client);
} catch (SupabaseException $e) {
    $auth = null;
}

if (!$auth || !$auth->check()) {
    set_flash('error', 'Please log in to view results.');
    redirect('/login.php');
}

$attemptId = (string) ($_GET['attempt'] ?? '');
if ($attemptId === '' || !preg_match('/^[0-9a-fA-F-]{36}$/', $attemptId)) {
    set_flash('error', 'No results to show.');
    redirect('/pages/dashboard.php');
}

$page_title = 'Quiz Results';
$active_page = 'pages';
require __DIR__ . '/../includes/head.php';
?>
<body>
<div class="dashboard-shell">
  <?php $dashboard_active = 'quizzes'; require __DIR__ . '/../includes/sidebar.php'; ?>

  <main class="dashboard-main">
    <div id="results-app" style="max-width: 52rem; margin: 0 auto;" data-attempt-id="<?php echo e($attemptId); ?>">

      <!-- Loader -->
      <div class="card p-5 text-center my-4" id="results-loading">
        <div class="spinner-border text-brand mb-3" role="status"></div>
        <h5 class="fw-semibold text-ink-900 mb-1">Grading Assessment</h5>
        <p class="text-muted-ink small mb-0">Calculating score, gamification rewards, and AI feedback...</p>
      </div>

      <!-- Error state -->
      <div class="card p-5 text-center d-none my-4" id="results-error">
        <div class="stat-icon-wrap stat-icon-amber mx-auto mb-3" style="width: 3.5rem; height: 3.5rem; font-size: 1.6rem;">
          <i class="bi bi-exclamation-triangle text-danger"></i>
        </div>
        <h5 class="fw-bold text-ink-900 mb-1">Couldn't load your results</h5>
        <p class="text-muted-ink mb-3" id="results-error-message"></p>
        <div>
          <a href="<?php echo url('/pages/dashboard.php'); ?>" class="btn btn-outline-brand">
            <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
          </a>
        </div>
      </div>

      <!-- Results content (hidden until loaded) -->
      <div id="results-content" class="d-none">

        <!-- Back navigation & header -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
          <a href="<?php echo url('/pages/dashboard.php'); ?>" class="btn btn-sm btn-outline-brand">
            <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
          </a>
          <span class="text-muted-ink small" id="result-subtitle">…</span>
        </div>

        <!-- Rewards banner (XP / badges / certificate), populated via JS -->
        <div id="rewards-banner" class="d-none p-3 mb-4 rounded bg-success-subtle d-flex flex-wrap align-items-center gap-2"></div>

        <!-- Score summary card -->
        <div class="card p-4 p-md-5 mb-4">
          <div class="row align-items-center g-4">
            <div class="col-md-5 text-center">
              <div class="result-score-ring" id="score-ring" style="--p:0%">
                <div class="inner">
                  <span class="display-6 fw-bold text-ink-900" id="result-score-pct">0%</span>
                  <span class="small text-muted-ink fw-semibold text-uppercase tracking-wider">Score</span>
                </div>
              </div>
            </div>
            <div class="col-md-7">
              <div class="badge-soft text-brand mb-2"><i class="bi bi-award me-1"></i>Assessment Completed</div>
              <h3 id="result-message" class="fw-bold text-ink-900 mb-3">…</h3>
              
              <div class="row g-2 text-center mt-2">
                <div class="col-4">
                  <div class="stat-tile p-3">
                    <div class="stat-label text-success">Correct</div>
                    <div class="stat-value text-success" id="result-correct">0</div>
                  </div>
                </div>
                <div class="col-4">
                  <div class="stat-tile p-3">
                    <div class="stat-label text-danger">Incorrect</div>
                    <div class="stat-value text-danger" id="result-incorrect">0</div>
                  </div>
                </div>
                <div class="col-4">
                  <div class="stat-tile p-3">
                    <div class="stat-label">Total</div>
                    <div class="stat-value text-ink-900" id="result-total">0</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Question review -->
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h4 class="h5 fw-bold mb-0 text-ink-900">
            <i class="bi bi-card-checklist text-brand me-2"></i>Question Review &amp; Explanations
          </h4>
          <span class="small text-muted-ink">Deep-dive into your answers</span>
        </div>
        <div id="review-list"></div>

        <!-- Action CTAs -->
        <div class="card p-4 text-center mt-4">
          <h5 class="fw-bold text-ink-900 mb-2">What's Next?</h5>
          <p class="text-muted-ink small mb-4">Keep your streak active or review tricky concepts with your personal AI coach.</p>
          <div class="d-flex flex-wrap justify-content-center gap-3">
            <a href="<?php echo url('/pages/generate.php'); ?>" class="btn btn-brand px-4">
              <i class="bi bi-magic me-2"></i>Generate Another Quiz
            </a>
            <a href="<?php echo url('/pages/coach.php'); ?>" class="btn btn-outline-brand px-4">
              <i class="bi bi-chat-dots me-2"></i>Ask AI Coach About Mistakes
            </a>
            <a href="<?php echo url('/pages/dashboard.php'); ?>" class="btn btn-ghost px-3">
              Dashboard
            </a>
          </div>
        </div>

      </div>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
