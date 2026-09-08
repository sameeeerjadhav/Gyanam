<?php
/**
 * Gyanam Portal — ATC: Downloads hub
 * Tabs: Documents | Banners | Question Banks
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/exam_integration.php';

requireLogin(['ATC CENTER']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$atcId = (int)($_SESSION['atc_id'] ?? 0);
$atcCode = examPortalAtcCodeFromSession($pdo);

$type = strtolower(trim((string)($_GET['type'] ?? 'documents')));
if (!in_array($type, ['documents', 'banners', 'question_banks'], true)) {
    $type = 'documents';
}

$searchTerm = trim((string)($_GET['search'] ?? ''));
$error = isset($_GET['err']) ? sanitize((string)$_GET['err']) : '';

// Question bank PDF download
if ($type === 'question_banks' && isset($_GET['download'])) {
    $bankId = (int)$_GET['download'];
    $withAnswers = !isset($_GET['answers']) || $_GET['answers'] !== '0';
    $redirBase = 'documents.php?type=question_banks';
    if ($searchTerm !== '') {
        $redirBase .= '&search=' . urlencode($searchTerm);
    }
    if ($atcCode === '') {
        header('Location: ' . $redirBase . '&err=' . urlencode('ATC code not found'));
        exit;
    }
    $export = fetchQuestionBankExport($atcCode, $bankId, $withAnswers);
    if (!$export['success'] || empty($export['data'])) {
        header('Location: ' . $redirBase . '&err=' . urlencode($export['error'] ?? 'Download failed'));
        exit;
    }
    try {
        streamQuestionBankPdf($export['data'], $atcCode);
    } catch (Throwable $e) {
        header('Location: ' . $redirBase . '&err=' . urlencode('PDF generation failed'));
        exit;
    }
}

$documents = [];
$banners = [];
$banks = [];
$counts = ['documents' => 0, 'banners' => 0, 'question_banks' => 0];

// Always load counts for tabs
try {
    $counts['documents'] = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE status = 'Active'")->fetchColumn();
} catch (Exception $e) {}
try {
    $counts['banners'] = count(getDownloadableBannersForAtc($pdo, $atcId));
} catch (Exception $e) {}
if ($atcCode !== '' && examIntegrationReady()) {
    $qbRes = fetchAssignedQuestionBanks($atcCode);
    if ($qbRes['success']) {
        $counts['question_banks'] = count($qbRes['banks']);
    }
}

if ($type === 'documents') {
    $sql = "SELECT d.*, u.username as uploaded_by_name
            FROM documents d
            LEFT JOIN users u ON d.uploaded_by = u.id
            WHERE d.status = 'Active'";
    $params = [];
    if ($searchTerm !== '') {
        $sql .= ' AND (d.original_name LIKE ? OR d.description LIKE ?)';
        $sp = '%' . $searchTerm . '%';
        $params = [$sp, $sp];
    }
    $sql .= ' ORDER BY d.upload_date DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $counts['documents'] = count($documents);
} elseif ($type === 'banners') {
    $banners = getDownloadableBannersForAtc($pdo, $atcId);
    if ($searchTerm !== '') {
        $q = strtolower($searchTerm);
        $banners = array_values(array_filter($banners, static function ($b) use ($q) {
            return str_contains(strtolower((string)($b['title'] ?? '')), $q);
        }));
    }
    $counts['banners'] = count($banners);
} else {
    if ($atcCode === '') {
        $error = $error !== '' ? $error : 'ATC code missing. Please re-login.';
    } elseif (!examIntegrationReady()) {
        $error = $error !== '' ? $error : 'Exam portal is not connected. Contact Head Office.';
    } else {
        $res = fetchAssignedQuestionBanks($atcCode);
        if (!$res['success']) {
            $error = $error !== '' ? $error : ($res['error'] ?? 'Could not load question banks');
        } else {
            $banks = $res['banks'];
            if ($searchTerm !== '') {
                $q = strtolower($searchTerm);
                $banks = array_values(array_filter($banks, static function ($b) use ($q) {
                    return str_contains(strtolower((string)($b['title'] ?? '')), $q)
                        || str_contains(strtolower((string)($b['subject'] ?? '')), $q);
                }));
            }
            $counts['question_banks'] = count($banks);
        }
    }
}

function formatFileSize($bytes) {
    $bytes = (int)$bytes;
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

function bannerIsVideo(string $path): bool {
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['mp4', 'webm', 'ogg'], true);
}

$tabMeta = [
    'documents' => ['label' => 'Documents', 'hint' => 'HO files & resources'],
    'banners' => ['label' => 'Banners', 'hint' => 'Assigned promotional banners'],
    'question_banks' => ['label' => 'Question Banks', 'hint' => 'Assigned exam practice PDFs'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Downloads — ATC Center | Gyanam India</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <style>
        .dl-hero {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 55%, #312e81 100%);
            border-radius: 18px; padding: 1.25rem 1.4rem 1.15rem; color: #fff;
            margin-bottom: 1.15rem; box-shadow: 0 12px 28px rgba(15,23,42,.18);
        }
        .dl-hero h3 { margin: 0; font-size: 1.15rem; font-weight: 800; letter-spacing: -.02em; }
        .dl-hero p { margin: .35rem 0 0; font-size: .84rem; opacity: .85; }
        .dl-tabs { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: .65rem; margin-top: 1rem; }
        @media (max-width:720px){ .dl-tabs { grid-template-columns: 1fr; } }
        .dl-tab {
            display: block; text-decoration: none; color: #e2e8f0;
            background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.12);
            border-radius: 14px; padding: .85rem 1rem; transition: .15s ease;
        }
        .dl-tab:hover { background: rgba(255,255,255,.14); }
        .dl-tab.active { background: #fff; color: #0f172a; border-color: #fff; }
        .dl-tab .t-label { display:flex; align-items:center; justify-content:space-between; gap:.5rem; font-weight:800; font-size:.9rem; }
        .dl-tab .t-hint { margin-top:.25rem; font-size:.72rem; opacity:.75; font-weight:500; }
        .dl-tab.active .t-hint { color:#64748b; opacity:1; }
        .dl-tab .count {
            min-width: 1.5rem; height: 1.5rem; border-radius: 999px; padding: 0 .4rem;
            display:inline-flex; align-items:center; justify-content:center;
            font-size:.72rem; font-weight:800; background: rgba(255,255,255,.18);
        }
        .dl-tab.active .count { background:#eef2ff; color:#3730a3; }
        .dl-note {
            border-radius: 12px; padding: .85rem 1rem; font-size: .84rem; margin-bottom: 1rem;
            background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af;
        }
        .dl-err {
            border-radius: 12px; padding: .85rem 1rem; font-size: .84rem; margin-bottom: 1rem;
            background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c;
        }
        .banner-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1rem;
        }
        .banner-dl-card {
            background:#fff; border:1.5px solid #e5e7eb; border-radius:16px; overflow:hidden;
            box-shadow:0 4px 14px rgba(15,23,42,.04); display:flex; flex-direction:column;
        }
        .banner-dl-media {
            aspect-ratio: 16/10; background:#0f172a; position:relative; overflow:hidden;
        }
        .banner-dl-media img, .banner-dl-media video {
            width:100%; height:100%; object-fit:cover; display:block;
        }
        .banner-dl-body { padding: .9rem 1rem 1rem; flex:1; display:flex; flex-direction:column; gap:.55rem; }
        .banner-dl-title { font-weight:800; font-size:.92rem; color:#0f172a; line-height:1.3; }
        .banner-dl-meta { font-size:.75rem; color:#64748b; font-weight:600; }
        .banner-dl-actions { display:flex; gap:.45rem; flex-wrap:wrap; margin-top:auto; }
        .btn-dl {
            display:inline-flex; align-items:center; gap:.35rem; height:34px; padding:0 .85rem;
            border-radius:10px; font-size:.78rem; font-weight:750; text-decoration:none;
        }
        .btn-dl-primary { background:linear-gradient(135deg,#10b981,#059669); color:#fff; }
        .btn-dl-ghost { background:#fff; color:#334155; border:1.5px solid #e2e8f0; }
        .qb-subject {
            display:inline-block; margin-top:.2rem; font-size:.75rem; font-weight:650;
            color:#4361ee; background:#eef2ff; padding:.15rem .5rem; border-radius:999px;
        }
        .qb-actions { display:flex; gap:.45rem; flex-wrap:wrap; }
        .qb-actions a { font-size:.8rem; font-weight:700; text-decoration:none; }
        .qb-actions .with-ans { color:#059669; }
        .qb-actions .practice { color:#6366f1; }
        .doc-file-info { display:flex; align-items:center; gap:1rem; }
        .doc-file-icon {
            width:40px; height:40px; border-radius:10px; flex-shrink:0;
            background:linear-gradient(135deg,#eff6ff,#dbeafe);
            display:flex; align-items:center; justify-content:center;
        }
        .doc-file-icon svg { width:18px; height:18px; stroke:#2563eb; }
        .file-size-badge {
            display:inline-block; padding:.35rem .65rem; background:#f1f5f9; border-radius:8px;
            font-size:.8rem; font-weight:700; font-family:ui-monospace,monospace;
        }
        .btn-download-compact {
            display:inline-flex; align-items:center; gap:.4rem; padding:.55rem .9rem;
            background:linear-gradient(135deg,#10b981,#059669); color:#fff; border-radius:10px;
            font-weight:700; font-size:.82rem; text-decoration:none;
        }
        .documents-table thead th {
            text-transform:uppercase; font-size:.72rem; letter-spacing:.04em;
            font-weight:800; color:#64748b; padding:.85rem 1rem;
        }
        .documents-table tbody td { vertical-align:middle; }
        .documents-table tbody tr:hover { background:#f8fafc; }
        .empty-panel {
            text-align:center; padding:2.5rem 1rem; color:#94a3b8;
            border:1.5px dashed #e2e8f0; border-radius:16px; background:#fff;
        }
        .empty-panel svg { width:44px; height:44px; margin-bottom:.75rem; opacity:.45; }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <div class="header-greeting">
                    <h2>Downloads</h2>
                    <p>Documents, banners &amp; question banks assigned to your centre</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">
            <div class="dl-hero">
                <h3>Resource Library</h3>
                <p>Everything Head Office shared with your ATC — files, campaign banners, and practice banks.</p>
                <div class="dl-tabs">
                    <?php foreach ($tabMeta as $key => $meta): ?>
                    <a class="dl-tab <?= $type === $key ? 'active' : '' ?>" href="documents.php?type=<?= urlencode($key) ?>">
                        <div class="t-label">
                            <span><?= htmlspecialchars($meta['label']) ?></span>
                            <span class="count"><?= (int)$counts[$key] ?></span>
                        </div>
                        <div class="t-hint"><?= htmlspecialchars($meta['hint']) ?></div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($type === 'banners'): ?>
                <div class="dl-note">Banners assigned to your ATC appear here for download (and on your dashboard carousel when Active).</div>
            <?php elseif ($type === 'question_banks'): ?>
                <div class="dl-note">Question banks are created in the Exam Portal and assigned to your ATC code<?= $atcCode !== '' ? ' (<strong>' . htmlspecialchars($atcCode) . '</strong>)' : '' ?>.</div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="dl-err"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="page-toolbar">
                <h3>
                    <?= htmlspecialchars($tabMeta[$type]['label']) ?>
                    <span class="badge-count"><?= (int)$counts[$type] ?></span>
                </h3>
                <form method="GET" style="display:flex;gap:.75rem;">
                    <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                    <div class="search-bar">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" name="search" placeholder="Search…" value="<?= htmlspecialchars($searchTerm) ?>">
                    </div>
                    <button type="submit" class="btn-primary" style="padding:0 1.35rem;">Search</button>
                </form>
            </div>

            <?php if ($type === 'documents'): ?>
                <div class="table-card">
                    <table class="data-table documents-table">
                        <thead>
                            <tr>
                                <th style="width:70px">SR</th>
                                <th>FILENAME</th>
                                <th style="width:180px">UPLOADED</th>
                                <th style="width:110px">SIZE</th>
                                <th style="width:130px">ACTION</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($documents)): ?>
                            <tr><td colspan="5" class="table-empty"><p>No documents available.</p></td></tr>
                        <?php else: foreach ($documents as $i => $doc): ?>
                            <tr>
                                <td style="text-align:center;font-weight:700;color:#64748b"><?= $i + 1 ?></td>
                                <td>
                                    <div class="doc-file-info">
                                        <div class="doc-file-icon">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                        </div>
                                        <div>
                                            <div class="cell-name"><?= htmlspecialchars($doc['original_name']) ?></div>
                                            <?php if (!empty($doc['description'])): ?>
                                            <div class="cell-sub"><?= htmlspecialchars(substr($doc['description'], 0, 80)) ?><?= strlen($doc['description']) > 80 ? '…' : '' ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td style="color:#64748b;font-size:.88rem"><?= date('d M Y', strtotime($doc['upload_date'])) ?></td>
                                <td><span class="file-size-badge"><?= formatFileSize($doc['file_size']) ?></span></td>
                                <td>
                                    <a class="btn-download-compact" href="../<?= htmlspecialchars($doc['file_path']) ?>" download>
                                        Download
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($type === 'banners'): ?>
                <?php if (empty($banners)): ?>
                    <div class="empty-panel">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                        <div>No banners assigned to your centre yet.</div>
                    </div>
                <?php else: ?>
                <div class="banner-grid">
                    <?php foreach ($banners as $b):
                        $path = (string)($b['image_path'] ?? '');
                        $url = '../uploads/announcements/' . rawurlencode($path);
                        $isVideo = bannerIsVideo($path);
                        $title = (string)($b['title'] ?? 'Banner');
                        $created = !empty($b['created_at']) ? date('d M Y', strtotime($b['created_at'])) : '—';
                    ?>
                    <div class="banner-dl-card">
                        <div class="banner-dl-media">
                            <?php if ($isVideo): ?>
                                <video src="<?= htmlspecialchars($url) ?>" muted playsinline preload="metadata"></video>
                            <?php else: ?>
                                <img src="<?= htmlspecialchars($url) ?>" alt="<?= htmlspecialchars($title) ?>" loading="lazy">
                            <?php endif; ?>
                        </div>
                        <div class="banner-dl-body">
                            <div class="banner-dl-title"><?= htmlspecialchars($title) ?></div>
                            <div class="banner-dl-meta"><?= $isVideo ? 'Video' : 'Image' ?> · <?= htmlspecialchars($created) ?></div>
                            <div class="banner-dl-actions">
                                <a class="btn-dl btn-dl-primary" href="<?= htmlspecialchars($url) ?>" download="<?= htmlspecialchars($path) ?>">Download</a>
                                <a class="btn-dl btn-dl-ghost" href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener">Open</a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="table-card">
                    <table class="data-table documents-table">
                        <thead>
                            <tr>
                                <th style="width:70px">SR</th>
                                <th>QUESTION BANK</th>
                                <th style="width:110px">QUESTIONS</th>
                                <th style="width:130px">UPDATED</th>
                                <th style="width:220px">DOWNLOAD PDF</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($banks)): ?>
                            <tr><td colspan="5" class="table-empty"><p>No question banks assigned yet.</p></td></tr>
                        <?php else: foreach ($banks as $i => $bank):
                            $id = (int)($bank['id'] ?? 0);
                            $updated = !empty($bank['updated_at']) ? date('d M Y', strtotime((string)$bank['updated_at'])) : '—';
                            $dl = 'documents.php?type=question_banks&download=' . $id;
                        ?>
                            <tr>
                                <td style="text-align:center;font-weight:700;color:#64748b"><?= $i + 1 ?></td>
                                <td>
                                    <div class="cell-name"><?= htmlspecialchars((string)($bank['title'] ?? 'Untitled')) ?></div>
                                    <?php if (!empty($bank['subject'])): ?>
                                        <span class="qb-subject"><?= htmlspecialchars((string)$bank['subject']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int)($bank['questions_count'] ?? 0) ?></td>
                                <td><?= htmlspecialchars($updated) ?></td>
                                <td>
                                    <div class="qb-actions">
                                        <a class="with-ans" href="<?= htmlspecialchars($dl) ?>">With answers</a>
                                        <a class="practice" href="<?= htmlspecialchars($dl . '&answers=0') ?>">Practice</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>
