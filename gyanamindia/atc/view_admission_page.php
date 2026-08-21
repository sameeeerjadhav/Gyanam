<?php
/**
 * Gyanam Portal — ATC: View Admission (Full-Page, Read-Only)
 * URL: view_admission_page.php?admission_id=X
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
if (file_exists(__DIR__ . '/../includes/notifications.php')) {
    require_once __DIR__ . '/../includes/notifications.php';
}
if (file_exists(__DIR__ . '/../includes/exam_integration.php')) {
    require_once __DIR__ . '/../includes/exam_integration.php';
}

requireLogin(['ATC CENTER']);

$pdo   = getDBConnection();
$atcId = $_SESSION['atc_id'] ?? null;
$admId = intval($_GET['admission_id'] ?? 0);

// ── Photo upload handler ──────────────────────────────────────────────────
$photoUploadMsg = '';
$photoUploadErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['student_photo']) && $admId > 0 && $atcId) {
    $file = $_FILES['student_photo'];
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $photoUploadErr = 'Upload failed (error code ' . $file['error'] . ').';
    } elseif (!isset($allowed[$file['type']])) {
        $photoUploadErr = 'Only JPG, PNG, GIF, WebP images are allowed.';
    } elseif ($file['size'] > 3 * 1024 * 1024) {
        $photoUploadErr = 'Image must be under 3 MB.';
    } else {
        $uploadDir = __DIR__ . '/../uploads/student_photos/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $ext      = $allowed[$file['type']];
        $filename = 'student_' . $admId . '_' . time() . '.' . $ext;
        $destPath = $uploadDir . $filename;
        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            $relPath = 'uploads/student_photos/' . $filename;
            $upd = $pdo->prepare("UPDATE admissions SET photo = ? WHERE id = ? AND atc_id = ?");
            $upd->execute([$relPath, $admId, $atcId]);
            $photoUploadMsg = 'Photo updated successfully!';
        } else {
            $photoUploadErr = 'Could not save the uploaded file.';
        }
    }
}

// Load admission
$admission = null;
if ($admId > 0 && $atcId) {
    $s = $pdo->prepare("SELECT * FROM admissions WHERE id = ? AND atc_id = ?");
    $s->execute([$admId, $atcId]);
    $admission = $s->fetch(PDO::FETCH_ASSOC);
}
if (!$admission) {
    header('Location: new_admission.php');
    exit;
}

// ── Load fee payment history ──────────────────────────────────────────────
$feePayments = [];
try {
    $fStmt = $pdo->prepare("SELECT id, amount, payment_date, receipt_no, payment_mode, description FROM fee_payments WHERE admission_id = ? ORDER BY payment_date ASC, id ASC");
    $fStmt->execute([$admId]);
    $feePayments = $fStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$fullName = trim(
    ($admission['first_name'] ?? '') . ' ' .
    ($admission['middle_name'] ? $admission['middle_name'] . ' ' : '') .
    ($admission['last_name'] ?? '')
);

$photoPath = null;
if (!empty($admission['photo'])) {
    $abs = realpath(__DIR__ . '/../' . $admission['photo']);
    if ($abs && file_exists($abs)) {
        $photoPath = '../' . ltrim($admission['photo'], '/');
    }
}

// Fee summary
$totalPaid    = array_sum(array_column($feePayments, 'amount'));
$netPayable   = floatval($admission['net_payable'] ?? 0);
$feesPending  = max(0, $netPayable - $totalPaid);

// ── Load all courses this student is enrolled in (by registration_id) ────
$enrollmentHistory = [];
try {
    $ehStmt = $pdo->prepare("
        SELECT id, course, admission_date, status, course_fees, discount_amount, net_payable, fees_pending,
               material_type, material_language, uniform_size
        FROM admissions
        WHERE registration_id = ? AND atc_id = ?
        ORDER BY admission_date ASC, id ASC
    ");
    $ehStmt->execute([$admission['registration_id'], $atcId]);
    $enrollmentHistory = $ehStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>View Admission — <?= e($fullName) ?> | Gyanam India</title>
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<link rel="stylesheet" href="../assets/css/inquiries.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎓</text></svg>">
<style>
.cvt-page-topbar {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 1.5rem; gap: 1rem; flex-wrap: wrap;
}
.cvt-page-title   { font-size: 1.25rem; font-weight: 800; color: var(--text-primary); }
.cvt-page-subtitle{ font-size: .85rem; color: var(--text-secondary); margin-top: .15rem; }

/* Student banner */
.cvt-student-banner {
    display: flex; align-items: flex-start; gap: 1rem;
    background: linear-gradient(135deg, #ecfdf5, #d1fae5);
    border: 1.5px solid #6ee7b7;
    border-radius: 14px; padding: 1rem 1.25rem; margin-bottom: 1.5rem;
}
.cvt-student-avatar {
    width: 52px; height: 52px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg, #10b981, #059669);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem; font-weight: 800; color: #fff;
}
.cvt-student-name  { font-size: 1.1rem; font-weight: 700; color: var(--text-primary); }
.cvt-student-meta  { font-size: .8rem; color: var(--text-secondary); margin-top: .2rem; }

/* Roll/Reg badges */
.id-badges { display: flex; gap: .6rem; flex-wrap: wrap; margin-top: .4rem; }
.id-badge {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .2rem .65rem; border-radius: 999px; font-size: .75rem; font-weight: 700;
}
.id-badge.roll { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.id-badge.reg  { background: #fdf4ff; color: #7e22ce; border: 1px solid #e9d5ff; }
.id-badge.status-active   { background: #ecfdf5; color: #065f46; border: 1px solid #6ee7b7; }
.id-badge.status-inactive { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

/* Sections */
.conv-section {
    background: #fff; border: 1.5px solid var(--border-color);
    border-radius: 14px; margin-bottom: 1.25rem; overflow: hidden;
}
.conv-section-header {
    display: flex; align-items: center; gap: .6rem;
    padding: .9rem 1.25rem;
    background: linear-gradient(135deg, #fafbfc, #f3f4f6);
    border-bottom: 1px solid var(--border-color);
    font-weight: 700; font-size: .9rem; color: var(--text-primary);
}
.conv-section-header svg { width: 17px; height: 17px; color: var(--primary-500); }
.conv-section-body { padding: 1.25rem; }
.conv-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1rem;
}
.conv-grid .full { grid-column: 1 / -1; }
.conv-field label {
    display: block; font-size: .78rem; font-weight: 600;
    color: var(--text-muted); margin-bottom: .3rem; text-transform: uppercase; letter-spacing: .03em;
}
.conv-value {
    font-size: .9rem; font-weight: 600; color: var(--text-primary);
    padding: .55rem .85rem; background: var(--gray-50);
    border: 1.5px solid var(--border-color); border-radius: 8px;
    min-height: 38px; word-break: break-word;
}
.conv-value.empty { color: var(--text-muted); font-style: italic; font-weight: 400; }
.conv-value.amount { color: #059669; font-weight: 800; font-size: 1rem; }

/* Photo box */
.photo-box {
    width: 120px; height: 140px; border-radius: 10px; overflow: hidden;
    border: 2px solid var(--border-color);
    display: flex; align-items: center; justify-content: center;
    background: var(--gray-50); color: var(--text-muted); flex-shrink: 0;
}
.photo-box img { width: 100%; height: 100%; object-fit: cover; }
.photo-box svg { width: 40px; height: 40px; opacity: .4; }

/* Photo upload area */
.photo-upload-area {
    display: flex; flex-direction: column; align-items: center; gap: .5rem;
    margin-left: auto;
}
.photo-upload-label {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .38rem .8rem; background: #fff; border: 1.5px solid #cbd5e1;
    border-radius: 8px; font-size: .73rem; font-weight: 700; color: #475569;
    cursor: pointer; transition: all .18s; white-space: nowrap;
}
.photo-upload-label:hover { background: #f1f5f9; border-color: #94a3b8; }
.photo-upload-label svg { width: 14px; height: 14px; }
.photo-upload-input { display: none; }
.photo-upload-btn {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .42rem .9rem; background: var(--primary-500); color: #fff;
    border: none; border-radius: 8px; font-size: .73rem; font-weight: 700;
    cursor: pointer; font-family: inherit; transition: all .18s;
}
.photo-upload-btn:hover { background: var(--primary-600); }
.photo-upload-filename { font-size: .68rem; color: #64748b; max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* Action bar */
.view-action-bar {
    display: flex; gap: .75rem; justify-content: flex-end;
    align-items: center; padding-top: .75rem; flex-wrap: wrap;
}

/* Fee receipt section */
.fee-history-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
.fee-history-table thead th {
    padding: .65rem 1rem; text-align: left; font-size: .7rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .07em; color: #9ca3af;
    background: #f9fafb; border-bottom: 1px solid #e5e7eb;
}
.fee-history-table tbody tr { border-bottom: 1px solid #f3f4f6; }
.fee-history-table tbody tr:hover { background: #f0f4ff; }
.fee-history-table tbody td { padding: .8rem 1rem; vertical-align: middle; }
.rcpt-no { font-family: monospace; font-size: .8rem; background: #f3f4f6; padding: .2rem .5rem; border-radius: 4px; color: #374151; }
.fee-amount { font-weight: 800; color: #059669; }
.mode-badge { display: inline-block; padding: .18rem .55rem; border-radius: 6px; font-size: .72rem; font-weight: 700; background: #eff6ff; color: #1d4ed8; }
.btn-rcpt-open { display: inline-flex; align-items: center; gap: .35rem; padding: .35rem .7rem; background: #eff6ff; color: #3b82f6; border-radius: 6px; font-size: .78rem; font-weight: 700; border: 1px solid #bfdbfe; cursor: pointer; font-family: inherit; text-decoration: none; }
.btn-rcpt-open:hover { background: #dbeafe; }
.btn-rcpt-dl { display: inline-flex; align-items: center; gap: .35rem; padding: .35rem .7rem; background: #ecfdf5; color: #059669; border-radius: 6px; font-size: .78rem; font-weight: 700; border: 1px solid #a7f3d0; text-decoration: none; }
.btn-rcpt-dl:hover { background: #d1fae5; }
.fee-summary-bar {
    display: flex; gap: 1.5rem; flex-wrap: wrap; padding: .85rem 1rem;
    background: linear-gradient(135deg, #f0fdf4, #ecfdf5); border-top: 1px solid #a7f3d0;
}
.fee-summary-item { display: flex; flex-direction: column; }
.fee-summary-label { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing:.04em; color: #6b7280; }
.fee-summary-value { font-size: 1rem; font-weight: 800; color: #111827; margin-top: .15rem; }
.fee-summary-value.pending { color: #dc2626; }
.fee-summary-value.paid { color: #059669; }

/* Alert messages */
.alert-success { padding: .75rem 1rem; border-radius: 10px; background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; font-weight: 600; margin-bottom: 1rem; }
.alert-error   { padding: .75rem 1rem; border-radius: 10px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; font-weight: 600; margin-bottom: 1rem; }

/* Enrollment history */
.enroll-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
.enroll-card {
    background: #fff; border: 1.5px solid #e5e7eb; border-radius: 12px;
    padding: 1rem; transition: all .2s; position: relative;
}
.enroll-card:hover { border-color: #a5b4fc; box-shadow: 0 4px 12px rgba(67,97,238,.08); }
.enroll-card.current { border-color: #4361ee; background: linear-gradient(135deg, #eff6ff, #eef2ff); }
.enroll-card.current::after {
    content: 'Currently Viewing'; position: absolute; top: .6rem; right: .6rem;
    font-size: .6rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em;
    background: linear-gradient(135deg, #4361ee, #3730a3); color: #fff;
    padding: .2rem .5rem; border-radius: 6px;
}
.enroll-course { font-size: .95rem; font-weight: 800; color: #1f2937; margin-bottom: .5rem; }
.enroll-meta { display: grid; grid-template-columns: 1fr 1fr; gap: .35rem .75rem; font-size: .78rem; }
.enroll-meta-label { color: #9ca3af; font-weight: 600; }
.enroll-meta-val { color: #374151; font-weight: 700; }
.enroll-status { display: inline-flex; padding: .15rem .55rem; border-radius: 6px; font-size: .7rem; font-weight: 700; }
.enroll-status.active { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
.enroll-status.inactive { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.enroll-count-badge {
    display: inline-flex; align-items: center; gap: .3rem; padding: .18rem .6rem;
    border-radius: 99px; font-size: .75rem; font-weight: 800;
    background: linear-gradient(135deg, #4361ee, #7c3aed); color: #fff;
    margin-left: .5rem;
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
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <div class="header-greeting">
                <h2>Student Details</h2>
                <p>Full admission record</p>
            </div>
        </div>
        <div class="header-right">
            <?php if (file_exists(__DIR__ . '/../includes/notification_bell.php')) include __DIR__ . '/../includes/notification_bell.php'; ?>
            <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
        </div>
    </header>

    <div class="page-content">

        <?php if ($photoUploadMsg): ?>
        <div class="alert-success">✅ <?= e($photoUploadMsg) ?></div>
        <?php endif; ?>
        <?php if ($photoUploadErr): ?>
        <div class="alert-error">⚠️ <?= e($photoUploadErr) ?></div>
        <?php endif; ?>

        <!-- Top bar -->
        <div class="cvt-page-topbar">
            <div>
                <div class="cvt-page-title"><?= e($fullName) ?></div>
                <div class="cvt-page-subtitle">Admission ID #<?= $admId ?> · Admitted on <?= date('d M Y', strtotime($admission['admission_date'])) ?></div>
            </div>
            <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                <a href="admission_form_pdf.php?admission_id=<?= $admId ?>" target="_blank" class="inq-btn inq-btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><polyline points="9 15 12 18 15 15"/></svg>
                    Download Form
                </a>
                <a href="new_admission.php" class="inq-btn inq-btn-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><polyline points="15 18 9 12 15 6"/></svg>
                    Back
                </a>
            </div>
        </div>

        <!-- Student banner with inline photo upload -->
        <div class="cvt-student-banner">
            <div class="cvt-student-avatar"><?= strtoupper(mb_substr($admission['first_name'], 0, 1)) ?></div>
            <div>
                <div class="cvt-student-name"><?= e($fullName) ?></div>
                <div class="id-badges">
                    <span class="id-badge roll">Roll No: <?= e($admission['roll_no']) ?></span>
                    <span class="id-badge reg">Reg ID: <?= e($admission['registration_id']) ?></span>
                    <span class="id-badge status-<?= strtolower($admission['status']) ?>"><?= e($admission['status']) ?></span>
                </div>
                <div class="cvt-student-meta" style="margin-top:.4rem;">
                    📱 <?= e($admission['mobile']) ?> &nbsp;·&nbsp;
                    📚 <?= e($admission['course']) ?> &nbsp;·&nbsp;
                    🏙 <?= e($admission['city']) ?>
                </div>
            </div>

            <!-- Photo + Upload -->
            <div class="photo-upload-area">
                <div class="photo-box">
                    <?php if ($photoPath): ?>
                        <img src="<?= e($photoPath) ?>" alt="Student Photo" id="photoPreview">
                    <?php else: ?>
                        <img src="" alt="" id="photoPreview" style="display:none;width:100%;height:100%;object-fit:cover;">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" id="photoPlaceholder"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="12" cy="10" r="3"/><path d="M7 21a5 5 0 0 1 10 0"/></svg>
                    <?php endif; ?>
                </div>
                <form method="POST" enctype="multipart/form-data" action="view_admission_page.php?admission_id=<?= $admId ?>" id="photoForm">
                    <input type="file" name="student_photo" id="photoInput" class="photo-upload-input" accept="image/jpeg,image/png,image/gif,image/webp">
                    <label for="photoInput" class="photo-upload-label">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <?= $photoPath ? 'Change Photo' : 'Upload Photo' ?>
                    </label>
                    <div class="photo-upload-filename" id="photoFilename" style="display:none;"></div>
                    <button type="submit" class="photo-upload-btn" id="photoSaveBtn" style="display:none;">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><polyline points="20 6 9 17 4 12"/></svg>
                        Save
                    </button>
                </form>
                <div style="font-size:.65rem;color:#94a3b8;text-align:center;">JPG/PNG · max 3MB</div>
            </div>
        </div>

        <?php
        // Helper: render a read-only field value
        function field(string $label, $value, string $extraClass = ''): void {
            $val = trim((string)$value);
            $isEmpty = ($val === '' || $val === '0' || $val === '0.00');
            echo '<div class="conv-field">';
            echo '<label>' . htmlspecialchars($label) . '</label>';
            echo '<div class="conv-value ' . ($isEmpty ? 'empty ' : '') . $extraClass . '">';
            echo $isEmpty ? '—' : htmlspecialchars($val);
            echo '</div></div>';
        }
        function fieldFull(string $label, $value): void {
            $val = trim((string)$value);
            $isEmpty = ($val === '');
            echo '<div class="conv-field full">';
            echo '<label>' . htmlspecialchars($label) . '</label>';
            echo '<div class="conv-value ' . ($isEmpty ? 'empty' : '') . '">';
            echo $isEmpty ? '—' : nl2br(htmlspecialchars($val));
            echo '</div></div>';
        }
        ?>

        <!-- 1. Personal Information -->
        <div class="conv-section">
            <div class="conv-section-header">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Personal Information
            </div>
            <div class="conv-section-body">
                <div class="conv-grid">
                    <?php
                    field('First Name',   $admission['first_name']);
                    field('Middle Name',  $admission['middle_name'] ?? '');
                    field('Last Name',    $admission['last_name']);
                    field('Gender',       $admission['gender']);
                    field('Date of Birth', !empty($admission['dob']) ? date('d M Y', strtotime($admission['dob'])) : '');
                    field('Qualification',$admission['qualification'] ?? '');
                    field("Father's Name",$admission['father_name'] ?? '');
                    field("Mother's Name",$admission['mother_name'] ?? '');
                    field('Category',     $admission['category'] ?? '');
                    ?>
                </div>
            </div>
        </div>

        <!-- 2. Contact & Address -->
        <div class="conv-section">
            <div class="conv-section-header">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                Contact &amp; Address
            </div>
            <div class="conv-section-body">
                <div class="conv-grid">
                    <?php
                    field('Mobile',      $admission['mobile']);
                    field('Alternate Phone', $admission['phone'] ?? '');
                    field('Email',       $admission['email'] ?? '');
                    fieldFull('Address', $admission['address'] ?? '');
                    field('City',        $admission['city'] ?? '');
                    field('State',       $admission['state'] ?? '');
                    field('PIN Code',    $admission['pin_code'] ?? '');
                    field('Referenced By', $admission['referenced_by'] ?? '');
                    ?>
                </div>
            </div>
        </div>

        <!-- 3. Course & Fee -->
        <div class="conv-section">
            <div class="conv-section-header">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                Course &amp; Fee Details
            </div>
            <div class="conv-section-body">
                <div class="conv-grid">
                    <?php
                    field('Course',          $admission['course']);
                    field('Admission Date',  !empty($admission['admission_date']) ? date('d M Y', strtotime($admission['admission_date'])) : '');
                    field('Installments',    $admission['installments'] ?? 1);
                    field('T-Shirt Size',    $admission['uniform_size'] ?? '');
                    field('Course Fees (₹)', '₹ ' . number_format(floatval($admission['course_fees'] ?? 0), 0), 'amount');
                    field('Discount (₹)',    '₹ ' . number_format(floatval($admission['discount_amount'] ?? 0), 0));
                    field('Discount Reason', $admission['discount_reason'] ?? '');
                    field('Net Payable (₹)', '₹ ' . number_format(floatval($admission['net_payable'] ?? 0), 0), 'amount');
                    field('Fees Pending (₹)','₹ ' . number_format(floatval($admission['fees_pending'] ?? 0), 0));
                    ?>
                </div>
            </div>
        </div>

        <!-- 4. Material & Notes -->
        <div class="conv-section">
            <div class="conv-section-header">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Material &amp; Notes
            </div>
            <div class="conv-section-body">
                <div class="conv-grid">
                    <?php
                    field('Material',          $admission['material_type'] ?? '');
                    field('Material Language', $admission['material_language'] ?? '');
                    fieldFull('Comments / Notes', $admission['comment'] ?? '');
                    ?>
                </div>
            </div>
        </div>

        <!-- 5. Fee Payment History -->
        <div class="conv-section">
            <div class="conv-section-header">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                Fee Payment History
                <span style="margin-left:auto;font-size:.78rem;font-weight:600;color:var(--text-muted);"><?= count($feePayments) ?> payment<?= count($feePayments) !== 1 ? 's' : '' ?></span>
            </div>
            <?php if (empty($feePayments)): ?>
            <div class="conv-section-body" style="text-align:center;color:var(--text-muted);padding:2rem 1rem;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:36px;height:36px;opacity:.35;margin-bottom:.5rem;display:block;margin-left:auto;margin-right:auto;"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                No fee payments recorded for this student yet.
            </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="fee-history-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Receipt No</th>
                            <th>Amount (₹)</th>
                            <th>Mode</th>
                            <th>Description</th>
                            <th style="text-align:center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($feePayments as $i => $pay): ?>
                        <tr>
                            <td style="color:#9ca3af;font-size:.8rem;"><?= $i + 1 ?></td>
                            <td><?= date('d M Y', strtotime($pay['payment_date'])) ?></td>
                            <td><span class="rcpt-no"><?= e($pay['receipt_no']) ?></span></td>
                            <td><span class="fee-amount">₹<?= number_format(floatval($pay['amount']), 0) ?></span></td>
                            <td><span class="mode-badge"><?= e($pay['payment_mode'] ?? 'Cash') ?></span></td>
                            <td style="font-size:.82rem;color:#6b7280;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e($pay['description'] ?? '') ?>"><?= e($pay['description'] ?? '—') ?></td>
                            <td style="text-align:center;">
                                <div style="display:flex;gap:.4rem;justify-content:center;">
                                    <a href="fee_receipt.php?payment_id=<?= $pay['id'] ?>" target="_blank" class="btn-rcpt-open" title="Open Receipt">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        Open
                                    </a>
                                    <a href="fee_receipt.php?payment_id=<?= $pay['id'] ?>&download=1" class="btn-rcpt-dl" title="Download Receipt">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        Download
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <!-- Summary bar -->
            <div class="fee-summary-bar">
                <div class="fee-summary-item">
                    <span class="fee-summary-label">Net Payable</span>
                    <span class="fee-summary-value">₹<?= number_format($netPayable, 0) ?></span>
                </div>
                <div class="fee-summary-item">
                    <span class="fee-summary-label">Total Paid</span>
                    <span class="fee-summary-value paid">₹<?= number_format($totalPaid, 0) ?></span>
                </div>
                <div class="fee-summary-item">
                    <span class="fee-summary-label">Balance Due</span>
                    <span class="fee-summary-value <?= $feesPending > 0 ? 'pending' : 'paid' ?>">
                        ₹<?= number_format($feesPending, 0) ?>
                        <?= $feesPending <= 0 ? ' ✅' : '' ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- 6. Course Enrollment History -->
        <?php if (count($enrollmentHistory) > 1): ?>
        <div class="conv-section">
            <div class="conv-section-header">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                Course Enrollment History
                <span class="enroll-count-badge"><?= count($enrollmentHistory) ?> courses</span>
            </div>
            <div class="conv-section-body">
                <div class="enroll-grid">
                    <?php foreach ($enrollmentHistory as $eh):
                        $isCurrent = ($eh['id'] == $admId);
                        $stClass = strtolower($eh['status'] ?? 'active');
                    ?>
                    <div class="enroll-card <?= $isCurrent ? 'current' : '' ?>">
                        <div class="enroll-course">📚 <?= e($eh['course']) ?></div>
                        <div class="enroll-meta">
                            <span class="enroll-meta-label">Admitted</span>
                            <span class="enroll-meta-val"><?= date('d M Y', strtotime($eh['admission_date'])) ?></span>
                            <span class="enroll-meta-label">Status</span>
                            <span class="enroll-meta-val"><span class="enroll-status <?= $stClass ?>"><?= e($eh['status']) ?></span></span>
                            <span class="enroll-meta-label">Course Fees</span>
                            <span class="enroll-meta-val">₹<?= number_format(floatval($eh['course_fees'] ?? 0), 0) ?></span>
                            <span class="enroll-meta-label">Net Payable</span>
                            <span class="enroll-meta-val" style="color:#059669;">₹<?= number_format(floatval($eh['net_payable'] ?? 0), 0) ?></span>
                            <?php if (!empty($eh['material_type']) && $eh['material_type'] !== 'Without Material'): ?>
                            <span class="enroll-meta-label">Material</span>
                            <span class="enroll-meta-val"><?= e($eh['material_type']) ?> (<?= e($eh['material_language'] ?? '') ?>)</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$isCurrent): ?>
                        <div style="margin-top:.65rem;">
                            <a href="view_admission_page.php?admission_id=<?= $eh['id'] ?>" style="font-size:.75rem;font-weight:700;color:#4361ee;text-decoration:none;">View Details →</a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 7. Exam Results -->
        <?php
        $examResults = [];
        $examResultsError = null;
        if (function_exists('fetchStudentExamResults') && defined('EXAM_API_TOKEN') && EXAM_API_TOKEN !== 'PASTE_YOUR_TOKEN_HERE' && !empty($admission['registration_id'])) {
            try {
                $examApiRes = fetchStudentExamResults($admission['registration_id']);
                if ($examApiRes['success'] && isset($examApiRes['data']['submissions'])) {
                    $examResults = $examApiRes['data']['submissions'];
                } elseif (!$examApiRes['success']) {
                    $examResultsError = $examApiRes['error'] ?? 'Failed to fetch results';
                }
            } catch (Exception $exErr) {
                $examResultsError = $exErr->getMessage();
            }
        }
        ?>
        <div class="conv-section">
            <div class="conv-section-header">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                Exam Results
                <?php if (!empty($examResults)): ?>
                <span class="enroll-count-badge"><?= count($examResults) ?> attempt<?= count($examResults) !== 1 ? 's' : '' ?></span>
                <?php endif; ?>
            </div>
            <?php if (!function_exists('fetchStudentExamResults') || !defined('EXAM_API_TOKEN') || EXAM_API_TOKEN === 'PASTE_YOUR_TOKEN_HERE'): ?>
            <div class="conv-section-body" style="text-align:center;color:var(--text-muted);padding:2rem 1rem;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:36px;height:36px;opacity:.35;margin-bottom:.5rem;display:block;margin-left:auto;margin-right:auto;"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                Exam Portal integration not configured yet.
            </div>
            <?php elseif ($examResultsError): ?>
            <div class="conv-section-body" style="text-align:center;padding:2rem 1rem;">
                <div style="color:#dc2626;font-size:.85rem;font-weight:600;">⚠️ <?= e($examResultsError) ?></div>
            </div>
            <?php elseif (empty($examResults)): ?>
            <div class="conv-section-body" style="text-align:center;color:var(--text-muted);padding:2rem 1rem;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:36px;height:36px;opacity:.35;margin-bottom:.5rem;display:block;margin-left:auto;margin-right:auto;"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                No exam results recorded for this student yet.
            </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="fee-history-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Exam Title</th>
                            <th>Score</th>
                            <th>Result</th>
                            <th>Correct / Total</th>
                            <th>Duration</th>
                            <th>Submitted At</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($examResults as $eIdx => $er): ?>
                        <tr>
                            <td style="color:#9ca3af;font-size:.8rem;"><?= $eIdx + 1 ?></td>
                            <td style="font-weight:700;color:#1f2937;"><?= e($er['exam_title'] ?? 'N/A') ?></td>
                            <td>
                                <span style="font-weight:800;font-size:1rem;color:<?= ($er['score'] ?? 0) >= 50 ? '#059669' : '#dc2626' ?>">
                                    <?= e($er['score'] ?? '0') ?>%
                                </span>
                            </td>
                            <td>
                                <?php $isPassed = strtolower($er['result'] ?? '') === 'pass'; ?>
                                <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .6rem;border-radius:6px;font-size:.72rem;font-weight:700;
                                    background:<?= $isPassed ? '#ecfdf5' : '#fef2f2' ?>;
                                    color:<?= $isPassed ? '#065f46' : '#991b1b' ?>;
                                    border:1px solid <?= $isPassed ? '#a7f3d0' : '#fecaca' ?>;">
                                    <?= $isPassed ? '✅ Pass' : '❌ Fail' ?>
                                </span>
                            </td>
                            <td style="font-weight:600;"><?= e(($er['correct_answers'] ?? '0') . ' / ' . ($er['total_questions'] ?? '0')) ?></td>
                            <td style="font-size:.82rem;color:#6b7280;"><?= e($er['duration_taken'] ?? '—') ?></td>
                            <td style="font-size:.82rem;">
                                <?php if (!empty($er['submitted_at'])): ?>
                                    <?= date('d M Y, h:i A', strtotime($er['submitted_at'])) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Action bar -->
        <div class="view-action-bar">
            <a href="admission_form_pdf.php?admission_id=<?= $admId ?>" target="_blank" class="inq-btn inq-btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><polyline points="9 15 12 18 15 15"/></svg>
                Download Admission Form
            </a>
            <a href="new_admission.php" class="inq-btn inq-btn-secondary">Back to Admissions</a>
        </div>

    </div><!-- /.page-content -->
</main>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
// Live photo preview before upload
const photoInput = document.getElementById('photoInput');
const photoPreview = document.getElementById('photoPreview');
const photoPlaceholder = document.getElementById('photoPlaceholder');
const photoFilename = document.getElementById('photoFilename');
const photoSaveBtn = document.getElementById('photoSaveBtn');

if (photoInput) {
    photoInput.addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function (e) {
            photoPreview.src = e.target.result;
            photoPreview.style.display = 'block';
            if (photoPlaceholder) photoPlaceholder.style.display = 'none';
        };
        reader.readAsDataURL(file);
        photoFilename.textContent = file.name;
        photoFilename.style.display = 'block';
        photoSaveBtn.style.display = 'inline-flex';
    });
}
</script>
</body>
</html>
