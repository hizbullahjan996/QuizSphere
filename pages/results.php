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

$page_title = 'Results';
$active_page = 'pages';
require __DIR__ . '/../includes/head.php';
?>
<body>
<div class="dashboard-shell">
  <?php $dashboard_active = 'quizzes'; require __DIR__ . '/../includes/sidebar.php'; ?>

  <main class="dashboard-main">
    <div id="results-app" style="max-width:52rem; margin:0 auto;" data-attempt-id="<?php echo e($attemptId); ?>">

      <!-- Loader -->
      <div class="text-center py-5" id="results-loading">
        <div class="spinner-border text-brand mb-3" role="status"></div>
        <p class="text-muted-ink mb-0">Loading your results...</p>
      </div>

      <!-- Error state -->
      <div class="text-center py-5 d-none" id="results-error">
        <i class="bi bi-exclamation-triangle text-danger" style="font-size:2rem;"></i>
        <h5 class="mt-3 mb-1">Couldn't load your results</h5>
        <p class="text-muted-ink mb-3" id="results-error-message"></p>
        <a href="<?php echo url('/pages/dashboard.php'); ?>" class="btn btn-outline-brand">Back to Dashboard</a>
      </div>

      <!-- Results content (hidden until loaded) -->
      <div id="results-content" class="d-none">

        <!-- Rewards banner (XP / badges / certificate), shown after grading -->
        <div id="rewards-banner" class="d-none p-3 mb-4 rounded bg-success-subtle d-flex flex-wrap align-items-center gap-2"></div>

        <div class="text-center mb-4">
          <a href="<?php echo url('/pages/dashboard.php'); ?>" class="text-muted-ink small mb-2 d-inline-block">
            <i class="bi bi-arrow-left me-1"></i>Back to dashboard
          </a>
          <h1 class="h3 mb-1">Quiz Results</h1>
          <p class="text-muted-ink mb-0" id="result-subtitle">…</p>
        </div>

        <!-- Score card -->
        <div class="card p-4 p-md-5 mb-4">
          <div class="row align-items-center g-4">
            <div class="col-md-4 text-center">
              <div class="result-score-ring" id="score-ring" style="--p:0%">
                <div class="inner">
                  <span class="display-6 fw-bold" id="result-score-pct">0%</span>
                  <span class="small text-muted-ink">Score</span>
                </div>
              </div>
            </div>
            <div class="col-md-8">
              <h4 id="result-message" class="mb-3">…</h4>
              <div class="row g-2 text-center">
                <div class="col-4">
                  <div class="stat-tile p-3">
                    <div class="stat-label">Correct</div>
                    <div class="stat-value text-success" id="result-correct">0</div>
                  </div>
                </div>
                <div class="col-4">
                  <div class="stat-tile p-3">
                    <div class="stat-label">Incorrect</div>
                    <div class="stat-value text-danger" id="result-incorrect">0</div>
                  </div>
                </div>
                <div class="col-4">
                  <div class="stat-tile p-3">
                    <div class="stat-label">Total</div>
                    <div class="stat-value" id="result-total">0</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Question review -->
        <h4 class="mb-3"><i class="bi bi-chat-left-quote me-2"></i>Question Review & Explanations</h4>
        <div id="review-list"></div>

        <!-- Actions -->
        <div class="d-flex flex-wrap justify-content-center gap-3 mt-4">
          <a href="<?php echo url('/pages/generate.php'); ?>" class="btn btn-brand btn-lg px-4">
            <i class="bi bi-magic me-2"></i>Generate Another Quiz
          </a>
        </div>
      </div>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
