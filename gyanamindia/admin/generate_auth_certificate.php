<?php
/**
 * Gyanam India — ATC Authorization Certificate Generator
 * Uses FPDI to overlay dynamic text on the PDF template.
 *
 * URL: admin/generate_auth_certificate.php?atc_id=XX
 *      admin/generate_auth_certificate.php?atc_id=XX&variant=it|abacus
 *      admin/generate_auth_certificate.php?atc_id=XX&variant=abacus&preview=1
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin(['Admin', 'ATC CENTER', 'DLC Office']);

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
$sessionRole  = getUserRole() ?? '';
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

$allowed = atcAuthCertificateVariants($atc['center_type'] ?? '');
$variantReq = strtolower(trim((string)($_GET['variant'] ?? '')));
if ($variantReq === '' || $variantReq === 'auto') {
    // Prefer single match; if both exist and none specified, use first
    $chosen = $allowed[0];
} else {
    $chosen = null;
    foreach ($allowed as $v) {
        if ($v['variant'] === $variantReq) {
            $chosen = $v;
            break;
        }
    }
    if (!$chosen) {
        http_response_code(400);
        die('This ATC center type does not include the requested certificate variant (' . htmlspecialchars($variantReq) . ').');
    }
}

$variant = $chosen['variant'];
$conductingLine = $chosen['course_line'];
$codePrefix = $chosen['code_prefix'];

// ── Build dynamic values ──────────────────────────────────────────────────────
$atcName = trim($atc['name'] ?? 'N/A');

$rawCode = !empty($atc['atc_code'])
    ? (string)$atc['atc_code']
    : (date('Y') . str_pad((string)$atcId, 5, '0', STR_PAD_LEFT));
$atcCode = $codePrefix . $rawCode;

$city     = trim($atc['city'] ?? $atc['taluka'] ?? '');
$district = trim($atc['district'] ?? '');
$location = $city ? "at $city, Dist. $district." : "at Dist. $district.";

$authStart = !empty($atc['date_created'])
    ? $atc['date_created']
    : date('Y-m-d');
$authEnd = !empty($atc['authorization_expires_at'])
    ? $atc['authorization_expires_at']
    : date('Y-m-d', strtotime($authStart . ' +1 year'));
$period = 'for the period ' . date('d/m/Y', strtotime($authStart))
        . ' to ' . date('d/m/Y', strtotime($authEnd));

// ── Template path ─────────────────────────────────────────────────────────────
$templatePath = atcAuthCertificateTemplatePath($variant);
if (!$templatePath) {
    $needed = $variant === 'abacus'
        ? 'assets/templates/gyanam_abacus_auth_certificate.pdf'
        : 'assets/templates/giit_auth_certificate.pdf';
    http_response_code(500);
    echo '<h2>Certificate template missing</h2>';
    echo '<p>Upload the <b>' . htmlspecialchars($chosen['brand']) . '</b> authorization PDF template to:</p>';
    echo '<p><code>' . htmlspecialchars($needed) . '</code></p>';
    echo '<p>Then try again.</p>';
    exit;
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

    $put = function (string $text, float $y, float $size, string $style = 'B', string $color = '0,0,0') use ($pdf, $W) {
        [$r, $g, $b] = array_map('intval', explode(',', $color));
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetFont('Times', $style, $size);
        $pdf->SetXY(0, $y);
        $pdf->Cell($W, 0, $text, 0, 0, 'C');
    };

    $put($atcName, 140, 18, 'BI', '0,0,128');
    $put('Centre registration code ' . $atcCode, 155, 11, 'B', '30,30,30');
    $put('Has been recognized as our Authorized Training Centre for', 164, 11, 'B', '30,30,30');
    $put($conductingLine, 172, 11, 'B', '30,30,30');
    $put($location, 180, 11, 'B', '30,30,30');
    $put($period, 188, 11, 'B', '30,30,30');

    $inline   = isset($_GET['preview']);
    $dest     = $inline ? 'I' : 'D';
    $filename = 'AuthCert_' . $variant . '_' . preg_replace('/[^A-Za-z0-9]/', '_', $rawCode) . '.pdf';

    if (ob_get_level()) {
        ob_end_clean();
    }
    $pdf->Output($dest, $filename);
    exit;

} catch (\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException $e) {
    http_response_code(500);
    echo '<h2>PDF Template Compatibility Issue</h2>';
    echo '<p>The certificate template uses a compressed format (PDF 1.5+) that FPDI cannot read without a paid extension.</p>';
    echo '<p><b>Fix:</b> Resave the template as <b>PDF 1.4</b> (Acrobat 5 compatible).</p>';
    echo '<p><small>Technical: ' . htmlspecialchars($e->getMessage()) . '</small></p>';
} catch (\Exception $e) {
    http_response_code(500);
    echo '<h2>Certificate Generation Error</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
}
