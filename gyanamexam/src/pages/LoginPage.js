/**
 * LoginPage - Gyanam Exam Portal student login
 * Split-panel layout with natural logo display (no card wrapper)
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
      @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');

      .gep-root * { box-sizing: border-box; margin: 0; padding: 0; }

      .gep-root {
        min-height: 100vh;
        display: flex;
        font-family: 'Inter', sans-serif;
        background: #0f172a;
      }

      /* ── Left panel ── */
      .gep-left {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 3rem 2.5rem;
        position: relative;
        overflow: hidden;
      }

      .gep-left::before {
        content: '';
        position: absolute;
        inset: 0;
        background:
          radial-gradient(ellipse 600px 500px at 20% 30%, rgba(99, 102, 241, 0.28), transparent),
          radial-gradient(ellipse 500px 600px at 80% 70%, rgba(16, 185, 129, 0.14), transparent),
          radial-gradient(ellipse 400px 400px at 50% 50%, rgba(59, 130, 246, 0.1), transparent),
          linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
        z-index: 0;
      }

      .gep-orb {
        position: absolute;
        border-radius: 50%;
        filter: blur(80px);
        opacity: 0.4;
        pointer-events: none;
        z-index: 0;
        animation: gepOrbFloat 20s ease-in-out infinite;
      }

      .gep-orb-1 {
        width: 300px; height: 300px;
        background: linear-gradient(135deg, #6366f1, #3b82f6);
        top: -5%; left: -8%;
      }

      .gep-orb-2 {
        width: 250px; height: 250px;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        bottom: -10%; right: -5%;
        animation-delay: -7s;
      }

      .gep-orb-3 {
        width: 180px; height: 180px;
        background: linear-gradient(135deg, #8b5cf6, #ec4899);
        top: 60%; left: 60%;
        animation-delay: -14s;
        opacity: 0.25;
      }

      @keyframes gepOrbFloat {
        0%, 100% { transform: translate(0, 0) scale(1); }
        25%      { transform: translate(40px, -30px) scale(1.1); }
        50%      { transform: translate(-20px, 40px) scale(0.9); }
        75%      { transform: translate(30px, 20px) scale(1.05); }
      }

      .gep-grid-bg {
        position: absolute;
        inset: 0;
        background-image:
          linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
          linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
        background-size: 50px 50px;
        z-index: 0;
        pointer-events: none;
      }

      .gep-left-content {
        position: relative;
        z-index: 1;
        max-width: 420px;
        text-align: center;
      }

      /* Logo — natural display, no card */
      .gep-brand-logo {
        margin-bottom: 1.75rem;
        animation: gepLogoEnter 1s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        opacity: 0;
        transform: scale(0.85) translateY(16px);
      }

      .gep-brand-logo img {
        width: 180px;
        height: auto;
        display: block;
        margin: 0 auto;
        object-fit: contain;
        filter: drop-shadow(0 8px 32px rgba(99, 102, 241, 0.3));
        transition: transform 0.4s ease, filter 0.4s ease;
      }

      .gep-brand-logo:hover img {
        transform: scale(1.04);
        filter: drop-shadow(0 12px 40px rgba(99, 102, 241, 0.45));
      }

      @keyframes gepLogoEnter {
        to { opacity: 1; transform: scale(1) translateY(0); }
      }

      .gep-brand-name {
        font-size: 2rem;
        font-weight: 900;
        letter-spacing: -0.02em;
        background: linear-gradient(135deg, #fff 0%, #e0e7ff 40%, #a5b4fc 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        line-height: 1.2;
        margin-bottom: 0.4rem;
        animation: gepFadeUp 0.8s ease 0.2s both;
      }

      .gep-brand-tagline {
        font-size: 0.8rem;
        color: rgba(255,255,255,0.5);
        font-weight: 600;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        animation: gepFadeUp 0.8s ease 0.3s both;
      }

      .gep-hero {
        margin-top: 2.5rem;
        animation: gepFadeUp 0.8s ease 0.4s both;
      }

      .gep-hero-title {
        font-size: 2.25rem;
        font-weight: 900;
        color: #fff;
        line-height: 1.15;
        letter-spacing: -0.03em;
        margin-bottom: 0.85rem;
      }

      .gep-hero-title span {
        background: linear-gradient(135deg, #67e8f9, #a5f3fc);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
      }

      .gep-hero-desc {
        font-size: 0.95rem;
        color: rgba(255,255,255,0.6);
        line-height: 1.65;
        font-weight: 400;
      }

      .gep-features {
        margin-top: 2rem;
        display: flex;
        flex-direction: column;
        gap: 0.85rem;
        text-align: left;
        animation: gepFadeUp 0.8s ease 0.5s both;
      }

      @keyframes gepFadeUp {
        from { opacity: 0; transform: translateY(18px); }
        to   { opacity: 1; transform: translateY(0); }
      }

      .gep-feature {
        display: flex;
        align-items: center;
        gap: 0.75rem;
      }

      .gep-feature-icon {
        width: 36px; height: 36px;
        background: rgba(255,255,255,0.08);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
      }

      .gep-feature-icon svg {
        width: 18px; height: 18px;
        stroke: #818cf8;
      }

      .gep-feature-text {
        font-size: 0.85rem;
        color: rgba(255,255,255,0.65);
        font-weight: 500;
      }

      .gep-footer-left {
        position: absolute;
        bottom: 1.5rem;
        left: 0; right: 0;
        text-align: center;
        z-index: 1;
        font-size: 0.72rem;
        color: rgba(255,255,255,0.28);
        font-weight: 500;
      }

      /* ── Right panel ── */
      .gep-right {
        width: 520px;
        min-width: 420px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2.5rem;
        background: #fff;
        position: relative;
      }

      .gep-right::before {
        content: '';
        position: absolute;
        left: 0; top: 10%; bottom: 10%;
        width: 1px;
        background: linear-gradient(to bottom, transparent, #e2e8f0, transparent);
      }

      .gep-card {
        width: 100%;
        max-width: 400px;
        animation: gepSlideUp 0.5s cubic-bezier(0.4, 0, 0.2, 1) both;
      }

      @keyframes gepSlideUp {
        from { opacity: 0; transform: translateY(20px); }
        to   { opacity: 1; transform: translateY(0); }
      }

      /* Mobile logo — shown when left panel is hidden */
      .gep-mobile-logo {
        display: none;
        text-align: center;
        margin-bottom: 1.75rem;
      }

      .gep-mobile-logo img {
        width: 140px;
        height: auto;
        object-fit: contain;
        filter: drop-shadow(0 6px 24px rgba(99, 102, 241, 0.25));
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

      .gep-field { margin-bottom: 1.25rem; }

      .gep-label {
        display: block;
        font-size: 0.75rem;
        font-weight: 700;
        color: #475569;
        margin-bottom: 0.45rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
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
        padding: 0.85rem 1rem 0.85rem 2.75rem;
        background: #f8fafc;
        border: 1.5px solid #e2e8f0;
        border-radius: 12px;
        color: #0f172a;
        font-size: 0.9375rem;
        font-family: 'Inter', sans-serif;
        outline: none;
        transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
      }

      .gep-input:focus {
        background: #fff;
        border-color: #6366f1;
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
      }

      .gep-input-wrap:focus-within .gep-input-icon { color: #6366f1; }

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

      .gep-pw-toggle:hover { color: #6366f1; }

      .gep-field-err {
        display: none;
        font-size: 0.78rem;
        color: #dc2626;
        font-weight: 500;
        margin-top: 0.4rem;
      }

      .gep-btn {
        width: 100%;
        padding: 0.9rem;
        background: linear-gradient(135deg, #6366f1, #4f46e5);
        color: #fff;
        border: none;
        border-radius: 12px;
        font-size: 0.9375rem;
        font-weight: 700;
        font-family: 'Inter', sans-serif;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center; gap: 0.5rem;
        transition: all 0.2s;
        box-shadow: 0 4px 14px rgba(99, 102, 241, 0.35);
        margin-top: 0.5rem;
      }

      .gep-btn:hover:not(:disabled) {
        transform: translateY(-1px);
        box-shadow: 0 8px 22px rgba(99, 102, 241, 0.4);
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
        font-size: 0.72rem;
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
        color: #6366f1;
        font-weight: 700;
        text-decoration: none;
        transition: color 0.2s;
      }

      .gep-admin-link a:hover { color: #4f46e5; text-decoration: underline; }

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
      @media (max-width: 960px) {
        .gep-left { display: none; }
        .gep-right {
          flex: 1;
          width: auto;
          min-width: 0;
          background: linear-gradient(160deg, #0f172a 0%, #1e293b 40%, #0f172a 100%);
        }
        .gep-right::before { display: none; }
        .gep-card {
          background: rgba(255,255,255,0.98);
          border-radius: 20px;
          padding: 2rem 1.75rem;
          box-shadow: 0 24px 64px rgba(0,0,0,0.3);
        }
        .gep-mobile-logo { display: block; }
      }

      @media (max-width: 480px) {
        .gep-right { padding: 1.25rem; }
        .gep-card { padding: 1.5rem 1.25rem; }
        .gep-mobile-logo img { width: 120px; }
        .gep-card-title { font-size: 1.5rem; }
      }
    `;
    document.head.appendChild(style);
  }

  _getLoginHTML() {
    const year = new Date().getFullYear();

    return `
    <div class="gep-root">

      <!-- Left: Brand showcase -->
      <div class="gep-left">
        <div class="gep-orb gep-orb-1"></div>
        <div class="gep-orb gep-orb-2"></div>
        <div class="gep-orb gep-orb-3"></div>
        <div class="gep-grid-bg"></div>

        <div class="gep-left-content">
          <div class="gep-brand-logo">
            <img src="assets/logo.png" alt="Gyanam India">
          </div>

          <div class="gep-brand-name">Gyanam India</div>
          <div class="gep-brand-tagline">Authorised Training Centre Network</div>

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
                <div class="gep-feature-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                  </svg>
                </div>
                <span class="gep-feature-text">Secure &amp; encrypted exam sessions</span>
              </div>
              <div class="gep-feature">
                <div class="gep-feature-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                  </svg>
                </div>
                <span class="gep-feature-text">Real-time timer with auto-submit</span>
              </div>
              <div class="gep-feature">
                <div class="gep-feature-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="20" x2="18" y2="10"/>
                    <line x1="12" y1="20" x2="12" y2="4"/>
                    <line x1="6" y1="20" x2="6" y2="14"/>
                  </svg>
                </div>
                <span class="gep-feature-text">Instant results &amp; score breakdown</span>
              </div>
            </div>
          </div>
        </div>

        <div class="gep-footer-left">&copy; ${year} Gyanam India. All rights reserved.</div>
      </div>

      <!-- Right: Login form -->
      <div class="gep-right">
        <div class="gep-card">

          <div class="gep-mobile-logo">
            <img src="assets/logo.png" alt="Gyanam India">
          </div>

          <div class="gep-card-header">
            <div class="gep-card-title">Welcome back</div>
            <p class="gep-card-sub">Enter your Registration ID &amp; password to access your exam</p>
          </div>

          <div id="gep-error" class="gep-error">
            <span class="gep-error-icon">&#9888;</span>
            <p id="gep-error-text" class="gep-error-text"></p>
          </div>

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
                <button type="button" id="gep-pw-toggle" class="gep-pw-toggle" title="Toggle password visibility" aria-label="Toggle password visibility">
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
      el.style.animation = 'none';
      el.offsetHeight;
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
