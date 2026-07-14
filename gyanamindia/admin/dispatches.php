<?php
/**
 * Gyanam Portal — Admin: Materials Dispatch Management
 * Per-student, per-material dispatching with real-time stock tracking
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo      = getDBConnection();
$userName = sanitize(getUserName());

// ── Auto-create / migrate tables ─────────────────────────────────────────────
try { $pdo->query("SELECT 1 FROM material_dispatches LIMIT 1"); } catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS material_dispatches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dispatch_id VARCHAR(50) NOT NULL,
            atc_id INT NOT NULL,
            postal_service VARCHAR(100),
            tracking_id VARCHAR(100),
            dispatch_date DATE,
            notes TEXT,
            status ENUM('Pending','Dispatched','Delivered') DEFAULT 'Dispatched',
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
try { $pdo->query("SELECT 1 FROM material_dispatch_students LIMIT 1"); } catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS material_dispatch_students (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dispatch_id INT NOT NULL,
            admission_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
// New per-item tracking table
try { $pdo->query("SELECT 1 FROM dispatch_items LIMIT 1"); } catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dispatch_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dispatch_id INT NOT NULL,
            admission_id INT NOT NULL,
            item_type VARCHAR(50) NOT NULL,
            item_detail VARCHAR(100),
            inventory_item_id INT DEFAULT NULL,
            quantity INT DEFAULT 1,
            status ENUM('Dispatched','Pending') DEFAULT 'Pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// ── AJAX handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        switch ($_POST['action']) {

            // ── Load students with their material needs for an ATC ──
            case 'get_atc_student_materials':
                $atcId = (int)$_POST['atc_id'];
                if (!$atcId) { echo json_encode(['success' => false, 'message' => 'ATC ID required']); exit; }

                // Get students who have material_type = 'With Material' and are Active
                $stmt = $pdo->prepare("
                    SELECT a.id, a.roll_no, a.registration_id,
                           TRIM(CONCAT(a.first_name,' ',COALESCE(a.middle_name,''),' ',a.last_name)) AS student_name,
                           a.course, a.uniform_size, a.material_language, a.material_type,
                           a.admission_date
                    FROM admissions a
                    WHERE a.atc_id = ? AND a.status = 'Active' AND a.material_type = 'With Material'
                    ORDER BY a.first_name ASC
                ");
                $stmt->execute([$atcId]);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Get all inventory items for matching
                $invItems = [];
                try {
                    $invItems = $pdo->query("SELECT id, item_name, category, current_stock FROM inventory_items WHERE status='Active'")->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {}

                // Get already dispatched items for these students
                $dispatchedMap = []; // admission_id => [item_type => status]
                try {
                    $admIds = array_column($students, 'id');
                    if (!empty($admIds)) {
                        $placeholders = implode(',', array_fill(0, count($admIds), '?'));
                        $diStmt = $pdo->prepare("SELECT admission_id, item_type, item_detail, status FROM dispatch_items WHERE admission_id IN ($placeholders)");
                        $diStmt->execute($admIds);
                        foreach ($diStmt->fetchAll(PDO::FETCH_ASSOC) as $di) {
                            $key = $di['admission_id'] . '_' . $di['item_type'] . '_' . $di['item_detail'];
                            $dispatchedMap[$key] = $di['status'];
                        }
                    }
                } catch (Exception $e) {}

                // Also check legacy material_dispatch_students (old dispatches without dispatch_items)
                $legacyDispatched = [];
                try {
                    if (!empty($admIds)) {
                        $placeholders = implode(',', array_fill(0, count($admIds), '?'));
                        $legStmt = $pdo->prepare("SELECT DISTINCT admission_id FROM material_dispatch_students WHERE admission_id IN ($placeholders)");
                        $legStmt->execute($admIds);
                        $legacyDispatched = $legStmt->fetchAll(PDO::FETCH_COLUMN);
                    }
                } catch (Exception $e) {}

                // Build per-student material data
                $result = [];
                foreach ($students as $s) {
                    $materials = [];
                    $studentFullyDispatched = true;

                    // Check if this student was dispatched via legacy system (old dispatches)
                    $isLegacyDispatched = in_array($s['id'], $legacyDispatched);

                    // T-Shirt material (if uniform_size is set)
                    if (!empty($s['uniform_size'])) {
                        $size = $s['uniform_size'];
                        $tKey = $s['id'] . '_T-Shirt_Size ' . $size;
                        $alreadyStatus = $dispatchedMap[$tKey] ?? null;

                        // Skip if already dispatched
                        if ($alreadyStatus === 'Dispatched' || ($isLegacyDispatched && !$alreadyStatus)) {
                            // Already handled
                        } else {
                            // Find matching inventory item
                            $matchedItem = null;
                            $matchedStock = 0;
                            foreach ($invItems as $inv) {
                                if ($inv['category'] === 'T-Shirts' && stripos($inv['item_name'], $size) !== false) {
                                    $matchedItem = $inv;
                                    $matchedStock = (int)$inv['current_stock'];
                                    break;
                                }
                            }
                            $materials[] = [
                                'type' => 'T-Shirt',
                                'detail' => 'Size ' . $size,
                                'inventory_item_id' => $matchedItem ? $matchedItem['id'] : null,
                                'inventory_item_name' => $matchedItem ? $matchedItem['item_name'] : null,
                                'stock' => $matchedStock,
                                'status' => $alreadyStatus === 'Pending' ? 'pending_dispatch' : ($matchedStock > 0 ? 'available' : 'out_of_stock'),
                                'pending_dispatch_id' => $alreadyStatus === 'Pending' ? true : false
                            ];
                            $studentFullyDispatched = false;
                        }
                    }

                    // Book material (if material_language is set)
                    if (!empty($s['material_language'])) {
                        $lang = $s['material_language'];
                        $bKey = $s['id'] . '_Book_' . $lang;
                        $alreadyStatus = $dispatchedMap[$bKey] ?? null;

                        if ($alreadyStatus === 'Dispatched' || ($isLegacyDispatched && !$alreadyStatus)) {
                            // Already handled
                        } else {
                            $matchedItem = null;
                            $matchedStock = 0;
                            foreach ($invItems as $inv) {
                                if ($inv['category'] === 'Books' && stripos($inv['item_name'], $lang) !== false) {
                                    $matchedItem = $inv;
                                    $matchedStock = (int)$inv['current_stock'];
                                    break;
                                }
                            }
                            $materials[] = [
                                'type' => 'Book',
                                'detail' => $lang,
                                'inventory_item_id' => $matchedItem ? $matchedItem['id'] : null,
                                'inventory_item_name' => $matchedItem ? $matchedItem['item_name'] : null,
                                'stock' => $matchedStock,
                                'status' => $alreadyStatus === 'Pending' ? 'pending_dispatch' : ($matchedStock > 0 ? 'available' : 'out_of_stock'),
                                'pending_dispatch_id' => $alreadyStatus === 'Pending' ? true : false
                            ];
                            $studentFullyDispatched = false;
                        }
                    }

                    // Certificate material (every student needs a certificate with their course)
                    $certKey = $s['id'] . '_Certificate_' . ($s['course'] ?? 'General');
                    $certStatus = $dispatchedMap[$certKey] ?? null;
                    if ($certStatus !== 'Dispatched' && !($isLegacyDispatched && !$certStatus)) {
                        $matchedCert = null;
                        $matchedCertStock = 0;
                        foreach ($invItems as $inv) {
                            if ($inv['category'] === 'Certificates' && stripos($inv['item_name'], 'Course Completion') !== false) {
                                $matchedCert = $inv;
                                $matchedCertStock = (int)$inv['current_stock'];
                                break;
                            }
                        }
                        $materials[] = [
                            'type' => 'Certificate',
                            'detail' => $s['course'] ?? 'General',
                            'inventory_item_id' => $matchedCert ? $matchedCert['id'] : null,
                            'inventory_item_name' => $matchedCert ? $matchedCert['item_name'] : null,
                            'stock' => $matchedCertStock,
                            'status' => $certStatus === 'Pending' ? 'pending_dispatch' : ($matchedCertStock > 0 ? 'available' : 'out_of_stock'),
                            'pending_dispatch_id' => $certStatus === 'Pending' ? true : false
                        ];
                        $studentFullyDispatched = false;
                    }

                    // Only include students who have un-dispatched materials
                    if (!empty($materials)) {
                        $result[] = [
                            'id' => $s['id'],
                            'student_name' => $s['student_name'],
                            'roll_no' => $s['roll_no'],
                            'registration_id' => $s['registration_id'],
                            'course' => $s['course'],
                            'admission_date' => $s['admission_date'],
                            'materials' => $materials
                        ];
                    }
                }

                echo json_encode(['success' => true, 'data' => $result]);
                exit;

            // ── Create dispatch with per-student, per-item tracking ──
            case 'create_dispatch':
                $atcId        = (int)$_POST['atc_id'];
                $postalSvc    = trim($_POST['postal_service'] ?? '');
                $trackingId   = trim($_POST['tracking_id'] ?? '');
                $dispatchDate = $_POST['dispatch_date'] ?? date('Y-m-d');
                $notes        = trim($_POST['notes'] ?? '');
                $itemsData    = json_decode($_POST['dispatch_items'] ?? '[]', true);

                if (!$atcId || !$postalSvc || !$dispatchDate || empty($itemsData)) {
                    echo json_encode(['success' => false, 'message' => 'Missing required fields or no items selected']);
                    exit;
                }

                // Validate stock for items marked as 'dispatch'
                foreach ($itemsData as $item) {
                    if ($item['action'] === 'dispatch' && !empty($item['inventory_item_id'])) {
                        $chk = $pdo->prepare("SELECT item_name, current_stock FROM inventory_items WHERE id = ?");
                        $chk->execute([$item['inventory_item_id']]);
                        $row = $chk->fetch(PDO::FETCH_ASSOC);
                        if ($row && $row['current_stock'] < 1) {
                            echo json_encode(['success' => false, 'message' => "Insufficient stock for \"{$row['item_name']}\". Available: {$row['current_stock']}"]);
                            exit;
                        }
                    }
                }

                // Generate dispatch ID
                $prefix = 'DISP-' . date('Ymd') . '-';
                $last   = $pdo->query("SELECT dispatch_id FROM material_dispatches WHERE dispatch_id LIKE '{$prefix}%' ORDER BY id DESC LIMIT 1")->fetchColumn();
                $seq    = $last ? (intval(substr($last, -3)) + 1) : 1;
                $dispId = $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);

                $pdo->beginTransaction();

                // Create dispatch record
                $stmt = $pdo->prepare("
                    INSERT INTO material_dispatches (dispatch_id, atc_id, postal_service, tracking_id, dispatch_date, notes, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, 'Dispatched', ?)
                ");
                $stmt->execute([$dispId, $atcId, $postalSvc, $trackingId ?: null, $dispatchDate, $notes ?: null, $_SESSION['user_id'] ?? null]);
                $dispPK = $pdo->lastInsertId();

                // Track unique admission IDs for material_dispatch_students
                $admissionIds = [];
                $dispatchedCount = 0;
                $pendingCount = 0;

                foreach ($itemsData as $item) {
                    $admId = (int)$item['admission_id'];
                    $admissionIds[$admId] = true;

                    $itemStatus = ($item['action'] === 'dispatch') ? 'Dispatched' : 'Pending';

                    // Insert into dispatch_items
                    $pdo->prepare("INSERT INTO dispatch_items (dispatch_id, admission_id, item_type, item_detail, inventory_item_id, quantity, status) VALUES (?, ?, ?, ?, ?, 1, ?)")
                        ->execute([$dispPK, $admId, $item['type'], $item['detail'], $item['inventory_item_id'] ?: null, $itemStatus]);

                    // Deduct inventory if dispatching
                    if ($itemStatus === 'Dispatched' && !empty($item['inventory_item_id'])) {
                        $invId = (int)$item['inventory_item_id'];
                        $pdo->prepare("UPDATE inventory_items SET current_stock = current_stock - 1 WHERE id = ? AND current_stock > 0")->execute([$invId]);
                        
                        // Record transaction
                        $bal = $pdo->prepare("SELECT current_stock FROM inventory_items WHERE id = ?");
                        $bal->execute([$invId]); $newBal = $bal->fetchColumn();
                        
                        try {
                            $pdo->prepare("INSERT INTO inventory_transactions (item_id, type, quantity, running_balance, reference_no, dispatch_id, atc_id, notes, created_by) VALUES (?, 'Dispatch', ?, ?, ?, ?, ?, ?, ?)")
                                ->execute([$invId, -1, $newBal, $dispId, $dispPK, $atcId, "Dispatched {$item['type']} ({$item['detail']}) for student #{$admId}", $_SESSION['user_id'] ?? null]);
                        } catch (Exception $e) { /* non-fatal */ }
                        
                        $dispatchedCount++;
                    } else {
                        $pendingCount++;
                    }
                }

                // Insert into material_dispatch_students ONLY for students with ALL items dispatched
                // Track per-student: how many items vs how many dispatched
                $perStudentItems = [];
                $perStudentDispatched = [];
                foreach ($itemsData as $item) {
                    $admId = (int)$item['admission_id'];
                    $perStudentItems[$admId] = ($perStudentItems[$admId] ?? 0) + 1;
                    if ($item['action'] === 'dispatch') {
                        $perStudentDispatched[$admId] = ($perStudentDispatched[$admId] ?? 0) + 1;
                    }
                }

                $ins = $pdo->prepare("INSERT INTO material_dispatch_students (dispatch_id, admission_id) VALUES (?, ?)");
                foreach ($perStudentItems as $admId => $totalItems) {
                    $dispItems = $perStudentDispatched[$admId] ?? 0;
                    // Only insert if ALL items for this student were dispatched
                    if ($dispItems >= $totalItems) {
                        try { $ins->execute([$dispPK, $admId]); } catch (Exception $e) { /* ignore dups */ }
                    }
                }

                $pdo->commit();

                $msg = "Dispatch {$dispId} created — {$dispatchedCount} item(s) dispatched";
                if ($pendingCount > 0) $msg .= ", {$pendingCount} item(s) marked pending";
                echo json_encode(['success' => true, 'message' => $msg, 'dispatch_id' => $dispId]);
                exit;

            // ── Update dispatch status ──
            case 'update_status':
                $stmt = $pdo->prepare("UPDATE material_dispatches SET status = ? WHERE id = ?");
                $stmt->execute([$_POST['status'], (int)$_POST['id']]);
                echo json_encode(['success' => true, 'message' => 'Status updated']);
                exit;

            // ── Get dispatch details (per-item breakdown) ──
            case 'get_dispatch_details':
                $dispPK = (int)$_POST['id'];
                
                // Try new dispatch_items table first
                $items = [];
                try {
                    $stmt = $pdo->prepare("
                        SELECT di.*, 
                               TRIM(CONCAT(a.first_name,' ',COALESCE(a.middle_name,''),' ',a.last_name)) AS student_name,
                               a.roll_no, a.registration_id, a.course
                        FROM dispatch_items di
                        JOIN admissions a ON di.admission_id = a.id
                        WHERE di.dispatch_id = ?
                        ORDER BY a.first_name, di.item_type
                    ");
                    $stmt->execute([$dispPK]);
                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {}

                // Fallback to legacy material_dispatch_students
                if (empty($items)) {
                    $stmt = $pdo->prepare("
                        SELECT a.roll_no, a.registration_id,
                               TRIM(CONCAT(a.first_name,' ',COALESCE(a.middle_name,''),' ',a.last_name)) AS student_name,
                               a.course, a.material_language, a.uniform_size
                        FROM material_dispatch_students mds
                        JOIN admissions a ON mds.admission_id = a.id
                        WHERE mds.dispatch_id = ?
                    ");
                    $stmt->execute([$dispPK]);
                    $legacyStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    // Convert to item-like format
                    foreach ($legacyStudents as $ls) {
                        if (!empty($ls['uniform_size'])) {
                            $items[] = ['student_name' => $ls['student_name'], 'roll_no' => $ls['roll_no'], 'registration_id' => $ls['registration_id'], 'course' => $ls['course'], 'item_type' => 'T-Shirt', 'item_detail' => 'Size ' . $ls['uniform_size'], 'status' => 'Dispatched'];
                        }
                        if (!empty($ls['material_language'])) {
                            $items[] = ['student_name' => $ls['student_name'], 'roll_no' => $ls['roll_no'], 'registration_id' => $ls['registration_id'], 'course' => $ls['course'], 'item_type' => 'Book', 'item_detail' => $ls['material_language'], 'status' => 'Dispatched'];
                        }
                    }
                }

                echo json_encode(['success' => true, 'data' => $items]);
                exit;

            // ── Notify ATC about unavailable items ──
            case 'notify_atc_pending':
                $dispPK = (int)$_POST['dispatch_pk'];
                $disp = $pdo->prepare("SELECT dispatch_id, atc_id FROM material_dispatches WHERE id = ?");
                $disp->execute([$dispPK]); $dispRow = $disp->fetch(PDO::FETCH_ASSOC);
                if (!$dispRow) { echo json_encode(['success' => false, 'message' => 'Dispatch not found']); exit; }

                // Get pending items summary
                $pendStmt = $pdo->prepare("SELECT item_type, item_detail, COUNT(*) as cnt FROM dispatch_items WHERE dispatch_id = ? AND status = 'Pending' GROUP BY item_type, item_detail");
                $pendStmt->execute([$dispPK]);
                $pendItems = $pendStmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($pendItems)) { echo json_encode(['success' => false, 'message' => 'No pending items found']); exit; }

                $lines = [];
                foreach ($pendItems as $pi) {
                    $lines[] = "{$pi['cnt']} × {$pi['item_type']} ({$pi['item_detail']})";
                }
                $msg = "Dispatch {$dispRow['dispatch_id']}: The following items could not be dispatched due to unavailability and will be sent when available: " . implode(', ', $lines) . ".";

                try {
                    $pdo->prepare("INSERT INTO notifications (sender_id, title, message, target_type, target_id) VALUES (?, ?, ?, 'ATC', ?)")
                        ->execute([$_SESSION['user_id'] ?? 1, 'Material Dispatch — Pending Items', $msg, $dispRow['atc_id']]);
                    echo json_encode(['success' => true, 'message' => 'ATC has been notified about pending materials']);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => 'Could not send notification: ' . $e->getMessage()]);
                }
                exit;

            // ── Get complaints for admin view ──
            case 'get_complaints':
                $dispPK = (int)$_POST['dispatch_pk'];
                try {
                    $cStmt = $pdo->prepare("
                        SELECT dc.*, atc.name AS atc_name
                        FROM dispatch_complaints dc
                        JOIN atc_centers atc ON dc.atc_id = atc.id
                        WHERE dc.dispatch_id = ?
                        ORDER BY dc.created_at DESC
                    ");
                    $cStmt->execute([$dispPK]);
                    echo json_encode(['success' => true, 'data' => $cStmt->fetchAll(PDO::FETCH_ASSOC)]);
                } catch (Exception $e) {
                    echo json_encode(['success' => true, 'data' => []]);
                }
                exit;

            // ── Resolve complaint ──
            case 'resolve_complaint':
                $cid = (int)$_POST['complaint_id'];
                $response = trim($_POST['response'] ?? '');
                $newStatus = $_POST['new_status'] ?? 'Resolved';
                $pdo->prepare("UPDATE dispatch_complaints SET status = ?, admin_response = ?, resolved_at = NOW() WHERE id = ?")
                    ->execute([$newStatus, $response, $cid]);
                echo json_encode(['success' => true, 'message' => 'Complaint updated']);
                exit;

        } // end switch
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── Fetch dispatches list ────────────────────────────────────────────────────
$dispatches = [];
try {
    $dispatches = $pdo->query("
        SELECT d.*, ac.name AS atc_name,
               COUNT(DISTINCT mds.admission_id) AS student_count
        FROM material_dispatches d
        JOIN atc_centers ac ON d.atc_id = ac.id
        LEFT JOIN material_dispatch_students mds ON mds.dispatch_id = d.id
        GROUP BY d.id
        ORDER BY d.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Fetch item counts per dispatch
$dispatchItemCounts = [];
try {
    $diCounts = $pdo->query("
        SELECT dispatch_id,
               SUM(CASE WHEN status='Dispatched' THEN 1 ELSE 0 END) AS dispatched_items,
               SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) AS pending_items,
               COUNT(*) AS total_items
        FROM dispatch_items GROUP BY dispatch_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($diCounts as $dc) { $dispatchItemCounts[$dc['dispatch_id']] = $dc; }
} catch (Exception $e) {}

// Fetch ATC list
$atcList = [];
try { $atcList = $pdo->query("SELECT id, name FROM atc_centers WHERE status='Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

// KPIs
$kpiTotal      = count($dispatches);
$kpiDispatched = count(array_filter($dispatches, fn($d) => $d['status'] === 'Dispatched'));
$kpiDelivered  = count(array_filter($dispatches, fn($d) => $d['status'] === 'Delivered'));

// Pending students (material_type = With Material, not yet fully dispatched)
$pendingStudentCount = 0;
try {
    $pendingStudentCount = $pdo->query("
        SELECT COUNT(*) FROM admissions
        WHERE material_type = 'With Material' AND status = 'Active'
          AND id NOT IN (SELECT DISTINCT admission_id FROM material_dispatch_students)
    ")->fetchColumn();
} catch (Exception $e) {}

// Pending items count
$pendingItemCount = 0;
try { $pendingItemCount = $pdo->query("SELECT COUNT(*) FROM dispatch_items WHERE status='Pending'")->fetchColumn(); } catch (Exception $e) {}

// ── Stock Requirements Dashboard Data ─────────────────────────────────────
$stockRequirements = [];
try {
    // Get all inventory items
    $allInv = $pdo->query("SELECT id, item_name, category, current_stock, min_stock_level FROM inventory_items WHERE status='Active' ORDER BY category, item_name")->fetchAll(PDO::FETCH_ASSOC);

    // Get all un-dispatched material needs
    // Books needed
    $bookNeeds = $pdo->query("
        SELECT a.material_language AS detail, COUNT(*) AS cnt
        FROM admissions a
        WHERE a.material_type = 'With Material' AND a.status = 'Active'
          AND a.material_language IS NOT NULL AND a.material_language != ''
          AND CONCAT(a.id, '_Book_', a.material_language) NOT IN (
              SELECT CONCAT(di.admission_id, '_', di.item_type, '_', di.item_detail)
              FROM dispatch_items di WHERE di.item_type = 'Book' AND di.status = 'Dispatched'
          )
          AND a.id NOT IN (SELECT DISTINCT admission_id FROM material_dispatch_students)
        GROUP BY a.material_language
    ")->fetchAll(PDO::FETCH_ASSOC);

    // T-Shirts needed
    $tshirtNeeds = $pdo->query("
        SELECT CONCAT('Size ', a.uniform_size) AS detail, COUNT(*) AS cnt
        FROM admissions a
        WHERE a.material_type = 'With Material' AND a.status = 'Active'
          AND a.uniform_size IS NOT NULL AND a.uniform_size != ''
          AND CONCAT(a.id, '_T-Shirt_Size ', a.uniform_size) NOT IN (
              SELECT CONCAT(di.admission_id, '_', di.item_type, '_', di.item_detail)
              FROM dispatch_items di WHERE di.item_type = 'T-Shirt' AND di.status = 'Dispatched'
          )
          AND a.id NOT IN (SELECT DISTINCT admission_id FROM material_dispatch_students)
        GROUP BY a.uniform_size
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Certificates needed (every student with material)
    $certNeeds = $pdo->query("
        SELECT COALESCE(a.course, 'General') AS detail, COUNT(*) AS cnt
        FROM admissions a
        WHERE a.material_type = 'With Material' AND a.status = 'Active'
          AND CONCAT(a.id, '_Certificate_', COALESCE(a.course, 'General')) NOT IN (
              SELECT CONCAT(di.admission_id, '_', di.item_type, '_', di.item_detail)
              FROM dispatch_items di WHERE di.item_type = 'Certificate' AND di.status = 'Dispatched'
          )
          AND a.id NOT IN (SELECT DISTINCT admission_id FROM material_dispatch_students)
        GROUP BY a.course
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Build needs map: item_name => needed_count
    $needsMap = [];
    foreach ($bookNeeds as $b) {
        foreach ($allInv as $inv) {
            if ($inv['category'] === 'Books' && stripos($inv['item_name'], $b['detail']) !== false) {
                $needsMap[$inv['id']] = ($needsMap[$inv['id']] ?? 0) + (int)$b['cnt'];
                break;
            }
        }
    }
    foreach ($tshirtNeeds as $t) {
        $sz = str_replace('Size ', '', $t['detail']);
        foreach ($allInv as $inv) {
            if ($inv['category'] === 'T-Shirts' && stripos($inv['item_name'], $sz) !== false) {
                $needsMap[$inv['id']] = ($needsMap[$inv['id']] ?? 0) + (int)$t['cnt'];
                break;
            }
        }
    }
    // All certs map to Course Completion Certificate item
    $totalCertNeed = 0;
    foreach ($certNeeds as $c) $totalCertNeed += (int)$c['cnt'];
    foreach ($allInv as $inv) {
        if ($inv['category'] === 'Certificates' && stripos($inv['item_name'], 'Course Completion') !== false) {
            $needsMap[$inv['id']] = ($needsMap[$inv['id']] ?? 0) + $totalCertNeed;
            break;
        }
    }

    // Build final stock requirements array
    foreach ($allInv as $inv) {
        $needed = $needsMap[$inv['id']] ?? 0;
        $stock = (int)$inv['current_stock'];
        $deficit = $stock - $needed;
        if ($needed > 0 || $stock > 0) {
            $stockRequirements[] = [
                'id' => $inv['id'],
                'name' => $inv['item_name'],
                'category' => $inv['category'],
                'stock' => $stock,
                'needed' => $needed,
                'deficit' => $deficit,
                'min_level' => (int)$inv['min_stock_level']
            ];
        }
    }
} catch (Exception $e) {
    // Stock dashboard is non-critical, silently fail
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Materials Dispatch — Head Office | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📦</text></svg>">
    <style>
    :root {
        --font: 'Sora', sans-serif;
        --mono: 'JetBrains Mono', monospace;
        --bg: #f4f6fb; --surface: #fff; --surface-2: #f9fafc; --surface-3: #f0f3f9;
        --border: #e6eaf3; --border-2: #d4dae8;
        --text: #111827; --text-2: #374151; --text-3: #6b7280;
        --brand: #4f46e5; --brand-dark: #4338ca; --brand-glow: rgba(79,70,229,.2);
        --emerald: #10b981; --emerald-soft: #ecfdf5; --emerald-dark: #065f46;
        --amber: #f59e0b; --amber-soft: #fffbeb; --amber-dark: #92400e;
        --rose: #f43f5e; --rose-soft: #fff1f2; --rose-dark: #9f1239;
        --sky: #0ea5e9; --sky-soft: #f0f9ff;
        --r-md: 10px; --r-lg: 14px; --r-xl: 20px; --r-full: 99px;
    }
    /* KPI Row */
    .kpi-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 1.25rem; margin-bottom: 2rem; }
    .kpi-card { background: var(--surface); border: 1.5px solid var(--border); border-radius: var(--r-xl); padding: 1.5rem; position: relative; overflow: hidden; transition: transform .2s, box-shadow .2s; }
    .kpi-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,.08); }
    .kpi-card::before { content:''; position: absolute; top: 0; left: 0; right: 0; height: 3px; }
    .kpi-card.blue::before { background: linear-gradient(90deg, #3b82f6, #6366f1); }
    .kpi-card.green::before { background: linear-gradient(90deg, var(--emerald), #34d399); }
    .kpi-card.amber::before { background: linear-gradient(90deg, var(--amber), #fbbf24); }
    .kpi-card.purple::before { background: linear-gradient(90deg, #8b5cf6, #a78bfa); }
    .kpi-card.orange::before { background: linear-gradient(90deg, #f97316, #fb923c); }
    .kpi-val { font-size: 2rem; font-weight: 800; color: var(--text); line-height: 1; font-family: var(--mono); }
    .kpi-lbl { font-size: .72rem; font-weight: 700; color: var(--text-3); text-transform: uppercase; letter-spacing: .05em; margin-top: .35rem; }
    /* Toolbar */
    .toolbar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
    .btn { display: inline-flex; align-items: center; gap: .5rem; padding: .7rem 1.25rem; border: none; border-radius: var(--r-md); font-size: .85rem; font-weight: 700; cursor: pointer; transition: all .2s; font-family: var(--font); }
    .btn-primary { background: linear-gradient(135deg, var(--brand), var(--brand-dark)); color: #fff; box-shadow: 0 4px 14px var(--brand-glow); }
    .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 8px 20px var(--brand-glow); }
    .btn-secondary { background: var(--surface); border: 1.5px solid var(--border); color: var(--text-2); }
    .btn-secondary:hover { background: var(--surface-3); }
    .btn-sm { padding: .4rem .85rem; font-size: .78rem; }
    .btn-success { background: linear-gradient(135deg, var(--emerald), #059669); color: #fff; }
    /* Table */
    .section-card { background: var(--surface); border: 1.5px solid var(--border); border-radius: var(--r-xl); overflow: hidden; }
    .tbl { width: 100%; border-collapse: collapse; font-size: .855rem; }
    .tbl th { padding: .85rem 1rem; text-align: left; font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-3); border-bottom: 2px solid var(--border); background: var(--surface-2); }
    .tbl td { padding: .85rem 1rem; border-bottom: 1px solid var(--border); vertical-align: middle; color: var(--text-2); }
    .tbl tr:last-child td { border-bottom: none; }
    .tbl tr:hover td { background: var(--surface-3); }
    .dispatch-id { font-family: var(--mono); font-weight: 700; color: var(--text); font-size: .83rem; }
    /* Status badges */
    .badge { display: inline-flex; align-items: center; gap: .3rem; padding: .25rem .7rem; border-radius: var(--r-full); font-size: .72rem; font-weight: 700; }
    .badge-dispatched { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .badge-delivered { background: var(--emerald-soft); color: var(--emerald-dark); border: 1px solid #a7f3d0; }
    .badge-pending { background: var(--amber-soft); color: var(--amber-dark); border: 1px solid #fde68a; }
    .items-pills { display: flex; gap: .4rem; flex-wrap: wrap; }
    .items-pill { padding: .2rem .55rem; border-radius: 6px; font-size: .7rem; font-weight: 700; }
    .items-pill.green { background: var(--emerald-soft); color: var(--emerald-dark); }
    .items-pill.amber { background: var(--amber-soft); color: var(--amber-dark); }
    /* Modal */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.5); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 1000; }
    .modal-overlay.open { display: flex; }
    .modal { background: var(--surface); border-radius: var(--r-xl); width: min(820px, 95vw); max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; animation: slideUp .3s ease; box-shadow: 0 24px 60px rgba(0,0,0,.2); }
    @keyframes slideUp { from { transform: translateY(24px); opacity: 0; } to { transform: none; opacity: 1; } }
    .modal-header { padding: 1.5rem 1.75rem; border-bottom: 1.5px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: var(--surface-2); }
    .modal-header h3 { font-size: 1.1rem; font-weight: 800; color: var(--text); margin: 0; display: flex; align-items: center; gap: .5rem; }
    .modal-body { padding: 1.5rem 1.75rem; overflow-y: auto; flex: 1; }
    .modal-footer { padding: 1.25rem 1.75rem; border-top: 1.5px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--surface-2); }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
    .form-row.full { grid-template-columns: 1fr; }
    .form-group { display: flex; flex-direction: column; gap: .4rem; }
    .form-group label { font-size: .78rem; font-weight: 700; color: var(--text-2); text-transform: uppercase; letter-spacing: .03em; }
    .form-group input, .form-group select, .form-group textarea {
        padding: .65rem .9rem; border: 1.5px solid var(--border); border-radius: var(--r-md);
        font-size: .875rem; outline: none; transition: border-color .2s; font-family: var(--font); color: var(--text);
    }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--brand); }
    .close-btn { border: none; background: none; cursor: pointer; font-size: 1.3rem; color: var(--text-3); padding: .25rem; border-radius: 8px; transition: background .15s; }
    .close-btn:hover { background: var(--surface-3); }
    /* Student material list */
    .student-materials-list { max-height: 400px; overflow-y: auto; border: 1.5px solid var(--border); border-radius: var(--r-lg); }
    .stu-card { border-bottom: 1.5px solid var(--border); padding: 0; transition: background .15s; }
    .stu-card:last-child { border-bottom: none; }
    .stu-header { display: flex; align-items: center; gap: .75rem; padding: .85rem 1rem; cursor: pointer; }
    .stu-header:hover { background: var(--surface-3); }
    .stu-header input[type=checkbox] { width: 17px; height: 17px; accent-color: var(--brand); cursor: pointer; flex-shrink: 0; }
    .stu-name { font-weight: 700; font-size: .88rem; color: var(--text); }
    .stu-meta { font-size: .72rem; color: var(--text-3); margin-top: .1rem; }
    .stu-items { padding: 0 1rem .75rem 2.75rem; display: flex; flex-direction: column; gap: .5rem; }
    .mat-item { display: flex; align-items: center; gap: .65rem; padding: .55rem .75rem; background: var(--surface-2); border-radius: var(--r-md); border: 1px solid var(--border); }
    .mat-item input[type=checkbox] { width: 15px; height: 15px; accent-color: var(--brand); cursor: pointer; }
    .mat-icon { font-size: 1.1rem; }
    .mat-label { font-size: .82rem; font-weight: 600; color: var(--text); flex: 1; }
    .mat-stock { font-size: .72rem; font-weight: 700; padding: .2rem .5rem; border-radius: 6px; }
    .mat-stock.available { background: var(--emerald-soft); color: var(--emerald-dark); }
    .mat-stock.low { background: var(--amber-soft); color: var(--amber-dark); }
    .mat-stock.out { background: var(--rose-soft); color: var(--rose-dark); }
    .mat-stock.pending { background: #eff6ff; color: #1d4ed8; }
    .summary-bar { display: flex; gap: 1rem; align-items: center; font-size: .82rem; font-weight: 600; }
    .summary-bar .green { color: var(--emerald); }
    .summary-bar .amber { color: var(--amber-dark); }
    .alert-empty { padding: 2rem; text-align: center; color: var(--text-3); font-size: .875rem; }
    .sel-actions { display: flex; gap: .5rem; align-items: center; margin-bottom: .75rem; }
    .sel-actions button { padding: .35rem .75rem; border: 1.5px solid var(--border); border-radius: 8px; background: var(--surface); cursor: pointer; font-size: .75rem; font-weight: 700; color: var(--text-2); font-family: var(--font); transition: all .15s; }
    .sel-actions button:hover { background: var(--surface-3); }
    .sel-actions .count { font-size: .8rem; color: var(--brand); font-weight: 700; }
    /* Filter select */
    .filter-sel { padding: .55rem .9rem; border: 1.5px solid var(--border); border-radius: var(--r-md); font-size: .875rem; font-family: var(--font); color: var(--text); background: var(--surface); min-width: 180px; }
    @media (max-width: 900px) { .kpi-row { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 600px) { .kpi-row { grid-template-columns: 1fr; } .form-row { grid-template-columns: 1fr; } }
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
                    <h2>📦 Materials Dispatch</h2>
                    <p>Per-student material dispatching with stock tracking</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">

            <!-- KPI Row -->
            <div class="kpi-row">
                <div class="kpi-card orange">
                    <div class="kpi-val"><?= $pendingStudentCount ?></div>
                    <div class="kpi-lbl">Students Awaiting</div>
                </div>
                <div class="kpi-card purple">
                    <div class="kpi-val"><?= $pendingItemCount ?></div>
                    <div class="kpi-lbl">Pending Items</div>
                </div>
                <div class="kpi-card blue">
                    <div class="kpi-val"><?= $kpiTotal ?></div>
                    <div class="kpi-lbl">Total Dispatches</div>
                </div>
                <div class="kpi-card amber">
                    <div class="kpi-val"><?= $kpiDispatched ?></div>
                    <div class="kpi-lbl">In Transit</div>
                </div>
                <div class="kpi-card green">
                    <div class="kpi-val"><?= $kpiDelivered ?></div>
                    <div class="kpi-lbl">Delivered</div>
                </div>
            </div>

            <!-- Stock Requirements Dashboard -->
            <?php if (!empty($stockRequirements)): ?>
            <div class="section-card" style="margin-bottom:1.5rem;border-left:4px solid var(--brand)">
                <div style="padding:1rem 1.25rem;border-bottom:1.5px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--surface-2);cursor:pointer" onclick="document.getElementById('stockDash').style.display = document.getElementById('stockDash').style.display === 'none' ? '' : 'none'">
                    <div style="display:flex;align-items:center;gap:.6rem">
                        <span style="font-size:1.1rem">📊</span>
                        <span style="font-weight:800;font-size:.9rem;color:var(--text)">Stock Requirements — Needed vs Available</span>
                        <span style="background:<?= count(array_filter($stockRequirements, fn($s) => $s['deficit'] < 0)) > 0 ? 'var(--rose-soft)' : 'var(--emerald-soft)' ?>;color:<?= count(array_filter($stockRequirements, fn($s) => $s['deficit'] < 0)) > 0 ? 'var(--rose-dark)' : 'var(--emerald-dark)' ?>;padding:.2rem .65rem;border-radius:999px;font-size:.72rem;font-weight:800"><?= count(array_filter($stockRequirements, fn($s) => $s['deficit'] < 0)) ?> items need restocking</span>
                    </div>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="color:var(--text-3);transition:transform .2s"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
                <div id="stockDash" style="overflow-x:auto">
                    <table class="tbl" style="font-size:.82rem">
                        <thead>
                            <tr>
                                <th>Material</th>
                                <th>Category</th>
                                <th style="text-align:center">In Stock</th>
                                <th style="text-align:center">Needed</th>
                                <th style="text-align:center">Deficit</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($stockRequirements as $sr): ?>
                        <tr>
                            <td style="font-weight:700;color:var(--text)"><?= htmlspecialchars($sr['name']) ?></td>
                            <td>
                                <span style="padding:.2rem .55rem;border-radius:6px;font-size:.7rem;font-weight:700;<?php
                                    if ($sr['category'] === 'Books') echo 'background:#eff6ff;color:#1d4ed8';
                                    elseif ($sr['category'] === 'T-Shirts') echo 'background:#fdf4ff;color:#a855f7';
                                    elseif ($sr['category'] === 'Certificates') echo 'background:#ecfdf5;color:#065f46';
                                    else echo 'background:#f3f4f6;color:#6b7280';
                                ?>"><?= $sr['category'] ?></span>
                            </td>
                            <td style="text-align:center;font-weight:700;font-family:var(--mono)"><?= $sr['stock'] ?></td>
                            <td style="text-align:center;font-weight:700;font-family:var(--mono);color:var(--brand)"><?= $sr['needed'] ?></td>
                            <td style="text-align:center;font-weight:800;font-family:var(--mono);color:<?= $sr['deficit'] < 0 ? 'var(--rose)' : ($sr['deficit'] == 0 ? 'var(--amber)' : 'var(--emerald)') ?>">
                                <?= $sr['deficit'] >= 0 ? '+' . $sr['deficit'] : $sr['deficit'] ?>
                            </td>
                            <td>
                                <?php if ($sr['deficit'] < 0): ?>
                                    <span style="background:var(--rose-soft);color:var(--rose-dark);padding:.22rem .6rem;border-radius:999px;font-size:.7rem;font-weight:700;border:1px solid #fecaca">⚠️ Order Needed</span>
                                <?php elseif ($sr['deficit'] == 0 && $sr['needed'] > 0): ?>
                                    <span style="background:var(--amber-soft);color:var(--amber-dark);padding:.22rem .6rem;border-radius:999px;font-size:.7rem;font-weight:700;border:1px solid #fde68a">Exact Match</span>
                                <?php elseif ($sr['stock'] <= $sr['min_level']): ?>
                                    <span style="background:var(--amber-soft);color:var(--amber-dark);padding:.22rem .6rem;border-radius:999px;font-size:.7rem;font-weight:700;border:1px solid #fde68a">Low Stock</span>
                                <?php else: ?>
                                    <span style="background:var(--emerald-soft);color:var(--emerald-dark);padding:.22rem .6rem;border-radius:999px;font-size:.7rem;font-weight:700;border:1px solid #a7f3d0">✓ Sufficient</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Toolbar -->
            <div class="toolbar">
                <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
                    <input type="text" id="filterSearch" class="filter-sel" placeholder="🔍 Search dispatch ID, ATC…" style="min-width:220px" oninput="applyFilter()">
                    <select id="filterAtc" class="filter-sel" onchange="applyFilter()">
                        <option value="">All ATC Centers</option>
                        <?php foreach ($atcList as $a): ?>
                        <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="filterStatus" class="filter-sel" style="min-width:140px" onchange="applyFilter()">
                        <option value="">All Statuses</option>
                        <option value="Dispatched">Dispatched</option>
                        <option value="Delivered">Delivered</option>
                    </select>
                </div>
                <button class="btn btn-primary" onclick="openCreateModal()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Create Dispatch
                </button>
            </div>

            <!-- Dispatches Table -->
            <div class="section-card">
                <div style="overflow-x:auto">
                <table class="tbl" id="dispatchTable">
                    <thead>
                        <tr>
                            <th>Dispatch ID</th>
                            <th>ATC Center</th>
                            <th>Students</th>
                            <th>Items</th>
                            <th>Postal Service</th>
                            <th>Tracking</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($dispatches)): ?>
                        <tr><td colspan="9" style="text-align:center;padding:3rem;color:var(--text-3)">📦 No dispatches yet. Click <strong>Create Dispatch</strong> to get started.</td></tr>
                    <?php else: ?>
                    <?php foreach ($dispatches as $d): ?>
                    <?php
                        $ic = $dispatchItemCounts[$d['id']] ?? null;
                        $dispItems = $ic ? (int)$ic['dispatched_items'] : null;
                        $pendItems = $ic ? (int)$ic['pending_items'] : null;
                        $totalItems = $ic ? (int)$ic['total_items'] : null;
                    ?>
                    <tr data-atc="<?= $d['atc_id'] ?>" data-status="<?= $d['status'] ?>">
                        <td><span class="dispatch-id"><?= htmlspecialchars($d['dispatch_id']) ?></span></td>
                        <td><?= htmlspecialchars($d['atc_name']) ?></td>
                        <td><strong><?= $d['student_count'] ?></strong> student(s)</td>
                        <td>
                            <?php if ($totalItems !== null): ?>
                            <div class="items-pills">
                                <?php if ($dispItems > 0): ?><span class="items-pill green">✓ <?= $dispItems ?> sent</span><?php endif; ?>
                                <?php if ($pendItems > 0): ?><span class="items-pill amber">⏳ <?= $pendItems ?> pending</span><?php endif; ?>
                            </div>
                            <?php else: ?>
                            <span style="color:var(--text-3);font-size:.78rem">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($d['postal_service']) ?></td>
                        <td><?= $d['tracking_id'] ? '<code style="font-size:.78rem;background:var(--surface-3);padding:.2rem .4rem;border-radius:4px">'.htmlspecialchars($d['tracking_id']).'</code>' : '—' ?></td>
                        <td><?= date('d M Y', strtotime($d['dispatch_date'])) ?></td>
                        <td>
                            <select class="status-sel" data-id="<?= $d['id'] ?>" style="border:1.5px solid var(--border);border-radius:8px;padding:.3rem .6rem;font-size:.78rem;font-weight:700;cursor:pointer;font-family:var(--font)" onchange="updateStatus(this)">
                                <option <?= $d['status']==='Dispatched'?'selected':'' ?>>Dispatched</option>
                                <option <?= $d['status']==='Delivered'?'selected':'' ?>>Delivered</option>
                            </select>
                        </td>
                        <td>
                            <button class="btn btn-secondary btn-sm" onclick="viewDetails(<?= $d['id'] ?>, '<?= htmlspecialchars($d['dispatch_id']) ?>')">View Details</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- ── Create Dispatch Modal ── -->
<div class="modal-overlay" id="createModal">
    <div class="modal">
        <div class="modal-header">
            <h3>📦 Create New Dispatch</h3>
            <button class="close-btn" onclick="closeModal('createModal')">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group">
                    <label>ATC Center *</label>
                    <select id="mdAtc" onchange="loadStudentMaterials()">
                        <option value="">— Select ATC Center —</option>
                        <?php foreach ($atcList as $a): ?>
                        <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Dispatch Date *</label>
                    <input type="date" id="mdDate" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Postal Service *</label>
                    <input type="text" id="mdPostal" placeholder="e.g. India Post, DTDC, BlueDart">
                </div>
                <div class="form-group">
                    <label>Tracking ID</label>
                    <input type="text" id="mdTracking" placeholder="Tracking number (optional)">
                </div>
            </div>
            <div class="form-row full">
                <div class="form-group">
                    <label>Notes</label>
                    <textarea id="mdNotes" rows="2" placeholder="Any additional notes..."></textarea>
                </div>
            </div>

            <!-- Student Materials -->
            <div class="form-row full" style="margin-top:.5rem">
                <div class="form-group">
                    <label style="margin-bottom:.5rem">📋 Student Materials</label>
                    <div class="sel-actions" id="selActions" style="display:none">
                        <button onclick="toggleAllStudents(true)">Select All</button>
                        <button onclick="toggleAllStudents(false)">Deselect All</button>
                        <button onclick="selectAvailableOnly()">Select Available Only</button>
                        <span class="count" id="selSummary"></span>
                    </div>
                    <div class="student-materials-list" id="studentMaterialList">
                        <div class="alert-empty">Select an ATC Center to view student material requirements</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <div class="summary-bar" id="summaryBar">
                <span>No items selected</span>
            </div>
            <div style="display:flex;gap:.75rem">
                <button class="btn btn-secondary" onclick="closeModal('createModal')">Cancel</button>
                <button class="btn btn-primary" id="createBtn" onclick="createDispatch()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                    Create Dispatch
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── View Details Modal ── -->
<div class="modal-overlay" id="detailsModal">
    <div class="modal" style="max-width:750px">
        <div class="modal-header">
            <h3 id="detailsTitle">Dispatch Details</h3>
            <button class="close-btn" onclick="closeModal('detailsModal')">✕</button>
        </div>
        <div class="modal-body" id="detailsBody"><div class="alert-empty">Loading...</div></div>
        <div class="modal-footer" id="detailsFooter">
            <span id="detailsNotifyArea"></span>
            <button class="btn btn-secondary" onclick="closeModal('detailsModal')">Close</button>
        </div>
    </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
let studentData = []; // Stores current student material data

function openCreateModal() { document.getElementById('createModal').classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// ── Load student materials for selected ATC ──
async function loadStudentMaterials() {
    const atcId = document.getElementById('mdAtc').value;
    const list = document.getElementById('studentMaterialList');
    const actions = document.getElementById('selActions');
    
    if (!atcId) {
        list.innerHTML = '<div class="alert-empty">Select an ATC Center to view student material requirements</div>';
        actions.style.display = 'none';
        updateSummary();
        return;
    }
    
    list.innerHTML = '<div class="alert-empty">Loading student materials...</div>';
    
    try {
        const res = await fetch('dispatches.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=get_atc_student_materials&atc_id=' + atcId
        });
        const data = await res.json();
        
        if (!data.success || !data.data.length) {
            list.innerHTML = '<div class="alert-empty">✅ All students in this ATC have their materials dispatched!</div>';
            actions.style.display = 'none';
            studentData = [];
            updateSummary();
            return;
        }
        
        studentData = data.data;
        actions.style.display = 'flex';
        renderStudentMaterials();
    } catch(e) {
        list.innerHTML = '<div class="alert-empty">Error loading data. Please try again.</div>';
    }
}

function renderStudentMaterials() {
    const list = document.getElementById('studentMaterialList');
    let html = '';
    
    studentData.forEach((s, si) => {
        html += `<div class="stu-card">
            <div class="stu-header">
                <input type="checkbox" class="stu-cb" data-si="${si}" onchange="toggleStudent(${si})" checked>
                <div style="flex:1">
                    <div class="stu-name">${s.student_name}</div>
                    <div class="stu-meta">${s.roll_no} · ${s.registration_id || ''} · ${s.course} · Adm: ${s.admission_date || ''}</div>
                </div>
            </div>
            <div class="stu-items">`;
        
        s.materials.forEach((m, mi) => {
            const icon = m.type === 'T-Shirt' ? '👕' : (m.type === 'Certificate' ? '📜' : '📚');
            let stockClass = 'available';
            let stockLabel = `Stock: ${m.stock}`;
            let checked = 'checked';
            
            if (m.status === 'pending_dispatch') {
                stockClass = 'pending';
                stockLabel = '⏳ Previously Pending';
                checked = 'checked';
            } else if (m.stock <= 0) {
                stockClass = 'out';
                stockLabel = 'Out of stock';
                checked = '';
            } else if (m.stock <= 5) {
                stockClass = 'low';
                stockLabel = `Low: ${m.stock}`;
            }
            
            if (!m.inventory_item_id) {
                stockClass = 'out';
                stockLabel = 'No inventory match';
                checked = '';
            }
            
            html += `<div class="mat-item">
                <input type="checkbox" class="mat-cb" data-si="${si}" data-mi="${mi}" ${checked} onchange="updateSummary()">
                <span class="mat-icon">${icon}</span>
                <span class="mat-label">${m.type} — ${m.detail}</span>
                <span class="mat-stock ${stockClass}">${stockLabel}</span>
            </div>`;
        });
        
        html += '</div></div>';
    });
    
    list.innerHTML = html;
    updateSummary();
}

function toggleStudent(si) {
    const checked = document.querySelector(`.stu-cb[data-si="${si}"]`).checked;
    document.querySelectorAll(`.mat-cb[data-si="${si}"]`).forEach(cb => {
        cb.checked = checked;
    });
    updateSummary();
}

function toggleAllStudents(state) {
    document.querySelectorAll('.stu-cb').forEach(cb => { cb.checked = state; });
    document.querySelectorAll('.mat-cb').forEach(cb => { cb.checked = state; });
    updateSummary();
}

function selectAvailableOnly() {
    document.querySelectorAll('.mat-cb').forEach(cb => {
        const si = parseInt(cb.dataset.si);
        const mi = parseInt(cb.dataset.mi);
        const m = studentData[si].materials[mi];
        cb.checked = (m.inventory_item_id && m.stock > 0) || m.status === 'pending_dispatch';
    });
    // Update student checkboxes
    document.querySelectorAll('.stu-cb').forEach(cb => {
        const si = cb.dataset.si;
        const anyChecked = document.querySelector(`.mat-cb[data-si="${si}"]:checked`);
        cb.checked = !!anyChecked;
    });
    updateSummary();
}

function updateSummary() {
    let willDispatch = 0;
    let willPending = 0;
    let totalSelected = 0;
    let studentsSelected = new Set();
    
    document.querySelectorAll('.mat-cb:checked').forEach(cb => {
        const si = parseInt(cb.dataset.si);
        const mi = parseInt(cb.dataset.mi);
        const m = studentData[si]?.materials[mi];
        if (!m) return;
        
        totalSelected++;
        studentsSelected.add(si);
        
        if (m.inventory_item_id && m.stock > 0) {
            willDispatch++;
        } else {
            willPending++;
        }
    });
    
    const bar = document.getElementById('summaryBar');
    const countEl = document.getElementById('selSummary');
    
    if (totalSelected === 0) {
        bar.innerHTML = '<span>No items selected</span>';
    } else {
        let html = `<span>${studentsSelected.size} student(s), ${totalSelected} item(s): </span>`;
        if (willDispatch > 0) html += `<span class="green">✓ ${willDispatch} will dispatch</span>`;
        if (willPending > 0) html += `<span class="amber">⏳ ${willPending} will be pending</span>`;
        bar.innerHTML = html;
    }
    
    countEl.textContent = `${studentsSelected.size} students, ${totalSelected} items selected`;
}

// ── Create Dispatch ──
async function createDispatch() {
    const atcId = document.getElementById('mdAtc').value;
    const date = document.getElementById('mdDate').value;
    const postal = document.getElementById('mdPostal').value.trim();
    
    if (!atcId || !date || !postal) { alert('Please fill ATC Center, Date, and Postal Service.'); return; }
    
    // Separate checked items into dispatchable vs not-dispatchable
    const dispatchItems = [];
    const skippedItems = [];
    
    document.querySelectorAll('.mat-cb:checked').forEach(cb => {
        const si = parseInt(cb.dataset.si);
        const mi = parseInt(cb.dataset.mi);
        const s = studentData[si];
        const m = s.materials[mi];
        
        if (m.inventory_item_id && m.stock > 0) {
            // Has stock — will be dispatched
            dispatchItems.push({
                admission_id: s.id,
                type: m.type,
                detail: m.detail,
                inventory_item_id: m.inventory_item_id,
                action: 'dispatch'
            });
        } else {
            // No stock or no inventory match — SKIP (stays in pending pool)
            skippedItems.push(`${s.student_name}: ${m.type} — ${m.detail} (${!m.inventory_item_id ? 'No inventory match' : 'Out of stock'})`);
        }
    });
    
    if (!dispatchItems.length && skippedItems.length) {
        alert('None of the selected items can be dispatched — all are out of stock or have no inventory match.\n\nThe following items were skipped:\n• ' + skippedItems.join('\n• '));
        return;
    }
    
    if (!dispatchItems.length) {
        alert('Please select at least one material item to dispatch.');
        return;
    }
    
    // Warn about skipped items
    if (skippedItems.length > 0) {
        const proceed = confirm(
            `${dispatchItems.length} item(s) will be dispatched.\n\n` +
            `⚠️ ${skippedItems.length} item(s) CANNOT be dispatched and will remain pending for future dispatch:\n` +
            `• ${skippedItems.join('\n• ')}\n\n` +
            `Proceed with dispatching available items only?`
        );
        if (!proceed) return;
    }
    
    const btn = document.getElementById('createBtn');
    btn.disabled = true;
    btn.innerHTML = 'Creating...';
    
    try {
        const res = await fetch('dispatches.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=create_dispatch&atc_id=${atcId}&dispatch_date=${date}` +
                  `&postal_service=${encodeURIComponent(postal)}` +
                  `&tracking_id=${encodeURIComponent(document.getElementById('mdTracking').value)}` +
                  `&notes=${encodeURIComponent(document.getElementById('mdNotes').value)}` +
                  `&dispatch_items=${encodeURIComponent(JSON.stringify(dispatchItems))}`
        });
        const data = await res.json();
        if (data.success) {
            let msg = data.message;
            if (skippedItems.length > 0) {
                msg += `\n\n${skippedItems.length} item(s) were NOT included and remain in the pending pool.`;
            }
            alert(msg);
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    } catch(e) {
        alert('Network error. Please try again.');
    }
    btn.disabled = false;
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg> Create Dispatch';
}

// ── Status Update ──
function updateStatus(sel) {
    fetch('dispatches.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=update_status&id=${sel.dataset.id}&status=${sel.value}`})
    .then(r=>r.json()).then(res => { if(!res.success) alert('Error updating status'); });
}

// ── View Details ──
async function viewDetails(id, dispId) {
    document.getElementById('detailsTitle').textContent = '📦 ' + dispId;
    document.getElementById('detailsBody').innerHTML = '<div class="alert-empty">Loading details...</div>';
    document.getElementById('detailsModal').classList.add('open');
    
    try {
        const res = await fetch('dispatches.php', {
            method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body: 'action=get_dispatch_details&id=' + id
        });
        const data = await res.json();
        
        if (!data.success || !data.data.length) {
            document.getElementById('detailsBody').innerHTML = '<div class="alert-empty">No details found for this dispatch</div>';
            return;
        }
        
        // Group by student
        const byStudent = {};
        data.data.forEach(item => {
            const key = item.student_name;
            if (!byStudent[key]) byStudent[key] = { info: item, items: [] };
            byStudent[key].items.push(item);
        });
        
        let html = '';
        for (const [name, group] of Object.entries(byStudent)) {
            html += `<div style="margin-bottom:1.25rem;border:1.5px solid var(--border);border-radius:var(--r-lg);overflow:hidden">
                <div style="padding:.75rem 1rem;background:var(--surface-2);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
                    <div>
                        <div style="font-weight:700;font-size:.9rem;color:var(--text)">${group.info.student_name}</div>
                        <div style="font-size:.72rem;color:var(--text-3)">${group.info.roll_no || ''} · ${group.info.registration_id || ''} · ${group.info.course || ''}</div>
                    </div>
                </div>
                <div style="padding:.5rem">`;
            
            group.items.forEach(item => {
                const icon = item.item_type === 'T-Shirt' ? '👕' : (item.item_type === 'Certificate' ? '📜' : '📚');
                const statusBadge = item.status === 'Dispatched'
                    ? '<span class="badge badge-dispatched">✓ Dispatched</span>'
                    : '<span class="badge badge-pending">⏳ Pending</span>';
                
                html += `<div style="display:flex;align-items:center;gap:.65rem;padding:.55rem .75rem;margin:.25rem;background:var(--surface-3);border-radius:8px">
                    <span style="font-size:1rem">${icon}</span>
                    <span style="flex:1;font-weight:600;font-size:.84rem;color:var(--text)">${item.item_type} — ${item.item_detail}</span>
                    ${statusBadge}
                </div>`;
            });
            
            html += '</div></div>';
        }
        
        document.getElementById('detailsBody').innerHTML = html;

        // Show "Notify ATC" button if there are pending items
        const hasPending = data.data.some(i => i.status === 'Pending');
        const notifyArea = document.getElementById('detailsNotifyArea');
        if (hasPending) {
            notifyArea.innerHTML = `<button class="btn btn-secondary btn-sm" style="background:#fff7ed;border-color:#fde68a;color:#92400e" onclick="notifyAtcPending(${id})">
                ⚠️ Notify ATC — Unavailable Items
            </button>`;
        } else {
            notifyArea.innerHTML = '';
        }
    } catch(e) {
        document.getElementById('detailsBody').innerHTML = '<div class="alert-empty">Error loading details</div>';
    }
}

// ── Table Filter (with search) ──
function applyFilter() {
    const atc = document.getElementById('filterAtc').value;
    const status = document.getElementById('filterStatus').value;
    const search = (document.getElementById('filterSearch')?.value || '').toLowerCase().trim();
    document.querySelectorAll('#dispatchTable tbody tr[data-atc]').forEach(row => {
        const matchAtc = !atc || row.dataset.atc === atc;
        const matchStatus = !status || row.dataset.status === status;
        const rowText = row.textContent.toLowerCase();
        const matchSearch = !search || rowText.includes(search);
        row.style.display = (matchAtc && matchStatus && matchSearch) ? '' : 'none';
    });
}

// ── Notify ATC about pending items ──
async function notifyAtcPending(dispPK) {
    if (!confirm('Send notification to this ATC about pending/unavailable items?')) return;
    try {
        const res = await fetch('dispatches.php', {
            method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'action=notify_atc_pending&dispatch_pk=' + dispPK
        });
        const data = await res.json();
        alert(data.message);
    } catch(e) { alert('Failed to send notification.'); }
}
</script>
</body>
</html>