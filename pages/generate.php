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
    <div class="row justify-content-center">
      <div class="col-lg-8 col-xl-7">

        <!-- Header -->
        <div class="mb-4">
          <div class="d-inline-flex align-items-center gap-2 badge-soft text-brand mb-2">
            <i class="bi bi-robot"></i> AI Assessment Engine
          </div>
          <h1 class="h3 fw-bold text-ink-900 mb-1">Generate a Quiz with AI</h1>
          <p class="text-muted-ink mb-0">
            Specify any subject, technology, or academic domain. QuizSphere dynamically synthesizes a custom, graded assessment.
          </p>
        </div>

        <!-- Form Card -->
        <div class="card p-4 p-md-5" id="generate-app">
          <form id="generate-form" novalidate>
            <div class="mb-4">
              <label for="topic" class="form-label d-flex justify-content-between align-items-center">
                <span>Quiz Topic / Syllabus</span>
                <span class="small text-muted-ink fw-normal">Up to 200 chars</span>
              </label>
              <input type="text" class="form-control form-control-lg" id="topic" name="topic"
                     value="<?php echo e($preTopic); ?>"
                     placeholder="e.g. Distributed Systems & Consensus Algorithms" required maxlength="200">
              <div class="feedback feedback-error">Please enter a topic.</div>

              <!-- Popular Topic Suggestions -->
              <div class="mt-3">
                <div class="small fw-semibold text-muted-ink mb-2">Quick topic presets:</div>
                <div class="d-flex flex-wrap gap-2">
                  <?php
                    $presets = ['Data Structures & Algorithms', 'Relational Databases (SQL)', 'Operating Systems', 'System Design', 'Cybersecurity Basics', 'Machine Learning'];
                    foreach ($presets as $preset):
                  ?>
                    <button type="button" class="btn btn-sm btn-outline-brand rounded-pill py-1 px-3 topic-preset-btn"
                            onclick="document.getElementById('topic').value='<?php echo addslashes($preset); ?>';">
                      <?php echo e($preset); ?>
                    </button>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>

            <div class="row g-3 mb-4">
              <div class="col-md-6">
                <label for="difficulty" class="form-label">Difficulty Level</label>
                <select class="form-select" id="difficulty" name="difficulty">
                  <option value="easy"<?php echo $preDifficulty === 'easy' ? ' selected' : ''; ?>>Easy (Foundations)</option>
                  <option value="medium"<?php echo $preDifficulty === 'medium' ? ' selected' : ''; ?>>Medium (Core Concepts)</option>
                  <option value="hard"<?php echo $preDifficulty === 'hard' ? ' selected' : ''; ?>>Hard (Advanced / Deep Dive)</option>
                </select>
                <div class="feedback feedback-error">Please choose a difficulty.</div>
                <div class="small text-muted-ink mt-2"><i class="bi bi-info-circle me-1"></i>Difficulty adapts based on target mastery.</div>
              </div>

              <div class="col-md-6">
                <label for="question_count" class="form-label">Number of Questions</label>
                <input type="number" class="form-control" id="question_count" name="question_count"
                       value="10" min="1" max="15">
                <div class="feedback feedback-error">Choose between 1 and 15 questions.</div>
                <div class="small text-muted-ink mt-2"><i class="bi bi-clock-history me-1"></i>Each question allocates 60s for testing.</div>
              </div>
            </div>

            <input type="hidden" name="question_type" value="multiple_choice">
            <input type="hidden" name="_csrf" value="<?php echo e(Security::csrfToken()); ?>">

            <button type="submit" class="btn btn-brand w-100 py-3 mt-1 shadow-sm" id="generate-btn">
              <i class="bi bi-magic me-2"></i>Generate Quiz with AI
            </button>

            <!-- Loading state -->
            <div class="text-center mt-4 d-none py-3" id="generating-state">
              <div class="spinner-border text-brand mb-3" role="status"></div>
              <h5 class="h6 fw-semibold text-ink-900 mb-1">AI is crafting your assessment...</h5>
              <p class="small text-muted-ink mb-0">Formulating questions, distractors, and concept tags (usually takes ~5s).</p>
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
