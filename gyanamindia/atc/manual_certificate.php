<?php
/**
 * Temporary ATC page: pick an existing student; only marks are entered by ATC.
 * Certificate generates without exam portal result.
 * Gated to allowlisted ATC codes only.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$atcId = (int)($_SESSION['atc_id'] ?? 0);

if (!$atcId) {
    die('Session error: ATC ID not found.');
}

$atcStmt = $pdo->prepare('SELECT id, name, atc_code, city, district, center_type FROM atc_centers WHERE id = ? LIMIT 1');
$atcStmt->execute([$atcId]);
$atc = $atcStmt->fetch(PDO::FETCH_ASSOC);
if (!$atc) {
    die('ATC Center not found.');
}

$_SESSION['atc_code'] = (string)($atc['atc_code'] ?? '');

if (!atcCanUseManualCourseCertificate($atcId, (string)($atc['atc_code'] ?? ''))) {
    http_response_code(403);
    die('This temporary page is not enabled for your ATC.');
}

$students = [];
try {
    $stmt = $pdo->prepare("
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
            COALESCE(NULLIF(TRIM(c.duration), ''), '3 months') AS course_duration
        FROM admissions a
        LEFT JOIN courses c ON c.course_name = a.course AND c.status = 'Active'
        WHERE a.atc_id = ? AND a.status = 'Active'
        ORDER BY a.roll_no ASC, a.id DESC
    ");
    $stmt->execute([$atcId]);
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
    $studentOptions[] = [
        'id' => (int)$s['id'],
        'name' => $fullName,
        'course' => (string)($s['course'] ?? ''),
        'reg_id' => $regId,
        'roll_no' => (string)($s['roll_no'] ?? ''),
        'mobile' => (string)($s['mobile'] ?? ''),
        'duration' => (string)($s['course_duration'] ?? '3 months'),
        'photo' => $photoUrl,
        'admission_date' => !empty($s['admission_date']) ? date('d M Y', strtotime($s['admission_date'])) : '',
    ];
}

$atcCity = trim((string)($atc['city'] ?? $atc['district'] ?? ''));
$conductedPreview = trim((string)$atc['name']) . ($atcCity !== '' ? ', ' . $atcCity : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual Certificate — ATC | Gyanam India</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📜</text></svg>">
    <style>
        .manual-wrap { max-width: 760px; }
        .manual-note {
            background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412;
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
            height: 44px; padding: 0 1.25rem; border: none; border-radius: 10px;
            font-weight: 800; font-size: .9rem; cursor: pointer; color: #fff;
            background: linear-gradient(135deg, #4361ee, #7c3aed);
        }
        .btn-preview {
            height: 44px; padding: 0 1.15rem; border-radius: 10px; font-weight: 800;
            font-size: .9rem; cursor: pointer; background: #fff; color: #334155;
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
                    <p>Temporary — generate certificate without exam</p>
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
                    Select a student — name, course, registration ID, duration and photo load automatically.
                    Enter only the marks, then generate the certificate (exam not required).
                </div>

                <span class="meta-chip">Conducted at: <?= htmlspecialchars($conductedPreview) ?></span>

                <div class="manual-card">
                    <h3>Select student</h3>
                    <?php if (empty($studentOptions)): ?>
                        <p class="empty-state">No active students found for your center.</p>
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
                        <div class="form-hint">Search by scrolling the list — <?= count($studentOptions) ?> active student(s).</div>
                    </div>

                    <form id="manualCertForm" method="post" action="../admin/generate_manual_course_certificate.php" target="_blank">
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
                                <div class="form-field">
                                    <label>Exam marks / score (40–100) <span class="req">*</span></label>
                                    <input type="number" name="score" id="scoreInput" min="40" max="100" required placeholder="Enter marks" disabled>
                                    <div class="form-hint">Only this field is entered by ATC. Grade is calculated from the score.</div>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" name="preview" value="1" class="btn-preview" id="btnPreview" disabled>Preview PDF</button>
                                <button type="submit" class="btn-gen" id="btnGenerate" disabled>Generate &amp; Download</button>
                            </div>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>
<script src="../assets/js/dashboard.js"></script>
<script>
const students = <?= json_encode($studentOptions, JSON_UNESCAPED_UNICODE) ?>;
const byId = {};
students.forEach(s => { byId[String(s.id)] = s; });

const select = document.getElementById('studentSelect');
const panel = document.getElementById('detailsPanel');
const admissionId = document.getElementById('admissionId');
const scoreInput = document.getElementById('scoreInput');
const btnPreview = document.getElementById('btnPreview');
const btnGenerate = document.getElementById('btnGenerate');
const photoPreview = document.getElementById('photoPreview');
const photoPlaceholder = document.getElementById('photoPlaceholder');

function setEnabled(on) {
    scoreInput.disabled = !on;
    btnPreview.disabled = !on;
    btnGenerate.disabled = !on;
}

if (select) {
    select.addEventListener('change', function () {
        const s = byId[this.value];
        if (!s) {
            panel.style.display = 'none';
            admissionId.value = '';
            setEnabled(false);
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
        setEnabled(true);
        scoreInput.focus();
    });
}
</script>
</body>
</html>
