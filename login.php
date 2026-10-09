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

<div class="auth-shell bg-surface-2 d-flex min-vh-100">
  <!-- Decorative side panel -->
  <aside class="auth-side col-lg-5 d-none d-lg-flex flex-column justify-content-between p-5 position-relative overflow-hidden" style="background-color: var(--sidebar-bg);">
    <!-- Decorative background elements -->
    <div class="position-absolute rounded-circle" style="width: 600px; height: 600px; background: radial-gradient(circle, var(--brand-600) 0%, transparent 70%); opacity: 0.15; top: -10%; left: -20%; filter: blur(60px); z-index: 0;"></div>
    <div class="position-absolute rounded-circle" style="width: 500px; height: 500px; background: radial-gradient(circle, var(--accent-500) 0%, transparent 70%); opacity: 0.1; bottom: -10%; right: -10%; filter: blur(60px); z-index: 0;"></div>

    <div class="position-relative z-1 mb-5">
      <a class="navbar-brand-app d-inline-block mb-5" href="<?php echo url('/index.php'); ?>">
        <img class="brand-logo" src="<?php echo url('/assets/images/logo-white.png'); ?>" alt="QuizSphere logo" style="height: 2.8rem; object-fit: contain;">
      </a>
      
      <h2 class="display-5 fw-bold mb-4 text-white" style="letter-spacing: -0.03em; line-height: 1.1;">Welcome Back to Smarter Learning</h2>
      <p class="text-brand-100 fs-5 mb-0" style="opacity: 0.85; max-width: 90%; line-height: 1.6;">
        Generate personalized quizzes, target your weak areas, and track your progress with AI-powered analytics.
      </p>
    </div>

    <!-- Dashboard visual -->
    <div class="position-relative z-1 mt-auto mb-4 w-100">
      <div class="card bg-sidebar-card border-0 shadow-lg rounded-4 p-3" style="background-color: var(--sidebar-card-bg); transform: perspective(1000px) rotateY(5deg) rotateX(2deg); transform-style: preserve-3d;">
        <div class="card-body bg-surface rounded-3 p-4 shadow-sm border border-ink-100">
          <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="d-flex align-items-center gap-2">
              <div class="avatar-circle avatar-circle-sm bg-brand-100 text-brand fw-bold d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; border-radius: 50%;">Q</div>
              <div class="fw-bold text-ink-900 small">Weekly Progress</div>
            </div>
            <span class="badge bg-success-subtle text-success rounded-pill px-2 py-1 border border-success-subtle" style="font-size: 0.7rem;">+15% Mastery</span>
          </div>
          
          <div class="row g-2 mb-3">
             <div class="col-6">
               <div class="p-2 bg-surface-2 rounded-3 border border-ink-100 text-center">
                  <div class="text-muted-ink" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em;">Total XP</div>
                  <div class="fw-bold fs-5 text-warning"><i class="bi bi-star-fill me-1 small"></i>2,450</div>
               </div>
             </div>
             <div class="col-6">
               <div class="p-2 bg-surface-2 rounded-3 border border-ink-100 text-center">
                  <div class="text-muted-ink" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em;">Streak</div>
                  <div class="fw-bold fs-5 text-brand"><i class="bi bi-fire me-1 small"></i>14 Days</div>
               </div>
             </div>
          </div>
          
          <!-- Mock Chart -->
          <div class="d-flex align-items-end justify-content-between pt-3 pb-1 px-2 border-top border-ink-100" style="height: 60px;">
             <div style="width: 12%; background: var(--brand-200); border-radius: 2px 2px 0 0; height: 40%;"></div>
             <div style="width: 12%; background: var(--brand-300); border-radius: 2px 2px 0 0; height: 60%;"></div>
             <div style="width: 12%; background: var(--brand-400); border-radius: 2px 2px 0 0; height: 35%;"></div>
             <div style="width: 12%; background: var(--brand-500); border-radius: 2px 2px 0 0; height: 80%;"></div>
             <div style="width: 12%; background: var(--brand-600); border-radius: 2px 2px 0 0; height: 100%;"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="position-relative z-1 d-flex justify-content-between align-items-center text-white small mt-4" style="opacity: 0.6;">
      <span>&copy; <?php echo date('Y'); ?> QuizSphere</span>
      <span>Empowering Smart Learning</span>
    </div>
  </aside>

  <!-- Form side -->
  <main class="auth-main flex-grow-1 d-flex align-items-center justify-content-center p-4" style="background-color: var(--surface-2);">
    <div class="w-100" style="max-width: 440px;">
      
      <!-- Mobile Logo -->
      <div class="d-lg-none text-center mb-4">
        <a href="<?php echo url('/index.php'); ?>" class="d-inline-block">
          <img class="brand-logo" src="<?php echo url('/assets/images/logo.png'); ?>" alt="QuizSphere logo" style="height: 3rem;">
        </a>
      </div>
      
      <div class="card p-4 p-sm-5 border-1 border-ink-200 shadow-sm bg-surface rounded-4">
        <div class="mb-4">
          <h1 class="h3 fw-bold text-ink-900 mb-2" style="letter-spacing: -0.02em;">Welcome back</h1>
          <p class="text-muted-ink small mb-0">Please sign in to your account.</p>
        </div>

        <?php if ($msg = get_flash('error')): ?>
          <div class="alert alert-danger bg-danger-soft text-danger-ink border-danger-soft mb-4 py-2 px-3 small rounded-3 d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill"></i> <?php echo e($msg); ?>
          </div>
        <?php endif; ?>

        <form data-auth="login" novalidate>
          <input type="hidden" name="_csrf" value="<?php echo e(Security::csrfToken()); ?>">
          
          <div class="mb-3">
            <label for="email" class="form-label fw-semibold text-ink-800 small mb-1">Email address</label>
            <input type="email" class="form-control px-3 py-2 border-ink-200 bg-surface focus-ring-brand rounded-3 transition-normal" id="email" name="email" placeholder="name@example.com" autocomplete="email" required>
            <div class="feedback feedback-error small text-danger mt-1">Please enter a valid email address.</div>
            <div class="feedback feedback-success"></div>
          </div>

          <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <label for="password" class="form-label fw-semibold text-ink-800 small mb-0">Password</label>
              <a href="#" class="small text-brand fw-medium text-decoration-none transition-normal" onclick="alert('Password reset instructions will be sent to your email.'); return false;">Forgot password?</a>
            </div>
            <div class="input-password-wrap position-relative">
              <input type="password" class="form-control px-3 py-2 border-ink-200 bg-surface focus-ring-brand rounded-3 transition-normal" id="password" name="password" placeholder="••••••••" autocomplete="current-password" required>
              <button type="button" class="toggle-password btn btn-link text-muted-ink position-absolute end-0 top-50 translate-middle-y p-2 text-decoration-none border-0 shadow-none" data-target="password" aria-label="Show password">
                <i class="bi bi-eye"></i>
              </button>
            </div>
            <div class="feedback feedback-error small text-danger mt-1">Password is required.</div>
            <div class="feedback feedback-success"></div>
          </div>

          <div class="mb-4">
            <div class="form-check d-flex align-items-center gap-2">
              <input class="form-check-input mt-0 border-ink-300" type="checkbox" id="remember" name="remember" style="width: 1rem; height: 1rem;">
              <label class="form-check-label small text-muted-ink" for="remember" style="user-select: none;">Keep me signed in</label>
            </div>
          </div>

          <button type="submit" class="btn btn-brand w-100 py-2 fw-semibold rounded-3 shadow-brand transition-normal d-flex align-items-center justify-content-center gap-2">
            <span>Sign In</span>
            <i class="bi bi-box-arrow-in-right"></i>
          </button>

          <div class="form-alert mt-3" hidden></div>
        </form>
      </div>
      
      <p class="text-center text-muted-ink small mt-4 mb-0">
        Don't have an account?
        <a href="<?php echo url('/register.php'); ?>" class="fw-semibold text-brand text-decoration-none ms-1 transition-normal">Create an account</a>
      </p>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
