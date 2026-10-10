/* ==========================================================================
   QuizSphere - Frontend application script (Phase 1)
   -------------------------------------------------------------
   Handles frontend-only interactions. The quiz engine below uses
   sample/demo data only. No AI, backend or Supabase connections yet.

   Modules:
     1. app.init()            - global bootstrap
     2. mobileNav            - Bootstrap handles toggling; anchor smooth scroll
     3. passwordToggle       - show/hide password fields
     4. formValidation       - client-side form validation
     5. passwordStrength     - registration live strength meter
     6. quizEngine           - sample quiz navigation + timer + scoring
     7. resultsEngine        - render results from sample data
     8. demoChart            - Chart.js performance placeholder
   ========================================================================== */

(function () {
  'use strict';

  /* ----------------------------------------------------------------------
     Sample demo data (Phase 1 only)
     ---------------------------------------------------------------------- */
  const SAMPLE_QUIZ = {
    title: 'Introduction to Biology',
    topic: 'Cell Structure & Function',
    difficulty: 'medium',
    totalTime: 300, // seconds (5 minutes)
    questions: [
      {
        text: 'Which organelle is known as the "powerhouse of the cell"?',
        options: ['Nucleus', 'Mitochondria', 'Ribosome', 'Golgi apparatus'],
        answer: 1
      },
      {
        text: 'Which of the following is NOT a type of blood cell?',
        options: ['Red blood cells', 'White blood cells', 'Platelets', 'Neurons'],
        answer: 3
      },
      {
        text: 'The process by which plants make their own food is called:',
        options: ['Respiration', 'Photosynthesis', 'Fermentation', 'Osmosis'],
        answer: 1
      },
      {
        text: 'Which of these is the basic unit of life?',
        options: ['Atom', 'Molecule', 'Cell', 'Organ'],
        answer: 2
      },
      {
        text: 'Where does DNA reside inside a human cell?',
        options: ['Mitochondria', 'Ribosome', 'Nucleus', 'Cell membrane'],
        answer: 2
      }
    ]
  };

  const STRONG_AREAS = ['Cell Basics', 'Photosynthesis'];
  const WEAK_AREAS = ['Mitochondria', 'Blood Cells'];

  /* ----------------------------------------------------------------------
     Helpers
     ---------------------------------------------------------------------- */
  const byId = (id) => document.getElementById(id);
  const on = (el, ev, fn) => { if (el) el.addEventListener(ev, fn); };

  // Resolve an app-relative path (e.g. 'pages/quiz.php') to an absolute URL
  // based on the base URL emitted in the page <head>.
  const appUrl = (path) => {
    const base = (window.APP_URL || '/').replace(/\/+$/, '');
    return base + '/' + String(path).replace(/^\/+/, '');
  };

  /* ----------------------------------------------------------------------
     Session: hold the Supabase access token in JS memory only (never
     localStorage/cookies). On fresh page loads the token is recovered from the
     PHP session (seeded after FastAPI login via the session bridge), so the
     user stays logged in across reloads.
     ---------------------------------------------------------------------- */
  let _sessionToken = null;
  let _csrfToken = null;
  let _sessionLoaded = false;

  async function loadSession() {
    if (_sessionLoaded) return;
    _sessionLoaded = true;
    try {
      const base = (window.APP_URL || '/').replace(/\/+$/, '');
      const res = await fetch(base + '/api/session_info.php', {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      });
      if (res.ok) {
        const info = await res.json();
        _sessionToken = info.access_token || _sessionToken || null;
        _csrfToken = info.csrf_token || null;
      }
    } catch (e) { /* session bridge unavailable */ }
  }

  const csrfToken = () => {
    const el = document.querySelector('input[name="_csrf"]');
    return (el && el.value) || _csrfToken || '';
  };

  /* Determine whether an endpoint should go to FastAPI or PHP.
     Auth endpoints (login/signup/logout) now go to FastAPI; only the PHP
     session bridge/clear helpers stay on PHP. Everything else goes to FastAPI. */
  function isPhpEndpoint(endpoint) {
    const lower = endpoint.toLowerCase();
    return lower.indexOf('api/session_info') !== -1 ||
           lower.indexOf('api/session_bridge') !== -1 ||
           lower.indexOf('api/session_clear') !== -1;
  }

  async function apiFetch(endpoint, method, body) {
    const opts = { method, headers: { 'Accept': 'application/json' } };
    const isPhp = isPhpEndpoint(endpoint);

    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    if (isPhp) {
      // PHP endpoint - no Bearer token needed (session token bridge / clear)
      if (endpoint.indexOf('http') !== 0 && endpoint.charAt(0) !== '/') {
        const base = (window.APP_URL || '/').replace(/\/+$/, '');
        endpoint = base + '/' + endpoint;
      }
    } else {
      // FastAPI endpoint
      await loadSession();
      if (_sessionToken) {
        opts.headers['Authorization'] = 'Bearer ' + _sessionToken;
      } else {
        // Auth calls (signup/login) may not have a token yet - they provide CSRF
        const csrf = csrfToken();
        if (csrf) opts.headers['X-CSRF-Token'] = csrf;
      }
      const apiBase = (window.API_URL || '').replace(/\/+$/, '');
      if (apiBase) {
        if (endpoint.indexOf('http') !== 0) {
          // Strip the .php suffix when calling FastAPI (routes drop the extension).
          // Match ".php" even when followed by a query string (?id=...), since the
          // endpoint may carry params (e.g. api/quiz.php?id=..). Previously the
          // `$` anchor only stripped a bare path, so query-string endpoints went
          // to FastAPI with the ".php" intact and 404'd ("Couldn't load this quiz").
          let fastPath = endpoint.replace(/\.php(?=\?|$)/i, '').replace(/^\//, '');
          endpoint = apiBase + '/' + fastPath;
        }
      } else if (endpoint.indexOf('http') !== 0) {
        const base = (window.APP_URL || '/').replace(/\/+$/, '');
        endpoint = base + '/' + endpoint.replace(/^\//, '');
      }
    }

    const res = await fetch(endpoint, opts);
    let data = {};
    try { data = await res.json(); } catch (e) { /* non-json */ }
    return { ok: res.ok, status: res.status, data };
  }

  /* After a successful FastAPI login/signup, store the tokens in memory and
     seed the PHP session via the bridge so protected pages keep working. */
  async function establishSession(tokens, redirect) {
    if (!tokens || !tokens.access_token) return false;
    _sessionToken = tokens.access_token;
    const base = (window.APP_URL || '/').replace(/\/+$/, '');
    try {
      await fetch(base + '/api/session_bridge.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          access_token: tokens.access_token,
          refresh_token: tokens.refresh_token || ''
        })
      });
    } catch (e) { /* session bridge best-effort */ }
    window.location.href = (redirect && redirect.startsWith('/'))
      ? redirect
      : appUrl(redirect || 'pages/dashboard.php');
    return true;
  }

  function setSubmitFeedback(formEl, type, message) {
    const alert = formEl.querySelector('.form-alert');
    if (!alert) return;
    alert.className = 'form-alert alert alert-' + type + ' mt-3';
    alert.textContent = message;
    alert.hidden = false;
  }

  /* ----------------------------------------------------------------------
     4a. Authentication forms (login / signup)
     ---------------------------------------------------------------------- */
  function initAuthForms() {
    document.querySelectorAll('[data-auth]').forEach((formEl) => {
      let inFlight = false; // guarantees one click -> one request
      on(formEl, 'submit', async (e) => {
        e.preventDefault();
        if (inFlight) return;
        const action = formEl.getAttribute('data-auth');
        let valid = true;

        if (action === 'signup') {
          valid = validateField(formEl, '#fullname', 'Full name is required.') && valid;
        }
        valid = validateEmail(formEl, '#email') && valid;
        valid = validateField(formEl, '#password', 'Password is required.') && valid;
        if (action === 'signup') {
          valid = validatePassword(formEl, '#password', 6) && valid;
          valid = validateConfirm(formEl) && valid;
        }
        if (!valid) {
          setSubmitFeedback(formEl, 'danger', 'Please fix the highlighted fields below.');
          return;
        }

        inFlight = true;
        const submitBtn = formEl.querySelector('[type="submit"]');
        const btnText = submitBtn ? submitBtn.innerHTML : '';
        const loadingLabel = action === 'signup' ? 'Creating account...' : 'Signing in...';
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.setAttribute('aria-busy', 'true');
          submitBtn.innerHTML = loadingLabel;
        }
        const restoreBtn = () => {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.removeAttribute('aria-busy');
            submitBtn.innerHTML = btnText;
          }
          inFlight = false;
        };

        try {
          const endpoint = action === 'signup' ? 'api/auth/signup' : 'api/auth/login';
          const payload = {
            fullname: (formEl.querySelector('#fullname') || {}).value || '',
            email: (formEl.querySelector('#email') || {}).value || '',
            password: (formEl.querySelector('#password') || {}).value || '',
            confirm_password: (formEl.querySelector('#confirm_password') || {}).value || '',
            _csrf: csrfToken()
          };
          const { ok, status, data } = await apiFetch(endpoint, 'POST', payload);
          if (ok && data && data.tokens && data.tokens.access_token) {
            // Keep the button locked while the browser navigates away.
            if (submitBtn) submitBtn.innerHTML = loadingLabel;
            await establishSession(data.tokens, data.redirect);
            return;
          }
          const msg = (data && data.message) || 'Something went wrong. Please try again.';
          setSubmitFeedback(formEl, data && data.error === 'confirm_required' ? 'success' : 'danger', msg);
          restoreBtn();
        } catch (err) {
          setSubmitFeedback(formEl, 'danger', 'Network error. Please check your connection and try again.');
          restoreBtn();
        }
      });
    });
  }

  /* ----------------------------------------------------------------------
     4b. Logout
     ---------------------------------------------------------------------- */
  function initLogout() {
    document.querySelectorAll('[data-logout]').forEach((btn) => {
      on(btn, 'click', async () => {
        btn.disabled = true;
        const base = (window.APP_URL || '/').replace(/\/+$/, '');
        try {
          // Revoke the token on FastAPI, then clear the PHP session.
          await apiFetch('api/auth/logout', 'POST', {});
          await fetch(base + '/api/session_clear.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: '{}'
          });
          _sessionToken = null;
          window.location.href = appUrl('index.php');
        } catch (e) {
          // Even if logout fails, clear the PHP session and go home.
          try {
            await fetch(base + '/api/session_clear.php', {
              method: 'POST', headers: { 'Content-Type': 'application/json' },
              credentials: 'same-origin', body: '{}'
            });
          } catch (e2) { /* ignore */ }
          _sessionToken = null;
          window.location.href = appUrl('index.php');
        }
      });
    });
  }

  /* ----------------------------------------------------------------------
     0b. Mobile slide-out navigation drawer
     ---------------------------------------------------------------------- */
  function initSidebarDrawer() {
    const toggle = byId('mobileMenuToggle');
    const closeBtn = byId('sidebarCloseBtn');
    const sidebar = byId('appSidebar');
    const backdrop = byId('sidebarBackdrop');
    if (!sidebar) return;

    function openDrawer() {
      sidebar.classList.add('show');
      if (backdrop) backdrop.classList.add('show');
      document.body.classList.add('drawer-open');
    }

    function closeDrawer() {
      sidebar.classList.remove('show');
      if (backdrop) backdrop.classList.remove('show');
      document.body.classList.remove('drawer-open');
    }

    on(toggle, 'click', (e) => {
      e.stopPropagation();
      openDrawer();
    });

    on(closeBtn, 'click', closeDrawer);
    on(backdrop, 'click', closeDrawer);

    // Close when clicking a nav link on mobile
    sidebar.querySelectorAll('.sidebar-link').forEach((link) => {
      on(link, 'click', () => {
        if (window.innerWidth < 992) closeDrawer();
      });
    });

    // Close on Escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && sidebar.classList.contains('show')) {
        closeDrawer();
      }
    });
  }

  /* ----------------------------------------------------------------------
     1. Smooth scroll for same-page anchor links
     ---------------------------------------------------------------------- */
  function initSmoothScroll() {
    document.querySelectorAll('a[href^="#"]').forEach((link) => {
      on(link, 'click', (e) => {
        const target = document.querySelector(link.getAttribute('href'));
        if (target) {
          e.preventDefault();
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      });
    });
  }

  /* ----------------------------------------------------------------------
     2. Password visibility toggles
     ---------------------------------------------------------------------- */
  function initPasswordToggles() {
    document.querySelectorAll('.toggle-password').forEach((btn) => {
      on(btn, 'click', () => {
        const input = document.getElementById(btn.getAttribute('data-target'));
        if (!input) return;
        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        const icon = btn.querySelector('i');
        if (icon) icon.className = isHidden ? 'bi bi-eye-slash' : 'bi bi-eye';
        btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
      });
    });
  }

  /* ----------------------------------------------------------------------
     3. Live password strength meter
     ---------------------------------------------------------------------- */
  function initPasswordStrength() {
    const input = byId('password');
    const meter = byId('password-strength');
    if (!input || !meter) return;

    const update = () => {
      const v = input.value;
      let score = 0;
      if (v.length >= 6) score++;
      if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
      if (/\d/.test(v)) score++;
      if (v.length >= 10 && /[^A-Za-z0-9]/.test(v)) score++;

      const labels = ['Too short', 'Weak', 'Fair', 'Good', 'Strong'];
      const colors = ['#ef4444', '#f59e0b', '#0ea5e9', '#10b981', '#10b981'];
      const width = ['25%', '40%', '60%', '80%', '100%'];

      meter.style.width = v.length === 0 ? '0%' : width[score];
      meter.style.backgroundColor = v.length === 0 ? 'transparent' : colors[score];
      meter.setAttribute('aria-valuenow', String(score));
      const label = byId('password-strength-label');
      if (label) label.textContent = v.length === 0 ? '' : labels[score];
    };

    on(input, 'input', update);
  }

  /* ----------------------------------------------------------------------
     4. Client-side form validation
     ---------------------------------------------------------------------- */
  function initFormValidation() {
    // Registration + Login forms
    document.querySelectorAll('[data-validate]').forEach((formEl) => {
      on(formEl, 'submit', (e) => {
        e.preventDefault();
        let valid = true;
        valid = validateField(formEl, '#fullname', 'Full name is required.') && valid;
        valid = validateEmail(formEl, '#email') && valid;
        valid = validateField(formEl, '#password', 'Password is required.') && valid;
        valid = validatePassword(formEl, '#password', 6) && valid;
        valid = validateConfirm(formEl) && valid;
        showSubmitFeedback(formEl, valid);
      });
    });
  }

  function setFieldState(wrapper, ok, message) {
    const input = wrapper.querySelector('input, select, textarea');
    const feedbackOk = wrapper.querySelector('.feedback-success');
    const feedbackErr = wrapper.querySelector('.feedback-error');
    wrapper.classList.remove('has-error', 'has-success');
    if (ok) {
      wrapper.classList.add('has-success');
      if (input) input.classList.remove('is-invalid');
      if (feedbackOk) feedbackOk.textContent = '';
    } else {
      wrapper.classList.add('has-error');
      if (input) input.classList.add('is-invalid');
      if (feedbackErr) feedbackErr.textContent = message;
      if (input) input.focus();
    }
  }

  const fieldGroup = (formEl, selector) => {
    const input = formEl.querySelector(selector);
    return { input, wrapper: input ? input.closest('.mb-3') : null };
  };

  function validateField(formEl, selector, requiredMsg) {
    const { input, wrapper } = fieldGroup(formEl, selector);
    if (!input || !wrapper) return true;
    const ok = input.value.trim().length > 0;
    setFieldState(wrapper, ok, requiredMsg);
    return ok;
  }

  function validateEmail(formEl, selector) {
    const { input, wrapper } = fieldGroup(formEl, selector);
    if (!input || !wrapper) return true;
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const ok = re.test(input.value.trim());
    setFieldState(wrapper, ok, 'Please enter a valid email address.');
    return ok;
  }

  function validatePassword(formEl, selector, min) {
    const { input, wrapper } = fieldGroup(formEl, selector);
    if (!input || !wrapper) return true;
    const ok = input.value.trim().length >= min;
    setFieldState(wrapper, ok, 'Password must be at least ' + min + ' characters.');
    return ok;
  }

  function validateConfirm(formEl) {
    const { input: pass, wrapper: pw } = fieldGroup(formEl, '#password');
    const { input: confirm, wrapper } = fieldGroup(formEl, '#confirm_password');
    if (!confirm || !wrapper) return true;
    const ok = confirm.value === pass.value && confirm.value.length > 0;
    setFieldState(wrapper, ok, 'Passwords do not match.');
    return ok;
  }

  function showSubmitFeedback(formEl, valid) {
    const alert = formEl.querySelector('.form-alert');
    if (!alert) return;
    if (valid) {
      alert.className = 'form-alert alert alert-success mt-3';
      alert.textContent = 'Success! (Authentication will be connected in a future phase.)';
      alert.hidden = false;
    } else {
      alert.className = 'form-alert alert alert-danger mt-3';
      alert.textContent = 'Please fix the highlighted fields below.';
      alert.hidden = false;
    }
  }

  /* ----------------------------------------------------------------------
     5. Quiz engine (sample data, frontend only)
     ---------------------------------------------------------------------- */
  function initQuizEngine() {
    const container = byId('quiz-app');
    if (!container) return;

    const questions = SAMPLE_QUIZ.questions;
    const total = questions.length;
    let current = 0;
    const answers = new Array(total).fill(-1);

    const qTitle = byId('quiz-title');
    const qTopic = byId('quiz-topic');
    const qDifficulty = byId('quiz-difficulty');
    const qNumber = byId('question-number');
    const qText = byId('question-text');
    const optsWrap = byId('question-options');
    const progress = byId('quiz-progress');
    const prevBtn = byId('prev-btn');
    const nextBtn = byId('next-btn');
    const submitBtn = byId('submit-btn');
    const timerEl = byId('quiz-timer');

    if (qTitle) qTitle.textContent = SAMPLE_QUIZ.title;
    if (qTopic) qTopic.textContent = SAMPLE_QUIZ.topic;
    if (qDifficulty) {
      const difficultyClass = 'badge-difficulty-' + SAMPLE_QUIZ.difficulty;
      qDifficulty.className = 'badge-soft ' + difficultyClass;
      qDifficulty.textContent = SAMPLE_QUIZ.difficulty.charAt(0).toUpperCase() + SAMPLE_QUIZ.difficulty.slice(1);
    }

    // Timer
    let seconds = SAMPLE_QUIZ.totalTime;
    let timerId = null;
    const fmt = (s) => {
      const m = Math.floor(s / 60);
      const sec = s % 60;
      return String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
    };
    if (timerEl) timerEl.textContent = fmt(seconds);
    timerId = setInterval(() => {
      seconds--;
      if (seconds <= 0) {
        clearInterval(timerId);
        finishQuiz();
        return;
      }
      if (timerEl) {
        timerEl.textContent = fmt(seconds);
        timerEl.classList.toggle('warning', seconds <= 60);
      }
    }, 1000);

    function render() {
      const q = questions[current];
      if (qNumber) qNumber.textContent = 'Question ' + (current + 1) + ' of ' + total;
      if (qText) qText.textContent = q.text;
      const pct = ((current + 1) / total) * 100;
      if (progress) progress.style.width = pct + '%';
      if (progress && progress.parentElement) {
        progress.parentElement.setAttribute('aria-valuenow', String(pct));
      }

      if (optsWrap) {
        optsWrap.innerHTML = '';
        const letters = ['A', 'B', 'C', 'D'];
        q.options.forEach((opt, i) => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'option mb-2' + (answers[current] === i ? ' selected' : '');
          btn.innerHTML = '<span class="option-letter">' + letters[i] + '</span><span>' + opt + '</span>';
          btn.addEventListener('click', () => {
            answers[current] = i;
            render();
          });
          optsWrap.appendChild(btn);
        });
      }

      if (prevBtn) { prevBtn.disabled = current === 0; prevBtn.classList.toggle('disabled', current === 0); }
      if (nextBtn) {
        const isLast = current === total - 1;
        nextBtn.classList.toggle('d-none', isLast);
        nextBtn.disabled = answers[current] === -1;
      }
      if (submitBtn) submitBtn.classList.toggle('d-none', !(current === total - 1));
    }

    if (prevBtn) on(prevBtn, 'click', () => { if (current > 0) { current--; render(); } });
    if (nextBtn) on(nextBtn, 'click', () => { if (current < total - 1 && answers[current] !== -1) { current++; render(); } });
    if (submitBtn) on(submitBtn, 'click', () => { clearInterval(timerId); finishQuiz(); });

    function finishQuiz() {
      const answered = answers.filter((a) => a !== -1).length;
      const correct = answers.reduce((acc, a, i) => acc + (a === questions[i].answer ? 1 : 0), 0);
      saveQuizResult({ correct, total, answered });
      window.location.href = 'results.php';
    }

    render();
  }

  /* ----------------------------------------------------------------------
     6. Results renderer (sample data)
     ---------------------------------------------------------------------- */
  function initResultsEngine() {
    const container = byId('results-app');
    if (!container) return;

    // Use demo result data unless a quiz result was staged.
    let result = loadQuizResult() || { correct: 4, total: 5, answered: 5 };

    const correct = result.correct;
    const total = result.total || 5;
    const incorrect = total - correct;
    const pct = Math.round((correct / total) * 100);

    const fill = (id, text) => { const el = byId(id); if (el) el.textContent = text; };
    fill('result-correct', String(correct));
    fill('result-incorrect', String(incorrect));
    fill('result-total', String(total));
    fill('result-score-pct', pct + '%');
    fill('result-message', performanceMessage(pct));

    const ring = byId('score-ring');
    if (ring) ring.style.setProperty('--p', pct + '%');

    renderAreaChips('strong-areas', STRONG_AREAS, 'success');
    renderAreaChips('weak-areas', WEAK_AREAS, 'danger');
  }

  function performanceMessage(pct) {
    if (pct >= 90) return 'Outstanding! You have excellent command of this topic.';
    if (pct >= 75) return 'Great work! You have a strong grasp, with a little room to grow.';
    if (pct >= 50) return 'Good effort! Some concepts need more practice.';
    if (pct >= 30) return 'Keep going! Focus on the weak areas below to improve.';
    return 'Don\u2019t be discouraged — review the material and try again!';
  }

  function renderAreaChips(id, items, variant) {
    const wrap = byId(id);
    if (!wrap) return;
    wrap.innerHTML = items.map((it) =>
      '<span class="area-chip bg-soft-' + variant + ' text-' + variant + '">' +
      '<i class="bi bi-' + (variant === 'success' ? 'check-circle' : 'x-circle') + '"></i>' + it + '</span>'
    ).join('');
  }

  /* ----------------------------------------------------------------------
     Result staging helpers (sessionStorage — frontend only, no backend)
     ---------------------------------------------------------------------- */
  function saveQuizResult(data) {
    try { sessionStorage.setItem('quizsphere_result', JSON.stringify(data)); } catch (e) {} // eslint-disable-line no-empty
  }
  function loadQuizResult() {
    try { return JSON.parse(sessionStorage.getItem('quizsphere_result') || 'null'); } catch (e) { return null; } // eslint-disable-line no-empty
  }

  /* ----------------------------------------------------------------------
     7. Performance chart (Chart.js placeholder)
     ---------------------------------------------------------------------- */
  function initDemoChart() {
    const canvas = byId('performance-chart');
    if (!canvas) return;
    if (typeof Chart === 'undefined') return;

    const labels = ['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Week 5', 'Week 6'];
    new Chart(canvas, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Average Score (%)',
            data: [55, 63, 70, 68, 78, 86],
            borderColor: '#4f46e5',
            backgroundColor: 'rgba(79, 70, 229, 0.12)',
            fill: true,
            tension: 0.35,
            pointRadius: 4,
            pointHoverRadius: 6,
            pointBackgroundColor: '#4f46e5',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 2
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#111827',
            titleColor: '#f9fafb',
            bodyColor: '#f3f4f6',
            padding: 10,
            cornerRadius: 8,
            displayColors: false,
            callbacks: {
              label: (ctx) => `Score: ${ctx.parsed.y}%`
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            max: 100,
            ticks: {
              stepSize: 20,
              callback: (v) => v + '%',
              color: '#94a3b8',
              font: { family: "'Inter', sans-serif", size: 11 }
            },
            grid: { color: '#f1f5f9' },
            border: { color: 'transparent' }
          },
          x: {
            ticks: {
              color: '#94a3b8',
              font: { family: "'Inter', sans-serif", size: 11 }
            },
            grid: { display: false }
          }
        }
      }
    });
  }

  /* ----------------------------------------------------------------------
     8. Generate quiz form (Phase 3/4) -> api/generate_quiz.php
     ---------------------------------------------------------------------- */
  function initGenerateForm() {
    const formEl = byId('generate-form');
    if (!formEl) return;

    on(formEl, 'submit', async (e) => {
      e.preventDefault();
      const topic = (byId('topic') ? byId('topic').value : '').trim();
      const difficulty = (byId('difficulty') ? byId('difficulty').value : 'medium');
      const question_count = parseInt((byId('question_count') || {}).value || '10', 10);
      const type = (formEl.querySelector('[name="question_type"]') || {}).value || 'multiple_choice';

      if (!topic) {
        setSubmitFeedback(formEl, 'danger', 'Please enter a topic.');
        return;
      }

      const btn = byId('generate-btn');
      const state = byId('generating-state');
      if (btn) btn.classList.add('d-none');
      if (state) state.classList.remove('d-none');

      try {
        const { ok, status, data } = await apiFetch('api/generate_quiz.php', 'POST', {
          topic, difficulty, question_count, question_type: type, _csrf: csrfToken()
        });
        if (ok && data && data.quiz && data.quiz.id) {
          window.location.href = appUrl('pages/quiz.php?id=' + encodeURIComponent(data.quiz.id));
          return;
        }
        const msg = (data && data.message) || 'Could not generate your quiz. Please try again.';
        setSubmitFeedback(formEl, 'danger', msg);
      } catch (err) {
        setSubmitFeedback(formEl, 'danger', 'Network error. Please check your connection and try again.');
      } finally {
        if (btn) btn.classList.remove('d-none');
        if (state) state.classList.add('d-none');
      }
    });
  }

  /* ----------------------------------------------------------------------
     9. Practice loaders (Weak-area recommendations from the dashboard)
        POSTs to api/practice.php and opens the generated quiz.
     ---------------------------------------------------------------------- */
  function initPracticeButtons() {
    document.querySelectorAll('[data-practice]').forEach((btn) => {
      on(btn, 'click', async (e) => {
        e.preventDefault();
        const concept = btn.getAttribute('data-practice');
        const difficulty = btn.getAttribute('data-difficulty') || '';
        if (btn.classList.contains('disabled')) return;

        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Preparing...';
        try {
          const body = { concept, question_count: 8, question_type: 'multiple_choice', _csrf: csrfToken() };
          if (difficulty) body.difficulty = difficulty;
          const { ok, data } = await apiFetch('api/practice.php', 'POST', body);
          if (ok && data && data.quiz && data.quiz.id) {
            window.location.href = appUrl('pages/quiz.php?id=' + encodeURIComponent(data.quiz.id));
            return;
          }
          const msg = (data && data.message) || 'Could not start practice. Please try again.';
          const alert = byId('practice-alert');
          if (alert) { alert.textContent = msg; alert.className = 'form-alert alert alert-danger mt-3'; alert.hidden = false; }
        } catch (err) {
          const alert = byId('practice-alert');
          if (alert) { alert.textContent = 'Network error. Please try again.'; alert.className = 'form-alert alert alert-danger mt-3'; alert.hidden = false; }
        } finally {
          btn.disabled = false;
          btn.innerHTML = original;
        }
      });
    });
  }

  /* ----------------------------------------------------------------------
     10. Quiz loader - drives the live quiz interface (#quiz-app[data-quiz-id])
         Loads via api/quiz.php and submits via api/submit_attempt.php.
     ---------------------------------------------------------------------- */
  function initQuizLoader() {
    const app = byId('quiz-app');
    if (!app) return;
    const quizId = app.getAttribute('data-quiz-id');
    if (!quizId) return;

    const loadingEl = byId('quiz-loading');
    const bodyEl = byId('quiz-body');
    const navEl = byId('quiz-nav');
    const errorEl = byId('quiz-error');
    const qTitle = byId('quiz-title');
    const qTopic = byId('quiz-topic');
    const qDifficulty = byId('quiz-difficulty');
    const qNumber = byId('question-number');
    const qText = byId('question-text');
    const optsWrap = byId('question-options');
    const progress = byId('quiz-progress');
    const timerEl = byId('quiz-timer');
    const prevBtn = byId('prev-btn');
    const nextBtn = byId('next-btn');
    const submitBtn = byId('submit-btn');

    let questions = [];
    let provider = 'gemini';
    let current = 0;
    const answers = [];
    let timerId = null;
    let seconds = 0;

    async function load() {
      const { ok, status, data } = await apiFetch('api/quiz.php?id=' + encodeURIComponent(quizId), 'GET');
      if (!ok) {
        loadingEl.classList.add('d-none');
        errorEl.classList.remove('d-none');
        if (byId('quiz-error-message')) byId('quiz-error-message').textContent = (data && data.message) || 'Quiz not found.';
        return;
      }
      questions = data.questions || [];
      provider = (data.quiz && data.quiz.provider) || 'gemini';
      seconds = questions.length * 60;
      if (qTitle) qTitle.textContent = (data.quiz && data.quiz.title) || 'Quiz';
      if (qTopic) qTopic.textContent = (data.quiz && data.quiz.topic) || '';
      if (qDifficulty && data.quiz && data.quiz.difficulty) {
        const d = data.quiz.difficulty;
        qDifficulty.className = 'badge-soft badge-difficulty-' + d;
        qDifficulty.textContent = d.charAt(0).toUpperCase() + d.slice(1);
      }
      startTimer();
      render();
      loadingEl.classList.add('d-none');
      bodyEl.classList.remove('d-none');
      navEl.classList.remove('d-none');
    }

    function startTimer() {
      if (timerEl) timerEl.textContent = fmt(seconds);
      if (timerId) clearInterval(timerId);
      timerId = setInterval(() => {
        seconds--;
        if (seconds <= 0) { clearInterval(timerId); submitQuiz(); return; }
        if (timerEl) {
          timerEl.innerHTML = '<i class="bi bi-clock"></i><span>' + fmt(seconds) + '</span>';
          timerEl.classList.toggle('warning', seconds <= 60);
        }
      }, 1000);
    }

    const fmt = (s) => {
      const m = Math.floor(s / 60);
      const sec = s % 60;
      return String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
    };

    function render() {
      const q = questions[current];
      if (!q) return;
      if (qNumber) qNumber.textContent = 'Question ' + (current + 1) + ' of ' + questions.length;
      if (qText) qText.textContent = q.body;
      const pct = ((current + 1) / questions.length) * 100;
      if (progress) progress.style.width = pct + '%';

      if (optsWrap) {
        optsWrap.innerHTML = '';
        const letters = ['A', 'B', 'C', 'D'];
        (q.options || []).forEach((opt, i) => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'option mb-2' + (answers[current] === i ? ' selected' : '');
          btn.innerHTML = '<span class="option-letter">' + letters[i] + '</span><span>' + opt + '</span>';
          btn.addEventListener('click', () => { answers[current] = i; render(); });
          optsWrap.appendChild(btn);
        });
      }

      if (prevBtn) { prevBtn.disabled = current === 0; prevBtn.classList.toggle('disabled', current === 0); }
      if (nextBtn) {
        const isLast = current === questions.length - 1;
        nextBtn.classList.toggle('d-none', isLast);
        nextBtn.disabled = answers[current] === undefined;
      }
      if (submitBtn) submitBtn.classList.toggle('d-none', !(current === questions.length - 1));
    }

    if (prevBtn) on(prevBtn, 'click', () => { if (current > 0) { current--; render(); } });
    if (nextBtn) on(nextBtn, 'click', () => { if (current < questions.length - 1 && answers[current] !== undefined) { current++; render(); } });
    if (submitBtn) on(submitBtn, 'click', () => { clearInterval(timerId); submitQuiz(); });

    let isSubmitting = false;
    async function submitQuiz() {
      if (isSubmitting) return;
      isSubmitting = true;
      if (timerId) clearInterval(timerId);
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Submitting...';
      // Only submit answered questions.
      const payload = [];
      questions.forEach((q, i) => {
        const selected = answers[i];
        if (selected !== undefined) {
          payload.push({ question_id: q.id, selected });
        }
      });

      const { ok, data } = await apiFetch('api/submit_attempt.php', 'POST', {
        quiz_id: quizId, provider, answers: payload, _csrf: csrfToken()
      });
      if (ok && data && data.attempt && data.attempt.id) {
        window.location.href = appUrl('pages/results.php?attempt=' + encodeURIComponent(data.attempt.id));
        return;
      }
      const msg = (data && data.message) || 'Your answers could not be submitted.';
      if (byId('quiz-error-message')) byId('quiz-error-message').textContent = msg;
      bodyEl.classList.add('d-none');
      navEl.classList.add('d-none');
      errorEl.classList.remove('d-none');
    }

    load();
  }

  /* ----------------------------------------------------------------------
     11. Results loader - renders a saved attempt (#results-app[data-attempt-id])
         Loads via api/attempt.php and renders the review with explanations.
     ---------------------------------------------------------------------- */
  function initResultsLoader() {
    const app = byId('results-app');
    if (!app) return;
    const attemptId = app.getAttribute('data-attempt-id');
    if (!attemptId) return;

    const loadingEl = byId('results-loading');
    const errorEl = byId('results-error');
    const contentEl = byId('results-content');

    async function load() {
      const { ok, data } = await apiFetch('api/attempt.php?id=' + encodeURIComponent(attemptId), 'GET');
      if (!ok) {
        loadingEl.classList.add('d-none');
        errorEl.classList.remove('d-none');
        if (byId('results-error-message')) byId('results-error-message').textContent = (data && data.message) || 'Results not found.';
        return;
      }

      const percent = Math.round((data.percent || 0));
      if (byId('result-subtitle')) byId('result-subtitle').textContent = 'Attempted on ' + (data.attempt.completed_at ? new Date(data.attempt.completed_at).toLocaleString() : 'just now');
      if (byId('result-score-pct')) byId('result-score-pct').textContent = percent + '%';
      if (byId('result-correct')) byId('result-correct').textContent = String(data.correct || 0);
      if (byId('result-incorrect')) byId('result-incorrect').textContent = String(data.incorrect || 0);
      if (byId('result-total')) byId('result-total').textContent = String(data.total || 0);
      if (byId('result-message')) byId('result-message').textContent = performanceMessage(percent);
      const ring = byId('score-ring');
      if (ring) ring.style.setProperty('--p', percent + '%');

      const reviewList = byId('review-list');
      if (reviewList) renderReview(reviewList, data.detail || []);

      renderRewards(data.rewards);

      loadingEl.classList.add('d-none');
      contentEl.classList.remove('d-none');
    }

    load();
  }

  /* Render a celebratory rewards banner on the results page (XP, badges, cert). */
  function renderRewards(rewards) {
    const wrap = byId('rewards-banner');
    if (!wrap) return;
    if (!rewards || (!rewards.gamification && !rewards.certificate)) return;

    const g = rewards.gamification || {};
    const html = [];

    if (typeof g.xp_earned === 'number' && g.xp_earned > 0) {
      html.push('<div class="reward-xp"><i class="bi bi-lightning-charge-fill me-1"></i> +' + g.xp_earned + ' XP earned</div>');
    }
    (g.new_achievements || []).forEach((b) => {
      html.push('<span class="reward-badge"><i class="bi ' + escapeHtml(b.icon || 'bi-trophy') + ' me-1 text-warning"></i>' + escapeHtml(b.name) + '</span>');
    });
    if (rewards.certificate && rewards.certificate.id) {
      html.push('<a class="btn btn-sm btn-brand" href="' + appUrl('pages/view-certificate.php?id=' + encodeURIComponent(rewards.certificate.id)) + '"><i class="bi bi-award me-1"></i>View Your Certificate</a>');
    }

    if (html.length) {
      wrap.innerHTML = html.join(' ');
      wrap.classList.remove('d-none');
    }
  }

  function renderReview(wrap, detail) {
    if (!detail.length) { wrap.innerHTML = '<p class="text-muted-ink">No question review available.</p>'; return; }
    wrap.innerHTML = detail.map((item, idx) => {
      const userLetter = item.selected >= 0 ? String.fromCharCode(65 + item.selected) : '—';
      const correctLetter = String.fromCharCode(65 + item.correct);
      const badge = item.is_correct
        ? '<span class="badge bg-success-subtle text-success">Correct</span>'
        : '<span class="badge bg-danger-subtle text-danger">Incorrect</span>';
      const concept = item.concept ? '<span class="badge-soft ms-2">' + escapeHtml(item.concept) + '</span>' : '';
      return '<div class="card p-3 p-md-4 mb-3">' +
        '<div class="d-flex justify-content-between align-items-start gap-2 mb-2">' +
          '<span class="fw-semibold">Q' + (idx + 1) + '. ' + escapeHtml(item.question) + '</span>' +
          '<span class="d-flex gap-2 flex-shrink-0">' + badge + concept + '</span>' +
        '</div>' +
        '<div class="small mb-1"><span class="text-muted-ink">Your answer: </span><span class="fw-semibold">' + userLetter + '</span></div>' +
        '<div class="small mb-2"><span class="text-muted-ink">Correct answer: </span><span class="fw-semibold text-success">' + correctLetter + ' — ' + escapeHtml((item.options && item.options[item.correct]) ? item.options[item.correct] : '') + '</span></div>' +
        '<div class="small p-2 mt-2 bg-soft-info rounded">' +
          '<i class="bi bi-lightbulb me-1"></i><span class="text-muted-ink">' + escapeHtml(item.explanation || '') + '</span>' +
        '</div>' +
      '</div>';
    }).join('');
  }

  /* ----------------------------------------------------------------------
     12. Dashboard analytics - real Chart.js chart, weak/strong areas,
         recommendations, and average score via api/analytics.php.
     ---------------------------------------------------------------------- */
  function initDashboardAnalytics() {
    const root = byId('dashboard-analytics');
    if (!root) return;

    async function load() {
      const { ok, data } = await apiFetch('api/analytics.php', 'GET');
      if (!ok) return;

      // Average score tile
      const avgEl = byId('stat-avg-score');
      if (avgEl) {
        if (data.stats.total_attempts > 0) {
          avgEl.textContent = Math.round(data.stats.average_score) + '%';
        } else {
          avgEl.textContent = '—';
          const sub = document.querySelector('[data-avg-sub]');
          if (sub) sub.textContent = 'After your first attempt';
        }
      }

      // Quiz Activity (Bar)
      renderQuizActivityChart(data.trend || []);

      // Topic Performance (Horizontal Bar)
      renderTopicPerformanceChart(data.concepts || []);

      // Recommendations (practice button)
      renderRecommendation(data.recommendations || {});

      // Wire any practice buttons that were just rendered.
      initPracticeButtons();

      // Insights
      renderInsights(data.insights || []);
    }

    load();
  }

  function renderQuizActivityChart(trend) {
    const canvas = byId('quiz-activity-chart');
    if (!canvas) return;
    if (typeof Chart === 'undefined') return;
    
    // Reverse to show oldest first if trend isn't ordered, but the PHP sorts them chronologically.
    const labels = trend.length ? trend.map((t) => t.label) : ['No data'];
    const scores = trend.length ? trend.map((t) => t.score) : [0];
    
    new Chart(canvas, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'Average Score (%)',
          data: scores,
          backgroundColor: '#635BFF',
          borderRadius: 6,
          barPercentage: 0.6
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#111827',
            titleColor: '#f9fafb',
            bodyColor: '#f3f4f6',
            padding: 10,
            cornerRadius: 8,
            displayColors: false,
            callbacks: {
              label: (ctx) => `Score: ${ctx.parsed.y}%`
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            max: 100,
            ticks: {
              stepSize: 20,
              callback: (v) => v + '%',
              color: '#64748B',
              font: { family: "'Inter', sans-serif", size: 11 }
            },
            grid: { color: '#F1F5F9' },
            border: { display: false }
          },
          x: {
            ticks: {
              color: '#64748B',
              font: { family: "'Inter', sans-serif", size: 11 }
            },
            grid: { display: false },
            border: { display: false }
          }
        }
      }
    });
  }

  function renderTopicPerformanceChart(concepts) {
    const canvas = byId('topic-performance-chart');
    if (!canvas) return;
    if (typeof Chart === 'undefined') return;
    
    if (concepts.length === 0) {
      concepts = [{ concept: 'No data', mastery: 0 }];
    }
    
    // Sort by mastery descending
    const sorted = [...concepts].sort((a, b) => b.mastery - a.mastery).slice(0, 5);
    const labels = sorted.map(c => {
      // truncate long concepts
      const name = c.concept || 'General';
      return name.length > 15 ? name.substring(0, 15) + '...' : name;
    });
    const scores = sorted.map(c => Math.round(c.mastery));
    
    // Determine color based on mastery status
    const bgColors = scores.map(s => {
      if (s >= 80) return '#10B981'; // Success
      if (s >= 60) return '#F59E0B'; // Warning
      return '#EF4444'; // Danger
    });

    new Chart(canvas, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'Mastery (%)',
          data: scores,
          backgroundColor: bgColors,
          borderRadius: 4,
          barPercentage: 0.7
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#111827',
            titleColor: '#f9fafb',
            bodyColor: '#f3f4f6',
            padding: 10,
            cornerRadius: 8,
            displayColors: false,
            callbacks: {
              label: (ctx) => `Mastery: ${ctx.parsed.x}%`
            }
          }
        },
        scales: {
          x: {
            beginAtZero: true,
            max: 100,
            ticks: {
              stepSize: 20,
              callback: (v) => v + '%',
              color: '#64748B',
              font: { family: "'Inter', sans-serif", size: 11 }
            },
            grid: { color: '#F1F5F9' },
            border: { display: false }
          },
          y: {
            ticks: {
              color: '#64748B',
              font: { family: "'Inter', sans-serif", size: 11 }
            },
            grid: { display: false },
            border: { display: false }
          }
        }
      }
    });
  }

  function renderAreaList(id, items, variant) {
    const wrap = byId(id);
    if (!wrap) return;
    if (!items.length) {
      wrap.innerHTML = '<p class="text-muted-ink small mb-0">No areas recorded yet.</p>';
      return;
    }
    const colorMap = { weak: 'danger', developing: 'warning', strong: 'success' };
    wrap.innerHTML = items.map((c) => {
      const barColor = colorMap[c.status] || (variant || 'primary');
      return '<div class="d-flex justify-content-between small mb-1">' +
        '<span class="fw-semibold text-truncate me-2">' + escapeHtml(c.concept) + '</span>' +
        '<span class="text-muted-ink flex-shrink-0">' + Math.round(c.mastery) + '%</span></div>' +
        '<div class="progress mb-3"><div class="progress-bar bg-' + barColor + '" style="width:' + c.mastery + '%"></div></div>';
    }).join('');
  }

  function renderRecommendation(rec) {
    const wrap = byId('recommendation-list');
    if (!wrap) return;

    if (rec.suggestion === 'start' || !rec.practice_concept) {
      if (rec.suggestion === 'start') {
        wrap.innerHTML = '<div class="text-center py-4"><i class="bi bi-stars fs-3 text-brand"></i><p class="text-muted-ink mb-0 mt-2">' +
          escapeHtml(rec.reason) + ' <a href="' + appUrl('pages/generate.php') + '">Generate your first quiz</a>.</p></div>';
      } else {
        wrap.innerHTML = '<p class="text-muted-ink small mb-0">' + escapeHtml(rec.reason) + '</p>';
      }
      return;
    }

    const diff = rec.suggested_difficulty || 'medium';
    const diffLabel = diff.charAt(0).toUpperCase() + diff.slice(1);
    wrap.innerHTML = '<div class="d-flex align-items-center gap-3 mb-2">' +
        '<div class="icon-badge"><i class="bi bi-bullseye"></i></div>' +
        '<div class="flex-grow-1">' +
          '<div class="fw-semibold">' + escapeHtml(rec.practice_concept) + ' Drill</div>' +
          '<div class="small text-muted-ink">' + escapeHtml(rec.reason) + '</div>' +
        '</div>' +
        '<button type="button" class="btn btn-sm btn-brand" data-practice="' + escapeHtml(rec.practice_concept) + '" data-difficulty="' + escapeHtml(diff) + '">Practice</button>' +
      '</div>' +
      '<div class="small text-muted-ink">Suggested difficulty: <span class="fw-semibold">' + escapeHtml(diffLabel) + '</span> based on your recent performance.</div>';
  }

  function renderInsights(list) {
    const wrap = byId('insights-list');
    if (!wrap) return;
    if (!list.length) { wrap.innerHTML = '<p class="text-muted-ink small mb-0">Complete a quiz to see insights.</p>'; return; }
    wrap.innerHTML = list.map((t) =>
      '<li class="d-flex gap-2 small">' +
        '<i class="bi bi-lightbulb text-brand flex-shrink-0 mt-1"></i><span>' + escapeHtml(t) + '</span></li>'
    ).join('');
  }

  function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = (value === null || value === undefined) ? '' : String(value);
    return div.innerHTML;
  }

  /* ----------------------------------------------------------------------
     13a. Upload Study Material -> AI quiz
     ---------------------------------------------------------------------- */
  function initUploadForm() {
    const form = byId('upload-form');
    if (!form) return;

    const fileInput = byId('pdf_file');
    const drop = byId('upload-drop');
    const label = byId('upload-label');
    const nameEl = byId('upload-name');
    const btn = byId('upload-btn');
    const uploading = byId('uploading-state');

    if (fileInput && drop) {
      const showName = () => {
        const f = fileInput.files && fileInput.files[0];
        if (f) {
          label.textContent = 'Replace file';
          nameEl.textContent = f.name + ' (' + Math.round(f.size / 1024) + ' KB)';
        } else {
          label.textContent = 'Click to select or drag & drop';
          nameEl.textContent = '';
        }
      };
      on(drop, 'click', () => fileInput.click());
      on(fileInput, 'change', showName);
      ['dragenter', 'dragover'].forEach((ev) =>
        on(drop, ev, (e) => { e.preventDefault(); drop.classList.add('dragover'); }));
      ['dragleave', 'drop'].forEach((ev) =>
        on(drop, ev, (e) => { e.preventDefault(); drop.classList.remove('dragover'); }));
      on(drop, 'drop', (e) => {
        const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (f) {
          const dt = new DataTransfer();
          dt.items.add(f);
          fileInput.files = dt.files;
          showName();
        }
      });
      showName();
    }

    on(form, 'submit', async (e) => {
      e.preventDefault();
      const file = fileInput && fileInput.files && fileInput.files[0];
      if (!file) { setSubmitFeedback(form, 'danger', 'Please choose a PDF file to upload.'); return; }
      if (file.type && file.type !== 'application/pdf') { setSubmitFeedback(form, 'danger', 'Please choose a PDF file.'); return; }
      if (file.size > 10 * 1024 * 1024) { setSubmitFeedback(form, 'danger', 'The PDF must be 10 MB or smaller.'); return; }

      const alertEl = form.querySelector('.form-alert');
      if (alertEl) alertEl.hidden = true;

      // Build the payload BEFORE disabling controls: FormData(form) skips
      // disabled inputs, which would silently drop the PDF file itself.
      const fd = new FormData(form);
      const csrf = csrfToken();
      if (csrf) fd.set('_csrf', csrf);

      btn.disabled = true;
      form.querySelectorAll('input, select, button').forEach((el) => { el.disabled = true; });
      if (uploading) uploading.classList.remove('d-none');

      try {
        await loadSession();
        const apiBase = (window.API_URL || '').replace(/\/+$/, '');
        let res;
        const opts = { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd };
        if (apiBase && _sessionToken) {
          opts.headers['Authorization'] = 'Bearer ' + _sessionToken;
          res = await fetch(apiBase + '/api/pdf_quiz', opts);
        } else {
          res = await fetch(appUrl('api/pdf_quiz.php'), opts);
        }
        let data = {};
        try { data = await res.json(); } catch (e) { /* non-json */ }
        if (res.ok && data && data.quiz && data.quiz.id) {
          window.location.href = appUrl('pages/quiz.php?id=' + encodeURIComponent(data.quiz.id));
          return;
        }
        setSubmitFeedback(form, 'danger', (data && data.message) || 'Something went wrong. Please try again.');
      } catch (err) {
        setSubmitFeedback(form, 'danger', 'Network error. Please check your connection and try again.');
      } finally {
        if (uploading) uploading.classList.add('d-none');
        btn.disabled = false;
        form.querySelectorAll('input, select, button').forEach((el) => { el.disabled = false; });
      }
    });
  }

  /* ----------------------------------------------------------------------
     13b. AI Learning Coach
     ---------------------------------------------------------------------- */
  function initCoach() {
    const app = byId('coach-app');
    if (!app) return;
    const form = byId('coach-form');
    const input = byId('coach-input');
    const send = byId('coach-send');
    const note = byId('coach-note');
    const messagesEl = byId('coach-messages');
    if (!form || !input || !messagesEl) return;
    let history = [];
    let isSubmitting = false;

    if (note) note.textContent = 'Advice is generated from your quiz performance only — the coach never invents facts about you.';

    const formatCoachText = (text) => {
      let html = escapeHtml(text);
      // Format markdown bold
      html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
      // Format markdown list items (simple approach for pre-wrap)
      html = html.replace(/^[\s]*\*[\s]+(.*)/gm, '• $1');
      return html;
    };

    const scroll = () => { messagesEl.scrollTop = messagesEl.scrollHeight; };
    const addBubble = (role, text, loading) => {
      const row = document.createElement('div');
      row.className = 'coach-msg coach-msg-' + role + (loading ? ' coach-loading' : '');
      row.innerHTML = (role === 'coach' ? '<div class="coach-avatar"><i class="bi bi-stars"></i></div>' : '') +
        '<div class="coach-bubble">' + (loading ? '<i class="bi bi-three-dots"></i>' : formatCoachText(text)) + '</div>';
      messagesEl.appendChild(row);
      scroll();
      return { row, bubble: row.querySelector('.coach-bubble') };
    };

    const chips = app.querySelectorAll('[data-suggestion]');

    const sendMsg = async (text) => {
      if (isSubmitting) return;
      const clean = (text || '').trim();
      if (!clean) { input.focus(); return; }

      isSubmitting = true;
      input.disabled = true;
      send.disabled = true;
      chips.forEach((c) => {
        c.setAttribute('disabled', 'true');
        c.style.pointerEvents = 'none';
        c.style.opacity = '0.6';
      });

      addBubble('user', clean);
      input.value = '';
      const loading = addBubble('coach', '', true);

      try {
        const recentHistory = history.slice(-6);
        let resp = null;
        try {
          resp = await apiFetch('api/coach.php', 'POST', { message: clean, history: recentHistory });
        } catch (netErr) {
          // Direct fallback to local PHP endpoint if FastAPI is unreachable
          const phpUrl = (window.APP_URL || '/').replace(/\/+$/, '') + '/api/coach.php';
          const csrf = csrfToken();
          const fallbackRes = await fetch(phpUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
            credentials: 'same-origin',
            body: JSON.stringify({ message: clean, history: recentHistory, _csrf: csrf })
          });
          let fbData = {};
          try { fbData = await fallbackRes.json(); } catch (_) {}
          resp = { ok: fallbackRes.ok, status: fallbackRes.status, data: fbData };
        }

        const data = resp && resp.data ? resp.data : {};
        if (resp && resp.ok && data.message) {
          loading.bubble.textContent = data.message;
          loading.row.classList.remove('coach-loading');
          history.push({ role: 'user', content: clean });
          history.push({ role: 'coach', content: data.message });
          if (history.length > 8) {
            history = history.slice(-8);
          }
        } else {
          loading.bubble.textContent = (data && data.message) || 'The AI coach is temporarily unavailable. Please try again in a moment.';
          loading.row.classList.remove('coach-loading');
        }
        scroll();
      } catch (err) {
        loading.bubble.textContent = 'Network error. Please check your connection and try again.';
        loading.row.classList.remove('coach-loading');
      } finally {
        isSubmitting = false;
        input.disabled = false;
        send.disabled = false;
        chips.forEach((c) => {
          c.removeAttribute('disabled');
          c.style.pointerEvents = '';
          c.style.opacity = '';
        });
        input.focus();
      }
    };

    on(form, 'submit', (e) => { e.preventDefault(); sendMsg(input.value); });
    chips.forEach((chip) => {
      on(chip, 'click', () => sendMsg(chip.getAttribute('data-suggestion')));
    });
    input.focus();
  }

  /* ----------------------------------------------------------------------
     16. Gamification (dashboard): level progress, achievements, rank, certs
     ---------------------------------------------------------------------- */
  function initDashboardGamification() {
    const root = byId('dashboard-analytics');
    if (!root) return;

    async function load() {
      const { ok, data } = await apiFetch('api/gamification.php', 'GET');
      if (ok && data && data.profile) {
        const p = data.profile;
        if (byId('level-progress')) byId('level-progress').style.width = Math.round((p.level_info.progress || 0) * 100) + '%';
        if (byId('level-progress-label')) {
          byId('level-progress-label').textContent = 'Level ' + p.level + ' · ' + p.xp + ' XP · ' + p.level_info.xp_next + ' XP to next level';
        }
        if (byId('leaderboard-rank-hint') && p.rank) {
          byId('leaderboard-rank-hint').textContent = '#' + p.rank + ' overall';
        }
        if (byId('sidebarUserLevel') && p.level) {
          byId('sidebarUserLevel').textContent = 'Level ' + p.level;
        }
        if (byId('sidebarUserXp') && p.xp !== undefined) {
          byId('sidebarUserXp').textContent = Number(p.xp).toLocaleString() + ' XP';
        }
        renderAchievements((data.achievements || {}));
        renderScoreDistributionChart(data.recent_attempts || []);
      }
      loadCertificatePreview();
    }

    function renderScoreDistributionChart(attempts) {
      const canvas = byId('score-distribution-chart');
      if (!canvas) return;
      if (typeof Chart === 'undefined') return;

      let weak = 0, developing = 0, strong = 0;
      attempts.forEach(a => {
        if (a.percent >= 80) strong++;
        else if (a.percent >= 60) developing++;
        else weak++;
      });
      
      if (weak === 0 && developing === 0 && strong === 0) {
        // Fallback placeholder
        weak = 1; developing = 1; strong = 1;
      }

      new Chart(canvas, {
        type: 'doughnut',
        data: {
          labels: ['Strong (80%+)', 'Developing (60-79%)', 'Weak (<60%)'],
          datasets: [{
            data: [strong, developing, weak],
            backgroundColor: ['#10B981', '#F59E0B', '#EF4444'],
            borderWidth: 0,
            hoverOffset: 4
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '75%',
          plugins: {
            legend: {
              position: 'bottom',
              labels: {
                color: '#64748B',
                font: { family: "'Inter', sans-serif", size: 11 },
                usePointStyle: true,
                padding: 15
              }
            },
            tooltip: {
              backgroundColor: '#111827',
              titleColor: '#f9fafb',
              bodyColor: '#f3f4f6',
              padding: 10,
              cornerRadius: 8
            }
          }
        }
      });
    }

    function renderAchievements(ach) {
      const grid = byId('achievements-grid');
      if (!grid) return;
      if (byId('achievement-count')) byId('achievement-count').textContent = ach.unlocked_count + ' / ' + ach.total;
      const all = ach.all || [];
      if (!all.length) {
        grid.innerHTML = '<div class="col-12"><p class="text-muted-ink small mb-0">Complete quizzes to unlock achievements.</p></div>';
        return;
      }
      grid.innerHTML = all.map((a) => {
        const unlocked = !!a.unlocked;
        return '<div class="col-6 text-center achievement-tile' + (unlocked ? ' unlocked' : ' locked') + '" title="' + escapeHtml(a.description || '') + '">' +
          '<div class="icon-badge mx-auto"><i class="bi ' + escapeHtml(a.icon || 'bi-trophy') + '"></i></div>' +
          '<div class="small fw-semibold mt-1">' + escapeHtml(a.name) + '</div>' +
          (unlocked ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-lock-fill text-muted-ink"></i>') +
        '</div>';
      }).join('');
    }

    async function loadCertificatePreview() {
      const wrap = byId('certificates-preview');
      if (!wrap) return;
      const { ok, data } = await apiFetch('api/certificates.php', 'GET');
      if (!ok || !data) { wrap.innerHTML = '<p class="text-muted-ink small mb-0">Could not load certificates.</p>'; return; }
      const list = data.certificates || [];
      if (!list.length) {
        wrap.innerHTML = '<p class="text-muted-ink small mb-0">Score 80%+ on a quiz to earn your first certificate.</p>';
        return;
      }
      wrap.innerHTML = list.slice(0, 3).map((c) => {
        return '<div class="d-flex align-items-center gap-2 mb-2">' +
          '<div class="icon-badge small-badge"><i class="bi bi-award"></i></div>' +
          '<div class="flex-grow-1 small"><span class="fw-semibold">' + escapeHtml(c.quiz_title || 'Certificate') + '</span><div class="text-muted-ink">' +
            escapeHtml((c.score || 0) + '%') + ' · ' + new Date(c.earned_at).toLocaleDateString() + '</div></div>' +
          '<a class="btn btn-sm btn-outline-brand" href="' + appUrl('pages/view-certificate.php?id=' + encodeURIComponent(c.id)) + '">View</a>' +
        '</div>';
      }).join('');
    }

    load();
  }

  /* ----------------------------------------------------------------------
     17. Leaderboard page (#leaderboard-app) via api/leaderboard.php
     ---------------------------------------------------------------------- */
  function initLeaderboard() {
    const app = byId('leaderboard-app');
    if (!app) return;

    async function load() {
      const { ok, data } = await apiFetch('api/leaderboard.php', 'GET');
      const body = byId('leaderboard-body');
      const errEl = byId('leaderboard-error');
      
      const statsContainer = byId('leaderboard-stats-container');
      const podiumContainer = byId('podium-container');
      const podiumCards = byId('podium-cards');
      
      if (!ok || !data) {
        if (errEl) { errEl.hidden = false; errEl.textContent = (data && data.message) || 'Could not load the leaderboard.'; }
        if (body) body.innerHTML = '<tr><td colspan="5" class="text-center text-muted-ink py-5 bg-surface-2">Leaderboard unavailable.</td></tr>';
        return;
      }

      const rows = data.leaderboard || [];
      const me = data.me;

      // Update overview stats
      if (statsContainer) {
        statsContainer.style.display = 'flex';
        byId('stat-my-rank').textContent = (me && me.rank) ? '#' + me.rank : '--';
        byId('stat-my-xp').textContent = (me && me.xp) ? Number(me.xp).toLocaleString() : '0';
        byId('stat-total-users').textContent = rows.length;
        byId('stat-top-xp').textContent = rows.length > 0 ? Number(rows[0].xp).toLocaleString() : '0';
      }

      // Populate podium if enough players
      if (podiumContainer && podiumCards && rows.length > 0) {
        podiumContainer.style.display = 'block';
        let podiumHtml = '';
        const top3 = rows.slice(0, 3);
        
        // Podium order: 2, 1, 3 for visual hierarchy
        const order = [1, 0, 2]; 
        
        order.forEach((index) => {
          if (!top3[index]) return;
          const r = top3[index];
          const isFirst = index === 0;
          const height = isFirst ? '220px' : (index === 1 ? '180px' : '150px');
          const medalColors = ['#f59e0b', '#9ca3af', '#b45309']; // Gold, Silver, Bronze
          const color = medalColors[index];
          
          podiumHtml += `
          <div class="col-4 col-sm-3 d-flex flex-column justify-content-end align-items-center">
            <div class="avatar-circle mb-3 border border-2 border-surface shadow-sm d-flex align-items-center justify-content-center text-white fw-bold fs-5" style="width: 60px; height: 60px; border-radius: 50%; background-color: ${color}; z-index: 1;">
              ${r.full_name ? r.full_name.charAt(0).toUpperCase() : 'L'}
            </div>
            <div class="card w-100 border-0 text-center shadow-sm d-flex flex-column pt-4 pb-2" style="background-color: var(--surface); height: ${height}; border-top: 4px solid ${color} !important; margin-top: -30px; border-radius: 16px 16px 0 0;">
              <h6 class="fw-bold text-ink-900 mb-0 px-2 text-truncate">${escapeHtml(r.full_name || 'Learner')}</h6>
              <div class="text-muted-ink small mt-1">Level ${r.level}</div>
              <div class="mt-auto mb-2">
                <span class="badge" style="background-color: ${color}20; color: ${color};"><i class="bi bi-star-fill me-1"></i>${Number(r.xp).toLocaleString()} XP</span>
              </div>
            </div>
          </div>`;
        });
        podiumCards.innerHTML = podiumHtml;
      }

      // Table layout
      if (!body) return;
      if (!rows.length) {
        body.innerHTML = '<tr><td colspan="5" class="text-center text-muted-ink py-5 bg-surface-2">No learners on the board yet.</td></tr>';
        return;
      }
      
      const medalIcons = { 1: '<i class="bi bi-trophy-fill" style="color: #f59e0b;"></i>', 2: '<i class="bi bi-award-fill" style="color: #9ca3af;"></i>', 3: '<i class="bi bi-award-fill" style="color: #b45309;"></i>' };
      
      body.innerHTML = rows.map((r) => {
        const isMe = me && me.user_id === r.user_id;
        const initial = r.full_name ? r.full_name.charAt(0).toUpperCase() : 'L';
        const bgRow = isMe ? 'style="background-color: var(--brand-50);"' : '';
        
        return `<tr class="align-middle ${isMe ? 'border-brand border-start border-4' : ''}" ${bgRow}>
          <td class="ps-4">
            <div class="d-flex align-items-center justify-content-center fw-bold fs-6" style="width: 32px; height: 32px;">
              ${medalIcons[r.rank] || '#' + r.rank}
            </div>
          </td>
          <td>
            <div class="d-flex align-items-center gap-3 py-1">
              <div class="avatar-circle bg-surface-2 text-ink-700 fw-bold d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px; border-radius: 50%;">
                ${initial}
              </div>
              <div class="fw-semibold text-ink-900">
                ${escapeHtml(r.full_name || 'Learner')}
                ${isMe ? ' <span class="badge bg-brand text-white ms-2 px-2 py-1 shadow-xs rounded-pill" style="font-size: 0.65rem;">You</span>' : ''}
              </div>
            </div>
          </td>
          <td class="text-center">
            <span class="badge bg-surface-2 border border-ink-200 text-ink-800 rounded-pill px-3 shadow-xs">Lvl ${r.level}</span>
          </td>
          <td class="text-center text-muted-ink fw-semibold">${r.quizzes_completed}</td>
          <td class="text-end pe-4 fw-bold text-ink-900">${Number(r.xp || 0).toLocaleString()} <span class="text-muted-ink small fw-normal ms-1">XP</span></td>
        </tr>`;
      }).join('');
    }

    load();
  }

  /* ----------------------------------------------------------------------
     18. Certificates list page (#certificates-app) via api/certificates.php
     ---------------------------------------------------------------------- */
  function initCertificates() {
    const app = byId('certificates-app');
    if (!app) return;

    async function load() {
      const { ok, data } = await apiFetch('api/certificates.php', 'GET');
      const listEl = byId('certificates-list');
      const errEl = byId('certificates-error');
      const statsContainer = byId('cert-stats-container');
      
      if (!listEl) return;
      if (!ok || !data) {
        if (errEl) { errEl.hidden = false; errEl.textContent = (data && data.message) || 'Could not load certificates.'; }
        listEl.innerHTML = '<div class="text-center text-muted-ink py-5 bg-surface-2 rounded-4 border border-ink-200">Certificates unavailable.</div>';
        return;
      }
      
      const list = data.certificates || [];
      
      // Update statistics
      if (statsContainer && list.length > 0) {
        statsContainer.style.display = 'flex';
        byId('stat-total-certs').textContent = list.length;
        
        const highestScore = list.reduce((max, c) => {
          const score = Math.round(c.score || 0);
          return score > max ? score : max;
        }, 0);
        byId('stat-highest-score').textContent = highestScore + '%';
      }
      
      if (!list.length) {
        listEl.innerHTML = `
          <div class="card p-5 text-center border-1 border-ink-200 shadow-sm bg-surface rounded-4">
            <div class="d-flex justify-content-center mb-4">
              <div class="avatar-circle avatar-circle-lg bg-surface-2 text-ink-300" style="width: 80px; height: 80px; border-radius: 50%;">
                <i class="bi bi-award fs-1"></i>
              </div>
            </div>
            <h4 class="fw-bold text-ink-900 mb-2">No certificates yet</h4>
            <p class="text-muted-ink mb-4 mx-auto" style="max-width: 400px;">
              Score 80% or higher on any quiz to automatically earn a verifiable certificate of achievement.
            </p>
            <a href="${appUrl('pages/generate.php')}" class="btn btn-brand px-4 py-2 rounded-pill fw-semibold shadow-brand transition-normal">
              <i class="bi bi-magic me-2"></i>Generate a Quiz
            </a>
          </div>
        `;
        return;
      }
      
      listEl.innerHTML = '<div class="row g-4">' + list.map((c) => {
        const score = Math.round(c.score || 0);
        const earnedDate = c.earned_at ? new Date(c.earned_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '';
        const viewUrl = appUrl('pages/view-certificate.php?id=' + encodeURIComponent(c.id));
        const verifyUrl = appUrl('verify-certificate.php?id=' + encodeURIComponent(c.id));
        
        return `
        <div class="col-md-6 col-xl-4 d-flex">
          <div class="card p-0 h-100 shadow-sm border-1 border-ink-200 transition-normal hover-bg-brand-50 rounded-4 w-100 d-flex flex-column overflow-hidden group">
            <div class="bg-surface-2 p-4 border-bottom border-ink-100 position-relative">
              <div class="position-absolute end-0 top-0 mt-3 me-3">
                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill py-1 px-2 d-flex align-items-center gap-1 shadow-xs">
                  <i class="bi bi-patch-check-fill"></i> Score ${score}%
                </span>
              </div>
              <div class="avatar-circle bg-brand text-white shadow-brand d-flex align-items-center justify-content-center mb-3" style="width: 48px; height: 48px; border-radius: 12px;">
                <i class="bi bi-award-fill fs-4"></i>
              </div>
              <h5 class="fw-bold text-ink-900 mb-1 pe-5 lh-sm">${escapeHtml(c.quiz_title || 'Certificate of Achievement')}</h5>
              <div class="small text-muted-ink d-flex align-items-center gap-1">
                <i class="bi bi-calendar-check"></i> Issued ${earnedDate}
              </div>
            </div>
            
            <div class="p-3 d-flex gap-2 mt-auto bg-surface">
              <a class="btn btn-sm bg-surface-2 border-ink-200 text-ink-800 flex-grow-1 fw-semibold transition-normal hover-bg-brand-50" href="${viewUrl}">
                <i class="bi bi-eye me-1 text-brand"></i> View
              </a>
              <a class="btn btn-sm bg-surface-2 border-ink-200 text-ink-800 fw-semibold transition-normal hover-bg-brand-50" href="${verifyUrl}" target="_blank" rel="noopener" title="Verify on public ledger">
                <i class="bi bi-qr-code text-ink-600"></i>
              </a>
            </div>
          </div>
        </div>`;
      }).join('') + '</div>';
    }

    load();
  }

  /* ----------------------------------------------------------------------
     19. Certificate view page (#certificate-view) + QR + PDF download
     ---------------------------------------------------------------------- */
  function initCertificateView() {
    const app = byId('certificate-view');
    if (!app) return;
    const certId = app.getAttribute('data-cert-id');
    if (!certId) return;

    const errEl = byId('cert-error');

    async function load() {
      const { ok, data } = await apiFetch('api/certificates.php?id=' + encodeURIComponent(certId), 'GET');
      const loadingEl = byId('cert-loading');
      const panel = byId('cert-panel');
      if (!ok || !data || !data.certificate) {
        if (loadingEl) loadingEl.classList.add('d-none');
        if (errEl) { errEl.hidden = false; errEl.textContent = (data && data.message) || 'Certificate not found.'; }
        return;
      }
      const c = data.certificate;
      if (byId('cert-name')) byId('cert-name').textContent = c.student_name || 'Learner';
      if (byId('cert-quiz')) byId('cert-quiz').textContent = c.quiz_title || 'Certificate of Achievement';
      
      const scoreVal = Math.round(c.score || 0);
      if (byId('cert-score')) byId('cert-score').textContent = scoreVal + '%';

      // Academic level badge
      let levelText = 'ADVANCED MASTERY';
      if (scoreVal >= 95) levelText = 'HIGHEST HONORS';
      else if (scoreVal >= 90) levelText = 'DISTINCTION';
      else if (scoreVal >= 80) levelText = 'VERIFIED PROFICIENCY';
      if (byId('cert-level')) byId('cert-level').textContent = levelText;

      // Clean formatted date
      const earnedDate = c.earned_at ? new Date(c.earned_at) : new Date();
      const formattedDate = earnedDate.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
      });
      if (byId('cert-date')) byId('cert-date').textContent = formattedDate;
      if (byId('cert-id')) byId('cert-id').textContent = c.id;

      renderQR(c.id);
      wireDownload(c);

      if (loadingEl) loadingEl.classList.add('d-none');
      if (panel) panel.classList.remove('d-none');
    }

    function renderQR(id) {
      const qrEl = byId('cert-qr');
      if (!qrEl || typeof QRCode === 'undefined') return;
      const url = appUrl('verify-certificate.php?id=' + encodeURIComponent(id));
      qrEl.innerHTML = '';
      try {
        new QRCode(qrEl, {
          text: url,
          width: 96,
          height: 96,
          colorDark: '#0f172a',
          colorLight: '#ffffff',
          correctLevel: QRCode.CorrectLevel.M
        });
      } catch (e) {
        /* QR unavailable */
      }
    }

    function wireDownload(c) {
      const btn = byId('cert-download');
      if (!btn) return;
      btn.addEventListener('click', async function () {
        const sheet = document.getElementById('certificate-print-sheet') || document.querySelector('.certificate-sheet');
        if (!sheet) return;

        const origBtnHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Preparing PDF...';

        const studentName = (c.student_name || 'Learner').trim();
        const studentClean = studentName.replace(/[^a-zA-Z0-9_-]/g, '_');
        const pdfFileName = 'QuizSphere-Certificate-' + studentClean + '.pdf';
        const jsPDFConstructor = (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : window.jsPDF;

        // Helper: Pure vector Canva-styled jsPDF fallback
        function generateVectorPdf() {
          if (!jsPDFConstructor) {
            window.print();
            return;
          }
          const doc = new jsPDFConstructor({ orientation: 'landscape', unit: 'mm', format: 'a4' });
          const w = 297, h = 210;

          // Warm Ivory Paper Background
          doc.setFillColor(253, 251, 247);
          doc.rect(0, 0, w, h, 'F');

          // Outer Navy Border
          doc.setDrawColor(15, 23, 42);
          doc.setLineWidth(2.2);
          doc.rect(7, 7, w - 14, h - 14);

          // Inner Dual Gold Borders
          doc.setDrawColor(197, 160, 89);
          doc.setLineWidth(0.8);
          doc.rect(11, 11, w - 22, h - 22);
          doc.setLineWidth(0.3);
          doc.rect(13, 13, w - 26, h - 26);

          // Corner Decorative Diamond Accents
          doc.setFillColor(197, 160, 89);
          const cornerDiamonds = [[17, 17], [w - 17, 17], [17, h - 17], [w - 17, h - 17]];
          cornerDiamonds.forEach(([cx, cy]) => {
            doc.circle(cx, cy, 1.5, 'F');
          });

          // Header: Embed Official QuizSphere Brand Logo
          const logoImg = document.getElementById('cert-brand-logo');
          if (logoImg && logoImg.src) {
            try {
              // Logo native aspect ratio is 1825 / 757 = ~2.41
              const logoW = 46;
              const logoH = 46 / (1825 / 757); // 19.1mm
              doc.addImage(logoImg.src, 'PNG', w / 2 - logoW / 2, 18, logoW, logoH, undefined, 'FAST');
            } catch (eLogo) {
              /* Logo fallback if image untranslatable */
            }
          }

          // Certificate Heading
          doc.setFont('times', 'bold');
          doc.setFontSize(23);
          doc.setTextColor(15, 23, 42);
          doc.text('CERTIFICATE OF ACHIEVEMENT', w / 2, 48, { align: 'center' });

          // Gold Separator Line
          doc.setDrawColor(197, 160, 89);
          doc.setLineWidth(0.5);
          doc.line(w / 2 - 35, 52, w / 2 + 35, 52);

          // Conferred line
          doc.setFont('times', 'italic');
          doc.setFontSize(10.5);
          doc.setTextColor(100, 116, 139);
          doc.text('THIS CREDENTIAL IS PROUDLY PRESENTED TO', w / 2, 64, { align: 'center' });

          // Recipient Name
          doc.setFont('times', 'bold');
          doc.setFontSize(26);
          doc.setTextColor(30, 58, 138);
          doc.text(studentName, w / 2, 78, { align: 'center' });

          // Gold Underline
          doc.setDrawColor(197, 160, 89);
          doc.setLineWidth(0.8);
          doc.line(w / 2 - 45, 82, w / 2 + 45, 82);

          // Description & Quiz Title
          doc.setFont('helvetica', 'normal');
          doc.setFontSize(9.5);
          doc.setTextColor(71, 85, 105);
          doc.text('for demonstrating intellectual rigor, critical thinking, and verified mastery in the AI-curated curriculum of', w / 2, 93, { align: 'center' });

          doc.setFont('times', 'bold');
          doc.setFontSize(16);
          doc.setTextColor(15, 23, 42);
          doc.text(c.quiz_title || 'Certificate of Achievement', w / 2, 102, { align: 'center' });

          // 2 Stat Badges (Score & Assessment Level)
          const scoreVal = Math.round(c.score || 0);
          let levelVal = 'ADVANCED MASTERY';
          if (scoreVal >= 95) levelVal = 'HIGHEST HONORS';
          else if (scoreVal >= 90) levelVal = 'DISTINCTION';
          else if (scoreVal >= 80) levelVal = 'VERIFIED PROFICIENCY';

          // Badge 1 (Score)
          doc.setFillColor(255, 255, 255);
          doc.setDrawColor(197, 160, 89);
          doc.setLineWidth(0.4);
          doc.roundedRect(w / 2 - 58, 112, 52, 15, 2, 2, 'FD');
          doc.setFont('helvetica', 'bold');
          doc.setFontSize(6.5);
          doc.setTextColor(100, 116, 139);
          doc.text('SCORE', w / 2 - 32, 117, { align: 'center' });
          doc.setFont('times', 'bold');
          doc.setFontSize(13);
          doc.setTextColor(15, 23, 42);
          doc.text(scoreVal + '%', w / 2 - 32, 124, { align: 'center' });

          // Badge 2 (Assessment Level)
          doc.setFillColor(254, 252, 232);
          doc.roundedRect(w / 2 + 6, 112, 52, 15, 2, 2, 'FD');
          doc.setFont('helvetica', 'bold');
          doc.setFontSize(6.5);
          doc.setTextColor(146, 64, 14);
          doc.text('ASSESSMENT LEVEL', w / 2 + 32, 117, { align: 'center' });
          doc.setFont('times', 'bold');
          doc.setFontSize(11);
          doc.setTextColor(146, 64, 14);
          doc.text(levelVal, w / 2 + 32, 124, { align: 'center' });

          // Footer Left: Issuance & Identification Metadata
          const earnedDate = c.earned_at ? new Date(c.earned_at) : new Date();
          const dateStr = earnedDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
          
          doc.setFont('helvetica', 'bold');
          doc.setFontSize(6.5);
          doc.setTextColor(100, 116, 139);
          doc.text('ISSUED ON', 26, 150);
          doc.setFont('times', 'bold');
          doc.setFontSize(9.5);
          doc.setTextColor(15, 23, 42);
          doc.text(dateStr, 26, 156);

          doc.setFont('helvetica', 'bold');
          doc.setFontSize(6.5);
          doc.setTextColor(100, 116, 139);
          doc.text('CERTIFICATE ID', 26, 164);
          doc.setFont('courier', 'bold');
          doc.setFontSize(6.5);
          doc.setTextColor(51, 65, 85);
          doc.text((c.id || '').substring(0, 26) + (c.id && c.id.length > 26 ? '...' : ''), 26, 170);

          doc.setFont('helvetica', 'bold');
          doc.setFontSize(6);
          doc.setTextColor(4, 120, 87);
          doc.text('Official Institutional Registry', 26, 177);

          // Footer Center: Gold Medallion Rosette Seal
          doc.setFillColor(212, 175, 55);
          doc.circle(w / 2, 160, 15, 'F');
          doc.setFillColor(253, 251, 247);
          doc.circle(w / 2, 160, 13.5, 'F');
          doc.setDrawColor(197, 160, 89);
          doc.setLineWidth(0.4);
          doc.circle(w / 2, 160, 12.5, 'D');

          doc.setFont('times', 'bold');
          doc.setFontSize(6);
          doc.setTextColor(15, 23, 42);
          doc.text('QUIZSPHERE', w / 2, 158.5, { align: 'center' });
          doc.setFont('helvetica', 'bold');
          doc.setFontSize(4.5);
          doc.setTextColor(180, 83, 9);
          doc.text('OFFICIAL SEAL', w / 2, 162.5, { align: 'center' });
          doc.setFontSize(4);
          doc.setTextColor(15, 23, 42);
          doc.text('VERIFIED', w / 2, 166, { align: 'center' });

          // Footer Right: Scannable QR Code & Instructions
          let qrPlaced = false;
          try {
            const qrCanvas = document.querySelector('#cert-qr canvas');
            const qrImg = document.querySelector('#cert-qr img');
            const qrData = qrCanvas ? qrCanvas.toDataURL('image/png') : (qrImg ? qrImg.src : null);
            if (qrData) {
              doc.setFillColor(255, 255, 255);
              doc.setDrawColor(197, 160, 89);
              doc.setLineWidth(0.4);
              doc.roundedRect(w - 68, 142, 46, 42, 2, 2, 'FD');
              doc.addImage(qrData, 'PNG', w - 58, 145, 26, 26);
              doc.setFont('helvetica', 'bold');
              doc.setFontSize(5.5);
              doc.setTextColor(100, 116, 139);
              doc.text('Scan to Verify', w - 45, 175, { align: 'center' });
              doc.setFontSize(4.5);
              doc.text('Public Verification Ledger', w - 45, 179, { align: 'center' });
              qrPlaced = true;
            }
          } catch (eQr) {
            /* QR placement fallback */
          }

          if (!qrPlaced) {
            doc.setFont('courier', 'normal');
            doc.setFontSize(6.5);
            doc.setTextColor(100, 116, 139);
            doc.text('Certificate ID: ' + c.id, w - 45, 160, { align: 'center' });
          }

          doc.save(pdfFileName);
        }

        // Try Stage 1: html2canvas
        let renderedSuccessfully = false;
        if (typeof window.html2canvas !== 'undefined' && jsPDFConstructor) {
          try {
            if (document.fonts && document.fonts.ready) {
              await Promise.race([document.fonts.ready, new Promise(res => setTimeout(res, 800))]);
            }

            const canvas = await window.html2canvas(sheet, {
              scale: 2.0,
              useCORS: true,
              allowTaint: false,
              backgroundColor: '#fdfbf7',
              logging: false,
              onclone: function (clonedDoc) {
                const s = clonedDoc.getElementById('certificate-print-sheet') || clonedDoc.querySelector('.certificate-sheet');
                if (s) {
                  s.style.width = '1040px';
                  s.style.height = '735px';
                  s.style.maxWidth = '1040px';
                  s.style.aspectRatio = 'unset';
                  s.style.boxShadow = 'none';
                  s.style.transform = 'none';
                }
              }
            });

            const imgData = canvas.toDataURL('image/png', 0.95);
            const doc = new jsPDFConstructor({
              orientation: 'landscape',
              unit: 'mm',
              format: 'a4',
              compress: true
            });

            doc.addImage(imgData, 'PNG', 0, 0, 297, 210, undefined, 'FAST');
            doc.save(pdfFileName);
            renderedSuccessfully = true;
          } catch (errHtml2Canvas) {
            console.warn('html2canvas capture skipped/failed, switching to vector PDF fallback:', errHtml2Canvas);
          }
        }

        // Stage 2: If Stage 1 did not run or threw, execute the vector fallback
        if (!renderedSuccessfully) {
          try {
            generateVectorPdf();
          } catch (errVector) {
            console.error('Vector PDF fallback also failed:', errVector);
            window.print();
          }
        }

        btn.disabled = false;
        btn.innerHTML = origBtnHtml;
      });
    }

    load();
  }

  /* ----------------------------------------------------------------------
     20. Public certificate verification (#verify-app) on verify-certificate.php
     ---------------------------------------------------------------------- */
  function initVerify() {
    const app = byId('verify-app');
    if (!app) return;
    const form = byId('verify-form');
    const input = byId('verify-input');
    if (!form || !input) return;

    async function executeVerify(id) {
      if (!id) { showVerifyError('Please enter a certificate ID.'); return; }
      if (!/^[0-9a-fA-F-]{36}$/.test(id)) { showVerifyError('That does not look like a valid certificate ID.'); return; }

      const loading = byId('verify-loading');
      const error = byId('verify-error');
      const result = byId('verify-result');
      if (loading) loading.classList.remove('d-none');
      if (error) error.classList.add('d-none');
      if (result) result.classList.add('d-none');

      const { ok, data } = await apiFetch('api/verify-certificate.php?id=' + encodeURIComponent(id), 'GET');
      if (loading) loading.classList.add('d-none');

      if (!ok || !data || !data.verified) {
        showVerifyError((data && data.message) || 'This certificate could not be verified.');
        return;
      }
      const c = data.certificate;
      if (byId('v-id')) byId('v-id').textContent = c.id;
      if (byId('v-name')) byId('v-name').textContent = c.student_name || 'Learner';
      if (byId('v-achievement')) byId('v-achievement').textContent = c.quiz_title || c.title || 'Certificate of Achievement';
      if (byId('v-score')) byId('v-score').textContent = Math.round(c.score || 0) + '%';
      
      const earnedDate = c.earned_at ? new Date(c.earned_at) : new Date();
      if (byId('v-date')) byId('v-date').textContent = earnedDate.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
      });
      
      // Update link to view certificate if element exists
      const viewCertBtn = byId('v-view-cert');
      if (viewCertBtn) {
        viewCertBtn.href = appUrl('pages/view-certificate.php?id=' + encodeURIComponent(c.id));
      }

      if (result) result.classList.remove('d-none');
    }

    on(form, 'submit', (e) => {
      e.preventDefault();
      const id = (input.value || '').trim();
      executeVerify(id);
    });

    // Auto-verify if ID is present in query parameters (e.g. from QR scan)
    const urlParams = new URLSearchParams(window.location.search);
    const paramId = (urlParams.get('id') || '').trim();
    if (paramId && /^[0-9a-fA-F-]{36}$/.test(paramId)) {
      input.value = paramId;
      executeVerify(paramId);
    }

    function showVerifyError(msg) {
      const error = byId('verify-error');
      const result = byId('verify-result');
      if (error) { error.textContent = msg; error.classList.remove('d-none'); }
      if (result) result.classList.add('d-none');
    }
  }

  /* ----------------------------------------------------------------------
     Bootstrap page wiring
     ---------------------------------------------------------------------- */
  document.addEventListener('DOMContentLoaded', function () {
    initSidebarDrawer();
    initSmoothScroll();
    initPasswordToggles();
    initPasswordStrength();
    initAuthForms();
    initLogout();
    initGenerateForm();
    initQuizLoader();
    initResultsLoader();
    initDashboardAnalytics();
    initUploadForm();
    initCoach();
    initDashboardGamification();
    initLeaderboard();
    initCertificates();
    initCertificateView();
    initVerify();
  });
})();
