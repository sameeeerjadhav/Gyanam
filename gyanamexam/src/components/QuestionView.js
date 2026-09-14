/**
 * QuestionView Component
 * Displays a single question with answer options
 * Supports multiple question types with XSS prevention and keyboard shortcuts
 */
export class QuestionView {
  constructor() {
    this.container = null;
    this.currentQuestion = null;
    this.currentAnswer = null;
    this.onAnswerChange = null;
    this.transitionDuration = 100; // milliseconds for smooth transitions
    this._keyboardHandler = null;
  }

  /**
   * Sanitize text to prevent XSS attacks
   * @param {string} text - Text to sanitize
   * @returns {string} Sanitized text
   * @private
   */
  _sanitizeText(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  /**
   * Render question to container with smooth transition
   * @param {HTMLElement} container - Container element
   * @param {Object} question - Question object
   * @param {number} questionNumber - Question number (1-based)
   * @param {string|string[]|null} savedAnswer - Previously saved answer
   * @param {Function} onAnswerChange - Callback when answer changes
   */
  render(container, question, questionNumber, savedAnswer = null, onAnswerChange = null) {
    if (!(container instanceof HTMLElement)) {
      throw new Error('Container must be an HTMLElement');
    }

    if (!question || !question.id || !question.text || !question.options) {
      throw new Error('Invalid question object');
    }

    this.container = container;
    this.currentQuestion = question;
    this.currentAnswer = savedAnswer;
    this.onAnswerChange = onAnswerChange;

    // Fade out transition
    this.container.style.opacity = '0';
    this.container.style.transition = `opacity ${this.transitionDuration}ms ease-in-out`;

    setTimeout(() => {
      this._renderContent(question, questionNumber, savedAnswer);

      // Fade in transition
      requestAnimationFrame(() => {
        this.container.style.opacity = '1';
      });
    }, this.transitionDuration);
  }

  /**
   * Resolve Marathi text from question/option payloads (supports alternate keys).
   * @private
   */
  _mrText(obj) {
    if (!obj || typeof obj !== 'object') return '';
    const raw = obj.text_mr ?? obj.textMr ?? obj.mr ?? '';
    return String(raw).trim();
  }

  /**
   * Render question content
   * @param {Object} question - Question object
   * @param {number} questionNumber - Question number (1-based)
   * @param {string|string[]|null} savedAnswer - Previously saved answer
   * @private
   */
  _renderContent(question, questionNumber, savedAnswer) {
    this.container.innerHTML = '';

    const questionDiv = document.createElement('div');
    questionDiv.className = 'question-view';
    questionDiv.setAttribute('role', 'region');
    questionDiv.setAttribute('aria-label', `Question ${questionNumber}`);

    // English on top, Marathi directly below — same card
    const questionText = document.createElement('div');
    questionText.style.cssText = 'margin-bottom:1.35rem;line-height:1.65;color:#0f172a';

    const enLine = document.createElement('div');
    enLine.style.cssText = 'font-size:1.12rem;font-weight:600;letter-spacing:-0.01em';
    enLine.innerHTML = this._sanitizeText(question.text);
    questionText.appendChild(enLine);

    const mrQ = this._mrText(question);
    if (mrQ) {
      const mrLine = document.createElement('div');
      mrLine.style.cssText = 'font-size:1.05rem;font-weight:500;color:#334155;margin-top:0.55rem;padding-top:0.55rem;border-top:1px dashed #e2e8f0';
      mrLine.innerHTML = this._sanitizeText(mrQ);
      questionText.appendChild(mrLine);
    }
    questionDiv.appendChild(questionText);

    const optionsDiv = this._renderOptions(question, savedAnswer);
    questionDiv.appendChild(optionsDiv);

    this.container.appendChild(questionDiv);
    this._setupKeyboardShortcuts(question);
  }

  /**
   * Get human-readable question type label
   * @param {string} type - Question type
   * @returns {string} Label
   * @private
   */
  _getQuestionTypeLabel(type) {
    const labels = {
      'multiple-choice-single': 'Single Choice',
      'multiple-choice-multiple': 'Multiple Choice',
      'true-false': 'True/False'
    };
    return labels[type] || 'Question';
  }

  /**
   * Render options based on question type
   * @param {Object} question - Question object
   * @param {string|string[]|null} savedAnswer - Previously saved answer
   * @returns {HTMLElement} Options container
   * @private
   */
  _renderOptions(question, savedAnswer) {
    const optionsDiv = document.createElement('div');
    optionsDiv.className = 'options-container';
    // A B on row 1, C D on row 2
    optionsDiv.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;align-items:stretch';
    optionsDiv.setAttribute('role', 'radiogroup');
    optionsDiv.setAttribute('aria-label', 'Answer options');

    const isMultipleChoice = question.type === 'multiple-choice-multiple';
    const inputType = isMultipleChoice ? 'checkbox' : 'radio';
    const savedAnswers = Array.isArray(savedAnswer) ? savedAnswer : (savedAnswer ? [savedAnswer] : []);

    const badgeColors = ['#3b82f6', '#8b5cf6', '#10b981', '#f59e0b'];

    question.options.forEach((option, index) => {
      const optionDiv = document.createElement('div');
      optionDiv.className = 'option-item';
      optionDiv.style.cssText = 'min-width:0';

      const isSelected = savedAnswers.includes(option.id);
      const label = document.createElement('label');
      label.setAttribute('data-option-index', index);
      label.style.cssText = `
        display:flex; align-items:flex-start; gap:0.7rem;
        padding:0.85rem 0.9rem; border-radius:12px; cursor:pointer;
        border:2px solid ${isSelected ? '#1d4ed8' : '#e2e8f0'};
        background:${isSelected ? '#eff6ff' : '#ffffff'};
        transition:all 0.2s ease; width:100%; height:100%; box-sizing:border-box;
      `;
      label.addEventListener('mouseenter', () => {
        if (!label.querySelector('input').checked) {
          label.style.borderColor = '#93c5fd';
          label.style.background = '#f8fafc';
        }
      });
      label.addEventListener('mouseleave', () => {
        const checked = label.querySelector('input').checked;
        label.style.borderColor = checked ? '#1d4ed8' : '#e2e8f0';
        label.style.background = checked ? '#eff6ff' : '#ffffff';
      });

      const input = document.createElement('input');
      input.type = inputType;
      input.name = isMultipleChoice ? `question-${question.id}-option` : `question-${question.id}`;
      input.value = option.id;
      input.style.cssText = 'width:18px;height:18px;accent-color:#1d4ed8;cursor:pointer;flex-shrink:0;margin-top:0.2rem';
      input.setAttribute('aria-label', `Option ${String.fromCharCode(65 + index)}`);

      if (isSelected) input.checked = true;

      input.addEventListener('change', () => {
        if (isMultipleChoice) {
          this._handleMultipleChoiceChange(optionsDiv);
        } else {
          this._handleSingleChoiceChange(optionsDiv, label, option.id);
        }
      });

      const badge = document.createElement('div');
      const optionLabel = String.fromCharCode(65 + index);
      badge.style.cssText = `
        width:28px; height:28px; border-radius:7px;
        background:${isSelected ? '#1d4ed8' : badgeColors[index] + '15'};
        color:${isSelected ? '#ffffff' : badgeColors[index]};
        display:flex; align-items:center; justify-content:center;
        font-size:0.78rem; font-weight:700; flex-shrink:0; margin-top:0.05rem;
        transition:all 0.2s ease;
      `;
      badge.textContent = optionLabel;

      const text = document.createElement('span');
      text.style.cssText = 'color:#1e293b;font-weight:500;font-size:0.92rem;line-height:1.4;flex:1;min-width:0;display:flex;flex-direction:column;gap:0.3rem';
      const enOpt = document.createElement('span');
      enOpt.innerHTML = this._sanitizeText(option.text);
      text.appendChild(enOpt);

      const mrOptText = this._mrText(option);
      if (mrOptText) {
        const mrOpt = document.createElement('span');
        mrOpt.style.cssText = 'color:#475569;font-weight:500;font-size:0.88rem;line-height:1.4';
        mrOpt.innerHTML = this._sanitizeText(mrOptText);
        text.appendChild(mrOpt);
      }

      label.appendChild(input);
      label.appendChild(badge);
      label.appendChild(text);
      optionDiv.appendChild(label);
      optionsDiv.appendChild(optionDiv);
    });

    return optionsDiv;
  }

  /**
   * Handle single choice answer change
   * @param {HTMLElement} optionsDiv - Options container
   * @param {HTMLElement} selectedLabel - Selected label element
   * @param {string} optionId - Selected option ID
   * @private
   */
  _handleSingleChoiceChange(optionsDiv, selectedLabel, optionId) {
    this.currentAnswer = optionId;
    const badgeColors = ['#3b82f6', '#8b5cf6', '#10b981', '#f59e0b'];

    // Update visual feedback
    optionsDiv.querySelectorAll('label').forEach((l, i) => {
      l.style.background = '#ffffff';
      l.style.borderColor = '#e2e8f0';
      const b = l.querySelector('div');
      if (b) { b.style.background = badgeColors[i] + '15'; b.style.color = badgeColors[i]; }
    });
    selectedLabel.style.background = '#eff6ff';
    selectedLabel.style.borderColor = '#1d4ed8';
    const selBadge = selectedLabel.querySelector('div');
    if (selBadge) { selBadge.style.background = '#1d4ed8'; selBadge.style.color = '#ffffff'; }

    if (this.onAnswerChange) {
      this.onAnswerChange(optionId);
    }
  }

  /**
   * Handle multiple choice answer change
   * @param {HTMLElement} optionsDiv - Options container
   * @private
   */
  _handleMultipleChoiceChange(optionsDiv) {
    const checkedInputs = optionsDiv.querySelectorAll('input[type="checkbox"]:checked');
    const selectedIds = Array.from(checkedInputs).map(input => input.value);
    this.currentAnswer = selectedIds.length > 0 ? selectedIds : null;
    const badgeColors = ['#3b82f6', '#8b5cf6', '#10b981', '#f59e0b'];

    // Update visual feedback
    optionsDiv.querySelectorAll('label').forEach((label, i) => {
      const input = label.querySelector('input');
      const badge = label.querySelector('div');
      if (input.checked) {
        label.style.background = '#eff6ff';
        label.style.borderColor = '#1d4ed8';
        if (badge) { badge.style.background = '#1d4ed8'; badge.style.color = '#ffffff'; }
      } else {
        label.style.background = '#ffffff';
        label.style.borderColor = '#e2e8f0';
        if (badge) { badge.style.background = badgeColors[i] + '15'; badge.style.color = badgeColors[i]; }
      }
    });

    if (this.onAnswerChange) {
      this.onAnswerChange(this.currentAnswer);
    }
  }

  /**
   * Set up keyboard shortcuts for answer selection
   * @param {Object} question - Question object
   * @private
   */
  _setupKeyboardShortcuts(question) {
    // Remove previous listener if exists
    if (this._keyboardHandler) {
      document.removeEventListener('keydown', this._keyboardHandler);
    }

    this._keyboardHandler = (event) => {
      // Only handle if not in input field
      if (event.target.tagName === 'INPUT' || event.target.tagName === 'TEXTAREA') {
        return;
      }

      // Keys 1-4 or A-D for options
      const key = event.key.toLowerCase();
      let optionIndex = -1;

      if (key >= '1' && key <= '4') {
        optionIndex = parseInt(key) - 1;
      } else if (key >= 'a' && key <= 'd') {
        optionIndex = key.charCodeAt(0) - 'a'.charCodeAt(0);
      }

      if (optionIndex >= 0 && optionIndex < question.options.length) {
        event.preventDefault();
        const labels = this.container.querySelectorAll('label[data-option-index]');
        const targetLabel = Array.from(labels).find(
          label => label.getAttribute('data-option-index') === String(optionIndex)
        );

        if (targetLabel) {
          const input = targetLabel.querySelector('input');
          if (input) {
            if (input.type === 'checkbox') {
              input.checked = !input.checked;
            } else {
              input.checked = true;
            }
            input.dispatchEvent(new Event('change'));
          }
        }
      }
    };

    document.addEventListener('keydown', this._keyboardHandler);
  }

  /**
   * Get current answer
   * @returns {string|string[]|null} Selected answer ID(s)
   */
  getCurrentAnswer() {
    return this.currentAnswer;
  }

  /**
   * Clear the view and cleanup
   */
  clear() {
    // Remove keyboard listener
    if (this._keyboardHandler) {
      document.removeEventListener('keydown', this._keyboardHandler);
      this._keyboardHandler = null;
    }

    if (this.container) {
      this.container.innerHTML = '';
      this.container.style.opacity = '1';
      this.container.style.transition = '';
    }

    this.currentQuestion = null;
    this.currentAnswer = null;
    this.onAnswerChange = null;
  }
}
