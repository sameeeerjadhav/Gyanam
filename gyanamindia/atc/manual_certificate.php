<?php
/**
 * Former ATC Manual Certificate page — disabled.
 * Manual certificates are Admin-only: admin/manual_certificate.php
 */
require_once __DIR__ . '/../includes/auth.php';
requireLogin(['ATC CENTER', 'Admin']);
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manual Certificate unavailable</title>
    <style>
        body{font-family:system-ui,sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
        .box{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:2rem;max-width:440px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.06)}
        h1{font-size:1.15rem;margin:0 0 .5rem;color:#0f172a}
        p{color:#64748b;font-size:.9rem;line-height:1.5;margin:0}
    </style>
</head>
<body>
<div class="box">
    <h1>Manual Certificate is Admin-only</h1>
    <p>This tool is no longer available in the ATC portal. Please contact Head Office / Admin to issue a manual certificate.</p>
</div>
</body>
</html>
