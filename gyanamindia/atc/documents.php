<?php
/**
 * Gyanam Portal — ATC: Downloads (Documents + Question Banks)
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/exam_integration.php';

requireLogin(['ATC CENTER']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());

$type = strtolower(trim((string)($_GET['type'] ?? 'documents')));
if (!in_array($type, ['documents', 'question_banks'], true)) {
    $type = 'documents';
}

$searchTerm = trim((string)($_GET['search'] ?? ''));
$error = isset($_GET['err']) ? sanitize((string)$_GET['err']) : '';
$atcCode = examPortalAtcCodeFromSession($pdo);

// Question bank PDF download (same page)
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
$banks = [];
$docTotal = 0;

if ($type === 'documents') {
    $sql = "SELECT d.*, u.username as uploaded_by_name
            FROM documents d
            LEFT JOIN users u ON d.uploaded_by = u.id
            WHERE d.status = 'Active'";
    $params = [];
    if ($searchTerm !== '') {
        $sql .= ' AND (d.original_name LIKE ? OR d.description LIKE ?)';
        $searchParam = '%' . $searchTerm . '%';
        $params = [$searchParam, $searchParam];
    }
    $sql .= ' ORDER BY d.upload_date DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $docTotal = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE status = 'Active'")->fetchColumn();
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
        }
    }
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' bytes';
}
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
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📄</text></svg>">
    <style>
        .dl-tabs {
            display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1rem;
        }
        .dl-tab {
            display: inline-flex; align-items: center; gap: .4rem;
            padding: .55rem 1rem; border-radius: 999px; font-size: .82rem; font-weight: 700;
            text-decoration: none; border: 1.5px solid #e5e7eb; background: #fff; color: #475569;
        }
        .dl-tab.active {
            background: linear-gradient(135deg, #4361ee, #3730a3); color: #fff; border-color: transparent;
            box-shadow: 0 3px 12px rgba(67, 97, 238, .25);
        }
        .dl-tab .count {
            min-width: 1.35rem; height: 1.35rem; padding: 0 .35rem; border-radius: 999px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: .72rem; background: rgba(15,23,42,.08);
        }
        .dl-tab.active .count { background: rgba(255,255,255,.22); }
        .qb-hint {
            background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af;
            border-radius: 12px; padding: .85rem 1rem; font-size: .84rem; margin-bottom: 1rem;
        }
        .qb-err {
            background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c;
            border-radius: 12px; padding: .85rem 1rem; font-size: .84rem; margin-bottom: 1rem;
        }
        .qb-subject {
            display: inline-block; margin-top: .2rem; font-size: .75rem; font-weight: 650;
            color: #4361ee; background: #eef2ff; padding: .15rem .5rem; border-radius: 999px;
        }
        .qb-actions { display: flex; gap: .45rem; flex-wrap: wrap; }
        .qb-actions a {
            display: inline-flex; align-items: center; gap: .35rem;
            font-size: .8rem; font-weight: 700; text-decoration: none;
        }
        .qb-actions .with-ans { color: #059669; }
        .qb-actions .practice { color: #6366f1; }
        .documents-table thead th {
            text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px;
            font-weight: 700; color: var(--text-secondary); padding: 1rem;
        }
        .doc-file-info { display: flex; align-items: center; gap: 1rem; }
        .doc-file-icon {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, var(--primary-50), var(--primary-100));
            border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .doc-file-icon svg { width: 20px; height: 20px; stroke: var(--primary-600); }
        .doc-file-details { flex: 1; min-width: 0; }
        .file-size-badge {
            display: inline-block; padding: 0.4rem 0.75rem; background: var(--gray-100);
            color: var(--text-primary); border-radius: var(--radius-md); font-size: 0.85rem;
            font-weight: 600; font-family: 'Courier New', monospace;
        }
        .btn-download-compact {
            display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.6rem 1rem;
            background: linear-gradient(135deg, #10b981, #059669); color: white; border: none;
            border-radius: var(--radius-md); font-weight: 600; font-size: 0.85rem; text-decoration: none;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }
        .btn-download-compact:hover {
            background: linear-gradient(135deg, #059669, #047857); transform: translateY(-1px);
        }
        .btn-download-compact svg { width: 16px; height: 16px; flex-shrink: 0; }
        .documents-table tbody tr:hover { background: var(--primary-50); }
        .documents-table tbody td { vertical-align: middle; }
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
                    <h2>Downloads</h2>
                    <p>Documents and assigned question bank PDFs</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">

            <div class="dl-tabs">
                <a class="dl-tab <?= $type === 'documents' ? 'active' : '' ?>" href="documents.php?type=documents<?= $searchTerm !== '' && $type === 'documents' ? '&search=' . urlencode($searchTerm) : '' ?>">
                    Documents
                    <?php if ($type === 'documents'): ?><span class="count"><?= count($documents) ?></span><?php endif; ?>
                </a>
                <a class="dl-tab <?= $type === 'question_banks' ? 'active' : '' ?>" href="documents.php?type=question_banks">
                    Question Banks
                    <?php if ($type === 'question_banks'): ?><span class="count"><?= count($banks) ?></span><?php endif; ?>
                </a>
            </div>

            <?php if ($type === 'question_banks'): ?>
                <div class="qb-hint">
                    Question banks are created and assigned by Head Office from the Exam Portal.
                    Only banks assigned to your ATC code<?= $atcCode !== '' ? ' (<strong>' . htmlspecialchars($atcCode) . '</strong>)' : '' ?> appear here.
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="qb-err"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="page-toolbar">
                <h3>
                    <?= $type === 'question_banks' ? 'Assigned Question Banks' : 'Available Downloads' ?>
                    <span class="badge-count"><?= $type === 'question_banks' ? count($banks) : count($documents) ?></span>
                </h3>
                <form method="GET" style="display: flex; gap: 0.75rem;">
                    <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                    <div class="search-bar">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" name="search" placeholder="<?= $type === 'question_banks' ? 'Search title or course…' : 'Search documents...' ?>" value="<?= htmlspecialchars($searchTerm) ?>">
                    </div>
                    <button type="submit" class="btn-primary" style="padding: 0 1.5rem;">Search</button>
                </form>
            </div>

            <div class="table-card">
                <?php if ($type === 'documents'): ?>
                <table class="data-table documents-table">
                    <thead>
                        <tr>
                            <th style="width: 80px;">SR NO</th>
                            <th style="width: 45%;">FILENAME</th>
                            <th style="width: 25%;">UPLOADED DATE</th>
                            <th style="width: 15%;">FILE SIZE</th>
                            <th style="width: 15%;">ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($documents)): ?>
                            <tr>
                                <td colspan="5" class="table-empty">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    <p>No documents available at the moment.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($documents as $index => $doc): ?>
                                <tr>
                                    <td style="text-align: center; font-weight: 600; color: var(--text-secondary);"><?= $index + 1 ?></td>
                                    <td>
                                        <div class="doc-file-info">
                                            <div class="doc-file-icon">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                            </div>
                                            <div class="doc-file-details">
                                                <div class="cell-name"><?= htmlspecialchars($doc['original_name']) ?></div>
                                                <?php if ($doc['description']): ?>
                                                    <div class="cell-sub"><?= htmlspecialchars(substr($doc['description'], 0, 70)) ?><?= strlen($doc['description']) > 70 ? '...' : '' ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="color: var(--text-secondary); font-size: 0.9rem;"><?= date('d M Y, h:i A', strtotime($doc['upload_date'])) ?></td>
                                    <td>
                                        <span class="file-size-badge"><?= formatFileSize($doc['file_size']) ?></span>
                                    </td>
                                    <td>
                                        <a href="../<?= $doc['file_path'] ?>" download class="btn-download-compact">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                            Download
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <table class="data-table documents-table">
                    <thead>
                        <tr>
                            <th style="width: 70px;">SR</th>
                            <th>QUESTION BANK</th>
                            <th style="width: 120px;">QUESTIONS</th>
                            <th style="width: 140px;">UPDATED</th>
                            <th style="width: 220px;">DOWNLOAD PDF</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($banks)): ?>
                            <tr>
                                <td colspan="5" class="table-empty">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                                    <p>No question banks assigned to your centre yet.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($banks as $index => $bank):
                                $id = (int)($bank['id'] ?? 0);
                                $updated = !empty($bank['updated_at'])
                                    ? date('d M Y', strtotime((string)$bank['updated_at']))
                                    : '—';
                                $dlBase = 'documents.php?type=question_banks&download=' . $id;
                            ?>
                                <tr>
                                    <td style="text-align:center;font-weight:600;color:var(--text-secondary)"><?= $index + 1 ?></td>
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
                                            <a class="with-ans" href="<?= htmlspecialchars($dlBase) ?>" title="PDF with answer key">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                                With answers
                                            </a>
                                            <a class="practice" href="<?= htmlspecialchars($dlBase . '&answers=0') ?>" title="Practice PDF without answers">
                                                Practice
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
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
