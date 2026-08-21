<?php
/**
 * Coordinate calibration tool — outputs a test PDF with numbered grid lines
 * to precisely locate where text should go on the auth certificate template.
 *
 * Visit: admin/cert_calibrate.php (admin login required)
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin(['Admin']);

require_once __DIR__ . '/../assets/fpdi/fpdi_autoload.php';
use setasign\Fpdi\Fpdi;

$templatePath = __DIR__ . '/../assets/templates/giit_auth_certificate.pdf';

$pdf = new Fpdi();
$pageCount = $pdf->setSourceFile($templatePath);
$tplId = $pdf->importPage(1);
$size  = $pdf->getTemplateSize($tplId);

$W = $size['width'];   // 195.2
$H = $size['height'];  // 274.8

$pdf->AddPage($W > $H ? 'L' : 'P', [$W, $H]);
$pdf->useTemplate($tplId, 0, 0, $W, $H);

// Draw horizontal guide lines every 5mm with Y labels
$pdf->SetDrawColor(255, 0, 0);
$pdf->SetTextColor(255, 0, 0);
$pdf->SetFont('Helvetica', '', 5);
$pdf->SetLineWidth(0.1);

for ($y = 100; $y <= $H; $y += 5) {
    // Faint line
    $pdf->Line(0, $y, $W, $y);
    // Y label on left
    $pdf->SetXY(0, $y - 2);
    $pdf->Cell(10, 4, $y . 'mm', 0, 0, 'L');
}

// Sample text placeholders in red so we can see where they land
$pdf->SetFont('Helvetica', 'B', 14);
$pdf->SetTextColor(200, 0, 0);

$sampleLines = [
    [130, 'ATC Name: M/S. Sample GIIT Academy'],
    [148, 'Centre registration code Gyanam ATC-20260001'],
    [157, 'Has been recognized as our Authorized Training Centre for'],
    [164, 'Conducting our IT and Abacus Courses'],
    [172, 'at Jalgaon, Dist. Jalgaon.'],
    [180, 'for the period 01/05/2026 to 30/04/2027'],
];

foreach ($sampleLines as [$y, $text]) {
    $pdf->SetFont('Helvetica', 'B', $y === 130 ? 14 : 9);
    $pdf->SetXY(0, $y);
    $pdf->Cell($W, 0, $text, 0, 0, 'C');
}

$pdf->Output('I', 'calibrate_auth_cert.pdf');
