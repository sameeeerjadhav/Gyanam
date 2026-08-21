<?php
/**
 * Gyanam Portal — ATC: Fee Receipt
 * Standalone printable receipt — classic slip design
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin(['ATC CENTER']);

$pdo   = getDBConnection();
$atcId = $_SESSION['atc_id'] ?? null;

if (!$atcId) die('Unauthorized');

// Fetch by payment_id or receipt_no
$paymentId = isset($_GET['payment_id']) ? intval($_GET['payment_id']) : null;
$receiptNo = isset($_GET['receipt_no']) ? sanitize($_GET['receipt_no']) : null;

if (!$paymentId && !$receiptNo) die('No receipt specified.');

// --- Fetch Payment ---
if ($paymentId) {
    $stmt = $pdo->prepare("SELECT * FROM fee_payments WHERE id = ?");
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        $legacyStmt = $pdo->prepare("SELECT remark_text FROM student_remarks WHERE id = ? AND atc_id = ? LIMIT 1");
        $legacyStmt->execute([$paymentId, $atcId]);
        $legacyRemark = $legacyStmt->fetchColumn();

        if ($legacyRemark && preg_match('/Receipt:\s*([A-Za-z0-9\-]+)/', (string)$legacyRemark, $m)) {
            $legacyReceiptNo = $m[1];
            $legacyPayStmt = $pdo->prepare("SELECT * FROM fee_payments WHERE receipt_no = ? LIMIT 1");
            $legacyPayStmt->execute([$legacyReceiptNo]);
            $payment = $legacyPayStmt->fetch(PDO::FETCH_ASSOC);
        }
    }
} else {
    $stmt = $pdo->prepare("SELECT * FROM fee_payments WHERE receipt_no = ?");
    $stmt->execute([$receiptNo]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$payment) die('Receipt not found or access denied.');

$hasAtcId = $pdo->query("SHOW COLUMNS FROM fee_payments LIKE 'atc_id'")->rowCount() > 0;
$isAuthorized = false;
if ($hasAtcId && !empty($payment['atc_id']) && intval($payment['atc_id']) === intval($atcId)) {
    $isAuthorized = true;
}

$ownerStmt = $pdo->prepare("SELECT id, atc_id FROM admissions WHERE id = ?");
$ownerStmt->execute([$payment['admission_id']]);
$ownerAdmission = $ownerStmt->fetch(PDO::FETCH_ASSOC);
if ($ownerAdmission && intval($ownerAdmission['atc_id']) === intval($atcId)) {
    $isAuthorized = true;
}

if (!$isAuthorized) die('Receipt not found or access denied.');

$downloadMode = isset($_GET['download']) && $_GET['download'] === '1';
if ($downloadMode) {
    $safeReceiptNo = preg_replace('/[^A-Za-z0-9\-_]/', '', (string)($payment['receipt_no'] ?? ('payment-' . $payment['id'])));
    if ($safeReceiptNo === '') $safeReceiptNo = 'receipt-' . date('YmdHis');
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="fee-receipt-' . $safeReceiptNo . '.html"');
}

// --- Fetch Student ---
$stmt = $pdo->prepare("SELECT * FROM admissions WHERE id = ? AND atc_id = ?");
$stmt->execute([$payment['admission_id'], $atcId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$student) die('Student record not found.');

// --- Fetch ATC Center details ---
$stmt = $pdo->prepare("SELECT atc.*, dlc.name as dlc_name FROM atc_centers atc LEFT JOIN dlc_offices dlc ON atc.dlc_id = dlc.id WHERE atc.id = ?");
$stmt->execute([$atcId]);
$atc = $stmt->fetch(PDO::FETCH_ASSOC);

// --- Logo URL ---
$logoUrl = '../assets/logo.png';
if (!empty($atc['logo'])) {
    $rawLogo = (string)$atc['logo'];
    if (preg_match('#^https?://#i', $rawLogo)) {
        $logoUrl = $rawLogo;
    } else {
        $relativeLogo = ltrim($rawLogo, '/\\');
        $absoluteLogo = realpath(__DIR__ . '/../' . $relativeLogo);
        if ($absoluteLogo && is_file($absoluteLogo)) {
            $logoUrl = '../' . str_replace('\\', '/', $relativeLogo);
        }
    }
}
if (strpos($logoUrl, '../uploads/atc_logos/') === 0) {
    $logoUrl .= '?v=' . urlencode((string)($atc['updated_at'] ?? time()));
}

// --- Derived values ---
$fullName      = trim($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name']);
$netPayable    = floatval($student['net_payable'] ?? $student['fees_total'] ?? 0);
$paymentAmount = floatval($payment['amount']);
$batchInfo     = trim((string)($payment['description'] ?? ''));

// Total paid to date (all payments for this student)
$stmt = $pdo->prepare("SELECT SUM(amount) FROM fee_payments WHERE admission_id = ?");
$stmt->execute([$payment['admission_id']]);
$totalPaid = floatval($stmt->fetchColumn());
$balance   = max(0, $netPayable - $totalPaid);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fee Receipt — <?= htmlspecialchars($payment['receipt_no']) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Inter', sans-serif;
    background: #eef0f8;
    color: #1e293b;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 1.5rem 1rem;
    gap: 1rem;
}

.action-bar {
    display: flex;
    gap: .75rem;
    align-items: center;
}
.btn {
    display: inline-flex; align-items: center; gap: .45rem;
    padding: .6rem 1.25rem;
    border-radius: 8px; border: none;
    font-size: .85rem; font-weight: 700;
    cursor: pointer; font-family: inherit;
    transition: all .2s; text-decoration: none;
}
.btn-print { background: #4361ee; color: #fff; box-shadow: 0 4px 12px rgba(67,97,238,.25); }
.btn-print:hover { background: #3050dd; transform: translateY(-1px); }
.btn-back  { background: #fff; color: #64748b; border: 1.5px solid #e2e8f0; }
.btn-back:hover { background: #f8fafc; }
.btn svg { width: 15px; height: 15px; }

.hint {
    font-size: .75rem; color: #94a3b8;
    background: #fff; border: 1px solid #e2e8f0;
    border-radius: 8px; padding: .45rem .9rem;
}

.classic-slip {
    background: #fff;
    width: 100%;
    max-width: 980px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    padding: 18px 22px;
    box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
    font-size: 15px;
}
.slip-head { display: flex; gap: 16px; align-items: flex-start; border-bottom: 1px solid #e5e7eb; padding-bottom: 14px; }
.slip-logo { width: 115px; height: 115px; border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden; background: #fff; display: flex; align-items: center; justify-content: center; }
.slip-logo img { width: 100%; height: 100%; object-fit: contain; }
.slip-atc-name { font-size: 40px; font-weight: 800; line-height: 1.1; color: #111827; }
.slip-atc-line { color: #374151; margin-top: 4px; font-size: 14px; }
.slip-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px 26px;
    padding-top: 14px;
    border-bottom: 1px solid #e5e7eb;
    padding-bottom: 12px;
}
.slip-row { border-bottom: 1px solid #f1f5f9; padding: 4px 0; }
.slip-row .lbl { font-weight: 700; margin-right: 8px; color: #111827; }
.slip-row .val { color: #1f2937; }
.fee-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
.fee-table th { text-align: left; font-size: 14px; color: #111827; border-bottom: 1px solid #d1d5db; padding: 8px 4px; }
.fee-table td { padding: 10px 4px; border-bottom: 1px solid #f3f4f6; color: #1f2937; }
.slip-footer { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 14px; }
.slip-note { font-size: 13px; color: #111827; line-height: 1.7; }
.slip-sign { font-size: 30px; color: #374151; text-align: right; min-width: 240px; }
.print-btn-inline {
    margin-top: 14px;
    background: #0ea5e9;
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 10px 28px;
    font-weight: 700;
    cursor: pointer;
}

@media print {
    .action-bar { display: none !important; }
    @page { size: A4; margin: 8mm; }
    body { background: #fff; padding: 0; margin: 0; }
    .classic-slip {
        box-shadow: none;
        border-radius: 0;
        border: 1px solid #d1d5db;
        width: 194mm;
        max-width: 194mm;
        margin: 0 auto;
        padding: 10px 14px;
        font-size: 12px;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .slip-head { gap: 10px; padding-bottom: 8px; }
    .slip-logo { width: 78px; height: 78px; }
    .slip-atc-name { font-size: 26px; }
    .slip-atc-line { font-size: 11px; margin-top: 2px; }
    .slip-grid { gap: 5px 14px; padding-top: 8px; padding-bottom: 7px; }
    .slip-row { padding: 2px 0; }
    .fee-table { margin-top: 7px; }
    .fee-table th { font-size: 11px; padding: 5px 3px; }
    .fee-table td { font-size: 11px; padding: 6px 3px; }
    .slip-footer { margin-top: 8px; }
    .slip-note { font-size: 10.5px; line-height: 1.45; }
    .slip-sign { font-size: 16px; min-width: 180px; }
    .print-btn-inline { display: none !important; }
}
</style>
</head>
<body>

<div class="action-bar">
    <a href="fees.php" class="btn btn-back">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        Back
    </a>
    <button class="btn btn-print" onclick="window.print()">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print / Save PDF
    </button>
    <span class="hint">Receipt preview</span>
</div>

<div class="classic-slip">
    <div class="slip-head">
        <div class="slip-logo">
            <img src="<?= htmlspecialchars($logoUrl) ?>" alt="ATC Logo">
        </div>
        <div>
            <div class="slip-atc-name"><?= htmlspecialchars($atc['name'] ?? 'ATC Center') ?></div>
            <?php if (!empty($atc['address'])): ?><div class="slip-atc-line"><?= htmlspecialchars($atc['address']) ?></div><?php endif; ?>
            <?php if (!empty($atc['mobile'])): ?><div class="slip-atc-line"><?= htmlspecialchars($atc['mobile']) ?></div><?php endif; ?>
        </div>
    </div>

    <div class="slip-grid">
        <div class="slip-row"><span class="lbl">User ID:</span><span class="val"><?= htmlspecialchars($student['registration_id'] ?? '—') ?></span></div>
        <div class="slip-row"><span class="lbl">Course Fees:</span><span class="val"><?= number_format($netPayable, 0) ?></span></div>
        <div class="slip-row"><span class="lbl">Student Name:</span><span class="val"><?= htmlspecialchars($fullName) ?></span></div>
        <div class="slip-row"><span class="lbl">Contact No:</span><span class="val"><?= htmlspecialchars($student['mobile'] ?? '—') ?></span></div>
        <div class="slip-row"><span class="lbl">Course:</span><span class="val"><?= htmlspecialchars($student['course'] ?? '—') ?></span></div>
        <div class="slip-row"><span class="lbl">Batch:</span><span class="val"><?= htmlspecialchars($batchInfo !== '' ? $batchInfo : ($student['course'] ?? '—')) ?></span></div>
    </div>

    <table class="fee-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Receipt No</th>
                <th>Fees Received</th>
                <th>Total Fees Paid</th>
                <th>Balance Fees</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?= date('d/m/Y', strtotime($payment['payment_date'])) ?></td>
                <td><?= htmlspecialchars($payment['receipt_no']) ?></td>
                <td><?= number_format($paymentAmount, 0) ?> (<?= htmlspecialchars($payment['payment_mode'] ?? 'Cash') ?>)</td>
                <td><?= number_format($totalPaid, 0) ?></td>
                <td><?= number_format($balance, 0) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="slip-footer">
        <div class="slip-note">
            <div><strong>Note:</strong></div>
            <div>1. Cheque subject to realisation.</div>
            <div>2. This receipt must be provided when demanded.</div>
            <div>3. Fees once paid will not be refunded.</div>
            <button class="print-btn-inline" onclick="window.print()">Print</button>
        </div>
        <div class="slip-sign">Authorised Seal &amp; Signature</div>
    </div>
</div>

<script>
    if (new URLSearchParams(location.search).get('print') === '1') {
        window.addEventListener('load', () => setTimeout(() => window.print(), 400));
    }
</script>
</body>
</html>
