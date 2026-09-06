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
      <div class="col-lg-7">

        <div class="mb-4">
          <h1 class="h3 mb-1">Generate a Quiz with AI</h1>
          <p class="text-muted-ink mb-0">
            Describe a topic and QuizSphere will build a personalized assessment for you.
          </p>
        </div>

        <div class="card p-4 p-md-5" id="generate-app">
          <form id="generate-form" novalidate>
            <div class="mb-3">
              <label for="topic" class="form-label">Topic</label>
              <input type="text" class="form-control" id="topic" name="topic"
                     value="<?php echo e($preTopic); ?>"
                     placeholder="e.g. Database Management Systems" required maxlength="200">
              <div class="feedback feedback-error">Please enter a topic.</div>
            </div>

            <div class="row g-3">
              <div class="col-md-6 mb-3">
                <label for="difficulty" class="form-label">Difficulty</label>
                <select class="form-select" id="difficulty" name="difficulty">
                  <option value="easy"<?php echo $preDifficulty === 'easy' ? ' selected' : ''; ?>>Easy</option>
                  <option value="medium"<?php echo $preDifficulty === 'medium' ? ' selected' : ''; ?>>Medium</option>
                  <option value="hard"<?php echo $preDifficulty === 'hard' ? ' selected' : ''; ?>>Hard</option>
                </select>
                <div class="feedback feedback-error">Please choose a difficulty.</div>
                <div class="small text-muted-ink mt-1"><i class="bi bi-sliders me-1"></i>Difficulty is suggested adaptively when practising a weak area.</div>
              </div>

              <div class="col-md-6 mb-3">
                <label for="question_count" class="form-label">Number of Questions</label>
                <input type="number" class="form-control" id="question_count" name="question_count"
                       value="10" min="1" max="15">
                <div class="feedback feedback-error">Choose between 1 and 15 questions.</div>
              </div>
            </div>

            <input type="hidden" name="question_type" value="multiple_choice">
            <input type="hidden" name="_csrf" value="<?php echo e(Security::csrfToken()); ?>">

            <button type="submit" class="btn btn-brand w-100 py-2 mt-2" id="generate-btn">
              <i class="bi bi-magic me-2"></i>Generate Quiz with AI
            </button>

            <!-- Loading state -->
            <div class="text-center mt-4 d-none" id="generating-state">
              <div class="spinner-border text-brand mb-3" role="status"></div>
              <h5 class="h6 mb-0">AI is creating your personalized quiz...</h5>
              <p class="small text-muted-ink mb-0">This usually takes a few seconds.</p>
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
