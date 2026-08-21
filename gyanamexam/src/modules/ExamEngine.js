/**
 * ExamEngine - Core exam execution orchestrator
 * 
 * Fixed:
 * - Uses ModalService instead of confirm()/alert()
 * - Listens for SecurityMonitor CustomEvents and shows proper modals
 * - Removed monkey-patching pattern
 */

import { Timer } from '../components/Timer.js';
import { QuestionPalette } from '../components/QuestionPalette.js';
import { QuestionView } from '../components/QuestionView.js';
import StateManager from '../services/StateManager.js';
import StorageService from '../services/StorageService.js';
import SecurityMonitor from '../services/SecurityMonitor.js';
import APIClient from '../services/APIClient.js';
import modalService from '../services/ModalService.js';
import { parseExamConfig } from '../utils/examConfigParser.js';
import { selectRandomQuestions } from '../utils/random.js';
import { serializeSubmission } from '../utils/submissionSerializer.js';

export class ExamEngine {
  constructor() {
    this.stateManager = new StateManager();
    this.storageService = new StorageService();
    this.apiClient = new APIClient();
    this.securityMonitor = new SecurityMonitor();

    this.timer = new Timer();
    this.questionPalette = new QuestionPalette();
    this.questionView = new QuestionView();

    this.examConfig = null;
    this.studentId = null;

    this.isInitialized = false;
    this.isRunning = false;

    // Bind security event handler
    this._onSecurityEvent = this._onSecurityEvent.bind(this);
  }

  initialize(examConfig, studentId) {
    this.examConfig = parseExamConfig(examConfig);
    this.studentId = studentId;

    const initialState = {
      examId: this.examConfig.examId,
      examType: this.examConfig.examType,
      studentId: this.studentId,
      startTime: null,
      duration: this.examConfig.duration,
      currentQuestionIndex: 0,
      questions: [],
      security: {
        fullScreenRequired: this.examConfig.security.fullScreenRequired,
        cameraRequired: this.examConfig.security.cameraRequired,
        microphoneRequired: this.examConfig.security.microphoneRequired,
        tabSwitchCount: 0,
        fullScreenExitCount: 0,
        events: []
      },
      submitted: false,
      submittedAt: null
    };

    this.stateManager.initialize(initialState, this.storageService);
    this.isInitialized = true;
  }

  /**
   * Handle security events dispatched by SecurityMonitor
   * @private
   */
  async _onSecurityEvent(e) {
    const { type, tabSwitchCount, remaining, limitExceeded, exitCount } = e.detail;

    if (type === 'tab_switch_warning') {
      let msg = `You switched away from the exam. This activity has been logged.<br><br><strong>Tab switches: ${tabSwitchCount}</strong>`;
      if (remaining !== null) {
        msg += `<br>Remaining allowed: ${Math.max(0, remaining)}`;
      }
      if (limitExceeded) {
        msg += '<br><br>⛔ You have exceeded the maximum allowed tab switches. Your exam may be flagged.';
      }
      await modalService.alert(msg, { title: '⚠️ Tab Switch Detected', type: 'warning', buttonText: 'Continue Exam' });
    }

    if (type === 'fullscreen_exit') {
      const confirmed = await modalService.confirm(
        `You exited full-screen mode. This has been logged (exit #${exitCount}).<br><br>Click <strong>Re-enter</strong> to continue your exam in full-screen mode.`,
        { title: '⚠️ Full-Screen Required', confirmText: 'Re-enter Full-Screen', cancelText: 'Dismiss', type: 'warning' }
      );
      if (confirmed) {
        this.securityMonitor.requestFullscreen().catch(() => {
          modalService.alert('Unable to re-enter full-screen. Try pressing F11.', { title: 'Full-Screen Error', type: 'info' });
        });
      }
    }
  }

  async startExam() {
    if (!this.isInitialized) throw new Error('ExamEngine must be initialized before starting exam');
    if (this.isRunning) throw new Error('Exam is already running');

    const savedState = this.stateManager.restore(this.examConfig.examId);

    if (savedState && !savedState.submitted && savedState.remainingTime > 0) {
      this.stateManager.initialize(savedState, this.storageService);
      const remainingMinutes = savedState.remainingTime / 60;
      this.timer.start(remainingMinutes, () => this.submitExam(true), (s) => {
        this.stateManager.update(state => { state.remainingTime = s; });
      });
      this._startSecurityMonitoring();
      this.isRunning = true;
      return;
    }

    const questionBank = await this.apiClient.getQuestionBank(this.examConfig.examId);
    const seed = this.examConfig.randomSeed || Date.now();
    const selectedQuestions = selectRandomQuestions(questionBank, this.examConfig.questionCount, seed);

    const examQuestions = selectedQuestions.map((q) => ({
      id: q.id,
      originalIndex: questionBank.indexOf(q),
      type: q.type,
      text: q.text,
      options: q.options,
      answer: null,
      markedForReview: false,
      attemptedAt: null
    }));

    this.stateManager.update(state => {
      state.questions = examQuestions;
      state.startTime = Date.now();
      state.currentQuestionIndex = 0;
      state.remainingTime = this.examConfig.duration * 60;
    });

    this.timer.start(this.examConfig.duration, () => this.submitExam(true), (s) => {
      this.stateManager.update(state => { state.remainingTime = s; });
    });

    this._startSecurityMonitoring();
    this.isRunning = true;
    this.stateManager.persist(true);
  }

  /**
   * Start security monitoring and register event listener
   * @private
   */
  _startSecurityMonitoring() {
    const security = this.examConfig.security;
    // Always start monitoring (tab switches logged even without fullscreen/camera)
    this.securityMonitor.initialize(security);
    this.securityMonitor.startMonitoring();
    document.addEventListener('security:event', this._onSecurityEvent);

    if (security.fullScreenRequired) {
      this.securityMonitor.requestFullscreen().catch(e => console.warn('Fullscreen denied:', e.message));
    }
    if (security.cameraRequired) {
      this.securityMonitor.requestCameraAccess().catch(e => console.warn('Camera denied:', e.message));
    }
    if (security.microphoneRequired) {
      this.securityMonitor.requestMicrophoneAccess().catch(e => console.warn('Mic denied:', e.message));
    }
  }

  navigateToQuestion(questionIndex) {
    if (!this.isRunning) throw new Error('Cannot navigate: exam is not running');
    const state = this.stateManager.getState();
    if (questionIndex < 0 || questionIndex >= state.questions.length) throw new Error(`Invalid question index: ${questionIndex}`);

    this.stateManager.update(state => { state.currentQuestionIndex = questionIndex; });
    if (this.securityMonitor.isMonitoring) this.securityMonitor.setCurrentQuestionIndex(questionIndex);
    this.stateManager.persist();
  }

  submitAnswer(answer) {
    if (!this.isRunning) throw new Error('Cannot submit answer: exam is not running');
    const state = this.stateManager.getState();
    const currentIndex = state.currentQuestionIndex;

    this.stateManager.update(state => {
      const q = state.questions[currentIndex];
      q.answer = answer;
      q.attemptedAt = new Date().toISOString();
    });
    this.stateManager.persist(true);
  }

  markForReview() {
    if (!this.isRunning) throw new Error('Cannot mark for review: exam is not running');
    const state = this.stateManager.getState();
    const currentIndex = state.currentQuestionIndex;

    this.stateManager.update(state => {
      state.questions[currentIndex].markedForReview = !state.questions[currentIndex].markedForReview;
    });
    this.stateManager.persist();
  }

  async submitExam(autoSubmit = false) {
    if (!this.isRunning) throw new Error('Cannot submit: exam is not running');

    if (!autoSubmit) {
      const confirmed = await modalService.confirm(
        'Are you sure you want to submit your exam? <strong>This action cannot be undone.</strong>',
        { title: 'Submit Exam', confirmText: 'Submit Now', cancelText: 'Continue Exam', type: 'danger' }
      );
      if (!confirmed) return null;
    }

    const state = this.stateManager.getState();
    const submission = serializeSubmission(state);
    const result = await this.apiClient.submitExam(submission);

    this.timer.stop();
    if (this.securityMonitor.isMonitoring) {
      this.securityMonitor.stopMonitoring();
      document.removeEventListener('security:event', this._onSecurityEvent);
    }

    this.stateManager.update(state => {
      state.submitted = true;
      state.submittedAt = new Date().toISOString();
    });
    this.stateManager.persist(true);

    this.isRunning = false;
    return result;
  }

  getState() { return this.stateManager.getState(); }
  getExamType() { return this.examConfig ? this.examConfig.examType : null; }
  isExamRunning() { return this.isRunning; }
  getTimer() { return this.timer; }
  getQuestionPalette() { return this.questionPalette; }
  getQuestionView() { return this.questionView; }
  getSecurityMonitor() { return this.securityMonitor; }

  destroy() {
    if (this.isRunning) this.timer.stop();
    if (this.securityMonitor.isMonitoring) this.securityMonitor.stopMonitoring();
    document.removeEventListener('security:event', this._onSecurityEvent);
  }
}

export default ExamEngine;
