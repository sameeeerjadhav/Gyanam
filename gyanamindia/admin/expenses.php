<?php
/**
 * Head Office — Expenses, partitioned by IT, Abacus, and Vedic Maths.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo = getDBConnection();

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ho_expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            stream VARCHAR(32) NOT NULL,
            expense_date DATE NOT NULL,
            title VARCHAR(255) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            payment_mode VARCHAR(40) NOT NULL DEFAULT 'Cash',
            notes TEXT,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ho_exp_stream_date (stream, expense_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log('[ho_expenses] ' . $e->getMessage());
}
try {
    $have = [];
    foreach ($pdo->query('SHOW COLUMNS FROM ho_expenses') as $col) {
        $have[(string)$col['Field']] = true;
    }
    foreach ([
        'category' => 'VARCHAR(64) NULL',
        'paid_to' => 'VARCHAR(255) NULL',
        'reference_no' => 'VARCHAR(80) NULL',
    ] as $column => $definition) {
        if (!isset($have[$column])) {
            $pdo->exec("ALTER TABLE ho_expenses ADD COLUMN {$column} {$definition}");
        }
    }
} catch (Exception $e) {
    error_log('[ho_expenses] ' . $e->getMessage());
}

$streams = [
    'IT' => ['slug' => 'it', 'color' => '#2563eb', 'soft' => '#eff6ff', 'border' => '#bfdbfe'],
    'Abacus' => ['slug' => 'abacus', 'color' => '#7c3aed', 'soft' => '#f5f3ff', 'border' => '#ddd6fe'],
    'Vedic Maths' => ['slug' => 'vedic', 'color' => '#d97706', 'soft' => '#fffbeb', 'border' => '#fde68a'],
];
$modes = ['Cash', 'UPI', 'Bank Transfer', 'Cheque'];
$heads = [
    'Salary & Wages',
    'Rent',
    'Electricity',
    'Internet & Phone',
    'Stationery',
    'Printing',
    'Marketing',
    'Travel',
    'Software & Subscriptions',
    'Repairs & Maintenance',
    'Training Material',
    'Professional Fees',
    'Bank Charges',
    'Miscellaneous',
];

$month = trim((string)($_GET['month'] ?? $_POST['month'] ?? date('Y-m')));
if ($month !== 'all' && !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$streamFilter = trim((string)($_GET['stream'] ?? $_POST['filter_stream'] ?? 'all'));
if ($streamFilter !== 'all' && !isset($streams[$streamFilter])) {
    $streamFilter = 'all';
}
$headFilter = trim((string)($_GET['head'] ?? $_POST['filter_head'] ?? 'all'));
if ($headFilter !== 'all' && !in_array($headFilter, $heads, true)) {
    $headFilter = 'all';
}
$modeFilter = trim((string)($_GET['mode'] ?? $_POST['filter_mode'] ?? 'all'));
if ($modeFilter !== 'all' && !in_array($modeFilter, $modes, true)) {
    $modeFilter = 'all';
}
$search = trim((string)($_GET['q'] ?? $_POST['filter_q'] ?? ''));
if (strlen($search) > 80) {
    $search = substr($search, 0, 80);
}

$expenseReturnUrl = static function (array $extra = []) use ($month, $streamFilter, $headFilter, $modeFilter, $search): string {
    $query = ['month' => $month];
    if ($streamFilter !== 'all') {
        $query['stream'] = $streamFilter;
    }
    if ($headFilter !== 'all') {
        $query['head'] = $headFilter;
    }
    if ($modeFilter !== 'all') {
        $query['mode'] = $modeFilter;
    }
    if ($search !== '') {
        $query['q'] = $search;
    }
    foreach ($extra as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
            continue;
        }
        $query[$key] = $value;
    }
    return 'expenses.php?' . http_build_query($query);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'add') {
        $stream = (string)($_POST['stream'] ?? '');
        $category = trim((string)($_POST['category'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $paidTo = trim((string)($_POST['paid_to'] ?? ''));
        $date = trim((string)($_POST['expense_date'] ?? ''));
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $mode = (string)($_POST['payment_mode'] ?? 'Cash');
        $reference = trim((string)($_POST['reference_no'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        if (!isset($streams[$stream]) || !in_array($category, $heads, true) || $title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $amount <= 0) {
            header('Location: ' . $expenseReturnUrl(['err' => '1']));
            exit;
        }
        if (!in_array($mode, $modes, true)) {
            $mode = 'Cash';
        }
        $pdo->prepare('INSERT INTO ho_expenses (stream, category, expense_date, title, paid_to, amount, payment_mode, reference_no, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $stream,
                $category,
                $date,
                $title,
                $paidTo !== '' ? $paidTo : null,
                $amount,
                $mode,
                $reference !== '' ? $reference : null,
                $notes !== '' ? $notes : null,
                getUserId(),
            ]);
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stream = (string)($_POST['stream'] ?? '');
        if ($id > 0 && isset($streams[$stream])) {
            $pdo->prepare('DELETE FROM ho_expenses WHERE id = ? AND stream = ?')->execute([$id, $stream]);
        }
    }

    header('Location: ' . $expenseReturnUrl());
    exit;
}

$streamTotals = [];
foreach ($streams as $name => $meta) {
    $streamTotals[$name] = ['count' => 0, 'sum' => 0.0];
}
$rows = [];
$filteredCount = 0;
$filteredSum = 0.0;
$pager = paginationMeta(0, paginationParams(20, 100, 'page'));
$loadError = '';
try {
    $monthSql = '';
    $monthParams = [];
    if ($month !== 'all') {
        $monthStart = $month . '-01';
        $monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month'));
        $monthSql = ' AND expense_date >= ? AND expense_date < ?';
        $monthParams = [$monthStart, $monthEnd];
    }
    $totSt = $pdo->prepare("SELECT stream, COUNT(*), COALESCE(SUM(amount), 0) FROM ho_expenses WHERE 1=1 {$monthSql} GROUP BY stream");
    $totSt->execute($monthParams);
    foreach ($totSt->fetchAll(PDO::FETCH_NUM) as $totRow) {
        $streamName = (string)$totRow[0];
        if (isset($streamTotals[$streamName])) {
            $streamTotals[$streamName] = ['count' => (int)$totRow[1], 'sum' => (float)$totRow[2]];
        }
    }

    $where = '1=1' . $monthSql;
    $params = $monthParams;
    if ($streamFilter !== 'all') {
        $where .= ' AND stream = ?';
        $params[] = $streamFilter;
    }
    if ($headFilter !== 'all') {
        $where .= ' AND category = ?';
        $params[] = $headFilter;
    }
    if ($modeFilter !== 'all') {
        $where .= ' AND payment_mode = ?';
        $params[] = $modeFilter;
    }
    if ($search !== '') {
        $where .= ' AND (title LIKE ? OR paid_to LIKE ? OR reference_no LIKE ? OR notes LIKE ? OR category LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $countSt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM ho_expenses WHERE {$where}");
    $countSt->execute($params);
    $countRow = $countSt->fetch(PDO::FETCH_NUM);
    $filteredCount = (int)(is_array($countRow) ? $countRow[0] : 0);
    $filteredSum = (float)(is_array($countRow) ? $countRow[1] : 0);
    $pager = paginationMeta($filteredCount, paginationParams(20, 100, 'page'));
    $perPage = (int)$pager['per_page'];
    $offset = (int)$pager['offset'];
    $listSt = $pdo->prepare("SELECT * FROM ho_expenses WHERE {$where} ORDER BY expense_date DESC, id DESC LIMIT {$perPage} OFFSET {$offset}");
    $listSt->execute($params);
    $rows = $listSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('[ho_expenses] ' . $e->getMessage());
    $loadError = 'Expenses could not be loaded. ' . $e->getMessage();
}

if (!function_exists('exMoney')) {
    function exMoney(float $amount): string
    {
        $whole = abs($amount - round($amount)) < 0.001;
        $text = $whole ? number_format($amount, 0) : number_format($amount, 2);
        return '<span class="ex-rs">&#8377;</span>' . $text;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses — Admin | Gyanam India</title>
    <?php include __DIR__ . '/../includes/head_fonts.php'; ?>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    
    <style>
        .ex-rs { font-family: "Segoe UI", "Nirmala UI", Arial, sans-serif !important; font-weight: 700; }
        .ex-err { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:10px; padding:.7rem 1rem; font-weight:700; margin-bottom:1rem }
        .ex-chips { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:.75rem; margin-bottom:1rem }
        .ex-chip { display:flex; justify-content:space-between; align-items:center; gap:.5rem; text-decoration:none; background:#fff; border:1px solid #e6eaf3; border-radius:14px; padding:.8rem .95rem; box-shadow:0 1px 2px rgba(15,23,42,.04) }
        .ex-chip.is-on { border-color:var(--ex, #2563eb); box-shadow:0 0 0 3px color-mix(in srgb, var(--ex, #2563eb) 16%, transparent) }
        .ex-chip b { display:block; font-size:.78rem; font-weight:800; color:var(--ex, #111827) }
        .ex-chip span { font-size:.68rem; font-weight:700; color:#64748b }
        .ex-chip strong { font-size:1.05rem; font-weight:800; color:#111827; font-variant-numeric:tabular-nums; white-space:nowrap }
        .ex-tools { display:flex; align-items:flex-end; justify-content:space-between; gap:.75rem; flex-wrap:wrap; background:#fff; border:1px solid #e6eaf3; border-radius:14px; padding:.85rem; margin-bottom:1rem }
        .ex-filters { display:flex; gap:.55rem; flex-wrap:wrap; align-items:flex-end }
        .ex-filters label { display:flex; flex-direction:column; gap:.28rem; font-size:.68rem; font-weight:800; color:#64748b }
        .ex-filters input, .ex-filters select { height:38px; border:1.5px solid #e2e8f0; border-radius:9px; padding:0 .65rem; font-weight:650; color:#111827; background:#fff; min-width:140px }
        .ex-filters input[type="search"] { min-width:180px }
        .ex-add { height:38px; border:none; border-radius:10px; background:#2563eb; color:#fff; font-weight:800; padding:0 1rem; cursor:pointer }
        .ex-add:hover { filter:brightness(.95) }
        .ex-panel { background:#fff; border:1px solid #e6eaf3; border-radius:14px; overflow:hidden; box-shadow:0 8px 24px rgba(15,23,42,.04) }
        .ex-panel-head { display:flex; justify-content:space-between; align-items:center; gap:.75rem; padding:.85rem 1rem; border-bottom:1px solid #eef2f7 }
        .ex-panel-head h3 { margin:0; font-size:.95rem; font-weight:800 }
        .ex-panel-head span { font-size:.78rem; font-weight:700; color:#64748b }
        .ex-table { width:100%; border-collapse:collapse; font-size:.8rem }
        .ex-table th { text-align:left; font-size:.66rem; letter-spacing:.04em; text-transform:uppercase; color:#64748b; padding:.65rem .85rem; background:#f8fafc }
        .ex-table td { padding:.7rem .85rem; border-top:1px solid #f1f5f9; vertical-align:middle }
        .ex-table .num { text-align:right; font-weight:800; white-space:nowrap; font-variant-numeric:tabular-nums }
        .ex-title { font-weight:800; color:#111827 }
        .ex-note { color:#64748b; font-size:.72rem; font-weight:600; margin-top:.12rem }
        .ex-pill { display:inline-flex; align-items:center; padding:.15rem .5rem; border-radius:999px; font-size:.68rem; font-weight:800; background:var(--ex-soft, #f8fafc); color:var(--ex, #111827) }
        .ex-del { border:none; background:transparent; color:#b91c1c; border-radius:7px; font-weight:800; font-size:.72rem; padding:.3rem .4rem; cursor:pointer }
        .ex-del:hover { background:#fef2f2 }
        .ex-empty { margin:1rem; padding:1.1rem .5rem; text-align:center; color:#94a3b8; font-weight:700; font-size:.84rem; border:1px dashed #e2e8f0; border-radius:10px; background:#fafbfc }
        .ex-panel .pager { margin:.75rem .9rem .9rem }
        .ex-form { display:grid; grid-template-columns:1fr 1fr; gap:.7rem .75rem }
        .ex-field { display:flex; flex-direction:column; gap:.28rem; min-width:0 }
        .ex-cap { font-size:.68rem; font-weight:700; color:#64748b }
        .ex-span { grid-column:1 / -1 }
        .ex-form input, .ex-form select, .ex-filters select { height:38px; border:1.5px solid #e2e8f0; border-radius:9px; padding:0 .65rem; font-weight:600; width:100%; box-sizing:border-box; background-color:#fff; color:#111827 }
        .ex-form select, .ex-filters select { appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='none' stroke='%2364748b' stroke-width='1.6' stroke-linecap='round' d='M1 1.5 6 6.5 11 1.5'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right .7rem center; padding-right:1.8rem }
        .ex-form input:focus, .ex-form select:focus, .ex-filters input:focus, .ex-filters select:focus { outline:none; border-color:#a5b4fc; box-shadow:0 0 0 3px rgba(99,102,241,.12) }
        .ex-amt { display:flex; align-items:center; height:38px; border:1.5px solid #e2e8f0; border-radius:9px; background:#fff; overflow:hidden }
        .ex-amt:focus-within { border-color:#a5b4fc; box-shadow:0 0 0 3px rgba(99,102,241,.12) }
        .ex-amt .ex-rs { padding-left:.65rem; color:#64748b }
        .ex-amt input { border:none; height:100%; border-radius:0; box-shadow:none; padding-left:.35rem }
        .ex-amt input:focus { box-shadow:none }
        .ex-save { height:38px; border:none; border-radius:10px; background:#2563eb; color:#fff; font-weight:800; padding:0 1rem; cursor:pointer }
        .ex-cancel { height:38px; border:1.5px solid #e2e8f0; border-radius:10px; background:#fff; color:#334155; font-weight:800; padding:0 .9rem; cursor:pointer }
        .ex-cancel.is-on { border-color:#2563eb; color:#1d4ed8; background:#eff6ff }
        #exModal .modal-card { max-width:680px }
        @media (max-width:1100px) { .ex-chips { grid-template-columns:1fr 1fr } }
        @media (max-width:640px) { .ex-chips, .ex-form { grid-template-columns:1fr } }
    </style>
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
                    <h2>Expenses</h2>
                    <p>Head Office spending, split by IT, Abacus, and Vedic Maths</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>
        <div class="page-content">
            <?php
                $monthGrand = 0.0;
                foreach ($streamTotals as $tot) {
                    $monthGrand += (float)$tot['sum'];
                }
                $keep = ['month' => $month];
                if ($headFilter !== 'all') {
                    $keep['head'] = $headFilter;
                }
                if ($modeFilter !== 'all') {
                    $keep['mode'] = $modeFilter;
                }
                if ($search !== '') {
                    $keep['q'] = $search;
                }
                $chipHref = static function (string $stream) use ($keep): string {
                    $query = $keep;
                    if ($stream !== 'all') {
                        $query['stream'] = $stream;
                    }
                    return 'expenses.php?' . http_build_query($query);
                };
            ?>
            <?php if ($loadError !== ''): ?>
                <div class="ex-err"><?= htmlspecialchars($loadError) ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['err'])): ?>
                <div class="ex-err">Choose a stream and an expense head, then enter the particular, date, and an amount greater than zero.</div>
            <?php endif; ?>

            <div class="ex-chips">
                <?php foreach ($streams as $name => $meta): ?>
                    <a class="ex-chip<?= $streamFilter === $name ? ' is-on' : '' ?>" style="--ex:<?= $meta['color'] ?>;--ex-soft:<?= $meta['soft'] ?>" href="<?= htmlspecialchars($chipHref($name)) ?>">
                        <div>
                            <b><?= htmlspecialchars($name) ?></b>
                            <span><?= (int)$streamTotals[$name]['count'] ?> expense<?= (int)$streamTotals[$name]['count'] === 1 ? '' : 's' ?></span>
                        </div>
                        <strong><?= exMoney((float)$streamTotals[$name]['sum']) ?></strong>
                    </a>
                <?php endforeach; ?>
                <a class="ex-chip<?= $streamFilter === 'all' ? ' is-on' : '' ?>" style="--ex:#111827" href="<?= htmlspecialchars($chipHref('all')) ?>">
                    <div>
                        <b>All streams</b>
                        <span><?= htmlspecialchars($month === 'all' ? 'All time' : date('F Y', strtotime($month . '-01'))) ?></span>
                    </div>
                    <strong><?= exMoney($monthGrand) ?></strong>
                </a>
            </div>

            <form method="get" class="ex-tools">
                <div class="ex-filters">
                    <label>Month
                        <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
                        <input type="month" value="<?= $month === 'all' ? '' : htmlspecialchars($month) ?>" onchange="if (this.value) { this.previousElementSibling.value = this.value; this.form.submit(); }">
                    </label>
                    <label>Stream
                        <select name="stream" onchange="this.form.submit()">
                            <option value="all">All streams</option>
                            <?php foreach ($streams as $name => $meta): ?>
                                <option value="<?= htmlspecialchars($name) ?>" <?= $streamFilter === $name ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Expense head
                        <select name="head" onchange="this.form.submit()">
                            <option value="all">All heads</option>
                            <?php foreach ($heads as $head): ?>
                                <option value="<?= htmlspecialchars($head) ?>" <?= $headFilter === $head ? 'selected' : '' ?>><?= htmlspecialchars($head) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Payment mode
                        <select name="mode" onchange="this.form.submit()">
                            <option value="all">All modes</option>
                            <?php foreach ($modes as $mode): ?>
                                <option value="<?= htmlspecialchars($mode) ?>" <?= $modeFilter === $mode ? 'selected' : '' ?>><?= htmlspecialchars($mode) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Search
                        <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Particular, payee, reference">
                    </label>
                    <button type="submit" class="ex-cancel">Apply</button>
                    <a class="ex-cancel<?= $month === 'all' ? ' is-on' : '' ?>" style="display:inline-flex;align-items:center;text-decoration:none" href="<?= htmlspecialchars($expenseReturnUrl(['month' => 'all'])) ?>">All months</a>
                </div>
                <button type="button" class="ex-add" onclick="document.getElementById('exModal').classList.add('active')">Add expense</button>
            </form>
            <section class="ex-panel">
                <div class="ex-panel-head">
                    <h3>Expenses</h3>
                    <span><?= (int)$filteredCount ?> record<?= $filteredCount === 1 ? '' : 's' ?> &middot; <?= exMoney($filteredSum) ?></span>
                </div>
                <?php if ($rows === []): ?>
                    <div class="ex-empty">No expenses match these filters.</div>
                <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="ex-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Stream</th>
                                <th>Head</th>
                                <th>Particular</th>
                                <th>Paid to</th>
                                <th>Reference</th>
                                <th>Mode</th>
                                <th class="num">Amount</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row):
                            $streamName = (string)($row['stream'] ?? '');
                            $meta = $streams[$streamName] ?? ['color' => '#111827', 'soft' => '#f8fafc'];
                        ?>
                            <tr>
                                <td><?= date('d M Y', strtotime((string)$row['expense_date'])) ?></td>
                                <td><span class="ex-pill" style="--ex:<?= $meta['color'] ?>;--ex-soft:<?= $meta['soft'] ?>"><?= htmlspecialchars($streamName) ?></span></td>
                                <td><?= htmlspecialchars((string)($row['category'] ?? '')) !== '' ? htmlspecialchars((string)$row['category']) : '&mdash;' ?></td>
                                <td>
                                    <div class="ex-title"><?= htmlspecialchars((string)$row['title']) ?></div>
                                    <?php if (!empty($row['notes'])): ?>
                                        <div class="ex-note"><?= htmlspecialchars((string)$row['notes']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars((string)($row['paid_to'] ?? '')) !== '' ? htmlspecialchars((string)$row['paid_to']) : '&mdash;' ?></td>
                                <td><?= htmlspecialchars((string)($row['reference_no'] ?? '')) !== '' ? htmlspecialchars((string)$row['reference_no']) : '&mdash;' ?></td>
                                <td><?= htmlspecialchars((string)($row['payment_mode'] ?? '')) ?></td>
                                <td class="num"><?= exMoney((float)$row['amount']) ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Remove this expense?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <input type="hidden" name="stream" value="<?= htmlspecialchars($streamName) ?>">
                                        <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
                                        <input type="hidden" name="filter_stream" value="<?= htmlspecialchars($streamFilter) ?>">
                                        <input type="hidden" name="filter_head" value="<?= htmlspecialchars($headFilter) ?>">
                                        <input type="hidden" name="filter_mode" value="<?= htmlspecialchars($modeFilter) ?>">
                                        <input type="hidden" name="filter_q" value="<?= htmlspecialchars($search) ?>">
                                        <button type="submit" class="ex-del">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?= renderPagination($pager, 'expenses') ?>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>

<div class="modal-overlay<?= isset($_GET['err']) ? ' active' : '' ?>" id="exModal" onclick="if (event.target === this) this.classList.remove('active')">
    <div class="modal-card" role="dialog" aria-labelledby="exModalTitle">
        <div class="modal-header">
            <h3 id="exModalTitle">Add expense</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('exModal').classList.remove('active')" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
                <input type="hidden" name="filter_stream" value="<?= htmlspecialchars($streamFilter) ?>">
                <input type="hidden" name="filter_head" value="<?= htmlspecialchars($headFilter) ?>">
                <input type="hidden" name="filter_mode" value="<?= htmlspecialchars($modeFilter) ?>">
                <input type="hidden" name="filter_q" value="<?= htmlspecialchars($search) ?>">
                <div class="ex-form">
                    <label class="ex-field">
                        <span class="ex-cap">Stream</span>
                        <select name="stream" required>
                            <option value="" selected disabled>Select stream</option>
                            <?php foreach ($streams as $name => $meta): ?>
                                <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="ex-field">
                        <span class="ex-cap">Expense head</span>
                        <select name="category" required>
                            <option value="" selected disabled>Select head</option>
                            <?php foreach ($heads as $head): ?>
                                <option value="<?= htmlspecialchars($head) ?>"><?= htmlspecialchars($head) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="ex-field">
                        <span class="ex-cap">Date</span>
                        <input type="date" name="expense_date" required value="<?= date('Y-m-d') ?>">
                    </label>
                    <label class="ex-field">
                        <span class="ex-cap">Amount</span>
                        <span class="ex-amt">
                            <span class="ex-rs">&#8377;</span>
                            <input type="number" name="amount" required min="0.01" step="0.01" placeholder="0.00" inputmode="decimal">
                        </span>
                    </label>
                    <label class="ex-field ex-span">
                        <span class="ex-cap">Particular</span>
                        <input type="text" name="title" required maxlength="255" placeholder="Short description of this expense">
                    </label>
                    <label class="ex-field">
                        <span class="ex-cap">Paid to</span>
                        <input type="text" name="paid_to" maxlength="255" placeholder="Vendor or person">
                    </label>
                    <label class="ex-field">
                        <span class="ex-cap">Payment mode</span>
                        <select name="payment_mode">
                            <?php foreach ($modes as $mode): ?>
                                <option value="<?= htmlspecialchars($mode) ?>"><?= htmlspecialchars($mode) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="ex-field">
                        <span class="ex-cap">Reference</span>
                        <input type="text" name="reference_no" maxlength="80" placeholder="UTR or cheque no.">
                    </label>
                    <label class="ex-field ex-span">
                        <span class="ex-cap">Note</span>
                        <input type="text" name="notes" maxlength="500" placeholder="Optional">
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="ex-cancel" onclick="document.getElementById('exModal').classList.remove('active')">Cancel</button>
                <button type="submit" class="ex-save">Save expense</button>
            </div>
        </form>
    </div>
</div>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>
