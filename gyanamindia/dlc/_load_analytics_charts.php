<?php
/**
 * Load DLC Analytics Overview chart series.
 * Expects: $pdo (PDO), $dlcId (int|null)
 * Sets: $dlcPie*, $dlcBar*, $dlcLine*, $dlcAtcStats (optional table rows)
 */
if (!isset($pdo) || !($pdo instanceof PDO)) {
    return;
}

$dlcId = isset($dlcId) ? (int)$dlcId : 0;

$dlcPieLabels = [];
$dlcPieData = [];
$dlcPieTitle = 'ATC Center Types';
$dlcPieSub = 'Active centres by center type';
$dlcBarLabels = [];
$dlcBarData = [];
$dlcBarTitle = 'Top ATCs by Share Due';
$dlcBarSub = 'DLC share due after HO share paid';
$dlcBarIsMoney = true;
$dlcLineLabels = [];
$dlcLineAdm = [];
$dlcLineRev = [];
$dlcAtcStats = $dlcAtcStats ?? [];

if ($dlcId <= 0) {
    return;
}

// Pie — ATC center types (fallback: Active vs Inactive)
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(center_type), ''), 'Other') AS label,
               COUNT(*) AS c
        FROM atc_centers
        WHERE dlc_id = ? AND status = 'Active'
        GROUP BY label
        ORDER BY c DESC
        LIMIT 8
    ");
    $stmt->execute([$dlcId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $dlcPieLabels = array_column($rows, 'label');
    $dlcPieData = array_map('intval', array_column($rows, 'c'));
    if (array_sum($dlcPieData) <= 0) {
        $stmt = $pdo->prepare("
            SELECT status AS label, COUNT(*) AS c
            FROM atc_centers
            WHERE dlc_id = ?
            GROUP BY status
            ORDER BY c DESC
        ");
        $stmt->execute([$dlcId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $dlcPieLabels = array_column($rows, 'label');
        $dlcPieData = array_map('intval', array_column($rows, 'c'));
        $dlcPieTitle = 'ATC Status';
        $dlcPieSub = 'Centres by status';
    }
} catch (Exception $e) {}

// Bar — share due by ATC, else active students
try {
    $byAtcShare = [];
    if (function_exists('calculateDlcShareSummary')) {
        try {
            ensureDualMaterialCourseSchema($pdo);
        } catch (Exception $e) {}
        $sum = calculateDlcShareSummary($pdo, $dlcId, true);
        foreach (($sum['students'] ?? []) as $s) {
            $n = trim((string)($s['atc_name'] ?? '')) ?: 'ATC';
            $byAtcShare[$n] = ($byAtcShare[$n] ?? 0) + (float)($s['dlc_share'] ?? 0);
        }
        arsort($byAtcShare);
        $byAtcShare = array_slice($byAtcShare, 0, 8, true);
    }
    if (!empty($byAtcShare) && array_sum($byAtcShare) > 0) {
        foreach ($byAtcShare as $label => $amt) {
            $n = (string)$label;
            $dlcBarLabels[] = mb_strlen($n) > 16 ? (mb_substr($n, 0, 14) . '…') : $n;
            $dlcBarData[] = round((float)$amt, 0);
        }
    } else {
        $stmt = $pdo->prepare("
            SELECT COALESCE(NULLIF(TRIM(c.name), ''), CONCAT('ATC #', c.id)) AS label,
                   COUNT(a.id) AS students
            FROM atc_centers c
            LEFT JOIN admissions a ON a.atc_id = c.id AND a.status = 'Active'
            WHERE c.dlc_id = ?
            GROUP BY c.id
            ORDER BY students DESC
            LIMIT 8
        ");
        $stmt->execute([$dlcId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $dlcBarTitle = 'Top ATCs by Students';
        $dlcBarSub = 'Active students by centre';
        $dlcBarIsMoney = false;
        foreach ($rows as $r) {
            $n = (string)($r['label'] ?? 'ATC');
            $dlcBarLabels[] = mb_strlen($n) > 16 ? (mb_substr($n, 0, 14) . '…') : $n;
            $dlcBarData[] = (int)($r['students'] ?? 0);
        }
    }
} catch (Exception $e) {}

// Line — admissions + DLC share paid, last 6 months
try {
    $mapAdm = [];
    $mapRev = [];
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(a.admission_date, '%Y-%m') AS sk, COUNT(*) AS cnt
        FROM admissions a
        INNER JOIN atc_centers c ON c.id = a.atc_id
        WHERE c.dlc_id = ? AND a.admission_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
        GROUP BY sk
    ");
    $stmt->execute([$dlcId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $mapAdm[$r['sk']] = (int)$r['cnt'];
    }
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(payment_date, '%Y-%m') AS sk,
               COALESCE(SUM(amount), 0) AS total
        FROM dlc_share_payments
        WHERE dlc_id = ? AND status = 'Completed'
          AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
        GROUP BY sk
    ");
    $stmt->execute([$dlcId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $mapRev[$r['sk']] = (float)$r['total'];
    }
    for ($i = 5; $i >= 0; $i--) {
        $sk = date('Y-m', strtotime("-{$i} months"));
        $dlcLineLabels[] = date('M Y', strtotime($sk . '-01'));
        $dlcLineAdm[] = $mapAdm[$sk] ?? 0;
        $dlcLineRev[] = $mapRev[$sk] ?? 0;
    }
} catch (Exception $e) {}

// ATC performance table (for analytics page)
if (empty($dlcAtcStats)) {
    try {
        $stmt = $pdo->prepare("
            SELECT c.name AS center_name,
                   COALESCE(NULLIF(TRIM(c.atc_code), ''), CONCAT('ATC-', c.id)) AS center_code,
                   c.status,
                   (SELECT COUNT(*) FROM admissions a WHERE a.atc_id = c.id) AS total_adm,
                   (SELECT COUNT(*) FROM admissions a WHERE a.atc_id = c.id AND a.status = 'Active') AS active_adm,
                   (SELECT COUNT(*) FROM inquiries i WHERE i.atc_id = c.id) AS total_inq
            FROM atc_centers c
            WHERE c.dlc_id = ?
            ORDER BY active_adm DESC, c.name ASC
        ");
        $stmt->execute([$dlcId]);
        $dlcAtcStats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $dlcAtcStats = [];
    }
}
