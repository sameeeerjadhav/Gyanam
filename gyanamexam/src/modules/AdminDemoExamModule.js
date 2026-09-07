/**
 * AdminDemoExamModule.js
 * Admin-only walkthrough of the student exam experience (offline mock data).
 * Flow: Landing → Pre-exam steps → Take exam → Result
 */
import modalService from '../services/ModalService.js';
import { mockQuestions, mockExamConfig } from '../data/mockQuestions.js';
import { QuestionView } from '../components/QuestionView.js';
import { QuestionPalette } from '../components/QuestionPalette.js';
import { Timer } from '../components/Timer.js';

const DEMO_DURATION_MIN = 5; // short demo timer
const PASSING = 40;

function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function formatMmSs(totalSeconds) {
  const s = Math.max(0, Math.floor(totalSeconds));
  const m = Math.floor(s / 60);
  const r = s % 60;
  return `${String(m).padStart(2, '0')}:${String(r).padStart(2, '0')}`;
}

export async function renderAdminDemoExam(ApiClient, { currentUser, loadPage }) {
  const el = document.getElementById('page-content');
  if (!el) return;

  if (currentUser?.role !== 'admin') {
    el.innerHTML = `
      <div class="dash-empty" style="padding:3rem">
        <div class="dash-empty-title">Admin only</div>
        <div class="dash-empty-text">The Demo Exam walkthrough is available to Administrators.</div>
      </div>`;
    return;
  }

  const state = {
    step: 'landing', // landing | gate | exam | result
    gateStep: 0,
    termsOk: false,
    identityOk: false,
    answers: {},
    marked: new Set(),
    currentIndex: 0,
    timer: null,
    questionView: null,
    palette: null,
    score: null,
  };

  const questions = mockQuestions.map((q, i) => ({
    ...q,
    // Ensure type string ExamPage-compatible
    type: q.type || 'multiple-choice-single',
  }));

  const examMeta = {
    id: mockExamConfig.examId,
    title: mockExamConfig.title || 'Admin Demo Examination',
    duration: DEMO_DURATION_MIN,
    total_questions: questions.length,
    passing_score: PASSING,
    exam_type: 'demo',
    subject: 'IT Demo / Sample Questions',
    instructions:
      'This is a practice walkthrough for administrators. No scores are saved to the database. '
      + 'You will see the same pre-exam steps and exam screen that students use.',
    proctored: false,
  };

  function cleanupExam() {
    if (state.timer) {
      try { state.timer.stop(); } catch (_) {}
      state.timer = null;
    }
    state.questionView = null;
    state.palette = null;
  }

  function renderLanding() {
    cleanupExam();
    state.step = 'landing';
    el.innerHTML = `
      <div class="page-header dash-page-header">
        <div>
          <h2>Demo Exam</h2>
          <p class="dash-meta">Preview the student exam procedure without affecting live data</p>
        </div>
      </div>
      <div class="card" style="max-width:720px;padding:1.5rem 1.6rem">
        <div style="display:flex;gap:1rem;align-items:flex-start;margin-bottom:1.25rem">
          <div style="width:48px;height:48px;border-radius:14px;background:linear-gradient(135deg,#2563eb,#7c3aed);display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" style="width:24px;height:24px"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 010 1.972l-11.54 6.347a1.125 1.125 0 01-1.667-.986V5.653z"/></svg>
          </div>
          <div>
            <h3 style="margin:0 0 .35rem;font-size:1.1rem;font-weight:800">Student exam walkthrough</h3>
            <p style="margin:0;color:var(--text-muted);font-size:.9rem;line-height:1.5">
              Experience what students see: identity confirmation, rules, terms, the live exam screen
              (timer, question palette, navigation), then a sample result. Nothing is submitted to the server.
            </p>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem;margin-bottom:1.35rem">
          <div style="background:#f8fafc;border:1px solid var(--gray-100,#e2e8f0);border-radius:12px;padding:.85rem">
            <div style="font-size:.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase">Questions</div>
            <div style="font-size:1.25rem;font-weight:800;margin-top:.2rem">${questions.length}</div>
          </div>
          <div style="background:#f8fafc;border:1px solid var(--gray-100,#e2e8f0);border-radius:12px;padding:.85rem">
            <div style="font-size:.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase">Demo timer</div>
            <div style="font-size:1.25rem;font-weight:800;margin-top:.2rem">${DEMO_DURATION_MIN} min</div>
          </div>
          <div style="background:#f8fafc;border:1px solid var(--gray-100,#e2e8f0);border-radius:12px;padding:.85rem">
            <div style="font-size:.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase">Pass mark</div>
            <div style="font-size:1.25rem;font-weight:800;margin-top:.2rem">${PASSING}%</div>
          </div>
        </div>
        <ol style="margin:0 0 1.35rem;padding-left:1.2rem;color:var(--text-muted);font-size:.88rem;line-height:1.65">
          <li>Pre-exam checklist (identity → rules → terms → start)</li>
          <li>Take the sample exam (same UI as students)</li>
          <li>View a local demo result screen</li>
        </ol>
        <button type="button" class="btn btn-primary" id="demo-start-btn">Start Demo Walkthrough</button>
      </div>`;
    document.getElementById('demo-start-btn')?.addEventListener('click', () => {
      state.gateStep = 0;
      state.termsOk = false;
      state.identityOk = false;
      renderGate();
    });
  }

  function gateSteps() {
    return [
      { key: 'identity', title: 'Confirm identity', blurb: 'Students confirm their details before the exam.' },
      { key: 'rules', title: 'Exam rules', blurb: 'Integrity rules shown to every candidate.' },
      { key: 'terms', title: 'Terms & instructions', blurb: 'Terms plus centre/exam instructions.' },
      { key: 'ready', title: 'Ready to start', blurb: 'Final confirmation before the timer begins.' },
    ];
  }

  function renderGate() {
    cleanupExam();
    state.step = 'gate';
    const steps = gateSteps();
    const cur = steps[state.gateStep] || steps[0];
    const pills = steps.map((s, i) => {
      const cls = i === state.gateStep ? 'peg-pill peg-pill-green' : (i < state.gateStep ? 'peg-pill' : 'peg-pill');
      return `<span class="${cls}" style="${i === state.gateStep ? '' : (i < state.gateStep ? 'opacity:.85' : 'opacity:.45')}">${i + 1}. ${esc(s.title)}</span>`;
    }).join('');

    let body = '';
    if (cur.key === 'identity') {
      body = `
        <div class="peg-card">
          <h3 class="peg-h">Is this you?</h3>
          <p class="peg-p">Students see their registered details here. For this demo we show your admin login.</p>
          <div class="peg-id-grid">
            <div><span class="peg-k">Name</span><strong>${esc(currentUser.username || 'Administrator')}</strong></div>
            <div><span class="peg-k">Role</span><strong>Admin (Demo)</strong></div>
            <div><span class="peg-k">Centre</span><strong>Head Office — Demo</strong></div>
            <div><span class="peg-k">Exam</span><strong>${esc(examMeta.title)}</strong></div>
          </div>
          <label class="peg-check">
            <input type="checkbox" id="demo-id-ok" ${state.identityOk ? 'checked' : ''}>
            <span>I confirm these details are correct</span>
          </label>
        </div>`;
    } else if (cur.key === 'rules') {
      body = `
        <div class="peg-card">
          <h3 class="peg-h">Examination rules</h3>
          <ul class="peg-list">
            <li>Do not switch browser tabs or open other applications during a live exam.</li>
            <li>Do not use notes, phones, or help from others unless the centre allows it.</li>
            <li>The timer starts when you click <strong>Start Exam</strong>.</li>
            <li>Answers are saved as you go; submit before time runs out.</li>
            <li>Proctored exams may require camera / fullscreen (disabled in this demo).</li>
          </ul>
        </div>`;
    } else if (cur.key === 'terms') {
      body = `
        <div class="peg-card">
          <h3 class="peg-h">Terms &amp; instructions</h3>
          <div class="peg-instructions">${esc(examMeta.instructions)}</div>
          <label class="peg-check" style="margin-top:1rem">
            <input type="checkbox" id="demo-terms-ok" ${state.termsOk ? 'checked' : ''}>
            <span>I have read and accept the examination terms</span>
          </label>
        </div>`;
    } else {
      body = `
        <div class="peg-card">
          <h3 class="peg-h">You are ready</h3>
          <p class="peg-p">Click <strong>Start Exam</strong> to open the student exam screen. The ${DEMO_DURATION_MIN}-minute demo timer will begin.</p>
          <div class="peg-exam-meta" style="margin-top:1rem">
            <span class="peg-pill">${questions.length} questions</span>
            <span class="peg-pill">${DEMO_DURATION_MIN} minutes</span>
            <span class="peg-pill peg-pill-green">Practice / Demo</span>
          </div>
        </div>`;
    }

    el.innerHTML = `
      <div class="peg-page" style="min-height:calc(100vh - 120px)">
        <div class="peg-shell">
          <div class="peg-top">
            <div class="peg-brand">
              <img src="assets/logo.png" alt="Gyanam">
              <div>
                <div class="peg-brand-name">Gyanam Exam</div>
                <div class="peg-brand-sub">Admin Demo Walkthrough</div>
              </div>
            </div>
            <button type="button" class="peg-cancel" id="demo-gate-cancel">Exit demo</button>
          </div>
          <div class="peg-exam-bar">
            <div class="peg-exam-title">${esc(examMeta.title)}</div>
            <div class="peg-exam-meta">${pills}</div>
          </div>
          ${body}
          <div class="peg-actions">
            <button type="button" class="btn btn-outline" id="demo-gate-back" ${state.gateStep === 0 ? 'disabled' : ''}>Back</button>
            <button type="button" class="btn btn-primary" id="demo-gate-next">
              ${cur.key === 'ready' ? 'Start Exam' : 'Continue'}
            </button>
          </div>
        </div>
      </div>`;

    document.getElementById('demo-id-ok')?.addEventListener('change', (e) => {
      state.identityOk = !!e.target.checked;
    });
    document.getElementById('demo-terms-ok')?.addEventListener('change', (e) => {
      state.termsOk = !!e.target.checked;
    });
    document.getElementById('demo-gate-cancel')?.addEventListener('click', () => renderLanding());
    document.getElementById('demo-gate-back')?.addEventListener('click', () => {
      if (state.gateStep > 0) {
        state.gateStep -= 1;
        renderGate();
      }
    });
    document.getElementById('demo-gate-next')?.addEventListener('click', () => {
      const key = gateSteps()[state.gateStep]?.key;
      if (key === 'identity' && !state.identityOk) {
        modalService.toast('Please confirm your identity details', 'error');
        return;
      }
      if (key === 'terms' && !state.termsOk) {
        modalService.toast('Please accept the terms to continue', 'error');
        return;
      }
      if (key === 'ready') {
        startExam();
        return;
      }
      state.gateStep += 1;
      renderGate();
    });
  }

  function startExam() {
    cleanupExam();
    state.step = 'exam';
    state.answers = {};
    state.marked = new Set();
    state.currentIndex = 0;
    state.timer = new Timer();
    state.questionView = new QuestionView();
    state.palette = new QuestionPalette();

    el.innerHTML = `
      <div class="exam-shell demo-exam-shell" style="margin:-1rem;min-height:calc(100vh - 64px);background:var(--gray-50,#f8fafc)">
        <header class="exam-header">
          <div class="exam-header-inner">
            <div class="exam-header-left">
              <h2>${esc(examMeta.title)}</h2>
              <p>Practice Exam · ${esc(examMeta.subject)} · Demo (not saved)</p>
            </div>
            <div class="exam-header-right" style="display:flex;align-items:center;gap:1rem">
              <div class="exam-timer" id="demo-timer">${formatMmSs(DEMO_DURATION_MIN * 60)}</div>
              <button type="button" class="btn btn-outline btn-sm" id="demo-exit-exam">Exit</button>
            </div>
          </div>
          <div class="exam-progress"><div class="exam-progress-bar" id="demo-progress" style="width:0%"></div></div>
        </header>
        <div class="exam-body" style="display:grid;grid-template-columns:1fr 260px;gap:1rem;padding:1rem;max-width:1200px;margin:0 auto">
          <div class="exam-main card" style="padding:1.25rem">
            <div id="demo-question-host"></div>
            <div class="exam-nav" style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--gray-100,#e2e8f0)">
              <button type="button" class="btn btn-outline btn-sm" id="demo-prev">← Previous</button>
              <button type="button" class="btn btn-outline btn-sm" id="demo-mark">Mark for review</button>
              <button type="button" class="btn btn-primary btn-sm" id="demo-next">Next →</button>
              <button type="button" class="btn btn-primary btn-sm" id="demo-submit" style="margin-left:auto;background:#dc2626;border-color:#dc2626">Submit Exam</button>
            </div>
          </div>
          <aside class="exam-side card" style="padding:1rem;height:fit-content">
            <div style="font-weight:800;font-size:.85rem;margin-bottom:.65rem">Question palette</div>
            <div id="demo-palette-host"></div>
            <p style="font-size:.72rem;color:var(--text-muted);margin-top:.75rem;line-height:1.4">
              Green = answered · Orange = marked · Blue = current
            </p>
          </aside>
        </div>
      </div>`;

    const qHost = document.getElementById('demo-question-host');
    const pHost = document.getElementById('demo-palette-host');

    state.palette.initialize(questions.length, (idx) => {
      state.currentIndex = idx;
      paintQuestion();
    });
    state.palette.render(pHost);

    const paintQuestion = () => {
      const q = questions[state.currentIndex];
      const ans = state.answers[q.id] ?? null;
      state.questionView.render(qHost, q, state.currentIndex + 1, ans, (val) => {
        state.answers[q.id] = val;
        refreshPalette();
        updateProgress();
      });
      refreshPalette();
      updateProgress();
      document.getElementById('demo-mark').textContent = state.marked.has(state.currentIndex)
        ? 'Unmark review'
        : 'Mark for review';
    };

    const refreshPalette = () => {
      for (let i = 0; i < questions.length; i++) {
        const q = questions[i];
        let status = 'unattempted';
        if (i === state.currentIndex) status = 'current';
        else if (state.marked.has(i)) status = 'marked';
        else if (state.answers[q.id] != null && state.answers[q.id] !== '') status = 'attempted';
        try {
          state.palette.updateQuestionStatus(i, status);
        } catch (_) {}
      }
      state.palette.render(pHost);
    };

    const updateProgress = () => {
      const answered = questions.filter(q => state.answers[q.id] != null && state.answers[q.id] !== '').length;
      const pct = Math.round((answered / questions.length) * 100);
      const bar = document.getElementById('demo-progress');
      if (bar) bar.style.width = `${pct}%`;
    };

    document.getElementById('demo-prev')?.addEventListener('click', () => {
      if (state.currentIndex > 0) {
        state.currentIndex -= 1;
        paintQuestion();
      }
    });
    document.getElementById('demo-next')?.addEventListener('click', () => {
      if (state.currentIndex < questions.length - 1) {
        state.currentIndex += 1;
        paintQuestion();
      }
    });
    document.getElementById('demo-mark')?.addEventListener('click', () => {
      if (state.marked.has(state.currentIndex)) state.marked.delete(state.currentIndex);
      else state.marked.add(state.currentIndex);
      paintQuestion();
    });
    document.getElementById('demo-submit')?.addEventListener('click', async () => {
      const ok = await modalService.confirm(
        'Submit this demo exam now? (Nothing is saved to the server.)',
        { title: 'Submit demo', confirmText: 'Submit', type: 'warning' }
      );
      if (ok) finishExam(false);
    });
    document.getElementById('demo-exit-exam')?.addEventListener('click', async () => {
      const ok = await modalService.confirm('Exit the demo exam without submitting?', {
        title: 'Exit demo', confirmText: 'Exit', type: 'warning',
      });
      if (ok) {
        cleanupExam();
        renderLanding();
      }
    });

    paintQuestion();

    const timerEl = document.getElementById('demo-timer');
    try {
      state.timer.start(DEMO_DURATION_MIN, () => finishExam(true), (remaining) => {
        if (timerEl) {
          timerEl.textContent = formatMmSs(remaining);
          timerEl.classList.toggle('is-warn', remaining <= 60);
        }
      });
    } catch (e) {
      modalService.toast('Timer failed: ' + e.message, 'error');
    }
  }

  function finishExam(auto) {
    cleanupExam();
    let correct = 0;
    questions.forEach((q) => {
      const ans = state.answers[q.id];
      if (ans != null && String(ans) === String(q.correctAnswer)) correct += 1;
    });
    const total = questions.length;
    const pct = total ? Math.round((correct / total) * 100) : 0;
    const passed = pct >= PASSING;
    state.score = { correct, total, pct, passed, auto: !!auto };
    state.step = 'result';
    renderResult();
  }

  function renderResult() {
    const s = state.score || { correct: 0, total: questions.length, pct: 0, passed: false, auto: false };
    el.innerHTML = `
      <div class="page-header">
        <div>
          <h2>Demo result</h2>
          <p>Local preview only — not stored in Results</p>
        </div>
      </div>
      <div class="card" style="max-width:560px;padding:1.5rem;text-align:center">
        <div style="font-size:.85rem;font-weight:700;color:var(--text-muted);margin-bottom:.5rem">
          ${s.auto ? 'Time expired — auto submitted' : 'Submitted'}
        </div>
        <div style="font-size:2.5rem;font-weight:800;color:${s.passed ? '#047857' : '#b91c1c'}">${s.pct}%</div>
        <div style="margin-top:.35rem;font-weight:700;font-size:1.05rem;color:${s.passed ? '#047857' : '#b91c1c'}">
          ${s.passed ? 'PASS' : 'FAIL'}
        </div>
        <p style="margin:1rem 0 0;color:var(--text-muted);font-size:.9rem">
          ${s.correct} / ${s.total} correct · Pass mark ${PASSING}%
        </p>
        <div style="display:flex;flex-wrap:wrap;gap:.65rem;justify-content:center;margin-top:1.35rem">
          <button type="button" class="btn btn-primary" id="demo-again">Try demo again</button>
          <button type="button" class="btn btn-outline" id="demo-home">Back to Demo Exam</button>
        </div>
      </div>`;
    document.getElementById('demo-again')?.addEventListener('click', () => {
      state.gateStep = 0;
      state.termsOk = false;
      state.identityOk = false;
      renderGate();
    });
    document.getElementById('demo-home')?.addEventListener('click', () => renderLanding());
  }

  // Extra styles for gate checkboxes inside admin layout
  if (!document.getElementById('demo-exam-styles')) {
    const st = document.createElement('style');
    st.id = 'demo-exam-styles';
    st.textContent = `
      .peg-h{margin:0 0 .5rem;font-size:1.1rem;font-weight:800}
      .peg-p{margin:0;color:var(--text-muted);font-size:.9rem;line-height:1.5}
      .peg-check{display:flex;align-items:flex-start;gap:.55rem;margin-top:1rem;font-size:.9rem;font-weight:600;cursor:pointer}
      .peg-check input{margin-top:.2rem}
      .peg-id-grid{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin:1rem 0}
      .peg-id-grid .peg-k{display:block;font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;color:var(--text-muted);font-weight:700;margin-bottom:.15rem}
      .peg-list{margin:.5rem 0 0;padding-left:1.15rem;line-height:1.65;color:var(--text-muted);font-size:.9rem}
      .peg-instructions{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:.9rem 1rem;font-size:.9rem;line-height:1.5;color:var(--text-muted)}
      .peg-actions{display:flex;justify-content:space-between;gap:.75rem;margin-top:1.25rem}
      .exam-timer.is-warn{color:#dc2626;font-weight:800}
      @media(max-width:900px){
        .exam-body{grid-template-columns:1fr !important}
        .peg-id-grid{grid-template-columns:1fr}
      }
    `;
    document.head.appendChild(st);
  }

  renderLanding();

  return {
    stop() {
      cleanupExam();
    },
  };
}
