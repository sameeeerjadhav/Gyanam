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
