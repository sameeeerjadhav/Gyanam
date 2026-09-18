<?php
/**
 * Admin-only: pick a student and enter marks to issue certificate/marksheet
 * without an exam portal result.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());

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
$filterCourseType = trim((string)($_GET['course_type'] ?? ''));
$filterCourseName = trim((string)($_GET['course_name'] ?? ''));
$selectedAtc = null;
foreach ($atcs as $a) {
    if ((int)$a['id'] === $filterAtcId) {
        $selectedAtc = $a;
        break;
    }
}

$students = [];
if ($filterAtcId > 0) {
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
                COALESCE(NULLIF(TRIM(c.duration), ''), '3 months') AS course_duration,
                c.course_type AS course_type,
                atc.name AS atc_name,
                atc.city AS atc_city,
                atc.district AS atc_district
            FROM admissions a
            LEFT JOIN courses c ON c.course_name = a.course AND c.status = 'Active'
            LEFT JOIN atc_centers atc ON atc.id = a.atc_id
            WHERE a.atc_id = ? AND a.status = 'Active'
        ";
        $params = [$filterAtcId];
        if ($filterCourseType !== '') {
            $sql .= ' AND UPPER(TRIM(COALESCE(c.course_type, \'\'))) = ?';
            $params[] = strtoupper($filterCourseType);
        }
        if ($filterCourseName !== '') {
            $sql .= ' AND a.course = ?';
            $params[] = $filterCourseName;
        }
        $sql .= ' ORDER BY a.roll_no ASC, a.id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $students = [];
    }
}

$courseTypes = function_exists('masterCourseTypes') ? masterCourseTypes() : ['IT', 'Typing', 'Abacus', 'Vedic Maths'];
$courseNames = [];
if ($filterAtcId > 0) {
    try {
        $cnSql = "
            SELECT DISTINCT a.course
            FROM admissions a
            LEFT JOIN courses c ON c.course_name = a.course AND c.status = 'Active'
            WHERE a.atc_id = ? AND a.status = 'Active' AND a.course IS NOT NULL AND a.course != ''
        ";
        $cnParams = [$filterAtcId];
        if ($filterCourseType !== '') {
            $cnSql .= ' AND UPPER(TRIM(COALESCE(c.course_type, \'\'))) = ?';
            $cnParams[] = strtoupper($filterCourseType);
        }
        $cnSql .= ' ORDER BY a.course ASC';
        $cnSt = $pdo->prepare($cnSql);
        $cnSt->execute($cnParams);
        $courseNames = $cnSt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Exception $e) {
        $courseNames = [];
    }
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
    $speeds = typingMarksheetSpeedDefaults($s['course'] ?? '');
    $isTyping = isTypingCourse($s['course_type'] ?? null, $s['course'] ?? null);
    $typingSaved = $isTyping ? getAdmissionTypingMarks($pdo, (int)$s['id'], $speeds['wpm'], $speeds['kph']) : null;
    $itSaved = isGiitItCourse($s['course_type'] ?? null, $s['course'] ?? null)
        ? getAdmissionAtcMarks($pdo, (int)$s['id'])
        : null;
    $studentOptions[] = [
        'id' => (int)$s['id'],
        'name' => $fullName,
        'course' => (string)($s['course'] ?? ''),
        'course_type' => (string)($s['course_type'] ?? ''),
        'reg_id' => $regId,
        'roll_no' => (string)($s['roll_no'] ?? ''),
        'mobile' => (string)($s['mobile'] ?? ''),
        'duration' => (string)($s['course_duration'] ?? '3 months'),
        'photo' => $photoUrl,
        'admission_date' => !empty($s['admission_date']) ? date('d M Y', strtotime($s['admission_date'])) : '',
        'conducted_at' => $conducted,
        'is_it' => isGiitItCourse($s['course_type'] ?? null, $s['course'] ?? null),
        'is_typing' => $isTyping,
        'wpm' => $speeds['wpm'],
        'kph' => $speeds['kph'],
        'typing_by_key' => $typingSaved['by_key'] ?? new stdClass(),
        'saved_exam_40' => $itSaved !== null && isset($itSaved['exam_marks']) && $itSaved['exam_marks'] !== null
            ? (int)$itSaved['exam_marks'] : null,
        'saved_atc_60' => $itSaved !== null ? (int)$itSaved['atc_marks'] : null,
    ];
}

$typingPartsJson = typingMarksheetParticulars(30, 9000);

$conductedPreview = '';
if ($selectedAtc) {
    $city = trim((string)($selectedAtc['city'] ?? $selectedAtc['district'] ?? ''));
    $conductedPreview = trim((string)$selectedAtc['name']) . ($city !== '' ? ', ' . $city : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual Certificate — Admin | Gyanam India</title>
        <?php include __DIR__ . '/../includes/head_fonts.php'; ?>
<link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📜</text></svg>">
    <style>
        .manual-wrap { max-width: 780px; }
        .manual-note {
            background: #eff6ff; border: 1px solid #bfdbfe; color: #1e3a8a;
            border-radius: 12px; padding: .85rem 1rem; font-size: .88rem; font-weight: 600;
            margin-bottom: 1.15rem; line-height: 1.45;
        }
        .manual-card {
            background: #fff; border: 1.5px solid var(--border-color); border-radius: 16px;
            padding: 1.35rem 1.4rem; box-shadow: 0 2px 10px rgba(15,23,42,.04);
            margin-bottom: 1rem;
        }
        .manual-card h3 { margin: 0 0 1rem; font-size: 1.05rem; font-weight: 800; }
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
            outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.15);
        }
        .form-field input[readonly] { background: #f8fafc; color: #334155; }
        .form-hint { font-size: .75rem; color: #64748b; margin-top: .25rem; font-weight: 500; }
        .form-actions { display: flex; flex-wrap: wrap; gap: .65rem; margin-top: 1.25rem; }
        .btn-gen {
            height: 44px; padding: 0 1.1rem; border: none; border-radius: 10px;
            font-weight: 800; font-size: .85rem; cursor: pointer; color: #fff;
            background: linear-gradient(135deg, #4361ee, #7c3aed);
        }
        .btn-preview {
            height: 44px; padding: 0 1rem; border-radius: 10px; font-weight: 800;
            font-size: .85rem; cursor: pointer; background: #fff; color: #334155;
            border: 1.5px solid #e2e8f0;
        }
        .btn-gen:disabled, .btn-preview:disabled { opacity: .45; cursor: not-allowed; }
        .meta-chip {
            display: inline-flex; align-items: center; gap: .35rem;
            padding: .35rem .7rem; border-radius: 999px; font-size: .75rem; font-weight: 700;
            background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; margin-bottom: 1rem;
        }
        .detail-grid {
            display: grid; grid-template-columns: 88px 1fr; gap: 1rem; align-items: start;
        }
        .detail-photo {
            width: 88px; height: 108px; border-radius: 10px; object-fit: cover;
            border: 1.5px solid #e2e8f0; background: #f1f5f9;
        }
        .detail-photo.placeholder {
            display: flex; align-items: center; justify-content: center;
            font-size: .7rem; font-weight: 700; color: #94a3b8; text-align: center; padding: .4rem;
        }
        .detail-fields {
            display: grid; grid-template-columns: 1fr 1fr; gap: .75rem 1rem;
        }
        .detail-fields .full { grid-column: 1 / -1; }
        .marks-box {
            border: 1.5px solid #c7d2fe; background: #eef2ff; border-radius: 12px;
            padding: 1rem 1.1rem; margin-top: .25rem;
        }
        .marks-box label { color: #3730a3; }
        #typingFields { grid-template-columns: 1fr; }
        .empty-state {
            color: #64748b; font-weight: 600; font-size: .9rem; padding: .5rem 0;
        }
        #detailsPanel { display: none; }
        @media (max-width: 640px) {
            .detail-grid { grid-template-columns: 1fr; }
            .detail-fields { grid-template-columns: 1fr; }
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
                    <h2>Manual Certificate</h2>
                    <p>Admin only — generate certificate without exam</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">
            <div class="manual-wrap">
                <div class="manual-note">
                    Select an ATC and student. Enter only the marks, then generate
                    <strong>Certificate</strong> and <strong>Marksheet</strong> (exam not required).
                    This tool is available to Admin only.
                </div>

                <div class="manual-card">
                    <h3>1. Select ATC &amp; filters</h3>
                    <form method="get" class="detail-fields">
                        <div class="form-field full">
                            <label>ATC centre <span class="req">*</span></label>
                            <select name="atc_id" onchange="this.form.submit()">
                                <option value="">Select ATC</option>
                                <?php foreach ($atcs as $a): ?>
                                    <option value="<?= (int)$a['id'] ?>" <?= $filterAtcId === (int)$a['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(trim(($a['name'] ?? '') . ' (' . ($a['atc_code'] ?? '') . ')')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($filterAtcId > 0): ?>
                        <div class="form-field">
                            <label>Course type</label>
                            <select name="course_type" onchange="this.form.submit()">
                                <option value="">All types</option>
                                <?php foreach ($courseTypes as $ct): ?>
                                    <option value="<?= htmlspecialchars($ct) ?>" <?= strcasecmp($filterCourseType, $ct) === 0 ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($ct) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Course name</label>
                            <select name="course_name" onchange="this.form.submit()">
                                <option value="">All courses</option>
                                <?php foreach ($courseNames as $cn): ?>
                                    <option value="<?= htmlspecialchars((string)$cn) ?>" <?= $filterCourseName === (string)$cn ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string)$cn) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </form>
                    <?php if ($conductedPreview !== ''): ?>
                        <span class="meta-chip" style="margin-top:1rem;margin-bottom:0">Conducted at: <?= htmlspecialchars($conductedPreview) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($filterAtcId > 0): ?>
                <div class="manual-card">
                    <h3>2. Select student</h3>
                    <?php if (empty($studentOptions)): ?>
                        <p class="empty-state">No active students found for this ATC.</p>
                    <?php else: ?>
                    <div class="form-field" style="margin-bottom:1rem">
                        <label>Student <span class="req">*</span></label>
                        <select id="studentSelect">
                            <option value="">Select student</option>
                            <?php foreach ($studentOptions as $opt): ?>
                                <option value="<?= (int)$opt['id'] ?>">
                                    <?= htmlspecialchars($opt['name']) ?>
                                    — <?= htmlspecialchars($opt['course']) ?>
                                    (<?= htmlspecialchars($opt['reg_id'] ?: ('Roll ' . $opt['roll_no'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-hint"><?= count($studentOptions) ?> active student(s) at this centre.</div>
                    </div>

                    <form id="manualCertForm" method="post" action="generate_manual_course_certificate.php" target="_blank">
                        <input type="hidden" name="admission_id" id="admissionId" value="">

                        <div id="detailsPanel">
                            <h3 style="margin-top:.25rem">Student details (auto-filled)</h3>
                            <div class="detail-grid">
                                <div>
                                    <img id="photoPreview" class="detail-photo" alt="Photo" style="display:none">
                                    <div id="photoPlaceholder" class="detail-photo placeholder">No photo</div>
                                </div>
                                <div class="detail-fields">
                                    <div class="form-field full">
                                        <label>Student name</label>
                                        <input type="text" id="dispName" readonly>
                                    </div>
                                    <div class="form-field full">
                                        <label>Course</label>
                                        <input type="text" id="dispCourse" readonly>
                                    </div>
                                    <div class="form-field">
                                        <label>Registration / Roll ID</label>
                                        <input type="text" id="dispReg" readonly>
                                    </div>
                                    <div class="form-field">
                                        <label>Duration</label>
                                        <input type="text" id="dispDuration" readonly>
                                    </div>
                                    <div class="form-field">
                                        <label>Mobile</label>
                                        <input type="text" id="dispMobile" readonly>
                                    </div>
                                    <div class="form-field">
                                        <label>Admission date</label>
                                        <input type="text" id="dispAdmDate" readonly>
                                    </div>
                                </div>
                            </div>

                            <div class="marks-box" style="margin-top:1.1rem">
                                <div id="itMarksWrap" style="display:none">
                                    <div class="form-field" style="margin-bottom:.75rem">
                                        <label>Main Exam marks /40 <span class="req">*</span></label>
                                        <input type="number" name="exam_40" id="exam40Input" min="0" max="40" placeholder="0–40" disabled>
                                    </div>
                                    <div class="form-field" style="margin-bottom:.75rem">
                                        <label>ATC internal marks /60 <span class="req">*</span></label>
                                        <input type="number" name="atc_marks" id="atc60Input" min="0" max="60" placeholder="0–60" disabled>
                                    </div>
                                    <div class="form-hint" style="margin-bottom:.75rem">IT total = Exam/40 + ATC/60 (auto-filled below).</div>
                                </div>
                                <div id="typingMarksWrap" style="display:none;margin-bottom:.75rem">
                                    <div class="form-hint" style="margin-bottom:.65rem">Typing particulars (same maxes for all typing courses). Total auto-fills below.</div>
                                    <div id="typingFields" class="detail-fields"></div>
                                </div>
                                <div class="form-field">
                                    <label>Total marks / score (40–100) <span class="req">*</span></label>
                                    <input type="number" name="score" id="scoreInput" min="40" max="100" required placeholder="Enter marks" disabled>
                                    <div class="form-hint" id="scoreHint">Only this field is entered by Admin. Grade is calculated from the score.</div>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" name="preview" value="1"
                                        formaction="generate_manual_course_certificate.php"
                                        class="btn-preview" id="btnPreviewCert" disabled>Preview Certificate</button>
                                <button type="submit"
                                        formaction="generate_manual_course_certificate.php"
                                        class="btn-gen" id="btnDownloadCert" disabled>Download Certificate</button>
                                <button type="submit" name="preview" value="1"
                                        formaction="generate_manual_marksheet.php"
                                        class="btn-preview" id="btnPreviewMarks" disabled>Preview Marksheet</button>
                                <button type="submit"
                                        formaction="generate_manual_marksheet.php"
                                        class="btn-gen" id="btnDownloadMarks" disabled>Download Marksheet</button>
                            </div>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<script src="../assets/js/dashboard.js"></script>
<script>
const students = <?= json_encode($studentOptions, JSON_UNESCAPED_UNICODE) ?>;
const typingPartsBase = <?= json_encode($typingPartsJson, JSON_UNESCAPED_UNICODE) ?>;
const byId = {};
students.forEach(s => { byId[String(s.id)] = s; });

const select = document.getElementById('studentSelect');
const panel = document.getElementById('detailsPanel');
const admissionId = document.getElementById('admissionId');
const scoreInput = document.getElementById('scoreInput');
const exam40Input = document.getElementById('exam40Input');
const atc60Input = document.getElementById('atc60Input');
const itMarksWrap = document.getElementById('itMarksWrap');
const typingMarksWrap = document.getElementById('typingMarksWrap');
const typingFields = document.getElementById('typingFields');
const scoreHint = document.getElementById('scoreHint');
const actionBtns = [
    document.getElementById('btnPreviewCert'),
    document.getElementById('btnDownloadCert'),
    document.getElementById('btnPreviewMarks'),
    document.getElementById('btnDownloadMarks'),
];
const photoPreview = document.getElementById('photoPreview');
const photoPlaceholder = document.getElementById('photoPlaceholder');

function typingPartsForStudent(s) {
    const wpm = s.wpm || 30;
    const kph = s.kph || 9000;
    return typingPartsBase.map(p => {
        let label = p.label;
        if (p.key === 'typing_speed') label = 'Computer Typing Speed @ ' + wpm + ' WPM English';
        if (p.key === 'data_entry') label = 'Data Entry Speed (Key depressed per hour) ' + kph + ' KPH';
        return { key: p.key, label, max: p.max };
    });
}

function syncItTotal() {
    if (!exam40Input || !atc60Input || !scoreInput) return;
    const e = parseInt(exam40Input.value, 10);
    const a = parseInt(atc60Input.value, 10);
    if (!Number.isNaN(e) && !Number.isNaN(a)) {
        scoreInput.value = String(Math.max(0, Math.min(100, e + a)));
    }
}

function syncTypingTotal() {
    if (!typingFields || !scoreInput) return;
    const inputs = typingFields.querySelectorAll('input[data-typing-key]');
    let sum = 0;
    let allFilled = inputs.length > 0;
    inputs.forEach(inp => {
        const v = parseInt(inp.value, 10);
        if (Number.isNaN(v)) allFilled = false;
        else sum += v;
    });
    if (allFilled) scoreInput.value = String(Math.max(0, Math.min(100, sum)));
}

function buildTypingFields(s) {
    if (!typingFields) return;
    typingFields.innerHTML = '';
    const parts = typingPartsForStudent(s);
    const saved = s.typing_by_key || {};
    parts.forEach(p => {
        const wrap = document.createElement('div');
        wrap.className = 'form-field';
        const lab = document.createElement('label');
        lab.innerHTML = p.label + ' <span class="req">*</span> <span style="font-weight:600;color:#64748b">(max ' + p.max + ')</span>';
        const inp = document.createElement('input');
        inp.type = 'number';
        inp.name = 'typing_marks[' + p.key + ']';
        inp.min = '0';
        inp.max = String(p.max);
        inp.required = true;
        inp.dataset.typingKey = p.key;
        if (saved[p.key] !== undefined && saved[p.key] !== null && saved[p.key] !== '') {
            inp.value = String(saved[p.key]);
        }
        inp.addEventListener('input', syncTypingTotal);
        wrap.appendChild(lab);
        wrap.appendChild(inp);
        typingFields.appendChild(wrap);
    });
    syncTypingTotal();
}

function setEnabled(on, isIt, isTyping) {
    if (!scoreInput) return;
    if (itMarksWrap) itMarksWrap.style.display = isIt ? 'block' : 'none';
    if (typingMarksWrap) typingMarksWrap.style.display = isTyping ? 'block' : 'none';
    if (exam40Input) {
        exam40Input.disabled = !on || !isIt;
        exam40Input.required = !!(on && isIt);
        if (!isIt) exam40Input.value = '';
    }
    if (atc60Input) {
        atc60Input.disabled = !on || !isIt;
        atc60Input.required = !!(on && isIt);
        if (!isIt) atc60Input.value = '';
    }
    if (!isTyping && typingFields) typingFields.innerHTML = '';
    const autoTotal = !!(isIt || isTyping);
    scoreInput.disabled = !on || autoTotal;
    scoreInput.readOnly = autoTotal;
    if (scoreHint) {
        if (isIt) scoreHint.textContent = 'IT total is Exam/40 + ATC/60 (read-only).';
        else if (isTyping) scoreHint.textContent = 'Typing total is sum of particulars (read-only).';
        else scoreHint.textContent = 'Only this field is entered by Admin. Grade is calculated from the score.';
    }
    actionBtns.forEach(btn => { if (btn) btn.disabled = !on; });
}

if (exam40Input) exam40Input.addEventListener('input', syncItTotal);
if (atc60Input) atc60Input.addEventListener('input', syncItTotal);

if (select) {
    select.addEventListener('change', function () {
        const s = byId[this.value];
        if (!s) {
            panel.style.display = 'none';
            admissionId.value = '';
            setEnabled(false, false, false);
            return;
        }
        admissionId.value = String(s.id);
        document.getElementById('dispName').value = s.name;
        document.getElementById('dispCourse').value = s.course;
        document.getElementById('dispReg').value = s.reg_id || ('Roll ' + s.roll_no);
        document.getElementById('dispDuration').value = s.duration || '3 months';
        document.getElementById('dispMobile').value = s.mobile || '—';
        document.getElementById('dispAdmDate').value = s.admission_date || '—';
        if (s.photo) {
            photoPreview.src = s.photo;
            photoPreview.style.display = 'block';
            photoPlaceholder.style.display = 'none';
        } else {
            photoPreview.removeAttribute('src');
            photoPreview.style.display = 'none';
            photoPlaceholder.style.display = 'flex';
        }
        panel.style.display = 'block';
        if (s.is_typing) buildTypingFields(s);
        setEnabled(true, !!s.is_it, !!s.is_typing);
        if (s.is_it) {
            if (exam40Input && s.saved_exam_40 !== null && s.saved_exam_40 !== undefined) {
                exam40Input.value = String(s.saved_exam_40);
            }
            if (atc60Input && s.saved_atc_60 !== null && s.saved_atc_60 !== undefined) {
                atc60Input.value = String(s.saved_atc_60);
            }
            syncItTotal();
            if (exam40Input) exam40Input.focus();
        } else if (s.is_typing) {
            const first = typingFields.querySelector('input');
            if (first) first.focus();
        } else scoreInput.focus();
    });
}
</script>
</body>
</html>
