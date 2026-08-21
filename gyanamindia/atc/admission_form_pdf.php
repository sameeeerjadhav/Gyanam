<?php
/**
 * Gyanam Portal — ATC: Admission Form (Print / Auto-generate)
 * 2-page printable HTML form matching the official pink-border template.
 * ?auto=1  → auto-opens print dialog (used on enquiry→admission conversion)
 * No ?auto  → shows toolbar only (for later download/print)
 */
header("Content-Type: text/html; charset=utf-8");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin(['ATC CENTER']);

$pdo         = getDBConnection();
$atcId       = $_SESSION['atc_id'] ?? null;
$admissionId = intval($_GET['admission_id'] ?? 0);
$autoDownload = isset($_GET['auto']) && $_GET['auto'] === '1';

if (!$atcId || $admissionId <= 0) die('Invalid request.');

// ── Fetch student + ATC ──────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT a.*,
           c.name as atc_name, c.center_type, c.address as atc_address,
           c.mobile as atc_mobile, c.email as atc_email, c.logo as atc_logo
    FROM admissions a
    JOIN atc_centers c ON c.id = a.atc_id
    WHERE a.id = ? AND a.atc_id = ?
    LIMIT 1
");
$stmt->execute([$admissionId, $atcId]);
$admission = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$admission) die('Admission not found.');

// ── Fetch payment history ─────────────────────────────────────────────────────
$payments = [];
try {
    $payStmt = $pdo->prepare("SELECT installment_no, amount, payment_date, payment_mode, receipt_no FROM fee_payments WHERE admission_id = ? ORDER BY installment_no ASC, payment_date ASC, id ASC");
    $payStmt->execute([$admissionId]);
    $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Derived values ─────────────────────────────────────────────────────────────
$netPayable  = floatval($admission['net_payable'] ?? $admission['fees_total'] ?? 0);
$feesPaid    = floatval($admission['fees_paid'] ?? 0);
$feesPending = max(0, $netPayable - $feesPaid);

$studentName = trim(
    ($admission['first_name'] ?? '') . ' ' .
    ($admission['middle_name'] ? $admission['middle_name'] . ' ' : '') .
    ($admission['last_name'] ?? '')
);
$surname     = $admission['last_name']   ?? '';
$firstName   = $admission['first_name']  ?? '';
$middleName  = $admission['middle_name'] ?? '';

$gender  = strtolower($admission['gender'] ?? '');
$isMale  = in_array($gender, ['male','m']);
$isFem   = in_array($gender, ['female','f','female']);

$dob = !empty($admission['dob']) ? date('d-m-Y', strtotime($admission['dob'])) : '';
$age = '';
if (!empty($admission['dob'])) {
    try {
        $diff = (new DateTime())->diff(new DateTime($admission['dob']));
        $age  = $diff->y . ' yrs';
    } catch (Exception $e) {}
}

$admissionDate = !empty($admission['admission_date']) ? date('d-m-Y', strtotime($admission['admission_date'])) : date('d-m-Y');

// Address
$address  = trim($admission['address'] ?? '');
$city     = trim($admission['city'] ?? '');
$state    = trim($admission['state'] ?? '');
$pin      = trim($admission['pin_code'] ?? '');
$district = trim($admission['district'] ?? $state);
$fullAddr = implode(', ', array_filter([$address, $city, $district, $pin]));

// First payment for receipt table
$firstPayment = $payments[0] ?? null;
$firstReceiptNo = $firstPayment['receipt_no'] ?? '';
$firstDate      = !empty($firstPayment['payment_date']) ? date('d-m-Y', strtotime($firstPayment['payment_date'])) : '';
$firstAmount    = $firstPayment ? number_format(floatval($firstPayment['amount']), 0) : '';

// Build payment rows (up to 10)
$payRows = [];
$runningBalance = $netPayable;
foreach ($payments as $p) {
    $paid = floatval($p['amount'] ?? 0);
    $runningBalance -= $paid;
    $payRows[] = [
        'no'       => str_pad(count($payRows) + 1, 2, '0', STR_PAD_LEFT),
        'amount'   => number_format($paid, 0),
        'receipt'  => $p['receipt_no'] ?? '',
        'date'     => !empty($p['payment_date']) ? date('d-m-Y', strtotime($p['payment_date'])) : '',
        'balance'  => number_format(max(0, $runningBalance), 0),
    ];
}
// Fill empty rows up to 10
while (count($payRows) < 10) {
    $payRows[] = ['no' => str_pad(count($payRows) + 1, 2, '0', STR_PAD_LEFT), 'amount'=>'', 'receipt'=>'', 'date'=>'', 'balance'=>''];
}

// Logo (ATC or fallback)
$logoUrl = '../assets/logo.png';
if (!empty($admission['atc_logo'])) {
    $rawLogo = (string)$admission['atc_logo'];
    if (preg_match('#^https?://#i', $rawLogo)) {
        $logoUrl = $rawLogo;
    } else {
        $rel = ltrim($rawLogo, '/\\');
        $abs = realpath(__DIR__ . '/../' . $rel);
        if ($abs && is_file($abs)) $logoUrl = '../' . str_replace('\\', '/', $rel);
    }
}

// Student photo
$photoHtml = '<div style="width:100px;height:120px;border:2px solid #e91e63;display:flex;align-items:center;justify-content:center;color:#bbb;font-size:11px;">No Photo</div>';
if (!empty($admission['photo'])) {
    $photoPath = __DIR__ . '/../' . ltrim($admission['photo'], '/');
    if (is_file($photoPath)) {
        $raw  = @file_get_contents($photoPath);
        if ($raw !== false) {
            $mime = @mime_content_type($photoPath) ?: 'image/jpeg';
            $uri  = 'data:' . $mime . ';base64,' . base64_encode($raw);
            $photoHtml = '<img src="' . $uri . '" style="width:100px;height:120px;object-fit:cover;border:2px solid #e91e63;">';
        }
    }
}

// Helper
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function checked($cond) { return $cond ? '✔' : ''; }

// Qualification row
$qual = trim($admission['qualification'] ?? '');

// Activity/occupation - derive from a field if available
$occupation = strtolower($admission['occupation'] ?? $admission['present_activity'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admission Form — <?= e($admission['roll_no'] ?? $admissionId) ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; background: #eee; }

  /* Screen toolbar */
  .toolbar {
    display: flex; gap: .75rem; align-items: center;
    max-width: 900px; margin: 20px auto 0;
    padding: 0 10px;
  }
  .btn {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .6rem 1.25rem; border-radius: 8px; border: none;
    font-size: .875rem; font-weight: 700; cursor: pointer;
    font-family: Arial, sans-serif; text-decoration: none; transition: all .2s;
  }
  .btn-pink   { background: #e91e63; color: #fff; }
  .btn-pink:hover { background: #c2185b; }
  .btn-grey   { background: #fff; color: #374151; border: 1.5px solid #d1d5db; }
  .btn-grey:hover { background: #f3f4f6; }
  .btn svg    { width: 14px; height: 14px; }

  /* Pages */
  .page {
    width: 850px;
    margin: 20px auto;
    background: white;
    border: 4px solid #e91e63;
    padding: 15px;
  }

  h1, h2 { text-align: center; margin: 5px; color: #e91e63; }

  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  td, th { border: 1px solid #e91e63; padding: 6px; font-size: 13px; vertical-align: middle; }
  .no-border td { border: none; }
  .label { width: 18%; font-weight: bold; background: #fce4ec; }
  .center { text-align: center; }
  .section { background: #e91e63; color: white; font-weight: bold; text-align: center; }

  .photo-cell { width: 110px; text-align: center; vertical-align: top; }
  .logo-cell  { width: 130px; text-align: center; vertical-align: middle; }

  .checkbox-label { display: inline-flex; align-items: center; gap: 4px; margin-right: 10px; font-size: 13px; }
  .cb {
    display: inline-block; width: 14px; height: 14px;
    border: 1.5px solid #e91e63; margin-right: 2px;
    text-align: center; line-height: 13px; font-size: 11px;
    font-weight: bold; color: #e91e63;
  }
  .val-cell { font-weight: 600; color: #1f2937; text-transform: capitalize; }

  @media print {
    @page { size: A4 portrait; margin: 6mm; }
    html, body { margin: 0; padding: 0; background: #fff; }
    .toolbar { display: none !important; }

    .page {
      border: 2px solid #e91e63;
      margin: 0;
      padding: 10px;
      width: 198mm;          /* A4 width minus 6mm margins each side */
      height: 285mm;         /* A4 height minus 6mm margins each side */
      box-sizing: border-box;
      overflow: hidden;
      page-break-after: always;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }
    .page:last-child { page-break-after: avoid; }

    /* Scale content to fill the page */
    table { margin-top: 5px; width: 100%; border-collapse: collapse; }
    td, th { font-size: 11.5px; padding: 4px 5px; }

    /* Stretch rows with explicit heights proportionally */
    tr[style*="height:35px"], tr[style*="height: 35px"] { height: 30px !important; }
    tr[style*="height:30px"], tr[style*="height: 30px"] { height: 26px !important; }

    h1 { font-size: 14px; margin: 3px; }
    h2 { font-size: 12px; margin: 3px; }

    .photo-cell img { width: 75px !important; height: 90px !important; }
    .logo-cell img  { width: 85px !important; height: 56px !important; }
    .val-cell { font-size: 11.5px; }
    .section { font-size: 11px; }
    .cb { width: 12px; height: 12px; font-size: 10px; line-height: 12px; }
  }
</style>
</head>
<body>

<!-- ── Screen toolbar ── -->
<div class="toolbar" id="toolbar">
  <a href="javascript:history.back()" class="btn btn-grey">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
    Back
  </a>
  <button class="btn btn-pink" onclick="window.print()">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
    Print / Download PDF
  </button>
  <span style="font-size:.8rem;color:#6b7280;">Tip: Use Ctrl+P → Save as PDF to download</span>
</div>

<!-- ============================= PAGE 1 ============================= -->
<div class="page">

  <!-- Header: Logo | Title | Photo -->
  <table class="no-border">
    <tr>
      <td class="logo-cell">
        <img src="<?= e($logoUrl) ?>" alt="Logo" style="width:110px;height:75px;object-fit:contain;">
      </td>
      <td class="center">
        <h1>GYANAM INDIA EDUCATIONAL SERVICES</h1>
        <h2>ADMISSION FORM</h2>
        <div style="font-size:12px;color:#555;margin-top:3px;"><?= e($admission['atc_name'] ?? '') ?></div>
      </td>
      <td class="photo-cell"><?= $photoHtml ?></td>
    </tr>
  </table>

  <!-- ATC / Course info -->
  <table>
    <tr>
      <td class="label">ATC Name</td>
      <td colspan="3" class="val-cell"><?= e($admission['atc_name'] ?? '') ?></td>
      <td class="label">Roll No</td>
      <td class="val-cell"><?= e($admission['roll_no'] ?? '') ?></td>
    </tr>
    <tr>
      <td class="label">Course</td>
      <td class="val-cell"><?= e($admission['course'] ?? '') ?></td>
      <td class="label">Course Fees</td>
      <td class="val-cell">₹<?= number_format(floatval($admission['course_fees'] ?? $admission['fees_total'] ?? 0), 0) ?></td>
      <td class="label">Reg. ID</td>
      <td class="val-cell"><?= e($admission['registration_id'] ?? '') ?></td>
    </tr>
    <tr>
      <td class="label">Place</td>
      <td class="val-cell"><?= e($city) ?></td>
      <td class="label">Joining Date</td>
      <td class="val-cell"><?= e($admissionDate) ?></td>
      <td class="label">Net Payable</td>
      <td class="val-cell">₹<?= number_format($netPayable, 0) ?></td>
    </tr>
  </table>

  <!-- Name -->
  <table>
    <tr>
      <td class="center"><strong>Surname</strong></td>
      <td class="center"><strong>First Name</strong></td>
      <td class="center"><strong>Middle Name</strong></td>
    </tr>
    <tr style="height:35px;">
      <td class="val-cell" style="text-align:center;text-transform:uppercase;"><?= e($surname) ?></td>
      <td class="val-cell" style="text-align:center;text-transform:uppercase;"><?= e($firstName) ?></td>
      <td class="val-cell" style="text-align:center;text-transform:uppercase;"><?= e($middleName) ?></td>
    </tr>
  </table>

  <!-- Present Address -->
  <table>
    <tr>
      <td class="label">Present Address</td>
      <td colspan="5" class="val-cell" style="height:35px;"><?= e($address) ?></td>
    </tr>
    <tr>
      <td class="label">Nearest Landmark</td>
      <td colspan="5" class="val-cell"><?= e($admission['landmark'] ?? '') ?></td>
    </tr>
    <tr>
      <td class="label">City/Town</td>
      <td class="val-cell"><?= e($city) ?></td>
      <td class="label">Tq.</td>
      <td class="val-cell"><?= e($admission['taluka'] ?? '') ?></td>
      <td class="label">Dist.</td>
      <td class="val-cell"><?= e($district) ?></td>
    </tr>
  </table>

  <!-- Permanent Address (same if not separate field) -->
  <table>
    <tr>
      <td class="label">Permanent Address</td>
      <td colspan="5" class="val-cell" style="height:35px;"><?= e($admission['permanent_address'] ?? $address) ?></td>
    </tr>
    <tr>
      <td class="label">Nearest Landmark</td>
      <td colspan="5" class="val-cell"><?= e($admission['permanent_landmark'] ?? '') ?></td>
    </tr>
    <tr>
      <td class="label">City/Town</td>
      <td class="val-cell"><?= e($admission['permanent_city'] ?? $city) ?></td>
      <td class="label">Tq.</td>
      <td class="val-cell"><?= e($admission['permanent_taluka'] ?? '') ?></td>
      <td class="label">Dist.</td>
      <td class="val-cell"><?= e($admission['permanent_district'] ?? $district) ?></td>
    </tr>
  </table>

  <!-- Contact -->
  <table>
    <tr>
      <td class="label">Contact No (R)</td>
      <td class="val-cell"><?= e($admission['phone'] ?? '') ?></td>
      <td class="label">M1 (Mobile)</td>
      <td class="val-cell"><?= e($admission['mobile'] ?? '') ?></td>
      <td class="label">M2 (Alt)</td>
      <td class="val-cell"><?= e($admission['alternate_mobile'] ?? '') ?></td>
    </tr>
  </table>

  <!-- Personal -->
  <table>
    <tr>
      <td class="label">Date of Birth</td>
      <td class="val-cell"><?= e($dob) ?></td>
      <td class="label">Age</td>
      <td class="val-cell"><?= e($age) ?></td>
      <td class="label">Sex</td>
      <td>
        <span class="cb"><?= $isMale ? '✔' : '' ?></span> M &nbsp;&nbsp;
        <span class="cb"><?= $isFem  ? '✔' : '' ?></span> F
      </td>
    </tr>
    <tr>
      <td class="label">Father Name</td>
      <td class="val-cell"><?= e($admission['father_name'] ?? '') ?></td>
      <td class="label">Mother Name</td>
      <td class="val-cell"><?= e($admission['mother_name'] ?? '') ?></td>
      <td class="label">Category</td>
      <td class="val-cell"><?= e($admission['category'] ?? '') ?></td>
    </tr>
    <tr>
      <td class="label">Email</td>
      <td class="val-cell" colspan="3"><?= e($admission['email'] ?? '') ?></td>
      <td class="label">Reference</td>
      <td class="val-cell"><?= e($admission['referenced_by'] ?? '') ?></td>
    </tr>
  </table>

  <!-- Present Activity -->
  <table>
    <tr>
      <td class="label">Present Activity</td>
      <td colspan="5">
        <span class="cb"><?= stripos($occupation,'edu') !== false ? '✔' : '' ?></span> Education &nbsp;
        <span class="cb"><?= stripos($occupation,'job') !== false ? '✔' : '' ?></span> Job &nbsp;
        <span class="cb"><?= stripos($occupation,'busi') !== false ? '✔' : '' ?></span> Business &nbsp;
        <span class="cb"><?= stripos($occupation,'house') !== false ? '✔' : '' ?></span> Housewife &nbsp;
        <span class="cb"><?= (stripos($occupation,'other') !== false || ($occupation && !preg_match('/edu|job|busi|house/i', $occupation))) ? '✔' : '' ?></span> Other
      </td>
    </tr>
  </table>

  <!-- Education -->
  <table>
    <tr class="section">
      <td>Examination / Qualification</td>
      <td>Board/University</td>
      <td>Year of Passing</td>
      <td>Percentage / Grade</td>
    </tr>
    <tr style="height:35px;">
      <td class="val-cell"><?= e($qual) ?></td>
      <td class="val-cell"><?= e($admission['board'] ?? '') ?></td>
      <td class="val-cell"><?= e($admission['passing_year'] ?? '') ?></td>
      <td class="val-cell"><?= e($admission['percentage'] ?? '') ?></td>
    </tr>
  </table>

  <!-- Declaration -->
  <table>
    <tr>
      <td style="font-size:12px;color:#333;">
        <strong>DECLARATION:</strong> I hereby declare that all the information given above is true, correct and complete to the best of my knowledge and belief. I agree to abide by the rules and regulations of Gyanam India Educational Services.
      </td>
    </tr>
  </table>

  <!-- First Payment -->
  <table>
    <tr>
      <td class="label">First Receipt No</td>
      <td class="val-cell"><?= e($firstReceiptNo) ?></td>
      <td class="label">Date</td>
      <td class="val-cell"><?= e($firstDate) ?></td>
      <td class="label">Amount (₹)</td>
      <td class="val-cell"><?= e($firstAmount) ?></td>
    </tr>
  </table>

  <!-- Signatures -->
  <table style="margin-top:12px;">
    <tr>
      <td class="label">Place</td>
      <td class="val-cell"><?= e($city) ?></td>
      <td class="label" style="text-align:center;">Student Signature</td>
      <td style="height:40px;"></td>
    </tr>
    <tr>
      <td class="label">ATC Seal &amp; Stamp</td>
      <td style="height:50px;"></td>
      <td class="label" style="text-align:center;">Authorised Signatory</td>
      <td style="height:50px;"></td>
    </tr>
  </table>

</div><!-- /page 1 -->

<!-- ============================= PAGE 2 ============================= -->
<div class="page">

  <!-- Header -->
  <table class="no-border" style="margin-bottom:6px;">
    <tr>
      <td style="border:none;"><img src="<?= e($logoUrl) ?>" style="width:70px;height:50px;object-fit:contain;border:1px solid #e91e63;"></td>
      <td style="border:none;text-align:center;">
        <h2 style="font-size:16px;">IMPORTANT INSTRUCTIONS FOR STUDENTS</h2>
        <div style="font-size:12px;color:#555;"><?= e($studentName) ?> &nbsp;|&nbsp; <?= e($admission['roll_no'] ?? '') ?> &nbsp;|&nbsp; <?= e($admission['course'] ?? '') ?></div>
      </td>
    </tr>
  </table>

  <table>
    <tr><td style="background:#fce4ec;font-weight:bold;">1.</td><td>Training from authorized center is compulsory. Attendance must be maintained as per norms.</td></tr>
    <tr><td style="background:#fce4ec;font-weight:bold;">2.</td><td>Course fees must be paid as per the decided schedule. Monthly installments to be paid on time.</td></tr>
    <tr><td style="background:#fce4ec;font-weight:bold;">3.</td><td>Study material will be provided by the institute as per the course curriculum.</td></tr>
    <tr><td style="background:#fce4ec;font-weight:bold;">4.</td><td>Examinations will be conducted by the institute. Appearing in exams is compulsory.</td></tr>
    <tr><td style="background:#fce4ec;font-weight:bold;">5.</td><td>Fees once paid will not be refunded under any circumstances.</td></tr>
    <tr><td style="background:#fce4ec;font-weight:bold;">6.</td><td>The institute does not guarantee employment but provides training and certification.</td></tr>
    <tr><td style="background:#fce4ec;font-weight:bold;">7.</td><td>Any misbehaviour or violation of institute rules may lead to cancellation of admission.</td></tr>
  </table>

  <!-- Fee Summary -->
  <table style="margin-top:14px;">
    <tr class="section"><td colspan="6">FEE SUMMARY</td></tr>
    <tr>
      <td class="label">Course Fees</td>
      <td class="val-cell">₹<?= number_format(floatval($admission['course_fees'] ?? $admission['fees_total'] ?? 0), 0) ?></td>
      <td class="label">Discount</td>
      <td class="val-cell">₹<?= number_format(floatval($admission['discount_amount'] ?? 0), 0) ?></td>
      <td class="label">Net Payable</td>
      <td class="val-cell">₹<?= number_format($netPayable, 0) ?></td>
    </tr>
    <tr>
      <td class="label">Fees Paid</td>
      <td class="val-cell" style="color:#15803d;font-weight:bold;">₹<?= number_format($feesPaid, 0) ?></td>
      <td class="label">Balance Due</td>
      <td class="val-cell" style="color:#be123c;font-weight:bold;">₹<?= number_format($feesPending, 0) ?></td>
      <td class="label">Installments</td>
      <td class="val-cell"><?= intval($admission['installments'] ?? 1) ?></td>
    </tr>
  </table>

  <!-- INSTALLMENT PAYMENT TABLE -->
  <table style="margin-top:14px;">
    <tr class="section">
      <td style="width:8%;">Sr No</td>
      <td>Amount Paid (₹)</td>
      <td>Receipt No</td>
      <td>Date</td>
      <td>Balance (₹)</td>
    </tr>
    <?php foreach ($payRows as $row): ?>
    <tr style="height:30px;">
      <td class="center"><?= e($row['no']) ?></td>
      <td class="val-cell"><?= e($row['amount']) ?></td>
      <td class="val-cell" style="font-size:11px;"><?= e($row['receipt']) ?></td>
      <td class="val-cell"><?= e($row['date']) ?></td>
      <td class="val-cell"><?= e($row['balance']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <!-- STUDY MATERIAL TABLE -->
  <table style="margin-top:14px;">
    <tr class="section">
      <td style="width:8%;">Sr No</td>
      <td>Subject / Material Name</td>
      <td>Student Sign</td>
      <td>Date</td>
      <td>Staff Sign</td>
    </tr>
    <?php for ($i = 1; $i <= 10; $i++): ?>
    <tr style="height:30px;">
      <td class="center"><?= str_pad($i, 2, '0', STR_PAD_LEFT) ?></td>
      <td></td><td></td><td></td><td></td>
    </tr>
    <?php endfor; ?>
  </table>

  <!-- Bottom signature -->
  <table style="margin-top:14px;">
    <tr>
      <td class="label">Student Signature</td>
      <td style="height:45px;"></td>
      <td class="label">ATC Seal &amp; Authorised Sign</td>
      <td style="height:45px;"></td>
    </tr>
  </table>

</div><!-- /page 2 -->

<script>
<?php if ($autoDownload): ?>
// Auto-trigger print dialog when page loads (enquiry→admission conversion)
window.addEventListener('load', function() {
    setTimeout(function() { window.print(); }, 600);
});
<?php endif; ?>
</script>
</body>
</html>
