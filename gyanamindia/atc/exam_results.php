<?php
/**
 * Gyanam Portal — ATC: Exam Results Dashboard
 * Shows exam performance for this ATC centre's students only.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';
if (file_exists(__DIR__ . '/../includes/exam_integration.php')) {
    require_once __DIR__ . '/../includes/exam_integration.php';
}

requireLogin(['ATC CENTER']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$atcId = $_SESSION['atc_id'] ?? null;

// Get ATC code
$atcCode = '';
try {
    $atcStmt = $pdo->prepare("SELECT atc_code, name FROM atc_centers WHERE id = ?");
    $atcStmt->execute([$atcId]);
    $atcRow = $atcStmt->fetch(PDO::FETCH_ASSOC);
    $atcCode = $atcRow['atc_code'] ?? 'ATC' . $atcId;
    $atcName = $atcRow['name'] ?? 'ATC Centre';
} catch (Exception $e) { $atcName = 'ATC Centre'; }

// Check integration
$integrationReady = function_exists('fetchAllExamResults') && defined('EXAM_API_TOKEN') && EXAM_API_TOKEN !== 'PASTE_YOUR_TOKEN_HERE';

// Fetch results from Exam Portal
$submissions = [];
$stats = ['total' => 0, 'passed' => 0, 'failed' => 0, 'avg' => 0];
$fetchError = null;

if ($integrationReady) {
    $res = fetchAllExamResults();
    if ($res['success'] && isset($res['data'])) {
        $allSubs = $res['data']['submissions'] ?? [];
        // Filter to only this ATC's students
        $submissions = array_values(array_filter($allSubs, function($s) use ($atcCode) {
            return ($s['centre_name'] ?? '') === $atcCode;
        }));
        // Recalculate stats for this centre
        $stats['total'] = count($submissions);
        $stats['passed'] = count(array_filter($submissions, fn($s) => strtolower($s['result'] ?? '') === 'pass'));
        $stats['failed'] = $stats['total'] - $stats['passed'];
        $stats['avg'] = $stats['total'] > 0 ? round(array_sum(array_column($submissions, 'score')) / $stats['total']) : 0;
    } else {
        $fetchError = $res['error'] ?? 'Could not fetch exam results.';
    }
}

// Calculate pass rate
$passRate = $stats['total'] > 0 ? round(($stats['passed'] / $stats['total']) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Results — ATC Login | Gyanam India</title>
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
        display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem; margin-bottom: 2rem;
    }
    .er-kpi {
        background: #fff; border: 1.5px solid #e5e7eb; border-radius: 16px;
        padding: 1.25rem 1.5rem; display: flex; align-items: center; gap: 1rem;
        transition: all .2s; position: relative; overflow: hidden;
    }
    .er-kpi:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.06); }
    .er-kpi::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; }
    .er-kpi.kpi-total::before { background: linear-gradient(180deg, #4361ee, #3730a3); }
    .er-kpi.kpi-pass::before { background: linear-gradient(180deg, #10b981, #059669); }
    .er-kpi.kpi-fail::before { background: linear-gradient(180deg, #f43f5e, #dc2626); }
    .er-kpi.kpi-avg::before { background: linear-gradient(180deg, #f59e0b, #d97706); }
    .er-kpi.kpi-rate::before { background: linear-gradient(180deg, #8b5cf6, #7c3aed); }

    .er-kpi-icon {
        width: 44px; height: 44px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .er-kpi-icon svg { width: 22px; height: 22px; stroke-width: 2; stroke: currentColor; }
    .kpi-total .er-kpi-icon { background: #eef1fd; color: #4361ee; }
    .kpi-pass .er-kpi-icon { background: #d1fae5; color: #059669; }
    .kpi-fail .er-kpi-icon { background: #ffe4e6; color: #f43f5e; }
    .kpi-avg .er-kpi-icon { background: #fef3c7; color: #d97706; }
    .kpi-rate .er-kpi-icon { background: #ede9fe; color: #7c3aed; }

    .er-kpi-label { font-size: .72rem; font-weight: 800; color: #6b7280; text-transform: uppercase; letter-spacing: .05em; }
    .er-kpi-value { font-size: 1.75rem; font-weight: 800; line-height: 1; color: #111827; margin-top: .2rem; }

    /* Filter Bar */
    .er-filter-bar {
        display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
        margin-bottom: 1.5rem; padding: 1rem 1.25rem;
        background: #fff; border: 1.5px solid #e5e7eb; border-radius: 14px;
    }
    .er-filter-bar label { font-size: .75rem; font-weight: 700; color: #374151; white-space: nowrap; }
    .er-filter-bar input[type="text"] {
        padding: .5rem .75rem; border: 1.5px solid #e5e7eb; border-radius: 10px;
        font-size: .82rem; font-family: var(--font); font-weight: 500; color: #1f2937;
        background: #fff; min-width: 200px; transition: border .2s;
    }
    .er-filter-bar input:focus { outline: none; border-color: #4361ee; box-shadow: 0 0 0 3px rgba(67,97,238,.1); }
    .er-filter-spacer { flex: 1; }

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

    /* Student Result Cards */
    .er-cards-grid {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
        gap: 1rem;
    }
    .er-card {
        background: #fff; border: 1.5px solid #e5e7eb; border-radius: 14px;
        overflow: hidden; transition: all .2s;
    }
    .er-card:hover { border-color: #a5b4fc; box-shadow: 0 4px 12px rgba(67,97,238,.08); }
    .er-card-header {
        display: flex; align-items: center; gap: .75rem;
        padding: .85rem 1.1rem; background: linear-gradient(135deg, #fafbfc, #f4f5f7);
        border-bottom: 1px solid #e5e7eb;
    }
    .er-card-avatar {
        width: 40px; height: 40px; border-radius: 10px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        font-size: 1rem; font-weight: 800; color: #fff;
    }
    .er-card-avatar.pass-bg { background: linear-gradient(135deg, #10b981, #059669); }
    .er-card-avatar.fail-bg { background: linear-gradient(135deg, #f43f5e, #dc2626); }
    .er-card-name { font-size: .88rem; font-weight: 700; color: #1f2937; }
    .er-card-id { font-size: .72rem; color: #9ca3af; font-family: var(--mono); }
    .er-card-badge {
        margin-left: auto; padding: .2rem .65rem; border-radius: 999px;
        font-size: .7rem; font-weight: 800; text-transform: uppercase;
    }
    .er-card-badge.pass { background: #d1fae5; color: #065f46; }
    .er-card-badge.fail { background: #fee2e2; color: #991b1b; }

    .er-card-body { padding: .85rem 1.1rem; }
    .er-card-exam { font-size: .82rem; font-weight: 600; color: #374151; margin-bottom: .6rem; }

    .er-card-stats {
        display: grid; grid-template-columns: repeat(3, 1fr); gap: .5rem;
    }
    .er-card-stat {
        text-align: center; padding: .5rem; background: #f9fafb; border-radius: 8px;
    }
    .er-card-stat-value { font-size: 1rem; font-weight: 800; color: #1f2937; }
    .er-card-stat-label { font-size: .62rem; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: .04em; }

    .er-card-footer {
        padding: .5rem 1.1rem .85rem; display: flex; gap: .5rem;
    }
    .er-card-btn {
        flex: 1; padding: .45rem; border-radius: 8px; border: 1.5px solid #e5e7eb;
        background: #fff; color: #374151; font-size: .75rem; font-weight: 700;
        cursor: pointer; font-family: var(--font); transition: all .15s;
        text-align: center;
    }
    .er-card-btn:hover { border-color: #4361ee; color: #4361ee; background: #eef2ff; }
    .er-card-btn.cert { border-color: #a7f3d0; color: #065f46; background: #ecfdf5; }
    .er-card-btn.cert:hover { background: #d1fae5; border-color: #059669; }

    /* Score circle */
    .er-score-circle {
        width: 50px; height: 50px; border-radius: 50%; margin-left: auto;
        display: flex; align-items: center; justify-content: center;
        font-size: .85rem; font-weight: 800; flex-shrink: 0;
    }
    .er-score-circle.high { background: #d1fae5; color: #065f46; border: 2px solid #10b981; }
    .er-score-circle.mid { background: #fef3c7; color: #92400e; border: 2px solid #f59e0b; }
    .er-score-circle.low { background: #fee2e2; color: #991b1b; border: 2px solid #f43f5e; }

    /* Empty */
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
        background: #fff; border-radius: 18px; max-width: 560px; width: 100%;
        max-height: 90vh; overflow: hidden; display: flex; flex-direction: column;
        box-shadow: 0 24px 80px rgba(0,0,0,.2);
    }
    .er-modal-header {
        background: linear-gradient(135deg, #4361ee, #3730a3); padding: 1.1rem 1.5rem;
        display: flex; align-items: center; justify-content: space-between;
    }
    .er-modal-header h3 { color: #fff; font-size: .95rem; font-weight: 800; margin: 0; }
    .er-modal-close {
        background: rgba(255,255,255,.15); border: none; border-radius: 8px;
        color: #fff; padding: .3rem .6rem; cursor: pointer; font-size: .9rem; font-weight: 700;
    }
    .er-modal-body { padding: 1.5rem; overflow-y: auto; flex: 1; }
    .er-detail-grid {
        display: grid; grid-template-columns: 1fr 1fr; gap: .6rem 1.2rem;
        margin-bottom: 1.25rem; padding-bottom: 1rem; border-bottom: 1px solid #e5e7eb;
    }
    .er-detail-label { font-size: .68rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: #9ca3af; margin-bottom: .1rem; }
    .er-detail-value { font-size: .85rem; font-weight: 600; color: #1f2937; }

    @media (max-width: 768px) {
        .er-page { padding: 1.25rem; }
        .er-kpi-grid { grid-template-columns: 1fr 1fr; }
        .er-cards-grid { grid-template-columns: 1fr; }
        .er-filter-bar { flex-direction: column; align-items: stretch; }
        .er-filter-bar input[type="text"] { min-width: 0; }
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
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <div class="header-greeting">
                    <h2>Exam Results</h2>
                    <p>Your students' examination performance</p>
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
                <p>The Exam Portal API token needs to be set up. Contact your administrator.</p>
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
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <div>
                        <div class="er-kpi-label">Total Exams Taken</div>
                        <div class="er-kpi-value"><?= $stats['total'] ?></div>
                    </div>
                </div>
                <div class="er-kpi kpi-pass">
                    <div class="er-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div>
                        <div class="er-kpi-label">Passed</div>
                        <div class="er-kpi-value"><?= $stats['passed'] ?></div>
                    </div>
                </div>
                <div class="er-kpi kpi-fail">
                    <div class="er-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </div>
                    <div>
                        <div class="er-kpi-label">Failed</div>
                        <div class="er-kpi-value"><?= $stats['failed'] ?></div>
                    </div>
                </div>
                <div class="er-kpi kpi-avg">
                    <div class="er-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    </div>
                    <div>
                        <div class="er-kpi-label">Average Score</div>
                        <div class="er-kpi-value"><?= $stats['avg'] ?>%</div>
                    </div>
                </div>
                <div class="er-kpi kpi-rate">
                    <div class="er-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    </div>
                    <div>
                        <div class="er-kpi-label">Pass Rate</div>
                        <div class="er-kpi-value"><?= $passRate ?>%</div>
                    </div>
                </div>
            </div>

            <!-- Status Tabs -->
            <div class="er-tabs">
                <button class="er-tab active" onclick="filterCards('all', this)">
                    All <span class="er-tab-count"><?= $stats['total'] ?></span>
                </button>
                <button class="er-tab" onclick="filterCards('pass', this)">
                    ✅ Passed <span class="er-tab-count"><?= $stats['passed'] ?></span>
                </button>
                <button class="er-tab" onclick="filterCards('fail', this)">
                    ❌ Failed <span class="er-tab-count"><?= $stats['failed'] ?></span>
                </button>
            </div>

            <!-- Filter -->
            <div class="er-filter-bar">
                <label>🔍</label>
                <input type="text" id="erSearch" placeholder="Search by student name, exam title..." oninput="applyFilters()">
            </div>

            <!-- Result Cards -->
            <?php if (empty($submissions)): ?>
            <div class="er-empty">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="1.5">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                </svg>
                <h4>No Exam Results Yet</h4>
                <p>Once your students take exams via the Exam Portal, their results will appear here automatically with pass/fail status and score breakdown.</p>
            </div>
            <?php else: ?>

            <div class="er-cards-grid" id="cardsGrid">
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
                    $submittedAt = !empty($sub['submitted_at']) ? date('d M Y', strtotime($sub['submitted_at'])) : '—';
                    $studentName = $sub['student_name'] ?? ($sub['student']['name'] ?? 'Unknown');
                    $studentId = $sub['student']['identifier'] ?? '';
                    $examTitle = $sub['exam_title'] ?? ($sub['exam']['title'] ?? 'Exam');
                    $initial = strtoupper(mb_substr($studentName, 0, 1));
                ?>
                <div class="er-card" data-result="<?= $result ?>" data-search="<?= htmlspecialchars(strtolower($studentName . ' ' . $studentId . ' ' . $examTitle)) ?>">
                    <div class="er-card-header">
                        <div class="er-card-avatar <?= $result ?>-bg"><?= $initial ?></div>
                        <div>
                            <div class="er-card-name"><?= htmlspecialchars($studentName) ?></div>
                            <div class="er-card-id"><?= htmlspecialchars($studentId) ?></div>
                        </div>
                        <div class="er-score-circle <?= $scoreClass ?>"><?= $score ?>%</div>
                    </div>
                    <div class="er-card-body">
                        <div class="er-card-exam">📝 <?= htmlspecialchars($examTitle) ?></div>
                        <div class="er-card-stats">
                            <div class="er-card-stat">
                                <div class="er-card-stat-value"><?= $correct ?>/<?= $totalQ ?></div>
                                <div class="er-card-stat-label">Correct</div>
                            </div>
                            <div class="er-card-stat">
                                <div class="er-card-stat-value"><?= $dMin ?>m <?= $dSec ?>s</div>
                                <div class="er-card-stat-label">Duration</div>
                            </div>
                            <div class="er-card-stat">
                                <div class="er-card-stat-value"><span class="er-card-badge <?= $result ?>"><?= $result === 'pass' ? '✅ PASS' : '❌ FAIL' ?></span></div>
                                <div class="er-card-stat-label">Result</div>
                            </div>
                        </div>
                    </div>
                    <div class="er-card-footer">
                        <button class="er-card-btn" onclick="showDetail(<?= $i ?>)">📋 View Details</button>
                        <?php if ($result === 'pass'): ?>
                        <a class="er-card-btn cert"
                           href="../admin/generate_course_certificate.php?reg_id=<?= urlencode($studentId) ?>&score=<?= intval($score) ?>&exam_date=<?= urlencode(date('Y-m-d', strtotime($sub['submitted_at'] ?? 'now'))) ?>&preview=1"
                           target="_blank"
                           style="text-decoration:none;display:block;text-align:center;">
                            🏆 Certificate
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
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
            <h3>📊 Exam Result Details</h3>
            <button class="er-modal-close" onclick="closeDetail()">✕</button>
        </div>
        <div class="er-modal-body" id="detailContent"></div>
    </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
const submissions = <?= json_encode($submissions) ?>;
let currentFilter = 'all';

function filterCards(filter, btn) {
    currentFilter = filter;
    document.querySelectorAll('.er-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    applyFilters();
}

function applyFilters() {
    const search = document.getElementById('erSearch').value.toLowerCase().trim();
    document.querySelectorAll('.er-card').forEach(card => {
        const result = card.dataset.result;
        const text = card.dataset.search || '';
        const matchFilter = (currentFilter === 'all' || result === currentFilter);
        const matchSearch = (!search || text.includes(search));
        card.style.display = (matchFilter && matchSearch) ? '' : 'none';
    });
}

function showDetail(index) {
    const sub = submissions[index];
    if (!sub) return;
    const name = sub.student_name || (sub.student ? sub.student.name : 'Unknown');
    const id = sub.student ? sub.student.identifier : '';
    const score = parseInt(sub.score || 0);
    const result = (sub.result || 'fail').toLowerCase();
    const dur = parseInt(sub.duration_taken || 0);
    const exam = sub.exam_title || (sub.exam ? sub.exam.title : 'Exam');
    const date = sub.submitted_at ? new Date(sub.submitted_at).toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';

    let html = `
        <div class="er-detail-grid">
            <div><div class="er-detail-label">Student</div><div class="er-detail-value">${name}</div></div>
            <div><div class="er-detail-label">ID</div><div class="er-detail-value" style="font-family:var(--mono)">${id}</div></div>
            <div><div class="er-detail-label">Exam</div><div class="er-detail-value">${exam}</div></div>
            <div><div class="er-detail-label">Score</div><div class="er-detail-value">${score}% — <span style="font-size:.75rem;font-weight:800;color:${result==='pass'?'#065f46':'#991b1b'}">${result.toUpperCase()}</span></div></div>
            <div><div class="er-detail-label">Correct</div><div class="er-detail-value">${sub.correct_answers}/${sub.total_questions}</div></div>
            <div><div class="er-detail-label">Duration</div><div class="er-detail-value">${Math.floor(dur/60)}m ${dur%60}s</div></div>
            <div><div class="er-detail-label">Submitted</div><div class="er-detail-value">${date}</div></div>
        </div>
    `;
    document.getElementById('detailContent').innerHTML = html;
    document.getElementById('detailModal').classList.add('active');
}

function closeDetail() { document.getElementById('detailModal').classList.remove('active'); }
document.getElementById('detailModal').addEventListener('click', function(e) { if(e.target===this) closeDetail(); });
</script>
</body>
</html>
