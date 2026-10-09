<?php
/** QuizSphere - Site footer fragment. */
?>
<footer class="footer-quiz" id="about">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-4">
        <a class="navbar-brand-app mb-2" href="<?php echo url('/index.php'); ?>" style="color:#fff;">
          <img class="brand-logo" src="<?php echo url('/assets/images/logo-white.png'); ?>" alt="QuizSphere logo">
        </a>
        <p class="mt-3 mb-0">
          An AI-powered adaptive learning platform. Turn any topic into an
          intelligent learning experience and track your progress.
        </p>
      </div>
      <div class="col-6 col-lg-2">
        <h5>Platform</h5>
        <ul class="list-unstyled mb-0">
          <li><a href="<?php echo url('/index.php#features'); ?>">Features</a></li>
          <li><a href="<?php echo url('/index.php#how'); ?>">How It Works</a></li>
          <li><a href="<?php echo url('/login.php'); ?>">Login</a></li>
          <li><a href="<?php echo url('/register.php'); ?>">Sign Up</a></li>
          <li><a href="<?php echo url('/verify-certificate.php'); ?>">Verify a Certificate</a></li>
        </ul>
      </div>
      <div class="col-6 col-lg-3">
        <h5>Company</h5>
        <ul class="list-unstyled mb-0">
          <li><a href="<?php echo url('/index.php#about'); ?>">About</a></li>
          <li><a href="#">Privacy</a></li>
          <li><a href="#">Terms</a></li>
          <li><a href="#">Contact</a></li>
        </ul>
      </div>
      <div class="col-lg-3">
        <h5>Follow Us</h5>
<div class="d-flex gap-3 fs-5">
    <a href="https://www.linkedin.com/in/hizb-ullah-jan-996h"
       target="_blank"
       rel="noopener noreferrer"
       aria-label="LinkedIn">
        <i class="bi bi-linkedin"></i>
    </a>

    <a href="https://github.com/hizbullahjan996/QuizSphere"
       target="_blank"
       rel="noopener noreferrer"
       aria-label="GitHub">
        <i class="bi bi-github"></i>
    </a>
</div>
      </div>
    </div>
    <div class="footer-bottom d-flex flex-wrap justify-content-between align-items-center">
      <span>&copy; <?php echo date('Y'); ?> QuizSphere. Crafted for the hackathon.</span>
      <span>Made for learners, by learners.</span>
    </div>
  </div>
</footer>
