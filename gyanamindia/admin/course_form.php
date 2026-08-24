<?php
/**
 * Gyanam Portal — Admin: Course Add / Edit Page
 * Separated from the legacy modal UI in `admin/courses.php`.
 *
 * If "With Material" is selected, admin must select which inventory items
 * (Books / T-Shirts) belong to that course. Admin can also create new
 * inventory items (which are immediately stocked).
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());

ensureDualMaterialCourseSchema($pdo);
ensureCourseMaterialItemsSchema($pdo);
ensureInventoryTables($pdo);

$mode = $_GET['action'] ?? 'add'; // add | edit
$courseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $mode === 'edit' && $courseId > 0;

// ── Load dropdown data ───────────────────────────────────────────────────────
$centerTypes = masterCourseTypes();
$durations = ['1 Month', '2 Months', '3 Months', '6 Months', '1 Year', '2 Years'];

// Inventory items (for With Material selection), grouped by category.
// Default list stays T-Shirts + Books; other categories are available via the filter.
$inventoryCategories = [];
try {
    $inventoryCategories = $pdo->query("SELECT name FROM inventory_categories ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}
if (empty($inventoryCategories)) {
    $inventoryCategories = ['Books', 'T-Shirts', 'Certificates', 'Stationery', 'Other'];
}

$itemsByCategory = [];
foreach ($inventoryCategories as $catName) {
    $itemsByCategory[$catName] = [];
}
try {
    $allInvItems = $pdo->query("
        SELECT id, item_name, category, current_stock
        FROM inventory_items
        WHERE status = 'Active'
        ORDER BY category ASC, item_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allInvItems as $it) {
        $catName = trim((string)($it['category'] ?? '')) ?: 'Other';
        if (!isset($itemsByCategory[$catName])) {
            $inventoryCategories[] = $catName;
            $itemsByCategory[$catName] = [];
        }
        $itemsByCategory[$catName][] = $it;
    }
    $inventoryCategories = array_values(array_unique($inventoryCategories));
} catch (Exception $e) {}

$primaryMaterialCats = ['T-Shirts', 'Books'];
$materialCatSlug = static function (string $name): string {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? 'other';
    return trim($slug, '-') ?: 'other';
};

// ── Load course (edit) ───────────────────────────────────────────────────────
$course = [];
$selectedItemIds = [];
$materialOption = 'without';

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
    $stmt->execute([$courseId]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    if (!$course) {
        header('Location: courses.php');
        exit;
    }

    $withW = (float)($course['ho_share_with_material'] ?? 0);
    $withD = (float)($course['dlc_share_with_material'] ?? 0);
    $withoutW = (float)($course['ho_share_without_material'] ?? 0);
    $withoutD = (float)($course['dlc_share_without_material'] ?? 0);

    if ($withW > 0 || $withD > 0) $materialOption = 'with';
    else if ($withoutW > 0 || $withoutD > 0) $materialOption = 'without';
    else {
        // Last resort for legacy courses
        $materialOption = (($course['material_type'] ?? '') === 'With Material') ? 'with' : 'without';
    }

    try {
        $mstmt = $pdo->prepare("
            SELECT inventory_item_id
            FROM course_material_items
            WHERE course_id = ? AND material_variant = 'With Material'
        ");
        $mstmt->execute([$courseId]);
        $selectedItemIds = array_map(fn($x) => (int)$x, $mstmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        $selectedItemIds = [];
    }
}

// ── Handle save ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    try {
        $courseType = trim($_POST['course_type'] ?? '');
        if (!in_array($courseType, $centerTypes, true)) {
            throw new Exception('Select which center type can see this course (Abacus, Vedic Maths, or IT).');
        }

        $courseName = trim($_POST['course_name'] ?? '');
        if (!$courseName) throw new Exception('Course name is required');

        $courseContent = trim($_POST['course_content'] ?? '');
        if (!$courseContent) throw new Exception('Course content is required');

        $durationSel = $_POST['duration'] ?? '';
        if ($durationSel === 'custom') {
            $duration = trim($_POST['custom_dur'] ?? '');
        } else {
            $duration = (string)$durationSel;
        }
        if (!$duration) throw new Exception('Duration is required');

        $status = $_POST['status'] ?? 'Active';
        if (!in_array($status, ['Active', 'Inactive'], true)) $status = 'Active';

        $materialOption = $_POST['material_option'] ?? 'without';
        if (!in_array($materialOption, ['with', 'without'], true)) $materialOption = 'without';

        $hoWith = floatval($_POST['ho_share_with_material'] ?? 0);
        $dlcWith = floatval($_POST['dlc_share_with_material'] ?? 0);
        $hoWithout = floatval($_POST['ho_share_without_material'] ?? 0);
        $dlcWithout = floatval($_POST['dlc_share_without_material'] ?? 0);

        $materialLanguage = trim($_POST['material_language'] ?? 'English');
        if ($materialLanguage !== 'Marathi') $materialLanguage = 'English';

        $withConfigured = ($materialOption === 'with' && ($hoWith > 0 || $dlcWith > 0)) ? 1 : 0;

        // Save course row
        $materialTypeDb = ($materialOption === 'with') ? 'With Material' : 'Without Material';

        $legacyHoShare = ($materialOption === 'with') ? $hoWith : $hoWithout;

        if ($isEdit) {
            $stmt = $pdo->prepare("
                UPDATE courses
                SET course_name                = ?,
                    course_type                = ?,
                    duration                   = ?,
                    course_content             = ?,
                    ho_share                   = ?,
                    ho_share_with_material     = ?,
                    ho_share_without_material  = ?,
                    dlc_share_with_material    = ?,
                    dlc_share_without_material = ?,
                    material_type              = ?,
                    material_language          = ?,
                    with_material_configured  = ?,
                    status                     = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $courseName,
                $courseType,
                $duration,
                $courseContent,
                $legacyHoShare,
                ($materialOption === 'with') ? $hoWith : 0,
                ($materialOption === 'without') ? $hoWithout : 0,
                ($materialOption === 'with') ? $dlcWith : 0,
                ($materialOption === 'without') ? $dlcWithout : 0,
                $materialTypeDb,
                $materialLanguage,
                $withConfigured,
                $status,
                $courseId
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO courses
                    (course_name, course_type, duration, course_content,
                     ho_share, ho_share_with_material, ho_share_without_material,
                     dlc_share_with_material, dlc_share_without_material,
                     material_type, material_language, with_material_configured, status)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $courseName,
                $courseType,
                $duration,
                $courseContent,
                $legacyHoShare,
                ($materialOption === 'with') ? $hoWith : 0,
                ($materialOption === 'without') ? $hoWithout : 0,
                ($materialOption === 'with') ? $dlcWith : 0,
                ($materialOption === 'without') ? $dlcWithout : 0,
                $materialTypeDb,
                $materialLanguage,
                $withConfigured,
                $status
            ]);
            $courseId = (int)$pdo->lastInsertId();
            $isEdit = true;
        }

        // Save mapping rows (only meaningful when With is configured)
        $pdo->prepare("
            DELETE FROM course_material_items
            WHERE course_id = ? AND material_variant = 'With Material'
        ")->execute([$courseId]);

        if ($withConfigured === 1) {
            $selectedIds = $_POST['with_material_inventory_item_ids'] ?? [];
            if (!is_array($selectedIds)) $selectedIds = [];
            $selectedIds = array_values(array_unique(array_map(fn($x) => (int)$x, $selectedIds)));
            $selectedIds = array_values(array_filter($selectedIds, fn($x) => $x > 0));

            if (!empty($selectedIds)) {
                $ins = $pdo->prepare("
                    INSERT INTO course_material_items (course_id, material_variant, inventory_item_id)
                    VALUES (?, 'With Material', ?)
                ");
                foreach ($selectedIds as $iid) {
                    // Only insert active items (prevents linking deleted items)
                    $chk = $pdo->prepare("SELECT id FROM inventory_items WHERE id = ? AND status='Active' LIMIT 1");
                    $chk->execute([$iid]);
                    if ($chk->fetch()) {
                        $ins->execute([$courseId, $iid]);
                    }
                }
            }
        }

        // Sync to Exam Portal (keeps existing behavior)
        if (function_exists('syncCoursesToExamPortal')) { syncCoursesToExamPortal($pdo); }

        header('Location: courses.php');
        exit;
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }
}

// ── Defaults for rendering ──────────────────────────────────────────────────
$courseNameVal = htmlspecialchars((string)($course['course_name'] ?? ''), ENT_QUOTES);
$courseTypeVal = htmlspecialchars((string)($course['course_type'] ?? ''), ENT_QUOTES);
$durationVal = (string)($course['duration'] ?? '');
$courseContentVal = htmlspecialchars((string)($course['course_content'] ?? ''), ENT_QUOTES);
$statusVal = $course['status'] ?? 'Active';

$withWVal = (string)($course['ho_share_with_material'] ?? '');
$withDVal = (string)($course['dlc_share_with_material'] ?? '');
$withoutWVal = (string)($course['ho_share_without_material'] ?? '');
$withoutDVal = (string)($course['dlc_share_without_material'] ?? '');

// Keep legacy material_language; also used for book language for With Material
$materialLanguageVal = $course['material_language'] ?? 'English';

$materialOption = $isEdit
    ? (($materialOption ?? 'without') === 'with' ? 'with' : 'without')
    : 'with';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isEdit ? 'Edit Course' : 'Add Master Course' ?> — Admin | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <style>
        :root { --font: 'Sora', sans-serif; }
        .page-content { padding: 1.75rem 2rem; width: 100%; box-sizing: border-box; }
        .form-wrap { width: 100%; max-width: none; }
        .card { width: 100%; box-sizing: border-box; background:#fff;border:1.5px solid var(--border-color);border-radius:18px; padding:1.25rem 1.5rem; box-shadow:0 4px 12px rgba(0,0,0,.04); }
        .card + .card { margin-top: 1.2rem; }
        .card-title { font-size:.85rem;font-weight:900;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.85rem; }
        .field-grid { display:grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .full { grid-column: 1 / -1; }
        label { font-size:.76rem; font-weight:800; color:var(--text-secondary); display:block; margin-bottom:.35rem; }
        .field-input, .field-select, textarea { width: 100%; padding: .6rem .75rem; border-radius:10px; border:1.5px solid var(--border-color); font-family:var(--font); font-size:.9rem; outline:none; background:#fff; }
        textarea { resize: vertical; }
        .field-hint { font-size:.74rem;color:var(--text-secondary); opacity:.85; margin-top:.35rem; }
        .radio-row { display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:.9rem; }
        .radio-pill { display:flex; align-items:center; gap:.6rem; padding:.65rem .9rem; border-radius:12px; border:1.5px solid var(--border-color); background:#fafbfc; cursor:pointer; user-select:none; }
        .radio-pill input { width:18px; height:18px; }
        .hidden { display:none; }
        .items-block { border:1.5px solid var(--border-color); border-radius:14px; padding:.85rem 1rem; background:#fafafa; }
        .items-title { font-size:.78rem; font-weight:900; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.05em; margin-bottom:.6rem; }
        .mat-filter-row { display:flex; gap:.75rem; align-items:flex-end; flex-wrap:wrap; margin-bottom:.9rem; }
        .mat-filter-row .mf-field { flex: 1; min-width: 220px; }
        .mat-filter-row .mf-field.cat { flex: 0 0 180px; min-width: 160px; }
        .items-list { display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: .5rem; max-height: 360px; overflow:auto; padding-right:.25rem; }
        .item-check { display:flex; align-items:flex-start; gap:.6rem; padding:.55rem .65rem; border:1.5px solid var(--border-color); border-radius:12px; background:#fff; }
        .item-check.is-hidden, .cat-block.is-hidden { display: none !important; }
        .filter-empty { display:none; font-size:.8rem; color:var(--text-secondary); padding:.35rem .1rem; }
        .filter-empty.show { display:block; }
        .item-check input { margin-top: .15rem; }
        .item-check .meta { display:flex; flex-direction:column; gap:.2rem; }
        .item-check .nm { font-weight:850; font-size:.86rem; color:#111827; }
        .item-check .st { font-size:.74rem; color:var(--text-secondary); }
        .btn-row { display:flex; justify-content:flex-end; gap:.8rem; margin-top: 1.1rem; flex-wrap:wrap; }
        .btn-secondary { background:#fff; border:1.5px solid var(--border-color); color:var(--text-primary); }
        .error-banner { background:#fef2f2; border:1.5px solid #fecaca; color:#991b1b; padding:.85rem 1rem; border-radius:14px; margin-bottom:1rem; }
        .modal-overlay { position:fixed; top:0;left:0;right:0;bottom:0; background:rgba(2,6,23,.55); display:none; align-items:center; justify-content:center; z-index: 999; padding: 1.2rem; }
        .modal-overlay.active { display:flex; }
        .modal-card { width:100%; max-width: 620px; background:#fff; border-radius:18px; border:1.5px solid var(--border-color); box-shadow:0 16px 60px rgba(0,0,0,.22); overflow:hidden; }
        .modal-header { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:.9rem 1.05rem; border-bottom:1.5px solid var(--border-color); }
        .modal-close { cursor:pointer; border:none; background:transparent; padding:.25rem; border-radius:10px; }
        .modal-close:hover { background:#f3f4f6; }
        .modal-body { padding: 1rem 1.05rem 1.25rem; }
        .modal-footer { padding: .95rem 1.05rem; border-top:1.5px solid var(--border-color); display:flex; justify-content:flex-end; gap:.8rem; }
        @media (max-width: 720px) {
            .field-grid { grid-template-columns: 1fr; }
            .page-content { padding: 1.1rem 1rem; }
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
                    <h2><?= $isEdit ? 'Edit Course' : 'Add Master Course' ?></h2>
                    <p>Configure HO/DLC shares and the course kit (books/t-shirts) items.</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">
            <div class="form-wrap">

            <?php if (!empty($errorMsg)): ?>
                <div class="error-banner"><?= htmlspecialchars($errorMsg) ?></div>
            <?php endif; ?>

            <form id="courseSaveForm" method="POST">
                <input type="hidden" name="action" value="save">

                <div class="card">
                    <div class="card-title">Course Information</div>

                    <div class="field-grid">
                        <div class="full">
                            <label for="course_name">Course Name <span class="field-req">*</span></label>
                            <input type="text" class="field-input" id="course_name" name="course_name" required maxlength="100"
                                   placeholder="e.g. Abacus Level 1, DCA, Vedic Maths"
                                   value="<?= $courseNameVal ?>">
                        </div>

                        <div>
                            <label for="course_type">Visible to center type <span class="field-req">*</span></label>
                            <select class="field-select" id="course_type" name="course_type" required>
                                <option value="">— Select center type —</option>
                                <?php foreach ($centerTypes as $ct): ?>
                                    <option value="<?= htmlspecialchars($ct) ?>" <?= ($courseTypeVal === htmlspecialchars($ct) ? 'selected' : '') ?>>
                                        <?= htmlspecialchars($ct) ?> centers
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="field-hint">Only ATCs of this type will see the course.</div>
                        </div>

                        <div>
                            <label for="duration">Duration</label>
                            <select class="field-select" id="duration" name="duration">
                                <option value="">— Select —</option>
                                <?php foreach ($durations as $d): ?>
                                    <option value="<?= htmlspecialchars($d) ?>" <?= ($durationVal === $d ? 'selected' : '') ?>>
                                        <?= htmlspecialchars($d) ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="custom" <?= ($durationVal && !in_array($durationVal, $durations, true) ? 'selected' : '') ?>>custom…</option>
                            </select>
                            <div id="customDurWrap" style="margin-top:.65rem; <?= ($durationVal && !in_array($durationVal, $durations, true)) ? 'display:block' : 'display:none' ?>" class="full">
                                <label for="custom_dur" style="margin-bottom:.35rem">Custom Duration</label>
                                <input type="text" class="field-input" id="custom_dur" name="custom_dur"
                                       maxlength="50" placeholder="e.g. 45 Days, 18 Months"
                                       value="<?= ($durationVal && !in_array($durationVal, $durations, true)) ? htmlspecialchars($durationVal, ENT_QUOTES) : '' ?>">
                            </div>
                        </div>

                        <div class="full">
                            <label for="course_content">Course Content <span class="field-req">*</span></label>
                            <textarea class="field-input" id="course_content" name="course_content" rows="4" required
                                      placeholder="Topics covered, objectives, syllabus outline (shown on student certificate)"><?= $courseContentVal ?></textarea>
                            <div class="field-hint">Shown on the student completion certificate.</div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-title">Materials & Share</div>

                    <div class="radio-row">
                        <label class="radio-pill">
                            <input type="radio" name="material_option" value="with" <?= $materialOption === 'with' ? 'checked' : '' ?> onchange="onMaterialOptionChange()">
                            With Material
                        </label>
                        <label class="radio-pill">
                            <input type="radio" name="material_option" value="without" <?= $materialOption === 'without' ? 'checked' : '' ?> onchange="onMaterialOptionChange()">
                            Without Material
                        </label>
                    </div>

                    <div id="withWrap" class="<?= $materialOption === 'with' ? '' : 'hidden' ?>">
                        <div class="field-grid">
                            <div>
                                <label for="ho_share_with_material">HO Share (₹)</label>
                                <input type="number" class="field-input" id="ho_share_with_material" name="ho_share_with_material" min="0" step="1"
                                       placeholder="e.g. 2400" value="<?= htmlspecialchars((string)$withWVal, ENT_QUOTES) ?>">
                            </div>
                            <div>
                                <label for="dlc_share_with_material">DLC Share (₹)</label>
                                <input type="number" class="field-input" id="dlc_share_with_material" name="dlc_share_with_material" min="0" step="1"
                                       placeholder="e.g. 200" value="<?= htmlspecialchars((string)$withDVal, ENT_QUOTES) ?>">
                            </div>
                            <div class="full">
                                <label for="material_language">Material Language</label>
                                <select class="field-select" id="material_language" name="material_language">
                                    <option value="English" <?= ($materialLanguageVal === 'English' ? 'selected' : '') ?>>English</option>
                                    <option value="Marathi" <?= ($materialLanguageVal === 'Marathi' ? 'selected' : '') ?>>Marathi</option>
                                </select>
                            </div>
                        </div>

                        <div style="margin-top:1.1rem" class="items-block">
                            <div class="items-title">Select Materials for this Course</div>
                            <div class="field-hint" style="margin-bottom:.85rem">
                                Existing inventory items are shown category-wise. Admin can create a new item (and stock it) if needed.
                            </div>

                            <div class="mat-filter-row">
                                <div class="mf-field">
                                    <label for="matSearch">Search items</label>
                                    <input type="search" class="field-input" id="matSearch" placeholder="e.g. Size 32, Level 1, Yellow…" autocomplete="off">
                                </div>
                                <div class="mf-field cat">
                                    <label for="matCategory">Category</label>
                                    <select class="field-select" id="matCategory">
                                        <option value="all">All (T-Shirts &amp; Books)</option>
                                        <?php foreach ($inventoryCategories as $catName): ?>
                                            <option value="<?= htmlspecialchars($materialCatSlug((string)$catName), ENT_QUOTES) ?>">
                                                <?= htmlspecialchars((string)$catName) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="button" class="btn-add" onclick="openNewItemModal()">+ Create New Item</button>
                            </div>
                            <div class="filter-empty" id="matFilterEmpty">No items match this filter.</div>

                            <?php foreach ($inventoryCategories as $catName):
                                $catSlug = $materialCatSlug((string)$catName);
                                $catItems = $itemsByCategory[$catName] ?? [];
                                $isPrimary = in_array((string)$catName, $primaryMaterialCats, true);
                            ?>
                            <div class="cat-block items-block<?= $isPrimary ? '' : ' is-hidden' ?>" data-cat="<?= htmlspecialchars($catSlug, ENT_QUOTES) ?>" data-primary="<?= $isPrimary ? '1' : '0' ?>" style="background:transparent; border:none; padding:0; margin-bottom: .9rem">
                                <div class="items-title" style="margin:0 .1rem .5rem"><?= htmlspecialchars((string)$catName) ?></div>
                                <div class="items-list">
                                    <?php foreach ($catItems as $it): ?>
                                        <?php $checked = in_array((int)$it['id'], $selectedItemIds, true) ? 'checked' : ''; ?>
                                        <label class="item-check" data-name="<?= htmlspecialchars(strtolower($it['item_name']), ENT_QUOTES) ?>">
                                            <input type="checkbox" name="with_material_inventory_item_ids[]" value="<?= (int)$it['id'] ?>" <?= $checked ?>>
                                            <span class="meta">
                                                <span class="nm"><?= htmlspecialchars($it['item_name']) ?></span>
                                                <span class="st">Stock: <?= (int)$it['current_stock'] ?></span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                    <?php if (empty($catItems)): ?>
                                        <div class="field-hint">No active items in this category.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div id="withoutWrap" class="<?= $materialOption === 'without' ? '' : 'hidden' ?>" style="margin-top:1.05rem">
                        <div class="field-grid">
                            <div>
                                <label for="ho_share_without_material">HO Share (₹)</label>
                                <input type="number" class="field-input" id="ho_share_without_material" name="ho_share_without_material" min="0" step="1"
                                       placeholder="e.g. 180" value="<?= htmlspecialchars((string)$withoutWVal, ENT_QUOTES) ?>">
                            </div>
                            <div>
                                <label for="dlc_share_without_material">DLC Share (₹)</label>
                                <input type="number" class="field-input" id="dlc_share_without_material" name="dlc_share_without_material" min="0" step="1"
                                       placeholder="e.g. 50" value="<?= htmlspecialchars((string)$withoutDVal, ENT_QUOTES) ?>">
                            </div>
                            <div class="full">
                                <div class="field-hint">Without Material: T-Shirts / Books are not included (certificate still included).</div>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:1.1rem">
                        <label for="status">Status <span class="field-req">*</span></label>
                        <select class="field-select" id="status" name="status" required style="max-width:260px">
                            <option value="Active" <?= ($statusVal === 'Active' ? 'selected' : '') ?>>Active</option>
                            <option value="Inactive" <?= ($statusVal === 'Inactive' ? 'selected' : '') ?>>Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="btn-row">
                    <button type="button" class="btn-secondary" onclick="location.href='courses.php'">Back</button>
                    <button type="submit" class="btn-primary">
                        <?= $isEdit ? 'Update Course' : 'Add Course' ?>
                    </button>
                </div>

                <?php if ($isEdit): ?>
                    <input type="hidden" name="course_id" value="<?= (int)$courseId ?>">
                <?php endif; ?>
            </form>
        </div>
        </div>
    </main>
</div>

<!-- ── New Inventory Item Modal (create + stock-in) ───────────────────────── -->
<div class="modal-overlay" id="newItemModal">
    <div class="modal-card">
        <div class="modal-header">
            <h3 style="margin:0;font-size:1rem;font-weight:900">Create New Item</h3>
            <button type="button" class="modal-close" onclick="closeNewItemModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>

        <form id="newItemForm" onsubmit="return false;">
            <div class="modal-body">
                <div class="field-grid" style="grid-template-columns: 1fr 1fr">
                    <div class="full">
                        <label for="ni_item_name">Item Name <span class="field-req">*</span></label>
                        <input type="text" class="field-input" id="ni_item_name" name="item_name" required maxlength="150">
                    </div>

                    <div>
                        <label for="ni_category">Category</label>
                        <select class="field-select" id="ni_category" name="category">
                            <option value="Books" selected>Books</option>
                            <option value="T-Shirts">T-Shirts</option>
                            <option value="Certificates">Certificates</option>
                            <option value="Stationery">Stationery</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div>
                        <label for="ni_unit">Unit</label>
                        <input type="text" class="field-input" id="ni_unit" name="unit" value="pcs">
                    </div>

                    <div>
                        <label for="ni_min_stock_level">Min Stock Level</label>
                        <input type="number" class="field-input" id="ni_min_stock_level" name="min_stock_level" min="0" step="1" value="10">
                    </div>

                    <div>
                        <label for="ni_initial_qty">Initial Stock Qty</label>
                        <input type="number" class="field-input" id="ni_initial_qty" name="initial_stock_qty" min="0" step="1" value="0">
                        <div class="field-hint">If qty > 0, we will also run “Stock In”.</div>
                    </div>

                    <div class="full">
                        <label for="ni_cost_per_item">Cost per Item (needed for Stock In)</label>
                        <input type="number" class="field-input" id="ni_cost_per_item" name="cost_per_item" min="0" step="0.01" value="0">
                    </div>

                    <div class="full">
                        <label for="ni_description">Description</label>
                        <textarea class="field-input" id="ni_description" name="description" rows="3" placeholder="Optional description"></textarea>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeNewItemModal()">Cancel</button>
                <button type="button" class="btn-primary" onclick="createAndStockItem()">Create</button>
            </div>
        </form>
    </div>
</div>

<script>
    // ── Duration custom toggle ──────────────────────────────────────────────
    const durSel = document.getElementById('duration');
    const customWrap = document.getElementById('customDurWrap');
    const customDur = document.getElementById('custom_dur');
    function syncDurationUi() {
        if (!durSel || !customWrap) return;
        if (durSel.value === 'custom') {
            customWrap.style.display = 'block';
            customDur.required = true;
        } else {
            customWrap.style.display = 'none';
            customDur.required = false;
        }
    }
    if (durSel) {
        durSel.addEventListener('change', () => syncDurationUi());
        syncDurationUi();
    }

    // ── Material option show/hide ────────────────────────────────────────────
    function onMaterialOptionChange() {
        const optWith = document.querySelector('input[name="material_option"][value="with"]').checked;
        document.getElementById('withWrap').classList.toggle('hidden', !optWith);
        document.getElementById('withoutWrap').classList.toggle('hidden', optWith);
    }

    // ── New inventory item modal ─────────────────────────────────────────────
    function openNewItemModal() {
        document.getElementById('newItemModal').classList.add('active');
    }
    function closeNewItemModal() {
        document.getElementById('newItemModal').classList.remove('active');
    }

    function filterCourseMaterials() {
        const q = (document.getElementById('matSearch')?.value || '').trim().toLowerCase();
        const cat = document.getElementById('matCategory')?.value || 'all';
        let anyVisible = false;

        document.querySelectorAll('.cat-block').forEach((block) => {
            const blockCat = block.getAttribute('data-cat');
            const isPrimary = block.getAttribute('data-primary') === '1';
            const catOk = (cat === 'all') ? isPrimary : (cat === blockCat);
            let visibleInBlock = 0;
            block.querySelectorAll('.item-check').forEach((el) => {
                const name = el.getAttribute('data-name') || '';
                const match = catOk && (!q || name.indexOf(q) !== -1);
                el.classList.toggle('is-hidden', !match);
                if (match) visibleInBlock++;
            });
            const hideBlock = !catOk || (q !== '' && visibleInBlock === 0);
            block.classList.toggle('is-hidden', hideBlock);
            if (catOk && !hideBlock) anyVisible = true;
        });

        const emptyEl = document.getElementById('matFilterEmpty');
        if (emptyEl) emptyEl.classList.toggle('show', !anyVisible);
    }
    document.getElementById('matSearch')?.addEventListener('input', filterCourseMaterials);
    document.getElementById('matCategory')?.addEventListener('change', filterCourseMaterials);
    filterCourseMaterials();

    async function createAndStockItem() {
        const item_name = document.getElementById('ni_item_name').value.trim();
        const category = document.getElementById('ni_category').value;
        const unit = document.getElementById('ni_unit').value.trim() || 'pcs';
        const min_stock_level = document.getElementById('ni_min_stock_level').value;
        const description = document.getElementById('ni_description').value.trim();
        const initial_stock_qty = parseInt(document.getElementById('ni_initial_qty').value || '0', 10);
        const cost_per_item = parseFloat(document.getElementById('ni_cost_per_item').value || '0');

        if (!item_name) { alert('Item name is required'); return; }

        const fd = new FormData();
        fd.append('action', 'add_item');
        fd.append('item_name', item_name);
        fd.append('category', category);
        fd.append('unit', unit);
        fd.append('min_stock_level', min_stock_level);
        fd.append('description', description);

        try {
            const r1 = await (await fetch('inventory.php', { method: 'POST', body: fd })).json();
            if (!r1.success) { alert(r1.message || 'Error adding item'); return; }

            if (initial_stock_qty > 0) {
                if (!(cost_per_item > 0)) { alert('Cost per item is required when initial qty > 0'); return; }
                const fd2 = new FormData();
                fd2.append('action', 'stock_in');
                fd2.append('item_id', r1.id);
                fd2.append('quantity', initial_stock_qty);
                fd2.append('cost', cost_per_item);
                const r2 = await (await fetch('inventory.php', { method: 'POST', body: fd2 })).json();
                if (!r2.success) { alert(r2.message || 'Error stock-in item'); return; }
            }

            closeNewItemModal();
            location.reload();
        } catch (e) {
            alert('Network error: ' + e.message);
        }
    }

    // ── Submit validation ──────────────────────────────────────────────────
    document.getElementById('courseSaveForm').addEventListener('submit', function(e) {
        const optWith = document.querySelector('input[name="material_option"][value="with"]').checked;
        const durSel = document.getElementById('duration');
        if (durSel && durSel.value === 'custom') {
            const cd = document.getElementById('custom_dur').value.trim();
            if (!cd) { e.preventDefault(); alert('Please enter custom duration'); return; }
        }

        // If "with" is selected and admin filled shares for with-material, we store mapping.
        if (optWith) {
            const hoWith = parseFloat(document.getElementById('ho_share_with_material')?.value || '0');
            const dlcWith = parseFloat(document.getElementById('dlc_share_with_material')?.value || '0');
            const selected = document.querySelectorAll('input[name="with_material_inventory_item_ids[]"]:checked').length;
            if ((hoWith > 0 || dlcWith > 0) && selected <= 0) {
                e.preventDefault();
                alert('Select at least one Book/T-Shirt item for this course (or set HO/DLC share to 0).');
                return;
            }
        }

        // Server side handles `duration=custom` using `custom_dur`.
    });

</script>

</body>
</html>

