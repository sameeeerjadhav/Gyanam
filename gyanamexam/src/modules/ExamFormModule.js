/**
 * ExamFormModule.js — Full-page create/edit Exam Configuration.
 */
import modalService from '../services/ModalService.js';

const EXAM_FORM_KEY = 'gyanam_exam_form_id';

export function setExamFormId(examId) {
  if (examId) sessionStorage.setItem(EXAM_FORM_KEY, String(examId));
  else sessionStorage.removeItem(EXAM_FORM_KEY);
}

export function getExamFormId() {
  return sessionStorage.getItem(EXAM_FORM_KEY) || '';
}

function proctorToggle(id, label, icon, desc, checked) {
  return `
    <label for="${id}" class="exam-proct-opt">
      <input type="checkbox" id="${id}" ${checked ? 'checked' : ''}>
      <span>
        <span class="exam-proct-label">${icon} ${label}</span>
        <span class="exam-proct-desc">${desc}</span>
      </span>
    </label>`;
}

export async function renderExamForm(ApiClient, { loadPage }) {
  const el = document.getElementById('page-content');
  const examId = getExamFormId();

  el.innerHTML = `
    <div class="exam-form-page">
      <div class="page-header"><div><h2>Exam Configuration</h2><p>Loading…</p></div></div>
    </div>`;

  let exam = null;
  let banks = [];
  let courses = [];
  try {
    const tasks = [ApiClient.getQuestionBanks(), ApiClient.getPortalCourses()];
    if (examId) tasks.unshift(ApiClient.getExams());
    const results = await Promise.all(tasks);
    if (examId) {
      const exams = results[0] || [];
      banks = results[1] || [];
      courses = results[2]?.courses || [];
      exam = exams.find(e => String(e.id) === String(examId)) || null;
      if (!exam) {
        el.innerHTML = `
          <div class="dash-empty" style="padding:3rem">
            <div class="dash-empty-title">Exam not found</div>
            <button type="button" class="btn btn-primary btn-sm" style="margin-top:1rem" id="exam-form-missing-back">Back to Exams</button>
          </div>`;
        document.getElementById('exam-form-missing-back')?.addEventListener('click', () => {
          setExamFormId(null);
          loadPage('exams');
        });
        return;
      }
    } else {
      banks = results[0] || [];
      courses = results[1]?.courses || [];
    }
  } catch (e) {
    el.innerHTML = `<div class="dash-empty" style="padding:3rem"><div class="dash-empty-title">Failed to load</div><div class="dash-empty-text">${e.message}</div></div>`;
    return;
  }

  const isEdit = !!exam;
  const isProctored = !!exam?.proctored;
  const ps = exam?.proctoring_settings || {};

  const bankOpts = banks.length > 0
    ? banks.map(b => {
        const sel = String(exam?.question_bank_id || '') === String(b.id) ? 'selected' : '';
        return `<option value="${b.id}" ${sel}>${b.title} — ${b.subject} (${b.questions_count} Qs)</option>`;
      }).join('')
    : '<option value="">No question banks created yet</option>';

  // Prefer Active courses first; still list Inactive for completeness
  if (Array.isArray(courses) && courses.length > 1) {
    courses.sort((a, b) => {
      const aInactive = String(a.status || 'Active').toLowerCase() === 'inactive' ? 1 : 0;
      const bInactive = String(b.status || 'Active').toLowerCase() === 'inactive' ? 1 : 0;
      if (aInactive !== bInactive) return aInactive - bInactive;
      return String(a.course_name || '').localeCompare(String(b.course_name || ''));
    });
  }

  let subjectField;
  if (courses.length > 0) {
    let opts = '<option value="">Select a course…</option>';
    courses.forEach(c => {
      const val = c.course_name;
      const inactive = String(c.status || 'Active').toLowerCase() === 'inactive';
      const label = c.course_type
        ? `${c.course_name} (${c.course_type})${inactive ? ' — Inactive' : ''}`
        : `${c.course_name}${inactive ? ' — Inactive' : ''}`;
      const sel = exam?.subject === val ? 'selected' : '';
      opts += `<option value="${val}" ${sel}>${label}</option>`;
    });
    subjectField = `<select id="ex-subj" class="form-select">${opts}</select>
      <p class="field-hint">${courses.length} course(s) synced from main portal.</p>`;
  } else {
    subjectField = `
      <input id="ex-subj" class="form-input" value="${exam?.subject || ''}" placeholder="e.g. Abacus Level 1, DCA…">
      <p class="field-hint">Sync courses from main portal (Admin › Courses → Sync to Exam Portal).</p>`;
  }

  el.innerHTML = `
  <div class="exam-form-page">
    <div class="page-header dash-page-header">
      <div>
        <button type="button" class="btn btn-ghost btn-sm assign-back" id="exam-form-back">← Exam Configurations</button>
        <h2 style="margin-top:0.35rem">${isEdit ? 'Edit Exam Configuration' : 'New Exam Configuration'}</h2>
        <p class="dash-meta">
          <span class="dash-chip">Choose Normal or Proctored mode</span>
        </p>
      </div>
      <div class="dash-page-actions">
        <button type="button" class="btn btn-outline btn-sm" id="exam-form-cancel">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="exam-form-save">${isEdit ? 'Save Changes' : 'Create Exam'}</button>
      </div>
    </div>

    <div class="card exam-form-card">
      <div class="exam-form-grid">
        <div class="form-group exam-span-2">
          <label class="form-label">Exam Title *</label>
          <input id="ex-title" class="form-input" value="${exam?.title || ''}" placeholder="e.g. Abacus Level 1 — Final Exam 2025">
        </div>

        <div class="form-group">
          <label class="form-label">Exam ID <span style="font-weight:400;color:var(--text-muted)">(auto if blank)</span></label>
          <input id="ex-id" class="form-input" value="${exam?.exam_id || ''}" placeholder="auto" ${isEdit ? 'readonly style="background:var(--gray-100)"' : ''}>
        </div>

        <div class="form-group">
          <label class="form-label">Type</label>
          <select id="ex-type" class="form-select">
            <option value="demo" ${exam?.exam_type === 'demo' ? 'selected' : ''}>Demo / Practice</option>
            <option value="main" ${!exam || exam?.exam_type === 'main' ? 'selected' : ''}>Main / Official</option>
          </select>
        </div>

        <div class="form-group exam-span-2">
          <label class="form-label">Course / Subject *</label>
          ${subjectField}
        </div>

        <div class="form-group">
          <label class="form-label">Duration (minutes)</label>
          <input id="ex-dur" class="form-input" type="number" value="${exam?.duration || 30}" min="1">
        </div>
        <div class="form-group">
          <label class="form-label">Questions to Show</label>
          <input id="ex-qs" class="form-input" type="number" value="${exam?.total_questions || 10}" min="1">
        </div>
        <div class="form-group">
          <label class="form-label">Passing Score (%)</label>
          <input id="ex-pass" class="form-input" type="number" value="${exam?.passing_score || 60}" min="1" max="100">
        </div>
        <div class="form-group">
          <label class="form-label">Question Bank *</label>
          <select id="ex-bank" class="form-select">${bankOpts}</select>
        </div>

        <div class="form-group exam-span-2">
          <label class="form-label">Instructions <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
          <textarea id="ex-inst" class="form-textarea" rows="3" placeholder="Shown to student before the exam starts…">${exam?.instructions || ''}</textarea>
        </div>

        <div class="form-group exam-span-2">
          <label class="form-label" style="margin-bottom:0.55rem">Exam Mode *</label>
          <div class="exam-mode-grid">
            <label id="mode-normal-card" class="exam-mode-card ${isProctored ? '' : 'is-active-normal'}">
              <input type="radio" name="ex-mode" id="ex-mode-normal" value="normal" ${isProctored ? '' : 'checked'}>
              <span>
                <span class="exam-mode-title" style="color:#166534">Normal</span>
                <span class="exam-mode-desc">Standard exam — no anti-cheat monitoring.</span>
              </span>
            </label>
            <label id="mode-proctored-card" class="exam-mode-card ${isProctored ? 'is-active-proctored' : ''}">
              <input type="radio" name="ex-mode" id="ex-mode-proctored" value="proctored" ${isProctored ? 'checked' : ''}>
              <span>
                <span class="exam-mode-title" style="color:#991b1b">Proctored</span>
                <span class="exam-mode-desc">Anti-cheat: tab switch, fullscreen, copy block.</span>
              </span>
            </label>
          </div>
          <input type="checkbox" id="ex-proctored" ${isProctored ? 'checked' : ''} style="display:none" aria-hidden="true" tabindex="-1">

          <div id="proctoring-panel" class="exam-proct-panel" style="display:${isProctored ? 'block' : 'none'}">
            <div class="exam-proct-head">Proctoring options</div>
            <div class="exam-proct-grid">
              ${proctorToggle('proct-camera', 'Camera Access', '📷', 'Request camera (optional)', !!ps.camera)}
              ${proctorToggle('proct-mic', 'Microphone', '🎤', 'Request mic (optional)', !!ps.microphone)}
              ${proctorToggle('proct-copypaste', 'Block Copy/Paste', '📋', 'Disable Ctrl+C / Ctrl+V', ps.copy_paste_block !== false)}
              ${proctorToggle('proct-rightclick', 'Block Right Click', '🖱️', 'Disable context menu', ps.right_click_block !== false)}
              ${proctorToggle('proct-fullscreen', 'Enforce Fullscreen', '🖥️', 'Warn on exit fullscreen', ps.fullscreen_enforce !== false)}
              ${proctorToggle('proct-devtools', 'DevTools Detection', '🔧', 'Detect open dev tools', ps.devtools_detect !== false)}
              ${proctorToggle('proct-textselect', 'Block Text Select', '✂️', 'Prevent text highlighting', ps.text_select_block !== false)}
            </div>
            <div class="exam-tab-limit">
              <label for="proct-tablimit">Tab-switch warnings before auto-submit</label>
              <input type="number" id="proct-tablimit" class="form-input" min="1" max="10" value="${ps.tab_switch_limit ?? 3}">
            </div>
          </div>
        </div>
      </div>
      <p class="exam-form-note">Questions are randomly selected from the bank for each student.</p>
    </div>
  </div>`;

  function setExamMode(proctored) {
    const cb = document.getElementById('ex-proctored');
    const panel = document.getElementById('proctoring-panel');
    const normalCard = document.getElementById('mode-normal-card');
    const proctCard = document.getElementById('mode-proctored-card');
    if (cb) cb.checked = !!proctored;
    if (panel) panel.style.display = proctored ? 'block' : 'none';
    normalCard?.classList.toggle('is-active-normal', !proctored);
    proctCard?.classList.toggle('is-active-proctored', !!proctored);
  }

  document.getElementById('ex-mode-normal')?.addEventListener('change', () => setExamMode(false));
  document.getElementById('ex-mode-proctored')?.addEventListener('change', () => setExamMode(true));

  const goBack = () => {
    setExamFormId(null);
    loadPage('exams');
  };
  document.getElementById('exam-form-back')?.addEventListener('click', goBack);
  document.getElementById('exam-form-cancel')?.addEventListener('click', goBack);

  document.getElementById('exam-form-save')?.addEventListener('click', async () => {
    const title = document.getElementById('ex-title').value.trim();
    const subject = document.getElementById('ex-subj').value.trim();
    if (!title) { modalService.toast('Exam Title is required', 'error'); return; }
    if (!subject) { modalService.toast('Please select a Course / Subject', 'error'); return; }
    const bankId = document.getElementById('ex-bank').value;
    if (!bankId) { modalService.toast('Please select a Question Bank', 'error'); return; }

    const cfg = {
      exam_id: document.getElementById('ex-id').value.trim() || undefined,
      title,
      subject,
      exam_type: document.getElementById('ex-type').value,
      duration: parseInt(document.getElementById('ex-dur').value, 10) || 30,
      total_questions: parseInt(document.getElementById('ex-qs').value, 10) || 10,
      passing_score: parseInt(document.getElementById('ex-pass').value, 10) || 60,
      question_bank_id: bankId,
      instructions: document.getElementById('ex-inst').value.trim(),
      proctored: document.getElementById('ex-proctored')?.checked || false,
      proctoring_settings: document.getElementById('ex-proctored')?.checked ? {
        camera: document.getElementById('proct-camera')?.checked || false,
        microphone: document.getElementById('proct-mic')?.checked || false,
        copy_paste_block: document.getElementById('proct-copypaste')?.checked || false,
        right_click_block: document.getElementById('proct-rightclick')?.checked || false,
        tab_switch_limit: parseInt(document.getElementById('proct-tablimit')?.value, 10) || 3,
        fullscreen_enforce: document.getElementById('proct-fullscreen')?.checked || false,
        devtools_detect: document.getElementById('proct-devtools')?.checked || false,
        text_select_block: document.getElementById('proct-textselect')?.checked || false,
      } : null,
    };

    const btn = document.getElementById('exam-form-save');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
    try {
      if (isEdit) await ApiClient.updateExam(exam.id, cfg);
      else await ApiClient.createExam(cfg);
      modalService.toast(isEdit ? 'Exam updated!' : 'Exam created!', 'success');
      setExamFormId(null);
      loadPage('exams');
    } catch (e) {
      modalService.toast('Failed to save exam: ' + e.message, 'error');
      if (btn) { btn.disabled = false; btn.textContent = isEdit ? 'Save Changes' : 'Create Exam'; }
    }
  });
}
