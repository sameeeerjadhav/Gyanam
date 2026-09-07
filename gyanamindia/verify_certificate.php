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

$brandKey = ($verified && (($record['brand'] ?? '') === 'abacus')) ? 'abacus' : 'it';
$logoUrl = admissionFormBrandLogoUrl($brandKey, 'root');
$photoUrl = $verified ? issuedCertificatePhotoUrl($record, $pdo) : '';
$orgName = $brandKey === 'abacus'
    ? 'Gyanam Abacus'
    : 'Gyanam Institute of Information Technology (GIIT)';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0b1f4d">
    <title><?= htmlspecialchars($pageTitle) ?> — GIIT / Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,500;8..60,700;8..60,800&family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars($logoUrl) ?>">
    <style>
        :root {
            --navy: #0b1f4d;
            --navy-2: #143a7a;
            --red: #c00000;
            --bg: #e8eef8;
            --card: #ffffff;
            --text: #0f172a;
            --muted: #64748b;
            --line: #dbe3f0;
            --ok: #047857;
            --ok-bg: #ecfdf5;
            --ok-border: #6ee7b7;
            --bad: #b91c1c;
            --bad-bg: #fef2f2;
            --bad-border: #fca5a5;
            --shadow: 0 12px 40px rgba(11, 31, 77, .12);
            --radius: 18px;
            --safe-b: env(safe-area-inset-bottom, 0px);
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
            font-family: "DM Sans", system-ui, sans-serif;
            color: var(--text);
            background:
                radial-gradient(120% 80% at 50% -10%, #c7d7f5 0%, transparent 55%),
                linear-gradient(180deg, #f4f7fc 0%, var(--bg) 100%);
            padding: max(1rem, env(safe-area-inset-top)) 1rem calc(1.75rem + var(--safe-b));
        }
        .wrap {
            width: 100%;
            max-width: 440px;
            margin: 0 auto;
        }
        .brand {
            text-align: center;
            margin-bottom: 1rem;
        }
        .brand img {
            height: 64px;
            width: auto;
            max-width: min(220px, 70vw);
            object-fit: contain;
            display: inline-block;
        }
        .brand .org {
            margin-top: .55rem;
            font-family: "Source Serif 4", Georgia, serif;
            font-weight: 800;
            font-size: clamp(.95rem, 3.6vw, 1.05rem);
            color: var(--navy);
            line-height: 1.25;
        }
        .brand .sub {
            margin-top: .2rem;
            font-size: .78rem;
            font-weight: 600;
            color: var(--muted);
            letter-spacing: .02em;
        }
        .banner {
            display: flex;
            align-items: center;
            gap: .65rem;
            border-radius: 14px;
            padding: .85rem 1rem;
            font-weight: 800;
            font-size: .92rem;
            border: 1.5px solid;
            margin-bottom: .9rem;
        }
        .banner.ok { background: var(--ok-bg); color: var(--ok); border-color: var(--ok-border); }
        .banner.bad { background: var(--bad-bg); color: var(--bad); border-color: var(--bad-border); }
        .banner svg { width: 22px; height: 22px; flex-shrink: 0; }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        .card-top {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
            padding: 1.15rem 1.15rem 1rem;
            background: linear-gradient(180deg, #f8fbff 0%, #fff 100%);
            border-bottom: 1px solid var(--line);
        }
        .photo {
            width: 88px;
            height: 108px;
            border-radius: 12px;
            object-fit: cover;
            border: 2px solid #e2e8f0;
            background: #f1f5f9;
            flex-shrink: 0;
            box-shadow: 0 4px 14px rgba(15, 23, 42, .08);
        }
        .photo.ph {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .68rem;
            font-weight: 700;
            color: #94a3b8;
            text-align: center;
            padding: .4rem;
        }
        .who { min-width: 0; flex: 1; padding-top: .15rem; }
        .who h1 {
            margin: 0;
            font-family: "Source Serif 4", Georgia, serif;
            font-size: clamp(1.05rem, 4.2vw, 1.28rem);
            font-weight: 800;
            color: var(--red);
            line-height: 1.25;
            word-break: break-word;
        }
        .who .course {
            margin-top: .45rem;
            font-size: .88rem;
            font-weight: 700;
            color: var(--navy);
            line-height: 1.35;
        }
        .who .chip {
            display: inline-flex;
            margin-top: .65rem;
            padding: .28rem .6rem;
            border-radius: 999px;
            background: #eff6ff;
            color: var(--navy-2);
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .02em;
        }
        .rows { padding: .35rem 1.15rem 1rem; }
        .row {
            display: grid;
            grid-template-columns: minmax(96px, 36%) 1fr;
            gap: .35rem .75rem;
            padding: .7rem 0;
            border-bottom: 1px solid var(--line);
            font-size: .9rem;
        }
        .row:last-of-type { border-bottom: none; }
        .row .k {
            color: var(--muted);
            font-weight: 700;
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding-top: .15rem;
        }
        .row .v {
            font-weight: 700;
            color: var(--text);
            word-break: break-word;
            line-height: 1.35;
        }
        .marks {
            margin: 0 1.15rem 1.15rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .65rem;
        }
        .mark {
            background: linear-gradient(145deg, #eff6ff, #f8fafc);
            border: 1px solid #bfdbfe;
            border-radius: 14px;
            padding: .85rem .9rem;
            text-align: center;
        }
        .mark .m-k {
            font-size: .68rem;
            font-weight: 800;
            color: #1e40af;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .mark .m-v {
            margin-top: .3rem;
            font-family: "Source Serif 4", Georgia, serif;
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--navy);
        }
        .search {
            margin-top: 1rem;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 1rem 1.1rem;
            box-shadow: 0 4px 18px rgba(15, 23, 42, .05);
        }
        .search label {
            display: block;
            font-size: .75rem;
            font-weight: 700;
            color: var(--muted);
            margin-bottom: .4rem;
        }
        .search-row { display: flex; gap: .5rem; }
        .search input {
            flex: 1;
            min-width: 0;
            height: 46px;
            border: 1.5px solid var(--line);
            border-radius: 12px;
            padding: 0 .85rem;
            font-size: 16px; /* prevents iOS zoom */
            font-family: inherit;
            background: #fff;
        }
        .search input:focus {
            outline: none;
            border-color: #60a5fa;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .15);
        }
        .search button {
            height: 46px;
            padding: 0 1.05rem;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--navy), var(--navy-2));
            color: #fff;
            font-weight: 800;
            font-size: .88rem;
            font-family: inherit;
            cursor: pointer;
            white-space: nowrap;
        }
        .foot {
            text-align: center;
            margin-top: 1.35rem;
            font-size: .72rem;
            color: var(--muted);
            font-weight: 600;
            line-height: 1.45;
        }
        .empty {
            margin: 0;
            padding: 1.15rem;
            color: var(--muted);
            font-weight: 600;
            font-size: .9rem;
            line-height: 1.5;
        }
        @media (min-width: 480px) {
            .brand img { height: 72px; }
            .photo { width: 96px; height: 118px; }
        }
        @media (max-width: 360px) {
            .card-top { flex-direction: column; align-items: center; text-align: center; }
            .who .chip { margin-left: auto; margin-right: auto; }
            .row { grid-template-columns: 1fr; gap: .2rem; }
            .search-row { flex-direction: column; }
            .search button { width: 100%; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <header class="brand">
        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars($orgName) ?>">
        <div class="org"><?= htmlspecialchars($orgName) ?></div>
        <div class="sub">Official Certificate Verification</div>
    </header>

    <?php if ($token === '' && $certNoQ === ''): ?>
        <div class="banner bad">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span>No certificate reference provided</span>
        </div>
        <div class="card"><p class="empty">Scan the QR code on the certificate, or enter the certificate number below.</p></div>
    <?php elseif ($verified): ?>
        <div class="banner ok">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span>Verified authentic certificate</span>
        </div>
        <article class="card">
            <div class="card-top">
                <?php if ($photoUrl !== ''): ?>
                    <img class="photo" src="<?= htmlspecialchars($photoUrl) ?>" alt="Student photo" width="96" height="118">
                <?php else: ?>
                    <div class="photo ph">No photo<br>on file</div>
                <?php endif; ?>
                <div class="who">
                    <h1><?= htmlspecialchars((string)$record['student_name']) ?></h1>
                    <div class="course"><?= htmlspecialchars((string)$record['course']) ?></div>
                    <span class="chip"><?= htmlspecialchars((string)$record['cert_no']) ?></span>
                </div>
            </div>
            <div class="rows">
                <div class="row"><div class="k">Registration ID</div><div class="v"><?= htmlspecialchars((string)($record['reg_id'] ?: '—')) ?></div></div>
                <div class="row"><div class="k">Conducted at</div><div class="v"><?= htmlspecialchars(trim(($record['atc_name'] ?? '') . (!empty($record['atc_code']) ? ' (' . $record['atc_code'] . ')' : '')) ?: '—') ?></div></div>
                <div class="row"><div class="k">Duration</div><div class="v"><?= htmlspecialchars((string)($record['duration'] ?: '—')) ?></div></div>
                <div class="row"><div class="k">Date of issue</div><div class="v"><?= htmlspecialchars(date('d M Y', strtotime((string)$record['issue_date']))) ?></div></div>
            </div>
            <div class="marks">
                <div class="mark">
                    <div class="m-k">Marks obtained</div>
                    <div class="m-v"><?= (int)$record['score'] ?>/100</div>
                </div>
                <div class="mark">
                    <div class="m-k">Grade</div>
                    <div class="m-v"><?= htmlspecialchars((string)$record['grade']) ?></div>
                </div>
            </div>
        </article>
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
                       value="<?= htmlspecialchars($certNoQ) ?>" autocomplete="off" inputmode="text">
                <button type="submit">Verify</button>
            </div>
        </form>
    </div>

    <footer class="foot">
        Gyanam India Educational Services<br>
        Official verification portal
    </footer>
</div>
</body>
</html>
