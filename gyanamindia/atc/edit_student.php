<?php
/**
 * Gyanam Portal — ATC: Edit Student (Full-page)
 * Two modes:
 *   • Share NOT paid → direct edit all fields, Save Changes button
 *   • Share IS paid  → fields locked (except phone), click lock icon → change request modal
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
if (file_exists(__DIR__ . '/../includes/notifications.php')) {
    require_once __DIR__ . '/../includes/notifications.php';
}
requireLogin(['ATC CENTER']);

$pdo   = getDBConnection();
$atcId = $_SESSION['atc_id'] ?? null;
$stuId = intval($_GET['id'] ?? 0);
ensureDualMaterialCourseSchema($pdo);

if (!$stuId || !$atcId) { header('Location: students.php'); exit; }

// Load student
$stmt = $pdo->prepare("SELECT * FROM admissions WHERE id = ? AND atc_id = ?");
$stmt->execute([$stuId, $atcId]);
$stu = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$stu) { header('Location: students.php'); exit; }

// Load ATC courses
$coursesStmt = $pdo->prepare("
    SELECT c.course_name,
           c.material_type, c.ho_share, c.ho_share_with_material, c.ho_share_without_material,
           acf.final_fee AS fees, acf.fee_with_material, acf.fee_without_material
    FROM courses c
    INNER JOIN atc_course_fees acf ON acf.course_id = c.id AND acf.atc_id = ?
    WHERE c.status = 'Active'
      AND (COALESCE(acf.fee_with_material, 0) > 0
        OR COALESCE(acf.fee_without_material, 0) > 0
        OR COALESCE(acf.final_fee, 0) > 0)
    ORDER BY c.course_name ASC
");
$coursesStmt->execute([$atcId]);
$atcCourseRows = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);
$atcCourses = array_column($atcCourseRows, 'course_name');

// Allowed material types per course (HO share + ATC fee both > 0)
$courseMaterialOptionsMap = [];
foreach ($atcCourseRows as $row) {
    $opts = [];
    foreach (buildCourseMaterialOptions($row) as $opt) {
        $opts[] = $opt['material_type'];
    }
    $courseMaterialOptionsMap[$row['course_name']] = $opts;
}

// Check share paid
function isSharePaid_Edit($pdo, $studentId) {
    try {
        $sp = $pdo->prepare("SELECT student_ids FROM share_payments WHERE atc_id = (SELECT atc_id FROM admissions WHERE id = ?) AND status = 'Completed'");
        $sp->execute([$studentId]);
        foreach ($sp->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $ids = json_decode($json, true);
            if (is_array($ids) && in_array((string)$studentId, array_map('strval', $ids))) return true;
        }
    } catch (Exception $e) {}
    return false;
}
$sharePaid = isSharePaid_Edit($pdo, $stuId);

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$fullName = trim(($stu['first_name'] ?? '') . ' ' . ($stu['middle_name'] ? $stu['middle_name'] . ' ' : '') . ($stu['last_name'] ?? ''));

$photoPath = null;
$photoNeverUploaded = true;
if (!empty($stu['photo'])) {
    $abs = realpath(__DIR__ . '/../' . $stu['photo']);
    if ($abs && file_exists($abs)) {
        $photoPath = '../' . ltrim($stu['photo'], '/');
        $photoNeverUploaded = false;
    }
}

$genderOptions   = ['Male','Female','Other'];
$categoryOptions = ['General','OBC','SC','ST'];
$qualOptions     = ['Below 10th','10th Pass','12th Pass','Diploma','Graduate','Post Graduate','Other'];
$materialTypes   = $courseMaterialOptionsMap[$stu['course'] ?? ''] ?? [];
if (empty($materialTypes)) {
    // Keep current value selectable if course options are unavailable
    $curMat = $stu['material_type'] ?? 'Without Material';
    $materialTypes = $curMat ? [$curMat] : ['Without Material'];
} elseif (!empty($stu['material_type']) && !in_array($stu['material_type'], $materialTypes, true)) {
    $materialTypes[] = $stu['material_type'];
}
$materialLangs   = ['English','Marathi'];
$tshirtSizes     = ['36','38','40','42','44','46'];

$courseList = $atcCourses;
if ($stu['course'] && !in_array($stu['course'], $courseList)) {
    array_unshift($courseList, $stu['course']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Student — <?= e($fullName) ?> | Gyanam India</title>
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<link rel="stylesheet" href="../assets/css/inquiries.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>✏️</text></svg>">
<style>
/* ── Page layout ── */
.cvt-page-topbar { display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;gap:1rem;flex-wrap:wrap; }
.cvt-page-title  { font-size:1.25rem;font-weight:800;color:var(--text-primary); }
.cvt-page-subtitle{ font-size:.85rem;color:var(--text-secondary);margin-top:.15rem; }

/* ── Student banner ── */
.cvt-student-banner {
    display:flex;align-items:center;gap:1rem;
    background:linear-gradient(135deg,#eff6ff,#dbeafe);
    border:1.5px solid #93c5fd;border-radius:14px;
    padding:1rem 1.25rem;margin-bottom:1.5rem;
}
.cvt-student-avatar {
    width:52px;height:52px;border-radius:50%;flex-shrink:0;
    background:linear-gradient(135deg,#4361ee,#3730a3);
    display:flex;align-items:center;justify-content:center;
    font-size:1.4rem;font-weight:800;color:#fff;
}
.cvt-student-name { font-size:1.1rem;font-weight:700;color:var(--text-primary); }
.id-badges { display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.4rem; }
.id-badge  { display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:999px;font-size:.75rem;font-weight:700; }
.id-badge.roll { background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe; }
.id-badge.reg  { background:#fdf4ff;color:#7e22ce;border:1px solid #e9d5ff; }
.id-badge.status-active { background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7; }
.id-badge.status-inactive{ background:#fef2f2;color:#991b1b;border:1px solid #fecaca; }

/* Photo upload */
.photo-upload-wrap { display:flex;flex-direction:column;align-items:center;gap:.45rem;margin-left:auto;flex-shrink:0; }
.photo-box { width:100px;height:120px;border-radius:10px;overflow:hidden;border:2px solid var(--border-color);display:flex;align-items:center;justify-content:center;background:var(--gray-50);flex-shrink:0; }
.photo-box img { width:100%;height:100%;object-fit:cover; }
.photo-box svg { width:36px;height:36px;opacity:.4; }
.photo-choose-btn { display:inline-flex;align-items:center;gap:.3rem;padding:.32rem .7rem;background:#fff;border:1.5px solid #cbd5e1;border-radius:7px;font-size:.72rem;font-weight:700;color:#475569;cursor:pointer;transition:all .18s;white-space:nowrap; }
.photo-choose-btn:hover { background:#f1f5f9;border-color:#94a3b8; }
.photo-choose-btn svg { width:13px;height:13px; }
.photo-upload-input { display:none; }
.photo-save-btn { display:none;align-items:center;gap:.3rem;padding:.35rem .75rem;background:var(--primary-500);color:#fff;border:none;border-radius:7px;font-size:.72rem;font-weight:700;cursor:pointer;font-family:inherit;transition:all .18s; }
.photo-save-btn:hover { background:var(--primary-600); }
.photo-save-btn.visible { display:inline-flex; }
.photo-fname { font-size:.65rem;color:#94a3b8;max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
.photo-req-notice { font-size:.65rem;color:#d97706;font-weight:700;text-align:center;max-width:110px; }

/* ── Edit sections ── */
.edit-section { background:#fff;border:1.5px solid var(--border-color);border-radius:14px;margin-bottom:1.25rem;overflow:hidden; }
.edit-section-header { display:flex;align-items:center;gap:.6rem;padding:.9rem 1.25rem;background:linear-gradient(135deg,#fafbfc,#f3f4f6);border-bottom:1px solid var(--border-color);font-weight:700;font-size:.9rem;color:var(--text-primary); }
.edit-section-header svg { width:17px;height:17px;color:var(--primary-500); }
.edit-section-body { padding:1.25rem; }
.edit-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1rem; }
.edit-grid .full { grid-column:1/-1; }

.edit-field { display:flex;flex-direction:column;gap:.3rem;position:relative; }
.edit-field label { font-size:.78rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.03em; }
.edit-input, .edit-select, .edit-textarea {
    padding:.6rem .9rem;border:1.5px solid var(--border-color);border-radius:8px;
    font-size:.9rem;font-family:inherit;color:var(--text-primary);background:#fff;
    transition:border-color .18s;width:100%;box-sizing:border-box;
}
.edit-input:focus,.edit-select:focus,.edit-textarea:focus { border-color:var(--primary-400);outline:none;box-shadow:0 0 0 3px rgba(67,97,238,.08); }
.edit-textarea { resize:vertical; }

/* ── Locked field (share paid) ── */
.edit-field.locked .edit-input,
.edit-field.locked .edit-select,
.edit-field.locked .edit-textarea {
    background: #f8fafc;
    color: #64748b;
    cursor: pointer;
    border-color: #e2e8f0;
}
.edit-field.locked .edit-input:hover,
.edit-field.locked .edit-select:hover,
.edit-field.locked .edit-textarea:hover {
    border-color: #818cf8;
    background: #eef2ff;
}
.lock-icon {
    position: absolute;
    top: 0;
    right: 0;
    width: 22px;
    height: 22px;
    background: linear-gradient(135deg, #818cf8, #6366f1);
    border-radius: 0 0 0 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all .2s;
}
.lock-icon:hover {
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    transform: scale(1.1);
}
.lock-icon svg { width: 11px; height: 11px; stroke: #fff; fill: none; }

/* ── Info notices ── */
.share-notice {
    display:flex;align-items:flex-start;gap:.75rem;
    background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;
    padding:.85rem 1.1rem;margin-bottom:1.25rem;
}
.share-notice.unlocked {
    background:#ecfdf5;border-color:#6ee7b7;
}
.share-notice svg { width:18px;height:18px;flex-shrink:0;margin-top:.1rem; }
.share-notice-text { font-size:.82rem;color:#92400e;font-weight:600; }
.share-notice.unlocked .share-notice-text { color:#065f46; }

/* Action bar */
.edit-action-bar { display:flex;gap:.75rem;justify-content:flex-end;align-items:center;padding-top:.75rem;flex-wrap:wrap; }

/* Phone save btn */
.phone-save-btn {
    display:inline-flex;align-items:center;gap:.35rem;
    padding:.4rem .8rem;background:linear-gradient(135deg,#10b981,#059669);
    color:#fff;border:none;border-radius:8px;font-size:.75rem;font-weight:700;
    cursor:pointer;font-family:inherit;transition:all .2s;margin-top:.25rem;
}
.phone-save-btn:hover { transform:translateY(-1px);box-shadow:0 2px 8px rgba(16,185,129,.3); }

/* Request-change mini modal */
.rcm-overlay { position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:9999;backdrop-filter:blur(2px); }
.rcm-overlay.active { display:flex; }
.rcm-card { background:#fff;border-radius:16px;width:100%;max-width:480px;padding:1.5rem;box-shadow:0 20px 60px rgba(0,0,0,.2);margin:1rem;animation:slideUp .25s ease; }
.rcm-title { font-size:1rem;font-weight:800;color:var(--text-primary);margin-bottom:1rem;display:flex;align-items:center;gap:.5rem; }
.rcm-label { font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b; }
.rcm-value-box { padding:.5rem .7rem;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;font-size:.9rem;color:#6b7280;margin-top:.25rem;margin-bottom:.75rem; }
.rcm-input { width:100%;padding:.65rem .875rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.9rem;font-family:inherit;box-sizing:border-box; }
.rcm-input:focus { border-color:#4361ee;outline:none; }
.rcm-select { width:100%;padding:.65rem .875rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.9rem;font-family:inherit;box-sizing:border-box; }
.rcm-select:focus { border-color:#4361ee;outline:none; }
.rcm-textarea { width:100%;padding:.65rem .875rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.9rem;font-family:inherit;resize:vertical;margin-top:.25rem;box-sizing:border-box; }
.rcm-textarea:focus { border-color:#4361ee;outline:none; }
.rcm-footer { display:flex;justify-content:flex-end;gap:.75rem;margin-top:1rem; }

/* Toast */
#esToastContainer { position:fixed;bottom:1.5rem;right:1.5rem;z-index:99999;display:flex;flex-direction:column;gap:.5rem; }

/* Animations */
@keyframes slideUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
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
                <h2>Edit Student</h2>
                <p><?= $sharePaid ? 'Request changes to student data' : 'Update student details' ?></p>
            </div>
        </div>
        <div class="header-right">
            <?php if (file_exists(__DIR__ . '/../includes/notification_bell.php')) include __DIR__ . '/../includes/notification_bell.php'; ?>
            <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
        </div>
    </header>

    <div class="page-content">

        <!-- Top bar -->
        <div class="cvt-page-topbar">
            <div>
                <div class="cvt-page-title">✏️ Edit — <?= e($fullName) ?></div>
                <div class="cvt-page-subtitle">Roll No: <?= e($stu['roll_no']) ?> · Reg ID: <?= e($stu['registration_id']) ?></div>
            </div>
            <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                <a href="view_admission_page.php?admission_id=<?= $stuId ?>" class="inq-btn inq-btn-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    View Details
                </a>
                <a href="students.php" class="inq-btn inq-btn-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><polyline points="15 18 9 12 15 6"/></svg>
                    Back
                </a>
            </div>
        </div>

        <!-- Student banner -->
        <div class="cvt-student-banner">
            <div class="cvt-student-avatar"><?= strtoupper(mb_substr($stu['first_name'], 0, 1)) ?></div>
            <div>
                <div class="cvt-student-name"><?= e($fullName) ?></div>
                <div class="id-badges">
                    <span class="id-badge roll">Roll: <?= e($stu['roll_no']) ?></span>
                    <span class="id-badge reg">Reg: <?= e($stu['registration_id']) ?></span>
                    <span class="id-badge status-<?= strtolower($stu['status']) ?>"><?= e($stu['status']) ?></span>
                </div>
            </div>

            <!-- Photo upload -->
            <div class="photo-upload-wrap">
                <div class="photo-box" id="photoBox">
                    <?php if ($photoPath): ?>
                    <img src="<?= e($photoPath) ?>" alt="Photo" id="photoPreview">
                    <?php else: ?>
                    <img src="" alt="Photo" id="photoPreview" style="display:none;width:100%;height:100%;object-fit:cover;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" id="photoPlaceholder"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="12" cy="10" r="3"/><path d="M7 21a5 5 0 0 1 10 0"/></svg>
                    <?php endif; ?>
                </div>
                <label class="photo-choose-btn" for="photoFileInput">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <?= $photoPath ? 'Change Photo' : 'Upload Photo' ?>
                </label>
                <input type="file" id="photoFileInput" class="photo-upload-input" accept="image/jpeg,image/png,image/gif,image/webp">
                <div class="photo-fname" id="photoFname" style="display:none;"></div>
                <?php if ($sharePaid && !$photoNeverUploaded): ?>
                <div class="photo-req-notice">⚠️ Photo change needs Admin approval</div>
                <button type="button" class="photo-save-btn" id="photoSaveBtn" onclick="submitPhotoRequest()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px"><polyline points="20 6 9 17 4 12"/></svg>
                    Request Change
                </button>
                <?php elseif ($sharePaid && $photoNeverUploaded): ?>
                <div class="photo-req-notice" style="color:#059669;">✅ First photo upload allowed</div>
                <button type="button" class="photo-save-btn" id="photoSaveBtn" onclick="submitPhotoFirstTime()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px"><polyline points="20 6 9 17 4 12"/></svg>
                    Upload & Save
                </button>
                <?php else: ?>
                <button type="button" class="photo-save-btn" id="photoSaveBtn" onclick="submitPhotoDirect()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px"><polyline points="20 6 9 17 4 12"/></svg>
                    Save Photo
                </button>
                <?php endif; ?>
                <div style="font-size:.62rem;color:#94a3b8;text-align:center;">JPG/PNG/GIF · max 3MB</div>
            </div>
        </div>

        <?php if ($sharePaid): ?>
        <div class="share-notice">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div class="share-notice-text">
                HO Share has been paid. Most fields are <strong>locked</strong> — click the 🔒 lock icon to request a change (Admin approval required).
                <br><span style="color:#059669;">✅ <strong>Always editable without approval:</strong> Mobile number &amp; Address details.</span>
                <br><span style="color:#dc2626;">🚫 <strong>Course cannot be changed</strong> at all once admitted.</span>
                <?php if ($photoNeverUploaded): ?>
                <br><span style="color:#059669;">📸 <strong>Exception:</strong> First-time photo upload is allowed without admin approval.</span>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="share-notice unlocked">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="16 12 12 8 8 12"/><line x1="12" y1="16" x2="12" y2="8"/></svg>
            <div class="share-notice-text">
                HO Share has <strong>not been paid</strong> yet. You can edit all fields directly (except Course) and save changes.
            </div>
        </div>
        <?php endif; ?>

        <?php
        // Helper: render a field
        // $type: text, date, select, textarea
        // $locked: boolean, whether field is locked (share paid and NOT a phone field)
        function renderField($id, $label, $type, $value, $locked, $options = [], $attrs = '') {
            $cls = $locked ? 'edit-field locked' : 'edit-field';
            $fieldName = str_replace('f_', '', $id);
            echo '<div class="' . $cls . '"' . ($attrs ? ' ' . $attrs : '') . '>';
            echo '<label>' . htmlspecialchars($label) . '</label>';

            if ($locked) {
                // Lock icon badge
                echo '<div class="lock-icon" onclick="openRC(\'' . $fieldName . '\', \'' . addslashes($label) . '\', \'' . $type . '\', \'' . $id . '\'' . (!empty($options) ? ', ' . htmlspecialchars(json_encode($options)) : '') . ')" title="Click to request change">';
                echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
                echo '</div>';
            }

            switch ($type) {
                case 'select':
                    echo '<select class="edit-select" id="' . $id . '"' . ($locked ? ' disabled' : '') . '>';
                    foreach ($options as $opt) {
                        $sel = ((string)$value === (string)$opt) ? ' selected' : '';
                        echo '<option value="' . htmlspecialchars($opt) . '"' . $sel . '>' . htmlspecialchars($opt) . '</option>';
                    }
                    echo '</select>';
                    if ($locked) {
                        // Clicking the disabled select opens the RC modal
                        echo '<div style="position:absolute;inset:0;top:20px;cursor:pointer;" onclick="openRC(\'' . $fieldName . '\', \'' . addslashes($label) . '\', \'select\', \'' . $id . '\', ' . htmlspecialchars(json_encode($options)) . ')"></div>';
                    }
                    break;
                case 'textarea':
                    echo '<textarea class="edit-textarea edit-input" id="' . $id . '" rows="3"' . ($locked ? ' readonly onclick="openRC(\'' . $fieldName . '\', \'' . addslashes($label) . '\', \'textarea\', \'' . $id . '\')"' : '') . '>' . htmlspecialchars($value) . '</textarea>';
                    break;
                case 'date':
                    echo '<input type="date" class="edit-input" id="' . $id . '" value="' . htmlspecialchars($value) . '"' . ($locked ? ' readonly onclick="openRC(\'' . $fieldName . '\', \'' . addslashes($label) . '\', \'date\', \'' . $id . '\')"' : '') . '>';
                    break;
                default:
                    echo '<input class="edit-input" id="' . $id . '" value="' . htmlspecialchars($value) . '"' . ($locked ? ' readonly onclick="openRC(\'' . $fieldName . '\', \'' . addslashes($label) . '\', \'text\', \'' . $id . '\')"' : '') . '>';
                    break;
            }
            echo '</div>';
        }

        $locked = $sharePaid; // All fields locked when share paid (except phones handled separately)
        ?>

        <!-- ── 1. Personal Information ── -->
        <div class="edit-section">
            <div class="edit-section-header">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Personal Information
            </div>
            <div class="edit-section-body">
                <div class="edit-grid">
                    <?php
                    renderField('f_first_name', 'First Name', 'text', $stu['first_name'], $locked);
                    renderField('f_middle_name', 'Middle Name', 'text', $stu['middle_name'] ?? '', $locked);
                    renderField('f_last_name', 'Last Name', 'text', $stu['last_name'], $locked);
                    renderField('f_gender', 'Gender', 'select', $stu['gender'], $locked, $genderOptions);
                    renderField('f_dob', 'Date of Birth', 'date', $stu['dob'] ?? '', $locked);
                    renderField('f_qualification', 'Qualification', 'select', $stu['qualification'] ?? '', $locked, $qualOptions);
                    renderField('f_father_name', "Father's Name", 'text', $stu['father_name'] ?? '', $locked);
                    renderField('f_mother_name', "Mother's Name", 'text', $stu['mother_name'] ?? '', $locked);
                    renderField('f_category', 'Category', 'select', $stu['category'] ?? '', $locked, $categoryOptions);
                    ?>
                </div>
            </div>
        </div>

        <!-- ── 2. Contact & Address ── -->
        <div class="edit-section">
            <div class="edit-section-header">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                Contact &amp; Address
                <span style="margin-left:auto;font-size:.72rem;font-weight:700;color:#059669;background:#ecfdf5;padding:.2rem .6rem;border-radius:6px;">✅ Always editable</span>
            </div>
            <div class="edit-section-body">
                <div class="edit-grid">
                    <!-- Mobile, address fields are NEVER locked -->
                    <?php
                    renderField('f_mobile', 'Mobile *', 'text', $stu['mobile'], false);
                    renderField('f_phone', 'Alternate Phone', 'text', $stu['phone'] ?? '', false);
                    renderField('f_email', 'Email', 'text', $stu['email'] ?? '', $locked);
                    renderField('f_address', 'Address', 'text', $stu['address'] ?? '', false, [], 'class="edit-field full"');
                    renderField('f_city', 'City', 'text', $stu['city'] ?? '', false);
                    renderField('f_state', 'State', 'text', $stu['state'] ?? '', false);
                    renderField('f_pin_code', 'PIN Code', 'text', $stu['pin_code'] ?? '', false);
                    renderField('f_referenced_by', 'Referenced By', 'text', $stu['referenced_by'] ?? '', $locked);
                    ?>
                </div>
                <?php if ($sharePaid): ?>
                <div style="margin-top:1rem;text-align:right;">
                    <button class="phone-save-btn" onclick="saveContactAndAddress()">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><polyline points="20 6 9 17 4 12"/></svg>
                        Save Mobile &amp; Address
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── 3. Course ── -->
        <div class="edit-section">
            <div class="edit-section-header">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                Course Details
                <span style="margin-left:auto;font-size:.72rem;font-weight:700;color:#dc2626;background:#fef2f2;padding:.2rem .6rem;border-radius:6px;">🚫 Course locked permanently</span>
            </div>
            <div class="edit-section-body">
                <div class="edit-grid">
                    <!-- Course: completely non-editable, shown as read-only label -->
                    <div class="edit-field">
                        <label>Course</label>
                        <input class="edit-input" id="f_course" value="<?= e($stu['course']) ?>" readonly disabled style="background:#f1f5f9;color:#475569;cursor:not-allowed;border-color:#e2e8f0;">
                    </div>
                    <?php
                    renderField('f_uniform_size', 'T-Shirt Size', 'select', $stu['uniform_size'] ?? '', $locked, array_merge([''], $tshirtSizes));
                    renderField('f_material_type', 'Material Type', 'select', $stu['material_type'] ?? '', $locked, $materialTypes);
                    renderField('f_material_language', 'Material Language', 'select', $stu['material_language'] ?? '', $locked, $materialLangs);
                    renderField('f_comment', 'Comments / Notes', 'textarea', $stu['comment'] ?? '', $locked, [], 'class="edit-field' . ($locked ? ' locked' : '') . ' full"');
                    ?>
                </div>
            </div>
        </div>

        <!-- Action bar -->
        <div class="edit-action-bar">
            <?php if (!$sharePaid): ?>
            <button class="inq-btn inq-btn-primary" id="saveAllBtn" onclick="saveAllDirect()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><polyline points="20 6 9 17 4 12"/></svg>
                💾 Save Changes
            </button>
            <?php endif; ?>
            <a href="students.php" class="inq-btn inq-btn-secondary">Cancel</a>
        </div>

    </div><!-- /.page-content -->
</main>
</div>

<!-- ── Request-Change Modal ── -->
<div class="rcm-overlay" id="rcmOverlay">
    <div class="rcm-card">
        <div class="rcm-title">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2" style="width:20px;height:20px"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Request Change
        </div>
        <div class="rcm-label">Field</div>
        <div class="rcm-value-box" id="rcm_field_label"></div>
        <div class="rcm-label">Current Value</div>
        <div class="rcm-value-box" id="rcm_old_value"></div>
        <div class="rcm-label" style="margin-top:.5rem;">New Value <span style="color:#ef4444">*</span></div>
        <div id="rcm_input_wrap" style="margin-top:.35rem;margin-bottom:.75rem;"></div>
        <div class="rcm-label">Reason for Change <span style="color:#ef4444">*</span></div>
        <textarea class="rcm-textarea" id="rcm_reason" rows="3" placeholder="Briefly explain why this change is needed…"></textarea>
        <div class="rcm-footer">
            <button class="inq-btn inq-btn-secondary" onclick="closeRC()">Cancel</button>
            <button class="inq-btn inq-btn-primary" onclick="submitRC()">📤 Submit Request</button>
        </div>
    </div>
</div>

<!-- Toast container -->
<div id="esToastContainer"></div>

<script src="../assets/js/dashboard.js"></script>
<script>
const STUDENT_ID = <?= $stuId ?>;
const SHARE_PAID = <?= $sharePaid ? 'true' : 'false' ?>;
const PHOTO_NEVER_UPLOADED = <?= $photoNeverUploaded ? 'true' : 'false' ?>;

// ── Photo upload ──────────────────────────────────────────────────────────
const photoFileInput   = document.getElementById('photoFileInput');
const photoPreview     = document.getElementById('photoPreview');
const photoPlaceholder = document.getElementById('photoPlaceholder');
const photoFname       = document.getElementById('photoFname');
const photoSaveBtn     = document.getElementById('photoSaveBtn');

if (photoFileInput) {
    photoFileInput.addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;
        if (file.size > 3 * 1024 * 1024) {
            esToast('Photo must be under 3 MB.', 'error');
            this.value = ''; return;
        }
        const reader = new FileReader();
        reader.onload = e => {
            photoPreview.src = e.target.result;
            photoPreview.style.display = 'block';
            if (photoPlaceholder) photoPlaceholder.style.display = 'none';
        };
        reader.readAsDataURL(file);
        photoFname.textContent = file.name;
        photoFname.style.display = 'block';
        if (photoSaveBtn) photoSaveBtn.classList.add('visible');
    });
}

// Share NOT paid → save photo directly
async function submitPhotoDirect() {
    const file = photoFileInput?.files[0];
    if (!file) { esToast('Please choose a photo first.', 'error'); return; }
    const fd = new FormData();
    fd.append('action', 'direct_update');
    fd.append('id', STUDENT_ID);
    fd.append('photo', file);
    // Send current field values so only photo changes
    appendAllFields(fd);

    photoSaveBtn.disabled = true; photoSaveBtn.textContent = 'Saving…';
    const res = await fetch('students.php', { method: 'POST', body: fd });
    const data = await res.json();
    photoSaveBtn.disabled = false;
    photoSaveBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px"><polyline points="20 6 9 17 4 12"/></svg> Save Photo';
    if (data.success) {
        esToast('✅ ' + data.message);
    } else {
        esToast(data.message || 'Failed.', 'error');
    }
}

// Share IS paid → photo change request
async function submitPhotoRequest() {
    const file = photoFileInput?.files[0];
    if (!file) { esToast('Please choose a photo first.', 'error'); return; }
    const fd = new FormData();
    fd.append('action', 'upload_photo_request');
    fd.append('student_id', STUDENT_ID);
    fd.append('photo', file);
    fd.append('reason', 'Photo update requested from Edit Student form.');

    photoSaveBtn.disabled = true; photoSaveBtn.textContent = 'Uploading…';
    const res = await fetch('students.php', { method: 'POST', body: fd });
    const data = await res.json();
    photoSaveBtn.disabled = false;
    photoSaveBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px"><polyline points="20 6 9 17 4 12"/></svg> Request Change';
    if (data.success) {
        esToast('✅ ' + data.message);
        photoFileInput.value = '';
        photoSaveBtn.classList.remove('visible');
    } else {
        esToast(data.message || 'Failed.', 'error');
    }
}

// Share IS paid, first photo upload → direct
async function submitPhotoFirstTime() {
    const file = photoFileInput?.files[0];
    if (!file) { esToast('Please choose a photo first.', 'error'); return; }
    const fd = new FormData();
    fd.append('ajax_upload_photo', '1');
    fd.append('student_id', STUDENT_ID);
    fd.append('photo', file);

    photoSaveBtn.disabled = true; photoSaveBtn.textContent = 'Uploading…';
    try {
        const res = await fetch('hall_tickets.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            esToast('✅ Photo uploaded successfully!');
            setTimeout(() => location.reload(), 1200);
        } else {
            esToast(data.message || 'Failed.', 'error');
            photoSaveBtn.disabled = false;
            photoSaveBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px"><polyline points="20 6 9 17 4 12"/></svg> Upload & Save';
        }
    } catch(e) {
        esToast('Upload failed.', 'error');
        photoSaveBtn.disabled = false;
    }
}

// ── Save All (direct — share NOT paid) ────────────────────────────────────
async function saveAllDirect() {
    const fd = new FormData();
    fd.append('action', 'direct_update');
    fd.append('id', STUDENT_ID);
    appendAllFields(fd);

    // Attach photo if selected
    const file = photoFileInput?.files[0];
    if (file) fd.append('photo', file);

    const btn = document.getElementById('saveAllBtn');
    btn.disabled = true; btn.textContent = 'Saving…';

    const res = await fetch('students.php', { method: 'POST', body: fd });
    const data = await res.json();
    btn.disabled = false;
    btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><polyline points="20 6 9 17 4 12"/></svg> 💾 Save Changes';
    if (data.success) {
        esToast('✅ ' + data.message);
        setTimeout(() => location.href = 'students.php', 1500);
    } else {
        esToast(data.message || 'Failed to save.', 'error');
    }
}

function appendAllFields(fd) {
    const fields = {
        first_name: 'f_first_name', middle_name: 'f_middle_name', last_name: 'f_last_name',
        gender: 'f_gender', dob: 'f_dob', qualification: 'f_qualification',
        father_name: 'f_father_name', mother_name: 'f_mother_name', category: 'f_category',
        mobile: 'f_mobile', phone: 'f_phone', email: 'f_email',
        address: 'f_address', city: 'f_city', state: 'f_state',
        pin_code: 'f_pin_code', referenced_by: 'f_referenced_by',
        course: 'f_course', uniform_size: 'f_uniform_size',
        material_type: 'f_material_type', material_language: 'f_material_language',
        comment: 'f_comment'
    };
    for (const [name, elId] of Object.entries(fields)) {
        const el = document.getElementById(elId);
        if (el) fd.append(name, el.value);
    }
}

// ── Save Mobile & Address (share IS paid — allowed directly) ─────────────
async function saveContactAndAddress() {
    const mobile = document.getElementById('f_mobile')?.value?.trim() || '';
    if (!mobile) { esToast('Mobile number is required.', 'error'); return; }

    const fd = new FormData();
    fd.append('action', 'update_phones');
    fd.append('id', STUDENT_ID);
    fd.append('mobile', mobile);
    fd.append('phone',    document.getElementById('f_phone')?.value?.trim() || '');
    fd.append('address',  document.getElementById('f_address')?.value?.trim() || '');
    fd.append('city',     document.getElementById('f_city')?.value?.trim() || '');
    fd.append('state',    document.getElementById('f_state')?.value?.trim() || '');
    fd.append('pin_code', document.getElementById('f_pin_code')?.value?.trim() || '');

    const res = await fetch('students.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
        esToast('✅ ' + data.message);
    } else {
        esToast(data.message, 'error');
    }
}

// ── Change Request Modal ──────────────────────────────────────────────────
let rcm_field_name = '', rcm_input_el = null;

function openRC(fieldName, fieldLabel, inputType, fieldId, options) {
    rcm_field_name = fieldName;
    const el = document.getElementById(fieldId);
    let oldVal = el ? (el.tagName === 'SELECT' ? el.options[el.selectedIndex]?.text : el.value) : '';

    document.getElementById('rcm_field_label').textContent = fieldLabel;
    document.getElementById('rcm_old_value').textContent   = oldVal || '(empty)';
    document.getElementById('rcm_reason').value = '';

    const wrap = document.getElementById('rcm_input_wrap');
    wrap.innerHTML = '';
    if (inputType === 'select' && options) {
        const sel = document.createElement('select');
        sel.className = 'rcm-select'; sel.id = 'rcm_new_value';
        options.forEach(opt => {
            if (!opt && opt !== 0) return;
            const o = document.createElement('option');
            o.value = opt; o.textContent = opt;
            if (opt === oldVal) o.selected = true;
            sel.appendChild(o);
        });
        wrap.appendChild(sel);
        rcm_input_el = sel;
    } else if (inputType === 'textarea') {
        const ta = document.createElement('textarea');
        ta.className = 'rcm-textarea'; ta.id = 'rcm_new_value';
        ta.rows = 3; ta.value = oldVal;
        wrap.appendChild(ta);
        rcm_input_el = ta;
    } else {
        const inp = document.createElement('input');
        inp.className = 'rcm-input'; inp.id = 'rcm_new_value';
        inp.type = inputType === 'date' ? 'date' : 'text';
        inp.value = el ? el.value : '';
        wrap.appendChild(inp);
        rcm_input_el = inp;
    }

    document.getElementById('rcm_old_value').dataset.rawOld = el ? el.value : '';
    document.getElementById('rcmOverlay').classList.add('active');
    setTimeout(() => rcm_input_el && rcm_input_el.focus(), 80);
}

function closeRC() {
    document.getElementById('rcmOverlay').classList.remove('active');
}

async function submitRC() {
    const fieldLabel = document.getElementById('rcm_field_label').textContent;
    const oldValue   = document.getElementById('rcm_old_value').dataset.rawOld || '';
    const newValue   = rcm_input_el ? rcm_input_el.value.trim() : '';
    const reason     = document.getElementById('rcm_reason').value.trim();

    if (!newValue) { esToast('Please enter a new value.', 'error'); return; }
    if (!reason)   { esToast('Please provide a reason.', 'error'); return; }
    if (newValue === oldValue) { esToast('No change detected.', 'error'); return; }

    const fd = new FormData();
    fd.append('action', 'submit_change_request');
    fd.append('student_id', STUDENT_ID);
    fd.append('field_name', rcm_field_name);
    fd.append('field_label', fieldLabel);
    fd.append('old_value', oldValue);
    fd.append('new_value', newValue);
    fd.append('reason', reason);

    const res = await fetch('students.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
        esToast('✅ ' + data.message);
        closeRC();
    } else {
        esToast(data.message, 'error');
    }
}

// Close on overlay click
document.getElementById('rcmOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeRC();
});

// ── Toast ─────────────────────────────────────────────────────────────────
function esToast(msg, type = 'success') {
    const t  = document.createElement('div');
    const bg = type === 'success' ? '#059669' : '#dc2626';
    t.style.cssText = `background:${bg};color:#fff;padding:.75rem 1.25rem;border-radius:10px;font-size:.875rem;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.18);animation:slideUp .3s ease;max-width:340px`;
    t.textContent = msg;
    document.getElementById('esToastContainer').appendChild(t);
    setTimeout(() => t.remove(), 3500);
}
</script>
</body>
</html>
