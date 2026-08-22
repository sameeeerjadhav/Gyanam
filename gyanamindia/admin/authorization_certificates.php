<?php
/**
 * Gyanam Portal — Admin: Authorization Certificates
 * Dedicated module to browse ATCs and print their authorization certificates.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
if (file_exists(__DIR__ . '/../includes/notifications.php'))
    require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);
$pdo = getDBConnection();

// Filters
$search    = trim($_GET['q']  ?? '');
$dlcFilter = intval($_GET['dlc'] ?? 0);
$expFilter = trim($_GET['exp'] ?? 'all');   // all | expiring | expired | valid

$where  = '1=1';
$params = [];

if ($search) {
    $where .= ' AND (a.name LIKE ? OR a.atc_code LIKE ? OR a.district LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($dlcFilter) {
    $where .= ' AND a.dlc_id = ?'; $params[] = $dlcFilter;
}
switch ($expFilter) {
    case 'expired':  $where .= ' AND a.authorization_expires_at < CURDATE()'; break;
    case 'expiring': $where .= ' AND a.authorization_expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)'; break;
    case 'valid':    $where .= ' AND a.authorization_expires_at > DATE_ADD(CURDATE(), INTERVAL 30 DAY)'; break;
}

$atcs = [];
try {
    $q = $pdo->prepare("
        SELECT a.id, a.atc_code, a.name, a.city, a.district, a.taluka, a.state,
               a.center_type, a.contact_person, a.mobile, a.status,
               a.authorization_expires_at,
               d.name AS dlc_name
        FROM atc_centers a
        LEFT JOIN dlc_offices d ON d.id = a.dlc_id
        WHERE $where
        ORDER BY a.name ASC
    ");
    $q->execute($params);
    $atcs = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// DLC list for filter
$dlcList = [];
try {
    $dlcList = $pdo->query("SELECT id, name FROM dlc_offices ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Quick counts
$stats = ['total' => 0, 'valid' => 0, 'expiring' => 0, 'expired' => 0, 'no_date' => 0];
try {
    $rows = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN authorization_expires_at > DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS valid,
            SUM(CASE WHEN authorization_expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS expiring,
            SUM(CASE WHEN authorization_expires_at < CURDATE() THEN 1 ELSE 0 END) AS expired,
            SUM(CASE WHEN authorization_expires_at IS NULL THEN 1 ELSE 0 END) AS no_date
        FROM atc_centers
    ")->fetch(PDO::FETCH_ASSOC);
    if ($rows) $stats = $rows;
} catch (Exception $e) {}

function acCenterVisualType(?string $centerType): string {
    $t = strtolower(trim(str_replace(['_', '/'], ' ', (string)$centerType)));
    $hasIt = (bool)preg_match('/(?<![a-z])it(?![a-z])/', $t);
    $hasAbacus = str_contains($t, 'abacus') || str_contains($t, 'vedic');
    if ($hasIt && $hasAbacus) return 'mixed';
    if ($hasIt) return 'it';
    if ($hasAbacus) return 'abacus';
    return 'default';
}

function acCenterTypeLabel(?string $centerType): string {
    return trim((string)$centerType) !== '' ? trim((string)$centerType) : '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Authorization Certificates — Admin | Gyanam India</title>
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<?php if (file_exists(__DIR__.'/../assets/css/notifications.css')): ?>
<link rel="stylesheet" href="../assets/css/notifications.css">
<?php endif; ?>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🏆</text></svg>">
<style>
:root {
    --gold:#c9a84c;--gold-lt:#fdfaf4;--gold-border:#e8d5a3;
    --navy:#1a1a2e;--green:#10b981;--green-lt:#ecfdf5;
    --amber:#f59e0b;--amber-lt:#fffbeb;--red:#ef4444;--red-lt:#fef2f2;
    --violet:#6366f1;--violet-lt:#eef2ff;
    --abacus:#7c3aed;--abacus-lt:#f5f3ff;
    --it:#2563eb;--it-lt:#eff6ff;
}

.ac-hero {
    background:linear-gradient(135deg,#fff 0%,#f8fafc 55%,#eef2ff 100%);
    border:1.5px solid #e2e8f0;border-radius:16px;padding:1.15rem 1.35rem;
    margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;
}
.ac-hero-left h3 { margin:0;font-size:1rem;font-weight:800;color:var(--text-primary) }
.ac-hero-left p { margin:.25rem 0 0;font-size:.82rem;color:var(--text-secondary);font-weight:500;max-width:520px;line-height:1.45 }
.ac-hero-badges { display:flex;gap:.45rem;flex-wrap:wrap }
.ac-hero-badge {
    display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .65rem;border-radius:999px;
    font-size:.72rem;font-weight:700;border:1px solid #e2e8f0;background:#fff;color:#475569;
}
.ac-hero-badge.it { border-color:#bfdbfe;background:var(--it-lt);color:#1d4ed8 }
.ac-hero-badge.abacus { border-color:#ddd6fe;background:var(--abacus-lt);color:#6d28d9 }

.ac-stats { display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.85rem;margin-bottom:1rem }
@media (max-width:1100px){ .ac-stats{grid-template-columns:repeat(3,minmax(0,1fr))} }
@media (max-width:640px){ .ac-stats{grid-template-columns:repeat(2,minmax(0,1fr))} }
.ac-stat {
    background:#fff;border:1.5px solid var(--border-color);border-radius:14px;padding:.95rem 1rem;
    display:flex;align-items:center;gap:.75rem;transition:all .18s;text-decoration:none;color:inherit;
}
.ac-stat:hover { transform:translateY(-2px);box-shadow:0 4px 14px rgba(0,0,0,.07) }
.ac-stat.is-active { border-color:#a5b4fc;box-shadow:0 0 0 3px rgba(99,102,241,.12);background:#fafaff }
.ac-stat-icon { width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0 }
.ac-stat-icon svg { width:17px;height:17px }
.ac-stat-icon.gold  { background:var(--gold-lt);color:var(--gold) }
.ac-stat-icon.green { background:var(--green-lt);color:var(--green) }
.ac-stat-icon.amber { background:var(--amber-lt);color:var(--amber) }
.ac-stat-icon.red   { background:var(--red-lt);color:var(--red) }
.ac-stat-icon.violet{ background:var(--violet-lt);color:var(--violet) }
.ac-stat-val { font-size:1.35rem;font-weight:900;color:var(--text-primary);line-height:1 }
.ac-stat-lbl { font-size:.7rem;color:var(--text-secondary);font-weight:600;margin-top:.15rem }

.ac-toolbar {
    background:#fff;border:1.5px solid var(--border-color);border-radius:14px;padding:.85rem 1rem;
    margin-bottom:1rem;display:flex;gap:.55rem;flex-wrap:wrap;align-items:center;
}
.ac-toolbar-meta { width:100%;font-size:.78rem;font-weight:600;color:var(--text-secondary);margin-bottom:.1rem }
.ac-filter-btn {
    padding:.42rem .85rem;border:1.5px solid var(--border-color);border-radius:999px;background:#fff;
    font-size:.78rem;font-weight:700;cursor:pointer;font-family:inherit;color:var(--text-secondary);
    transition:all .18s;text-decoration:none;white-space:nowrap;
}
.ac-filter-btn:hover,.ac-filter-btn.active { border-color:var(--violet);color:var(--violet);background:var(--violet-lt) }
.ac-filter-btn.valid.active   { border-color:var(--green);color:var(--green);background:var(--green-lt) }
.ac-filter-btn.expiring.active{ border-color:var(--amber);color:var(--amber);background:var(--amber-lt) }
.ac-filter-btn.expired.active { border-color:var(--red);color:var(--red);background:var(--red-lt) }
.ac-search-input {
    padding:.45rem .85rem;border:1.5px solid var(--border-color);border-radius:9px;font-size:.84rem;
    font-family:inherit;outline:none;min-width:210px;flex:1;max-width:280px;
}
.ac-search-input:focus { border-color:var(--violet);box-shadow:0 0 0 3px rgba(99,102,241,.1) }
.ac-select { padding:.45rem .85rem;border:1.5px solid var(--border-color);border-radius:9px;font-size:.82rem;font-family:inherit;background:#fff }
.ac-btn-search {
    padding:.45rem .95rem;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;border:none;
    border-radius:9px;font-size:.8rem;font-weight:700;cursor:pointer;font-family:inherit;
}
.ac-btn-clear {
    padding:.45rem .85rem;border:1.5px solid var(--border-color);border-radius:9px;font-size:.8rem;
    font-weight:700;color:var(--text-secondary);text-decoration:none;background:#fff;
}

.ac-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1.1rem }

.ac-card {
    background:#fff;border:1.5px solid var(--border-color);border-radius:16px;overflow:hidden;
    transition:all .22s cubic-bezier(.34,1.56,.64,1);display:flex;flex-direction:column;
}
.ac-card:hover { transform:translateY(-3px);box-shadow:0 10px 30px rgba(15,23,42,.08);border-color:#c7d2fe }

.ac-card-accent { height:4px;width:100% }
.ac-card-accent.valid    { background:linear-gradient(90deg,#10b981,#34d399) }
.ac-card-accent.expiring { background:linear-gradient(90deg,#f59e0b,#fbbf24) }
.ac-card-accent.expired  { background:linear-gradient(90deg,#ef4444,#f87171) }
.ac-card-accent.nodate   { background:linear-gradient(90deg,#94a3b8,#cbd5e1) }

.ac-card-body { padding:1.05rem 1.15rem 1rem;flex:1 }
.ac-card-top { display:flex;align-items:flex-start;gap:.8rem;margin-bottom:.9rem }
.ac-card-avatar {
    width:48px;height:48px;border-radius:12px;flex-shrink:0;display:flex;align-items:center;justify-content:center;
    font-size:1.15rem;font-weight:900;color:#fff;
}
.ac-card-avatar.it { background:linear-gradient(135deg,#2563eb,#38bdf8) }
.ac-card-avatar.abacus { background:linear-gradient(135deg,#7c3aed,#a855f7) }
.ac-card-avatar.mixed { background:linear-gradient(135deg,#6366f1,#7c3aed) }
.ac-card-avatar.default { background:linear-gradient(135deg,#64748b,#94a3b8) }

.ac-card-name { font-size:.92rem;font-weight:800;color:var(--text-primary);line-height:1.25;margin-bottom:.15rem }
.ac-card-meta { font-size:.74rem;color:var(--text-secondary);font-weight:500 }
.ac-code-badge {
    display:inline-flex;align-items:center;padding:.18rem .55rem;margin-top:.35rem;
    background:#f8fafc;border:1px solid #e2e8f0;border-radius:999px;
    font-size:.68rem;font-weight:800;color:#334155;letter-spacing:.03em;font-family:ui-monospace,monospace;
}

.type-pill {
    display:inline-flex;align-items:center;padding:.18rem .55rem;border-radius:999px;
    font-size:.68rem;font-weight:800;border:1px solid transparent;white-space:nowrap;
}
.type-pill.it { background:var(--it-lt);color:#1d4ed8;border-color:#bfdbfe }
.type-pill.abacus { background:var(--abacus-lt);color:#6d28d9;border-color:#ddd6fe }
.type-pill.mixed { background:#fdf4ff;color:#86198f;border-color:#f5d0fe }

.ac-card-details { display:grid;grid-template-columns:1fr 1fr;gap:.55rem .7rem;margin-bottom:.2rem }
.ac-detail { display:flex;flex-direction:column;gap:.08rem;min-width:0 }
.ac-detail-lbl { font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-secondary) }
.ac-detail-val { font-size:.78rem;font-weight:600;color:var(--text-primary);line-height:1.35;word-break:break-word }

.exp-chip { display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .58rem;border-radius:999px;font-size:.7rem;font-weight:700 }
.exp-chip.valid    { background:var(--green-lt);color:#065f46;border:1px solid #6ee7b7 }
.exp-chip.expiring { background:var(--amber-lt);color:#92400e;border:1px solid #fde68a }
.exp-chip.expired  { background:var(--red-lt);color:#991b1b;border:1px solid #fecaca }
.exp-chip.nodate   { background:#f1f5f9;color:#475569;border:1px solid #e2e8f0 }
.exp-dot { width:5px;height:5px;border-radius:50%;background:currentColor;flex-shrink:0 }

.ac-card-footer {
    padding:.85rem 1.15rem;background:linear-gradient(180deg,#fafbfc,#f8fafc);
    border-top:1px solid #eef2f7;display:flex;flex-direction:column;gap:.45rem;
}
.ac-cert-row { display:flex;gap:.45rem;flex-wrap:wrap }
.ac-cert-block { flex:1;min-width:140px;display:flex;flex-direction:column;gap:.35rem }

.btn-cert {
    display:inline-flex;align-items:center;justify-content:center;gap:.4rem;
    padding:.55rem .75rem;border:none;border-radius:10px;font-size:.76rem;font-weight:800;
    cursor:pointer;font-family:inherit;text-decoration:none;transition:all .18s;white-space:nowrap;
}
.btn-cert svg { width:13px;height:13px;flex-shrink:0 }
.btn-cert.it { background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;box-shadow:0 3px 10px rgba(37,99,235,.22) }
.btn-cert.abacus { background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;box-shadow:0 3px 10px rgba(124,58,237,.22) }
.btn-cert:hover { transform:translateY(-1px);filter:brightness(1.05) }
.btn-cert.disabled { opacity:.45;cursor:not-allowed;pointer-events:none;filter:none;transform:none }

.btn-cert-ghost {
    display:inline-flex;align-items:center;justify-content:center;gap:.3rem;
    padding:.45rem .65rem;background:#fff;border:1.5px solid #e2e8f0;border-radius:9px;
    font-size:.72rem;font-weight:700;color:#475569;text-decoration:none;transition:all .15s;
}
.btn-cert-ghost:hover { border-color:#c7d2fe;color:#4338ca;background:#f8faff }

.cert-warn { font-size:.68rem;font-weight:600;color:#9a3412;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:.35rem .5rem }

.btn-renew {
    display:inline-flex;align-items:center;justify-content:center;gap:.35rem;width:100%;
    padding:.52rem .85rem;background:#fff;border:1.5px solid #fde68a;border-radius:10px;
    font-size:.76rem;font-weight:700;cursor:pointer;font-family:inherit;color:#92400e;transition:all .18s;
}
.btn-renew:hover { background:var(--amber-lt);border-color:#fbbf24 }
.btn-renew svg { width:13px;height:13px }

.empty-state { text-align:center;padding:3.5rem 1rem;color:var(--text-secondary);grid-column:1/-1;background:#fff;border:1.5px dashed #e2e8f0;border-radius:16px }
.empty-state svg { display:block;margin:0 auto .85rem;width:44px;height:44px;opacity:.28 }
.empty-state p { font-size:.88rem;font-weight:600;margin:0 }

.view-toggle { display:flex;gap:.3rem;margin-left:auto }
.view-btn { width:34px;height:34px;border:1.5px solid var(--border-color);border-radius:8px;background:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s }
.view-btn.active,.view-btn:hover { border-color:var(--violet);background:var(--violet-lt);color:var(--violet) }
.view-btn svg { width:15px;height:15px }

.ac-table-wrap { background:#fff;border:1.5px solid var(--border-color);border-radius:14px;overflow:hidden;display:none }
.ac-table-wrap.active { display:block }
.ac-grid-wrap.hidden { display:none }
.ac-tbl { width:100%;border-collapse:collapse;font-size:.86rem }
.ac-tbl thead th { padding:.72rem 1rem;text-align:left;font-size:.66rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--border-color);background:#fafbfc;white-space:nowrap }
.ac-tbl tbody tr { border-bottom:1px solid #f3f4f6;transition:background .1s }
.ac-tbl tbody tr:last-child { border-bottom:none }
.ac-tbl tbody tr:hover { background:#f9fafb }
.ac-tbl tbody td { padding:.68rem 1rem;vertical-align:middle }

#acToastWrap { position:fixed;bottom:1.5rem;right:1.5rem;z-index:99999;display:flex;flex-direction:column;gap:.5rem }
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
    <header class="top-header">
        <div class="header-left">
            <button class="hamburger" id="hamburgerBtn">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <div class="header-greeting">
                <h2>Authorization Certificates</h2>
                <p>Print and manage ATC authorization certificates</p>
            </div>
        </div>
        <div class="header-right">
            <?php if (file_exists(__DIR__.'/../includes/notification_bell.php')) include __DIR__.'/../includes/notification_bell.php'; ?>
            <?php include __DIR__.'/../includes/profile_dropdown.php'; ?>
        </div>
    </header>

    <div class="page-content">

        <div class="ac-hero">
            <div class="ac-hero-left">
                <h3>ATC Authorization Certificates</h3>
                <p>Preview or download GIIT and Gyanam Abacus authorization PDFs. Certificates are generated from the official templates with each center’s name, code, location, and validity period.</p>
            </div>
            <div class="ac-hero-badges">
                <span class="ac-hero-badge it">GIIT — IT Centers</span>
                <span class="ac-hero-badge abacus">Gyanam Abacus — Abacus / Vedic</span>
            </div>
        </div>

        <!-- Stats -->
        <div class="ac-stats">
            <a href="?exp=all<?= $dlcFilter ? '&dlc='.$dlcFilter : '' ?><?= $search ? '&q='.urlencode($search) : '' ?>" class="ac-stat<?= $expFilter==='all' ? ' is-active' : '' ?>">
                <div class="ac-stat-icon gold"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg></div>
                <div><div class="ac-stat-val"><?= (int)$stats['total'] ?></div><div class="ac-stat-lbl">Total ATCs</div></div>
            </a>
            <a href="?exp=valid<?= $dlcFilter ? '&dlc='.$dlcFilter : '' ?><?= $search ? '&q='.urlencode($search) : '' ?>" class="ac-stat<?= $expFilter==='valid' ? ' is-active' : '' ?>">
                <div class="ac-stat-icon green"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></div>
                <div><div class="ac-stat-val"><?= (int)$stats['valid'] ?></div><div class="ac-stat-lbl">Valid</div></div>
            </a>
            <a href="?exp=expiring<?= $dlcFilter ? '&dlc='.$dlcFilter : '' ?><?= $search ? '&q='.urlencode($search) : '' ?>" class="ac-stat<?= $expFilter==='expiring' ? ' is-active' : '' ?>">
                <div class="ac-stat-icon amber"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
                <div><div class="ac-stat-val"><?= (int)$stats['expiring'] ?></div><div class="ac-stat-lbl">Expiring Soon</div></div>
            </a>
            <a href="?exp=expired<?= $dlcFilter ? '&dlc='.$dlcFilter : '' ?><?= $search ? '&q='.urlencode($search) : '' ?>" class="ac-stat<?= $expFilter==='expired' ? ' is-active' : '' ?>">
                <div class="ac-stat-icon red"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></div>
                <div><div class="ac-stat-val"><?= (int)$stats['expired'] ?></div><div class="ac-stat-lbl">Expired</div></div>
            </a>
            <a href="?exp=all<?= $dlcFilter ? '&dlc='.$dlcFilter : '' ?><?= $search ? '&q='.urlencode($search) : '' ?>" class="ac-stat">
                <div class="ac-stat-icon violet"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
                <div><div class="ac-stat-val"><?= (int)$stats['no_date'] ?></div><div class="ac-stat-lbl">No Date Set</div></div>
            </a>
        </div>

        <!-- Filters -->
        <form method="GET" class="ac-toolbar" id="filterForm">
            <div class="ac-toolbar-meta">Showing <?= count($atcs) ?> center<?= count($atcs) === 1 ? '' : 's' ?><?= $search ? ' matching “'.htmlspecialchars($search).'”' : '' ?></div>
            <a href="?exp=all" class="ac-filter-btn <?= $expFilter==='all' ? 'active' : '' ?>">All</a>
            <a href="?exp=valid" class="ac-filter-btn valid <?= $expFilter==='valid' ? 'active' : '' ?>">Valid</a>
            <a href="?exp=expiring" class="ac-filter-btn expiring <?= $expFilter==='expiring' ? 'active' : '' ?>">Expiring</a>
            <a href="?exp=expired" class="ac-filter-btn expired <?= $expFilter==='expired' ? 'active' : '' ?>">Expired</a>
            <input type="hidden" name="exp" value="<?= htmlspecialchars($expFilter) ?>">
            <select name="dlc" class="ac-select" onchange="this.form.submit()">
                <option value="0">All DLC Offices</option>
                <?php foreach ($dlcList as $d): ?>
                <option value="<?= $d['id'] ?>" <?= $dlcFilter == $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="q" class="ac-search-input" placeholder="Search name, code, district…" value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="ac-btn-search">Search</button>
            <?php if ($search || $dlcFilter): ?>
            <a href="?exp=<?= urlencode($expFilter) ?>" class="ac-btn-clear">Clear</a>
            <?php endif; ?>

            <div class="view-toggle">
                <button type="button" class="view-btn active" id="btnGrid" title="Card view" onclick="setView('grid')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                </button>
                <button type="button" class="view-btn" id="btnList" title="Table view" onclick="setView('list')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                </button>
            </div>
        </form>

        <!-- GRID VIEW -->
        <div class="ac-grid ac-grid-wrap" id="gridView">
        <?php if (empty($atcs)): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
                <p>No ATC centers found matching your filters.</p>
            </div>
        <?php else: ?>
            <?php foreach ($atcs as $atc):
                $expTs = !empty($atc['authorization_expires_at']) ? strtotime($atc['authorization_expires_at']) : null;
                $daysLeft = $expTs ? (int)(($expTs - strtotime(date('Y-m-d'))) / 86400) : null;

                if ($expTs === null) {
                    $expStatus = 'nodate';
                    $expLabel  = 'No Date';
                } elseif ($daysLeft < 0) {
                    $expStatus = 'expired';
                    $expLabel  = 'Expired ' . abs($daysLeft) . 'd ago';
                } elseif ($daysLeft <= 30) {
                    $expStatus = 'expiring';
                    $expLabel  = 'Expires in ' . $daysLeft . 'd';
                } else {
                    $expStatus = 'valid';
                    $expLabel  = 'Valid till ' . date('M Y', $expTs);
                }
                $initial = strtoupper(substr($atc['name'], 0, 1));
                $visType = acCenterVisualType($atc['center_type'] ?? '');
                $locParts = array_filter([
                    trim($atc['city'] ?? ''),
                    trim($atc['district'] ?? ''),
                    trim($atc['state'] ?? ''),
                ]);
                $locLabel = $locParts ? implode(', ', $locParts) : '—';
                $certVars = atcAuthCertificateVariants($atc['center_type'] ?? '');
            ?>
            <div class="ac-card">
                <div class="ac-card-accent <?= $expStatus ?>"></div>
                <div class="ac-card-body">
                    <div class="ac-card-top">
                        <div class="ac-card-avatar <?= htmlspecialchars($visType) ?>"><?= htmlspecialchars($initial) ?></div>
                        <div style="flex:1;min-width:0">
                            <div class="ac-card-name"><?= htmlspecialchars($atc['name']) ?></div>
                            <div class="ac-card-meta"><?= htmlspecialchars($locLabel) ?></div>
                            <span class="ac-code-badge"><?= htmlspecialchars($atc['atc_code'] ?: date('Y') . str_pad($atc['id'], 5, '0', STR_PAD_LEFT)) ?></span>
                        </div>
                    </div>

                    <div class="ac-card-details">
                        <div class="ac-detail">
                            <span class="ac-detail-lbl">Center Type</span>
                            <span class="ac-detail-val">
                                <span class="type-pill <?= htmlspecialchars($visType) ?>"><?= htmlspecialchars(acCenterTypeLabel($atc['center_type'] ?? '')) ?></span>
                            </span>
                        </div>
                        <div class="ac-detail">
                            <span class="ac-detail-lbl">DLC Office</span>
                            <span class="ac-detail-val"><?= htmlspecialchars($atc['dlc_name'] ?? '—') ?></span>
                        </div>
                        <div class="ac-detail">
                            <span class="ac-detail-lbl">Contact</span>
                            <span class="ac-detail-val"><?= htmlspecialchars($atc['contact_person'] ?? '—') ?></span>
                        </div>
                        <div class="ac-detail">
                            <span class="ac-detail-lbl">Authorization</span>
                            <span class="exp-chip <?= $expStatus ?>">
                                <span class="exp-dot"></span>
                                <?= $expLabel ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="ac-card-footer">
                    <div class="ac-cert-row">
                    <?php foreach ($certVars as $cv):
                        $vKey = $cv['variant'];
                        $vLabel = $vKey === 'it' ? 'GIIT Certificate' : 'Abacus Certificate';
                        $tplOk = atcAuthCertificateTemplatePath($vKey) !== null;
                        $previewUrl = 'generate_auth_certificate.php?atc_id=' . (int)$atc['id'] . '&variant=' . urlencode($vKey) . '&preview=1';
                        $downloadUrl = 'generate_auth_certificate.php?atc_id=' . (int)$atc['id'] . '&variant=' . urlencode($vKey);
                    ?>
                        <div class="ac-cert-block">
                            <?php if (!$tplOk): ?>
                                <div class="cert-warn">Template missing for <?= htmlspecialchars($cv['brand']) ?></div>
                            <?php endif; ?>
                            <a class="btn-cert <?= htmlspecialchars($vKey) ?><?= $tplOk ? '' : ' disabled' ?>" href="<?= $tplOk ? htmlspecialchars($previewUrl) : '#' ?>" target="_blank" rel="noopener">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                Preview
                            </a>
                            <a class="btn-cert-ghost<?= $tplOk ? '' : ' disabled' ?>" href="<?= $tplOk ? htmlspecialchars($downloadUrl) : '#' ?>"<?= $tplOk ? '' : ' style="pointer-events:none;opacity:.45"' ?>>
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                Download <?= $vKey === 'it' ? 'GIIT' : 'Abacus' ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <?php if ($daysLeft !== null && $daysLeft <= 30): ?>
                    <button class="btn-renew" onclick="renewATC(<?= $atc['id'] ?>, '<?= htmlspecialchars(addslashes($atc['name'])) ?>')" title="Renew Authorization">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                        Renew Authorization (+1 year)
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>

        <!-- TABLE VIEW -->
        <div class="ac-table-wrap" id="tableView">
            <table class="ac-tbl">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>ATC Code</th>
                        <th>Center Name</th>
                        <th>Location</th>
                        <th>DLC Office</th>
                        <th>Type</th>
                        <th>Authorization</th>
                        <th>Status</th>
                        <th style="text-align:center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($atcs)): ?>
                    <tr><td colspan="9" style="text-align:center;padding:2.5rem;color:var(--text-secondary);">No ATCs found.</td></tr>
                <?php else: ?>
                    <?php foreach ($atcs as $i => $atc):
                        $expTs2 = !empty($atc['authorization_expires_at']) ? strtotime($atc['authorization_expires_at']) : null;
                        $daysLeft2 = $expTs2 ? (int)(($expTs2 - strtotime(date('Y-m-d'))) / 86400) : null;
                        if      ($expTs2 === null)     { $es2 = 'nodate';   $el2 = 'No Date'; }
                        elseif  ($daysLeft2 < 0)       { $es2 = 'expired';  $el2 = 'Expired ' . abs($daysLeft2) . 'd ago'; }
                        elseif  ($daysLeft2 <= 30)     { $es2 = 'expiring'; $el2 = 'Expires in ' . $daysLeft2 . 'd'; }
                        else                           { $es2 = 'valid';    $el2 = 'Valid till ' . date('M Y', $expTs2); }
                    ?>
                    <tr>
                        <td style="color:var(--text-secondary);font-size:.8rem"><?= $i+1 ?></td>
                        <td><span class="ac-code-badge"><?= htmlspecialchars($atc['atc_code'] ?: date('Y') . str_pad($atc['id'], 5, '0', STR_PAD_LEFT)) ?></span></td>
                        <td>
                            <div style="font-weight:700;font-size:.875rem"><?= htmlspecialchars($atc['name']) ?></div>
                            <div style="font-size:.73rem;color:var(--text-secondary)"><?= htmlspecialchars($atc['contact_person'] ?? '') ?></div>
                        </td>
                        <td style="font-size:.82rem"><?= htmlspecialchars($atc['district']) ?>, <?= htmlspecialchars($atc['state']) ?></td>
                        <td style="font-size:.82rem"><?= htmlspecialchars($atc['dlc_name'] ?? '—') ?></td>
                        <td style="font-size:.8rem"><?= htmlspecialchars($atc['center_type'] ?? '—') ?></td>
                        <td>
                            <span class="exp-chip <?= $es2 ?>"><span class="exp-dot"></span><?= $el2 ?></span>
                        </td>
                        <td>
                            <span class="exp-chip <?= strtolower($atc['status']) === 'active' ? 'valid' : 'expired' ?>">
                                <span class="exp-dot"></span><?= $atc['status'] ?>
                            </span>
                        </td>
                        <td style="text-align:center;white-space:nowrap">
                            <div style="display:flex;gap:.35rem;justify-content:center;flex-wrap:wrap">
                                <?php
                                $certVars2 = atcAuthCertificateVariants($atc['center_type'] ?? '');
                                foreach ($certVars2 as $cv2):
                                    $vk = $cv2['variant'];
                                    $tplOk2 = atcAuthCertificateTemplatePath($vk) !== null;
                                    $short = $vk === 'it' ? 'GIIT' : 'Abacus';
                                ?>
                                <a href="generate_auth_certificate.php?atc_id=<?= (int)$atc['id'] ?>&variant=<?= urlencode($vk) ?>&preview=1" target="_blank"
                                   class="btn-cert <?= htmlspecialchars($vk) ?>" style="padding:.38rem .6rem;font-size:.72rem;<?= $tplOk2 ? '' : 'opacity:.45;pointer-events:none' ?>">
                                    <?= htmlspecialchars($short) ?>
                                </a>
                                <?php endforeach; ?>
                                <?php if ($daysLeft2 !== null && $daysLeft2 <= 30): ?>
                                <button onclick="renewATC(<?= $atc['id'] ?>, '<?= htmlspecialchars(addslashes($atc['name'])) ?>')"
                                    class="btn-renew" style="width:auto;padding:.38rem .65rem;font-size:.72rem">
                                    Renew
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div><!-- /page-content -->
</main>
</div>

<div id="acToastWrap"></div>

<script src="../assets/js/dashboard.js"></script>
<script>
// View toggle
function setView(v) {
    const grid = document.getElementById('gridView');
    const tbl  = document.getElementById('tableView');
    const bg   = document.getElementById('btnGrid');
    const bl   = document.getElementById('btnList');
    if (v === 'grid') {
        grid.classList.remove('hidden'); tbl.classList.remove('active');
        bg.classList.add('active'); bl.classList.remove('active');
        localStorage.setItem('authCertView','grid');
    } else {
        grid.classList.add('hidden'); tbl.classList.add('active');
        bl.classList.add('active'); bg.classList.remove('active');
        localStorage.setItem('authCertView','list');
    }
}
// Restore last view
(function() {
    if (localStorage.getItem('authCertView') === 'list') setView('list');
})();

// Renew ATC authorization
async function renewATC(id, name) {
    if (!confirm(`Renew authorization for "${name}" by 1 more year?`)) return;
    const fd = new FormData();
    fd.append('action', 'renew'); fd.append('atc_id', id);
    try {
        const res = await fetch('atc_centers.php', { method:'POST', body: new URLSearchParams(fd) });
        const data = await res.json();
        if (data.success) {
            acToast('✅ ' + data.message);
            setTimeout(() => location.reload(), 1500);
        } else {
            acToast(data.message || 'Error.', 'error');
        }
    } catch(e) { acToast('Network error.', 'error'); }
}

function acToast(msg, type='success') {
    const t = document.createElement('div');
    t.style.cssText = `background:${type==='success'?'#059669':'#dc2626'};color:#fff;padding:.75rem 1.25rem;border-radius:10px;font-size:.875rem;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.15);max-width:340px`;
    t.textContent = msg;
    document.getElementById('acToastWrap').appendChild(t);
    setTimeout(() => t.remove(), 3500);
}
</script>
</body>
</html>
