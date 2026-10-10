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

    <div class="row g-4 mb-4">
      <div class="col-12 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <div>
          <div class="d-inline-flex align-items-center gap-2 bg-soft-brand text-brand mb-3 px-3 py-1 rounded-pill fw-bold small text-uppercase tracking-wide border border-brand-100">
            <i class="bi bi-collection-play"></i> Assessment Library
          </div>
          <h1 class="h3 fw-bold text-ink-900 mb-2" style="letter-spacing: -0.02em;">My Quizzes</h1>
          <p class="text-muted-ink mb-0 fs-5" style="max-width: 600px;">
            Manage, filter, and review all your generated quizzes.
          </p>
        </div>
        <div class="d-flex align-items-center gap-2">
          <a href="<?php echo url('/pages/upload.php'); ?>" class="btn btn-outline-brand fw-semibold shadow-xs d-none d-sm-inline-flex transition-normal hover-bg-brand-50">
            <i class="bi bi-file-earmark-pdf me-1"></i>Import PDF
          </a>
          <a href="<?php echo url('/pages/generate.php'); ?>" class="btn btn-brand fw-semibold shadow-brand transition-normal">
            <i class="bi bi-robot me-1"></i>New AI Quiz
          </a>
        </div>
      </div>
    </div>

    <!-- Stats Row -->
    <?php if ($quizzes): ?>
      <?php 
        $totalQuestions = 0;
        foreach ($quizzes as $q) {
          $totalQuestions += (int)($q['question_count'] ?? 0);
        }
      ?>
      <div class="row g-3 g-xl-4 mb-5">
        <div class="col-sm-6 col-lg-4">
          <div class="card bg-surface border-1 border-ink-200 shadow-sm rounded-4 p-4 h-100 d-flex flex-row align-items-center gap-3 transition-normal hover-bg-brand-50">
            <div class="avatar-circle avatar-circle-md bg-brand-100 text-brand flex-shrink-0 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; border-radius: 50%;">
              <i class="bi bi-collection-fill fs-4"></i>
            </div>
            <div>
              <div class="small fw-semibold text-muted-ink text-uppercase tracking-wider mb-1" style="font-size: 0.75rem;">Total Quizzes</div>
              <div class="h3 fw-bold text-ink-900 mb-0"><?php echo count($quizzes); ?></div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-4">
          <div class="card bg-surface border-1 border-ink-200 shadow-sm rounded-4 p-4 h-100 d-flex flex-row align-items-center gap-3 transition-normal hover-bg-brand-50">
            <div class="avatar-circle avatar-circle-md bg-success-subtle text-success flex-shrink-0 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; border-radius: 50%;">
              <i class="bi bi-question-square-fill fs-4"></i>
            </div>
            <div>
              <div class="small fw-semibold text-muted-ink text-uppercase tracking-wider mb-1" style="font-size: 0.75rem;">Total Questions</div>
              <div class="h3 fw-bold text-ink-900 mb-0"><?php echo $totalQuestions; ?></div>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Quiz Library -->
    <div class="card p-0 border-1 border-ink-200 shadow-sm bg-surface rounded-4 overflow-hidden mb-4">
      <div class="p-4 border-bottom border-ink-100 bg-surface-2 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
        <div>
          <h5 class="mb-1 fw-bold text-ink-900">Quiz Archive</h5>
          <p class="text-muted-ink small mb-0">Showing <?php echo count($quizzes); ?> saved quiz<?php echo count($quizzes) === 1 ? '' : 'zes'; ?></p>
        </div>
        
        <?php if ($quizzes): ?>
          <div style="max-width: 300px; width: 100%;">
            <div class="input-group">
              <span class="input-group-text bg-surface border-ink-200 text-muted-ink"><i class="bi bi-search"></i></span>
              <input type="text" class="form-control border-ink-200 bg-surface focus-ring-brand" id="quizSearchInput" placeholder="Filter by title or topic..." onkeyup="filterQuizzes()">
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div class="table-responsive">
        <table class="table align-middle mb-0 table-hover" id="quizTable">
          <thead class="bg-surface">
            <tr class="text-muted-ink small text-uppercase tracking-wider" style="font-size: 0.75rem;">
              <th class="ps-4 fw-semibold border-bottom border-ink-100">Quiz Title</th>
              <th class="fw-semibold border-bottom border-ink-100">Topic</th>
              <th class="fw-semibold border-bottom border-ink-100">Difficulty</th>
              <th class="fw-semibold border-bottom border-ink-100">Created</th>
              <th class="text-end pe-4 fw-semibold border-bottom border-ink-100">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($quizzes): ?>
              <?php foreach ($quizzes as $q): ?>
              <?php
                $diff = strtolower($q['difficulty'] ?? 'medium');
                $diffBadgeClass = 'bg-surface-2 text-ink-800 border-ink-200';
                if ($diff === 'easy') $diffBadgeClass = 'bg-success-subtle text-success border-success-subtle';
                if ($diff === 'hard') $diffBadgeClass = 'bg-danger-soft text-danger-ink border-danger-subtle';
              ?>
              <tr class="quiz-row transition-normal">
                <td class="ps-4">
                  <div class="fw-semibold text-ink-900 quiz-title-col mb-1"><?php echo e($q['title'] ?? 'Quiz'); ?></div>
                  <div class="text-muted-ink small"><i class="bi bi-card-list me-1"></i><?php echo e($q['question_count'] ?? 0); ?> questions</div>
                </td>
                <td><span class="text-muted-ink small quiz-topic-col"><?php echo e($q['topic'] ?? '—'); ?></span></td>
                <td><span class="badge border shadow-xs rounded-pill px-3 <?php echo $diffBadgeClass; ?>"><?php echo e(ucfirst($diff)); ?></span></td>
                <td class="text-muted-ink small"><i class="bi bi-calendar3 me-1"></i><?php echo e(date('M j, Y', strtotime((string) ($q['created_at'] ?? 'now')))); ?></td>
                <td class="text-end pe-4">
                  <a href="<?php echo url('/pages/quiz.php?id=' . urlencode($q['id'])); ?>" class="btn btn-sm bg-surface-2 border-ink-200 text-ink-800 fw-semibold transition-normal hover-bg-brand-50 shadow-xs text-nowrap">
                    Take Quiz <i class="bi bi-arrow-right ms-1 text-brand"></i>
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="5" class="text-center py-5">
                  <div class="py-4">
                    <div class="avatar-circle avatar-circle-lg bg-surface-2 text-ink-300 mx-auto mb-3" style="width: 80px; height: 80px; border-radius: 50%;">
                      <i class="bi bi-journal-plus fs-1"></i>
                    </div>
                    <h5 class="fw-bold text-ink-900 mb-2">No quizzes generated yet</h5>
                    <p class="text-muted-ink small mb-4 mx-auto" style="max-width: 400px;">Create your first quiz using AI or by uploading study material.</p>
                    <a href="<?php echo url('/pages/generate.php'); ?>" class="btn btn-brand px-4 py-2 rounded-pill fw-semibold shadow-brand transition-normal">
                      <i class="bi bi-magic me-2"></i>Generate Your First Quiz
                    </a>
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
  let visibleCount = 0;
  
  rows.forEach(row => {
    const title = row.querySelector('.quiz-title-col')?.textContent.toLowerCase() || '';
    const topic = row.querySelector('.quiz-topic-col')?.textContent.toLowerCase() || '';
    if (title.includes(filter) || topic.includes(filter)) {
      row.style.display = '';
      visibleCount++;
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
