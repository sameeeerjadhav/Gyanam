<?php
/**
 * Gyanam Portal — Head Office: Scheme Management
 * Create / edit / deactivate incentive schemes for ATCs.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo = getDBConnection();

// ── Auto-expire schemes past end_date ──────────────────────────────────────
try {
    $pdo->exec("UPDATE schemes SET status='Expired' WHERE status='Active' AND end_date < CURDATE()");
} catch(Exception $e){}

// ── AJAX handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        switch ($_POST['action']) {

            case 'create_scheme': {
                $name       = trim($_POST['name'] ?? '');
                $type       = $_POST['scheme_type'] ?? 'Admission Count';
                $trigger    = intval($_POST['trigger_count'] ?? 10);
                $benefitT   = $_POST['benefit_type'] ?? 'Free Share';
                $benefitV   = intval($_POST['benefit_value'] ?? 1);
                $appTo      = $_POST['applicable_to'] ?? 'All ATCs';
                $atcTypeF   = trim($_POST['atc_type_filter'] ?? '');
                $desc       = trim($_POST['description'] ?? '');
                $startDate  = $_POST['start_date'] ?? '';
                $endDate    = $_POST['end_date'] ?? '';
                $status     = $_POST['status'] ?? 'Active';

                if (!$name || !$startDate || !$endDate) { echo json_encode(['success'=>false,'message'=>'Name, start & end date required.']); exit; }

                $stmt = $pdo->prepare("INSERT INTO schemes(name,scheme_type,trigger_count,benefit_type,benefit_value,applicable_to,atc_type_filter,description,start_date,end_date,status) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$name,$type,$trigger,$benefitT,$benefitV,$appTo,$atcTypeF?:null,$desc?:null,$startDate,$endDate,$status]);
                $schemeId = $pdo->lastInsertId();

                // Auto-assign to ATCs based on applicable_to
                assignSchemeToATCs($pdo, $schemeId, $appTo, $atcTypeF, null);
                // Seed progress rows
                seedProgress($pdo, $schemeId);

                echo json_encode(['success'=>true,'message'=>'Scheme created and assigned.']);
                exit;
            }

            case 'update_status': {
                $id     = intval($_POST['id'] ?? 0);
                $status = $_POST['status'] ?? '';
                if (!in_array($status, ['Active','Inactive','Expired'])) { echo json_encode(['success'=>false,'message'=>'Invalid status.']); exit; }
                $pdo->prepare("UPDATE schemes SET status=? WHERE id=?")->execute([$status,$id]);
                echo json_encode(['success'=>true,'message'=>'Status updated.']);
                exit;
            }

            case 'delete_scheme': {
                $id = intval($_POST['id'] ?? 0);
                $pdo->prepare("DELETE FROM schemes WHERE id=?")->execute([$id]);
                echo json_encode(['success'=>true,'message'=>'Scheme deleted.']);
                exit;
            }

            case 'sync_progress': {
                // Recalculate admission counts for all active schemes from admissions table
                syncAllProgress($pdo);
                echo json_encode(['success'=>true,'message'=>'Progress synced.']);
                exit;
            }
        }
    } catch(Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit;
    }
}

// ── Helpers ────────────────────────────────────────────────────────────────
function assignSchemeToATCs($pdo, $schemeId, $appTo, $atcTypeFilter, $specificAtcId) {
    if ($appTo === 'All ATCs') {
        $atcs = $pdo->query("SELECT id FROM atc_centers WHERE status='Active'")->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($appTo === 'ATC Type' && $atcTypeFilter) {
        $s = $pdo->prepare("SELECT id FROM atc_centers WHERE status='Active' AND center_type=?");
        $s->execute([$atcTypeFilter]);
        $atcs = $s->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($appTo === 'Specific ATC' && $specificAtcId) {
        $atcs = [$specificAtcId];
    } else {
        return;
    }
    $ins = $pdo->prepare("INSERT IGNORE INTO scheme_assignments(scheme_id,atc_id) VALUES(?,?)");
    foreach ($atcs as $aid) { $ins->execute([$schemeId, $aid]); }
}

function seedProgress($pdo, $schemeId) {
    $atcs = $pdo->prepare("SELECT atc_id FROM scheme_assignments WHERE scheme_id=?");
    $atcs->execute([$schemeId]);
    $ins  = $pdo->prepare("INSERT IGNORE INTO scheme_progress(scheme_id,atc_id,current_count,benefit_unlocked) VALUES(?,?,0,0)");
    foreach ($atcs->fetchAll(PDO::FETCH_COLUMN) as $aid) {
        $ins->execute([$schemeId,$aid]);
    }
}

function syncAllProgress($pdo) {
    // For each active scheme+assignment, count admissions within period
    $schemes = $pdo->query("SELECT s.id,s.trigger_count,s.benefit_value,s.start_date,s.end_date,sa.atc_id
        FROM schemes s
        JOIN scheme_assignments sa ON sa.scheme_id=s.id
        WHERE s.status='Active'")->fetchAll(PDO::FETCH_ASSOC);

    $upd = $pdo->prepare("UPDATE scheme_progress SET current_count=?,benefit_unlocked=?,unlocked_at=IF(?,NOW(),unlocked_at) WHERE scheme_id=? AND atc_id=?");
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE atc_id=? AND status='Active' AND admission_date BETWEEN ? AND ?");

    foreach ($schemes as $s) {
        $cntStmt->execute([$s['atc_id'],$s['start_date'],$s['end_date']]);
        $cnt     = intval($cntStmt->fetchColumn());
        $unlocked = intval($cnt / $s['trigger_count']);
        $upd->execute([$cnt, $unlocked, ($unlocked>0), $s['id'], $s['atc_id']]);
    }
}

// ── Fetch data ─────────────────────────────────────────────────────────────
$schemes = $pdo->query("SELECT s.*,
    (SELECT COUNT(DISTINCT sa.atc_id) FROM scheme_assignments sa WHERE sa.scheme_id=s.id) AS assigned_atcs,
    (SELECT SUM(sp.benefit_unlocked) FROM scheme_progress sp WHERE sp.scheme_id=s.id) AS total_unlocked
    FROM schemes s ORDER BY s.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$atcList = $pdo->query("SELECT id,name,center_type FROM atc_centers WHERE status='Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Scheme Management — Head Office | Gyanam India</title>
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
                <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <div class="header-greeting">
                    <h2>🎁 Scheme Management</h2>
                    <p>Create and manage incentive schemes for ATC centers</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="sc-container">

            <!-- KPI Cards -->
            <div class="sc-kpi-grid">
                <?php
                $total    = count($schemes);
                $active   = count(array_filter($schemes, fn($s) => $s['status']==='Active'));
                $expired  = count(array_filter($schemes, fn($s) => $s['status']==='Expired'));
                $unlocked = array_sum(array_column($schemes,'total_unlocked'));
                ?>
                <div class="sc-kpi-card sc-kpi-blue">
                    <div class="sc-kpi-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12V22H4V12"/><path d="M22 7H2v5h20V7z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg></div>
                    <div class="sc-kpi-content"><div class="sc-kpi-value"><?= $total ?></div><div class="sc-kpi-label">Total Schemes</div></div>
                </div>
                <div class="sc-kpi-card sc-kpi-green">
                    <div class="sc-kpi-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></div>
                    <div class="sc-kpi-content"><div class="sc-kpi-value"><?= $active ?></div><div class="sc-kpi-label">Active Schemes</div></div>
                </div>
                <div class="sc-kpi-card sc-kpi-amber">
                    <div class="sc-kpi-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
                    <div class="sc-kpi-content"><div class="sc-kpi-value"><?= $expired ?></div><div class="sc-kpi-label">Expired</div></div>
                </div>
                <div class="sc-kpi-card sc-kpi-purple">
                    <div class="sc-kpi-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg></div>
                    <div class="sc-kpi-content"><div class="sc-kpi-value"><?= $unlocked ?></div><div class="sc-kpi-label">Benefits Unlocked</div></div>
                </div>
            </div>

            <!-- Toolbar -->
            <div class="sc-toolbar">
                <button onclick="openCreateModal()" class="sc-btn-create">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    New Scheme
                </button>
                <button onclick="syncProgress()" class="sc-btn-sync">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    Sync Progress
                </button>
                <a href="scheme_report.php" class="sc-btn-report">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    View Report
                </a>
            </div>

            <!-- Schemes Grid -->
            <div class="sc-cards-grid">
                <?php if (empty($schemes)): ?>
                    <div style="grid-column:1/-1;text-align:center;padding:4rem;color:#9ca3af">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48" style="display:block;margin:0 auto .75rem"><path d="M20 12V22H4V12"/><path d="M22 7H2v5h20V7z"/><path d="M12 22V7"/></svg>
                        <p style="font-weight:700;font-size:1rem">No schemes yet. Create your first scheme!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($schemes as $sc): ?>
                        <?php
                            $statusClass = match($sc['status']) {
                                'Active' => 'sc-status-active',
                                'Inactive' => 'sc-status-inactive',
                                default => 'sc-status-expired'
                            };
                            $benefitIcon = match($sc['benefit_type']) {
                                'Free Share' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
                                'Cash Incentive' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2" ry="2"/><line x1="12" y1="11" x2="12" y2="13"/><circle cx="12" cy="12" r="1"/></svg>',
                                default => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>'
                            };
                        ?>
                        <div class="sc-card">
                            <div class="sc-card-top">
                                <div class="sc-card-icon"><?= $benefitIcon ?></div>
                                <div class="sc-card-info">
                                    <h4><?= htmlspecialchars($sc['name']) ?></h4>
                                    <span class="sc-type-pill"><?= htmlspecialchars($sc['scheme_type']) ?></span>
                                </div>
                                <span class="sc-status-pill <?= $statusClass ?>"><?= $sc['status'] ?></span>
                            </div>
                            <div class="sc-card-rule">
                                <span class="sc-rule-box">
                                    Every <strong><?= $sc['trigger_count'] ?></strong> admissions →
                                    <strong><?= $sc['benefit_value'] ?></strong> <?= htmlspecialchars($sc['benefit_type']) ?>
                                </span>
                            </div>
                            <div class="sc-card-meta">
                                <span>📅 <?= date('d M Y',strtotime($sc['start_date'])) ?> – <?= date('d M Y',strtotime($sc['end_date'])) ?></span>
                                <span>🏢 <?= htmlspecialchars($sc['applicable_to']) ?><?= $sc['atc_type_filter'] ? " ({$sc['atc_type_filter']})" : '' ?></span>
                            </div>
                            <div class="sc-card-stats">
                                <div class="sc-stat"><div class="sc-stat-val"><?= $sc['assigned_atcs'] ?? 0 ?></div><div class="sc-stat-lbl">ATCs Assigned</div></div>
                                <div class="sc-stat"><div class="sc-stat-val"><?= $sc['total_unlocked'] ?? 0 ?></div><div class="sc-stat-lbl">Benefits Given</div></div>
                            </div>
                            <?php if ($sc['description']): ?>
                            <p class="sc-card-desc"><?= htmlspecialchars($sc['description']) ?></p>
                            <?php endif; ?>
                            <div class="sc-card-actions">
                                <?php if ($sc['status'] === 'Active'): ?>
                                    <button onclick="updateStatus(<?= $sc['id'] ?>,'Inactive')" class="sc-btn-sm sc-btn-warn">Deactivate</button>
                                <?php elseif ($sc['status'] === 'Inactive'): ?>
                                    <button onclick="updateStatus(<?= $sc['id'] ?>,'Active')" class="sc-btn-sm sc-btn-success">Activate</button>
                                <?php endif; ?>
                                <button onclick="deleteScheme(<?= $sc['id'] ?>,<?= json_encode($sc['name']) ?>)" class="sc-btn-sm sc-btn-danger">Delete</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<!-- Create Modal -->
<div class="sc-overlay" id="createModal">
    <div class="sc-modal">
        <div class="sc-modal-header">
            <h3>🎁 Create New Scheme</h3>
            <button onclick="closeCreateModal()" class="sc-modal-x" title="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="sc-modal-body">
            <div class="sc-form-row">
                <div class="sc-form-group" style="grid-column:1/-1">
                    <label>Scheme Name *</label>
                    <input type="text" id="f_name" class="sc-input" placeholder='e.g. "10+1 Free Admission Scheme"'>
                </div>
                <div class="sc-form-group">
                    <label>Scheme Type *</label>
                    <select id="f_type" class="sc-input">
                        <option value="Admission Count">Admission Count</option>
                        <option value="Revenue Target">Revenue Target</option>
                        <option value="Custom">Custom</option>
                    </select>
                </div>
                <div class="sc-form-group">
                    <label>Trigger Count * <small style="color:#6b7280">(e.g. 10 admissions)</small></label>
                    <input type="number" id="f_trigger" class="sc-input" min="1" value="10">
                </div>
                <div class="sc-form-group">
                    <label>Benefit Type *</label>
                    <select id="f_benefit_type" class="sc-input">
                        <option value="Free Share">Free Share (no HO share payment)</option>
                        <option value="Discount">Discount</option>
                        <option value="Cash Incentive">Cash Incentive</option>
                    </select>
                </div>
                <div class="sc-form-group">
                    <label>Benefit Value * <small style="color:#6b7280">(e.g. 1 free)</small></label>
                    <input type="number" id="f_benefit_val" class="sc-input" min="1" value="1">
                </div>
                <div class="sc-form-group">
                    <label>Applicable To *</label>
                    <select id="f_applicable" class="sc-input" onchange="toggleAtcFilter()">
                        <option value="All ATCs">All ATCs</option>
                        <option value="ATC Type">By ATC Type</option>
                    </select>
                </div>
                <div class="sc-form-group" id="atcTypeRow" style="display:none">
                    <label>Center Type Filter</label>
                    <select id="f_atc_type" class="sc-input">
                        <option value="">— Any —</option>
                        <option value="Abacus">Abacus</option>
                        <option value="IT">IT</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="sc-form-group">
                    <label>Start Date *</label>
                    <input type="date" id="f_start" class="sc-input" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="sc-form-group">
                    <label>End Date *</label>
                    <input type="date" id="f_end" class="sc-input" value="<?= date('Y-m-d', strtotime('+1 year')) ?>">
                </div>
                <div class="sc-form-group" style="grid-column:1/-1">
                    <label>Description <small style="color:#6b7280">(optional)</small></label>
                    <textarea id="f_desc" class="sc-input" rows="2" placeholder="Brief explanation of this scheme..."></textarea>
                </div>
                <div class="sc-form-group">
                    <label>Status</label>
                    <select id="f_status" class="sc-input">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive (save for later)</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="sc-modal-footer">
            <button onclick="closeCreateModal()" class="sc-btn-cancel">Cancel</button>
            <button onclick="saveScheme()" class="sc-btn-save">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Create Scheme
            </button>
        </div>
    </div>
</div>

<div id="toastCont" style="position:fixed;bottom:1.5rem;right:1.5rem;z-index:99999;display:flex;flex-direction:column;gap:.5rem"></div>
<script src="../assets/js/dashboard.js"></script>
<script>
function toggleAtcFilter() {
    const v = document.getElementById('f_applicable').value;
    document.getElementById('atcTypeRow').style.display = v === 'ATC Type' ? '' : 'none';
}

function openCreateModal() { document.getElementById('createModal').classList.add('active'); }
function closeCreateModal(){ document.getElementById('createModal').classList.remove('active'); }

async function saveScheme() {
    const fd = new FormData();
    fd.append('action','create_scheme');
    fd.append('name', document.getElementById('f_name').value.trim());
    fd.append('scheme_type', document.getElementById('f_type').value);
    fd.append('trigger_count', document.getElementById('f_trigger').value);
    fd.append('benefit_type', document.getElementById('f_benefit_type').value);
    fd.append('benefit_value', document.getElementById('f_benefit_val').value);
    fd.append('applicable_to', document.getElementById('f_applicable').value);
    fd.append('atc_type_filter', document.getElementById('f_atc_type').value);
    fd.append('description', document.getElementById('f_desc').value);
    fd.append('start_date', document.getElementById('f_start').value);
    fd.append('end_date', document.getElementById('f_end').value);
    fd.append('status', document.getElementById('f_status').value);
    const r = await (await fetch('', {method:'POST', body:fd})).json();
    toast(r.message, r.success ? 'success':'error');
    if (r.success) { closeCreateModal(); setTimeout(()=>location.reload(),900); }
}

async function updateStatus(id, status) {
    const fd = new FormData(); fd.append('action','update_status'); fd.append('id',id); fd.append('status',status);
    const r = await (await fetch('', {method:'POST', body:fd})).json();
    toast(r.message, r.success?'success':'error');
    if (r.success) setTimeout(()=>location.reload(),700);
}

async function deleteScheme(id, name) {
    if (!confirm(`Delete scheme "${name}"? This cannot be undone.`)) return;
    const fd = new FormData(); fd.append('action','delete_scheme'); fd.append('id',id);
    const r = await (await fetch('', {method:'POST', body:fd})).json();
    toast(r.message, r.success?'success':'error');
    if (r.success) setTimeout(()=>location.reload(),700);
}

async function syncProgress() {
    const fd = new FormData(); fd.append('action','sync_progress');
    const btn = event.currentTarget; btn.disabled=true; btn.textContent='Syncing…';
    const r = await (await fetch('', {method:'POST', body:fd})).json();
    toast(r.message, r.success?'success':'error');
    btn.disabled=false; btn.innerHTML='↻ Sync Progress';
    if (r.success) setTimeout(()=>location.reload(),900);
}

function toast(msg, type='success') {
    const t = document.createElement('div');
    t.style.cssText=`background:${type==='success'?'#059669':'#dc2626'};color:#fff;padding:.75rem 1.25rem;border-radius:10px;font-size:.875rem;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.18);max-width:320px`;
    t.textContent=msg; document.getElementById('toastCont').appendChild(t);
    setTimeout(()=>t.remove(),3500);
}
</script>
<style>
:root{--font:'Sora',sans-serif;--mono:'JetBrains Mono',monospace;--brand:#4361ee;--purple:#8b5cf6;--emerald:#10b981;--amber:#f59e0b;}
body{font-family:var(--font)}
.sc-container{padding:1.75rem 2rem;width:100%;box-sizing:border-box}

/* KPI */
.sc-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1.25rem;margin-bottom:1.75rem}
@media(max-width:1100px){.sc-kpi-grid{grid-template-columns:repeat(2,1fr)}}
.sc-kpi-card{border-radius:16px;padding:1.25rem 1.5rem;display:flex;align-items:center;gap:1rem;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1.5px solid;border-left-width:4px;transition:all .3s}
.sc-kpi-card:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,.13)}
.sc-kpi-blue{background:linear-gradient(135deg,#eef1fd,#e0e7ff);border-color:#c7d2fe;border-left-color:var(--brand)}
.sc-kpi-green{background:linear-gradient(135deg,#d1fae5,#a7f3d0);border-color:#6ee7b7;border-left-color:var(--emerald)}
.sc-kpi-purple{background:linear-gradient(135deg,#ede9fe,#ddd6fe);border-color:#c4b5fd;border-left-color:var(--purple)}
.sc-kpi-amber{background:linear-gradient(135deg,#fef3c7,#fde68a);border-color:#fcd34d;border-left-color:var(--amber)}
.sc-kpi-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,.22)}
.sc-kpi-blue .sc-kpi-icon{background:linear-gradient(135deg,var(--brand),#3730a3)}
.sc-kpi-green .sc-kpi-icon{background:linear-gradient(135deg,var(--emerald),#059669)}
.sc-kpi-purple .sc-kpi-icon{background:linear-gradient(135deg,var(--purple),#7c3aed)}
.sc-kpi-amber .sc-kpi-icon{background:linear-gradient(135deg,var(--amber),#d97706)}
.sc-kpi-icon svg{width:22px;height:22px;stroke:#fff}
.sc-kpi-value{font-size:1.6rem;font-weight:800;font-family:var(--mono);line-height:1}
.sc-kpi-blue .sc-kpi-value{color:var(--brand)}.sc-kpi-green .sc-kpi-value{color:var(--emerald)}.sc-kpi-purple .sc-kpi-value{color:var(--purple)}.sc-kpi-amber .sc-kpi-value{color:#d97706}
.sc-kpi-label{font-size:.78rem;font-weight:600;color:#6b7280;margin-top:.2rem}

/* toolbar */
.sc-toolbar{display:flex;align-items:center;gap:.75rem;margin-bottom:1.75rem;flex-wrap:wrap}
.sc-btn-create{display:inline-flex;align-items:center;gap:.5rem;padding:0 1.25rem;height:42px;border-radius:10px;font-weight:700;font-size:.875rem;color:#fff;background:linear-gradient(135deg,var(--brand),#3730a3);border:none;cursor:pointer;box-shadow:0 2px 8px rgba(67,97,238,.25);transition:all .2s;font-family:var(--font)}
.sc-btn-create:hover{transform:translateY(-2px);box-shadow:0 4px 16px rgba(67,97,238,.35)}
.sc-btn-create svg{width:16px;height:16px}
.sc-btn-sync{display:inline-flex;align-items:center;gap:.5rem;padding:0 1.25rem;height:42px;border-radius:10px;font-weight:700;font-size:.875rem;color:#fff;background:linear-gradient(135deg,#0284c7,#0369a1);border:none;cursor:pointer;transition:all .2s;font-family:var(--font)}
.sc-btn-sync:hover{transform:translateY(-2px)}
.sc-btn-sync svg{width:16px;height:16px}
.sc-btn-report{display:inline-flex;align-items:center;gap:.5rem;padding:0 1.25rem;height:42px;border-radius:10px;font-weight:700;font-size:.875rem;color:#fff;background:linear-gradient(135deg,var(--purple),#7c3aed);border:none;cursor:pointer;text-decoration:none;transition:all .2s}
.sc-btn-report:hover{transform:translateY(-2px)}
.sc-btn-report svg{width:16px;height:16px}

/* Cards grid */
.sc-cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1.5rem}
.sc-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:1.5rem;box-shadow:0 1px 2px rgba(0,0,0,.04);transition:all .2s;display:flex;flex-direction:column;gap:1.2rem;position:relative;overflow:hidden}
.sc-card:hover{box-shadow:0 10px 25px -5px rgba(0,0,0,.08), 0 8px 10px -6px rgba(0,0,0,.04);border-color:#d1d5db;transform:translateY(-2px)}
.sc-card::before{content:'';position:absolute;top:0;left:0;width:100%;height:3px;background:var(--brand);opacity:0.8}
.sc-card-top{display:flex;align-items:flex-start;gap:1rem}
.sc-card-icon{width:40px;height:40px;border-radius:8px;background:#f3f4f6;color:var(--brand);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.sc-card-icon svg{width:20px;height:20px}
.sc-card-info{flex:1}
.sc-card-info h4{font-size:1.05rem;font-weight:700;color:#111827;margin:0 0 .4rem;line-height:1.3}
.sc-type-pill{font-size:.7rem;font-weight:600;background:#f3f4f6;color:#4b5563;padding:.2rem .5rem;border-radius:4px;text-transform:uppercase;letter-spacing:.03em}
.sc-status-pill{font-size:.7rem;font-weight:700;padding:.25rem .65rem;border-radius:999px;flex-shrink:0}
.sc-status-active{background:#ecfdf5;color:#059669;border:1px solid #a7f3d0}
.sc-status-inactive{background:#f9fafb;color:#4b5563;border:1px solid #e5e7eb}
.sc-status-expired{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
.sc-card-rule{background:#f9fafb;border-left:3px solid var(--purple);border-radius:0 6px 6px 0;padding:.85rem 1rem;font-size:.85rem;color:#374151}
.sc-rule-box strong{color:var(--brand);font-weight:700}
.sc-card-meta{display:flex;flex-direction:column;gap:.4rem;font-size:.78rem;color:#6b7280}
.sc-card-stats{display:flex;gap:1rem;padding:.75rem;background:#f9fafb;border-radius:10px}
.sc-stat{text-align:center;flex:1}
.sc-stat-val{font-size:1.3rem;font-weight:800;font-family:var(--mono);color:var(--brand)}
.sc-stat-lbl{font-size:.72rem;color:#6b7280;font-weight:600}
.sc-card-desc{font-size:.8rem;color:#6b7280;font-style:italic;margin:0}
.sc-card-actions{display:flex;gap:.5rem;margin-top:.25rem}
.sc-btn-sm{padding:.4rem .875rem;border-radius:8px;font-size:.78rem;font-weight:700;border:none;cursor:pointer;font-family:var(--font)}
.sc-btn-warn{background:#fef3c7;color:#d97706}.sc-btn-warn:hover{background:#fde68a}
.sc-btn-success{background:#d1fae5;color:#059669}.sc-btn-success:hover{background:#a7f3d0}
.sc-btn-danger{background:#fee2e2;color:#dc2626}.sc-btn-danger:hover{background:#fecaca}

/* Modal */
.sc-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:1rem}
.sc-overlay.active{display:flex}
.sc-modal{background:#fff;border-radius:20px;width:100%;max-width:680px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.25)}
.sc-modal-header{display:flex;align-items:center;justify-content:space-between;padding:1.25rem 1.5rem;border-bottom:1px solid #f3f4f6;flex-shrink:0}
.sc-modal-header h3{font-size:1.1rem;font-weight:800;color:#1f2937;margin:0}
.sc-modal-x{width:36px;height:36px;background:#fee2e2;border-radius:10px;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center}
.sc-modal-x svg{width:18px;height:18px}
.sc-modal-body{padding:1.5rem;overflow-y:auto;flex:1}
.sc-modal-footer{display:flex;justify-content:flex-end;gap:.75rem;padding:1rem 1.5rem;border-top:1px solid #f3f4f6;flex-shrink:0}
.sc-form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.sc-form-group{display:flex;flex-direction:column;gap:.35rem}
.sc-form-group label{font-size:.82rem;font-weight:700;color:#374151}
.sc-input{height:42px;padding:0 .875rem;border:1.5px solid #e5e7eb;border-radius:10px;font-family:var(--font);font-size:.875rem;color:#1f2937;transition:border-color .2s}
.sc-input:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 4px rgba(67,97,238,.1)}
textarea.sc-input{height:auto;padding:.75rem .875rem;resize:vertical}
.sc-btn-cancel{padding:.6rem 1.4rem;border-radius:10px;border:1.5px solid #e5e7eb;background:#fff;font-size:.875rem;font-weight:700;color:#374151;cursor:pointer;font-family:var(--font)}
.sc-btn-save{display:inline-flex;align-items:center;gap:.4rem;padding:.6rem 1.4rem;border-radius:10px;border:none;background:linear-gradient(135deg,var(--brand),#3730a3);color:#fff;font-size:.875rem;font-weight:700;cursor:pointer;font-family:var(--font)}
</style>
</body>
</html>
