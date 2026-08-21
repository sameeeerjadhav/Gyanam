<?php
/**
 * Gyanam Portal — ATC: Material Dispatches
 * View dispatches, file complaints, print receipts.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo      = getDBConnection();
$userName = sanitize(getUserName());
$atcId    = $_SESSION['atc_id'] ?? null;

if (!$atcId) die('ATC ID not found. Please log in again.');

// ── Auto-create complaint table ───────────────────────────────────────────
try { $pdo->query("SELECT 1 FROM dispatch_complaints LIMIT 1"); } catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dispatch_complaints (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dispatch_id INT NOT NULL,
            atc_id INT NOT NULL,
            complaint_type ENUM('Wrong Materials','Damaged','Missing Items','Wrong Quantity','Other') DEFAULT 'Other',
            description TEXT,
            photo VARCHAR(255),
            status ENUM('Pending','Resolved','Rejected') DEFAULT 'Pending',
            admin_response TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            resolved_at TIMESTAMP NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// ── AJAX handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    switch ($_POST['action']) {
        case 'get_dispatch_students':
            try {
                $id = (int)$_POST['id'];
                // Try dispatch_items first (new system)
                $items = [];
                try {
                    $stmt = $pdo->prepare("
                        SELECT di.item_type, di.item_detail, di.status,
                               TRIM(CONCAT(a.first_name,' ',COALESCE(a.middle_name,''),' ',a.last_name)) AS student_name,
                               a.roll_no, a.registration_id, a.course, a.material_language
                        FROM dispatch_items di
                        JOIN admissions a ON di.admission_id = a.id
                        JOIN material_dispatches d ON di.dispatch_id = d.id
                        WHERE di.dispatch_id = ? AND d.atc_id = ?
                        ORDER BY a.first_name, di.item_type
                    ");
                    $stmt->execute([$id, $atcId]);
                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {}

                // Legacy fallback
                if (empty($items)) {
                    $stmt = $pdo->prepare("
                        SELECT a.roll_no, a.registration_id,
                               TRIM(CONCAT(a.first_name,' ',COALESCE(a.middle_name,''),' ',a.last_name)) AS student_name,
                               a.course, a.material_language
                        FROM material_dispatch_students mds
                        JOIN admissions a ON mds.admission_id = a.id
                        JOIN material_dispatches d ON mds.dispatch_id = d.id
                        WHERE mds.dispatch_id = ? AND d.atc_id = ?
                    ");
                    $stmt->execute([$id, $atcId]);
                    $legacy = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($legacy as $ls) {
                        $items[] = array_merge($ls, ['item_type' => 'Book', 'item_detail' => $ls['material_language'] ?? '', 'status' => 'Dispatched']);
                    }
                }

                echo json_encode(['success' => true, 'data' => $items]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;

        case 'file_complaint':
            $dispId = (int)$_POST['dispatch_pk'];
            $type   = $_POST['complaint_type'] ?? 'Other';
            $desc   = trim($_POST['description'] ?? '');

            if (!$dispId || !$desc) {
                echo json_encode(['success' => false, 'message' => 'Description is required']);
                exit;
            }

            // Verify dispatch belongs to this ATC
            $chk = $pdo->prepare("SELECT id FROM material_dispatches WHERE id = ? AND atc_id = ?");
            $chk->execute([$dispId, $atcId]);
            if (!$chk->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Dispatch not found']);
                exit;
            }

            // Handle photo upload
            $photoPath = null;
            if (!empty($_FILES['complaint_photo']['name'])) {
                $dir = __DIR__ . '/../uploads/complaints/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $ext = pathinfo($_FILES['complaint_photo']['name'], PATHINFO_EXTENSION);
                $fn  = 'complaint_' . $dispId . '_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['complaint_photo']['tmp_name'], $dir . $fn);
                $photoPath = 'uploads/complaints/' . $fn;
            }

            $stmt = $pdo->prepare("INSERT INTO dispatch_complaints (dispatch_id, atc_id, complaint_type, description, photo) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$dispId, $atcId, $type, $desc, $photoPath]);

            // Notify admin
            try {
                $atcName = $pdo->prepare("SELECT name FROM atc_centers WHERE id = ?");
                $atcName->execute([$atcId]);
                $aName = $atcName->fetchColumn() ?: 'ATC';
                $pdo->prepare("INSERT INTO notifications (sender_id, title, message, target_type, target_id) VALUES (?, ?, ?, 'All', NULL)")
                    ->execute([$_SESSION['user_id'] ?? 1, "Dispatch Complaint — {$aName}", "Dispatch complaint from {$aName}: {$type} — {$desc}"]);
            } catch (Exception $e) {}

            echo json_encode(['success' => true, 'message' => 'Complaint submitted successfully']);
            exit;

        case 'get_complaints':
            $dispId = (int)$_POST['dispatch_pk'];
            try {
                $stmt = $pdo->prepare("SELECT * FROM dispatch_complaints WHERE dispatch_id = ? AND atc_id = ? ORDER BY created_at DESC");
                $stmt->execute([$dispId, $atcId]);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (Exception $e) {
                echo json_encode(['success' => true, 'data' => []]);
            }
            exit;
    }
    exit;
}

// Fetch dispatches for this ATC
$dispatches = [];
try {
    $stmt = $pdo->prepare("
        SELECT d.*, COUNT(mds.id) AS student_count
        FROM material_dispatches d
        LEFT JOIN material_dispatch_students mds ON mds.dispatch_id = d.id
        WHERE d.atc_id = ?
        GROUP BY d.id
        ORDER BY d.created_at DESC
    ");
    $stmt->execute([$atcId]);
    $dispatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Complaint counts per dispatch
$complaintCounts = [];
try {
    $ccStmt = $pdo->prepare("SELECT dispatch_id, COUNT(*) as cnt, SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) as pending_cnt FROM dispatch_complaints WHERE atc_id = ? GROUP BY dispatch_id");
    $ccStmt->execute([$atcId]);
    foreach ($ccStmt->fetchAll(PDO::FETCH_ASSOC) as $cc) {
        $complaintCounts[$cc['dispatch_id']] = $cc;
    }
} catch (Exception $e) {}

// Pending students (With Material, not yet dispatched)
$pendingStudents = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, roll_no, registration_id,
               TRIM(CONCAT(first_name,' ',COALESCE(middle_name,''),' ',last_name)) AS student_name,
               course, material_language, uniform_size, admission_date
        FROM admissions
        WHERE atc_id = ? AND material_type = 'With Material'
          AND id NOT IN (SELECT COALESCE(admission_id,0) FROM material_dispatch_students)
        ORDER BY admission_date DESC
    ");
    $stmt->execute([$atcId]);
    $pendingStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get certificate dispatch status for pending students
$certDispatched = [];
try {
    if (!empty($pendingStudents)) {
        $pIds = array_column($pendingStudents, 'id');
        $ph = implode(',', array_fill(0, count($pIds), '?'));
        $cdStmt = $pdo->prepare("SELECT admission_id, item_detail, status FROM dispatch_items WHERE admission_id IN ($ph) AND item_type = 'Certificate'");
        $cdStmt->execute($pIds);
        foreach ($cdStmt->fetchAll(PDO::FETCH_ASSOC) as $cd) {
            $certDispatched[$cd['admission_id']] = $cd['status'];
        }
    }
} catch (Exception $e) {}

$kpiTotal      = count($dispatches);
$kpiDispatched = count(array_filter($dispatches, fn($d) => $d['status'] === 'Dispatched'));
$kpiDelivered  = count(array_filter($dispatches, fn($d) => $d['status'] === 'Delivered'));

// Approved duplicate cert requests awaiting dispatch
$approvedDupCerts = [];
try {
    $dcStmt = $pdo->prepare("
        SELECT dcr.id, dcr.student_name, dcr.roll_no, dcr.course,
               dcr.cert_type, dcr.reason, dcr.admin_note,
               dcr.reviewed_at, dcr.admission_id
        FROM duplicate_cert_requests dcr
        WHERE dcr.atc_id = ? AND dcr.status = 'Approved'
        ORDER BY dcr.reviewed_at DESC
    ");
    $dcStmt->execute([$atcId]);
    $approvedDupCerts = $dcStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$kpiPending = count($pendingStudents) + count($approvedDupCerts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Material Dispatches — ATC Center | Gyanam India</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📦</text></svg>">
    <style>
    .kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1.1rem;margin-bottom:1.75rem}
    .kpi-card{background:var(--bg-surface);border:1.5px solid var(--border-color);border-radius:var(--radius-xl);padding:1.1rem 1.3rem;display:flex;align-items:center;gap:.85rem;position:relative;overflow:hidden}
    .kpi-card::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%}
    .kpi-card.blue::before{background:#3b82f6}.kpi-card.green::before{background:#10b981}
    .kpi-card.amber::before{background:#f59e0b}.kpi-card.orange::before{background:#f97316}
    .kpi-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .kpi-icon svg{width:18px;height:18px;stroke:currentColor}
    .kpi-card.blue .kpi-icon{background:#eff6ff;color:#3b82f6}
    .kpi-card.green .kpi-icon{background:#ecfdf5;color:#10b981}
    .kpi-card.amber .kpi-icon{background:#fffbeb;color:#f59e0b}
    .kpi-card.orange .kpi-icon{background:#fff7ed;color:#f97316}
    .kpi-val{font-size:1.6rem;font-weight:800;color:var(--text-primary);line-height:1}
    .kpi-lbl{font-size:.7rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-top:.2rem}
    .section-card{background:var(--bg-surface);border:1.5px solid var(--border-color);border-radius:var(--radius-xl);overflow:hidden;margin-bottom:1.5rem}
    .section-head{padding:1rem 1.5rem;border-bottom:1.5px solid var(--border-color);display:flex;align-items:center;gap:.6rem;font-weight:800;font-size:.9rem;color:var(--text-primary)}
    .tbl{width:100%;border-collapse:collapse;font-size:.855rem}
    .tbl th{padding:.75rem 1rem;text-align:left;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);border-bottom:2px solid var(--border-color);background:var(--bg-subtle)}
    .tbl td{padding:.85rem 1rem;border-bottom:1px solid var(--border-color);vertical-align:middle;color:var(--text-secondary)}
    .tbl tr:last-child td{border-bottom:none}
    .tbl tr:hover td{background:var(--bg-subtle)}
    .dispatch-id{font-family:monospace;font-weight:700;color:var(--text-primary);font-size:.83rem}
    .badge{display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .7rem;border-radius:99px;font-size:.75rem;font-weight:700}
    .badge-pending{background:#fff7ed;color:#c2410c}
    .badge-dispatched{background:#eff6ff;color:#1d4ed8}
    .badge-delivered{background:#ecfdf5;color:#065f46}
    .btn-action{display:inline-flex;align-items:center;gap:.35rem;padding:.4rem .75rem;border:1.5px solid var(--border-color);border-radius:8px;font-size:.75rem;font-weight:700;cursor:pointer;background:var(--bg-surface);color:var(--text-secondary);transition:all .2s;font-family:inherit;white-space:nowrap}
    .btn-action:hover{background:var(--bg-subtle);border-color:#6366f1;color:#6366f1}
    .btn-action.danger{border-color:#fca5a5;color:#dc2626;background:#fff5f5}
    .btn-action.danger:hover{background:#fee2e2;border-color:#ef4444}
    .btn-action.print{border-color:#c4b5fd;color:#7c3aed;background:#faf5ff}
    .btn-action.print:hover{background:#f3e8ff}
    .action-group{display:flex;gap:.35rem;flex-wrap:wrap}
    .pending-pill{display:inline-block;background:#fff7ed;color:#c2410c;padding:.2rem .65rem;border-radius:99px;font-size:.72rem;font-weight:700;margin-left:.5rem}
    .complaint-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#f59e0b;margin-right:.25rem}
    .empty-state{padding:2.5rem;text-align:center;color:var(--text-muted);font-size:.875rem}
    /* Modal */
    .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:1000}
    .modal-overlay.open{display:flex}
    .modal{background:#fff;border-radius:20px;width:min(620px,95vw);max-height:90vh;overflow:hidden;display:flex;flex-direction:column;animation:slideUp .3s ease}
    @keyframes slideUp{from{transform:translateY(24px);opacity:0}to{transform:none;opacity:1}}
    .modal-hdr{padding:1.25rem 1.5rem;border-bottom:1.5px solid var(--border-color);display:flex;align-items:center;justify-content:space-between}
    .modal-hdr h3{font-size:1rem;font-weight:800;color:var(--text-primary);margin:0}
    .modal-bdy{padding:1.25rem 1.5rem;overflow-y:auto;flex:1}
    .modal-ftr{padding:1rem 1.5rem;border-top:1.5px solid var(--border-color);display:flex;justify-content:flex-end;gap:.5rem}
    .close-btn{border:none;background:none;cursor:pointer;font-size:1.3rem;color:var(--text-muted);padding:.25rem;border-radius:8px}
    .close-btn:hover{background:var(--bg-subtle)}
    /* Complaint form */
    .cf-group{display:flex;flex-direction:column;gap:.35rem;margin-bottom:.85rem}
    .cf-group label{font-size:.78rem;font-weight:700;color:var(--text-secondary)}
    .cf-group select,.cf-group input,.cf-group textarea{padding:.6rem .85rem;border:1.5px solid var(--border-color);border-radius:8px;font-size:.84rem;font-family:inherit;outline:none}
    .cf-group select:focus,.cf-group input:focus,.cf-group textarea:focus{border-color:#6366f1}
    .cf-submit{padding:.6rem 1.2rem;background:#6366f1;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-family:inherit}
    .cf-submit:hover{background:#4f46e5}
    /* Complaint list */
    .complaint-card{border:1.5px solid var(--border-color);border-radius:10px;padding:.85rem 1rem;margin-bottom:.65rem}
    .complaint-card .cc-type{font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#f59e0b}
    .complaint-card .cc-desc{font-size:.84rem;color:var(--text-primary);margin:.3rem 0}
    .complaint-card .cc-meta{font-size:.7rem;color:var(--text-muted)}
    .complaint-card .cc-resolved{font-size:.78rem;color:#059669;background:#ecfdf5;padding:.3rem .6rem;border-radius:6px;margin-top:.4rem;display:inline-block}
    .complaint-card .cc-status{display:inline-flex;padding:.15rem .5rem;border-radius:99px;font-size:.68rem;font-weight:700}
    .cc-status.pending{background:#fffbeb;color:#d97706}.cc-status.resolved{background:#ecfdf5;color:#059669}.cc-status.rejected{background:#fee2e2;color:#dc2626}
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
                    <h2>Material Dispatches</h2>
                    <p>Track materials dispatched to your center</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">

            <!-- KPI Row -->
            <div class="kpi-row">
                <div class="kpi-card orange">
                    <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg></div>
                    <div><div class="kpi-val"><?= $kpiPending ?></div><div class="kpi-lbl">Awaiting Dispatch</div></div>
                </div>
                <div class="kpi-card blue">
                    <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></div>
                    <div><div class="kpi-val"><?= $kpiTotal ?></div><div class="kpi-lbl">Total Dispatches</div></div>
                </div>
                <div class="kpi-card amber">
                    <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div>
                    <div><div class="kpi-val"><?= $kpiDispatched ?></div><div class="kpi-lbl">In Transit</div></div>
                </div>
                <div class="kpi-card green">
                    <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></div>
                    <div><div class="kpi-val"><?= $kpiDelivered ?></div><div class="kpi-lbl">Delivered</div></div>
                </div>
            </div>

            <!-- Pending Students -->
            <?php if (!empty($pendingStudents)): ?>
            <div class="section-card">
                <div class="section-head">
                    Students Awaiting Dispatch
                    <span class="pending-pill"><?= count($pendingStudents) ?> pending</span>
                </div>
                <div style="overflow-x:auto">
                <table class="tbl">
                    <thead><tr>
                        <th>#</th>
                        <th>Student</th>
                        <th>Registration ID</th>
                        <th>Course</th>
                        <th>Book Language</th>
                        <th>T-Shirt Size</th>
                        <th>Certificate</th>
                        <th>Admission Date</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($pendingStudents as $i => $s): ?>
                    <tr>
                        <td style="color:var(--text-muted);font-size:.78rem"><?= $i + 1 ?></td>
                        <td style="font-weight:700;color:var(--text-primary)"><?= htmlspecialchars($s['student_name']) ?><br><span style="font-size:.7rem;color:var(--text-muted)"><?= htmlspecialchars($s['roll_no'] ?? '') ?></span></td>
                        <td><code style="font-size:.78rem"><?= htmlspecialchars($s['registration_id'] ?? '') ?></code></td>
                        <td><?= htmlspecialchars($s['course'] ?? '—') ?></td>
                        <td><?php if (!empty($s['material_language'])): ?>
                            <span style="display:inline-flex;align-items:center;gap:.3rem;background:#eff6ff;color:#1d4ed8;padding:.2rem .6rem;border-radius:6px;font-size:.75rem;font-weight:700">📚 <?= htmlspecialchars($s['material_language']) ?></span>
                            <?php else: ?><span style="color:var(--text-muted)">—</span><?php endif; ?></td>
                        <td><?php if (!empty($s['uniform_size'])): ?>
                            <span style="display:inline-flex;align-items:center;gap:.3rem;background:#fdf4ff;color:#a855f7;padding:.2rem .6rem;border-radius:6px;font-size:.75rem;font-weight:700">👕 <?= htmlspecialchars($s['uniform_size']) ?></span>
                            <?php else: ?><span style="color:var(--text-muted)">—</span><?php endif; ?></td>
                        <td><?php
                            $cStatus = $certDispatched[$s['id']] ?? null;
                            if ($cStatus === 'Dispatched'): ?>
                            <span style="display:inline-flex;align-items:center;gap:.3rem;background:#ecfdf5;color:#059669;padding:.2rem .6rem;border-radius:6px;font-size:.75rem;font-weight:700">📜 Sent</span>
                            <?php else: ?>
                            <span style="display:inline-flex;align-items:center;gap:.3rem;background:#fff7ed;color:#c2410c;padding:.2rem .6rem;border-radius:6px;font-size:.75rem;font-weight:700">📜 Needed</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.82rem;color:var(--text-muted)"><?= !empty($s['admission_date']) ? date('d M Y', strtotime($s['admission_date'])) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Approved Duplicate Certificate Requests awaiting dispatch -->
            <?php if (!empty($approvedDupCerts)): ?>
            <div class="section-card" style="border-left:4px solid #6366f1;">
                <div class="section-head" style="background:linear-gradient(135deg,#eef2ff,#f5f3ff);">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2" style="width:18px;height:18px;flex-shrink:0">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="12" y1="18" x2="12" y2="12"/>
                        <line x1="9" y1="15" x2="15" y2="15"/>
                    </svg>
                    <span style="color:#4f46e5;font-weight:800">Duplicate Certificates — Approved &amp; Awaiting Dispatch</span>
                    <span style="margin-left:auto;background:#6366f1;color:#fff;border-radius:999px;font-size:.72rem;font-weight:800;padding:.2rem .65rem;"><?= count($approvedDupCerts) ?> pending</span>
                </div>
                <div style="padding:.75rem 1.25rem;font-size:.8rem;color:#4f46e5;background:#eef2ff;border-bottom:1px solid #c7d2fe;">
                    📌 These duplicate certificate requests have been <strong>approved by Head Office</strong> and the certificates are expected to be dispatched to your center soon.
                </div>
                <div style="overflow-x:auto">
                <table class="tbl">
                    <thead><tr>
                        <th>#</th>
                        <th>Student</th>
                        <th>Roll No</th>
                        <th>Course</th>
                        <th>Certificate Type</th>
                        <th>Reason</th>
                        <th>Approved On</th>
                        <th>Admin Note</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($approvedDupCerts as $i => $dc): ?>
                    <tr>
                        <td style="color:var(--text-muted);font-size:.78rem"><?= $i + 1 ?></td>
                        <td style="font-weight:700;color:var(--text-primary)"><?= htmlspecialchars(trim($dc['student_name'])) ?></td>
                        <td><span style="background:#eef2ff;color:#4f46e5;padding:.2rem .5rem;border-radius:5px;font-size:.75rem;font-weight:700"><?= htmlspecialchars($dc['roll_no'] ?? '—') ?></span></td>
                        <td style="font-size:.82rem;max-width:160px"><?= htmlspecialchars($dc['course'] ?? '—') ?></td>
                        <td>
                            <span style="display:inline-flex;align-items:center;gap:.3rem;background:#ede9fe;color:#5b21b6;padding:.22rem .65rem;border-radius:999px;font-size:.72rem;font-weight:700">
                                📄 <?= htmlspecialchars($dc['cert_type']) ?>
                            </span>
                        </td>
                        <td>
                            <span style="display:inline-block;padding:.2rem .55rem;border-radius:6px;font-size:.72rem;font-weight:700;
                                <?= $dc['reason'] === 'Damaged' ? 'background:#fef3c7;color:#92400e;' : ($dc['reason'] === 'Name Correction' ? 'background:#ede9fe;color:#5b21b6;' : 'background:#f0fdf4;color:#166534;') ?>">
                                <?= htmlspecialchars($dc['reason']) ?>
                            </span>
                        </td>
                        <td style="font-size:.8rem;color:var(--text-muted)"><?= $dc['reviewed_at'] ? date('d M Y', strtotime($dc['reviewed_at'])) : '—' ?></td>
                        <td style="font-size:.8rem;color:var(--text-secondary);max-width:160px"><?= htmlspecialchars($dc['admin_note'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php endif; ?>


            <div class="section-card">
                <div class="section-head">Dispatch History</div>
                <?php if (empty($dispatches)): ?>
                    <div class="empty-state">No dispatches received yet. Head Office will dispatch materials here.</div>
                <?php else: ?>
                <div style="overflow-x:auto">
                <table class="tbl">
                    <thead><tr>
                        <th>Dispatch ID</th><th>Students</th><th>Postal Service</th><th>Tracking ID</th><th>Dispatch Date</th><th>Status</th><th>Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($dispatches as $d):
                        $bc = ['Pending'=>'badge-pending','Dispatched'=>'badge-dispatched','Delivered'=>'badge-delivered'];
                        $cc = $complaintCounts[$d['id']] ?? null;
                    ?>
                    <tr>
                        <td><span class="dispatch-id"><?= htmlspecialchars($d['dispatch_id']) ?></span></td>
                        <td><strong><?= $d['student_count'] ?></strong> student(s)</td>
                        <td><?= htmlspecialchars($d['postal_service']) ?></td>
                        <td><?= $d['tracking_id'] ? '<code style="font-size:.78rem;background:var(--bg-subtle);padding:.15rem .5rem;border-radius:5px">'.htmlspecialchars($d['tracking_id']).'</code>' : '—' ?></td>
                        <td><?= date('d M Y', strtotime($d['dispatch_date'])) ?></td>
                        <td>
                            <span class="badge <?= $bc[$d['status']] ?? '' ?>"><?= $d['status'] ?></span>
                            <?php if ($cc && $cc['pending_cnt'] > 0): ?>
                            <span style="margin-left:.3rem" title="<?= $cc['pending_cnt'] ?> pending complaint(s)"><span class="complaint-dot"></span></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-group">
                                <button class="btn-action" onclick="viewStudents(<?= $d['id'] ?>, '<?= htmlspecialchars($d['dispatch_id']) ?>')">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                                    View
                                </button>
                                <a href="dispatch_receipt.php?id=<?= $d['id'] ?>" target="_blank" class="btn-action print">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="12" height="12"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                    Print Receipt
                                </a>
                                <button class="btn-action danger" onclick="openComplaint(<?= $d['id'] ?>, '<?= htmlspecialchars($d['dispatch_id']) ?>')">
                                    ⚠️ Report Issue
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </main>
</div>

<!-- Students Modal -->
<div class="modal-overlay" id="stuModal">
    <div class="modal">
        <div class="modal-hdr">
            <h3 id="stuModalTitle">Students in Dispatch</h3>
            <button class="close-btn" onclick="closeModal('stuModal')">×</button>
        </div>
        <div class="modal-bdy" id="stuModalBody">Loading...</div>
        <div class="modal-ftr">
            <button class="btn-action" onclick="closeModal('stuModal')">Close</button>
        </div>
    </div>
</div>

<!-- Complaint Modal -->
<div class="modal-overlay" id="complaintModal">
    <div class="modal">
        <div class="modal-hdr">
            <h3 id="complaintTitle">⚠️ Report Issue</h3>
            <button class="close-btn" onclick="closeModal('complaintModal')">×</button>
        </div>
        <div class="modal-bdy">
            <input type="hidden" id="complaintDispId">
            <div class="cf-group">
                <label>Complaint Type</label>
                <select id="complaintType">
                    <option value="Wrong Materials">Wrong Materials</option>
                    <option value="Damaged">Damaged</option>
                    <option value="Missing Items">Missing Items</option>
                    <option value="Wrong Quantity">Wrong Quantity</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div class="cf-group">
                <label>Description *</label>
                <textarea id="complaintDesc" rows="4" placeholder="Describe the issue in detail..."></textarea>
            </div>
            <div class="cf-group">
                <label>Photo (optional)</label>
                <input type="file" id="complaintPhoto" accept="image/*">
            </div>
            <hr style="border:none;border-top:1.5px solid var(--border-color);margin:1rem 0">
            <div id="complaintHistory"><p style="color:var(--text-muted);font-size:.82rem">Loading previous complaints...</p></div>
        </div>
        <div class="modal-ftr">
            <button class="btn-action" onclick="closeModal('complaintModal')">Cancel</button>
            <button class="cf-submit" onclick="submitComplaint()">Submit Complaint</button>
        </div>
    </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function viewStudents(id, dispId) {
    document.getElementById('stuModalTitle').textContent = 'Students in ' + dispId;
    document.getElementById('stuModalBody').innerHTML = 'Loading...';
    document.getElementById('stuModal').classList.add('open');
    fetch('dispatches.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=get_dispatch_students&id=' + id})
    .then(r => r.json()).then(res => {
        if (!res.success || !res.data.length) {
            document.getElementById('stuModalBody').innerHTML = '<p style="text-align:center;padding:2rem;color:var(--text-muted)">No students found</p>';
            return;
        }

        // Group by student
        const byStudent = {};
        res.data.forEach(item => {
            const key = item.student_name;
            if (!byStudent[key]) byStudent[key] = { info: item, items: [] };
            byStudent[key].items.push(item);
        });

        let html = '<table style="width:100%;border-collapse:collapse;font-size:.84rem">' +
            '<thead><tr style="border-bottom:2px solid #e5e7eb">' +
            '<th style="padding:.6rem .8rem;text-align:left;font-size:.7rem;text-transform:uppercase;color:#6b7280">Student</th>' +
            '<th style="padding:.6rem .8rem;text-align:left;font-size:.7rem;text-transform:uppercase;color:#6b7280">Course</th>' +
            '<th style="padding:.6rem .8rem;text-align:left;font-size:.7rem;text-transform:uppercase;color:#6b7280">Material</th>' +
            '<th style="padding:.6rem .8rem;text-align:left;font-size:.7rem;text-transform:uppercase;color:#6b7280">Status</th>' +
            '</tr></thead><tbody>';

        for (const [name, group] of Object.entries(byStudent)) {
            group.items.forEach((item, i) => {
                const icon = (item.item_type === 'T-Shirt') ? '👕' : (item.item_type === 'Certificate' ? '📜' : '📚');
                const statusBadge = item.status === 'Dispatched'
                    ? '<span style="color:#059669;font-weight:700;font-size:.75rem">✓ Dispatched</span>'
                    : '<span style="color:#d97706;font-weight:700;font-size:.75rem">⏳ Pending</span>';
                html += `<tr style="border-bottom:1px solid #f3f4f6">
                    <td style="padding:.7rem .8rem">${i === 0 ? `<strong>${name}</strong><br><small style="color:#9ca3af">${group.info.roll_no||''} · ${group.info.registration_id||''}</small>` : ''}</td>
                    <td style="padding:.7rem .8rem;color:#6b7280">${i === 0 ? (group.info.course||'') : ''}</td>
                    <td style="padding:.7rem .8rem">${icon} ${item.item_type} — ${item.item_detail||''}</td>
                    <td style="padding:.7rem .8rem">${statusBadge}</td>
                </tr>`;
            });
        }
        html += '</tbody></table>';
        document.getElementById('stuModalBody').innerHTML = html;
    });
}

// ── Complaint system ──
function openComplaint(dispPK, dispId) {
    document.getElementById('complaintTitle').textContent = '⚠️ Report Issue — ' + dispId;
    document.getElementById('complaintDispId').value = dispPK;
    document.getElementById('complaintDesc').value = '';
    document.getElementById('complaintType').value = 'Other';
    document.getElementById('complaintPhoto').value = '';
    document.getElementById('complaintModal').classList.add('open');
    loadComplaints(dispPK);
}

function loadComplaints(dispPK) {
    const hist = document.getElementById('complaintHistory');
    hist.innerHTML = '<p style="color:var(--text-muted);font-size:.82rem">Loading...</p>';
    fetch('dispatches.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=get_complaints&dispatch_pk=' + dispPK})
    .then(r => r.json()).then(res => {
        if (!res.data?.length) {
            hist.innerHTML = '<p style="color:var(--text-muted);font-size:.82rem;text-align:center">No previous complaints for this dispatch.</p>';
            return;
        }
        let html = '<div style="font-size:.72rem;font-weight:800;text-transform:uppercase;color:var(--text-muted);margin-bottom:.5rem">Previous Complaints</div>';
        res.data.forEach(c => {
            const stClass = c.status.toLowerCase();
            html += `<div class="complaint-card">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <span class="cc-type">${c.complaint_type}</span>
                    <span class="cc-status ${stClass}">${c.status}</span>
                </div>
                <div class="cc-desc">${c.description}</div>
                <div class="cc-meta">${new Date(c.created_at).toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'})}</div>
                ${c.admin_response ? `<div class="cc-resolved">Admin: ${c.admin_response}</div>` : ''}
                ${c.photo ? `<img src="../${c.photo}" style="max-width:100%;max-height:120px;border-radius:8px;margin-top:.5rem" alt="Photo">` : ''}
            </div>`;
        });
        hist.innerHTML = html;
    });
}

async function submitComplaint() {
    const dispPK = document.getElementById('complaintDispId').value;
    const ctype  = document.getElementById('complaintType').value;
    const desc   = document.getElementById('complaintDesc').value.trim();
    const photo  = document.getElementById('complaintPhoto').files[0];

    if (!desc) { alert('Please describe the issue'); return; }

    const fd = new FormData();
    fd.append('action', 'file_complaint');
    fd.append('dispatch_pk', dispPK);
    fd.append('complaint_type', ctype);
    fd.append('description', desc);
    if (photo) fd.append('complaint_photo', photo);

    try {
        const res = await fetch('dispatches.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            alert('Complaint submitted successfully!');
            loadComplaints(dispPK);
            document.getElementById('complaintDesc').value = '';
            document.getElementById('complaintPhoto').value = '';
        } else {
            alert('Error: ' + data.message);
        }
    } catch(e) {
        alert('Network error. Please try again.');
    }
}
</script>
</body>
</html>
