<?php
/**
 * Manual autoloader for FPDF + FPDI (no Composer required)
 */

$fpdfFile = __DIR__ . '/fpdf/fpdf.php';
if (!class_exists('FPDF') && file_exists($fpdfFile)) {
    require_once $fpdfFile;
}

spl_autoload_register(function (string $class) {
    // Map Setasign\Fpdi namespace → src/
    $prefix = 'setasign\\Fpdi\\';
    $baseDir = __DIR__ . '/src/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
