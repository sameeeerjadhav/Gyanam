/**
 * LoginPage — GIIT / Gyanam Exam Portal student login
 * Institutional header (hex pattern + brand) · centered sign-in · copyright + live clock footer
 */

import AuthenticationModule from '../services/AuthenticationModule.js';
import router from '../services/Router.js';
import { stampedeDelayMs, withBackoff } from '../utils/stampede.js';

class LoginPage {
  constructor(authModule = null) {
    this.authModule = authModule || new AuthenticationModule();
    this.isSubmitting = false;
    this._clockTimer = null;
  }

  render(container) {
    // Always rebuild styles so a cached old stylesheet cannot stick after deploy
    document.getElementById('gep-login-styles')?.remove();
    document.getElementById('gep-login-styles-v2')?.remove();
    container.innerHTML = this._getLoginHTML();
    this._injectStyles();
    this._attachEventListeners();
    this._startClock();
  }

  _injectStyles() {
    document.getElementById('gep-login-styles')?.remove();
    document.getElementById('gep-login-styles-v2')?.remove();
    const style = document.createElement('style');
    style.id = 'gep-login-styles-v2';
    style.textContent = `
      @import url('https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@400;500;600;700;800&display=swap');

      .gep-root * { box-sizing: border-box; margin: 0; padding: 0; }

      .gep-root {
        --navy: #0f2744;
        --navy-2: #16355c;
        --red: #c41e3a;
        --red-dark: #9f1830;
        --ink: #0f172a;
        --muted: #64748b;
        --line: #e2e8f0;
        --bg: #f4f6f9;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        font-family: 'Source Sans 3', 'Segoe UI', sans-serif;
        background: var(--bg);
        color: var(--ink);
      }

      /* ── Header with network / hex pattern ── */
      .gep-header {
        position: relative;
        background: #fff;
        border-bottom: 1px solid var(--line);
        overflow: hidden;
        isolation: isolate;
      }

      .gep-header-pattern {
        position: absolute;
        inset: 0;
        z-index: 0;
        pointer-events: none;
        opacity: 0.55;
        background-color: #f8fafc;
        background-image:
          radial-gradient(circle at 12% 40%, rgba(196, 30, 58, 0.06), transparent 42%),
          radial-gradient(circle at 88% 20%, rgba(15, 39, 68, 0.07), transparent 40%),
          url("data:image/svg+xml,%3Csvg width='120' height='104' viewBox='0 0 120 104' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' stroke='%2394a3b8' stroke-width='0.9' opacity='0.45'%3E%3Cpath d='M30 2l28 16v32L30 66 2 50V18z'/%3E%3Cpath d='M90 2l28 16v32L90 66 62 50V18z'/%3E%3Cpath d='M60 36l28 16v32L60 100 32 84V52z'/%3E%3Ccircle cx='30' cy='2' r='2.2' fill='%2394a3b8'/%3E%3Ccircle cx='58' cy='18' r='2.2' fill='%2394a3b8'/%3E%3Ccircle cx='90' cy='2' r='2.2' fill='%2394a3b8'/%3E%3Ccircle cx='30' cy='66' r='2.2' fill='%2394a3b8'/%3E%3Ccircle cx='60' cy='36' r='2.2' fill='%2394a3b8'/%3E%3C/g%3E%3C/svg%3E");
        background-size: auto, auto, 120px 104px;
      }

      .gep-header-inner {
        position: relative;
        z-index: 1;
        max-width: 1120px;
        margin: 0 auto;
        padding: 1.1rem 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
      }

      .gep-brand {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        min-width: 0;
      }

      .gep-brand-logo {
        width: 72px;
        height: 72px;
        object-fit: contain;
        flex-shrink: 0;
        display: block;
        filter: drop-shadow(0 2px 6px rgba(15, 39, 68, 0.12));
      }

      .gep-brand-text { min-width: 0; }

      .gep-brand-text strong {
        display: block;
        font-size: 1.05rem;
        font-weight: 800;
        color: var(--navy);
        letter-spacing: -0.01em;
        line-height: 1.2;
      }

      .gep-brand-text span {
        display: block;
        margin-top: 0.15rem;
        font-size: 0.78rem;
        font-weight: 600;
        color: var(--muted);
        letter-spacing: 0.04em;
        text-transform: uppercase;
      }

      .gep-header-badge {
        margin-left: auto;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.4rem 0.75rem;
        border-radius: 999px;
        background: rgba(15, 39, 68, 0.06);
        border: 1px solid rgba(15, 39, 68, 0.1);
        font-size: 0.72rem;
        font-weight: 700;
        color: var(--navy);
        letter-spacing: 0.04em;
        text-transform: uppercase;
        white-space: nowrap;
      }

      .gep-header-badge i {
        width: 7px; height: 7px;
        border-radius: 50%;
        background: #16a34a;
        box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.2);
      }

      /* ── Nav strip ── */
      .gep-nav {
        background: var(--navy);
        box-shadow: 0 2px 8px rgba(15, 39, 68, 0.18);
      }

      .gep-nav-inner {
        max-width: 1120px;
        margin: 0 auto;
        padding: 0 1.5rem;
        display: flex;
        align-items: stretch;
        gap: 0.25rem;
      }

      .gep-nav-item {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.7rem 1.1rem;
        font-size: 0.82rem;
        font-weight: 700;
        color: rgba(255,255,255,0.72);
        text-decoration: none;
        border: none;
        background: transparent;
        cursor: default;
      }

      .gep-nav-item svg { width: 15px; height: 15px; }

      .gep-nav-item.is-active {
        background: var(--red);
        color: #fff;
      }

      /* ── Main ── */
      .gep-main {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2.5rem 1.25rem 3rem;
        position: relative;
      }

      .gep-main::before {
        content: '';
        position: absolute;
        inset: 0;
        background:
          radial-gradient(ellipse 500px 280px at 50% 0%, rgba(15, 39, 68, 0.05), transparent 70%);
        pointer-events: none;
      }

      .gep-shell {
        position: relative;
        z-index: 1;
        width: 100%;
        max-width: 980px;
        display: grid;
        grid-template-columns: 1.05fr 0.95fr;
        gap: 1.75rem;
        align-items: stretch;
      }

      .gep-aside {
        background: #fff;
        border: 1px solid var(--line);
        border-radius: 18px;
        padding: 1.75rem 1.6rem;
        box-shadow: 0 10px 30px rgba(15, 39, 68, 0.05);
        display: flex;
        flex-direction: column;
        justify-content: center;
        animation: gepIn 0.45s ease both;
      }

      .gep-aside-kicker {
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: var(--red);
        margin-bottom: 0.55rem;
      }

      .gep-aside h1 {
        font-size: clamp(1.55rem, 2.4vw, 2rem);
        font-weight: 800;
        color: var(--navy);
        letter-spacing: -0.02em;
        line-height: 1.2;
        margin-bottom: 0.75rem;
      }

      .gep-aside p {
        font-size: 0.95rem;
        color: var(--muted);
        line-height: 1.6;
        margin-bottom: 1.35rem;
      }

      .gep-points { list-style: none; display: flex; flex-direction: column; gap: 0.7rem; }

      .gep-points li {
        display: flex;
        align-items: flex-start;
        gap: 0.65rem;
        font-size: 0.88rem;
        font-weight: 600;
        color: #334155;
        line-height: 1.4;
      }

      .gep-points li::before {
        content: '';
        width: 8px; height: 8px;
        margin-top: 0.4rem;
        border-radius: 50%;
        background: var(--red);
        flex-shrink: 0;
        box-shadow: 0 0 0 4px rgba(196, 30, 58, 0.12);
      }

      .gep-card {
        background: #fff;
        border: 1px solid var(--line);
        border-radius: 18px;
        padding: 1.85rem 1.7rem 1.6rem;
        box-shadow: 0 16px 40px rgba(15, 39, 68, 0.08);
        animation: gepIn 0.5s ease 0.05s both;
      }

      @keyframes gepIn {
        from { opacity: 0; transform: translateY(14px); }
        to   { opacity: 1; transform: translateY(0); }
      }

      .gep-card-head {
        margin-bottom: 1.35rem;
        padding-bottom: 1rem;
        border-bottom: 1px dashed var(--line);
        background:
          repeating-linear-gradient(
            -45deg,
            transparent,
            transparent 6px,
            rgba(15, 39, 68, 0.025) 6px,
            rgba(15, 39, 68, 0.025) 12px
          );
        margin-left: -0.35rem;
        margin-right: -0.35rem;
        padding-left: 0.35rem;
        padding-right: 0.35rem;
        border-radius: 8px 8px 0 0;
      }

      .gep-card-title {
        font-size: 1.35rem;
        font-weight: 800;
        color: var(--navy);
        letter-spacing: 0.02em;
        text-transform: uppercase;
      }

      .gep-card-title em {
        font-style: normal;
        color: var(--red);
      }

      .gep-card-sub {
        margin-top: 0.35rem;
        font-size: 0.88rem;
        color: var(--muted);
        font-weight: 500;
      }

      .gep-error {
        display: none;
        align-items: flex-start;
        gap: 0.55rem;
        padding: 0.8rem 0.9rem;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 10px;
        margin-bottom: 1.1rem;
        animation: gepShake 0.4s ease;
      }

      @keyframes gepShake {
        0%, 100% { transform: translateX(0); }
        20%, 60% { transform: translateX(-4px); }
        40%, 80% { transform: translateX(4px); }
      }

      .gep-error-icon { flex-shrink: 0; margin-top: 0.05rem; }
      .gep-error-text { color: #b91c1c; font-size: 0.84rem; font-weight: 600; line-height: 1.45; }

      .gep-field { margin-bottom: 1.05rem; }

      .gep-label {
        display: block;
        font-size: 0.78rem;
        font-weight: 700;
        color: #334155;
        margin-bottom: 0.4rem;
      }

      .gep-input-wrap { position: relative; }

      .gep-input-icon {
        position: absolute;
        left: 0.85rem; top: 50%; transform: translateY(-50%);
        width: 17px; height: 17px;
        color: #94a3b8;
        pointer-events: none;
      }

      .gep-input {
        width: 100%;
        padding: 0.78rem 1rem 0.78rem 2.6rem;
        background: #f8fafc;
        border: 1.5px solid #dbe3ee;
        border-radius: 10px;
        color: var(--ink);
        font-size: 0.95rem;
        font-family: inherit;
        outline: none;
        transition: border-color .18s, box-shadow .18s, background .18s;
      }

      .gep-input:focus {
        background: #fff;
        border-color: var(--navy);
        box-shadow: 0 0 0 3px rgba(15, 39, 68, 0.1);
      }

      .gep-input-wrap:focus-within .gep-input-icon { color: var(--navy); }

      .gep-pw-toggle {
        position: absolute;
        right: 0.7rem; top: 50%; transform: translateY(-50%);
        background: none; border: none; cursor: pointer;
        color: #94a3b8; padding: 0.25rem;
        display: flex; align-items: center;
      }

      .gep-pw-toggle:hover { color: var(--navy); }

      .gep-field-err {
        display: none;
        font-size: 0.76rem;
        color: #dc2626;
        font-weight: 600;
        margin-top: 0.35rem;
      }

      .gep-btn {
        width: 100%;
        margin-top: 0.35rem;
        padding: 0.85rem 1rem;
        border: none;
        border-radius: 10px;
        background: linear-gradient(135deg, #15803d, #16a34a);
        color: #fff;
        font-size: 0.95rem;
        font-weight: 800;
        font-family: inherit;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        box-shadow: 0 8px 18px rgba(22, 163, 74, 0.28);
        transition: transform .15s, box-shadow .15s, filter .15s;
      }

      .gep-btn:hover:not(:disabled) {
        transform: translateY(-1px);
        box-shadow: 0 10px 22px rgba(22, 163, 74, 0.35);
        filter: brightness(1.03);
      }

      .gep-btn:disabled { opacity: 0.65; cursor: not-allowed; }

      .gep-spinner {
        width: 17px; height: 17px;
        border: 2.5px solid rgba(255,255,255,0.35);
        border-top-color: #fff;
        border-radius: 50%;
        animation: gepSpin .65s linear infinite;
      }

      @keyframes gepSpin { to { transform: rotate(360deg); } }

      .gep-help {
        margin-top: 1rem;
        font-size: 0.8rem;
        color: var(--muted);
        line-height: 1.5;
        text-align: center;
      }

      .gep-help strong { color: #334155; }

      .gep-admin-link {
        margin-top: 0.85rem;
        text-align: center;
        font-size: 0.84rem;
        color: var(--muted);
        font-weight: 500;
      }

      .gep-admin-link a {
        color: var(--navy);
        font-weight: 800;
        text-decoration: none;
      }

      .gep-admin-link a:hover { color: var(--red); text-decoration: underline; }

      /* ── Footer (copyright + live time) ── */
      .gep-footer {
        background: #1a1f2a;
        color: #e2e8f0;
        margin-top: auto;
      }

      .gep-footer-top {
        max-width: 1120px;
        margin: 0 auto;
        padding: 0.85rem 1.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
        font-size: 0.82rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
      }

      .gep-footer-top .accent { color: #f87171; }

      .gep-footer-bot {
        border-top: 1px solid rgba(255,255,255,0.1);
        padding: 0.55rem 1.5rem 0.7rem;
        text-align: center;
        font-size: 0.72rem;
        color: rgba(226, 232, 240, 0.55);
        font-weight: 500;
        letter-spacing: 0.02em;
      }

      @media (max-width: 860px) {
        .gep-shell { grid-template-columns: 1fr; max-width: 440px; }
        .gep-aside { display: none; }
        .gep-header-badge { display: none; }
        .gep-brand-text strong { font-size: 0.95rem; }
      }

      @media (max-width: 480px) {
        .gep-header-inner { padding: 0.9rem 1rem; }
        .gep-brand-logo { width: 58px; height: 58px; }
        .gep-card { padding: 1.35rem 1.15rem 1.25rem; }
        .gep-footer-top { justify-content: center; text-align: center; }
      }
    `;
    document.head.appendChild(style);
  }

  _getLoginHTML() {
    const year = new Date().getFullYear();
    return `
    <div class="gep-root">
      <header class="gep-header">
        <div class="gep-header-pattern" aria-hidden="true"></div>
        <div class="gep-header-inner">
          <div class="gep-brand">
            <img class="gep-brand-logo" src="assets/giit_brand_logo.png?v=2" alt="GIIT"
                 onerror="this.onerror=null;this.src='assets/giit_logo.png?v=2';this.onerror=function(){this.src='assets/logo.png'}">
            <div class="gep-brand-text">
              <strong>Gyanam Institute of Information Technology</strong>
              <span>Student Exam Portal</span>
            </div>
          </div>
          <div class="gep-header-badge"><i></i> Secure exam access</div>
        </div>
      </header>

      <nav class="gep-nav" aria-label="Portal navigation">
        <div class="gep-nav-inner">
          <span class="gep-nav-item is-active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Student Sign In
          </span>
        </div>
      </nav>

      <main class="gep-main">
        <div class="gep-shell">
          <aside class="gep-aside">
            <div class="gep-aside-kicker">GIIT Online Examination</div>
            <h1>Sign in to begin your exam session</h1>
            <p>Use your Registration ID and password issued by your ATC to access your assigned exams.</p>
            <ul class="gep-points">
              <li>Timed papers with auto-submit</li>
              <li>Question map &amp; bilingual support</li>
              <li>Instant score after submission</li>
            </ul>
          </aside>

          <section class="gep-card" aria-label="Student login">
            <div class="gep-card-head">
              <div class="gep-card-title">Student <em>Login</em></div>
              <p class="gep-card-sub">Enter your Registration ID and password to continue</p>
            </div>

            <div id="gep-error" class="gep-error" role="alert">
              <span class="gep-error-icon">⚠</span>
              <p id="gep-error-text" class="gep-error-text"></p>
            </div>

            <form id="gep-login-form" novalidate>
              <div class="gep-field">
                <label for="gep-identifier" class="gep-label">Registration ID / User Id</label>
                <div class="gep-input-wrap">
                  <svg class="gep-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                  </svg>
                  <input type="text" id="gep-identifier" name="identifier" autocomplete="username"
                         placeholder="e.g. GIIT2 / GYANAM1" class="gep-input" required>
                </div>
                <p id="gep-identifier-err" class="gep-field-err"></p>
              </div>

              <div class="gep-field">
                <label for="gep-password" class="gep-label">Password</label>
                <div class="gep-input-wrap">
                  <svg class="gep-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                  </svg>
                  <input type="password" id="gep-password" name="password" autocomplete="current-password"
                         placeholder="Enter your password" class="gep-input" style="padding-right:2.75rem" required>
                  <button type="button" id="gep-pw-toggle" class="gep-pw-toggle" title="Show password" aria-label="Toggle password visibility">
                    <svg id="gep-eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="17" height="17">
                      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                    </svg>
                  </button>
                </div>
                <p id="gep-password-err" class="gep-field-err"></p>
              </div>

              <button type="submit" id="gep-submit" class="gep-btn">
                <span id="gep-btn-text">Sign In</span>
                <span id="gep-btn-loading" class="gep-spinner" style="display:none"></span>
              </button>
            </form>

            <p class="gep-help">Default password for new students is <strong>password</strong>. Contact your ATC if you need help.</p>
            <div class="gep-admin-link">Admin / ATC? <a href="admin.html">Open Admin Portal →</a></div>
          </section>
        </div>
      </main>

      <footer class="gep-footer">
        <div class="gep-footer-top">
          <div>Copyright © ${year} <span class="accent">GIIT</span></div>
          <div>Time <span class="accent" id="gep-live-clock">—</span></div>
        </div>
        <div class="gep-footer-bot">
          A unit of IT Training — Gyanam India Educational Services (ISO 9001 : 2015)
        </div>
      </footer>
    </div>`;
  }

  _startClock() {
    const el = document.getElementById('gep-live-clock');
    if (!el) return;
    const tick = () => {
      const now = new Date();
      const d = String(now.getDate()).padStart(2, '0');
      const m = String(now.getMonth() + 1).padStart(2, '0');
      const y = now.getFullYear();
      let h = now.getHours();
      const ampm = h >= 12 ? 'PM' : 'AM';
      h = h % 12 || 12;
      const min = String(now.getMinutes()).padStart(2, '0');
      const sec = String(now.getSeconds()).padStart(2, '0');
      el.textContent = `${d}-${m}-${y} ${String(h).padStart(2, '0')}:${min}:${sec} ${ampm}`;
    };
    tick();
    this._clockTimer = setInterval(tick, 1000);
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
      await new Promise((r) => setTimeout(r, stampedeDelayMs(identifier, 15000, 1000)));
      const result = await withBackoff(
        () => this.authModule.authenticate({ identifier, password }),
        { retries: 4, baseMs: 1000, maxMs: 8000, label: 'student-login' }
      );
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
    if (this._clockTimer) {
      clearInterval(this._clockTimer);
      this._clockTimer = null;
    }
    document.getElementById('gep-login-styles')?.remove();
    document.getElementById('gep-login-styles-v2')?.remove();
  }
}

export default LoginPage;
export { LoginPage };
