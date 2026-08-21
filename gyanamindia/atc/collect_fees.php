<?php
/**
 * Gyanam Portal — ATC: Collect Fees (Full Page)
 * URL: collect_fees.php?id=X
 * Premium full-page layout replacing the small modal from fees.php
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo   = getDBConnection();
$atcId = $_SESSION['atc_id'] ?? null;
$stuId = intval($_GET['id'] ?? 0);

if (!$stuId || !$atcId) { header('Location: fees.php'); exit; }

// ── AJAX: Record payment ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'record_payment') {
        try {
            $studentId   = intval($_POST['student_id'] ?? 0);
            $amount      = floatval($_POST['amount'] ?? 0);
            $paymentMode = $_POST['payment_mode'] ?? null;
            $description = $_POST['description'] ?? null;
            $remarks     = $_POST['remarks'] ?? null;

            if (!$studentId || $amount <= 0 || !$paymentMode) {
                echo json_encode(['success' => false, 'message' => 'Invalid input data']);
                exit;
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT fees_paid, fees_pending, net_payable, first_name, middle_name, last_name, roll_no, course, mobile FROM admissions WHERE id = ? AND atc_id = ?");
            $stmt->execute([$studentId, $atcId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$student) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Student not found']);
                exit;
            }

            if ($amount > $student['fees_pending']) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Payment amount exceeds pending fees']);
                exit;
            }

            $newPaid    = $student['fees_paid'] + $amount;
            $newPending = $student['fees_pending'] - $amount;

            $stmt = $pdo->prepare("UPDATE admissions SET fees_paid = ?, fees_pending = ? WHERE id = ? AND atc_id = ?");
            $stmt->execute([$newPaid, $newPending, $studentId, $atcId]);

            $stmt = $pdo->prepare("SELECT COALESCE(MAX(installment_no), 0) + 1 as next_installment FROM fee_payments WHERE admission_id = ?");
            $stmt->execute([$studentId]);
            $installmentNo = $stmt->fetchColumn();

            // Generate ATC-prefixed receipt number
            $atcNameStmt = $pdo->prepare("SELECT name FROM atc_centers WHERE id = ?");
            $atcNameStmt->execute([$atcId]);
            $atcRawName  = (string)($atcNameStmt->fetchColumn() ?: 'ATC');
            preg_match('/^[A-Za-z]+/', trim($atcRawName), $atcMatch);
            $atcPrefix   = strtoupper(substr($atcMatch[0] ?? 'ATC', 0, 8));
            $countStmt   = $pdo->prepare("SELECT COUNT(*) FROM fee_payments WHERE atc_id = ?");
            $countStmt->execute([$atcId]);
            $nextSeq     = intval($countStmt->fetchColumn()) + 1;
            $receiptNo   = $atcPrefix . '-' . str_pad($nextSeq, 5, '0', STR_PAD_LEFT);
            $dupCheck    = $pdo->prepare("SELECT COUNT(*) FROM fee_payments WHERE receipt_no = ?");
            $dupCheck->execute([$receiptNo]);
            if (intval($dupCheck->fetchColumn()) > 0) {
                $receiptNo = $atcPrefix . '-' . str_pad($nextSeq + intval(microtime(true) * 10) % 100, 5, '0', STR_PAD_LEFT);
            }

            $stmt = $pdo->prepare("
                INSERT INTO fee_payments (
                    admission_id, atc_id, installment_no, receipt_no, amount, payment_mode,
                    transaction_ref, remarks, description, payment_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $studentId, $atcId, $installmentNo, $receiptNo, $amount,
                $paymentMode, $_POST['transaction_ref'] ?? null, $remarks, $description
            ]);

            $newPaymentId = $pdo->lastInsertId();

            try {
                $stmt = $pdo->prepare("INSERT INTO student_remarks (admission_id, atc_id, remark_type, remark_text, created_by) VALUES (?, ?, 'Payment', ?, ?)");
                $remarkText = "Payment of Rs. " . number_format($amount, 2) . " received via " . $paymentMode . " (Receipt: " . $receiptNo . ")";
                $stmt->execute([$studentId, $atcId, $remarkText, $_SESSION['user_id']]);
            } catch (Exception $e) {}

            $pdo->commit();

            echo json_encode([
                'success'        => true,
                'message'        => 'Payment recorded successfully',
                'receipt_no'     => $receiptNo,
                'payment_id'     => $newPaymentId,
                'installment_no' => $installmentNo,
                'new_paid'       => $newPaid,
                'new_pending'    => $newPending,
                'student_name'   => trim($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name']),
                'student_mobile' => $student['mobile'] ?? null,
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
}

// ── Load student ──────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM admissions WHERE id = ? AND atc_id = ?");
$stmt->execute([$stuId, $atcId]);
$stu = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$stu) { header('Location: fees.php'); exit; }

$fullName   = trim(($stu['first_name'] ?? '') . ' ' . ($stu['middle_name'] ? $stu['middle_name'] . ' ' : '') . ($stu['last_name'] ?? ''));
$netPayable = floatval($stu['net_payable'] ?? $stu['fees_total'] ?? 0);
$feesPaid   = floatval($stu['fees_paid'] ?? 0);
$balance    = max(0, floatval($stu['fees_pending'] ?? ($netPayable - $feesPaid)));
$courseFees = floatval($stu['course_fees'] ?? $netPayable);
$discount   = floatval($stu['discount_amount'] ?? 0);
$feeStatus  = $balance <= 0 ? 'Cleared' : ($feesPaid > 0 ? 'Partial' : 'Pending');

// Fetch ATC name for WhatsApp messages
$atcNameRow = $pdo->prepare("SELECT name FROM atc_centers WHERE id = ?");
$atcNameRow->execute([$atcId]);
$atcDisplayName = $atcNameRow->fetchColumn() ?: 'Gyanam ATC';

// ── Load payment history ──────────────────────────────────────────────────
$payments = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM fee_payments WHERE admission_id = ? AND atc_id = ? ORDER BY payment_date DESC, id DESC");
    $stmt->execute([$stuId, $atcId]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM fee_payments WHERE admission_id = ? ORDER BY payment_date DESC, id DESC");
        $stmt->execute([$stuId]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {}
}

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Collect Fees — <?= e($fullName) ?> | Gyanam India</title>
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<link rel="stylesheet" href="../assets/css/notifications.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>💰</text></svg>">
<style>
/* ── Layout ── */
.cf-topbar { display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;gap:1rem;flex-wrap:wrap; }
.cf-title { font-size:1.25rem;font-weight:800;color:var(--text-primary);display:flex;align-items:center;gap:.5rem; }
.cf-subtitle { font-size:.85rem;color:var(--text-secondary);margin-top:.15rem; }
.cf-back { display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;border-radius:10px;border:1.5px solid var(--border-color);background:#fff;color:var(--text-primary);font-size:.84rem;font-weight:700;text-decoration:none;transition:all .18s; }
.cf-back:hover { background:var(--gray-50);border-color:var(--gray-300); }
.cf-back svg { width:16px;height:16px; }

/* ── Student Banner ── */
.cf-banner {
    display:flex;align-items:center;gap:1.25rem;
    background:linear-gradient(135deg,#eef2ff,#e0e7ff);
    border:1.5px solid #a5b4fc;border-radius:16px;
    padding:1.25rem 1.5rem;margin-bottom:1.75rem;
    position:relative;overflow:hidden;
}
.cf-banner::before {
    content:'';position:absolute;top:0;right:0;width:200px;height:200px;
    background:radial-gradient(circle,rgba(99,102,241,.08),transparent 70%);
    pointer-events:none;
}
.cf-avatar {
    width:60px;height:60px;border-radius:50%;flex-shrink:0;
    background:linear-gradient(135deg,#4361ee,#3730a3);
    display:flex;align-items:center;justify-content:center;
    font-size:1.5rem;font-weight:800;color:#fff;
    box-shadow:0 4px 16px rgba(67,97,238,.25);
}
.cf-student-name { font-size:1.15rem;font-weight:800;color:#1e3a8a; }
.cf-student-meta { font-size:.82rem;color:#3730a3;font-weight:600;margin-top:.15rem; }
.cf-status-pill {
    position:absolute;top:1rem;right:1.25rem;
    display:inline-flex;align-items:center;gap:.3rem;
    padding:.3rem .8rem;border-radius:999px;font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;
}
.cf-status-pill.cleared { background:#d1fae5;color:#065f46;border:1px solid #6ee7b7; }
.cf-status-pill.partial { background:#fef3c7;color:#92400e;border:1px solid #fcd34d; }
.cf-status-pill.pending { background:#fee2e2;color:#991b1b;border:1px solid #fca5a5; }

/* ── Fee Cards ── */
.cf-cards { display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.75rem; }
.cf-card {
    background:#fff;border:1.5px solid var(--border-color);border-radius:14px;
    padding:1.1rem 1.25rem;position:relative;overflow:hidden;
    transition:transform .2s,box-shadow .2s;
}
.cf-card:hover { transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.06); }
.cf-card::before { content:'';position:absolute;top:0;left:0;right:0;height:3px; }
.cf-card.gross::before { background:linear-gradient(90deg,#6366f1,#8b5cf6); }
.cf-card.discount::before { background:linear-gradient(90deg,#f59e0b,#f97316); }
.cf-card.paid::before { background:linear-gradient(90deg,#10b981,#059669); }
.cf-card.due::before { background:linear-gradient(90deg,#ef4444,#dc2626); }
.cf-card-label { font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:.4rem; }
.cf-card-value { font-size:1.45rem;font-weight:800;color:var(--text-primary);font-family:'JetBrains Mono',monospace; }
.cf-card-value.green { color:#059669; }
.cf-card-value.red { color:#dc2626; }
.cf-card-value.orange { color:#d97706; }
.cf-card-value.purple { color:#6366f1; }

/* ── Two Column Layout ── */
.cf-columns { display:grid;grid-template-columns:1fr 1fr;gap:1.5rem; }

/* ── Section panels ── */
.cf-section {
    background:#fff;border:1.5px solid var(--border-color);border-radius:16px;
    overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.04);
}
.cf-section-header {
    display:flex;align-items:center;gap:.65rem;
    padding:1rem 1.25rem;border-bottom:1.5px solid var(--border-color);
    background:linear-gradient(135deg,#fafbfc,#f3f4f6);
    font-size:.88rem;font-weight:800;color:var(--text-primary);
}
.cf-section-header svg { width:18px;height:18px;color:#4361ee; }
.cf-section-body { padding:1.5rem 1.25rem; }

/* ── Form styling ── */
.cf-form-grid { display:flex;flex-direction:column;gap:1.1rem; }
.cf-form-row { display:grid;grid-template-columns:1fr 1fr;gap:1rem; }
.cf-field { display:flex;flex-direction:column;gap:.35rem; }
.cf-field.full { grid-column:1/-1; }
.cf-label { font-size:.78rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.03em; }
.cf-input, .cf-select {
    height:44px;padding:0 .9rem;border:1.5px solid var(--border-color);border-radius:10px;
    font-size:.9rem;font-family:inherit;color:var(--text-primary);background:#fff;
    outline:none;transition:border-color .18s,box-shadow .18s;width:100%;box-sizing:border-box;
}
.cf-input:focus, .cf-select:focus { border-color:#4361ee;box-shadow:0 0 0 3px rgba(67,97,238,.1); }
.cf-input.error { border-color:#ef4444; }
.cf-hint { font-size:.75rem;color:#dc2626;font-weight:600;display:none; }
.cf-hint.show { display:block; }

/* ── Collect button ── */
.cf-collect-btn {
    width:100%;height:50px;border-radius:14px;border:none;cursor:pointer;
    background:linear-gradient(135deg,#10b981,#059669);color:#fff;
    font-size:1rem;font-weight:800;font-family:inherit;
    display:flex;align-items:center;justify-content:center;gap:.6rem;
    box-shadow:0 6px 20px rgba(16,185,129,.3);transition:all .2s;
    margin-top:.5rem;
}
.cf-collect-btn:hover { transform:translateY(-2px);box-shadow:0 8px 28px rgba(16,185,129,.4); }
.cf-collect-btn:disabled { opacity:.6;transform:none;cursor:not-allowed; }
.cf-collect-btn svg { width:20px;height:20px; }

/* ── Payment History ── */
.cf-history-item {
    display:flex;align-items:center;justify-content:space-between;
    padding:.85rem 0;border-bottom:1px solid #f3f4f6;
    gap:.75rem;
}
.cf-history-item:last-child { border-bottom:none; }
.cf-h-left { display:flex;align-items:center;gap:.8rem;flex:1;min-width:0; }
.cf-h-num {
    width:36px;height:36px;border-radius:10px;flex-shrink:0;
    background:linear-gradient(135deg,#eef2ff,#dbeafe);
    display:flex;align-items:center;justify-content:center;
    font-size:.75rem;font-weight:800;color:#4361ee;
}
.cf-h-receipt { font-size:.7rem;font-weight:800;color:#3730a3;background:#eef2ff;border:1px solid #c7d2fe;border-radius:999px;padding:.12rem .5rem;display:inline-block; }
.cf-h-amount { font-size:.9rem;font-weight:800;color:#111827;font-family:'JetBrains Mono',monospace; }
.cf-h-meta { font-size:.73rem;color:#6b7280;margin-top:.15rem; }
.cf-h-mode { display:inline-flex;padding:.12rem .5rem;border-radius:6px;font-size:.68rem;font-weight:700; }
.cf-h-mode.cash { background:#ecfdf5;color:#059669; }
.cf-h-mode.upi { background:#eef2ff;color:#4361ee; }
.cf-h-mode.online { background:#fdf4ff;color:#7e22ce; }
.cf-h-mode.cheque { background:#fef3c7;color:#92400e; }
.cf-h-actions { display:flex;gap:.4rem;flex-shrink:0; }
.cf-h-btn {
    height:30px;padding:0 .65rem;border-radius:8px;border:1px solid #e5e7eb;
    background:#fff;color:#374151;font-size:.72rem;font-weight:700;
    text-decoration:none;display:inline-flex;align-items:center;gap:.25rem;
    cursor:pointer;transition:all .15s;
}
.cf-h-btn:hover { border-color:#a5b4fc;color:#1e3a8a;background:#eef2ff; }
.cf-h-btn svg { width:13px;height:13px; }
.cf-empty { text-align:center;padding:2rem;color:#9ca3af;font-size:.85rem; }

/* ── Progress bar ── */
.cf-progress-wrap { margin-bottom:1.75rem; }
.cf-progress-bar { width:100%;height:10px;background:#e5e7eb;border-radius:999px;overflow:hidden; }
.cf-progress-fill { height:100%;border-radius:999px;transition:width .8s ease;position:relative;overflow:hidden; }
.cf-progress-fill::after { content:'';position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.3),transparent);animation:shimmer 2s infinite; }
@keyframes shimmer { 0%{transform:translateX(-100%)} 100%{transform:translateX(100%)} }
.cf-progress-info { display:flex;align-items:center;justify-content:space-between;margin-top:.5rem;font-size:.78rem;font-weight:700;color:var(--text-secondary); }

/* Toast */
#cfToast { position:fixed;bottom:1.5rem;right:1.5rem;z-index:99999;transform:translateY(100px);opacity:0;transition:all .3s cubic-bezier(.34,1.56,.64,1); }
#cfToast.show { transform:translateY(0);opacity:1; }

/* ── Responsive ── */
@media(max-width:860px) {
    .cf-columns { grid-template-columns:1fr; }
    .cf-cards { grid-template-columns:repeat(2,1fr); }
}
@media(max-width:560px) {
    .cf-cards { grid-template-columns:1fr; }
    .cf-form-row { grid-template-columns:1fr; }
}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
    <header class="top-header">
        <div class="header-left">
            <button class="hamburger" id="hamburgerBtn">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <div class="header-greeting">
                <h2>Collect Fees</h2>
                <p>Record payment for <?= e($fullName) ?></p>
            </div>
        </div>
        <div class="header-right">
            <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
            <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
        </div>
    </header>

    <div class="page-content">

        <!-- Top Bar -->
        <div class="cf-topbar">
            <div>
                <div class="cf-title">💰 Collect Fees</div>
                <div class="cf-subtitle">Record fee payment for <?= e($fullName) ?> · Roll: <?= e($stu['roll_no']) ?></div>
            </div>
            <a href="fees.php" class="cf-back">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                Back to Fees
            </a>
        </div>

        <!-- Student Banner -->
        <div class="cf-banner">
            <div class="cf-avatar"><?= strtoupper(mb_substr($stu['first_name'], 0, 1)) ?></div>
            <div>
                <div class="cf-student-name"><?= e($fullName) ?></div>
                <div class="cf-student-meta">Roll No: <?= e($stu['roll_no']) ?> · <?= e($stu['course']) ?> · <?= e($stu['mobile'] ?? '') ?></div>
            </div>
            <span class="cf-status-pill <?= strtolower($feeStatus) ?>"><?= $feeStatus ?></span>
        </div>

        <!-- Fee Summary Cards -->
        <div class="cf-cards">
            <div class="cf-card gross">
                <div class="cf-card-label">Course Fees</div>
                <div class="cf-card-value purple">₹<?= number_format($courseFees, 0) ?></div>
            </div>
            <div class="cf-card discount">
                <div class="cf-card-label">Discount</div>
                <div class="cf-card-value orange">₹<?= number_format($discount, 0) ?></div>
            </div>
            <div class="cf-card paid">
                <div class="cf-card-label">Total Paid</div>
                <div class="cf-card-value green">₹<?= number_format($feesPaid, 0) ?></div>
            </div>
            <div class="cf-card due">
                <div class="cf-card-label">Balance Due</div>
                <div class="cf-card-value red">₹<?= number_format($balance, 0) ?></div>
            </div>
        </div>

        <!-- Progress Bar -->
        <?php $pct = $netPayable > 0 ? min(100, round(($feesPaid / $netPayable) * 100, 1)) : 0;
              $barColor = $pct >= 100 ? '#10b981' : ($pct >= 50 ? '#f59e0b' : '#ef4444');
        ?>
        <div class="cf-progress-wrap">
            <div class="cf-progress-bar">
                <div class="cf-progress-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>;"></div>
            </div>
            <div class="cf-progress-info">
                <span>₹<?= number_format($feesPaid, 0) ?> of ₹<?= number_format($netPayable, 0) ?> paid</span>
                <span style="color:<?= $barColor ?>"><?= $pct ?>% collected</span>
            </div>
        </div>

        <!-- Two Column Layout -->
        <div class="cf-columns">

            <!-- LEFT: Payment Form -->
            <div class="cf-section">
                <div class="cf-section-header">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    Record New Payment
                </div>
                <div class="cf-section-body">
                    <?php if ($balance <= 0): ?>
                    <div style="text-align:center;padding:2rem 1rem;">
                        <div style="font-size:3rem;margin-bottom:.5rem;">✅</div>
                        <div style="font-size:1.1rem;font-weight:800;color:#059669;">All Fees Cleared!</div>
                        <div style="font-size:.85rem;color:#6b7280;margin-top:.35rem;">This student has no pending balance.</div>
                    </div>
                    <?php else: ?>
                    <div class="cf-form-grid">
                        <input type="hidden" id="studentId" value="<?= $stuId ?>">
                        <div class="cf-form-row">
                            <div class="cf-field">
                                <label class="cf-label">Amount (₹) <span style="color:#ef4444">*</span></label>
                                <input type="number" class="cf-input" id="feeAmount" min="1" max="<?= $balance ?>" step="1" placeholder="Enter amount" oninput="validateAmount()">
                                <div class="cf-hint" id="amountHint"></div>
                            </div>
                            <div class="cf-field">
                                <label class="cf-label">Payment Mode <span style="color:#ef4444">*</span></label>
                                <select class="cf-select" id="paymentMode">
                                    <option value="">— Select Mode —</option>
                                    <option value="Cash">Cash</option>
                                    <option value="UPI">UPI</option>
                                    <option value="Online Transfer">Online Transfer</option>
                                    <option value="Cheque">Cheque</option>
                                    <option value="DD">Demand Draft</option>
                                </select>
                            </div>
                        </div>
                        <div class="cf-field">
                            <label class="cf-label">Description / Reference</label>
                            <input type="text" class="cf-input" id="feeDescription" placeholder="e.g. 2nd installment, UPI txn ID..." maxlength="255">
                        </div>
                        <div class="cf-form-row">
                            <div class="cf-field">
                                <label class="cf-label">Installment Amount (₹)</label>
                                <input type="number" class="cf-input" id="installmentAmt" min="0" step="1" placeholder="Per installment">
                            </div>
                            <div class="cf-field">
                                <label class="cf-label">Next Installment Date</label>
                                <input type="date" class="cf-input" id="nextInstDate">
                            </div>
                        </div>
                        <button class="cf-collect-btn" id="collectBtn" onclick="collectFees()">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                            Collect ₹ — Payment
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIGHT: Payment History -->
            <div class="cf-section">
                <div class="cf-section-header">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Payment History
                    <span style="margin-left:auto;font-size:.72rem;font-weight:800;background:#eef2ff;color:#4361ee;padding:.2rem .65rem;border-radius:999px;"><?= count($payments) ?> receipt<?= count($payments) !== 1 ? 's' : '' ?></span>
                </div>
                <div class="cf-section-body" style="padding:.75rem 1.25rem;max-height:520px;overflow-y:auto;">
                    <?php if (empty($payments)): ?>
                    <div class="cf-empty">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="1.5" style="width:40px;height:40px;margin-bottom:.5rem;display:block;margin:0 auto .5rem"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
                        No payments recorded yet.
                    </div>
                    <?php else: ?>
                    <?php foreach ($payments as $i => $p):
                        $pAmt = floatval($p['amount']);
                        $pDate = date('d M Y', strtotime($p['payment_date']));
                        $pTime = date('h:i A', strtotime($p['payment_date']));
                        $pMode = $p['payment_mode'] ?? 'Cash';
                        $modeClass = strtolower(str_replace(' ', '', $pMode));
                        if (in_array($modeClass, ['onlinetransfer','dd','demandDraft'])) $modeClass = 'online';
                        $receipt = $p['receipt_no'] ?? ('PAY-'.$p['id']);
                    ?>
                    <div class="cf-history-item">
                        <div class="cf-h-left">
                            <div class="cf-h-num">#<?= $p['installment_no'] ?? ($i+1) ?></div>
                            <div>
                                <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                                    <span class="cf-h-receipt"><?= e($receipt) ?></span>
                                    <span class="cf-h-amount">₹<?= number_format($pAmt, 2) ?></span>
                                    <span class="cf-h-mode <?= $modeClass ?>"><?= e($pMode) ?></span>
                                </div>
                                <div class="cf-h-meta"><?= $pDate ?> · <?= $pTime ?></div>
                            </div>
                        </div>
                        <div class="cf-h-actions">
                            <a class="cf-h-btn" href="fee_receipt.php?payment_id=<?= $p['id'] ?>" target="_blank" title="View Receipt">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                Receipt
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /.cf-columns -->

    </div><!-- /.page-content -->
</main>
</div>

<!-- Toast -->
<div id="cfToast" style="background:#111827;color:#fff;padding:.875rem 1.25rem;border-radius:14px;font-size:.875rem;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.18);max-width:380px;display:flex;align-items:center;gap:.5rem;"></div>

<script src="../assets/js/dashboard.js"></script>
<script>
const MAX_BALANCE = <?= $balance ?>;
const _atcName    = <?= json_encode($atcDisplayName) ?>;
const _stuName    = <?= json_encode($fullName) ?>;
const _stuMobile  = <?= json_encode($stu['mobile'] ?? '') ?>;
const _stuCourse  = <?= json_encode($stu['course'] ?? '') ?>;

function validateAmount() {
    const input = document.getElementById('feeAmount');
    const hint  = document.getElementById('amountHint');
    const btn   = document.getElementById('collectBtn');
    const val   = parseFloat(input.value) || 0;

    if (val > MAX_BALANCE) {
        hint.textContent = '⚠️ Cannot exceed remaining balance of ₹' + MAX_BALANCE.toLocaleString('en-IN');
        hint.classList.add('show');
        input.classList.add('error');
        btn.disabled = true;
    } else if (val <= 0 && input.value !== '') {
        hint.textContent = '⚠️ Amount must be greater than 0.';
        hint.classList.add('show');
        input.classList.add('error');
        btn.disabled = true;
    } else {
        hint.classList.remove('show');
        input.classList.remove('error');
        btn.disabled = false;
    }

    // Update button text
    if (val > 0 && val <= MAX_BALANCE) {
        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Collect ₹' + val.toLocaleString('en-IN');
    } else {
        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Collect ₹ — Payment';
    }
}

function showToast(msg, type) {
    const t = document.getElementById('cfToast');
    const color = type === 'error' ? '#ef4444' : '#10b981';
    t.style.borderLeft = '4px solid ' + color;
    t.innerHTML = (type === 'error' ? '❌ ' : '✅ ') + msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 4000);
}

function collectFees() {
    const studentId = document.getElementById('studentId').value;
    const amount    = parseFloat(document.getElementById('feeAmount').value) || 0;
    const mode      = document.getElementById('paymentMode').value;
    const desc      = document.getElementById('feeDescription').value.trim();
    const nextDate  = document.getElementById('nextInstDate').value;
    const nextAmt   = document.getElementById('installmentAmt').value;

    if (amount <= 0) { showToast('Please enter a valid amount', 'error'); return; }
    if (!mode)       { showToast('Please select a payment mode', 'error'); return; }

    const btn = document.getElementById('collectBtn');
    btn.disabled = true;
    btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;animation:spin .7s linear infinite"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/></svg> Processing…';

    const fd = new FormData();
    fd.append('action', 'record_payment');
    fd.append('student_id', studentId);
    fd.append('amount', amount);
    fd.append('payment_mode', mode);
    fd.append('description', desc);
    fd.append('transaction_ref', nextDate);
    fd.append('remarks', 'Next installment: ' + (nextDate || '—') + (nextAmt ? ', Installment amt: ₹' + nextAmt : ''));

    fetch('', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('Payment recorded! Receipt: ' + data.receipt_no);
            if (data.payment_id) {
                setTimeout(() => window.open('fee_receipt.php?payment_id=' + data.payment_id, '_blank'), 700);
            }
            // WhatsApp Thank You prompt
            const mob = data.student_mobile || _stuMobile;
            if (mob && mob.length >= 10) {
                const newPendingFmt = Number(data.new_pending).toLocaleString('en-IN');
                const paidFmt = amount.toLocaleString('en-IN');
                setTimeout(() => {
                    const doSend = confirm('✅ Payment recorded!\n\nWould you like to send a Thank You WhatsApp message to ' + (data.student_name || _stuName) + '?');
                    if (doSend) sendPaymentThankYou(mob, data.student_name || _stuName, paidFmt, _stuCourse, newPendingFmt);
                }, 900);
            }
            setTimeout(() => location.reload(), 2200);
        } else {
            showToast(data.message || 'Error recording payment', 'error');
            btn.disabled = false;
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Collect ₹ — Payment';
        }
    })
    .catch(err => {
        showToast('Error: ' + (err.message || 'Unknown'), 'error');
        btn.disabled = false;
        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Collect ₹ — Payment';
    });
}

function sendPaymentThankYou(mobile, studentName, amountPaid, course, newBalance) {
    const phone = mobile.replace(/\D/g, '');
    const waPhone = phone.length === 10 ? '91' + phone : phone;
    const msg = `Dear Student, *${studentName}*, thank you for the payment of *Rs.${amountPaid}* for course *${course}*. Your balance amount is *Rs.${newBalance}*\n\nBest Regards,\n*${_atcName}*`;
    window.open('https://wa.me/' + waPhone + '?text=' + encodeURIComponent(msg), '_blank');
}
</script>
<style>@keyframes spin{to{transform:rotate(360deg)}}</style>
</body>
</html>
