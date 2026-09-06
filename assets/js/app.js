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
            borderColor: '#3b6ef6',
            backgroundColor: 'rgba(59, 110, 246, 0.12)',
            fill: true,
            tension: 0.35,
            pointRadius: 4,
            pointBackgroundColor: '#3b6ef6'
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, max: 100, grid: { color: '#eef2f7' } },
          x: { grid: { display: false } }
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

    async function submitQuiz() {
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

      // Line chart (trend)
      renderTrendChart(data.trend || [], data.stats.total_attempts);

      // Weak / developing / strong lists
      renderAreaList('weak-list', data.weak || []);
      renderAreaList('developing-list', data.developing || [], 'warning');
      renderAreaList('strong-list', data.strong || [], 'success');

      // Recommendations (practice button)
      renderRecommendation(data.recommendations || {});

      // Wire any practice buttons that were just rendered.
      initPracticeButtons();

      // Insights
      renderInsights(data.insights || []);
    }

    load();
  }

  function renderTrendChart(trend, totalAttempts) {
    const canvas = byId('performance-chart');
    if (!canvas) return;
    if (typeof Chart === 'undefined') return;
    const labels = trend.length ? trend.map((t) => t.label) : ['No data'];
    const scores = trend.length ? trend.map((t) => t.score) : [0];
    new Chart(canvas, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: 'Average Score (%)',
          data: scores,
          borderColor: '#3b6ef6',
          backgroundColor: 'rgba(59, 110, 246, 0.12)',
          fill: true,
          tension: 0.35,
          pointRadius: 4,
          pointBackgroundColor: '#3b6ef6'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, max: 100, grid: { color: '#eef2f7' } },
          x: { grid: { display: false } }
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

    if (note) note.textContent = 'Advice is generated from your quiz performance only — the coach never invents facts about you.';

    const scroll = () => { messagesEl.scrollTop = messagesEl.scrollHeight; };
    const addBubble = (role, text, loading) => {
      const row = document.createElement('div');
      row.className = 'coach-msg coach-msg-' + role + (loading ? ' coach-loading' : '');
      row.innerHTML = (role === 'coach' ? '<div class="coach-avatar"><i class="bi bi-stars"></i></div>' : '') +
        '<div class="coach-bubble">' + (loading ? '<i class="bi bi-three-dots"></i>' : escapeHtml(text)) + '</div>';
      messagesEl.appendChild(row);
      scroll();
      return { row, bubble: row.querySelector('.coach-bubble') };
    };

    const sendMsg = async (text) => {
      const clean = (text || '').trim();
      if (!clean) { input.focus(); return; }
      addBubble('user', clean);
      input.value = '';
      input.disabled = true;
      send.disabled = true;
      const loading = addBubble('coach', '', true);

      try {
        const { ok, data } = await apiFetch('api/coach.php', 'POST', { message: clean, history });
        loading.bubble.textContent = (data && data.message) || 'Sorry, something went wrong. Please try again.';
        loading.row.classList.remove('coach-loading');
        if (ok && data && data.message) {
          history.push({ role: 'user', content: clean });
          history.push({ role: 'coach', content: data.message });
        }
        scroll();
      } catch (err) {
        loading.bubble.textContent = 'Network error. Please check your connection and try again.';
        loading.row.classList.remove('coach-loading');
      } finally {
        input.disabled = false;
        send.disabled = false;
        input.focus();
      }
    };

    on(form, 'submit', (e) => { e.preventDefault(); sendMsg(input.value); });
    app.querySelectorAll('[data-suggestion]').forEach((chip) => {
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
        renderAchievements((data.achievements || {}));
      }
      loadCertificatePreview();
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
      if (!ok || !data) {
        if (errEl) { errEl.hidden = false; errEl.textContent = (data && data.message) || 'Could not load the leaderboard.'; }
        if (body) body.innerHTML = '<tr><td colspan="5" class="text-center text-muted-ink py-4">Leaderboard unavailable.</td></tr>';
        return;
      }

      // My rank card
      const meWrap = byId('leaderboard-me');
      if (meWrap) {
        const me = data.me;
        if (me && me.rank) {
          const nameEl = meWrap.querySelector('.fw-semibold');
          if (nameEl) nameEl.textContent = 'Your rank: #' + me.rank + ' (' + me.xp + ' XP, Level ' + me.level + ')';
          const sub = meWrap.querySelector('.small');
          if (sub) sub.textContent = 'Stay consistent to climb higher!';
        } else {
          const nameEl = meWrap.querySelector('.fw-semibold');
          if (nameEl) nameEl.textContent = 'No rank yet';
          const sub = meWrap.querySelector('.small');
          if (sub) sub.textContent = 'Complete quizzes to earn XP and appear on the leaderboard.';
        }
      }

      // Top learners
      if (!body) return;
      const rows = data.leaderboard || [];
      if (!rows.length) {
        body.innerHTML = '<tr><td colspan="5" class="text-center text-muted-ink py-4">No learners on the board yet.</td></tr>';
        return;
      }
      const medal = { 1: '🥇', 2: '🥈', 3: '🥉' };
      body.innerHTML = rows.map((r) => {
        const isMe = data.me && data.me.user_id === r.user_id;
        return '<tr' + (isMe ? ' class="table-active"' : '') + '>' +
          '<td><span class="fw-bold">' + (medal[r.rank] || '#' + r.rank) + '</span></td>' +
          '<td class="fw-semibold">' + escapeHtml(r.full_name || 'Learner') + (isMe ? ' <span class="badge bg-brand-subtle">You</span>' : '') + '</td>' +
          '<td class="text-end">' + r.level + '</td>' +
          '<td class="text-end">' + r.quizzes_completed + '</td>' +
          '<td class="text-end fw-bold">' + Number(r.xp || 0).toLocaleString() + ' XP</td>' +
        '</tr>';
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
      if (!listEl) return;
      if (!ok || !data) {
        if (errEl) { errEl.hidden = false; errEl.textContent = (data && data.message) || 'Could not load certificates.'; }
        listEl.innerHTML = '<div class="text-center text-muted-ink py-4">Certificates unavailable.</div>';
        return;
      }
      const list = data.certificates || [];
      if (!list.length) {
        listEl.innerHTML = '<div class="text-center py-5"><i class="bi bi-award fs-3 text-brand"></i>' +
          '<p class="text-muted-ink mb-0 mt-2">No certificates yet. Score 80% or higher on a quiz to earn one.</p></div>';
        return;
      }
      listEl.innerHTML = '<div class="row g-3">' + list.map((c) => {
        return '<div class="col-md-6"><div class="card p-4 h-100">' +
          '<div class="d-flex align-items-center gap-3">' +
            '<div class="icon-badge"><i class="bi bi-award"></i></div>' +
            '<div class="flex-grow-1">' +
              '<div class="fw-semibold">' + escapeHtml(c.quiz_title || 'Certificate of Achievement') + '</div>' +
              '<div class="small text-muted-ink">Score ' + Math.round(c.score || 0) + '% · ' + new Date(c.earned_at).toLocaleDateString() + '</div>' +
            '</div>' +
          '</div>' +
          '<div class="d-flex gap-2 mt-3">' +
            '<a class="btn btn-sm btn-brand" href="' + appUrl('pages/view-certificate.php?id=' + encodeURIComponent(c.id)) + '"><i class="bi bi-eye me-1"></i>View</a>' +
            '<a class="btn btn-sm btn-outline-brand" href="' + appUrl('verify-certificate.php?id=' + encodeURIComponent(c.id)) + '" target="_blank" rel="noopener"><i class="bi bi-patch-check me-1"></i>Verify</a>' +
          '</div>' +
        '</div></div>';
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
        loadingEl.classList.add('d-none');
        if (errEl) { errEl.hidden = false; errEl.textContent = (data && data.message) || 'Certificate not found.'; }
        return;
      }
      const c = data.certificate;
      if (byId('cert-name')) byId('cert-name').textContent = c.student_name || 'Learner';
      if (byId('cert-quiz')) byId('cert-quiz').textContent = c.quiz_title || 'Certificate of Achievement';
      if (byId('cert-score')) byId('cert-score').textContent = Math.round(c.score || 0) + '%';
      if (byId('cert-date')) byId('cert-date').textContent = new Date(c.earned_at).toLocaleDateString();
      if (byId('cert-id')) byId('cert-id').textContent = c.id;

      renderQR(c.id);
      wireDownload(c);

      loadingEl.classList.add('d-none');
      panel.classList.remove('d-none');
    }

    function renderQR(id) {
      const qrEl = byId('cert-qr');
      if (!qrEl || typeof QRCode === 'undefined') return;
      const url = appUrl('verify-certificate.php?id=' + encodeURIComponent(id));
      qrEl.innerHTML = '';
      try { new QRCode(qrEl, { text: url, width: 128, height: 128, correctLevel: QRCode.CorrectLevel.M }); }
      catch (e) { /* QR unavailable */ }
    }

    function wireDownload(c) {
      const btn = byId('cert-download');
      if (!btn || typeof window.jspdf === 'undefined') return;
      btn.addEventListener('click', function () {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
        const w = 297, h = 210;
        doc.setFillColor(245, 247, 255); doc.rect(0, 0, w, h, 'F');
        doc.setDrawColor(37, 82, 235); doc.setLineWidth(1.2); doc.rect(8, 8, w - 16, h - 16);
        doc.setTextColor(30, 50, 137);
        doc.setFontSize(14); doc.text('QuizSphere', w / 2, 30, { align: 'center' });
        doc.setFontSize(26); doc.setTextColor(15, 23, 42); doc.text('Certificate of Achievement', w / 2, 52, { align: 'center' });
        doc.setFontSize(12); doc.setTextColor(100, 116, 139); doc.text('This certifies that', w / 2, 72, { align: 'center' });
        doc.setFontSize(28); doc.setTextColor(37, 82, 235);
        doc.text(c.student_name || 'Learner', w / 2, 88, { align: 'center' });
        doc.setFontSize(12); doc.setTextColor(100, 116, 139); doc.text('has successfully completed', w / 2, 102, { align: 'center' });
        doc.setFontSize(18); doc.setTextColor(15, 23, 42); doc.text(c.quiz_title || 'Certificate of Achievement', w / 2, 116, { align: 'center' });
        doc.setFontSize(12); doc.setTextColor(51, 65, 85);
        doc.text('Score: ' + Math.round(c.score || 0) + '%', w / 2, 134, { align: 'center' });
        doc.text('Date: ' + new Date(c.earned_at).toLocaleDateString(), w / 2, 142, { align: 'center' });
        doc.setFontSize(10); doc.setTextColor(100, 116, 139);
        doc.text('Certificate ID: ' + c.id, w / 2, 158, { align: 'center' });
        doc.text('Verify at ' + appUrl('verify-certificate.php?id=' + encodeURIComponent(c.id)), w / 2, 166, { align: 'center' });
        doc.save('QuizSphere-Certificate-' + c.id + '.pdf');
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

    on(form, 'submit', async (e) => {
      e.preventDefault();
      const id = (input.value || '').trim();
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
      if (byId('v-date')) byId('v-date').textContent = new Date(c.earned_at).toLocaleDateString();
      if (result) result.classList.remove('d-none');
    });

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
