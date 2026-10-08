<?php
/**
 * QuizSphere - Registration page
 * Registers via api/auth.php (server-side, Supabase) with client validation.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$page_title  = 'Register';
$active_page = 'register';
require __DIR__ . '/includes/head.php';
?>
<body>

<div class="auth-shell">
  <!-- Decorative side panel -->
  <aside class="auth-side col-lg-5 d-none d-lg-flex">
    <a class="navbar-brand-app mb-4" href="<?php echo url('/index.php'); ?>" style="color:#fff;">
      <img class="brand-logo" src="<?php echo url('/assets/images/logo.png'); ?>" alt="QuizSphere logo" style="height: 2.8rem; filter: brightness(0) invert(1);">
    </a>
    <div>
      <div class="d-inline-flex align-items-center gap-1 mb-3 px-3 py-1 rounded-pill text-white" style="background: rgba(255,255,255,0.18); font-size: 0.82rem; font-weight: 600;">
        <i class="bi bi-rocket-takeoff text-warning"></i> Start Learning Today
      </div>
      <h2 class="display-6 fw-bold mb-3 text-white">Join thousands of proactive learners.</h2>
      <p class="opacity-75 mb-4 text-white">
        Turn lecture notes, textbook chapters, or custom syllabus topics into dynamic, graded quizzes in seconds.
      </p>
      <div class="d-flex flex-column gap-3 text-white">
        <div class="d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill text-warning"></i> 100% Free personalized learning</div>
        <div class="d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill text-warning"></i> AI-powered question synthesis</div>
        <div class="d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill text-warning"></i> Adaptive drills based on concept mastery</div>
      </div>
    </div>
    <div class="d-flex justify-content-between align-items-center text-white opacity-75 small">
      <span>&copy; <?php echo date('Y'); ?> QuizSphere</span>
      <span>Empowering Smart Learning</span>
    </div>
  </aside>

  <!-- Form side -->
  <main class="auth-main">
    <div class="auth-card card p-4 p-sm-5 shadow-sm border-1">
      <div class="text-center mb-4">
        <a href="<?php echo url('/index.php'); ?>" class="d-inline-block mb-3">
          <img class="brand-logo" src="<?php echo url('/assets/images/logo.png'); ?>" alt="QuizSphere logo" style="height: 3.2rem;">
        </a>
        <h1 class="h3 fw-bold text-ink-900 mb-1">Create your account</h1>
        <p class="text-muted-ink small mb-0">Start your personalized learning journey today.</p>
      </div>

      <?php if ($msg = get_flash('error')): ?>
        <div class="alert alert-danger mb-3"><?php echo e($msg); ?></div>
      <?php endif; ?>

      <form data-auth="signup" novalidate>
        <input type="hidden" name="_csrf" value="<?php echo e(Security::csrfToken()); ?>">
        <div class="mb-3">
          <label for="fullname" class="form-label">Full name</label>
          <input type="text" class="form-control" id="fullname" name="fullname" placeholder="Jane Doe" autocomplete="name">
          <div class="feedback feedback-error">Full name is required.</div>
          <div class="feedback feedback-success"></div>
        </div>

        <div class="mb-3">
          <label for="email" class="form-label">Email address</label>
          <input type="email" class="form-control" id="email" name="email" placeholder="you@example.com" autocomplete="email">
          <div class="feedback feedback-error">Please enter a valid email address.</div>
          <div class="feedback feedback-success"></div>
        </div>

        <div class="mb-3">
          <label for="password" class="form-label">Password</label>
          <div class="input-password-wrap">
            <input type="password" class="form-control" id="password" name="password" placeholder="Min. 6 characters" autocomplete="new-password">
            <button type="button" class="toggle-password" data-target="password" aria-label="Show password"><i class="bi bi-eye"></i></button>
          </div>
          <div class="d-flex align-items-center gap-2 mt-2">
            <div class="progress flex-grow-1" style="height:6px;">
              <div class="progress-bar" id="password-strength" role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="4"></div>
            </div>
            <span class="small text-muted-ink" id="password-strength-label"></span>
          </div>
          <div class="feedback feedback-error">Password must be at least 6 characters.</div>
          <div class="feedback feedback-success"></div>
        </div>

        <div class="mb-4">
          <label for="confirm_password" class="form-label">Confirm password</label>
          <div class="input-password-wrap">
            <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Re-enter your password" autocomplete="new-password">
            <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Show password"><i class="bi bi-eye"></i></button>
          </div>
          <div class="feedback feedback-error">Passwords do not match.</div>
          <div class="feedback feedback-success"></div>
        </div>

        <button type="submit" class="btn btn-brand w-100 py-2 mb-3 shadow-sm">
          Create Account <i class="bi bi-arrow-right ms-1"></i>
        </button>

        <div class="form-alert" hidden></div>

        <p class="text-center text-muted-ink small mb-0 mt-3">
          Already have an account?
          <a href="<?php echo url('/login.php'); ?>" class="fw-semibold text-brand text-decoration-none">Sign in</a>
        </p>
      </form>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
