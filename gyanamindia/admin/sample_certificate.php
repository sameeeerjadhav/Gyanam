<?php
/**
 * Admin: Sample Certificate — download certificate + marksheet (2-page PDF)
 * for any course, using any student for name/photo/ATC display.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo = getDBConnection();

$courses = [];
try {
    $courses = $pdo->query("
        SELECT id, course_name, course_type, duration, course_content
        FROM courses
        WHERE status = 'Active'
        ORDER BY course_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $courses = [];
}

$atcs = [];
try {
    $atcs = $pdo->query("
        SELECT id, name, atc_code, city, district
        FROM atc_centers
        WHERE status = 'Active' OR status IS NULL OR status = ''
        ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    try {
        $atcs = $pdo->query("SELECT id, name, atc_code, city, district FROM atc_centers ORDER BY name ASC")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e2) {
        $atcs = [];
    }
}

$filterAtcId = (int)($_GET['atc_id'] ?? 0);
$filterCourseId = (int)($_GET['course_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));

$students = [];
try {
    $sql = "
        SELECT
            a.id,
            a.roll_no,
            a.registration_id,
            a.first_name,
            a.middle_name,
            a.last_name,
            a.course,
            a.photo,
            a.mobile,
            a.admission_date,
            a.atc_id,
            atc.name AS atc_name,
            atc.city AS atc_city,
            atc.district AS atc_district,
            atc.atc_code
        FROM admissions a
        LEFT JOIN atc_centers atc ON atc.id = a.atc_id
        WHERE a.status = 'Active'
    ";
    $params = [];
    if ($filterAtcId > 0) {
        $sql .= ' AND a.atc_id = ?';
        $params[] = $filterAtcId;
    }
    if ($q !== '') {
        $sql .= ' AND (
            a.first_name LIKE ? OR a.last_name LIKE ? OR a.middle_name LIKE ?
            OR a.registration_id LIKE ? OR a.roll_no LIKE ? OR a.mobile LIKE ?
            OR CONCAT(COALESCE(a.first_name,\'\'),\' \',COALESCE(a.last_name,\'\')) LIKE ?
        )';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY a.first_name ASC, a.last_name ASC, a.id DESC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $students = [];
}

$studentOptions = [];
foreach ($students as $s) {
    $fullName = trim(
        ($s['first_name'] ?? '') . ' ' .
        (!empty($s['middle_name']) ? $s['middle_name'] . ' ' : '') .
        ($s['last_name'] ?? '')
    );
    $regId = trim((string)($s['registration_id'] ?? ''));
    if ($regId === '') {
        $regId = trim((string)($s['roll_no'] ?? ''));
    }
    $photoUrl = '';
    if (!empty($s['photo'])) {
        $photoUrl = '../' . ltrim((string)$s['photo'], '/');
    }
    $atcCity = trim((string)($s['atc_city'] ?? $s['atc_district'] ?? ''));
    $conducted = trim((string)($s['atc_name'] ?? '')) . ($atcCity !== '' ? ', ' . $atcCity : '');
    $studentOptions[] = [
        'id' => (int)$s['id'],
        'name' => $fullName,
        'course' => (string)($s['course'] ?? ''),
        'reg_id' => $regId,
        'roll_no' => (string)($s['roll_no'] ?? ''),
        'mobile' => (string)($s['mobile'] ?? ''),
        'photo' => $photoUrl,
        'atc' => $conducted,
        'atc_code' => (string)($s['atc_code'] ?? ''),
        'label' => $fullName . ' — ' . ($regId !== '' ? $regId : ('#' . (int)$s['id']))
            . (!empty($s['atc_name']) ? ' (' . $s['atc_name'] . ')' : ''),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sample Certificate — Admin | Gyanam India</title>
    <?php include __DIR__ . '/../includes/head_fonts.php'; ?>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📜</text></svg>">
    <style>
        .sample-wrap { max-width: 820px; }
        .sample-note {
            background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46;
            border-radius: 12px; padding: .85rem 1rem; font-size: .88rem; font-weight: 600;
            margin-bottom: 1.15rem; line-height: 1.45;
        }
        .sample-card {
            background: #fff; border: 1.5px solid var(--border-color); border-radius: 16px;
            padding: 1.35rem 1.4rem; box-shadow: 0 2px 10px rgba(15,23,42,.04);
            margin-bottom: 1rem;
        }
        .sample-card h3 { margin: 0 0 1rem; font-size: 1.05rem; font-weight: 800; }
        .form-field { margin-bottom: .9rem; }
        .form-field label {
            display: block; font-size: .78rem; font-weight: 700; color: #475569;
            margin-bottom: .3rem;
        }
        .form-field label .req { color: #dc2626; }
        .form-field input, .form-field select {
            width: 100%; height: 42px; border: 1.5px solid #e2e8f0; border-radius: 10px;
            padding: 0 .75rem; font-size: .9rem; background: #fff;
        }
        .form-field input:focus, .form-field select:focus {
            outline: none; border-color: #059669; box-shadow: 0 0 0 3px rgba(5,150,105,.15);
        }
        .form-hint { font-size: .75rem; color: #64748b; margin-top: .25rem; font-weight: 500; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: .85rem; }
        .form-actions { display: flex; flex-wrap: wrap; gap: .65rem; margin-top: 1.25rem; }
        .btn-gen {
            height: 44px; padding: 0 1.1rem; border: none; border-radius: 10px;
            font-weight: 800; font-size: .85rem; cursor: pointer; color: #fff;
            background: linear-gradient(135deg, #059669, #0d9488);
        }
        .btn-preview {
            height: 44px; padding: 0 1rem; border-radius: 10px; font-weight: 800;
            font-size: .85rem; cursor: pointer; background: #fff; color: #334155;
            border: 1.5px solid #e2e8f0;
        }
        .btn-gen:disabled, .btn-preview:disabled { opacity: .45; cursor: not-allowed; }
        .detail-grid {
            display: grid; grid-template-columns: 88px 1fr; gap: 1rem; align-items: start;
            margin-top: .5rem;
        }
        .detail-photo {
            width: 88px; height: 108px; border-radius: 10px; object-fit: cover;
            border: 1.5px solid #e2e8f0; background: #f1f5f9;
        }
        .detail-photo.placeholder {
            display: flex; align-items: center; justify-content: center;
            font-size: .7rem; font-weight: 700; color: #94a3b8; text-align: center; padding: .4rem;
        }
        .meta-chip {
            display: inline-flex; align-items: center; gap: .35rem;
            padding: .35rem .7rem; border-radius: 999px; font-size: .75rem; font-weight: 700;
            background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; margin: .2rem .35rem .2rem 0;
        }
        .filter-bar {
            display: grid; grid-template-columns: 1fr 1.2fr auto; gap: .65rem; align-items: end;
            margin-bottom: .9rem;
        }
        #detailsPanel { display: none; }
        @media (max-width: 720px) {
            .form-row, .filter-bar { grid-template-columns: 1fr; }
            .detail-grid { grid-template-columns: 1fr; }
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
                    <h2>Sample Certificate</h2>
                    <p>Certificate + marksheet in one PDF (2 pages)</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">
            <div class="sample-wrap">
                <div class="sample-note">
                    Pick any <strong>course</strong> and any <strong>student</strong>. Download a sample pack:
                    page 1 = course completion certificate, page 2 = statement of marks.
                    Uses a SAMPLE certificate number and does not issue a real certificate.
                </div>

                <form id="sampleForm" method="get" action="generate_sample_certificate_pack.php" target="_blank">
                    <div class="sample-card">
                        <h3>1. Course to showcase</h3>
                        <?php if (empty($courses)): ?>
                            <p class="form-hint">No active courses found. Add courses first.</p>
                        <?php else: ?>
                            <div class="form-field">
                                <label>Course <span class="req">*</span></label>
                                <select name="course_id" id="courseId" required>
                                    <option value="">Select course</option>
                                    <?php foreach ($courses as $c): ?>
                                        <option value="<?= (int)$c['id'] ?>"
                                            data-type="<?= htmlspecialchars((string)($c['course_type'] ?? '')) ?>"
                                            data-duration="<?= htmlspecialchars((string)($c['duration'] ?? '')) ?>"
                                            <?= $filterCourseId === (int)$c['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string)$c['course_name']) ?>
                                            <?php if (!empty($c['course_type'])): ?>
                                                (<?= htmlspecialchars((string)$c['course_type']) ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="form-hint" id="courseMetaHint">This course name appears on both PDF pages.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="sample-card">
                        <h3>2. Student to display</h3>
                        <div class="filter-bar">
                            <div class="form-field" style="margin:0">
                                <label>Filter by ATC</label>
                                <select id="atcFilter" onchange="applyStudentFilters()">
                                    <option value="0">All ATCs</option>
                                    <?php foreach ($atcs as $a): ?>
                                        <option value="<?= (int)$a['id'] ?>" <?= $filterAtcId === (int)$a['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(trim(($a['name'] ?? '') . ' (' . ($a['atc_code'] ?? '') . ')')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-field" style="margin:0">
                                <label>Search student</label>
                                <input type="search" id="studentSearch" value="<?= htmlspecialchars($q) ?>"
                                       placeholder="Name, ID, mobile…" oninput="filterStudentSelect()">
                            </div>
                            <div class="form-field" style="margin:0">
                                <label>&nbsp;</label>
                                <button type="button" class="btn-preview" style="width:100%" onclick="applyStudentFilters()">Reload list</button>
                            </div>
                        </div>

                        <div class="form-field">
                            <label>Student <span class="req">*</span></label>
                            <select name="admission_id" id="admissionId" required onchange="onStudentChange()">
                                <option value="">Select student</option>
                                <?php foreach ($studentOptions as $opt): ?>
                                    <option
                                        value="<?= (int)$opt['id'] ?>"
                                        data-name="<?= htmlspecialchars($opt['name']) ?>"
                                        data-reg="<?= htmlspecialchars($opt['reg_id']) ?>"
                                        data-course="<?= htmlspecialchars($opt['course']) ?>"
                                        data-atc="<?= htmlspecialchars($opt['atc']) ?>"
                                        data-photo="<?= htmlspecialchars($opt['photo']) ?>"
                                        data-label="<?= htmlspecialchars($opt['label']) ?>"
                                    ><?= htmlspecialchars($opt['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-hint">Student’s enrolled course is ignored — the course selected above is used on the sample.</p>
                        </div>

                        <div id="detailsPanel">
                            <div class="detail-grid">
                                <div id="photoBox" class="detail-photo placeholder">No photo</div>
                                <div>
                                    <div id="chipName" class="meta-chip"></div>
                                    <div id="chipReg" class="meta-chip"></div>
                                    <div id="chipAtc" class="meta-chip"></div>
                                    <div id="chipEnrolled" class="meta-chip"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="sample-card">
                        <h3>3. Marks &amp; download</h3>
                        <div class="form-row">
                            <div class="form-field">
                                <label>Sample score <span class="req">*</span></label>
                                <input type="number" name="score" id="score" min="40" max="100" value="82" required>
                                <p class="form-hint">Passing score 40–100 (default 82 / A+).</p>
                            </div>
                            <div class="form-field">
                                <label>Issue date</label>
                                <input type="date" name="issue_date" id="issueDate" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn-gen" id="btnDownload">Download 2-page PDF</button>
                            <button type="button" class="btn-preview" id="btnPreview" onclick="previewPack()">Preview in browser</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<script src="../assets/js/dashboard.js"></script>
<script>
(function () {
    const form = document.getElementById('sampleForm');
    const courseSel = document.getElementById('courseId');
    const admissionSel = document.getElementById('admissionId');
    const details = document.getElementById('detailsPanel');

    function ensurePhotoBox() {
        let box = document.getElementById('photoBox');
        if (!box) return null;
        if (box.tagName === 'IMG') {
            const div = document.createElement('div');
            div.id = 'photoBox';
            div.className = 'detail-photo placeholder';
            box.replaceWith(div);
            box = div;
        }
        return box;
    }

    window.onStudentChange = function () {
        const opt = admissionSel.options[admissionSel.selectedIndex];
        if (!opt || !opt.value) {
            details.style.display = 'none';
            return;
        }
        details.style.display = 'block';
        document.getElementById('chipName').textContent = opt.dataset.name || '';
        document.getElementById('chipReg').textContent = 'ID: ' + (opt.dataset.reg || '—');
        document.getElementById('chipAtc').textContent = opt.dataset.atc || 'ATC —';
        document.getElementById('chipEnrolled').textContent = 'Enrolled: ' + (opt.dataset.course || '—');
        const photo = opt.dataset.photo || '';
        const box = ensurePhotoBox();
        if (!box) return;
        if (photo) {
            const img = document.createElement('img');
            img.id = 'photoBox';
            img.src = photo;
            img.alt = 'Photo';
            img.className = 'detail-photo';
            box.replaceWith(img);
        } else {
            box.className = 'detail-photo placeholder';
            box.textContent = 'No photo';
        }
    };

    window.filterStudentSelect = function () {
        const q = (document.getElementById('studentSearch').value || '').toLowerCase().trim();
        const opts = admissionSel.options;
        for (let i = 1; i < opts.length; i++) {
            const label = (opts[i].dataset.label || opts[i].textContent || '').toLowerCase();
            opts[i].hidden = q !== '' && label.indexOf(q) === -1;
        }
    };

    window.applyStudentFilters = function () {
        const atc = document.getElementById('atcFilter').value || '0';
        const q = document.getElementById('studentSearch').value || '';
        const course = courseSel ? courseSel.value : '';
        const params = new URLSearchParams();
        if (atc !== '0') params.set('atc_id', atc);
        if (q) params.set('q', q);
        if (course) params.set('course_id', course);
        window.location = 'sample_certificate.php?' + params.toString();
    };

    window.previewPack = function () {
        if (!form.reportValidity()) return;
        const url = new URL(form.action, window.location.href);
        const fd = new FormData(form);
        fd.forEach((v, k) => url.searchParams.set(k, v));
        url.searchParams.set('preview', '1');
        window.open(url.toString(), '_blank');
    };

    if (courseSel) {
        courseSel.addEventListener('change', function () {
            const opt = courseSel.options[courseSel.selectedIndex];
            const hint = document.getElementById('courseMetaHint');
            if (!opt || !opt.value) {
                hint.textContent = 'This course name appears on both PDF pages.';
                return;
            }
            const type = opt.dataset.type || '';
            const dur = opt.dataset.duration || '';
            hint.textContent = [type ? ('Type: ' + type) : '', dur ? ('Duration: ' + dur) : '']
                .filter(Boolean).join(' · ') || 'This course name appears on both PDF pages.';
        });
        courseSel.dispatchEvent(new Event('change'));
    }
})();
</script>
</body>
</html>
