<?php
/**
 * ATC — Certificate Distribution
 * Students appear once Head Office has dispatched their certificate.
 * ATC can WhatsApp them to collect it, then record who collected it and when.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin(['ATC CENTER']);

$pdo   = getDBConnection();
$atcId = (int)($_SESSION['atc_id'] ?? 0);
if ($atcId <= 0) {
    die('ATC ID not found. Please log in again.');
}

ensureDispatchTables($pdo);

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS certificate_distributions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            atc_id INT NOT NULL,
            admission_id INT NOT NULL,
            dispatch_item_id INT NOT NULL,
            course_name VARCHAR(255) NOT NULL,
            student_name VARCHAR(255) NOT NULL,
            date_issued DATE NOT NULL,
            received_by VARCHAR(255) DEFAULT NULL,
            narration TEXT,
            collected TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_cd_atc_item (atc_id, dispatch_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log('[certificate_distributions] ' . $e->getMessage());
}

$atcName = trim((string)($_SESSION['atc_sidebar_name'] ?? ''));
if ($atcName === '') {
    try {
        $st = $pdo->prepare('SELECT name FROM atc_centers WHERE id = ? LIMIT 1');
        $st->execute([$atcId]);
        $atcName = trim((string)$st->fetchColumn());
    } catch (Exception $e) {}
}
if ($atcName === '') {
    $atcName = 'your ATC centre';
}

function cdOwnsCertificate(PDO $pdo, int $atcId, int $itemId): ?array
{
    $st = $pdo->prepare("
        SELECT di.id AS dispatch_item_id, di.item_detail, a.id AS admission_id, a.course, a.mobile,
               TRIM(CONCAT(a.first_name,' ',COALESCE(a.middle_name,''),' ',a.last_name)) AS student_name
        FROM dispatch_items di
        INNER JOIN material_dispatches md ON md.id = di.dispatch_id
        INNER JOIN admissions a ON a.id = di.admission_id
        WHERE di.id = ? AND di.item_type = 'Certificate' AND di.status = 'Dispatched'
          AND md.atc_id = ? AND a.atc_id = ?
        LIMIT 1
    ");
    $st->execute([$itemId, $atcId, $atcId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = (string)$_POST['action'];
    $itemId = (int)($_POST['dispatch_item_id'] ?? 0);
    $owned = $itemId > 0 ? cdOwnsCertificate($pdo, $atcId, $itemId) : null;
    if (!$owned) {
        echo json_encode(['success' => false, 'message' => 'Certificate dispatch not found for this centre.']);
        exit;
    }

    if ($action === 'set_remark') {
        $collected = ((string)($_POST['collected'] ?? '0')) === '1' ? 1 : 0;
        try {
            $latest = $pdo->prepare('SELECT id FROM certificate_distributions WHERE atc_id = ? AND dispatch_item_id = ? ORDER BY id DESC LIMIT 1');
            $latest->execute([$atcId, $itemId]);
            $existingId = (int)$latest->fetchColumn();
            if ($existingId > 0) {
                $pdo->prepare('UPDATE certificate_distributions SET collected = ? WHERE id = ? AND atc_id = ?')
                    ->execute([$collected, $existingId, $atcId]);
            } elseif ($collected === 1) {
                $pdo->prepare("
                    INSERT INTO certificate_distributions
                        (atc_id, admission_id, dispatch_item_id, course_name, student_name, date_issued, collected)
                    VALUES (?, ?, ?, ?, ?, CURDATE(), 1)
                ")->execute([
                    $atcId,
                    (int)$owned['admission_id'],
                    $itemId,
                    (string)$owned['course'],
                    (string)$owned['student_name'],
                ]);
            }
            echo json_encode(['success' => true, 'collected' => $collected]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Could not save the remark.']);
        }
        exit;
    }

    if ($action === 'insert_record') {
        $course = trim((string)($_POST['course_name'] ?? ''));
        $date   = trim((string)($_POST['date_issued'] ?? ''));
        $by     = trim((string)($_POST['received_by'] ?? ''));
        $note   = trim((string)($_POST['narration'] ?? ''));
        if ($course === '') {
            $course = (string)$owned['course'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            echo json_encode(['success' => false, 'message' => 'Choose the date the certificate was issued to the receiver.']);
            exit;
        }
        if ($by === '') {
            echo json_encode(['success' => false, 'message' => "Enter the receiver's name."]);
            exit;
        }
        try {
            $pdo->prepare("
                INSERT INTO certificate_distributions
                    (atc_id, admission_id, dispatch_item_id, course_name, student_name, date_issued, received_by, narration, collected)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ")->execute([
                $atcId,
                (int)$owned['admission_id'],
                $itemId,
                $course,
                (string)$owned['student_name'],
                $date,
                $by,
                $note !== '' ? $note : null,
            ]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Could not save the collection record.']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

$rows = [];
try {
    $st = $pdo->prepare("
        SELECT di.id AS dispatch_item_id, di.item_detail,
               md.dispatch_id, md.dispatch_date, md.status AS shipment_status,
               a.id AS admission_id, a.roll_no, a.mobile, a.course,
               TRIM(CONCAT(a.first_name,' ',COALESCE(a.middle_name,''),' ',a.last_name)) AS student_name
        FROM dispatch_items di
        INNER JOIN material_dispatches md ON md.id = di.dispatch_id
        INNER JOIN admissions a ON a.id = di.admission_id
        WHERE md.atc_id = ? AND a.atc_id = ?
          AND di.item_type = 'Certificate' AND di.status = 'Dispatched'
        ORDER BY md.dispatch_date DESC, di.id DESC
    ");
    $st->execute([$atcId, $atcId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $rows = [];
}

$latestByItem = [];
$records = [];
try {
    $st = $pdo->prepare('SELECT * FROM certificate_distributions WHERE atc_id = ? ORDER BY id DESC');
    $st->execute([$atcId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $rec) {
        $records[] = $rec;
        $key = (int)$rec['dispatch_item_id'];
        if (!isset($latestByItem[$key])) {
            $latestByItem[$key] = $rec;
        }
    }
} catch (Exception $e) {}

$readyCount = 0;
$collectedCount = 0;
foreach ($rows as &$row) {
    $latest = $latestByItem[(int)$row['dispatch_item_id']] ?? null;
    $row['collected'] = $latest && (int)$latest['collected'] === 1;
    $row['collected_on'] = $row['collected'] ? (string)($latest['date_issued'] ?? '') : '';
    $row['received_by'] = $latest['received_by'] ?? '';
    if ($row['collected']) {
        $collectedCount++;
    } else {
        $readyCount++;
    }
}
unset($row);

$courseOptions = [];
foreach ($rows as $row) {
    $name = trim((string)$row['course']);
    if ($name !== '') {
        $courseOptions[$name] = true;
    }
}
$courseOptions = array_keys($courseOptions);
sort($courseOptions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate Distribution — ATC | Gyanam India</title>
    <?php include __DIR__ . '/../includes/head_fonts.php'; ?>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%20viewBox%3D%270%200%2024%2024%27%20fill%3D%27none%27%20stroke%3D%27%234f46e5%27%20stroke-width%3D%272%27%20stroke-linecap%3D%27round%27%20stroke-linejoin%3D%27round%27%3E%3Ccircle%20cx%3D%2712%27%20cy%3D%278%27%20r%3D%276%27%2F%3E%3Cpath%20d%3D%27M15.5%2013.5%2017%2022l-5-3-5%203%201.5-8.5%27%2F%3E%3C%2Fsvg%3E">
    <style>
        .cd-note { background:#eff6ff; border:1px solid #bfdbfe; color:#1e3a8a; border-radius:12px; padding:.85rem 1rem; font-size:.84rem; font-weight:600; margin-bottom:1rem; line-height:1.45 }
        .cd-kpi { display:grid; grid-template-columns:repeat(3,1fr); gap:.75rem; margin-bottom:1rem }
        .cd-kpi div { background:#fff; border:1px solid #e6eaf3; border-radius:14px; padding:.9rem 1rem }
        .cd-kpi strong { display:block; font-size:1.45rem; font-weight:800; color:#111827 }
        .cd-kpi span { font-size:.72rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:#64748b }
        .cd-card { background:#fff; border:1px solid #e6eaf3; border-radius:14px; overflow:hidden; margin-bottom:1rem }
        .cd-head { display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:.85rem 1rem; border-bottom:1px solid #eef1fd; flex-wrap:wrap }
        .cd-head h3 { margin:0; font-size:.95rem; font-weight:800 }
        .cd-tools { display:flex; gap:.5rem; flex-wrap:wrap }
        .cd-tools select, .cd-tools input { height:36px; border:1.5px solid #e2e8f0; border-radius:8px; padding:0 .7rem; font-weight:600; font-family:inherit }
        .cd-table { width:100%; border-collapse:collapse; font-size:.82rem }
        .cd-table th { text-align:left; font-size:.68rem; letter-spacing:.03em; text-transform:uppercase; color:#64748b; padding:.65rem .7rem; background:#f8fafc; white-space:nowrap }
        .cd-table td { padding:.65rem .7rem; border-top:1px solid #f1f5f9; vertical-align:middle }
        .cd-name { font-weight:800; color:#111827 }
        .cd-sub { font-size:.72rem; color:#64748b; font-weight:600 }
        .cd-badge { display:inline-block; padding:.15rem .5rem; border-radius:999px; font-size:.68rem; font-weight:800 }
        .cd-wait { background:#fef3c7; color:#92400e }
        .cd-ok { background:#d1fae5; color:#065f46 }
        .cd-wa, .cd-rec { height:32px; padding:0 .7rem; border:none; border-radius:8px; font-weight:800; cursor:pointer; font-family:inherit; font-size:.75rem }
        .cd-wa { background:#059669; color:#fff }
        .cd-wa:disabled { background:#cbd5e1; cursor:not-allowed }
        .cd-rec { background:#4f46e5; color:#fff }
        .cd-remark { height:32px; border:1.5px solid #e2e8f0; border-radius:8px; font-weight:700; font-family:inherit; padding:0 .4rem }
        .cd-overlay { position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:80; display:none; align-items:center; justify-content:center; padding:1rem }
        .cd-overlay.open { display:flex }
        .cd-modal { background:#fff; width:min(720px, 100%); border-radius:16px; padding:1.15rem 1.2rem 1.2rem; box-shadow:0 20px 50px rgba(15,23,42,.2) }
        .cd-modal h3 { margin:0 0 1rem; font-size:1.05rem; font-weight:800; color:#2563eb }
        .cd-grid { display:grid; grid-template-columns:1fr 1fr; gap:.9rem 1.25rem }
        .cd-field label { display:block; font-size:.78rem; font-weight:800; margin-bottom:.35rem; color:#111827 }
        .cd-field input, .cd-field select, .cd-field textarea { width:100%; box-sizing:border-box; border:1.5px solid #d1d5db; border-radius:8px; padding:.55rem .7rem; font-family:inherit; font-weight:600; font-size:.9rem }
        .cd-field textarea { min-height:88px; resize:vertical }
        .cd-student { color:#dc2626; font-weight:800; font-size:1rem; padding-top:.35rem }
        .cd-insert { margin-top:1rem; width:100%; height:42px; border:none; border-radius:8px; background:#2196f3; color:#fff; font-weight:800; font-size:.95rem; cursor:pointer; font-family:inherit }
        .cd-close { float:right; border:none; background:transparent; font-size:1.3rem; cursor:pointer; line-height:1; color:#64748b }
        .cd-err { color:#b91c1c; font-size:.8rem; font-weight:700; min-height:1.1rem; margin-top:.45rem }
        @media (max-width:800px) { .cd-kpi, .cd-grid { grid-template-columns:1fr } }
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
                    <h2>Certificate Distribution</h2>
                    <p>Hand over dispatched certificates and record who collected them</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>
        <div class="page-content">
            <div class="cd-kpi">
                <div><strong><?= count($rows) ?></strong><span>Dispatched certificates</span></div>
                <div><strong><?= $readyCount ?></strong><span>Not collected</span></div>
                <div><strong><?= $collectedCount ?></strong><span>Collected</span></div>
            </div>
            <div class="cd-note">
                A student appears here after Head Office dispatches their certificate to <strong><?= htmlspecialchars($atcName) ?></strong>.
                WhatsApp tells them it is ready to collect. After you record the handover, the message says it was collected on that date.
            </div>

            <div class="cd-card">
                <div class="cd-head">
                    <h3>Students</h3>
                    <div class="cd-tools">
                        <select id="cdStatus">
                            <option value="all">All</option>
                            <option value="0">Not collected</option>
                            <option value="1">Collected</option>
                        </select>
                        <input type="search" id="cdSearch" placeholder="Search name, roll, course" autocomplete="off">
                    </div>
                </div>
                <?php if ($rows === []): ?>
                    <div style="padding:2rem 1rem;color:#64748b;font-weight:600">No certificate dispatches for this centre yet.</div>
                <?php else: ?>
                <div style="overflow-x:auto" id="cdScroll">
                    <table class="cd-table" id="cdTable">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Course</th>
                                <th>Dispatch</th>
                                <th>Remark</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row):
                            $collected = !empty($row['collected']);
                            $collectedOn = $collected && $row['collected_on'] !== '' ? date('d M Y', strtotime($row['collected_on'])) : '';
                            $isDup = stripos((string)$row['item_detail'], 'Duplicate #') === 0;
                            $search = mb_strtolower($row['student_name'] . ' ' . $row['roll_no'] . ' ' . $row['course']);
                        ?>
                            <tr data-search="<?= htmlspecialchars($search) ?>" data-collected="<?= $collected ? '1' : '0' ?>" data-item="<?= (int)$row['dispatch_item_id'] ?>">
                                <td>
                                    <div class="cd-name"><?= htmlspecialchars($row['student_name']) ?></div>
                                    <div class="cd-sub"><?= htmlspecialchars($row['roll_no'] ?: ('#' . $row['admission_id'])) ?><?= $row['mobile'] ? ' · ' . htmlspecialchars($row['mobile']) : '' ?></div>
                                </td>
                                <td>
                                    <?= htmlspecialchars($row['course']) ?>
                                    <?php if ($isDup): ?><div class="cd-sub">Duplicate certificate</div><?php endif; ?>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars($row['dispatch_id'] ?: ('#' . $row['dispatch_item_id'])) ?></div>
                                    <div class="cd-sub"><?= !empty($row['dispatch_date']) ? date('d M Y', strtotime($row['dispatch_date'])) : '—' ?> · <?= htmlspecialchars($row['shipment_status'] ?: 'Dispatched') ?></div>
                                </td>
                                <td>
                                    <select class="cd-remark" data-item="<?= (int)$row['dispatch_item_id'] ?>" aria-label="Collected or not">
                                        <option value="0" <?= !$collected ? 'selected' : '' ?>>Not collected</option>
                                        <option value="1" <?= $collected ? 'selected' : '' ?>>Collected</option>
                                    </select>
                                    <?php if ($collectedOn !== ''): ?>
                                        <div class="cd-sub">on <?= htmlspecialchars($collectedOn) ?><?= $row['received_by'] ? ' · ' . htmlspecialchars($row['received_by']) : '' ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="cd-wa" <?= $row['mobile'] ? '' : 'disabled' ?>
                                        data-name="<?= htmlspecialchars($row['student_name'], ENT_QUOTES) ?>"
                                        data-mobile="<?= htmlspecialchars((string)$row['mobile'], ENT_QUOTES) ?>"
                                        data-collected="<?= $collected ? '1' : '0' ?>"
                                        data-date="<?= htmlspecialchars($collectedOn, ENT_QUOTES) ?>">WhatsApp</button>
                                    <button type="button" class="cd-rec"
                                        data-item="<?= (int)$row['dispatch_item_id'] ?>"
                                        data-name="<?= htmlspecialchars($row['student_name'], ENT_QUOTES) ?>"
                                        data-course="<?= htmlspecialchars($row['course'], ENT_QUOTES) ?>">Record</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <div class="cd-card" id="cdRecordsCard">
                <div class="cd-head">
                    <h3>Show Records</h3>
                </div>
                <?php if ($records === []): ?>
                    <div style="padding:1.4rem 1rem;color:#64748b;font-weight:600">No collection records yet.</div>
                <?php else: ?>
                <div style="overflow-x:auto" id="cdRecScroll">
                    <table class="cd-table" id="cdRecTable">
                        <thead>
                            <tr>
                                <th>Date issued</th>
                                <th>Student</th>
                                <th>Course</th>
                                <th>Received by</th>
                                <th>Narration</th>
                                <th>Remark</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($records as $rec): ?>
                            <tr>
                                <td><?= !empty($rec['date_issued']) ? date('d/m/Y', strtotime($rec['date_issued'])) : '—' ?></td>
                                <td class="cd-name"><?= htmlspecialchars($rec['student_name']) ?></td>
                                <td><?= htmlspecialchars($rec['course_name']) ?></td>
                                <td><?= htmlspecialchars($rec['received_by'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($rec['narration'] ?: '—') ?></td>
                                <td><span class="cd-badge <?= (int)$rec['collected'] === 1 ? 'cd-ok' : 'cd-wait' ?>"><?= (int)$rec['collected'] === 1 ? 'Collected' : 'Not collected' ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<div class="cd-overlay" id="cdOverlay">
    <form class="cd-modal" id="cdForm">
        <button type="button" class="cd-close" id="cdClose" aria-label="Close">&times;</button>
        <h3>Certificate collection</h3>
        <input type="hidden" name="dispatch_item_id" id="cdItem">
        <div class="cd-grid">
            <div class="cd-field">
                <label for="cdCourse">Course Name:</label>
                <select name="course_name" id="cdCourse" required>
                    <?php foreach ($courseOptions as $courseName): ?>
                        <option value="<?= htmlspecialchars($courseName) ?>"><?= htmlspecialchars($courseName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cd-field">
                <label>Student Name:</label>
                <div class="cd-student" id="cdStudent">—</div>
            </div>
            <div class="cd-field">
                <label for="cdDate">Date Issued:</label>
                <input type="date" name="date_issued" id="cdDate" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="cd-field">
                <label for="cdBy">Received By:</label>
                <input type="text" name="received_by" id="cdBy" placeholder="Enter Receiver's Name" required>
            </div>
            <div class="cd-field" style="grid-column:1 / -1">
                <label for="cdNote">Narration:</label>
                <textarea name="narration" id="cdNote" placeholder="Enter Narration"></textarea>
            </div>
        </div>
        <div class="cd-err" id="cdErr"></div>
        <button type="submit" class="cd-insert">Insert</button>
    </form>
</div>

<script src="../assets/js/dashboard.js"></script>
<script src="../assets/js/list-pager.js"></script>
<script>
const cdCentre = <?= json_encode($atcName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function cdPhone(mobile) {
    let num = String(mobile || '').replace(/\D/g, '');
    if (num.length === 11 && num.charAt(0) === '0') num = num.slice(1);
    if (num.length === 10) num = '91' + num;
    return num;
}

function cdMessage(name, collected, dateText) {
    if (collected) {
        return 'Dear ' + name + ',\n\nYour certificate has been collected on ' + (dateText || 'the recorded date') + '.\n\nWith regards,\n' + cdCentre + '\nGyanam India';
    }
    return 'Dear ' + name + ',\n\nYour certificate is ready. Please come to ' + cdCentre + ' and collect it.\n\nWith regards,\n' + cdCentre + '\nGyanam India';
}

document.querySelectorAll('.cd-wa').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const num = cdPhone(btn.dataset.mobile);
        if (!num) return;
        const msg = cdMessage(btn.dataset.name || 'Student', btn.dataset.collected === '1', btn.dataset.date || '');
        window.open('https://wa.me/' + num + '?text=' + encodeURIComponent(msg), '_blank');
    });
});

document.querySelectorAll('.cd-remark').forEach(function (sel) {
    sel.addEventListener('change', function () {
        const fd = new FormData();
        fd.append('action', 'set_remark');
        fd.append('dispatch_item_id', sel.dataset.item);
        fd.append('collected', sel.value);
        fetch('certificate_distribution.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) {
                    alert(res.message || 'Could not save');
                    return;
                }
                location.reload();
            })
            .catch(function () { alert('Network error'); });
    });
});

const overlay = document.getElementById('cdOverlay');
document.querySelectorAll('.cd-rec').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('cdItem').value = btn.dataset.item;
        document.getElementById('cdStudent').textContent = btn.dataset.name || '—';
        const course = document.getElementById('cdCourse');
        if (course && btn.dataset.course) {
            let found = false;
            Array.from(course.options).forEach(function (opt) {
                if (opt.value === btn.dataset.course) found = true;
            });
            if (!found) {
                const opt = document.createElement('option');
                opt.value = btn.dataset.course;
                opt.textContent = btn.dataset.course;
                course.appendChild(opt);
            }
            course.value = btn.dataset.course;
        }
        document.getElementById('cdBy').value = '';
        document.getElementById('cdNote').value = '';
        document.getElementById('cdDate').value = new Date().toISOString().slice(0, 10);
        document.getElementById('cdErr').textContent = '';
        overlay.classList.add('open');
    });
});
document.getElementById('cdClose').addEventListener('click', function () { overlay.classList.remove('open'); });
overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.classList.remove('open'); });

document.getElementById('cdForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.append('action', 'insert_record');
    const err = document.getElementById('cdErr');
    err.textContent = '';
    fetch('certificate_distribution.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success) {
                err.textContent = res.message || 'Could not save';
                return;
            }
            location.reload();
        })
        .catch(function () { err.textContent = 'Network error'; });
});

const cdPager = initListPager({
    rows: '#cdTable tbody tr',
    mount: '#cdScroll',
    label: 'students',
    match: function (row) {
        const q = (document.getElementById('cdSearch')?.value || '').trim().toLowerCase();
        const status = document.getElementById('cdStatus')?.value || 'all';
        const textOk = !q || (row.dataset.search || '').indexOf(q) !== -1;
        const statusOk = status === 'all' || row.dataset.collected === status;
        return textOk && statusOk;
    }
});
document.getElementById('cdSearch')?.addEventListener('input', function () { cdPager.refresh(true); });
document.getElementById('cdStatus')?.addEventListener('change', function () { cdPager.refresh(true); });
initListPager({ rows: '#cdRecTable tbody tr', mount: '#cdRecScroll', label: 'records' });
</script>
</body>
</html>
