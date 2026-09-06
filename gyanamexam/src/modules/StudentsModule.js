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
  let filterSlot = '';
  let filterWindow = '';
  let filterExams = ''; // '' | 'assigned' | 'none'

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

  function hasActiveFilters() {
    return !!(filterText || filterCentre || filterSlot || filterWindow || filterExams);
  }

  function syncClearBtn() {
    const btn = document.getElementById('stu-clear-filters');
    if (btn) btn.hidden = !hasActiveFilters();
  }

  function clearFilters() {
    filterText = '';
    filterCentre = '';
    filterSlot = '';
    filterWindow = '';
    filterExams = '';
    const search = document.getElementById('stu-search');
    const centre = document.getElementById('stu-centre-filter');
    const slot = document.getElementById('stu-slot-filter');
    const win = document.getElementById('stu-window-filter');
    const exams = document.getElementById('stu-exams-filter');
    if (search) search.value = '';
    if (centre) centre.value = '';
    if (slot) slot.value = '';
    if (win) win.value = '';
    if (exams) exams.value = '';
    renderTable();
  }

  // Export to window for inline onclicks
  window.updateBulkBar = updateBulkBar;
  window.toggleAllStudents = toggleAllStudents;
  window.clearStudentFilters = clearFilters;

  function renderTable() {
    const filtered = allStudents.filter(s => {
      const q = filterText.trim().toLowerCase();
      const matchesText = !q ||
        (s.name || '').toLowerCase().includes(q) ||
        (s.identifier || '').toLowerCase().includes(q) ||
        (s.centre_name || '').toLowerCase().includes(q);

      const matchesCentre = !filterCentre || s.centre_name === filterCentre;
      const matchesSlot = !filterSlot || s.exam_slot === filterSlot;
      const matchesWindow = !filterWindow || s.time_window === filterWindow;
      const examCount = (s.exams || []).length;
      const matchesExams =
        !filterExams ||
        (filterExams === 'assigned' && examCount > 0) ||
        (filterExams === 'none' && examCount === 0);

      return matchesText && matchesCentre && matchesSlot && matchesWindow && matchesExams;
    });

    const tbody = document.querySelector('#students-table-body');
    if (!tbody) return;

    const esc = (str) => String(str || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");

    tbody.innerHTML = filtered.length === 0
      ? `<tr><td colspan="7" class="stu-empty">
            <div class="stu-empty-inner">
              <div class="stu-empty-icon">👥</div>
              <div>No students match your filters</div>
              ${hasActiveFilters() ? '<button type="button" class="btn btn-outline btn-sm" style="margin-top:0.65rem" onclick="clearStudentFilters()">Clear filters</button>' : ''}
            </div>
         </td></tr>`
      : filtered.map(s => {
          const examCount = (s.exams || []).length;
          const nameSafe = esc(s.name);
          return `<tr class="stu-row">
            <td class="stu-check"><input type="checkbox" class="student-select" value="${s.id}" onchange="updateBulkBar()"></td>
            <td>
              <div class="stu-id">${s.identifier}</div>
            </td>
            <td>
              <div class="stu-name">${s.name}</div>
              <div class="stu-sub">${s.centre_name || '—'}</div>
            </td>
            <td class="stu-centre-col">${s.centre_name || '—'}</td>
            <td>
              <span class="stu-slot">${s.exam_slot || '—'} · ${s.time_window || '—'}</span>
            </td>
            <td>
              <span class="stu-exam-count ${examCount > 0 ? 'has' : ''}">${examCount} exam${examCount === 1 ? '' : 's'}</span>
            </td>
            <td>
              <div class="stu-actions" role="group" aria-label="Actions">
                <button type="button" class="stu-act stu-act-primary" title="Assign Exams" onclick="showAssignExamsModal('${s.id}', '${nameSafe}')">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                  Assign
                </button>
                <button type="button" class="stu-act" title="Exam History" onclick="viewStudentHistory('${s.id}')">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </button>
                <button type="button" class="stu-act" title="Edit" onclick="editStudent('${s.id}')">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                </button>
                <button type="button" class="stu-act stu-act-danger" title="Delete" onclick="deleteStudent('${s.id}')">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                </button>
              </div>
            </td>
          </tr>`;
        }).join('');

    // Update header count
    const countLabel = document.getElementById('student-count-label');
    if (countLabel) countLabel.textContent = `${filtered.length} of ${allStudents.length} student${allStudents.length === 1 ? '' : 's'}`;

    // Reset master checkbox and bulk bar
    const master = document.getElementById('select-all-students');
    if (master) master.checked = false;
    updateBulkBar();
    syncClearBtn();
  }

  const centres = [...new Set(allStudents.map(s => s.centre_name))].sort().filter(Boolean);
  const slots = [...new Set(allStudents.map(s => s.exam_slot))].sort().filter(Boolean);
  const windows = [...new Set(allStudents.map(s => s.time_window))].sort().filter(Boolean);
  const slotOrder = { SLOT1: 1, SLOT2: 2, SLOT3: 3 };
  const windowOrder = { MORNING: 1, AFTERNOON: 2, EVENING: 3 };
  slots.sort((a, b) => (slotOrder[a] || 99) - (slotOrder[b] || 99) || a.localeCompare(b));
  windows.sort((a, b) => (windowOrder[a] || 99) - (windowOrder[b] || 99) || a.localeCompare(b));

  const scopeNote = currentUser.centre_id
    ? `<span class="stu-scope">${currentUser.centre_id}</span>`
    : '';

  const slotOpts = slots.length
    ? slots.map(s => `<option value="${s}">${s}</option>`).join('')
    : '<option value="SLOT1">SLOT1</option><option value="SLOT2">SLOT2</option><option value="SLOT3">SLOT3</option>';
  const windowOpts = windows.length
    ? windows.map(w => `<option value="${w}">${w.charAt(0) + w.slice(1).toLowerCase()}</option>`).join('')
    : '<option value="MORNING">Morning</option><option value="AFTERNOON">Afternoon</option><option value="EVENING">Evening</option>';

  el.innerHTML = `
  <div class="stu-page">
    <div class="page-header stu-header">
      <div>
        <h2>Student Records ${scopeNote}</h2>
        <p id="student-count-label">${allStudents.length} student${allStudents.length === 1 ? '' : 's'}</p>
      </div>
      <div class="stu-header-actions">
        <button id="import-students-btn" class="btn btn-outline btn-sm">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
          Import CSV
        </button>
        <button id="add-student-btn" class="btn btn-primary btn-sm">+ Register Student</button>
      </div>
    </div>

    <div class="stu-toolbar card">
      <div class="stu-search-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
        </svg>
        <input type="text" id="stu-search" class="form-input" placeholder="Search name, ID, or centre…">
      </div>
      <div class="stu-filters">
        ${!currentUser.centre_id ? `
        <select id="stu-centre-filter" class="form-select" title="Centre">
          <option value="">All Centres</option>
          ${centres.map(c => `<option value="${c}">${c}</option>`).join('')}
        </select>` : ''}
        <select id="stu-slot-filter" class="form-select" title="Exam slot">
          <option value="">All Slots</option>
          ${slotOpts}
        </select>
        <select id="stu-window-filter" class="form-select" title="Time window">
          <option value="">All Times</option>
          ${windowOpts}
        </select>
        <select id="stu-exams-filter" class="form-select" title="Exam assignment">
          <option value="">All Exams</option>
          <option value="assigned">Has exams</option>
          <option value="none">No exams</option>
        </select>
        <button type="button" id="stu-clear-filters" class="btn btn-outline btn-sm stu-clear-btn" hidden onclick="clearStudentFilters()">Clear</button>
      </div>
    </div>

    <div id="bulk-bar" class="stu-bulk-bar" style="display:none">
      <div class="stu-bulk-left">
        <span class="stu-bulk-count" id="bulk-count">0</span>
        <span>selected</span>
      </div>
      <div class="stu-bulk-actions">
        <button type="button" class="btn btn-sm" onclick="showBulkEditModal()">Bulk Edit</button>
        <button type="button" class="btn btn-sm" onclick="showBulkAssignModal()">Assign Exam</button>
      </div>
    </div>

    <div class="card stu-table-card">
      <div class="table-wrap stu-table-wrap">
        <table class="stu-table">
          <thead>
            <tr>
              <th class="stu-check"><input type="checkbox" id="select-all-students" onchange="toggleAllStudents(this)" title="Select all"></th>
              <th>Student ID</th>
              <th>Name</th>
              <th class="stu-centre-col">Centre</th>
              <th>Slot</th>
              <th>Exams</th>
              <th style="text-align:right">Actions</th>
            </tr>
          </thead>
          <tbody id="students-table-body"></tbody>
        </table>
      </div>
    </div>
  </div>`;

  renderTable();

  document.getElementById('stu-search').addEventListener('input', e => {
    filterText = e.target.value;
    renderTable();
  });

  document.getElementById('stu-centre-filter')?.addEventListener('change', e => {
    filterCentre = e.target.value;
    renderTable();
  });
  document.getElementById('stu-slot-filter')?.addEventListener('change', e => {
    filterSlot = e.target.value;
    renderTable();
  });
  document.getElementById('stu-window-filter')?.addEventListener('change', e => {
    filterWindow = e.target.value;
    renderTable();
  });
  document.getElementById('stu-exams-filter')?.addEventListener('change', e => {
    filterExams = e.target.value;
    renderTable();
  });

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
