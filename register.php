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
      <img class="brand-logo" src="<?php echo url('/assets/images/logo.png'); ?>" alt="QuizSphere logo">
      QuizSphere
    </a>
    <div>
      <h2 class="mb-3">Start learning with AI.</h2>
      <p class="opacity-75 mb-4">
        Join thousands of learners turning any topic into an intelligent experience.
      </p>
      <div class="d-flex flex-column gap-3">
        <div class="d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill"></i> Free forever plan</div>
        <div class="d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill"></i> AI-generated quizzes</div>
        <div class="d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill"></i> Adaptive practice paths</div>
      </div>
    </div>
    <p class="opacity-75 small mb-0">&copy; <?php echo date('Y'); ?> QuizSphere</p>
  </aside>

  <!-- Form side -->
  <main class="auth-main">
    <div class="auth-card">
      <div class="text-center mb-4">
        <img class="brand-logo-lg mb-3" src="<?php echo url('/assets/images/logo.png'); ?>" alt="QuizSphere logo">
        <h1 class="h3 mb-1">Create your account</h1>
        <p class="text-muted-ink mb-0">Start your personalized learning journey.</p>
      </div>

      <?php if ($msg = get_flash('error')): ?>
        <div class="alert alert-danger"><?php echo e($msg); ?></div>
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

        <button type="submit" class="btn btn-brand w-100 py-2 mb-3">
          Create Account <i class="bi bi-arrow-right ms-1"></i>
        </button>

        <div class="form-alert" hidden></div>

        <p class="text-center text-muted-ink mb-0">
          Already have an account?
          <a href="<?php echo url('/login.php'); ?>">Sign in</a>
        </p>
      </form>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
