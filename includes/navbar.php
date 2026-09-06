<?php
/**
 * QuizSphere - Site navbar fragment.
 * Expects: $active_page (string) e.g. 'home', 'features', 'how', 'about'.
 */
$active_page = $active_page ?? '';
function nav_link(string $key, string $href, string $label): string
{
    global $active_page;
    $active = $active_page === $key ? ' active' : '';
    return '<a class="nav-link' . $active . '" href="' . e($href) . '">' . e($label) . '</a>';
}
?>
<nav class="navbar navbar-expand-lg navbar-quiz sticky-top">
  <div class="container">
    <a class="navbar-brand-app" href="<?php echo url('/index.php'); ?>">
      <img class="brand-logo" src="<?php echo url('/assets/images/logo.png'); ?>" alt="QuizSphere logo">
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#siteNav" aria-controls="siteNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="siteNav">
      <ul class="navbar-nav mx-auto mb-2 mb-lg-0">
        <li class="nav-item"><?php echo nav_link('home', url('/index.php'), 'Home'); ?></li>
        <li class="nav-item"><?php echo nav_link('features', url('/index.php#features'), 'Features'); ?></li>
        <li class="nav-item"><?php echo nav_link('how', url('/index.php#how'), 'How It Works'); ?></li>
        <li class="nav-item"><?php echo nav_link('about', url('/index.php#about'), 'About'); ?></li>
      </ul>
      <div class="d-flex align-items-center gap-2">
        <a href="<?php echo url('/login.php'); ?>" class="btn btn-ghost">Login</a>
        <a href="<?php echo url('/register.php'); ?>" class="btn btn-brand">Get Started</a>
      </div>
    </div>
  </div>
</nav>
