/**
 * DashboardModule.js — Admin dashboard with centre breakdown.
 */
export async function renderDashboard(ApiClient, { currentUser, loadPage }) {
  const data = await ApiClient.getDashboardStats();
  const recent = data.recent || [];
  const stats = data.stats || {};
  const counts = data.counts || {};
  const liveCount = counts.live_now || 0;
  const centreBreakdown = data.centre_breakdown || [];
  const passRate = stats.total ? Math.round((stats.passed / stats.total) * 100) : 0;
  const isScoped = !!currentUser.centre_id;
  const nowLabel = new Date().toLocaleString('en-IN', {
    day: 'numeric', month: 'short', year: 'numeric',
    hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true,
  });

  const kpi = (opts) => `
    <div class="dash-kpi dash-kpi-${opts.tone || 'blue'}">
      <div class="dash-kpi-top">
        <span class="dash-kpi-label">${opts.label}</span>
        <span class="dash-kpi-icon" aria-hidden="true">${opts.icon || ''}</span>
      </div>
      <div class="dash-kpi-value">${opts.value}</div>
      ${opts.sub ? `<div class="dash-kpi-sub">${opts.sub}</div>` : ''}
    </div>`;

  document.getElementById('page-content').innerHTML = `
  <div class="dash-page">
    <div class="page-header dash-page-header">
      <div>
        <h2>Dashboard</h2>
        <p class="dash-meta">
          ${isScoped
            ? `<span class="dash-chip dash-chip-blue">${currentUser.centre_id}</span>`
            : `<span class="dash-chip">Organisation-wide</span>`}
          <span class="dash-meta-sep">·</span>
          <span>Updated ${nowLabel}</span>
        </p>
      </div>
      <div class="dash-page-actions">
        <button type="button" class="btn btn-outline btn-sm" onclick="loadPage('dashboard')" title="Refresh">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182"/></svg>
          Refresh
        </button>
        <button type="button" class="btn btn-primary btn-sm" onclick="loadPage('live')">Live Monitor</button>
      </div>
    </div>

    <div class="dash-kpi-grid">
      ${kpi({ label: 'Submissions', value: stats.total ?? 0, sub: 'All time', tone: 'blue', icon: '📄' })}
      ${kpi({ label: 'Passed', value: stats.passed ?? 0, sub: `${passRate}% pass rate`, tone: 'green', icon: '✓' })}
      ${kpi({ label: 'Failed', value: stats.failed ?? 0, sub: stats.total ? `${100 - passRate}% fail rate` : '—', tone: 'red', icon: '✕' })}
      ${kpi({ label: 'Avg Score', value: `${stats.avg ?? 0}%`, tone: 'amber', icon: '◎' })}
      ${kpi({ label: 'Students', value: counts.students ?? 0, sub: 'Registered', tone: 'blue', icon: '👥' })}
      ${kpi({ label: 'Live Now', value: liveCount, sub: `<span class="live-dot"></span> Active sessions`, tone: 'green', icon: '●' })}
      ${kpi({ label: 'Question Banks', value: counts.question_banks ?? 0, tone: 'slate', icon: '📚' })}
      ${kpi({ label: 'Exam Configs', value: counts.exam_configs ?? 0, tone: 'slate', icon: '⚙' })}
    </div>

    <div class="dash-mid-grid">
      <div class="card dash-panel">
        <div class="card-header dash-panel-head">
          <div>
            <h3>Recent Submissions</h3>
            <p class="dash-panel-sub">Latest exam attempts</p>
          </div>
          <button type="button" class="btn btn-ghost btn-sm" onclick="loadPage('results')">View all</button>
        </div>
        ${recent.length === 0
          ? `<div class="dash-empty">
               <div class="dash-empty-icon">📭</div>
               <div class="dash-empty-title">No submissions yet</div>
               <div class="dash-empty-text">Results will appear here once students complete exams.</div>
             </div>`
          : `<div class="table-wrap dash-table-wrap">
              <table class="dash-table">
                <thead>
                  <tr><th>Student</th><th>Exam</th><th>Score</th><th>Result</th><th>Time</th></tr>
                </thead>
                <tbody>
                  ${recent.map(s => `
                  <tr>
                    <td><div class="dash-strong">${s.student_name || '—'}</div></td>
                    <td><div class="dash-muted">${s.exam_title || '—'}</div></td>
                    <td><span class="dash-score">${s.score ?? '—'}%</span></td>
                    <td><span class="badge ${s.result === 'pass' ? 'badge-green' : 'badge-red'}">${s.result || '—'}</span></td>
                    <td class="dash-muted">${s.submitted_at ? new Date(s.submitted_at).toLocaleString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true }) : '—'}</td>
                  </tr>`).join('')}
                </tbody>
              </table>
            </div>`}
      </div>

      <div class="card dash-panel">
        <div class="card-header dash-panel-head">
          <div>
            <h3>Live Sessions</h3>
            <p class="dash-panel-sub">Students currently in exam</p>
          </div>
          <span class="badge ${liveCount > 0 ? 'badge-green' : 'badge-gray'}">${liveCount} active</span>
        </div>
        <div class="dash-live-body">
          <div class="dash-live-count ${liveCount > 0 ? 'is-live' : ''}">${liveCount}</div>
          <div class="dash-live-label">${liveCount === 1 ? 'student appearing now' : 'students appearing now'}</div>
          <button type="button" class="btn ${liveCount > 0 ? 'btn-primary' : 'btn-outline'} btn-sm" onclick="loadPage('live')">
            Open Live Monitor
          </button>
        </div>
        <div class="dash-quick-links">
          <button type="button" class="dash-link" onclick="loadPage('questions')">Question Banks</button>
          <button type="button" class="dash-link" onclick="loadPage('exams')">Exam Configs</button>
          <button type="button" class="dash-link" onclick="loadPage('students')">Students</button>
        </div>
      </div>
    </div>

    ${!isScoped ? `
    <div class="card dash-panel">
      <div class="card-header dash-panel-head">
        <div>
          <h3>Centre Performance</h3>
          <p class="dash-panel-sub">Breakdown across ATC centres</p>
        </div>
        <span class="badge badge-gray">${centreBreakdown.length} centre${centreBreakdown.length === 1 ? '' : 's'}</span>
      </div>
      ${centreBreakdown.length === 0
        ? `<div class="dash-empty">
             <div class="dash-empty-icon">🏛</div>
             <div class="dash-empty-title">No centre data yet</div>
             <div class="dash-empty-text">Centre stats appear after students are registered and exams are taken.</div>
           </div>`
        : `<div class="table-wrap dash-table-wrap">
            <table class="dash-table">
              <thead>
                <tr>
                  <th>Centre</th>
                  <th>Students</th>
                  <th>Submissions</th>
                  <th>Pass Rate</th>
                  <th>Avg Score</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                ${centreBreakdown.map(c => {
                  const pr = Number(c.pass_rate) || 0;
                  const tone = pr >= 70 ? 'good' : pr >= 50 ? 'avg' : 'bad';
                  const barColor = tone === 'good' ? '#16a34a' : tone === 'avg' ? '#d97706' : '#dc2626';
                  const label = tone === 'good' ? 'Good' : tone === 'avg' ? 'Average' : 'Needs attention';
                  const badge = tone === 'good' ? 'badge-green' : tone === 'avg' ? 'badge-yellow' : 'badge-red';
                  return `<tr>
                    <td><div class="dash-strong">${c.centre_name || '—'}</div></td>
                    <td>${c.student_count ?? 0}</td>
                    <td>${c.submissions ?? 0}</td>
                    <td>
                      <div class="dash-passbar">
                        <div class="dash-passbar-track"><div class="dash-passbar-fill" style="width:${Math.min(100, pr)}%;background:${barColor}"></div></div>
                        <span style="color:${barColor}">${pr}%</span>
                      </div>
                    </td>
                    <td><span class="dash-score">${c.avg_score ?? 0}%</span></td>
                    <td><span class="badge ${badge}">${label}</span></td>
                  </tr>`;
                }).join('')}
              </tbody>
            </table>
          </div>`}
    </div>` : ''}
  </div>`;

  window.loadPage = loadPage;
}
