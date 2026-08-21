/**
 * CredentialsModule.js — Manage Admin, ATC, and DLC portal user credentials.
 * Admin-only page. Allows creating, editing passwords, and deleting portal users.
 */
import modalService from '../services/ModalService.js';

export async function renderCredentials(ApiClient, ctx) {
  const el = document.getElementById('page-content');

  let users = [];
  try {
    users = await ApiClient.getPortalUsers();
  } catch (e) {
    el.innerHTML = `<div class="card" style="padding:2rem;text-align:center;color:var(--text-muted)">Failed to load users: ${e.message}</div>`;
    return;
  }

  let filterText = '';
  let filterRole = '';

  function renderTable() {
    const filtered = users.filter(u => {
      const matchesText = !filterText ||
        u.username.toLowerCase().includes(filterText.toLowerCase()) ||
        u.name.toLowerCase().includes(filterText.toLowerCase()) ||
        (u.centre_id || '').toLowerCase().includes(filterText.toLowerCase());
      const matchesRole = !filterRole || u.role === filterRole;
      return matchesText && matchesRole;
    });

    const tbody = document.getElementById('cred-table-body');
    if (!tbody) return;

    const roleBadge = (role) => {
      const colors = {
        admin: 'background:#1d4ed8;color:white',
        atc: 'background:#60a5fa;color:#1e3a8a',
        dlc: 'background:#a78bfa;color:#2e1065',
      };
      return `<span style="font-size:0.7rem;font-weight:700;text-transform:uppercase;padding:0.2rem 0.6rem;border-radius:4px;letter-spacing:0.02em;${colors[role] || 'background:#e2e8f0;color:#475569'}">${role}</span>`;
    };

    tbody.innerHTML = filtered.length === 0
      ? '<tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:2rem">No users found.</td></tr>'
      : filtered.map(u => `
        <tr>
          <td style="font-weight:600">${u.username}</td>
          <td>${u.name}</td>
          <td>${roleBadge(u.role)}</td>
          <td>${u.centre_id || '<span style="color:var(--text-muted)">—</span>'}</td>
          <td style="font-size:0.78rem;color:var(--text-muted)">${u.email || '—'}</td>
          <td style="font-size:0.78rem;color:var(--text-muted)">${u.created_at ? new Date(u.created_at).toLocaleDateString() : '—'}</td>
          <td>
            <div style="display:flex;gap:0.375rem">
              <button class="btn btn-outline btn-sm" onclick="editCredUser('${u.username}')">Edit</button>
              ${u.role !== 'admin' ? `<button class="btn btn-danger btn-sm" onclick="deleteCredUser('${u.username}')">Delete</button>` : ''}
            </div>
          </td>
        </tr>`).join('');
  }

  // Stats
  const adminCount = users.filter(u => u.role === 'admin').length;
  const atcCount = users.filter(u => u.role === 'atc').length;
  const dlcCount = users.filter(u => u.role === 'dlc').length;

  el.innerHTML = `
  <div class="page-header">
    <div>
      <h2>Portal Credentials</h2>
      <p>${users.length} user(s) — ${adminCount} Admin, ${atcCount} ATC, ${dlcCount} DLC</p>
    </div>
    <button id="add-user-btn" class="btn btn-primary">+ New User</button>
  </div>

  <!-- Stats Cards -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem">
    <div class="card" style="padding:1.25rem;display:flex;align-items:center;gap:1rem">
      <div style="width:42px;height:42px;background:linear-gradient(135deg,#1d4ed8,#3b82f6);border-radius:10px;display:flex;align-items:center;justify-content:center">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" style="width:20px;height:20px"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
      </div>
      <div>
        <div style="font-size:1.5rem;font-weight:800;color:#0f172a">${adminCount}</div>
        <div style="font-size:0.78rem;color:var(--text-muted);font-weight:500">Admins</div>
      </div>
    </div>
    <div class="card" style="padding:1.25rem;display:flex;align-items:center;gap:1rem">
      <div style="width:42px;height:42px;background:linear-gradient(135deg,#3b82f6,#60a5fa);border-radius:10px;display:flex;align-items:center;justify-content:center">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" style="width:20px;height:20px"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3H21"/></svg>
      </div>
      <div>
        <div style="font-size:1.5rem;font-weight:800;color:#0f172a">${atcCount}</div>
        <div style="font-size:0.78rem;color:var(--text-muted);font-weight:500">ATC Centres</div>
      </div>
    </div>
    <div class="card" style="padding:1.25rem;display:flex;align-items:center;gap:1rem">
      <div style="width:42px;height:42px;background:linear-gradient(135deg,#8b5cf6,#a78bfa);border-radius:10px;display:flex;align-items:center;justify-content:center">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" style="width:20px;height:20px"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342"/></svg>
      </div>
      <div>
        <div style="font-size:1.5rem;font-weight:800;color:#0f172a">${dlcCount}</div>
        <div style="font-size:0.78rem;color:var(--text-muted);font-weight:500">DLC Centres</div>
      </div>
    </div>
  </div>

  <!-- Filters -->
  <div class="card" style="margin-bottom:1.5rem;padding:1rem;display:flex;gap:1rem;flex-wrap:wrap;align-items:center;background:var(--gray-50)">
    <div style="flex:1;min-width:240px;position:relative">
      <input type="text" id="cred-search" class="form-input" placeholder="Search by username, name, or centre..." style="padding-left:2.5rem">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);width:18px;height:18px;color:var(--gray-400)">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
      </svg>
    </div>
    <div style="width:150px">
      <select id="cred-role-filter" class="form-select">
        <option value="">All Roles</option>
        <option value="admin">Admin</option>
        <option value="atc">ATC</option>
        <option value="dlc">DLC</option>
      </select>
    </div>
  </div>

  <!-- Table -->
  <div class="table-wrap card">
    <table>
      <thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Centre</th><th>Email</th><th>Created</th><th>Actions</th></tr></thead>
      <tbody id="cred-table-body"></tbody>
    </table>
  </div>`;

  renderTable();

  // Event listeners
  document.getElementById('cred-search').addEventListener('input', e => { filterText = e.target.value; renderTable(); });
  document.getElementById('cred-role-filter').addEventListener('change', e => { filterRole = e.target.value; renderTable(); });
  document.getElementById('add-user-btn').addEventListener('click', () => showUserModal(ApiClient, null, refreshList));

  async function refreshList() {
    try {
      users = await ApiClient.getPortalUsers();
      renderTable();
    } catch (e) { modalService.toast('Failed to refresh: ' + e.message, 'error'); }
  }

  window.editCredUser = (username) => {
    const user = users.find(u => u.username === username);
    if (user) showUserModal(ApiClient, user, refreshList);
  };

  window.deleteCredUser = async (username) => {
    const ok = await modalService.confirm(
      `Delete user <strong>${username}</strong>? This will revoke their access to the exam portal.`,
      { title: 'Delete User', confirmText: 'Delete', type: 'danger' }
    );
    if (!ok) return;
    try {
      await ApiClient.deletePortalUser(username);
      modalService.toast('User deleted.', 'success');
      refreshList();
    } catch (e) { modalService.toast('Failed: ' + e.message, 'error'); }
  };
}

// ─── User Create/Edit Modal ─────────────────────────────────────────────────

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

function showUserModal(ApiClient, existingUser = null, onSuccess) {
  const isEdit = !!existingUser;

  getOverlay().style.display = 'flex';
  document.getElementById('modal-box').innerHTML = `
    <div class="modal-card" style="max-width:480px;width:95vw;padding:0">
      <div class="modal-header" style="background:linear-gradient(135deg,#1d4ed8,#3b82f6)">
        <div style="display:flex;align-items:center;gap:0.75rem">
          <div style="width:36px;height:36px;background:rgba(255,255,255,0.15);border-radius:8px;display:flex;align-items:center;justify-content:center">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" style="width:18px;height:18px"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
          </div>
          <h3 class="modal-title" style="color:#fff;margin:0">${isEdit ? 'Edit User' : 'Create New User'}</h3>
        </div>
        <button onclick="closeModal()" style="background:rgba(255,255,255,0.15);border:none;color:#fff;border-radius:6px;padding:0.3rem 0.65rem;cursor:pointer;font-size:1rem;line-height:1">×</button>
      </div>
      <div style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem">
        <div class="form-group">
          <label class="form-label">Username *</label>
          <input id="cu-username" class="form-input" value="${existingUser?.username || ''}" placeholder="e.g. atc_mumbai" ${isEdit ? 'readonly style="background:var(--gray-100)"' : ''}>
          ${!isEdit ? '<p style="font-size:0.72rem;color:var(--text-muted);margin-top:0.2rem">This will be used for login. Cannot be changed later.</p>' : ''}
        </div>
        <div class="form-group">
          <label class="form-label">Full Name *</label>
          <input id="cu-name" class="form-input" value="${existingUser?.name || ''}" placeholder="e.g. Mumbai ATC Centre">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group">
            <label class="form-label">Role *</label>
            <select id="cu-role" class="form-select" ${isEdit && existingUser?.role === 'admin' ? 'disabled' : ''}>
              <option value="atc" ${existingUser?.role === 'atc' ? 'selected' : ''}>ATC</option>
              <option value="dlc" ${existingUser?.role === 'dlc' ? 'selected' : ''}>DLC</option>
              <option value="admin" ${existingUser?.role === 'admin' ? 'selected' : ''}>Admin</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Centre ID</label>
            <input id="cu-centre" class="form-input" value="${existingUser?.centre_id || ''}" placeholder="e.g. MUMBAI_01">
            <p style="font-size:0.72rem;color:var(--text-muted);margin-top:0.2rem">Leave blank for admin (all centres)</p>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Email <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
          <input id="cu-email" class="form-input" type="email" value="${existingUser?.email || ''}" placeholder="user@example.com">
        </div>
        <div class="form-group">
          <label class="form-label">${isEdit ? 'New Password' : 'Password *'} <span style="font-weight:400;color:var(--text-muted)">${isEdit ? '(leave blank to keep current)' : ''}</span></label>
          <input id="cu-password" class="form-input" type="password" placeholder="${isEdit ? '••••••••' : 'Min 4 characters'}">
        </div>
        ${isEdit ? '' : `
        <div class="form-group">
          <label class="form-label">Confirm Password *</label>
          <input id="cu-password2" class="form-input" type="password" placeholder="Re-enter password">
        </div>`}

        <div class="modal-actions" style="padding-top:0.75rem;border-top:1px solid var(--gray-100);margin-top:0.25rem">
          <button class="modal-btn modal-btn-cancel" onclick="closeModal()">Cancel</button>
          <button class="modal-btn modal-btn-confirm" id="cu-save-btn">${isEdit ? 'Save Changes' : 'Create User'}</button>
        </div>
      </div>
    </div>`;

  document.getElementById('cu-save-btn').addEventListener('click', async () => {
    const username = document.getElementById('cu-username').value.trim();
    const name = document.getElementById('cu-name').value.trim();
    const role = document.getElementById('cu-role').value;
    const centre_id = document.getElementById('cu-centre').value.trim() || null;
    const email = document.getElementById('cu-email').value.trim() || null;
    const password = document.getElementById('cu-password').value;

    if (!username) { modalService.toast('Username is required.', 'error'); return; }
    if (!name) { modalService.toast('Name is required.', 'error'); return; }

    if (!isEdit) {
      if (!password || password.length < 4) { modalService.toast('Password must be at least 4 characters.', 'error'); return; }
      const password2 = document.getElementById('cu-password2').value;
      if (password !== password2) { modalService.toast('Passwords do not match.', 'error'); return; }
    }

    const payload = { username, name, role, centre_id, email };
    if (password) payload.password = password;

    const btn = document.getElementById('cu-save-btn');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    try {
      if (isEdit) {
        await ApiClient.updatePortalUser(payload);
      } else {
        await ApiClient.createPortalUser(payload);
      }
      window.closeModal();
      modalService.toast(isEdit ? 'User updated!' : 'User created!', 'success');
      if (onSuccess) onSuccess();
    } catch (e) {
      modalService.toast('Failed: ' + e.message, 'error');
      btn.disabled = false;
      btn.textContent = isEdit ? 'Save Changes' : 'Create User';
    }
  });
}
