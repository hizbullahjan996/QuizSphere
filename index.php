<?php
/**
 * QuizSphere - Landing page
 * Phase 1: pure frontend marketing page.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
$page_title  = '';
$active_page = 'home';
require __DIR__ . '/includes/head.php';
?>
<body>

<?php require __DIR__ . '/includes/navbar.php'; ?>

<!-- ======================= Hero ======================= -->
<section class="hero">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-6 hero-copy">
        <span class="section-label mb-3">
          <i class="bi bi-stars"></i> AI-Powered Learning
        </span>
        <h1 class="hero-title mb-3">
          Turn Any Topic Into an <span class="text-gradient">Intelligent</span> Learning Experience
        </h1>
        <p class="hero-sub mb-4">
          QuizSphere uses AI to generate quizzes from any topic or PDF,
          analyze your performance and personalize practice so you learn faster.
        </p>
        <div class="d-flex flex-wrap gap-3">
          <a href="<?php echo url('/register.php'); ?>" class="btn btn-brand btn-lg px-4">
            <i class="bi bi-magic me-2"></i>Generate Your First Quiz
          </a>
          <a href="#features" class="btn btn-outline-brand btn-lg px-4">
            Explore Features
          </a>
        </div>
        <div class="d-flex flex-wrap gap-4 mt-5 text-muted-ink small">
          <div><i class="bi bi-people-fill me-1 text-brand"></i> 5,000+ learners</div>
          <div><i class="bi bi-question-circle-fill me-1"></i> 40,000+ quizzes</div>
          <div><i class="bi bi-star-fill me-1"></i> 4.8 rating</div>
        </div>
      </div>
      <div class="col-lg-6 hero-visual">
        <div class="card floating-card p-4">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="fw-bold">Recent Quiz · Computer Basics</span>
            <span class="badge badge-soft">In progress</span>
          </div>
          <div class="progress mb-2" role="progressbar" aria-label="Quiz progress" aria-valuenow="60" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar" style="width: 60%"></div>
          </div>
          <div class="text-muted-ink small mb-3">Question 3 of 5</div>
          <div class="mb-3 fw-semibold">Which component is known as the "brain" of the computer?</div>
          <div class="d-flex flex-column gap-2">
            <div class="option selected"><span class="option-letter">A</span><span>RAM</span></div>
            <div class="option"><span class="option-letter">B</span><span>CPU</span></div>
            <div class="option"><span class="option-letter">C</span><span>Hard Drive</span></div>
            <div class="option"><span class="option-letter">D</span><span>GPU</span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ======================= Features ======================= -->
<section class="section bg-soft-surface-3" id="features">
  <div class="container">
    <div class="text-center mb-5">
      <span class="section-label mb-2">Features</span>
      <h2 class="display-6 fw-bold mb-3">Everything you need to learn smarter</h2>
      <p class="text-muted-ink mx-auto" style="max-width:34rem;">
        Six powerful capabilities work together to make learning adaptive, measurable and rewarding.
      </p>
    </div>
    <div class="row g-4">
      <?php
      $features = [
        ['bi-robot', 'AI Quiz Generation', 'Describe a topic and our AI instantly builds a balanced, curriculum-aligned assessment for you.'],
        ['bi-file-earmark-pdf', 'PDF to Quiz', 'Upload a PDF and QuizSphere extracts key concepts and turns them into quiz questions.'],
        ['bi-graph-up-arrow', 'Adaptive Learning', 'Questions adjust in real time to your skill level, keeping you challenged without frustration.'],
        ['bi-person-raised-hand', 'AI Learning Coach', 'Get step-by-step explanations and personalized hints whenever you get stuck.'],
        ['bi-bar-chart-line', 'Performance Analytics', 'Understand your strengths, weak areas and improvement with clear visual reports.'],
        ['bi-patch-check', 'Certificates', 'Earn shareable certificates when you master a topic and prove your knowledge.'],
      ];
      foreach ($features as [$icon, $title, $text]) {
          echo '<div class="col-md-6 col-lg-4">';
          echo '<div class="card card-hover h-100 p-4">';
          echo '<div class="icon-badge mb-3"><i class="bi ' . $icon . ' fs-4"></i></div>';
          echo '<h5 class="mb-2">' . e($title) . '</h5>';
          echo '<p class="mb-0 text-muted-ink">' . e($text) . '</p>';
          echo '</div></div>';
      }
      ?>
    </div>
  </div>
</section>

<!-- ======================= How It Works ======================= -->
<section class="section" id="how">
  <div class="container">
    <div class="text-center mb-5">
      <span class="section-label mb-2">How QuizSphere Works</span>
      <h2 class="display-6 fw-bold mb-3">From topic to mastery in six steps</h2>
      <p class="text-muted-ink mx-auto" style="max-width:34rem;">
        A simple flow that turns any subject into a guided path toward understanding.
      </p>
    </div>
    <div class="row g-4">
      <?php
      $steps = [
        ['Choose a topic or upload a PDF', 'Start with any subject you want to learn, or drop in a document to work from.'],
        ['AI generates your assessment', 'We craft questions tailored to your chosen material and level.'],
        ['Attempt the quiz', 'Answer at your own pace with a clean, distraction-free interface.'],
        ['Analyze your weak areas', 'Instant feedback shows exactly where you need the most work.'],
        ['Practice personalized concepts', 'Get targeted exercises that fill the gaps in your understanding.'],
        ['Track your improvement', 'Watch your scores and mastery grow with clear analytics.'],
      ];
      foreach ($steps as $idx => [$title, $text]) {
          $n = $idx + 1;
          echo '<div class="col-md-6 col-lg-4">';
          echo '<div class="card h-100 p-4">';
          echo '<span class="step-number">' . $n . '</span>';
          echo '<h5 class="mb-2">' . e($title) . '</h5>';
          echo '<p class="mb-0 text-muted-ink">' . e($text) . '</p>';
          echo '</div></div>';
      }
      ?>
    </div>
  </div>
</section>

<!-- ======================= CTA ======================= -->
<section class="section bg-soft-brand">
  <div class="container text-center">
    <h2 class="display-6 fw-bold mb-3">Ready to learn smarter?</h2>
    <p class="text-muted-ink mx-auto mb-4" style="max-width:30rem;">
      Create your first AI-powered quiz in seconds — no credit card, no setup.
    </p>
    <a href="<?php echo url('/register.php'); ?>" class="btn btn-brand btn-lg px-5">
      Get Started Free <i class="bi bi-arrow-right ms-2"></i>
    </a>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
