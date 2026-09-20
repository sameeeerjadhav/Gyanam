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
$courseFilter = trim((string)($_GET['course'] ?? 'all'));
$mediaFilter = strtolower(trim((string)($_GET['media'] ?? 'all'))); // banners: all|image|video
$sortBy = strtolower(trim((string)($_GET['sort'] ?? 'updated'))); // updated|name|questions|date
$error = isset($_GET['err']) ? sanitize((string)$_GET['err']) : '';

if (!in_array($mediaFilter, ['all', 'image', 'video'], true)) {
    $mediaFilter = 'all';
}
if (!in_array($sortBy, ['updated', 'name', 'questions', 'date', 'size'], true)) {
    $sortBy = 'updated';
}

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
    $activeCourseNames = getAtcActiveCourseNames($pdo, $atcId);
    $listed = fetchAssignedQuestionBanks($atcCode);
    $allowedBanks = $listed['success']
        ? filterQuestionBanksByAtcActiveCourses($listed['banks'], $activeCourseNames)
        : [];
    $allowedIds = array_map(static fn($b) => (int)($b['id'] ?? 0), $allowedBanks);
    if (!in_array($bankId, $allowedIds, true)) {
        header('Location: ' . $redirBase . '&err=' . urlencode('This question bank is only available for courses you have activated.'));
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
$activeCourseNames = getAtcActiveCourseNames($pdo, $atcId);

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
        $counts['question_banks'] = count(filterQuestionBanksByAtcActiveCourses($qbRes['banks'], $activeCourseNames));
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
    if ($sortBy === 'name') {
        $sql .= ' ORDER BY d.original_name ASC';
    } elseif ($sortBy === 'size') {
        $sql .= ' ORDER BY d.file_size DESC';
    } else {
        $sql .= ' ORDER BY d.upload_date DESC';
    }
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
    if ($mediaFilter === 'image' || $mediaFilter === 'video') {
        $banners = array_values(array_filter($banners, static function ($b) use ($mediaFilter) {
            $path = (string)($b['image_path'] ?? '');
            $isVid = in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['mp4', 'webm', 'ogg'], true);
            return $mediaFilter === 'video' ? $isVid : !$isVid;
        }));
    }
    usort($banners, static function ($a, $b) use ($sortBy) {
        if ($sortBy === 'name') {
            return strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
        }
        return strtotime((string)($b['created_at'] ?? '1970-01-01')) <=> strtotime((string)($a['created_at'] ?? '1970-01-01'));
    });
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
            $banks = filterQuestionBanksByAtcActiveCourses($res['banks'], $activeCourseNames);
            if ($courseFilter !== '' && $courseFilter !== 'all') {
                $cf = strtolower($courseFilter);
                $banks = array_values(array_filter($banks, static function ($b) use ($cf) {
                    return strtolower(trim((string)($b['subject'] ?? ''))) === $cf;
                }));
            }
            if ($searchTerm !== '') {
                $q = strtolower($searchTerm);
                $banks = array_values(array_filter($banks, static function ($b) use ($q) {
                    return str_contains(strtolower((string)($b['title'] ?? '')), $q)
                        || str_contains(strtolower((string)($b['subject'] ?? '')), $q);
                }));
            }
            usort($banks, static function ($a, $b) use ($sortBy) {
                if ($sortBy === 'name') {
                    return strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
                }
                if ($sortBy === 'questions') {
                    return (int)($b['questions_count'] ?? 0) <=> (int)($a['questions_count'] ?? 0);
                }
                return strtotime((string)($b['updated_at'] ?? '1970-01-01')) <=> strtotime((string)($a['updated_at'] ?? '1970-01-01'));
            });
            $counts['question_banks'] = count($banks);
        }
    }
}

$qbCourseOptions = [];
foreach ($activeCourseNames as $cn) {
    $cn = trim((string)$cn);
    if ($cn !== '') {
        $qbCourseOptions[$cn] = $cn;
    }
}
ksort($qbCourseOptions, SORT_NATURAL | SORT_FLAG_CASE);

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
    'documents' => 'Documents',
    'banners' => 'Banners',
    'question_banks' => 'Question Banks',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Downloads — ATC Center | Gyanam India</title>
    <?php include __DIR__ . '/../includes/head_fonts.php'; ?>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <style>
        .filter-pills { display:flex; gap:.45rem; flex-wrap:wrap; margin-bottom:1rem; }
        .filter-pill {
            display:inline-flex; align-items:center; gap:.35rem;
            padding:.45rem .95rem; border-radius:999px; font-size:.8rem; font-weight:700;
            text-decoration:none; color:#64748b; background:#fff; border:1.5px solid #e5e7eb;
            transition:all .15s;
        }
        .filter-pill:hover { border-color:#c7d2fe; color:#4361ee; background:#f8faff; }
        .filter-pill.active { background:#4361ee; color:#fff; border-color:#4361ee; }
        .filter-pill .n {
            min-width:1.2rem; height:1.2rem; border-radius:999px; font-size:.68rem;
            display:inline-flex; align-items:center; justify-content:center;
            background:rgba(15,23,42,.08); padding:0 .3rem;
        }
        .filter-pill.active .n { background:rgba(255,255,255,.25); }
        .dl-err {
            background:#fef2f2; border:1px solid #fecaca; color:#b91c1c;
            border-radius:10px; padding:.75rem 1rem; font-size:.84rem; margin-bottom:1rem;
        }

        .dl-toolbar {
            display:flex; align-items:flex-start; justify-content:space-between;
            gap:1rem; flex-wrap:wrap; margin-bottom:1.15rem;
        }
        .dl-toolbar-title h3 {
            margin:0; font-size:1.05rem; font-weight:800; color:var(--text-primary,#0f1523);
            display:flex; align-items:center; gap:.5rem;
        }
        .dl-toolbar-title .badge-count {
            display:inline-flex; align-items:center; justify-content:center;
            min-width:1.5rem; height:1.5rem; padding:0 .45rem; border-radius:999px;
            background:#eef2ff; color:#4361ee; font-size:.72rem; font-weight:800;
        }
        .dl-toolbar-title p {
            margin:.25rem 0 0; font-size:.78rem; color:#94a3b8; font-weight:500;
        }
        .dl-filters {
            display:flex; flex-wrap:wrap; align-items:center; gap:.55rem;
            background:#fff; border:1.5px solid #e5e7eb; border-radius:14px;
            padding:.55rem .65rem; box-shadow:0 1px 2px rgba(15,23,42,.03);
        }
        .dl-select {
            height:40px; border:1.5px solid #e2e8f0; border-radius:10px;
            padding:0 .75rem; font-size:.82rem; font-weight:650; font-family:inherit;
            color:#334155; background:#f8fafc; outline:none; cursor:pointer;
            min-width:140px; max-width:220px;
        }
        .dl-select:focus { border-color:#4361ee; background:#fff; box-shadow:0 0 0 3px rgba(67,97,238,.12); }
        .dl-search {
            display:flex; align-items:center; gap:.45rem;
            height:40px; padding:0 .75rem 0 .85rem;
            border:1.5px solid #e2e8f0; border-radius:10px;
            background:#f8fafc; min-width:200px; flex:1;
        }
        .dl-search:focus-within { border-color:#4361ee; background:#fff; box-shadow:0 0 0 3px rgba(67,97,238,.12); }
        .dl-search svg { width:16px; height:16px; color:#94a3b8; flex-shrink:0; }
        .dl-search input {
            border:none; outline:none; background:transparent; width:100%;
            font-size:.84rem; font-family:inherit; color:#0f172a; font-weight:500;
        }
        .dl-search input::placeholder { color:#94a3b8; font-weight:500; }
        .dl-btn-search {
            height:40px; padding:0 1.1rem; border:none; border-radius:10px;
            background:linear-gradient(135deg,#4361ee,#6366f1);
            color:#fff; font-size:.82rem; font-weight:800; font-family:inherit;
            cursor:pointer; display:inline-flex; align-items:center; gap:.4rem;
            box-shadow:0 4px 12px rgba(67,97,238,.28); transition:transform .15s, box-shadow .15s;
            white-space:nowrap;
        }
        .dl-btn-search:hover { transform:translateY(-1px); box-shadow:0 6px 16px rgba(67,97,238,.35); }
        .dl-btn-search:active { transform:none; }
        .dl-btn-search svg { width:15px; height:15px; }
        .dl-btn-clear {
            height:40px; padding:0 .9rem; border:1.5px solid #e2e8f0; border-radius:10px;
            background:#fff; color:#64748b; font-size:.78rem; font-weight:700;
            text-decoration:none; display:inline-flex; align-items:center;
        }
        .dl-btn-clear:hover { border-color:#cbd5e1; color:#334155; background:#f8fafc; }
        @media (max-width:720px) {
            .dl-filters { width:100%; }
            .dl-search { min-width:0; width:100%; flex:1 1 100%; }
            .dl-select { max-width:none; flex:1; }
        }

        .documents-table thead th {
            text-transform:uppercase; font-size:.75rem; letter-spacing:.5px;
            font-weight:700; color:var(--text-secondary); padding:1rem;
        }
        .doc-file-info { display:flex; align-items:center; gap:1rem; }
        .doc-file-icon {
            width:40px; height:40px; flex-shrink:0; border-radius:var(--radius-md);
            background:linear-gradient(135deg, var(--primary-50), var(--primary-100));
            display:flex; align-items:center; justify-content:center;
        }
        .doc-file-icon svg { width:20px; height:20px; stroke:var(--primary-600); }
        .file-size-badge {
            display:inline-block; padding:.4rem .75rem; background:var(--gray-100);
            border-radius:var(--radius-md); font-size:.85rem; font-weight:600;
            font-family:'Courier New', monospace;
        }
        .btn-download-compact {
            display:inline-flex; align-items:center; gap:.5rem; padding:.55rem 1rem;
            background:linear-gradient(135deg,#10b981,#059669); color:#fff;
            border-radius:var(--radius-md); font-weight:600; font-size:.85rem; text-decoration:none;
        }
        .documents-table tbody tr:hover { background:var(--primary-50); }
        .documents-table tbody td { vertical-align:middle; }
        .qb-subject {
            display:inline-block; margin-top:.2rem; font-size:.75rem; font-weight:650;
            color:#4361ee; background:#eef2ff; padding:.15rem .5rem; border-radius:999px;
        }
        .qb-actions { display:flex; gap:.65rem; flex-wrap:wrap; }
        .qb-actions a { font-size:.82rem; font-weight:700; text-decoration:none; }
        .qb-actions .with-ans { color:#059669; }
        .qb-actions .practice { color:#6366f1; }
        .banner-row-thumb {
            width:72px; height:44px; border-radius:8px; object-fit:cover;
            background:#e2e8f0; border:1px solid #e5e7eb;
        }
        .banner-actions { display:flex; gap:.5rem; flex-wrap:wrap; }
        .banner-actions a { font-size:.82rem; font-weight:700; text-decoration:none; color:#4361ee; }
        .banner-actions a.dl { color:#059669; }
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
                    <p>Download available documents, banners and question banks</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">
            <div class="filter-pills">
                <?php foreach ($tabMeta as $key => $label): ?>
                <a class="filter-pill <?= $type === $key ? 'active' : '' ?>" href="documents.php?type=<?= urlencode($key) ?>">
                    <?= htmlspecialchars($label) ?>
                    <span class="n"><?= (int)$counts[$key] ?></span>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if ($error !== ''): ?>
                <div class="dl-err"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php
            $hasExtraFilters = ($searchTerm !== '')
                || ($courseFilter !== '' && $courseFilter !== 'all')
                || ($mediaFilter !== 'all')
                || ($sortBy !== 'updated' && $sortBy !== 'date');
            $toolbarHint = match ($type) {
                'question_banks' => 'Only banks for your activated courses',
                'banners' => 'Banners assigned to your centre',
                default => 'Shared documents from Head Office',
            };
            ?>
            <div class="dl-toolbar">
                <div class="dl-toolbar-title">
                    <h3>
                        <?= htmlspecialchars($tabMeta[$type]) ?>
                        <span class="badge-count"><?= (int)$counts[$type] ?></span>
                    </h3>
                    <p><?= htmlspecialchars($toolbarHint) ?></p>
                </div>
                <form method="GET" class="dl-filters" action="documents.php">
                    <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">

                    <?php if ($type === 'question_banks'): ?>
                    <select class="dl-select" name="course" onchange="this.form.submit()" title="Filter by course">
                        <option value="all" <?= $courseFilter === 'all' || $courseFilter === '' ? 'selected' : '' ?>>All courses</option>
                        <?php foreach ($qbCourseOptions as $cn): ?>
                        <option value="<?= htmlspecialchars($cn) ?>" <?= strcasecmp($courseFilter, $cn) === 0 ? 'selected' : '' ?>><?= htmlspecialchars($cn) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="dl-select" name="sort" onchange="this.form.submit()" title="Sort">
                        <option value="updated" <?= $sortBy === 'updated' ? 'selected' : '' ?>>Newest updated</option>
                        <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Name A–Z</option>
                        <option value="questions" <?= $sortBy === 'questions' ? 'selected' : '' ?>>Most questions</option>
                    </select>
                    <?php elseif ($type === 'banners'): ?>
                    <select class="dl-select" name="media" onchange="this.form.submit()" title="Media type">
                        <option value="all" <?= $mediaFilter === 'all' ? 'selected' : '' ?>>All media</option>
                        <option value="image" <?= $mediaFilter === 'image' ? 'selected' : '' ?>>Images only</option>
                        <option value="video" <?= $mediaFilter === 'video' ? 'selected' : '' ?>>Videos only</option>
                    </select>
                    <select class="dl-select" name="sort" onchange="this.form.submit()" title="Sort">
                        <option value="date" <?= in_array($sortBy, ['date', 'updated'], true) ? 'selected' : '' ?>>Newest first</option>
                        <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Title A–Z</option>
                    </select>
                    <?php else: ?>
                    <select class="dl-select" name="sort" onchange="this.form.submit()" title="Sort">
                        <option value="date" <?= in_array($sortBy, ['date', 'updated'], true) ? 'selected' : '' ?>>Newest first</option>
                        <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Name A–Z</option>
                        <option value="size" <?= $sortBy === 'size' ? 'selected' : '' ?>>Largest first</option>
                    </select>
                    <?php endif; ?>

                    <div class="dl-search">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" name="search" placeholder="<?= $type === 'question_banks' ? 'Search bank or course…' : 'Search…' ?>" value="<?= htmlspecialchars($searchTerm) ?>" autocomplete="off">
                    </div>
                    <button type="submit" class="dl-btn-search">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        Search
                    </button>
                    <?php if ($hasExtraFilters): ?>
                    <a class="dl-btn-clear" href="documents.php?type=<?= urlencode($type) ?>">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="table-card">
                <?php if ($type === 'documents'): ?>
                <table class="data-table documents-table">
                    <thead>
                        <tr>
                            <th style="width:80px">SR NO</th>
                            <th>FILENAME</th>
                            <th style="width:160px">UPLOADED DATE</th>
                            <th style="width:120px">FILE SIZE</th>
                            <th style="width:130px">ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($documents)): ?>
                        <tr>
                            <td colspan="5" class="table-empty">
                                <p>No documents available at the moment.</p>
                            </td>
                        </tr>
                    <?php else: foreach ($documents as $i => $doc): ?>
                        <tr>
                            <td style="text-align:center;font-weight:600;color:var(--text-secondary)"><?= $i + 1 ?></td>
                            <td>
                                <div class="doc-file-info">
                                    <div class="doc-file-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    </div>
                                    <div>
                                        <div class="cell-name"><?= htmlspecialchars($doc['original_name']) ?></div>
                                        <?php if (!empty($doc['description'])): ?>
                                        <div class="cell-sub"><?= htmlspecialchars(substr($doc['description'], 0, 70)) ?><?= strlen($doc['description']) > 70 ? '…' : '' ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td style="color:var(--text-secondary);font-size:.9rem"><?= date('d M Y', strtotime($doc['upload_date'])) ?></td>
                            <td><span class="file-size-badge"><?= formatFileSize($doc['file_size']) ?></span></td>
                            <td>
                                <a href="../<?= htmlspecialchars($doc['file_path']) ?>" download class="btn-download-compact">Download</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <?php elseif ($type === 'banners'): ?>
                <table class="data-table documents-table">
                    <thead>
                        <tr>
                            <th style="width:80px">SR NO</th>
                            <th style="width:100px">PREVIEW</th>
                            <th>TITLE</th>
                            <th style="width:140px">DATE</th>
                            <th style="width:160px">ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($banners)): ?>
                        <tr>
                            <td colspan="5" class="table-empty">
                                <p>No banners assigned to your centre yet.</p>
                            </td>
                        </tr>
                    <?php else: foreach ($banners as $i => $b):
                        $path = (string)($b['image_path'] ?? '');
                        $url = '../uploads/announcements/' . rawurlencode($path);
                        $isVideo = bannerIsVideo($path);
                        $title = (string)($b['title'] ?? 'Banner');
                        $created = !empty($b['created_at']) ? date('d M Y', strtotime($b['created_at'])) : '—';
                    ?>
                        <tr>
                            <td style="text-align:center;font-weight:600;color:var(--text-secondary)"><?= $i + 1 ?></td>
                            <td>
                                <?php if ($isVideo): ?>
                                    <video class="banner-row-thumb" src="<?= htmlspecialchars($url) ?>" muted preload="metadata"></video>
                                <?php else: ?>
                                    <img class="banner-row-thumb" src="<?= htmlspecialchars($url) ?>" alt="">
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="cell-name"><?= htmlspecialchars($title) ?></div>
                                <div class="cell-sub"><?= $isVideo ? 'Video' : 'Image' ?></div>
                            </td>
                            <td style="color:var(--text-secondary);font-size:.9rem"><?= htmlspecialchars($created) ?></td>
                            <td>
                                <div class="banner-actions">
                                    <a class="dl" href="<?= htmlspecialchars($url) ?>" download="<?= htmlspecialchars($path) ?>">Download</a>
                                    <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener">Open</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <?php else: ?>
                <table class="data-table documents-table">
                    <thead>
                        <tr>
                            <th style="width:80px">SR NO</th>
                            <th>QUESTION BANK</th>
                            <th style="width:110px">QUESTIONS</th>
                            <th style="width:130px">UPDATED</th>
                            <th style="width:200px">DOWNLOAD PDF</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($banks)): ?>
                        <tr>
                            <td colspan="5" class="table-empty">
                                <p>No question banks for your active courses. Activate a course under Courses, then check here again.</p>
                            </td>
                        </tr>
                    <?php else: foreach ($banks as $i => $bank):
                        $id = (int)($bank['id'] ?? 0);
                        $updated = !empty($bank['updated_at']) ? date('d M Y', strtotime((string)$bank['updated_at'])) : '—';
                        $dlQs = ['type' => 'question_banks', 'download' => $id];
                        if ($searchTerm !== '') $dlQs['search'] = $searchTerm;
                        if ($courseFilter !== '' && $courseFilter !== 'all') $dlQs['course'] = $courseFilter;
                        if ($sortBy !== 'updated') $dlQs['sort'] = $sortBy;
                        $dl = 'documents.php?' . http_build_query($dlQs);
                    ?>
                        <tr>
                            <td style="text-align:center;font-weight:600;color:var(--text-secondary)"><?= $i + 1 ?></td>
                            <td>
                                <div class="cell-name"><?= htmlspecialchars((string)($bank['title'] ?? 'Untitled')) ?></div>
                                <?php if (!empty($bank['subject'])): ?>
                                    <span class="qb-subject"><?= htmlspecialchars((string)$bank['subject']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)($bank['questions_count'] ?? 0) ?></td>
                            <td style="color:var(--text-secondary);font-size:.9rem"><?= htmlspecialchars($updated) ?></td>
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
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>
