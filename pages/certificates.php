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
        <h1 class="h4 mb-1"><i class="bi bi-award me-2"></i>My Certificates</h1>
        <p class="text-muted-ink mb-0">Awards for scores of 80% or higher on a completed quiz.</p>
      </div>
    </div>

    <div class="card p-4">
      <div class="form-alert mt-0 mb-3" id="certificates-error" hidden></div>
      <div id="certificates-list">
        <div class="text-center text-muted-ink py-4"><i class="bi bi-hourglass-split me-1"></i>Loading your certificates...</div>
      </div>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
