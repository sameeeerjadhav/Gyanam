<?php
/**
 * Gyanam Portal — ATC: Question Banks (PDF downloads from Exam Portal)
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/exam_integration.php';

requireLogin(['ATC CENTER']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$atcCode = examPortalAtcCodeFromSession($pdo);
$error = '';
$banks = [];

// PDF download
if (isset($_GET['download'])) {
    $bankId = (int)$_GET['download'];
    $withAnswers = !isset($_GET['answers']) || $_GET['answers'] !== '0';
    if ($atcCode === '') {
        header('Location: question_banks.php?err=' . urlencode('ATC code not found'));
        exit;
    }
    $export = fetchQuestionBankExport($atcCode, $bankId, $withAnswers);
    if (!$export['success'] || empty($export['data'])) {
        header('Location: question_banks.php?err=' . urlencode($export['error'] ?? 'Download failed'));
        exit;
    }
    try {
        streamQuestionBankPdf($export['data'], $atcCode);
    } catch (Throwable $e) {
        header('Location: question_banks.php?err=' . urlencode('PDF generation failed'));
        exit;
    }
}

if (isset($_GET['err'])) {
    $error = sanitize((string)$_GET['err']);
}

$searchTerm = trim((string)($_GET['search'] ?? ''));

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Question Banks — ATC Center | Gyanam India</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <style>
        .qb-hint {
            background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af;
            border-radius: 12px; padding: .85rem 1rem; font-size: .84rem; margin-bottom: 1rem;
        }
        .qb-err {
            background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c;
            border-radius: 12px; padding: .85rem 1rem; font-size: .84rem; margin-bottom: 1rem;
        }
        .qb-actions { display: flex; gap: .4rem; flex-wrap: wrap; }
        .qb-actions .btn-act { text-decoration: none; }
        .qb-subject {
            display: inline-block; margin-top: .2rem; font-size: .75rem; font-weight: 650;
            color: #4361ee; background: #eef2ff; padding: .15rem .5rem; border-radius: 999px;
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
                    <h2>Question Banks</h2>
                    <p>Download assigned practice banks as PDF</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">
            <div class="qb-hint">
                Question banks are created and assigned by Head Office from the Exam Portal.
                Only banks assigned to your ATC code<?= $atcCode !== '' ? ' (<strong>' . htmlspecialchars($atcCode) . '</strong>)' : '' ?> appear here.
            </div>

            <?php if ($error !== ''): ?>
                <div class="qb-err"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="page-toolbar">
                <h3>
                    Assigned Banks
                    <span class="badge-count"><?= count($banks) ?></span>
                </h3>
                <form method="GET" style="display: flex; gap: 0.75rem;">
                    <div class="search-bar">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" name="search" placeholder="Search title or course…" value="<?= htmlspecialchars($searchTerm) ?>">
                    </div>
                    <button type="submit" class="btn-primary" style="padding: 0 1.5rem;">Search</button>
                </form>
            </div>

            <div class="table-card">
                <table class="data-table documents-table">
                    <thead>
                        <tr>
                            <th style="width: 70px;">SR</th>
                            <th>QUESTION BANK</th>
                            <th style="width: 120px;">QUESTIONS</th>
                            <th style="width: 160px;">UPDATED</th>
                            <th style="width: 220px;">DOWNLOAD PDF</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($banks)): ?>
                            <tr>
                                <td colspan="5" class="table-empty">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    <p>No question banks assigned to your centre yet.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($banks as $index => $bank):
                                $id = (int)($bank['id'] ?? 0);
                                $updated = !empty($bank['updated_at'])
                                    ? date('d M Y', strtotime((string)$bank['updated_at']))
                                    : '—';
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
                                            <a class="btn-act" style="color:#059669" href="question_banks.php?download=<?= $id ?>" title="PDF with answer key">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                                With answers
                                            </a>
                                            <a class="btn-act" href="question_banks.php?download=<?= $id ?>&answers=0" title="Practice PDF without answers">
                                                Practice
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>
