<?php
/**
 * QuizSphere - Generate a quiz (AI) page
 * -------------------------------------------------------------
 * Authenticated. Collects topic, difficulty and question count, then calls
 * api/generate_quiz.php (server-side AI) and navigates to the created quiz.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

try {
    $client = SupabaseClient::fromConfig();
    $auth = new Auth($client);
} catch (SupabaseException $e) {
    $auth = null;
}

if (!$auth || !$auth->check()) {
    set_flash('error', 'Please log in to generate quizzes.');
    redirect('/login.php');
}

$user = $auth->user();
$preTopic = (string) ($_GET['concept'] ?? ($_GET['topic'] ?? ''));
$preDifficulty = (string) ($_GET['difficulty'] ?? 'medium');
if (!in_array($preDifficulty, ['easy', 'medium', 'hard'], true)) {
    $preDifficulty = 'medium';
}
$page_title = 'Generate Quiz';
$active_page = 'pages';
require __DIR__ . '/../includes/head.php';
?>
<body>
<div class="dashboard-shell">
  <?php $dashboard_active = 'generate'; require __DIR__ . '/../includes/sidebar.php'; ?>

  <main class="dashboard-main">
    <div class="row g-4 g-xl-5">
      <!-- Header -->
      <div class="col-12 mb-2">
        <div class="d-inline-flex align-items-center gap-2 bg-soft-brand text-brand mb-3 px-3 py-1 rounded-pill fw-bold small text-uppercase tracking-wide border border-brand-100">
          <i class="bi bi-robot"></i> AI Assessment Engine
        </div>
        <h1 class="h3 fw-bold text-ink-900 mb-2" style="letter-spacing: -0.02em;">AI Quiz Studio</h1>
        <p class="text-muted-ink mb-0 fs-5">
          Create personalized quizzes to test your knowledge across any topic or academic domain.
        </p>
      </div>

      <!-- Left Column: Form -->
      <div class="col-lg-7">
        <div class="card p-4 p-md-5 border-1 border-ink-200 shadow-sm bg-surface rounded-4" id="generate-app">
          <form id="generate-form" novalidate>
            <div class="mb-4">
              <label for="topic" class="form-label fw-semibold text-ink-800 d-flex justify-content-between align-items-center mb-2">
                <span>Quiz Topic / Syllabus</span>
                <span class="small text-muted-ink fw-normal" style="font-size: 0.75rem;">Up to 200 chars</span>
              </label>
              <input type="text" class="form-control form-control-lg px-3 py-2 border-ink-200 bg-surface focus-ring-brand rounded-3 transition-normal" id="topic" name="topic"
                     value="<?php echo e($preTopic); ?>"
                     placeholder="e.g. Distributed Systems & Consensus Algorithms" required maxlength="200" oninput="updateSummary()">
              <div class="feedback feedback-error small text-danger mt-1">Please enter a topic.</div>

              <!-- Popular Topic Suggestions -->
              <div class="mt-4">
                <div class="small fw-semibold text-ink-700 mb-2">Quick topic presets:</div>
                <div class="d-flex flex-wrap gap-2">
                  <?php
                    $presets = ['Data Structures', 'Relational Databases', 'Operating Systems', 'System Design', 'Cybersecurity', 'Machine Learning'];
                    foreach ($presets as $preset):
                  ?>
                    <button type="button" class="btn btn-sm bg-surface-2 border-ink-200 text-ink-700 rounded-pill py-1 px-3 topic-preset-btn transition-normal hover-bg-brand-50"
                            onclick="document.getElementById('topic').value='<?php echo addslashes($preset); ?>'; updateSummary();">
                      <?php echo e($preset); ?>
                    </button>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>

            <div class="row g-4 mb-4">
              <div class="col-md-6">
                <label for="difficulty" class="form-label fw-semibold text-ink-800 mb-2">Difficulty Level</label>
                <select class="form-select px-3 py-2 border-ink-200 bg-surface focus-ring-brand rounded-3 transition-normal" id="difficulty" name="difficulty" onchange="updateSummary()">
                  <option value="easy"<?php echo $preDifficulty === 'easy' ? ' selected' : ''; ?>>Easy (Foundations)</option>
                  <option value="medium"<?php echo $preDifficulty === 'medium' ? ' selected' : ''; ?>>Medium (Core Concepts)</option>
                  <option value="hard"<?php echo $preDifficulty === 'hard' ? ' selected' : ''; ?>>Hard (Deep Dive)</option>
                </select>
                <div class="feedback feedback-error small text-danger mt-1">Please choose a difficulty.</div>
                <div class="small text-muted-ink mt-2 lh-sm" style="font-size: 0.75rem;"><i class="bi bi-info-circle me-1"></i>Difficulty adapts based on target mastery.</div>
              </div>

              <div class="col-md-6">
                <label for="question_count" class="form-label fw-semibold text-ink-800 mb-2">Number of Questions</label>
                <input type="number" class="form-control px-3 py-2 border-ink-200 bg-surface focus-ring-brand rounded-3 transition-normal" id="question_count" name="question_count"
                       value="10" min="1" max="15" onchange="updateSummary()" oninput="updateSummary()">
                <div class="feedback feedback-error small text-danger mt-1">Choose between 1 and 15.</div>
                <div class="small text-muted-ink mt-2 lh-sm" style="font-size: 0.75rem;"><i class="bi bi-clock-history me-1"></i>Allocates ~60s per question for testing.</div>
              </div>
            </div>

            <input type="hidden" name="question_type" value="multiple_choice">
            <input type="hidden" name="_csrf" value="<?php echo e(Security::csrfToken()); ?>">

            <button type="submit" class="btn btn-brand w-100 py-3 mt-2 fw-semibold rounded-3 shadow-brand transition-normal d-flex align-items-center justify-content-center gap-2" id="generate-btn">
              <i class="bi bi-magic fs-5"></i>
              <span>Generate Quiz</span>
            </button>

            <!-- Loading state -->
            <div class="text-center mt-4 d-none py-4 bg-surface-2 rounded-3 border border-ink-100" id="generating-state">
              <div class="spinner-border text-brand mb-3" role="status" style="width: 2rem; height: 2rem;"></div>
              <h5 class="h6 fw-bold text-ink-900 mb-2">AI is crafting your assessment...</h5>
              <p class="small text-muted-ink mb-0 px-3 lh-sm">Analyzing concepts, formulating questions, distractors, and educational tags.</p>
            </div>

            <div class="form-alert mt-3" hidden></div>
          </form>
        </div>
      </div>

      <!-- Right Column: Guidance & Summary -->
      <div class="col-lg-5">
        <div class="card p-4 border-1 border-ink-200 shadow-sm bg-surface rounded-4 h-100 sticky-top" style="top: 2rem; z-index: 1;">
          
          <div class="mb-4 pb-3 border-bottom border-ink-100">
            <h5 class="fw-bold text-ink-900 mb-3 d-flex align-items-center gap-2">
              <i class="bi bi-sliders2 text-brand"></i> Configuration Summary
            </h5>
            
            <div class="d-flex flex-column gap-3">
              <div class="d-flex align-items-start gap-3 bg-surface-2 p-3 rounded-3 border border-ink-100">
                <i class="bi bi-card-text text-brand mt-1"></i>
                <div>
                  <div class="small fw-semibold text-ink-900 mb-1">Target Topic</div>
                  <div class="text-muted-ink small" id="summary-topic"><?php echo $preTopic ?: 'None specified'; ?></div>
                </div>
              </div>
              
              <div class="d-flex gap-2">
                <div class="flex-grow-1 bg-surface-2 p-3 rounded-3 border border-ink-100 d-flex flex-column gap-1">
                  <div class="small fw-semibold text-ink-900"><i class="bi bi-bar-chart-steps text-warning me-1"></i> Level</div>
                  <div class="text-muted-ink small text-capitalize" id="summary-diff"><?php echo $preDifficulty; ?></div>
                </div>
                <div class="flex-grow-1 bg-surface-2 p-3 rounded-3 border border-ink-100 d-flex flex-column gap-1">
                  <div class="small fw-semibold text-ink-900"><i class="bi bi-ui-checks-grid text-success me-1"></i> Format</div>
                  <div class="text-muted-ink small"><span id="summary-count">10</span> Multiple Choice</div>
                </div>
              </div>
            </div>
          </div>

          <div>
            <h6 class="fw-bold text-ink-900 mb-3 d-flex align-items-center gap-2">
              <i class="bi bi-lightbulb text-warning"></i> Writing Good Topics
            </h6>
            <ul class="small text-muted-ink d-flex flex-column gap-2 ps-3 mb-0 lh-sm">
              <li>Be specific. <em>"Python decorators"</em> is better than just <em>"Python"</em>.</li>
              <li>You can paste syllabus fragments or core concepts.</li>
              <li>Include constraints if needed, e.g., <em>"JavaScript Promises (no async/await)"</em>.</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
    
    <script>
      function updateSummary() {
        const topic = document.getElementById('topic').value.trim();
        const diff = document.getElementById('difficulty').value;
        const count = document.getElementById('question_count').value;
        
        document.getElementById('summary-topic').textContent = topic || 'None specified';
        document.getElementById('summary-diff').textContent = diff;
        document.getElementById('summary-count').textContent = count || '0';
      }
      
      // Initialize on load
      document.addEventListener('DOMContentLoaded', updateSummary);
    </script>
</div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
