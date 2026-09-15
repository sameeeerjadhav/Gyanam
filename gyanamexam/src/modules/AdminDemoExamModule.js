/**
 * AdminDemoExamModule.js
 * Admin configures the Global Practice Exam (one QB → all students, any course).
 * Optional: short offline mock walkthrough for admins.
 */
import modalService from '../services/ModalService.js';
import { mockQuestions, mockExamConfig } from '../data/mockQuestions.js';
import { QuestionView } from '../components/QuestionView.js';
import { QuestionPalette } from '../components/QuestionPalette.js';
import { Timer } from '../components/Timer.js';

const DEMO_DURATION_MIN = 5;
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
        <div class="dash-empty-text">Global Practice Exam settings are available to Administrators.</div>
      </div>`;
    return;
  }

  el.innerHTML = `
    <div class="page-header"><div><h2>Practice Exam</h2><p>Loading…</p></div></div>`;

  let banks = [];
  let current = null;
  try {
    const [banksRes, gp] = await Promise.all([
      ApiClient.getQuestionBanks(),
      ApiClient.getGlobalPracticeExam(),
    ]);
    banks = Array.isArray(banksRes) ? banksRes : [];
    current = gp?.exam || null;
  } catch (e) {
    el.innerHTML = `<div class="dash-empty" style="padding:3rem"><div class="dash-empty-title">Failed to load</div><div class="dash-empty-text">${esc(e.message)}</div></div>`;
    return;
  }

  const bankOpts = banks.length
    ? banks.map((b) => {
      const sel = current && String(current.question_bank_id) === String(b.id) ? 'selected' : '';
      return `<option value="${b.id}" ${sel}>${esc(b.title)} — ${esc(b.subject)} (${b.questions_count ?? 0} Qs)</option>`;
    }).join('')
    : '<option value="">No question banks yet — create one first</option>';

  const statusChip = current?.active
    ? '<span class="badge badge-green">Live for all students</span>'
    : current
      ? '<span class="badge badge-gray">Configured · Inactive</span>'
      : '<span class="badge badge-gray">Not configured</span>';

  el.innerHTML = `
  <div class="page-header">
    <div>
      <h2>Practice Exam (All Students)</h2>
      <p>Pick one question bank. Every student sees this on their dashboard — any course, no assignment needed.</p>
    </div>
    ${statusChip}
  </div>

  <div class="card" style="padding:1.35rem 1.5rem;margin-bottom:1.25rem;border:1px solid #bfdbfe;background:linear-gradient(180deg,#eff6ff,#fff)">
    <div style="display:flex;gap:0.75rem;align-items:flex-start;margin-bottom:1rem">
      <div style="width:40px;height:40px;border-radius:10px;background:#dbeafe;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.2rem">🎯</div>
      <div>
        <div style="font-weight:700;color:#1e3a8a;font-size:0.95rem">Student “Experience the Exam” section</div>
        <div style="font-size:0.82rem;color:#475569;margin-top:0.25rem;line-height:1.45">
          When active, students get a dedicated practice card on their portal. Same flow as a real exam
          (pre-exam gate → question paper → timer → submit → result). Official course exams stay separate.
        </div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1.4fr 1fr 1fr;gap:0.85rem">
      <div class="form-group" style="margin:0">
        <label class="form-label">Title *</label>
        <input id="gp-title" class="form-input" value="${esc(current?.title || 'Practice Exam — Experience How It Works')}" maxlength="255">
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">Duration (minutes) *</label>
        <input id="gp-dur" class="form-input" type="number" min="1" max="300" value="${current?.duration ?? 15}">
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">Questions to show *</label>
        <input id="gp-qs" class="form-input" type="number" min="1" max="200" value="${current?.total_questions ?? 10}">
      </div>
      <div class="form-group" style="margin:0;grid-column:1 / -1">
        <label class="form-label">Question Bank *</label>
        <select id="gp-bank" class="form-select">${bankOpts}</select>
        <p style="margin:0.35rem 0 0;font-size:0.75rem;color:var(--text-muted)">Only this bank is used — never mixed with other banks.</p>
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">Passing score (%)</label>
        <input id="gp-pass" class="form-input" type="number" min="1" max="100" value="${current?.passing_score ?? 40}">
      </div>
      <div class="form-group" style="margin:0;display:flex;align-items:flex-end;gap:1rem;padding-bottom:0.35rem">
        <label style="display:flex;align-items:center;gap:0.45rem;font-size:0.85rem;font-weight:600;cursor:pointer">
          <input type="checkbox" id="gp-active" ${!current || current.active ? 'checked' : ''}> Visible to all students
        </label>
        <label style="display:flex;align-items:center;gap:0.45rem;font-size:0.85rem;font-weight:600;cursor:pointer">
          <input type="checkbox" id="gp-random" ${current?.randomize_questions !== false ? 'checked' : ''}> Randomize questions
        </label>
      </div>
      <div class="form-group" style="margin:0;grid-column:1 / -1">
        <label class="form-label">Instructions (shown before start)</label>
        <textarea id="gp-inst" class="form-textarea" rows="3">${esc(current?.instructions || 'This practice exam helps you experience how the real exam works — timer, question map, navigation, and submit. Your practice score may be saved for your reference.')}</textarea>
      </div>
    </div>

    <div style="display:flex;gap:0.65rem;justify-content:flex-end;margin-top:1.1rem;flex-wrap:wrap">
      <button type="button" class="btn btn-outline" id="gp-open-student">Open student portal</button>
      <button type="button" class="btn btn-primary" id="gp-save">Save Practice Exam</button>
    </div>
  </div>

  <div class="card" style="padding:1.15rem 1.35rem">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
      <div>
        <div style="font-weight:700;font-size:0.95rem">Admin mock walkthrough (optional)</div>
        <div style="font-size:0.8rem;color:var(--text-muted);margin-top:0.2rem">Offline sample questions — does not use your selected bank or save scores.</div>
      </div>
      <button type="button" class="btn btn-outline btn-sm" id="demo-start-btn">Try mock walkthrough</button>
    </div>
    <div id="demo-walkthrough-host" style="display:none;margin-top:1rem"></div>
  </div>`;

  document.getElementById('gp-open-student')?.addEventListener('click', () => {
    window.open('index.html', '_blank');
  });

  document.getElementById('gp-save')?.addEventListener('click', async () => {
    const title = document.getElementById('gp-title')?.value?.trim();
    const bankId = document.getElementById('gp-bank')?.value;
    const duration = parseInt(document.getElementById('gp-dur')?.value, 10) || 15;
    const total_questions = parseInt(document.getElementById('gp-qs')?.value, 10) || 10;
    const passing_score = parseInt(document.getElementById('gp-pass')?.value, 10) || 40;
    if (!title) { modalService.toast('Title is required', 'error'); return; }
    if (!bankId) { modalService.toast('Please select a Question Bank', 'error'); return; }

    const btn = document.getElementById('gp-save');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
    try {
      await ApiClient.saveGlobalPracticeExam({
        title,
        question_bank_id: parseInt(bankId, 10),
        duration,
        total_questions,
        passing_score,
        instructions: document.getElementById('gp-inst')?.value?.trim() || null,
        active: !!document.getElementById('gp-active')?.checked,
        randomize_questions: !!document.getElementById('gp-random')?.checked,
        proctored: false,
      });
      modalService.toast('Practice exam saved — visible to all students when active.', 'success');
      renderAdminDemoExam(ApiClient, { currentUser, loadPage });
    } catch (e) {
      modalService.toast('Save failed: ' + e.message, 'error');
      if (btn) { btn.disabled = false; btn.textContent = 'Save Practice Exam'; }
    }
  });

  // ── Optional mock walkthrough (existing behaviour) ─────────────────────
  document.getElementById('demo-start-btn')?.addEventListener('click', () => {
    const host = document.getElementById('demo-walkthrough-host');
    if (!host) return;
    host.style.display = 'block';
    startMockWalkthrough(host);
  });

  function startMockWalkthrough(host) {
    const state = {
      step: 'landing',
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

    const questions = mockQuestions.map((q) => ({
      ...q,
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
      instructions: 'Offline mock walkthrough. Nothing is saved.',
      proctored: false,
    };

    function cleanupExam() {
      if (state.timer) {
        try { state.timer.stop(); } catch (_) {}
        state.timer = null;
      }
    }

    function renderLanding() {
      cleanupExam();
      host.innerHTML = `
        <div style="padding:1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px">
          <p style="margin:0 0 0.75rem;font-size:0.85rem;color:#475569">Short ${DEMO_DURATION_MIN}-minute mock with sample questions.</p>
          <button type="button" class="btn btn-primary btn-sm" id="mock-go">Start mock</button>
        </div>`;
      document.getElementById('mock-go')?.addEventListener('click', () => {
        state.gateStep = 0;
        state.termsOk = false;
        state.identityOk = false;
        renderGate();
      });
    }

    function renderGate() {
      const steps = ['Identity', 'Rules', 'Start'];
      host.innerHTML = `
        <div class="peg-card" style="max-width:560px;margin:0 auto">
          <div style="font-size:0.75rem;font-weight:700;color:#64748b;margin-bottom:0.75rem">${steps.map((s, i) =>
            `<span style="margin-right:0.5rem;${i === state.gateStep ? 'color:#1d4ed8' : ''}">${i + 1}. ${s}</span>`).join('')}</div>
          ${state.gateStep === 0 ? `
            <p class="peg-p">Confirm you are ready for this mock walkthrough.</p>
            <label style="display:flex;gap:0.5rem;align-items:center;font-size:0.85rem"><input type="checkbox" id="demo-id-ok" ${state.identityOk ? 'checked' : ''}> I understand this is a mock demo</label>
          ` : ''}
          ${state.gateStep === 1 ? `
            <ul style="font-size:0.85rem;color:#475569;line-height:1.5;padding-left:1.1rem">
              <li>Do not refresh mid-exam in a real paper.</li>
              <li>Use the question map to jump between questions.</li>
              <li>Submit before the timer ends.</li>
            </ul>
            <label style="display:flex;gap:0.5rem;align-items:center;font-size:0.85rem;margin-top:0.75rem"><input type="checkbox" id="demo-terms-ok" ${state.termsOk ? 'checked' : ''}> I have read the rules</label>
          ` : ''}
          ${state.gateStep === 2 ? `<p class="peg-p">Click Start Exam to begin the ${DEMO_DURATION_MIN}-minute mock timer.</p>` : ''}
          <div style="display:flex;gap:0.5rem;justify-content:flex-end;margin-top:1rem">
            <button type="button" class="btn btn-outline btn-sm" id="demo-gate-back" ${state.gateStep === 0 ? 'disabled' : ''}>Back</button>
            <button type="button" class="btn btn-primary btn-sm" id="demo-gate-next">${state.gateStep === 2 ? 'Start Exam' : 'Next'}</button>
          </div>
        </div>`;
      document.getElementById('demo-id-ok')?.addEventListener('change', (e) => { state.identityOk = e.target.checked; });
      document.getElementById('demo-terms-ok')?.addEventListener('change', (e) => { state.termsOk = e.target.checked; });
      document.getElementById('demo-gate-back')?.addEventListener('click', () => {
        state.gateStep = Math.max(0, state.gateStep - 1);
        renderGate();
      });
      document.getElementById('demo-gate-next')?.addEventListener('click', () => {
        if (state.gateStep === 0 && !state.identityOk) {
          modalService.toast('Please confirm to continue', 'error');
          return;
        }
        if (state.gateStep === 1 && !state.termsOk) {
          modalService.toast('Please accept the rules', 'error');
          return;
        }
        if (state.gateStep >= 2) {
          startExam();
          return;
        }
        state.gateStep += 1;
        renderGate();
      });
    }

    function startExam() {
      cleanupExam();
      state.answers = {};
      state.marked = new Set();
      state.currentIndex = 0;
      state.questionView = new QuestionView();
      state.palette = new QuestionPalette();
      state.timer = new Timer();

      host.innerHTML = `
        <div class="exam-shell demo-exam-shell" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
          <header class="exam-header" style="padding:0.75rem 1rem;background:#fff;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center">
            <div>
              <div style="font-weight:700">${esc(examMeta.title)}</div>
              <div style="font-size:0.75rem;color:#64748b">Mock · ${questions.length} questions</div>
            </div>
            <div style="display:flex;gap:0.75rem;align-items:center">
              <div class="exam-timer" id="demo-timer" style="font-weight:800;color:#16a34a">${formatMmSs(DEMO_DURATION_MIN * 60)}</div>
              <button type="button" class="btn btn-outline btn-sm" id="demo-exit-exam">Exit</button>
            </div>
          </header>
          <div style="display:flex;min-height:420px">
            <div style="flex:1;padding:1rem">
              <div id="demo-question-host"></div>
              <div style="display:flex;gap:0.5rem;margin-top:1rem;flex-wrap:wrap">
                <button type="button" class="btn btn-outline btn-sm" id="demo-prev">← Previous</button>
                <button type="button" class="btn btn-outline btn-sm" id="demo-mark">Mark for review</button>
                <button type="button" class="btn btn-primary btn-sm" id="demo-next">Next →</button>
                <button type="button" class="btn btn-sm" id="demo-submit" style="margin-left:auto;background:#dc2626;color:#fff;border:none">Submit</button>
              </div>
            </div>
            <div style="width:220px;border-left:1px solid #e2e8f0;padding:0.85rem;background:#fff">
              <div style="font-weight:700;font-size:0.8rem;margin-bottom:0.5rem">Question map</div>
              <div id="demo-palette-host"></div>
            </div>
          </div>
        </div>`;

      const qHost = document.getElementById('demo-question-host');
      const pHost = document.getElementById('demo-palette-host');

      const paint = () => {
        const q = questions[state.currentIndex];
        state.questionView.render(qHost, q, state.currentIndex + 1, state.answers[q.id] ?? null, (optId) => {
          state.answers[q.id] = optId;
          paintPalette();
        });
        document.getElementById('demo-mark').textContent = state.marked.has(state.currentIndex)
          ? 'Unmark review' : 'Mark for review';
        paintPalette();
      };

      const paintPalette = () => {
        state.palette.initialize(questions.length, (idx) => {
          state.currentIndex = idx;
          paint();
        });
        questions.forEach((q, i) => {
          let status = 'unattempted';
          if (state.marked.has(i)) status = 'marked';
          else if (state.answers[q.id] != null) status = 'attempted';
          if (i === state.currentIndex) status = 'current';
          state.palette.updateQuestionStatus(i, status);
        });
        state.palette.render(pHost);
      };

      paint();

      document.getElementById('demo-prev')?.addEventListener('click', () => {
        if (state.currentIndex > 0) { state.currentIndex -= 1; paint(); }
      });
      document.getElementById('demo-next')?.addEventListener('click', () => {
        if (state.currentIndex < questions.length - 1) { state.currentIndex += 1; paint(); }
      });
      document.getElementById('demo-mark')?.addEventListener('click', () => {
        if (state.marked.has(state.currentIndex)) state.marked.delete(state.currentIndex);
        else state.marked.add(state.currentIndex);
        paint();
      });
      document.getElementById('demo-submit')?.addEventListener('click', async () => {
        const ok = await modalService.confirm('Submit this mock exam?', {
          title: 'Submit mock', confirmText: 'Submit', type: 'warning',
        });
        if (ok) finishExam();
      });
      document.getElementById('demo-exit-exam')?.addEventListener('click', async () => {
        const ok = await modalService.confirm('Exit mock without submitting?', {
          title: 'Exit', confirmText: 'Exit', type: 'warning',
        });
        if (ok) renderLanding();
      });

      state.timer.start(DEMO_DURATION_MIN, () => finishExam(), (secs) => {
        const timerEl = document.getElementById('demo-timer');
        if (timerEl) timerEl.textContent = formatMmSs(secs);
      });
    }

    function finishExam() {
      cleanupExam();
      let correct = 0;
      questions.forEach((q) => {
        if (state.answers[q.id] != null && String(state.answers[q.id]) === String(q.correctAnswer || q.correct_answer)) {
          correct += 1;
        }
      });
      const score = questions.length ? Math.round((correct / questions.length) * 100) : 0;
      state.score = score;
      host.innerHTML = `
        <div style="padding:1.25rem;text-align:center;background:#fff;border:1px solid #e2e8f0;border-radius:12px">
          <div style="font-size:1.1rem;font-weight:800;margin-bottom:0.35rem">${score >= PASSING ? 'Pass (mock)' : 'Fail (mock)'}</div>
          <div style="font-size:2rem;font-weight:800;color:#1d4ed8">${score}%</div>
          <div style="font-size:0.85rem;color:#64748b;margin:0.5rem 0 1rem">${correct} / ${questions.length} correct · not saved</div>
          <button type="button" class="btn btn-outline btn-sm" id="mock-again">Try again</button>
        </div>`;
      document.getElementById('mock-again')?.addEventListener('click', () => renderLanding());
    }

    renderLanding();
  }
}
