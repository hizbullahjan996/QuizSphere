<?php
/**
 * QuizSphere - Public certificate verification
 * -------------------------------------------------------------
 * Anyone with a certificate ID can verify it here. The QR code on a
 * certificate points to this page. Only public-safe fields are shown.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
$page_title  = 'Verify Certificate';
$active_page = 'verify';
require __DIR__ . '/includes/head.php';
?>
<body>

<?php require __DIR__ . '/includes/navbar.php'; ?>

<section class="section py-5">
  <div class="container" style="max-width: 46rem;" id="verify-app">
    <div class="text-center mb-4">
      <span class="section-label mb-2"><i class="bi bi-patch-check"></i> Certificate Verification</span>
      <h1 class="h2 mb-2">Verify a Certificate</h1>
      <p class="text-muted-ink mb-0">Enter a certificate ID to confirm its authenticity.</p>
    </div>

    <!-- Lookup form -->
    <form class="d-flex gap-2 mb-4" id="verify-form">
      <input type="text" id="verify-input" class="form-control form-control-lg"
             placeholder="Paste certificate ID" autocomplete="off">
      <button type="submit" class="btn btn-brand flex-shrink-0 px-4">Verify</button>
    </form>

    <!-- Loading -->
    <div class="text-center py-4 d-none" id="verify-loading">
      <div class="spinner-border text-brand" role="status"></div>
    </div>

    <!-- Error -->
    <div class="alert alert-danger d-none" id="verify-error" role="alert"></div>

    <!-- Result -->
    <div class="card p-4 p-md-5 text-center d-none" id="verify-result">
      <div class="icon-badge badge-verified mb-3"><i class="bi bi-patch-check-fill"></i></div>
      <h2 class="h3 mb-2">Certificate Verified</h2>
      <p class="text-muted-ink mb-4">This certificate is genuine and issued by QuizSphere.</p>

      <div class="row text-start g-3">
        <div class="col-sm-6">
          <div class="small text-muted-ink">Certificate ID</div>
          <div class="fw-semibold" id="v-id">—</div>
        </div>
        <div class="col-sm-6">
          <div class="small text-muted-ink">Student</div>
          <div class="fw-semibold" id="v-name">—</div>
        </div>
        <div class="col-sm-6">
          <div class="small text-muted-ink">Achievement</div>
          <div class="fw-semibold" id="v-achievement">—</div>
        </div>
        <div class="col-sm-6">
          <div class="small text-muted-ink">Score</div>
          <div class="fw-semibold" id="v-score">—</div>
        </div>
        <div class="col-12">
          <div class="small text-muted-ink">Issue Date</div>
          <div class="fw-semibold" id="v-date">—</div>
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
