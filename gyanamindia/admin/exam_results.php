<?php
/**
 * Gyanam Portal — Admin: Exam Results Dashboard
 * Comprehensive view of all students' exam performance across all ATCs.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';
if (file_exists(__DIR__ . '/../includes/exam_integration.php')) {
    require_once __DIR__ . '/../includes/exam_integration.php';
}

requireLogin(['Admin']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());

// Check integration
$integrationReady = function_exists('fetchAllExamResults') && defined('EXAM_API_TOKEN') && EXAM_API_TOKEN !== 'PASTE_YOUR_TOKEN_HERE';

// Fetch results from Exam Portal
$submissions = [];
$stats = ['total' => 0, 'passed' => 0, 'failed' => 0, 'avg' => 0];
$fetchError = null;

if ($integrationReady) {
    $res = fetchAllExamResults();
    if ($res['success'] && isset($res['data'])) {
        $submissions = $res['data']['submissions'] ?? [];
        $stats = $res['data']['stats'] ?? $stats;
    } else {
        $fetchError = $res['error'] ?? 'Could not fetch exam results.';
    }
}

// Get filters
$filterResult = $_GET['result'] ?? 'all';
$filterSearch = trim($_GET['search'] ?? '');

// Client-side filtering will be done via JS
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Results — Head Office | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📊</text></svg>">
    <style>
    :root { --font: 'Sora', sans-serif; --mono: 'JetBrains Mono', monospace; }

    .er-page { padding: 1.75rem 2rem; width: 100%; box-sizing: border-box; }

    /* KPI Row */
    .er-kpi-grid {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 1rem; margin-bottom: 2rem;
    }
    .er-kpi {
        background: #fff; border: 1.5px solid #e5e7eb; border-radius: 16px;
        padding: 1.25rem 1.5rem; display: flex; align-items: center; gap: 1rem;
        transition: all .2s; position: relative; overflow: hidden;
    }
    .er-kpi:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.06); }
    .er-kpi::before {
        content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%;
    }
    .er-kpi.kpi-total::before { background: linear-gradient(180deg, #4361ee, #3730a3); }
    .er-kpi.kpi-pass::before { background: linear-gradient(180deg, #10b981, #059669); }
    .er-kpi.kpi-fail::before { background: linear-gradient(180deg, #f43f5e, #dc2626); }
    .er-kpi.kpi-avg::before { background: linear-gradient(180deg, #f59e0b, #d97706); }

    .er-kpi-icon {
        width: 44px; height: 44px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .er-kpi-icon svg { width: 22px; height: 22px; stroke-width: 2; }
    .kpi-total .er-kpi-icon { background: #eef1fd; color: #4361ee; }
    .kpi-pass .er-kpi-icon { background: #d1fae5; color: #059669; }
    .kpi-fail .er-kpi-icon { background: #ffe4e6; color: #f43f5e; }
    .kpi-avg .er-kpi-icon { background: #fef3c7; color: #d97706; }
    .er-kpi-icon svg { stroke: currentColor; }

    .er-kpi-label { font-size: .72rem; font-weight: 800; color: #6b7280; text-transform: uppercase; letter-spacing: .05em; }
    .er-kpi-value { font-size: 1.75rem; font-weight: 800; line-height: 1; color: #111827; margin-top: .2rem; }

    /* Filter Bar */
    .er-filter-bar {
        display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
        margin-bottom: 1.5rem; padding: 1rem 1.25rem;
        background: #fff; border: 1.5px solid #e5e7eb; border-radius: 14px;
    }
    .er-filter-bar label { font-size: .75rem; font-weight: 700; color: #374151; white-space: nowrap; }
    .er-filter-bar select, .er-filter-bar input[type="text"] {
        padding: .5rem .75rem; border: 1.5px solid #e5e7eb; border-radius: 10px;
        font-size: .82rem; font-family: var(--font); font-weight: 500; color: #1f2937;
        background: #fff; transition: border .2s;
    }
    .er-filter-bar select:focus, .er-filter-bar input:focus {
        outline: none; border-color: #4361ee; box-shadow: 0 0 0 3px rgba(67,97,238,.1);
    }
    .er-filter-bar input[type="text"] { min-width: 200px; }
    .er-filter-spacer { flex: 1; }
    .er-export-btn {
        display: inline-flex; align-items: center; gap: .4rem;
        padding: .55rem 1.1rem; border-radius: 10px; border: 1.5px solid #e5e7eb;
        background: #fff; color: #374151; font-size: .8rem; font-weight: 700;
        cursor: pointer; font-family: var(--font); transition: all .2s;
    }
    .er-export-btn:hover { background: #f9fafb; border-color: #a5b4fc; }
    .er-export-btn svg { width: 14px; height: 14px; }

    /* Status tabs */
    .er-tabs { display: flex; gap: .5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
    .er-tab {
        padding: .55rem 1.1rem; border-radius: 10px; border: 1.5px solid #e5e7eb;
        background: #fff; color: #374151; font-size: .82rem; font-weight: 700;
        cursor: pointer; font-family: var(--font); transition: all .15s;
        display: flex; align-items: center; gap: .4rem;
    }
    .er-tab:hover { border-color: #a5b4fc; background: #eef2ff; }
    .er-tab.active { background: linear-gradient(135deg,#4361ee,#3730a3); border-color: #3730a3; color: #fff; box-shadow: 0 2px 8px rgba(67,97,238,.2); }
    .er-tab-count { padding: .15rem .5rem; border-radius: 999px; font-size: .7rem; font-weight: 800; }
    .er-tab.active .er-tab-count { background: rgba(255,255,255,.2); }
    .er-tab:not(.active) .er-tab-count { background: #e5e7eb; color: #6b7280; }

    /* Results Table */
    .er-table-wrap {
        background: #fff; border: 1.5px solid #e5e7eb; border-radius: 14px;
        overflow: hidden;
    }
    .er-table {
        width: 100%; border-collapse: collapse; font-size: .84rem;
    }
    .er-table thead { background: linear-gradient(135deg, #f8fafc, #f1f5f9); }
    .er-table th {
        padding: .85rem 1rem; text-align: left; font-size: .72rem; font-weight: 800;
        color: #6b7280; text-transform: uppercase; letter-spacing: .05em;
        border-bottom: 1.5px solid #e5e7eb;
    }
    .er-table td {
        padding: .75rem 1rem; border-bottom: 1px solid #f3f4f6;
        color: #374151; vertical-align: middle;
    }
    .er-table tbody tr { transition: background .15s; }
    .er-table tbody tr:hover { background: #f9fafb; }
    .er-table tbody tr:last-child td { border-bottom: 0; }

    .er-student-name { font-weight: 700; color: #1f2937; }
    .er-student-id { font-size: .72rem; color: #9ca3af; font-family: var(--mono); }
    .er-centre { font-size: .75rem; font-weight: 700; padding: .2rem .55rem; border-radius: 6px; background: #eef2ff; color: #4361ee; }

    .er-score-bar {
        display: flex; align-items: center; gap: .5rem;
    }
    .er-score-track {
        flex: 1; height: 6px; background: #e5e7eb; border-radius: 999px; overflow: hidden;
        min-width: 60px; max-width: 100px;
    }
    .er-score-fill { height: 100%; border-radius: 999px; transition: width .3s ease; }
    .er-score-fill.high { background: linear-gradient(90deg, #10b981, #059669); }
    .er-score-fill.mid { background: linear-gradient(90deg, #f59e0b, #d97706); }
    .er-score-fill.low { background: linear-gradient(90deg, #f43f5e, #dc2626); }
    .er-score-pct { font-weight: 800; font-size: .85rem; min-width: 36px; }

    .er-badge {
        display: inline-flex; align-items: center; gap: .25rem;
        padding: .25rem .7rem; border-radius: 999px; font-size: .72rem; font-weight: 800;
        text-transform: uppercase; letter-spacing: .04em;
    }
    .er-badge.pass { background: #d1fae5; color: #065f46; }
    .er-badge.fail { background: #fee2e2; color: #991b1b; }

    .er-date { font-size: .78rem; color: #6b7280; }

    .er-detail-btn {
        padding: .35rem .7rem; border-radius: 8px; border: 1.5px solid #e5e7eb;
        background: #fff; color: #374151; font-size: .75rem; font-weight: 700;
        cursor: pointer; font-family: var(--font); transition: all .15s; white-space: nowrap;
    }
    .er-detail-btn:hover { border-color: #4361ee; color: #4361ee; background: #eef2ff; }

    /* Empty / Not configured */
    .er-empty {
        text-align: center; padding: 4rem 2rem;
        background: #fff; border: 1.5px solid #e5e7eb; border-radius: 14px;
    }
    .er-empty svg { width: 48px; height: 48px; stroke: #d1d5db; display: block; margin: 0 auto 1rem; }
    .er-empty h4 { font-size: 1.1rem; font-weight: 700; color: #374151; margin-bottom: .5rem; }
    .er-empty p { font-size: .875rem; color: #6b7280; max-width: 500px; margin: 0 auto; line-height: 1.6; }

    /* Detail Modal */
    .er-modal-overlay {
        display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5);
        z-index: 9999; align-items: center; justify-content: center; padding: 1rem;
    }
    .er-modal-overlay.active { display: flex; }
    .er-modal {
        background: #fff; border-radius: 18px; max-width: 600px; width: 100%;
        max-height: 90vh; overflow: hidden; display: flex; flex-direction: column;
        box-shadow: 0 24px 80px rgba(0,0,0,.2);
    }
    .er-modal-header {
        background: linear-gradient(135deg, #4361ee, #3730a3); padding: 1.1rem 1.5rem;
        display: flex; align-items: center; justify-content: space-between;
    }
    .er-modal-header h3 { color: #fff; font-size: .95rem; font-weight: 800; margin: 0; display: flex; align-items: center; gap: .5rem; }
    .er-modal-close {
        background: rgba(255,255,255,.15); border: none; border-radius: 8px;
        color: #fff; padding: .3rem .6rem; cursor: pointer; font-size: .9rem; font-weight: 700;
    }
    .er-modal-close:hover { background: rgba(255,255,255,.25); }
    .er-modal-body { padding: 1.5rem; overflow-y: auto; flex: 1; }

    .er-detail-grid {
        display: grid; grid-template-columns: 1fr 1fr; gap: .75rem 1.5rem;
        margin-bottom: 1.5rem; padding-bottom: 1.25rem; border-bottom: 1px solid #e5e7eb;
    }
    .er-detail-label { font-size: .68rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: #9ca3af; margin-bottom: .15rem; }
    .er-detail-value { font-size: .88rem; font-weight: 600; color: #1f2937; }

    .er-answers-title { font-size: .8rem; font-weight: 800; color: #374151; margin-bottom: .75rem; }
    .er-answers-list { display: flex; flex-direction: column; gap: .5rem; }
    .er-answer-item {
        display: flex; align-items: flex-start; gap: .6rem;
        padding: .6rem .85rem; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px;
        font-size: .82rem;
    }
    .er-answer-q { font-weight: 600; color: #374151; flex: 1; }
    .er-answer-status { font-size: .7rem; font-weight: 800; padding: .15rem .4rem; border-radius: 4px; }
    .er-answer-status.correct { background: #d1fae5; color: #065f46; }
    .er-answer-status.wrong { background: #fee2e2; color: #991b1b; }

    @media (max-width: 768px) {
        .er-page { padding: 1.25rem; }
        .er-kpi-grid { grid-template-columns: 1fr 1fr; }
        .er-filter-bar { flex-direction: column; align-items: stretch; }
        .er-filter-bar input[type="text"] { min-width: 0; }
        .er-table { font-size: .78rem; }
        .er-table th, .er-table td { padding: .6rem .5rem; }
        .er-detail-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 480px) {
        .er-kpi-grid { grid-template-columns: 1fr; }
    }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <div class="header-greeting">
                    <h2>Exam Results</h2>
                    <p>All students' examination performance across centres</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="er-page">

            <?php if (!$integrationReady): ?>
            <div class="er-empty">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="1.5">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <h4>Exam Portal Not Configured</h4>
                <p>The Exam Portal API token needs to be set up. Please check config/db.php for EXAM_API_TOKEN.</p>
            </div>

            <?php else: ?>

            <?php if ($fetchError): ?>
            <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:1rem;border-radius:10px;margin-bottom:1.5rem;font-weight:600;font-size:.85rem;">
                ⚠️ <?= htmlspecialchars($fetchError) ?>
            </div>
            <?php endif; ?>

            <!-- KPI Cards -->
            <div class="er-kpi-grid">
                <div class="er-kpi kpi-total">
                    <div class="er-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <div>
                        <div class="er-kpi-label">Total Exams Taken</div>
                        <div class="er-kpi-value" id="kpiTotal"><?= $stats['total'] ?></div>
                    </div>
                </div>
                <div class="er-kpi kpi-pass">
                    <div class="er-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div>
                        <div class="er-kpi-label">Passed</div>
                        <div class="er-kpi-value" id="kpiPassed"><?= $stats['passed'] ?></div>
                    </div>
                </div>
                <div class="er-kpi kpi-fail">
                    <div class="er-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </div>
                    <div>
                        <div class="er-kpi-label">Failed</div>
                        <div class="er-kpi-value" id="kpiFailed"><?= $stats['failed'] ?></div>
                    </div>
                </div>
                <div class="er-kpi kpi-avg">
                    <div class="er-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    </div>
                    <div>
                        <div class="er-kpi-label">Average Score</div>
                        <div class="er-kpi-value" id="kpiAvg"><?= $stats['avg'] ?>%</div>
                    </div>
                </div>
            </div>

            <!-- Status Tabs -->
            <div class="er-tabs">
                <button class="er-tab active" data-filter="all" onclick="filterResults('all', this)">
                    All <span class="er-tab-count" id="countAll"><?= $stats['total'] ?></span>
                </button>
                <button class="er-tab" data-filter="pass" onclick="filterResults('pass', this)">
                    ✅ Passed <span class="er-tab-count" id="countPass"><?= $stats['passed'] ?></span>
                </button>
                <button class="er-tab" data-filter="fail" onclick="filterResults('fail', this)">
                    ❌ Failed <span class="er-tab-count" id="countFail"><?= $stats['failed'] ?></span>
                </button>
            </div>

            <!-- Filter Bar -->
            <div class="er-filter-bar">
                <label>🔍</label>
                <input type="text" id="erSearch" placeholder="Search by student name, ID, exam title..." oninput="applyFilters()">
                <div class="er-filter-spacer"></div>
                <button class="er-export-btn" onclick="exportResults()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Export CSV
                </button>
            </div>

            <!-- Results Table -->
            <?php if (empty($submissions)): ?>
            <div class="er-empty">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="1.5">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                </svg>
                <h4>No Exam Results Yet</h4>
                <p>Once students take exams via the Exam Portal, their results will appear here automatically.</p>
            </div>
            <?php else: ?>
            <div class="er-table-wrap">
                <table class="er-table" id="resultsTable">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Exam</th>
                            <th>Centre</th>
                            <th>Score</th>
                            <th>Correct</th>
                            <th>Result</th>
                            <th>Duration</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="resultsBody">
                        <?php foreach ($submissions as $i => $sub): ?>
                        <?php
                            $score = intval($sub['score'] ?? 0);
                            $result = strtolower($sub['result'] ?? 'fail');
                            $scoreClass = $score >= 70 ? 'high' : ($score >= 40 ? 'mid' : 'low');
                            $correct = $sub['correct_answers'] ?? 0;
                            $totalQ = $sub['total_questions'] ?? 0;
                            $duration = $sub['duration_taken'] ?? 0;
                            $dMin = floor($duration / 60);
                            $dSec = $duration % 60;
                            $submittedAt = !empty($sub['submitted_at']) ? date('d M Y, h:i A', strtotime($sub['submitted_at'])) : '—';
                            $studentName = $sub['student_name'] ?? ($sub['student']['name'] ?? 'Unknown');
                            $studentId = $sub['student']['identifier'] ?? '';
                            $centre = $sub['centre_name'] ?? '';
                            $examTitle = $sub['exam_title'] ?? ($sub['exam']['title'] ?? 'Exam');
                        ?>
                        <tr data-result="<?= $result ?>" class="er-row">
                            <td>
                                <div class="er-student-name"><?= htmlspecialchars($studentName) ?></div>
                                <div class="er-student-id"><?= htmlspecialchars($studentId) ?></div>
                            </td>
                            <td><?= htmlspecialchars($examTitle) ?></td>
                            <td><span class="er-centre"><?= htmlspecialchars($centre) ?></span></td>
                            <td>
                                <div class="er-score-bar">
                                    <span class="er-score-pct"><?= $score ?>%</span>
                                    <div class="er-score-track">
                                        <div class="er-score-fill <?= $scoreClass ?>" style="width:<?= $score ?>%"></div>
                                    </div>
                                </div>
                            </td>
                            <td><?= $correct ?>/<?= $totalQ ?></td>
                            <td><span class="er-badge <?= $result ?>"><?= $result === 'pass' ? '✅ Pass' : '❌ Fail' ?></span></td>
                            <td><?= $dMin ?>m <?= $dSec ?>s</td>
                            <td class="er-date"><?= $submittedAt ?></td>
                            <td>
                                <button class="er-detail-btn" onclick="showDetail(<?= $i ?>)">
                                    View Details
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Detail Modal -->
<div class="er-modal-overlay" id="detailModal">
    <div class="er-modal">
        <div class="er-modal-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Exam Result Details
            </h3>
            <button class="er-modal-close" onclick="closeDetail()">✕</button>
        </div>
        <div class="er-modal-body" id="detailContent">
            <!-- Filled by JS -->
        </div>
    </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
const submissions = <?= json_encode($submissions) ?>;
let currentFilter = 'all';

function filterResults(filter, btn) {
    currentFilter = filter;
    document.querySelectorAll('.er-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    applyFilters();
}

function applyFilters() {
    const search = document.getElementById('erSearch').value.toLowerCase().trim();
    const rows = document.querySelectorAll('.er-row');
    let shown = 0;

    rows.forEach(row => {
        const result = row.dataset.result;
        const text = row.textContent.toLowerCase();
        const matchFilter = (currentFilter === 'all' || result === currentFilter);
        const matchSearch = (!search || text.includes(search));
        row.style.display = (matchFilter && matchSearch) ? '' : 'none';
        if (matchFilter && matchSearch) shown++;
    });
}

function showDetail(index) {
    const sub = submissions[index];
    if (!sub) return;

    const studentName = sub.student_name || (sub.student ? sub.student.name : 'Unknown');
    const studentId = sub.student ? sub.student.identifier : '';
    const score = parseInt(sub.score || 0);
    const result = (sub.result || 'fail').toLowerCase();
    const duration = parseInt(sub.duration_taken || 0);
    const dMin = Math.floor(duration / 60);
    const dSec = duration % 60;
    const correct = sub.correct_answers || 0;
    const total = sub.total_questions || 0;
    const examTitle = sub.exam_title || (sub.exam ? sub.exam.title : 'Exam');
    const centre = sub.centre_name || '';
    const submittedAt = sub.submitted_at ? new Date(sub.submitted_at).toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';

    let html = `
        <div class="er-detail-grid">
            <div><div class="er-detail-label">Student</div><div class="er-detail-value">${studentName}</div></div>
            <div><div class="er-detail-label">Identifier</div><div class="er-detail-value" style="font-family:var(--mono)">${studentId}</div></div>
            <div><div class="er-detail-label">Exam</div><div class="er-detail-value">${examTitle}</div></div>
            <div><div class="er-detail-label">Centre</div><div class="er-detail-value">${centre}</div></div>
            <div><div class="er-detail-label">Score</div><div class="er-detail-value" style="font-size:1.2rem">${score}% <span class="er-badge ${result}" style="font-size:.65rem;vertical-align:middle;margin-left:.4rem">${result === 'pass' ? '✅ PASS' : '❌ FAIL'}</span></div></div>
            <div><div class="er-detail-label">Correct Answers</div><div class="er-detail-value">${correct} / ${total}</div></div>
            <div><div class="er-detail-label">Duration</div><div class="er-detail-value">${dMin}m ${dSec}s</div></div>
            <div><div class="er-detail-label">Submitted</div><div class="er-detail-value">${submittedAt}</div></div>
        </div>
    `;

    // Show answers if available
    const answers = sub.answers || [];
    if (answers.length > 0) {
        html += `<div class="er-answers-title">Question-wise Breakdown (${answers.length} questions)</div>`;
        html += `<div class="er-answers-list">`;
        answers.forEach((a, i) => {
            const isCorrect = a.is_correct || a.selected_option === a.correct_option;
            html += `
                <div class="er-answer-item">
                    <span style="font-weight:800;color:#6b7280;font-size:.75rem;min-width:22px">Q${i+1}</span>
                    <span class="er-answer-q">${a.question_text || 'Question ' + (i+1)}</span>
                    <span class="er-answer-status ${isCorrect ? 'correct' : 'wrong'}">${isCorrect ? '✓' : '✗'}</span>
                </div>
            `;
        });
        html += `</div>`;
    }

    document.getElementById('detailContent').innerHTML = html;
    document.getElementById('detailModal').classList.add('active');
}

function closeDetail() {
    document.getElementById('detailModal').classList.remove('active');
}

document.getElementById('detailModal').addEventListener('click', function(e) {
    if (e.target === this) closeDetail();
});

function exportResults() {
    let csv = 'Student Name,Student ID,Exam,Centre,Score (%),Correct,Total,Result,Duration,Date\n';
    submissions.forEach(s => {
        const name = s.student_name || (s.student ? s.student.name : '');
        const id = s.student ? s.student.identifier : '';
        const exam = s.exam_title || (s.exam ? s.exam.title : '');
        const centre = s.centre_name || '';
        csv += `"${name}","${id}","${exam}","${centre}",${s.score},${s.correct_answers},${s.total_questions},${s.result},${s.duration_taken},"${s.submitted_at}"\n`;
    });
    const blob = new Blob([csv], {type: 'text/csv'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'exam_results_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click(); URL.revokeObjectURL(url);
}
</script>
</body>
</html>
