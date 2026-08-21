<?php
/**
 * Gyanam Portal — Admin: Birthday Management
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$userId = $_SESSION['user_id'] ?? null;

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'add_birthday':
                $stmt = $pdo->prepare("
                    INSERT INTO birthdays (person_name, mobile, birth_date, description, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['person_name'],
                    $_POST['mobile'],
                    $_POST['birth_date'],
                    $_POST['description'],
                    $_POST['status'] ?? 'Active',
                    $userId
                ]);
                echo json_encode(['success' => true, 'message' => 'Birthday added successfully']);
                exit;
                
            case 'update_birthday':
                $stmt = $pdo->prepare("
                    UPDATE birthdays SET 
                        person_name = ?, mobile = ?, birth_date = ?, description = ?, status = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['person_name'],
                    $_POST['mobile'],
                    $_POST['birth_date'],
                    $_POST['description'],
                    $_POST['status'],
                    $_POST['id']
                ]);
                echo json_encode(['success' => true, 'message' => 'Birthday updated successfully']);
                exit;
                
            case 'delete_birthday':
                $stmt = $pdo->prepare("DELETE FROM birthdays WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                echo json_encode(['success' => true, 'message' => 'Birthday deleted successfully']);
                exit;
                
            case 'get_birthday':
                $stmt = $pdo->prepare("SELECT * FROM birthdays WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                $birthday = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $birthday]);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Fetch birthdays (manual entries)
$statusFilter = $_GET['status'] ?? 'all';
$monthFilter = $_GET['month'] ?? 'all';

$sql = "SELECT b.*, u.name as created_by_name, 'manual' as type
        FROM birthdays b 
        LEFT JOIN users u ON b.created_by = u.id 
        WHERE 1=1";
$params = [];

if ($statusFilter !== 'all') {
    $sql .= " AND b.status = ?";
    $params[] = $statusFilter;
}

if ($monthFilter !== 'all') {
    $sql .= " AND MONTH(b.birth_date) = ?";
    $params[] = $monthFilter;
}

$sql .= " ORDER BY MONTH(b.birth_date), DAY(b.birth_date)";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$birthdays = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch ATC Center birthdays
$atcBdSql = "SELECT
    CONCAT('ATC_', id) as id,
    COALESCE(NULLIF(TRIM(contact_person),''), name) as person_name,
    IFNULL(mobile,'') as mobile,
    dob as birth_date,
    CONCAT('ATC Center — ', IFNULL(district,'')) as description,
    'Active' as status,
    'atc' as type
    FROM atc_centers
    WHERE dob IS NOT NULL AND status = 'Active'";
$atcBdParams = [];
if ($monthFilter !== 'all') {
    $atcBdSql .= " AND MONTH(dob) = ?";
    $atcBdParams[] = $monthFilter;
}
$atcBdSql .= " ORDER BY MONTH(dob), DAY(dob)";
$stmt = $pdo->prepare($atcBdSql);
$stmt->execute($atcBdParams);
$atcBirthdays = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch DLC Office birthdays
$dlcBdSql = "SELECT
    CONCAT('DLC_', id) as id,
    COALESCE(NULLIF(TRIM(contact_person),''), name) as person_name,
    IFNULL(mobile,'') as mobile,
    dob as birth_date,
    CONCAT('DLC Office — ', IFNULL(district,'')) as description,
    'Active' as status,
    'dlc' as type
    FROM dlc_offices
    WHERE dob IS NOT NULL AND status = 'Active'";
$dlcBdParams = [];
if ($monthFilter !== 'all') {
    $dlcBdSql .= " AND MONTH(dob) = ?";
    $dlcBdParams[] = $monthFilter;
}
$dlcBdSql .= " ORDER BY MONTH(dob), DAY(dob)";
$stmt = $pdo->prepare($dlcBdSql);
$stmt->execute($dlcBdParams);
$dlcBirthdays = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Merge all
$birthdays = array_merge($birthdays, $atcBirthdays, $dlcBirthdays);

// Sort by month and day
usort($birthdays, function($a, $b) {
    $dateA = DateTime::createFromFormat('Y-m-d', $a['birth_date']);
    $dateB = DateTime::createFromFormat('Y-m-d', $b['birth_date']);
    return strcmp($dateA->format('m-d'), $dateB->format('m-d'));
});

// Get today's and this month's counts (manual + ATC + DLC)
$stmt = $pdo->query("
    SELECT COUNT(*) FROM (
        SELECT id FROM birthdays WHERE MONTH(birth_date)=MONTH(CURDATE()) AND DAY(birth_date)=DAY(CURDATE()) AND status='Active'
        UNION ALL
        SELECT id FROM atc_centers WHERE dob IS NOT NULL AND MONTH(dob)=MONTH(CURDATE()) AND DAY(dob)=DAY(CURDATE()) AND status='Active'
        UNION ALL
        SELECT id FROM dlc_offices WHERE dob IS NOT NULL AND MONTH(dob)=MONTH(CURDATE()) AND DAY(dob)=DAY(CURDATE()) AND status='Active'
    ) t
");
$todayCount = $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT COUNT(*) FROM (
        SELECT id FROM birthdays WHERE MONTH(birth_date)=MONTH(CURDATE()) AND status='Active'
        UNION ALL
        SELECT id FROM atc_centers WHERE dob IS NOT NULL AND MONTH(dob)=MONTH(CURDATE()) AND status='Active'
        UNION ALL
        SELECT id FROM dlc_offices WHERE dob IS NOT NULL AND MONTH(dob)=MONTH(CURDATE()) AND status='Active'
    ) t
");
$thisMonthCount = $stmt->fetchColumn();

// Fetch today's birthdays for special section (manual + ATC + DLC)
$todayBirthdaysSql = "
SELECT b.id, b.person_name, b.mobile, b.birth_date, b.description, b.status, 'manual' as type
FROM birthdays b
WHERE MONTH(b.birth_date)=MONTH(CURDATE()) AND DAY(b.birth_date)=DAY(CURDATE()) AND b.status='Active'
UNION ALL
SELECT CONCAT('ATC_',id), COALESCE(NULLIF(TRIM(contact_person),''),name), IFNULL(mobile,''), dob, CONCAT('ATC Center — ',IFNULL(district,'')), 'Active', 'atc'
FROM atc_centers WHERE dob IS NOT NULL AND MONTH(dob)=MONTH(CURDATE()) AND DAY(dob)=DAY(CURDATE()) AND status='Active'
UNION ALL
SELECT CONCAT('DLC_',id), COALESCE(NULLIF(TRIM(contact_person),''),name), IFNULL(mobile,''), dob, CONCAT('DLC Office — ',IFNULL(district,'')), 'Active', 'dlc'
FROM dlc_offices WHERE dob IS NOT NULL AND MONTH(dob)=MONTH(CURDATE()) AND DAY(dob)=DAY(CURDATE()) AND status='Active'
ORDER BY person_name";

$stmt = $pdo->prepare($todayBirthdaysSql);
$stmt->execute();
$todayBirthdays = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Birthday Management — Admin | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎂</text></svg>">
    <style>
        :root {
            --birthday-pink: #ec4899;
            --birthday-purple: #a855f7;
            --birthday-yellow: #fbbf24;
        }
        
        .page-content {
            padding: 2rem;
        }
        
        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .kpi-card {
            background: white;
            border-radius: 16px;
            padding: 1.75rem;
            display: flex;
            align-items: center;
            gap: 1.25rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            border-left: 4px solid;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 120px;
            height: 120px;
            background: radial-gradient(circle, currentColor 0%, transparent 70%);
            opacity: 0.05;
        }
        
        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }
        
        .kpi-card:nth-child(1) { border-left-color: var(--birthday-pink); }
        .kpi-card:nth-child(2) { border-left-color: var(--birthday-purple); }
        .kpi-card:nth-child(3) { border-left-color: var(--emerald); }
        
        .kpi-icon {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .kpi-icon svg {
            width: 32px;
            height: 32px;
            stroke: white;
        }
        
        .kpi-content {
            flex: 1;
        }
        
        .kpi-value {
            font-size: 2.25rem;
            font-weight: 800;
            font-family: 'JetBrains Mono', monospace;
            color: #1e293b;
            line-height: 1;
            margin-bottom: 0.5rem;
        }
        
        .kpi-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #64748b;
        }
        
        /* Toolbar */
        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .toolbar-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        
        .select-sm {
            padding: 0.75rem 2.5rem 0.75rem 1rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.875rem;
            font-family: 'Sora', sans-serif;
            font-weight: 500;
            background: white;
            color: #1e293b;
            outline: none;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px;
            transition: all 0.2s;
        }
        
        .select-sm:hover {
            border-color: #cbd5e1;
        }
        
        .select-sm:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        /* Add Birthday Button */
        .btn-primary {
            padding: 0.875rem 1.75rem;
            background: linear-gradient(135deg, var(--birthday-pink), var(--birthday-purple));
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 0.9375rem;
            font-weight: 700;
            font-family: 'Sora', sans-serif;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.625rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(236, 72, 153, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s;
        }
        
        .btn-primary:hover::before {
            left: 100%;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(236, 72, 153, 0.4);
        }
        
        .btn-primary:active {
            transform: translateY(0);
        }
        
        .btn-primary svg {
            width: 20px;
            height: 20px;
            stroke-width: 2.5;
        }
        
        .btn-secondary {
            padding: 0.875rem 1.75rem;
            background: #f1f5f9;
            color: #64748b;
            border: none;
            border-radius: 12px;
            font-size: 0.9375rem;
            font-weight: 600;
            font-family: 'Sora', sans-serif;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-secondary:hover {
            background: #e2e8f0;
            color: #475569;
        }
        
        /* Table */
        .table-wrap {
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table thead {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border-bottom: 2px solid #e2e8f0;
        }
        
        .data-table thead th {
            padding: 1rem 1.5rem;
            text-align: left;
            font-size: 0.75rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .data-table tbody tr {
            border-bottom: 1px solid #f1f5f9;
            transition: all 0.2s;
        }
        
        .data-table tbody tr:hover {
            background: #fafbff;
        }
        
        .data-table tbody tr.birthday-today {
            background: linear-gradient(90deg, #fef3c7, #fef9e7);
            border-left: 4px solid var(--birthday-yellow);
        }
        
        .data-table tbody tr.birthday-today:hover {
            background: linear-gradient(90deg, #fde68a, #fef3c7);
        }
        
        .data-table tbody td {
            padding: 1.125rem 1.5rem;
            vertical-align: middle;
            font-size: 0.875rem;
            color: #334155;
        }
        
        .person-cell {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .person-emoji {
            font-size: 1.5rem;
            animation: bounce 1s infinite;
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }
        
        .person-name {
            font-weight: 700;
            color: #1e293b;
        }
        
        .mobile-number {
            font-family: 'JetBrains Mono', monospace;
            color: #64748b;
        }
        
        .birth-date {
            font-weight: 600;
            color: #1e293b;
        }
        
        .age-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.375rem 0.75rem;
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            color: #1e40af;
            border-radius: 12px;
            font-size: 0.8125rem;
            font-weight: 700;
            font-family: 'JetBrains Mono', monospace;
        }
        
        .description-text {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #64748b;
        }
        
        .badge-active {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
            padding: 0.375rem 0.875rem;
            border-radius: 12px;
            font-size: 0.8125rem;
            font-weight: 600;
        }
        
        .badge-inactive {
            background: rgba(100, 116, 139, 0.1);
            color: #64748b;
            padding: 0.375rem 0.875rem;
            border-radius: 12px;
            font-size: 0.8125rem;
            font-weight: 600;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #94a3b8;
        }
        
        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .empty-state-text {
            font-size: 1rem;
            font-weight: 600;
            color: #64748b;
        }
        
        /* Modal Improvements */
        .modal-header {
            background: linear-gradient(135deg, var(--birthday-pink), var(--birthday-purple));
            padding: 2rem;
            border-radius: 16px 16px 0 0;
            position: relative;
            overflow: hidden;
        }
        
        .modal-header::before {
            content: '🎂🎉🎈';
            position: absolute;
            top: 50%;
            right: 2rem;
            transform: translateY(-50%);
            font-size: 3rem;
            opacity: 0.2;
        }
        
        .modal-header-content {
            display: flex;
            align-items: center;
            gap: 1rem;
            position: relative;
            z-index: 1;
        }
        
        .modal-icon {
            width: 56px;
            height: 56px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .modal-icon svg {
            width: 28px;
            height: 28px;
            stroke: white;
        }
        
        .modal-header h3 {
            font-size: 1.5rem;
            font-weight: 800;
            color: white;
            margin: 0 0 0.25rem 0;
        }
        
        .modal-header p {
            font-size: 0.875rem;
            color: rgba(255, 255, 255, 0.9);
            margin: 0;
        }
        
        .modal-close {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            width: 36px;
            height: 36px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border: none;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            z-index: 2;
        }
        
        .modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }
        
        .modal-close svg {
            width: 20px;
            height: 20px;
            stroke: white;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.25rem;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: 0.5rem;
        }
        
        .required {
            color: #ef4444;
        }
        
        .form-input {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.875rem;
            font-family: 'Sora', sans-serif;
            color: #1e293b;
            transition: all 0.2s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        textarea.form-input {
            resize: vertical;
            min-height: 80px;
        }
        
        /* Modal Footer */
        .modal-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 1rem;
            padding: 1.5rem 2rem;
            background: #f8fafc;
            border-radius: 0 0 16px 16px;
        }
        
        .modal-footer .btn-primary {
            min-width: 140px;
        }
        
        .modal-footer .btn-secondary {
            min-width: 100px;
        }
        
        /* Modal Body */
        .modal-body {
            padding: 2rem;
            max-height: calc(90vh - 300px);
            overflow-y: auto;
        }
        
        .modal-body::-webkit-scrollbar {
            width: 8px;
        }
        
        .modal-body::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }
        
        .modal-body::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        
        .modal-body::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .action-btn {
            width: 38px;
            height: 38px;
            border: none;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }
        
        .action-btn svg {
            width: 18px;
            height: 18px;
            stroke-width: 2;
            position: relative;
            z-index: 1;
        }
        
        .action-btn::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 10px;
            opacity: 0;
            transition: opacity 0.2s;
        }
        
        .action-btn:hover::before {
            opacity: 1;
        }
        
        .action-edit {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
        }
        
        .action-edit::before {
            background: linear-gradient(135deg, #10b981, #059669);
        }
        
        .action-edit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .action-edit:hover svg {
            stroke: white;
        }
        
        .action-delete {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }
        
        .action-delete::before {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }
        
        .action-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }
        
        .action-delete:hover svg {
            stroke: white;
        }
        
        .action-btn:active {
            transform: translateY(0);
        }
        
        /* WhatsApp Button */
        .whatsapp-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.125rem;
            background: linear-gradient(135deg, #25d366, #20ba58);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 0.875rem;
            font-weight: 600;
            font-family: 'Sora', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(37, 211, 102, 0.3);
            flex: 1;
            justify-content: center;
        }
        
        .whatsapp-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 211, 102, 0.5);
            background: linear-gradient(135deg, #20ba58, #1fa751);
        }
        
        .whatsapp-btn:active {
            transform: translateY(0);
        }
        
        /* Tooltip for action buttons */
        .action-btn[title]:hover::after {
            content: attr(title);
            position: absolute;
            bottom: calc(100% + 8px);
            left: 50%;
            transform: translateX(-50%);
            padding: 0.375rem 0.75rem;
            background: #1e293b;
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 6px;
            white-space: nowrap;
            pointer-events: none;
            z-index: 10;
        }
        
        .action-btn[title]:hover::before {
            content: '';
            position: absolute;
            bottom: calc(100% + 2px);
            left: 50%;
            transform: translateX(-50%);
            border: 6px solid transparent;
            border-top-color: #1e293b;
            pointer-events: none;
            z-index: 10;
        }
        
        @media (max-width: 768px) {
            .page-content {
                padding: 1rem;
            }
            
            .kpi-grid {
                grid-template-columns: 1fr;
            }
            
            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .toolbar-left {
                width: 100%;
            }
            
            .select-sm {
                width: 100%;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .data-table {
                font-size: 0.8125rem;
            }
            
            .data-table thead th,
            .data-table tbody td {
                padding: 0.875rem 1rem;
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
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <div class="header-greeting">
                    <h2>Birthday Management</h2>
                    <p>Manage birthday notifications</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">
            
            <!-- KPI Cards -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon" style="background: linear-gradient(135deg, #f43f5e, #e11d48);">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <div class="kpi-content">
                        <div class="kpi-value"><?= $todayCount ?></div>
                        <div class="kpi-label">Today's Birthdays</div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </div>
                    <div class="kpi-content">
                        <div class="kpi-value"><?= $thisMonthCount ?></div>
                        <div class="kpi-label">This Month</div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div class="kpi-content">
                        <div class="kpi-value"><?= count($birthdays) ?></div>
                        <div class="kpi-label">Total Birthdays</div>
                    </div>
                </div>
            </div>

            <!-- Today's Birthdays Section -->
            <?php if (!empty($todayBirthdays)): ?>
            <div style="background: linear-gradient(135deg, #fef3c7 0%, #fef9e7 100%); border-radius: 16px; padding: 2rem; margin-bottom: 2rem; border-left: 4px solid #fbbf24;">
                <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem;">
                    <span style="font-size: 2rem;">🎂🎉</span>
                    <div>
                        <h3 style="margin: 0; font-size: 1.25rem; font-weight: 800; color: #1e293b;">Special Birthdays Today!</h3>
                        <p style="margin: 0; font-size: 0.875rem; color: #64748b;"><?= count($todayBirthdays) ?> birthday<?= count($todayBirthdays) !== 1 ? 'ies' : '' ?> to celebrate</p>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem;">
                    <?php foreach ($todayBirthdays as $person): 
                        $birthDate = new DateTime($person['birth_date']);
                        $today = new DateTime();
                        $age = $today->diff($birthDate)->y;
                    ?>
                    <div style="background: white; border-radius: 12px; padding: 1.25rem; box-shadow: 0 2px 8px rgba(0,0,0,0.08); display: flex; flex-direction: column; gap: 1rem;">
                        <div>
                            <h4 style="margin: 0 0 0.5rem 0; font-size: 1rem; font-weight: 700; color: #1e293b;"><?= htmlspecialchars($person['person_name']) ?></h4>
                            <p style="margin: 0; font-size: 0.875rem; color: #64748b;">
                                <?= $person['type'] === 'student' ? '📚 Student' : '👤 Contact' ?> • 
                                <span style="font-weight: 600;"><?= $age ?> years old</span>
                            </p>
                            <?php if ($person['type'] === 'student'): ?>
                                <p style="margin: 0.5rem 0 0 0; font-size: 0.8125rem; color: #64748b;"><?= htmlspecialchars($person['description']) ?></p>
                            <?php endif; ?>
                        </div>
                        <?php if ($person['mobile']): ?>
                        <div style="display: flex; gap: 0.75rem;">
                            <button class="whatsapp-btn" onclick="sendWhatsAppWish('<?= addslashes($person['person_name']) ?>', '<?= $person['mobile'] ?>')">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white" style="width: 16px; height: 16px;">
                                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.67-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.076 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421-7.403h-.004c-1.425 0-2.835.356-4.06 1.031l-.291.169-3.015-.787.804 2.93-.189.301A7.002 7.002 0 003.02 9.414c0 3.866 3.113 7.012 6.938 7.012 1.893 0 3.672-.652 5.093-1.849 1.42-1.198 2.33-2.926 2.33-4.856 0-3.866-3.113-7.012-6.938-7.012m6.938 13.6H4.059A8.968 8.968 0 000 11.5C0 5.477 5.507 0.5 12 0.5s12 4.977 12 11-5.507 11-12 11z"/>
                                </svg>
                                Send Wish
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Toolbar -->
            <div class="toolbar">
                <div class="toolbar-left">
                    <form method="GET" style="display: contents;">
                        <select name="status" class="select-sm" onchange="this.form.submit()">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                            <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $statusFilter === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                        <select name="month" class="select-sm" onchange="this.form.submit()">
                            <option value="all" <?= $monthFilter === 'all' ? 'selected' : '' ?>>All Months</option>
                            <?php 
                            $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                            foreach ($months as $i => $month): 
                                $monthNum = $i + 1;
                            ?>
                                <option value="<?= $monthNum ?>" <?= $monthFilter == $monthNum ? 'selected' : '' ?>><?= $month ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <div class="toolbar-right">
                    <button class="btn-primary" onclick="openAddModal()">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                            <line x1="12" y1="14" x2="12" y2="18"/>
                            <line x1="10" y1="16" x2="14" y2="16"/>
                        </svg>
                        Add Birthday
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Person Name</th>
                            <th>Mobile</th>
                            <th>Birth Date</th>
                            <th>Age</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th style="text-align: center;">Send Wish</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($birthdays)): ?>
                            <tr>
                                <td colspan="8">
                                    <div class="empty-state">
                                        <div class="empty-state-icon">🎂</div>
                                        <div class="empty-state-text">No birthdays found</div>
                                        <p style="margin-top: 0.5rem; font-size: 0.875rem;">Add birthdays to start receiving notifications</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($birthdays as $birthday): 
                                $birthDate = new DateTime($birthday['birth_date']);
                                $today = new DateTime();
                                $age = $today->diff($birthDate)->y;
                                $isToday = $birthDate->format('m-d') === $today->format('m-d');
                                $isReadOnly = in_array($birthday['type'], ['student','atc','dlc']);
                                $typeLabel = match($birthday['type']) {
                                    'atc' => '🏫 ATC Center',
                                    'dlc' => '🏢 DLC Office',
                                    default => ''
                                };
                            ?>
                                <tr class="<?= $isToday ? 'birthday-today' : '' ?>">
                                    <td>
                                        <div class="person-cell">
                                            <?= $isToday ? '<span class="person-emoji">🎂</span>' : '' ?>
                                            <div>
                                                <span class="person-name"><?= htmlspecialchars($birthday['person_name']) ?></span>
                                                <?php if ($typeLabel): ?>
                                                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;"><?= $typeLabel ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="mobile-number"><?= htmlspecialchars($birthday['mobile'] ?: '—') ?></span>
                                    </td>
                                    <td>
                                        <span class="birth-date"><?= date('d M Y', strtotime($birthday['birth_date'])) ?></span>
                                    </td>
                                    <td>
                                        <span class="age-badge"><?= $age ?> years</span>
                                    </td>
                                    <td>
                                        <div class="description-text" title="<?= htmlspecialchars($birthday['description'] ?? '') ?>">
                                            <?= htmlspecialchars($birthday['description'] ?: '—') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge-<?= strtolower($birthday['status']) ?>">
                                            <?= $birthday['status'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($birthday['mobile'] && $birthday['status'] === 'Active'): ?>
                                        <button class="whatsapp-btn" onclick="sendWhatsAppWish('<?= addslashes(htmlspecialchars($birthday['person_name'])) ?>', '<?= $birthday['mobile'] ?>')" style="width: 100%; padding: 0.625rem 0.875rem;">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white" style="width: 14px; height: 14px;">
                                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.67-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.076 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421-7.403h-.004c-1.425 0-2.835.356-4.06 1.031l-.291.169-3.015-.787.804 2.93-.189.301A7.002 7.002 0 003.02 9.414c0 3.866 3.113 7.012 6.938 7.012 1.893 0 3.672-.652 5.093-1.849 1.42-1.198 2.33-2.926 2.33-4.856 0-3.866-3.113-7.012-6.938-7.012m6.938 13.6H4.059A8.968 8.968 0 000 11.5C0 5.477 5.507 0.5 12 0.5s12 4.977 12 11-5.507 11-12 11z"/>
                                            </svg>
                                            Wish
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$isReadOnly): ?>
                                        <div class="action-buttons">
                                            <button class="action-btn action-edit" onclick="editBirthday(<?= $birthday['id'] ?>)" title="Edit">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            </button>
                                            <button class="action-btn action-delete" onclick="deleteBirthday(<?= $birthday['id'] ?>, '<?= addslashes($birthday['person_name']) ?>')" title="Delete">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                            </button>
                                        </div>
                                        <?php else: ?>
                                            <span style="font-size: 0.8125rem; color: #64748b;"><?= match($birthday['type']) { 'atc' => 'ATC record', 'dlc' => 'DLC record', default => 'Auto record' } ?></span>
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

<!-- Add/Edit Modal -->
<div class="modal-overlay" id="birthdayModal">
    <div class="modal-card" style="max-width: 600px;">
        <div class="modal-header">
            <div class="modal-header-content">
                <div class="modal-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <div>
                    <h3 id="modalTitle">Add Birthday</h3>
                    <p>Add birthday notification details</p>
                </div>
            </div>
            <button class="modal-close" onclick="closeModal()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <form id="birthdayForm" onsubmit="saveBirthday(event)">
            <input type="hidden" id="birthday_id" name="id">
            
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Person Name <span class="required">*</span></label>
                        <input type="text" class="form-input" id="person_name" name="person_name" required maxlength="100">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Mobile Number</label>
                        <input type="tel" class="form-input" id="mobile" name="mobile" pattern="[0-9]{10}" maxlength="15">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Birth Date <span class="required">*</span></label>
                        <input type="date" class="form-input" id="birth_date" name="birth_date" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select class="form-input" id="status" name="status">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Description / Message</label>
                        <textarea class="form-input" id="description" name="description" rows="3" placeholder="Special message or notes..."></textarea>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn-primary" id="submitBtn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 6L9 17l-5-5"/>
                    </svg>
                    <span id="submitBtnText">Save Birthday</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
function openAddModal() {
    document.getElementById('birthdayForm').reset();
    document.getElementById('birthday_id').value = '';
    document.getElementById('modalTitle').textContent = 'Add Birthday';
    document.getElementById('submitBtnText').textContent = 'Save Birthday';
    document.getElementById('birthdayModal').classList.add('active');
}

async function editBirthday(id) {
    try {
        const formData = new FormData();
        formData.append('action', 'get_birthday');
        formData.append('id', id);

        const response = await fetch('', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        if (result.success) {
            const data = result.data;
            document.getElementById('birthday_id').value = data.id;
            document.getElementById('person_name').value = data.person_name;
            document.getElementById('mobile').value = data.mobile || '';
            document.getElementById('birth_date').value = data.birth_date;
            document.getElementById('description').value = data.description || '';
            document.getElementById('status').value = data.status;
            
            document.getElementById('modalTitle').textContent = 'Edit Birthday';
            document.getElementById('submitBtnText').textContent = 'Update Birthday';
            document.getElementById('birthdayModal').classList.add('active');
        } else {
            alert('❌ Error loading birthday details');
        }
    } catch (error) {
        alert('❌ Error loading birthday details. Please try again.');
    }
}

async function saveBirthday(event) {
    event.preventDefault();
    
    const submitBtn = document.getElementById('submitBtn');
    const submitBtnText = document.getElementById('submitBtnText');
    const originalText = submitBtnText.textContent;
    
    submitBtn.disabled = true;
    submitBtnText.textContent = 'Saving...';
    
    try {
        const formData = new FormData(event.target);
        const id = document.getElementById('birthday_id').value;
        formData.append('action', id ? 'update_birthday' : 'add_birthday');

        const response = await fetch('', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        if (result.success) {
            const icon = id ? '✅' : '🎉';
            const message = id ? 'Birthday updated successfully!' : 'Birthday added successfully!';
            alert(`${icon} ${message}`);
            location.reload();
        } else {
            alert('❌ Error: ' + result.message);
            submitBtn.disabled = false;
            submitBtnText.textContent = originalText;
        }
    } catch (error) {
        alert('❌ Error saving birthday. Please try again.');
        submitBtn.disabled = false;
        submitBtnText.textContent = originalText;
    }
}

async function deleteBirthday(id, name) {
    // Create custom confirmation dialog
    const confirmed = confirm(`🗑️ Delete Birthday\n\nAre you sure you want to delete the birthday for "${name}"?\n\nThis action cannot be undone.`);
    
    if (!confirmed) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete_birthday');
        formData.append('id', id);

        const response = await fetch('', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        if (result.success) {
            // Show success message
            alert('✅ Birthday deleted successfully!');
            location.reload();
        } else {
            alert('❌ Error: ' + result.message);
        }
    } catch (error) {
        alert('❌ Error deleting birthday. Please try again.');
    }
}

function closeModal() {
    document.getElementById('birthdayModal').classList.remove('active');
}

function sendWhatsAppWish(personName, mobile) {
    // Sanitize mobile number to ensure it's valid
    mobile = mobile.replace(/[^\d+]/g, '');
    
    // If mobile doesn't start with +, assume it's India (+91)
    if (!mobile.startsWith('+')) {
        mobile = '+91' + mobile.slice(-10); // Take last 10 digits
    }
    
    // Create a warm birthday wish message
    const message = `Dear ${personName},

Warm Birthday Greetings from the entire Gyanam India family!

On this special occasion, we extend our heartfelt wishes to you. May this new year of your life bring you great health, abundant happiness, and continued success in everything you pursue.

We are grateful to have you as a valued part of the Gyanam India community. May your day be as wonderful as the joy you bring to everyone around you.

With warm regards,
Team Gyanam India`;
    
    // Encode the message for URL
    const encodedMessage = encodeURIComponent(message);
    
    // Open WhatsApp with pre-filled message
    const whatsappUrl = `https://wa.me/${mobile.replace(/\D/g, '')}?text=${encodedMessage}`;
    
    // Open in new window
    window.open(whatsappUrl, '_blank', 'width=600,height=600');
}
</script>

</body>
</html>
