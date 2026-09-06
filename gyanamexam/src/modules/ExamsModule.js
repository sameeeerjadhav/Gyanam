/**
 * ExamsModule.js — Renders the Exam Configurations page.
 * Subject dropdown populated from portal-courses API (synced from main Gyanam portal).
 */
import modalService from '../services/ModalService.js';

// Cache portal courses for the session
let _examCourseCache = null;
async function loadCourses(ApiClient) {
  if (_examCourseCache) return _examCourseCache;
  try {
    const res = await ApiClient.getPortalCourses();
    _examCourseCache = res.courses || [];
  } catch (e) { _examCourseCache = []; }
  return _examCourseCache;
}

export async function renderExams(ApiClient) {
  const [allConfigs, allBanks] = await Promise.all([ApiClient.getExams(), ApiClient.getQuestionBanks()]);
  const el = document.getElementById('page-content');

  let filterText = '';
  let filterType = '';
  let filterStatus = '';

  function renderTable() {
    const filtered = allConfigs.filter(cfg => {
      const matchesText = !filterText ||
        cfg.title.toLowerCase().includes(filterText.toLowerCase()) ||
        cfg.exam_id.toLowerCase().includes(filterText.toLowerCase());
      const matchesType = !filterType || cfg.exam_type === filterType;
      const matchesStatus = !filterStatus || (filterStatus === 'active' ? cfg.active : !cfg.active);
      return matchesText && matchesType && matchesStatus;
    });

    const tbody = document.querySelector('#exams-table-body');
    if (!tbody) return;

    tbody.innerHTML = filtered.length === 0
      ? '<tr><td colspan="9" style="text-align:center;color:var(--text-muted);padding:2rem">No matching exams found.</td></tr>'
      : filtered.map(cfg => `
        <tr>
          <td style="font-family:monospace;font-size:0.78rem;color:var(--text-muted)">${cfg.exam_id}</td>
          <td style="font-weight:600">${cfg.title}</td>
          <td><span class="badge ${cfg.exam_type === 'demo' ? 'badge-blue' : 'badge-gray'}">${cfg.exam_type}</span></td>
          <td>${cfg.duration} min</td>
          <td>${cfg.total_questions}</td>
          <td>${cfg.passing_score}%</td>
          <td><span class="badge ${cfg.active ? 'badge-green' : 'badge-gray'}">${cfg.active ? 'Active' : 'Inactive'}</span></td>
          <td>${cfg.proctored ? '<span class="badge badge-red" style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca">🛡️ Proctored</span>' : '<span class="badge" style="background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0">Normal</span>'}</td>
          <td>
            <div style="display:flex;gap:0.375rem">
              <button class="btn btn-outline btn-sm" onclick="toggleExam('${cfg.id}','${cfg.active}')">${cfg.active ? 'Deactivate' : 'Activate'}</button>
              <button class="btn btn-outline btn-sm" onclick="editExam('${cfg.id}')">Edit</button>
              <button class="btn btn-danger btn-sm" onclick="deleteExam('${cfg.id}')">Delete</button>
            </div>
          </td>
        </tr>`).join('');
  }

  el.innerHTML = `
  <div class="page-header">
    <div><h2>Exam Configurations</h2><p id="exam-count-label">${allConfigs.length} exam(s) configured</p></div>
    <button id="add-exam-btn" class="btn btn-primary">+ New Exam</button>
  </div>

  <div class="card" style="margin-bottom:1.5rem; padding:1rem; display:flex; gap:1rem; flex-wrap:wrap; align-items:center; background: var(--gray-50)">
    <div style="flex:1; min-width:240px; position:relative">
      <input type="text" id="ex-search" class="form-input" placeholder="Search by title or ID..." style="padding-left:2.5rem">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute; left:0.75rem; top:50%; transform:translateY(-50%); width:18px; height:18px; color:var(--gray-400)">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
      </svg>
    </div>
    <div style="width:150px">
      <select id="ex-type-filter" class="form-select">
        <option value="">All Types</option>
        <option value="main">Main</option>
        <option value="demo">Demo</option>
      </select>
    </div>
    <div style="width:150px">
      <select id="ex-status-filter" class="form-select">
        <option value="">All Status</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
      </select>
    </div>
  </div>

  <div class="table-wrap card">
    <table>
      <thead><tr><th>Exam ID</th><th>Title</th><th>Type</th><th>Duration</th><th>Questions</th><th>Pass %</th><th>Status</th><th>Mode</th><th>Actions</th></tr></thead>
      <tbody id="exams-table-body"></tbody>
    </table>
  </div>`;

  renderTable();

  document.getElementById('ex-search').addEventListener('input', e => { filterText = e.target.value; renderTable(); });
  document.getElementById('ex-type-filter').addEventListener('change', e => { filterType = e.target.value; renderTable(); });
  document.getElementById('ex-status-filter').addEventListener('change', e => { filterStatus = e.target.value; renderTable(); });
  document.getElementById('add-exam-btn').addEventListener('click', () => showExamModal(ApiClient));

  window.editExam = async (id) => {
    try {
      const exams = await ApiClient.getExams();
      showExamModal(ApiClient, exams.find(e => e.id == id));
    } catch (e) { modalService.toast(e.message, 'error'); }
  };

  window.toggleExam = async (id) => {
    try { await ApiClient.toggleExam(id); renderExams(ApiClient); }
    catch (e) { modalService.toast(e.message, 'error'); }
  };

  window.deleteExam = async (id) => {
    const ok = await modalService.confirm('Delete this exam configuration?', { title: 'Delete Exam', confirmText: 'Delete', type: 'danger' });
    if (ok) {
      try { await ApiClient.deleteExam(id); renderExams(ApiClient); }
      catch (e) { modalService.toast(e.message, 'error'); }
    }
  };
}

function _proctoringToggle(id, label, icon, desc, checked) {
  return `
    <label for="${id}" style="display:flex;align-items:flex-start;gap:0.55rem;padding:0.55rem 0.65rem;background:#fff;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;transition:border-color .15s">
      <input type="checkbox" id="${id}" ${checked ? 'checked' : ''} style="width:15px;height:15px;margin-top:2px;accent-color:#dc2626;flex-shrink:0">
      <span>
        <span style="display:block;font-size:0.78rem;font-weight:650;color:#1e293b;line-height:1.25">${icon} ${label}</span>
        <span style="display:block;font-size:0.68rem;color:#64748b;margin-top:0.12rem;line-height:1.35">${desc}</span>
      </span>
    </label>
  `;
}

function getOverlay() {
  let ov = document.getElementById('modal-overlay');
  if (!ov) {
    ov = document.createElement('div');
    ov.id = 'modal-overlay';
    ov.style.cssText = 'display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,0.55);align-items:flex-start;justify-content:center;overflow-y:auto;overflow-x:hidden;padding:1.25rem 1rem;box-sizing:border-box';
    ov.innerHTML = '<div id="modal-box" style="margin:auto;width:100%;display:flex;justify-content:center;padding:0.25rem 0"></div>';
    document.body.appendChild(ov);
    ov.addEventListener('click', e => { if (e.target === ov) window.closeModal(); });
  }
  // Patch overlay created earlier by ModalService (centered, no scroll) so tall modals work
  ov.style.alignItems = 'flex-start';
  ov.style.justifyContent = 'center';
  ov.style.overflowY = 'auto';
  ov.style.overflowX = 'hidden';
  ov.style.padding = '1.25rem 1rem';
  ov.style.boxSizing = 'border-box';
  const box = document.getElementById('modal-box');
  if (box) {
    box.style.margin = 'auto';
    box.style.width = '100%';
    box.style.display = 'flex';
    box.style.justifyContent = 'center';
    box.style.padding = '0.25rem 0';
    box.style.transform = 'none';
    box.style.opacity = '1';
  }
  document.body.style.overflow = 'hidden';
  return ov;
}

async function showExamModal(ApiClient, exam = null) {
  const [banks, courses] = await Promise.all([ApiClient.getQuestionBanks(), loadCourses(ApiClient)]);

  // Build bank options
  const bankOpts = banks.length > 0
    ? banks.map(b => '<option value="' + b.id + '" ' + (exam?.question_bank_id === b.id ? 'selected' : '') + '>' + b.title + ' — ' + b.subject + ' (' + b.questions_count + ' Qs)</option>').join('')
    : '<option value="">No question banks created yet</option>';

  // Subject field: safe string build, no nested template literals
  let subjectField;
  if (courses.length > 0) {
    let opts = '<option value="">Select a course…</option>';
    courses.forEach(c => {
      const val = c.course_name;
      const label = c.course_type ? c.course_name + ' (' + c.course_type + ')' : c.course_name;
      const sel = (exam?.subject === val) ? ' selected' : '';
      opts += '<option value="' + val + '"' + sel + '>' + label + '</option>';
    });
    subjectField = '<select id="ex-subj" class="form-select">' + opts + '</select>';
  } else {
    subjectField = '<input id="ex-subj" class="form-input" value="' + (exam?.subject || '') + '" placeholder="e.g. Abacus Level 1, DCA…">'
      + '<p style="font-size:0.75rem;color:var(--text-muted);margin-top:0.25rem">Sync courses from main portal (Admin › Courses) to get a dropdown.</p>';
  }

  const isProctored = !!exam?.proctored;

  getOverlay().style.display = 'flex';
  document.getElementById('modal-box').innerHTML = `
    <div class="modal-card exam-config-modal" style="max-width:640px;width:min(95vw,640px);padding:0;margin:0 auto;display:flex;flex-direction:column;max-height:min(92vh,900px);overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,.35)">
      <div class="modal-header" style="flex-shrink:0;margin:0;padding:1rem 1.25rem;background:linear-gradient(135deg,#1e3a8a,#3730a3);border-radius:var(--radius-xl) var(--radius-xl) 0 0">
        <div style="display:flex;align-items:center;gap:0.75rem;min-width:0">
          <div style="width:36px;height:36px;background:rgba(255,255,255,0.15);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" style="width:18px;height:18px"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          </div>
          <div style="min-width:0">
            <h3 class="modal-title" style="color:#fff;margin:0;font-size:1.05rem">${exam ? 'Edit Exam Configuration' : 'New Exam Configuration'}</h3>
            <p style="margin:0.15rem 0 0;font-size:0.72rem;color:rgba(255,255,255,0.7)">Choose Normal or Proctored mode for this exam</p>
          </div>
        </div>
        <button type="button" onclick="closeModal()" style="background:rgba(255,255,255,0.15);border:none;color:#fff;border-radius:6px;padding:0.35rem 0.7rem;cursor:pointer;font-size:1.1rem;line-height:1;flex-shrink:0" aria-label="Close">×</button>
      </div>

      <div style="flex:1 1 auto;min-height:0;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;padding:1.1rem 1.25rem 0.5rem">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.85rem 0.75rem">
          <div class="form-group" style="grid-column:1/-1;margin:0">
            <label class="form-label">Exam Title *</label>
            <input id="ex-title" class="form-input" value="${exam?.title || ''}" placeholder="e.g. Abacus Level 1 — Final Exam 2025">
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Exam ID <span style="font-weight:400;color:var(--text-muted)">(auto if blank)</span></label>
            <input id="ex-id" class="form-input" value="${exam?.exam_id || ''}" placeholder="auto" ${exam ? 'readonly style="background:var(--gray-100)"' : ''}>
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Type</label>
            <select id="ex-type" class="form-select">
              <option value="demo" ${exam?.exam_type === 'demo' ? 'selected' : ''}>Demo / Practice</option>
              <option value="main" ${exam?.exam_type === 'main' ? 'selected' : ''}>Main / Official</option>
            </select>
          </div>
          <div class="form-group" style="grid-column:1/-1;margin:0">
            <label class="form-label">Course / Subject *</label>
            ${subjectField}
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Duration (minutes)</label>
            <input id="ex-dur" class="form-input" type="number" value="${exam?.duration || 30}" min="1">
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Questions to Show</label>
            <input id="ex-qs" class="form-input" type="number" value="${exam?.total_questions || 10}" min="1">
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Passing Score (%)</label>
            <input id="ex-pass" class="form-input" type="number" value="${exam?.passing_score || 60}" min="1" max="100">
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Question Bank *</label>
            <select id="ex-bank" class="form-select">${bankOpts}</select>
          </div>
          <div class="form-group" style="grid-column:1/-1;margin:0">
            <label class="form-label">Instructions <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
            <textarea id="ex-inst" class="form-textarea" style="min-height:56px;max-height:100px;resize:vertical" placeholder="Shown to student before the exam starts…">${exam?.instructions || ''}</textarea>
          </div>

          <div class="form-group" style="grid-column:1/-1;margin:0.25rem 0 0">
            <label class="form-label" style="margin-bottom:0.45rem">Exam Mode *</label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.55rem">
              <label id="mode-normal-card" style="display:flex;align-items:flex-start;gap:0.55rem;padding:0.75rem 0.85rem;border:2px solid ${isProctored ? '#e5e7eb' : '#16a34a'};border-radius:10px;background:${isProctored ? '#fff' : '#f0fdf4'};cursor:pointer;transition:all .15s">
                <input type="radio" name="ex-mode" id="ex-mode-normal" value="normal" ${isProctored ? '' : 'checked'} style="margin-top:2px;accent-color:#16a34a" onchange="window.setExamMode(false)">
                <span>
                  <span style="display:block;font-weight:700;font-size:0.84rem;color:#166534">Normal</span>
                  <span style="display:block;font-size:0.68rem;color:#64748b;margin-top:0.12rem;line-height:1.35">Standard exam — no anti-cheat monitoring.</span>
                </span>
              </label>
              <label id="mode-proctored-card" style="display:flex;align-items:flex-start;gap:0.55rem;padding:0.75rem 0.85rem;border:2px solid ${isProctored ? '#dc2626' : '#e5e7eb'};border-radius:10px;background:${isProctored ? '#fef2f2' : '#fff'};cursor:pointer;transition:all .15s">
                <input type="radio" name="ex-mode" id="ex-mode-proctored" value="proctored" ${isProctored ? 'checked' : ''} style="margin-top:2px;accent-color:#dc2626" onchange="window.setExamMode(true)">
                <span>
                  <span style="display:block;font-weight:700;font-size:0.84rem;color:#991b1b">Proctored</span>
                  <span style="display:block;font-size:0.68rem;color:#64748b;margin-top:0.12rem;line-height:1.35">Anti-cheat: tab switch, fullscreen, copy block.</span>
                </span>
              </label>
            </div>
            <input type="checkbox" id="ex-proctored" ${isProctored ? 'checked' : ''} style="display:none" aria-hidden="true" tabindex="-1">
            <div id="proctoring-panel" style="display:${isProctored ? 'block' : 'none'};margin-top:0.65rem;padding:0.75rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px">
              <div style="font-size:0.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-bottom:0.55rem">Proctoring options</div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem">
                ${_proctoringToggle('proct-camera', 'Camera Access', '📷', 'Request camera (optional)', exam?.proctoring_settings?.camera)}
                ${_proctoringToggle('proct-mic', 'Microphone', '🎤', 'Request mic (optional)', exam?.proctoring_settings?.microphone)}
                ${_proctoringToggle('proct-copypaste', 'Block Copy/Paste', '📋', 'Disable Ctrl+C / Ctrl+V', exam?.proctoring_settings?.copy_paste_block !== false)}
                ${_proctoringToggle('proct-rightclick', 'Block Right Click', '🖱️', 'Disable context menu', exam?.proctoring_settings?.right_click_block !== false)}
                ${_proctoringToggle('proct-fullscreen', 'Enforce Fullscreen', '🖥️', 'Warn on exit fullscreen', exam?.proctoring_settings?.fullscreen_enforce !== false)}
                ${_proctoringToggle('proct-devtools', 'DevTools Detection', '🔧', 'Detect open dev tools', exam?.proctoring_settings?.devtools_detect !== false)}
                ${_proctoringToggle('proct-textselect', 'Block Text Select', '✂️', 'Prevent text highlighting', exam?.proctoring_settings?.text_select_block !== false)}
              </div>
              <div style="margin-top:0.65rem;display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap">
                <label style="font-size:0.75rem;font-weight:600;color:#374151">Tab-switch warnings before auto-submit</label>
                <input type="number" id="proct-tablimit" class="form-input" style="width:72px" min="1" max="10" value="${exam?.proctoring_settings?.tab_switch_limit ?? 3}">
              </div>
            </div>
          </div>
        </div>
        <p style="font-size:0.74rem;color:#64748b;margin:0.85rem 0 0.35rem;line-height:1.45">Questions are randomly selected from the bank for each student.</p>
      </div>

      <div class="modal-actions" style="flex-shrink:0;margin:0;padding:0.85rem 1.25rem;border-top:1px solid #e5e7eb;background:#fafafa;border-radius:0 0 var(--radius-xl) var(--radius-xl);display:flex;justify-content:flex-end;gap:0.5rem">
        <button type="button" class="modal-btn modal-btn-cancel" onclick="closeModal()">Cancel</button>
        <button type="button" class="modal-btn modal-btn-confirm" onclick="doSaveExam('${exam?.id || ''}')">${exam ? 'Save Changes' : 'Create Exam'}</button>
      </div>
    </div>`;

  window.setExamMode = (proctored) => {
    const cb = document.getElementById('ex-proctored');
    const panel = document.getElementById('proctoring-panel');
    const normalCard = document.getElementById('mode-normal-card');
    const proctCard = document.getElementById('mode-proctored-card');
    if (cb) cb.checked = !!proctored;
    if (panel) panel.style.display = proctored ? 'block' : 'none';
    if (normalCard) {
      normalCard.style.borderColor = proctored ? '#e5e7eb' : '#16a34a';
      normalCard.style.background = proctored ? '#fff' : '#f0fdf4';
    }
    if (proctCard) {
      proctCard.style.borderColor = proctored ? '#dc2626' : '#e5e7eb';
      proctCard.style.background = proctored ? '#fef2f2' : '#fff';
    }
  };

  window.doSaveExam = async (existingDbId) => {
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
      duration: parseInt(document.getElementById('ex-dur').value) || 30,
      total_questions: parseInt(document.getElementById('ex-qs').value) || 10,
      passing_score: parseInt(document.getElementById('ex-pass').value) || 60,
      question_bank_id: bankId,
      instructions: document.getElementById('ex-inst').value.trim(),
      proctored: document.getElementById('ex-proctored')?.checked || false,
      proctoring_settings: document.getElementById('ex-proctored')?.checked ? {
        camera: document.getElementById('proct-camera')?.checked || false,
        microphone: document.getElementById('proct-mic')?.checked || false,
        copy_paste_block: document.getElementById('proct-copypaste')?.checked || false,
        right_click_block: document.getElementById('proct-rightclick')?.checked || false,
        tab_switch_limit: parseInt(document.getElementById('proct-tablimit')?.value) || 3,
        fullscreen_enforce: document.getElementById('proct-fullscreen')?.checked || false,
        devtools_detect: document.getElementById('proct-devtools')?.checked || false,
        text_select_block: document.getElementById('proct-textselect')?.checked || false,
      } : null,
    };
    try {
      if (existingDbId) { await ApiClient.updateExam(existingDbId, cfg); }
      else { await ApiClient.createExam(cfg); }
      window.closeModal();
      _examCourseCache = null;
      renderExams(ApiClient);
      modalService.toast(existingDbId ? 'Exam updated!' : 'Exam created!', 'success');
    } catch (e) { modalService.toast('Failed to save exam: ' + e.message, 'error'); }
  };
}
