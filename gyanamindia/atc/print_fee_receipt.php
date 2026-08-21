<?php
header("Content-Type: text/html; charset=utf-8");
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin(['ATC CENTER']);

try {

$pdo   = getDBConnection();
$atcId = $_SESSION['atc_id'] ?? null;
if (!$atcId) die('<p style="font-family:sans-serif;padding:2rem">ATC ID not found. Please log in again.</p>');

$paymentId = intval($_GET['payment_id'] ?? 0);
$receiptNo = trim($_GET['receipt_no'] ?? '');
if (!$paymentId && !$receiptNo) die('<p style="font-family:sans-serif;padding:2rem">No payment specified.</p>');

// Check if atc_id column exists in fee_payments
$hasAtcId = $pdo->query("SHOW COLUMNS FROM fee_payments LIKE 'atc_id'")->rowCount() > 0;

// Fetch the specific payment record
if ($paymentId) {
    $stmt = $pdo->prepare("SELECT * FROM fee_payments WHERE id = ?");
    $stmt->execute([$paymentId]);
    $thisPayment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$thisPayment) {
        $legacyStmt = $pdo->prepare("SELECT remark_text FROM student_remarks WHERE id = ? AND atc_id = ? LIMIT 1");
        $legacyStmt->execute([$paymentId, $atcId]);
        $legacyRemark = $legacyStmt->fetchColumn();

        if ($legacyRemark && preg_match('/Receipt:\s*([A-Za-z0-9\-]+)/', (string)$legacyRemark, $m)) {
            $legacyReceiptNo = $m[1];
            $legacyPayStmt = $pdo->prepare("SELECT * FROM fee_payments WHERE receipt_no = ? LIMIT 1");
            $legacyPayStmt->execute([$legacyReceiptNo]);
            $thisPayment = $legacyPayStmt->fetch(PDO::FETCH_ASSOC);
        }
    }
} else {
    $stmt = $pdo->prepare("SELECT * FROM fee_payments WHERE receipt_no = ?");
    $stmt->execute([$receiptNo]);
    $thisPayment = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$thisPayment) die('<p style="font-family:sans-serif;padding:2rem">Receipt not found or access denied.</p>');

$isAuthorized = false;
if ($hasAtcId && !empty($thisPayment['atc_id']) && intval($thisPayment['atc_id']) === intval($atcId)) {
    $isAuthorized = true;
}

$ownerStmt = $pdo->prepare("SELECT id, atc_id FROM admissions WHERE id = ?");
$ownerStmt->execute([$thisPayment['admission_id']]);
$ownerAdmission = $ownerStmt->fetch(PDO::FETCH_ASSOC);
if ($ownerAdmission && intval($ownerAdmission['atc_id']) === intval($atcId)) {
    $isAuthorized = true;
}

if (!$isAuthorized) {
    die('<p style="font-family:sans-serif;padding:2rem">Receipt not found or access denied.</p>');
}

$admissionId = $thisPayment['admission_id'];

// Fetch student + ATC info including logo
$stmt = $pdo->prepare("
    SELECT a.*, 
           c.name as atc_name, c.address as atc_address, 
           c.mobile as atc_mobile, c.email as atc_email, 
           c.center_type, c.logo as atc_logo
    FROM admissions a
    JOIN atc_centers c ON a.atc_id = c.id
    WHERE a.id = ?
");
$stmt->execute([$admissionId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$student) die('<p style="font-family:sans-serif;padding:2rem">Student not found.</p>');

// Cumulative payments
$stmt = $pdo->prepare("
    SELECT * FROM fee_payments
    WHERE admission_id = ?
    AND (payment_date < ? OR (payment_date = ? AND id <= ?))
    ORDER BY installment_no ASC, payment_date ASC, id ASC
");
$stmt->execute([$admissionId, $thisPayment['payment_date'], $thisPayment['payment_date'], $thisPayment['id']]);
$allPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Parse next installment info
$nextDate   = $thisPayment['next_installment_date'] ?? null;
$nextAmount = $thisPayment['next_installment_amount'] ?? null;
if (!$nextDate && !empty($thisPayment['remarks'])) {
    if (preg_match('/Next installment:\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/', $thisPayment['remarks'], $m)) $nextDate = $m[1];
    if (preg_match('/Installment amt[^0-9]*([0-9,]+)/', $thisPayment['remarks'], $m)) $nextAmount = str_replace(',', '', $m[1]);
}

$studentName = trim($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']);
$totalPaid   = floatval($student['fees_paid']);
$netPayable  = floatval($student['net_payable'] ?? $student['fees_total']);
$balance     = max(0, $netPayable - $totalPaid);
$batchInfo   = trim((string)($thisPayment['description'] ?? ''));

// Logo URL
$logoUrl = '../assets/logo.png';
if (!empty($student['atc_logo'])) {
    $rawLogo = (string)$student['atc_logo'];
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
    $logoUrl .= '?v=' . urlencode((string)($student['updated_at'] ?? time()));
}

} catch (Exception $e) {
    echo '<p style="font-family:sans-serif;padding:2rem;color:red">Error loading receipt: ' . htmlspecialchars($e->getMessage()) . '</p>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fee Receipt &mdash; <?= htmlspecialchars($thisPayment['receipt_no']) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

/* ── Screen layout ── */
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

/* ── Receipt — A5 half-page ── */
.receipt {
    background: #fff;
    width: 100%;
    max-width: 560px;          /* ≈ A5 width on screen */
    border-radius: 14px;
    box-shadow: 0 8px 40px rgba(0,0,0,.14);
    overflow: hidden;
    font-size: 13px;
}

/* ── Header ── */
.rh {
    background: linear-gradient(135deg, #1e3a8a 0%, #4361ee 60%, #7c3aed 100%);
    color: #fff;
    padding: 1rem 1.25rem .9rem;
    position: relative;
    overflow: hidden;
}
.rh::before {
    content: '';
    position: absolute; top: -30px; right: -30px;
    width: 130px; height: 130px;
    border-radius: 50%;
    background: rgba(255,255,255,.07);
}
.rh-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: .75rem;
}
.rh-brand {
    display: flex;
    align-items: center;
    gap: .6rem;
    flex: 1;
    min-width: 0;
}
.rh-logo {
    width: 46px; height: 46px;
    border-radius: 8px;
    background: rgba(255,255,255,.18);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    overflow: hidden;
    border: 1.5px solid rgba(255,255,255,.3);
}
.rh-logo img { width: 100%; height: 100%; object-fit: contain; }
.rh-logo-placeholder { font-size: 1.4rem; }
.rh-org { flex: 1; min-width: 0; }
.rh-org-name { font-size: .95rem; font-weight: 800; letter-spacing: -.02em; line-height: 1.2; }
.rh-org-sub  { font-size: .7rem; opacity: .85; margin-top: .15rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rh-org-addr { font-size: .65rem; opacity: .7; margin-top: .1rem; }

.rh-meta { text-align: right; flex-shrink: 0; }
.rh-meta-label { font-size: .6rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; opacity: .7; }
.rh-meta-no    { font-size: .85rem; font-weight: 800; }
.rh-meta-date  { font-size: .65rem; opacity: .75; margin-top: .1rem; }

.rh-amount-row {
    margin-top: .75rem;
    padding-top: .75rem;
    border-top: 1px solid rgba(255,255,255,.2);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.rh-amount-label { font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; opacity: .75; }
.rh-amount-value { font-size: 1.6rem; font-weight: 900; letter-spacing: -.03em; }
.rh-paid-badge {
    background: rgba(255,255,255,.2);
    border: 1px solid rgba(255,255,255,.3);
    border-radius: 20px;
    padding: .2rem .7rem;
    font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
}

/* ── Body ── */
.rb { padding: .9rem 1.25rem; }

/* Student info */
.info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .45rem .75rem;
    margin-bottom: .75rem;
}
.info-item {}
.info-label { font-size: .6rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: #94a3b8; margin-bottom: .1rem; }
.info-val   { font-size: .8rem; font-weight: 600; color: #1e293b; }

.divider { border: none; border-top: 1.5px dashed #e2e8f0; margin: .65rem 0; }

/* Payments mini-table */
.sec-label { font-size: .6rem; font-weight: 800; text-transform: uppercase; letter-spacing: .07em; color: #64748b; margin-bottom: .45rem; }
.pay-table { width: 100%; border-collapse: collapse; font-size: .75rem; }
.pay-table thead th {
    background: #f8fafc;
    padding: .35rem .55rem;
    text-align: left;
    font-size: .6rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .05em;
    color: #94a3b8;
    border-bottom: 1.5px solid #e2e8f0;
}
.pay-table tbody td {
    padding: .4rem .55rem;
    border-bottom: 1px solid #f1f5f9;
    color: #374151;
    vertical-align: middle;
}
.pay-table tbody tr:last-child td { border-bottom: none; }
.pay-table tbody tr.current-row { background: #eff6ff; }
.pay-table tbody tr.current-row td { font-weight: 700; color: #1d4ed8; }
.inst-badge {
    display: inline-block; padding: .15rem .45rem;
    border-radius: 99px; font-size: .62rem; font-weight: 700;
    background: #e0e7ff; color: #4338ca;
}
.inst-badge.current { background: #dbeafe; color: #1d4ed8; }

/* Summary row */
.summary-strip {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: .5rem;
    background: linear-gradient(135deg, #f8fafc, #f0f4ff);
    border: 1.5px solid #e2e8f0;
    border-radius: 9px;
    padding: .65rem .75rem;
    margin-top: .65rem;
    text-align: center;
}
.ss-label { font-size: .58rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #94a3b8; margin-bottom: .2rem; }
.ss-val   { font-size: .9rem; font-weight: 800; }
.ss-val.green { color: #059669; }
.ss-val.red   { color: #dc2626; }
.ss-val.blue  { color: #4361ee; }

/* Next installment */
.next-box {
    margin-top: .6rem;
    background: #fffbeb;
    border: 1.5px solid #fde68a;
    border-radius: 8px;
    padding: .55rem .75rem;
    display: flex; gap: .75rem; align-items: center;
    font-size: .75rem;
}
.next-box svg { flex-shrink: 0; color: #d97706; width: 16px; height: 16px; }
.next-title  { font-size: .6rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #92400e; }
.next-detail { font-size: .78rem; font-weight: 700; color: #78350f; margin-top: .1rem; }

/* Footer */
.rf {
    background: #f8fafc;
    border-top: 1.5px solid #e2e8f0;
    padding: .55rem 1.25rem;
    display: flex; justify-content: space-between; align-items: center;
    font-size: .62rem; color: #94a3b8;
}
.rf-stamp {
    font-size: .62rem; font-weight: 700; color: #22c55e;
    display: flex; align-items: center; gap: .3rem;
}
.rf-stamp::before {
    content: ''; display: inline-block;
    width: 6px; height: 6px;
    border-radius: 50%; background: #22c55e;
}

/* Signature row */
.sig-row {
    display: flex;
    justify-content: flex-end;
    margin-top: .75rem;
    padding-top: .65rem;
    border-top: 1px dashed #e2e8f0;
}
.sig-box {
    text-align: center;
    min-width: 100px;
}
.sig-line { border-top: 1.5px solid #cbd5e1; margin-bottom: .25rem; margin-top: 1.5rem; }
.sig-label { font-size: .62rem; color: #94a3b8; font-weight: 600; }

/* ── Print styles — 2 per A4 page ── */
@media print {
    @page { size: A4; margin: 8mm; }

    body {
        background: #fff;
        padding: 0;
        display: block;
    }

    .action-bar,
    .hint { display: none !important; }

    .receipt {
        box-shadow: none;
        border-radius: 0;
        max-width: 100%;
        border: 1px solid #cbd5e1;
        /* Make exactly half A4 height */
        page-break-inside: avoid;
        break-inside:      avoid;
        width: 100%;
    }

    /*
     * Two-up printing: wrap two receipts in a single page.
     * The host page (fees.php or fee_receipt.php) only ever
     * renders one slip, so the @page + height constraint
     * produces a half-page receipt naturally.
     */
    .receipt { height: 133mm; overflow: hidden; }

    .rh    { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .inst-badge, .summary-strip { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
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

<style>
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

<div class="classic-slip">
    <div class="slip-head">
        <div class="slip-logo">
            <img src="<?= htmlspecialchars($logoUrl) ?>" alt="ATC Logo">
        </div>
        <div>
            <div class="slip-atc-name"><?= htmlspecialchars($student['atc_name'] ?? 'ATC Center') ?></div>
            <?php if (!empty($student['atc_address'])): ?><div class="slip-atc-line"><?= htmlspecialchars($student['atc_address']) ?></div><?php endif; ?>
            <?php if (!empty($student['atc_mobile'])): ?><div class="slip-atc-line"><?= htmlspecialchars($student['atc_mobile']) ?></div><?php endif; ?>
        </div>
    </div>

    <div class="slip-grid">
        <div class="slip-row"><span class="lbl">User ID:</span><span class="val"><?= htmlspecialchars($student['registration_id'] ?? '—') ?></span></div>
        <div class="slip-row"><span class="lbl">Course Fees:</span><span class="val"><?= number_format($netPayable, 0) ?></span></div>
        <div class="slip-row"><span class="lbl">Student Name:</span><span class="val"><?= htmlspecialchars($studentName) ?></span></div>
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
                <td><?= date('d/m/Y', strtotime($thisPayment['payment_date'])) ?></td>
                <td><?= htmlspecialchars($thisPayment['receipt_no']) ?></td>
                <td><?= number_format((float)$thisPayment['amount'], 0) ?> (<?= htmlspecialchars($thisPayment['payment_mode'] ?? 'Cash') ?>)</td>
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

</body>
</html>
