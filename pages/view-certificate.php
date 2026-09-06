<?php
/**
 * QuizSphere - View Certificate
 * Authenticated. Displays a single earned certificate with its unique ID,
 * QR verification code, and a "Download PDF" button (jsPDF + QRCode.js).
 */
require_once __DIR__ . '/../includes/bootstrap.php';

try {
    $client = SupabaseClient::fromConfig();
    $auth = new Auth($client);
} catch (SupabaseException $e) {
    $auth = null;
}

if (!$auth || !$auth->check()) {
    set_flash('error', 'Please log in to view this certificate.');
    redirect('/login.php');
}

$certId = (string) ($_GET['id'] ?? '');
if (!preg_match('/^[0-9a-fA-F-]{36}$/', $certId)) {
    set_flash('error', 'Invalid certificate.');
    redirect('/pages/certificates.php');
}

$page_title = 'View Certificate';
$active_page = 'pages';
require __DIR__ . '/../includes/head.php';
?>
<body>
<div class="dashboard-shell">
  <?php $dashboard_active = 'certificates'; require __DIR__ . '/../includes/sidebar.php'; ?>

  <main class="dashboard-main" id="certificate-view" data-cert-id="<?php echo e($certId); ?>">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
      <div>
        <h1 class="h4 mb-1"><i class="bi bi-award me-2"></i>Certificate</h1>
        <p class="text-muted-ink mb-0">Your official QuizSphere certificate.</p>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-brand" id="cert-download"><i class="bi bi-download me-2"></i>Download PDF</button>
        <a href="<?php echo url('/pages/certificates.php'); ?>" class="btn btn-outline-brand">All Certificates</a>
      </div>
    </div>

    <div class="form-alert mt-0 mb-4" id="cert-error" hidden></div>

    <!-- Loading / rendered certificate -->
    <div id="cert-loading" class="text-center text-muted-ink py-5">
      <div class="spinner-border text-brand mb-3" role="status"></div>
      <div>Loading certificate...</div>
    </div>

    <div id="cert-panel" class="d-none">
      <div class="card p-4 p-md-5 overflow-hidden">
        <!-- Certificate display -->
        <div class="certificate-sheet text-center">
          <div class="cert-brand"><img class="brand-logo-cert" src="<?php echo url('/assets/images/logo.png'); ?>" alt="QuizSphere logo"> </div>
          <div class="cert-title mt-2">Certificate of Achievement</div>
          <div class="cert-sub mt-1">This certifies that</div>
          <div class="cert-name" id="cert-name">—</div>
          <p class="text-muted-ink mt-2 mb-1">has successfully completed</p>
          <div class="cert-accomplishment" id="cert-quiz">—</div>
          <div class="cert-details mt-3">
            <span>Score: <strong id="cert-score">—</strong></span>
            <span>Date: <strong id="cert-date">—</strong></span>
          </div>
          <div class="cert-id">Certificate ID: <strong id="cert-id">—</strong></div>

          <div class="d-flex justify-content-center mt-4">
            <div class="cert-qr">
              <div id="cert-qr"></div>
              <div class="small text-muted-ink mt-1">Scan to verify</div>
            </div>
          </div>
        </div>
      </div>
    </div>

  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
