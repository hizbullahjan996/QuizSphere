<?php
/**
 * QuizSphere - Upload Study Material (PDF to AI Quiz)
 * -------------------------------------------------------------
 * Authenticated. Accepts a PDF, uploads it to Supabase Storage, extracts its
 * text server-side, and generates a quiz strictly from that material via
 * api/pdf_quiz.php.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

try {
    $client = SupabaseClient::fromConfig();
    $auth = new Auth($client);
} catch (SupabaseException $e) {
    $auth = null;
}

if (!$auth || !$auth->check()) {
    set_flash('error', 'Please log in to upload study material.');
    redirect('/login.php');
}

$user = $auth->user();
$page_title = 'Upload Study Material';
$active_page = 'pages';
require __DIR__ . '/../includes/head.php';
?>
<body>
<div class="dashboard-shell">
  <?php $dashboard_active = 'upload'; require __DIR__ . '/../includes/sidebar.php'; ?>

  <main class="dashboard-main">
    <div class="row justify-content-center">
      <div class="col-lg-7">

        <div class="mb-4">
          <h1 class="h3 mb-1"><i class="bi bi-file-earmark-richtext me-2"></i>Upload Study Material</h1>
          <p class="text-muted-ink mb-0">
            Upload a <strong>text-based PDF</strong> and QuizSphere will generate a quiz
            questions-only from its content.
          </p>
        </div>

        <div class="card p-4 p-md-5">
          <form id="upload-form" enctype="multipart/form-data" novalidate>

            <div class="mb-4">
              <label class="form-label d-block fw-semibold">Choose a PDF file</label>
              <div class="upload-drop" id="upload-drop">
                <i class="bi bi-cloud-arrow-up fs-1 text-brand"></i>
                <p class="mb-1 fw-semibold" id="upload-label">Click to select or drag &amp; drop</p>
                <p class="small text-muted-ink mb-0" id="upload-name"></p>
                <input type="file" id="pdf_file" name="pdf" accept="application/pdf" class="d-none">
              </div>
              <input type="hidden" name="_csrf" value="<?php echo e(Security::csrfToken()); ?>">
              <div class="form-text">Maximum size 10 MB. Scanned/image PDFs (no selectable text) are not supported.</div>
            </div>

            <div class="row g-3 mb-4">
              <div class="col-md-5">
                <label for="difficulty" class="form-label">Difficulty</label>
                <select class="form-select" id="difficulty" name="difficulty">
                  <option value="easy">Easy</option>
                  <option value="medium" selected>Medium</option>
                  <option value="hard">Hard</option>
                </select>
              </div>
              <div class="col-md-4">
                <label for="question_count" class="form-label">Number of Questions</label>
                <select class="form-select" id="question_count" name="question_count">
                  <?php foreach ([5, 8, 10, 12, 15] as $n): ?>
                    <option value="<?php echo (int) $n; ?>"<?php echo $n === 10 ? ' selected' : ''; ?>><?php echo (int) $n; ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <button type="submit" class="btn btn-brand w-100 py-2" id="upload-btn">
              <i class="bi bi-magic me-2"></i>Generate Quiz from PDF
            </button>

            <!-- Loading state -->
            <div class="text-center mt-4 d-none" id="uploading-state">
              <div class="spinner-border text-brand mb-3" role="status"></div>
              <h5 class="h6 mb-0">Processing your PDF and generating a quiz...</h5>
              <p class="small text-muted-ink mb-0">This can take up to a minute.</p>
            </div>

            <div class="form-alert mt-3" hidden></div>
          </form>
        </div>

      </div>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
