<?php
/**
 * Gyanam India — ATC Authorization Certificate Generator
 * Uses FPDI to overlay dynamic text on the PDF template.
 *
 * URL: admin/generate_auth_certificate.php?atc_id=XX
 *      admin/generate_auth_certificate.php?atc_id=XX&preview=1  (inline view)
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin(['Admin', 'DLC', 'ATC CENTER']);

// ── Load FPDI (manual, no Composer) ──────────────────────────────────────────
require_once __DIR__ . '/../assets/fpdi/fpdi_autoload.php';
use setasign\Fpdi\Fpdi;

$pdo = getDBConnection();

// ── Validate atc_id ───────────────────────────────────────────────────────────
$atcId = intval($_GET['atc_id'] ?? 0);
if (!$atcId) {
    http_response_code(400);
    die('<b>Error:</b> Missing <code>atc_id</code> parameter.');
}

// ATC role can only download their own
$sessionRole  = $_SESSION['role'] ?? '';
$sessionAtcId = intval($_SESSION['atc_id'] ?? 0);
if ($sessionRole === 'ATC CENTER' && $sessionAtcId !== $atcId) {
    http_response_code(403);
    die('Access denied.');
}

// ── Fetch ATC record ──────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM atc_centers WHERE id = ?");
$stmt->execute([$atcId]);
$atc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$atc) {
    http_response_code(404);
    die('ATC Center not found.');
}

// ── Build dynamic values ──────────────────────────────────────────────────────

// 1. ATC Name
$atcName = trim($atc['name'] ?? 'N/A');

// 2. ATC Code  e.g. "Gyanam ATC-20260001"
$atcCode = !empty($atc['atc_code'])
    ? 'Gyanam ATC-' . $atc['atc_code']
    : 'Gyanam ATC-' . date('Y') . str_pad($atcId, 5, '0', STR_PAD_LEFT);

// 3. Course types → sentence
$typeMap = [
    'it'                 => 'IT Courses',
    'abacus'             => 'Abacus Courses',
    'vedic_maths'        => 'Vedic Maths Courses',
    'vedic maths'        => 'Vedic Maths Courses',
    'vedicmaths'         => 'Vedic Maths Courses',
    'it_abacus'          => 'IT and Abacus Courses',
    'it+abacus'          => 'IT and Abacus Courses',
    'it_vedic'           => 'IT and Vedic Maths Courses',
    'it+vedicmaths'      => 'IT and Vedic Maths Courses',
    'it+vedic maths'     => 'IT and Vedic Maths Courses',
    'it+vedic_maths'     => 'IT and Vedic Maths Courses',
    'abacus_vedic'       => 'Abacus and Vedic Maths Courses',
    'abacus+vedicmaths'  => 'Abacus and Vedic Maths Courses',
    'abacus+vedic maths' => 'Abacus and Vedic Maths Courses',
    'abacus+vedic_maths' => 'Abacus and Vedic Maths Courses',
    'all'                => 'IT, Abacus and Vedic Maths Courses',
    'it+abacus+vedic'    => 'IT, Abacus and Vedic Maths Courses',
    'abacus+vedic+it'    => 'IT, Abacus and Vedic Maths Courses',
    'it_abacus_vedic'    => 'IT, Abacus and Vedic Maths Courses',
];
$ctRaw = strtolower(trim($atc['center_type'] ?? 'it'));
$courseLine = $typeMap[$ctRaw]
    ?? ucwords(str_replace(['_', '+'], [' and ', ' and '], $ctRaw)) . ' Courses';

// "Conducting our [courseLine]" → if too long, we'll split
$conductingLine = 'Conducting our ' . $courseLine;

// 4. Location
$city     = trim($atc['city'] ?? $atc['taluka'] ?? '');
$district = trim($atc['district'] ?? '');
$location = $city ? "at $city, Dist. $district." : "at Dist. $district.";

// 5. Auth Period
$authStart = !empty($atc['date_created'])
    ? $atc['date_created']
    : date('Y-m-d');
$authEnd = !empty($atc['authorization_expires_at'])
    ? $atc['authorization_expires_at']
    : date('Y-m-d', strtotime($authStart . ' +1 year'));
$period = 'for the period ' . date('d/m/Y', strtotime($authStart))
        . ' to ' . date('d/m/Y', strtotime($authEnd));

// ── Template path ─────────────────────────────────────────────────────────────
$templatePath = __DIR__ . '/../assets/templates/giit_auth_certificate.pdf';
if (!file_exists($templatePath)) {
    die('<b>Template not found:</b> ' . htmlspecialchars($templatePath));
}

// ── Generate PDF ──────────────────────────────────────────────────────────────
try {
    $pdf = new Fpdi();

    // Import template page
    $pdf->setSourceFile($templatePath);
    $tplId = $pdf->importPage(1);
    $size  = $pdf->getTemplateSize($tplId);

    $W = $size['width'];    // 195.2 mm
    $H = $size['height'];   // 274.8 mm

    $pdf->AddPage($W > $H ? 'L' : 'P', [$W, $H]);
    $pdf->useTemplate($tplId, 0, 0, $W, $H);

    // ── Text rendering helper ─────────────────────────────────────────────────
    // Writes centered text at Y. color = 'r,g,b'
    $put = function (string $text, float $y, float $size, string $style = 'B', string $color = '0,0,0') use ($pdf, $W) {
        [$r, $g, $b] = array_map('intval', explode(',', $color));
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetFont('Times', $style, $size);
        $pdf->SetXY(0, $y);
        $pdf->Cell($W, 0, $text, 0, 0, 'C');
    };

    // ── Coordinate map (mm) for 195.2 × 274.8 template ──────────────────────
    //
    // Y positions determined by visual proportion of the template image.
    // Template has approx. header occupying top ~42% (~115mm).
    // Dynamic content fills the lower white body section:
    //
    //  Y=128 → "Authorization / Certificate" ribbon (template graphic, skip)
    //  Y=140 → ATC Name (large, bold-italic, dark blue)
    //  Y=154 → "Centre registration code Gyanam ATC-XXXXX"
    //  Y=161 → "Has been recognized as our Authorized Training Centre for"
    //  Y=168 → "Conducting our [course type]"
    //  Y=175 → "at [City], Dist. [District]."
    //  Y=182 → "for the period DD/MM/YYYY to DD/MM/YYYY"

    // ATC Name — large, bold-italic, navy blue
    $put($atcName, 140, 18, 'BI', '0,0,128');

    // Registration code line
    $put('Centre registration code ' . $atcCode, 155, 11, 'B', '30,30,30');

    // "Has been recognized..." line
    $put('Has been recognized as our Authorized Training Centre for', 164, 11, 'B', '30,30,30');

    // Course type line
    $put($conductingLine, 172, 11, 'B', '30,30,30');

    // Location
    $put($location, 180, 11, 'B', '30,30,30');

    // Period
    $put($period, 188, 11, 'B', '30,30,30');

    // ── Output ────────────────────────────────────────────────────────────────
    $inline   = isset($_GET['preview']);
    $dest     = $inline ? 'I' : 'D';
    $filename = 'AuthCert_' . preg_replace('/[^A-Za-z0-9]/', '_', $atcCode) . '.pdf';

    // Suppress any accidental output before PDF headers
    ob_end_clean();
    $pdf->Output($dest, $filename);
    exit;

} catch (\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException $e) {
    // Most common issue: PDF is version 1.5+ with compressed xref
    http_response_code(500);
    echo '<h2>PDF Template Compatibility Issue</h2>';
    echo '<p>The certificate template uses a compressed format (PDF 1.5+) that FPDI cannot read without a paid extension.</p>';
    echo '<p><b>Fix:</b> Open <code>giit_auth_certificate.pdf</code> in Adobe Acrobat or any PDF editor and resave it as <b>PDF 1.4</b> (Acrobat 5 compatible).</p>';
    echo '<p><small>Technical: ' . htmlspecialchars($e->getMessage()) . '</small></p>';
} catch (\Exception $e) {
    http_response_code(500);
    echo '<h2>Certificate Generation Error</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
}
