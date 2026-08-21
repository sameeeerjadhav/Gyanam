/**
 * LoadingSpinner - Component for displaying loading states
 * 
 * Features:
 * - Spinner for loading states
 * - Overlay for blocking interactions
 * - Customizable size and message
 * - Styled with Tailwind CSS
 * 
 * Requirements: 19.1
 */

export class LoadingSpinner {
  constructor() {
    this.container = null;
    this.isVisible = false;
  }

  /**
   * Show loading spinner
   * @param {Object} options - Loading options
   * @param {string} options.message - Loading message
   * @param {boolean} options.overlay - Show overlay to block interactions
   * @param {string} options.size - Spinner size ('small', 'medium', 'large')
   */
  show(options = {}) {
    const {
      message = 'Loading...',
      overlay = true,
      size = 'medium'
    } = options;

    if (this.isVisible) {
      this.hide();
    }

    const sizeClasses = {
      small: 'h-8 w-8',
      medium: 'h-12 w-12',
      large: 'h-16 w-16'
    };

    const spinnerSize = sizeClasses[size] || sizeClasses.medium;

    this.container = document.createElement('div');
    this.container.id = 'loading-spinner-container';
    this.container.className = overlay 
      ? 'fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-30'
      : 'flex items-center justify-center py-8';

    this.container.innerHTML = `
      <div class="bg-white rounded-lg shadow-lg p-6 flex flex-col items-center">
        <div class="animate-spin rounded-full ${spinnerSize} border-b-2 border-blue-600"></div>
        ${message ? `<p class="mt-4 text-gray-700 font-medium">${message}</p>` : ''}
      </div>
    `;

    document.body.appendChild(this.container);
    this.isVisible = true;
  }

  /**
   * Hide loading spinner
   */
  hide() {
    if (this.container) {
      this.container.remove();
      this.container = null;
    }
    this.isVisible = false;
  }

  /**
   * Show inline loading spinner (for embedding in components)
   * @param {HTMLElement} targetElement - Element to show spinner in
   * @param {Object} options - Loading options
   * @param {string} options.message - Loading message
   * @param {string} options.size - Spinner size ('small', 'medium', 'large')
   */
  showInline(targetElement, options = {}) {
    const {
      message = 'Loading...',
      size = 'medium'
    } = options;

    const sizeClasses = {
      small: 'h-6 w-6',
      medium: 'h-10 w-10',
      large: 'h-14 w-14'
    };

    const spinnerSize = sizeClasses[size] || sizeClasses.medium;

    targetElement.innerHTML = `
      <div class="flex flex-col items-center justify-center py-8">
        <div class="animate-spin rounded-full ${spinnerSize} border-b-2 border-blue-600"></div>
        ${message ? `<p class="mt-3 text-gray-600">${message}</p>` : ''}
      </div>
    `;
  }

  /**
   * Show button loading state
   * @param {HTMLButtonElement} button - Button element
   * @param {string} loadingText - Text to show while loading
   */
  showButtonLoading(button, loadingText = 'Loading...') {
    if (!button) return;

    // Store original content
    button.dataset.originalContent = button.innerHTML;
    button.dataset.originalDisabled = button.disabled;

    // Set loading state
    button.disabled = true;
    button.innerHTML = `
      <span class="flex items-center justify-center gap-2">
        <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <span>${loadingText}</span>
      </span>
    `;
  }

  /**
   * Hide button loading state
   * @param {HTMLButtonElement} button - Button element
   */
  hideButtonLoading(button) {
    if (!button || !button.dataset.originalContent) return;

    // Restore original content
    button.innerHTML = button.dataset.originalContent;
    button.disabled = button.dataset.originalDisabled === 'true';

    // Clean up data attributes
    delete button.dataset.originalContent;
    delete button.dataset.originalDisabled;
  }
}

// Create global instance
export const loadingSpinner = new LoadingSpinner();
