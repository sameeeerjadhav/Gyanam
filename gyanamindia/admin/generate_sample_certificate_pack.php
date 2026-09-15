<?php
/**
 * Admin-only: Sample pack — course completion certificate + Statement of Marks
 * as a single 2-page PDF (page 1 = certificate, page 2 = marksheet).
 *
 * Params: course_id, admission_id, score [, issue_date] [, preview=1]
 * Uses the selected course details with the selected student's name/photo/ATC.
 * Does not allocate real certificate numbers or write issued_certificates.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/statement_of_marks_pdf.php';
requireLogin(['Admin']);

require_once __DIR__ . '/../assets/fpdi/fpdi_autoload.php';
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

$pdo = getDBConnection();
$src = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
$preview = isset($src['preview']) || isset($_GET['preview']);
$score = (int)($src['score'] ?? 82);
$admissionId = (int)($src['admission_id'] ?? 0);
$courseId = (int)($src['course_id'] ?? 0);

if ($courseId <= 0) {
    http_response_code(400);
    die('<b>Error:</b> Select a course first.');
}
if ($admissionId <= 0) {
    http_response_code(400);
    die('<b>Error:</b> Select a student first.');
}
if ($score < 40 || $score > 100) {
    http_response_code(400);
    die('<b>Error:</b> Score must be between 40 and 100.');
}

$grade = courseExamGradeFromScore($score);
if ($grade === 'Fail') {
    http_response_code(400);
    die('<b>Error:</b> Sample requires a passing score (40+).');
}

$cst = $pdo->prepare("
    SELECT id, course_name, course_type, duration, course_content, status
    FROM courses
    WHERE id = ?
    LIMIT 1
");
$cst->execute([$courseId]);
$course = $cst->fetch(PDO::FETCH_ASSOC);
if (!$course) {
    http_response_code(404);
    die('<b>Error:</b> Course not found.');
}

$st = $pdo->prepare("
    SELECT a.*,
           atc.name AS atc_name, atc.city AS atc_city, atc.district AS atc_district,
           atc.center_type, atc.atc_code, atc.id AS student_atc_id
    FROM admissions a
    LEFT JOIN atc_centers atc ON atc.id = a.atc_id
    WHERE a.id = ?
    LIMIT 1
");
$st->execute([$admissionId]);
$student = $st->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    http_response_code(404);
    die('<b>Error:</b> Student not found.');
}

$courseName = trim((string)($course['course_name'] ?? ''));
$courseType = $course['course_type'] ?? null;
$duration = trim((string)($course['duration'] ?? ''));
if ($duration === '') {
    $duration = '3 months';
}
$contents = trim((string)($course['course_content'] ?? ''));
if ($contents === '') {
    $contents = '—';
}
$contents = preg_replace('/\s+/', ' ', $contents);
if (function_exists('mb_strlen') && mb_strlen($contents) > 420) {
    $contents = mb_substr($contents, 0, 417) . '…';
} elseif (strlen($contents) > 420) {
    $contents = substr($contents, 0, 417) . '…';
}

$fullName = formatPersonNameTitleCase(trim(
    ($student['first_name'] ?? '') . ' ' .
    (!empty($student['middle_name']) ? $student['middle_name'] . ' ' : '') .
    ($student['last_name'] ?? '')
));
if ($fullName === '' || $courseName === '') {
    http_response_code(400);
    die('<b>Error:</b> Student name and course name are required.');
}

$regId = trim((string)($student['registration_id'] ?? ''));
if ($regId === '') {
    $regId = trim((string)($student['roll_no'] ?? ''));
}
if ($regId === '') {
    $regId = 'ADM-' . $admissionId;
}

$atc = [
    'name' => (string)($student['atc_name'] ?? ''),
    'city' => (string)($student['atc_city'] ?? ''),
    'district' => (string)($student['atc_district'] ?? ''),
    'center_type' => $student['center_type'] ?? null,
    'atc_code' => (string)($student['atc_code'] ?? ''),
];
$atcCity = trim((string)($atc['city'] ?: $atc['district']));
$conductedAt = trim((string)($atc['name'] ?: 'N/A')) . ($atcCity !== '' ? ', ' . $atcCity : '');
$atcNameMarksheet = $conductedAt;

$photoPath = null;
if (!empty($student['photo'])) {
    $p = __DIR__ . '/../' . ltrim(trim((string)$student['photo']), '/');
    if (is_file($p)) {
        $photoPath = $p;
    }
}

$issueDateRaw = trim((string)($src['issue_date'] ?? ''));
$issueTs = $issueDateRaw !== '' ? strtotime($issueDateRaw) : time();
if ($issueTs === false) {
    $issueTs = time();
}
$dateOfIssue = date('d/m/Y', $issueTs);
$examDate = date('Y-m-d', $issueTs);
$monthYear = date('F-Y', $issueTs);

$certBrand = courseCertificateBrand($courseType, $atc['center_type'] ?? null, $courseName);
$isTyping = isTypingCourse($courseType, $courseName);
$speeds = typingMarksheetSpeedDefaults($courseName);
if ($isTyping && ($contents === '—' || $contents === '')) {
    $contents = typingMarksheetDefaultContents($speeds['wpm']);
}

$isItSplit = isGiitItCourse($courseType, $courseName);
$exam40 = 0;
$atc60 = 0;
if ($isItSplit) {
    $exam40 = max(0, min(40, (int)round($score * 0.4)));
    $atc60 = max(0, min(60, $score - $exam40));
    $score = $exam40 + $atc60;
    $grade = courseExamGradeFromScore($score);
}

$durationLine = 'The course duration is ' . $duration;
$gradeLine = courseCertificateGradeLine($grade);
$courseAbv = strtoupper(preg_replace('/[^A-Z0-9]/i', '', substr($courseName, 0, 6)));

// Sample only — never allocate a real certificate number
$certNo = 'SAMPLE-' . $courseAbv . '-' . date('Y', $issueTs);

$template = courseCertificateTemplateBackground($certBrand);
if (!$template && $certBrand !== 'abacus') {
    die('<b>Template not found:</b> Upload <code>assets/templates/giit_course_certificate.pdf</code> (and optional PNG fallback).');
}

try {
    [$pdfCert, $W, $H] = beginCourseCertificatePdf($template);
    if (!$template || (($template['type'] ?? '') !== 'pdf' && ($template['type'] ?? '') !== 'png')) {
        gyanamAbacusCourseCertificateDrawFrame($pdfCert, $W, $H);
    }

    $put = function (string $text, float $y, float $size, string $style = 'B', string $color = '0,0,0') use ($pdfCert, $W) {
        [$r, $g, $b] = array_map('intval', explode(',', $color));
        $pdfCert->SetTextColor($r, $g, $b);
        $pdfCert->SetFont('Times', $style, $size);
        $pdfCert->SetXY(0, $y);
        $pdfCert->Cell($W, 0, $text, 0, 0, 'C');
    };
    $putLeft = function (string $text, float $x, float $y, float $size, string $style = 'B', string $color = '0,0,0') use ($pdfCert) {
        [$r, $g, $b] = array_map('intval', explode(',', $color));
        $pdfCert->SetTextColor($r, $g, $b);
        $pdfCert->SetFont('Times', $style, $size);
        $pdfCert->SetXY($x, $y);
        $pdfCert->Write(0, $text);
    };

    $L = courseCertificateOverlayLayout();
    paintCourseCertificateBodyText($put, $fullName, $courseName, $conductedAt, $durationLine, $gradeLine, $L, $pdfCert, $W);
    $putLeft('Certificate no: ' . $certNo, $L['cert_x'], $L['cert_y'], (float)$L['footer_size'], (string)$L['footer_style'], (string)$L['footer_color']);
    $putLeft('Date: ' . $dateOfIssue, $L['cert_x'], $L['date_y'], (float)$L['footer_size'], (string)$L['footer_style'], (string)$L['footer_color']);

    if ($photoPath) {
        try {
            $pdfCert->Image($photoPath, $L['photo_x'], $L['photo_y'], $L['photo_w'], $L['photo_h'], '', '', '', true, 72);
        } catch (Exception $imgE) {
        }
    }

    // Sample watermark-style note above footer (subtle)
    $pdfCert->SetTextColor(120, 120, 120);
    $pdfCert->SetFont('Times', 'I', 9);
    $pdfCert->SetXY(0, 218.0);
    $pdfCert->Cell($W, 0, 'SAMPLE — For demonstration only', 0, 0, 'C');

    $certBytes = $pdfCert->Output('S');

    $marksBytes = outputStatementOfMarksPdf([
        'student_id'      => $regId,
        'full_name'       => $fullName,
        'atc_name'        => $atcNameMarksheet,
        'course_name'     => $courseName,
        'course_contents' => $contents,
        'duration'        => $duration,
        'month_year'      => $monthYear,
        'center_code'     => trim((string)($atc['atc_code'] ?? '')) ?: '—',
        'score'           => $score,
        'grade'           => $grade,
        'brand'           => $certBrand,
        'is_typing'       => $isTyping,
        'is_it_split'     => $isItSplit && !$isTyping,
        'exam_40'         => $exam40,
        'atc_60'          => $atc60,
        'wpm'             => $speeds['wpm'],
        'kph'             => $speeds['kph'],
        'preview'         => true,
        'return_string'   => true,
    ]);
    if (!is_string($marksBytes) || $marksBytes === '') {
        throw new RuntimeException('Marksheet PDF could not be built.');
    }

    $merged = new Fpdi();
    $merged->SetTitle('Sample Certificate & Marksheet — ' . $courseName);
    $merged->SetAuthor('Gyanam India Educational Services');
    $merged->SetAutoPageBreak(false);

    foreach ([$certBytes, $marksBytes] as $pageBytes) {
        $pageCount = $merged->setSourceFile(StreamReader::createByString($pageBytes));
        for ($i = 1; $i <= $pageCount; $i++) {
            $tpl = $merged->importPage($i);
            $size = $merged->getTemplateSize($tpl);
            $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
            $merged->AddPage($orientation, [$size['width'], $size['height']]);
            $merged->useTemplate($tpl);
        }
    }

    $safeCourse = preg_replace('/[^A-Za-z0-9_-]+/', '_', $courseAbv ?: 'Course');
    $safeReg = preg_replace('/[^A-Za-z0-9_-]+/', '_', $regId);
    $filename = 'Sample_Certificate_Marksheet_' . $safeCourse . '_' . $safeReg . '.pdf';

    if (ob_get_level()) {
        ob_end_clean();
    }
    $merged->Output($preview ? 'I' : 'D', $filename);
    exit;
} catch (\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException $e) {
    http_response_code(500);
    echo '<h2>PDF Template Compatibility Issue</h2>';
    echo '<p>Add a PNG fallback under <code>assets/templates/</code>.</p>';
    echo '<p><small>' . htmlspecialchars($e->getMessage()) . '</small></p>';
} catch (Exception $e) {
    http_response_code(500);
    echo '<h2>Sample Pack Generation Error</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
}
