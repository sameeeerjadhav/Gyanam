<?php
/**
 * Gyanam Portal — Admin: Duplicate Certificate Requests
 * Admin reviews and approves/rejects ATC requests.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
if (file_exists(__DIR__ . '/../includes/notifications.php'))
    require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);
$pdo = getDBConnection();

// ── Ensure table exists ───────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `duplicate_cert_requests` (
        `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `atc_id`       INT NOT NULL,
        `admission_id` INT NOT NULL,
        `student_name` VARCHAR(200) NOT NULL,
        `roll_no`      VARCHAR(50) DEFAULT NULL,
        `course`       VARCHAR(200) DEFAULT NULL,
        `cert_type`    ENUM('Course Completion Certificate','Exam Certificate') NOT NULL,
        `reason`       ENUM('Name Correction','Misplaced by Student','Damaged') NOT NULL,
        `remarks`      TEXT DEFAULT NULL,
        `status`       ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
        `admin_note`   TEXT DEFAULT NULL,
        `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `reviewed_at`  DATETIME DEFAULT NULL,
        `reviewed_by`  INT DEFAULT NULL,
        INDEX `idx_atc` (`atc_id`),
        INDEX `idx_admission` (`admission_id`),
        INDEX `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// ── AJAX Handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        switch ($_POST['action']) {
            case 'review':
                $reqId     = intval($_POST['req_id'] ?? 0);
                $newStatus = $_POST['status'] ?? '';
                $adminNote = trim($_POST['admin_note'] ?? '');

                if (!$reqId || !in_array($newStatus, ['Approved', 'Rejected'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid data.']);
                    exit;
                }
                $stmt = $pdo->prepare("UPDATE duplicate_cert_requests SET status=?, admin_note=?, reviewed_at=NOW(), reviewed_by=? WHERE id=? AND status='Pending'");
                $stmt->execute([$newStatus, $adminNote ?: null, $_SESSION['user_id'], $reqId]);
                if ($stmt->rowCount() === 0) {
                    echo json_encode(['success' => false, 'message' => 'Request not found or already reviewed.']);
                    exit;
                }
                echo json_encode(['success' => true, 'message' => "Request $newStatus successfully."]);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ── Filters ──────────────────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? 'Pending';
$filterAtc    = intval($_GET['atc_id'] ?? 0);
$search       = trim($_GET['q'] ?? '');

$where  = '1=1';
$params = [];
if (in_array($filterStatus, ['Pending','Approved','Rejected'])) {
    $where .= ' AND r.status = ?'; $params[] = $filterStatus;
}
if ($filterAtc) {
    $where .= ' AND r.atc_id = ?'; $params[] = $filterAtc;
}
if ($search) {
    $where .= ' AND (r.student_name LIKE ? OR r.roll_no LIKE ? OR r.course LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

$requests = [];
try {
    $q = $pdo->prepare("
        SELECT r.*, a.name AS atc_name
        FROM duplicate_cert_requests r
        LEFT JOIN atc_centers a ON a.id = r.atc_id
        WHERE $where
        ORDER BY r.requested_at DESC
        LIMIT 500
    ");
    $q->execute($params);
    $requests = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Counts for tab badges
$counts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
try {
    $cs = $pdo->query("SELECT status, COUNT(*) as n FROM duplicate_cert_requests GROUP BY status");
    foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($counts[$row['status']])) $counts[$row['status']] = (int)$row['n'];
    }
} catch (Exception $e) {}

// ATC list for filter
$atcList = [];
try {
    $atcList = $pdo->query("SELECT id, name FROM atc_centers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Duplicate Certificate Requests — Admin | Gyanam India</title>
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<?php if (file_exists(__DIR__.'/../assets/css/notifications.css')): ?>
<link rel="stylesheet" href="../assets/css/notifications.css">
<?php endif; ?>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📋</text></svg>">
<style>
:root {
    --dc-violet:#6366f1;--dc-violet-dk:#4f46e5;--dc-violet-lt:#eef2ff;
    --dc-amber:#f59e0b;--dc-amber-lt:#fffbeb;
    --dc-green:#10b981;--dc-green-lt:#ecfdf5;
    --dc-red:#ef4444;--dc-red-lt:#fef2f2;
}
.dc-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:1rem;margin-bottom:1.5rem}
.dc-stat{background:#fff;border:1.5px solid var(--border-color);border-radius:14px;padding:1rem 1.25rem;display:flex;align-items:center;gap:.8rem}
.dc-stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.dc-stat-icon svg{width:18px;height:18px}
.dc-stat-icon.violet{background:var(--dc-violet-lt);color:var(--dc-violet)}
.dc-stat-icon.amber{background:var(--dc-amber-lt);color:var(--dc-amber)}
.dc-stat-icon.green{background:var(--dc-green-lt);color:var(--dc-green)}
.dc-stat-icon.red{background:var(--dc-red-lt);color:var(--dc-red)}
.dc-stat-val{font-size:1.5rem;font-weight:900;color:var(--text-primary);line-height:1}
.dc-stat-lbl{font-size:.72rem;color:var(--text-secondary);font-weight:600;margin-top:.2rem}

/* Filters bar */
.dc-filters{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;margin-bottom:1.25rem}
.dc-filter-btn{padding:.5rem 1rem;border:1.5px solid var(--border-color);border-radius:9px;background:#fff;font-size:.82rem;font-weight:700;cursor:pointer;font-family:inherit;color:var(--text-secondary);transition:all .18s;text-decoration:none;white-space:nowrap}
.dc-filter-btn:hover,.dc-filter-btn.active{border-color:var(--dc-violet);color:var(--dc-violet);background:var(--dc-violet-lt)}
.dc-filter-btn.approved.active{border-color:var(--dc-green);color:var(--dc-green);background:var(--dc-green-lt)}
.dc-filter-btn.rejected.active{border-color:var(--dc-red);color:var(--dc-red);background:var(--dc-red-lt)}
.dc-search-input{padding:.5rem .9rem;border:1.5px solid var(--border-color);border-radius:9px;font-size:.875rem;font-family:inherit;outline:none;min-width:200px}
.dc-search-input:focus{border-color:var(--dc-violet)}
.dc-select{padding:.5rem .9rem;border:1.5px solid var(--border-color);border-radius:9px;font-size:.82rem;font-family:inherit;background:#fff}

/* Card & Table */
.dc-card{background:#fff;border:1.5px solid var(--border-color);border-radius:14px;overflow:hidden}
.dc-card-head{display:flex;align-items:center;gap:.75rem;padding:.9rem 1.25rem;background:#fafbfc;border-bottom:1px solid var(--border-color)}
.dc-card-head-icon{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,var(--dc-violet),var(--dc-violet-dk));display:flex;align-items:center;justify-content:center;flex-shrink:0}
.dc-card-head-icon svg{width:16px;height:16px;stroke:#fff}
.dc-card-head-title{font-weight:800;font-size:.92rem;color:var(--text-primary)}
.dc-card-head-count{margin-left:auto;background:var(--dc-violet-lt);color:var(--dc-violet);border-radius:999px;font-size:.72rem;font-weight:800;padding:.15rem .6rem}
.dc-table{width:100%;border-collapse:collapse;font-size:.875rem}
.dc-table thead th{padding:.75rem 1rem;text-align:left;font-size:.68rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--border-color);background:#fafbfc;white-space:nowrap}
.dc-table tbody tr{border-bottom:1px solid #f3f4f6;transition:background .1s}
.dc-table tbody tr:last-child{border-bottom:none}
.dc-table tbody tr:hover{background:#fafbff}
.dc-table tbody td{padding:.75rem 1rem;vertical-align:middle}
.student-name{font-weight:700;color:var(--text-primary);font-size:.875rem}
.student-meta{font-size:.73rem;color:var(--text-secondary);margin-top:.1rem}
.atc-chip{display:inline-flex;align-items:center;padding:.18rem .6rem;background:#f0fdf4;color:#065f46;border:1px solid #6ee7b7;border-radius:6px;font-size:.7rem;font-weight:700}
.cert-type-badge{display:inline-block;padding:.2rem .6rem;border-radius:6px;font-size:.72rem;font-weight:700}
.cert-type-badge.completion{background:#ede9fe;color:#5b21b6}
.cert-type-badge.exam{background:#dbeafe;color:#1e40af}
.reason-badge{display:inline-block;padding:.2rem .6rem;border-radius:6px;font-size:.72rem;font-weight:700}
.status-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.22rem .65rem;border-radius:999px;font-size:.7rem;font-weight:700}
.status-badge.pending{background:var(--dc-amber-lt);color:#92400e;border:1px solid #fde68a}
.status-badge.approved{background:var(--dc-green-lt);color:#065f46;border:1px solid #6ee7b7}
.status-badge.rejected{background:var(--dc-red-lt);color:#991b1b;border:1px solid #fecaca}
.status-dot{width:5px;height:5px;border-radius:50%;background:currentColor}
.empty-state{text-align:center;padding:3rem 1rem;color:var(--text-secondary)}
.empty-state svg{display:block;margin:0 auto .75rem;width:40px;height:40px;opacity:.3}

/* Action buttons */
.btn-approve{display:inline-flex;align-items:center;gap:.3rem;padding:.38rem .8rem;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:7px;font-size:.75rem;font-weight:700;cursor:pointer;font-family:inherit;transition:all .18s}
.btn-approve:hover{transform:translateY(-1px);box-shadow:0 3px 10px rgba(16,185,129,.3)}
.btn-reject{display:inline-flex;align-items:center;gap:.3rem;padding:.38rem .8rem;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;border:none;border-radius:7px;font-size:.75rem;font-weight:700;cursor:pointer;font-family:inherit;transition:all .18s}
.btn-reject:hover{transform:translateY(-1px);box-shadow:0 3px 10px rgba(239,68,68,.3)}

/* Review Modal */
.rv-overlay{position:fixed;inset:0;background:rgba(15,23,42,.5);display:none;align-items:center;justify-content:center;z-index:9999;backdrop-filter:blur(3px)}
.rv-overlay.open{display:flex}
.rv-modal{background:#fff;border-radius:18px;width:100%;max-width:500px;margin:1rem;box-shadow:0 20px 60px rgba(0,0,0,.18);animation:slideUp .25s ease;overflow:hidden}
@keyframes slideUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.rv-head{padding:1.1rem 1.5rem;display:flex;align-items:center;gap:.75rem;border-bottom:1px solid var(--border-color)}
.rv-head-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.rv-head-icon.approve{background:var(--dc-green-lt);color:var(--dc-green)}
.rv-head-icon.reject{background:var(--dc-red-lt);color:var(--dc-red)}
.rv-head-icon svg{width:18px;height:18px}
.rv-title{font-size:1rem;font-weight:800;color:var(--text-primary)}
.rv-close{margin-left:auto;background:none;border:none;color:#94a3b8;cursor:pointer;padding:4px;border-radius:6px;display:flex}
.rv-close:hover{color:#1e293b;background:#f1f5f9}
.rv-close svg{width:18px;height:18px}
.rv-body{padding:1.25rem 1.5rem}
.rv-info{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:.85rem 1rem;margin-bottom:1.1rem;font-size:.85rem}
.rv-info strong{font-weight:800;color:var(--text-primary)}
.rv-info span{color:var(--text-secondary);display:block;margin-top:.2rem}
.rv-label{display:block;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-secondary);margin-bottom:.35rem}
.rv-textarea{width:100%;padding:.65rem .875rem;border:1.5px solid var(--border-color);border-radius:9px;font-size:.88rem;font-family:inherit;resize:vertical;box-sizing:border-box}
.rv-textarea:focus{border-color:var(--dc-violet);outline:none}
.rv-footer{padding:.9rem 1.5rem;background:#fafbfc;border-top:1px solid var(--border-color);display:flex;justify-content:flex-end;gap:.6rem}
.btn-secondary{padding:.6rem 1.2rem;border:1.5px solid var(--border-color);background:#fff;color:var(--text-primary);border-radius:9px;font-size:.875rem;font-weight:700;cursor:pointer;font-family:inherit}
.btn-secondary:hover{background:#f1f5f9}
.btn-confirm-green{padding:.6rem 1.4rem;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:9px;font-size:.875rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 4px 12px rgba(16,185,129,.25)}
.btn-confirm-red{padding:.6rem 1.4rem;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;border:none;border-radius:9px;font-size:.875rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 4px 12px rgba(239,68,68,.25)}
#dcToastWrap{position:fixed;bottom:1.5rem;right:1.5rem;z-index:99999;display:flex;flex-direction:column;gap:.5rem}
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
                <h2>Duplicate Certificate Requests</h2>
                <p>Review and approve/reject ATC requests for duplicate certificates</p>
            </div>
        </div>
        <div class="header-right">
            <?php if (file_exists(__DIR__.'/../includes/notification_bell.php')) include __DIR__.'/../includes/notification_bell.php'; ?>
            <?php include __DIR__.'/../includes/profile_dropdown.php'; ?>
        </div>
    </header>

    <div class="page-content">

        <!-- Stats -->
        <div class="dc-stats">
            <div class="dc-stat">
                <div class="dc-stat-icon violet"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
                <div><div class="dc-stat-val"><?= array_sum($counts) ?></div><div class="dc-stat-lbl">Total</div></div>
            </div>
            <div class="dc-stat">
                <div class="dc-stat-icon amber"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
                <div><div class="dc-stat-val"><?= $counts['Pending'] ?></div><div class="dc-stat-lbl">Pending</div></div>
            </div>
            <div class="dc-stat">
                <div class="dc-stat-icon green"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></div>
                <div><div class="dc-stat-val"><?= $counts['Approved'] ?></div><div class="dc-stat-lbl">Approved</div></div>
            </div>
            <div class="dc-stat">
                <div class="dc-stat-icon red"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></div>
                <div><div class="dc-stat-val"><?= $counts['Rejected'] ?></div><div class="dc-stat-lbl">Rejected</div></div>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" class="dc-filters">
            <a href="?status=Pending" class="dc-filter-btn <?= $filterStatus === 'Pending' ? 'active' : '' ?>">⏳ Pending <strong>(<?= $counts['Pending'] ?>)</strong></a>
            <a href="?status=Approved" class="dc-filter-btn approved <?= $filterStatus === 'Approved' ? 'active' : '' ?>">✅ Approved</a>
            <a href="?status=Rejected" class="dc-filter-btn rejected <?= $filterStatus === 'Rejected' ? 'active' : '' ?>">❌ Rejected</a>
            <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
            <select name="atc_id" class="dc-select" onchange="this.form.submit()">
                <option value="0">All ATC Centers</option>
                <?php foreach ($atcList as $atc): ?>
                <option value="<?= $atc['id'] ?>" <?= $filterAtc == $atc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($atc['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="q" class="dc-search-input" placeholder="Search student / roll no / course…" value="<?= htmlspecialchars($search) ?>">
            <button type="submit" style="padding:.5rem .9rem;background:var(--dc-violet);color:#fff;border:none;border-radius:9px;font-size:.82rem;font-weight:700;cursor:pointer;font-family:inherit;">Search</button>
            <?php if ($search || $filterAtc): ?>
            <a href="?status=<?= urlencode($filterStatus) ?>" style="padding:.5rem .9rem;border:1.5px solid var(--border-color);border-radius:9px;font-size:.82rem;font-weight:700;color:var(--text-secondary);text-decoration:none;">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Table -->
        <div class="dc-card">
            <div class="dc-card-head">
                <div class="dc-card-head-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></div>
                <div class="dc-card-head-title"><?= $filterStatus ?> Requests</div>
                <div class="dc-card-head-count"><?= count($requests) ?></div>
            </div>
            <div style="overflow-x:auto;">
                <table class="dc-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>ATC Center</th>
                            <th>Certificate Type</th>
                            <th>Reason</th>
                            <th>Remarks</th>
                            <th>Requested</th>
                            <th>Status</th>
                            <?php if ($filterStatus === 'Pending'): ?><th style="text-align:center;">Actions</th><?php endif; ?>
                            <?php if ($filterStatus !== 'Pending'): ?><th>Admin Note</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($requests)): ?>
                        <tr><td colspan="9">
                            <div class="empty-state">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                <p>No <?= strtolower($filterStatus) ?> requests found.</p>
                            </div>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($requests as $i => $req): ?>
                        <tr>
                            <td style="color:var(--text-secondary);font-size:.8rem;"><?= $i + 1 ?></td>
                            <td>
                                <div class="student-name"><?= htmlspecialchars($req['student_name']) ?></div>
                                <div class="student-meta"><?= htmlspecialchars($req['roll_no'] ?? '—') ?> · <?= htmlspecialchars($req['course'] ?? '—') ?></div>
                            </td>
                            <td><span class="atc-chip"><?= htmlspecialchars($req['atc_name'] ?? 'ATC #'.$req['atc_id']) ?></span></td>
                            <td>
                                <span class="cert-type-badge <?= str_contains($req['cert_type'],'Completion') ? 'completion' : 'exam' ?>">
                                    <?= htmlspecialchars($req['cert_type']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="reason-badge" style="<?= $req['reason']==='Damaged' ? 'background:#fef3c7;color:#92400e' : ($req['reason']==='Name Correction' ? 'background:#ede9fe;color:#5b21b6' : 'background:#f0fdf4;color:#166534') ?>">
                                    <?= htmlspecialchars($req['reason']) ?>
                                </span>
                            </td>
                            <td style="font-size:.8rem;color:var(--text-secondary);max-width:150px;"><?= htmlspecialchars($req['remarks'] ?? '—') ?></td>
                            <td style="font-size:.78rem;color:var(--text-secondary);white-space:nowrap;"><?= date('d M Y', strtotime($req['requested_at'])) ?><br><span style="font-size:.72rem;"><?= date('h:i A', strtotime($req['requested_at'])) ?></span></td>
                            <td>
                                <span class="status-badge <?= strtolower($req['status']) ?>">
                                    <span class="status-dot"></span>
                                    <?= $req['status'] ?>
                                </span>
                            </td>
                            <?php if ($filterStatus === 'Pending'): ?>
                            <td style="text-align:center;white-space:nowrap;">
                                <div style="display:flex;gap:.4rem;justify-content:center;">
                                    <button class="btn-approve" onclick="openReview(<?= $req['id'] ?>, 'Approved', '<?= htmlspecialchars(addslashes($req['student_name'])) ?>', '<?= htmlspecialchars(addslashes($req['cert_type'])) ?>')">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px"><polyline points="20 6 9 17 4 12"/></svg>
                                        Approve
                                    </button>
                                    <button class="btn-reject" onclick="openReview(<?= $req['id'] ?>, 'Rejected', '<?= htmlspecialchars(addslashes($req['student_name'])) ?>', '<?= htmlspecialchars(addslashes($req['cert_type'])) ?>')">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                        Reject
                                    </button>
                                </div>
                            </td>
                            <?php endif; ?>
                            <?php if ($filterStatus !== 'Pending'): ?>
                            <td style="font-size:.78rem;color:var(--text-secondary);max-width:160px;"><?= htmlspecialchars($req['admin_note'] ?? '—') ?></td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /page-content -->
</main>
</div>

<!-- Review Modal -->
<div class="rv-overlay" id="rvOverlay">
    <div class="rv-modal">
        <div class="rv-head">
            <div class="rv-head-icon" id="rvHeadIcon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div>
                <div class="rv-title" id="rvTitle">Approve Request</div>
            </div>
            <button class="rv-close" onclick="closeReview()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="rv-body">
            <div class="rv-info">
                <strong id="rvStudentName">—</strong>
                <span id="rvCertType">—</span>
            </div>
            <label class="rv-label">Admin Note <span style="color:#94a3b8;">(optional for approval, recommended for rejection)</span></label>
            <textarea class="rv-textarea" id="rvNote" rows="3" placeholder="Add a note for the ATC…"></textarea>
        </div>
        <div class="rv-footer">
            <button class="btn-secondary" onclick="closeReview()">Cancel</button>
            <button id="rvConfirmBtn" class="btn-confirm-green" onclick="confirmReview()">✅ Confirm Approval</button>
        </div>
    </div>
</div>

<div id="dcToastWrap"></div>

<script src="../assets/js/dashboard.js"></script>
<script>
let rvReqId = null;
let rvStatus = null;

function openReview(reqId, status, studentName, certType) {
    rvReqId = reqId;
    rvStatus = status;
    const isApprove = status === 'Approved';
    document.getElementById('rvTitle').textContent = isApprove ? 'Approve Request' : 'Reject Request';
    document.getElementById('rvHeadIcon').className = 'rv-head-icon ' + (isApprove ? 'approve' : 'reject');
    document.getElementById('rvStudentName').textContent = studentName;
    document.getElementById('rvCertType').textContent = certType;
    document.getElementById('rvNote').value = '';
    const btn = document.getElementById('rvConfirmBtn');
    btn.className = isApprove ? 'btn-confirm-green' : 'btn-confirm-red';
    btn.textContent = isApprove ? '✅ Confirm Approval' : '❌ Confirm Rejection';
    document.getElementById('rvOverlay').classList.add('open');
}

function closeReview() {
    document.getElementById('rvOverlay').classList.remove('open');
    rvReqId = null; rvStatus = null;
}

document.getElementById('rvOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeReview();
});

async function confirmReview() {
    if (!rvReqId || !rvStatus) return;
    const note = document.getElementById('rvNote').value.trim();
    if (rvStatus === 'Rejected' && !note) {
        dcToast('Please provide a reason for rejection.', 'error');
        return;
    }
    const btn = document.getElementById('rvConfirmBtn');
    btn.disabled = true; btn.textContent = 'Processing…';

    const fd = new FormData();
    fd.append('action', 'review');
    fd.append('req_id', rvReqId);
    fd.append('status', rvStatus);
    fd.append('admin_note', note);

    try {
        const res = await fetch('', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            dcToast((rvStatus === 'Approved' ? '✅ ' : '❌ ') + data.message);
            closeReview();
            setTimeout(() => location.reload(), 1400);
        } else {
            dcToast(data.message, 'error');
        }
    } catch(e) {
        dcToast('Network error.', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = rvStatus === 'Approved' ? '✅ Confirm Approval' : '❌ Confirm Rejection';
    }
}

function dcToast(msg, type='success') {
    const t = document.createElement('div');
    t.style.cssText = `background:${type==='success'?'#059669':'#dc2626'};color:#fff;padding:.75rem 1.25rem;border-radius:10px;font-size:.875rem;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.15);max-width:340px`;
    t.textContent = msg;
    document.getElementById('dcToastWrap').appendChild(t);
    setTimeout(() => t.remove(), 3500);
}
</script>
</body>
</html>
