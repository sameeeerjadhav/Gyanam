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
          <td>${cfg.proctored ? '<span class="badge badge-red" style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca">🛡️ Yes</span>' : '<span style="color:var(--text-muted);font-size:0.8rem">No</span>'}</td>
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
      <thead><tr><th>Exam ID</th><th>Title</th><th>Type</th><th>Duration</th><th>Questions</th><th>Pass %</th><th>Status</th><th>Proctored</th><th>Actions</th></tr></thead>
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
    <div style="display:flex;align-items:flex-start;gap:0.6rem;padding:0.6rem 0.75rem;background:white;border:1px solid #e2e8f0;border-radius:8px">
      <input type="checkbox" id="${id}" ${checked ? 'checked' : ''} style="width:16px;height:16px;margin-top:2px;accent-color:#dc2626;flex-shrink:0">
      <div>
        <div style="font-size:0.8rem;font-weight:600;color:#1e293b">${icon} ${label}</div>
        <div style="font-size:0.7rem;color:#64748b;margin-top:0.1rem">${desc}</div>
      </div>
    </div>
  `;
}

function getOverlay() {
  let ov = document.getElementById('modal-overlay');
  if (!ov) {
    ov = document.createElement('div');
    ov.id = 'modal-overlay';
    ov.style.cssText = 'display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.45);align-items:center;justify-content:center;overflow-y:auto;padding:1rem';
    ov.innerHTML = '<div id="modal-box" style="margin:auto"></div>';
    document.body.appendChild(ov);
    ov.addEventListener('click', e => { if (e.target === ov) window.closeModal(); });
  }
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

  getOverlay().style.display = 'flex';
  document.getElementById('modal-box').innerHTML = `
    <div class="modal-card" style="max-width:580px;width:95vw;padding:0">
      <div class="modal-header" style="background:linear-gradient(135deg,#4361ee,#3730a3)">
        <div style="display:flex;align-items:center;gap:0.75rem">
          <div style="width:36px;height:36px;background:rgba(255,255,255,0.15);border-radius:8px;display:flex;align-items:center;justify-content:center">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" style="width:18px;height:18px"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          </div>
          <h3 class="modal-title" style="color:#fff;margin:0">${exam ? 'Edit Exam Configuration' : 'New Exam Configuration'}</h3>
        </div>
        <button onclick="closeModal()" style="background:rgba(255,255,255,0.15);border:none;color:#fff;border-radius:6px;padding:0.3rem 0.65rem;cursor:pointer;font-size:1rem;line-height:1">×</button>
      </div>
      <div style="padding:1.25rem;display:flex;flex-direction:column;gap:0">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
          <div class="form-group" style="grid-column:1/-1">
            <label class="form-label">Exam Title *</label>
            <input id="ex-title" class="form-input" value="${exam?.title || ''}" placeholder="e.g. Abacus Level 1 — Final Exam 2025">
          </div>
          <div class="form-group">
            <label class="form-label">Exam ID <span style="font-weight:400;color:var(--text-muted)">(auto if blank)</span></label>
            <input id="ex-id" class="form-input" value="${exam?.exam_id || ''}" placeholder="auto" ${exam ? 'readonly style="background:var(--gray-100)"' : ''}>
          </div>
          <div class="form-group">
            <label class="form-label">Type</label>
            <select id="ex-type" class="form-select">
              <option value="demo" ${exam?.exam_type === 'demo' ? 'selected' : ''}>Demo / Practice</option>
              <option value="main" ${exam?.exam_type === 'main' ? 'selected' : ''}>Main / Official</option>
            </select>
          </div>
          <div class="form-group" style="grid-column:1/-1">
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
          <div class="form-group" style="grid-column:1/-1">
            <label class="form-label">Instructions <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
            <textarea id="ex-inst" class="form-textarea" style="min-height:70px" placeholder="Instructions shown to student before exam starts...">${exam?.instructions || ''}</textarea>
          </div>

          <!-- Proctoring Section -->
          <div class="form-group" style="grid-column:1/-1;margin-top:0.5rem">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:0.75rem 1rem;background:linear-gradient(135deg,#fef2f2,#fff1f2);border:1px solid #fecaca;border-radius:10px;cursor:pointer" onclick="document.getElementById('proctoring-panel').style.display=document.getElementById('proctoring-panel').style.display==='none'?'block':'none';this.querySelector('.proct-arrow').style.transform=document.getElementById('proctoring-panel').style.display==='none'?'':'rotate(180deg)'">
              <div style="display:flex;align-items:center;gap:0.6rem">
                <span style="font-size:1.1rem">🛡️</span>
                <div>
                  <div style="font-weight:700;font-size:0.85rem;color:#991b1b">Proctoring Settings</div>
                  <div style="font-size:0.72rem;color:#b91c1c">Anti-cheating measures for this exam</div>
                </div>
              </div>
              <div style="display:flex;align-items:center;gap:0.75rem">
                <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer" onclick="event.stopPropagation()">
                  <input type="checkbox" id="ex-proctored" ${exam?.proctored ? 'checked' : ''} style="width:18px;height:18px;accent-color:#dc2626">
                  <span style="font-size:0.8rem;font-weight:600;color:#991b1b">Enable</span>
                </label>
                <svg class="proct-arrow" style="width:16px;height:16px;color:#991b1b;transition:transform 0.2s;${exam?.proctored ? 'transform:rotate(180deg)' : ''}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
              </div>
            </div>
            <div id="proctoring-panel" style="display:${exam?.proctored ? 'block' : 'none'};margin-top:0.75rem;padding:1rem;background:#fafbfc;border:1px solid #e2e8f0;border-radius:10px">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                ${_proctoringToggle('proct-camera', 'Camera Access', '📷', 'Request camera for monitoring (non-mandatory)', exam?.proctoring_settings?.camera)}
                ${_proctoringToggle('proct-mic', 'Microphone Access', '🎤', 'Request microphone for audio monitoring (non-mandatory)', exam?.proctoring_settings?.microphone)}
                ${_proctoringToggle('proct-copypaste', 'Block Copy/Paste', '📋', 'Disable Ctrl+C, Ctrl+V, right-click copy', exam?.proctoring_settings?.copy_paste_block !== false)}
                ${_proctoringToggle('proct-rightclick', 'Block Right Click', '🖱️', 'Disable context menu during exam', exam?.proctoring_settings?.right_click_block !== false)}
                ${_proctoringToggle('proct-fullscreen', 'Enforce Fullscreen', '🖥️', 'Force fullscreen mode, warn on exit', exam?.proctoring_settings?.fullscreen_enforce !== false)}
                ${_proctoringToggle('proct-devtools', 'DevTools Detection', '🔧', 'Detect if browser dev tools are opened', exam?.proctoring_settings?.devtools_detect !== false)}
                ${_proctoringToggle('proct-textselect', 'Block Text Selection', '✂️', 'Prevent selecting/highlighting text', exam?.proctoring_settings?.text_select_block !== false)}
              </div>
              <div style="margin-top:0.75rem;display:flex;align-items:center;gap:0.75rem">
                <div style="flex:1">
                  <label style="font-size:0.78rem;font-weight:600;color:#374151;display:block;margin-bottom:0.3rem">⚠️ Tab Switch Warning Limit</label>
                  <div style="display:flex;align-items:center;gap:0.5rem">
                    <input type="number" id="proct-tablimit" class="form-input" style="width:80px" min="1" max="10" value="${exam?.proctoring_settings?.tab_switch_limit ?? 3}">
                    <span style="font-size:0.75rem;color:var(--text-muted)">warnings before auto-submit</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <p style="font-size:0.78rem;color:var(--text-muted);margin:0.25rem 0 0.75rem">ℹ️ Questions are randomly selected from the bank for each student.</p>
        <div class="modal-actions" style="padding-top:0.75rem;border-top:1px solid var(--gray-100);margin-top:0.25rem">
          <button class="modal-btn modal-btn-cancel" onclick="closeModal()">Cancel</button>
          <button class="modal-btn modal-btn-confirm" onclick="doSaveExam('${exam?.id || ''}')">${exam ? 'Save Changes' : 'Create Exam'}</button>
        </div>
      </div>
    </div>`;

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
