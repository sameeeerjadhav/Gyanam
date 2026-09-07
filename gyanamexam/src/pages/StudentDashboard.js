/**
 * StudentDashboard — GIIT branded student exam home
 * Profile card with photo + centre details, available exams, history, certificates.
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
      await this.initializeModules();
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
    this.availableExams = Array.isArray(data) ? data : (data.data || []);
  }

  _injectStyles() {
    if (document.getElementById('sd-giit-styles')) return;
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
        from { opacity: 0; transform: translateY(14px); }
        to { opacity: 1; transform: translateY(0); }
      }

      .sd-topbar {
        background: #fff;
        border-bottom: 1px solid var(--sd-line);
        position: sticky; top: 0; z-index: 20;
        box-shadow: 0 1px 0 rgba(15,39,68,.04);
      }
      .sd-topbar-inner {
        max-width: 1120px; margin: 0 auto;
        padding: .7rem 1.25rem;
        display: flex; align-items: center; justify-content: space-between; gap: 1rem;
      }
      .sd-brand { display: flex; align-items: center; gap: .85rem; min-width: 0; }
      .sd-brand img {
        height: 46px; width: auto; max-width: 180px; object-fit: contain; display: block;
      }
      .sd-brand-meta { min-width: 0; }
      .sd-brand-meta strong {
        display: block; font-size: .95rem; font-weight: 800; color: var(--sd-navy);
        letter-spacing: .01em; line-height: 1.2;
      }
      .sd-brand-meta span {
        display: block; font-size: .72rem; color: var(--sd-muted); font-weight: 600;
        text-transform: uppercase; letter-spacing: .06em; margin-top: .1rem;
      }
      .sd-logout {
        display: inline-flex; align-items: center; gap: .4rem;
        background: #fff5f5; border: 1px solid #fecaca; color: var(--sd-red);
        padding: .45rem .9rem; border-radius: 8px; font-size: .8rem; font-weight: 700;
        cursor: pointer; font-family: inherit; transition: background .15s;
      }
      .sd-logout:hover { background: #fee2e2; }

      .sd-main { max-width: 1120px; margin: 0 auto; padding: 1.35rem 1.25rem 2.5rem; }

      .sd-profile {
        background: linear-gradient(135deg, var(--sd-navy) 0%, var(--sd-navy-2) 58%, #1e4d7b 100%);
        border-radius: 16px; padding: 1.35rem 1.4rem;
        color: #fff; display: grid; grid-template-columns: auto 1fr;
        gap: 1.25rem; align-items: center;
        box-shadow: 0 12px 28px rgba(15,39,68,.18);
        animation: sd-fade-up .45s ease both;
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

      .sd-section { margin-top: 1.75rem; animation: sd-fade-up .45s ease both; }
      .sd-section:nth-of-type(2) { animation-delay: .05s; }
      .sd-section:nth-of-type(3) { animation-delay: .1s; }
      .sd-section:nth-of-type(4) { animation-delay: .15s; }

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

      .sd-footer {
        max-width: 1120px; margin: 0 auto; padding: 0 1.25rem 2rem;
        text-align: center; color: #94a3b8; font-size: .75rem; font-weight: 600;
      }

      @media (max-width: 720px) {
        .sd-profile { grid-template-columns: 1fr; text-align: center; justify-items: center; }
        .sd-facts { width: 100%; }
        .sd-brand img { height: 40px; }
        .sd-brand-meta strong { font-size: .86rem; }
        .sd-name { font-size: 1.25rem; }
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

    const photoHtml = photoUrl
      ? `<img src="${this._esc(photoUrl)}" alt="${this._esc(user.name || 'Student')}" onerror="this.style.display='none';var f=this.nextElementSibling;if(f)f.style.display='flex'"><div class="sd-photo-fallback" style="display:none">${initial}</div>`
      : `<div class="sd-photo-fallback">${initial}</div>`;

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
        </header>

        <main class="sd-main">
          <section class="sd-profile" aria-label="Student profile">
            <div class="sd-photo">${photoHtml}</div>
            <div style="min-width:0;width:100%">
              <p class="sd-hello">${greeting}</p>
              <h1 class="sd-name">${this._esc(user.name || user.identifier || 'Student')}</h1>
              <p class="sd-role-line">Candidate · Ready for examination</p>
              <div class="sd-facts">
                <div class="sd-fact"><em>Registration ID</em><strong>${this._esc(user.identifier || '—')}</strong></div>
                <div class="sd-fact"><em>Centre</em><strong>${this._esc(centre)}</strong></div>
                <div class="sd-fact"><em>Exam Slot</em><strong>${this._esc(slot)}</strong></div>
                <div class="sd-fact"><em>Time Window</em><strong>${this._esc(windowLabel)}</strong></div>
                ${course ? `<div class="sd-fact"><em>Course</em><strong>${this._esc(course)}</strong></div>` : ''}
              </div>
            </div>
          </section>

          <section class="sd-section">
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
          </section>

          <section class="sd-section">
            <div id="exam-history-container"></div>
          </section>

          <section class="sd-section">
            <div id="certificates-container"></div>
          </section>
        </main>

        <footer class="sd-footer">
          A unit of IT Training — Gyanam India (ISO 9001 : 2015)
        </footer>
      </div>`;

    this.attachEventListeners();
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

  async initializeModules() {
    const studentId = this.currentSession.user.id;
    const examHistoryContainer = this.container.querySelector('#exam-history-container');
    if (examHistoryContainer) {
      await this.examHistoryModule.initialize(examHistoryContainer, studentId).catch(e => console.warn('History module error:', e));
    }
    const certificatesContainer = this.container.querySelector('#certificates-container');
    if (certificatesContainer) {
      await this.certificationModule.initialize(certificatesContainer, studentId).catch(e => console.warn('Certification module error:', e));
    }
  }

  attachEventListeners() {
    const logoutBtn = this.container.querySelector('#logout-btn');
    if (logoutBtn) logoutBtn.addEventListener('click', () => this.handleLogout());

    const refreshBtn = this.container.querySelector('#refresh-exams-btn');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', () => {
        const svg = refreshBtn.querySelector('svg');
        if (svg) {
          svg.style.animation = 'sd-spin 0.6s ease';
          setTimeout(() => { svg.style.animation = ''; }, 700);
        }
        this.refresh();
      });
    }

    this.container.querySelectorAll('.start-exam-btn').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const examId = e.currentTarget.dataset.examId;
        if (examId) this.handleStartExam(examId);
      });
    });

    this.container.querySelectorAll('.sd-exam-card[data-exam-id]').forEach(card => {
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
