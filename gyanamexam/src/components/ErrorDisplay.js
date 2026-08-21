/**
 * ErrorDisplay - Component for displaying error messages and notifications
 * 
 * Features:
 * - Modal for critical error messages
 * - Toast notifications for warnings and info
 * - Auto-dismiss for non-critical messages
 * - Styled with Tailwind CSS
 * 
 * Requirements: 29.1, 29.2, 29.3
 */

export class ErrorDisplay {
  constructor() {
    this.modalContainer = null;
    this.toastContainer = null;
    this.activeToasts = [];
    this.initialize();
  }

  /**
   * Initialize error display containers
   * @private
   */
  initialize() {
    // Create modal container
    this.modalContainer = document.createElement('div');
    this.modalContainer.id = 'error-modal-container';
    this.modalContainer.className = 'fixed inset-0 z-50 hidden';
    document.body.appendChild(this.modalContainer);

    // Create toast container
    this.toastContainer = document.createElement('div');
    this.toastContainer.id = 'toast-container';
    this.toastContainer.className = 'fixed top-4 right-4 z-50 space-y-2';
    document.body.appendChild(this.toastContainer);
  }

  /**
   * Show error modal
   * @param {string} title - Error title
   * @param {string} message - Error message
   * @param {Object} options - Additional options
   * @param {string} options.type - Error type ('error', 'warning', 'info')
   * @param {Function} options.onClose - Callback when modal is closed
   * @param {boolean} options.showRetry - Show retry button
   * @param {Function} options.onRetry - Callback for retry action
   */
  showModal(title, message, options = {}) {
    const {
      type = 'error',
      onClose = null,
      showRetry = false,
      onRetry = null
    } = options;

    const config = this.getTypeConfig(type);

    this.modalContainer.innerHTML = `
      <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6 animate-fade-in">
          <!-- Icon -->
          <div class="flex items-center justify-center mb-4">
            <div class="rounded-full ${config.bgColor} p-3">
              ${config.icon}
            </div>
          </div>
          
          <!-- Title -->
          <h3 class="text-xl font-bold text-gray-900 text-center mb-2">
            ${title}
          </h3>
          
          <!-- Message -->
          <p class="text-gray-600 text-center mb-6">
            ${message}
          </p>
          
          <!-- Actions -->
          <div class="flex gap-3">
            ${showRetry ? `
              <button 
                id="modal-retry-btn"
                class="flex-1 px-4 py-2 ${config.buttonColor} text-white rounded-lg hover:opacity-90 transition-opacity font-medium"
              >
                Retry
              </button>
            ` : ''}
            <button 
              id="modal-close-btn"
              class="flex-1 px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 transition-colors font-medium"
            >
              Close
            </button>
          </div>
        </div>
      </div>
    `;

    this.modalContainer.classList.remove('hidden');

    // Attach event listeners
    const closeBtn = this.modalContainer.querySelector('#modal-close-btn');
    closeBtn.addEventListener('click', () => {
      this.hideModal();
      if (onClose) onClose();
    });

    if (showRetry) {
      const retryBtn = this.modalContainer.querySelector('#modal-retry-btn');
      retryBtn.addEventListener('click', () => {
        this.hideModal();
        if (onRetry) onRetry();
      });
    }
  }

  /**
   * Hide error modal
   */
  hideModal() {
    this.modalContainer.classList.add('hidden');
    this.modalContainer.innerHTML = '';
  }

  /**
   * Show toast notification
   * @param {string} message - Toast message
   * @param {Object} options - Additional options
   * @param {string} options.type - Toast type ('error', 'warning', 'success', 'info')
   * @param {number} options.duration - Duration in milliseconds (0 for no auto-dismiss)
   * @param {Function} options.onClose - Callback when toast is closed
   */
  showToast(message, options = {}) {
    const {
      type = 'info',
      duration = 5000,
      onClose = null
    } = options;

    const config = this.getTypeConfig(type);
    const toastId = `toast-${Date.now()}-${Math.random()}`;

    const toast = document.createElement('div');
    toast.id = toastId;
    toast.className = `${config.bgColor} ${config.textColor} px-4 py-3 rounded-lg shadow-lg flex items-center gap-3 max-w-sm animate-slide-in`;
    toast.innerHTML = `
      <div class="flex-shrink-0">
        ${config.smallIcon}
      </div>
      <p class="flex-1 text-sm font-medium">${message}</p>
      <button class="toast-close-btn flex-shrink-0 hover:opacity-70 transition-opacity">
        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
        </svg>
      </button>
    `;

    this.toastContainer.appendChild(toast);
    this.activeToasts.push(toastId);

    // Close button
    const closeBtn = toast.querySelector('.toast-close-btn');
    closeBtn.addEventListener('click', () => {
      this.hideToast(toastId);
      if (onClose) onClose();
    });

    // Auto-dismiss
    if (duration > 0) {
      setTimeout(() => {
        this.hideToast(toastId);
        if (onClose) onClose();
      }, duration);
    }
  }

  /**
   * Hide toast notification
   * @param {string} toastId - Toast ID
   */
  hideToast(toastId) {
    const toast = document.getElementById(toastId);
    if (toast) {
      toast.classList.add('animate-slide-out');
      setTimeout(() => {
        toast.remove();
        this.activeToasts = this.activeToasts.filter(id => id !== toastId);
      }, 300);
    }
  }

  /**
   * Clear all toasts
   */
  clearAllToasts() {
    this.activeToasts.forEach(toastId => {
      this.hideToast(toastId);
    });
  }

  /**
   * Get type configuration
   * @private
   * @param {string} type - Type ('error', 'warning', 'success', 'info')
   * @returns {Object} Configuration object
   */
  getTypeConfig(type) {
    const configs = {
      error: {
        bgColor: 'bg-red-50',
        textColor: 'text-red-800',
        buttonColor: 'bg-red-600',
        icon: `
          <svg class="h-8 w-8 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
        `,
        smallIcon: `
          <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
          </svg>
        `
      },
      warning: {
        bgColor: 'bg-yellow-50',
        textColor: 'text-yellow-800',
        buttonColor: 'bg-yellow-600',
        icon: `
          <svg class="h-8 w-8 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
        `,
        smallIcon: `
          <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
          </svg>
        `
      },
      success: {
        bgColor: 'bg-green-50',
        textColor: 'text-green-800',
        buttonColor: 'bg-green-600',
        icon: `
          <svg class="h-8 w-8 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        `,
        smallIcon: `
          <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
          </svg>
        `
      },
      info: {
        bgColor: 'bg-blue-50',
        textColor: 'text-blue-800',
        buttonColor: 'bg-blue-600',
        icon: `
          <svg class="h-8 w-8 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        `,
        smallIcon: `
          <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
          </svg>
        `
      }
    };

    return configs[type] || configs.info;
  }

  /**
   * Cleanup and remove containers
   */
  destroy() {
    this.clearAllToasts();
    if (this.modalContainer) {
      this.modalContainer.remove();
    }
    if (this.toastContainer) {
      this.toastContainer.remove();
    }
  }
}

// Create global instance
export const errorDisplay = new ErrorDisplay();

// Add CSS animations
const style = document.createElement('style');
style.textContent = `
  @keyframes fade-in {
    from {
      opacity: 0;
      transform: scale(0.95);
    }
    to {
      opacity: 1;
      transform: scale(1);
    }
  }

  @keyframes slide-in {
    from {
      opacity: 0;
      transform: translateX(100%);
    }
    to {
      opacity: 1;
      transform: translateX(0);
    }
  }

  @keyframes slide-out {
    from {
      opacity: 1;
      transform: translateX(0);
    }
    to {
      opacity: 0;
      transform: translateX(100%);
    }
  }

  .animate-fade-in {
    animation: fade-in 0.2s ease-out;
  }

  .animate-slide-in {
    animation: slide-in 0.3s ease-out;
  }

  .animate-slide-out {
    animation: slide-out 0.3s ease-in;
  }
`;
document.head.appendChild(style);
