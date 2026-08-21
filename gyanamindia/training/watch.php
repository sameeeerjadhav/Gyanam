<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin(['Training']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$atcId = $_SESSION['atc_id'] ?? null;
$assignId = (int)($_GET['id'] ?? 0);

if (!$assignId) { header('Location: index.php'); exit; }

// Verify this assignment belongs to this user's ATC and is currently active
$sql = "
    SELECT tv.*, va.access_start, va.access_end, va.id AS assignment_id
    FROM video_assignments va
    JOIN training_videos tv ON tv.id = va.video_id AND tv.status = 'Active'
    WHERE va.id = ?
      AND (va.atc_id IS NULL" . ($atcId ? " OR va.atc_id = ?" : "") . ")
      AND va.access_start <= CURDATE()
      AND va.access_end >= CURDATE()
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$atcId ? $stmt->execute([$assignId, $atcId]) : $stmt->execute([$assignId]);
$video = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$video) {
    $_SESSION['login_error'] = 'Video not available or access expired.';
    header('Location: index.php');
    exit;
}

// Determine embed
$embedHtml = '';
if ($video['video_type'] === 'youtube' && $video['video_url']) {
    preg_match('/(?:v=|youtu\.be\/|embed\/)([a-zA-Z0-9_-]{11})/', $video['video_url'], $m);
    if (!empty($m[1])) {
        $embedHtml = '<iframe src="https://www.youtube.com/embed/' . $m[1] . '?rel=0&modestbranding=1" frameborder="0" allowfullscreen style="width:100%;height:100%;border-radius:14px"></iframe>';
    }
} elseif ($video['video_type'] === 'upload' && $video['video_path']) {
    $embedHtml = '<video controls style="width:100%;height:100%;border-radius:14px;background:#000"><source src="../' . htmlspecialchars($video['video_path']) . '" type="video/mp4">Your browser does not support video.</video>';
} elseif ($video['video_url']) {
    $embedHtml = '<iframe src="' . htmlspecialchars($video['video_url']) . '" frameborder="0" allowfullscreen style="width:100%;height:100%;border-radius:14px"></iframe>';
}

$daysLeft = (int)((strtotime($video['access_end']) - time()) / 86400);
$daysClass = $daysLeft <= 3 ? 'urgent' : ($daysLeft <= 7 ? 'warn' : 'ok');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($video['title']) ?> — Training | Gyanam India</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<style>
:root {
    --brand: #4f46e5;
    --text: #111827;
    --text-2: #4b5563;
    --text-3: #9ca3af;
    --border: #e5e7eb;
}
.back-link {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    margin-bottom: 1.25rem;
    padding: .45rem 1rem;
    background: #f3f4f6;
    border-radius: 10px;
    color: var(--brand);
    text-decoration: none;
    font-weight: 700;
    font-size: .82rem;
    transition: all .2s;
}
.back-link:hover { background: #e5e7eb; transform: translateX(-2px); }
.back-link svg { width: 14px; height: 14px; }

.player-wrap {
    width: 100%;
    aspect-ratio: 16/9;
    max-height: 72vh;
    background: linear-gradient(135deg, #0f0e17, #1a1a2e);
    border-radius: 16px;
    overflow: hidden;
    margin-bottom: 1.5rem;
    box-shadow: 0 8px 32px rgba(0,0,0,.15);
}

.video-info {
    background: #fff;
    border: 1.5px solid var(--border);
    border-radius: 16px;
    padding: 1.75rem 2rem;
    box-shadow: 0 1px 4px rgba(0,0,0,.03);
}
.vi-title {
    font-size: 1.35rem;
    font-weight: 800;
    color: var(--text);
    margin-bottom: .5rem;
    line-height: 1.3;
}
.vi-desc {
    font-size: .88rem;
    color: var(--text-2);
    line-height: 1.7;
    margin-bottom: 1.25rem;
    padding-bottom: 1.25rem;
    border-bottom: 1px solid #f3f4f6;
}
.vi-meta {
    display: flex;
    gap: .75rem;
    flex-wrap: wrap;
}
.vi-chip {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    padding: .35rem .75rem;
    border-radius: 8px;
    font-size: .78rem;
    font-weight: 700;
    background: #f3f4f6;
    color: var(--text-2);
}
.vi-chip svg { width: 13px; height: 13px; }
.vi-chip.days-ok { background: #d1fae5; color: #065f46; }
.vi-chip.days-warn { background: #fef3c7; color: #92400e; }
.vi-chip.days-urgent { background: #fee2e2; color: #991b1b; }
.vi-chip.type {
    background: linear-gradient(135deg, #eef2ff, #ede9fe);
    color: #4f46e5;
}

@media (max-width: 768px) {
    .video-info { padding: 1.25rem; }
    .vi-title { font-size: 1.1rem; }
}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
    <header class="top-header">
        <div class="header-left">
            <button class="hamburger" id="hamburgerBtn"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
            <div class="header-greeting"><h2>Now Playing</h2><p><?= htmlspecialchars($video['title']) ?></p></div>
        </div>
    </header>
    <div class="page-content">

    <a href="index.php" class="back-link">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        Back to Videos
    </a>

    <div class="player-wrap"><?= $embedHtml ?></div>

    <div class="video-info">
        <div class="vi-title"><?= htmlspecialchars($video['title']) ?></div>
        <?php if ($video['description']): ?>
        <div class="vi-desc"><?= nl2br(htmlspecialchars($video['description'])) ?></div>
        <?php endif; ?>
        <div class="vi-meta">
            <span class="vi-chip">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <?= date('d M Y', strtotime($video['access_start'])) ?> — <?= date('d M Y', strtotime($video['access_end'])) ?>
            </span>
            <span class="vi-chip days-<?= $daysClass ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <?= $daysLeft ?> day<?= $daysLeft !== 1 ? 's' : '' ?> remaining
            </span>
            <span class="vi-chip type">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                <?= ucfirst($video['video_type']) ?>
            </span>
        </div>
    </div>

    </div>
</main>
</div>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>
