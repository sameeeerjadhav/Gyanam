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
import LoginPage from './pages/LoginPage.js';
import { StudentDashboard } from './pages/StudentDashboard.js';
import ExamPage from './pages/ExamPage.js';
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

  // Global 401 handler — redirect to login without a full page reload
  window.addEventListener('gyanam:unauthorized', () => {
    router.navigate('/login');
  });

  setupRoutes(appContainer);
  router.initialize();

  if (authModule.isAuthenticated()) {
    router.navigate('/student');
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
      const { exam, questions } = data;

      if (!questions || questions.length === 0) {
        appContainer.innerHTML = _errorHTML('No questions found.', 'This exam has no questions assigned yet.');
        return;
      }

      // Always create a fresh ExamPage for a clean session
      examPage = new ExamPage();
      await examPage.render(appContainer, exam, questions, examId, router);

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
      const answers  = sub.answers || [];

      // Build answer review HTML
      const answerReviewHTML = answers.length > 0 ? `
        <div style="text-align:left;margin-top:2rem">
          <div style="font-weight:700;font-size:0.9rem;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:1rem">Answer Review</div>
          <div style="display:flex;flex-direction:column;gap:0.75rem">
            ${answers.map((a, i) => {
              const opts = typeof a.options === 'string' ? JSON.parse(a.options) : (a.options || []);
              const correctOpt = opts.find(o => String(o.id) === String(a.correct_answer));
              const selectedOpt = opts.find(o => String(o.id) === String(a.selected_answer));
              return `
              <div style="background:${a.is_correct ? '#f0fdf4' : '#fef2f2'};border:1px solid ${a.is_correct ? '#bbf7d0' : '#fecaca'};border-radius:12px;padding:1rem 1.25rem">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.5rem;margin-bottom:0.5rem">
                  <div style="font-weight:600;font-size:0.875rem;color:#0f172a;flex:1">${i + 1}. ${a.question_text || '—'}</div>
                  <span style="font-size:1.1rem">${a.is_correct ? '✅' : '❌'}</span>
                </div>
                <div style="font-size:0.8rem;color:#475569">
                  <span>Your answer: <strong style="color:${a.is_correct ? '#16a34a' : '#dc2626'}">${selectedOpt ? selectedOpt.text : (a.selected_answer || 'Not answered')}</strong></span>
                  ${!a.is_correct ? `<span style="margin-left:1rem;color:#16a34a">✓ Correct: <strong>${correctOpt ? correctOpt.text : a.correct_answer}</strong></span>` : ''}
                </div>
                ${!a.is_correct ? `
                <div style="margin-top:0.5rem">
                  <button onclick="window._openChallengeModal(${sub.submission_db_id},${a.question_id},'${(a.question_text || '').replace(/'/g, "\\'").substring(0,80)}')"
                    style="background:none;border:1px solid #f59e0b;color:#92400e;padding:0.25rem 0.65rem;border-radius:6px;font-size:0.75rem;cursor:pointer;font-weight:600">
                    🚩 Challenge
                  </button>
                </div>` : ''}
              </div>`;
            }).join('')}
          </div>
        </div>` : '';

      appContainer.innerHTML = `
        <div style="min-height:100vh;background:#f8fafc;padding:2rem;font-family:'Inter',sans-serif;">
          <div style="width:100%;max-width:620px;margin:0 auto;">
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:1.5rem;padding:2.5rem;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,0.04);">

              <!-- Result Icon -->
              <div style="font-size:4rem;margin-bottom:1.25rem;">${isPassed ? '🎉' : '📚'}</div>
              <h1 style="font-size:1.875rem;font-weight:800;color:${isPassed ? '#16a34a' : '#dc2626'};margin:0 0 0.5rem;letter-spacing:-0.02em;">
                ${isPassed ? 'Congratulations!' : 'Keep Practicing'}
              </h1>
              <p style="color:#64748b;margin:0 0 2.5rem;font-size:1rem;font-weight:500;">${sub.exam_title || 'Exam'} — Final Results</p>

              <!-- Score Circle -->
              <div style="width:140px;height:140px;border-radius:50%;background:${isPassed ? '#f0fdf4' : '#fef2f2'};border:4px solid ${isPassed ? '#16a34a' : '#dc2626'};display:flex;flex-direction:column;align-items:center;justify-content:center;margin:0 auto 2.5rem;box-shadow:0 4px 12px ${isPassed ? 'rgba(22,163,74,0.1)' : 'rgba(220,38,38,0.1)'};">
                <div style="font-size:2.75rem;font-weight:800;color:${isPassed ? '#16a34a' : '#dc2626'};">${sub.score}%</div>
                <div style="font-size:0.75rem;color:#64748b;text-transform:uppercase;letter-spacing:0.1em;font-weight:700;">Score</div>
              </div>

              <!-- Stats Grid -->
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:2.5rem;">
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:1rem;padding:1.25rem;">
                  <div style="font-size:1.625rem;font-weight:800;color:#0f172a;">${sub.correct_answers}</div>
                  <div style="font-size:0.8125rem;color:#64748b;font-weight:600;">Correct Answers</div>
                </div>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:1rem;padding:1.25rem;">
                  <div style="font-size:1.625rem;font-weight:800;color:#0f172a;">${sub.total_questions}</div>
                  <div style="font-size:0.8125rem;color:#64748b;font-weight:600;">Total Questions</div>
                </div>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:1rem;padding:1.25rem;">
                  <div style="font-size:1.625rem;font-weight:800;color:${isPassed ? '#16a34a' : '#dc2626'};">${isPassed ? 'PASS' : 'FAIL'}</div>
                  <div style="font-size:0.8125rem;color:#64748b;font-weight:600;">Result</div>
                </div>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:1rem;padding:1.25rem;">
                  <div style="font-size:1.625rem;font-weight:800;color:#0f172a;">${sub.passing_score || 40}%</div>
                  <div style="font-size:0.8125rem;color:#64748b;font-weight:600;">Passing Score</div>
                </div>
              </div>

              ${answerReviewHTML}

              <!-- Back Button -->
              <button onclick="window.history.pushState(null,'','/student');window.dispatchEvent(new PopStateEvent('popstate'));"
                style="width:100%;padding:1rem;background:#1d4ed8;color:white;border:none;border-radius:0.75rem;font-size:1.125rem;font-weight:700;cursor:pointer;transition:all 0.2s;box-shadow:0 4px 12px rgba(29,78,216,0.2);margin-top:2rem;"
                onmouseover="this.style.background='#1e40af'"
                onmouseout="this.style.background='#1d4ed8'"
              >
                &larr; Back to Dashboard
              </button>
            </div>
          </div>
        </div>

        <!-- Challenge Modal -->
        <div id="challenge-overlay" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.5);align-items:center;justify-content:center">
          <div style="background:white;border-radius:16px;padding:2rem;max-width:440px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.2)">
            <h3 style="margin:0 0 0.5rem;font-size:1.1rem;font-weight:700">🚩 Challenge This Question</h3>
            <p id="challenge-q-text" style="font-size:0.8rem;color:#64748b;margin-bottom:1rem"></p>
            <label style="font-size:0.85rem;font-weight:600;color:#374151;display:block;margin-bottom:0.5rem">Reason for challenge *</label>
            <select id="challenge-reason" style="width:100%;padding:0.6rem 0.75rem;border:1px solid #d1d5db;border-radius:8px;font-size:0.9rem;margin-bottom:0.75rem">
              <option value="Wrong answer key">Wrong answer key listed</option>
              <option value="Question is ambiguous">Question is ambiguous</option>
              <option value="Question has a typo">Question has a typo</option>
              <option value="Options are incorrect">Options are incorrect</option>
              <option value="Other">Other</option>
            </select>
            <textarea id="challenge-detail" placeholder="Additional details (optional)" rows="3"
              style="width:100%;padding:0.6rem 0.75rem;border:1px solid #d1d5db;border-radius:8px;font-size:0.85rem;resize:vertical;margin-bottom:1rem;box-sizing:border-box"></textarea>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end">
              <button onclick="document.getElementById('challenge-overlay').style.display='none'"
                style="padding:0.6rem 1.25rem;border:1px solid #d1d5db;border-radius:8px;background:white;cursor:pointer;font-weight:600">Cancel</button>
              <button id="challenge-submit-btn" onclick="window._submitChallenge()"
                style="padding:0.6rem 1.25rem;border:none;border-radius:8px;background:#f59e0b;color:white;cursor:pointer;font-weight:700">Submit Challenge</button>
            </div>
          </div>
        </div>
      `;

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
          // Show success banner
          const banner = document.createElement('div');
          banner.textContent = '✅ Challenge submitted! Admin will review it.';
          banner.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;background:#1d4ed8;color:#fff;padding:0.875rem 1.25rem;border-radius:12px;font-weight:600;z-index:9999;box-shadow:0 4px 20px rgba(29,78,216,0.3)';
          document.body.appendChild(banner);
          setTimeout(() => banner.remove(), 4500);
        } catch (e) {
          alert('Failed to submit: ' + e.message);
          btn.disabled = false; btn.textContent = 'Submit Challenge';
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
