/**
 * ExamPage - Premium student examination interface
 *
 * Accepts real exam data from the backend (questions + examConfig).
 * Handles submission, result polling, and navigation to the result page.
 * Modern UI with progress bar, polished option cards, and responsive layout.
 */

import ApiClient from '../services/APIClient.js';
import modalService from '../services/ModalService.js';
import ProctoringService from '../services/ProctoringService.js';
import { QuestionView } from '../components/QuestionView.js';
import { QuestionPalette } from '../components/QuestionPalette.js';
import { Timer } from '../components/Timer.js';

class ExamPage {
  constructor() {
    this.examConfig = null;
    this.questions = [];
    this.examId = null;
    this.router = null;
    this.currentIndex = 0;
    this.answers = {};
    this.markedForReview = new Set();
    this.timer = new Timer();
    this.questionView = new QuestionView();
    this.questionPalette = new QuestionPalette();
    this.isSubmitting = false;
    this._timerInterval = null;
    this.proctoring = new ProctoringService();
  }

  async render(container, examConfig, questions, examId, router) {
    this.currentIndex = 0;
    this.answers = {};
    this.markedForReview = new Set();
    this.isSubmitting = false;

    if (this.timer) this.timer.stop();
    if (this._timerInterval) clearInterval(this._timerInterval);

    this.examConfig = examConfig;
    this.questions = questions;
    this.examId = examId;
    this.router = router;

    container.innerHTML = this._getExamHTML();
    this._updateHeader();
    this._attachEventListeners();
    this._renderCurrentQuestion();
    this._renderQuestionPalette();
    this._startTimerDisplay();
    this._startHeartbeat();

    this.timer.start(examConfig.duration, () => this._submitExam(true), (remaining) => {
      this._remaining = remaining;
    });

    // Initialize proctoring if enabled
    if (examConfig.proctored && examConfig.proctoring_settings) {
      await this._initializeProctoring(examConfig.proctoring_settings);
    }
  }

  _getExamHTML() {
    const user = ApiClient.getUser();
    const userName = user?.name || 'Student';
    const userId = user?.identifier || '';
    const initial = (userName).charAt(0).toUpperCase();

    return `
      <style>
        @keyframes exam-fadeIn { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
        @keyframes timer-pulse { 0%,100% { transform:scale(1); } 50% { transform:scale(1.03); } }
        @keyframes progress-grow { from { width:0; } }
        .exam-nav-btn { transition: all 0.2s ease; }
        .exam-nav-btn:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .exam-nav-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .exam-submit-btn:hover { background: #b91c1c !important; transform: translateY(-1px); box-shadow: 0 8px 24px rgba(220,38,38,0.3); }
        .exam-submit-btn { transition: all 0.25s ease; }
        .exam-mark-btn:hover { background: #fef3c7 !important; border-color: #f59e0b !important; }
        .exam-mark-btn { transition: all 0.2s ease; }
        .exam-layout { display:flex !important; flex-direction:row !important; }
        @media (max-width: 900px) {
          .exam-layout { flex-direction: column !important; height: auto !important; }
          .exam-sidebar { width: 100% !important; border-left: none !important; border-top: 1px solid #e2e8f0; max-height: 300px; overflow-y: auto; flex-shrink: 1 !important; }
          .exam-main { height: auto !important; min-height: auto !important; overflow-y: visible !important; }
        }
      </style>

      <div style="background:#f1f5f9;height:100vh;display:flex;flex-direction:column;color:#0f172a;font-family:'Inter',sans-serif;overflow:hidden">

        <!-- ═══ Top Header Bar ═══ -->
        <header style="background:white;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:20;box-shadow:0 1px 4px rgba(0,0,0,0.05)">
          <div style="max-width:1400px;margin:0 auto;padding:0.75rem 1.5rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">

            <!-- Exam Info -->
            <div style="flex:1;min-width:180px">
              <h2 id="exam-title" style="font-size:1rem;font-weight:700;color:#0f172a;margin:0;line-height:1.3">Loading...</h2>
              <div style="display:flex;align-items:center;gap:0.75rem;margin-top:0.2rem">
                <span id="exam-type" style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em"></span>
                <span style="width:3px;height:3px;border-radius:50%;background:#cbd5e1"></span>
                <span style="font-size:0.72rem;color:#64748b;font-weight:500"><span id="total-questions">—</span> Questions</span>
              </div>
            </div>

            <!-- Progress Bar (desktop) -->
            <div style="flex:0 0 200px;display:flex;align-items:center;gap:0.5rem">
              <div style="flex:1;height:6px;background:#e2e8f0;border-radius:999px;overflow:hidden">
                <div id="progress-bar" style="height:100%;background:linear-gradient(90deg,#3b82f6,#1d4ed8);border-radius:999px;transition:width 0.4s ease;width:0%;animation:progress-grow 0.6s ease-out"></div>
              </div>
              <span id="progress-label" style="font-size:0.72rem;font-weight:600;color:#64748b;white-space:nowrap">0%</span>
            </div>

            <!-- Student Info -->
            <div style="display:flex;align-items:center;gap:0.75rem">
              <div style="text-align:right">
                <div style="font-size:0.82rem;font-weight:600;color:#1e293b">${userName}</div>
                <div style="font-size:0.7rem;color:#94a3b8;font-weight:500">${userId}</div>
              </div>
              <div style="width:36px;height:36px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:0.85rem;font-weight:700;color:white;flex-shrink:0">${initial}</div>
            </div>

            <!-- Timer -->
            <div id="timer-display" style="font-family:'Inter',monospace;font-size:1.35rem;font-weight:800;background:#f0fdf4;color:#16a34a;border:2px solid #bbf7d0;padding:0.4rem 1.1rem;border-radius:12px;min-width:110px;text-align:center;letter-spacing:0.02em">00:00</div>
          </div>
        </header>

        <!-- ═══ Main Layout ═══ -->
        <div class="exam-layout" style="flex:1;display:flex;overflow:hidden;min-height:0">

          <!-- Question Panel -->
          <div class="exam-main" style="flex:1;overflow-y:auto;padding:1.5rem 2rem;min-height:0">
            <div style="max-width:800px;margin:0 auto;animation:exam-fadeIn 0.4s ease-out">

              <!-- Question Card -->
              <div style="background:white;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 2px 8px rgba(0,0,0,0.04);overflow:hidden">

                <!-- Question Number Bar -->
                <div style="padding:0.875rem 1.5rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between">
                  <div style="display:flex;align-items:center;gap:0.75rem">
                    <div id="question-number-badge" style="width:32px;height:32px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:0.82rem;font-weight:700;color:white">1</div>
                    <span style="font-size:0.82rem;font-weight:600;color:#475569">
                      Question <span id="current-question-number">1</span> of <span id="total-questions-inline">—</span>
                    </span>
                  </div>
                  <span style="background:#f0fdf4;color:#15803d;padding:0.2rem 0.6rem;border-radius:6px;font-size:0.7rem;font-weight:700;border:1px solid #bbf7d0">+1.0 Mark</span>
                </div>

                <!-- Question Content -->
                <div style="padding:1.75rem 1.5rem;min-height:320px">
                  <div id="question-view-container"></div>
                </div>

                <!-- Navigation Footer -->
                <div style="padding:1rem 1.5rem;background:#fafbfc;border-top:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap">
                  <button id="prev-button" class="exam-nav-btn" style="display:flex;align-items:center;gap:0.4rem;background:white;border:1px solid #e2e8f0;color:#475569;padding:0.6rem 1.25rem;border-radius:10px;font-weight:600;font-size:0.85rem;cursor:pointer">
                    <svg style="width:16px;height:16px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    Previous
                  </button>
                  <div style="display:flex;gap:0.5rem">
                    <button id="mark-review-button" class="exam-mark-btn" style="display:flex;align-items:center;gap:0.4rem;background:#fffbeb;border:1px solid #fde68a;color:#b45309;padding:0.6rem 1rem;border-radius:10px;font-weight:600;font-size:0.85rem;cursor:pointer">
                      <svg style="width:16px;height:16px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/></svg>
                      Review
                    </button>
                    <button id="next-button" class="exam-nav-btn" style="display:flex;align-items:center;gap:0.4rem;background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:white;padding:0.6rem 1.5rem;border-radius:10px;font-weight:700;font-size:0.85rem;border:none;cursor:pointer;box-shadow:0 2px 8px rgba(29,78,216,0.2)">
                      Next
                      <svg style="width:16px;height:16px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </button>
                  </div>
                </div>
              </div>

              <!-- Keyboard hint -->
              <div style="text-align:center;margin-top:1rem;font-size:0.75rem;color:#94a3b8">
                <span style="background:#f1f5f9;padding:0.2rem 0.5rem;border-radius:4px;font-weight:500">💡 Press A-D or 1-4 to select • ← → to navigate</span>
              </div>
            </div>
          </div>

          <!-- ═══ Sidebar ═══ -->
          <div class="exam-sidebar" style="width:280px;flex-shrink:0;background:white;border-left:1px solid #e2e8f0;display:flex;flex-direction:column;padding:1.25rem;overflow-y:auto">
            <h2 style="font-size:0.75rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:1rem">Question Map</h2>

            <div id="question-palette-container" style="flex:1;overflow-y:auto;margin-bottom:1.25rem"></div>

            <!-- Legend -->
            <div style="background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;padding:0.75rem;margin-bottom:1rem">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem">
                <div style="display:flex;align-items:center;gap:0.4rem;font-size:0.7rem;color:#475569;font-weight:500">
                  <div style="width:12px;height:12px;background:#16a34a;border-radius:3px"></div> Answered
                </div>
                <div style="display:flex;align-items:center;gap:0.4rem;font-size:0.7rem;color:#475569;font-weight:500">
                  <div style="width:12px;height:12px;background:#d97706;border-radius:3px"></div> Marked
                </div>
                <div style="display:flex;align-items:center;gap:0.4rem;font-size:0.7rem;color:#475569;font-weight:500">
                  <div style="width:12px;height:12px;background:#f1f5f9;border:2px solid #cbd5e1;border-radius:3px"></div> Pending
                </div>
                <div style="display:flex;align-items:center;gap:0.4rem;font-size:0.7rem;color:#475569;font-weight:500">
                  <div style="width:12px;height:12px;background:#eff6ff;border:2px solid #3b82f6;border-radius:3px"></div> Current
                </div>
              </div>
            </div>

            <!-- Stats -->
            <div id="answer-stats" style="display:flex;gap:0.5rem;margin-bottom:1rem">
              <div style="flex:1;text-align:center;background:#f0fdf4;border-radius:8px;padding:0.5rem">
                <div id="stat-answered" style="font-size:1.1rem;font-weight:800;color:#16a34a">0</div>
                <div style="font-size:0.65rem;color:#64748b;font-weight:600">Done</div>
              </div>
              <div style="flex:1;text-align:center;background:#fffbeb;border-radius:8px;padding:0.5rem">
                <div id="stat-marked" style="font-size:1.1rem;font-weight:800;color:#d97706">0</div>
                <div style="font-size:0.65rem;color:#64748b;font-weight:600">Review</div>
              </div>
              <div style="flex:1;text-align:center;background:#f8fafc;border-radius:8px;padding:0.5rem">
                <div id="stat-pending" style="font-size:1.1rem;font-weight:800;color:#94a3b8">0</div>
                <div style="font-size:0.65rem;color:#64748b;font-weight:600">Left</div>
              </div>
            </div>

            <!-- Submit Button -->
            <button id="submit-exam-button" class="exam-submit-btn" style="width:100%;padding:0.8rem;background:#dc2626;color:white;border:none;border-radius:10px;font-weight:700;font-size:0.9rem;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:0.5rem;box-shadow:0 4px 12px rgba(220,38,38,0.15)">
              <svg style="width:18px;height:18px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              Submit Exam
            </button>
          </div>
        </div>
      </div>
    `;
  }

  async _initializeProctoring(settings) {
    await this.proctoring.initialize(
      settings,
      // onViolation callback
      (type, message, tabCount, tabLimit) => {
        this._showProctoringWarning(type, message, tabCount, tabLimit);
      },
      // onAutoSubmit callback (tab switch limit exceeded)
      () => {
        this._submitExam(true);
      }
    );

    // Show proctoring status indicator
    this._renderProctoringIndicator();

    // Attach camera preview if available
    if (settings.camera && this.proctoring.getCameraStream()) {
      this._renderCameraPreview();
    }
  }

  _showProctoringWarning(type, message, tabCount, tabLimit) {
    // Remove existing warning if any
    const existing = document.getElementById('proctoring-warning-overlay');
    if (existing) existing.remove();

    const isAutoSubmit = type === 'tab_switch_exceeded';
    const bgColor = isAutoSubmit ? '#dc2626' : '#f59e0b';
    const iconBg = isAutoSubmit ? '#991b1b' : '#92400e';

    const overlay = document.createElement('div');
    overlay.id = 'proctoring-warning-overlay';
    overlay.style.cssText = `
      position:fixed;top:0;left:0;right:0;z-index:99999;
      display:flex;align-items:center;justify-content:center;
      padding:1rem;pointer-events:none;
      animation:procWarnSlide 0.3s ease-out;
    `;
    overlay.innerHTML = `
      <style>
        @keyframes procWarnSlide { from { transform:translateY(-100%);opacity:0; } to { transform:translateY(0);opacity:1; } }
        @keyframes procWarnPulse { 0%,100% { box-shadow:0 4px 20px rgba(0,0,0,0.2); } 50% { box-shadow:0 4px 30px rgba(220,38,38,0.4); } }
      </style>
      <div style="
        background:${bgColor};color:white;padding:1rem 1.5rem;border-radius:12px;
        max-width:600px;width:100%;display:flex;align-items:center;gap:1rem;
        box-shadow:0 4px 20px rgba(0,0,0,0.2);pointer-events:auto;
        ${isAutoSubmit ? 'animation:procWarnPulse 1s infinite;' : ''}
      ">
        <div style="width:40px;height:40px;background:${iconBg};border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.3rem">
          ${isAutoSubmit ? '🚫' : '⚠️'}
        </div>
        <div style="flex:1">
          <div style="font-weight:700;font-size:0.9rem;margin-bottom:0.2rem">
            ${isAutoSubmit ? 'Exam Auto-Submitted' : 'Proctoring Warning'}
          </div>
          <div style="font-size:0.82rem;opacity:0.95">${message}</div>
        </div>
        ${!isAutoSubmit ? `
          <button onclick="this.closest('#proctoring-warning-overlay').remove()"
            style="background:rgba(255,255,255,0.2);border:none;color:white;padding:0.4rem 0.8rem;border-radius:6px;cursor:pointer;font-weight:600;font-size:0.8rem">
            OK
          </button>
        ` : ''}
      </div>
    `;
    document.body.appendChild(overlay);

    // Auto-dismiss non-critical warnings after 5 seconds
    if (!isAutoSubmit) {
      setTimeout(() => {
        if (overlay.parentNode) overlay.remove();
      }, 5000);
    }
  }

  _renderProctoringIndicator() {
    const header = document.querySelector('header');
    if (!header) return;

    // Add a proctoring badge next to the timer
    const timerEl = document.getElementById('timer-display');
    if (!timerEl) return;

    const badge = document.createElement('div');
    badge.id = 'proctoring-badge';
    badge.style.cssText = `
      display:flex;align-items:center;gap:0.4rem;
      background:#fef2f2;border:1.5px solid #fecaca;
      padding:0.35rem 0.75rem;border-radius:8px;
      font-size:0.75rem;font-weight:700;color:#dc2626;
    `;
    badge.innerHTML = `
      <span style="width:8px;height:8px;background:#dc2626;border-radius:50%;animation:timer-pulse 1.5s infinite"></span>
      PROCTORED
      <span id="tab-switch-counter" style="background:#dc2626;color:white;padding:0.1rem 0.4rem;border-radius:4px;font-size:0.7rem;margin-left:0.25rem">
        0/${this.proctoring.settings.tab_switch_limit || 3}
      </span>
    `;
    timerEl.parentNode.insertBefore(badge, timerEl);
  }

  _renderCameraPreview() {
    const sidebar = document.querySelector('.exam-sidebar');
    if (!sidebar) return;

    const stream = this.proctoring.getCameraStream();
    if (!stream) return;

    const container = document.createElement('div');
    container.id = 'camera-preview-container';
    container.style.cssText = `
      margin-bottom:1rem;border-radius:10px;overflow:hidden;
      border:2px solid #e2e8f0;position:relative;
    `;
    container.innerHTML = `
      <div style="position:absolute;top:0.4rem;left:0.4rem;background:rgba(220,38,38,0.9);color:white;padding:0.15rem 0.5rem;border-radius:4px;font-size:0.65rem;font-weight:700;display:flex;align-items:center;gap:0.3rem;z-index:2">
        <span style="width:6px;height:6px;background:white;border-radius:50%;animation:timer-pulse 1s infinite"></span>
        REC
      </div>
    `;

    const video = document.createElement('video');
    video.autoplay = true;
    video.muted = true;
    video.playsInline = true;
    video.srcObject = stream;
    video.style.cssText = 'width:100%;height:auto;display:block;transform:scaleX(-1);border-radius:8px';
    container.appendChild(video);

    // Insert at the top of sidebar
    sidebar.insertBefore(container, sidebar.firstChild);
  }

  _updateHeader() {
    const titleEl = document.getElementById('exam-title');
    const typeEl = document.getElementById('exam-type');
    const totalEl = document.getElementById('total-questions');
    const totalInline = document.getElementById('total-questions-inline');

    if (titleEl) titleEl.textContent = this.examConfig.title || 'Examination';
    if (typeEl) typeEl.textContent = this.examConfig.exam_type === 'demo' ? '📝 Practice Exam' : '📋 Official Exam';
    if (totalEl) totalEl.textContent = this.questions.length;
    if (totalInline) totalInline.textContent = this.questions.length;
  }

  _attachEventListeners() {
    document.getElementById('prev-button')?.addEventListener('click', () => this._navigate(-1));
    document.getElementById('next-button')?.addEventListener('click', () => this._navigate(1));
    document.getElementById('mark-review-button')?.addEventListener('click', () => this._toggleMarkForReview());
    document.getElementById('submit-exam-button')?.addEventListener('click', () => this._submitExam(false));
  }

  _navigate(delta) {
    const newIndex = this.currentIndex + delta;
    if (newIndex >= 0 && newIndex < this.questions.length) {
      this.currentIndex = newIndex;
      this._renderCurrentQuestion();
      this._renderQuestionPalette();
      this._updateProgress();
    }
  }

  _toggleMarkForReview() {
    if (this.markedForReview.has(this.currentIndex)) {
      this.markedForReview.delete(this.currentIndex);
    } else {
      this.markedForReview.add(this.currentIndex);
    }
    this._renderQuestionPalette();
    this._updateStats();
  }

  _renderCurrentQuestion() {
    const container = document.getElementById('question-view-container');
    const numEl = document.getElementById('current-question-number');
    const numBadge = document.getElementById('question-number-badge');
    const prevBtn = document.getElementById('prev-button');
    const nextBtn = document.getElementById('next-button');

    if (!container) return;

    const q = this.questions[this.currentIndex];
    if (!q) return;

    if (numEl) numEl.textContent = this.currentIndex + 1;
    if (numBadge) numBadge.textContent = this.currentIndex + 1;
    if (prevBtn) prevBtn.disabled = this.currentIndex === 0;
    if (nextBtn) nextBtn.disabled = this.currentIndex === this.questions.length - 1;

    this.questionView.render(
      container,
      q,
      this.currentIndex + 1,
      this.answers[q.id] ?? null,
      (answer) => {
        this.answers[q.id] = answer;
        this._renderQuestionPalette();
        this._updateProgress();
        this._updateStats();
      }
    );
  }

  _renderQuestionPalette() {
    const container = document.getElementById('question-palette-container');
    if (!container) return;

    if (!this.questionPalette.questionCount) {
      this.questionPalette.initialize(this.questions.length, (idx) => {
        this.currentIndex = idx;
        this._renderCurrentQuestion();
        this._renderQuestionPalette();
        this._updateProgress();
      });
    }

    this.questions.forEach((q, idx) => {
      let status = 'unattempted';
      if (idx === this.currentIndex) status = 'current';
      else if (this.markedForReview.has(idx)) status = 'marked';
      else if (this.answers[q.id] != null) status = 'attempted';
      this.questionPalette.updateQuestionStatus(idx, status);
    });

    this.questionPalette.render(container);
    this._updateStats();
  }

  _updateProgress() {
    const answered = Object.keys(this.answers).length;
    const total = this.questions.length;
    const pct = total > 0 ? Math.round((answered / total) * 100) : 0;

    const bar = document.getElementById('progress-bar');
    const label = document.getElementById('progress-label');
    if (bar) bar.style.width = pct + '%';
    if (label) label.textContent = pct + '%';
  }

  _updateStats() {
    const answered = Object.keys(this.answers).length;
    const marked = this.markedForReview.size;
    const pending = this.questions.length - answered;

    const el1 = document.getElementById('stat-answered');
    const el2 = document.getElementById('stat-marked');
    const el3 = document.getElementById('stat-pending');
    if (el1) el1.textContent = answered;
    if (el2) el2.textContent = marked;
    if (el3) el3.textContent = pending;
  }

  _startTimerDisplay() {
    const timerDisplay = document.getElementById('timer-display');
    if (!timerDisplay) return;

    this._timerInterval = setInterval(() => {
      const formattedTime = this.timer.getFormattedTime();
      const warningLevel = this.timer.getWarningLevel();

      timerDisplay.textContent = formattedTime;

      if (warningLevel === 'red') {
        timerDisplay.style.background = '#fef2f2';
        timerDisplay.style.color = '#dc2626';
        timerDisplay.style.borderColor = '#fecaca';
        timerDisplay.style.animation = 'timer-pulse 0.8s infinite';
      } else if (warningLevel === 'yellow') {
        timerDisplay.style.background = '#fffbeb';
        timerDisplay.style.color = '#d97706';
        timerDisplay.style.borderColor = '#fde68a';
        timerDisplay.style.animation = 'none';
      } else {
        timerDisplay.style.background = '#f0fdf4';
        timerDisplay.style.color = '#16a34a';
        timerDisplay.style.borderColor = '#bbf7d0';
        timerDisplay.style.animation = 'none';
      }
    }, 1000);
  }

  _startHeartbeat() {
    if (this._heartbeatInterval) clearInterval(this._heartbeatInterval);
    this._appliedExtraMinutes = 0;

    const sendBeat = async () => {
      if (this.isSubmitting) { clearInterval(this._heartbeatInterval); return; }
      try {
        const resp = await ApiClient.pulseHeartbeat(this.examId);
        // Check if admin granted extra time
        const serverExtra = resp?.extraMinutes || 0;
        if (serverExtra > this._appliedExtraMinutes) {
          const added = serverExtra - this._appliedExtraMinutes;
          this._appliedExtraMinutes = serverExtra;
          this.timer.addTime(added * 60);
          // Notify student
          const banner = document.createElement('div');
          banner.textContent = `⏱ +${added} min added by admin`;
          banner.style.cssText = 'position:fixed;top:80px;right:1rem;background:#22c55e;color:#fff;padding:0.6rem 1.2rem;border-radius:10px;font-weight:700;font-size:0.9rem;z-index:9999;box-shadow:0 4px 16px rgba(34,197,94,0.3);animation:exam-fadeIn 0.3s ease';
          document.body.appendChild(banner);
          setTimeout(() => banner.remove(), 4000);
        }
      } catch (e) { /* silent */ }
    };

    sendBeat();
    this._heartbeatInterval = setInterval(sendBeat, 30000);
  }

  async _submitExam(autoSubmit = false) {
    if (this.isSubmitting) return;

    if (!autoSubmit) {
      const unanswered = this.questions.filter(q => this.answers[q.id] == null).length;
      const answered = this.questions.length - unanswered;
      const msg = unanswered > 0
        ? `You have answered <strong>${answered}</strong> of <strong>${this.questions.length}</strong> questions. <strong>${unanswered} unanswered</strong>.<br><br>Are you sure you want to submit?`
        : 'You have answered all questions. <br><br>Are you sure you want to <strong>submit your exam</strong>? This action cannot be undone.';

      const confirmed = await modalService.confirm(msg, {
        title: 'Submit Exam',
        confirmText: 'Submit Now',
        cancelText: 'Continue Exam',
        type: 'danger'
      });

      if (!confirmed) {
        this.isSubmitting = false;
        return;
      }
    }

    this.isSubmitting = true;
    this.timer.stop();
    if (this._timerInterval) clearInterval(this._timerInterval);
    if (this._heartbeatInterval) clearInterval(this._heartbeatInterval);
    if (this.proctoring) this.proctoring.destroy();

    const submitBtn = document.getElementById('submit-exam-button');
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<div style="width:18px;height:18px;border:2px solid rgba(255,255,255,0.3);border-top-color:white;border-radius:50%;animation:spin 0.7s linear infinite"></div> Submitting...';
      submitBtn.style.background = '#991b1b';
    }

    const answers = this.questions.map(q => ({
      question_id: q.id,
      answer: this.answers[q.id] ?? null
    }));

    try {
      const response = await ApiClient.submitExam(this.examId, { answers });
      const submissionId = response.submission_id;
      if (!submissionId) throw new Error('Server did not return a submission ID.');

      if (this.router) {
        this.router.navigate(`/student/result/${submissionId}`);
      } else {
        window.location.href = `/student/result/${submissionId}`;
      }
    } catch (error) {
      console.error('Exam submission failed:', error);
      this.isSubmitting = false;
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<svg style="width:18px;height:18px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> Submit Exam';
        submitBtn.style.background = '#dc2626';
      }
      await modalService.alert(
        `Submission failed: ${error.message}<br><br>Please check your internet connection and try again.`,
        { title: 'Submission Error', type: 'danger' }
      );
    }
  }

  destroy() {
    this.timer.stop();
    if (this._timerInterval) clearInterval(this._timerInterval);
    if (this._heartbeatInterval) clearInterval(this._heartbeatInterval);
    if (this.proctoring) this.proctoring.destroy();
    this.isSubmitting = false;
    this.questions = [];
    this.answers = {};
  }
}

export default ExamPage;
