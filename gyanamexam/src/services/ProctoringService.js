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
   */
  async initialize(settings, onViolation, onAutoSubmit) {
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
    const handler = () => {
      if (!this.active) return;
      if (document.hidden) {
        this.tabSwitchCount++;
        const limit = this.settings.tab_switch_limit;
        const remaining = limit - this.tabSwitchCount;

        if (this.tabSwitchCount >= limit) {
          this._triggerViolation(
            'tab_switch_exceeded',
            `You have switched tabs ${this.tabSwitchCount} times. Maximum allowed: ${limit}. Your exam will be auto-submitted.`
          );
          // Auto-submit after a brief delay so the warning shows
          setTimeout(() => {
            if (this._onAutoSubmit) this._onAutoSubmit();
          }, 1500);
        } else {
          this._triggerViolation(
            'tab_switch',
            `Warning ${this.tabSwitchCount}/${limit}: Tab switch detected! ${remaining} warning(s) remaining before auto-submit.`
          );
        }
      }
    };
    document.addEventListener('visibilitychange', handler);
    this._listeners.push(['visibilitychange', handler]);

    // Also detect window blur (covers alt-tab scenarios)
    const blurHandler = () => {
      if (!this.active) return;
      // Only count if not already counted by visibilitychange
      if (!document.hidden) {
        // Small delay to avoid double-counting with visibilitychange
        setTimeout(() => {
          if (!document.hidden && this.active) {
            // Window lost focus but tab is still visible (e.g., alt-tab on some OS)
            // We don't double-count here, visibilitychange handles the main case
          }
        }, 100);
      }
    };
    window.addEventListener('blur', blurHandler);
    this._listeners.push(['blur', blurHandler, window]);
  }

  // ─── Fullscreen Enforcement ─────────────────────────────────────────────────

  _setupFullscreen() {
    // Request fullscreen
    this._requestFullscreen();

    const handler = () => {
      if (!this.active) return;
      if (!document.fullscreenElement && !document.webkitFullscreenElement) {
        this._triggerViolation(
          'fullscreen_exit',
          'You exited fullscreen mode. Please return to fullscreen to continue the exam.'
        );
        // Re-request fullscreen after a moment
        setTimeout(() => {
          if (this.active) this._requestFullscreen();
        }, 2000);
      }
    };
    document.addEventListener('fullscreenchange', handler);
    document.addEventListener('webkitfullscreenchange', handler);
    this._listeners.push(['fullscreenchange', handler], ['webkitfullscreenchange', handler]);
  }

  _requestFullscreen() {
    const el = document.documentElement;
    try {
      if (el.requestFullscreen) el.requestFullscreen();
      else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
      else if (el.msRequestFullscreen) el.msRequestFullscreen();
    } catch (e) {
      console.warn('Proctoring: Could not enter fullscreen.', e.message);
    }
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
    } catch (e) { /* ignore */ }
  }
}

export default ProctoringService;
