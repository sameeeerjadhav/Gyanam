<?php
/**
 * Admin-only: Generate Certificate pack — course completion certificate + Statement of Marks
 * as a single PDF. Saves IT Exam/40 + Internal/60 (or typing particulars) so ATC Student Marks updates.
 *
 * Params: admission_id + score | exam_40+atc_marks | typing_marks [, preview=1]
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
$score = (int)($src['score'] ?? 0);
$admissionId = (int)($src['admission_id'] ?? 0);

if ($admissionId <= 0) {
    http_response_code(400);
    die('<b>Error:</b> Select a student first.');
}

$st = $pdo->prepare("
    SELECT a.*,
           COALESCE(NULLIF(TRIM(c.duration), ''), '') AS course_duration,
           c.course_type AS course_type,
           c.course_content AS course_content,
           atc.name AS atc_name, atc.city AS atc_city, atc.district AS atc_district,
           atc.center_type, atc.atc_code, atc.id AS student_atc_id
    FROM admissions a
    LEFT JOIN courses c ON c.course_name = a.course AND c.status = 'Active'
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

$studentAtcId = (int)($student['student_atc_id'] ?? $student['atc_id'] ?? 0);
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

$fullName = formatPersonNameTitleCase(trim(
    ($student['first_name'] ?? '') . ' ' .
    (!empty($student['middle_name']) ? $student['middle_name'] . ' ' : '') .
    ($student['last_name'] ?? '')
));
$courseName = trim((string)($student['course'] ?? ''));
$courseType = $student['course_type'] ?? null;
$regId = trim((string)($student['registration_id'] ?? ''));
if ($regId === '') {
    $regId = trim((string)($student['roll_no'] ?? ''));
}
if ($regId === '') {
    $regId = 'ADM-' . $admissionId;
}
$duration = trim((string)($student['course_duration'] ?? ''));
if ($duration === '') {
    $duration = '3 months';
}

$photoPath = null;
$photoRel = '';
if (!empty($student['photo'])) {
    $photoRel = trim((string)$student['photo']);
    $p = __DIR__ . '/../' . ltrim($photoRel, '/');
    if (is_file($p)) {
        $photoPath = $p;
    }
}

if ($fullName === '' || $courseName === '') {
    http_response_code(400);
    die('<b>Error:</b> Student name and course are required.');
}

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

$isTyping = isTypingCourse($courseType, $courseName);
$speeds = typingMarksheetSpeedDefaults($courseName);
if ($isTyping && ($contents === '—' || $contents === '')) {
    $contents = typingMarksheetDefaultContents($speeds['wpm']);
}

$isItSplit = false;
$exam40 = 0;
$atc60 = 0;
$typingObtained = null;

// Typing: save particulars + derive total
$rawTyping = $src['typing_marks'] ?? null;
if ($isTyping) {
    if (is_array($rawTyping)) {
        if (!upsertAdmissionTypingMarks(
            $pdo,
            $admissionId,
            $rawTyping,
            $studentAtcId ?: null,
            (int)($_SESSION['user_id'] ?? 0) ?: null,
            'Admin',
            $speeds['wpm'],
            $speeds['kph']
        )) {
            http_response_code(400);
            die('<b>Error:</b> Invalid typing particulars marks.');
        }
    }
    $savedT = getAdmissionTypingMarks($pdo, $admissionId, $speeds['wpm'], $speeds['kph']);
    if (!$savedT) {
        http_response_code(400);
        die('<b>Error:</b> Enter all typing particulars marks.');
    }
    $score = (int)$savedT['total'];
    $typingObtained = $savedT['obtained'];
}

// IT: Exam/40 + Internal/60 — persist so ATC Student Marks updates
$exam40Manual = isset($src['exam_40']) ? (int)$src['exam_40'] : null;
$atc60Manual = isset($src['atc_marks']) ? (int)$src['atc_marks'] : null;
if (!$isTyping && isGiitItCourse($courseType, $courseName)) {
    if ($atc60Manual === null || $exam40Manual === null) {
        $saved = getAdmissionAtcMarks($pdo, $admissionId);
        if ($saved !== null) {
            if ($atc60Manual === null) {
                $atc60Manual = (int)$saved['atc_marks'];
            }
            if ($exam40Manual === null && isset($saved['exam_marks']) && $saved['exam_marks'] !== null) {
                $exam40Manual = (int)$saved['exam_marks'];
            }
        }
    }
    if ($exam40Manual === null || $atc60Manual === null) {
        http_response_code(400);
        die('<b>Error:</b> Enter Main Exam marks /40 and ATC internal marks /60.');
    }
    $exam40 = max(0, min(40, $exam40Manual));
    $atc60 = max(0, min(60, $atc60Manual));
    $score = $exam40 + $atc60;
    $isItSplit = true;
    upsertAdmissionAtcMarks(
        $pdo,
        $admissionId,
        $atc60,
        $studentAtcId ?: null,
        (int)($_SESSION['user_id'] ?? 0) ?: null,
        'Admin',
        $exam40
    );
}

if ($score < 40 || $score > 100) {
    http_response_code(400);
    die('<b>Error:</b> Score must be between 40 and 100 (passing grade required).');
}

$grade = courseExamGradeFromScore($score);
if ($grade === 'Fail') {
    http_response_code(400);
    die('<b>Error:</b> Certificate cannot be issued — score is below passing grade.');
}

$issueDateRaw = trim((string)($src['issue_date'] ?? ''));
$issueTs = $issueDateRaw !== '' ? strtotime($issueDateRaw) : time();
if ($issueTs === false) {
    $issueTs = time();
}
$dateOfIssue = date('d/m/Y', $issueTs);
$examDate = date('Y-m-d', $issueTs);
$monthYear = date('F-Y', $issueTs);

$durationLine = 'The course duration is ' . $duration;
$gradeLine = courseCertificateGradeLine($grade);
$certBrand = courseCertificateBrand($courseType, $atc['center_type'] ?? null, $courseName);
$courseAbv = strtoupper(preg_replace('/[^A-Z0-9]/i', '', substr($courseName, 0, 6)));
$certNo = buildCourseCertificateNumber(
    $pdo,
    $certBrand,
    $regId,
    $courseName,
    (int)date('Y', $issueTs),
    !$preview
);

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

    try {
        $issued = issueCertificateRecord($pdo, [
            'cert_no' => $certNo,
            'student_name' => $fullName,
            'reg_id' => $regId,
            'course' => $courseName,
            'atc_name' => trim((string)($atc['name'] ?? '')),
            'atc_code' => trim((string)($atc['atc_code'] ?? '')),
            'score' => $score,
            'grade' => $grade,
            'duration' => $duration,
            'issue_date' => $examDate,
            'brand' => $certBrand,
            'photo_path' => $photoRel,
            'admission_id' => $admissionId,
            'issued_by_atc_id' => $studentAtcId ?: null,
            'source' => 'manual',
        ]);
        embedCertificateVerifyQr($pdfCert, $issued['verify_url'], $L['qr_x'], $L['qr_y'], $L['qr_size']);
    } catch (Throwable $qrE) {
        // Non-fatal
    }

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
        'typing_obtained' => $typingObtained,
        'wpm'             => $speeds['wpm'],
        'kph'             => $speeds['kph'],
        'preview'         => true,
        'return_string'   => true,
    ]);
    if (!is_string($marksBytes) || $marksBytes === '') {
        throw new RuntimeException('Marksheet PDF could not be built.');
    }

    $merged = new Fpdi();
    $merged->SetTitle('Certificate & Marksheet — ' . $fullName);
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

    $safeReg = preg_replace('/[^A-Za-z0-9_-]+/', '_', $regId);
    $filename = 'Certificate_' . $safeReg . '_' . ($courseAbv ?: 'Course') . '.pdf';

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
    echo '<h2>Certificate Generation Error</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
}
