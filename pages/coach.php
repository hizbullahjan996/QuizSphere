<?php
/**
 * QuizSphere - AI Learning Coach
 * -------------------------------------------------------------
 * Authenticated. A chat-style interface that gives personalized, data-grounded
 * study guidance using the student's real performance data (via api/coach.php).
 */
require_once __DIR__ . '/../includes/bootstrap.php';

try {
    $client = SupabaseClient::fromConfig();
    $auth = new Auth($client);
} catch (SupabaseException $e) {
    $auth = null;
}

if (!$auth || !$auth->check()) {
    set_flash('error', 'Please log in to use the AI Learning Coach.');
    redirect('/login.php');
}

$user = $auth->user();
$page_title = 'AI Learning Coach';
$active_page = 'pages';
require __DIR__ . '/../includes/head.php';
?>
<body>
<div class="dashboard-shell">
  <?php $dashboard_active = 'coach'; require __DIR__ . '/../includes/sidebar.php'; ?>

  <main class="dashboard-main p-0 d-flex flex-column h-100">
    <div class="d-flex flex-column h-100" id="coach-app" style="max-height: calc(100vh - 60px);">
      <!-- Header -->
      <div class="bg-surface border-bottom border-ink-100 p-3 px-md-4 d-flex align-items-center gap-3 flex-shrink-0 z-1">
        <div class="avatar-circle avatar-circle-sm bg-brand-50 text-brand d-flex align-items-center justify-content-center flex-shrink-0" style="width: 42px; height: 42px; border-radius: 50%;">
          <i class="bi bi-robot fs-5"></i>
        </div>
        <div>
          <h1 class="h6 fw-bold text-ink-900 mb-0 lh-1">QuizSphere AI Coach</h1>
          <p class="small text-muted-ink mb-0">Your personal assistant for learning and quiz practice</p>
        </div>
      </div>

      <!-- Messages Stream -->
      <div class="flex-grow-1 overflow-auto p-3 p-md-4 bg-surface-2 position-relative" id="coach-messages">
        <!-- Initial Message -->
        <div class="d-flex align-items-start gap-3 coach-msg coach-msg-coach mb-4 w-85" data-msg>
          <div class="avatar-circle avatar-circle-sm bg-brand-100 text-brand d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px; border-radius: 50%;">
            <i class="bi bi-stars"></i>
          </div>
          <div class="coach-bubble bg-surface border border-ink-200 text-ink-800 p-3 rounded-4 rounded-top-0 shadow-xs lh-base">
            Hi! I'm your AI Learning Coach.<br><br>
            Ask me what to study next, which concepts need work, or how to master tricky topics — I'll tailor my guidance directly to your quiz results.
          </div>
        </div>

        <!-- Quick Prompt Suggestions (Only shown at start) -->
        <div class="d-flex flex-column align-items-center justify-content-center my-5" id="coach-suggestions-wrapper">
          <div class="small fw-bold text-ink-700 text-uppercase tracking-wider mb-3 d-flex align-items-center gap-2">
            <i class="bi bi-lightbulb-fill text-warning"></i> Example Prompts
          </div>
          <div class="d-flex flex-wrap gap-2 justify-content-center mx-auto" id="coach-suggestions" style="max-width: 600px;">
            <button type="button" class="btn btn-sm bg-surface border-ink-200 text-ink-800 rounded-pill py-2 px-3 shadow-xs transition-normal hover-bg-brand-50" data-suggestion="Explain my latest quiz mistakes.">
              <i class="bi bi-exclamation-circle text-danger-ink me-1"></i> Explain my latest quiz mistakes
            </button>
            <button type="button" class="btn btn-sm bg-surface border-ink-200 text-ink-800 rounded-pill py-2 px-3 shadow-xs transition-normal hover-bg-brand-50" data-suggestion="Help me understand a difficult concept.">
              <i class="bi bi-compass text-brand me-1"></i> Help me understand a difficult concept
            </button>
            <button type="button" class="btn btn-sm bg-surface border-ink-200 text-ink-800 rounded-pill py-2 px-3 shadow-xs transition-normal hover-bg-brand-50" data-suggestion="Create a study plan for my weak topics.">
              <i class="bi bi-list-check text-success-ink me-1"></i> Create a study plan for my weak topics
            </button>
            <button type="button" class="btn btn-sm bg-surface border-ink-200 text-ink-800 rounded-pill py-2 px-3 shadow-xs transition-normal hover-bg-brand-50" data-suggestion="Give me a hint without revealing the answer.">
              <i class="bi bi-puzzle text-warning-ink me-1"></i> Give me a hint without revealing the answer
            </button>
          </div>
        </div>
      </div>

      <!-- Input Form -->
      <div class="bg-surface border-top border-ink-100 p-3 flex-shrink-0 z-1">
        <div class="mx-auto" style="max-width: 800px;">
          <form id="coach-form" class="position-relative">
            <textarea id="coach-input" class="form-control bg-surface-2 border-ink-200 focus-ring-brand rounded-4 px-3 py-3 pe-5 resize-none shadow-sm transition-normal" 
                      style="min-height: 56px; max-height: 200px; resize: none; overflow-y: auto;" 
                      maxlength="1000" rows="1"
                      placeholder="Ask your coach anything about your learning progress..."></textarea>
            
            <button type="submit" class="btn btn-brand position-absolute bottom-0 end-0 m-2 d-flex align-items-center justify-content-center p-0 rounded-circle shadow-brand transition-normal" 
                    id="coach-send" style="width: 40px; height: 40px;" aria-label="Send Message">
              <i class="bi bi-send-fill ms-1" style="font-size: 1.1rem;"></i>
            </button>
          </form>
          
          <div class="d-flex align-items-center justify-content-center gap-2 mt-2">
            <i class="bi bi-shield-check text-success small"></i>
            <p class="small text-muted-ink mb-0 text-center" id="coach-note" style="font-size: 0.7rem;">
              Advice is grounded strictly in your quiz performance — the coach never invents facts.
            </p>
          </div>
        </div>
      </div>
      
    </div>
    
    <script>
      document.addEventListener('DOMContentLoaded', () => {
        const input = document.getElementById('coach-input');
        const form = document.getElementById('coach-form');
        const suggestionsWrapper = document.getElementById('coach-suggestions-wrapper');
        const sendBtn = document.getElementById('coach-send');
        
        // Auto-resize textarea
        input.addEventListener('input', function() {
          this.style.height = 'auto';
          this.style.height = (this.scrollHeight) + 'px';
        });

        // Handle Enter vs Shift+Enter
        input.addEventListener('keydown', function(e) {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (this.value.trim() !== '') {
              form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
            }
          }
        });

        // Hide suggestions on first send
        form.addEventListener('submit', () => {
          if (input.value.trim() !== '' && suggestionsWrapper) {
            suggestionsWrapper.style.display = 'none';
            // Reset textarea height
            setTimeout(() => {
              input.style.height = 'auto';
            }, 100);
          }
        });
        
        // Hide suggestions on click of suggestion
        document.querySelectorAll('#coach-suggestions button').forEach(btn => {
          btn.addEventListener('click', () => {
            if (suggestionsWrapper) {
              suggestionsWrapper.style.display = 'none';
            }
          });
        });
      });
    </script>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify/dist/purify.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>
