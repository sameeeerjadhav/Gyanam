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
if (mb_strlen($contents) > 420) $contents = mb_substr($contents, 0, 417) . '…';

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

$pdf = new Fpdi();
$pdf->SetTitle('Statement of Marks — ' . $studentId);
$pdf->SetAuthor('Gyanam India Educational Services');
$pdf->SetAutoPageBreak(false);
$pdf->AddPage('P', 'A4');

$W = 210.0;
$H = 297.0;
$pad = 8.0;
$x = $pad;
$tw = $W - 2 * $pad;
$top = $pad;
$bottom = $H - $pad;

// Outer border flush to content area (MCCE-style single frame)
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.4);
$pdf->Rect($pad, $pad, $tw, $bottom - $top);

$cell = function (
    float $cx, float $cy, float $cw, float $ch, string $text, bool $fill,
    string $align = 'C', float $size = 9, string $style = '', bool $wrap = false
) use ($pdf) {
    if ($fill) {
        $pdf->SetFillColor(210, 210, 210);
    }
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.25);
    $pdf->Rect($cx, $cy, $cw, $ch, $fill ? 'DF' : 'D');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Helvetica', $style, $size);
    $lineH = max(3.5, $size * 0.42);
    if ($wrap) {
        $pdf->SetXY($cx + 1.2, $cy + 1.6);
        $pdf->MultiCell($cw - 2.4, $lineH, $text, 0, $align);
        return;
    }
    $pdf->SetXY($cx + 1.0, $cy + ($ch - $lineH) / 2);
    $pdf->Cell($cw - 2.0, $lineH, $text, 0, 0, $align);
};

// ── Fixed heights that must always fit ──────────────────────────────────────
$headerTopH   = 8.0;   // GYANAM INDIA… line
$headerBodyH  = 34.0;  // logo + MSME / org / ISO
$barH         = 11.0;
$legHdrH      = 12.0;
$legValH      = 14.0;
$legendTotal  = $legHdrH + $legValH;

// Everything between title bar and grade legend stretches to fill the page
$gridStart = $top + $headerTopH + $headerBodyH + $barH;
$gridEnd   = $bottom - $legendTotal;
$stretchH  = max(120.0, $gridEnd - $gridStart);

// Proportional slots (weights sum to 1.0)
$wMetaHdr = 0.08;
$wMetaVal = 0.09;
$wRow     = 0.10; // ×3 student / ATC / course
$wContent = 0.18;
$wMarksHd = 0.09;
$wMarksBd = 0.26; // ×2 max + obtained (shared via body)

$metaHdrH   = $stretchH * $wMetaHdr;
$metaValH   = $stretchH * $wMetaVal;
$rowH       = $stretchH * $wRow;
$contentH   = $stretchH * $wContent;
$marksHdrH  = $stretchH * $wMarksHd;
$marksBodyH = $stretchH * $wMarksBd;

// Absorb rounding so bottom of marks lands exactly on legend top
$planned = $metaHdrH + $metaValH + ($rowH * 3) + $contentH + $marksHdrH + $marksBodyH;
$marksBodyH += ($stretchH - $planned);

// ── Header ──────────────────────────────────────────────────────────────────
$pdf->SetFont('Helvetica', 'B', 12);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetXY($x, $top + 1.5);
$pdf->Cell($tw, 5.5, $headerOrg, 0, 0, 'C');

$logoY = $top + $headerTopH;
$logoW = 30.0;
$logoH = 28.0;
if (is_file($logoPath)) {
    try {
        $pdf->Image($logoPath, $x + 2, $logoY + 2, $logoW, $logoH);
    } catch (Exception $e) {}
}

$textX = $x + $logoW + 8;
$textW = $tw - $logoW - 10;
$pdf->SetXY($textX, $logoY + 4);
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell($textW, 5, 'Udyam (MSME) No: MH-14-0160225', 0, 2, 'L');
$pdf->SetFont('Helvetica', 'B', 13);
$pdf->MultiCell($textW, 6, $displayOrg, 0, 'L');
$pdf->SetX($textX);
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell($textW, 5, 'An ISO 9001:2015 Certified Organisation', 0, 2, 'L');

// Title bar
$barY = $top + $headerTopH + $headerBodyH;
$pdf->SetFillColor(210, 210, 210);
$pdf->Rect($x, $barY, $tw, $barH, 'DF');
$pdf->SetFont('Helvetica', 'B', 14);
$pdf->SetXY($x, $barY + ($barH - 6) / 2);
$pdf->Cell($tw, 6, 'Statement of Marks', 0, 0, 'C');

// ── Meta 4-column grid ──────────────────────────────────────────────────────
$infoY = $barY + $barH;
$colW = $tw / 4;
$headers = ['Month & Year of Exam', 'Course Duration', 'Center Code', 'Student ID'];
$values  = [$monthYear, $duration, $centerCode, $studentId];
for ($i = 0; $i < 4; $i++) {
    $cell($x + $i * $colW, $infoY, $colW, $metaHdrH, $headers[$i], true, 'C', 8, 'B', true);
    $cell($x + $i * $colW, $infoY + $metaHdrH, $colW, $metaValH, $values[$i], false, 'C', 11, 'B');
}

// ── Student / ATC / Course rows ─────────────────────────────────────────────
$rowsY = $infoY + $metaHdrH + $metaValH;
$labelW = 58.0;
$valW = $tw - $labelW;
$infoRows = [
    ['Name of Student', $fullName],
    ["Name of ATC\n(Authorized Training Center)", $atcName],
    ['Name of the Course', $courseName],
];
foreach ($infoRows as $i => $pair) {
    $ry = $rowsY + $i * $rowH;
    $cell($x, $ry, $labelW, $rowH, $pair[0], true, 'L', 8, 'B', true);
    $cell($x + $labelW, $ry, $valW, $rowH, $pair[1], false, 'L', 11, 'B');
}

// ── Course contents ─────────────────────────────────────────────────────────
$contentY = $rowsY + 3 * $rowH;
$pdf->SetFillColor(210, 210, 210);
$pdf->Rect($x, $contentY, $labelW, $contentH, 'DF');
$pdf->Rect($x + $labelW, $contentY, $valW, $contentH, 'D');
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->SetXY($x + 1.5, $contentY + 3);
$pdf->MultiCell($labelW - 3, 4, "Course\nContents", 0, 'L');
$pdf->SetFont('Helvetica', '', 10);
$pdf->SetXY($x + $labelW + 2, $contentY + 3);
$pdf->MultiCell($valW - 4, 4.5, $contents, 0, 'L');

// ── Marks + signatory ───────────────────────────────────────────────────────
$marksY = $contentY + $contentH;
$leftW = $tw * 0.68;
$rightW = $tw - $leftW;
$pW = $leftW * 0.38;
$mW = $leftW * 0.18;
$pctW = $leftW * 0.22;
$gW = $leftW - $pW - $mW - $pctW;
$rh = $marksBodyH / 2;

$cell($x, $marksY, $pW, $marksHdrH, 'Particulars', true, 'C', 10, 'B');
$cell($x + $pW, $marksY, $mW, $marksHdrH, 'Marks', true, 'C', 10, 'B');
$cell($x + $pW + $mW, $marksY, $pctW, $marksHdrH, 'Percentage', true, 'C', 10, 'B');
$cell($x + $pW + $mW + $pctW, $marksY, $gW, $marksHdrH, 'Grade', true, 'C', 10, 'B');

$cell($x, $marksY + $marksHdrH, $pW, $rh, 'Maximum Marks', true, 'L', 10, 'B');
$cell($x + $pW, $marksY + $marksHdrH, $mW, $rh, '100', false, 'C', 14, 'B');
$cell($x, $marksY + $marksHdrH + $rh, $pW, $rh, 'Marks Obtained', true, 'L', 10, 'B');
$cell($x + $pW, $marksY + $marksHdrH + $rh, $mW, $rh, (string)$score, false, 'C', 14, 'B');

$pdf->Rect($x + $pW + $mW, $marksY + $marksHdrH, $pctW, $marksBodyH, 'D');
$pdf->Rect($x + $pW + $mW + $pctW, $marksY + $marksHdrH, $gW, $marksBodyH, 'D');
$pdf->SetFont('Helvetica', 'B', 20);
$pdf->SetXY($x + $pW + $mW, $marksY + $marksHdrH + $marksBodyH / 2 - 5);
$pdf->Cell($pctW, 10, (string)$score, 0, 0, 'C');
$pdf->SetXY($x + $pW + $mW + $pctW, $marksY + $marksHdrH + $marksBodyH / 2 - 5);
$pdf->Cell($gW, 10, $grade, 0, 0, 'C');

$sx = $x + $leftW;
$sy = $marksY;
$sh = $marksHdrH + $marksBodyH;
$pdf->Rect($sx, $sy, $rightW, $sh, 'D');

$pdf->SetFont('Helvetica', 'B', 8);
$pdf->SetXY($sx + 1, $sy + $sh - 14);
$pdf->Cell($rightW - 2, 5, $signatory, 0, 0, 'C');
$pdf->SetFont('Helvetica', '', 7);
$pdf->SetXY($sx + 1, $sy + $sh - 8);
$pdf->Cell($rightW - 2, 4.5, 'Gyanam India Educational Services', 0, 0, 'C');

// ── Grade legend flush to bottom border ─────────────────────────────────────
$legY = $bottom - $legendTotal;
$grades = ['A++', 'A+', 'A', 'B', 'C', 'Fail', 'AB'];
$bands  = ['90 & Above', '80 to 89', '66 to 79', '55 to 65', '40 to 54', 'Below 40', 'Absent'];
$gw = $tw / 7;
for ($i = 0; $i < 7; $i++) {
    $cell($x + $i * $gw, $legY, $gw, $legHdrH, $grades[$i], true, 'C', 10, 'B');
    $cell($x + $i * $gw, $legY + $legHdrH, $gw, $legValH, $bands[$i], false, 'C', 8, '');
}

$inline = isset($_GET['preview']);
$fname  = 'Marksheet_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $studentId) . '.pdf';
$pdf->Output($inline ? 'I' : 'D', $fname);
exit;
