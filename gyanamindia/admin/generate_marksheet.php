<?php
/**
 * Gyanam India — Statement of Marks (MCCE layout, Gyanam branding)
 *
 * URL: generate_marksheet.php?reg_id=STUDENT_REG&preview=1
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
if (file_exists(__DIR__ . '/../includes/exam_integration.php')) {
    require_once __DIR__ . '/../includes/exam_integration.php';
}
requireLogin(['Admin', 'DLC', 'ATC CENTER']);

require_once __DIR__ . '/../assets/fpdi/fpdi_autoload.php';
use setasign\Fpdi\Fpdi;

$pdo = getDBConnection();
$sessionRole  = (string)(getUserRole() ?? '');
$sessionAtcId = intval($_SESSION['atc_id'] ?? 0);
$isSample     = isset($_GET['sample']) && (string)$_GET['sample'] === '1';
if ($isSample && $sessionRole !== 'Admin') {
    http_response_code(403);
    die('Sample marksheet is only available to Admin.');
}

$loadStudentByReg = static function (PDO $pdo, string $regId): ?array {
    $sql = "
        SELECT a.*,
               atc.name AS atc_name, atc.city AS atc_city, atc.district AS atc_district,
               atc.atc_code, atc.id AS atc_id, atc.center_type,
               c.duration AS course_duration, c.course_type AS course_type,
               c.course_content
        FROM admissions a
        LEFT JOIN atc_centers atc ON atc.id = a.atc_id
        LEFT JOIN courses c ON c.course_name = a.course AND c.status = 'Active'
        WHERE a.registration_id = ?
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$regId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
    $sql2 = str_replace('a.registration_id = ?', 'a.roll_no = ?', $sql);
    $stmt = $pdo->prepare($sql2);
    $stmt->execute([$regId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
};

$regId   = trim($_GET['reg_id'] ?? '');
$student = null;
if ($regId !== '') {
    $student = $loadStudentByReg($pdo, $regId);
}

if ($isSample && !$student) {
    $sampleAtcId = (int)($_GET['atc_id'] ?? 0);
    $atcRow = null;
    if ($sampleAtcId > 0) {
        $as = $pdo->prepare("SELECT id, name, city, district, atc_code, center_type FROM atc_centers WHERE id = ? LIMIT 1");
        $as->execute([$sampleAtcId]);
        $atcRow = $as->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $sampleBrand = strtolower(trim((string)($_GET['brand'] ?? 'it')));
    $isAbacus    = $sampleBrand === 'abacus';
    $student = [
        'first_name'        => 'SAMPLE',
        'middle_name'       => '',
        'last_name'         => 'STUDENT',
        'course'            => $isAbacus ? 'Abacus Level 1' : 'MS-CIT',
        'course_type'       => $isAbacus ? 'Abacus' : 'IT',
        'course_content'    => $isAbacus
            ? 'Abacus basics, visualization, speed and accuracy drills'
            : 'MS Office, Internet, Digital Literacy',
        'course_duration'   => $isAbacus ? '3 Months' : '2 Months',
        'atc_name'          => $atcRow['name'] ?? 'Sample ATC',
        'atc_city'          => $atcRow['city'] ?? 'Pune',
        'atc_district'      => $atcRow['district'] ?? '',
        'atc_code'          => $atcRow['atc_code'] ?? '202600001',
        'atc_id'            => (int)($atcRow['id'] ?? 0),
        'center_type'       => $atcRow['center_type'] ?? ($isAbacus ? 'Abacus' : 'IT'),
        'registration_id'   => 'SAMPLE-REG-001',
        'roll_no'           => 'SAMPLE-001',
    ];
}

if (!$student) {
    http_response_code($regId === '' ? 400 : 404);
    die($regId === '' ? '<b>Error:</b> Missing <code>reg_id</code> parameter.' : '<b>Error:</b> Student not found.');
}

if ($sessionRole === 'ATC CENTER' && intval($student['atc_id']) !== $sessionAtcId) {
    http_response_code(403);
    die('Access denied.');
}

$lookupId = trim((string)($student['registration_id'] ?? '')) ?: trim((string)($student['roll_no'] ?? $regId));
$exam = null;
if ($isSample) {
    $exam = [
        'identifier' => $lookupId ?: 'SAMPLE-REG-001',
        'score'      => 82,
        'exam_date'  => date('Y-m-d'),
        'result'     => 'pass',
    ];
} else {
    if (!function_exists('examIntegrationReady') || !examIntegrationReady()) {
        http_response_code(403);
        die('<b>Marksheet not available:</b> Exam portal is not connected.');
    }
    $exam = function_exists('fetchStudentPassingExamResult') ? fetchStudentPassingExamResult($lookupId) : null;
    if (!$exam) {
        $res = fetchStudentExamResults($lookupId);
        $subs = $res['success'] ? ($res['data']['submissions'] ?? []) : [];
        $best = null;
        foreach ($subs as $sub) {
            if (!is_array($sub) || examSubmissionIsDemo($sub)) {
                continue;
            }
            $id = trim((string)($sub['student']['identifier'] ?? ''));
            if ($id === '') {
                continue;
            }
            $rec = [
                'identifier'   => $id,
                'score'        => (int)($sub['score'] ?? 0),
                'exam_date'    => date('Y-m-d', strtotime((string)($sub['submitted_at'] ?? 'now'))),
                'exam_title'   => (string)($sub['exam_title'] ?? ($sub['exam']['title'] ?? '')),
                'submitted_at' => $sub['submitted_at'] ?? null,
                'result'       => strtolower((string)($sub['result'] ?? '')),
            ];
            if ($best === null || strtotime((string)$rec['submitted_at']) > strtotime((string)($best['submitted_at'] ?? '0'))) {
                $best = $rec;
            }
        }
        $exam = $best;
    }
    if (!$exam) {
        http_response_code(403);
        die('<b>Marksheet not available:</b> No exam result found for this student.');
    }
}

$score = (int)($exam['score'] ?? 0);
$examDate = (string)($exam['exam_date'] ?? date('Y-m-d'));
$resultFlag = strtolower((string)($exam['result'] ?? ''));
if ($resultFlag === 'absent' || $resultFlag === 'ab') {
    $grade = 'AB';
} else {
    $grade = courseExamGradeFromScore($score);
}

$fullName = trim(
    $student['first_name'] . ' ' .
    ($student['middle_name'] ? $student['middle_name'] . ' ' : '') .
    $student['last_name']
);
$courseName = trim($student['course'] ?? 'N/A');
if (empty($student['course_type']) && $courseName !== '' && $courseName !== 'N/A') {
    try {
        $ctSt = $pdo->prepare("SELECT course_type, course_content, duration FROM courses WHERE status = 'Active' AND (course_name = ? OR ? LIKE CONCAT(course_name, '%')) LIMIT 1");
        $ctSt->execute([$courseName, $courseName]);
        $crow = $ctSt->fetch(PDO::FETCH_ASSOC);
        if ($crow) {
            $student['course_type'] = $crow['course_type'] ?? $student['course_type'];
            if (empty($student['course_content'])) $student['course_content'] = $crow['course_content'] ?? '';
            if (empty($student['course_duration'])) $student['course_duration'] = $crow['duration'] ?? '';
        }
    } catch (Exception $e) {}
}

$atcCity = trim($student['atc_city'] ?? $student['atc_district'] ?? '');
$atcName = trim($student['atc_name'] ?? 'N/A') . ($atcCity ? ', ' . $atcCity : '');
$duration = trim((string)($student['course_duration'] ?? '')) ?: '—';
$contents = trim((string)($student['course_content'] ?? ''));
if ($contents === '') $contents = '—';
$contents = preg_replace('/\s+/', ' ', $contents);
if (mb_strlen($contents) > 220) $contents = mb_substr($contents, 0, 217) . '…';

$monthYear = date('F-Y', strtotime($examDate ?: 'now'));
$centerCode = trim((string)($student['atc_code'] ?? '')) ?: '—';
$studentId = trim((string)($student['registration_id'] ?? $lookupId));

$brand = courseCertificateBrand(
    $student['course_type'] ?? null,
    $student['center_type'] ?? null,
    $courseName
);
if ($isSample) {
    $forceBrand = strtolower(trim((string)($_GET['brand'] ?? '')));
    if ($forceBrand === 'abacus' || $forceBrand === 'it') {
        $brand = $forceBrand;
    }
}
$logoPath = admissionFormBrandLogoPath($brand === 'abacus' ? 'abacus' : 'it');
$headerOrg = 'GYANAM INDIA EDUCATIONAL SERVICES';
$displayOrg = $brand === 'abacus'
    ? 'Gyanam Abacus — A Unit of Gyanam India Educational Services'
    : 'Gyanam Institute of Information Technology';
$signatory = $brand === 'abacus'
    ? 'Authorized Signatory For Gyanam Abacus'
    : 'Authorized Signatory For GIIT';

$stampPath = __DIR__ . '/../assets/templates/gyanam_marksheet_stamp.png';

$pdf = new Fpdi();
$pdf->SetTitle('Statement of Marks — ' . $studentId);
$pdf->SetAuthor('Gyanam India Educational Services');
$pdf->SetAutoPageBreak(false);
$pdf->AddPage('P', 'A4');

$W = 210.0;
$H = 297.0;
$x = 14.0;
$y = 12.0;
$tw = $W - 28.0;

$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.35);
$pdf->Rect($x - 2, $y - 2, $tw + 4, 273);

// Top org line
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetXY($x, $y);
$pdf->Cell($tw, 6, $headerOrg, 0, 0, 'C');

// Logo + org block
$logoY = $y + 8;
$logoW = 32;
if (is_file($logoPath)) {
    try {
        $pdf->Image($logoPath, $x, $logoY, $logoW);
    } catch (Exception $e) {}
}

$pdf->SetXY($x + $logoW + 6, $logoY + 2);
$pdf->SetFont('Helvetica', '', 8);
$pdf->Cell($tw - $logoW - 6, 4, 'Udyam (MSME) No: MH-14-0160225', 0, 2, 'L');
$pdf->SetFont('Helvetica', 'B', 13);
$pdf->Cell($tw - $logoW - 6, 6, $displayOrg, 0, 2, 'L');
$pdf->SetFont('Helvetica', '', 8);
$pdf->Cell($tw - $logoW - 6, 4, 'An ISO 9001:2015 Certified Organisation', 0, 2, 'L');

// Statement of Marks bar
$barY = $logoY + 28;
$pdf->SetFillColor(220, 220, 220);
$pdf->SetLineWidth(0.3);
$pdf->Rect($x, $barY, $tw, 9, 'DF');
$pdf->SetFont('Helvetica', 'B', 12);
$pdf->SetXY($x, $barY + 1.8);
$pdf->Cell($tw, 6, 'Statement of Marks', 0, 0, 'C');

$cell = function (float $cx, float $cy, float $cw, float $ch, string $text, bool $fill, string $align = 'C', int $size = 8, string $style = '') use ($pdf) {
    if ($fill) $pdf->SetFillColor(220, 220, 220);
    $pdf->Rect($cx, $cy, $cw, $ch, $fill ? 'DF' : 'D');
    $pdf->SetFont('Helvetica', $style, $size);
    $pdf->SetXY($cx + 0.8, $cy + ($ch - 4.2) / 2);
    $pdf->Cell($cw - 1.6, 4.2, $text, 0, 0, $align);
};

$infoY = $barY + 9;
$colW = $tw / 4;
$hdrH = 8;
$valH = 8;
$headers = ['Month & Year of Exam', 'Course Duration', 'Center Code', 'Student ID'];
$values  = [$monthYear, $duration, $centerCode, $studentId];
for ($i = 0; $i < 4; $i++) {
    $cell($x + $i * $colW, $infoY, $colW, $hdrH, $headers[$i], true, 'C', 7.5, 'B');
    $cell($x + $i * $colW, $infoY + $hdrH, $colW, $valH, $values[$i], false, 'C', 8.5, 'B');
}

$rowsY = $infoY + $hdrH + $valH;
$labelW = 58;
$valW = $tw - $labelW;
$infoRows = [
    ['Name of Student', $fullName],
    ['Name of ATC (Authorized Training Center)', $atcName],
    ['Name of the Course', $courseName],
];
$rowH = 9;
foreach ($infoRows as $i => $pair) {
    $ry = $rowsY + $i * $rowH;
    $cell($x, $ry, $labelW, $rowH, $pair[0], true, 'L', 7.5, 'B');
    $cell($x + $labelW, $ry, $valW, $rowH, $pair[1], false, 'L', 8.5, 'B');
}

$contentY = $rowsY + count($infoRows) * $rowH;
$contentH = 16;
$pdf->SetFillColor(220, 220, 220);
$pdf->Rect($x, $contentY, $labelW, $contentH, 'DF');
$pdf->Rect($x + $labelW, $contentY, $valW, $contentH, 'D');
$pdf->SetFont('Helvetica', 'B', 7.5);
$pdf->SetXY($x + 0.8, $contentY + 2);
$pdf->MultiCell($labelW - 1.6, 3.6, 'Course Contents', 0, 'L');
$pdf->SetFont('Helvetica', 'B', 8);
$pdf->SetXY($x + $labelW + 1, $contentY + 2);
$pdf->MultiCell($valW - 2, 3.8, $contents, 0, 'L');

// Marks table + signatory
$marksY = $contentY + $contentH;
$leftW = 122;
$rightW = $tw - $leftW;
$mh = 8;
$rh = 11;
$pW = 46;
$mW = 24;
$pctW = 26;
$gW = $leftW - $pW - $mW - $pctW;

$cell($x, $marksY, $pW, $mh, 'Particulars', true, 'C', 8, 'B');
$cell($x + $pW, $marksY, $mW, $mh, 'Marks', true, 'C', 8, 'B');
$cell($x + $pW + $mW, $marksY, $pctW, $mh, 'Percentage', true, 'C', 8, 'B');
$cell($x + $pW + $mW + $pctW, $marksY, $gW, $mh, 'Grade', true, 'C', 8, 'B');

$bodyH = $rh * 2;
$cell($x, $marksY + $mh, $pW, $rh, 'Maximum Marks', true, 'L', 8, 'B');
$cell($x + $pW, $marksY + $mh, $mW, $rh, '100', false, 'C', 10, 'B');
$cell($x, $marksY + $mh + $rh, $pW, $rh, 'Marks Obtained', true, 'L', 8, 'B');
$cell($x + $pW, $marksY + $mh + $rh, $mW, $rh, (string)$score, false, 'C', 10, 'B');

// Percentage + Grade rowspan
$pdf->Rect($x + $pW + $mW, $marksY + $mh, $pctW, $bodyH, 'D');
$pdf->Rect($x + $pW + $mW + $pctW, $marksY + $mh, $gW, $bodyH, 'D');
$pdf->SetFont('Helvetica', 'B', 14);
$pdf->SetXY($x + $pW + $mW, $marksY + $mh + $bodyH / 2 - 3.5);
$pdf->Cell($pctW, 7, (string)$score, 0, 0, 'C');
$pdf->SetXY($x + $pW + $mW + $pctW, $marksY + $mh + $bodyH / 2 - 3.5);
$pdf->Cell($gW, 7, $grade, 0, 0, 'C');

// Signatory panel
$sx = $x + $leftW;
$sy = $marksY;
$sh = $mh + $bodyH;
$pdf->Rect($sx, $sy, $rightW, $sh, 'D');
if (is_file($stampPath)) {
    try {
        $pdf->Image($stampPath, $sx + ($rightW - 28) / 2, $sy + 2, 28, 28);
    } catch (Exception $e) {}
}
$pdf->SetFont('Helvetica', 'B', 7.5);
$pdf->SetXY($sx + 1, $sy + $sh - 10);
$pdf->Cell($rightW - 2, 4, $signatory, 0, 0, 'C');
$pdf->SetFont('Helvetica', '', 6.5);
$pdf->SetXY($sx + 1, $sy + $sh - 6);
$pdf->Cell($rightW - 2, 3.5, 'Gyanam India Educational Services', 0, 0, 'C');

// Grade legend
$legY = $marksY + $sh;
$grades = ['A++', 'A+', 'A', 'B', 'C', 'Fail', 'AB'];
$bands  = ['90 & Above', '80 to 89', '66 to 79', '55 to 65', '40 to 54', 'Below 40', 'Absent'];
$gw = $tw / 7;
$lh = 8;
for ($i = 0; $i < 7; $i++) {
    $cell($x + $i * $gw, $legY, $gw, $lh, $grades[$i], true, 'C', 8, 'B');
    $cell($x + $i * $gw, $legY + $lh, $gw, $lh, $bands[$i], false, 'C', 7, '');
}

$inline = isset($_GET['preview']);
$fname  = 'Marksheet_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $studentId) . '.pdf';
$pdf->Output($inline ? 'I' : 'D', $fname);
exit;
