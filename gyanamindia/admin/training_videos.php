<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';
requireLogin(['Admin']);
$pdo = getDBConnection();
$userName = sanitize(getUserName());
$msg = '';

// AJAX upload handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_video') {
            $title = trim($_POST['title'] ?? '');
            $type  = $_POST['video_type'] ?? 'youtube';
            $url   = trim($_POST['video_url'] ?? '');
            $desc  = trim($_POST['description'] ?? '');
            $path  = null;
            if ($type === 'upload' && isset($_FILES['video_file']) && $_FILES['video_file']['error'] === 0) {
                $ext = strtolower(pathinfo($_FILES['video_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['mp4','webm','mov'])) {
                    $fname = 'vid_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;
                    $dest  = __DIR__ . '/../uploads/training_videos/' . $fname;
                    move_uploaded_file($_FILES['video_file']['tmp_name'], $dest);
                    $path = 'uploads/training_videos/' . $fname;
                } else { echo json_encode(['success'=>false,'message'=>'Invalid file type']); exit; }
            }
            if (!$title) { echo json_encode(['success'=>false,'message'=>'Title required']); exit; }
            $pdo->prepare("INSERT INTO training_videos (title,description,video_type,video_url,video_path,uploaded_by) VALUES (?,?,?,?,?,?)")
                ->execute([$title,$desc,$type,$url?:null,$path,getUserId()]);
            $vid = $pdo->lastInsertId();
            // Auto-assign if ATCs selected
            $atcs = $_POST['assign_atcs'] ?? [];
            $start = $_POST['access_start'] ?? date('Y-m-d');
            $end   = $_POST['access_end'] ?? date('Y-m-d', strtotime('+30 days'));
            if (!empty($atcs)) {
                $stmt = $pdo->prepare("INSERT INTO video_assignments (video_id,atc_id,access_start,access_end,assigned_by) VALUES (?,?,?,?,?)");
                foreach ($atcs as $a) {
                    $stmt->execute([$vid, $a === 'all' ? null : (int)$a, $start, $end, getUserId()]);
                }
            }
            echo json_encode(['success'=>true,'message'=>'Video added & assigned!']); exit;
        }
        if ($action === 'delete_video') {
            $pdo->prepare("DELETE FROM training_videos WHERE id=?")->execute([(int)$_POST['video_id']]);
            echo json_encode(['success'=>true,'message'=>'Deleted']); exit;
        }
        if ($action === 'assign') {
            $vid=$_POST['video_id']; $atcId=$_POST['atc_id']==='all'?null:(int)$_POST['atc_id'];
            $pdo->prepare("INSERT INTO video_assignments (video_id,atc_id,access_start,access_end,assigned_by) VALUES (?,?,?,?,?)")
                ->execute([$vid,$atcId,$_POST['access_start'],$_POST['access_end'],getUserId()]);
            echo json_encode(['success'=>true,'message'=>'Assigned!']); exit;
        }
        if ($action === 'delete_assignment') {
            $pdo->prepare("DELETE FROM video_assignments WHERE id=?")->execute([(int)$_POST['assignment_id']]);
            echo json_encode(['success'=>true,'message'=>'Removed']); exit;
        }
        if ($action === 'extend') {
            $pdo->prepare("UPDATE video_assignments SET access_end=? WHERE id=?")->execute([$_POST['new_end'],(int)$_POST['assignment_id']]);
            echo json_encode(['success'=>true,'message'=>'Extended!']); exit;
        }
    } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit; }
    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}

$videos = $pdo->query("SELECT v.*, (SELECT COUNT(*) FROM video_assignments WHERE video_id=v.id) AS assign_count FROM training_videos v ORDER BY v.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$assignments = $pdo->query("SELECT va.*, tv.title AS video_title, atc.name AS atc_name FROM video_assignments va JOIN training_videos tv ON tv.id=va.video_id LEFT JOIN atc_centers atc ON atc.id=va.atc_id ORDER BY va.access_end DESC")->fetchAll(PDO::FETCH_ASSOC);
$atcList = $pdo->query("SELECT id,name FROM atc_centers WHERE status='Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$totalV = count($videos); $totalA = count($assignments);
$activeA = count(array_filter($assignments, fn($a) => $a['access_start'] <= date('Y-m-d') && $a['access_end'] >= date('Y-m-d')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Training Videos — Admin | Gyanam India</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/notifications.css">
<style>
:root {
    --font:'Sora',sans-serif; --bg:#f4f6fb; --surface:#ffffff; --border:#e6eaf3; --border-2:#d4dae8;
    --text:#111827; --text-2:#374151; --text-3:#6b7280; --text-4:#9ca3af;
    --brand:#4361ee; --brand-dark:#3451d1; --brand-light:#eef1fd; --brand-glow:rgba(67,97,238,.18);
    --violet:#7c3aed; --violet-soft:#f5f3ff; --emerald:#10b981; --emerald-dark:#059669; --emerald-soft:#ecfdf5;
    --amber:#f59e0b; --amber-dark:#d97706; --amber-soft:#fffbeb; --rose:#f43f5e; --rose-soft:#fff1f3;
    --sky:#0ea5e9; --sky-soft:#f0f9ff;
    --shadow-sm:0 1px 4px rgba(0,0,0,.06), 0 2px 8px rgba(0,0,0,.04);
    --shadow-md:0 4px 16px rgba(0,0,0,.08), 0 2px 6px rgba(0,0,0,.04);
    --r-md:10px; --r-lg:14px; --r-xl:18px; --r-full:9999px; --t:.18s ease;
}

/* ── Toast ── */
.tv-toast { position:fixed; top:1.25rem; right:1.25rem; z-index:9999; display:flex; align-items:center; gap:.75rem;
    padding:.875rem 1.25rem; border-radius:var(--r-lg); min-width:280px; max-width:400px;
    background:var(--surface); border:1px solid var(--border); box-shadow:0 20px 60px rgba(0,0,0,.12);
    font-size:.875rem; font-weight:600; animation:toastIn .3s cubic-bezier(.34,1.56,.64,1); }
.tv-toast.ok { border-left:4px solid var(--emerald); }
.tv-toast.err { border-left:4px solid var(--rose); }
.tv-toast .ti { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:.9rem; }
.tv-toast.ok .ti { background:var(--emerald-soft); }
.tv-toast.err .ti { background:var(--rose-soft); }
@keyframes toastIn { from{opacity:0;transform:translateX(30px) scale(.95)} to{opacity:1;transform:translateX(0) scale(1)} }

/* ── Page header ── */
.page-hero { display:flex; align-items:center; gap:1rem; margin-bottom:1.75rem; }
.page-hero-icon { width:50px; height:50px; border-radius:var(--r-lg); background:linear-gradient(135deg,var(--brand),var(--violet));
    display:flex; align-items:center; justify-content:center; box-shadow:0 6px 20px var(--brand-glow); flex-shrink:0; }
.page-hero-icon svg { width:24px; height:24px; stroke:#fff; fill:none; }
.page-hero-title { font-size:1.375rem; font-weight:800; color:var(--text); letter-spacing:-.03em; }
.page-hero-sub { font-size:.8125rem; color:var(--text-3); margin-top:.15rem; }

/* ── KPI Cards ── */
.kpi-row { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; margin-bottom:1.75rem; }
.kpi { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-xl); padding:1.25rem 1.5rem;
    position:relative; overflow:hidden; box-shadow:var(--shadow-sm); transition:transform var(--t), box-shadow var(--t); display:flex; align-items:center; gap:1rem; }
.kpi:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); }
.kpi::before { content:''; position:absolute; top:0; left:0; bottom:0; width:4px; border-radius:var(--r-xl) 0 0 var(--r-xl); }
.kpi.c-brand::before { background:linear-gradient(180deg,var(--brand),#818cf8); }
.kpi.c-emerald::before { background:linear-gradient(180deg,var(--emerald),#34d399); }
.kpi.c-amber::before { background:linear-gradient(180deg,var(--amber),#fcd34d); }
.kpi.c-violet::before { background:linear-gradient(180deg,var(--violet),#a78bfa); }
.kpi-icon { width:42px; height:42px; border-radius:var(--r-md); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.kpi-icon svg { width:20px; height:20px; fill:none; }
.kpi-icon.brand { background:var(--brand-light); } .kpi-icon.brand svg { stroke:var(--brand); }
.kpi-icon.emerald { background:var(--emerald-soft); } .kpi-icon.emerald svg { stroke:var(--emerald-dark); }
.kpi-icon.amber { background:var(--amber-soft); } .kpi-icon.amber svg { stroke:var(--amber-dark); }
.kpi-icon.violet { background:var(--violet-soft); } .kpi-icon.violet svg { stroke:var(--violet); }
.kpi-val { font-size:1.75rem; font-weight:900; color:var(--text); letter-spacing:-.05em; line-height:1; }
.kpi-lbl { font-size:.7rem; font-weight:700; color:var(--text-4); text-transform:uppercase; letter-spacing:.06em; margin-top:.3rem; }

/* ── Section Headers ── */
.tv-section { display:flex; align-items:center; gap:.5rem; font-size:.75rem; font-weight:800; letter-spacing:.08em;
    text-transform:uppercase; color:var(--text-3); margin:2rem 0 1rem; padding-bottom:.5rem;
    border-bottom:2px solid var(--border); }
.tv-section svg { width:16px; height:16px; stroke:currentColor; fill:none; }

/* ── Form Cards ── */
.tv-form { background:var(--surface); border:1.5px solid var(--border); border-radius:var(--r-xl); padding:1.5rem 1.75rem;
    margin-bottom:1.5rem; box-shadow:var(--shadow-sm); }
.tv-form label { display:block; font-size:.8rem; font-weight:700; color:var(--text-2); margin-bottom:.35rem; margin-top:.85rem; }
.tv-form label:first-child { margin-top:0; }
.tv-form input, .tv-form select, .tv-form textarea {
    width:100%; padding:.65rem .9rem; border:1.5px solid var(--border); border-radius:var(--r-md);
    font-family:var(--font); font-size:.875rem; font-weight:500; background:#fafbfd; color:var(--text);
    outline:none; transition:border-color var(--t), box-shadow var(--t); }
.tv-form input:focus, .tv-form select:focus, .tv-form textarea:focus { border-color:var(--brand); box-shadow:0 0 0 3px var(--brand-glow); background:#fff; }
.tv-form textarea { height:70px; resize:vertical; }
.tv-form select { cursor:pointer; appearance:none; -webkit-appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right .7rem center; background-size:14px; padding-right:2.25rem; }
.tv-row { display:flex; gap:1rem; flex-wrap:wrap; } .tv-row > * { flex:1; min-width:200px; }

/* ── Buttons ── */
.tv-btn { display:inline-flex; align-items:center; gap:.4rem; padding:.65rem 1.35rem; border:none; border-radius:var(--r-md);
    font-weight:700; font-family:var(--font); font-size:.85rem; cursor:pointer; margin-top:.85rem; transition:all var(--t); }
.tv-btn-primary { background:linear-gradient(135deg,var(--brand),var(--brand-dark)); color:#fff; box-shadow:0 4px 14px var(--brand-glow); }
.tv-btn-primary:hover { transform:translateY(-2px); box-shadow:0 8px 24px var(--brand-glow); }
.tv-btn-primary:disabled { opacity:.5; cursor:not-allowed; transform:none; }
.tv-btn-danger { background:var(--rose-soft); color:var(--rose); border:1.5px solid #fecdd3; font-size:.75rem; padding:.35rem .75rem; margin:0;
    border-radius:var(--r-md); font-weight:700; font-family:var(--font); cursor:pointer; transition:all var(--t); }
.tv-btn-danger:hover { background:#fee2e2; border-color:#fca5a5; }
.tv-btn-amber { background:var(--amber-soft); color:var(--amber-dark); border:1.5px solid #fde68a; font-size:.75rem; padding:.35rem .75rem; margin:0;
    border-radius:var(--r-md); font-weight:700; font-family:var(--font); cursor:pointer; transition:all var(--t); }
.tv-btn-amber:hover { background:#fef3c7; border-color:#fcd34d; }

/* ── Upload Progress ── */
.upload-progress { display:none; margin-top:.85rem; padding:.75rem 1rem; background:linear-gradient(135deg,#eef1fd,#f5f3ff);
    border:1.5px solid #ddd6fe; border-radius:var(--r-md); }
.prog-outer { height:10px; background:#e0e7ff; border-radius:var(--r-full); overflow:hidden; }
.prog-inner { height:100%; background:linear-gradient(90deg,var(--brand),var(--violet),#818cf8); border-radius:var(--r-full);
    transition:width .25s ease; width:0; box-shadow:0 0 12px var(--brand-glow); }
.prog-meta { display:flex; justify-content:space-between; margin-top:.4rem; }
.prog-text { font-size:.75rem; font-weight:800; color:var(--brand); }
.prog-status { font-size:.72rem; font-weight:600; color:var(--text-3); }

/* ── ATC Chips ── */
.atc-chips { display:flex; flex-wrap:wrap; gap:.4rem; margin-top:.4rem; max-height:130px; overflow-y:auto; padding:.4rem 0; }
.atc-chip { display:inline-flex; align-items:center; gap:.3rem; padding:.3rem .7rem; border-radius:var(--r-full);
    font-size:.75rem; font-weight:700; cursor:pointer; border:1.5px solid var(--border); background:#fafbfd;
    color:var(--text-2); transition:all .15s ease; user-select:none; }
.atc-chip:hover { border-color:#93c5fd; background:var(--sky-soft); color:var(--sky); }
.atc-chip.selected { background:var(--brand); color:#fff; border-color:var(--brand); box-shadow:0 2px 8px var(--brand-glow); }
.atc-chip.selected::before { content:'✓ '; font-size:.65rem; }
.atc-chip.all-chip.selected { background:var(--violet); border-color:var(--violet); box-shadow:0 2px 8px rgba(124,58,237,.2); }

/* ── Tables ── */
.tv-table-wrap { background:var(--surface); border:1.5px solid var(--border); border-radius:var(--r-xl); overflow:hidden;
    margin-bottom:1.5rem; box-shadow:var(--shadow-sm); }
.tv-table { width:100%; border-collapse:collapse; font-size:.875rem; }
.tv-table thead { background:linear-gradient(180deg,#f8fafc,#f1f5f9); }
.tv-table th { padding:.8rem 1.125rem; text-align:left; font-size:.68rem; font-weight:800; color:var(--text-4);
    text-transform:uppercase; letter-spacing:.07em; border-bottom:1.5px solid var(--border); white-space:nowrap; }
.tv-table td { padding:.85rem 1.125rem; border-bottom:1px solid #f3f5f9; vertical-align:middle; }
.tv-table tbody tr:last-child td { border-bottom:none; }
.tv-table tbody tr { transition:background var(--t); }
.tv-table tbody tr:hover { background:#fafbff; }

/* ── Badges ── */
.badge-active { background:var(--emerald-soft); color:var(--emerald-dark); padding:.25rem .65rem; border-radius:var(--r-full);
    font-size:.72rem; font-weight:800; border:1px solid #a7f3d0; display:inline-flex; align-items:center; gap:.25rem; }
.badge-active::before { content:''; width:6px; height:6px; border-radius:50%; background:var(--emerald); }
.badge-expired { background:var(--rose-soft); color:#be123c; padding:.25rem .65rem; border-radius:var(--r-full);
    font-size:.72rem; font-weight:800; border:1px solid #fecdd3; display:inline-flex; align-items:center; gap:.25rem; }
.badge-expired::before { content:''; width:6px; height:6px; border-radius:50%; background:var(--rose); }
.badge-upcoming { background:#eff6ff; color:#1d4ed8; padding:.25rem .65rem; border-radius:var(--r-full);
    font-size:.72rem; font-weight:800; border:1px solid #bfdbfe; display:inline-flex; align-items:center; gap:.25rem; }
.badge-upcoming::before { content:''; width:6px; height:6px; border-radius:50%; background:var(--sky); }
.badge-type { background:var(--brand-light); color:var(--brand); padding:.2rem .6rem; border-radius:var(--r-md); font-size:.72rem; font-weight:700; border:1px solid #c7d2fe; }
.badge-count { background:#f3f4f6; color:var(--text-2); padding:.2rem .6rem; border-radius:var(--r-md); font-size:.72rem; font-weight:700; border:1px solid var(--border); }

/* ── Responsive ── */
@media (max-width:1280px) { .kpi-row { grid-template-columns:repeat(2,1fr); } }
@media (max-width:768px) { .kpi-row { grid-template-columns:1fr; } .tv-row { flex-direction:column; } .page-hero { flex-wrap:wrap; } }
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
<header class="top-header">
    <div class="header-left">
        <button class="hamburger" id="hamburgerBtn"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
        <div class="header-greeting"><h2>Training Videos</h2><p>Upload, assign and manage training content</p></div>
    </div>
    <div class="header-right"><?php include __DIR__.'/../includes/notification_bell.php'; ?><?php include __DIR__.'/../includes/profile_dropdown.php'; ?></div>
</header>
<div class="page-content">

<!-- Page Hero -->
<div class="page-hero">
    <div class="page-hero-icon">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
    </div>
    <div>
        <h1 class="page-hero-title">Training Videos</h1>
        <p class="page-hero-sub">Upload, assign and manage training content for ATC centers</p>
    </div>
</div>

<!-- KPIs -->
<div class="kpi-row">
    <div class="kpi c-brand">
        <div class="kpi-icon brand"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg></div>
        <div><div class="kpi-val"><?=$totalV?></div><div class="kpi-lbl">Total Videos</div></div>
    </div>
    <div class="kpi c-emerald">
        <div class="kpi-icon emerald"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>
        <div><div class="kpi-val"><?=$activeA?></div><div class="kpi-lbl">Active Assigns</div></div>
    </div>
    <div class="kpi c-amber">
        <div class="kpi-icon amber"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
        <div><div class="kpi-val"><?=$totalA?></div><div class="kpi-lbl">Total Assigns</div></div>
    </div>
    <div class="kpi c-violet">
        <div class="kpi-icon violet"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg></div>
        <div><div class="kpi-val"><?=count($atcList)?></div><div class="kpi-lbl">ATC Centers</div></div>
    </div>
</div>

<!-- ADD VIDEO -->
<div class="tv-section">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add New Video & Assign
</div>
<form id="addVideoForm" class="tv-form" enctype="multipart/form-data">
    <div class="tv-row">
        <div><label>Title *</label><input type="text" name="title" id="vTitle" required placeholder="e.g. Module 1 - Introduction"></div>
        <div><label>Type</label>
            <select name="video_type" id="vType" onchange="toggleType()">
                <option value="youtube">YouTube Link</option>
                <option value="link">External Link</option>
                <option value="upload">Upload MP4</option>
            </select>
        </div>
    </div>
    <div id="fUrl"><label>Video URL</label><input type="url" name="video_url" id="vUrl" placeholder="https://youtube.com/watch?v=..."></div>
    <div id="fUpload" style="display:none"><label>Video File (MP4/WebM, max 500MB)</label><input type="file" name="video_file" id="vFile" accept=".mp4,.webm,.mov"></div>
    <div class="upload-progress" id="uploadProgress">
        <div class="prog-outer"><div class="prog-inner" id="progBar"></div></div>
        <div class="prog-meta"><span class="prog-status" id="progStatus">Uploading...</span><span class="prog-text" id="progText">0%</span></div>
    </div>
    <label>Description</label><textarea name="description" placeholder="Optional description..."></textarea>

    <label>Assign to ATCs <span style="font-weight:400;color:var(--text-4)">(click to select)</span></label>
    <div class="atc-chips" id="atcChips">
        <span class="atc-chip all-chip" data-id="all" onclick="toggleChip(this)">🌐 All ATCs</span>
        <?php foreach($atcList as $a): ?>
        <span class="atc-chip" data-id="<?=$a['id']?>" onclick="toggleChip(this)"><?=htmlspecialchars($a['name'])?></span>
        <?php endforeach; ?>
    </div>

    <div class="tv-row">
        <div><label>Access Start</label><input type="date" name="access_start" value="<?=date('Y-m-d')?>"></div>
        <div><label>Valid Till</label><input type="date" name="access_end" value="<?=date('Y-m-d',strtotime('+30 days'))?>"></div>
    </div>
    <button type="submit" class="tv-btn tv-btn-primary" id="addBtn">🎬 Add & Assign Video</button>
</form>

<!-- VIDEOS LIST -->
<div class="tv-section">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
    All Videos (<?=$totalV?>)
</div>
<div class="tv-table-wrap"><table class="tv-table">
<thead><tr><th>#</th><th>Title</th><th>Type</th><th>Source</th><th>Assigns</th><th>Added</th><th></th></tr></thead>
<tbody>
<?php if(empty($videos)):?><tr><td colspan="7" style="text-align:center;padding:2.5rem;color:var(--text-4)">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:36px;height:36px;margin-bottom:.5rem;opacity:.4"><polygon points="5 3 19 12 5 21 5 3"/></svg><br>No videos uploaded yet
</td></tr>
<?php else: foreach($videos as $i=>$v):?>
<tr>
    <td style="color:var(--text-4);font-weight:600"><?=$i+1?></td>
    <td style="font-weight:800;color:var(--text)"><?=htmlspecialchars($v['title'])?></td>
    <td><span class="badge-type"><?=ucfirst($v['video_type'])?></span></td>
    <td style="font-size:.8rem;color:var(--text-3);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($v['video_url']?:$v['video_path']?:'—')?></td>
    <td><span class="badge-count"><?=$v['assign_count']?> ATC<?=$v['assign_count']!=1?'s':''?></span></td>
    <td style="font-size:.8rem;color:var(--text-4);white-space:nowrap"><?=date('d M Y',strtotime($v['created_at']))?></td>
    <td><button class="tv-btn-danger" onclick="deleteVideo(<?=$v['id']?>)">Delete</button></td>
</tr>
<?php endforeach; endif;?>
</tbody></table></div>

<!-- QUICK ASSIGN -->
<?php if(!empty($videos)):?>
<div class="tv-section">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>
    Quick Assign Existing Video
</div>
<form id="assignForm" class="tv-form">
    <div class="tv-row">
        <div><label>Video *</label><select name="video_id" required><option value="">— Select Video —</option><?php foreach($videos as $v):?><option value="<?=$v['id']?>"><?=htmlspecialchars($v['title'])?></option><?php endforeach;?></select></div>
        <div><label>ATC Center</label><select name="atc_id"><option value="all">🌐 All ATCs</option><?php foreach($atcList as $a):?><option value="<?=$a['id']?>"><?=htmlspecialchars($a['name'])?></option><?php endforeach;?></select></div>
    </div>
    <div class="tv-row">
        <div><label>Access Start</label><input type="date" name="access_start" value="<?=date('Y-m-d')?>"></div>
        <div><label>Valid Till</label><input type="date" name="access_end" value="<?=date('Y-m-d',strtotime('+30 days'))?>"></div>
    </div>
    <button type="submit" class="tv-btn tv-btn-primary">📌 Assign Video</button>
</form>
<?php endif;?>

<!-- ASSIGNMENTS -->
<div class="tv-section">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    All Assignments (<?=$totalA?>)
</div>
<div class="tv-table-wrap"><table class="tv-table">
<thead><tr><th>Video</th><th>ATC Center</th><th>Start</th><th>Valid Till</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php if(empty($assignments)):?><tr><td colspan="6" style="text-align:center;padding:2.5rem;color:var(--text-4)">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:36px;height:36px;margin-bottom:.5rem;opacity:.4"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg><br>No assignments yet
</td></tr>
<?php else: foreach($assignments as $a):
    $now=date('Y-m-d');
    if($now<$a['access_start']){$stl='badge-upcoming';$stx='Upcoming';}
    elseif($now>$a['access_end']){$stl='badge-expired';$stx='Expired';}
    else{$stl='badge-active';$stx='Active';}
?>
<tr>
    <td style="font-weight:700;color:var(--text)"><?=htmlspecialchars($a['video_title'])?></td>
    <td><?=$a['atc_name']?htmlspecialchars($a['atc_name']):'<span style="color:var(--violet);font-weight:700">All ATCs</span>'?></td>
    <td style="font-size:.8rem;color:var(--text-3)"><?=date('d M Y',strtotime($a['access_start']))?></td>
    <td style="font-size:.8rem;color:var(--text-3)"><?=date('d M Y',strtotime($a['access_end']))?></td>
    <td><span class="<?=$stl?>"><?=$stx?></span></td>
    <td style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">
        <form class="extendForm" style="display:inline-flex;gap:.3rem;align-items:center">
            <input type="hidden" name="assignment_id" value="<?=$a['id']?>">
            <input type="date" name="new_end" value="<?=$a['access_end']?>" style="padding:.3rem .5rem;border:1.5px solid var(--border);border-radius:8px;font-size:.78rem;width:135px;font-family:var(--font)">
            <button type="submit" class="tv-btn-amber">Extend</button>
        </form>
        <button class="tv-btn-danger" onclick="deleteAssignment(<?=$a['id']?>)">Remove</button>
    </td>
</tr>
<?php endforeach; endif;?>
</tbody></table></div>

</div></main></div>
<script src="../assets/js/dashboard.js"></script>
<script>
function toast(msg,ok=true){const d=document.createElement('div');d.className='tv-toast '+(ok?'ok':'err');
    d.innerHTML='<div class="ti">'+(ok?'✅':'❌')+'</div><span>'+msg+'</span>';document.body.appendChild(d);setTimeout(()=>d.remove(),3500)}
function toggleType(){const t=document.getElementById('vType').value;document.getElementById('fUpload').style.display=t==='upload'?'block':'none';document.getElementById('fUrl').style.display=t!=='upload'?'block':'none'}
function toggleChip(el){
    if(el.dataset.id==='all'){
        const wasSelected=el.classList.contains('selected');
        document.querySelectorAll('.atc-chip').forEach(c=>c.classList.remove('selected'));
        if(!wasSelected)el.classList.add('selected');
    } else {
        document.querySelector('.atc-chip.all-chip')?.classList.remove('selected');
        el.classList.toggle('selected');
    }
}

// Add video with XHR progress
document.getElementById('addVideoForm').addEventListener('submit', function(e){
    e.preventDefault();
    const btn=document.getElementById('addBtn');
    const fd=new FormData(this);
    fd.append('ajax','1'); fd.append('action','add_video');
    document.querySelectorAll('.atc-chip.selected').forEach(c=>fd.append('assign_atcs[]',c.dataset.id));

    const xhr=new XMLHttpRequest();
    const progWrap=document.getElementById('uploadProgress');
    const progBar=document.getElementById('progBar');
    const progText=document.getElementById('progText');
    const progStatus=document.getElementById('progStatus');
    const isUpload=document.getElementById('vType').value==='upload';

    if(isUpload){progWrap.style.display='block';progBar.style.width='0';progText.textContent='0%';progStatus.textContent='Uploading...'}
    btn.disabled=true; btn.textContent='⏳ Uploading...';

    xhr.upload.addEventListener('progress',function(e){
        if(e.lengthComputable){
            const pct=Math.round(e.loaded/e.total*100);
            progBar.style.width=pct+'%';
            progText.textContent=pct+'%';
            const mb=(e.loaded/1048576).toFixed(1);
            const totalMb=(e.total/1048576).toFixed(1);
            progStatus.textContent=pct>=100?'Processing on server...':mb+'MB / '+totalMb+'MB';
            if(pct>=100)btn.textContent='⚙️ Processing...';
        }
    });
    xhr.onload=function(){
        btn.disabled=false;btn.textContent='🎬 Add & Assign Video';progWrap.style.display='none';
        try{const r=JSON.parse(xhr.responseText);toast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),800)}
        catch(e){toast('Upload failed',false)}
    };
    xhr.onerror=function(){btn.disabled=false;btn.textContent='🎬 Add & Assign Video';progWrap.style.display='none';toast('Network error',false)};
    xhr.open('POST','');xhr.send(fd);
});

// Quick assign form
document.getElementById('assignForm')?.addEventListener('submit', async function(e){
    e.preventDefault();
    const fd=new FormData(this);fd.append('ajax','1');fd.append('action','assign');
    const r=await(await fetch('',{method:'POST',body:fd})).json();
    toast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),800);
});

// Extend forms
document.querySelectorAll('.extendForm').forEach(f=>f.addEventListener('submit', async function(e){
    e.preventDefault();
    const fd=new FormData(this);fd.append('ajax','1');fd.append('action','extend');
    const r=await(await fetch('',{method:'POST',body:fd})).json();
    toast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),800);
}));

async function deleteVideo(id){if(!confirm('Delete video & all assignments?'))return;
    const fd=new FormData();fd.append('ajax','1');fd.append('action','delete_video');fd.append('video_id',id);
    const r=await(await fetch('',{method:'POST',body:fd})).json();toast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),800)}
async function deleteAssignment(id){if(!confirm('Remove assignment?'))return;
    const fd=new FormData();fd.append('ajax','1');fd.append('action','delete_assignment');fd.append('assignment_id',id);
    const r=await(await fetch('',{method:'POST',body:fd})).json();toast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),800)}
</script>
</body></html>
