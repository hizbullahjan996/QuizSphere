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
    <div class="topbar">
      <div>
        <div class="d-inline-flex align-items-center gap-2 badge-soft text-brand mb-2">
          <i class="bi bi-collection-play"></i> Assessment Library
        </div>
        <h1 class="h4 fw-bold text-ink-900 mb-1">My Quizzes</h1>
        <p class="text-muted-ink mb-0">Browse, filter, and retake your generated and uploaded assessments.</p>
      </div>
      <div class="d-flex align-items-center gap-2">
        <a href="<?php echo url('/pages/generate.php'); ?>" class="btn btn-brand">
          <i class="bi bi-robot me-1"></i>New Quiz
        </a>
        <a href="<?php echo url('/pages/upload.php'); ?>" class="btn btn-outline-brand d-none d-sm-inline-flex">
          <i class="bi bi-file-earmark-pdf me-1"></i>Upload PDF
        </a>
      </div>
    </div>

    <div class="card p-4">
      <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
        <div>
          <h5 class="mb-1 fw-bold text-ink-900">Quiz Archive</h5>
          <p class="text-muted-ink small mb-0">
            Showing <?php echo count($quizzes); ?> saved quiz<?php echo count($quizzes) === 1 ? '' : 'zes'; ?>
          </p>
        </div>
        <?php if ($quizzes): ?>
          <div style="max-width: 280px; width: 100%;">
            <div class="input-group input-group-sm">
              <span class="input-group-text bg-surface border-end-0 text-muted-ink"><i class="bi bi-search"></i></span>
              <input type="text" class="form-control border-start-0" id="quizSearchInput" placeholder="Filter by title or topic..." onkeyup="filterQuizzes()">
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div class="table-responsive">
        <table class="table align-middle mb-0" id="quizTable">
          <thead>
            <tr class="text-muted-ink small">
              <th>Quiz Title</th>
              <th>Topic</th>
              <th>Difficulty</th>
              <th>Created</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($quizzes): ?>
              <?php foreach ($quizzes as $q): ?>
              <?php
                $diff = strtolower($q['difficulty'] ?? 'medium');
                $diffBadgeClass = 'badge-difficulty-' . ($diff === 'easy' ? 'easy' : ($diff === 'hard' ? 'hard' : 'medium'));
              ?>
              <tr class="quiz-row">
                <td>
                  <div class="fw-semibold text-ink-900 quiz-title-col"><?php echo e($q['title'] ?? 'Quiz'); ?></div>
                </td>
                <td><span class="text-muted-ink small quiz-topic-col"><?php echo e($q['topic'] ?? '—'); ?></span></td>
                <td><span class="badge-soft <?php echo $diffBadgeClass; ?>"><?php echo e(ucfirst($diff)); ?></span></td>
                <td class="text-muted-ink small"><?php echo e(date('M j, Y', strtotime((string) ($q['created_at'] ?? 'now')))); ?></td>
                <td class="text-end">
                  <a href="<?php echo url('/pages/quiz.php?id=' . urlencode($q['id'])); ?>" class="btn btn-sm btn-outline-brand py-1 px-3 text-nowrap">
                    Take Quiz <i class="bi bi-arrow-right ms-1"></i>
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="5" class="text-center text-muted-ink py-5">
                  <div class="py-3">
                    <i class="bi bi-journal-plus fs-1 text-brand d-block mb-2"></i>
                    <h6 class="fw-bold text-ink-900 mb-1">No quizzes generated yet</h6>
                    <p class="text-muted-ink small mb-3">Create your first quiz using AI or by uploading study material.</p>
                    <a href="<?php echo url('/pages/generate.php'); ?>" class="btn btn-sm btn-brand">Generate Your First Quiz</a>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<script>
function filterQuizzes() {
  const input = document.getElementById('quizSearchInput');
  const filter = input ? input.value.toLowerCase() : '';
  const rows = document.querySelectorAll('.quiz-row');
  rows.forEach(row => {
    const title = row.querySelector('.quiz-title-col')?.textContent.toLowerCase() || '';
    const topic = row.querySelector('.quiz-topic-col')?.textContent.toLowerCase() || '';
    if (title.includes(filter) || topic.includes(filter)) {
      row.style.display = '';
    } else {
      row.style.display = 'none';
    }
  });
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
