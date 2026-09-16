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

$atcName = '';
if ($atcId) {
    $a = $pdo->prepare("SELECT name FROM atc_centers WHERE id = ?");
    $a->execute([$atcId]);
    $atcName = (string)($a->fetchColumn() ?: '');
}
$watermarkText = trim(($atcName !== '' ? $atcName . ' · ' : '') . $userName);

// Secure embed — never expose direct upload paths
$embedHtml = '';
$isUpload = false;
if ($video['video_type'] === 'youtube' && $video['video_url']) {
    preg_match('/(?:v=|youtu\.be\/|embed\/)([a-zA-Z0-9_-]{11})/', $video['video_url'], $m);
    if (!empty($m[1])) {
        // modestbranding; no related videos; fs still needed for fullscreen watch
        $embedHtml = '<iframe id="tvPlayerFrame" src="https://www.youtube-nocookie.com/embed/' . htmlspecialchars($m[1])
            . '?rel=0&modestbranding=1&controls=1&disablekb=1&iv_load_policy=3&playsinline=1"'
            . ' title="Training video" frameborder="0" allow="accelerometer; autoplay; encrypted-media; picture-in-picture"'
            . ' allowfullscreen referrerpolicy="strict-origin-when-cross-origin"'
            . ' style="width:100%;height:100%;border:0;border-radius:14px"></iframe>';
    }
} elseif ($video['video_type'] === 'upload' && $video['video_path']) {
    $isUpload = true;
    $streamSrc = 'stream.php?a=' . (int)$video['assignment_id'];
    $embedHtml = '<video id="tvPlayer" controls playsinline controlslist="nodownload noplaybackrate noremoteplayback"'
        . ' disablepictureinpicture preload="metadata"'
        . ' style="width:100%;height:100%;border-radius:14px;background:#000;object-fit:contain">'
        . '<source src="' . htmlspecialchars($streamSrc) . '" type="video/mp4">'
        . 'Your browser does not support video.</video>';
} elseif ($video['video_url']) {
    $embedHtml = '<iframe id="tvPlayerFrame" src="' . htmlspecialchars($video['video_url']) . '"'
        . ' frameborder="0" allowfullscreen style="width:100%;height:100%;border-radius:14px"></iframe>';
}

$daysLeft = (int)((strtotime($video['access_end']) - time()) / 86400);
$daysClass = $daysLeft <= 3 ? 'urgent' : ($daysLeft <= 7 ? 'warn' : 'ok');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
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
    position: relative;
    width: 100%;
    aspect-ratio: 16/9;
    max-height: 72vh;
    background: linear-gradient(135deg, #0f0e17, #1a1a2e);
    border-radius: 16px;
    overflow: hidden;
    margin-bottom: 1.5rem;
    box-shadow: 0 8px 32px rgba(0,0,0,.15);
    user-select: none;
    -webkit-user-select: none;
}
.player-wrap video,
.player-wrap iframe {
    position: relative;
    z-index: 1;
}

/* Visible + tiled watermarks — leak deterrent (cannot stop OS capture) */
.wm-layer {
    position: absolute;
    inset: 0;
    z-index: 2;
    pointer-events: none;
    overflow: hidden;
}
.wm-tile {
    position: absolute;
    inset: -40%;
    width: 180%;
    height: 180%;
    background-image: repeating-linear-gradient(
        -28deg,
        transparent 0,
        transparent 68px,
        rgba(255,255,255,.045) 68px,
        rgba(255,255,255,.045) 69px
    );
    transform: rotate(-12deg);
}
.wm-text {
    position: absolute;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%) rotate(-18deg);
    font-size: clamp(.85rem, 2.2vw, 1.15rem);
    font-weight: 800;
    letter-spacing: .04em;
    color: rgba(255,255,255,.22);
    white-space: nowrap;
    text-shadow: 0 1px 2px rgba(0,0,0,.35);
    font-family: 'Sora', system-ui, sans-serif;
}
.wm-corner {
    position: absolute;
    right: .75rem;
    bottom: .65rem;
    z-index: 3;
    pointer-events: none;
    font-size: .65rem;
    font-weight: 700;
    color: rgba(255,255,255,.55);
    background: rgba(0,0,0,.35);
    padding: .2rem .45rem;
    border-radius: 6px;
    font-family: 'Sora', system-ui, sans-serif;
}

.secure-note {
    display: flex;
    align-items: flex-start;
    gap: .55rem;
    margin: -.5rem 0 1.25rem;
    padding: .65rem .85rem;
    border-radius: 10px;
    background: #fff7ed;
    border: 1px solid #fed7aa;
    color: #9a3412;
    font-size: .75rem;
    font-weight: 600;
    line-height: 1.45;
}
.secure-note svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; }

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

/* Hide download affordance on some WebKit builds */
video::-webkit-media-controls-enclosure { overflow: hidden; }
video::-internal-media-controls-download-button { display: none !important; }
video::-webkit-media-controls-download-button { display: none !important; }
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

    <div class="secure-note">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <div>
            Protected training content — downloading and sharing is not allowed.
            This session is watermarked for your centre. Screen recording cannot be fully blocked by any website; misuse can be traced.
        </div>
    </div>

    <div class="player-wrap" id="playerWrap" oncontextmenu="return false;">
        <?= $embedHtml ?>
        <div class="wm-layer" aria-hidden="true">
            <div class="wm-tile"></div>
            <div class="wm-text"><?= htmlspecialchars($watermarkText !== '' ? $watermarkText : 'Gyanam Training') ?></div>
        </div>
        <div class="wm-corner"><?= htmlspecialchars($watermarkText !== '' ? $watermarkText : 'Gyanam') ?></div>
    </div>

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
<script>
(function () {
    const wrap = document.getElementById('playerWrap');
    const video = document.getElementById('tvPlayer');

    // Block right-click / drag save on player
    document.addEventListener('contextmenu', function (e) {
        if (wrap && wrap.contains(e.target)) e.preventDefault();
    });
    document.addEventListener('dragstart', function (e) {
        if (wrap && wrap.contains(e.target)) e.preventDefault();
    });

    // Block common save / print / view-source shortcuts while on this page
    document.addEventListener('keydown', function (e) {
        const k = (e.key || '').toLowerCase();
        if ((e.ctrlKey || e.metaKey) && ['s', 'u', 'p'].includes(k)) {
            e.preventDefault();
        }
        if (e.key === 'F12' || ((e.ctrlKey || e.metaKey) && e.shiftKey && ['i', 'j', 'c'].includes(k))) {
            e.preventDefault();
        }
    });

    if (video) {
        video.setAttribute('controlsList', 'nodownload noplaybackrate noremoteplayback');
        video.disablePictureInPicture = true;

        // Pause when tab is hidden (mild deterrent during some capture flows)
        document.addEventListener('visibilitychange', function () {
            if (document.hidden && !video.paused) {
                try { video.pause(); } catch (_) {}
            }
        });

        // Discourage PiP
        video.addEventListener('enterpictureinpicture', function () {
            try { document.exitPictureInPicture(); } catch (_) {}
        });
    }
})();
</script>
</body>
</html>
