<?php
/**
 * Temporary: Course Completion Certificate for allowlisted ATC.
 * Prefer admission_id + score (details loaded from DB). Legacy POST fields still work.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin(['ATC CENTER']);

require_once __DIR__ . '/../assets/fpdi/fpdi_autoload.php';
use setasign\Fpdi\Fpdi;

$pdo = getDBConnection();
$sessionAtcId = (int)($_SESSION['atc_id'] ?? 0);
$sessionAtcCode = (string)($_SESSION['atc_code'] ?? '');

if (!atcCanUseManualCourseCertificate($sessionAtcId, $sessionAtcCode)) {
    http_response_code(403);
    die('<b>Access denied:</b> Manual certificate is not enabled for this ATC.');
}

$src = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
$preview = isset($src['preview']) || isset($_GET['preview']);
$score = (int)($src['score'] ?? 0);
$admissionId = (int)($src['admission_id'] ?? 0);

$fullName = '';
$courseName = '';
$regId = '';
$duration = '';
$photoPath = null;
$courseType = null;

$atcStmt = $pdo->prepare('SELECT name, city, district, center_type, atc_code FROM atc_centers WHERE id = ? LIMIT 1');
$atcStmt->execute([$sessionAtcId]);
$atc = $atcStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$atcCity = trim((string)($atc['city'] ?? $atc['district'] ?? ''));
$conductedAt = trim((string)($atc['name'] ?? 'N/A')) . ($atcCity !== '' ? ', ' . $atcCity : '');

if ($admissionId > 0) {
    $st = $pdo->prepare("
        SELECT a.*,
               COALESCE(NULLIF(TRIM(c.duration), ''), '') AS course_duration,
               c.course_type AS course_type
        FROM admissions a
        LEFT JOIN courses c ON c.course_name = a.course AND c.status = 'Active'
        WHERE a.id = ? AND a.atc_id = ?
        LIMIT 1
    ");
    $st->execute([$admissionId, $sessionAtcId]);
    $student = $st->fetch(PDO::FETCH_ASSOC);
    if (!$student) {
        http_response_code(404);
        die('<b>Error:</b> Student not found for your ATC.');
    }

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
        $p = __DIR__ . '/../' . ltrim((string)$student['photo'], '/');
        if (is_file($p)) {
            $photoPath = $p;
        }
    }
} else {
    // Legacy manual fields
    $fullName = strtoupper(trim((string)($src['student_name'] ?? '')));
    $courseName = trim((string)($src['course_name'] ?? ''));
    $regId = trim((string)($src['reg_id'] ?? ''));
    $duration = trim((string)($src['duration'] ?? ''));
    if ($regId === '') {
        $regId = 'MANUAL-' . $sessionAtcId . '-' . date('YmdHis');
    }
    if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
        $tmp = $_FILES['photo']['tmp_name'];
        $info = @getimagesize($tmp);
        if ($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            $photoPath = $tmp;
        }
    }
    try {
        $cSt = $pdo->prepare("SELECT duration, course_type FROM courses WHERE status = 'Active' AND (course_name = ? OR ? LIKE CONCAT(course_name, '%')) LIMIT 1");
        $cSt->execute([$courseName, $courseName]);
        $cRow = $cSt->fetch(PDO::FETCH_ASSOC);
        if ($cRow) {
            if ($duration === '') {
                $duration = trim((string)($cRow['duration'] ?? ''));
            }
            $courseType = $cRow['course_type'] ?? null;
        }
    } catch (Exception $e) {}
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
$gradeLine = "and has passed the examination with '" . $grade . "' grade";

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
    $pdf = new Fpdi();
    $W = (is_array($template) && !empty($template['width'])) ? (float)$template['width'] : 210.0;
    $H = (is_array($template) && !empty($template['height'])) ? (float)$template['height'] : 297.0;

    $pdf->AddPage($W > $H ? 'L' : 'P', [$W, $H]);

    if ($template && $template['type'] === 'pdf') {
        $pdf->setSourceFile($template['path']);
        $tplId = $pdf->importPage(1);
        $pdf->useTemplate($tplId, 0, 0, $W, $H);
    } elseif ($template && $template['type'] === 'png') {
        $pdf->Image($template['path'], 0, 0, $W, $H, 'PNG');
    } else {
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

    $put($fullName, 128, 20, 'BI', '180,0,0');
    $put($courseName, 146, 16, 'B', '180,0,0');
    $put($conductedAt, 161, 14, 'B', '0,0,128');
    $put($durationLine, 171, 14, 'B', '30,30,30');
    $put($gradeLine, 181, 14, 'B', '0,0,128');
    $putLeft($certNo, 38, 248, 11, 'B', '30,30,30');
    $putLeft($dateOfIssue, 38, 256, 11, 'B', '30,30,30');

    if ($photoPath) {
        try {
            $pdf->Image($photoPath, 148, 118, 32, 38, '', '', '', true, 72);
        } catch (Exception $imgE) {
            // skip photo
        }
    }

    $qrTmp = null;
    if (!$preview) {
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
                'admission_id' => $admissionId > 0 ? $admissionId : null,
                'issued_by_atc_id' => $sessionAtcId ?: null,
                'source' => 'manual',
            ]);
            $qrTmp = embedCertificateVerifyQr($pdf, $issued['verify_url'], 168, 242, 26);
        } catch (Throwable $qrE) {
            // Non-fatal
        }
    }

    $dest = $preview ? 'I' : 'D';
    $safeReg = preg_replace('/[^A-Za-z0-9_-]/', '_', $regId);
    $filename = 'Certificate_' . $safeReg . '_' . $courseAbv . '.pdf';

    if (ob_get_level()) {
        ob_end_clean();
    }
    $pdf->Output($dest, $filename);
    if ($qrTmp && is_file($qrTmp)) {
        @unlink($qrTmp);
    }
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
