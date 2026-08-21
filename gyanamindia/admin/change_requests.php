<?php
/**
 * Gyanam Portal — Admin: Change Requests
 * Allows Admin to review, approve or reject ATC-submitted student data change requests.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo      = getDBConnection();
$userName = sanitize(getUserName());
$userId   = getUserId();

// ── AJAX handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        switch ($_POST['action']) {

            case 'approve_request':
                $id    = intval($_POST['id']);
                $allowedFields = [
                    'first_name','middle_name','last_name','gender','dob','qualification','course',
                    'mobile','phone','email','address','city','pin_code','referenced_by','comment','status','photo'
                ];

                $pdo->beginTransaction();

                $getReq = $pdo->prepare("SELECT * FROM change_requests WHERE id = ? AND status = 'Pending' FOR UPDATE");
                $getReq->execute([$id]);
                $request = $getReq->fetch(PDO::FETCH_ASSOC);

                if (!$request) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Request already processed or not found.']);
                    exit;
                }

                if (!in_array($request['field_name'], $allowedFields, true)) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'This field is not allowed for approval update.']);
                    exit;
                }

                $apply = $pdo->prepare("UPDATE admissions SET {$request['field_name']} = ? WHERE id = ? AND atc_id = ?");
                $apply->execute([$request['new_value'], $request['admission_id'], $request['atc_id']]);

                if ($apply->rowCount() === 0) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Student not found for this request.']);
                    exit;
                }

                $mark = $pdo->prepare("
                    UPDATE change_requests
                    SET status = 'Approved', applied = 1, one_time_token = NULL, reviewed_at = NOW(), reviewed_by = ?
                    WHERE id = ?
                ");
                $mark->execute([$userId, $id]);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Request approved and student data updated.']);
                exit;

            case 'reject_request':
                $id     = intval($_POST['id']);
                $reason = trim($_POST['reject_reason'] ?? '');
                if (!$reason) { echo json_encode(['success' => false, 'message' => 'Please provide a rejection reason.']); exit; }
                $stmt = $pdo->prepare("
                    UPDATE change_requests
                    SET status = 'Rejected', reject_reason = ?, reviewed_at = NOW(), reviewed_by = ?
                    WHERE id = ? AND status = 'Pending'
                ");
                $stmt->execute([$reason, $userId, $id]);
                echo json_encode(['success' => true, 'message' => 'Request rejected.']);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ── Page data ──────────────────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'Pending';
$validStatuses = ['Pending', 'Approved', 'Rejected', 'all'];
if (!in_array($statusFilter, $validStatuses)) $statusFilter = 'Pending';

$sql = "
    SELECT cr.*,
           a.first_name, a.middle_name, a.last_name, a.roll_no, a.registration_id,
           ac.name AS atc_name, ac.district AS atc_district, ac.state AS atc_state,
           u.name AS reviewed_by_name
    FROM change_requests cr
    JOIN admissions a ON a.id = cr.admission_id
    JOIN atc_centers ac ON ac.id = cr.atc_id
    LEFT JOIN users u ON u.id = cr.reviewed_by
";
$params = [];
if ($statusFilter !== 'all') {
    $sql .= " WHERE cr.status = ?";
    $params[] = $statusFilter;
}
$sql .= " ORDER BY cr.requested_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts for tabs
$counts = [];
foreach (['Pending', 'Approved', 'Rejected'] as $s) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM change_requests WHERE status = ?");
    $st->execute([$s]);
    $counts[$s] = $st->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Approve Edits — Admin | Gyanam India</title>
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<link rel="stylesheet" href="../assets/css/notifications.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔄</text></svg>">
<style>
.cr-badge { display:inline-flex; align-items:center; gap:.3rem; padding:.25rem .65rem; border-radius:99px; font-size:.75rem; font-weight:700; }
.cr-badge.pending  { background:#fef3c7; color:#92400e; }
.cr-badge.approved { background:#d1fae5; color:#065f46; }
.cr-badge.rejected { background:#fee2e2; color:#991b1b; }
.cr-badge.applied  { background:#e0e7ff; color:#3730a3; }
.atc-pill { display:inline-flex; align-items:center; gap:.4rem; padding:.3rem .75rem; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; font-size:.8rem; font-weight:600; color:#1d4ed8; }
.field-change { display:flex; align-items:center; gap:.5rem; font-size:.85rem; flex-wrap:wrap; }
.field-old { color:#6b7280; text-decoration:line-through; }
.field-arrow { color:#9ca3af; }
.field-new  { color:#059669; font-weight:700; }
.val-box { padding:.2rem .5rem; background:#f3f4f6; border-radius:4px; max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.reason-text { font-size:.8rem; color:#6b7280; font-style:italic; max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.reject-form { display:none; margin-top:.5rem; }
.reject-area { width:100%; min-width:180px; padding:.5rem; font-size:.82rem; border:1.5px solid #e5e7eb; border-radius:6px; font-family:inherit; resize:none; }
.reject-actions { display:flex; gap:.4rem; margin-top:.4rem; }
.cr-action-btn {
    display:inline-flex; align-items:center; justify-content:center; gap:.3rem;
    border-radius:8px; padding:.35rem .8rem; font-size:.78rem; font-weight:700;
    border:1px solid transparent; cursor:pointer;
}
.cr-action-btn.approve { background:#ecfdf5; color:#065f46; border-color:#a7f3d0; }
.cr-action-btn.reject { background:#fef2f2; color:#991b1b; border-color:#fecaca; }
.cr-action-btn:hover { filter:brightness(.98); }

/* KPI Cards (Clean Side-Border Style) */
.kpi-row { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:1.25rem; margin-bottom:1.75rem; }
.kpi-card { background:var(--bg-surface, #fff); border:1.5px solid var(--border-color, #e5e7eb); border-radius:16px; padding:1.25rem 1.5rem; display:flex; align-items:center; gap:1rem; position:relative; overflow:hidden; transition:all 0.2s; }
.kpi-card:hover { border-color:#d1d5db; transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,0.05); }
.kpi-card.active { border-color:#6366f1; background:#f5f3ff; }
.kpi-card::before { content:''; position:absolute; top:0; left:0; width:4px; height:100%; }

.kpi-card.amber::before { background:#f59e0b; }
.kpi-card.green::before { background:#10b981; }
.kpi-card.rose::before { background:#ef4444; }
.kpi-card.blue::before { background:#3b82f6; }

.kpi-icon { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.kpi-icon svg { width:20px; height:20px; }

.kpi-card.amber .kpi-icon { background:#fffbeb; color:#f59e0b; }
.kpi-card.green .kpi-icon { background:#ecfdf5; color:#10b981; }
.kpi-card.rose .kpi-icon { background:#fef2f2; color:#ef4444; }
.kpi-card.blue .kpi-icon { background:#eff6ff; color:#3b82f6; }

.kpi-val { font-size:1.7rem; font-weight:800; color:var(--text-primary, #111827); line-height:1; }
.kpi-lbl { font-size:.72rem; font-weight:700; color:var(--text-muted, #6b7280); text-transform:uppercase; letter-spacing:.05em; margin-top:.2rem; }
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
                <h2>Approve Edits</h2>
                <p>Review and approve student edit requests from ATC centers</p>
            </div>
        </div>
        <div class="header-right">
            <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
            <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
        </div>
    </header>

    <div class="page-content">

        <?php 
            $totalCount = array_sum($counts);
            
            // Map status to specific colors and icons matching the reference
            $cards = [
                'Pending' => ['color' => 'amber', 'label' => 'Pending Review', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'],
                'Approved' => ['color' => 'green', 'label' => 'Approved', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'],
                'Rejected' => ['color' => 'rose', 'label' => 'Rejected', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>'],
                'all' => ['color' => 'blue', 'label' => 'All Requests', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>']
            ];
        ?>
        
        <!-- KPI Row for Status Filtering -->
        <div class="kpi-row">
            <?php foreach (['Pending', 'Approved', 'Rejected', 'all'] as $s): 
                $card = $cards[$s];
                $isActive = $statusFilter === $s;
                $val = $s === 'all' ? $totalCount : $counts[$s];
            ?>
            <a href="?status=<?= $s ?>" style="text-decoration: none; color: inherit; display: block;">
                <div class="kpi-card <?= $card['color'] ?> <?= $isActive ? 'active' : '' ?>">
                    <div class="kpi-icon"><?= $card['icon'] ?></div>
                    <div>
                        <div class="kpi-val"><?= number_format($val) ?></div>
                        <div class="kpi-lbl"><?= $card['label'] ?></div>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="page-toolbar">
            <h3>
                Requested Student Edit Details
                <span class="badge-count"><?= count($requests) ?></span>
            </h3>
        </div>

        <div class="table-card">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>ATC Center</th>
                        <th>Student</th>
                        <th>Field</th>
                        <th>Change</th>
                        <th>Reason</th>
                        <th>Requested</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($requests)): ?>
                    <tr>
                        <td colspan="9" class="table-empty">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <p>No <?= $statusFilter !== 'all' ? strtolower($statusFilter) : '' ?> change requests found.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($requests as $i => $cr):
                        $studentName = trim($cr['first_name'] . ' ' . ($cr['middle_name'] ? $cr['middle_name'] . ' ' : '') . $cr['last_name']);
                        $statusClass = strtolower($cr['status']);
                        if ($cr['status'] === 'Approved' && $cr['applied']) $statusClass = 'applied';
                    ?>
                    <tr id="row-<?= $cr['id'] ?>">
                        <td><?= $i + 1 ?></td>
                        <td>
                            <div class="atc-pill">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                                <?= htmlspecialchars($cr['atc_name']) ?>
                            </div>
                            <div class="cell-sub" style="margin-top:.25rem"><?= htmlspecialchars($cr['atc_district']) ?>, <?= htmlspecialchars($cr['atc_state']) ?></div>
                        </td>
                        <td>
                            <div class="cell-name"><?= htmlspecialchars($studentName) ?></div>
                            <div class="cell-sub">
                                <?= htmlspecialchars($cr['registration_id'] ?? $cr['roll_no']) ?>
                            </div>
                        </td>
                        <td>
                            <span style="font-weight:700;font-size:.85rem"><?= htmlspecialchars($cr['field_label']) ?></span>
                            <div class="cell-sub" style="font-family:monospace"><?= htmlspecialchars($cr['field_name']) ?></div>
                        </td>
                        <td>
                            <div class="field-change">
                                <span class="val-box field-old" title="<?= htmlspecialchars($cr['old_value'] ?? '—') ?>"><?= htmlspecialchars($cr['old_value'] ?: '—') ?></span>
                                <span class="field-arrow">&#8594;</span>
                                <span class="val-box field-new" title="<?= htmlspecialchars($cr['new_value']) ?>"><?= htmlspecialchars($cr['new_value']) ?></span>
                            </div>
                        </td>
                        <td>
                            <span class="reason-text" title="<?= htmlspecialchars($cr['reason']) ?>"><?= htmlspecialchars($cr['reason']) ?></span>
                            <?php if ($cr['status'] === 'Rejected' && $cr['reject_reason']): ?>
                                <div class="cell-sub" style="color:#dc2626" title="<?= htmlspecialchars($cr['reject_reason']) ?>">&#10005; <?= htmlspecialchars(substr($cr['reject_reason'], 0, 40)) ?>…</div>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;font-size:.82rem">
                            <?= date('d M Y', strtotime($cr['requested_at'])) ?>
                            <div class="cell-sub"><?= date('h:i A', strtotime($cr['requested_at'])) ?></div>
                        </td>
                        <td>
                            <?php if ($cr['status'] === 'Approved' && $cr['applied']): ?>
                                <span class="cr-badge applied">&#10003; Applied</span>
                            <?php else: ?>
                                <span class="cr-badge <?= $statusClass ?>"><?= $cr['status'] ?></span>
                            <?php endif; ?>
                            <?php if ($cr['reviewed_by_name']): ?>
                                <div class="cell-sub">by <?= htmlspecialchars($cr['reviewed_by_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($cr['status'] === 'Pending'): ?>
                            <div class="cell-actions" style="flex-direction:column;align-items:flex-start;gap:.4rem">
                                <button class="cr-action-btn approve"
                                        onclick="approveRequest(<?= $cr['id'] ?>)">
                                    &#10003; Approve
                                </button>
                                <button class="cr-action-btn reject"
                                        onclick="showRejectForm(<?= $cr['id'] ?>)">
                                    &#10005; Reject
                                </button>
                                <div class="reject-form" id="reject-form-<?= $cr['id'] ?>">
                                    <textarea class="reject-area" id="reject-reason-<?= $cr['id'] ?>" rows="2" placeholder="Reason for rejection…"></textarea>
                                    <div class="reject-actions">
                                        <button class="btn-danger" style="font-size:.75rem;padding:.3rem .7rem"
                                                onclick="rejectRequest(<?= $cr['id'] ?>)">Confirm</button>
                                        <button class="btn-secondary" style="font-size:.75rem;padding:.3rem .7rem"
                                                onclick="hideRejectForm(<?= $cr['id'] ?>)">Cancel</button>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <span style="font-size:.8rem;color:#9ca3af">—</span>
                            <?php endif; ?>
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

<!-- Toast -->
<div id="toastContainer" style="position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;display:flex;flex-direction:column;gap:.5rem"></div>

<script src="../assets/js/dashboard.js"></script>
<script>
function showToast(msg, type = 'success') {
    const t = document.createElement('div');
    const bg = type === 'success' ? '#059669' : '#dc2626';
    t.style.cssText = `background:${bg};color:#fff;padding:.75rem 1.25rem;border-radius:10px;font-size:.875rem;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.18);animation:slideUp .3s ease;max-width:320px`;
    t.textContent = msg;
    document.getElementById('toastContainer').appendChild(t);
    setTimeout(() => t.remove(), 3500);
}

async function approveRequest(id) {
    if (!confirm('Approve this change request? Student details will update immediately.')) return;
    const fd = new FormData();
    fd.append('action', 'approve_request');
    fd.append('id', id);
    const res = await fetch('', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
        showToast('\u2705 ' + data.message);
        setTimeout(() => location.reload(), 1200);
    } else {
        showToast(data.message, 'error');
    }
}

function showRejectForm(id) {
    document.getElementById('reject-form-' + id).style.display = 'block';
}
function hideRejectForm(id) {
    document.getElementById('reject-form-' + id).style.display = 'none';
}

async function rejectRequest(id) {
    const reason = document.getElementById('reject-reason-' + id).value.trim();
    if (!reason) { showToast('Please enter a rejection reason.', 'error'); return; }
    const fd = new FormData();
    fd.append('action', 'reject_request');
    fd.append('id', id);
    fd.append('reject_reason', reason);
    const res = await fetch('', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
        showToast('\u274C ' + data.message);
        setTimeout(() => location.reload(), 1200);
    } else {
        showToast(data.message, 'error');
    }
}
</script>
<style>
@keyframes slideUp { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
</style>
</body>
</html>
