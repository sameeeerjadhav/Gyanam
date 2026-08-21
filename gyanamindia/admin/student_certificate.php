<?php
/**
 * Gyanam Portal — Admin: Student Certificate
 * Professional certificate print page.
 * Template can be replaced later — data binding is already wired.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['Admin']);

$pdo = getDBConnection();
$id  = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) die('Invalid student ID.');

// Fetch student + ATC + DLC
try {
    $stmt = $pdo->prepare(
        "SELECT a.*,
                CONCAT(a.first_name,' ',COALESCE(NULLIF(TRIM(a.middle_name),''),''),' ',a.last_name) AS full_name,
                atc.name AS atc_name, atc.atc_code, atc.city AS atc_city, atc.state AS atc_state,
                dlc.name AS dlc_name
         FROM admissions a
         LEFT JOIN atc_centers atc ON atc.id = a.atc_id
         LEFT JOIN dlc_offices dlc ON dlc.id = atc.dlc_id
         WHERE a.id = ?"
    );
    $stmt->execute([$id]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $s = null;
}

if (!$s) die('Student not found.');

$fullName    = trim(preg_replace('/\s+/', ' ', $s['full_name']));
$regId       = $s['registration_id'] ?: ('GYANAM' . $s['id']);
$rollNo      = $s['roll_no'] ?? '';
$course      = $s['course']  ?? '';
$atcName     = $s['atc_name'] ?? '';
$atcCode     = $s['atc_code'] ?? '';
$dlcName     = $s['dlc_name'] ?? '';
$admDate     = !empty($s['admission_date']) ? date('d M Y', strtotime($s['admission_date'])) : date('d M Y');
$issueDate   = date('d M Y');          // Certificate issue date = today
$issueYear   = date('Y');
$certNo      = 'GYANAM-CERT-' . $issueYear . '-' . str_pad($s['id'], 5, '0', STR_PAD_LEFT);

// Photo path
$photoPath = !empty($s['photo']) ? '../' . htmlspecialchars($s['photo']) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Certificate — <?= htmlspecialchars($fullName) ?> | Gyanam India Educational Services</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700;900&family=Sora:wght@300;400;500;600;700&family=Great+Vibes&display=swap" rel="stylesheet">
<style>
/* ── Reset ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    background: #e8e4d8;
    font-family: 'Sora', sans-serif;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 2rem 1rem;
}

/* ── Print controls (hidden on print) ── */
.print-controls {
    display: flex; gap: 1rem; margin-bottom: 1.5rem; align-items: center;
}
.ctrl-btn {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .65rem 1.4rem; border-radius: 10px; font-size: .875rem;
    font-weight: 700; font-family: 'Sora', sans-serif; cursor: pointer;
    border: none; transition: all .18s;
}
.ctrl-print { background: #4361ee; color: #fff; box-shadow: 0 4px 14px rgba(67,97,238,.3); }
.ctrl-print:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(67,97,238,.35); }
.ctrl-back  { background: #fff; color: #374151; border: 1.5px solid #e5e7eb; }
.ctrl-back:hover { background: #f9fafb; }
.ctrl-back  svg, .ctrl-print svg { width: 15px; height: 15px; }

/* ── Certificate card ── */
.certificate {
    width: 210mm;
    min-height: 148mm;
    background: #fff;
    position: relative;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,.18);
    page-break-inside: avoid;
}

/* Ornamental border */
.cert-border-outer {
    position: absolute; inset: 0;
    border: 14px solid #c9a84c;
    pointer-events: none; z-index: 10;
}
.cert-border-inner {
    position: absolute; inset: 8px;
    border: 3px solid #c9a84c;
    pointer-events: none; z-index: 10;
}

/* Corner ornaments */
.corner {
    position: absolute; width: 60px; height: 60px; z-index: 11;
}
.corner-tl { top: 14px; left: 14px; }
.corner-tr { top: 14px; right: 14px; transform: scaleX(-1); }
.corner-bl { bottom: 14px; left: 14px; transform: scaleY(-1); }
.corner-br { bottom: 14px; right: 14px; transform: scale(-1,-1); }

/* Background watermark */
.cert-watermark {
    position: absolute; top: 50%; left: 50%;
    transform: translate(-50%, -50%) rotate(-15deg);
    font-family: 'Playfair Display', serif;
    font-size: 90px; font-weight: 900;
    color: rgba(201, 168, 76, 0.07);
    white-space: nowrap; pointer-events: none; z-index: 1;
    letter-spacing: 4px;
}

/* Content wrapper */
.cert-content {
    position: relative; z-index: 5;
    padding: 28px 40px;
    display: flex; flex-direction: column; align-items: center;
    min-height: 148mm;
}

/* Header */
.cert-org-logo {
    width: 64px; height: 64px; object-fit: contain; margin-bottom: 4px;
}
.cert-org-logo-ph {
    width: 64px; height: 64px; border-radius: 50%;
    background: linear-gradient(135deg, #4361ee, #7c3aed);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem; font-weight: 900; color: #fff; margin-bottom: 4px;
}
.cert-org-name {
    font-family: 'Playfair Display', serif;
    font-size: 1.2rem; font-weight: 900;
    color: #1a1a2e; letter-spacing: .05em;
    text-align: center; margin-bottom: 2px;
}
.cert-org-sub {
    font-size: .65rem; color: #6b7280; text-align: center;
    text-transform: uppercase; letter-spacing: .12em;
}

/* Divider */
.cert-divider {
    width: 80%; height: 2px; margin: 10px auto;
    background: linear-gradient(90deg, transparent, #c9a84c 20%, #c9a84c 80%, transparent);
}

/* Certificate title */
.cert-title {
    font-family: 'Playfair Display', serif;
    font-size: 1.75rem; font-weight: 900;
    color: #1a1a2e; letter-spacing: .08em;
    text-transform: uppercase; text-align: center;
    margin-bottom: 6px;
}
.cert-subtitle {
    font-size: .75rem; color: #6b7280;
    text-transform: uppercase; letter-spacing: .15em;
    margin-bottom: 12px;
}

/* Main body */
.cert-body {
    display: flex; align-items: flex-start; gap: 24px;
    width: 100%; margin-bottom: 16px;
}
.cert-photo-wrap { flex-shrink: 0; }
.cert-photo {
    width: 90px; height: 110px; object-fit: cover;
    border: 3px solid #c9a84c; border-radius: 4px;
}
.cert-photo-ph {
    width: 90px; height: 110px;
    border: 3px solid #c9a84c; border-radius: 4px;
    background: #f3f4f6;
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem; color: #d1d5db;
}

.cert-text-block {
    flex: 1; padding-top: 4px;
}
.cert-intro {
    font-size: .8rem; color: #4b5563; line-height: 1.7; margin-bottom: 10px;
}
.cert-name {
    font-family: 'Playfair Display', serif;
    font-size: 1.55rem; font-weight: 900;
    color: #4361ee; letter-spacing: .02em;
    border-bottom: 2px solid #c9a84c;
    display: inline-block; padding-bottom: 2px; margin-bottom: 10px;
}
.cert-details {
    display: grid; grid-template-columns: 1fr 1fr; gap: 6px 20px;
    margin-bottom: 10px;
}
.cert-field { display: flex; flex-direction: column; }
.cert-field-label {
    font-size: .6rem; font-weight: 800; color: #9ca3af;
    text-transform: uppercase; letter-spacing: .1em;
}
.cert-field-value {
    font-size: .8rem; font-weight: 700; color: #1f2937;
}

.cert-achievement {
    font-size: .78rem; color: #4b5563; line-height: 1.6;
    font-style: italic; margin-top: 4px;
}

/* Footer */
.cert-footer {
    display: flex; justify-content: space-between; align-items: flex-end;
    width: 100%; margin-top: auto; padding-top: 12px;
    border-top: 1px solid #e5e7eb;
}
.cert-sign-block { display: flex; flex-direction: column; align-items: center; gap: 4px; }
.cert-sign-line {
    width: 110px; border-top: 2px solid #374151; margin-bottom: 3px;
}
.cert-sign-name { font-size: .68rem; font-weight: 700; color: #374151; text-align: center; }
.cert-sign-title { font-size: .6rem; color: #9ca3af; text-align: center; }

.cert-stamp-area {
    width: 80px; height: 80px; border: 2px dashed #d1d5db; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .58rem; color: #d1d5db; text-align: center; line-height: 1.3;
}

.cert-meta {
    text-align: center; font-size: .62rem; color: #9ca3af; line-height: 1.5;
}
.cert-meta strong { color: #374151; }

/* ── Print overrides ── */
@page {
    size: A4 landscape;
    margin: 10mm;
}
@media print {
    body { background: #fff; padding: 0; }
    .print-controls { display: none !important; }
    .certificate { box-shadow: none; width: 100%; }
}
</style>
</head>
<body>

<!-- Print controls -->
<div class="print-controls">
    <button class="ctrl-btn ctrl-print" onclick="window.print()">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print Certificate
    </button>
    <a href="print_certificates.php" class="ctrl-btn ctrl-back">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        Back to List
    </a>
    <span style="font-size:.78rem;color:#6b7280;margin-left:.5rem">
        Certificate No: <strong style="color:#4361ee;font-family:monospace"><?= htmlspecialchars($certNo) ?></strong>
    </span>
</div>

<!-- ═══════════════════════════════════════════════
     CERTIFICATE  —  Replace this section with your
     custom template. All PHP variables below are
     already bound and ready to use:
       $fullName, $regId, $rollNo, $course,
       $atcName, $atcCode, $dlcName,
       $admDate, $issueDate, $certNo, $photoPath
     ═══════════════════════════════════════════════ -->
<div class="certificate">

    <!-- Borders -->
    <div class="cert-border-outer"></div>
    <div class="cert-border-inner"></div>

    <!-- Corner ornaments (SVG) -->
    <?php $cornerSvg = '<svg class="corner corner-tl" viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg"><path d="M2 2 L30 2 Q40 2 40 12 L40 30 Q40 40 30 40 L12 40 Q2 40 2 30 Z" fill="none" stroke="#c9a84c" stroke-width="2"/><path d="M7 7 L20 7 Q26 7 26 13 L26 26 Q26 32 20 32 L13 32 Q7 32 7 26 Z" fill="none" stroke="#c9a84c" stroke-width="1"/><circle cx="7" cy="7" r="2" fill="#c9a84c"/><circle cx="30" cy="2" r="1.5" fill="#c9a84c"/><circle cx="2" cy="30" r="1.5" fill="#c9a84c"/></svg>'; ?>
    <svg class="corner corner-tl" viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg"><path d="M2 2 L30 2 Q40 2 40 12 L40 30 Q40 40 30 40 L12 40 Q2 40 2 30 Z" fill="none" stroke="#c9a84c" stroke-width="2"/><path d="M7 7 L20 7 Q26 7 26 13 L26 26 Q26 32 20 32 L13 32 Q7 32 7 26 Z" fill="none" stroke="#c9a84c" stroke-width="1"/><circle cx="7" cy="7" r="2" fill="#c9a84c"/></svg>
    <svg class="corner corner-tr" viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg"><path d="M2 2 L30 2 Q40 2 40 12 L40 30 Q40 40 30 40 L12 40 Q2 40 2 30 Z" fill="none" stroke="#c9a84c" stroke-width="2"/><path d="M7 7 L20 7 Q26 7 26 13 L26 26 Q26 32 20 32 L13 32 Q7 32 7 26 Z" fill="none" stroke="#c9a84c" stroke-width="1"/><circle cx="7" cy="7" r="2" fill="#c9a84c"/></svg>
    <svg class="corner corner-bl" viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg"><path d="M2 2 L30 2 Q40 2 40 12 L40 30 Q40 40 30 40 L12 40 Q2 40 2 30 Z" fill="none" stroke="#c9a84c" stroke-width="2"/><path d="M7 7 L20 7 Q26 7 26 13 L26 26 Q26 32 20 32 L13 32 Q7 32 7 26 Z" fill="none" stroke="#c9a84c" stroke-width="1"/><circle cx="7" cy="7" r="2" fill="#c9a84c"/></svg>
    <svg class="corner corner-br" viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg"><path d="M2 2 L30 2 Q40 2 40 12 L40 30 Q40 40 30 40 L12 40 Q2 40 2 30 Z" fill="none" stroke="#c9a84c" stroke-width="2"/><path d="M7 7 L20 7 Q26 7 26 13 L26 26 Q26 32 20 32 L13 32 Q7 32 7 26 Z" fill="none" stroke="#c9a84c" stroke-width="1"/><circle cx="7" cy="7" r="2" fill="#c9a84c"/></svg>

    <!-- Watermark -->
    <div class="cert-watermark">GYANAM</div>

    <!-- Content -->
    <div class="cert-content">

        <!-- Header: Logo + Org name -->
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:6px">
            <?php if (file_exists(__DIR__ . '/../assets/logo.png')): ?>
            <img src="../assets/logo.png" class="cert-org-logo" alt="Logo">
            <?php else: ?>
            <div class="cert-org-logo-ph">G</div>
            <?php endif; ?>
            <div>
                <div class="cert-org-name">Gyanam India Educational Services</div>
                <div class="cert-org-sub">Empowering Education · Building Futures</div>
            </div>
        </div>

        <div class="cert-divider"></div>

        <div class="cert-title">Certificate of Achievement</div>
        <div class="cert-subtitle">Examination Excellence Award</div>

        <!-- Body -->
        <div class="cert-body">

            <!-- Student photo -->
            <div class="cert-photo-wrap">
                <?php if ($photoPath): ?>
                <img src="<?= $photoPath ?>" class="cert-photo" alt="<?= htmlspecialchars($fullName) ?>"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                <div class="cert-photo-ph" style="display:none">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#c9a84c" stroke-width="1.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </div>
                <?php else: ?>
                <div class="cert-photo-ph">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#c9a84c" stroke-width="1.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </div>
                <?php endif; ?>
            </div>

            <!-- Text -->
            <div class="cert-text-block">
                <div class="cert-intro">This is to certify that</div>
                <div class="cert-name"><?= htmlspecialchars($fullName) ?></div>

                <div class="cert-details">
                    <div class="cert-field">
                        <span class="cert-field-label">Registration ID</span>
                        <span class="cert-field-value"><?= htmlspecialchars($regId) ?></span>
                    </div>
                    <?php if ($rollNo): ?>
                    <div class="cert-field">
                        <span class="cert-field-label">Roll Number</span>
                        <span class="cert-field-value"><?= htmlspecialchars($rollNo) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="cert-field">
                        <span class="cert-field-label">Course Completed</span>
                        <span class="cert-field-value"><?= htmlspecialchars($course ?: '—') ?></span>
                    </div>
                    <div class="cert-field">
                        <span class="cert-field-label">Admission Date</span>
                        <span class="cert-field-value"><?= htmlspecialchars($admDate) ?></span>
                    </div>
                    <div class="cert-field">
                        <span class="cert-field-label">ATC Center</span>
                        <span class="cert-field-value"><?= htmlspecialchars($atcName) ?><?= $atcCode ? ' (' . htmlspecialchars($atcCode) . ')' : '' ?></span>
                    </div>
                    <div class="cert-field">
                        <span class="cert-field-label">Issue Date</span>
                        <span class="cert-field-value"><?= htmlspecialchars($issueDate) ?></span>
                    </div>
                </div>

                <div class="cert-achievement">
                    has successfully completed the prescribed course of study and passed the
                    examination conducted by <strong>Gyanam India Educational Services</strong>
                    with distinction and is hereby awarded this certificate in recognition of
                    academic excellence.
                </div>
            </div>
        </div><!-- /cert-body -->

        <!-- Footer -->
        <div class="cert-footer">

            <div class="cert-sign-block">
                <div class="cert-sign-line"></div>
                <div class="cert-sign-name">ATC Center Director</div>
                <div class="cert-sign-title"><?= htmlspecialchars($atcName) ?></div>
            </div>

            <div class="cert-meta">
                <strong>Certificate No:</strong> <?= htmlspecialchars($certNo) ?><br>
                <strong>Issued:</strong> <?= htmlspecialchars($issueDate) ?><br>
                <span style="font-size:.55rem">Verify at gyanamindia.com</span>
            </div>

            <div class="cert-stamp-area">Stamp &amp;<br>Seal</div>

            <div class="cert-sign-block">
                <div class="cert-sign-line"></div>
                <div class="cert-sign-name">Head Office</div>
                <div class="cert-sign-title">Gyanam India Educational Services</div>
            </div>

        </div><!-- /cert-footer -->

    </div><!-- /cert-content -->
</div><!-- /certificate -->

<script>
// Auto-print if ?print=1
const urlParams = new URLSearchParams(window.location.search);
if (urlParams.get('print') === '1') { window.onload = () => window.print(); }
</script>
</body>
</html>
