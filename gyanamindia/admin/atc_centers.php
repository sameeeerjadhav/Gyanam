<?php
/**
 * Gyanam Portal — Admin: ATC Centers Management
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';
if (file_exists(__DIR__ . '/../includes/exam_integration.php')) {
    require_once __DIR__ . '/../includes/exam_integration.php';
}

requireLogin(['Admin']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_POST['action']) {

            case 'add':
                // Ensure new columns exist
                try {
                    $pdo->exec("ALTER TABLE atc_centers ADD COLUMN IF NOT EXISTS franchise_fees DECIMAL(12,2) DEFAULT NULL");
                } catch (Exception $e) {
                }
                try {
                    $pdo->exec("ALTER TABLE atc_centers ADD COLUMN IF NOT EXISTS login_username VARCHAR(100) DEFAULT NULL");
                } catch (Exception $e) {
                }
                try {
                    $pdo->exec("ALTER TABLE atc_centers ADD COLUMN IF NOT EXISTS login_password VARCHAR(100) DEFAULT NULL");
                } catch (Exception $e) {
                }

                try { $pdo->exec("ALTER TABLE atc_centers ADD COLUMN IF NOT EXISTS alternate_mobile VARCHAR(15) DEFAULT NULL"); } catch (Exception $e) {}
                try { $pdo->exec("ALTER TABLE atc_centers ADD COLUMN IF NOT EXISTS date_created DATE DEFAULT NULL"); } catch (Exception $e) {}
                try { $pdo->exec("ALTER TABLE atc_centers ADD COLUMN IF NOT EXISTS city VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}

                $rawFees = $_POST['franchise_fees'] ?? '';
                $franchiseFees = ($rawFees !== '' && $rawFees !== null) ? floatval($rawFees) : null;
                $tempPassword = 'password'; // temporary — ATC must change after first login
                $atcCode = null;

                $stmt = $pdo->prepare("
                    INSERT INTO atc_centers (name, district, state, taluka, city, pin_code, center_type, dlc_id,
                        contact_person, email, mobile, alternate_mobile, address, dob,
                        date_created, authorization_expires_at, franchise_fees, login_username, login_password, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?)
                ");
                $stmt->execute([
                    $_POST['name'],
                    $_POST['district'],
                    $_POST['state'],
                    $_POST['taluka'],
                    $_POST['city'] ?: null,
                    $_POST['pin_code'],
                    $_POST['center_type'],
                    $_POST['dlc_id'] ?: null,
                    $_POST['contact_person'] ?: null,
                    $_POST['email'] ?: null,
                    $_POST['mobile'] ?: null,
                    $_POST['alternate_mobile'] ?: null,
                    $_POST['address'] ?: null,
                    !empty($_POST['dob']) ? $_POST['dob'] : null,
                    !empty($_POST['date_created']) ? $_POST['date_created'] : date('Y-m-d'),
                    !empty($_POST['authorization_expires_at']) ? $_POST['authorization_expires_at'] : null,
                    $franchiseFees,
                    $_POST['status']
                ]);
                $newId = $pdo->lastInsertId();

                // Auto-generate ATC code: YYYY + 5-digit sequence e.g. 202500001
                // Username = ATC code, temporary password = "password"
                try {
                    $colChk = $pdo->query("SHOW COLUMNS FROM atc_centers LIKE 'atc_code'")->fetch();
                    if (!$colChk) {
                        $pdo->exec("ALTER TABLE atc_centers ADD COLUMN atc_code VARCHAR(20) DEFAULT NULL AFTER id");
                    }
                    $atcYear = date('Y');
                    $atcCode = $atcYear . str_pad($newId, 5, '0', STR_PAD_LEFT);
                } catch (Exception $e) {
                    $atcCode = date('Y') . str_pad($newId, 5, '0', STR_PAD_LEFT);
                }

                $loginUser = $atcCode;
                $loginPass = $tempPassword;

                // Ensure username (ATC code) is unique in users
                $dupChk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $dupChk->execute([$loginUser]);
                if ($dupChk->fetch()) {
                    $pdo->prepare("DELETE FROM atc_centers WHERE id = ?")->execute([$newId]);
                    echo json_encode(['success' => false, 'message' => 'Username/ATC code "' . $loginUser . '" already exists as a login. Contact support.']);
                    exit;
                }

                $pdo->prepare("UPDATE atc_centers SET atc_code = ?, login_username = ?, login_password = ? WHERE id = ?")
                    ->execute([$atcCode, $loginUser, $loginPass, $newId]);

                // Auto-create user account for ATC login
                try {
                    $pdo->prepare("INSERT INTO users (username, password, role, name, email, mobile, atc_id, dlc_id, status) VALUES (?, ?, 'ATC CENTER', ?, ?, ?, ?, ?, 'Active')")
                        ->execute([$loginUser, $loginPass, $_POST['contact_person'] ?: $_POST['name'], $_POST['email'] ?: null, $_POST['mobile'] ?: null, $newId, $_POST['dlc_id'] ?: null]);
                    if (function_exists('syncPortalUserToExam')) {
                        syncPortalUserToExam($loginUser, $_POST['contact_person'] ?: $_POST['name'], $_POST['email'] ?: null, $loginPass, 'ATC CENTER', $atcCode);
                    }
                } catch (Exception $e) { /* user creation is non-fatal */
                }

                // 🎬 Auto-create Training login if requested
                if (!empty($_POST['create_training'])) {
                    $useSame = !empty($_POST['same_training_creds']);
                    $tUser = $useSame ? $loginUser : trim($_POST['training_username'] ?? '');
                    $tPass = $useSame ? $loginPass : trim($_POST['training_password'] ?? '');
                    if ($tUser && $tPass) {
                        try {
                            $pdo->prepare("INSERT INTO users (username, password, role, name, email, mobile, atc_id, status) VALUES (?, ?, 'Training', ?, ?, ?, ?, 'Active')")
                                ->execute([$tUser, $tPass, ($_POST['contact_person'] ?: $_POST['name']) . ' (Training)', $_POST['email'] ?: null, $_POST['mobile'] ?: null, $newId]);
                        } catch (Exception $e) { /* non-fatal */ }
                    }
                }

                // Update DLC atc_count
                if (!empty($_POST['dlc_id'])) {
                    $pdo->prepare("UPDATE dlc_offices SET atc_count = (SELECT COUNT(*) FROM atc_centers WHERE dlc_id = ?) WHERE id = ?")
                        ->execute([$_POST['dlc_id'], $_POST['dlc_id']]);
                }
                echo json_encode([
                    'success'  => true,
                    'message'  => 'ATC Center added — Username: ' . $loginUser . ' | Temp password: password',
                    'atc_code' => $atcCode,
                    'username' => $loginUser,
                    'password' => $loginPass,
                ]);
                // 🔄 Sync ATC centres to Exam Portal
                if (function_exists('syncATCCentresToExamPortal')) {
                    syncATCCentresToExamPortal($pdo);
                }
                exit;

            case 'edit':
                try {
                    $pdo->exec("ALTER TABLE atc_centers ADD COLUMN IF NOT EXISTS franchise_fees DECIMAL(12,2) DEFAULT NULL");
                } catch (Exception $e) {
                }
                try {
                    $pdo->exec("ALTER TABLE atc_centers ADD COLUMN IF NOT EXISTS login_username VARCHAR(100) DEFAULT NULL");
                } catch (Exception $e) {
                }
                try {
                    $pdo->exec("ALTER TABLE atc_centers ADD COLUMN IF NOT EXISTS login_password VARCHAR(100) DEFAULT NULL");
                } catch (Exception $e) {
                }
                try { $pdo->exec("ALTER TABLE atc_centers ADD COLUMN IF NOT EXISTS alternate_mobile VARCHAR(15) DEFAULT NULL"); } catch (Exception $e) {}
                try { $pdo->exec("ALTER TABLE atc_centers ADD COLUMN IF NOT EXISTS date_created DATE DEFAULT NULL"); } catch (Exception $e) {}
                try { $pdo->exec("ALTER TABLE atc_centers ADD COLUMN IF NOT EXISTS city VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}

                $rawFees = $_POST['franchise_fees'] ?? '';
                $franchiseFees = ($rawFees !== '' && $rawFees !== null) ? floatval($rawFees) : null;
                $editId = intval($_POST['id']);

                // Credentials: username is always the ATC code; password optional on edit
                $atcCodeRow = $pdo->prepare("SELECT atc_code FROM atc_centers WHERE id = ?");
                $atcCodeRow->execute([$editId]);
                $currentAtcCode = (string)($atcCodeRow->fetchColumn() ?: '');
                if ($currentAtcCode === '') {
                    $currentAtcCode = date('Y') . str_pad($editId, 5, '0', STR_PAD_LEFT);
                    try {
                        $pdo->prepare("UPDATE atc_centers SET atc_code = ? WHERE id = ?")->execute([$currentAtcCode, $editId]);
                    } catch (Exception $e) { /* non-fatal */ }
                }
                $newLoginUser = $currentAtcCode; // username must equal ATC code
                $newLoginPass = trim($_POST['login_password'] ?? '');

                // Keep existing password if the field was cleared
                if ($newLoginPass === '') {
                    $curPassStmt = $pdo->prepare("SELECT login_password FROM atc_centers WHERE id = ?");
                    $curPassStmt->execute([$editId]);
                    $newLoginPass = (string)($curPassStmt->fetchColumn() ?: '');
                }

                // Check username uniqueness if we are syncing/creating user
                $oldUser = $pdo->prepare("SELECT login_username FROM atc_centers WHERE id = ?");
                $oldUser->execute([$editId]);
                $oldUsername = $oldUser->fetchColumn();
                if ($newLoginUser !== $oldUsername) {
                    $dupChk = $pdo->prepare("SELECT id FROM users WHERE username = ? AND (atc_id IS NULL OR atc_id <> ?)");
                    $dupChk->execute([$newLoginUser, $editId]);
                    if ($dupChk->fetch()) {
                        echo json_encode(['success' => false, 'message' => 'Username "' . $newLoginUser . '" already exists. Choose a different username.']);
                        exit;
                    }
                }

                $stmt = $pdo->prepare("
                    UPDATE atc_centers
                    SET name=?, district=?, state=?, taluka=?, city=?, pin_code=?, center_type=?, dlc_id=?,
                        contact_person=?, email=?, mobile=?, alternate_mobile=?, address=?,
                        dob=?, date_created=?, authorization_expires_at=?, franchise_fees=?,
                        login_username=?,
                        login_password=?,
                        status=?
                    WHERE id=?
                ");
                $stmt->execute([
                    $_POST['name'],
                    $_POST['district'],
                    $_POST['state'],
                    $_POST['taluka'],
                    $_POST['city'] ?: null,
                    $_POST['pin_code'],
                    $_POST['center_type'],
                    $_POST['dlc_id'] ?: null,
                    $_POST['contact_person'] ?: null,
                    $_POST['email'] ?: null,
                    $_POST['mobile'] ?: null,
                    $_POST['alternate_mobile'] ?: null,
                    $_POST['address'] ?: null,
                    !empty($_POST['dob']) ? $_POST['dob'] : null,
                    !empty($_POST['date_created']) ? $_POST['date_created'] : null,
                    !empty($_POST['authorization_expires_at']) ? $_POST['authorization_expires_at'] : null,
                    $franchiseFees,
                    $newLoginUser,
                    $newLoginPass !== '' ? $newLoginPass : null,
                    $_POST['status'],
                    $editId
                ]);

                // Also update / create the linked users record
                try {
                    $uChk = $pdo->prepare("SELECT id FROM users WHERE atc_id=? AND role='ATC CENTER' LIMIT 1");
                    $uChk->execute([$editId]);
                    $uId = $uChk->fetchColumn();
                    if ($uId) {
                        if ($newLoginPass !== '') {
                            $pdo->prepare("UPDATE users SET username=?, password=? WHERE id=?")
                                ->execute([$newLoginUser, $newLoginPass, $uId]);
                        } else {
                            $pdo->prepare("UPDATE users SET username=? WHERE id=?")
                                ->execute([$newLoginUser, $uId]);
                        }
                    } else {
                        $passForCreate = $newLoginPass !== '' ? $newLoginPass : 'password';
                        $pdo->prepare("INSERT INTO users (username, password, role, name, email, mobile, atc_id, dlc_id, status) VALUES (?, ?, 'ATC CENTER', ?, ?, ?, ?, ?, 'Active')")
                            ->execute([$newLoginUser, $passForCreate, $_POST['contact_person'] ?: $_POST['name'], $_POST['email'] ?: null, $_POST['mobile'] ?: null, $editId, $_POST['dlc_id'] ?: null]);
                        if ($newLoginPass === '') {
                            $pdo->prepare("UPDATE atc_centers SET login_password = ? WHERE id = ?")->execute([$passForCreate, $editId]);
                        }
                    }
                    if (function_exists('syncPortalUserToExam')) {
                        syncPortalUserToExam($newLoginUser, $_POST['contact_person'] ?: $_POST['name'], $_POST['email'] ?: null, $newLoginPass !== '' ? $newLoginPass : null, 'ATC CENTER', $currentAtcCode);
                    }
                } catch (Exception $e) { /* non-fatal */
                }

                // 🎬 Create/Update Training login
                if (!empty($_POST['create_training'])) {
                    $useSame = !empty($_POST['same_training_creds']);
                    $tUser = $useSame ? $newLoginUser : trim($_POST['training_username'] ?? '');
                    $tPass = trim($_POST['training_password'] ?? '');
                    if ($useSame) {
                        if ($newLoginPass !== '') {
                            $tPass = $newLoginPass;
                        } else {
                            $curPassStmt = $pdo->prepare("SELECT login_password FROM atc_centers WHERE id = ?");
                            $curPassStmt->execute([$editId]);
                            $tPass = (string)($curPassStmt->fetchColumn() ?: 'password');
                        }
                    }
                    if ($tUser) {
                        try {
                            $chk = $pdo->prepare("SELECT id FROM users WHERE role='Training' AND atc_id=?");
                            $chk->execute([$editId]);
                            $existing = $chk->fetch(PDO::FETCH_ASSOC);
                            $tName = ($_POST['contact_person'] ?: $_POST['name']) . ' (Training)';
                            if ($existing) {
                                if ($tPass) {
                                    $pdo->prepare("UPDATE users SET username=?,password=?,name=?,status='Active' WHERE id=?")->execute([$tUser,$tPass,$tName,$existing['id']]);
                                } else {
                                    $pdo->prepare("UPDATE users SET username=?,name=?,status='Active' WHERE id=?")->execute([$tUser,$tName,$existing['id']]);
                                }
                            } elseif ($tPass) {
                                $pdo->prepare("INSERT INTO users (username,password,role,name,email,mobile,atc_id,status) VALUES (?,?,'Training',?,?,?,?,'Active')")
                                    ->execute([$tUser,$tPass,$tName,$_POST['email']?:null,$_POST['mobile']?:null,$editId]);
                            }
                        } catch (Exception $e) { /* non-fatal */ }
                    }
                }

                // Update DLC atc_count for old and new dlc
                if (!empty($_POST['dlc_id'])) {
                    $pdo->prepare("UPDATE dlc_offices SET atc_count = (SELECT COUNT(*) FROM atc_centers WHERE dlc_id = id)")->execute();
                }
                echo json_encode(['success' => true, 'message' => 'ATC Center updated successfully']);
                // 🔄 Sync ATC centres to Exam Portal
                if (function_exists('syncATCCentresToExamPortal')) {
                    syncATCCentresToExamPortal($pdo);
                }
                exit;

            case 'delete':
                $stmt = $pdo->prepare("SELECT dlc_id FROM atc_centers WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $pdo->prepare("DELETE FROM atc_centers WHERE id = ?")->execute([$_POST['id']]);
                if (!empty($row['dlc_id'])) {
                    $pdo->prepare("UPDATE dlc_offices SET atc_count = (SELECT COUNT(*) FROM atc_centers WHERE dlc_id = ?) WHERE id = ?")
                        ->execute([$row['dlc_id'], $row['dlc_id']]);
                }
                echo json_encode(['success' => true, 'message' => 'ATC Center deleted']);
                // 🔄 Sync ATC centres to Exam Portal
                if (function_exists('syncATCCentresToExamPortal')) {
                    syncATCCentresToExamPortal($pdo);
                }
                exit;

            case 'renew':
                $atcId = (int) $_POST['id'];
                // Get current expiry
                $row = $pdo->prepare("SELECT authorization_expires_at FROM atc_centers WHERE id = ?");
                $row->execute([$atcId]);
                $current = $row->fetchColumn();
                // Base from current expiry if in the future, else from today
                $base = (!empty($current) && strtotime($current) > time())
                    ? $current
                    : date('Y-m-d');
                $newExpiry = date('Y-m-d', strtotime('+1 year', strtotime($base)));
                $pdo->prepare("UPDATE atc_centers SET authorization_expires_at = ? WHERE id = ?")
                    ->execute([$newExpiry, $atcId]);
                echo json_encode([
                    'success' => true,
                    'message' => 'Authorization renewed until ' . date('d F Y', strtotime($newExpiry)),
                    'new_expiry' => $newExpiry
                ]);
                exit;

            case 'get':
                $stmt = $pdo->prepare("
                    SELECT atc.*, dlc.name as dlc_name FROM atc_centers atc
                    LEFT JOIN dlc_offices dlc ON atc.dlc_id = dlc.id
                    WHERE atc.id = ?
                ");
                $stmt->execute([$_POST['atc_id']]);
                $atcData = $stmt->fetch(PDO::FETCH_ASSOC);
                // Also fetch training user info
                $atcData['training_user'] = null;
                if ($atcData) {
                    $tStmt = $pdo->prepare("SELECT id, username FROM users WHERE role='Training' AND atc_id=? LIMIT 1");
                    $tStmt->execute([$_POST['atc_id']]);
                    $atcData['training_user'] = $tStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                echo json_encode(['success' => true, 'data' => $atcData]);
                exit;

            case 'get_details': {
                $atcId = $_POST['atc_id'];

                // Get ATC basic info
                $stmt = $pdo->prepare("
                SELECT atc.*, dlc.name as dlc_name, dlc.district as dlc_district
                FROM atc_centers atc
                LEFT JOIN dlc_offices dlc ON atc.dlc_id = dlc.id
                WHERE atc.id = ?
            ");
                $stmt->execute([$atcId]);
                $atcInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                // Get inquiry statistics
                // Check if inquiry_type column exists
                $checkColumn = $pdo->query("SHOW COLUMNS FROM inquiries LIKE 'inquiry_type'")->fetch();

                if ($checkColumn) {
                    // Column exists, use it
                    $stmt = $pdo->prepare("
                    SELECT 
                        COUNT(*) as total_inquiries,
                        SUM(CASE WHEN status = 'New' THEN 1 ELSE 0 END) as pending_inquiries,
                        SUM(CASE WHEN status = 'Contacted' THEN 1 ELSE 0 END) as contacted_inquiries,
                        SUM(CASE WHEN status = 'Converted' THEN 1 ELSE 0 END) as converted_inquiries,
                        SUM(CASE WHEN inquiry_type = 'Walk-in' THEN 1 ELSE 0 END) as walkin_inquiries,
                        SUM(CASE WHEN inquiry_type = 'Telephonic' THEN 1 ELSE 0 END) as telephonic_inquiries
                    FROM inquiries
                    WHERE atc_id = ?
                ");
                    $stmt->execute([$atcId]);
                    $inquiryStats = $stmt->fetch(PDO::FETCH_ASSOC);

                    $telephonicStats = [
                        'total_telephonic' => $inquiryStats['telephonic_inquiries'] ?? 0,
                        'pending_telephonic' => 0,
                        'followup_telephonic' => 0,
                        'converted_telephonic' => 0
                    ];
                } else {
                    // Column doesn't exist, use simple query
                    $stmt = $pdo->prepare("
                    SELECT 
                        COUNT(*) as total_inquiries,
                        SUM(CASE WHEN status = 'New' THEN 1 ELSE 0 END) as pending_inquiries,
                        SUM(CASE WHEN status = 'Contacted' THEN 1 ELSE 0 END) as contacted_inquiries,
                        SUM(CASE WHEN status = 'Converted' THEN 1 ELSE 0 END) as converted_inquiries
                    FROM inquiries
                    WHERE atc_id = ?
                ");
                    $stmt->execute([$atcId]);
                    $inquiryStats = $stmt->fetch(PDO::FETCH_ASSOC);

                    $telephonicStats = [
                        'total_telephonic' => 0,
                        'pending_telephonic' => 0,
                        'followup_telephonic' => 0,
                        'converted_telephonic' => 0
                    ];
                }

                // Get admission statistics
                $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_admissions,
                    SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_students,
                    SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_students,
                    SUM(CASE WHEN status = 'Inactive' THEN 1 ELSE 0 END) as inactive_students,
                    COALESCE(SUM(course_fees - discount_amount), 0) as total_fees,
                    COALESCE(SUM(fees_paid), 0) as total_collected,
                    COALESCE(SUM(fees_pending), 0) as total_pending
                FROM admissions
                WHERE atc_id = ?
            ");
                $stmt->execute([$atcId]);
                $admissionStats = $stmt->fetch(PDO::FETCH_ASSOC);

                // Get recent students — prefer registration_id as identifier
                $checkColumns = $pdo->query("SHOW COLUMNS FROM admissions")->fetchAll(PDO::FETCH_COLUMN);

                $hasRegistrationId = in_array('registration_id', $checkColumns);
                $hasRollNo = in_array('roll_no', $checkColumns);
                $hasFirstName = in_array('first_name', $checkColumns);
                $hasCourse = in_array('course', $checkColumns);

                // Registration ID is primary — fallback to roll_no, then DB id
                $studentIdCol = $hasRegistrationId ? 'registration_id'
                    : ($hasRollNo ? 'roll_no' : 'id');
                $studentNameCol = $hasFirstName
                    ? "CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name)"
                    : "'Unknown'";
                $courseCol = $hasCourse ? 'course' : "'N/A'";

                $stmt = $pdo->prepare("
                SELECT {$studentIdCol} as student_id,
                       roll_no,
                       {$studentNameCol} as student_name,
                       {$courseCol} as course_name,
                       admission_date, status,
                       course_fees, fees_paid, fees_pending
                FROM admissions
                WHERE atc_id = ?
                ORDER BY admission_date DESC
                LIMIT 10
            ");
                $stmt->execute([$atcId]);
                $recentStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Get course-wise breakdown
                $stmt = $pdo->prepare("
                SELECT {$courseCol} as course_name, 
                       COUNT(*) as student_count,
                       SUM(course_fees - discount_amount) as total_fees,
                       SUM(fees_paid) as collected,
                       SUM(fees_pending) as pending
                FROM admissions
                WHERE atc_id = ? AND status = 'Active'
                GROUP BY {$courseCol}
                ORDER BY student_count DESC
            ");
                $stmt->execute([$atcId]);
                $courseBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'atcInfo' => $atcInfo,
                    'inquiryStats' => $inquiryStats,
                    'telephonicStats' => $telephonicStats,
                    'admissionStats' => $admissionStats,
                    'recentStudents' => $recentStudents,
                    'courseBreakdown' => $courseBreakdown
                ]);
                exit;
            } // end case get_details
        } // end switch
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Fetch ATC centers with statistics
$searchTerm = $_GET['search'] ?? '';
$dlcFilter = $_GET['dlc'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';

$sql = "SELECT 
            atc.*,
            dlc.name as dlc_name,
            dlc.district as dlc_district,
            COUNT(DISTINCT adm.id) as total_students,
            COALESCE(sp_agg.total_share_received, 0) as total_share_received,
            COALESCE(sp_agg.reported_student_count, 0) as reported_students
        FROM atc_centers atc
        LEFT JOIN dlc_offices dlc ON atc.dlc_id = dlc.id
        LEFT JOIN admissions adm ON atc.id = adm.atc_id AND adm.status = 'Active'
        LEFT JOIN (
            SELECT atc_id,
                   SUM(total_share_amount) as total_share_received,
                   SUM(JSON_LENGTH(student_ids)) as reported_student_count
            FROM share_payments
            WHERE status = 'Completed'
            GROUP BY atc_id
        ) sp_agg ON sp_agg.atc_id = atc.id
        WHERE 1=1";
$params = [];

if ($searchTerm) {
    $sql .= " AND (atc.name LIKE ? OR atc.district LIKE ? OR dlc.name LIKE ?)";
    $searchParam = "%$searchTerm%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam]);
}

if ($dlcFilter !== 'all') {
    $sql .= " AND atc.dlc_id = ?";
    $params[] = $dlcFilter;
}

if ($statusFilter !== 'all') {
    $sql .= " AND atc.status = ?";
    $params[] = $statusFilter;
}

// Count matching ATCs for pagination
$countSql = "SELECT COUNT(*) FROM atc_centers atc
             LEFT JOIN dlc_offices dlc ON atc.dlc_id = dlc.id
             WHERE 1=1";
$countParams = [];
if ($searchTerm) {
    $countSql .= " AND (atc.name LIKE ? OR atc.district LIKE ? OR dlc.name LIKE ?)";
    $sp = "%$searchTerm%";
    $countParams = [$sp, $sp, $sp];
}
if ($dlcFilter !== 'all') {
    $countSql .= " AND atc.dlc_id = ?";
    $countParams[] = $dlcFilter;
}
if ($statusFilter !== 'all') {
    $countSql .= " AND atc.status = ?";
    $countParams[] = $statusFilter;
}
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($countParams);
$pager = paginationMeta((int)$countStmt->fetchColumn(), paginationParams(25));

$sql .= " GROUP BY atc.id ORDER BY atc.name ASC
          LIMIT {$pager['per_page']} OFFSET {$pager['offset']}";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$atcCenters = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build training user lookup (atc_id => {username, password})
$trainingLookup = [];
try {
    $tStmt = $pdo->query("SELECT atc_id, username, password FROM users WHERE role='Training' AND status='Active'");
    foreach ($tStmt as $t) {
        $trainingLookup[$t['atc_id']] = $t;
    }
} catch (Exception $e) { /* non-fatal */ }

// Get DLC offices for filter
$stmt = $pdo->query("SELECT id, name FROM dlc_offices WHERE status = 'Active' ORDER BY name");
$dlcOffices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts
$stmt = $pdo->query("SELECT COUNT(*) FROM atc_centers");
$totalCount = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM atc_centers WHERE status = 'Active'");
$activeCount = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM atc_centers WHERE status = 'Inactive'");
$inactiveCount = $stmt->fetchColumn();

// Get overall statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(DISTINCT atc.id) as total_centers,
        COUNT(DISTINCT adm.id) as total_students,
        COALESCE(sp_total.total_received, 0) as total_share_received,
        COALESCE(sp_total.total_reported, 0) as total_reported
    FROM atc_centers atc
    LEFT JOIN admissions adm ON atc.id = adm.atc_id AND adm.status = 'Active'
    LEFT JOIN (
        SELECT SUM(total_share_amount) as total_received,
               SUM(JSON_LENGTH(student_ids)) as total_reported
        FROM share_payments WHERE status = 'Completed'
    ) sp_total ON 1=1
    WHERE atc.status = 'Active'
");
$overallStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Calculate total expected share across all ATCs
try {
    $totalExpStmt = $pdo->query("
        SELECT COALESCE(SUM(
            CASE WHEN a.ho_share_snapshot IS NOT NULL AND a.ho_share_snapshot > 0
                 THEN a.ho_share_snapshot
                 ELSE COALESCE(c.ho_share, 0)
            END
        ), 0) as total_expected
        FROM admissions a
        LEFT JOIN courses c ON a.course = c.course_name AND c.status = 'Active'
        WHERE a.status = 'Active'
    ");
    $totalShareExpected = (float) $totalExpStmt->fetchColumn();
} catch (Exception $e) {
    $totalShareExpected = 0;
}
$totalSharePending = max(0, $totalShareExpected - (float) $overallStats['total_share_received']);

// ── Backfill atc_code for existing rows that don't have one ──
try {
    $colChkPage = $pdo->query("SHOW COLUMNS FROM atc_centers LIKE 'atc_code'")->fetch();
    if (!$colChkPage) {
        $pdo->exec("ALTER TABLE atc_centers ADD COLUMN atc_code VARCHAR(20) DEFAULT NULL AFTER id");
    }
    // Backfill: set missing codes to YYYY + 5-digit-padded id
    $pdo->exec("UPDATE atc_centers SET atc_code = CONCAT(YEAR(IFNULL(created_at, NOW())), LPAD(id, 5, '0')) WHERE atc_code IS NULL OR atc_code = '' OR atc_code LIKE 'GATC%'");
} catch (Exception $e) { /* non-fatal */
}
// ── Ensure new columns exist ──
try {
    $pdo->exec("ALTER TABLE atc_centers ADD COLUMN IF NOT EXISTS franchise_fees DECIMAL(12,2) DEFAULT NULL");
} catch (Exception $e) {
}
try {
    $pdo->exec("ALTER TABLE atc_centers ADD COLUMN IF NOT EXISTS login_username VARCHAR(100) DEFAULT NULL");
} catch (Exception $e) {
}
try {
    $pdo->exec("ALTER TABLE atc_centers ADD COLUMN IF NOT EXISTS login_password VARCHAR(100) DEFAULT NULL");
} catch (Exception $e) {
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ATCs — Admin | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon"
        href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🏫</text></svg>">
    <style>
        /* ═══════════════════════════════════════════
       DESIGN TOKENS
    ═══════════════════════════════════════════ */
        :root {
            --font: 'Sora', sans-serif;
            --mono: 'JetBrains Mono', monospace;
            --bg: #f4f6fb;
            --surface: #ffffff;
            --surface-2: #f9fafc;
            --surface-3: #f0f3f9;
            --border: #e6eaf3;
            --border-2: #d4dae8;
            --text: #111827;
            --text-2: #374151;
            --text-3: #6b7280;
            --text-4: #9ca3af;
            --brand: #4361ee;
            --brand-dark: #3451d1;
            --brand-light: #eef1fd;
            --brand-glow: rgba(67, 97, 238, .18);
            --violet: #7c3aed;
            --violet-soft: #f5f3ff;
            --sky: #0ea5e9;
            --sky-soft: #f0f9ff;
            --emerald: #10b981;
            --emerald-dark: #059669;
            --emerald-soft: #ecfdf5;
            --amber: #f59e0b;
            --amber-dark: #d97706;
            --amber-soft: #fffbeb;
            --rose: #f43f5e;
            --rose-soft: #fff1f3;
            --shadow-xs: 0 1px 2px rgba(0, 0, 0, .05);
            --shadow-sm: 0 1px 4px rgba(0, 0, 0, .06), 0 2px 8px rgba(0, 0, 0, .04);
            --shadow-md: 0 4px 16px rgba(0, 0, 0, .08), 0 2px 6px rgba(0, 0, 0, .04);
            --shadow-lg: 0 20px 60px rgba(0, 0, 0, .12), 0 8px 20px rgba(0, 0, 0, .06);
            --shadow-brand: 0 6px 20px var(--brand-glow);
            --r-xs: 4px;
            --r-sm: 6px;
            --r-md: 10px;
            --r-lg: 14px;
            --r-xl: 18px;
            --r-2xl: 24px;
            --r-full: 9999px;
            --t: .18s ease;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .page-content {
            padding: 1.75rem 2rem;
        }

        /* ── Page header ── */
        .page-header-block {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1.75rem;
        }

        .page-header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .page-header-icon {
            width: 50px;
            height: 50px;
            border-radius: var(--r-lg);
            background: linear-gradient(135deg, var(--brand), var(--violet));
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-brand);
            flex-shrink: 0;
        }

        .page-header-icon svg {
            width: 24px;
            height: 24px;
            stroke: white;
            fill: none;
        }

        .page-header-title {
            font-size: 1.375rem;
            font-weight: 800;
            color: var(--text);
            letter-spacing: -.03em;
        }

        .page-header-subtitle {
            font-size: .8125rem;
            color: var(--text-3);
            margin-top: .2rem;
        }

        /* ── KPI Grid ── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.75rem;
        }

        .kpi-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--r-xl);
            padding: 1.375rem 1.5rem 1.25rem;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: transform var(--t), box-shadow var(--t);
            cursor: default;
        }

        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            width: 4px;
            border-radius: var(--r-xl) 0 0 var(--r-xl);
        }

        .kpi-card::after {
            content: '';
            position: absolute;
            right: -20px;
            top: -20px;
            width: 90px;
            height: 90px;
            border-radius: 50%;
            opacity: .055;
            pointer-events: none;
        }

        .kpi-card.brand::before {
            background: linear-gradient(180deg, var(--brand), #818cf8);
        }

        .kpi-card.emerald::before {
            background: linear-gradient(180deg, var(--emerald), #34d399);
        }

        .kpi-card.amber::before {
            background: linear-gradient(180deg, var(--amber), #fcd34d);
        }

        .kpi-card.sky::before {
            background: linear-gradient(180deg, var(--sky), #38bdf8);
        }

        .kpi-card.brand::after {
            background: var(--brand);
        }

        .kpi-card.emerald::after {
            background: var(--emerald);
        }

        .kpi-card.amber::after {
            background: var(--amber);
        }

        .kpi-card.sky::after {
            background: var(--sky);
        }

        .kpi-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: .875rem;
        }

        .kpi-icon {
            width: 42px;
            height: 42px;
            border-radius: var(--r-md);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .kpi-icon svg {
            width: 20px;
            height: 20px;
            fill: none;
        }

        .kpi-icon.brand {
            background: var(--brand-light);
        }

        .kpi-icon.brand svg {
            stroke: var(--brand);
        }

        .kpi-icon.emerald {
            background: var(--emerald-soft);
        }

        .kpi-icon.emerald svg {
            stroke: var(--emerald-dark);
        }

        .kpi-icon.amber {
            background: var(--amber-soft);
        }

        .kpi-icon.amber svg {
            stroke: var(--amber-dark);
        }

        .kpi-icon.sky {
            background: var(--sky-soft);
        }

        .kpi-icon.sky svg {
            stroke: var(--sky);
        }

        .kpi-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--text);
            letter-spacing: -.05em;
            line-height: 1;
        }

        .kpi-label {
            font-size: .72rem;
            font-weight: 600;
            color: var(--text-3);
            text-transform: uppercase;
            letter-spacing: .07em;
            margin-top: .375rem;
        }

        /* ── Status tabs ── */
        .status-tabs {
            display: flex;
            gap: .375rem;
            margin-bottom: 1.5rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--r-xl);
            padding: .375rem;
            box-shadow: var(--shadow-sm);
            width: fit-content;
        }

        .status-tab {
            display: flex;
            align-items: center;
            gap: .5rem;
            padding: .625rem 1.125rem;
            border-radius: var(--r-lg);
            font-size: .8375rem;
            font-weight: 600;
            color: var(--text-2);
            text-decoration: none;
            transition: all var(--t);
            white-space: nowrap;
        }

        .status-tab:hover {
            background: var(--surface-2);
            color: var(--text);
        }

        .status-tab.active {
            background: var(--brand);
            color: white;
            box-shadow: 0 4px 12px var(--brand-glow);
        }

        .status-tab .tab-pill {
            padding: .15rem .5rem;
            border-radius: var(--r-full);
            font-size: .72rem;
            font-weight: 800;
            background: rgba(255, 255, 255, .22);
        }

        .status-tab:not(.active) .tab-pill {
            background: var(--border);
            color: var(--text-3);
        }

        /* ── Toolbar ── */
        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .toolbar-left {
            display: flex;
            align-items: center;
            gap: .625rem;
        }

        .toolbar-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text);
            letter-spacing: -.02em;
        }

        .toolbar-count {
            font-size: .72rem;
            font-weight: 700;
            color: var(--text-4);
            background: var(--surface-3);
            border: 1px solid var(--border);
            padding: .175rem .55rem;
            border-radius: var(--r-full);
        }

        .toolbar-right {
            display: flex;
            align-items: center;
            gap: .625rem;
        }

        .select-sm {
            padding: .65rem 2.25rem .65rem .875rem;
            border: 1.5px solid var(--border);
            border-radius: var(--r-md);
            font-size: .85rem;
            font-family: var(--font);
            font-weight: 500;
            background: var(--surface);
            color: var(--text);
            outline: none;
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right .625rem center;
            background-size: 13px;
            transition: border-color var(--t);
        }

        .select-sm:focus {
            border-color: var(--brand);
        }

        .search-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-icon {
            position: absolute;
            left: .875rem;
            width: 15px;
            height: 15px;
            stroke: var(--text-4);
            fill: none;
            pointer-events: none;
        }

        .search-input {
            padding: .65rem .875rem .65rem 2.4rem;
            border: 1.5px solid var(--border);
            border-radius: var(--r-md);
            font-size: .85rem;
            font-family: var(--font);
            font-weight: 500;
            background: var(--surface);
            color: var(--text);
            outline: none;
            width: 230px;
            transition: border-color var(--t), box-shadow var(--t);
        }

        .search-input:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px var(--brand-glow);
        }

        .search-input::placeholder {
            color: var(--text-4);
        }

        .btn-search {
            padding: .65rem 1.125rem;
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--r-md);
            font-size: .85rem;
            font-weight: 600;
            font-family: var(--font);
            color: var(--text-2);
            cursor: pointer;
            transition: all var(--t);
        }

        .btn-search:hover {
            border-color: var(--brand);
            color: var(--brand);
            background: var(--brand-light);
        }

        /* ── Table ── */
        .table-wrap {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--r-xl);
            box-shadow: var(--shadow-sm);
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .table-wrap::-webkit-scrollbar {
            height: 6px;
        }
        .table-wrap::-webkit-scrollbar-track {
            background: var(--surface-2);
            border-radius: 0 0 var(--r-xl) var(--r-xl);
        }
        .table-wrap::-webkit-scrollbar-thumb {
            background: var(--border-2);
            border-radius: var(--r-full);
        }
        .table-wrap::-webkit-scrollbar-thumb:hover {
            background: var(--text-4);
        }

        .data-table {
            width: 100%;
            min-width: 1100px;
            border-collapse: collapse;
            font-size: .875rem;
        }

        .data-table thead {
            background: var(--surface-2);
            border-bottom: 1px solid var(--border);
        }

        .data-table thead th {
            padding: .875rem 1.25rem;
            text-align: left;
            font-size: .7rem;
            font-weight: 700;
            color: var(--text-4);
            text-transform: uppercase;
            letter-spacing: .07em;
            white-space: nowrap;
        }

        .data-table tbody tr {
            border-bottom: 1px solid var(--surface-3);
            transition: background var(--t);
        }

        .data-table tbody tr:last-child {
            border-bottom: none;
        }

        .data-table tbody tr:hover {
            background: #fafbff;
        }

        .data-table tbody td {
            padding: .9375rem 1.25rem;
            vertical-align: middle;
        }

        /* Center cell */
        .center-cell {
            display: flex;
            align-items: center;
            gap: .875rem;
        }

        .center-avatar {
            width: 38px;
            height: 38px;
            border-radius: var(--r-md);
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .875rem;
            font-weight: 800;
            color: white;
            background: linear-gradient(135deg, var(--brand), var(--violet));
            box-shadow: 0 2px 6px var(--brand-glow);
            overflow: hidden;
        }

        .center-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .center-name {
            font-size: .875rem;
            font-weight: 700;
            color: var(--text);
        }

        .center-location {
            font-size: .775rem;
            color: var(--text-3);
            margin-top: .15rem;
        }

        .cell-main {
            font-weight: 600;
            color: var(--text);
            font-size: .875rem;
        }

        .cell-sub {
            font-size: .775rem;
            color: var(--text-3);
            margin-top: .15rem;
        }

        /* Student count */
        .student-count {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .3rem .75rem;
            border-radius: var(--r-full);
            font-size: .75rem;
            font-weight: 700;
            background: var(--emerald-soft);
            color: var(--emerald-dark);
            border: 1px solid #a7f3d0;
        }

        .student-count svg {
            width: 12px;
            height: 12px;
            stroke: var(--emerald-dark);
            fill: none;
        }

        /* Fee amounts */
        .fee-amount {
            font-weight: 600;
            font-size: .875rem;
            color: var(--text);
        }

        .fee-collected {
            color: var(--emerald-dark);
        }

        .fee-pending {
            color: var(--amber-dark);
        }

        .fee-progress {
            margin-top: .4rem;
            height: 4px;
            background: var(--surface-3);
            border-radius: var(--r-full);
            overflow: hidden;
        }

        .fee-progress-bar {
            height: 100%;
            transition: width .3s ease;
            background: linear-gradient(90deg, var(--emerald), var(--emerald-dark));
        }

        .fee-progress-bar-amber {
            height: 100%;
            transition: width .3s ease;
            background: linear-gradient(90deg, #fbbf24, #d97706);
        }

        .fee-progress-bar-danger {
            height: 100%;
            transition: width .3s ease;
            background: linear-gradient(90deg, #f97316, #ef4444);
        }

        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .3rem .75rem;
            border-radius: var(--r-full);
            font-size: .72rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .status-active {
            background: var(--emerald-soft);
            color: var(--emerald-dark);
            border: 1px solid #a7f3d0;
        }

        .status-active .status-dot {
            background: var(--emerald);
        }

        .status-inactive {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
        }

        .status-inactive .status-dot {
            background: #94a3b8;
        }

        /* Action buttons */
        .actions-wrap {
            display: flex;
            align-items: center;
            gap: .375rem;
        }

        .btn-act {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--r-md);
            border: 1.5px solid var(--border);
            background: var(--surface-2);
            cursor: pointer;
            transition: all var(--t);
        }

        .btn-act svg {
            width: 14px;
            height: 14px;
            stroke: var(--text-3);
            fill: none;
        }

        .btn-act:hover {
            border-color: var(--brand);
            background: var(--brand-light);
        }

        .btn-act:hover svg {
            stroke: var(--brand);
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 4.5rem 2rem;
        }

        .empty-icon {
            width: 56px;
            height: 56px;
            border-radius: var(--r-lg);
            background: var(--surface-2);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.125rem;
        }

        .empty-icon svg {
            width: 26px;
            height: 26px;
            stroke: var(--text-4);
            fill: none;
        }

        .empty-title {
            font-size: .9375rem;
            font-weight: 700;
            color: var(--text-2);
        }

        .empty-sub {
            font-size: .8125rem;
            color: var(--text-3);
            margin-top: .3rem;
        }

        /* ── Responsive ── */
        @media (max-width:1280px) {
            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width:900px) {
            .page-content {
                padding: 1.25rem;
            }
        }

        @media (max-width:768px) {
            .kpi-grid {
                grid-template-columns: 1fr;
            }

            .toolbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .status-tabs {
                overflow-x: auto;
                max-width: 100%;
            }

            .page-header-block {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .search-input {
                width: 100%;
            }
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* ── Modal Styles ── */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 2rem;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-card {
            background: var(--surface);
            border-radius: var(--r-2xl);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 900px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            padding: 1.75rem 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(135deg, var(--surface-2), var(--surface));
            border-radius: var(--r-2xl) var(--r-2xl) 0 0;
        }

        .modal-header h3 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .modal-header h3 svg {
            stroke: var(--brand);
        }

        .modal-close {
            width: 36px;
            height: 36px;
            border: none;
            background: var(--surface-3);
            border-radius: var(--r-md);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all var(--t);
        }

        .modal-close:hover {
            background: var(--rose-soft);
            transform: rotate(90deg);
        }

        .modal-close svg {
            width: 18px;
            height: 18px;
            stroke: var(--text-3);
        }

        .modal-close:hover svg {
            stroke: var(--rose);
        }

        .modal-body {
            padding: 2rem;
            overflow-y: auto;
            flex: 1;
        }

        .modal-body::-webkit-scrollbar {
            width: 8px;
        }

        .modal-body::-webkit-scrollbar-track {
            background: var(--surface-2);
            border-radius: 4px;
        }

        .modal-body::-webkit-scrollbar-thumb {
            background: var(--border-2);
            border-radius: 4px;
        }

        .modal-body::-webkit-scrollbar-thumb:hover {
            background: var(--text-4);
        }

        .modal-footer {
            padding: 1.25rem 2rem;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.75rem;
            background: var(--surface-2);
            border-radius: 0 0 var(--r-2xl) var(--r-2xl);
        }

        .btn-secondary {
            padding: 0.75rem 1.5rem;
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--r-md);
            font-size: 0.875rem;
            font-weight: 600;
            font-family: var(--font);
            color: var(--text-2);
            cursor: pointer;
            transition: all var(--t);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-secondary:hover {
            background: var(--surface-3);
            border-color: var(--border-2);
            color: var(--text);
        }

        /* Form Section in Modal */
        .form-section {
            margin-bottom: 2rem;
        }

        .form-section:last-child {
            margin-bottom: 0;
        }

        .form-section-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--border);
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .form-section-title svg {
            stroke: var(--brand);
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.25rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
        }

        .detail-item.full-width {
            grid-column: 1 / -1;
        }

        .detail-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-3);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .detail-value {
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--text);
        }

        .cell-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.3rem 0.75rem;
            border-radius: var(--r-full);
            font-size: 0.75rem;
            font-weight: 700;
        }

        .cell-badge.status-active {
            background: var(--emerald-soft);
            color: var(--emerald-dark);
            border: 1px solid #a7f3d0;
        }

        .cell-badge.status-inactive {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
        }

        /* Stats Grid in Modal */
        .stats-grid-modal {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
        }

        .stat-card-modal {
            padding: 1.5rem;
            border-radius: var(--r-xl);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .stat-card-modal::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 150px;
            height: 150px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .stat-icon-modal {
            width: 48px;
            height: 48px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: var(--r-md);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }

        .stat-icon-modal svg {
            width: 24px;
            height: 24px;
            stroke: white;
        }

        .stat-label-modal {
            font-size: 0.75rem;
            font-weight: 600;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }

        .stat-value-modal {
            font-size: 1.75rem;
            font-weight: 800;
            font-family: var(--mono);
            margin-bottom: 0.25rem;
        }

        .stat-sub-modal {
            font-size: 0.8125rem;
            opacity: 0.8;
        }

        .table-responsive {
            overflow-x: auto;
            margin-top: 1rem;
        }

        .cell-name {
            font-weight: 600;
            color: var(--text);
        }

        @media (max-width: 1024px) {
            .stats-grid-modal {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .modal-card {
                max-width: 100%;
                max-height: 100vh;
                border-radius: 0;
            }

            .modal-header,
            .modal-footer {
                border-radius: 0;
            }

            .details-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid-modal {
                grid-template-columns: 1fr;
            }
        }

        .btn-add-atc {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .7rem 1.375rem;
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
            border: none;
            border-radius: var(--r-md);
            color: white;
            font-size: .875rem;
            font-weight: 700;
            font-family: var(--font);
            cursor: pointer;
            white-space: nowrap;
            box-shadow: 0 4px 14px var(--brand-glow);
            transition: all .2s ease;
        }

        .btn-add-atc:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px var(--brand-glow);
        }

        .btn-add-atc svg {
            width: 16px;
            height: 16px;
        }

        /* ATC Add/Edit Modal */
        .atc-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(10, 15, 30, .55);
            backdrop-filter: blur(5px);
            z-index: 5000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .atc-modal-overlay.active {
            display: flex;
        }

        .atc-modal-card {
            background: #fff;
            border-radius: 20px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 760px;
            max-height: 92vh;
            overflow-y: auto;
            animation: modalSlideIn .25s cubic-bezier(.34, 1.56, .64, 1);
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: scale(.94) translateY(12px);
            }

            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .atc-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.5rem 1.75rem;
            background: linear-gradient(135deg, var(--brand-light), #ede9fe);
            border-bottom: 1px solid var(--border);
        }

        .atc-modal-header h3 {
            display: flex;
            align-items: center;
            gap: .625rem;
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--text);
        }

        .atc-modal-header h3 svg {
            width: 22px;
            height: 22px;
            stroke: var(--brand);
        }

        .atc-modal-close {
            width: 34px;
            height: 34px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            background: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .atc-modal-close svg {
            width: 15px;
            height: 15px;
            stroke: var(--text-3);
        }

        .atc-modal-body {
            padding: 1.75rem;
        }

        .atc-form-section {
            margin-bottom: 1.5rem;
        }

        .atc-form-section-title {
            font-size: .72rem;
            font-weight: 800;
            color: var(--text-3);
            text-transform: uppercase;
            letter-spacing: .07em;
            padding-bottom: .5rem;
            border-bottom: 2px solid var(--border);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: .4rem;
        }

        .atc-form-section-title svg {
            width: 14px;
            height: 14px;
            stroke: var(--brand);
        }

        .atc-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .atc-form-grid .full {
            grid-column: 1/-1;
        }

        .atc-form-field {
            display: flex;
            flex-direction: column;
            gap: .4rem;
        }

        .atc-form-field label {
            font-size: .8rem;
            font-weight: 700;
            color: var(--text-2);
        }

        .atc-form-field label .req {
            color: var(--rose);
            margin-left: 2px;
        }

        .atc-form-field input,
        .atc-form-field select,
        .atc-form-field textarea {
            padding: .65rem .875rem;
            border: 1.5px solid var(--border);
            border-radius: var(--r-md);
            font-size: .875rem;
            font-family: var(--font);
            color: var(--text);
            background: #fff;
            outline: none;
            transition: border-color var(--t);
        }

        .atc-form-field input:focus,
        .atc-form-field select:focus,
        .atc-form-field textarea:focus {
            border-color: var(--brand);
        }

        .atc-modal-footer {
            display: flex;
            gap: .75rem;
            justify-content: flex-end;
            padding: 1.25rem 1.75rem;
            border-top: 1px solid var(--border);
            background: var(--surface-2);
        }

        .btn-modal-cancel {
            padding: .7rem 1.25rem;
            border: 1.5px solid var(--border);
            border-radius: var(--r-md);
            background: #fff;
            color: var(--text-2);
            font-size: .875rem;
            font-weight: 600;
            font-family: var(--font);
            cursor: pointer;
        }

        .btn-modal-save {
            padding: .7rem 1.375rem;
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
            border: none;
            border-radius: var(--r-md);
            color: white;
            font-size: .875rem;
            font-weight: 700;
            font-family: var(--font);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            box-shadow: 0 4px 14px var(--brand-glow);
            transition: all .2s;
        }

        .btn-modal-save:hover {
            transform: translateY(-2px);
        }

        .btn-modal-save:disabled {
            opacity: .6;
            cursor: not-allowed;
            transform: none;
        }

        /* Expiry badges */
        .exp-badge {
            display: inline-block;
            margin-top: .3rem;
            font-size: .67rem;
            font-weight: 700;
            padding: .15rem .5rem;
            border-radius: 99px;
            letter-spacing: .3px;
        }

        .exp-badge.expired {
            background: #fee2e2;
            color: #dc2626;
        }

        .exp-badge.expiring {
            background: #fef3c7;
            color: #d97706;
        }

        .exp-badge.valid {
            background: #dcfce7;
            color: #16a34a;
        }

        /* Renew button */
        .btn-act.renew {
            color: #16a34a;
        }

        .btn-act.renew:hover {
            background: #dcfce7;
            color: #15803d;
        }

        .btn-act.danger:hover {
            border-color: #fca5a5;
            background: var(--rose-soft);
        }

        .btn-act.danger:hover svg {
            stroke: var(--rose);
        }

        /* Confirm overlay for delete */
        .confirm-overlay {
            position: fixed;
            inset: 0;
            background: rgba(10, 15, 30, .55);
            backdrop-filter: blur(5px);
            z-index: 6000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .confirm-overlay.active {
            display: flex;
        }

        .confirm-card {
            background: #fff;
            border-radius: 16px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 400px;
            overflow: hidden;
        }

        .confirm-body {
            padding: 2rem;
            text-align: center;
        }

        .confirm-icon-wrap {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: var(--rose-soft);
            border: 2px solid #fecdd3;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.25rem;
        }

        .confirm-icon-wrap svg {
            width: 26px;
            height: 26px;
            stroke: var(--rose);
        }

        .confirm-title {
            font-size: 1rem;
            font-weight: 800;
            color: var(--text);
            margin-bottom: .5rem;
        }

        .confirm-msg {
            font-size: .875rem;
            color: var(--text-2);
            line-height: 1.5;
        }

        .confirm-footer {
            display: flex;
            gap: .75rem;
            padding: 1.25rem 1.75rem;
            border-top: 1px solid var(--border);
            background: var(--surface-2);
        }

        .confirm-footer button {
            flex: 1;
            padding: .75rem;
            border-radius: var(--r-md);
            font-size: .875rem;
            font-weight: 700;
            font-family: var(--font);
            cursor: pointer;
            border: none;
        }

        .btn-cancel-confirm {
            background: #fff;
            border: 1.5px solid var(--border) !important;
            color: var(--text-2);
        }

        .btn-delete-confirm {
            background: linear-gradient(135deg, var(--rose), #e11d48);
            color: white;
        }

        .btn-act.notify-wa {
            background: #dcfce7;
            border-color: #86efac;
            color: #16a34a;
        }

        .btn-act.notify-wa:hover {
            background: #25D366;
            color: #fff;
            border-color: #25D366;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 211, 102, .35);
        }

        .btn-act.notify-wa svg {
            fill: currentColor;
            stroke: none;
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
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="3" y1="12" x2="21" y2="12" />
                            <line x1="3" y1="6" x2="21" y2="6" />
                            <line x1="3" y1="18" x2="21" y2="18" />
                        </svg>
                    </button>
                    <div class="header-greeting">
                        <h2>ATCs Management</h2>
                        <p>Manage and monitor ATCs</p>
                    </div>
                </div>
                <div class="header-right">
                    <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                    <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
                </div>
            </header>

            <div class="page-content">

                <!-- Page header -->
                <div class="page-header-block">
                    <div class="page-header-left">
                        <div class="page-header-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 10v6M2 10l10-5 10 5-10 5z" />
                                <path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5" />
                            </svg>
                        </div>
                        <div>
                            <div class="page-header-title">ATCs Management</div>
                            <div class="page-header-subtitle">Manage and monitor Authorized Training Centers</div>
                        </div>
                    </div>
                    <button class="btn-add-atc" onclick="openAddModal()">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19" />
                            <line x1="5" y1="12" x2="19" y2="12" />
                        </svg>
                        Add ATC Center
                    </button>
                </div>

                <!-- Overall Statistics Cards -->
                <div class="kpi-grid">
                    <div class="kpi-card brand">
                        <div class="kpi-top">
                            <div class="kpi-icon brand">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path d="M22 10v6M2 10l10-5 10 5-10 5z" />
                                    <path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5" />
                                </svg>
                            </div>
                        </div>
                        <div class="kpi-value"><?= $overallStats['total_centers'] ?></div>
                        <div class="kpi-label">Total Centers</div>
                    </div>

                    <div class="kpi-card emerald">
                        <div class="kpi-top">
                            <div class="kpi-icon emerald">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                                    <circle cx="9" cy="7" r="4" />
                                    <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                                </svg>
                            </div>
                        </div>
                        <div class="kpi-value"><?= $overallStats['total_students'] ?></div>
                        <div class="kpi-label">Total Students</div>
                    </div>

                    <div class="kpi-card sky">
                        <div class="kpi-top">
                            <div class="kpi-icon sky">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <line x1="12" y1="1" x2="12" y2="23" />
                                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                                </svg>
                            </div>
                        </div>
                        <div class="kpi-value">₹ <?= number_format($overallStats['total_share_received'], 0) ?></div>
                        <div class="kpi-label">Share Received</div>
                    </div>

                    <div class="kpi-card amber">
                        <div class="kpi-top">
                            <div class="kpi-icon amber">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10" />
                                    <polyline points="12 6 12 12 16 14" />
                                </svg>
                            </div>
                        </div>
                        <div class="kpi-value">₹ <?= number_format($totalSharePending, 0) ?></div>
                        <div class="kpi-label">Share Pending</div>
                    </div>
                </div>

                <!-- Status Filter Tabs -->
                <div class="status-tabs">
                    <a href="?status=all<?= $searchTerm ? '&search=' . urlencode($searchTerm) : '' ?><?= $dlcFilter !== 'all' ? '&dlc=' . $dlcFilter : '' ?>"
                        class="status-tab <?= $statusFilter === 'all' ? 'active' : '' ?>">
                        <span>All Centers</span>
                        <span class="tab-pill"><?= $totalCount ?></span>
                    </a>
                    <a href="?status=Active<?= $searchTerm ? '&search=' . urlencode($searchTerm) : '' ?><?= $dlcFilter !== 'all' ? '&dlc=' . $dlcFilter : '' ?>"
                        class="status-tab <?= $statusFilter === 'Active' ? 'active' : '' ?>">
                        <span>Active</span>
                        <span class="tab-pill"><?= $activeCount ?></span>
                    </a>
                    <a href="?status=Inactive<?= $searchTerm ? '&search=' . urlencode($searchTerm) : '' ?><?= $dlcFilter !== 'all' ? '&dlc=' . $dlcFilter : '' ?>"
                        class="status-tab <?= $statusFilter === 'Inactive' ? 'active' : '' ?>">
                        <span>Inactive</span>
                        <span class="tab-pill"><?= $inactiveCount ?></span>
                    </a>
                </div>

                <div class="toolbar">
                    <div class="toolbar-left">
                        <span class="toolbar-title">ATCs List</span>
                        <span class="toolbar-count"><?= count($atcCenters) ?> shown</span>
                    </div>
                    <div class="toolbar-right">
                        <form method="GET" style="display:contents;">
                            <input type="hidden" name="status" value="<?= $statusFilter ?>">
                            <select name="dlc" class="select-sm" onchange="this.form.submit()">
                                <option value="all">All DLC Offices</option>
                                <?php foreach ($dlcOffices as $dlc): ?>
                                    <option value="<?= $dlc['id'] ?>" <?= $dlcFilter == $dlc['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($dlc['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="search-wrap">
                                <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="8" />
                                    <path d="m21 21-4.35-4.35" />
                                </svg>
                                <input type="text" name="search" class="search-input" placeholder="Search centers…"
                                    value="<?= htmlspecialchars($searchTerm) ?>">
                            </div>
                            <button type="submit" class="btn-search">Search</button>
                        </form>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="data-table atc-table">
                        <thead>
                            <tr>
                                <th>ATC Code</th>
                                <th>ATC Name</th>
                                <th>DLC Office</th>
                                <th>Students</th>
                                <th>Reporting Status</th>
                                <th>Share Status</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($atcCenters)): ?>
                                <tr>
                                    <td colspan="8">
                                        <div class="empty-state">
                                            <div class="empty-icon">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                    stroke-linejoin="round">
                                                    <path d="M22 10v6M2 10l10-5 10 5-10 5z" />
                                                    <path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5" />
                                                </svg>
                                            </div>
                                            <div class="empty-title">No ATCs found</div>
                                            <div class="empty-sub">Try adjusting your search or filters.</div>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($atcCenters as $atc): ?>
                                    <?php
                                    $totalStu = (int) $atc['total_students'];
                                    $reportedStu = (int) $atc['reported_students'];
                                    $pendingStu = max(0, $totalStu - $reportedStu);
                                    $shareReceived = (float) $atc['total_share_received'];

                                    // Calculate total expected share for this ATC
                                    static $shareExpCache = [];
                                    if (!isset($shareExpCache[$atc['id']])) {
                                        try {
                                            $seStmt = $pdo->prepare("
                                                SELECT COALESCE(SUM(
                                                    CASE WHEN a.ho_share_snapshot IS NOT NULL AND a.ho_share_snapshot > 0
                                                         THEN a.ho_share_snapshot
                                                         ELSE COALESCE(c.ho_share, 0)
                                                    END
                                                ), 0)
                                                FROM admissions a
                                                LEFT JOIN courses c ON a.course = c.course_name AND c.status = 'Active'
                                                WHERE a.atc_id = ? AND a.status = 'Active'
                                            ");
                                            $seStmt->execute([$atc['id']]);
                                            $shareExpCache[$atc['id']] = (float) $seStmt->fetchColumn();
                                        } catch (Exception $e) {
                                            $shareExpCache[$atc['id']] = 0;
                                        }
                                    }
                                    $shareExpected = $shareExpCache[$atc['id']];
                                    $sharePending = max(0, $shareExpected - $shareReceived);

                                    $initial = strtoupper(substr($atc['name'], 0, 1));
                                    $statusClass = strtolower($atc['status']) === 'active' ? 'status-active' : 'status-inactive';
                                    ?>
                                    <tr>
                                        <td>
                                            <!-- ATC Code -->
                                            <span
                                                style="display:inline-flex;align-items:center;padding:.3rem .7rem;background:linear-gradient(135deg,#eff6ff,#ede9fe);border:1px solid #c7d2fe;border-radius:99px;font-size:.78rem;font-weight:800;color:#4361ee;letter-spacing:.03em;font-family:var(--mono);">
                                                <?= htmlspecialchars($atc['atc_code'] ?: date('Y') . str_pad($atc['id'], 5, '0', STR_PAD_LEFT)) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="center-cell">
                                                <?php
                                                $atcLogoPath = !empty($atc['logo']) ? '../' . ltrim($atc['logo'], '/') : '';
                                                $atcLogoExists = $atcLogoPath && file_exists(__DIR__ . '/../' . ltrim($atc['logo'], '/'));
                                                ?>
                                                <div class="center-avatar">
                                                    <?php if ($atcLogoExists): ?>
                                                        <img src="<?= htmlspecialchars($atcLogoPath) ?>"
                                                            alt="<?= htmlspecialchars($atc['name']) ?> logo">
                                                    <?php else: ?>
                                                        <?= $initial ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <div class="center-name"><?= htmlspecialchars($atc['name']) ?></div>
                                                    <div class="center-location"><?= htmlspecialchars($atc['district']) ?>,
                                                        <?= htmlspecialchars($atc['state']) ?></div>
                                                    <?php
                                                    $expTs = !empty($atc['authorization_expires_at']) ? strtotime($atc['authorization_expires_at']) : null;
                                                    $daysLeft = $expTs ? (int) (($expTs - time()) / 86400) : null;
                                                    if ($expTs !== null):
                                                        if ($daysLeft < 0): ?>
                                                            <span class="exp-badge expired">Expired <?= abs($daysLeft) ?> days
                                                                ago</span>
                                                        <?php elseif ($daysLeft <= 30): ?>
                                                            <span class="exp-badge expiring">Expires in <?= $daysLeft ?> days</span>
                                                        <?php else: ?>
                                                            <span class="exp-badge valid">Valid till <?= date('M Y', $expTs) ?></span>
                                                        <?php endif;
                                                    endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="cell-main"><?= htmlspecialchars($atc['dlc_name']) ?></div>
                                            <div class="cell-sub"><?= htmlspecialchars($atc['dlc_district']) ?></div>
                                        </td>
                                        <td>
                                            <?php
                                            $reportPct = $totalStu > 0 ? round(($reportedStu / $totalStu) * 100) : 0;
                                            $reportBarClass = $reportPct >= 75 ? 'fee-progress-bar' : ($reportPct >= 40 ? 'fee-progress-bar-amber' : 'fee-progress-bar-danger');
                                            ?>
                                            <div style="white-space:nowrap;margin-bottom:.35rem;">
                                                <span style="font-weight:700;color:#059669;"><?= $reportedStu ?></span>
                                                <span style="color:#9ca3af;"> / </span>
                                                <span style="font-weight:600;color:#6b7280;"><?= $totalStu ?></span>
                                                <small
                                                    style="font-weight:500;color:#9ca3af;margin-left:.25rem;">(<?= $reportPct ?>%)</small>
                                            </div>
                                            <div class="fee-progress">
                                                <div class="<?= $reportBarClass ?>" style="width:<?= $reportPct ?>%;"></div>
                                            </div>
                                            <?php if ($pendingStu > 0): ?>
                                                <div style="font-size:.72rem;color:#d97706;margin-top:.25rem;font-weight:600;">
                                                    <?= $pendingStu ?> pending</div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $shareCollectedPct = $shareExpected > 0 ? round(($shareReceived / $shareExpected) * 100) : 0;
                                            $shareBarCls = $shareCollectedPct >= 75 ? 'fee-progress-bar' : ($shareCollectedPct >= 40 ? 'fee-progress-bar-amber' : 'fee-progress-bar-danger');
                                            ?>
                                            <div style="white-space:nowrap;margin-bottom:.35rem;">
                                                <span
                                                    style="font-weight:700;color:#059669;">₹<?= number_format($shareReceived, 0) ?></span>
                                                <span style="color:#9ca3af;"> / </span>
                                                <span
                                                    style="font-weight:600;color:#6b7280;">₹<?= number_format($shareExpected, 0) ?></span>
                                                <small
                                                    style="font-weight:500;color:#9ca3af;margin-left:.25rem;">(<?= $shareCollectedPct ?>%)</small>
                                            </div>
                                            <div class="fee-progress">
                                                <div class="<?= $shareBarCls ?>" style="width:<?= $shareCollectedPct ?>%;">
                                                </div>
                                            </div>
                                            <?php if ($sharePending > 0): ?>
                                                <div style="font-size:.72rem;color:#d97706;margin-top:.25rem;font-weight:600;">
                                                    ₹<?= number_format($sharePending, 0) ?> pending</div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= $statusClass ?>">
                                                <span class="status-dot"></span>
                                                <?= $atc['status'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="actions-wrap">
                                                <button class="btn-act" onclick="viewDetails(<?= $atc['id'] ?>)"
                                                    title="View Details &amp; Credentials">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                                        stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                        stroke-linejoin="round">
                                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                                        <circle cx="12" cy="12" r="3" />
                                                    </svg>
                                                </button>
                                                <!-- Auth Certificate -->
                                                <a class="btn-act" style="color:#16a34a;text-decoration:none"
                                                    href="generate_auth_certificate.php?atc_id=<?= $atc['id'] ?>"
                                                    target="_blank"
                                                    title="Download Authorization Certificate (PDF)">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
                                                        <line x1="12" y1="18" x2="12" y2="12"/><polyline points="9 15 12 18 15 15"/>
                                                    </svg>
                                                </a>
                                                <?php if (!empty($atc['mobile']) && !empty($atc['login_username'])): ?>

                                                    <button class="btn-act notify-wa"
                                                        onclick="notifyATC('<?= htmlspecialchars($atc['mobile'], ENT_QUOTES) ?>', '<?= htmlspecialchars($atc['contact_person'] ?: $atc['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($atc['atc_code'] ?: date('Y') . str_pad($atc['id'], 5, '0', STR_PAD_LEFT), ENT_QUOTES) ?>', '<?= htmlspecialchars($atc['login_username'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($atc['login_password'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars(($trainingLookup[$atc['id']]['username'] ?? ''), ENT_QUOTES) ?>', '<?= htmlspecialchars(($trainingLookup[$atc['id']]['password'] ?? ''), ENT_QUOTES) ?>')"
                                                        title="Send Credentials via WhatsApp">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                                            fill="currentColor" style="width:14px;height:14px;">
                                                            <path
                                                                d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                                                        </svg>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($daysLeft !== null && $daysLeft <= 30): ?>
                                                    <button class="btn-act renew"
                                                        onclick="renewATC(<?= $atc['id'] ?>, '<?= htmlspecialchars($atc['name'], ENT_QUOTES) ?>')"
                                                        title="Renew Authorization">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                                            stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <polyline points="23 4 23 10 17 10" />
                                                            <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10" />
                                                        </svg>
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn-act" onclick="editATC(<?= $atc['id'] ?>)" title="Edit ATC">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                                        stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                        stroke-linejoin="round">
                                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                                                    </svg>
                                                </button>
                                                <button class="btn-act danger"
                                                    onclick="deleteATC(<?= $atc['id'] ?>, '<?= htmlspecialchars($atc['name'], ENT_QUOTES) ?>')"
                                                    title="Delete ATC">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                                        stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                        stroke-linejoin="round">
                                                        <polyline points="3 6 5 6 21 6" />
                                                        <path
                                                            d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?= renderPagination($pager, 'ATC centers') ?>
            </div>
        </main>
    </div>
    <!-- Add/Edit ATC Modal -->
    <div class="atc-modal-overlay" id="atcFormModal">
        <div class="atc-modal-card">
            <div class="atc-modal-header">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 10v6M2 10l10-5 10 5-10 5z" />
                        <path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5" />
                    </svg>
                    <span id="atcFormTitle">Add New ATC Center</span>
                </h3>
                <button type="button" class="atc-modal-close" onclick="closeATCModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>
            <form id="atcForm" novalidate>
                <input type="hidden" id="atcFormAction" name="action" value="add">
                <input type="hidden" id="atcFormId" name="id">
                <div class="atc-modal-body">

                    <!-- Basic Info -->
                    <div class="atc-form-section">
                        <div class="atc-form-section-title">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <path d="M22 10v6M2 10l10-5 10 5-10 5z" />
                                <path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5" />
                            </svg>
                            Center Information
                        </div>
                        <div class="atc-form-grid">
                            <div class="atc-form-field full">
                                <label for="f_name">Center Name <span class="req">*</span></label>
                                <input type="text" id="f_name" name="name" required maxlength="150"
                                    placeholder="e.g., Pune Authorized Training Center">
                            </div>
                            <div class="atc-form-field">
                                <label for="f_center_type">Center Type <span class="req">*</span></label>
                                <select id="f_center_type" name="center_type" required>
                                    <option value="">-- Select Type --</option>
                                    <option value="Abacus">Abacus</option>
                                    <option value="Vedic Maths">Vedic Maths</option>
                                    <option value="IT">IT</option>
                                    <option value="Abacus + IT">Abacus + IT</option>
                                    <option value="Abacus + Vedic Maths">Abacus + Vedic Maths</option>
                                    <option value="Vedic Maths + IT">Vedic Maths + IT</option>
                                    <option value="Abacus + Vedic Maths + IT">Abacus + Vedic Maths + IT</option>
                                </select>
                            </div>
                            <div class="atc-form-field">
                                <label for="f_dlc_id">Assign to DLC Login <span class="req">*</span></label>
                                <select id="f_dlc_id" name="dlc_id" required>
                                    <option value="">-- Select DLC --</option>
                                    <?php foreach ($dlcOffices as $dlc): ?>
                                        <option value="<?= $dlc['id'] ?>"><?= htmlspecialchars($dlc['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="atc-form-field">
                                <label for="f_date_created">Date Created</label>
                                <input type="date" id="f_date_created" name="date_created" max="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="atc-form-field">
                                <label for="f_authorization_expires_at">Authorization Expiry Date</label>
                                <input type="date" id="f_authorization_expires_at" name="authorization_expires_at">
                            </div>
                            <div class="atc-form-field">
                                <label for="f_franchise_fees">Franchise Fees (&#8377;)</label>
                                <input type="number" id="f_franchise_fees" name="franchise_fees" min="0" step="0.01"
                                    placeholder="e.g. 25000">
                            </div>
                        </div>
                    </div>

                    <!-- Location -->
                    <div class="atc-form-section">
                        <div class="atc-form-section-title">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                                <circle cx="12" cy="10" r="3" />
                            </svg>
                            Location Details
                        </div>
                        <div class="atc-form-grid">
                            <div class="atc-form-field full">
                                <label for="f_address">Full Address <span class="req">*</span></label>
                                <textarea id="f_address" name="address" rows="2" required
                                    placeholder="Complete address with landmark, street, area"></textarea>
                            </div>
                            <div class="atc-form-field">
                                <label for="f_district">District <span class="req">*</span></label>
                                <input type="text" id="f_district" name="district" required maxlength="100"
                                    placeholder="e.g., Pune">
                            </div>
                            <div class="atc-form-field">
                                <label for="f_taluka">Taluka <span class="req">*</span></label>
                                <input type="text" id="f_taluka" name="taluka" required maxlength="100"
                                    placeholder="e.g., Haveli">
                            </div>
                            <div class="atc-form-field">
                                <label for="f_city">City</label>
                                <input type="text" id="f_city" name="city" maxlength="100"
                                    placeholder="e.g., Pune City">
                            </div>
                            <div class="atc-form-field">
                                <label for="f_state">State</label>
                                <select id="f_state" name="state">
                                    <option value="Maharashtra">Maharashtra</option>
                                    <option value="Gujarat">Gujarat</option>
                                    <option value="Karnataka">Karnataka</option>
                                    <option value="Delhi">Delhi</option>
                                    <option value="Rajasthan">Rajasthan</option>
                                    <option value="Uttar Pradesh">Uttar Pradesh</option>
                                    <option value="Madhya Pradesh">Madhya Pradesh</option>
                                    <option value="Tamil Nadu">Tamil Nadu</option>
                                    <option value="West Bengal">West Bengal</option>
                                    <option value="Telangana">Telangana</option>
                                    <option value="Andhra Pradesh">Andhra Pradesh</option>
                                    <option value="Kerala">Kerala</option>
                                    <option value="Punjab">Punjab</option>
                                    <option value="Haryana">Haryana</option>
                                    <option value="Bihar">Bihar</option>
                                    <option value="Odisha">Odisha</option>
                                    <option value="Jharkhand">Jharkhand</option>
                                    <option value="Chhattisgarh">Chhattisgarh</option>
                                    <option value="Assam">Assam</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="atc-form-field">
                                <label for="f_pin_code">PIN Code <span class="req">*</span></label>
                                <input type="text" id="f_pin_code" name="pin_code" required maxlength="10"
                                    pattern="[0-9]{6}" placeholder="6-digit PIN">
                            </div>
                        </div>
                    </div>

                    <!-- Contact -->
                    <div class="atc-form-section">
                        <div class="atc-form-section-title">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <path
                                    d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
                            </svg>
                            Contact Information
                        </div>
                        <div class="atc-form-grid">
                            <div class="atc-form-field">
                                <label for="f_contact_person">Contact Person</label>
                                <input type="text" id="f_contact_person" name="contact_person" maxlength="100"
                                    placeholder="Full name">
                            </div>
                            <div class="atc-form-field">
                                <label for="f_mobile">Mobile Number</label>
                                <input type="tel" id="f_mobile" name="mobile" maxlength="10" pattern="[0-9]{10}"
                                    placeholder="9876543210">
                            </div>
                            <div class="atc-form-field">
                                <label for="f_alternate_mobile">Alternate Mobile <span style="font-size:.72rem;color:var(--text-3);font-weight:500">(optional)</span></label>
                                <input type="tel" id="f_alternate_mobile" name="alternate_mobile" maxlength="15"
                                    placeholder="e.g., 9876543211">
                            </div>
                            <div class="atc-form-field">
                                <label for="f_email">Email Address</label>
                                <input type="email" id="f_email" name="email" maxlength="100"
                                    placeholder="center@example.com">
                            </div>
                            <div class="atc-form-field">
                                <label for="f_dob">Contact Person Birthday</label>
                                <input type="date" id="f_dob" name="dob">
                            </div>
                            <div class="atc-form-field">
                                <label for="f_status">Status</label>
                                <select id="f_status" name="status">
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Login Account -->
                    <div class="atc-form-section" id="loginAccountSection">
                        <div class="atc-form-section-title">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                            </svg>
                            Login Account Credentials
                        </div>
                        <div id="loginAccountHint"
                            style="padding:.75rem 1rem;background:linear-gradient(135deg,#eff6ff,#ede9fe);border:1px solid #c7d2fe;border-radius:12px;margin-bottom:1rem;font-size:.8rem;color:#4338ca;font-weight:500;">
                            Username is always the <strong>ATC Code</strong>. Temporary password is set to
                            <strong>password</strong> — ATC should change it after first login. You can share via WhatsApp.
                        </div>
                        <div class="atc-form-grid">
                            <div class="atc-form-field">
                                <label for="f_login_username">Username (ATC Code)</label>
                                <input type="text" id="f_login_username" name="login_username" maxlength="100"
                                    placeholder="Auto = ATC Code after save" autocomplete="off" readonly
                                    style="background:#f8fafc;cursor:not-allowed;">
                            </div>
                            <div class="atc-form-field">
                                <label for="f_login_password">Password <span class="req" id="loginPasswordReq" style="display:none">*</span></label>
                                <input type="text" id="f_login_password" name="login_password" maxlength="100"
                                    value="password" autocomplete="off" readonly
                                    style="background:#f8fafc;cursor:not-allowed;">
                                <div class="field-hint" id="loginPasswordHint" style="font-size:.72rem;color:#64748b;margin-top:.3rem;">
                                    Temporary password: <strong>password</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Training Login -->
                    <div class="atc-form-section" id="trainingLoginSection">
                        <div class="atc-form-section-title">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                            Training Video Login
                            <span id="trainingBadge" style="display:none;font-size:.65rem;font-weight:800;padding:.15rem .5rem;border-radius:99px;margin-left:.5rem;"></span>
                        </div>
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;">
                            <div style="font-size:.78rem;color:#6d28d9;font-weight:500;">🎬 Create a separate login to access training videos for this ATC</div>
                            <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-size:.78rem;font-weight:700;color:#6d28d9;margin:0;white-space:nowrap;">
                                <input type="checkbox" id="f_create_training" name="create_training" value="1" style="width:auto;accent-color:#7c3aed;" onchange="toggleTrainingFields()">
                                Enable
                            </label>
                        </div>
                        <div id="trainingFieldsATC" style="display:none;">
                            <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-size:.78rem;font-weight:600;color:#6d28d9;margin:0 0 .75rem;">
                                <input type="checkbox" id="f_same_training_creds" name="same_training_creds" value="1" style="width:auto;accent-color:#7c3aed;" onchange="handleTrainingSameCreds()">
                                <span>Use same credentials as ATC login</span>
                            </label>
                            <div class="atc-form-grid">
                                <div class="atc-form-field">
                                    <label for="f_training_username">Training Username</label>
                                    <input type="text" id="f_training_username" name="training_username" placeholder="e.g., training_pune" autocomplete="off">
                                </div>
                                <div class="atc-form-field">
                                    <label for="f_training_password">Training Password</label>
                                    <input type="text" id="f_training_password" name="training_password" placeholder="e.g., Train@123" autocomplete="off">
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="atc-modal-footer">
                    <button type="button" class="btn-modal-cancel" onclick="closeATCModal()">Cancel</button>
                    <button type="submit" class="btn-modal-save" id="atcSaveBtn">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" style="width:16px;height:16px;">
                            <polyline points="20 6 9 17 4 12" />
                        </svg>
                        <span id="atcSaveBtnText">Save ATC Center</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirm -->
    <div class="confirm-overlay" id="deleteConfirmOverlay">
        <div class="confirm-card">
            <div class="confirm-body">
                <div class="confirm-icon-wrap">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <polyline points="3 6 5 6 21 6" />
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                    </svg>
                </div>
                <div class="confirm-title">Delete ATC Center?</div>
                <div class="confirm-msg">You're about to permanently delete <strong id="deleteName"></strong>. This
                    cannot be undone.</div>
            </div>
            <div class="confirm-footer">
                <button class="btn-cancel-confirm" onclick="closeDeleteConfirm()">Cancel</button>
                <button class="btn-delete-confirm" id="confirmDeleteBtn">Delete</button>
            </div>
        </div>
    </div>


    <div class="modal-overlay" id="atcDetailsModal">
        <div class="modal-card" style="max-width: 1100px; max-height: 90vh; overflow-y: auto;">
            <div class="modal-header">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        style="width: 22px; height: 22px;">
                        <path d="M22 10v6M2 10l10-5 10 5-10 5z" />
                        <path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5" />
                    </svg>
                    <span id="atcDetailsTitle">ATC Center Details</span>
                </h3>
                <button type="button" class="modal-close" onclick="closeDetailsModal()" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>

            <div class="modal-body" id="atcDetailsContent">
                <div style="text-align: center; padding: 3rem;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        style="width: 48px; height: 48px; margin: 0 auto; animation: spin 1s linear infinite;">
                        <circle cx="12" cy="12" r="10" />
                        <path d="M12 6v6l4 2" />
                    </svg>
                    <p style="margin-top: 1rem; color: var(--text-secondary);">Loading details...</p>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeDetailsModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        style="width: 16px; height: 16px;">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                    Close
                </button>
            </div>
        </div>
    </div>

    <script src="../assets/js/dashboard.js"></script>
    <script>
        /* ── Toast ── */
        function showToast(msg, type = 'success') {
            const existing = document.querySelector('.atc-toast-container') || (() => {
                const c = document.createElement('div');
                c.className = 'atc-toast-container';
                c.style.cssText = 'position:fixed;top:1.25rem;right:1.25rem;z-index:9999;display:flex;flex-direction:column;gap:.5rem;';
                document.body.appendChild(c); return c;
            })();
            const t = document.createElement('div');
            t.style.cssText = `display:flex;align-items:center;gap:.75rem;padding:.875rem 1.125rem;border-radius:12px;background:#fff;border:1px solid #e6eaf3;box-shadow:0 20px 60px rgba(0,0,0,.12);min-width:260px;font-size:.875rem;font-weight:500;border-left:3px solid ${type === 'success' ? '#10b981' : '#f43f5e'};`;
            t.textContent = msg;
            existing.appendChild(t);
            setTimeout(() => t.remove(), 3500);
        }

        /* ── Add Modal ── */
        function openAddModal() {
            document.getElementById('atcFormTitle').textContent = 'Add New ATC Center';
            document.getElementById('atcFormAction').value = 'add';
            document.getElementById('atcFormId').value = '';
            document.getElementById('atcForm').reset();
            document.getElementById('atcSaveBtnText').textContent = 'Save ATC Center';
            // Auto-fill today as date_created and expiry as today+1 year
            const today = new Date();
            const todayStr = today.toISOString().split('T')[0];
            const expiry = new Date(today.getFullYear() + 1, today.getMonth(), today.getDate());
            document.getElementById('f_date_created').value = todayStr;
            document.getElementById('f_authorization_expires_at').value = expiry.toISOString().split('T')[0];
            // Login: username = ATC code (auto), temp password = password
            document.getElementById('loginAccountSection').style.display = '';
            const uEl = document.getElementById('f_login_username');
            const pEl = document.getElementById('f_login_password');
            uEl.value = '';
            uEl.placeholder = 'Auto = ATC Code after save';
            uEl.readOnly = true;
            uEl.style.background = '#f8fafc';
            uEl.style.cursor = 'not-allowed';
            pEl.value = 'password';
            pEl.placeholder = 'password';
            pEl.readOnly = true;
            pEl.style.background = '#f8fafc';
            pEl.style.cursor = 'not-allowed';
            document.getElementById('loginPasswordReq').style.display = 'none';
            document.getElementById('loginPasswordHint').style.display = '';
            document.getElementById('loginPasswordHint').innerHTML = 'Temporary password: <strong>password</strong>';
            document.getElementById('loginAccountHint').innerHTML = 'Username is always the <strong>ATC Code</strong>. Temporary password is set to <strong>password</strong> — ATC should change it after first login. You can share via WhatsApp.';
            document.getElementById('loginAccountHint').style.background = 'linear-gradient(135deg,#eff6ff,#ede9fe)';
            document.getElementById('loginAccountHint').style.borderColor = '#c7d2fe';
            document.getElementById('loginAccountHint').style.color = '#4338ca';
            // Reset training fields
            document.getElementById('f_create_training').checked = false;
            document.getElementById('trainingFieldsATC').style.display = 'none';
            document.getElementById('f_same_training_creds').checked = false;
            document.getElementById('f_training_username').value = '';
            document.getElementById('f_training_password').value = '';
            document.getElementById('f_training_password').placeholder = 'e.g., Train@123';
            document.getElementById('trainingBadge').style.display = 'none';
            document.getElementById('atcFormModal').classList.add('active');
        }

        function closeATCModal() {
            document.getElementById('atcFormModal').classList.remove('active');
            document.getElementById('atcForm').reset();
        }

        /* ── Training Login Helpers ── */
        function toggleTrainingFields() {
            document.getElementById('trainingFieldsATC').style.display = document.getElementById('f_create_training').checked ? 'block' : 'none';
        }
        function handleTrainingSameCreds() {
            const same = document.getElementById('f_same_training_creds').checked;
            const tU = document.getElementById('f_training_username');
            const tP = document.getElementById('f_training_password');
            const isAdd = document.getElementById('atcFormAction').value === 'add';
            if (same) {
                const u = document.getElementById('f_login_username').value;
                const p = document.getElementById('f_login_password').value || 'password';
                tU.value = isAdd && !u ? '(same as ATC Code)' : u;
                tP.value = p;
                tU.readOnly = true; tP.readOnly = true;
                tU.style.opacity = '.6'; tP.style.opacity = '.6';
            } else {
                tU.readOnly = false; tP.readOnly = false;
                tU.style.opacity = '1'; tP.style.opacity = '1';
            }
        }

        /* ── Edit ATC ── */
        async function editATC(id) {
            try {
                const fd = new FormData();
                fd.append('action', 'get'); fd.append('atc_id', id);
                const res = await fetch('', { method: 'POST', body: new URLSearchParams(fd) });
                const data = await res.json();
                if (!data.success) { showToast('Error loading data', 'error'); return; }
                const a = data.data;
                document.getElementById('atcFormTitle').textContent = 'Edit ATC Center';
                document.getElementById('atcFormAction').value = 'edit';
                document.getElementById('atcFormId').value = a.id;
                document.getElementById('f_name').value = a.name || '';
                document.getElementById('f_center_type').value = a.center_type || '';
                document.getElementById('f_dlc_id').value = a.dlc_id || '';
                document.getElementById('f_authorization_expires_at').value = a.authorization_expires_at || '';
                document.getElementById('f_franchise_fees').value = (a.franchise_fees !== null && a.franchise_fees !== undefined) ? a.franchise_fees : '';
                document.getElementById('f_district').value = a.district || '';
                document.getElementById('f_taluka').value = a.taluka || '';
                document.getElementById('f_city').value = a.city || '';
                document.getElementById('f_pin_code').value = a.pin_code || '';
                document.getElementById('f_state').value = a.state || 'Maharashtra';
                document.getElementById('f_address').value = a.address || '';
                document.getElementById('f_contact_person').value = a.contact_person || '';
                document.getElementById('f_dob').value = a.dob || '';
                document.getElementById('f_mobile').value = a.mobile || '';
                document.getElementById('f_alternate_mobile').value = a.alternate_mobile || '';
                document.getElementById('f_email').value = a.email || '';
                document.getElementById('f_date_created').value = a.date_created || '';
                document.getElementById('f_status').value = a.status || 'Active';
                document.getElementById('atcSaveBtnText').textContent = 'Update ATC Center';
                // Login: username locked to ATC code; show current password so Admin can see it
                document.getElementById('loginAccountSection').style.display = '';
                const uEl = document.getElementById('f_login_username');
                const pEl = document.getElementById('f_login_password');
                const code = a.atc_code || a.login_username || '';
                uEl.value = code;
                uEl.readOnly = true;
                uEl.style.background = '#f8fafc';
                uEl.style.cursor = 'not-allowed';
                pEl.value = a.login_password || '';
                pEl.placeholder = 'Current password (editable to reset)';
                pEl.readOnly = false;
                pEl.style.background = '#fff';
                pEl.style.cursor = 'text';
                document.getElementById('loginPasswordReq').style.display = 'none';
                document.getElementById('loginPasswordHint').style.display = '';
                document.getElementById('loginPasswordHint').innerHTML = 'Admin can view / reset password here. Leave as-is to keep.';
                document.getElementById('loginAccountHint').innerHTML = '🔐 Username is fixed to <strong>ATC Code</strong>. Password is visible below — change it here if you need to reset.';
                document.getElementById('loginAccountHint').style.background = 'linear-gradient(135deg,#f0fdf4,#dcfce7)';
                document.getElementById('loginAccountHint').style.borderColor = '#86efac';
                document.getElementById('loginAccountHint').style.color = '#15803d';
                // Populate training login info
                const badge = document.getElementById('trainingBadge');
                const tU = document.getElementById('f_training_username');
                const tP = document.getElementById('f_training_password');
                document.getElementById('f_same_training_creds').checked = false;
                tU.readOnly = false; tU.style.opacity = '1';
                tP.readOnly = false; tP.style.opacity = '1';
                if (a.training_user) {
                    badge.textContent = '✓ Active';
                    badge.style.display = 'inline';
                    badge.style.background = '#d1fae5'; badge.style.color = '#065f46'; badge.style.border = '1px solid #a7f3d0';
                    tU.value = a.training_user.username;
                    tP.value = a.training_user.password || '';
                    tP.placeholder = 'Leave blank to keep current';
                    document.getElementById('f_create_training').checked = true;
                    document.getElementById('trainingFieldsATC').style.display = 'block';
                } else {
                    badge.textContent = 'Not Created';
                    badge.style.display = 'inline';
                    badge.style.background = '#fef3c7'; badge.style.color = '#92400e'; badge.style.border = '1px solid #fde68a';
                    tU.value = ''; tP.value = ''; tP.placeholder = 'e.g., Train@123';
                    document.getElementById('f_create_training').checked = false;
                    document.getElementById('trainingFieldsATC').style.display = 'none';
                }
                document.getElementById('atcFormModal').classList.add('active');
            } catch (e) { showToast('Error loading ATC data', 'error'); }
        }

        /* ── Form Submit ── */
        document.getElementById('atcForm').addEventListener('submit', async function (e) {
            e.preventDefault();
            const btn = document.getElementById('atcSaveBtn');
            const origText = document.getElementById('atcSaveBtnText').textContent;
            btn.disabled = true;
            document.getElementById('atcSaveBtnText').textContent = 'Saving...';
            try {
                const fd = new FormData(this);
                const res = await fetch('', { method: 'POST', body: new URLSearchParams(fd) });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message, 'success');
                    closeATCModal();
                    setTimeout(() => location.reload(), 800);
                } else {
                    showToast(data.message || 'Error saving ATC', 'error');
                }
            } catch (e) { showToast('Server error', 'error'); }
            btn.disabled = false;
            document.getElementById('atcSaveBtnText').textContent = origText;
        });

        /* ── Delete ── */
        let _pendingDeleteId = null;
        function deleteATC(id, name) {
            _pendingDeleteId = id;
            document.getElementById('deleteName').textContent = name;
            document.getElementById('deleteConfirmOverlay').classList.add('active');
        }
        function closeDeleteConfirm() {
            document.getElementById('deleteConfirmOverlay').classList.remove('active');
            _pendingDeleteId = null;
        }
        document.getElementById('confirmDeleteBtn').addEventListener('click', async function () {
            if (!_pendingDeleteId) return;
            this.textContent = 'Deleting...'; this.disabled = true;
            try {
                const fd = new FormData();
                fd.append('action', 'delete'); fd.append('id', _pendingDeleteId);
                const res = await fetch('', { method: 'POST', body: new URLSearchParams(fd) });
                const data = await res.json();
                if (data.success) {
                    showToast('ATC Center deleted', 'success');
                    closeDeleteConfirm();
                    setTimeout(() => location.reload(), 800);
                } else { showToast(data.message || 'Delete failed', 'error'); }
            } catch (e) { showToast('Server error', 'error'); }
            this.textContent = 'Delete'; this.disabled = false;
        });

        /* ── Close modals on overlay click / Escape ── */
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('atcFormModal').addEventListener('click', function (e) { if (e.target === this) closeATCModal(); });
            document.getElementById('deleteConfirmOverlay').addEventListener('click', function (e) { if (e.target === this) closeDeleteConfirm(); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeATCModal(); closeDeleteConfirm(); closeDetailsModal(); } });
        });



        async function viewDetails(id) {

            try {
                const formData = new FormData();
                formData.append('action', 'get_details');
                formData.append('atc_id', id);

                const response = await fetch('', {
                    method: 'POST',
                    body: new URLSearchParams(formData)
                });

                const data = await response.json();

                if (data.success) {
                    document.getElementById('atcDetailsModal').classList.add('active');
                    displayATCDetails(data);
                } else {
                    document.getElementById('atcDetailsContent').innerHTML = `
                <div style="text-align: center; padding: 3rem;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 48px; height: 48px; margin: 0 auto; color: var(--danger-600);"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <p style="margin-top: 1rem; color: var(--text-secondary);">Error loading details: ${data.message}</p>
                </div>
            `;
                    document.getElementById('atcDetailsModal').classList.add('active');
                }
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('atcDetailsModal').classList.add('active');
                document.getElementById('atcDetailsContent').innerHTML = `
            <div style="text-align: center; padding: 3rem;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 48px; height: 48px; margin: 0 auto; color: var(--danger-600);"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <p style="margin-top: 1rem; color: var(--text-secondary);">Error loading ATC details</p>
            </div>
        `;
            }
        }

        function displayATCDetails(data) {
            const atc = data.atcInfo;
            const inquiry = data.inquiryStats;
            const telephonic = data.telephonicStats;
            const admission = data.admissionStats;
            const students = data.recentStudents;
            const courses = data.courseBreakdown;

            document.getElementById('atcDetailsTitle').textContent = atc.name;

            const collectionRate = admission.total_fees > 0 ? (admission.total_collected / admission.total_fees * 100).toFixed(1) : 0;

            let html = `
        <!-- ATC Basic Information -->
        <div class="form-section">
            <div class="form-section-title">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                Center Information
            </div>
            <div class="details-grid">
                <div class="detail-item">
                    <div class="detail-label">ATC Name</div>
                    <div class="detail-value">${atc.name}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">DLC Office</div>
                    <div class="detail-value">${atc.dlc_name}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Location</div>
                    <div class="detail-value">${atc.district}, ${atc.state}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Status</div>
                    <div class="detail-value">
                        <span class="cell-badge status-${atc.status.toLowerCase()}">${atc.status}</span>
                    </div>
                </div>
                ${atc.contact_person ? `
                <div class="detail-item">
                    <div class="detail-label">Contact Person</div>
                    <div class="detail-value">${atc.contact_person}</div>
                </div>
                ` : ''}
                ${atc.mobile ? `
                <div class="detail-item">
                    <div class="detail-label">Mobile</div>
                    <div class="detail-value">${atc.mobile}</div>
                </div>
                ` : ''}
                ${atc.email ? `
                <div class="detail-item">
                    <div class="detail-label">Email</div>
                    <div class="detail-value">${atc.email}</div>
                </div>
                ` : ''}
                ${atc.address ? `
                <div class="detail-item full-width">
                    <div class="detail-label">Address</div>
                    <div class="detail-value">${atc.address}</div>
                </div>
                ` : ''}
            </div>
        </div>

        <!-- Login Credentials (visible only in eye / View Details) -->
        <div class="form-section">
            <div class="form-section-title">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Login Credentials
            </div>
            <div class="details-grid">
                <div class="detail-item">
                    <div class="detail-label">ATC Code / Username</div>
                    <div class="detail-value" style="font-family:var(--mono),ui-monospace,monospace;font-weight:800;color:#4361ee;">${atc.atc_code || atc.login_username || '—'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Password</div>
                    <div class="detail-value" style="font-family:var(--mono),ui-monospace,monospace;font-weight:800;">${atc.login_password ? atc.login_password : '—'}</div>
                </div>
                <div class="detail-item full-width">
                    <div class="detail-label">Login tip</div>
                    <div class="detail-value" style="font-size:.85rem;color:#64748b;">Username is the ATC Code. Select "ATC Login" on the portal login page.</div>
                </div>
            </div>
        </div>
        
        <!-- Statistics Overview -->
        <div class="form-section">
            <div class="form-section-title">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                Statistics Overview
            </div>
            <div class="stats-grid-modal">
                <div class="stat-card-modal" style="background: linear-gradient(135deg, #6366f1, #8b5cf6);">
                    <div class="stat-icon-modal">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div class="stat-content-modal">
                        <div class="stat-label-modal">Total Admissions</div>
                        <div class="stat-value-modal">${admission.total_admissions || 0}</div>
                        <div class="stat-sub-modal">Active: ${admission.active_students || 0}</div>
                    </div>
                </div>
                
                <div class="stat-card-modal" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <div class="stat-icon-modal">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <div class="stat-content-modal">
                        <div class="stat-label-modal">Total Collected</div>
                        <div class="stat-value-modal">₹ ${Number(admission.total_collected || 0).toLocaleString()}</div>
                        <div class="stat-sub-modal">${collectionRate}% of total</div>
                    </div>
                </div>
                
                <div class="stat-card-modal" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                    <div class="stat-icon-modal">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div class="stat-content-modal">
                        <div class="stat-label-modal">Pending Fees</div>
                        <div class="stat-value-modal">₹ ${Number(admission.total_pending || 0).toLocaleString()}</div>
                        <div class="stat-sub-modal">Outstanding amount</div>
                    </div>
                </div>
                
                <div class="stat-card-modal" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
                    <div class="stat-icon-modal">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    </div>
                    <div class="stat-content-modal">
                        <div class="stat-label-modal">Total Inquiries</div>
                        <div class="stat-value-modal">${(inquiry.total_inquiries || 0) + (telephonic.total_telephonic || 0)}</div>
                        <div class="stat-sub-modal">Walk-in + Telephonic</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Course-wise Breakdown -->
        ${courses.length > 0 ? `
        <div class="form-section">
            <div class="form-section-title">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                Course-wise Breakdown
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Course Name</th>
                            <th>Students</th>
                            <th>Total Fees</th>
                            <th>Collected</th>
                            <th>Pending</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${courses.map(course => `
                            <tr>
                                <td><div class="cell-name">${course.course_name}</div></td>
                                <td><strong>${course.student_count}</strong></td>
                                <td>₹ ${Number(course.total_fees || 0).toLocaleString()}</td>
                                <td class="fee-collected">₹ ${Number(course.collected || 0).toLocaleString()}</td>
                                <td class="fee-pending">₹ ${Number(course.pending || 0).toLocaleString()}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        </div>
        ` : ''}
        
        <!-- Recent Students -->
        ${students.length > 0 ? `
        <div class="form-section">
            <div class="form-section-title">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Recent Students (Last 10)
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Course</th>
                            <th>Admission Date</th>
                            <th>Fees Paid</th>
                            <th>Pending</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${students.map(student => `
                            <tr>
                                <td><div class="cell-name">${student.student_id}</div></td>
                                <td><div class="cell-name">${student.student_name}</div></td>
                                <td><div class="cell-sub">${student.course_name}</div></td>
                                <td>${new Date(student.admission_date).toLocaleDateString('en-IN')}</td>
                                <td class="fee-collected">₹ ${Number(student.fees_paid || 0).toLocaleString()}</td>
                                <td class="fee-pending">₹ ${Number(student.fees_pending || 0).toLocaleString()}</td>
                                <td><span class="cell-badge status-${student.status.toLowerCase()}">${student.status}</span></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        </div>
        ` : `
        <div class="form-section">
            <div class="form-section-title">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Recent Students
            </div>
            <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 48px; height: 48px; margin: 0 auto; opacity: 0.3;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <p style="margin-top: 1rem;">No students admitted yet</p>
            </div>
        </div>
        `}
    `;

            document.getElementById('atcDetailsContent').innerHTML = html;
        }

        function closeDetailsModal() {
            document.getElementById('atcDetailsModal').classList.remove('active');
        }

        // Close modal on overlay click
        document.addEventListener('DOMContentLoaded', function () {
            const modal = document.getElementById('atcDetailsModal');
            if (modal) {
                modal.addEventListener('click', function (e) {
                    if (e.target === this) {
                        closeDetailsModal();
                    }
                });
            }

            // Close modal on Escape key
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    closeDetailsModal();
                }
            });
        });

        // Renew ATC authorization by 1 year
        function renewATC(id, name) {
            if (!confirm(`Renew authorization for "${name}" by 1 more year?`)) return;
            const fd = new URLSearchParams({ action: 'renew', id });
            fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        setTimeout(() => location.reload(), 1200);
                    } else {
                        showToast(data.message || 'Renewal failed', 'error');
                    }
                })
                .catch(() => showToast('Network error', 'error'));
        }

        /* ── WhatsApp Notify ── */
        function notifyATC(mobile, contactPerson, atcCode, username, password, trainUser, trainPass) {
            if (!mobile || mobile.length < 10) { showToast('No mobile number for this ATC', 'error'); return; }
            if (!username) { showToast('No login credentials found for this ATC', 'error'); return; }
            const phone = '91' + mobile.replace(/\D/g, '').slice(-10);
            let trainingBlock = '';
            if (trainUser && trainPass) {
                trainingBlock = `

*Training Portal Login:*
• Username: ${trainUser}
• Password: ${trainPass}
• Select "Training" on the login page`;
            }
            const msg = `*Welcome to Gyanam India!*

Dear ${contactPerson},

Congratulations on becoming an Authorized Training Center (ATC) under Gyanam India!

Your *ATC Code / Username:* *${atcCode || username}*

*ATC Login Details:*
• Portal: gyanamindia.labxco.in
• Username: *${username}*
• Temporary Password: *${password || 'password'}*
• On the login page, select *"ATC Login"*

⚠️ Please *change your password after first login* from your Profile section for security.

Do not share these credentials with anyone.${trainingBlock}

For any assistance, feel free to reach out to us.

Best Regards,
*Team Gyanam India*`;
            const url = 'https://wa.me/' + phone + '?text=' + encodeURIComponent(msg);
            window.open(url, '_blank');
        }
    </script>

</body>

</html>