<?php
/**
 * QuizSphere - Landing page
 * Phase 1: pure frontend marketing page.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
$page_title  = 'QuizSphere';
$active_page = 'home';
require __DIR__ . '/includes/head.php';
?>
<body>

<?php require __DIR__ . '/includes/navbar.php'; ?>

<!-- ======================= Hero ======================= -->
<section class="hero bg-surface-2 position-relative overflow-hidden py-5 py-lg-7">
  <div class="container py-5">
    <div class="row align-items-center g-5">
      <div class="col-lg-6 hero-copy position-relative z-1">
        <span class="section-label mb-3 d-inline-flex align-items-center gap-2 bg-soft-brand text-brand rounded-pill px-3 py-1 fw-bold small text-uppercase tracking-wide border border-brand-100">
          <i class="bi bi-robot"></i> AI-POWERED LEARNING PLATFORM
        </span>
        <h1 class="hero-title mb-4 fw-bold text-ink-900" style="font-size: clamp(2.5rem, 5vw, 4rem); line-height: 1.1; letter-spacing: -0.04em;">
          Learn Smarter. <br><span class="text-brand">Quiz Better.</span> <br>Achieve More.
        </h1>
        <p class="hero-sub mb-5 text-muted-ink fs-5" style="max-width: 500px; line-height: 1.6;">
          Turn your learning materials into interactive quizzes, discover your weak areas, track your progress, and build knowledge with AI-powered personalized practice.
        </p>
        <div class="d-flex flex-wrap gap-3">
          <a href="<?php echo url('/register.php'); ?>" class="btn btn-brand btn-lg px-4 fw-semibold shadow-brand">
            Get Started Free
          </a>
          <a href="#features" class="btn btn-outline-brand btn-lg px-4 fw-semibold bg-surface">
            Explore Features
          </a>
        </div>
      </div>
      <div class="col-lg-6 hero-visual position-relative z-1">
        <div class="position-relative">
          <div class="position-absolute bg-brand-200 rounded-circle" style="width: 400px; height: 400px; filter: blur(80px); opacity: 0.5; top: 10%; right: 10%; z-index: -1;"></div>
          
          <div class="card floating-card p-4 border-1 border-ink-100 shadow-lg rounded-4 bg-surface">
            <!-- Dashboard inspired visual -->
            <div class="d-flex justify-content-between align-items-center mb-4">
               <div class="d-flex align-items-center gap-3">
                  <div class="avatar-circle avatar-circle-sm bg-brand-100 text-brand fw-bold d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; border-radius: 50%;">Q</div>
                  <div>
                    <div class="fw-bold text-ink-900 lh-1 mb-1">Computer Architecture</div>
                    <div class="small text-muted-ink">QuizSphere Coach</div>
                  </div>
               </div>
               <span class="badge bg-success-subtle text-success rounded-pill px-3 py-2 border border-success-subtle">
                 Level 12
               </span>
            </div>
            
            <div class="row g-3 mb-4">
               <div class="col-6">
                 <div class="p-3 bg-surface-2 rounded-3 border border-ink-100 text-center">
                    <div class="text-muted-ink small mb-1">Score</div>
                    <div class="fw-bold fs-3 text-brand">92%</div>
                 </div>
               </div>
               <div class="col-6">
                 <div class="p-3 bg-surface-2 rounded-3 border border-ink-100 text-center">
                    <div class="text-muted-ink small mb-1">XP Earned</div>
                    <div class="fw-bold fs-3 text-warning"><i class="bi bi-star-fill me-1"></i>+450</div>
                 </div>
               </div>
            </div>

            <div class="bg-surface-3 p-3 rounded-3 mb-3 border border-ink-100">
              <div class="d-flex gap-3">
                <div class="flex-shrink-0 mt-1">
                  <i class="bi bi-robot fs-4 text-brand"></i>
                </div>
                <div>
                  <div class="fw-semibold text-ink-900 small mb-1">AI Coach</div>
                  <div class="small text-muted-ink" style="line-height: 1.5;">Great job identifying the ALU! Try reviewing memory hierarchy next to boost your mastery.</div>
                </div>
              </div>
            </div>

            <div class="d-flex gap-2 justify-content-end">
              <div class="icon-badge badge-brand rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; background: #FFFBEB;"><i class="bi bi-trophy-fill text-warning"></i></div>
              <div class="icon-badge badge-brand rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; background: #EEF2FF;"><i class="bi bi-award-fill text-brand"></i></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ======================= Trust & Value Strip ======================= -->
<section class="py-4 border-bottom border-ink-100 bg-surface">
  <div class="container">
    <div class="d-flex flex-wrap justify-content-center align-items-center gap-4 gap-md-5 text-muted-ink fw-semibold small text-uppercase" style="letter-spacing: 0.05em;">
      <div class="d-flex align-items-center gap-2"><i class="bi bi-magic fs-5 text-brand"></i> AI Quiz Generation</div>
      <div class="d-none d-sm-block text-ink-200">|</div>
      <div class="d-flex align-items-center gap-2"><i class="bi bi-bullseye fs-5 text-brand"></i> Personalized Practice</div>
      <div class="d-none d-md-block text-ink-200">|</div>
      <div class="d-flex align-items-center gap-2"><i class="bi bi-graph-up-arrow fs-5 text-brand"></i> Progress Analytics</div>
      <div class="d-none d-lg-block text-ink-200">|</div>
      <div class="d-flex align-items-center gap-2"><i class="bi bi-trophy fs-5 text-brand"></i> Achievement Tracking</div>
    </div>
  </div>
</section>

<!-- ======================= Features ======================= -->
<section class="section bg-surface-2 py-6" id="features">
  <div class="container py-5">
    <div class="text-center mb-5">
      <span class="section-label mb-2 d-inline-flex align-items-center gap-2 bg-soft-brand text-brand rounded-pill px-3 py-1 fw-bold small text-uppercase tracking-wide border border-brand-100">Features</span>
      <h2 class="display-6 fw-bold mb-3 text-ink-900" style="letter-spacing: -0.02em;">Everything you need to learn smarter</h2>
      <p class="text-muted-ink mx-auto fs-5" style="max-width:38rem;">
        Discover the tools that make learning adaptive, measurable, and rewarding.
      </p>
    </div>
    <div class="row g-4">
      <?php
      $features = [
        ['bi-robot', 'AI Quiz Generator', 'Generate quizzes from topics or supported learning materials instantly.'],
        ['bi-file-earmark-pdf', 'PDF Material Upload', 'Use supported uploaded learning materials to create relevant quizzes.'],
        ['bi-chat-dots', 'AI Coach', 'Get learning guidance and help understanding difficult concepts.'],
        ['bi-bar-chart-line', 'Performance Analytics', 'Track quiz scores, progress, and areas that need improvement.'],
        ['bi-star', 'Gamified Learning', 'Earn XP, progress through levels, maintain streaks, and unlock achievements where supported.'],
        ['bi-award', 'Certificates and Achievements', 'Showcase earned achievements and available certificates.'],
        ['bi-trophy', 'Leaderboard', 'Compare performance with other learners where leaderboard functionality is enabled.'],
        ['bi-bullseye', 'Adaptive Practice', 'Focus practice on weak topics when supported by existing performance data.']
      ];
      foreach ($features as [$icon, $title, $text]) {
          echo '<div class="col-md-6 col-lg-3">';
          echo '<div class="card card-hover h-100 p-4 border-1 border-ink-100 shadow-xs bg-surface rounded-3 transition-normal">';
          echo '<div class="icon-badge mb-3 bg-soft-brand text-brand rounded-3 d-inline-flex align-items-center justify-content-center" style="width: 48px; height: 48px;"><i class="bi ' . $icon . ' fs-4"></i></div>';
          echo '<h5 class="mb-2 fw-bold text-ink-900 fs-6">' . e($title) . '</h5>';
          echo '<p class="mb-0 text-muted-ink small lh-base">' . e($text) . '</p>';
          echo '</div></div>';
      }
      ?>
    </div>
  </div>
</section>

<!-- ======================= How It Works ======================= -->
<section class="section py-6 bg-surface" id="how">
  <div class="container py-5">
    <div class="text-center mb-5">
      <span class="section-label mb-2 d-inline-flex align-items-center gap-2 bg-soft-brand text-brand rounded-pill px-3 py-1 fw-bold small text-uppercase tracking-wide border border-brand-100">How It Works</span>
      <h2 class="display-6 fw-bold mb-3 text-ink-900" style="letter-spacing: -0.02em;">From topic to mastery in three steps</h2>
    </div>
    
    <div class="row g-4 position-relative">
      <!-- Optional connecting line on desktop -->
      <div class="d-none d-lg-block position-absolute top-50 start-0 w-100 border-top border-2 border-ink-100" style="z-index: 0; transform: translateY(-50%);"></div>
      
      <?php
      $steps = [
        ['Add Your Learning Material', 'Choose a topic or upload supported study material to get started.'],
        ['Generate and Take a Quiz', 'Use AI-powered quiz creation and test your knowledge interactively.'],
        ['Improve and Track Progress', 'Review results, identify weak topics, and continue practising.'],
      ];
      foreach ($steps as $idx => [$title, $text]) {
          $n = $idx + 1;
          echo '<div class="col-lg-4 position-relative z-1">';
          echo '<div class="card h-100 p-5 text-center border-1 border-ink-100 shadow-sm bg-surface rounded-4">';
          echo '<div class="step-number mx-auto mb-4 bg-brand-600 text-white fw-bold shadow-brand d-flex align-items-center justify-content-center rounded-circle" style="width: 56px; height: 56px; font-size: 1.25rem;">' . $n . '</div>';
          echo '<h5 class="mb-3 fw-bold text-ink-900">' . e($title) . '</h5>';
          echo '<p class="mb-0 text-muted-ink lh-base">' . e($text) . '</p>';
          echo '</div></div>';
      }
      ?>
    </div>
  </div>
</section>

<!-- ======================= Dashboard Preview ======================= -->
<section class="section py-6 bg-sidebar bg-dark" style="background-color: var(--sidebar-bg); color: var(--sidebar-text);">
  <div class="container py-5">
    <div class="row align-items-center g-5">
      <div class="col-lg-5 order-lg-2">
        <span class="section-label mb-3 d-inline-flex align-items-center gap-2 bg-brand-900 text-brand-200 rounded-pill px-3 py-1 fw-bold small text-uppercase tracking-wide border border-brand-800">Analytics</span>
        <h2 class="display-6 fw-bold mb-4 text-white" style="letter-spacing: -0.02em;">Monitor your progress in real-time</h2>
        <p class="text-brand-100 fs-5 mb-4" style="opacity: 0.8; line-height: 1.6;">
          Our comprehensive dashboard helps you monitor your learning journey with insights into your total quizzes, average scores, active streaks, and XP growth.
        </p>
        <ul class="list-unstyled d-flex flex-column gap-3 text-white">
          <li class="d-flex align-items-center gap-3"><i class="bi bi-check-circle-fill text-success fs-5"></i> Track total quizzes and scores</li>
          <li class="d-flex align-items-center gap-3"><i class="bi bi-check-circle-fill text-success fs-5"></i> View detailed performance charts</li>
          <li class="d-flex align-items-center gap-3"><i class="bi bi-check-circle-fill text-success fs-5"></i> Keep your learning streak alive</li>
        </ul>
      </div>
      <div class="col-lg-7 order-lg-1">
        <div class="card bg-sidebar-card border-sidebar-card p-2 rounded-4 shadow-lg" style="background-color: var(--sidebar-card-bg); border-color: var(--sidebar-card-border);">
          <div class="card-body bg-surface rounded-3 p-4">
             <!-- Simulated Dashboard Stats -->
             <div class="row g-3 mb-4">
               <div class="col-6 col-md-3">
                 <div class="p-3 border border-ink-100 rounded-3 text-center h-100">
                   <div class="text-muted-ink small mb-1">Total Quizzes</div>
                   <div class="fw-bold fs-4 text-ink-900">42</div>
                 </div>
               </div>
               <div class="col-6 col-md-3">
                 <div class="p-3 border border-ink-100 rounded-3 text-center h-100">
                   <div class="text-muted-ink small mb-1">Avg Score</div>
                   <div class="fw-bold fs-4 text-ink-900">86%</div>
                 </div>
               </div>
               <div class="col-6 col-md-3">
                 <div class="p-3 border border-ink-100 rounded-3 text-center h-100">
                   <div class="text-muted-ink small mb-1">Streak</div>
                   <div class="fw-bold fs-4 text-warning"><i class="bi bi-fire me-1"></i>14</div>
                 </div>
               </div>
               <div class="col-6 col-md-3">
                 <div class="p-3 border border-ink-100 rounded-3 text-center h-100">
                   <div class="text-muted-ink small mb-1">Level</div>
                   <div class="fw-bold fs-4 text-brand">12</div>
                 </div>
               </div>
             </div>
             <!-- Simulated Chart -->
             <div class="border border-ink-100 rounded-3 p-3">
               <div class="d-flex justify-content-between align-items-center mb-3">
                 <div class="fw-semibold text-ink-900">Quiz Activity</div>
                 <div class="text-muted-ink small">Past 7 Days</div>
               </div>
               <div class="d-flex align-items-end justify-content-between pt-4 pb-2 px-2" style="height: 120px; border-bottom: 1px dashed var(--ink-200);">
                 <!-- Simple CSS bars -->
                 <div style="width: 12%; background: var(--brand-500); border-radius: 4px 4px 0 0; height: 60%;"></div>
                 <div style="width: 12%; background: var(--brand-500); border-radius: 4px 4px 0 0; height: 80%;"></div>
                 <div style="width: 12%; background: var(--brand-500); border-radius: 4px 4px 0 0; height: 40%;"></div>
                 <div style="width: 12%; background: var(--brand-500); border-radius: 4px 4px 0 0; height: 90%;"></div>
                 <div style="width: 12%; background: var(--brand-500); border-radius: 4px 4px 0 0; height: 75%;"></div>
                 <div style="width: 12%; background: var(--brand-500); border-radius: 4px 4px 0 0; height: 85%;"></div>
                 <div style="width: 12%; background: var(--brand-500); border-radius: 4px 4px 0 0; height: 100%;"></div>
               </div>
             </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ======================= Gamification & Achievements ======================= -->
<section class="section py-6 bg-surface-2" id="achievements">
  <div class="container py-5">
    <div class="row align-items-center g-5">
      <div class="col-lg-6">
        <span class="section-label mb-3 d-inline-flex align-items-center gap-2 bg-warning-soft text-warning-ink rounded-pill px-3 py-1 fw-bold small text-uppercase tracking-wide border border-warning-subtle">Gamification</span>
        <h2 class="display-6 fw-bold mb-4 text-ink-900" style="letter-spacing: -0.02em;">Make learning rewarding</h2>
        <p class="text-muted-ink fs-5 mb-4" style="line-height: 1.6;">
          Earn XP, climb the ranks, and unlock badges as you hit milestones. Keep your streak alive and earn certificates for mastering topics.
        </p>
        <div class="d-flex flex-column gap-3">
          <div class="d-flex align-items-center gap-3 bg-surface p-3 rounded-3 border border-ink-100 shadow-xs">
            <div class="icon-badge badge-warning bg-warning-soft text-warning-ink rounded-circle"><i class="bi bi-star-fill"></i></div>
            <div class="fw-semibold text-ink-900">XP and Levels</div>
          </div>
          <div class="d-flex align-items-center gap-3 bg-surface p-3 rounded-3 border border-ink-100 shadow-xs">
            <div class="icon-badge badge-brand bg-soft-brand text-brand rounded-circle"><i class="bi bi-trophy-fill"></i></div>
            <div class="fw-semibold text-ink-900">Achievement Badges</div>
          </div>
          <div class="d-flex align-items-center gap-3 bg-surface p-3 rounded-3 border border-ink-100 shadow-xs">
            <div class="icon-badge badge-success bg-success-soft text-success-ink rounded-circle"><i class="bi bi-award-fill"></i></div>
            <div class="fw-semibold text-ink-900">Certificates of Mastery</div>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="row g-3">
          <div class="col-6">
            <div class="card p-4 text-center border-0 shadow-sm bg-surface rounded-4 h-100">
              <i class="bi bi-fire fs-1 text-warning mb-2"></i>
              <div class="fw-bold text-ink-900">7-Day Streak</div>
              <div class="small text-muted-ink">Unlocked!</div>
            </div>
          </div>
          <div class="col-6">
            <div class="card p-4 text-center border-0 shadow-sm bg-surface rounded-4 h-100">
              <i class="bi bi-patch-check-fill fs-1 text-success mb-2"></i>
              <div class="fw-bold text-ink-900">Perfect Score</div>
              <div class="small text-muted-ink">Unlocked!</div>
            </div>
          </div>
          <div class="col-6">
            <div class="card p-4 text-center border-0 shadow-sm bg-surface rounded-4 h-100">
              <i class="bi bi-lightning-charge-fill fs-1 text-brand mb-2"></i>
              <div class="fw-bold text-ink-900">Fast Learner</div>
              <div class="small text-muted-ink">Locked</div>
            </div>
          </div>
          <div class="col-6">
            <div class="card p-4 text-center border-0 shadow-sm bg-surface rounded-4 h-100">
              <i class="bi bi-award-fill fs-1 text-info mb-2"></i>
              <div class="fw-bold text-ink-900">Top 10% Rank</div>
              <div class="small text-muted-ink">Locked</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ======================= AI Coach Showcase ======================= -->
<section class="section py-6 bg-surface">
  <div class="container py-5 text-center">
    <span class="section-label mb-3 d-inline-flex align-items-center gap-2 bg-soft-brand text-brand rounded-pill px-3 py-1 fw-bold small text-uppercase tracking-wide border border-brand-100">AI Coach</span>
    <h2 class="display-6 fw-bold mb-3 text-ink-900" style="letter-spacing: -0.02em;">Never get stuck again</h2>
    <p class="text-muted-ink mx-auto fs-5 mb-5" style="max-width:38rem;">
      Get personalized learning guidance and conceptual breakdowns whenever you need help.
    </p>

    <div class="card mx-auto shadow-sm border-1 border-ink-100 rounded-4 text-start bg-surface-2" style="max-width: 600px;">
      <div class="card-header bg-surface border-bottom border-ink-100 p-3 d-flex align-items-center gap-3 rounded-top-4">
        <div class="avatar-circle avatar-circle-sm bg-brand-100 text-brand fw-bold d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; border-radius: 50%;"><i class="bi bi-robot"></i></div>
        <div class="fw-semibold text-ink-900">QuizSphere Coach</div>
      </div>
      <div class="card-body p-4 d-flex flex-column gap-3">
        <!-- User message -->
        <div class="d-flex gap-3 align-self-end w-75">
          <div class="bg-brand text-white p-3 rounded-4 rounded-bottom-0 shadow-sm ms-auto" style="background: var(--brand-600);">
            Can you explain how an API works in simple terms?
          </div>
        </div>
        <!-- Coach message -->
        <div class="d-flex gap-3 w-85">
          <div class="avatar-circle avatar-circle-sm bg-brand-100 text-brand fw-bold d-flex align-items-center justify-content-center flex-shrink-0" style="width: 32px; height: 32px; border-radius: 50%; mt-1"><i class="bi bi-robot small"></i></div>
          <div class="bg-surface border border-ink-100 text-ink-800 p-3 rounded-4 rounded-top-0 shadow-xs lh-base">
            Absolutely! Think of an API like a waiter in a restaurant. <br><br>
            You (the <b>client</b>) sit at a table with a menu of requests. The kitchen (the <b>server</b>) is where the food is made.<br><br>
            The waiter (the <b>API</b>) takes your order to the kitchen and delivers the food back to you. It lets two separate systems talk to each other!
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ======================= CTA ======================= -->
<section class="section py-7 bg-sidebar" style="background-color: var(--sidebar-bg);">
  <div class="container text-center py-5">
    <h2 class="display-5 fw-bold mb-4 text-white" style="letter-spacing: -0.03em;">Your Next Learning Breakthrough Starts Here.</h2>
    <p class="text-brand-100 mx-auto mb-5 fs-5" style="max-width:32rem; opacity: 0.8;">
      Build your knowledge, challenge yourself, and track your progress with QuizSphere.
    </p>
    <a href="<?php echo url('/register.php'); ?>" class="btn btn-brand btn-lg px-5 py-3 fw-bold rounded-pill shadow-brand fs-5 transition-normal">
      Start Learning Free <i class="bi bi-arrow-right ms-2"></i>
    </a>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
