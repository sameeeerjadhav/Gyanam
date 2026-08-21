<?php
/**
 * Gyanam Portal — ATC: Convert Inquiry to Admission (Full-Page Form)
 * URL: convert_admission_page.php?inquiry_id=X
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
if (file_exists(__DIR__ . '/../includes/notifications.php')) {
    require_once __DIR__ . '/../includes/notifications.php';
}

requireLogin(['ATC CENTER']);

$pdo    = getDBConnection();
$atcId  = $_SESSION['atc_id'] ?? null;
$inqId  = intval($_GET['inquiry_id'] ?? 0);
$source = $_GET['source'] ?? 'walkin'; // 'walkin' or 'telephonic'
ensureDualMaterialCourseSchema($pdo);

// Load inquiry from the correct table
$inquiry = null;
if ($inqId > 0 && $atcId) {
    if ($source === 'telephonic') {
        $s = $pdo->prepare("
            SELECT id, atc_id, first_name, middle_name, last_name, mobile,
                   interested_course, next_inform_date, status, created_at,
                   '' as email, '' as inquiry_type, '' as qualification,
                   '' as address, '' as city, '' as pin_code, '' as phone,
                   '' as referenced_by, '' as comment, '' as gender, '' as dob,
                   '' as course_fees, 'telephonic' as _source
            FROM telephonic_inquiries WHERE id = ? AND atc_id = ?
        ");
    } else {
        $s = $pdo->prepare("SELECT *, 'walkin' as _source FROM inquiries WHERE id = ? AND atc_id = ?");
    }
    $s->execute([$inqId, $atcId]);
    $inquiry = $s->fetch(PDO::FETCH_ASSOC);
}
if (!$inquiry) {
    header('Location: new_admission.php');
    exit;
}

// Fetch active courses with dual material fees
$courseStmt = $pdo->prepare("
    SELECT c.course_name, c.course_type, c.material_type, c.material_language, c.duration,
           c.ho_share, c.ho_share_with_material, c.ho_share_without_material,
           acf.final_fee AS fees,
           acf.fee_with_material,
           acf.fee_without_material
    FROM courses c
    INNER JOIN atc_course_fees acf ON acf.course_id = c.id AND acf.atc_id = ?
    WHERE c.status = 'Active'
      AND (COALESCE(acf.fee_with_material, 0) > 0
        OR COALESCE(acf.fee_without_material, 0) > 0
        OR COALESCE(acf.final_fee, 0) > 0)
    ORDER BY c.course_name ASC
");
$courseStmt->execute([$atcId]);
$activeCourses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);

$courseSelectOptions = [];
$courseFeesMap = [];
$courseMaterialMap = [];
foreach ($activeCourses as $c) {
    foreach (buildCourseMaterialOptions($c) as $opt) {
        $courseSelectOptions[] = $opt;
        $key = $opt['course_name'] . '|' . $opt['material_type'];
        $courseFeesMap[$key] = $opt['fee'];
        $courseMaterialMap[$opt['course_name']] = [
            'material_type' => $opt['material_type'],
            'course_type'   => $opt['course_type'] ?? ($c['course_type'] ?? ''),
        ];
    }
    // Also keep simple name → type for abacus uniform toggle
    if (!isset($courseMaterialMap[$c['course_name']])) {
        $courseMaterialMap[$c['course_name']] = [
            'material_type' => $c['material_type'] ?? 'Without Material',
            'course_type'   => $c['course_type'] ?? '',
        ];
    } else {
        $courseMaterialMap[$c['course_name']]['course_type'] = $c['course_type'] ?? '';
    }
}

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$fullName = trim(
    ($inquiry['first_name'] ?? '') . ' ' .
    ($inquiry['middle_name'] ? $inquiry['middle_name'] . ' ' : '') .
    ($inquiry['last_name'] ?? '')
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Convert to Admission — <?= e($fullName) ?> | Gyanam India</title>
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<link rel="stylesheet" href="../assets/css/inquiries.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎓</text></svg>">
<style>
/* ── Convert Page Extra Styles ── */
.cvt-page-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
    gap: 1rem;
    flex-wrap: wrap;
}
.cvt-page-title { font-size: 1.25rem; font-weight: 800; color: var(--text-primary); }
.cvt-page-subtitle { font-size: .85rem; color: var(--text-secondary); margin-top: .15rem; }
.cvt-student-banner {
    display: flex; align-items: center; gap: 1rem;
    background: linear-gradient(135deg, var(--primary-50), #ede9fe);
    border: 1.5px solid var(--primary-200);
    border-radius: 14px; padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
}
.cvt-student-avatar {
    width: 52px; height: 52px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg, var(--primary-500), #7c3aed);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem; font-weight: 800; color: #fff;
}
.cvt-student-name { font-size: 1.1rem; font-weight: 700; color: var(--text-primary); }
.cvt-student-meta { font-size: .8rem; color: var(--text-secondary); margin-top: .2rem; }

/* Form Sections */
.conv-section {
    background: #fff;
    border: 1.5px solid var(--border-color);
    border-radius: 14px;
    margin-bottom: 1.25rem;
    overflow: hidden;
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
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 1rem;
}
.conv-grid .full { grid-column: 1 / -1; }
.conv-field label {
    display: block; font-size: .8rem; font-weight: 600;
    color: var(--text-secondary); margin-bottom: .35rem;
}
.conv-field label .req { color: #ef4444; }
.conv-input, .conv-select, .conv-textarea {
    width: 100%; padding: .6rem .85rem;
    border: 1.5px solid var(--border-color);
    border-radius: 8px; font-size: .875rem;
    background: #fff; color: var(--text-primary);
    transition: border-color .2s, box-shadow .2s;
    font-family: inherit;
}
.conv-input:focus, .conv-select:focus, .conv-textarea:focus {
    outline: none; border-color: var(--primary-400);
    box-shadow: 0 0 0 3px var(--primary-100);
}
.conv-input[readonly] { background: var(--gray-50); cursor: not-allowed; font-weight: 700; color: var(--primary-700); }
.conv-textarea { resize: vertical; min-height: 80px; }

/* Fee Summary card */
.fee-summary-card {
    background: linear-gradient(135deg, #ecfdf5, #d1fae5);
    border: 1.5px solid #6ee7b7;
    border-radius: 10px; padding: .85rem 1rem;
    display: flex; gap: 2rem; flex-wrap: wrap;
    margin-top: .75rem;
}
.fee-summary-item label { font-size: .75rem; font-weight: 600; color: #065f46; }
.fee-summary-item span { font-size: 1rem; font-weight: 800; color: #047857; display: block; }

/* Personal Info: fields + photo side by side */
.personal-info-layout {
    display: flex;
    gap: 1.5rem;
    align-items: flex-start;
}
.personal-info-fields { flex: 1; min-width: 0; }
.personal-info-photo {
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: .5rem;
}
.personal-info-photo .photo-label-text {
    font-size: .78rem;
    font-weight: 700;
    color: var(--text-secondary, #6b7280);
    text-align: center;
}
@media (max-width: 640px) {
    .personal-info-layout {
        flex-direction: column-reverse;
        align-items: stretch;
    }
    .personal-info-photo {
        flex-direction: row;
        gap: 1rem;
        align-items: center;
    }
}

/* Photo Upload */
.photo-uploader {
    width: 130px; height: 160px;
    border: 2.5px dashed #c7d2fe;
    border-radius: 14px; cursor: pointer;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    gap: .5rem; color: #9ca3af;
    font-size: .72rem; text-align: center;
    transition: border-color .25s, background .25s, box-shadow .25s;
    overflow: hidden; position: relative;
    background: linear-gradient(135deg, #fafbff, #f0f2ff);
}
.photo-uploader:hover {
    border-color: #818cf8;
    background: linear-gradient(135deg, #eef2ff, #e8e0ff);
    box-shadow: 0 4px 16px rgba(67,97,238,.12);
}
.photo-uploader img {
    width: 100%; height: 100%; object-fit: cover; position: absolute; inset: 0;
    border-radius: 12px;
}
.photo-uploader svg { width: 32px; height: 32px; color: #a5b4fc; }
.photo-uploader .photo-hint {
    font-size: .65rem; color: #9ca3af; font-weight: 600;
    line-height: 1.3;
}

/* Submit bar */
.conv-submit-bar {
    display: flex; gap: .75rem; justify-content: flex-end;
    align-items: center; padding-top: .75rem;
}

/* Spinner */
.spinner { display: none; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.4); border-top-color: #fff; border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
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
                <h2>Convert to Admission</h2>
                <p>Fill in the full admission details for this student</p>
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
                <div class="cvt-page-title">Convert Inquiry → Admission</div>
                <div class="cvt-page-subtitle">Inquiry #<?= $inqId ?> · <?= e($inquiry['interested_course']) ?></div>
            </div>
            <a href="new_admission.php" class="inq-btn inq-btn-secondary">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><polyline points="15 18 9 12 15 6"/></svg>
                Back to Admissions
            </a>
        </div>

        <!-- Student banner -->
        <div class="cvt-student-banner">
            <div class="cvt-student-avatar"><?= strtoupper(mb_substr($inquiry['first_name'], 0, 1)) ?></div>
            <div>
                <div class="cvt-student-name"><?= e($fullName) ?></div>
                <div class="cvt-student-meta">
                    📱 <?= e($inquiry['mobile']) ?> &nbsp;·&nbsp;
                    📚 <?= e($inquiry['interested_course']) ?> &nbsp;·&nbsp;
                    🏙 <?= e($inquiry['city']) ?> &nbsp;·&nbsp;
                    <span style="color:#10b981;font-weight:700;">Ready to convert</span>
                </div>
            </div>
        </div>

        <!-- Success alert placeholder -->
        <div id="successAlert" style="display:none;margin-bottom:1rem;padding:.9rem 1rem;border-radius:10px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-weight:600;"></div>
        <div id="errorAlert"   style="display:none;margin-bottom:1rem;padding:.9rem 1rem;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-weight:600;"></div>

        <!-- THE FORM -->
        <form id="convertForm" novalidate enctype="multipart/form-data">
            <input type="hidden" name="action"          value="convert_to_admission">
            <input type="hidden" name="inquiry_id"      value="<?= $inqId ?>">
            <input type="hidden" name="inquiry_source"  value="<?= e($source) ?>">

            <!-- ── 1. Personal Information ── -->
            <div class="conv-section">
                <div class="conv-section-header">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Personal Information
                </div>
                <div class="conv-section-body">
                    <div class="personal-info-layout">
                        <!-- Left: Form fields -->
                        <div class="personal-info-fields">
                            <div class="conv-grid">
                                <div class="conv-field">
                                    <label>First Name <span class="req">*</span></label>
                                    <input type="text" class="conv-input" id="cvt_first_name" name="cvt_first_name" required maxlength="60" value="<?= e($inquiry['first_name']) ?>">
                                </div>
                                <div class="conv-field">
                                    <label>Middle Name</label>
                                    <input type="text" class="conv-input" id="cvt_middle_name" name="cvt_middle_name" maxlength="60" value="<?= e($inquiry['middle_name'] ?? '') ?>">
                                </div>
                                <div class="conv-field">
                                    <label>Last Name <span class="req">*</span></label>
                                    <input type="text" class="conv-input" id="cvt_last_name" name="cvt_last_name" required maxlength="60" value="<?= e($inquiry['last_name']) ?>">
                                </div>
                                <div class="conv-field">
                                    <label>Gender <span class="req">*</span></label>
                                    <select class="conv-select" id="cvt_gender" name="cvt_gender" required>
                                        <option value="">— Select —</option>
                                        <option value="Male"   <?= ($inquiry['gender'] ?? '') === 'Male'   ? 'selected' : '' ?>>Male</option>
                                        <option value="Female" <?= ($inquiry['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                                        <option value="Other"  <?= ($inquiry['gender'] ?? '') === 'Other'  ? 'selected' : '' ?>>Other</option>
                                    </select>
                                </div>
                                <div class="conv-field">
                                    <label>Date of Birth <span class="req">*</span></label>
                                    <input type="date" class="conv-input" id="cvt_dob" name="cvt_dob" required value="<?= e($inquiry['dob'] ?? '') ?>">
                                </div>
                                <div class="conv-field">
                                    <label>Qualification <span class="req">*</span></label>
                                    <input type="text" class="conv-input" id="cvt_qualification" name="cvt_qualification" required maxlength="100" value="<?= e($inquiry['qualification'] ?? '') ?>" placeholder="e.g. 10th, HSC, Graduate">
                                </div>
                                <div class="conv-field">
                                    <label>Father's Name</label>
                                    <input type="text" class="conv-input" name="father_name" maxlength="100" placeholder="Optional">
                                </div>
                                <div class="conv-field">
                                    <label>Mother's Name</label>
                                    <input type="text" class="conv-input" name="mother_name" maxlength="100" placeholder="Optional">
                                </div>
                                <div class="conv-field">
                                    <label>Category</label>
                                    <select class="conv-select" name="category">
                                        <option value="General" selected>General</option>
                                        <option value="OBC">OBC</option>
                                        <option value="SC">SC</option>
                                        <option value="ST">ST</option>
                                        <option value="NT">NT</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Photo upload -->
                        <div class="personal-info-photo">
                            <div class="photo-label-text">Student Photo</div>
                            <label class="photo-uploader" id="photoLabel">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="9" r="3.5"/><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 18a8 8 0 0 1-6.25-3 6 6 0 0 1 12.5 0A8 8 0 0 1 12 20z"/></svg>
                                <span id="photoPlaceholderText">Click to<br>upload photo</span>
                                <span class="photo-hint">JPG/PNG · Max 2MB</span>
                                <img id="photoPreview" style="display:none;" alt="preview">
                                <input type="file" name="student_photo" id="student_photo" accept="image/*" style="display:none;" onchange="previewPhoto(this)">
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── 2. Contact & Address ── -->
            <div class="conv-section">
                <div class="conv-section-header">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    Contact & Address
                </div>
                <div class="conv-section-body">
                    <div class="conv-grid">
                        <div class="conv-field">
                            <label>Mobile <span class="req">*</span></label>
                            <input type="tel" class="conv-input" id="cvt_mobile" name="cvt_mobile" required maxlength="10" value="<?= e($inquiry['mobile']) ?>" placeholder="10-digit mobile">
                        </div>
                        <div class="conv-field">
                            <label>Alternate Phone</label>
                            <input type="tel" class="conv-input" name="phone" maxlength="15" value="<?= e($inquiry['phone'] ?? '') ?>">
                        </div>
                        <div class="conv-field">
                            <label>Email</label>
                            <input type="email" class="conv-input" name="email" value="<?= e($inquiry['email'] ?? '') ?>">
                        </div>
                        <div class="conv-field full">
                            <label>Address <span class="req">*</span></label>
                            <input type="text" class="conv-input" id="cvt_address" name="cvt_address" required maxlength="255" value="<?= e($inquiry['address'] ?? '') ?>" placeholder="Street / Area">
                        </div>
                        <div class="conv-field">
                            <label>City <span class="req">*</span></label>
                            <input type="text" class="conv-input" id="cvt_city" name="cvt_city" required maxlength="80" value="<?= e($inquiry['city'] ?? '') ?>">
                        </div>
                        <div class="conv-field">
                            <label>State</label>
                            <input type="text" class="conv-input" name="cvt_state" maxlength="80" placeholder="e.g. Maharashtra">
                        </div>
                        <div class="conv-field">
                            <label>PIN Code <span class="req">*</span></label>
                            <input type="text" class="conv-input" id="cvt_pin_code" name="cvt_pin_code" required maxlength="6" value="<?= e($inquiry['pin_code'] ?? '') ?>" placeholder="6-digit PIN">
                        </div>
                        <div class="conv-field">
                            <label>Referenced By</label>
                            <input type="text" class="conv-input" name="referenced_by" value="<?= e($inquiry['referenced_by'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── 3. Course & Fee ── -->
            <div class="conv-section">
                <div class="conv-section-header">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    Course & Fee Details
                </div>
                <div class="conv-section-body">
                    <div class="conv-grid">
                        <div class="conv-field">
                            <label>Course <span class="req">*</span></label>
                            <select class="conv-select" id="convert_course" name="course" required onchange="onCourseChange()">
                                <option value="">— Select Course —</option>
                                <?php foreach ($courseSelectOptions as $opt): ?>
                                <option value="<?= e($opt['course_name']) ?>"
                                    data-fees="<?= $opt['fee'] ?>"
                                    data-material="<?= e($opt['material_type']) ?>"
                                    data-language="<?= e($opt['language'] ?? 'English') ?>"
                                    data-ctype="<?= e($opt['course_type'] ?? '') ?>"
                                    <?= ($opt['course_name'] === ($inquiry['interested_course'] ?? '') && $opt['material_type'] === 'Without Material') ? 'selected' : '' ?>>
                                    <?= e($opt['label']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div style="font-size:.72rem;color:#9ca3af;margin-top:.25rem">Only options with HO share &amp; your fee set appear</div>
                        </div>
                        <div class="conv-field">
                            <label>Admission Date <span class="req">*</span></label>
                            <input type="date" class="conv-input" id="convert_admission_date" name="admission_date" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="conv-field">
                            <label>Course Fees (₹) <span class="req">*</span></label>
                            <input type="number" class="conv-input" id="convert_course_fees" name="course_fees" required min="0" step="1" placeholder="e.g. 8000" oninput="calcFees()" value="<?= e($inquiry['course_fees'] ?? 0) ?>">
                        </div>
                        <div class="conv-field">
                            <label>Discount (₹)</label>
                            <input type="number" class="conv-input" id="convert_discount_amount" name="discount_amount" min="0" step="1" value="0" oninput="calcFees()">
                        </div>
                        <div class="conv-field">
                            <label>Discount Reason</label>
                            <input type="text" class="conv-input" name="discount_reason" maxlength="255" placeholder="e.g. Scholarship">
                        </div>
                        <div class="conv-field">
                            <label>No. of Installments</label>
                            <select class="conv-select" name="installments">
                                <option value="1">1 (Full Payment)</option>
                                <option value="2">2 Installments</option>
                                <option value="3">3 Installments</option>
                                <option value="4">4 Installments</option>
                                <option value="6">6 Installments</option>
                                <option value="12">12 Monthly</option>
                            </select>
                        </div>
                        <div class="conv-field">
                            <label>Net Payable (₹)</label>
                            <input type="number" class="conv-input" id="convert_net_payable" name="net_payable" readonly placeholder="Auto-calculated">
                        </div>
                    </div>
                    <!-- Fee Summary -->
                    <div class="fee-summary-card" id="feeSummaryCard" style="display:none;">
                        <div class="fee-summary-item"><label>Course Fees</label><span id="fs_course">₹0</span></div>
                        <div class="fee-summary-item"><label>Discount</label><span id="fs_discount">— ₹0</span></div>
                        <div class="fee-summary-item"><label>Net Payable</label><span id="fs_net">₹0</span></div>
                    </div>
                </div>
            </div>

            <!-- ── 4. Material & Notes ── -->
            <div class="conv-section">
                <div class="conv-section-header">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    Material & Notes
                </div>
                <div class="conv-section-body">
                    <div class="conv-grid">
                        <!-- Material type comes from selected course variant -->
                        <div class="conv-field">
                            <label>Material</label>
                            <input type="hidden" name="material_type" id="material_type_hidden" value="Without Material">
                            <input type="text" class="conv-input" id="material_type_display" value="Without Material" readonly
                                   style="background:#f3f4f6;cursor:not-allowed;font-weight:700;color:#6b7280">
                            <div style="font-size:.72rem;color:#9ca3af;margin-top:.25rem">Taken from the course option you selected above</div>
                        </div>

                        <!-- Material Language: only visible for "With Material" courses -->
                        <div class="conv-field" id="materialLangField" style="display:none">
                            <label>Material Language <span class="req">*</span></label>
                            <select class="conv-select" name="material_language" id="convert_material_language">
                                <option value="English">English</option>
                                <option value="Marathi">Marathi</option>
                            </select>
                        </div>

                        <!-- T-Shirt Size: only visible for courses with material -->
                        <div class="conv-field" id="tshirtField" style="display:none">
                            <label>T-Shirt / Uniform Size <span class="req">*</span></label>
                            <select class="conv-select" name="uniform_size" id="convert_uniform_size">
                                <option value="">— Select Size —</option>
                                <option value="36">36</option>
                                <option value="38">38</option>
                                <option value="40">40</option>
                                <option value="42">42</option>
                                <option value="44">44</option>
                                <option value="46">46</option>
                            </select>
                        </div>

                        <div class="conv-field full">
                            <label>Comments / Notes</label>
                            <textarea class="conv-textarea" name="comment" placeholder="Any additional notes…"><?= e($inquiry['comment'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit -->
            <div class="conv-submit-bar">
                <a href="new_admission.php" class="inq-btn inq-btn-secondary">Cancel</a>
                <button type="submit" class="inq-btn inq-btn-primary" id="convertSubmitBtn">
                    <span class="spinner" id="submitSpinner"></span>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px" id="submitIcon"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
                    <span id="convertSubmitBtnText">Convert to Admission</span>
                </button>
            </div>
        </form>

    </div><!-- /.page-content -->
</main>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
const COURSE_FEES_MAP = <?= json_encode($courseFeesMap, JSON_UNESCAPED_UNICODE) ?>;
const COURSE_MATERIAL_MAP = <?= json_encode($courseMaterialMap, JSON_UNESCAPED_UNICODE) ?>;

// ── Master handler when course changes ─────────────────────────────────────
function onCourseChange() {
    const sel = document.getElementById('convert_course');
    const opt = sel.options[sel.selectedIndex];
    const courseName = sel.value;

    // 1️⃣ Auto-fill course fees (always overwrite from master data)
    const feesInput = document.getElementById('convert_course_fees');
    if (opt && opt.dataset.fees) {
        feesInput.value = parseFloat(opt.dataset.fees) || 0;
    } else {
        feesInput.value = '';
    }

    // 2️⃣ Material type from selected With/Without dropdown option
    const material = (opt && opt.dataset.material) ? opt.dataset.material : 'Without Material';
    const language = (opt && opt.dataset.language) ? opt.dataset.language : 'English';
    const hasMaterial = (material === 'With Material');
    const ctype = (opt && opt.dataset.ctype) ? opt.dataset.ctype : ((COURSE_MATERIAL_MAP[courseName] || {}).course_type || '');

    document.getElementById('material_type_hidden').value  = material;
    document.getElementById('material_type_display').value = material;
    document.getElementById('material_type_display').style.color = hasMaterial ? '#059669' : '#6b7280';

    // 3️⃣ Show/hide material language field
    document.getElementById('materialLangField').style.display = hasMaterial ? '' : 'none';
    const langSel = document.getElementById('convert_material_language');
    if (langSel && language) langSel.value = language;

    // 4️⃣ Show/hide T-shirt field (With Material or Abacus)
    const showShirt = hasMaterial || (ctype || '').toLowerCase().includes('abacus') || courseName.toLowerCase().includes('abacus');
    document.getElementById('tshirtField').style.display = showShirt ? '' : 'none';
    if (!showShirt) {
        document.getElementById('convert_uniform_size').value = '';
    }

    // 5️⃣ Recalculate fees
    calcFees();
}

// ── Fee calculation ────────────────────────────────────────────────────────
function calcFees() {
    const courseFees = parseFloat(document.getElementById('convert_course_fees').value) || 0;
    const discount   = parseFloat(document.getElementById('convert_discount_amount').value) || 0;
    const net        = Math.max(0, courseFees - discount);

    const netField = document.getElementById('convert_net_payable');
    if (netField) netField.value = net > 0 ? net.toFixed(2) : '';

    const card = document.getElementById('feeSummaryCard');
    if (courseFees > 0) {
        card.style.display = 'flex';
        document.getElementById('fs_course').textContent   = '₹' + courseFees.toFixed(0);
        document.getElementById('fs_discount').textContent = '— ₹' + discount.toFixed(0);
        document.getElementById('fs_net').textContent      = '₹' + net.toFixed(0);
    } else {
        card.style.display = 'none';
    }
}

// Photo preview
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            const img = document.getElementById('photoPreview');
            const txt = document.getElementById('photoPlaceholderText');
            img.src = e.target.result;
            img.style.display = 'block';
            if (txt) txt.style.display = 'none';
            document.querySelector('#photoLabel svg').style.display = 'none';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Click photo label to trigger file input
document.getElementById('photoLabel').addEventListener('click', function(e) {
    if (e.target !== document.getElementById('student_photo')) {
        document.getElementById('student_photo').click();
    }
});

// Init on page load — trigger course change to set everything
window.addEventListener('DOMContentLoaded', onCourseChange);

// Form submission
document.getElementById('convertForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    // Validation
    const required = [
        { id: 'cvt_first_name',          label: 'First Name' },
        { id: 'cvt_last_name',           label: 'Last Name' },
        { id: 'cvt_gender',              label: 'Gender' },
        { id: 'cvt_dob',                 label: 'Date of Birth' },
        { id: 'cvt_qualification',       label: 'Qualification' },
        { id: 'cvt_mobile',              label: 'Mobile' },
        { id: 'cvt_address',             label: 'Address' },
        { id: 'cvt_city',                label: 'City' },
        { id: 'cvt_pin_code',            label: 'PIN Code' },
        { id: 'convert_course',          label: 'Course' },
        { id: 'convert_admission_date',  label: 'Admission Date' },
        { id: 'convert_course_fees',     label: 'Course Fees' },
    ];

    // Dynamically require material fields if course has material
    const selOpt = document.getElementById('convert_course').options[document.getElementById('convert_course').selectedIndex];
    const hasMat = selOpt && selOpt.dataset.material === 'With Material';
    if (hasMat) {
        required.push({ id: 'convert_material_language', label: 'Material Language' });
        required.push({ id: 'convert_uniform_size', label: 'T-Shirt Size' });
    }

    let missing = [];
    required.forEach(f => {
        const el = document.getElementById(f.id);
        if (!el || !el.value.trim()) {
            missing.push(f.label);
            if (el) el.style.borderColor = '#ef4444';
        } else {
            if (el) el.style.borderColor = '';
        }
    });

    const mob = document.getElementById('cvt_mobile');
    if (mob && mob.value && !/^\d{10}$/.test(mob.value.trim())) {
        missing.push('Mobile (must be 10 digits)');
        mob.style.borderColor = '#ef4444';
    }
    const pin = document.getElementById('cvt_pin_code');
    if (pin && pin.value && !/^\d{6}$/.test(pin.value.trim())) {
        missing.push('PIN Code (must be 6 digits)');
        pin.style.borderColor = '#ef4444';
    }

    if (missing.length > 0) {
        showError('Please fill required fields:\n• ' + missing.join('\n• '));
        return;
    }

    // Submit
    const btn  = document.getElementById('convertSubmitBtn');
    const spin = document.getElementById('submitSpinner');
    const icon = document.getElementById('submitIcon');
    const txt  = document.getElementById('convertSubmitBtnText');
    btn.disabled  = true;
    spin.style.display = 'inline-block';
    icon.style.display = 'none';
    txt.textContent    = 'Converting…';

    try {
        const formData = new FormData(this);
        const response = await fetch('new_admission.php', { method: 'POST', body: formData });
        const rawText  = await response.text(); // Read as text first to catch PHP errors

        let result;
        try {
            result = JSON.parse(rawText);
        } catch (parseErr) {
            // JSON parse failed — show the raw server output (strip HTML tags)
            const preview = rawText.replace(/<[^>]+>/g, '').trim().substring(0, 400);
            showError('Server returned an invalid response. Details: ' + (preview || 'No details. Check server error logs.'));
            btn.disabled = false; spin.style.display = 'none'; icon.style.display = '';
            txt.textContent = 'Convert to Admission';
            return;
        }

        if (result.success) {
            if (result.admission_id) {
                window.open('admission_form_pdf.php?admission_id=' + encodeURIComponent(result.admission_id) + '&auto=1', '_blank');
            }
            showSuccess('✅ Student converted successfully! Roll No: ' + (result.roll_no || ''));
            setTimeout(() => { window.location.href = 'new_admission.php'; }, 2200);
        } else {
            showError(result.message || 'Error converting to admission.');
            btn.disabled = false;
            spin.style.display = 'none';
            icon.style.display = '';
            txt.textContent    = 'Convert to Admission';
        }
    } catch (err) {
        showError('Network error. Could not reach server. Please check your internet and try again.');
        btn.disabled = false;
        spin.style.display = 'none';
        icon.style.display = '';
        txt.textContent    = 'Convert to Admission';
    }
});

function showSuccess(msg) {
    const el = document.getElementById('successAlert');
    el.textContent = msg;
    el.style.display = 'block';
    document.getElementById('errorAlert').style.display = 'none';
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
function showError(msg) {
    const el = document.getElementById('errorAlert');
    el.textContent = msg;
    el.style.display = 'block';
    document.getElementById('successAlert').style.display = 'none';
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
</script>
</body>
</html>
