<?php
/**
 * QuizSphere - Dashboard sidebar fragment.
 * Expects: $dashboard_active (string) e.g. 'dashboard', 'quizzes', 'practice'.
 */
$dashboard_active = $dashboard_active ?? 'dashboard';
function dash_link(string $key, string $href, string $icon, string $label): string
{
    global $dashboard_active;
    $active = $dashboard_active === $key ? ' active' : '';
    return '<a class="sidebar-link' . $active . '" href="' . e($href) . '">' .
           '<i class="bi ' . $icon . '"></i><span>' . e($label) . '</span></a>';
}
?>
<aside class="sidebar">
  <input type="hidden" name="_csrf" value="<?php echo e(Security::csrfToken()); ?>">
  <a class="navbar-brand-app sidebar-brand mb-4" href="<?php echo url('/index.php'); ?>">
    <img class="brand-logo" src="<?php echo url('/assets/images/logo.png'); ?>" alt="QuizSphere logo">
  </a>
  <nav class="d-flex flex-column gap-1">
    <?php echo dash_link('dashboard', url('/pages/dashboard.php'), 'bi-grid-1x2', 'Dashboard'); ?>
    <?php echo dash_link('generate', url('/pages/generate.php'), 'bi-magic', 'Generate Quiz'); ?>
    <?php echo dash_link('upload', url('/pages/upload.php'), 'bi-file-earmark-richtext', 'Upload Study Material'); ?>
    <?php echo dash_link('coach', url('/pages/coach.php'), 'bi-stars', 'AI Coach'); ?>
    <?php echo dash_link('quizzes', url('/pages/my_quizzes.php'), 'bi-pencil-square', 'Quizzes'); ?>
    <?php echo dash_link('leaderboard', url('/pages/leaderboard.php'), 'bi-trophy', 'Leaderboard'); ?>
    <?php echo dash_link('certificates', url('/pages/certificates.php'), 'bi-award', 'Certificates'); ?>
  </nav>
  <hr class="my-3">
  <button type="button" class="sidebar-link border-0 bg-transparent w-100 text-start" data-logout>
    <i class="bi bi-box-arrow-right"></i><span>Log out</span>
  </button>
</aside>
