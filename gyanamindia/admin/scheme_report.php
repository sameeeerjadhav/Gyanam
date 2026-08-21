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
        $cntS->execute([$s['atc_id'],$s['start_date'],$s['end_date']]);
        $cnt = intval($cntS->fetchColumn());
        $triggerCount = intval($s['trigger_count']);
        if ($triggerCount <= 0) {
            $upd->execute([$cnt,0,0,$s['id'],$s['atc_id']]);
            continue;
        }
        $ul  = intdiv($cnt, $triggerCount);
        $upd->execute([$cnt,$ul,($ul>0),$s['id'],$s['atc_id']]);
    }
} catch(Exception $e){}

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

$totalAtcs    = count(array_unique(array_column($reportRows,'atc_name')));
$totalUnlocked = array_sum(array_column($reportRows,'benefit_unlocked'));
$pendingBenefits = count(array_filter($reportRows, fn($r) => $r['benefit_status']==='Pending'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Scheme Report — Head Office | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📊</text></svg>">
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <button class="hamburger" id="hamburgerBtn"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
                <div class="header-greeting">
                    <h2>📊 Scheme Progress Report</h2>
                    <p>All ATC scheme progress — benefits unlocked, pending, expired</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="sc-container">

            <!-- KPI -->
            <div class="sc-kpi-grid">
                <div class="sc-kpi-card sc-kpi-blue">
                    <div class="sc-kpi-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg></div>
                    <div class="sc-kpi-content"><div class="sc-kpi-value"><?= $totalAtcs ?></div><div class="sc-kpi-label">ATCs in Schemes</div></div>
                </div>
                <div class="sc-kpi-card sc-kpi-green">
                    <div class="sc-kpi-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg></div>
                    <div class="sc-kpi-content"><div class="sc-kpi-value"><?= $totalUnlocked ?></div><div class="sc-kpi-label">Benefits Unlocked</div></div>
                </div>
                <div class="sc-kpi-card sc-kpi-amber">
                    <div class="sc-kpi-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
                    <div class="sc-kpi-content"><div class="sc-kpi-value"><?= $pendingBenefits ?></div><div class="sc-kpi-label">Benefits Pending</div></div>
                </div>
                <div class="sc-kpi-card sc-kpi-purple">
                    <div class="sc-kpi-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></div>
                    <div class="sc-kpi-content"><div class="sc-kpi-value"><?= count($reportRows) ?></div><div class="sc-kpi-label">Total Entries</div></div>
                </div>
            </div>

            <!-- Back + actions -->
            <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.5rem;flex-wrap:wrap">
                <a href="schemes.php" style="display:inline-flex;align-items:center;gap:.4rem;padding:0 1.1rem;height:38px;border-radius:9px;font-weight:700;font-size:.85rem;color:#374151;border:1.5px solid #e5e7eb;background:#fff;text-decoration:none">
                    ← Back to Schemes
                </a>
            </div>

            <!-- DataTable -->
            <div class="sc-table-card">
                <div style="padding:1.25rem 1.5rem;border-bottom:1px solid #f3f4f6">
                    <h3 style="font-size:1.1rem;font-weight:800;color:#1f2937;margin:0">ATC Scheme Progress</h3>
                </div>
                <div style="overflow-x:auto;padding:1rem 1.5rem 1.5rem">
                    <table id="rptTable" class="display" style="width:100%">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>ATC Center</th>
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
                            <?php foreach ($reportRows as $i => $r): ?>
                                <?php $pct = max(0, min(100, intval($r['progress_pct']))); ?>
                                <tr>
                                    <td><?= $i+1 ?></td>
                                    <td><?= htmlspecialchars($r['atc_name']) ?></td>
                                    <td><?= htmlspecialchars($r['scheme_name']) ?></td>
                                    <td><?= htmlspecialchars($r['scheme_type']) ?></td>
                                    <td><?= $r['trigger_count'] ?></td>
                                    <td><?= htmlspecialchars($r['benefit_type']) ?> × <?= $r['benefit_value'] ?></td>
                                    <td class="no-export">
                                        <div style="display:flex;align-items:center;gap:.5rem">
                                            <div style="flex:1;background:#e5e7eb;border-radius:999px;height:8px;min-width:80px">
                                                <div style="height:8px;border-radius:999px;background:<?= $pct>=100?'#059669':'#4361ee' ?>;width:<?= $pct ?>%"></div>
                                            </div>
                                            <span style="font-family:monospace;font-size:.78rem;font-weight:700;color:#374151;white-space:nowrap"><?= $r['current_count'] ?>/<?= $r['trigger_count'] ?></span>
                                        </div>
                                    </td>
                                    <td style="text-align:center;font-weight:800;color:#4361ee;font-family:monospace"><?= $r['benefit_unlocked'] ?></td>
                                    <td style="font-size:.78rem"><?= date('d M Y',strtotime($r['start_date'])) ?> – <?= date('d M Y',strtotime($r['end_date'])) ?></td>
                                    <td>
                                        <?php $sc = $r['scheme_status']; ?>
                                        <span style="font-size:.72rem;font-weight:700;padding:.2rem .6rem;border-radius:999px;background:<?= $sc==='Active'?'#d1fae5':($sc==='Expired'?'#fee2e2':'#f3f4f6') ?>;color:<?= $sc==='Active'?'#059669':($sc==='Expired'?'#dc2626':'#6b7280') ?>"><?= $sc ?></span>
                                    </td>
                                    <td>
                                        <?php $bs = $r['benefit_status']; ?>
                                        <span style="font-size:.72rem;font-weight:700;padding:.2rem .6rem;border-radius:999px;background:<?= $bs==='Unlocked'?'#d1fae5':($bs==='Expired'?'#fee2e2':'#fef3c7') ?>;color:<?= $bs==='Unlocked'?'#059669':($bs==='Expired'?'#dc2626':'#d97706') ?>"><?= $bs ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="../assets/js/dashboard.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script>
$(document).ready(function(){
    $('#rptTable').DataTable({
        dom: '<"top-controls"Bf>rt<"bottom-controls"ip>',
        buttons: [
            { extend: 'copy', text: '<span style="font-size:1.05rem">📋</span> Copy', className: 'rpt-btn buttons-copy', exportOptions: { columns: ':not(.no-export)' } },
            { extend: 'excel', text: '<span style="font-size:1.05rem">📊</span> Excel', className: 'rpt-btn buttons-excel', title: 'Scheme Report', exportOptions: { columns: ':not(.no-export)' } },
            { extend: 'csv', text: '<span style="font-size:1.05rem">📄</span> CSV', className: 'rpt-btn buttons-csv', exportOptions: { columns: ':not(.no-export)' } },
            { extend: 'pdf', text: '<span style="font-size:1.05rem">📕</span> PDF', className: 'rpt-btn buttons-pdf', title: 'Scheme Progress Report', exportOptions: { columns: ':not(.no-export)' } },
            { extend: 'print', text: '<span style="font-size:1.05rem">🖨️</span> Print', className: 'rpt-btn buttons-print', title: 'Scheme Progress Report', exportOptions: { columns: ':not(.no-export)' } }
        ],
        pageLength: 25,
        order: [[9, 'asc']],
        language: {
            search: "",
            searchPlaceholder: "Search report..."
        }
    });
});
</script>
<style>
/* Modern DataTable Controls Layout */
.top-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
    gap: 1rem;
}
.bottom-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 1rem;
    margin-top: 1rem;
    border-top: 1px solid #f3f4f6;
    font-size: 0.85rem;
}
div.dataTables_wrapper .dt-buttons {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 0;
    flex-wrap: wrap;
}
:root{--font:'Sora',sans-serif;--mono:'JetBrains Mono',monospace;--brand:#4361ee;--purple:#8b5cf6;--emerald:#10b981;--amber:#f59e0b;}
body{font-family:var(--font)}
.sc-container{padding:1.75rem 2rem;max-width:1400px;margin:0 auto}
.sc-kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1.25rem;margin-bottom:1.75rem}
@media(max-width:1100px){.sc-kpi-grid{grid-template-columns:repeat(2,1fr)}}
.sc-kpi-card{border-radius:16px;padding:1.25rem 1.5rem;display:flex;align-items:center;gap:1rem;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1.5px solid;border-left-width:4px}
.sc-kpi-blue{background:linear-gradient(135deg,#eef1fd,#e0e7ff);border-color:#c7d2fe;border-left-color:var(--brand)}
.sc-kpi-green{background:linear-gradient(135deg,#d1fae5,#a7f3d0);border-color:#6ee7b7;border-left-color:var(--emerald)}
.sc-kpi-purple{background:linear-gradient(135deg,#ede9fe,#ddd6fe);border-color:#c4b5fd;border-left-color:var(--purple)}
.sc-kpi-amber{background:linear-gradient(135deg,#fef3c7,#fde68a);border-color:#fcd34d;border-left-color:var(--amber)}
.sc-kpi-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,.22)}
.sc-kpi-blue .sc-kpi-icon{background:linear-gradient(135deg,var(--brand),#3730a3)}
.sc-kpi-green .sc-kpi-icon{background:linear-gradient(135deg,var(--emerald),#059669)}
.sc-kpi-purple .sc-kpi-icon{background:linear-gradient(135deg,var(--purple),#7c3aed)}
.sc-kpi-amber .sc-kpi-icon{background:linear-gradient(135deg,var(--amber),#d97706)}
.sc-kpi-icon svg{width:22px;height:22px;stroke:#fff}
.sc-kpi-value{font-size:1.6rem;font-weight:800;font-family:var(--mono);line-height:1}
.sc-kpi-blue .sc-kpi-value{color:var(--brand)}.sc-kpi-green .sc-kpi-value{color:var(--emerald)}.sc-kpi-purple .sc-kpi-value{color:var(--purple)}.sc-kpi-amber .sc-kpi-value{color:#d97706}
.sc-kpi-label{font-size:.78rem;font-weight:600;color:#6b7280;margin-top:.2rem}
.sc-table-card{background:#fff;border:1.5px solid #e5e7eb;border-radius:18px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04)}
/* Enhanced Export Buttons */
.rpt-btn { 
    display: inline-flex !important; 
    align-items: center !important; 
    gap: 0.4rem !important; 
    padding: 0.5rem 1rem !important; 
    border-radius: 8px !important; 
    font-size: 0.8rem !important; 
    font-weight: 700 !important; 
    color: #fff !important; 
    border: none !important; 
    cursor: pointer !important; 
    margin-right: 0.5rem !important; 
    margin-bottom: 0.5rem !important;
    text-shadow: 0 1px 1px rgba(0,0,0,0.2) !important;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1), inset 0 1px 0 rgba(255,255,255,0.15) !important;
    transition: all 0.25s ease !important;
}

.rpt-btn:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 4px 10px rgba(0,0,0,0.15), inset 0 1px 0 rgba(255,255,255,0.2) !important;
    filter: brightness(1.1) !important;
}

.rpt-btn:active {
    transform: translateY(0) !important;
    box-shadow: 0 1px 2px rgba(0,0,0,0.1) !important;
}

.buttons-copy { background: linear-gradient(135deg, #4b5563, #374151) !important; }
.buttons-excel { background: linear-gradient(135deg, #10b981, #059669) !important; }
.buttons-csv { background: linear-gradient(135deg, #0ea5e9, #0284c7) !important; }
.buttons-pdf { background: linear-gradient(135deg, #ef4444, #dc2626) !important; }
.buttons-print { background: linear-gradient(135deg, #8b5cf6, #7c3aed) !important; }

div.dataTables_wrapper div.dataTables_filter input { 
    border: 1.5px solid #e5e7eb; 
    border-radius: 8px; 
    padding: 0.45rem 0.85rem; 
    font-family: var(--font); 
    font-size: 0.85rem; 
    outline: none;
    transition: border-color 0.2s;
}
div.dataTables_wrapper div.dataTables_filter input:focus {
    border-color: var(--brand);
}
div.dataTables_wrapper .dt-buttons {
    margin-bottom: 1rem;
}

/* Responsive Mobile Layout */
@media (max-width: 768px) {
    .sc-container { 
        padding: 1rem; 
    }
    .sc-kpi-grid { 
        grid-template-columns: 1fr; 
        gap: 1rem; 
        margin-bottom: 1.25rem;
    }
    .sc-kpi-card { 
        padding: 1rem 1.25rem; 
    }
    .sc-table-card > div:first-child {
        padding: 1rem;
    }
    .sc-table-card > div:last-child {
        padding: 0.5rem 1rem 1rem;
    }
    .top-controls {
        flex-direction: column-reverse;
        align-items: stretch;
        gap: 1rem;
    }
    div.dataTables_wrapper div.dataTables_filter {
        text-align: left;
        width: 100%;
        margin-top: 0;
    }
    div.dataTables_wrapper div.dataTables_filter label {
        display: flex;
        flex-direction: column;
        width: 100%;
        gap: 0.4rem;
        font-weight: 600;
        color: #374151;
    }
    div.dataTables_wrapper div.dataTables_filter input {
        width: 100%;
        margin-left: 0;
        box-sizing: border-box;
    }
    div.dataTables_wrapper .dt-buttons {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
        gap: 0.5rem;
        width: 100%;
        margin-bottom: 0;
    }
    .rpt-btn {
        margin: 0 !important;
        justify-content: center;
        width: 100%;
        padding: 0.5rem !important;
    }
    .bottom-controls {
        flex-direction: column;
        gap: 1rem;
        text-align: center;
        padding-top: 1.25rem;
    }
    div.dataTables_wrapper div.dataTables_paginate {
        width: 100%;
        display: flex;
        justify-content: center;
        margin-top: 0.5rem;
    }
}
@media (max-width: 480px) {
    div.dataTables_wrapper .dt-buttons {
        grid-template-columns: 1fr 1fr;
    }
}
</style>
</body>
</html>
