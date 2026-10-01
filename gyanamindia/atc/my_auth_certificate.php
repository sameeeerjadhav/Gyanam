<?php
/**
 * Gyanam Portal — ATC: Authorization Certificates page
 * Shows Abacus/Vedic and/or IT certificates based on center_type.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$atcId = (int)($_SESSION['atc_id'] ?? 0);

if (!$atcId) {
    die('Session error: ATC ID not found.');
}

$stmt = $pdo->prepare("SELECT id, name, atc_code, center_type, district, city, taluka, state, date_created, authorization_expires_at FROM atc_centers WHERE id = ? LIMIT 1");
$stmt->execute([$atcId]);
$atc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$atc) {
    die('ATC Center not found.');
}

$centerType = (string)($atc['center_type'] ?? '');
$variants = atcAuthCertificateVariants($centerType);

$authStart = !empty($atc['date_created']) ? $atc['date_created'] : date('Y-m-d');
$authEnd = !empty($atc['authorization_expires_at'])
    ? $atc['authorization_expires_at']
    : date('Y-m-d', strtotime($authStart . ' +1 year'));
$authCode = trim((string)($atc['atc_code'] ?? ''));
if ($authCode === '') {
    $authCode = date('Y') . str_pad((string)$atcId, 5, '0', STR_PAD_LEFT);
}
$placeParts = array_filter([
    trim((string)($atc['city'] ?? '')),
    trim((string)($atc['taluka'] ?? '')),
    trim((string)($atc['district'] ?? '')),
    trim((string)($atc['state'] ?? '')),
]);
$place = implode(', ', array_unique($placeParts));
$daysLeft = (int)floor((strtotime(date('Y-m-d', strtotime($authEnd))) - strtotime(date('Y-m-d'))) / 86400);
$authLive = $daysLeft >= 0;

foreach ($variants as &$v) {
    $v['template_ok'] = atcAuthCertificateTemplatePath($v['variant']) !== null;
    $v['preview_url'] = '../admin/generate_auth_certificate.php?atc_id=' . $atcId . '&variant=' . urlencode($v['variant']) . '&preview=1';
    $v['download_url'] = '../admin/generate_auth_certificate.php?atc_id=' . $atcId . '&variant=' . urlencode($v['variant']);
}
unset($v);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auth Certificate — ATC Center | Gyanam India</title>
    <?php include __DIR__ . '/../includes/head_fonts.php'; ?>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%234f46e5' stroke-width='2'%3E%3Ccircle cx='12' cy='8' r='5'/%3E%3Cpath d='M8.5 13.5 7 22l5-2 5 2-1.5-8.5'/%3E%3C/svg%3E">
    <style>
        .ac-page { display: flex; flex-direction: column; gap: 1.15rem; }
        .ac-summary {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 1.25rem 1.35rem 1.1rem;
            box-shadow: 0 1px 3px rgba(15,23,42,.05);
        }
        .ac-summary-top { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; flex-wrap: wrap; }
        .ac-kicker { margin: 0 0 .25rem; font-size: .72rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: #6366f1; }
        .ac-summary h1 { margin: 0; font-size: 1.35rem; font-weight: 800; color: #0f172a; }
        .ac-place { margin: .3rem 0 0; color: #64748b; font-size: .86rem; font-weight: 600; }
        .ac-live {
            display: inline-flex; align-items: center; gap: .4rem;
            height: 32px; padding: 0 .75rem; border-radius: 999px;
            font-size: .75rem; font-weight: 800; white-space: nowrap;
        }
        .ac-live.on { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .ac-live.off { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .ac-live i { width: 7px; height: 7px; border-radius: 50%; background: currentColor; display: inline-block; }
        .ac-facts {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: .75rem;
            margin-top: 1.1rem;
        }
        .ac-fact {
            background: #f8fafc;
            border: 1px solid #eef2f7;
            border-radius: 12px;
            padding: .7rem .85rem;
        }
        .ac-fact span { display: block; font-size: .68rem; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; color: #94a3b8; margin-bottom: .2rem; }
        .ac-fact strong { display: block; font-size: .92rem; font-weight: 800; color: #0f172a; line-height: 1.35; }
        .ac-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1rem; }
        .ac-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 8px 24px rgba(15,23,42,.04);
        }
        .ac-band { height: 6px; }
        .ac-card.abacus .ac-band { background: linear-gradient(90deg, #6d28d9, #c4b5fd); }
        .ac-card.it .ac-band { background: linear-gradient(90deg, #1d4ed8, #7dd3fc); }
        .ac-body { padding: 1.15rem 1.2rem 1.2rem; display: flex; flex-direction: column; gap: .85rem; flex: 1; }
        .ac-head { display: flex; gap: .85rem; align-items: flex-start; }
        .ac-mark {
            width: 52px; height: 52px; border-radius: 14px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 800; font-size: .78rem; letter-spacing: .03em;
        }
        .ac-card.abacus .ac-mark { background: linear-gradient(145deg, #6d28d9, #a78bfa); }
        .ac-card.it .ac-mark { background: linear-gradient(145deg, #1d4ed8, #38bdf8); }
        .ac-head h3 { margin: 0; font-size: 1.02rem; font-weight: 800; color: #0f172a; line-height: 1.3; }
        .ac-brand { margin-top: .2rem; font-size: .78rem; font-weight: 700; color: #64748b; }
        .ac-line { margin: 0; font-size: .88rem; font-weight: 600; color: #334155; line-height: 1.45; }
        .ac-window {
            display: flex; justify-content: space-between; gap: .75rem; flex-wrap: wrap;
            background: #f8fafc; border-radius: 12px; padding: .7rem .8rem;
            font-size: .78rem; color: #475569; font-weight: 700;
        }
        .ac-window b { color: #0f172a; }
        .ac-actions { display: flex; gap: .5rem; margin-top: auto; }
        .ac-btn {
            flex: 1; height: 40px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
            text-decoration: none; font-size: .82rem; font-weight: 800; border: 1.5px solid transparent;
        }
        .ac-btn svg { width: 15px; height: 15px; }
        .ac-btn.primary { background: #1e3a8a; color: #fff; }
        .ac-btn.primary:hover { background: #1e40af; }
        .ac-btn.ghost { background: #fff; color: #1e3a8a; border-color: #c7d2fe; }
        .ac-btn.ghost:hover { background: #eef2ff; }
        .ac-warn {
            font-size: .78rem; font-weight: 600; color: #9a3412; background: #fff7ed;
            border: 1px solid #fed7aa; border-radius: 10px; padding: .6rem .7rem;
        }
        .ac-empty {
            padding: 2.5rem 1.5rem; text-align: center; color: #64748b; font-weight: 600;
            background: #fff; border: 1.5px dashed #e2e8f0; border-radius: 16px;
        }
        @media (max-width: 900px) {
            .ac-facts { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 560px) {
            .ac-facts, .ac-actions { grid-template-columns: 1fr; }
            .ac-actions { flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <div class="header-greeting">
                    <h2>Auth Certificate</h2>
                    <p>Download your center authorization certificate(s)</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content ac-page">
            <section class="ac-summary">
                <div class="ac-summary-top">
                    <div>
                        <p class="ac-kicker">Authorized Centre</p>
                        <h1><?= htmlspecialchars($atc['name'] ?? 'ATC Center') ?></h1>
                        <?php if ($place !== ''): ?>
                            <p class="ac-place"><?= htmlspecialchars($place) ?></p>
                        <?php endif; ?>
                    </div>
                    <span class="ac-live <?= $authLive ? 'on' : 'off' ?>"><i></i><?= $authLive ? 'Authorization Active' : 'Authorization Expired' ?></span>
                </div>
                <div class="ac-facts">
                    <div class="ac-fact"><span>Centre Code</span><strong><?= htmlspecialchars($authCode) ?></strong></div>
                    <div class="ac-fact"><span>Centre Type</span><strong><?= htmlspecialchars($centerType !== '' ? $centerType : '—') ?></strong></div>
                    <div class="ac-fact"><span>Valid From</span><strong><?= htmlspecialchars(date('d M Y', strtotime($authStart))) ?></strong></div>
                    <div class="ac-fact"><span>Valid Until</span><strong><?= htmlspecialchars(date('d M Y', strtotime($authEnd))) ?></strong></div>
                </div>
            </section>

            <?php if (empty($variants)): ?>
                <div class="ac-empty">No authorization certificates are set for this centre type.</div>
            <?php else: ?>
                <div class="ac-grid">
                    <?php foreach ($variants as $v): ?>
                        <article class="ac-card <?= htmlspecialchars($v['variant']) ?>">
                            <div class="ac-band"></div>
                            <div class="ac-body">
                                <div class="ac-head">
                                    <div class="ac-mark"><?= $v['variant'] === 'it' ? 'GIIT' : 'GYA' ?></div>
                                    <div>
                                        <h3><?= htmlspecialchars($v['label']) ?></h3>
                                        <div class="ac-brand"><?= htmlspecialchars($v['brand']) ?></div>
                                    </div>
                                </div>
                                <p class="ac-line"><?= htmlspecialchars($v['course_line']) ?></p>
                                <div class="ac-window">
                                    <span>Valid <b><?= htmlspecialchars(date('d M Y', strtotime($authStart))) ?></b></span>
                                    <span>to <b><?= htmlspecialchars(date('d M Y', strtotime($authEnd))) ?></b></span>
                                </div>
                                <?php if (!$v['template_ok']): ?>
                                    <div class="ac-warn">The certificate file is not uploaded yet. Contact Head Office.</div>
                                <?php endif; ?>
                                <div class="ac-actions">
                                    <?php if ($v['template_ok']): ?>
                                        <a class="ac-btn primary" href="<?= htmlspecialchars($v['download_url']) ?>" target="_blank" rel="noopener">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
                                            Download PDF
                                        </a>
                                        <a class="ac-btn ghost" href="<?= htmlspecialchars($v['preview_url']) ?>" target="_blank" rel="noopener">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                                            Preview
                                        </a>
                                    <?php else: ?>
                                        <span class="ac-btn ghost" style="opacity:.55;cursor:not-allowed">Unavailable</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>
