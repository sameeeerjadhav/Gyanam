<?php
/**
 * Gyanam Portal — Admin: DLC Offices Management
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo      = getDBConnection();
$userName = sanitize(getUserName());

/* ══════════════════════════════════════
   AJAX HANDLER
══════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        switch ($_POST['action']) {

            case 'add':
                $stmt = $pdo->prepare("
                    INSERT INTO dlc_offices (name, district, taluka, pin_code, state, contact_person, email, mobile, address, dob, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    trim($_POST['name']),         trim($_POST['district']),
                    trim($_POST['taluka'] ?? ''), trim($_POST['pin_code'] ?? ''),
                    $_POST['state'],              trim($_POST['contact_person'] ?? ''),
                    trim($_POST['email'] ?? ''),  trim($_POST['mobile'] ?? ''),
                    trim($_POST['address'] ?? ''), !empty($_POST['dob']) ? $_POST['dob'] : null,
                    $_POST['status'],
                ]);
                echo json_encode(['success' => true, 'message' => 'DLC Office added successfully']);
                exit;

            case 'edit':
                $stmt = $pdo->prepare("
                    UPDATE dlc_offices
                    SET name=?, district=?, taluka=?, pin_code=?, state=?, contact_person=?, email=?, mobile=?, address=?, dob=?, status=?
                    WHERE id=?
                ");
                $stmt->execute([
                    trim($_POST['name']),         trim($_POST['district']),
                    trim($_POST['taluka'] ?? ''), trim($_POST['pin_code'] ?? ''),
                    $_POST['state'],              trim($_POST['contact_person'] ?? ''),
                    trim($_POST['email'] ?? ''),  trim($_POST['mobile'] ?? ''),
                    trim($_POST['address'] ?? ''), !empty($_POST['dob']) ? $_POST['dob'] : null,
                    $_POST['status'],
                    (int)$_POST['id'],
                ]);
                echo json_encode(['success' => true, 'message' => 'DLC Office updated successfully']);
                exit;

            case 'delete':
                $pdo->prepare("DELETE FROM dlc_offices WHERE id=?")->execute([(int)$_POST['id']]);
                echo json_encode(['success' => true, 'message' => 'DLC Office deleted successfully']);
                exit;

            case 'get':
                $stmt = $pdo->prepare("SELECT * FROM dlc_offices WHERE id=?");
                $stmt->execute([(int)$_POST['id']]);
                echo json_encode(['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC)]);
                exit;

            case 'create_user':
                $stmt = $pdo->prepare("SELECT id FROM users WHERE dlc_id=? AND role='DLC Office'");
                $stmt->execute([(int)$_POST['dlc_id']]);
                if ($stmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'A user already exists for this DLC office']);
                    exit;
                }
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username=?");
                $stmt->execute([trim($_POST['username'])]);
                if ($stmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Username already taken. Please choose another.']);
                    exit;
                }
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, password, role, name, email, mobile, dlc_id, status)
                    VALUES (?, ?, 'DLC Office', ?, ?, ?, ?, 'Active')
                ");
                $stmt->execute([
                    trim($_POST['username']),
                    password_hash($_POST['password'], PASSWORD_DEFAULT),
                    trim($_POST['name']),
                    trim($_POST['email']   ?? ''),
                    trim($_POST['mobile']  ?? ''),
                    (int)$_POST['dlc_id'],
                ]);
                echo json_encode(['success' => true, 'message' => 'User created successfully']);
                exit;

            case 'get_dlc_user':
                $stmt = $pdo->prepare("SELECT id, username, name, email, mobile, status FROM users WHERE dlc_id=? AND role='DLC Office'");
                $stmt->execute([(int)$_POST['dlc_id']]);
                echo json_encode(['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC)]);
                exit;

            case 'change_password':
                $uid = (int)($_POST['user_id'] ?? 0);
                $np  = $_POST['new_password'] ?? '';
                if ($uid <= 0 || strlen($np) < 6) {
                    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
                    exit;
                }
                $pdo->prepare("UPDATE users SET password=? WHERE id=? AND role='DLC Office'")
                    ->execute([password_hash($np, PASSWORD_DEFAULT), $uid]);
                echo json_encode(['success' => true, 'message' => 'Password updated successfully!']);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

/* ══════════════════════════════════════
   PAGE DATA
══════════════════════════════════════ */
$stmt = $pdo->query("
    SELECT d.*, COUNT(a.id) AS atc_count
    FROM dlc_offices d
    LEFT JOIN atc_centers a ON d.id = a.dlc_id
    GROUP BY d.id
    ORDER BY d.created_at DESC
");
$dlcOffices   = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalCount   = count($dlcOffices);
$activeCount  = count(array_filter($dlcOffices, fn($d) => $d['status'] === 'Active'));
$inactiveCount= $totalCount - $activeCount;
$totalATC     = array_sum(array_column($dlcOffices, 'atc_count'));

$pageUrl = strtok($_SERVER['REQUEST_URI'], '?');

$states = ['Maharashtra','Gujarat','Karnataka','Delhi','Rajasthan','Uttar Pradesh',
           'Madhya Pradesh','Tamil Nadu','West Bengal','Telangana','Andhra Pradesh',
           'Kerala','Punjab','Haryana','Bihar','Odisha','Jharkhand','Chhattisgarh','Assam','Other'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DLC Offices — Admin | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📚</text></svg>">
    <style>
    /* ═══════════════════════════════════════════
       DESIGN TOKENS
    ═══════════════════════════════════════════ */
    :root {
        --font:        'Sora', sans-serif;
        --mono:        'JetBrains Mono', monospace;
        --bg:          #f4f6fb;
        --surface:     #ffffff;
        --surface-2:   #f9fafc;
        --surface-3:   #f0f3f9;
        --border:      #e6eaf3;
        --border-2:    #d4dae8;
        --text:        #111827;
        --text-2:      #374151;
        --text-3:      #6b7280;
        --text-4:      #9ca3af;
        --brand:       #4361ee;
        --brand-dark:  #3451d1;
        --brand-light: #eef1fd;
        --brand-glow:  rgba(67,97,238,.18);
        --violet:      #7c3aed;
        --violet-soft: #f5f3ff;
        --sky:         #0ea5e9;
        --sky-soft:    #f0f9ff;
        --emerald:     #10b981;
        --emerald-dark:#059669;
        --emerald-soft:#ecfdf5;
        --amber:       #f59e0b;
        --amber-dark:  #d97706;
        --amber-soft:  #fffbeb;
        --rose:        #f43f5e;
        --rose-soft:   #fff1f3;
        --shadow-xs:   0 1px 2px rgba(0,0,0,.05);
        --shadow-sm:   0 1px 4px rgba(0,0,0,.06), 0 2px 8px rgba(0,0,0,.04);
        --shadow-md:   0 4px 16px rgba(0,0,0,.08), 0 2px 6px rgba(0,0,0,.04);
        --shadow-lg:   0 20px 60px rgba(0,0,0,.12), 0 8px 20px rgba(0,0,0,.06);
        --shadow-brand:0 6px 20px var(--brand-glow);
        --r-xs:4px; --r-sm:6px; --r-md:10px;
        --r-lg:14px; --r-xl:18px; --r-2xl:24px; --r-full:9999px;
        --t:.18s ease;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: var(--font); background: var(--bg); color: var(--text); line-height: 1.5; -webkit-font-smoothing: antialiased; }
    .page-content { padding: 1.75rem 2rem; }

    /* ── Page header ── */
    .page-header-block { display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:1.75rem; }
    .page-header-left  { display:flex; align-items:center; gap:1rem; }
    .page-header-icon  {
        width:50px; height:50px; border-radius:var(--r-lg);
        background:linear-gradient(135deg,var(--brand),var(--violet));
        display:flex; align-items:center; justify-content:center;
        box-shadow:var(--shadow-brand); flex-shrink:0;
    }
    .page-header-icon svg    { width:24px; height:24px; stroke:white; fill:none; }
    .page-header-title       { font-size:1.375rem; font-weight:800; color:var(--text); letter-spacing:-.03em; }
    .page-header-subtitle    { font-size:.8125rem; color:var(--text-3); margin-top:.2rem; }
    .btn-add-dlc {
        display:inline-flex; align-items:center; gap:.5rem; padding:.7rem 1.375rem;
        background:linear-gradient(135deg,var(--brand),var(--brand-dark));
        border:none; border-radius:var(--r-md); color:#fff;
        font-size:.875rem; font-weight:700; font-family:var(--font);
        cursor:pointer; white-space:nowrap;
        box-shadow:var(--shadow-brand), inset 0 1px 0 rgba(255,255,255,.15);
        transition:transform var(--t), box-shadow var(--t);
    }
    .btn-add-dlc:hover  { transform:translateY(-2px); box-shadow:0 10px 28px var(--brand-glow); }
    .btn-add-dlc:active { transform:translateY(0); }
    .btn-add-dlc svg    { width:16px; height:16px; stroke:white; fill:none; }

    /* ── KPI Grid ── */
    .kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; margin-bottom:1.75rem; }
    .kpi-card {
        background:var(--surface); border:1px solid var(--border);
        border-radius:var(--r-xl); padding:1.375rem 1.5rem 1.25rem;
        position:relative; overflow:hidden;
        box-shadow:var(--shadow-sm); transition:transform var(--t), box-shadow var(--t); cursor:default;
    }
    .kpi-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); }
    .kpi-card::before { content:''; position:absolute; top:0; left:0; bottom:0; width:4px; border-radius:var(--r-xl) 0 0 var(--r-xl); }
    .kpi-card::after  { content:''; position:absolute; right:-20px; top:-20px; width:90px; height:90px; border-radius:50%; opacity:.055; pointer-events:none; }
    .kpi-card.brand::before   { background:linear-gradient(180deg,var(--brand),#818cf8); }
    .kpi-card.emerald::before { background:linear-gradient(180deg,var(--emerald),#34d399); }
    .kpi-card.amber::before   { background:linear-gradient(180deg,var(--amber),#fcd34d); }
    .kpi-card.sky::before     { background:linear-gradient(180deg,var(--sky),#38bdf8); }
    .kpi-card.brand::after    { background:var(--brand);   }
    .kpi-card.emerald::after  { background:var(--emerald); }
    .kpi-card.amber::after    { background:var(--amber);   }
    .kpi-card.sky::after      { background:var(--sky);     }
    .kpi-top   { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:.875rem; }
    .kpi-icon  { width:42px; height:42px; border-radius:var(--r-md); display:flex; align-items:center; justify-content:center; }
    .kpi-icon svg { width:20px; height:20px; fill:none; }
    .kpi-icon.brand   { background:var(--brand-light); }  .kpi-icon.brand   svg { stroke:var(--brand); }
    .kpi-icon.emerald { background:var(--emerald-soft); } .kpi-icon.emerald svg { stroke:var(--emerald-dark); }
    .kpi-icon.amber   { background:var(--amber-soft);  }  .kpi-icon.amber   svg { stroke:var(--amber-dark); }
    .kpi-icon.sky     { background:var(--sky-soft);    }  .kpi-icon.sky     svg { stroke:var(--sky); }
    .kpi-value { font-size:2rem; font-weight:800; color:var(--text); letter-spacing:-.05em; line-height:1; }
    .kpi-label { font-size:.72rem; font-weight:600; color:var(--text-3); text-transform:uppercase; letter-spacing:.07em; margin-top:.375rem; }

    /* ── Toolbar ── */
    .toolbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; gap:1rem; flex-wrap:wrap; }
    .toolbar-left  { display:flex; align-items:center; gap:.625rem; }
    .toolbar-title { font-size:1rem; font-weight:700; color:var(--text); letter-spacing:-.02em; }
    .toolbar-count { font-size:.72rem; font-weight:700; color:var(--text-4); background:var(--surface-3); border:1px solid var(--border); padding:.175rem .55rem; border-radius:var(--r-full); }
    .toolbar-right { display:flex; align-items:center; gap:.625rem; }
    .search-wrap  { position:relative; display:flex; align-items:center; }
    .search-icon  { position:absolute; left:.875rem; width:15px; height:15px; stroke:var(--text-4); fill:none; pointer-events:none; }
    .search-input {
        padding:.65rem .875rem .65rem 2.4rem; border:1.5px solid var(--border); border-radius:var(--r-md);
        font-size:.85rem; font-family:var(--font); font-weight:500;
        background:var(--surface); color:var(--text); outline:none;
        width:230px; transition:border-color var(--t), box-shadow var(--t);
    }
    .search-input:focus { border-color:var(--brand); box-shadow:0 0 0 3px var(--brand-glow); }
    .search-input::placeholder { color:var(--text-4); }

    /* ── Table ── */
    .table-wrap {
        background:var(--surface); border:1px solid var(--border);
        border-radius:var(--r-xl); box-shadow:var(--shadow-sm); overflow:hidden;
    }
    .data-table { width:100%; border-collapse:collapse; font-size:.875rem; }
    .data-table thead { background:var(--surface-2); border-bottom:1px solid var(--border); }
    .data-table thead th {
        padding:.875rem 1.25rem; text-align:left;
        font-size:.7rem; font-weight:700; color:var(--text-4);
        text-transform:uppercase; letter-spacing:.07em; white-space:nowrap;
    }
    .data-table tbody tr { border-bottom:1px solid var(--surface-3); transition:background var(--t); }
    .data-table tbody tr:last-child { border-bottom:none; }
    .data-table tbody tr:hover { background:#fafbff; }
    .data-table tbody td { padding:.9375rem 1.25rem; vertical-align:middle; }

    /* Office cell */
    .office-cell   { display:flex; align-items:center; gap:.875rem; }
    .office-avatar {
        width:38px; height:38px; border-radius:var(--r-md); flex-shrink:0;
        display:flex; align-items:center; justify-content:center;
        font-size:.875rem; font-weight:800; color:white;
        background:linear-gradient(135deg,var(--brand),var(--violet));
        box-shadow:0 2px 6px var(--brand-glow);
    }
    .office-name  { font-size:.875rem; font-weight:700; color:var(--text); }
    .office-state { font-size:.775rem; color:var(--text-3); margin-top:.15rem; }

    .cell-main { font-weight:600; color:var(--text); font-size:.875rem; }
    .cell-sub  { font-size:.775rem; color:var(--text-3); margin-top:.15rem; }

    /* ATC count pill */
    .atc-pill {
        display:inline-flex; align-items:center; gap:.35rem;
        padding:.3rem .75rem; border-radius:var(--r-full);
        font-size:.75rem; font-weight:700;
        background:var(--sky-soft); color:#0369a1; border:1px solid #bae6fd;
    }
    .atc-pill svg { width:12px; height:12px; stroke:#0369a1; fill:none; }

    /* Status badges */
    .status-badge {
        display:inline-flex; align-items:center; gap:.35rem;
        padding:.3rem .75rem; border-radius:var(--r-full);
        font-size:.72rem; font-weight:700; white-space:nowrap;
    }
    .status-dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
    .s-active   { background:var(--emerald-soft); color:var(--emerald-dark); border:1px solid #a7f3d0; }
    .s-active   .status-dot { background:var(--emerald); }
    .s-inactive { background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0; }
    .s-inactive .status-dot { background:#94a3b8; }

    /* Action buttons */
    .actions-wrap { display:flex; align-items:center; gap:.375rem; }
    .btn-act {
        width:32px; height:32px; display:flex; align-items:center; justify-content:center;
        border-radius:var(--r-md); border:1.5px solid var(--border);
        background:var(--surface-2); cursor:pointer; transition:all var(--t);
    }
    .btn-act svg { width:14px; height:14px; stroke:var(--text-3); fill:none; }
    .btn-act:hover           { border-color:var(--brand);  background:var(--brand-light);  }
    .btn-act:hover svg       { stroke:var(--brand); }
    .btn-act.user-btn:hover  { border-color:#a7f3d0;        background:var(--emerald-soft); }
    .btn-act.user-btn:hover svg { stroke:var(--emerald-dark); }
    .btn-act.danger:hover    { border-color:#fca5a5;        background:var(--rose-soft);    }
    .btn-act.danger:hover svg { stroke:var(--rose); }

    /* Empty state */
    .empty-state { text-align:center; padding:4.5rem 2rem; }
    .empty-icon  { width:56px; height:56px; border-radius:var(--r-lg); background:var(--surface-2); border:1px solid var(--border); display:flex; align-items:center; justify-content:center; margin:0 auto 1.125rem; }
    .empty-icon svg { width:26px; height:26px; stroke:var(--text-4); fill:none; }
    .empty-title { font-size:.9375rem; font-weight:700; color:var(--text-2); }
    .empty-sub   { font-size:.8125rem; color:var(--text-3); margin-top:.3rem; }

    /* ── Toast ── */
    .toast-container { position:fixed; top:1.25rem; right:1.25rem; z-index:9999; display:flex; flex-direction:column; gap:.5rem; }
    .toast {
        display:flex; align-items:center; gap:.75rem; padding:.875rem 1rem;
        border-radius:var(--r-lg); background:var(--surface); border:1px solid var(--border);
        box-shadow:var(--shadow-lg); min-width:280px; max-width:360px;
        font-size:.875rem; font-weight:500; font-family:var(--font);
        animation:toastIn .3s cubic-bezier(.34,1.56,.64,1);
    }
    .toast.success { border-left:3px solid var(--emerald); }
    .toast.error   { border-left:3px solid var(--rose); }
    .toast-icon { width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .toast.success .toast-icon { background:var(--emerald-soft); } .toast.success .toast-icon svg { stroke:var(--emerald-dark); }
    .toast.error   .toast-icon { background:var(--rose-soft);    } .toast.error   .toast-icon svg { stroke:var(--rose); }
    .toast-icon svg { width:14px; height:14px; fill:none; }
    .toast-msg  { flex:1; color:var(--text); line-height:1.4; }
    .toast-close { background:none; border:none; cursor:pointer; color:var(--text-4); padding:.2rem; display:flex; align-items:center; }
    .toast-close svg { width:13px; height:13px; stroke:currentColor; fill:none; }
    @keyframes toastIn { from { opacity:0; transform:translateX(16px); } to { opacity:1; transform:translateX(0); } }

    /* ══ MODAL BASE ══ */
    .modal-overlay {
        position:fixed; inset:0;
        background:rgba(8,12,28,.52); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
        z-index:1000; display:none; align-items:center; justify-content:center;
        padding:1.5rem; font-family:var(--font);
    }
    .modal-overlay.active { display:flex; }
    .modal-panel {
        background:var(--surface); border-radius:var(--r-2xl);
        border:1px solid var(--border); box-shadow:var(--shadow-lg);
        width:100%; max-height:90vh;
        display:flex; flex-direction:column;
        animation:slideUp .28s cubic-bezier(.34,1.56,.64,1); overflow:hidden;
    }
    @keyframes slideUp { from { opacity:0; transform:scale(.94) translateY(14px); } to { opacity:1; transform:scale(1) translateY(0); } }

    .modal-header {
        display:flex; align-items:center; justify-content:space-between;
        padding:1.25rem 1.75rem; border-bottom:1px solid var(--border);
        background:var(--surface-2); flex-shrink:0;
    }
    .modal-header-left { display:flex; align-items:center; gap:.875rem; }
    .modal-icon { width:42px; height:42px; border-radius:var(--r-md); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .modal-icon svg { width:20px; height:20px; fill:none; }
    .modal-icon.brand   { background:var(--brand-light); }  .modal-icon.brand   svg { stroke:var(--brand); }
    .modal-icon.emerald { background:var(--emerald-soft); } .modal-icon.emerald svg { stroke:var(--emerald-dark); }
    .modal-title    { font-size:1rem; font-weight:800; color:var(--text); letter-spacing:-.025em; line-height:1.25; }
    .modal-subtitle { font-size:.785rem; color:var(--text-3); margin-top:.175rem; }
    .btn-close {
        width:34px; height:34px; display:flex; align-items:center; justify-content:center;
        background:transparent; border:1.5px solid var(--border); border-radius:var(--r-md);
        cursor:pointer; transition:all var(--t); flex-shrink:0;
    }
    .btn-close svg { width:14px; height:14px; stroke:var(--text-3); fill:none; }
    .btn-close:hover { background:var(--rose-soft); border-color:#fca5a5; }
    .btn-close:hover svg { stroke:var(--rose); }

    .modal-body   { 
        padding:1.625rem 1.75rem; 
        overflow-y:auto; 
        flex:1; 
        max-height: calc(90vh - 200px);
    }
    
    /* Custom scrollbar for modal body */
    .modal-body::-webkit-scrollbar {
        width: 8px;
    }
    
    .modal-body::-webkit-scrollbar-track {
        background: var(--surface-2);
        border-radius: 10px;
    }
    
    .modal-body::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 10px;
        transition: background 0.2s;
    }
    
    .modal-body::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }
    .modal-footer {
        display:flex; align-items:center; justify-content:flex-end; gap:.75rem;
        padding:1.125rem 1.75rem; border-top:1px solid var(--border);
        background:var(--surface-2); flex-shrink:0;
    }
    .modal-btn {
        display:inline-flex; align-items:center; gap:.4rem;
        padding:.72rem 1.375rem; border-radius:var(--r-md);
        font-size:.875rem; font-weight:700; font-family:var(--font);
        cursor:pointer; border:none; transition:all var(--t); white-space:nowrap;
    }
    .modal-btn svg { width:14px; height:14px; stroke:currentColor; fill:none; }
    .modal-btn-ghost   { background:var(--surface); border:1.5px solid var(--border) !important; color:var(--text-2); }
    .modal-btn-ghost:hover { background:var(--surface-3); }
    .modal-btn-primary { background:linear-gradient(135deg,var(--brand),var(--brand-dark)); color:white; box-shadow:var(--shadow-brand); }
    .modal-btn-primary:hover { transform:translateY(-1px); box-shadow:0 10px 24px var(--brand-glow); }
    .modal-btn-primary:disabled { opacity:.6; cursor:not-allowed; transform:none !important; }
    .modal-btn-emerald { background:linear-gradient(135deg,var(--emerald),var(--emerald-dark)); color:white; box-shadow:0 4px 14px rgba(16,185,129,.25); }
    .modal-btn-emerald:hover { transform:translateY(-1px); box-shadow:0 8px 22px rgba(16,185,129,.35); }
    .modal-btn-emerald:disabled { opacity:.6; cursor:not-allowed; transform:none !important; }

    /* ── Form inside modals ── */
    .form-section { margin-bottom:1.75rem; }
    .form-section:last-child { margin-bottom:0; }
    .form-section-title {
        display:flex; align-items:center; gap:.5rem;
        font-size:.75rem; font-weight:700; color:var(--text-3);
        text-transform:uppercase; letter-spacing:.07em;
        margin-bottom:1.125rem; padding-bottom:.75rem;
        border-bottom:1px solid var(--border);
    }
    .form-section-title svg { width:13px; height:13px; stroke:var(--brand); fill:none; flex-shrink:0; }
    .form-grid   { display:grid; grid-template-columns:1fr 1fr; gap:1.125rem; }
    .form-field  { display:flex; flex-direction:column; }
    .form-field.span-2 { grid-column:1/-1; }
    .field-label { display:block; font-size:.8rem; font-weight:700; color:var(--text-2); margin-bottom:.5rem; }
    .required-star { color:var(--rose); margin-left:.15rem; }
    .field-hint  { font-size:.75rem; color:var(--text-4); margin-top:.375rem; display:flex; align-items:flex-start; gap:.35rem; line-height:1.4; }
    .field-hint svg { width:12px; height:12px; flex-shrink:0; margin-top:.1rem; stroke:var(--text-4); fill:none; }

    .input-wrap { position:relative; display:flex; align-items:center; }
    .input-icon { position:absolute; left:.875rem; width:16px; height:16px; display:flex; align-items:center; justify-content:center; pointer-events:none; z-index:1; }
    .input-icon svg { width:15px; height:15px; stroke:var(--text-4); fill:none; }
    .input-wrap input, .input-wrap select, .input-wrap textarea {
        width:100%; padding:.75rem .875rem .75rem 2.625rem;
        border:1.5px solid var(--border); border-radius:var(--r-md);
        font-size:.875rem; font-family:var(--font); font-weight:500;
        background:var(--surface); color:var(--text); outline:none;
        transition:border-color var(--t), box-shadow var(--t);
    }
    .input-wrap input:focus, .input-wrap select:focus, .input-wrap textarea:focus {
        border-color:var(--brand); box-shadow:0 0 0 3px var(--brand-glow);
    }
    .input-wrap input::placeholder, .input-wrap textarea::placeholder { color:var(--text-4); font-weight:400; }
    .select-wrap select {
        appearance:none; -webkit-appearance:none; cursor:pointer;
        background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
        background-repeat:no-repeat; background-position:right .875rem center; background-size:13px; padding-right:2.5rem;
    }
    /* Standalone textarea (no icon wrap) */
    .form-field > textarea {
        padding:.75rem 1rem; border:1.5px solid var(--border); border-radius:var(--r-md);
        font-size:.875rem; font-family:var(--font); font-weight:500;
        background:var(--surface); color:var(--text); outline:none;
        resize:vertical; min-height:80px; transition:border-color var(--t), box-shadow var(--t);
    }
    .form-field > textarea:focus { border-color:var(--brand); box-shadow:0 0 0 3px var(--brand-glow); }
    .form-field > textarea::placeholder { color:var(--text-4); font-weight:400; }

    /* ── DLC name banner in user modal ── */
    .dlc-banner {
        display:flex; align-items:center; gap:1rem;
        padding:1.125rem 1.25rem; margin-bottom:1.5rem;
        background:linear-gradient(135deg,var(--brand-light),#e8ecfe);
        border:1.5px solid #c7d2fe; border-radius:var(--r-lg);
    }
    .dlc-banner-icon {
        width:42px; height:42px; border-radius:var(--r-md);
        background:linear-gradient(135deg,var(--brand),var(--violet));
        display:flex; align-items:center; justify-content:center;
        box-shadow:var(--shadow-brand); flex-shrink:0;
    }
    .dlc-banner-icon svg { width:20px; height:20px; stroke:white; fill:none; }
    .dlc-banner-label { font-size:.72rem; font-weight:700; color:var(--brand); text-transform:uppercase; letter-spacing:.06em; }
    .dlc-banner-name  { font-size:.9375rem; font-weight:700; color:var(--text); margin-top:.15rem; }

    /* ── Existing user info card ── */
    .existing-user-card {
        padding:1.25rem; background:var(--surface-2);
        border:1px solid var(--border); border-radius:var(--r-lg);
        margin-bottom:1.5rem;
    }
    .existing-user-header {
        display:flex; align-items:center; gap:.75rem; margin-bottom:1rem; padding-bottom:.875rem; border-bottom:1px solid var(--border);
    }
    .existing-user-avatar {
        width:42px; height:42px; border-radius:50%;
        background:linear-gradient(135deg,var(--emerald),var(--emerald-dark));
        color:white; display:flex; align-items:center; justify-content:center;
        font-size:1.1rem; font-weight:800; flex-shrink:0;
        box-shadow:0 4px 12px rgba(16,185,129,.25);
    }
    .existing-user-title { font-size:.9375rem; font-weight:700; color:var(--text); }
    .existing-user-note  { font-size:.775rem; color:var(--text-3); margin-top:.1rem; }
    .existing-user-grid  { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
    .existing-user-item  { display:flex; flex-direction:column; gap:.2rem; }
    .existing-user-label { font-size:.7rem; font-weight:700; color:var(--text-4); text-transform:uppercase; letter-spacing:.06em; }
    .existing-user-value { font-size:.875rem; font-weight:600; color:var(--text-2); }
    .existing-user-value.mono { font-family:var(--mono); color:var(--text); }

    /* ── Delete confirm overlay ── */
    .confirm-overlay {
        position:fixed; inset:0;
        background:rgba(8,12,28,.52); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
        z-index:2000; display:none; align-items:center; justify-content:center;
        padding:1.5rem; font-family:var(--font);
    }
    .confirm-overlay.active { display:flex; }
    .confirm-card {
        background:var(--surface); border-radius:var(--r-2xl); border:1px solid var(--border);
        box-shadow:var(--shadow-lg); width:100%; max-width:420px; overflow:hidden;
        animation:slideUp .28s cubic-bezier(.34,1.56,.64,1);
    }
    .confirm-body { padding:2rem 2rem 1.5rem; text-align:center; }
    .confirm-icon-wrap {
        width:58px; height:58px; border-radius:50%;
        background:var(--rose-soft); border:2px solid #fecdd3;
        display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem;
    }
    .confirm-icon-wrap svg { width:26px; height:26px; stroke:var(--rose); fill:none; }
    .confirm-title  { font-size:1.0625rem; font-weight:800; color:var(--text); margin-bottom:.5rem; }
    .confirm-msg    { font-size:.875rem; color:var(--text-2); line-height:1.6; }
    .confirm-name   { font-weight:700; color:var(--text); font-family:var(--mono); }
    .confirm-warn   { margin-top:.875rem; font-size:.8rem; color:var(--rose); background:var(--rose-soft); border:1px solid #fecdd3; border-radius:var(--r-md); padding:.5rem .875rem; display:inline-block; }
    .confirm-footer { display:flex; gap:.75rem; padding:1.125rem 1.75rem; border-top:1px solid var(--border); background:var(--surface-2); }
    .confirm-footer button { flex:1; padding:.75rem; border-radius:var(--r-md); font-size:.875rem; font-weight:700; font-family:var(--font); cursor:pointer; border:none; transition:all var(--t); }
    .btn-keep-dlc { background:var(--surface); border:1.5px solid var(--border) !important; color:var(--text-2); }
    .btn-keep-dlc:hover { background:var(--surface-3); }
    .btn-del-dlc  { background:linear-gradient(135deg,var(--rose),#e11d48); color:white; box-shadow:0 4px 14px rgba(244,63,94,.28); }
    .btn-del-dlc:hover { transform:translateY(-1px); box-shadow:0 8px 22px rgba(244,63,94,.35); }
    .btn-del-dlc:disabled { opacity:.6; cursor:not-allowed; transform:none; }

    /* ── Responsive ── */
    @media (max-width:1280px) { .kpi-grid { grid-template-columns:repeat(2,1fr); } }
    @media (max-width:900px)  { .page-content { padding:1.25rem; } }
    @media (max-width:768px)  {
        .kpi-grid { grid-template-columns:1fr; }
        .toolbar  { flex-direction:column; align-items:flex-start; }
        .form-grid { grid-template-columns:1fr; }
        .form-field.span-2 { grid-column:1; }
        .modal-body  { padding:1.25rem; }
        .modal-footer{ padding:1rem 1.25rem; }
        .existing-user-grid { grid-template-columns:1fr; }
        .btn-add-dlc { width:100%; justify-content:center; }
        .search-input { width:100%; }
    }
    @keyframes spin { to { transform:rotate(360deg); } }
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
                    <h2>DLC Offices</h2>
                    <p>Manage DLC office accounts & details</p>
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
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/><path d="M9 8h1"/><path d="M9 12h1"/><path d="M14 8h1"/><path d="M14 12h1"/></svg>
                    </div>
                    <div>
                        <div class="page-header-title">DLC Offices</div>
                        <div class="page-header-subtitle">Manage District-Level Centre offices and their user accounts</div>
                    </div>
                </div>
                <button class="btn-add-dlc" onclick="openAddModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add DLC Office
                </button>
            </div>

            <!-- KPI Cards -->
            <div class="kpi-grid">
                <div class="kpi-card brand">
                    <div class="kpi-top">
                        <div class="kpi-icon brand">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg>
                        </div>
                    </div>
                    <div class="kpi-value"><?= $totalCount ?></div>
                    <div class="kpi-label">Total Offices</div>
                </div>
                <div class="kpi-card emerald">
                    <div class="kpi-top">
                        <div class="kpi-icon emerald">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                    </div>
                    <div class="kpi-value"><?= $activeCount ?></div>
                    <div class="kpi-label">Active</div>
                </div>
                <div class="kpi-card amber">
                    <div class="kpi-top">
                        <div class="kpi-icon amber">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        </div>
                    </div>
                    <div class="kpi-value"><?= $inactiveCount ?></div>
                    <div class="kpi-label">Inactive</div>
                </div>
                <div class="kpi-card sky">
                    <div class="kpi-top">
                        <div class="kpi-icon sky">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                        </div>
                    </div>
                    <div class="kpi-value"><?= $totalATC ?></div>
                    <div class="kpi-label">ATC Centers</div>
                </div>
            </div>

            <!-- Toolbar -->
            <div class="toolbar">
                <div class="toolbar-left">
                    <span class="toolbar-title">All DLC Offices</span>
                    <span class="toolbar-count" id="visibleCount"><?= $totalCount ?> shown</span>
                </div>
                <div class="toolbar-right">
                    <div class="search-wrap">
                        <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" class="search-input" id="searchInput" placeholder="Search offices, districts…" autocomplete="off">
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="table-wrap">
                <table class="data-table" id="dlcTable">
                    <thead>
                        <tr>
                            <th>Office</th>
                            <th>District / State</th>
                            <th>Contact</th>
                            <th>ATC Centers</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="dlcTableBody">
                    <?php if (empty($dlcOffices)): ?>
                        <tr><td colspan="6">
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg>
                                </div>
                                <div class="empty-title">No DLC Offices found</div>
                                <div class="empty-sub">Add your first office to get started.</div>
                            </div>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($dlcOffices as $dlc):
                            $initial     = strtoupper(substr($dlc['name'], 0, 1));
                            $statusClass = strtolower($dlc['status']) === 'active' ? 's-active' : 's-inactive';
                        ?>
                        <tr>
                            <td>
                                <div class="office-cell">
                                    <div class="office-avatar"><?= $initial ?></div>
                                    <div>
                                        <div class="office-name"><?= htmlspecialchars($dlc['name']) ?></div>
                                        <?php if ($dlc['address']): ?>
                                            <div class="office-state"><?= htmlspecialchars(mb_substr($dlc['address'], 0, 45)) ?><?= mb_strlen($dlc['address']) > 45 ? '…' : '' ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="cell-main"><?= htmlspecialchars($dlc['district']) ?></div>
                                <div class="cell-sub"><?= htmlspecialchars($dlc['state']) ?></div>
                            </td>
                            <td>
                                <?php if ($dlc['contact_person']): ?>
                                    <div class="cell-main"><?= htmlspecialchars($dlc['contact_person']) ?></div>
                                <?php endif; ?>
                                <?php if ($dlc['mobile']): ?>
                                    <div class="cell-sub"><?= htmlspecialchars($dlc['mobile']) ?></div>
                                <?php endif; ?>
                                <?php if (!$dlc['contact_person'] && !$dlc['mobile']): ?>
                                    <span style="color:var(--text-4);">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="atc-pill">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/></svg>
                                    <?= (int)$dlc['atc_count'] ?> Center<?= $dlc['atc_count'] != 1 ? 's' : '' ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?= $statusClass ?>">
                                    <span class="status-dot"></span><?= htmlspecialchars($dlc['status']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="actions-wrap">
                                    <button class="btn-act user-btn" onclick="openUserModal(<?= (int)$dlc['id'] ?>, '<?= htmlspecialchars($dlc['name'], ENT_QUOTES) ?>')" title="Manage User">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                                    </button>
                                    <button class="btn-act" onclick="editDLC(<?= (int)$dlc['id'] ?>)" title="Edit">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </button>
                                    <button class="btn-act danger" onclick="confirmDeleteDLC(<?= (int)$dlc['id'] ?>, '<?= htmlspecialchars($dlc['name'], ENT_QUOTES) ?>')" title="Delete">
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

<!-- Toast -->
<div class="toast-container" id="toastContainer"></div>

<!-- ══════════════════════════════════
     ADD / EDIT DLC MODAL
══════════════════════════════════ -->
<div class="modal-overlay" id="dlcModal">
    <div class="modal-panel" style="max-width:700px;">
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon brand">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg>
                </div>
                <div>
                    <div class="modal-title" id="dlcModalTitle">Add New DLC Office</div>
                    <div class="modal-subtitle" id="dlcModalSubtitle">Fill in the details to register a new office</div>
                </div>
            </div>
            <button type="button" class="btn-close" onclick="closeModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <form id="dlcForm" novalidate>
            <input type="hidden" id="dlcId"      name="id">
            <input type="hidden" id="formAction" name="action" value="add">
            <div class="modal-body">

                <!-- Basic info -->
                <div class="form-section">
                    <div class="form-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg>
                        Office Information
                    </div>
                    <div class="form-grid">
                        <div class="form-field span-2">
                            <label class="field-label" for="name">Office Name <span class="required-star">*</span></label>
                            <div class="input-wrap">
                                <span class="input-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg></span>
                                <input type="text" id="name" name="name" placeholder="e.g. Pune District Learning Centre" required maxlength="150" autocomplete="off">
                            </div>
                        </div>
                        <div class="form-field">
                            <label class="field-label" for="district">District <span class="required-star">*</span></label>
                            <div class="input-wrap">
                                <span class="input-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></span>
                                <input type="text" id="district" name="district" placeholder="e.g. Pune" required maxlength="100" autocomplete="off">
                            </div>
                        </div>
                        <div class="form-field">
                            <label class="field-label" for="taluka">Taluka <span class="required-star">*</span></label>
                            <div class="input-wrap">
                                <span class="input-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></span>
                                <input type="text" id="taluka" name="taluka" placeholder="e.g. Haveli" required maxlength="100" autocomplete="off">
                            </div>
                        </div>
                        <div class="form-field">
                            <label class="field-label" for="pin_code">PIN Code <span class="required-star">*</span></label>
                            <div class="input-wrap">
                                <span class="input-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></span>
                                <input type="text" id="pin_code" name="pin_code" placeholder="6-digit PIN" required maxlength="10" pattern="[0-9]{6}" autocomplete="off">
                            </div>
                        </div>
                        <div class="form-field">
                            <label class="field-label" for="dob">Contact Birthday</label>
                            <div class="input-wrap">
                                <input type="date" id="dob" name="dob">
                            </div>
                        </div>
                        <div class="form-field">
                            <label class="field-label" for="state">State</label>
                            <div class="input-wrap select-wrap">
                                <span class="input-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg></span>
                                <select id="state" name="state">
                                    <?php foreach ($states as $s): ?>
                                        <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-field">
                            <label class="field-label" for="status">Status</label>
                            <div class="input-wrap select-wrap">
                                <span class="input-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>
                                <select id="status" name="status">
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-field span-2">
                            <label class="field-label" for="address">Office Address</label>
                            <textarea id="address" name="address" rows="3" placeholder="Full office address with landmark…"></textarea>
                        </div>
                    </div>
                </div>

                <!-- Contact -->
                <div class="form-section">
                    <div class="form-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        Contact Information
                    </div>
                    <div class="form-grid">
                        <div class="form-field">
                            <label class="field-label" for="contact_person">Contact Person</label>
                            <div class="input-wrap">
                                <span class="input-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
                                <input type="text" id="contact_person" name="contact_person" placeholder="Full name" maxlength="100" autocomplete="off">
                            </div>
                        </div>
                        <div class="form-field">
                            <label class="field-label" for="mobile">Mobile Number</label>
                            <div class="input-wrap">
                                <span class="input-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg></span>
                                <input type="tel" id="mobile" name="mobile" placeholder="9876543210" pattern="[0-9]{10}" maxlength="10" autocomplete="off">
                            </div>
                            <span class="field-hint">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                                10 digits, no spaces
                            </span>
                        </div>
                        <div class="form-field span-2">
                            <label class="field-label" for="email">Email Address</label>
                            <div class="input-wrap">
                                <span class="input-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></span>
                                <input type="email" id="email" name="email" placeholder="office@example.com" maxlength="100" autocomplete="off">
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /modal-body -->
            <div class="modal-footer">
                <button type="button" class="modal-btn modal-btn-ghost" onclick="closeModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    Cancel
                </button>
                <button type="submit" class="modal-btn modal-btn-primary" id="dlcSubmitBtn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <span id="dlcSubmitText">Add DLC Office</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════
     USER MODAL (Create / View)
══════════════════════════════════ -->
<div class="modal-overlay" id="userModal">
    <div class="modal-panel" style="max-width:600px;">
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon emerald">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                </div>
                <div>
                    <div class="modal-title" id="userModalTitle">Create User Account</div>
                    <div class="modal-subtitle">Set up login credentials for this DLC office</div>
                </div>
            </div>
            <button type="button" class="btn-close" onclick="closeUserModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <div class="modal-body" id="userModalBody">
            <!-- Populated by JS -->
        </div>
        <div class="modal-footer" id="userModalFooter">
            <button type="button" class="modal-btn modal-btn-ghost" onclick="closeUserModal()">Close</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════
     DELETE CONFIRM
══════════════════════════════════ -->
<div class="confirm-overlay" id="deleteOverlay">
    <div class="confirm-card">
        <div class="confirm-body">
            <div class="confirm-icon-wrap">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            </div>
            <div class="confirm-title">Delete DLC Office?</div>
            <div class="confirm-msg">
                You're about to permanently delete<br>
                <span class="confirm-name" id="deleteDlcName"></span>
            </div>
            <div class="confirm-warn">This will also unlink all associated ATC centers.</div>
        </div>
        <div class="confirm-footer">
            <button class="btn-keep-dlc" onclick="closeDeleteDialog()">Keep Office</button>
            <button class="btn-del-dlc"  id="confirmDeleteBtn">Delete</button>
        </div>
    </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
/* ════════════════════════════════════
   DLC OFFICES SCRIPTS
════════════════════════════════════ */
const PAGE_URL = '<?= htmlspecialchars($pageUrl, ENT_QUOTES) ?>';

/* ── Toast ── */
function showToast(msg, type) {
    var c = document.getElementById('toastContainer');
    var t = document.createElement('div');
    t.className = 'toast ' + (type || 'success');
    var icon = (type === 'error')
        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';
    t.innerHTML = '<div class="toast-icon">' + icon + '</div>'
        + '<span class="toast-msg">' + msg + '</span>'
        + '<button class="toast-close" onclick="this.closest(\'.toast\').remove()">'
        + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';
    c.appendChild(t);
    setTimeout(function() { if (t.parentNode) t.remove(); }, 4500);
}

/* ── POST helper ── */
async function postAction(data) {
    var params = new URLSearchParams();
    for (var k in data) { if (data[k] !== null && data[k] !== undefined) params.append(k, data[k]); }
    var res = await fetch(PAGE_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return res.json();
}

/* ── Loading state ── */
function setLoading(btn, loading, orig) {
    if (loading) {
        btn.disabled = true;
        btn.innerHTML = '<svg style="width:14px;height:14px;animation:spin .8s linear infinite;fill:none;stroke:currentColor;stroke-width:2;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> Saving…';
    } else {
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}

/* ── Client-side search ── */
document.getElementById('searchInput').addEventListener('input', function() {
    var q    = this.value.toLowerCase().trim();
    var rows = document.querySelectorAll('#dlcTableBody tr');
    var vis  = 0;
    rows.forEach(function(row) {
        var match = row.textContent.toLowerCase().includes(q);
        row.style.display = match ? '' : 'none';
        if (match) vis++;
    });
    document.getElementById('visibleCount').textContent = vis + ' shown';
});

/* ════════ DLC MODAL ════════ */
function openAddModal() {
    document.getElementById('dlcModalTitle').textContent    = 'Add New DLC Office';
    document.getElementById('dlcModalSubtitle').textContent = 'Fill in the details to register a new office';
    document.getElementById('dlcSubmitText').textContent    = 'Add DLC Office';
    document.getElementById('formAction').value = 'add';
    document.getElementById('dlcId').value      = '';
    document.getElementById('dlcForm').reset();
    document.getElementById('state').value  = 'Maharashtra';
    document.getElementById('status').value = 'Active';
    document.getElementById('dlcModal').classList.add('active');
    setTimeout(function() { document.getElementById('name').focus(); }, 120);
}

function closeModal() {
    document.getElementById('dlcModal').classList.remove('active');
}

async function editDLC(id) {
    try {
        var data = await postAction({ action: 'get', id: id });
        if (!data.success) { showToast('Error loading data', 'error'); return; }
        var d = data.data;
        document.getElementById('dlcModalTitle').textContent    = 'Edit DLC Office';
        document.getElementById('dlcModalSubtitle').textContent = 'Update office details and information';
        document.getElementById('dlcSubmitText').textContent    = 'Save Changes';
        document.getElementById('formAction').value    = 'edit';
        document.getElementById('dlcId').value         = d.id;
        document.getElementById('name').value          = d.name          || '';
        document.getElementById('district').value      = d.district      || '';
        document.getElementById('taluka').value        = d.taluka        || '';
        document.getElementById('pin_code').value      = d.pin_code      || '';
        document.getElementById('state').value         = d.state         || 'Maharashtra';
        document.getElementById('contact_person').value= d.contact_person|| '';
        document.getElementById('mobile').value        = d.mobile        || '';
        document.getElementById('email').value         = d.email         || '';
        document.getElementById('address').value       = d.address       || '';
        document.getElementById('status').value        = d.status        || 'Active';
        document.getElementById('dob').value            = d.dob           || '';
        document.getElementById('dlcModal').classList.add('active');
        setTimeout(function() { document.getElementById('name').focus(); }, 120);
    } catch (err) {
        console.error(err);
        showToast('Error loading office data', 'error');
    }
}

document.getElementById('dlcForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var mobile = document.getElementById('mobile').value;
    if (mobile && !/^[0-9]{10}$/.test(mobile)) {
        showToast('Please enter a valid 10-digit mobile number', 'error');
        document.getElementById('mobile').focus(); return;
    }
    var btn  = document.getElementById('dlcSubmitBtn');
    var orig = btn.innerHTML;
    setLoading(btn, true, orig);
    try {
        var fd   = new FormData(this);
        var data = await postAction(Object.fromEntries(fd));
        if (data.success) {
            showToast(data.message, 'success');
            closeModal();
            setTimeout(function() { location.reload(); }, 900);
        } else {
            showToast('Error: ' + (data.message || 'Could not save'), 'error');
            setLoading(btn, false, orig);
        }
    } catch (err) {
        console.error(err);
        showToast('Unexpected error.', 'error');
        setLoading(btn, false, orig);
    }
});

/* ════════ DELETE ════════ */
var _deleteId = null;

function confirmDeleteDLC(id, name) {
    _deleteId = id;
    document.getElementById('deleteDlcName').textContent = name;
    document.getElementById('deleteOverlay').classList.add('active');
}
function closeDeleteDialog() {
    _deleteId = null;
    document.getElementById('deleteOverlay').classList.remove('active');
}

document.getElementById('confirmDeleteBtn').addEventListener('click', async function() {
    if (!_deleteId) return;
    var btn = this, orig = btn.innerHTML;
    btn.disabled = true; btn.textContent = 'Deleting…';
    try {
        var data = await postAction({ action: 'delete', id: _deleteId });
        closeDeleteDialog();
        if (data.success) {
            showToast('DLC Office deleted', 'success');
            setTimeout(function() { location.reload(); }, 900);
        } else {
            showToast('Error: ' + (data.message || 'Could not delete'), 'error');
            btn.disabled = false; btn.innerHTML = orig;
        }
    } catch (err) {
        showToast('Unexpected error.', 'error');
        btn.disabled = false; btn.innerHTML = orig;
    }
});

/* ════════ USER MODAL ════════ */
function closeUserModal() {
    document.getElementById('userModal').classList.remove('active');
}

async function openUserModal(dlcId, dlcName) {
    // Show loading state while fetching
    document.getElementById('userModalBody').innerHTML =
        '<div style="text-align:center;padding:3rem 2rem;color:var(--text-4);">Loading…</div>';
    document.getElementById('userModalFooter').innerHTML =
        '<button type="button" class="modal-btn modal-btn-ghost" onclick="closeUserModal()">Close</button>';
    document.getElementById('userModal').classList.add('active');

    try {
        var data = await postAction({ action: 'get_dlc_user', dlc_id: dlcId });
        if (data.success && data.data) {
            // ── Existing user ──
            var u = data.data;
            var initial = (u.username || 'U').charAt(0).toUpperCase();
            var statusCls = (u.status === 'Active') ? 's-active' : 's-inactive';

            document.getElementById('userModalTitle').textContent = 'DLC User Account';
            document.getElementById('userModalBody').innerHTML =
                '<div class="dlc-banner">'
                + '<div class="dlc-banner-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg></div>'
                + '<div><div class="dlc-banner-label">DLC Office</div><div class="dlc-banner-name">' + dlcName + '</div></div>'
                + '</div>'
                + '<div class="existing-user-card">'
                + '<div class="existing-user-header">'
                + '<div class="existing-user-avatar">' + initial + '</div>'
                + '<div><div class="existing-user-title">' + (u.name || '\u2014') + '</div>'
                + '<div class="existing-user-note">User account already exists for this office</div></div>'
                + '</div>'
                + '<div class="existing-user-grid">'
                // Username row with copy
                + '<div class="existing-user-item"><span class="existing-user-label">Username</span>'
                + '<span class="existing-user-value mono" style="display:flex;align-items:center;gap:.5rem">'
                + '<span id="euUsername">' + (u.username || '\u2014') + '</span>'
                + '<button onclick="navigator.clipboard.writeText(\'' + (u.username||'') + '\').then(()=>showToast(\'Username copied!\',\'success\'))" title="Copy" style="border:none;background:none;cursor:pointer;padding:0;display:flex;align-items:center">'
                + '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>'
                + '</button></span></div>'
                // Password row (hashed – show reset option)
                + '<div class="existing-user-item"><span class="existing-user-label">Password</span>'
                + '<span class="existing-user-value" style="display:flex;align-items:center;gap:.5rem;color:var(--text-4)">'
                + '<span>\u25cf\u25cf\u25cf\u25cf\u25cf\u25cf\u25cf\u25cf</span>'
                + '<button onclick="toggleResetPwd()" id="toggleResetBtn" style="border:none;background:var(--brand-light);color:var(--brand);border-radius:6px;font-size:.7rem;font-weight:700;padding:.2rem .55rem;cursor:pointer">Reset</button>'
                + '</span></div>'
                + '<div class="existing-user-item"><span class="existing-user-label">Status</span><span class="existing-user-value"><span class="status-badge ' + statusCls + '"><span class="status-dot"></span>' + u.status + '</span></span></div>'
                + '<div class="existing-user-item"><span class="existing-user-label">Email</span><span class="existing-user-value">' + (u.email || '\u2014') + '</span></div>'
                + '<div class="existing-user-item"><span class="existing-user-label">Mobile</span><span class="existing-user-value mono">' + (u.mobile || '\u2014') + '</span></div>'
                + '</div>'
                // Reset password inline form
                + '<div id="resetPwdBox" style="display:none;margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border)">'
                + '<div style="font-size:.75rem;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.6rem">Set New Password</div>'
                + '<div style="display:flex;gap:.5rem;align-items:center">'
                + '<input type="password" id="newPwdInput" placeholder="Min. 6 characters" minlength="6" style="flex:1;padding:.65rem .85rem;border:1.5px solid var(--border);border-radius:8px;font-size:.875rem;font-family:inherit;outline:none" onfocus="this.style.borderColor=\'var(--brand)\'" onblur="this.style.borderColor=\'var(--border)\'">'
                + '<button onclick="doResetPassword(' + u.id + ')" style="padding:.65rem 1.1rem;background:linear-gradient(135deg,var(--brand),var(--brand-dark));color:#fff;border:none;border-radius:8px;font-size:.82rem;font-weight:700;cursor:pointer;white-space:nowrap">Save</button>'
                + '</div>'
                + '<div style="font-size:.72rem;color:var(--text-4);margin-top:.4rem">The DLC user will use this new password on next login.</div>'
                + '</div>'
                + '</div>';

            document.getElementById('userModalFooter').innerHTML =
                '<button type="button" class="modal-btn modal-btn-ghost" onclick="closeUserModal()">Close</button>';

        } else {
            // ── No user – show create form ──
            document.getElementById('userModalTitle').textContent = 'Create User Account';
            document.getElementById('userModalBody').innerHTML =
                '<div class="dlc-banner">'
                + '<div class="dlc-banner-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg></div>'
                + '<div><div class="dlc-banner-label">DLC Office</div><div class="dlc-banner-name">' + dlcName + '</div></div>'
                + '</div>'
                + '<form id="createUserForm" novalidate>'
                + '<input type="hidden" name="action"  value="create_user">'
                + '<input type="hidden" name="dlc_id"  value="' + dlcId + '">'

                + '<div class="form-section">'
                + '<div class="form-section-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Login Credentials</div>'
                + '<div class="form-grid">'
                + '<div class="form-field"><label class="field-label">Username <span class="required-star">*</span></label>'
                + '<div class="input-wrap"><span class="input-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>'
                + '<input type="text" name="username" id="cu_username" placeholder="e.g. pune_dlc" required maxlength="50" autocomplete="off"></div>'
                + '<span class="field-hint"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>Unique login username</span></div>'

                + '<div class="form-field"><label class="field-label">Password <span class="required-star">*</span></label>'
                + '<div class="input-wrap"><span class="input-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>'
                + '<input type="password" name="password" id="cu_password" placeholder="Min. 6 characters" required minlength="6" autocomplete="new-password"></div>'
                + '<span class="field-hint"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>At least 6 characters</span></div>'
                + '</div></div>'

                + '<div class="form-section">'
                + '<div class="form-section-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> Personal Information</div>'
                + '<div class="form-grid">'
                + '<div class="form-field span-2"><label class="field-label">Full Name <span class="required-star">*</span></label>'
                + '<div class="input-wrap"><span class="input-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>'
                + '<input type="text" name="name" placeholder="Full name" required maxlength="100" autocomplete="off"></div></div>'

                + '<div class="form-field"><label class="field-label">Email Address</label>'
                + '<div class="input-wrap"><span class="input-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></span>'
                + '<input type="email" name="email" placeholder="user@example.com" maxlength="100" autocomplete="off"></div></div>'

                + '<div class="form-field"><label class="field-label">Mobile</label>'
                + '<div class="input-wrap"><span class="input-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg></span>'
                + '<input type="tel" name="mobile" placeholder="9876543210" pattern="[0-9]{10}" maxlength="10" autocomplete="off"></div></div>'
                + '</div></div>'
                + '</form>';

            document.getElementById('userModalFooter').innerHTML =
                '<button type="button" class="modal-btn modal-btn-ghost" onclick="closeUserModal()">Cancel</button>'
                + '<button type="button" class="modal-btn modal-btn-emerald" id="createUserBtn" onclick="submitCreateUser()">'
                + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>'
                + 'Create User</button>';

            setTimeout(function() {
                var el = document.getElementById('cu_username');
                if (el) el.focus();
            }, 120);
        }
    } catch (err) {
        console.error(err);
        document.getElementById('userModalBody').innerHTML =
            '<div style="text-align:center;padding:3rem 2rem;color:var(--rose);">Error loading user data.</div>';
    }
}

function toggleResetPwd() {
    var box = document.getElementById('resetPwdBox');
    var btn = document.getElementById('toggleResetBtn');
    if (!box) return;
    var show = box.style.display === 'none';
    box.style.display = show ? 'block' : 'none';
    btn.textContent = show ? 'Cancel' : 'Reset';
    if (show) setTimeout(function() { var el = document.getElementById('newPwdInput'); if (el) el.focus(); }, 80);
}

async function doResetPassword(userId) {
    var inp = document.getElementById('newPwdInput');
    if (!inp || inp.value.length < 6) {
        showToast('Password must be at least 6 characters.', 'error');
        if (inp) inp.focus();
        return;
    }
    try {
        var data = await postAction({ action: 'change_password', user_id: userId, new_password: inp.value });
        if (data.success) {
            showToast(data.message, 'success');
            toggleResetPwd();
            inp.value = '';
        } else {
            showToast('Error: ' + (data.message || 'Could not update'), 'error');
        }
    } catch (err) {
        showToast('Unexpected error.', 'error');
    }
}

async function submitCreateUser() {
    var form = document.getElementById('createUserForm');
    if (!form) return;
    if (!form.checkValidity()) { form.reportValidity(); return; }

    var mobile = form.querySelector('[name="mobile"]').value;
    if (mobile && !/^[0-9]{10}$/.test(mobile)) {
        showToast('Enter a valid 10-digit mobile number', 'error'); return;
    }

    var btn  = document.getElementById('createUserBtn');
    var orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<svg style="width:14px;height:14px;animation:spin .8s linear infinite;fill:none;stroke:currentColor;stroke-width:2;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> Creating…';

    try {
        var fd   = new FormData(form);
        var res  = await fetch(PAGE_URL, { method: 'POST', body: fd });
        var data = await res.json();
        if (data.success) {
            showToast('User account created successfully!', 'success');
            closeUserModal();
            setTimeout(function() { location.reload(); }, 900);
        } else {
            showToast('Error: ' + (data.message || 'Could not create user'), 'error');
            btn.disabled = false; btn.innerHTML = orig;
        }
    } catch (err) {
        console.error(err);
        showToast('Unexpected error.', 'error');
        btn.disabled = false; btn.innerHTML = orig;
    }
}

/* ── Close on overlay / Escape ── */
document.querySelectorAll('.modal-overlay, .confirm-overlay').forEach(function(o) {
    o.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
            if (this.id === 'deleteOverlay') _deleteId = null;
        }
    });
});
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.modal-overlay.active, .confirm-overlay.active').forEach(function(el) {
        el.classList.remove('active');
    });
    _deleteId = null;
});
</script>
</body>
</html>