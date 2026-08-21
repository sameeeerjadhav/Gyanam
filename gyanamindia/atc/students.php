<?php
/**
 * Gyanam Portal — ATC: Students Management
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$atcId = $_SESSION['atc_id'] ?? null;

// Handle AJAX requests
// ── Helper: check if a student's HO share has been paid ───────────────────
function isSharePaid($pdo, $studentId) {
    try {
        $sp = $pdo->prepare("SELECT student_ids FROM share_payments WHERE atc_id = (SELECT atc_id FROM admissions WHERE id = ?) AND status = 'Completed'");
        $sp->execute([$studentId]);
        foreach ($sp->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $ids = json_decode($json, true);
            if (is_array($ids) && in_array((string)$studentId, array_map('strval', $ids))) {
                return true;
            }
        }
    } catch (Exception $e) { /* table may not exist yet */ }
    return false;
}

// ── AJAX handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'get_student':
                $studentId = intval($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("SELECT * FROM admissions WHERE id = ? AND atc_id = ?");
                $stmt->execute([$studentId, $atcId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($student) {
                    $student['share_paid'] = isSharePaid($pdo, $studentId);
                }
                echo json_encode(['success' => true, 'data' => $student]);
                exit;
                
            case 'update_status':
                $stmt = $pdo->prepare("UPDATE admissions SET status = ? WHERE id = ? AND atc_id = ?");
                $stmt->execute([$_POST['status'], $_POST['id'], $atcId]);
                echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
                exit;
                
            case 'update_student':
                $studentId = intval($_POST['id'] ?? 0);
                if (!$studentId) {
                    echo json_encode(['success' => false, 'message' => 'Invalid student.']);
                    exit;
                }

                $base = $pdo->prepare("SELECT * FROM admissions WHERE id = ? AND atc_id = ?");
                $base->execute([$studentId, $atcId]);
                $baseRow = $base->fetch(PDO::FETCH_ASSOC);
                if (!$baseRow) {
                    echo json_encode(['success' => false, 'message' => 'Student not found.']);
                    exit;
                }

                $fieldLabels = [
                    'first_name' => 'First Name',
                    'middle_name' => 'Middle Name',
                    'last_name' => 'Last Name',
                    'gender' => 'Gender',
                    'dob' => 'Date of Birth',
                    'qualification' => 'Qualification',
                    'course' => 'Course',
                    'mobile' => 'Mobile Number',
                    'phone' => 'Phone Number',
                    'email' => 'Email Address',
                    'address' => 'Address',
                    'city' => 'City',
                    'pin_code' => 'PIN Code',
                    'referenced_by' => 'Referenced By',
                    'comment' => 'Comments/Notes',
                    'photo' => 'Student Photo'
                ];

                $newData = [];
                foreach (array_keys($fieldLabels) as $field) {
                    if ($field === 'photo') continue;
                    $newData[$field] = trim((string)($_POST[$field] ?? ''));
                }

                $newPhotoPath = null;
                if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/../uploads/students/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $fileExt = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                    if (!in_array($fileExt, ['jpg','jpeg','png','gif'], true) || $_FILES['photo']['size'] > 2097152) {
                        echo json_encode(['success' => false, 'message' => 'Invalid photo. Use JPG, PNG or GIF up to 2MB.']);
                        exit;
                    }
                    $fileName   = $baseRow['roll_no'] . '.' . $fileExt;
                    $targetPath = $uploadDir . $fileName;
                    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $targetPath)) {
                        echo json_encode(['success' => false, 'message' => 'Failed to upload photo. Please try again.']);
                        exit;
                    }
                    $newPhotoPath = 'uploads/students/' . $fileName;
                }

                $pendingSelect = $pdo->prepare("SELECT id FROM change_requests WHERE admission_id = ? AND atc_id = ? AND field_name = ? AND status = 'Pending' LIMIT 1");
                $pendingUpdate = $pdo->prepare("UPDATE change_requests SET old_value = ?, new_value = ?, reason = ?, requested_at = NOW() WHERE id = ?");
                $pendingInsert = $pdo->prepare("INSERT INTO change_requests (admission_id, atc_id, field_name, field_label, old_value, new_value, reason) VALUES (?, ?, ?, ?, ?, ?, ?)");

                $changesCount = 0;
                $reasonText = 'Bulk update requested from Edit Student form.';

                $pdo->beginTransaction();
                try {
                    foreach ($fieldLabels as $field => $label) {
                        $oldValue = trim((string)($baseRow[$field] ?? ''));
                        $newValue = $field === 'photo' ? ($newPhotoPath ?? $oldValue) : ($newData[$field] ?? $oldValue);

                        if ($newValue === $oldValue) continue;

                        $pendingSelect->execute([$studentId, $atcId, $field]);
                        $pendingId = $pendingSelect->fetchColumn();

                        if ($pendingId) {
                            $pendingUpdate->execute([$oldValue, $newValue, $reasonText, $pendingId]);
                        } else {
                            $pendingInsert->execute([$studentId, $atcId, $field, $label, $oldValue, $newValue, $reasonText]);
                        }
                        $changesCount++;
                    }

                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }

                if ($changesCount === 0) {
                    echo json_encode(['success' => false, 'message' => 'No changes found to request.']);
                } else {
                    echo json_encode(['success' => true, 'message' => "Request update sent to Admin for {$changesCount} field(s)."]);
                }
                exit;

            case 'submit_change_request':
                // Only allowed when share is paid
                $studentId  = intval($_POST['student_id'] ?? 0);
                $fieldName  = trim($_POST['field_name'] ?? '');
                $fieldLabel = trim($_POST['field_label'] ?? '');
                $oldValue   = trim($_POST['old_value'] ?? '');
                $newValue   = trim($_POST['new_value'] ?? '');
                $reason     = trim($_POST['reason'] ?? '');

                if (!$studentId || !$fieldName || !$newValue || !$reason) {
                    echo json_encode(['success' => false, 'message' => 'Please fill all required fields.']);
                    exit;
                }
                $chk = $pdo->prepare("SELECT COUNT(*) FROM change_requests WHERE admission_id = ? AND field_name = ? AND status = 'Pending'");
                $chk->execute([$studentId, $fieldName]);
                if ($chk->fetchColumn() > 0) {
                    echo json_encode(['success' => false, 'message' => 'A pending request for this field already exists. Please wait for Admin review.']);
                    exit;
                }
                $ins = $pdo->prepare("
                    INSERT INTO change_requests (admission_id, atc_id, field_name, field_label, old_value, new_value, reason)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([$studentId, $atcId, $fieldName, $fieldLabel, $oldValue, $newValue, $reason]);
                echo json_encode(['success' => true, 'message' => 'Change request submitted. Admin will review it shortly.']);
                exit;

            case 'get_approved_requests':
                $studentId = intval($_POST['student_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT id, field_name, new_value FROM change_requests WHERE admission_id = ? AND atc_id = ? AND status = 'Approved' ORDER BY reviewed_at DESC LIMIT 10");
                $stmt->execute([$studentId, $atcId]);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                exit;

            case 'upload_photo_request':
                // Upload photo and create a change request (used when share is paid)
                $studentId = intval($_POST['student_id'] ?? 0);
                $reason    = trim($_POST['reason'] ?? 'Photo update requested from Edit Student form.');

                if (!$studentId) {
                    echo json_encode(['success' => false, 'message' => 'Invalid student ID.']);
                    exit;
                }
                $base2 = $pdo->prepare("SELECT * FROM admissions WHERE id = ? AND atc_id = ?");
                $base2->execute([$studentId, $atcId]);
                $baseRow2 = $base2->fetch(PDO::FETCH_ASSOC);
                if (!$baseRow2) {
                    echo json_encode(['success' => false, 'message' => 'Student not found.']);
                    exit;
                }
                if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['success' => false, 'message' => 'No photo file received.']);
                    exit;
                }
                $allowed2 = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                $mime2     = mime_content_type($_FILES['photo']['tmp_name']);
                if (!isset($allowed2[$mime2])) {
                    echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, GIF, WebP allowed.']);
                    exit;
                }
                if ($_FILES['photo']['size'] > 3 * 1024 * 1024) {
                    echo json_encode(['success' => false, 'message' => 'Photo must be under 3 MB.']);
                    exit;
                }
                $uploadDir2 = __DIR__ . '/../uploads/students/';
                if (!is_dir($uploadDir2)) mkdir($uploadDir2, 0755, true);
                $ext2      = $allowed2[$mime2];
                $filename2 = 'student_' . $studentId . '_req_' . time() . '.' . $ext2;
                $destPath2 = $uploadDir2 . $filename2;
                if (!move_uploaded_file($_FILES['photo']['tmp_name'], $destPath2)) {
                    echo json_encode(['success' => false, 'message' => 'Failed to save the uploaded file.']);
                    exit;
                }
                $newPhotoPath2 = 'uploads/students/' . $filename2;
                $oldPhotoPath2 = $baseRow2['photo'] ?? '';

                // Check for existing pending photo request
                $chk2 = $pdo->prepare("SELECT COUNT(*) FROM change_requests WHERE admission_id = ? AND field_name = 'photo' AND status = 'Pending'");
                $chk2->execute([$studentId]);
                if ($chk2->fetchColumn() > 0) {
                    echo json_encode(['success' => false, 'message' => 'A pending photo change request already exists. Please wait for Admin review.']);
                    exit;
                }
                $ins2 = $pdo->prepare("INSERT INTO change_requests (admission_id, atc_id, field_name, field_label, old_value, new_value, reason) VALUES (?, ?, 'photo', 'Student Photo', ?, ?, ?)");
                $ins2->execute([$studentId, $atcId, $oldPhotoPath2, $newPhotoPath2, $reason]);
                echo json_encode(['success' => true, 'message' => 'Photo change request submitted. Admin will review and apply it.']);
                exit;

            case 'direct_update':
                // Directly update admissions table — only allowed when share is NOT paid
                $studentId = intval($_POST['id'] ?? 0);
                if (!$studentId) {
                    echo json_encode(['success' => false, 'message' => 'Invalid student.']);
                    exit;
                }
                // Verify share is NOT paid
                if (isSharePaid($pdo, $studentId)) {
                    echo json_encode(['success' => false, 'message' => 'HO Share is paid. Direct edits are not allowed. Use change requests instead.']);
                    exit;
                }
                $verify = $pdo->prepare("SELECT id FROM admissions WHERE id = ? AND atc_id = ?");
                $verify->execute([$studentId, $atcId]);
                if (!$verify->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Student not found.']);
                    exit;
                }

                $allowedFields = [
                    'first_name','middle_name','last_name','gender','dob','qualification',
                    'father_name','mother_name','category','mobile','phone','email',
                    'address','city','state','pin_code','referenced_by','course',
                    'uniform_size','material_type','material_language','comment'
                ];
                $sets = [];
                $vals = [];
                foreach ($allowedFields as $f) {
                    if (isset($_POST[$f])) {
                        $sets[] = "$f = ?";
                        $vals[] = trim($_POST[$f]);
                    }
                }

                // Handle photo upload
                if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/../uploads/students/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $fExt = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                    if (in_array($fExt, ['jpg','jpeg','png','gif','webp']) && $_FILES['photo']['size'] <= 3145728) {
                        $fName = 'student_' . $studentId . '_' . time() . '.' . $fExt;
                        if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $fName)) {
                            $sets[] = "photo = ?";
                            $vals[] = 'uploads/students/' . $fName;
                        }
                    }
                }

                if (empty($sets)) {
                    echo json_encode(['success' => false, 'message' => 'No changes to save.']);
                    exit;
                }
                $vals[] = $studentId;
                $vals[] = $atcId;
                $sql = "UPDATE admissions SET " . implode(', ', $sets) . " WHERE id = ? AND atc_id = ?";
                $pdo->prepare($sql)->execute($vals);
                echo json_encode(['success' => true, 'message' => 'Student details updated successfully.']);
                exit;

            case 'update_phones':
                // Directly update mobile/phone/address — allowed even when share IS paid
                $studentId = intval($_POST['id'] ?? 0);
                if (!$studentId) {
                    echo json_encode(['success' => false, 'message' => 'Invalid student.']);
                    exit;
                }
                $verify2 = $pdo->prepare("SELECT id FROM admissions WHERE id = ? AND atc_id = ?");
                $verify2->execute([$studentId, $atcId]);
                if (!$verify2->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Student not found.']);
                    exit;
                }
                $mobile   = trim($_POST['mobile'] ?? '');
                $phone    = trim($_POST['phone'] ?? '');
                $address  = trim($_POST['address'] ?? '');
                $city     = trim($_POST['city'] ?? '');
                $state    = trim($_POST['state'] ?? '');
                $pin_code = trim($_POST['pin_code'] ?? '');
                if (!$mobile) {
                    echo json_encode(['success' => false, 'message' => 'Mobile number is required.']);
                    exit;
                }
                $pdo->prepare("UPDATE admissions SET mobile = ?, phone = ?, address = ?, city = ?, state = ?, pin_code = ? WHERE id = ? AND atc_id = ?")
                    ->execute([$mobile, $phone, $address, $city, $state, $pin_code, $studentId, $atcId]);
                echo json_encode(['success' => true, 'message' => 'Mobile & address updated successfully.']);
                exit;

            case 'delete':
                $stmt = $pdo->prepare("SELECT roll_no FROM admissions WHERE id = ? AND atc_id = ?");
                $stmt->execute([$_POST['id'], $atcId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($student) {
                    $stmt = $pdo->prepare("DELETE FROM admissions WHERE id = ? AND atc_id = ?");
                    $stmt->execute([$_POST['id'], $atcId]);
                    $stmt = $pdo->prepare("UPDATE atc_centers SET student_count = student_count - 1 WHERE id = ?");
                    $stmt->execute([$atcId]);
                    echo json_encode(['success' => true, 'message' => 'Student deleted successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Student not found']);
                }
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}


// Fetch students

$searchTerm = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? 'all';
$courseFilter = $_GET['course'] ?? 'all';
$feesFilter = $_GET['fees'] ?? 'all';

$sql = "
    SELECT a.*,
           (
               SELECT cr.status
               FROM change_requests cr
               WHERE cr.admission_id = a.id
               ORDER BY cr.requested_at DESC
               LIMIT 1
           ) AS change_request_status
    FROM admissions a
    WHERE a.atc_id = ?
";
$params = [$atcId];

if ($searchTerm) {
    $sql .= " AND (a.first_name LIKE ? OR a.middle_name LIKE ? OR a.last_name LIKE ? OR a.roll_no LIKE ? OR a.mobile LIKE ?)";
    $searchParam = "%$searchTerm%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
}

if ($statusFilter !== 'all') {
    $sql .= " AND a.status = ?";
    $params[] = $statusFilter;
}

if ($courseFilter !== 'all') {
    $sql .= " AND a.course = ?";
    $params[] = $courseFilter;
}

if ($feesFilter === 'paid') {
    $sql .= " AND (COALESCE(a.net_payable, a.fees_total) - a.fees_paid) <= 0";
} elseif ($feesFilter === 'partial') {
    $sql .= " AND a.fees_paid > 0 AND (COALESCE(a.net_payable, a.fees_total) - a.fees_paid) > 0";
} elseif ($feesFilter === 'pending') {
    $sql .= " AND a.fees_paid = 0 AND COALESCE(a.net_payable, a.fees_total) > 0";
}

$sql .= " ORDER BY a.admission_date DESC, a.roll_no DESC";

// Count for pagination (same filters)
$countSql = "SELECT COUNT(*) FROM admissions a WHERE a.atc_id = ?";
$countParams = [$atcId];
if ($searchTerm) {
    $countSql .= " AND (a.first_name LIKE ? OR a.middle_name LIKE ? OR a.last_name LIKE ? OR a.roll_no LIKE ? OR a.mobile LIKE ?)";
    $searchParam = "%$searchTerm%";
    $countParams = array_merge($countParams, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
}
if ($statusFilter !== 'all') {
    $countSql .= " AND a.status = ?";
    $countParams[] = $statusFilter;
}
if ($courseFilter !== 'all') {
    $countSql .= " AND a.course = ?";
    $countParams[] = $courseFilter;
}
if ($feesFilter === 'paid') {
    $countSql .= " AND (COALESCE(a.net_payable, a.fees_total) - a.fees_paid) <= 0";
} elseif ($feesFilter === 'partial') {
    $countSql .= " AND a.fees_paid > 0 AND (COALESCE(a.net_payable, a.fees_total) - a.fees_paid) > 0";
} elseif ($feesFilter === 'pending') {
    $countSql .= " AND a.fees_paid = 0 AND COALESCE(a.net_payable, a.fees_total) > 0";
}
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($countParams);
$pager = paginationMeta((int)$countStmt->fetchColumn(), paginationParams(25));

$sql .= " LIMIT {$pager['per_page']} OFFSET {$pager['offset']}";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts
$stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE atc_id = ?");
$stmt->execute([$atcId]);
$totalCount = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE atc_id = ? AND status = 'Active'");
$stmt->execute([$atcId]);
$activeCount = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE atc_id = ? AND status = 'Inactive'");
$stmt->execute([$atcId]);
$inactiveCount = $stmt->fetchColumn();

// Get fees counts
$stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE atc_id = ? AND (COALESCE(net_payable, fees_total) - fees_paid) <= 0");
$stmt->execute([$atcId]);
$feesPaidCount = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE atc_id = ? AND fees_paid > 0 AND (COALESCE(net_payable, fees_total) - fees_paid) > 0");
$stmt->execute([$atcId]);
$feesPartialCount = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE atc_id = ? AND fees_paid = 0 AND COALESCE(net_payable, fees_total) > 0");
$stmt->execute([$atcId]);
$feesPendingCount = $stmt->fetchColumn();

// Get unique courses
$stmt = $pdo->prepare("SELECT DISTINCT course FROM admissions WHERE atc_id = ? ORDER BY course");
$stmt->execute([$atcId]);
$courses = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Students — ATC Login | Gyanam India</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📚</text></svg>">
    <!-- Export libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <style>
    /* ── Export button group ── */
    .export-btn-group { display: flex; gap: .3rem; flex-wrap: wrap; align-items: center; }
    .exp-btn {
        display: inline-flex; align-items: center; gap: .2rem;
        padding: .3rem .55rem; border-radius: 6px; border: 1.5px solid;
        font-size: .68rem; font-weight: 700; cursor: pointer;
        white-space: nowrap; transition: all .18s; font-family: inherit;
    }
    .exp-copy  { background:#f1f5f9; border-color:#cbd5e1; color:#475569; }
    .exp-csv   { background:#ecfdf5; border-color:#6ee7b7; color:#065f46; }
    .exp-excel { background:#eff6ff; border-color:#93c5fd; color:#1d4ed8; }
    .exp-pdf   { background:#fef2f2; border-color:#fca5a5; color:#b91c1c; }
    .exp-print { background:#f5f3ff; border-color:#c4b5fd; color:#6d28d9; }
    .exp-btn:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,.1); }

    /* ── Compact toolbar layout ── */
    .students-toolbar {
        display: flex;
        flex-direction: column;
        gap: .75rem;
        margin-bottom: 1.25rem;
    }
    .students-toolbar-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        flex-wrap: wrap;
    }
    .students-toolbar-top h3 {
        font-size: 1.05rem;
        font-weight: 800;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: .5rem;
        margin: 0;
    }
    .students-toolbar-bottom {
        display: flex;
        align-items: center;
        gap: .6rem;
        flex-wrap: wrap;
    }
    .students-toolbar-bottom select {
        padding: .45rem .7rem;
        border: 1.5px solid var(--border-color);
        border-radius: 8px;
        font-size: .82rem;
        font-family: inherit;
        font-weight: 600;
        background: #fff;
        color: var(--text-primary);
        cursor: pointer;
    }
    .students-toolbar-bottom .search-bar {
        max-width: 240px;
        padding: .4rem .7rem;
    }
    .students-toolbar-bottom .search-bar input {
        font-size: .8rem;
    }
    .students-toolbar-bottom .btn-primary {
        padding: .45rem 1rem;
        font-size: .8rem;
    }

    /* ── Compact table overrides for Students ── */
    #studentsTable th {
        padding: .5rem .5rem;
        font-size: .66rem;
    }
    #studentsTable td {
        padding: .45rem .5rem;
        font-size: .78rem;
    }
    #studentsTable .cell-name {
        font-size: .78rem;
    }
    #studentsTable .cell-sub {
        font-size: .68rem;
    }
    #studentsTable .cell-actions {
        display: grid;
        grid-template-columns: repeat(3, 30px);
        gap: 3px;
        width: fit-content;
    }
    #studentsTable .btn-icon {
        width: 30px;
        height: 30px;
        border-radius: 6px;
    }
    #studentsTable .btn-icon svg {
        width: 14px;
        height: 14px;
    }
    .table-card {
        overflow-x: auto;
    }

    /* ── Print styles ── */
    @media print {
        .sidebar, .top-header, .students-toolbar .export-btn-group, .status-tabs,
        .modal-overlay, #toastContainer { display: none !important; }
        .main-content { margin: 0 !important; }
        .data-table th, .data-table td { font-size: 11px !important; }
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
                    <h2>Students</h2>
                    <p>View and manage all students</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">
            
            <!-- Status Filter Tabs -->
            <div class="status-tabs">
                <a href="?status=all&course=<?= $courseFilter ?>&fees=<?= $feesFilter ?><?= $searchTerm ? '&search=' . urlencode($searchTerm) : '' ?>" class="status-tab <?= $statusFilter === 'all' ? 'active' : '' ?>">
                    <span class="tab-label">All Students</span>
                    <span class="tab-count"><?= $totalCount ?></span>
                </a>
                <a href="?status=Active&course=<?= $courseFilter ?>&fees=<?= $feesFilter ?><?= $searchTerm ? '&search=' . urlencode($searchTerm) : '' ?>" class="status-tab <?= $statusFilter === 'Active' ? 'active' : '' ?>">
                    <span class="tab-label">Active</span>
                    <span class="tab-count badge-active"><?= $activeCount ?></span>
                </a>
                <a href="?status=Inactive&course=<?= $courseFilter ?>&fees=<?= $feesFilter ?><?= $searchTerm ? '&search=' . urlencode($searchTerm) : '' ?>" class="status-tab <?= $statusFilter === 'Inactive' ? 'active' : '' ?>">
                    <span class="tab-label">Inactive</span>
                    <span class="tab-count badge-inactive"><?= $inactiveCount ?></span>
                </a>
            </div>
            
            <div class="students-toolbar">
                <div class="students-toolbar-top">
                    <h3>
                        Student List
                        <span class="badge-count"><?= count($students) ?></span>
                    </h3>
                    <div class="export-btn-group">
                        <button class="exp-btn exp-copy"  onclick="exportCopy()"  title="Copy">📋 Copy</button>
                        <button class="exp-btn exp-csv"   onclick="exportCSV()"   title="CSV">📄 CSV</button>
                        <button class="exp-btn exp-excel" onclick="exportExcel()" title="Excel">📊 Excel</button>
                        <button class="exp-btn exp-pdf"   onclick="exportPDF()"   title="PDF">📑 PDF</button>
                        <button class="exp-btn exp-print" onclick="printStudents()" title="Print">🖨️ Print</button>
                    </div>
                </div>
                <div class="students-toolbar-bottom">
                    <form method="GET" style="display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; width: 100%;">
                        <input type="hidden" name="status" value="<?= $statusFilter ?>">
                        <select name="course" onchange="this.form.submit()">
                            <option value="all">All Courses</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?= htmlspecialchars($course) ?>" <?= $courseFilter === $course ? 'selected' : '' ?>><?= htmlspecialchars($course) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="fees" onchange="this.form.submit()">
                            <option value="all" <?= $feesFilter === 'all' ? 'selected' : '' ?>>All Fees</option>
                            <option value="paid" <?= $feesFilter === 'paid' ? 'selected' : '' ?>>✅ Paid (<?= $feesPaidCount ?>)</option>
                            <option value="partial" <?= $feesFilter === 'partial' ? 'selected' : '' ?>>⚠️ Partial (<?= $feesPartialCount ?>)</option>
                            <option value="pending" <?= $feesFilter === 'pending' ? 'selected' : '' ?>>❌ Not Paid (<?= $feesPendingCount ?>)</option>
                        </select>
                        <div class="search-bar">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                            <input type="text" name="search" placeholder="Search by name, roll no, mobile..." value="<?= htmlspecialchars($searchTerm) ?>">
                        </div>
                        <button type="submit" class="btn-primary">Search</button>
                    </form>
                </div>
            </div>

            <div class="table-card">
                <table class="data-table" id="studentsTable">
                    <thead>
                        <tr>
                            <th>Photo</th>
                            <th>Roll No</th>
                            <th>Student Name</th>
                            <th>Course</th>
                            <th>Contact</th>
                            <th>Admission Date</th>
                            <th>Fees Status</th>
                            <th>Notify</th>
                            <th>Request Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr>
                                <td colspan="10" class="table-empty">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
                                    <p>No students found.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($students as $student): ?>
                                <?php
                                    $fullName = $student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name'];
                                    $netPayable = floatval($student['net_payable'] ?: $student['fees_total']);
                                    $feesPaid = floatval($student['fees_paid']);
                                    $netBalance = $netPayable - $feesPaid;
                                    
                                    if ($netBalance <= 0) {
                                        $feesStatusClass = 'paid';
                                        $feesStatusText = 'Paid';
                                    } elseif ($feesPaid > 0) {
                                        $feesStatusClass = 'partial';
                                        $feesStatusText = 'Partial';
                                    } else {
                                        $feesStatusClass = 'pending';
                                        $feesStatusText = 'Pending';
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($student['photo']): ?>
                                            <img src="../<?= htmlspecialchars($student['photo']) ?>" alt="<?= htmlspecialchars($fullName) ?>" style="width: 36px; height: 48px; border-radius: 4px; object-fit: cover; border: 1.5px solid var(--border-color); display: block;">
                                        <?php else: ?>
                                            <div style="width: 36px; height: 48px; border-radius: 4px; background: linear-gradient(135deg, #6366f1, #8b5cf6); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 1.1rem;">
                                                <?= strtoupper(substr($student['first_name'], 0, 1)) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="cell-name"><?= htmlspecialchars($student['registration_id'] ?? '-') ?></div>
                                        <div class="cell-sub">Roll No: <?= htmlspecialchars($student['roll_no']) ?></div>
                                    </td>
                                    <td>
                                        <div class="cell-name"><?= htmlspecialchars($fullName) ?></div>
                                        <div class="cell-sub"><?= htmlspecialchars($student['gender']) ?> • DOB: <?= date('d M Y', strtotime($student['dob'])) ?></div>
                                    </td>
                                    <td>
                                        <div class="cell-name"><?= htmlspecialchars($student['course']) ?></div>
                                        <div class="cell-sub"><?= htmlspecialchars($student['qualification']) ?></div>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($student['mobile']) ?></div>
                                        <?php if ($student['email']): ?>
                                            <div class="cell-sub"><?= htmlspecialchars($student['email']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('d M Y', strtotime($student['admission_date'])) ?></td>
                                    <td>
                                        <span class="fees-badge <?= $feesStatusClass ?>">
                                            <?= $feesStatusText ?>
                                        </span>
                                        <div class="cell-sub">₹ <?= number_format($feesPaid, 2) ?> / ₹ <?= number_format($netPayable, 2) ?></div>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php if ($netBalance > 0 && $student['mobile']): ?>
                                            <button class="wa-notify-btn" onclick="sendFeeReminder('<?= addslashes(htmlspecialchars($fullName)) ?>', '<?= $student['mobile'] ?>', '<?= number_format($netBalance, 2) ?>', '<?= addslashes(htmlspecialchars($student['course'])) ?>')" title="Send WhatsApp Fee Reminder">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512" fill="white" style="width:14px;height:14px;"><path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>
                                            </button>
                                        <?php elseif ($netBalance <= 0): ?>
                                            <span style="color:#10b981;font-size:1.1rem" title="Fully Paid">✅</span>
                                        <?php else: ?>
                                            <span style="color:#9ca3af">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($student['change_request_status'])): ?>
                                            <span class="req-state-badge req-<?= strtolower($student['change_request_status']) ?>">
                                                <?= htmlspecialchars($student['change_request_status']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:#9ca3af">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="cell-actions">
                                            <a class="btn-icon" href="view_admission_page.php?admission_id=<?= $student['id'] ?>" title="View Details" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            </a>
                                            <a class="btn-icon" href="admission_form_pdf.php?admission_id=<?= $student['id'] ?>" target="_blank" title="Download Admission Form" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;color:#059669;">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><polyline points="9 15 12 18 15 15"/></svg>
                                            </a>
                                            <a class="btn-icon" href="edit_student.php?id=<?= $student['id'] ?>" title="Edit Student" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            </a>
                                            <button class="btn-icon" onclick="toggleStatus(<?= $student['id'] ?>, '<?= $student['status'] ?>')" title="Toggle Status">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M2 12h20"/></svg>
                                            </button>
                                            <button class="btn-icon danger" onclick="deleteStudent(<?= $student['id'] ?>, '<?= htmlspecialchars($fullName, ENT_QUOTES) ?>')" title="Delete">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= renderPagination($pager, 'students') ?>
        </div>
    </main>
</div>

<!-- Student Details Modal -->
<div class="modal-overlay" id="studentModal">
    <div class="modal-card" style="max-width: 900px;">
        <div class="modal-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Student Details
            </h3>
            <button type="button" class="modal-close" onclick="closeModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        
        <div class="modal-body" id="studentContent">
            <!-- Content will be populated by JavaScript -->
        </div>
        
        <div class="modal-footer">
            <button type="button" class="btn-secondary" onclick="closeModal()">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Edit Student Modal -->
<div class="modal-overlay" id="editStudentModal">
    <div class="edit-student-card">
        <!-- Header -->
        <div class="edit-student-header">
            <div class="edit-student-header-bg"></div>
            <div class="edit-student-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </div>
            <div class="edit-student-title-wrap">
                <div class="edit-student-title">Edit Student</div>
                <div class="edit-student-subtitle">Update student information</div>
            </div>
            <button type="button" onclick="closeEditModal()" class="edit-student-close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <form id="editStudentForm" class="edit-student-form" enctype="multipart/form-data">
            <input type="hidden" id="edit_student_id" name="id">
            <input type="hidden" id="edit_current_photo" name="current_photo">

            <div class="edit-student-body">

                <div class="edit-section">
                    <div class="edit-section-header">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        <span>Student Photo</span>
                    </div>
                    <div class="edit-section-body">
                        <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
                            <div style="width:84px;height:84px;border-radius:50%;overflow:hidden;border:2px solid var(--border-color);background:#f8fafc;display:flex;align-items:center;justify-content:center">
                                <img id="edit_photo_img" src="" alt="Student Photo" style="width:100%;height:100%;object-fit:cover;display:none">
                                <div id="edit_photo_placeholder" style="color:#94a3b8;font-size:.75rem;font-weight:700">NO PHOTO</div>
                            </div>
                            <div style="flex:1;min-width:220px">
                                <input type="file" id="edit_photo" name="photo" accept="image/*" onchange="previewEditPhoto(this)" class="edit-input" style="padding:.5rem">
                                <div id="edit_photo_name" style="font-size:.78rem;color:#64748b;margin-top:.35rem">Upload JPG, PNG or GIF (max 2MB)</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Restriction Notice -->
                <div style="display:none;align-items:flex-start;gap:.75rem;background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:1rem 1.25rem;margin-bottom:1rem">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" width="20" height="20" style="flex-shrink:0;margin-top:.1rem"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <div>
                        <div style="font-weight:700;font-size:.85rem;color:#92400e">Edit Restrictions Active</div>
                        <div style="font-size:.8rem;color:#78350f;margin-top:.2rem">Direct edit is disabled for this student. Use the <strong>📝 Request Change</strong> button for any field and Head Office will approve it.</div>
                    </div>
                </div>

                <!-- Approved Changes Banner (informational) -->
                <div id="approvedChangesBanner" style="display:none;background:#d1fae5;border:1.5px solid #6ee7b7;border-radius:10px;padding:1rem 1.25rem;margin-bottom:1rem">
                    <div style="font-weight:700;font-size:.85rem;color:#065f46">✅ Approved edits are auto-applied by Head Office</div>
                    <div id="approvedChangesList" style="margin-top:.5rem;font-size:.82rem;color:#047857"></div>
                </div>

                <!-- Personal Information -->
                <div class="edit-section">
                    <div class="edit-section-header">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span>Personal Information</span>
                    </div>
                    <div class="edit-section-body">
                        <div class="edit-form-row">
                            <div class="edit-form-group">
                                <label class="edit-label">First Name</label>
                                <input type="text" id="edit_first_name" name="first_name" class="edit-input">
                                <button type="button" class="req-change-btn" onclick="openChangeRequest('first_name','First Name','edit_first_name')">📝 Request Change</button>
                            </div>
                            <div class="edit-form-group">
                                <label class="edit-label">Middle Name</label>
                                <input type="text" id="edit_middle_name" name="middle_name" class="edit-input">
                                <button type="button" class="req-change-btn" onclick="openChangeRequest('middle_name','Middle Name','edit_middle_name')">📝 Request Change</button>
                            </div>
                        </div>
                        <div class="edit-form-row">
                            <div class="edit-form-group">
                                <label class="edit-label">Last Name</label>
                                <input type="text" id="edit_last_name" name="last_name" class="edit-input">
                                <button type="button" class="req-change-btn" onclick="openChangeRequest('last_name','Last Name','edit_last_name')">📝 Request Change</button>
                            </div>
                            <div class="edit-form-group">
                                <label class="edit-label">Gender</label>
                                <input type="text" id="edit_gender" name="gender" class="edit-input">
                                <button type="button" class="req-change-btn" onclick="openChangeRequest('gender','Gender','edit_gender')">📝 Request Change</button>
                            </div>
                        </div>
                        <div class="edit-form-row">
                            <div class="edit-form-group">
                                <label class="edit-label">Date of Birth</label>
                                <input type="text" id="edit_dob" name="dob" class="edit-input">
                                <button type="button" class="req-change-btn" onclick="openChangeRequest('dob','Date of Birth','edit_dob')">📝 Request Change</button>
                            </div>
                            <div class="edit-form-group">
                                <label class="edit-label">Qualification</label>
                                <input type="text" id="edit_qualification" name="qualification" class="edit-input">
                                <button type="button" class="req-change-btn" onclick="openChangeRequest('qualification','Qualification','edit_qualification')">📝 Request Change</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="edit-section">
                    <div class="edit-section-header">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        <span>Contact Information</span>
                    </div>
                    <div class="edit-section-body">
                        <div class="edit-form-row">
                            <div class="edit-form-group">
                                <label class="edit-label">Mobile Number <span class="edit-required">*</span></label>
                                <input type="tel" id="edit_mobile" name="mobile" required pattern="[0-9]{10}" maxlength="10" class="edit-input" placeholder="9876543210">
                                <button type="button" class="req-change-btn" id="req_mobile_btn" onclick="openChangeRequest('mobile','Mobile Number','edit_mobile')">📝 Request Change</button>
                            </div>
                            <div class="edit-form-group">
                                <label class="edit-label">Email Address</label>
                                <input type="text" id="edit_email" name="email" class="edit-input">
                                <button type="button" class="req-change-btn" onclick="openChangeRequest('email','Email Address','edit_email')">📝 Request Change</button>
                            </div>
                        </div>
                        <div class="edit-form-row">
                            <div class="edit-form-group full-width">
                                <label class="edit-label">Address</label>
                                <input type="text" id="edit_address" name="address" class="edit-input">
                                <button type="button" class="req-change-btn" onclick="openChangeRequest('address','Address','edit_address')">📝 Request Change</button>
                            </div>
                        </div>
                        <div class="edit-form-row">
                            <div class="edit-form-group">
                                <label class="edit-label">City</label>
                                <input type="text" id="edit_city" name="city" class="edit-input">
                                <button type="button" class="req-change-btn" onclick="openChangeRequest('city','City','edit_city')">📝 Request Change</button>
                            </div>
                            <div class="edit-form-group">
                                <label class="edit-label">PIN Code</label>
                                <input type="text" id="edit_pin_code" name="pin_code" class="edit-input">
                                <button type="button" class="req-change-btn" onclick="openChangeRequest('pin_code','PIN Code','edit_pin_code')">📝 Request Change</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Course & Status -->
                <div class="edit-section">
                    <div class="edit-section-header">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                        <span>Course &amp; Notes</span>
                    </div>
                    <div class="edit-section-body">
                        <div class="edit-form-row">
                            <div class="edit-form-group">
                                <label class="edit-label">Course</label>
                                <input type="text" id="edit_course" name="course" class="edit-input">
                                <button type="button" class="req-change-btn" onclick="openChangeRequest('course','Course','edit_course')">📝 Request Change</button>
                            </div>
                            <div class="edit-form-group">
                                <label class="edit-label">Referenced By</label>
                                <input type="text" id="edit_referenced_by" name="referenced_by" class="edit-input">
                                <button type="button" class="req-change-btn" onclick="openChangeRequest('referenced_by','Referenced By','edit_referenced_by')">📝 Request Change</button>
                            </div>
                        </div>
                        <div class="edit-form-row">
                            <div class="edit-form-group full-width">
                                <label class="edit-label">Comments / Notes</label>
                                <textarea id="edit_comment" name="comment" rows="2" class="edit-input" style="resize:none"></textarea>
                                <button type="button" class="req-change-btn" onclick="openChangeRequest('comment','Comments/Notes','edit_comment')">📝 Request Change</button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <div class="edit-student-footer">
                <button type="button" class="edit-btn-cancel" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="edit-btn-save" id="edit_submit_btn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    <span>Request Update</span>
                </button>
            </div>
        </form>
    </div>
</div>



<!-- Request Change Mini-Modal -->
<div class="modal-overlay" id="changeRequestModal" style="z-index:10000">
    <div class="modal-card" style="max-width:480px">
        <div class="modal-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Request Data Change
            </h3>
            <button type="button" class="modal-close" onclick="closeChangeRequestModal()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="cr_student_id">
            <input type="hidden" id="cr_field_name">
            <div style="margin-bottom:1rem">
                <label style="font-size:.8rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em">Field</label>
                <div id="cr_field_label" style="font-weight:700;font-size:.95rem;margin-top:.25rem;color:#1e293b"></div>
            </div>
            <div style="margin-bottom:1rem">
                <label style="font-size:.8rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em">Current Value</label>
                <div id="cr_old_value" style="font-size:.9rem;color:#6b7280;margin-top:.25rem;padding:.5rem .75rem;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0"></div>
            </div>
            <div style="margin-bottom:1rem">
                <label style="font-size:.8rem;font-weight:700;color:#1e293b;text-transform:uppercase;letter-spacing:.05em">New Value <span style="color:#ef4444">*</span></label>
                <input type="text" id="cr_new_value" class="form-input" placeholder="Enter desired new value" style="width:100%;margin-top:.35rem;padding:.65rem .875rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.9rem;font-family:inherit">
            </div>
            <div style="margin-bottom:.5rem">
                <label style="font-size:.8rem;font-weight:700;color:#1e293b;text-transform:uppercase;letter-spacing:.05em">Reason for Change <span style="color:#ef4444">*</span></label>
                <textarea id="cr_reason" rows="3" placeholder="Briefly explain why this change is needed…" style="width:100%;margin-top:.35rem;padding:.65rem .875rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.9rem;font-family:inherit;resize:vertical"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-secondary" onclick="closeChangeRequestModal()">Cancel</button>
            <button type="button" class="btn-primary" onclick="submitChangeRequest()">
                📤 Submit Request
            </button>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div id="toastContainer" style="position:fixed;bottom:1.5rem;right:1.5rem;z-index:99999;display:flex;flex-direction:column;gap:.5rem"></div>

<style>
.req-change-btn {
    display:none;align-items:center;gap:.3rem;margin-top:.35rem;
    font-size:.74rem;font-weight:700;color:#6366f1;background:#eff6ff;
    border:1.5px solid #c7d2fe;border-radius:6px;padding:.2rem .6rem;
    cursor:pointer;transition:background .15s;font-family:inherit;
}
.req-change-btn:hover { background:#e0e7ff; }
.req-state-badge { display:inline-flex; align-items:center; padding:.22rem .55rem; border-radius:999px; font-size:.74rem; font-weight:700; }
.req-state-badge.req-pending { background:#fef3c7; color:#92400e; }
.req-state-badge.req-approved { background:#d1fae5; color:#065f46; }
.req-state-badge.req-rejected { background:#fee2e2; color:#991b1b; }
@keyframes slideUp { from { opacity:0;transform:translateY(10px); } to { opacity:1;transform:translateY(0); } }
</style>

<script src="../assets/js/dashboard.js"></script>

<script>
// View student details
async function viewStudent(id) {
    try {
        const formData = new FormData();
        formData.append('action', 'get_student');
        formData.append('id', id);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success && result.data) {
            const student = result.data;
            const fullName = `${student.first_name} ${student.middle_name ? student.middle_name + ' ' : ''}${student.last_name}`;
            const netPayable = parseFloat(student.net_payable || student.fees_total || 0);
            const feesPaid = parseFloat(student.fees_paid || 0);
            const netBalance = netPayable - feesPaid;
            
            document.getElementById('studentContent').innerHTML = `
                <div class="student-details-grid">
                    ${student.photo ? `
                    <div class="detail-section" style="text-align: center;">
                        <img src="../${student.photo}" alt="${fullName}" style="width: 150px; height: 150px; border-radius: 50%; object-fit: cover; border: 4px solid var(--primary-500); box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
                    </div>
                    ` : ''}
                    
                    <div class="detail-section">
                        <h4>Personal Information</h4>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Roll Number</span>
                                <span class="detail-value">${student.roll_no}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Full Name</span>
                                <span class="detail-value">${fullName}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Gender</span>
                                <span class="detail-value">${student.gender}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Date of Birth</span>
                                <span class="detail-value">${new Date(student.dob).toLocaleDateString('en-IN', {day: '2-digit', month: 'short', year: 'numeric'})}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Qualification</span>
                                <span class="detail-value">${student.qualification}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Course</span>
                                <span class="detail-value">${student.course}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-section">
                        <h4>Contact Information</h4>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Mobile</span>
                                <span class="detail-value">${student.mobile}</span>
                            </div>
                            ${student.phone ? `
                            <div class="detail-item">
                                <span class="detail-label">Phone</span>
                                <span class="detail-value">${student.phone}</span>
                            </div>
                            ` : ''}
                            ${student.email ? `
                            <div class="detail-item full-width">
                                <span class="detail-label">Email</span>
                                <span class="detail-value">${student.email}</span>
                            </div>
                            ` : ''}
                            <div class="detail-item full-width">
                                <span class="detail-label">Address</span>
                                <span class="detail-value">${student.address}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">City</span>
                                <span class="detail-value">${student.city}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">PIN Code</span>
                                <span class="detail-value">${student.pin_code}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-section">
                        <h4>Admission & Fees Information</h4>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Admission Date</span>
                                <span class="detail-value">${new Date(student.admission_date).toLocaleDateString('en-IN', {day: '2-digit', month: 'short', year: 'numeric'})}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Status</span>
                                <span class="detail-value">
                                    <span class="cell-badge status-${student.status.toLowerCase()}">${student.status}</span>
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Net Payable</span>
                                <span class="detail-value">₹ ${netPayable.toFixed(2)}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Fees Paid</span>
                                <span class="detail-value" style="color: var(--success-600);">₹ ${feesPaid.toFixed(2)}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Balance</span>
                                <span class="detail-value" style="color: ${netBalance > 0 ? 'var(--amber-600)' : 'var(--success-600)'};">₹ ${netBalance.toFixed(2)}</span>
                            </div>
                            ${student.referenced_by ? `
                            <div class="detail-item">
                                <span class="detail-label">Referenced By</span>
                                <span class="detail-value">${student.referenced_by}</span>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                    
                    ${student.comment ? `
                    <div class="detail-section">
                        <h4>Comments</h4>
                        <p style="color: var(--text-secondary); line-height: 1.6;">${student.comment}</p>
                    </div>
                    ` : ''}
                </div>
            `;
            
            document.getElementById('studentModal').classList.add('active');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error loading student details');
    }
}

// Close modal
function closeModal() {
    document.getElementById('studentModal').classList.remove('active');
}

// Edit student
async function editStudent(id) {
    try {
        const formData = new FormData();
        formData.append('action', 'get_student');
        formData.append('id', id);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success && result.data) {
            const student = result.data;
            
            // Populate form fields
            document.getElementById('edit_student_id').value = student.id;
            document.getElementById('edit_first_name').value = student.first_name;
            document.getElementById('edit_middle_name').value = student.middle_name || '';
            document.getElementById('edit_last_name').value = student.last_name;
            document.getElementById('edit_gender').value = student.gender;
            document.getElementById('edit_dob').value = student.dob;
            document.getElementById('edit_qualification').value = student.qualification;
            document.getElementById('edit_course').value = student.course;
            document.getElementById('edit_mobile').value = student.mobile;
            document.getElementById('edit_email').value = student.email || '';
            document.getElementById('edit_address').value = student.address || '';
            document.getElementById('edit_city').value = student.city || '';
            document.getElementById('edit_pin_code').value = student.pin_code || '';
            document.getElementById('edit_referenced_by').value = student.referenced_by || '';
            document.getElementById('edit_comment').value = student.comment || '';
            document.getElementById('edit_current_photo').value = student.photo || '';

            const submitBtn = document.querySelector('#editStudentForm .edit-btn-save span');
            if (submitBtn) submitBtn.textContent = 'Request Update';

            const photoImg = document.getElementById('edit_photo_img');
            const photoPlaceholder = document.getElementById('edit_photo_placeholder');
            const photoName = document.getElementById('edit_photo_name');
            const photoInput = document.getElementById('edit_photo');

            if (photoInput) photoInput.value = '';
            if (student.photo) {
                photoImg.src = '../' + student.photo;
                photoImg.style.display = 'block';
                photoPlaceholder.style.display = 'none';
                photoName.textContent = 'Current photo: ' + student.photo.split('/').pop();
            } else {
                photoImg.src = '';
                photoImg.style.display = 'none';
                photoPlaceholder.style.display = 'flex';
                photoName.textContent = 'Upload JPG, PNG or GIF (max 2MB)';
            }

            const banner = document.getElementById('approvedChangesBanner');
            if (banner) banner.style.display = 'none';
            
            document.getElementById('editStudentModal').classList.add('active');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error loading student details');
    }
}

// ── Change Request Functions ──────────────────────────────────────────────
function openChangeRequest(fieldName, fieldLabel, inputId) {
    const oldVal = document.getElementById(inputId)?.value || '';
    document.getElementById('cr_field_name').value  = fieldName;
    document.getElementById('cr_field_label').textContent = fieldLabel;
    document.getElementById('cr_old_value').textContent   = oldVal || '(empty)';
    document.getElementById('cr_new_value').value  = '';
    document.getElementById('cr_reason').value     = '';
    document.getElementById('changeRequestModal').classList.add('active');
}

function closeChangeRequestModal() {
    document.getElementById('changeRequestModal').classList.remove('active');
}

async function submitChangeRequest() {
    const studentId  = document.getElementById('cr_student_id').value;
    const fieldName  = document.getElementById('cr_field_name').value;
    const fieldLabel = document.getElementById('cr_field_label').textContent;
    const oldValue   = document.getElementById('cr_old_value').textContent;
    const newValue   = document.getElementById('cr_new_value').value.trim();
    const reason     = document.getElementById('cr_reason').value.trim();

    if (!newValue || !reason) { showToast('Please fill New Value and Reason.', 'error'); return; }

    const fd = new FormData();
    fd.append('action', 'submit_change_request');
    fd.append('student_id', studentId);
    fd.append('field_name', fieldName);
    fd.append('field_label', fieldLabel);
    fd.append('old_value', oldValue === '(empty)' ? '' : oldValue);
    fd.append('new_value', newValue);
    fd.append('reason', reason);

    const res  = await fetch('', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
        showToast('✅ ' + data.message);
        closeChangeRequestModal();
    } else {
        showToast(data.message, 'error');
    }
}

function showToast(msg, type = 'success') {
    const t  = document.createElement('div');
    const bg = type === 'success' ? '#059669' : '#dc2626';
    t.style.cssText = `background:${bg};color:#fff;padding:.75rem 1.25rem;border-radius:10px;font-size:.875rem;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.18);animation:slideUp .3s ease;max-width:320px`;
    t.textContent = msg;
    document.getElementById('toastContainer').appendChild(t);
    setTimeout(() => t.remove(), 3500);
}


// Preview photo in edit modal
function previewEditPhoto(input) {
    const photoImg = document.getElementById('edit_photo_img');
    const photoPlaceholder = document.getElementById('edit_photo_placeholder');
    const photoName = document.getElementById('edit_photo_name');
    
    if (input.files && input.files[0]) {
        const file = input.files[0];
        
        // Validate file size (2MB)
        if (file.size > 2097152) {
            alert('File size must be less than 2MB');
            input.value = '';
            return;
        }
        
        // Validate file type
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        if (!allowedTypes.includes(file.type)) {
            alert('Only JPG, PNG and GIF files are allowed');
            input.value = '';
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            photoImg.src = e.target.result;
            photoImg.style.display = 'block';
            photoPlaceholder.style.display = 'none';
            photoName.textContent = file.name;
        };
        reader.readAsDataURL(file);
    }
}

// Close edit modal
function closeEditModal() {
    document.getElementById('editStudentModal').classList.remove('active');
}

// Handle edit form submission
document.getElementById('editStudentForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'update_student');
    
    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            alert(result.message || 'Update request sent to Admin.');
            location.reload();
        } else {
            alert(result.message || 'Error updating student');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error updating student');
    }
});

// Toggle status
async function toggleStatus(id, currentStatus) {
    const newStatus = currentStatus === 'Active' ? 'Inactive' : 'Active';
    
    if (!confirm(`Are you sure you want to change status to ${newStatus}?`)) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'update_status');
        formData.append('id', id);
        formData.append('status', newStatus);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            location.reload();
        } else {
            alert(result.message || 'Error updating status');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error updating status');
    }
}

// Delete student
async function deleteStudent(id, name) {
    if (!confirm(`Are you sure you want to delete ${name}? This action cannot be undone and will delete all related records (payments, remarks, etc.).`)) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            alert('Student deleted successfully');
            location.reload();
        } else {
            alert(result.message || 'Error deleting student');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error deleting student');
    }
}

// ── WhatsApp Fee Reminder ─────────────────────────────────────────────────
function sendFeeReminder(studentName, mobile, balanceAmount, courseName) {
    // Sanitize mobile number
    mobile = mobile.replace(/[^\d+]/g, '');
    if (!mobile.startsWith('+')) {
        mobile = '+91' + mobile.slice(-10);
    }

    const message = `Dear Parent/Guardian,

Greetings from *Gyanam India*!

This is a gentle reminder regarding the pending fee payment for the following student:

👤 *Student Name:* ${studentName}
📚 *Course:* ${courseName}
💰 *Outstanding Balance:* ₹${balanceAmount}

We kindly request you to clear the above dues at your earliest convenience to ensure uninterrupted learning and services.

You may visit the center or contact us for any queries regarding the payment.

Thank you for your continued trust and support.

Warm regards,
*Team Gyanam India*
📞 For queries, please contact your ATC center.`;

    const encodedMessage = encodeURIComponent(message);
    const whatsappUrl = `https://wa.me/${mobile.replace(/\D/g, '')}?text=${encodedMessage}`;
    window.open(whatsappUrl, '_blank', 'width=600,height=600');
}
</script>
<style>
/* Status Tabs */
.status-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    overflow-x: auto;
    padding-bottom: 0.5rem;
}

.status-tab {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.25rem;
    border-radius: var(--radius-lg);
    background: var(--bg-surface);
    border: 1.5px solid var(--border-color);
    text-decoration: none;
    color: var(--text-primary);
    font-weight: 600;
    font-size: 0.85rem;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.status-tab:hover {
    border-color: var(--primary-300);
    background: var(--primary-50);
}

.status-tab.active {
    background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
    border-color: var(--primary-600);
    color: #fff;
}

.tab-count {
    padding: 0.2rem 0.6rem;
    border-radius: var(--radius-full);
    font-size: 0.75rem;
    font-weight: 700;
    background: var(--gray-200);
    color: var(--text-primary);
}

.status-tab.active .tab-count {
    background: rgba(255, 255, 255, 0.25);
    color: #fff;
}

.badge-active {
    background: var(--success-100);
    color: var(--success-700);
}

.badge-inactive {
    background: var(--gray-200);
    color: var(--gray-700);
}

/* Fees Badges */
.fees-badge {
    display: inline-block;
    padding: 0.35rem 0.75rem;
    border-radius: var(--radius-full);
    font-size: 0.75rem;
    font-weight: 600;
}

.fees-badge.paid {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.fees-badge.partial {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
}

.fees-badge.pending {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
}

/* WhatsApp Notify Button */
.wa-notify-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border: none;
    border-radius: 50%;
    background: #25d366;
    color: white;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 2px 6px rgba(37, 211, 102, 0.35);
    position: relative;
    padding: 0;
}

.wa-notify-btn:hover {
    transform: scale(1.15);
    box-shadow: 0 3px 10px rgba(37, 211, 102, 0.5);
}

.wa-notify-btn:active {
    transform: scale(1);
}

.wa-notify-btn svg {
    width: 14px;
    height: 14px;
}

/* Student Details Grid */
.student-details-grid {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.detail-section h4 {
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border-color);
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.detail-item.full-width {
    grid-column: 1 / -1;
}

.detail-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.detail-value {
    font-size: 0.9rem;
    color: var(--text-primary);
}

/* ═══════════ EDIT STUDENT MODAL STYLES ═══════════ */
.edit-student-card {
    max-width: 900px;
    width: 95%;
    background: #ffffff;
    border-radius: 20px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    display: flex;
    flex-direction: column;
    max-height: 90vh;
    overflow: hidden;
}

/* Header */
.edit-student-header {
    position: relative;
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.5rem 2rem;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #fff;
    overflow: hidden;
}

.edit-student-header-bg {
    position: absolute;
    top: -50%;
    right: -10%;
    width: 300px;
    height: 300px;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
}

.edit-student-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    background: rgba(255,255,255,.18);
    backdrop-filter: blur(10px);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0,0,0,.1);
    z-index: 1;
}

.edit-student-icon svg {
    width: 24px;
    height: 24px;
}

.edit-student-title-wrap {
    z-index: 1;
}

.edit-student-title {
    font-size: 1.125rem;
    font-weight: 800;
    letter-spacing: -0.02em;
}

.edit-student-subtitle {
    font-size: .8125rem;
    opacity: .9;
    margin-top: .2rem;
    font-weight: 500;
}

.edit-student-close {
    margin-left: auto;
    background: rgba(255,255,255,.15);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 10px;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #fff;
    transition: all .2s ease;
    z-index: 1;
}

.edit-student-close:hover {
    background: rgba(255,255,255,.25);
    transform: rotate(90deg);
}

.edit-student-close svg {
    width: 18px;
    height: 18px;
}

/* Form */
.edit-student-form {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    flex: 1;
}

.edit-student-body {
    overflow-y: auto;
    overflow-x: hidden;
    padding: 1.5rem 2rem;
    background: #fafbfc;
    flex: 1;
}

/* Custom Scrollbar */
.edit-student-body::-webkit-scrollbar {
    width: 12px;
}

.edit-student-body::-webkit-scrollbar-track {
    background: #e2e8f0;
    border-radius: 10px;
    margin: 4px 0;
}

.edit-student-body::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #94a3b8 0%, #64748b 100%);
    border-radius: 10px;
    border: 3px solid #e2e8f0;
}

.edit-student-body::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(180deg, #64748b 0%, #475569 100%);
}

/* Section */
.edit-section {
    background: #ffffff;
    border: 1.5px solid #e5e7eb;
    border-radius: 16px;
    margin-bottom: 1.5rem;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,.04);
}

.edit-section:hover {
    border-color: #d1d5db;
    box-shadow: 0 4px 12px rgba(0,0,0,.06);
}

.edit-section-header {
    display: flex;
    align-items: center;
    gap: .7rem;
    padding: .875rem 1.25rem;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-bottom: 1.5px solid #e5e7eb;
    font-size: .8125rem;
    font-weight: 800;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: .08em;
}

.edit-section-header svg {
    width: 16px;
    height: 16px;
    stroke: #10b981;
    flex-shrink: 0;
}

.edit-section-body {
    padding: 1.25rem;
}

/* Photo Upload Area */
.photo-upload-area {
    display: flex;
    align-items: center;
    gap: 1.5rem;
}

.photo-preview-circle {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    overflow: hidden;
    border: 3px solid #10b981;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
    flex-shrink: 0;
    position: relative;
}

.photo-preview-circle img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.photo-placeholder-circle {
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, #10b981, #059669);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 2.5rem;
    font-weight: 700;
}

.photo-upload-info {
    flex: 1;
}

.photo-upload-btn {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    padding: .65rem 1.25rem;
    border-radius: 10px;
    border: 1.5px solid #e5e7eb;
    background: #ffffff;
    color: #6b7280;
    font-size: .875rem;
    font-weight: 700;
    cursor: pointer;
    transition: all .2s ease;
    font-family: 'Sora', sans-serif;
}

.photo-upload-btn:hover {
    background: #f9fafb;
    color: #374151;
    border-color: #d1d5db;
    transform: translateY(-1px);
}

.photo-upload-btn svg {
    width: 18px;
    height: 18px;
}

.photo-hint {
    font-size: .75rem;
    color: #6b7280;
    margin-top: .5rem;
}

.photo-filename {
    font-size: .8rem;
    color: #10b981;
    margin-top: .25rem;
    font-weight: 500;
}

/* Form Layout */
.edit-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1rem;
}

.edit-form-row:last-child {
    margin-bottom: 0;
}

.edit-form-group {
    display: flex;
    flex-direction: column;
    gap: .4rem;
}

.edit-form-group.full-width {
    grid-column: 1 / -1;
}

.edit-label {
    font-size: .8125rem;
    font-weight: 700;
    color: #374151;
    letter-spacing: -0.01em;
}

.edit-required {
    color: #ef4444;
    font-weight: 800;
}

.edit-input {
    height: 44px;
    padding: 0 1rem;
    border-radius: 10px;
    border: 1.5px solid #e5e7eb;
    background: #ffffff;
    font-size: .875rem;
    color: #1f2937;
    font-family: 'Sora', sans-serif;
    font-weight: 500;
    transition: all .2s ease;
    width: 100%;
}

.edit-input:hover {
    border-color: #d1d5db;
}

.edit-input:focus {
    outline: none;
    border-color: #10b981;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
}

textarea.edit-input {
    height: auto;
    padding: .875rem 1rem;
    resize: vertical;
    min-height: 80px;
}

/* Footer */
.edit-student-footer {
    display: flex;
    justify-content: flex-end;
    gap: .875rem;
    padding: 1.25rem 2rem;
    border-top: 1.5px solid #e5e7eb;
    background: #ffffff;
    box-shadow: 0 -4px 12px rgba(0,0,0,.03);
}

.edit-btn-cancel {
    padding: .75rem 1.75rem;
    border-radius: 10px;
    border: 1.5px solid #e5e7eb;
    background: #ffffff;
    color: #6b7280;
    font-size: .875rem;
    font-weight: 700;
    cursor: pointer;
    transition: all .2s ease;
    font-family: 'Sora', sans-serif;
}

.edit-btn-cancel:hover {
    background: #f9fafb;
    color: #374151;
    border-color: #d1d5db;
    transform: translateY(-1px);
}

.edit-btn-save {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    padding: .75rem 2rem;
    border-radius: 10px;
    border: none;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #fff;
    font-size: .875rem;
    font-weight: 800;
    cursor: pointer;
    transition: all .2s ease;
    box-shadow: 0 4px 14px rgba(16, 185, 129, .3);
    font-family: 'Sora', sans-serif;
}

.edit-btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(16, 185, 129, .4);
}

.edit-btn-save:disabled {
    opacity: .6;
    transform: none;
    cursor: not-allowed;
}

.edit-btn-save svg {
    width: 16px;
    height: 16px;
}

/* Responsive */
@media (max-width: 1024px) {
    .photo-upload-area {
        flex-direction: column;
        text-align: center;
    }
}

@media (max-width: 768px) {
    .edit-form-row {
        grid-template-columns: 1fr;
    }
    
    .edit-student-card {
        width: 98%;
        max-height: 95vh;
    }
    
    .edit-student-header {
        padding: 1.25rem 1.5rem;
    }
    
    .edit-student-body {
        padding: 1.25rem 1.5rem;
    }
    
    .edit-student-body::-webkit-scrollbar {
        width: 8px;
    }
    
    .edit-student-footer {
        padding: 1rem 1.5rem;
    }
    
    .photo-preview-circle {
        width: 100px;
        height: 100px;
    }
    
    .photo-placeholder-circle {
        font-size: 2rem;
    }
}

/* Responsive */
@media (max-width: 768px) {
    .detail-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
// ── Export helpers (ATC Students) ─────────────────────────────────────────
function getAtcTableData() {
    const headers = ['Roll No','Reg ID','Student Name','Gender/DOB','Course','Mobile','Email','Admission Date','Fees Status','Status'];
    const rows = [];
    document.querySelectorAll('#studentsTable tbody tr').forEach(tr => {
        const tds = tr.querySelectorAll('td');
        if (!tds.length || tds.length < 3) return;
        // Skip "no students" empty row
        if (tds[0] && tds[0].getAttribute('colspan')) return;
        rows.push([
            tds[1]?.querySelector('.cell-name')?.textContent?.trim() || '',  // Roll/Reg
            tds[1]?.querySelector('.cell-sub')?.textContent?.replace('Roll No:','')?.trim() || '',
            tds[2]?.querySelector('.cell-name')?.textContent?.trim() || '',  // Name
            tds[2]?.querySelector('.cell-sub')?.textContent?.trim() || '',   // Gender+DOB
            tds[3]?.querySelector('.cell-name')?.textContent?.trim() || '',  // Course
            tds[4]?.querySelector('div')?.textContent?.trim() || '',         // Mobile
            tds[4]?.querySelector('.cell-sub')?.textContent?.trim() || '',   // Email
            tds[5]?.textContent?.trim() || '',                                // Adm Date
            tds[6]?.querySelector('.fees-badge')?.textContent?.trim() || '', // Fees status
            tds[7]?.querySelector('.cell-badge')?.textContent?.trim() || ''  // Status
        ]);
    });
    return { headers, rows };
}

function exportCopy() {
    const { headers, rows } = getAtcTableData();
    if (!rows.length) { alert('No student data to copy.'); return; }
    const text = [headers, ...rows].map(r => r.join('\t')).join('\n');
    navigator.clipboard.writeText(text).then(() => alert('✅ Copied ' + rows.length + ' student(s) to clipboard!'));
}
function exportCSV() {
    const { headers, rows } = getAtcTableData();
    if (!rows.length) { alert('No data to export.'); return; }
    const csv = [headers, ...rows].map(r => r.map(c => '"'+String(c).replace(/"/g,'""')+'"').join(',')).join('\n');
    const a = Object.assign(document.createElement('a'), { href: 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv), download: 'atc_students_' + Date.now() + '.csv' });
    a.click();
}
function exportExcel() {
    const { headers, rows } = getAtcTableData();
    if (!rows.length) { alert('No data to export.'); return; }
    const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Students');
    XLSX.writeFile(wb, 'atc_students_' + Date.now() + '.xlsx');
}
function exportPDF() {
    const { headers, rows } = getAtcTableData();
    if (!rows.length) { alert('No data to export.'); return; }
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape' });
    doc.setFontSize(13); doc.text('Student List — Gyanam India (ATC)', 14, 14);
    doc.setFontSize(9);  doc.text('Generated: ' + new Date().toLocaleString('en-IN'), 14, 21);
    doc.autoTable({ head: [headers], body: rows, startY: 26, styles: { fontSize: 7.5 }, headStyles: { fillColor: [67, 97, 238] } });
    doc.save('atc_students_' + Date.now() + '.pdf');
}
function printStudents() {
    const { headers, rows } = getAtcTableData();
    if (!rows.length) { alert('No student data to print.'); return; }

    const status  = <?= json_encode($statusFilter !== 'all' ? $statusFilter : '') ?>;
    const course  = <?= json_encode($courseFilter !== 'all' ? $courseFilter : '') ?>;
    const search  = <?= json_encode($searchTerm) ?>;
    const filters = [status && ('Status: ' + status), course && ('Course: ' + course), search && ('Search: "' + search + '"')].filter(Boolean);

    const now = new Date().toLocaleString('en-IN', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
    const filterLine = filters.length ? '<p style="margin:.25rem 0 0;font-size:11px;color:#64748b">Filters: ' + filters.join(' &nbsp;|&nbsp; ') + '</p>' : '';

    // Build table — skip Photo (col 0) and Actions (last col)
    const printHeaders = headers.slice(1, -1);
    const printRows    = rows.map(r => r.slice(1, -1));

    const thHtml   = printHeaders.map(h => `<th>${h}</th>`).join('');
    const rowsHtml = printRows.map(r => '<tr>' + r.map(c => `<td>${c}</td>`).join('') + '</tr>').join('');

    const html = `<!DOCTYPE html><html><head><meta charset="UTF-8">
    <title>Students &mdash; Gyanam India (ATC)</title>
    <style>
        body{font-family:Arial,sans-serif;margin:1cm;font-size:11px;color:#111}
        h2{margin:0;font-size:15px}p{margin:0}
        table{width:100%;border-collapse:collapse;margin-top:12px}
        th{background:#4361ee;color:#fff;padding:6px 8px;text-align:left;font-size:9.5px;text-transform:uppercase;letter-spacing:.04em}
        td{padding:5px 8px;border-bottom:1px solid #e5e7eb;vertical-align:top;font-size:10.5px}
        tr:nth-child(even) td{background:#f8fafc}
        .footer{margin-top:14px;font-size:10px;color:#94a3b8;text-align:right}
        @media print{@page{margin:1cm;size:landscape}}
    </style></head><body>
    <h2>Student List &mdash; Gyanam India</h2>
    <p style="font-size:11px;color:#64748b;margin-top:3px">Generated: ${now} &nbsp;&bull;&nbsp; ${rows.length} student(s)</p>
    ${filterLine}
    <table><thead><tr>${thHtml}</tr></thead><tbody>${rowsHtml}</tbody></table>
    <div class="footer">Gyanam India &mdash; Confidential</div>
    </body></html>`;

    const w = window.open('', '_blank', 'width=1100,height=700');
    w.document.write(html);
    w.document.close();
    w.focus();
    setTimeout(() => { w.print(); w.close(); }, 400);
}
</script>
</body>
</html>
