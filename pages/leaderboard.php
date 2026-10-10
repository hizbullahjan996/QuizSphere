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

    <div class="row g-4 mb-4">
      <div class="col-12">
        <div class="d-inline-flex align-items-center gap-2 bg-warning-soft text-warning-ink mb-3 px-3 py-1 rounded-pill fw-bold small text-uppercase tracking-wide border border-warning-subtle">
          <i class="bi bi-trophy-fill"></i> Global Rankings
        </div>
        <h1 class="h3 fw-bold text-ink-900 mb-2" style="letter-spacing: -0.02em;">Leaderboard</h1>
        <p class="text-muted-ink mb-0 fs-5" style="max-width: 600px;">
          Track your progress, earn experience points (XP), and see how you rank among other learners.
        </p>
      </div>
    </div>

    <!-- Overview Stats -->
    <div class="row g-3 g-xl-4 mb-5" id="leaderboard-stats-container" style="display: none;">
      <div class="col-sm-6 col-lg-3">
        <div class="card bg-surface border-1 border-ink-200 shadow-sm rounded-4 p-4 h-100 text-center transition-normal hover-bg-brand-50">
          <div class="small fw-semibold text-muted-ink text-uppercase tracking-wider mb-2" style="font-size: 0.75rem;">Your Rank</div>
          <div class="h2 fw-bold text-brand mb-0" id="stat-my-rank">--</div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card bg-surface border-1 border-ink-200 shadow-sm rounded-4 p-4 h-100 text-center transition-normal hover-bg-brand-50">
          <div class="small fw-semibold text-muted-ink text-uppercase tracking-wider mb-2" style="font-size: 0.75rem;">Your XP</div>
          <div class="h2 fw-bold text-ink-900 mb-0" id="stat-my-xp">0</div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card bg-surface border-1 border-ink-200 shadow-sm rounded-4 p-4 h-100 text-center transition-normal hover-bg-brand-50">
          <div class="small fw-semibold text-muted-ink text-uppercase tracking-wider mb-2" style="font-size: 0.75rem;">Total Participants</div>
          <div class="h2 fw-bold text-ink-900 mb-0" id="stat-total-users">0</div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card bg-surface border-1 border-ink-200 shadow-sm rounded-4 p-4 h-100 text-center transition-normal hover-bg-brand-50">
          <div class="small fw-semibold text-muted-ink text-uppercase tracking-wider mb-2" style="font-size: 0.75rem;">Highest XP</div>
          <div class="h2 fw-bold text-ink-900 mb-0" id="stat-top-xp">0</div>
        </div>
      </div>
    </div>

    <!-- Podium Section -->
    <div class="mb-5" id="podium-container" style="display: none;">
      <div class="text-center mb-4">
        <h4 class="fw-bold text-ink-900"><i class="bi bi-stars text-warning me-2"></i>Top Performers</h4>
      </div>
      <div class="row g-3 justify-content-center align-items-end" id="podium-cards" style="min-height: 220px;">
        <!-- Populated via JS -->
      </div>
    </div>

    <!-- Rankings Table -->
    <div class="card p-0 border-1 border-ink-200 shadow-sm bg-surface rounded-4 overflow-hidden mb-4">
      <div class="p-4 border-bottom border-ink-100 d-flex justify-content-between align-items-center bg-surface-2">
        <h5 class="mb-0 fw-bold text-ink-900">All-Time Standings</h5>
      </div>
      
      <div class="form-alert m-3" id="leaderboard-error" hidden></div>

      <div class="table-responsive">
        <table class="table align-middle mb-0 table-hover">
          <thead class="bg-surface">
            <tr class="text-muted-ink small text-uppercase tracking-wider" style="font-size: 0.75rem;">
              <th class="ps-4 fw-semibold" style="width: 80px;">Rank</th>
              <th class="fw-semibold">Learner</th>
              <th class="text-center fw-semibold">Level</th>
              <th class="text-center fw-semibold">Quizzes</th>
              <th class="text-end pe-4 fw-semibold">Total XP</th>
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
