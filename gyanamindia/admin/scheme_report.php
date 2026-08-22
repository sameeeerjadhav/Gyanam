<?php
/**
 * Gyanam Portal — Head Office: Scheme Report
 * All ATCs' scheme progress, benefits unlocked/pending/expired. Full export.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo = getDBConnection();

// Auto-sync progress before rendering
try {
    $schemes = $pdo->query("SELECT s.id,s.trigger_count,s.benefit_value,s.start_date,s.end_date,sa.atc_id
        FROM schemes s JOIN scheme_assignments sa ON sa.scheme_id=s.id WHERE s.status='Active'")->fetchAll(PDO::FETCH_ASSOC);
    $upd = $pdo->prepare("UPDATE scheme_progress SET current_count=?,benefit_unlocked=?,unlocked_at=IF(?,NOW(),unlocked_at) WHERE scheme_id=? AND atc_id=?");
    $cntS = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE atc_id=? AND status='Active' AND admission_date BETWEEN ? AND ?");
    foreach ($schemes as $s) {
        $cntS->execute([$s['atc_id'], $s['start_date'], $s['end_date']]);
        $cnt = intval($cntS->fetchColumn());
        $triggerCount = intval($s['trigger_count']);
        if ($triggerCount <= 0) {
            $upd->execute([$cnt, 0, 0, $s['id'], $s['atc_id']]);
            continue;
        }
        $ul = intdiv($cnt, $triggerCount);
        $upd->execute([$cnt, $ul, ($ul > 0), $s['id'], $s['atc_id']]);
    }
} catch (Exception $e) {}

// Fetch full report data
$reportRows = $pdo->query("
    SELECT
        ac.name        AS atc_name,
        s.name         AS scheme_name,
        s.scheme_type,
        s.trigger_count,
        s.benefit_type,
        s.benefit_value,
        s.start_date,
        s.end_date,
        s.status       AS scheme_status,
        sp.current_count,
        sp.benefit_unlocked,
        sp.unlocked_at,
        CASE
            WHEN s.status = 'Expired' AND COALESCE(sp.benefit_unlocked, 0) = 0 THEN 'Expired'
            WHEN COALESCE(sp.benefit_unlocked, 0) > 0 THEN 'Unlocked'
            ELSE 'Pending'
        END AS benefit_status,
        CASE
            WHEN COALESCE(s.trigger_count, 0) > 0
                THEN ROUND((COALESCE(sp.current_count, 0) / s.trigger_count) * 100, 0)
            ELSE 0
        END AS progress_pct
    FROM scheme_progress sp
    JOIN schemes s       ON s.id  = sp.scheme_id
    JOIN atc_centers ac  ON ac.id = sp.atc_id
    ORDER BY s.status ASC, ac.name ASC, s.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$totalAtcs       = count(array_unique(array_column($reportRows, 'atc_name')));
$totalUnlocked   = array_sum(array_column($reportRows, 'benefit_unlocked'));
$pendingBenefits = count(array_filter($reportRows, fn($r) => $r['benefit_status'] === 'Pending'));
$totalEntries    = count($reportRows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scheme Report — Head Office | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%234361ee' stroke-width='2'%3E%3Cline x1='18' y1='20' x2='18' y2='10'/%3E%3Cline x1='12' y1='20' x2='12' y2='4'/%3E%3Cline x1='6' y1='20' x2='6' y2='14'/%3E%3C/svg%3E">
    <style>
    :root {
        --font: 'Sora', sans-serif;
        --mono: 'JetBrains Mono', monospace;
        --brand: #4361ee;
        --brand-dk: #3730a3;
        --brand-lt: #eef2ff;
        --emerald: #059669;
        --emerald-lt: #ecfdf5;
        --amber: #d97706;
        --amber-lt: #fffbeb;
        --rose: #dc2626;
        --rose-lt: #fef2f2;
        --violet: #7c3aed;
        --violet-lt: #f5f3ff;
        --radius: 14px;
        --shadow-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
    }

    body { font-family: var(--font); }

    .sr-page { padding: 1.75rem 2rem; width: 100%; box-sizing: border-box; }

    /* KPI */
    .sr-kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    .sr-kpi {
        background: #fff;
        border: 1.5px solid #e5e7eb;
        border-radius: var(--radius);
        padding: 1.2rem 1.35rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        position: relative;
        overflow: hidden;
        box-shadow: var(--shadow-sm);
        transition: transform .2s, box-shadow .2s;
    }
    .sr-kpi:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.06); }
    .sr-kpi::before {
        content: '';
        position: absolute;
        top: 0; left: 0;
        width: 4px; height: 100%;
    }
    .sr-kpi.blue::before { background: linear-gradient(180deg, var(--brand), var(--brand-dk)); }
    .sr-kpi.green::before { background: linear-gradient(180deg, #10b981, var(--emerald)); }
    .sr-kpi.amber::before { background: linear-gradient(180deg, #f59e0b, var(--amber)); }
    .sr-kpi.violet::before { background: linear-gradient(180deg, #8b5cf6, var(--violet)); }

    .sr-kpi-icon {
        width: 44px; height: 44px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .sr-kpi-icon svg { width: 20px; height: 20px; stroke: currentColor; fill: none; stroke-width: 2; }
    .sr-kpi.blue .sr-kpi-icon { background: var(--brand-lt); color: var(--brand); }
    .sr-kpi.green .sr-kpi-icon { background: var(--emerald-lt); color: var(--emerald); }
    .sr-kpi.amber .sr-kpi-icon { background: var(--amber-lt); color: var(--amber); }
    .sr-kpi.violet .sr-kpi-icon { background: var(--violet-lt); color: var(--violet); }

    .sr-kpi-label {
        font-size: .7rem; font-weight: 800; color: #6b7280;
        text-transform: uppercase; letter-spacing: .05em;
    }
    .sr-kpi-value {
        font-size: 1.7rem; font-weight: 800; color: #111827;
        line-height: 1; margin-top: .2rem; letter-spacing: -.03em;
    }

    /* Toolbar */
    .sr-toolbar {
        display: flex; align-items: center; justify-content: space-between;
        gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem;
    }
    .sr-back {
        display: inline-flex; align-items: center; gap: .4rem;
        padding: .55rem 1rem; border-radius: 10px;
        font-weight: 700; font-size: .82rem; color: #374151;
        border: 1.5px solid #e5e7eb; background: #fff;
        text-decoration: none; transition: all .15s; font-family: var(--font);
    }
    .sr-back:hover { border-color: #a5b4fc; color: var(--brand); background: var(--brand-lt); }
    .sr-back svg { width: 15px; height: 15px; stroke: currentColor; fill: none; stroke-width: 2; }

    /* Card */
    .sr-card {
        background: #fff;
        border: 1.5px solid #e5e7eb;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: var(--shadow-sm);
    }
    .sr-card-head {
        display: flex; align-items: center; justify-content: space-between;
        gap: 1rem; flex-wrap: wrap;
        padding: 1.15rem 1.4rem;
        border-bottom: 1px solid #f1f5f9;
        background: #fafbfc;
    }
    .sr-card-title {
        font-size: 1rem; font-weight: 800; color: #111827; margin: 0;
        display: flex; align-items: center; gap: .5rem;
    }
    .sr-card-title svg { width: 18px; height: 18px; stroke: var(--brand); fill: none; stroke-width: 2; }
    .sr-card-sub { font-size: .78rem; color: #94a3b8; font-weight: 500; margin-top: .15rem; }
    .sr-card-body { padding: 1.15rem 1.4rem 1.4rem; overflow-x: auto; }

    /* Progress bar */
    .sr-progress {
        display: flex; align-items: center; gap: .5rem; min-width: 120px;
    }
    .sr-progress-track {
        flex: 1; background: #e5e7eb; border-radius: 999px; height: 7px; min-width: 70px; overflow: hidden;
    }
    .sr-progress-fill {
        height: 100%; border-radius: 999px; transition: width .3s;
    }
    .sr-progress-fill.done { background: var(--emerald); }
    .sr-progress-fill.mid { background: var(--brand); }
    .sr-progress-text {
        font-family: var(--mono); font-size: .72rem; font-weight: 700;
        color: #475569; white-space: nowrap;
    }

    /* Status chips */
    .sr-chip {
        display: inline-flex; align-items: center;
        font-size: .7rem; font-weight: 800; padding: .2rem .6rem;
        border-radius: 999px; letter-spacing: .02em;
    }
    .sr-chip.active { background: var(--emerald-lt); color: var(--emerald); }
    .sr-chip.expired { background: var(--rose-lt); color: var(--rose); }
    .sr-chip.inactive { background: #f3f4f6; color: #6b7280; }
    .sr-chip.unlocked { background: var(--emerald-lt); color: var(--emerald); }
    .sr-chip.pending { background: var(--amber-lt); color: var(--amber); }

    /* Empty */
    .sr-empty {
        text-align: center; padding: 3.5rem 1.5rem; color: #94a3b8;
    }
    .sr-empty svg {
        width: 48px; height: 48px; stroke: #d1d5db; fill: none; stroke-width: 1.5;
        display: block; margin: 0 auto .85rem;
    }
    .sr-empty h4 { font-size: 1rem; font-weight: 700; color: #374151; margin: 0 0 .4rem; }
    .sr-empty p { font-size: .85rem; margin: 0; line-height: 1.55; max-width: 420px; margin-inline: auto; }

    /* DataTables polish */
    .top-controls {
        display: flex; justify-content: space-between; align-items: center;
        margin-bottom: 1.1rem; flex-wrap: wrap; gap: .85rem;
    }
    .bottom-controls {
        display: flex; justify-content: space-between; align-items: center;
        padding-top: 1rem; margin-top: 1rem;
        border-top: 1px solid #f1f5f9; font-size: .82rem; color: #64748b;
    }
    div.dataTables_wrapper .dt-buttons {
        display: flex; gap: .45rem; margin-bottom: 0; flex-wrap: wrap;
    }
    .rpt-btn {
        display: inline-flex !important;
        align-items: center !important;
        gap: .35rem !important;
        padding: .45rem .85rem !important;
        border-radius: 9px !important;
        font-size: .78rem !important;
        font-weight: 700 !important;
        font-family: var(--font) !important;
        border: 1.5px solid transparent !important;
        cursor: pointer !important;
        margin: 0 !important;
        transition: all .15s !important;
        box-shadow: none !important;
        text-shadow: none !important;
    }
    .rpt-btn svg { width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2; flex-shrink: 0; }
    .rpt-btn:hover { transform: translateY(-1px) !important; filter: none !important; }
    .buttons-copy { background: #f8fafc !important; color: #475569 !important; border-color: #e2e8f0 !important; }
    .buttons-copy:hover { background: #f1f5f9 !important; border-color: #cbd5e1 !important; }
    .buttons-excel { background: var(--emerald-lt) !important; color: var(--emerald) !important; border-color: #a7f3d0 !important; }
    .buttons-excel:hover { background: #d1fae5 !important; }
    .buttons-csv { background: #f0f9ff !important; color: #0284c7 !important; border-color: #bae6fd !important; }
    .buttons-csv:hover { background: #e0f2fe !important; }
    .buttons-pdf { background: var(--rose-lt) !important; color: var(--rose) !important; border-color: #fecaca !important; }
    .buttons-pdf:hover { background: #fee2e2 !important; }
    .buttons-print { background: var(--violet-lt) !important; color: var(--violet) !important; border-color: #ddd6fe !important; }
    .buttons-print:hover { background: #ede9fe !important; }

    div.dataTables_wrapper div.dataTables_filter input {
        border: 1.5px solid #e5e7eb;
        border-radius: 10px;
        padding: .5rem .85rem;
        font-family: var(--font);
        font-size: .82rem;
        outline: none;
        background: #f9fafb;
        transition: border-color .2s, box-shadow .2s, background .2s;
        min-width: 200px;
    }
    div.dataTables_wrapper div.dataTables_filter input:focus {
        border-color: var(--brand);
        background: #fff;
        box-shadow: 0 0 0 3px rgba(67,97,238,.1);
    }

    #rptTable {
        width: 100% !important;
        border-collapse: collapse;
        font-size: .82rem;
    }
    #rptTable thead th {
        padding: .75rem .9rem;
        text-align: left;
        font-size: .68rem;
        font-weight: 800;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: .05em;
        border-bottom: 1px solid #e5e7eb;
        background: #f8fafc;
        white-space: nowrap;
    }
    #rptTable tbody td {
        padding: .75rem .9rem;
        vertical-align: middle;
        border-bottom: 1px solid #f1f5f9;
        color: #1f2937;
    }
    #rptTable tbody tr:hover { background: #f8fafc; }
    #rptTable tbody tr:last-child td { border-bottom: none; }

    div.dataTables_wrapper div.dataTables_paginate .paginate_button {
        border-radius: 8px !important;
        border: 1px solid transparent !important;
        font-family: var(--font) !important;
        font-weight: 600 !important;
        font-size: .8rem !important;
    }
    div.dataTables_wrapper div.dataTables_paginate .paginate_button.current {
        background: var(--brand-lt) !important;
        color: var(--brand) !important;
        border-color: #c7d2fe !important;
    }

    @media (max-width: 900px) {
        .sr-page { padding: 1.25rem; }
        .sr-kpi-grid { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 640px) {
        .sr-kpi-grid { grid-template-columns: 1fr; }
        .top-controls { flex-direction: column-reverse; align-items: stretch; }
        div.dataTables_wrapper div.dataTables_filter,
        div.dataTables_wrapper div.dataTables_filter input { width: 100%; }
        div.dataTables_wrapper .dt-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            width: 100%;
        }
        .rpt-btn { justify-content: center !important; width: 100%; }
        .bottom-controls { flex-direction: column; gap: .75rem; text-align: center; }
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
                    <h2>Scheme Progress Report</h2>
                    <p>All ATC scheme progress — benefits unlocked, pending, expired</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="sr-page">

            <div class="sr-kpi-grid">
                <div class="sr-kpi blue">
                    <div class="sr-kpi-icon">
                        <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div>
                        <div class="sr-kpi-label">ATCs in Schemes</div>
                        <div class="sr-kpi-value"><?= $totalAtcs ?></div>
                    </div>
                </div>
                <div class="sr-kpi green">
                    <div class="sr-kpi-icon">
                        <svg viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                    </div>
                    <div>
                        <div class="sr-kpi-label">Benefits Unlocked</div>
                        <div class="sr-kpi-value"><?= $totalUnlocked ?></div>
                    </div>
                </div>
                <div class="sr-kpi amber">
                    <div class="sr-kpi-icon">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div>
                        <div class="sr-kpi-label">Benefits Pending</div>
                        <div class="sr-kpi-value"><?= $pendingBenefits ?></div>
                    </div>
                </div>
                <div class="sr-kpi violet">
                    <div class="sr-kpi-icon">
                        <svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    </div>
                    <div>
                        <div class="sr-kpi-label">Total Entries</div>
                        <div class="sr-kpi-value"><?= $totalEntries ?></div>
                    </div>
                </div>
            </div>

            <div class="sr-toolbar">
                <a href="schemes.php" class="sr-back">
                    <svg viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                    Back to Schemes
                </a>
            </div>

            <div class="sr-card">
                <div class="sr-card-head">
                    <div>
                        <h3 class="sr-card-title">
                            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            ATC Scheme Progress
                        </h3>
                        <div class="sr-card-sub">Live progress across all assigned schemes</div>
                    </div>
                </div>
                <div class="sr-card-body">
                    <?php if (empty($reportRows)): ?>
                        <div class="sr-empty">
                            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            <h4>No scheme progress yet</h4>
                            <p>Create and assign schemes first. Progress will appear here once ATCs start admitting students within the scheme period.</p>
                        </div>
                    <?php else: ?>
                    <table id="rptTable" class="display" style="width:100%">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>ATC</th>
                                <th>Scheme Name</th>
                                <th>Type</th>
                                <th>Trigger</th>
                                <th>Benefit</th>
                                <th>Progress</th>
                                <th>Unlocked</th>
                                <th>Period</th>
                                <th>Scheme Status</th>
                                <th>Benefit Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportRows as $i => $r):
                                $pct = max(0, min(100, intval($r['progress_pct'])));
                                $sc = $r['scheme_status'];
                                $bs = $r['benefit_status'];
                                $scClass = $sc === 'Active' ? 'active' : ($sc === 'Expired' ? 'expired' : 'inactive');
                                $bsClass = $bs === 'Unlocked' ? 'unlocked' : ($bs === 'Expired' ? 'expired' : 'pending');
                            ?>
                                <tr>
                                    <td style="color:#94a3b8;font-size:.78rem"><?= $i + 1 ?></td>
                                    <td style="font-weight:700"><?= htmlspecialchars($r['atc_name']) ?></td>
                                    <td><?= htmlspecialchars($r['scheme_name']) ?></td>
                                    <td><?= htmlspecialchars($r['scheme_type']) ?></td>
                                    <td style="font-family:var(--mono);font-weight:600"><?= $r['trigger_count'] ?></td>
                                    <td><?= htmlspecialchars($r['benefit_type']) ?> × <?= $r['benefit_value'] ?></td>
                                    <td class="no-export">
                                        <div class="sr-progress">
                                            <div class="sr-progress-track">
                                                <div class="sr-progress-fill <?= $pct >= 100 ? 'done' : 'mid' ?>" style="width:<?= $pct ?>%"></div>
                                            </div>
                                            <span class="sr-progress-text"><?= $r['current_count'] ?>/<?= $r['trigger_count'] ?></span>
                                        </div>
                                    </td>
                                    <td style="text-align:center;font-weight:800;color:var(--brand);font-family:var(--mono)"><?= $r['benefit_unlocked'] ?></td>
                                    <td style="font-size:.78rem;color:#64748b;white-space:nowrap"><?= date('d M Y', strtotime($r['start_date'])) ?> – <?= date('d M Y', strtotime($r['end_date'])) ?></td>
                                    <td><span class="sr-chip <?= $scClass ?>"><?= htmlspecialchars($sc) ?></span></td>
                                    <td><span class="sr-chip <?= $bsClass ?>"><?= htmlspecialchars($bs) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="../assets/js/dashboard.js"></script>
<?php if (!empty($reportRows)): ?>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script>
$(document).ready(function () {
    const icon = (paths) =>
        `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${paths}</svg>`;

    $('#rptTable').DataTable({
        dom: '<"top-controls"Bf>rt<"bottom-controls"ip>',
        buttons: [
            {
                extend: 'copy',
                text: icon('<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>') + ' Copy',
                className: 'rpt-btn buttons-copy',
                exportOptions: { columns: ':not(.no-export)' }
            },
            {
                extend: 'excel',
                text: icon('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>') + ' Excel',
                className: 'rpt-btn buttons-excel',
                title: 'Scheme Report',
                exportOptions: { columns: ':not(.no-export)' }
            },
            {
                extend: 'csv',
                text: icon('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>') + ' CSV',
                className: 'rpt-btn buttons-csv',
                exportOptions: { columns: ':not(.no-export)' }
            },
            {
                extend: 'pdf',
                text: icon('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>') + ' PDF',
                className: 'rpt-btn buttons-pdf',
                title: 'Scheme Progress Report',
                exportOptions: { columns: ':not(.no-export)' }
            },
            {
                extend: 'print',
                text: icon('<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>') + ' Print',
                className: 'rpt-btn buttons-print',
                title: 'Scheme Progress Report',
                exportOptions: { columns: ':not(.no-export)' }
            }
        ],
        pageLength: 25,
        order: [[9, 'asc']],
        language: {
            search: '',
            searchPlaceholder: 'Search report…',
            emptyTable: 'No scheme progress data available.',
            zeroRecords: 'No matching records found.'
        }
    });
});
</script>
<?php endif; ?>
</body>
</html>
