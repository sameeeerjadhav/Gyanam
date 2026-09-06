/**
 * Main Application Entry Point - Gyanam Online Examination Portal
 *
 * Fixes:
 * - Single shared AuthenticationModule instance
 * - Exam route correctly fetches questions from API before launching ExamPage
 * - /student/result/:id route registered and renders result screen
 * - All routes guarded
 */

import router from './services/Router.js';
import { getAuthModule } from './services/AuthenticationModule.js';
import LoginPage from './pages/LoginPage.js?v=2';
import { StudentDashboard } from './pages/StudentDashboard.js?v=2';
import ExamPage from './pages/ExamPage.js?v=5';
import ApiClient from './services/APIClient.js';

// Single shared auth module
const authModule = getAuthModule();

let loginPage = null;
let studentDashboard = null;
let examPage = null;

/** Destroy the currently active page component before routing to a new one */
function destroyCurrentPage() {
  if (loginPage && typeof loginPage.destroy === 'function') loginPage.destroy();
  if (studentDashboard && typeof studentDashboard.destroy === 'function') studentDashboard.destroy();
  if (examPage && typeof examPage.destroy === 'function') examPage.destroy();
}

function initializeApp() {
  const appContainer = document.getElementById('app');
  if (!appContainer) { console.error('App container not found'); return; }

  // Global 401 handler — soft during exam (keep answers), hard elsewhere
  window.addEventListener('gyanam:unauthorized', (e) => {
    const soft = !!(e.detail?.soft && examPage);
    if (soft && typeof examPage.showSessionExpiredOverlay === 'function') {
      examPage.showSessionExpiredOverlay(authModule);
      return;
    }
    try { authModule._session = null; } catch (_) {}
    router.navigate('/login');
  });

  setupRoutes(appContainer);
  router.initialize();

  if (authModule.isAuthenticated()) {
    const path = window.location.pathname || '';
    const search = window.location.search || '';
    if (path.includes('/exam') || (search.includes('id=') && path.includes('exam'))) {
      router.handleRoute((router.basePath || '') + '/exam');
    } else if (search.includes('id=') && /exam/i.test(path + search)) {
      router.handleRoute((router.basePath || '') + '/exam');
    } else {
      router.navigate('/student');
    }
  } else {
    router.navigate('/login');
  }
}

function setupRoutes(appContainer) {

  // ─── Login ──────────────────────────────────────────────────────────────────
  router.register('/login', () => {
    destroyCurrentPage();
    if (authModule.isAuthenticated()) { router.navigate('/student'); return; }
    if (!loginPage) loginPage = new LoginPage(authModule);
    loginPage.render(appContainer);
  });

  // ─── Student Dashboard ──────────────────────────────────────────────────────
  router.register('/student', () => {
    destroyCurrentPage();
    if (!authModule.isAuthenticated()) { router.navigate('/login'); return; }
    studentDashboard = new StudentDashboard(authModule, null, router);
    studentDashboard.initialize(appContainer);
  });

  // ─── Exam ────────────────────────────────────────────────────────────────────
  router.register('/exam', async (params) => {
    destroyCurrentPage();
    if (!authModule.isAuthenticated()) { router.navigate('/login'); return; }

    // Get examId from query params (?id=X)
    const urlParams = new URLSearchParams(window.location.search);
    const examId = params?.id || urlParams.get('id');

    if (!examId) {
      appContainer.innerHTML = _errorHTML('No exam ID provided.', 'Please go back and select an exam.');
      return;
    }

    // Show loading state
    appContainer.innerHTML = _loadingHTML('Loading exam questions...');

    try {
      const data = await ApiClient.getExamQuestions(examId);
      const { exam, questions, draft } = data;

      if (!questions || questions.length === 0) {
        appContainer.innerHTML = _errorHTML('No questions found.', 'This exam has no questions assigned yet.');
        return;
      }

      // Always create a fresh ExamPage for a clean session
      examPage = new ExamPage();
      await examPage.render(appContainer, exam, questions, examId, router, draft);

    } catch (error) {
      console.error('Failed to load exam:', error);
      appContainer.innerHTML = _errorHTML('Failed to Load Exam', error.message, true);
    }
  });

  // ─── Student Result Page ─────────────────────────────────────────────────────
  router.register('/student/result/:submissionId', async (params) => {
    if (!authModule.isAuthenticated()) { router.navigate('/login'); return; }

    const submissionId = params?.submissionId;
    if (!submissionId) { router.navigate('/student'); return; }

    appContainer.innerHTML = _loadingHTML('Loading your results...');

    try {
      const data = await ApiClient.getSubmissionResult(submissionId);

      if (data.status === 'not_found') {
        appContainer.innerHTML = _errorHTML('Result Not Found', 'Your submission could not be found.', true);
        return;
      }

      const sub = data.submission;
      const isPassed = sub.result === 'pass';
      const outcome = isPassed ? 'pass' : 'fail';
      const answers  = sub.answers || [];
      const scoreNum = Math.max(0, Math.min(100, Number(sub.score) || 0));
      const circumference = 2 * Math.PI * 70;
      const dashOffset = circumference - (scoreNum / 100) * circumference;
      const wrongCount = answers.filter(a => !a.is_correct).length;
      const correctCount = answers.length ? answers.filter(a => a.is_correct).length : (sub.correct_answers ?? 0);

      // Clear leftover exam overlays; pick up auto-submit notice from session
      document.getElementById('proctoring-warning-overlay')?.remove();
      let notice = null;
      try {
        const raw = sessionStorage.getItem('gyanam_result_notice');
        if (raw) {
          notice = JSON.parse(raw);
          sessionStorage.removeItem('gyanam_result_notice');
        }
      } catch (_) { notice = null; }

      const submittedLabel = sub.submitted_at
        ? new Date(sub.submitted_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
        : '';

      const escapeAttr = (s) => String(s || '')
        .replace(/\\/g, '\\\\')
        .replace(/'/g, "\\'")
        .substring(0, 80);

      const answerReviewHTML = answers.length > 0 ? `
        <div class="result-review">
          <div class="result-review-head">
            <div class="result-review-title">Answer review</div>
            <div class="result-review-count">${correctCount} correct · ${wrongCount} incorrect</div>
          </div>
          ${answers.map((a, i) => {
            const opts = typeof a.options === 'string' ? JSON.parse(a.options) : (a.options || []);
            const correctOpt = opts.find(o => String(o.id) === String(a.correct_answer));
            const selectedOpt = opts.find(o => String(o.id) === String(a.selected_answer));
            const ok = !!a.is_correct;
            return `
              <div class="result-answer ${ok ? 'correct' : 'wrong'}">
                <div class="result-answer-top">
                  <div class="result-answer-q">${i + 1}. ${a.question_text || '—'}</div>
                  <span class="result-answer-badge">${ok ? 'Correct' : 'Incorrect'}</span>
                </div>
                <div class="result-answer-meta">
                  Your answer: <strong class="${ok ? 'ok' : 'bad'}">${selectedOpt ? selectedOpt.text : (a.selected_answer || 'Not answered')}</strong>
                  ${!ok ? `<br>Correct answer: <strong class="ok">${correctOpt ? correctOpt.text : a.correct_answer}</strong>` : ''}
                </div>
                ${!ok ? `
                  <button type="button" class="result-challenge-btn"
                    onclick="window._openChallengeModal(${sub.submission_db_id},${a.question_id},'${escapeAttr(a.question_text)}')">
                    Challenge this question
                  </button>` : ''}
              </div>`;
          }).join('')}
        </div>` : '';

      appContainer.innerHTML = `
        <div class="result-page">
          <div class="result-page-inner">
            <div class="result-brand">
              <div class="result-brand-mark">
                <img src="assets/logo.png" alt="" onerror="this.style.display='none'">
                <span>Gyanam Exam Portal</span>
              </div>
              <div class="result-brand-sub">Results</div>
            </div>

            ${notice?.type === 'auto_submit' ? `
              <div class="result-notice" style="margin-bottom:0.85rem">
                <div class="result-notice-icon">!</div>
                <div>
                  <div class="result-notice-title">Exam auto-submitted</div>
                  <div class="result-notice-text">${notice.message || 'This exam was auto-submitted due to a proctoring rule.'}</div>
                </div>
              </div>` : ''}

            <div class="result-card">
              <div class="result-card-accent ${outcome}"></div>
              <div class="result-card-body">
                <div class="result-status-pill ${outcome}">${isPassed ? 'Passed' : 'Did not pass'}</div>
                <h1 class="result-title ${outcome}">${isPassed ? 'Congratulations!' : 'Keep practicing'}</h1>
                <p class="result-subtitle">${sub.exam_title || 'Exam'} — final results</p>

                <div class="result-score-wrap">
                  <svg class="result-score-ring" viewBox="0 0 160 160" aria-hidden="true">
                    <circle class="track" cx="80" cy="80" r="70"></circle>
                    <circle class="progress ${outcome}" cx="80" cy="80" r="70"
                      stroke-dasharray="${circumference.toFixed(2)}"
                      stroke-dashoffset="${dashOffset.toFixed(2)}"></circle>
                  </svg>
                  <div class="result-score-center">
                    <div class="result-score-value ${outcome}">${sub.score}%</div>
                    <div class="result-score-label">Score</div>
                  </div>
                </div>

                <div class="result-stats">
                  <div class="result-stat">
                    <div class="result-stat-label">Correct</div>
                    <div class="result-stat-value">${sub.correct_answers}</div>
                  </div>
                  <div class="result-stat">
                    <div class="result-stat-label">Total questions</div>
                    <div class="result-stat-value">${sub.total_questions}</div>
                  </div>
                  <div class="result-stat">
                    <div class="result-stat-label">Result</div>
                    <div class="result-stat-value ${outcome}">${isPassed ? 'PASS' : 'FAIL'}</div>
                  </div>
                  <div class="result-stat">
                    <div class="result-stat-label">Passing score</div>
                    <div class="result-stat-value">${sub.passing_score || 40}%</div>
                  </div>
                </div>

                ${submittedLabel || sub.student_name ? `
                  <div class="result-meta">
                    ${sub.student_name ? `<span>${sub.student_name}</span>` : ''}
                    ${submittedLabel ? `<span>${submittedLabel}</span>` : ''}
                  </div>` : ''}

                ${answerReviewHTML}

                <div class="result-actions">
                  <button type="button" class="result-btn-primary" id="result-back-btn">
                    ← Back to dashboard
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div id="challenge-overlay" class="result-challenge-modal">
          <div class="result-challenge-dialog">
            <h3>Challenge this question</h3>
            <p id="challenge-q-text" style="font-size:0.8rem;color:#64748b;margin:0 0 1rem"></p>
            <label for="challenge-reason">Reason *</label>
            <select id="challenge-reason">
              <option value="Wrong answer key">Wrong answer key listed</option>
              <option value="Question is ambiguous">Question is ambiguous</option>
              <option value="Question has a typo">Question has a typo</option>
              <option value="Options are incorrect">Options are incorrect</option>
              <option value="Other">Other</option>
            </select>
            <label for="challenge-detail">Additional details</label>
            <textarea id="challenge-detail" placeholder="Optional notes…" rows="3"></textarea>
            <div class="result-challenge-actions">
              <button type="button" class="result-challenge-cancel" onclick="document.getElementById('challenge-overlay').style.display='none'">Cancel</button>
              <button type="button" class="result-challenge-submit" id="challenge-submit-btn" onclick="window._submitChallenge()">Submit challenge</button>
            </div>
          </div>
        </div>
      `;

      document.getElementById('result-back-btn')?.addEventListener('click', () => {
        if (typeof router?.navigate === 'function') router.navigate('/student');
        else {
          window.history.pushState(null, '', '/student');
          window.dispatchEvent(new PopStateEvent('popstate'));
        }
      });

      // Challenge modal logic
      let _challengeSubId = null, _challengeQId = null;
      window._openChallengeModal = (subId, qId, qText) => {
        _challengeSubId = subId; _challengeQId = qId;
        document.getElementById('challenge-q-text').textContent = qText + '…';
        document.getElementById('challenge-detail').value = '';
        document.getElementById('challenge-overlay').style.display = 'flex';
      };
      window._submitChallenge = async () => {
        const reason = document.getElementById('challenge-reason').value;
        const detail = document.getElementById('challenge-detail').value.trim();
        const fullReason = detail ? `${reason}: ${detail}` : reason;
        const btn = document.getElementById('challenge-submit-btn');
        btn.disabled = true; btn.textContent = 'Submitting…';
        try {
          await ApiClient.flagQuestion(_challengeSubId, _challengeQId, fullReason);
          document.getElementById('challenge-overlay').style.display = 'none';
          const banner = document.createElement('div');
          banner.textContent = 'Challenge submitted — admin will review it.';
          banner.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;background:#1d4ed8;color:#fff;padding:0.875rem 1.25rem;border-radius:12px;font-weight:600;z-index:9999;box-shadow:0 4px 20px rgba(29,78,216,0.3)';
          document.body.appendChild(banner);
          setTimeout(() => banner.remove(), 4500);
        } catch (e) {
          alert('Failed to submit: ' + e.message);
          btn.disabled = false; btn.textContent = 'Submit challenge';
        }
      };

    } catch (error) {
      console.error('Failed to load result:', error);
      appContainer.innerHTML = _errorHTML('Failed to Load Result', error.message, true);
    }
  });


  // ─── Admin / ATC / DLC placeholders ─────────────────────────────────────────
  [
    { path: '/admin', title: 'Admin Dashboard', desc: 'Please use admin.html for the full admin portal' },
    { path: '/atc', title: 'ATC Dashboard', desc: 'Assessment and test control' },
    { path: '/dlc', title: 'DLC Dashboard', desc: 'Digital learning center' },
  ].forEach(({ path, title, desc }) => {
    router.register(path, () => {
      appContainer.innerHTML = `
        <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f8fafc;font-family:'Inter',sans-serif;">
          <div style="text-align:center;color:#0f172a;padding:2rem;">
            <div style="width:80px;height:80px;background:#1d4ed8;border-radius:1.5rem;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;font-size:2rem;box-shadow:0 8px 20px rgba(29,78,216,0.15);">🚧</div>
            <h1 style="font-size:2.25rem;font-weight:800;margin-bottom:0.75rem;letter-spacing:-0.02em;">${title}</h1>
            <p style="color:#64748b;margin-bottom:2.5rem;font-size:1.125rem;max-width:400px;margin-left:auto;margin-right:auto;">${desc}</p>
            <a href="admin.html" style="background:#1d4ed8;color:white;padding:0.875rem 2.5rem;border-radius:0.75rem;text-decoration:none;font-weight:700;display:inline-block;transition:all 0.2s;"
               onmouseover="this.style.background='#1e40af'"
               onmouseout="this.style.background='#1d4ed8'">
              Open Admin Portal &rarr;
            </a>
          </div>
        </div>
      `;
    });
  });
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
function _loadingHTML(message) {
  return `
    <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f8fafc;font-family:'Inter',sans-serif;">
      <div style="text-align:center;color:#0f172a;">
        <div style="width:52px;height:52px;border:4px solid #e2e8f0;border-top-color:#1d4ed8;border-radius:50%;animation:spin 0.8s linear infinite;margin:0 auto 1.25rem;"></div>
        <style>@keyframes spin{to{transform:rotate(360deg)}}</style>
        <p style="color:#64748b;font-weight:600;font-size:1rem;">${message}</p>
      </div>
    </div>
  `;
}

function _errorHTML(title, message, showBack = false) {
  return `
    <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f8fafc;font-family:'Inter',sans-serif;">
      <div style="text-align:center;color:#0f172a;padding:2rem;max-width:440px;">
        <div style="width:64px;height:64px;background:#fef2f2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;font-size:2rem;">❌</div>
        <h2 style="font-size:1.5rem;font-weight:800;color:#dc2626;margin-bottom:0.75rem;letter-spacing:-0.02em;">${title}</h2>
        <p style="color:#64748b;margin-bottom:2.5rem;font-size:1rem;line-height:1.6;font-weight:500;">${message}</p>
        ${showBack ? `
          <button onclick="window.history.pushState(null,'','/student');window.dispatchEvent(new PopStateEvent('popstate'));" 
            style="background:#1d4ed8;color:white;padding:0.875rem 2.5rem;border:none;border-radius:0.75rem;cursor:pointer;font-weight:700;font-size:1rem;transition:all 0.2s;box-shadow:0 4px 12px rgba(29,78,216,0.15);"
            onmouseover="this.style.background='#1e40af'"
            onmouseout="this.style.background='#1d4ed8'">
            &larr; Back to Dashboard
          </button>
        ` : ''}
      </div>
    </div>
  `;
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializeApp);
} else {
  initializeApp();
}

export { initializeApp };
