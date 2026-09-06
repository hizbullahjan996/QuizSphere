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
    <div class="coach-shell" id="coach-app" style="max-width:56rem; margin:0 auto;">

      <div class="mb-4 text-center">
        <h1 class="h3 mb-1"><i class="bi bi-stars me-2"></i>AI Learning Coach</h1>
        <p class="text-muted-ink mb-0">
          Your personal study guide — grounded in your real quiz performance.
        </p>
      </div>

      <!-- Suggested prompts -->
      <div class="d-flex flex-wrap gap-2 justify-content-center mb-4" id="coach-suggestions">
        <button type="button" class="btn btn-sm btn-outline-brand" data-suggestion="What should I study today?">What should I study today?</button>
        <button type="button" class="btn btn-sm btn-outline-brand" data-suggestion="Why am I weak in SQL joins?">Why am I weak in SQL joins?</button>
        <button type="button" class="btn btn-sm btn-outline-brand" data-suggestion="Create a practice plan for me.">Create a practice plan for me.</button>
        <button type="button" class="btn btn-sm btn-outline-brand" data-suggestion="Explain my recent performance.">Explain my recent performance.</button>
      </div>

      <!-- Messages -->
      <div class="card p-3 p-md-4 mb-3" id="coach-messages">
        <div class="d-flex align-items-start gap-2 coach-msg coach-msg-coach" data-msg>
          <div class="coach-avatar"><i class="bi bi-stars"></i></div>
          <div class="coach-bubble">
            Hi! I'm your AI Learning Coach. Ask me what to study, which concepts
            need work, or to explain something you found difficult — I'll tailor
            my advice to your actual progress.
          </div>
        </div>
      </div>

      <!-- Input -->
      <form id="coach-form" class="d-flex gap-2">
        <input type="text" id="coach-input" class="form-control" maxlength="1000"
               placeholder="Ask your coach something..." autocomplete="off">
        <button type="submit" class="btn btn-brand flex-shrink-0" id="coach-send">
          <i class="bi bi-send me-1"></i>Send
        </button>
      </form>
      <p class="small text-muted-ink mt-2 mb-0"
         id="coach-note"></p>

    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
