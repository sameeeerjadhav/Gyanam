<?php
/**
 * Gyanam Portal — ATC: Pay Share to Admin
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/razorpay.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$atcId = $_SESSION['atc_id'] ?? null;
ensureDualMaterialCourseSchema($pdo);

// Course-wise share amounts — map both with/without; pay flow prefers admission snapshot
$courseShareAmounts = [];
$normalizedShareMap = []; // key: "coursename" or "coursename|with material"
$defaultShareAmount = 0;

try {
    $shareStmt = $pdo->prepare("
        SELECT DISTINCT c.course_name,
               c.ho_share, c.ho_share_with_material, c.ho_share_without_material, c.material_type
        FROM courses c
        WHERE c.status = 'Active'
          AND EXISTS (
              SELECT 1 FROM atc_course_fees acf
              WHERE acf.course_id = c.id AND acf.atc_id = ?
          )
        ORDER BY c.course_name ASC
    ");
    $shareStmt->execute([$atcId]);
    $shareRows = $shareStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($shareRows as $row) {
        $name = trim((string)($row['course_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $with    = max(0, (float)($row['ho_share_with_material'] ?? 0));
        $without = max(0, (float)($row['ho_share_without_material'] ?? 0));
        $legacy  = max(0, (float)($row['ho_share'] ?? 0));
        if ($with <= 0 && $without <= 0 && $legacy > 0) {
            if (($row['material_type'] ?? '') === 'With Material') $with = $legacy;
            else $without = $legacy;
        }

        // Display map shows without preferentially (list UI)
        $displayShare = $without > 0 ? $without : $with;
        $courseShareAmounts[$name] = $displayShare;
        $key = mb_strtolower($name);
        $normalizedShareMap[$key] = $displayShare;
        $normalizedShareMap[$key . '|with material']    = $with > 0 ? $with : $displayShare;
        $normalizedShareMap[$key . '|without material'] = $without > 0 ? $without : $displayShare;
        if ($key === 'other') {
            $defaultShareAmount = $displayShare;
        }
    }
} catch (Exception $e) {
    // Fallback: try without the JOIN (in case atc_course_fees doesn't exist)
    try {
        $shareStmt = $pdo->query("SELECT course_name, ho_share, ho_share_with_material, ho_share_without_material, material_type FROM courses WHERE status = 'Active' ORDER BY course_name ASC");
        foreach ($shareStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim((string)($row['course_name'] ?? ''));
            if ($name === '') continue;
            $with    = max(0, (float)($row['ho_share_with_material'] ?? 0));
            $without = max(0, (float)($row['ho_share_without_material'] ?? 0));
            $legacy  = max(0, (float)($row['ho_share'] ?? 0));
            if ($with <= 0 && $without <= 0 && $legacy > 0) {
                if (($row['material_type'] ?? '') === 'With Material') $with = $legacy;
                else $without = $legacy;
            }
            $displayShare = $without > 0 ? $without : $with;
            if ($displayShare <= 0) continue;
            $courseShareAmounts[$name] = $displayShare;
            $key = mb_strtolower($name);
            $normalizedShareMap[$key] = $displayShare;
            $normalizedShareMap[$key . '|with material']    = $with > 0 ? $with : $displayShare;
            $normalizedShareMap[$key . '|without material'] = $without > 0 ? $without : $displayShare;
        }
    } catch (Exception $e2) {}
}

if (empty($courseShareAmounts)) {
    $courseShareAmounts = ['No courses configured' => 0];
    $normalizedShareMap = [];
    $defaultShareAmount = 0;
}

$transactionFee = 15; // Fixed transaction fee

function resolveCourseShareAmount($courseName, $normalizedShareMap, $defaultShareAmount, $snapshot = null, $materialType = null)
{
    // If a snapshot exists (recorded at admission time), always use it — immune to HO updates
    if ($snapshot !== null && $snapshot > 0) {
        return (float)$snapshot;
    }
    $key = mb_strtolower(trim((string)$courseName));
    if ($materialType) {
        $matKey = $key . '|' . mb_strtolower(trim((string)$materialType));
        if ($matKey !== '' && array_key_exists($matKey, $normalizedShareMap)) {
            return (float)$normalizedShareMap[$matKey];
        }
    }
    // Fallback to live rate for older records without a snapshot
    if ($key !== '' && array_key_exists($key, $normalizedShareMap)) {
        return (float)$normalizedShareMap[$key];
    }
    return (float)$defaultShareAmount;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        // ── CREATE RAZORPAY ORDER ────────────────────────────────────
        if ($_POST['action'] === 'create_order') {
            $paymentId = intval($_POST['payment_id'] ?? 0);
            if ($paymentId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid payment reference']);
                exit;
            }

            $payStmt = $pdo->prepare("SELECT total_amount FROM share_payments WHERE id = ? AND atc_id = ? AND status = 'Pending'");
            $payStmt->execute([$paymentId, $atcId]);
            $payRow = $payStmt->fetch(PDO::FETCH_ASSOC);

            if (!$payRow) {
                echo json_encode(['success' => false, 'message' => 'Payment record not found or already processed']);
                exit;
            }

            $amountPaise = (int) round(((float)$payRow['total_amount']) * 100); // Razorpay needs paise
            if ($amountPaise <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid amount for order creation']);
                exit;
            }
            $receiptId   = 'rcpt_' . uniqid();

            $orderData = [
                'amount'          => $amountPaise,
                'currency'        => RAZORPAY_CURRENCY,
                'receipt'         => $receiptId,
                'payment_capture' => 1
            ];

            $ch = curl_init('https://api.razorpay.com/v1/orders');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($orderData),
                CURLOPT_USERPWD        => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            ]);
            $resp     = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $err = json_decode($resp, true);
                echo json_encode(['success' => false, 'message' => $err['error']['description'] ?? 'Razorpay order creation failed']);
                exit;
            }

            $order = json_decode($resp, true);
            echo json_encode(['success' => true, 'order_id' => $order['id'], 'amount' => $amountPaise]);
            exit;
        }

        if ($_POST['action'] === 'create_payment') {
            $studentIds = json_decode($_POST['student_ids'], true);
            
            if (empty($studentIds)) {
                echo json_encode(['success' => false, 'message' => 'No students selected']);
                exit;
            }
            
            // Calculate total amount
            $totalShareAmount = 0;
            $studentDetails = [];
            
            foreach ($studentIds as $studentId) {
                $stmt = $pdo->prepare("SELECT id, roll_no, first_name, last_name, course, material_type, ho_share_snapshot FROM admissions WHERE id = ? AND atc_id = ?");
                $stmt->execute([$studentId, $atcId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($student) {
                    $course = $student['course'];
                    // Use snapshot (locked at admission time) if available; else live rate by material
                    $snap = isset($student['ho_share_snapshot']) && $student['ho_share_snapshot'] > 0
                        ? (float)$student['ho_share_snapshot'] : null;
                    $shareAmount = resolveCourseShareAmount(
                        $course,
                        $normalizedShareMap,
                        $defaultShareAmount,
                        $snap,
                        $student['material_type'] ?? null
                    );
                    $totalShareAmount += $shareAmount;
                    
                    $studentDetails[] = [
                        'id' => $student['id'],
                        'roll_no' => $student['roll_no'],
                        'name' => $student['first_name'] . ' ' . $student['last_name'],
                        'course' => $course,
                        'share_amount' => $shareAmount
                    ];
                }
            }
            
            $totalAmount = $totalShareAmount + $transactionFee;
            
            // Create payment record
            $stmt = $pdo->prepare("
                INSERT INTO share_payments (atc_id, student_ids, total_share_amount, transaction_fee, total_amount, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'Pending', NOW())
            ");
            $stmt->execute([
                $atcId,
                json_encode($studentIds),
                $totalShareAmount,
                $transactionFee,
                $totalAmount
            ]);
            
            $paymentId = $pdo->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'payment_id' => $paymentId,
                'total_share_amount' => $totalShareAmount,
                'transaction_fee' => $transactionFee,
                'total_amount' => $totalAmount,
                'student_details' => $studentDetails
            ]);
            exit;
        }
        
        if ($_POST['action'] === 'verify_payment') {
            $paymentId         = $_POST['payment_id'];
            $razorpayPaymentId = $_POST['razorpay_payment_id'];
            $razorpayOrderId   = $_POST['razorpay_order_id'];
            $razorpaySignature = $_POST['razorpay_signature'];

            // ── HMAC-SHA256 signature verification ───────────────────
            $expectedSignature = hash_hmac(
                'sha256',
                $razorpayOrderId . '|' . $razorpayPaymentId,
                RAZORPAY_KEY_SECRET
            );

            if ($expectedSignature !== $razorpaySignature) {
                echo json_encode(['success' => false, 'message' => 'Payment signature verification failed. Possible fraud attempt.']);
                exit;
            }

            // Update payment record
            $stmt = $pdo->prepare("
                UPDATE share_payments
                SET status = 'Completed',
                    razorpay_payment_id = ?,
                    razorpay_order_id   = ?,
                    razorpay_signature  = ?,
                    paid_at = NOW()
                WHERE id = ? AND atc_id = ?
            ");
            $stmt->execute([$razorpayPaymentId, $razorpayOrderId, $razorpaySignature, $paymentId, $atcId]);

            echo json_encode(['success' => true, 'message' => 'Payment verified and recorded successfully']);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Fetch students — properly detect share-paid status per admission (incl. re-enrollments)
// Each admission row (even same student, different course) has its own unique ID.
// share_payments.student_ids stores a JSON array of admission IDs that were paid in that batch.
// We decode them in PHP to build a reliable lookup — the old JSON_EXTRACT JOIN was broken.

$paidAdmissionIds = [];
try {
    $sp = $pdo->prepare("SELECT student_ids FROM share_payments WHERE atc_id = ? AND status = 'Completed'");
    $sp->execute([$atcId]);
    foreach ($sp->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $ids = json_decode($json, true);
        if (is_array($ids)) {
            foreach ($ids as $id) {
                $paidAdmissionIds[intval($id)] = true;
            }
        }
    }
} catch (Exception $e) {}

// Fetch ALL active admissions — a re-enrolled student will have multiple rows (one per course)
$stmt = $pdo->prepare("SELECT *, COALESCE(ho_share_snapshot, NULL) AS ho_share_snapshot FROM admissions WHERE atc_id = ? AND status = 'Active' ORDER BY roll_no ASC, id ASC");
$stmt->execute([$atcId]);
$allAdmissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mark each admission row with its share_paid status
$students = [];
foreach ($allAdmissions as $row) {
    $row['share_paid'] = isset($paidAdmissionIds[intval($row['id'])]) ? 1 : 0;
    $students[] = $row;
}

// Get ATC details
$stmt = $pdo->prepare("SELECT * FROM atc_centers WHERE id = ?");
$stmt->execute([$atcId]);
$atcDetails = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Share — ATC Center | Gyanam India</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>💰</text></svg>">
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
                    <h2>Pay Share to Admin</h2>
                    <p>Pay course-wise share for students</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">
            
            <!-- Course Share Rates -->
            <div class="info-card">
                <div class="info-card-header">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <h3>Course-wise Share Rates</h3>
                </div>
                <div class="share-rates-grid">
                    <?php foreach ($courseShareAmounts as $course => $amount): ?>
                        <div class="share-rate-item">
                            <div class="share-course"><?= $course ?></div>
                            <div class="share-amount">₹<?= number_format($amount, 0) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="info-note">
                    <strong>Note:</strong> Additional ₹<?= $transactionFee ?> transaction fee will be charged per payment.
                </div>
            </div>

            <!-- Selected Students Summary -->
            <div class="summary-card" id="summaryCard" style="display: none;">
                <div class="summary-header">
                    <h3>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        Selected Students: <span id="selectedCount">0</span>
                    </h3>
                    <button class="btn-clear" onclick="clearSelection()">Clear All</button>
                </div>
                <div class="summary-body">
                    <div class="summary-row">
                        <span>Total Share Amount:</span>
                        <strong id="totalShareAmount">₹0</strong>
                    </div>
                    <div class="summary-row">
                        <span>Transaction Fee:</span>
                        <strong>₹<?= $transactionFee ?></strong>
                    </div>
                    <div class="summary-row total">
                        <span>Total Payable:</span>
                        <strong id="totalPayable">₹<?= $transactionFee ?></strong>
                    </div>
                </div>
                <button class="btn-pay" onclick="proceedToPayment()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    Proceed to Payment
                </button>
            </div>

            <!-- Students List -->
            <div class="page-toolbar">
                <h3>
                    Select Students for Payment
                    <span class="badge-count"><?= count($students) ?></span>
                </h3>
                <div class="search-bar">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                    <input type="text" id="searchInput" placeholder="Search students...">
                </div>
            </div>

            <div class="table-card">
                <table class="data-table" id="studentsTable">
                    <thead>
                        <tr>
                            <th style="width: 50px;">
                                <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                            </th>
                            <th>Roll No</th>
                            <th>Adm. ID</th>
                            <th>Student Name</th>
                            <th>Course</th>
                            <th>Share Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr>
                                <td colspan="7" class="table-empty">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    <p>No students found.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($students as $student): ?>
                                <?php 
                    $course = $student['course'];
                    // Use snapshot (locked at admission time) if available; else live rate
                    $snapshot = isset($student['ho_share_snapshot']) && $student['ho_share_snapshot'] > 0
                        ? (float)$student['ho_share_snapshot'] : null;
                    $shareAmount = resolveCourseShareAmount(
                        $course,
                        $normalizedShareMap,
                        $defaultShareAmount,
                        $snapshot,
                        $student['material_type'] ?? null
                    );
                    $sharePaid = $student['share_paid'];
                ?>
                                <tr data-student-id="<?= $student['id'] ?>" data-share-amount="<?= $shareAmount ?>" data-share-paid="<?= $sharePaid ?>">
                                    <td>
                                        <?php if (!$sharePaid): ?>
                                            <input type="checkbox" class="student-checkbox" value="<?= $student['id'] ?>" onchange="updateSummary()">
                                        <?php else: ?>
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 20px; height: 20px; color: #10b981;"><polyline points="20 6 9 17 4 12"/></svg>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?= htmlspecialchars($student['roll_no']) ?></strong></td>
                                    <td style="font-family:monospace;font-size:.82rem;color:#6b7280">#<?= $student['id'] ?></td>
                                    <td>
                                        <div class="cell-name">
                                            <?= htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']) ?>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($course) ?></td>
                                    <td class="fee-amount">₹<?= number_format($shareAmount, 0) ?></td>
                                    <td>
                                        <?php if ($sharePaid): ?>
                                            <span class="cell-badge status-paid">Paid</span>
                                        <?php else: ?>
                                            <span class="cell-badge status-pending">Pending</span>
                                        <?php endif; ?>
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

<script>
const courseShareAmounts = <?= json_encode($courseShareAmounts) ?>;
const transactionFee = <?= $transactionFee ?>;
const atcDetails = <?= json_encode($atcDetails) ?>;

let selectedStudents = [];


// Search functionality
document.getElementById('searchInput').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#studentsTable tbody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
});

// Toggle select all
function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.student-checkbox');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
    });
    
    updateSummary();
}

// Update summary
function updateSummary() {
    const checkboxes = document.querySelectorAll('.student-checkbox:checked');
    selectedStudents = Array.from(checkboxes).map(cb => parseInt(cb.value));
    
    let totalShareAmount = 0;
    
    checkboxes.forEach(checkbox => {
        const row = checkbox.closest('tr');
        const shareAmount = parseFloat(row.dataset.shareAmount);
        totalShareAmount += shareAmount;
    });
    
    const totalPayable = totalShareAmount + transactionFee;
    
    document.getElementById('selectedCount').textContent = selectedStudents.length;
    document.getElementById('totalShareAmount').textContent = '₹' + totalShareAmount.toLocaleString();
    document.getElementById('totalPayable').textContent = '₹' + totalPayable.toLocaleString();
    
    if (selectedStudents.length > 0) {
        document.getElementById('summaryCard').style.display = 'block';
    } else {
        document.getElementById('summaryCard').style.display = 'none';
    }
}

// Clear selection
function clearSelection() {
    document.querySelectorAll('.student-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    updateSummary();
}

// Proceed to payment
async function proceedToPayment() {
    if (selectedStudents.length === 0) {
        alert('Please select at least one student');
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'create_payment');
        formData.append('student_ids', JSON.stringify(selectedStudents));
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            initiateRazorpayPayment(result);
        } else {
            alert('Error: ' + result.message);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error creating payment. Please try again.');
    }
}

// Initiate Razorpay payment
async function initiateRazorpayPayment(paymentData) {
    // Step 1: Create a Razorpay Order server-side
    let orderId = '';
    let orderAmountPaise = 0;
    try {
        const fd = new FormData();
        fd.append('action', 'create_order');
        fd.append('payment_id', paymentData.payment_id);
        const orderResp   = await fetch('', { method: 'POST', body: fd });
        const orderResult = await orderResp.json();
        if (!orderResult.success) {
            alert('Could not create payment order: ' + orderResult.message);
            return;
        }
        orderId = orderResult.order_id;
        orderAmountPaise = orderResult.amount;
    } catch (e) {
        alert('Network error while creating order. Please try again.');
        return;
    }

    const options = {
        key:         '<?= RAZORPAY_KEY_ID ?>',
        amount:      orderAmountPaise,
        currency:    'INR',
        name:        'Gyanam India Educational Services',
        description: `Share payment for ${paymentData.student_details.length} student(s)`,
        order_id:    orderId,
        handler: function (response) {
            verifyPayment(paymentData.payment_id, response);
        },
        prefill: {
            name:    atcDetails ? atcDetails.name    : '',
            email:   atcDetails ? (atcDetails.email  || '') : '',
            contact: atcDetails ? (atcDetails.mobile || '') : ''
        },
        notes: {
            atc_id:        atcDetails ? atcDetails.id : '',
            payment_id:    paymentData.payment_id,
            student_count: paymentData.student_details.length
        },
        theme: { color: '#6366f1' },
        modal: {
            ondismiss: function() {
                showToast('Payment cancelled. You can try again anytime.', 'warning');
            }
        }
    };

    const rzp = new Razorpay(options);
    rzp.on('payment.failed', function(resp) {
        showToast('Payment failed: ' + resp.error.description, 'error');
    });
    rzp.open();
}

// Verify payment with server
async function verifyPayment(paymentId, razorpayResponse) {
    try {
        const formData = new FormData();
        formData.append('action',               'verify_payment');
        formData.append('payment_id',            paymentId);
        formData.append('razorpay_payment_id',   razorpayResponse.razorpay_payment_id);
        formData.append('razorpay_order_id',     razorpayResponse.razorpay_order_id   || '');
        formData.append('razorpay_signature',    razorpayResponse.razorpay_signature  || '');

        const response = await fetch('', { method: 'POST', body: formData });
        const result   = await response.json();

        if (result.success) {
            showToast('✅ Payment Successful! Share payment completed.', 'success');
            setTimeout(() => location.reload(), 2500);
        } else {
            showToast('Verification failed: ' + result.message, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Error verifying payment. Please contact support.', 'error');
    }
}

// Toast notification helper
function showToast(message, type = 'success') {
    const existing = document.getElementById('rzp-toast');
    if (existing) existing.remove();
    const colors = { success: '#10b981', error: '#ef4444', warning: '#f59e0b' };
    const toast = document.createElement('div');
    toast.id = 'rzp-toast';
    toast.style.cssText = `position:fixed;bottom:1.5rem;right:1.5rem;background:${colors[type]};color:#fff;padding:.875rem 1.5rem;border-radius:12px;font-size:.9rem;font-weight:700;z-index:9999;box-shadow:0 8px 24px rgba(0,0,0,.2);max-width:360px;animation:slideUp .3s ease;`;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
}
</script>

<script src="../assets/js/dashboard.js"></script>

<style>
.info-card {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    border-radius: var(--radius-lg);
    padding: 1.5rem;
    margin-bottom: 2rem;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
}

.info-card-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1.5rem;
}

.info-card-header svg {
    width: 24px;
    height: 24px;
}

.info-card-header h3 {
    font-size: 1.2rem;
    margin: 0;
}

.share-rates-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.share-rate-item {
    background: rgba(255, 255, 255, 0.15);
    padding: 1rem;
    border-radius: var(--radius-md);
    text-align: center;
    backdrop-filter: blur(10px);
}

.share-course {
    font-size: 0.9rem;
    margin-bottom: 0.5rem;
    opacity: 0.9;
}

.share-amount {
    font-size: 1.3rem;
    font-weight: 700;
}

.info-note {
    background: rgba(255, 255, 255, 0.2);
    padding: 0.75rem 1rem;
    border-radius: var(--radius-md);
    font-size: 0.9rem;
    backdrop-filter: blur(10px);
}

.summary-card {
    background: white;
    border-radius: var(--radius-lg);
    padding: 1.5rem;
    margin-bottom: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border: 2px solid #6366f1;
}

.summary-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid var(--border-color);
}

.summary-header h3 {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 1.1rem;
    margin: 0;
}

.summary-header svg {
    width: 20px;
    height: 20px;
    color: #6366f1;
}

.btn-clear {
    padding: 0.5rem 1rem;
    background: var(--bg-surface);
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-md);
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-clear:hover {
    background: var(--danger-50);
    border-color: var(--danger-300);
    color: var(--danger-600);
}

.summary-body {
    margin-bottom: 1.5rem;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 0.75rem 0;
    font-size: 1rem;
}

.summary-row.total {
    border-top: 2px solid var(--border-color);
    margin-top: 0.5rem;
    padding-top: 1rem;
    font-size: 1.2rem;
    color: #6366f1;
}

.btn-pay {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    padding: 1rem;
    background: linear-gradient(135deg, #10b981, #059669);
    border: none;
    border-radius: var(--radius-md);
    color: white;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.btn-pay:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
}

.btn-pay svg {
    width: 20px;
    height: 20px;
}

.section-header {
    margin: 2rem 0 1rem;
}

.section-header h3 {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-primary);
}

.status-paid {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.status-pending {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
}

.status-completed {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

@media (max-width: 768px) {
    .share-rates-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .summary-header {
        flex-direction: column;
        gap: 1rem;
        align-items: flex-start;
    }
}
</style>
</body>
</html>
