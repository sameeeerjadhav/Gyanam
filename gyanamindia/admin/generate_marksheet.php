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
$signatory = $brand === 'abacus'
    ? 'Authorized Signatory For Gyanam Abacus'
    : 'Authorized Signatory For GIIT';

$headerBannerPath = __DIR__ . '/../assets/templates/giit_marksheet_header.png';
$abacusLogoPath   = __DIR__ . '/../assets/templates/abacus_marksheet_logo.png';
if (!is_file($abacusLogoPath)) {
    $abacusLogoPath = admissionFormBrandLogoPath('abacus');
}

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

$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.4);
$pdf->Rect($pad, $pad, $tw, $bottom - $top);

$cell = function (
    float $cx, float $cy, float $cw, float $ch, string $text, bool $fill,
    string $align = 'C', float $size = 10, string $style = '', bool $wrap = false
) use ($pdf) {
    if ($fill) {
        $pdf->SetFillColor(210, 210, 210);
    }
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.25);
    $pdf->Rect($cx, $cy, $cw, $ch, $fill ? 'DF' : 'D');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Times', $style, $size);
    $lineH = 4.2;
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    $innerW = max(1.0, $cw - 2.0);
    $needsWrap = $wrap || (strpos($text, "\n") !== false) || ($pdf->GetStringWidth($text) > $innerW);
    if ($needsWrap) {
        $lines = 0;
        foreach (explode("\n", $text) as $para) {
            $para = ($para === '') ? ' ' : $para;
            $w = $pdf->GetStringWidth($para);
            $lines += max(1, (int)ceil($w / $innerW));
        }
        $blockH = $lines * $lineH;
        $ty = $cy + max(0.6, ($ch - $blockH) / 2);
        $pdf->SetXY($cx + 1.0, $ty);
        $pdf->MultiCell($innerW, $lineH, $text, 0, 'C');
        return;
    }
    $pdf->SetXY($cx + 1.0, $cy + ($ch - $lineH) / 2);
    $pdf->Cell($innerW, $lineH, $text, 0, 0, 'C');
};

$FONT = 10.0;
$barH        = 11.0;
$legHdrH     = 12.0;
$legValH     = 14.0;
$legendTotal = $legHdrH + $legValH;

// Header image replaces org title + MSME text + GIIT logo
$headerGap = 2.0;
if ($brand === 'abacus') {
    $headerImgW = 72.0;
    $headerImgH = 24.0;
    $headerImgX = $x + ($tw - $headerImgW) / 2;
    $headerImgPath = $abacusLogoPath;
} else {
    $headerImgW = $tw * 0.78;
    $headerImgH = $headerImgW * (542.0 / 1280.0); // image.png aspect
    $headerImgX = $x + ($tw - $headerImgW) / 2;
    $headerImgPath = $headerBannerPath;
}
$headerBodyH = $headerImgH + $headerGap;

$gridStart = $top + $headerBodyH + $barH;
$gridEnd   = $bottom - $legendTotal;
$stretchH  = max(100.0, $gridEnd - $gridStart);

$wMetaHdr = 0.08;
$wMetaVal = 0.09;
$wRow     = 0.10;
$wContent = 0.18;
$wMarksHd = 0.09;
$wMarksBd = 0.26;

$metaHdrH   = $stretchH * $wMetaHdr;
$metaValH   = $stretchH * $wMetaVal;
$rowH       = $stretchH * $wRow;
$contentH   = $stretchH * $wContent;
$marksHdrH  = $stretchH * $wMarksHd;
$marksBodyH = $stretchH * $wMarksBd;
$planned = $metaHdrH + $metaValH + ($rowH * 3) + $contentH + $marksHdrH + $marksBodyH;
$marksBodyH += ($stretchH - $planned);

$colW   = $tw / 4;
$labelW = $colW;
$valW   = $tw - $labelW;
$pW     = $labelW;

// ── Header banner image (no separate org / MSME text) ───────────────────────
if (is_file($headerImgPath)) {
    try {
        $pdf->Image($headerImgPath, $headerImgX, $top + 0.5, $headerImgW, $headerImgH);
    } catch (Exception $e) {}
}

$barY = $top + $headerBodyH;
$pdf->SetFillColor(210, 210, 210);
$pdf->Rect($x, $barY, $tw, $barH, 'DF');
$pdf->SetFont('Times', 'B', $FONT);
$pdf->SetXY($x, $barY + ($barH - 4.2) / 2);
$pdf->Cell($tw, 4.2, 'Statement of Marks', 0, 0, 'C');

// ── Meta 4-column grid ──────────────────────────────────────────────────────
$infoY = $barY + $barH;
$headers = ['Month & Year of Exam', 'Course Duration', 'Center Code', 'Student ID'];
$values  = [$monthYear, $duration, $centerCode, $studentId];
for ($i = 0; $i < 4; $i++) {
    $cell($x + $i * $colW, $infoY, $colW, $metaHdrH, $headers[$i], true, 'C', $FONT, 'B', true);
    $cell($x + $i * $colW, $infoY + $metaHdrH, $colW, $metaValH, $values[$i], false, 'C', $FONT, 'B');
}

// ── Student / ATC / Course ──────────────────────────────────────────────────
$rowsY = $infoY + $metaHdrH + $metaValH;
$infoRows = [
    ['Name of Student', $fullName],
    ["Name of ATC\n(Authorized Training Center)", $atcName],
    ['Name of the Course', $courseName],
];
foreach ($infoRows as $i => $pair) {
    $ry = $rowsY + $i * $rowH;
    $cell($x, $ry, $labelW, $rowH, $pair[0], false, 'C', $FONT, 'B', true);
    $cell($x + $labelW, $ry, $valW, $rowH, $pair[1], false, 'C', $FONT, 'B');
}

$contentY = $rowsY + 3 * $rowH;
$cell($x, $contentY, $labelW, $contentH, "Course\nContents", false, 'C', $FONT, 'B', true);
$cell($x + $labelW, $contentY, $valW, $contentH, $contents, false, 'C', $FONT, 'B', true);

// ── Marks ───────────────────────────────────────────────────────────────────
$marksY = $contentY + $contentH;
$leftW = $tw * 0.68;
$rightW = $tw - $leftW;
$restW = $leftW - $pW;
$mW = $restW * 0.30;
$pctW = $restW * 0.35;
$gW = $restW - $mW - $pctW;
$rh = $marksBodyH / 2;

$cell($x, $marksY, $pW, $marksHdrH, 'Particulars', false, 'C', $FONT, 'B');
$cell($x + $pW, $marksY, $mW, $marksHdrH, 'Marks', false, 'C', $FONT, 'B');
$cell($x + $pW + $mW, $marksY, $pctW, $marksHdrH, 'Percentage', false, 'C', $FONT, 'B');
$cell($x + $pW + $mW + $pctW, $marksY, $gW, $marksHdrH, 'Grade', false, 'C', $FONT, 'B');

$cell($x, $marksY + $marksHdrH, $pW, $rh, 'Maximum Marks', false, 'C', $FONT, 'B');
$cell($x + $pW, $marksY + $marksHdrH, $mW, $rh, '100', false, 'C', $FONT, 'B');
$cell($x, $marksY + $marksHdrH + $rh, $pW, $rh, 'Marks Obtained', false, 'C', $FONT, 'B');
$cell($x + $pW, $marksY + $marksHdrH + $rh, $mW, $rh, (string)$score, false, 'C', $FONT, 'B');

$pdf->Rect($x + $pW + $mW, $marksY + $marksHdrH, $pctW, $marksBodyH, 'D');
$pdf->Rect($x + $pW + $mW + $pctW, $marksY + $marksHdrH, $gW, $marksBodyH, 'D');
$cell($x + $pW + $mW, $marksY + $marksHdrH, $pctW, $marksBodyH, (string)$score, false, 'C', $FONT, 'B');
$cell($x + $pW + $mW + $pctW, $marksY + $marksHdrH, $gW, $marksBodyH, $grade, false, 'C', $FONT, 'B');

$sx = $x + $leftW;
$sy = $marksY;
$sh = $marksHdrH + $marksBodyH;
$pdf->Rect($sx, $sy, $rightW, $sh, 'D');
$pdf->SetFont('Times', 'B', $FONT);
$pdf->SetXY($sx + 1, $sy + $sh - 14);
$pdf->Cell($rightW - 2, 5, $signatory, 0, 0, 'C');
$pdf->SetFont('Times', '', $FONT);
$pdf->SetXY($sx + 1, $sy + $sh - 8);
$pdf->Cell($rightW - 2, 4.5, 'Gyanam India Educational Services', 0, 0, 'C');

$legY = $bottom - $legendTotal;
$grades = ['A++', 'A+', 'A', 'B', 'C', 'Fail', 'AB'];
$bands  = ['90 & Above', '80 to 89', '66 to 79', '55 to 65', '40 to 54', 'Below 40', 'Absent'];
$gw = $tw / 7;
for ($i = 0; $i < 7; $i++) {
    $cell($x + $i * $gw, $legY, $gw, $legHdrH, $grades[$i], true, 'C', $FONT, 'B');
    $cell($x + $i * $gw, $legY + $legHdrH, $gw, $legValH, $bands[$i], false, 'C', $FONT, '');
}

$inline = isset($_GET['preview']);
$fname  = 'Marksheet_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $studentId) . '.pdf';
$pdf->Output($inline ? 'I' : 'D', $fname);
exit;
