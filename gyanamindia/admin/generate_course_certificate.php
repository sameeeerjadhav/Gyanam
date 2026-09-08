<?php
/**
 * Gyanam India — Course Completion Certificate Generator
 * Uses FPDI to overlay dynamic student data on the PDF template.
 *
 * URL params:
 *   reg_id    = student registration_id (links to admissions)
 *   preview=1 = show inline (default downloads)
 *
 * Score and exam date are loaded from the Exam Portal — never from the URL.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
if (file_exists(__DIR__ . '/../includes/exam_integration.php')) {
    require_once __DIR__ . '/../includes/exam_integration.php';
}
requireLogin(['Admin', 'DLC']);

// ── Load FPDI ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../assets/fpdi/fpdi_autoload.php';
use setasign\Fpdi\Fpdi;

$pdo = getDBConnection();

// ── Inputs ────────────────────────────────────────────────────────────────────
$regId = trim($_GET['reg_id'] ?? '');

if (!$regId) {
    http_response_code(400);
    die('<b>Error:</b> Missing <code>reg_id</code> parameter.');
}

// ── Fetch student (admission) record ─────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT a.*,
           atc.name       AS atc_name,
           atc.city       AS atc_city,
           atc.district   AS atc_district,
           atc.atc_code,
           atc.id         AS atc_id,
           atc.center_type,
           c.duration     AS course_duration,
           c.course_type  AS course_type
    FROM   admissions a
    LEFT JOIN atc_centers atc ON atc.id = a.atc_id
    LEFT JOIN courses      c   ON c.course_name = a.course AND c.status = 'Active'
    WHERE  a.registration_id = ?
    LIMIT  1
");
$stmt->execute([$regId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    // Fallback: try roll_no
    $stmt = $pdo->prepare("
        SELECT a.*,
               atc.name     AS atc_name,
               atc.city     AS atc_city,
               atc.district AS atc_district,
               atc.atc_code,
               atc.id       AS atc_id,
               atc.center_type,
               c.duration   AS course_duration,
               c.course_type AS course_type
        FROM   admissions a
        LEFT JOIN atc_centers atc ON atc.id = a.atc_id
        LEFT JOIN courses      c   ON c.course_name = a.course AND c.status = 'Active'
        WHERE  a.roll_no = ?
        LIMIT  1
    ");
    $stmt->execute([$regId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$student) {
    http_response_code(404);
    die('<b>Error:</b> Student with registration ID <b>' . htmlspecialchars($regId) . '</b> not found.');
}

// ── ATC role: restrict to own students only ───────────────────────────────────
$sessionRole  = $_SESSION['role'] ?? '';
$sessionAtcId = intval($_SESSION['atc_id'] ?? 0);
if ($sessionRole === 'ATC CENTER' && intval($student['atc_id']) !== $sessionAtcId) {
    http_response_code(403);
    die('Access denied.');
}

// ── Eligibility: exam portal pass + (ATC) share/photo ─────────────────────────
$eligibility = validateCourseCertificateRequest($pdo, $student, $sessionRole);
if (!$eligibility['eligible']) {
    http_response_code(403);
    die('<b>Certificate not available:</b> ' . htmlspecialchars($eligibility['message']));
}

$examPass = $eligibility['exam'];
$score    = (int)($examPass['score'] ?? 0);
$examDate = (string)($examPass['exam_date'] ?? date('Y-m-d'));

// ── Build dynamic values ──────────────────────────────────────────────────────

// 1. Student name — full caps as shown on template
$fullName = strtoupper(trim(
    $student['first_name'] . ' ' .
    ($student['middle_name'] ? $student['middle_name'] . ' ' : '') .
    $student['last_name']
));

// 2. Course name
$courseName = trim($student['course'] ?? 'N/A');
if (empty($student['course_type']) && $courseName !== '' && $courseName !== 'N/A') {
    try {
        $ctSt = $pdo->prepare("SELECT course_type FROM courses WHERE status = 'Active' AND (course_name = ? OR ? LIKE CONCAT(course_name, '%')) LIMIT 1");
        $ctSt->execute([$courseName, $courseName]);
        $foundType = $ctSt->fetchColumn();
        if ($foundType) {
            $student['course_type'] = $foundType;
        }
    } catch (\Exception $e) {}
}

// 3. ATC name + city  (e.g. "Aim Computers, Jalgaon")
$atcCity     = trim($student['atc_city'] ?? $student['atc_district'] ?? '');
$conductedAt = trim($student['atc_name'] ?? 'N/A') . ($atcCity ? ', ' . $atcCity : '');

// 4. Duration  (e.g. "3 months" from courses table, or default)
$duration = trim($student['course_duration'] ?? '');
if (!$duration) $duration = '3 months';  // safe fallback
$durationLine = 'The course duration is ' . $duration;

// 5. Grade from score — bands match GIIT template footer (A++ … C)
$grade = courseExamGradeFromScore($score);
if ($grade === 'Fail') {
    http_response_code(400);
    die('<b>Error:</b> Certificate cannot be issued — exam score is below passing grade (40%).');
}

$gradeLine = courseCertificateGradeLine($grade);

// 6. Certificate number — IT: GIIT2026-1; Abacus: legacy course-reg-###
$dateOfIssue = date('d/m/Y', strtotime($examDate ?: date('Y-m-d')));
$issueYear = (int)date('Y', strtotime($examDate ?: date('Y-m-d')));

$certBrand = courseCertificateBrand(
    $student['course_type'] ?? null,
    $student['center_type'] ?? null,
    $courseName
);
$courseAbv = strtoupper(preg_replace('/[^A-Z0-9]/i', '', substr($courseName, 0, 6)));
$preview = isset($_GET['preview']);
$certNo = buildCourseCertificateNumber(
    $pdo,
    $certBrand,
    $regId,
    $courseName,
    $issueYear,
    !$preview
);

// ── Template (GIIT for IT courses, Gyanam Abacus for Abacus/Vedic) ───────────
$template = courseCertificateTemplateBackground($certBrand);
if (!$template && $certBrand !== 'abacus') {
    die('<b>Template not found:</b> Upload <code>assets/templates/giit_course_certificate.pdf</code> (and optional PNG fallback).');
}

// ── Student photo ─────────────────────────────────────────────────────────────
$photoPath = null;
if (!empty($student['photo'])) {
    $p = __DIR__ . '/../' . ltrim($student['photo'], '/');
    if (file_exists($p)) $photoPath = $p;
}

// ── Generate PDF ──────────────────────────────────────────────────────────────
try {
    [$pdf, $W, $H] = beginCourseCertificatePdf($template);
    if (!$template || (($template['type'] ?? '') !== 'pdf' && ($template['type'] ?? '') !== 'png')) {
        gyanamAbacusCourseCertificateDrawFrame($pdf, $W, $H);
    }

    // ── Helper: centered text ─────────────────────────────────────────────────
    $put = function (string $text, float $y, float $size, string $style = 'B', string $color = '0,0,0') use ($pdf, $W) {
        [$r, $g, $b] = array_map('intval', explode(',', $color));
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetFont('Times', $style, $size);
        $pdf->SetXY(0, $y);
        $pdf->Cell($W, 0, $text, 0, 0, 'C');
    };

    // ── Helper: left-aligned text ─────────────────────────────────────────────
    $putLeft = function (string $text, float $x, float $y, float $size, string $style = 'B', string $color = '0,0,0') use ($pdf) {
        [$r, $g, $b] = array_map('intval', explode(',', $color));
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetFont('Times', $style, $size);
        $pdf->SetXY($x, $y);
        $pdf->Write(0, $text);
    };

    // ── Coordinate map — blank GIIT course certificate (A4) ──
    $L = courseCertificateOverlayLayout();

    paintCourseCertificateBodyText($put, $fullName, $courseName, $conductedAt, $durationLine, $gradeLine, $L);
    $putLeft('Certificate no: ' . $certNo, $L['cert_x'], $L['cert_y'], (float)$L['footer_size'], (string)$L['footer_style'], (string)$L['footer_color']);
    $putLeft('Date: ' . $dateOfIssue, $L['cert_x'], $L['date_y'], (float)$L['footer_size'], (string)$L['footer_style'], (string)$L['footer_color']);

    if ($photoPath) {
        try {
            $pdf->Image($photoPath, $L['photo_x'], $L['photo_y'], $L['photo_w'], $L['photo_h'], '', '', '', true, 72);
        } catch (\Exception $imgE) {
            // Photo load failed — skip silently
        }
    }

    // QR on both preview and download (black modules only — no white card)
    try {
        $issued = issueCertificateRecord($pdo, [
            'cert_no' => $certNo,
            'student_name' => $fullName,
            'reg_id' => $regId,
            'course' => $courseName,
            'atc_name' => trim((string)($student['atc_name'] ?? '')),
            'atc_code' => trim((string)($student['atc_code'] ?? '')),
            'score' => $score,
            'grade' => $grade,
            'duration' => $duration,
            'issue_date' => $examDate ?: date('Y-m-d'),
            'brand' => $certBrand,
            'photo_path' => trim((string)($student['photo'] ?? '')),
            'admission_id' => (int)($student['id'] ?? 0) ?: null,
            'issued_by_atc_id' => $sessionAtcId ?: (int)($student['atc_id'] ?? 0) ?: null,
            'source' => 'exam',
        ]);
        embedCertificateVerifyQr($pdf, $issued['verify_url'], $L['qr_x'], $L['qr_y'], $L['qr_size']);
    } catch (\Throwable $qrE) {
        // Non-fatal: certificate still prints without QR
    }

    // ── Output ────────────────────────────────────────────────────────────────
    $dest     = $preview ? 'I' : 'D';
    $safeReg  = preg_replace('/[^A-Za-z0-9_-]/', '_', $regId);
    $filename = 'Certificate_' . $safeReg . '_' . $courseAbv . '.pdf';

    if (ob_get_level()) {
        ob_end_clean();
    }
    $pdf->Output($dest, $filename);
    exit;

} catch (\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException $e) {
    http_response_code(500);
    echo '<h2>PDF Template Compatibility Issue</h2>';
    echo '<p>The course certificate template uses a compressed format (PDF 1.5+) that FPDI cannot read without a paid extension.</p>';
    echo '<p><b>Fix:</b> Add <code>assets/templates/' . ($certBrand === 'abacus' ? 'gyanam_abacus_course_certificate.png' : 'giit_course_certificate.png') . '</code> (full-page raster of the PDF) — the generator will use it automatically.</p>';
    echo '<p><small>' . htmlspecialchars($e->getMessage()) . '</small></p>';
} catch (\Exception $e) {
    http_response_code(500);
    echo '<h2>Certificate Generation Error</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
}
