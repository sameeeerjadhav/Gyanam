/**
 * PreExamGate.js — Multi-step professional pre-exam checklist.
 * Runs BEFORE getExamQuestions so the live timer does not start early.
 * Final "Start Exam" click is used as the user gesture for fullscreen / camera.
 */
import ApiClient from '../services/APIClient.js';

const SLOT_LABELS = {
  SLOT1: 'Slot 1 (Morning)',
  SLOT2: 'Slot 2 (Afternoon)',
  SLOT3: 'Slot 3 (Evening)',
};

const WINDOW_LABELS = {
  MORNING: 'Morning (10:00 – 13:00)',
  AFTERNOON: 'Afternoon (14:00 – 17:00)',
  EVENING: 'Evening (18:00 – 21:00)',
};

function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

export class PreExamGate {
  constructor({ examId, user, onCancel }) {
    this.examId = examId;
    this.user = user || {};
    this.onCancel = onCancel;
    this.step = 0;
    this.exam = null;
    this.termsAccepted = false;
    this.identityConfirmed = false;
    this.capturedPhoto = null; // data URL of still capture
    this._cameraStream = null;
    this._container = null;
    this._resolve = null;
    this._reject = null;
  }

  /**
   * @returns {Promise<{ examMeta: object, enteredFullscreen: boolean, cameraStream: MediaStream|null }>}
   */
  run(container) {
    this._container = container;
    return new Promise(async (resolve, reject) => {
      this._resolve = resolve;
      this._reject = reject;
      try {
        await this._loadExamMeta();
        this._render();
      } catch (e) {
        reject(e);
      }
    });
  }

  async _loadExamMeta() {
    const list = await ApiClient.getStudentExams();
    const exams = Array.isArray(list) ? list : (list?.data || []);
    this.exam = exams.find(e => String(e.id) === String(this.examId)) || null;
    if (!this.exam) throw new Error('This exam is not assigned to you or is no longer available.');
    if (this.exam.attempt_info && !this.exam.attempt_info.can_attempt) {
      throw new Error('No attempts remaining for this exam.');
    }
  }

  get settings() {
    return this.exam?.proctoring_settings || {};
  }

  get isProctored() {
    return !!this.exam?.proctored;
  }

  get enforceFullscreen() {
    return this.isProctored && this.settings.fullscreen_enforce !== false;
  }

  get needsCamera() {
    return this.isProctored && !!this.settings.camera;
  }

  destroy() {
    this._stopCamera();
  }

  _stopCamera() {
    if (this._cameraStream) {
      this._cameraStream.getTracks().forEach(t => t.stop());
      this._cameraStream = null;
    }
  }

  async _ensureCamera() {
    if (!this.needsCamera) return null;
    if (this._cameraStream) return this._cameraStream;
    try {
      this._cameraStream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
        audio: false,
      });
      return this._cameraStream;
    } catch (e) {
      throw new Error('Camera access is required for this proctored exam. Please allow camera and try again.');
    }
  }

  async _requestFullscreen() {
    const el = document.documentElement;
    try {
      if (document.fullscreenElement || document.webkitFullscreenElement) return true;
      if (el.requestFullscreen) await el.requestFullscreen();
      else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
      else if (el.msRequestFullscreen) el.msRequestFullscreen();
      return !!(document.fullscreenElement || document.webkitFullscreenElement);
    } catch (e) {
      return false;
    }
  }

  _steps() {
    const steps = [
      { key: 'identity', label: 'Identity' },
      { key: 'rules', label: 'Rules' },
      { key: 'terms', label: 'Terms' },
      { key: 'ready', label: 'Start' },
    ];
    return steps;
  }

  _render() {
    const steps = this._steps();
    const step = steps[this.step];
    this._container.innerHTML = `
      <div class="peg-page">
        <div class="peg-shell">
          <header class="peg-top">
            <div class="peg-brand">
              <img src="assets/logo.png" alt="" onerror="this.style.display='none'">
              <div>
                <div class="peg-brand-name">Gyanam Exam Portal</div>
                <div class="peg-brand-sub">Secure examination gateway</div>
              </div>
            </div>
            <button type="button" class="peg-cancel" id="peg-cancel">Exit</button>
          </header>

          <div class="peg-exam-bar">
            <div>
              <div class="peg-exam-title">${esc(this.exam.title)}</div>
              <div class="peg-exam-meta">
                ${esc(this.exam.subject || '—')} · ${this.exam.duration} min · ${this.exam.total_questions} questions
                ${this.isProctored ? '<span class="peg-pill peg-pill-red">Proctored</span>' : '<span class="peg-pill peg-pill-green">Normal</span>'}
              </div>
            </div>
          </div>

          <nav class="peg-steps" aria-label="Pre-exam steps">
            ${steps.map((s, i) => `
              <div class="peg-step ${i === this.step ? 'is-active' : ''} ${i < this.step ? 'is-done' : ''}">
                <span class="peg-step-num">${i < this.step ? '✓' : i + 1}</span>
                <span class="peg-step-label">${s.label}</span>
              </div>`).join('<div class="peg-step-line"></div>')}
          </nav>

          <div class="peg-card" id="peg-card">
            ${this._stepHTML(step.key)}
          </div>

          <div class="peg-footer">
            <button type="button" class="peg-btn peg-btn-ghost" id="peg-back" ${this.step === 0 ? 'disabled' : ''}>Back</button>
            <button type="button" class="peg-btn peg-btn-primary" id="peg-next">
              ${this.step === steps.length - 1 ? (this.enforceFullscreen ? 'Enter fullscreen & start' : 'Start exam') : 'Continue'}
            </button>
          </div>
          <p class="peg-footnote" id="peg-err" hidden></p>
        </div>
      </div>`;

    document.getElementById('peg-cancel')?.addEventListener('click', () => {
      this.destroy();
      if (typeof this.onCancel === 'function') this.onCancel();
      this._reject?.(new Error('Cancelled'));
    });
    document.getElementById('peg-back')?.addEventListener('click', () => {
      if (this.step > 0) { this.step -= 1; this._render(); }
    });
    document.getElementById('peg-next')?.addEventListener('click', () => this._onNext());

    this._bindStep(step.key);
  }

  _setError(msg) {
    const el = document.getElementById('peg-err');
    if (!el) return;
    if (!msg) { el.hidden = true; el.textContent = ''; return; }
    el.hidden = false;
    el.textContent = msg;
  }

  _bindStep(key) {
    if (key === 'identity') {
      document.getElementById('peg-confirm-identity')?.addEventListener('change', (e) => {
        this.identityConfirmed = e.target.checked;
      });
      if (this.needsCamera) {
        this._ensureCamera().then(stream => {
          this._attachLivePreview(stream);
        }).catch(err => this._setError(err.message));

        document.getElementById('peg-capture-btn')?.addEventListener('click', () => this._capturePhoto());
        document.getElementById('peg-retake-btn')?.addEventListener('click', () => this._retakePhoto());
      }
    }
    if (key === 'terms') {
      document.getElementById('peg-accept-terms')?.addEventListener('change', (e) => {
        this.termsAccepted = e.target.checked;
      });
    }
  }

  _attachLivePreview(stream) {
    const video = document.getElementById('peg-camera');
    if (video && stream) {
      video.srcObject = stream;
      video.play().catch(() => {});
    }
  }

  _capturePhoto() {
    this._setError('');
    const video = document.getElementById('peg-camera');
    if (!video || !this._cameraStream) {
      this._setError('Camera is not ready yet. Please wait a moment and try again.');
      return;
    }
    if (!video.videoWidth || !video.videoHeight) {
      this._setError('Camera feed is still loading. Wait a second, then capture again.');
      return;
    }

    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    const ctx = canvas.getContext('2d');
    // Mirror to match preview
    ctx.translate(canvas.width, 0);
    ctx.scale(-1, 1);
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

    try {
      this.capturedPhoto = canvas.toDataURL('image/jpeg', 0.92);
    } catch (e) {
      this._setError('Could not capture photo. Please try again.');
      return;
    }

    // Re-render identity step to show captured still + retake
    this._render();
  }

  _retakePhoto() {
    this.capturedPhoto = null;
    this._render();
  }

  async _onNext() {
    this._setError('');
    const steps = this._steps();
    const key = steps[this.step].key;

    if (key === 'identity') {
      if (this.needsCamera && !this.capturedPhoto) {
        this._setError('Please capture your photo before continuing.');
        return;
      }
      if (!this.identityConfirmed) {
        this._setError('Please confirm that your details are correct before continuing.');
        return;
      }
      if (this.needsCamera && !this._cameraStream) {
        try { await this._ensureCamera(); }
        catch (e) { this._setError(e.message); return; }
      }
      if (this.capturedPhoto) {
        try { sessionStorage.setItem('gyanam_exam_photo', this.capturedPhoto); }
        catch (_) { /* ignore quota */ }
      }
    }

    if (key === 'terms' && !this.termsAccepted) {
      this._setError('You must accept the terms and conditions to proceed.');
      return;
    }

    if (this.step < steps.length - 1) {
      this.step += 1;
      this._render();
      return;
    }

    // Final start — user gesture: fullscreen + hand off camera stream
    const btn = document.getElementById('peg-next');
    if (btn) { btn.disabled = true; btn.textContent = 'Starting…'; }

    let enteredFullscreen = false;
    if (this.enforceFullscreen) {
      enteredFullscreen = await this._requestFullscreen();
      if (!enteredFullscreen) {
        this._setError('Fullscreen is required. Allow fullscreen when prompted, then try again.');
        if (btn) {
          btn.disabled = false;
          btn.textContent = 'Enter fullscreen & start';
        }
        return;
      }
    }

    // Keep camera alive for ExamPage if needed — transfer ownership
    const stream = this._cameraStream;
    this._cameraStream = null; // don't stop on destroy

    this._resolve?.({
      examMeta: this.exam,
      enteredFullscreen,
      cameraStream: stream,
      capturedPhoto: this.capturedPhoto || null,
    });
  }

  _stepHTML(key) {
    if (key === 'identity') return this._identityHTML();
    if (key === 'rules') return this._rulesHTML();
    if (key === 'terms') return this._termsHTML();
    return this._readyHTML();
  }

  _identityHTML() {
    const u = this.user;
    const initial = (u.name || u.identifier || 'S').charAt(0).toUpperCase();
    const slot = SLOT_LABELS[u.exam_slot] || u.exam_slot || '—';
    const window = WINDOW_LABELS[u.time_window] || u.time_window || '—';

    return `
      <h2 class="peg-h2">Confirm candidate identity</h2>
      <p class="peg-lead">Verify your details before the exam begins. Report any mismatch to your centre immediately.</p>

      <div class="peg-identity">
        <div class="peg-photo-col">
          ${this.needsCamera ? `
            <div class="peg-camera-wrap ${this.capturedPhoto ? 'is-captured' : ''}">
              ${this.capturedPhoto ? `
                <img id="peg-photo" class="peg-photo" src="${this.capturedPhoto}" alt="Captured candidate photo">
                <div class="peg-camera-tag peg-camera-tag-ok">Photo captured</div>
              ` : `
                <video id="peg-camera" class="peg-camera" autoplay playsinline muted></video>
                <div class="peg-camera-tag">Live camera</div>
              `}
            </div>
            <div class="peg-photo-actions">
              ${this.capturedPhoto ? `
                <button type="button" class="peg-btn peg-btn-ghost peg-btn-sm" id="peg-retake-btn">Retake photo</button>
              ` : `
                <button type="button" class="peg-btn peg-btn-primary peg-btn-sm" id="peg-capture-btn">Capture photo</button>
              `}
            </div>
            <p class="peg-hint">${this.capturedPhoto
              ? 'Photo saved for this exam session. Retake if needed.'
              : 'Centre your face in the frame, then capture a clear photo to continue.'}</p>
          ` : `
            <div class="peg-avatar">${esc(initial)}</div>
            <p class="peg-hint">Photo capture is not required for this exam.</p>
          `}
        </div>
        <div class="peg-info-grid">
          <div class="peg-info"><span>Full name</span><strong>${esc(u.name || '—')}</strong></div>
          <div class="peg-info"><span>Student ID</span><strong>${esc(u.identifier || '—')}</strong></div>
          <div class="peg-info"><span>Centre</span><strong>${esc(u.centre_name || '—')}</strong></div>
          <div class="peg-info"><span>Exam slot</span><strong>${esc(slot)}</strong></div>
          <div class="peg-info"><span>Time window</span><strong>${esc(window)}</strong></div>
          <div class="peg-info"><span>Exam</span><strong>${esc(this.exam.title)}</strong></div>
        </div>
      </div>

      <label class="peg-check">
        <input type="checkbox" id="peg-confirm-identity" ${this.identityConfirmed ? 'checked' : ''}>
        <span>I confirm that the candidate details above are correct and that I am the registered examinee.</span>
      </label>`;
  }

  _rulesHTML() {
    const s = this.settings;
    const limit = s.tab_switch_limit ?? 3;
    const items = [];

    if (this.isProctored) {
      items.push(['Fullscreen', this.enforceFullscreen
        ? 'You must stay in fullscreen for the entire exam. Leaving fullscreen will pause progress until you return.'
        : 'Fullscreen may be requested. Stay focused on the exam window.']);
      items.push(['Tab / window switching', `Leaving the exam tab or window is recorded. After ${limit} warning(s), the exam is auto-submitted.`]);
      if (s.copy_paste_block !== false) items.push(['Copy & paste', 'Copy, cut, and paste are blocked during the exam.']);
      if (s.right_click_block !== false) items.push(['Right-click', 'Context menu / right-click is disabled.']);
      if (s.text_select_block !== false) items.push(['Text selection', 'Selecting question text may be blocked.']);
      if (s.devtools_detect !== false) items.push(['Developer tools', 'Opening browser developer tools is flagged as a violation.']);
      if (s.camera) items.push(['Camera', 'Your webcam may remain on for local monitoring. Nothing is uploaded to the server from this check.']);
      if (s.microphone) items.push(['Microphone', 'Microphone access may be requested for this session.']);
    } else {
      items.push(['Focus', 'Complete the exam in one sitting without interruption.']);
      items.push(['Integrity', 'Do not share questions or answers. Academic misconduct may void your attempt.']);
    }

    items.push(['Timer', `You have ${this.exam.duration} minutes. The exam will auto-submit when time ends.`]);
    items.push(['Passing score', `You need at least ${this.exam.passing_score || 40}% to pass.`]);

    return `
      <h2 class="peg-h2">${this.isProctored ? 'Proctoring rules & warnings' : 'Exam rules'}</h2>
      <p class="peg-lead">Read carefully. Violations may result in warnings or automatic submission.</p>
      <ul class="peg-rules">
        ${items.map(([t, d]) => `<li><strong>${esc(t)}</strong><span>${esc(d)}</span></li>`).join('')}
      </ul>
      ${this.isProctored ? `
        <div class="peg-alert">
          <strong>Important:</strong> Auto-submit cannot be undone. Stay in the exam window and avoid switching tabs.
        </div>` : ''}`;
  }

  _termsHTML() {
    const custom = (this.exam.instructions || '').trim();
    return `
      <h2 class="peg-h2">Terms &amp; conditions</h2>
      <p class="peg-lead">By starting this exam you agree to the following.</p>
      <div class="peg-terms">
        <ol>
          <li>I am the registered candidate named on the previous screen and will not allow anyone else to take this exam on my behalf.</li>
          <li>I will not use unauthorized materials, devices, or assistance during the examination.</li>
          <li>I understand that proctoring signals (tab switches, fullscreen exit, etc.) may be logged and reviewed.</li>
          <li>I accept that exceeding allowed warnings may cause automatic submission of my answers.</li>
          <li>I will not copy, photograph, or distribute exam content in any form.</li>
          <li>Technical issues should be reported to my centre immediately; incomplete attempts may still be recorded.</li>
        </ol>
        ${custom ? `
          <div class="peg-instructions">
            <div class="peg-instructions-label">Exam-specific instructions</div>
            <div class="peg-instructions-body">${esc(custom).replace(/\n/g, '<br>')}</div>
          </div>` : ''}
      </div>
      <label class="peg-check">
        <input type="checkbox" id="peg-accept-terms" ${this.termsAccepted ? 'checked' : ''}>
        <span>I have read and agree to the terms, conditions, and exam instructions above.</span>
      </label>`;
  }

  _readyHTML() {
    return `
      <h2 class="peg-h2">Ready to begin</h2>
      <p class="peg-lead">Once you start, the exam timer begins and your attempt is recorded.</p>
      <div class="peg-ready-grid">
        <div class="peg-ready-item"><span>Duration</span><strong>${this.exam.duration} minutes</strong></div>
        <div class="peg-ready-item"><span>Questions</span><strong>${this.exam.total_questions}</strong></div>
        <div class="peg-ready-item"><span>Passing score</span><strong>${this.exam.passing_score || 40}%</strong></div>
        <div class="peg-ready-item"><span>Mode</span><strong>${this.isProctored ? 'Proctored' : 'Normal'}</strong></div>
      </div>
      <ul class="peg-checklist">
        <li>Close other tabs and applications</li>
        <li>Ensure a stable internet connection</li>
        ${this.enforceFullscreen ? '<li>You will be asked to enter fullscreen</li>' : ''}
        ${this.needsCamera ? '<li>Keep your camera unobstructed</li>' : ''}
        <li>Do not refresh or close the browser during the exam</li>
      </ul>
      <div class="peg-alert peg-alert-blue">
        Click <strong>${this.enforceFullscreen ? 'Enter fullscreen & start' : 'Start exam'}</strong> only when you are ready. Good luck.
      </div>`;
  }
}

export default PreExamGate;
