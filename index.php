<?php
/**
 * QuizSphere - Landing page
 * Modern, pitch-ready SaaS EdTech marketing page.
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
        <div class="d-inline-flex align-items-center gap-2 badge-soft text-brand mb-3">
          <i class="bi bi-stars"></i> Intelligent EdTech Platform
        </div>
        <h1 class="hero-title mb-3">
          Turn Any Topic Into an <span class="text-gradient">Intelligent</span> Learning Experience
        </h1>
        <p class="hero-sub mb-4">
          QuizSphere synthesizes adaptive quizzes from any syllabus topic or uploaded PDF, identifies your weak areas in real time, and guides you toward verifiable mastery.
        </p>
        <div class="d-flex flex-wrap gap-3">
          <a href="<?php echo url('/register.php'); ?>" class="btn btn-brand btn-lg px-4 shadow-sm">
            <i class="bi bi-magic me-2"></i>Generate Your First Quiz
          </a>
          <a href="#features" class="btn btn-outline-brand btn-lg px-4">
            Explore Capabilities
          </a>
        </div>
        <div class="d-flex flex-wrap gap-4 mt-5 text-muted-ink small">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-shield-check text-success fs-5"></i>
            <span><strong>100% Free</strong> Hackathon Edition</span>
          </div>
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-lightning-charge-fill text-warning fs-5"></i>
            <span><strong>Instant AI</strong> Generation</span>
          </div>
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-patch-check-fill text-brand fs-5"></i>
            <span><strong>Verified</strong> Certificates</span>
          </div>
        </div>
      </div>
      <div class="col-lg-6 hero-visual">
        <div class="card floating-card p-4 p-md-5">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="d-flex align-items-center gap-2">
              <span class="stat-icon-wrap stat-icon-indigo" style="width: 2rem; height: 2rem; font-size: 0.9rem;">
                <i class="bi bi-cpu"></i>
              </span>
              <span class="fw-bold text-ink-900 small">Computer Architecture · Live Assessment</span>
            </div>
            <span class="badge-soft badge-difficulty-medium">Medium</span>
          </div>
          <div class="progress mb-2" role="progressbar" aria-label="Quiz progress" aria-valuenow="60" aria-valuemin="0" aria-valuemax="100" style="height: 6px;">
            <div class="progress-bar" style="width: 60%"></div>
          </div>
          <div class="text-muted-ink small mb-3">Question 3 of 5</div>
          <div class="mb-3 fw-bold text-ink-900">Which processor component performs arithmetic and bitwise logic operations?</div>
          <div class="d-flex flex-column gap-2">
            <div class="option"><span class="option-letter">A</span><span>Control Unit (CU)</span></div>
            <div class="option selected"><span class="option-letter">B</span><span>Arithmetic Logic Unit (ALU)</span></div>
            <div class="option"><span class="option-letter">C</span><span>Memory Management Unit (MMU)</span></div>
            <div class="option"><span class="option-letter">D</span><span>Instruction Register (IR)</span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ======================= Metrics Ribbon ======================= -->
<section class="py-4 border-top border-bottom bg-surface">
  <div class="container">
    <div class="row g-4 text-center">
      <div class="col-6 col-md-3">
        <div class="fw-bold text-ink-900 fs-4">60 Seconds</div>
        <div class="text-muted-ink small">Per Question Adaptive Timing</div>
      </div>
      <div class="col-6 col-md-3">
        <div class="fw-bold text-ink-900 fs-4">PDF Extraction</div>
        <div class="text-muted-ink small">Upload Any Study Notes</div>
      </div>
      <div class="col-6 col-md-3">
        <div class="fw-bold text-ink-900 fs-4">AI Coach</div>
        <div class="text-muted-ink small">Personalized Study Guidance</div>
      </div>
      <div class="col-6 col-md-3">
        <div class="fw-bold text-ink-900 fs-4">80%+ Mastery</div>
        <div class="text-muted-ink small">Automatic Certificate Issuance</div>
      </div>
    </div>
  </div>
</section>

<!-- ======================= Features ======================= -->
<section class="section bg-soft-surface-3" id="features">
  <div class="container">
    <div class="text-center mb-5">
      <div class="d-inline-flex align-items-center gap-2 badge-soft text-brand mb-2">
        <i class="bi bi-grid-1x2"></i> Core Architecture
      </div>
      <h2 class="display-6 fw-bold mb-3 text-ink-900">Engineered for deep academic mastery</h2>
      <p class="text-muted-ink mx-auto" style="max-width:36rem;">
        QuizSphere merges generative AI, diagnostic analytics, and gamified progress into one cohesive EdTech platform.
      </p>
    </div>
    <div class="row g-4">
      <?php
      $features = [
        ['bi-robot', 'stat-icon-indigo', 'AI Quiz Synthesis', 'Prompt any topic, programming language, or academic field. QuizSphere drafts structured questions with verified solutions.'],
        ['bi-file-earmark-pdf', 'stat-icon-violet', 'PDF to Interactive Exam', 'Upload lecture slides or research PDFs. The parser extracts content tokens to test your retention directly.'],
        ['bi-graph-up-arrow', 'stat-icon-emerald', 'Concept Mastery Engine', 'Analyzes question responses into weak, developing, and strong knowledge areas with targeted drill suggestions.'],
        ['bi-chat-dots', 'stat-icon-indigo', 'Grounded AI Coach', 'Discuss difficult answers with an intelligent tutor that references your actual quiz errors without hallucination.'],
        ['bi-trophy', 'stat-icon-amber', 'Gamified Achievement System', 'Earn XP, climb the real-time leaderboard, maintain daily learning streaks, and celebrate unlocked milestones.'],
        ['bi-patch-check', 'stat-icon-emerald', 'Verifiable Credentials', 'Score 80%+ on assessments to generate cryptographic certificates with public QR code validation.'],
      ];
      foreach ($features as [$icon, $iconClass, $title, $text]) {
          echo '<div class="col-md-6 col-lg-4">';
          echo '<div class="card card-hover h-100 p-4">';
          echo '<div class="stat-icon-wrap ' . $iconClass . ' mb-3"><i class="bi ' . $icon . '"></i></div>';
          echo '<h5 class="fw-bold text-ink-900 mb-2">' . e($title) . '</h5>';
          echo '<p class="mb-0 text-muted-ink small lh-base">' . e($text) . '</p>';
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
      <div class="d-inline-flex align-items-center gap-2 badge-soft text-brand mb-2">
        <i class="bi bi-arrow-repeat"></i> Learning Workflow
      </div>
      <h2 class="display-6 fw-bold mb-3 text-ink-900">From concept confusion to certified mastery</h2>
      <p class="text-muted-ink mx-auto" style="max-width:34rem;">
        A streamlined, data-driven cycle designed to accelerate study retention.
      </p>
    </div>
    <div class="row g-4">
      <?php
      $steps = [
        ['1', 'Specify Topic or PDF', 'Choose an academic subject, paste syllabus terms, or upload textbook chapters.'],
        ['2', 'AI Builds the Assessment', 'The engine extracts key competencies and balances difficulty levels automatically.'],
        ['3', 'Take the Timed Quiz', 'Answer in a distraction-free, responsive testing environment with countdown feedback.'],
        ['4', 'Review Detailed Explanations', 'View comprehensive justifications for every correct and incorrect answer.'],
        ['5', 'Consult Your AI Coach', 'Clarify misunderstandings and receive personalized recommended practice drills.'],
        ['6', 'Claim Verifiable Certificate', 'Reach 80%+ accuracy to unlock shareable, cryptographically signed credentials.'],
      ];
      foreach ($steps as [$num, $title, $text]) {
          echo '<div class="col-md-6 col-lg-4">';
          echo '<div class="card h-100 p-4 border-1">';
          echo '<div class="d-flex align-items-center justify-content-between mb-3">';
          echo '<span class="avatar-circle avatar-circle-sm" style="font-size:0.85rem;">' . $num . '</span>';
          echo '<span class="badge-soft">Step ' . $num . '</span>';
          echo '</div>';
          echo '<h5 class="fw-bold text-ink-900 mb-2">' . e($title) . '</h5>';
          echo '<p class="mb-0 text-muted-ink small lh-base">' . e($text) . '</p>';
          echo '</div></div>';
      }
      ?>
    </div>
  </div>
</section>

<!-- ======================= CTA ======================= -->
<section class="section bg-soft-brand text-center py-5">
  <div class="container py-4">
    <div class="d-inline-flex align-items-center gap-2 badge-soft text-brand mb-3">
      <i class="bi bi-mortarboard"></i> Ready to Elevate Your Learning?
    </div>
    <h2 class="display-6 fw-bold mb-3 text-ink-900">Start Testing Your Knowledge in Seconds</h2>
    <p class="text-muted-ink mx-auto mb-4" style="max-width:32rem;">
      Create an account to generate custom quizzes, track mastery analytics, and build verifiable skills.
    </p>
    <div class="d-flex flex-wrap justify-content-center gap-3">
      <a href="<?php echo url('/register.php'); ?>" class="btn btn-brand btn-lg px-5 shadow-sm">
        Get Started Free <i class="bi bi-arrow-right ms-2"></i>
      </a>
      <a href="<?php echo url('/login.php'); ?>" class="btn btn-outline-brand btn-lg px-4">
        Log In
      </a>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
