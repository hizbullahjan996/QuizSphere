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
        <div class="d-inline-flex align-items-center gap-2 badge-soft text-warning mb-2">
          <i class="bi bi-trophy-fill"></i> Global Rankings
        </div>
        <h1 class="h4 fw-bold text-ink-900 mb-1">Learner Leaderboard</h1>
        <p class="text-muted-ink mb-0">Celebrate top performers by total XP. Complete quizzes and maintain streaks to ascend.</p>
      </div>
      <a href="<?php echo url('/pages/generate.php'); ?>" class="btn btn-brand d-none d-sm-inline-flex">
        <i class="bi bi-plus-lg me-2"></i>Earn XP Now
      </a>
    </div>

    <!-- Current User's Rank Card -->
    <div class="card p-4 mb-4 border-brand-subtle" id="leaderboard-me" style="background: linear-gradient(135deg, var(--surface) 0%, var(--brand-50) 100%);">
      <div class="d-flex align-items-center gap-3">
        <div class="icon-badge badge-podium" style="width: 3.2rem; height: 3.2rem; font-size: 1.35rem;">
          <i class="bi bi-person-fill"></i>
        </div>
        <div class="flex-grow-1">
          <div class="fw-semibold text-ink-900 fs-5">Your rank</div>
          <div class="small text-muted-ink">Loading your ranking position...</div>
        </div>
        <div class="d-none d-md-block text-end">
          <span class="badge-soft text-brand fw-semibold">Real-time Standing</span>
        </div>
      </div>
    </div>

    <!-- Top Learners Table Card -->
    <div class="card p-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <h5 class="mb-1 fw-bold text-ink-900"><i class="bi bi-stars text-warning me-2"></i>Top Learners</h5>
          <p class="text-muted-ink small mb-0">Ranked by total earned experience points (XP)</p>
        </div>
        <span class="badge-soft">All-Time XP</span>
      </div>

      <div class="form-alert mt-3" id="leaderboard-error" hidden></div>

      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead>
            <tr class="text-muted-ink small">
              <th style="width: 80px;">Rank</th>
              <th>Student</th>
              <th class="text-end">Level</th>
              <th class="text-end">Quizzes</th>
              <th class="text-end">Total XP</th>
            </tr>
          </thead>
          <tbody id="leaderboard-body">
            <tr>
              <td colspan="5" class="text-center text-muted-ink py-5">
                <div class="spinner-border spinner-border-sm text-brand me-2" role="status"></div>
                Loading leaderboard rankings...
              </td>
            </tr>
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
