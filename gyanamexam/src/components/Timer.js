/**
 * Timer Component
 * 
 * Countdown timer with pause/resume functionality and automatic expiration callback.
 * Displays time in MM:SS format and supports persistence through state management.
 * 
 * Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 11.4, 20.3
 */
export class Timer {
  constructor() {
    this.intervalId = null;
    this.remainingSeconds = 0;
    this.isPaused = false;
    this.onExpireCallback = null;
    this.onTickCallback = null;
    this.warningThresholds = {
      yellow: 300, // 5 minutes
      red: 60      // 1 minute
    };
  }

  /**
   * Start timer with duration
   * @param {number} durationMinutes - Duration in minutes
   * @param {Function} onExpire - Callback when timer expires
   * @param {Function} onTick - Optional callback on each tick with remaining seconds
   */
  start(durationMinutes, onExpire, onTick = null) {
    if (typeof durationMinutes !== 'number' || durationMinutes <= 0) {
      throw new Error('Duration must be a positive number');
    }

    if (typeof onExpire !== 'function') {
      throw new Error('onExpire must be a function');
    }

    // Stop any existing timer
    this.stop();

    // Initialize timer state
    this.remainingSeconds = Math.floor(durationMinutes * 60);
    this.isPaused = false;
    this.onExpireCallback = onExpire;
    this.onTickCallback = onTick;

    // Start countdown
    this.intervalId = setInterval(() => {
      if (!this.isPaused) {
        this.remainingSeconds--;

        // Call tick callback if provided
        if (this.onTickCallback && typeof this.onTickCallback === 'function') {
          this.onTickCallback(this.remainingSeconds);
        }

        if (this.remainingSeconds <= 0) {
          this.remainingSeconds = 0;
          // Save callback before stopping (stop() clears it)
          const callback = this.onExpireCallback;
          this.stop();
          if (callback) {
            callback();
          }
        }
      }
    }, 1000);
  }

  /**
   * Pause timer
   */
  pause() {
    this.isPaused = true;
  }

  /**
   * Resume timer
   */
  resume() {
    this.isPaused = false;
  }

  /**
   * Get remaining time in seconds
   * @returns {number} Remaining seconds
   */
  getRemainingTime() {
    return this.remainingSeconds;
  }

  /**
   * Get formatted time string in MM:SS format
   * @returns {string} Formatted time string
   */
  getFormattedTime() {
    const minutes = Math.floor(this.remainingSeconds / 60);
    const seconds = this.remainingSeconds % 60;
    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
  }

  /**
   * Get warning level based on remaining time
   * @returns {string} 'green' | 'yellow' | 'red'
   */
  getWarningLevel() {
    if (this.remainingSeconds <= this.warningThresholds.red) {
      return 'red';
    } else if (this.remainingSeconds <= this.warningThresholds.yellow) {
      return 'yellow';
    }
    return 'green';
  }

  /**
   * Check if timer should pulse (< 1 minute remaining)
   * @returns {boolean} True if timer should pulse
   */
  shouldPulse() {
    return this.remainingSeconds <= this.warningThresholds.red;
  }

  /**
   * Add extra seconds to the remaining time (for admin time extensions)
   * @param {number} seconds
   */
  addTime(seconds) {
    this.remainingSeconds = Math.max(0, this.remainingSeconds + Math.floor(seconds));
  }

  /**
   * Stop and cleanup timer
   */
  stop() {
    if (this.intervalId !== null) {
      clearInterval(this.intervalId);
      this.intervalId = null;
    }
    this.isPaused = false;
    this.onExpireCallback = null;
    this.onTickCallback = null;
  }

  /**
   * Check if timer is running
   * @returns {boolean} True if timer is running
   */
  isRunning() {
    return this.intervalId !== null;
  }

  /**
   * Check if timer is paused
   * @returns {boolean} True if timer is paused
   */
  isPausedState() {
    return this.isPaused;
  }
}
