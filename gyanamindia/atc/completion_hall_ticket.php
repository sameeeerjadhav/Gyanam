<?php
/**
 * Gyanam Portal — ATC: Completion Hall Ticket
 * Shows students with exams on a selected date; export + attendance sheet.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo      = getDBConnection();
$userName = sanitize(getUserName());
$atcId    = $_SESSION['atc_id'] ?? null;

// Date filter (default: today)
$examDate = $_GET['exam_date'] ?? date('Y-m-d');
$examDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $examDate) ? $examDate : date('Y-m-d');

// Handle AJAX: schedule exam for a student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        if ($_POST['action'] === 'schedule_exam') {
            $admId  = intval($_POST['admission_id'] ?? 0);
            $date   = $_POST['exam_date'] ?? '';
            $time   = $_POST['exam_time'] ?? '10:00';
            $hall   = trim($_POST['exam_hall'] ?? '');
            if (!$admId || !$date) { echo json_encode(['success'=>false,'message'=>'Missing fields']); exit; }
            // Upsert
            $chk = $pdo->prepare("SELECT id FROM exam_schedules WHERE admission_id=? AND atc_id=?");
            $chk->execute([$admId, $atcId]);
            if ($existing = $chk->fetch()) {
                $upd = $pdo->prepare("UPDATE exam_schedules SET exam_date=?,exam_time=?,exam_hall=? WHERE id=?");
                $upd->execute([$date,$time,$hall,$existing['id']]);
            } else {
                $ins = $pdo->prepare("INSERT INTO exam_schedules(admission_id,atc_id,exam_date,exam_time,exam_hall) VALUES(?,?,?,?,?)");
                $ins->execute([$admId,$atcId,$date,$time,$hall]);
            }
            echo json_encode(['success'=>true,'message'=>'Exam scheduled.']);
            exit;
        }
        if ($_POST['action'] === 'remove_exam') {
            $admId = intval($_POST['admission_id'] ?? 0);
            $pdo->prepare("DELETE FROM exam_schedules WHERE admission_id=? AND atc_id=?")->execute([$admId,$atcId]);
            echo json_encode(['success'=>true,'message'=>'Removed.']);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit;
    }
}

// Fetch students scheduled for the selected date
$stmt = $pdo->prepare("
    SELECT a.id, a.roll_no, a.registration_id, a.first_name, a.middle_name, a.last_name,
           a.course, a.photo, a.mobile,
           es.exam_date, es.exam_time, es.exam_hall
    FROM admissions a
    JOIN exam_schedules es ON es.admission_id = a.id AND es.atc_id = a.atc_id
    WHERE a.atc_id = ? AND es.exam_date = ? AND a.status = 'Active'
    ORDER BY es.exam_time ASC, a.roll_no ASC
");
$stmt->execute([$atcId, $examDate]);
$scheduledStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch ALL active students for the "Schedule Exam" modal
$stmt2 = $pdo->prepare("SELECT id, roll_no, registration_id, first_name, middle_name, last_name, course FROM admissions WHERE atc_id=? AND status='Active' ORDER BY roll_no ASC");
$stmt2->execute([$atcId]);
$allStudents = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// ATC details
$stmt3 = $pdo->prepare("SELECT * FROM atc_centers WHERE id=?");
$stmt3->execute([$atcId]);
$atcDetails = $stmt3->fetch(PDO::FETCH_ASSOC);

$displayDate = date('d M Y', strtotime($examDate));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Completion Hall Ticket — ATC Center | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <!-- DataTables (CDN) for Copy/Excel/CSV/PDF/Print -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📋</text></svg>">
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <div class="header-greeting">
                    <h2>Completion Hall Ticket</h2>
                    <p>Exam schedule & attendance for <?= htmlspecialchars($displayDate) ?></p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="cht-container">

            <!-- Date Picker toolbar -->
            <div class="cht-toolbar">
                <form method="GET" class="cht-date-form">
                    <label class="cht-date-label">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Exam Date
                    </label>
                    <input type="date" name="exam_date" value="<?= htmlspecialchars($examDate) ?>" class="cht-date-input" onchange="this.form.submit()">
                </form>
                <div class="cht-toolbar-actions">
                    <button onclick="openScheduleModal()" class="cht-btn-schedule">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Schedule Exam
                    </button>
                    <button onclick="generateAttendanceSheet()" class="cht-btn-attendance">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        Attendance Sheet
                    </button>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="cht-kpi-grid">
                <div class="cht-kpi-card cht-kpi-blue">
                    <div class="cht-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div class="cht-kpi-content">
                        <div class="cht-kpi-value"><?= count($scheduledStudents) ?></div>
                        <div class="cht-kpi-label">Students Today</div>
                    </div>
                </div>
                <div class="cht-kpi-card cht-kpi-green">
                    <div class="cht-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <div class="cht-kpi-content">
                        <div class="cht-kpi-value"><?= htmlspecialchars($displayDate) ?></div>
                        <div class="cht-kpi-label">Selected Exam Date</div>
                    </div>
                </div>
                <div class="cht-kpi-card cht-kpi-purple">
                    <div class="cht-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <div class="cht-kpi-content">
                        <div class="cht-kpi-value"><?= count(array_unique(array_column($scheduledStudents,'course'))) ?></div>
                        <div class="cht-kpi-label">Courses</div>
                    </div>
                </div>
                <div class="cht-kpi-card cht-kpi-amber">
                    <div class="cht-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div class="cht-kpi-content">
                        <div class="cht-kpi-value"><?= empty($scheduledStudents) ? '—' : date('h:i A', strtotime($scheduledStudents[0]['exam_time'])) ?></div>
                        <div class="cht-kpi-label">First Exam Time</div>
                    </div>
                </div>
            </div>

            <!-- Export Table -->
            <div class="cht-table-card">
                <div class="cht-table-header">
                    <h3 class="cht-table-title">
                        Students — <?= htmlspecialchars($displayDate) ?>
                        <span class="cht-badge"><?= count($scheduledStudents) ?></span>
                    </h3>
                </div>
                <div class="cht-table-wrapper">
                    <table id="chtDataTable" class="display" style="width:100%">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Roll No</th>
                                <th>Registration ID</th>
                                <th>Student Name</th>
                                <th>Course</th>
                                <th>Exam Date</th>
                                <th>Exam Time</th>
                                <th>Hall</th>
                                <th class="no-export">Hall Ticket</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($scheduledStudents)): ?>
                                <tr>
                                    <td colspan="9" style="text-align:center;padding:3rem;color:#9ca3af">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="40" height="40" style="display:block;margin:0 auto .75rem"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                                        No students scheduled for this date.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($scheduledStudents as $i => $s): ?>
                                    <?php
                                        $fullName = $s['first_name'] . ' ' . ($s['middle_name'] ? $s['middle_name'].' ' : '') . $s['last_name'];
                                        $regId = $s['registration_id'] ?? $s['roll_no'];
                                    ?>
                                    <tr>
                                        <td><?= $i + 1 ?></td>
                                        <td><span class="cht-roll"><?= htmlspecialchars($s['roll_no']) ?></span></td>
                                        <td><span class="cht-reg"><?= htmlspecialchars($regId) ?></span></td>
                                        <td><?= htmlspecialchars($fullName) ?></td>
                                        <td><?= htmlspecialchars($s['course']) ?></td>
                                        <td><?= date('d M Y', strtotime($s['exam_date'])) ?></td>
                                        <td><?= date('h:i A', strtotime($s['exam_time'])) ?></td>
                                        <td><?= htmlspecialchars($s['exam_hall'] ?: '—') ?></td>
                                        <td class="no-export">
                                            <button onclick='viewHallTicket(<?= json_encode($s) ?>)' class="cht-btn-view">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                                Hall Ticket
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- ── Schedule Exam Modal ────────────────────────────────────────────────── -->
<div class="cht-modal-overlay" id="scheduleModal">
    <div class="cht-modal-card">
        <div class="cht-modal-header">
            <h3>Schedule Exam</h3>
            <button onclick="closeScheduleModal()" class="cht-modal-close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="cht-modal-body" style="padding:1.5rem">
            <div class="cht-form-group">
                <label>Student</label>
                <select id="sch_student" class="cht-select-lg">
                    <option value="">— Select Student —</option>
                    <?php foreach ($allStudents as $st): ?>
                        <?php $fn = $st['first_name'].' '.($st['middle_name'] ? $st['middle_name'].' ':'').$st['last_name']; ?>
                        <option value="<?= $st['id'] ?>"><?= htmlspecialchars("#{$st['roll_no']} — $fn ({$st['course']})") ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cht-form-row">
                <div class="cht-form-group">
                    <label>Exam Date</label>
                    <input type="date" id="sch_date" value="<?= $examDate ?>" class="cht-input">
                </div>
                <div class="cht-form-group">
                    <label>Exam Time</label>
                    <input type="time" id="sch_time" value="10:00" class="cht-input">
                </div>
            </div>
            <div class="cht-form-group">
                <label>Exam Hall <span style="color:#9ca3af;font-weight:400">(optional)</span></label>
                <input type="text" id="sch_hall" placeholder="e.g. Hall A, Room 101" class="cht-input">
            </div>
        </div>
        <div class="cht-modal-footer">
            <button onclick="closeScheduleModal()" class="cht-btn-cancel2">Cancel</button>
            <button onclick="saveSchedule()" class="cht-btn-save">Save Schedule</button>
        </div>
    </div>
</div>

<!-- ── Hall Ticket Modal ─────────────────────────────────────────────────── -->
<div class="cht-modal-overlay" id="hallTicketModal" onclick="htOverlayClick(event)">
    <div class="cht-modal-card" style="max-width:800px;display:flex;flex-direction:column;max-height:92vh">
        <div class="cht-modal-header" style="flex-shrink:0">
            <h3>🎫 Examination Hall Ticket</h3>
            <button onclick="closeHtModal()" class="cht-modal-close" title="Close" style="width:36px;height:36px;background:#fee2e2;border-radius:10px;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div id="htContent" class="cht-modal-body" style="padding:1.5rem;overflow-y:auto;flex:1"></div>
        <div class="cht-modal-footer" style="flex-shrink:0">
            <button onclick="closeHtModal()" class="cht-btn-cancel2">✕ Close</button>
            <button onclick="downloadHallTicket()" style="display:inline-flex;align-items:center;gap:.4rem;padding:.6rem 1.4rem;border-radius:10px;border:none;background:linear-gradient(135deg,#059669,#047857);color:#fff;font-size:.875rem;font-weight:700;cursor:pointer">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Download PDF
            </button>
            <button onclick="printHallTicket()" class="cht-btn-save">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Print
            </button>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="toastContainer" style="position:fixed;bottom:1.5rem;right:1.5rem;z-index:99999;display:flex;flex-direction:column;gap:.5rem"></div>

<!-- Scripts -->
<script src="../assets/js/dashboard.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
const atcDetails    = <?= json_encode($atcDetails) ?>;
const examDateStr   = <?= json_encode($examDate) ?>;
const scheduledData = <?= json_encode($scheduledStudents) ?>;

// ── DataTable with export buttons ──────────────────────────────────────────
$(document).ready(function () {
    $('#chtDataTable').DataTable({
        dom: '<"cht-dt-top"Bf>rt<"cht-dt-bottom"ip>',
        buttons: [
            {
                extend: 'copy',
                text: 'Copy',
                className: 'cht-export-btn',
                exportOptions: { columns: ':not(.no-export)' }
            },
            {
                extend: 'excel',
                text: 'Excel',
                className: 'cht-export-btn',
                title: 'Completion Hall Ticket — ' + <?= json_encode($displayDate) ?>,
                exportOptions: { columns: ':not(.no-export)' }
            },
            {
                extend: 'csv',
                text: 'CSV',
                className: 'cht-export-btn',
                exportOptions: { columns: ':not(.no-export)' }
            },
            {
                extend: 'pdf',
                text: 'PDF',
                className: 'cht-export-btn',
                title: 'Completion Hall Ticket — ' + <?= json_encode($displayDate) ?>,
                exportOptions: { columns: ':not(.no-export)' }
            },
            {
                extend: 'print',
                text: 'Print',
                className: 'cht-export-btn',
                title: 'Exam Schedule — <?= addslashes($displayDate) ?>',
                exportOptions: { columns: ':not(.no-export)' }
            }
        ],
        pageLength: 25,
        order: [[6, 'asc']]
    });
});

// ── Schedule Modal ──────────────────────────────────────────────────────────
function openScheduleModal() {
    document.getElementById('scheduleModal').classList.add('active');
}
function closeScheduleModal() {
    document.getElementById('scheduleModal').classList.remove('active');
}

async function saveSchedule() {
    const admId = document.getElementById('sch_student').value;
    const date  = document.getElementById('sch_date').value;
    const time  = document.getElementById('sch_time').value;
    const hall  = document.getElementById('sch_hall').value;
    if (!admId || !date || !time) { showToast('Please fill all required fields.', 'error'); return; }
    const fd = new FormData();
    fd.append('action','schedule_exam');
    fd.append('admission_id', admId);
    fd.append('exam_date', date);
    fd.append('exam_time', time);
    fd.append('exam_hall', hall);
    const res  = await fetch('', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) {
        showToast('✅ ' + data.message);
        closeScheduleModal();
        setTimeout(() => location.reload(), 900);
    } else {
        showToast(data.message, 'error');
    }
}

// ── Hall Ticket Preview ─────────────────────────────────────────────────────
function viewHallTicket(s) {
    const fullName = `${s.first_name} ${s.middle_name ? s.middle_name + ' ' : ''}${s.last_name}`;
    const photo    = s.photo ? `../${s.photo}` : '../assets/logo.png';
    const examDt   = new Date(s.exam_date).toLocaleDateString('en-IN',{day:'2-digit',month:'long',year:'numeric'});
    const examTm   = new Date('2000-01-01T' + s.exam_time).toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit'});
    document.getElementById('htContent').innerHTML = `
        <div class="hall-ticket">
            <div class="hall-ticket-header">
                <div class="hall-ticket-logo"><img src="../assets/logo.png" alt="Gyanam India"></div>
                <div class="hall-ticket-title">
                    <h1>GYANAM INDIA EDUCATIONAL SERVICES</h1>
                    <h2>EXAMINATION HALL TICKET</h2>
                    <p class="hall-ticket-center">${atcDetails.name}</p>
                </div>
                <div class="hall-ticket-photo"><img src="${photo}" alt="Student Photo"></div>
            </div>
            <div class="hall-ticket-body">
                <div class="hall-ticket-section">
                    <h3>CANDIDATE DETAILS</h3>
                    <table class="hall-ticket-table">
                        <tr>
                            <td class="label">Roll Number:</td><td class="value"><strong>${s.roll_no}</strong></td>
                            <td class="label">Registration ID:</td><td class="value"><strong>${s.registration_id || s.roll_no}</strong></td>
                        </tr>
                        <tr>
                            <td class="label">Candidate Name:</td><td class="value" colspan="3"><strong>${fullName.toUpperCase()}</strong></td>
                        </tr>
                        <tr>
                            <td class="label">Course:</td><td class="value"><strong>${s.course}</strong></td>
                            <td class="label">Mobile:</td><td class="value">${s.mobile}</td>
                        </tr>
                    </table>
                </div>
                <div class="hall-ticket-section">
                    <h3>EXAMINATION DETAILS</h3>
                    <table class="hall-ticket-table">
                        <tr>
                            <td class="label">Exam Date:</td><td class="value"><strong>${examDt}</strong></td>
                            <td class="label">Exam Time:</td><td class="value"><strong>${examTm}</strong></td>
                        </tr>
                        <tr>
                            <td class="label">Exam Hall:</td><td class="value" colspan="3">${s.exam_hall || '—'}</td>
                        </tr>
                        <tr>
                            <td class="label">Center Name:</td><td class="value" colspan="3">${atcDetails.name}</td>
                        </tr>
                        <tr>
                            <td class="label">Address:</td><td class="value" colspan="3">${atcDetails.address || '—'}</td>
                        </tr>
                    </table>
                </div>
                <div class="hall-ticket-section">
                    <h3>IMPORTANT INSTRUCTIONS</h3>
                    <ol class="hall-ticket-instructions">
                        <li>Candidates must bring this hall ticket to the examination center.</li>
                        <li>Candidates must reach 30 minutes before the exam starts.</li>
                        <li>Mobile phones and electronic devices are strictly prohibited.</li>
                        <li>Candidates must carry a valid photo ID proof along with this hall ticket.</li>
                        <li>Use of unfair means will result in cancellation of the examination.</li>
                    </ol>
                </div>
            </div>
            <div class="hall-ticket-footer">
                <div class="hall-ticket-signature">
                    <div class="signature-box"><div class="signature-line"></div><p>Candidate's Signature</p></div>
                    <div class="signature-box"><div class="signature-line"></div><p>Invigilator's Signature</p></div>
                    <div class="signature-box"><div class="signature-line"></div><p>Center Superintendent</p></div>
                </div>
                <div class="hall-ticket-note">
                    <p><strong>Note:</strong> Computer-generated, does not require stamp.</p>
                    <p>Contact: ${atcDetails.mobile || 'N/A'} | ${atcDetails.email || 'N/A'}</p>
                </div>
            </div>
        </div>`;
    document.getElementById('hallTicketModal').classList.add('active');
}

function closeHtModal() {
    document.getElementById('hallTicketModal').classList.remove('active');
}

function htOverlayClick(e) {
    if (e.target === document.getElementById('hallTicketModal')) closeHtModal();
}

// Download: open in new tab → Ctrl+P → Save as PDF
function downloadHallTicket() {
    const content = document.getElementById('htContent').innerHTML;
    openHallTicketWindow(content, true);
}

function openHallTicketWindow(content, autoDownload = false) {
    const pw = window.open('', '_blank', 'height=860,width=1050');
    pw.document.write('<html><head><title>Hall Ticket – Gyanam India</title><style>');
    pw.document.write(`
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:Arial,sans-serif;padding:20px;background:#f5f5f5}
        .hall-ticket{max-width:210mm;margin:0 auto;background:#fff;padding:20px;border:3px solid #6366f1}
        .hall-ticket-header{display:flex;align-items:center;justify-content:space-between;padding-bottom:18px;border-bottom:3px solid #6366f1;margin-bottom:18px}
        .hall-ticket-logo img{width:80px;height:80px;object-fit:contain}
        .hall-ticket-title{flex:1;text-align:center;padding:0 16px}
        .hall-ticket-title h1{font-size:22px;color:#6366f1;margin-bottom:4px}
        .hall-ticket-title h2{font-size:16px;color:#8b5cf6;margin-bottom:4px}
        .hall-ticket-center{font-size:13px;color:#666}
        .hall-ticket-photo img{width:100px;height:120px;object-fit:cover;border:2px solid #6366f1}
        .hall-ticket-section{margin-bottom:16px}
        .hall-ticket-section h3{background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;padding:7px 14px;font-size:13px;margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px}
        .hall-ticket-table{width:100%;border-collapse:collapse}
        .hall-ticket-table td{padding:7px;border:1px solid #ddd;font-size:12px}
        td.label{background:#f9fafb;font-weight:600;width:24%;color:#374151}
        td.value{width:26%}
        .hall-ticket-instructions{padding-left:18px;font-size:11px;line-height:1.8}
        .hall-ticket-footer{border-top:2px solid #6366f1;padding-top:16px;margin-top:16px}
        .hall-ticket-signature{display:flex;justify-content:space-between;margin-bottom:16px}
        .signature-box{text-align:center;flex:1}
        .signature-line{width:140px;height:48px;border-bottom:2px solid #333;margin:0 auto 4px}
        .signature-box p{font-size:11px;font-weight:600}
        .hall-ticket-note{text-align:center;font-size:10px;color:#666;line-height:1.6}
        .download-hint{text-align:center;padding:12px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;font-size:12px;color:#92400e;margin-bottom:16px;font-weight:600}
        @media print{body{padding:0;background:#fff}.hall-ticket{border:3px solid #000}.download-hint{display:none}}
    `);
    pw.document.write('</style></head><body>');
    if (autoDownload) {
        pw.document.write('<div class="download-hint">📥 Press <strong>Ctrl + P</strong> (or ⌘P on Mac) → Select <strong>"Save as PDF"</strong> as the printer to download.</div>');
    }
    pw.document.write(content);
    pw.document.write('</body></html>');
    pw.document.close();
    if (!autoDownload) pw.print();
}

// ── Attendance Sheet ────────────────────────────────────────────────────────
function generateAttendanceSheet() {
    if (scheduledData.length === 0) {
        showToast('No students scheduled for this date.', 'error');
        return;
    }
    const examDateFormatted = <?= json_encode($displayDate) ?>;
    const rows = scheduledData.map((s, i) => {
        const fullName = `${s.first_name} ${s.middle_name ? s.middle_name + ' ' : ''}${s.last_name}`;
        const photo    = s.photo ? `../${s.photo}` : '';
        const regId    = s.registration_id || s.roll_no;
        return `
            <tr>
                <td style="text-align:center;width:40px">${i + 1}</td>
                <td><strong>${fullName.toUpperCase()}</strong></td>
                <td>${s.course}</td>
                <td style="font-family:monospace;font-size:12px">${regId}</td>
                <td style="width:90px;text-align:center">
                    ${photo ? `<img src="${photo}" style="width:70px;height:85px;object-fit:cover;border:1px solid #ddd">` : '<div style="width:70px;height:85px;border:1px dashed #ccc;margin:0 auto;display:flex;align-items:center;justify-content:center;font-size:10px;color:#999">No Photo</div>'}
                </td>
                <td style="width:130px">&nbsp;</td>
            </tr>`;
    }).join('');

    const html = `
        <html><head><title>Attendance — ${examDateFormatted}</title><style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:Arial,sans-serif;padding:24px;background:#fff}
        .att-header{text-align:center;border-bottom:3px double #333;padding-bottom:14px;margin-bottom:20px}
        .att-header h1{font-size:20px;color:#1f2937;letter-spacing:1px}
        .att-header h2{font-size:14px;color:#4b5563;margin-top:4px}
        .att-meta{display:flex;justify-content:space-between;font-size:12px;color:#374151;margin-bottom:16px}
        table{width:100%;border-collapse:collapse;font-size:12px}
        th{background:#1f2937;color:#fff;padding:8px 10px;text-align:left;text-transform:uppercase;letter-spacing:.5px;font-size:11px}
        td{border:1px solid #d1d5db;padding:8px 10px;vertical-align:middle}
        tr:nth-child(even) td{background:#f9fafb}
        .att-footer{margin-top:28px;display:flex;justify-content:space-between}
        .sign-area{text-align:center}
        .sign-line{width:160px;height:50px;border-bottom:2px solid #333;margin:0 auto 4px}
        .sign-label{font-size:11px;font-weight:600;color:#374151}
        @media print{body{padding:0}}
        </style></head>
        <body>
        <div class="att-header">
            <h1>GYANAM INDIA EDUCATIONAL SERVICES</h1>
            <h2>EXAMINATION ATTENDANCE SHEET</h2>
        </div>
        <div class="att-meta">
            <span><strong>Center:</strong> ${atcDetails.name}</span>
            <span><strong>Exam Date:</strong> ${examDateFormatted}</span>
            <span><strong>Total Students:</strong> ${scheduledData.length}</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Sr. No.</th>
                    <th>Student Name</th>
                    <th>Course</th>
                    <th>Registration ID</th>
                    <th>Student Photo</th>
                    <th>Signature</th>
                </tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>
        <div class="att-footer">
            <div class="sign-area"><div class="sign-line"></div><div class="sign-label">Invigilator's Signature</div></div>
            <div class="sign-area"><div class="sign-line"></div><div class="sign-label">Center Superintendent</div></div>
            <div class="sign-area"><div class="sign-line"></div><div class="sign-label">Head Office Stamp</div></div>
        </div>
        </body></html>`;

    const pw = window.open('', '', 'height=900,width=1100');
    pw.document.write(html);
    pw.document.close();
    pw.print();
}

// ── Toast ───────────────────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const t  = document.createElement('div');
    const bg = type === 'success' ? '#059669' : '#dc2626';
    t.style.cssText = `background:${bg};color:#fff;padding:.75rem 1.25rem;border-radius:10px;font-size:.875rem;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.18);max-width:320px`;
    t.textContent = msg;
    document.getElementById('toastContainer').appendChild(t);
    setTimeout(() => t.remove(), 3500);
}
</script>

<style>
:root { --font:'Sora',sans-serif; --mono:'JetBrains Mono',monospace; --brand:#4361ee; --purple:#8b5cf6; --emerald:#10b981; --amber:#f59e0b; }
body { font-family: var(--font); }

/* Container */
.cht-container { padding:1.75rem 2rem; max-width:1400px; margin:0 auto; }

/* Toolbar */
.cht-toolbar { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap; }
.cht-date-form { display:flex; align-items:center; gap:.75rem; }
.cht-date-label { display:flex; align-items:center; gap:.4rem; font-size:.85rem; font-weight:700; color:#374151; }
.cht-date-label svg { width:16px; height:16px; stroke:#6b7280; }
.cht-date-input { height:42px; padding:0 .875rem; border-radius:10px; border:1.5px solid #e5e7eb; font-size:.875rem; font-family:var(--font); font-weight:500; color:#1f2937; cursor:pointer; transition:border-color .2s; }
.cht-date-input:focus { outline:none; border-color:var(--brand); box-shadow:0 0 0 4px rgba(67,97,238,.1); }
.cht-toolbar-actions { display:flex; gap:.75rem; }
.cht-btn-schedule { display:inline-flex; align-items:center; gap:.5rem; padding:0 1.25rem; height:42px; border-radius:10px; font-size:.875rem; font-weight:700; color:#fff; background:linear-gradient(135deg,var(--brand),#3730a3); border:none; cursor:pointer; box-shadow:0 2px 8px rgba(67,97,238,.25); transition:all .2s; }
.cht-btn-schedule:hover { transform:translateY(-2px); box-shadow:0 4px 16px rgba(67,97,238,.35); }
.cht-btn-schedule svg { width:16px; height:16px; }
.cht-btn-attendance { display:inline-flex; align-items:center; gap:.5rem; padding:0 1.25rem; height:42px; border-radius:10px; font-size:.875rem; font-weight:700; color:#fff; background:linear-gradient(135deg,var(--emerald),#059669); border:none; cursor:pointer; box-shadow:0 2px 8px rgba(16,185,129,.25); transition:all .2s; }
.cht-btn-attendance:hover { transform:translateY(-2px); box-shadow:0 4px 16px rgba(16,185,129,.35); }
.cht-btn-attendance svg { width:16px; height:16px; }

/* KPI */
.cht-kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:1.25rem; margin-bottom:1.75rem; }
@media(max-width:1100px){ .cht-kpi-grid { grid-template-columns:repeat(2,1fr); } }
@media(max-width:600px){ .cht-kpi-grid { grid-template-columns:1fr; } }
.cht-kpi-card { background:#fff; border-radius:12px; padding:1.25rem 1.5rem; display:flex; flex-direction:column; align-items:flex-start; gap:1.25rem; box-shadow:0 1px 3px rgba(0,0,0,.05); transition:all .2s ease; border:1px solid #e5e7eb; border-left-width:4px; }
.cht-kpi-card:hover { transform:translateY(-2px); box-shadow:0 6px 16px rgba(0,0,0,.08); }
.cht-kpi-blue  { border-left-color:var(--brand); }
.cht-kpi-green { border-left-color:var(--emerald); }
.cht-kpi-purple{ border-left-color:var(--purple); }
.cht-kpi-amber { border-left-color:var(--amber); }

.cht-kpi-icon  { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; }
.cht-kpi-blue .cht-kpi-icon  { background:#eef1fd; color:var(--brand); }
.cht-kpi-green .cht-kpi-icon { background:#d1fae5; color:var(--emerald); }
.cht-kpi-purple .cht-kpi-icon{ background:#ede9fe; color:var(--purple); }
.cht-kpi-amber .cht-kpi-icon { background:#fef3c7; color:var(--amber); }

.cht-kpi-icon svg { width:20px; height:20px; stroke:currentColor; fill:none; }

.cht-kpi-content { display:flex; flex-direction:column; gap:.35rem; }
.cht-kpi-label { font-size:.65rem; font-weight:800; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; order:-1; margin-top:0; }
.cht-kpi-value { font-size:1.6rem; font-weight:800; font-family:var(--font); line-height:1; color:#111827; }

/* Table card */
.cht-table-card { background:#fff; border:1.5px solid #e5e7eb; border-radius:18px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.cht-table-header { padding:1.25rem 1.5rem; border-bottom:1px solid #f3f4f6; }
.cht-table-title { display:flex; align-items:center; gap:.625rem; font-size:1.1rem; font-weight:800; color:#1f2937; margin:0; }
.cht-badge { padding:.2rem .7rem; border-radius:999px; font-size:.78rem; font-weight:800; background:#e5e7eb; color:#6b7280; }
.cht-table-wrapper { overflow-x:auto; padding:1rem 1.5rem 1.5rem; }

/* DataTables overrides */
div.dataTables_wrapper div.dataTables_filter input { border:1.5px solid #e5e7eb; border-radius:8px; padding:.35rem .75rem; font-family:var(--font); font-size:.85rem; }
div.dataTables_wrapper div.dataTables_filter input:focus { outline:none; border-color:var(--brand); }
.cht-export-btn { display:inline-flex; align-items:center; gap:.35rem; padding:.5rem 1rem; height:38px; border-radius:6px; font-size:.8rem; font-weight:700; color:#fff; border:none; cursor:pointer; margin-right:.4rem; margin-bottom:.4rem; transition:background .2s; }
.dt-buttons { margin-bottom:1rem; }
div.dt-buttons { display:flex; flex-wrap:wrap; gap:.35rem; }
.buttons-copy   { background:#4b5563!important; }
.buttons-copy:hover { background:#374151!important; }
.buttons-excel  { background:#059669!important; }
.buttons-excel:hover { background:#047857!important; }
.buttons-csv    { background:#0ea5e9!important; }
.buttons-csv:hover { background:#0284c7!important; }
.buttons-pdf    { background:#dc2626!important; }
.buttons-pdf:hover { background:#b91c1c!important; }
.buttons-print  { background:#8b5cf6!important; }
.buttons-print:hover { background:#7c3aed!important; }
.cht-roll { font-family:var(--mono); font-size:.8rem; font-weight:700; background:#eef1fd; color:var(--brand); padding:.2rem .6rem; border-radius:6px; }
.cht-reg  { font-family:var(--mono); font-size:.78rem; color:#374151; }
.cht-btn-view { display:inline-flex; align-items:center; gap:.4rem; padding:.4rem .875rem; border-radius:8px; font-size:.8rem; font-weight:700; color:#fff; background:linear-gradient(135deg,var(--brand),#3730a3); border:none; cursor:pointer; }
.cht-btn-view:hover { transform:translateY(-1px); box-shadow:0 3px 10px rgba(67,97,238,.3); }
.cht-btn-view svg { width:14px; height:14px; }

/* Modals */
.cht-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9999; align-items:center; justify-content:center; padding:1rem; }
.cht-modal-overlay.active { display:flex; }
.cht-modal-card { background:#fff; border-radius:20px; width:100%; max-width:560px; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,.25); }
.cht-modal-header { display:flex; align-items:center; justify-content:space-between; padding:1.25rem 1.5rem; border-bottom:1px solid #f3f4f6; }
.cht-modal-header h3 { font-size:1.1rem; font-weight:800; color:#1f2937; margin:0; }
.cht-modal-close { background:none; border:none; cursor:pointer; padding:.25rem; display:flex; align-items:center; justify-content:center; border-radius:8px; }
.cht-modal-close:hover { background:#f3f4f6; }
.cht-modal-close svg { width:20px; height:20px; stroke:#6b7280; }
.cht-modal-footer { display:flex; justify-content:flex-end; gap:.75rem; padding:1rem 1.5rem; border-top:1px solid #f3f4f6; }
.cht-btn-cancel2 { padding:.6rem 1.4rem; border-radius:10px; border:1.5px solid #e5e7eb; background:#fff; font-size:.875rem; font-weight:700; color:#374151; cursor:pointer; }
.cht-btn-save { display:inline-flex; align-items:center; gap:.4rem; padding:.6rem 1.4rem; border-radius:10px; border:none; background:linear-gradient(135deg,var(--brand),#3730a3); color:#fff; font-size:.875rem; font-weight:700; cursor:pointer; }
.cht-btn-save svg { width:15px; height:15px; }

/* Form inside modal */
.cht-form-group { margin-bottom:1.1rem; }
.cht-form-group label { display:block; font-size:.82rem; font-weight:700; color:#374151; margin-bottom:.4rem; }
.cht-form-row { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
.cht-select-lg, .cht-input { width:100%; height:42px; padding:0 .875rem; border:1.5px solid #e5e7eb; border-radius:10px; font-family:var(--font); font-size:.875rem; color:#1f2937; transition:border-color .2s; }
.cht-select-lg:focus, .cht-input:focus { outline:none; border-color:var(--brand); box-shadow:0 0 0 4px rgba(67,97,238,.1); }

/* Hall Ticket inside modal */
.hall-ticket { border:3px solid #6366f1; padding:20px; font-family:Arial,sans-serif; }
.hall-ticket-header { display:flex; align-items:center; justify-content:space-between; padding-bottom:16px; border-bottom:3px solid #6366f1; margin-bottom:16px; }
.hall-ticket-logo img { width:70px; height:70px; object-fit:contain; }
.hall-ticket-title { flex:1; text-align:center; padding:0 12px; }
.hall-ticket-title h1 { font-size:17px; color:#6366f1; }
.hall-ticket-title h2 { font-size:13px; color:#8b5cf6; }
.hall-ticket-center { font-size:12px; color:#666; }
.hall-ticket-photo img { width:90px; height:110px; object-fit:cover; border:2px solid #6366f1; }
.hall-ticket-section { margin-bottom:14px; }
.hall-ticket-section h3 { background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; padding:6px 12px; font-size:11px; margin-bottom:8px; text-transform:uppercase; letter-spacing:.5px; }
.hall-ticket-table { width:100%; border-collapse:collapse; }
.hall-ticket-table td { padding:6px 8px; border:1px solid #e5e7eb; font-size:12px; }
.hall-ticket-table td.label { background:#f9fafb; font-weight:600; width:24%; color:#374151; }
.hall-ticket-instructions { padding-left:16px; font-size:11px; line-height:1.8; }
.hall-ticket-footer { border-top:2px solid #6366f1; padding-top:14px; margin-top:14px; }
.hall-ticket-signature { display:flex; justify-content:space-between; margin-bottom:14px; }
.signature-box { text-align:center; flex:1; }
.signature-line { width:120px; height:45px; border-bottom:2px solid #333; margin:0 auto 4px; }
.signature-box p { font-size:11px; font-weight:600; }
.hall-ticket-note { text-align:center; font-size:10px; color:#666; line-height:1.6; }
</style>
</body>
</html>
