/**
 * StudentDashboard — GIIT branded student portal with top nav tabs
 * Tabs: Dashboard · Profile · My Exams · My Results
 */

import { ExamHistoryModule } from '../modules/ExamHistoryModule.js';
import { CertificationModule } from '../modules/CertificationModule.js';
import { AuthenticationModule } from '../services/AuthenticationModule.js';
import ApiClient from '../services/APIClient.js';
import modalService from '../services/ModalService.js';

export class StudentDashboard {
  constructor(authModule = null, apiClient = null, router = null) {
    this.authModule = authModule || new AuthenticationModule();
    this.router = router;
    this.examHistoryModule = new ExamHistoryModule(null);
    this.certificationModule = new CertificationModule(null);
    this.container = null;
    this.currentSession = null;
    this.availableExams = [];
    this.practiceExams = [];
    this.activeTab = 'dashboard';
    this._resultsLoaded = false;
  }

  async initialize(container) {
    this.container = container;

    if (!this.authModule.isAuthenticated()) {
      if (this.router) this.router.navigate('/login');
      else window.location.href = '/login.html';
      return;
    }

    this.currentSession = this.authModule.getCurrentSession();
    this._renderLoading();

    try {
      await this._refreshProfile();
      await this.loadAvailableExams();
      this.render();
      await this._maybeLoadResults();
    } catch (error) {
      if (error.status === 401 || error.message === 'Unauthorized') {
        if (this.router) this.router.navigate('/login');
        else window.location.href = '/index.html#/login';
        return;
      }
      this.renderError(error);
    }
  }

  async _refreshProfile() {
    try {
      const me = await ApiClient.getStudentMe();
      if (me && typeof me === 'object') {
        this.authModule.updateSessionUser(me);
        this.currentSession = this.authModule.getCurrentSession();
      }
    } catch (_) {
      /* keep cached session user */
    }
  }

  _renderLoading() {
    if (!this.container) return;
    this._injectStyles();
    this.container.innerHTML = `
      <div class="sd-root sd-loading-wrap">
        <div class="sd-spinner" aria-hidden="true"></div>
        <p>Loading your dashboard…</p>
      </div>`;
  }

  async loadAvailableExams() {
    const data = await ApiClient.getStudentExams();
    const all = Array.isArray(data) ? data : (data.data || []);
    this.practiceExams = all.filter((e) => !!e.is_global_practice);
    this.availableExams = all.filter((e) => !e.is_global_practice);
  }

  _injectStyles() {
    const existing = document.getElementById('sd-giit-styles');
    if (existing) existing.remove();
    const st = document.createElement('style');
    st.id = 'sd-giit-styles';
    st.textContent = `
      @import url('https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@400;500;600;700;800&display=swap');

      .sd-root {
        --sd-navy: #0f2744;
        --sd-navy-2: #16355c;
        --sd-red: #c41e3a;
        --sd-red-dark: #9f1830;
        --sd-muted: #5b6b7c;
        --sd-line: #e2e8f0;
        --sd-bg: #f3f5f8;
        --sd-card: #ffffff;
        --sd-text: #0f172a;
        min-height: 100vh;
        background: var(--sd-bg);
        font-family: 'Source Sans 3', 'Segoe UI', sans-serif;
        color: var(--sd-text);
      }
      .sd-root *, .sd-root *::before, .sd-root *::after { box-sizing: border-box; }

      .sd-loading-wrap {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: 1rem; color: var(--sd-muted); font-weight: 600;
      }
      .sd-spinner {
        width: 44px; height: 44px; border-radius: 50%;
        border: 3px solid #dbe3ee; border-top-color: var(--sd-red);
        animation: sd-spin .75s linear infinite;
      }
      @keyframes sd-spin { to { transform: rotate(360deg); } }
      @keyframes sd-fade-up {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
      }

      .sd-topbar {
        background: #fff;
        border-bottom: 1px solid var(--sd-line);
        position: sticky; top: 0; z-index: 30;
        box-shadow: 0 1px 0 rgba(15,39,68,.04);
      }
      .sd-topbar-inner {
        max-width: 1120px; margin: 0 auto;
        padding: .65rem 1.25rem 0;
        display: flex; align-items: center; justify-content: space-between; gap: 1rem;
      }
      .sd-brand { display: flex; align-items: center; gap: .85rem; min-width: 0; }
      .sd-brand img {
        height: 42px; width: auto; max-width: 160px; object-fit: contain; display: block;
      }
      .sd-brand-meta { min-width: 0; }
      .sd-brand-meta strong {
        display: block; font-size: .9rem; font-weight: 800; color: var(--sd-navy);
        letter-spacing: .01em; line-height: 1.2;
      }
      .sd-brand-meta span {
        display: block; font-size: .7rem; color: var(--sd-muted); font-weight: 600;
        text-transform: uppercase; letter-spacing: .06em; margin-top: .1rem;
      }
      .sd-logout {
        display: inline-flex; align-items: center; gap: .4rem;
        background: #fff5f5; border: 1px solid #fecaca; color: var(--sd-red);
        padding: .42rem .85rem; border-radius: 8px; font-size: .78rem; font-weight: 700;
        cursor: pointer; font-family: inherit; transition: background .15s; flex-shrink: 0;
      }
      .sd-logout:hover { background: #fee2e2; }

      .sd-nav {
        max-width: 1120px; margin: 0 auto;
        padding: 0 1.25rem;
        display: flex; gap: .15rem; overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
      }
      .sd-nav::-webkit-scrollbar { display: none; }
      .sd-nav-btn {
        appearance: none; background: transparent; border: none;
        font-family: inherit; cursor: pointer;
        padding: .85rem 1.05rem .75rem;
        font-size: .86rem; font-weight: 700; color: var(--sd-muted);
        border-bottom: 2.5px solid transparent;
        white-space: nowrap; transition: color .15s, border-color .15s;
        display: inline-flex; align-items: center; gap: .45rem;
      }
      .sd-nav-btn:hover { color: var(--sd-navy); }
      .sd-nav-btn.is-active {
        color: var(--sd-red);
        border-bottom-color: var(--sd-red);
      }
      .sd-nav-btn svg { width: 16px; height: 16px; opacity: .85; }

      .sd-main { max-width: 1120px; margin: 0 auto; padding: 1.35rem 1.25rem 2.5rem; }
      .sd-panel { display: none; animation: sd-fade-up .35s ease both; }
      .sd-panel.is-active { display: block; }

      .sd-welcome {
        display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem;
        margin-bottom: 1.25rem; flex-wrap: wrap;
      }
      .sd-welcome h1 {
        margin: 0; font-size: 1.45rem; font-weight: 800; color: var(--sd-navy); letter-spacing: -.01em;
      }
      .sd-welcome p { margin: .3rem 0 0; color: var(--sd-muted); font-size: .9rem; font-weight: 500; }

      .sd-stats {
        display: grid; grid-template-columns: repeat(3, 1fr); gap: .85rem; margin-bottom: 1.5rem;
      }
      .sd-stat {
        background: #fff; border: 1px solid var(--sd-line); border-radius: 12px;
        padding: 1rem 1.1rem; box-shadow: 0 1px 3px rgba(15,39,68,.04);
      }
      .sd-stat em {
        display: block; font-style: normal; font-size: .68rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: .05em; color: var(--sd-muted); margin-bottom: .25rem;
      }
      .sd-stat strong {
        display: block; font-size: 1.35rem; font-weight: 800; color: var(--sd-navy); line-height: 1.2;
      }
      .sd-stat span { display: block; margin-top: .2rem; font-size: .78rem; color: var(--sd-muted); font-weight: 600; }

      .sd-profile {
        background: linear-gradient(135deg, var(--sd-navy) 0%, var(--sd-navy-2) 58%, #1e4d7b 100%);
        border-radius: 16px; padding: 1.35rem 1.4rem;
        color: #fff; display: grid; grid-template-columns: auto 1fr;
        gap: 1.25rem; align-items: center;
        box-shadow: 0 12px 28px rgba(15,39,68,.18);
        position: relative; overflow: hidden;
      }
      .sd-profile::after {
        content: ''; position: absolute; right: -40px; top: -50px;
        width: 180px; height: 180px; border-radius: 50%;
        background: rgba(255,255,255,.05); pointer-events: none;
      }
      .sd-photo {
        width: 96px; height: 118px; border-radius: 10px; overflow: hidden;
        border: 3px solid rgba(255,255,255,.85);
        background: rgba(255,255,255,.12);
        box-shadow: 0 8px 20px rgba(0,0,0,.22);
        flex-shrink: 0;
      }
      .sd-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
      .sd-photo-fallback {
        width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;
        font-size: 2rem; font-weight: 800; color: #fff; letter-spacing: .02em;
        background: linear-gradient(160deg, rgba(196,30,58,.55), rgba(15,39,68,.35));
      }
      .sd-hello {
        font-size: .8rem; font-weight: 600; color: rgba(255,255,255,.72); margin: 0 0 .2rem;
      }
      .sd-name {
        margin: 0; font-size: 1.45rem; font-weight: 800; letter-spacing: -.01em; line-height: 1.2;
      }
      .sd-role-line {
        margin: .35rem 0 0; font-size: .82rem; color: rgba(255,255,255,.78); font-weight: 500;
      }
      .sd-facts {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: .55rem; margin-top: 1rem;
      }
      .sd-fact {
        background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.12);
        border-radius: 10px; padding: .55rem .7rem;
      }
      .sd-fact em {
        display: block; font-style: normal; font-size: .65rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: .05em; color: rgba(255,255,255,.55); margin-bottom: .15rem;
      }
      .sd-fact strong {
        display: block; font-size: .86rem; font-weight: 700; color: #fff; word-break: break-word;
      }

      .sd-profile-card {
        background: #fff; border: 1px solid var(--sd-line); border-radius: 16px;
        overflow: hidden; box-shadow: 0 1px 3px rgba(15,39,68,.04);
      }
      .sd-profile-banner {
        background: linear-gradient(135deg, var(--sd-navy) 0%, var(--sd-navy-2) 100%);
        height: 88px;
      }
      .sd-profile-body {
        padding: 0 1.5rem 1.5rem;
        display: grid; grid-template-columns: auto 1fr; gap: 1.25rem;
        margin-top: -40px; position: relative;
      }
      .sd-profile-body .sd-photo {
        border-color: #fff; background: #e2e8f0;
      }
      .sd-profile-body .sd-name { color: var(--sd-navy); margin-top: 48px; }
      .sd-profile-body .sd-role-line { color: var(--sd-muted); }
      .sd-profile-grid {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: .75rem; margin-top: 1.25rem; grid-column: 1 / -1;
      }
      .sd-profile-field {
        background: #f8fafc; border: 1px solid var(--sd-line); border-radius: 10px; padding: .75rem .9rem;
      }
      .sd-profile-field em {
        display: block; font-style: normal; font-size: .68rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: .05em; color: var(--sd-muted); margin-bottom: .2rem;
      }
      .sd-profile-field strong {
        display: block; font-size: .92rem; font-weight: 700; color: var(--sd-navy); word-break: break-word;
      }

      .sd-section { margin-top: 1.5rem; }
      .sd-section-head {
        display: flex; align-items: center; justify-content: space-between;
        gap: .75rem; margin-bottom: 1rem; flex-wrap: wrap;
      }
      .sd-section-title {
        display: flex; align-items: center; gap: .65rem; margin: 0;
      }
      .sd-section-icon {
        width: 34px; height: 34px; border-radius: 9px;
        background: #fff; border: 1px solid var(--sd-line);
        display: flex; align-items: center; justify-content: center;
        color: var(--sd-red); box-shadow: 0 1px 2px rgba(15,39,68,.04);
      }
      .sd-section-title h2 {
        margin: 0; font-size: 1.05rem; font-weight: 800; color: var(--sd-navy);
      }
      .sd-section-title p {
        margin: .1rem 0 0; font-size: .78rem; color: var(--sd-muted); font-weight: 500;
      }
      .sd-refresh {
        display: inline-flex; align-items: center; gap: .4rem;
        background: #fff; border: 1px solid var(--sd-line); color: var(--sd-navy);
        padding: .45rem .85rem; border-radius: 8px; font-size: .8rem; font-weight: 700;
        cursor: pointer; font-family: inherit; transition: border-color .15s, color .15s;
      }
      .sd-refresh:hover { border-color: #94a3b8; color: var(--sd-red); }

      .sd-exam-grid {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem;
      }
      .sd-exam-card {
        background: var(--sd-card); border: 1px solid var(--sd-line); border-radius: 14px;
        overflow: hidden; cursor: pointer;
        box-shadow: 0 1px 3px rgba(15,39,68,.04);
        transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
      }
      .sd-exam-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 28px rgba(15,39,68,.1);
        border-color: #cbd5e1;
      }
      .sd-exam-accent { height: 3px; }
      .sd-exam-body { padding: 1.2rem 1.25rem 1.25rem; }
      .sd-exam-badges { display: flex; gap: .4rem; flex-wrap: wrap; margin-bottom: .85rem; }
      .sd-pill {
        display: inline-flex; align-items: center; gap: .25rem;
        padding: .22rem .65rem; border-radius: 999px;
        font-size: .68rem; font-weight: 800; letter-spacing: .03em; text-transform: uppercase;
      }
      .sd-pill-official { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
      .sd-pill-practice { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
      .sd-pill-proctor { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

      .sd-experience {
        border: 1px solid #bfdbfe;
        background: linear-gradient(135deg, #eff6ff 0%, #ffffff 55%);
        border-radius: 16px;
        padding: 1.25rem 1.35rem;
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 1rem;
        align-items: center;
        box-shadow: 0 8px 24px rgba(37, 99, 235, 0.08);
      }
      .sd-experience h2 {
        margin: 0 0 0.35rem; font-size: 1.15rem; font-weight: 800; color: #0f2744;
      }
      .sd-experience p {
        margin: 0; font-size: 0.88rem; color: #475569; line-height: 1.45; max-width: 46rem;
      }
      .sd-experience-actions { display: flex; flex-direction: column; gap: 0.5rem; align-items: stretch; min-width: 180px; }
      .sd-experience .sd-start {
        background: linear-gradient(135deg, #1d4ed8, #2563eb);
        box-shadow: 0 6px 16px rgba(37, 99, 235, 0.28);
      }
      .sd-experience-meta { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.75rem; }

      .sd-exam-title {
        margin: 0 0 .25rem; font-size: 1.05rem; font-weight: 800; color: var(--sd-navy); line-height: 1.3;
      }
      .sd-exam-sub { margin: 0 0 1rem; font-size: .84rem; color: var(--sd-muted); font-weight: 500; }
      .sd-meta { display: flex; flex-wrap: wrap; gap: .45rem; margin-bottom: 1.1rem; }
      .sd-chip {
        background: #f8fafc; border: 1px solid var(--sd-line); color: #334155;
        padding: .3rem .6rem; border-radius: 7px; font-size: .75rem; font-weight: 600;
      }
      .sd-start {
        width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: .45rem;
        background: linear-gradient(135deg, var(--sd-red) 0%, var(--sd-red-dark) 100%);
        color: #fff; border: none; border-radius: 10px; padding: .78rem 1rem;
        font-size: .9rem; font-weight: 800; cursor: pointer; font-family: inherit;
        box-shadow: 0 6px 16px rgba(196,30,58,.22);
        transition: transform .15s ease, box-shadow .15s ease;
      }
      .sd-start:hover {
        transform: translateY(-1px);
        box-shadow: 0 10px 22px rgba(196,30,58,.3);
      }

      .sd-empty {
        grid-column: 1 / -1; text-align: center;
        background: #fff; border: 1px solid var(--sd-line); border-radius: 14px;
        padding: 2.75rem 1.5rem; box-shadow: 0 1px 3px rgba(15,39,68,.04);
      }
      .sd-empty h3 { margin: 0 0 .4rem; font-size: 1.05rem; font-weight: 800; color: var(--sd-navy); }
      .sd-empty p { margin: 0; color: var(--sd-muted); font-size: .88rem; }

      .sd-quick-links {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: .75rem;
        margin-top: 1.25rem;
      }
      .sd-quick {
        background: #fff; border: 1px solid var(--sd-line); border-radius: 12px;
        padding: 1rem 1.1rem; cursor: pointer; text-align: left; font-family: inherit;
        transition: border-color .15s, box-shadow .15s; color: inherit;
      }
      .sd-quick:hover { border-color: #cbd5e1; box-shadow: 0 6px 16px rgba(15,39,68,.06); }
      .sd-quick strong { display: block; font-size: .92rem; font-weight: 800; color: var(--sd-navy); }
      .sd-quick span { display: block; margin-top: .25rem; font-size: .78rem; color: var(--sd-muted); font-weight: 500; }

      .sd-footer {
        max-width: 1120px; margin: 0 auto; padding: 0 1.25rem 2rem;
        text-align: center; color: #94a3b8; font-size: .75rem; font-weight: 600;
      }

      @media (max-width: 720px) {
        .sd-profile { grid-template-columns: 1fr; text-align: center; justify-items: center; }
        .sd-profile-body { grid-template-columns: 1fr; justify-items: center; text-align: center; }
        .sd-profile-body .sd-name { margin-top: .75rem; }
        .sd-facts { width: 100%; }
        .sd-stats { grid-template-columns: 1fr; }
        .sd-brand-meta { display: none; }
        .sd-experience { grid-template-columns: 1fr; }
        .sd-experience-actions { min-width: 0; }
        .sd-nav-btn { padding: .75rem .7rem .65rem; font-size: .8rem; }
      }
    `;
    document.head.appendChild(st);
  }

  render() {
    if (!this.container) return;
    this._injectStyles();

    const user = this.currentSession?.user || {};
    const initial = (user.name || user.identifier || 'S').charAt(0).toUpperCase();
    const greeting = this._getGreeting();
    const photoUrl = user.photo_url || user.photoUrl || '';
    const course = user.course || '';
    const centre = user.centre_name || user.centerName || '—';
    const slot = user.exam_slot || user.examSlot || '—';
    const windowLabel = user.time_window || user.timeWindow || '—';
    const name = user.name || user.identifier || 'Student';
    const practiceReady = (this.practiceExams || []).length > 0;

    const photoHtml = photoUrl
      ? `<img src="${this._esc(photoUrl)}" alt="${this._esc(name)}" onerror="this.style.display='none';var f=this.nextElementSibling;if(f)f.style.display='flex'"><div class="sd-photo-fallback" style="display:none">${initial}</div>`
      : `<div class="sd-photo-fallback">${initial}</div>`;

    const tabs = [
      { id: 'dashboard', label: 'Dashboard', icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>' },
      { id: 'profile', label: 'Profile', icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>' },
      { id: 'exams', label: 'My Exams', icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z"/></svg>' },
      { id: 'results', label: 'My Results', icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6a7.5 7.5 0 107.5 7.5h-7.5V6z"/><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5H21A7.5 7.5 0 0013.5 3v7.5z"/></svg>' },
    ];

    const navHtml = tabs.map((t) => `
      <button type="button" class="sd-nav-btn${this.activeTab === t.id ? ' is-active' : ''}" data-tab="${t.id}" role="tab" aria-selected="${this.activeTab === t.id}">
        ${t.icon}${t.label}
      </button>`).join('');

    this.container.innerHTML = `
      <div class="sd-root">
        <header class="sd-topbar">
          <div class="sd-topbar-inner">
            <div class="sd-brand">
              <img src="assets/giit_brand_logo.png" alt="GIIT" onerror="this.src='assets/logo.png'">
              <div class="sd-brand-meta">
                <strong>Gyanam Institute of Information Technology</strong>
                <span>Student Exam Portal</span>
              </div>
            </div>
            <button type="button" id="logout-btn" class="sd-logout">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9"/></svg>
              Logout
            </button>
          </div>
          <nav class="sd-nav" role="tablist" aria-label="Student portal sections">
            ${navHtml}
          </nav>
        </header>

        <main class="sd-main">
          <section class="sd-panel${this.activeTab === 'dashboard' ? ' is-active' : ''}" data-panel="dashboard" role="tabpanel">
            <div class="sd-welcome">
              <div>
                <h1>${greeting}, ${this._esc(name.split(' ')[0])}</h1>
                <p>Welcome to your GIIT exam portal. Manage exams, profile, and results from the tabs above.</p>
              </div>
            </div>
            <div class="sd-stats">
              <div class="sd-stat">
                <em>Assigned exams</em>
                <strong>${this.availableExams.length}</strong>
                <span>Official papers</span>
              </div>
              <div class="sd-stat">
                <em>Practice</em>
                <strong>${practiceReady ? 'Ready' : 'Soon'}</strong>
                <span>${practiceReady ? 'Try the demo flow' : 'Awaiting admin setup'}</span>
              </div>
              <div class="sd-stat">
                <em>Centre</em>
                <strong style="font-size:1.05rem">${this._esc(centre)}</strong>
                <span>Slot ${this._esc(slot)}</span>
              </div>
            </div>
            ${this.renderExperienceSection()}
            <div class="sd-quick-links">
              <button type="button" class="sd-quick" data-goto="exams">
                <strong>My Exams →</strong>
                <span>View and start assigned exams</span>
              </button>
              <button type="button" class="sd-quick" data-goto="profile">
                <strong>Profile →</strong>
                <span>Registration ID, centre &amp; slot</span>
              </button>
              <button type="button" class="sd-quick" data-goto="results">
                <strong>My Results →</strong>
                <span>Scores, history &amp; certificates</span>
              </button>
            </div>
          </section>

          <section class="sd-panel${this.activeTab === 'profile' ? ' is-active' : ''}" data-panel="profile" role="tabpanel">
            <div class="sd-profile-card">
              <div class="sd-profile-banner"></div>
              <div class="sd-profile-body">
                <div class="sd-photo">${photoHtml}</div>
                <div style="min-width:0;width:100%">
                  <h1 class="sd-name">${this._esc(name)}</h1>
                  <p class="sd-role-line">Candidate · GIIT Student Exam Portal</p>
                </div>
                <div class="sd-profile-grid">
                  <div class="sd-profile-field"><em>Registration ID</em><strong>${this._esc(user.identifier || '—')}</strong></div>
                  <div class="sd-profile-field"><em>Centre</em><strong>${this._esc(centre)}</strong></div>
                  <div class="sd-profile-field"><em>Exam Slot</em><strong>${this._esc(slot)}</strong></div>
                  <div class="sd-profile-field"><em>Time Window</em><strong>${this._esc(windowLabel)}</strong></div>
                  ${course ? `<div class="sd-profile-field"><em>Course</em><strong>${this._esc(course)}</strong></div>` : ''}
                </div>
              </div>
            </div>
          </section>

          <section class="sd-panel${this.activeTab === 'exams' ? ' is-active' : ''}" data-panel="exams" role="tabpanel">
            ${this.renderExperienceSection()}
            <div class="sd-section">
              <div class="sd-section-head">
                <div class="sd-section-title">
                  <div class="sd-section-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z"/></svg>
                  </div>
                  <div>
                    <h2>Available Exams</h2>
                    <p>${this.availableExams.length} official exam${this.availableExams.length !== 1 ? 's' : ''} assigned to you</p>
                  </div>
                </div>
                <button type="button" id="refresh-exams-btn" class="sd-refresh">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                  Refresh
                </button>
              </div>
              <div id="available-exams-container" class="sd-exam-grid">
                ${this.renderAvailableExams()}
              </div>
            </div>
          </section>

          <section class="sd-panel${this.activeTab === 'results' ? ' is-active' : ''}" data-panel="results" role="tabpanel">
            <div class="sd-section" style="margin-top:0">
              <div id="exam-history-container"></div>
            </div>
            <div class="sd-section">
              <div id="certificates-container"></div>
            </div>
          </section>
        </main>

        <footer class="sd-footer">
          A unit of IT Training — Gyanam India (ISO 9001 : 2015)
        </footer>
      </div>`;

    this.attachEventListeners();
  }

  switchTab(tabId) {
    if (!tabId || !this.container) return;
    this.activeTab = tabId;

    this.container.querySelectorAll('.sd-nav-btn').forEach((btn) => {
      const on = btn.dataset.tab === tabId;
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    this.container.querySelectorAll('.sd-panel').forEach((panel) => {
      panel.classList.toggle('is-active', panel.dataset.panel === tabId);
    });

    if (tabId === 'results') this._maybeLoadResults();
  }

  async _maybeLoadResults() {
    if (this._resultsLoaded || !this.container) return;
    const studentId = this.currentSession?.user?.id;
    const examHistoryContainer = this.container.querySelector('#exam-history-container');
    const certificatesContainer = this.container.querySelector('#certificates-container');
    if (!examHistoryContainer && !certificatesContainer) return;

    this._resultsLoaded = true;
    if (examHistoryContainer) {
      await this.examHistoryModule.initialize(examHistoryContainer, studentId).catch((e) => console.warn('History module error:', e));
    }
    if (certificatesContainer) {
      await this.certificationModule.initialize(certificatesContainer, studentId).catch((e) => console.warn('Certification module error:', e));
    }
  }

  _esc(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  _getGreeting() {
    const h = new Date().getHours();
    if (h < 12) return 'Good morning';
    if (h < 17) return 'Good afternoon';
    return 'Good evening';
  }

  renderExperienceSection() {
    const exam = (this.practiceExams || [])[0];
    if (!exam) {
      return `
        <section class="sd-section" style="margin-top:0" aria-label="Practice experience">
          <div class="sd-experience" style="background:#f8fafc;border-color:#e2e8f0;box-shadow:none">
            <div>
              <h2>Experience the Exam</h2>
              <p>A practice paper will appear here once your administrator enables the global Practice Exam. Use it to learn the timer, question map, and submit flow before your official exam.</p>
            </div>
          </div>
        </section>`;
    }

    const canAttempt = exam.attempt_info?.can_attempt !== false;
    const duration = exam.duration || 0;
    const totalQs = exam.total_questions || 0;
    const remaining = exam.attempt_info?.remaining;

    return `
      <section class="sd-section" style="margin-top:0" aria-label="Practice experience">
        <div class="sd-experience">
          <div>
            <div style="display:flex;gap:0.45rem;flex-wrap:wrap;margin-bottom:0.45rem">
              <span class="sd-pill sd-pill-practice">Practice · All courses</span>
              <span class="sd-pill sd-pill-official" style="background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe">Same real exam flow</span>
            </div>
            <h2>${this._esc(exam.title || 'Practice Exam')}</h2>
            <p>Try this practice paper to feel how a demo / main exam works — pre-exam steps, timer, bilingual questions, question map, and submit. It is available to every student and is not tied to your course assignment.</p>
            <div class="sd-experience-meta">
              <span class="sd-chip">${duration} min</span>
              <span class="sd-chip">${totalQs} questions</span>
              <span class="sd-chip">Pass ${exam.passing_score ?? 40}%</span>
              ${remaining != null ? `<span class="sd-chip">${remaining} attempt(s) left</span>` : ''}
            </div>
          </div>
          <div class="sd-experience-actions">
            <button type="button" class="start-exam-btn sd-start" data-exam-id="${exam.id}" ${canAttempt ? '' : 'disabled style="opacity:0.55;cursor:not-allowed"'}>
              ${canAttempt ? 'Start Practice Exam' : 'No attempts left'}
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
            </button>
          </div>
        </div>
      </section>`;
  }

  renderAvailableExams() {
    if (this.availableExams.length === 0) {
      return `
        <div class="sd-empty">
          <h3>No exams available</h3>
          <p>You do not have any exams assigned right now. Please check with your centre.</p>
        </div>`;
    }
    return this.availableExams.map((exam, i) => this.renderExamCard(exam, i)).join('');
  }

  renderExamCard(exam, index = 0) {
    const isDemo = (exam.exam_type || exam.examType) === 'demo';
    const duration = exam.duration || 0;
    const totalQs = exam.total_questions || exam.totalQuestions || 0;
    const passingScore = exam.passing_score || exam.passingScore || 60;
    const dbId = exam.id;
    const accent = isDemo ? 'linear-gradient(90deg,#f59e0b,#d97706)' : 'linear-gradient(90deg,#c41e3a,#9f1830)';
    const badge = isDemo
      ? '<span class="sd-pill sd-pill-practice">Practice</span>'
      : '<span class="sd-pill sd-pill-official">Official</span>';
    const proctor = exam.proctored
      ? '<span class="sd-pill sd-pill-proctor">Proctored</span>'
      : '';

    return `
      <article class="sd-exam-card" data-exam-id="${dbId}" style="animation-delay:${0.04 * index}s">
        <div class="sd-exam-accent" style="background:${accent}"></div>
        <div class="sd-exam-body">
          <div class="sd-exam-badges">${badge}${proctor}</div>
          <h3 class="sd-exam-title">${this._esc(exam.title)}</h3>
          <p class="sd-exam-sub">${this._esc(exam.subject || 'General')}</p>
          <div class="sd-meta">
            <span class="sd-chip">${duration} min</span>
            <span class="sd-chip">${totalQs} questions</span>
            <span class="sd-chip">Pass ${passingScore}%</span>
          </div>
          <button type="button" class="start-exam-btn sd-start" data-exam-id="${dbId}">
            Start Exam
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
          </button>
        </div>
      </article>`;
  }

  attachEventListeners() {
    const logoutBtn = this.container.querySelector('#logout-btn');
    if (logoutBtn) logoutBtn.addEventListener('click', () => this.handleLogout());

    this.container.querySelectorAll('.sd-nav-btn').forEach((btn) => {
      btn.addEventListener('click', () => this.switchTab(btn.dataset.tab));
    });

    this.container.querySelectorAll('.sd-quick[data-goto]').forEach((btn) => {
      btn.addEventListener('click', () => this.switchTab(btn.dataset.goto));
    });

    const refreshBtn = this.container.querySelector('#refresh-exams-btn');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', () => {
        const svg = refreshBtn.querySelector('svg');
        if (svg) {
          svg.style.animation = 'sd-spin 0.6s ease';
          setTimeout(() => { svg.style.animation = ''; }, 700);
        }
        this.activeTab = 'exams';
        this._resultsLoaded = false;
        this.refresh();
      });
    }

    this.container.querySelectorAll('.start-exam-btn').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const examId = e.currentTarget.dataset.examId;
        if (examId) this.handleStartExam(examId);
      });
    });

    this.container.querySelectorAll('.sd-exam-card[data-exam-id]').forEach((card) => {
      card.addEventListener('click', (e) => {
        if (e.target.closest('.start-exam-btn')) return;
        const examId = card.dataset.examId;
        if (examId) this.handleStartExam(examId);
      });
    });
  }

  async handleLogout() {
    const confirmed = await modalService.confirm(
      'Are you sure you want to logout?',
      { title: 'Logout', confirmText: 'Logout', cancelText: 'Stay', type: 'warning' }
    );
    if (confirmed) {
      this.authModule.logout();
      if (this.router) this.router.navigate('/login');
      else window.location.href = '/login.html';
    }
  }

  handleStartExam(examId) {
    if (this.router) {
      this.router.navigate(`/exam?id=${encodeURIComponent(examId)}`);
      return;
    }
    const base = (window.location.pathname.includes('/gyanamexam') ? '/gyanamexam' : '');
    window.location.href = `${base}/index.html?id=${encodeURIComponent(examId)}#/exam`;
  }

  renderError(error) {
    if (!this.container) return;
    this._injectStyles();
    this.container.innerHTML = `
      <div class="sd-root" style="display:flex;align-items:center;justify-content:center;padding:2rem">
        <div class="sd-empty" style="max-width:420px;width:100%">
          <h3>Failed to load dashboard</h3>
          <p style="margin-bottom:1.25rem">${this._esc(error.message || 'An unexpected error occurred')}</p>
          <button type="button" class="sd-start" onclick="location.reload()" style="max-width:200px;margin:0 auto">Retry</button>
        </div>
      </div>`;
  }

  async refresh() {
    if (!this.container) return;
    await this.initialize(this.container);
  }

  destroy() {
    if (this.examHistoryModule) this.examHistoryModule.destroy();
    if (this.certificationModule) this.certificationModule.destroy();
    if (this.container) this.container.innerHTML = '';
    this.container = null;
  }
}

export default StudentDashboard;
