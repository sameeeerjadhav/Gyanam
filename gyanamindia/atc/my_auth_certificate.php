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
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🏆</text></svg>">
    <style>
        .auth-hero { margin-bottom: 1.25rem; }
        .auth-hero h1 { font-size: 1.35rem; font-weight: 800; margin: 0 0 .35rem; color: var(--text-primary); }
        .auth-hero p { margin: 0; color: var(--text-secondary); font-size: .9rem; font-weight: 500; }
        .auth-meta {
            display: flex; flex-wrap: wrap; gap: .5rem; margin: 1rem 0 1.5rem;
        }
        .auth-chip {
            display: inline-flex; align-items: center; gap: .35rem;
            padding: .35rem .7rem; border-radius: 999px; font-size: .75rem; font-weight: 700;
            background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0;
        }
        .auth-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.15rem;
        }
        .auth-card {
            background: #fff; border: 1.5px solid var(--border-color); border-radius: 16px;
            padding: 1.25rem; display: flex; flex-direction: column; gap: .85rem;
            box-shadow: 0 2px 10px rgba(15,23,42,.04);
        }
        .auth-card.abacus { border-top: 4px solid #7c3aed; }
        .auth-card.it { border-top: 4px solid #2563eb; }
        .auth-card-top { display: flex; gap: .85rem; align-items: flex-start; }
        .auth-badge {
            width: 48px; height: 48px; border-radius: 12px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-weight: 900; font-size: .78rem; color: #fff; letter-spacing: .02em;
        }
        .auth-badge.abacus { background: linear-gradient(135deg,#7c3aed,#a855f7); }
        .auth-badge.it { background: linear-gradient(135deg,#2563eb,#38bdf8); }
        .auth-card h3 { margin: 0; font-size: .98rem; font-weight: 800; color: var(--text-primary); }
        .auth-card .brand { font-size: .78rem; font-weight: 700; color: var(--text-secondary); margin-top: .15rem; }
        .auth-card .course { font-size: .82rem; color: #475569; font-weight: 600; line-height: 1.4; }
        .auth-actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: auto; }
        .auth-btn {
            display: inline-flex; align-items: center; gap: .35rem; text-decoration: none;
            padding: .55rem .9rem; border-radius: 10px; font-size: .82rem; font-weight: 800;
            border: 1.5px solid transparent; cursor: pointer;
        }
        .auth-btn.primary { background: linear-gradient(135deg,#4361ee,#7c3aed); color: #fff; }
        .auth-btn.secondary { background: #fff; color: #334155; border-color: #e2e8f0; }
        .auth-btn:hover { filter: brightness(1.03); }
        .auth-warn {
            font-size: .78rem; font-weight: 600; color: #9a3412; background: #fff7ed;
            border: 1px solid #fed7aa; border-radius: 10px; padding: .55rem .7rem;
        }
        .auth-empty {
            padding: 2rem; text-align: center; color: var(--text-secondary); font-weight: 600;
            background: #fff; border: 1.5px dashed #e2e8f0; border-radius: 14px;
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

        <div class="page-content">
            <div class="auth-hero">
                <h1><?= htmlspecialchars($atc['name'] ?? 'ATC Center') ?></h1>
                <p>Certificates available for your center type are listed below.</p>
            </div>

            <div class="auth-meta">
                <span class="auth-chip">Type: <?= htmlspecialchars($centerType !== '' ? $centerType : '—') ?></span>
                <span class="auth-chip">Code: <?= htmlspecialchars($atc['atc_code'] ?: (date('Y') . str_pad((string)$atcId, 5, '0', STR_PAD_LEFT))) ?></span>
                <span class="auth-chip">Valid: <?= htmlspecialchars(date('d M Y', strtotime($authStart))) ?> → <?= htmlspecialchars(date('d M Y', strtotime($authEnd))) ?></span>
            </div>

            <?php if (empty($variants)): ?>
                <div class="auth-empty">No authorization certificates configured for this center type.</div>
            <?php else: ?>
                <div class="auth-grid">
                    <?php foreach ($variants as $v): ?>
                        <div class="auth-card <?= htmlspecialchars($v['variant']) ?>">
                            <div class="auth-card-top">
                                <div class="auth-badge <?= htmlspecialchars($v['variant']) ?>">
                                    <?= $v['variant'] === 'it' ? 'GIIT' : 'GYA' ?>
                                </div>
                                <div>
                                    <h3><?= htmlspecialchars($v['label']) ?></h3>
                                    <div class="brand"><?= htmlspecialchars($v['brand']) ?> logo template</div>
                                </div>
                            </div>
                            <div class="course"><?= htmlspecialchars($v['course_line']) ?></div>

                            <?php if (!$v['template_ok']): ?>
                                <div class="auth-warn">
                                    PDF template not uploaded yet
                                    (<?= $v['variant'] === 'abacus' ? 'gyanam_abacus_auth_certificate.pdf' : 'giit_auth_certificate.pdf' ?>).
                                    Contact Head Office.
                                </div>
                            <?php endif; ?>

                            <div class="auth-actions">
                                <?php if ($v['template_ok']): ?>
                                    <a class="auth-btn primary" href="<?= htmlspecialchars($v['download_url']) ?>" target="_blank" rel="noopener">Download PDF</a>
                                    <a class="auth-btn secondary" href="<?= htmlspecialchars($v['preview_url']) ?>" target="_blank" rel="noopener">Preview</a>
                                <?php else: ?>
                                    <span class="auth-btn secondary" style="opacity:.55;cursor:not-allowed">Unavailable</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>
