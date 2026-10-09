<?php
/**
 * QuizSphere - Dashboard sidebar fragment.
 * Expects: $dashboard_active (string) e.g. 'dashboard', 'generate', 'upload', 'coach', 'quizzes', 'leaderboard', 'certificates'.
 */
$dashboard_active = $dashboard_active ?? 'dashboard';

// Derive learner identity gracefully from session / profile data
$sidebarUser = $_SESSION['user'] ?? null;
$sidebarProfile = $_SESSION['profile'] ?? [];
$sidebarName = trim($profile['full_name'] ?? ($sidebarUser['full_name'] ?? ''));
if ($sidebarName === '') {
    $sidebarName = 'Learner';
}
$firstChar = function_exists('mb_substr') ? mb_substr($sidebarName, 0, 1) : substr($sidebarName, 0, 1);
$sidebarInitial = function_exists('mb_strtoupper') ? mb_strtoupper($firstChar ?: 'L') : strtoupper($firstChar ?: 'L');
$sidebarXp = (int) ($profile['xp'] ?? ($sidebarProfile['xp'] ?? ($sidebarUser['xp'] ?? 0)));
$sidebarLevel = (int) ($profile['level'] ?? ($sidebarProfile['level'] ?? ($sidebarUser['level'] ?? 1)));

if (!function_exists('dash_link')) {
    function dash_link(string $key, string $href, string $icon, string $label): string
    {
        global $dashboard_active;
        $active = $dashboard_active === $key ? ' active' : '';
        return '<a class="sidebar-link' . $active . '" href="' . e($href) . '">' .
               '<i class="bi ' . $icon . '"></i><span>' . e($label) . '</span></a>';
    }
}
?>

<!-- Mobile Topbar (< 992px) -->
<header class="mobile-topbar d-lg-none">
  <button type="button" class="btn btn-ghost p-1" id="mobileMenuToggle" aria-label="Open navigation menu">
    <i class="bi bi-list fs-3"></i>
  </button>
  <a class="navbar-brand-app" href="<?php echo url('/pages/dashboard.php'); ?>">
    <img class="brand-logo" src="<?php echo url('/assets/images/logo.png'); ?>" alt="QuizSphere logo">
  </a>
  <div class="avatar-circle avatar-circle-sm" title="<?php echo e($sidebarName); ?>">
    <?php echo e($sidebarInitial); ?>
  </div>
</header>

<!-- Mobile Drawer Backdrop -->
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<!-- Application Sidebar (Desktop persistent / Mobile slide-out drawer) -->
<aside class="sidebar" id="appSidebar">
  <input type="hidden" name="_csrf" value="<?php echo e(Security::csrfToken()); ?>">

  <!-- Brand Header -->
  <div class="sidebar-header d-flex align-items-center justify-content-between mb-3">
    <a class="navbar-brand-app sidebar-brand" href="<?php echo url('/pages/dashboard.php'); ?>">
      <img class="brand-logo" src="<?php echo url('/assets/images/logo-white.png'); ?>" alt="QuizSphere logo">
    </a>
    <button type="button" class="btn btn-ghost d-lg-none p-1 text-muted-ink" id="sidebarCloseBtn" aria-label="Close navigation menu">
      <i class="bi bi-x-lg fs-5"></i>
    </button>
  </div>

  <!-- Learner Profile Card -->
  <div class="sidebar-profile-card mb-3">
    <div class="d-flex align-items-center gap-2">
      <div class="avatar-circle avatar-circle-md flex-shrink-0">
        <?php echo e($sidebarInitial); ?>
      </div>
      <div class="flex-grow-1 min-w-0">
        <div class="sidebar-user-name fw-bold text-truncate" id="sidebarUserName"><?php echo e($sidebarName); ?></div>
        <div class="d-flex align-items-center gap-2 small mt-1">
          <span class="badge-soft badge-sm" id="sidebarUserLevel">Level <?php echo $sidebarLevel; ?></span>
          <span class="sidebar-user-xp" id="sidebarUserXp"><?php echo number_format($sidebarXp); ?> XP</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Navigation Groups -->
  <div class="sidebar-nav-wrap flex-grow-1">
    <div class="sidebar-section-title">Learn</div>
    <nav class="d-flex flex-column gap-1 mb-3">
      <?php echo dash_link('dashboard', url('/pages/dashboard.php'), 'bi-grid-1x2', 'Dashboard'); ?>
      <?php echo dash_link('generate', url('/pages/generate.php'), 'bi-magic', 'Generate Quiz'); ?>
      <?php echo dash_link('upload', url('/pages/upload.php'), 'bi-file-earmark-richtext', 'Upload Material'); ?>
      <?php echo dash_link('coach', url('/pages/coach.php'), 'bi-stars', 'AI Coach'); ?>
    </nav>

    <div class="sidebar-section-title">Progress</div>
    <nav class="d-flex flex-column gap-1 mb-2">
      <?php echo dash_link('quizzes', url('/pages/my_quizzes.php'), 'bi-pencil-square', 'My Quizzes'); ?>
      <?php echo dash_link('leaderboard', url('/pages/leaderboard.php'), 'bi-trophy', 'Leaderboard'); ?>
      <?php echo dash_link('certificates', url('/pages/certificates.php'), 'bi-award', 'Certificates'); ?>
    </nav>
  </div>

  <!-- Bottom Section: Logout -->
  <div class="sidebar-footer pt-3 mt-auto border-top">
    <button type="button" class="sidebar-link sidebar-logout border-0 bg-transparent w-100 text-start" data-logout>
      <i class="bi bi-box-arrow-right"></i><span>Log out</span>
    </button>
  </div>
</aside>
