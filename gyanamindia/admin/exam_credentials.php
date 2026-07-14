<?php
/**
 * Exam Portal Credentials Management
 * Manage admin, ATC, and DLC login accounts for the Gyanam Exam Portal.
 * Only accessible by Admin users.
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
            case 'list':
                $result = examApi_request('GET', '/portal-users');
                if ($result['success']) {
                    echo json_encode(['success' => true, 'users' => $result['data']]);
                } else {
                    echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Failed to fetch']);
                }
                exit;

            case 'save':
                $payload = [
                    'username'  => trim($_POST['username'] ?? ''),
                    'name'      => trim($_POST['name'] ?? ''),
                    'email'     => trim($_POST['email'] ?? '') ?: null,
                    'role'      => $_POST['role'] ?? 'atc',
                    'centre_id' => trim($_POST['centre_id'] ?? '') ?: null,
                ];
                if (!empty($_POST['password'])) $payload['password'] = $_POST['password'];
                if (empty($payload['username']) || empty($payload['name'])) {
                    echo json_encode(['success' => false, 'message' => 'Username and Name are required.']);
                    exit;
                }
                $result = examApi_request('POST', '/portal-users', $payload);
                echo json_encode($result['success']
                    ? ['success' => true, 'message' => $result['data']['message'] ?? 'Saved.']
                    : ['success' => false, 'message' => $result['error'] ?? 'Failed.']);
                exit;

            case 'delete':
                $username = trim($_POST['username'] ?? '');
                if (!$username) { echo json_encode(['success'=>false,'message'=>'Username required.']); exit; }
                $result = examApi_request('DELETE', '/portal-users/' . urlencode($username));
                echo json_encode($result['success']
                    ? ['success' => true, 'message' => 'Deleted.']
                    : ['success' => false, 'message' => $result['error'] ?? 'Failed.']);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Portal Credentials — Admin | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <style>
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
        box-shadow: 0 4px 14px rgba(79,110,247,.3); transition: all .2s ease;
    }
    .btn-add-user:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(79,110,247,.35); }
    .btn-add-user svg { width: 16px; height: 16px; }
    .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.125rem; margin-bottom: 1.75rem; }
    .kpi-card {
        background: var(--surface); border-radius: var(--r-xl);
        border: 1px solid var(--border); box-shadow: var(--shadow-sm);
        padding: 1.375rem 1.5rem; position: relative; overflow: hidden;
        transition: transform .2s, box-shadow .2s;
    }
    .kpi-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
    .kpi-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; border-radius: var(--r-xl) var(--r-xl) 0 0; }
    .kpi-card.indigo::before { background: linear-gradient(90deg, var(--indigo), #818cf8); }
    .kpi-card.violet::before { background: linear-gradient(90deg, var(--violet), #a78bfa); }
    .kpi-card.sky::before    { background: linear-gradient(90deg, var(--sky), #38bdf8); }
    .kpi-card.emerald::before{ background: linear-gradient(90deg, var(--emerald), #34d399); }
    .kpi-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
    .kpi-icon { width: 40px; height: 40px; border-radius: var(--r-md); display: flex; align-items: center; justify-content: center; }
    .kpi-icon svg { width: 20px; height: 20px; }
    .kpi-icon.indigo { background: var(--indigo-soft); } .kpi-icon.indigo svg { stroke: var(--indigo); }
    .kpi-icon.violet { background: var(--violet-soft); } .kpi-icon.violet svg { stroke: var(--violet); }
    .kpi-icon.sky    { background: var(--sky-soft); }    .kpi-icon.sky svg    { stroke: var(--sky); }
    .kpi-icon.emerald{ background: var(--emerald-soft);} .kpi-icon.emerald svg{ stroke: var(--emerald-dark); }
    .kpi-value { font-size: 1.875rem; font-weight: 800; color: var(--text); letter-spacing: -.04em; line-height: 1; }
    .kpi-label { font-size: .775rem; color: var(--text-3); font-weight: 600; text-transform: uppercase; letter-spacing: .05em; margin-top: .35rem; }
    .toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; gap: 1rem; flex-wrap: wrap; }
    .toolbar-left { display: flex; align-items: center; gap: .75rem; }
    .toolbar-title { font-size: 1.0625rem; font-weight: 800; color: var(--text); letter-spacing: -.02em; }
    .toolbar-count { font-size: .75rem; font-weight: 700; color: var(--text-3); background: var(--surface-raised); border: 1px solid var(--border); padding: .2rem .6rem; border-radius: var(--r-full); }
    .toolbar-right { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; }
    .search-wrap { position: relative; display: flex; align-items: center; }
    .search-wrap svg { position: absolute; left: .875rem; width: 16px; height: 16px; stroke: var(--text-3); pointer-events: none; }
    .search-input { padding: .7rem .875rem .7rem 2.5rem; border: 1.5px solid var(--border); border-radius: var(--r-md); font-size: .875rem; font-family: var(--font); font-weight: 500; background: var(--surface); color: var(--text); outline: none; width: 220px; transition: all .18s; }
    .search-input:focus { border-color: var(--indigo); box-shadow: 0 0 0 3px rgba(79,110,247,.1); }
    .select-sm { padding: .7rem 2.25rem .7rem .875rem; border: 1.5px solid var(--border); border-radius: var(--r-md); font-size: .875rem; font-family: var(--font); font-weight: 500; background: var(--surface); color: var(--text); outline: none; cursor: pointer; appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238896a5' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right .625rem center; background-size: 14px; }
    .select-sm:focus { border-color: var(--indigo); }
    .btn-search { padding: .7rem 1.25rem; background: var(--surface); border: 1.5px solid var(--border); border-radius: var(--r-md); font-size: .875rem; font-weight: 600; font-family: var(--font); color: var(--text-2); cursor: pointer; transition: all .18s; }
    .btn-search:hover { border-color: var(--indigo); color: var(--indigo); background: var(--indigo-soft); }
    .table-wrap { background: var(--surface); border-radius: var(--r-xl); border: 1px solid var(--border); box-shadow: var(--shadow-sm); overflow: hidden; }
    .data-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    .data-table thead { background: var(--surface-raised); }
    .data-table thead th { padding: 1rem 1.25rem; text-align: left; font-size: .72rem; font-weight: 700; color: var(--text-3); text-transform: uppercase; letter-spacing: .06em; border-bottom: 1px solid var(--border); white-space: nowrap; }
    .data-table tbody tr { border-bottom: 1px solid #f3f5f9; transition: background .15s; }
    .data-table tbody tr:last-child { border-bottom: none; }
    .data-table tbody tr:hover { background: #fafbff; }
    .data-table tbody td { padding: 1.1rem 1.25rem; }
    .user-cell { display: flex; align-items: center; gap: .875rem; }
    .user-avatar { width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: .875rem; font-weight: 800; color: white; background: linear-gradient(135deg, var(--indigo), var(--violet)); }
    .user-name { font-size: .9rem; font-weight: 600; color: var(--text); }
    .user-id { font-size: .75rem; color: var(--text-3); font-family: var(--mono, monospace); margin-top: .1rem; }
    .cell-main { font-weight: 600; color: var(--text); }
    .role-badge { display: inline-flex; align-items: center; gap: .35rem; padding: .3rem .75rem; border-radius: var(--r-full); font-size: .75rem; font-weight: 700; white-space: nowrap; }
    .role-badge-dot { width: 6px; height: 6px; border-radius: 50%; }
    .role-admin { background: var(--violet-soft); color: #5b21b6; border: 1px solid #ddd6fe; }
    .role-admin .role-badge-dot { background: var(--violet); }
    .role-dlc { background: var(--sky-soft); color: #0369a1; border: 1px solid #bae6fd; }
    .role-dlc .role-badge-dot { background: var(--sky); }
    .role-atc { background: var(--emerald-soft); color: var(--emerald-dark); border: 1px solid #b3f0de; }
    .role-atc .role-badge-dot { background: var(--emerald); }
    .actions-wrap { display: flex; align-items: center; gap: .375rem; }
    .btn-act { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: var(--r-md); border: 1.5px solid var(--border); background: var(--surface-raised); cursor: pointer; transition: all .18s; }
    .btn-act svg { width: 15px; height: 15px; stroke: var(--text-3); transition: stroke .18s; }
    .btn-act:hover { border-color: var(--indigo); background: var(--indigo-soft); }
    .btn-act:hover svg { stroke: var(--indigo); }
    .btn-act.danger:hover { border-color: #fca5a5; background: var(--rose-soft); }
    .btn-act.danger:hover svg { stroke: var(--rose); }
    .empty-state { text-align: center; padding: 4rem 2rem; }
    .empty-icon { width: 56px; height: 56px; border-radius: var(--r-lg); background: var(--surface-raised); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; }
    .empty-icon svg { width: 26px; height: 26px; stroke: var(--text-3); }
    .empty-title { font-size: .9375rem; font-weight: 700; color: var(--text-2); }
    .empty-sub { font-size: .8125rem; color: var(--text-3); margin-top: .25rem; }
    .confirm-overlay { position: fixed; inset: 0; background: rgba(10,15,30,.55); backdrop-filter: blur(5px); z-index: 2000; display: none; align-items: center; justify-content: center; padding: 1.5rem; font-family: var(--font); }
    .confirm-overlay.active { display: flex; }
    .confirm-card { background: var(--surface); border-radius: var(--r-xl); border: 1px solid var(--border); box-shadow: var(--shadow-lg); width: 100%; max-width: 480px; overflow: hidden; animation: modalSlideIn .25s cubic-bezier(.34,1.56,.64,1); }
    @keyframes modalSlideIn { from { opacity: 0; transform: scale(.95) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
    .toast-container { position: fixed; top: 1.25rem; right: 1.25rem; z-index: 9999; display: flex; flex-direction: column; gap: .625rem; }
    .toast { display: flex; align-items: center; gap: .75rem; padding: .875rem 1.125rem; border-radius: var(--r-lg); background: var(--surface); border: 1px solid var(--border); box-shadow: var(--shadow-lg); min-width: 280px; max-width: 380px; animation: toastIn .3s cubic-bezier(.34,1.56,.64,1); font-size: .875rem; font-weight: 500; }
    .toast.success { border-left: 3px solid var(--emerald); }
    .toast.error { border-left: 3px solid var(--rose); }
    .toast-icon { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .toast.success .toast-icon { background: var(--emerald-soft); }
    .toast.success .toast-icon svg { stroke: var(--emerald-dark); }
    .toast.error .toast-icon { background: var(--rose-soft); }
    .toast.error .toast-icon svg { stroke: var(--rose); }
    .toast-icon svg { width: 16px; height: 16px; }
    .toast-msg { flex: 1; color: var(--text); }
    .toast-close { background: none; border: none; cursor: pointer; color: var(--text-3); padding: .25rem; }
    .toast-close svg { width: 14px; height: 14px; stroke: currentColor; }
    @keyframes toastIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }
    @media (max-width: 900px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 600px) { .kpi-grid { grid-template-columns: 1fr; } .toolbar { flex-direction: column; align-items: stretch; } }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include 'sidebar.php'; ?>
    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <div class="header-greeting">
                    <h2>Exam Portal Credentials</h2>
                    <p>Manage exam portal login accounts</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">

        <!-- Page Header -->
        <div class="page-header-block">
            <div class="page-header-left">
                <div class="page-header-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"/></svg>
                </div>
                <div>
                    <div class="page-header-title">Exam Portal Credentials</div>
                    <div class="page-header-subtitle">Manage login accounts for the Gyanam Exam Portal (Admin, ATC, DLC)</div>
                </div>
            </div>
            <button class="btn-add-user" onclick="openModal()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add User
            </button>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-grid" id="kpi-grid">
            <div class="kpi-card indigo"><div class="kpi-top"><div class="kpi-icon indigo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div></div><div class="kpi-value" id="kpi-total">—</div><div class="kpi-label">Total Users</div></div>
            <div class="kpi-card violet"><div class="kpi-top"><div class="kpi-icon violet"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg></div></div><div class="kpi-value" id="kpi-admin">—</div><div class="kpi-label">Admins</div></div>
            <div class="kpi-card sky"><div class="kpi-top"><div class="kpi-icon sky"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75"/></svg></div></div><div class="kpi-value" id="kpi-atc">—</div><div class="kpi-label">ATC Logins</div></div>
            <div class="kpi-card emerald"><div class="kpi-top"><div class="kpi-icon emerald"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347"/></svg></div></div><div class="kpi-value" id="kpi-dlc">—</div><div class="kpi-label">DLC Logins</div></div>
        </div>

        <!-- Toolbar -->
        <div class="toolbar">
            <div class="toolbar-left">
                <span class="toolbar-title">Portal Users</span>
                <span class="toolbar-count" id="toolbar-count">0</span>
            </div>
            <div class="toolbar-right">
                <div class="search-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" class="search-input" id="search-input" placeholder="Search users...">
                </div>
                <select class="select-sm" id="role-filter">
                    <option value="">All Roles</option>
                    <option value="admin">Admin</option>
                    <option value="atc">ATC</option>
                    <option value="dlc">DLC</option>
                </select>
                <button class="btn-search" onclick="loadUsers()">↻ Refresh</button>
            </div>
        </div>

        <!-- Table -->
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Centre</th>
                        <th>Email</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="users-tbody">
                    <tr><td colspan="6" style="text-align:center;padding:3rem;color:var(--text-3)">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        </div><!-- /.page-content -->
    </main>
</div>
<div class="confirm-overlay" id="user-modal">
    <div class="confirm-card" style="max-width:480px">
        <div style="padding:1.5rem 1.75rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
            <h3 id="modal-title" style="font-size:1.1rem;font-weight:800;color:var(--text)">Add Exam Portal User</h3>
            <button onclick="closeModal()" style="background:none;border:none;cursor:pointer;color:var(--text-3);font-size:1.25rem">&times;</button>
        </div>
        <div style="padding:1.5rem 1.75rem">
            <input type="hidden" id="m-editing">
            <div style="display:grid;gap:1rem">
                <div>
                    <label style="font-size:.8rem;font-weight:700;color:var(--text-2);display:block;margin-bottom:.35rem">Username *</label>
                    <input type="text" id="m-username" class="search-input" style="width:100%;padding-left:.875rem" placeholder="e.g. atc_mumbai">
                </div>
                <div>
                    <label style="font-size:.8rem;font-weight:700;color:var(--text-2);display:block;margin-bottom:.35rem">Full Name *</label>
                    <input type="text" id="m-name" class="search-input" style="width:100%;padding-left:.875rem" placeholder="e.g. Mumbai ATC Centre">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                    <div>
                        <label style="font-size:.8rem;font-weight:700;color:var(--text-2);display:block;margin-bottom:.35rem">Role *</label>
                        <select id="m-role" class="select-sm" style="width:100%">
                            <option value="atc">ATC</option>
                            <option value="dlc">DLC</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:.8rem;font-weight:700;color:var(--text-2);display:block;margin-bottom:.35rem">Centre ID</label>
                        <input type="text" id="m-centre" class="search-input" style="width:100%;padding-left:.875rem" placeholder="e.g. ATC1">
                    </div>
                </div>
                <div>
                    <label style="font-size:.8rem;font-weight:700;color:var(--text-2);display:block;margin-bottom:.35rem">Email (optional)</label>
                    <input type="email" id="m-email" class="search-input" style="width:100%;padding-left:.875rem" placeholder="user@example.com">
                </div>
                <div>
                    <label style="font-size:.8rem;font-weight:700;color:var(--text-2);display:block;margin-bottom:.35rem" id="m-pass-label">Password *</label>
                    <input type="password" id="m-password" class="search-input" style="width:100%;padding-left:.875rem" placeholder="Min 4 characters">
                </div>
            </div>
            <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:1.5rem">
                <button onclick="closeModal()" class="btn-search">Cancel</button>
                <button onclick="saveUser()" class="btn-add-user" id="m-save-btn">Save User</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container" id="toast-container"></div>

<script>
let allUsers = [];

async function loadUsers() {
    const form = new FormData();
    form.append('action', 'list');
    try {
        const res = await fetch(window.location.href, { method: 'POST', body: form });
        const data = await res.json();
        if (data.success) {
            allUsers = data.users || [];
            renderTable();
            updateKPIs();
        } else {
            showToast(data.message || 'Failed to load users', 'error');
        }
    } catch (e) {
        showToast('Network error: ' + e.message, 'error');
    }
}

function renderTable() {
    const search = document.getElementById('search-input').value.toLowerCase();
    const roleF = document.getElementById('role-filter').value;

    const filtered = allUsers.filter(u => {
        const matchSearch = !search || u.username.toLowerCase().includes(search) || u.name.toLowerCase().includes(search) || (u.centre_id || '').toLowerCase().includes(search);
        const matchRole = !roleF || u.role === roleF;
        return matchSearch && matchRole;
    });

    document.getElementById('toolbar-count').textContent = filtered.length;
    const tbody = document.getElementById('users-tbody');

    if (filtered.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="empty-state"><div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div><div class="empty-title">No users found</div><div class="empty-sub">Try adjusting your search or filters</div></td></tr>';
        return;
    }

    tbody.innerHTML = filtered.map(u => {
        const initial = (u.name || u.username || '?').charAt(0).toUpperCase();
        const roleClass = u.role === 'admin' ? 'role-admin' : u.role === 'atc' ? 'role-atc' : 'role-dlc';
        const roleLabel = u.role.toUpperCase();
        const created = u.created_at ? new Date(u.created_at).toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'}) : '—';
        return `<tr>
            <td><div class="user-cell"><div class="user-avatar">${initial}</div><div><div class="user-name">${u.name}</div><div class="user-id">${u.username}</div></div></div></td>
            <td><span class="role-badge ${roleClass}"><span class="role-badge-dot"></span>${roleLabel}</span></td>
            <td><span class="cell-main">${u.centre_id || '—'}</span></td>
            <td><span style="font-size:.85rem;color:var(--text-2)">${u.email || '—'}</span></td>
            <td><span style="font-size:.82rem;color:var(--text-3)">${created}</span></td>
            <td><div class="actions-wrap">
                <button class="btn-act" title="Edit" onclick='editUser(${JSON.stringify(u)})'><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>
                ${u.role !== 'admin' ? `<button class="btn-act danger" title="Delete" onclick="deleteUser('${u.username}')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>` : ''}
            </div></td>
        </tr>`;
    }).join('');
}

function updateKPIs() {
    document.getElementById('kpi-total').textContent = allUsers.length;
    document.getElementById('kpi-admin').textContent = allUsers.filter(u => u.role === 'admin').length;
    document.getElementById('kpi-atc').textContent = allUsers.filter(u => u.role === 'atc').length;
    document.getElementById('kpi-dlc').textContent = allUsers.filter(u => u.role === 'dlc').length;
}

// Modal
function openModal(user = null) {
    const isEdit = !!user;
    document.getElementById('modal-title').textContent = isEdit ? 'Edit Exam Portal User' : 'Add Exam Portal User';
    document.getElementById('m-editing').value = isEdit ? '1' : '';
    document.getElementById('m-username').value = user?.username || '';
    document.getElementById('m-username').readOnly = isEdit;
    document.getElementById('m-username').style.background = isEdit ? '#f0f2f7' : '';
    document.getElementById('m-name').value = user?.name || '';
    document.getElementById('m-role').value = user?.role || 'atc';
    document.getElementById('m-centre').value = user?.centre_id || '';
    document.getElementById('m-email').value = user?.email || '';
    document.getElementById('m-password').value = '';
    document.getElementById('m-pass-label').textContent = isEdit ? 'New Password (leave blank to keep)' : 'Password *';
    document.getElementById('m-save-btn').textContent = isEdit ? 'Save Changes' : 'Create User';
    document.getElementById('user-modal').classList.add('active');
}

function closeModal() {
    document.getElementById('user-modal').classList.remove('active');
}

function editUser(u) { openModal(u); }

async function saveUser() {
    const isEdit = document.getElementById('m-editing').value === '1';
    const username = document.getElementById('m-username').value.trim();
    const name = document.getElementById('m-name').value.trim();
    const password = document.getElementById('m-password').value;

    if (!username || !name) { showToast('Username and Name are required.', 'error'); return; }
    if (!isEdit && (!password || password.length < 4)) { showToast('Password must be at least 4 characters.', 'error'); return; }

    const form = new FormData();
    form.append('action', 'save');
    form.append('username', username);
    form.append('name', name);
    form.append('role', document.getElementById('m-role').value);
    form.append('centre_id', document.getElementById('m-centre').value.trim());
    form.append('email', document.getElementById('m-email').value.trim());
    if (password) form.append('password', password);

    const btn = document.getElementById('m-save-btn');
    btn.disabled = true; btn.textContent = 'Saving...';

    try {
        const res = await fetch(window.location.href, { method: 'POST', body: form });
        const data = await res.json();
        if (data.success) {
            showToast(data.message, 'success');
            closeModal();
            loadUsers();
        } else {
            showToast(data.message || 'Failed to save.', 'error');
        }
    } catch (e) {
        showToast('Network error: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = isEdit ? 'Save Changes' : 'Create User';
    }
}

async function deleteUser(username) {
    if (!confirm('Delete user "' + username + '" from the Exam Portal?\n\nThis will revoke their exam portal access.')) return;

    const form = new FormData();
    form.append('action', 'delete');
    form.append('username', username);

    try {
        const res = await fetch(window.location.href, { method: 'POST', body: form });
        const data = await res.json();
        if (data.success) { showToast('User deleted.', 'success'); loadUsers(); }
        else { showToast(data.message || 'Failed.', 'error'); }
    } catch (e) { showToast('Network error: ' + e.message, 'error'); }
}

function showToast(msg, type = 'success') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.innerHTML = `<div class="toast-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${type === 'success' ? '<path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>' : '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>'}</svg></div><span class="toast-msg">${msg}</span><button class="toast-close" onclick="this.parentElement.remove()"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>`;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 4500);
}

// Filters
document.getElementById('search-input').addEventListener('input', renderTable);
document.getElementById('role-filter').addEventListener('change', renderTable);

// Load on page ready
loadUsers();
</script>
</body>
</html>
