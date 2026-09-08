<?php
/**
 * Admin: ATC Center onboarding enquiries → convert to ATC
 * Parallel to student inquiry → admission. Direct Add ATC remains on atc_centers.php.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
ensureAtcOnboardingEnquirySchema($pdo);

$centerTypes = [
    'Abacus', 'Vedic Maths', 'IT', 'Abacus + IT', 'Abacus + Vedic Maths',
    'Vedic Maths + IT', 'Abacus + Vedic Maths + IT',
];
$enquirySources = ['Phone', 'Walk-in', 'Online', 'Reference', 'Other'];
$statuses = ['New', 'Contacted', 'Converted', 'Closed'];
$states = [
    'Maharashtra', 'Gujarat', 'Karnataka', 'Delhi', 'Rajasthan', 'Uttar Pradesh', 'Madhya Pradesh',
    'Tamil Nadu', 'West Bengal', 'Telangana', 'Andhra Pradesh', 'Kerala', 'Punjab', 'Haryana',
    'Bihar', 'Odisha', 'Jharkhand', 'Chhattisgarh', 'Assam', 'Other',
];

$dlcOffices = $pdo->query("SELECT id, name FROM dlc_offices WHERE status = 'Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$pageMode = $_GET['mode'] ?? '';
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

function atcEnquiryCollectPost(array $src): array {
    return [
        'center_name'            => trim((string)($src['center_name'] ?? '')),
        'contact_person'         => trim((string)($src['contact_person'] ?? '')),
        'mobile'                 => trim((string)($src['mobile'] ?? '')),
        'alternate_mobile'       => trim((string)($src['alternate_mobile'] ?? '')),
        'email'                  => trim((string)($src['email'] ?? '')),
        'interested_center_type' => trim((string)($src['interested_center_type'] ?? '')),
        'preferred_dlc_id'       => !empty($src['preferred_dlc_id']) ? (int)$src['preferred_dlc_id'] : null,
        'address'                => trim((string)($src['address'] ?? '')),
        'district'               => trim((string)($src['district'] ?? '')),
        'taluka'                 => trim((string)($src['taluka'] ?? '')),
        'city'                   => trim((string)($src['city'] ?? '')),
        'state'                  => trim((string)($src['state'] ?? 'Maharashtra')),
        'pin_code'               => trim((string)($src['pin_code'] ?? '')),
        'enquiry_source'         => trim((string)($src['enquiry_source'] ?? 'Phone')),
        'enquiry_date'           => !empty($src['enquiry_date']) ? $src['enquiry_date'] : date('Y-m-d'),
        'next_followup_date'     => !empty($src['next_followup_date']) ? $src['next_followup_date'] : null,
        'next_followup_time'     => trim((string)($src['next_followup_time'] ?? '')) ?: null,
        'referenced_by'          => trim((string)($src['referenced_by'] ?? '')),
        'comment'                => trim((string)($src['comment'] ?? '')),
        'status'                 => trim((string)($src['status'] ?? 'New')),
    ];
}

function atcEnquiryValidate(array $d): ?string {
    if ($d['center_name'] === '') {
        return 'Center name is required.';
    }
    if ($d['contact_person'] === '') {
        return 'Contact person is required.';
    }
    if ($d['mobile'] === '' || !preg_match('/^[0-9]{10}$/', $d['mobile'])) {
        return 'Enter a valid 10-digit mobile number.';
    }
    if ($d['interested_center_type'] === '') {
        return 'Interested center type is required.';
    }
    $allowed = ['New', 'Contacted', 'Converted', 'Closed'];
    if (!in_array($d['status'], $allowed, true)) {
        return 'Invalid status.';
    }
    return null;
}

// Full-page add / edit submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['add_page', 'edit_page'], true)) {
    $action = $_POST['action'];
    $data = atcEnquiryCollectPost($_POST);
    $err = atcEnquiryValidate($data);
    if ($err) {
        $redir = $action === 'edit_page'
            ? 'atc_enquiries.php?mode=edit&id=' . (int)($_POST['id'] ?? 0) . '&error=' . urlencode($err)
            : 'atc_enquiries.php?mode=add&error=' . urlencode($err);
        header('Location: ' . $redir);
        exit;
    }
    try {
        if ($action === 'add_page') {
            $stmt = $pdo->prepare("
                INSERT INTO atc_onboarding_enquiries (
                    center_name, contact_person, mobile, alternate_mobile, email,
                    interested_center_type, preferred_dlc_id, address, district, taluka,
                    city, state, pin_code, enquiry_source, enquiry_date,
                    next_followup_date, next_followup_time, referenced_by, comment, status, created_by
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $data['center_name'], $data['contact_person'], $data['mobile'], $data['alternate_mobile'] ?: null,
                $data['email'] ?: null, $data['interested_center_type'], $data['preferred_dlc_id'],
                $data['address'] ?: null, $data['district'] ?: null, $data['taluka'] ?: null,
                $data['city'] ?: null, $data['state'] ?: 'Maharashtra', $data['pin_code'] ?: null,
                $data['enquiry_source'] ?: 'Phone', $data['enquiry_date'],
                $data['next_followup_date'], $data['next_followup_time'], $data['referenced_by'] ?: null,
                $data['comment'] ?: null, $data['status'] === 'Converted' ? 'New' : $data['status'],
                $userName,
            ]);
            header('Location: atc_enquiries.php?saved=1');
            exit;
        }

        $id = (int)($_POST['id'] ?? 0);
        $cur = $pdo->prepare("SELECT status FROM atc_onboarding_enquiries WHERE id = ?");
        $cur->execute([$id]);
        $curStatus = $cur->fetchColumn();
        if (!$curStatus) {
            header('Location: atc_enquiries.php?err=' . urlencode('Enquiry not found'));
            exit;
        }
        // Never set Converted from edit form — only via ATC create
        if ($data['status'] === 'Converted' && $curStatus !== 'Converted') {
            $data['status'] = $curStatus;
        }
        if ($curStatus === 'Converted') {
            $data['status'] = 'Converted';
        }
        $stmt = $pdo->prepare("
            UPDATE atc_onboarding_enquiries SET
                center_name=?, contact_person=?, mobile=?, alternate_mobile=?, email=?,
                interested_center_type=?, preferred_dlc_id=?, address=?, district=?, taluka=?,
                city=?, state=?, pin_code=?, enquiry_source=?, enquiry_date=?,
                next_followup_date=?, next_followup_time=?, referenced_by=?, comment=?, status=?
            WHERE id=?
        ");
        $stmt->execute([
            $data['center_name'], $data['contact_person'], $data['mobile'], $data['alternate_mobile'] ?: null,
            $data['email'] ?: null, $data['interested_center_type'], $data['preferred_dlc_id'],
            $data['address'] ?: null, $data['district'] ?: null, $data['taluka'] ?: null,
            $data['city'] ?: null, $data['state'] ?: 'Maharashtra', $data['pin_code'] ?: null,
            $data['enquiry_source'] ?: 'Phone', $data['enquiry_date'],
            $data['next_followup_date'], $data['next_followup_time'], $data['referenced_by'] ?: null,
            $data['comment'] ?: null, $data['status'], $id,
        ]);
        header('Location: atc_enquiries.php?updated=1');
        exit;
    } catch (Exception $e) {
        header('Location: atc_enquiries.php?mode=add&error=' . urlencode($e->getMessage()));
        exit;
    }
}

// AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        switch ($_POST['action']) {
            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                $st = $pdo->prepare("SELECT status FROM atc_onboarding_enquiries WHERE id = ?");
                $st->execute([$id]);
                $rowStatus = $st->fetchColumn();
                if (!$rowStatus) {
                    echo json_encode(['success' => false, 'message' => 'Enquiry not found']);
                    exit;
                }
                if ($rowStatus === 'Converted') {
                    echo json_encode(['success' => false, 'message' => 'Converted enquiries cannot be deleted.']);
                    exit;
                }
                $pdo->prepare("DELETE FROM atc_onboarding_enquiries WHERE id = ?")->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Enquiry deleted']);
                exit;

            case 'get':
                $st = $pdo->prepare("SELECT * FROM atc_onboarding_enquiries WHERE id = ?");
                $st->execute([(int)($_POST['id'] ?? 0)]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                echo json_encode($row ? ['success' => true, 'data' => $row] : ['success' => false, 'message' => 'Not found']);
                exit;

            case 'set_status':
                $id = (int)($_POST['id'] ?? 0);
                $newStatus = trim((string)($_POST['status'] ?? ''));
                if (!in_array($newStatus, ['New', 'Contacted', 'Closed'], true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid status (use Convert via ATC form).']);
                    exit;
                }
                $st = $pdo->prepare("SELECT status FROM atc_onboarding_enquiries WHERE id = ?");
                $st->execute([$id]);
                if ($st->fetchColumn() === 'Converted') {
                    echo json_encode(['success' => false, 'message' => 'Already converted.']);
                    exit;
                }
                $pdo->prepare("UPDATE atc_onboarding_enquiries SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
                echo json_encode(['success' => true, 'message' => 'Status updated']);
                exit;
        }
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

$editRow = null;
if ($pageMode === 'edit' && $editId > 0) {
    $st = $pdo->prepare("SELECT * FROM atc_onboarding_enquiries WHERE id = ?");
    $st->execute([$editId]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$editRow) {
        header('Location: atc_enquiries.php?err=' . urlencode('Enquiry not found'));
        exit;
    }
}

$statusFilter = $_GET['status'] ?? 'all';
$searchTerm = trim((string)($_GET['search'] ?? ''));
$pagerParams = paginationParams(25);

$where = [];
$params = [];
if ($statusFilter !== 'all') {
    $where[] = 'e.status = ?';
    $params[] = $statusFilter;
} else {
    $where[] = "e.status != 'Converted'";
}
if ($searchTerm !== '') {
    $where[] = '(e.center_name LIKE ? OR e.contact_person LIKE ? OR e.mobile LIKE ? OR e.district LIKE ? OR e.city LIKE ?)';
    $like = '%' . $searchTerm . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM atc_onboarding_enquiries e' . $whereSql);
$countStmt->execute($params);
$pager = paginationMeta((int)$countStmt->fetchColumn(), $pagerParams);

$listStmt = $pdo->prepare("
    SELECT e.*, d.name AS dlc_name, a.atc_code AS converted_atc_code
    FROM atc_onboarding_enquiries e
    LEFT JOIN dlc_offices d ON d.id = e.preferred_dlc_id
    LEFT JOIN atc_centers a ON a.id = e.converted_atc_id
    $whereSql
    ORDER BY e.created_at DESC
    LIMIT {$pager['per_page']} OFFSET {$pager['offset']}
");
$listStmt->execute($params);
$enquiries = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$statusCounts = ['all' => 0, 'New' => 0, 'Contacted' => 0, 'Converted' => 0, 'Closed' => 0];
foreach ($pdo->query("SELECT status, COUNT(*) AS c FROM atc_onboarding_enquiries GROUP BY status") as $row) {
    $statusCounts[$row['status']] = (int)$row['c'];
    if ($row['status'] !== 'Converted') {
        $statusCounts['all'] += (int)$row['c'];
    }
}

$fv = function (string $key, $default = '') use ($editRow) {
    if ($editRow && array_key_exists($key, $editRow) && $editRow[$key] !== null) {
        return (string)$editRow[$key];
    }
    return (string)$default;
};

$formError = trim((string)($_GET['error'] ?? ''));
$isForm = in_array($pageMode, ['add', 'edit'], true);
$pageTitle = $pageMode === 'edit' ? 'Edit ATC Enquiry' : ($pageMode === 'add' ? 'Add ATC Enquiry' : 'ATC Enquiries');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> — Admin | Gyanam India</title>
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<?php if (file_exists(__DIR__.'/../assets/css/notifications.css')): ?>
<link rel="stylesheet" href="../assets/css/notifications.css">
<?php endif; ?>
<style>
.page-header-block { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:1.25rem; }
.page-header-left { display:flex; align-items:center; gap:.9rem; }
.page-header-icon {
    width:48px; height:48px; border-radius:14px; display:flex; align-items:center; justify-content:center;
    background:linear-gradient(135deg,#eff6ff,#e0e7ff); color:#4338ca; border:1px solid #c7d2fe;
}
.page-header-icon svg { width:22px; height:22px }
.page-header-title { font-size:1.15rem; font-weight:800; color:#0f172a }
.page-header-subtitle { font-size:.82rem; color:#64748b; font-weight:500; margin-top:.15rem }
.btn-primary-action {
    display:inline-flex; align-items:center; gap:.4rem; height:42px; padding:0 1.15rem;
    border-radius:10px; border:none; background:linear-gradient(135deg,#4361ee,#3b82f6);
    color:#fff; font-weight:800; font-size:.85rem; text-decoration:none; font-family:inherit;
    box-shadow:0 3px 12px rgba(67,97,238,.28);
}
.btn-secondary-action {
    display:inline-flex; align-items:center; gap:.4rem; height:42px; padding:0 1rem;
    border-radius:10px; border:1.5px solid #e5e7eb; background:#fff; color:#334155;
    font-weight:700; font-size:.85rem; text-decoration:none; font-family:inherit;
}
.header-actions { display:flex; gap:.55rem; flex-wrap:wrap; align-items:center }
.enq-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.85rem; margin-bottom:1.15rem }
@media (max-width:900px){ .enq-stats { grid-template-columns:repeat(2,minmax(0,1fr)) } }
.enq-stat {
    background:#fff; border:1.5px solid #e6eaf3; border-radius:14px; padding:.9rem 1.05rem;
    box-shadow:0 1px 4px rgba(0,0,0,.04);
}
.enq-stat .n { font-size:1.35rem; font-weight:800; color:#0f172a; line-height:1.1 }
.enq-stat .l { font-size:.75rem; font-weight:600; color:#64748b; margin-top:.2rem }
.status-tabs { display:flex; flex-wrap:wrap; gap:.4rem; margin-bottom:1rem }
.status-tab {
    display:inline-flex; align-items:center; gap:.35rem; padding:.4rem .8rem; border-radius:999px;
    border:1.5px solid #e5e7eb; background:#fff; color:#475569; font-size:.78rem; font-weight:700;
    text-decoration:none;
}
.status-tab.active { background:#4361ee; border-color:#4361ee; color:#fff }
.status-tab .cnt {
    font-size:.68rem; font-weight:800; padding:.1rem .4rem; border-radius:999px;
    background:rgba(15,23,42,.08);
}
.status-tab.active .cnt { background:rgba(255,255,255,.22) }
.toolbar {
    display:flex; gap:.65rem; flex-wrap:wrap; align-items:center; justify-content:space-between;
    margin-bottom:1rem;
}
.toolbar form { display:flex; gap:.5rem; flex:1; min-width:220px }
.toolbar input[type=search] {
    flex:1; height:40px; border:1.5px solid #e5e7eb; border-radius:10px; padding:0 .85rem;
    font-family:inherit; font-size:.85rem;
}
.table-card {
    background:#fff; border:1.5px solid #e6eaf3; border-radius:16px; overflow:hidden;
    box-shadow:0 1px 4px rgba(0,0,0,.04);
}
.table-wrap { overflow-x:auto }
table.enq-table { width:100%; border-collapse:collapse; font-size:.84rem }
.enq-table th {
    text-align:left; padding:.75rem 1rem; font-size:.72rem; font-weight:800; letter-spacing:.04em;
    text-transform:uppercase; color:#64748b; background:#f8fafc; border-bottom:1px solid #eef2f7;
}
.enq-table td { padding:.85rem 1rem; border-bottom:1px solid #f1f5f9; vertical-align:middle; color:#1e293b }
.enq-table tr:last-child td { border-bottom:none }
.badge {
    display:inline-flex; align-items:center; padding:.18rem .55rem; border-radius:999px;
    font-size:.7rem; font-weight:800;
}
.badge-New { background:#eff6ff; color:#1d4ed8 }
.badge-Contacted { background:#fffbeb; color:#b45309 }
.badge-Converted { background:#ecfdf5; color:#047857 }
.badge-Closed { background:#f1f5f9; color:#475569 }
.row-actions { display:flex; gap:.35rem; flex-wrap:wrap }
.row-btn {
    display:inline-flex; align-items:center; gap:.25rem; height:32px; padding:0 .65rem;
    border-radius:8px; border:1.5px solid #e5e7eb; background:#fff; color:#334155;
    font-size:.72rem; font-weight:700; text-decoration:none; cursor:pointer; font-family:inherit;
}
.row-btn.convert { background:#ecfdf5; border-color:#6ee7b7; color:#047857 }
.row-btn.danger { color:#b91c1c; border-color:#fecaca; background:#fef2f2 }
.muted { color:#94a3b8; font-size:.78rem }
.form-card {
    background:#fff; border:1.5px solid #e5e7eb; border-radius:16px; overflow:hidden;
    box-shadow:0 4px 20px rgba(15,23,42,.04);
}
.form-card-head {
    padding:1.1rem 1.5rem; border-bottom:1px solid #eef2f7;
    background:linear-gradient(135deg,#fff 0%,#f8fafc 100%);
}
.form-card-head h3 { margin:0; font-size:1.05rem; font-weight:800 }
.form-card-head p { margin:.25rem 0 0; font-size:.8rem; color:#64748b }
.form-body { padding:1.35rem 1.5rem }
.atc-form-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.85rem 1.1rem }
.atc-form-grid .full { grid-column:1 / -1 }
.atc-form-field { display:flex; flex-direction:column; gap:.28rem }
.atc-form-field label { font-size:.78rem; font-weight:700; color:#374151 }
.atc-form-field label .req { color:#ef4444 }
.atc-form-field input, .atc-form-field select, .atc-form-field textarea {
    height:42px; padding:0 .85rem; border:1.5px solid #e5e7eb; border-radius:10px;
    font-family:inherit; font-size:.875rem; width:100%; box-sizing:border-box;
}
.atc-form-field textarea { height:auto; padding:.7rem .85rem }
.section-title {
    grid-column:1 / -1; font-size:.75rem; font-weight:800; text-transform:uppercase;
    letter-spacing:.06em; color:#64748b; margin:.35rem 0 .15rem;
}
.form-actions {
    display:flex; gap:.75rem; justify-content:flex-end; padding:1rem 1.5rem;
    border-top:1px solid #eef2f7; background:#fafbfc;
}
.btn-cancel {
    height:42px; padding:0 1.15rem; border-radius:10px; border:1.5px solid #e5e7eb;
    background:#fff; font-weight:700; font-size:.85rem; text-decoration:none; color:#475569;
    display:inline-flex; align-items:center; font-family:inherit;
}
.btn-save {
    height:42px; padding:0 1.35rem; border-radius:10px; border:none;
    background:linear-gradient(135deg,#4361ee,#3b82f6); color:#fff; font-weight:800;
    font-size:.85rem; cursor:pointer; font-family:inherit;
}
.alert { padding:.85rem 1.1rem; border-radius:12px; margin-bottom:1rem; font-size:.875rem; font-weight:600 }
.alert.error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca }
.alert.success { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0 }
.back-link {
    display:inline-flex; align-items:center; gap:.35rem; font-size:.82rem; font-weight:700;
    color:#4361ee; text-decoration:none; margin-bottom:.85rem;
}
@media (max-width:1100px){ .atc-form-grid { grid-template-columns:repeat(2,minmax(0,1fr)) } }
@media (max-width:720px){ .atc-form-grid { grid-template-columns:1fr } }
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
    <header class="top-header">
        <div class="header-left">
            <button class="hamburger" id="hamburgerBtn" type="button">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <div class="header-greeting">
                <h2><?= htmlspecialchars($pageTitle) ?></h2>
                <p><?= $isForm ? 'Capture ATC onboarding lead details' : 'Track ATC franchise leads and convert to ATC centers' ?></p>
            </div>
        </div>
        <div class="header-right">
            <?php if (file_exists(__DIR__.'/../includes/notification_bell.php')) include __DIR__.'/../includes/notification_bell.php'; ?>
            <?php include __DIR__.'/../includes/profile_dropdown.php'; ?>
        </div>
    </header>

    <div class="page-content">
        <?php if (!empty($_GET['converted'])): ?>
        <div class="alert success">Enquiry converted — ATC center created successfully.</div>
        <?php endif; ?>
        <?php if (!empty($_GET['saved'])): ?>
        <div class="alert success">Enquiry saved successfully.</div>
        <?php endif; ?>
        <?php if (!empty($_GET['updated'])): ?>
        <div class="alert success">Enquiry updated successfully.</div>
        <?php endif; ?>
        <?php if (!empty($_GET['err'])): ?>
        <div class="alert error"><?= htmlspecialchars((string)$_GET['err']) ?></div>
        <?php endif; ?>
        <?php if ($formError !== ''): ?>
        <div class="alert error"><?= htmlspecialchars($formError) ?></div>
        <?php endif; ?>

        <?php if ($isForm): ?>
            <a class="back-link" href="atc_enquiries.php">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                Back to ATC Enquiries
            </a>
            <div class="form-card">
                <div class="form-card-head">
                    <h3><?= htmlspecialchars($pageTitle) ?></h3>
                    <p>Fill lead details now; convert later into a full ATC with login via Convert.</p>
                </div>
                <form method="post" action="atc_enquiries.php">
                    <input type="hidden" name="action" value="<?= $pageMode === 'edit' ? 'edit_page' : 'add_page' ?>">
                    <?php if ($pageMode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)$editId ?>">
                    <?php endif; ?>
                    <div class="form-body">
                        <div class="atc-form-grid">
                            <div class="section-title">Lead / Center</div>
                            <div class="atc-form-field full">
                                <label>Proposed Center Name <span class="req">*</span></label>
                                <input type="text" name="center_name" required maxlength="150" value="<?= htmlspecialchars($fv('center_name')) ?>" placeholder="e.g. Nashik GIIT Center">
                            </div>
                            <div class="atc-form-field">
                                <label>Interested Center Type <span class="req">*</span></label>
                                <select name="interested_center_type" required>
                                    <option value="">-- Select --</option>
                                    <?php foreach ($centerTypes as $ct): ?>
                                    <option value="<?= htmlspecialchars($ct) ?>" <?= $fv('interested_center_type') === $ct ? 'selected' : '' ?>><?= htmlspecialchars($ct) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="atc-form-field">
                                <label>Preferred DLC</label>
                                <select name="preferred_dlc_id">
                                    <option value="">-- Optional --</option>
                                    <?php foreach ($dlcOffices as $dlc): ?>
                                    <option value="<?= (int)$dlc['id'] ?>" <?= (string)$fv('preferred_dlc_id') === (string)$dlc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($dlc['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="atc-form-field">
                                <label>Enquiry Source</label>
                                <select name="enquiry_source">
                                    <?php foreach ($enquirySources as $src): ?>
                                    <option value="<?= htmlspecialchars($src) ?>" <?= $fv('enquiry_source', 'Phone') === $src ? 'selected' : '' ?>><?= htmlspecialchars($src) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="atc-form-field">
                                <label>Enquiry Date</label>
                                <input type="date" name="enquiry_date" value="<?= htmlspecialchars($fv('enquiry_date', date('Y-m-d'))) ?>">
                            </div>
                            <div class="atc-form-field">
                                <label>Status</label>
                                <select name="status" <?= $fv('status') === 'Converted' ? 'disabled' : '' ?>>
                                    <?php foreach (['New', 'Contacted', 'Closed'] as $st): ?>
                                    <option value="<?= $st ?>" <?= $fv('status', 'New') === $st ? 'selected' : '' ?>><?= $st ?></option>
                                    <?php endforeach; ?>
                                    <?php if ($fv('status') === 'Converted'): ?>
                                    <option value="Converted" selected>Converted</option>
                                    <?php endif; ?>
                                </select>
                                <?php if ($fv('status') === 'Converted'): ?>
                                <input type="hidden" name="status" value="Converted">
                                <?php endif; ?>
                            </div>

                            <div class="section-title">Contact</div>
                            <div class="atc-form-field">
                                <label>Contact Person <span class="req">*</span></label>
                                <input type="text" name="contact_person" required maxlength="100" value="<?= htmlspecialchars($fv('contact_person')) ?>">
                            </div>
                            <div class="atc-form-field">
                                <label>Mobile <span class="req">*</span></label>
                                <input type="tel" name="mobile" required maxlength="10" pattern="[0-9]{10}" value="<?= htmlspecialchars($fv('mobile')) ?>" placeholder="10-digit">
                            </div>
                            <div class="atc-form-field">
                                <label>Alternate Mobile</label>
                                <input type="tel" name="alternate_mobile" maxlength="15" value="<?= htmlspecialchars($fv('alternate_mobile')) ?>">
                            </div>
                            <div class="atc-form-field">
                                <label>Email</label>
                                <input type="email" name="email" maxlength="100" value="<?= htmlspecialchars($fv('email')) ?>">
                            </div>
                            <div class="atc-form-field">
                                <label>Referenced By</label>
                                <input type="text" name="referenced_by" maxlength="120" value="<?= htmlspecialchars($fv('referenced_by')) ?>">
                            </div>
                            <div class="atc-form-field">
                                <label>Next Follow-up Date</label>
                                <input type="date" name="next_followup_date" value="<?= htmlspecialchars($fv('next_followup_date')) ?>">
                            </div>
                            <div class="atc-form-field">
                                <label>Next Follow-up Time</label>
                                <input type="time" name="next_followup_time" value="<?= htmlspecialchars($fv('next_followup_time')) ?>">
                            </div>

                            <div class="section-title">Location</div>
                            <div class="atc-form-field full">
                                <label>Address</label>
                                <textarea name="address" rows="2" placeholder="Proposed center address"><?= htmlspecialchars($fv('address')) ?></textarea>
                            </div>
                            <div class="atc-form-field">
                                <label>District</label>
                                <input type="text" name="district" maxlength="100" value="<?= htmlspecialchars($fv('district')) ?>">
                            </div>
                            <div class="atc-form-field">
                                <label>Taluka</label>
                                <input type="text" name="taluka" maxlength="100" value="<?= htmlspecialchars($fv('taluka')) ?>">
                            </div>
                            <div class="atc-form-field">
                                <label>City</label>
                                <input type="text" name="city" maxlength="100" value="<?= htmlspecialchars($fv('city')) ?>">
                            </div>
                            <div class="atc-form-field">
                                <label>State</label>
                                <select name="state">
                                    <?php $curState = $fv('state', 'Maharashtra'); foreach ($states as $st): ?>
                                    <option value="<?= htmlspecialchars($st) ?>" <?= $curState === $st ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="atc-form-field">
                                <label>PIN Code</label>
                                <input type="text" name="pin_code" maxlength="10" pattern="[0-9]{6}" value="<?= htmlspecialchars($fv('pin_code')) ?>">
                            </div>
                            <div class="atc-form-field full">
                                <label>Notes / Comment</label>
                                <textarea name="comment" rows="3"><?= htmlspecialchars($fv('comment')) ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <a href="atc_enquiries.php" class="btn-cancel">Cancel</a>
                        <button type="submit" class="btn-save"><?= $pageMode === 'edit' ? 'Update Enquiry' : 'Save Enquiry' ?></button>
                    </div>
                </form>
            </div>

        <?php else: ?>
            <div class="page-header-block">
                <div class="page-header-left">
                    <div class="page-header-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72"/><path d="M14 2h6v6"/><path d="M10 14L20 4"/></svg>
                    </div>
                    <div>
                        <div class="page-header-title">ATC Enquiries</div>
                        <div class="page-header-subtitle">Onboarding leads for new ATC centers — convert when ready</div>
                    </div>
                </div>
                <div class="header-actions">
                    <a class="btn-secondary-action" href="atc_centers.php">ATC Logins</a>
                    <a class="btn-secondary-action" href="atc_form.php">Add ATC Center</a>
                    <a class="btn-primary-action" href="atc_enquiries.php?mode=add">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Add Enquiry
                    </a>
                </div>
            </div>

            <div class="enq-stats">
                <div class="enq-stat"><div class="n"><?= (int)$statusCounts['all'] ?></div><div class="l">Open Enquiries</div></div>
                <div class="enq-stat"><div class="n"><?= (int)$statusCounts['New'] ?></div><div class="l">New</div></div>
                <div class="enq-stat"><div class="n"><?= (int)$statusCounts['Contacted'] ?></div><div class="l">Contacted</div></div>
                <div class="enq-stat"><div class="n"><?= (int)$statusCounts['Converted'] ?></div><div class="l">Converted</div></div>
            </div>

            <div class="status-tabs">
                <?php
                $tabs = ['all' => 'Open', 'New' => 'New', 'Contacted' => 'Contacted', 'Converted' => 'Converted', 'Closed' => 'Closed'];
                foreach ($tabs as $key => $label):
                    $qs = 'status=' . urlencode($key) . ($searchTerm !== '' ? '&search=' . urlencode($searchTerm) : '');
                ?>
                <a class="status-tab <?= $statusFilter === $key ? 'active' : '' ?>" href="?<?= $qs ?>">
                    <span><?= $label ?></span>
                    <span class="cnt"><?= (int)($statusCounts[$key] ?? 0) ?></span>
                </a>
                <?php endforeach; ?>
            </div>

            <div class="toolbar">
                <form method="get" action="atc_enquiries.php">
                    <?php if ($statusFilter !== 'all'): ?>
                    <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                    <?php endif; ?>
                    <input type="search" name="search" value="<?= htmlspecialchars($searchTerm) ?>" placeholder="Search name, mobile, district…">
                    <button type="submit" class="btn-secondary-action" style="height:40px">Search</button>
                </form>
            </div>

            <div class="table-card">
                <div class="table-wrap">
                    <table class="enq-table">
                        <thead>
                            <tr>
                                <th>Center / Contact</th>
                                <th>Type</th>
                                <th>Location</th>
                                <th>Follow-up</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$enquiries): ?>
                            <tr><td colspan="6" class="muted" style="text-align:center;padding:2rem">No enquiries found.</td></tr>
                        <?php else: foreach ($enquiries as $e): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:800"><?= htmlspecialchars($e['center_name']) ?></div>
                                    <div class="muted"><?= htmlspecialchars($e['contact_person'] ?: '—') ?> · <?= htmlspecialchars($e['mobile'] ?: '—') ?></div>
                                    <?php if (!empty($e['enquiry_source'])): ?>
                                    <div class="muted"><?= htmlspecialchars($e['enquiry_source']) ?><?= !empty($e['enquiry_date']) ? ' · ' . htmlspecialchars($e['enquiry_date']) : '' ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($e['interested_center_type'] ?: '—') ?>
                                    <?php if (!empty($e['dlc_name'])): ?>
                                    <div class="muted"><?= htmlspecialchars($e['dlc_name']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars(trim(implode(', ', array_filter([$e['city'] ?? '', $e['district'] ?? '', $e['state'] ?? '']))) ?: '—') ?>
                                </td>
                                <td>
                                    <?php if (!empty($e['next_followup_date'])): ?>
                                        <?= htmlspecialchars($e['next_followup_date']) ?>
                                        <?php if (!empty($e['next_followup_time'])): ?>
                                        <div class="muted"><?= htmlspecialchars(substr((string)$e['next_followup_time'], 0, 5)) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= htmlspecialchars($e['status']) ?>"><?= htmlspecialchars($e['status']) ?></span>
                                    <?php if ($e['status'] === 'Converted' && !empty($e['converted_atc_code'])): ?>
                                    <div class="muted"><?= htmlspecialchars($e['converted_atc_code']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="row-actions">
                                        <a class="row-btn" href="atc_enquiries.php?mode=edit&id=<?= (int)$e['id'] ?>">Edit</a>
                                        <?php if ($e['status'] !== 'Converted'): ?>
                                        <a class="row-btn convert" href="atc_form.php?enquiry_id=<?= (int)$e['id'] ?>" title="Convert to ATC Center">Convert → ATC</a>
                                        <button type="button" class="row-btn danger" onclick="deleteEnquiry(<?= (int)$e['id'] ?>)">Delete</button>
                                        <?php elseif (!empty($e['converted_atc_id'])): ?>
                                        <a class="row-btn" href="atc_view.php?id=<?= (int)$e['converted_atc_id'] ?>">View ATC</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (($pager['total_pages'] ?? 1) > 1): ?>
                <div style="padding:.85rem 1rem;border-top:1px solid #eef2f7">
                    <?= renderPagination($pager, 'enquiries') ?>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
</div>
<script src="../assets/js/dashboard.js"></script>
<script>
async function deleteEnquiry(id) {
    if (!confirm('Delete this ATC enquiry?')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    try {
        const res = await fetch('atc_enquiries.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) location.reload();
        else alert(data.message || 'Could not delete');
    } catch (e) {
        alert('Server error');
    }
}
</script>
</body>
</html>
