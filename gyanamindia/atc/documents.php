<?php
/**
 * Gyanam Portal — ATC: Documents
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());

// Fetch active documents only
$searchTerm = $_GET['search'] ?? '';

$sql = "SELECT d.*, u.username as uploaded_by_name 
        FROM documents d 
        LEFT JOIN users u ON d.uploaded_by = u.id 
        WHERE d.status = 'Active'";
$params = [];

if ($searchTerm) {
    $sql .= " AND (d.original_name LIKE ? OR d.description LIKE ?)";
    $searchParam = "%$searchTerm%";
    $params = [$searchParam, $searchParam];
}

$sql .= " ORDER BY d.upload_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get count
$stmt = $pdo->query("SELECT COUNT(*) FROM documents WHERE status = 'Active'");
$totalCount = $stmt->fetchColumn();
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
                    <p>Download available documents and resources</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">
            
            <div class="page-toolbar">
                <h3>
                    Available Downloads
                    <span class="badge-count"><?= count($documents) ?></span>
                </h3>
                <form method="GET" style="display: flex; gap: 0.75rem;">
                    <div class="search-bar">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" name="search" placeholder="Search documents..." value="<?= htmlspecialchars($searchTerm) ?>">
                    </div>
                    <button type="submit" class="btn-primary" style="padding: 0 1.5rem;">Search</button>
                </form>
            </div>

            <div class="table-card">
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
            </div>
        </div>
    </main>
</div>

<script src="../assets/js/dashboard.js"></script>
<style>
/* Documents Table Styles */
.documents-table thead th {
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.5px;
    font-weight: 700;
    color: var(--text-secondary);
    padding: 1rem;
}

.doc-file-info {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.doc-file-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--primary-50), var(--primary-100));
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.doc-file-icon svg {
    width: 20px;
    height: 20px;
    stroke: var(--primary-600);
}

.doc-file-details {
    flex: 1;
    min-width: 0;
}

.file-size-badge {
    display: inline-block;
    padding: 0.4rem 0.75rem;
    background: var(--gray-100);
    color: var(--text-primary);
    border-radius: var(--radius-md);
    font-size: 0.85rem;
    font-weight: 600;
    font-family: 'Courier New', monospace;
}

.btn-download-compact {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.6rem 1rem;
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    border: none;
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 0.85rem;
    text-decoration: none;
    transition: all 0.2s ease;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.btn-download-compact:hover {
    background: linear-gradient(135deg, #059669, #047857);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
}

.btn-download-compact svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}

.documents-table tbody tr {
    transition: all 0.2s ease;
}

.documents-table tbody tr:hover {
    background: var(--primary-50);
}

.documents-table tbody td {
    vertical-align: middle;
}
</style>
</body>
</html>

<?php
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}
?>
