<?php
/**
 * Gyanam Portal — Admin: Documents Management
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo      = getDBConnection();
$userName = sanitize(getUserName());
$userId   = $_SESSION['user_id'] ?? null;

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return number_format($bytes / 1048576,    2) . ' MB';
    if ($bytes >= 1024)       return number_format($bytes / 1024,       2) . ' KB';
    return $bytes . ' B';
}

function fileTypeInfo($filename, $mimeType = '') {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match($ext) {
        'pdf'              => ['label' => 'PDF',   'color' => '#ef4444', 'bg' => '#fff1f2'],
        'doc', 'docx'      => ['label' => 'DOC',   'color' => '#2563eb', 'bg' => '#eff6ff'],
        'xls', 'xlsx'      => ['label' => 'XLS',   'color' => '#16a34a', 'bg' => '#f0fdf4'],
        'ppt', 'pptx'      => ['label' => 'PPT',   'color' => '#ea580c', 'bg' => '#fff7ed'],
        'txt'              => ['label' => 'TXT',   'color' => '#6b7280', 'bg' => '#f9fafb'],
        'jpg', 'jpeg','png' => ['label' => 'IMG',  'color' => '#7c3aed', 'bg' => '#f5f3ff'],
        default            => ['label' => strtoupper($ext) ?: 'FILE', 'color' => '#4361ee', 'bg' => '#eef1fd'],
    };
}

/* ══════════════════════════════════════
   AJAX HANDLER
══════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        switch ($_POST['action']) {

            case 'upload':
                if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Please select a valid file');
                }
                $file         = $_FILES['document'];
                $originalName = $file['name'];
                $fileSize     = $file['size'];
                $fileType     = $file['type'];

                if ($fileSize > 50 * 1024 * 1024) {
                    throw new Exception('File size must not exceed 50 MB');
                }

                $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                $fileName  = 'DOC_' . date('YmdHis') . '_' . uniqid() . '.' . $extension;
                $uploadDir = __DIR__ . '/../uploads/documents/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $filePath = $uploadDir . $fileName;

                if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                    throw new Exception('Failed to save file');
                }

                $stmt = $pdo->prepare("
                    INSERT INTO documents (file_name, original_name, file_path, file_size, file_type, uploaded_by, uploaded_by_role, description)
                    VALUES (?, ?, ?, ?, ?, ?, 'ADMIN', ?)
                ");
                $stmt->execute([
                    $fileName, $originalName,
                    'uploads/documents/' . $fileName,
                    $fileSize, $fileType, $userId,
                    $_POST['description'] ?? ''
                ]);

                echo json_encode(['success' => true, 'message' => 'Document uploaded successfully']);
                exit;

            case 'delete':
                $stmt = $pdo->prepare("SELECT file_path FROM documents WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                $doc = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($doc) {
                    $fp = __DIR__ . '/../' . $doc['file_path'];
                    if (file_exists($fp)) unlink($fp);
                    $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$_POST['id']]);
                }
                echo json_encode(['success' => true, 'message' => 'Document deleted']);
                exit;

            case 'toggle_status':
                $pdo->prepare("UPDATE documents SET status = IF(status='Active','Inactive','Active') WHERE id = ?")
                    ->execute([$_POST['id']]);
                echo json_encode(['success' => true, 'message' => 'Status updated']);
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
$searchTerm   = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? 'all';

$sql    = "SELECT d.*, u.username AS uploaded_by_name FROM documents d LEFT JOIN users u ON d.uploaded_by = u.id WHERE 1=1";
$params = [];

if ($searchTerm) {
    $sql   .= " AND (d.original_name LIKE ? OR d.description LIKE ?)";
    $p      = "%$searchTerm%";
    $params = [$p, $p];
}
if ($statusFilter !== 'all') {
    $sql     .= " AND d.status = ?";
    $params[] = $statusFilter;
}
$sql .= " ORDER BY d.upload_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalCount    = (int)$pdo->query("SELECT COUNT(*) FROM documents")->fetchColumn();
$activeCount   = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE status='Active'")->fetchColumn();
$inactiveCount = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE status='Inactive'")->fetchColumn();

// Storage used
$storageRow = $pdo->query("SELECT COALESCE(SUM(file_size),0) FROM documents")->fetchColumn();
$storageUsed = formatFileSize((int)$storageRow);

$pageUrl = strtok($_SERVER['REQUEST_URI'], '?');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents — Admin | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📄</text></svg>">
    <style>
    /* ═══════════════════════════════════════════
       DESIGN TOKENS  (shared portal system)
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
    .page-header-title       { font-size:1.375rem; font-weight:800; color:var(--text); letter-spacing:-.03em; line-height:1.2; }
    .page-header-subtitle    { font-size:.8125rem; color:var(--text-3); margin-top:.2rem; }

    .btn-upload {
        display:inline-flex; align-items:center; gap:.5rem;
        padding:.7rem 1.375rem;
        background:linear-gradient(135deg,var(--brand),var(--brand-dark));
        border:none; border-radius:var(--r-md); color:#fff;
        font-size:.875rem; font-weight:700; font-family:var(--font);
        cursor:pointer; white-space:nowrap; letter-spacing:-.01em;
        box-shadow:var(--shadow-brand), inset 0 1px 0 rgba(255,255,255,.15);
        transition:transform var(--t), box-shadow var(--t);
    }
    .btn-upload:hover  { transform:translateY(-2px); box-shadow:0 10px 28px var(--brand-glow); }
    .btn-upload:active { transform:translateY(0); }
    .btn-upload svg    { width:16px; height:16px; stroke:white; fill:none; }

    /* ── KPI Grid ── */
    .kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; margin-bottom:1.75rem; }
    .kpi-card {
        background:var(--surface); border:1px solid var(--border);
        border-radius:var(--r-xl); padding:1.375rem 1.5rem 1.25rem;
        position:relative; overflow:hidden;
        box-shadow:var(--shadow-sm);
        transition:transform var(--t), box-shadow var(--t); cursor:default;
    }
    .kpi-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); }
    .kpi-card::before { content:''; position:absolute; top:0; left:0; bottom:0; width:4px; border-radius:var(--r-xl) 0 0 var(--r-xl); }
    .kpi-card::after  { content:''; position:absolute; right:-20px; top:-20px; width:90px; height:90px; border-radius:50%; opacity:.055; pointer-events:none; }

    .kpi-card.brand::before   { background:linear-gradient(180deg,var(--brand),#818cf8); }
    .kpi-card.emerald::before { background:linear-gradient(180deg,var(--emerald),#34d399); }
    .kpi-card.amber::before   { background:linear-gradient(180deg,var(--amber),#fcd34d); }
    .kpi-card.violet::before  { background:linear-gradient(180deg,var(--violet),#a78bfa); }
    .kpi-card.brand::after    { background:var(--brand);   }
    .kpi-card.emerald::after  { background:var(--emerald); }
    .kpi-card.amber::after    { background:var(--amber);   }
    .kpi-card.violet::after   { background:var(--violet);  }

    .kpi-top   { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:.875rem; }
    .kpi-icon  { width:42px; height:42px; border-radius:var(--r-md); display:flex; align-items:center; justify-content:center; }
    .kpi-icon svg { width:20px; height:20px; fill:none; }
    .kpi-icon.brand   { background:var(--brand-light); }  .kpi-icon.brand   svg { stroke:var(--brand); }
    .kpi-icon.emerald { background:var(--emerald-soft); } .kpi-icon.emerald svg { stroke:var(--emerald-dark); }
    .kpi-icon.amber   { background:var(--amber-soft);  }  .kpi-icon.amber   svg { stroke:var(--amber-dark); }
    .kpi-icon.violet  { background:var(--violet-soft); }  .kpi-icon.violet  svg { stroke:var(--violet); }

    .kpi-value { font-size:2rem; font-weight:800; color:var(--text); letter-spacing:-.05em; line-height:1; }
    .kpi-label { font-size:.72rem; font-weight:600; color:var(--text-3); text-transform:uppercase; letter-spacing:.07em; margin-top:.375rem; }

    /* ── Status tabs ── */
    .status-tabs {
        display:flex; gap:.25rem;
        background:var(--surface); border:1px solid var(--border);
        border-radius:var(--r-xl); padding:.3125rem;
        box-shadow:var(--shadow-xs);
        width:fit-content; max-width:100%; overflow-x:auto;
        margin-bottom:1.375rem;
    }
    .status-tab {
        display:flex; align-items:center; gap:.5rem;
        padding:.5625rem 1.125rem; border-radius:var(--r-lg);
        font-size:.8375rem; font-weight:600;
        color:var(--text-3); text-decoration:none;
        transition:background var(--t), color var(--t); white-space:nowrap;
    }
    .status-tab:hover  { background:var(--surface-3); color:var(--text-2); }
    .status-tab.active { background:var(--brand); color:#fff; box-shadow:0 3px 10px var(--brand-glow); }
    .tab-pill { padding:.15rem .5rem; border-radius:var(--r-full); font-size:.7rem; font-weight:800; }
    .status-tab.active     .tab-pill { background:rgba(255,255,255,.22); color:#fff; }
    .status-tab:not(.active) .tab-pill { background:var(--surface-3); color:var(--text-4); }
    .tab-pill.pill-emerald { background:#d1fae5; color:#065f46; }
    .tab-pill.pill-gray    { background:#f1f5f9; color:#64748b; }
    .status-tab.active .tab-pill.pill-emerald,
    .status-tab.active .tab-pill.pill-gray { background:rgba(255,255,255,.22); color:#fff; }

    /* ── Toolbar ── */
    .toolbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; gap:1rem; flex-wrap:wrap; }
    .toolbar-left  { display:flex; align-items:center; gap:.625rem; }
    .toolbar-title { font-size:1rem; font-weight:700; color:var(--text); letter-spacing:-.02em; }
    .toolbar-count { font-size:.72rem; font-weight:700; color:var(--text-4); background:var(--surface-3); border:1px solid var(--border); padding:.175rem .55rem; border-radius:var(--r-full); }
    .toolbar-right { display:flex; align-items:center; gap:.625rem; flex-wrap:wrap; }

    .search-wrap  { position:relative; display:flex; align-items:center; }
    .search-icon  { position:absolute; left:.875rem; width:15px; height:15px; stroke:var(--text-4); fill:none; pointer-events:none; }
    .search-input {
        padding:.65rem .875rem .65rem 2.4rem;
        border:1.5px solid var(--border); border-radius:var(--r-md);
        font-size:.85rem; font-family:var(--font); font-weight:500;
        background:var(--surface); color:var(--text); outline:none;
        width:220px; transition:border-color var(--t), box-shadow var(--t);
    }
    .search-input:focus { border-color:var(--brand); box-shadow:0 0 0 3px var(--brand-glow); }
    .search-input::placeholder { color:var(--text-4); }
    .btn-search {
        padding:.65rem 1.125rem; background:var(--surface); border:1.5px solid var(--border);
        border-radius:var(--r-md); font-size:.85rem; font-weight:600;
        font-family:var(--font); color:var(--text-2); cursor:pointer; transition:all var(--t);
    }
    .btn-search:hover { border-color:var(--brand); color:var(--brand); background:var(--brand-light); }

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
    .data-table thead th:first-child { width:60px; text-align:center; }
    .data-table tbody tr { border-bottom:1px solid var(--surface-3); transition:background var(--t); }
    .data-table tbody tr:last-child  { border-bottom:none; }
    .data-table tbody tr:hover { background:#fafbff; }
    .data-table tbody td { padding:.9375rem 1.25rem; vertical-align:middle; }
    .data-table tbody td:first-child { text-align:center; }

    /* Row number */
    .row-num { font-size:.75rem; font-weight:700; color:var(--text-4); font-family:var(--mono); }

    /* File cell */
    .file-cell     { display:flex; align-items:center; gap:.875rem; }
    .file-type-badge {
        width:40px; height:40px; border-radius:var(--r-md);
        display:flex; align-items:center; justify-content:center;
        font-size:.65rem; font-weight:800; letter-spacing:.02em;
        flex-shrink:0; border:1px solid transparent;
    }
    .file-name  { font-size:.875rem; font-weight:700; color:var(--text); line-height:1.35; word-break:break-word; }
    .file-desc  { font-size:.775rem; color:var(--text-3); margin-top:.2rem; line-height:1.4; }

    /* Date / uploader */
    .date-main  { font-size:.85rem; font-weight:600; color:var(--text-2); }
    .date-sub   { font-size:.72rem; color:var(--text-4); margin-top:.15rem; }

    /* Size badge */
    .size-badge {
        display:inline-block; padding:.3rem .65rem;
        background:var(--surface-3); border:1px solid var(--border);
        border-radius:var(--r-full);
        font-size:.72rem; font-weight:700; color:var(--text-3);
        font-family:var(--mono); white-space:nowrap;
    }

    /* Status badge */
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
        background:var(--surface-2); cursor:pointer;
        text-decoration:none; transition:all var(--t);
    }
    .btn-act svg { width:14px; height:14px; stroke:var(--text-3); fill:none; }
    .btn-act:hover           { border-color:var(--brand);  background:var(--brand-light);  }
    .btn-act:hover svg       { stroke:var(--brand); }
    .btn-act.dl:hover        { border-color:#a7f3d0;        background:var(--emerald-soft); }
    .btn-act.dl:hover svg    { stroke:var(--emerald-dark); }
    .btn-act.toggle:hover    { border-color:#fde68a;        background:var(--amber-soft);   }
    .btn-act.toggle:hover svg{ stroke:var(--amber-dark); }
    .btn-act.danger:hover    { border-color:#fca5a5;        background:var(--rose-soft);    }
    .btn-act.danger:hover svg{ stroke:var(--rose); }

    /* Empty state */
    .empty-state { text-align:center; padding:4.5rem 2rem; }
    .empty-icon  { width:64px; height:64px; border-radius:var(--r-xl); background:var(--surface-2); border:1px solid var(--border); display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem; }
    .empty-icon svg { width:30px; height:30px; stroke:var(--text-4); fill:none; }
    .empty-title { font-size:.9375rem; font-weight:700; color:var(--text-2); }
    .empty-sub   { font-size:.8125rem; color:var(--text-3); margin-top:.3rem; }

    /* ── Upload modal drop zone ── */
    .dropzone {
        border:2px dashed var(--border-2); border-radius:var(--r-lg);
        padding:2.5rem 2rem; text-align:center; cursor:pointer;
        transition:border-color var(--t), background var(--t);
        position:relative; background:var(--surface-2);
    }
    .dropzone:hover, .dropzone.drag-over { border-color:var(--brand); background:var(--brand-light); }
    .dropzone input[type="file"] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }
    .dropzone-icon {
        width:52px; height:52px; border-radius:var(--r-lg);
        background:var(--brand-light); border:1px solid #c7d2fe;
        display:flex; align-items:center; justify-content:center;
        margin:0 auto .875rem;
    }
    .dropzone-icon svg { width:24px; height:24px; stroke:var(--brand); fill:none; }
    .dropzone-title { font-size:.9375rem; font-weight:700; color:var(--text); margin-bottom:.375rem; }
    .dropzone-sub   { font-size:.8rem; color:var(--text-3); }
    .dropzone-sub strong { color:var(--brand); }

    .file-preview {
        display:none; align-items:center; gap:.875rem;
        padding:1rem 1.125rem; margin-top:1rem;
        background:var(--surface); border:1px solid var(--border);
        border-radius:var(--r-lg);
    }
    .file-preview.show { display:flex; }
    .file-preview-icon {
        width:40px; height:40px; border-radius:var(--r-md);
        display:flex; align-items:center; justify-content:center;
        font-size:.65rem; font-weight:800; flex-shrink:0; background:var(--brand-light); color:var(--brand);
    }
    .file-preview-info { flex:1; min-width:0; }
    .file-preview-name { font-size:.875rem; font-weight:700; color:var(--text); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .file-preview-size { font-size:.75rem; color:var(--text-4); margin-top:.1rem; font-family:var(--mono); }
    .file-preview-remove { background:none; border:none; cursor:pointer; color:var(--text-4); padding:.25rem; display:flex; align-items:center; transition:color var(--t); }
    .file-preview-remove:hover { color:var(--rose); }
    .file-preview-remove svg { width:14px; height:14px; stroke:currentColor; fill:none; }

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

    /* ── Modals ── */
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
    .modal-icon.brand  { background:var(--brand-light); } .modal-icon.brand  svg { stroke:var(--brand); }
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

    .modal-body   { padding:1.625rem 1.75rem; overflow-y:auto; flex:1; }
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

    /* Form fields inside modal */
    .form-section { margin-bottom:1.5rem; }
    .form-section:last-child { margin-bottom:0; }
    .field-label  { display:block; font-size:.8rem; font-weight:700; color:var(--text-2); margin-bottom:.5rem; }
    .required-star { color:var(--rose); margin-left:.15rem; }
    .field-hint   { font-size:.75rem; color:var(--text-4); margin-top:.375rem; display:flex; align-items:flex-start; gap:.35rem; line-height:1.4; }
    .field-hint svg { width:12px; height:12px; flex-shrink:0; margin-top:.1rem; stroke:var(--text-4); fill:none; }
    .form-field   { margin-bottom:1.125rem; }
    .form-field:last-child { margin-bottom:0; }
    .form-field textarea {
        width:100%; padding:.75rem 1rem;
        border:1.5px solid var(--border); border-radius:var(--r-md);
        font-size:.875rem; font-family:var(--font); font-weight:500;
        background:var(--surface); color:var(--text); outline:none; resize:vertical; min-height:90px;
        transition:border-color var(--t), box-shadow var(--t);
    }
    .form-field textarea:focus { border-color:var(--brand); box-shadow:0 0 0 3px var(--brand-glow); }
    .form-field textarea::placeholder { color:var(--text-4); font-weight:400; }

    /* Delete confirm overlay */
    .confirm-overlay {
        position:fixed; inset:0;
        background:rgba(8,12,28,.52); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
        z-index:2000; display:none; align-items:center; justify-content:center;
        padding:1.5rem; font-family:var(--font);
    }
    .confirm-overlay.active { display:flex; }
    .confirm-card {
        background:var(--surface); border-radius:var(--r-2xl); border:1px solid var(--border);
        box-shadow:var(--shadow-lg); width:100%; max-width:400px; overflow:hidden;
        animation:slideUp .28s cubic-bezier(.34,1.56,.64,1);
    }
    .confirm-body { padding:2rem 2rem 1.5rem; text-align:center; }
    .confirm-icon-wrap {
        width:58px; height:58px; border-radius:50%;
        background:var(--rose-soft); border:2px solid #fecdd3;
        display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem;
    }
    .confirm-icon-wrap svg { width:26px; height:26px; stroke:var(--rose); fill:none; }
    .confirm-title { font-size:1.0625rem; font-weight:800; color:var(--text); margin-bottom:.5rem; }
    .confirm-msg   { font-size:.875rem; color:var(--text-2); line-height:1.6; }
    .confirm-filename { font-weight:700; color:var(--text); font-family:var(--mono); word-break:break-all; }
    .confirm-footer { display:flex; gap:.75rem; padding:1.125rem 1.75rem; border-top:1px solid var(--border); background:var(--surface-2); }
    .confirm-footer button { flex:1; padding:.75rem; border-radius:var(--r-md); font-size:.875rem; font-weight:700; font-family:var(--font); cursor:pointer; border:none; transition:all var(--t); }
    .btn-keep-doc    { background:var(--surface); border:1.5px solid var(--border) !important; color:var(--text-2); }
    .btn-keep-doc:hover { background:var(--surface-3); }
    .btn-del-doc     { background:linear-gradient(135deg,var(--rose),#e11d48); color:white; box-shadow:0 4px 14px rgba(244,63,94,.28); }
    .btn-del-doc:hover { transform:translateY(-1px); box-shadow:0 8px 22px rgba(244,63,94,.35); }
    .btn-del-doc:disabled { opacity:.6; cursor:not-allowed; transform:none; }

    /* ── Responsive ── */
    @media (max-width:1280px) { .kpi-grid { grid-template-columns:repeat(2,1fr); } }
    @media (max-width:900px)  { .page-content { padding:1.25rem; } }
    @media (max-width:768px)  {
        .kpi-grid  { grid-template-columns:1fr; }
        .toolbar   { flex-direction:column; align-items:flex-start; }
        .modal-body{ padding:1.25rem; }
        .modal-footer { padding:1rem 1.25rem; }
        .btn-upload { width:100%; justify-content:center; }
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
                    <h2>Documents</h2>
                    <p>Upload & manage shared documents</p>
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
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    </div>
                    <div>
                        <div class="page-header-title">Documents</div>
                        <div class="page-header-subtitle">Upload, manage and share documents across the portal</div>
                    </div>
                </div>
                <button class="btn-upload" onclick="openUploadModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    Upload Document
                </button>
            </div>

            <!-- KPI Cards -->
            <div class="kpi-grid">
                <div class="kpi-card brand">
                    <div class="kpi-top">
                        <div class="kpi-icon brand">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                    </div>
                    <div class="kpi-value"><?= $totalCount ?></div>
                    <div class="kpi-label">Total Documents</div>
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
                <div class="kpi-card violet">
                    <div class="kpi-top">
                        <div class="kpi-icon violet">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                        </div>
                    </div>
                    <div class="kpi-value" style="font-size:1.5rem;"><?= $storageUsed ?></div>
                    <div class="kpi-label">Storage Used</div>
                </div>
            </div>

            <!-- Status Tabs -->
            <div class="status-tabs">
                <?php
                $tabs = [
                    ['status' => 'all',      'label' => 'All Documents', 'count' => $totalCount,    'pillClass' => ''],
                    ['status' => 'Active',   'label' => 'Active',        'count' => $activeCount,   'pillClass' => 'pill-emerald'],
                    ['status' => 'Inactive', 'label' => 'Inactive',      'count' => $inactiveCount, 'pillClass' => 'pill-gray'],
                ];
                foreach ($tabs as $t):
                    $active = ($statusFilter === $t['status']) ? 'active' : '';
                    $href   = '?status='.urlencode($t['status']).($searchTerm ? '&search='.urlencode($searchTerm) : '');
                ?>
                    <a href="<?= $href ?>" class="status-tab <?= $active ?>">
                        <?= htmlspecialchars($t['label']) ?>
                        <span class="tab-pill <?= $t['pillClass'] ?>"><?= $t['count'] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Toolbar -->
            <div class="toolbar">
                <div class="toolbar-left">
                    <span class="toolbar-title">Document Library</span>
                    <span class="toolbar-count"><?= count($documents) ?> shown</span>
                </div>
                <div class="toolbar-right">
                    <form method="GET" style="display:contents;">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                        <div class="search-wrap">
                            <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                            <input type="text" name="search" class="search-input" placeholder="Search documents…" value="<?= htmlspecialchars($searchTerm) ?>">
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
                            <th>#</th>
                            <th>File</th>
                            <th>Uploaded</th>
                            <th>Size</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($documents)): ?>
                        <tr><td colspan="6">
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                </div>
                                <div class="empty-title">No documents found</div>
                                <div class="empty-sub">Upload your first document to get started.</div>
                            </div>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($documents as $idx => $doc):
                            $typeInfo    = fileTypeInfo($doc['original_name'], $doc['file_type'] ?? '');
                            $statusClass = strtolower($doc['status']) === 'active' ? 's-active' : 's-inactive';
                        ?>
                        <tr>
                            <td><span class="row-num"><?= $idx + 1 ?></span></td>
                            <td>
                                <div class="file-cell">
                                    <div class="file-type-badge" style="background:<?= $typeInfo['bg'] ?>;color:<?= $typeInfo['color'] ?>;border-color:<?= $typeInfo['bg'] ?>;">
                                        <?= $typeInfo['label'] ?>
                                    </div>
                                    <div>
                                        <div class="file-name"><?= htmlspecialchars($doc['original_name']) ?></div>
                                        <?php if ($doc['description']): ?>
                                            <div class="file-desc"><?= htmlspecialchars(mb_substr($doc['description'], 0, 72)) ?><?= mb_strlen($doc['description']) > 72 ? '…' : '' ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="date-main"><?= date('d M Y', strtotime($doc['upload_date'])) ?></div>
                                <div class="date-sub"><?= date('h:i A', strtotime($doc['upload_date'])) ?> · <?= htmlspecialchars($doc['uploaded_by_name'] ?? 'Admin') ?></div>
                            </td>
                            <td><span class="size-badge"><?= formatFileSize($doc['file_size']) ?></span></td>
                            <td>
                                <span class="status-badge <?= $statusClass ?>">
                                    <span class="status-dot"></span><?= htmlspecialchars($doc['status']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="actions-wrap">
                                    <a href="../<?= htmlspecialchars($doc['file_path']) ?>" download class="btn-act dl" title="Download">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    </a>
                                    <button class="btn-act toggle" onclick="toggleStatus(<?= (int)$doc['id'] ?>)" title="Toggle Status">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                                    </button>
                                    <button class="btn-act danger" onclick="confirmDelete(<?= (int)$doc['id'] ?>, '<?= htmlspecialchars($doc['original_name'], ENT_QUOTES) ?>')" title="Delete">
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

<!-- ══════════════════════════════════
     UPLOAD DOCUMENT MODAL
══════════════════════════════════ -->
<div class="modal-overlay" id="uploadModal">
    <div class="modal-panel" style="max-width:560px;">
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon brand">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                </div>
                <div>
                    <div class="modal-title">Upload Document</div>
                    <div class="modal-subtitle">Add a new document to the library</div>
                </div>
            </div>
            <button type="button" class="btn-close" onclick="closeUploadModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <form id="uploadForm" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="action" value="upload">
            <div class="modal-body">

                <!-- Drop zone -->
                <div class="form-section">
                    <label class="field-label">Select File <span class="required-star">*</span></label>
                    <div class="dropzone" id="dropzone">
                        <input type="file" id="document" name="document" required
                               accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.jpeg,.png"
                               onchange="handleFileSelect(this)">
                        <div class="dropzone-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        </div>
                        <div class="dropzone-title">Drop your file here</div>
                        <div class="dropzone-sub">or <strong>browse to choose</strong> a file</div>
                    </div>
                    <!-- File preview -->
                    <div class="file-preview" id="filePreview">
                        <div class="file-preview-icon" id="filePreviewBadge">DOC</div>
                        <div class="file-preview-info">
                            <div class="file-preview-name" id="filePreviewName">—</div>
                            <div class="file-preview-size" id="filePreviewSize">—</div>
                        </div>
                        <button type="button" class="file-preview-remove" onclick="removeFile()" title="Remove file">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <span class="field-hint" style="margin-top:.75rem;">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                        PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, TXT, JPG, PNG — max 50 MB
                    </span>
                </div>

                <!-- Description -->
                <div class="form-section">
                    <div class="form-field">
                        <label class="field-label" for="description">Description</label>
                        <textarea id="description" name="description" rows="3" placeholder="Brief description of this document…"></textarea>
                    </div>
                </div>

            </div><!-- /modal-body -->
            <div class="modal-footer">
                <button type="button" class="modal-btn modal-btn-ghost" onclick="closeUploadModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    Cancel
                </button>
                <button type="submit" class="modal-btn modal-btn-primary" id="uploadBtn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <span id="uploadBtnText">Upload Document</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════
     DELETE CONFIRM DIALOG
══════════════════════════════════ -->
<div class="confirm-overlay" id="deleteOverlay">
    <div class="confirm-card">
        <div class="confirm-body">
            <div class="confirm-icon-wrap">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            </div>
            <div class="confirm-title">Delete Document?</div>
            <div class="confirm-msg">
                You're about to permanently delete<br>
                <span class="confirm-filename" id="deleteFileName"></span><br>
                This action cannot be undone.
            </div>
        </div>
        <div class="confirm-footer">
            <button class="btn-keep-doc" onclick="closeDeleteDialog()">Keep File</button>
            <button class="btn-del-doc" id="confirmDeleteBtn">Delete</button>
        </div>
    </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
/* ════════════════════════════════════
   DOCUMENTS PAGE SCRIPTS
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
        btn.innerHTML = '<svg style="width:14px;height:14px;animation:spin .8s linear infinite;fill:none;stroke:currentColor;stroke-width:2;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> Uploading…';
    } else {
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}

/* ── File size formatter (JS) ── */
function fmtSize(bytes) {
    if (bytes >= 1073741824) return (bytes/1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576)    return (bytes/1048576).toFixed(2)    + ' MB';
    if (bytes >= 1024)       return (bytes/1024).toFixed(2)       + ' KB';
    return bytes + ' B';
}

/* ── File type label ── */
function fileLabel(name) {
    var ext = name.split('.').pop().toUpperCase();
    var map = { PDF:'PDF', DOC:'DOC', DOCX:'DOC', XLS:'XLS', XLSX:'XLS', PPT:'PPT', PPTX:'PPT', TXT:'TXT', JPG:'IMG', JPEG:'IMG', PNG:'IMG' };
    return map[ext] || ext;
}

/* ── Dropzone: drag events ── */
var dz = document.getElementById('dropzone');
['dragenter','dragover'].forEach(function(ev) {
    dz.addEventListener(ev, function(e) { e.preventDefault(); dz.classList.add('drag-over'); });
});
['dragleave','drop'].forEach(function(ev) {
    dz.addEventListener(ev, function(e) { e.preventDefault(); dz.classList.remove('drag-over'); });
});
dz.addEventListener('drop', function(e) {
    var files = e.dataTransfer.files;
    if (files.length) {
        document.getElementById('document').files = files;
        handleFileSelect(document.getElementById('document'));
    }
});

/* ── File selected ── */
function handleFileSelect(input) {
    if (!input.files || !input.files.length) return;
    var f = input.files[0];
    document.getElementById('filePreviewName').textContent  = f.name;
    document.getElementById('filePreviewSize').textContent  = fmtSize(f.size);
    document.getElementById('filePreviewBadge').textContent = fileLabel(f.name);
    document.getElementById('filePreview').classList.add('show');
}

function removeFile() {
    document.getElementById('document').value = '';
    document.getElementById('filePreview').classList.remove('show');
}

/* ── Upload modal ── */
function openUploadModal() {
    document.getElementById('uploadForm').reset();
    document.getElementById('filePreview').classList.remove('show');
    document.getElementById('uploadModal').classList.add('active');
}
function closeUploadModal() {
    document.getElementById('uploadModal').classList.remove('active');
}

/* Upload form submit */
document.getElementById('uploadForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var fileInput = document.getElementById('document');
    if (!fileInput.files.length) {
        showToast('Please select a file to upload', 'error');
        return;
    }
    var btn  = document.getElementById('uploadBtn');
    var orig = btn.innerHTML;
    setLoading(btn, true, orig);
    try {
        var fd  = new FormData(this);
        var res = await fetch(PAGE_URL, { method: 'POST', body: fd });
        var data = await res.json();
        if (data.success) {
            showToast(data.message, 'success');
            closeUploadModal();
            setTimeout(function() { location.reload(); }, 900);
        } else {
            showToast('Error: ' + (data.message || 'Upload failed'), 'error');
            setLoading(btn, false, orig);
        }
    } catch (err) {
        console.error(err);
        showToast('Unexpected error. Please try again.', 'error');
        setLoading(btn, false, orig);
    }
});

/* ── Toggle status ── */
async function toggleStatus(id) {
    try {
        var data = await postAction({ action: 'toggle_status', id: id });
        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(function() { location.reload(); }, 700);
        } else {
            showToast('Error: ' + (data.message || 'Could not update status'), 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('Unexpected error.', 'error');
    }
}

/* ── Delete confirm ── */
var _deleteId = null;

function confirmDelete(id, name) {
    _deleteId = id;
    document.getElementById('deleteFileName').textContent = name;
    document.getElementById('deleteOverlay').classList.add('active');
}
function closeDeleteDialog() {
    _deleteId = null;
    document.getElementById('deleteOverlay').classList.remove('active');
}

document.getElementById('confirmDeleteBtn').addEventListener('click', async function() {
    if (!_deleteId) return;
    var btn  = this;
    var orig = btn.innerHTML;
    btn.disabled = true;
    btn.textContent = 'Deleting…';
    try {
        var data = await postAction({ action: 'delete', id: _deleteId });
        closeDeleteDialog();
        if (data.success) {
            showToast('Document deleted successfully', 'success');
            setTimeout(function() { location.reload(); }, 900);
        } else {
            showToast('Error: ' + (data.message || 'Could not delete'), 'error');
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    } catch (err) {
        console.error(err);
        showToast('Unexpected error.', 'error');
        btn.disabled = false;
        btn.innerHTML = orig;
    }
});

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