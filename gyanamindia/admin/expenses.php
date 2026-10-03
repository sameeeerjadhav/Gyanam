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

$streams = [
    'IT' => ['slug' => 'it', 'color' => '#2563eb', 'soft' => '#eff6ff', 'border' => '#bfdbfe'],
    'Abacus' => ['slug' => 'abacus', 'color' => '#7c3aed', 'soft' => '#f5f3ff', 'border' => '#ddd6fe'],
    'Vedic Maths' => ['slug' => 'vedic', 'color' => '#d97706', 'soft' => '#fffbeb', 'border' => '#fde68a'],
];
$modes = ['Cash', 'UPI', 'Bank Transfer', 'Cheque'];

$month = trim((string)($_GET['month'] ?? date('Y-m')));
if ($month !== 'all' && !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $backMonth = trim((string)($_POST['month'] ?? $month));
    if ($backMonth !== 'all' && !preg_match('/^\d{4}-\d{2}$/', $backMonth)) {
        $backMonth = date('Y-m');
    }
    $anchor = '';

    if ($action === 'add') {
        $stream = (string)($_POST['stream'] ?? '');
        $title = trim((string)($_POST['title'] ?? ''));
        $date = trim((string)($_POST['expense_date'] ?? ''));
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $mode = (string)($_POST['payment_mode'] ?? 'Cash');
        $notes = trim((string)($_POST['notes'] ?? ''));
        if (!isset($streams[$stream]) || $title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $amount <= 0) {
            header('Location: expenses.php?month=' . urlencode($backMonth) . '&err=1');
            exit;
        }
        if (!in_array($mode, $modes, true)) {
            $mode = 'Cash';
        }
        $pdo->prepare('INSERT INTO ho_expenses (stream, expense_date, title, amount, payment_mode, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$stream, $date, $title, $amount, $mode, $notes !== '' ? $notes : null, getUserId()]);
        $anchor = '#' . $streams[$stream]['slug'];
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stream = (string)($_POST['stream'] ?? '');
        if ($id > 0 && isset($streams[$stream])) {
            $pdo->prepare('DELETE FROM ho_expenses WHERE id = ? AND stream = ?')->execute([$id, $stream]);
            $anchor = '#' . $streams[$stream]['slug'];
        }
    }

    header('Location: expenses.php?month=' . urlencode($backMonth) . $anchor);
    exit;
}

$partitions = [];
$grand = 0.0;
foreach ($streams as $name => $meta) {
    $where = 'stream = ?';
    $params = [$name];
    if ($month !== 'all') {
        $where .= ' AND DATE_FORMAT(expense_date, "%Y-%m") = ?';
        $params[] = $month;
    }
    $countSt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM ho_expenses WHERE $where");
    $countSt->execute($params);
    [$count, $sum] = $countSt->fetch(PDO::FETCH_NUM);
    $count = (int)$count;
    $sum = (float)$sum;
    $grand += $sum;

    $pagerParams = paginationParams(12, 100, $meta['slug'] . '_page');
    $pager = paginationMeta($count, $pagerParams);
    $listSt = $pdo->prepare("SELECT * FROM ho_expenses WHERE $where ORDER BY expense_date DESC, id DESC LIMIT {$pager['per_page']} OFFSET {$pager['offset']}");
    $listSt->execute($params);
    $partitions[$name] = [
        'meta' => $meta,
        'rows' => $listSt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'count' => $count,
        'sum' => $sum,
        'pager' => $pager,
    ];
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
    <link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%20viewBox%3D%270%200%2024%2024%27%20fill%3D%27none%27%20stroke%3D%27%234f46e5%27%20stroke-width%3D%272%27%20stroke-linecap%3D%27round%27%20stroke-linejoin%3D%27round%27%3E%3Cpath%20d%3D%27M4%202v20l2-1%202%201%202-1%202%201%202-1%202%201%202-1%202%201V2l-2%201-2-1-2%201-2-1-2%201-2-1-2%201-2-1z%27%2F%3E%3Cpath%20d%3D%27M8%207h8M8%2011h8M8%2015h5%27%2F%3E%3C%2Fsvg%3E">
    <style>
        .ex-month { display:flex; align-items:center; gap:.6rem; flex-wrap:wrap; margin-bottom:1rem }
        .ex-month input, .ex-month a { height:38px; border:1.5px solid #e2e8f0; border-radius:9px; padding:0 .75rem; font-weight:700; font-family:inherit; background:#fff; color:#1f2937; text-decoration:none; display:inline-flex; align-items:center }
        .ex-grand { margin-left:auto; font-weight:800; color:#111827 }
        .ex-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:1rem; align-items:start }
        .ex-card { background:#fff; border:1.5px solid #e6eaf3; border-radius:16px; overflow:hidden }
        .ex-card h3 { margin:0; font-size:1rem; font-weight:800 }
        .ex-head { padding:.9rem 1rem; display:flex; justify-content:space-between; align-items:flex-start; gap:.5rem }
        .ex-sum { font-size:1.25rem; font-weight:800; line-height:1.1 }
        .ex-count { font-size:.72rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.04em }
        .ex-form { display:grid; gap:.45rem; padding:0 1rem .85rem }
        .ex-form input, .ex-form select { height:36px; border:1.5px solid #e2e8f0; border-radius:8px; padding:0 .6rem; font-family:inherit; font-weight:600; width:100%; box-sizing:border-box }
        .ex-form button { height:36px; border:none; border-radius:8px; color:#fff; font-weight:800; cursor:pointer; font-family:inherit }
        .ex-table { width:100%; border-collapse:collapse; font-size:.78rem }
        .ex-table th { text-align:left; font-size:.66rem; letter-spacing:.03em; text-transform:uppercase; color:#64748b; padding:.55rem .7rem; background:#f8fafc }
        .ex-table td { padding:.55rem .7rem; border-top:1px solid #f1f5f9; vertical-align:top }
        .ex-title { font-weight:800; color:#111827 }
        .ex-note { color:#64748b; font-size:.72rem; font-weight:600 }
        .ex-del { border:none; background:#fef2f2; color:#b91c1c; border-radius:7px; font-weight:800; font-size:.72rem; padding:.25rem .45rem; cursor:pointer; font-family:inherit }
        .ex-empty { padding:1rem; color:#64748b; font-weight:600; font-size:.84rem }
        .ex-card .pager { margin:.65rem .7rem .85rem }
        .ex-err { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:10px; padding:.7rem 1rem; font-weight:700; margin-bottom:1rem }
        @media (max-width:1100px) { .ex-grid { grid-template-columns:1fr } }
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
            <?php if (isset($_GET['err'])): ?>
                <div class="ex-err">Enter a particular, a date, and an amount greater than zero.</div>
            <?php endif; ?>
            <form method="get" class="ex-month">
                <label for="exMonth" style="font-weight:800;font-size:.84rem">Month</label>
                <input type="month" id="exMonth" name="month" value="<?= $month === 'all' ? '' : htmlspecialchars($month) ?>" onchange="if(this.value) this.form.submit()">
                <a href="expenses.php?month=all">All months</a>
                <div class="ex-grand">Total <?= $month === 'all' ? 'all time' : date('M Y', strtotime($month . '-01')) ?>: ₹<?= number_format($grand, 0) ?></div>
            </form>

            <div class="ex-grid">
                <?php foreach ($partitions as $name => $part):
                    $meta = $part['meta'];
                ?>
                <section class="ex-card" id="<?= htmlspecialchars($meta['slug']) ?>" style="border-color:<?= $meta['border'] ?>">
                    <div class="ex-head" style="background:<?= $meta['soft'] ?>">
                        <div>
                            <h3 style="color:<?= $meta['color'] ?>"><?= htmlspecialchars($name) ?></h3>
                            <div class="ex-count"><?= (int)$part['count'] ?> expense<?= (int)$part['count'] === 1 ? '' : 's' ?></div>
                        </div>
                        <div class="ex-sum" style="color:<?= $meta['color'] ?>">₹<?= number_format($part['sum'], 0) ?></div>
                    </div>
                    <form method="post" class="ex-form">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="stream" value="<?= htmlspecialchars($name) ?>">
                        <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
                        <input type="date" name="expense_date" required value="<?= date('Y-m-d') ?>" aria-label="Date">
                        <input type="text" name="title" required maxlength="255" placeholder="Particular" aria-label="Particular">
                        <input type="number" name="amount" required min="0.01" step="0.01" placeholder="Amount" aria-label="Amount">
                        <select name="payment_mode" aria-label="Payment mode">
                            <?php foreach ($modes as $mode): ?>
                                <option value="<?= htmlspecialchars($mode) ?>"><?= htmlspecialchars($mode) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="notes" maxlength="500" placeholder="Note (optional)" aria-label="Note">
                        <button type="submit" style="background:<?= $meta['color'] ?>">Add <?= htmlspecialchars($name) ?> expense</button>
                    </form>
                    <?php if ($part['rows'] === []): ?>
                        <div class="ex-empty">No expenses in this partition for the selected period.</div>
                    <?php else: ?>
                    <div style="overflow-x:auto">
                        <table class="ex-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Particular</th>
                                    <th>Amount</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($part['rows'] as $row): ?>
                                <tr>
                                    <td><?= date('d M Y', strtotime($row['expense_date'])) ?><div class="ex-note"><?= htmlspecialchars($row['payment_mode']) ?></div></td>
                                    <td>
                                        <div class="ex-title"><?= htmlspecialchars($row['title']) ?></div>
                                        <?php if (!empty($row['notes'])): ?><div class="ex-note"><?= htmlspecialchars($row['notes']) ?></div><?php endif; ?>
                                    </td>
                                    <td style="font-weight:800">₹<?= number_format((float)$row['amount'], 0) ?></td>
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
