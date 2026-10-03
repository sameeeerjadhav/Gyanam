<?php
/**
 * ATC — this centre's expenses.
 * Course costs go in IT, Abacus, or Vedic Maths.
 * Rent, salary, and other shared costs go in Centre.
 * Rows are stored per centre and never mixed with Head Office expenses.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo = getDBConnection();
$atcId = (int)($_SESSION['atc_id'] ?? 0);

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS atc_expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            atc_id INT NOT NULL,
            stream VARCHAR(32) NOT NULL,
            expense_date DATE NOT NULL,
            title VARCHAR(255) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            payment_mode VARCHAR(40) NOT NULL DEFAULT 'Cash',
            notes TEXT,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_atc_exp (atc_id, stream, expense_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log('[atc_expenses] ' . $e->getMessage());
}
try {
    $have = [];
    foreach ($pdo->query('SHOW COLUMNS FROM atc_expenses') as $col) {
        $have[(string)$col['Field']] = true;
    }
    foreach ([
        'category' => 'VARCHAR(64) NULL',
        'paid_to' => 'VARCHAR(255) NULL',
        'reference_no' => 'VARCHAR(80) NULL',
    ] as $column => $definition) {
        if (!isset($have[$column])) {
            $pdo->exec("ALTER TABLE atc_expenses ADD COLUMN {$column} {$definition}");
        }
    }
} catch (Exception $e) {
    error_log('[atc_expenses] ' . $e->getMessage());
}

$streams = [
    'IT' => ['slug' => 'it', 'color' => '#2563eb', 'soft' => '#eff6ff', 'border' => '#bfdbfe'],
    'Abacus' => ['slug' => 'abacus', 'color' => '#7c3aed', 'soft' => '#f5f3ff', 'border' => '#ddd6fe'],
    'Vedic Maths' => ['slug' => 'vedic', 'color' => '#d97706', 'soft' => '#fffbeb', 'border' => '#fde68a'],
    'Centre' => ['slug' => 'centre', 'color' => '#475569', 'soft' => '#f8fafc', 'border' => '#e2e8f0'],
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

$month = trim((string)($_GET['month'] ?? date('Y-m')));
if ($month !== 'all' && !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $atcId > 0) {
    $action = (string)($_POST['action'] ?? '');
    $backMonth = trim((string)($_POST['month'] ?? $month));
    if ($backMonth !== 'all' && !preg_match('/^\d{4}-\d{2}$/', $backMonth)) {
        $backMonth = date('Y-m');
    }
    $anchor = '';

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
            header('Location: expenses.php?month=' . urlencode($backMonth) . '&err=1');
            exit;
        }
        if (!in_array($mode, $modes, true)) {
            $mode = 'Cash';
        }
        $pdo->prepare('INSERT INTO atc_expenses (atc_id, stream, category, expense_date, title, paid_to, amount, payment_mode, reference_no, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $atcId,
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
        $anchor = '#' . $streams[$stream]['slug'];
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stream = (string)($_POST['stream'] ?? '');
        if ($id > 0 && isset($streams[$stream])) {
            $pdo->prepare('DELETE FROM atc_expenses WHERE id = ? AND atc_id = ? AND stream = ?')->execute([$id, $atcId, $stream]);
            $anchor = '#' . $streams[$stream]['slug'];
        }
    }

    header('Location: expenses.php?month=' . urlencode($backMonth) . $anchor);
    exit;
}

$partitions = [];
$grand = 0.0;
$loadError = $atcId <= 0 ? 'This login is not linked to an ATC centre.' : '';
if ($atcId > 0) {
    try {
        foreach ($streams as $name => $meta) {
            $where = 'atc_id = ? AND stream = ?';
            $params = [$atcId, $name];
            if ($month !== 'all') {
                $monthStart = $month . '-01';
                $monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month'));
                $where .= ' AND expense_date >= ? AND expense_date < ?';
                $params[] = $monthStart;
                $params[] = $monthEnd;
            }
            $countSt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM atc_expenses WHERE $where");
            $countSt->execute($params);
            $countRow = $countSt->fetch(PDO::FETCH_NUM);
            $count = (int)(is_array($countRow) ? $countRow[0] : 0);
            $sum = (float)(is_array($countRow) ? $countRow[1] : 0);
            $grand += $sum;

            $pagerParams = paginationParams(12, 100, $meta['slug'] . '_page');
            $pager = paginationMeta($count, $pagerParams);
            $perPage = (int)$pager['per_page'];
            $offset = (int)$pager['offset'];
            $listSt = $pdo->prepare("SELECT * FROM atc_expenses WHERE $where ORDER BY expense_date DESC, id DESC LIMIT {$perPage} OFFSET {$offset}");
            $listSt->execute($params);
            $partitions[$name] = [
                'meta' => $meta,
                'rows' => $listSt->fetchAll(PDO::FETCH_ASSOC) ?: [],
                'count' => $count,
                'sum' => $sum,
                'pager' => $pager,
            ];
        }
    } catch (Throwable $e) {
        error_log('[atc_expenses] ' . $e->getMessage());
        $loadError = 'Expenses could not be loaded. ' . $e->getMessage();
        $partitions = [];
        $grand = 0.0;
    }
}

if (!function_exists('exMoney')) {
    function exMoney(float $amount): string
    {
        $whole = abs($amount - round($amount)) < 0.001;
        $text = $whole ? number_format($amount, 0) : number_format($amount, 2);
        return '<span class="ex-rs">&#8377;</span>' . $text;
    }
}
$periodLabel = $month === 'all' ? 'All time' : date('F Y', strtotime($month . '-01'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses — ATC | Gyanam India</title>
    <?php include __DIR__ . '/../includes/head_fonts.php'; ?>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <style>
        .ex-rs { font-family: "Segoe UI", "Nirmala UI", Arial, sans-serif !important; font-weight: 700; }
        .ex-bar { display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; background:#fff; border:1px solid #e6eaf3; border-radius:14px; padding:.75rem .9rem; margin-bottom:1rem; box-shadow:0 1px 2px rgba(15,23,42,.04) }
        .ex-bar-left { display:flex; align-items:center; gap:.55rem; flex-wrap:wrap }
        .ex-bar-label { font-size:.78rem; font-weight:800; color:#64748b }
        .ex-bar input[type="month"] { height:38px; border:1.5px solid #e2e8f0; border-radius:10px; padding:0 .7rem; font-weight:700; color:#111827; background:#fff }
        .ex-all { height:38px; padding:0 .85rem; border-radius:10px; border:1.5px solid #e2e8f0; background:#fff; color:#334155; font-weight:800; font-size:.8rem; text-decoration:none; display:inline-flex; align-items:center }
        .ex-all.is-on, .ex-all:hover { border-color:#c7d2fe; background:#eef2ff; color:#3730a3 }
        .ex-total { display:flex; flex-direction:column; align-items:flex-end; line-height:1.15; margin-left:auto }
        .ex-total span { font-size:.68rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#64748b }
        .ex-total strong { font-size:1.25rem; font-weight:800; color:#111827; font-variant-numeric:tabular-nums }
        .ex-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:1rem; align-items:start }
        .ex-card { background:#fff; border:1px solid var(--ex-line, #e6eaf3); border-radius:16px; overflow:hidden; box-shadow:0 8px 24px rgba(15,23,42,.04) }
        .ex-head { padding:.95rem 1rem; display:flex; justify-content:space-between; align-items:center; gap:.75rem; background:var(--ex-soft, #f8fafc); border-bottom:1px solid var(--ex-line, #e6eaf3) }
        .ex-card h3 { margin:0; font-size:1rem; font-weight:800; color:var(--ex, #111827) }
        .ex-count { margin-top:.2rem; font-size:.68rem; font-weight:700; color:#64748b; letter-spacing:.04em; text-transform:uppercase }
        .ex-sum { font-size:1.35rem; font-weight:800; color:var(--ex, #111827); font-variant-numeric:tabular-nums; white-space:nowrap }
        .ex-form { display:grid; grid-template-columns:1fr 1fr; gap:.55rem .6rem; padding:.9rem 1rem 1rem }
        .ex-field { display:flex; flex-direction:column; gap:.28rem; min-width:0 }
        .ex-cap { font-size:.68rem; font-weight:700; color:#64748b }
        .ex-span { grid-column:1 / -1 }
        .ex-form input, .ex-form select { height:38px; border:1.5px solid #e2e8f0; border-radius:9px; padding:0 .65rem; font-weight:600; width:100%; box-sizing:border-box; background-color:#fff; color:#111827 }
        .ex-form select { appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='none' stroke='%2364748b' stroke-width='1.6' stroke-linecap='round' d='M1 1.5 6 6.5 11 1.5'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right .7rem center; padding-right:1.8rem }
        .ex-form input:focus, .ex-form select:focus, .ex-bar input:focus { outline:none; border-color:#a5b4fc; box-shadow:0 0 0 3px rgba(99,102,241,.12) }
        .ex-amt { display:flex; align-items:center; height:38px; border:1.5px solid #e2e8f0; border-radius:9px; background:#fff; overflow:hidden }
        .ex-amt:focus-within { border-color:#a5b4fc; box-shadow:0 0 0 3px rgba(99,102,241,.12) }
        .ex-amt .ex-rs { padding-left:.65rem; color:#64748b }
        .ex-amt input { border:none; height:100%; border-radius:0; box-shadow:none; padding-left:.35rem }
        .ex-amt input:focus { box-shadow:none }
        .ex-form button { grid-column:1 / -1; height:40px; border:none; border-radius:10px; background:var(--ex, #2563eb); color:#fff; font-weight:800; cursor:pointer }
        .ex-form button:hover { filter:brightness(.95) }
        .ex-list { border-top:1px solid #eef2f7 }
        .ex-table { width:100%; border-collapse:collapse; font-size:.78rem }
        .ex-table th { text-align:left; font-size:.66rem; letter-spacing:.04em; text-transform:uppercase; color:#64748b; padding:.6rem .85rem; background:#f8fafc }
        .ex-table td { padding:.7rem .85rem; border-top:1px solid #f1f5f9; vertical-align:middle }
        .ex-table .num { text-align:right; font-weight:800; white-space:nowrap; font-variant-numeric:tabular-nums }
        .ex-title { font-weight:800; color:#111827 }
        .ex-note { color:#64748b; font-size:.72rem; font-weight:600; margin-top:.1rem }
        .ex-del { border:none; background:transparent; color:#b91c1c; border-radius:7px; font-weight:800; font-size:.72rem; padding:.3rem .4rem; cursor:pointer }
        .ex-del:hover { background:#fef2f2 }
        .ex-empty { margin:0 1rem 1rem; padding:.85rem .5rem; text-align:center; color:#94a3b8; font-weight:700; font-size:.8rem; border:1px dashed #e2e8f0; border-radius:10px; background:#fafbfc }
        .ex-card .pager { margin:.7rem .85rem .9rem }
        .ex-err { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:10px; padding:.7rem 1rem; font-weight:700; margin-bottom:1rem }
        @media (max-width:900px) { .ex-grid { grid-template-columns:1fr } .ex-total { align-items:flex-start; margin-left:0 } }
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
                    <p>This centre only. Course costs in IT, Abacus, and Vedic Maths. Shared costs in Centre.</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>
        <div class="page-content">
            <?php if ($loadError !== ''): ?>
                <div class="ex-err"><?= htmlspecialchars($loadError) ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['err'])): ?>
                <div class="ex-err">Choose an expense head, then enter the particular, date, and an amount greater than zero.</div>
            <?php endif; ?>
            <form method="get" class="ex-bar">
                <div class="ex-bar-left">
                    <label class="ex-bar-label" for="exMonth">Month</label>
                    <input type="month" id="exMonth" name="month" value="<?= $month === 'all' ? '' : htmlspecialchars($month) ?>" onchange="if(this.value) this.form.submit()">
                    <a class="ex-all<?= $month === 'all' ? ' is-on' : '' ?>" href="expenses.php?month=all">All months</a>
                </div>
                <div class="ex-total">
                    <span><?= htmlspecialchars($periodLabel) ?></span>
                    <strong><?= exMoney($grand) ?></strong>
                </div>
            </form>

            <div class="ex-grid">
                <?php foreach ($partitions as $name => $part):
                    $meta = $part['meta'];
                ?>
                <section class="ex-card" id="<?= htmlspecialchars($meta['slug']) ?>" style="--ex:<?= $meta['color'] ?>;--ex-soft:<?= $meta['soft'] ?>;--ex-line:<?= $meta['border'] ?>">
                    <div class="ex-head">
                        <div>
                            <h3><?= htmlspecialchars($name) ?></h3>
                            <div class="ex-count"><?= (int)$part['count'] ?> expense<?= (int)$part['count'] === 1 ? '' : 's' ?></div>
                        </div>
                        <div class="ex-sum"><?= exMoney((float)$part['sum']) ?></div>
                    </div>
                    <form method="post" class="ex-form">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="stream" value="<?= htmlspecialchars($name) ?>">
                        <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
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
                        <label class="ex-field ex-span">
                            <span class="ex-cap">Particular</span>
                            <input type="text" name="title" required maxlength="255" placeholder="Short description of this expense">
                        </label>
                        <label class="ex-field">
                            <span class="ex-cap">Paid to</span>
                            <input type="text" name="paid_to" maxlength="255" placeholder="Vendor or person">
                        </label>
                        <label class="ex-field">
                            <span class="ex-cap">Amount</span>
                            <span class="ex-amt">
                                <span class="ex-rs">&#8377;</span>
                                <input type="number" name="amount" required min="0.01" step="0.01" placeholder="0.00" inputmode="decimal">
                            </span>
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
                        <button type="submit">Add expense</button>
                    </form>
                    <?php if ($part['rows'] === []): ?>
                        <div class="ex-empty">No expenses for this period.</div>
                    <?php else: ?>
                    <div class="ex-list" style="overflow-x:auto">
                        <table class="ex-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Particular</th>
                                    <th class="num">Amount</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($part['rows'] as $row): ?>
                                <tr>
                                    <td><?= date('d M Y', strtotime($row['expense_date'])) ?><div class="ex-note"><?= htmlspecialchars($row['payment_mode']) ?></div></td>
                                    <td>
                                        <div class="ex-title"><?= htmlspecialchars($row['title']) ?></div>
                                        <?php
                                            $metaBits = array_filter([
                                                trim((string)($row['category'] ?? '')),
                                                trim((string)($row['paid_to'] ?? '')) !== '' ? 'Paid to ' . trim((string)$row['paid_to']) : '',
                                                trim((string)($row['reference_no'] ?? '')),
                                                trim((string)($row['notes'] ?? '')),
                                            ]);
                                        ?>
                                        <?php if ($metaBits): ?><div class="ex-note"><?= htmlspecialchars(implode(' · ', $metaBits)) ?></div><?php endif; ?>
                                    </td>
                                    <td class="num"><?= exMoney((float)$row['amount']) ?></td>
                                    <td>
                                        <form method="post" onsubmit="return confirm('Remove this expense?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <input type="hidden" name="stream" value="<?= htmlspecialchars($name) ?>">
                                            <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
                                            <button type="submit" class="ex-del">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?= renderPagination($part['pager'], 'expenses') ?>
                    <?php endif; ?>
                </section>
                <?php endforeach; ?>
            </div>
        </div>
    </main>
</div>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>
