<?php
/**
 * Gyanam Portal - Admin: Dashboard Banners
 * Manage global announcements (images) shown on ATC & DLC dashboards.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo = getDBConnection();
$message = '';
$error = '';

$uploadDir = __DIR__ . '/../uploads/announcements/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}

// Handle Forms
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        $title          = trim(sanitize($_POST['title'] ?? ''));
        $targetAudience = sanitize($_POST['target_audience'] ?? 'All');
        $status         = sanitize($_POST['status'] ?? 'Active');
        $orientation    = sanitize($_POST['orientation'] ?? 'horizontal');

        $allowedImg   = ['jpg','jpeg','png','gif','webp'];
        $allowedVideo = ['mp4','webm','ogg'];

        if (empty($title)) {
            $error = 'Title is required.';
        } elseif (!isset($_FILES['banner_image']) || $_FILES['banner_image']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please select a valid file (image or video).';
        } else {
            $fileInfo = pathinfo($_FILES['banner_image']['name']);
            $ext = strtolower($fileInfo['extension']);
            if (!in_array($ext, array_merge($allowedImg, $allowedVideo))) {
                $error = 'Unsupported file type. Allowed: JPG, PNG, WEBP, GIF, MP4, WEBM.';
            } else {
                $uniqueName = 'banner_' . time() . '_' . uniqid() . '.' . $ext;
                $targetFile = $uploadDir . $uniqueName;
                // Auto-detect orientation for images
                if (in_array($ext, $allowedImg) && $orientation === 'auto') {
                    $dim = @getimagesize($_FILES['banner_image']['tmp_name']);
                    $orientation = ($dim && $dim[1] > $dim[0]) ? 'vertical' : 'horizontal';
                }
                if (move_uploaded_file($_FILES['banner_image']['tmp_name'], $targetFile)) {
                    $stmt = $pdo->prepare("INSERT INTO announcements (title, image_path, target_audience, status, orientation) VALUES (?, ?, ?, ?, ?)");
                    if ($stmt->execute([$title, $uniqueName, $targetAudience, $status, $orientation])) {
                        $message = 'Banner uploaded successfully!';
                    } else {
                        $error = 'Database error while saving banner info.';
                    }
                } else {
                    $error = 'Failed to upload. Ensure the uploads folder has correct permissions.';
                }
            }
        }
    } elseif ($action === 'toggle_status') {
        $id = (int)($_POST['banner_id'] ?? 0);
        $newStatus = sanitize($_POST['new_status'] ?? 'Active');
        if (in_array($newStatus, ['Active', 'Inactive'])) {
            $stmt = $pdo->prepare("UPDATE announcements SET status=? WHERE id=?");
            if ($stmt->execute([$newStatus, $id])) {
                $message = 'Banner status updated.';
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['banner_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT image_path FROM announcements WHERE id=?");
        $stmt->execute([$id]);
        $file = $stmt->fetchColumn();
        if ($file && file_exists($uploadDir . $file)) {
            @unlink($uploadDir . $file);
        }
        $pdo->prepare("DELETE FROM announcements WHERE id=?")->execute([$id]);
        $message = 'Banner deleted successfully.';
    } elseif ($action === 'edit') {
        $id             = (int)($_POST['banner_id'] ?? 0);
        $newTitle       = trim(sanitize($_POST['title'] ?? ''));
        $newAudience    = sanitize($_POST['target_audience'] ?? 'All');
        $newStatus      = sanitize($_POST['status'] ?? 'Active');
        if (empty($newTitle)) {
            $error = 'Title is required.';
        } else {
            $allowedImg   = ['jpg','jpeg','png','gif','webp'];
            $allowedVideo = ['mp4','webm','ogg'];
            $newOrientation = sanitize($_POST['orientation'] ?? '');
            $newImagePath = null;
            if (isset($_FILES['banner_image']) && $_FILES['banner_image']['error'] === UPLOAD_ERR_OK) {
                $fileInfo = pathinfo($_FILES['banner_image']['name']);
                $ext = strtolower($fileInfo['extension']);
                if (!in_array($ext, array_merge($allowedImg, $allowedVideo))) {
                    $error = 'Unsupported file type. Allowed: JPG, PNG, WEBP, GIF, MP4, WEBM.';
                } else {
                    $uniqueName = 'banner_' . time() . '_' . uniqid() . '.' . $ext;
                    if (in_array($ext, $allowedImg) && $newOrientation === 'auto') {
                        $dim = @getimagesize($_FILES['banner_image']['tmp_name']);
                        $newOrientation = ($dim && $dim[1] > $dim[0]) ? 'vertical' : 'horizontal';
                    }
                    if (move_uploaded_file($_FILES['banner_image']['tmp_name'], $uploadDir . $uniqueName)) {
                        $old = $pdo->prepare("SELECT image_path FROM announcements WHERE id=?");
                        $old->execute([$id]);
                        $oldFile = $old->fetchColumn();
                        if ($oldFile && file_exists($uploadDir . $oldFile)) @unlink($uploadDir . $oldFile);
                        $newImagePath = $uniqueName;
                    } else {
                        $error = 'Failed to upload new file.';
                    }
                }
            }
            if (!$error) {
                if ($newImagePath) {
                    $stmt = $pdo->prepare("UPDATE announcements SET title=?, target_audience=?, status=?, image_path=?, orientation=? WHERE id=?");
                    $stmt->execute([$newTitle, $newAudience, $newStatus, $newImagePath, $newOrientation ?: 'horizontal', $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE announcements SET title=?, target_audience=?, status=?, orientation=? WHERE id=?");
                    $stmt->execute([$newTitle, $newAudience, $newStatus, $newOrientation ?: 'horizontal', $id]);
                }
                $message = 'Banner updated successfully!';
            }
        }
    }
}

// Fetch all banners
$stmt = $pdo->query("SELECT * FROM announcements ORDER BY created_at DESC");
$banners = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count active slides per audience
$activeAtc = 0; $activeDlc = 0;
foreach ($banners as $b) {
    if ($b['status'] === 'Active') {
        if ($b['target_audience'] === 'All')     { $activeAtc++; $activeDlc++; }
        elseif ($b['target_audience'] === 'ATC') { $activeAtc++; }
        elseif ($b['target_audience'] === 'DLC') { $activeDlc++; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Banners — Admin | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <style>
    /* ── Layout ── */
    .banners-layout { display:grid; grid-template-columns:360px 1fr; gap:1.5rem; align-items:start; }
    @media(max-width:1024px){ .banners-layout{ grid-template-columns:1fr; } }

    /* ── Status Cards ── */
    .status-bar { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.75rem; }
    @media(max-width:640px){ .status-bar{ grid-template-columns:1fr; } }
    .status-card {
        border-radius:var(--radius-xl,16px); padding:1.1rem 1.25rem;
        display:flex; align-items:center; gap:.9rem;
        border:1px solid transparent; position:relative; overflow:hidden;
    }
    .status-card.atc { background:linear-gradient(135deg,#eff6ff,#dbeafe); border-color:#bfdbfe; }
    .status-card.dlc { background:linear-gradient(135deg,#f0fdf4,#dcfce7); border-color:#bbf7d0; }
    .status-card-icon { width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
    .status-card.atc .status-card-icon { background:linear-gradient(135deg,#3b82f6,#2563eb); }
    .status-card.dlc .status-card-icon { background:linear-gradient(135deg,#10b981,#059669); }
    .status-card-icon svg { width:20px;height:20px;stroke:#fff;stroke-width:2.2; }
    .status-label { font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.15rem; }
    .status-card.atc .status-label { color:#1d4ed8; }
    .status-card.dlc .status-label { color:#15803d; }
    .status-count { font-size:1.2rem;font-weight:800;line-height:1; }
    .status-card.atc .status-count { color:#1e40af; }
    .status-card.dlc .status-count { color:#166534; }
    .status-hint { font-size:.68rem;margin-top:.15rem;font-weight:500; }
    .status-card.atc .status-hint { color:#3b82f6; }
    .status-card.dlc .status-hint { color:#22c55e; }
    .slide-indicator {
        position:absolute;top:.8rem;right:.9rem;
        background:rgba(255,255,255,.75);
        border-radius:999px;font-size:.62rem;font-weight:800;
        padding:.15rem .5rem;display:flex;align-items:center;gap:.25rem;
    }
    .slide-dot { width:5px;height:5px;border-radius:50%;display:inline-block; }
    .status-card.atc .slide-dot { background:#3b82f6; }
    .status-card.dlc .slide-dot { background:#10b981; }

    /* ── Upload Card ── */
    .upload-card {
        background:var(--bg-surface,#fff);
        border:1px solid var(--border-color,#e2e8f0);
        border-radius:var(--radius-xl,16px);
        padding:1.5rem;
        box-shadow:0 2px 8px rgba(0,0,0,.04);
        position:sticky; top:90px;
    }
    .upload-card-header {
        display:flex;align-items:center;gap:.65rem;
        font-size:.95rem;font-weight:800;color:var(--text-primary,#0f1523);
        padding-bottom:.9rem;margin-bottom:1.1rem;
        border-bottom:1px solid var(--border-color,#e2e8f0);
    }
    .upload-card-header svg { width:18px;height:18px;stroke:var(--primary-500,#6366f1);fill:none; }

    /* File Drop Zone */
    .drop-zone {
        border:2px dashed #c7d2fe;border-radius:12px;
        padding:1.5rem 1rem;text-align:center;margin-bottom:1rem;
        background:#f5f7ff;cursor:pointer;transition:all .25s;
        position:relative;overflow:hidden;
    }
    .drop-zone:hover,.drop-zone.drag-over { border-color:#6366f1;background:#eef0fd; }
    .drop-zone svg { width:32px;height:32px;color:#818cf8;margin-bottom:.5rem; }
    .drop-zone p { font-size:.82rem;color:#475569;font-weight:500;margin:0; }
    .drop-zone span { font-size:.72rem;color:#94a3b8;display:block;margin-top:.25rem; }
    .drop-zone input[type=file] { position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%; }

    /* Image Preview */
    #imgPreviewWrap { display:none;border-radius:10px;overflow:hidden;border:1px solid #e2e8f0;margin-bottom:1rem;position:relative; }
    #imgPreview { width:100%;height:130px;object-fit:cover;display:block; }
    #removePreview {
        position:absolute;top:6px;right:6px;background:rgba(0,0,0,.55);color:#fff;
        border:none;border-radius:50%;width:24px;height:24px;font-size:.85rem;
        cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1;
    }

    /* Form elements */
    .form-group { margin-bottom:.9rem; }
    .form-label { display:block;font-size:.78rem;font-weight:700;color:var(--text-secondary,#475569);margin-bottom:.35rem;letter-spacing:.01em; }
    .form-control {
        width:100%;padding:.6rem .85rem;
        border:1.5px solid var(--border-color,#e2e8f0);
        border-radius:9px;font-size:.875rem;
        font-family:inherit;color:var(--text-primary,#0f1523);
        background:var(--bg-surface,#fff);
        transition:border-color .2s,box-shadow .2s;
        box-sizing:border-box;
    }
    .form-control:focus { border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.1);outline:none; }
    .btn-upload {
        width:100%;padding:.75rem;border:none;border-radius:10px;
        background:linear-gradient(135deg,#6366f1,#8b5cf6);
        color:#fff;font-size:.875rem;font-weight:700;
        cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.5rem;
        font-family:inherit;transition:all .25s;
        box-shadow:0 4px 14px rgba(99,102,241,.3);
    }
    .btn-upload:hover { transform:translateY(-2px);box-shadow:0 6px 20px rgba(99,102,241,.4); }
    .btn-upload:active { transform:translateY(0); }

    /* ── Alert ── */
    .alert {
        display:flex;align-items:center;gap:.65rem;
        padding:.8rem 1rem;border-radius:10px;margin-bottom:1.25rem;
        font-size:.84rem;font-weight:600;animation:slideDown .3s ease;
    }
    @keyframes slideDown { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:none} }
    .alert-success { background:#f0fdf4;border:1px solid #bbf7d0;color:#166534; }
    .alert-error   { background:#fef2f2;border:1px solid #fecaca;color:#b91c1c; }
    .alert svg { width:17px;height:17px;flex-shrink:0; }

    /* Edit btn */
    .btn-act-edit { background:#f5f3ff;border-color:#ddd6fe;color:#7c3aed; }
    .btn-act-edit:hover { background:#ede9fe; }

    /* ── Banners Grid ── */
    .banners-section-title {
        font-size:1rem;font-weight:800;color:var(--text-primary,#0f1523);
        margin-bottom:1rem;display:flex;align-items:center;justify-content:space-between;
    }
    .banners-count { font-size:.72rem;background:var(--bg-secondary,#f1f5f9);color:var(--text-secondary,#64748b);padding:.2rem .6rem;border-radius:999px;font-weight:700; }
    .banners-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:1.1rem; }

    /* Banner Card */
    .banner-card {
        background:var(--bg-surface,#fff);
        border:1px solid var(--border-color,#e2e8f0);
        border-radius:var(--radius-xl,16px);overflow:hidden;
        display:flex;flex-direction:column;
        transition:box-shadow .25s,transform .25s;
    }
    .banner-card:hover { transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,.08); }
    .banner-card.is-active { border-color:#bbf7d0; }
    .banner-card.is-inactive { opacity:.72; }

    .banner-img-wrap { width:100%;height:145px;position:relative;overflow:hidden;background:#f1f5f9; }
    .banner-img { width:100%;height:100%;object-fit:cover;transition:transform .4s; }
    .banner-card:hover .banner-img { transform:scale(1.04); }
    .banner-audience-pill {
        position:absolute;top:9px;left:9px;z-index:2;
        background:rgba(0,0,0,.6);color:#fff;backdrop-filter:blur(4px);
        border-radius:20px;font-size:.62rem;font-weight:800;letter-spacing:.04em;
        padding:.2rem .6rem;display:flex;align-items:center;gap:.3rem;text-transform:uppercase;
    }
    .audience-dot { width:5px;height:5px;border-radius:50%;background:#fff;display:inline-block; }
    .banner-status-pill {
        position:absolute;top:9px;right:9px;z-index:2;
        border-radius:20px;font-size:.62rem;font-weight:800;
        padding:.2rem .55rem;
    }
    .banner-status-pill.active   { background:#16a34a;color:#fff; }
    .banner-status-pill.inactive { background:rgba(0,0,0,.5);color:#e5e7eb; }

    .banner-body { padding:.9rem 1rem;flex:1;display:flex;flex-direction:column;gap:.4rem; }
    .banner-name { font-size:.9rem;font-weight:700;color:var(--text-primary,#0f1523);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
    .banner-date { font-size:.7rem;color:var(--text-muted,#94a3b8);font-weight:500; }

    .banner-actions { display:flex;gap:.5rem;padding:.75rem 1rem;border-top:1px solid var(--border-color,#f1f5f9); }
    .btn-act {
        flex:1;padding:.45rem .5rem;font-size:.78rem;font-weight:700;
        border-radius:8px;border:1.5px solid transparent;cursor:pointer;
        font-family:inherit;display:flex;align-items:center;justify-content:center;gap:.3rem;
        transition:all .2s;
    }
    .btn-act svg { width:13px;height:13px; }
    .btn-act-toggle-on  { background:#fff;border-color:#d1d5db;color:#374151; }
    .btn-act-toggle-on:hover  { background:#f3f4f6;border-color:#9ca3af; }
    .btn-act-toggle-off { background:#f0fdf4;border-color:#bbf7d0;color:#15803d; }
    .btn-act-toggle-off:hover { background:#dcfce7; }
    .btn-act-delete { background:#fef2f2;border-color:#fecaca;color:#dc2626; }
    .btn-act-delete:hover { background:#fee2e2; }

    /* ── Edit Modal ── */
    .modal-overlay {
        position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;
        display:flex;align-items:center;justify-content:center;
        opacity:0;pointer-events:none;transition:opacity .25s;
    }
    .modal-overlay.open { opacity:1;pointer-events:auto; }
    .edit-modal {
        background:var(--bg-surface,#fff);border-radius:var(--radius-xl,16px);
        width:min(480px,95vw);max-height:90vh;overflow-y:auto;
        box-shadow:0 20px 60px rgba(0,0,0,.18);
        transform:translateY(24px) scale(.97);transition:transform .28s ease, opacity .25s;
        opacity:0;
    }
    .modal-overlay.open .edit-modal { transform:none;opacity:1; }
    .edit-modal-header {
        padding:1.25rem 1.5rem;
        border-bottom:1px solid var(--border-color,#e2e8f0);
        display:flex;align-items:center;justify-content:space-between;
        background:linear-gradient(135deg,#6366f1,#8b5cf6);
        border-radius:var(--radius-xl,16px) var(--radius-xl,16px) 0 0;
    }
    .edit-modal-header h3 { margin:0;font-size:.95rem;font-weight:800;color:#fff; }
    .modal-close {
        background:rgba(255,255,255,.2);border:none;color:#fff;
        width:28px;height:28px;border-radius:50%;cursor:pointer;
        font-size:1.1rem;display:flex;align-items:center;justify-content:center;
        transition:background .2s;
    }
    .modal-close:hover { background:rgba(255,255,255,.35); }
    .edit-modal-body { padding:1.5rem; }
    .edit-img-preview {
        width:100%;height:110px;object-fit:cover;
        border-radius:10px;margin-bottom:1rem;display:block;
        border:1px solid var(--border-color,#e2e8f0);
    }
    .btn-edit-submit {
        width:100%;padding:.72rem;border:none;border-radius:10px;
        background:linear-gradient(135deg,#6366f1,#8b5cf6);
        color:#fff;font-size:.875rem;font-weight:700;
        cursor:pointer;font-family:inherit;transition:all .25s;
        box-shadow:0 4px 14px rgba(99,102,241,.3);
        display:flex;align-items:center;justify-content:center;gap:.5rem;
    }
    .btn-edit-submit:hover { transform:translateY(-2px);box-shadow:0 6px 20px rgba(99,102,241,.4); }

    /* Empty state */
    .empty-state {
        text-align:center;padding:3.5rem 2rem;
        border:2px dashed var(--border-color,#e2e8f0);
        border-radius:var(--radius-xl,16px);color:#94a3b8;
    }
    .empty-state svg { width:52px;height:52px;margin-bottom:1rem;opacity:.4; }
    .empty-state h3 { font-size:1rem;font-weight:700;color:var(--text-secondary,#64748b);margin-bottom:.35rem; }
    .empty-state p { font-size:.83rem; }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <button class="hamburger" id="hamburgerBtn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <div class="header-greeting">
                    <h2>Dashboard Banners</h2>
                    <p>Manage sliding announcements shown on ATC &amp; DLC dashboards</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">

            <?php if ($message): ?>
            <div class="alert alert-success">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <?= htmlspecialchars($message) ?>
            </div>
            <?php endif; ?>
            <?php if ($error): ?>
            <div class="alert alert-error">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><circle cx="12" cy="16" r=".5" fill="currentColor"/></svg>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <!-- Carousel Live Status -->
            <div class="status-bar">
                <div class="status-card atc">
                    <div class="status-card-icon">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                    </div>
                    <div>
                        <div class="status-label">ATC Dashboard</div>
                        <div class="status-count"><?= $activeAtc ?> Active Slide<?= $activeAtc !== 1 ? 's' : '' ?></div>
                        <div class="status-hint"><?= $activeAtc <= 1 ? 'Static — no auto-slide' : 'Auto-slides every 5 seconds ✨' ?></div>
                    </div>
                    <?php if ($activeAtc > 1): ?>
                    <div class="slide-indicator"><span class="slide-dot"></span><?= $activeAtc ?> Slides</div>
                    <?php endif; ?>
                </div>
                <div class="status-card dlc">
                    <div class="status-card-icon">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    </div>
                    <div>
                        <div class="status-label">DLC Dashboard</div>
                        <div class="status-count"><?= $activeDlc ?> Active Slide<?= $activeDlc !== 1 ? 's' : '' ?></div>
                        <div class="status-hint"><?= $activeDlc <= 1 ? 'Static — no auto-slide' : 'Auto-slides every 5 seconds ✨' ?></div>
                    </div>
                    <?php if ($activeDlc > 1): ?>
                    <div class="slide-indicator"><span class="slide-dot"></span><?= $activeDlc ?> Slides</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="banners-layout">
                <!-- Upload Form -->
                <div>
                    <div class="upload-card">
                        <div class="upload-card-header">
                            <svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            Upload New Banner
                        </div>
                        <form method="POST" enctype="multipart/form-data" id="uploadForm">
                            <input type="hidden" name="action" value="upload">

                            <div class="drop-zone" id="dropZone">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                <p>Click or drag &amp; drop here</p>
                                <span>Image (JPG, PNG, WEBP) &middot; Video (MP4, WEBM)</span>
                                <input type="file" name="banner_image" id="bannerFile" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm" required>
                            </div>

                            <div id="imgPreviewWrap">
                                <img id="imgPreview" src="" alt="Preview">
                                <video id="vidPreview" style="display:none;width:100%;height:130px;object-fit:cover;border-radius:10px;border:1px solid #e2e8f0" muted playsinline></video>
                                <button type="button" id="removePreview" title="Remove">&times;</button>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Title / Event Name</label>
                                <input type="text" name="title" class="form-control" placeholder="e.g. Happy Diwali 2026" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Show To</label>
                                <select name="target_audience" class="form-control">
                                    <option value="All">All — ATC &amp; DLC</option>
                                    <option value="ATC">ATC Centers Only</option>
                                    <option value="DLC">DLC Offices Only</option>
                                </select>
                            </div>
                            <div class="form-group" id="orientationGroup">
                                <label class="form-label">Orientation</label>
                                <select name="orientation" class="form-control">
                                    <option value="auto">Auto-detect (recommended)</option>
                                    <option value="horizontal">Horizontal (Landscape)</option>
                                    <option value="vertical">Vertical (Portrait / Reel)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Initial Status</label>
                                <select name="status" class="form-control">
                                    <option value="Active">Active — Publish Immediately</option>
                                    <option value="Inactive">Inactive — Draft</option>
                                </select>
                            </div>

                            <button type="submit" class="btn-upload">
                                <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                Upload Banner
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Banners List -->
                <div>
                    <div class="banners-section-title">
                        All Banners
                        <span class="banners-count"><?= count($banners) ?> total</span>
                    </div>

                    <?php if (empty($banners)): ?>
                    <div class="empty-state">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                        <h3>No banners uploaded yet</h3>
                        <p>Use the form on the left to upload your first dashboard banner.</p>
                    </div>
                    <?php else: ?>
                    <div class="banners-grid">
                        <?php
                        $videoExtArr = ['mp4','webm','ogg'];
                        foreach ($banners as $b):
                            $isActive   = ($b['status'] === 'Active');
                            $imgUrl     = '../uploads/announcements/' . htmlspecialchars($b['image_path']);
                            $isVideo    = in_array(strtolower(pathinfo($b['image_path'], PATHINFO_EXTENSION)), $videoExtArr);
                            $isVertical = (($b['orientation'] ?? 'horizontal') === 'vertical');
                            $audienceColors = ['All'=>'#fff','ATC'=>'#fde68a','DLC'=>'#a7f3d0'];
                        ?>
                        <div class="banner-card <?= $isActive ? 'is-active' : 'is-inactive' ?>">
                            <div class="banner-img-wrap">
                                <div class="banner-audience-pill">
                                    <span class="audience-dot" style="background:<?= $audienceColors[$b['target_audience']] ?? '#fff' ?>"></span>
                                    <?= htmlspecialchars($b['target_audience']) ?>
                                </div>
                                <span class="banner-status-pill <?= $isActive ? 'active' : 'inactive' ?>">
                                    <?= $isActive ? '● Live' : '○ Hidden' ?>
                                </span>
                                <!-- Media type badge -->
                                <?php if ($isVideo): ?>
                                <span style="position:absolute;bottom:9px;right:9px;z-index:2;background:rgba(0,0,0,.65);color:#fff;border-radius:20px;font-size:.6rem;font-weight:800;padding:.2rem .55rem;backdrop-filter:blur(4px);display:flex;align-items:center;gap:.3rem">
                                    <svg viewBox="0 0 24 24" width="10" height="10" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg> VIDEO
                                </span>
                                <?php elseif ($isVertical): ?>
                                <span style="position:absolute;bottom:9px;right:9px;z-index:2;background:rgba(99,102,241,.8);color:#fff;border-radius:20px;font-size:.6rem;font-weight:800;padding:.2rem .55rem">
                                    ↕ VERTICAL
                                </span>
                                <?php endif; ?>
                                <?php if ($isVideo): ?>
                                <video src="<?= $imgUrl ?>" class="banner-img" muted playsinline preload="none" style="object-fit:cover"></video>
                                <?php else: ?>
                                <img src="<?= $imgUrl ?>" class="banner-img" alt="<?= htmlspecialchars($b['title']) ?>" loading="lazy">
                                <?php endif; ?>
                            </div>
                            <div class="banner-body">
                                <div class="banner-name" title="<?= htmlspecialchars($b['title']) ?>"><?= htmlspecialchars($b['title']) ?></div>
                                <div class="banner-date">Uploaded <?= date('d M Y', strtotime($b['created_at'])) ?></div>
                            </div>
                            <div class="banner-actions">
                                <!-- Edit Button -->
                                <button type="button" class="btn-act btn-act-edit"
                                    data-id="<?= $b['id'] ?>"
                                    data-title="<?= htmlspecialchars($b['title'], ENT_QUOTES) ?>"
                                    data-audience="<?= htmlspecialchars($b['target_audience']) ?>"
                                    data-status="<?= htmlspecialchars($b['status']) ?>"
                                    data-orientation="<?= htmlspecialchars($b['orientation'] ?? 'horizontal') ?>"
                                    data-img="<?= $isVideo ? '' : $imgUrl ?>"
                                    data-is-video="<?= $isVideo ? '1' : '0' ?>"
                                    onclick="openEditModal(this)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Edit
                                </button>
                                <!-- Hide/Publish -->
                                <form method="POST" style="flex:1;display:flex;">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="banner_id" value="<?= $b['id'] ?>">
                                    <input type="hidden" name="new_status" value="<?= $isActive ? 'Inactive' : 'Active' ?>">
                                    <button type="submit" class="btn-act <?= $isActive ? 'btn-act-toggle-on' : 'btn-act-toggle-off' ?>">
                                        <?php if ($isActive): ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg> Hide
                                        <?php else: ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> Publish
                                        <?php endif; ?>
                                    </button>
                                </form>
                                <!-- Delete -->
                                <form method="POST" style="flex:1;display:flex;" onsubmit="return confirm('Permanently delete this banner?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="banner_id" value="<?= $b['id'] ?>">
                                    <button type="submit" class="btn-act btn-act-delete">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg> Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /page-content -->
    </main>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModalOverlay">
    <div class="edit-modal">
        <div class="edit-modal-header">
            <h3>
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;margin-right:6px"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Edit Banner
            </h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <div class="edit-modal-body">
            <img id="editImgPreview" class="edit-img-preview" src="" alt="Current Banner">
            <form method="POST" enctype="multipart/form-data" id="editForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="banner_id" id="editBannerId">

                <div class="form-group">
                    <label class="form-label">Replace Media <span style="color:#94a3b8;font-weight:500">(optional — leave blank to keep current)</span></label>
                    <div class="drop-zone" id="editDropZone" style="padding:1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:24px;height:24px;color:#818cf8;margin-bottom:.3rem" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <p style="margin:0;font-size:.78rem">Click or drop new image / video here</p>
                        <input type="file" name="banner_image" id="editBannerFile" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Title / Event Name</label>
                    <input type="text" name="title" id="editTitle" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Show To</label>
                    <select name="target_audience" id="editAudience" class="form-control">
                        <option value="All">All — ATC &amp; DLC</option>
                        <option value="ATC">ATC Centers Only</option>
                        <option value="DLC">DLC Offices Only</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Orientation</label>
                    <select name="orientation" id="editOrientation" class="form-control">
                        <option value="horizontal">Horizontal (Landscape)</option>
                        <option value="vertical">Vertical (Portrait / Reel)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" id="editStatus" class="form-control">
                        <option value="Active">Active — Live</option>
                        <option value="Inactive">Inactive — Hidden</option>
                    </select>
                </div>

                <button type="submit" class="btn-edit-submit">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Save Changes
                </button>
            </form>
        </div>
    </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
// ── Upload Form Preview (image + video) ──
const bannerFile  = document.getElementById('bannerFile');
const dropZone    = document.getElementById('dropZone');
const previewWrap = document.getElementById('imgPreviewWrap');
const previewImg  = document.getElementById('imgPreview');
const previewVid  = document.getElementById('vidPreview');
const removeBtn   = document.getElementById('removePreview');

function showPreview(file) {
    if (!file) return;
    if (file.type.startsWith('video/')) {
        previewImg.style.display = 'none';
        previewVid.style.display = 'block';
        previewVid.src = URL.createObjectURL(file);
        previewWrap.style.display = 'block';
        dropZone.style.display = 'none';
    } else if (file.type.startsWith('image/')) {
        previewVid.style.display = 'none';
        previewImg.style.display = 'block';
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            previewWrap.style.display = 'block';
            dropZone.style.display = 'none';
        };
        reader.readAsDataURL(file);
    }
}

bannerFile.addEventListener('change', function() { showPreview(this.files[0]); });
removeBtn.addEventListener('click', function() {
    bannerFile.value = '';
    previewWrap.style.display = 'none';
    previewImg.style.display = 'block';
    previewVid.style.display = 'none';
    previewVid.src = '';
    previewImg.src = '';
    dropZone.style.display = 'block';
});
dropZone.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', function()  { this.classList.remove('drag-over'); });
dropZone.addEventListener('drop', function(e) {
    e.preventDefault(); this.classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) { bannerFile.files = e.dataTransfer.files; showPreview(file); }
});

// ── Edit Modal ──
const editOverlay = document.getElementById('editModalOverlay');

function openEditModal(btn) {
    document.getElementById('editBannerId').value    = btn.dataset.id;
    document.getElementById('editTitle').value       = btn.dataset.title;
    document.getElementById('editAudience').value    = btn.dataset.audience;
    document.getElementById('editStatus').value      = btn.dataset.status;
    document.getElementById('editOrientation').value = btn.dataset.orientation || 'horizontal';
    document.getElementById('editBannerFile').value  = '';

    var isVideo = btn.dataset.isVideo === '1';
    var editImgPrev = document.getElementById('editImgPreview');
    if (isVideo) {
        editImgPrev.src = '';
        editImgPrev.style.display = 'none';
        // Show a notice instead
        var vNote = document.getElementById('editVideoNote');
        if (!vNote) {
            vNote = document.createElement('div');
            vNote.id = 'editVideoNote';
            vNote.style = 'background:#ede9fe;color:#6d28d9;border-radius:8px;padding:.6rem .85rem;font-size:.78rem;font-weight:700;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem';
            vNote.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg> Current banner is a VIDEO. Upload a new file to replace it.';
            editImgPrev.parentNode.insertBefore(vNote, editImgPrev);
        }
        vNote.style.display = 'flex';
    } else {
        var vNote = document.getElementById('editVideoNote');
        if (vNote) vNote.style.display = 'none';
        editImgPrev.src = btn.dataset.img;
        editImgPrev.style.display = 'block';
    }

    editOverlay.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeEditModal() {
    editOverlay.classList.remove('open');
    document.body.style.overflow = '';
}

editOverlay.addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});

// New media preview inside edit modal
document.getElementById('editBannerFile').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    const editImgPrev = document.getElementById('editImgPreview');
    if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = function(e) { editImgPrev.src = e.target.result; editImgPrev.style.display = 'block'; };
        reader.readAsDataURL(file);
    } else if (file.type.startsWith('video/')) {
        editImgPrev.style.display = 'none'; // can't preview video in img
    }
});

// Edit drop zone
const editDropZone  = document.getElementById('editDropZone');
const editFileInput = document.getElementById('editBannerFile');
editDropZone.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('drag-over'); });
editDropZone.addEventListener('dragleave', function()  { this.classList.remove('drag-over'); });
editDropZone.addEventListener('drop', function(e) {
    e.preventDefault(); this.classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) { editFileInput.files = e.dataTransfer.files; editFileInput.dispatchEvent(new Event('change')); }
});
</script>
</body>
</html>
