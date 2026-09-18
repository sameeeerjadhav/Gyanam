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
    this.profile = null;
    this.activeTab = 'dashboard';
    this._resultsLoaded = false;
    this._clockTimer = null;
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
        this.profile = me;
        this.authModule.updateSessionUser({
          id: me.id,
          identifier: me.identifier,
          name: me.name,
          centre_name: me.centre_name,
          exam_slot: me.exam_slot,
          time_window: me.time_window,
          photo_url: me.photo_url,
          course: me.course,
          role: me.role || 'student',
        });
        this.currentSession = this.authModule.getCurrentSession();
      }
    } catch (_) {
      /* keep cached session user */
      this.profile = this.profile || this.authModule.getCurrentSession()?.user || null;
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
    // Prefer unlocked first, then demos, then locked mains
    this.availableExams = all
      .filter((e) => !e.is_global_practice)
      .sort((a, b) => {
        const rank = (e) => {
          if (e.locked) return 2;
          if ((e.exam_type || '') === 'demo' || e.is_demo) return 0;
          return 1;
        };
        return rank(a) - rank(b);
      });
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
        display: flex;
        flex-direction: column;
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

      .sd-chrome { position: sticky; top: 0; z-index: 30; }

      .sd-header {
        position: relative;
        background: #fff;
        border-bottom: 1px solid var(--sd-line);
        overflow: hidden;
        isolation: isolate;
      }
      .sd-header-pattern {
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
      .sd-header-inner {
        position: relative;
        z-index: 1;
        max-width: 1120px;
        margin: 0 auto;
        padding: 1.05rem 1.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
      }
      .sd-brand { display: flex; align-items: center; gap: 1rem; min-width: 0; }
      .sd-brand-logo {
        width: 72px;
        height: 72px;
        object-fit: contain;
        flex-shrink: 0;
        display: block;
        filter: drop-shadow(0 2px 6px rgba(15, 39, 68, 0.12));
      }
      .sd-brand-meta { min-width: 0; }
      .sd-brand-meta strong {
        display: block; font-size: 1.08rem; font-weight: 800; color: var(--sd-navy);
        letter-spacing: -.01em; line-height: 1.2;
      }
      .sd-brand-meta span {
        display: block; font-size: .78rem; color: var(--sd-muted); font-weight: 600;
        text-transform: uppercase; letter-spacing: .04em; margin-top: .15rem;
      }
      .sd-logout {
        display: inline-flex; align-items: center; gap: .4rem;
        background: #fff5f5; border: 1px solid #fecaca; color: var(--sd-red);
        padding: .5rem .95rem; border-radius: 8px; font-size: .8rem; font-weight: 700;
        cursor: pointer; font-family: inherit; transition: background .15s; flex-shrink: 0;
        position: relative; z-index: 1;
      }
      .sd-logout:hover { background: #fee2e2; }

      .sd-nav {
        background: var(--sd-navy);
        box-shadow: 0 2px 8px rgba(15, 39, 68, 0.18);
      }
      .sd-nav-inner {
        max-width: 1120px; margin: 0 auto;
        padding: 0 1.25rem;
        display: flex; gap: .2rem; overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
      }
      .sd-nav-inner::-webkit-scrollbar { display: none; }
      .sd-nav-btn {
        appearance: none; background: transparent; border: none;
        font-family: inherit; cursor: pointer;
        padding: .75rem 1.1rem;
        font-size: .82rem; font-weight: 700; color: rgba(255,255,255,.72);
        white-space: nowrap; transition: background .15s, color .15s;
        display: inline-flex; align-items: center; gap: .45rem;
      }
      .sd-nav-btn:hover { color: #fff; background: rgba(255,255,255,.08); }
      .sd-nav-btn.is-active {
        background: var(--sd-red);
        color: #fff;
      }
      .sd-nav-btn svg { width: 15px; height: 15px; opacity: .9; }

      .sd-main { flex: 1; max-width: 1120px; width: 100%; margin: 0 auto; padding: 1.35rem 1.25rem 2.5rem; }
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
        background:
          radial-gradient(circle at 12% 40%, rgba(196, 30, 58, 0.22), transparent 42%),
          radial-gradient(circle at 88% 20%, rgba(255,255,255,0.1), transparent 40%),
          linear-gradient(135deg, var(--sd-navy) 0%, var(--sd-navy-2) 55%, #1e4d7b 100%);
        height: 110px;
        position: relative;
      }
      .sd-profile-banner::after {
        content: '';
        position: absolute; inset: 0; opacity: 0.35; pointer-events: none;
        background-image: url("data:image/svg+xml,%3Csvg width='120' height='104' viewBox='0 0 120 104' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' stroke='%23ffffff' stroke-width='0.9' opacity='0.35'%3E%3Cpath d='M30 2l28 16v32L30 66 2 50V18z'/%3E%3Cpath d='M90 2l28 16v32L90 66 62 50V18z'/%3E%3Cpath d='M60 36l28 16v32L60 100 32 84V52z'/%3E%3C/g%3E%3C/svg%3E");
        background-size: 120px 104px;
      }
      .sd-profile-body {
        padding: 0 1.5rem 1.5rem;
        display: grid; grid-template-columns: auto 1fr; gap: 1.25rem;
        margin-top: -48px; position: relative;
      }
      .sd-profile-body .sd-photo {
        width: 112px; height: 136px;
        border-color: #fff; background: #e2e8f0;
        box-shadow: 0 10px 24px rgba(15,39,68,.2);
      }
      .sd-profile-body .sd-name { color: var(--sd-navy); margin-top: 56px; font-size: 1.5rem; }
      .sd-profile-body .sd-role-line { color: var(--sd-muted); }
      .sd-profile-badges {
        display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .65rem;
      }
      .sd-profile-id {
        margin-top: .45rem; font-size: .86rem; font-weight: 700; color: var(--sd-navy);
        font-variant-numeric: tabular-nums; letter-spacing: .02em;
      }
      .sd-profile-id span { color: var(--sd-muted); font-weight: 600; margin-right: .35rem; }
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
      .sd-profile-layout {
        display: grid; grid-template-columns: 1.35fr 0.85fr; gap: 1rem; align-items: start;
      }
      .sd-profile-side { display: flex; flex-direction: column; gap: 1rem; }
      .sd-profile-block {
        background: #fff; border: 1px solid var(--sd-line); border-radius: 14px;
        padding: 1.15rem 1.2rem; box-shadow: 0 1px 3px rgba(15,39,68,.04);
      }
      .sd-profile-block h3 {
        margin: 0 0 .85rem; font-size: .92rem; font-weight: 800; color: var(--sd-navy);
        display: flex; align-items: center; gap: .45rem;
      }
      .sd-profile-block h3 svg { width: 16px; height: 16px; color: var(--sd-red); flex-shrink: 0; }
      .sd-mini-stats {
        display: grid; grid-template-columns: repeat(2, 1fr); gap: .55rem;
      }
      .sd-mini-stat {
        background: #f8fafc; border: 1px solid var(--sd-line); border-radius: 10px;
        padding: .7rem .75rem; text-align: center;
      }
      .sd-mini-stat em {
        display: block; font-style: normal; font-size: .62rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: .05em; color: var(--sd-muted); margin-bottom: .2rem;
      }
      .sd-mini-stat strong {
        display: block; font-size: 1.15rem; font-weight: 800; color: var(--sd-navy);
      }
      .sd-assigned-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .55rem; }
      .sd-assigned-list li {
        display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem;
        padding: .7rem .75rem; background: #f8fafc; border: 1px solid var(--sd-line); border-radius: 10px;
      }
      .sd-assigned-list strong { display: block; font-size: .86rem; font-weight: 800; color: var(--sd-navy); }
      .sd-assigned-list span { display: block; margin-top: .15rem; font-size: .75rem; color: var(--sd-muted); font-weight: 500; }
      .sd-latest {
        display: grid; gap: .45rem;
      }
      .sd-latest-row {
        display: flex; justify-content: space-between; gap: .75rem;
        font-size: .84rem; padding: .35rem 0; border-bottom: 1px solid #f1f5f9;
      }
      .sd-latest-row:last-child { border-bottom: none; }
      .sd-latest-row em { font-style: normal; color: var(--sd-muted); font-weight: 600; }
      .sd-latest-row strong { color: var(--sd-navy); font-weight: 700; text-align: right; }
      .sd-result-pass { color: #047857 !important; }
      .sd-result-fail { color: #b91c1c !important; }
      .sd-profile-note {
        margin-top: .85rem; font-size: .78rem; color: var(--sd-muted); font-weight: 500; line-height: 1.45;
      }
      @media (max-width: 900px) {
        .sd-profile-layout { grid-template-columns: 1fr; }
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
        background: #1a1f2a;
        color: #e2e8f0;
        margin-top: auto;
      }
      .sd-footer-top {
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
      .sd-footer-top .accent { color: #f87171; }
      .sd-footer-bot {
        border-top: 1px solid rgba(255,255,255,0.1);
        padding: 0.55rem 1.5rem 0.7rem;
        text-align: center;
        font-size: 0.72rem;
        color: rgba(226, 232, 240, 0.55);
        font-weight: 500;
        letter-spacing: 0.02em;
      }

      @media (max-width: 720px) {
        .sd-profile { grid-template-columns: 1fr; text-align: center; justify-items: center; }
        .sd-profile-body { grid-template-columns: 1fr; justify-items: center; text-align: center; }
        .sd-profile-body .sd-name { margin-top: .75rem; }
        .sd-profile-badges { justify-content: center; }
        .sd-profile-id { text-align: center; }
        .sd-facts { width: 100%; }
        .sd-stats { grid-template-columns: 1fr; }
        .sd-brand-meta strong { font-size: .95rem; }
        .sd-brand-logo { width: 56px; height: 56px; }
        .sd-header-inner { padding: 0.9rem 1rem; }
        .sd-experience { grid-template-columns: 1fr; }
        .sd-experience-actions { min-width: 0; }
        .sd-nav-btn { padding: .7rem .75rem; font-size: .78rem; }
        .sd-footer-top { justify-content: center; text-align: center; }
      }
    `;
    document.head.appendChild(st);
  }

  render() {
    if (!this.container) return;
    this._injectStyles();

    const user = this.currentSession?.user || {};
    const greeting = this._getGreeting();
    const centre = user.centre_name || user.centerName || '—';
    const slot = this._formatSlot(user.exam_slot || user.examSlot);
    const name = user.name || user.identifier || 'Student';
    const resultsCount = (this.completedExams || []).length;

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

    const year = new Date().getFullYear();

    this.container.innerHTML = `
      <div class="sd-root">
        <div class="sd-chrome">
          <header class="sd-header">
            <div class="sd-header-pattern" aria-hidden="true"></div>
            <div class="sd-header-inner">
              <div class="sd-brand">
                <img class="sd-brand-logo" src="assets/giit_brand_logo.png" alt="GIIT"
                     onerror="this.onerror=null;this.src='assets/giit_logo.png';this.onerror=function(){this.src='assets/logo.png'}">
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
          </header>
          <nav class="sd-nav" role="tablist" aria-label="Student portal sections">
            <div class="sd-nav-inner">
              ${navHtml}
            </div>
          </nav>
        </div>

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
                <em>Results</em>
                <strong>${resultsCount}</strong>
                <span>Completed attempts</span>
              </div>
              <div class="sd-stat">
                <em>Centre</em>
                <strong style="font-size:1.05rem">${this._esc(centre)}</strong>
                <span>Slot ${this._esc(slot)}</span>
              </div>
            </div>
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
            ${this.renderProfilePanel()}
          </section>

          <section class="sd-panel${this.activeTab === 'exams' ? ' is-active' : ''}" data-panel="exams" role="tabpanel">
            <div class="sd-section" style="margin-top:0">
              <div class="sd-section-head">
                <div class="sd-section-title">
                  <div class="sd-section-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z"/></svg>
                  </div>
                  <div>
                    <h2>Available Exams</h2>
                    <p>${this.availableExams.length} exam${this.availableExams.length !== 1 ? 's' : ''} assigned to you</p>
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
          <div class="sd-footer-top">
            <div>Copyright © ${year} <span class="accent">GIIT</span></div>
            <div>Time <span class="accent" id="sd-live-clock">—</span></div>
          </div>
          <div class="sd-footer-bot">
            A unit of IT Training — Gyanam India Educational Services (ISO 9001 : 2015)
          </div>
        </footer>
      </div>`;

    this.attachEventListeners();
    this._startClock();
  }

  _startClock() {
    if (this._clockTimer) {
      clearInterval(this._clockTimer);
      this._clockTimer = null;
    }
    const el = this.container?.querySelector('#sd-live-clock');
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

  _formatSlot(slot) {
    const map = { SLOT1: 'Slot 1', SLOT2: 'Slot 2', SLOT3: 'Slot 3' };
    const key = String(slot || '').toUpperCase();
    return map[key] || slot || '—';
  }

  _formatWindow(win) {
    const map = { MORNING: 'Morning', AFTERNOON: 'Afternoon', EVENING: 'Evening' };
    const key = String(win || '').toUpperCase();
    return map[key] || win || '—';
  }

  _formatDate(iso) {
    if (!iso) return '—';
    try {
      return new Date(iso).toLocaleDateString('en-IN', {
        day: '2-digit', month: 'short', year: 'numeric',
      });
    } catch (_) {
      return '—';
    }
  }

  _formatDateTime(iso) {
    if (!iso) return '—';
    try {
      const d = new Date(iso);
      return `${d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })} · ${d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })}`;
    } catch (_) {
      return '—';
    }
  }

  _formatDuration(seconds) {
    if (seconds == null || seconds === '') return '—';
    const n = Number(seconds);
    if (!Number.isFinite(n) || n < 0) return '—';
    const m = Math.floor(n / 60);
    const s = Math.floor(n % 60);
    if (m <= 0) return `${s}s`;
    return `${m}m ${String(s).padStart(2, '0')}s`;
  }

  renderProfilePanel() {
    const user = this.profile || this.currentSession?.user || {};
    const name = user.name || user.identifier || 'Student';
    const initial = name.charAt(0).toUpperCase();
    const photoUrl = user.photo_url || user.photoUrl || '';
    const course = user.course || '';
    const centre = user.centre_name || user.centerName || '—';
    const slot = this._formatSlot(user.exam_slot || user.examSlot);
    const windowLabel = this._formatWindow(user.time_window || user.timeWindow);
    const identifier = user.identifier || '—';
    const registered = this._formatDate(user.registered_at || user.created_at);
    const updated = this._formatDate(user.profile_updated_at || user.updated_at);
    const assigned = Array.isArray(user.assigned_exams) ? user.assigned_exams : [];
    const assignedCount = user.assigned_exams_count ?? assigned.length;
    const attempts = user.attempts_count ?? 0;
    const passed = user.passed_count ?? 0;
    const failed = user.failed_count ?? 0;
    const best = user.best_score != null ? `${user.best_score}%` : '—';
    const latest = user.latest_attempt || null;

    const photoHtml = photoUrl
      ? `<img src="${this._esc(photoUrl)}" alt="${this._esc(name)}" onerror="this.style.display='none';var f=this.nextElementSibling;if(f)f.style.display='flex'"><div class="sd-photo-fallback" style="display:none">${initial}</div>`
      : `<div class="sd-photo-fallback">${initial}</div>`;

    const assignedHtml = assigned.length
      ? `<ul class="sd-assigned-list">${assigned.map((e) => `
          <li>
            <div>
              <strong>${this._esc(e.title || 'Exam')}</strong>
              <span>${this._esc(e.subject || 'General')} · ${e.duration || 0} min · ${e.total_questions || 0} Qs</span>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:0.25rem;flex-shrink:0">
              <span class="sd-pill ${(e.exam_type || '') === 'demo' ? 'sd-pill-practice' : 'sd-pill-official'}">${(e.exam_type || '') === 'demo' ? 'Demo' : 'Official'}</span>
              ${e.proctored ? '<span class="sd-pill sd-pill-proctor">Proctored</span>' : ''}
            </div>
          </li>`).join('')}</ul>`
      : `<div class="sd-empty" style="padding:1.5rem 1rem"><h3 style="font-size:0.95rem">No exams assigned</h3><p>Your centre has not assigned an official paper yet.</p></div>`;

    const latestHtml = latest
      ? `<div class="sd-latest">
          <div class="sd-latest-row"><em>Exam</em><strong>${this._esc(latest.exam_title || '—')}</strong></div>
          <div class="sd-latest-row"><em>Score</em><strong>${latest.score != null ? `${latest.score}%` : '—'}</strong></div>
          <div class="sd-latest-row"><em>Result</em><strong class="${latest.result === 'pass' ? 'sd-result-pass' : latest.result === 'fail' ? 'sd-result-fail' : ''}">${this._esc((latest.result || '—').toString().toUpperCase())}</strong></div>
          <div class="sd-latest-row"><em>Correct</em><strong>${latest.correct_answers ?? '—'} / ${latest.total_questions ?? '—'}</strong></div>
          <div class="sd-latest-row"><em>Duration</em><strong>${this._formatDuration(latest.duration_taken)}</strong></div>
          <div class="sd-latest-row"><em>Submitted</em><strong>${this._formatDateTime(latest.submitted_at)}</strong></div>
        </div>`
      : `<p class="sd-profile-note" style="margin:0">No attempts yet. Complete an exam to see your latest result here.</p>`;

    return `
      <div class="sd-profile-layout">
        <div>
          <div class="sd-profile-card">
            <div class="sd-profile-banner" aria-hidden="true"></div>
            <div class="sd-profile-body">
              <div class="sd-photo">${photoHtml}</div>
              <div style="min-width:0;width:100%">
                <h1 class="sd-name">${this._esc(name)}</h1>
                <p class="sd-role-line">Registered candidate · GIIT Student Exam Portal</p>
                <p class="sd-profile-id"><span>Reg. ID</span>${this._esc(identifier)}</p>
                <div class="sd-profile-badges">
                  <span class="sd-pill sd-pill-official">Active student</span>
                  ${course ? `<span class="sd-pill" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe">${this._esc(course)}</span>` : ''}
                  <span class="sd-pill" style="background:#f8fafc;color:#334155;border:1px solid #e2e8f0">${this._esc(windowLabel)}</span>
                </div>
              </div>
              <div class="sd-profile-grid">
                <div class="sd-profile-field"><em>Registration ID</em><strong>${this._esc(identifier)}</strong></div>
                <div class="sd-profile-field"><em>Full name</em><strong>${this._esc(name)}</strong></div>
                <div class="sd-profile-field"><em>Course</em><strong>${this._esc(course || 'Not set')}</strong></div>
                <div class="sd-profile-field"><em>Exam centre</em><strong>${this._esc(centre)}</strong></div>
                <div class="sd-profile-field"><em>Exam slot</em><strong>${this._esc(slot)}</strong></div>
                <div class="sd-profile-field"><em>Time window</em><strong>${this._esc(windowLabel)}</strong></div>
                <div class="sd-profile-field"><em>Portal role</em><strong>Student</strong></div>
                <div class="sd-profile-field"><em>Account registered</em><strong>${this._esc(registered)}</strong></div>
                <div class="sd-profile-field"><em>Profile last updated</em><strong>${this._esc(updated)}</strong></div>
                <div class="sd-profile-field"><em>Assigned exams</em><strong>${assignedCount}</strong></div>
                <div class="sd-profile-field"><em>Total attempts</em><strong>${attempts}</strong></div>
                <div class="sd-profile-field"><em>Best score</em><strong>${this._esc(best)}</strong></div>
              </div>
              <p class="sd-profile-note" style="grid-column:1/-1">
                Profile details are issued by your ATC / centre. Contact your centre if any information needs correction.
              </p>
            </div>
          </div>
        </div>
        <div class="sd-profile-side">
          <div class="sd-profile-block">
            <h3>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/></svg>
              Exam summary
            </h3>
            <div class="sd-mini-stats">
              <div class="sd-mini-stat"><em>Assigned</em><strong>${assignedCount}</strong></div>
              <div class="sd-mini-stat"><em>Attempts</em><strong>${attempts}</strong></div>
              <div class="sd-mini-stat"><em>Passed</em><strong class="sd-result-pass">${passed}</strong></div>
              <div class="sd-mini-stat"><em>Failed</em><strong class="sd-result-fail">${failed}</strong></div>
            </div>
          </div>
          <div class="sd-profile-block">
            <h3>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z"/></svg>
              Assigned exams
            </h3>
            ${assignedHtml}
          </div>
          <div class="sd-profile-block">
            <h3>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              Latest attempt
            </h3>
            ${latestHtml}
          </div>
        </div>
      </div>`;
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

  renderAvailableExams() {
    if (this.availableExams.length === 0) {
      return `
        <div class="sd-empty">
          <h3>No exams available</h3>
          <p>No demo or main exams match your registered course yet. Ask your centre to confirm your course and schedule.</p>
        </div>`;
    }
    return this.availableExams.map((exam, i) => this.renderExamCard(exam, i)).join('');
  }

  renderExamCard(exam, index = 0) {
    const isDemo = (exam.exam_type || exam.examType) === 'demo' || exam.is_demo;
    const locked = !!exam.locked || exam.access_status === 'awaiting_schedule' || exam.access_status === 'awaiting_hall_ticket';
    const unlimited = !!exam.attempt_info?.unlimited || isDemo;
    const canAttempt = !locked && (unlimited || exam.attempt_info?.can_attempt !== false);
    const duration = exam.duration || 0;
    const totalQs = exam.total_questions || exam.totalQuestions || 0;
    const passingScore = exam.passing_score || exam.passingScore || 60;
    const dbId = exam.id;
    const accent = locked
      ? 'linear-gradient(90deg,#94a3b8,#64748b)'
      : (isDemo ? 'linear-gradient(90deg,#f59e0b,#d97706)' : 'linear-gradient(90deg,#c41e3a,#9f1830)');
    const badge = locked
      ? '<span class="sd-pill" style="background:#f1f5f9;color:#475569;border:1px solid #e2e8f0">Locked</span>'
      : (isDemo
        ? '<span class="sd-pill sd-pill-practice">Demo</span>'
        : '<span class="sd-pill sd-pill-official">Official</span>');
    const proctor = exam.proctored
      ? '<span class="sd-pill sd-pill-proctor">Proctored</span>'
      : '';
    const lockNote = locked
      ? `<p class="sd-exam-sub" style="color:#b45309;margin-top:0.35rem">${this._esc(exam.lock_reason || 'Locked until your ATC generates your hall ticket.')}</p>`
      : '';
    const startBtn = locked
      ? `<button type="button" class="sd-start" disabled style="opacity:0.55;cursor:not-allowed;background:#94a3b8;box-shadow:none">
            Locked until hall ticket
          </button>`
      : (canAttempt
        ? `<button type="button" class="start-exam-btn sd-start" data-exam-id="${dbId}">
            ${isDemo ? 'Start Demo' : 'Start Exam'}
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
          </button>`
        : `<button type="button" class="sd-start" disabled style="opacity:0.55;cursor:not-allowed">No attempts left</button>`);

    const attemptsChip = unlimited
      ? '<span class="sd-chip">Unlimited attempts</span>'
      : (exam.attempt_info
        ? `<span class="sd-chip">${exam.attempt_info.remaining ?? 0} attempt(s) left</span>`
        : '');

    return `
      <article class="sd-exam-card${locked ? ' is-locked' : ''}" ${locked ? '' : `data-exam-id="${dbId}"`} style="animation-delay:${0.04 * index}s${locked ? ';opacity:0.92' : ''}">
        <div class="sd-exam-accent" style="background:${accent}"></div>
        <div class="sd-exam-body">
          <div class="sd-exam-badges">${badge}${proctor}</div>
          <h3 class="sd-exam-title">${this._esc(exam.title)}</h3>
          <p class="sd-exam-sub">${this._esc(exam.subject || 'General')}</p>
          ${lockNote}
          <div class="sd-meta">
            <span class="sd-chip">${duration} min</span>
            <span class="sd-chip">${totalQs} questions</span>
            <span class="sd-chip">Pass ${passingScore}%</span>
            ${attemptsChip}
          </div>
          ${startBtn}
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
    if (this._clockTimer) {
      clearInterval(this._clockTimer);
      this._clockTimer = null;
    }
    if (this.examHistoryModule) this.examHistoryModule.destroy();
    if (this.certificationModule) this.certificationModule.destroy();
    if (this.container) this.container.innerHTML = '';
    this.container = null;
  }
}

export default StudentDashboard;
