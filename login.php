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
      <img class="brand-logo" src="<?php echo url('/assets/images/logo-white.png'); ?>" alt="QuizSphere logo" style="height: 2.8rem;">
    </a>
    <div>
      <div class="d-inline-flex align-items-center gap-1 mb-3 px-3 py-1 rounded-pill text-white" style="background: rgba(255,255,255,0.18); font-size: 0.82rem; font-weight: 600;">
        <i class="bi bi-stars text-warning"></i> AI-Powered Assessment Platform
      </div>
      <h2 class="display-6 fw-bold mb-3 text-white">Master any topic with adaptive quizzes.</h2>
      <p class="opacity-75 mb-4 text-white">
        Log in to track your learning velocity, consult your personal AI Coach, and unlock verified credentials.
      </p>
      <div class="d-flex flex-column gap-3 text-white">
        <div class="d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill text-warning"></i> Personalized AI quiz generation</div>
        <div class="d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill text-warning"></i> Real-time mastery analytics &amp; streak tracking</div>
        <div class="d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill text-warning"></i> Verifiable certificates of achievement</div>
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
        <h1 class="h3 fw-bold text-ink-900 mb-1">Sign in to QuizSphere</h1>
        <p class="text-muted-ink small mb-0">Enter your credentials to continue your learning journey.</p>
      </div>

      <?php if ($msg = get_flash('error')): ?>
        <div class="alert alert-danger mb-3"><?php echo e($msg); ?></div>
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
            <a href="#" class="small text-muted-ink text-decoration-none" onclick="alert('Password reset instructions will be sent to your email.'); return false;">Forgot password?</a>
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
            <label class="form-check-label small text-muted-ink" for="remember">Keep me signed in</label>
          </div>
        </div>

        <button type="submit" class="btn btn-brand w-100 py-2 mb-3 shadow-sm">
          Sign In <i class="bi bi-box-arrow-in-right ms-1"></i>
        </button>

        <div class="form-alert" hidden></div>

        <p class="text-center text-muted-ink small mb-0 mt-3">
          Don't have an account?
          <a href="<?php echo url('/register.php'); ?>" class="fw-semibold text-brand text-decoration-none">Create an account</a>
        </p>
      </form>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
