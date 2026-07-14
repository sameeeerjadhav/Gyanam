<?php
/**
 * Gyanam Portal — ATC: Fees Management
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo = getDBConnection(); 
$userName = sanitize(getUserName());
$atcId = $_SESSION['atc_id'] ?? null;

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'get_student':
                $stmt = $pdo->prepare("SELECT * FROM admissions WHERE id = ? AND atc_id = ?");
                $stmt->execute([$_POST['id'], $atcId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $student]);
                exit;
                
            case 'update_fees_structure':
                $studentId = $_POST['student_id'];
                $courseFees = floatval($_POST['course_fees']);
                $discountAmount = floatval($_POST['discount_amount']);
                $discountReason = $_POST['discount_reason'] ?? null;
                $netPayable = $courseFees - $discountAmount;
                
                // Get current paid amount
                $stmt = $pdo->prepare("SELECT fees_paid FROM admissions WHERE id = ? AND atc_id = ?");
                $stmt->execute([$studentId, $atcId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                $feesPaid = $student['fees_paid'];
                
                // Calculate new pending
                $netBalance = $netPayable - $feesPaid;
                
                $stmt = $pdo->prepare("
                    UPDATE admissions 
                    SET course_fees = ?, discount_amount = ?, discount_reason = ?, 
                        net_payable = ?, fees_total = ?, fees_pending = ?
                    WHERE id = ? AND atc_id = ?
                ");
                $stmt->execute([
                    $courseFees, 
                    $discountAmount, 
                    $discountReason, 
                    $netPayable, 
                    $netPayable, 
                    $netBalance,
                    $studentId, 
                    $atcId
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Fees structure updated successfully']);
                exit;
                
            case 'record_payment':
                try {
                    $studentId = $_POST['student_id'] ?? null;
                    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
                    $paymentMode = $_POST['payment_mode'] ?? null;
                    $transactionRef = $_POST['transaction_ref'] ?? null;
                    $description = $_POST['description'] ?? null;
                    $remarks = $_POST['remarks'] ?? null;
                    
                    // Validate inputs
                    if (!$studentId) {
                        echo json_encode(['success' => false, 'message' => 'Student ID is required']);
                        exit;
                    }
                    
                    if ($amount <= 0) {
                        echo json_encode(['success' => false, 'message' => 'Invalid payment amount']);
                        exit;
                    }
                    
                    if (!$paymentMode) {
                        echo json_encode(['success' => false, 'message' => 'Payment mode is required']);
                        exit;
                    }
                    
                    // Get current fees data
                    $stmt = $pdo->prepare("SELECT fees_paid, fees_pending, net_payable, first_name, middle_name, last_name, roll_no, course FROM admissions WHERE id = ? AND atc_id = ?");
                    $stmt->execute([$studentId, $atcId]);
                    $student = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$student) {
                        echo json_encode(['success' => false, 'message' => 'Student not found']);
                        exit;
                    }
                    
                    // Check if amount exceeds pending
                    if ($amount > $student['fees_pending']) {
                        echo json_encode(['success' => false, 'message' => 'Payment amount exceeds pending fees']);
                        exit;
                    }
                    
                    // Start transaction
                    $pdo->beginTransaction();
                    
                    try {
                        // Update fees
                        $newPaid = $student['fees_paid'] + $amount;
                        $newPending = $student['fees_pending'] - $amount;
                        
                        $stmt = $pdo->prepare("
                            UPDATE admissions 
                            SET fees_paid = ?, fees_pending = ? 
                            WHERE id = ? AND atc_id = ?
                        ");
                        $stmt->execute([$newPaid, $newPending, $studentId, $atcId]);
                        
                        // Get next installment number
                        $stmt = $pdo->prepare("SELECT COALESCE(MAX(installment_no), 0) + 1 as next_installment FROM fee_payments WHERE admission_id = ?");
                        $stmt->execute([$studentId]);
                        $installmentNo = $stmt->fetchColumn();
                        
                        // Create payment record
                        $receiptNo = 'RCP-' . date('Y') . '-' . str_pad($studentId, 6, '0', STR_PAD_LEFT) . '-' . time();
                        
                        // Check if columns exist and insert accordingly
                        $checkColumns = $pdo->query("SHOW COLUMNS FROM fee_payments LIKE 'atc_id'")->rowCount();
                        
                        if ($checkColumns > 0) {
                            // New schema with all columns
                            $stmt = $pdo->prepare("
                                INSERT INTO fee_payments (
                                    admission_id, atc_id, installment_no, receipt_no, amount, payment_mode, 
                                    transaction_ref, remarks, description, payment_date
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ");
                            $stmt->execute([
                                $studentId,
                                $atcId,
                                $installmentNo,
                                $receiptNo,
                                $amount,
                                $paymentMode,
                                $transactionRef,
                                $remarks,
                                $description
                            ]);
                        } else {
                            // Old schema - basic columns only
                            $stmt = $pdo->prepare("
                                INSERT INTO fee_payments (
                                    admission_id, receipt_no, amount, payment_mode, 
                                    transaction_ref, remarks, payment_date, created_by
                                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
                            ");
                            $stmt->execute([
                                $studentId,
                                $receiptNo,
                                $amount,
                                $paymentMode,
                                $transactionRef,
                                $remarks,
                                $_SESSION['user_id']
                            ]);
                        }
                        
                        // Try to add payment remark if table exists
                        try {
                            $stmt = $pdo->prepare("
                                INSERT INTO student_remarks (admission_id, atc_id, remark_type, remark_text, created_by)
                                VALUES (?, ?, 'Payment', ?, ?)
                            ");
                            $remarkText = "Payment of ₹" . number_format($amount, 2) . " received via " . $paymentMode . " (Receipt: " . $receiptNo . ")";
                            $stmt->execute([$studentId, $atcId, $remarkText, $_SESSION['user_id']]);
                        } catch (Exception $e) {
                            // Table might not exist, continue without error
                        }
                        
                        // Commit transaction
                        $pdo->commit();
                        
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Payment recorded successfully',
                            'receipt_no' => $receiptNo,
                            'installment_no' => $installmentNo,
                            'new_paid' => $newPaid,
                            'new_pending' => $newPending,
                            'student_name' => trim($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name']),
                            'roll_no' => $student['roll_no'],
                            'course' => $student['course']
                        ]);
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                }
                exit;
                
            case 'get_payment_history':
                $stmt = $pdo->prepare("
                    SELECT * FROM fee_payments 
                    WHERE admission_id = ? AND atc_id = ? 
                    ORDER BY installment_no ASC, payment_date DESC
                ");
                $stmt->execute([$_POST['student_id'], $atcId]);
                $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $payments]);
                exit;
                
            case 'get_remarks_history':
                $stmt = $pdo->prepare("
                    SELECT r.*, u.name as created_by_name 
                    FROM student_remarks r
                    LEFT JOIN users u ON r.created_by = u.id
                    WHERE r.admission_id = ? AND r.atc_id = ? 
                    ORDER BY r.created_at DESC
                ");
                $stmt->execute([$_POST['student_id'], $atcId]);
                $remarks = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $remarks]);
                exit;
                
            case 'add_remark':
                try {
                    $studentId = $_POST['student_id'] ?? null;
                    $remarkType = $_POST['remark_type'] ?? 'General';
                    $remarkText = $_POST['remark_text'] ?? null;
                    
                    if (!$studentId || !$remarkText) {
                        echo json_encode(['success' => false, 'message' => 'Student ID and remark text are required']);
                        exit;
                    }
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO student_remarks (admission_id, atc_id, remark_type, remark_text, created_by)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $studentId,
                        $atcId,
                        $remarkType,
                        $remarkText,
                        $_SESSION['user_id']
                    ]);
                    echo json_encode(['success' => true, 'message' => 'Remark added successfully']);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => 'Error adding remark: ' . $e->getMessage()]);
                }
                exit;
                
            case 'delete_payment':
                try {
                    $paymentId = $_POST['payment_id'] ?? null;
                    
                    if (!$paymentId) {
                        echo json_encode(['success' => false, 'message' => 'Payment ID is required']);
                        exit;
                    }
                    
                    // Start transaction
                    $pdo->beginTransaction();
                    
                    // Get payment details first
                    $stmt = $pdo->prepare("SELECT * FROM fee_payments WHERE id = ? AND atc_id = ?");
                    $stmt->execute([$paymentId, $atcId]);
                    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$payment) {
                        $pdo->rollBack();
                        echo json_encode(['success' => false, 'message' => 'Payment not found']);
                        exit;
                    }
                    
                    // Update admission fees
                    $stmt = $pdo->prepare("
                        UPDATE admissions 
                        SET fees_paid = fees_paid - ?, fees_pending = fees_pending + ?
                        WHERE id = ? AND atc_id = ?
                    ");
                    $stmt->execute([$payment['amount'], $payment['amount'], $payment['admission_id'], $atcId]);
                    
                    // Delete payment
                    $stmt = $pdo->prepare("DELETE FROM fee_payments WHERE id = ?");
                    $stmt->execute([$paymentId]);
                    
                    // Try to add remark
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO student_remarks (admission_id, atc_id, remark_type, remark_text, created_by)
                            VALUES (?, ?, 'Payment', ?, ?)
                        ");
                        $remarkText = "Payment deleted: ₹" . number_format($payment['amount'], 2) . " (Receipt: " . $payment['receipt_no'] . ")";
                        $stmt->execute([$payment['admission_id'], $atcId, $remarkText, $_SESSION['user_id']]);
                    } catch (Exception $e) {
                        // Continue even if remark fails
                    }
                    
                    // Commit transaction
                    $pdo->commit();
                    
                    echo json_encode(['success' => true, 'message' => 'Payment deleted successfully']);
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    echo json_encode(['success' => false, 'message' => 'Error deleting payment: ' . $e->getMessage()]);
                }
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Fetch students with fees data
$searchTerm = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? 'all';

$sql = "SELECT 
    a.*,
    COALESCE(a.course_fees, a.fees_total) as course_fees,
    COALESCE(a.discount_amount, 0) as discount_amount,
    COALESCE(a.net_payable, a.fees_total) as net_payable
FROM admissions a 
WHERE a.atc_id = ?";
$params = [$atcId];

if ($searchTerm) {
    $sql .= " AND (first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ? OR roll_no LIKE ? OR mobile LIKE ?)";
    $searchParam = "%$searchTerm%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
}

if ($statusFilter === 'paid') {
    $sql .= " AND fees_pending = 0";
} elseif ($statusFilter === 'pending') {
    $sql .= " AND fees_pending > 0";
} elseif ($statusFilter === 'partial') {
    $sql .= " AND fees_paid > 0 AND fees_pending > 0";
}

$sql .= " ORDER BY admission_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts
$stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE atc_id = ?");
$stmt->execute([$atcId]);
$totalCount = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE atc_id = ? AND fees_pending = 0");
$stmt->execute([$atcId]);
$paidCount = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE atc_id = ? AND fees_pending > 0");
$stmt->execute([$atcId]);
$pendingCount = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE atc_id = ? AND fees_paid > 0 AND fees_pending > 0");
$stmt->execute([$atcId]);
$partialCount = $stmt->fetchColumn();

// KPI aggregates
$totalCollected = 0;
$totalPending   = 0;
foreach ($students as $s) {
    $totalCollected += floatval($s['fees_paid']);
    $totalPending   += max(0, floatval($s['net_payable'] ?? $s['fees_total']) - floatval($s['fees_paid']));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fees Management — ATC Center | Gyanam India</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📚</text></svg>">
    <style>
    /* ─── KPI Cards ─── */
    .fees-kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem;}
    @media(max-width:768px){.fees-kpi-grid{grid-template-columns:repeat(2,1fr);}}
    .fees-kpi{background:var(--bg-surface);border:1.5px solid var(--border-color);border-radius:var(--radius-xl);padding:1.1rem 1.25rem;display:flex;align-items:center;gap:.875rem;box-shadow:var(--shadow-sm);transition:transform .2s;}
    .fees-kpi:hover{transform:translateY(-2px);}
    .fees-kpi-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .fees-kpi-icon svg{width:22px;height:22px;}
    .fees-kpi-val{font-size:1.3rem;font-weight:800;color:var(--text-primary);line-height:1.1;}
    .fees-kpi-lbl{font-size:.73rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-top:.15rem;}
    /* ─── Table avatar + progress ─── */
    .std-avatar{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:800;color:#fff;flex-shrink:0;background:linear-gradient(135deg,#4361ee,#7c3aed);}
    .mini-bar-wrap{height:5px;background:var(--gray-100);border-radius:999px;margin-top:.35rem;overflow:hidden;width:80px;}
    .mini-bar-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,#10b981,#059669);transition:width .4s;}
    .mini-bar-fill.partial{background:linear-gradient(90deg,#f59e0b,#d97706);}
    .mini-bar-fill.pending{background:linear-gradient(90deg,#ef4444,#dc2626);}
    /* ─── Payment Modal ─── */
    .pm-header{display:flex;align-items:center;gap:1rem;padding:1.25rem 1.5rem;background:linear-gradient(135deg,#ec4899,#be185d);color:#fff;}
    .pm-icon{width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .pm-icon svg{width:22px;height:22px;stroke:#fff;}
    .pm-title{font-size:1rem;font-weight:800;}
    .pm-sub{font-size:.78rem;opacity:.85;}
    .pm-close{margin-left:auto;background:rgba(255,255,255,.2);border:none;border-radius:8px;width:34px;height:34px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#fff;transition:background .2s;}
    .pm-close:hover{background:rgba(255,255,255,.35);}
    .pm-close svg{width:16px;height:16px;}
    .pm-student-bar{display:flex;align-items:center;gap:.875rem;padding:.875rem 1.5rem;background:linear-gradient(135deg,#fdf2f8,#fce7f3);border-bottom:1.5px solid #fbcfe8;}
    .pm-student-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#ec4899,#be185d);display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:800;color:#fff;flex-shrink:0;}
    .pm-student-name{font-size:.9rem;font-weight:800;color:#831843;}
    .pm-student-meta{font-size:.75rem;color:#9d174d;margin-top:.1rem;}
    .pm-amount-hero{margin:1.25rem 1.5rem 0;background:linear-gradient(135deg,#ec4899,#be185d);border-radius:var(--radius-xl);padding:1rem 1.25rem;color:#fff;display:flex;justify-content:space-between;align-items:center;gap:1rem;}
    .pm-amount-block{text-align:center;}
    .pm-amount-lbl{font-size:.7rem;font-weight:700;opacity:.8;text-transform:uppercase;letter-spacing:.06em;}
    .pm-amount-num{font-size:1.35rem;font-weight:900;margin-top:.1rem;}
    .pm-body{padding:1.25rem 1.5rem;display:flex;flex-direction:column;gap:.875rem;}
    .pm-grid{display:grid;grid-template-columns:1fr 1fr;gap:.875rem;}
    .pm-field{display:flex;flex-direction:column;gap:.35rem;}
    .pm-field.full{grid-column:1/-1;}
    .pm-label{font-size:.78rem;font-weight:700;color:var(--text-secondary);}
    .pm-req{color:#ec4899;}
    .pm-input,.pm-select{height:42px;padding:0 .875rem;border-radius:var(--radius-md);border:1.5px solid var(--border-color);background:var(--bg-surface);font-size:.875rem;color:var(--text-primary);font-family:inherit;width:100%;transition:border-color .2s,box-shadow .2s;}
    .pm-input:focus,.pm-select:focus{outline:none;border-color:#ec4899;box-shadow:0 0 0 3px rgba(236,72,153,.15);}
    textarea.pm-input{height:auto;padding:.75rem .875rem;}
    .pm-footer{display:flex;justify-content:flex-end;gap:.75rem;padding:1rem 1.5rem;border-top:1.5px solid var(--border-color);background:var(--gray-50);}
    .pm-btn-cancel{padding:.6rem 1.5rem;border-radius:var(--radius-full);border:1.5px solid var(--border-color);background:var(--bg-surface);color:var(--text-secondary);font-size:.875rem;font-weight:700;cursor:pointer;transition:all .2s;}
    .pm-btn-cancel:hover{background:var(--gray-100);color:var(--text-primary);}
    .pm-btn-submit{display:inline-flex;align-items:center;gap:.5rem;padding:.65rem 1.75rem;border-radius:var(--radius-full);border:none;background:linear-gradient(135deg,#ec4899,#be185d);color:#fff;font-size:.875rem;font-weight:800;cursor:pointer;transition:all .2s;box-shadow:0 4px 14px rgba(236,72,153,.3);}
    .pm-btn-submit:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(236,72,153,.4);}
    .pm-btn-submit:disabled{opacity:.65;transform:none;cursor:not-allowed;}
    .pm-btn-submit svg{width:16px;height:16px;}
    
    /* ─── Action Buttons ─── */
    .btn-icon {
        width: 32px;
        height: 32px;
        border-radius: var(--radius-md);
        border: 1.5px solid var(--border-color);
        background: var(--bg-surface);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all .2s;
        color: var(--text-secondary);
    }
    .btn-icon:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,.1);
    }
    .btn-icon svg {
        width: 16px;
        height: 16px;
        stroke: currentColor;
    }
    .btn-icon.success {
        color: #10b981;
        border-color: #a7f3d0;
        background: #d1fae5;
    }
    .btn-icon.success:hover {
        background: #a7f3d0;
        border-color: #6ee7b7;
        box-shadow: 0 4px 12px rgba(16,185,129,.3);
    }
    .btn-icon.danger {
        color: #ef4444;
        border-color: #fecaca;
        background: #fee2e2;
    }
    .btn-icon.danger:hover {
        background: #fecaca;
        border-color: #fca5a5;
        box-shadow: 0 4px 12px rgba(239,68,68,.3);
    }
    .btn-icon.primary {
        color: #4361ee;
        border-color: #c7d2fe;
        background: #e0e7ff;
    }
    .btn-icon.primary:hover {
        background: #c7d2fe;
        border-color: #a5b4fc;
        box-shadow: 0 4px 12px rgba(67,97,238,.3);
    }
    
    /* ─── Fees Badge ─── */
    .fees-badge {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        padding: .35rem .75rem;
        border-radius: var(--radius-full);
        font-size: .72rem;
        font-weight: 700;
        white-space: nowrap;
    }
    .fees-badge.paid {
        background: #d1fae5;
        color: #059669;
        border: 1px solid #a7f3d0;
    }
    .fees-badge.partial {
        background: #fef3c7;
        color: #d97706;
        border: 1px solid #fde68a;
    }
    .fees-badge.pending {
        background: #fee2e2;
        color: #dc2626;
        border: 1px solid #fecaca;
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
                    <h2>Fees Management</h2>
                    <p>Track and manage student fees</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">

            <!-- KPI Summary Cards -->
            <div class="fees-kpi-grid">
                <div class="fees-kpi">
                    <div class="fees-kpi-icon" style="background:#eff6ff">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div>
                        <div class="fees-kpi-val"><?= $totalCount ?></div>
                        <div class="fees-kpi-lbl">Total Students</div>
                    </div>
                </div>
                <div class="fees-kpi">
                    <div class="fees-kpi-icon" style="background:#f0fdf4">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <div>
                        <div class="fees-kpi-val">₹<?= number_format($totalCollected, 0) ?></div>
                        <div class="fees-kpi-lbl">Total Collected</div>
                    </div>
                </div>
                <div class="fees-kpi">
                    <div class="fees-kpi-icon" style="background:#fef2f2">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </div>
                    <div>
                        <div class="fees-kpi-val">₹<?= number_format($totalPending, 0) ?></div>
                        <div class="fees-kpi-lbl">Total Pending</div>
                    </div>
                </div>
                <div class="fees-kpi">
                    <div class="fees-kpi-icon" style="background:#f0fdf4">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    </div>
                    <div>
                        <div class="fees-kpi-val"><?= $paidCount ?> / <?= $totalCount ?></div>
                        <div class="fees-kpi-lbl">Fully Paid</div>
                    </div>
                </div>
            </div>

            <!-- Status Filter Tabs -->
            <div class="status-tabs">
                <a href="?status=all" class="status-tab <?= $statusFilter === 'all' ? 'active' : '' ?>">
                    <span class="tab-label">All Students</span>
                    <span class="tab-count"><?= $totalCount ?></span>
                </a>
                <a href="?status=paid" class="status-tab <?= $statusFilter === 'paid' ? 'active' : '' ?>">
                    <span class="tab-label">Fully Paid</span>
                    <span class="tab-count badge-paid"><?= $paidCount ?></span>
                </a>
                <a href="?status=partial" class="status-tab <?= $statusFilter === 'partial' ? 'active' : '' ?>">
                    <span class="tab-label">Partial Payment</span>
                    <span class="tab-count badge-partial"><?= $partialCount ?></span>
                </a>
                <a href="?status=pending" class="status-tab <?= $statusFilter === 'pending' ? 'active' : '' ?>">
                    <span class="tab-label">Pending</span>
                    <span class="tab-count badge-pending"><?= $pendingCount ?></span>
                </a>
            </div>

            <div class="page-toolbar">
                <h3>
                    Student Fees
                    <span class="badge-count"><?= count($students) ?></span>
                </h3>
                <div style="display:flex;gap:.75rem;align-items:center;">
                    <form method="GET" style="display:flex;gap:.75rem;">
                        <input type="hidden" name="status" value="<?= $statusFilter ?>">
                        <div class="search-bar">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                            <input type="text" name="search" placeholder="Search by name, roll no, mobile..." value="<?= htmlspecialchars($searchTerm) ?>">
                        </div>
                        <button type="submit" class="btn-primary" style="padding:0 1.5rem;">Search</button>
                    </form>
                </div>
            </div>

            <div class="table-card">
                <table class="data-table" id="feesTable">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Roll No</th>
                            <th>Course</th>
                            <th>Net Payable</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Status / Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr>
                                <td colspan="7" class="table-empty">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                    <p>No students found.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($students as $student):
                                $fullName = $student['first_name'].' '.($student['middle_name'] ? $student['middle_name'].' ' : '').$student['last_name'];
                                $initial  = strtoupper(mb_substr($student['first_name'], 0, 1));
                                $netPayable = floatval($student['net_payable'] ?? $student['fees_total']);
                                $feesPaid   = floatval($student['fees_paid']);
                                $netBalance = max(0, $netPayable - $feesPaid);
                                $paidPct    = $netPayable > 0 ? min(100, round(($feesPaid / $netPayable) * 100)) : 0;
                                if ($netBalance <= 0)       { $sc = 'paid';    $st = 'Fully Paid'; }
                                elseif ($feesPaid > 0)      { $sc = 'partial';  $st = 'Partial'; }
                                else                        { $sc = 'pending';  $st = 'Pending'; }
                            ?>
                                <tr onclick="viewStudentDetails(<?= $student['id'] ?>)" style="cursor:pointer;">
                                    <td>
                                        <div style="display:flex;align-items:center;gap:.75rem;">
                                            <div class="std-avatar"><?= $initial ?></div>
                                            <div>
                                                <div class="cell-name"><?= htmlspecialchars($fullName) ?></div>
                                                <div class="cell-sub"><?= htmlspecialchars($student['mobile']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span style="font-size:.8rem;color:var(--text-muted);"><?= htmlspecialchars($student['roll_no']) ?></span></td>
                                    <td><?= htmlspecialchars($student['course']) ?></td>
                                    <td style="font-weight:700;">₹<?= number_format($netPayable, 0) ?></td>
                                    <td style="color:#10b981;font-weight:700;">₹<?= number_format($feesPaid, 0) ?></td>
                                    <td>
                                        <span style="color:<?= $netBalance > 0 ? '#f59e0b' : '#10b981' ?>;font-weight:700;">₹<?= number_format($netBalance, 0) ?></span>
                                        <div class="mini-bar-wrap"><div class="mini-bar-fill <?= $sc ?>" style="width:<?= $paidPct ?>%"></div></div>
                                    </td>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:.5rem;">
                                            <span class="fees-badge <?= $sc ?>"><?= $st ?> · <?= $paidPct ?>%</span>
                                            <?php if ($netBalance > 0): ?>
                                                <button class="btn-icon success" onclick="event.stopPropagation();recordPayment(<?= $student['id'] ?>)" title="Record Payment">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn-icon" onclick="event.stopPropagation();viewStudentDetails(<?= $student['id'] ?>)" title="View Details">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
<!-- Student Details Modal -->
<div class="modal-overlay" id="detailsModal">
    <div class="modal-card" style="max-width: 900px;">
        <div class="modal-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Student Fees Details
            </h3>
            <button type="button" class="modal-close" onclick="closeDetailsModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        
        <div class="modal-body" id="detailsContent">
            <!-- Content will be populated by JavaScript -->
        </div>
        
        <div class="modal-footer">
            <button type="button" class="btn-secondary" onclick="closeDetailsModal()">
                Close
            </button>
            <button type="button" class="btn-primary" id="recordPaymentBtn" onclick="recordPaymentFromDetails()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px;"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                Record Payment
            </button>
        </div>
    </div>
</div>

<!-- Record Payment Modal (Premium) -->
<div class="modal-overlay" id="paymentModal">
    <div class="modal-card" style="max-width:580px;padding:0;border-radius:var(--radius-xl);overflow:hidden;">

        <!-- Header -->
        <div class="pm-header">
            <div class="pm-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <div>
                <div class="pm-title">Record Fee Payment</div>
                <div class="pm-sub">Enter payment details to update student ledger</div>
            </div>
            <button type="button" class="pm-close" onclick="closePaymentModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <!-- Student strip -->
        <div class="pm-student-bar">
            <div class="pm-student-avatar" id="pmAvatar">?</div>
            <div>
                <div class="pm-student-name" id="paymentStudentName"></div>
                <div class="pm-student-meta" id="paymentStudentRoll"></div>
            </div>
        </div>

        <form id="paymentForm" novalidate>
            <input type="hidden" id="payment_student_id" name="student_id">
            <input type="hidden" name="action" value="record_payment">

            <!-- Amount hero row -->
            <div class="pm-amount-hero">
                <div class="pm-amount-block">
                    <div class="pm-amount-lbl">Balance Pending</div>
                    <div class="pm-amount-num" id="paymentPendingAmount">₹0</div>
                </div>
                <div style="width:1px;background:rgba(255,255,255,.3);height:40px;"></div>
                <div class="pm-amount-block">
                    <div class="pm-amount-lbl">Net Payable</div>
                    <div class="pm-amount-num" id="paymentTotalFees">₹0</div>
                </div>
                <div style="width:1px;background:rgba(255,255,255,.3);height:40px;"></div>
                <div class="pm-amount-block">
                    <div class="pm-amount-lbl">Already Paid</div>
                    <div class="pm-amount-num" id="paymentPaidSoFar">₹0</div>
                </div>
            </div>

            <div class="pm-body">
                <div class="pm-grid">
                    <div class="pm-field full">
                        <label class="pm-label">Payment Amount (₹) <span class="pm-req">*</span></label>
                        <input type="number" class="pm-input" id="amount" name="amount" required min="1" step="1" placeholder="Enter amount received">
                    </div>
                    <div class="pm-field">
                        <label class="pm-label">Payment Mode <span class="pm-req">*</span></label>
                        <select class="pm-select" id="payment_mode" name="payment_mode" required>
                            <option value="">— Select —</option>
                            <option value="Cash">💵 Cash</option>
                            <option value="UPI">📱 UPI</option>
                            <option value="Card">💳 Debit / Credit Card</option>
                            <option value="Net Banking">🏦 Net Banking</option>
                            <option value="Cheque">📄 Cheque</option>
                        </select>
                    </div>
                    <div class="pm-field">
                        <label class="pm-label">Transaction Ref / Cheque No</label>
                        <input type="text" class="pm-input" id="transaction_ref" name="transaction_ref" maxlength="100" placeholder="Optional">
                    </div>
                    <div class="pm-field full">
                        <label class="pm-label">Description</label>
                        <input type="text" class="pm-input" id="description" name="description" maxlength="255" placeholder="e.g., 1st installment, Final payment">
                    </div>
                    <div class="pm-field full">
                        <label class="pm-label">Remarks</label>
                        <textarea class="pm-input" id="remarks" name="remarks" rows="2" placeholder="Any additional notes"></textarea>
                    </div>
                </div>
            </div>

            <div class="pm-footer">
                <button type="button" class="pm-btn-cancel" onclick="closePaymentModal()">Cancel</button>
                <button type="submit" class="pm-btn-submit" id="paymentSubmitBtn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    <span id="paymentSubmitBtnText">Record Payment</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Receipt Modal -->
<div class="modal-overlay" id="receiptModal">
    <div class="modal-card" style="max-width: 800px;">
        <div class="modal-header" style="background: linear-gradient(135deg, #ec4899, #db2777); color: white;">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                Fee Receipt
            </h3>
            <button type="button" class="modal-close" onclick="closeReceiptModal()" aria-label="Close" style="color: white;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        
        <div class="modal-body" id="receiptContent" style="padding: 0;">
            <!-- Receipt will be generated here -->
        </div>
        
        <div class="modal-footer">
            <button type="button" class="btn-secondary" onclick="closeReceiptModal()">
                Close
            </button>
            <button type="button" class="btn-primary" onclick="printReceipt()" style="background: linear-gradient(135deg, #ec4899, #db2777);">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px;"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Print Receipt
            </button>
        </div>
    </div>
</div>

<!-- Update Fees Structure Modal -->
<div class="modal-overlay" id="updateFeesModal">
    <div class="modal-card" style="max-width: 600px;">
        <div class="modal-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                Update Fees Structure
            </h3>
            <button type="button" class="modal-close" onclick="closeUpdateFeesModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        
        <form id="updateFeesForm" novalidate>
            <input type="hidden" id="update_student_id" name="student_id">
            <input type="hidden" name="action" value="update_fees_structure">
            
            <div class="modal-body">
                <div class="form-section">
                    <div class="profile-form-grid">
                        <div class="form-field full-width">
                            <label for="update_course_fees">Course Fees <span class="required">*</span></label>
                            <input type="number" id="update_course_fees" name="course_fees" required min="0" step="0.01" placeholder="0.00" oninput="updateNetPayable()">
                            <small class="field-hint">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                                Total course fees before discount
                            </small>
                        </div>
                        
                        <div class="form-field">
                            <label for="update_discount_amount">Discount Amount</label>
                            <input type="number" id="update_discount_amount" name="discount_amount" min="0" step="0.01" placeholder="0.00" oninput="updateNetPayable()">
                        </div>
                        
                        <div class="form-field">
                            <label for="update_discount_reason">Discount Reason</label>
                            <input type="text" id="update_discount_reason" name="discount_reason" maxlength="255" placeholder="e.g., Early bird, Scholarship">
                        </div>
                        
                        <div class="form-field full-width">
                            <div style="background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: white; padding: 1rem; border-radius: 8px; text-align: center;">
                                <div style="font-size: 0.85rem; opacity: 0.9; margin-bottom: 0.25rem;">Net Payable Fees</div>
                                <div style="font-size: 1.75rem; font-weight: 700;" id="calculated_net_payable">₹ 0.00</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeUpdateFeesModal()">
                    Cancel
                </button>
                <button type="submit" class="btn-primary" id="updateFeesSubmitBtn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px;">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    <span id="updateFeesSubmitBtnText">Update Fees</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Remark Modal -->
<div class="modal-overlay" id="addRemarkModal">
    <div class="modal-card" style="max-width: 600px;">
        <div class="modal-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                Add Student Remark
            </h3>
            <button type="button" class="modal-close" onclick="closeAddRemarkModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        
        <form id="addRemarkForm" novalidate>
            <input type="hidden" id="remark_student_id" name="student_id">
            <input type="hidden" name="action" value="add_remark">
            
            <div class="modal-body">
                <div class="form-section">
                    <div class="profile-form-grid">
                        <div class="form-field full-width">
                            <label for="remark_type">Remark Type <span class="required">*</span></label>
                            <select id="remark_type" name="remark_type" required>
                                <option value="General">General</option>
                                <option value="Registration">Registration</option>
                                <option value="Reporting">Reporting</option>
                                <option value="Exam Scheduling">Exam Scheduling</option>
                                <option value="Results">Results</option>
                                <option value="Dispatch">Dispatch</option>
                                <option value="Payment">Payment</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="form-field full-width">
                            <label for="remark_text">Remark <span class="required">*</span></label>
                            <textarea id="remark_text" name="remark_text" required rows="4" placeholder="Enter your remark here..."></textarea>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeAddRemarkModal()">
                    Cancel
                </button>
                <button type="submit" class="btn-primary" id="addRemarkSubmitBtn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px;">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    <span id="addRemarkSubmitBtnText">Add Remark</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
let currentStudentId = null;
let currentStudentData = null;

// View student details
async function viewStudentDetails(id) {
    console.log('viewStudentDetails called with id:', id);
    currentStudentId = id;
    
    try {
        const formData = new FormData();
        formData.append('action', 'get_student');
        formData.append('id', id);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const result = await response.json();
        
        console.log('Student data received:', result);
        
        if (result.success && result.data) {
            currentStudentData = result.data;
            const student = result.data;
            const fullName = `${student.first_name} ${student.middle_name ? student.middle_name + ' ' : ''}${student.last_name}`;
            
            const courseFees = parseFloat(student.course_fees || student.fees_total || 0);
            const discountAmount = parseFloat(student.discount_amount || 0);
            const netPayable = parseFloat(student.net_payable || student.fees_total || 0);
            const feesPaid = parseFloat(student.fees_paid || 0);
            const netBalance = netPayable - feesPaid;
            const paidPercentage = netPayable > 0 ? Math.round((feesPaid / netPayable) * 100) : 0;
            
            // Get payment history
            const historyFormData = new FormData();
            historyFormData.append('action', 'get_payment_history');
            historyFormData.append('student_id', id);
            
            const historyResponse = await fetch('', { method: 'POST', body: historyFormData });
            const historyResult = await historyResponse.json();
            const payments = historyResult.success ? historyResult.data : [];
            
            // Get remarks history
            const remarksFormData = new FormData();
            remarksFormData.append('action', 'get_remarks_history');
            remarksFormData.append('student_id', id);
            
            const remarksResponse = await fetch('', { method: 'POST', body: remarksFormData });
            const remarksResult = await remarksResponse.json();
            const remarks = remarksResult.success ? remarksResult.data : [];
            
            // Build payment receipts table
            let paymentTableHTML = '';
            if (payments.length > 0) {
                paymentTableHTML = `
                    <div class="detail-section">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <h4 style="margin: 0;">Paid Fees Receipts</h4>
                        </div>
                        <div style="overflow-x: auto;">
                            <table class="data-table" style="font-size: 0.85rem;">
                                <thead>
                                    <tr>
                                        <th>Installment</th>
                                        <th>Receipt No</th>
                                        <th>Amount</th>
                                        <th>Paid Date</th>
                                        <th>Payment Mode</th>
                                        <th>Description</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${payments.map(payment => `
                                        <tr>
                                            <td><strong>#${payment.installment_no || 1}</strong></td>
                                            <td><span style="color: var(--primary-600); font-weight: 600;">${payment.receipt_no}</span></td>
                                            <td><strong style="color: var(--success-600);">₹ ${parseFloat(payment.amount).toFixed(2)}</strong></td>
                                            <td>${new Date(payment.payment_date).toLocaleDateString('en-IN', {day: '2-digit', month: 'short', year: 'numeric'})}</td>
                                            <td>${payment.payment_mode}</td>
                                            <td>${payment.description || payment.remarks || '-'}</td>
                                            <td>
                                                <button class="btn-icon danger" onclick="deletePayment(${payment.id})" title="Delete Payment">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                                </button>
                                            </td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            } else {
                paymentTableHTML = `
                    <div class="detail-section">
                        <h4>Paid Fees Receipts</h4>
                        <p style="text-align: center; color: var(--text-secondary); padding: 2rem;">No payments recorded yet.</p>
                    </div>
                `;
            }
            
            // Build remarks history
            let remarksHTML = '';
            if (remarks.length > 0) {
                remarksHTML = `
                    <div class="detail-section">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <h4 style="margin: 0;">Remarks History</h4>
                            <button class="btn-primary" onclick="openAddRemarkModal()" style="padding: 0.5rem 1rem; font-size: 0.85rem;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                Add Remark
                            </button>
                        </div>
                        <div class="remarks-timeline">
                            ${remarks.map(remark => `
                                <div class="remark-item">
                                    <div class="remark-header">
                                        <span class="remark-type remark-type-${remark.remark_type.toLowerCase().replace(' ', '-')}">${remark.remark_type}</span>
                                        <span class="remark-time">${new Date(remark.created_at).toLocaleString('en-IN', {day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'})}</span>
                                    </div>
                                    <div class="remark-text">${remark.remark_text}</div>
                                    ${remark.created_by_name ? `<div class="remark-author">By: ${remark.created_by_name}</div>` : ''}
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            } else {
                remarksHTML = `
                    <div class="detail-section">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <h4 style="margin: 0;">Remarks History</h4>
                            <button class="btn-primary" onclick="openAddRemarkModal()" style="padding: 0.5rem 1rem; font-size: 0.85rem;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                Add Remark
                            </button>
                        </div>
                        <p style="text-align: center; color: var(--text-secondary); padding: 2rem;">No remarks added yet.</p>
                    </div>
                `;
            }
            
            document.getElementById('detailsContent').innerHTML = `
                <div class="student-details-grid">
                    <div class="detail-section">
                        <h4>Student Information</h4>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Roll Number</span>
                                <span class="detail-value">${student.roll_no}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Full Name</span>
                                <span class="detail-value">${fullName}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Course</span>
                                <span class="detail-value">${student.course}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Mobile</span>
                                <span class="detail-value">${student.mobile}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-section">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <h4 style="margin: 0;">Fees Details</h4>
                            <button class="btn-primary" onclick="openUpdateFeesModal()" style="padding: 0.5rem 1rem; font-size: 0.85rem;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                Update Fees
                            </button>
                        </div>
                        <div class="fees-details-grid">
                            <div class="fees-detail-item">
                                <span class="fees-detail-label">Course Fees</span>
                                <span class="fees-detail-value">₹ ${courseFees.toFixed(2)}</span>
                            </div>
                            <div class="fees-detail-item">
                                <span class="fees-detail-label">Discount</span>
                                <span class="fees-detail-value" style="color: var(--success-600);">- ₹ ${discountAmount.toFixed(2)}</span>
                            </div>
                            ${student.discount_reason ? `
                            <div class="fees-detail-item full-width">
                                <span class="fees-detail-label">Discount Reason</span>
                                <span class="fees-detail-value">${student.discount_reason}</span>
                            </div>
                            ` : ''}
                            <div class="fees-detail-item highlight">
                                <span class="fees-detail-label">Net Payable Fees</span>
                                <span class="fees-detail-value">₹ ${netPayable.toFixed(2)}</span>
                            </div>
                            <div class="fees-detail-item">
                                <span class="fees-detail-label">Paid Fees</span>
                                <span class="fees-detail-value" style="color: var(--success-600);">₹ ${feesPaid.toFixed(2)}</span>
                            </div>
                            <div class="fees-detail-item highlight">
                                <span class="fees-detail-label">Net Balance Fees</span>
                                <span class="fees-detail-value" style="color: ${netBalance > 0 ? 'var(--amber-600)' : 'var(--success-600)'};">₹ ${netBalance.toFixed(2)}</span>
                            </div>
                        </div>
                        <div class="fees-progress-bar" style="margin-top: 1rem;">
                            <div class="fees-progress-fill" style="width: ${paidPercentage}%">
                                <span>${paidPercentage}%</span>
                            </div>
                        </div>
                    </div>
                    
                    ${paymentTableHTML}
                    
                    ${remarksHTML}
                </div>
            `;
            
            // Show/hide record payment button
            const recordPaymentBtn = document.getElementById('recordPaymentBtn');
            if (netBalance > 0) {
                recordPaymentBtn.style.display = 'flex';
            } else {
                recordPaymentBtn.style.display = 'none';
            }
            
            document.getElementById('detailsModal').classList.add('active');
            console.log('Details modal opened successfully');
        } else {
            console.error('Failed to load student details:', result);
            alert('Error loading student details');
        }
    } catch (error) {
        console.error('Error in viewStudentDetails:', error);
        alert('Error loading student details: ' + error.message);
    }
}

// Close details modal
function closeDetailsModal() {
    document.getElementById('detailsModal').classList.remove('active');
}

// Record payment from details modal
function recordPaymentFromDetails() {
    closeDetailsModal();
    recordPayment(currentStudentId);
}

// Record payment
async function recordPayment(id) {
    console.log('recordPayment called with id:', id);
    currentStudentId = id;
    
    try {
        const formData = new FormData();
        formData.append('action', 'get_student');
        formData.append('id', id);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success && result.data) {
            const student = result.data;
            const fullName = `${student.first_name} ${student.middle_name ? student.middle_name + ' ' : ''}${student.last_name}`;
            
            document.getElementById('payment_student_id').value = student.id;
            document.getElementById('pmAvatar').textContent = fullName.charAt(0).toUpperCase();
            document.getElementById('paymentStudentName').textContent = fullName;
            document.getElementById('paymentStudentRoll').textContent = `Roll No: ${student.roll_no}  ·  ${student.course}`;
            const pending  = parseFloat(student.fees_pending  || 0);
            const netPay   = parseFloat(student.net_payable   || student.fees_total || 0);
            const paidSoFar = parseFloat(student.fees_paid    || 0);
            document.getElementById('paymentPendingAmount').textContent = `₹${pending.toLocaleString('en-IN')}`;
            document.getElementById('paymentTotalFees').textContent     = `₹${netPay.toLocaleString('en-IN')}`;
            document.getElementById('paymentPaidSoFar').textContent     = `₹${paidSoFar.toLocaleString('en-IN')}`;
            
            // Set max amount to pending amount
            document.getElementById('amount').max = student.fees_pending;
            document.getElementById('amount').value = '';
            document.getElementById('payment_mode').value = '';
            document.getElementById('transaction_ref').value = '';
            document.getElementById('remarks').value = '';
            document.getElementById('description').value = '';
            
            document.getElementById('paymentModal').classList.add('active');
            console.log('Payment modal opened successfully');
        } else {
            console.error('Failed to load student data:', result);
            alert('Error loading student data');
        }
    } catch (error) {
        console.error('Error in recordPayment:', error);
        alert('Error loading student data: ' + error.message);
    }
}

// Close payment modal
function closePaymentModal() {
    document.getElementById('paymentModal').classList.remove('active');
}

// Payment form submission
document.addEventListener('DOMContentLoaded', function() {
    const paymentForm = document.getElementById('paymentForm');
    if (paymentForm) {
        paymentForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const amount = parseFloat(document.getElementById('amount').value);
            const pendingAmountText = document.getElementById('paymentPendingAmount').textContent.replace('₹', '').replace(/,/g, '').trim();
            const pendingAmount = parseFloat(pendingAmountText);
            
            if (isNaN(amount) || amount <= 0) {
                alert('Please enter a valid amount');
                return;
            }
            
            if (isNaN(pendingAmount)) {
                alert('Unable to determine pending amount. Please try again.');
                return;
            }
            
            if (amount > pendingAmount) {
                alert(`Payment amount cannot exceed pending amount of ₹${pendingAmount.toLocaleString('en-IN')}`);
                return;
            }
            
            const paymentMode = document.getElementById('payment_mode').value;
            if (!paymentMode) {
                alert('Please select a payment mode');
                return;
            }
            
            const submitBtn = document.getElementById('paymentSubmitBtn');
            const originalText = document.getElementById('paymentSubmitBtnText').textContent;
            submitBtn.disabled = true;
            document.getElementById('paymentSubmitBtnText').textContent = 'Processing...';
            
            try {
                const formData = new FormData(this);
                const response = await fetch('', { method: 'POST', body: formData });
                const result = await response.json();
                
                if (result.success) {
                    closePaymentModal();
                    showReceipt(result);
                    // Reload the page to show updated data
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    alert(result.message || 'Error recording payment');
                    submitBtn.disabled = false;
                    document.getElementById('paymentSubmitBtnText').textContent = originalText;
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error recording payment. Please check your connection and try again.');
                submitBtn.disabled = false;
                document.getElementById('paymentSubmitBtnText').textContent = originalText;
            }
        });
    }
});

// Show receipt
function showReceipt(paymentData) {
    // Use data from server response if available, otherwise fall back to current data
    const studentName = paymentData.student_name || (currentStudentData ? 
        `${currentStudentData.first_name} ${currentStudentData.middle_name ? currentStudentData.middle_name + ' ' : ''}${currentStudentData.last_name}` : 
        'N/A');
    const rollNo = paymentData.roll_no || (currentStudentData ? currentStudentData.roll_no : 'N/A');
    const course = paymentData.course || (currentStudentData ? currentStudentData.course : 'N/A');
    
    const paymentDate = new Date().toLocaleDateString('en-IN', {
        day: '2-digit', 
        month: 'short', 
        year: 'numeric', 
        hour: '2-digit', 
        minute: '2-digit'
    });
    
    const amount = parseFloat(document.getElementById('amount').value);
    const paymentMode = document.getElementById('payment_mode').value;
    const transactionRef = document.getElementById('transaction_ref').value;
    const totalFees = parseFloat(document.getElementById('paymentTotalFees').textContent.replace('₹', '').replace(/,/g, '').trim());
    const paidSoFar = paymentData.new_paid || 0;
    const pending = paymentData.new_pending || 0;
    
    document.getElementById('receiptContent').innerHTML = `
        <div class="receipt-container">
            <div class="receipt-header">
                <h2>Gyanam India Educational Services</h2>
                <p>Fee Payment Receipt</p>
            </div>
            
            <div class="receipt-body">
                <div class="receipt-row">
                    <span class="receipt-label">Receipt No:</span>
                    <span class="receipt-value"><strong>${paymentData.receipt_no}</strong></span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Date:</span>
                    <span class="receipt-value">${paymentDate}</span>
                </div>
                <div class="receipt-divider"></div>
                <div class="receipt-row">
                    <span class="receipt-label">Student Name:</span>
                    <span class="receipt-value">${studentName}</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Roll Number:</span>
                    <span class="receipt-value">${rollNo}</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Course:</span>
                    <span class="receipt-value">${course}</span>
                </div>
                <div class="receipt-divider"></div>
                <div class="receipt-row highlight">
                    <span class="receipt-label">Amount Paid:</span>
                    <span class="receipt-value"><strong>₹${amount.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Payment Mode:</span>
                    <span class="receipt-value">${paymentMode}</span>
                </div>
                ${transactionRef ? `
                <div class="receipt-row">
                    <span class="receipt-label">Transaction Ref:</span>
                    <span class="receipt-value">${transactionRef}</span>
                </div>` : ''}
                <div class="receipt-divider"></div>
                <div class="receipt-row">
                    <span class="receipt-label">Total Fees:</span>
                    <span class="receipt-value">₹${totalFees.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Total Paid:</span>
                    <span class="receipt-value">₹${paidSoFar.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Balance Pending:</span>
                    <span class="receipt-value">₹${pending.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                </div>
            </div>
            
            <div class="receipt-footer">
                <p>Thank you for your payment!</p>
                <p style="font-size: 0.85rem; color: #666; margin-top: 0.5rem;">This is a computer-generated receipt.</p>
            </div>
        </div>
    `;
    
    document.getElementById('receiptModal').classList.add('active');
}
                    <span class="receipt-value">₹ ${parseFloat(student.fees_total).toFixed(2)}</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Total Paid:</span>
                    <span class="receipt-value">₹ ${parseFloat(paymentData.new_paid).toFixed(2)}</span>
                </div>
                <div class="receipt-row highlight">
                    <span class="receipt-label">Balance Pending:</span>
                    <span class="receipt-value"><strong>₹ ${parseFloat(paymentData.new_pending).toFixed(2)}</strong></span>
                </div>
            </div>
            
            <div class="receipt-footer">
                <p>This is a computer-generated receipt.</p>
                <p>Thank you for your payment!</p>
            </div>
        </div>
    `;
    
    document.getElementById('receiptModal').classList.add('active');
}

// Close receipt modal
function closeReceiptModal() {
    document.getElementById('receiptModal').classList.remove('active');
    location.reload();
}

// Print receipt
function printReceipt() {
    const receiptContent = document.getElementById('receiptContent').innerHTML;
    const printWindow = window.open('', '', 'height=600,width=800');
    printWindow.document.write('<html><head><title>Fee Receipt</title>');
    printWindow.document.write('<style>');
    printWindow.document.write(`
        body { font-family: Arial, sans-serif; padding: 20px; }
        .receipt-container { max-width: 600px; margin: 0 auto; border: 2px solid #000; padding: 20px; }
        .receipt-header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 20px; }
        .receipt-header h2 { margin: 0; font-size: 24px; }
        .receipt-header p { margin: 5px 0 0 0; font-size: 14px; }
        .receipt-row { display: flex; justify-content: space-between; padding: 8px 0; }
        .receipt-label { font-weight: 600; }
        .receipt-divider { border-top: 1px dashed #ccc; margin: 10px 0; }
        .receipt-row.highlight { background: #f3f4f6; padding: 10px; margin: 5px -10px; }
        .receipt-footer { text-align: center; margin-top: 30px; padding-top: 15px; border-top: 2px solid #000; font-size: 12px; }
        @media print { body { padding: 0; } }
    `);
    printWindow.document.write('</style></head><body>');
    printWindow.document.write(receiptContent);
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.print();
}

// Open update fees modal
function openUpdateFeesModal() {
    if (!currentStudentData) return;
    
    const student = currentStudentData;
    const courseFees = parseFloat(student.course_fees || student.fees_total || 0);
    const discountAmount = parseFloat(student.discount_amount || 0);
    
    document.getElementById('update_student_id').value = student.id;
    document.getElementById('update_course_fees').value = courseFees.toFixed(2);
    document.getElementById('update_discount_amount').value = discountAmount.toFixed(2);
    document.getElementById('update_discount_reason').value = student.discount_reason || '';
    
    updateNetPayable();
    
    document.getElementById('updateFeesModal').classList.add('active');
}

// Close update fees modal
function closeUpdateFeesModal() {
    document.getElementById('updateFeesModal').classList.remove('active');
}

// Calculate net payable
function updateNetPayable() {
    const courseFees = parseFloat(document.getElementById('update_course_fees').value) || 0;
    const discountAmount = parseFloat(document.getElementById('update_discount_amount').value) || 0;
    const netPayable = courseFees - discountAmount;
    document.getElementById('calculated_net_payable').textContent = `₹ ${netPayable.toFixed(2)}`;
}

// Update fees structure form submission
document.addEventListener('DOMContentLoaded', function() {
    const updateFeesForm = document.getElementById('updateFeesForm');
    if (updateFeesForm) {
        updateFeesForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const submitBtn = document.getElementById('updateFeesSubmitBtn');
            const originalText = document.getElementById('updateFeesSubmitBtnText').textContent;
            submitBtn.disabled = true;
            document.getElementById('updateFeesSubmitBtnText').textContent = 'Updating...';
            
            try {
                const formData = new FormData(this);
                const response = await fetch('', { method: 'POST', body: formData });
                const result = await response.json();
                
                if (result.success) {
                    closeUpdateFeesModal();
                    alert('Fees structure updated successfully');
                    viewStudentDetails(currentStudentId);
                } else {
                    alert(result.message || 'Error updating fees structure');
                    submitBtn.disabled = false;
                    document.getElementById('updateFeesSubmitBtnText').textContent = originalText;
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error updating fees structure');
                submitBtn.disabled = false;
                document.getElementById('updateFeesSubmitBtnText').textContent = originalText;
            }
        });
    }
});

// Open add remark modal
function openAddRemarkModal() {
    if (!currentStudentData) return;
    
    document.getElementById('remark_student_id').value = currentStudentData.id;
    document.getElementById('remark_type').value = 'General';
    document.getElementById('remark_text').value = '';
    
    document.getElementById('addRemarkModal').classList.add('active');
}

// Close add remark modal
function closeAddRemarkModal() {
    document.getElementById('addRemarkModal').classList.remove('active');
}

// Add remark form submission
document.addEventListener('DOMContentLoaded', function() {
    const addRemarkForm = document.getElementById('addRemarkForm');
    if (addRemarkForm) {
        addRemarkForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const submitBtn = document.getElementById('addRemarkSubmitBtn');
            const originalText = document.getElementById('addRemarkSubmitBtnText').textContent;
            submitBtn.disabled = true;
            document.getElementById('addRemarkSubmitBtnText').textContent = 'Adding...';
            
            try {
                const formData = new FormData(this);
                const response = await fetch('', { method: 'POST', body: formData });
                const result = await response.json();
                
                if (result.success) {
                    closeAddRemarkModal();
                    alert('Remark added successfully');
                    viewStudentDetails(currentStudentId);
                } else {
                    alert(result.message || 'Error adding remark');
                    submitBtn.disabled = false;
                    document.getElementById('addRemarkSubmitBtnText').textContent = originalText;
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error adding remark');
                submitBtn.disabled = false;
                document.getElementById('addRemarkSubmitBtnText').textContent = originalText;
            }
        });
    }
});

// Delete payment
async function deletePayment(paymentId) {
    if (!confirm('Are you sure you want to delete this payment? This will update the student\'s balance.')) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete_payment');
        formData.append('payment_id', paymentId);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            alert('Payment deleted successfully');
            closeDetailsModal();
            // Reload page to show updated data
            setTimeout(() => {
                window.location.reload();
            }, 500);
        } else {
            alert(result.message || 'Error deleting payment');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error deleting payment. Please try again.');
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('Fees Management Page Loaded');
    
    // Add click handlers for modal overlays to close on outside click
    const modals = ['detailsModal', 'paymentModal', 'receiptModal', 'updateFeesModal', 'addRemarkModal'];
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        }
    });
    
    // Add escape key handler to close modals
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (modal && modal.classList.contains('active')) {
                    modal.classList.remove('active');
                }
            });
        }
    });
});
</script>
<style>
/* Status Tabs */
.status-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    overflow-x: auto;
    padding-bottom: 0.5rem;
}

.status-tab {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.25rem;
    border-radius: var(--radius-lg);
    background: var(--bg-surface);
    border: 1.5px solid var(--border-color);
    text-decoration: none;
    color: var(--text-primary);
    font-weight: 600;
    font-size: 0.85rem;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.status-tab:hover {
    border-color: var(--primary-300);
    background: var(--primary-50);
}

.status-tab.active {
    background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
    border-color: var(--primary-600);
    color: #fff;
}

.tab-count {
    padding: 0.2rem 0.6rem;
    border-radius: var(--radius-full);
    font-size: 0.75rem;
    font-weight: 700;
    background: var(--gray-200);
    color: var(--text-primary);
}

.status-tab.active .tab-count {
    background: rgba(255, 255, 255, 0.25);
    color: #fff;
}

.badge-paid {
    background: var(--success-100);
    color: var(--success-700);
}

.badge-partial {
    background: var(--amber-100);
    color: var(--amber-700);
}

.badge-pending {
    background: var(--red-100);
    color: var(--red-700);
}

/* Fees Badges */
.fees-badge {
    display: inline-block;
    padding: 0.35rem 0.75rem;
    border-radius: var(--radius-full);
    font-size: 0.75rem;
    font-weight: 600;
}

.fees-badge.paid {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.fees-badge.partial {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
}

.fees-badge.pending {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
}

/* Student Details Grid */
.student-details-grid {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.detail-section h4 {
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border-color);
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.detail-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.detail-value {
    font-size: 0.9rem;
    color: var(--text-primary);
}

/* Fees Summary Cards */
.fees-summary-cards {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 1rem;
}

.fees-summary-card {
    padding: 1rem;
    border-radius: var(--radius-lg);
    text-align: center;
}

.fees-summary-card.total {
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    color: white;
}

.fees-summary-card.paid {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.fees-summary-card.pending {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
}

.fees-summary-label {
    font-size: 0.75rem;
    opacity: 0.9;
    margin-bottom: 0.5rem;
}

.fees-summary-value {
    font-size: 1.5rem;
    font-weight: 700;
}

/* Fees Progress Bar */
.fees-progress-bar {
    width: 100%;
    height: 32px;
    background: var(--gray-200);
    border-radius: var(--radius-full);
    overflow: hidden;
    position: relative;
}

.fees-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #8b5cf6, #7c3aed);
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding-right: 1rem;
    transition: width 1s ease;
    color: white;
    font-weight: 700;
    font-size: 0.875rem;
}

/* Payment History */
.payment-history {
    margin-top: 1.5rem;
}

.payment-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.payment-item {
    background: var(--gray-50);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 1rem;
}

.payment-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.payment-receipt {
    font-weight: 600;
    color: var(--primary-600);
}

.payment-amount {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--success-600);
}

.payment-item-details {
    display: flex;
    gap: 1rem;
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.payment-remarks {
    margin-top: 0.5rem;
    padding-top: 0.5rem;
    border-top: 1px dashed var(--border-color);
    font-size: 0.85rem;
    color: var(--text-secondary);
    font-style: italic;
}

/* Receipt Styles */
.receipt-container {
    padding: 2rem;
    background: white;
}

.receipt-header {
    text-align: center;
    border-bottom: 2px solid #000;
    padding-bottom: 1rem;
    margin-bottom: 1.5rem;
}

.receipt-header h2 {
    margin: 0;
    font-size: 1.5rem;
    color: #000;
}

.receipt-header p {
    margin: 0.5rem 0 0 0;
    font-size: 0.9rem;
    color: #666;
}

.receipt-body {
    margin: 1.5rem 0;
}

.receipt-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
}

.receipt-row.highlight {
    background: #f3f4f6;
    padding: 0.75rem;
    margin: 0.5rem -0.75rem;
    border-radius: 4px;
}

.receipt-label {
    font-weight: 600;
    color: #374151;
}

.receipt-value {
    color: #111827;
}

.receipt-divider {
    border-top: 1px dashed #d1d5db;
    margin: 1rem 0;
}

.receipt-footer {
    text-align: center;
    margin-top: 2rem;
    padding-top: 1rem;
    border-top: 2px solid #000;
    font-size: 0.85rem;
    color: #666;
}

.receipt-footer p {
    margin: 0.25rem 0;
}

/* Responsive */
@media (max-width: 768px) {
    .fees-summary-cards {
        grid-template-columns: 1fr;
    }
    
    .detail-grid {
        grid-template-columns: 1fr;
    }
    
    .payment-item-details {
        flex-direction: column;
        gap: 0.25rem;
    }
}

/* Fees Details Grid */
.fees-details-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

.fees-detail-item {
    padding: 1rem;
    background: var(--gray-50);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.fees-detail-item.highlight {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    border-color: #fbbf24;
}

.fees-detail-item.full-width {
    grid-column: 1 / -1;
}

.fees-detail-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.fees-detail-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text-primary);
}

/* Remarks Timeline */
.remarks-timeline {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.remark-item {
    background: var(--gray-50);
    border: 1px solid var(--border-color);
    border-left: 4px solid var(--primary-500);
    border-radius: var(--radius-lg);
    padding: 1rem;
}

.remark-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
}

.remark-type {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: var(--radius-full);
    font-size: 0.75rem;
    font-weight: 600;
    background: var(--primary-100);
    color: var(--primary-700);
}

.remark-type-registration {
    background: #dbeafe;
    color: #1e40af;
}

.remark-type-reporting {
    background: #fce7f3;
    color: #be185d;
}

.remark-type-exam-scheduling {
    background: #fef3c7;
    color: #92400e;
}

.remark-type-results {
    background: #d1fae5;
    color: #065f46;
}

.remark-type-dispatch {
    background: #e0e7ff;
    color: #3730a3;
}

.remark-type-payment {
    background: #dcfce7;
    color: #166534;
}

.remark-type-general {
    background: #f3f4f6;
    color: #374151;
}

.remark-type-other {
    background: #fef2f2;
    color: #991b1b;
}

.remark-time {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.remark-text {
    font-size: 0.9rem;
    color: var(--text-primary);
    line-height: 1.5;
}

.remark-author {
    margin-top: 0.5rem;
    padding-top: 0.5rem;
    border-top: 1px dashed var(--border-color);
    font-size: 0.75rem;
    color: var(--text-secondary);
    font-style: italic;
}

/* Payment Modal Styles */
.payment-info-banner {
    background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);
    color: white;
    padding: 1.5rem;
    border-radius: var(--radius-lg);
    margin-bottom: 1.5rem;
    box-shadow: 0 10px 30px rgba(236, 72, 153, 0.3);
    position: relative;
    overflow: hidden;
}

.payment-info-banner::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    pointer-events: none;
}

.payment-info-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    position: relative;
}

.payment-info-header svg {
    width: 28px;
    height: 28px;
    flex-shrink: 0;
}

.payment-info-student {
    flex: 1;
}

.payment-info-student strong {
    font-size: 1.15rem;
    display: block;
    margin-bottom: 0.25rem;
}

.payment-info-student div {
    opacity: 0.9;
    font-size: 0.9rem;
}

.payment-info-amounts {
    margin-top: 1.25rem;
    padding-top: 1.25rem;
    border-top: 1px solid rgba(255,255,255,0.3);
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.25rem;
    position: relative;
}

.payment-info-item {
    text-align: center;
}

.payment-info-label {
    opacity: 0.85;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
    font-weight: 500;
}

.payment-info-value {
    font-size: 1.5rem;
    font-weight: 700;
    text-shadow: 0 2px 10px rgba(0,0,0,0.2);
}

/* Payment Button */
.btn-payment {
    background: linear-gradient(135deg, #ec4899, #db2777) !important;
    box-shadow: 0 4px 12px rgba(236, 72, 153, 0.3);
}

.btn-payment:hover {
    background: linear-gradient(135deg, #db2777, #be185d) !important;
    box-shadow: 0 6px 16px rgba(236, 72, 153, 0.4);
}

/* Modal Header Icon */
.modal-header-icon {
    width: 22px;
    height: 22px;
    flex-shrink: 0;
    color: var(--primary-500);
}

/* Section Icon */
.section-icon {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    color: var(--primary-500);
}

/* Hint Icon */
.hint-icon {
    width: 14px;
    height: 14px;
    flex-shrink: 0;
    margin-top: 0.1rem;
    opacity: 0.7;
}

/* Button Icon */
.btn-icon-svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}

/* Form Section */
.form-section {
    margin-bottom: 0;
    background: var(--bg-surface);
    padding: 1.5rem;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border-color);
}

.form-section-title {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 1.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid var(--border-color);
}

/* Profile Form Grid */
.profile-form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.5rem;
}

.form-field {
    display: flex;
    flex-direction: column;
}

.form-field.full-width {
    grid-column: 1 / -1;
}

.form-field label {
    margin-bottom: 0.6rem;
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--text-primary);
    display: flex;
    align-items: center;
}

.form-field input,
.form-field select,
.form-field textarea {
    padding: 0.85rem 1rem;
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-md);
    font-size: 0.95rem;
    transition: all 0.2s ease;
    font-family: inherit;
    background: var(--bg-surface);
}

.form-field input:focus,
.form-field select:focus,
.form-field textarea:focus {
    outline: none;
    border-color: #ec4899;
    box-shadow: 0 0 0 3px rgba(236, 72, 153, 0.1);
    background: #fff;
}

.form-field textarea {
    resize: vertical;
    min-height: 80px;
}

.field-hint {
    display: flex;
    align-items: flex-start;
    gap: 0.4rem;
    margin-top: 0.4rem;
    font-size: 0.8rem;
    color: var(--text-secondary);
    line-height: 1.4;
}

.required {
    color: #ef4444;
    margin-left: 0.2rem;
    font-size: 1.1em;
}

/* Modal Enhancements */
.modal-card {
    background: #fff;
    border-radius: var(--radius-xl);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    overflow: hidden;
}

.modal-header {
    background: linear-gradient(135deg, var(--primary-50) 0%, var(--primary-100) 100%);
    border-bottom: 1px solid var(--primary-200);
}

.modal-header h3 {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 1.25rem;
}

.modal-body {
    padding: 2rem 2.5rem;
    max-height: calc(90vh - 200px);
    overflow-y: auto;
}

.modal-footer {
    padding: 1.5rem 2.5rem;
    border-top: 1px solid var(--border-color);
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    background: var(--gray-50);
}

.modal-footer button {
    border-radius: var(--radius-md);
    cursor: pointer;
    font-family: inherit;
}

.modal-footer .btn-secondary {
    background: #fff;
    color: var(--text-primary);
    border: 2px solid var(--border-color);
    padding: 0.75rem 1.75rem;
    font-weight: 600;
    font-size: 0.95rem;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.modal-footer .btn-secondary:hover {
    background: var(--gray-50);
    border-color: var(--gray-400);
    transform: translateY(-1px);
}

.modal-footer .btn-primary {
    background: linear-gradient(135deg, var(--primary-500), var(--primary-600));
    color: #fff;
    border: none;
    padding: 0.75rem 2rem;
    font-weight: 600;
    font-size: 0.95rem;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.modal-footer .btn-primary:hover {
    background: linear-gradient(135deg, var(--primary-600), var(--primary-700));
    box-shadow: 0 6px 16px rgba(99, 102, 241, 0.4);
    transform: translateY(-1px);
}

.modal-footer .btn-primary:active,
.modal-footer .btn-secondary:active {
    transform: translateY(0);
}

.modal-footer .btn-primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* Responsive */
@media (max-width: 768px) {
    .profile-form-grid {
        grid-template-columns: 1fr;
    }
    
    .modal-body {
        padding: 1.5rem;
    }
    
    .modal-footer {
        padding: 1rem 1.5rem;
        flex-direction: column-reverse;
    }
    
    .modal-footer button {
        width: 100%;
        justify-content: center;
    }
    
    .payment-info-amounts {
        gap: 1rem;
    }
    
    .payment-info-value {
        font-size: 1.25rem;
    }
    
    .form-section {
        padding: 1rem;
    }
}

/* Receipt Styles */
.receipt-container {
    background: white;
    max-width: 700px;
    margin: 0 auto;
}

.receipt-header {
    background: linear-gradient(135deg, #ec4899, #db2777);
    color: white;
    text-align: center;
    padding: 2.5rem 2rem;
}

.receipt-logo {
    width: 60px;
    height: 60px;
    margin: 0 auto 1rem;
    background: rgba(255,255,255,0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.receipt-logo svg {
    width: 35px;
    height: 35px;
    stroke: white;
}

.receipt-header h2 {
    font-size: 1.75rem;
    margin-bottom: 0.5rem;
    font-weight: 700;
}

.receipt-subtitle {
    font-size: 0.9rem;
    opacity: 0.9;
    margin-bottom: 1rem;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.receipt-number {
    display: inline-block;
    background: rgba(255,255,255,0.2);
    padding: 0.6rem 1.5rem;
    border-radius: 25px;
    font-size: 0.95rem;
    font-weight: 600;
}

.receipt-body {
    padding: 2rem 2.5rem;
}

.receipt-info-section {
    background: var(--gray-50);
    padding: 1rem;
    border-radius: var(--radius-md);
    margin-bottom: 2rem;
    text-align: center;
}

.receipt-info-label {
    font-size: 0.75rem;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.4rem;
}

.receipt-info-value {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
}

.receipt-section {
    margin-bottom: 2rem;
    padding-bottom: 1.5rem;
    border-bottom: 2px dashed var(--border-color);
}

.receipt-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.receipt-section-title {
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--text-primary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 1rem;
    padding-bottom: 0.6rem;
    border-bottom: 2px solid #ec4899;
}

.receipt-row {
    display: flex;
    justify-content: space-between;
    padding: 0.75rem 0;
    align-items: center;
}

.receipt-label {
    font-weight: 500;
    color: var(--text-secondary);
    font-size: 0.9rem;
}

.receipt-value {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.9rem;
    text-align: right;
}

.receipt-row-highlight {
    background: linear-gradient(135deg, #fce7f3, #fbcfe8);
    margin: 0 -1rem;
    padding: 1rem !important;
    border-radius: var(--radius-md);
}

.receipt-row-highlight .receipt-label {
    color: #be185d;
    font-weight: 700;
}

.receipt-amount {
    font-size: 1.5rem !important;
    color: #be185d !important;
    font-weight: 700 !important;
}

.receipt-row-pending {
    background: #fef3c7;
    margin: 0 -1rem;
    padding: 1rem !important;
    border-radius: var(--radius-md);
}

.receipt-row-pending .receipt-label {
    color: #92400e;
    font-weight: 700;
}

.receipt-pending {
    font-size: 1.25rem !important;
    color: #92400e !important;
    font-weight: 700 !important;
}

.receipt-footer {
    background: var(--gray-50);
    text-align: center;
    padding: 2rem;
    border-top: 2px solid var(--border-color);
}

.receipt-footer-note {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.6rem;
    font-size: 0.8rem;
    color: var(--text-secondary);
    margin-bottom: 1rem;
}

.receipt-footer-note svg {
    width: 18px;
    height: 18px;
    stroke: var(--text-secondary);
    flex-shrink: 0;
}

.receipt-footer-thanks {
    font-size: 1.1rem;
    font-weight: 600;
    color: #ec4899;
}

</style>
</body>
</html>
