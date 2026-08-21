/**
 * SecurityMonitor - Proctoring enforcement and event logging
 * 
 * Fixed: Replaced alert()/confirm() with CustomEvents for modal handling
 * The UI layer listens for 'security:warning' and 'security:fullscreen-exit' events.
 */
class SecurityMonitor {
  constructor() {
    this.config = null;
    this.events = [];
    this.isMonitoring = false;
    this.currentQuestionIndex = 0;

    this.handleVisibilityChange = this.handleVisibilityChange.bind(this);
    this.handleFullscreenChange = this.handleFullscreenChange.bind(this);
    this.handleWindowBlur = this.handleWindowBlur.bind(this);
    this.handleContextMenu = this.handleContextMenu.bind(this);
    this.handleCopy = this.handleCopy.bind(this);
    this.handlePaste = this.handlePaste.bind(this);
  }

  initialize(securityConfig) {
    if (!securityConfig || typeof securityConfig !== 'object') {
      throw new TypeError('Security configuration must be an object');
    }

    this.config = {
      fullScreenRequired: securityConfig.fullScreenRequired || false,
      cameraRequired: securityConfig.cameraRequired || false,
      microphoneRequired: securityConfig.microphoneRequired || false,
      tabSwitchLimit: securityConfig.tabSwitchLimit || Infinity,
      allowReview: securityConfig.allowReview !== false,
      allowSkip: securityConfig.allowSkip !== false
    };

    this.events = [];
    this.isMonitoring = false;
  }

  startMonitoring() {
    if (this.isMonitoring) return;
    if (!this.config) throw new Error('SecurityMonitor must be initialized before starting monitoring');

    document.addEventListener('visibilitychange', this.handleVisibilityChange);
    document.addEventListener('fullscreenchange', this.handleFullscreenChange);
    window.addEventListener('blur', this.handleWindowBlur);
    document.addEventListener('contextmenu', this.handleContextMenu);
    document.addEventListener('copy', this.handleCopy);
    document.addEventListener('paste', this.handlePaste);

    this.isMonitoring = true;
    this.logEvent({ type: 'monitoring_started', timestamp: Date.now(), questionIndex: this.currentQuestionIndex, metadata: { config: this.config } });
  }

  stopMonitoring() {
    if (!this.isMonitoring) return;

    document.removeEventListener('visibilitychange', this.handleVisibilityChange);
    document.removeEventListener('fullscreenchange', this.handleFullscreenChange);
    window.removeEventListener('blur', this.handleWindowBlur);
    document.removeEventListener('contextmenu', this.handleContextMenu);
    document.removeEventListener('copy', this.handleCopy);
    document.removeEventListener('paste', this.handlePaste);

    this.isMonitoring = false;
    this.logEvent({ type: 'monitoring_stopped', timestamp: Date.now(), questionIndex: this.currentQuestionIndex, metadata: {} });
  }

  getEvents() { return [...this.events]; }

  setCurrentQuestionIndex(questionIndex) {
    this.currentQuestionIndex = questionIndex;
  }

  logEvent(event) {
    this.events.push({ ...event, timestamp: event.timestamp || Date.now() });
  }

  /**
   * Emit a security event that the UI can listen to
   * @private
   */
  _emitSecurityEvent(type, detail) {
    document.dispatchEvent(new CustomEvent('security:event', {
      bubbles: true,
      detail: { type, ...detail }
    }));
  }

  handleVisibilityChange() {
    if (document.hidden) {
      this.logEvent({ type: 'tab_switch', timestamp: Date.now(), questionIndex: this.currentQuestionIndex, metadata: { hidden: true } });
      this._scheduleTabSwitchWarning();
    }
  }

  _scheduleTabSwitchWarning() {
    const showWarning = () => {
      if (!document.hidden) {
        const tabSwitchCount = this.getEventCount('tab_switch');
        const remaining = this.config.tabSwitchLimit !== Infinity
          ? this.config.tabSwitchLimit - tabSwitchCount
          : null;

        // Emit event instead of alert()
        this._emitSecurityEvent('tab_switch_warning', {
          tabSwitchCount,
          remaining,
          limitExceeded: remaining !== null && remaining <= 0
        });

        document.removeEventListener('visibilitychange', showWarning);
      }
    };
    document.addEventListener('visibilitychange', showWarning);
  }

  handleFullscreenChange() {
    const isFullscreen = !!(
      document.fullscreenElement ||
      document.webkitFullscreenElement ||
      document.mozFullScreenElement ||
      document.msFullscreenElement
    );

    if (!isFullscreen && this.config.fullScreenRequired) {
      this.logEvent({ type: 'fullscreen_exit', timestamp: Date.now(), questionIndex: this.currentQuestionIndex, metadata: {} });

      // Emit event instead of confirm()
      const exitCount = this.getEventCount('fullscreen_exit');
      this._emitSecurityEvent('fullscreen_exit', { exitCount });
    }
  }

  handleWindowBlur() {
    this.logEvent({ type: 'window_blur', timestamp: Date.now(), questionIndex: this.currentQuestionIndex, metadata: {} });
  }

  handleContextMenu(event) {
    event.preventDefault();
    this.logEvent({ type: 'context_menu_attempt', timestamp: Date.now(), questionIndex: this.currentQuestionIndex, metadata: {} });
    this._emitSecurityEvent('context_menu', {});
  }

  handleCopy(event) {
    event.preventDefault();
    this.logEvent({ type: 'copy_attempt', timestamp: Date.now(), questionIndex: this.currentQuestionIndex, metadata: {} });
  }

  handlePaste(event) {
    event.preventDefault();
    this.logEvent({ type: 'paste_attempt', timestamp: Date.now(), questionIndex: this.currentQuestionIndex, metadata: {} });
  }

  async requestCameraAccess() {
    if (!this.config.cameraRequired) return null;
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: true });
      this.logEvent({ type: 'camera_access_granted', timestamp: Date.now(), questionIndex: this.currentQuestionIndex, metadata: { streamId: stream.id } });
      return stream;
    } catch (error) {
      this.logEvent({ type: 'camera_access_denied', timestamp: Date.now(), questionIndex: this.currentQuestionIndex, metadata: { error: error.message } });
      throw error;
    }
  }

  async requestMicrophoneAccess() {
    if (!this.config.microphoneRequired) return null;
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      this.logEvent({ type: 'microphone_access_granted', timestamp: Date.now(), questionIndex: this.currentQuestionIndex, metadata: { streamId: stream.id } });
      return stream;
    } catch (error) {
      this.logEvent({ type: 'microphone_access_denied', timestamp: Date.now(), questionIndex: this.currentQuestionIndex, metadata: { error: error.message } });
      throw error;
    }
  }

  async requestFullscreen() {
    if (!this.config.fullScreenRequired) return;
    try {
      const el = document.documentElement;
      if (el.requestFullscreen) await el.requestFullscreen();
      else if (el.webkitRequestFullscreen) await el.webkitRequestFullscreen();
      else if (el.mozRequestFullScreen) await el.mozRequestFullScreen();
      else if (el.msRequestFullscreen) await el.msRequestFullscreen();
      this.logEvent({ type: 'fullscreen_entered', timestamp: Date.now(), questionIndex: this.currentQuestionIndex, metadata: {} });
    } catch (error) {
      this.logEvent({ type: 'fullscreen_request_failed', timestamp: Date.now(), questionIndex: this.currentQuestionIndex, metadata: { error: error.message } });
      throw error;
    }
  }

  getEventCount(eventType) {
    return this.events.filter(e => e.type === eventType).length;
  }

  isTabSwitchLimitExceeded() {
    return this.getEventCount('tab_switch') >= this.config.tabSwitchLimit;
  }

  clearEvents() { this.events = []; }
}

export default SecurityMonitor;
export { SecurityMonitor };
