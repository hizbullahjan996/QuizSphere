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
    <div class="row g-4 g-xl-5">
      <!-- Header -->
      <div class="col-12 mb-2">
        <div class="d-inline-flex align-items-center gap-2 bg-soft-brand text-brand mb-3 px-3 py-1 rounded-pill fw-bold small text-uppercase tracking-wide border border-brand-100">
          <i class="bi bi-file-earmark-pdf"></i> Study Material Import
        </div>
        <h1 class="h3 fw-bold text-ink-900 mb-2" style="letter-spacing: -0.02em;">Upload Study Material</h1>
        <p class="text-muted-ink mb-0 fs-5">
          Turn your lecture slides or textbook chapters into interactive, adaptive quizzes instantly.
        </p>
      </div>

      <!-- Left Column: Upload Form -->
      <div class="col-lg-7">
        <div class="card p-4 p-md-5 border-1 border-ink-200 shadow-sm bg-surface rounded-4 h-100">
          <form id="upload-form" enctype="multipart/form-data" novalidate class="d-flex flex-column h-100">
            <div class="mb-4 flex-grow-1">
              <label class="form-label d-block fw-semibold text-ink-900 mb-3">Select PDF Document</label>
              
              <div class="upload-drop bg-surface-2 border-2 border-ink-200 rounded-4 p-5 text-center transition-normal hover-bg-brand-50" id="upload-drop" style="border-style: dashed !important; cursor: pointer;">
                <i class="bi bi-cloud-arrow-up text-brand mb-3 d-block" style="font-size: 3.5rem; line-height: 1;"></i>
                <h5 class="fw-bold text-ink-900 mb-2">Drag and drop your file here</h5>
                <p class="small text-muted-ink mb-3 mx-auto" style="max-width: 250px;">Or click anywhere in this area to browse files from your computer.</p>
                <div class="badge bg-surface border border-ink-200 text-ink-700 px-3 py-2 rounded-pill shadow-xs" id="upload-label">
                  Browse Files
                </div>
                
                <p class="mt-3 fw-bold text-brand fs-5 mb-0 text-break" id="upload-name"></p>
                <input type="file" id="pdf_file" name="pdf" accept="application/pdf" class="d-none">
              </div>
              
              <input type="hidden" name="_csrf" value="<?php echo e(Security::csrfToken()); ?>">
              
              <div class="d-flex align-items-start gap-2 mt-3 text-muted-ink small px-2">
                <i class="bi bi-info-circle-fill text-ink-300 mt-1"></i>
                <span style="line-height: 1.5;">Accepted formats: <strong>.PDF only</strong>. Maximum file size is <strong>10 MB</strong>. Make sure your document contains selectable text.</span>
              </div>
            </div>

            <div class="row g-3 mb-4">
              <div class="col-md-6">
                <label for="difficulty" class="form-label fw-semibold text-ink-800 small mb-2">Difficulty</label>
                <select class="form-select px-3 py-2 border-ink-200 bg-surface focus-ring-brand rounded-3 transition-normal" id="difficulty" name="difficulty">
                  <option value="easy">Easy (Foundational)</option>
                  <option value="medium" selected>Medium (Standard)</option>
                  <option value="hard">Hard (Rigorous)</option>
                </select>
              </div>
              <div class="col-md-6">
                <label for="question_count" class="form-label fw-semibold text-ink-800 small mb-2">Number of Questions</label>
                <select class="form-select px-3 py-2 border-ink-200 bg-surface focus-ring-brand rounded-3 transition-normal" id="question_count" name="question_count">
                  <?php foreach ([5, 8, 10, 12, 15] as $n): ?>
                    <option value="<?php echo (int) $n; ?>"<?php echo $n === 10 ? ' selected' : ''; ?>><?php echo (int) $n; ?> Questions</option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <button type="submit" class="btn btn-brand w-100 py-3 fw-semibold rounded-3 shadow-brand transition-normal d-flex align-items-center justify-content-center gap-2" id="upload-btn">
              <i class="bi bi-file-earmark-arrow-up fs-5"></i>
              <span>Generate Quiz from PDF</span>
            </button>

            <!-- Loading state -->
            <div class="text-center mt-4 d-none py-4 bg-surface-2 rounded-3 border border-ink-100" id="uploading-state">
              <div class="spinner-border text-brand mb-3" role="status" style="width: 2rem; height: 2rem;"></div>
              <h5 class="h6 fw-bold text-ink-900 mb-2">Processing Document...</h5>
              <p class="small text-muted-ink mb-0 px-3 lh-sm">Extracting text tokens and generating tailored questions with AI (takes ~15-30 seconds).</p>
            </div>

            <div class="form-alert mt-3" hidden></div>
          </form>
        </div>
      </div>

      <!-- Right Column: Guidance & Timeline -->
      <div class="col-lg-5">
        <div class="d-flex flex-column gap-4 h-100">
          
          <!-- Processing Workflow -->
          <div class="card p-4 border-1 border-ink-200 shadow-sm bg-surface rounded-4">
            <h5 class="fw-bold text-ink-900 mb-4 d-flex align-items-center gap-2">
              <i class="bi bi-diagram-3 text-brand"></i> How it works
            </h5>
            
            <div class="d-flex flex-column gap-0 position-relative">
              <div class="position-absolute border-start border-2 border-ink-200 h-100" style="left: 11px; top: 10px; z-index: 0; bottom: 20px;"></div>
              
              <div class="d-flex gap-3 position-relative z-1 mb-4">
                <div class="bg-brand text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 24px; height: 24px; font-size: 0.75rem; font-weight: bold;">1</div>
                <div>
                  <div class="fw-bold text-ink-900 small mb-1">Upload Study Material</div>
                  <div class="text-muted-ink small lh-sm">Select your PDF textbook chapter, notes, or presentation.</div>
                </div>
              </div>
              
              <div class="d-flex gap-3 position-relative z-1 mb-4">
                <div class="bg-surface border border-ink-300 text-ink-500 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 24px; height: 24px; font-size: 0.75rem; font-weight: bold;">2</div>
                <div>
                  <div class="fw-bold text-ink-900 small mb-1">Text Extraction</div>
                  <div class="text-muted-ink small lh-sm">We securely extract readable text blocks from the document pages.</div>
                </div>
              </div>
              
              <div class="d-flex gap-3 position-relative z-1 mb-4">
                <div class="bg-surface border border-ink-300 text-ink-500 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 24px; height: 24px; font-size: 0.75rem; font-weight: bold;">3</div>
                <div>
                  <div class="fw-bold text-ink-900 small mb-1">AI Question Generation</div>
                  <div class="text-muted-ink small lh-sm">The AI identifies core concepts and synthesizes questions and distractors.</div>
                </div>
              </div>

              <div class="d-flex gap-3 position-relative z-1">
                <div class="bg-surface border border-ink-300 text-ink-500 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 24px; height: 24px; font-size: 0.75rem; font-weight: bold;">4</div>
                <div>
                  <div class="fw-bold text-ink-900 small mb-1">Review & Attempt</div>
                  <div class="text-muted-ink small lh-sm">Your quiz is ready to be taken in your dashboard immediately.</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Guidance Card -->
          <div class="card p-4 border-1 border-warning-subtle shadow-sm bg-warning-soft rounded-4 text-warning-ink">
            <h6 class="fw-bold mb-3 d-flex align-items-center gap-2">
              <i class="bi bi-lightbulb-fill"></i> Upload Guidelines
            </h6>
            <ul class="small d-flex flex-column gap-2 ps-3 mb-0 lh-sm opacity-75">
              <li><strong>Text-based documents</strong> yield the highest quality quizzes.</li>
              <li>Scanned or heavily image-based documents may miss critical information unless they have OCR text layers.</li>
              <li>Limit uploads to specific chapters rather than whole 500-page books for precise testing.</li>
              <li>Ensure the file is not password protected.</li>
            </ul>
          </div>
          
        </div>
      </div>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
