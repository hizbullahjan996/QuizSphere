<?php
/**
 * QuizSphere - Public certificate verification
 * -------------------------------------------------------------
 * Anyone with a certificate ID can verify it here. The QR code on a
 * certificate points to this page. Only public-safe fields are shown.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
$page_title  = 'Verify Credential · QuizSphere';
$active_page = 'verify';
$initialId   = (string) ($_GET['id'] ?? '');
require __DIR__ . '/includes/head.php';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700;800&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet">

<body>

<?php require __DIR__ . '/includes/navbar.php'; ?>

<section class="section py-5">
  <div class="container" style="max-width: 50rem;" id="verify-app">
    <div class="text-center mb-4">
      <div class="d-inline-flex align-items-center gap-2 badge-soft text-brand mb-2">
        <i class="bi bi-patch-check-fill"></i> Cryptographic Trust &amp; Ledger Verification
      </div>
      <h1 class="h2 fw-bold text-ink-900 mb-2">Verify a QuizSphere Certificate</h1>
      <p class="text-muted-ink mb-0">Scan a certificate's QR code or paste its 36-character identifier below to validate its authenticity on the official registry.</p>
    </div>

    <!-- Lookup form -->
    <div class="card p-3 p-md-4 mb-4 shadow-sm border-1">
      <form class="d-flex flex-column flex-sm-row gap-2" id="verify-form">
        <input type="text" id="verify-input" class="form-control form-control-lg font-monospace"
               placeholder="Enter Certificate UUID (e.g. 550e8400-e29b-41d4-a716-446655440000)" 
               value="<?php echo e($initialId); ?>" autocomplete="off" spellcheck="false">
        <button type="submit" class="btn btn-brand flex-shrink-0 px-4 py-2">
          <i class="bi bi-shield-check me-1"></i>Verify Credential
        </button>
      </form>
    </div>

    <!-- Loading -->
    <div class="text-center py-5 d-none" id="verify-loading">
      <div class="spinner-border text-brand mb-3" role="status"></div>
      <p class="text-muted-ink fw-semibold mb-0">Verifying credential on QuizSphere ledger...</p>
      <div class="small text-muted-ink mt-1">Cross-checking cryptographic authenticity and issuer signature</div>
    </div>

    <!-- Error -->
    <div class="alert alert-danger d-none mb-4" id="verify-error" role="alert"></div>

    <!-- Result -->
    <div class="card p-4 p-md-5 text-center d-none shadow-md border-0" id="verify-result" style="background: linear-gradient(180deg, #ffffff 0%, #fcfbf8 100%); border-top: 4px solid #c5a059 !important;">
      
      <div class="d-inline-flex align-items-center justify-content-center p-3 rounded-circle mb-3 mx-auto" style="background: rgba(16, 185, 129, 0.1); width: 68px; height: 68px;">
        <i class="bi bi-patch-check-fill text-success fs-1"></i>
      </div>

      <div class="badge-soft text-success fw-bold text-uppercase tracking-wider mb-2 d-inline-block px-3 py-1">
        <i class="bi bi-shield-lock-fill me-1"></i> Official Verified Credential
      </div>

      <h2 class="h3 fw-bold text-ink-900 mb-1" style="font-family: 'Cinzel', serif; letter-spacing: 0.05em;">Certificate Authenticated</h2>
      <p class="text-muted-ink mb-4 small">This official credential was conferred by the QuizSphere AI Assessment Directorate.</p>

      <div class="card p-4 text-start border-1 shadow-xs" style="background: #ffffff; border-color: rgba(197, 160, 89, 0.35);">
        <div class="row g-3">
          <div class="col-sm-6">
            <div class="small text-muted-ink fw-semibold text-uppercase tracking-wider">Recipient Name</div>
            <div class="fw-bold text-ink-900 fs-5" id="v-name" style="font-family: 'Cinzel', serif;">—</div>
          </div>
          <div class="col-sm-6">
            <div class="small text-muted-ink fw-semibold text-uppercase tracking-wider">Score Achieved</div>
            <div class="fw-bold text-success fs-5" id="v-score">—</div>
          </div>
          <div class="col-sm-6">
            <div class="small text-muted-ink fw-semibold text-uppercase tracking-wider">Curriculum / Quiz Topic</div>
            <div class="fw-bold text-ink-800" id="v-achievement">—</div>
          </div>
          <div class="col-sm-6">
            <div class="small text-muted-ink fw-semibold text-uppercase tracking-wider">Date Conferred</div>
            <div class="fw-semibold text-ink-800" id="v-date">—</div>
          </div>
          <div class="col-12 border-top pt-3 mt-2">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
              <div>
                <div class="small text-muted-ink">Unique Certificate Identifier:</div>
                <code class="text-brand fw-semibold small" id="v-id">—</code>
              </div>
              <a href="#" class="btn btn-sm btn-outline-brand d-inline-flex align-items-center" id="v-view-cert">
                <i class="bi bi-award me-1"></i>View Official Certificate
              </a>
            </div>
          </div>
        </div>
      </div>

      <div class="mt-4 pt-2">
        <div class="small text-muted-ink">
          <i class="bi bi-info-circle me-1"></i>Issued by QuizSphere Inc. · Verified via SHA-256 Ledger Record
        </div>
      </div>

    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
