<?php
/**
 * Gyanam Portal — ATC: Report Receipt
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$atcId = isset($_SESSION['atc_id']) ? $_SESSION['atc_id'] : null;

// Fetch all students to map IDs -> Roll No / Name
$studentMap = array();
try {
    $sq = $pdo->prepare("SELECT id, roll_no, first_name, middle_name, last_name, course FROM admissions WHERE atc_id = ?");
    $sq->execute(array($atcId));
    foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $name = trim($s['first_name'] . ' ' . ($s['middle_name'] ? $s['middle_name'] . ' ' : '') . $s['last_name']);
        $studentMap[$s['id']] = array(
            'roll_no' => $s['roll_no'],
            'name' => $name,
            'course' => $s['course']
        );
    }
}
catch (Exception $e) {
}

// Parse Filters
$filterFrom = $_GET['from_date'] ?? '';
$filterTo = $_GET['to_date'] ?? '';
$filterMonth = $_GET['month'] ?? ''; // Format: YYYY-MM

// Build Query
$sql = "SELECT * FROM share_payments WHERE atc_id = ? AND status = 'Completed'";
$params = [$atcId];

if ($filterFrom) {
    $sql .= " AND DATE(COALESCE(paid_at, created_at)) >= ?";
    $params[] = $filterFrom;
}
if ($filterTo) {
    $sql .= " AND DATE(COALESCE(paid_at, created_at)) <= ?";
    $params[] = $filterTo;
}
if ($filterMonth && $filterMonth !== '') {
    $sql .= " AND DATE_FORMAT(COALESCE(paid_at, created_at), '%Y-%m') = ?";
    $params[] = $filterMonth;
}

$sql .= " ORDER BY created_at DESC";

// Fetch completed share payments
$receipts = array();
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
catch (Exception $e) {
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Receipt — ATC Center | Gyanam India Educational Services</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <style>
        .page-content { padding: 1.75rem 2rem; }
        .tbl-wrap { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
        .rr-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .rr-table thead th {
            padding: .75rem 1rem; text-align: left; font-size: .7rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .07em; color: #9ca3af;
            background: #f9fafb; border-bottom: 1px solid #e5e7eb;
        }
        .rr-table tbody tr { border-bottom: 1px solid #f9fafb; }
        .rr-table tbody tr:hover { background: #f0f4ff; }
        .rr-table tbody td { padding: .9rem 1rem; vertical-align: middle; }
        .tsn-badge { font-family: monospace; font-size: .8rem; color: #6b7280; background: #f3f4f6; padding: .2rem .5rem; border-radius: 4px; display: inline-block; max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: middle; }
        .amount-val { font-weight: 700; color: #4361ee; }
        .roll-list { font-size: .82rem; color: #374151; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px; display: inline-block; }
        .roll-count { font-size: .72rem; color: #9ca3af; display: block; margin-top: 2px; }
        .btn-view { display: inline-flex; align-items: center; gap: .35rem; padding: .38rem .75rem; background: #eff6ff; color: #3b82f6; border-radius: 6px; font-size: .8rem; font-weight: 600; border: 1px solid #bfdbfe; cursor: pointer; font-family: inherit; }
        .btn-view:hover { background: #dbeafe; }
        .btn-pdf { display: inline-flex; align-items: center; gap: .35rem; padding: .38rem .75rem; background: #ecfdf5; color: #059669; border-radius: 6px; font-size: .8rem; font-weight: 600; border: 1px solid #a7f3d0; text-decoration: none; }
        .btn-pdf:hover { background: #d1fae5; }
        .empty-msg { text-align: center; padding: 3rem 1rem; color: #9ca3af; }
        .empty-msg p { margin: .75rem 0 0; }

        /* Modal */
        .modal-bg { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 9999; align-items: center; justify-content: center; }
        .modal-bg.open { display: flex; }
        .modal-box { background: #fff; width: 90%; max-width: 560px; border-radius: 14px; overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,.2); }
        .modal-head { display: flex; justify-content: space-between; align-items: center; padding: 1.1rem 1.5rem; background: #f9fafb; border-bottom: 1px solid #e5e7eb; }
        .modal-head h3 { margin: 0; font-size: 1rem; color: #111827; }
        .modal-close { background: none; border: none; font-size: 1.4rem; cursor: pointer; color: #6b7280; line-height: 1; padding: 0; }
        .modal-body { padding: 1.25rem 1.5rem; max-height: 55vh; overflow-y: auto; }
        .stu-row { display: flex; flex-direction: column; padding: .65rem 0; border-bottom: 1px solid #f3f4f6; }
        .stu-row:last-child { border-bottom: none; }
        .stu-name { font-weight: 600; color: #111827; font-size: .9rem; }
        .stu-meta { font-size: .78rem; color: #6b7280; margin-top: 2px; }

        /* Filter Bar */
        .filter-bar { display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap; margin-bottom: 1.5rem; background: #fff; padding: 1.25rem; border-radius: 12px; border: 1px solid #e5e7eb; }
        .filter-grp { display: flex; flex-direction: column; gap: 0.35rem; }
        .filter-grp label { font-size: 0.8rem; font-weight: 700; color: #374151; }
        .filter-grp input, .filter-grp select { height: 38px; padding: 0 0.75rem; border: 1.5px solid #e5e7eb; border-radius: 8px; font-family: inherit; font-size: 0.875rem; outline: none; transition: border-color .2s; }
        .filter-grp input:focus, .filter-grp select:focus { border-color: #4361ee; }
        .btn-filter { height: 38px; padding: 0 1.25rem; background: #4361ee; color: #fff; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; font-family: inherit; transition: background .2s; }
        .btn-filter:hover { background: #3730a3; }
        .btn-reset { height: 38px; padding: 0 1.25rem; background: #f3f4f6; color: #4b5563; border: 1.5px solid #e5e7eb; border-radius: 8px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; font-family: inherit; transition: background .2s; }
        .btn-reset:hover { background: #e5e7eb; }
        
        @media (max-width: 768px) {
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-grp { width: 100%; }
            .filter-grp[style] { margin-left: 0 !important; padding-left: 0 !important; border-left: none !important; border-top: 1px solid #e5e7eb; padding-top: 1rem !important; margin-top: 0.5rem !important; }
        }
    </style>
    <!-- Export libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <style>
    .export-toolbar { display: flex; gap: .4rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; }
    .exp-btn {
        display: inline-flex; align-items: center; gap: .28rem;
        padding: .4rem .75rem; border-radius: 8px; border: 1.5px solid;
        font-size: .73rem; font-weight: 700; cursor: pointer;
        white-space: nowrap; transition: all .18s; font-family: inherit;
    }
    .exp-copy  { background:#f1f5f9; border-color:#cbd5e1; color:#475569; }
    .exp-csv   { background:#ecfdf5; border-color:#6ee7b7; color:#065f46; }
    .exp-excel { background:#eff6ff; border-color:#93c5fd; color:#1d4ed8; }
    .exp-pdf   { background:#fef2f2; border-color:#fca5a5; color:#b91c1c; }
    .exp-print { background:#f5f3ff; border-color:#c4b5fd; color:#6d28d9; }
    .exp-btn:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,.1); }
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
                    <h2>Report Receipt</h2>
                    <p>View and download Gyanam Share fee receipts</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">
            <!-- Filter Bar -->
            <form method="GET" action="report_receipt.php" class="filter-bar">
                <div class="filter-grp">
                    <label>From Date</label>
                    <input type="date" name="from_date" value="<?php echo htmlspecialchars($filterFrom); ?>">
                </div>
                <div class="filter-grp">
                    <label>To Date</label>
                    <input type="date" name="to_date" value="<?php echo htmlspecialchars($filterTo); ?>">
                </div>
                <div class="filter-grp" style="margin-left: 0.5rem; padding-left: 1.25rem; border-left: 1px solid #e5e7eb;">
                    <label>Or select Month</label>
                    <input type="month" name="month" value="<?php echo htmlspecialchars($filterMonth); ?>">
                </div>
                <div class="filter-grp" style="flex-direction: row; align-items: flex-end; margin-left: auto;">
                    <button type="submit" class="btn-filter">Apply Filter</button>
                    <?php if ($filterFrom || $filterTo || $filterMonth): ?>
                        <a href="report_receipt.php" class="btn-reset">Reset</a>
                    <?php
endif; ?>
                </div>
            </form>
            <!-- Export toolbar -->
            <div class="export-toolbar">
                <button class="exp-btn exp-copy"  onclick="exportCopy()"  title="Copy">📋 Copy</button>
                <button class="exp-btn exp-csv"   onclick="exportCSV()"   title="CSV">📄 CSV</button>
                <button class="exp-btn exp-excel" onclick="exportExcel()" title="Excel">📊 Excel</button>
                <button class="exp-btn exp-pdf"   onclick="exportPDF()"   title="PDF">📑 PDF</button>
                <button class="exp-btn exp-print" onclick="printReceipts()" title="Print">🖨️ Print</button>
            </div>

            <div class="tbl-wrap">
                <table class="rr-table">
                    <thead>
                        <tr>
                            <th>Receipt No</th>
                            <th>TSN ID</th>
                            <th>Transaction Time</th>
                            <th>Roll Numbers</th>
                            <th>Amount</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($receipts)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-msg">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    <p>No Gyanam Share receipts found.</p>
                                </div>
                            </td>
                        </tr>
                    <?php
else: ?>
                        <?php foreach ($receipts as $r): ?>
                        <?php
        $ids = json_decode($r['student_ids'], true);
        if (!is_array($ids))
            $ids = array();
        $rolls = array();
        $modalStudents = array();
        foreach ($ids as $sid) {
            if (isset($studentMap[$sid])) {
                $rolls[] = $studentMap[$sid]['roll_no'] ? $studentMap[$sid]['roll_no'] : 'N/A';
                $modalStudents[] = array(
                    'name' => $studentMap[$sid]['name'],
                    'roll' => $studentMap[$sid]['roll_no'] ? $studentMap[$sid]['roll_no'] : 'N/A',
                    'course' => $studentMap[$sid]['course']
                );
            }
            else {
                $rolls[] = 'Unknown';
                $modalStudents[] = array('name' => 'Unknown Student', 'roll' => 'Unknown', 'course' => '');
            }
        }
        $rcptNo = 'GYANAM-' . date('Y', strtotime($r['created_at'])) . '-' . $r['id'];
        $modalJson = htmlspecialchars(json_encode($modalStudents), ENT_QUOTES, 'UTF-8');
        $txnDate = $r['paid_at'] ? $r['paid_at'] : $r['created_at'];
?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($rcptNo); ?></strong></td>
                            <td><span class="tsn-badge" title="<?php echo htmlspecialchars($r['razorpay_payment_id']); ?>"><?php echo htmlspecialchars($r['razorpay_payment_id']); ?></span></td>
                            <td style="font-size:.85rem;color:#6b7280;"><?php echo date('d M Y, h:i A', strtotime($txnDate)); ?></td>
                            <td>
                                <span class="roll-list" title="<?php echo htmlspecialchars(implode(', ', $rolls)); ?>"><?php echo htmlspecialchars(implode(', ', $rolls)); ?></span>
                                <span class="roll-count"><?php echo count($rolls); ?> student(s)</span>
                            </td>
                            <td><span class="amount-val">&#8377;<?php echo number_format($r['total_amount'], 2); ?></span></td>
                            <td style="text-align:center;">
                                <div style="display:flex;gap:.5rem;justify-content:center;">
                                    <button type="button" class="btn-view" onclick="showModal('<?php echo htmlspecialchars($rcptNo, ENT_QUOTES); ?>', '<?php echo $modalJson; ?>')">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        View
                                    </button>
                                    <a href="print_share_receipt.php?id=<?php echo $r['id']; ?>" target="_blank" class="btn-pdf">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        PDF
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php
    endforeach; ?>
                    <?php
endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- Detail popup modal -->
<div class="modal-bg" id="rcptModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3 id="modalTitle">Receipt Details</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody"><!-- injected --></div>
    </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
function showModal(rcptNo, jsonStr) {
    document.getElementById('modalTitle').textContent = 'Receipt ' + rcptNo;
    var students = JSON.parse(jsonStr);
    var html = '';
    if (students.length === 0) {
        html = '<p style="color:#9ca3af;text-align:center;padding:1rem;">No student details.</p>';
    } else {
        for (var i = 0; i < students.length; i++) {
            var s = students[i];
            html += '<div class="stu-row">';
            html += '<span class="stu-name">' + (i + 1) + '. ' + s.name + '</span>';
            html += '<span class="stu-meta">Roll No: <strong>' + s.roll + '</strong> &bull; ' + s.course + '</span>';
            html += '</div>';
        }
    }
    document.getElementById('modalBody').innerHTML = html;
    document.getElementById('rcptModal').classList.add('open');
}

function closeModal() {
    document.getElementById('rcptModal').classList.remove('open');
}

window.addEventListener('click', function(e) {
    if (e.target === document.getElementById('rcptModal')) closeModal();
});

// ── Export helpers (Report Receipts) ─────────────────────────────────────
function getRcptTableData() {
    const headers = ['Receipt No','TSN ID','Transaction Time','Roll Numbers','Student Count','Amount (Rs)'];
    const rows = [];
    document.querySelectorAll('.rr-table tbody tr').forEach(tr => {
        const tds = tr.querySelectorAll('td');
        if (!tds.length || tds[0].getAttribute('colspan')) return;
        rows.push([
            tds[0]?.textContent?.trim() || '',
            tds[1]?.querySelector('.tsn-badge')?.title || tds[1]?.textContent?.trim() || '',
            tds[2]?.textContent?.trim() || '',
            tds[3]?.querySelector('.roll-list')?.title || tds[3]?.querySelector('.roll-list')?.textContent?.trim() || '',
            tds[3]?.querySelector('.roll-count')?.textContent?.trim() || '',
            tds[4]?.textContent?.replace(/[₹\s]/g,'')?.trim() || '',
        ]);
    });
    return { headers, rows };
}
function exportCopy() {
    const { headers, rows } = getRcptTableData();
    if (!rows.length) { alert('No data to copy.'); return; }
    navigator.clipboard.writeText([headers,...rows].map(r=>r.join('\t')).join('\n'))
        .then(()=>alert('✅ Copied '+rows.length+' receipt(s) to clipboard!'));
}
function exportCSV() {
    const { headers, rows } = getRcptTableData();
    if (!rows.length) { alert('No data to export.'); return; }
    const csv=[headers,...rows].map(r=>r.map(c=>'"'+String(c).replace(/"/g,'""')+'"').join(',')).join('\n');
    Object.assign(document.createElement('a'),{href:'data:text/csv;charset=utf-8,'+encodeURIComponent(csv),download:'receipts_'+Date.now()+'.csv'}).click();
}
function exportExcel() {
    const { headers, rows } = getRcptTableData();
    if (!rows.length) { alert('No data to export.'); return; }
    const ws=XLSX.utils.aoa_to_sheet([headers,...rows]);
    const wb=XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb,ws,'Receipts');
    XLSX.writeFile(wb,'receipts_'+Date.now()+'.xlsx');
}
function exportPDF() {
    const { headers, rows } = getRcptTableData();
    if (!rows.length) { alert('No data to export.'); return; }
    const {jsPDF}=window.jspdf;
    const doc=new jsPDF({orientation:'landscape'});
    doc.setFontSize(13);doc.text('Report Receipts — Gyanam India Educational Services',14,14);
    doc.setFontSize(9);doc.text('Generated: '+new Date().toLocaleString('en-IN'),14,21);
    doc.autoTable({head:[headers],body:rows,startY:26,styles:{fontSize:8},headStyles:{fillColor:[67,97,238]}});
    doc.save('receipts_'+Date.now()+'.pdf');
}
function printReceipts() {
    const { headers, rows } = getRcptTableData();
    if (!rows.length) { alert('No data to print.'); return; }
    const now=new Date().toLocaleString('en-IN',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
    const thHtml=headers.map(h=>`<th>${h}</th>`).join('');
    const rowsHtml=rows.map(r=>'<tr>'+r.map(c=>`<td>${c}</td>`).join('')+'</tr>').join('');
    const html=`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Report Receipts</title>
    <style>body{font-family:Arial,sans-serif;margin:1cm;font-size:11px}h2{margin:0;font-size:15px}p{margin:0 0 8px}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    th{background:#4361ee;color:#fff;padding:6px 8px;text-align:left;font-size:9.5px;text-transform:uppercase}
    td{padding:5px 8px;border-bottom:1px solid #e5e7eb}tr:nth-child(even) td{background:#f8fafc}
    .footer{margin-top:12px;font-size:10px;color:#94a3b8;text-align:right}
    @media print{@page{margin:1cm;size:landscape}}</style></head><body>
    <h2>Report Receipts — Gyanam India Educational Services</h2>
    <p style="font-size:11px;color:#64748b">Generated: ${now} &bull; ${rows.length} receipt(s)</p>
    <table><thead><tr>${thHtml}</tr></thead><tbody>${rowsHtml}</tbody></table>
    <div class="footer">Gyanam India Educational Services — Confidential</div></body></html>`;
    const w=window.open('','_blank','width=1100,height=700');
    w.document.write(html);w.document.close();w.focus();
    setTimeout(()=>{w.print();w.close();},400);
}
</script>
</body>
</html>
