/**
 * QuestionsModule.js — Renders the Question Banks page.
 * Courses come from the main Gyanam India portal (via /portal-courses API).
 * ATC Centres (with types) come from /portal-atc-centres API.
 *
 * "Show" button navigates to a dedicated full-page view for the bank's questions.
 */
import modalService from '../services/ModalService.js';
import { setAssignBankId } from './AssignBankModule.js';

// Cache for portal data (fetched once per session)
let _coursesCache    = null;
let _coursesSyncedAt = null;
let _atcDataCache    = null; // { centres: [{code,name,centre_type,...}], types: [...] }

async function getPortalCourses(ApiClient, { force = false } = {}) {
  if (!force && _coursesCache) return _coursesCache;
  try {
    const res = await ApiClient.getPortalCourses({ fresh: !!force });
    const list = Array.isArray(res.courses) ? res.courses : [];
    _coursesSyncedAt = res.synced_at || null;
    // Exam portal: Active IT only (API already filters; keep a client safeguard)
    _coursesCache = list
      .filter(c => {
        const type = String(c.course_type || '').trim().toUpperCase();
        const status = String(c.status || 'Active').trim().toLowerCase();
        return type === 'IT' && status !== 'inactive';
      })
      .sort((a, b) => String(a.course_name || '').localeCompare(String(b.course_name || '')));
  } catch (e) {
    _coursesCache = [];
    _coursesSyncedAt = null;
  }
  return _coursesCache;
}

function courseSubjectFieldHtml(courses, selectedValue = '', inputId = 'nb-subject') {
  const selected = String(selectedValue || '');
  const options = (courses || []).map(c => {
    const val = String(c.course_name || '');
    const sel = val === selected ? ' selected' : '';
    const dur = c.duration ? ` · ${String(c.duration).replace(/</g, '&lt;')}` : '';
    return `<option value="${val.replace(/"/g, '&quot;')}"${sel}>${val.replace(/</g, '&lt;')}${dur}</option>`;
  }).join('');
  const syncHint = _coursesSyncedAt
    ? ` Last sync: ${String(_coursesSyncedAt).replace('T', ' ').slice(0, 19)}.`
    : '';
  const selectedAttr = selected.replace(/"/g, '&quot;');
  // Keep selected value even if not in synced list (legacy banks)
  const orphan = selected && !(courses || []).some(c => String(c.course_name || '') === selected)
    ? `<option value="${selectedAttr}" selected>${selected.replace(/</g, '&lt;')} (current)</option>`
    : '';
  return `
    <input type="search" id="${inputId}-filter" class="form-input" autocomplete="off"
      placeholder="Filter IT courses…" style="margin-bottom:0.4rem">
    <select id="${inputId}" class="form-input" style="width:100%;max-width:100%">
      <option value="">— Select an Active IT course —</option>
      ${orphan}
      ${options}
    </select>
    <p style="font-size:0.75rem;color:var(--text-muted);margin-top:0.35rem">
      ${(courses || []).length} Active IT course(s) from main portal.${syncHint}
      <button type="button" id="nb-refresh-courses" class="btn btn-ghost btn-sm" style="padding:0 0.35rem;font-size:0.75rem">Refresh list</button>
    </p>`;
}

async function getPortalATCData(ApiClient) {
  if (_atcDataCache) return _atcDataCache;
  try {
    const res = await ApiClient.getPortalATCCentres();
    _atcDataCache = { centres: res.centres || [], types: res.types || [] };
  } catch (e) { _atcDataCache = { centres: [], types: [] }; }
  return _atcDataCache;
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

// ════════════════════════════════════════════════════════════════════════════════
// MAIN: Question Banks list page
// ════════════════════════════════════════════════════════════════════════════════
export async function renderQuestions(ApiClient, { currentUser, loadPage }) {
  const el = document.getElementById('page-content');

  el.innerHTML = `
  <div class="page-header"><div><h2>Question Banks</h2><p>Loading...</p></div></div>
  <div style="text-align:center;padding:3rem;color:var(--text-muted)">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:32px;height:32px;margin:0 auto 1rem;display:block;animation:spin 1s linear infinite">
      <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>
    </svg>
    Loading question banks and portal data...
  </div>`;

  const [allBanks, courses, atcData] = await Promise.all([
    ApiClient.getQuestionBanks(),
    getPortalCourses(ApiClient, { force: true }),
    getPortalATCData(ApiClient),
  ]);
  const centresList = atcData.centres;

  let filterText = '';
  let filterCentre = '';

  function renderList() {
    const filtered = allBanks.filter(b => {
      const matchesText = !filterText ||
        b.title.toLowerCase().includes(filterText.toLowerCase()) ||
        b.subject.toLowerCase().includes(filterText.toLowerCase());
      const matchesCentre = !filterCentre ||
        (Array.isArray(b.assigned_to) && b.assigned_to.includes(filterCentre)) ||
        (b.centre_id === filterCentre);
      return matchesText && matchesCentre;
    });

    const listDiv = document.getElementById('banks-list');
    if (!listDiv) return;

    listDiv.innerHTML = filtered.length === 0
      ? '<div class="empty-state"><h3>No matching question banks</h3><p>Try adjusting your filters or create a new bank.</p></div>'
      : filtered.map(bank => {
        const assignedLabel = currentUser.role === 'admin'
          ? (Array.isArray(bank.assigned_to) && bank.assigned_to.length
            ? '<span class="badge badge-blue" style="font-size:0.7rem">' + bank.assigned_to.join(', ') + '</span>'
            : '<span class="badge badge-gray" style="font-size:0.7rem">Unassigned</span>')
          : '';
        const isOwned = bank.created_by_user_id === currentUser.id;
        const ownerBadge = isOwned
          ? '<span class="badge badge-green" style="font-size:0.7rem">Mine</span>'
          : '<span class="badge badge-gray" style="font-size:0.7rem">Assigned</span>';
        return `
          <div class="card" style="margin-bottom:1rem">
            <div style="padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem">
              <div style="flex:1;min-width:200px">
                <div style="font-weight:700;font-size:0.95rem;display:flex;align-items:center;gap:0.5rem">${bank.title} ${currentUser.role === 'admin' ? '' : ownerBadge}</div>
                <div style="font-size:0.78rem;color:var(--text-muted);margin-top:0.2rem">${bank.questions_count} questions · ${bank.subject} · by ${bank.creator_name || 'Admin'} ${assignedLabel}</div>
              </div>
              <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
                <button class="btn btn-outline btn-sm" onclick="viewBankQuestions('${bank.id}')">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                  View
                </button>
                <button class="btn btn-outline btn-sm" onclick="addQuestion('${bank.id}')">+ Add Q</button>
                <button class="btn btn-outline btn-sm" onclick="editBank('${bank.id}')">Edit</button>
                ${currentUser.role === 'admin' ? '<button class="btn btn-outline btn-sm" onclick="openAssignBankPage(\'' + bank.id + '\')">🔗 Assign</button>' : ''}
                ${(currentUser.role === 'admin' || isOwned) ? '<button class="btn btn-danger btn-sm" onclick="deleteBank(\'' + bank.id + '\')">Delete</button>' : ''}
              </div>
            </div>
          </div>`;
      }).join('');
  }

  const scopeNote = currentUser.centre_id ? ' · <span style="color:var(--text-muted);font-size:0.78rem">' + currentUser.centre_id + ' only</span>' : '';
  const centreOptions = centresList.length > 0
    ? centresList.map(c => {
        const typeLabel = c.centre_type ? ' · ' + c.centre_type : '';
        return '<option value="' + c.code + '">' + c.name + ' (' + c.code + ')' + typeLabel + '</option>';
      }).join('')
    : '<option value="" disabled>No centres synced yet</option>';

  el.innerHTML = `
  <div class="page-header">
    <div><h2>Question Banks${scopeNote}</h2><p>${allBanks.length} bank(s) total</p></div>
    <div style="display:flex;gap:0.5rem">
      <button id="import-qs-btn" class="btn btn-outline">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m6.75 12L11.25 21m0 0l-3.75-3.75M11.25 21V9.75"/></svg>
        Import CSV
      </button>
      <button id="add-bank-btn" class="btn btn-primary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        New Bank
      </button>
    </div>
  </div>

  <div class="card" style="margin-bottom:1.5rem; padding:1rem; display:flex; gap:1rem; flex-wrap:wrap; align-items:center; background: var(--gray-50)">
    <div style="flex:1; min-width:240px; position:relative">
      <input type="text" id="bank-search" class="form-input" placeholder="Search by title or course..." style="padding-left:2.5rem">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute; left:0.75rem; top:50%; transform:translateY(-50%); width:18px; height:18px; color:var(--gray-400)">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
      </svg>
    </div>
    ${!currentUser.centre_id ? `
    <div style="width:220px">
      <select id="bank-centre-filter" class="form-select">
        <option value="">All Centres</option>
        ${centreOptions}
      </select>
    </div>` : ''}
  </div>

  <div id="banks-list"></div>`;

  renderList();

  document.getElementById('bank-search').addEventListener('input', e => { filterText = e.target.value; renderList(); });
  if (!currentUser.centre_id) {
    document.getElementById('bank-centre-filter').addEventListener('change', e => { filterCentre = e.target.value; renderList(); });
  }

  document.getElementById('add-bank-btn').addEventListener('click', () => showNewBankModal(ApiClient, currentUser, null, courses));
  document.getElementById('import-qs-btn').addEventListener('click', () => showImportQuestionsModal(ApiClient));

  window.editBank = async (bankId) => {
    try {
      const banks = await ApiClient.getQuestionBanks();
      showNewBankModal(ApiClient, currentUser, banks.find(b => b.id == bankId), courses);
    } catch (e) { modalService.toast(e.message, 'error'); }
  };

  // ── View bank questions → FULL PAGE ──────────────────────────────────────
  window.viewBankQuestions = async (bankId) => {
    try {
      const banks = await ApiClient.getQuestionBanks();
      const bank = banks.find(b => b.id == bankId);
      if (!bank) return;
      renderBankDetailPage(el, ApiClient, bank, currentUser, courses);
    } catch (e) { modalService.toast(e.message, 'error'); }
  };

  // ── Assign Bank (dedicated page) ─────────────────────────────────────────
  window.openAssignBankPage = (bankId) => {
    setAssignBankId(bankId);
    if (typeof loadPage === 'function') loadPage('assign-bank');
    else if (typeof window.loadPage === 'function') window.loadPage('assign-bank');
  };

  window.deleteBank = async (bankId) => {
    const ok = await modalService.confirm('Delete this entire question bank? This cannot be undone.', { title: 'Delete Bank', confirmText: 'Delete', type: 'danger' });
    if (!ok) return;
    try { await ApiClient.deleteQuestionBank(bankId); renderQuestions(ApiClient, { currentUser }); }
    catch (e) { modalService.toast(e.message, 'error'); }
  };

  window.addQuestion = async (bankId) => {
    const banks = await ApiClient.getQuestionBanks();
    showQuestionModal(ApiClient, bankId, null, banks.find(b => b.id == bankId), currentUser);
  };

  window.editQuestion = async (bankId, qId) => {
    const banks = await ApiClient.getQuestionBanks();
    const bank = banks.find(b => b.id == bankId);
    showQuestionModal(ApiClient, bankId, bank?.questions?.find(q => q.id == qId), bank, currentUser);
  };

  window.deleteQuestion = async (bankId, qId) => {
    const ok = await modalService.confirm('Remove this question?', { title: 'Remove Question', confirmText: 'Remove', type: 'danger' });
    if (!ok) return;
    try { await ApiClient.deleteQuestion(bankId, qId); renderQuestions(ApiClient, { currentUser }); }
    catch (e) { modalService.toast('Failed to delete question: ' + e.message, 'error'); }
  };
}


// ════════════════════════════════════════════════════════════════════════════════
// BANK DETAIL PAGE (full page — replaces page-content)
// ════════════════════════════════════════════════════════════════════════════════
async function renderBankDetailPage(el, ApiClient, bank, currentUser, courses) {
  el.innerHTML = '<div style="text-align:center;padding:3rem;color:var(--text-muted)">Loading questions...</div>';

  let questions = [];
  try {
    questions = await ApiClient.getQuestionBankQuestions(bank.id);
  } catch (e) {
    el.innerHTML = '<div style="padding:2rem;text-align:center;color:var(--error)">Failed to load questions.</div>';
    return;
  }

  const assignedLabel = Array.isArray(bank.assigned_to) && bank.assigned_to.length
    ? bank.assigned_to.join(', ')
    : 'Unassigned';

  el.innerHTML = `
  <div style="margin-bottom:1.25rem">
    <button id="back-to-banks" class="btn btn-outline btn-sm" style="margin-bottom:1rem">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
      Back to Question Banks
    </button>
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem">
      <div>
        <h2 style="font-size:1.25rem;font-weight:800;margin-bottom:0.25rem">${bank.title}</h2>
        <p style="font-size:0.85rem;color:var(--text-muted)">${bank.subject} · ${questions.length} questions · by ${bank.creator_name || 'Admin'} · Assigned: <span class="badge badge-blue" style="font-size:0.7rem">${assignedLabel}</span></p>
      </div>
      <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
        ${currentUser.role === 'admin' ? `<button class="btn btn-outline btn-sm" onclick="openAssignBankPage('${bank.id}')">🔗 Assign Centres</button>` : ''}
        <button class="btn btn-primary btn-sm" onclick="addQuestion('${bank.id}')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Add Question
        </button>
      </div>
    </div>
  </div>

  ${questions.length === 0
    ? '<div class="card" style="padding:3rem;text-align:center;color:var(--text-muted)"><h3>No questions yet</h3><p>Click "Add Question" to add your first question to this bank.</p></div>'
    : `<div class="card">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th style="width:50px">#</th>
                <th>Question</th>
                <th>Options</th>
                <th style="width:100px">Answer</th>
                <th style="width:130px">Actions</th>
              </tr>
            </thead>
            <tbody>
              ${questions.map((q, i) => `
              <tr>
                <td style="font-weight:600;color:var(--text-muted)">${i + 1}</td>
                <td style="max-width:300px;line-height:1.5">${q.text}</td>
                <td style="font-size:0.8rem;color:var(--text-muted);max-width:240px">
                  ${q.options.map(o => '<span style="display:inline-block;background:var(--gray-50);border:1px solid var(--gray-200);padding:0.15rem 0.4rem;border-radius:4px;margin:0.1rem">' + o.id.toUpperCase() + ': ' + o.text + '</span>').join(' ')}
                </td>
                <td><span class="badge badge-green">${q.options.find(o => o.id === q.correct_answer)?.text || q.correct_answer}</span></td>
                <td>
                  <div style="display:flex;gap:0.375rem">
                    <button class="btn btn-outline btn-sm" onclick="editQuestion('${bank.id}','${q.id}')">Edit</button>
                    <button class="btn btn-danger btn-sm" onclick="deleteQuestion('${bank.id}','${q.id}')">Remove</button>
                  </div>
                </td>
              </tr>`).join('')}
            </tbody>
          </table>
        </div>
      </div>`
  }`;

  document.getElementById('back-to-banks').addEventListener('click', () => {
    renderQuestions(ApiClient, { currentUser });
  });

  // Override addQuestion for this page context — after save, reload this detail page
  window.addQuestion = async (bankId) => {
    const banks = await ApiClient.getQuestionBanks();
    const bk = banks.find(b => b.id == bankId);
    showQuestionModal(ApiClient, bankId, null, bk, currentUser, () => {
      renderBankDetailPage(el, ApiClient, bk, currentUser, courses);
    });
  };

  window.editQuestion = async (bankId, qId) => {
    const banks = await ApiClient.getQuestionBanks();
    const bk = banks.find(b => b.id == bankId);
    const qs = await ApiClient.getQuestionBankQuestions(bankId);
    showQuestionModal(ApiClient, bankId, qs.find(q => q.id == qId), bk, currentUser, () => {
      renderBankDetailPage(el, ApiClient, bk, currentUser, courses);
    });
  };

  window.deleteQuestion = async (bankId, qId) => {
    const ok = await modalService.confirm('Remove this question?', { title: 'Remove Question', confirmText: 'Remove', type: 'danger' });
    if (!ok) return;
    try {
      await ApiClient.deleteQuestion(bankId, qId);
      const banks = await ApiClient.getQuestionBanks();
      const bk = banks.find(b => b.id == bankId);
      renderBankDetailPage(el, ApiClient, bk, currentUser, courses);
    } catch (e) { modalService.toast('Failed to delete question: ' + e.message, 'error'); }
  };
}


// ════════════════════════════════════════════════════════════════════════════════
// MODAL: New / Edit Question Bank
// ════════════════════════════════════════════════════════════════════════════════
function showNewBankModal(ApiClient, currentUser, bank = null, courses = []) {
  const courseDropdownHtml = courses.length > 0
    ? courseSubjectFieldHtml(courses, bank?.subject || '', 'nb-subject')
    : `
      <input id="nb-subject" class="form-input" placeholder="e.g. Abacus Level 1, DCA, Vedic Maths..." value="${bank?.subject || ''}">
      <p style="font-size:0.75rem;color:var(--text-muted);margin-top:0.25rem">No courses synced yet.
        <button type="button" id="nb-refresh-courses" class="btn btn-ghost btn-sm" style="padding:0 0.35rem;font-size:0.75rem">Retry sync</button>
        — open <strong>Gyanam India Admin › Courses</strong> and click <strong>Sync to Exam Portal</strong> (not just refresh here).
      </p>`;

  getOverlay().style.display = 'flex';
  document.getElementById('modal-box').innerHTML = `
    <div class="modal-card" style="max-width:580px;width:95vw;padding:0">
      <div class="modal-header" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);padding:1.25rem 1.5rem">
        <div style="display:flex;align-items:center;gap:0.75rem">
          <div style="width:36px;height:36px;background:rgba(255,255,255,0.15);border-radius:8px;display:flex;align-items:center;justify-content:center">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" style="width:18px;height:18px"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          </div>
          <h3 style="color:#fff;margin:0;font-size:1.05rem;font-weight:700">${bank ? 'Edit Question Bank' : 'New Question Bank'}</h3>
        </div>
        <button onclick="closeModal()" style="background:rgba(255,255,255,0.15);border:none;color:#fff;border-radius:6px;padding:0.35rem 0.7rem;cursor:pointer;font-size:1.1rem;line-height:1">×</button>
      </div>
      <div style="padding:1.25rem 1.5rem">
        <p style="font-size:0.82rem;color:var(--text-muted);margin-bottom:1rem">Fill in details below. You can assign this bank to ATC centres after creation using the <strong>🔗 Assign</strong> button.</p>
        <div class="form-group">
          <label class="form-label">Bank Title *</label>
          <input id="nb-title" class="form-input" placeholder="e.g. Abacus Level 1 — Term 1 Exam" value="${bank?.title || ''}">
        </div>
        <div class="form-group">
          <label class="form-label">Course / Subject *</label>
          ${courseDropdownHtml}
        </div>
        <div style="display:flex;gap:0.75rem;justify-content:flex-end;padding-top:0.75rem;border-top:1px solid var(--gray-100);margin-top:0.5rem">
          <button class="modal-btn modal-btn-cancel" onclick="closeModal()">Cancel</button>
          <button class="modal-btn modal-btn-confirm" onclick="saveBank('${bank?.id || ''}')">${bank ? 'Save Changes' : 'Create Bank'}</button>
        </div>
      </div>
    </div>`;

  document.getElementById('nb-refresh-courses')?.addEventListener('click', async () => {
    const btn = document.getElementById('nb-refresh-courses');
    if (btn) { btn.disabled = true; btn.textContent = 'Refreshing…'; }
    try {
      const fresh = await getPortalCourses(ApiClient, { force: true });
      showNewBankModal(ApiClient, currentUser, bank, fresh);
      modalService.toast(fresh.length ? `${fresh.length} Active IT course(s) loaded` : 'Still no Active IT courses — sync from main portal Admin › Courses', fresh.length ? 'success' : 'error');
    } catch (e) {
      modalService.toast('Refresh failed: ' + e.message, 'error');
      if (btn) { btn.disabled = false; btn.textContent = 'Refresh list'; }
    }
  });

  const filterEl = document.getElementById('nb-subject-filter');
  const selectEl = document.getElementById('nb-subject');
  if (filterEl && selectEl) {
    filterEl.addEventListener('input', () => {
      const q = filterEl.value.trim().toLowerCase();
      Array.from(selectEl.options).forEach((opt, i) => {
        if (i === 0 && !opt.value) { opt.hidden = false; return; }
        const hay = String(opt.textContent || opt.value || '').toLowerCase();
        opt.hidden = q !== '' && !hay.includes(q);
      });
    });
  }

  window.saveBank = async (bankId) => {
    const title = document.getElementById('nb-title').value.trim();
    const subjectEl = document.getElementById('nb-subject');
    const subject = subjectEl ? subjectEl.value.trim() : '';
    if (!title) { modalService.toast('Bank Title is required', 'error'); return; }
    if (!subject) { modalService.toast('Please select a Course / Subject', 'error'); return; }
    try {
      if (bankId) {
        await ApiClient.updateQuestionBank(bankId, { title, subject });
        modalService.toast('Question bank updated!', 'success');
      } else {
        await ApiClient.createQuestionBank({ title, subject });
        modalService.toast('Question bank created! Use Assign to choose ATC centres.', 'success');
      }
      window.closeModal();
      _coursesCache = null;
      renderQuestions(ApiClient, { currentUser, loadPage: typeof loadPage === 'function' ? loadPage : window.loadPage });
    } catch (e) { modalService.toast('Failed to save bank: ' + e.message, 'error'); }
  };
}


// ════════════════════════════════════════════════════════════════════════════════
// MODAL: Add / Edit Question
// ════════════════════════════════════════════════════════════════════════════════
function showQuestionModal(ApiClient, bankId, question = null, bank = null, currentUser, onSaveCallback = null) {
  getOverlay().style.display = 'flex';
  document.getElementById('modal-box').innerHTML = `
    <div class="modal-card" style="max-width:620px;width:95vw;padding:0">
      <div class="modal-header" style="background:linear-gradient(135deg,#059669,#047857);padding:1.25rem 1.5rem">
        <div style="display:flex;align-items:center;gap:0.75rem">
          <div style="width:36px;height:36px;background:rgba(255,255,255,0.15);border-radius:8px;display:flex;align-items:center;justify-content:center">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" style="width:18px;height:18px"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          </div>
          <div>
            <h3 style="color:#fff;margin:0;font-size:1.05rem;font-weight:700">${question ? 'Edit Question' : 'Add Question'}</h3>
            <p style="color:rgba(255,255,255,0.7);margin:0;font-size:0.78rem">${bank?.title || ''} · ${bank?.subject || ''}</p>
          </div>
        </div>
        <button onclick="closeModal()" style="background:rgba(255,255,255,0.15);border:none;color:#fff;border-radius:6px;padding:0.35rem 0.7rem;cursor:pointer;font-size:1.1rem;line-height:1">×</button>
      </div>
      <div style="padding:1.25rem 1.5rem">
        <div class="form-group">
          <label class="form-label">Question Text *</label>
          <textarea id="q-text" class="form-textarea" style="min-height:80px">${question?.text || ''}</textarea>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
          <div class="form-group">
            <label class="form-label" style="display:flex;align-items:center;gap:0.35rem"><span style="width:20px;height:20px;border-radius:50%;background:#dbeafe;color:#2563eb;display:inline-flex;align-items:center;justify-content:center;font-size:0.7rem;font-weight:700">A</span> Option A *</label>
            <input id="q-a" class="form-input" value="${question?.options?.find(o => o.id === 'a')?.text || ''}" placeholder="Enter option A">
          </div>
          <div class="form-group">
            <label class="form-label" style="display:flex;align-items:center;gap:0.35rem"><span style="width:20px;height:20px;border-radius:50%;background:#dbeafe;color:#2563eb;display:inline-flex;align-items:center;justify-content:center;font-size:0.7rem;font-weight:700">B</span> Option B *</label>
            <input id="q-b" class="form-input" value="${question?.options?.find(o => o.id === 'b')?.text || ''}" placeholder="Enter option B">
          </div>
          <div class="form-group">
            <label class="form-label" style="display:flex;align-items:center;gap:0.35rem"><span style="width:20px;height:20px;border-radius:50%;background:#dbeafe;color:#2563eb;display:inline-flex;align-items:center;justify-content:center;font-size:0.7rem;font-weight:700">C</span> Option C *</label>
            <input id="q-c" class="form-input" value="${question?.options?.find(o => o.id === 'c')?.text || ''}" placeholder="Enter option C">
          </div>
          <div class="form-group">
            <label class="form-label" style="display:flex;align-items:center;gap:0.35rem"><span style="width:20px;height:20px;border-radius:50%;background:#dbeafe;color:#2563eb;display:inline-flex;align-items:center;justify-content:center;font-size:0.7rem;font-weight:700">D</span> Option D *</label>
            <input id="q-d" class="form-input" value="${question?.options?.find(o => o.id === 'd')?.text || ''}" placeholder="Enter option D">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Correct Answer *</label>
          <select id="q-ans" class="form-select">
            <option value="a" ${question?.correct_answer === 'a' ? 'selected' : ''}>A</option>
            <option value="b" ${question?.correct_answer === 'b' ? 'selected' : ''}>B</option>
            <option value="c" ${question?.correct_answer === 'c' ? 'selected' : ''}>C</option>
            <option value="d" ${question?.correct_answer === 'd' ? 'selected' : ''}>D</option>
          </select>
        </div>
        <div style="display:flex;gap:0.75rem;justify-content:flex-end;padding-top:0.75rem;border-top:1px solid var(--gray-100);margin-top:0.5rem">
          <button class="modal-btn modal-btn-cancel" onclick="closeModal()">Cancel</button>
          <button class="modal-btn modal-btn-confirm" onclick="saveQuestion('${bankId}','${question?.id || ''}')">${question ? 'Save Changes' : 'Add Question'}</button>
        </div>
      </div>
    </div>`;

  window.saveQuestion = async (bankId, qId) => {
    const text = document.getElementById('q-text').value.trim();
    const opts = ['a', 'b', 'c', 'd'].map(x => ({ id: x, text: document.getElementById('q-' + x).value.trim() }));
    const ans = document.getElementById('q-ans').value;
    if (!text || opts.some(o => !o.text)) { modalService.toast('Please fill all fields', 'error'); return; }
    try {
      if (qId) { await ApiClient.updateQuestion(bankId, qId, { text, options: opts, correct_answer: ans }); }
      else { await ApiClient.addQuestion(bankId, { text, options: opts, correct_answer: ans }); }
      window.closeModal();
      modalService.toast(qId ? 'Question updated!' : 'Question added!', 'success');
      if (onSaveCallback) { onSaveCallback(); }
      else { renderQuestions(ApiClient, { currentUser }); }
    } catch (e) { modalService.toast('Failed to save question: ' + e.message, 'error'); }
  };
}


// ════════════════════════════════════════════════════════════════════════════════
// MODAL: Bulk Import — CSV / Excel / JSON
// ════════════════════════════════════════════════════════════════════════════════
async function showImportQuestionsModal(ApiClient) {
  const banks = await ApiClient.getQuestionBanks();
  const bankOpts = banks.map(b => '<option value="' + b.id + '">' + b.title + ' — ' + b.subject + '</option>').join('');

  getOverlay().style.display = 'flex';
  document.getElementById('modal-box').innerHTML = `
    <div class="modal-card" style="max-width:660px;width:95vw;padding:0">
      <div class="modal-header" style="background:linear-gradient(135deg,#f59e0b,#d97706);padding:1.25rem 1.5rem">
        <div style="display:flex;align-items:center;gap:0.75rem">
          <div style="width:36px;height:36px;background:rgba(255,255,255,0.15);border-radius:8px;display:flex;align-items:center;justify-content:center">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" style="width:18px;height:18px"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m6.75 12L11.25 21m0 0l-3.75-3.75M11.25 21V9.75"/></svg>
          </div>
          <div>
            <h3 style="color:#fff;margin:0;font-size:1.05rem;font-weight:700">Bulk Import Questions</h3>
            <p style="color:rgba(255,255,255,0.75);margin:0;font-size:0.78rem">CSV · Excel · JSON</p>
          </div>
        </div>
        <button onclick="closeModal()" style="background:rgba(255,255,255,0.15);border:none;color:#fff;border-radius:6px;padding:0.35rem 0.7rem;cursor:pointer;font-size:1.1rem;line-height:1">×</button>
      </div>
      <div style="padding:1.25rem 1.5rem">

        <!-- Target Bank -->
        <div class="form-group" style="margin-bottom:1rem">
          <label class="form-label">Target Question Bank *</label>
          <select id="import-bank-id" class="form-select">${bankOpts}</select>
        </div>

        <!-- Method Tabs -->
        <div style="display:flex;gap:0;background:var(--gray-100);border-radius:8px;padding:3px;margin-bottom:1rem" id="import-tabs">
          <button onclick="switchImportTab('csv')" id="tab-csv" style="flex:1;padding:0.45rem 0.75rem;border:none;border-radius:6px;font-size:0.82rem;font-weight:600;cursor:pointer;background:#fff;color:#1d4ed8;box-shadow:0 1px 3px rgba(0,0,0,0.08)">📋 Paste CSV</button>
          <button onclick="switchImportTab('excel')" id="tab-excel" style="flex:1;padding:0.45rem 0.75rem;border:none;border-radius:6px;font-size:0.82rem;font-weight:600;cursor:pointer;background:transparent;color:var(--text-muted)">📊 Excel File</button>
          <button onclick="switchImportTab('json')" id="tab-json" style="flex:1;padding:0.45rem 0.75rem;border:none;border-radius:6px;font-size:0.82rem;font-weight:600;cursor:pointer;background:transparent;color:var(--text-muted)">{ } JSON File</button>
        </div>

        <!-- CSV Tab -->
        <div id="import-panel-csv">
          <div class="form-group">
            <label class="form-label" style="display:flex;justify-content:space-between">
              <span>CSV Content</span>
              <button onclick="downloadCSVTemplate()" style="font-size:0.75rem;color:#2563eb;background:none;border:none;cursor:pointer;font-weight:600">⬇ Download Template</button>
            </label>
            <textarea id="csv-content" class="form-textarea" style="min-height:160px;font-family:monospace;font-size:0.82rem" placeholder="Question,Option A,Option B,Option C,Option D,correct&#10;What is 2+2?,1,2,3,4,b&#10;Capital of India?,Chennai,Delhi,Mumbai,Pune,b"></textarea>
          </div>
          <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:0.75rem">Columns: <code style="background:var(--gray-100);padding:0.1rem 0.3rem;border-radius:3px">Question, Option A, Option B, Option C, Option D, Correct (a/b/c/d)</code></p>
        </div>

        <!-- Excel Tab -->
        <div id="import-panel-excel" style="display:none">
          <div id="excel-drop-zone" style="border:2px dashed var(--gray-300);border-radius:10px;padding:2rem;text-align:center;cursor:pointer;transition:all 0.2s;background:var(--gray-50)" onclick="document.getElementById('excel-file-input').click()" ondragover="event.preventDefault();this.style.borderColor='#2563eb';this.style.background='#eff6ff'" ondragleave="this.style.borderColor='var(--gray-300)';this.style.background='var(--gray-50)'" ondrop="handleExcelDrop(event)">
            <div style="font-size:2.5rem;margin-bottom:0.5rem">📊</div>
            <p style="font-weight:600;margin-bottom:0.25rem">Drop your Excel file here</p>
            <p style="font-size:0.82rem;color:var(--text-muted)">.xlsx or .xls — or click to browse</p>
            <input id="excel-file-input" type="file" accept=".xlsx,.xls" style="display:none" onchange="handleExcelFile(this.files[0])">
          </div>
          <div id="excel-preview" style="display:none;margin-top:0.75rem"></div>
          <p style="font-size:0.78rem;color:var(--text-muted);margin-top:0.75rem">
            Columns (Row 1 = header, data from Row 2): <code style="background:var(--gray-100);padding:0.1rem 0.3rem;border-radius:3px">Question | Option A | Option B | Option C | Option D | Correct</code>
            <button onclick="downloadExcelTemplate()" style="margin-left:0.5rem;font-size:0.75rem;color:#2563eb;background:none;border:none;cursor:pointer;font-weight:600">⬇ Download Excel Template</button>
          </p>
        </div>

        <!-- JSON Tab -->
        <div id="import-panel-json" style="display:none">
          <div id="json-drop-zone" style="border:2px dashed var(--gray-300);border-radius:10px;padding:2rem;text-align:center;cursor:pointer;transition:all 0.2s;background:var(--gray-50)" onclick="document.getElementById('json-file-input').click()" ondragover="event.preventDefault();this.style.borderColor='#2563eb';this.style.background='#eff6ff'" ondragleave="this.style.borderColor='var(--gray-300)';this.style.background='var(--gray-50)'" ondrop="handleJSONDrop(event)">
            <div style="font-size:2.5rem;margin-bottom:0.5rem">{ }</div>
            <p style="font-weight:600;margin-bottom:0.25rem">Drop your JSON file here</p>
            <p style="font-size:0.82rem;color:var(--text-muted)">.json — or click to browse</p>
            <input id="json-file-input" type="file" accept=".json" style="display:none" onchange="handleJSONFile(this.files[0])">
          </div>
          <div id="json-preview" style="display:none;margin-top:0.75rem"></div>
          <div style="margin-top:0.75rem;padding:0.75rem;background:var(--gray-50);border-radius:8px;border:1px solid var(--gray-200)">
            <p style="font-size:0.78rem;font-weight:600;margin-bottom:0.35rem;color:var(--text-secondary)">Expected JSON format:</p>
            <code style="font-size:0.75rem;color:var(--text-muted);white-space:pre-wrap;display:block">[ { "question": "...", "a": "...", "b": "...", "c": "...", "d": "...", "correct": "b" } ]</code>
          </div>
        </div>

        <!-- Actions -->
        <div style="display:flex;gap:0.75rem;justify-content:flex-end;align-items:center;padding-top:0.75rem;border-top:1px solid var(--gray-100);margin-top:0.75rem">
          <span id="import-row-count" style="font-size:0.8rem;color:var(--text-muted);flex:1"></span>
          <button class="modal-btn modal-btn-cancel" onclick="closeModal()">Cancel</button>
          <button class="modal-btn modal-btn-confirm" id="do-import-btn" onclick="doImportQuestions()">Import Questions</button>
        </div>
      </div>
    </div>`;

  // ── Parsed rows cache ──────────────────────────────────────────────────────
  let _parsedCSV = null; // only used by Excel/JSON tabs to feed the CSV endpoint

  // ── Tab switcher ───────────────────────────────────────────────────────────
  window.switchImportTab = (tab) => {
    ['csv', 'excel', 'json'].forEach(t => {
      document.getElementById('import-panel-' + t).style.display = t === tab ? '' : 'none';
      const btn = document.getElementById('tab-' + t);
      btn.style.background = t === tab ? '#fff' : 'transparent';
      btn.style.color = t === tab ? '#1d4ed8' : 'var(--text-muted)';
      btn.style.boxShadow = t === tab ? '0 1px 3px rgba(0,0,0,0.08)' : 'none';
    });
    _parsedCSV = null;
    document.getElementById('import-row-count').textContent = '';
  };

  // ── Show preview table ─────────────────────────────────────────────────────
  function showPreview(rows, containerId) {
    const el = document.getElementById(containerId);
    if (!rows || rows.length === 0) { el.style.display = 'none'; return; }
    const preview = rows.slice(0, 5);
    el.style.display = '';
    el.innerHTML = `
      <div style="border-radius:8px;overflow:hidden;border:1px solid var(--gray-200)">
        <div style="background:var(--gray-50);padding:0.5rem 0.75rem;font-size:0.78rem;font-weight:600;color:var(--text-secondary)">Preview — first ${preview.length} of ${rows.length} rows</div>
        <div class="table-wrap" style="max-height:160px;overflow-y:auto">
          <table style="font-size:0.78rem">
            <thead><tr><th>#</th><th>Question</th><th>A</th><th>B</th><th>C</th><th>D</th><th>Ans</th></tr></thead>
            <tbody>${preview.map((r, i) => '<tr><td>' + (i+1) + '</td><td style="max-width:200px">' + r[0] + '</td><td>' + r[1] + '</td><td>' + r[2] + '</td><td>' + r[3] + '</td><td>' + r[4] + '</td><td><strong>' + r[5] + '</strong></td></tr>').join('')}</tbody>
          </table>
        </div>
      </div>`;
    document.getElementById('import-row-count').textContent = rows.length + ' row(s) ready to import';
  }

  // ── Excel file handling ────────────────────────────────────────────────────
  window.handleExcelDrop = (e) => {
    e.preventDefault();
    document.getElementById('excel-drop-zone').style.borderColor = 'var(--gray-300)';
    document.getElementById('excel-drop-zone').style.background = 'var(--gray-50)';
    const file = e.dataTransfer.files[0];
    if (file) window.handleExcelFile(file);
  };

  window.handleExcelFile = async (file) => {
    if (!file) return;
    const dropZone = document.getElementById('excel-drop-zone');
    dropZone.innerHTML = '<div style="font-size:1.5rem">⏳</div><p style="font-weight:600">Reading ' + file.name + '...</p>';

    try {
      // Load SheetJS dynamically if not already loaded
      if (!window.XLSX) {
        await new Promise((resolve, reject) => {
          const s = document.createElement('script');
          s.src = 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js';
          s.onload = resolve; s.onerror = reject;
          document.head.appendChild(s);
        });
      }

      const arrayBuffer = await file.arrayBuffer();
      const workbook = window.XLSX.read(arrayBuffer, { type: 'array' });
      const sheet = workbook.Sheets[workbook.SheetNames[0]];
      const rows = window.XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '' });

      // Skip header row (row 0), validate data rows
      const dataRows = rows.slice(1).filter(r => r[0] && r[0].toString().trim());
      if (dataRows.length === 0) {
        dropZone.innerHTML = '<div style="font-size:1.5rem">⚠️</div><p>No data found. Make sure Row 1 is the header and data starts from Row 2.</p>';
        return;
      }

      // Normalize — map to [question, a, b, c, d, correct]
      const normalized = dataRows.map(r => [
        String(r[0] || '').trim(),
        String(r[1] || '').trim(),
        String(r[2] || '').trim(),
        String(r[3] || '').trim(),
        String(r[4] || '').trim(),
        String(r[5] || '').trim().toLowerCase().replace(/option\s*/i, '').charAt(0),
      ]);

      _parsedCSV = normalized.map(r => r.map(v => '"' + v.replace(/"/g, '""') + '"').join(',')).join('\n');
      dropZone.innerHTML = '<div style="font-size:1.5rem">✅</div><p style="font-weight:600;color:#16a34a">' + file.name + ' — ' + normalized.length + ' questions parsed</p>';
      showPreview(normalized, 'excel-preview');

    } catch (err) {
      dropZone.innerHTML = '<div style="font-size:1.5rem">❌</div><p style="color:var(--error)">Failed to read file: ' + err.message + '</p>';
    }
  };

  // ── JSON file handling ─────────────────────────────────────────────────────
  window.handleJSONDrop = (e) => {
    e.preventDefault();
    document.getElementById('json-drop-zone').style.borderColor = 'var(--gray-300)';
    document.getElementById('json-drop-zone').style.background = 'var(--gray-50)';
    const file = e.dataTransfer.files[0];
    if (file) window.handleJSONFile(file);
  };

  window.handleJSONFile = async (file) => {
    if (!file) return;
    const dropZone = document.getElementById('json-drop-zone');
    try {
      const text = await file.text();
      const data = JSON.parse(text);
      if (!Array.isArray(data)) throw new Error('JSON must be an array of question objects.');

      const normalized = data.map((item, i) => {
        const q = item.question || item.text || item.Question || '';
        const a = item.a || item.A || item.option_a || item.optionA || '';
        const b = item.b || item.B || item.option_b || item.optionB || '';
        const c = item.c || item.C || item.option_c || item.optionC || '';
        const d = item.d || item.D || item.option_d || item.optionD || '';
        const ans = (item.correct || item.answer || item.correct_answer || 'a').toString().toLowerCase().charAt(0);
        if (!q) throw new Error('Row ' + (i+1) + ' has no question text.');
        return [q, a, b, c, d, ans];
      });

      _parsedCSV = normalized.map(r => r.map(v => '"' + String(v).replace(/"/g, '""') + '"').join(',')).join('\n');
      dropZone.innerHTML = '<div style="font-size:1.5rem">✅</div><p style="font-weight:600;color:#16a34a">' + file.name + ' — ' + normalized.length + ' questions parsed</p>';
      showPreview(normalized, 'json-preview');

    } catch (err) {
      dropZone.innerHTML = '<div style="font-size:1.5rem">❌</div><p style="color:var(--error)">' + err.message + '</p>';
    }
  };

  // ── Template downloads ─────────────────────────────────────────────────────
  window.downloadCSVTemplate = () => {
    const content = 'Question,Option A,Option B,Option C,Option D,Correct\n'
      + '"What is 2 + 2?","1","2","3","4","d"\n'
      + '"Capital of India?","Chennai","Delhi","Mumbai","Pune","b"\n'
      + '"Which is a programming language?","Excel","Python","Word","Paint","b"';
    const blob = new Blob([content], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'question_bank_template.csv';
    a.click();
  };

  window.downloadExcelTemplate = async () => {
    if (!window.XLSX) {
      const s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js';
      document.head.appendChild(s);
      await new Promise(r => s.onload = r);
    }
    const ws = window.XLSX.utils.aoa_to_sheet([
      ['Question', 'Option A', 'Option B', 'Option C', 'Option D', 'Correct'],
      ['What is 2 + 2?', '1', '2', '3', '4', 'd'],
      ['Capital of India?', 'Chennai', 'Delhi', 'Mumbai', 'Pune', 'b'],
      ['Which is a programming language?', 'Excel', 'Python', 'Word', 'Paint', 'b'],
    ]);
    ws['!cols'] = [{ wch: 40 }, { wch: 12 }, { wch: 12 }, { wch: 12 }, { wch: 12 }, { wch: 8 }];
    const wb = window.XLSX.utils.book_new();
    window.XLSX.utils.book_append_sheet(wb, ws, 'Questions');
    window.XLSX.writeFile(wb, 'question_bank_template.xlsx');
  };

  // ── Unified Import Submit ──────────────────────────────────────────────────
  window.doImportQuestions = async () => {
    const bankId = document.getElementById('import-bank-id').value;
    if (!bankId) { modalService.toast('Please select a Question Bank first.', 'error'); return; }

    // Determine active tab
    const csvPanel  = document.getElementById('import-panel-csv');
    const excelPanel = document.getElementById('import-panel-excel');
    let csv = '';

    if (csvPanel.style.display !== 'none') {
      // CSV paste mode
      csv = document.getElementById('csv-content').value.trim();
      if (!csv) { modalService.toast('Paste CSV content first.', 'error'); return; }
    } else {
      // Excel or JSON mode — use parsed CSV
      if (!_parsedCSV) {
        modalService.toast('Upload and parse a file first.', 'error');
        return;
      }
      csv = _parsedCSV;
    }

    const btn = document.getElementById('do-import-btn');
    btn.disabled = true; btn.textContent = 'Importing...';
    try {
      const result = await ApiClient.request('/question-banks/' + bankId + '/import-questions', {
        method: 'POST',
        body: JSON.stringify({ csv }),
      });
      window.closeModal();
      modalService.toast('✅ Imported ' + result.added + ' questions. Skipped ' + result.skipped + ' rows.', 'success');
    } catch (e) {
      modalService.toast('Import failed: ' + e.message, 'error');
      btn.disabled = false; btn.textContent = 'Import Questions';
    }
  };
}
