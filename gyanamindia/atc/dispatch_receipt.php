<?php
/**
 * Gyanam Portal — ATC: Dispatch Receipt Print
 * Professional A4-size printable receipt for a dispatch.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['ATC CENTER']);

$pdo   = getDBConnection();
$atcId = $_SESSION['atc_id'] ?? null;
$id    = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$atcId || !$id) die('Invalid request.');

// Fetch dispatch — try with extra ATC columns, fall back if missing
$dispatch = null;
try {
    $stmt = $pdo->prepare("
        SELECT d.*, atc.name AS atc_name, atc.atc_code
        FROM material_dispatches d
        JOIN atc_centers atc ON d.atc_id = atc.id
        WHERE d.id = ? AND d.atc_id = ?
    ");
    $stmt->execute([$id, $atcId]);
    $dispatch = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die('Error loading dispatch: ' . htmlspecialchars($e->getMessage()));
}

if (!$dispatch) die('Dispatch not found or access denied.');

// Fetch items (new system)
$items = [];
try {
    $iStmt = $pdo->prepare("
        SELECT di.item_type, di.item_detail, di.status, di.quantity,
               TRIM(CONCAT(a.first_name,' ',COALESCE(a.middle_name,''),' ',a.last_name)) AS student_name,
               a.roll_no, a.registration_id, a.course,
               inv.item_name AS inv_name, inv.cost AS unit_cost
        FROM dispatch_items di
        JOIN admissions a ON di.admission_id = a.id
        LEFT JOIN inventory_items inv ON di.inventory_item_id = inv.id
        WHERE di.dispatch_id = ?
        ORDER BY a.first_name, di.item_type
    ");
    $iStmt->execute([$id]);
    $items = $iStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Fallback to legacy
if (empty($items)) {
    try {
        $lStmt = $pdo->prepare("
            SELECT a.roll_no, a.registration_id,
                   TRIM(CONCAT(a.first_name,' ',COALESCE(a.middle_name,''),' ',a.last_name)) AS student_name,
                   a.course, a.material_language, a.uniform_size
            FROM material_dispatch_students mds
            JOIN admissions a ON mds.admission_id = a.id
            WHERE mds.dispatch_id = ?
        ");
        $lStmt->execute([$id]);
        foreach ($lStmt->fetchAll(PDO::FETCH_ASSOC) as $ls) {
            if (!empty($ls['material_language'])) {
                $items[] = ['student_name' => $ls['student_name'], 'roll_no' => $ls['roll_no'], 'registration_id' => $ls['registration_id'],
                            'course' => $ls['course'], 'item_type' => 'Book', 'item_detail' => $ls['material_language'],
                            'quantity' => 1, 'status' => 'Dispatched', 'unit_cost' => null];
            }
            if (!empty($ls['uniform_size'])) {
                $items[] = ['student_name' => $ls['student_name'], 'roll_no' => $ls['roll_no'], 'registration_id' => $ls['registration_id'],
                            'course' => $ls['course'], 'item_type' => 'T-Shirt', 'item_detail' => 'Size ' . $ls['uniform_size'],
                            'quantity' => 1, 'status' => 'Dispatched', 'unit_cost' => null];
            }
        }
    } catch (Exception $e) {}
}

// Compute totals
$totalQty  = 0;
$totalCost = 0;
foreach ($items as &$it) {
    $it['quantity'] = max(1, (int)($it['quantity'] ?? 1));
    $it['unit_cost'] = $it['unit_cost'] !== null ? (float)$it['unit_cost'] : null;
    $it['line_total'] = $it['unit_cost'] ? round($it['unit_cost'] * $it['quantity'], 2) : null;
    $totalQty += $it['quantity'];
    if ($it['line_total']) $totalCost += $it['line_total'];
}
unset($it);

// Material type summary
$matSummary = [];
foreach ($items as $it) {
    $key = $it['item_type'] . ' — ' . ($it['item_detail'] ?? '');
    if (!isset($matSummary[$key])) $matSummary[$key] = 0;
    $matSummary[$key] += $it['quantity'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dispatch Receipt — <?= htmlspecialchars($dispatch['dispatch_id']) ?> | Gyanam India Educational Services</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Sora', sans-serif; background: #e8e8e8; min-height: 100vh; display: flex; flex-direction: column; align-items: center; padding: 1.5rem 1rem; }

/* Print controls */
.print-controls { display: flex; gap: .75rem; margin-bottom: 1.25rem; }
.ctrl-btn { display: inline-flex; align-items: center; gap: .4rem; padding: .6rem 1.2rem; border-radius: 10px; font-size: .84rem; font-weight: 700; font-family: 'Sora',sans-serif; cursor: pointer; border: none; transition: all .15s; text-decoration: none; }
.ctrl-print { background: #4361ee; color: #fff; box-shadow: 0 4px 12px rgba(67,97,238,.25); }
.ctrl-print:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(67,97,238,.3); }
.ctrl-back { background: #fff; color: #374151; border: 1.5px solid #e5e7eb; }
.ctrl-back:hover { background: #f9fafb; }
.ctrl-btn svg { width: 14px; height: 14px; }

/* Receipt container */
.receipt { width: 210mm; min-height: 297mm; background: #fff; padding: 18mm 20mm; box-shadow: 0 15px 50px rgba(0,0,0,.15); position: relative; }

/* Header */
.rh { display: flex; align-items: center; gap: 1.25rem; margin-bottom: 1.25rem; padding-bottom: 1rem; border-bottom: 3px solid #4361ee; }
.rh-logo { width: 56px; height: 56px; object-fit: contain; }
.rh-logo-ph { width: 56px; height: 56px; border-radius: 50%; background: linear-gradient(135deg,#4361ee,#7c3aed); display: flex; align-items: center; justify-content: center; font-size: 1.3rem; font-weight: 900; color: #fff; }
.rh-info h1 { font-size: 1.15rem; font-weight: 900; color: #111; letter-spacing: -.02em; }
.rh-info p { font-size: .72rem; color: #6b7280; margin-top: .15rem; }
.rh-right { margin-left: auto; text-align: right; }
.rh-right .dispatch-id { font-family: 'JetBrains Mono', monospace; font-size: .95rem; font-weight: 700; color: #4361ee; }
.rh-right .rh-date { font-size: .72rem; color: #6b7280; margin-top: .2rem; }

/* Meta section */
.rmeta { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem; padding: 1rem; background: #f8fafc; border-radius: 10px; border: 1px solid #e5e7eb; }
.rmeta-grp { }
.rmeta-lbl { font-size: .65rem; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: .08em; }
.rmeta-val { font-size: .84rem; font-weight: 700; color: #111; margin-top: .15rem; }

/* Summary chips */
.rsummary { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1.25rem; }
.rsum-chip { display: inline-flex; align-items: center; gap: .35rem; padding: .35rem .75rem; border-radius: 8px; border: 1.5px solid #e5e7eb; font-size: .75rem; font-weight: 700; background: #fff; }
.rsum-chip .count { font-family: 'JetBrains Mono',monospace; font-weight: 900; color: #4361ee; }
.rsum-chip .label { color: #6b7280; }

/* Table */
.rtbl { width: 100%; border-collapse: collapse; font-size: .8rem; margin-bottom: 1.25rem; }
.rtbl thead th { padding: .65rem .75rem; text-align: left; font-size: .65rem; font-weight: 800; text-transform: uppercase; letter-spacing: .07em; color: #6b7280; background: #f3f4f6; border-bottom: 2px solid #e5e7eb; }
.rtbl tbody td { padding: .7rem .75rem; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
.rtbl tbody tr:last-child td { border-bottom: none; }
.rtbl tfoot td { padding: .75rem; font-weight: 800; background: #f8fafc; border-top: 2px solid #e5e7eb; }
.stu-bold { font-weight: 700; color: #111; }
.stu-sub { font-size: .68rem; color: #9ca3af; font-family: 'JetBrains Mono',monospace; }
.mat-icon { font-size: .9rem; }
.mat-label { font-weight: 600; }
.cost-val { font-family: 'JetBrains Mono',monospace; font-weight: 700; color: #059669; }
.status-d { color: #059669; font-weight: 700; font-size: .72rem; }
.status-p { color: #d97706; font-weight: 700; font-size: .72rem; }

/* Footer */
.rfooter { margin-top: auto; padding-top: 2rem; display: flex; justify-content: space-between; align-items: flex-end; }
.rsign { display: flex; flex-direction: column; align-items: center; gap: .3rem; }
.rsign-line { width: 120px; border-top: 2px solid #374151; }
.rsign-name { font-size: .72rem; font-weight: 700; color: #374151; }
.rsign-title { font-size: .62rem; color: #9ca3af; }
.rfooter-center { text-align: center; font-size: .6rem; color: #9ca3af; line-height: 1.5; }

/* Print */
@page { size: A4; margin: 10mm; }
@media print {
    body { background: #fff; padding: 0; }
    .print-controls { display: none !important; }
    .receipt { box-shadow: none; width: 100%; padding: 0; }
}
</style>
</head>
<body>

<div class="print-controls">
    <button class="ctrl-btn ctrl-print" onclick="window.print()">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print Receipt
    </button>
    <a href="dispatches.php" class="ctrl-btn ctrl-back">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        Back to Dispatches
    </a>
</div>

<div class="receipt">

    <!-- Header -->
    <div class="rh">
        <?php if (file_exists(__DIR__ . '/../assets/logo.png')): ?>
        <img src="../assets/logo.png" class="rh-logo" alt="Logo">
        <?php else: ?>
        <div class="rh-logo-ph">G</div>
        <?php endif; ?>
        <div class="rh-info">
            <h1>Gyanam India Educational Services</h1>
            <p>Dispatch Receipt — Material Delivery Record</p>
        </div>
        <div class="rh-right">
            <div class="dispatch-id"><?= htmlspecialchars($dispatch['dispatch_id']) ?></div>
            <div class="rh-date"><?= date('d M Y', strtotime($dispatch['dispatch_date'])) ?></div>
        </div>
    </div>

    <!-- Meta -->
    <div class="rmeta">
        <div class="rmeta-grp">
            <div class="rmeta-lbl">ATC Center</div>
            <div class="rmeta-val"><?= htmlspecialchars($dispatch['atc_name']) ?><?= $dispatch['atc_code'] ? ' (' . htmlspecialchars($dispatch['atc_code']) . ')' : '' ?></div>
        </div>
        <div class="rmeta-grp">
            <div class="rmeta-lbl">Postal Service</div>
            <div class="rmeta-val"><?= htmlspecialchars($dispatch['postal_service']) ?></div>
        </div>
        <div class="rmeta-grp">
            <div class="rmeta-lbl">Tracking ID</div>
            <div class="rmeta-val"><?= htmlspecialchars($dispatch['tracking_id'] ?: '—') ?></div>
        </div>
        <div class="rmeta-grp">
            <div class="rmeta-lbl">Status</div>
            <div class="rmeta-val"><?= htmlspecialchars($dispatch['status']) ?></div>
        </div>
    </div>

    <!-- Material Summary -->
    <div class="rsummary">
        <?php foreach ($matSummary as $label => $count): ?>
        <div class="rsum-chip">
            <span class="count"><?= $count ?></span>
            <span class="label">× <?= htmlspecialchars($label) ?></span>
        </div>
        <?php endforeach; ?>
        <div class="rsum-chip" style="border-color:#a7f3d0;background:#ecfdf5">
            <span class="count" style="color:#059669"><?= $totalQty ?></span>
            <span class="label" style="color:#065f46">Total Items</span>
        </div>
        <?php if ($totalCost > 0): ?>
        <div class="rsum-chip" style="border-color:#bfdbfe;background:#eff6ff">
            <span class="count" style="color:#1d4ed8">₹<?= number_format($totalCost, 2) ?></span>
            <span class="label" style="color:#1e40af">Total Cost</span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Items Table -->
    <table class="rtbl">
        <thead>
            <tr>
                <th>#</th>
                <th>Student</th>
                <th>Course</th>
                <th>Material</th>
                <th>Qty</th>
                <?php if ($totalCost > 0): ?><th>Unit Cost</th><th>Total</th><?php endif; ?>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $idx => $it): ?>
        <tr>
            <td style="color:#9ca3af;font-size:.72rem"><?= $idx + 1 ?></td>
            <td>
                <div class="stu-bold"><?= htmlspecialchars($it['student_name']) ?></div>
                <div class="stu-sub"><?= htmlspecialchars($it['registration_id'] ?? '') ?> <?= $it['roll_no'] ? '· ' . htmlspecialchars($it['roll_no']) : '' ?></div>
            </td>
            <td style="font-size:.82rem;color:#6b7280"><?= htmlspecialchars($it['course'] ?? '—') ?></td>
            <td>
                <span class="mat-icon"><?= $it['item_type'] === 'T-Shirt' ? '👕' : '📚' ?></span>
                <span class="mat-label"><?= htmlspecialchars($it['item_type'] . ' — ' . ($it['item_detail'] ?? '')) ?></span>
            </td>
            <td style="text-align:center;font-weight:700"><?= $it['quantity'] ?></td>
            <?php if ($totalCost > 0): ?>
            <td class="cost-val"><?= $it['unit_cost'] ? '₹' . number_format($it['unit_cost'], 2) : '—' ?></td>
            <td class="cost-val"><?= $it['line_total'] ? '₹' . number_format($it['line_total'], 2) : '—' ?></td>
            <?php endif; ?>
            <td>
                <?php if ($it['status'] === 'Dispatched'): ?>
                <span class="status-d">✓ Dispatched</span>
                <?php else: ?>
                <span class="status-p">⏳ Pending</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" style="text-align:right">Total</td>
                <td style="text-align:center"><?= $totalQty ?></td>
                <?php if ($totalCost > 0): ?>
                <td></td>
                <td class="cost-val" style="font-size:.9rem">₹<?= number_format($totalCost, 2) ?></td>
                <?php endif; ?>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <?php if (!empty($dispatch['notes'])): ?>
    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:.65rem .9rem;font-size:.78rem;color:#92400e;margin-bottom:1.5rem">
        <strong>Notes:</strong> <?= htmlspecialchars($dispatch['notes']) ?>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="rfooter">
        <div class="rsign">
            <div class="rsign-line"></div>
            <div class="rsign-name">Received By</div>
            <div class="rsign-title"><?= htmlspecialchars($dispatch['atc_name']) ?></div>
        </div>

        <div class="rfooter-center">
            <strong>Gyanam India Educational Services</strong><br>
            Dispatch Receipt · <?= htmlspecialchars($dispatch['dispatch_id']) ?><br>
            This is a computer-generated receipt
        </div>

        <div class="rsign">
            <div class="rsign-line"></div>
            <div class="rsign-name">Dispatched By</div>
            <div class="rsign-title">Head Office</div>
        </div>
    </div>

</div>

<script>
if (new URLSearchParams(window.location.search).get('print') === '1') {
    window.onload = () => window.print();
}
</script>
</body>
</html>
