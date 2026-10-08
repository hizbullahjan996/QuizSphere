<?php
/**
 * QuizSphere - My Certificates
 * Authenticated. Lists the user's earned certificates. Certificates are
 * awarded automatically for scores of 80% or higher on a completed quiz.
 * Loaded via api/certificates.php.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

try {
    $client = SupabaseClient::fromConfig();
    $auth = new Auth($client);
} catch (SupabaseException $e) {
    $auth = null;
}

if (!$auth || !$auth->check()) {
    set_flash('error', 'Please log in to view your certificates.');
    redirect('/login.php');
}

$page_title = 'My Certificates';
$active_page = 'pages';
require __DIR__ . '/../includes/head.php';
?>
<body>
<div class="dashboard-shell">
  <?php $dashboard_active = 'certificates'; require __DIR__ . '/../includes/sidebar.php'; ?>

  <main class="dashboard-main" id="certificates-app">

    <div class="topbar">
      <div>
        <div class="d-inline-flex align-items-center gap-2 badge-soft text-brand mb-2">
          <i class="bi bi-award-fill"></i> Verified Credentials
        </div>
        <h1 class="h4 fw-bold text-ink-900 mb-1">My Certificates</h1>
        <p class="text-muted-ink mb-0">Official certificates of achievement awarded automatically for scores of 80% or higher.</p>
      </div>
      <a href="<?php echo url('/pages/generate.php'); ?>" class="btn btn-brand d-none d-sm-inline-flex">
        <i class="bi bi-plus-lg me-2"></i>Earn New Certificate
      </a>
    </div>

    <!-- Certificate Guidelines Card -->
    <div class="card p-4 mb-4" style="background: linear-gradient(135deg, var(--surface) 0%, var(--surface-2) 100%);">
      <div class="d-flex align-items-center gap-3">
        <div class="stat-icon-wrap stat-icon-indigo" style="width: 3.2rem; height: 3.2rem; font-size: 1.35rem;">
          <i class="bi bi-shield-check"></i>
        </div>
        <div class="flex-grow-1">
          <div class="fw-semibold text-ink-900">Cryptographically verifiable certificates</div>
          <div class="small text-muted-ink">Every certificate features a unique identifier and scannable QR code for public verification.</div>
        </div>
      </div>
    </div>

    <div class="card p-4">
      <div class="form-alert mt-0 mb-3" id="certificates-error" hidden></div>
      <div id="certificates-list">
        <div class="text-center text-muted-ink py-5">
          <div class="spinner-border spinner-border-sm text-brand me-2" role="status"></div>
          Loading your earned certificates...
        </div>
      </div>
    </div>

  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
