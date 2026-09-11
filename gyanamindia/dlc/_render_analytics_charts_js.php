<?php
/**
 * Chart.js init for DLC Analytics Overview canvases.
 * Expects vars from _load_analytics_charts.php
 */
$dlcBarIsMoney = !empty($dlcBarIsMoney);
?>
<script>
(function initDlcAnalyticsCharts() {
    const CHART = {
        pieLabels: <?= json_encode(array_values($dlcPieLabels ?? []), JSON_UNESCAPED_UNICODE) ?>,
        pieData: <?= json_encode(array_values($dlcPieData ?? [])) ?>,
        barLabels: <?= json_encode(array_values($dlcBarLabels ?? []), JSON_UNESCAPED_UNICODE) ?>,
        barData: <?= json_encode(array_values($dlcBarData ?? [])) ?>,
        barIsMoney: <?= $dlcBarIsMoney ? 'true' : 'false' ?>,
        lineLabels: <?= json_encode(array_values($dlcLineLabels ?? []), JSON_UNESCAPED_UNICODE) ?>,
        lineAdm: <?= json_encode(array_values($dlcLineAdm ?? [])) ?>,
        lineRev: <?= json_encode(array_values($dlcLineRev ?? [])) ?>,
    };

    function loadChartJs(cb) {
        if (window.Chart) { cb(); return; }
        const s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
        s.async = true;
        s.onload = cb;
        document.head.appendChild(s);
    }

    const palette = ['#4361ee', '#0d9488', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4', '#84cc16', '#ec4899'];

    loadChartJs(function () {
        Chart.defaults.font.family = "'Sora', 'Inter', system-ui, sans-serif";
        Chart.defaults.font.weight = 600;
        Chart.defaults.color = '#64748b';

        const pieEl = document.getElementById('dlcPieChart');
        if (pieEl && CHART.pieData.length) {
            new Chart(pieEl, {
                type: 'pie',
                data: {
                    labels: CHART.pieLabels,
                    datasets: [{
                        data: CHART.pieData,
                        backgroundColor: palette.slice(0, CHART.pieData.length),
                        borderWidth: 2,
                        borderColor: '#fff',
                        hoverOffset: 6,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { boxWidth: 12, padding: 12, font: { size: 11, weight: 700 } },
                        },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            cornerRadius: 10,
                            padding: 10,
                            callbacks: {
                                label: (c) => {
                                    const total = c.dataset.data.reduce((a, b) => a + b, 0) || 1;
                                    const pct = Math.round((c.parsed / total) * 100);
                                    return ` ${c.label}: ${c.parsed} (${pct}%)`;
                                },
                            },
                        },
                    },
                },
            });
        }

        const barEl = document.getElementById('dlcBarChart');
        if (barEl && CHART.barData.length) {
            const ctx = barEl.getContext('2d');
            const grad = ctx.createLinearGradient(0, 0, 0, 260);
            grad.addColorStop(0, 'rgba(67, 97, 238, 0.95)');
            grad.addColorStop(1, 'rgba(56, 189, 248, 0.55)');
            new Chart(barEl, {
                type: 'bar',
                data: {
                    labels: CHART.barLabels,
                    datasets: [{
                        label: CHART.barIsMoney ? 'Share due' : 'Active students',
                        data: CHART.barData,
                        backgroundColor: grad,
                        borderRadius: 8,
                        maxBarThickness: 42,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            cornerRadius: 10,
                            padding: 10,
                            callbacks: {
                                label: (c) => CHART.barIsMoney
                                    ? (' ₹' + Number(c.parsed.y || 0).toLocaleString('en-IN'))
                                    : (' ' + Number(c.parsed.y || 0).toLocaleString('en-IN') + ' students'),
                            },
                        },
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: (v) => CHART.barIsMoney
                                    ? ('₹' + Number(v).toLocaleString('en-IN'))
                                    : String(v),
                                font: { size: 10 },
                            },
                            grid: { color: '#f1f5f9', drawBorder: false },
                        },
                        x: {
                            ticks: { font: { size: 10, weight: 700 }, maxRotation: 35, minRotation: 0 },
                            grid: { display: false },
                        },
                    },
                },
            });
        }

        const lineEl = document.getElementById('dlcLineChart');
        if (lineEl && CHART.lineLabels.length) {
            new Chart(lineEl, {
                type: 'line',
                data: {
                    labels: CHART.lineLabels,
                    datasets: [
                        {
                            label: 'Admissions',
                            data: CHART.lineAdm,
                            borderColor: '#4361ee',
                            backgroundColor: 'rgba(67, 97, 238, 0.12)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            yAxisID: 'y',
                        },
                        {
                            label: 'DLC share paid (₹)',
                            data: CHART.lineRev,
                            borderColor: '#0d9488',
                            backgroundColor: 'rgba(13, 148, 136, 0.08)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            yAxisID: 'y1',
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'top',
                            align: 'end',
                            labels: { boxWidth: 12, padding: 14, font: { size: 11, weight: 700 } },
                        },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            cornerRadius: 10,
                            padding: 10,
                        },
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            position: 'left',
                            beginAtZero: true,
                            title: { display: true, text: 'Admissions', font: { size: 11, weight: 700 } },
                            ticks: { stepSize: 1, font: { size: 10 } },
                            grid: { color: '#f1f5f9', drawBorder: false },
                        },
                        y1: {
                            type: 'linear',
                            position: 'right',
                            beginAtZero: true,
                            title: { display: true, text: 'Share ₹', font: { size: 11, weight: 700 } },
                            ticks: {
                                callback: (v) => '₹' + Number(v).toLocaleString('en-IN'),
                                font: { size: 10 },
                            },
                            grid: { drawOnChartArea: false },
                        },
                        x: {
                            ticks: { font: { size: 11, weight: 700 } },
                            grid: { display: false },
                        },
                    },
                },
            });
        }
    });
})();
</script>
