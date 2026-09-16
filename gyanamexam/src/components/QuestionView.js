/**
 * QuestionView — polished bilingual question + MCQ options
 * Clean list options (not chunky multi-color cards)
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

  _ensureStyles() {
    if (document.getElementById('qv-polish-styles')) return;
    const st = document.createElement('style');
    st.id = 'qv-polish-styles';
    st.textContent = `
      .qv-root { font-family: 'Source Sans 3', 'Segoe UI', sans-serif; }
      .qv-stem {
        margin: 0 0 1.25rem;
        padding: 0 0 1.1rem;
        border-bottom: 1px solid #e8edf3;
      }
      .qv-en {
        font-size: 1.08rem;
        font-weight: 650;
        letter-spacing: -0.01em;
        line-height: 1.55;
        color: #0f2744;
        font-family: 'Source Sans 3', 'Segoe UI', sans-serif;
      }
      .qv-mr {
        margin-top: 0.55rem;
        font-size: 1.02rem;
        font-weight: 550;
        line-height: 1.55;
        color: #334155;
        font-family: 'Noto Sans Devanagari', 'Source Sans 3', sans-serif;
      }
      .options-container {
        display: flex !important;
        flex-direction: column;
        gap: 0.55rem;
      }
      .option-item { min-width: 0; }
      .qv-opt {
        display: flex;
        align-items: flex-start;
        gap: 0.85rem;
        width: 100%;
        padding: 0.85rem 1rem;
        border-radius: 10px;
        cursor: pointer;
        border: 1px solid #e2e8f0;
        background: #fff;
        box-sizing: border-box;
        transition: border-color .15s ease, background .15s ease, box-shadow .15s ease;
      }
      .qv-opt:hover {
        border-color: #cbd5e1;
        background: #fafbfc;
      }
      .qv-opt.is-selected {
        border-color: #16a34a;
        background: #f0fdf4;
        box-shadow: inset 3px 0 0 #16a34a;
      }
      .qv-opt input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
        width: 0; height: 0;
      }
      .qv-letter {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        border: 1.5px solid #cbd5e1;
        background: #f8fafc;
        color: #0f2744;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.78rem;
        font-weight: 800;
        flex-shrink: 0;
        margin-top: 0.05rem;
        transition: all .15s ease;
      }
      .qv-opt.is-selected .qv-letter {
        border-color: #16a34a;
        background: #16a34a;
        color: #fff;
      }
      .qv-opt-text {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 0.3rem;
        color: #1e293b;
        font-weight: 550;
        font-size: 0.95rem;
        line-height: 1.45;
      }
      .qv-opt-text .qv-en { font-size: 0.95rem; font-weight: 550; color: #1e293b; }
      .qv-opt-text .qv-mr { margin-top: 0; font-size: 0.9rem; color: #475569; }
    `;
    document.head.appendChild(st);
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
    this._ensureStyles();

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
    questionDiv.className = 'question-view qv-root';
    questionDiv.setAttribute('role', 'region');
    questionDiv.setAttribute('aria-label', `Question ${questionNumber}`);

    const pair = this._bilingualPair(question);
    const questionText = document.createElement('div');
    questionText.className = 'qv-stem';

    const enLine = document.createElement('div');
    enLine.className = 'qv-en';
    enLine.innerHTML = this._sanitizeText(pair.en || question.text);
    questionText.appendChild(enLine);

    if (pair.mr) {
      const mrLine = document.createElement('div');
      mrLine.className = 'qv-mr';
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
    optionsDiv.setAttribute('role', 'radiogroup');
    optionsDiv.setAttribute('aria-label', 'Answer options');

    const isMultipleChoice = question.type === 'multiple-choice-multiple';
    const inputType = isMultipleChoice ? 'checkbox' : 'radio';
    const savedAnswers = Array.isArray(savedAnswer) ? savedAnswer : (savedAnswer ? [savedAnswer] : []);
    const options = this._normalizeOptions(question.options);

    options.forEach((option, index) => {
      const optionDiv = document.createElement('div');
      optionDiv.className = 'option-item';

      const isSelected = savedAnswers.includes(option.id);
      const label = document.createElement('label');
      label.className = `qv-opt${isSelected ? ' is-selected' : ''}`;
      label.setAttribute('data-option-index', index);

      const input = document.createElement('input');
      input.type = inputType;
      input.name = isMultipleChoice ? `question-${question.id}-option` : `question-${question.id}`;
      input.value = option.id;
      input.setAttribute('aria-label', `Option ${String.fromCharCode(65 + index)}`);
      if (isSelected) input.checked = true;

      input.addEventListener('change', () => {
        if (isMultipleChoice) this._handleMultipleChoiceChange(optionsDiv);
        else this._handleSingleChoiceChange(optionsDiv, label, option.id);
      });

      const badge = document.createElement('div');
      badge.className = 'qv-letter';
      badge.textContent = String.fromCharCode(65 + index);

      const optPair = this._bilingualPair(option);
      const text = document.createElement('span');
      text.className = 'qv-opt-text';

      const enOpt = document.createElement('span');
      enOpt.className = 'qv-en';
      enOpt.innerHTML = this._sanitizeText(optPair.en || option.text);
      text.appendChild(enOpt);

      if (optPair.mr) {
        const mrOpt = document.createElement('span');
        mrOpt.className = 'qv-mr';
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
    optionsDiv.querySelectorAll('.qv-opt').forEach((l) => l.classList.remove('is-selected'));
    selectedLabel.classList.add('is-selected');
    if (this.onAnswerChange) this.onAnswerChange(optionId);
  }

  _handleMultipleChoiceChange(optionsDiv) {
    const checkedInputs = optionsDiv.querySelectorAll('input[type="checkbox"]:checked');
    const selectedIds = Array.from(checkedInputs).map((input) => input.value);
    this.currentAnswer = selectedIds.length > 0 ? selectedIds : null;
    optionsDiv.querySelectorAll('.qv-opt').forEach((label) => {
      const input = label.querySelector('input');
      label.classList.toggle('is-selected', !!(input && input.checked));
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
