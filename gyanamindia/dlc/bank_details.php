<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['DLC Office']);

$pdo    = getDBConnection();
$dlcId  = $_SESSION['dlc_id'] ?? null;
$msg    = '';
$msgType = '';
$dlcNotLinked = false;

if (!$dlcId) {
    // Strategy 1: read dlc_id from users table
    try {
        $uid = $_SESSION['user_id'] ?? null;
        if ($uid) {
            $s = $pdo->prepare("SELECT dlc_id FROM users WHERE id = ? LIMIT 1");
            $s->execute([$uid]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r && !empty($r['dlc_id'])) {
                $dlcId = (int)$r['dlc_id'];
                $_SESSION['dlc_id'] = $dlcId;
            }
        }
    } catch (Exception $e) {}
}

if (!$dlcId) {
    // Strategy 2: fuzzy match username against dlc_offices.name
    // Strips spaces/special chars for comparison: 'JalgaonDLC' vs 'Jalgaon DLC'
    try {
        $uname = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $_SESSION['username'] ?? ''));
        if ($uname) {
            $s = $pdo->query("SELECT id, name FROM dlc_offices WHERE status='Active'");
            $offices = $s->fetchAll(PDO::FETCH_ASSOC);
            foreach ($offices as $office) {
                $normalized = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $office['name']));
                // Check if username contains the office name or vice-versa
                if (str_contains($uname, $normalized) || str_contains($normalized, $uname)
                    || str_contains($uname, substr($normalized, 0, 6))
                    || str_contains($normalized, substr($uname, 0, 6))) {
                    $dlcId = (int)$office['id'];
                    $_SESSION['dlc_id'] = $dlcId;
                    // Also fix users table so this doesn't repeat
                    try {
                        $uid = $_SESSION['user_id'] ?? null;
                        if ($uid) {
                            $pdo->prepare("UPDATE users SET dlc_id = ? WHERE id = ? AND dlc_id IS NULL")
                                ->execute([$dlcId, $uid]);
                        }
                    } catch (Exception $e2) {}
                    break;
                }
            }
        }
    } catch (Exception $e) {}
}

if (!$dlcId) {
    // Strategy 3: only one active DLC office — use it
    try {
        $s = $pdo->query("SELECT id FROM dlc_offices WHERE status='Active'");
        $all = $s->fetchAll(PDO::FETCH_COLUMN);
        if (count($all) === 1) {
            $dlcId = (int)$all[0];
            $_SESSION['dlc_id'] = $dlcId;
        }
    } catch (Exception $e) {}
}

if (!$dlcId) {
    // Still can't resolve — show page with a warning, don't redirect
    $dlcNotLinked = true;
}

// Save bank details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_bank'])) {
    try {
        $stmt = $pdo->prepare("UPDATE dlc_offices SET bank_name=?, account_holder=?, account_no=?, ifsc_code=?, upi_id=?, bank_updated_at=NOW() WHERE id=?");
        $stmt->execute([
            trim($_POST['bank_name'] ?? ''),
            trim($_POST['account_holder'] ?? ''),
            trim($_POST['account_no'] ?? ''),
            strtoupper(trim($_POST['ifsc_code'] ?? '')),
            trim($_POST['upi_id'] ?? ''),
            $dlcId
        ]);
        $msg = 'Bank details saved successfully!';
        $msgType = 'success';
    } catch (Exception $e) {
        $msg = 'Error saving details. Please try again.';
        $msgType = 'error';
    }
}

// Fetch current details
$dlc = [];
try {
    $s = $pdo->prepare("SELECT name, bank_name, account_holder, account_no, ifsc_code, upi_id, bank_updated_at FROM dlc_offices WHERE id=?");
    $s->execute([$dlcId]);
    $dlc = $s->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Fetch payments received from HO
$payments = [];
$totalReceived = 0;
try {
    $s = $pdo->prepare("SELECT * FROM dlc_share_payments WHERE dlc_id=? ORDER BY payment_date DESC");
    $s->execute([$dlcId]);
    $payments = $s->fetchAll(PDO::FETCH_ASSOC);
    $totalReceived = array_sum(array_column($payments, 'amount'));
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bank Details — DLC | Gyanam India</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<link rel="stylesheet" href="../assets/css/notifications.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🏦</text></svg>">
<style>
:root{
  --ind:#4f6ef7;--ind-dk:#3a57e8;--ind-soft:#eef1fe;
  --vio:#7c3aed;--em:#00c48c;--em-dk:#00a376;--em-soft:#e6faf4;
  --amb:#f59e0b;--amb-soft:#fffbeb;
  --r-md:10px;--r-lg:14px;--r-xl:20px;--r-full:9999px;
  --mono:'JetBrains Mono',monospace;
  --sh:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);
}
.module-card{background:#fff;border-radius:var(--r-xl);border:1px solid var(--border-color);box-shadow:var(--sh);overflow:hidden;margin-bottom:1.5rem}
.mc-head{padding:1.1rem 1.4rem;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:.8rem;background:#fafbfc}
.mc-icon{width:40px;height:40px;border-radius:var(--r-lg);display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--ind),var(--vio));box-shadow:0 6px 14px rgba(79,110,247,.25);flex-shrink:0}
.mc-icon svg{width:18px;height:18px;stroke:#fff}
.mc-icon.green{background:linear-gradient(135deg,var(--em),var(--em-dk))}
.mc-title{font-size:.95rem;font-weight:800;color:var(--text-primary);margin:0}
.mc-sub{font-size:.76rem;color:var(--text-secondary);margin:.1rem 0 0}
.mc-body{padding:1.4rem}

.alert{padding:.8rem 1rem;border-radius:var(--r-lg);font-size:.875rem;font-weight:600;margin-bottom:1.2rem;display:flex;align-items:center;gap:.5rem}
.alert svg{width:15px;height:15px;flex-shrink:0}
.alert.success{background:var(--em-soft);color:var(--em-dk);border:1px solid #b3f0de}
.alert.error{background:#fff0f0;color:#c0392b;border:1px solid #f5c6cb}

.form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:1.2rem 1.25rem}
@media(max-width:768px){.form-grid{grid-template-columns:1fr}}
.fg{display:flex;flex-direction:column;gap:.3rem}
.fl{font-size:.72rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.04em}
.fc{padding:.75rem .9rem;border:1.5px solid var(--border-color);border-radius:var(--r-md);font-size:.875rem;font-family:inherit;background:#fafbfc;color:var(--text-primary);outline:none;transition:border-color .15s,box-shadow .15s;width:100%}
.fc:focus{border-color:var(--ind);box-shadow:0 0 0 3px rgba(79,110,247,.1)}
.fh{font-size:.7rem;color:var(--text-secondary)}
.btn-save{display:inline-flex;align-items:center;gap:.45rem;margin-top:1.2rem;padding:.78rem 1.6rem;background:linear-gradient(135deg,var(--ind),var(--ind-dk));border:none;border-radius:var(--r-md);color:#fff;font-size:.875rem;font-weight:700;font-family:inherit;cursor:pointer;box-shadow:0 4px 12px rgba(79,110,247,.28);transition:all .2s}
.btn-save:hover{transform:translateY(-2px);box-shadow:0 8px 18px rgba(79,110,247,.35)}
.btn-save svg{width:15px;height:15px}

.total-pill{display:inline-flex;align-items:center;gap:.4rem;padding:.35rem .9rem;border-radius:var(--r-full);background:var(--em-soft);color:var(--em-dk);font-size:.8rem;font-weight:700;border:1px solid #b3f0de;margin-left:auto}
.total-pill svg{width:12px;height:12px}

.ptable{width:100%;border-collapse:collapse;font-size:.875rem}
.ptable thead th{padding:.8rem 1rem;text-align:left;font-size:.7rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--border-color);background:#fafbfc}
.ptable tbody tr{border-bottom:1px solid #f3f5f9;transition:background .12s}
.ptable tbody tr:last-child{border-bottom:none}
.ptable tbody tr:hover{background:#fafbff}
.ptable tbody td{padding:.8rem 1rem;vertical-align:middle}
.pbadge{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:var(--r-full);font-size:.7rem;font-weight:700}
.pbadge.done{background:var(--em-soft);color:var(--em-dk);border:1px solid #b3f0de}
.pbadge.pend{background:var(--amb-soft);color:#78350f;border:1px solid #fde68a}
.pbadge-dot{width:5px;height:5px;border-radius:50%;background:currentColor}
.amt{font-family:var(--mono);font-weight:700;color:var(--em-dk)}
.ref{font-family:var(--mono);font-size:.8rem;color:var(--ind)}
.empty-p{text-align:center;padding:2.5rem 1rem;color:var(--text-secondary);font-size:.875rem}
.empty-p svg{display:block;margin:0 auto .7rem;opacity:.3;width:32px;height:32px}
.ts-chip{font-size:.7rem;color:var(--text-secondary);background:#f8f9fc;border:1px solid var(--border-color);border-radius:var(--r-full);padding:.18rem .65rem;display:inline-flex;align-items:center;gap:.3rem}
.ts-chip svg{width:10px;height:10px}
/* Proof thumbnail */
.proof-thumb{width:34px;height:34px;object-fit:cover;border-radius:6px;border:1px solid var(--border-color);cursor:pointer;transition:transform .15s;display:block}
.proof-thumb:hover{transform:scale(1.1)}
/* Lightbox */
.lb-overlay{position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9999;display:none;align-items:center;justify-content:center;cursor:zoom-out}
.lb-overlay.open{display:flex}
.lb-overlay img{max-width:92vw;max-height:92vh;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.5)}
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
        <h2 style="font-size:1.05rem;font-weight:800;margin:0;color:var(--text-primary)">Bank Details</h2>
        <p style="font-size:.74rem;color:var(--text-secondary);margin:0">Manage your bank &amp; UPI info for HO payments</p>
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
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?php endif; ?>
      <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <!-- Bank Details Form -->
    <div class="module-card">
      <div class="mc-head">
        <div class="mc-icon">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 14h.01M10 14h.01M14 14h.01"/></svg>
        </div>
        <div style="flex:1">
          <div class="mc-title">Your Bank / UPI Details</div>
          <div class="mc-sub">HO Admin will send your share amount to these details</div>
        </div>
        <?php if (!empty($dlc['bank_updated_at'])): ?>
        <span class="ts-chip">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          Updated: <?= date('d M Y', strtotime($dlc['bank_updated_at'])) ?>
        </span>
        <?php endif; ?>
      </div>
      <div class="mc-body">
        <form method="POST">
          <div class="form-grid">
            <div class="fg">
              <label class="fl" for="bank_name">Bank Name</label>
              <input type="text" id="bank_name" name="bank_name" class="fc" placeholder="e.g. State Bank of India" value="<?= htmlspecialchars($dlc['bank_name'] ?? '') ?>">
            </div>
            <div class="fg">
              <label class="fl" for="account_holder">Account Holder Name</label>
              <input type="text" id="account_holder" name="account_holder" class="fc" placeholder="Name as per bank records" value="<?= htmlspecialchars($dlc['account_holder'] ?? '') ?>">
            </div>
            <div class="fg">
              <label class="fl" for="account_no">Account Number</label>
              <input type="text" id="account_no" name="account_no" class="fc" placeholder="Enter account number" value="<?= htmlspecialchars($dlc['account_no'] ?? '') ?>">
            </div>
            <div class="fg">
              <label class="fl" for="ifsc_code">IFSC Code</label>
              <input type="text" id="ifsc_code" name="ifsc_code" class="fc" placeholder="e.g. SBIN0001234" maxlength="20" style="text-transform:uppercase" value="<?= htmlspecialchars($dlc['ifsc_code'] ?? '') ?>">
              <span class="fh">11-character bank branch code</span>
            </div>
            <div class="fg">
              <label class="fl" for="upi_id">UPI ID (Optional)</label>
              <input type="text" id="upi_id" name="upi_id" class="fc" placeholder="e.g. name@upi or 9876543210@paytm" value="<?= htmlspecialchars($dlc['upi_id'] ?? '') ?>">
            </div>
          </div>
          <button type="submit" name="save_bank" class="btn-save">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Save Bank Details
          </button>
        </form>
      </div>
    </div>

    <!-- Payments Received -->
    <div class="module-card">
      <div class="mc-head">
        <div class="mc-icon green">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div style="flex:1">
          <div class="mc-title">Share Payments Received from Head Office</div>
          <div class="mc-sub">Payments recorded by Admin on your account</div>
        </div>
        <?php if ($totalReceived > 0): ?>
        <span class="total-pill">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
          Total: &#8377;<?= number_format($totalReceived, 2) ?>
        </span>
        <?php endif; ?>
      </div>
      <div class="mc-body" style="padding:0">
        <?php if (empty($payments)): ?>
        <div class="empty-p">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
          No share payments recorded yet.
        </div>
        <?php else: ?>
        <table class="ptable">
          <thead><tr><th>#</th><th>Date</th><th>Amount</th><th>Mode</th><th>Ref / UTR</th><th>Remarks</th><th>Proof</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($payments as $i => $p): ?>
          <tr>
            <td style="font-size:.8rem;color:var(--text-secondary)"><?= $i + 1 ?></td>
            <td style="font-weight:600"><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
            <td class="amt">&#8377;<?= number_format($p['amount'], 2) ?></td>
            <td><?= htmlspecialchars($p['payment_mode']) ?></td>
            <td class="ref"><?= htmlspecialchars($p['reference_no'] ?? '—') ?></td>
            <td style="font-size:.82rem;color:var(--text-secondary)"><?= htmlspecialchars($p['remarks'] ?? '—') ?></td>
            <td>
              <?php if (!empty($p['proof_image'])): ?>
              <img src="../uploads/payment_proofs/<?= htmlspecialchars($p['proof_image']) ?>" class="proof-thumb" onclick="openLightbox(this.src)" title="Tap to view payment proof">
              <?php else: ?>
              <span style="color:#ccc;font-size:.75rem">—</span>
              <?php endif; ?>
            </td>
            <td><span class="pbadge <?= $p['status']==='Completed'?'done':'pend' ?>"><span class="pbadge-dot"></span><?= $p['status'] ?></span></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

  </div>
</main>
</div>
<script src="../assets/js/dashboard.js"></script>
<script>
// Lightbox for payment proof
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
