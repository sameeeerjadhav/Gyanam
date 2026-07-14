/**
 * LoginPage - Premium redesign for the Gyanam Exam Portal
 * Split-panel layout: branded left panel + glassmorphic login form right panel
 */

import AuthenticationModule from '../services/AuthenticationModule.js';
import router from '../services/Router.js';

class LoginPage {
  constructor(authModule = null) {
    this.authModule = authModule || new AuthenticationModule();
    this.isSubmitting = false;
  }

  render(container) {
    container.innerHTML = this._getLoginHTML();
    this._injectStyles();
    this._attachEventListeners();
  }

  _injectStyles() {
    if (document.getElementById('gep-login-styles')) return;
    const style = document.createElement('style');
    style.id = 'gep-login-styles';
    style.textContent = `
      @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');

      .gep-root * { box-sizing: border-box; margin: 0; padding: 0; }

      .gep-root {
        min-height: 100vh;
        display: flex;
        font-family: 'Inter', sans-serif;
        background: #0f172a;
      }

      /* ── Left panel ── */
      .gep-left {
        flex: 1.1;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 3rem;
        background: linear-gradient(145deg, #1e1b4b 0%, #312e81 40%, #1d4ed8 100%);
        position: relative;
        overflow: hidden;
      }

      .gep-left::before {
        content: '';
        position: absolute;
        width: 500px; height: 500px;
        background: radial-gradient(circle, rgba(139,92,246,0.35) 0%, transparent 70%);
        top: -120px; left: -120px;
        border-radius: 50%;
        animation: gepFloat 8s ease-in-out infinite;
      }

      .gep-left::after {
        content: '';
        position: absolute;
        width: 350px; height: 350px;
        background: radial-gradient(circle, rgba(16,185,129,0.2) 0%, transparent 70%);
        bottom: -80px; right: -80px;
        border-radius: 50%;
        animation: gepFloat 10s ease-in-out infinite reverse;
      }

      @keyframes gepFloat {
        0%, 100% { transform: translateY(0) scale(1); }
        50%       { transform: translateY(-30px) scale(1.05); }
      }

      .gep-brand { position: relative; z-index: 1; }

      .gep-brand-logo {
        width: 56px; height: 56px;
        background: linear-gradient(135deg, rgba(255,255,255,0.2), rgba(255,255,255,0.05));
        border: 1.5px solid rgba(255,255,255,0.2);
        border-radius: 16px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.75rem;
        backdrop-filter: blur(8px);
        margin-bottom: 1.25rem;
        box-shadow: 0 8px 32px rgba(0,0,0,0.2);
      }

      .gep-brand-name {
        font-size: 1.5rem;
        font-weight: 800;
        color: #fff;
        letter-spacing: -0.02em;
        line-height: 1;
      }

      .gep-brand-tagline {
        font-size: 0.8rem;
        color: rgba(255,255,255,0.55);
        font-weight: 500;
        margin-top: 0.35rem;
        letter-spacing: 0.04em;
        text-transform: uppercase;
      }

      .gep-hero { position: relative; z-index: 1; flex: 1; display: flex; flex-direction: column; justify-content: center; padding: 2rem 0; }

      .gep-hero-title {
        font-size: 2.75rem;
        font-weight: 900;
        color: #fff;
        line-height: 1.1;
        letter-spacing: -0.03em;
        margin-bottom: 1rem;
      }

      .gep-hero-title span { color: #a5f3fc; }

      .gep-hero-desc {
        font-size: 1rem;
        color: rgba(255,255,255,0.65);
        line-height: 1.65;
        max-width: 380px;
        font-weight: 400;
      }

      .gep-features {
        margin-top: 2.5rem;
        display: flex;
        flex-direction: column;
        gap: 0.85rem;
      }

      .gep-feature {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        animation: gepFadeInLeft 0.6s ease both;
      }

      .gep-feature:nth-child(1) { animation-delay: 0.1s; }
      .gep-feature:nth-child(2) { animation-delay: 0.2s; }
      .gep-feature:nth-child(3) { animation-delay: 0.3s; }

      @keyframes gepFadeInLeft {
        from { opacity: 0; transform: translateX(-16px); }
        to   { opacity: 1; transform: translateX(0); }
      }

      .gep-feature-icon {
        width: 36px; height: 36px;
        background: rgba(255,255,255,0.1);
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1rem;
        flex-shrink: 0;
      }

      .gep-feature-text { font-size: 0.875rem; color: rgba(255,255,255,0.75); font-weight: 500; }

      .gep-footer-left {
        position: relative; z-index: 1;
        font-size: 0.75rem;
        color: rgba(255,255,255,0.3);
        font-weight: 500;
      }

      /* ── Right panel ── */
      .gep-right {
        flex: 0 0 480px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2rem;
        background: #f8fafc;
      }

      .gep-card {
        width: 100%;
        max-width: 400px;
        animation: gepSlideUp 0.5s cubic-bezier(0.4, 0, 0.2, 1) both;
      }

      @keyframes gepSlideUp {
        from { opacity: 0; transform: translateY(24px); }
        to   { opacity: 1; transform: translateY(0); }
      }

      .gep-card-header { margin-bottom: 2rem; }

      .gep-card-title {
        font-size: 1.75rem;
        font-weight: 800;
        color: #0f172a;
        letter-spacing: -0.03em;
        line-height: 1.15;
      }

      .gep-card-sub {
        font-size: 0.9rem;
        color: #64748b;
        margin-top: 0.4rem;
        font-weight: 400;
        line-height: 1.5;
      }

      /* Error banner */
      .gep-error {
        display: none;
        align-items: flex-start;
        gap: 0.6rem;
        padding: 0.875rem 1rem;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 12px;
        margin-bottom: 1.5rem;
        animation: gepShake 0.4s ease;
      }

      @keyframes gepShake {
        0%, 100% { transform: translateX(0); }
        20%, 60% { transform: translateX(-4px); }
        40%, 80% { transform: translateX(4px); }
      }

      .gep-error-icon { font-size: 0.9rem; margin-top: 0.05rem; flex-shrink: 0; }
      .gep-error-text { color: #b91c1c; font-size: 0.845rem; font-weight: 500; line-height: 1.5; }

      /* Form fields */
      .gep-field { margin-bottom: 1.25rem; }

      .gep-label {
        display: block;
        font-size: 0.8rem;
        font-weight: 700;
        color: #374151;
        margin-bottom: 0.5rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
      }

      .gep-input-wrap { position: relative; }

      .gep-input-icon {
        position: absolute;
        left: 0.9rem; top: 50%; transform: translateY(-50%);
        color: #94a3b8;
        width: 18px; height: 18px;
        pointer-events: none;
        transition: color 0.2s;
      }

      .gep-input {
        width: 100%;
        padding: 0.8rem 1rem 0.8rem 2.75rem;
        background: #fff;
        border: 1.5px solid #e2e8f0;
        border-radius: 12px;
        color: #0f172a;
        font-size: 0.9375rem;
        font-family: 'Inter', sans-serif;
        outline: none;
        transition: border-color 0.2s, box-shadow 0.2s;
      }

      .gep-input:focus {
        border-color: #4f46e5;
        box-shadow: 0 0 0 4px rgba(79,70,229,0.1);
      }

      .gep-input:focus + .gep-input-icon,
      .gep-input-wrap:focus-within .gep-input-icon {
        color: #4f46e5;
      }

      .gep-pw-toggle {
        position: absolute;
        right: 0.875rem; top: 50%; transform: translateY(-50%);
        background: none; border: none; cursor: pointer;
        color: #94a3b8;
        padding: 0.25rem;
        border-radius: 6px;
        display: flex; align-items: center;
        transition: color 0.2s;
      }

      .gep-pw-toggle:hover { color: #4f46e5; }

      .gep-field-err {
        display: none;
        font-size: 0.78rem;
        color: #dc2626;
        font-weight: 500;
        margin-top: 0.4rem;
      }

      /* Submit button */
      .gep-btn {
        width: 100%;
        padding: 0.9rem;
        background: linear-gradient(135deg, #4f46e5, #3730a3);
        color: #fff;
        border: none;
        border-radius: 12px;
        font-size: 0.9375rem;
        font-weight: 700;
        font-family: 'Inter', sans-serif;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center; gap: 0.5rem;
        transition: all 0.2s;
        box-shadow: 0 4px 14px rgba(79,70,229,0.35);
        margin-top: 0.5rem;
      }

      .gep-btn:hover:not(:disabled) {
        transform: translateY(-1px);
        box-shadow: 0 8px 22px rgba(79,70,229,0.4);
      }

      .gep-btn:active:not(:disabled) { transform: translateY(0); }

      .gep-btn:disabled { opacity: 0.65; cursor: not-allowed; }

      .gep-spinner {
        width: 18px; height: 18px;
        border: 2.5px solid rgba(255,255,255,0.35);
        border-top-color: #fff;
        border-radius: 50%;
        animation: gepSpin 0.65s linear infinite;
      }

      @keyframes gepSpin { to { transform: rotate(360deg); } }

      .gep-divider {
        display: flex; align-items: center; gap: 0.75rem;
        margin: 1.5rem 0;
        color: #cbd5e1;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.08em;
      }

      .gep-divider::before, .gep-divider::after {
        content: ''; flex: 1; height: 1px; background: #e2e8f0;
      }

      .gep-admin-link {
        text-align: center;
        font-size: 0.85rem;
        color: #64748b;
        font-weight: 500;
      }

      .gep-admin-link a {
        color: #4f46e5;
        font-weight: 700;
        text-decoration: none;
        transition: color 0.2s;
      }

      .gep-admin-link a:hover { color: #3730a3; text-decoration: underline; }

      .gep-card-footer {
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid #f1f5f9;
        text-align: center;
        font-size: 0.75rem;
        color: #94a3b8;
        font-weight: 500;
        line-height: 1.6;
      }

      /* Responsive */
      @media (max-width: 900px) {
        .gep-left { display: none; }
        .gep-right { flex: 1; background: linear-gradient(145deg, #1e1b4b, #312e81); }
        .gep-card { background: rgba(255,255,255,0.97); border-radius: 20px; padding: 2rem; box-shadow: 0 24px 64px rgba(0,0,0,0.25); }
        .gep-card-title { color: #0f172a; }
      }
    `;
    document.head.appendChild(style);
  }

  _getLoginHTML() {
    return `
    <div class="gep-root">

      <!-- ═══ Left Branding Panel ═══ -->
      <div class="gep-left">

        <div class="gep-brand">
          <div class="gep-brand-logo"><img src="assets/logo.png" alt="Gyanam" style="width:40px;height:40px;border-radius:8px;object-fit:contain;"></div>
          <div class="gep-brand-name">Gyanam India</div>
          <div class="gep-brand-tagline">Authorised Training Centre Network</div>
        </div>

        <div class="gep-hero">
          <div class="gep-hero-title">
            Your exam,<br><span>your future.</span>
          </div>
          <p class="gep-hero-desc">
            Secure, timed, and proctored online examinations delivered
            directly to you. Log in with your Registration ID to begin.
          </p>

          <div class="gep-features">
            <div class="gep-feature">
              <div class="gep-feature-icon">🔒</div>
              <span class="gep-feature-text">Secure & encrypted exam sessions</span>
            </div>
            <div class="gep-feature">
              <div class="gep-feature-icon">⏱</div>
              <span class="gep-feature-text">Real-time timer with auto-submit</span>
            </div>
            <div class="gep-feature">
              <div class="gep-feature-icon">📊</div>
              <span class="gep-feature-text">Instant results & score breakdown</span>
            </div>
          </div>
        </div>

        <div class="gep-footer-left">© 2025 Gyanam India. All rights reserved.</div>
      </div>

      <!-- ═══ Right Login Panel ═══ -->
      <div class="gep-right">
        <div class="gep-card">

          <div class="gep-card-header">
            <div class="gep-card-title">Welcome back</div>
            <p class="gep-card-sub">Enter your Registration ID &amp; password to access your exam</p>
          </div>

          <!-- Error Banner -->
          <div id="gep-error" class="gep-error">
            <span class="gep-error-icon">⚠</span>
            <p id="gep-error-text" class="gep-error-text"></p>
          </div>

          <!-- Form -->
          <form id="gep-login-form" novalidate>

            <div class="gep-field">
              <label for="gep-identifier" class="gep-label">Registration ID</label>
              <div class="gep-input-wrap">
                <input
                  type="text"
                  id="gep-identifier"
                  name="identifier"
                  autocomplete="username"
                  placeholder="e.g. GYANAM1"
                  class="gep-input"
                  required
                />
                <svg class="gep-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                  <circle cx="12" cy="7" r="4"/>
                </svg>
              </div>
              <p id="gep-identifier-err" class="gep-field-err"></p>
            </div>

            <div class="gep-field">
              <label for="gep-password" class="gep-label">Password</label>
              <div class="gep-input-wrap">
                <input
                  type="password"
                  id="gep-password"
                  name="password"
                  autocomplete="current-password"
                  placeholder="Enter your password"
                  class="gep-input"
                  style="padding-right:3rem"
                  required
                />
                <svg class="gep-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <rect x="3" y="11" width="18" height="11" rx="2"/>
                  <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
                <button type="button" id="gep-pw-toggle" class="gep-pw-toggle" title="Toggle password visibility">
                  <svg id="gep-eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="17" height="17">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                  </svg>
                </button>
              </div>
              <p id="gep-password-err" class="gep-field-err"></p>
            </div>

            <button type="submit" id="gep-submit" class="gep-btn">
              <span id="gep-btn-text">Login to Portal</span>
              <span id="gep-btn-loading" style="display:none" class="gep-spinner"></span>
            </button>

          </form>

          <div class="gep-divider">or</div>

          <div class="gep-admin-link">
            Admin / ATC? <a href="admin.html">Go to Admin Portal &rarr;</a>
          </div>

          <div class="gep-card-footer">
            Default password for new students is <strong>password</strong>.<br>
            Contact your ATC if you face any login issues.
          </div>

        </div>
      </div>

    </div>
    `;
  }

  _attachEventListeners() {
    const form = document.getElementById('gep-login-form');
    if (form) form.addEventListener('submit', this._handleSubmit.bind(this));

    // Password visibility toggle
    const toggle = document.getElementById('gep-pw-toggle');
    const pwInput = document.getElementById('gep-password');
    const eyeIcon = document.getElementById('gep-eye-icon');
    if (toggle && pwInput) {
      toggle.addEventListener('click', () => {
        const isHidden = pwInput.type === 'password';
        pwInput.type = isHidden ? 'text' : 'password';
        eyeIcon.innerHTML = isHidden
          ? `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
             <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
             <line x1="1" y1="1" x2="23" y2="23"/>`
          : `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
             <circle cx="12" cy="12" r="3"/>`;
      });
    }

    // Clear field errors on input
    document.getElementById('gep-identifier')?.addEventListener('input', () => this._clearFieldErr('identifier'));
    document.getElementById('gep-password')?.addEventListener('input', () => this._clearFieldErr('password'));
  }

  async _handleSubmit(event) {
    event.preventDefault();
    if (this.isSubmitting) return;

    this._hideError();
    this._clearFieldErr('identifier');
    this._clearFieldErr('password');

    const identifier = document.getElementById('gep-identifier')?.value.trim();
    const password   = document.getElementById('gep-password')?.value;

    if (!identifier) { this._showFieldErr('identifier', 'Registration ID is required.'); return; }
    if (!password)   { this._showFieldErr('password', 'Password is required.'); return; }

    this._setLoading(true);
    this.isSubmitting = true;

    try {
      const result = await this.authModule.authenticate({ identifier, password });
      if (result.success) router.navigate('/student');
    } catch (error) {
      this._showError(error.message);
    } finally {
      this._setLoading(false);
      this.isSubmitting = false;
    }
  }

  _showError(message) {
    const el  = document.getElementById('gep-error');
    const txt = document.getElementById('gep-error-text');
    if (el && txt) {
      txt.textContent = message;
      el.style.display = 'flex';
      // Re-trigger shake animation
      el.style.animation = 'none';
      el.offsetHeight; // force reflow
      el.style.animation = '';
    }
  }

  _hideError() {
    const el = document.getElementById('gep-error');
    if (el) el.style.display = 'none';
  }

  _showFieldErr(field, message) {
    const err = document.getElementById(`gep-${field}-err`);
    const input = document.getElementById(`gep-${field}`);
    if (err) { err.textContent = message; err.style.display = 'block'; }
    if (input) input.style.borderColor = '#dc2626';
  }

  _clearFieldErr(field) {
    const err = document.getElementById(`gep-${field}-err`);
    const input = document.getElementById(`gep-${field}`);
    if (err) err.style.display = 'none';
    if (input) input.style.borderColor = '';
  }

  _setLoading(loading) {
    const btn  = document.getElementById('gep-submit');
    const txt  = document.getElementById('gep-btn-text');
    const spin = document.getElementById('gep-btn-loading');
    if (btn)  btn.disabled = loading;
    if (txt)  txt.style.display = loading ? 'none' : 'inline';
    if (spin) spin.style.display = loading ? 'inline-block' : 'none';
  }

  destroy() {
    const style = document.getElementById('gep-login-styles');
    if (style) style.remove();
  }
}

export default LoginPage;
export { LoginPage };

