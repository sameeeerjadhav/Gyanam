<?php
header("Content-Type: text/html; charset=utf-8");
/**
 * Gyanam Portal - ATC: Fees Management
 * Modern UI with full functionality
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$atcId = $_SESSION['atc_id'] ?? null;

if (!$atcId) {
    die('ATC ID not found. Please log in again.');
}

// Fetch ATC name for WhatsApp messages
$atcNameStmt = $pdo->prepare("SELECT name FROM atc_centers WHERE id = ?");
$atcNameStmt->execute([$atcId]);
$atcName = $atcNameStmt->fetchColumn() ?: 'Gyanam ATC';

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

            case 'search_student':
                $q = trim($_POST['query'] ?? '');
                if (strlen($q) < 2) { echo json_encode(['success' => false, 'message' => 'Query too short']); exit; }
                $like = '%' . $q . '%';
                $stmt = $pdo->prepare("
                    SELECT id, roll_no, first_name, middle_name, last_name, course, mobile,
                           COALESCE(course_fees, fees_total, 0) as course_fees,
                           COALESCE(discount_amount, 0) as discount_amount,
                           COALESCE(net_payable, fees_total, 0) as net_payable,
                           COALESCE(fees_paid, 0) as fees_paid,
                           COALESCE(fees_pending, 0) as fees_pending,
                           installments
                    FROM admissions
                    WHERE atc_id = ? AND (roll_no LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR mobile LIKE ?)
                    ORDER BY first_name LIMIT 10
                ");
                $stmt->execute([$atcId, $like, $like, $like, $like]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $rows]);
                exit;
                
            case 'update_fees_structure':
                $studentId = $_POST['student_id'];
                $courseFees = floatval($_POST['course_fees']);
                $discountAmount = floatval($_POST['discount_amount']);
                $discountReason = $_POST['discount_reason'] ?? null;
                $netPayable = $courseFees - $discountAmount;
                
                $stmt = $pdo->prepare("SELECT fees_paid FROM admissions WHERE id = ? AND atc_id = ?");
                $stmt->execute([$studentId, $atcId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                $feesPaid = $student['fees_paid'];
                $netBalance = $netPayable - $feesPaid;
                
                $stmt = $pdo->prepare("
                    UPDATE admissions 
                    SET course_fees = ?, discount_amount = ?, discount_reason = ?, 
                        net_payable = ?, fees_total = ?, fees_pending = ?
                    WHERE id = ? AND atc_id = ?
                ");
                $stmt->execute([
                    $courseFees, $discountAmount, $discountReason, 
                    $netPayable, $netPayable, $netBalance,
                    $studentId, $atcId
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
                    
                    $newPaid = $student['fees_paid'] + $amount;
                    $newPending = $student['fees_pending'] - $amount;
                    
                    $stmt = $pdo->prepare("UPDATE admissions SET fees_paid = ?, fees_pending = ? WHERE id = ? AND atc_id = ?");
                    $stmt->execute([$newPaid, $newPending, $studentId, $atcId]);
                    
                    $stmt = $pdo->prepare("SELECT COALESCE(MAX(installment_no), 0) + 1 as next_installment FROM fee_payments WHERE admission_id = ?");
                    $stmt->execute([$studentId]);
                    $installmentNo = $stmt->fetchColumn();
                    
                    // --- Generate ATC-prefixed sequential receipt number ---
                    $atcNameStmt = $pdo->prepare("SELECT name FROM atc_centers WHERE id = ?");
                    $atcNameStmt->execute([$atcId]);
                    $atcRawName  = (string)($atcNameStmt->fetchColumn() ?: 'ATC');
                    // First word, letters only, max 8 chars, all caps
                    preg_match('/^[A-Za-z]+/', trim($atcRawName), $atcMatch);
                    $atcPrefix   = strtoupper(substr($atcMatch[0] ?? 'ATC', 0, 8));
                    // Count all existing receipts for this ATC to get next number
                    $countStmt   = $pdo->prepare("SELECT COUNT(*) FROM fee_payments WHERE atc_id = ?");
                    $countStmt->execute([$atcId]);
                    $nextSeq     = intval($countStmt->fetchColumn()) + 1;
                    $receiptNo   = $atcPrefix . '-' . str_pad($nextSeq, 5, '0', STR_PAD_LEFT);
                    // Ensure uniqueness (in case of collision)
                    $dupCheck    = $pdo->prepare("SELECT COUNT(*) FROM fee_payments WHERE receipt_no = ?");
                    $dupCheck->execute([$receiptNo]);
                    if (intval($dupCheck->fetchColumn()) > 0) {
                        $receiptNo = $atcPrefix . '-' . str_pad($nextSeq + intval(microtime(true) * 10) % 100, 5, '0', STR_PAD_LEFT);
                    }
                    
                    $checkColumns = $pdo->query("SHOW COLUMNS FROM fee_payments LIKE 'atc_id'")->rowCount();
                    
                    if ($checkColumns > 0) {
                        $stmt = $pdo->prepare("
                            INSERT INTO fee_payments (
                                admission_id, atc_id, installment_no, receipt_no, amount, payment_mode, 
                                transaction_ref, remarks, description, payment_date
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $studentId, $atcId, $installmentNo, $receiptNo, $amount, 
                            $paymentMode, $transactionRef, $remarks, $description
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO fee_payments (
                                admission_id, receipt_no, amount, payment_mode, 
                                transaction_ref, remarks, payment_date, created_by
                            ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
                        ");
                        $stmt->execute([
                            $studentId, $receiptNo, $amount, $paymentMode, 
                            $transactionRef, $remarks, $_SESSION['user_id']
                        ]);
                    }
                    
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO student_remarks (admission_id, atc_id, remark_type, remark_text, created_by)
                            VALUES (?, ?, 'Payment', ?, ?)
                        ");
                        $remarkText = "Payment of Rs. " . number_format($amount, 2) . " received via " . $paymentMode . " (Receipt: " . $receiptNo . ")";
                        $stmt->execute([$studentId, $atcId, $remarkText, $_SESSION['user_id']]);
                    } catch (Exception $e) {
                        // Continue without remark
                    }
                    
                        $newPaymentId = $pdo->lastInsertId();
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
                        'roll_no'        => $student['roll_no'],
                        'course'         => $student['course']
                    ]);
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                }
                exit;
                
            case 'get_payment_history':
                $stmt = $pdo->prepare("
                    SELECT * FROM fee_payments 
                    WHERE admission_id = ? AND atc_id = ? 
                    ORDER BY payment_date DESC, id DESC
                ");
                $studentId = intval($_POST['student_id'] ?? 0);
                if ($studentId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid student']);
                    exit;
                }

                $stmt = $pdo->prepare("SELECT id FROM admissions WHERE id = ? AND atc_id = ?");
                $stmt->execute([$studentId, $atcId]);
                if (!$stmt->fetchColumn()) {
                    echo json_encode(['success' => false, 'message' => 'Student not found']);
                    exit;
                }

                $hasAtcColumn = $pdo->query("SHOW COLUMNS FROM fee_payments LIKE 'atc_id'")->rowCount() > 0;
                if ($hasAtcColumn) {
                    $stmt = $pdo->prepare("
                        SELECT * FROM fee_payments 
                        WHERE admission_id = ? AND atc_id = ? 
                        ORDER BY payment_date DESC, id DESC
                    ");
                    $stmt->execute([$studentId, $atcId]);
                } else {
                    $stmt = $pdo->prepare("
                        SELECT * FROM fee_payments 
                        WHERE admission_id = ? 
                        ORDER BY payment_date DESC, id DESC
                    ");
                    $stmt->execute([$studentId]);
                }
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
                    $stmt->execute([$studentId, $atcId, $remarkType, $remarkText, $_SESSION['user_id']]);
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
                    
                    $pdo->beginTransaction();
                    
                    $stmt = $pdo->prepare("SELECT * FROM fee_payments WHERE id = ? AND atc_id = ?");
                    $stmt->execute([$paymentId, $atcId]);
                    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$payment) {
                        $pdo->rollBack();
                        echo json_encode(['success' => false, 'message' => 'Payment not found']);
                        exit;
                    }
                    
                    $stmt = $pdo->prepare("
                        UPDATE admissions 
                        SET fees_paid = fees_paid - ?, fees_pending = fees_pending + ?
                        WHERE id = ? AND atc_id = ?
                    ");
                    $stmt->execute([$payment['amount'], $payment['amount'], $payment['admission_id'], $atcId]);
                    
                    $stmt = $pdo->prepare("DELETE FROM fee_payments WHERE id = ?");
                    $stmt->execute([$paymentId]);
                    
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO student_remarks (admission_id, atc_id, remark_type, remark_text, created_by)
                            VALUES (?, ?, 'Payment', ?, ?)
                        ");
                        $remarkText = "Payment deleted: Rs. " . number_format($payment['amount'], 2) . " (Receipt: " . $payment['receipt_no'] . ")";
                        $stmt->execute([$payment['admission_id'], $atcId, $remarkText, $_SESSION['user_id']]);
                    } catch (Exception $e) {
                        // Continue
                    }
                    
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

$sql .= " ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter by status
$students = [];
foreach ($allStudents as $student) {
    $netPayable = floatval($student['net_payable'] ?? $student['fees_total']);
    $feesPaid = floatval($student['fees_paid']);
    $netBalance = max(0, $netPayable - $feesPaid);
    
    if ($netBalance <= 0) {
        $status = 'paid';
    } elseif ($feesPaid > 0) {
        $status = 'partial';
    } else {
        $status = 'pending';
    }
    
    if ($statusFilter === 'all' || $statusFilter === $status) {
        $students[] = $student;
    }
}

// Calculate statistics
$totalCount = count($allStudents);
$paidCount = 0;
$partialCount = 0;
$pendingCount = 0;
$totalCollected = 0;
$totalPending = 0;
$totalFees = 0;

foreach ($allStudents as $student) {
    $netPayable = floatval($student['net_payable'] ?? $student['fees_total']);
    $feesPaid = floatval($student['fees_paid']);
    $netBalance = max(0, $netPayable - $feesPaid);

    $totalFees      += $netPayable;
    $totalCollected += $feesPaid;
    $totalPending   += $netBalance;

    if ($netBalance <= 0) {
        $paidCount++;
    } elseif ($feesPaid > 0) {
        $partialCount++;
    } else {
        $pendingCount++;
    }
}
$totalPendingAmt = $totalPending;
$collectionPct   = $totalFees > 0 ? round(($totalCollected / $totalFees) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fees Management - ATC Center | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>ðŸ’°</text></svg>">
    <style>
    :root {
        --font:'Sora',sans-serif; --mono:'JetBrains Mono',monospace;
        --bg:#f4f6fb; --surface:#fff; --border:#e6eaf3;
        --text:#111827; --text-2:#374151; --text-3:#6b7280;
        --brand:#4361ee; --brand-dark:#3451d1; --brand-light:#eef1fd;
        --emerald:#10b981; --emerald-dark:#059669; --emerald-soft:#ecfdf5;
        --rose:#f43f5e;
        --shadow-sm:0 1px 4px rgba(0,0,0,.06),0 2px 8px rgba(0,0,0,.04);
        --shadow-md:0 4px 16px rgba(0,0,0,.08);
        --r-md:10px; --r-lg:14px; --r-2xl:24px; --t:.18s ease;
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
    .page-content{padding:1.75rem 2rem 2rem}
    .page-shell{display:flex;flex-direction:column;gap:1.25rem;width:100%}

    /* Panel */
    .panel{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--r-2xl);overflow:hidden;box-shadow:var(--shadow-sm)}
    .panel-head{padding:1.25rem 1.75rem;border-bottom:1.5px solid var(--border);display:flex;align-items:center;gap:.75rem}
    .panel-head-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center}
    .panel-head-icon svg{width:18px;height:18px}
    .panel-head-title{font-size:.9375rem;font-weight:800;color:var(--text)}
    .panel-head-sub{font-size:.78rem;color:var(--text-3);margin-top:.1rem}
    .panel-body{padding:1.5rem 1.75rem}

    /* Forms (used inside collect modal) */
    .form-row{display:grid;grid-template-columns:1fr 1fr;gap:.875rem}
    .form-group{display:flex;flex-direction:column;gap:.375rem}
    .form-label{font-size:.78rem;font-weight:700;color:var(--text-2)}
    .form-input,.form-select{height:42px;padding:0 .875rem;border:1.5px solid var(--border);border-radius:var(--r-md);font-size:.875rem;font-family:var(--font);color:var(--text);background:var(--surface);outline:none;transition:border-color var(--t),box-shadow var(--t);width:100%}
    .form-input:focus,.form-select:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(67,97,238,.12)}
    .btn-collect{width:100%;height:48px;border-radius:999px;background:linear-gradient(135deg,var(--emerald),var(--emerald-dark));color:#fff;font-size:.9375rem;font-weight:800;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.5rem;transition:all var(--t);box-shadow:0 4px 14px rgba(16,185,129,.3);margin-top:.25rem}
    .btn-collect:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(16,185,129,.4)}
    .btn-collect:disabled{opacity:.6;transform:none;cursor:not-allowed}
    .btn-collect svg{width:18px;height:18px}

    /* Collect Fees Modal */
    .cm-overlay{position:fixed;inset:0;z-index:1000;display:none;align-items:center;justify-content:center;padding:1rem}
    .cm-overlay.open{display:flex}
    .cm-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.5);backdrop-filter:blur(5px)}
    .cm-box{position:relative;background:#fff;border-radius:20px;width:100%;max-width:520px;box-shadow:0 24px 64px rgba(0,0,0,.22);overflow:hidden;max-height:90vh;overflow-y:auto}
    .cm-header{background:linear-gradient(135deg,#eef2ff,#e8eeff);padding:1.4rem 1.75rem 1.25rem;border-bottom:1.5px solid #c7d2fb}
    .cm-student-row{display:flex;align-items:center;gap:.875rem}
    .cm-avatar{width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,var(--brand),#7c3aed);display:flex;align-items:center;justify-content:center;font-size:1.3rem;font-weight:800;color:#fff;flex-shrink:0}
    .cm-close{background:rgba(255,255,255,.65);border:1.5px solid rgba(99,102,241,.25);border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;cursor:pointer;margin-left:auto;flex-shrink:0;color:#4361ee;transition:background var(--t)}
    .cm-close:hover{background:#fff}
    .cm-pills{display:grid;grid-template-columns:repeat(3,1fr);gap:.65rem;margin-top:1.1rem}
    .cm-pill{background:rgba(255,255,255,.72);border-radius:12px;padding:.8rem 1rem;text-align:center}
    .cm-pill-lbl{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#4361ee;margin-bottom:.3rem}
    .cm-pill-val{font-size:1.05rem;font-weight:800;font-family:var(--mono)}
    .cm-body{padding:1.4rem 1.75rem;display:flex;flex-direction:column;gap:1rem}
    .cm-history{margin-top:.2rem;border:1.5px solid #dbe4ff;border-radius:12px;background:#f8faff;overflow:hidden}
    .cm-history-head{display:flex;align-items:center;justify-content:space-between;padding:.7rem .9rem;border-bottom:1px solid #e3e9ff;background:#eef3ff}
    .cm-history-title{font-size:.74rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#1e3a8a}
    .cm-history-count{font-size:.72rem;font-weight:700;color:#334155;background:#fff;border:1px solid #dbe4ff;border-radius:999px;padding:.15rem .55rem}
    .cm-history-body{max-height:220px;overflow:auto;background:#fff}
    .cm-history-empty{padding:.95rem;font-size:.8rem;color:#64748b;text-align:center}
    .cm-history-item{display:grid;grid-template-columns:1fr auto;gap:.7rem;padding:.7rem .9rem;border-bottom:1px solid #eef2ff;align-items:center}
    .cm-history-item:last-child{border-bottom:none}
    .cm-history-meta{display:flex;flex-direction:column;gap:.12rem;min-width:0}
    .cm-history-row1{font-size:.8rem;font-weight:700;color:#1f2937;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}
    .cm-history-row2{font-size:.73rem;color:#64748b}
    .cm-receipt-tag{display:inline-flex;align-items:center;border:1px solid #c7d2fe;background:#eef2ff;color:#3730a3;border-radius:999px;padding:.1rem .45rem;font-size:.64rem;font-weight:700}
    .cm-history-actions{display:flex;gap:.35rem}
    .cm-mini-btn{height:28px;padding:0 .6rem;border-radius:8px;border:1px solid #dbe4ff;background:#fff;color:#334155;font-size:.7rem;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:.3rem;cursor:pointer;transition:all var(--t)}
    .cm-mini-btn:hover{border-color:#a5b4fc;color:#1e3a8a;background:#eef2ff}
    .cm-mini-btn svg{width:13px;height:13px}

    /* Student list table */
    .std-list-wrap{overflow-x:auto}
    .std-table{width:100%;border-collapse:separate;border-spacing:0;min-width:720px}
    .std-table thead th{background:#f8fafc;padding:.85rem 1rem;border-bottom:1.5px solid var(--border);font-size:.68rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--text-3);text-align:left;white-space:nowrap}
    .std-table tbody td{padding:.75rem 1rem;border-bottom:1px solid #edf1f7;color:var(--text-2);font-size:.84rem;vertical-align:middle}
    .std-table tbody tr:last-child td{border-bottom:none}
    .std-table tbody tr:hover td{background:#fafbff;cursor:pointer}
    .srow-avatar{width:52px;height:60px;border-radius:10px;background:linear-gradient(135deg,#eef2ff,#dbeafe);color:#2643b9;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.1rem;flex-shrink:0;overflow:hidden;box-shadow:0 2px 8px rgba(99,102,241,.18);}
    .srow-name{font-size:.875rem;font-weight:800;color:var(--text)}
    .srow-meta{font-size:.73rem;color:var(--text-3);margin-top:.15rem}
    .srow-money{font-family:var(--mono);font-weight:700;font-size:.82rem;white-space:nowrap}
    .srow-money.green{color:#047857}
    .srow-money.red{color:#be123c}
    .spill{display:inline-flex;align-items:center;padding:.3rem .7rem;border-radius:999px;font-size:.7rem;font-weight:800;white-space:nowrap}
    .spill.paid{background:#ecfdf5;color:#047857}
    .spill.partial{background:#fffbeb;color:#b45309}
    .spill.pending{background:#fff1f2;color:#be123c}
    .btn-scollect{height:34px;padding:0 .9rem;border:none;border-radius:999px;background:linear-gradient(135deg,var(--brand),var(--brand-dark));color:#fff;font:800 .74rem var(--font);cursor:pointer;display:inline-flex;align-items:center;gap:.35rem;box-shadow:0 4px 12px rgba(67,97,238,.2);transition:all var(--t);white-space:nowrap}
    .btn-scollect:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(67,97,238,.3)}
    .std-list-filter{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;padding:1rem 1.75rem;border-bottom:1.5px solid var(--border)}
    .std-list-search{position:relative;flex:1;min-width:200px}
    .std-list-search svg{position:absolute;left:.8rem;top:50%;transform:translateY(-50%);width:16px;height:16px;stroke:var(--text-3)}
    .std-list-search input{width:100%;height:38px;border:1.5px solid var(--border);border-radius:999px;padding:0 1rem 0 2.4rem;font:.83rem var(--font);color:var(--text);background:#fbfcff;outline:none;transition:border-color var(--t)}
    .std-list-search input:focus{border-color:var(--brand)}
    .std-filter-btns{display:flex;gap:.5rem;flex-wrap:wrap}
    .sfbtn{height:34px;padding:0 .85rem;border-radius:999px;border:1.5px solid var(--border);background:#fff;color:var(--text-2);font:.75rem/1 var(--font);font-weight:700;cursor:pointer;transition:all var(--t)}
    .sfbtn.active,.sfbtn:hover{background:#eef2ff;border-color:#b8c6ff;color:#1e3a8a}

    /* ── KPI Cards ── */
    .kpi-strip{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem;margin-bottom:.25rem}
    .kpi-card{
        background:#fff;border:1.5px solid var(--border);border-radius:18px;
        padding:1.25rem 1.5rem;box-shadow:var(--shadow-sm);
        display:flex;align-items:flex-start;gap:1rem;
        animation:fadeInUp .4s ease both;
        transition:transform .2s,box-shadow .2s;
    }
    .kpi-card:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,.09)}
    .kpi-icon{
        width:48px;height:48px;border-radius:14px;flex-shrink:0;
        display:flex;align-items:center;justify-content:center;
    }
    .kpi-icon svg{width:22px;height:22px}
    .kpi-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3);margin-bottom:.3rem}
    .kpi-value{font-size:1.5rem;font-weight:800;color:var(--text);line-height:1;font-family:var(--mono)}
    .kpi-sub{font-size:.73rem;color:var(--text-3);margin-top:.3rem;font-weight:500}

    /* ── Progress bar in row ── */
    .fee-progress-wrap{margin-top:.35rem;height:4px;background:#e5e7eb;border-radius:999px;overflow:hidden;width:120px;max-width:100%}
    .fee-progress-bar{height:100%;border-radius:999px;transition:width .5s ease}
    .fee-progress-bar.paid{background:linear-gradient(90deg,#10b981,#059669)}
    .fee-progress-bar.partial{background:linear-gradient(90deg,#f59e0b,#d97706)}
    .fee-progress-bar.pending{background:#f87171}

    /* ── Panel header gradient ── */
    .panel-head-gradient{
        background:linear-gradient(135deg,#eef2ff 0%,#f0f9ff 100%);
        border-bottom:1.5px solid #dbeafe;
        padding:1.25rem 1.75rem;
        display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;
    }
    .panel-head-left{display:flex;align-items:center;gap:.875rem}
    .panel-head-icon-lg{
        width:44px;height:44px;border-radius:12px;
        background:linear-gradient(135deg,#4361ee,#3730a3);
        display:flex;align-items:center;justify-content:center;
        box-shadow:0 4px 12px rgba(67,97,238,.3);
    }
    .panel-head-icon-lg svg{width:20px;height:20px;stroke:#fff}
    .panel-head-badges{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
    .ph-badge{
        display:inline-flex;align-items:center;gap:.3rem;
        padding:.3rem .75rem;border-radius:999px;font-size:.72rem;font-weight:700;
    }
    .ph-badge.blue{background:#dbeafe;color:#1d4ed8}
    .ph-badge.green{background:#d1fae5;color:#065f46}
    .ph-badge.amber{background:#fef3c7;color:#92400e}
    .ph-badge.red{background:#fee2e2;color:#991b1b}

    /* Toast */
    .toast{position:fixed;bottom:1.5rem;right:1.5rem;background:#111827;color:#fff;padding:.875rem 1.25rem;border-radius:var(--r-lg);font-size:.875rem;font-weight:600;display:flex;align-items:center;gap:.625rem;box-shadow:var(--shadow-md);z-index:9999;transform:translateY(100px);opacity:0;transition:all .3s cubic-bezier(.34,1.56,.64,1)}
    .toast.show{transform:translateY(0);opacity:1}
    .toast.success{border-left:4px solid var(--emerald)}
    .toast.error{border-left:4px solid var(--rose)}
    .toast svg{width:18px;height:18px;flex-shrink:0}

    @keyframes fadeInUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}

    @media(max-width:1100px){.kpi-strip{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:768px){
        .page-content{padding:1.1rem}.page-shell{gap:1.1rem}
        .kpi-strip{grid-template-columns:1fr 1fr}
        .panel-head,.panel-body{padding:1rem}
        .form-row{grid-template-columns:1fr}
        .cm-pills{grid-template-columns:1fr 1fr}
        .std-list-filter{flex-direction:column;align-items:stretch}
    }
    @media(max-width:480px){.kpi-strip{grid-template-columns:1fr}}
    @keyframes spin{to{transform:rotate(360deg)}}

    /* WhatsApp Buttons */
    .btn-wa-remind{
        height:34px;width:34px;border:none;border-radius:50%;cursor:pointer;
        background:#dcfce7;color:#16a34a;display:inline-flex;align-items:center;justify-content:center;
        transition:all .18s ease;flex-shrink:0;
    }
    .btn-wa-remind:hover{background:#25D366;color:#fff;transform:translateY(-1px);box-shadow:0 4px 12px rgba(37,211,102,.35)}
    .btn-wa-thankyou{
        height:30px;padding:0 .7rem;border:none;border-radius:999px;cursor:pointer;
        background:#dcfce7;color:#16a34a;font:700 .72rem var(--font);display:inline-flex;align-items:center;gap:.3rem;
        transition:all .18s ease;
    }
    .btn-wa-thankyou:hover{background:#25D366;color:#fff;transform:translateY(-1px);box-shadow:0 4px 12px rgba(37,211,102,.35)}
    .btn-wa-thankyou svg{fill:currentColor;width:14px;height:14px}
    </style>
</head>
<body>
<div class="dashboard-layout">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <header class="top-header">
            <div class="header-left">
                <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <div class="header-greeting">
                    <h2>Fees Management</h2>
                    <p>Collect fees and review payment history</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">
            <div class="page-shell">

            <!-- ── KPI Strip ── -->
            <div class="kpi-strip">
                <div class="kpi-card" style="animation-delay:.05s">
                    <div class="kpi-icon" style="background:#eef2ff">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#4361ee" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div>
                        <div class="kpi-label">Total Students</div>
                        <div class="kpi-value"><?= $totalCount ?></div>
                        <div class="kpi-sub"><?= $paidCount ?> fully paid · <?= $partialCount ?> partial</div>
                    </div>
                </div>
                <div class="kpi-card" style="animation-delay:.1s">
                    <div class="kpi-icon" style="background:#ecfdf5">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <div>
                        <div class="kpi-label">Total Collected</div>
                        <div class="kpi-value" style="color:#047857">&#8377;<?= number_format($totalCollected, 0) ?></div>
                        <div class="kpi-sub"><?= $collectionPct ?>% of total fees</div>
                    </div>
                </div>
                <div class="kpi-card" style="animation-delay:.15s">
                    <div class="kpi-icon" style="background:#fef2f2">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </div>
                    <div>
                        <div class="kpi-label">Pending Amount</div>
                        <div class="kpi-value" style="color:#be123c">&#8377;<?= number_format($totalPendingAmt, 0) ?></div>
                        <div class="kpi-sub"><?= $pendingCount ?> awaiting · <?= $partialCount ?> partial</div>
                    </div>
                </div>
                <div class="kpi-card" style="animation-delay:.2s">
                    <div class="kpi-icon" style="background:#fefce8">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#ca8a04" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div>
                        <div class="kpi-label">Fully Cleared</div>
                        <div class="kpi-value" style="color:#854d0e"><?= $paidCount ?></div>
                        <div class="kpi-sub"><?= $totalCount > 0 ? round(($paidCount / $totalCount) * 100) : 0 ?>% clearance rate</div>
                    </div>
                </div>
            </div>

            <!-- Student List -->
            <div class="panel">
                <div class="panel-head-gradient">
                    <div class="panel-head-left">
                        <div class="panel-head-icon-lg">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        </div>
                        <div>
                            <div style="font-size:1rem;font-weight:800;color:#1e3a8a;letter-spacing:-.02em">Fees Overview</div>
                            <div style="font-size:.78rem;color:#3b4f9a;margin-top:.1rem"><?= $totalCount ?> enrolled students · Click any row to manage fees</div>
                        </div>
                    </div>
                    <div class="panel-head-badges">
                        <span class="ph-badge blue"><?= $totalCount ?> Total</span>
                        <span class="ph-badge green"><?= $paidCount ?> Paid</span>
                        <span class="ph-badge amber"><?= $partialCount ?> Partial</span>
                        <span class="ph-badge red"><?= $pendingCount ?> Pending</span>
                    </div>
                </div>
                <div class="std-list-filter">
                    <div class="std-list-search">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" id="stdListSearch" placeholder="Filter list..." oninput="filterStdList()">
                    </div>
                    <div class="std-filter-btns">
                        <button class="sfbtn active" data-filter="all"    onclick="setStdFilter('all',this)">All</button>
                        <button class="sfbtn"        data-filter="pending" onclick="setStdFilter('pending',this)">Pending</button>
                        <button class="sfbtn"        data-filter="partial" onclick="setStdFilter('partial',this)">Partial</button>
                        <button class="sfbtn"        data-filter="paid"    onclick="setStdFilter('paid',this)">Paid</button>
                    </div>
                </div>
                <div class="panel-body" style="padding:0">
                    <div class="std-list-wrap">
                        <table class="std-table" id="stdListTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Student</th>
                                    <th>Course</th>
                                    <th>Net Payable</th>
                                    <th>Paid</th>
                                    <th>Pending</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="stdListTbody">
<?php
$rowNum = 0;
foreach ($allStudents as $s):
    $rowNum++;
    $sName   = trim($s['first_name'] . ' ' . ($s['middle_name'] ?? '') . ' ' . $s['last_name']);
    $sInit   = mb_strtoupper(mb_substr($sName, 0, 1));
    $sNet    = floatval($s['net_payable'] ?? $s['fees_total']);
    $sPaid   = floatval($s['fees_paid']);
    $sBal    = max(0, $sNet - $sPaid);
    $sSt     = $sBal <= 0 ? 'paid' : ($sPaid > 0 ? 'partial' : 'pending');
    $sStLabel = ucfirst($sSt);
?>
                                <tr data-status="<?= $sSt ?>" data-name="<?= htmlspecialchars(strtolower($sName)) ?>" data-roll="<?= htmlspecialchars(strtolower($s['roll_no'])) ?>" onclick="window.location='collect_fees.php?id=<?= (int)$s['id'] ?>'" style="cursor:pointer">
                                    <td style="color:var(--text-3);font-size:.78rem"><?= $rowNum ?></td>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:.75rem">
                                            <?php if (!empty($s['photo'])): ?>
                                                <img src="../<?= htmlspecialchars($s['photo']) ?>" alt="<?= htmlspecialchars($sInit) ?>" style="width:52px;height:60px;border-radius:10px;object-fit:cover;border:2px solid #e0e7ff;box-shadow:0 2px 8px rgba(0,0,0,.1);flex-shrink:0;display:block;" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                                                <div class="srow-avatar" style="display:none"><?= htmlspecialchars($sInit) ?></div>
                                            <?php else: ?>
                                                <div class="srow-avatar"><?= htmlspecialchars($sInit) ?></div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="srow-name"><?= htmlspecialchars($sName) ?></div>
                                                <div class="srow-meta"><?= htmlspecialchars($s['roll_no']) ?> &middot; <?= htmlspecialchars($s['mobile'] ?: '-') ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-size:.84rem;font-weight:700;color:var(--text-2)"><?= htmlspecialchars($s['course']) ?></div>
                                    </td>
                                    <td class="srow-money">&#8377;<?= number_format($sNet, 0) ?></td>
                                    <td>
                                        <div class="srow-money green">&#8377;<?= number_format($sPaid, 0) ?></div>
                                        <?php $pct = $sNet > 0 ? min(100, round(($sPaid / $sNet) * 100)) : 0; ?>
                                        <div class="fee-progress-wrap">
                                            <div class="fee-progress-bar <?= $sSt ?>" style="width:<?= $pct ?>%"></div>
                                        </div>
                                    </td>
                                    <td class="srow-money red">&#8377;<?= number_format($sBal, 0) ?></td>
                                    <td><span class="spill <?= $sSt ?>"><?= $sStLabel ?></span></td>
                                    <td onclick="event.stopPropagation()">
                                        <div style="display:flex;gap:.35rem;align-items:center">
                                        <?php if ($sSt !== 'paid'): ?>
                                            <a href="collect_fees.php?id=<?= (int)$s['id'] ?>" class="btn-scollect" style="text-decoration:none">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                                Collect
                                            </a>
                                            <button class="btn-wa-remind" onclick="sendFeeReminder('<?= htmlspecialchars($s['mobile'] ?: '', ENT_QUOTES) ?>', '<?= htmlspecialchars($sName, ENT_QUOTES) ?>', '<?= number_format($sBal, 0) ?>', '<?= htmlspecialchars($s['course'], ENT_QUOTES) ?>')" title="Send WhatsApp Fee Reminder">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                            </button>
                                        <?php else: ?>
                                            <span style="font-size:.74rem;color:#047857;font-weight:700">&#10003; Cleared</span>
                                        <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
<?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            </div>

        </div><!-- /.page-content -->
    </div><!-- /.main-content -->
</div><!-- /.dashboard-layout -->

<!-- Collect Fees Modal -->
<div class="cm-overlay" id="collectModal">
    <div class="cm-backdrop" onclick="closeCollectModal()"></div>
    <div class="cm-box">
        <div class="cm-header">
            <div class="cm-student-row">
                <div class="cm-avatar" id="cmAvatar">?</div>
                <div style="min-width:0;flex:1">
                    <div id="cmName" style="font-size:1.05rem;font-weight:800;color:#1e3a8a;line-height:1.25">-</div>
                    <div id="cmRoll" style="font-size:.74rem;font-weight:600;color:#3730a3;margin-top:.1rem"></div>
                    <div id="cmCourse" style="font-size:.77rem;color:#4361ee;font-weight:600;margin-top:.1rem"></div>
                </div>
                <button class="cm-close" onclick="closeCollectModal()" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="18" height="18"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="cm-pills">
                <div class="cm-pill">
                    <div class="cm-pill-lbl">Net Payable</div>
                    <div class="cm-pill-val" id="cmNetPayable">-</div>
                </div>
                <div class="cm-pill">
                    <div class="cm-pill-lbl">Paid</div>
                    <div class="cm-pill-val" id="cmFeesPaid" style="color:#047857">-</div>
                </div>
                <div class="cm-pill">
                    <div class="cm-pill-lbl">Balance Due</div>
                    <div class="cm-pill-val" id="cmBalance" style="color:#be123c">-</div>
                </div>
            </div>
        </div>
        <div class="cm-body">
            <input type="hidden" id="selectedStudentId" value="">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Amount (&#8377;) <span style="color:var(--rose)">*</span></label>
                    <input type="number" class="form-input" id="feeAmount" min="1" step="1" placeholder="e.g. 1500" oninput="validateFeeAmount()">
                    <small id="feeAmountHint" style="display:none;color:#be123c;font-size:.78rem;margin-top:.25rem;font-weight:600;"></small>
                </div>
                <div class="form-group">
                    <label class="form-label">Payment Mode <span style="color:var(--rose)">*</span></label>
                    <select class="form-select" id="paymentMode">
                        <option value="">- Select -</option>
                        <option value="Cash">Cash</option>
                        <option value="UPI">UPI</option>
                        <option value="Online Transfer">Online Transfer</option>
                        <option value="Cheque">Cheque</option>
                        <option value="DD">Demand Draft</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Description / Reference</label>
                <input type="text" class="form-input" id="feeDescription" placeholder="e.g. 2nd installment, UPI txn ID..." maxlength="255">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Installment Amount (&#8377;)</label>
                    <input type="number" class="form-input" id="installmentAmt" min="0" step="1" placeholder="Per installment">
                </div>
                <div class="form-group">
                    <label class="form-label">Next Installment Date</label>
                    <input type="date" class="form-input" id="nextInstallmentDate">
                </div>
            </div>
            <button class="btn-collect" id="collectBtn" onclick="collectFees()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                Collect Fees
            </button>

            <div class="cm-history">
                <div class="cm-history-head">
                    <div class="cm-history-title">Student Payment History</div>
                    <div class="cm-history-count" id="cmHistoryCount">0 Receipts</div>
                </div>
                <div class="cm-history-body" id="cmHistoryBody">
                    <div class="cm-history-empty">No previous receipts found.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast">
    <svg id="toastIcon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
    <span id="toastMsg"></span>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
let currentStudent = null;

/* Toast */
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    const icon = document.getElementById('toastIcon');
    t.className = 'toast ' + type;
    document.getElementById('toastMsg').textContent = msg;
    if (type === 'error') icon.innerHTML = '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>';
    else icon.innerHTML = '<polyline points="20 6 9 17 4 12"/>';
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3500);
}

/* Load student by ID then open collect modal */
function selectStudentById(id) {
    const fd = new FormData();
    fd.append('action', 'get_student');
    fd.append('id', id);
    fetch('', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data) openCollectModal(data.data);
        })
        .catch(err => console.error('Error loading student:', err));
}

/* Populate and open the collect modal */
function openCollectModal(s) {
    currentStudent = s;
    const name = [s.first_name, s.middle_name, s.last_name].filter(Boolean).join(' ');
    const net  = parseFloat(s.net_payable)  || 0;
    const paid = parseFloat(s.fees_paid)    || 0;
    const bal  = Math.max(0, parseFloat(s.fees_pending) || (net - paid));

    document.getElementById('cmAvatar').textContent     = name.charAt(0).toUpperCase();
    document.getElementById('cmName').textContent       = name;
    document.getElementById('cmRoll').textContent       = s.roll_no || '';
    document.getElementById('cmCourse').textContent     = s.course  || '';
    document.getElementById('cmNetPayable').textContent = '\u20B9' + net.toLocaleString('en-IN');
    document.getElementById('cmFeesPaid').textContent   = '\u20B9' + paid.toLocaleString('en-IN');
    document.getElementById('cmBalance').textContent    = '\u20B9' + bal.toLocaleString('en-IN');
    document.getElementById('selectedStudentId').value  = s.id;

    // Set max allowed payment = remaining balance
    const feeAmountInput = document.getElementById('feeAmount');
    feeAmountInput.max = bal > 0 ? bal : 0;
    feeAmountInput.setAttribute('data-max-balance', bal);

    ['feeAmount','feeDescription','installmentAmt','nextInstallmentDate'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('paymentMode').value = '';

    document.getElementById('collectModal').classList.add('open');
    document.body.style.overflow = 'hidden';

    loadStudentPaymentHistory(s.id);
}

function closeCollectModal() {
    document.getElementById('collectModal').classList.remove('open');
    document.body.style.overflow = '';
    currentStudent = null;
    // Reset validation state
    document.getElementById('feeAmountHint').style.display = 'none';
    document.getElementById('collectBtn').disabled = false;
}

function validateFeeAmount() {
    const input   = document.getElementById('feeAmount');
    const hint    = document.getElementById('feeAmountHint');
    const btn     = document.getElementById('collectBtn');
    const maxBal  = parseFloat(input.getAttribute('data-max-balance') || input.max || 0);
    const entered = parseFloat(input.value) || 0;

    if (maxBal > 0 && entered > maxBal) {
        hint.textContent = `⚠️ Cannot exceed remaining balance of ₹${maxBal.toLocaleString('en-IN')}. Max allowed: ₹${maxBal.toLocaleString('en-IN')}.`;
        hint.style.display = 'block';
        input.style.borderColor = '#ef4444';
        btn.disabled = true;
        btn.style.opacity = '0.6';
        btn.style.cursor  = 'not-allowed';
    } else if (entered <= 0 && input.value !== '') {
        hint.textContent = '⚠️ Amount must be greater than 0.';
        hint.style.display = 'block';
        input.style.borderColor = '#ef4444';
        btn.disabled = true;
        btn.style.opacity = '0.6';
        btn.style.cursor  = 'not-allowed';
    } else {
        hint.style.display = 'none';
        input.style.borderColor = '';
        btn.disabled = false;
        btn.style.opacity = '';
        btn.style.cursor  = '';
    }
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeCollectModal(); });

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, function (char) {
        return ({'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'})[char];
    });
}

function loadStudentPaymentHistory(studentId) {
    const historyBody = document.getElementById('cmHistoryBody');
    const historyCount = document.getElementById('cmHistoryCount');

    historyBody.innerHTML = '<div class="cm-history-empty">Loading payment history...</div>';
    historyCount.textContent = '...';

    const fd = new FormData();
    fd.append('action', 'get_payment_history');
    fd.append('student_id', studentId);

    fetch('', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                historyBody.innerHTML = '<div class="cm-history-empty">Unable to load history.</div>';
                historyCount.textContent = '0 Receipts';
                return;
            }

            const rows = Array.isArray(data.data) ? data.data : [];
            historyCount.textContent = `${rows.length} Receipt${rows.length === 1 ? '' : 's'}`;

            if (!rows.length) {
                historyBody.innerHTML = '<div class="cm-history-empty">No previous receipts found.</div>';
                return;
            }

            historyBody.innerHTML = rows.map(payment => {
                const amount = parseFloat(payment.amount || 0);
                const receiptNo = payment.receipt_no || ('PAY-' + payment.id);
                const paymentDate = payment.payment_date
                    ? new Date(payment.payment_date).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
                    : '-';
                const printUrl = payment.id
                    ? `fee_receipt.php?payment_id=${encodeURIComponent(payment.id)}`
                    : `fee_receipt.php?receipt_no=${encodeURIComponent(payment.receipt_no || '')}`;
                const downloadUrl = printUrl + '&download=1';

                return `
                    <div class="cm-history-item">
                        <div class="cm-history-meta">
                            <div class="cm-history-row1">
                                <span class="cm-receipt-tag">${escapeHtml(receiptNo)}</span>
                                <span>₹${amount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                            </div>
                            <div class="cm-history-row2">${escapeHtml(paymentDate)} · ${escapeHtml(payment.payment_mode || '-')} · Installment #${escapeHtml(payment.installment_no || 1)}</div>
                        </div>
                        <div class="cm-history-actions">
                            <a class="cm-mini-btn" href="${printUrl}" target="_blank" rel="noopener" title="Open Receipt">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                Open
                            </a>
                            <a class="cm-mini-btn" href="${downloadUrl}" target="_blank" rel="noopener" title="Download Receipt">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                Download
                            </a>
                        </div>
                    </div>
                `;
            }).join('');
        })
        .catch(err => {
            historyBody.innerHTML = '<div class="cm-history-empty">Unable to load history.</div>';
            historyCount.textContent = '0 Receipts';
            console.error('Error loading payment history:', err);
        });
}

/* Student list filter */
function filterStdList() {
    const q = document.getElementById('stdListSearch').value.toLowerCase().trim();
    document.querySelectorAll('#stdListTbody tr').forEach(tr => {
        const name = tr.dataset.name || '';
        const roll = tr.dataset.roll || '';
        const statusOk = !window._stdFilter || window._stdFilter === 'all' || tr.dataset.status === window._stdFilter;
        const textOk   = !q || name.includes(q) || roll.includes(q);
        tr.style.display = (statusOk && textOk) ? '' : 'none';
    });
}
function setStdFilter(f, btn) {
    window._stdFilter = f;
    document.querySelectorAll('.sfbtn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    filterStdList();
}
/* Collect Fees */
function collectFees() {
    const studentId = document.getElementById('selectedStudentId').value;
    const amount    = parseFloat(document.getElementById('feeAmount').value) || 0;
    const mode      = document.getElementById('paymentMode').value;
    const desc      = document.getElementById('feeDescription').value.trim();

    if (!studentId) { showToast('Please search and select a student first', 'error'); return; }
    if (amount <= 0) { showToast('Please enter a valid fee amount', 'error'); return; }
    if (!mode)       { showToast('Please select a payment mode', 'error'); return; }

    const btn = document.getElementById('collectBtn');
    btn.disabled = true;
    btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;animation:spin .7s linear infinite"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/></svg> Processing...';

    const nextDate = document.getElementById('nextInstallmentDate').value;
    const nextAmt  = document.getElementById('installmentAmt').value;
    const fd = new FormData();
    fd.append('action', 'record_payment');
    fd.append('student_id', studentId);
    fd.append('amount', amount);
    fd.append('payment_mode', mode);
    fd.append('description', desc);
    fd.append('transaction_ref', nextDate);
    fd.append('remarks', 'Next installment: ' + (nextDate || '-') + (nextAmt ? ', Installment amt: \u20B9' + nextAmt : ''));

    fetch('', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Collect Fees';

            if (data.success) {
                showToast('\u2705 Payment recorded! Receipt: ' + data.receipt_no);
                if (data.payment_id) setTimeout(() => window.open('fee_receipt.php?payment_id=' + data.payment_id, '_blank'), 700);
                // Show WhatsApp Thank You option
                if (data.student_mobile) {
                    const doSend = confirm('Payment recorded!\n\nWould you like to send a Thank You message via WhatsApp to ' + data.student_name + '?');
                    if (doSend) {
                        sendPaymentThankYou(data.student_mobile, data.student_name, amount.toLocaleString('en-IN'), data.course, data.new_pending.toLocaleString('en-IN'));
                    }
                }
                closeCollectModal();
                setTimeout(() => location.reload(), 2500);
            } else {
                showToast(data.message || 'Error recording payment', 'error');
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Collect Fees';
            showToast('Error occurred: ' + (err.message || 'Unknown error'), 'error');
            console.error('Error collecting fees:', err);
        });
}

/* ── WhatsApp Notification Functions ── */
const _atcName = <?= json_encode($atcName) ?>;

function sendFeeReminder(mobile, studentName, balance, course) {
    if (!mobile || mobile.length < 10) {
        showToast('No mobile number available for this student', 'error');
        return;
    }
    const phone = mobile.replace(/\D/g, '');
    const waPhone = phone.length === 10 ? '91' + phone : phone;
    const msg = `Dear Student, *${studentName}*, your balance amount is *Rs.${balance}* for course *${course}*. Please pay your outstanding fees.\n\nBest Regards,\n*${_atcName}*`;
    window.open('https://wa.me/' + waPhone + '?text=' + encodeURIComponent(msg), '_blank');
}

function sendPaymentThankYou(mobile, studentName, amountPaid, course, newBalance) {
    if (!mobile || mobile.length < 10) {
        showToast('No mobile number available for this student', 'error');
        return;
    }
    const phone = mobile.replace(/\D/g, '');
    const waPhone = phone.length === 10 ? '91' + phone : phone;
    const msg = `Dear Student, *${studentName}*, thank you for the payment of *Rs.${amountPaid}* for course *${course}*. Your balance amount is *Rs.${newBalance}*\n\nBest Regards,\n*${_atcName}*`;
    window.open('https://wa.me/' + waPhone + '?text=' + encodeURIComponent(msg), '_blank');
}

</script>
</body>
</html>
