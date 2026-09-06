/**
 * ProctoringService.js
 * 
 * Enforces proctoring rules during an exam session based on settings
 * received from the backend. Each feature is independently toggleable.
 *
 * Features:
 * - Camera access (non-mandatory, shows preview)
 * - Microphone access (non-mandatory, monitors audio)
 * - Copy/paste blocking
 * - Right-click blocking
 * - Tab switch detection with configurable warning limit
 * - Fullscreen enforcement
 * - DevTools detection (resize heuristic)
 * - Text selection blocking
 */

class ProctoringService {
  constructor() {
    this.settings = {};
    this.active = false;
    this.tabSwitchCount = 0;
    this.warnings = [];
    this._listeners = [];
    this._cameraStream = null;
    this._micStream = null;
    this._onViolation = null; // callback(type, message, count, limit)
    this._onAutoSubmit = null; // callback when limit exceeded
    this._devtoolsCheckInterval = null;
    this._fullscreenWarningShown = false;
  }

  /**
   * Initialize proctoring with settings from the backend.
   * @param {Object} settings - proctoring_settings from exam config
   * @param {Function} onViolation - called on each violation (type, message, count, limit)
   * @param {Function} onAutoSubmit - called when tab switch limit is exceeded
   * @param {Object} [opts]
   * @param {MediaStream|null} [opts.cameraStream] - pre-opened camera from pre-exam gate
   */
  async initialize(settings, onViolation, onAutoSubmit, opts = {}) {
    this.settings = {
      camera: false,
      microphone: false,
      copy_paste_block: true,
      right_click_block: true,
      tab_switch_limit: 3,
      fullscreen_enforce: true,
      devtools_detect: true,
      text_select_block: true,
      ...settings,
    };

    this.tabSwitchCount = 0;
    this.warnings = [];
    this._onViolation = onViolation;
    this._onAutoSubmit = onAutoSubmit;
    this.active = true;

    if (opts.cameraStream) {
      this._cameraStream = opts.cameraStream;
    }

    // Set up each feature
    if (this.settings.camera) await this._setupCamera();
    if (this.settings.microphone) await this._setupMicrophone();
    if (this.settings.copy_paste_block) this._setupCopyPasteBlock();
    if (this.settings.right_click_block) this._setupRightClickBlock();
    if (this.settings.tab_switch_limit > 0) this._setupTabSwitchDetection();
    if (this.settings.fullscreen_enforce) this._setupFullscreen();
    if (this.settings.devtools_detect) this._setupDevToolsDetection();
    if (this.settings.text_select_block) this._setupTextSelectBlock();
  }

  /**
   * Get the current proctoring status for display.
   */
  getStatus() {
    return {
      active: this.active,
      tabSwitchCount: this.tabSwitchCount,
      tabSwitchLimit: this.settings.tab_switch_limit || 3,
      cameraActive: !!this._cameraStream,
      micActive: !!this._micStream,
      warnings: [...this.warnings],
    };
  }

  /**
   * Get camera stream for video preview element.
   */
  getCameraStream() {
    return this._cameraStream;
  }

  // ─── Camera ─────────────────────────────────────────────────────────────────

  async _setupCamera() {
    if (this._cameraStream) return; // already provided by pre-exam gate
    try {
      this._cameraStream = await navigator.mediaDevices.getUserMedia({ video: true });
    } catch (e) {
      console.warn('Proctoring: Camera access denied or unavailable.', e.message);
      // Camera is non-mandatory, just log it
      this._addWarning('camera_denied', 'Camera access was denied. Exam will continue without camera monitoring.');
    }
  }

  // ─── Microphone ─────────────────────────────────────────────────────────────

  async _setupMicrophone() {
    try {
      this._micStream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch (e) {
      console.warn('Proctoring: Microphone access denied or unavailable.', e.message);
      this._addWarning('mic_denied', 'Microphone access was denied. Exam will continue without audio monitoring.');
    }
  }

  // ─── Copy/Paste Block ───────────────────────────────────────────────────────

  _setupCopyPasteBlock() {
    const handler = (e) => {
      if (!this.active) return;
      e.preventDefault();
      this._triggerViolation('copy_paste', 'Copy/Paste is disabled during this exam.');
    };

    document.addEventListener('copy', handler);
    document.addEventListener('cut', handler);
    document.addEventListener('paste', handler);
    this._listeners.push(['copy', handler], ['cut', handler], ['paste', handler]);

    // Also block Ctrl+C, Ctrl+V, Ctrl+X
    const keyHandler = (e) => {
      if (!this.active) return;
      if ((e.ctrlKey || e.metaKey) && ['c', 'v', 'x', 'a'].includes(e.key.toLowerCase())) {
        // Allow Ctrl+A only if text_select_block is off
        if (e.key.toLowerCase() === 'a' && !this.settings.text_select_block) return;
        e.preventDefault();
        this._triggerViolation('copy_paste', 'Keyboard shortcuts for copy/paste are disabled.');
      }
    };
    document.addEventListener('keydown', keyHandler);
    this._listeners.push(['keydown', keyHandler]);
  }

  // ─── Right Click Block ──────────────────────────────────────────────────────

  _setupRightClickBlock() {
    const handler = (e) => {
      if (!this.active) return;
      e.preventDefault();
      this._triggerViolation('right_click', 'Right-click is disabled during this exam.');
      return false;
    };
    document.addEventListener('contextmenu', handler);
    this._listeners.push(['contextmenu', handler]);
  }

  // ─── Tab Switch Detection ───────────────────────────────────────────────────

  _setupTabSwitchDetection() {
    let lastCountAt = 0;
    const countSwitch = (messagePrefix) => {
      if (!this.active) return;
      const now = Date.now();
      if (now - lastCountAt < 1000) return; // debounce visibility+blur double fire
      lastCountAt = now;

      this.tabSwitchCount++;
      const limit = this.settings.tab_switch_limit;
      const remaining = limit - this.tabSwitchCount;

      if (this.tabSwitchCount >= limit) {
        this._triggerViolation(
          'tab_switch_exceeded',
          `${messagePrefix} ${this.tabSwitchCount} times. Maximum allowed: ${limit}. Your exam will be auto-submitted.`
        );
        setTimeout(() => {
          if (this._onAutoSubmit) this._onAutoSubmit();
        }, 1500);
      } else {
        this._triggerViolation(
          'tab_switch',
          `Warning ${this.tabSwitchCount}/${limit}: ${messagePrefix}! ${remaining} warning(s) remaining before auto-submit.`
        );
      }
    };

    const handler = () => {
      if (!this.active) return;
      if (document.hidden) countSwitch('Tab switch detected');
    };
    document.addEventListener('visibilitychange', handler);
    this._listeners.push(['visibilitychange', handler]);

    const blurHandler = () => {
      if (!this.active) return;
      if (document.hidden) return;
      countSwitch('Window focus lost');
    };
    window.addEventListener('blur', blurHandler);
    this._listeners.push(['blur', blurHandler, window]);
  }

  // ─── Fullscreen Enforcement ─────────────────────────────────────────────────

  isFullscreen() {
    return !!(
      document.fullscreenElement ||
      document.webkitFullscreenElement ||
      document.mozFullScreenElement ||
      document.msFullscreenElement
    );
  }

  /**
   * Request fullscreen. Must be called from a user gesture for browsers to allow it.
   * @returns {Promise<boolean>}
   */
  async requestFullscreen() {
    if (this.isFullscreen()) return true;
    const el = document.documentElement;
    try {
      if (el.requestFullscreen) await el.requestFullscreen();
      else if (el.webkitRequestFullscreen) {
        el.webkitRequestFullscreen();
        await new Promise(r => setTimeout(r, 100));
      } else if (el.mozRequestFullScreen) {
        el.mozRequestFullScreen();
        await new Promise(r => setTimeout(r, 100));
      } else if (el.msRequestFullscreen) {
        el.msRequestFullscreen();
        await new Promise(r => setTimeout(r, 100));
      }
      return this.isFullscreen();
    } catch (e) {
      console.warn('Proctoring: Could not enter fullscreen.', e?.message || e);
      return false;
    }
  }

  /** @deprecated use requestFullscreen() */
  _requestFullscreen() {
    this.requestFullscreen();
  }

  _setupFullscreen() {
    // If already fullscreen (from pre-exam gate), keep it; otherwise try once
    // (may fail without gesture — ExamPage / gate should have entered already).
    if (!this.isFullscreen()) {
      this.requestFullscreen().then(ok => {
        if (!ok && this.active) {
          this._triggerViolation(
            'fullscreen_required',
            'Fullscreen is required for this exam. Click “Enter Fullscreen” to continue.'
          );
        }
      });
    }

    const handler = () => {
      if (!this.active) return;
      if (!this.isFullscreen()) {
        this._triggerViolation(
          'fullscreen_exit',
          'You left fullscreen. Click “Enter Fullscreen” to continue the exam.'
        );
      }
    };

    document.addEventListener('fullscreenchange', handler);
    document.addEventListener('webkitfullscreenchange', handler);
    document.addEventListener('mozfullscreenchange', handler);
    document.addEventListener('MSFullscreenChange', handler);
    this._listeners.push(
      ['fullscreenchange', handler],
      ['webkitfullscreenchange', handler],
      ['mozfullscreenchange', handler],
      ['MSFullscreenChange', handler]
    );
  }

  // ─── DevTools Detection ─────────────────────────────────────────────────────

  _setupDevToolsDetection() {
    let prevWidth = window.outerWidth;
    let prevHeight = window.outerHeight;

    this._devtoolsCheckInterval = setInterval(() => {
      if (!this.active) return;

      const widthDiff = window.outerWidth - window.innerWidth;
      const heightDiff = window.outerHeight - window.innerHeight;

      // Heuristic: if the difference is large, devtools might be open
      if (widthDiff > 200 || heightDiff > 200) {
        this._triggerViolation(
          'devtools',
          'Developer tools detected. Please close them immediately to continue the exam.'
        );
      }
    }, 3000);
  }

  // ─── Text Selection Block ───────────────────────────────────────────────────

  _setupTextSelectBlock() {
    // CSS approach
    const style = document.createElement('style');
    style.id = 'proctoring-no-select';
    style.textContent = `
      .proctoring-active * {
        -webkit-user-select: none !important;
        -moz-user-select: none !important;
        -ms-user-select: none !important;
        user-select: none !important;
      }
      .proctoring-active input, .proctoring-active textarea {
        -webkit-user-select: text !important;
        user-select: text !important;
      }
    `;
    document.head.appendChild(style);
    document.body.classList.add('proctoring-active');

    // Also block selectstart
    const handler = (e) => {
      if (!this.active) return;
      // Allow selection in inputs
      if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
      e.preventDefault();
    };
    document.addEventListener('selectstart', handler);
    this._listeners.push(['selectstart', handler]);
  }

  // ─── Violation Handling ─────────────────────────────────────────────────────

  _triggerViolation(type, message) {
    const entry = {
      type,
      message,
      timestamp: new Date().toISOString(),
      tabSwitchCount: this.tabSwitchCount,
    };
    this.warnings.push(entry);

    if (this._onViolation) {
      this._onViolation(type, message, this.tabSwitchCount, this.settings.tab_switch_limit);
    }
  }

  _addWarning(type, message) {
    this.warnings.push({ type, message, timestamp: new Date().toISOString() });
  }

  // ─── Cleanup ────────────────────────────────────────────────────────────────

  destroy() {
    this.active = false;

    // Remove all event listeners
    this._listeners.forEach(([event, handler, target]) => {
      (target || document).removeEventListener(event, handler);
    });
    this._listeners = [];

    // Stop camera
    if (this._cameraStream) {
      this._cameraStream.getTracks().forEach(t => t.stop());
      this._cameraStream = null;
    }

    // Stop microphone
    if (this._micStream) {
      this._micStream.getTracks().forEach(t => t.stop());
      this._micStream = null;
    }

    // Clear devtools interval
    if (this._devtoolsCheckInterval) {
      clearInterval(this._devtoolsCheckInterval);
      this._devtoolsCheckInterval = null;
    }

    // Remove no-select styles
    const style = document.getElementById('proctoring-no-select');
    if (style) style.remove();
    document.body.classList.remove('proctoring-active');

    // Exit fullscreen
    try {
      if (document.fullscreenElement) document.exitFullscreen();
      else if (document.webkitFullscreenElement) document.webkitExitFullscreen();
      else if (document.mozFullScreenElement) document.mozCancelFullScreen();
      else if (document.msFullscreenElement) document.msExitFullscreen();
    } catch (e) { /* ignore */ }
  }
}

export default ProctoringService;
