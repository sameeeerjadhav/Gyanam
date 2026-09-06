/**
 * ExamsModule.js — Exam Configurations list page.
 * Create/Edit opens ExamFormModule as a dedicated page.
 */
import modalService from '../services/ModalService.js';
import { setExamFormId } from './ExamFormModule.js';

export async function renderExams(ApiClient, { loadPage } = {}) {
  const [allConfigs] = await Promise.all([ApiClient.getExams()]);
  const el = document.getElementById('page-content');

  let filterText = '';
  let filterType = '';
  let filterStatus = '';

  function openExamForm(examId = null) {
    setExamFormId(examId);
    if (typeof loadPage === 'function') loadPage('exam-form');
    else if (typeof window.loadPage === 'function') window.loadPage('exam-form');
  }

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
  document.getElementById('add-exam-btn').addEventListener('click', () => openExamForm(null));

  window.editExam = (id) => openExamForm(id);

  window.toggleExam = async (id) => {
    try { await ApiClient.toggleExam(id); renderExams(ApiClient, { loadPage }); }
    catch (e) { modalService.toast(e.message, 'error'); }
  };

  window.deleteExam = async (id) => {
    const ok = await modalService.confirm('Delete this exam configuration?', { title: 'Delete Exam', confirmText: 'Delete', type: 'danger' });
    if (ok) {
      try { await ApiClient.deleteExam(id); renderExams(ApiClient, { loadPage }); }
      catch (e) { modalService.toast(e.message, 'error'); }
    }
  };
}
