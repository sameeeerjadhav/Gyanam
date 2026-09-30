<?php
/**
 * Course Certificates — one PDF with the completion certificate, then the marksheet.
 *
 * URL: generate_course_print_pack.php?reg_id=STUDENT_REG&preview=1
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
if (file_exists(__DIR__ . '/../includes/exam_integration.php')) {
    require_once __DIR__ . '/../includes/exam_integration.php';
}
requireLogin(['Admin', 'DLC']);

require_once __DIR__ . '/../assets/fpdi/fpdi_autoload.php';
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

$regId = trim((string)($_GET['reg_id'] ?? ''));
if ($regId === '') {
    http_response_code(400);
    die('<b>Error:</b> Missing <code>reg_id</code> parameter.');
}

$_GET['reg_id'] = $regId;
if (!isset($_GET['preview'])) {
    $_GET['preview'] = '1';
}
$GLOBALS['GYANAM_CAPTURE_PDF'] = true;

$takePdf = static function (): string {
    $bytes = $GLOBALS['GYANAM_CAPTURED_PDF'] ?? null;
    if (!is_string($bytes) || strncmp($bytes, '%PDF', 4) !== 0) {
        if (!headers_sent()) {
            http_response_code(500);
            echo '<b>Error:</b> Could not build the certificate and marksheet.';
        }
        exit;
    }
    return $bytes;
};

// Included at file scope so a return inside each generator ends only that include.
$GLOBALS['GYANAM_CAPTURED_PDF'] = null;
include __DIR__ . '/generate_course_certificate.php';
$certBytes = $takePdf();

$GLOBALS['GYANAM_CAPTURED_PDF'] = null;
include __DIR__ . '/generate_marksheet.php';
$marksBytes = $takePdf();

try {
    $merged = new Fpdi();
    $merged->SetTitle('Certificate and Marksheet');
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
    $filename = 'Certificate_Marksheet_' . ($safeReg !== '' ? $safeReg : 'Student') . '.pdf';

    if (ob_get_level()) {
        ob_end_clean();
    }
    $merged->Output('I', $filename);
    exit;
} catch (Exception $e) {
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<h2>Print Error</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
}
