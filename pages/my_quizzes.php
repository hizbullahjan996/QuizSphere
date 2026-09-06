<?php
/**
 * QuizSphere - My Quizzes list page
 * -------------------------------------------------------------
 * Authenticated. Server-renders the authenticated user's quizzes (most recent
 * first) with a "Take" action, mirroring the dashboard's Recent Quizzes table.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

try {
    $client = SupabaseClient::fromConfig();
    $auth = new Auth($client);
    $repo = new QuizRepository($client);
} catch (SupabaseException $e) {
    $auth = null;
}

if (!$auth || !$auth->check()) {
    set_flash('error', 'Please log in to view your quizzes.');
    redirect('/login.php');
}

$token = $auth->accessToken();
$quizzes = [];
if ($token) {
    try {
        $quizzes = $repo->listUserQuizzes($auth->id(), $token, 50);
    } catch (SupabaseException $e) {
        $quizzes = [];
    }
}

$page_title = 'My Quizzes';
$active_page = 'pages';
require __DIR__ . '/../includes/head.php';
?>
<body>
<div class="dashboard-shell">
  <?php $dashboard_active = 'quizzes'; require __DIR__ . '/../includes/sidebar.php'; ?>

  <main class="dashboard-main">
    <div class="row justify-content-center">
      <div class="col-lg-10">

        <div class="d-flex justify-content-between align-items-center mb-4">
          <h1 class="h3 mb-0">My Quizzes</h1>
          <a href="<?php echo url('/pages/generate.php'); ?>" class="btn btn-brand">
            <i class="bi bi-magic me-1"></i>Generate
          </a>
        </div>

        <div class="card p-4">
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr class="text-muted-ink small">
                  <th>Quiz</th>
                  <th>Topic</th>
                  <th>Level</th>
                  <th>Date</th>
                  <th class="text-end"></th>
                </tr>
              </thead>
              <tbody>
                <?php if ($quizzes): ?>
                  <?php foreach ($quizzes as $q): ?>
                  <tr>
                    <td class="fw-semibold"><?php echo e($q['title'] ?? 'Quiz'); ?></td>
                    <td><?php echo e($q['topic'] ?? '—'); ?></td>
                    <td><span class="badge-soft"><?php echo e(ucfirst($q['difficulty'] ?? 'medium')); ?></span></td>
                    <td class="text-muted-ink"><?php echo e(date('M j, Y', strtotime((string) ($q['created_at'] ?? 'now')))); ?></td>
                    <td class="text-end">
                      <a href="<?php echo url('/pages/quiz.php?id=' . urlencode($q['id'])); ?>" class="small">Take</a>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="5" class="text-center text-muted-ink py-4">
                      No quizzes yet. <a href="<?php echo url('/pages/generate.php'); ?>">Generate your first quiz</a>.
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
