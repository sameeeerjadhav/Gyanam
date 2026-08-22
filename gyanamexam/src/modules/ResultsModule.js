/**
 * ResultsModule.js — Renders the Results & Analytics page with Chart.js charts.
 * Includes flagged questions review panel for admin/ATC.
 */
async function fetchAllResults(ApiClient) {
  const all = [];
  let page = 1;
  let lastPage = 1;
  let serverStats = null;
  do {
    const data = await ApiClient.getResults({ page, per_page: 100 });
    const batch = data?.submissions || [];
    all.push(...batch);
    if (data?.stats) serverStats = data.stats;
    lastPage = data?.pagination?.last_page || 1;
    page += 1;
  } while (page <= lastPage && page <= 200); // up to ~20k rows
  return { submissions: all, serverStats };
}

export async function renderResults(ApiClient, { currentUser }) {
  const [{ submissions: allSubmissions, serverStats }, flags] = await Promise.all([
    fetchAllResults(ApiClient),
    ApiClient.getFlags('pending').catch(() => []),
  ]);

  const centres = [...new Set(allSubmissions.map(s => s.student?.centre_name || s.centre_name).filter(Boolean))].sort();
  const el = document.getElementById('page-content');
  let currentCentre = '';
  let searchQ = '';
  let tablePage = 1;
  const PAGE_SIZE = 25;

  function calculateStats(subs) {
    // Prefer server aggregates when showing unfiltered set
    if (!currentCentre && !searchQ && serverStats) {
      return {
        total: serverStats.total ?? subs.length,
        passed: serverStats.passed ?? 0,
        failed: serverStats.failed ?? 0,
        avg: serverStats.avg ?? 0,
      };
    }
    const total = subs.length;
    const passed = subs.filter(s => s.result === 'pass').length;
    const failed = total - passed;
    const avg = total ? Math.round(subs.reduce((acc, s) => acc + s.score, 0) / total) : 0;
    return { total, passed, failed, avg };
  }

  function renderView() {
    let filtered = currentCentre
      ? allSubmissions.filter(s => (s.student?.centre_name || s.centre_name) === currentCentre)
      : allSubmissions;

    if (searchQ) {
      const q = searchQ.toLowerCase();
      filtered = filtered.filter(s => {
        const name = (s.student?.name || s.student_name || '').toLowerCase();
        const exam = (s.exam?.title || s.exam_title || '').toLowerCase();
        const centre = (s.student?.centre_name || s.centre_name || '').toLowerCase();
        return name.includes(q) || exam.includes(q) || centre.includes(q);
      });
    }

    const stats = calculateStats(filtered);
    const pages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
    if (tablePage > pages) tablePage = pages;
    const slice = filtered.slice((tablePage - 1) * PAGE_SIZE, tablePage * PAGE_SIZE);

    const flagsHTML = flags.length > 0 ? `
    <div class="card" style="margin-top:1.5rem">
      <div class="card-header">
        <h3>🚩 Flagged Questions</h3>
        <span class="badge badge-yellow">${flags.length} pending</span>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Student</th><th>Exam</th><th>Question</th><th>Reason</th><th>Date</th><th>Actions</th></tr></thead>
          <tbody>
            ${flags.map(f => `<tr id="flag-row-${f.id}">
              <td style="font-weight:600">${f.student_name || '—'}</td>
              <td style="font-size:0.82rem;color:var(--text-muted)">${f.exam_title || '—'}</td>
              <td style="font-size:0.8rem;max-width:200px;white-space:normal">${f.question_text || '—'}</td>
              <td style="font-size:0.82rem">${f.reason}</td>
              <td style="font-size:0.75rem;color:var(--text-muted)">${f.created_at ? new Date(f.created_at).toLocaleDateString('en-IN') : '—'}</td>
              <td>
                <div style="display:flex;gap:0.375rem">
                  <button class="btn btn-outline btn-sm" onclick="resolveFlag(${f.id},'reviewed')" style="color:#16a34a;border-color:#16a34a">✓ Reviewed</button>
                  <button class="btn btn-outline btn-sm" onclick="resolveFlag(${f.id},'dismissed')" style="color:#dc2626;border-color:#dc2626">✕ Dismiss</button>
                </div>
              </td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>
    </div>` : '';

    el.innerHTML = `
  <div class="page-header">
    <div><h2>Results &amp; Analytics</h2><p>Performance data refined by filters</p></div>
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center">
      <input id="results-search" type="search" class="form-input" placeholder="Search name, exam, centre…" value="${searchQ.replace(/"/g, '&quot;')}" style="width:220px;height:38px;padding:0 .75rem;border:1px solid var(--border,#e5e7eb);border-radius:8px">
      ${!currentUser.centre_id ? `
      <select id="results-centre-filter" class="form-select" style="width:200px">
        <option value="">All Centres</option>
        ${centres.map(c => `<option value="${c}" ${currentCentre === c ? 'selected' : ''}>${c}</option>`).join('')}
      </select>` : ''}
      <button onclick="exportSubmissions()" class="btn btn-outline">Export CSV</button>
    </div>
  </div>

  <div class="stats-grid" style="margin-bottom:1.5rem">
    <div class="stat-card stat-card-blue"><div class="stat-label">Total Submissions</div><div class="stat-value">${stats.total}</div></div>
    <div class="stat-card stat-card-green"><div class="stat-label">Passed</div><div class="stat-value">${stats.passed}</div><div class="stat-sub">${stats.total ? Math.round(stats.passed / stats.total * 100) : 0}% pass rate</div></div>
    <div class="stat-card stat-card-red"><div class="stat-label">Failed</div><div class="stat-value">${stats.failed}</div></div>
    <div class="stat-card stat-card-yellow"><div class="stat-label">Avg Score</div><div class="stat-value">${stats.avg}%</div></div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(300px, 1fr));gap:1.5rem;margin-bottom:1.5rem">
    <div class="card" style="padding:1.25rem">
      <h3 style="margin-bottom:1rem;font-size:1rem;color:var(--gray-700)">Pass/Fail Distribution</h3>
      <div style="height:250px"><canvas id="passFailChart"></canvas></div>
    </div>
    <div class="card" style="padding:1.25rem">
      <h3 style="margin-bottom:1rem;font-size:1rem;color:var(--gray-700)">Subject-wise Avg Score</h3>
      <div style="height:250px"><canvas id="subjectChart"></canvas></div>
    </div>
  </div>

  <div class="card" style="margin-bottom:1.5rem;padding:1.25rem">
    <h3 style="margin-bottom:1rem;font-size:1rem;color:var(--gray-700)">Performance Trends (Filtered)</h3>
    <div style="height:250px"><canvas id="trendChart"></canvas></div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Filtered Submissions (${filtered.length})</h3>
      <span style="font-size:0.75rem;color:var(--text-muted)">Page ${tablePage} / ${pages}</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Student</th><th>Exam</th><th>Score</th><th>Result</th><th>Attempted</th></tr></thead>
        <tbody>
          ${slice.length === 0 ? '<tr><td colspan="5" style="text-align:center;padding:1.5rem;color:var(--text-muted)">No matching submissions.</td></tr>' : slice.map(s => `<tr>
            <td style="font-weight:600">${s.student?.name || s.student_name || s.student_id}</td>
            <td style="color:var(--text-muted)">${s.exam?.title || s.exam_title || s.exam_id}</td>
            <td><strong>${s.score}%</strong></td>
            <td><span class="badge ${s.result === 'pass' ? 'badge-green' : 'badge-red'}">${s.result}</span></td>
            <td style="font-size:0.75rem">${new Date(s.submitted_at).toLocaleString('en-IN')}</td>
          </tr>`).join('')}
        </tbody>
      </table>
    </div>
    <div style="display:flex;gap:0.5rem;justify-content:flex-end;padding:0.75rem 1rem;border-top:1px solid var(--border,#e5e7eb)">
      <button class="btn btn-outline btn-sm" id="results-prev" ${tablePage <= 1 ? 'disabled' : ''}>Prev</button>
      <button class="btn btn-outline btn-sm" id="results-next" ${tablePage >= pages ? 'disabled' : ''}>Next</button>
    </div>
  </div>

  ${flagsHTML}`;

    renderAnalyticsCharts(filtered);

    const searchEl = document.getElementById('results-search');
    if (searchEl) {
      let t;
      searchEl.addEventListener('input', (e) => {
        clearTimeout(t);
        t = setTimeout(() => { searchQ = e.target.value.trim(); tablePage = 1; renderView(); }, 150);
      });
    }

    document.getElementById('results-prev')?.addEventListener('click', () => { tablePage = Math.max(1, tablePage - 1); renderView(); });
    document.getElementById('results-next')?.addEventListener('click', () => { tablePage = Math.min(pages, tablePage + 1); renderView(); });

    if (!currentUser.centre_id) {
      const filterEl = document.getElementById('results-centre-filter');
      if (filterEl) filterEl.addEventListener('change', e => { currentCentre = e.target.value; tablePage = 1; renderView(); });
    }

    window.resolveFlag = async (id, status) => {
      const row = document.getElementById(`flag-row-${id}`);
      if (row) row.style.opacity = '0.4';
      try {
        await ApiClient.updateFlag(id, status);
        if (row) row.remove();
      } catch (e) {
        if (row) row.style.opacity = '1';
        alert('Failed: ' + e.message);
      }
    };
  }

  renderView();

  window.exportSubmissions = async () => {
    try {
      const csv = await ApiClient.exportResults();
      const blob = new Blob([csv], { type: 'text/csv' });
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `results_${new Date().toISOString().split('T')[0]}.csv`;
      a.click();
    } catch (e) { console.error(e.message); }
  };
}

function renderAnalyticsCharts(submissions) {
  if (!submissions || submissions.length === 0) return;

  const passed = submissions.filter(s => s.result === 'pass').length;
  const failed = submissions.filter(s => s.result === 'fail').length;

  new Chart(document.getElementById('passFailChart'), {
    type: 'doughnut',
    data: {
      labels: ['Passed', 'Failed'],
      datasets: [{ data: [passed, failed], backgroundColor: ['#22c55e', '#ef4444'], borderWidth: 0 }]
    },
    options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
  });

  const subjectMap = {};
  submissions.forEach(s => {
    const sub = s.exam?.subject || s.exam_title || 'Unknown';
    if (!subjectMap[sub]) subjectMap[sub] = { totalScore: 0, count: 0 };
    subjectMap[sub].totalScore += s.score;
    subjectMap[sub].count++;
  });
  const subjects = Object.keys(subjectMap);
  const avgScores = subjects.map(sub => Math.round(subjectMap[sub].totalScore / subjectMap[sub].count));

  new Chart(document.getElementById('subjectChart'), {
    type: 'bar',
    data: {
      labels: subjects,
      datasets: [{ label: 'Avg Score %', data: avgScores, backgroundColor: '#3b82f6', borderRadius: 6 }]
    },
    options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 100 } }, plugins: { legend: { display: false } } }
  });

  const trendData = [...submissions].reverse().slice(-20);
  new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
      labels: trendData.map((_, i) => i + 1),
      datasets: [{ label: 'Score %', data: trendData.map(s => s.score), borderColor: '#6366f1', tension: 0.3, fill: true, backgroundColor: 'rgba(99,102,241,0.1)' }]
    },
    options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, max: 100 }, x: { display: false } } }
  });
}
