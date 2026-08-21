<?php
/**
 * Gyanam Portal — Admin: Share Receipts (ATC-wise)
 * Mirrors the ATC report_receipt.php but visible to Admin with full ATC filter.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo      = getDBConnection();
$userName = sanitize(getUserName());

// ── ATC list for filter ──────────────────────────────────────────────────────
$atcList = [];
try {
    $atcList = $pdo->query("SELECT id, name, atc_code FROM atc_centers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Filters ──────────────────────────────────────────────────────────────────
$filterAtc   = isset($_GET['atc_id'])    && $_GET['atc_id']    !== '' ? (int)$_GET['atc_id'] : 0;
$filterFrom  = trim($_GET['from_date']  ?? '');
$filterTo    = trim($_GET['to_date']    ?? '');
$filterMonth = trim($_GET['month']      ?? '');

// ── Build query ───────────────────────────────────────────────────────────────
$sql    = "SELECT sp.*, a.name AS atc_name, a.atc_code
           FROM share_payments sp
           LEFT JOIN atc_centers a ON a.id = sp.atc_id
           WHERE sp.status = 'Completed'";
$params = [];

if ($filterAtc) {
    $sql .= " AND sp.atc_id = ?"; $params[] = $filterAtc;
}
if ($filterFrom) {
    $sql .= " AND DATE(COALESCE(sp.paid_at, sp.created_at)) >= ?"; $params[] = $filterFrom;
}
if ($filterTo) {
    $sql .= " AND DATE(COALESCE(sp.paid_at, sp.created_at)) <= ?"; $params[] = $filterTo;
}
if ($filterMonth) {
    $sql .= " AND DATE_FORMAT(COALESCE(sp.paid_at, sp.created_at), '%Y-%m') = ?"; $params[] = $filterMonth;
}
$sql .= " ORDER BY sp.created_at DESC";

$receipts = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Build student map for the fetched ATCs ────────────────────────────────────
$studentMap = [];
if (!empty($receipts)) {
    $atcIds = array_values(array_unique(array_column($receipts, 'atc_id')));
    $ph = implode(',', array_fill(0, count($atcIds), '?'));
    try {
        $sq = $pdo->prepare("SELECT id, roll_no, first_name, middle_name, last_name, course, atc_id FROM admissions WHERE atc_id IN ($ph)");
        $sq->execute($atcIds);
        foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $name = trim($s['first_name'] . ' ' . ($s['middle_name'] ? $s['middle_name'] . ' ' : '') . $s['last_name']);
            $studentMap[$s['id']] = ['roll_no' => $s['roll_no'], 'name' => $name, 'course' => $s['course']];
        }
    } catch (Exception $e) {}
}

// Summary stats
$totalReceipts = count($receipts);
$totalAmount   = array_sum(array_column($receipts, 'total_amount'));
$totalStudents = 0;
foreach ($receipts as $r) {
    $ids = json_decode($r['student_ids'] ?? '[]', true);
    $totalStudents += is_array($ids) ? count($ids) : 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Share Receipts — Admin | Gyanam India Educational Services</title>
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<link rel="stylesheet" href="../assets/css/notifications.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<style>
/* ── Stats strip ── */
.stats-strip { display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem }
.stat-card { background:#fff;border:1.5px solid var(--border-color);border-radius:14px;padding:1rem 1.25rem;display:flex;align-items:center;gap:.8rem }
.stat-icon { width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0 }
.stat-icon svg { width:18px;height:18px }
.stat-icon.blue   { background:#eff6ff;color:#3b82f6 }
.stat-icon.green  { background:#ecfdf5;color:#10b981 }
.stat-icon.violet { background:#f5f3ff;color:#7c3aed }
.stat-val { font-size:1.45rem;font-weight:900;color:var(--text-primary);line-height:1 }
.stat-lbl { font-size:.72rem;color:var(--text-secondary);font-weight:600;margin-top:.18rem }

/* ── Filter bar ── */
.filter-bar { display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1.25rem;background:#fff;padding:1.1rem 1.25rem;border-radius:14px;border:1.5px solid var(--border-color) }
.filter-grp { display:flex;flex-direction:column;gap:.3rem }
.filter-grp label { font-size:.76rem;font-weight:700;color:var(--text-secondary) }
.filter-grp input,.filter-grp select { height:38px;padding:0 .75rem;border:1.5px solid var(--border-color);border-radius:8px;font-family:inherit;font-size:.875rem;outline:none;transition:border-color .18s;background:#fff }
.filter-grp input:focus,.filter-grp select:focus { border-color:#6366f1 }
.btn-filter { height:38px;padding:0 1.25rem;background:#6366f1;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .2s;white-space:nowrap }
.btn-filter:hover { background:#4f46e5 }
.btn-reset  { height:38px;padding:0 1rem;background:#f3f4f6;color:#4b5563;border:1.5px solid var(--border-color);border-radius:8px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;font-family:inherit;white-space:nowrap }
.btn-reset:hover { background:#e5e7eb }

/* ── Table ── */
.tbl-wrap { background:#fff;border:1.5px solid var(--border-color);border-radius:14px;overflow:hidden }
.rr-table { width:100%;border-collapse:collapse;font-size:.875rem }
.rr-table thead th { padding:.75rem 1rem;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-secondary);background:#fafbfc;border-bottom:1px solid var(--border-color);white-space:nowrap }
.rr-table tbody tr { border-bottom:1px solid #f3f4f6;transition:background .12s }
.rr-table tbody tr:hover { background:#f5f7ff }
.rr-table tbody td { padding:.9rem 1rem;vertical-align:middle }
.atc-badge { display:inline-flex;align-items:center;padding:.2rem .6rem;background:linear-gradient(135deg,#eff6ff,#ede9fe);border:1px solid #c7d2fe;border-radius:999px;font-size:.7rem;font-weight:800;color:#4361ee;font-family:monospace }
.tsn-badge { font-family:monospace;font-size:.78rem;color:#6b7280;background:#f3f4f6;padding:.18rem .5rem;border-radius:4px;display:inline-block;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle }
.amount-val { font-weight:800;color:#059669 }
.roll-list  { font-size:.8rem;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px;display:inline-block }
.roll-count { font-size:.7rem;color:#9ca3af;display:block;margin-top:2px }
.btn-view { display:inline-flex;align-items:center;gap:.3rem;padding:.35rem .7rem;background:#eff6ff;color:#3b82f6;border-radius:6px;font-size:.78rem;font-weight:700;border:1px solid #bfdbfe;cursor:pointer;font-family:inherit }
.btn-view:hover { background:#dbeafe }
.btn-pdf  { display:inline-flex;align-items:center;gap:.3rem;padding:.35rem .7rem;background:#ecfdf5;color:#059669;border-radius:6px;font-size:.78rem;font-weight:700;border:1px solid #a7f3d0;text-decoration:none }
.btn-pdf:hover  { background:#d1fae5 }
.empty-msg { text-align:center;padding:3.5rem 1rem;color:#9ca3af }
.empty-msg p { margin:.75rem 0 0;font-size:.9rem }

/* Export buttons */
.export-toolbar { display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem }
.exp-btn { display:inline-flex;align-items:center;gap:.28rem;padding:.4rem .75rem;border-radius:8px;border:1.5px solid;font-size:.73rem;font-weight:700;cursor:pointer;white-space:nowrap;transition:all .18s;font-family:inherit }
.exp-copy  { background:#f1f5f9;border-color:#cbd5e1;color:#475569 }
.exp-csv   { background:#ecfdf5;border-color:#6ee7b7;color:#065f46 }
.exp-excel { background:#eff6ff;border-color:#93c5fd;color:#1d4ed8 }
.exp-pdf   { background:#fef2f2;border-color:#fca5a5;color:#b91c1c }
.exp-prnt  { background:#f5f3ff;border-color:#c4b5fd;color:#6d28d9 }
.exp-btn:hover { transform:translateY(-1px);box-shadow:0 2px 8px rgba(0,0,0,.1) }

/* Modal */
.modal-bg { display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center }
.modal-bg.open { display:flex }
.modal-box { background:#fff;width:90%;max-width:540px;border-radius:16px;overflow:hidden;box-shadow:0 20px 50px rgba(0,0,0,.2) }
.modal-head { display:flex;justify-content:space-between;align-items:center;padding:1rem 1.4rem;background:#f9fafb;border-bottom:1px solid #e5e7eb }
.modal-head h3 { margin:0;font-size:.95rem;color:#111827;font-weight:800 }
.modal-close { background:none;border:none;font-size:1.4rem;cursor:pointer;color:#6b7280;line-height:1 }
.modal-body { padding:1.1rem 1.4rem;max-height:52vh;overflow-y:auto }
.stu-row { display:flex;flex-direction:column;padding:.6rem 0;border-bottom:1px solid #f3f4f6 }
.stu-row:last-child { border-bottom:none }
.stu-name { font-weight:700;color:#111827;font-size:.875rem }
.stu-meta { font-size:.77rem;color:#6b7280;margin-top:2px }

@media (max-width:768px) {
    .filter-bar { flex-direction:column;align-items:stretch }
    .stats-strip { grid-template-columns:1fr 1fr }
}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include __DIR__ . '/sidebar.php'; ?>

<main class="main-content">
    <header class="top-header">
        <div class="header-left">
            <button class="hamburger" id="hamburgerBtn">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <div class="header-greeting">
                <h2>Gyanam Share Receipts</h2>
                <p>All ATC share payment receipts — searchable &amp; filterable</p>
            </div>
        </div>
        <div class="header-right">
            <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
            <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
        </div>
    </header>

    <div class="page-content">

        <!-- Stats -->
        <div class="stats-strip">
            <div class="stat-card">
                <div class="stat-icon blue"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
                <div><div class="stat-val"><?= $totalReceipts ?></div><div class="stat-lbl">Total Receipts</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
                <div><div class="stat-val">₹<?= number_format($totalAmount, 0) ?></div><div class="stat-lbl">Total Collected</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon violet"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
                <div><div class="stat-val"><?= $totalStudents ?></div><div class="stat-lbl">Students Covered</div></div>
            </div>
            <?php if ($filterAtc): ?>
            <div class="stat-card">
                <div class="stat-icon blue"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg></div>
                <?php
                $selAtcName = '';
                foreach ($atcList as $a) if ($a['id'] == $filterAtc) { $selAtcName = $a['name']; break; }
                ?>
                <div><div class="stat-val" style="font-size:.95rem"><?= htmlspecialchars($selAtcName) ?></div><div class="stat-lbl">Filtered ATC</div></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Filter bar -->
        <form method="GET" class="filter-bar">
            <div class="filter-grp">
                <label>ATC Center</label>
                <select name="atc_id" style="min-width:200px">
                    <option value="">— All ATCs —</option>
                    <?php foreach ($atcList as $a): ?>
                    <option value="<?= $a['id'] ?>" <?= $filterAtc == $a['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['name']) ?>
                        <?php if ($a['atc_code']): ?>(<?= htmlspecialchars($a['atc_code']) ?>)<?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-grp">
                <label>From Date</label>
                <input type="date" name="from_date" value="<?= htmlspecialchars($filterFrom) ?>">
            </div>
            <div class="filter-grp">
                <label>To Date</label>
                <input type="date" name="to_date" value="<?= htmlspecialchars($filterTo) ?>">
            </div>
            <div class="filter-grp">
                <label>Or Month</label>
                <input type="month" name="month" value="<?= htmlspecialchars($filterMonth) ?>">
            </div>
            <button type="submit" class="btn-filter">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                Apply Filter
            </button>
            <?php if ($filterAtc || $filterFrom || $filterTo || $filterMonth): ?>
            <a href="share_receipts.php" class="btn-reset">✕ Clear</a>
            <?php endif; ?>
        </form>

        <!-- Export buttons -->
        <div class="export-toolbar">
            <button class="exp-btn exp-copy"  onclick="exportCopy()"   title="Copy">📋 Copy</button>
            <button class="exp-btn exp-csv"   onclick="exportCSV()"    title="CSV">📄 CSV</button>
            <button class="exp-btn exp-excel" onclick="exportExcel()"  title="Excel">📊 Excel</button>
            <button class="exp-btn exp-pdf"   onclick="exportPDF()"    title="PDF">📑 PDF</button>
            <button class="exp-btn exp-prnt"  onclick="printReceipts()" title="Print">🖨️ Print</button>
            <span style="margin-left:auto;font-size:.8rem;color:var(--text-secondary);align-self:center">
                <?= $totalReceipts ?> receipt<?= $totalReceipts !== 1 ? 's' : '' ?> found
            </span>
        </div>

        <!-- Table -->
        <div class="tbl-wrap">
            <table class="rr-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Receipt No</th>
                        <th>ATC Center</th>
                        <th>TSN / Payment ID</th>
                        <th>Transaction Time</th>
                        <th>Roll Numbers</th>
                        <th>Amount</th>
                        <th style="text-align:center">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($receipts)): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-msg">
                                <svg xmlns="http://www.w3.org/2000/svg" width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <p>No Gyanam Share receipts found<?= ($filterAtc || $filterFrom || $filterTo || $filterMonth) ? ' for the selected filters' : '' ?>.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($receipts as $idx => $r):
                        $ids  = json_decode($r['student_ids'] ?? '[]', true);
                        if (!is_array($ids)) $ids = [];
                        $rolls = [];
                        $modalStudents = [];
                        foreach ($ids as $sid) {
                            if (isset($studentMap[$sid])) {
                                $rolls[] = $studentMap[$sid]['roll_no'] ?: 'N/A';
                                $modalStudents[] = [
                                    'name'   => $studentMap[$sid]['name'],
                                    'roll'   => $studentMap[$sid]['roll_no'] ?: 'N/A',
                                    'course' => $studentMap[$sid]['course'],
                                ];
                            } else {
                                $rolls[] = 'Unknown';
                                $modalStudents[] = ['name' => 'Unknown Student', 'roll' => 'Unknown', 'course' => ''];
                            }
                        }
                        $rcptNo    = 'GYANAM-' . date('Y', strtotime($r['created_at'])) . '-' . $r['id'];
                        $modalJson = htmlspecialchars(json_encode($modalStudents), ENT_QUOTES, 'UTF-8');
                        $txnDate   = $r['paid_at'] ? $r['paid_at'] : $r['created_at'];
                        $rollStr   = implode(', ', $rolls);
                    ?>
                    <tr>
                        <td style="color:var(--text-secondary);font-size:.78rem"><?= $idx + 1 ?></td>
                        <td><strong style="font-family:monospace;font-size:.83rem;color:#4361ee"><?= htmlspecialchars($rcptNo) ?></strong></td>
                        <td>
                            <div style="font-weight:700;font-size:.85rem"><?= htmlspecialchars($r['atc_name'] ?? '—') ?></div>
                            <?php if ($r['atc_code']): ?>
                            <span class="atc-badge"><?= htmlspecialchars($r['atc_code']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><span class="tsn-badge" title="<?= htmlspecialchars($r['razorpay_payment_id'] ?? '') ?>"><?= htmlspecialchars($r['razorpay_payment_id'] ?? '—') ?></span></td>
                        <td style="font-size:.82rem;color:#6b7280"><?= date('d M Y, h:i A', strtotime($txnDate)) ?></td>
                        <td>
                            <span class="roll-list" title="<?= htmlspecialchars($rollStr) ?>"><?= htmlspecialchars($rollStr) ?></span>
                            <span class="roll-count"><?= count($rolls) ?> student(s)</span>
                        </td>
                        <td><span class="amount-val">₹<?= number_format($r['total_amount'], 2) ?></span></td>
                        <td style="text-align:center">
                            <div style="display:flex;gap:.4rem;justify-content:center">
                                <button type="button" class="btn-view" onclick="showModal('<?= htmlspecialchars($rcptNo, ENT_QUOTES) ?>', '<?= $modalJson ?>')">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    View
                                </button>
                                <a href="../atc/print_share_receipt.php?id=<?= $r['id'] ?>" target="_blank" class="btn-pdf">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                    Print
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div><!-- /page-content -->
</main>
</div>

<!-- Detail modal -->
<div class="modal-bg" id="rcptModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3 id="modalTitle">Receipt Details</h3>
            <button class="modal-close" onclick="closeModal()">×</button>
        </div>
        <div class="modal-body" id="modalBody"></div>
    </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
function showModal(rcptNo, jsonStr) {
    document.getElementById('modalTitle').textContent = 'Receipt ' + rcptNo;
    var students = JSON.parse(jsonStr);
    var html = '';
    if (!students.length) {
        html = '<p style="color:#9ca3af;text-align:center;padding:1rem">No student details.</p>';
    } else {
        for (var i = 0; i < students.length; i++) {
            var s = students[i];
            html += '<div class="stu-row">';
            html += '<span class="stu-name">' + (i+1) + '. ' + s.name + '</span>';
            html += '<span class="stu-meta">Roll No: <strong>' + s.roll + '</strong> &bull; ' + s.course + '</span>';
            html += '</div>';
        }
    }
    document.getElementById('modalBody').innerHTML = html;
    document.getElementById('rcptModal').classList.add('open');
}
function closeModal() { document.getElementById('rcptModal').classList.remove('open'); }
window.addEventListener('click', function(e) { if (e.target === document.getElementById('rcptModal')) closeModal(); });

// ── Export helpers ─────────────────────────────────────────────────────────
function getRcptTableData() {
    const headers = ['#','Receipt No','ATC Center','ATC Code','TSN ID','Transaction Time','Roll Numbers','Students','Amount (Rs)'];
    const rows = [];
    document.querySelectorAll('.rr-table tbody tr').forEach(tr => {
        const tds = tr.querySelectorAll('td');
        if (!tds.length || tds[0].getAttribute('colspan')) return;
        rows.push([
            tds[0]?.textContent?.trim() || '',
            tds[1]?.textContent?.trim() || '',
            tds[2]?.querySelector('div')?.textContent?.trim() || '',
            tds[2]?.querySelector('.atc-badge')?.textContent?.trim() || '',
            tds[3]?.querySelector('.tsn-badge')?.title || tds[3]?.textContent?.trim() || '',
            tds[4]?.textContent?.trim() || '',
            tds[5]?.querySelector('.roll-list')?.title || tds[5]?.querySelector('.roll-list')?.textContent?.trim() || '',
            tds[5]?.querySelector('.roll-count')?.textContent?.trim() || '',
            tds[6]?.textContent?.replace(/[₹\s]/g,'')?.trim() || '',
        ]);
    });
    return { headers, rows };
}
function exportCopy() {
    const { headers, rows } = getRcptTableData();
    if (!rows.length) { alert('No data.'); return; }
    navigator.clipboard.writeText([headers,...rows].map(r=>r.join('\t')).join('\n'))
        .then(()=>alert('✅ Copied '+rows.length+' receipt(s)!'));
}
function exportCSV() {
    const { headers, rows } = getRcptTableData();
    if (!rows.length) { alert('No data.'); return; }
    const csv=[headers,...rows].map(r=>r.map(c=>'"'+String(c).replace(/"/g,'""')+'"').join(',')).join('\n');
    Object.assign(document.createElement('a'),{href:'data:text/csv;charset=utf-8,'+encodeURIComponent(csv),download:'share_receipts_'+Date.now()+'.csv'}).click();
}
function exportExcel() {
    const { headers, rows } = getRcptTableData();
    if (!rows.length) { alert('No data.'); return; }
    const ws=XLSX.utils.aoa_to_sheet([headers,...rows]);
    const wb=XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb,ws,'Share Receipts');
    XLSX.writeFile(wb,'share_receipts_'+Date.now()+'.xlsx');
}
function exportPDF() {
    const { headers, rows } = getRcptTableData();
    if (!rows.length) { alert('No data.'); return; }
    const {jsPDF}=window.jspdf;
    const doc=new jsPDF({orientation:'landscape'});
    doc.setFontSize(13); doc.text('Gyanam Share Receipts — Gyanam India Educational Services',14,14);
    doc.setFontSize(9);  doc.text('Generated: '+new Date().toLocaleString('en-IN'),14,21);
    doc.autoTable({head:[headers],body:rows,startY:26,styles:{fontSize:7},headStyles:{fillColor:[67,97,238]}});
    doc.save('share_receipts_'+Date.now()+'.pdf');
}
function printReceipts() {
    const { headers, rows } = getRcptTableData();
    if (!rows.length) { alert('No data to print.'); return; }
    const now=new Date().toLocaleString('en-IN',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
    const thHtml=headers.map(h=>`<th>${h}</th>`).join('');
    const rowsHtml=rows.map(r=>'<tr>'+r.map(c=>`<td>${c}</td>`).join('')+'</tr>').join('');
    const html=`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Share Receipts</title>
    <style>body{font-family:Arial,sans-serif;margin:1cm;font-size:11px}h2{margin:0;font-size:14px}p{margin:0 0 6px}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    th{background:#4361ee;color:#fff;padding:6px 8px;text-align:left;font-size:9px;text-transform:uppercase}
    td{padding:4px 8px;border-bottom:1px solid #e5e7eb}tr:nth-child(even) td{background:#f8fafc}
    .footer{margin-top:12px;font-size:10px;color:#94a3b8;text-align:right}
    @media print{@page{margin:1cm;size:landscape}}</style></head><body>
    <h2>Gyanam Share Receipts — Gyanam India Educational Services</h2>
    <p style="font-size:11px;color:#64748b">Generated: ${now} &bull; ${rows.length} receipt(s)</p>
    <table><thead><tr>${thHtml}</tr></thead><tbody>${rowsHtml}</tbody></table>
    <div class="footer">Gyanam India Educational Services — Confidential</div></body></html>`;
    const w=window.open('','_blank','width=1200,height=750');
    w.document.write(html); w.document.close(); w.focus();
    setTimeout(()=>{w.print();w.close();},400);
}
</script>
</body>
</html>
