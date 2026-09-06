<?php
/**
 * Admin — Record offline / cash HO share payment for an ATC.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
ensureSharePaymentSchema($pdo);

$msg = '';
$msgType = '';
$selAtcId = (int)($_GET['atc_id'] ?? $_POST['atc_id'] ?? 0);

// ── Record cash / offline payment ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_cash_share'])) {
    $selAtcId = (int)($_POST['atc_id'] ?? 0);
    $studentIds = array_map('intval', $_POST['student_ids'] ?? []);
    $mode = trim((string)($_POST['payment_mode'] ?? 'Cash'));
    $ref = trim((string)($_POST['reference_no'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    $payDate = trim((string)($_POST['payment_date'] ?? date('Y-m-d')));
    $includeFee = !empty($_POST['include_txn_fee']);
    $txnFee = $includeFee ? 15.0 : 0.0;

    $result = recordOfflineSharePayment($pdo, $selAtcId, $studentIds, $mode, $ref, $remarks, $payDate, $txnFee);
    $msg = $result['message'] ?? ($result['success'] ? 'Saved.' : 'Failed.');
    $msgType = $result['success'] ? 'success' : 'error';

    if ($result['success'] && !empty($result['payment_id'])) {
        header('Location: share_receipts.php?highlight=' . (int)$result['payment_id']);
        exit;
    }
}

// ATC list
$atcs = $pdo->query("
    SELECT id, name, atc_code, district
    FROM atc_centers
    WHERE status = 'Active'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$unpaidStudents = [];
$shareMapPack = ['map' => [], 'default' => 0.0];
$selAtcName = '';

if ($selAtcId > 0) {
    foreach ($atcs as $a) {
        if ((int)$a['id'] === $selAtcId) {
            $selAtcName = $a['name'] . (!empty($a['atc_code']) ? ' (' . $a['atc_code'] . ')' : '');
            break;
        }
    }

    $paidMap = [];
    try {
        $sp = $pdo->prepare("SELECT student_ids FROM share_payments WHERE atc_id = ? AND status = 'Completed'");
        $sp->execute([$selAtcId]);
        foreach ($sp->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $ids = json_decode((string)$json, true);
            if (is_array($ids)) {
                foreach ($ids as $id) {
                    $paidMap[(int)$id] = true;
                }
            }
        }
    } catch (Exception $e) {}

    $shareMapPack = buildHoShareAmountMap($pdo, $selAtcId);
    $st = $pdo->prepare("
        SELECT id, roll_no, registration_id, first_name, middle_name, last_name, course,
               material_type, COALESCE(ho_share_snapshot, 0) AS ho_share_snapshot
        FROM admissions
        WHERE atc_id = ? AND status = 'Active'
        ORDER BY roll_no ASC, id ASC
    ");
    $st->execute([$selAtcId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($paidMap[(int)$row['id']])) {
            continue;
        }
        $snap = ((float)$row['ho_share_snapshot'] > 0) ? (float)$row['ho_share_snapshot'] : null;
        $row['share_amount'] = resolveAdmissionHoShareAmount(
            (string)$row['course'],
            $shareMapPack['map'],
            (float)$shareMapPack['default'],
            $snap,
            $row['material_type'] ?? null
        );
        $row['full_name'] = trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']);
        $unpaidStudents[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Record Cash Share — Admin | Gyanam India</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<link rel="stylesheet" href="../assets/css/notifications.css">
<style>
:root { --font:'Sora',sans-serif; --mono:'JetBrains Mono',monospace; }
.cash-page { padding:1.5rem 2rem; max-width:none; width:100%; }
.cash-card { background:#fff; border:1.5px solid #e5e7eb; border-radius:14px; padding:1.25rem 1.4rem; margin-bottom:1.25rem; }
.cash-card h3 { margin:0 0 .85rem; font-size:1rem; font-weight:800; }
.cash-alert { padding:.85rem 1rem; border-radius:10px; margin-bottom:1rem; font-size:.88rem; font-weight:600; }
.cash-alert.ok { background:#ecfdf5; border:1.5px solid #a7f3d0; color:#065f46; }
.cash-alert.err { background:#fef2f2; border:1.5px solid #fecaca; color:#991b1b; }
.cash-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:.85rem; }
.cash-grid label { display:block; font-size:.72rem; font-weight:800; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:.3rem; }
.cash-grid select, .cash-grid input, .cash-grid textarea {
    width:100%; height:40px; border:1.5px solid #e5e7eb; border-radius:8px; padding:0 .75rem;
    font-family:var(--font); font-size:.88rem; background:#fff;
}
.cash-grid textarea { height:auto; min-height:72px; padding:.65rem .75rem; }
.cash-table-wrap { overflow:auto; border:1.5px solid #e5e7eb; border-radius:12px; }
.cash-table { width:100%; border-collapse:collapse; font-size:.84rem; }
.cash-table th { background:#f8fafc; text-align:left; padding:.7rem .85rem; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; border-bottom:1.5px solid #e5e7eb; }
.cash-table td { padding:.65rem .85rem; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.cash-table tr:hover td { background:#f8fafc; }
.cash-amt { font-family:var(--mono); font-weight:700; color:#065f46; }
.cash-bar { display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; justify-content:space-between; margin-top:1rem; padding-top:1rem; border-top:1.5px solid #f1f5f9; }
.cash-total { font-size:1.05rem; font-weight:800; }
.cash-total span { font-family:var(--mono); color:#059669; }
.btn-cash { display:inline-flex; align-items:center; gap:.4rem; height:42px; padding:0 1.2rem; border:none; border-radius:10px; background:#059669; color:#fff; font-weight:800; font-family:var(--font); cursor:pointer; }
.btn-cash:disabled { opacity:.45; cursor:not-allowed; }
.btn-ghost { display:inline-flex; align-items:center; height:42px; padding:0 1rem; border:1.5px solid #e5e7eb; border-radius:10px; background:#fff; color:#374151; font-weight:700; text-decoration:none; font-family:var(--font); font-size:.86rem; }
.empty-box { padding:2rem; text-align:center; color:#9ca3af; font-size:.9rem; }
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <div class="page-content cash-page">

        <div class="page-header" style="margin-bottom:1.25rem;display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
            <div>
                <h2>Record Cash / Offline Share</h2>
                <p>Mark HO share as paid for cash/bank, or record an already-paid Razorpay UPI (includes ₹15 fee).</p>
            </div>
            <a class="btn-ghost" href="share_payments.php">← Back to Share Payments</a>
        </div>

        <?php if ($msg): ?>
        <div class="cash-alert <?= $msgType === 'success' ? 'ok' : 'err' ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <div class="cash-card">
            <h3>1. Select ATC</h3>
            <form method="get" class="cash-grid">
                <div style="grid-column:1/-1;max-width:480px">
                    <label>ATC Center</label>
                    <select name="atc_id" onchange="this.form.submit()">
                        <option value="0">— Choose ATC —</option>
                        <?php foreach ($atcs as $a): ?>
                        <option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === $selAtcId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['name']) ?>
                            <?= !empty($a['atc_code']) ? '(' . htmlspecialchars($a['atc_code']) . ')' : '' ?>
                            <?= !empty($a['district']) ? ' · ' . htmlspecialchars($a['district']) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>

        <?php if ($selAtcId > 0): ?>
        <form method="post" id="cashShareForm">
            <input type="hidden" name="atc_id" value="<?= $selAtcId ?>">
            <input type="hidden" name="record_cash_share" value="1">

            <div class="cash-card">
                <h3>2. Unpaid students — <?= htmlspecialchars($selAtcName) ?></h3>
                <?php if (empty($unpaidStudents)): ?>
                <div class="empty-box">All active students for this ATC already have HO share paid.</div>
                <?php else: ?>
                <div class="cash-table-wrap">
                    <table class="cash-table">
                        <thead>
                            <tr>
                                <th style="width:42px"><input type="checkbox" id="checkAll" title="Select all"></th>
                                <th>Roll / Reg</th>
                                <th>Student</th>
                                <th>Course</th>
                                <th>Material</th>
                                <th style="text-align:right">HO Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unpaidStudents as $s): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="stu-check" name="student_ids[]"
                                           value="<?= (int)$s['id'] ?>"
                                           data-amount="<?= htmlspecialchars((string)$s['share_amount']) ?>">
                                </td>
                                <td>
                                    <div style="font-weight:700"><?= htmlspecialchars($s['roll_no']) ?></div>
                                    <div style="font-size:.75rem;color:#94a3b8"><?= htmlspecialchars($s['registration_id'] ?? '') ?></div>
                                </td>
                                <td style="font-weight:600"><?= htmlspecialchars($s['full_name']) ?></td>
                                <td><?= htmlspecialchars($s['course']) ?></td>
                                <td><?= htmlspecialchars($s['material_type'] ?? '—') ?></td>
                                <td style="text-align:right" class="cash-amt">₹<?= number_format((float)$s['share_amount'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($unpaidStudents)): ?>
            <div class="cash-card">
                <h3>3. Payment details</h3>
                <div class="cash-grid">
                    <div>
                        <label>Payment mode *</label>
                        <select name="payment_mode" id="paymentMode" required>
                            <option value="Razorpay" selected>Razorpay / PhonePe (already paid)</option>
                            <option value="UPI">UPI</option>
                            <option value="Cash">Cash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Cheque">Cheque</option>
                        </select>
                    </div>
                    <div>
                        <label>Payment date *</label>
                        <input type="date" name="payment_date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
                    </div>
                    <div>
                        <label>Reference / UTR / receipt no.</label>
                        <input type="text" name="reference_no" placeholder="e.g. PhonePe UTR 105324340617">
                    </div>
                    <div style="grid-column:1/-1">
                        <label style="display:flex;align-items:center;gap:.5rem;text-transform:none;font-size:.88rem;font-weight:700;color:#065f46;cursor:pointer">
                            <input type="checkbox" name="include_txn_fee" id="includeTxnFee" value="1" checked style="width:18px;height:18px">
                            Include ₹15 Razorpay transaction fee (total = share + ₹15)
                        </label>
                    </div>
                    <div style="grid-column:1/-1">
                        <label>Remarks</label>
                        <textarea name="remarks" placeholder="Optional note (who paid, place, etc.)"></textarea>
                    </div>
                </div>

                <div class="cash-bar">
                    <div class="cash-total">
                        Selected: <span id="selCount">0</span>
                        · Share: <span id="selShare">₹0.00</span>
                        · Fee: <span id="selFee">₹15.00</span>
                        · Total: <span id="selTotal">₹0.00</span>
                        <div id="feeHint" style="font-size:.75rem;font-weight:600;color:#059669;margin-top:.25rem">Includes ₹15 gateway fee (matches Razorpay charge)</div>
                    </div>
                    <button type="submit" class="btn-cash" id="submitBtn" disabled
                            onclick="return confirm('Record share payment for the selected students?');">
                        Record payment
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </form>
        <?php endif; ?>

    </div>
</main>
</div>
<script src="../assets/js/dashboard.js"></script>
<script>
(function () {
    var TXN_FEE = 15;
    var checks = Array.prototype.slice.call(document.querySelectorAll('.stu-check'));
    var all = document.getElementById('checkAll');
    var countEl = document.getElementById('selCount');
    var shareEl = document.getElementById('selShare');
    var feeEl = document.getElementById('selFee');
    var totalEl = document.getElementById('selTotal');
    var feeHint = document.getElementById('feeHint');
    var feeBox = document.getElementById('includeTxnFee');
    var modeEl = document.getElementById('paymentMode');
    var btn = document.getElementById('submitBtn');
    if (!checks.length) return;

    function feeAmount() {
        return (feeBox && feeBox.checked) ? TXN_FEE : 0;
    }

    function refresh() {
        var n = 0, sum = 0;
        checks.forEach(function (c) {
            if (c.checked) {
                n++;
                sum += parseFloat(c.getAttribute('data-amount') || '0') || 0;
            }
        });
        var fee = feeAmount();
        var total = sum + fee;
        if (countEl) countEl.textContent = String(n);
        if (shareEl) shareEl.textContent = '₹' + sum.toFixed(2);
        if (feeEl) feeEl.textContent = '₹' + fee.toFixed(2);
        if (totalEl) totalEl.textContent = '₹' + total.toFixed(2);
        if (feeHint) {
            feeHint.textContent = fee > 0
                ? 'Includes ₹15 gateway fee (matches Razorpay charge)'
                : 'No transaction fee (pure offline cash/cheque)';
            feeHint.style.color = fee > 0 ? '#059669' : '#94a3b8';
        }
        if (btn) btn.disabled = n === 0;
        if (all) {
            all.checked = n > 0 && n === checks.length;
            all.indeterminate = n > 0 && n < checks.length;
        }
    }

    function syncFeeDefault() {
        if (!feeBox || !modeEl) return;
        var m = modeEl.value;
        // Razorpay / UPI receipts should include fee by default; cash/cheque off
        feeBox.checked = (m === 'Razorpay' || m === 'UPI');
        refresh();
    }

    checks.forEach(function (c) { c.addEventListener('change', refresh); });
    if (all) {
        all.addEventListener('change', function () {
            checks.forEach(function (c) { c.checked = all.checked; });
            refresh();
        });
    }
    if (feeBox) feeBox.addEventListener('change', refresh);
    if (modeEl) modeEl.addEventListener('change', syncFeeDefault);
    syncFeeDefault();
})();
</script>
</body>
</html>
