/**
 * DashboardModule.js — Admin dashboard with centre breakdown.
 */
export async function renderDashboard(ApiClient, { currentUser, loadPage }) {
    const data = await ApiClient.getDashboardStats();
    const recent = data.recent;
    const stats = data.stats;
    const counts = data.counts;
    const liveCount = counts.live_now;
    const centreBreakdown = data.centre_breakdown || [];
    const scopeLabel = currentUser.centre_id
        ? `Centre: <strong>${currentUser.centre_id}</strong>`
        : '<strong>All Centres</strong>';

    document.getElementById('page-content').innerHTML = `
  <div class="page-header"><div><h2>Overview</h2><p>Showing data for ${scopeLabel} &nbsp;&middot;&nbsp; ${new Date().toLocaleString('en-IN')}</p></div></div>

  <div class="stats-grid" style="margin-bottom:1.5rem">
    <div class="stat-card stat-card-blue">
      <div class="stat-label">Total Submissions</div>
      <div class="stat-value">${stats.total}</div>
      <div class="stat-sub">${currentUser.centre_id ? currentUser.centre_id : 'All centres'} · All time</div>
    </div>
    <div class="stat-card stat-card-green">
      <div class="stat-label">Passed</div>
      <div class="stat-value">${stats.passed}</div>
      <div class="stat-sub">${stats.total ? Math.round(stats.passed / stats.total * 100) : 0}% pass rate</div>
    </div>
    <div class="stat-card stat-card-red">
      <div class="stat-label">Failed</div>
      <div class="stat-value">${stats.failed}</div>
    </div>
    <div class="stat-card stat-card-yellow">
      <div class="stat-label">Avg Score</div>
      <div class="stat-value">${stats.avg}%</div>
    </div>
    <div class="stat-card stat-card-blue">
      <div class="stat-label">Students</div>
      <div class="stat-value">${counts.students}</div>
      <div class="stat-sub">Registered</div>
    </div>
    <div class="stat-card stat-card-green">
      <div class="stat-label">Live Now</div>
      <div class="stat-value">${counts.live_now}</div>
      <div class="stat-sub"><span class="live-dot"></span> Active exam sessions</div>
    </div>
    <div class="stat-card stat-card-blue">
      <div class="stat-label">Question Banks</div>
      <div class="stat-value">${counts.question_banks}</div>
    </div>
    <div class="stat-card stat-card-blue">
      <div class="stat-label">Exam Configs</div>
      <div class="stat-value">${counts.exam_configs}</div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem">
    <div class="card">
      <div class="card-header"><h3>Recent Submissions</h3></div>
      <div class="table-wrap">
        ${recent.length === 0
          ? '<div class="card-body" style="color:var(--text-muted);font-size:0.875rem">No submissions yet.</div>'
          : `<table>
              <thead><tr><th>Student</th><th>Exam</th><th>Score</th><th>Result</th><th>Time</th></tr></thead>
              <tbody>
                ${recent.map(s => `
                <tr>
                  <td style="font-weight:600">${s.student_name}</td>
                  <td style="font-size:0.8rem;color:var(--text-muted)">${s.exam_title}</td>
                  <td><strong>${s.score}%</strong></td>
                  <td><span class="badge ${s.result === 'pass' ? 'badge-green' : 'badge-red'}">${s.result || '—'}</span></td>
                  <td style="font-size:0.78rem;color:var(--text-muted)">${new Date(s.submitted_at).toLocaleTimeString('en-IN')}</td>
                </tr>`).join('')}
              </tbody>
            </table>`}
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h3>Live Sessions</h3><span class="badge badge-green">${liveCount} active</span></div>
      <div class="card-body">
        <div style="font-size:0.875rem;color:var(--text-muted);padding:1rem;text-align:center">
          Currently <strong>${liveCount}</strong> students are appearing for exams.
          <br><br>
          <button class="btn btn-outline btn-sm" onclick="loadPage('live')">View Live Monitor</button>
        </div>
      </div>
    </div>
  </div>

  ${!currentUser.centre_id && centreBreakdown.length > 0 ? `
  <div class="card">
    <div class="card-header">
      <h3>Centre Performance Breakdown</h3>
      <span class="badge badge-gray">${centreBreakdown.length} centre(s)</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Centre</th><th>Students</th><th>Submissions</th>
            <th>Pass Rate</th><th>Avg Score</th><th>Status</th>
          </tr>
        </thead>
        <tbody>
          ${centreBreakdown.map(c => {
            const pr = c.pass_rate;
            const barColor = pr >= 70 ? '#22c55e' : pr >= 50 ? '#f59e0b' : '#ef4444';
            return `<tr>
              <td style="font-weight:600">${c.centre_name || '—'}</td>
              <td>${c.student_count}</td>
              <td>${c.submissions}</td>
              <td>
                <div style="display:flex;align-items:center;gap:0.5rem">
                  <div style="flex:1;background:var(--gray-200);border-radius:999px;height:6px;min-width:60px">
                    <div style="width:${pr}%;background:${barColor};height:6px;border-radius:999px"></div>
                  </div>
                  <span style="font-size:0.8rem;font-weight:600;color:${barColor}">${pr}%</span>
                </div>
              </td>
              <td><strong>${c.avg_score}%</strong></td>
              <td>
                <span class="badge ${pr >= 70 ? 'badge-green' : pr >= 50 ? 'badge-yellow' : 'badge-red'}">
                  ${pr >= 70 ? 'Good' : pr >= 50 ? 'Average' : 'Needs Attention'}
                </span>
              </td>
            </tr>`;
          }).join('')}
        </tbody>
      </table>
    </div>
  </div>` : ''}
`;
    window.loadPage = loadPage;
}
