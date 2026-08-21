/**
 * QuestionPalette Component
 * 
 * Visual navigation component displaying all questions with status indicators.
 * Supports click navigation and real-time status updates.
 * 
 * Requirements: 10.1, 10.6
 */
export class QuestionPalette {
  constructor() {
    this.questionCount = 0;
    this.onQuestionClickHandler = null;
    this.container = null;
    this.questionStatuses = new Map();
  }

  /**
   * Initialize palette with question count
   * @param {number} questionCount - Total number of questions
   * @param {Function} onQuestionClick - Click handler function(questionIndex)
   */
  initialize(questionCount, onQuestionClick) {
    if (typeof questionCount !== 'number' || questionCount <= 0) {
      throw new Error('Question count must be a positive number');
    }

    if (typeof onQuestionClick !== 'function') {
      throw new Error('onQuestionClick must be a function');
    }

    this.questionCount = questionCount;
    this.onQuestionClickHandler = onQuestionClick;

    // Initialize all questions as unattempted
    this.questionStatuses.clear();
    for (let i = 0; i < questionCount; i++) {
      this.questionStatuses.set(i, 'unattempted');
    }
  }

  /**
   * Update question status
   * @param {number} questionIndex - Zero-based question index
   * @param {string} status - Status: 'unattempted' | 'attempted' | 'marked' | 'current'
   */
  updateQuestionStatus(questionIndex, status) {
    if (typeof questionIndex !== 'number' || questionIndex < 0 || questionIndex >= this.questionCount) {
      throw new Error(`Invalid question index: ${questionIndex}`);
    }

    const validStatuses = ['unattempted', 'attempted', 'marked', 'current'];
    if (!validStatuses.includes(status)) {
      throw new Error(`Invalid status: ${status}. Must be one of: ${validStatuses.join(', ')}`);
    }

    this.questionStatuses.set(questionIndex, status);

    // Update DOM if already rendered
    if (this.container) {
      this._updateQuestionElement(questionIndex, status);
    }
  }

  /**
   * Render palette to DOM container
   * @param {HTMLElement} container - Container element
   */
  render(container) {
    if (!(container instanceof HTMLElement)) {
      throw new Error('Container must be an HTMLElement');
    }

    this.container = container;
    this.container.innerHTML = '';

    // Create grid container - using explicit CSS to avoid any Tailwind conflicts
    const grid = document.createElement('div');
    grid.style.display = 'grid';
    grid.style.gridTemplateColumns = 'repeat(5, 1fr)';
    grid.style.gap = '0.625rem';
    grid.style.width = '100%';
    grid.setAttribute('role', 'navigation');
    grid.setAttribute('aria-label', 'Question navigation palette');

    // Create question buttons
    for (let i = 0; i < this.questionCount; i++) {
      const button = this._createQuestionButton(i);
      grid.appendChild(button);
    }

    // Use event delegation for click handling
    grid.addEventListener('click', (event) => {
      const button = event.target.closest('[data-question-index]');
      if (button) {
        const questionIndex = parseInt(button.getAttribute('data-question-index'), 10);
        if (this.onQuestionClickHandler) {
          this.onQuestionClickHandler(questionIndex);
        }
      }
    });

    // Add keyboard navigation support
    grid.addEventListener('keydown', (event) => {
      const button = event.target.closest('[data-question-index]');
      if (!button) return;

      // Enter or Space key to select question
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        const questionIndex = parseInt(button.getAttribute('data-question-index'), 10);
        if (this.onQuestionClickHandler) {
          this.onQuestionClickHandler(questionIndex);
        }
      }

      // Arrow key navigation
      const currentIndex = parseInt(button.getAttribute('data-question-index'), 10);
      let targetIndex = null;

      switch (event.key) {
        case 'ArrowRight':
          targetIndex = currentIndex + 1;
          break;
        case 'ArrowLeft':
          targetIndex = currentIndex - 1;
          break;
        case 'ArrowDown':
          targetIndex = currentIndex + 5; // Move down one row (5 columns)
          break;
        case 'ArrowUp':
          targetIndex = currentIndex - 5; // Move up one row
          break;
      }

      if (targetIndex !== null && targetIndex >= 0 && targetIndex < this.questionCount) {
        event.preventDefault();
        const targetButton = grid.querySelector(`[data-question-index="${targetIndex}"]`);
        if (targetButton) {
          targetButton.focus();
        }
      }
    });

    this.container.appendChild(grid);
  }

  /**
   * Create a question button element
   * @private
   * @param {number} questionIndex - Zero-based question index
   * @returns {HTMLElement} Button element
   */
  _createQuestionButton(questionIndex) {
    const button = document.createElement('button');
    button.setAttribute('data-question-index', questionIndex);
    button.setAttribute('type', 'button');
    button.setAttribute('tabindex', '0');
    button.setAttribute('aria-label', `Question ${questionIndex + 1}`);
    button.textContent = questionIndex + 1;

    // Base styles
    button.className = 'question-palette-button w-12 h-12 rounded border-2 font-medium transition-all duration-200 hover:scale-105 focus:outline-none focus:ring-2 focus:ring-blue-500';
    button.style.backgroundColor = '#f1f5f9';
    button.style.borderColor = '#e2e8f0';
    button.style.color = '#475569';

    // Apply status-specific styles
    const status = this.questionStatuses.get(questionIndex) || 'unattempted';
    this._applyStatusStyles(button, status);

    return button;
  }

  /**
   * Update a specific question element in the DOM
   * @private
   * @param {number} questionIndex - Zero-based question index
   * @param {string} status - New status
   */
  _updateQuestionElement(questionIndex, status) {
    const button = this.container.querySelector(`[data-question-index="${questionIndex}"]`);
    if (button) {
      // Preserve focus if this button was focused
      const wasFocused = document.activeElement === button;
      this._applyStatusStyles(button, status);
      if (wasFocused) {
        button.focus();
      }
    }
  }

  /**
   * Apply status-specific styles to a button
   * @private
   * @param {HTMLElement} button - Button element
   * @param {string} status - Status to apply
   */
  _applyStatusStyles(button, status) {
    // Remove all status classes
    button.classList.remove(
      'bg-white', 'text-gray-700', 'border-gray-300',
      'bg-green-500', 'text-white', 'border-green-500',
      'bg-yellow-400', 'text-gray-900', 'border-yellow-400',
      'border-blue-500', 'font-bold', 'ring-2', 'ring-blue-300'
    );

    // Apply status-specific styles
    switch (status) {
      case 'unattempted':
        button.style.backgroundColor = '#f1f5f9';
        button.style.borderColor = '#e2e8f0';
        button.style.color = '#475569';
        button.style.fontWeight = '500';
        button.style.boxShadow = 'none';
        break;
      case 'attempted':
        button.style.backgroundColor = '#16a34a';
        button.style.borderColor = '#16a34a';
        button.style.color = '#ffffff';
        button.style.fontWeight = '700';
        button.style.boxShadow = 'none';
        break;
      case 'marked':
        button.style.backgroundColor = '#d97706';
        button.style.borderColor = '#d97706';
        button.style.color = '#ffffff';
        button.style.fontWeight = '700';
        button.style.boxShadow = 'none';
        break;
      case 'current':
        button.style.backgroundColor = '#eff6ff';
        button.style.borderColor = '#1d4ed8';
        button.style.color = '#1d4ed8';
        button.style.fontWeight = '800';
        button.style.boxShadow = '0 0 0 2px rgba(29,78,216,0.15)';
        break;
    }
  }

  /**
   * Get current status of a question
   * @param {number} questionIndex - Zero-based question index
   * @returns {string} Current status
   */
  getQuestionStatus(questionIndex) {
    return this.questionStatuses.get(questionIndex) || 'unattempted';
  }

  /**
   * Get all question statuses
   * @returns {Map<number, string>} Map of question indices to statuses
   */
  getAllStatuses() {
    return new Map(this.questionStatuses);
  }

  /**
   * Clear the palette
   */
  clear() {
    if (this.container) {
      this.container.innerHTML = '';
      this.container = null;
    }
    this.questionStatuses.clear();
    this.questionCount = 0;
    this.onQuestionClickHandler = null;
  }
}
