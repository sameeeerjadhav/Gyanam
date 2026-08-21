<?php
/**
 * Gyanam Portal — ATC: Re-Admission (Existing Student → New Course)
 * Search for an existing student, pre-fill their details, pick a new course & create a fresh admission row.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ob_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
if (file_exists(__DIR__ . '/../includes/notifications.php')) {
    require_once __DIR__ . '/../includes/notifications.php';
}
requireLogin(['ATC CENTER']);

$pdo    = getDBConnection();
$atcId  = $_SESSION['atc_id'] ?? null;
$userName = sanitize(getUserName());
ensureDualMaterialCourseSchema($pdo);

// Auto-migration: ensure ho_share_snapshot column exists
try { $pdo->exec("ALTER TABLE admissions ADD COLUMN IF NOT EXISTS ho_share_snapshot DECIMAL(10,2) DEFAULT NULL"); } catch (Exception $e) {}

// getHoShareForCourse() is in includes/functions.php (material-aware)

// ── AJAX handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        switch ($_POST['action']) {

            // Search existing students
            case 'search_student':
                $q = trim($_POST['q'] ?? '');
                if (strlen($q) < 2) { echo json_encode(['success'=>true,'data'=>[]]); exit; }
                $like = "%$q%";
                $stmt = $pdo->prepare("
                    SELECT id, roll_no, registration_id, first_name, middle_name, last_name,
                           mobile, course, status, photo, gender, dob, qualification,
                           father_name, mother_name, category,
                           email, phone, address, state, city, pin_code,
                           uniform_size, referenced_by, comment
                    FROM admissions
                    WHERE atc_id = ?
                      AND (first_name LIKE ? OR last_name LIKE ? OR roll_no LIKE ?
                           OR registration_id LIKE ? OR mobile LIKE ?)
                    ORDER BY first_name ASC
                    LIMIT 15
                ");
                $stmt->execute([$atcId, $like, $like, $like, $like, $like]);
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
                exit;

            // Fetch existing courses for a student (by registration_id)
            case 'get_enrolled_courses':
                $regId = trim($_POST['registration_id'] ?? '');
                if (!$regId) { echo json_encode(['success'=>true,'courses'=>[]]); exit; }
                $cs = $pdo->prepare("SELECT DISTINCT course FROM admissions WHERE registration_id = ? AND atc_id = ?");
                $cs->execute([$regId, $atcId]);
                echo json_encode(['success'=>true,'courses'=>$cs->fetchAll(PDO::FETCH_COLUMN)]);
                exit;

            // Create re-admission
            case 're_admit':
                $sourceId = intval($_POST['source_id'] ?? 0);
                if (!$sourceId) { echo json_encode(['success'=>false,'message'=>'Select a student first.']); exit; }

                // Fetch source student
                $src = $pdo->prepare("SELECT * FROM admissions WHERE id = ? AND atc_id = ?");
                $src->execute([$sourceId, $atcId]);
                $srcRow = $src->fetch(PDO::FETCH_ASSOC);
                if (!$srcRow) { echo json_encode(['success'=>false,'message'=>'Source student not found.']); exit; }

                // Check course not same
                $newCourse = trim($_POST['course'] ?? '');
                if (!$newCourse) { echo json_encode(['success'=>false,'message'=>'Please select a course.']); exit; }

                // Prevent duplicate course for the same student
                $dupChk = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE registration_id = ? AND atc_id = ? AND course = ?");
                $dupChk->execute([$srcRow['registration_id'], $atcId, $newCourse]);
                if ($dupChk->fetchColumn() > 0) {
                    echo json_encode(['success'=>false,'message'=>'This student is already enrolled in "'.$newCourse.'". Please choose a different course.']);
                    exit;
                }

                $courseFees     = floatval($_POST['course_fees'] ?? 0);
                $discountAmount = floatval($_POST['discount_amount'] ?? 0);
                $netPayable     = max(0, $courseFees - $discountAmount);

                // Reuse the SAME roll_no & registration_id from the source student
                $rollNo         = $srcRow['roll_no'];
                $registrationId = $srcRow['registration_id'];

                // Drop unique constraints if they exist (same student can have multiple courses)
                try {
                    $idxCheck = $pdo->query("SHOW INDEX FROM admissions WHERE Key_name = 'uk_roll_no'")->fetch();
                    if ($idxCheck) {
                        $pdo->exec("ALTER TABLE admissions DROP INDEX uk_roll_no");
                    }
                    $idxCheck2 = $pdo->query("SHOW INDEX FROM admissions WHERE Key_name = 'uq_registration_id'")->fetch();
                    if ($idxCheck2) {
                        $pdo->exec("ALTER TABLE admissions DROP INDEX uq_registration_id");
                    }
                } catch (Exception $e) { /* already dropped or doesn't exist */ }

                $matTypeRA = $_POST['material_type'] ?? 'Without Material';
                $hoShareSnapshotRA  = getHoShareForCourse($pdo, $newCourse, $matTypeRA);
                $dlcShareSnapshotRA = getDlcShareForCourse($pdo, $newCourse, $matTypeRA);

                $stmt = $pdo->prepare("
                    INSERT INTO admissions (
                        atc_id, roll_no, registration_id,
                        first_name, middle_name, last_name, gender, dob,
                        category, father_name, mother_name,
                        qualification, course, uniform_size,
                        address, state, pin_code, city,
                        mobile, phone, email, referenced_by, comment,
                        admission_date, status,
                        course_fees, discount_amount, discount_reason, installments,
                        net_payable, fees_total, fees_pending,
                        material_type, material_language, photo, ho_share_snapshot, dlc_share_snapshot
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([
                    $atcId,
                    $rollNo,
                    $registrationId,
                    $srcRow['first_name'],
                    $srcRow['middle_name'],
                    $srcRow['last_name'],
                    $_POST['gender']        ?? $srcRow['gender'],
                    $_POST['dob']           ?? $srcRow['dob'],
                    $_POST['category']      ?? $srcRow['category'] ?? 'General',
                    $_POST['father_name']   ?? $srcRow['father_name'] ?? '',
                    $_POST['mother_name']   ?? $srcRow['mother_name'] ?? '',
                    $_POST['qualification'] ?? $srcRow['qualification'],
                    $newCourse,
                    $_POST['uniform_size']  ?? $srcRow['uniform_size'],
                    $_POST['address']       ?? $srcRow['address'],
                    $_POST['state']         ?? $srcRow['state'] ?? '',
                    $_POST['pin_code']      ?? $srcRow['pin_code'],
                    $_POST['city']          ?? $srcRow['city'],
                    $_POST['mobile']        ?? $srcRow['mobile'],
                    $_POST['phone']         ?? $srcRow['phone'],
                    $_POST['email']         ?? $srcRow['email'],
                    'Re-Admission: added course ' . $newCourse,
                    $_POST['comment']       ?? '',
                    date('Y-m-d'),
                    'Active',
                    $courseFees,
                    $discountAmount,
                    $_POST['discount_reason'] ?? null,
                    intval($_POST['installments'] ?? 1),
                    $netPayable,
                    $netPayable,
                    $netPayable,
                    $matTypeRA,
                    $_POST['material_language'] ?? 'English',
                    $srcRow['photo'],
                    $hoShareSnapshotRA,
                    $dlcShareSnapshotRA,
                ]);

                $newAdmId = (int)$pdo->lastInsertId();

                // Update student count
                $pdo->prepare("UPDATE atc_centers SET student_count = student_count + 1 WHERE id = ?")
                    ->execute([$atcId]);

                echo json_encode([
                    'success'         => true,
                    'message'         => 'Course added successfully! Roll No: ' . $rollNo . ' | Reg ID: ' . $registrationId . ' | New Course: ' . $newCourse,
                    'admission_id'    => $newAdmId,
                    'roll_no'         => $rollNo,
                    'registration_id' => $registrationId,
                ]);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        exit;
    }
}

// ── Load courses for dropdown ──────────────────────────────────────────────
$coursesStmt = $pdo->prepare("
    SELECT c.id, c.course_name, c.course_type, c.duration,
           c.material_type, c.material_language,
           c.ho_share, c.ho_share_with_material, c.ho_share_without_material,
           acf.final_fee AS fees,
           acf.fee_with_material,
           acf.fee_without_material
    FROM   courses c
    INNER JOIN atc_course_fees acf ON acf.course_id = c.id AND acf.atc_id = ?
    WHERE  c.status = 'Active'
      AND  (COALESCE(acf.fee_with_material, 0) > 0
         OR COALESCE(acf.fee_without_material, 0) > 0
         OR COALESCE(acf.final_fee, 0) > 0)
    ORDER BY c.course_name ASC
");
$coursesStmt->execute([$atcId]);
$atcCourses = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);

$courseSelectOptions = [];
foreach ($atcCourses as $ac) {
    foreach (buildCourseMaterialOptions($ac) as $opt) {
        $courseSelectOptions[] = $opt;
    }
}

function e_ra($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Re-Admission — ATC Center | Gyanam India</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔄</text></svg>">
<style>
:root { --font:'Sora',sans-serif; --mono:'JetBrains Mono',monospace; }
.page-content { padding:1.75rem 2rem; width:100%; box-sizing:border-box; }

/* ── Page header ── */
.ra-header { display:flex;align-items:center;gap:1rem;margin-bottom:1.75rem; }
.ra-icon { width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#4361ee,#7c3aed);display:flex;align-items:center;justify-content:center;box-shadow:0 6px 20px rgba(67,97,238,.22);flex-shrink:0; }
.ra-icon svg { width:24px;height:24px;stroke:#fff;fill:none; }
.ra-title { font-size:1.35rem;font-weight:800;color:#1f2937;letter-spacing:-.03em; }
.ra-subtitle { font-size:.82rem;color:#6b7280;margin-top:.15rem; }

/* ── Steps indicator ── */
.ra-steps { display:flex;gap:.5rem;margin-bottom:1.75rem; }
.ra-step { display:flex;align-items:center;gap:.5rem;padding:.65rem 1.25rem;border-radius:12px;font-size:.82rem;font-weight:700;transition:all .2s; }
.ra-step .step-num { width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800; }
.ra-step.inactive { background:#f9fafb;border:1.5px solid #e5e7eb;color:#9ca3af; }
.ra-step.inactive .step-num { background:#e5e7eb;color:#9ca3af; }
.ra-step.active { background:linear-gradient(135deg,#4361ee,#3730a3);color:#fff;box-shadow:0 4px 12px rgba(67,97,238,.25); }
.ra-step.active .step-num { background:rgba(255,255,255,.22);color:#fff; }
.ra-step.done { background:#ecfdf5;border:1.5px solid #6ee7b7;color:#065f46; }
.ra-step.done .step-num { background:#6ee7b7;color:#065f46; }

/* ── Search panel ── */
.ra-panel { background:#fff;border:1.5px solid #e5e7eb;border-radius:16px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.04);margin-bottom:1.5rem; }
.ra-panel-head { display:flex;align-items:center;gap:.625rem;padding:1rem 1.25rem;background:linear-gradient(135deg,#f8fafc,#eef1fd);border-bottom:1px solid #e5e7eb;font-weight:700;font-size:.9rem;color:#1f2937; }
.ra-panel-head svg { width:18px;height:18px;color:#4361ee; }
.ra-panel-body { padding:1.25rem; }

/* Search */
.ra-search-wrap { position:relative;display:flex;align-items:center;gap:.75rem; }
.ra-search-wrap input { flex:1;padding:.75rem 1rem .75rem 2.75rem;border:1.5px solid #e5e7eb;border-radius:10px;font-size:.9rem;font-family:var(--font);outline:none;color:#1f2937;transition:border-color .2s; }
.ra-search-wrap input:focus { border-color:#4361ee;box-shadow:0 0 0 3px rgba(67,97,238,.08); }
.ra-search-wrap svg.search-ico { position:absolute;left:.9rem;width:18px;height:18px;stroke:#9ca3af;pointer-events:none; }

/* Results list */
.ra-results { margin-top:.75rem; }
.ra-result-item { display:flex;align-items:center;gap:.875rem;padding:.75rem .9rem;border:1.5px solid transparent;border-radius:10px;cursor:pointer;transition:all .15s; }
.ra-result-item:hover { background:#f0f3ff;border-color:#c7d2fe; }
.ra-result-item.selected { background:#eff6ff;border-color:#4361ee; }
.ra-result-avatar { width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#4361ee,#7c3aed);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.9rem;font-weight:800;flex-shrink:0; }
.ra-result-name { font-weight:700;color:#1f2937;font-size:.875rem; }
.ra-result-meta { font-size:.75rem;color:#6b7280;margin-top:.1rem; }
.ra-result-badge { display:inline-flex;padding:.15rem .5rem;border-radius:99px;font-size:.65rem;font-weight:700;margin-left:.4rem; }
.ra-result-badge.active { background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0; }
.ra-result-badge.inactive { background:#fef2f2;color:#991b1b;border:1px solid #fecaca; }
.ra-no-results { text-align:center;padding:1.5rem;color:#9ca3af;font-size:.85rem; }

/* ── Selected student banner ── */
.ra-selected-banner { display:flex;align-items:center;gap:1rem;background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1.5px solid #93c5fd;border-radius:14px;padding:1rem 1.25rem;margin-bottom:1.25rem;animation:fadeSlide .3s ease; }
.ra-sel-avatar { width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,#4361ee,#3730a3);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.4rem;font-weight:800;flex-shrink:0; }
.ra-sel-name { font-size:1.1rem;font-weight:700;color:#1f2937; }
.ra-sel-badges { display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.35rem; }
.ra-sel-badge { display:inline-flex;align-items:center;gap:.25rem;padding:.2rem .65rem;border-radius:99px;font-size:.72rem;font-weight:700; }
.ra-sel-badge.roll { background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe; }
.ra-sel-badge.course { background:#fdf4ff;color:#7e22ce;border:1px solid #e9d5ff; }
.ra-sel-badge.status { background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7; }
.ra-sel-photo { width:60px;height:72px;border-radius:8px;overflow:hidden;border:2px solid #bfdbfe;margin-left:auto;flex-shrink:0; }
.ra-sel-photo img { width:100%;height:100%;object-fit:cover; }
.ra-change-btn { margin-left:auto;padding:.45rem .9rem;background:#fff;border:1.5px solid #e5e7eb;border-radius:8px;font-size:.78rem;font-weight:700;color:#6b7280;cursor:pointer;font-family:var(--font);transition:all .15s; }
.ra-change-btn:hover { border-color:#4361ee;color:#4361ee;background:#eff6ff; }

/* ── Course form ── */
.ra-form-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1rem; }
.ra-form-grid .full { grid-column:1/-1; }
.ra-field { display:flex;flex-direction:column;gap:.3rem; }
.ra-field label { font-size:.75rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em; }
.ra-field label .req { color:#ef4444; }
.ra-input,.ra-select,.ra-textarea {
    padding:.65rem .9rem;border:1.5px solid #e5e7eb;border-radius:8px;
    font-size:.9rem;font-family:var(--font);color:#1f2937;background:#fff;
    transition:border-color .18s;width:100%;box-sizing:border-box;
}
.ra-input:focus,.ra-select:focus,.ra-textarea:focus { border-color:#4361ee;outline:none;box-shadow:0 0 0 3px rgba(67,97,238,.08); }
.ra-textarea { resize:vertical; }

/* Fee summary */
.ra-fee-summary { display:flex;gap:1rem;flex-wrap:wrap;margin-top:1rem;padding:1rem;background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px; }
.ra-fee-item { flex:1;min-width:120px;text-align:center; }
.ra-fee-item .val { font-size:1.25rem;font-weight:800;color:#1f2937;font-family:var(--mono); }
.ra-fee-item .lbl { font-size:.68rem;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-top:.1rem; }

/* Submit */
.ra-submit-bar { display:flex;gap:.75rem;justify-content:flex-end;margin-top:1.25rem;flex-wrap:wrap; }
.ra-btn-primary { display:inline-flex;align-items:center;gap:.5rem;padding:.75rem 1.5rem;border-radius:10px;font-size:.875rem;font-weight:800;color:#fff;background:linear-gradient(135deg,#10b981,#059669);border:none;cursor:pointer;font-family:var(--font);box-shadow:0 4px 14px rgba(16,185,129,.25);transition:all .2s; }
.ra-btn-primary:hover { transform:translateY(-2px);box-shadow:0 6px 20px rgba(16,185,129,.35); }
.ra-btn-primary:disabled { opacity:.55;cursor:not-allowed;transform:none; }
.ra-btn-secondary { padding:.75rem 1.5rem;border-radius:10px;font-size:.875rem;font-weight:600;color:#6b7280;background:#fff;border:1.5px solid #e5e7eb;cursor:pointer;font-family:var(--font);text-decoration:none;display:inline-flex;align-items:center;gap:.4rem; }
.ra-btn-secondary:hover { border-color:#4361ee;color:#4361ee; }

/* Success */
.ra-success-card { background:linear-gradient(135deg,#ecfdf5,#d1fae5);border:2px solid #6ee7b7;border-radius:16px;padding:2rem;text-align:center;margin-bottom:1.5rem;animation:fadeSlide .4s ease; }
.ra-success-icon { width:64px;height:64px;border-radius:50%;background:#10b981;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem; }
.ra-success-icon svg { width:32px;height:32px;stroke:#fff; }
.ra-success-title { font-size:1.2rem;font-weight:800;color:#065f46;margin-bottom:.5rem; }
.ra-success-ids { display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap;margin-top:.75rem; }
.ra-success-id { padding:.35rem .85rem;border-radius:99px;font-size:.82rem;font-weight:700;font-family:var(--mono); }
.ra-success-id.roll { background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe; }
.ra-success-id.reg { background:#fdf4ff;color:#7e22ce;border:1px solid #e9d5ff; }

/* Toast */
#raToastContainer { position:fixed;bottom:1.5rem;right:1.5rem;z-index:99999;display:flex;flex-direction:column;gap:.5rem; }

/* Animations */
@keyframes fadeSlide { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
@keyframes spin { to{transform:rotate(360deg)} }

/* Hidden */
.ra-hidden { display:none !important; }

/* Locked / conditional fields */
.ra-field-wrap { position:relative;transition:all .25s; }
.ra-field-wrap.locked { opacity:.45;pointer-events:none; }
.ra-field-wrap.locked .ra-select,
.ra-field-wrap.locked .ra-input { background:#f3f4f6;border-color:#e5e7eb;color:#9ca3af; }
.ra-lock-notice { display:none;font-size:.7rem;font-weight:600;color:#9ca3af;font-style:italic;margin-top:.2rem; }
.ra-field-wrap.locked .ra-lock-notice { display:block; }
.ra-field-wrap.unlocked { opacity:1;pointer-events:auto; }
.ra-field-wrap.unlocked .ra-lock-notice { display:none; }
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
                <h2>Re-Admission</h2>
                <p>Enroll existing student to a new course</p>
            </div>
        </div>
        <div class="header-right">
            <?php if (file_exists(__DIR__ . '/../includes/notification_bell.php')) include __DIR__ . '/../includes/notification_bell.php'; ?>
            <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
        </div>
    </header>

    <div class="page-content">

        <!-- Page header -->
        <div class="ra-header">
            <div class="ra-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
            </div>
            <div>
                <div class="ra-title">Re-Admission</div>
                <div class="ra-subtitle">Admit an existing student to a new course — personal details are auto-filled</div>
            </div>
        </div>

        <!-- Steps -->
        <div class="ra-steps">
            <div class="ra-step active" id="stepSearch">
                <span class="step-num">1</span> Search Student
            </div>
            <div class="ra-step inactive" id="stepCourse">
                <span class="step-num">2</span> Select Course
            </div>
            <div class="ra-step inactive" id="stepDone">
                <span class="step-num">3</span> Confirmed
            </div>
        </div>

        <!-- ═══ STEP 1: Search & Select Student ═══ -->
        <div id="sectionSearch">
            <div class="ra-panel">
                <div class="ra-panel-head">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                    Search Existing Student
                </div>
                <div class="ra-panel-body">
                    <div class="ra-search-wrap">
                        <svg class="search-ico" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" id="studentSearch" placeholder="Search by name, roll no, registration ID or mobile…" autocomplete="off">
                    </div>
                    <div class="ra-results" id="searchResults"></div>
                </div>
            </div>
        </div>

        <!-- ═══ STEP 2: Course Selection Form ═══ -->
        <div id="sectionCourse" class="ra-hidden">

            <!-- Selected student banner -->
            <div class="ra-selected-banner" id="selectedBanner"></div>

            <div class="ra-panel">
                <div class="ra-panel-head">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                    New Course Details
                </div>
                <div class="ra-panel-body">
                    <div class="ra-form-grid">
                        <!-- Course -->
                        <div class="ra-field">
                            <label>Course <span class="req">*</span></label>
                            <select class="ra-select" id="raCourse" onchange="onCourseChange()">
                                <option value="">— Select Course —</option>
                                <?php foreach ($courseSelectOptions as $opt): ?>
                                <option value="<?= e_ra($opt['course_name']) ?>"
                                        data-fees="<?= $opt['fee'] ?>"
                                        data-type="<?= e_ra($opt['course_type'] ?? '') ?>"
                                        data-material="<?= e_ra($opt['material_type']) ?>"
                                        data-language="<?= e_ra($opt['language'] ?? 'English') ?>">
                                    <?= e_ra($opt['label']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Course Fees -->
                        <div class="ra-field">
                            <label>Course Fees (₹)</label>
                            <input class="ra-input" id="raCourseFees" type="number" step="0.01" value="0" onchange="calcFee()" readonly>
                        </div>
                        <!-- Discount -->
                        <div class="ra-field">
                            <label>Discount (₹)</label>
                            <input class="ra-input" id="raDiscount" type="number" step="0.01" value="0" onchange="calcFee()">
                        </div>
                        <!-- Discount Reason -->
                        <div class="ra-field">
                            <label>Discount Reason</label>
                            <input class="ra-input" id="raDiscountReason" placeholder="Optional">
                        </div>
                        <!-- Installments -->
                        <div class="ra-field">
                            <label>Installments</label>
                            <select class="ra-select" id="raInstallments">
                                <option value="1">1 (Full Payment)</option>
                                <option value="2">2 Installments</option>
                                <option value="3">3 Installments</option>
                                <option value="4">4 Installments</option>
                            </select>
                        </div>
                        <!-- Uniform Size (Abacus only) -->
                        <div class="ra-field-wrap locked" id="wrapUniform">
                            <div class="ra-field">
                                <label>👕 T-Shirt / Uniform Size</label>
                                <select class="ra-select" id="raUniformSize" disabled>
                                    <option value="">— Select —</option>
                                    <option value="36">36</option>
                                    <option value="38">38</option>
                                    <option value="40">40</option>
                                    <option value="42">42</option>
                                    <option value="44">44</option>
                                    <option value="46">46</option>
                                </select>
                                <div class="ra-lock-notice">🔒 Available only for Abacus courses</div>
                            </div>
                        </div>
                        <!-- Material Type (auto-set from course, NOT user-selectable) -->
                        <input type="hidden" id="raMaterialType" value="Without Material">
                        <div class="ra-field-wrap locked" id="wrapMaterialInfo">
                            <div class="ra-field">
                                <label>📦 Material</label>
                                <div class="ra-input" id="raMaterialDisplay" style="background:#f3f4f6;cursor:default;">Select a course first</div>
                                <div class="ra-lock-notice">🔒 Determined by the selected course</div>
                            </div>
                        </div>
                        <!-- Material Language (only when course includes material) -->
                        <div class="ra-field-wrap locked" id="wrapMaterialLang">
                            <div class="ra-field">
                                <label>🌐 Material Language</label>
                                <select class="ra-select" id="raMaterialLang" disabled>
                                    <option value="English">English</option>
                                    <option value="Marathi">Marathi</option>
                                </select>
                                <div class="ra-lock-notice">🔒 Available when course includes material</div>
                            </div>
                        </div>
                        <!-- Comment -->
                        <div class="ra-field full">
                            <label>Comments / Notes</label>
                            <textarea class="ra-textarea ra-input" id="raComment" rows="2" placeholder="Optional notes for this re-admission…"></textarea>
                        </div>
                    </div>

                    <!-- Fee summary -->
                    <div class="ra-fee-summary">
                        <div class="ra-fee-item"><div class="val" id="feeGross">₹0</div><div class="lbl">Course Fees</div></div>
                        <div class="ra-fee-item"><div class="val" id="feeDisc" style="color:#d97706;">-₹0</div><div class="lbl">Discount</div></div>
                        <div class="ra-fee-item"><div class="val" id="feeNet" style="color:#059669;">₹0</div><div class="lbl">Net Payable</div></div>
                    </div>
                </div>
            </div>

            <!-- Submit -->
            <div class="ra-submit-bar">
                <button class="ra-btn-secondary" onclick="goBackToSearch()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><polyline points="15 18 9 12 15 6"/></svg>
                    Back
                </button>
                <button class="ra-btn-primary" id="submitReAdmit" onclick="submitReAdmission()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px"><polyline points="20 6 9 17 4 12"/></svg>
                    Confirm Re-Admission
                </button>
            </div>
        </div>

        <!-- ═══ STEP 3: Success ═══ -->
        <div id="sectionDone" class="ra-hidden">
            <div class="ra-success-card" id="successCard"></div>
            <div class="ra-submit-bar">
                <button class="ra-btn-secondary" onclick="location.href='students.php'">View Students</button>
                <button class="ra-btn-primary" onclick="resetAll()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                    Another Re-Admission
                </button>
            </div>
        </div>

    </div>
</main>
</div>

<!-- Toast -->
<div id="raToastContainer"></div>

<script src="../assets/js/dashboard.js"></script>
<script>
/* ── State ── */
let selectedStudent = null;
let debounceTimer   = null;

/* ── Search ── */
const searchInput  = document.getElementById('studentSearch');
const searchResults = document.getElementById('searchResults');

searchInput.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(doSearch, 300);
});

async function doSearch() {
    const q = searchInput.value.trim();
    if (q.length < 2) { searchResults.innerHTML = ''; return; }

    const fd = new FormData();
    fd.append('action', 'search_student');
    fd.append('q', q);

    const res  = await fetch('', { method:'POST', body:fd });
    const data = await res.json();

    if (!data.success || !data.data.length) {
        searchResults.innerHTML = '<div class="ra-no-results">No students found. Try a different search term.</div>';
        return;
    }

    searchResults.innerHTML = data.data.map(s => {
        const full = [s.first_name, s.middle_name, s.last_name].filter(Boolean).join(' ');
        const init = (s.first_name || '?')[0].toUpperCase();
        const badge = s.status === 'Active'
            ? '<span class="ra-result-badge active">Active</span>'
            : '<span class="ra-result-badge inactive">' + (s.status||'') + '</span>';
        return `
        <div class="ra-result-item" onclick='selectStudent(${JSON.stringify(s)})'>
            <div class="ra-result-avatar">${init}</div>
            <div style="flex:1">
                <div class="ra-result-name">${esc(full)} ${badge}</div>
                <div class="ra-result-meta">Roll: ${esc(s.roll_no)} · Reg: ${esc(s.registration_id)} · ${esc(s.course||'—')} · 📱 ${esc(s.mobile||'—')}</div>
            </div>
        </div>`;
    }).join('');
}

/* ── Select ── */
function selectStudent(s) {
    selectedStudent = s;
    const full = [s.first_name, s.middle_name, s.last_name].filter(Boolean).join(' ');
    const init = (s.first_name || '?')[0].toUpperCase();

    let photoHtml = '';
    if (s.photo) {
        photoHtml = `<div class="ra-sel-photo"><img src="../${s.photo}" alt=""></div>`;
    }

    document.getElementById('selectedBanner').innerHTML = `
        <div class="ra-sel-avatar">${init}</div>
        <div>
            <div class="ra-sel-name">${esc(full)}</div>
            <div class="ra-sel-badges">
                <span class="ra-sel-badge roll">Roll: ${esc(s.roll_no)}</span>
                <span class="ra-sel-badge course">📚 ${esc(s.course||'—')}</span>
                <span class="ra-sel-badge status">${esc(s.status)}</span>
            </div>
        </div>
        ${photoHtml}
        <button class="ra-change-btn" onclick="goBackToSearch()">Change</button>
    `;

    // Pre-fill uniform size if available
    if (s.uniform_size) {
        const uSel = document.getElementById('raUniformSize');
        for (let i = 0; i < uSel.options.length; i++) {
            if (uSel.options[i].value === s.uniform_size) { uSel.selectedIndex = i; break; }
        }
    }

    // Move to step 2
    setStep(2);

    // Fetch enrolled courses and disable them in the dropdown
    loadEnrolledCourses(s.registration_id);
}

/* ── Step transitions ── */
function setStep(n) {
    document.getElementById('sectionSearch').classList.toggle('ra-hidden', n !== 1);
    document.getElementById('sectionCourse').classList.toggle('ra-hidden', n !== 2);
    document.getElementById('sectionDone').classList.toggle('ra-hidden', n !== 3);

    document.getElementById('stepSearch').className = 'ra-step ' + (n===1?'active':n>1?'done':'inactive');
    document.getElementById('stepCourse').className = 'ra-step ' + (n===2?'active':n>2?'done':'inactive');
    document.getElementById('stepDone').className   = 'ra-step ' + (n===3?'active':'inactive');
}

function goBackToSearch() {
    selectedStudent = null;
    setStep(1);
}

/* ── Load enrolled courses and disable them in dropdown ── */
async function loadEnrolledCourses(regId) {
    try {
        const fd = new FormData();
        fd.append('action', 'get_enrolled_courses');
        fd.append('registration_id', regId);
        const res = await (await fetch('', { method:'POST', body:fd })).json();
        if (!res.success) return;

        const sel = document.getElementById('raCourse');
        const enrolled = (res.courses || []).map(c => c.trim().toLowerCase());
        for (let i = 0; i < sel.options.length; i++) {
            const opt = sel.options[i];
            if (!opt.value) continue;
            if (enrolled.includes(opt.value.trim().toLowerCase())) {
                opt.disabled = true;
                opt.textContent = opt.textContent.replace(/ — ₹/, ' ✅ Already Enrolled — ₹');
            } else {
                opt.disabled = false;
            }
        }
    } catch(e) {}
}

/* ── Course change — conditional field logic ── */
function onCourseChange() {
    const sel  = document.getElementById('raCourse');
    const opt  = sel.options[sel.selectedIndex];
    const fees = parseFloat(opt.dataset.fees || 0);
    document.getElementById('raCourseFees').value = fees;

    const courseType  = (opt.dataset.type || '').toLowerCase();   // abacus, vedic maths, it
    const materialDef = opt.dataset.material || 'Without Material';
    const langDef     = opt.dataset.language || 'English';
    const hasCourse   = !!sel.value;

    // ── T-Shirt / Uniform: only for Abacus ──
    const wrapU   = document.getElementById('wrapUniform');
    const selU    = document.getElementById('raUniformSize');
    const isAbacus = courseType === 'abacus';
    if (isAbacus && hasCourse) {
        wrapU.classList.remove('locked'); wrapU.classList.add('unlocked');
        selU.disabled = false;
        // Pre-fill from selected student if available
        if (selectedStudent && selectedStudent.uniform_size) {
            setSelect('raUniformSize', selectedStudent.uniform_size);
        }
    } else {
        wrapU.classList.remove('unlocked'); wrapU.classList.add('locked');
        selU.disabled = true; selU.value = '';
    }

    // ── Material: auto-set from course definition (not user-selectable) ──
    const isWithMaterial = (materialDef === 'With Material');
    const materialDisplay = document.getElementById('raMaterialDisplay');
    const wrapMInfo = document.getElementById('wrapMaterialInfo');
    document.getElementById('raMaterialType').value = materialDef;  // hidden field

    if (hasCourse) {
        wrapMInfo.classList.remove('locked'); wrapMInfo.classList.add('unlocked');
        const badgeColor = isWithMaterial ? 'background:#d1fae5;color:#065f46;border:1px solid #6ee7b7' : 'background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0';
        materialDisplay.innerHTML = '<span style="display:inline-block;padding:.2rem .6rem;border-radius:6px;font-size:.82rem;font-weight:700;' + badgeColor + '">' + materialDef + '</span>';
    } else {
        wrapMInfo.classList.remove('unlocked'); wrapMInfo.classList.add('locked');
        materialDisplay.textContent = 'Select a course first';
    }

    // ── Material Language: only when With Material ──
    const wrapML  = document.getElementById('wrapMaterialLang');
    const selML   = document.getElementById('raMaterialLang');
    if (hasCourse && isWithMaterial) {
        wrapML.classList.remove('locked'); wrapML.classList.add('unlocked');
        selML.disabled = false;
        setSelect('raMaterialLang', langDef);
    } else {
        wrapML.classList.remove('unlocked'); wrapML.classList.add('locked');
        selML.disabled = true;
        selML.value = langDef;
    }

    calcFee();
}

function calcFee() {
    const gross = parseFloat(document.getElementById('raCourseFees').value) || 0;
    const disc  = parseFloat(document.getElementById('raDiscount').value) || 0;
    const net   = Math.max(0, gross - disc);
    document.getElementById('feeGross').textContent = '₹' + gross.toLocaleString('en-IN');
    document.getElementById('feeDisc').textContent  = '-₹' + disc.toLocaleString('en-IN');
    document.getElementById('feeNet').textContent   = '₹' + net.toLocaleString('en-IN');
}

function setSelect(id, val) {
    const el = document.getElementById(id);
    for (let i = 0; i < el.options.length; i++) {
        if (el.options[i].value === val) { el.selectedIndex = i; return; }
    }
}

/* ── Submit ── */
async function submitReAdmission() {
    if (!selectedStudent) { raToast('Select a student first.', 'error'); return; }
    const course = document.getElementById('raCourse').value;
    if (!course) { raToast('Please select a course.', 'error'); return; }

    const btn = document.getElementById('submitReAdmit');
    btn.disabled = true; btn.textContent = 'Processing…';

    const fd = new FormData();
    fd.append('action', 're_admit');
    fd.append('source_id', selectedStudent.id);
    fd.append('course', course);
    fd.append('course_fees', document.getElementById('raCourseFees').value);
    fd.append('discount_amount', document.getElementById('raDiscount').value);
    fd.append('discount_reason', document.getElementById('raDiscountReason').value);
    fd.append('installments', document.getElementById('raInstallments').value);
    fd.append('uniform_size', document.getElementById('raUniformSize').value);
    fd.append('material_type', document.getElementById('raMaterialType').value);
    fd.append('material_language', document.getElementById('raMaterialLang').value);
    fd.append('comment', document.getElementById('raComment').value);

    const res  = await fetch('', { method:'POST', body:fd });
    const data = await res.json();

    btn.disabled = false;
    btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px"><polyline points="20 6 9 17 4 12"/></svg> Confirm Re-Admission';

    if (data.success) {
        document.getElementById('successCard').innerHTML = `
            <div class="ra-success-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div class="ra-success-title">🎉 Re-Admission Successful!</div>
            <p style="color:#065f46;font-size:.9rem;">${esc(data.message)}</p>
            <div class="ra-success-ids">
                <span class="ra-success-id roll">Roll No: ${esc(data.roll_no)}</span>
                <span class="ra-success-id reg">Reg ID: ${esc(data.registration_id)}</span>
            </div>
        `;
        setStep(3);
    } else {
        raToast(data.message || 'Something went wrong.', 'error');
    }
}

/* ── Reset ── */
function resetAll() {
    selectedStudent = null;
    searchInput.value = '';
    searchResults.innerHTML = '';
    document.getElementById('raCourse').selectedIndex = 0;
    document.getElementById('raCourseFees').value = 0;
    document.getElementById('raDiscount').value = 0;
    document.getElementById('raDiscountReason').value = '';
    document.getElementById('raInstallments').selectedIndex = 0;
    document.getElementById('raComment').value = '';
    calcFee();
    setStep(1);
    searchInput.focus();
}

/* ── Helpers ── */
function esc(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

function raToast(msg, type = 'success') {
    const t = document.createElement('div');
    const bg = type === 'success' ? '#059669' : '#dc2626';
    t.style.cssText = `background:${bg};color:#fff;padding:.75rem 1.25rem;border-radius:10px;font-size:.875rem;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.18);animation:fadeSlide .3s ease;max-width:340px`;
    t.textContent = msg;
    document.getElementById('raToastContainer').appendChild(t);
    setTimeout(() => t.remove(), 3500);
}
</script>
</body>
</html>
