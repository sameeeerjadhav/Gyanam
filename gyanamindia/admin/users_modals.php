<!-- ============================================================
     ADD / EDIT USER MODAL
     ============================================================ -->
<div class="modal-overlay" id="userModal">
    <div class="modal-panel" style="max-width: 720px;">

        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon indigo">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </div>
                <div>
                    <div class="modal-title" id="modalTitle">Add New User</div>
                    <div class="modal-subtitle" id="modalSubtitle">Fill in the details to create a user account</div>
                </div>
            </div>
            <button type="button" class="btn-close" onclick="closeModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <form id="userForm" novalidate>
            <input type="hidden" id="userId"     name="id">
            <input type="hidden" id="formAction" name="action" value="add">

            <div class="modal-body">

                <!-- ── Account Information ── -->
                <div class="form-section">
                    <div class="form-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Account Information
                    </div>

                    <div class="form-grid">

                        <div class="form-field">
                            <label class="field-label" for="username">
                                Username <span class="required-star">*</span>
                            </label>
                            <div class="input-wrap">
                                <span class="input-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                </span>
                                <input type="text" id="username" name="username"
                                       placeholder="e.g. john_doe"
                                       required maxlength="50" autocomplete="off">
                            </div>
                            <span class="field-hint">Unique identifier used for login</span>
                        </div>

                        <div class="form-field">
                            <label class="field-label" for="password">
                                Password <span class="required-star" id="passwordRequired">*</span>
                            </label>
                            <div class="input-wrap">
                                <span class="input-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                </span>
                                <input type="password" id="password" name="password"
                                       placeholder="Min. 6 characters"
                                       autocomplete="new-password">
                                <button type="button" class="input-toggle-pwd" tabindex="-1" aria-label="Toggle password visibility">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>
                            </div>
                            <span class="field-hint" id="passwordHint">Minimum 6 characters required</span>
                        </div>

                        <div class="form-field span-2">
                            <label class="field-label" for="name">
                                Full Name <span class="required-star">*</span>
                            </label>
                            <div class="input-wrap">
                                <span class="input-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                </span>
                                <input type="text" id="name" name="name"
                                       placeholder="Enter full display name"
                                       required maxlength="100" autocomplete="off">
                            </div>
                            <span class="field-hint">Displayed across the portal</span>
                        </div>

                    </div>
                </div>

                <!-- ── Role & Organization ── -->
                <div class="form-section">
                    <div class="form-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                        Role &amp; Organization
                    </div>

                    <div class="form-grid">

                        <div class="form-field">
                            <label class="field-label" for="role">
                                User Role <span class="required-star">*</span>
                            </label>
                            <div class="input-wrap select-wrap">
                                <span class="input-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                                </span>
                                <select id="role" name="role" required onchange="handleRoleChange()">
                                    <option value="">Select a role…</option>
                                    <option value="Admin">Admin</option>
                                    <option value="DLC Office">DLC Office</option>
                                    <option value="ATC CENTER">ATC Center</option>
                                </select>
                            </div>
                            <span class="field-hint">Determines access &amp; permissions</span>
                        </div>

                        <div class="form-field">
                            <label class="field-label" for="status">Account Status</label>
                            <div class="input-wrap select-wrap">
                                <span class="input-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                </span>
                                <select id="status" name="status">
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                            <span class="field-hint">Inactive users cannot log in</span>
                        </div>

                        <div class="form-field" id="dlcField" style="display: none;">
                            <label class="field-label" for="dlc_id">
                                DLC Office <span class="required-star" id="dlcRequired">*</span>
                            </label>
                            <div class="input-wrap select-wrap">
                                <span class="input-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg>
                                </span>
                                <select id="dlc_id" name="dlc_id" onchange="handleDLCChange()">
                                    <option value="">Select DLC Office…</option>
                                    <?php foreach ($dlcOffices as $dlc): ?>
                                        <option value="<?= $dlc['id'] ?>"><?= htmlspecialchars($dlc['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-field" id="atcField" style="display: none;">
                            <label class="field-label" for="atc_id">
                                ATC Center <span class="required-star" id="atcRequired">*</span>
                            </label>
                            <div class="input-wrap select-wrap">
                                <span class="input-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                                </span>
                                <select id="atc_id" name="atc_id">
                                    <option value="">Select ATC Center…</option>
                                    <?php foreach ($atcCenters as $atc): ?>
                                        <option value="<?= $atc['id'] ?>" data-dlc="<?= $atc['dlc_id'] ?>"><?= htmlspecialchars($atc['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Training Login Section (ATC only) -->
                        <div class="form-field span-2" id="trainingSection" style="display:none;">
                            <div style="background:linear-gradient(135deg,#f5f3ff,#eef1fd);border:1.5px solid #ddd6fe;border-radius:12px;padding:1.125rem 1.25rem;margin-top:.25rem;">
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;">
                                    <div style="display:flex;align-items:center;gap:.5rem;">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                        <span style="font-size:.8125rem;font-weight:800;color:#5b21b6;">Training Login</span>
                                        <span class="training-status-badge" id="trainingStatusBadge" style="display:none;font-size:.68rem;font-weight:800;padding:.15rem .5rem;border-radius:99px;"></span>
                                    </div>
                                    <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-size:.78rem;font-weight:700;color:#6d28d9;margin:0;">
                                        <input type="checkbox" id="createTraining" name="create_training" value="1" style="width:auto;accent-color:#7c3aed;">
                                        Enable
                                    </label>
                                </div>
                                <div id="trainingFields" style="display:none;">
                                    <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-size:.78rem;font-weight:600;color:#6d28d9;margin:0 0 .75rem;">
                                        <input type="checkbox" id="sameCredentials" style="width:auto;accent-color:#7c3aed;" onchange="handleSameCredentials()">
                                        <span>Use same credentials as ATC login</span>
                                    </label>
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
                                        <div>
                                            <label class="field-label" for="training_username" style="margin:0 0 .25rem;font-size:.75rem;">Training Username</label>
                                            <input type="text" id="training_username" name="training_username" placeholder="training_username" autocomplete="off"
                                                   style="padding:.6rem .75rem;border:1.5px solid #ddd6fe;border-radius:8px;font-size:.85rem;">
                                        </div>
                                        <div>
                                            <label class="field-label" for="training_password" style="margin:0 0 .25rem;font-size:.75rem;">Training Password</label>
                                            <input type="password" id="training_password" name="training_password" placeholder="Set password" autocomplete="new-password"
                                                   style="padding:.6rem .75rem;border:1.5px solid #ddd6fe;border-radius:8px;font-size:.85rem;">
                                        </div>
                                    </div>
                                    <div style="margin-top:.5rem;font-size:.72rem;color:#7c3aed;font-weight:500;">
                                        ⓘ Training users can only view videos assigned to this ATC center
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ── Contact Information ── -->
                <div class="form-section">
                    <div class="form-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        Contact Information
                    </div>

                    <div class="form-grid">

                        <div class="form-field">
                            <label class="field-label" for="mobile">Mobile Number</label>
                            <div class="input-wrap">
                                <span class="input-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                                </span>
                                <input type="tel" id="mobile" name="mobile"
                                       placeholder="9876543210"
                                       pattern="[0-9]{10}" maxlength="10" autocomplete="off">
                            </div>
                            <span class="field-hint">10-digit number, no spaces</span>
                        </div>

                        <div class="form-field">
                            <label class="field-label" for="email">Email Address</label>
                            <div class="input-wrap">
                                <span class="input-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                </span>
                                <input type="email" id="email" name="email"
                                       placeholder="user@example.com"
                                       maxlength="100" autocomplete="off">
                            </div>
                            <span class="field-hint">Used for notifications &amp; recovery</span>
                        </div>

                        <div class="form-field">
                            <label class="field-label" for="date_of_birth">Date of Birth</label>
                            <div class="input-wrap">
                                <span class="input-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                </span>
                                <input type="date" id="date_of_birth" name="date_of_birth" autocomplete="off">
                            </div>
                            <span class="field-hint">Used for birthday alerts on dashboard</span>
                        </div>

                    </div>
                </div>

            </div><!-- /modal-body -->

            <div class="modal-footer">
                <button type="button" class="modal-btn modal-btn-ghost" onclick="closeModal()">
                    Cancel
                </button>
                <button type="submit" class="modal-btn modal-btn-primary" id="submitBtn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <span id="submitBtnText">Add User</span>
                </button>
            </div>
        </form>

    </div>
</div>


<!-- ============================================================
     RESET PASSWORD MODAL
     ============================================================ -->
<div class="modal-overlay" id="resetPasswordModal">
    <div class="modal-panel" style="max-width: 480px;">

        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon amber">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </div>
                <div>
                    <div class="modal-title">Reset Password</div>
                    <div class="modal-subtitle">Set a new password for this account</div>
                </div>
            </div>
            <button type="button" class="btn-close" onclick="closeResetPasswordModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <form id="resetPasswordForm" novalidate>
            <input type="hidden" id="resetUserId" name="id">
            <input type="hidden" name="action" value="reset_password">

            <div class="modal-body">

                <!-- User context banner -->
                <div class="reset-user-banner">
                    <div class="reset-user-avatar" id="resetUserAvatar">J</div>
                    <div class="reset-user-info">
                        <div class="reset-user-name" id="resetUsername">—</div>
                        <div class="reset-user-note">New password will take effect immediately</div>
                    </div>
                    <span class="reset-user-badge">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        Sensitive action
                    </span>
                </div>

                <!-- New Password -->
                <div class="form-section" style="margin-top: 1.5rem;">
                    <div class="form-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        New Password
                    </div>

                    <div class="form-field">
                        <label class="field-label" for="new_password">
                            Password <span class="required-star">*</span>
                        </label>
                        <div class="input-wrap">
                            <span class="input-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            </span>
                            <input type="password" id="new_password" name="new_password"
                                   placeholder="Enter new password"
                                   required minlength="6" autocomplete="new-password">
                            <button type="button" class="input-toggle-pwd" tabindex="-1" aria-label="Toggle password visibility">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                        <span class="field-hint">Minimum 6 characters recommended</span>

                        <!-- Strength meter -->
                        <div class="pwd-strength-wrap" id="resetPwdStrengthWrap" style="display:none;">
                            <div class="pwd-strength-bar">
                                <div class="pwd-strength-fill" id="resetPwdStrengthFill"></div>
                            </div>
                            <span class="pwd-strength-label" id="resetPwdStrengthLabel">Weak</span>
                        </div>
                    </div>
                </div>

            </div><!-- /modal-body -->

            <div class="modal-footer">
                <button type="button" class="modal-btn modal-btn-ghost" onclick="closeResetPasswordModal()">
                    Cancel
                </button>
                <button type="submit" class="modal-btn modal-btn-amber">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Reset Password
                </button>
            </div>
        </form>

    </div>
</div>


<!-- ============================================================
     MODAL STYLES  (scoped — paste once in your <head> or CSS file)
     ============================================================ -->
<style>
/* ── Variables (extend your root block if already defined) ── */
:root {
    --modal-font: 'Sora', sans-serif;
    --modal-bg: #ffffff;
    --modal-border: #e4e8f0;
    --modal-surface: #f8f9fc;
    --modal-text: #0f1523;
    --modal-muted: #8896a5;
    --modal-secondary: #4a5568;
    --modal-radius-sm: 6px;
    --modal-radius-md: 10px;
    --modal-radius-lg: 14px;
    --modal-radius-xl: 20px;
    --modal-indigo: #4f6ef7;
    --modal-indigo-soft: #eef1fe;
    --modal-amber: #f59e0b;
    --modal-amber-soft: #fffbeb;
    --modal-rose: #f43f5e;
    --modal-rose-soft: #fff1f3;
    --modal-emerald: #00c48c;
    --modal-emerald-soft: #e6faf4;
    --modal-shadow-lg: 0 24px 64px rgba(0,0,0,0.14), 0 8px 24px rgba(0,0,0,0.08);
}

/* ── Overlay ── */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(10, 15, 30, 0.55);
    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);
    z-index: 1000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
    font-family: var(--modal-font);
}

.modal-overlay.active { display: flex; }

/* ── Panel ── */
.modal-panel {
    background: var(--modal-bg);
    border-radius: var(--modal-radius-xl);
    border: 1px solid var(--modal-border);
    box-shadow: var(--modal-shadow-lg);
    width: 100%;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    animation: modalSlideIn 0.28s cubic-bezier(0.34, 1.56, 0.64, 1);
    overflow: hidden;
}

@keyframes modalSlideIn {
    from { opacity: 0; transform: scale(0.94) translateY(12px); }
    to   { opacity: 1; transform: scale(1)    translateY(0);    }
}

/* ── Header ── */
.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.25rem 1.75rem;
    border-bottom: 1px solid var(--modal-border);
    background: var(--modal-surface);
    flex-shrink: 0;
}

.modal-header-left {
    display: flex;
    align-items: center;
    gap: 0.875rem;
}

.modal-icon {
    width: 42px;
    height: 42px;
    border-radius: var(--modal-radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.modal-icon svg { width: 20px; height: 20px; }
.modal-icon.indigo { background: var(--modal-indigo-soft); }
.modal-icon.indigo svg { stroke: var(--modal-indigo); }
.modal-icon.amber  { background: var(--modal-amber-soft); }
.modal-icon.amber  svg { stroke: var(--modal-amber); }

.modal-title {
    font-size: 1.0625rem;
    font-weight: 800;
    color: var(--modal-text);
    letter-spacing: -0.02em;
    line-height: 1.2;
}

.modal-subtitle {
    font-size: 0.8rem;
    color: var(--modal-muted);
    margin-top: 0.15rem;
}

.btn-close {
    width: 34px;
    height: 34px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: 1.5px solid var(--modal-border);
    border-radius: var(--modal-radius-md);
    cursor: pointer;
    transition: all 0.18s ease;
    flex-shrink: 0;
}
.btn-close svg { width: 15px; height: 15px; stroke: var(--modal-muted); transition: stroke 0.18s; }
.btn-close:hover { background: var(--modal-rose-soft); border-color: #fca5a5; }
.btn-close:hover svg { stroke: var(--modal-rose); }

/* ── Body ── */
.modal-body {
    padding: 1.75rem;
    overflow-y: auto;
    flex: 1;
    max-height: calc(90vh - 200px);
}

/* Custom scrollbar for modal body */
.modal-body::-webkit-scrollbar {
    width: 8px;
}

.modal-body::-webkit-scrollbar-track {
    background: var(--modal-surface);
    border-radius: 10px;
}

.modal-body::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
    transition: background 0.2s;
}

.modal-body::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* ── Form sections ── */
.form-section {
    margin-bottom: 1.75rem;
}

.form-section:last-child { margin-bottom: 0; }

.form-section-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.75rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--modal-secondary);
    padding-bottom: 0.875rem;
    margin-bottom: 1.125rem;
    border-bottom: 1px solid var(--modal-border);
}
.form-section-title svg { width: 15px; height: 15px; stroke: var(--modal-indigo); flex-shrink: 0; }

/* ── Form grid ── */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.125rem;
}
.form-field.span-2 { grid-column: 1 / -1; }

/* ── Individual field ── */
.form-field {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}

.field-label {
    font-size: 0.8125rem;
    font-weight: 700;
    color: var(--modal-secondary);
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.required-star { color: var(--modal-rose); font-size: 0.875rem; }

/* ── Input wrapper ── */
.input-wrap {
    position: relative;
    display: flex;
    align-items: center;
}

.input-icon {
    position: absolute;
    left: 0.875rem;
    display: flex;
    pointer-events: none;
    z-index: 1;
}
.input-icon svg { width: 16px; height: 16px; stroke: var(--modal-muted); transition: stroke 0.18s; }

.input-wrap input,
.input-wrap select {
    width: 100%;
    padding: 0.75rem 0.875rem 0.75rem 2.625rem;
    border: 1.5px solid var(--modal-border);
    border-radius: var(--modal-radius-md);
    font-size: 0.9rem;
    font-family: var(--modal-font);
    font-weight: 500;
    background: var(--modal-surface);
    color: var(--modal-text);
    outline: none;
    transition: all 0.18s ease;
    appearance: none;
    -webkit-appearance: none;
}

.select-wrap input,
.select-wrap select {
    padding-right: 2.5rem;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238896a5' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 0.75rem center;
    background-size: 15px;
    cursor: pointer;
}

.input-wrap input::placeholder { color: #b0bac8; font-weight: 400; }
.input-wrap input:hover, .input-wrap select:hover { border-color: #cdd3e0; background: #fff; }
.input-wrap input:focus, .input-wrap select:focus {
    border-color: var(--modal-indigo);
    background: #fff;
    box-shadow: 0 0 0 3px rgba(79,110,247,0.1);
}

.input-wrap:focus-within .input-icon svg { stroke: var(--modal-indigo); }

/* password toggle */
.input-toggle-pwd {
    position: absolute;
    right: 0.75rem;
    background: transparent;
    border: none;
    cursor: pointer;
    padding: 0.25rem;
    display: flex;
    color: var(--modal-muted);
    transition: color 0.18s;
}
.input-toggle-pwd:hover { color: var(--modal-indigo); }
.input-toggle-pwd svg { width: 16px; height: 16px; stroke: currentColor; }

.input-wrap:has(.input-toggle-pwd) input { padding-right: 2.75rem; }

/* ── Hints ── */
.field-hint {
    font-size: 0.775rem;
    color: var(--modal-muted);
    font-weight: 500;
    line-height: 1.4;
}

/* ── Footer ── */
.modal-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.75rem;
    padding: 1.125rem 1.75rem;
    border-top: 1px solid var(--modal-border);
    background: var(--modal-surface);
    flex-shrink: 0;
}

.modal-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.7rem 1.5rem;
    border-radius: var(--modal-radius-md);
    font-size: 0.875rem;
    font-weight: 700;
    font-family: var(--modal-font);
    cursor: pointer;
    border: none;
    transition: all 0.2s ease;
    white-space: nowrap;
}
.modal-btn svg { width: 15px; height: 15px; }

.modal-btn-ghost {
    background: transparent;
    border: 1.5px solid var(--modal-border);
    color: var(--modal-secondary);
}
.modal-btn-ghost:hover {
    background: var(--modal-rose-soft);
    border-color: #fca5a5;
    color: var(--modal-rose);
}

.modal-btn-primary {
    background: linear-gradient(135deg, #4f6ef7, #3a57e8);
    color: white;
    box-shadow: 0 4px 14px rgba(79,110,247,0.3);
}
.modal-btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 20px rgba(79,110,247,0.35);
}
.modal-btn-primary:active { transform: translateY(0); }

.modal-btn-amber {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
    box-shadow: 0 4px 14px rgba(245,158,11,0.28);
}
.modal-btn-amber:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 20px rgba(245,158,11,0.35);
}
.modal-btn-amber:active { transform: translateY(0); }

/* ── Reset user banner ── */
.reset-user-banner {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.125rem 1.25rem;
    background: linear-gradient(135deg, #fffbeb, #fef3c7);
    border: 1.5px solid #fde68a;
    border-radius: var(--modal-radius-lg);
}

.reset-user-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    font-weight: 800;
    flex-shrink: 0;
    letter-spacing: -0.02em;
    box-shadow: 0 4px 12px rgba(245,158,11,0.3);
}

.reset-user-info { flex: 1; min-width: 0; }

.reset-user-name {
    font-size: 0.9375rem;
    font-weight: 700;
    color: #78350f;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.reset-user-note {
    font-size: 0.775rem;
    color: #92400e;
    margin-top: 0.15rem;
}

.reset-user-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.3rem 0.75rem;
    background: white;
    border: 1.5px solid #fde68a;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
    color: #92400e;
    white-space: nowrap;
}
.reset-user-badge svg { width: 13px; height: 13px; stroke: var(--modal-amber); }

/* ── Password strength ── */
.pwd-strength-wrap {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-top: 0.5rem;
}

.pwd-strength-bar {
    flex: 1;
    height: 5px;
    background: var(--modal-border);
    border-radius: 999px;
    overflow: hidden;
}

.pwd-strength-fill {
    height: 100%;
    border-radius: 999px;
    transition: width 0.3s ease, background 0.3s ease;
}

.pwd-strength-label {
    font-size: 0.75rem;
    font-weight: 700;
    min-width: 42px;
    text-align: right;
    color: var(--modal-muted);
}

/* ── Responsive ── */
@media (max-width: 600px) {
    .form-grid { grid-template-columns: 1fr; }
    .form-field.span-2 { grid-column: 1; }
    .modal-body { padding: 1.25rem; }
    .modal-footer { padding: 1rem 1.25rem; }
    .reset-user-badge { display: none; }
}
</style>

<!-- ── Password toggle + strength meter script ── -->
<script>
// Toggle password visibility for any .input-toggle-pwd button
document.querySelectorAll('.input-toggle-pwd').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = btn.closest('.input-wrap').querySelector('input');
        if (!input) return;
        const isText = input.type === 'text';
        input.type = isText ? 'password' : 'text';
        btn.querySelector('svg').style.opacity = isText ? '1' : '0.5';
    });
});

// Strength meter for reset password modal
const newPwd = document.getElementById('new_password');
if (newPwd) {
    newPwd.addEventListener('input', function () {
        const v = this.value;
        const wrap = document.getElementById('resetPwdStrengthWrap');
        const fill = document.getElementById('resetPwdStrengthFill');
        const label = document.getElementById('resetPwdStrengthLabel');
        if (!wrap) return;

        if (!v) { wrap.style.display = 'none'; return; }
        wrap.style.display = 'flex';

        let score = 0;
        if (v.length >= 6)  score++;
        if (v.length >= 10) score++;
        if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
        if (/\d/.test(v)) score++;
        if (/[^A-Za-z0-9]/.test(v)) score++;

        const levels = [
            { pct: '20%',  color: '#ef4444', text: 'Weak'     },
            { pct: '40%',  color: '#f97316', text: 'Fair'     },
            { pct: '60%',  color: '#f59e0b', text: 'Good'     },
            { pct: '80%',  color: '#84cc16', text: 'Strong'   },
            { pct: '100%', color: '#00c48c', text: 'Very strong' },
        ];
        const lvl = levels[Math.min(score - 1, 4)] || levels[0];
        fill.style.width = lvl.pct;
        fill.style.background = lvl.color;
        label.textContent = lvl.text;
        label.style.color = lvl.color;
    });
}
</script>