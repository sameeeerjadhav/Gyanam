export async function renderLive(ApiClient, { getScopedLive }) {
  const el = document.getElementById('page-content');
  let _refreshRunning = false;
  let _stopped = false;

  const controller = {
    stop: () => {
      _stopped = true;
    }
  };

  async function refresh() {
    if (_stopped || _refreshRunning) return;
    _refreshRunning = true;
    try {
      const oneHourAgo = new Date(Date.now() - 3600 * 1000).toISOString();
      const [live, resultData] = await Promise.all([
        getScopedLive(),
        ApiClient.getResults({ since: oneHourAgo })
      ]);
      const subs = resultData?.submissions || [];

      if (_stopped) return;

      el.innerHTML = `
        <div class="page-header">
          <div><h2>Live Monitoring</h2><p>Auto-refreshes every 10 seconds</p></div>
          <button class="btn btn-outline btn-sm" id="live-refresh-btn">↻ Refresh Now</button>
        </div>
        <div class="stats-grid" style="margin-bottom:1.5rem">
          <div class="stat-card stat-card-green"><div class="stat-label">Active Sessions</div><div class="stat-value">${live.length}</div></div>
          <div class="stat-card stat-card-blue"><div class="stat-label">Submissions (1h)</div><div class="stat-value">${subs.length}</div></div>
          <div class="stat-card stat-card-green"><div class="stat-label">Passed (1h)</div><div class="stat-value">${subs.filter(s => s.result === 'pass').length}</div></div>
          <div class="stat-card stat-card-red"><div class="stat-label">Failed (1h)</div><div class="stat-value">${subs.filter(s => s.result === 'fail').length}</div></div>
        </div>

        <div class="card" style="margin-bottom:1.25rem">
          <div class="card-header"><h3><span class="live-dot"></span> Currently Appearing</h3><span class="badge badge-green">${live.length} students</span></div>
          ${live.length === 0 ? '<div class="card-body" style="color:var(--text-muted);font-size:0.875rem">No active exam sessions at the moment.</div>' : `
          <div class="table-wrap"><table>
            <thead><tr><th>Student</th><th>Exam</th><th>Started At</th><th>Last Seen</th><th>Duration</th><th>Actions</th></tr></thead>
            <tbody>
              ${live.map(s => {
        const dur = Math.round((Date.now() - new Date(s.startedAt).getTime()) / 60000);
        return `<tr>
                  <td style="font-weight:600">${s.studentName || '—'}</td>
                  <td style="font-size:0.82rem;color:var(--text-muted)">${s.examTitle || s.examId || '—'}</td>
                  <td style="font-size:0.8rem">${new Date(s.startedAt).toLocaleTimeString('en-IN')}</td>
                  <td style="font-size:0.8rem">${new Date(s.lastSeen).toLocaleTimeString('en-IN')}</td>
                  <td><span class="badge badge-blue">${dur} min</span></td>
                  <td>
                    <button class="btn btn-outline btn-sm" onclick="addStudentTime('${s.studentId}','${s.examId}','${s.studentName || 'Student'}')" title="Extend time">
                      ⏱ +Time
                    </button>
                  </td>
                </tr>`;
      }).join('')}
            </tbody>
          </table></div>`}
        </div>

        <div class="card">
          <div class="card-header"><h3>Recent Submissions (1h)</h3></div>
          ${subs.length === 0 ? '<div class="card-body" style="color:var(--text-muted);font-size:0.875rem">No recent submissions in the last hour.</div>' : `
          <div class="table-wrap"><table>
            <thead><tr><th>Student</th><th>Exam</th><th>Score</th><th>Correct</th><th>Total</th><th>Result</th><th>Submitted</th></tr></thead>
            <tbody>
              ${subs.map(s => `<tr>
                <td style="font-weight:600">${s.student?.name || s.student_name || s.student_id || '—'}</td>
                <td style="font-size:0.82rem;color:var(--text-muted)">${s.exam?.title || s.exam_title || s.exam_id || '—'}</td>
                <td><strong>${s.score ?? '—'}%</strong></td>
                <td>${s.correct_answers ?? '—'}</td>
                <td>${s.total_questions ?? '—'}</td>
                <td><span class="badge ${s.result === 'pass' ? 'badge-green' : 'badge-red'}">${s.result || '—'}</span></td>
                <td style="font-size:0.78rem;color:var(--text-muted)">${new Date(s.submitted_at).toLocaleTimeString('en-IN')}</td>
              </tr>`).join('')}
            </tbody>
          </table></div>`}
        </div>
      `;

      const btn = document.getElementById('live-refresh-btn');
      if (btn) btn.addEventListener('click', () => refresh());

    } catch (e) {
      console.error('Live monitoring refresh failed', e);
      if (!_stopped) {
        el.innerHTML = `<div style="padding:2rem;text-align:center;color:var(--error)">
          <p>Live monitoring failed to load data.</p>
          <p style="font-size:0.8rem;color:var(--gray-400)">${e.message}</p>
          <button class="btn btn-outline" onclick="loadPage('live')">Retry</button>
        </div>`;
      }
    } finally {
      _refreshRunning = false;
    }

    if (!_stopped) setTimeout(refresh, 10000);
  }

  // ── Time Extension ─────────────────────────────────────
  window.addStudentTime = async (studentId, examId, studentName) => {
    const input = prompt(`Add extra minutes for ${studentName}:\n(Enter 1–60 minutes)`, '5');
    if (!input) return;
    const mins = parseInt(input);
    if (isNaN(mins) || mins < 1 || mins > 60) {
      alert('Please enter a valid number between 1 and 60.');
      return;
    }
    try {
      const res = await ApiClient.extendTime(studentId, examId, mins);
      const msg = document.createElement('div');
      msg.textContent = res.message || `+${mins} minutes added.`;
      msg.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;background:#22c55e;color:#fff;padding:0.75rem 1.25rem;border-radius:10px;font-weight:600;z-index:9999;box-shadow:0 4px 16px rgba(34,197,94,0.3)';
      document.body.appendChild(msg);
      setTimeout(() => msg.remove(), 3500);
      // Refresh to update display
      setTimeout(refresh, 500);
    } catch (e) {
      alert('Failed: ' + e.message);
    }
  };

  refresh();
  return controller;
}
