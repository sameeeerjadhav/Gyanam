<?php
/**
 * Gyanam Portal — ATC: Student Admissions Management
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);  // Never output errors to browser — log them instead
ini_set('log_errors', 1);
ob_start(); // Buffer all output so stray warnings don't corrupt JSON

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Try to include notifications, but don't fail if it doesn't exist
if (file_exists(__DIR__ . '/../includes/notifications.php')) {
    require_once __DIR__ . '/../includes/notifications.php';
}

// Exam Portal integration (auto-sync students)
if (file_exists(__DIR__ . '/../includes/exam_integration.php')) {
    require_once __DIR__ . '/../includes/exam_integration.php';
}

requireLogin(['ATC CENTER']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$atcId = $_SESSION['atc_id'] ?? null;
ensureDualMaterialCourseSchema($pdo);

// ── Auto-migration: snapshot ho_share at admission time ───────────────────────
// This ensures existing students keep the rate active when they joined,
// even if HO later updates the course share amount.
try {
    $pdo->exec("ALTER TABLE admissions ADD COLUMN IF NOT EXISTS ho_share_snapshot DECIMAL(10,2) DEFAULT NULL COMMENT 'HO share rate locked at time of admission'");
} catch (Exception $e) { /* column may already exist */ }

// getHoShareForCourse() is defined in includes/functions.php (material-aware)

// generateNextRollNoSimple() and generateRegistrationId() are defined in includes/functions.php

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean(); // Discard any stray output before JSON
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    
    try {
        switch ($_POST['action']) {
            case 'search':
                // Search in both admissions and inquiries
                $searchTerm = $_POST['search'] ?? '';
                $results = ['admissions' => [], 'inquiries' => []];
                
                if ($searchTerm) {
                    // Search in ALL admissions (not just those from inquiries)
                    $stmt = $pdo->prepare("
                        SELECT *, 'admission' as record_type FROM admissions 
                        WHERE atc_id = ?
                        AND (first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ? OR roll_no LIKE ? OR mobile LIKE ?)
                        ORDER BY admission_date DESC
                        LIMIT 10
                    ");
                    $searchParam = "%$searchTerm%";
                    $stmt->execute([$atcId, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
                    $results['admissions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Search in inquiries (non-converted only)
                    $stmt = $pdo->prepare("
                        SELECT *, 'inquiry' as record_type FROM inquiries 
                        WHERE atc_id = ? AND status != 'Converted'
                        AND (first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ? OR mobile LIKE ?)
                        ORDER BY created_at DESC
                        LIMIT 10
                    ");
                    $stmt->execute([$atcId, $searchParam, $searchParam, $searchParam, $searchParam]);
                    $results['inquiries'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                
                echo json_encode(['success' => true, 'data' => $results]);
                exit;
                
            case 'convert_to_admission':
                $inqSource = $_POST['inquiry_source'] ?? 'walkin';

                // Get inquiry data from the correct table
                if ($inqSource === 'telephonic') {
                    $stmt = $pdo->prepare("SELECT * FROM telephonic_inquiries WHERE id = ? AND atc_id = ?");
                } else {
                    $stmt = $pdo->prepare("SELECT * FROM inquiries WHERE id = ? AND atc_id = ?");
                }
                $stmt->execute([$_POST['inquiry_id'], $atcId]);
                $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$inquiry) {
                    echo json_encode(['success' => false, 'message' => 'Inquiry not found']);
                    exit;
                }
                
                // Fetch ATC center_type for Registration ID abbreviation
                $atcStmt = $pdo->prepare("SELECT center_type FROM atc_centers WHERE id = ?");
                $atcStmt->execute([$atcId]);
                $centerType = $atcStmt->fetchColumn() ?: 'Other';

                $courseFees     = floatval($_POST['course_fees']    ?? 0);
                $discountAmount = floatval($_POST['discount_amount'] ?? 0);
                $netPayable     = max(0, $courseFees - $discountAmount);

                // Generate sequential Roll No (per ATC) and global Registration ID
                $rollNo         = generateNextRollNoSimple($pdo, $atcId);
                $registrationId = generateRegistrationId($pdo, $centerType);

                // inquiry_id FK only references `inquiries` table (NOT `telephonic_inquiries`)
                // For telephonic source we must pass NULL to avoid FK constraint violation
                $fkInquiryId = ($inqSource === 'telephonic') ? null : (int)$_POST['inquiry_id'];

                // Snapshot HO + DLC shares at admission time
                $matType1 = $_POST['material_type'] ?? 'Without Material';
                $hoShareSnapshot1  = getHoShareForCourse($pdo, $_POST['course'] ?? '', $matType1);
                $dlcShareSnapshot1 = getDlcShareForCourse($pdo, $_POST['course'] ?? '', $matType1);

                $stmt = $pdo->prepare("
                    INSERT INTO admissions (
                        atc_id, inquiry_id, roll_no, registration_id,
                        first_name, middle_name, last_name,
                        gender, dob, qualification, course, uniform_size, photo, address, state, pin_code, city,
                        mobile, phone, email, referenced_by, comment, admission_date,
                        course_fees, discount_amount, discount_reason, installments, net_payable,
                        fees_total, fees_pending,
                        father_name, mother_name, material_type, material_language,
                        ho_share_snapshot, dlc_share_snapshot, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')
                ");

                $stmt->execute([
                    $atcId,
                    $fkInquiryId,  // NULL for telephonic (FK only references inquiries table)
                    $rollNo,
                    $registrationId,
                    trim($_POST['cvt_first_name']    ?? $inquiry['first_name']),
                    $inquiry['middle_name'],
                    trim($_POST['cvt_last_name']     ?? $inquiry['last_name']),
                    $_POST['cvt_gender']             ?? $inquiry['gender'],
                    $_POST['cvt_dob']                ?? $inquiry['dob'],
                    trim($_POST['cvt_qualification'] ?? $inquiry['qualification']),
                    $_POST['course'],
                    $_POST['uniform_size'] ?? null,
                    null,
                    trim($_POST['cvt_address']  ?? $inquiry['address']),
                    trim($_POST['cvt_state']    ?? ''),
                    trim($_POST['cvt_pin_code'] ?? $inquiry['pin_code']),
                    trim($_POST['cvt_city']     ?? $inquiry['city']),
                    trim($_POST['cvt_mobile']   ?? $inquiry['mobile']),
                    $inquiry['phone'],
                    $inquiry['email'],
                    $inquiry['referenced_by'],
                    $_POST['comment'] ?? $inquiry['comment'],
                    date('Y-m-d'),
                    $courseFees,
                    $discountAmount,
                    $_POST['discount_reason'] ?? null,
                    intval($_POST['installments'] ?? 1),
                    $netPayable,
                    $netPayable,
                    $netPayable,
                    trim($_POST['father_name'] ?? ''),
                    trim($_POST['mother_name'] ?? ''),
                    $matType1,
                    $_POST['material_language'] ?? 'English',
                    $hoShareSnapshot1,
                    $dlcShareSnapshot1,
                ]);

                $admissionId = (int)$pdo->lastInsertId();

                $inserted = true;

                // Upload photo after roll_no is finalized
                $photoField = isset($_FILES['student_photo']) ? 'student_photo' : (isset($_FILES['photo']) ? 'photo' : null);
                if ($photoField && $_FILES[$photoField]['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/../uploads/students/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $fileExt = strtolower(pathinfo($_FILES[$photoField]['name'], PATHINFO_EXTENSION));
                    if (in_array($fileExt, ['jpg','jpeg','png'])) {
                        $fileName = $rollNo . '.' . $fileExt;
                        if (move_uploaded_file($_FILES[$photoField]['tmp_name'], $uploadDir . $fileName)) {
                            $photoPath = 'uploads/students/' . $fileName;
                            $pdo->prepare("UPDATE admissions SET photo = ? WHERE id = ? AND atc_id = ?")
                                ->execute([$photoPath, $admissionId, $atcId]);
                        }
                    }
                }
                
                // Mark inquiry as Converted in the correct table
                if (($inqSource ?? 'walkin') === 'telephonic') {
                    $pdo->prepare("UPDATE telephonic_inquiries SET status = 'Converted' WHERE id = ?")
                        ->execute([$_POST['inquiry_id']]);
                } else {
                    $pdo->prepare("UPDATE inquiries SET status = 'Converted' WHERE id = ?")
                        ->execute([$_POST['inquiry_id']]);
                }
                
                // Update student count in ATC center
                $stmt = $pdo->prepare("UPDATE atc_centers SET student_count = student_count + 1 WHERE id = ?");
                $stmt->execute([$atcId]);
                
                // ── Sync to Exam Portal (fire-and-forget) ──
                $examSyncWarning = null;
                if (function_exists('syncStudentToExamPortal') && defined('EXAM_API_TOKEN') && EXAM_API_TOKEN !== 'PASTE_YOUR_TOKEN_HERE') {
                    try {
                        $atcCodeStmt = $pdo->prepare("SELECT atc_code FROM atc_centers WHERE id = ?");
                        $atcCodeStmt->execute([$atcId]);
                        $syncAtcCode = $atcCodeStmt->fetchColumn() ?: 'ATC' . $atcId;
                        $syncName = trim(($_POST['cvt_first_name'] ?? $inquiry['first_name']) . ' ' . ($inquiry['middle_name'] ? $inquiry['middle_name'] . ' ' : '') . ($_POST['cvt_last_name'] ?? $inquiry['last_name']));
                        $syncResult = syncStudentToExamPortal($registrationId, $syncName, $syncAtcCode);
                        if (!$syncResult['success']) {
                            $examSyncWarning = 'Exam Portal sync failed: ' . ($syncResult['error'] ?? 'Unknown error');
                            error_log('[ExamSync] ' . $examSyncWarning . ' for ' . $registrationId);
                        }
                    } catch (Exception $syncEx) {
                        $examSyncWarning = 'Exam Portal sync error: ' . $syncEx->getMessage();
                        error_log('[ExamSync] ' . $examSyncWarning);
                    }
                }

                echo json_encode([
                    'success' => true, 
                    'message' => 'Student converted to admission successfully',
                    'roll_no' => $rollNo,
                    'admission_id' => $admissionId,
                    'exam_sync_warning' => $examSyncWarning
                ]);
                exit;

            case 'convert_telephonic_to_admission':
                // Get telephonic inquiry data
                $stmt = $pdo->prepare("SELECT * FROM telephonic_inquiries WHERE id = ? AND atc_id = ?");
                $stmt->execute([$_POST['inquiry_id'], $atcId]);
                $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$inquiry) {
                    echo json_encode(['success' => false, 'message' => 'Telephonic inquiry not found']);
                    exit;
                }

                // Fetch ATC center_type for Registration ID
                $atcStmt2 = $pdo->prepare("SELECT center_type FROM atc_centers WHERE id = ?");
                $atcStmt2->execute([$atcId]);
                $centerType2 = $atcStmt2->fetchColumn() ?: 'Other';

                $courseFees    = floatval($_POST['course_fees']    ?? 0);
                $discountAmount = floatval($_POST['discount_amount'] ?? 0);
                $netPayable    = $courseFees - $discountAmount;

                $rollNo         = generateNextRollNoSimple($pdo, $atcId);
                $registrationId = generateRegistrationId($pdo, $centerType2);

                $cvtCourse2 = $_POST['course'] ?: $inquiry['interested_course'];
                $matType2 = $_POST['material_type'] ?? 'Without Material';
                $hoShareSnapshot2  = getHoShareForCourse($pdo, $cvtCourse2, $matType2);
                $dlcShareSnapshot2 = getDlcShareForCourse($pdo, $cvtCourse2, $matType2);

                $stmt = $pdo->prepare("
                    INSERT INTO admissions (
                            atc_id, roll_no, registration_id, first_name, middle_name, last_name,
                        mobile, course, uniform_size, admission_date,
                        course_fees, discount_amount, discount_reason, installments, net_payable,
                            fees_total, fees_pending, comment, referenced_by,
                        gender, dob, qualification, address, state, city, pin_code,
                        father_name, mother_name, material_type, material_language,
                        ho_share_snapshot, dlc_share_snapshot, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')
                ");

                $stmt->execute([
                    $atcId,
                    $rollNo,
                    $registrationId,
                    trim($_POST['cvt_first_name']    ?? $inquiry['first_name']),
                    $inquiry['middle_name'],
                    trim($_POST['cvt_last_name']     ?? $inquiry['last_name']),
                    trim($_POST['cvt_mobile']        ?? $inquiry['mobile']),
                    $cvtCourse2,
                    $_POST['uniform_size'] ?? null,
                    date('Y-m-d'),
                    $courseFees,
                    $discountAmount,
                    $_POST['discount_reason'] ?? null,
                    intval($_POST['installments'] ?? 1),
                    $netPayable,
                    $netPayable,
                    $netPayable,
                    $_POST['comment'] ?? $inquiry['comment'],
                    'Telephonic Inquiry',
                    $_POST['cvt_gender']        ?? null,
                    $_POST['cvt_dob']           ?? null,
                    trim($_POST['cvt_qualification'] ?? ''),
                    trim($_POST['cvt_address']  ?? ''),
                    trim($_POST['cvt_state']    ?? ''),
                    trim($_POST['cvt_city']     ?? ''),
                    trim($_POST['cvt_pin_code'] ?? ''),
                    trim($_POST['father_name']  ?? ''),
                    trim($_POST['mother_name']  ?? ''),
                    $matType2,
                    $_POST['material_language'] ?? 'English',
                    $hoShareSnapshot2,
                    $dlcShareSnapshot2,
                ]);

                $admissionId2 = (int)$pdo->lastInsertId();

                // Handle photo upload
                if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/../uploads/students/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $fileExt = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                    if (in_array($fileExt, ['jpg','jpeg','png'])) {
                        $fileName = $rollNo . '.' . $fileExt;
                        if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $fileName)) {
                            $pdo->prepare("UPDATE admissions SET photo = ? WHERE id = ? AND atc_id = ?")
                                ->execute(['uploads/students/' . $fileName, $admissionId2, $atcId]);
                        }
                    }
                }

                // Mark telephonic inquiry as Converted
                $pdo->prepare("UPDATE telephonic_inquiries SET status = 'Converted' WHERE id = ?")
                    ->execute([$_POST['inquiry_id']]);

                // Update student count
                $pdo->prepare("UPDATE atc_centers SET student_count = student_count + 1 WHERE id = ?")
                    ->execute([$atcId]);

                // ── Sync to Exam Portal (fire-and-forget) ──
                $examSyncWarning2 = null;
                if (function_exists('syncStudentToExamPortal') && defined('EXAM_API_TOKEN') && EXAM_API_TOKEN !== 'PASTE_YOUR_TOKEN_HERE') {
                    try {
                        $atcCodeStmt2 = $pdo->prepare("SELECT atc_code FROM atc_centers WHERE id = ?");
                        $atcCodeStmt2->execute([$atcId]);
                        $syncAtcCode2 = $atcCodeStmt2->fetchColumn() ?: 'ATC' . $atcId;
                        $syncName2 = trim(($_POST['cvt_first_name'] ?? $inquiry['first_name']) . ' ' . ($inquiry['middle_name'] ? $inquiry['middle_name'] . ' ' : '') . ($_POST['cvt_last_name'] ?? $inquiry['last_name']));
                        $syncResult2 = syncStudentToExamPortal($registrationId, $syncName2, $syncAtcCode2);
                        if (!$syncResult2['success']) {
                            $examSyncWarning2 = 'Exam Portal sync failed: ' . ($syncResult2['error'] ?? 'Unknown error');
                            error_log('[ExamSync] ' . $examSyncWarning2 . ' for ' . $registrationId);
                        }
                    } catch (Exception $syncEx2) {
                        $examSyncWarning2 = 'Exam Portal sync error: ' . $syncEx2->getMessage();
                        error_log('[ExamSync] ' . $examSyncWarning2);
                    }
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Telephonic inquiry converted to admission! Roll No: ' . $rollNo,
                    'roll_no' => $rollNo,
                    'admission_id' => $admissionId2,
                    'exam_sync_warning' => $examSyncWarning2
                ]);
                exit;
                
            case 'add':
                // Fetch ATC center_type for Registration ID
                $atcStmt3 = $pdo->prepare("SELECT center_type FROM atc_centers WHERE id = ?");
                $atcStmt3->execute([$atcId]);
                $centerType3 = $atcStmt3->fetchColumn() ?: 'Other';

                $courseFees     = floatval($_POST['course_fees'] ?? 0);
                $discountAmount = floatval($_POST['discount_amount'] ?? 0);
                $netPayable     = $courseFees - $discountAmount;

                $rollNo3         = generateNextRollNoSimple($pdo, $atcId);
                $registrationId3 = generateRegistrationId($pdo, $centerType3);

                $matType3 = $_POST['material_type'] ?? 'Without Material';
                $hoShareSnapshot3  = getHoShareForCourse($pdo, $_POST['course'] ?? '', $matType3);
                $dlcShareSnapshot3 = getDlcShareForCourse($pdo, $_POST['course'] ?? '', $matType3);

                $stmt = $pdo->prepare("
                    INSERT INTO admissions (
                        atc_id, roll_no, registration_id,
                        inquiry_id, first_name, middle_name, last_name, gender, dob,
                        category, father_name, mother_name,
                        qualification, course, uniform_size, address, state, pin_code, city,
                        mobile, phone, email, referenced_by, comment, admission_date, status,
                        course_fees, discount_amount, discount_reason, installments, net_payable,
                        fees_total, fees_pending,
                        material_type, material_language, ho_share_snapshot, dlc_share_snapshot
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([
                    $atcId,
                    $rollNo3,
                    $registrationId3,
                    $_POST['inquiry_id'] ?: null,
                    $_POST['first_name'],
                    $_POST['middle_name'],
                    $_POST['last_name'],
                    $_POST['gender'],
                    $_POST['dob'],
                    $_POST['category'] ?? 'General',
                    $_POST['father_name'] ?? '',
                    $_POST['mother_name'] ?? '',
                    $_POST['qualification'],
                    $_POST['course'],
                    $_POST['uniform_size'] ?? null,
                    $_POST['address'],
                    $_POST['state'] ?? '',
                    $_POST['pin_code'],
                    $_POST['city'],
                    $_POST['mobile'],
                    $_POST['phone'],
                    $_POST['email'],
                    $_POST['referenced_by'],
                    $_POST['comment'],
                    $_POST['admission_date'],
                    $_POST['status'],
                    $courseFees,
                    $discountAmount,
                    $_POST['discount_reason'] ?? null,
                    intval($_POST['installments'] ?? 1),
                    $netPayable,
                    $netPayable,
                    $netPayable,
                    $matType3,
                    $_POST['material_language'] ?? 'English',
                    $hoShareSnapshot3,
                    $dlcShareSnapshot3,
                ]);

                $admissionId3 = (int)$pdo->lastInsertId();

                // Update student count in ATC center
                $upd = $pdo->prepare("UPDATE atc_centers SET student_count = student_count + 1 WHERE id = ?");
                $upd->execute([$atcId]);

                // ── Sync to Exam Portal (fire-and-forget) ──
                $examSyncWarning3 = null;
                if (function_exists('syncStudentToExamPortal') && defined('EXAM_API_TOKEN') && EXAM_API_TOKEN !== 'PASTE_YOUR_TOKEN_HERE') {
                    try {
                        $atcCodeStmt3 = $pdo->prepare("SELECT atc_code FROM atc_centers WHERE id = ?");
                        $atcCodeStmt3->execute([$atcId]);
                        $syncAtcCode3 = $atcCodeStmt3->fetchColumn() ?: 'ATC' . $atcId;
                        $syncName3 = trim($_POST['first_name'] . ' ' . ($_POST['middle_name'] ? $_POST['middle_name'] . ' ' : '') . $_POST['last_name']);
                        $syncResult3 = syncStudentToExamPortal($registrationId3, $syncName3, $syncAtcCode3);
                        if (!$syncResult3['success']) {
                            $examSyncWarning3 = 'Exam Portal sync failed: ' . ($syncResult3['error'] ?? 'Unknown error');
                            error_log('[ExamSync] ' . $examSyncWarning3 . ' for ' . $registrationId3);
                        }
                    } catch (Exception $syncEx3) {
                        $examSyncWarning3 = 'Exam Portal sync error: ' . $syncEx3->getMessage();
                        error_log('[ExamSync] ' . $examSyncWarning3);
                    }
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Admission added successfully! Roll No: ' . $rollNo3 . ' | Reg ID: ' . $registrationId3,
                    'admission_id' => $admissionId3,
                    'roll_no' => $rollNo3,
                    'registration_id' => $registrationId3,
                    'exam_sync_warning' => $examSyncWarning3
                ]);
                exit;
                
            case 'edit':
                $courseFees2     = floatval($_POST['course_fees'] ?? 0);
                $discountAmount2 = floatval($_POST['discount_amount'] ?? 0);
                $netPayable2     = $courseFees2 - $discountAmount2;
                $stmt = $pdo->prepare("
                    UPDATE admissions
                    SET first_name=?, middle_name=?, last_name=?, gender=?, dob=?,
                        category=?, father_name=?, mother_name=?,
                        qualification=?, course=?, uniform_size=?, address=?, state=?, pin_code=?,
                        city=?, mobile=?, phone=?, email=?, referenced_by=?,
                        comment=?, admission_date=?, status=?,
                        course_fees=?, discount_amount=?, discount_reason=?, installments=?,
                        net_payable=?, fees_total=?,
                        material_type=?, material_language=?
                    WHERE id=? AND atc_id=?
                ");
                $stmt->execute([
                    $_POST['first_name'],
                    $_POST['middle_name'],
                    $_POST['last_name'],
                    $_POST['gender'],
                    $_POST['dob'],
                    $_POST['category'] ?? 'General',
                    $_POST['father_name'] ?? '',
                    $_POST['mother_name'] ?? '',
                    $_POST['qualification'],
                    $_POST['course'],
                    $_POST['uniform_size'] ?? null,
                    $_POST['address'],
                    $_POST['state'] ?? '',
                    $_POST['pin_code'],
                    $_POST['city'],
                    $_POST['mobile'],
                    $_POST['phone'],
                    $_POST['email'],
                    $_POST['referenced_by'],
                    $_POST['comment'],
                    $_POST['admission_date'],
                    $_POST['status'],
                    $courseFees2,
                    $discountAmount2,
                    $_POST['discount_reason'] ?? null,
                    intval($_POST['installments'] ?? 1),
                    $netPayable2,
                    $netPayable2,
                    $_POST['material_type'] ?? 'Without Material',
                    $_POST['material_language'] ?? 'English',
                    $_POST['id'],
                    $atcId
                ]);
                echo json_encode(['success' => true, 'message' => 'Admission updated successfully']);
                exit;
                
            case 'delete':
                $stmt = $pdo->prepare("DELETE FROM admissions WHERE id = ? AND atc_id = ?");
                $stmt->execute([$_POST['id'], $atcId]);
                
                // Update student count in ATC center
                $stmt = $pdo->prepare("UPDATE atc_centers SET student_count = student_count - 1 WHERE id = ?");
                $stmt->execute([$atcId]);
                
                echo json_encode(['success' => true, 'message' => 'Admission deleted successfully']);
                exit;
                
            case 'get':
                $stmt = $pdo->prepare("SELECT * FROM admissions WHERE id = ? AND atc_id = ?");
                $stmt->execute([$_POST['id'], $atcId]);
                $admission = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $admission]);
                exit;
                
            case 'get_inquiries':
                // Get converted inquiries for dropdown
                $stmt = $pdo->prepare("
                    SELECT id, first_name, middle_name, last_name, mobile, interested_course 
                    FROM inquiries 
                    WHERE atc_id = ? AND status = 'Converted'
                    ORDER BY first_name
                ");
                $stmt->execute([$atcId]);
                $inquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $inquiries]);
                exit;
                
            case 'load_inquiry':
                // Load inquiry details to pre-fill form
                $stmt = $pdo->prepare("SELECT * FROM inquiries WHERE id = ? AND atc_id = ?");
                $stmt->execute([$_POST['inquiry_id'], $atcId]);
                $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $inquiry]);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Fetch admissions for this ATC (paginated)
$statusFilter = $_GET['status'] ?? 'all';
$pagerParams  = paginationParams(25);

$admWhere  = [];
$admParams = [];
if ($atcId) {
    $admWhere[]  = 'atc_id = ?';
    $admParams[] = $atcId;
}
if ($statusFilter !== 'all') {
    $admWhere[]  = 'status = ?';
    $admParams[] = $statusFilter;
}
$admWhereSql = $admWhere ? (' WHERE ' . implode(' AND ', $admWhere)) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM admissions' . $admWhereSql);
$countStmt->execute($admParams);
$pager = paginationMeta((int)$countStmt->fetchColumn(), $pagerParams);

$sql = 'SELECT * FROM admissions' . $admWhereSql
     . ' ORDER BY admission_date DESC'
     . " LIMIT {$pager['per_page']} OFFSET {$pager['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($admParams);
$admissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get status counts for all admissions
$statusCounts = [
    'all' => 0,
    'Active' => 0,
    'Inactive' => 0
];

if ($atcId) {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM admissions WHERE atc_id = ? GROUP BY status");
    $stmt->execute([$atcId]);
} else {
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM admissions GROUP BY status");
}

$counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($counts as $count) {
    $statusCounts[$count['status']] = $count['count'];
    $statusCounts['all'] += $count['count'];
}

// Fetch pending (non-converted) inquiries for this ATC
if ($atcId) {
    $inqStmt = $pdo->prepare("SELECT *, 'walkin' as _source FROM inquiries WHERE atc_id = ? AND status != 'Converted' ORDER BY created_at DESC");
    $inqStmt->execute([$atcId]);
} else {
    $inqStmt = $pdo->query("SELECT *, 'walkin' as _source FROM inquiries WHERE status != 'Converted' ORDER BY created_at DESC");
}
$pendingInquiries = $inqStmt->fetchAll(PDO::FETCH_ASSOC);

// Also fetch pending telephonic inquiries and merge them
if ($atcId) {
    $telStmt = $pdo->prepare("SELECT id, atc_id, first_name, middle_name, last_name, mobile, interested_course as interested_course, next_inform_date, status, created_at, '' as email, '' as inquiry_type, '' as qualification, 'telephonic' as _source FROM telephonic_inquiries WHERE atc_id = ? AND status != 'Converted' ORDER BY created_at DESC");
    $telStmt->execute([$atcId]);
} else {
    $telStmt = $pdo->query("SELECT id, atc_id, first_name, middle_name, last_name, mobile, interested_course as interested_course, next_inform_date, status, created_at, '' as email, '' as inquiry_type, '' as qualification, 'telephonic' as _source FROM telephonic_inquiries WHERE status != 'Converted' ORDER BY created_at DESC");
}
$telInquiries = $telStmt->fetchAll(PDO::FETCH_ASSOC);

// Merge and sort by created_at desc
$pendingInquiries = array_merge($pendingInquiries, $telInquiries);
usort($pendingInquiries, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));

// Fetch Active master courses for this ATC (only courses where either material fee > 0)
$coursesStmt = $pdo->prepare("
    SELECT c.id, c.course_name, c.course_type, c.duration,
           c.material_type, c.material_language,
           c.ho_share, c.ho_share_with_material, c.ho_share_without_material,
           acf.final_fee AS fees,
           acf.fee_with_material,
           acf.fee_without_material
    FROM   courses c
    INNER JOIN atc_course_fees acf ON acf.course_id = c.id AND acf.atc_id = ?
    WHERE  c.status = 'Active'
      AND  (COALESCE(acf.fee_with_material, 0) > 0
         OR COALESCE(acf.fee_without_material, 0) > 0
         OR COALESCE(acf.final_fee, 0) > 0)
    ORDER BY c.course_name ASC
");
$coursesStmt->execute([$atcId]);
$atcCourses = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);

// Expand into With / Without Material dropdown options
$courseSelectOptions = [];
foreach ($atcCourses as $ac) {
    foreach (buildCourseMaterialOptions($ac) as $opt) {
        $courseSelectOptions[] = $opt;
    }
}

function inqFullName(array $r): string {
    return trim($r['first_name'].' '.($r['middle_name'] ? $r['middle_name'].' ' : '').$r['last_name']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admissions — ATC Center | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📚</text></svg>">
    <style>
        :root {
            --font: 'Sora', sans-serif;
            --mono: 'JetBrains Mono', monospace;
        }
        
        /* Page Content */
        .page-content {
            padding: 1.75rem 2rem;
            width: 100%;
            box-sizing: border-box;
        }

        
        /* ── Pending Inquiries Panel ── */
        .adm-panel {
            background: #ffffff;
            border: 1.5px solid #e5e7eb;
            border-radius: 18px;
            margin-bottom: 2rem;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,.06);
            animation: fadeInUp .4s ease both;
        }
        .adm-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: .75rem;
            padding: 1.25rem 1.5rem;
            background: linear-gradient(135deg, #eef1fd 0%, #f3e8ff 100%);
            border-bottom: 1.5px solid #e5e7eb;
        }
        .adm-panel-title {
            display: flex;
            align-items: center;
            gap: .625rem;
            font-size: 1rem;
            font-weight: 800;
            color: #1f2937;
            letter-spacing: -0.02em;
        }
        .adm-panel-title svg { 
            width: 20px;
            height: 20px;
            stroke: #4361ee;
            flex-shrink: 0;
        }
        .adm-panel-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 26px;
            padding: .2rem .6rem;
            border-radius: 999px;
            font-size: .75rem;
            font-weight: 800;
            background: linear-gradient(135deg, #4361ee, #3730a3);
            color: #fff;
            box-shadow: 0 2px 8px rgba(67, 97, 238, .3);
        }
        .adm-panel-hint {
            font-size: .8125rem;
            color: #6b7280;
            font-weight: 500;
        }
        .adm-panel-hint strong {
            color: #4361ee;
            font-weight: 700;
        }
        .adm-panel-empty {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .875rem;
            padding: 3rem 1.5rem;
            color: #9ca3af;
            font-size: .9375rem;
            font-weight: 500;
            text-align: center;
        }
        .adm-panel-empty svg { 
            width: 24px;
            height: 24px;
            flex-shrink: 0;
            stroke: #d1d5db;
        }
        .adm-inq-search {
            display: flex;
            align-items: center;
            gap: .875rem;
            padding: 1rem 1.5rem;
            border-bottom: 1.5px solid #e5e7eb;
            background: #fafbfc;
        }
        .adm-inq-search svg { 
            width: 18px;
            height: 18px;
            stroke: #9ca3af;
            flex-shrink: 0;
        }
        .adm-inq-search input {
            flex: 1;
            border: none;
            outline: none;
            font-size: .875rem;
            font-weight: 500;
            background: transparent;
            color: #1f2937;
            font-family: var(--font);
        }
        .adm-inq-search input::placeholder {
            color: #9ca3af;
        }
        .adm-convert-btn {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .55rem 1.125rem;
            border-radius: 10px;
            font-size: .8125rem;
            font-weight: 800;
            color: #fff;
            background: linear-gradient(135deg, #10b981, #059669);
            border: none;
            cursor: pointer;
            transition: all .2s ease;
            box-shadow: 0 2px 8px rgba(16,185,129,.25);
            white-space: nowrap;
            letter-spacing: -0.01em;
        }
        .adm-convert-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(16,185,129,.35);
        }
        .adm-convert-btn:active { 
            transform: translateY(0); 
        }
        .adm-convert-btn svg { 
            width: 15px;
            height: 15px;
        }
        
        /* Status Tabs */
        .status-tabs {
            display: flex;
            gap: .5rem;
            margin-bottom: 1.5rem;
            background: #ffffff;
            border: 1.5px solid #e5e7eb;
            border-radius: 14px;
            padding: .5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
            width: fit-content;
        }
        .status-tab {
            display: flex;
            align-items: center;
            gap: .625rem;
            padding: .75rem 1.25rem;
            border-radius: 10px;
            font-size: .875rem;
            font-weight: 700;
            color: #6b7280;
            text-decoration: none;
            transition: all .2s ease;
            white-space: nowrap;
            letter-spacing: -0.01em;
        }
        .status-tab:hover {
            background: #f9fafb;
            color: #374151;
        }
        .status-tab.active {
            background: linear-gradient(135deg, #4361ee, #3730a3);
            color: #fff;
            box-shadow: 0 4px 12px rgba(67, 97, 238, .25);
        }
        .tab-count {
            padding: .2rem .6rem;
            border-radius: 999px;
            font-size: .75rem;
            font-weight: 800;
            background: rgba(255,255,255,.2);
            min-width: 24px;
            text-align: center;
        }
        .status-tab:not(.active) .tab-count {
            background: #e5e7eb;
            color: #6b7280;
        }
        .badge-active {
            background: #d1fae5 !important;
            color: #065f46 !important;
        }
        .badge-inactive {
            background: #fee2e2 !important;
            color: #991b1b !important;
        }
        .status-tab.active .badge-active,
        .status-tab.active .badge-inactive {
            background: rgba(255,255,255,.25) !important;
            color: #fff !important;
        }
        
        /* Page Toolbar */
        .page-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.25rem;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .page-toolbar h3 {
            display: flex;
            align-items: center;
            gap: .625rem;
            font-size: 1.125rem;
            font-weight: 800;
            color: #1f2937;
            margin: 0;
            letter-spacing: -0.02em;
        }
        .badge-count {
            padding: .25rem .75rem;
            border-radius: 999px;
            font-size: .8125rem;
            font-weight: 800;
            background: #e5e7eb;
            color: #6b7280;
        }
        .search-bar {
            position: relative;
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: 0 1rem;
            background: #ffffff;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            height: 44px;
            min-width: 320px;
            transition: all .2s ease;
        }
        .search-bar:focus-within {
            border-color: #4361ee;
            box-shadow: 0 0 0 4px rgba(67, 97, 238, .1);
        }
        .search-bar svg {
            width: 18px;
            height: 18px;
            stroke: #9ca3af;
            flex-shrink: 0;
        }
        .search-bar input {
            flex: 1;
            border: none;
            outline: none;
            font-size: .875rem;
            font-weight: 500;
            background: transparent;
            color: #1f2937;
            font-family: var(--font);
        }
        .search-bar input::placeholder {
            color: #9ca3af;
        }
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: 0 1.5rem;
            height: 44px;
            border-radius: 10px;
            font-size: .875rem;
            font-weight: 800;
            color: #fff;
            background: linear-gradient(135deg, #4361ee, #3730a3);
            border: none;
            cursor: pointer;
            transition: all .2s ease;
            box-shadow: 0 2px 8px rgba(67, 97, 238, .25);
            white-space: nowrap;
            letter-spacing: -0.01em;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(67, 97, 238, .35);
        }
        .btn-primary:active {
            transform: translateY(0);
        }
        
        /* Table Card */
        .table-card {
            background: #ffffff;
            border: 1.5px solid #e5e7eb;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
            margin-bottom: 1.5rem;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .875rem;
        }
        .data-table thead {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border-bottom: 1.5px solid #e5e7eb;
        }
        .data-table thead th {
            padding: 1rem 1.25rem;
            text-align: left;
            font-size: .75rem;
            font-weight: 800;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .08em;
            white-space: nowrap;
        }
        .data-table tbody tr {
            border-bottom: 1px solid #f3f4f6;
            transition: all .25s ease;
        }
        .data-table tbody tr:last-child {
            border-bottom: none;
        }
        .data-table tbody tr:hover {
            background: linear-gradient(135deg, #fafbfc, #f8f9fa);
            box-shadow: 0 2px 8px rgba(0,0,0,.04);
            transform: translateX(2px);
        }
        .data-table tbody td {
            padding: 1rem 1.25rem;
            vertical-align: middle;
        }
        
        /* Cell badge enhancements */
        .cell-badge {
            display: inline-flex;
            align-items: center;
            padding: .35rem .875rem;
            border-radius: 999px;
            font-size: .75rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            box-shadow: 0 2px 6px rgba(0,0,0,.08);
            transition: all .2s ease;
        }
        .cell-badge:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(0,0,0,.12);
        }
        .cell-badge.status-active {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
            border: 1px solid #6ee7b7;
        }
        .cell-badge.status-inactive {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        /* ── Enhanced Action Buttons ── */
        .cell-actions {
            display: flex;
            gap: .5rem;
            align-items: center;
            justify-content: flex-end;
        }
        .btn-icon {
            position: relative;
            width: 38px;
            height: 38px;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            background: #ffffff;
            color: #6b7280;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all .25s cubic-bezier(0.4, 0, 0.2, 1);
            flex-shrink: 0;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }
        .btn-icon svg {
            width: 16px;
            height: 16px;
            stroke: currentColor;
            transition: all .25s ease;
        }
        /* View button - Blue theme */
        .btn-icon:not(.danger):nth-child(1):hover {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            border-color: #3b82f6;
            color: #fff;
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 8px 16px rgba(59, 130, 246, .3), 0 0 0 4px rgba(59, 130, 246, .1);
        }
        /* Edit button - Emerald theme */
        .btn-icon:not(.danger):nth-child(2):hover {
            background: linear-gradient(135deg, #10b981, #059669);
            border-color: #10b981;
            color: #fff;
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 8px 16px rgba(16, 185, 129, .3), 0 0 0 4px rgba(16, 185, 129, .1);
        }
        /* Delete button - Rose theme */
        .btn-icon.danger:hover {
            background: linear-gradient(135deg, #f43f5e, #e11d48);
            border-color: #f43f5e;
            color: #fff;
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 8px 16px rgba(244, 63, 94, .3), 0 0 0 4px rgba(244, 63, 94, .1);
        }
        .btn-icon:active {
            transform: translateY(0) scale(0.98);
        }
        /* Tooltip styling - simple and clean */
        .btn-icon {
            position: relative;
        }
        .btn-icon:hover::before {
            content: attr(title);
            position: absolute;
            bottom: calc(100% + 10px);
            left: 50%;
            transform: translateX(-50%);
            padding: 6px 12px;
            background: rgba(31, 41, 55, 0.95);
            color: #fff;
            font-size: 11px;
            font-weight: 500;
            border-radius: 6px;
            white-space: nowrap;
            z-index: 1000;
            box-shadow: 0 2px 8px rgba(0,0,0,.15);
            animation: tooltipFadeIn 0.2s ease;
        }
        @keyframes tooltipFadeIn {
            from {
                opacity: 0;
                transform: translateX(-50%) translateY(-4px);
            }
            to {
                opacity: 1;
                transform: translateX(-50%) translateY(0);
            }
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .page-content {
                padding: 1.25rem 1rem;
            }
            .page-toolbar {
                flex-direction: column;
                align-items: flex-start;
            }
            .search-bar {
                min-width: 100%;
                width: 100%;
            }
            .status-tabs {
                overflow-x: auto;
                width: 100%;
                max-width: 100%;
            }
            .adm-panel-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .btn-icon {
                width: 36px;
                height: 36px;
            }
            .btn-icon svg {
                width: 15px;
                height: 15px;
            }
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
                    <h2>Student Admissions</h2>
                    <p>Manage student admissions and enrollments</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">

            <!-- ═══════════ PENDING INQUIRIES PANEL ═══════════ -->
            <div class="adm-panel" id="inquiriesPanel">
                <div class="adm-panel-header">
                    <div class="adm-panel-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                        Pending Inquiries
                        <span class="adm-panel-badge"><?= count($pendingInquiries) ?></span>
                    </div>
                    <div class="adm-panel-hint">Click <strong>Convert</strong> on any inquiry to enroll the student as an admission.</div>
                </div>

                <?php if (empty($pendingInquiries)): ?>
                    <div class="adm-panel-empty">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                        <span>No pending inquiries — all inquiries have been converted!</span>
                    </div>
                <?php else: ?>
                    <div class="adm-inq-search">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" id="inqSearchInput" placeholder="Filter inquiries by name, mobile or course…" oninput="filterInquiries()">
                    </div>
                    <div class="table-card" style="margin:0;border-radius:0 0 var(--radius-xl) var(--radius-xl);">
                        <table class="data-table" id="inqTable">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Contact</th>
                                    <th>Course Interested</th>
                                    <th>Type</th>
                                    <th>Follow-up</th>
                                    <th>Status</th>
                                    <th style="text-align:center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($pendingInquiries as $inq):
                                $inqName    = inqFullName($inq);
                                $inqInitial = strtoupper(mb_substr($inq['first_name'], 0, 1));
                                $statusColors = ['New'=>'#3b82f6','Contacted'=>'#f59e0b','Closed'=>'#6b7280'];
                                $sColor = $statusColors[$inq['status']] ?? '#6b7280';
                                $isTelephonic = ($inq['_source'] === 'telephonic');
                                $typeLabel  = $isTelephonic ? 'Telephonic' : ($inq['inquiry_type'] ?: 'Walk-in');
                                $typeBg     = $isTelephonic ? '#fef3c7' : '#e0f2fe';
                                $typeColor  = $isTelephonic ? '#92400e' : '#0369a1';
                            ?>
                                <tr data-inq-name="<?= strtolower(sanitize($inqName)) ?>" data-inq-mobile="<?= sanitize($inq['mobile']) ?>" data-inq-course="<?= strtolower(sanitize($inq['interested_course'])) ?>">
                                    <td>
                                        <div style="display:flex;align-items:center;gap:.75rem">
                                            <div style="width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,<?= $isTelephonic ? '#f59e0b,#d97706' : '#4361ee,#7c3aed' ?>);display:flex;align-items:center;justify-content:center;font-size:.9rem;font-weight:800;color:#fff;flex-shrink:0"><?= $inqInitial ?></div>
                                            <div>
                                                <div style="font-weight:700;font-size:.875rem;color:var(--text-primary)"><?= sanitize($inqName) ?></div>
                                                <div style="font-size:.75rem;color:var(--text-muted)"><?= sanitize($inq['qualification'] ?: '—') ?> · <?= date('d M Y', strtotime($inq['created_at'])) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight:600;font-size:.875rem"><?= sanitize($inq['mobile']) ?></div>
                                        <?php if ($inq['email']): ?><div style="font-size:.75rem;color:var(--text-muted)"><?= sanitize($inq['email']) ?></div><?php endif; ?>
                                    </td>
                                    <td style="font-weight:600;font-size:.875rem"><?= sanitize($inq['interested_course']) ?></td>
                                    <td>
                                        <span style="display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .75rem;border-radius:999px;font-size:.75rem;font-weight:700;background:<?= $typeBg ?>;color:<?= $typeColor ?>">
                                            <?= $typeLabel ?>
                                        </span>
                                    </td>
                                    <td style="font-size:.82rem;color:var(--text-secondary)">
                                        <?= $inq['next_inform_date'] ? date('d M Y', strtotime($inq['next_inform_date'])) : '—' ?>
                                    </td>
                                    <td>
                                        <span style="display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .75rem;border-radius:999px;font-size:.75rem;font-weight:700;background:<?= $sColor ?>22;color:<?= $sColor ?>">
                                            <span style="width:6px;height:6px;border-radius:50%;background:<?= $sColor ?>;flex-shrink:0"></span>
                                            <?= sanitize($inq['status']) ?>
                                        </span>
                                    </td>
                                    <td style="text-align:center">
                                        <a
                                            class="adm-convert-btn"
                                            href="convert_admission_page.php?inquiry_id=<?= $inq['id'] ?>&source=<?= $inq['_source'] ?>"
                                            title="Convert to Admission">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
                                            Convert
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Status Filter Tabs (for Admissions) -->
            <div class="status-tabs" style="margin-top:2rem">
                <a href="?status=all" class="status-tab <?= $statusFilter === 'all' ? 'active' : '' ?>">
                    <span class="tab-label">All Students</span>
                    <span class="tab-count"><?= $statusCounts['all'] ?></span>
                </a>
                <a href="?status=Active" class="status-tab <?= $statusFilter === 'Active' ? 'active' : '' ?>">
                    <span class="tab-label">Active</span>
                    <span class="tab-count badge-active"><?= $statusCounts['Active'] ?></span>
                </a>
                <a href="?status=Inactive" class="status-tab <?= $statusFilter === 'Inactive' ? 'active' : '' ?>">
                    <span class="tab-label">Inactive</span>
                    <span class="tab-count badge-inactive"><?= $statusCounts['Inactive'] ?></span>
                </a>
            </div>
            
            <div class="page-toolbar">
                <h3>
                    <?= $statusFilter === 'all' ? 'All' : ucfirst($statusFilter) ?> Students
                    <span class="badge-count"><?= count($admissions) ?></span>
                </h3>
                <div style="display: flex; gap: 0.75rem; align-items: center;">
                    <div class="search-bar">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" id="searchInput" placeholder="Search students or inquiries..." onkeyup="handleSearch(event)">
                    </div>
                    <button class="btn-primary" onclick="performSearch()" style="padding: 0 1.5rem;">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px;"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        Search
                    </button>
                </div>
            </div>

            <!-- Search Results -->
            <div id="searchResults" style="display: none; margin-bottom: 1.5rem;">
                <div class="table-card">
                    <div style="padding: 1rem; border-bottom: 1px solid var(--border-color); background: linear-gradient(135deg, #f3f4f6, #e5e7eb);">
                        <h4 style="margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 20px; height: 20px;"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                            Search Results
                            <button onclick="closeSearchResults()" style="margin-left: auto; background: none; border: none; cursor: pointer; color: var(--text-secondary);" title="Close">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 20px; height: 20px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </h4>
                    </div>
                    <div id="searchResultsContent" style="padding: 1rem;">
                        <!-- Results will be populated here -->
                    </div>
                </div>
            </div>

            <div class="table-card">
                <table class="data-table" id="admissionTable">
                    <thead>
                        <tr>
                            <th>Reg ID · Roll No</th>
                            <th>Student Name</th>
                            <th>Contact</th>
                            <th>Course</th>
                            <th>Admission Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($admissions)): ?>
                            <tr>
                                <td colspan="7" class="table-empty">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
                                    <p>No admissions found. Add your first student admission to get started.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($admissions as $admission): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($admission['registration_id'])): ?>
                                            <div style="font-family:monospace;font-size:.82rem;font-weight:700;color:#4361ee;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:.25rem .6rem;display:inline-block;letter-spacing:.03em;">
                                                <?= htmlspecialchars($admission['registration_id']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="cell-sub" style="margin-top:4px;">Roll No: <strong><?= htmlspecialchars($admission['roll_no'] ?? '—') ?></strong></div>
                                    </td>
                                    <td>
                                        <div class="cell-name">
                                            <?= htmlspecialchars($admission['first_name'] . ' ' . ($admission['middle_name'] ? $admission['middle_name'] . ' ' : '') . $admission['last_name']) ?>
                                        </div>
                                        <div class="cell-sub"><?= htmlspecialchars($admission['city']) ?></div>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($admission['mobile']) ?></div>
                                        <?php if ($admission['email']): ?>
                                            <div class="cell-sub"><?= htmlspecialchars($admission['email']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($admission['course']) ?></td>
                                    <td><?= date('d M Y', strtotime($admission['admission_date'])) ?></td>
                                    <td>
                                        <span class="cell-badge status-<?= strtolower($admission['status']) ?>">
                                            <?= $admission['status'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="cell-actions">
                                            <a class="btn-icon" href="view_admission_page.php?admission_id=<?= $admission['id'] ?>" title="View Details" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            </a>
                                            <a class="btn-icon" href="admission_form_pdf.php?admission_id=<?= $admission['id'] ?>" target="_blank" title="Download Admission Form" style="display:inline-flex;align-items:center;justify-content:center;color:#e91e63;border-color:#fce4ec;background:#fff0f5;text-decoration:none;">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><polyline points="9 15 12 18 15 15"/></svg>
                                            </a>
                                            <button class="btn-icon danger" onclick="deleteAdmission(<?= $admission['id'] ?>, '<?= htmlspecialchars($admission['first_name'] . ' ' . $admission['last_name'], ENT_QUOTES) ?>')" title="Delete">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= renderPagination($pager, 'admissions') ?>
        </div>
    </main>
</div>

<!-- ═══════════ ADD ADMISSION MODAL ═══════════ -->
<div class="modal-overlay" id="admissionModal">
    <div class="adm-modal-card">
        <!-- Header -->
        <div class="adm-modal-header">
            <div class="adm-modal-header-bg"></div>
            <div class="adm-modal-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
            </div>
            <div class="adm-modal-title-wrap">
                <div id="modalTitle" class="adm-modal-title">Add New Admission</div>
                <div class="adm-modal-subtitle">Fill in all required fields to register the student</div>
            </div>
            <button type="button" onclick="closeModal()" class="adm-modal-close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <form id="admissionForm" class="adm-modal-form" novalidate enctype="multipart/form-data">
            <input type="hidden" id="admissionId" name="id">
            <input type="hidden" id="formAction" name="action" value="add">
            <input type="hidden" id="inquiry_id" name="inquiry_id">

            <div class="adm-modal-body">

                <!-- Load from Inquiry -->
                <div class="adm-section">
                    <div class="adm-section-header">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                        <span>Load from Inquiry</span>
                    </div>
                    <div class="adm-section-body">
                        <div class="adm-form-row">
                            <div class="adm-form-group full-width">
                                <label class="adm-label">Select Converted Inquiry</label>
                                <select class="adm-input" id="load_inquiry" onchange="loadInquiryData(this.value)">
                                    <option value="">— Or enter details manually below —</option>
                                </select>
                                <small class="adm-hint">Selecting an inquiry auto-fills student details below</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Personal Information -->
                <div class="adm-section">
                    <div class="adm-section-header">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span>Personal Information</span>
                    </div>
                    <div class="adm-section-body">
                        <div class="adm-form-row">
                            <div class="adm-form-group">
                                <label class="adm-label">First Name <span class="required">*</span></label>
                                <input type="text" class="adm-input" id="first_name" name="first_name" required maxlength="60" placeholder="Enter first name">
                            </div>
                            <div class="adm-form-group">
                                <label class="adm-label">Middle Name</label>
                                <input type="text" class="adm-input" id="middle_name" name="middle_name" maxlength="60" placeholder="Optional">
                            </div>
                        </div>
                        <div class="adm-form-row">
                            <div class="adm-form-group">
                                <label class="adm-label">Last Name <span class="required">*</span></label>
                                <input type="text" class="adm-input" id="last_name" name="last_name" required maxlength="60" placeholder="Enter last name">
                            </div>
                            <div class="adm-form-group">
                                <label class="adm-label">Gender <span class="required">*</span></label>
                                <select class="adm-input" id="gender" name="gender" required>
                                    <option value="">Select Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="adm-form-row">
                            <div class="adm-form-group">
                                <label class="adm-label">Date of Birth <span class="required">*</span></label>
                                <input type="date" class="adm-input" id="dob" name="dob" required>
                            </div>
                            <div class="adm-form-group">
                                <label class="adm-label">Category</label>
                                <select class="adm-input" id="category" name="category">
                                    <option value="General">General</option>
                                    <option value="OBC">OBC</option>
                                    <option value="SC">SC</option>
                                    <option value="ST">ST</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="adm-form-row">
                            <div class="adm-form-group">
                                <label class="adm-label">Qualification <span class="required">*</span></label>
                                <input type="text" class="adm-input" id="qualification" name="qualification" required maxlength="80" placeholder="e.g., 12th Pass">
                            </div>
                            <div class="adm-form-group">
                                <label class="adm-label">Student Photo</label>
                                <div class="adm-photo-upload" onclick="document.getElementById('student_photo').click()" style="height:auto;padding:.6rem;">
                                    <img id="photoPreview" src="" alt="" style="max-height:60px;display:none;border-radius:6px;">
                                    <div id="photoPlaceholder">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:20px;height:20px;"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                        <span style="font-size:.78rem">Click to upload</span>
                                    </div>
                                </div>
                                <input type="file" id="student_photo" name="photo" accept="image/*" style="display:none;" onchange="previewPhoto(this)">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Guardian Details -->
                <div class="adm-section">
                    <div class="adm-section-header">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <span>Guardian Details</span>
                    </div>
                    <div class="adm-section-body">
                        <div class="adm-form-row">
                            <div class="adm-form-group">
                                <label class="adm-label">Father's Name</label>
                                <input type="text" class="adm-input" id="father_name" name="father_name" maxlength="100" placeholder="Father's full name">
                            </div>
                            <div class="adm-form-group">
                                <label class="adm-label">Mother's Name</label>
                                <input type="text" class="adm-input" id="mother_name" name="mother_name" maxlength="100" placeholder="Mother's full name">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="adm-section">
                    <div class="adm-section-header">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        <span>Contact Information</span>
                    </div>
                    <div class="adm-section-body">
                        <div class="adm-form-row">
                            <div class="adm-form-group">
                                <label class="adm-label">Mobile Number <span class="required">*</span></label>
                                <input type="tel" class="adm-input" id="mobile" name="mobile" required pattern="[0-9]{10}" maxlength="10" placeholder="9876543210">
                            </div>
                            <div class="adm-form-group">
                                <label class="adm-label">Alternate Phone</label>
                                <input type="tel" class="adm-input" id="phone" name="phone" maxlength="15" placeholder="Optional">
                            </div>
                        </div>
                        <div class="adm-form-row">
                            <div class="adm-form-group full-width">
                                <label class="adm-label">Email Address</label>
                                <input type="email" class="adm-input" id="email" name="email" maxlength="100" placeholder="student@example.com">
                            </div>
                        </div>
                        <div class="adm-form-row">
                            <div class="adm-form-group full-width">
                                <label class="adm-label">Address <span class="required">*</span></label>
                                <textarea class="adm-input" id="address" name="address" required rows="2" placeholder="Complete residential address"></textarea>
                            </div>
                        </div>
                        <div class="adm-form-row">
                            <div class="adm-form-group">
                                <label class="adm-label">City <span class="required">*</span></label>
                                <input type="text" class="adm-input" id="city" name="city" required maxlength="80" placeholder="City name">
                            </div>
                            <div class="adm-form-group">
                                <label class="adm-label">State</label>
                                <input type="text" class="adm-input" id="state" name="state" maxlength="80" placeholder="e.g., Maharashtra">
                            </div>
                        </div>
                        <div class="adm-form-row">
                            <div class="adm-form-group">
                                <label class="adm-label">PIN Code <span class="required">*</span></label>
                                <input type="text" class="adm-input" id="pin_code" name="pin_code" required pattern="[0-9]{6}" maxlength="6" placeholder="411001">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Course & Admission Details -->
                <div class="adm-section">
                    <div class="adm-section-header">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                        <span>Course & Admission Details</span>
                    </div>
                    <div class="adm-section-body">
                        <div class="adm-form-row">
                            <div class="adm-form-group full-width">
                                <label class="adm-label">Course <span class="required">*</span></label>
                                <select class="adm-input" id="course" name="course" required onchange="toggleUniformSize(); updateCourseFee(this);">
                                    <option value="">— Select Course —</option>
                                    <?php foreach ($courseSelectOptions as $opt): ?>
                                        <option value="<?= htmlspecialchars($opt['course_name']) ?>"
                                                data-fee="<?= floatval($opt['fee']) ?>"
                                                data-material="<?= htmlspecialchars($opt['material_type']) ?>"
                                                data-language="<?= htmlspecialchars($opt['language'] ?? 'English') ?>">
                                            <?= htmlspecialchars($opt['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div style="font-size:.72rem;color:#6b7280;margin-top:.3rem">Only options with HO share &amp; your fee set appear (W / WO separately)</div>
                            </div>
                        </div>
                        <div class="adm-form-row">
                            <div class="adm-form-group" id="uniformSizeField" style="display:none;">
                                <label class="adm-label">T-Shirt Size <span class="required">*</span></label>
                                <select class="adm-input" id="uniform_size" name="uniform_size">
                                    <option value="">-- Select T-Shirt Size --</option>
                                    <option value="36">36</option>
                                    <option value="38">38</option>
                                    <option value="40">40</option>
                                    <option value="42">42</option>
                                    <option value="44">44</option>
                                    <option value="46">46</option>
                                </select>
                            </div>
                            <div class="adm-form-group">
                                <label class="adm-label">Admission Date <span class="required">*</span></label>
                                <input type="date" class="adm-input" id="admission_date" name="admission_date" required>
                            </div>
                        </div>
                        <div class="adm-form-row">
                            <div class="adm-form-group">
                                <label class="adm-label">Status</label>
                                <select class="adm-input" id="status" name="status">
                                    <option value="Active">🟢 Active</option>
                                    <option value="Inactive">🔴 Inactive</option>
                                </select>
                            </div>
                            <div class="adm-form-group">
                                <label class="adm-label">Referenced By</label>
                                <input type="text" class="adm-input" id="referenced_by" name="referenced_by" maxlength="100" placeholder="e.g., Friend, Advertisement">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Fee Details -->
                <div class="adm-section">
                    <div class="adm-section-header">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        <span>Fee Details</span>
                    </div>
                    <div class="adm-section-body">
                        <div class="adm-form-row">
                            <div class="adm-form-group">
                                <label class="adm-label">Course Fees (&#8377;) <span class="required">*</span></label>
                                <input type="number" class="adm-input" id="course_fees" name="course_fees" min="0" step="0.01" placeholder="0.00" oninput="recalcNetPayable()">
                            </div>
                            <div class="adm-form-group">
                                <label class="adm-label">Discount Amount (&#8377;)</label>
                                <input type="number" class="adm-input" id="discount_amount" name="discount_amount" min="0" step="0.01" value="0" placeholder="0.00" oninput="recalcNetPayable()">
                            </div>
                        </div>
                        <div class="adm-form-row">
                            <div class="adm-form-group">
                                <label class="adm-label">Discount Reason</label>
                                <input type="text" class="adm-input" id="discount_reason" name="discount_reason" maxlength="255" placeholder="e.g., Sibling discount">
                            </div>
                            <div class="adm-form-group">
                                <label class="adm-label">No. of Installments</label>
                                <select class="adm-input" id="installments" name="installments">
                                    <option value="1">1 (Full Payment)</option>
                                    <option value="2">2 Installments</option>
                                    <option value="3">3 Installments</option>
                                    <option value="4">4 Installments</option>
                                    <option value="6">6 Installments</option>
                                    <option value="12">12 Monthly</option>
                                </select>
                            </div>
                        </div>
                        <div class="adm-form-row">
                            <div class="adm-form-group">
                                <label class="adm-label">Net Payable (&#8377;)</label>
                                <input type="number" class="adm-input" id="net_payable" name="net_payable" readonly style="background:#f3f4f6;cursor:not-allowed;font-weight:700;" placeholder="Auto-calculated">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Material Details -->
                <div class="adm-section">
                    <div class="adm-section-header">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                        <span>Material Details</span>
                    </div>
                    <div class="adm-section-body">
                        <div class="adm-form-row">
                            <div class="adm-form-group">
                                <label class="adm-label">Material</label>
                                <select class="adm-input" id="material_type" name="material_type" onchange="onMaterialTypeChange()">
                                    <option value="Without Material">Without Material</option>
                                    <option value="With Material">With Material</option>
                                </select>
                                <div style="font-size:.72rem;color:#9ca3af;margin-top:.25rem">Auto-filled from course selection; you can still change it</div>
                            </div>
                            <div class="adm-form-group" id="materialLangGroup">
                                <label class="adm-label">Material Language</label>
                                <select class="adm-input" id="material_language" name="material_language">
                                    <option value="English">English</option>
                                    <option value="Marathi">Marathi</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Notes -->
                <div class="adm-section">
                    <div class="adm-section-header">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <span>Notes</span>
                    </div>
                    <div class="adm-section-body">
                        <div class="adm-form-row">
                            <div class="adm-form-group full-width">
                                <label class="adm-label">Comments / Notes</label>
                                <textarea class="adm-input" id="comment" name="comment" rows="3" placeholder="Any additional notes..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <div class="adm-modal-footer">
                <button type="button" class="adm-btn-cancel" onclick="closeModal()">Cancel</button>
                <button type="submit" class="adm-btn-submit" id="submitBtn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    <span id="submitBtnText">Save Admission</span>
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* ═══════════ ADMISSION MODAL STYLES ═══════════ */
.modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.adm-modal-card {
    max-width: 900px;
    width: 95%;
    background: #ffffff;
    border-radius: 20px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    display: flex;
    flex-direction: column;
    max-height: 90vh;
    overflow: hidden;
}

/* Header */
.adm-modal-header {
    position: relative;
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.5rem 2rem;
    background: linear-gradient(135deg, #4361ee 0%, #3730a3 100%);
    color: #fff;
    overflow: hidden;
}

.adm-modal-header-bg {
    position: absolute;
    top: -50%;
    right: -10%;
    width: 300px;
    height: 300px;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
}

.adm-modal-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    background: rgba(255,255,255,.18);
    backdrop-filter: blur(10px);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0,0,0,.1);
    z-index: 1;
}

.adm-modal-icon svg {
    width: 24px;
    height: 24px;
}

.adm-modal-title-wrap {
    z-index: 1;
}

.adm-modal-title {
    font-size: 1.125rem;
    font-weight: 800;
    letter-spacing: -0.02em;
}

.adm-modal-subtitle {
    font-size: .8125rem;
    opacity: .9;
    margin-top: .2rem;
    font-weight: 500;
}

.adm-modal-close {
    margin-left: auto;
    background: rgba(255,255,255,.15);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 10px;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #fff;
    transition: all .2s ease;
    z-index: 1;
}

.adm-modal-close:hover {
    background: rgba(255,255,255,.25);
    transform: rotate(90deg);
}

.adm-modal-close svg {
    width: 18px;
    height: 18px;
}

/* Form */
.adm-modal-form {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    flex: 1;
}

.adm-modal-body {
    overflow-y: auto;
    overflow-x: hidden;
    padding: 1.5rem 2rem;
    background: #fafbfc;
    flex: 1;
}

/* Custom Scrollbar */
.adm-modal-body::-webkit-scrollbar {
    width: 12px;
}

.adm-modal-body::-webkit-scrollbar-track {
    background: #e2e8f0;
    border-radius: 10px;
    margin: 4px 0;
}

.adm-modal-body::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #94a3b8 0%, #64748b 100%);
    border-radius: 10px;
    border: 3px solid #e2e8f0;
}

.adm-modal-body::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(180deg, #64748b 0%, #475569 100%);
}

/* Section */
.adm-section {
    background: #ffffff;
    border: 1.5px solid #e5e7eb;
    border-radius: 16px;
    margin-bottom: 1.5rem;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,.04);
}

.adm-section:hover {
    border-color: #d1d5db;
    box-shadow: 0 4px 12px rgba(0,0,0,.06);
}

.adm-section-header {
    display: flex;
    align-items: center;
    gap: .7rem;
    padding: .875rem 1.25rem;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-bottom: 1.5px solid #e5e7eb;
    font-size: .8125rem;
    font-weight: 800;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: .08em;
}

.adm-section-header svg {
    width: 16px;
    height: 16px;
    stroke: #4361ee;
    flex-shrink: 0;
}

.adm-section-body {
    padding: 1.25rem;
}

/* Form Layout */
.adm-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1rem;
}

.adm-form-row:last-child {
    margin-bottom: 0;
}

.adm-form-group {
    display: flex;
    flex-direction: column;
    gap: .4rem;
}

.adm-form-group.full-width {
    grid-column: 1 / -1;
}

.adm-label {
    font-size: .8125rem;
    font-weight: 700;
    color: #374151;
    letter-spacing: -0.01em;
}

.required {
    color: #ef4444;
    font-weight: 800;
}

.adm-input {
    height: 44px;
    padding: 0 1rem;
    border-radius: 10px;
    border: 1.5px solid #e5e7eb;
    background: #ffffff;
    font-size: .875rem;
    color: #1f2937;
    font-family: 'Sora', sans-serif;
    font-weight: 500;
    transition: all .2s ease;
    width: 100%;
}

.adm-input:hover {
    border-color: #d1d5db;
}

.adm-input:focus {
    outline: none;
    border-color: #4361ee;
    box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.1);
}

textarea.adm-input {
    height: auto;
    padding: .875rem 1rem;
    resize: vertical;
    min-height: 80px;
}

.adm-hint {
    font-size: .75rem;
    color: #6b7280;
    margin-top: .2rem;
}

/* Photo Upload */
.adm-photo-upload {
    height: 140px;
    border: 2px dashed #d1d5db;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all .2s ease;
    overflow: hidden;
    background: #f9fafb;
    position: relative;
}

.adm-photo-upload:hover {
    border-color: #4361ee;
    background: #eef1fd;
    border-style: solid;
}

.adm-photo-upload img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 10px;
}

.adm-photo-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: .4rem;
    color: #6b7280;
    text-align: center;
}

.adm-photo-placeholder svg {
    width: 28px;
    height: 28px;
}

.adm-photo-placeholder span {
    font-size: .8rem;
    font-weight: 600;
}

.adm-photo-placeholder small {
    font-size: .7rem;
}

/* Footer */
.adm-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: .875rem;
    padding: 1.25rem 2rem;
    border-top: 1.5px solid #e5e7eb;
    background: #ffffff;
    box-shadow: 0 -4px 12px rgba(0,0,0,.03);
}

.adm-btn-cancel {
    padding: .75rem 1.75rem;
    border-radius: 10px;
    border: 1.5px solid #e5e7eb;
    background: #ffffff;
    color: #6b7280;
    font-size: .875rem;
    font-weight: 700;
    cursor: pointer;
    transition: all .2s ease;
    font-family: 'Sora', sans-serif;
}

.adm-btn-cancel:hover {
    background: #f9fafb;
    color: #374151;
    border-color: #d1d5db;
    transform: translateY(-1px);
}

.adm-btn-submit {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    padding: .75rem 2rem;
    border-radius: 10px;
    border: none;
    background: linear-gradient(135deg, #4361ee 0%, #3730a3 100%);
    color: #fff;
    font-size: .875rem;
    font-weight: 800;
    cursor: pointer;
    transition: all .2s ease;
    box-shadow: 0 4px 14px rgba(67, 97, 238, .3);
    font-family: 'Sora', sans-serif;
}

.adm-btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(67, 97, 238, .4);
}

.adm-btn-submit:disabled {
    opacity: .6;
    transform: none;
    cursor: not-allowed;
}

.adm-btn-submit svg {
    width: 16px;
    height: 16px;
}

/* Responsive */
@media (max-width: 768px) {
    .adm-form-row {
        grid-template-columns: 1fr;
    }
    
    .adm-modal-card {
        width: 98%;
        max-height: 95vh;
    }
    
    .adm-modal-header {
        padding: 1.25rem 1.5rem;
    }
    
    .adm-modal-body {
        padding: 1.25rem 1.5rem;
    }
    
    .adm-modal-body::-webkit-scrollbar {
        width: 8px;
    }
    
    .adm-modal-footer {
        padding: 1rem 1.5rem;
    }
}
</style>

<style>
/* ── Edit/Add Admission Modal (OLD - TO BE REMOVED) ── */

/* View Details Styling */
.view-details {
    font-family: var(--font);
}
.detail-section {
    margin-bottom: 1.5rem;
}
.detail-section h4 {
    font-size: 1rem;
    font-weight: 800;
    color: #1f2937;
    margin-bottom: 1rem;
    padding-bottom: .5rem;
    border-bottom: 2px solid #e5e7eb;
}
.detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}
.detail-item {
    display: flex;
    flex-direction: column;
    gap: .25rem;
}
.detail-item.full-width {
    grid-column: 1 / -1;
}
.detail-label {
    font-size: .75rem;
    font-weight: 700;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.detail-value {
    font-size: .875rem;
    font-weight: 600;
    color: #1f2937;
}
</style>



<!-- View Admission Modal -->
<div class="modal-overlay" id="viewModal" style="position: fixed; inset: 0; z-index: 9999; background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; padding: 1rem;">
    <div class="modal-card" style="max-width: 700px; background: #ffffff; border-radius: 20px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); overflow: hidden;">
        <div class="modal-header" style="display: flex; align-items: center; justify-content: space-between; padding: 1.5rem 2rem; background: linear-gradient(135deg, #4361ee, #3730a3); color: white; border: none;">
            <h3 style="display: flex; align-items: center; gap: .75rem; font-size: 1.125rem; font-weight: 800; margin: 0; color: white;">
                <svg style="width: 22px; height: 22px;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                Admission Details
            </h3>
            <button type="button" onclick="closeViewModal()" aria-label="Close" style="background: rgba(255,255,255,.15); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,.2); border-radius: 10px; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #fff; transition: all .2s ease;">
                <svg style="width: 18px; height: 18px;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        
        <div id="viewContent" style="padding: 2rem; max-height: calc(90vh - 180px); overflow-y: auto;">
            <!-- Content will be populated by JavaScript -->
        </div>
        
        <div style="display: flex; justify-content: flex-end; gap: .875rem; padding: 1.25rem 2rem; border-top: 1.5px solid #e5e7eb; background: #ffffff;">
            <button type="button" onclick="closeViewModal()" style="padding: .75rem 1.75rem; border-radius: 10px; border: 1.5px solid #e5e7eb; background: #ffffff; color: #6b7280; font-size: .875rem; font-weight: 700; cursor: pointer; transition: all .2s ease;">
                Close
            </button>
        </div>
    </div>
</div>

<!-- ═══════════ CONVERT TO ADMISSION MODAL ═══════════ -->
<div class="modal-overlay" id="convertModal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.55); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:1rem;">
    <div class="modal-card cvt-modal-card">

        <!-- Header -->
        <div class="cvt-header">
            <div class="cvt-header-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
            </div>
            <div>
                <div class="cvt-header-title">Convert Inquiry to Admission</div>
                <div class="cvt-header-sub">Fill in enrollment details to register this student</div>
            </div>
            <button type="button" class="cvt-close" onclick="closeConvertModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <!-- Student identity bar -->
        <div class="cvt-student-bar">
            <div class="cvt-student-avatar" id="cvt_avatar">?</div>
            <div class="cvt-student-info">
                <div class="cvt-student-name" id="convert_student_name">—</div>
                <div class="cvt-student-meta">
                    <span id="convert_student_mobile"></span>
                    <span class="cvt-dot">·</span>
                    <span id="convert_student_course"></span>
                </div>
            </div>
            <div class="cvt-student-badge">Inquiry → Admission</div>
        </div>

        <form id="convertForm" novalidate enctype="multipart/form-data">
            <input type="hidden" id="convert_inquiry_id" name="inquiry_id">
            <input type="hidden" name="action" value="convert_to_admission">

            <div class="cvt-body">

                <!-- Section 1: Course -->
                <div class="cvt-section">
                    <div class="cvt-section-label">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                        Course Details
                    </div>
                    <div class="cvt-grid">
                        <div class="cvt-field full">
                            <label class="cvt-label">Course <span class="cvt-req">*</span></label>
                            <select class="cvt-select" id="convert_course" name="course" required onchange="calcFees(event); toggleConvertUniformSize(); syncConvertMaterial(this);">
                                <option value="">— Select Course —</option>
                                <?php foreach ($courseSelectOptions as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt['course_name']) ?>"
                                            data-fees="<?= $opt['fee'] ?>"
                                            data-material="<?= htmlspecialchars($opt['material_type']) ?>"
                                            data-language="<?= htmlspecialchars($opt['language'] ?? 'English') ?>">
                                        <?= htmlspecialchars($opt['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="cvt-field" id="convertUniformSizeField" style="display:none;">
                            <label class="cvt-label">T-Shirt Size <span class="cvt-req">*</span></label>
                            <select class="cvt-select" id="convert_uniform_size" name="uniform_size">
                                <option value="">— Select Size —</option>
                                <option value="36">36</option>
                                <option value="38">38</option>
                                <option value="40">40</option>
                                <option value="42">42</option>
                                <option value="44">44</option>
                                <option value="46">46</option>
                            </select>
                        </div>
                        <div class="cvt-field">
                            <label class="cvt-label">Admission Date <span class="cvt-req">*</span></label>
                            <input type="date" class="cvt-input" id="convert_admission_date" name="admission_date" required>
                        </div>
                        <div class="cvt-field">
                            <label class="cvt-label">Status</label>
                            <select class="cvt-select" id="convert_status" name="status">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Section 1b: Student Details (mandatory) -->
                <div class="cvt-section">
                    <div class="cvt-section-label">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Student Details
                    </div>
                    <div class="cvt-grid">
                        <div class="cvt-field">
                            <label class="cvt-label">First Name <span class="cvt-req">*</span></label>
                            <input type="text" class="cvt-input" id="cvt_first_name" name="cvt_first_name" required placeholder="First name">
                        </div>
                        <div class="cvt-field">
                            <label class="cvt-label">Last Name <span class="cvt-req">*</span></label>
                            <input type="text" class="cvt-input" id="cvt_last_name" name="cvt_last_name" required placeholder="Last name">
                        </div>
                        <div class="cvt-field">
                            <label class="cvt-label">Gender <span class="cvt-req">*</span></label>
                            <select class="cvt-select" id="cvt_gender" name="cvt_gender" required>
                                <option value="">— Select —</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="cvt-field">
                            <label class="cvt-label">Date of Birth <span class="cvt-req">*</span></label>
                            <input type="date" class="cvt-input" id="cvt_dob" name="cvt_dob" required>
                        </div>
                        <div class="cvt-field">
                            <label class="cvt-label">Qualification <span class="cvt-req">*</span></label>
                            <input type="text" class="cvt-input" id="cvt_qualification" name="cvt_qualification" required placeholder="e.g. 10th, HSC, Graduate">
                        </div>
                        <div class="cvt-field">
                            <label class="cvt-label">Mobile <span class="cvt-req">*</span></label>
                            <input type="tel" class="cvt-input" id="cvt_mobile" name="cvt_mobile" required maxlength="10" placeholder="10-digit mobile">
                        </div>
                        <div class="cvt-field full">
                            <label class="cvt-label">Address <span class="cvt-req">*</span></label>
                            <input type="text" class="cvt-input" id="cvt_address" name="cvt_address" required placeholder="Full address">
                        </div>
                        <div class="cvt-field">
                            <label class="cvt-label">City <span class="cvt-req">*</span></label>
                            <input type="text" class="cvt-input" id="cvt_city" name="cvt_city" required placeholder="City">
                        </div>
                        <div class="cvt-field">
                            <label class="cvt-label">State</label>
                            <input type="text" class="cvt-input" id="cvt_state" name="cvt_state" placeholder="e.g., Maharashtra">
                        </div>
                        <div class="cvt-field">
                            <label class="cvt-label">Pin Code <span class="cvt-req">*</span></label>
                            <input type="text" class="cvt-input" id="cvt_pin_code" name="cvt_pin_code" required maxlength="6" placeholder="6-digit pin">
                        </div>
                    </div>
                </div>

                <!-- Guardian Details -->
                <div class="cvt-section">
                    <div class="cvt-section-label">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Guardian Details
                    </div>
                    <div class="cvt-grid">
                        <div class="cvt-field">
                            <label class="cvt-label">Father's Name</label>
                            <input type="text" class="cvt-input" id="cvt_father_name" name="father_name" maxlength="100" placeholder="Father's full name">
                        </div>
                        <div class="cvt-field">
                            <label class="cvt-label">Mother's Name</label>
                            <input type="text" class="cvt-input" id="cvt_mother_name" name="mother_name" maxlength="100" placeholder="Mother's full name">
                        </div>
                    </div>
                </div>

                <!-- Fee Details -->
                <div class="cvt-section">
                    <div class="cvt-section-label">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        Fee Details
                    </div>
                    <div class="cvt-grid">
                        <div class="cvt-field">
                            <label class="cvt-label">Course Fees (&#8377;) <span class="cvt-req">*</span></label>
                            <input type="number" class="cvt-input" id="convert_course_fees" name="course_fees" required min="0" step="1" placeholder="e.g. 8000" oninput="calcFees()">
                        </div>
                        <div class="cvt-field">
                            <label class="cvt-label">Discount (&#8377;)</label>
                            <input type="number" class="cvt-input" id="convert_discount_amount" name="discount_amount" min="0" step="1" value="0" placeholder="0" oninput="calcFees()">
                        </div>
                        <div class="cvt-field">
                            <label class="cvt-label">Discount Reason</label>
                            <input type="text" class="cvt-input" id="convert_discount_reason" name="discount_reason" maxlength="255" placeholder="e.g., Scholarship">
                        </div>
                        <div class="cvt-field">
                            <label class="cvt-label">No. of Installments</label>
                            <select class="cvt-select" id="convert_installments" name="installments">
                                <option value="1">1 (Full Payment)</option>
                                <option value="2">2 Installments</option>
                                <option value="3">3 Installments</option>
                                <option value="4">4 Installments</option>
                                <option value="6">6 Installments</option>
                                <option value="12">12 Monthly</option>
                            </select>
                        </div>
                        <div class="cvt-field">
                            <label class="cvt-label">Net Payable (&#8377;)</label>
                            <input type="number" class="cvt-input" id="convert_net_payable" name="net_payable" readonly style="background:var(--gray-50);cursor:not-allowed;font-weight:700" placeholder="Auto calculated">
                        </div>
                    </div>
                    <!-- Fee summary card -->
                    <div class="cvt-fee-summary" id="feeSummaryCard" style="display:none">
                        <div class="cvt-fee-row">
                            <span>Course Fees</span><span id="fs_course">&#8377;0</span>
                        </div>
                        <div class="cvt-fee-row discount">
                            <span>Discount</span><span id="fs_discount">— &#8377;0</span>
                        </div>
                        <div class="cvt-fee-divider"></div>
                        <div class="cvt-fee-row net">
                            <span>Net Payable</span><span id="fs_net">&#8377;0</span>
                        </div>
                    </div>
                </div>

                <!-- Material Details -->
                <div class="cvt-section">
                    <div class="cvt-section-label">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                        Material Details
                    </div>
                    <div class="cvt-grid">
                        <div class="cvt-field">
                            <label class="cvt-label">Material</label>
                            <select class="cvt-select" id="convert_material_type" name="material_type">
                                <option value="Without Material">Without Material</option>
                                <option value="With Material">With Material</option>
                            </select>
                        </div>
                        <div class="cvt-field">
                            <label class="cvt-label">Material Language</label>
                            <select class="cvt-select" id="convert_material_language" name="material_language">
                                <option value="English">English</option>
                                <option value="Marathi">Marathi</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Notes -->
                <div class="cvt-section">
                    <div class="cvt-section-label">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        Notes
                    </div>
                    <div class="cvt-grid">
                        <div class="cvt-field full">
                            <label class="cvt-label">Comments / Notes</label>
                            <textarea class="cvt-input" id="convert_comment" name="comment" rows="3" placeholder="Any additional notes about this admission…"></textarea>
                        </div>
                    </div>
                </div>

            </div><!-- /.cvt-body -->

            <div class="cvt-footer">
                <button type="button" class="cvt-btn-cancel" onclick="closeConvertModal()">Cancel</button>
                <button type="submit" class="cvt-btn-submit" id="convertSubmitBtn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
                    <span id="convertSubmitBtnText">Convert to Admission</span>
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* ═══════ Convert Modal Styles ═══════ */
.cvt-modal-card {
    max-width: 720px;
    width: 95vw;
    border-radius: var(--radius-xl);
    overflow: hidden;
    padding: 0;
}
.cvt-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.25rem 1.5rem;
    background: linear-gradient(135deg, #10b981, #059669);
    color: #fff;
}
.cvt-header-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    background: rgba(255,255,255,.2);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.cvt-header-icon svg { width:22px;height:22px;stroke:#fff; }
.cvt-header-title { font-size:1rem;font-weight:800; }
.cvt-header-sub   { font-size:.78rem;opacity:.85;margin-top:.1rem; }
.cvt-close {
    margin-left: auto;
    background: rgba(255,255,255,.2);
    border: none; border-radius: 8px;
    width: 34px; height: 34px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: #fff;
    transition: background .2s;
    flex-shrink: 0;
}
.cvt-close:hover { background: rgba(255,255,255,.35); }
.cvt-close svg { width:16px;height:16px; }

/* Student bar */
.cvt-student-bar {
    display: flex;
    align-items: center;
    gap: .875rem;
    padding: .875rem 1.5rem;
    background: linear-gradient(135deg, #f0fdf4, #dcfce7);
    border-bottom: 1.5px solid #bbf7d0;
}
.cvt-student-avatar {
    width: 44px; height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, #10b981, #059669);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; font-weight: 800; color: #fff;
    flex-shrink: 0;
}
.cvt-student-name { font-size:.9375rem;font-weight:800;color:#065f46; }
.cvt-student-meta { font-size:.78rem;color:#047857;margin-top:.15rem;display:flex;align-items:center;gap:.4rem; }
.cvt-dot { opacity:.5; }
.cvt-student-badge {
    margin-left: auto;
    padding: .3rem .875rem;
    border-radius: 999px;
    background: #10b981;
    color: #fff;
    font-size: .72rem;
    font-weight: 800;
    white-space: nowrap;
    flex-shrink: 0;
}

/* Body */
.cvt-body {
    padding: 1.25rem 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    max-height: 55vh;
    overflow-y: auto;
}

/* Custom Scrollbar for Convert Modal Body */
.cvt-body::-webkit-scrollbar {
    width: 6px;
}
.cvt-body::-webkit-scrollbar-track {
    background: transparent;
}
.cvt-body::-webkit-scrollbar-thumb {
    background: var(--gray-300);
    border-radius: 10px;
}
.cvt-body::-webkit-scrollbar-thumb:hover {
    background: var(--gray-400);
}
.cvt-section { display:flex;flex-direction:column;gap:.875rem; }
.cvt-section-label {
    display: flex; align-items: center; gap: .5rem;
    font-size: .72rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: .07em;
    color: var(--text-muted);
    padding-bottom: .5rem;
    border-bottom: 1px solid var(--border-color);
}
.cvt-section-label svg { width:14px;height:14px;color:var(--primary-500); }
.cvt-grid { display:grid;grid-template-columns:1fr 1fr;gap:.875rem; }
.cvt-field { display:flex;flex-direction:column;gap:.35rem; }
.cvt-field.full { grid-column: 1 / -1; }
.cvt-label { font-size:.78rem;font-weight:700;color:var(--text-secondary); }
.cvt-req { color:var(--danger-500); }
.cvt-input, .cvt-select {
    height: 40px;
    padding: 0 .875rem;
    border-radius: var(--radius-md);
    border: 1.5px solid var(--border-color);
    background: var(--bg-surface);
    font-size: .875rem;
    color: var(--text-primary);
    font-family: inherit;
    transition: border-color .2s, box-shadow .2s;
    width: 100%;
}
.cvt-input:focus, .cvt-select:focus {
    outline: none;
    border-color: #10b981;
    box-shadow: 0 0 0 3px rgba(16,185,129,.15);
}
textarea.cvt-input { height: auto; padding: .75rem .875rem; }

/* Fee summary */
.cvt-fee-summary {
    background: var(--gray-50);
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: .875rem 1rem;
    display: flex;
    flex-direction: column;
    gap: .5rem;
}
.cvt-fee-row { display:flex;justify-content:space-between;font-size:.825rem;color:var(--text-secondary);font-weight:500; }
.cvt-fee-row.net   { font-weight:800;font-size:.9rem;color:var(--text-primary); }
.cvt-fee-row.paid  { color:#059669;font-weight:700; }
.cvt-fee-row.pending { color:#dc2626;font-weight:800;font-size:.9rem; }
.cvt-fee-row.discount { color:#d97706; }
.cvt-fee-divider { height:1px;background:var(--border-color); }

/* Photo box */
.cvt-photo-box {
    height: 140px;
    border: 2px dashed var(--border-color);
    border-radius: var(--radius-lg);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    transition: border-color .2s, background .2s;
    overflow: hidden;
    background: var(--gray-50);
}
.cvt-photo-box:hover { border-color:#10b981; background:#f0fdf4; }
#photoPlaceholder { display:flex;flex-direction:column;align-items:center;gap:.4rem;color:var(--text-muted); }
#photoPlaceholder svg { width:28px;height:28px; }
#photoPlaceholder span { font-size:.8rem;font-weight:600; }
#photoPlaceholder small { font-size:.7rem; }

/* Footer */
.cvt-footer {
    display: flex;
    justify-content: flex-end;
    gap: .75rem;
    padding: 1rem 1.5rem;
    border-top: 1.5px solid var(--border-color);
    background: var(--gray-50);
}
.cvt-btn-cancel {
    padding: .6rem 1.5rem;
    border-radius: var(--radius-full);
    border: 1.5px solid var(--border-color);
    background: var(--bg-surface);
    color: var(--text-secondary);
    font-size: .875rem; font-weight: 700;
    cursor: pointer;
    transition: all .2s;
}
.cvt-btn-cancel:hover { background: var(--gray-100); color: var(--text-primary); }
.cvt-btn-submit {
    display: inline-flex; align-items: center; gap: .5rem;
    padding: .65rem 1.75rem;
    border-radius: var(--radius-full);
    border: none;
    background: linear-gradient(135deg, #10b981, #059669);
    color: #fff;
    font-size: .875rem; font-weight: 800;
    cursor: pointer;
    transition: all .2s;
    box-shadow: 0 4px 14px rgba(16,185,129,.3);
}
.cvt-btn-submit:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(16,185,129,.4); }
.cvt-btn-submit:disabled { opacity:.65; transform:none; cursor:not-allowed; }
.cvt-btn-submit svg { width:16px;height:16px; }

@media(max-width:520px) {
    .cvt-grid { grid-template-columns: 1fr; }
    .cvt-student-badge { display:none; }
}
</style>


<script src="../assets/js/dashboard.js"></script>
<script>
// Toggle uniform size field based on course selection
function toggleUniformSize() {
    const courseInput = document.getElementById('course');
    const uniformSizeField = document.getElementById('uniformSizeField');
    const uniformSizeSelect = document.getElementById('uniform_size');
    
    if (courseInput && uniformSizeField) {
        const courseValue = courseInput.value.toLowerCase().trim();
        if (courseValue.includes('abacus')) {
            uniformSizeField.style.display = 'block';
        } else {
            uniformSizeField.style.display = 'none';
            if (uniformSizeSelect) uniformSizeSelect.value = ''; // Clear selection
        }
    }
}

// Auto-fill course fee + material type from selected course variant
function updateCourseFee(selectEl) {
    const selectedOpt = selectEl.options[selectEl.selectedIndex];
    const fee = selectedOpt && selectedOpt.dataset.fee ? parseFloat(selectedOpt.dataset.fee) : 0;
    const material = selectedOpt && selectedOpt.dataset.material ? selectedOpt.dataset.material : 'Without Material';
    const language = selectedOpt && selectedOpt.dataset.language ? selectedOpt.dataset.language : 'English';

    const feesField = document.getElementById('course_fees');
    if (feesField && fee > 0) {
        feesField.value = fee.toFixed(2);
        recalcNetPayable();
    }

    const matField = document.getElementById('material_type');
    if (matField) matField.value = material;

    const langField = document.getElementById('material_language');
    if (langField) langField.value = language;

    toggleMaterialLangVisibility();
}

function onMaterialTypeChange() {
    toggleMaterialLangVisibility();
    // If ATC flips material type after course select, try to match fee from sibling option
    const courseSel = document.getElementById('course');
    const matType = document.getElementById('material_type')?.value || 'Without Material';
    if (!courseSel || !courseSel.value) return;
    for (let i = 0; i < courseSel.options.length; i++) {
        const opt = courseSel.options[i];
        if (opt.value === courseSel.value && opt.dataset.material === matType) {
            courseSel.selectedIndex = i;
            const feesField = document.getElementById('course_fees');
            const fee = parseFloat(opt.dataset.fee) || 0;
            if (feesField && fee > 0) {
                feesField.value = fee.toFixed(2);
                recalcNetPayable();
            }
            break;
        }
    }
}

function toggleMaterialLangVisibility() {
    const mat = document.getElementById('material_type')?.value || 'Without Material';
    const group = document.getElementById('materialLangGroup');
    if (group) group.style.opacity = (mat === 'With Material') ? '1' : '0.55';
}

function syncConvertMaterial(selectEl) {
    const selectedOpt = selectEl.options[selectEl.selectedIndex];
    if (!selectedOpt) return;
    const material = selectedOpt.dataset.material || 'Without Material';
    const language = selectedOpt.dataset.language || 'English';
    const matEl = document.getElementById('convert_material_type');
    const langEl = document.getElementById('convert_material_language');
    if (matEl) matEl.value = material;
    if (langEl) langEl.value = language;
}

// Toggle uniform size field in convert modal
function toggleConvertUniformSize() {
    const courseSelect = document.getElementById('convert_course');
    const uniformSizeField = document.getElementById('convertUniformSizeField');
    const uniformSizeSelect = document.getElementById('convert_uniform_size');
    
    if (courseSelect && uniformSizeField) {
        const courseValue = courseSelect.value.toLowerCase().trim();
        if (courseValue.includes('abacus')) {
            uniformSizeField.style.display = 'block';
        } else {
            uniformSizeField.style.display = 'none';
            uniformSizeSelect.value = ''; // Clear selection
        }
    }
}

// Load inquiries for dropdown on page load
document.addEventListener('DOMContentLoaded', async function() {
    await loadInquiriesDropdown();
    
    // Set default admission date to today
    document.getElementById('admission_date').valueAsDate = new Date();
    
    // Add event listener for course field
    const courseInput = document.getElementById('course');
    if (courseInput) {
        courseInput.addEventListener('input', toggleUniformSize);
        courseInput.addEventListener('change', toggleUniformSize);
    }
});

// Load converted inquiries into dropdown
async function loadInquiriesDropdown() {
    try {
        const formData = new FormData();
        formData.append('action', 'get_inquiries');
        
        const response = await fetch('', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success && result.data) {
            const select = document.getElementById('load_inquiry');
            result.data.forEach(inquiry => {
                const option = document.createElement('option');
                option.value = inquiry.id;
                option.textContent = `${inquiry.first_name} ${inquiry.middle_name || ''} ${inquiry.last_name} - ${inquiry.mobile} (${inquiry.interested_course})`;
                select.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Error loading inquiries:', error);
    }
}

/* ── Open convert modal directly from pending inquiry row ── */
function openConvertFromInquiry(inquiryId, name, mobile, course) {
    document.getElementById('convert_inquiry_id').value = inquiryId;
    document.getElementById('convert_student_name').textContent   = name;
    document.getElementById('cvt_avatar').textContent = name.charAt(0).toUpperCase();
    document.getElementById('convert_student_mobile').textContent = mobile;
    document.getElementById('convert_student_course').textContent = course;
    
    // Clear student detail fields first
    _clearCvtStudentFields();
    
    // Pre-fill known data from inquiry name/mobile
    const parts = name.split(' ');
    document.getElementById('cvt_first_name').value = parts[0] || '';
    document.getElementById('cvt_last_name').value  = parts[parts.length - 1] || '';
    document.getElementById('cvt_mobile').value     = mobile;
    
    // Load full inquiry data to pre-fill address, DOB etc via AJAX
    _loadInquiryIntoConvertModal(inquiryId);
    
    // Pre-fill course field with the inquiry's interested course
    const courseSelect = document.getElementById('convert_course');
    courseSelect.value = '';
    for (let opt of courseSelect.options) {
        if (opt.value && opt.value.toLowerCase() === course.toLowerCase()) {
            courseSelect.value = opt.value;
            break;
        }
    }
    
    // Reset fees & inputs
    document.getElementById('convert_discount_amount').value = '0';
    document.getElementById('convert_discount_reason').value = '';
    document.getElementById('convert_comment').value        = '';
    const cvtNetField = document.getElementById('convert_net_payable');
    if (cvtNetField) cvtNetField.value = '';
    const photoEl = document.getElementById('cvt_photo');
    if (photoEl) photoEl.value = '';

    calcFees({target: courseSelect});
    document.getElementById('convertModal').style.display = 'flex';
}

async function _loadInquiryIntoConvertModal(inquiryId) {
    try {
        const fd = new FormData();
        fd.append('action', 'load_inquiry');
        fd.append('inquiry_id', inquiryId);
        const res = await fetch('', { method: 'POST', body: fd });
        const r   = await res.json();
        if (r.success && r.data) {
            const d = r.data;
            document.getElementById('cvt_first_name').value    = d.first_name    || '';
            document.getElementById('cvt_last_name').value     = d.last_name     || '';
            document.getElementById('cvt_gender').value        = d.gender        || '';
            document.getElementById('cvt_dob').value           = d.dob           || '';
            document.getElementById('cvt_qualification').value = d.qualification || '';
            document.getElementById('cvt_mobile').value        = d.mobile        || '';
            document.getElementById('cvt_address').value       = d.address       || '';
            document.getElementById('cvt_city').value          = d.city          || '';
            document.getElementById('cvt_pin_code').value      = d.pin_code      || '';
        }
    } catch(e) { /* silent */ }
}

function _clearCvtStudentFields() {
    ['cvt_first_name','cvt_last_name','cvt_gender','cvt_dob','cvt_qualification',
     'cvt_mobile','cvt_address','cvt_city','cvt_pin_code'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.value = ''; el.style.borderColor = ''; }
    });
}

/* ── Open convert modal from telephonic inquiry row ── */
function openConvertFromTelephonic(inquiryId, name, mobile, course) {
    document.querySelector('#convertForm input[name="action"]').value = 'convert_telephonic_to_admission';
    document.getElementById('convert_inquiry_id').value = inquiryId;
    document.getElementById('convert_student_name').textContent   = name;
    document.getElementById('cvt_avatar').textContent = name.charAt(0).toUpperCase();
    document.getElementById('convert_student_mobile').textContent = mobile;
    document.getElementById('convert_student_course').textContent = '📞 ' + course;
    
    // Clear all student detail fields then pre-fill what we know
    _clearCvtStudentFields();
    const parts = name.split(' ');
    document.getElementById('cvt_first_name').value = parts[0] || '';
    document.getElementById('cvt_last_name').value  = parts[parts.length - 1] || '';
    document.getElementById('cvt_mobile').value     = mobile;
    
    // Pre-fill course field
    const courseSelect = document.getElementById('convert_course');
    courseSelect.value = '';
    for (let opt of courseSelect.options) {
        if (opt.value && opt.value.toLowerCase() === course.toLowerCase()) {
            courseSelect.value = opt.value;
            break;
        }
    }
    
    document.getElementById('convert_discount_amount').value = '0';
    document.getElementById('convert_discount_reason').value = '';
    document.getElementById('convert_comment').value        = '';
    const cvtNetField2 = document.getElementById('convert_net_payable');
    if (cvtNetField2) cvtNetField2.value = '';

    calcFees({target: courseSelect});
    document.getElementById('convertModal').style.display = 'flex';
}

/* ── Restore action to normal when convert modal closes ── */
function closeConvertModal() {
    document.getElementById('convertModal').style.display = 'none';
    // Reset action back to default
    const actionInput = document.querySelector('#convertForm input[name="action"]');
    if (actionInput) actionInput.value = 'convert_to_admission';
}

/* ── Fee Calculator for Convert Modal ── */
function calcFees(event) {
    const courseSelect = document.getElementById('convert_course');
    const selectedOpt = courseSelect.options[courseSelect.selectedIndex];
    const feesInput = document.getElementById('convert_course_fees');
    
    // If triggered by a change in course selection, pull the fee dynamically
    if (event && event.target === courseSelect && selectedOpt && selectedOpt.dataset.fees) {
        feesInput.value = parseFloat(selectedOpt.dataset.fees);
    }
    
    const courseFees = parseFloat(feesInput.value) || 0;
    const discount   = parseFloat(document.getElementById('convert_discount_amount').value) || 0;
    const netPayable = Math.max(0, courseFees - discount);

    // Update Net Payable field
    const netField = document.getElementById('convert_net_payable');
    if (netField) netField.value = netPayable > 0 ? netPayable.toFixed(2) : '';

    // Update summary card
    const card = document.getElementById('feeSummaryCard');
    if (courseFees > 0) {
        card.style.display = 'flex';
        document.getElementById('fs_course').textContent   = '₹' + courseFees.toFixed(0);
        document.getElementById('fs_discount').textContent = '— ₹' + discount.toFixed(0);
        document.getElementById('fs_net').textContent      = '₹' + netPayable.toFixed(0);
    } else {
        card.style.display = 'none';
        if (netField) netField.value = '';
    }
}

/* ── Photo Upload Preview ── */
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('photoPreview').src = e.target.result;
            document.getElementById('photoPreview').style.display = 'block';
            document.getElementById('photoPlaceholder').style.display = 'none';
        }
        reader.readAsDataURL(input.files[0]);
    }
}

/* ── Filter pending inquiries table ── */
function filterInquiries() {
    const term = document.getElementById('inqSearchInput').value.toLowerCase();
    document.querySelectorAll('#inqTable tbody tr').forEach(row => {
        const name   = row.dataset.inqName   || '';
        const mobile = row.dataset.inqMobile || '';
        const course = row.dataset.inqCourse || '';
        row.style.display = (!term || name.includes(term) || mobile.includes(term) || course.includes(term)) ? '' : 'none';
    });
}

// Load inquiry data to pre-fill form
async function loadInquiryData(inquiryId) {
    if (!inquiryId) {
        // Clear form if no inquiry selected
        document.getElementById('admissionForm').reset();
        document.getElementById('inquiry_id').value = '';
        document.getElementById('admission_date').valueAsDate = new Date();
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'load_inquiry');
        formData.append('inquiry_id', inquiryId);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success && result.data) {
            const inquiry = result.data;
            
            // Set inquiry_id
            document.getElementById('inquiry_id').value = inquiry.id;
            
            // Fill form fields
            document.getElementById('first_name').value = inquiry.first_name || '';
            document.getElementById('middle_name').value = inquiry.middle_name || '';
            document.getElementById('last_name').value = inquiry.last_name || '';
            document.getElementById('gender').value = inquiry.gender || '';
            document.getElementById('dob').value = inquiry.dob || '';
            document.getElementById('qualification').value = inquiry.qualification || '';
            document.getElementById('course').value = inquiry.interested_course || '';
            document.getElementById('address').value = inquiry.address || '';
            document.getElementById('pin_code').value = inquiry.pin_code || '';
            document.getElementById('city').value = inquiry.city || '';
            document.getElementById('mobile').value = inquiry.mobile || '';
            document.getElementById('phone').value = inquiry.phone || '';
            document.getElementById('email').value = inquiry.email || '';
            document.getElementById('referenced_by').value = inquiry.referenced_by || '';
            document.getElementById('comment').value = inquiry.comment || '';
        }
    } catch (error) {
        alert('Error loading inquiry data');
    }
}

// Search and Convert functionality
let searchTimeout;

function handleSearch(event) {
    if (event.key === 'Enter') {
        performSearch();
    }
}

async function performSearch() {
    const searchTerm = document.getElementById('searchInput').value.trim();
    
    if (!searchTerm || searchTerm.length < 2) {
        alert('Please enter at least 2 characters to search');
        return;
    }
    
    console.log('Searching for:', searchTerm);
    
    try {
        const formData = new FormData();
        formData.append('action', 'search');
        formData.append('search', searchTerm);
        
        console.log('Sending search request...');
        const response = await fetch('', { method: 'POST', body: formData });
        const result = await response.json();
        
        console.log('Search result:', result);
        
        if (result.success) {
            displaySearchResults(result.data);
        } else {
            alert('Error performing search: ' + (result.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error performing search: ' + error.message);
    }
}

function displaySearchResults(data) {
    console.log('Displaying search results:', data);
    
    const resultsDiv = document.getElementById('searchResults');
    const contentDiv = document.getElementById('searchResultsContent');
    
    if (!resultsDiv || !contentDiv) {
        console.error('Search results divs not found!');
        alert('Error: Search results container not found');
        return;
    }
    
    let html = '';
    
    // Display inquiries first (non-converted)
    if (data.inquiries && data.inquiries.length > 0) {
        console.log('Found', data.inquiries.length, 'inquiries');
        html += `
            <div style="margin-bottom: 2rem;">
                <h5 style="color: var(--primary-600); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                    Inquiries (${data.inquiries.length})
                </h5>
                <div style="display: grid; gap: 1rem;">
        `;
        
        data.inquiries.forEach(inquiry => {
            const fullName = `${inquiry.first_name} ${inquiry.middle_name ? inquiry.middle_name + ' ' : ''}${inquiry.last_name}`;
            html += `
                <div style="background: var(--bg-surface); border: 2px solid var(--primary-200); border-radius: var(--radius-lg); padding: 1rem; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="font-weight: 600; font-size: 1rem; color: var(--text-primary); margin-bottom: 0.25rem;">${fullName}</div>
                        <div style="font-size: 0.85rem; color: var(--text-secondary); display: flex; gap: 1rem;">
                            <span>📱 ${inquiry.mobile}</span>
                            <span>📚 ${inquiry.interested_course}</span>
                            <span class="cell-badge status-${inquiry.status.toLowerCase()}">${inquiry.status}</span>
                        </div>
                    </div>
                    <a class="btn-primary" href="convert_admission_page.php?inquiry_id=${inquiry.id}" style="background: linear-gradient(135deg, #10b981, #059669);display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;padding:.55rem 1rem;border-radius:8px;font-weight:700;font-size:.85rem;color:#fff;">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px;"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
                        Convert to Admission
                    </a>
                </div>
            `;
        });
        
        html += `
                </div>
            </div>
        `;
    } else {
        console.log('No inquiries found');
    }
    
    // Display admissions
    if (data.admissions && data.admissions.length > 0) {
        console.log('Found', data.admissions.length, 'admissions');
        html += `
            <div>
                <h5 style="color: var(--success-600); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                    Admitted Students (${data.admissions.length})
                </h5>
                <div style="display: grid; gap: 1rem;">
        `;
        
        data.admissions.forEach(admission => {
            const fullName = `${admission.first_name} ${admission.middle_name ? admission.middle_name + ' ' : ''}${admission.last_name}`;
            html += `
                <div style="background: var(--bg-surface); border: 2px solid var(--success-200); border-radius: var(--radius-lg); padding: 1rem;">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <div style="font-weight: 600; font-size: 1rem; color: var(--text-primary); margin-bottom: 0.25rem;">${fullName}</div>
                            <div style="font-size: 0.85rem; color: var(--text-secondary); display: flex; gap: 1rem; flex-wrap: wrap;">
                                <span>🎓 ${admission.roll_no}</span>
                                <span>📱 ${admission.mobile}</span>
                                <span>📚 ${admission.course}</span>
                                <span class="cell-badge status-${admission.status.toLowerCase()}">${admission.status}</span>
                            </div>
                        </div>
                        <button class="btn-icon" onclick="viewAdmission(${admission.id})" title="View Details">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>
            `;
        });
        
        html += `
                </div>
            </div>
        `;
    } else {
        console.log('No admissions found');
    }
    
    if (!html) {
        html = '<p style="text-align: center; color: var(--text-secondary); padding: 2rem;">No results found. Try a different search term.</p>';
    }
    
    console.log('Setting HTML content');
    contentDiv.innerHTML = html;
    resultsDiv.style.display = 'block';
    console.log('Search results displayed');
}

function closeSearchResults() {
    document.getElementById('searchResults').style.display = 'none';
    document.getElementById('searchInput').value = '';
}

// Open convert to admission modal
let currentInquiryId = null;

async function openConvertModal(inquiryId) {
    currentInquiryId = inquiryId;
    
    try {
        const formData = new FormData();
        formData.append('action', 'load_inquiry');
        formData.append('inquiry_id', inquiryId);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success && result.data) {
            const inquiry = result.data;
            const fullName = `${inquiry.first_name} ${inquiry.middle_name ? inquiry.middle_name + ' ' : ''}${inquiry.last_name}`;
            
            document.getElementById('convert_inquiry_id').value = inquiryId;
            document.getElementById('convert_student_name').textContent = fullName;
            document.getElementById('convert_student_mobile').textContent = inquiry.mobile;
            document.getElementById('convert_student_course').textContent = inquiry.interested_course;
            document.getElementById('convert_course').value = inquiry.interested_course;

            document.getElementById('convertModal').style.display = 'flex';
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error loading inquiry data');
    }
}

// (closeConvertModal is defined above near openConvertFromInquiry)

// Handle convert form submission
document.addEventListener('DOMContentLoaded', function() {
    const convertForm = document.getElementById('convertForm');
    if (convertForm) {
        convertForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            // ── Mandatory field validation ──
            const requiredFields = [
                { id: 'convert_course',        label: 'Course' },
                { id: 'convert_admission_date',label: 'Admission Date' },
                { id: 'convert_course_fees',   label: 'Course Fees' },
                { id: 'cvt_first_name',        label: 'First Name' },
                { id: 'cvt_last_name',         label: 'Last Name' },
                { id: 'cvt_gender',            label: 'Gender' },
                { id: 'cvt_dob',               label: 'Date of Birth' },
                { id: 'cvt_qualification',     label: 'Qualification' },
                { id: 'cvt_mobile',            label: 'Mobile' },
                { id: 'cvt_address',           label: 'Address' },
                { id: 'cvt_city',              label: 'City' },
                { id: 'cvt_pin_code',          label: 'Pin Code' },
            ];

            // Also validate T-Shirt size if Abacus course
            const courseVal = (document.getElementById('convert_course').value || '').toLowerCase();
            if (courseVal.includes('abacus')) {
                requiredFields.push({ id: 'convert_uniform_size', label: 'T-Shirt Size' });
            }

            let missing = [];
            requiredFields.forEach(f => {
                const el = document.getElementById(f.id);
                if (!el || !el.value.trim()) {
                    missing.push(f.label);
                    if (el) el.style.borderColor = '#ef4444';
                } else {
                    if (el) el.style.borderColor = '';
                }
            });

            // Mobile must be 10 digits
            const mobileEl = document.getElementById('cvt_mobile');
            if (mobileEl && mobileEl.value && !/^\d{10}$/.test(mobileEl.value.trim())) {
                missing.push('Mobile (must be 10 digits)');
                mobileEl.style.borderColor = '#ef4444';
            }
            // Pin code must be 6 digits
            const pinEl = document.getElementById('cvt_pin_code');
            if (pinEl && pinEl.value && !/^\d{6}$/.test(pinEl.value.trim())) {
                missing.push('Pin Code (must be 6 digits)');
                pinEl.style.borderColor = '#ef4444';
            }

            if (missing.length > 0) {
                alert('Please fill required fields:\n• ' + missing.join('\n• '));
                return;
            }
            const submitBtn = document.getElementById('convertSubmitBtn');
            if (!submitBtn) return;
            const originalText = document.getElementById('convertSubmitBtnText').textContent;
            submitBtn.disabled = true;
            document.getElementById('convertSubmitBtnText').textContent = 'Converting...';
            
            try {
                const formData = new FormData(this);
                const response = await fetch('', { method: 'POST', body: formData });
                const result = await response.json();
                
                if (result.success) {
                    if (result.admission_id) {
                        window.open('admission_form_pdf.php?admission_id=' + encodeURIComponent(result.admission_id) + '&auto=1', '_blank');
                    }
                    alert(`Student converted successfully! Roll No: ${result.roll_no}`);
                    closeConvertModal();
                    closeSearchResults();
                    location.reload();
                } else {
                    alert(result.message || 'Error converting to admission');
                    submitBtn.disabled = false;
                    document.getElementById('convertSubmitBtnText').textContent = originalText;
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error converting to admission');
                submitBtn.disabled = false;
                document.getElementById('convertSubmitBtnText').textContent = originalText;
            }
        });
    }
});

// Search functionality
document.getElementById('searchInput').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#admissionTable tbody tr');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
});

// Open add modal
function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Add New Admission';
    document.getElementById('submitBtnText').textContent = 'Add Admission';
    document.getElementById('formAction').value = 'add';
    document.getElementById('admissionForm').reset();
    document.getElementById('admissionId').value = '';
    document.getElementById('inquiry_id').value = '';
    document.getElementById('admission_date').valueAsDate = new Date();
    document.getElementById('status').value = 'Active';
    document.getElementById('category').value = 'General';
    document.getElementById('installments').value = '1';
    document.getElementById('material_type').value = 'Without Material';
    document.getElementById('material_language').value = 'English';
    document.getElementById('discount_amount').value = '0';
    document.getElementById('net_payable').value = '';
    document.getElementById('course_fees').value = '';

    // Reset photo preview
    if (document.getElementById('student_photo')) {
        document.getElementById('student_photo').value = '';
        const prev = document.getElementById('photoPreview');
        const ph   = document.getElementById('photoPlaceholder');
        if (prev) { prev.src = ''; prev.style.display = 'none'; }
        if (ph)   { ph.style.display = 'flex'; }
    }

    document.getElementById('admissionModal').classList.add('active');
    document.getElementById('admissionModal').style.display = 'flex';

    // Reload inquiries dropdown
    loadInquiriesDropdown();
}

// Auto-calculate Net Payable
function recalcNetPayable() {
    const fees     = parseFloat(document.getElementById('course_fees')?.value)     || 0;
    const discount = parseFloat(document.getElementById('discount_amount')?.value)  || 0;
    const net      = Math.max(0, fees - discount);
    const netField = document.getElementById('net_payable');
    if (netField) netField.value = net.toFixed(2);
}

// Photo preview
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            const prev = document.getElementById('photoPreview');
            const ph   = document.getElementById('photoPlaceholder');
            if (prev) { prev.src = e.target.result; prev.style.display = 'block'; }
            if (ph)   { ph.style.display = 'none'; }
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Close modal
function closeModal() {
    document.getElementById('admissionModal').style.display = 'none';
}

// Photo Upload Preview helper
function previewEditPhoto(input) {
    const preview = document.getElementById('editPhotoPreview');
    const placeholder = document.getElementById('editPhotoPlaceholder');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
            placeholder.style.display = 'none';
        }
        reader.readAsDataURL(input.files[0]);
    } else {
        preview.src = '';
        preview.style.display = 'none';
        placeholder.style.display = 'flex';
    }
}

// View admission details
async function viewAdmission(id) {
    try {
        const formData = new FormData();
        formData.append('action', 'get');
        formData.append('id', id);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success && result.data) {
            const a = result.data;
            const fullName = `${a.first_name} ${a.middle_name ? a.middle_name + ' ' : ''}${a.last_name}`;
            
            document.getElementById('viewContent').innerHTML = `
                <div class="view-details">
                    <div class="detail-section">
                        <h4>Personal Information</h4>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Full Name</span>
                                <span class="detail-value">${fullName}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Gender</span>
                                <span class="detail-value">${a.gender}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Date of Birth</span>
                                <span class="detail-value">${new Date(a.dob).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Qualification</span>
                                <span class="detail-value">${a.qualification}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-section">
                        <h4>Contact Information</h4>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Mobile</span>
                                <span class="detail-value">${a.mobile}</span>
                            </div>
                            ${a.phone ? `
                            <div class="detail-item">
                                <span class="detail-label">Alternate Phone</span>
                                <span class="detail-value">${a.phone}</span>
                            </div>` : ''}
                            ${a.email ? `
                            <div class="detail-item">
                                <span class="detail-label">Email</span>
                                <span class="detail-value">${a.email}</span>
                            </div>` : ''}
                            <div class="detail-item full-width">
                                <span class="detail-label">Address</span>
                                <span class="detail-value">${a.address}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">City</span>
                                <span class="detail-value">${a.city}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">PIN Code</span>
                                <span class="detail-value">${a.pin_code}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-section">
                        <h4>Course & Admission Details</h4>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Roll Number</span>
                                <span class="detail-value"><strong>${a.roll_no || 'Not Assigned'}</strong></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Course</span>
                                <span class="detail-value">${a.course}</span>
                            </div>
                            ${a.uniform_size ? `
                            <div class="detail-item">
                                <span class="detail-label">Uniform Size</span>
                                <span class="detail-value">${a.uniform_size}</span>
                            </div>` : ''}
                            <div class="detail-item">
                                <span class="detail-label">Admission Date</span>
                                <span class="detail-value">${new Date(a.admission_date).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })}</span>
                            </div>
                            ${a.referenced_by ? `
                            <div class="detail-item">
                                <span class="detail-label">Referenced By</span>
                                <span class="detail-value">${a.referenced_by}</span>
                            </div>` : ''}
                            <div class="detail-item">
                                <span class="detail-label">Status</span>
                                <span class="cell-badge status-${a.status.toLowerCase()}">${a.status}</span>
                            </div>
                            ${a.comment ? `
                            <div class="detail-item full-width">
                                <span class="detail-label">Comments</span>
                                <span class="detail-value">${a.comment}</span>
                            </div>` : ''}
                        </div>
                    </div>
                    
                    ${a.photo ? `
                    <div class="detail-section">
                        <h4>Student Photo</h4>
                        <div style="text-align: center; padding: 1rem;">
                            <img src="../${a.photo}" alt="Student Photo" style="max-width: 200px; max-height: 250px; border: 2px solid #e5e7eb; border-radius: 8px; object-fit: cover;">
                        </div>
                    </div>` : ''}
                </div>
            `;
            
            document.getElementById('viewModal').style.display = 'flex';
        }
    } catch (error) {
        alert('Error loading admission details');
    }
}

// Close view modal
function closeViewModal() {
    document.getElementById('viewModal').style.display = 'none';
}

// Delete admission
async function deleteAdmission(id, name) {
    if (!confirm(`Are you sure you want to delete admission for "${name}"?\n\nThis action cannot be undone.`)) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            alert('Admission deleted successfully');
            location.reload();
        } else {
            alert(result.message || 'Error deleting admission');
        }
    } catch (error) {
        alert('Error deleting admission');
    }
}

// Form submission
document.getElementById('admissionForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    // Validate mobile number
    const mobile = document.getElementById('mobile').value;
    if (mobile && !/^[0-9]{10}$/.test(mobile)) {
        alert('Please enter a valid 10-digit mobile number');
        return;
    }
    
    // Validate PIN code
    const pinCode = document.getElementById('pin_code').value;
    if (pinCode && !/^[0-9]{6}$/.test(pinCode)) {
        alert('Please enter a valid 6-digit PIN code');
        return;
    }
    
    const submitBtn = document.getElementById('submitBtn');
    const originalText = document.getElementById('submitBtnText').textContent;
    submitBtn.disabled = true;
    document.getElementById('submitBtnText').textContent = 'Saving...';
    
    try {
        const formData = new FormData(this);
        const response = await fetch('', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            if (result.admission_id) {
                window.open('admission_form_pdf.php?admission_id=' + encodeURIComponent(result.admission_id) + '&auto=1', '_blank');
            }
            alert(result.message);
            location.reload();
        } else {
            alert(result.message || 'Error saving admission');
            submitBtn.disabled = false;
            document.getElementById('submitBtnText').textContent = originalText;
        }
    } catch (error) {
        alert('Error saving admission');
        submitBtn.disabled = false;
        document.getElementById('submitBtnText').textContent = originalText;
    }
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

.badge-active {
    background: var(--success-100);
    color: var(--success-700);
}

.badge-inactive {
    background: var(--gray-200);
    color: var(--gray-700);
}

/* Status badges in table */
.cell-badge.status-active {
    background: var(--success-100);
    color: var(--success-700);
}

.cell-badge.status-inactive {
    background: var(--gray-200);
    color: var(--gray-700);
}

/* View details styling - Enhanced */
.view-details {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.detail-section {
    background: linear-gradient(135deg, #fafbfc, #f8f9fa);
    border: 1.5px solid #e5e7eb;
    border-radius: 14px;
    padding: 1.25rem 1.5rem;
    transition: all .3s ease;
}

.detail-section:hover {
    border-color: #d1d5db;
    box-shadow: 0 4px 12px rgba(0,0,0,.06);
    transform: translateY(-2px);
}

.detail-section h4 {
    font-size: 1rem;
    font-weight: 800;
    color: #1f2937;
    margin-bottom: 1.25rem;
    padding-bottom: .75rem;
    border-bottom: 2px solid #e5e7eb;
    display: flex;
    align-items: center;
    gap: .5rem;
    letter-spacing: -0.02em;
}

.detail-section h4::before {
    content: '';
    width: 4px;
    height: 20px;
    background: linear-gradient(135deg, #4361ee, #3730a3);
    border-radius: 999px;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.25rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: .4rem;
    padding: .75rem;
    background: #ffffff;
    border-radius: 10px;
    border: 1px solid #f3f4f6;
    transition: all .2s ease;
}

.detail-item:hover {
    border-color: #e5e7eb;
    box-shadow: 0 2px 8px rgba(0,0,0,.04);
}

.detail-item.full-width {
    grid-column: 1 / -1;
}

.detail-label {
    font-size: .75rem;
    font-weight: 800;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: .06em;
    display: flex;
    align-items: center;
    gap: .375rem;
}

.detail-label::before {
    content: '•';
    color: #4361ee;
    font-size: 1rem;
    font-weight: 900;
}

.detail-value {
    font-size: .9375rem;
    color: #1f2937;
    font-weight: 600;
    padding-left: .875rem;
}

/* Enhanced modal styling for view */
#viewModal .modal-card {
    max-width: 750px;
    border-radius: 20px;
    overflow: hidden;
}

#viewModal .modal-header {
    background: linear-gradient(135deg, #4361ee, #3730a3);
    color: #fff;
    padding: 1.5rem 1.75rem;
    border-bottom: none;
}

#viewModal .modal-header h3 {
    color: #fff;
    font-size: 1.125rem;
    font-weight: 800;
    letter-spacing: -0.02em;
}

#viewModal .modal-header svg {
    stroke: #fff;
    width: 20px;
    height: 20px;
}

#viewModal .modal-close {
    background: rgba(255,255,255,.2);
    border: none;
    color: #fff;
}

#viewModal .modal-close:hover {
    background: rgba(255,255,255,.35);
    color: #fff;
}

#viewModal .modal-body {
    padding: 1.75rem;
    max-height: 65vh;
    overflow-y: auto;
}

#viewModal .modal-footer {
    background: #f9fafb;
    border-top: 1.5px solid #e5e7eb;
    padding: 1.25rem 1.75rem;
}

.field-hint {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin-top: 0.25rem;
}

.field-hint svg {
    width: 14px;
    height: 14px;
    flex-shrink: 0;
}

/* Responsive for view modal */
@media (max-width: 768px) {
    .detail-grid {
        grid-template-columns: 1fr;
    }
    
    #viewModal .modal-card {
        max-width: 95vw;
    }
}
</style>
</body>
</html>
