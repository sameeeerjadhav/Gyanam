/**
 * ResultsModule.js — Results & Analytics with Chart.js + filters.
 */
let _chartInstances = [];

function destroyCharts() {
  _chartInstances.forEach(c => {
    try { c.destroy(); } catch (_) {}
  });
  _chartInstances = [];
}

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
  } while (page <= lastPage && page <= 200);
  return { submissions: all, serverStats };
}

export async function renderResults(ApiClient, { currentUser }) {
  const [{ submissions: allSubmissions, serverStats }, flags] = await Promise.all([
    fetchAllResults(ApiClient),
    ApiClient.getFlags('pending').catch(() => []),
  ]);

  const centres = [...new Set(allSubmissions.map(s => s.student?.centre_name || s.centre_name).filter(Boolean))].sort();
  const results = [...new Set(allSubmissions.map(s => s.result).filter(Boolean))];
  const el = document.getElementById('page-content');
  let currentCentre = '';
  let currentResult = '';
  let searchQ = '';
  let tablePage = 1;
  let pageSize = 25;
  let pendingFlags = Array.isArray(flags) ? [...flags] : [];

  function calculateStats(subs) {
    if (!currentCentre && !currentResult && !searchQ && serverStats) {
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
    const avg = total ? Math.round(subs.reduce((acc, s) => acc + (Number(s.score) || 0), 0) / total) : 0;
    return { total, passed, failed, avg };
  }

  function getFiltered() {
    let filtered = currentCentre
      ? allSubmissions.filter(s => (s.student?.centre_name || s.centre_name) === currentCentre)
      : allSubmissions;

    if (currentResult) {
      filtered = filtered.filter(s => s.result === currentResult);
    }

    if (searchQ) {
      const q = searchQ.toLowerCase();
      filtered = filtered.filter(s => {
        const name = (s.student?.name || s.student_name || '').toLowerCase();
        const exam = (s.exam?.title || s.exam_title || '').toLowerCase();
        const centre = (s.student?.centre_name || s.centre_name || '').toLowerCase();
        const id = String(s.student?.identifier || s.student_id || '').toLowerCase();
        return name.includes(q) || exam.includes(q) || centre.includes(q) || id.includes(q);
      });
    }
    return filtered;
  }

  function hasActiveFilters() {
    return !!(searchQ || currentCentre || currentResult);
  }

  function clearFilters() {
    searchQ = '';
    currentCentre = '';
    currentResult = '';
    tablePage = 1;
    renderView();
  }

  function renderPagination(total) {
    const pager = document.getElementById('res-pagination');
    if (!pager) return;
    const totalPages = Math.max(1, Math.ceil(total / pageSize) || 1);
    if (tablePage > totalPages) tablePage = totalPages;
    const start = total === 0 ? 0 : (tablePage - 1) * pageSize + 1;
    const end = Math.min(tablePage * pageSize, total);

    let pageBtns = '';
    const from = Math.max(1, tablePage - 2);
    const to = Math.min(totalPages, tablePage + 2);
    if (from > 1) {
      pageBtns += `<button type="button" class="stu-page-btn" data-page="1">1</button>`;
      if (from > 2) pageBtns += `<span class="stu-page-ellipsis">…</span>`;
    }
    for (let p = from; p <= to; p++) {
      pageBtns += `<button type="button" class="stu-page-btn ${p === tablePage ? 'is-active' : ''}" data-page="${p}">${p}</button>`;
    }
    if (to < totalPages) {
      if (to < totalPages - 1) pageBtns += `<span class="stu-page-ellipsis">…</span>`;
      pageBtns += `<button type="button" class="stu-page-btn" data-page="${totalPages}">${totalPages}</button>`;
    }

    pager.innerHTML = `
      <div class="stu-pager-left">
        <label class="stu-pager-size">
          Rows
          <select id="res-page-size" class="form-select" aria-label="Rows per page">
            ${[10, 25, 50, 100].map(n => `<option value="${n}" ${pageSize === n ? 'selected' : ''}>${n}</option>`).join('')}
          </select>
        </label>
        <span class="stu-pager-range">${total === 0 ? '0 submissions' : `Showing ${start}–${end} of ${total}`}</span>
      </div>
      <div class="stu-pager-right">
        <button type="button" class="stu-page-btn" data-page="${tablePage - 1}" ${tablePage <= 1 ? 'disabled' : ''}>‹</button>
        ${pageBtns}
        <button type="button" class="stu-page-btn" data-page="${tablePage + 1}" ${tablePage >= totalPages ? 'disabled' : ''}>›</button>
      </div>`;

    pager.querySelectorAll('[data-page]').forEach(btn => {
      btn.addEventListener('click', () => {
        const p = parseInt(btn.getAttribute('data-page'), 10);
        if (!Number.isFinite(p) || p < 1) return;
        tablePage = p;
        renderView();
      });
    });
    document.getElementById('res-page-size')?.addEventListener('change', e => {
      pageSize = parseInt(e.target.value, 10) || 25;
      tablePage = 1;
      renderView();
    });
  }

  function renderView() {
    destroyCharts();
    const filtered = getFiltered();
    const stats = calculateStats(filtered);
    const passRate = stats.total ? Math.round((stats.passed / stats.total) * 100) : 0;
    const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize) || 1);
    if (tablePage > totalPages) tablePage = totalPages;
    const slice = filtered.slice((tablePage - 1) * pageSize, tablePage * pageSize);

    const flagsHTML = pendingFlags.length > 0 ? `
    <div class="card res-panel" style="margin-top:1rem">
      <div class="card-header dash-panel-head">
        <div>
          <h3>Flagged Questions</h3>
          <p class="dash-panel-sub">Pending review from students</p>
        </div>
        <span class="badge badge-yellow">${pendingFlags.length} pending</span>
      </div>
      <div class="table-wrap dash-table-wrap">
        <table class="dash-table">
          <thead><tr><th>Student</th><th>Exam</th><th>Question</th><th>Reason</th><th>Date</th><th>Actions</th></tr></thead>
          <tbody>
            ${pendingFlags.map(f => `<tr id="flag-row-${f.id}">
              <td><div class="dash-strong">${f.student_name || '—'}</div></td>
              <td><div class="dash-muted">${f.exam_title || '—'}</div></td>
              <td style="font-size:0.8rem;max-width:220px;white-space:normal">${f.question_text || '—'}</td>
              <td style="font-size:0.82rem">${f.reason || '—'}</td>
              <td class="dash-muted">${f.created_at ? new Date(f.created_at).toLocaleDateString('en-IN') : '—'}</td>
              <td>
                <div class="stu-actions">
                  <button type="button" class="stu-act stu-act-primary" onclick="resolveFlag(${f.id},'reviewed')" title="Mark reviewed">✓</button>
                  <button type="button" class="stu-act stu-act-danger" onclick="resolveFlag(${f.id},'dismissed')" title="Dismiss">✕</button>
                </div>
              </td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>
    </div>` : '';

    el.innerHTML = `
  <div class="res-page">
    <div class="page-header dash-page-header">
      <div>
        <h2>Results &amp; Analytics</h2>
        <p class="dash-meta">
          ${currentUser.centre_id
            ? `<span class="dash-chip dash-chip-blue">${currentUser.centre_id}</span>`
            : `<span class="dash-chip">Organisation-wide</span>`}
          <span class="dash-meta-sep">·</span>
          <span>${filtered.length} submission${filtered.length === 1 ? '' : 's'} shown</span>
        </p>
      </div>
      <div class="dash-page-actions">
        <button type="button" class="btn btn-outline btn-sm" onclick="exportSubmissions()">Export CSV</button>
      </div>
    </div>

    <div class="stu-toolbar card">
      <div class="stu-search-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
        </svg>
        <input id="results-search" type="search" class="form-input" placeholder="Search name, exam, centre, or ID…" value="${searchQ.replace(/"/g, '&quot;')}">
      </div>
      <div class="stu-filters">
        ${!currentUser.centre_id ? `
        <select id="results-centre-filter" class="form-select" title="Centre">
          <option value="">All Centres</option>
          ${centres.map(c => `<option value="${c}" ${currentCentre === c ? 'selected' : ''}>${c}</option>`).join('')}
        </select>` : ''}
        <select id="results-result-filter" class="form-select" title="Result">
          <option value="">All Results</option>
          <option value="pass" ${currentResult === 'pass' ? 'selected' : ''}>Passed</option>
          <option value="fail" ${currentResult === 'fail' ? 'selected' : ''}>Failed</option>
          ${results.filter(r => r !== 'pass' && r !== 'fail').map(r => `<option value="${r}" ${currentResult === r ? 'selected' : ''}>${r}</option>`).join('')}
        </select>
        <button type="button" id="results-clear" class="btn btn-outline btn-sm stu-clear-btn" ${hasActiveFilters() ? '' : 'hidden'}>Clear</button>
      </div>
    </div>

    <div class="dash-kpi-grid res-kpi-grid">
      <div class="dash-kpi dash-kpi-blue">
        <div class="dash-kpi-top"><span class="dash-kpi-label">Submissions</span><span class="dash-kpi-icon">📄</span></div>
        <div class="dash-kpi-value">${stats.total}</div>
        <div class="dash-kpi-sub">${hasActiveFilters() ? 'Filtered set' : 'All time'}</div>
      </div>
      <div class="dash-kpi dash-kpi-green">
        <div class="dash-kpi-top"><span class="dash-kpi-label">Passed</span><span class="dash-kpi-icon">✓</span></div>
        <div class="dash-kpi-value">${stats.passed}</div>
        <div class="dash-kpi-sub">${passRate}% pass rate</div>
      </div>
      <div class="dash-kpi dash-kpi-red">
        <div class="dash-kpi-top"><span class="dash-kpi-label">Failed</span><span class="dash-kpi-icon">✕</span></div>
        <div class="dash-kpi-value">${stats.failed}</div>
        <div class="dash-kpi-sub">${stats.total ? `${100 - passRate}% fail rate` : '—'}</div>
      </div>
      <div class="dash-kpi dash-kpi-amber">
        <div class="dash-kpi-top"><span class="dash-kpi-label">Avg Score</span><span class="dash-kpi-icon">◎</span></div>
        <div class="dash-kpi-value">${stats.avg}%</div>
        <div class="dash-kpi-sub">Across shown results</div>
      </div>
    </div>

    <div class="dash-mid-grid">
      <div class="card res-panel">
        <div class="card-header dash-panel-head">
          <div>
            <h3>Pass / Fail</h3>
            <p class="dash-panel-sub">Distribution of outcomes</p>
          </div>
        </div>
        <div class="res-chart-wrap">
          ${filtered.length === 0
            ? `<div class="dash-empty"><div class="dash-empty-icon">📊</div><div class="dash-empty-title">No data to chart</div><div class="dash-empty-text">Submissions will appear here after exams are taken.</div></div>`
            : `<canvas id="passFailChart"></canvas>`}
        </div>
      </div>
      <div class="card res-panel">
        <div class="card-header dash-panel-head">
          <div>
            <h3>Subject Avg Score</h3>
            <p class="dash-panel-sub">Average by subject / exam</p>
          </div>
        </div>
        <div class="res-chart-wrap">
          ${filtered.length === 0
            ? `<div class="dash-empty"><div class="dash-empty-icon">📈</div><div class="dash-empty-title">No data to chart</div><div class="dash-empty-text">Subject averages show once results exist.</div></div>`
            : `<canvas id="subjectChart"></canvas>`}
        </div>
      </div>
    </div>

    <div class="card res-panel">
      <div class="card-header dash-panel-head">
        <div>
          <h3>Score Trend</h3>
          <p class="dash-panel-sub">Latest attempts in current filter</p>
        </div>
      </div>
      <div class="res-chart-wrap res-chart-wide">
        ${filtered.length === 0
          ? `<div class="dash-empty"><div class="dash-empty-icon">📉</div><div class="dash-empty-title">No trend yet</div><div class="dash-empty-text">A score line chart will build as submissions come in.</div></div>`
          : `<canvas id="trendChart"></canvas>`}
      </div>
    </div>

    <div class="card res-panel">
      <div class="card-header dash-panel-head">
        <div>
          <h3>Submissions</h3>
          <p class="dash-panel-sub">${filtered.length} matching result${filtered.length === 1 ? '' : 's'}</p>
        </div>
      </div>
      <div class="table-wrap dash-table-wrap">
        <table class="dash-table">
          <thead>
            <tr>
              <th>Student</th>
              <th>Exam</th>
              <th>Centre</th>
              <th>Score</th>
              <th>Result</th>
              <th>Attempted</th>
            </tr>
          </thead>
          <tbody>
            ${slice.length === 0
              ? `<tr><td colspan="6" class="stu-empty">
                   <div class="stu-empty-inner">
                     <div class="stu-empty-icon">📭</div>
                     <div>No matching submissions</div>
                     ${hasActiveFilters() ? '<button type="button" class="btn btn-outline btn-sm" style="margin-top:0.65rem" id="res-empty-clear">Clear filters</button>' : ''}
                   </div>
                 </td></tr>`
              : slice.map(s => {
                  const name = s.student?.name || s.student_name || s.student_id || '—';
                  const exam = s.exam?.title || s.exam_title || s.exam_id || '—';
                  const centre = s.student?.centre_name || s.centre_name || '—';
                  return `<tr>
                    <td><div class="dash-strong">${name}</div></td>
                    <td><div class="dash-muted">${exam}</div></td>
                    <td class="dash-muted">${centre}</td>
                    <td><span class="dash-score">${s.score ?? '—'}%</span></td>
                    <td><span class="badge ${s.result === 'pass' ? 'badge-green' : 'badge-red'}">${s.result || '—'}</span></td>
                    <td class="dash-muted">${s.submitted_at ? new Date(s.submitted_at).toLocaleString('en-IN') : '—'}</td>
                  </tr>`;
                }).join('')}
          </tbody>
        </table>
      </div>
      <div id="res-pagination" class="stu-pagination"></div>
    </div>

    ${flagsHTML}
  </div>`;

    if (filtered.length > 0) renderAnalyticsCharts(filtered);
    renderPagination(filtered.length);

    const searchEl = document.getElementById('results-search');
    if (searchEl) {
      let t;
      searchEl.addEventListener('input', (e) => {
        clearTimeout(t);
        t = setTimeout(() => { searchQ = e.target.value.trim(); tablePage = 1; renderView(); }, 160);
      });
    }

    document.getElementById('results-centre-filter')?.addEventListener('change', e => {
      currentCentre = e.target.value;
      tablePage = 1;
      renderView();
    });
    document.getElementById('results-result-filter')?.addEventListener('change', e => {
      currentResult = e.target.value;
      tablePage = 1;
      renderView();
    });
    document.getElementById('results-clear')?.addEventListener('click', clearFilters);
    document.getElementById('res-empty-clear')?.addEventListener('click', clearFilters);

    window.resolveFlag = async (id, status) => {
      const row = document.getElementById(`flag-row-${id}`);
      if (row) row.style.opacity = '0.4';
      try {
        await ApiClient.updateFlag(id, status);
        pendingFlags = pendingFlags.filter(f => String(f.id) !== String(id));
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
  if (typeof Chart === 'undefined') return;

  const passed = submissions.filter(s => s.result === 'pass').length;
  const failed = submissions.filter(s => s.result === 'fail').length;

  const passFailEl = document.getElementById('passFailChart');
  if (passFailEl) {
    _chartInstances.push(new Chart(passFailEl, {
      type: 'doughnut',
      data: {
        labels: ['Passed', 'Failed'],
        datasets: [{ data: [passed, failed], backgroundColor: ['#16a34a', '#dc2626'], borderWidth: 0, hoverOffset: 4 }]
      },
      options: {
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true, padding: 16 } }
        }
      }
    }));
  }

  const subjectMap = {};
  submissions.forEach(s => {
    const sub = s.exam?.subject || s.exam?.title || s.exam_title || 'Unknown';
    if (!subjectMap[sub]) subjectMap[sub] = { totalScore: 0, count: 0 };
    subjectMap[sub].totalScore += Number(s.score) || 0;
    subjectMap[sub].count++;
  });
  const subjects = Object.keys(subjectMap).slice(0, 12);
  const avgScores = subjects.map(sub => Math.round(subjectMap[sub].totalScore / subjectMap[sub].count));

  const subjectEl = document.getElementById('subjectChart');
  if (subjectEl) {
    _chartInstances.push(new Chart(subjectEl, {
      type: 'bar',
      data: {
        labels: subjects,
        datasets: [{ label: 'Avg Score %', data: avgScores, backgroundColor: '#3b82f6', borderRadius: 6, maxBarThickness: 36 }]
      },
      options: {
        maintainAspectRatio: false,
        scales: {
          y: { beginAtZero: true, max: 100, grid: { color: '#f1f5f9' } },
          x: { ticks: { maxRotation: 40, minRotation: 0, autoSkip: true, font: { size: 10 } }, grid: { display: false } }
        },
        plugins: { legend: { display: false } }
      }
    }));
  }

  const trendData = [...submissions].reverse().slice(-20);
  const trendEl = document.getElementById('trendChart');
  if (trendEl) {
    _chartInstances.push(new Chart(trendEl, {
      type: 'line',
      data: {
        labels: trendData.map((_, i) => i + 1),
        datasets: [{
          label: 'Score %',
          data: trendData.map(s => s.score),
          borderColor: '#4f46e5',
          backgroundColor: 'rgba(79,70,229,0.12)',
          tension: 0.35,
          fill: true,
          pointRadius: 3,
          pointHoverRadius: 5,
        }]
      },
      options: {
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, max: 100, grid: { color: '#f1f5f9' } },
          x: { display: false }
        }
      }
    }));
  }
}
