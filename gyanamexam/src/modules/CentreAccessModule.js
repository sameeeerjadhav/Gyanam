/**
 * CentreAccessModule.js — ATC opens main exams once for the whole centre.
 */
import modalService from '../services/ModalService.js';

function formatWhen(iso) {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  return date.toLocaleString('en-IN', {
    day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: true,
  });
}

export async function renderCentreAccess(ApiClient) {
  const el = document.getElementById('page-content');
  if (!el) return;

  let status = { open: false, expires_at: null };
  try {
    status = await ApiClient.getCentreExamAccess();
  } catch (e) {
    el.innerHTML = `<div class="card" style="text-align:center;padding:3rem;color:var(--danger)">Could not load exam access: ${String(e.message || '')}</div>`;
    return;
  }

  const open = !!status.open;
  el.innerHTML = `
    <div class="page-header">
      <div>
        <h2>Exam access</h2>
        <p>Open main exams once for every student at your centre.</p>
      </div>
    </div>
    <div class="card" style="max-width:640px;padding:1.5rem">
      <p style="margin:0 0 1rem;font-size:0.95rem">
        ${open
          ? `<span class="badge badge-green">Open</span> Students can start main exams until <strong>${formatWhen(status.expires_at)}</strong>.`
          : `<span class="badge badge-gray">Closed</span> Students cannot start a main exam from home or at the centre.`}
      </p>
      <p style="margin:0 0 1rem;color:var(--text-secondary);font-size:0.88rem">
        Enter your ATC password here, on your own computer. You do not type it on each student computer.
        Demo exams stay available either way. Closing stops new starts. An exam that has already started can still be finished.
      </p>
      ${open ? '' : `
        <div class="form-group">
          <label class="form-label" for="centre-access-password">Your ATC password</label>
          <input id="centre-access-password" class="form-input" type="password" autocomplete="current-password" style="max-width:360px">
        </div>
        <button id="centre-access-open" class="btn btn-primary" type="button">Open exams for 3 hours</button>
      `}
      ${open ? `<button id="centre-access-close" class="btn btn-outline" type="button">Close exams</button>` : ''}
    </div>`;

  document.getElementById('centre-access-open')?.addEventListener('click', async () => {
    const password = document.getElementById('centre-access-password')?.value || '';
    if (!password.trim()) {
      modalService.toast('Enter your ATC password', 'error');
      return;
    }
    const btn = document.getElementById('centre-access-open');
    if (btn) { btn.disabled = true; btn.textContent = 'Opening…'; }
    try {
      await ApiClient.openCentreExamAccess(password);
      modalService.toast('Main exams are open for 3 hours', 'success');
      renderCentreAccess(ApiClient);
    } catch (e) {
      modalService.toast(e.message || 'Could not open exams', 'error');
      if (btn) { btn.disabled = false; btn.textContent = 'Open exams for 3 hours'; }
    }
  });

  document.getElementById('centre-access-close')?.addEventListener('click', async () => {
    const ok = await modalService.confirm(
      'Students will not be able to start a new main exam until you open again. Exams already in progress can be finished.',
      { title: 'Close exams', confirmText: 'Close exams', type: 'warning' }
    );
    if (!ok) return;
    try {
      await ApiClient.closeCentreExamAccess();
      modalService.toast('Main exams are closed', 'success');
      renderCentreAccess(ApiClient);
    } catch (e) {
      modalService.toast(e.message || 'Could not close exams', 'error');
    }
  });
}

function esc(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/"/g, '&quot;');
}

export async function renderAdminCentreAccess(ApiClient) {
  const el = document.getElementById('page-content');
  if (!el) return;

  let centres = [];
  try {
    const res = await ApiClient.getAdminCentreExamAccess();
    centres = res?.centres || [];
  } catch (e) {
    el.innerHTML = `<div class="card" style="text-align:center;padding:3rem;color:var(--danger)">Could not load ATCs: ${esc(e.message)}</div>`;
    return;
  }

  el.innerHTML = `
    <div class="page-header">
      <div>
        <h2>Exam access</h2>
        <p>Open main exams for the students of the ATCs you select.</p>
      </div>
      <div style="display:flex;gap:0.5rem;flex-wrap:wrap;justify-content:flex-end">
        <button id="admin-access-open" class="btn btn-primary" type="button" disabled>Open selected for 3 hours</button>
        <button id="admin-access-close" class="btn btn-outline" type="button" disabled>Close selected</button>
      </div>
    </div>
    <div class="card" style="margin-bottom:1rem;padding:1rem;display:flex;gap:1rem;flex-wrap:wrap;align-items:end">
      <div style="flex:1;min-width:220px">
        <label class="form-label" for="admin-access-search">Search ATC</label>
        <input id="admin-access-search" class="form-input" type="search" placeholder="Name or code…" autocomplete="off">
      </div>
      <div style="min-width:240px">
        <label class="form-label" for="admin-access-password">Your admin password</label>
        <input id="admin-access-password" class="form-input" type="password" autocomplete="current-password">
      </div>
    </div>
    <div class="table-wrap card">
      <table>
        <thead>
          <tr>
            <th style="width:42px"><input id="admin-access-all" type="checkbox" aria-label="Select all ATCs"></th>
            <th>ATC</th>
            <th>Code</th>
            <th>Students</th>
          </tr>
        </thead>
        <tbody id="admin-access-body">
          ${centres.map((centre) => `
            <tr data-name="${esc((centre.name || '') + ' ' + (centre.code || ''))}">
              <td><input class="admin-access-check" type="checkbox" value="${esc(centre.code)}" aria-label="Select ${esc(centre.name)}"></td>
              <td style="font-weight:600">${esc(centre.name)}</td>
              <td>${esc(centre.code)}</td>
              <td>${centre.open
                ? `<span class="badge badge-green">Open until ${esc(formatWhen(centre.expires_at))}</span>`
                : '<span class="badge badge-gray">Closed</span>'}</td>
            </tr>`).join('') || '<tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:2rem">No ATC logins found.</td></tr>'}
        </tbody>
      </table>
    </div>`;

  const searchEl = document.getElementById('admin-access-search');
  const selectAll = document.getElementById('admin-access-all');
  const openBtn = document.getElementById('admin-access-open');
  const closeBtn = document.getElementById('admin-access-close');

  function visibleChecks() {
    return Array.from(document.querySelectorAll('#admin-access-body tr'))
      .filter((tr) => tr.style.display !== 'none')
      .map((tr) => tr.querySelector('.admin-access-check'))
      .filter(Boolean);
  }

  function selectedCodes() {
    return visibleChecks().filter((box) => box.checked).map((box) => box.value);
  }

  function refreshButtons() {
    const count = selectedCodes().length;
    const suffix = count ? ` (${count})` : '';
    if (openBtn) {
      openBtn.disabled = count === 0;
      openBtn.textContent = `Open selected for 3 hours${suffix}`;
    }
    if (closeBtn) {
      closeBtn.disabled = count === 0;
      closeBtn.textContent = `Close selected${suffix}`;
    }
    if (selectAll) {
      const boxes = visibleChecks();
      selectAll.checked = boxes.length > 0 && boxes.every((box) => box.checked);
      selectAll.indeterminate = boxes.some((box) => box.checked) && !selectAll.checked;
    }
  }

  searchEl?.addEventListener('input', () => {
    const q = searchEl.value.trim().toLowerCase();
    document.querySelectorAll('#admin-access-body tr').forEach((tr) => {
      const hay = String(tr.dataset.name || '').toLowerCase();
      tr.style.display = !q || hay.includes(q) ? '' : 'none';
    });
    refreshButtons();
  });
  selectAll?.addEventListener('change', () => {
    visibleChecks().forEach((box) => { box.checked = selectAll.checked; });
    refreshButtons();
  });
  document.querySelectorAll('.admin-access-check').forEach((box) => {
    box.addEventListener('change', refreshButtons);
  });

  openBtn?.addEventListener('click', async () => {
    const centres = selectedCodes();
    const password = document.getElementById('admin-access-password')?.value || '';
    if (!centres.length) {
      modalService.toast('Select at least one ATC', 'error');
      return;
    }
    if (!password.trim()) {
      modalService.toast('Enter your admin password', 'error');
      return;
    }
    openBtn.disabled = true;
    closeBtn.disabled = true;
    openBtn.textContent = 'Opening…';
    try {
      const res = await ApiClient.openAdminCentreExamAccess(password, centres);
      modalService.toast(res?.message || 'Main exams are open', 'success');
      renderAdminCentreAccess(ApiClient);
    } catch (e) {
      modalService.toast(e.message || 'Could not open exams', 'error');
      refreshButtons();
    }
  });

  closeBtn?.addEventListener('click', async () => {
    const centres = selectedCodes();
    if (!centres.length) {
      modalService.toast('Select at least one ATC', 'error');
      return;
    }
    const ok = await modalService.confirm(
      `Close main exams for ${centres.length} selected ATC(s). Students who already started can finish.`,
      { title: 'Close exams', confirmText: 'Close selected', type: 'warning' }
    );
    if (!ok) return;
    try {
      const res = await ApiClient.closeAdminCentreExamAccess(centres);
      modalService.toast(res?.message || 'Main exams are closed', 'success');
      renderAdminCentreAccess(ApiClient);
    } catch (e) {
      modalService.toast(e.message || 'Could not close exams', 'error');
    }
  });
}
