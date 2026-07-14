/**
 * StudentDashboard - Premium student interface
 *
 * Modern glassmorphism design with smooth micro-animations,
 * responsive exam cards, and polished layout.
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

  _renderLoading() {
    if (!this.container) return;
    this.container.innerHTML = `
      <div style="min-height:100vh;background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%);display:flex;align-items:center;justify-content:center;font-family:'Inter',sans-serif">
        <div style="text-align:center">
          <div style="width:56px;height:56px;border:3px solid rgba(255,255,255,0.15);border-top-color:#60a5fa;border-radius:50%;animation:spin 0.8s linear infinite;margin:0 auto 1.5rem"></div>
          <style>@keyframes spin{to{transform:rotate(360deg)}}</style>
          <p style="color:#94a3b8;font-weight:500;font-size:0.95rem">Loading your dashboard...</p>
        </div>
      </div>`;
  }

  async loadAvailableExams() {
    const data = await ApiClient.getStudentExams();
    this.availableExams = Array.isArray(data) ? data : (data.data || []);
  }

  render() {
    if (!this.container) return;
    const user = this.currentSession.user;
    const initial = (user.name || user.identifier || 'S').charAt(0).toUpperCase();
    const greeting = this._getGreeting();

    this.container.innerHTML = `
      <style>
        @keyframes fadeInUp { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
        @keyframes shimmer { 0% { background-position:-200% 0; } 100% { background-position:200% 0; } }
        @keyframes pulse-ring { 0% { box-shadow: 0 0 0 0 rgba(59,130,246,0.4); } 70% { box-shadow: 0 0 0 10px rgba(59,130,246,0); } }
        .sd-card-hover:hover { transform: translateY(-4px); box-shadow: 0 20px 40px rgba(0,0,0,0.12) !important; }
        .sd-card-hover { transition: all 0.3s cubic-bezier(0.4,0,0.2,1); }
        .sd-start-btn:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(29,78,216,0.35); }
        .sd-start-btn { transition: all 0.25s ease; }
        .sd-info-tag:hover { background: rgba(255,255,255,0.12); }
        .sd-logout-btn:hover { background: rgba(239,68,68,0.15) !important; color: #ef4444 !important; }
      </style>

      <div style="min-height:100vh;background:#f1f5f9;font-family:'Inter',sans-serif">
        <!-- Hero Header -->
        <header style="background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 50%,#1e40af 100%);padding:0;position:relative;overflow:hidden">
          <!-- Decorative circles -->
          <div style="position:absolute;top:-60px;right:-40px;width:200px;height:200px;border-radius:50%;background:rgba(59,130,246,0.1);pointer-events:none"></div>
          <div style="position:absolute;bottom:-80px;left:10%;width:300px;height:300px;border-radius:50%;background:rgba(96,165,250,0.06);pointer-events:none"></div>

          <div style="max-width:1200px;margin:0 auto;padding:2rem 2rem 2.5rem;position:relative;z-index:1">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1.5rem">
              <!-- Left: Avatar + Greeting -->
              <div style="display:flex;align-items:center;gap:1.25rem;animation:fadeInUp 0.5s ease-out">
                <div style="width:56px;height:56px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;font-weight:800;color:white;box-shadow:0 8px 24px rgba(59,130,246,0.25);flex-shrink:0">${initial}</div>
                <div>
                  <p style="color:#94a3b8;font-size:0.82rem;font-weight:500;margin:0">${greeting}</p>
                  <h1 style="color:white;font-size:1.5rem;font-weight:800;letter-spacing:-0.02em;margin:0.15rem 0 0">${user.name || user.identifier}</h1>
                </div>
              </div>

              <!-- Right: Info tags + Logout -->
              <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;animation:fadeInUp 0.5s ease-out 0.1s both">
                <div class="sd-info-tag" style="display:flex;align-items:center;gap:0.4rem;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.1);padding:0.4rem 0.85rem;border-radius:999px;color:#cbd5e1;font-size:0.78rem;font-weight:500;transition:background 0.2s">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5zm6-10.125a1.875 1.875 0 11-3.75 0 1.875 1.875 0 013.75 0zm1.294 6.336a6.721 6.721 0 01-3.17.789 6.721 6.721 0 01-3.168-.789 3.376 3.376 0 016.338 0z"/></svg>
                  ${user.identifier}
                </div>
                <div class="sd-info-tag" style="display:flex;align-items:center;gap:0.4rem;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.1);padding:0.4rem 0.85rem;border-radius:999px;color:#cbd5e1;font-size:0.78rem;font-weight:500;transition:background 0.2s">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3H21"/></svg>
                  ${user.centerName || 'Centre'}
                </div>
                <div class="sd-info-tag" style="display:flex;align-items:center;gap:0.4rem;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.1);padding:0.4rem 0.85rem;border-radius:999px;color:#cbd5e1;font-size:0.78rem;font-weight:500;transition:background 0.2s">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                  ${user.examSlot || 'Slot'}
                </div>
                <button id="logout-btn" class="sd-logout-btn" style="display:flex;align-items:center;gap:0.4rem;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);padding:0.4rem 0.85rem;border-radius:999px;color:#94a3b8;font-size:0.78rem;font-weight:600;cursor:pointer;transition:all 0.2s">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9"/></svg>
                  Logout
                </button>
              </div>
            </div>
          </div>
        </header>

        <!-- Main Content -->
        <main style="max-width:1200px;margin:0 auto;padding:2rem">
          <!-- Available Exams Section -->
          <section style="margin-bottom:2.5rem;animation:fadeInUp 0.5s ease-out 0.2s both">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
              <div style="display:flex;align-items:center;gap:0.75rem">
                <div style="width:36px;height:36px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);border-radius:10px;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(29,78,216,0.2)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" style="width:18px;height:18px"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z"/></svg>
                </div>
                <div>
                  <h2 style="font-size:1.125rem;font-weight:700;color:#0f172a;margin:0">Available Exams</h2>
                  <p style="font-size:0.78rem;color:#64748b;margin:0">${this.availableExams.length} exam${this.availableExams.length !== 1 ? 's' : ''} assigned to you</p>
                </div>
              </div>
              <button id="refresh-exams-btn" style="display:flex;align-items:center;gap:0.4rem;padding:0.5rem 1rem;border-radius:8px;border:1px solid #e2e8f0;background:white;color:#475569;font-size:0.82rem;font-weight:600;cursor:pointer;transition:all 0.2s;box-shadow:0 1px 3px rgba(0,0,0,0.04)" onmouseover="this.style.borderColor='#3b82f6';this.style.color='#3b82f6'" onmouseout="this.style.borderColor='#e2e8f0';this.style.color='#475569'">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                Refresh
              </button>
            </div>
            <div id="available-exams-container" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1.25rem">
              ${this.renderAvailableExams()}
            </div>
          </section>

          <!-- History Section -->
          <section style="margin-bottom:2.5rem;animation:fadeInUp 0.5s ease-out 0.3s both">
            <div id="exam-history-container"></div>
          </section>

          <!-- Certificates Section -->
          <section style="animation:fadeInUp 0.5s ease-out 0.4s both">
            <div id="certificates-container"></div>
          </section>
        </main>
      </div>`;

    this.attachEventListeners();
  }

  _getGreeting() {
    const h = new Date().getHours();
    if (h < 12) return '☀️ Good morning';
    if (h < 17) return '🌤️ Good afternoon';
    return '🌙 Good evening';
  }

  renderAvailableExams() {
    if (this.availableExams.length === 0) {
      return `
        <div style="grid-column:1/-1;text-align:center;padding:3.5rem 2rem;background:white;border-radius:16px;border:1px solid #e2e8f0;box-shadow:0 1px 4px rgba(0,0,0,0.04)">
          <div style="width:64px;height:64px;background:#f1f5f9;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem">
            <svg viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" style="width:32px;height:32px"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
          </div>
          <h3 style="font-size:1.125rem;font-weight:700;color:#1e293b;margin:0 0 0.4rem">No Exams Available</h3>
          <p style="color:#64748b;font-size:0.875rem;margin:0">You don't have any exams assigned at the moment. Check back later!</p>
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
    const delay = 0.05 * index;

    const badgeBg = isDemo ? 'linear-gradient(135deg,#f59e0b,#d97706)' : 'linear-gradient(135deg,#10b981,#059669)';
    const badgeText = isDemo ? 'Practice' : 'Official';
    const accentColor = isDemo ? '#f59e0b' : '#10b981';
    const icon = isDemo ? '📝' : '📋';

    return `
      <div class="sd-card-hover" data-exam-id="${dbId}" style="background:white;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;cursor:pointer;box-shadow:0 1px 4px rgba(0,0,0,0.04);animation:fadeInUp 0.4s ease-out ${delay}s both;position:relative">
        <!-- Top accent line -->
        <div style="height:3px;background:${badgeBg}"></div>

        <div style="padding:1.5rem">
          <!-- Badge + Icon -->
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
            <div style="display:flex;align-items:center;gap:0.4rem">
              <span style="background:${badgeBg};color:white;padding:0.25rem 0.75rem;border-radius:999px;font-size:0.7rem;font-weight:700;letter-spacing:0.03em;text-transform:uppercase">${badgeText}</span>
              ${exam.proctored ? '<span style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;padding:0.25rem 0.6rem;border-radius:999px;font-size:0.65rem;font-weight:700;letter-spacing:0.03em;display:flex;align-items:center;gap:0.25rem">🛡️ Proctored</span>' : ''}
            </div>
            <span style="font-size:1.75rem">${icon}</span>
          </div>

          <!-- Title -->
          <h3 style="font-size:1.05rem;font-weight:700;color:#0f172a;margin:0 0 0.3rem;line-height:1.3">${exam.title}</h3>
          <p style="font-size:0.82rem;color:#64748b;margin:0 0 1.25rem">${exam.subject || 'General'}</p>

          <!-- Meta chips -->
          <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:1.25rem">
            <div style="display:flex;align-items:center;gap:0.35rem;background:#f8fafc;border:1px solid #e2e8f0;padding:0.3rem 0.65rem;border-radius:6px;font-size:0.75rem;font-weight:500;color:#475569">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              ${duration} min
            </div>
            <div style="display:flex;align-items:center;gap:0.35rem;background:#f8fafc;border:1px solid #e2e8f0;padding:0.3rem 0.65rem;border-radius:6px;font-size:0.75rem;font-weight:500;color:#475569">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z"/></svg>
              ${totalQs} Qs
            </div>
            <div style="display:flex;align-items:center;gap:0.35rem;background:#f8fafc;border:1px solid #e2e8f0;padding:0.3rem 0.65rem;border-radius:6px;font-size:0.75rem;font-weight:500;color:#475569">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
              Pass: ${passingScore}%
            </div>
          </div>

          <!-- Start button -->
          <button class="start-exam-btn sd-start-btn" data-exam-id="${dbId}" style="width:100%;padding:0.75rem;background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:white;border:none;border-radius:10px;font-weight:700;font-size:0.9rem;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:0.5rem;letter-spacing:0.01em;box-shadow:0 4px 12px rgba(29,78,216,0.2)">
            Start Exam
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:16px;height:16px"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
          </button>
        </div>
      </div>`;
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
        if (svg) { svg.style.animation = 'spin 0.6s ease'; setTimeout(() => svg.style.animation = '', 700); }
        this.refresh();
      });
    }

    const startBtns = this.container.querySelectorAll('.start-exam-btn');
    startBtns.forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const examId = e.currentTarget.dataset.examId;
        if (examId) this.handleStartExam(examId);
      });
    });

    // Also make the card clickable
    const cards = this.container.querySelectorAll('.sd-card-hover[data-exam-id]');
    cards.forEach(card => {
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
      window.history.pushState(null, '', `/exam?id=${examId}`);
      this.router.handleRoute(`/exam`);
    } else {
      window.location.href = `/exam.html?id=${examId}`;
    }
  }

  renderError(error) {
    if (!this.container) return;
    this.container.innerHTML = `
      <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f1f5f9;font-family:'Inter',sans-serif">
        <div style="background:white;border:1px solid #fecaca;border-radius:16px;padding:2.5rem;max-width:420px;width:90%;text-align:center;box-shadow:0 8px 30px rgba(0,0,0,0.08)">
          <div style="width:56px;height:56px;background:#fef2f2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem">
            <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" style="width:28px;height:28px"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
          </div>
          <h3 style="font-size:1.125rem;font-weight:700;color:#1e293b;margin-bottom:0.5rem">Failed to Load Dashboard</h3>
          <p style="font-size:0.875rem;color:#64748b;margin-bottom:1.5rem">${error.message || 'An unexpected error occurred'}</p>
          <button onclick="location.reload()" style="background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:white;border:none;padding:0.7rem 2rem;border-radius:10px;cursor:pointer;font-weight:700;font-size:0.9rem;box-shadow:0 4px 12px rgba(29,78,216,0.2)">Retry</button>
        </div>
      </div>`;
  }

  async refresh() { if (!this.container) return; await this.initialize(this.container); }

  destroy() {
    if (this.examHistoryModule) this.examHistoryModule.destroy();
    if (this.certificationModule) this.certificationModule.destroy();
    if (this.container) this.container.innerHTML = '';
    this.container = null;
  }
}

export default StudentDashboard;
