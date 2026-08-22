<?php
/**
 * Gyanam Portal — Admin: Inventory Management
 * Track materials received, dispatched, and current stock levels.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());

// ── Auto-create tables if missing (once, then flagged) ────────────────────
ensureInventoryTables($pdo);
// Seed defaults only on first create (when flag was just missing and table empty)
try {
    if (!isSchemaFlagSet('schema_inventory_seeded_v1')) {
        $check = (int)$pdo->query("SELECT COUNT(*) FROM inventory_items")->fetchColumn();
        if ($check === 0) {
            $sizes = ['36','38','40','42','44','46'];
            $ins = $pdo->prepare("INSERT INTO inventory_items (item_name, category, unit, current_stock, min_stock_level, description) VALUES (?,?,?,0,?,?)");
            foreach ($sizes as $sz) {
                $ins->execute(["T-Shirt - Size $sz", 'T-Shirts', 'pcs', 5, "T-Shirt size $sz for Abacus students"]);
            }
            $certIns = $pdo->prepare("INSERT INTO inventory_items (item_name, category, unit, current_stock, min_stock_level, description) VALUES (?,?,?,0,?,?)");
            $certIns->execute(['Course Completion Certificate', 'Certificates', 'pcs', 10, 'Physical course completion certificate dispatched to ATCs']);
            $certIns->execute(['Exam Certificate', 'Certificates', 'pcs', 10, 'Physical exam certificate dispatched to ATCs']);
        }
        markSchemaFlag('schema_inventory_seeded_v1');
    }
} catch (Exception $e) {}

// ── AJAX handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        switch ($_POST['action']) {

            case 'add_item':
                $name = trim($_POST['item_name'] ?? '');
                $cat  = trim($_POST['category'] ?? 'Books') ?: 'Books';
                $unit = trim($_POST['unit'] ?? 'pcs');
                $min  = max(0, intval($_POST['min_stock_level'] ?? 10));
                $desc = trim($_POST['description'] ?? '');
                if (!$name) { echo json_encode(['success'=>false,'message'=>'Item name is required']); exit; }
                // Check duplicate
                $dup = $pdo->prepare("SELECT id FROM inventory_items WHERE item_name = ?");
                $dup->execute([$name]);
                if ($dup->fetch()) { echo json_encode(['success'=>false,'message'=>'Item with this name already exists']); exit; }
                // Ensure category exists in master list
                try { $pdo->prepare("INSERT IGNORE INTO inventory_categories (name) VALUES (?)")->execute([$cat]); } catch (Exception $e) {}
                $stmt = $pdo->prepare("INSERT INTO inventory_items (item_name,category,unit,cost,min_stock_level,description) VALUES (?,?,?,NULL,?,?)");
                $stmt->execute([$name, $cat, $unit, $min, $desc]);
                echo json_encode(['success'=>true,'message'=>'Item added successfully','id'=>$pdo->lastInsertId()]);
                exit;

            case 'edit_item':
                $id   = intval($_POST['id']);
                $name = trim($_POST['item_name'] ?? '');
                $cat  = trim($_POST['category'] ?? 'Books') ?: 'Books';
                $unit = trim($_POST['unit'] ?? 'pcs');
                $min  = max(0, intval($_POST['min_stock_level'] ?? 10));
                $desc = trim($_POST['description'] ?? '');
                $status = $_POST['status'] ?? 'Active';
                if (!$name || !$id) { echo json_encode(['success'=>false,'message'=>'Invalid data']); exit; }
                try { $pdo->prepare("INSERT IGNORE INTO inventory_categories (name) VALUES (?)")->execute([$cat]); } catch (Exception $e) {}
                $stmt = $pdo->prepare("UPDATE inventory_items SET item_name=?,category=?,unit=?,min_stock_level=?,description=?,status=? WHERE id=?");
                $stmt->execute([$name, $cat, $unit, $min, $desc, $status, $id]);
                echo json_encode(['success'=>true,'message'=>'Item updated']);
                exit;

            case 'add_category':
                $catName = trim($_POST['name'] ?? '');
                if ($catName === '') { echo json_encode(['success'=>false,'message'=>'Category name is required']); exit; }
                if (mb_strlen($catName) > 100) { echo json_encode(['success'=>false,'message'=>'Category name too long']); exit; }
                try {
                    $pdo->prepare("INSERT INTO inventory_categories (name) VALUES (?)")->execute([$catName]);
                    echo json_encode(['success'=>true,'message'=>'Category created','name'=>$catName]);
                } catch (Exception $e) {
                    echo json_encode(['success'=>false,'message'=>'Category already exists']);
                }
                exit;

            case 'stock_in':
                $itemId       = intval($_POST['item_id']);
                $qty          = intval($_POST['quantity']);
                $rate         = floatval($_POST['cost'] ?? 0);
                $ref          = trim($_POST['reference_no'] ?? '');
                $supplier     = trim($_POST['supplier'] ?? '');
                $notes        = trim($_POST['notes'] ?? '');
                $purchaseDate = trim($_POST['purchase_date'] ?? '') ?: null;
                if (!$itemId || $qty <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid item or quantity']); exit; }
                if ($rate <= 0) { echo json_encode(['success'=>false,'message'=>'Cost per item is required for stock in']); exit; }
                $itemRow = $pdo->prepare("SELECT current_stock FROM inventory_items WHERE id = ?");
                $itemRow->execute([$itemId]);
                $itemData = $itemRow->fetch(PDO::FETCH_ASSOC);
                if (!$itemData) { echo json_encode(['success'=>false,'message'=>'Item not found']); exit; }
                $total = round($rate * $qty, 2);
                $pdo->beginTransaction();
                // Update stock + last known unit cost from this stock-in
                $pdo->prepare("UPDATE inventory_items SET current_stock = current_stock + ?, cost = ? WHERE id = ?")
                    ->execute([$qty, $rate, $itemId]);
                $newBal = intval($itemData['current_stock']) + $qty;
                $pdo->prepare("INSERT INTO inventory_transactions (item_id,type,quantity,rate_per_item,total_amount,running_balance,reference_no,supplier,purchase_date,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$itemId, 'Stock In', $qty, $rate, $total, $newBal, $ref ?: null, $supplier ?: null, $purchaseDate, $notes ?: null, $_SESSION['user_id'] ?? null]);
                $pdo->commit();
                $totalStr = ' | Total: ₹' . number_format($total, 2);
                echo json_encode(['success'=>true,'message'=>"$qty units added. New balance: $newBal$totalStr",'new_stock'=>$newBal]);
                exit;

            case 'adjustment':
                $itemId = intval($_POST['item_id']);
                $qty    = intval($_POST['quantity']); // can be negative
                $notes  = trim($_POST['notes'] ?? '');
                if (!$itemId || $qty == 0) { echo json_encode(['success'=>false,'message'=>'Invalid data']); exit; }
                $pdo->beginTransaction();
                $cur = $pdo->prepare("SELECT current_stock FROM inventory_items WHERE id = ?");
                $cur->execute([$itemId]); $stock = intval($cur->fetchColumn());
                if ($stock + $qty < 0) { $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>'Adjustment would make stock negative']); exit; }
                $pdo->prepare("UPDATE inventory_items SET current_stock = current_stock + ? WHERE id = ?")->execute([$qty, $itemId]);
                $newBal = $stock + $qty;
                $pdo->prepare("INSERT INTO inventory_transactions (item_id,type,quantity,running_balance,notes,created_by) VALUES (?,?,?,?,?,?)")
                    ->execute([$itemId, 'Adjustment', $qty, $newBal, $notes ?: null, $_SESSION['user_id'] ?? null]);
                $pdo->commit();
                echo json_encode(['success'=>true,'message'=>"Adjusted by $qty. New balance: $newBal",'new_stock'=>$newBal]);
                exit;

            case 'get_transactions':
                $itemId = intval($_POST['item_id']);
                $stmt = $pdo->prepare("SELECT t.*, u.name as user_name FROM inventory_transactions t LEFT JOIN users u ON t.created_by = u.id WHERE t.item_id = ? ORDER BY t.created_at DESC LIMIT 50");
                $stmt->execute([$itemId]);
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
                exit;

            case 'delete_item':
                $id = intval($_POST['id']);
                $pdo->prepare("DELETE FROM inventory_items WHERE id = ?")->execute([$id]);
                echo json_encode(['success'=>true,'message'=>'Item deleted']);
                exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ── Fetch data ────────────────────────────────────────────────────────────
$catFilter = trim($_GET['category'] ?? 'all');
$searchQ   = trim($_GET['q'] ?? '');

// Categories master list
$categories = [];
try {
    $categories = $pdo->query("SELECT name FROM inventory_categories ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}
if (empty($categories)) {
    $categories = ['Books', 'T-Shirts', 'Certificates', 'Stationery', 'Other'];
}

$sql = "SELECT * FROM inventory_items WHERE status = 'Active'";
$params = [];
if ($catFilter !== 'all' && $catFilter !== '') {
    $sql .= " AND category = ?";
    $params[] = $catFilter;
}
if ($searchQ !== '') {
    $sql .= " AND (item_name LIKE ? OR description LIKE ?)";
    $params[] = '%' . $searchQ . '%';
    $params[] = '%' . $searchQ . '%';
}
$sql .= " ORDER BY category, item_name";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$allItems = $pdo->query("SELECT * FROM inventory_items WHERE status = 'Active' ORDER BY item_name")->fetchAll(PDO::FETCH_ASSOC);

// KPIs
$totalItems = count($allItems);
$totalStock = array_sum(array_column($allItems, 'current_stock'));
$lowStock = count(array_filter($allItems, fn($i) => $i['current_stock'] <= $i['min_stock_level'] && $i['current_stock'] > 0));
$outOfStock = count(array_filter($allItems, fn($i) => $i['current_stock'] == 0));

// Category-wise stock summary (with min stock visibility)
$categorySummary = [];
try {
    $categorySummary = $pdo->query("
        SELECT
            category,
            COUNT(*) AS item_count,
            COALESCE(SUM(current_stock), 0) AS total_stock,
            COALESCE(SUM(min_stock_level), 0) AS total_min_level,
            SUM(CASE WHEN current_stock <= 0 THEN 1 ELSE 0 END) AS out_count,
            SUM(CASE WHEN current_stock > 0 AND current_stock <= min_stock_level THEN 1 ELSE 0 END) AS low_count
        FROM inventory_items
        WHERE status = 'Active'
        GROUP BY category
        ORDER BY category ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $categorySummary = [];
}

// Recent transactions
$recentTxns = $pdo->query("SELECT t.*, i.item_name, i.category, u.name as user_name FROM inventory_transactions t JOIN inventory_items i ON t.item_id = i.id LEFT JOIN users u ON t.created_by = u.id ORDER BY t.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function invCatQuery(string $cat, string $q = ''): string {
    $params = [];
    if ($cat !== 'all' && $cat !== '') $params['category'] = $cat;
    if ($q !== '') $params['q'] = $q;
    return $params ? ('?' . http_build_query($params)) : '?category=all';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inventory Management — Admin | Gyanam India</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<link rel="stylesheet" href="../assets/css/notifications.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📦</text></svg>">
<style>
:root{--font:'Sora',sans-serif;--mono:'JetBrains Mono',monospace;--bg:#f4f6fb;--surface:#fff;--border:#e6eaf3;--text:#111827;--text-2:#374151;--text-3:#6b7280;--brand:#4361ee;--brand-dark:#3451d1;--brand-light:#eef1fd;--emerald:#10b981;--amber:#f59e0b;--rose:#f43f5e;--shadow-sm:0 1px 4px rgba(0,0,0,.06);--shadow-md:0 4px 16px rgba(0,0,0,.08);--r-md:10px;--r-lg:14px;--r-xl:18px;--r-2xl:24px;--t:.18s ease}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text)}

/* KPI */
.inv-kpi{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.25rem}
.inv-kpi-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--r-xl);padding:1.25rem 1.5rem;position:relative;overflow:hidden;box-shadow:var(--shadow-sm);transition:transform var(--t)}
.inv-kpi-card:hover{transform:translateY(-3px)}
.inv-kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.inv-kpi-card.blue::before{background:linear-gradient(90deg,#4361ee,#818cf8)}
.inv-kpi-card.green::before{background:linear-gradient(90deg,#10b981,#34d399)}
.inv-kpi-card.amber::before{background:linear-gradient(90deg,#f59e0b,#fbbf24)}
.inv-kpi-card.rose::before{background:linear-gradient(90deg,#f43f5e,#fb7185)}
.inv-kpi-val{font-size:2rem;font-weight:800;line-height:1;margin-bottom:.3rem}
.inv-kpi-lbl{font-size:.72rem;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em}

.cat-summary{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.85rem;margin-bottom:1.5rem}
.cat-sum-card{background:#fff;border:1.5px solid var(--border);border-radius:14px;padding:1rem 1.1rem;box-shadow:var(--shadow-sm)}
.cat-sum-name{font-size:.88rem;font-weight:800;color:var(--text);margin-bottom:.65rem}
.cat-sum-grid{display:grid;grid-template-columns:1fr 1fr;gap:.45rem .6rem}
.cat-sum-metric{display:flex;flex-direction:column;gap:.1rem}
.cat-sum-metric span{font-size:.65rem;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.04em}
.cat-sum-metric strong{font-size:.95rem;font-weight:800;color:var(--text)}
.cat-sum-metric strong.low{color:#d97706}
.cat-sum-metric strong.out{color:#e11d48}
.cat-sum-metric strong.ok{color:#059669}

.inv-search{height:38px;padding:0 .9rem;border:1.5px solid var(--border);border-radius:10px;font:800 .82rem var(--font);outline:none;min-width:200px;flex:1;max-width:280px}
.inv-search:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(67,97,238,.12)}
.inv-search-form{display:flex;gap:.45rem;align-items:center;flex-wrap:wrap;flex:1}

/* Toolbar */
.inv-toolbar{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem}
.inv-toolbar-left{display:flex;gap:.5rem;flex-wrap:wrap}
.inv-cat-btn{padding:.5rem 1rem;border-radius:999px;border:1.5px solid var(--border);background:var(--surface);font:700 .8rem var(--font);color:var(--text-2);cursor:pointer;transition:all var(--t);text-decoration:none}
.inv-cat-btn:hover,.inv-cat-btn.active{background:var(--brand-light);border-color:#a5b4fc;color:#1e3a8a}
.inv-toolbar-right{display:flex;gap:.5rem}

/* Buttons */
.btn-inv{display:inline-flex;align-items:center;gap:.4rem;padding:.6rem 1.1rem;border-radius:var(--r-md);font:700 .84rem var(--font);cursor:pointer;border:none;transition:all var(--t)}
.btn-inv.primary{background:linear-gradient(135deg,var(--brand),var(--brand-dark));color:#fff;box-shadow:0 4px 14px rgba(67,97,238,.25)}
.btn-inv.primary:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(67,97,238,.35)}
.btn-inv.green{background:linear-gradient(135deg,var(--emerald),#059669);color:#fff;box-shadow:0 4px 14px rgba(16,185,129,.25)}
.btn-inv.green:hover{transform:translateY(-2px)}
.btn-inv.outline{background:var(--surface);border:1.5px solid var(--border);color:var(--text-2)}
.btn-inv.outline:hover{border-color:var(--brand);color:var(--brand)}
.btn-inv svg{width:16px;height:16px}

/* Table */
.inv-panel{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--r-2xl);box-shadow:var(--shadow-sm);overflow:hidden;margin-bottom:1.75rem}
.inv-panel-head{padding:1rem 1.5rem;border-bottom:1.5px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.inv-panel-title{font-size:.95rem;font-weight:800}
.inv-tbl{width:100%;border-collapse:collapse}
.inv-tbl thead th{padding:.8rem 1.25rem;text-align:left;font-size:.68rem;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:var(--text-3);background:#f8fafc;border-bottom:1.5px solid var(--border);white-space:nowrap}
.inv-tbl tbody td{padding:.85rem 1.25rem;border-bottom:1px solid #f1f3f7;font-size:.875rem;vertical-align:middle}
.inv-tbl tbody tr:last-child td{border-bottom:none}
.inv-tbl tbody tr:hover td{background:#fafbff}

/* Stock badges */
.stock-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .65rem;border-radius:999px;font-size:.72rem;font-weight:800}
.stock-badge.ok{background:#ecfdf5;color:#059669}
.stock-badge.low{background:#fffbeb;color:#d97706}
.stock-badge.out{background:#fee2e2;color:#dc2626}
.stock-num{font-family:var(--mono);font-weight:800;font-size:1rem}
.cat-pill{display:inline-flex;padding:.2rem .6rem;border-radius:6px;font-size:.7rem;font-weight:700}
.cat-pill.books{background:#eef2ff;color:#4361ee}
.cat-pill.t-shirts{background:#fdf4ff;color:#a855f7}
.cat-pill.stationery{background:#fef3c7;color:#d97706}
.cat-pill.other{background:#f1f5f9;color:#64748b}

/* Action btns */
.inv-act{width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;border:1.5px solid var(--border);background:var(--surface);cursor:pointer;transition:all var(--t)}
.inv-act svg{width:14px;height:14px;stroke:var(--text-3)}
.inv-act:hover{border-color:var(--brand);background:var(--brand-light)}
.inv-act:hover svg{stroke:var(--brand)}
.inv-act.danger:hover{border-color:#fca5a5;background:#fff1f2}
.inv-act.danger:hover svg{stroke:var(--rose)}

/* Txn type badges */
.txn-type{display:inline-flex;padding:.2rem .55rem;border-radius:6px;font-size:.7rem;font-weight:700}
.txn-type.stock-in{background:#ecfdf5;color:#059669}
.txn-type.stock-out,.txn-type.dispatch{background:#fee2e2;color:#dc2626}
.txn-type.adjustment{background:#fef3c7;color:#d97706}
.txn-type.return{background:#eef2ff;color:#4361ee}

/* Modal */
.inv-modal-overlay{position:fixed;inset:0;background:rgba(10,15,30,.55);backdrop-filter:blur(5px);z-index:5000;display:none;align-items:center;justify-content:center;padding:1.5rem}
.inv-modal-overlay.open{display:flex}
.inv-modal{background:#fff;border-radius:20px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 24px 64px rgba(0,0,0,.2);animation:invSlide .25s ease}
@keyframes invSlide{from{opacity:0;transform:scale(.95) translateY(12px)}to{opacity:1;transform:none}}
.inv-modal-header{display:flex;align-items:center;justify-content:space-between;padding:1.25rem 1.5rem;border-bottom:1.5px solid var(--border);background:linear-gradient(135deg,#eef2ff,#ede9fe)}
.inv-modal-header h3{font-size:1.05rem;font-weight:800;display:flex;align-items:center;gap:.5rem}
.inv-modal-close{width:32px;height:32px;border:1.5px solid var(--border);border-radius:8px;background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center}
.inv-modal-close svg{width:14px;height:14px;stroke:var(--text-3)}
.inv-modal-body{padding:1.5rem}
.inv-modal-footer{padding:1rem 1.5rem;border-top:1.5px solid var(--border);display:flex;justify-content:flex-end;gap:.6rem;background:#f8fafc}
.inv-field{display:flex;flex-direction:column;gap:.35rem;margin-bottom:1rem}
.inv-field label{font-size:.78rem;font-weight:700;color:var(--text-2)}
.inv-field input,.inv-field select,.inv-field textarea{padding:.65rem .875rem;border:1.5px solid var(--border);border-radius:var(--r-md);font:.875rem var(--font);color:var(--text);outline:none;transition:border-color var(--t)}
.inv-field input:focus,.inv-field select:focus,.inv-field textarea:focus{border-color:var(--brand)}
.inv-field-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}

/* Toast */
.inv-toast{position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;transform:translateY(100px);opacity:0;transition:all .3s cubic-bezier(.34,1.56,.64,1);background:#111827;color:#fff;padding:.875rem 1.25rem;border-radius:14px;font-size:.875rem;font-weight:600;box-shadow:var(--shadow-md);max-width:380px}
.inv-toast.show{transform:translateY(0);opacity:1}

/* Responsive */
@media(max-width:900px){.inv-kpi{grid-template-columns:repeat(2,1fr)}.inv-field-row{grid-template-columns:1fr}}
@media(max-width:560px){.inv-kpi{grid-template-columns:1fr}.inv-toolbar{flex-direction:column;align-items:stretch}}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
    <header class="top-header">
        <div class="header-left">
            <button class="hamburger" id="hamburgerBtn"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
            <div class="header-greeting"><h2>📦 Inventory Management</h2><p>Track materials, stock levels & dispatches</p></div>
        </div>
        <div class="header-right">
            <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
            <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
        </div>
    </header>

    <div class="page-content">

    <!-- KPIs -->
    <div class="inv-kpi">
        <div class="inv-kpi-card blue"><div class="inv-kpi-val"><?= $totalItems ?></div><div class="inv-kpi-lbl">Total Items</div></div>
        <div class="inv-kpi-card green"><div class="inv-kpi-val"><?= number_format($totalStock) ?></div><div class="inv-kpi-lbl">Total Stock Units</div></div>
        <div class="inv-kpi-card amber"><div class="inv-kpi-val"><?= $lowStock ?></div><div class="inv-kpi-lbl">Low Stock Items</div></div>
        <div class="inv-kpi-card rose"><div class="inv-kpi-val"><?= $outOfStock ?></div><div class="inv-kpi-lbl">Out of Stock</div></div>
    </div>

    <!-- Category-wise Stock -->
    <?php if (!empty($categorySummary)): ?>
    <div class="inv-panel" style="margin-bottom:1.25rem">
        <div class="inv-panel-head">
            <div class="inv-panel-title">📊 Category-wise Stock</div>
            <span style="font-size:.75rem;font-weight:700;color:var(--text-3)"><?= count($categorySummary) ?> categories</span>
        </div>
        <div style="padding:1rem 1.15rem 1.15rem">
            <div class="cat-summary" style="margin:0">
                <?php foreach ($categorySummary as $cs):
                    $alertClass = ((int)$cs['out_count'] > 0) ? 'out' : (((int)$cs['low_count'] > 0) ? 'low' : 'ok');
                ?>
                <a href="<?= e(invCatQuery($cs['category'], $searchQ)) ?>" class="cat-sum-card" style="text-decoration:none;color:inherit;display:block;transition:border-color .15s,transform .15s" onmouseover="this.style.borderColor='#a5b4fc';this.style.transform='translateY(-2px)'" onmouseout="this.style.borderColor='';this.style.transform=''">
                    <div class="cat-sum-name"><?= e($cs['category']) ?></div>
                    <div class="cat-sum-grid">
                        <div class="cat-sum-metric"><span>Items</span><strong><?= (int)$cs['item_count'] ?></strong></div>
                        <div class="cat-sum-metric"><span>Total Stock</span><strong class="ok"><?= number_format((int)$cs['total_stock']) ?></strong></div>
                        <div class="cat-sum-metric"><span>Min Levels (sum)</span><strong><?= number_format((int)$cs['total_min_level']) ?></strong></div>
                        <div class="cat-sum-metric"><span>Alerts</span><strong class="<?= $alertClass ?>"><?= (int)$cs['low_count'] ?> low · <?= (int)$cs['out_count'] ?> out</strong></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Toolbar -->
    <div class="inv-toolbar">
        <div class="inv-toolbar-left" style="flex-wrap:wrap;flex:1;gap:.45rem">
            <a href="<?= e(invCatQuery('all', $searchQ)) ?>" class="inv-cat-btn <?= $catFilter==='all'?'active':'' ?>">All</a>
            <?php foreach ($categories as $catName): ?>
            <a href="<?= e(invCatQuery($catName, $searchQ)) ?>" class="inv-cat-btn <?= $catFilter===$catName?'active':'' ?>"><?= e($catName) ?></a>
            <?php endforeach; ?>
            <button type="button" class="btn-inv primary" onclick="openModal('addCategoryModal')" title="Create category" style="height:36px;padding:.45rem .95rem;font-size:.8rem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:14px;height:14px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Category
            </button>
        </div>
        <div class="inv-toolbar-right">
            <form class="inv-search-form" method="GET" action="">
                <?php if ($catFilter !== 'all' && $catFilter !== ''): ?>
                <input type="hidden" name="category" value="<?= e($catFilter) ?>">
                <?php endif; ?>
                <input class="inv-search" type="search" name="q" value="<?= e($searchQ) ?>" placeholder="Search item by name…" aria-label="Search items">
                <button type="submit" class="btn-inv outline" style="height:38px">Search</button>
                <?php if ($searchQ !== ''): ?>
                <a class="btn-inv outline" style="height:38px;display:inline-flex;align-items:center" href="<?= e(invCatQuery($catFilter === 'all' ? 'all' : $catFilter)) ?>">Clear</a>
                <?php endif; ?>
            </form>
            <button class="btn-inv green" onclick="openModal('stockInModal')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Stock In
            </button>
            <button class="btn-inv primary" onclick="openModal('addItemModal')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                Add Item
            </button>
        </div>
    </div>

    <!-- Inventory Table -->
    <div class="inv-panel">
        <div class="inv-panel-head">
            <div class="inv-panel-title">📦 Inventory Items<?= $searchQ !== '' ? ' — search: “' . e($searchQ) . '”' : '' ?></div>
            <span style="font-size:.75rem;font-weight:700;color:var(--text-3)"><?= count($items) ?> items</span>
        </div>
        <div style="overflow-x:auto">
        <table class="inv-tbl">
            <thead><tr><th>#</th><th>Item Name</th><th>Category</th><th>Cost (₹)</th><th>Current Stock</th><th>Total Cost (₹)</th><th>Min Level</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (empty($items)): ?>
            <tr><td colspan="9" style="text-align:center;padding:2.5rem;color:var(--text-3)">
                <?= $searchQ !== '' ? 'No items match “' . e($searchQ) . '”.' : 'No inventory items found. Click <strong>Add Item</strong> to get started.' ?>
            </td></tr>
            <?php else: ?>
            <?php $n=0; foreach ($items as $item): $n++;
                $stockClass = $item['current_stock'] <= 0 ? 'out' : ($item['current_stock'] <= $item['min_stock_level'] ? 'low' : 'ok');
                $stockLabel = $stockClass === 'out' ? 'Out of Stock' : ($stockClass === 'low' ? 'Low Stock' : 'In Stock');
                $catClass = strtolower(str_replace(' ','-',$item['category']));
            ?>
            <tr>
                <td style="color:var(--text-3);font-size:.78rem"><?= $n ?></td>
                <td><div style="font-weight:700"><?= e($item['item_name']) ?></div><?php if($item['description']): ?><div style="font-size:.75rem;color:var(--text-3);margin-top:.1rem"><?= e(substr($item['description'],0,60)) ?></div><?php endif; ?></td>
                <td><span class="cat-pill <?= $catClass ?>"><?= e($item['category']) ?></span></td>
                <td style="font-family:var(--mono);font-weight:700;color:#059669"><?= $item['cost'] ? '₹'.number_format($item['cost'],2) : '<span style="color:var(--text-3)">—</span>' ?></td>
                <td><span class="stock-num"><?= $item['current_stock'] ?></span> <span style="font-size:.75rem;color:var(--text-3)"><?= e($item['unit']) ?></span></td>
                <td style="font-family:var(--mono);font-weight:700;color:#1d4ed8"><?php $totalCost = ($item['cost'] && $item['current_stock'] > 0) ? $item['cost'] * $item['current_stock'] : null; echo $totalCost ? '₹'.number_format($totalCost,2) : '<span style="color:var(--text-3)">—</span>'; ?></td>
                <td style="font-size:.84rem;color:var(--text-3)"><?= $item['min_stock_level'] ?></td>
                <td><span class="stock-badge <?= $stockClass ?>"><span style="width:6px;height:6px;border-radius:50%;background:currentColor"></span> <?= $stockLabel ?></span></td>
                <td>
                    <div style="display:flex;gap:.3rem">
                        <button class="inv-act" onclick='openEditItem(<?= json_encode([
                            "id" => (int)$item["id"],
                            "item_name" => $item["item_name"],
                            "category" => $item["category"],
                            "unit" => $item["unit"],
                            "min_stock_level" => (int)$item["min_stock_level"],
                            "description" => $item["description"] ?? "",
                            "status" => $item["status"] ?? "Active",
                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>)' title="Edit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>
                        <button class="inv-act" onclick="openStockInFor(<?= $item['id'] ?>,'<?= e($item['item_name']) ?>')" title="Stock In"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></button>
                        <button class="inv-act" onclick="openAdjustment(<?= $item['id'] ?>,'<?= e($item['item_name']) ?>',<?= $item['current_stock'] ?>)" title="Adjust"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg></button>
                        <button class="inv-act" onclick="viewHistory(<?= $item['id'] ?>,'<?= e($item['item_name']) ?>')" title="History"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></button>
                        <button class="inv-act danger" onclick="deleteItem(<?= $item['id'] ?>,'<?= e($item['item_name']) ?>')" title="Delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Recent Transactions -->
    <div class="inv-panel">
        <div class="inv-panel-head"><div class="inv-panel-title">📋 Recent Transactions</div></div>
        <div style="overflow-x:auto">
        <table class="inv-tbl">
            <thead><tr><th>Date</th><th>Item</th><th>Type</th><th>Qty</th><th>Balance</th><th>Reference</th><th>By</th></tr></thead>
            <tbody>
            <?php if (empty($recentTxns)): ?>
            <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-3)">No transactions recorded yet.</td></tr>
            <?php else: foreach ($recentTxns as $tx):
                $typeClass = strtolower(str_replace(' ','-',$tx['type']));
                $qtyColor = $tx['quantity'] > 0 ? '#059669' : '#dc2626';
                $qtyPrefix = $tx['quantity'] > 0 ? '+' : '';
            ?>
            <tr>
                <td style="font-size:.8rem;white-space:nowrap"><?= date('d M Y, h:i A', strtotime($tx['created_at'])) ?></td>
                <td style="font-weight:600"><?= e($tx['item_name']) ?></td>
                <td><span class="txn-type <?= $typeClass ?>"><?= e($tx['type']) ?></span></td>
                <td style="font-family:var(--mono);font-weight:800;color:<?= $qtyColor ?>"><?= $qtyPrefix . $tx['quantity'] ?></td>
                <td style="font-family:var(--mono);font-weight:700"><?= $tx['running_balance'] ?></td>
                <td style="font-size:.8rem;color:var(--text-3)"><?= e($tx['reference_no'] ?: ($tx['supplier'] ?: '—')) ?></td>
                <td style="font-size:.8rem"><?= e($tx['user_name'] ?? '—') ?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    </div>
</main>
</div>

<!-- Add Item Modal -->
<div class="inv-modal-overlay" id="addItemModal">
<div class="inv-modal">
    <div class="inv-modal-header"><h3>📦 Add New Item</h3><button class="inv-modal-close" onclick="closeModal('addItemModal')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="inv-modal-body">
        <div class="inv-field"><label>Item Name *</label><input type="text" id="ai_name" placeholder="e.g. Abacus Book - English"></div>
        <div class="inv-field-row">
            <div class="inv-field"><label>Category *</label>
                <select id="ai_cat">
                    <?php foreach ($categories as $catName): ?>
                    <option value="<?= e($catName) ?>"><?= e($catName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="inv-field"><label>Unit</label><input type="text" id="ai_unit" value="pcs" placeholder="pcs, sets, boxes"></div>
        </div>
        <div class="inv-field"><label>Min Stock Level (alert threshold) *</label><input type="number" id="ai_min" value="10" min="0"></div>
        <div class="inv-field"><label>Description</label><textarea id="ai_desc" rows="2" placeholder="Optional description..."></textarea></div>
        <p style="margin:0;font-size:.75rem;color:var(--text-3);font-weight:500">Cost per item is entered later when you do <strong>Stock In</strong>.</p>
    </div>
    <div class="inv-modal-footer">
        <button class="btn-inv outline" onclick="closeModal('addItemModal')">Cancel</button>
        <button class="btn-inv primary" onclick="addItem()">Add Item</button>
    </div>
</div></div>

<!-- Edit Item Modal -->
<div class="inv-modal-overlay" id="editItemModal">
<div class="inv-modal">
    <div class="inv-modal-header"><h3>✏️ Edit Item</h3><button class="inv-modal-close" onclick="closeModal('editItemModal')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="inv-modal-body">
        <input type="hidden" id="ei_id">
        <div class="inv-field"><label>Item Name *</label><input type="text" id="ei_name"></div>
        <div class="inv-field-row">
            <div class="inv-field"><label>Category *</label>
                <select id="ei_cat">
                    <?php foreach ($categories as $catName): ?>
                    <option value="<?= e($catName) ?>"><?= e($catName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="inv-field"><label>Unit</label><input type="text" id="ei_unit" placeholder="pcs, sets, boxes"></div>
        </div>
        <div class="inv-field-row">
            <div class="inv-field"><label>Min Stock Level (alert threshold) *</label><input type="number" id="ei_min" min="0"></div>
            <div class="inv-field"><label>Status</label><select id="ei_status"><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>
        </div>
        <div class="inv-field"><label>Description</label><textarea id="ei_desc" rows="2" placeholder="Optional description..."></textarea></div>
        <p style="margin:0;font-size:.75rem;color:var(--text-3);font-weight:500">Unit cost is updated when you receive stock via <strong>Stock In</strong>.</p>
    </div>
    <div class="inv-modal-footer">
        <button class="btn-inv outline" onclick="closeModal('editItemModal')">Cancel</button>
        <button class="btn-inv primary" onclick="saveEditItem()">Save Changes</button>
    </div>
</div></div>

<!-- Stock In Modal -->
<div class="inv-modal-overlay" id="stockInModal">
<div class="inv-modal">
    <div class="inv-modal-header"><h3>📥 Stock In — Receive Materials</h3><button class="inv-modal-close" onclick="closeModal('stockInModal')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="inv-modal-body">
        <div class="inv-field"><label>Select Item *</label>
            <select id="si_item" onchange="onStockItemChange()">
                <option value="" data-cost="0">— Select Item —</option>
                <?php foreach ($allItems as $i): ?>
                <option value="<?= $i['id'] ?>" data-cost="<?= floatval($i['cost'] ?? 0) ?>">
                    <?= e($i['item_name']) ?> (<?= e($i['category']) ?>) — Stock: <?= $i['current_stock'] ?><?= $i['cost'] ? ' — Last cost: ₹'.number_format($i['cost'],2) : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="inv-field-row">
            <div class="inv-field"><label>Quantity *</label><input type="number" id="si_qty" min="1" placeholder="e.g. 50" oninput="calcStockTotal()"></div>
            <div class="inv-field"><label>Cost per Item (₹) *</label><input type="number" id="si_cost" min="0" step="0.01" placeholder="e.g. 120.00" oninput="calcStockTotal()"></div>
        </div>
        <div class="inv-field">
            <label>Total Amount (₹) <span style="font-size:.72rem;color:var(--text-3);font-weight:500">(Cost × Qty)</span></label>
            <input type="text" id="si_total" readonly placeholder="Auto-calculated"
                   style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-color:#6ee7b7;font-weight:800;font-family:var(--mono);color:#059669;font-size:1rem;cursor:not-allowed;">
        </div>
        <div class="inv-field-row">
            <div class="inv-field"><label>Supplier</label><input type="text" id="si_supplier" placeholder="e.g. ABC Publishers"></div>
            <div class="inv-field"><label>Invoice / Reference No</label><input type="text" id="si_ref" placeholder="e.g. INV-2026-001"></div>
        </div>
        <div class="inv-field">
            <label>Date of Purchase <span style="font-size:.72rem;color:var(--text-3);font-weight:500">(when materials were bought)</span></label>
            <input type="date" id="si_purchase_date" max="<?= date('Y-m-d') ?>" style="font-family:var(--font)">
        </div>
        <div class="inv-field"><label>Notes</label><textarea id="si_notes" rows="2" placeholder="Optional notes..."></textarea></div>
    </div>
    <div class="inv-modal-footer">
        <button class="btn-inv outline" onclick="closeModal('stockInModal')">Cancel</button>
        <button class="btn-inv green" onclick="stockIn()">📥 Receive Stock</button>
    </div>
</div></div>

<!-- Add Category Modal -->
<div class="inv-modal-overlay" id="addCategoryModal">
<div class="inv-modal" style="max-width:440px">
    <div class="inv-modal-header"><h3>➕ Create Category</h3><button class="inv-modal-close" onclick="closeModal('addCategoryModal')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="inv-modal-body">
        <div class="inv-field"><label>Category Name *</label><input type="text" id="ac_name" maxlength="100" placeholder="e.g. Uniforms, Kits, Badges"></div>
    </div>
    <div class="inv-modal-footer">
        <button class="btn-inv outline" onclick="closeModal('addCategoryModal')">Cancel</button>
        <button class="btn-inv primary" onclick="addCategory()">Create Category</button>
    </div>
</div></div>

<!-- Adjustment Modal -->
<div class="inv-modal-overlay" id="adjustModal">
<div class="inv-modal">
    <div class="inv-modal-header"><h3>🔧 Stock Adjustment</h3><button class="inv-modal-close" onclick="closeModal('adjustModal')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="inv-modal-body">
        <div id="adj_item_info" style="padding:.75rem 1rem;background:#f8fafc;border-radius:10px;margin-bottom:1rem;font-size:.85rem"></div>
        <input type="hidden" id="adj_item_id">
        <div class="inv-field"><label>Adjustment Quantity (use - for decrease)</label><input type="number" id="adj_qty" placeholder="e.g. -5 or +10"></div>
        <div class="inv-field"><label>Reason *</label><textarea id="adj_notes" rows="2" placeholder="e.g. Damaged items, Stock count correction..."></textarea></div>
    </div>
    <div class="inv-modal-footer">
        <button class="btn-inv outline" onclick="closeModal('adjustModal')">Cancel</button>
        <button class="btn-inv primary" onclick="doAdjustment()">Apply Adjustment</button>
    </div>
</div></div>

<!-- Transaction History Modal -->
<div class="inv-modal-overlay" id="historyModal">
<div class="inv-modal" style="max-width:700px">
    <div class="inv-modal-header"><h3 id="hist_title">📋 Transaction History</h3><button class="inv-modal-close" onclick="closeModal('historyModal')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="inv-modal-body" id="hist_body" style="max-height:500px;overflow-y:auto">Loading...</div>
    <div class="inv-modal-footer"><button class="btn-inv outline" onclick="closeModal('historyModal')">Close</button></div>
</div></div>

<div class="inv-toast" id="invToast"></div>

<script src="../assets/js/dashboard.js"></script>
<script>
function openModal(id){ document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function closeModal(id){ document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
function toast(msg,type='success'){
    const t=document.getElementById('invToast');
    t.style.borderLeft='4px solid '+(type==='error'?'#ef4444':'#10b981');
    t.textContent=(type==='error'?'❌ ':'✅ ')+msg;
    t.classList.add('show'); setTimeout(()=>t.classList.remove('show'),3500);
}
function post(data){
    const fd=new FormData(); for(const k in data) fd.append(k,data[k]);
    return fetch('',{method:'POST',body:fd}).then(r=>r.json());
}

function addItem(){
    const name=document.getElementById('ai_name').value.trim();
    if(!name){toast('Item name is required','error');return;}
    post({action:'add_item',item_name:name,category:document.getElementById('ai_cat').value,unit:document.getElementById('ai_unit').value.trim()||'pcs',min_stock_level:document.getElementById('ai_min').value,description:document.getElementById('ai_desc').value}).then(r=>{
        if(r.success){toast(r.message);setTimeout(()=>location.reload(),1000);}else toast(r.message,'error');
    });
}

function addCategory(){
    const name=document.getElementById('ac_name').value.trim();
    if(!name){toast('Category name is required','error');return;}
    post({action:'add_category',name}).then(r=>{
        if(r.success){toast(r.message);setTimeout(()=>location.reload(),800);}else toast(r.message,'error');
    });
}

function openEditItem(item){
    document.getElementById('ei_id').value=item.id;
    document.getElementById('ei_name').value=item.item_name||'';
    const catSel=document.getElementById('ei_cat');
    if(item.category && ![...catSel.options].some(o=>o.value===item.category)){
        const opt=document.createElement('option');
        opt.value=item.category; opt.textContent=item.category;
        catSel.appendChild(opt);
    }
    catSel.value=item.category||'Books';
    document.getElementById('ei_unit').value=item.unit||'pcs';
    document.getElementById('ei_min').value=item.min_stock_level??10;
    document.getElementById('ei_status').value=item.status||'Active';
    document.getElementById('ei_desc').value=item.description||'';
    openModal('editItemModal');
}

function saveEditItem(){
    const id=document.getElementById('ei_id').value;
    const name=document.getElementById('ei_name').value.trim();
    if(!name){toast('Item name is required','error');return;}
    post({
        action:'edit_item',
        id,
        item_name:name,
        category:document.getElementById('ei_cat').value,
        unit:document.getElementById('ei_unit').value.trim()||'pcs',
        min_stock_level:document.getElementById('ei_min').value,
        status:document.getElementById('ei_status').value,
        description:document.getElementById('ei_desc').value
    }).then(r=>{
        if(r.success){toast(r.message);setTimeout(()=>location.reload(),1000);}else toast(r.message,'error');
    });
}

function stockIn(){
    const itemId=document.getElementById('si_item').value;
    const qty=parseInt(document.getElementById('si_qty').value)||0;
    const cost=parseFloat(document.getElementById('si_cost').value)||0;
    const purchaseDate=document.getElementById('si_purchase_date').value;
    if(!itemId){toast('Please select an item','error');return;}
    if(qty<=0){toast('Quantity must be greater than 0','error');return;}
    if(cost<=0){toast('Cost per item is required','error');return;}
    post({action:'stock_in',item_id:itemId,quantity:qty,cost:cost,
          supplier:document.getElementById('si_supplier').value,
          reference_no:document.getElementById('si_ref').value,
          purchase_date:purchaseDate,
          notes:document.getElementById('si_notes').value}).then(r=>{
        if(r.success){toast(r.message);setTimeout(()=>location.reload(),1000);}else toast(r.message,'error');
    });
}

function onStockItemChange(){
    document.getElementById('si_qty').value='';
    document.getElementById('si_total').value='';
    const sel=document.getElementById('si_item');
    const lastCost=parseFloat(sel.options[sel.selectedIndex]?.dataset?.cost||0);
    document.getElementById('si_cost').value=lastCost>0?lastCost:'';
    calcStockTotal();
}

function calcStockTotal(){
    const qty=parseFloat(document.getElementById('si_qty').value)||0;
    const cost=parseFloat(document.getElementById('si_cost').value)||0;
    const totalEl=document.getElementById('si_total');
    if(qty>0&&cost>0){
        const total=(qty*cost).toFixed(2);
        totalEl.value='₹ '+parseFloat(total).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
    } else {
        totalEl.value='';
    }
}

function openStockInFor(id,name){
    openModal('stockInModal');
    const sel=document.getElementById('si_item');
    sel.value=id;
    // Default purchase date to today
    document.getElementById('si_purchase_date').value=new Date().toISOString().split('T')[0];
    onStockItemChange();
}

function openAdjustment(id,name,stock){
    document.getElementById('adj_item_id').value=id;
    document.getElementById('adj_item_info').innerHTML='<strong>'+name+'</strong> — Current Stock: <strong>'+stock+'</strong>';
    document.getElementById('adj_qty').value='';
    document.getElementById('adj_notes').value='';
    openModal('adjustModal');
}

function doAdjustment(){
    const id=document.getElementById('adj_item_id').value;
    const qty=parseInt(document.getElementById('adj_qty').value)||0;
    const notes=document.getElementById('adj_notes').value.trim();
    if(!qty){toast('Enter adjustment quantity','error');return;}
    if(!notes){toast('Please provide a reason','error');return;}
    post({action:'adjustment',item_id:id,quantity:qty,notes:notes}).then(r=>{
        if(r.success){toast(r.message);setTimeout(()=>location.reload(),1000);}else toast(r.message,'error');
    });
}

function viewHistory(id,name){
    document.getElementById('hist_title').textContent='📋 History: '+name;
    document.getElementById('hist_body').innerHTML='<p style="text-align:center;color:var(--text-3)">Loading...</p>';
    openModal('historyModal');
    post({action:'get_transactions',item_id:id}).then(r=>{
        if(!r.success||!r.data.length){document.getElementById('hist_body').innerHTML='<p style="text-align:center;color:var(--text-3);padding:2rem">No transactions found.</p>';return;}
        let html='<table class="inv-tbl"><thead><tr><th>Date Added</th><th>Purchase Date</th><th>Type</th><th>Qty</th><th>Rate (₹)</th><th>Total (₹)</th><th>Balance</th><th>Reference</th><th>Notes</th></tr></thead><tbody>';
        r.data.forEach(t=>{
            const tc=t.type.toLowerCase().replace(' ','-');
            const col=t.quantity>0?'#059669':'#dc2626';
            const pfx=t.quantity>0?'+':'';
            const rate=t.rate_per_item?'₹'+parseFloat(t.rate_per_item).toLocaleString('en-IN',{minimumFractionDigits:2}):'—';
            const total=t.total_amount?'₹'+parseFloat(t.total_amount).toLocaleString('en-IN',{minimumFractionDigits:2}):'—';
            const purDate=t.purchase_date?new Date(t.purchase_date).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}):'—';
            html+=`<tr><td style="font-size:.78rem;white-space:nowrap">${new Date(t.created_at).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'})}</td><td style="font-size:.78rem;white-space:nowrap;color:${t.purchase_date?'#7c3aed':'#9ca3af'};font-weight:${t.purchase_date?700:400}">${purDate}</td><td><span class="txn-type ${tc}">${t.type}</span></td><td style="font-family:var(--mono);font-weight:800;color:${col}">${pfx}${t.quantity}</td><td style="font-family:var(--mono);font-size:.82rem;color:#6b7280">${rate}</td><td style="font-family:var(--mono);font-size:.82rem;font-weight:700;color:#059669">${total}</td><td style="font-family:var(--mono);font-weight:700">${t.running_balance}</td><td style="font-size:.78rem">${t.reference_no||t.supplier||'—'}</td><td style="font-size:.78rem;max-width:120px;overflow:hidden;text-overflow:ellipsis">${t.notes||'—'}</td></tr>`;
        });
        html+='</tbody></table>';
        document.getElementById('hist_body').innerHTML=html;
    });
}

function deleteItem(id,name){
    if(!confirm('Delete "'+name+'"? This will remove all transaction history for this item.')){return;}
    post({action:'delete_item',id:id}).then(r=>{
        if(r.success){toast('Item deleted');setTimeout(()=>location.reload(),800);}else toast(r.message,'error');
    });
}

document.addEventListener('keydown',e=>{if(e.key==='Escape'){document.querySelectorAll('.inv-modal-overlay.open').forEach(m=>m.classList.remove('open'));document.body.style.overflow='';}});
</script>
</body>
</html>
