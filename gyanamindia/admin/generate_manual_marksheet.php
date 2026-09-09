<?php
/**
 * Admin-only: Statement of Marks without exam portal.
 * POST/GET: admission_id + score [, preview=1]
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin(['Admin']);

require_once __DIR__ . '/../assets/fpdi/fpdi_autoload.php';
use setasign\Fpdi\Fpdi;

$pdo = getDBConnection();

$src = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
$preview = isset($src['preview']) || isset($_GET['preview']);
$score = (int)($src['score'] ?? 0);
$admissionId = (int)($src['admission_id'] ?? 0);

if ($admissionId <= 0) {
    http_response_code(400);
    die('<b>Error:</b> Select a student first.');
}

if ($score < 40 || $score > 100) {
    http_response_code(400);
    die('<b>Error:</b> Score must be between 40 and 100.');
}

$st = $pdo->prepare("
    SELECT a.*,
           atc.name AS atc_name, atc.city AS atc_city, atc.district AS atc_district,
           atc.atc_code, atc.id AS atc_id, atc.center_type,
           c.duration AS course_duration, c.course_type AS course_type,
           c.course_content
    FROM admissions a
    LEFT JOIN atc_centers atc ON atc.id = a.atc_id
    LEFT JOIN courses c ON c.course_name = a.course AND c.status = 'Active'
    WHERE a.id = ?
    LIMIT 1
");
$st->execute([$admissionId]);
$student = $st->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    http_response_code(404);
    die('<b>Error:</b> Student not found.');
}

$grade = courseExamGradeFromScore($score);
if ($grade === 'Fail') {
    http_response_code(400);
    die('<b>Error:</b> Marksheet cannot be issued — score is below passing grade.');
}

$examDate = date('Y-m-d');
$issueDateRaw = trim((string)($src['issue_date'] ?? ''));
if ($issueDateRaw !== '' && strtotime($issueDateRaw) !== false) {
    $examDate = date('Y-m-d', strtotime($issueDateRaw));
}

$fullName = trim(
    ($student['first_name'] ?? '') . ' ' .
    (!empty($student['middle_name']) ? $student['middle_name'] . ' ' : '') .
    ($student['last_name'] ?? '')
);
$courseName = trim((string)($student['course'] ?? 'N/A'));

if (empty($student['course_type']) && $courseName !== '' && $courseName !== 'N/A') {
    try {
        $ctSt = $pdo->prepare("SELECT course_type, course_content, duration FROM courses WHERE status = 'Active' AND (course_name = ? OR ? LIKE CONCAT(course_name, '%')) LIMIT 1");
        $ctSt->execute([$courseName, $courseName]);
        $crow = $ctSt->fetch(PDO::FETCH_ASSOC);
        if ($crow) {
            $student['course_type'] = $crow['course_type'] ?? $student['course_type'];
            if (empty($student['course_content'])) {
                $student['course_content'] = $crow['course_content'] ?? '';
            }
            if (empty($student['course_duration'])) {
                $student['course_duration'] = $crow['duration'] ?? '';
            }
        }
    } catch (Exception $e) {}
}

$atcCity = trim((string)($student['atc_city'] ?? $student['atc_district'] ?? ''));
$atcName = trim((string)($student['atc_name'] ?? 'N/A')) . ($atcCity !== '' ? ', ' . $atcCity : '');
$duration = trim((string)($student['course_duration'] ?? '')) ?: '—';
$contents = trim((string)($student['course_content'] ?? ''));
if ($contents === '') {
    $contents = '—';
}
$contents = preg_replace('/\s+/', ' ', $contents);
if (function_exists('mb_strlen') && mb_strlen($contents) > 420) {
    $contents = mb_substr($contents, 0, 417) . '…';
} elseif (strlen($contents) > 420) {
    $contents = substr($contents, 0, 417) . '…';
}

$monthYear = date('F-Y', strtotime($examDate));
$centerCode = trim((string)($student['atc_code'] ?? '')) ?: '—';
$studentId = trim((string)($student['registration_id'] ?? ''));
if ($studentId === '') {
    $studentId = trim((string)($student['roll_no'] ?? ('ADM-' . $admissionId)));
}

$brand = courseCertificateBrand(
    $student['course_type'] ?? null,
    $student['center_type'] ?? null,
    $courseName
);
$signatory = $brand === 'abacus'
    ? 'Authorized Signatory For Gyanam Abacus'
    : 'Authorized Signatory For GIIT';

$headerBannerPath = __DIR__ . '/../assets/templates/giit_marksheet_header_bw.png';
if (!is_file($headerBannerPath)) {
    $headerBannerPath = __DIR__ . '/../assets/templates/giit_marksheet_header.png';
}
$abacusLogoPath = __DIR__ . '/../assets/templates/abacus_marksheet_logo_bw.png';
if (!is_file($abacusLogoPath)) {
    $abacusLogoPath = __DIR__ . '/../assets/templates/abacus_marksheet_logo.png';
}
if (!is_file($abacusLogoPath) && function_exists('admissionFormBrandLogoPath')) {
    $abacusLogoPath = admissionFormBrandLogoPath('abacus');
}
$signPljPath = __DIR__ . '/../assets/templates/marksheet_sign_plj.png';
$signRpsPath = __DIR__ . '/../assets/templates/marksheet_sign_rps.png';

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
$barH = 11.0;
$legHdrH = 12.0;
$legValH = 14.0;
$legendTotal = $legHdrH + $legValH;

$headerGap = 2.0;
if ($brand === 'abacus') {
    $headerImgW = 72.0;
    $headerImgH = 24.0;
    $headerImgX = $x + ($tw - $headerImgW) / 2;
    $headerImgPath = $abacusLogoPath;
} else {
    $headerImgW = $tw * 0.65;
    $headerImgH = $headerImgW * (520.0 / 1280.0);
    $headerImgX = $x + ($tw - $headerImgW) / 2;
    $headerImgPath = $headerBannerPath;
}
$headerBodyH = $headerImgH + $headerGap;

$gridStart = $top + $headerBodyH + $barH;
$gridEnd = $bottom - $legendTotal;
$stretchH = max(100.0, $gridEnd - $gridStart);

$wMetaHdr = 0.08;
$wMetaVal = 0.09;
$wRow = 0.10;
$wContent = 0.18;
$wMarksHd = 0.09;
$wMarksBd = 0.26;

$metaHdrH = $stretchH * $wMetaHdr;
$metaValH = $stretchH * $wMetaVal;
$rowH = $stretchH * $wRow;
$contentH = $stretchH * $wContent;
$marksHdrH = $stretchH * $wMarksHd;
$marksBodyH = $stretchH * $wMarksBd;
$planned = $metaHdrH + $metaValH + ($rowH * 3) + $contentH + $marksHdrH + $marksBodyH;
$marksBodyH += ($stretchH - $planned);

$colW = $tw / 4;
$labelW = $colW;
$valW = $tw - $labelW;
$pW = $labelW;

if (is_file($headerImgPath)) {
    try {
        $pdf->Image($headerImgPath, $headerImgX, $top + 0.5, $headerImgW, $headerImgH);
    } catch (Exception $e) {}
}

$barY = $top + $headerBodyH;
$pdf->SetFillColor(255, 255, 255);
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.25);
$pdf->Rect($x, $barY, $tw, $barH, 'DF');
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Times', 'B', $FONT);
$pdf->SetXY($x, $barY + ($barH - 4.2) / 2);
$pdf->Cell($tw, 4.2, 'Statement of Marks', 0, 0, 'C');

$infoY = $barY + $barH;
$headers = ['Month & Year of Exam', 'Course Duration', 'Center Code', 'Student ID'];
$values = [$monthYear, $duration, $centerCode, $studentId];
for ($i = 0; $i < 4; $i++) {
    $cell($x + $i * $colW, $infoY, $colW, $metaHdrH, $headers[$i], true, 'C', $FONT, 'B', true);
    $cell($x + $i * $colW, $infoY + $metaHdrH, $colW, $metaValH, $values[$i], false, 'C', $FONT, 'B');
}

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

// Signatures (reduced) above authorized-signatory text; seal reserved below later
$sigH = 10.5;
$sigW1 = $sigH * (223.0 / 118.0);
$sigW2 = $sigH * (280.0 / 118.0);
$sigGap = 2.0;
$sigTotalW = $sigW1 + $sigGap + $sigW2;
$maxSigW = max(20.0, $rightW - 4.0);
if ($sigTotalW > $maxSigW) {
    $scale = $maxSigW / $sigTotalW;
    $sigH *= $scale;
    $sigW1 *= $scale;
    $sigW2 *= $scale;
    $sigTotalW = $sigW1 + $sigGap + $sigW2;
}
$sigY = $sy + 3.5;
$sigX = $sx + ($rightW - $sigTotalW) / 2;
if (is_file($signPljPath)) {
    try {
        $pdf->Image($signPljPath, $sigX, $sigY, $sigW1, $sigH);
    } catch (Exception $e) {}
}
if (is_file($signRpsPath)) {
    try {
        $pdf->Image($signRpsPath, $sigX + $sigW1 + $sigGap, $sigY, $sigW2, $sigH);
    } catch (Exception $e) {}
}
$textY = $sigY + $sigH + 2.0;
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Times', 'B', $FONT);
$pdf->SetXY($sx + 1, $textY);
$pdf->Cell($rightW - 2, 4.5, $signatory, 0, 0, 'C');
$pdf->SetFont('Times', '', $FONT);
$pdf->SetXY($sx + 1, $textY + 4.8);
$pdf->Cell($rightW - 2, 4.5, 'Gyanam India Educational Services', 0, 0, 'C');
// Seal will be placed below this text in a follow-up.

$legY = $bottom - $legendTotal;
$grades = ['A++', 'A+', 'A', 'B', 'C', 'Fail', 'AB'];
$bands = ['90 & Above', '80 to 89', '66 to 79', '55 to 65', '40 to 54', 'Below 40', 'Absent'];
$gw = $tw / 7;
for ($i = 0; $i < 7; $i++) {
    $cell($x + $i * $gw, $legY, $gw, $legHdrH, $grades[$i], true, 'C', $FONT, 'B');
    $cell($x + $i * $gw, $legY + $legHdrH, $gw, $legValH, $bands[$i], false, 'C', $FONT, '');
}

if (ob_get_level()) {
    ob_end_clean();
}
$fname = 'Marksheet_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $studentId) . '.pdf';
$pdf->Output($preview ? 'I' : 'D', $fname);
exit;
