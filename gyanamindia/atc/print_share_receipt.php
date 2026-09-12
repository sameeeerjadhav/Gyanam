<?php
/**
 * Gyanam Portal — ATC / Admin: Print Share Receipt
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['ATC CENTER', 'Admin']);

$pdo   = getDBConnection();
$role  = $_SESSION['user_role'] ?? '';
$sessionAtcId = isset($_SESSION['atc_id']) ? (int)$_SESSION['atc_id'] : 0;
$receiptId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$receiptId) {
    die("Invalid receipt ID.");
}

// Admin can view any receipt; ATC can only view their own
if ($role === 'Admin') {
    $stmt = $pdo->prepare("SELECT * FROM share_payments WHERE id = ? AND status = 'Completed'");
    $stmt->execute([$receiptId]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM share_payments WHERE id = ? AND atc_id = ? AND status = 'Completed'");
    $stmt->execute([$receiptId, $sessionAtcId]);
}
$receipt = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$receipt) {
    die("Receipt not found or payment not completed.");
}

// Always use the ATC from the payment row (Admin sessions have no atc_id)
$atcId = (int)($receipt['atc_id'] ?? 0);

// Fetch ATC Details
$atc = [];
if ($atcId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM atc_centers WHERE id = ?");
    $stmt->execute([$atcId]);
    $atc = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Fetch students linked on this payment
$studentIdsDecoded = json_decode((string)($receipt['student_ids'] ?? '[]'), true);
if (!is_array($studentIdsDecoded)) {
    $studentIdsDecoded = [];
}
$studentIdsDecoded = array_values(array_filter(array_map('intval', $studentIdsDecoded), fn($id) => $id > 0));
$students = [];
if (!empty($studentIdsDecoded)) {
    $inList = implode(',', $studentIdsDecoded);
    // Prefer scoped to payment ATC; fall back without atc filter if needed
    $stmt = $pdo->prepare("
        SELECT roll_no, first_name, middle_name, last_name, course
        FROM admissions
        WHERE id IN ($inList)
        " . ($atcId > 0 ? ' AND atc_id = ?' : '') . "
        ORDER BY FIELD(id, $inList)
    ");
    $stmt->execute($atcId > 0 ? [$atcId] : []);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$atcCode = $atc['atc_code'] ?? ($atc['center_code'] ?? ($atc['id'] ?? '—'));
$atcName = $atc['name'] ?? '—';
$atcMobile = $atc['mobile'] ?? ($atc['phone'] ?? '—');
$txnId = $receipt['razorpay_payment_id'] ?: ($receipt['remarks'] ?? ('#' . $receipt['id']));

$receiptNo = 'GYANAM-' . date('Y', strtotime($receipt['created_at'])) . '-' . $receipt['id'];
$printDate = date('d M Y, h:i A');
$txnSource = $receipt['created_at'];
if (!empty($receipt['paid_at']) && !preg_match('/\s00:00:00$/', (string)$receipt['paid_at'])) {
    $txnSource = $receipt['paid_at'];
}
$txnDate = date('d M Y, h:i A', strtotime($txnSource));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <?php include __DIR__ . '/../includes/head_fonts.php'; ?>
<title>Receipt <?= $receiptNo ?> - Gyanam India Educational Services</title>
    <style>
        @page { size: A4; margin: 15mm; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #fff; color: #111827; margin: 0; padding: 0; line-height: 1.5; }
        .receipt-container { max-width: 800px; margin: 0 auto; padding: 20px; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #4361ee; padding-bottom: 15px; margin-bottom: 20px; }
        .logo { max-width: 150px; }
        .company-details { text-align: right; font-size: 0.85rem; color: #4b5563; }
        .company-details h2 { margin: 0 0 5px 0; color: #4361ee; font-size: 1.25rem; font-weight: 800; }
        .receipt-title { text-align: center; margin-bottom: 30px; }
        .receipt-title h1 { margin: 0; font-size: 1.5rem; text-transform: uppercase; letter-spacing: 2px; color: #1f2937; }
        .receipt-title p { margin: 5px 0 0 0; font-size: 0.9rem; color: #6b7280; }

        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .info-box { background: #f9fafb; padding: 15px; border-radius: 6px; border: 1px solid #f3f4f6; }
        .info-row { display: flex; margin-bottom: 8px; font-size: 0.9rem; }
        .info-row:last-child { margin-bottom: 0; }
        .info-label { width: 130px; font-weight: 600; color: #4b5563; }
        .info-value { font-weight: 600; color: #111827; }

        .dataTable { width: 100%; border-collapse: collapse; margin-bottom: 30px; font-size: 0.9rem; }
        .dataTable th { background: #4361ee; color: white; padding: 10px; text-align: left; font-weight: 600; }
        .dataTable td { padding: 10px; border-bottom: 1px solid #e5e7eb; }
        .dataTable tbody tr:last-child td { border-bottom: 2px solid #e5e7eb; }

        .summaryTable { width: 300px; float: right; border-collapse: collapse; margin-bottom: 30px; font-size: 0.95rem; }
        .summaryTable td { padding: 8px 10px; border-bottom: 1px solid #f3f4f6; }
        .summaryTable tr:last-child td { border-bottom: none; border-top: 2px solid #111827; font-weight: 800; font-size: 1.05rem; }
        .summaryTable .label { color: #4b5563; font-weight: 600; }
        .summaryTable .val { text-align: right; color: #111827; }

        .clearfix::after { content: ""; clear: both; display: table; }

        .footer { clear: both; margin-top: 50px; text-align: center; font-size: 0.8rem; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 20px; }

        @media print {
            body { background: white; }
            .receipt-container { border: none; box-shadow: none; padding: 0; max-width: 100%; }
        }
    </style>
</head>
<body>

<div class="receipt-container">
    <div class="header">
        <div>
            <img src="../assets/logo.png" alt="Gyanam India" class="logo">
        </div>
        <div class="company-details">
            <h2>Gyanam India Educational Services</h2>
            Head Office Payment Receipt<br>
            Date Printed: <?= $printDate ?>
        </div>
    </div>

    <div class="receipt-title">
        <h1>Gyanam Share Receipt</h1>
        <p>Receipt No: <strong><?= $receiptNo ?></strong></p>
    </div>

    <div class="info-grid">
        <div class="info-box">
            <div class="info-row">
                <div class="info-label">ATC Center:</div>
                <div class="info-value"><?= htmlspecialchars((string)$atcName) ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">ATC Code/ID:</div>
                <div class="info-value"><?= htmlspecialchars((string)$atcCode) ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Contact:</div>
                <div class="info-value"><?= htmlspecialchars((string)$atcMobile) ?></div>
            </div>
        </div>
        <div class="info-box">
            <div class="info-row">
                <div class="info-label">Transaction ID:</div>
                <div class="info-value" style="word-break: break-all;"><?= htmlspecialchars((string)$txnId) ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Payment Date:</div>
                <div class="info-value"><?= $txnDate ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Status:</div>
                <div class="info-value" style="color:#059669;">Completed</div>
            </div>
        </div>
    </div>

    <h3 style="font-size: 1rem; color: #374151; margin-bottom: 10px;">Students Included (<?= count($students) ?>)</h3>
    <table class="dataTable">
        <thead>
            <tr>
                <th style="width: 50px;">#</th>
                <th style="width: 150px;">Roll No</th>
                <th>Student Name</th>
                <th>Course</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($students)): ?>
                <tr>
                    <td colspan="4" style="text-align: center; color: #6b7280;">No student details available.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($students as $idx => $s):
                    $name = trim($s['first_name'] . ' ' . ($s['middle_name'] ? $s['middle_name'] . ' ' : '') . $s['last_name']);
                ?>
                <tr>
                    <td><?= $idx + 1 ?></td>
                    <td><strong><?= htmlspecialchars($s['roll_no'] ?: '—') ?></strong></td>
                    <td><?= htmlspecialchars($name) ?></td>
                    <td><?= htmlspecialchars($s['course']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="clearfix">
        <table class="summaryTable">
            <tr>
                <td class="label">Total HO Share:</td>
                <td class="val">&#8377;<?= number_format((float)$receipt['total_share_amount'], 2) ?></td>
            </tr>
            <tr>
                <td class="label">Transaction Fee:</td>
                <td class="val">&#8377;<?= number_format((float)$receipt['transaction_fee'], 2) ?></td>
            </tr>
            <tr>
                <td class="label">Total Paid Amount:</td>
                <td class="val">&#8377;<?= number_format((float)$receipt['total_amount'], 2) ?></td>
            </tr>
        </table>
    </div>

    <div class="footer">
        <p>This is a computer-generated receipt and does not require a physical signature.</p>
        <p>&copy; <?= date('Y') ?> Gyanam India Educational Services. All rights reserved.</p>
    </div>
</div>

<script>
    window.onload = function() {
        window.print();
    }
</script>

</body>
</html>
