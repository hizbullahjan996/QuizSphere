<?php
/**
 * QuizSphere - Leaderboard
 * Authenticated. Shows top learners (rank, display name, XP, level, quizzes
 * completed) plus the current user's own rank. Loaded via api/leaderboard.php.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

try {
    $client = SupabaseClient::fromConfig();
    $auth = new Auth($client);
} catch (SupabaseException $e) {
    $auth = null;
}

if (!$auth || !$auth->check()) {
    set_flash('error', 'Please log in to view the leaderboard.');
    redirect('/login.php');
}

$page_title = 'Leaderboard';
$active_page = 'pages';
require __DIR__ . '/../includes/head.php';
?>
<body>
<div class="dashboard-shell">
  <?php $dashboard_active = 'leaderboard'; require __DIR__ . '/../includes/sidebar.php'; ?>

  <main class="dashboard-main" id="leaderboard-app">

    <div class="topbar">
      <div>
        <h1 class="h4 mb-1"><i class="bi bi-trophy me-2"></i>Leaderboard</h1>
        <p class="text-muted-ink mb-0">Top learners by XP — stay consistent to climb the ranks.</p>
      </div>
      <a href="<?php echo url('/pages/generate.php'); ?>" class="btn btn-brand d-none d-sm-inline-flex"><i class="bi bi-plus-lg me-2"></i>Earn XP</a>
    </div>

    <!-- My rank -->
    <div class="card p-4 mb-4" id="leaderboard-me">
      <div class="d-flex align-items-center gap-3">
        <div class="icon-badge badge-podium"><i class="bi bi-person"></i></div>
        <div class="flex-grow-1">
          <div class="fw-semibold">Your rank</div>
          <div class="small text-muted-ink">Loading your position...</div>
        </div>
      </div>
    </div>

    <!-- Top list -->
    <div class="card p-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Top Learners</h5>
        <span class="badge-soft">By XP</span>
      </div>
      <div class="form-alert mt-3" id="leaderboard-error" hidden></div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead>
            <tr class="text-muted-ink small">
              <th style="width:70px;">Rank</th>
              <th>Student</th>
              <th class="text-end">Level</th>
              <th class="text-end">Quizzes</th>
              <th class="text-end">XP</th>
            </tr>
          </thead>
          <tbody id="leaderboard-body">
            <tr><td colspan="5" class="text-center text-muted-ink py-4"><i class="bi bi-hourglass-split me-1"></i>Loading leaderboard...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
