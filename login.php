<?php
/**
 * QuizSphere - Login page
 * Authenticates via api/auth.php (server-side, Supabase).
 */
require_once __DIR__ . '/includes/bootstrap.php';
$page_title  = 'Login';
$active_page = 'login';
require __DIR__ . '/includes/head.php';
?>
<body>

<div class="auth-shell">
  <!-- Decorative side panel -->
  <aside class="auth-side col-lg-5 d-none d-lg-flex">
    <a class="navbar-brand-app mb-4" href="<?php echo url('/index.php'); ?>" style="color:#fff;">
      <img class="brand-logo" src="<?php echo url('/assets/images/logo.png'); ?>" alt="QuizSphere logo">
    </a>
    <div>
      <h2 class="mb-3">Welcome back, learner.</h2>
      <p class="opacity-75 mb-4">
        Continue your adaptive learning journey and pick up where you left off.
      </p>
      <div class="d-flex flex-column gap-3">
        <div class="d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill"></i> Personalized quiz recommendations</div>
        <div class="d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill"></i> Track your progress and streaks</div>
        <div class="d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill"></i> Earn certificates for mastery</div>
      </div>
    </div>
    <p class="opacity-75 small mb-0">&copy; <?php echo date('Y'); ?> QuizSphere</p>
  </aside>

  <!-- Form side -->
  <main class="auth-main">
    <div class="auth-card">
      <div class="text-center mb-4">
        <img class="brand-logo-lg mb-3" src="<?php echo url('/assets/images/logo.png'); ?>" alt="QuizSphere logo">
        <h1 class="h3 mb-1">Sign in to QuizSphere</h1>
        <p class="text-muted-ink mb-0">Enter your details to continue your learning.</p>
      </div>

      <?php if ($msg = get_flash('error')): ?>
        <div class="alert alert-danger"><?php echo e($msg); ?></div>
      <?php endif; ?>

      <form data-auth="login" novalidate>
        <input type="hidden" name="_csrf" value="<?php echo e(Security::csrfToken()); ?>">
        <div class="mb-3">
          <label for="email" class="form-label">Email address</label>
          <input type="email" class="form-control" id="email" name="email" placeholder="you@example.com" autocomplete="email">
          <div class="feedback feedback-error">Please enter a valid email address.</div>
          <div class="feedback feedback-success"></div>
        </div>

        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center">
            <label for="password" class="form-label">Password</label>
            <a href="#" class="small">Forgot password?</a>
          </div>
          <div class="input-password-wrap">
            <input type="password" class="form-control" id="password" name="password" placeholder="Your password" autocomplete="current-password">
            <button type="button" class="toggle-password" data-target="password" aria-label="Show password"><i class="bi bi-eye"></i></button>
          </div>
          <div class="feedback feedback-error">Password is required.</div>
          <div class="feedback feedback-success"></div>
        </div>

        <div class="mb-4">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="remember" name="remember">
            <label class="form-check-label" for="remember">Remember me</label>
          </div>
        </div>

        <button type="submit" class="btn btn-brand w-100 py-2 mb-3">
          Login <i class="bi bi-box-arrow-in-right ms-1"></i>
        </button>

        <div class="form-alert" hidden></div>

        <p class="text-center text-muted-ink mb-0">
          Don't have an account?
          <a href="<?php echo url('/register.php'); ?>">Create one now</a>
        </p>
      </form>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
