<?php
/**
 * Public certificate verification page (no login).
 * URL: /verify_certificate.php?t={token}
 * Also supports ?cert_no=GIIT2026-1
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = getDBConnection();
$token = trim((string)($_GET['t'] ?? ''));
$certNoQ = trim((string)($_GET['cert_no'] ?? ''));

$record = null;
$lookupMode = '';
if ($token !== '') {
    $record = findIssuedCertificateByToken($pdo, $token);
    $lookupMode = 'token';
} elseif ($certNoQ !== '') {
    $record = findIssuedCertificateByCertNo($pdo, $certNoQ);
    $lookupMode = 'cert_no';
}

$verified = is_array($record) && !empty($record['id']);
$pageTitle = $verified ? 'Verified Certificate' : 'Certificate Verification';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — Gyanam India</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>✅</text></svg>">
    <style>
        :root {
            --bg: #f1f5f9;
            --card: #fff;
            --text: #0f172a;
            --muted: #64748b;
            --ok: #15803d;
            --ok-bg: #dcfce7;
            --ok-border: #86efac;
            --bad: #b91c1c;
            --bad-bg: #fee2e2;
            --bad-border: #fca5a5;
            --line: #e2e8f0;
            --accent: #1d4ed8;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh;
            font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
            background:
                radial-gradient(ellipse 80% 50% at 50% -20%, #dbeafe, transparent),
                var(--bg);
            color: var(--text);
            padding: 1.25rem 1rem 2.5rem;
        }
        .wrap { max-width: 520px; margin: 0 auto; }
        .brand {
            text-align: center; margin-bottom: 1.15rem;
        }
        .brand .logo {
            font-size: 1.15rem; font-weight: 800; letter-spacing: .02em;
            color: #1e3a8a;
        }
        .brand .sub { font-size: .8rem; color: var(--muted); font-weight: 600; margin-top: .2rem; }
        .banner {
            border-radius: 14px; padding: .9rem 1rem; font-weight: 800;
            display: flex; align-items: center; gap: .65rem; margin-bottom: 1rem;
            border: 1.5px solid;
        }
        .banner.ok { background: var(--ok-bg); color: var(--ok); border-color: var(--ok-border); }
        .banner.bad { background: var(--bad-bg); color: var(--bad); border-color: var(--bad-border); }
        .banner svg { width: 22px; height: 22px; flex-shrink: 0; }
        .card {
            background: var(--card); border: 1.5px solid var(--line);
            border-radius: 16px; padding: 1.15rem 1.2rem;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .06);
        }
        .card h1 {
            margin: 0 0 .85rem; font-size: 1.05rem; font-weight: 800;
        }
        .row {
            display: grid; grid-template-columns: 38% 1fr;
            gap: .35rem .75rem; padding: .55rem 0;
            border-bottom: 1px solid var(--line);
            font-size: .9rem;
        }
        .row:last-child { border-bottom: none; }
        .row .k { color: var(--muted); font-weight: 700; font-size: .78rem; text-transform: uppercase; letter-spacing: .03em; }
        .row .v { font-weight: 700; word-break: break-word; }
        .marks {
            margin-top: .9rem; background: #eff6ff; border: 1px solid #bfdbfe;
            border-radius: 12px; padding: .85rem 1rem;
            display: grid; grid-template-columns: 1fr 1fr; gap: .65rem;
        }
        .marks .m-k { font-size: .72rem; font-weight: 700; color: #1e40af; text-transform: uppercase; }
        .marks .m-v { font-size: 1.15rem; font-weight: 800; color: #1e3a8a; }
        .search {
            margin-top: 1.15rem; background: var(--card); border: 1.5px solid var(--line);
            border-radius: 14px; padding: 1rem 1.1rem;
        }
        .search label { display: block; font-size: .78rem; font-weight: 700; color: var(--muted); margin-bottom: .35rem; }
        .search-row { display: flex; gap: .5rem; }
        .search input {
            flex: 1; height: 42px; border: 1.5px solid var(--line); border-radius: 10px;
            padding: 0 .75rem; font-size: .9rem;
        }
        .search button {
            height: 42px; padding: 0 1rem; border: none; border-radius: 10px;
            background: var(--accent); color: #fff; font-weight: 800; cursor: pointer;
        }
        .foot {
            text-align: center; margin-top: 1.5rem; font-size: .75rem;
            color: var(--muted); font-weight: 600;
        }
        .empty { color: var(--muted); font-weight: 600; font-size: .9rem; line-height: 1.45; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <div class="logo">Gyanam India Educational Services</div>
        <div class="sub">Course Completion Certificate Verification</div>
    </div>

    <?php if ($token === '' && $certNoQ === ''): ?>
        <div class="banner bad">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span>No certificate reference provided</span>
        </div>
        <div class="card">
            <p class="empty">Scan the QR code printed on the certificate, or enter the certificate number below.</p>
        </div>
    <?php elseif ($verified): ?>
        <div class="banner ok">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span>Verified authentic certificate</span>
        </div>
        <div class="card">
            <h1><?= htmlspecialchars((string)$record['student_name']) ?></h1>
            <div class="row"><div class="k">Certificate No.</div><div class="v"><?= htmlspecialchars((string)$record['cert_no']) ?></div></div>
            <div class="row"><div class="k">Registration ID</div><div class="v"><?= htmlspecialchars((string)($record['reg_id'] ?: '—')) ?></div></div>
            <div class="row"><div class="k">Course</div><div class="v"><?= htmlspecialchars((string)$record['course']) ?></div></div>
            <div class="row"><div class="k">Conducted at (ATC)</div><div class="v"><?= htmlspecialchars(trim(($record['atc_name'] ?? '') . (!empty($record['atc_code']) ? ' (' . $record['atc_code'] . ')' : '')) ?: '—') ?></div></div>
            <div class="row"><div class="k">Duration</div><div class="v"><?= htmlspecialchars((string)($record['duration'] ?: '—')) ?></div></div>
            <div class="row"><div class="k">Date of issue</div><div class="v"><?= htmlspecialchars(date('d M Y', strtotime((string)$record['issue_date']))) ?></div></div>
            <div class="marks">
                <div>
                    <div class="m-k">Marks obtained</div>
                    <div class="m-v"><?= (int)$record['score'] ?> / 100</div>
                </div>
                <div>
                    <div class="m-k">Grade</div>
                    <div class="m-v"><?= htmlspecialchars((string)$record['grade']) ?></div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="banner bad">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            <span>Certificate not found or invalid</span>
        </div>
        <div class="card">
            <p class="empty">
                <?= $lookupMode === 'token'
                    ? 'This verification link is invalid or the certificate was never issued.'
                    : 'No issued certificate matches that number. Check the number printed on the certificate.' ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="search">
        <form method="get" action="">
            <label for="cert_no">Look up by certificate number</label>
            <div class="search-row">
                <input type="text" id="cert_no" name="cert_no" placeholder="e.g. GIIT2026-1"
                       value="<?= htmlspecialchars($certNoQ) ?>" autocomplete="off">
                <button type="submit">Verify</button>
            </div>
        </form>
    </div>

    <div class="foot">Gyanam India Educational Services · Official verification portal</div>
</div>
</body>
</html>
