/**
 * ExamHistoryModule - Displays student's past exam attempts in a table.
 * Features:
 * - Fetches and displays exam history from API
 * - Shows exam status, score, result in a clean table
 * - "View Details" button opens a modal with a full score breakdown
 */

import { APIClient } from '../services/APIClient.js';

export class ExamHistoryModule {
  constructor(apiClient = null) {
    this.examHistory = [];
    this.container = null;
  }

  async initialize(container, studentId) {
    this.container = container;
    try {
      this._renderLoading();
      const ApiClient = (await import('../services/APIClient.js')).default;
      const raw = await ApiClient.getMyHistory().catch(() => []);
      this.examHistory = Array.isArray(raw) ? raw : [];
      this.render();
    } catch (error) {
      this._renderError(error);
    }
  }

  render() {
    if (!this.container) return;

    if (this.examHistory.length === 0) {
      this.container.innerHTML = `
        <div class="dash-section-header" style="margin-bottom:1rem">
          <h2 class="dash-section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:22px;height:22px;color:#1d4ed8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z"/></svg>
            Previous Exams
          </h2>
        </div>
        <div class="card" style="text-align:center;padding:3rem 1.5rem;color:var(--text-muted)">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:48px;height:48px;margin:0 auto 1rem"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z"/></svg>
          <p style="font-size:0.95rem">No exam attempts yet. Complete an exam to see your results here.</p>
        </div>
      `;
      return;
    }

    const rows = this.examHistory.map((sub, idx) => {
      const passed = sub.result === 'pass';
      const resultBadge = passed
        ? `<span class="badge badge-green">✓ PASS</span>`
        : `<span class="badge badge-red">✗ FAIL</span>`;
      const scoreColor = passed ? 'var(--success-text)' : 'var(--danger-text)';
      const date = sub.submitted_at
        ? new Date(sub.submitted_at).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
        : '—';
      const time = sub.submitted_at
        ? new Date(sub.submitted_at).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })
        : '';

      return `
        <tr>
          <td style="font-weight:600;color:var(--text-primary)">${sub.exam_title || 'Exam'}</td>
          <td style="color:var(--text-secondary);font-size:0.82rem">${date}<br><span style="color:var(--text-muted)">${time}</span></td>
          <td style="text-align:center">${sub.correct_answers ?? '—'} / ${sub.total_questions ?? '—'}</td>
          <td style="text-align:center;font-weight:700;font-size:1rem;color:${scoreColor}">${sub.score ?? '—'}%</td>
          <td style="text-align:center">${resultBadge}</td>
          <td style="text-align:center">
            <button class="btn btn-outline btn-sm view-result-btn" data-idx="${idx}" style="gap:0.3rem">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
              View
            </button>
          </td>
        </tr>`;
    }).join('');

    this.container.innerHTML = `
      <div class="dash-section-header" style="margin-bottom:1rem">
        <h2 class="dash-section-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:22px;height:22px;color:#1d4ed8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z"/></svg>
          Previous Exams
        </h2>
        <span class="dash-badge">${this.examHistory.length} attempt${this.examHistory.length !== 1 ? 's' : ''}</span>
      </div>
      <div class="card" style="overflow:hidden">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Exam Name</th>
                <th>Date &amp; Time</th>
                <th style="text-align:center">Correct / Total</th>
                <th style="text-align:center">Score</th>
                <th style="text-align:center">Result</th>
                <th style="text-align:center">Details</th>
              </tr>
            </thead>
            <tbody>
              ${rows}
            </tbody>
          </table>
        </div>
      </div>
    `;

    // Attach view-result button listeners
    this.container.querySelectorAll('.view-result-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = parseInt(btn.dataset.idx, 10);
        this._showResultModal(this.examHistory[idx]);
      });
    });
  }

  _showResultModal(sub) {
    // Remove any existing modal
    let overlay = document.getElementById('history-result-overlay');
    if (overlay) overlay.remove();

    const passed = sub.result === 'pass';
    const scoreColor = passed ? '#16a34a' : '#dc2626';
    const scoreBg = passed ? '#f0fdf4' : '#fef2f2';
    const resultLabel = passed ? '✓ PASSED' : '✗ FAILED';

    const correct = sub.correct_answers ?? 0;
    const total = sub.total_questions ?? 0;
    const wrong = total - correct;
    const score = sub.score ?? 0;
    const date = sub.submitted_at
      ? new Date(sub.submitted_at).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' })
      : '—';

    overlay = document.createElement('div');
    overlay.id = 'history-result-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;padding:1rem';
    overlay.innerHTML = `
      <div style="background:white;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,0.2);width:100%;max-width:480px;overflow:hidden;animation:fadeInUp 0.2s ease">
        <!-- Header -->
        <div style="background:${scoreBg};padding:1.5rem;text-align:center;border-bottom:1px solid ${passed ? '#bbf7d0' : '#fecaca'}">
          <div style="font-size:2.5rem;margin-bottom:0.5rem">${passed ? '🏆' : '📋'}</div>
          <h3 style="font-size:1.1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.25rem">${sub.exam_title || 'Exam Result'}</h3>
          <div style="font-size:0.8rem;color:var(--text-muted)">${date}</div>
          <div style="margin-top:0.75rem">
            <span style="display:inline-block;padding:0.375rem 1rem;border-radius:100px;background:white;color:${scoreColor};font-weight:700;font-size:0.85rem;border:2px solid ${scoreColor}">${resultLabel}</span>
          </div>
        </div>

        <!-- Score Highlight -->
        <div style="padding:1.5rem;text-align:center;border-bottom:1px solid var(--gray-100)">
          <div style="font-size:3.5rem;font-weight:800;color:${scoreColor};line-height:1">${score}%</div>
          <div style="font-size:0.85rem;color:var(--text-muted);margin-top:0.25rem">Overall Score</div>
        </div>

        <!-- Stats -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0;border-bottom:1px solid var(--gray-100)">
          <div style="padding:1.25rem;text-align:center;border-right:1px solid var(--gray-100)">
            <div style="font-size:1.75rem;font-weight:800;color:var(--text-primary)">${total}</div>
            <div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.2rem">Total Questions</div>
          </div>
          <div style="padding:1.25rem;text-align:center;border-right:1px solid var(--gray-100)">
            <div style="font-size:1.75rem;font-weight:800;color:#16a34a">${correct}</div>
            <div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.2rem">Correct</div>
          </div>
          <div style="padding:1.25rem;text-align:center">
            <div style="font-size:1.75rem;font-weight:800;color:#dc2626">${wrong}</div>
            <div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.2rem">Incorrect</div>
          </div>
        </div>

        <!-- Progress Bar -->
        <div style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--gray-100)">
          <div style="display:flex;justify-content:space-between;font-size:0.78rem;color:var(--text-muted);margin-bottom:0.4rem">
            <span>Score Progress</span>
            <span>${score}%</span>
          </div>
          <div style="background:var(--gray-100);border-radius:100px;height:10px;overflow:hidden">
            <div style="height:100%;width:${score}%;background:${scoreColor};border-radius:100px;transition:width 0.6s ease"></div>
          </div>
        </div>

        <!-- Close Button -->
        <div style="padding:1rem 1.5rem;display:flex;justify-content:flex-end">
          <button id="close-result-modal" style="background:var(--primary);color:white;border:none;padding:0.5rem 1.5rem;border-radius:8px;font-weight:600;cursor:pointer;font-size:0.875rem">
            Close
          </button>
        </div>
      </div>
    `;

    document.body.appendChild(overlay);

    // Close on button or backdrop click
    document.getElementById('close-result-modal').addEventListener('click', () => overlay.remove());
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
  }

  _renderLoading() {
    if (!this.container) return;
    this.container.innerHTML = `
      <div class="dash-section-header" style="margin-bottom:1rem">
        <h2 class="dash-section-title">Previous Exams</h2>
      </div>
      <div class="card" style="padding:2rem;text-align:center;color:var(--text-muted)">
        <div style="display:inline-block;width:32px;height:32px;border:3px solid var(--gray-200);border-top-color:var(--primary);border-radius:50%;animation:spin 0.7s linear infinite;margin-bottom:0.75rem"></div>
        <p>Loading your exam history...</p>
      </div>
    `;
  }

  _renderError(error) {
    if (!this.container) return;
    this.container.innerHTML = `
      <div class="card" style="padding:1.5rem;text-align:center;color:var(--danger-text)">
        <p>Failed to load exam history: ${error.message || 'Unknown error'}</p>
        <button onclick="location.reload()" class="btn btn-outline btn-sm" style="margin-top:0.75rem">Retry</button>
      </div>
    `;
  }

  async refresh(studentId) {
    if (!this.container) return;
    await this.initialize(this.container, studentId);
  }

  destroy() {
    const overlay = document.getElementById('history-result-overlay');
    if (overlay) overlay.remove();
    if (this.container) this.container.innerHTML = '';
    this.container = null;
    this.examHistory = [];
  }
}
