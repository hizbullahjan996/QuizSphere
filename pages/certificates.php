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

    <div class="row g-4 mb-4">
      <div class="col-12">
        <div class="d-inline-flex align-items-center gap-2 bg-soft-brand text-brand mb-3 px-3 py-1 rounded-pill fw-bold small text-uppercase tracking-wide border border-brand-100">
          <i class="bi bi-award-fill"></i> Verified Credentials
        </div>
        <h1 class="h3 fw-bold text-ink-900 mb-2" style="letter-spacing: -0.02em;">My Certificates</h1>
        <p class="text-muted-ink mb-0 fs-5" style="max-width: 600px;">
          Celebrate your learning achievements. Certificates are awarded automatically for scoring 80% or higher.
        </p>
      </div>
    </div>

    <!-- Stats Row -->
    <div class="row g-3 g-xl-4 mb-4" id="cert-stats-container" style="display: none;">
      <div class="col-sm-6 col-lg-4">
        <div class="card bg-surface border-1 border-ink-200 shadow-sm rounded-4 p-4 h-100 d-flex flex-row align-items-center gap-3">
          <div class="avatar-circle avatar-circle-md bg-brand-100 text-brand flex-shrink-0 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; border-radius: 50%;">
            <i class="bi bi-trophy-fill fs-4"></i>
          </div>
          <div>
            <div class="small fw-semibold text-muted-ink text-uppercase tracking-wider mb-1" style="font-size: 0.75rem;">Total Certificates</div>
            <div class="h3 fw-bold text-ink-900 mb-0" id="stat-total-certs">0</div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-4">
        <div class="card bg-surface border-1 border-ink-200 shadow-sm rounded-4 p-4 h-100 d-flex flex-row align-items-center gap-3">
          <div class="avatar-circle avatar-circle-md bg-success-subtle text-success flex-shrink-0 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; border-radius: 50%;">
            <i class="bi bi-graph-up-arrow fs-4"></i>
          </div>
          <div>
            <div class="small fw-semibold text-muted-ink text-uppercase tracking-wider mb-1" style="font-size: 0.75rem;">Highest Score</div>
            <div class="h3 fw-bold text-ink-900 mb-0" id="stat-highest-score">0%</div>
          </div>
        </div>
      </div>
      <div class="col-sm-12 col-lg-4 d-flex">
        <div class="card bg-surface border-1 border-ink-200 shadow-sm rounded-4 p-4 h-100 w-100 d-flex justify-content-center flex-column position-relative overflow-hidden" style="background: linear-gradient(135deg, var(--surface) 0%, var(--surface-2) 100%);">
          <!-- Decorative bg -->
          <div class="position-absolute end-0 bottom-0 text-brand" style="opacity: 0.05; transform: translate(20%, 20%); font-size: 8rem; line-height: 1;">
            <i class="bi bi-shield-check"></i>
          </div>
          <div class="position-relative z-1">
            <h6 class="fw-bold text-ink-900 mb-1 d-flex align-items-center gap-2">
              <i class="bi bi-shield-lock-fill text-brand"></i> Verifiable
            </h6>
            <p class="small text-muted-ink mb-0 lh-sm">
              Each certificate features a unique QR code for instant public verification.
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Certificates Grid -->
    <div class="form-alert mt-0 mb-4" id="certificates-error" hidden></div>
    
    <div id="certificates-list">
      <div class="text-center text-muted-ink py-5 bg-surface-2 rounded-4 border border-ink-200">
        <div class="spinner-border spinner-border-sm text-brand me-2" role="status"></div>
        Loading your achievements...
      </div>
    </div>

  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
