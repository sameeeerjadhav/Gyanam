<?php
/**
 * Gyanam Portal — ATC Authorization Certificate
 * Opens in new tab; printable as PDF via browser print dialog.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin(['Admin']);

$pdo = getDBConnection();
$id  = (int)($_GET['id'] ?? 0);

if (!$id) { die('<p style="font-family:sans-serif;padding:2rem;">Invalid ATC ID.</p>'); }

$stmt = $pdo->prepare("
    SELECT atc.*, dlc.name AS dlc_name, dlc.district AS dlc_district
    FROM atc_centers atc
    LEFT JOIN dlc_offices dlc ON atc.dlc_id = dlc.id
    WHERE atc.id = ?
");
$stmt->execute([$id]);
$atc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$atc) { die('<p style="font-family:sans-serif;padding:2rem;">ATC Center not found.</p>'); }

// Calculate expiry: use stored date or 1 year from today
$todayTs     = strtotime(date('Y-m-d'));
$expiryDate  = !empty($atc['authorization_expires_at'])
    ? date('d F Y', strtotime($atc['authorization_expires_at']))
    : date('d F Y', strtotime('+1 year'));

$issueDate   = !empty($atc['created_at'])
    ? date('d F Y', strtotime($atc['created_at']))
    : date('d F Y');

// Unique cert number: GI-ATC-<YEAR>-<ID padded to 4>
$certNo = 'GI-ATC-' . date('Y') . '-' . str_pad($id, 4, '0', STR_PAD_LEFT);

$centerName  = htmlspecialchars($atc['name']);
$centerType  = htmlspecialchars($atc['center_type'] ?? 'IT');
$district    = htmlspecialchars($atc['district'] ?? '');
$taluka      = htmlspecialchars($atc['taluka'] ?? '');
$state       = htmlspecialchars($atc['state'] ?? 'Maharashtra');
$address     = htmlspecialchars($atc['address'] ?? '');
$contactName = htmlspecialchars($atc['contact_person'] ?? '');
$dlcName     = htmlspecialchars($atc['dlc_name'] ?? 'Gyanam India');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Authorization Certificate — <?= $centerName ?> | Gyanam India</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Cinzel:wght@400;700;900&family=Inter:wght@300;400;500;600;700&display=swap');

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Inter', sans-serif;
    background: #e8e0d4;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 2.5rem 1rem;
    gap: 1.5rem;
  }

  /* Print toolbar (hidden when printing) */
  .print-bar {
    display: flex; gap: .75rem; align-items: center;
    background: #1e293b; color: #fff;
    padding: .75rem 1.25rem; border-radius: 12px;
    font-size: .875rem;
  }
  .print-bar strong { font-weight: 700; }
  .btn-print {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff; border: none; border-radius: 8px;
    padding: .55rem 1.25rem; font-size: .875rem; font-weight: 700;
    cursor: pointer; font-family: 'Inter', sans-serif;
    display: flex; align-items: center; gap: .4rem;
  }
  .btn-print:hover { opacity: .9; }

  /* A4 certificate page */
  .cert-page {
    width: 210mm;
    min-height: 297mm;
    background: #fff;
    position: relative;
    overflow: hidden;
    box-shadow: 0 12px 60px rgba(0,0,0,.25);
    display: flex;
    flex-direction: column;
    padding: 0;
  }

  /* ── Gold ornament border ── */
  .cert-page::before {
    content: '';
    position: absolute;
    inset: 12px;
    border: 3px solid #c9a84c;
    pointer-events: none;
    z-index: 10;
  }
  .cert-page::after {
    content: '';
    position: absolute;
    inset: 18px;
    border: 1px solid #e0c97a;
    pointer-events: none;
    z-index: 10;
  }

  /* ── Background watermark ── */
  .cert-watermark {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    z-index: 0;
    opacity: .04;
    font-family: 'Cinzel', serif;
    font-size: 120px;
    font-weight: 900;
    color: #c9a84c;
    letter-spacing: -4px;
    text-align: center;
    user-select: none;
    line-height: 1;
  }

  /* ── Header ribbon ── */
  .cert-header {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 60%, #0f3460 100%);
    padding: 2.5rem 3rem 2rem;
    position: relative;
    z-index: 1;
    text-align: center;
  }
  .cert-logo-row {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    margin-bottom: 1rem;
  }
  .cert-logo-badge {
    width: 64px; height: 64px; border-radius: 50%;
    background: linear-gradient(135deg, #c9a84c, #e8d5a3);
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem;
    box-shadow: 0 0 0 4px rgba(201,168,76,.3);
  }
  .cert-org-name {
    font-family: 'Cinzel', serif;
    font-size: 2rem; font-weight: 900;
    color: #fff;
    letter-spacing: 3px;
    text-shadow: 0 2px 4px rgba(0,0,0,.4);
  }
  .cert-org-tagline {
    color: #c9a84c;
    font-size: .8rem; font-weight: 500; letter-spacing: 4px;
    text-transform: uppercase;
    margin-top: .25rem;
  }
  .cert-ribbon {
    background: linear-gradient(90deg, transparent, #c9a84c, transparent);
    height: 2px; margin: 1.25rem auto 0; width: 70%;
  }

  /* ── Body content ── */
  .cert-body {
    flex: 1;
    padding: 2.5rem 4rem;
    position: relative;
    z-index: 1;
    text-align: center;
  }
  .cert-announce {
    font-family: 'Cinzel', serif;
    font-size: .85rem; font-weight: 400;
    letter-spacing: 6px; text-transform: uppercase;
    color: #7c6f5a; margin-bottom: 1rem;
  }
  .cert-title {
    font-family: 'Cinzel', serif;
    font-size: 2.4rem; font-weight: 700;
    color: #1a1a2e;
    line-height: 1.2;
    margin-bottom: .5rem;
  }
  .cert-subtitle {
    font-size: .95rem; color: #7c6f5a; font-weight: 400;
    margin-bottom: 2rem;
    font-style: italic;
  }
  .cert-divider {
    display: flex; align-items: center; gap: 1rem;
    margin: 0 auto 2rem;
    max-width: 340px;
  }
  .cert-divider::before, .cert-divider::after {
    content: ''; flex: 1; height: 1px; background: #c9a84c;
  }
  .cert-divider-star { color: #c9a84c; font-size: 1.2rem; }

  .cert-is-hereby {
    font-size: .9rem; color: #555; margin-bottom: .75rem;
  }
  .cert-center-name {
    font-family: 'Cinzel', serif;
    font-size: 2rem; font-weight: 700;
    color: #1a1a2e;
    border-bottom: 2px solid #c9a84c;
    padding-bottom: .5rem;
    display: inline-block;
    margin-bottom: 1.5rem;
  }
  .cert-desc {
    font-size: .875rem; color: #444; line-height: 1.9;
    max-width: 480px; margin: 0 auto 2rem;
  }
  .cert-desc strong { color: #1a1a2e; font-weight: 700; }

  /* Details grid */
  .cert-details {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: .75rem 2rem;
    background: linear-gradient(135deg, #fdfaf4, #f9f4ea);
    border: 1px solid #e8d5a3;
    border-radius: 12px;
    padding: 1.25rem 1.75rem;
    text-align: left;
    margin-bottom: 2rem;
  }
  .cert-detail-item { display: flex; flex-direction: column; gap: .15rem; }
  .cert-detail-label {
    font-size: .68rem; font-weight: 700; letter-spacing: 2px;
    text-transform: uppercase; color: #c9a84c;
  }
  .cert-detail-value {
    font-size: .875rem; font-weight: 600; color: #1a1a2e;
  }

  /* ── Footer signature area ── */
  .cert-footer {
    padding: 1.5rem 4rem 3rem;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    position: relative;
    z-index: 1;
  }
  .cert-sig { text-align: center; }
  .cert-sig-line {
    width: 160px; border-top: 1.5px solid #1a1a2e;
    margin: 0 auto .5rem;
  }
  .cert-sig-name { font-size: .8rem; font-weight: 700; color: #1a1a2e; letter-spacing: .5px; }
  .cert-sig-role { font-size: .72rem; color: #7c6f5a; margin-top: .1rem; }

  .cert-seal {
    width: 90px; height: 90px; border-radius: 50%;
    border: 3px solid #c9a84c;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    font-family: 'Cinzel', serif;
    background: linear-gradient(135deg, #fffbf0, #fdf3d0);
    box-shadow: 0 0 0 6px rgba(201,168,76,.15);
    text-align: center;
    padding: .5rem;
  }
  .cert-seal-text { font-size: .5rem; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: #c9a84c; }
  .cert-seal-emoji { font-size: 1.5rem; line-height: 1; }
  .cert-seal-year { font-size: .65rem; font-weight: 700; color: #c9a84c; margin-top: .1rem; }

  /* cert number ribbon at bottom */
  .cert-cert-no {
    text-align: center;
    font-size: .72rem; color: #7c6f5a;
    letter-spacing: 2px;
    padding-bottom: 1.5rem;
    position: relative; z-index: 1;
  }

  /* ── Print styles ── */
  @media print {
    @page { size: A4; margin: 0; }
    body { background: #fff; padding: 0; }
    .print-bar { display: none !important; }
    .cert-page {
      box-shadow: none;
      width: 210mm;
      min-height: 297mm;
    }
  }
</style>
</head>
<body>

<!-- Print Toolbar -->
<div class="print-bar">
  <strong>Gyanam India</strong> &mdash; Authorization Certificate Preview
  <button class="btn-print" onclick="window.print()">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
    Print / Save as PDF
  </button>
</div>

<!-- Certificate -->
<div class="cert-page">
  <div class="cert-watermark">GYANAM</div>

  <!-- Header -->
  <div class="cert-header">
    <div class="cert-logo-row">
      <div class="cert-logo-badge">📚</div>
      <div>
        <div class="cert-org-name">GYANAM INDIA</div>
        <div class="cert-org-tagline">Knowledge &bull; Growth &bull; Excellence</div>
      </div>
    </div>
    <div class="cert-ribbon"></div>
  </div>

  <!-- Body -->
  <div class="cert-body">
    <div class="cert-announce">Certificate of Authorization</div>
    <div class="cert-title">Authorization Certificate</div>
    <div class="cert-subtitle">Authorized Training Center Recognition</div>

    <div class="cert-divider"><span class="cert-divider-star">✦</span></div>

    <div class="cert-is-hereby">This is to certify that</div>
    <div class="cert-center-name"><?= $centerName ?></div>

    <p class="cert-desc">
      is hereby authorized and recognized as an <strong>Authorized Training Center (ATC)</strong> of
      <strong>Gyanam India</strong> for imparting education and training in
      <strong><?= $centerType ?></strong> courses. This authorization is granted under
      the oversight of <strong><?= $dlcName ?></strong>.
    </p>

    <!-- Details -->
    <div class="cert-details">
      <div class="cert-detail-item">
        <span class="cert-detail-label">Center Type</span>
        <span class="cert-detail-value"><?= $centerType ?></span>
      </div>
      <div class="cert-detail-item">
        <span class="cert-detail-label">DLC Region</span>
        <span class="cert-detail-value"><?= $dlcName ?></span>
      </div>
      <div class="cert-detail-item">
        <span class="cert-detail-label">District</span>
        <span class="cert-detail-value"><?= $district ?: '—' ?></span>
      </div>
      <div class="cert-detail-item">
        <span class="cert-detail-label">Taluka</span>
        <span class="cert-detail-value"><?= $taluka ?: '—' ?></span>
      </div>
      <div class="cert-detail-item">
        <span class="cert-detail-label">State</span>
        <span class="cert-detail-value"><?= $state ?></span>
      </div>
      <?php if ($contactName): ?>
      <div class="cert-detail-item">
        <span class="cert-detail-label">Contact Person</span>
        <span class="cert-detail-value"><?= $contactName ?></span>
      </div>
      <?php endif; ?>
      <div class="cert-detail-item">
        <span class="cert-detail-label">Issue Date</span>
        <span class="cert-detail-value"><?= $issueDate ?></span>
      </div>
      <div class="cert-detail-item">
        <span class="cert-detail-label">Valid Until</span>
        <span class="cert-detail-value"><?= $expiryDate ?></span>
      </div>
    </div>
  </div>

  <!-- Footer -->
  <div class="cert-footer">
    <div class="cert-sig">
      <div class="cert-sig-line"></div>
      <div class="cert-sig-name">Head Office</div>
      <div class="cert-sig-role">Gyanam India — Authorizing Authority</div>
    </div>
    <div class="cert-seal">
      <div class="cert-seal-text">Official</div>
      <div class="cert-seal-emoji">🏆</div>
      <div class="cert-seal-text">Gyanam India</div>
      <div class="cert-seal-year"><?= date('Y') ?></div>
    </div>
    <div class="cert-sig">
      <div class="cert-sig-line"></div>
      <div class="cert-sig-name"><?= $dlcName ?></div>
      <div class="cert-sig-role">DLC Login — Regional Coordinator</div>
    </div>
  </div>

  <!-- Certificate Number -->
  <div class="cert-cert-no">Certificate No: <?= $certNo ?></div>
</div>

</body>
</html>
