/**
 * StudentsModule.js — Renders the Student Records page with bulk assignment support.
 */
import modalService from '../services/ModalService.js';

function getOverlay() {
  let ov = document.getElementById('modal-overlay');
  if (!ov) {
    ov = document.createElement('div');
    ov.id = 'modal-overlay';
    ov.style.cssText = 'display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.45);align-items:center;justify-content:center;overflow-y:auto;padding:1rem';
    ov.innerHTML = '<div id="modal-box"></div>';
    document.body.appendChild(ov);
    ov.addEventListener('click', e => { if (e.target === ov) window.closeModal(); });
  }
  return ov;
}

export async function renderStudents(ApiClient, { currentUser }) {
  const allStudents = await ApiClient.getStudents();
  const el = document.getElementById('page-content');

  // State for filtering
  let filterText = '';
  let filterCentre = '';

  function updateBulkBar() {
    const selected = document.querySelectorAll('.student-select:checked');
    const bar = document.getElementById('bulk-bar');
    const count = document.getElementById('bulk-count');
    if (selected.length > 0) {
      if (bar) bar.style.display = 'flex';
      if (count) count.textContent = selected.length;
    } else {
      if (bar) bar.style.display = 'none';
      const master = document.getElementById('select-all-students');
      if (master) master.checked = false;
    }
  }

  function toggleAllStudents(masterCb) {
    document.querySelectorAll('.student-select').forEach(cb => cb.checked = masterCb.checked);
    updateBulkBar();
  }

  // Export to window for inline onclicks
  window.updateBulkBar = updateBulkBar;
  window.toggleAllStudents = toggleAllStudents;

  function renderTable() {
    const filtered = allStudents.filter(s => {
      const matchesText = !filterText ||
        s.name.toLowerCase().includes(filterText.toLowerCase()) ||
        s.identifier.toLowerCase().includes(filterText.toLowerCase());

      const matchesCentre = !filterCentre || s.centre_name === filterCentre;

      return matchesText && matchesCentre;
    });

    const tbody = document.querySelector('#students-table-body');
    if (!tbody) return;

    tbody.innerHTML = filtered.length === 0
      ? '<tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">No students found matching your criteria.</td></tr>'
      : filtered.map(s => `<tr>
            <td><input type="checkbox" class="student-select" value="${s.id}" onchange="updateBulkBar()"></td>
            <td style="font-family:monospace;font-weight:600">${s.identifier}</td>
            <td>${s.name}</td>
            <td style="font-size:0.82rem">${s.centre_name}</td>
            <td><span class="badge badge-gray">${s.exam_slot} · ${s.time_window}</span></td>
            <td style="font-size:0.82rem">${(s.exams || []).length} exam(s)</td>
            <td>
              <div style="display:flex;gap:0.375rem;flex-wrap:wrap">
                <button class="btn btn-primary btn-sm" onclick="showAssignExamsModal('${s.id}', '${s.name.replace(/'/g, "\\'")}')">📋 Assign Exams</button>
                <button class="btn btn-outline btn-sm" onclick="viewStudentHistory('${s.id}')">History</button>
                <button class="btn btn-outline btn-sm" onclick="editStudent('${s.id}')">Edit</button>
                <button class="btn btn-danger btn-sm" onclick="deleteStudent('${s.id}')">Delete</button>
              </div>
            </td>
          </tr>`).join('');

    // Update header count
    const countLabel = document.getElementById('student-count-label');
    if (countLabel) countLabel.textContent = `${filtered.length} student(s)`;

    // Reset master checkbox and bulk bar
    const master = document.getElementById('select-all-students');
    if (master) master.checked = false;
    updateBulkBar();
  }

  const centres = [...new Set(allStudents.map(s => s.centre_name))].sort().filter(Boolean);
  const scopeNote = currentUser.centre_id ? ` <span style="font-size:0.78rem;color:var(--text-muted)">· ${currentUser.centre_id} only</span>` : '';

  el.innerHTML = `
  <div class="page-header">
    <div><h2>Student Records${scopeNote}</h2><p id="student-count-label">${allStudents.length} students enrolled</p></div>
    <div style="display:flex;gap:0.5rem">
      <button id="import-students-btn" class="btn btn-outline">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m6.75 12L11.25 21m0 0l-3.75-3.75M11.25 21V9.75"/></svg>
        Import CSV
      </button>
      <button id="add-student-btn" class="btn btn-primary">+ Register Student</button>
    </div>
  </div>

  <div class="card" style="margin-bottom:1.5rem; padding:1rem; display:flex; gap:1rem; flex-wrap:wrap; align-items:center; background: var(--gray-50)">
    <div style="flex:1; min-width:240px; position:relative">
      <input type="text" id="stu-search" class="form-input" placeholder="Search by name or student ID..." style="padding-left:2.5rem">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute; left:0.75rem; top:50%; transform:translateY(-50%); width:18px; height:18px; color:var(--gray-400)">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
      </svg>
    </div>
    ${!currentUser.centre_id ? `
    <div style="width:200px">
      <select id="stu-centre-filter" class="form-select">
        <option value="">All Centres</option>
        ${centres.map(c => `<option value="${c}">${c}</option>`).join('')}
      </select>
    </div>` : ''}
  </div>

  <!-- Bulk Action Bar -->
  <div id="bulk-bar" class="card" style="display:none; margin-bottom: 1rem; background: #2563eb; color: white; padding: 0.75rem 1.25rem; align-items: center; justify-content: space-between; border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);">
    <div style="display:flex; align-items:center; gap:12px">
      <div style="background: rgba(255,255,255,0.2); width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.82rem; font-weight: 700;" id="bulk-count">0</div>
      <div style="font-weight: 600; font-size: 0.9375rem;">Students Selected</div>
    </div>
    <div style="display:flex; gap:0.5rem">
      <button class="btn" style="background: white; color: #2563eb; font-weight: 700; border: none; font-size: 0.875rem;" onclick="showBulkEditModal()">✏️ Bulk Edit</button>
      <button class="btn" style="background: white; color: #2563eb; font-weight: 700; border: none; font-size: 0.875rem;" onclick="showBulkAssignModal()">📋 Assign Exam</button>
    </div>
  </div>

  <div class="card">
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:40px"><input type="checkbox" id="select-all-students" onchange="toggleAllStudents(this)"></th>
            <th>Student ID</th><th>Name</th><th>Centre</th><th>Slot</th><th>Exams Assigned</th><th>Actions</th>
          </tr>
        </thead>
        <tbody id="students-table-body"></tbody>
      </table>
    </div>
  </div>`;

  renderTable();

  document.getElementById('stu-search').addEventListener('input', e => {
    filterText = e.target.value;
    renderTable();
  });

  if (!currentUser.centre_id) {
    document.getElementById('stu-centre-filter').addEventListener('change', e => {
      filterCentre = e.target.value;
      renderTable();
    });
  }

  document.getElementById('add-student-btn').addEventListener('click', () => showStudentModal(ApiClient, currentUser));
  document.getElementById('import-students-btn').addEventListener('click', () => showImportStudentsModal(ApiClient, currentUser));

  // ─── Student CRUD ────────────────────────────
  window.editStudent = async (id) => {
    try {
      const students = await ApiClient.getStudents();
      showStudentModal(ApiClient, currentUser, students.find(s => s.id == id));
    } catch (e) { modalService.toast(e.message, 'error'); }
  };

  window.deleteStudent = async (id) => {
    const ok = await modalService.confirm('Delete this student?', { title: 'Delete Student', confirmText: 'Delete', type: 'danger' });
    if (ok) {
      try { await ApiClient.deleteStudent(id); renderStudents(ApiClient, { currentUser }); }
      catch (e) { modalService.toast(e.message, 'error'); }
    }
  };

  window.viewStudentHistory = async (id) => {
    try {
      const [history, students] = await Promise.all([ApiClient.getStudentHistory(id), ApiClient.getStudents()]);
      const student = students.find(s => s.id == id);
      getOverlay().style.display = 'flex';
      document.getElementById('modal-box').innerHTML = `
        <div class="modal-card" style="max-width:600px">
          <div class="modal-header"><h3 class="modal-title">Exam History — ${student?.name || 'Student'}</h3></div>
          ${history.length === 0 ? '<p style="color:var(--text-muted);text-align:center;padding:1.5rem">No exam attempts on record yet.</p>' : `
          <div class="table-wrap" style="max-height:320px;overflow-y:auto">
            <table><thead><tr><th>Exam</th><th>Score</th><th>Result</th><th>Date</th></tr></thead>
            <tbody>${history.map(s => `<tr>
              <td>${s.exam_title || s.exam_id}</td>
              <td>${s.score ?? '-'}%</td>
              <td><span class="badge ${s.result === 'pass' ? 'badge-green' : 'badge-red'}">${s.result || '-'}</span></td>
              <td style="font-size:0.78rem">${s.submitted_at ? new Date(s.submitted_at).toLocaleString() : '-'}</td>
            </tr>`).join('')}</tbody></table>
          </div>`}
          <div class="modal-actions"><button class="modal-btn modal-btn-confirm" onclick="closeModal()">Close</button></div>
        </div>`;
    } catch (e) { modalService.toast(e.message, 'error'); }
  };

  // ─── Assign Exams Modal ──────────────────────
  window.showAssignExamsModal = async (studentId, studentName) => {
    getOverlay().style.display = 'flex';
    const box = document.getElementById('modal-box');
    box.innerHTML = `<div class="modal-card" style="max-width:700px">
      <div class="modal-header"><h3 class="modal-title">📋 Exam Assignments — ${studentName}</h3></div>
      <div id="assign-body" style="padding:1rem"><div class="loader"></div></div>
      <div class="modal-actions"><button class="modal-btn modal-btn-cancel" onclick="closeModal()">Close</button></div>
    </div>`;

    async function reloadAssignBody() {
      const [students, allExams] = await Promise.all([ApiClient.getAssignedStudents(), ApiClient.getAssignableExams()]);
      const student = students.find(s => s.id == studentId);
      const assignedExamIds = (student?.assignments || []).map(a => a.exam_id);
      const unassigned = allExams.filter(e => !assignedExamIds.includes(e.id));

      document.getElementById('assign-body').innerHTML = `
        <div style="margin-bottom:1.25rem">
          <div style="font-weight:600;font-size:0.875rem;margin-bottom:0.75rem;color:var(--gray-700)">Assigned Exams</div>
          ${(student?.assignments || []).length === 0
          ? `<p style="font-size:0.82rem;color:var(--text-muted)">No exams assigned yet.</p>`
          : `<div class="table-wrap"><table>
                <thead><tr><th>Exam</th><th>Subject</th><th>Attempts Used</th><th>Max Allowed</th><th>Actions</th></tr></thead>
                <tbody>${student.assignments.map(a => `<tr>
                  <td style="font-weight:600">${a.title}</td>
                  <td style="color:var(--text-muted);font-size:0.82rem">${a.subject || '—'}</td>
                  <td><span class="badge ${a.remaining === 0 ? 'badge-red' : 'badge-blue'}">${a.used_attempts} / ${a.max_attempts}</span></td>
                  <td><div style="display:flex;align-items:center;gap:0.5rem">
                    <input type="number" id="att-${a.exam_id}" value="${a.max_attempts}" min="1" max="10" style="width:60px;padding:3px 6px;border:1px solid var(--gray-300);border-radius:4px;font-size:0.82rem">
                    <button class="btn btn-outline btn-sm" onclick="updateAttemptLimit(${studentId},${a.exam_id})">Update</button>
                  </div></td>
                  <td><button class="btn btn-danger btn-sm" onclick="removeAssignment(${studentId},${a.exam_id})">Remove</button></td>
                </tr>`).join('')}</tbody>
              </table></div>`}
        </div>
        <div style="border-top:1px solid var(--gray-200);padding-top:1rem">
          <div style="font-weight:600;font-size:0.875rem;margin-bottom:0.75rem;color:var(--gray-700)">Assign New Exam</div>
          ${unassigned.length === 0
          ? `<p style="font-size:0.82rem;color:var(--text-muted)">All available exams are already assigned.</p>`
          : `<div style="display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap">
                <div class="form-group" style="margin:0;flex:1;min-width:200px">
                  <label class="form-label">Exam</label>
                  <select id="new-exam-select" class="form-input" style="height:38px">
                    ${unassigned.map(e => `<option value="${e.id}">${e.title} (${e.subject || 'N/A'})</option>`).join('')}
                  </select>
                </div>
                <div class="form-group" style="margin:0;width:120px">
                  <label class="form-label">Max Attempts</label>
                  <input id="new-exam-attempts" type="number" class="form-input" value="1" min="1" max="10">
                </div>
                <button class="btn btn-primary" style="height:38px;margin-bottom:0" onclick="doAssignExam(${studentId})">+ Assign</button>
              </div>`}
        </div>`;
    }

    window.updateAttemptLimit = async (sId, eId) => {
      const val = parseInt(document.getElementById(`att-${eId}`)?.value) || 1;
      try { await ApiClient.updateAttempts(sId, eId, val); modalService.toast('Attempts updated', 'success'); reloadAssignBody(); }
      catch (e) { modalService.toast(e.message, 'error'); }
    };

    window.removeAssignment = async (sId, eId) => {
      const ok = await modalService.confirm('Remove this exam assignment?', { title: 'Remove Assignment', confirmText: 'Remove', type: 'danger' });
      if (!ok) return;
      try { await ApiClient.unassignExam(sId, eId); modalService.toast('Assignment removed', 'success'); reloadAssignBody(); }
      catch (e) { modalService.toast(e.message, 'error'); }
    };

    window.doAssignExam = async (sId) => {
      const eId = document.getElementById('new-exam-select')?.value;
      const max = parseInt(document.getElementById('new-exam-attempts')?.value) || 1;
      if (!eId) return;
      try { await ApiClient.assignExam(sId, eId, max); modalService.toast('Exam assigned successfully', 'success'); reloadAssignBody(); }
      catch (e) { modalService.toast(e.message, 'error'); }
    };

    try { await reloadAssignBody(); }
    catch (e) { document.getElementById('assign-body').innerHTML = `<p style="color:var(--error)">${e.message}</p>`; }
  };

  // ─── Bulk Assign Modal ───────────────────────
  window.showBulkAssignModal = async () => {
    const selectedIds = [...document.querySelectorAll('.student-select:checked')].map(cb => cb.value);
    if (selectedIds.length === 0) return;
    try {
      const exams = await ApiClient.getAssignableExams();
      getOverlay().style.display = 'flex';
      document.getElementById('modal-box').innerHTML = `
        <div class="modal-card" style="max-width:500px">
          <div class="modal-header"><h3 class="modal-title">📦 Bulk Assign Exam</h3></div>
          <div style="padding:1.5rem">
            <p style="font-size:0.875rem;color:var(--text-muted);margin-bottom:1.5rem">Assigning to <strong>${selectedIds.length}</strong> selected students.</p>
            <div class="form-group">
              <label class="form-label">Select Exam to Assign</label>
              <select id="bulk-exam-id" class="form-input">${exams.map(e => `<option value="${e.id}">${e.title} (${e.subject || 'N/A'})</option>`).join('')}</select>
            </div>
            <div class="form-group">
              <label class="form-label">Max Allowed Attempts</label>
              <input type="number" id="bulk-max-attempts" class="form-input" value="1" min="1" max="10">
              <p style="font-size:0.75rem;color:var(--text-muted);margin-top:0.25rem">Default is 1 attempt.</p>
            </div>
          </div>
          <div class="modal-actions">
            <button class="modal-btn modal-btn-cancel" onclick="closeModal()">Cancel</button>
            <button class="modal-btn modal-btn-confirm" id="bulk-confirm-btn" onclick="doBulkAssign()">Confirm & Assign</button>
          </div>
        </div>`;
    } catch (e) { modalService.toast('Failed to load exams: ' + e.message, 'error'); }
  };

  window.doBulkAssign = async () => {
    const selectedIds = [...document.querySelectorAll('.student-select:checked')].map(cb => cb.value);
    const examId = document.getElementById('bulk-exam-id').value;
    const maxAttempts = parseInt(document.getElementById('bulk-max-attempts').value) || 1;
    const btn = document.getElementById('bulk-confirm-btn');
    if (!examId) return;
    try {
      btn.disabled = true; btn.textContent = 'Assigning...';
      const res = await ApiClient.bulkAssignExam(selectedIds, examId, maxAttempts);
      modalService.toast(res.message, 'success');
      window.closeModal();
      renderStudents(ApiClient, { currentUser });
    } catch (e) {
      modalService.toast('Bulk assignment failed: ' + e.message, 'error');
      btn.disabled = false; btn.textContent = 'Confirm & Assign';
    }
  };

  // ─── Bulk Edit Modal ────────────────────────
  window.showBulkEditModal = () => {
    const selectedIds = [...document.querySelectorAll('.student-select:checked')].map(cb => cb.value);
    if (selectedIds.length === 0) return;

    const centres = [...new Set(allStudents.map(s => s.centre_name))].sort().filter(Boolean);
    const centreOpts = centres.map(c => '<option value="' + c + '">' + c + '</option>').join('');

    getOverlay().style.display = 'flex';
    document.getElementById('modal-box').innerHTML = `
      <div class="modal-card" style="max-width:500px;padding:0">
        <div class="modal-header" style="background:linear-gradient(135deg,#f59e0b,#d97706);padding:1.25rem 1.5rem;display:flex;align-items:center;justify-content:space-between">
          <div style="display:flex;align-items:center;gap:0.75rem">
            <div style="width:36px;height:36px;background:rgba(255,255,255,0.15);border-radius:8px;display:flex;align-items:center;justify-content:center">
              <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" style="width:18px;height:18px"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>
            </div>
            <div>
              <h3 style="color:#fff;margin:0;font-size:1.05rem;font-weight:700">Bulk Edit Students</h3>
              <p style="color:rgba(255,255,255,0.75);margin:0;font-size:0.78rem">${selectedIds.length} student(s) selected</p>
            </div>
          </div>
          <button onclick="closeModal()" style="background:rgba(255,255,255,0.15);border:none;color:#fff;border-radius:6px;padding:0.35rem 0.7rem;cursor:pointer;font-size:1.1rem;line-height:1">×</button>
        </div>
        <div style="padding:1.25rem 1.5rem">
          <p style="font-size:0.82rem;color:var(--text-muted);margin-bottom:1rem;background:var(--gray-50);padding:0.6rem 0.75rem;border-radius:6px;border:1px solid var(--gray-200)">
            ⚡ Only changed fields will be applied. Leave a field empty to keep current values.
          </p>
          <div class="form-group">
            <label class="form-label">Centre Name</label>
            <select id="bulk-centre" class="form-select">
              <option value="">— Keep current —</option>
              ${centreOpts}
            </select>
            <input id="bulk-centre-custom" class="form-input" placeholder="Or type a new centre name..." style="margin-top:0.35rem">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
            <div class="form-group">
              <label class="form-label">Exam Slot</label>
              <select id="bulk-slot" class="form-select">
                <option value="">— Keep current —</option>
                <option value="SLOT1">Slot 1 (Morning)</option>
                <option value="SLOT2">Slot 2 (Afternoon)</option>
                <option value="SLOT3">Slot 3 (Evening)</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Time Window</label>
              <select id="bulk-window" class="form-select">
                <option value="">— Keep current —</option>
                <option value="MORNING">Morning (10:00 - 13:00)</option>
                <option value="AFTERNOON">Afternoon (14:00 - 17:00)</option>
                <option value="EVENING">Evening (18:00 - 21:00)</option>
              </select>
            </div>
          </div>
          <div style="display:flex;gap:0.75rem;justify-content:flex-end;padding-top:0.75rem;border-top:1px solid var(--gray-100);margin-top:0.5rem">
            <button class="modal-btn modal-btn-cancel" onclick="closeModal()">Cancel</button>
            <button class="modal-btn modal-btn-confirm" id="bulk-edit-confirm" onclick="doBulkEdit()">Apply Changes</button>
          </div>
        </div>
      </div>`;
  };

  window.doBulkEdit = async () => {
    const selectedIds = [...document.querySelectorAll('.student-select:checked')].map(cb => cb.value);
    if (selectedIds.length === 0) return;

    const centreSelect = document.getElementById('bulk-centre').value;
    const centreCustom = document.getElementById('bulk-centre-custom').value.trim();
    const centre     = centreCustom || centreSelect || '';
    const slot       = document.getElementById('bulk-slot').value;
    const window_    = document.getElementById('bulk-window').value;

    const fields = {};
    if (centre)  fields.centre_name = centre;
    if (slot)    fields.exam_slot   = slot;
    if (window_) fields.time_window = window_;

    if (Object.keys(fields).length === 0) {
      modalService.toast('No changes selected. Update at least one field.', 'error');
      return;
    }

    const btn = document.getElementById('bulk-edit-confirm');
    try {
      btn.disabled = true; btn.textContent = 'Applying...';
      const res = await ApiClient.bulkUpdateStudents(selectedIds, fields);
      modalService.toast(res.message, 'success');
      window.closeModal();
      renderStudents(ApiClient, { currentUser });
    } catch (e) {
      modalService.toast('Bulk edit failed: ' + e.message, 'error');
      btn.disabled = false; btn.textContent = 'Apply Changes';
    }
  };
}

async function showStudentModal(ApiClient, currentUser, student = null) {
  const exams = await ApiClient.getExams();
  const assignedIds = (student?.exams || []).map(e => e.id);
  getOverlay().style.display = 'flex';
  document.getElementById('modal-box').innerHTML = `
    <div class="modal-card" style="max-width:560px">
      <div class="modal-header"><h3 class="modal-title">${student ? 'Edit Student' : 'Register New Student'}</h3></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
        <div class="form-group" style="grid-column:1/-1"><label class="form-label">Full Name *</label><input id="st-name" class="form-input" value="${student?.name || ''}"></div>
        <div class="form-group"><label class="form-label">Student ID (Internal) *</label><input id="st-id" class="form-input" value="${student?.identifier || ''}" ${student ? 'disabled' : ''}></div>
        <div class="form-group"><label class="form-label">Centre *</label><input id="st-centre" class="form-input" value="${student?.centre_name || currentUser.centre_id || ''}" ${currentUser.centre_id ? 'disabled' : ''}></div>
                <div class="form-group">
          <label class="form-label">Exam Slot</label>
          <select id="st-slot" class="form-select">
            <option value="SLOT1" ${student?.exam_slot === 'SLOT1' ? 'selected' : ''}>Slot 1 (Morning)</option>
            <option value="SLOT2" ${student?.exam_slot === 'SLOT2' ? 'selected' : ''}>Slot 2 (Afternoon)</option>
            <option value="SLOT3" ${student?.exam_slot === 'SLOT3' ? 'selected' : ''}>Slot 3 (Evening)</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Time Window</label>
          <select id="st-window" class="form-select">
            <option value="MORNING" ${student?.time_window === 'MORNING' ? 'selected' : ''}>Morning (10:00 - 13:00)</option>
            <option value="AFTERNOON" ${student?.time_window === 'AFTERNOON' ? 'selected' : ''}>Afternoon (14:00 - 17:00)</option>
            <option value="EVENING" ${student?.time_window === 'EVENING' ? 'selected' : ''}>Evening (18:00 - 21:00)</option>
          </select>
        </div>

      </div>
      <div class="modal-actions">
        <button class="modal-btn modal-btn-cancel" onclick="closeModal()">Cancel</button>
        <button class="modal-btn modal-btn-confirm" onclick="doSaveStudent('${student?.id || ''}')"> ${student ? 'Save Changes' : 'Register'}</button>
      </div>
    </div>`;

  window.doSaveStudent = async (studentDbId) => {
    const payload = {
      name: document.getElementById('st-name').value.trim(),
      identifier: document.getElementById('st-id').value.trim(),
      centre_name: document.getElementById('st-centre').value.trim(),
      exam_slot: document.getElementById('st-slot').value.trim(),
      time_window: document.getElementById('st-window').value.trim(),
      exams: [...document.querySelectorAll('.exam-check:checked')].map(c => c.value)
    };
    if (!payload.name || !payload.identifier || !payload.centre_name) { modalService.toast('Fill all required fields', 'error'); return; }
    try {
      if (studentDbId) { await ApiClient.updateStudent(studentDbId, payload); }
      else { await ApiClient.createStudent(payload); }
      window.closeModal();
      renderStudents(ApiClient, { currentUser });
    } catch (e) { modalService.toast('Save failed: ' + e.message, 'error'); }
  };
}

function showImportStudentsModal(ApiClient, currentUser) {
  getOverlay().style.display = 'flex';
  document.getElementById('modal-box').innerHTML = `
    <div class="modal-card" style="max-width:560px">
      <div class="modal-header">
        <div class="modal-icon info"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m6.75 12L11.25 21m0 0l-3.75-3.75M11.25 21V9.75"/></svg></div>
        <h3 class="modal-title">Bulk Import Students (CSV)</h3>
      </div>
      <div class="form-group"><label class="form-label">CSV Content</label>
        <textarea id="csv-stu" class="form-textarea" style="min-height:150px;font-family:monospace;font-size:0.82rem"
          placeholder="StudentID,Full Name,Centre,SLOT1,MORNING,exam_001;exam_002&#10;STU001,Ravi Kumar,Delhi Centre,SLOT1,MORNING,exam_demo"></textarea>
      </div>
      <p style="font-size:0.78rem;color:var(--text-muted)">Format: ID, Name, Centre, Slot, Window, ExamIDs (semicolon separated)</p>
      <div class="modal-actions">
        <button class="modal-btn modal-btn-cancel" onclick="closeModal()">Cancel</button>
        <button class="modal-btn modal-btn-confirm" onclick="doImportStudents()">Import Students</button>
      </div>
    </div>`;

  window.doImportStudents = async () => {
    const csv = document.getElementById('csv-stu').value.trim();
    if (!csv) { modalService.toast('Paste CSV content first.', 'error'); return; }
    try {
      const result = await ApiClient.request('/students/import', { method: 'POST', body: JSON.stringify({ csv }) });
      window.closeModal();
      renderStudents(ApiClient, { currentUser });
      modalService.toast(`Registered ${result.added} students. Skipped ${result.skipped} rows.`, 'success');
    } catch (e) { modalService.toast('Import failed: ' + e.message, 'error'); }
  };
}
