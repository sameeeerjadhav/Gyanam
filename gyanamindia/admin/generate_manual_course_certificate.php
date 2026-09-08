<?php
/**
 * Admin-only: Course Completion Certificate without exam portal result.
 * Prefer admission_id + score (details loaded from DB).
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

$fullName = '';
$courseName = '';
$regId = '';
$duration = '';
$photoPath = null;
$photoRel = '';
$courseType = null;
$atc = [];
$studentAtcId = 0;
$conductedAt = 'N/A';

if ($admissionId > 0) {
    $st = $pdo->prepare("
        SELECT a.*,
               COALESCE(NULLIF(TRIM(c.duration), ''), '') AS course_duration,
               c.course_type AS course_type,
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

    $fullName = strtoupper(trim(
        ($student['first_name'] ?? '') . ' ' .
        (!empty($student['middle_name']) ? $student['middle_name'] . ' ' : '') .
        ($student['last_name'] ?? '')
    ));
    $courseName = trim((string)($student['course'] ?? ''));
    $regId = trim((string)($student['registration_id'] ?? ''));
    if ($regId === '') {
        $regId = trim((string)($student['roll_no'] ?? ''));
    }
    if ($regId === '') {
        $regId = 'ADM-' . $admissionId;
    }
    $duration = trim((string)($student['course_duration'] ?? ''));
    $courseType = $student['course_type'] ?? null;

    if (!empty($student['photo'])) {
        $photoRel = trim((string)$student['photo']);
        $p = __DIR__ . '/../' . ltrim($photoRel, '/');
        if (is_file($p)) {
            $photoPath = $p;
        }
    }
} else {
    http_response_code(400);
    die('<b>Error:</b> Select a student first.');
}

if ($fullName === '' || $courseName === '') {
    http_response_code(400);
    die('<b>Error:</b> Student name and course are required. Select a student from the list.');
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

if ($duration === '') {
    $duration = '3 months';
}
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
    [$pdf, $W, $H] = beginCourseCertificatePdf($template);
    if (!$template || (($template['type'] ?? '') !== 'pdf' && ($template['type'] ?? '') !== 'png')) {
        gyanamAbacusCourseCertificateDrawFrame($pdf, $W, $H);
    }

    $put = function (string $text, float $y, float $size, string $style = 'B', string $color = '0,0,0') use ($pdf, $W) {
        [$r, $g, $b] = array_map('intval', explode(',', $color));
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetFont('Times', $style, $size);
        $pdf->SetXY(0, $y);
        $pdf->Cell($W, 0, $text, 0, 0, 'C');
    };

    $putLeft = function (string $text, float $x, float $y, float $size, string $style = 'B', string $color = '0,0,0') use ($pdf) {
        [$r, $g, $b] = array_map('intval', explode(',', $color));
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetFont('Times', $style, $size);
        $pdf->SetXY($x, $y);
        $pdf->Write(0, $text);
    };

    $L = courseCertificateOverlayLayout();

    paintCourseCertificateBodyText($put, $fullName, $courseName, $conductedAt, $durationLine, $gradeLine, $L);
    $putLeft('Certificate no: ' . $certNo, $L['cert_x'], $L['cert_y'], (float)$L['footer_size'], (string)$L['footer_style'], (string)$L['footer_color']);
    $putLeft('Date: ' . $dateOfIssue, $L['cert_x'], $L['date_y'], (float)$L['footer_size'], (string)$L['footer_style'], (string)$L['footer_color']);

    if ($photoPath) {
        try {
            $pdf->Image($photoPath, $L['photo_x'], $L['photo_y'], $L['photo_w'], $L['photo_h'], '', '', '', true, 72);
        } catch (Exception $imgE) {
            // skip photo
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
            'issue_date' => date('Y-m-d', $issueTs),
            'brand' => $certBrand,
            'photo_path' => $photoRel,
            'admission_id' => $admissionId > 0 ? $admissionId : null,
            'issued_by_atc_id' => $studentAtcId ?: null,
            'source' => 'manual',
        ]);
        embedCertificateVerifyQr($pdf, $issued['verify_url'], $L['qr_x'], $L['qr_y'], $L['qr_size']);
    } catch (Throwable $qrE) {
        // Non-fatal
    }

    $dest = $preview ? 'I' : 'D';
    $safeReg = preg_replace('/[^A-Za-z0-9_-]/', '_', $regId);
    $filename = 'Certificate_' . $safeReg . '_' . $courseAbv . '.pdf';

    if (ob_get_level()) {
        ob_end_clean();
    }
    $pdf->Output($dest, $filename);
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
