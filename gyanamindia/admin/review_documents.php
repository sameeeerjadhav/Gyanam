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
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<title>Hall Ticket Preview</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;background:#fff;margin:0;padding:12px;color:#111}
.ht-print-tbl{width:100%;border-collapse:collapse;font-size:13px}
.ht-print-tbl th,.ht-print-tbl td{border:1px solid #000;padding:6px 8px;vertical-align:top}
.ht-p-center{text-align:center}
.ht-p-bold{font-weight:700}
</style>
</head><body>
<div class="ht-print">
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
</div>
</body></html>
        <?php
        exit;
    }

    if ($embed === 'completion_html' && $student && $atc) {
        $fullName = trim($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']);
        $regId    = $student['registration_id'] ?: ($student['roll_no'] ?? '');
        $photo    = !empty($student['photo']) ? '../' . htmlspecialchars($student['photo']) : '';
        $certNo   = 'CERT-' . ($atc['atc_code'] ?? 'ATC') . '-' . ($student['roll_no'] ?? '') . '-' . date('Y');
        $today    = date('d F Y');
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<title>Completion Certificate Preview</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet">
<style>
body{font-family:Arial,Helvetica,sans-serif;background:#f5f5f5;margin:0;padding:16px}
.certificate{max-width:210mm;margin:0 auto;background:#fff;padding:2rem;border:4px solid #4361ee;border-radius:12px;position:relative}
.cert-head{display:flex;align-items:center;justify-content:space-between;padding-bottom:1.25rem;border-bottom:3px double #4361ee;margin-bottom:1.25rem;gap:1rem}
.cert-logo img{width:80px;height:80px;object-fit:contain}
.cert-org{flex:1;text-align:center}
.cert-org h1{font-size:20px;font-weight:800;color:#4361ee;margin:0 0 4px;text-transform:uppercase}
.cert-org h2{font-size:13px;color:#7c3aed;font-weight:700;margin:0 0 2px;text-transform:uppercase}
.cert-org p{font-size:11px;color:#6b7280;margin:0}
.cert-student-photo{width:100px;height:120px;border:2px solid #4361ee;object-fit:cover;border-radius:4px}
.cert-body{text-align:center;padding:1rem 0}
.cert-student-name{font-family:'Playfair Display',Georgia,serif;font-size:32px;font-weight:700;color:#4361ee;margin:.3rem 0}
.cert-course-name{font-size:20px;font-weight:800;color:#7c3aed;margin:.25rem 0;text-transform:uppercase}
.cert-details-bar{display:flex;flex-wrap:wrap;justify-content:center;gap:.5rem 1.25rem;background:#eef1fd;border-radius:8px;padding:.75rem;font-size:11.5px;margin-top:1rem}
</style>
</head><body>
<div class="certificate">
  <div class="cert-head">
    <div class="cert-logo"><img src="../assets/logo.png" alt=""></div>
    <div class="cert-org">
      <h1>Gyanam India Educational Services</h1>
      <h2>Certificate of Course Completion</h2>
      <p>ATC HTML certificate (Completion Certificate page)</p>
    </div>
    <?php if ($photo): ?><img src="<?= $photo ?>" class="cert-student-photo" alt=""><?php endif; ?>
  </div>
  <div class="cert-body">
    <p style="font-style:italic;color:#6b7280">This is to certify that</p>
    <div class="cert-student-name"><?= htmlspecialchars(strtoupper($fullName)) ?></div>
    <p>has successfully completed the course</p>
    <div class="cert-course-name"><?= htmlspecialchars($student['course'] ?? '') ?></div>
  </div>
  <div class="cert-details-bar">
    <span><strong>Roll:</strong> <?= htmlspecialchars($student['roll_no'] ?? '') ?></span>
    <span><strong>Reg:</strong> <?= htmlspecialchars($regId) ?></span>
    <span><strong>Center:</strong> <?= htmlspecialchars($atc['name'] ?? '') ?></span>
    <span><strong>Date:</strong> <?= $today ?></span>
    <span><strong>Cert No:</strong> <?= htmlspecialchars($certNo) ?></span>
  </div>
</div>
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

$passRegId = null;
$passNote  = '';
if ($selStudent && function_exists('examIntegrationReady') && examIntegrationReady()) {
    $tryReg = trim((string)($selStudent['registration_id'] ?? ''));
    if ($tryReg === '') {
        $tryReg = trim((string)($selStudent['roll_no'] ?? ''));
    }
    if ($tryReg !== '' && function_exists('fetchStudentPassingExamResult')) {
        $pass = fetchStudentPassingExamResult($tryReg);
        if ($pass) {
            $passRegId = $tryReg;
        } else {
            $passNote = 'Selected student has no passing main exam in Exam Portal — GIIT PDF preview unavailable.';
        }
    }
} elseif ($selStudent) {
    $passNote = 'Exam portal not connected — GIIT PDF preview unavailable.';
}

$tplBase = __DIR__ . '/../assets/templates/';
$templateFiles = [
    'giit_course_certificate.pdf'       => 'GIIT course completion (source PDF)',
    'giit_course_certificate.png'       => 'GIIT course completion (FPDI fallback PNG)',
    'giit_auth_certificate.pdf'         => 'GIIT IT authorization',
    'gyanam_abacus_auth_certificate.pdf'=> 'Gyanam Abacus authorization',
];

$authVariants = $selAtc ? atcAuthCertificateVariants($selAtc['center_type'] ?? '') : [];

$docSections = [
    'certificates_pdf' => [
        'title' => 'Official PDF certificates',
        'items' => [],
    ],
    'certificates_html' => [
        'title' => 'HTML certificates (browser print)',
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

// GIIT course PDF
$docSections['certificates_pdf']['items'][] = [
    'name'        => 'GIIT Course Completion Certificate',
    'desc'        => 'Official student course completion PDF (GIIT template, grade A++–C). Used by Admin and ATC Completion Certificates.',
    'live'        => 'atc/completion_certificate.php · admin/course_certificates.php',
    'preview_url' => $passRegId ? ('generate_course_certificate.php?reg_id=' . urlencode($passRegId) . '&preview=1') : null,
    'preview_note'=> $passNote ?: null,
    'code'        => 'admin/generate_course_certificate.php',
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

$docSections['certificates_html']['items'][] = [
    'name'        => 'ATC Authorization (HTML legacy)',
    'desc'        => 'Older HTML authorization layout — separate from GIIT PDF auth cert.',
    'live'        => 'admin/atc_centers.php',
    'preview_url' => $selAtcId ? ('atc_certificate.php?id=' . $selAtcId) : null,
    'code'        => 'admin/atc_certificate.php',
];
$docSections['certificates_html']['items'][] = [
    'name'        => 'Student Certificate (HTML legacy)',
    'desc'        => 'Old generic HTML layout — not used for official course completion (use GIIT PDF above).',
    'live'        => 'admin/print_certificates.php',
    'preview_url' => $selStudentId ? ('student_certificate.php?id=' . $selStudentId) : null,
    'code'        => 'admin/student_certificate.php',
];

$docSections['hall_tickets']['items'][] = [
    'name'        => 'Examination Hall Ticket',
    'desc'        => 'Generated per student on ATC Hall Tickets (requires share paid + photo).',
    'live'        => 'atc/hall_tickets.php',
    'preview_url' => ($selStudentId && $selAtcId) ? ('review_documents.php?embed=hall_ticket&student_id=' . $selStudentId . '&atc_id=' . $selAtcId) : null,
    'code'        => 'atc/hall_tickets.php',
];
$docSections['hall_tickets']['items'][] = [
    'name'        => 'Completion Hall Ticket',
    'desc'        => 'Separate hall ticket flow on ATC Completion Hall Ticket page.',
    'live'        => 'atc/completion_hall_ticket.php',
    'preview_url' => null,
    'preview_note'=> 'Open live ATC page to preview — same layout family as exam hall ticket.',
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
            <button type="submit">Apply sample data</button>
        </form>

        <?php foreach ($docSections as $section): ?>
        <div class="rv-section">
            <h3><?= htmlspecialchars($section['title']) ?> <span><?= count($section['items']) ?></span></h3>
            <div class="rv-grid">
                <?php foreach ($section['items'] as $item):
                    $preview = $item['preview_url'] ?? null;
                    $note    = $item['preview_note'] ?? null;
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
                        <a class="rv-btn primary" href="<?= htmlspecialchars($preview) ?>" target="_blank" rel="noopener">Open full preview</a>
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
                    <b>GIIT course PDF?</b> Official completion cert — Admin → Course Certificates or ATC → Completion Certificates.<br>
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
