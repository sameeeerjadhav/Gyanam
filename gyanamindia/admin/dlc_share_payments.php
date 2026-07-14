<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo = getDBConnection();
$msg = '';
$msgType = '';

// ── Handle POST: record new payment ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    $dlcId      = (int)($_POST['dlc_id'] ?? 0);
    $amount     = (float)($_POST['amount'] ?? 0);
    $payDate    = $_POST['payment_date'] ?? '';
    $payMode    = trim($_POST['payment_mode'] ?? 'Bank Transfer');
    $refNo      = trim($_POST['reference_no'] ?? '');
    $remarks    = trim($_POST['remarks'] ?? '');
    $status     = in_array($_POST['status'] ?? '', ['Completed','Pending']) ? $_POST['status'] : 'Completed';

    // Handle proof image upload
    $proofImage = null;
    $proofDir   = __DIR__ . '/../uploads/payment_proofs/';
    if (!is_dir($proofDir)) @mkdir($proofDir, 0777, true);
    if (isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['proof_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp','pdf'])) {
            $fname = 'proof_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['proof_image']['tmp_name'], $proofDir . $fname)) {
                $proofImage = $fname;
            }
        }
    }

    if ($dlcId && $amount > 0 && $payDate) {
        try {
            $stmt = $pdo->prepare("INSERT INTO dlc_share_payments (dlc_id, amount, payment_date, payment_mode, reference_no, remarks, status, proof_image) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$dlcId, $amount, $payDate, $payMode, $refNo, $remarks, $status, $proofImage]);
            $msg = 'Payment recorded successfully!';
            $msgType = 'success';
        } catch (Exception $e) {
            $msg = 'Error recording payment: ' . $e->getMessage();
            $msgType = 'error';
        }
    } else {
        $msg = 'Please fill all required fields (DLC, Amount, Date).';
        $msgType = 'error';
    }
}

// ── Fetch all DLCs with bank details + total paid ──────────────────────────
ensureDualMaterialCourseSchema($pdo);
$dlcs = [];
try {
    $stmt = $pdo->query("
        SELECT d.id, d.name, d.bank_name, d.account_holder, d.account_no, d.ifsc_code, d.upi_id, d.bank_updated_at,
               COALESCE(SUM(CASE WHEN sp.status='Completed' THEN sp.amount ELSE 0 END),0) AS total_paid,
               COUNT(sp.id) AS payment_count
        FROM dlc_offices d
        LEFT JOIN dlc_share_payments sp ON sp.dlc_id = d.id
        WHERE d.status='Active'
        GROUP BY d.id
        ORDER BY d.name
    ");
    $dlcs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Enrich each DLC with per-student calculated due / pending
$grandDue = 0;
$grandPending = 0;
foreach ($dlcs as &$d) {
    $calc = calculateDlcShareSummary($pdo, (int)$d['id']);
    $d['share_due']      = $calc['due'];
    $d['share_pending']  = $calc['pending'];
    $d['share_students'] = $calc['student_count'];
    $grandDue     += $calc['due'];
    $grandPending += $calc['pending'];
}
unset($d);

// ── Fetch payment history (for modal/history view) ─────────────────────────
$histDlcId = (int)($_GET['history'] ?? 0);
$history   = [];
$histDlcName = '';
if ($histDlcId) {
    try {
        $s = $pdo->prepare("SELECT sp.*, d.name as dlc_name FROM dlc_share_payments sp JOIN dlc_offices d ON d.id=sp.dlc_id WHERE sp.dlc_id=? ORDER BY sp.payment_date DESC");
        $s->execute([$histDlcId]);
        $history = $s->fetchAll(PDO::FETCH_ASSOC);
        $histDlcName = $history[0]['dlc_name'] ?? '';
    } catch (Exception $e) {}
}

// Student-wise breakdown for a DLC
$stuDlcId = (int)($_GET['students'] ?? 0);
$stuRows  = [];
$stuDlcName = '';
$stuSummary = null;
if ($stuDlcId) {
    $stuSummary = calculateDlcShareSummary($pdo, $stuDlcId);
    $stuRows = $stuSummary['students'];
    foreach ($dlcs as $d) {
        if ((int)$d['id'] === $stuDlcId) {
            $stuDlcName = $d['name'];
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DLC Share Payments — Admin | Gyanam India</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<link rel="stylesheet" href="../assets/css/notifications.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>💸</text></svg>">
<style>
:root{
  --ind:#4f6ef7;--ind-dk:#3a57e8;--ind-soft:#eef1fe;
  --vio:#7c3aed;--em:#00c48c;--em-dk:#00a376;--em-soft:#e6faf4;
  --amb:#f59e0b;--amb-soft:#fffbeb;
  --r-md:10px;--r-lg:14px;--r-xl:20px;--r-full:9999px;
  --mono:'JetBrains Mono',monospace;
  --sh:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);
}
/* Card */
.mc{background:#fff;border-radius:var(--r-xl);border:1px solid var(--border-color);box-shadow:var(--sh);margin-bottom:1.5rem;overflow:hidden}
/* Table */
.dtable{width:100%;border-collapse:collapse;font-size:.875rem}
.dtable thead th{padding:.85rem 1rem;text-align:left;font-size:.7rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--border-color);background:#fafbfc;white-space:nowrap}
.dtable tbody tr{border-bottom:1px solid #f3f5f9;transition:background .12s}
.dtable tbody tr:last-child{border-bottom:none}
.dtable tbody tr:hover{background:#fafbff}
.dtable tbody td{padding:.85rem 1rem;vertical-align:middle}
/* Badges */
.badge{display:inline-flex;align-items:center;gap:.3rem;padding:.22rem .65rem;border-radius:var(--r-full);font-size:.7rem;font-weight:700}
.badge-dot{width:5px;height:5px;border-radius:50%;background:currentColor}
.badge.done{background:var(--em-soft);color:var(--em-dk);border:1px solid #b3f0de}
.badge.pend{background:var(--amb-soft);color:#78350f;border:1px solid #fde68a}
.badge.none{background:#f3f5f9;color:var(--text-secondary);border:1px solid #e4e8f0}
/* Buttons */
.btn{display:inline-flex;align-items:center;gap:.35rem;padding:.45rem .9rem;border-radius:var(--r-md);font-size:.78rem;font-weight:700;font-family:inherit;cursor:pointer;border:none;transition:all .18s}
.btn-primary{background:linear-gradient(135deg,var(--ind),var(--ind-dk));color:#fff;box-shadow:0 3px 10px rgba(79,110,247,.25)}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(79,110,247,.32)}
.btn-outline{background:#fff;color:var(--ind);border:1.5px solid var(--ind-soft);}
.btn-outline:hover{background:var(--ind-soft)}
.btn-sm{padding:.35rem .7rem;font-size:.76rem}
.btn svg{width:13px;height:13px}
/* Alert */
.alert{padding:.78rem 1rem;border-radius:var(--r-lg);font-size:.875rem;font-weight:600;margin-bottom:1.2rem;display:flex;align-items:center;gap:.5rem}
.alert svg{width:14px;height:14px;flex-shrink:0}
.alert.success{background:var(--em-soft);color:var(--em-dk);border:1px solid #b3f0de}
.alert.error{background:#fff0f0;color:#c0392b;border:1px solid #f5c6cb}
/* Bank info chip */
.bank-chip{display:flex;flex-direction:column;gap:.1rem}
.bank-chip .bn{font-weight:700;font-size:.85rem}
.bank-chip .ba{font-size:.78rem;color:var(--text-secondary);font-family:var(--mono)}
.no-bank{font-size:.78rem;color:#aaa;font-style:italic}
/* Amount */
.amt{font-family:var(--mono);font-weight:700;color:var(--em-dk);font-size:.9rem}
.ref{font-family:var(--mono);font-size:.78rem;color:var(--ind)}
/* Modal overlay */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);z-index:1000;display:none;align-items:center;justify-content:center;padding:1rem}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:var(--r-xl);box-shadow:0 20px 60px rgba(0,0,0,.2);width:100%;max-width:520px;overflow:hidden;animation:slideUp .25s ease}
@keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.modal-head{padding:1.1rem 1.4rem;display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,var(--ind),var(--vio))}
.modal-head-title{color:#fff;font-weight:800;font-size:1rem}
.modal-head-sub{color:rgba(255,255,255,.75);font-size:.75rem;margin-top:.1rem}
.modal-close{background:rgba(255,255,255,.2);border:none;border-radius:var(--r-md);padding:.35rem .55rem;cursor:pointer;color:#fff;display:flex;align-items:center}
.modal-close svg{width:14px;height:14px}
.modal-body{padding:1.4rem}
/* Form */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:.85rem}
.fg{display:flex;flex-direction:column;gap:.3rem}
.fg.full{grid-column:1/-1}
.fl{font-size:.7rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.04em}
.fc{padding:.7rem .9rem;border:1.5px solid var(--border-color);border-radius:var(--r-md);font-size:.875rem;font-family:inherit;background:#fafbfc;outline:none;transition:border-color .15s,box-shadow .15s;width:100%}
.fc:focus{border-color:var(--ind);box-shadow:0 0 0 3px rgba(79,110,247,.1)}
/* History modal table */
.hist-table{width:100%;border-collapse:collapse;font-size:.82rem}
.hist-table th{padding:.65rem .8rem;text-align:left;font-size:.68rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--border-color)}
.hist-table td{padding:.65rem .8rem;border-bottom:1px solid #f3f5f9}
.hist-table tr:last-child td{border-bottom:none}
/* Bank detail modal */
.bank-row{display:flex;align-items:start;gap:.6rem;padding:.6rem 0;border-bottom:1px solid #f3f5f9}
.bank-row:last-child{border-bottom:none}
.bank-row-label{font-size:.72rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.04em;width:110px;flex-shrink:0;padding-top:.1rem}
.bank-row-value{font-size:.9rem;font-weight:600;font-family:var(--mono);color:var(--text-primary)}
.bank-row-value.na{font-family:inherit;color:var(--text-secondary);font-style:italic;font-weight:400}
/* Summary counter */
.summary-bar{display:flex;align-items:center;gap:1rem;padding:.85rem 1.2rem;border-bottom:1px solid var(--border-color);background:#fafbfc;flex-wrap:wrap}
.sb-item{display:flex;flex-direction:column;gap:.1rem}
.sb-label{font-size:.68rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.05em}
.sb-value{font-size:1.1rem;font-weight:800;color:var(--text-primary)}
.sb-value.green{color:var(--em-dk)}
.sb-div{width:1px;height:36px;background:var(--border-color)}
/* Proof image upload zone */
.proof-drop{border:1.5px dashed var(--border-color);border-radius:var(--r-md);padding:.85rem;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;background:#fafbfc;position:relative}
.proof-drop:hover,.proof-drop.drag-over{border-color:var(--ind);background:var(--ind-soft)}
.proof-drop input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.proof-drop p{margin:.35rem 0 0;font-size:.75rem;color:var(--text-secondary)}
.proof-preview{width:100%;max-height:140px;object-fit:cover;border-radius:var(--r-md);border:1px solid var(--border-color);display:none;margin-top:.5rem}
/* Proof thumbnail in table */
.proof-thumb{width:36px;height:36px;object-fit:cover;border-radius:6px;border:1px solid var(--border-color);cursor:pointer;transition:transform .15s;display:block}
.proof-thumb:hover{transform:scale(1.1)}
/* Lightbox */
.lb-overlay{position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9999;display:none;align-items:center;justify-content:center;cursor:zoom-out}
.lb-overlay.open{display:flex}
.lb-overlay img{max-width:92vw;max-height:92vh;border-radius:var(--r-xl);box-shadow:0 20px 60px rgba(0,0,0,.5)}
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
      <div style="margin-left:.9rem">
        <h2 style="font-size:1.05rem;font-weight:800;margin:0;color:var(--text-primary)">DLC Share Payments</h2>
        <p style="font-size:.74rem;color:var(--text-secondary);margin:0">View DLC bank details &amp; record HO → DLC payments</p>
      </div>
    </div>
    <div class="header-right">
      <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
      <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
    </div>
  </header>

  <div class="page-content">

    <?php if ($msg): ?>
    <div class="alert <?= $msgType ?>">
      <?php if ($msgType === 'success'): ?>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <?php else: ?>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/></svg>
      <?php endif; ?>
      <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

<?php
// Summary stats
$totalDLCs        = count($dlcs);
$dlcsWithBank     = count(array_filter($dlcs, function($d){ return !empty($d['account_no']); }));
$grandTotal       = array_sum(array_column($dlcs, 'total_paid'));
?>
    <!-- Summary Bar -->
    <div class="mc">
      <div class="summary-bar">
        <div class="sb-item">
          <span class="sb-label">Active DLCs</span>
          <span class="sb-value"><?= $totalDLCs ?></span>
        </div>
        <div class="sb-div"></div>
        <div class="sb-item">
          <span class="sb-label">Bank Details Submitted</span>
          <span class="sb-value"><?= $dlcsWithBank ?> / <?= $totalDLCs ?></span>
        </div>
        <div class="sb-div"></div>
        <div class="sb-item">
          <span class="sb-label">Total DLC Due (share-paid students)</span>
          <span class="sb-value">&#8377;<?= number_format($grandDue, 0) ?></span>
        </div>
        <div class="sb-div"></div>
        <div class="sb-item">
          <span class="sb-label">Total Paid to DLCs</span>
          <span class="sb-value green">&#8377;<?= number_format($grandTotal, 0) ?></span>
        </div>
        <div class="sb-div"></div>
        <div class="sb-item">
          <span class="sb-label">Pending to Pay</span>
          <span class="sb-value" style="color:#c2410c">&#8377;<?= number_format($grandPending, 0) ?></span>
        </div>
      </div>

      <!-- DLCs Table -->
      <div style="overflow-x:auto">
        <table class="dtable">
          <thead>
            <tr>
              <th>DLC Office</th>
              <th>Bank Details</th>
              <th>Students (HO paid)</th>
              <th>DLC Due</th>
              <th>Paid</th>
              <th>Pending</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($dlcs as $d): ?>
          <?php $hasBank = !empty($d['account_no']); ?>
          <tr>
            <td>
              <div style="font-weight:700;font-size:.9rem"><?= htmlspecialchars($d['name']) ?></div>
              <?php if ($d['bank_updated_at']): ?>
              <div style="font-size:.72rem;color:var(--text-secondary);margin-top:.15rem">
                Updated <?= date('d M Y', strtotime($d['bank_updated_at'])) ?>
              </div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($hasBank): ?>
              <div class="bank-chip">
                <span class="bn"><?= htmlspecialchars($d['bank_name'] ?: 'N/A') ?></span>
                <span class="ba">A/C: ****<?= substr($d['account_no'], -4) ?></span>
                <?php if ($d['upi_id']): ?><span style="font-size:.72rem;color:var(--ind)"><?= htmlspecialchars($d['upi_id']) ?></span><?php endif; ?>
              </div>
              <?php else: ?>
              <span class="no-bank">Not submitted yet</span>
              <?php endif; ?>
            </td>
            <td>
              <a href="dlc_share_payments.php?students=<?= (int)$d['id'] ?>" style="font-weight:800;color:#3730a3;text-decoration:none">
                <?= (int)$d['share_students'] ?> student<?= (int)$d['share_students'] === 1 ? '' : 's' ?>
              </a>
            </td>
            <td class="amt" style="color:#1f2937">&#8377;<?= number_format((float)$d['share_due'], 0) ?></td>
            <td class="amt">&#8377;<?= number_format($d['total_paid'], 0) ?></td>
            <td class="amt" style="color:#c2410c">&#8377;<?= number_format((float)$d['share_pending'], 0) ?></td>
            <td>
              <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                <?php if ($hasBank): ?>
                <button class="btn btn-outline btn-sm" onclick='showBankModal(<?= htmlspecialchars(json_encode($d)) ?>)'>
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                  Bank Info
                </button>
                <?php endif; ?>
                <button class="btn btn-primary btn-sm" onclick='openPayModal(<?= (int)$d["id"] ?>, <?= htmlspecialchars(json_encode($d["name"])) ?>, <?= (float)$d["share_pending"] ?>)'>
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  Record Payment
                </button>
                <a href="dlc_share_payments.php?students=<?= (int)$d['id'] ?>" class="btn btn-outline btn-sm">Per student</a>
                <?php if ($d['payment_count'] > 0): ?>
                <a href="dlc_share_payments.php?history=<?= $d['id'] ?>" class="btn btn-outline btn-sm">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                  History
                </a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($dlcs)): ?>
          <tr><td colspan="7" style="text-align:center;padding:2.5rem;color:var(--text-secondary)">No active DLC offices found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($stuDlcId): ?>
    <div class="mc" style="margin-top:1.25rem">
      <div class="summary-bar" style="justify-content:space-between">
        <div>
          <div class="sb-label">Per-student DLC share</div>
          <div class="sb-value" style="font-size:1rem"><?= htmlspecialchars($stuDlcName ?: 'DLC') ?></div>
          <div style="font-size:.75rem;color:var(--text-secondary);margin-top:.2rem">
            Only students whose ATC has already paid HO share are listed. Due &#8377;<?= number_format((float)($stuSummary['due'] ?? 0), 0) ?>
            · Paid &#8377;<?= number_format((float)($stuSummary['paid'] ?? 0), 0) ?>
            · Pending &#8377;<?= number_format((float)($stuSummary['pending'] ?? 0), 0) ?>
          </div>
        </div>
        <a href="dlc_share_payments.php" class="btn btn-outline btn-sm">← Back</a>
      </div>
      <div style="overflow-x:auto">
        <table class="dtable">
          <thead>
            <tr>
              <th>#</th>
              <th>Student</th>
              <th>Roll / Reg</th>
              <th>ATC</th>
              <th>Course</th>
              <th>Material</th>
              <th>DLC Share</th>
              <th>Admitted</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($stuRows)): ?>
              <tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-secondary)">No HO-share-paid students with DLC share yet for this DLC.</td></tr>
            <?php else: $n=0; foreach ($stuRows as $s): $n++; ?>
              <tr>
                <td><?= $n ?></td>
                <td style="font-weight:700"><?= htmlspecialchars($s['student_name']) ?></td>
                <td style="font-family:var(--mono);font-size:.78rem">
                  <?= htmlspecialchars($s['roll_no']) ?>
                  <?php if (!empty($s['registration_id'])): ?><div style="color:var(--text-secondary)"><?= htmlspecialchars($s['registration_id']) ?></div><?php endif; ?>
                </td>
                <td><?= htmlspecialchars($s['atc_name']) ?></td>
                <td><?= htmlspecialchars($s['course']) ?></td>
                <td><?= htmlspecialchars($s['material_type'] ?: '—') ?></td>
                <td class="amt">&#8377;<?= number_format((float)$s['dlc_share'], 0) ?></td>
                <td><?= $s['admission_date'] ? date('d M Y', strtotime($s['admission_date'])) : '—' ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- Payment History (shown when ?history=X) -->
    <?php if ($histDlcId && !empty($history)): ?>
    <div class="mc">
      <div style="padding:.9rem 1.2rem;border-bottom:1px solid var(--border-color);background:#fafbfc;display:flex;align-items:center;justify-content:space-between">
        <div>
          <span style="font-weight:800;font-size:.95rem">Payment History — <?= htmlspecialchars($histDlcName) ?></span>
          <span style="font-size:.75rem;color:var(--text-secondary);margin-left:.75rem"><?= count($history) ?> records</span>
        </div>
        <a href="dlc_share_payments.php" class="btn btn-outline btn-sm">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          Close
        </a>
      </div>
      <div style="overflow-x:auto">
        <table class="hist-table">
          <thead><tr><th>#</th><th>Date</th><th>Amount</th><th>Mode</th><th>Reference / UTR</th><th>Remarks</th><th>Proof</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($history as $i => $h): ?>
          <tr>
            <td style="color:var(--text-secondary)"><?= $i+1 ?></td>
            <td style="font-weight:600"><?= date('d M Y', strtotime($h['payment_date'])) ?></td>
            <td class="amt">&#8377;<?= number_format($h['amount'], 2) ?></td>
            <td><?= htmlspecialchars($h['payment_mode']) ?></td>
            <td class="ref"><?= htmlspecialchars($h['reference_no'] ?? '—') ?></td>
            <td style="color:var(--text-secondary)"><?= htmlspecialchars($h['remarks'] ?? '—') ?></td>
            <td>
              <?php if (!empty($h['proof_image'])): ?>
              <img src="../uploads/payment_proofs/<?= htmlspecialchars($h['proof_image']) ?>" class="proof-thumb" onclick="openLightbox(this.src)" title="Click to enlarge">
              <?php else: ?>
              <span style="color:#ccc;font-size:.75rem">—</span>
              <?php endif; ?>
            </td>
            <td><span class="badge <?= $h['status']==='Completed'?'done':'pend' ?>"><span class="badge-dot"></span><?= $h['status'] ?></span></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /page-content -->
</main>
</div>

<!-- ── Bank Info Modal ── -->
<div class="modal-overlay" id="bankModal">
  <div class="modal-box">
    <div class="modal-head">
      <div>
        <div class="modal-head-title">Bank Details</div>
        <div class="modal-head-sub" id="bankModalDlcName"></div>
      </div>
      <button class="modal-close" onclick="closeBankModal()">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="modal-body" id="bankModalBody"></div>
  </div>
</div>

<!-- ── Record Payment Modal ── -->
<div class="modal-overlay" id="payModal">
  <div class="modal-box">
    <div class="modal-head">
      <div>
        <div class="modal-head-title">Record HO → DLC Payment</div>
        <div class="modal-head-sub" id="payModalDlcName"></div>
      </div>
      <button class="modal-close" onclick="closePayModal()">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="record_payment" value="1">
        <input type="hidden" name="dlc_id" id="payDlcId">
        <div class="form-grid">
          <div class="fg">
            <label class="fl" for="amount">Amount (₹) *</label>
            <input type="number" id="amount" name="amount" class="fc" min="1" step="0.01" placeholder="e.g. 5000" required>
          </div>
          <div class="fg">
            <label class="fl" for="payment_date">Payment Date *</label>
            <input type="date" id="payment_date" name="payment_date" class="fc" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="fg">
            <label class="fl" for="payment_mode">Payment Mode</label>
            <select id="payment_mode" name="payment_mode" class="fc">
              <option>Bank Transfer</option>
              <option>UPI</option>
              <option>NEFT</option>
              <option>RTGS</option>
              <option>IMPS</option>
              <option>Cheque</option>
              <option>Cash</option>
            </select>
          </div>
          <div class="fg">
            <label class="fl" for="status">Status</label>
            <select id="status" name="status" class="fc">
              <option value="Completed">Completed</option>
              <option value="Pending">Pending</option>
            </select>
          </div>
          <div class="fg">
            <label class="fl" for="reference_no">Reference / UTR No.</label>
            <input type="text" id="reference_no" name="reference_no" class="fc" placeholder="Transaction / UTR ID">
          </div>
          <div class="fg">
            <label class="fl" for="remarks">Remarks</label>
            <input type="text" id="remarks" name="remarks" class="fc" placeholder="Optional note">
          </div>
          <!-- Payment Proof Image -->
          <div class="fg full">
            <label class="fl">Payment Proof <span style="color:#94a3b8;font-weight:500;text-transform:none">(screenshot/receipt — optional)</span></label>
            <div class="proof-drop" id="proofDrop">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--ind)"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              <p>Click or drag &amp; drop screenshot / receipt here</p>
              <p style="font-size:.68rem;color:#b0b8cc">JPG, PNG, WEBP, PDF accepted</p>
              <input type="file" name="proof_image" id="proofFile" accept="image/jpeg,image/png,image/webp,application/pdf">
            </div>
            <img id="proofPreview" class="proof-preview" alt="Proof preview">
          </div>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:1.1rem;width:100%;justify-content:center;padding:.82rem">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          Save Payment Record
        </button>
      </form>
    </div>
  </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
function showBankModal(d) {
    document.getElementById('bankModalDlcName').textContent = d.name;
    const rows = [
        ['Bank Name',       d.bank_name       || '—'],
        ['Account Holder',  d.account_holder  || '—'],
        ['Account Number',  d.account_no      || '—'],
        ['IFSC Code',       d.ifsc_code       || '—'],
        ['UPI ID',          d.upi_id          || '—'],
    ];
    document.getElementById('bankModalBody').innerHTML = rows.map(([l,v]) =>
        `<div class="bank-row">
          <span class="bank-row-label">${l}</span>
          <span class="bank-row-value${v==='—'?' na':''}">${v}</span>
        </div>`).join('');
    document.getElementById('bankModal').classList.add('open');
}
function closeBankModal() { document.getElementById('bankModal').classList.remove('open'); }

function openPayModal(id, name, pendingAmount) {
    document.getElementById('payDlcId').value = id;
    document.getElementById('payModalDlcName').textContent = name;
    const amt = document.querySelector('#payModal input[name="amount"]');
    if (amt && pendingAmount && pendingAmount > 0) {
        amt.value = Math.round(pendingAmount);
    }
    // Reset proof preview
    document.getElementById('proofPreview').style.display = 'none';
    document.getElementById('proofPreview').src = '';
    document.getElementById('proofFile').value = '';
    document.getElementById('payModal').classList.add('open');
}
function closePayModal() { document.getElementById('payModal').classList.remove('open'); }

// Close on overlay click
['bankModal','payModal'].forEach(id => {
    const el = document.getElementById(id);
    el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});

// Proof image preview
const proofFile    = document.getElementById('proofFile');
const proofPreview = document.getElementById('proofPreview');
const proofDrop    = document.getElementById('proofDrop');

proofFile.addEventListener('change', function() {
    const file = this.files[0];
    if (file && file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = e => { proofPreview.src = e.target.result; proofPreview.style.display = 'block'; };
        reader.readAsDataURL(file);
    } else {
        proofPreview.style.display = 'none';
    }
});
proofDrop.addEventListener('dragover',  e => { e.preventDefault(); proofDrop.classList.add('drag-over'); });
proofDrop.addEventListener('dragleave', () => proofDrop.classList.remove('drag-over'));
proofDrop.addEventListener('drop', e => {
    e.preventDefault(); proofDrop.classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) { proofFile.files = e.dataTransfer.files; proofFile.dispatchEvent(new Event('change')); }
});

// Lightbox
const lb = Object.assign(document.createElement('div'), {className:'lb-overlay'});
lb.innerHTML = '<img id="lbImg">';
document.body.appendChild(lb);
lb.addEventListener('click', () => lb.classList.remove('open'));
function openLightbox(src) {
    document.getElementById('lbImg').src = src;
    lb.classList.add('open');
}
</script>
</body>
</html>
