<?php
/**
 * Autoload for the question-bank PDF library (mPDF and its small dependencies).
 */

spl_autoload_register(static function (string $class): void {
    $map = [
        'Mpdf\\PsrLogAwareTrait\\' => __DIR__ . '/vendor/psr-log-aware-trait/src/',
        'Mpdf\\PsrHttpMessageShim\\' => __DIR__ . '/vendor/psr-http-message-shim/src/',
        'Mpdf\\' => __DIR__ . '/mpdf/src/',
        'DeepCopy\\' => __DIR__ . '/vendor/deepcopy/src/DeepCopy/',
        'Psr\\Log\\' => __DIR__ . '/vendor/psr-log/src/',
        'Psr\\Http\\Message\\' => __DIR__ . '/vendor/http-message/src/',
        'setasign\\Fpdi\\' => __DIR__ . '/vendor/fpdi/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }
        $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
        return;
    }
});

require_once __DIR__ . '/mpdf/src/functions.php';
