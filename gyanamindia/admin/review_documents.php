<?php
/**
 * TEMP — Admin document review hub
 * Preview all certificates, hall tickets, and templates in one place for QA/calibration.
 * Remove or hide from sidebar when review is complete.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
if (file_exists(__DIR__ . '/../includes/exam_integration.php')) {
    require_once __DIR__ . '/../includes/exam_integration.php';
}

requireLogin(['Admin']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());

// ── Embed previews (iframes) ───────────────────────────────────────────────────
$embed = trim($_GET['embed'] ?? '');
if ($embed !== '') {
    $studentId = (int)($_GET['student_id'] ?? 0);
    $atcId     = (int)($_GET['atc_id'] ?? 0);

    $student = null;
    $atc     = null;
    if ($studentId > 0) {
        $st = $pdo->prepare("
            SELECT a.*, es.exam_date AS sched_exam_date, es.exam_time AS sched_exam_time,
                   es.exam_slot AS sched_exam_slot, es.exam_hall AS sched_exam_hall
            FROM admissions a
            LEFT JOIN exam_schedules es ON es.admission_id = a.id AND es.atc_id = a.atc_id
            WHERE a.id = ? LIMIT 1
        ");
        $st->execute([$studentId]);
        $student = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($student) {
            $atcId = (int)$student['atc_id'];
        }
    }
    if ($atcId > 0) {
        $at = $pdo->prepare('SELECT * FROM atc_centers WHERE id = ? LIMIT 1');
        $at->execute([$atcId]);
        $atc = $at->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($embed === 'hall_ticket' && $student && $atc) {
        $fullName = trim($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']);
        $regNo    = $student['registration_id'] ?: ($student['roll_no'] ?: '-');
        $photo    = !empty($student['photo']) ? '../' . htmlspecialchars($student['photo']) : '../assets/logo.png';
        $examDate = !empty($student['sched_exam_date']) ? date('d M Y, l', strtotime($student['sched_exam_date'])) : 'To Be Announced';
        $examTime = 'To Be Announced';
        if (!empty($student['sched_exam_time'])) {
            [$h, $m] = array_map('intval', explode(':', $student['sched_exam_time'] . ':0'));
            $examTime = sprintf('%02d:%02d %s', ($h % 12) ?: 12, $m, $h >= 12 ? 'PM' : 'AM');
        }
        $examSlot = $student['sched_exam_slot'] ?? '-';
        $examAddr = htmlspecialchars(implode(', ', array_filter([
            $atc['address'] ?? '', $atc['city'] ?? '', $atc['district'] ?? '', $atc['state'] ?? '',
        ])) . (!empty($atc['pin_code']) ? ' - ' . $atc['pin_code'] : ''));
        $autoPrint   = isset($_GET['print']) && $_GET['print'] === '1';
        $inIframe    = isset($_GET['iframe']) && $_GET['iframe'] === '1';
        $showToolbar = !$inIframe && !$autoPrint;
        $bodyClass   = $autoPrint ? 'ht-a4-print' : 'ht-a4-viewport';
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<title>Hall Ticket — <?= htmlspecialchars($fullName) ?></title>
<link rel="stylesheet" href="../assets/css/hall_ticket_a4.css">
</head><body class="<?= $bodyClass ?>">
<?php if ($showToolbar): ?>
<div class="ht-print-toolbar no-print">
    <button type="button" class="ht-btn-secondary" onclick="window.close()">Close</button>
    <button type="button" onclick="window.print()">Print Hall Ticket</button>
</div>
<p class="ht-print-hint no-print">Tip: In the print dialog, turn off <b>Headers and footers</b> for a clean ticket.</p>
<?php endif; ?>
<div class="ht-print" id="hallTicketPrint">
  <table class="ht-print-tbl">
    <tr>
      <td class="ht-p-center" style="width:20%"><img src="../assets/logo.png" style="height:70px;object-fit:contain" alt=""></td>
      <td class="ht-p-center ht-p-bold" style="width:60%">
        <div style="font-size:20px">EXAMINATION HALL TICKET</div>
        <div><?= htmlspecialchars($student['course'] ?? 'Examination') ?></div>
      </td>
      <td style="width:20%"></td>
    </tr>
  </table>
  <br>
  <table class="ht-print-tbl">
    <tr><th colspan="4" class="ht-p-center">Candidate Details</th></tr>
    <tr>
      <td class="ht-p-bold" style="width:25%">Candidate Name</td>
      <td style="width:35%"><?= htmlspecialchars($fullName) ?></td>
      <td rowspan="3" colspan="2" class="ht-p-center">
        <img src="<?= $photo ?>" style="width:110px;height:140px;border:1px solid #000;object-fit:cover" alt="">
      </td>
    </tr>
    <tr><td class="ht-p-bold">Registration Number</td><td><?= htmlspecialchars($regNo) ?></td></tr>
    <tr><td class="ht-p-bold">Course</td><td><?= htmlspecialchars($student['course'] ?? '-') ?></td></tr>
  </table>
  <br>
  <table class="ht-print-tbl">
    <tr><th colspan="4" class="ht-p-center">Examination Details</th></tr>
    <tr>
      <td class="ht-p-bold">Examination Date</td><td><?= htmlspecialchars($examDate) ?></td>
      <td class="ht-p-bold">Exam Time</td><td><?= htmlspecialchars($examTime) ?></td>
    </tr>
    <tr>
      <td class="ht-p-bold">Exam Slot</td><td><?= htmlspecialchars($examSlot) ?></td>
      <td class="ht-p-bold">Exam User ID</td><td><?= htmlspecialchars($regNo) ?>@gyanamindia.in</td>
    </tr>
    <tr><td class="ht-p-bold">Exam Centre</td><td colspan="3"><?= htmlspecialchars($atc['name'] ?? '-') ?></td></tr>
    <tr><td class="ht-p-bold">Centre Address</td><td colspan="3"><?= $examAddr ?></td></tr>
  </table>
  <br>
  <table class="ht-print-tbl">
    <tr>
      <td class="ht-p-center ht-p-bold" style="height:90px">Student Signature</td>
      <td class="ht-p-center ht-p-bold" style="height:90px">Invigilator Signature</td>
    </tr>
  </table>
  <br>
  <table class="ht-print-tbl">
    <tr><th class="ht-p-center">Instructions for Students</th></tr>
    <tr>
      <td>
        <ol>
          <li>Report to the exam centre at least 30 minutes before the exam time.</li>
          <li>Candidate must carry the hall ticket and original photo ID.</li>
          <li>If any mistake is found in candidate details, report immediately to centre staff.</li>
          <li>Candidate must sign the attendance sheet before the start of exam.</li>
          <li>Opening any other window during the exam will terminate the exam.</li>
          <li>Use <b>FINISH EXAM</b> option carefully as it will end the exam.</li>
        </ol>
      </td>
    </tr>
  </table>
</div>
<?php if ($autoPrint): ?>
<script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 400); });</script>
<?php endif; ?>
</body></html>
        <?php
        exit;
    }

    if ($embed === 'completion_hall_ticket' && $student && $atc) {
        $fullName = trim($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']);
        $regNo    = $student['registration_id'] ?: ($student['roll_no'] ?: '-');
        $rollNo   = $student['roll_no'] ?: '-';
        $photo    = !empty($student['photo']) ? '../' . htmlspecialchars($student['photo']) : '../assets/logo.png';
        $mobile   = htmlspecialchars($student['mobile'] ?? '—');
        $examDt   = !empty($student['sched_exam_date']) ? date('d F Y', strtotime($student['sched_exam_date'])) : 'To Be Announced';
        $examTm   = 'To Be Announced';
        if (!empty($student['sched_exam_time'])) {
            [$h, $m] = array_map('intval', explode(':', $student['sched_exam_time'] . ':0'));
            $examTm = sprintf('%02d:%02d %s', ($h % 12) ?: 12, $m, $h >= 12 ? 'PM' : 'AM');
        }
        $examHall = htmlspecialchars($student['sched_exam_hall'] ?? '—');
        $atcName  = htmlspecialchars($atc['name'] ?? '-');
        $atcAddr  = htmlspecialchars($atc['address'] ?? '—');
        $atcPhone = htmlspecialchars($atc['mobile'] ?? 'N/A');
        $atcEmail = htmlspecialchars($atc['email'] ?? 'N/A');
        $autoPrint   = isset($_GET['print']) && $_GET['print'] === '1';
        $inIframe    = isset($_GET['iframe']) && $_GET['iframe'] === '1';
        $showToolbar = !$inIframe && !$autoPrint;
        $bodyClass   = $autoPrint ? 'cht-a4-print' : 'cht-a4-viewport';
        $printUrl    = 'review_documents.php?embed=completion_hall_ticket&print=1&student_id=' . (int)$student['id'] . '&atc_id=' . (int)$atc['id'];
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<title>Completion Hall Ticket — <?= htmlspecialchars($fullName) ?></title>
<link rel="stylesheet" href="../assets/css/completion_hall_ticket.css?v=1">
</head><body class="<?= $bodyClass ?>">
<?php if ($showToolbar): ?>
<div class="cht-print-toolbar no-print">
    <button type="button" class="cht-btn-secondary" onclick="window.close()">Close</button>
    <button type="button" onclick="window.location.href='<?= htmlspecialchars($printUrl) ?>'">Print Hall Ticket</button>
</div>
<p class="cht-print-hint no-print">Tip: In the print dialog, turn off <b>Headers and footers</b> for a clean ticket.</p>
<?php endif; ?>
<div class="cht-ticket-wrap">
<div class="hall-ticket" id="completionHallTicket">
    <div class="hall-ticket-header">
        <div class="hall-ticket-logo"><img src="../assets/logo.png" alt="Gyanam India"></div>
        <div class="hall-ticket-title">
            <h1>GYANAM INDIA EDUCATIONAL SERVICES</h1>
            <h2>EXAMINATION HALL TICKET</h2>
            <p class="hall-ticket-center"><?= $atcName ?></p>
        </div>
        <div class="hall-ticket-photo"><img src="<?= $photo ?>" alt="Student Photo"></div>
    </div>
    <div class="hall-ticket-body">
        <div class="hall-ticket-section">
            <h3>Candidate Details</h3>
            <table class="hall-ticket-table">
                <tr>
                    <td class="label">Roll Number:</td><td class="value"><strong><?= htmlspecialchars($rollNo) ?></strong></td>
                    <td class="label">Registration ID:</td><td class="value"><strong><?= htmlspecialchars($regNo) ?></strong></td>
                </tr>
                <tr>
                    <td class="label">Candidate Name:</td><td class="value" colspan="3"><strong><?= htmlspecialchars(strtoupper($fullName)) ?></strong></td>
                </tr>
                <tr>
                    <td class="label">Course:</td><td class="value"><strong><?= htmlspecialchars($student['course'] ?? '-') ?></strong></td>
                    <td class="label">Mobile:</td><td class="value"><?= $mobile ?></td>
                </tr>
            </table>
        </div>
        <div class="hall-ticket-section">
            <h3>Examination Details</h3>
            <table class="hall-ticket-table">
                <tr>
                    <td class="label">Exam Date:</td><td class="value"><strong><?= htmlspecialchars($examDt) ?></strong></td>
                    <td class="label">Exam Time:</td><td class="value"><strong><?= htmlspecialchars($examTm) ?></strong></td>
                </tr>
                <tr>
                    <td class="label">Exam Hall:</td><td class="value" colspan="3"><?= $examHall ?></td>
                </tr>
                <tr>
                    <td class="label">Center Name:</td><td class="value" colspan="3"><?= $atcName ?></td>
                </tr>
                <tr>
                    <td class="label">Address:</td><td class="value" colspan="3"><?= $atcAddr ?></td>
                </tr>
            </table>
        </div>
        <div class="hall-ticket-section">
            <h3>Important Instructions</h3>
            <ol class="hall-ticket-instructions">
                <li>Candidates must bring this hall ticket to the examination center.</li>
                <li>Candidates must reach 30 minutes before the exam starts.</li>
                <li>Mobile phones and electronic devices are strictly prohibited.</li>
                <li>Candidates must carry a valid photo ID proof along with this hall ticket.</li>
                <li>Use of unfair means will result in cancellation of the examination.</li>
            </ol>
        </div>
    </div>
    <div class="hall-ticket-footer">
        <div class="hall-ticket-signature">
            <div class="signature-box"><div class="signature-line"></div><p>Candidate's Signature</p></div>
            <div class="signature-box"><div class="signature-line"></div><p>Invigilator's Signature</p></div>
            <div class="signature-box"><div class="signature-line"></div><p>Center Superintendent</p></div>
        </div>
        <div class="hall-ticket-note">
            <p><strong>Note:</strong> Computer-generated, does not require stamp.</p>
            <p>Contact: <?= $atcPhone ?> | <?= $atcEmail ?></p>
        </div>
    </div>
</div>
</div>
<?php if ($autoPrint): ?>
<script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 400); });</script>
<?php endif; ?>
</body></html>
        <?php
        exit;
    }

    http_response_code(404);
    echo 'Preview not available — select a valid ATC and student.';
    exit;
}

// ── Sample data for previews ───────────────────────────────────────────────────
$atcs = $pdo->query("SELECT id, name, atc_code, center_type FROM atc_centers WHERE status='Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$selAtcId = (int)($_GET['atc_id'] ?? ($atcs[0]['id'] ?? 0));

$students = [];
if ($selAtcId > 0) {
    $ss = $pdo->prepare("
        SELECT a.id, a.roll_no, a.registration_id, a.first_name, a.last_name, a.middle_name,
               a.course, a.photo, a.atc_id
        FROM admissions a
        WHERE a.atc_id = ? AND a.status = 'Active'
        ORDER BY a.roll_no ASC
        LIMIT 300
    ");
    $ss->execute([$selAtcId]);
    $students = $ss->fetchAll(PDO::FETCH_ASSOC);
}

$selStudentId = (int)($_GET['student_id'] ?? 0);
if ($selStudentId <= 0) {
    foreach ($students as $s) {
        if (!empty($s['photo'])) {
            $selStudentId = (int)$s['id'];
            break;
        }
    }
    if ($selStudentId <= 0 && !empty($students)) {
        $selStudentId = (int)$students[0]['id'];
    }
}

$selStudent = null;
foreach ($students as $s) {
    if ((int)$s['id'] === $selStudentId) {
        $selStudent = $s;
        break;
    }
}

$selAtc = null;
foreach ($atcs as $a) {
    if ((int)$a['id'] === $selAtcId) {
        $selAtc = $a;
        break;
    }
}

$passRegId  = null;
$passNote   = '';
$tryReg     = '';
if ($selStudent) {
    $tryReg = trim((string)($selStudent['registration_id'] ?? ''));
    if ($tryReg === '') {
        $tryReg = trim((string)($selStudent['roll_no'] ?? ''));
    }
}
if ($selStudent && function_exists('examIntegrationReady') && examIntegrationReady()) {
    if ($tryReg !== '' && function_exists('fetchStudentPassingExamResult')) {
        $pass = fetchStudentPassingExamResult($tryReg);
        if ($pass) {
            $passRegId = $tryReg;
        } else {
            $passNote = 'Selected student has no passing main exam in Exam Portal — certificate preview unavailable.';
        }
    }
} elseif ($selStudent) {
    $passNote = 'Exam portal not connected — certificate preview unavailable.';
}

$marksBrand = strtolower(trim((string)($_GET['marks_brand'] ?? 'auto')));
if ($marksBrand !== 'it' && $marksBrand !== 'abacus') {
    $marksBrand = 'auto';
}
$marksQs = ['sample' => '1', 'preview' => '1', 'v' => '4'];
if ($tryReg !== '') {
    $marksQs['reg_id'] = $tryReg;
}
if ($selAtcId > 0) {
    $marksQs['atc_id'] = $selAtcId;
}
if ($marksBrand !== 'auto') {
    $marksQs['brand'] = $marksBrand;
}
$marksPreviewUrl = 'generate_marksheet.php?' . http_build_query($marksQs);

$tplBase = __DIR__ . '/../assets/templates/';
$templateFiles = [
    'giit_course_certificate.pdf'       => 'GIIT course completion (IT courses)',
    'giit_course_certificate.png'       => 'GIIT course completion (FPDI fallback PNG)',
    'gyanam_abacus_course_certificate.pdf' => 'Gyanam Abacus course completion (optional official PDF)',
    'gyanam_abacus_course_certificate.png' => 'Gyanam Abacus course completion (optional PNG)',
    'giit_auth_certificate.pdf'         => 'GIIT IT authorization',
    'gyanam_abacus_auth_certificate.pdf'=> 'Gyanam Abacus authorization',
    'gyanam_marksheet_stamp.png'        => 'Gyanam marksheet authorized-signatory stamp',
];

$authVariants = $selAtc ? atcAuthCertificateVariants($selAtc['center_type'] ?? '') : [];

$docSections = [
    'certificates_pdf' => [
        'title' => 'Official PDF certificates',
        'items' => [],
    ],
    'hall_tickets' => [
        'title' => 'Hall tickets',
        'items' => [],
    ],
    'tools' => [
        'title' => 'Templates & calibration',
        'items' => [],
    ],
];

// Course completion PDF (brand follows course type)
$docSections['certificates_pdf']['items'][] = [
    'name'        => 'Course Completion Certificate',
    'desc'        => 'IT courses use the GIIT template. Abacus / Vedic Maths courses use Gyanam Abacus. Same generator for Admin and ATC.',
    'live'        => 'atc/completion_certificate.php · admin/course_certificates.php',
    'preview_url' => $passRegId ? ('generate_course_certificate.php?reg_id=' . urlencode($passRegId) . '&preview=1') : null,
    'preview_note'=> $passNote ?: 'Preview uses the selected student’s course type.',
    'code'        => 'admin/generate_course_certificate.php',
];

$docSections['certificates_pdf']['items'][] = [
    'name'        => 'Statement of Marks (Marksheet)',
    'desc'        => 'MCCE layout with Gyanam logo and stamp. Review uses dummy marks (82 / A+) so you can check layout without an exam result. Live print still needs a real exam.',
    'live'        => 'admin/print_certificates.php · atc/exam_results.php · atc/completion_certificate.php',
    'preview_url' => $marksPreviewUrl,
    'preview_note'=> $tryReg !== ''
        ? 'Dummy marks on the selected student/ATC. Open full preview for A4.'
        : 'Dummy student + selected ATC. Open full preview for A4.',
    'code'        => 'admin/generate_marksheet.php',
];

foreach ($authVariants as $v) {
    $tplOk = atcAuthCertificateTemplatePath($v['variant']) !== null;
    $docSections['certificates_pdf']['items'][] = [
        'name'        => $v['label'],
        'desc'        => $v['brand'] . ' authorization PDF for ATC centers.',
        'live'        => 'admin/authorization_certificates.php',
        'preview_url' => ($selAtcId && $tplOk) ? ('generate_auth_certificate.php?atc_id=' . $selAtcId . '&variant=' . urlencode($v['variant']) . '&preview=1') : null,
        'preview_note'=> $tplOk ? null : 'Template PDF missing in assets/templates/',
        'code'        => 'admin/generate_auth_certificate.php',
    ];
}

$docSections['hall_tickets']['items'][] = [
    'name'        => 'Examination Hall Ticket',
    'desc'        => 'Generated per student on ATC Hall Tickets (requires share paid + photo).',
    'live'        => 'atc/hall_tickets.php',
    'preview_url' => ($selStudentId && $selAtcId) ? ('review_documents.php?embed=hall_ticket&iframe=1&student_id=' . $selStudentId . '&atc_id=' . $selAtcId) : null,
    'open_url'    => ($selStudentId && $selAtcId) ? ('review_documents.php?embed=hall_ticket&view=1&student_id=' . $selStudentId . '&atc_id=' . $selAtcId) : null,
    'print_url'   => ($selStudentId && $selAtcId) ? ('review_documents.php?embed=hall_ticket&print=1&student_id=' . $selStudentId . '&atc_id=' . $selAtcId) : null,
    'preview_note' => 'Use Print for a clean A4 page. In the browser print dialog, turn off Headers and footers.',
    'code'        => 'atc/hall_tickets.php',
];
$docSections['hall_tickets']['items'][] = [
    'name'        => 'Completion Hall Ticket',
    'desc'        => 'Separate hall ticket flow on ATC Completion Hall Ticket page (uses exam schedule date/time/hall).',
    'live'        => 'atc/completion_hall_ticket.php',
    'preview_url' => ($selStudentId && $selAtcId) ? ('review_documents.php?embed=completion_hall_ticket&iframe=1&student_id=' . $selStudentId . '&atc_id=' . $selAtcId) : null,
    'open_url'    => ($selStudentId && $selAtcId) ? ('review_documents.php?embed=completion_hall_ticket&view=1&student_id=' . $selStudentId . '&atc_id=' . $selAtcId) : null,
    'print_url'   => ($selStudentId && $selAtcId) ? ('review_documents.php?embed=completion_hall_ticket&print=1&student_id=' . $selStudentId . '&atc_id=' . $selAtcId) : null,
    'preview_note' => 'Exam date/time/hall come from exam_schedules. Schedule a student on the ATC page if fields show “To Be Announced”.',
    'code'        => 'atc/completion_hall_ticket.php',
];

foreach ($templateFiles as $file => $label) {
    $path = $tplBase . $file;
    $docSections['tools']['items'][] = [
        'name'        => $label,
        'desc'        => basename($path),
        'live'        => 'assets/templates/',
        'preview_url' => is_file($path) ? ('../assets/templates/' . rawurlencode($file)) : null,
        'preview_note'=> is_file($path) ? null : 'File not found',
        'code'        => 'assets/templates/' . $file,
    ];
}
$docSections['tools']['items'][] = [
    'name'        => 'Auth certificate coordinate grid',
    'desc'        => 'Red guide lines for PDF text placement (IT / Abacus).',
    'live'        => 'admin/cert_calibrate.php',
    'preview_url' => 'cert_calibrate.php?variant=it',
    'code'        => 'admin/cert_calibrate.php',
];
$docSections['tools']['items'][] = [
    'name'        => 'Auth certificate coordinate grid (Abacus)',
    'desc'        => 'Abacus template calibration.',
    'live'        => 'admin/cert_calibrate.php',
    'preview_url' => 'cert_calibrate.php?variant=abacus',
    'code'        => 'admin/cert_calibrate.php',
];

$pageTitle = 'Review Documents (TEMP)';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> — Admin | Gyanam India</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<style>
:root { --font:'Sora',sans-serif; --mono:'JetBrains Mono',monospace; }
.rv-page { padding:1.75rem 2rem; max-width:1400px; }
.rv-banner { background:linear-gradient(135deg,#fef3c7,#fde68a); border:1.5px solid #f59e0b; border-radius:14px; padding:1rem 1.25rem; margin-bottom:1.5rem; }
.rv-banner strong { color:#92400e; }
.rv-banner p { margin:.35rem 0 0; font-size:.84rem; color:#78350f; }
.rv-filters { display:flex; gap:.75rem; flex-wrap:wrap; align-items:flex-end; background:#fff; border:1.5px solid var(--border-color,#e5e7eb); border-radius:14px; padding:1rem 1.15rem; margin-bottom:1.5rem; }
.rv-filters label { display:block; font-size:.72rem; font-weight:800; color:#6b7280; margin-bottom:.25rem; text-transform:uppercase; letter-spacing:.04em; }
.rv-filters select { min-width:220px; height:40px; border:1.5px solid #e5e7eb; border-radius:8px; padding:0 .75rem; font-family:var(--font); font-size:.84rem; }
.rv-filters button { height:40px; padding:0 1.1rem; border:none; border-radius:8px; background:#6366f1; color:#fff; font-weight:800; cursor:pointer; font-family:var(--font); }
.rv-section { margin-bottom:2rem; }
.rv-section h3 { font-size:1rem; font-weight:800; margin:0 0 .85rem; color:#111; display:flex; align-items:center; gap:.5rem; }
.rv-section h3 span { font-size:.72rem; font-weight:700; background:#eef1fd; color:#4361ee; padding:.15rem .55rem; border-radius:999px; }
.rv-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:1rem; }
.rv-card { background:#fff; border:1.5px solid #e5e7eb; border-radius:14px; overflow:hidden; display:flex; flex-direction:column; }
.rv-card-head { padding:1rem 1.1rem .75rem; border-bottom:1px solid #f3f4f6; }
.rv-card-head h4 { margin:0 0 .35rem; font-size:.92rem; font-weight:800; color:#111; }
.rv-card-head p { margin:0; font-size:.78rem; color:#6b7280; line-height:1.45; }
.rv-meta { font-size:.7rem; color:#9ca3af; margin-top:.45rem; font-family:var(--mono); }
.rv-live { font-size:.72rem; color:#059669; font-weight:700; margin-top:.35rem; }
.rv-preview { flex:1; min-height:280px; background:#f9fafb; border-top:1px solid #f3f4f6; position:relative; }
.rv-preview iframe { width:100%; height:320px; border:0; background:#fff; }
.rv-preview-empty { height:320px; display:flex; align-items:center; justify-content:center; padding:1rem; text-align:center; font-size:.8rem; color:#9ca3af; }
.rv-actions { display:flex; gap:.5rem; padding:.75rem 1rem; border-top:1px solid #f3f4f6; flex-wrap:wrap; }
.rv-btn { display:inline-flex; align-items:center; gap:.3rem; padding:.4rem .85rem; border-radius:8px; font-size:.76rem; font-weight:800; text-decoration:none; border:1.5px solid #e5e7eb; background:#fff; color:#374151; }
.rv-btn.primary { background:#ecfdf5; border-color:#a7f3d0; color:#065f46; }
.rv-btn:hover { transform:translateY(-1px); }
.rv-yesno { display:inline-block; font-size:.68rem; font-weight:800; padding:.12rem .45rem; border-radius:999px; margin-left:.35rem; }
.rv-yesno.ok { background:#d1fae5; color:#065f46; }
.rv-yesno.no { background:#fee2e2; color:#991b1b; }
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <div class="page-content rv-page">

        <div class="page-header" style="margin-bottom:1rem">
            <div>
                <h2>Review Documents <span style="font-size:.75rem;background:#fef3c7;color:#92400e;padding:.2rem .55rem;border-radius:999px;vertical-align:middle">TEMP</span></h2>
                <p>Preview every certificate &amp; hall ticket type in one place for layout review</p>
            </div>
        </div>

        <div class="rv-banner">
            <strong>Temporary QA page</strong>
            <p>Hall tickets live at <b>ATC → Hall Tickets</b> (<code>atc/hall_tickets.php</code>). Use the selectors below to pick sample data, then open previews. Remove this page from the sidebar when review is done.</p>
        </div>

        <form method="get" class="rv-filters">
            <div>
                <label>Sample ATC</label>
                <select name="atc_id" id="rvAtc" onchange="document.getElementById('rvStudent').value=''; this.form.submit()">
                    <?php foreach ($atcs as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === $selAtcId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['name']) ?> (<?= htmlspecialchars($a['atc_code'] ?? '—') ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Sample student</label>
                <select name="student_id" id="rvStudent">
                    <?php foreach ($students as $s):
                        $sn = trim($s['first_name'] . ' ' . $s['last_name']);
                        $hasPhoto = !empty($s['photo']);
                    ?>
                    <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $selStudentId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sn) ?> · <?= htmlspecialchars($s['roll_no']) ?><?= $hasPhoto ? ' · photo' : ' · no photo' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Marksheet dummy brand</label>
                <select name="marks_brand">
                    <option value="auto" <?= $marksBrand === 'auto' ? 'selected' : '' ?>>Auto (from course)</option>
                    <option value="it" <?= $marksBrand === 'it' ? 'selected' : '' ?>>GIIT (IT sample)</option>
                    <option value="abacus" <?= $marksBrand === 'abacus' ? 'selected' : '' ?>>Gyanam Abacus sample</option>
                </select>
            </div>
            <button type="submit">Apply sample data</button>
        </form>

        <?php foreach ($docSections as $section): ?>
        <div class="rv-section">
            <h3><?= htmlspecialchars($section['title']) ?> <span><?= count($section['items']) ?></span></h3>
            <div class="rv-grid">
                <?php foreach ($section['items'] as $item):
                    $preview   = $item['preview_url'] ?? null;
                    $openUrl   = $item['open_url'] ?? $preview;
                    $printUrl  = $item['print_url'] ?? null;
                    $note      = $item['preview_note'] ?? null;
                ?>
                <div class="rv-card">
                    <div class="rv-card-head">
                        <h4><?= htmlspecialchars($item['name']) ?></h4>
                        <p><?= htmlspecialchars($item['desc']) ?></p>
                        <div class="rv-live">Live: <?= htmlspecialchars($item['live']) ?></div>
                        <div class="rv-meta"><?= htmlspecialchars($item['code']) ?></div>
                    </div>
                    <div class="rv-preview">
                        <?php if ($preview): ?>
                        <iframe src="<?= htmlspecialchars($preview) ?>" title="<?= htmlspecialchars($item['name']) ?>" loading="lazy"></iframe>
                        <?php else: ?>
                        <div class="rv-preview-empty"><?= htmlspecialchars($note ?: 'Preview not available for current selection.') ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="rv-actions">
                        <?php if ($preview): ?>
                        <a class="rv-btn primary" href="<?= htmlspecialchars($openUrl) ?>" target="_blank" rel="noopener">Open full preview</a>
                        <?php if ($printUrl): ?>
                        <a class="rv-btn" href="<?= htmlspecialchars($printUrl) ?>" target="_blank" rel="noopener">Print</a>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($note && $preview): ?>
                        <span class="rv-btn" style="cursor:default;opacity:.85"><?= htmlspecialchars($note) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="rv-section">
            <h3>Quick answers</h3>
            <div class="rv-card" style="padding:1rem 1.15rem">
                <p style="font-size:.86rem;line-height:1.6;margin:0;color:#374151">
                    <b>Hall ticket?</b> Yes — ATC portal → <b>Hall Tickets</b> (<code>atc/hall_tickets.php</code>). Requires HO share paid + student photo.<br>
                    <b>Course completion PDF?</b> IT courses → GIIT template; Abacus/Vedic → Gyanam Abacus. Admin → Course Certificates or ATC → Completion Certificates.<br>
                    <b>Auth PDFs?</b> Admin → Auth Certificates (GIIT IT + Gyanam Abacus by center type).<br>
                    <b>Exam connection?</b>
                    <?php if (function_exists('examIntegrationReady') && examIntegrationReady()): ?>
                    <span class="rv-yesno ok">Connected</span>
                    <?php else: ?>
                    <span class="rv-yesno no">Not configured</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

    </div>
</main>
</div>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>
