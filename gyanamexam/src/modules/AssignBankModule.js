/**
 * AssignBankModule.js — Full-page ATC assignment for a question bank.
 * Filters: search, type, district, assignment status.
 */
import modalService from '../services/ModalService.js';

const ASSIGN_BANK_KEY = 'gyanam_assign_bank_id';

export function setAssignBankId(bankId) {
  sessionStorage.setItem(ASSIGN_BANK_KEY, String(bankId));
}

export function getAssignBankId() {
  return sessionStorage.getItem(ASSIGN_BANK_KEY) || '';
}

export async function renderAssignBank(ApiClient, { currentUser, loadPage }) {
  const el = document.getElementById('page-content');
  const bankId = getAssignBankId();

  if (!bankId) {
    el.innerHTML = `
      <div class="assign-page">
        <div class="dash-empty" style="padding:3rem 1rem">
          <div class="dash-empty-icon">🔗</div>
          <div class="dash-empty-title">No question bank selected</div>
          <div class="dash-empty-text">Open a bank from Question Banks and click Assign.</div>
          <button type="button" class="btn btn-primary btn-sm" style="margin-top:1rem" onclick="loadPage('questions')">Back to Question Banks</button>
        </div>
      </div>`;
    return;
  }

  el.innerHTML = `
    <div class="assign-page">
      <div class="page-header"><div><h2>Assign to ATC Centres</h2><p>Loading…</p></div></div>
      <div style="text-align:center;padding:2.5rem;color:var(--text-muted)">Loading centres…</div>
    </div>`;

  let banks = [];
  let centres = [];
  let types = [];
  try {
    const [banksRes, atcRes] = await Promise.all([
      ApiClient.getQuestionBanks(),
      ApiClient.getPortalATCCentres(),
    ]);
    banks = Array.isArray(banksRes) ? banksRes : (banksRes?.data || []);
    centres = atcRes?.centres || [];
    types = atcRes?.types || [];
  } catch (e) {
    el.innerHTML = `<div class="dash-empty" style="padding:3rem"><div class="dash-empty-title">Failed to load</div><div class="dash-empty-text">${e.message}</div></div>`;
    return;
  }

  const bank = banks.find(b => String(b.id) === String(bankId));
  if (!bank) {
    el.innerHTML = `
      <div class="dash-empty" style="padding:3rem">
        <div class="dash-empty-title">Question bank not found</div>
        <button type="button" class="btn btn-primary btn-sm" style="margin-top:1rem" onclick="loadPage('questions')">Back</button>
      </div>`;
    return;
  }

  const initiallyAssigned = new Set(
    (bank.assignedTo || bank.assigned_to || []).map(String)
  );
  const selected = new Set(initiallyAssigned);

  let filterText = '';
  let filterType = '';
  let filterDistrict = '';
  let filterStatus = ''; // '' | 'assigned' | 'unassigned'
  let page = 1;
  const pageSize = 25;

  const districts = [...new Set(centres.map(c => c.district).filter(Boolean))].sort();

  function getFiltered() {
    const q = filterText.trim().toLowerCase();
    return centres.filter(c => {
      const code = String(c.code || '');
      const name = String(c.name || '');
      const district = String(c.district || '');
      const state = String(c.state || '');
      const type = String(c.centre_type || '');

      const matchesText = !q
        || code.toLowerCase().includes(q)
        || name.toLowerCase().includes(q)
        || district.toLowerCase().includes(q)
        || state.toLowerCase().includes(q);

      const matchesType = !filterType || type === filterType;
      const matchesDistrict = !filterDistrict || district === filterDistrict;
      const isAssigned = selected.has(code);
      const matchesStatus =
        !filterStatus
        || (filterStatus === 'assigned' && isAssigned)
        || (filterStatus === 'unassigned' && !isAssigned);

      return matchesText && matchesType && matchesDistrict && matchesStatus;
    });
  }

  function hasFilters() {
    return !!(filterText || filterType || filterDistrict || filterStatus);
  }

  function clearFilters() {
    filterText = '';
    filterType = '';
    filterDistrict = '';
    filterStatus = '';
    page = 1;
    const search = document.getElementById('assign-search');
    const type = document.getElementById('assign-type');
    const dist = document.getElementById('assign-district');
    const status = document.getElementById('assign-status');
    if (search) search.value = '';
    if (type) type.value = '';
    if (dist) dist.value = '';
    if (status) status.value = '';
    renderList();
  }

  function syncSelectAll(filtered) {
    const master = document.getElementById('assign-select-all');
    if (!master) return;
    const visibleCodes = filtered.map(c => String(c.code));
    const allChecked = visibleCodes.length > 0 && visibleCodes.every(code => selected.has(code));
    const someChecked = visibleCodes.some(code => selected.has(code));
    master.checked = allChecked;
    master.indeterminate = !allChecked && someChecked;
  }

  function updateSelectionBar(filteredCount) {
    const countEl = document.getElementById('assign-selected-count');
    const dirtyEl = document.getElementById('assign-dirty');
    const visibleEl = document.getElementById('assign-visible-count');
    if (countEl) countEl.textContent = String(selected.size);
    if (visibleEl) visibleEl.textContent = String(filteredCount);
    if (dirtyEl) {
      const dirty = selected.size !== initiallyAssigned.size
        || [...selected].some(c => !initiallyAssigned.has(c))
        || [...initiallyAssigned].some(c => !selected.has(c));
      dirtyEl.hidden = !dirty;
    }
  }

  function renderList() {
    const filtered = getFiltered();
    const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize) || 1);
    if (page > totalPages) page = totalPages;
    const slice = filtered.slice((page - 1) * pageSize, page * pageSize);
    const list = document.getElementById('assign-centre-list');
    const clearBtn = document.getElementById('assign-clear');
    if (clearBtn) clearBtn.hidden = !hasFilters();

    if (!list) return;

    if (filtered.length === 0) {
      list.innerHTML = `
        <div class="dash-empty" style="padding:2rem 1rem">
          <div class="dash-empty-icon">🏛</div>
          <div class="dash-empty-title">${centres.length === 0 ? 'No ATC centres synced' : 'No centres match filters'}</div>
          <div class="dash-empty-text">${centres.length === 0
            ? 'Save/edit ATC centres in the main Gyanam portal to sync them here.'
            : 'Try clearing search or filters.'}</div>
          ${hasFilters() ? '<button type="button" class="btn btn-outline btn-sm" style="margin-top:0.75rem" id="assign-empty-clear">Clear filters</button>' : ''}
        </div>`;
      document.getElementById('assign-empty-clear')?.addEventListener('click', clearFilters);
    } else {
      list.innerHTML = slice.map(c => {
        const code = String(c.code || '');
        const checked = selected.has(code) ? 'checked' : '';
        const type = c.centre_type
          ? `<span class="assign-type-tag">${c.centre_type}</span>`
          : '';
        const locParts = [c.district, c.state].filter(Boolean).join(', ');
        return `
          <label class="assign-row ${selected.has(code) ? 'is-selected' : ''}">
            <input type="checkbox" class="assign-check" value="${code}" ${checked}>
            <span class="assign-row-body">
              <span class="assign-row-top">
                <strong class="assign-code">${code}</strong>
                ${type}
                ${selected.has(code) ? '<span class="assign-pill">Assigned</span>' : ''}
              </span>
              <span class="assign-name">${c.name || '—'}${locParts ? ` · ${locParts}` : ''}</span>
            </span>
          </label>`;
      }).join('');

      list.querySelectorAll('.assign-check').forEach(cb => {
        cb.addEventListener('change', () => {
          const code = cb.value;
          if (cb.checked) selected.add(code);
          else selected.delete(code);
          renderList();
        });
      });
    }

    // pagination footer
    const pager = document.getElementById('assign-pagination');
    if (pager) {
      const start = filtered.length === 0 ? 0 : (page - 1) * pageSize + 1;
      const end = Math.min(page * pageSize, filtered.length);
      pager.innerHTML = `
        <span class="stu-pager-range">${filtered.length === 0 ? '0 centres' : `Showing ${start}–${end} of ${filtered.length}`}</span>
        <div class="stu-pager-right">
          <button type="button" class="stu-page-btn" id="assign-prev" ${page <= 1 ? 'disabled' : ''}>‹</button>
          <span class="stu-pager-range">Page ${page} / ${totalPages}</span>
          <button type="button" class="stu-page-btn" id="assign-next" ${page >= totalPages ? 'disabled' : ''}>›</button>
        </div>`;
      document.getElementById('assign-prev')?.addEventListener('click', () => { page = Math.max(1, page - 1); renderList(); });
      document.getElementById('assign-next')?.addEventListener('click', () => { page = Math.min(totalPages, page + 1); renderList(); });
    }

    syncSelectAll(filtered);
    updateSelectionBar(filtered.length);
  }

  el.innerHTML = `
  <div class="assign-page">
    <div class="page-header dash-page-header">
      <div>
        <button type="button" class="btn btn-ghost btn-sm assign-back" id="assign-back">← Question Banks</button>
        <h2 style="margin-top:0.35rem">Assign to ATC Centres</h2>
        <p class="dash-meta">
          <span class="dash-chip dash-chip-blue">${bank.subject || 'Bank'}</span>
          <span class="dash-meta-sep">·</span>
          <span>${bank.questions_count ?? 0} questions</span>
        </p>
      </div>
      <div class="dash-page-actions">
        <span id="assign-dirty" class="dash-chip" hidden style="background:#fff7ed;color:#c2410c;border-color:#fed7aa">Unsaved changes</span>
        <button type="button" class="btn btn-outline btn-sm" id="assign-cancel">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="assign-save">Save Assignment</button>
      </div>
    </div>

    <div class="card assign-bank-card">
      <div class="assign-bank-icon">📚</div>
      <div>
        <div class="assign-bank-title">${bank.title || 'Untitled bank'}</div>
        <div class="assign-bank-sub">${bank.subject || '—'} · currently assigned to <strong id="assign-selected-count">${selected.size}</strong> centre(s)</div>
      </div>
    </div>

    <div class="stu-toolbar card">
      <div class="stu-search-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
        </svg>
        <input type="search" id="assign-search" class="form-input" placeholder="Search code, name, district, or state…">
      </div>
      <div class="stu-filters">
        <select id="assign-type" class="form-select" title="Centre type">
          <option value="">All Types</option>
          ${types.map(t => `<option value="${t}">${t}</option>`).join('')}
        </select>
        <select id="assign-district" class="form-select" title="District">
          <option value="">All Districts</option>
          ${districts.map(d => `<option value="${d}">${d}</option>`).join('')}
        </select>
        <select id="assign-status" class="form-select" title="Assignment status">
          <option value="">All Status</option>
          <option value="assigned">Assigned</option>
          <option value="unassigned">Not assigned</option>
        </select>
        <button type="button" id="assign-clear" class="btn btn-outline btn-sm stu-clear-btn" hidden>Clear</button>
      </div>
    </div>

    <div class="card assign-list-card">
      <div class="assign-list-head">
        <label class="assign-select-all">
          <input type="checkbox" id="assign-select-all">
          <span>Select visible (<span id="assign-visible-count">0</span>)</span>
        </label>
        <div class="assign-list-actions">
          <button type="button" class="btn btn-outline btn-sm" id="assign-all-filtered">Select filtered</button>
          <button type="button" class="btn btn-outline btn-sm" id="assign-none-filtered">Clear filtered</button>
          <button type="button" class="btn btn-outline btn-sm" id="assign-none-all">Clear all</button>
        </div>
      </div>
      <div id="assign-centre-list" class="assign-centre-list"></div>
      <div id="assign-pagination" class="stu-pagination"></div>
    </div>
  </div>`;

  document.getElementById('assign-back')?.addEventListener('click', () => loadPage('questions'));
  document.getElementById('assign-cancel')?.addEventListener('click', () => loadPage('questions'));

  document.getElementById('assign-search')?.addEventListener('input', e => {
    filterText = e.target.value;
    page = 1;
    renderList();
  });
  document.getElementById('assign-type')?.addEventListener('change', e => {
    filterType = e.target.value;
    page = 1;
    renderList();
  });
  document.getElementById('assign-district')?.addEventListener('change', e => {
    filterDistrict = e.target.value;
    page = 1;
    renderList();
  });
  document.getElementById('assign-status')?.addEventListener('change', e => {
    filterStatus = e.target.value;
    page = 1;
    renderList();
  });
  document.getElementById('assign-clear')?.addEventListener('click', clearFilters);

  document.getElementById('assign-select-all')?.addEventListener('change', e => {
    const filtered = getFiltered();
    const visible = filtered.slice((page - 1) * pageSize, page * pageSize);
    visible.forEach(c => {
      const code = String(c.code || '');
      if (e.target.checked) selected.add(code);
      else selected.delete(code);
    });
    renderList();
  });

  document.getElementById('assign-all-filtered')?.addEventListener('click', () => {
    getFiltered().forEach(c => selected.add(String(c.code || '')));
    renderList();
  });
  document.getElementById('assign-none-filtered')?.addEventListener('click', () => {
    getFiltered().forEach(c => selected.delete(String(c.code || '')));
    renderList();
  });
  document.getElementById('assign-none-all')?.addEventListener('click', () => {
    selected.clear();
    renderList();
  });

  document.getElementById('assign-save')?.addEventListener('click', async () => {
    const codes = [...selected];
    const btn = document.getElementById('assign-save');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
    try {
      await ApiClient.assignQuestionBank(bankId, codes);
      modalService.toast(
        codes.length
          ? `Assigned to ${codes.length} centre(s)`
          : 'Bank unassigned (admin-only)',
        'success'
      );
      sessionStorage.removeItem(ASSIGN_BANK_KEY);
      loadPage('questions');
    } catch (e) {
      modalService.toast('Assignment failed: ' + e.message, 'error');
      if (btn) { btn.disabled = false; btn.textContent = 'Save Assignment'; }
    }
  });

  renderList();
}
