<?php
/**
 * Gyanam Portal — Admin: Material Requirements
 * Course material needs for share-paid students (all ATCs by default).
 * Tracks pending vs completed (dispatched) batches.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo      = getDBConnection();
$userName = sanitize(getUserName());

ensureCourseMaterialItemsSchema($pdo);
ensureInventoryTables($pdo);

if (!function_exists('examIntegrationReady')) {
    $examFile = __DIR__ . '/../includes/exam_integration.php';
    if (is_file($examFile)) {
        require_once $examFile;
    }
}

// ── Lookups ───────────────────────────────────────────────────────────────────
$dlcList = [];
$atcList = [];
try {
    $dlcList = $pdo->query("SELECT id, name FROM dlc_offices WHERE status='Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
try {
    $atcList = $pdo->query("
        SELECT a.id, a.name, a.atc_code, a.dlc_id, d.name AS dlc_name
        FROM atc_centers a
        LEFT JOIN dlc_offices d ON d.id = a.dlc_id
        WHERE a.status='Active'
        ORDER BY a.name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try {
        $atcList = $pdo->query("SELECT id, name, atc_code, dlc_id, NULL AS dlc_name FROM atc_centers WHERE status='Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {}
}

$atcById = [];
foreach ($atcList as $a) {
    $atcById[(int)$a['id']] = $a;
}

// ── Filters (default: all ATCs) ───────────────────────────────────────────────
$filterDlc     = isset($_GET['dlc_id']) && $_GET['dlc_id'] !== '' ? (int)$_GET['dlc_id'] : 0;
$filterAtc     = isset($_GET['atc_id']) && $_GET['atc_id'] !== '' ? (int)$_GET['atc_id'] : 0;
$filterCourse  = trim((string)($_GET['course'] ?? ''));
$filterMatType = trim((string)($_GET['mat_type'] ?? 'all')); // all | Book | T-Shirt | Certificate
$searchTerm    = trim((string)($_GET['search'] ?? ''));
$tab           = ($_GET['tab'] ?? 'pending') === 'completed' ? 'completed' : 'pending';

if (!in_array($filterMatType, ['all', 'Book', 'T-Shirt', 'Certificate'], true)) {
    $filterMatType = 'all';
}

// If ATC selected but mismatches DLC, clear ATC
if ($filterAtc && $filterDlc && isset($atcById[$filterAtc]) && (int)($atcById[$filterAtc]['dlc_id'] ?? 0) !== $filterDlc) {
    $filterAtc = 0;
}

$pendingStudents   = [];
$completedStudents = [];
$materialSummary   = [];
$courseOptions     = [];

// Scope ATC ids
$scopeAtcIds = [];
foreach ($atcList as $a) {
    $aid = (int)$a['id'];
    if ($filterAtc && $aid !== $filterAtc) continue;
    if ($filterDlc && (int)($a['dlc_id'] ?? 0) !== $filterDlc) continue;
    $scopeAtcIds[] = $aid;
}

if (!empty($scopeAtcIds)) {
    $phAtc = implode(',', array_fill(0, count($scopeAtcIds), '?'));

    // ── Students with material ────────────────────────────────────────────────
    try {
        $stmt = $pdo->prepare("
            SELECT a.id, a.atc_id, a.roll_no, a.registration_id,
                   TRIM(CONCAT(a.first_name,' ',COALESCE(NULLIF(TRIM(a.middle_name),''),''),' ',a.last_name)) AS student_name,
                   a.course, a.uniform_size, a.material_language, a.material_type, a.admission_date,
                   COALESCE(a.ho_share_paid, 0) AS ho_share_paid,
                   atc.name AS atc_name, atc.atc_code
            FROM admissions a
            INNER JOIN atc_centers atc ON atc.id = a.atc_id
            WHERE a.atc_id IN ($phAtc)
              AND a.status = 'Active'
              AND a.material_type = 'With Material'
            ORDER BY atc.name ASC, a.first_name ASC
        ");
        $stmt->execute($scopeAtcIds);
        $allStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $stmt = $pdo->prepare("
            SELECT a.id, a.atc_id, a.roll_no, a.registration_id,
                   TRIM(CONCAT(a.first_name,' ',COALESCE(NULLIF(TRIM(a.middle_name),''),''),' ',a.last_name)) AS student_name,
                   a.course, a.uniform_size, a.material_language, a.material_type, a.admission_date,
                   0 AS ho_share_paid,
                   atc.name AS atc_name, atc.atc_code
            FROM admissions a
            INNER JOIN atc_centers atc ON atc.id = a.atc_id
            WHERE a.atc_id IN ($phAtc)
              AND a.status = 'Active'
              AND a.material_type = 'With Material'
            ORDER BY atc.name ASC, a.first_name ASC
        ");
        $stmt->execute($scopeAtcIds);
        $allStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Course filter options from this result set (before course filter applied)
    $courseOptions = array_values(array_unique(array_filter(array_map(
        static fn($x) => trim((string)($x['course'] ?? '')),
        $allStudents
    ))));
    sort($courseOptions, SORT_NATURAL | SORT_FLAG_CASE);

    if ($filterCourse !== '') {
        $allStudents = array_values(array_filter($allStudents, static function ($s) use ($filterCourse) {
            return trim((string)($s['course'] ?? '')) === $filterCourse;
        }));
    }

    if ($searchTerm !== '') {
        $q = mb_strtolower($searchTerm);
        $allStudents = array_values(array_filter($allStudents, static function ($s) use ($q) {
            $hay = mb_strtolower(trim(
                ($s['student_name'] ?? '') . ' ' .
                ($s['roll_no'] ?? '') . ' ' .
                ($s['registration_id'] ?? '') . ' ' .
                ($s['atc_name'] ?? '') . ' ' .
                ($s['course'] ?? '')
            ));
            return $hay !== '' && mb_strpos($hay, $q) !== false;
        }));
    }

    // ── Share-paid map (scoped ATCs) ──────────────────────────────────────────
    $paidMap = [];
    try {
        $spStmt = $pdo->prepare("SELECT student_ids FROM share_payments WHERE atc_id IN ($phAtc) AND status = 'Completed'");
        $spStmt->execute($scopeAtcIds);
        foreach ($spStmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $ids = json_decode($json, true);
            if (is_array($ids)) {
                foreach ($ids as $sid) {
                    $paidMap[(int)$sid] = true;
                }
            }
        }
    } catch (Exception $e) {}

    // ── Dispatch items ────────────────────────────────────────────────────────
    $partialDispatched = [];
    $admIds = array_map('intval', array_column($allStudents, 'id'));
    if (!empty($admIds)) {
        $chunkSize = 400;
        for ($i = 0; $i < count($admIds); $i += $chunkSize) {
            $chunk = array_slice($admIds, $i, $chunkSize);
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            try {
                $diStmt = $pdo->prepare("SELECT admission_id, item_type, item_detail, status FROM dispatch_items WHERE admission_id IN ($ph)");
                $diStmt->execute($chunk);
                foreach ($diStmt->fetchAll(PDO::FETCH_ASSOC) as $di) {
                    $key = $di['admission_id'] . '_' . $di['item_type'] . '_' . $di['item_detail'];
                    $partialDispatched[$key] = $di['status'];
                }
            } catch (Exception $e) {}
        }
    }

    // ── Exam-pass map (local + one remote fetch) ──────────────────────────────
    $examPassMap = [];
    $byRegToAdm = [];
    if (!empty($admIds)) {
        $chunkSize = 400;
        for ($i = 0; $i < count($admIds); $i += $chunkSize) {
            $chunk = array_slice($admIds, $i, $chunkSize);
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            try {
                $st = $pdo->prepare("
                    SELECT a.id, a.registration_id, a.roll_no, COALESCE(es.exam_status, '') AS exam_status
                    FROM admissions a
                    LEFT JOIN exam_schedules es ON es.admission_id = a.id AND es.atc_id = a.atc_id
                    WHERE a.id IN ($ph)
                ");
                $st->execute($chunk);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $admId = (int)$row['id'];
                    $reg = trim((string)($row['registration_id'] ?? ''));
                    if ($reg === '') {
                        $reg = trim((string)($row['roll_no'] ?? ''));
                    }
                    if ($reg !== '') {
                        $byRegToAdm[strtoupper($reg)] = $admId;
                    }
                    if (($row['exam_status'] ?? '') === 'Passed') {
                        $examPassMap[$admId] = true;
                    }
                }
            } catch (Exception $e) {}
        }
    }

    $codeToAtcIds = [];
    foreach ($atcList as $a) {
        $code = trim((string)($a['atc_code'] ?? ''));
        if ($code === '') continue;
        if ($filterAtc && (int)$a['id'] !== $filterAtc) continue;
        if ($filterDlc && (int)($a['dlc_id'] ?? 0) !== $filterDlc) continue;
        $codeToAtcIds[strtoupper($code)] = true;
    }

    if (!empty($codeToAtcIds) && function_exists('examIntegrationReady') && examIntegrationReady()
        && function_exists('fetchAllExamResultsComplete') && function_exists('examSubmissionPassRecord')) {
        $res = fetchAllExamResultsComplete();
        if (!empty($res['success']) && !empty($res['data']['submissions'])) {
            foreach ($res['data']['submissions'] as $sub) {
                $centre = strtoupper(trim((string)($sub['centre_name'] ?? '')));
                if ($centre === '' || empty($codeToAtcIds[$centre])) {
                    continue;
                }
                $rec = examSubmissionPassRecord($sub);
                if (!$rec) {
                    continue;
                }
                $key = strtoupper(trim((string)($rec['identifier'] ?? '')));
                if ($key !== '' && isset($byRegToAdm[$key])) {
                    $examPassMap[$byRegToAdm[$key]] = true;
                }
            }
        }
    }

    // ── Course material selection flags ───────────────────────────────────────
    $courseMap = [];
    $courseCategoryFlags = [];
    $courseNames = array_values(array_unique(array_filter(array_map(
        static fn($x) => trim((string)($x['course'] ?? '')),
        $allStudents
    ))));

    if (!empty($courseNames)) {
        $ph = implode(',', array_fill(0, count($courseNames), '?'));
        try {
            $cStmt = $pdo->prepare("SELECT id, course_name, with_material_configured FROM courses WHERE course_name IN ($ph)");
            $cStmt->execute($courseNames);
            foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $courseMap[(string)$c['course_name']] = [
                    'course_id' => (int)$c['id'],
                    'with_material_configured' => (int)($c['with_material_configured'] ?? 0),
                ];
            }
        } catch (Exception $e) {}
    }

    $configuredCourseIds = [];
    foreach ($courseMap as $row) {
        if (!empty($row['with_material_configured'])) {
            $configuredCourseIds[] = $row['course_id'];
        }
    }
    $configuredCourseIds = array_values(array_unique($configuredCourseIds));

    if (!empty($configuredCourseIds)) {
        $ph = implode(',', array_fill(0, count($configuredCourseIds), '?'));
        try {
            $mStmt = $pdo->prepare("
                SELECT cmi.course_id, ii.category
                FROM course_material_items cmi
                INNER JOIN inventory_items ii ON ii.id = cmi.inventory_item_id
                WHERE cmi.material_variant = 'With Material'
                  AND cmi.course_id IN ($ph)
                  AND ii.status='Active'
            ");
            $mStmt->execute($configuredCourseIds);
            foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $cid = (int)$m['course_id'];
                if (!isset($courseCategoryFlags[$cid])) {
                    $courseCategoryFlags[$cid] = ['hasTshirt' => false, 'hasBooks' => false];
                }
                if (($m['category'] ?? '') === 'T-Shirts') $courseCategoryFlags[$cid]['hasTshirt'] = true;
                if (($m['category'] ?? '') === 'Books') $courseCategoryFlags[$cid]['hasBooks'] = true;
            }
        } catch (Exception $e) {}
    }

    // ── Categorize students ───────────────────────────────────────────────────
    foreach ($allStudents as $s) {
        $isSharePaid = isset($paidMap[(int)$s['id']]) || !empty($s['ho_share_paid']);
        if (!$isSharePaid) {
            continue;
        }

        $materials = [];
        $allDispatched = true;

        $courseRow = $courseMap[(string)($s['course'] ?? '')] ?? null;
        $isCourseConfigured = !empty($courseRow) && ((int)$courseRow['with_material_configured'] === 1);
        $courseId = !empty($courseRow) ? (int)$courseRow['course_id'] : 0;
        $courseFlags = $courseId && isset($courseCategoryFlags[$courseId])
            ? $courseCategoryFlags[$courseId]
            : ['hasTshirt' => false, 'hasBooks' => false];
        $includeTshirt = !$isCourseConfigured ? true : !empty($courseFlags['hasTshirt']);
        $includeBooks  = !$isCourseConfigured ? true : !empty($courseFlags['hasBooks']);

        if (!empty($s['uniform_size']) && $includeTshirt) {
            $tKey = $s['id'] . '_T-Shirt_Size ' . $s['uniform_size'];
            $status = $partialDispatched[$tKey] ?? null;
            if ($status === 'Dispatched') {
                $materials[] = ['type' => 'T-Shirt', 'detail' => 'Size ' . $s['uniform_size'], 'dispatched' => true];
            } else {
                $materials[] = ['type' => 'T-Shirt', 'detail' => 'Size ' . $s['uniform_size'], 'dispatched' => false];
                $allDispatched = false;
            }
        }

        if (!empty($s['material_language']) && $includeBooks) {
            $bKey = $s['id'] . '_Book_' . $s['material_language'];
            $status = $partialDispatched[$bKey] ?? null;
            if ($status === 'Dispatched') {
                $materials[] = ['type' => 'Book', 'detail' => $s['material_language'], 'dispatched' => true];
            } else {
                $materials[] = ['type' => 'Book', 'detail' => $s['material_language'], 'dispatched' => false];
                $allDispatched = false;
            }
        }

        if (!empty($examPassMap[(int)$s['id']])) {
            $cKey = $s['id'] . '_Certificate_' . ($s['course'] ?? 'General');
            $certPartialStatus = $partialDispatched[$cKey] ?? null;
            if ($certPartialStatus === 'Dispatched') {
                $materials[] = ['type' => 'Certificate', 'detail' => $s['course'] ?? 'General', 'dispatched' => true];
            } else {
                $materials[] = ['type' => 'Certificate', 'detail' => $s['course'] ?? 'General', 'dispatched' => false];
                $allDispatched = false;
            }
        }

        // Material-type filter: keep student if they have a matching pending/sent item
        if ($filterMatType !== 'all') {
            $materials = array_values(array_filter($materials, static function ($m) use ($filterMatType) {
                return ($m['type'] ?? '') === $filterMatType;
            }));
            if (empty($materials)) {
                continue;
            }
            $allDispatched = true;
            foreach ($materials as $m) {
                if (empty($m['dispatched'])) {
                    $allDispatched = false;
                    break;
                }
            }
        }

        $s['materials'] = $materials;
        if ($allDispatched && !empty($materials)) {
            $completedStudents[] = $s;
        } elseif (!empty($materials)) {
            $pendingStudents[] = $s;
            foreach ($materials as $m) {
                if (!$m['dispatched']) {
                    $key = $m['type'] . ' — ' . $m['detail'];
                    $materialSummary[$key] = ($materialSummary[$key] ?? 0) + 1;
                }
            }
        }
    }
}

$pendingCount   = count($pendingStudents);
$completedCount = count($completedStudents);
$showAtcCol     = !$filterAtc; // when viewing more than one ATC, show column

$selAtcName = 'All ATC Centers';
$selAtcCode = '';
if ($filterAtc && isset($atcById[$filterAtc])) {
    $selAtcName = (string)$atcById[$filterAtc]['name'];
    $selAtcCode = (string)($atcById[$filterAtc]['atc_code'] ?? '');
} elseif ($filterDlc) {
    foreach ($dlcList as $d) {
        if ((int)$d['id'] === $filterDlc) {
            $selAtcName = 'DLC: ' . $d['name'];
            break;
        }
    }
}

$qsBase = http_build_query(array_filter([
    'dlc_id'   => $filterDlc ?: null,
    'atc_id'   => $filterAtc ?: null,
    'course'   => $filterCourse !== '' ? $filterCourse : null,
    'mat_type' => $filterMatType !== 'all' ? $filterMatType : null,
    'search'   => $searchTerm !== '' ? $searchTerm : null,
], static fn($v) => $v !== null && $v !== ''));

function mrQs(string $base, array $extra = []): string {
    $parts = [];
    if ($base !== '') $parts[] = $base;
    foreach ($extra as $k => $v) {
        if ($v === null || $v === '') continue;
        $parts[] = rawurlencode($k) . '=' . rawurlencode((string)$v);
    }
    return $parts ? ('?' . implode('&', $parts)) : '?';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Material Requirements — Admin | Gyanam India Educational Services</title>
<?php include __DIR__ . '/../includes/head_fonts.php'; ?>
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<link rel="stylesheet" href="../assets/css/notifications.css">
<style>
:root { --font:'Sora',sans-serif; --mono:'JetBrains Mono',monospace }

.mr-stats { display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1rem;margin-bottom:1.25rem }
.mr-stat { background:#fff;border:1.5px solid var(--border-color,#e5e7eb);border-radius:14px;padding:1rem 1.15rem;display:flex;align-items:center;gap:.8rem;box-shadow:0 1px 4px rgba(0,0,0,.03) }
.mr-stat-icon { width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0 }
.mr-stat-icon svg { width:18px;height:18px }
.mr-stat-icon.orange { background:#fff7ed;color:#f97316 }
.mr-stat-icon.green  { background:#ecfdf5;color:#10b981 }
.mr-stat-icon.blue   { background:#eff6ff;color:#3b82f6 }
.mr-stat-icon.violet { background:#f5f3ff;color:#7c3aed }
.mr-stat-val { font-size:1.35rem;font-weight:900;color:var(--text-primary,#0f172a);line-height:1 }
.mr-stat-lbl { font-size:.7rem;font-weight:700;color:#64748b;margin-top:.15rem }

.mr-filter {
  display:flex;
  flex-wrap:wrap;
  align-items:center;
  gap:.45rem .5rem;
  margin-bottom:1rem;
  background:#fff;
  padding:.65rem .85rem;
  border-radius:12px;
  border:1.5px solid var(--border-color,#e5e7eb);
  box-shadow:0 1px 4px rgba(0,0,0,.03);
}
.mr-filter > input[type="hidden"] { display:none }
.mf-grp {
  display:flex;
  flex-direction:column;
  gap:0;
  min-width:0;
  flex:0 1 auto;
}
.mf-grp label {
  position:absolute;
  width:1px;height:1px;padding:0;margin:-1px;
  overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;
}
.mf-grp select, .mf-grp input {
  height:34px;padding:0 .65rem;border:1.5px solid #e5e7eb;border-radius:8px;
  font-family:inherit;font-size:.8rem;font-weight:600;outline:none;background:#fff;
  color:#0f172a;min-width:0;
}
.mf-grp select { max-width:160px }
.mf-grp--search { flex:1 1 160px; min-width:140px }
.mf-grp--search input { width:100%; max-width:none }
.mf-grp select:focus, .mf-grp input:focus { border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.12) }
.mf-actions { display:flex;gap:.4rem;align-items:center;flex:0 0 auto;margin-left:auto }
.btn-go {
  height:34px;padding:0 .9rem;background:#4f46e5;color:#fff;border:none;
  border-radius:8px;font-weight:800;cursor:pointer;font-family:inherit;white-space:nowrap;font-size:.78rem;
}
.btn-go:hover { background:#4338ca }
.btn-clear {
  height:34px;padding:0 .75rem;border:1.5px solid #e5e7eb;background:#fff;color:#64748b;
  border-radius:8px;font-weight:700;cursor:pointer;font-family:inherit;font-size:.78rem;text-decoration:none;
  display:inline-flex;align-items:center;
}
.btn-clear:hover { background:#f8fafc;color:#334155 }

.mr-tabs { display:flex;gap:.5rem;margin-bottom:1.15rem;flex-wrap:wrap }
.mr-tab {
  padding:.55rem 1.05rem;border-radius:999px;border:1.5px solid #e5e7eb;background:#fff;
  font:700 .82rem inherit;color:#64748b;cursor:pointer;display:inline-flex;align-items:center;gap:.4rem;
  transition:all .15s;text-decoration:none;
}
.mr-tab:hover { border-color:#c7d2fe;background:#eef2ff;color:#4338ca }
.mr-tab.active { background:linear-gradient(135deg,#6366f1,#4f46e5);border-color:#4f46e5;color:#fff;box-shadow:0 2px 8px rgba(99,102,241,.22) }
.mr-tab-count { padding:.12rem .45rem;border-radius:999px;font-size:.68rem;font-weight:800;min-width:18px;text-align:center }
.mr-tab.active .mr-tab-count { background:rgba(255,255,255,.22) }
.mr-tab:not(.active) .mr-tab-count { background:#f1f5f9;color:#64748b }

.mr-summary { background:#fff;border:1.5px solid #e5e7eb;border-radius:16px;padding:1.1rem 1.25rem;margin-bottom:1.15rem }
.mr-summary-title { font-size:.78rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.65rem;display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap }
.mr-summary-grid { display:flex;flex-wrap:wrap;gap:.5rem }
.mr-sum-chip { display:inline-flex;align-items:center;gap:.4rem;padding:.4rem .85rem;border-radius:10px;font-size:.78rem;font-weight:700;border:1.5px solid #e5e7eb;background:#fafbfc }
.mr-sum-chip .count { font-family:var(--mono);font-weight:900;color:#4361ee;font-size:.9rem }
.mr-sum-chip .label { color:#64748b }

.btn-print { display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;border-radius:10px;border:1.5px solid #c4b5fd;background:#f5f3ff;color:#6d28d9;font:700 .8rem inherit;cursor:pointer;transition:all .15s;white-space:nowrap }
.btn-print:hover { background:#ede9fe;transform:translateY(-1px) }

.mr-tbl-wrap { background:#fff;border:1.5px solid #e5e7eb;border-radius:16px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.03) }
.mr-tbl { width:100%;border-collapse:collapse;font-size:.84rem }
.mr-tbl thead { background:#f8fafc }
.mr-tbl th { padding:.85rem 1rem;text-align:left;font-size:.68rem;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.07em;border-bottom:1px solid #e5e7eb;white-space:nowrap }
.mr-tbl tbody tr { border-bottom:1px solid #f1f5f9;transition:background .12s }
.mr-tbl tbody tr:hover { background:#f8faff }
.mr-tbl tbody tr:last-child { border-bottom:none }
.mr-tbl td { padding:.85rem 1rem;vertical-align:middle }
.atc-chip { display:inline-block;font-size:.72rem;font-weight:700;color:#4338ca;background:#eef2ff;border:1px solid #c7d2fe;padding:.15rem .5rem;border-radius:6px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap }

.mat-pills { display:flex;flex-wrap:wrap;gap:.4rem }
.mat-pill { display:inline-flex;align-items:center;gap:.3rem;padding:.22rem .6rem;border-radius:6px;font-size:.72rem;font-weight:700 }
.mat-pill.book { background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe }
.mat-pill.tshirt { background:#fdf4ff;color:#a855f7;border:1px solid #e9d5ff }
.mat-pill.cert { background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7 }
.mat-pill.done { background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7;text-decoration:line-through;opacity:.65 }

.empty-state { text-align:center;padding:3.5rem 2rem;color:#64748b }
.empty-state svg { width:48px;height:48px;stroke:#cbd5e1;display:block;margin:0 auto .75rem }
.empty-state .title { font-size:1rem;font-weight:800;margin-bottom:.25rem;color:#475569 }
.empty-state .sub { font-size:.82rem;color:#94a3b8;max-width:28rem;margin:0 auto;line-height:1.45 }

@media print {
    .sidebar, .sidebar-overlay, .top-header, .mr-filter, .mr-tabs,
    .mr-stats, .btn-print, .hamburger, .notification-bell,
    .profile-dropdown, .header-right, .header-left { display:none!important }
    body { background:#fff!important }
    .dashboard-layout { display:block!important }
    .main-content { margin:0!important; padding:0!important; width:100%!important; box-shadow:none!important }
    .page-content { padding:0!important }
    .mr-tbl-wrap, .mr-summary { display:none!important }
    .print-doc { display:block!important }
    @page { size: A4 portrait; margin: 12mm 14mm; }
    * { -webkit-print-color-adjust: exact!important; print-color-adjust: exact!important; }
}
.print-doc { display:none }
.pd-page { font-family:'Sora',Arial,sans-serif; color:#111; font-size:9.5pt }
.pd-header { display:flex; align-items:stretch; gap:0; margin-bottom:14pt; overflow:hidden; border:1.5pt solid #1a3a8f }
.pd-header-blue { background:linear-gradient(135deg,#1a3a8f 0%,#3b63d9 100%); padding:12pt 16pt; display:flex; flex-direction:column; justify-content:center; min-width:140pt }
.pd-header-blue .org { font-size:13pt; font-weight:900; color:#fff; letter-spacing:-.03em; line-height:1.1 }
.pd-header-blue .tagline { font-size:7.5pt; color:rgba(255,255,255,.75); margin-top:3pt }
.pd-header-right { flex:1; padding:10pt 14pt; background:#f8faff; display:flex; flex-direction:column; justify-content:space-between }
.pd-header-right .doc-title { font-size:11pt; font-weight:800; color:#1a3a8f }
.pd-header-right .doc-sub { font-size:7.5pt; color:#6b7280; margin-top:2pt }
.pd-meta { display:grid; grid-template-columns:1fr 1fr 1fr; gap:8pt; margin-top:6pt }
.pd-meta-item .lbl { font-size:6.5pt; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#9ca3af }
.pd-meta-item .val { font-size:8.5pt; font-weight:700; color:#111; margin-top:1pt }
.pd-section-title { font-size:8pt; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:#1a3a8f; margin:12pt 0 5pt; padding-bottom:3pt; border-bottom:1.5pt solid #3b63d9 }
.pd-sum-tbl, .pd-stu-tbl { width:100%; border-collapse:collapse; font-size:8.5pt; margin-bottom:12pt }
.pd-sum-tbl th, .pd-stu-tbl th { background:#1a3a8f; color:#fff; padding:5pt 8pt; text-align:left; font-size:7pt; font-weight:700; text-transform:uppercase }
.pd-sum-tbl td, .pd-stu-tbl td { padding:5pt 8pt; border-bottom:1pt solid #e5e7eb }
.pd-sum-tbl tr:nth-child(even) td, .pd-stu-tbl tr:nth-child(even) td { background:#f8fafc }
.pd-sum-tbl .total-row td { background:#1a3a8f!important; color:#fff!important; font-weight:800 }
.pd-stu-tbl .pill { display:inline-block; padding:1.5pt 5pt; border-radius:4pt; font-weight:700; font-size:7pt }
.pd-stu-tbl .pill-book { background:#dbeafe; color:#1e40af }
.pd-stu-tbl .pill-tshirt { background:#f3e8ff; color:#7c3aed }
.pd-footer { margin-top:14pt; display:flex; justify-content:space-between; align-items:flex-end; border-top:1pt solid #e5e7eb; padding-top:8pt }
.pd-footer .sign-line { width:80pt; border-top:1pt solid #374151; margin-bottom:4pt }
.pd-footer .sign-lbl { font-size:7pt; font-weight:700; color:#374151 }
.pd-footer .sign-sub { font-size:6pt; color:#9ca3af }
.pd-footer-center { text-align:center; font-size:6.5pt; color:#9ca3af; line-height:1.6 }

@media (max-width:900px) {
  .mf-grp select { max-width:none }
  .mf-actions { margin-left:0 }
}
@media (max-width:600px) {
  .mr-stats { grid-template-columns:1fr 1fr }
  .mf-grp, .mf-grp--search { flex:1 1 100% }
  .mf-grp select, .mf-grp--search input { max-width:none; width:100% }
  .mf-actions { width:100% }
  .btn-go, .btn-clear { flex:1; justify-content:center }
}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include __DIR__ . '/sidebar.php'; ?>

<main class="main-content">
    <header class="top-header">
        <div class="header-left">
            <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
            <div class="header-greeting">
                <h2>Material Requirements</h2>
                <p>Share-paid students needing course materials — all ATCs by default</p>
            </div>
        </div>
        <div class="header-right">
            <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
            <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
        </div>
    </header>

    <div class="page-content">

        <!-- Print document -->
        <div class="print-doc">
        <?php if (!empty($pendingStudents)): ?>
        <div class="pd-page">
            <div class="pd-header">
                <div class="pd-header-blue">
                    <?php if (file_exists(__DIR__ . '/../assets/logo.png')): ?>
                    <img src="../assets/logo.png" style="height:32pt;width:auto;margin-bottom:6pt;object-fit:contain" alt="Logo">
                    <?php endif; ?>
                    <div class="org">Gyanam India<br>Educational Services</div>
                    <div class="tagline">Material Requirements Report</div>
                </div>
                <div class="pd-header-right">
                    <div>
                        <div class="doc-title">Course Material Requirements</div>
                        <div class="doc-sub">Share-paid students with pending materials</div>
                    </div>
                    <div class="pd-meta">
                        <div class="pd-meta-item"><div class="lbl">Scope</div><div class="val"><?= htmlspecialchars($selAtcName) ?></div></div>
                        <div class="pd-meta-item"><div class="lbl">ATC Code</div><div class="val"><?= $selAtcCode !== '' ? htmlspecialchars($selAtcCode) : '—' ?></div></div>
                        <div class="pd-meta-item"><div class="lbl">Generated</div><div class="val"><?= date('d M Y, h:i A') ?></div></div>
                        <div class="pd-meta-item"><div class="lbl">Pending Students</div><div class="val"><?= $pendingCount ?></div></div>
                        <div class="pd-meta-item"><div class="lbl">Material Types</div><div class="val"><?= count($materialSummary) ?></div></div>
                        <div class="pd-meta-item"><div class="lbl">Prepared By</div><div class="val"><?= htmlspecialchars($userName) ?></div></div>
                    </div>
                </div>
            </div>

            <?php if (!empty($materialSummary)): ?>
            <div class="pd-section-title">Material Summary — Total Units Required</div>
            <table class="pd-sum-tbl">
                <thead><tr><th>#</th><th>Material Type</th><th>Description</th><th style="text-align:right">Quantity Needed</th></tr></thead>
                <tbody>
                <?php $n=0; foreach ($materialSummary as $label => $count):
                    $n++;
                    $parts = explode(' — ', $label, 2);
                ?>
                <tr>
                    <td style="color:#9ca3af;font-weight:700"><?= $n ?></td>
                    <td><strong><?= htmlspecialchars($parts[0] ?? $label) ?></strong></td>
                    <td style="color:#6b7280"><?= htmlspecialchars($parts[1] ?? '') ?></td>
                    <td style="text-align:right;font-weight:800;color:#1a3a8f"><?= $count ?> pcs</td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row"><td colspan="3" style="text-align:right">TOTAL UNITS</td><td style="text-align:right"><?= array_sum($materialSummary) ?> pcs</td></tr>
                </tbody>
            </table>
            <?php endif; ?>

            <div class="pd-section-title">Student-wise Material Requirements</div>
            <table class="pd-stu-tbl">
                <thead>
                    <tr>
                        <th>#</th>
                        <?php if ($showAtcCol): ?><th>ATC</th><?php endif; ?>
                        <th>Student Name</th>
                        <th>Registration ID</th>
                        <th>Roll No</th>
                        <th>Course</th>
                        <th>Book Language</th>
                        <th>T-Shirt Size</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pendingStudents as $idx => $s): ?>
                <tr>
                    <td style="color:#9ca3af;font-weight:700;text-align:center"><?= $idx + 1 ?></td>
                    <?php if ($showAtcCol): ?><td><?= htmlspecialchars($s['atc_name'] ?? '—') ?></td><?php endif; ?>
                    <td><strong><?= htmlspecialchars($s['student_name']) ?></strong></td>
                    <td style="font-family:monospace;font-size:7.5pt"><?= htmlspecialchars($s['registration_id'] ?: 'GYANAM'.$s['id']) ?></td>
                    <td style="font-family:monospace;font-size:7.5pt;color:#6b7280"><?= htmlspecialchars($s['roll_no'] ?? '—') ?></td>
                    <td style="color:#6b7280"><?= htmlspecialchars($s['course'] ?? '—') ?></td>
                    <td>
                        <?php foreach ($s['materials'] as $m): if (!$m['dispatched'] && $m['type'] === 'Book'): ?>
                        <span class="pill pill-book"><?= htmlspecialchars($m['detail']) ?></span>
                        <?php endif; endforeach; ?>
                    </td>
                    <td>
                        <?php foreach ($s['materials'] as $m): if (!$m['dispatched'] && $m['type'] === 'T-Shirt'): ?>
                        <span class="pill pill-tshirt"><?= htmlspecialchars($m['detail']) ?></span>
                        <?php endif; endforeach; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="pd-footer">
                <div class="sign-box"><div class="sign-line"></div><div class="sign-lbl">Verified By</div><div class="sign-sub">Head Office — Gyanam India</div></div>
                <div class="pd-footer-center">
                    <strong>Gyanam India Educational Services</strong><br>
                    Course Material Requirements · <?= htmlspecialchars($selAtcName) ?><br>
                    Generated: <?= date('d M Y h:i A') ?>
                </div>
                <div class="sign-box"><div class="sign-line"></div><div class="sign-lbl">Received By</div><div class="sign-sub"><?= htmlspecialchars($filterAtc ? $selAtcName : 'ATC Center') ?></div></div>
            </div>
        </div>
        <?php else: ?>
        <div style="text-align:center;padding:2rem;font-family:Arial,sans-serif;color:#6b7280">
            No pending students match the current filters.
        </div>
        <?php endif; ?>
        </div>

        <!-- Filters -->
        <form method="GET" class="mr-filter" id="mrFilterForm">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
            <div class="mf-grp">
                <label for="dlc_id">DLC Office</label>
                <select name="dlc_id" id="dlc_id" title="DLC Office" aria-label="DLC Office">
                    <option value="">All DLCs</option>
                    <?php foreach ($dlcList as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= $filterDlc === (int)$d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mf-grp">
                <label for="atc_id">ATC Center</label>
                <select name="atc_id" id="atc_id" title="ATC Center" aria-label="ATC Center">
                    <option value="">All ATCs</option>
                    <?php foreach ($atcList as $a):
                        $hide = $filterDlc && (int)($a['dlc_id'] ?? 0) !== $filterDlc;
                    ?>
                    <option value="<?= (int)$a['id'] ?>"
                            data-dlc="<?= (int)($a['dlc_id'] ?? 0) ?>"
                            <?= $filterAtc === (int)$a['id'] ? 'selected' : '' ?>
                            <?= $hide ? 'hidden disabled' : '' ?>>
                        <?= htmlspecialchars($a['name']) ?><?= !empty($a['atc_code']) ? ' (' . htmlspecialchars($a['atc_code']) . ')' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mf-grp">
                <label for="course">Course</label>
                <select name="course" id="course" title="Course" aria-label="Course">
                    <option value="">All Courses</option>
                    <?php foreach ($courseOptions as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>" <?= $filterCourse === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mf-grp">
                <label for="mat_type">Material</label>
                <select name="mat_type" id="mat_type" title="Material type" aria-label="Material type">
                    <option value="all" <?= $filterMatType === 'all' ? 'selected' : '' ?>>All types</option>
                    <option value="Book" <?= $filterMatType === 'Book' ? 'selected' : '' ?>>Books</option>
                    <option value="T-Shirt" <?= $filterMatType === 'T-Shirt' ? 'selected' : '' ?>>T-Shirts</option>
                    <option value="Certificate" <?= $filterMatType === 'Certificate' ? 'selected' : '' ?>>Certificates</option>
                </select>
            </div>
            <div class="mf-grp mf-grp--search">
                <label for="search">Search</label>
                <input type="search" name="search" id="search" placeholder="Search name, roll, reg, ATC…" value="<?= htmlspecialchars($searchTerm) ?>" autocomplete="off" aria-label="Search">
            </div>
            <div class="mf-actions">
                <button type="submit" class="btn-go" id="mrFilterBtn">Apply</button>
                <?php if ($filterDlc || $filterAtc || $filterCourse !== '' || $filterMatType !== 'all' || $searchTerm !== ''): ?>
                <a class="btn-clear" href="material_requirements.php?tab=<?= urlencode($tab) ?>">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Stats -->
        <div class="mr-stats">
            <div class="mr-stat">
                <div class="mr-stat-icon orange"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg></div>
                <div><div class="mr-stat-val"><?= $pendingCount ?></div><div class="mr-stat-lbl">Pending students</div></div>
            </div>
            <div class="mr-stat">
                <div class="mr-stat-icon green"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></div>
                <div><div class="mr-stat-val"><?= $completedCount ?></div><div class="mr-stat-lbl">Completed</div></div>
            </div>
            <div class="mr-stat">
                <div class="mr-stat-icon blue"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></div>
                <div><div class="mr-stat-val"><?= count($materialSummary) ?></div><div class="mr-stat-lbl">Material SKUs needed</div></div>
            </div>
            <div class="mr-stat">
                <div class="mr-stat-icon violet"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg></div>
                <div><div class="mr-stat-val"><?= count($scopeAtcIds) ?></div><div class="mr-stat-lbl">ATCs in scope</div></div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="mr-tabs">
            <a href="<?= htmlspecialchars(mrQs($qsBase, ['tab' => 'pending'])) ?>" class="mr-tab <?= $tab === 'pending' ? 'active' : '' ?>">
                Pending materials <span class="mr-tab-count"><?= $pendingCount ?></span>
            </a>
            <a href="<?= htmlspecialchars(mrQs($qsBase, ['tab' => 'completed'])) ?>" class="mr-tab <?= $tab === 'completed' ? 'active' : '' ?>">
                Completed <span class="mr-tab-count"><?= $completedCount ?></span>
            </a>
        </div>

        <?php if ($tab === 'pending'): ?>

        <?php if (!empty($materialSummary)): ?>
        <div class="mr-summary">
            <div class="mr-summary-title">
                <span>Material summary — units still needed</span>
                <button type="button" class="btn-print" onclick="window.print()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Print report
                </button>
            </div>
            <div class="mr-summary-grid">
                <?php foreach ($materialSummary as $label => $count): ?>
                <div class="mr-sum-chip">
                    <span class="count"><?= $count ?></span>
                    <span class="label">× <?= htmlspecialchars($label) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="mr-tbl-wrap">
            <?php if (empty($pendingStudents)): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="1.5"><polyline points="20 6 9 17 4 12"/></svg>
                <div class="title"><?= empty($scopeAtcIds) ? 'No ATC centers found' : 'No pending materials' ?></div>
                <div class="sub"><?= empty($scopeAtcIds)
                    ? 'There are no active ATC centers in the current filter scope.'
                    : 'No share-paid students need materials for this filter. Try clearing filters or check Completed.' ?></div>
            </div>
            <?php else: ?>
            <table class="mr-tbl">
                <thead>
                    <tr>
                        <th>#</th>
                        <?php if ($showAtcCol): ?><th>ATC</th><?php endif; ?>
                        <th>Student</th>
                        <th>Registration ID</th>
                        <th>Course</th>
                        <th>Admission</th>
                        <th>Materials needed</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pendingStudents as $idx => $s): ?>
                <tr>
                    <td style="color:#94a3b8;font-size:.75rem"><?= $idx + 1 ?></td>
                    <?php if ($showAtcCol): ?>
                    <td><span class="atc-chip" title="<?= htmlspecialchars($s['atc_name'] ?? '') ?>"><?= htmlspecialchars($s['atc_name'] ?? '—') ?></span></td>
                    <?php endif; ?>
                    <td>
                        <div style="font-weight:800;font-size:.88rem"><?= htmlspecialchars($s['student_name']) ?></div>
                        <div style="font-size:.7rem;color:#94a3b8"><?= htmlspecialchars($s['roll_no'] ?? '') ?></div>
                    </td>
                    <td><code style="font-size:.78rem;background:#f1f5f9;padding:.15rem .4rem;border-radius:4px"><?= htmlspecialchars($s['registration_id'] ?: 'GYANAM' . $s['id']) ?></code></td>
                    <td style="font-weight:700;font-size:.82rem;color:#4361ee"><?= htmlspecialchars($s['course'] ?? '—') ?></td>
                    <td style="font-size:.82rem;color:#64748b"><?= !empty($s['admission_date']) ? date('d M Y', strtotime($s['admission_date'])) : '—' ?></td>
                    <td>
                        <div class="mat-pills">
                            <?php foreach ($s['materials'] as $m): if (!$m['dispatched']): ?>
                            <span class="mat-pill <?= $m['type'] === 'T-Shirt' ? 'tshirt' : ($m['type'] === 'Certificate' ? 'cert' : 'book') ?>">
                                <?= $m['type'] === 'T-Shirt' ? 'T-Shirt' : ($m['type'] === 'Certificate' ? 'Cert' : 'Book') ?> · <?= htmlspecialchars($m['detail']) ?>
                            </span>
                            <?php endif; endforeach; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <?php else: ?>

        <div class="mr-tbl-wrap">
            <?php if (empty($completedStudents)): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
                <div class="title">No completed dispatches yet</div>
                <div class="sub">Once materials are fully dispatched for students, they appear here.</div>
            </div>
            <?php else: ?>
            <table class="mr-tbl">
                <thead>
                    <tr>
                        <th>#</th>
                        <?php if ($showAtcCol): ?><th>ATC</th><?php endif; ?>
                        <th>Student</th>
                        <th>Registration ID</th>
                        <th>Course</th>
                        <th>Materials sent</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($completedStudents as $idx => $s): ?>
                <tr>
                    <td style="color:#94a3b8;font-size:.75rem"><?= $idx + 1 ?></td>
                    <?php if ($showAtcCol): ?>
                    <td><span class="atc-chip" title="<?= htmlspecialchars($s['atc_name'] ?? '') ?>"><?= htmlspecialchars($s['atc_name'] ?? '—') ?></span></td>
                    <?php endif; ?>
                    <td>
                        <div style="font-weight:800;font-size:.88rem"><?= htmlspecialchars($s['student_name']) ?></div>
                        <div style="font-size:.7rem;color:#94a3b8"><?= htmlspecialchars($s['roll_no'] ?? '') ?></div>
                    </td>
                    <td><code style="font-size:.78rem;background:#f1f5f9;padding:.15rem .4rem;border-radius:4px"><?= htmlspecialchars($s['registration_id'] ?: 'GYANAM' . $s['id']) ?></code></td>
                    <td style="font-weight:700;font-size:.82rem;color:#4361ee"><?= htmlspecialchars($s['course'] ?? '—') ?></td>
                    <td>
                        <div class="mat-pills">
                            <?php foreach ($s['materials'] as $m): ?>
                            <span class="mat-pill done">
                                <?= $m['type'] === 'T-Shirt' ? 'T-Shirt' : ($m['type'] === 'Certificate' ? 'Cert' : 'Book') ?> · <?= htmlspecialchars($m['detail']) ?> ✓
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <?php endif; ?>

    </div>
</main>
</div>

<script src="../assets/js/dashboard.js"></script>
<script src="../assets/js/live-filter.js"></script>
<script>
(function () {
    const dlc = document.getElementById('dlc_id');
    const atc = document.getElementById('atc_id');
    function filterAtcByDlc() {
        if (!dlc || !atc) return;
        const dlcId = dlc.value;
        Array.from(atc.options).forEach((opt) => {
            if (!opt.value) { opt.hidden = false; opt.disabled = false; return; }
            const match = !dlcId || String(opt.getAttribute('data-dlc') || '') === String(dlcId);
            opt.hidden = !match;
            opt.disabled = !match;
            if (!match && opt.selected) atc.value = '';
        });
    }
    dlc?.addEventListener('change', filterAtcByDlc);
    filterAtcByDlc();

    GyanamLiveFilter({
        mode: 'server',
        form: '#mrFilterForm',
        input: '#search',
        button: '#mrFilterBtn',
        searchParam: 'search',
        debounceMs: 450,
        allValue: '',
        reloadSelects: ['#dlc_id', '#atc_id', '#course', '#mat_type'],
        keepParams: ['tab'],
    });
})();
</script>
</body>
</html>
