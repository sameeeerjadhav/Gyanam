<?php
/**
 * Gyanam Portal — ATC: Dispatch Receipt Print
 * Professional A4 printable material delivery receipt.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin(['ATC CENTER']);

$pdo   = getDBConnection();
$atcId = $_SESSION['atc_id'] ?? null;
$id    = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$atcId || !$id) die('Invalid request.');

$dispatch = null;
try {
    $stmt = $pdo->prepare("
        SELECT d.*, atc.name AS atc_name, atc.atc_code, atc.center_type
        FROM material_dispatches d
        JOIN atc_centers atc ON d.atc_id = atc.id
        WHERE d.id = ? AND d.atc_id = ?
    ");
    $stmt->execute([$id, $atcId]);
    $dispatch = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try {
        $stmt = $pdo->prepare("
            SELECT d.*, atc.name AS atc_name, atc.atc_code
            FROM material_dispatches d
            JOIN atc_centers atc ON d.atc_id = atc.id
            WHERE d.id = ? AND d.atc_id = ?
        ");
        $stmt->execute([$id, $atcId]);
        $dispatch = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($dispatch) $dispatch['center_type'] = '';
    } catch (Exception $e2) {
        die('Error loading dispatch.');
    }
}

if (!$dispatch) die('Dispatch not found or access denied.');

$items = [];
try {
    $iStmt = $pdo->prepare("
        SELECT di.item_type, di.item_detail, di.status, di.quantity,
               TRIM(CONCAT(a.first_name,' ',COALESCE(NULLIF(TRIM(a.middle_name),''),''),' ',a.last_name)) AS student_name,
               a.roll_no, a.registration_id, a.course, a.id AS admission_id,
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

if (empty($items)) {
    try {
        $lStmt = $pdo->prepare("
            SELECT a.id AS admission_id, a.roll_no, a.registration_id,
                   TRIM(CONCAT(a.first_name,' ',COALESCE(NULLIF(TRIM(a.middle_name),''),''),' ',a.last_name)) AS student_name,
                   a.course, a.material_language, a.uniform_size
            FROM material_dispatch_students mds
            JOIN admissions a ON mds.admission_id = a.id
            WHERE mds.dispatch_id = ?
        ");
        $lStmt->execute([$id]);
        foreach ($lStmt->fetchAll(PDO::FETCH_ASSOC) as $ls) {
            if (!empty($ls['material_language'])) {
                $items[] = [
                    'student_name' => $ls['student_name'], 'roll_no' => $ls['roll_no'],
                    'registration_id' => $ls['registration_id'], 'admission_id' => $ls['admission_id'],
                    'course' => $ls['course'], 'item_type' => 'Book', 'item_detail' => $ls['material_language'],
                    'quantity' => 1, 'status' => 'Dispatched', 'unit_cost' => null,
                ];
            }
            if (!empty($ls['uniform_size'])) {
                $items[] = [
                    'student_name' => $ls['student_name'], 'roll_no' => $ls['roll_no'],
                    'registration_id' => $ls['registration_id'], 'admission_id' => $ls['admission_id'],
                    'course' => $ls['course'], 'item_type' => 'T-Shirt', 'item_detail' => 'Size ' . $ls['uniform_size'],
                    'quantity' => 1, 'status' => 'Dispatched', 'unit_cost' => null,
                ];
            }
        }
    } catch (Exception $e) {}
}

$totalQty  = 0;
$totalCost = 0;
foreach ($items as &$it) {
    $it['quantity']   = max(1, (int)($it['quantity'] ?? 1));
    $it['unit_cost']  = $it['unit_cost'] !== null ? (float)$it['unit_cost'] : null;
    $it['line_total'] = $it['unit_cost'] ? round($it['unit_cost'] * $it['quantity'], 2) : null;
    $it['display_reg'] = admissionDisplayRegistrationId([
        'id' => $it['admission_id'] ?? 0,
        'registration_id' => $it['registration_id'] ?? '',
        'roll_no' => $it['roll_no'] ?? '',
        'course' => $it['course'] ?? '',
        'center_type' => $dispatch['center_type'] ?? '',
    ]);
    $totalQty += $it['quantity'];
    if ($it['line_total']) $totalCost += $it['line_total'];
}
unset($it);

$matSummary = [];
foreach ($items as $it) {
    $key = trim($it['item_type'] . ' — ' . ($it['item_detail'] ?? ''), ' —');
    if (!isset($matSummary[$key])) $matSummary[$key] = 0;
    $matSummary[$key] += $it['quantity'];
}

$dispatchDate = !empty($dispatch['dispatch_date'])
    ? date('d M Y', strtotime($dispatch['dispatch_date']))
    : '—';
$status = (string)($dispatch['status'] ?? 'Dispatched');
$statusClass = strtolower($status) === 'delivered' ? 'ok' : (strtolower($status) === 'pending' ? 'warn' : 'info');
$hasCost = $totalCost > 0;
$uniqueStudents = count(array_unique(array_map(static fn($r) => (string)($r['display_reg'] ?? ''), $items)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . '/../includes/head_fonts.php'; ?>
<title>Dispatch Receipt — <?= htmlspecialchars($dispatch['dispatch_id']) ?> | Gyanam India</title>
<style>
:root {
  --ink: #0f172a;
  --muted: #64748b;
  --line: #e2e8f0;
  --soft: #f8fafc;
  --brand: #1e3a8a;
  --brand-2: #2563eb;
  --ok: #047857;
  --ok-bg: #ecfdf5;
  --warn: #b45309;
  --warn-bg: #fffbeb;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: 'Sora', system-ui, sans-serif;
  background: #eef2f7;
  color: var(--ink);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 1.25rem 1rem 2rem;
  -webkit-font-smoothing: antialiased;
}

.print-controls { display: flex; gap: .6rem; margin-bottom: 1rem; flex-wrap: wrap; }
.ctrl-btn {
  display: inline-flex; align-items: center; gap: .4rem;
  height: 38px; padding: 0 1rem; border-radius: 9px;
  font: 700 .8rem 'Sora', sans-serif; cursor: pointer;
  border: none; text-decoration: none; transition: background .15s, box-shadow .15s;
}
.ctrl-print { background: var(--brand-2); color: #fff; box-shadow: 0 4px 12px rgba(37,99,235,.22); }
.ctrl-print:hover { background: #1d4ed8; }
.ctrl-back { background: #fff; color: #334155; border: 1.5px solid var(--line); }
.ctrl-back:hover { background: var(--soft); }
.ctrl-btn svg { width: 14px; height: 14px; }

.receipt {
  width: 210mm;
  max-width: 100%;
  min-height: 297mm;
  background: #fff;
  padding: 14mm 16mm 16mm;
  box-shadow: 0 18px 50px rgba(15,23,42,.12);
  border: 1px solid #dbe3ef;
  position: relative;
  display: flex;
  flex-direction: column;
}
.receipt::before {
  content: '';
  position: absolute; inset: 0 0 auto 0; height: 5px;
  background: linear-gradient(90deg, var(--brand), var(--brand-2));
}

/* Letterhead */
.letterhead {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 1rem;
  align-items: start;
  padding-bottom: .95rem;
  border-bottom: 1.5px solid var(--line);
  margin-bottom: 1.1rem;
}
.brand-row { display: flex; align-items: center; gap: .85rem; min-width: 0; }
.brand-logo {
  width: 52px; height: 52px; object-fit: contain; flex-shrink: 0;
  border: 1px solid var(--line); border-radius: 10px; background: #fff; padding: 4px;
}
.brand-mark {
  width: 52px; height: 52px; border-radius: 10px; flex-shrink: 0;
  background: linear-gradient(145deg, var(--brand), var(--brand-2));
  color: #fff; display: flex; align-items: center; justify-content: center;
  font-size: .95rem; font-weight: 800; letter-spacing: .02em;
}
.brand-name { font-size: 1.05rem; font-weight: 800; color: var(--ink); letter-spacing: -.02em; line-height: 1.25; }
.brand-sub { font-size: .72rem; color: var(--muted); margin-top: .18rem; font-weight: 500; }
.doc-meta { text-align: right; }
.doc-type {
  display: inline-block;
  font-size: .62rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase;
  color: var(--brand); background: #eff6ff; border: 1px solid #bfdbfe;
  padding: .22rem .55rem; border-radius: 999px; margin-bottom: .4rem;
}
.doc-id {
  font-family: 'JetBrains Mono', ui-monospace, monospace;
  font-size: .95rem; font-weight: 700; color: var(--brand);
  letter-spacing: -.01em;
}
.doc-date { font-size: .75rem; color: var(--muted); margin-top: .25rem; font-weight: 600; }

.doc-title {
  font-size: .78rem; font-weight: 800; text-transform: uppercase; letter-spacing: .08em;
  color: var(--muted); margin-bottom: .65rem;
}

/* Info grid */
.info-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0;
  border: 1.5px solid var(--line);
  border-radius: 12px;
  overflow: hidden;
  margin-bottom: 1.15rem;
  background: #fff;
}
.info-cell {
  padding: .75rem .85rem;
  border-right: 1px solid var(--line);
  background: var(--soft);
}
.info-cell:last-child { border-right: none; }
.info-lbl {
  font-size: .6rem; font-weight: 800; color: #94a3b8;
  text-transform: uppercase; letter-spacing: .07em; margin-bottom: .28rem;
}
.info-val { font-size: .82rem; font-weight: 700; color: var(--ink); line-height: 1.35; word-break: break-word; }
.status-pill {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .18rem .55rem; border-radius: 999px;
  font-size: .72rem; font-weight: 800;
}
.status-pill.info { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.status-pill.ok { background: var(--ok-bg); color: var(--ok); border: 1px solid #a7f3d0; }
.status-pill.warn { background: var(--warn-bg); color: var(--warn); border: 1px solid #fde68a; }

/* Summary */
.section-h {
  display: flex; align-items: baseline; justify-content: space-between; gap: .75rem;
  margin-bottom: .55rem;
}
.section-h h2 {
  font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; color: var(--muted);
}
.section-h .hint { font-size: .7rem; color: #94a3b8; font-weight: 600; }
.summary-table {
  width: 100%; border-collapse: collapse; margin-bottom: 1.15rem;
  border: 1.5px solid var(--line); border-radius: 12px; overflow: hidden;
}
.summary-table th, .summary-table td {
  padding: .55rem .8rem; font-size: .78rem; border-bottom: 1px solid var(--line); text-align: left;
}
.summary-table th {
  background: var(--soft); font-size: .62rem; font-weight: 800;
  text-transform: uppercase; letter-spacing: .06em; color: var(--muted);
}
.summary-table tr:last-child td { border-bottom: none; }
.summary-table td.qty {
  font-family: 'JetBrains Mono', ui-monospace, monospace;
  font-weight: 700; color: var(--brand-2); width: 4.5rem; text-align: center;
}
.summary-table tfoot td {
  background: #f1f5f9; font-weight: 800; border-top: 1.5px solid var(--line);
}

/* Items table */
.items-wrap {
  border: 1.5px solid var(--line);
  border-radius: 12px;
  overflow: hidden;
  margin-bottom: 1rem;
}
.rtbl { width: 100%; border-collapse: collapse; font-size: .78rem; }
.rtbl thead th {
  padding: .6rem .7rem; text-align: left;
  font-size: .6rem; font-weight: 800; text-transform: uppercase; letter-spacing: .07em;
  color: var(--muted); background: var(--soft); border-bottom: 1.5px solid var(--line);
  white-space: nowrap;
}
.rtbl tbody td {
  padding: .65rem .7rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle;
}
.rtbl tbody tr:last-child td { border-bottom: none; }
.rtbl tbody tr:nth-child(even) td { background: #fafbfc; }
.rtbl tfoot td {
  padding: .7rem; font-weight: 800; background: #f1f5f9;
  border-top: 1.5px solid var(--line);
}
.idx { color: #94a3b8; font-size: .7rem; font-weight: 700; width: 2rem; }
.stu-name { font-weight: 750; color: var(--ink); font-size: .8rem; }
.stu-reg {
  font-family: 'JetBrains Mono', ui-monospace, monospace;
  font-size: .68rem; color: #64748b; margin-top: .15rem; font-weight: 600;
}
.course { color: #475569; font-weight: 600; font-size: .76rem; }
.type-badge {
  display: inline-block;
  font-size: .62rem; font-weight: 800; text-transform: uppercase; letter-spacing: .04em;
  padding: .12rem .4rem; border-radius: 5px; margin-right: .35rem;
  vertical-align: middle;
}
.type-badge.book { background: #dbeafe; color: #1e40af; }
.type-badge.tshirt { background: #ede9fe; color: #5b21b6; }
.type-badge.cert { background: #fef3c7; color: #92400e; }
.type-badge.other { background: #e2e8f0; color: #334155; }
.mat-detail { font-weight: 600; color: var(--ink); }
.qty-cell {
  text-align: center; font-family: 'JetBrains Mono', ui-monospace, monospace;
  font-weight: 700; color: var(--ink);
}
.cost-val {
  font-family: 'JetBrains Mono', ui-monospace, monospace;
  font-weight: 700; color: var(--ok); white-space: nowrap;
}
.st-ok { color: var(--ok); font-weight: 800; font-size: .72rem; }
.st-warn { color: var(--warn); font-weight: 800; font-size: .72rem; }

.notes {
  border: 1.5px solid #fde68a; background: var(--warn-bg);
  border-radius: 10px; padding: .7rem .9rem;
  font-size: .76rem; color: #92400e; margin-bottom: 1.25rem; line-height: 1.45;
}
.notes strong { font-weight: 800; }

/* Signatures */
.rfooter {
  margin-top: auto;
  padding-top: 1.75rem;
  display: grid;
  grid-template-columns: 1fr 1.2fr 1fr;
  gap: 1rem;
  align-items: end;
  border-top: 1.5px solid var(--line);
}
.rsign { text-align: center; }
.rsign-line {
  width: 130px; max-width: 100%; height: 0;
  border-top: 1.5px solid #334155; margin: 2.25rem auto .4rem;
}
.rsign-name { font-size: .72rem; font-weight: 800; color: #1e293b; }
.rsign-title { font-size: .62rem; color: #94a3b8; margin-top: .12rem; font-weight: 600; }
.rfooter-center {
  text-align: center; font-size: .62rem; color: #94a3b8; line-height: 1.55; font-weight: 500;
  padding-bottom: .15rem;
}
.rfooter-center strong { color: #64748b; font-weight: 800; display: block; margin-bottom: .15rem; }

@page { size: A4; margin: 10mm; }
@media print {
  body { background: #fff; padding: 0; }
  .print-controls { display: none !important; }
  .receipt {
    box-shadow: none; border: none; width: 100%; min-height: auto;
    padding: 0; max-width: none;
  }
  .receipt::before { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
  .rtbl tbody tr:nth-child(even) td,
  .info-cell, .summary-table th, .rtbl thead th, .rtbl tfoot td, .summary-table tfoot td {
    print-color-adjust: exact; -webkit-print-color-adjust: exact;
  }
}
@media (max-width: 820px) {
  .info-grid { grid-template-columns: 1fr 1fr; }
  .info-cell:nth-child(2n) { border-right: none; }
  .info-cell:nth-child(-n+2) { border-bottom: 1px solid var(--line); }
  .letterhead { grid-template-columns: 1fr; }
  .doc-meta { text-align: left; }
  .rfooter { grid-template-columns: 1fr; gap: 1.25rem; text-align: center; }
  .items-wrap { overflow-x: auto; }
  .rtbl { min-width: 640px; }
}
</style>
</head>
<body>

<div class="print-controls">
    <button type="button" class="ctrl-btn ctrl-print" onclick="window.print()">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print Receipt
    </button>
    <a href="dispatches.php" class="ctrl-btn ctrl-back">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        Back to Dispatches
    </a>
</div>

<div class="receipt">

    <header class="letterhead">
        <div class="brand-row">
            <?php if (file_exists(__DIR__ . '/../assets/logo.png')): ?>
            <img src="../assets/logo.png" class="brand-logo" alt="Gyanam India">
            <?php else: ?>
            <div class="brand-mark">GI</div>
            <?php endif; ?>
            <div>
                <div class="brand-name">Gyanam India Educational Services</div>
                <div class="brand-sub">Head Office · Material Dispatch Division</div>
            </div>
        </div>
        <div class="doc-meta">
            <div class="doc-type">Official Receipt</div>
            <div class="doc-id"><?= htmlspecialchars($dispatch['dispatch_id']) ?></div>
            <div class="doc-date">Dispatch date · <?= htmlspecialchars($dispatchDate) ?></div>
        </div>
    </header>

    <div class="doc-title">Material Delivery Record</div>

    <div class="info-grid">
        <div class="info-cell">
            <div class="info-lbl">ATC Center</div>
            <div class="info-val">
                <?= htmlspecialchars($dispatch['atc_name']) ?>
                <?php if (!empty($dispatch['atc_code'])): ?>
                <div style="font-size:.7rem;font-weight:600;color:#64748b;margin-top:.15rem;font-family:'JetBrains Mono',monospace"><?= htmlspecialchars($dispatch['atc_code']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="info-cell">
            <div class="info-lbl">Courier / Service</div>
            <div class="info-val"><?= htmlspecialchars($dispatch['postal_service'] ?: '—') ?></div>
        </div>
        <div class="info-cell">
            <div class="info-lbl">Tracking ID</div>
            <div class="info-val" style="font-family:'JetBrains Mono',monospace;font-size:.78rem"><?= htmlspecialchars($dispatch['tracking_id'] ?: '—') ?></div>
        </div>
        <div class="info-cell">
            <div class="info-lbl">Status</div>
            <div class="info-val">
                <span class="status-pill <?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($status) ?></span>
            </div>
        </div>
    </div>

    <?php if (!empty($matSummary)): ?>
    <div class="section-h">
        <h2>Contents summary</h2>
        <span class="hint"><?= (int)$uniqueStudents ?> student<?= $uniqueStudents === 1 ? '' : 's' ?> · <?= (int)$totalQty ?> unit<?= $totalQty === 1 ? '' : 's' ?></span>
    </div>
    <table class="summary-table">
        <thead>
            <tr>
                <th>Material</th>
                <th style="text-align:center;width:5rem">Qty</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($matSummary as $label => $count): ?>
            <tr>
                <td><?= htmlspecialchars($label) ?></td>
                <td class="qty"><?= (int)$count ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td>Total units</td>
                <td class="qty"><?= (int)$totalQty ?></td>
            </tr>
            <?php if ($hasCost): ?>
            <tr>
                <td>Estimated inventory cost</td>
                <td class="qty" style="color:var(--ok)">₹<?= number_format($totalCost, 2) ?></td>
            </tr>
            <?php endif; ?>
        </tfoot>
    </table>
    <?php endif; ?>

    <div class="section-h">
        <h2>Itemised student materials</h2>
    </div>
    <div class="items-wrap">
        <table class="rtbl">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Student</th>
                    <th>Course</th>
                    <th>Material</th>
                    <th style="text-align:center">Qty</th>
                    <?php if ($hasCost): ?><th>Unit</th><th>Amount</th><?php endif; ?>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="<?= $hasCost ? 8 : 6 ?>" style="text-align:center;color:#94a3b8;padding:1.5rem">No line items on this dispatch.</td></tr>
            <?php else: ?>
                <?php foreach ($items as $idx => $it):
                    $type = (string)($it['item_type'] ?? 'Other');
                    $badgeClass = 'other';
                    if (strcasecmp($type, 'Book') === 0) $badgeClass = 'book';
                    elseif (strcasecmp($type, 'T-Shirt') === 0) $badgeClass = 'tshirt';
                    elseif (strcasecmp($type, 'Certificate') === 0) $badgeClass = 'cert';
                    $detail = trim((string)($it['item_detail'] ?? ''));
                    $reg = (string)($it['display_reg'] ?? '');
                    $roll = trim((string)($it['roll_no'] ?? ''));
                    // Avoid "GYANAM6 · GYANAM6" when roll duplicates registration
                    $sub = $reg;
                    if ($roll !== '' && strcasecmp($roll, $reg) !== 0) {
                        $sub .= ' · ' . $roll;
                    }
                ?>
                <tr>
                    <td class="idx"><?= $idx + 1 ?></td>
                    <td>
                        <div class="stu-name"><?= htmlspecialchars(trim(preg_replace('/\s+/', ' ', (string)$it['student_name']))) ?></div>
                        <?php if ($sub !== ''): ?><div class="stu-reg"><?= htmlspecialchars($sub) ?></div><?php endif; ?>
                    </td>
                    <td class="course"><?= htmlspecialchars($it['course'] ?? '—') ?></td>
                    <td>
                        <span class="type-badge <?= $badgeClass ?>"><?= htmlspecialchars($type) ?></span>
                        <?php if ($detail !== ''): ?><span class="mat-detail"><?= htmlspecialchars($detail) ?></span><?php endif; ?>
                    </td>
                    <td class="qty-cell"><?= (int)$it['quantity'] ?></td>
                    <?php if ($hasCost): ?>
                    <td class="cost-val"><?= $it['unit_cost'] ? '₹' . number_format($it['unit_cost'], 2) : '—' ?></td>
                    <td class="cost-val"><?= $it['line_total'] ? '₹' . number_format($it['line_total'], 2) : '—' ?></td>
                    <?php endif; ?>
                    <td>
                        <?php if (($it['status'] ?? '') === 'Dispatched' || ($it['status'] ?? '') === 'Delivered'): ?>
                        <span class="st-ok">Dispatched</span>
                        <?php else: ?>
                        <span class="st-warn"><?= htmlspecialchars($it['status'] ?: 'Pending') ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <?php if (!empty($items)): ?>
            <tfoot>
                <tr>
                    <td colspan="4" style="text-align:right">Total</td>
                    <td class="qty-cell"><?= (int)$totalQty ?></td>
                    <?php if ($hasCost): ?>
                    <td></td>
                    <td class="cost-val">₹<?= number_format($totalCost, 2) ?></td>
                    <?php endif; ?>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>

    <?php if (!empty($dispatch['notes'])): ?>
    <div class="notes"><strong>Notes:</strong> <?= htmlspecialchars($dispatch['notes']) ?></div>
    <?php endif; ?>

    <footer class="rfooter">
        <div class="rsign">
            <div class="rsign-line"></div>
            <div class="rsign-name">Received by</div>
            <div class="rsign-title"><?= htmlspecialchars($dispatch['atc_name']) ?></div>
        </div>
        <div class="rfooter-center">
            <strong>Gyanam India Educational Services</strong>
            Dispatch Receipt · <?= htmlspecialchars($dispatch['dispatch_id']) ?><br>
            Computer-generated document · <?= date('d M Y, h:i A') ?>
        </div>
        <div class="rsign">
            <div class="rsign-line"></div>
            <div class="rsign-name">Authorised by</div>
            <div class="rsign-title">Head Office</div>
        </div>
    </footer>

</div>

<script>
if (new URLSearchParams(window.location.search).get('print') === '1') {
    window.addEventListener('load', function () { window.print(); });
}
</script>
</body>
</html>
