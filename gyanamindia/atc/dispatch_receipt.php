<?php
/**
 * Gyanam Portal — ATC: Dispatch Receipt (tabular print document)
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
$hasCost = $totalCost > 0;
$colspanItems = $hasCost ? 8 : 6;

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<?php include __DIR__ . '/../includes/head_fonts.php'; ?>
<title>Dispatch Receipt — <?= htmlspecialchars($dispatch['dispatch_id']) ?> | Gyanam India</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: 'Sora', Arial, Helvetica, sans-serif;
  background: #ddd;
  color: #000;
  padding: 16px;
  line-height: 1.35;
}
.no-print { margin: 0 auto 14px; max-width: 210mm; display: flex; gap: 8px; }
.no-print button, .no-print a {
  font: 700 13px/1 'Sora', Arial, sans-serif;
  padding: 8px 14px;
  border: 1px solid #333;
  background: #fff;
  color: #000;
  text-decoration: none;
  cursor: pointer;
}
.no-print .btn-print { background: #1a3a8f; color: #fff; border-color: #1a3a8f; }

.sheet {
  width: 210mm;
  max-width: 100%;
  min-height: 297mm;
  margin: 0 auto;
  background: #fff;
  padding: 14mm 12mm 16mm;
  border: 1px solid #999;
}

/* Classic document header */
.doc-head {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 10px;
}
.doc-head td { vertical-align: middle; padding: 0; }
.logo {
  width: 54px; height: 54px; object-fit: contain;
  border: 1px solid #000;
}
.org-name { font-size: 16px; font-weight: 800; letter-spacing: 0.2px; }
.org-line { font-size: 11px; color: #333; margin-top: 2px; }
.doc-right { text-align: right; font-size: 11px; }
.doc-right .rid {
  font-family: 'JetBrains Mono', Consolas, monospace;
  font-size: 13px; font-weight: 700;
}
.rule { border: none; border-top: 2px solid #000; margin: 8px 0 10px; }
.rule-thin { border: none; border-top: 1px solid #000; margin: 8px 0 10px; }

.title {
  text-align: center;
  font-size: 14px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 1px;
  margin: 4px 0 12px;
}

/* Particulars table */
.meta {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 14px;
  font-size: 11.5px;
}
.meta th, .meta td {
  border: 1px solid #000;
  padding: 6px 8px;
  text-align: left;
  vertical-align: top;
}
.meta th {
  width: 22%;
  background: #f0f0f0;
  font-weight: 700;
  white-space: nowrap;
}
.meta td { font-weight: 600; }

.sec {
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.6px;
  margin: 14px 0 6px;
}

/* Data tables */
.tbl {
  width: 100%;
  border-collapse: collapse;
  font-size: 11px;
  margin-bottom: 4px;
}
.tbl th, .tbl td {
  border: 1px solid #000;
  padding: 5px 6px;
  vertical-align: top;
}
.tbl th {
  background: #f0f0f0;
  font-weight: 800;
  text-align: center;
  text-transform: uppercase;
  font-size: 10px;
  letter-spacing: 0.4px;
}
.tbl td.c { text-align: center; }
.tbl td.r { text-align: right; }
.tbl tfoot td { font-weight: 800; background: #f7f7f7; }
.mono { font-family: 'JetBrains Mono', Consolas, monospace; font-size: 10.5px; }

.notes {
  border: 1px solid #000;
  padding: 7px 8px;
  font-size: 11px;
  margin: 12px 0;
}
.notes b { font-weight: 800; }

.sign {
  width: 100%;
  border-collapse: collapse;
  margin-top: 28px;
  font-size: 11px;
}
.sign td {
  width: 33.33%;
  text-align: center;
  vertical-align: bottom;
  padding: 0 8px;
}
.sign .line {
  border-top: 1px solid #000;
  margin: 36px auto 6px;
  width: 85%;
}
.sign .lbl { font-weight: 800; }
.sign .sub { font-size: 10px; color: #333; margin-top: 2px; }

.footer-note {
  margin-top: 18px;
  text-align: center;
  font-size: 9.5px;
  color: #444;
  border-top: 1px solid #000;
  padding-top: 8px;
}

@page { size: A4; margin: 10mm; }
@media print {
  body { background: #fff; padding: 0; }
  .no-print { display: none !important; }
  .sheet { border: none; width: 100%; min-height: auto; padding: 0; box-shadow: none; }
  .meta th, .tbl th, .tbl tfoot td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
@media (max-width: 800px) {
  .sheet { padding: 12px; }
  .tbl { font-size: 10px; }
}
</style>
</head>
<body>

<div class="no-print">
  <button type="button" class="btn-print" onclick="window.print()">Print Receipt</button>
  <a href="dispatches.php">Back to Dispatches</a>
  <span style="align-self:center;font-size:11px;color:#555;margin-left:6px">Tabular format · rev <?= htmlspecialchars(substr(hash_file('sha1', __FILE__), 0, 8)) ?></span>
</div>

<div class="sheet">

  <table class="doc-head">
    <tr>
      <td style="width:62px">
        <?php if (file_exists(__DIR__ . '/../assets/logo.png')): ?>
        <img src="../assets/logo.png" class="logo" alt="Logo">
        <?php else: ?>
        <div class="logo" style="display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px">GI</div>
        <?php endif; ?>
      </td>
      <td style="padding-left:10px">
        <div class="org-name">Gyanam India Educational Services</div>
        <div class="org-line">Head Office — Material Dispatch Division</div>
      </td>
      <td class="doc-right">
        <div><b>Dispatch No.</b></div>
        <div class="rid"><?= htmlspecialchars($dispatch['dispatch_id']) ?></div>
        <div style="margin-top:4px"><b>Date:</b> <?= htmlspecialchars($dispatchDate) ?></div>
      </td>
    </tr>
  </table>

  <hr class="rule">
  <div class="title">Dispatch Receipt / Material Delivery Record</div>

  <table class="meta">
    <tr>
      <th>ATC Center</th>
      <td>
        <?= htmlspecialchars($dispatch['atc_name']) ?>
        <?php if (!empty($dispatch['atc_code'])): ?>
          (<?= htmlspecialchars($dispatch['atc_code']) ?>)
        <?php endif; ?>
      </td>
      <th>Status</th>
      <td><?= htmlspecialchars($dispatch['status'] ?? '—') ?></td>
    </tr>
    <tr>
      <th>Postal / Courier</th>
      <td><?= htmlspecialchars($dispatch['postal_service'] ?: '—') ?></td>
      <th>Tracking ID</th>
      <td class="mono"><?= htmlspecialchars($dispatch['tracking_id'] ?: '—') ?></td>
    </tr>
  </table>

  <?php if (!empty($matSummary)): ?>
  <div class="sec">A. Contents Summary</div>
  <table class="tbl">
    <thead>
      <tr>
        <th style="width:8%">Sr.</th>
        <th>Material Description</th>
        <th style="width:14%">Quantity</th>
      </tr>
    </thead>
    <tbody>
    <?php $n = 0; foreach ($matSummary as $label => $count): $n++; ?>
      <tr>
        <td class="c"><?= $n ?></td>
        <td><?= htmlspecialchars($label) ?></td>
        <td class="c mono"><?= (int)$count ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="2" class="r">Total Units</td>
        <td class="c mono"><?= (int)$totalQty ?></td>
      </tr>
      <?php if ($hasCost): ?>
      <tr>
        <td colspan="2" class="r">Estimated Cost</td>
        <td class="c mono">₹<?= number_format($totalCost, 2) ?></td>
      </tr>
      <?php endif; ?>
    </tfoot>
  </table>
  <?php endif; ?>

  <div class="sec">B. Student-wise Material Details</div>
  <table class="tbl">
    <thead>
      <tr>
        <th style="width:6%">Sr.</th>
        <th style="width:24%">Student Name</th>
        <th style="width:14%">Reg. ID</th>
        <th style="width:16%">Course</th>
        <th>Material</th>
        <th style="width:7%">Qty</th>
        <?php if ($hasCost): ?>
        <th style="width:9%">Unit Cost</th>
        <th style="width:9%">Amount</th>
        <?php endif; ?>
        <th style="width:10%">Status</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($items)): ?>
      <tr>
        <td colspan="<?= $colspanItems ?>" class="c" style="padding:14px">No items recorded for this dispatch.</td>
      </tr>
    <?php else: ?>
      <?php foreach ($items as $idx => $it):
        $reg = (string)($it['display_reg'] ?? '');
        $detail = trim((string)($it['item_detail'] ?? ''));
        $mat = trim(($it['item_type'] ?? '') . ($detail !== '' ? ' — ' . $detail : ''));
      ?>
      <tr>
        <td class="c"><?= $idx + 1 ?></td>
        <td><?= htmlspecialchars(trim(preg_replace('/\s+/', ' ', (string)$it['student_name']))) ?></td>
        <td class="mono"><?= htmlspecialchars($reg !== '' ? $reg : '—') ?></td>
        <td><?= htmlspecialchars($it['course'] ?? '—') ?></td>
        <td><?= htmlspecialchars($mat !== '' ? $mat : '—') ?></td>
        <td class="c mono"><?= (int)$it['quantity'] ?></td>
        <?php if ($hasCost): ?>
        <td class="r mono"><?= $it['unit_cost'] ? '₹' . number_format($it['unit_cost'], 2) : '—' ?></td>
        <td class="r mono"><?= $it['line_total'] ? '₹' . number_format($it['line_total'], 2) : '—' ?></td>
        <?php endif; ?>
        <td class="c"><?= htmlspecialchars($it['status'] ?: '—') ?></td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
    <?php if (!empty($items)): ?>
    <tfoot>
      <tr>
        <td colspan="5" class="r">Total</td>
        <td class="c mono"><?= (int)$totalQty ?></td>
        <?php if ($hasCost): ?>
        <td></td>
        <td class="r mono">₹<?= number_format($totalCost, 2) ?></td>
        <?php endif; ?>
        <td></td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>

  <?php if (!empty($dispatch['notes'])): ?>
  <div class="notes"><b>Notes:</b> <?= htmlspecialchars($dispatch['notes']) ?></div>
  <?php endif; ?>

  <table class="sign">
    <tr>
      <td>
        <div class="line"></div>
        <div class="lbl">Received By</div>
        <div class="sub"><?= htmlspecialchars($dispatch['atc_name']) ?></div>
      </td>
      <td>
        <div class="line"></div>
        <div class="lbl">Checked By</div>
        <div class="sub">Store / Dispatch Desk</div>
      </td>
      <td>
        <div class="line"></div>
        <div class="lbl">Authorised By</div>
        <div class="sub">Head Office</div>
      </td>
    </tr>
  </table>

  <div class="footer-note">
    This is a computer-generated dispatch receipt of Gyanam India Educational Services.<br>
    Document No. <?= htmlspecialchars($dispatch['dispatch_id']) ?> · Generated on <?= date('d M Y, h:i A') ?>
  </div>

</div>

<script>
if (new URLSearchParams(window.location.search).get('print') === '1') {
  window.addEventListener('load', function () { window.print(); });
}
</script>
</body>
</html>
