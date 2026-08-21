/**
 * ATCModule.js — ATC Portal: three-tab view
 *   Tab 1: Question Banks assigned to this ATC (clickable → view questions)
 *   Tab 2: My Students + assign/edit/history + bulk assign
 *   Tab 3: Live Monitoring (centre-scoped)
 *
 * Backend scoping is enforced server-side. This module respects it.
 * Version: v3
 */
import modalService from '../services/ModalService.js';

function getOverlay() {
  let ov = document.getElementById('modal-overlay');
  if (!ov) {
    ov = document.createElement('div');
    ov.id = 'modal-overlay';
    ov.style.cssText = 'display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;overflow-y:auto;padding:1rem';
    ov.innerHTML = '<div id="modal-box" style="margin:auto"></div>';
    document.body.appendChild(ov);
    ov.addEventListener('click', e => { if (e.target === ov) window.closeModal(); });
  }
  return ov;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  MAIN RENDER
// ═══════════════════════════════════════════════════════════════════════════════
export async function renderATC(ApiClient, ctx) {
  const { currentUser, getScopedLive } = ctx;
  const el = document.getElementById('page-content');
  if (!el) return;

  el.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:200px"><div class="loader"></div></div>';

  let banks = [], students = [], availableExams = [], resultsData = {};
  try {
    [banks, students, availableExams, resultsData] = await Promise.all([
      ApiClient.getQuestionBanks(),
      ApiClient.getAssignedStudents(),
      ApiClient.getAssignableExams(),
      ApiClient.getResults(),
    ]);
  } catch (e) {
    el.innerHTML = `<div class="card" style="text-align:center;padding:3rem;color:var(--danger)">Failed to load: ${e.message}</div>`;
    return;
  }

  // Store for refresh
  window._atcCurrentUser = currentUser;
  window._atcCtx = ctx;

  const centreName = currentUser.centre_id || 'Your Centre';

  el.innerHTML = `
    <div class="page-header" style="margin-bottom:1.25rem">
      <div>
        <h2 style="margin:0">ATC Dashboard</h2>
        <p style="margin:0.25rem 0 0;color:var(--text-muted);font-size:0.875rem">
          📍 ${centreName} &nbsp;·&nbsp; ${students.length} students &nbsp;·&nbsp; ${banks.length} question bank(s) assigned
        </p>
      </div>
    </div>

    <!-- Tabs -->
    <div style="display:flex;gap:0;border-bottom:2px solid var(--gray-200);margin-bottom:1.5rem">
      <button id="atc-tab-qb" class="atc-tab"
        style="padding:0.625rem 1.25rem;border:none;background:none;font-weight:600;font-size:0.9rem;cursor:pointer;border-bottom:2px solid #2563eb;margin-bottom:-2px;color:#2563eb">
        📚 Question Banks <span class="badge badge-primary" style="margin-left:0.25rem">${banks.length}</span>
      </button>
      <button id="atc-tab-students" class="atc-tab"
        style="padding:0.625rem 1.25rem;border:none;background:none;font-weight:600;font-size:0.9rem;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--text-muted)">
        👥 My Students <span class="badge badge-gray" style="margin-left:0.25rem">${students.length}</span>
      </button>
      <button id="atc-tab-live" class="atc-tab"
        style="padding:0.625rem 1.25rem;border:none;background:none;font-weight:600;font-size:0.9rem;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--text-muted)">
        🔴 Live Monitoring
      </button>
      <button id="atc-tab-results" class="atc-tab"
        style="padding:0.625rem 1.25rem;border:none;background:none;font-weight:600;font-size:0.9rem;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--text-muted)">
        📊 Results
      </button>
    </div>

    <!-- Tab Panels -->
    <div id="atc-panel-qb">${renderQBPanel(banks)}</div>
    <div id="atc-panel-students" style="display:none">${renderStudentsPanel(students, availableExams)}</div>
    <div id="atc-panel-live" style="display:none"><div style="display:flex;align-items:center;justify-content:center;height:120px"><div class="loader"></div></div></div>
    <div id="atc-panel-results" style="display:none">${renderResultsPanel(resultsData)}</div>
  `;

  // ── Tab switching ──────────────────────────────────────
  let _liveTimer = null;
  const tabs = ['qb', 'students', 'live', 'results'];

  window.switchATCTab = (tab) => {
    tabs.forEach(t => {
      document.getElementById(`atc-panel-${t}`).style.display = t === tab ? '' : 'none';
      const btn = document.getElementById(`atc-tab-${t}`);
      btn.style.borderBottomColor = t === tab ? '#2563eb' : 'transparent';
      btn.style.color = t === tab ? '#2563eb' : 'var(--text-muted)';
    });

    // Start / stop live monitoring
    if (tab === 'live') {
      startLiveRefresh();
    } else {
      stopLiveRefresh();
    }
  };

  tabs.forEach(t => {
    document.getElementById(`atc-tab-${t}`).addEventListener('click', () => window.switchATCTab(t));
  });

  // ── Student search ──────────────────────────────────────
  const searchEl = document.getElementById('atc-student-search');
  if (searchEl) searchEl.addEventListener('input', () => filterStudentRows(searchEl.value));

  // ── Bulk select ─────────────────────────────────────────
  window.atcToggleAll = (master) => {
    document.querySelectorAll('.atc-stu-select').forEach(cb => cb.checked = master.checked);
    atcUpdateBulkBar();
  };
  window.atcUpdateBulkBar = () => {
    const count = document.querySelectorAll('.atc-stu-select:checked').length;
    const bar = document.getElementById('atc-bulk-bar');
    const ct = document.getElementById('atc-bulk-count');
    if (bar) bar.style.display = count > 0 ? 'flex' : 'none';
    if (ct) ct.textContent = count;
    const master = document.getElementById('atc-select-all');
    if (master && count === 0) master.checked = false;
  };

  // ── Window functions ──────────────────────────────────────
  window.atcViewQB = (bankId) => viewQBQuestions(ApiClient, banks.find(b => b.id == bankId));
  window.showAssignModal = (studentId, studentName) => showAssignModal(ApiClient, studentId, studentName, availableExams, students);
  window.unassignStudentExam = (studentId, examId, examTitle) => unassignExam(ApiClient, studentId, examId, examTitle);
  window.atcEditStudent = (id) => editStudentModal(ApiClient, students.find(s => s.id == id));
  window.atcViewHistory = (id) => viewHistoryModal(ApiClient, students.find(s => s.id == id));
  window.atcShowBulkAssign = () => showBulkAssignModal(ApiClient, availableExams);
  window.atcExportResults = async () => {
    try {
      const csv = await ApiClient.exportResults();
      const blob = new Blob([csv], { type: 'text/csv' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a'); a.href = url;
      a.download = `results_${currentUser.centre_id}_${new Date().toISOString().split('T')[0]}.csv`;
      a.click();
    } catch (e) { modalService.toast('Export failed: ' + e.message, 'error'); }
  };

  // ── Live monitoring helpers ─────────────────────────────
  let _liveRunning = false;
  let _liveStopped = false;

  async function doLiveRefresh() {
    if (_liveStopped || _liveRunning) return;
    _liveRunning = true;
    try {
      const oneHourAgo = new Date(Date.now() - 3600 * 1000).toISOString();
      const [live, resultData] = await Promise.all([
        getScopedLive(),
        ApiClient.getResults({ since: oneHourAgo }),
      ]);
      const subs = resultData?.submissions || [];

      if (_liveStopped) return;

      const panel = document.getElementById('atc-panel-live');
      if (!panel) return;

      panel.innerHTML = `
        <div class="stats-grid" style="margin-bottom:1.25rem">
          <div class="stat-card stat-card-green"><div class="stat-label">Active Now</div><div class="stat-value">${live.length}</div></div>
          <div class="stat-card stat-card-blue"><div class="stat-label">Submissions (1h)</div><div class="stat-value">${subs.length}</div></div>
          <div class="stat-card stat-card-green"><div class="stat-label">Passed (1h)</div><div class="stat-value">${subs.filter(s => s.result === 'pass').length}</div></div>
          <div class="stat-card stat-card-red"><div class="stat-label">Failed (1h)</div><div class="stat-value">${subs.filter(s => s.result === 'fail').length}</div></div>
        </div>

        <div class="card" style="margin-bottom:1.25rem">
          <div class="card-header"><h3><span class="live-dot"></span> Currently Appearing</h3><span class="badge badge-green">${live.length} students</span></div>
          ${live.length === 0 ? '<div class="card-body" style="color:var(--text-muted);font-size:0.875rem">No active exam sessions at the moment.</div>' : `
          <div class="table-wrap"><table>
            <thead><tr><th>Student</th><th>Exam</th><th>Started</th><th>Last Seen</th><th>Duration</th></tr></thead>
            <tbody>${live.map(s => {
              const dur = Math.round((Date.now() - new Date(s.startedAt).getTime()) / 60000);
              return `<tr>
                <td style="font-weight:600">${s.studentName || '—'}</td>
                <td style="font-size:0.82rem;color:var(--text-muted)">${s.examTitle || s.examId || '—'}</td>
                <td style="font-size:0.8rem">${new Date(s.startedAt).toLocaleTimeString('en-IN')}</td>
                <td style="font-size:0.8rem">${new Date(s.lastSeen).toLocaleTimeString('en-IN')}</td>
                <td><span class="badge badge-blue">${dur} min</span></td>
              </tr>`;
            }).join('')}</tbody>
          </table></div>`}
        </div>

        <div class="card">
          <div class="card-header"><h3>Recent Submissions (1h)</h3></div>
          ${subs.length === 0 ? '<div class="card-body" style="color:var(--text-muted);font-size:0.875rem">No recent submissions in the last hour.</div>' : `
          <div class="table-wrap"><table>
            <thead><tr><th>Student</th><th>Exam</th><th>Score</th><th>Result</th><th>Submitted</th></tr></thead>
            <tbody>${subs.map(s => `<tr>
              <td style="font-weight:600">${s.student?.name || s.student_name || '—'}</td>
              <td style="font-size:0.82rem;color:var(--text-muted)">${s.exam?.title || s.exam_title || '—'}</td>
              <td><strong>${s.score ?? '—'}%</strong></td>
              <td><span class="badge ${s.result === 'pass' ? 'badge-green' : 'badge-red'}">${s.result || '—'}</span></td>
              <td style="font-size:0.78rem">${new Date(s.submitted_at).toLocaleTimeString('en-IN')}</td>
            </tr>`).join('')}</tbody>
          </table></div>`}
        </div>

        <p style="text-align:center;font-size:0.75rem;color:var(--text-muted);margin-top:1rem">Auto-refreshes every 10 seconds</p>
      `;
    } catch (e) {
      const panel = document.getElementById('atc-panel-live');
      if (panel) panel.innerHTML = `<div class="card" style="text-align:center;padding:2rem;color:var(--danger)">${e.message}</div>`;
    } finally {
      _liveRunning = false;
    }
    if (!_liveStopped) _liveTimer = setTimeout(doLiveRefresh, 10000);
  }

  function startLiveRefresh() {
    _liveStopped = false;
    doLiveRefresh();
  }
  function stopLiveRefresh() {
    _liveStopped = true;
    if (_liveTimer) { clearTimeout(_liveTimer); _liveTimer = null; }
  }

  // Return controller for page cleanup
  return { stop: stopLiveRefresh };
}

// ═══════════════════════════════════════════════════════════════════════════════
//  TAB 4: Results
// ═══════════════════════════════════════════════════════════════════════════════
function renderResultsPanel(data) {
  const subs = data?.submissions || [];
  const total  = subs.length;
  const passed = subs.filter(s => s.result === 'pass').length;
  const failed = total - passed;
  const avg    = total ? Math.round(subs.reduce((a, s) => a + (s.score || 0), 0) / total) : 0;
  const passRate = total ? Math.round(passed / total * 100) : 0;

  if (total === 0) {
    return `
      <div class="card" style="text-align:center;padding:3rem">
        <div style="font-size:3rem;margin-bottom:1rem">📊</div>
        <h3 style="color:var(--text-muted)">No Results Yet</h3>
        <p style="color:var(--text-muted);font-size:0.875rem">No students from your centre have submitted exams yet.</p>
      </div>`;
  }

  return `
    <div class="stats-grid" style="margin-bottom:1.25rem">
      <div class="stat-card stat-card-blue"><div class="stat-label">Total Submissions</div><div class="stat-value">${total}</div></div>
      <div class="stat-card stat-card-green"><div class="stat-label">Passed</div><div class="stat-value">${passed}</div><div class="stat-sub">${passRate}% pass rate</div></div>
      <div class="stat-card stat-card-red"><div class="stat-label">Failed</div><div class="stat-value">${failed}</div></div>
      <div class="stat-card stat-card-yellow"><div class="stat-label">Avg Score</div><div class="stat-value">${avg}%</div></div>
    </div>

    <div class="card" style="padding:0;overflow:hidden">
      <div style="padding:0.875rem 1.25rem;border-bottom:1px solid var(--gray-100);display:flex;align-items:center;justify-content:space-between">
        <h3 style="margin:0;font-size:1rem">All Submissions (${total})</h3>
        <button onclick="window.atcExportResults()" class="btn btn-outline btn-sm">Export CSV</button>
      </div>
      <div style="overflow-x:auto">
        <table class="data-table" style="min-width:650px">
          <thead>
            <tr><th>Student</th><th>Exam</th><th>Score</th><th>Correct</th><th>Result</th><th>Date</th></tr>
          </thead>
          <tbody>
            ${subs.map(s => `<tr>
              <td style="font-weight:600">${s.student?.name || s.student_name || '—'}</td>
              <td style="font-size:0.82rem;color:var(--text-muted)">${s.exam?.title || s.exam_title || '—'}</td>
              <td><strong>${s.score ?? '—'}%</strong></td>
              <td style="font-size:0.82rem">${s.correct_answers ?? '—'} / ${s.total_questions ?? '—'}</td>
              <td><span class="badge ${s.result === 'pass' ? 'badge-green' : 'badge-red'}">${s.result || '—'}</span></td>
              <td style="font-size:0.75rem;color:var(--text-muted)">${s.submitted_at ? new Date(s.submitted_at).toLocaleString('en-IN') : '—'}</td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>
    </div>`;
}

// ═══════════════════════════════════════════════════════════════════════════════
function renderQBPanel(banks) {
  if (banks.length === 0) {
    return `
      <div class="card" style="text-align:center;padding:3rem">
        <div style="font-size:3rem;margin-bottom:1rem">📭</div>
        <h3 style="color:var(--text-muted)">No Question Banks Assigned</h3>
        <p style="color:var(--text-muted);font-size:0.875rem">Contact the admin to assign question banks to your centre.</p>
      </div>`;
  }
  return `
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1.25rem">
      ${banks.map(b => renderQBCard(b)).join('')}
    </div>`;
}

function renderQBCard(b) {
  const qCount = b.questions_count ?? 0;
  return `
    <div class="card" style="padding:0;overflow:hidden;cursor:pointer;transition:transform 0.2s,box-shadow 0.2s"
         onclick="atcViewQB(${b.id})"
         onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 24px rgba(0,0,0,0.1)'"
         onmouseout="this.style.transform='';this.style.boxShadow=''">
      <div style="height:4px;background:linear-gradient(90deg,#4361ee,#7c3aed)"></div>
      <div style="padding:1.25rem">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.75rem;margin-bottom:0.75rem">
          <div>
            <div style="font-weight:700;font-size:1rem;color:var(--gray-900);line-height:1.3">${b.title}</div>
            <div style="font-size:0.8rem;color:var(--text-muted);margin-top:0.15rem">${b.subject}</div>
          </div>
          <div style="width:40px;height:40px;background:#eff6ff;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.25rem">📚</div>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between">
          <div style="display:flex;align-items:center;gap:0.5rem;font-size:0.8rem;color:var(--text-muted)">
            <strong style="color:var(--gray-800)">${qCount}</strong> questions &nbsp;·&nbsp; by ${b.created_by || 'Admin'}
          </div>
          <span style="font-size:0.75rem;color:#4361ee;font-weight:600">View →</span>
        </div>
      </div>
    </div>`;
}

// ── View QB Questions (full-page overlay) ───────────────────────────
async function viewQBQuestions(ApiClient, bank) {
  if (!bank) return;
  getOverlay().style.display = 'flex';
  const box = document.getElementById('modal-box');
  box.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:200px"><div class="loader"></div></div>';

  try {
    const questions = await ApiClient.getQuestionBankQuestions(bank.id);
    box.innerHTML = `
      <div class="modal-card" style="max-width:800px;width:95vw;padding:0;max-height:90vh;display:flex;flex-direction:column">
        <div class="modal-header" style="background:linear-gradient(135deg,#4361ee,#7c3aed);flex-shrink:0">
          <div style="display:flex;align-items:center;gap:0.75rem">
            <div style="width:36px;height:36px;background:rgba(255,255,255,0.15);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.1rem">📚</div>
            <div>
              <h3 class="modal-title" style="color:#fff;margin:0">${bank.title}</h3>
              <p style="color:rgba(255,255,255,0.75);margin:0;font-size:0.8rem">${bank.subject} · ${questions.length} question(s)</p>
            </div>
          </div>
          <button onclick="closeModal()" style="background:rgba(255,255,255,0.15);border:none;color:#fff;border-radius:6px;padding:0.3rem 0.65rem;cursor:pointer;font-size:1rem">×</button>
        </div>
        <div style="flex:1;overflow-y:auto;padding:1.25rem">
          ${questions.length === 0 ? '<p style="text-align:center;color:var(--text-muted);padding:2rem">No questions in this bank yet.</p>' : `
          <div style="display:flex;flex-direction:column;gap:1rem">
            ${questions.map((q, i) => {
              const opts = typeof q.options === 'string' ? JSON.parse(q.options) : q.options;
              return `
                <div style="background:var(--gray-50);border:1px solid var(--gray-200);border-radius:12px;padding:1rem 1.25rem">
                  <div style="display:flex;align-items:flex-start;gap:0.75rem;margin-bottom:0.625rem">
                    <span style="background:#4361ee;color:#fff;font-weight:700;font-size:0.75rem;min-width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0">${i + 1}</span>
                    <div style="font-weight:600;font-size:0.9rem;line-height:1.45;color:var(--gray-900)">${q.text}</div>
                  </div>
                  <div style="padding-left:2.5rem;display:flex;flex-direction:column;gap:0.35rem">
                    ${(opts || []).map(o => {
                      const isCorrect = o.id === q.correct_answer;
                      return `<div style="display:flex;align-items:center;gap:0.5rem;font-size:0.82rem;padding:0.3rem 0.5rem;border-radius:6px;${isCorrect ? 'background:#dcfce7;border:1px solid #86efac;font-weight:600;color:#166534' : 'color:var(--gray-600)'}">
                        <span style="font-weight:700;font-size:0.75rem;text-transform:uppercase;min-width:16px">${o.id}.</span>
                        ${o.text}
                        ${isCorrect ? '<span style="margin-left:auto;font-size:0.7rem">✅ Correct</span>' : ''}
                      </div>`;
                    }).join('')}
                  </div>
                </div>`;
            }).join('')}
          </div>`}
        </div>
        <div class="modal-actions" style="border-top:1px solid var(--gray-200);padding:0.75rem 1.25rem;flex-shrink:0">
          <button class="modal-btn modal-btn-confirm" onclick="closeModal()">Close</button>
        </div>
      </div>`;
  } catch (e) {
    box.innerHTML = `<div class="modal-card"><p style="color:var(--danger)">${e.message}</p><div class="modal-actions"><button class="modal-btn modal-btn-confirm" onclick="closeModal()">Close</button></div></div>`;
  }
}


// ═══════════════════════════════════════════════════════════════════════════════
//  TAB 2: Students (with checkboxes, edit, history, bulk assign)
// ═══════════════════════════════════════════════════════════════════════════════
function renderStudentsPanel(students, availableExams) {
  if (students.length === 0) {
    return `
      <div class="card" style="text-align:center;padding:3rem">
        <div style="font-size:3rem;margin-bottom:1rem">👥</div>
        <h3 style="color:var(--text-muted)">No Students Found</h3>
        <p style="color:var(--text-muted);font-size:0.875rem">No students are registered under your centre yet.</p>
      </div>`;
  }

  const rows = students.map(s => {
    const esc = s.name.replace(/'/g, "\\'");
    return `
      <tr data-search="${(s.identifier + ' ' + s.name).toLowerCase()}">
        <td><input type="checkbox" class="atc-stu-select" value="${s.id}" onchange="atcUpdateBulkBar()"></td>
        <td style="font-family:monospace;font-weight:600;font-size:0.85rem">${s.identifier}</td>
        <td style="font-weight:500">${s.name}</td>
        <td style="font-size:0.82rem;color:var(--text-muted)">${s.exam_slot || '—'}</td>
        <td>
          ${(s.assignments || []).length === 0
            ? '<span style="font-size:0.78rem;color:var(--text-muted)">None</span>'
            : `<div style="display:flex;flex-wrap:wrap;gap:0.3rem">
                ${(s.assignments || []).map(a => `
                  <span class="badge badge-primary" style="display:inline-flex;align-items:center;gap:0.25rem;font-size:0.7rem">
                    ${a.title}
                    <span style="opacity:0.7;font-size:0.6rem">(${a.remaining}/${a.max_attempts})</span>
                    <button onclick="event.stopPropagation();unassignStudentExam('${s.id}','${a.exam_id}','${a.title.replace(/'/g,"\\'")}')"
                      style="background:none;border:none;cursor:pointer;padding:0;line-height:1;color:inherit;opacity:0.75" title="Remove">✕</button>
                  </span>`).join('')}
              </div>`}
        </td>
        <td>
          <div style="display:flex;gap:0.375rem;flex-wrap:wrap">
            <button class="btn btn-primary btn-sm" onclick="showAssignModal('${s.id}','${esc}')">📋 Assign</button>
            <button class="btn btn-outline btn-sm" onclick="atcViewHistory('${s.id}')">History</button>
            <button class="btn btn-outline btn-sm" onclick="atcEditStudent('${s.id}')">Edit</button>
          </div>
        </td>
      </tr>`;
  }).join('');

  return `
    <!-- Bulk Action Bar -->
    <div id="atc-bulk-bar" class="card" style="display:none;margin-bottom:1rem;background:#2563eb;color:white;padding:0.75rem 1.25rem;align-items:center;justify-content:space-between;border:none;border-radius:12px;box-shadow:0 4px 12px rgba(37,99,235,0.2)">
      <div style="display:flex;align-items:center;gap:12px">
        <div style="background:rgba(255,255,255,0.2);width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.82rem;font-weight:700" id="atc-bulk-count">0</div>
        <div style="font-weight:600;font-size:0.9375rem">Students Selected</div>
      </div>
      <button class="btn" style="background:white;color:#2563eb;font-weight:700;border:none;font-size:0.875rem" onclick="atcShowBulkAssign()">📋 Bulk Assign Exam</button>
    </div>

    <div class="card" style="padding:0;overflow:hidden">
      <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--gray-100);display:flex;align-items:center;gap:0.75rem">
        <div style="flex:1;min-width:220px;position:relative">
          <input type="text" id="atc-student-search" class="form-input" placeholder="Search students…" style="padding-left:2.25rem">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            style="position:absolute;left:0.6rem;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--gray-400)">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
          </svg>
        </div>
        <span style="font-size:0.82rem;color:var(--text-muted);white-space:nowrap">${students.length} student(s)</span>
      </div>
      <div style="overflow-x:auto">
        <table class="data-table" style="min-width:750px">
          <thead>
            <tr>
              <th style="width:40px"><input type="checkbox" id="atc-select-all" onchange="atcToggleAll(this)"></th>
              <th>Student ID</th>
              <th>Name</th>
              <th>Slot</th>
              <th>Assigned Exams</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="atc-students-tbody">
            ${rows}
          </tbody>
        </table>
      </div>
    </div>`;
}

function filterStudentRows(query) {
  const q = query.toLowerCase();
  document.querySelectorAll('#atc-students-tbody tr').forEach(tr => {
    const text = (tr.getAttribute('data-search') || tr.textContent).toLowerCase();
    tr.style.display = text.includes(q) ? '' : 'none';
  });
}


// ═══════════════════════════════════════════════════════════════════════════════
//  MODALS
// ═══════════════════════════════════════════════════════════════════════════════

// ── Assign Exam Modal ──────────────────────────────────────────────
async function showAssignModal(ApiClient, studentId, studentName, availableExams, students) {
  const student = students.find(s => s.id == studentId);
  const alreadyAssignedIds = (student?.assignments || []).map(a => String(a.exam_id));

  const examOptions = availableExams.length === 0
    ? '<option value="">No exams available for your centre</option>'
    : availableExams
        .map(e => {
          const already = alreadyAssignedIds.includes(String(e.id));
          return `<option value="${e.id}" ${already ? 'disabled style="color:#94a3b8"' : ''}>${e.title} — ${e.subject} (${e.total_questions}Q, ${e.duration}min)${already ? ' ✓ Already assigned' : ''}</option>`;
        })
        .join('');

  getOverlay().style.display = 'flex';
  document.getElementById('modal-box').innerHTML = `
    <div class="modal-card" style="max-width:480px;width:95vw;padding:0">
      <div class="modal-header" style="background:linear-gradient(135deg,#4361ee,#3730a3)">
        <div style="display:flex;align-items:center;gap:0.75rem">
          <div style="width:36px;height:36px;background:rgba(255,255,255,0.15);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.1rem">📋</div>
          <div>
            <h3 class="modal-title" style="color:#fff;margin:0">Assign Exam</h3>
            <p style="color:rgba(255,255,255,0.75);margin:0;font-size:0.8rem">${studentName}</p>
          </div>
        </div>
        <button onclick="closeModal()" style="background:rgba(255,255,255,0.15);border:none;color:#fff;border-radius:6px;padding:0.3rem 0.65rem;cursor:pointer;font-size:1rem">×</button>
      </div>
      <div style="padding:1.25rem;display:flex;flex-direction:column;gap:0.875rem">
        <div class="form-group">
          <label class="form-label">Select Exam *</label>
          <select id="assign-exam-select" class="form-select">
            <option value="">— Choose an exam —</option>
            ${examOptions}
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Max Attempts</label>
          <select id="assign-attempts-select" class="form-select">
            <option value="1">1 attempt</option>
            <option value="2">2 attempts</option>
            <option value="3">3 attempts</option>
          </select>
        </div>
        <div class="modal-actions" style="padding-top:0.5rem;margin-top:0.25rem;border-top:1px solid var(--gray-100)">
          <button class="modal-btn modal-btn-cancel" onclick="closeModal()">Cancel</button>
          <button class="modal-btn modal-btn-confirm" onclick="doATCAssignExam('${studentId}')">Assign</button>
        </div>
      </div>
    </div>`;

  window.doATCAssignExam = async (sid) => {
    const examId = document.getElementById('assign-exam-select').value;
    const maxAttempts = parseInt(document.getElementById('assign-attempts-select').value) || 1;
    if (!examId) { modalService.toast('Please select an exam', 'error'); return; }
    try {
      await ApiClient.assignExam(parseInt(sid), parseInt(examId), maxAttempts);
      window.closeModal();
      modalService.toast('Exam assigned successfully!', 'success');
      renderATC(ApiClient, window._atcCtx);
    } catch (e) {
      modalService.toast('Assignment failed: ' + e.message, 'error');
    }
  };
}

// ── Unassign ────────────────────────────────────────────────────────
async function unassignExam(ApiClient, studentId, examId, examTitle) {
  const ok = await modalService.confirm(
    `Remove <strong>${examTitle}</strong> from this student?`,
    { title: 'Remove Assignment', confirmText: 'Remove', type: 'danger' }
  );
  if (!ok) return;
  try {
    await ApiClient.unassignExam(studentId, examId);
    modalService.toast('Assignment removed.', 'success');
    renderATC(ApiClient, window._atcCtx);
  } catch (e) {
    modalService.toast('Failed: ' + e.message, 'error');
  }
}

// ── Bulk Assign Modal ───────────────────────────────────────────────
async function showBulkAssignModal(ApiClient, availableExams) {
  const selectedIds = [...document.querySelectorAll('.atc-stu-select:checked')].map(cb => cb.value);
  if (selectedIds.length === 0) { modalService.toast('No students selected', 'error'); return; }

  const examOpts = availableExams.map(e => `<option value="${e.id}">${e.title} — ${e.subject} (${e.total_questions}Q, ${e.duration}min)</option>`).join('');

  getOverlay().style.display = 'flex';
  document.getElementById('modal-box').innerHTML = `
    <div class="modal-card" style="max-width:500px;width:95vw;padding:0">
      <div class="modal-header" style="background:linear-gradient(135deg,#4361ee,#3730a3)">
        <div style="display:flex;align-items:center;gap:0.75rem">
          <div style="width:36px;height:36px;background:rgba(255,255,255,0.15);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.1rem">📦</div>
          <div>
            <h3 class="modal-title" style="color:#fff;margin:0">Bulk Assign Exam</h3>
            <p style="color:rgba(255,255,255,0.75);margin:0;font-size:0.8rem">${selectedIds.length} student(s) selected</p>
          </div>
        </div>
        <button onclick="closeModal()" style="background:rgba(255,255,255,0.15);border:none;color:#fff;border-radius:6px;padding:0.3rem 0.65rem;cursor:pointer;font-size:1rem">×</button>
      </div>
      <div style="padding:1.25rem;display:flex;flex-direction:column;gap:0.875rem">
        <div class="form-group">
          <label class="form-label">Select Exam to Assign *</label>
          <select id="atc-bulk-exam" class="form-select">${examOpts}</select>
        </div>
        <div class="form-group">
          <label class="form-label">Max Attempts</label>
          <select id="atc-bulk-attempts" class="form-select">
            <option value="1">1 attempt</option>
            <option value="2">2 attempts</option>
            <option value="3">3 attempts</option>
          </select>
        </div>
        <div class="modal-actions" style="padding-top:0.5rem;border-top:1px solid var(--gray-100)">
          <button class="modal-btn modal-btn-cancel" onclick="closeModal()">Cancel</button>
          <button class="modal-btn modal-btn-confirm" id="atc-bulk-confirm" onclick="doATCBulkAssign()">Confirm & Assign</button>
        </div>
      </div>
    </div>`;

  window.doATCBulkAssign = async () => {
    const examId = document.getElementById('atc-bulk-exam').value;
    const maxAttempts = parseInt(document.getElementById('atc-bulk-attempts').value) || 1;
    const btn = document.getElementById('atc-bulk-confirm');
    if (!examId) return;
    try {
      btn.disabled = true; btn.textContent = 'Assigning…';
      const res = await ApiClient.bulkAssignExam(selectedIds, examId, maxAttempts);
      modalService.toast(res.message || `Assigned to ${selectedIds.length} students`, 'success');
      window.closeModal();
      renderATC(ApiClient, window._atcCtx);
    } catch (e) {
      modalService.toast('Bulk assignment failed: ' + e.message, 'error');
      btn.disabled = false; btn.textContent = 'Confirm & Assign';
    }
  };
}

// ── Edit Student Modal ──────────────────────────────────────────────
async function editStudentModal(ApiClient, student) {
  if (!student) return;
  getOverlay().style.display = 'flex';
  document.getElementById('modal-box').innerHTML = `
    <div class="modal-card" style="max-width:480px;width:95vw;padding:0">
      <div class="modal-header" style="background:linear-gradient(135deg,#f59e0b,#d97706)">
        <div style="display:flex;align-items:center;gap:0.75rem">
          <div style="width:36px;height:36px;background:rgba(255,255,255,0.15);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.1rem">✏️</div>
          <h3 class="modal-title" style="color:#fff;margin:0">Edit Student</h3>
        </div>
        <button onclick="closeModal()" style="background:rgba(255,255,255,0.15);border:none;color:#fff;border-radius:6px;padding:0.3rem 0.65rem;cursor:pointer;font-size:1rem">×</button>
      </div>
      <div style="padding:1.25rem;display:flex;flex-direction:column;gap:0.875rem">
        <div class="form-group">
          <label class="form-label">Student ID</label>
          <input id="atc-edit-identifier" class="form-input" value="${student.identifier}" readonly style="background:var(--gray-100)">
        </div>
        <div class="form-group">
          <label class="form-label">Name *</label>
          <input id="atc-edit-name" class="form-input" value="${student.name}">
        </div>
        <div class="form-group">
          <label class="form-label">Exam Slot</label>
          <input id="atc-edit-slot" class="form-input" value="${student.exam_slot || ''}" placeholder="e.g. Morning, Afternoon">
        </div>
        <div class="form-group">
          <label class="form-label">New Password <span style="font-weight:400;color:var(--text-muted)">(leave blank to keep current)</span></label>
          <input id="atc-edit-password" class="form-input" type="password" placeholder="••••••••">
        </div>
        <div class="modal-actions" style="padding-top:0.5rem;border-top:1px solid var(--gray-100)">
          <button class="modal-btn modal-btn-cancel" onclick="closeModal()">Cancel</button>
          <button class="modal-btn modal-btn-confirm" onclick="doATCSaveStudent('${student.id}')">Save Changes</button>
        </div>
      </div>
    </div>`;

  window.doATCSaveStudent = async (id) => {
    const name = document.getElementById('atc-edit-name').value.trim();
    const exam_slot = document.getElementById('atc-edit-slot').value.trim();
    const password = document.getElementById('atc-edit-password').value;
    if (!name) { modalService.toast('Name is required', 'error'); return; }
    const payload = { name, exam_slot };
    if (password) payload.password = password;
    try {
      await ApiClient.updateStudent(id, payload);
      window.closeModal();
      modalService.toast('Student updated!', 'success');
      renderATC(ApiClient, window._atcCtx);
    } catch (e) { modalService.toast(e.message, 'error'); }
  };
}

// ── Student History Modal ───────────────────────────────────────────
async function viewHistoryModal(ApiClient, student) {
  if (!student) return;
  getOverlay().style.display = 'flex';
  const box = document.getElementById('modal-box');
  box.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:150px"><div class="loader"></div></div>';

  try {
    const history = await ApiClient.getStudentHistory(student.id);
    box.innerHTML = `
      <div class="modal-card" style="max-width:650px;width:95vw;padding:0;max-height:80vh;display:flex;flex-direction:column">
        <div class="modal-header" style="background:linear-gradient(135deg,#4361ee,#3730a3);flex-shrink:0">
          <div style="display:flex;align-items:center;gap:0.75rem">
            <div style="width:36px;height:36px;background:rgba(255,255,255,0.15);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.1rem">📊</div>
            <div>
              <h3 class="modal-title" style="color:#fff;margin:0">Exam History</h3>
              <p style="color:rgba(255,255,255,0.75);margin:0;font-size:0.8rem">${student.name} · ${student.identifier}</p>
            </div>
          </div>
          <button onclick="closeModal()" style="background:rgba(255,255,255,0.15);border:none;color:#fff;border-radius:6px;padding:0.3rem 0.65rem;cursor:pointer;font-size:1rem">×</button>
        </div>
        <div style="flex:1;overflow-y:auto;padding:1.25rem">
          ${history.length === 0 ? '<p style="text-align:center;color:var(--text-muted);padding:2rem">No exam attempts on record yet.</p>' : `
          <table class="data-table" style="width:100%">
            <thead><tr><th>Exam</th><th>Score</th><th>Result</th><th>Date</th></tr></thead>
            <tbody>${history.map(s => `<tr>
              <td style="font-weight:500">${s.exam_title || s.exam_id || '—'}</td>
              <td><strong>${s.score ?? '—'}%</strong></td>
              <td><span class="badge ${s.result === 'pass' ? 'badge-green' : 'badge-red'}">${s.result || '—'}</span></td>
              <td style="font-size:0.78rem;color:var(--text-muted)">${s.submitted_at ? new Date(s.submitted_at).toLocaleString() : '—'}</td>
            </tr>`).join('')}</tbody>
          </table>`}
        </div>
        <div class="modal-actions" style="border-top:1px solid var(--gray-200);padding:0.75rem 1.25rem;flex-shrink:0">
          <button class="modal-btn modal-btn-confirm" onclick="closeModal()">Close</button>
        </div>
      </div>`;
  } catch (e) {
    box.innerHTML = `<div class="modal-card"><p style="color:var(--danger)">${e.message}</p><div class="modal-actions"><button class="modal-btn modal-btn-confirm" onclick="closeModal()">Close</button></div></div>`;
  }
}
