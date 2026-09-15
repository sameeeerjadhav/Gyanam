/**
 * QuestionView Component
 * Displays a single question with answer options
 * Supports English + Marathi stacked in the same card
 */
export class QuestionView {
  constructor() {
    this.container = null;
    this.currentQuestion = null;
    this.currentAnswer = null;
    this.onAnswerChange = null;
    this.transitionDuration = 100;
    this._keyboardHandler = null;
  }

  _sanitizeText(text) {
    const div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
  }

  _hasDevanagari(s) {
    return /[\u0900-\u097F]/.test(String(s || ''));
  }

  /** Split a single field that may contain EN + MR (newline or " | "). */
  _splitBilingualField(text) {
    const raw = String(text ?? '').trim();
    if (!raw) return { en: '', mr: '' };

    const pipe = raw.split('|').map((p) => p.trim()).filter(Boolean);
    if (pipe.length === 2) {
      if (!this._hasDevanagari(pipe[0]) && this._hasDevanagari(pipe[1])) {
        return { en: pipe[0], mr: pipe[1] };
      }
      if (this._hasDevanagari(pipe[0]) && !this._hasDevanagari(pipe[1])) {
        return { en: pipe[1], mr: pipe[0] };
      }
    }

    const lines = raw.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
    if (lines.length >= 2) {
      const rest = lines.slice(1).join(' ');
      if (!this._hasDevanagari(lines[0]) && this._hasDevanagari(rest)) {
        return { en: lines[0], mr: rest };
      }
      if (this._hasDevanagari(lines[0]) && !this._hasDevanagari(lines[1])) {
        return { en: rest, mr: lines[0] };
      }
    }

    return { en: raw, mr: '' };
  }

  _bilingualPair(obj) {
    if (!obj || typeof obj !== 'object') return { en: '', mr: '' };
    const explicitMr = String(obj.text_mr ?? obj.textMr ?? obj.mr ?? obj.marathi ?? '').trim();
    const rawText = String(obj.text ?? '').trim();
    if (explicitMr) return { en: rawText, mr: explicitMr };
    return this._splitBilingualField(rawText);
  }

  _normalizeOptions(options) {
    if (Array.isArray(options)) return options;
    if (typeof options === 'string') {
      try {
        const parsed = JSON.parse(options);
        return Array.isArray(parsed) ? parsed : [];
      } catch (_) {
        return [];
      }
    }
    return [];
  }

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

    this.container.style.opacity = '0';
    this.container.style.transition = `opacity ${this.transitionDuration}ms ease-in-out`;

    setTimeout(() => {
      this._renderContent(question, questionNumber, savedAnswer);
      requestAnimationFrame(() => {
        this.container.style.opacity = '1';
      });
    }, this.transitionDuration);
  }

  _renderContent(question, questionNumber, savedAnswer) {
    this.container.innerHTML = '';

    const questionDiv = document.createElement('div');
    questionDiv.className = 'question-view';
    questionDiv.setAttribute('role', 'region');
    questionDiv.setAttribute('aria-label', `Question ${questionNumber}`);

    const pair = this._bilingualPair(question);
    const questionText = document.createElement('div');
    questionText.className = 'qv-question-text';
    questionText.style.cssText = 'margin-bottom:1.35rem;line-height:1.65;color:#0f172a';

    // English on top
    const enLine = document.createElement('div');
    enLine.className = 'qv-en';
    enLine.style.cssText = 'font-size:1.12rem;font-weight:600;letter-spacing:-0.01em;font-family:Inter,\"Noto Sans\",sans-serif';
    enLine.innerHTML = this._sanitizeText(pair.en || question.text);
    questionText.appendChild(enLine);

    // Marathi directly below (same card)
    if (pair.mr) {
      const mrLine = document.createElement('div');
      mrLine.className = 'qv-mr';
      mrLine.style.cssText = 'font-size:1.08rem;font-weight:500;color:#1e293b;margin-top:0.55rem;padding-top:0.55rem;border-top:1px dashed #cbd5e1;font-family:\"Noto Sans Devanagari\",\"Noto Sans\",sans-serif';
      mrLine.innerHTML = this._sanitizeText(pair.mr);
      questionText.appendChild(mrLine);
    }

    questionDiv.appendChild(questionText);
    questionDiv.appendChild(this._renderOptions(question, savedAnswer));
    this.container.appendChild(questionDiv);
    this._setupKeyboardShortcuts(question);
  }

  _renderOptions(question, savedAnswer) {
    const optionsDiv = document.createElement('div');
    optionsDiv.className = 'options-container';
    optionsDiv.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;align-items:stretch';
    optionsDiv.setAttribute('role', 'radiogroup');
    optionsDiv.setAttribute('aria-label', 'Answer options');

    const isMultipleChoice = question.type === 'multiple-choice-multiple';
    const inputType = isMultipleChoice ? 'checkbox' : 'radio';
    const savedAnswers = Array.isArray(savedAnswer) ? savedAnswer : (savedAnswer ? [savedAnswer] : []);
    const badgeColors = ['#3b82f6', '#8b5cf6', '#10b981', '#f59e0b'];
    const options = this._normalizeOptions(question.options);

    options.forEach((option, index) => {
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
        if (isMultipleChoice) this._handleMultipleChoiceChange(optionsDiv);
        else this._handleSingleChoiceChange(optionsDiv, label, option.id);
      });

      const badge = document.createElement('div');
      badge.style.cssText = `
        width:28px; height:28px; border-radius:7px;
        background:${isSelected ? '#1d4ed8' : badgeColors[index] + '15'};
        color:${isSelected ? '#ffffff' : badgeColors[index]};
        display:flex; align-items:center; justify-content:center;
        font-size:0.78rem; font-weight:700; flex-shrink:0; margin-top:0.05rem;
        transition:all 0.2s ease;
      `;
      badge.textContent = String.fromCharCode(65 + index);

      const optPair = this._bilingualPair(option);
      const text = document.createElement('span');
      text.style.cssText = 'color:#1e293b;font-weight:500;font-size:0.92rem;line-height:1.4;flex:1;min-width:0;display:flex;flex-direction:column;gap:0.35rem';

      const enOpt = document.createElement('span');
      enOpt.className = 'qv-en';
      enOpt.style.cssText = 'font-family:Inter,\"Noto Sans\",sans-serif';
      enOpt.innerHTML = this._sanitizeText(optPair.en || option.text);
      text.appendChild(enOpt);

      if (optPair.mr) {
        const mrOpt = document.createElement('span');
        mrOpt.className = 'qv-mr';
        mrOpt.style.cssText = 'color:#334155;font-weight:500;font-size:0.9rem;line-height:1.4;font-family:\"Noto Sans Devanagari\",\"Noto Sans\",sans-serif';
        mrOpt.innerHTML = this._sanitizeText(optPair.mr);
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

  _handleSingleChoiceChange(optionsDiv, selectedLabel, optionId) {
    this.currentAnswer = optionId;
    const badgeColors = ['#3b82f6', '#8b5cf6', '#10b981', '#f59e0b'];
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
    if (this.onAnswerChange) this.onAnswerChange(optionId);
  }

  _handleMultipleChoiceChange(optionsDiv) {
    const checkedInputs = optionsDiv.querySelectorAll('input[type="checkbox"]:checked');
    const selectedIds = Array.from(checkedInputs).map((input) => input.value);
    this.currentAnswer = selectedIds.length > 0 ? selectedIds : null;
    const badgeColors = ['#3b82f6', '#8b5cf6', '#10b981', '#f59e0b'];
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
    if (this.onAnswerChange) this.onAnswerChange(this.currentAnswer);
  }

  _setupKeyboardShortcuts(question) {
    if (this._keyboardHandler) {
      document.removeEventListener('keydown', this._keyboardHandler);
    }

    this._keyboardHandler = (event) => {
      if (event.target.tagName === 'INPUT' || event.target.tagName === 'TEXTAREA') return;
      const key = event.key.toLowerCase();
      let optionIndex = -1;
      if (key >= '1' && key <= '4') optionIndex = parseInt(key, 10) - 1;
      else if (key >= 'a' && key <= 'd') optionIndex = key.charCodeAt(0) - 'a'.charCodeAt(0);

      const opts = this._normalizeOptions(question.options);
      if (optionIndex >= 0 && optionIndex < opts.length) {
        event.preventDefault();
        const labels = this.container.querySelectorAll('label[data-option-index]');
        const targetLabel = Array.from(labels).find(
          (label) => label.getAttribute('data-option-index') === String(optionIndex)
        );
        if (targetLabel) {
          const input = targetLabel.querySelector('input');
          if (input) {
            if (input.type === 'checkbox') input.checked = !input.checked;
            else input.checked = true;
            input.dispatchEvent(new Event('change'));
          }
        }
      }
    };

    document.addEventListener('keydown', this._keyboardHandler);
  }

  getCurrentAnswer() {
    return this.currentAnswer;
  }

  clear() {
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
