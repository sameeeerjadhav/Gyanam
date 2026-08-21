<?php
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
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$_POST['username']]);
                if ($stmt->fetch()) { echo json_encode(['success' => false, 'message' => 'Username already exists']); exit; }
                if (!empty($_POST['email'])) {
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->execute([$_POST['email']]);
                    if ($stmt->fetch()) { echo json_encode(['success' => false, 'message' => 'Email already exists']); exit; }
                }
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role, name, email, mobile, date_of_birth, dlc_id, atc_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$_POST['username'], $_POST['password'], $_POST['role'], $_POST['name'], $_POST['email'] ?: null, $_POST['mobile'] ?: null, $_POST['date_of_birth'] ?: null, $_POST['dlc_id'] ?: null, $_POST['atc_id'] ?: null, $_POST['status']]);

                // 🔄 Auto-sync to Exam Portal (fire-and-forget)
                if (function_exists('syncPortalUserToExam') && in_array($_POST['role'], ['ATC CENTER', 'DLC Office'])) {
                    $centreId = null;
                    if ($_POST['role'] === 'ATC CENTER' && !empty($_POST['atc_id'])) {
                        $acStmt = $pdo->prepare("SELECT atc_code FROM atc_centers WHERE id = ?");
                        $acStmt->execute([$_POST['atc_id']]);
                        $centreId = $acStmt->fetchColumn() ?: (date('Y') . str_pad($_POST['atc_id'], 5, '0', STR_PAD_LEFT));
                    } elseif ($_POST['role'] === 'DLC Office' && !empty($_POST['dlc_id'])) {
                        $centreId = 'DLC' . $_POST['dlc_id'];
                    }
                    syncPortalUserToExam($_POST['username'], $_POST['name'], $_POST['email'] ?: null, $_POST['password'], $_POST['role'], $centreId);
                }

                // 🎬 Auto-create Training login for this ATC
                if ($_POST['role'] === 'ATC CENTER' && !empty($_POST['create_training']) && !empty($_POST['atc_id'])) {
                    $tUser = trim($_POST['training_username'] ?? '');
                    $tPass = trim($_POST['training_password'] ?? '');
                    if ($tUser && $tPass) {
                        // Check if training user already exists for this ATC
                        $chk = $pdo->prepare("SELECT id FROM users WHERE role='Training' AND atc_id=?");
                        $chk->execute([$_POST['atc_id']]);
                        if (!$chk->fetch()) {
                            $tName = $_POST['name'] . ' (Training)';
                            $pdo->prepare("INSERT INTO users (username, password, role, name, email, mobile, atc_id, status) VALUES (?, ?, 'Training', ?, ?, ?, ?, 'Active')")
                                ->execute([$tUser, $tPass, $tName, $_POST['email'] ?: null, $_POST['mobile'] ?: null, $_POST['atc_id']]);
                        }
                    }
                }

                echo json_encode(['success' => true, 'message' => 'User created successfully']); exit;

            case 'edit':
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                $stmt->execute([$_POST['username'], $_POST['id']]);
                if ($stmt->fetch()) { echo json_encode(['success' => false, 'message' => 'Username already exists']); exit; }
                if (!empty($_POST['email'])) {
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $stmt->execute([$_POST['email'], $_POST['id']]);
                    if ($stmt->fetch()) { echo json_encode(['success' => false, 'message' => 'Email already exists']); exit; }
                }
                if (!empty($_POST['password'])) {
                    $stmt = $pdo->prepare("UPDATE users SET username=?, password=?, role=?, name=?, email=?, mobile=?, date_of_birth=?, dlc_id=?, atc_id=?, status=? WHERE id=?");
                    $stmt->execute([$_POST['username'], $_POST['password'], $_POST['role'], $_POST['name'], $_POST['email'] ?: null, $_POST['mobile'] ?: null, $_POST['date_of_birth'] ?: null, $_POST['dlc_id'] ?: null, $_POST['atc_id'] ?: null, $_POST['status'], $_POST['id']]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username=?, role=?, name=?, email=?, mobile=?, date_of_birth=?, dlc_id=?, atc_id=?, status=? WHERE id=?");
                    $stmt->execute([$_POST['username'], $_POST['role'], $_POST['name'], $_POST['email'] ?: null, $_POST['mobile'] ?: null, $_POST['date_of_birth'] ?: null, $_POST['dlc_id'] ?: null, $_POST['atc_id'] ?: null, $_POST['status'], $_POST['id']]);
                }

                // 🔄 Auto-sync to Exam Portal (fire-and-forget)
                if (function_exists('syncPortalUserToExam') && in_array($_POST['role'], ['ATC CENTER', 'DLC Office'])) {
                    $centreId = null;
                    if ($_POST['role'] === 'ATC CENTER' && !empty($_POST['atc_id'])) {
                        $acStmt = $pdo->prepare("SELECT atc_code FROM atc_centers WHERE id = ?");
                        $acStmt->execute([$_POST['atc_id']]);
                        $centreId = $acStmt->fetchColumn() ?: (date('Y') . str_pad($_POST['atc_id'], 5, '0', STR_PAD_LEFT));
                    } elseif ($_POST['role'] === 'DLC Office' && !empty($_POST['dlc_id'])) {
                        $centreId = 'DLC' . $_POST['dlc_id'];
                    }
                    syncPortalUserToExam($_POST['username'], $_POST['name'], $_POST['email'] ?: null, $_POST['password'] ?: null, $_POST['role'], $centreId);
                }

                // 🎬 Create/Update Training login for this ATC
                if ($_POST['role'] === 'ATC CENTER' && !empty($_POST['create_training']) && !empty($_POST['atc_id'])) {
                    $tUser = trim($_POST['training_username'] ?? '');
                    $tPass = trim($_POST['training_password'] ?? '');
                    if ($tUser) {
                        $chk = $pdo->prepare("SELECT id FROM users WHERE role='Training' AND atc_id=?");
                        $chk->execute([$_POST['atc_id']]);
                        $existing = $chk->fetch(PDO::FETCH_ASSOC);
                        $tName = $_POST['name'] . ' (Training)';
                        if ($existing) {
                            if ($tPass) {
                                $pdo->prepare("UPDATE users SET username=?,password=?,name=?,status='Active' WHERE id=?")
                                    ->execute([$tUser,$tPass,$tName,$existing['id']]);
                            } else {
                                $pdo->prepare("UPDATE users SET username=?,name=?,status='Active' WHERE id=?")
                                    ->execute([$tUser,$tName,$existing['id']]);
                            }
                        } else {
                            if ($tPass) {
                                $pdo->prepare("INSERT INTO users (username,password,role,name,email,mobile,atc_id,status) VALUES (?,?,'Training',?,?,?,?,'Active')")
                                    ->execute([$tUser,$tPass,$tName,$_POST['email']?:null,$_POST['mobile']?:null,$_POST['atc_id']]);
                            }
                        }
                    }
                }

                echo json_encode(['success' => true, 'message' => 'User updated successfully']); exit;

            case 'delete':
                // Get username before deleting (for exam portal sync)
                $delStmt = $pdo->prepare("SELECT username, role FROM users WHERE id = ?");
                $delStmt->execute([$_POST['id']]);
                $delUser = $delStmt->fetch(PDO::FETCH_ASSOC);

                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$_POST['id']]);

                // 🔄 Remove from Exam Portal (fire-and-forget)
                if (function_exists('deletePortalUserFromExam') && $delUser && in_array($delUser['role'], ['ATC CENTER', 'DLC Office'])) {
                    deletePortalUserFromExam($delUser['username']);
                }

                echo json_encode(['success' => true, 'message' => 'User deleted successfully']); exit;

            case 'get':
                $stmt = $pdo->prepare("SELECT u.*, dlc.name as dlc_name, atc.name as atc_name FROM users u LEFT JOIN dlc_offices dlc ON u.dlc_id = dlc.id LEFT JOIN atc_centers atc ON u.atc_id = atc.id WHERE u.id = ?");
                $stmt->execute([$_POST['id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                // Also fetch Training login info for this ATC
                $user['training_user'] = null;
                if ($user && $user['role'] === 'ATC CENTER' && $user['atc_id']) {
                    $tStmt = $pdo->prepare("SELECT id, username FROM users WHERE role='Training' AND atc_id=? LIMIT 1");
                    $tStmt->execute([$user['atc_id']]);
                    $user['training_user'] = $tStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                echo json_encode(['success' => true, 'data' => $user]); exit;

            case 'reset_password':
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$_POST['new_password'], $_POST['id']]);

                // 🔄 Sync new password to Exam Portal (fire-and-forget)
                if (function_exists('syncPortalUserToExam')) {
                    $rpStmt = $pdo->prepare("SELECT username, name, email, role, dlc_id, atc_id FROM users WHERE id = ?");
                    $rpStmt->execute([$_POST['id']]);
                    $rpUser = $rpStmt->fetch(PDO::FETCH_ASSOC);
                    if ($rpUser && in_array($rpUser['role'], ['ATC CENTER', 'DLC Office'])) {
                        $centreId = null;
                        if ($rpUser['role'] === 'ATC CENTER' && !empty($rpUser['atc_id'])) {
                            $acStmt = $pdo->prepare("SELECT atc_code FROM atc_centers WHERE id = ?");
                            $acStmt->execute([$rpUser['atc_id']]);
                            $centreId = $acStmt->fetchColumn() ?: (date('Y') . str_pad($rpUser['atc_id'], 5, '0', STR_PAD_LEFT));
                        } elseif ($rpUser['role'] === 'DLC Office' && !empty($rpUser['dlc_id'])) {
                            $centreId = 'DLC' . $rpUser['dlc_id'];
                        }
                        syncPortalUserToExam($rpUser['username'], $rpUser['name'], $rpUser['email'], $_POST['new_password'], $rpUser['role'], $centreId);
                    }
                }

                echo json_encode(['success' => true, 'message' => 'Password reset successfully']); exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit;
    }
}

$searchTerm  = $_GET['search'] ?? '';
$roleFilter  = $_GET['role']   ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';

$sql = "SELECT u.*, dlc.name as dlc_name, atc.name as atc_name FROM users u LEFT JOIN dlc_offices dlc ON u.dlc_id = dlc.id LEFT JOIN atc_centers atc ON u.atc_id = atc.id WHERE 1=1";
$params = [];
if ($searchTerm) {
    $sql .= " AND (u.username LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR u.mobile LIKE ?)";
    $s = "%$searchTerm%";
    $params = array_merge($params, [$s, $s, $s, $s]);
}
if ($roleFilter !== 'all')   { $sql .= " AND u.role = ?";   $params[] = $roleFilter; }
if ($statusFilter !== 'all') { $sql .= " AND u.status = ?"; $params[] = $statusFilter; }
$sql .= " ORDER BY u.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$dlcOffices = $pdo->query("SELECT id, name FROM dlc_offices WHERE status = 'Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$atcCenters = $pdo->query("SELECT id, name, dlc_id FROM atc_centers WHERE status = 'Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$totalCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$adminCount = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Admin'")->fetchColumn();
$dlcCount   = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'DLC Office'")->fetchColumn();
$atcCount   = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'ATC CENTER'")->fetchColumn();
$activeCount = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'Active'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management — Admin | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>👥</text></svg>">

    <style>
    /* ── Design tokens ── */
    :root {
        --font: 'Sora', sans-serif;
        --mono: 'JetBrains Mono', monospace;
        --bg: #f0f2f7;
        --surface: #ffffff;
        --surface-raised: #f8f9fc;
        --border: #e4e8f0;
        --border-strong: #cdd3e0;
        --text: #0f1523;
        --text-2: #4a5568;
        --text-3: #8896a5;
        --indigo: #4f6ef7;
        --indigo-dark: #3a57e8;
        --indigo-soft: #eef1fe;
        --violet: #7c3aed;
        --violet-soft: #f5f3ff;
        --emerald: #00c48c;
        --emerald-dark: #00a376;
        --emerald-soft: #e6faf4;
        --amber: #f59e0b;
        --amber-soft: #fffbeb;
        --rose: #f43f5e;
        --rose-soft: #fff1f3;
        --sky: #0ea5e9;
        --sky-soft: #f0f9ff;
        --shadow-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
        --shadow-md: 0 4px 16px rgba(0,0,0,.07);
        --shadow-lg: 0 24px 64px rgba(0,0,0,.14), 0 8px 24px rgba(0,0,0,.08);
        --r-sm: 6px; --r-md: 10px; --r-lg: 14px; --r-xl: 20px; --r-full: 9999px;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: var(--font); background: var(--bg); color: var(--text); }

    /* ── Page header ── */
    .page-header-block {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 1.75rem; padding: 1.5rem 1.75rem;
        background: var(--surface); border-radius: var(--r-xl);
        border: 1px solid var(--border); box-shadow: var(--shadow-sm);
    }
    .page-header-left { display: flex; align-items: center; gap: 1.125rem; }
    .page-header-icon {
        width: 48px; height: 48px; border-radius: var(--r-lg);
        background: linear-gradient(135deg, var(--indigo), var(--violet));
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 8px 20px rgba(79,110,247,.28); flex-shrink: 0;
    }
    .page-header-icon svg { width: 24px; height: 24px; stroke: white; }
    .page-header-title { font-size: 1.375rem; font-weight: 800; color: var(--text); letter-spacing: -.03em; }
    .page-header-subtitle { font-size: .8125rem; color: var(--text-3); margin-top: .15rem; }

    .btn-add-user {
        display: inline-flex; align-items: center; gap: .5rem;
        padding: .7rem 1.375rem;
        background: linear-gradient(135deg, var(--indigo), var(--indigo-dark));
        border: none; border-radius: var(--r-md); color: white;
        font-size: .875rem; font-weight: 700; font-family: var(--font);
        cursor: pointer; white-space: nowrap;
        box-shadow: 0 4px 14px rgba(79,110,247,.3);
        transition: all .2s ease;
    }
    .btn-add-user:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(79,110,247,.35); }
    .btn-add-user svg { width: 16px; height: 16px; }

    /* ── KPI grid ── */
    .kpi-grid {
        display: grid; grid-template-columns: repeat(4, 1fr);
        gap: 1.125rem; margin-bottom: 1.75rem;
    }
    .kpi-card {
        background: var(--surface); border-radius: var(--r-xl);
        border: 1px solid var(--border); box-shadow: var(--shadow-sm);
        padding: 1.375rem 1.5rem; position: relative; overflow: hidden;
        transition: transform .2s, box-shadow .2s;
    }
    .kpi-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
    .kpi-card::before {
        content: ''; position: absolute; top: 0; left: 0; right: 0;
        height: 3px; border-radius: var(--r-xl) var(--r-xl) 0 0;
    }
    .kpi-card.indigo::before { background: linear-gradient(90deg, var(--indigo), #818cf8); }
    .kpi-card.violet::before { background: linear-gradient(90deg, var(--violet), #a78bfa); }
    .kpi-card.sky::before    { background: linear-gradient(90deg, var(--sky),    #38bdf8); }
    .kpi-card.emerald::before{ background: linear-gradient(90deg, var(--emerald),#34d399); }
    .kpi-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
    .kpi-icon { width: 40px; height: 40px; border-radius: var(--r-md); display: flex; align-items: center; justify-content: center; }
    .kpi-icon svg { width: 20px; height: 20px; }
    .kpi-icon.indigo { background: var(--indigo-soft); } .kpi-icon.indigo svg { stroke: var(--indigo); }
    .kpi-icon.violet { background: var(--violet-soft); } .kpi-icon.violet svg { stroke: var(--violet); }
    .kpi-icon.sky    { background: var(--sky-soft);    } .kpi-icon.sky svg    { stroke: var(--sky); }
    .kpi-icon.emerald{ background: var(--emerald-soft);} .kpi-icon.emerald svg{ stroke: var(--emerald-dark); }
    .kpi-value { font-size: 1.875rem; font-weight: 800; color: var(--text); letter-spacing: -.04em; line-height: 1; }
    .kpi-label { font-size: .775rem; color: var(--text-3); font-weight: 600; text-transform: uppercase; letter-spacing: .05em; margin-top: .35rem; }

    /* ── Role tabs ── */
    .role-tabs {
        display: flex; gap: .375rem; margin-bottom: 1.5rem;
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--r-xl); padding: .375rem; box-shadow: var(--shadow-sm);
        width: fit-content;
    }
    .role-tab {
        display: flex; align-items: center; gap: .5rem;
        padding: .625rem 1.125rem; border-radius: var(--r-lg);
        font-size: .8375rem; font-weight: 600; color: var(--text-2);
        text-decoration: none; transition: all .18s ease; white-space: nowrap;
    }
    .role-tab:hover { background: var(--surface-raised); color: var(--text); }
    .role-tab.active { background: var(--indigo); color: white; box-shadow: 0 4px 12px rgba(79,110,247,.3); }
    .role-tab .tab-pill {
        padding: .15rem .5rem; border-radius: var(--r-full);
        font-size: .72rem; font-weight: 800; background: rgba(255,255,255,.22);
    }
    .role-tab:not(.active) .tab-pill { background: var(--border); color: var(--text-3); }

    /* ── Toolbar ── */
    .toolbar {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 1rem; gap: 1rem; flex-wrap: wrap;
    }
    .toolbar-left { display: flex; align-items: center; gap: .75rem; }
    .toolbar-title { font-size: 1.0625rem; font-weight: 800; color: var(--text); letter-spacing: -.02em; }
    .toolbar-count {
        font-size: .75rem; font-weight: 700; color: var(--text-3);
        background: var(--surface-raised); border: 1px solid var(--border);
        padding: .2rem .6rem; border-radius: var(--r-full);
    }
    .toolbar-right { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; }

    .search-wrap { position: relative; display: flex; align-items: center; }
    .search-wrap svg { position: absolute; left: .875rem; width: 16px; height: 16px; stroke: var(--text-3); pointer-events: none; }
    .search-input {
        padding: .7rem .875rem .7rem 2.5rem;
        border: 1.5px solid var(--border); border-radius: var(--r-md);
        font-size: .875rem; font-family: var(--font); font-weight: 500;
        background: var(--surface); color: var(--text);
        outline: none; width: 220px; transition: all .18s;
    }
    .search-input:focus { border-color: var(--indigo); box-shadow: 0 0 0 3px rgba(79,110,247,.1); }

    .select-sm {
        padding: .7rem 2.25rem .7rem .875rem; border: 1.5px solid var(--border);
        border-radius: var(--r-md); font-size: .875rem; font-family: var(--font);
        font-weight: 500; background: var(--surface); color: var(--text);
        outline: none; cursor: pointer; appearance: none; -webkit-appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238896a5' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right .625rem center; background-size: 14px;
        transition: border-color .18s;
    }
    .select-sm:focus { border-color: var(--indigo); }

    .btn-search {
        padding: .7rem 1.25rem; background: var(--surface); border: 1.5px solid var(--border);
        border-radius: var(--r-md); font-size: .875rem; font-weight: 600;
        font-family: var(--font); color: var(--text-2); cursor: pointer; transition: all .18s;
    }
    .btn-search:hover { border-color: var(--indigo); color: var(--indigo); background: var(--indigo-soft); }

    /* ── Table ── */
    .table-wrap {
        background: var(--surface); border-radius: var(--r-xl);
        border: 1px solid var(--border); box-shadow: var(--shadow-sm); overflow: hidden;
    }
    .data-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    .data-table thead { background: var(--surface-raised); }
    .data-table thead th {
        padding: 1rem 1.25rem; text-align: left; font-size: .72rem; font-weight: 700;
        color: var(--text-3); text-transform: uppercase; letter-spacing: .06em;
        border-bottom: 1px solid var(--border); white-space: nowrap;
    }
    .data-table tbody tr { border-bottom: 1px solid #f3f5f9; transition: background .15s; }
    .data-table tbody tr:last-child { border-bottom: none; }
    .data-table tbody tr:hover { background: #fafbff; }
    .data-table tbody td { padding: 1.1rem 1.25rem; }

    /* User avatar cell */
    .user-cell { display: flex; align-items: center; gap: .875rem; }
    .user-avatar {
        width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        font-size: .875rem; font-weight: 800; color: white;
        background: linear-gradient(135deg, var(--indigo), var(--violet));
    }
    .user-name { font-size: .9rem; font-weight: 600; color: var(--text); }
    .user-id { font-size: .75rem; color: var(--text-3); font-family: var(--mono); margin-top: .1rem; }
    .cell-main { font-weight: 600; color: var(--text); }
    .cell-sub { font-size: .8rem; color: var(--text-3); margin-top: .1rem; }

    /* Role badge */
    .role-badge {
        display: inline-flex; align-items: center; gap: .35rem;
        padding: .3rem .75rem; border-radius: var(--r-full);
        font-size: .75rem; font-weight: 700; white-space: nowrap;
    }
    .role-badge-dot { width: 6px; height: 6px; border-radius: 50%; }
    .role-admin   { background: var(--violet-soft); color: #5b21b6; border: 1px solid #ddd6fe; }
    .role-admin   .role-badge-dot { background: var(--violet); }
    .role-dlc     { background: var(--sky-soft);    color: #0369a1; border: 1px solid #bae6fd; }
    .role-dlc     .role-badge-dot { background: var(--sky); }
    .role-atc     { background: var(--emerald-soft);color: var(--emerald-dark); border: 1px solid #b3f0de; }
    .role-atc     .role-badge-dot { background: var(--emerald); }
    .role-training { background: #fdf2f8; color: #9d174d; border: 1px solid #fbcfe8; }
    .role-training .role-badge-dot { background: #ec4899; }

    /* Status badge */
    .status-badge {
        display: inline-flex; align-items: center; gap: .35rem;
        padding: .3rem .75rem; border-radius: var(--r-full);
        font-size: .75rem; font-weight: 700;
    }
    .status-dot { width: 6px; height: 6px; border-radius: 50%; }
    .status-active   { background: var(--emerald-soft); color: var(--emerald-dark); border: 1px solid #b3f0de; }
    .status-active   .status-dot { background: var(--emerald); }
    .status-inactive { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }
    .status-inactive .status-dot { background: #94a3b8; }

    /* Action buttons */
    .actions-wrap { display: flex; align-items: center; gap: .375rem; }
    .btn-act {
        width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;
        border-radius: var(--r-md); border: 1.5px solid var(--border); background: var(--surface-raised);
        cursor: pointer; transition: all .18s;
    }
    .btn-act svg { width: 15px; height: 15px; stroke: var(--text-3); transition: stroke .18s; }
    .btn-act:hover { border-color: var(--indigo); background: var(--indigo-soft); }
    .btn-act:hover svg { stroke: var(--indigo); }
    .btn-act.danger:hover { border-color: #fca5a5; background: var(--rose-soft); }
    .btn-act.danger:hover svg { stroke: var(--rose); }
    .btn-act.amber-act:hover { border-color: #fde68a; background: var(--amber-soft); }
    .btn-act.amber-act:hover svg { stroke: var(--amber); }

    /* Empty state */
    .empty-state { text-align: center; padding: 4rem 2rem; }
    .empty-icon {
        width: 56px; height: 56px; border-radius: var(--r-lg); background: var(--surface-raised);
        border: 1px solid var(--border); display: flex; align-items: center; justify-content: center;
        margin: 0 auto 1rem;
    }
    .empty-icon svg { width: 26px; height: 26px; stroke: var(--text-3); }
    .empty-title { font-size: .9375rem; font-weight: 700; color: var(--text-2); }
    .empty-sub { font-size: .8125rem; color: var(--text-3); margin-top: .25rem; }

    /* ── Toast notification ── */
    .toast-container { position: fixed; top: 1.25rem; right: 1.25rem; z-index: 9999; display: flex; flex-direction: column; gap: .625rem; }
    .toast {
        display: flex; align-items: center; gap: .75rem;
        padding: .875rem 1.125rem; border-radius: var(--r-lg);
        background: var(--surface); border: 1px solid var(--border);
        box-shadow: var(--shadow-lg); min-width: 280px; max-width: 380px;
        animation: toastIn .3s cubic-bezier(.34,1.56,.64,1);
        font-size: .875rem; font-weight: 500;
    }
    .toast.success { border-left: 3px solid var(--emerald); }
    .toast.error   { border-left: 3px solid var(--rose); }
    .toast-icon { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .toast.success .toast-icon { background: var(--emerald-soft); }
    .toast.success .toast-icon svg { stroke: var(--emerald-dark); }
    .toast.error   .toast-icon { background: var(--rose-soft); }
    .toast.error   .toast-icon svg { stroke: var(--rose); }
    .toast-icon svg { width: 16px; height: 16px; }
    .toast-msg { flex: 1; color: var(--text); }
    .toast-close { background: none; border: none; cursor: pointer; color: var(--text-3); padding: .25rem; }
    .toast-close svg { width: 14px; height: 14px; stroke: currentColor; }
    @keyframes toastIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }

    /* ── Delete confirm dialog ── */
    .confirm-overlay {
        position: fixed; inset: 0; background: rgba(10,15,30,.55); backdrop-filter: blur(5px);
        z-index: 2000; display: none; align-items: center; justify-content: center; padding: 1.5rem;
        font-family: var(--font);
    }
    .confirm-overlay.active { display: flex; }
    .confirm-card {
        background: var(--surface); border-radius: var(--r-xl); border: 1px solid var(--border);
        box-shadow: var(--shadow-lg); width: 100%; max-width: 400px; overflow: hidden;
        animation: modalSlideIn .25s cubic-bezier(.34,1.56,.64,1);
    }
    .confirm-body { padding: 2rem; text-align: center; }
    .confirm-icon-wrap {
        width: 56px; height: 56px; border-radius: 50%; background: var(--rose-soft);
        border: 2px solid #fecdd3; display: flex; align-items: center; justify-content: center;
        margin: 0 auto 1.25rem;
    }
    .confirm-icon-wrap svg { width: 26px; height: 26px; stroke: var(--rose); }
    .confirm-title { font-size: 1.0625rem; font-weight: 800; color: var(--text); margin-bottom: .5rem; }
    .confirm-msg { font-size: .875rem; color: var(--text-2); line-height: 1.5; }
    .confirm-username { font-weight: 700; color: var(--text); font-family: var(--mono); }
    .confirm-footer { display: flex; gap: .75rem; padding: 1.25rem 1.75rem; border-top: 1px solid var(--border); background: var(--surface-raised); }
    .confirm-footer button { flex: 1; padding: .75rem; border-radius: var(--r-md); font-size: .875rem; font-weight: 700; font-family: var(--font); cursor: pointer; border: none; transition: all .2s; }
    .btn-cancel-confirm { background: var(--surface); border: 1.5px solid var(--border) !important; color: var(--text-2); }
    .btn-cancel-confirm:hover { background: var(--surface-raised); }
    .btn-delete-confirm { background: linear-gradient(135deg, var(--rose), #e11d48); color: white; box-shadow: 0 4px 14px rgba(244,63,94,.3); }
    .btn-delete-confirm:hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(244,63,94,.35); }

    @keyframes modalSlideIn { from { opacity: 0; transform: scale(.94) translateY(12px); } to { opacity: 1; transform: scale(1) translateY(0); } }

    /* ── Responsive ── */
    @media (max-width: 1280px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 768px) {
        .kpi-grid { grid-template-columns: 1fr; }
        .toolbar { flex-direction: column; align-items: flex-start; }
        .role-tabs { overflow-x: auto; max-width: 100%; }
        .page-header-block { flex-direction: column; align-items: flex-start; gap: 1rem; }
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
                    <h2>User Management</h2>
                    <p>Manage system users, roles & access</p>
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
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div>
                        <h1 class="page-header-title">User Management</h1>
                        <p class="page-header-subtitle">Manage system users, roles &amp; access control</p>
                    </div>
                </div>
                <button class="btn-add-user" onclick="openAddModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add User
                </button>
            </div>

            <!-- KPI cards -->
            <div class="kpi-grid">
                <div class="kpi-card indigo">
                    <div class="kpi-top">
                        <div class="kpi-icon indigo">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        </div>
                    </div>
                    <div class="kpi-value"><?= $totalCount ?></div>
                    <div class="kpi-label">Total Users</div>
                </div>
                <div class="kpi-card violet">
                    <div class="kpi-top">
                        <div class="kpi-icon violet">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                        </div>
                    </div>
                    <div class="kpi-value"><?= $adminCount ?></div>
                    <div class="kpi-label">Admins</div>
                </div>
                <div class="kpi-card sky">
                    <div class="kpi-top">
                        <div class="kpi-icon sky">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg>
                        </div>
                    </div>
                    <div class="kpi-value"><?= $dlcCount ?></div>
                    <div class="kpi-label">DLC Users</div>
                </div>
                <div class="kpi-card emerald">
                    <div class="kpi-top">
                        <div class="kpi-icon emerald">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                        </div>
                    </div>
                    <div class="kpi-value"><?= $atcCount ?></div>
                    <div class="kpi-label">ATC Users</div>
                </div>
            </div>

            <!-- Role tabs -->
            <div class="role-tabs">
                <?php
                $tabs = [
                    ['role' => 'all',       'label' => 'All Roles',  'count' => $totalCount],
                    ['role' => 'Admin',     'label' => 'Admin',      'count' => $adminCount],
                    ['role' => 'DLC Office','label' => 'DLC Office', 'count' => $dlcCount],
                    ['role' => 'ATC CENTER','label' => 'ATC Center', 'count' => $atcCount],
                ];
                foreach ($tabs as $tab):
                    $active = $roleFilter === $tab['role'] ? 'active' : '';
                    $href = '?role=' . urlencode($tab['role']) . '&status=' . urlencode($statusFilter) . ($searchTerm ? '&search=' . urlencode($searchTerm) : '');
                ?>
                    <a href="<?= $href ?>" class="role-tab <?= $active ?>">
                        <?= $tab['label'] ?>
                        <span class="tab-pill"><?= $tab['count'] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Toolbar -->
            <div class="toolbar">
                <div class="toolbar-left">
                    <span class="toolbar-title">User List</span>
                    <span class="toolbar-count"><?= count($users) ?> shown</span>
                </div>
                <div class="toolbar-right">
                    <form method="GET" style="display:contents;">
                        <input type="hidden" name="role" value="<?= htmlspecialchars($roleFilter) ?>">
                        <select name="status" class="select-sm" onchange="this.form.submit()">
                            <option value="all"     <?= $statusFilter === 'all'      ? 'selected' : '' ?>>All Status</option>
                            <option value="Active"  <?= $statusFilter === 'Active'   ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive"<?= $statusFilter === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                        <div class="search-wrap">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                            <input type="text" name="search" class="search-input" placeholder="Search users…" value="<?= htmlspecialchars($searchTerm) ?>">
                        </div>
                        <button type="submit" class="btn-search">Search</button>
                    </form>
                </div>
            </div>

            <!-- Table -->
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Full Name</th>
                            <th>Role</th>
                            <th>Organization</th>
                            <th>Contact</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="7">
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                                </div>
                                <div class="empty-title">No users found</div>
                                <div class="empty-sub">Try adjusting your search or filters.</div>
                            </div>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $user):
                            $initial = strtoupper(substr($user['name'] ?: $user['username'], 0, 1));
                            $roleClass = match($user['role']) {
                                'Admin'      => 'role-admin',
                                'DLC Office' => 'role-dlc',
                                'ATC CENTER' => 'role-atc',
                                'Training'   => 'role-training',
                                default      => 'role-dlc'
                            };
                            $statusClass = strtolower($user['status']) === 'active' ? 'status-active' : 'status-inactive';
                        ?>
                        <tr>
                            <td>
                                <div class="user-cell">
                                    <div class="user-avatar"><?= $initial ?></div>
                                    <div>
                                        <div class="user-name"><?= htmlspecialchars($user['username']) ?></div>
                                        <div class="user-id">#<?= $user['id'] ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="cell-main"><?= htmlspecialchars($user['name']) ?></span></td>
                            <td>
                                <span class="role-badge <?= $roleClass ?>">
                                    <span class="role-badge-dot"></span>
                                    <?= $user['role'] ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($user['atc_name']): ?>
                                    <div class="cell-main"><?= htmlspecialchars($user['atc_name']) ?></div>
                                    <?php if ($user['dlc_name']): ?><div class="cell-sub"><?= htmlspecialchars($user['dlc_name']) ?></div><?php endif; ?>
                                <?php elseif ($user['dlc_name']): ?>
                                    <div class="cell-main"><?= htmlspecialchars($user['dlc_name']) ?></div>
                                <?php else: ?>
                                    <span style="color:var(--text-3);">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['mobile']): ?><div class="cell-main"><?= htmlspecialchars($user['mobile']) ?></div><?php endif; ?>
                                <?php if ($user['email']): ?><div class="cell-sub"><?= htmlspecialchars($user['email']) ?></div><?php endif; ?>
                                <?php if (!$user['mobile'] && !$user['email']): ?><span style="color:var(--text-3);">—</span><?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?= $statusClass ?>">
                                    <span class="status-dot"></span>
                                    <?= $user['status'] ?>
                                </span>
                            </td>
                            <td>
                                <div class="actions-wrap">
                                    <button class="btn-act amber-act" onclick="resetPassword(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username'], ENT_QUOTES) ?>')" title="Reset Password">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    </button>
                                    <button class="btn-act" onclick="editUser(<?= $user['id'] ?>)" title="Edit User">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </button>
                                    <button class="btn-act danger" onclick="confirmDelete(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username'], ENT_QUOTES) ?>')" title="Delete User">
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

        </div><!-- /page-content -->
    </main>
</div>

<!-- Toast container -->
<div class="toast-container" id="toastContainer"></div>

<!-- Delete confirm dialog -->
<div class="confirm-overlay" id="confirmOverlay">
    <div class="confirm-card">
        <div class="confirm-body">
            <div class="confirm-icon-wrap">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            </div>
            <div class="confirm-title">Delete User?</div>
            <div class="confirm-msg">You're about to permanently delete <span class="confirm-username" id="confirmUsername"></span>. This action cannot be undone.</div>
        </div>
        <div class="confirm-footer">
            <button class="btn-cancel-confirm" onclick="closeConfirm()">Cancel</button>
            <button class="btn-delete-confirm" id="confirmDeleteBtn">Delete</button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/users_modals.php'; ?>

<script src="../assets/js/dashboard.js"></script>
<script>
/* ================================================================
   USER MANAGEMENT SCRIPTS
   ================================================================ */

// ── Toast ──────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const c = document.getElementById('toastContainer');
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    const checkSvg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`;
    const errorSvg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>`;
    t.innerHTML = `<div class="toast-icon">${type === 'success' ? checkSvg : errorSvg}</div><span class="toast-msg">${msg}</span><button class="toast-close" onclick="this.closest('.toast').remove()"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>`;
    c.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

// ── Loading state helper ───────────────────────────────────────
function setLoading(btn, loading, originalHTML) {
    if (loading) {
        btn.disabled = true;
        btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;animation:spin .8s linear infinite;"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> Processing…`;
    } else {
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    }
}

// ── Open add modal ─────────────────────────────────────────────
function openAddModal() {
    document.getElementById('modalTitle').textContent    = 'Add New User';
    document.getElementById('modalSubtitle').textContent = 'Fill in the details to create a user account';
    document.getElementById('submitBtnText').textContent  = 'Add User';
    document.getElementById('formAction').value  = 'add';
    document.getElementById('userId').value      = '';
    document.getElementById('userForm').reset();
    document.getElementById('passwordRequired').style.display = 'inline';
    document.getElementById('password').required = true;
    document.getElementById('passwordHint').textContent = 'Minimum 6 characters required';
    document.getElementById('dlcField').style.display  = 'none';
    document.getElementById('atcField').style.display  = 'none';
    document.getElementById('trainingSection').style.display = 'none';
    document.getElementById('createTraining').checked = false;
    document.getElementById('trainingFields').style.display = 'none';
    document.getElementById('sameCredentials').checked = false;
    document.getElementById('training_username').value = '';
    document.getElementById('training_password').value = '';
    document.getElementById('trainingStatusBadge').style.display = 'none';
    document.getElementById('userModal').classList.add('active');
}

// ── Close modal ────────────────────────────────────────────────
function closeModal() {
    document.getElementById('userModal').classList.remove('active');
    document.getElementById('userForm').reset();
}

// ── Role change handler ────────────────────────────────────────
function handleRoleChange() {
    const role       = document.getElementById('role').value;
    const dlcField   = document.getElementById('dlcField');
    const atcField   = document.getElementById('atcField');
    const dlcSelect  = document.getElementById('dlc_id');
    const atcSelect  = document.getElementById('atc_id');
    const dlcReq     = document.getElementById('dlcRequired');
    const atcReq     = document.getElementById('atcRequired');

    dlcSelect.value = '';
    atcSelect.value = '';

    const show = (el, req, reqEl) => {
        el.style.display = 'block';
        req.required = true;
        if (reqEl) reqEl.style.display = 'inline';
    };
    const hide = (el, req, reqEl) => {
        el.style.display = 'none';
        req.required = false;
        if (reqEl) reqEl.style.display = 'none';
    };

    if (role === 'Admin') {
        hide(dlcField, dlcSelect, dlcReq);
        hide(atcField, atcSelect, atcReq);
    } else if (role === 'DLC Office') {
        show(dlcField, dlcSelect, dlcReq);
        hide(atcField, atcSelect, atcReq);
    } else if (role === 'ATC CENTER') {
        show(dlcField, dlcSelect, dlcReq);
        show(atcField, atcSelect, atcReq);
    } else if (role === 'Training') {
        hide(dlcField, dlcSelect, dlcReq);
        show(atcField, atcSelect, atcReq);
    } else {
        hide(dlcField, dlcSelect, dlcReq);
        hide(atcField, atcSelect, atcReq);
    }

    // Show/hide training section
    const trainingSection = document.getElementById('trainingSection');
    trainingSection.style.display = (role === 'ATC CENTER') ? 'block' : 'none';
    if (role !== 'ATC CENTER') {
        document.getElementById('createTraining').checked = false;
        document.getElementById('trainingFields').style.display = 'none';
    }
}

// ── Toggle training fields visibility ─────────────────────────
document.getElementById('createTraining').addEventListener('change', function() {
    document.getElementById('trainingFields').style.display = this.checked ? 'block' : 'none';
});

// ── Same credentials handler ──────────────────────────────────
function handleSameCredentials() {
    const same = document.getElementById('sameCredentials').checked;
    const tUser = document.getElementById('training_username');
    const tPass = document.getElementById('training_password');
    if (same) {
        tUser.value = document.getElementById('username').value;
        tPass.value = document.getElementById('password').value;
        tUser.readOnly = true;
        tPass.readOnly = true;
        tUser.style.opacity = '.6';
        tPass.style.opacity = '.6';
    } else {
        tUser.readOnly = false;
        tPass.readOnly = false;
        tUser.style.opacity = '1';
        tPass.style.opacity = '1';
    }
}

// ── DLC change — filter ATC options ───────────────────────────
function handleDLCChange() {
    const dlcId    = document.getElementById('dlc_id').value;
    const atcSel   = document.getElementById('atc_id');
    atcSel.querySelectorAll('option').forEach(opt => {
        opt.style.display = (!opt.value || !dlcId || opt.dataset.dlc === dlcId) ? '' : 'none';
    });
    const cur = atcSel.options[atcSel.selectedIndex];
    if (cur && cur.dataset.dlc && cur.dataset.dlc !== dlcId) atcSel.value = '';
}

// ── Edit user ──────────────────────────────────────────────────
async function editUser(id) {
    try {
        const fd = new FormData();
        fd.append('action', 'get');
        fd.append('id', id);
        const res  = await fetch('', { method: 'POST', body: new URLSearchParams(fd) });
        const data = await res.json();
        if (!data.success) { showToast('Error: ' + data.message, 'error'); return; }

        const u = data.data;
        document.getElementById('modalTitle').textContent    = 'Edit User';
        document.getElementById('modalSubtitle').textContent = 'Update account details and permissions';
        document.getElementById('submitBtnText').textContent  = 'Update User';
        document.getElementById('formAction').value  = 'edit';
        document.getElementById('userId').value      = u.id;
        document.getElementById('username').value    = u.username;
        document.getElementById('password').value    = '';
        document.getElementById('password').required = false;
        document.getElementById('passwordRequired').style.display = 'none';
        document.getElementById('passwordHint').textContent = 'Leave blank to keep current password';
        document.getElementById('name').value        = u.name;
        document.getElementById('role').value        = u.role;
        document.getElementById('status').value      = u.status;
        document.getElementById('mobile').value      = u.mobile  || '';
        document.getElementById('email').value       = u.email   || '';
        document.getElementById('date_of_birth').value = u.date_of_birth || '';
        document.getElementById('dlc_id').value      = u.dlc_id  || '';
        document.getElementById('atc_id').value      = u.atc_id  || '';

        handleRoleChange();
        if (u.role === 'ATC CENTER' && u.dlc_id) handleDLCChange();

        // Populate training login info
        if (u.role === 'ATC CENTER') {
            const badge = document.getElementById('trainingStatusBadge');
            const tUserField = document.getElementById('training_username');
            const tPassField = document.getElementById('training_password');
            document.getElementById('sameCredentials').checked = false;
            tUserField.readOnly = false; tUserField.style.opacity = '1';
            tPassField.readOnly = false; tPassField.style.opacity = '1';
            if (u.training_user) {
                badge.textContent = '✓ Active';
                badge.style.display = 'inline';
                badge.style.background = '#d1fae5';
                badge.style.color = '#065f46';
                badge.style.border = '1px solid #a7f3d0';
                tUserField.value = u.training_user.username;
                tPassField.value = '';
                tPassField.placeholder = 'Leave blank to keep current';
                document.getElementById('createTraining').checked = true;
                document.getElementById('trainingFields').style.display = 'block';
            } else {
                badge.textContent = 'Not Created';
                badge.style.display = 'inline';
                badge.style.background = '#fef3c7';
                badge.style.color = '#92400e';
                badge.style.border = '1px solid #fde68a';
                tUserField.value = '';
                tPassField.value = '';
                tPassField.placeholder = 'Set password';
                document.getElementById('createTraining').checked = false;
                document.getElementById('trainingFields').style.display = 'none';
            }
        }

        document.getElementById('userModal').classList.add('active');
    } catch (err) {
        console.error(err);
        showToast('Error loading user data', 'error');
    }
}

// ── Delete with confirm dialog ─────────────────────────────────
let _pendingDeleteId = null;
function confirmDelete(id, username) {
    _pendingDeleteId = id;
    document.getElementById('confirmUsername').textContent = username;
    document.getElementById('confirmOverlay').classList.add('active');
}
function closeConfirm() {
    _pendingDeleteId = null;
    document.getElementById('confirmOverlay').classList.remove('active');
}
document.getElementById('confirmDeleteBtn').addEventListener('click', async () => {
    if (!_pendingDeleteId) return;
    const btn = document.getElementById('confirmDeleteBtn');
    const orig = btn.textContent;
    btn.disabled = true; btn.textContent = 'Deleting…';
    try {
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', _pendingDeleteId);
        const res  = await fetch('', { method: 'POST', body: new URLSearchParams(fd) });
        const data = await res.json();
        closeConfirm();
        if (data.success) { showToast(data.message, 'success'); setTimeout(() => location.reload(), 900); }
        else { showToast('Error: ' + data.message, 'error'); btn.disabled = false; btn.textContent = orig; }
    } catch (err) {
        console.error(err);
        showToast('Error deleting user', 'error');
        btn.disabled = false; btn.textContent = orig;
    }
});

// ── Reset password modal ───────────────────────────────────────
function resetPassword(id, username) {
    document.getElementById('resetUserId').value = id;
    document.getElementById('resetUsername').textContent = username;
    document.getElementById('resetUserAvatar').textContent = username.charAt(0).toUpperCase();
    document.getElementById('new_password').value = '';
    // Reset strength meter
    const wrap = document.getElementById('resetPwdStrengthWrap');
    if (wrap) wrap.style.display = 'none';
    document.getElementById('resetPasswordModal').classList.add('active');
}
function closeResetPasswordModal() {
    document.getElementById('resetPasswordModal').classList.remove('active');
    document.getElementById('resetPasswordForm').reset();
}

// ── Form submission: add / edit ────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const userForm = document.getElementById('userForm');
    if (userForm) {
        userForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn  = document.getElementById('submitBtn');
            const orig = btn.innerHTML;
            setLoading(btn, true, orig);
            try {
                const res  = await fetch('', { method: 'POST', body: new URLSearchParams(new FormData(userForm)) });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal();
                    setTimeout(() => location.reload(), 900);
                } else {
                    showToast('Error: ' + data.message, 'error');
                    setLoading(btn, false, orig);
                }
            } catch (err) {
                console.error(err);
                showToast('Error saving user', 'error');
                setLoading(btn, false, orig);
            }
        });
    }

    const resetForm = document.getElementById('resetPasswordForm');
    if (resetForm) {
        resetForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn  = resetForm.querySelector('button[type="submit"]');
            const orig = btn.innerHTML;
            setLoading(btn, true, orig);
            try {
                const res  = await fetch('', { method: 'POST', body: new URLSearchParams(new FormData(resetForm)) });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message, 'success');
                    closeResetPasswordModal();
                } else {
                    showToast('Error: ' + data.message, 'error');
                    setLoading(btn, false, orig);
                }
            } catch (err) {
                console.error(err);
                showToast('Error resetting password', 'error');
                setLoading(btn, false, orig);
            }
        });
    }

    // Close modals on overlay or Escape
    document.querySelectorAll('.modal-overlay, .confirm-overlay').forEach(el => {
        el.addEventListener('click', (e) => { if (e.target === el) el.classList.remove('active'); });
    });
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.modal-overlay.active, .confirm-overlay.active').forEach(el => el.classList.remove('active'));
    });
});

// CSS spin keyframe (referenced in setLoading)
const style = document.createElement('style');
style.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
document.head.appendChild(style);
</script>

</body>
</html>