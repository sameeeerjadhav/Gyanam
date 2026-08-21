<?php
/**
 * Gyanam Portal — ATC: Duplicate Certificate Requests
 * ATC can request duplicate certificates for students.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
if (file_exists(__DIR__ . '/../includes/notifications.php'))
    require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo   = getDBConnection();
$atcId = $_SESSION['atc_id'] ?? null;

// ── Create table if not exists ──────────────────────────────────────────────
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

            case 'submit_request':
                $admissionId = intval($_POST['admission_id'] ?? 0);
                $certType    = trim($_POST['cert_type'] ?? '');
                $reason      = trim($_POST['reason'] ?? '');
                $remarks     = trim($_POST['remarks'] ?? '');

                $validCert   = ['Course Completion Certificate','Exam Certificate'];
                $validReason = ['Name Correction','Misplaced by Student','Damaged'];

                if (!$admissionId || !in_array($certType, $validCert) || !in_array($reason, $validReason)) {
                    echo json_encode(['success' => false, 'message' => 'Please fill all required fields.']);
                    exit;
                }

                // Verify student belongs to this ATC
                $s = $pdo->prepare("SELECT id, CONCAT(first_name,' ',COALESCE(middle_name,''),' ',last_name) as full_name, roll_no, course FROM admissions WHERE id = ? AND atc_id = ?");
                $s->execute([$admissionId, $atcId]);
                $stu = $s->fetch(PDO::FETCH_ASSOC);
                if (!$stu) {
                    echo json_encode(['success' => false, 'message' => 'Student not found.']);
                    exit;
                }

                // Check for existing pending request for same cert type
                $chk = $pdo->prepare("SELECT id FROM duplicate_cert_requests WHERE admission_id = ? AND cert_type = ? AND status = 'Pending'");
                $chk->execute([$admissionId, $certType]);
                if ($chk->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'A pending request for this certificate type already exists for this student.']);
                    exit;
                }

                $ins = $pdo->prepare("INSERT INTO duplicate_cert_requests (atc_id, admission_id, student_name, roll_no, course, cert_type, reason, remarks) VALUES (?,?,?,?,?,?,?,?)");
                $ins->execute([$atcId, $admissionId, trim($stu['full_name']), $stu['roll_no'], $stu['course'], $certType, $reason, $remarks ?: null]);

                echo json_encode(['success' => true, 'message' => 'Request submitted successfully! Admin will review shortly.']);
                exit;

            case 'cancel_request':
                $reqId = intval($_POST['req_id'] ?? 0);
                $s = $pdo->prepare("DELETE FROM duplicate_cert_requests WHERE id = ? AND atc_id = ? AND status = 'Pending'");
                $s->execute([$reqId, $atcId]);
                echo json_encode(['success' => true, 'message' => 'Request cancelled.']);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ── Fetch students ────────────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$params = [$atcId];
$where  = 'a.atc_id = ?';
if ($search) {
    $where .= " AND (CONCAT(a.first_name,' ',COALESCE(a.middle_name,''),' ',a.last_name) LIKE ? OR a.roll_no LIKE ? OR a.course LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

$students = [];
try {
    $q = $pdo->prepare("
        SELECT a.id, a.roll_no, a.registration_id,
               CONCAT(a.first_name,' ',COALESCE(a.middle_name,' '),' ',a.last_name) AS full_name,
               a.course, a.mobile, a.status,
               (SELECT COUNT(*) FROM duplicate_cert_requests r WHERE r.admission_id = a.id AND r.status = 'Pending') AS pending_reqs
        FROM admissions a
        WHERE $where
        ORDER BY a.first_name ASC
        LIMIT 300
    ");
    $q->execute($params);
    $students = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Fetch this ATC's existing requests ────────────────────────────────────────
$myRequests = [];
try {
    $r = $pdo->prepare("SELECT * FROM duplicate_cert_requests WHERE atc_id = ? ORDER BY requested_at DESC LIMIT 100");
    $r->execute([$atcId]);
    $myRequests = $r->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Duplicate Certificate Requests | Gyanam India</title>
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<?php if (file_exists(__DIR__.'/../assets/css/notifications.css')): ?>
<link rel="stylesheet" href="../assets/css/notifications.css">
<?php endif; ?>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📄</text></svg>">
<style>
/* ── Page vars ── */
:root {
    --dc-violet: #6366f1;
    --dc-violet-dk: #4f46e5;
    --dc-violet-lt: #eef2ff;
    --dc-amber: #f59e0b;
    --dc-amber-lt: #fffbeb;
    --dc-green: #10b981;
    --dc-green-lt: #ecfdf5;
    --dc-red: #ef4444;
    --dc-red-lt: #fef2f2;
}

/* ── Top bar ── */
.dc-topbar { display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem; }
.dc-title { font-size:1.2rem;font-weight:800;color:var(--text-primary); }
.dc-subtitle { font-size:.82rem;color:var(--text-secondary);margin-top:.15rem; }

/* ── Stats ── */
.dc-stats { display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem; }
.dc-stat { background:#fff;border:1.5px solid var(--border-color);border-radius:14px;padding:1rem 1.25rem;display:flex;align-items:center;gap:.8rem; }
.dc-stat-icon { width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.dc-stat-icon svg { width:18px;height:18px; }
.dc-stat-icon.violet { background:var(--dc-violet-lt);color:var(--dc-violet); }
.dc-stat-icon.amber { background:var(--dc-amber-lt);color:var(--dc-amber); }
.dc-stat-icon.green { background:var(--dc-green-lt);color:var(--dc-green); }
.dc-stat-icon.red { background:var(--dc-red-lt);color:var(--dc-red); }
.dc-stat-val { font-size:1.5rem;font-weight:900;color:var(--text-primary);line-height:1; }
.dc-stat-lbl { font-size:.72rem;color:var(--text-secondary);font-weight:600;margin-top:.2rem; }

/* ── Tabs ── */
.dc-tabs { display:flex;gap:.5rem;margin-bottom:1.25rem;border-bottom:2px solid var(--border-color);padding-bottom:0; }
.dc-tab { padding:.65rem 1.2rem;font-size:.85rem;font-weight:700;color:var(--text-secondary);border:none;background:none;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;border-radius:6px 6px 0 0;transition:all .18s;font-family:inherit; }
.dc-tab:hover { color:var(--dc-violet);background:var(--dc-violet-lt); }
.dc-tab.active { color:var(--dc-violet);border-bottom-color:var(--dc-violet); }

/* ── Search bar ── */
.dc-search { display:flex;gap:.6rem;align-items:center;margin-bottom:1rem; }
.dc-search input { flex:1;padding:.65rem 1rem;border:1.5px solid var(--border-color);border-radius:10px;font-size:.9rem;font-family:inherit;outline:none;transition:border-color .18s; }
.dc-search input:focus { border-color:var(--dc-violet); }
.dc-search-btn { padding:.65rem 1.1rem;background:var(--dc-violet);color:#fff;border:none;border-radius:10px;font-size:.85rem;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:.4rem; }
.dc-search-btn svg { width:16px;height:16px; }

/* ── Student table ── */
.dc-card { background:#fff;border:1.5px solid var(--border-color);border-radius:14px;overflow:hidden;margin-bottom:1.5rem; }
.dc-card-head { display:flex;align-items:center;gap:.75rem;padding:.9rem 1.25rem;background:#fafbfc;border-bottom:1px solid var(--border-color); }
.dc-card-head-icon { width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,var(--dc-violet),var(--dc-violet-dk));display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.dc-card-head-icon svg { width:16px;height:16px;stroke:#fff; }
.dc-card-head-title { font-weight:800;font-size:.92rem;color:var(--text-primary); }
.dc-card-head-count { margin-left:auto;background:var(--dc-violet-lt);color:var(--dc-violet);border-radius:999px;font-size:.72rem;font-weight:800;padding:.15rem .6rem; }

.dc-table { width:100%;border-collapse:collapse;font-size:.875rem; }
.dc-table thead th { padding:.75rem 1rem;text-align:left;font-size:.7rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--border-color);background:#fafbfc;white-space:nowrap; }
.dc-table tbody tr { border-bottom:1px solid #f3f4f6;transition:background .1s; }
.dc-table tbody tr:last-child { border-bottom:none; }
.dc-table tbody tr:hover { background:#f9fafb; }
.dc-table tbody td { padding:.75rem 1rem;vertical-align:middle; }
.student-name { font-weight:700;color:var(--text-primary);font-size:.875rem; }
.student-meta { font-size:.75rem;color:var(--text-secondary);margin-top:.1rem; }
.roll-badge { display:inline-flex;align-items:center;padding:.15rem .55rem;background:var(--dc-violet-lt);color:var(--dc-violet);border-radius:6px;font-size:.72rem;font-weight:700; }

/* Request button */
.btn-request { display:inline-flex;align-items:center;gap:.35rem;padding:.45rem .9rem;background:linear-gradient(135deg,var(--dc-violet),var(--dc-violet-dk));color:#fff;border:none;border-radius:8px;font-size:.78rem;font-weight:700;cursor:pointer;font-family:inherit;transition:all .2s;white-space:nowrap; }
.btn-request:hover { transform:translateY(-1px);box-shadow:0 4px 12px rgba(99,102,241,.3); }
.btn-request svg { width:13px;height:13px; }
.btn-request.has-pending { background:linear-gradient(135deg,var(--dc-amber),#d97706); }
.btn-request.has-pending:hover { box-shadow:0 4px 12px rgba(245,158,11,.3); }

/* Status badges */
.status-badge { display:inline-flex;align-items:center;gap:.3rem;padding:.22rem .65rem;border-radius:999px;font-size:.7rem;font-weight:700; }
.status-badge.pending { background:var(--dc-amber-lt);color:#92400e;border:1px solid #fde68a; }
.status-badge.approved { background:var(--dc-green-lt);color:#065f46;border:1px solid #6ee7b7; }
.status-badge.rejected { background:var(--dc-red-lt);color:#991b1b;border:1px solid #fecaca; }
.status-dot { width:5px;height:5px;border-radius:50%;background:currentColor; }

.empty-state { text-align:center;padding:3rem 1rem;color:var(--text-secondary); }
.empty-state svg { display:block;margin:0 auto .75rem;width:40px;height:40px;opacity:.3; }
.empty-state p { font-size:.875rem; }

/* cancel btn */
.btn-cancel-req { display:inline-flex;align-items:center;gap:.3rem;padding:.32rem .7rem;background:#fff;border:1.5px solid #fecaca;color:var(--dc-red);border-radius:7px;font-size:.72rem;font-weight:700;cursor:pointer;font-family:inherit;transition:all .18s; }
.btn-cancel-req:hover { background:var(--dc-red-lt); }

/* ── Request Modal ── */
.req-overlay { position:fixed;inset:0;background:rgba(15,23,42,.5);display:none;align-items:center;justify-content:center;z-index:9999;backdrop-filter:blur(3px); }
.req-overlay.open { display:flex; }
.req-modal { background:#fff;border-radius:18px;width:100%;max-width:520px;margin:1rem;box-shadow:0 20px 60px rgba(0,0,0,.18);animation:slideUp .25s ease;overflow:hidden; }
@keyframes slideUp { from { opacity:0;transform:translateY(16px) } to { opacity:1;transform:translateY(0) } }
.req-modal-head { padding:1.25rem 1.5rem;background:linear-gradient(135deg,var(--dc-violet-lt),#e0e7ff);border-bottom:1px solid #c7d2fe;display:flex;align-items:center;gap:.75rem; }
.req-modal-head-icon { width:42px;height:42px;background:linear-gradient(135deg,var(--dc-violet),var(--dc-violet-dk));border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.req-modal-head-icon svg { width:20px;height:20px;stroke:#fff; }
.req-modal-title { font-size:1rem;font-weight:800;color:var(--dc-violet-dk); }
.req-modal-sub { font-size:.78rem;color:#6366f1;margin-top:.1rem; }
.req-modal-close { margin-left:auto;background:none;border:none;color:#94a3b8;cursor:pointer;padding:4px;border-radius:6px;display:flex;transition:all .15s; }
.req-modal-close:hover { color:#1e293b;background:#f1f5f9; }
.req-modal-close svg { width:18px;height:18px; }
.req-modal-body { padding:1.5rem; }
.req-student-info { background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:.8rem 1rem;margin-bottom:1.25rem;font-size:.875rem; }
.req-student-info strong { color:var(--text-primary);font-weight:800; }
.req-student-info span { color:var(--text-secondary); }
.form-row { display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem; }
.form-row.full { grid-template-columns:1fr; }
.req-label { display:block;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-secondary);margin-bottom:.35rem; }
.req-input, .req-select, .req-textarea {
    width:100%;padding:.65rem .875rem;border:1.5px solid var(--border-color);border-radius:9px;
    font-size:.88rem;font-family:inherit;color:var(--text-primary);background:#fff;
    transition:border-color .15s;box-sizing:border-box;
}
.req-input:focus,.req-select:focus,.req-textarea:focus { border-color:var(--dc-violet);outline:none;box-shadow:0 0 0 3px rgba(99,102,241,.1); }
.req-textarea { resize:vertical; }
.req-modal-footer { padding:1rem 1.5rem;background:#fafbfc;border-top:1px solid var(--border-color);display:flex;justify-content:flex-end;gap:.65rem; }
.btn-secondary { padding:.6rem 1.2rem;border:1.5px solid var(--border-color);background:#fff;color:var(--text-primary);border-radius:9px;font-size:.875rem;font-weight:700;cursor:pointer;font-family:inherit;transition:all .18s; }
.btn-secondary:hover { background:#f1f5f9; }
.btn-primary { padding:.6rem 1.4rem;background:linear-gradient(135deg,var(--dc-violet),var(--dc-violet-dk));color:#fff;border:none;border-radius:9px;font-size:.875rem;font-weight:700;cursor:pointer;font-family:inherit;transition:all .2s;box-shadow:0 4px 12px rgba(99,102,241,.25); }
.btn-primary:hover { transform:translateY(-1px);box-shadow:0 6px 16px rgba(99,102,241,.35); }
.btn-primary:disabled { opacity:.6;cursor:not-allowed;transform:none; }

/* Toast */
#dcToastWrap { position:fixed;bottom:1.5rem;right:1.5rem;z-index:99999;display:flex;flex-direction:column;gap:.5rem; }
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
                <p>Request duplicate certificates for your students</p>
            </div>
        </div>
        <div class="header-right">
            <?php if (file_exists(__DIR__.'/../includes/notification_bell.php')) include __DIR__.'/../includes/notification_bell.php'; ?>
            <?php include __DIR__.'/../includes/profile_dropdown.php'; ?>
        </div>
    </header>

    <div class="page-content">

        <!-- Stats -->
        <?php
        $statTotal    = count($myRequests);
        $statPending  = count(array_filter($myRequests, fn($r) => $r['status'] === 'Pending'));
        $statApproved = count(array_filter($myRequests, fn($r) => $r['status'] === 'Approved'));
        $statRejected = count(array_filter($myRequests, fn($r) => $r['status'] === 'Rejected'));
        ?>
        <div class="dc-stats">
            <div class="dc-stat">
                <div class="dc-stat-icon violet">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
                <div><div class="dc-stat-val"><?= $statTotal ?></div><div class="dc-stat-lbl">Total Requests</div></div>
            </div>
            <div class="dc-stat">
                <div class="dc-stat-icon amber">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div><div class="dc-stat-val"><?= $statPending ?></div><div class="dc-stat-lbl">Pending</div></div>
            </div>
            <div class="dc-stat">
                <div class="dc-stat-icon green">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div><div class="dc-stat-val"><?= $statApproved ?></div><div class="dc-stat-lbl">Approved</div></div>
            </div>
            <div class="dc-stat">
                <div class="dc-stat-icon red">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </div>
                <div><div class="dc-stat-val"><?= $statRejected ?></div><div class="dc-stat-lbl">Rejected</div></div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="dc-tabs">
            <button class="dc-tab active" id="tabStudents" onclick="switchTab('students')">
                👥 Students — Submit Request
            </button>
            <button class="dc-tab" id="tabHistory" onclick="switchTab('history')">
                📋 My Requests
                <?php if ($statPending > 0): ?>
                <span style="margin-left:.4rem;background:#f59e0b;color:#fff;border-radius:999px;font-size:.65rem;font-weight:800;padding:.1rem .45rem;"><?= $statPending ?></span>
                <?php endif; ?>
            </button>
        </div>

        <!-- Students Tab -->
        <div id="panelStudents">
            <form method="GET" class="dc-search">
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by student name, roll no, or course…" id="searchInput">
                <button type="submit" class="dc-search-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    Search
                </button>
                <?php if ($search): ?>
                <a href="duplicate_certificates.php" style="padding:.65rem .9rem;border:1.5px solid var(--border-color);border-radius:10px;font-size:.82rem;font-weight:700;color:var(--text-secondary);text-decoration:none;white-space:nowrap;">Clear</a>
                <?php endif; ?>
            </form>

            <div class="dc-card">
                <div class="dc-card-head">
                    <div class="dc-card-head-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div class="dc-card-head-title">All Students</div>
                    <div class="dc-card-head-count"><?= count($students) ?> found</div>
                </div>
                <div style="overflow-x:auto;">
                    <table class="dc-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Student</th>
                                <th>Roll No</th>
                                <th>Course</th>
                                <th>Mobile</th>
                                <th>Status</th>
                                <th style="text-align:center;">Request</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($students)): ?>
                            <tr><td colspan="7">
                                <div class="empty-state">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                                    <p>No students found<?= $search ? " for \"$search\"" : '' ?>.</p>
                                </div>
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($students as $i => $stu): ?>
                            <tr>
                                <td style="color:var(--text-secondary);font-size:.8rem;"><?= $i + 1 ?></td>
                                <td>
                                    <div class="student-name"><?= htmlspecialchars(trim($stu['full_name'])) ?></div>
                                    <div class="student-meta">Reg: <?= htmlspecialchars($stu['registration_id']) ?></div>
                                </td>
                                <td><span class="roll-badge"><?= htmlspecialchars($stu['roll_no'] ?? '—') ?></span></td>
                                <td style="font-size:.82rem;max-width:160px;"><?= htmlspecialchars($stu['course'] ?? '—') ?></td>
                                <td style="font-size:.82rem;"><?= htmlspecialchars($stu['mobile'] ?? '—') ?></td>
                                <td>
                                    <span class="status-badge <?= strtolower($stu['status']) === 'active' ? 'approved' : 'pending' ?>">
                                        <span class="status-dot"></span>
                                        <?= htmlspecialchars($stu['status']) ?>
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    <button class="btn-request <?= $stu['pending_reqs'] > 0 ? 'has-pending' : '' ?>"
                                        onclick="openRequestModal(<?= $stu['id'] ?>, '<?= htmlspecialchars(addslashes(trim($stu['full_name']))) ?>', '<?= htmlspecialchars(addslashes($stu['roll_no'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($stu['course'] ?? '')) ?>')">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                                        <?= $stu['pending_reqs'] > 0 ? 'Request (Pending)' : 'Request' ?>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div><!-- /panelStudents -->

        <!-- My Requests Tab -->
        <div id="panelHistory" style="display:none;">
            <div class="dc-card">
                <div class="dc-card-head">
                    <div class="dc-card-head-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <div class="dc-card-head-title">My Requests History</div>
                    <div class="dc-card-head-count"><?= count($myRequests) ?> total</div>
                </div>
                <div style="overflow-x:auto;">
                    <table class="dc-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Student</th>
                                <th>Certificate Type</th>
                                <th>Reason</th>
                                <th>Requested On</th>
                                <th>Status</th>
                                <th>Admin Note</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($myRequests)): ?>
                            <tr><td colspan="8">
                                <div class="empty-state">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    <p>No requests submitted yet.</p>
                                </div>
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($myRequests as $i => $req): ?>
                            <tr>
                                <td style="color:var(--text-secondary);font-size:.8rem;"><?= $i + 1 ?></td>
                                <td>
                                    <div class="student-name"><?= htmlspecialchars($req['student_name']) ?></div>
                                    <div class="student-meta"><?= htmlspecialchars($req['roll_no'] ?? '') ?> · <?= htmlspecialchars($req['course'] ?? '') ?></div>
                                </td>
                                <td style="font-size:.82rem;font-weight:600;"><?= htmlspecialchars($req['cert_type']) ?></td>
                                <td>
                                    <span style="display:inline-block;padding:.2rem .6rem;border-radius:6px;font-size:.72rem;font-weight:700;
                                    <?= $req['reason'] === 'Damaged' ? 'background:#fef3c7;color:#92400e;' : ($req['reason'] === 'Name Correction' ? 'background:#ede9fe;color:#5b21b6;' : 'background:#f0fdf4;color:#166534;') ?>">
                                        <?= htmlspecialchars($req['reason']) ?>
                                    </span>
                                </td>
                                <td style="font-size:.8rem;color:var(--text-secondary);"><?= date('d M Y, h:i A', strtotime($req['requested_at'])) ?></td>
                                <td>
                                    <span class="status-badge <?= strtolower($req['status']) ?>">
                                        <span class="status-dot"></span>
                                        <?= $req['status'] ?>
                                    </span>
                                </td>
                                <td style="font-size:.8rem;color:var(--text-secondary);max-width:160px;"><?= htmlspecialchars($req['admin_note'] ?? '—') ?></td>
                                <td>
                                    <?php if ($req['status'] === 'Pending'): ?>
                                    <button class="btn-cancel-req" onclick="cancelRequest(<?= $req['id'] ?>)">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                        Cancel
                                    </button>
                                    <?php else: ?>
                                    <span style="font-size:.75rem;color:#94a3b8;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div><!-- /panelHistory -->

    </div><!-- /.page-content -->
</main>
</div>

<!-- ── Request Modal ── -->
<div class="req-overlay" id="reqOverlay">
    <div class="req-modal">
        <div class="req-modal-head">
            <div class="req-modal-head-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
            </div>
            <div>
                <div class="req-modal-title">Request Duplicate Certificate</div>
                <div class="req-modal-sub" id="modalStudentLabel">—</div>
            </div>
            <button class="req-modal-close" onclick="closeModal()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="req-modal-body">
            <div class="req-student-info" id="modalStudentInfo">—</div>
            <div class="form-row">
                <div>
                    <label class="req-label">Certificate Type <span style="color:#ef4444">*</span></label>
                    <select class="req-select" id="modalCertType">
                        <option value="">— Select —</option>
                        <option value="Course Completion Certificate">Course Completion Certificate</option>
                        <option value="Exam Certificate">Exam Certificate</option>
                    </select>
                </div>
                <div>
                    <label class="req-label">Reason <span style="color:#ef4444">*</span></label>
                    <select class="req-select" id="modalReason">
                        <option value="">— Select —</option>
                        <option value="Name Correction">Name Correction</option>
                        <option value="Misplaced by Student">Misplaced by Student</option>
                        <option value="Damaged">Damaged</option>
                    </select>
                </div>
            </div>
            <div class="form-row full">
                <div>
                    <label class="req-label">Additional Remarks <span style="color:#94a3b8;">(optional)</span></label>
                    <textarea class="req-textarea" id="modalRemarks" rows="3" placeholder="Any additional details…"></textarea>
                </div>
            </div>
        </div>
        <div class="req-modal-footer">
            <button class="btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn-primary" id="modalSubmitBtn" onclick="submitRequest()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px;margin-right:.3rem"><polyline points="20 6 9 17 4 12"/></svg>
                Submit Request
            </button>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="dcToastWrap"></div>

<script src="../assets/js/dashboard.js"></script>
<script>
let currentAdmissionId = null;

// Tab switching
function switchTab(tab) {
    document.getElementById('panelStudents').style.display = tab === 'students' ? '' : 'none';
    document.getElementById('panelHistory').style.display  = tab === 'history'  ? '' : 'none';
    document.getElementById('tabStudents').classList.toggle('active', tab === 'students');
    document.getElementById('tabHistory').classList.toggle('active',  tab === 'history');
}

// Open modal
function openRequestModal(admissionId, name, rollNo, course) {
    currentAdmissionId = admissionId;
    document.getElementById('modalStudentLabel').textContent = name;
    document.getElementById('modalStudentInfo').innerHTML =
        `<strong>${name}</strong> &nbsp;|&nbsp; <span>Roll No: ${rollNo || '—'}</span> &nbsp;|&nbsp; <span>Course: ${course || '—'}</span>`;
    document.getElementById('modalCertType').value = '';
    document.getElementById('modalReason').value = '';
    document.getElementById('modalRemarks').value = '';
    document.getElementById('reqOverlay').classList.add('open');
}

function closeModal() {
    document.getElementById('reqOverlay').classList.remove('open');
    currentAdmissionId = null;
}

document.getElementById('reqOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// Submit
async function submitRequest() {
    if (!currentAdmissionId) return;
    const certType = document.getElementById('modalCertType').value;
    const reason   = document.getElementById('modalReason').value;
    const remarks  = document.getElementById('modalRemarks').value;

    if (!certType) { dcToast('Please select a certificate type.', 'error'); return; }
    if (!reason)   { dcToast('Please select a reason.', 'error'); return; }

    const btn = document.getElementById('modalSubmitBtn');
    btn.disabled = true; btn.textContent = 'Submitting…';

    const fd = new FormData();
    fd.append('action', 'submit_request');
    fd.append('admission_id', currentAdmissionId);
    fd.append('cert_type', certType);
    fd.append('reason', reason);
    fd.append('remarks', remarks);

    try {
        const res = await fetch('', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            dcToast('✅ ' + data.message);
            closeModal();
            setTimeout(() => location.reload(), 1500);
        } else {
            dcToast(data.message, 'error');
        }
    } catch(e) {
        dcToast('Network error. Please try again.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px;margin-right:.3rem"><polyline points="20 6 9 17 4 12"/></svg>Submit Request';
    }
}

// Cancel request
async function cancelRequest(reqId) {
    if (!confirm('Cancel this request?')) return;
    const fd = new FormData();
    fd.append('action', 'cancel_request');
    fd.append('req_id', reqId);
    try {
        const res = await fetch('', { method: 'POST', body: fd });
        const data = await res.json();
        dcToast(data.success ? '✅ ' + data.message : data.message, data.success ? 'success' : 'error');
        if (data.success) setTimeout(() => location.reload(), 1200);
    } catch(e) { dcToast('Error.', 'error'); }
}

// Toast
function dcToast(msg, type = 'success') {
    const t = document.createElement('div');
    t.style.cssText = `background:${type==='success'?'#059669':'#dc2626'};color:#fff;padding:.75rem 1.25rem;border-radius:10px;font-size:.875rem;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.15);animation:slideUp .3s ease;max-width:340px`;
    t.textContent = msg;
    document.getElementById('dcToastWrap').appendChild(t);
    setTimeout(() => t.remove(), 3500);
}
</script>
</body>
</html>
