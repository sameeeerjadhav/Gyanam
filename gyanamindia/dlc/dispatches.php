<?php
/**
 * Gyanam Portal — DLC: Dispatch Management
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['DLC Office']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$userId = $_SESSION['user_id'] ?? null;
$dlcId = $_SESSION['dlc_id'] ?? null;

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'forward':
                // Update dispatch with ATC info
                $stmt = $pdo->prepare("
                    UPDATE dispatches 
                    SET atc_id = ?, dlc_remarks = ?, status = 'Forwarded to ATC', dlc_forwarded_date = NOW()
                    WHERE id = ? AND dlc_id = ?
                ");
                $stmt->execute([
                    $_POST['atc_id'],
                    $_POST['dlc_remarks'],
                    $_POST['dispatch_id'],
                    $dlcId
                ]);
                
                // Add to history
                $stmt = $pdo->prepare("
                    INSERT INTO dispatch_history (dispatch_id, status, remarks, updated_by, updated_by_role)
                    VALUES (?, 'Forwarded to ATC', ?, ?, 'DLC Office')
                ");
                $stmt->execute([$_POST['dispatch_id'], $_POST['dlc_remarks'], $userId]);
                
                echo json_encode(['success' => true, 'message' => 'Dispatch forwarded to ATC successfully']);
                exit;
                
            case 'get':
                $stmt = $pdo->prepare("
                    SELECT d.*, dlc.name as dlc_name, atc.name as atc_name,
                           u.username as created_by_name
                    FROM dispatches d
                    LEFT JOIN dlc_offices dlc ON d.dlc_id = dlc.id
                    LEFT JOIN atc_centers atc ON d.atc_id = atc.id
                    LEFT JOIN users u ON d.created_by = u.id
                    WHERE d.id = ? AND d.dlc_id = ?
                ");
                $stmt->execute([$_POST['id'], $dlcId]);
                $dispatch = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Get history
                $stmt = $pdo->prepare("
                    SELECT h.*, u.username as updated_by_name
                    FROM dispatch_history h
                    LEFT JOIN users u ON h.updated_by = u.id
                    WHERE h.dispatch_id = ?
                    ORDER BY h.updated_at DESC
                ");
                $stmt->execute([$_POST['id']]);
                $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $dispatch, 'history' => $history]);
                exit;
                
            case 'get_atc_list':
                $stmt = $pdo->prepare("
                    SELECT id, name, district, state 
                    FROM atc_centers 
                    WHERE dlc_id = ? AND status = 'Active'
                    ORDER BY name
                ");
                $stmt->execute([$dlcId]);
                $atcList = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $atcList]);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Fetch dispatches for this DLC
$searchTerm = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? 'all';

$sql = "SELECT d.*, dlc.name as dlc_name, atc.name as atc_name
        FROM dispatches d
        LEFT JOIN dlc_offices dlc ON d.dlc_id = dlc.id
        LEFT JOIN atc_centers atc ON d.atc_id = atc.id
        WHERE d.dlc_id = ?";
$params = [$dlcId];

if ($searchTerm) {
    $sql .= " AND (d.dispatch_no LIKE ? OR atc.name LIKE ? OR d.tracking_number LIKE ?)";
    $searchParam = "%$searchTerm%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam]);
}

if ($statusFilter !== 'all') {
    $sql .= " AND d.status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY d.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$dispatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts
$stmt = $pdo->prepare("SELECT COUNT(*) FROM dispatches WHERE dlc_id = ?");
$stmt->execute([$dlcId]);
$totalCount = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM dispatches WHERE dlc_id = ? AND status = 'Sent to DLC'");
$stmt->execute([$dlcId]);
$pendingCount = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM dispatches WHERE dlc_id = ? AND status = 'Forwarded to ATC'");
$stmt->execute([$dlcId]);
$forwardedCount = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM dispatches WHERE dlc_id = ? AND status = 'Delivered'");
$stmt->execute([$dlcId]);
$deliveredCount = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispatches — DLC Office | Gyanam India</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📦</text></svg>">
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
                    <h2>Dispatch Management</h2>
                    <p>Forward dispatches to ATC centers</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">
            
            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #6366f1, #8b5cf6);">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 0 0-1.172-2.872L3 3"/><path d="m15 9 6-6"/><path d="M3 21h18"/></svg>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">Total Dispatches</div>
                        <div class="stat-value"><?= $totalCount ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">Pending Action</div>
                        <div class="stat-value"><?= $pendingCount ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">Forwarded</div>
                        <div class="stat-value"><?= $forwardedCount ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">Delivered</div>
                        <div class="stat-value"><?= $deliveredCount ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Status Filter Tabs -->
            <div class="status-tabs">
                <a href="?status=all<?= $searchTerm ? '&search=' . urlencode($searchTerm) : '' ?>" class="status-tab <?= $statusFilter === 'all' ? 'active' : '' ?>">
                    <span class="tab-label">All</span>
                    <span class="tab-count"><?= $totalCount ?></span>
                </a>
                <a href="?status=Sent to DLC<?= $searchTerm ? '&search=' . urlencode($searchTerm) : '' ?>" class="status-tab <?= $statusFilter === 'Sent to DLC' ? 'active' : '' ?>">
                    <span class="tab-label">Pending</span>
                    <span class="tab-count badge-pending"><?= $pendingCount ?></span>
                </a>
                <a href="?status=Forwarded to ATC<?= $searchTerm ? '&search=' . urlencode($searchTerm) : '' ?>" class="status-tab <?= $statusFilter === 'Forwarded to ATC' ? 'active' : '' ?>">
                    <span class="tab-label">Forwarded</span>
                    <span class="tab-count badge-info"><?= $forwardedCount ?></span>
                </a>
                <a href="?status=Delivered<?= $searchTerm ? '&search=' . urlencode($searchTerm) : '' ?>" class="status-tab <?= $statusFilter === 'Delivered' ? 'active' : '' ?>">
                    <span class="tab-label">Delivered</span>
                    <span class="tab-count badge-success"><?= $deliveredCount ?></span>
                </a>
            </div>
            
            <div class="page-toolbar">
                <h3>
                    Dispatch List
                    <span class="badge-count"><?= count($dispatches) ?></span>
                </h3>
                <form method="GET" style="display: flex; gap: 0.75rem; align-items: center;">
                    <input type="hidden" name="status" value="<?= $statusFilter ?>">
                    <div class="search-bar">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" name="search" placeholder="Search dispatches..." value="<?= htmlspecialchars($searchTerm) ?>">
                    </div>
                    <button type="submit" class="btn-primary" style="padding: 0 1.5rem;">Search</button>
                </form>
            </div>

            <div class="table-card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Dispatch No.</th>
                            <th>Material</th>
                            <th>Quantity</th>
                            <th>Tracking</th>
                            <th>Dispatch Date</th>
                            <th>ATC Center</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dispatches)): ?>
                            <tr>
                                <td colspan="8" class="table-empty">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 0 0-1.172-2.872L3 3"/><path d="m15 9 6-6"/><path d="M3 21h18"/></svg>
                                    <p>No dispatches found.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($dispatches as $dispatch): ?>
                                <tr>
                                    <td>
                                        <div class="cell-name"><?= htmlspecialchars($dispatch['dispatch_no']) ?></div>
                                        <div class="cell-sub"><?= date('d M Y', strtotime($dispatch['created_at'])) ?></div>
                                    </td>
                                    <td>
                                        <div class="cell-name"><?= htmlspecialchars($dispatch['material_type']) ?></div>
                                        <div class="cell-sub"><?= htmlspecialchars(substr($dispatch['description'], 0, 40)) ?><?= strlen($dispatch['description']) > 40 ? '...' : '' ?></div>
                                    </td>
                                    <td><strong><?= $dispatch['quantity'] ?></strong></td>
                                    <td>
                                        <?php if ($dispatch['tracking_number']): ?>
                                            <div class="cell-name"><?= htmlspecialchars($dispatch['tracking_number']) ?></div>
                                            <div class="cell-sub"><?= htmlspecialchars($dispatch['courier_name']) ?></div>
                                        <?php else: ?>
                                            <span class="cell-sub">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($dispatch['dispatch_date']): ?>
                                            <?= date('d M Y', strtotime($dispatch['dispatch_date'])) ?>
                                        <?php else: ?>
                                            <span class="cell-sub">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($dispatch['atc_name']): ?>
                                            <div class="cell-name"><?= htmlspecialchars($dispatch['atc_name']) ?></div>
                                        <?php else: ?>
                                            <span class="cell-sub">Not assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $statusClass = match($dispatch['status']) {
                                            'Sent to DLC' => 'status-pending',
                                            'Forwarded to ATC' => 'status-info',
                                            'Delivered' => 'status-success',
                                            'Cancelled' => 'status-cancelled',
                                            default => 'status-pending'
                                        };
                                        ?>
                                        <span class="cell-badge <?= $statusClass ?>">
                                            <?= $dispatch['status'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="cell-actions">
                                            <?php if ($dispatch['status'] === 'Sent to DLC'): ?>
                                                <button class="btn-icon" onclick="forwardToATC(<?= $dispatch['id'] ?>, '<?= htmlspecialchars($dispatch['dispatch_no'], ENT_QUOTES) ?>')" title="Forward to ATC">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn-icon" onclick="viewDetails(<?= $dispatch['id'] ?>)" title="View Details">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            </button>
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


<!-- Forward to ATC Modal -->
<div class="modal-overlay" id="forwardModal">
    <div class="modal-card">
        <div class="modal-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 22px; height: 22px;"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                <span>Forward Dispatch to ATC</span>
            </h3>
            <button type="button" class="modal-close" onclick="closeForwardModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        
        <form id="forwardForm" novalidate>
            <input type="hidden" id="dispatchId" name="dispatch_id">
            <input type="hidden" name="action" value="forward">
            
            <div class="modal-body">
                
                <!-- Dispatch Info -->
                <div class="info-banner" style="background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; padding: 1rem; border-radius: var(--radius-md); margin-bottom: 1.5rem;">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 24px; height: 24px;"><path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 0 0-1.172-2.872L3 3"/><path d="m15 9 6-6"/><path d="M3 21h18"/></svg>
                        <div>
                            <div style="font-weight: 600; font-size: 1.1rem;" id="dispatchNoDisplay">—</div>
                            <div style="opacity: 0.9; font-size: 0.9rem;">Select ATC center to forward this dispatch</div>
                        </div>
                    </div>
                </div>
                
                <!-- ATC Selection Section -->
                <div class="form-section">
                    <div class="form-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                        ATC Center Selection
                    </div>
                    
                    <div class="profile-form-grid">
                        <div class="form-field full-width">
                            <label for="atc_id">
                                Select ATC Center <span class="required">*</span>
                            </label>
                            <div class="input-with-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                                <select id="atc_id" name="atc_id" required>
                                    <option value="">-- Select ATC Center --</option>
                                </select>
                            </div>
                            <small class="field-hint">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 14px; height: 14px;"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                                Choose the ATC center where this dispatch should be sent
                            </small>
                        </div>
                    </div>
                </div>
                
                <!-- Remarks Section -->
                <div class="form-section">
                    <div class="form-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        DLC Remarks
                    </div>
                    
                    <div class="profile-form-grid">
                        <div class="form-field full-width">
                            <label for="dlc_remarks">
                                Add Remarks <span class="required">*</span>
                            </label>
                            <textarea 
                                id="dlc_remarks" 
                                name="dlc_remarks" 
                                rows="4"
                                placeholder="Add any special instructions or remarks for the ATC center..."
                                required
                            ></textarea>
                            <small class="field-hint">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 14px; height: 14px;"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                                Include handling instructions, priority level, or any other relevant information
                            </small>
                        </div>
                    </div>
                </div>
                
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeForwardModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    Cancel
                </button>
                <button type="submit" class="btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px;"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    Forward to ATC
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Details Modal -->
<div class="modal-overlay" id="detailsModal">
    <div class="modal-card" style="max-width: 800px;">
        <div class="modal-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 22px; height: 22px;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <span>Dispatch Details</span>
            </h3>
            <button type="button" class="modal-close" onclick="closeDetailsModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        
        <div class="modal-body" id="detailsContent">
            <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 48px; height: 48px; margin: 0 auto;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="6" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <p>Loading details...</p>
            </div>
        </div>
        
        <div class="modal-footer">
            <button type="button" class="btn-secondary" onclick="closeDetailsModal()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                Close
            </button>
        </div>
    </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
// Forward to ATC
function forwardToATC(id, dispatchNo) {
    document.getElementById('dispatchId').value = id;
    document.getElementById('dispatchNoDisplay').textContent = dispatchNo;
    
    // Load ATC centers
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_atc_list'
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const select = document.getElementById('atc_id');
            select.innerHTML = '<option value="">-- Select ATC Center --</option>';
            data.data.forEach(atc => {
                select.innerHTML += `<option value="${atc.id}">${atc.name} - ${atc.district}, ${atc.state}</option>`;
            });
        }
    });
    
    document.getElementById('forwardModal').classList.add('active');
}

function closeForwardModal() {
    document.getElementById('forwardModal').classList.remove('active');
    document.getElementById('forwardForm').reset();
}

// Handle forward form submission
document.getElementById('forwardForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px; animation: spin 1s linear infinite;"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> Processing...';
    
    fetch('', {
        method: 'POST',
        body: new URLSearchParams(formData)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + data.message);
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px;"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg> Forward to ATC';
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px;"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg> Forward to ATC';
    });
});

// View details
function viewDetails(id) {
    document.getElementById('detailsModal').classList.add('active');
    
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=get&id=${id}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const d = data.data;
            const history = data.history;
            
            let statusClass = '';
            switch(d.status) {
                case 'Sent to DLC': statusClass = 'status-pending'; break;
                case 'Forwarded to ATC': statusClass = 'status-info'; break;
                case 'Delivered': statusClass = 'status-success'; break;
                case 'Cancelled': statusClass = 'status-cancelled'; break;
            }
            
            let html = `
                <div class="form-section">
                    <div class="form-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 0 0-1.172-2.872L3 3"/><path d="m15 9 6-6"/><path d="M3 21h18"/></svg>
                        Dispatch Information
                    </div>
                    <div class="profile-form-grid">
                        <div class="form-field">
                            <label>Dispatch Number</label>
                            <div style="font-weight: 600; color: var(--primary-600);">${d.dispatch_no}</div>
                        </div>
                        <div class="form-field">
                            <label>Status</label>
                            <span class="cell-badge ${statusClass}">${d.status}</span>
                        </div>
                        <div class="form-field">
                            <label>Material Type</label>
                            <div>${d.material_type}</div>
                        </div>
                        <div class="form-field">
                            <label>Quantity</label>
                            <div><strong>${d.quantity}</strong></div>
                        </div>
                        <div class="form-field full-width">
                            <label>Description</label>
                            <div>${d.description || '—'}</div>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <div class="form-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Tracking Information
                    </div>
                    <div class="profile-form-grid">
                        <div class="form-field">
                            <label>Tracking Number</label>
                            <div>${d.tracking_number || '—'}</div>
                        </div>
                        <div class="form-field">
                            <label>Courier Name</label>
                            <div>${d.courier_name || '—'}</div>
                        </div>
                        <div class="form-field">
                            <label>Dispatch Date</label>
                            <div>${d.dispatch_date ? new Date(d.dispatch_date).toLocaleDateString('en-IN') : '—'}</div>
                        </div>
                        <div class="form-field">
                            <label>Expected Delivery</label>
                            <div>${d.expected_delivery ? new Date(d.expected_delivery).toLocaleDateString('en-IN') : '—'}</div>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <div class="form-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg>
                        Destination
                    </div>
                    <div class="profile-form-grid">
                        <div class="form-field">
                            <label>DLC Office</label>
                            <div>${d.dlc_name}</div>
                        </div>
                        <div class="form-field">
                            <label>ATC Center</label>
                            <div>${d.atc_name || '<span class="cell-sub">Not assigned yet</span>'}</div>
                        </div>
                    </div>
                </div>
                
                ${d.admin_remarks || d.dlc_remarks ? `
                <div class="form-section">
                    <div class="form-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        Remarks
                    </div>
                    <div class="profile-form-grid">
                        ${d.admin_remarks ? `
                        <div class="form-field full-width">
                            <label>Admin Remarks</label>
                            <div style="padding: 0.75rem; background: var(--bg-surface); border-radius: var(--radius-md); border: 1px solid var(--border-color);">${d.admin_remarks}</div>
                        </div>
                        ` : ''}
                        ${d.dlc_remarks ? `
                        <div class="form-field full-width">
                            <label>DLC Remarks</label>
                            <div style="padding: 0.75rem; background: var(--bg-surface); border-radius: var(--radius-md); border: 1px solid var(--border-color);">${d.dlc_remarks}</div>
                        </div>
                        ` : ''}
                    </div>
                </div>
                ` : ''}
                
                ${history.length > 0 ? `
                <div class="form-section">
                    <div class="form-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        History
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        ${history.map(h => `
                            <div style="padding: 0.75rem; background: var(--bg-surface); border-radius: var(--radius-md); border-left: 3px solid var(--primary-500);">
                                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                                    <span class="cell-badge ${h.status === 'Delivered' ? 'status-success' : h.status === 'Forwarded to ATC' ? 'status-info' : 'status-pending'}">${h.status}</span>
                                    <span class="cell-sub">${new Date(h.updated_at).toLocaleString('en-IN')}</span>
                                </div>
                                ${h.remarks ? `<div style="color: var(--text-secondary); font-size: 0.9rem;">${h.remarks}</div>` : ''}
                                <div class="cell-sub" style="margin-top: 0.5rem;">By: ${h.updated_by_name} (${h.updated_by_role})</div>
                            </div>
                        `).join('')}
                    </div>
                </div>
                ` : ''}
            `;
            
            document.getElementById('detailsContent').innerHTML = html;
        } else {
            document.getElementById('detailsContent').innerHTML = `
                <div style="text-align: center; padding: 2rem; color: var(--danger-600);">
                    <p>Error loading details: ${data.message}</p>
                </div>
            `;
        }
    })
    .catch(err => {
        document.getElementById('detailsContent').innerHTML = `
            <div style="text-align: center; padding: 2rem; color: var(--danger-600);">
                <p>Error: ${err.message}</p>
            </div>
        `;
    });
}

function closeDetailsModal() {
    document.getElementById('detailsModal').classList.remove('active');
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
        }
    });
});
</script>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    border-radius: var(--radius-lg);
    padding: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid var(--border-color);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: var(--radius-lg);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.stat-icon svg {
    width: 28px;
    height: 28px;
    stroke: white;
}

.stat-content {
    flex: 1;
}

.stat-label {
    font-size: 0.85rem;
    color: var(--text-secondary);
    margin-bottom: 0.25rem;
}

.stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--text-primary);
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</body>
</html>
