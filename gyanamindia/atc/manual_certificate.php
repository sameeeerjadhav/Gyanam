<?php
/**
 * Temporary ATC page: manually enter student details and generate a course
 * completion certificate without an exam result.
 * Gated to allowlisted ATC codes only (see atcCanUseManualCourseCertificate).
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

$courses = [];
try {
    $cst = $pdo->query("SELECT course_name, duration, course_type FROM courses WHERE status = 'Active' ORDER BY course_name ASC");
    $courses = $cst->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $courses = [];
}

$atcCity = trim((string)($atc['city'] ?? $atc['district'] ?? ''));
$conductedPreview = trim((string)$atc['name']) . ($atcCity !== '' ? ', ' . $atcCity : '');
$courseDurations = [];
foreach ($courses as $c) {
    $courseDurations[$c['course_name']] = trim((string)($c['duration'] ?? '')) ?: '3 months';
}
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
        .manual-wrap { max-width: 720px; }
        .manual-note {
            background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412;
            border-radius: 12px; padding: .85rem 1rem; font-size: .88rem; font-weight: 600;
            margin-bottom: 1.15rem; line-height: 1.45;
        }
        .manual-card {
            background: #fff; border: 1.5px solid var(--border-color); border-radius: 16px;
            padding: 1.35rem 1.4rem; box-shadow: 0 2px 10px rgba(15,23,42,.04);
        }
        .manual-card h3 { margin: 0 0 1rem; font-size: 1.05rem; font-weight: 800; }
        .form-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: .9rem 1rem;
        }
        .form-grid .full { grid-column: 1 / -1; }
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
        .meta-chip {
            display: inline-flex; align-items: center; gap: .35rem;
            padding: .35rem .7rem; border-radius: 999px; font-size: .75rem; font-weight: 700;
            background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; margin-bottom: 1rem;
        }
        @media (max-width: 640px) {
            .form-grid { grid-template-columns: 1fr; }
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
                    Temporary tool for your center only. Fill student details and generate a course completion certificate
                    even if the student has not given the exam. Use carefully — this bypasses the normal exam check.
                </div>

                <span class="meta-chip">Conducted at: <?= htmlspecialchars($conductedPreview) ?></span>

                <div class="manual-card">
                    <h3>Student &amp; course details</h3>
                    <form id="manualCertForm" method="post" action="../admin/generate_manual_course_certificate.php" enctype="multipart/form-data" target="_blank">
                        <div class="form-grid">
                            <div class="form-field full">
                                <label>Student full name <span class="req">*</span></label>
                                <input type="text" name="student_name" required placeholder="e.g. HITESH HIMMAT SAPKALE" autocomplete="off">
                            </div>
                            <div class="form-field full">
                                <label>Course <span class="req">*</span></label>
                                <select name="course_name" id="courseSelect" required>
                                    <option value="">Select course</option>
                                    <?php foreach ($courses as $c): ?>
                                        <option value="<?= htmlspecialchars($c['course_name']) ?>"
                                                data-duration="<?= htmlspecialchars($courseDurations[$c['course_name']] ?? '3 months') ?>">
                                            <?= htmlspecialchars($c['course_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-field">
                                <label>Registration / Roll ID</label>
                                <input type="text" name="reg_id" placeholder="Optional — auto if blank" autocomplete="off">
                                <div class="form-hint">Used on certificate number. Leave blank to auto-generate.</div>
                            </div>
                            <div class="form-field">
                                <label>Exam score (40–100) <span class="req">*</span></label>
                                <input type="number" name="score" min="40" max="100" value="70" required>
                                <div class="form-hint">Grade is calculated from score (A++ … C).</div>
                            </div>
                            <div class="form-field">
                                <label>Date of issue <span class="req">*</span></label>
                                <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="form-field">
                                <label>Course duration</label>
                                <input type="text" name="duration" id="durationInput" placeholder="e.g. 3 months" autocomplete="off">
                                <div class="form-hint">Auto-filled from course when available.</div>
                            </div>
                            <div class="form-field full">
                                <label>Student photo (optional)</label>
                                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="preview" value="1" class="btn-preview">Preview PDF</button>
                            <button type="submit" class="btn-gen">Generate &amp; Download</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>
<script src="../assets/js/dashboard.js"></script>
<script>
const durations = <?= json_encode($courseDurations, JSON_UNESCAPED_UNICODE) ?>;
const courseSelect = document.getElementById('courseSelect');
const durationInput = document.getElementById('durationInput');
courseSelect.addEventListener('change', function () {
    const name = this.value;
    if (name && durations[name] && !durationInput.value) {
        durationInput.value = durations[name];
    } else if (name && durations[name]) {
        durationInput.value = durations[name];
    }
});
</script>
</body>
</html>
