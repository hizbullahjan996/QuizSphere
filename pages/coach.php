<?php
/**
 * QuizSphere - AI Learning Coach
 * -------------------------------------------------------------
 * Authenticated. A chat-style interface that gives personalized, data-grounded
 * study guidance using the student's real performance data (via api/coach.php).
 */
require_once __DIR__ . '/../includes/bootstrap.php';

try {
    $client = SupabaseClient::fromConfig();
    $auth = new Auth($client);
} catch (SupabaseException $e) {
    $auth = null;
}

if (!$auth || !$auth->check()) {
    set_flash('error', 'Please log in to use the AI Learning Coach.');
    redirect('/login.php');
}

$user = $auth->user();
$page_title = 'AI Learning Coach';
$active_page = 'pages';
require __DIR__ . '/../includes/head.php';
?>
<body>
<div class="dashboard-shell">
  <?php $dashboard_active = 'coach'; require __DIR__ . '/../includes/sidebar.php'; ?>

  <main class="dashboard-main">
    <div class="coach-shell" id="coach-app" style="max-width: 54rem; margin: 0 auto;">

      <!-- Header & Intro -->
      <div class="card p-4 p-md-5 mb-4 text-center">
        <div class="d-inline-flex align-items-center justify-content-center mx-auto mb-3 stat-icon-wrap stat-icon-indigo" style="width: 3.5rem; height: 3.5rem; font-size: 1.6rem;">
          <i class="bi bi-robot"></i>
        </div>
        <div class="d-inline-flex align-items-center gap-2 badge-soft text-brand mx-auto mb-2">
          <i class="bi bi-stars"></i> Intelligent Tutor
        </div>
        <h1 class="h3 fw-bold text-ink-900 mb-2">AI Learning Coach</h1>
        <p class="text-muted-ink mb-0 mx-auto" style="max-width: 36rem;">
          Your personal study mentor. Grounded in your real quiz attempts and concept mastery, the coach helps you review mistakes and master weak areas.
        </p>
      </div>

      <!-- Quick Prompt Suggestions -->
      <div class="mb-3">
        <div class="small fw-semibold text-muted-ink text-uppercase tracking-wider mb-2 text-center">
          <i class="bi bi-lightbulb me-1 text-warning"></i>Suggested Prompts
        </div>
        <div class="d-flex flex-wrap gap-2 justify-content-center" id="coach-suggestions">
          <button type="button" class="btn btn-sm btn-outline-brand" data-suggestion="What should I study today?">
            <i class="bi bi-compass me-1"></i>What should I study today?
          </button>
          <button type="button" class="btn btn-sm btn-outline-brand" data-suggestion="Which topics are my weakest?">
            <i class="bi bi-exclamation-circle me-1"></i>Which topics are my weakest?
          </button>
          <button type="button" class="btn btn-sm btn-outline-brand" data-suggestion="Create a 3-step practice plan for me.">
            <i class="bi bi-list-check me-1"></i>Create a 3-step practice plan
          </button>
          <button type="button" class="btn btn-sm btn-outline-brand" data-suggestion="Explain my recent performance.">
            <i class="bi bi-graph-up me-1"></i>Explain my recent performance
          </button>
        </div>
      </div>

      <!-- Messages Stream -->
      <div class="card p-3 p-md-4 mb-3" id="coach-messages">
        <div class="d-flex align-items-start gap-2 coach-msg coach-msg-coach" data-msg>
          <div class="coach-avatar"><i class="bi bi-stars"></i></div>
          <div class="coach-bubble">
            Hi! I'm your AI Learning Coach. Ask me what to study next, which concepts need work, or how to master tricky topics — I'll tailor my guidance directly to your quiz results.
          </div>
        </div>
      </div>

      <!-- Input Form -->
      <div class="card p-2 p-md-3">
        <form id="coach-form" class="d-flex gap-2">
          <input type="text" id="coach-input" class="form-control form-control-lg border-0 shadow-none px-3" maxlength="1000"
                 placeholder="Ask your coach anything about your learning progress..." autocomplete="off">
          <button type="submit" class="btn btn-brand flex-shrink-0 px-4" id="coach-send">
            <i class="bi bi-send-fill me-1"></i>Send
          </button>
        </form>
      </div>

      <div class="d-flex align-items-center justify-content-center gap-2 mt-2">
        <i class="bi bi-shield-check text-success small"></i>
        <p class="small text-muted-ink mb-0 text-center" id="coach-note">
          Advice is grounded strictly in your quiz performance — the coach never invents facts.
        </p>
      </div>

    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
