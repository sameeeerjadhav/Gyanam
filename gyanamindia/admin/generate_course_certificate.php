<?php
/**
 * Gyanam India — Course Completion Certificate Generator
 * Uses FPDI to overlay dynamic student data on the PDF template.
 *
 * URL params:
 *   reg_id    = student registration_id (links to admissions)
 *   score     = exam score 0-100 (from exam portal)
 *   exam_date = date student passed (submitted_at from exam portal, YYYY-MM-DD)
 *   preview=1 = show inline (default downloads)
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin(['Admin', 'DLC', 'ATC CENTER']);

// ── Load FPDI ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../assets/fpdi/fpdi_autoload.php';
use setasign\Fpdi\Fpdi;

$pdo = getDBConnection();

// ── Inputs ────────────────────────────────────────────────────────────────────
$regId    = trim($_GET['reg_id']    ?? '');
$score    = intval($_GET['score']   ?? 0);
$examDate = trim($_GET['exam_date'] ?? date('Y-m-d'));

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
           c.duration     AS course_duration
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
               c.duration   AS course_duration
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

// ── Build dynamic values ──────────────────────────────────────────────────────

// 1. Student name — full caps as shown on template
$fullName = strtoupper(trim(
    $student['first_name'] . ' ' .
    ($student['middle_name'] ? $student['middle_name'] . ' ' : '') .
    $student['last_name']
));

// 2. Course name
$courseName = trim($student['course'] ?? 'N/A');

// 3. ATC name + city  (e.g. "Aim Computers, Jalgaon")
$atcCity     = trim($student['atc_city'] ?? $student['atc_district'] ?? '');
$conductedAt = trim($student['atc_name'] ?? 'N/A') . ($atcCity ? ', ' . $atcCity : '');

// 4. Duration  (e.g. "3 months" from courses table, or default)
$duration = trim($student['course_duration'] ?? '');
if (!$duration) $duration = '3 months';  // safe fallback
$durationLine = 'The course duration is ' . $duration;

// 5. Grade from score (0–100)
$grade = 'C';
if ($score >= 90)      $grade = 'A++';
elseif ($score >= 80)  $grade = 'A+';
elseif ($score >= 66)  $grade = 'A';
elseif ($score >= 55)  $grade = 'B';
elseif ($score >= 40)  $grade = 'C';
else                   $grade = 'Fail'; // shouldn't be generated for fails

$gradeLine = 'and has passed the examination with "' . $grade . '" grade';

// 6. Certificate number: CourseName(abbrev) + RegId + counter
//    e.g. MSCIT-GYANAM1-001
$courseAbv  = strtoupper(preg_replace('/[^A-Z0-9]/i', '', substr($courseName, 0, 6)));
$certBase   = $courseAbv . '-' . strtoupper($regId);

// Counter: how many certs have been issued to this student for this course
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cert_counters (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reg_id VARCHAR(50) NOT NULL,
        course VARCHAR(200) NOT NULL,
        counter INT NOT NULL DEFAULT 1,
        issued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_reg_course (reg_id, course)
    )");
    $pdo->prepare("INSERT INTO cert_counters (reg_id, course, counter)
                   VALUES (?, ?, 1)
                   ON DUPLICATE KEY UPDATE counter = counter")->execute([$regId, $courseName]);
    $counter = (int)$pdo->prepare("SELECT counter FROM cert_counters WHERE reg_id=? AND course=?")
                         ->execute([$regId, $courseName]) ? 1 : 1;
    $cRow = $pdo->prepare("SELECT counter FROM cert_counters WHERE reg_id=? AND course=?");
    $cRow->execute([$regId, $courseName]);
    $counter = (int)($cRow->fetchColumn() ?: 1);
} catch (\Exception $e) {
    $counter = 1;
}
$certNo = $certBase . '-' . str_pad($counter, 3, '0', STR_PAD_LEFT);

// 7. Date of issue — the day student passed (from exam portal)
$dateOfIssue = date('d/m/Y', strtotime($examDate ?: date('Y-m-d')));

// ── Template path ─────────────────────────────────────────────────────────────
$templatePath = __DIR__ . '/../assets/templates/giit_course_certificate.pdf';
if (!file_exists($templatePath)) {
    die('<b>Template not found:</b> ' . htmlspecialchars($templatePath));
}

// ── Student photo ─────────────────────────────────────────────────────────────
$photoPath = null;
if (!empty($student['photo'])) {
    $p = __DIR__ . '/../' . ltrim($student['photo'], '/');
    if (file_exists($p)) $photoPath = $p;
}

// ── Generate PDF ──────────────────────────────────────────────────────────────
try {
    $pdf = new Fpdi();
    $pdf->setSourceFile($templatePath);
    $tplId = $pdf->importPage(1);
    $size  = $pdf->getTemplateSize($tplId);

    $W = $size['width'];
    $H = $size['height'];

    $pdf->AddPage($W > $H ? 'L' : 'P', [$W, $H]);
    $pdf->useTemplate($tplId, 0, 0, $W, $H);

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

    // ── Coordinate map for giit_course_certificate.pdf (A4: ~210.8 × 298.1 mm) ──
    //
    // Template layout (from image):
    //   ~Y=110  → "Certificate" ribbon (template graphic)
    //   ~Y=125  → "This is to certify that" (already on template)
    //   Y=134   → Student name (RED, bold-italic, large)
    //   ~Y=142  → "Has Successfully completed" (template)
    //   Y=150   → Course name (RED, bold)
    //   ~Y=158  → "Conducted at" (template)
    //   Y=165   → ATC name, city
    //   Y=173   → Duration line
    //   Y=181   → Grade line
    //   Y=196   → Certificate No. value (after label on template)
    //   Y=203   → Date of issue value (after label on template)

    // Student name — RED, bold-italic
    $put($fullName, 134, 20, 'BI', '180,0,0');

    // Course name — RED, bold
    $put($courseName, 151, 17, 'B', '180,0,0');

    // Conducted at (ATC name + city)
    $put($conductedAt, 167, 14, 'B', '30,30,30');

    // Duration line
    $put($durationLine, 176, 14, 'B', '30,30,30');

    // Grade line
    $put($gradeLine, 185, 14, 'B', '30,30,30');

    // Certificate No. & Date of issue — commented out pending live calibration
    // $putLeft($certNo, 50, 206, 11, 'B', '30,30,30');
    // $putLeft($dateOfIssue, 44, 216, 11, 'B', '30,30,30');

    // ── Student photo overlay ─────────────────────────────────────────────────
    // Photo area is top-right of content: roughly X=148, Y=118, 32×38mm
    if ($photoPath) {
        try {
            $pdf->Image($photoPath, 148, 118, 32, 38, '', '', '', true, 72);
        } catch (\Exception $imgE) {
            // Photo load failed — skip silently
        }
    }

    // ── Output ────────────────────────────────────────────────────────────────
    $inline   = isset($_GET['preview']);
    $dest     = $inline ? 'I' : 'D';
    $safeReg  = preg_replace('/[^A-Za-z0-9_-]/', '_', $regId);
    $filename = 'Certificate_' . $safeReg . '_' . $courseAbv . '.pdf';

    ob_end_clean();
    $pdf->Output($dest, $filename);
    exit;

} catch (\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException $e) {
    http_response_code(500);
    echo '<h2>PDF Template Compatibility Issue</h2>';
    echo '<p>The course certificate template uses a compressed format (PDF 1.5+). Please resave <code>giit_course_certificate.pdf</code> as <b>PDF 1.4</b> (Acrobat 5 compatible).</p>';
    echo '<p><small>' . htmlspecialchars($e->getMessage()) . '</small></p>';
} catch (\Exception $e) {
    http_response_code(500);
    echo '<h2>Certificate Generation Error</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
}
