<?php
/**
 * Gyanam Portal — ATC: My Schemes
 * ATC views their active schemes with progress bars.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo   = getDBConnection();
$atcId = $_SESSION['atc_id'] ?? null;

// Auto-expire
try { $pdo->exec("UPDATE schemes SET status='Expired' WHERE status='Active' AND end_date < CURDATE()"); } catch(Exception $e){}

// Fetch my schemes with progress
$mySchemes = $pdo->prepare("
    SELECT
        s.id, s.name, s.scheme_type, s.trigger_count, s.benefit_type, s.benefit_value,
        s.start_date, s.end_date, s.status AS scheme_status, s.description,
        sp.current_count, sp.benefit_unlocked, sp.unlocked_at,
        LEAST(100, ROUND((sp.current_count / s.trigger_count) * 100, 0)) AS progress_pct
    FROM scheme_assignments sa
    JOIN schemes s         ON s.id  = sa.scheme_id
    LEFT JOIN scheme_progress sp ON sp.scheme_id = s.id AND sp.atc_id = sa.atc_id
    WHERE sa.atc_id = ?
    ORDER BY s.status ASC, s.end_date ASC
");
$mySchemes->execute([$atcId]);
$mySchemes = $mySchemes->fetchAll(PDO::FETCH_ASSOC);

$activeCount   = count(array_filter($mySchemes, fn($s) => $s['scheme_status']==='Active'));
$unlockedTotal = array_sum(array_column($mySchemes,'benefit_unlocked'));
$closestScheme = array_filter($mySchemes, fn($s) => $s['scheme_status']==='Active' && $s['progress_pct'] < 100);
$maxPct        = $closestScheme ? max(array_column(array_values($closestScheme),'progress_pct')) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>My Schemes — ATC Center | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎁</text></svg>">
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <button class="hamburger" id="hamburgerBtn"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
                <div class="header-greeting">
                    <h2>My Schemes</h2>
                    <p>Your active incentive schemes — track progress and earn benefits!</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="msc-container">

            <!-- KPI -->
            <div class="msc-kpi-grid">
                <div class="msc-kpi-card msc-kpi-blue">
                    <div class="msc-kpi-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12V22H4V12"/><path d="M22 7H2v5h20V7z"/><path d="M12 22V7"/></svg></div>
                    <div class="msc-kpi-content"><div class="msc-kpi-value"><?= count($mySchemes) ?></div><div class="msc-kpi-label">Total Schemes</div></div>
                </div>
                <div class="msc-kpi-card msc-kpi-green">
                    <div class="msc-kpi-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></div>
                    <div class="msc-kpi-content"><div class="msc-kpi-value"><?= $activeCount ?></div><div class="msc-kpi-label">Active</div></div>
                </div>
                <div class="msc-kpi-card msc-kpi-purple">
                    <div class="msc-kpi-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg></div>
                    <div class="msc-kpi-content"><div class="msc-kpi-value"><?= $unlockedTotal ?></div><div class="msc-kpi-label">Benefits Earned</div></div>
                </div>
                <div class="msc-kpi-card msc-kpi-amber">
                    <div class="msc-kpi-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
                    <div class="msc-kpi-content"><div class="msc-kpi-value"><?= $maxPct ?>%</div><div class="msc-kpi-label">Best Progress</div></div>
                </div>
            </div>

            <?php if (empty($mySchemes)): ?>
                <div style="text-align:center;padding:4rem;color:#9ca3af">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="52" height="52" style="display:block;margin:0 auto 1rem"><path d="M20 12V22H4V12"/><path d="M22 7H2v5h20V7z"/><path d="M12 22V7"/></svg>
                    <p style="font-weight:700;font-size:1.1rem;margin-bottom:.5rem">No schemes assigned yet</p>
                    <p style="font-size:.9rem">The Head Office will assign incentive schemes to your center soon.</p>
                </div>
            <?php else: ?>
                <!-- Active Schemes -->
                <?php $active = array_filter($mySchemes, fn($s) => $s['scheme_status']==='Active'); ?>
                <?php if (!empty($active)): ?>
                <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1rem">
                    <svg style="width:20px;height:20px;color:#059669" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <h3 class="msc-section-title" style="margin:0">Active Schemes</h3>
                </div>
                <div class="msc-cards-grid">
                    <?php foreach ($active as $sc): ?>
                        <?php
                            $pct  = min(100, intval($sc['progress_pct']));
                            $done = $pct >= 100;
                            $remaining = max(0, $sc['trigger_count'] - $sc['current_count']);
                            $benefitIcon = match($sc['benefit_type']) {
                                'Free Share' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>',
                                'Cash Incentive' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="2"></circle><path d="M6 12h.01M18 12h.01"></path></svg>',
                                default => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 12 20 22 4 22 4 12"></polyline><rect x="2" y="7" width="20" height="5"></rect><line x1="12" y1="22" x2="12" y2="7"></line><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"></path><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"></path></svg>'
                            };
                        ?>
                        <div class="msc-card <?= $done ? 'msc-card-done' : '' ?>">
                            <?php if ($sc['benefit_unlocked'] > 0): ?>
                            <div class="msc-badge-unlocked"><svg style="width:12px;height:12px;display:inline;margin-right:4px;vertical-align:-1px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="2" x2="12" y2="6"></line><line x1="12" y1="18" x2="12" y2="22"></line><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"></line><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"></line><line x1="2" y1="12" x2="6" y2="12"></line><line x1="18" y1="12" x2="22" y2="12"></line><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"></line><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"></line></svg> Benefit Unlocked! × <?= $sc['benefit_unlocked'] ?></div>
                            <?php endif; ?>
                            <div class="msc-card-top">
                                <div class="msc-card-icon-box"><?= $benefitIcon ?></div>
                                <div class="msc-card-info">
                                    <h4><?= htmlspecialchars($sc['name']) ?></h4>
                                    <span class="msc-type-pill"><?= htmlspecialchars($sc['scheme_type']) ?></span>
                                </div>
                            </div>

                            <!-- Progress bar -->
                            <div class="msc-progress-section">
                                <div class="msc-progress-header">
                                    <span class="msc-progress-label">Progress</span>
                                    <span class="msc-progress-frac"><?= $sc['current_count'] ?> / <?= $sc['trigger_count'] ?> admissions</span>
                                </div>
                                <div class="msc-progress-bar-bg">
                                    <div class="msc-progress-bar-fill <?= $done ? 'msc-fill-done' : '' ?>" style="width:<?= $pct ?>%"></div>
                                </div>
                                <div class="msc-progress-footer">
                                    <span style="font-weight:700;color:<?= $done?'#059669':'#4361ee' ?>"><?= $pct ?>%</span>
                                    <?php if (!$done): ?>
                                    <span style="color:#6b7280;font-size:.78rem"><?= $remaining ?> more to unlock benefit</span>
                                    <?php else: ?>
                                    <span style="color:#059669;font-weight:700;font-size:.82rem">✅ Threshold Reached!</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="msc-card-rule">
                                Every <strong><?= $sc['trigger_count'] ?></strong> admissions →
                                <strong><?= $sc['benefit_value'] ?> <?= htmlspecialchars($sc['benefit_type']) ?></strong>
                            </div>
                            <div class="msc-card-meta">
                                <span><svg style="width:14px;height:14px;display:inline;vertical-align:-2px;margin-right:4px;stroke:#9ca3af" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> Valid: <?= date('d M Y',strtotime($sc['start_date'])) ?> – <?= date('d M Y',strtotime($sc['end_date'])) ?></span>
                                <?php if ($sc['description']): ?><span style="color:#9ca3af;font-size:.78rem;font-style:italic"><?= htmlspecialchars($sc['description']) ?></span><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Inactive / Expired -->
                <?php $others = array_filter($mySchemes, fn($s) => $s['scheme_status']!=='Active'); ?>
                <?php if (!empty($others)): ?>
                <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1rem;margin-top:2.5rem">
                    <svg style="width:20px;height:20px;color:#6b7280" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
                    <h3 class="msc-section-title" style="margin:0">Inactive / Expired Schemes</h3>
                </div>
                <div class="msc-cards-grid">
                    <?php foreach ($others as $sc): ?>
                        <?php $pct = min(100, intval($sc['progress_pct'])); ?>
                        <div class="msc-card msc-card-faded">
                            <div class="msc-card-top">
                                <div class="msc-card-icon-box" style="background:#f3f4f6;color:#9ca3af"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 12 20 22 4 22 4 12"></polyline><rect x="2" y="7" width="20" height="5"></rect><line x1="12" y1="22" x2="12" y2="7"></line><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"></path><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"></path></svg></div>
                                <div class="msc-card-info">
                                    <h4 style="color:#9ca3af"><?= htmlspecialchars($sc['name']) ?></h4>
                                    <span class="msc-type-pill" style="background:#f3f4f6;color:#9ca3af"><?= $sc['scheme_status'] ?></span>
                                </div>
                            </div>
                            <div class="msc-progress-bar-bg" style="margin:.5rem 0">
                                <div class="msc-progress-bar-fill" style="width:<?= $pct ?>%;background:#9ca3af"></div>
                            </div>
                            <div style="font-size:.8rem;color:#9ca3af"><?= $sc['current_count'] ?>/<?= $sc['trigger_count'] ?> admissions · <?= $sc['benefit_unlocked'] ?> benefits earned</div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<script src="../assets/js/dashboard.js"></script>
<!-- Animate progress bars on load -->
<script>
document.querySelectorAll('.msc-progress-bar-fill').forEach(bar => {
    const w = bar.style.width;
    bar.style.width = '0';
    setTimeout(() => { bar.style.transition = 'width 1.2s cubic-bezier(.4,0,.2,1)'; bar.style.width = w; }, 150);
});
</script>
<style>
:root{--font:'Sora',sans-serif;--mono:'JetBrains Mono',monospace;--brand:#4361ee;--purple:#8b5cf6;--emerald:#10b981;--amber:#f59e0b;}
body{font-family:var(--font)}
.msc-container{padding:1.75rem 2rem;width:100%;box-sizing:border-box}

.msc-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1.25rem;margin-bottom:2rem;width:100%}
@media(max-width:500px){.msc-kpi-grid{grid-template-columns:1fr 1fr}}

.msc-kpi-card { background:#fff; border-radius:12px; padding:1.25rem 1.5rem; display:flex; flex-direction:column; align-items:flex-start; gap:1.25rem; box-shadow:0 1px 3px rgba(0,0,0,.05); transition:all .2s ease; border:1px solid #e5e7eb; border-left-width:4px; }
.msc-kpi-card:hover { transform:translateY(-2px); box-shadow:0 6px 16px rgba(0,0,0,.08); }
.msc-kpi-blue  { border-left-color:var(--brand); }
.msc-kpi-green { border-left-color:var(--emerald); }
.msc-kpi-purple{ border-left-color:var(--purple); }
.msc-kpi-amber { border-left-color:var(--amber); }

.msc-kpi-icon  { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; }
.msc-kpi-blue .msc-kpi-icon  { background:#eef1fd; color:var(--brand); }
.msc-kpi-green .msc-kpi-icon { background:#d1fae5; color:var(--emerald); }
.msc-kpi-purple .msc-kpi-icon{ background:#ede9fe; color:var(--purple); }
.msc-kpi-amber .msc-kpi-icon { background:#fef3c7; color:var(--amber); }

.msc-kpi-icon svg { width:20px; height:20px; stroke:currentColor; fill:none; }

.msc-kpi-content { display:flex; flex-direction:column; gap:.35rem; width:100%; }
.msc-kpi-label { font-size:.65rem; font-weight:800; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; order:-1; margin-top:0; }
.msc-kpi-value { font-size:1.6rem; font-weight:800; font-family:var(--font); line-height:1; color:#111827; }

.msc-section-title{font-size:1.1rem;font-weight:800;color:#1f2937;margin:0 0 1rem;letter-spacing:-0.01em}
.msc-cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1.5rem}
.msc-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.05);transition:all .2s ease;display:flex;flex-direction:column;gap:1.1rem;position:relative;overflow:hidden;border-left:4px solid var(--brand)}
.msc-card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.08)}
.msc-card-done{border-left-color:var(--emerald);box-shadow:0 0 0 1px rgba(16,185,129,.15)}
.msc-card-faded{opacity:.7;border-left-color:#d1d5db}
.msc-badge-unlocked{position:absolute;top:0;right:0;background:linear-gradient(135deg,#059669,#047857);color:#fff;font-size:.7rem;font-weight:800;padding:.35rem .8rem;border-radius:0 11px 0 8px;display:flex;align-items:center}
.msc-card-top{display:flex;align-items:center;gap:.875rem}
.msc-card-icon-box{width:46px;height:46px;border-radius:10px;background:#eef1fd;color:var(--brand);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.msc-card-icon-box svg { width:22px;height:22px;stroke:currentColor; }
.msc-card-info h4{font-size:1.05rem;font-weight:700;color:#1f2937;margin:0 0 .3rem;font-family:var(--font)}
.msc-type-pill{font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;background:#eef1fd;color:var(--brand);padding:.25rem .6rem;border-radius:6px;display:inline-block}
.msc-progress-section{display:flex;flex-direction:column;gap:.45rem}
.msc-progress-header{display:flex;justify-content:space-between;align-items:center}
.msc-progress-label{font-size:.8rem;font-weight:700;color:#374151}
.msc-progress-frac{font-size:.78rem;font-family:var(--mono);font-weight:700;color:#6b7280}
.msc-progress-bar-bg{height:10px;background:#f3f4f6;border-radius:999px;overflow:hidden;border:1px solid #e5e7eb}
.msc-progress-bar-fill{height:10px;border-radius:999px;background:linear-gradient(90deg,var(--brand),#818cf8)}
.msc-fill-done{background:linear-gradient(90deg,var(--emerald),#34d399)}
.msc-progress-footer{display:flex;justify-content:space-between;align-items:center}
.msc-card-rule{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.75rem 1rem;font-size:.85rem;color:#475569;text-align:center}
.msc-card-rule strong{color:var(--brand)}
.msc-card-meta{display:flex;flex-direction:column;gap:.35rem;font-size:.78rem;color:#6b7280}
</style>
</body>
</html>
