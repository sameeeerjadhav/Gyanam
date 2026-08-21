<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin(['Training']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$atcId = $_SESSION['atc_id'] ?? null;

// Fetch assigned videos for this user's ATC (or ALL ATCs assignments)
$sql = "
    SELECT tv.*, va.access_start, va.access_end, va.id AS assignment_id
    FROM video_assignments va
    JOIN training_videos tv ON tv.id = va.video_id AND tv.status = 'Active'
    WHERE (va.atc_id IS NULL" . ($atcId ? " OR va.atc_id = ?" : "") . ")
      AND va.access_start <= CURDATE()
      AND va.access_end >= CURDATE()
    ORDER BY va.access_end ASC
";
$stmt = $pdo->prepare($sql);
$atcId ? $stmt->execute([$atcId]) : $stmt->execute();
$videos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get ATC name
$atcName = '';
if ($atcId) {
    $a = $pdo->prepare("SELECT name FROM atc_centers WHERE id = ?");
    $a->execute([$atcId]);
    $atcName = $a->fetchColumn() ?: '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Training Videos — Gyanam India</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<style>
:root {
    --brand: #4f46e5;
    --brand-light: #818cf8;
    --brand-bg: #eef2ff;
    --emerald: #10b981;
    --amber: #f59e0b;
    --rose: #f43f5e;
    --text: #111827;
    --text-2: #4b5563;
    --text-3: #9ca3af;
    --bg: #f8fafc;
    --card: #fff;
    --border: #e5e7eb;
    --font: 'Sora', system-ui, sans-serif;
}

/* ── Page Hero ── */
.tv-hero {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 50%, #a855f7 100%);
    border-radius: 20px;
    padding: 2rem 2.25rem;
    margin-bottom: 2rem;
    color: #fff;
    position: relative;
    overflow: hidden;
}
.tv-hero::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 300px;
    height: 300px;
    background: radial-gradient(circle, rgba(255,255,255,.12) 0%, transparent 70%);
    border-radius: 50%;
}
.tv-hero::after {
    content: '';
    position: absolute;
    bottom: -40%;
    left: 10%;
    width: 200px;
    height: 200px;
    background: radial-gradient(circle, rgba(255,255,255,.08) 0%, transparent 70%);
    border-radius: 50%;
}
.tv-hero-content { position: relative; z-index: 1; }
.tv-hero h1 {
    font-size: 1.75rem;
    font-weight: 800;
    margin: 0 0 .35rem;
    letter-spacing: -.025em;
}
.tv-hero p {
    font-size: .9rem;
    opacity: .85;
    margin: 0;
    font-weight: 500;
}
.tv-hero-stats {
    display: flex;
    gap: 1.5rem;
    margin-top: 1.25rem;
}
.tv-stat {
    background: rgba(255,255,255,.15);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 12px;
    padding: .75rem 1.25rem;
    min-width: 100px;
}
.tv-stat-val {
    font-size: 1.5rem;
    font-weight: 800;
    line-height: 1;
}
.tv-stat-lbl {
    font-size: .72rem;
    opacity: .75;
    margin-top: .2rem;
    font-weight: 600;
}

/* ── Video Grid ── */
.video-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.5rem;
}
.video-card {
    background: var(--card);
    border: 1.5px solid var(--border);
    border-radius: 16px;
    overflow: hidden;
    transition: all .3s cubic-bezier(.4,0,.2,1);
    box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.video-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 20px 40px rgba(79,70,229,.12);
    border-color: var(--brand-light);
}
.video-thumb {
    width: 100%;
    height: 190px;
    background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
}
.video-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform .4s;
}
.video-card:hover .video-thumb img { transform: scale(1.05); }
.video-thumb svg {
    width: 48px;
    height: 48px;
    stroke: #fff;
    opacity: .4;
}
.play-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,.3);
    opacity: 0;
    transition: opacity .3s;
}
.video-card:hover .play-overlay { opacity: 1; }
.play-btn-circle {
    width: 56px;
    height: 56px;
    background: rgba(255,255,255,.95);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 20px rgba(0,0,0,.25);
}
.play-btn-circle svg {
    width: 22px;
    height: 22px;
    stroke: var(--brand);
    fill: var(--brand);
    opacity: 1;
    margin-left: 3px;
}
.video-type-badge {
    position: absolute;
    top: .75rem;
    left: .75rem;
    padding: .2rem .6rem;
    border-radius: 6px;
    font-size: .65rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .05em;
    backdrop-filter: blur(8px);
}
.badge-youtube { background: rgba(255,0,0,.85); color: #fff; }
.badge-link { background: rgba(59,130,246,.85); color: #fff; }
.badge-upload { background: rgba(16,185,129,.85); color: #fff; }

.video-body { padding: 1.25rem 1.375rem; }
.video-title {
    font-weight: 800;
    font-size: 1rem;
    color: var(--text);
    margin-bottom: .4rem;
    line-height: 1.3;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.video-desc {
    font-size: .82rem;
    color: var(--text-2);
    margin-bottom: 1rem;
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.video-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .5rem;
}
.video-days {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .3rem .7rem;
    border-radius: 99px;
    font-size: .72rem;
    font-weight: 700;
}
.days-ok { background: #d1fae5; color: #065f46; }
.days-warn { background: #fef3c7; color: #92400e; }
.days-urgent { background: #fee2e2; color: #991b1b; }

.watch-btn {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    padding: .55rem 1.25rem;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: #fff;
    border-radius: 10px;
    font-weight: 700;
    font-size: .82rem;
    text-decoration: none;
    transition: all .25s;
    box-shadow: 0 2px 8px rgba(79,70,229,.25);
}
.watch-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 16px rgba(79,70,229,.35);
}
.watch-btn svg { width: 14px; height: 14px; fill: #fff; stroke: none; }

/* ── Empty State ── */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    background: var(--card);
    border: 2px dashed var(--border);
    border-radius: 20px;
}
.empty-icon {
    width: 80px;
    height: 80px;
    background: var(--brand-bg);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.25rem;
}
.empty-icon svg { width: 36px; height: 36px; stroke: var(--brand-light); }
.empty-state h3 { color: var(--text); font-size: 1.15rem; margin: 0 0 .4rem; font-weight: 800; }
.empty-state p { color: var(--text-3); font-size: .88rem; margin: 0; }

@media (max-width: 768px) {
    .tv-hero { padding: 1.5rem; border-radius: 14px; }
    .tv-hero h1 { font-size: 1.3rem; }
    .tv-hero-stats { flex-wrap: wrap; gap: .75rem; }
    .video-grid { grid-template-columns: 1fr; }
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
            <div class="header-greeting"><h2>Welcome, <?= $userName ?>!</h2><p>Your training video dashboard</p></div>
        </div>
    </header>
    <div class="page-content">

    <!-- Hero Banner -->
    <div class="tv-hero">
        <div class="tv-hero-content">
            <h1>🎬 Training Videos</h1>
            <p><?= $atcName ? htmlspecialchars($atcName) . ' — ' : '' ?>Watch your assigned training content</p>
            <div class="tv-hero-stats">
                <div class="tv-stat">
                    <div class="tv-stat-val"><?= count($videos) ?></div>
                    <div class="tv-stat-lbl">Available Videos</div>
                </div>
                <?php
                $urgentCount = 0;
                foreach ($videos as $v) {
                    $dl = (int)((strtotime($v['access_end']) - time()) / 86400);
                    if ($dl <= 7) $urgentCount++;
                }
                if ($urgentCount > 0):
                ?>
                <div class="tv-stat">
                    <div class="tv-stat-val"><?= $urgentCount ?></div>
                    <div class="tv-stat-lbl">Expiring Soon</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (empty($videos)): ?>
    <div class="empty-state">
        <div class="empty-icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
        </div>
        <h3>No Videos Available</h3>
        <p>No training videos have been assigned to your center at this time.</p>
    </div>
    <?php else: ?>
    <div class="video-grid">
        <?php foreach ($videos as $v):
            $daysLeft = (int)((strtotime($v['access_end']) - time()) / 86400);
            $thumbUrl = $v['thumbnail'] ?? null;
            if (!$thumbUrl && $v['video_type'] === 'youtube' && $v['video_url']) {
                preg_match('/(?:v=|youtu\.be\/|embed\/)([a-zA-Z0-9_-]{11})/', $v['video_url'], $m);
                if (!empty($m[1])) $thumbUrl = 'https://img.youtube.com/vi/' . $m[1] . '/hqdefault.jpg';
            }
            $daysClass = $daysLeft <= 3 ? 'days-urgent' : ($daysLeft <= 7 ? 'days-warn' : 'days-ok');
            $typeBadge = $v['video_type'] === 'youtube' ? 'badge-youtube' : ($v['video_type'] === 'link' ? 'badge-link' : 'badge-upload');
        ?>
        <div class="video-card">
            <a href="watch.php?id=<?= $v['assignment_id'] ?>" style="text-decoration:none;color:inherit">
            <div class="video-thumb">
                <?php if ($thumbUrl): ?>
                    <img src="<?= htmlspecialchars($thumbUrl) ?>" alt="<?= htmlspecialchars($v['title']) ?>">
                <?php else: ?>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                <?php endif; ?>
                <div class="play-overlay">
                    <div class="play-btn-circle">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                    </div>
                </div>
                <span class="video-type-badge <?= $typeBadge ?>"><?= ucfirst($v['video_type']) ?></span>
            </div>
            </a>
            <div class="video-body">
                <div class="video-title"><?= htmlspecialchars($v['title']) ?></div>
                <div class="video-desc"><?= htmlspecialchars($v['description'] ?: 'Training video content') ?></div>
                <div class="video-footer">
                    <span class="video-days <?= $daysClass ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:12px;height:12px"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <?= $daysLeft ?> day<?= $daysLeft !== 1 ? 's' : '' ?> left
                    </span>
                    <a href="watch.php?id=<?= $v['assignment_id'] ?>" class="watch-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                        Watch Now
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    </div>
</main>
</div>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>
