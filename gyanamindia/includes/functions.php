<?php
/**
 * Shared Utility Functions — Gyanam Portal
 */

function sanitize(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void {
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, '/')) {
        header('Location: ' . $url);
        exit;
    }
    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    header('Location: ' . $scheme . '://' . $host . $basePath . '/' . $url);
    exit;
}

function getGreeting(): string {
    $hour = (int) date('G');
    if ($hour < 12) return 'Good Morning';
    if ($hour < 17) return 'Good Afternoon';
    return 'Good Evening';
}

function formatDate(?string $date, string $format = 'd M Y'): string {
    if (!$date) return '—';
    return date($format, strtotime($date));
}

function baseURL(): string {
    return '';
}

// ─────────────────────────────────────────────────────────────────────────────
// STUDENT IDENTIFIER GENERATION
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Generate the next globally unique Registration ID.
 *
 * New Format: GYANAM + [global sequence number]
 * Examples:  GYANAM1, GYANAM2, GYANAM100
 *
 * The sequence is global across all ATCs and center types.
 * Backward-compatible: also reads old GIES and gi* formats for max-sequence detection.
 *
 * @param PDO    $pdo  Active PDO connection
 * @param string $centerType  Ignored — kept for backward compatibility
 * @return string e.g. "GYANAM15"
 */
function generateRegistrationId(PDO $pdo, string $centerType = ''): string {
    // Find the highest global sequence across old (gi..., GIES...) and new (GYANAM...) formats
    $stmt = $pdo->prepare(
        "SELECT COALESCE(MAX(
            CAST(
                CASE
                    WHEN registration_id REGEXP '^GYANAM[0-9]+$' THEN REGEXP_REPLACE(registration_id, '^GYANAM', '')
                    WHEN registration_id REGEXP '^GIES[0-9]+$'   THEN REGEXP_REPLACE(registration_id, '^GIES', '')
                    WHEN registration_id REGEXP '^gi[a-z]+[0-9]+$' THEN REGEXP_REPLACE(registration_id, '^gi[a-z]+', '')
                    ELSE '0'
                END
            AS UNSIGNED)
        ), 0)
        FROM admissions
        WHERE registration_id IS NOT NULL AND registration_id != ''"
    );
    $stmt->execute();
    $maxSeq  = (int) $stmt->fetchColumn();
    $nextSeq = $maxSeq + 1;

    return 'GYANAM' . $nextSeq;
}

/**
 * Generate the next Roll No for a student within a specific ATC.
 * Roll No is a simple integer: 1, 2, 3...
 * Unique within one ATC; may repeat across ATCs.
 *
 * @param PDO $pdo    Active PDO connection
 * @param int $atcId  The ATC center ID
 * @return string     e.g. "1", "2", "15"
 */
function generateNextRollNoSimple(PDO $pdo, int $atcId): string {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(MAX(CAST(roll_no AS UNSIGNED)), 0)
         FROM admissions
         WHERE atc_id = ?
           AND roll_no REGEXP '^[0-9]+$'"
    );
    $stmt->execute([$atcId]);
    $maxRoll = (int) $stmt->fetchColumn();
    return (string) ($maxRoll + 1);
}

// ─────────────────────────────────────────────────────────────────────────────
// DUAL MATERIAL COURSE FEES (With Material / Without Material)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Ensure courses + atc_course_fees have dual HO-share / fee columns.
 * Uses a file flag after first successful migrate so later requests skip SHOW/UPDATE.
 */
function ensureDualMaterialCourseSchema(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $flagFile = __DIR__ . '/../config/.schema_dual_material_ok';
    if (is_file($flagFile)) {
        return;
    }

    $needsMigrate = false;
    try {
        $courseCols = $pdo->query("SHOW COLUMNS FROM courses")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['ho_share_with_material', 'ho_share_without_material', 'dlc_share_with_material', 'dlc_share_without_material'] as $col) {
            if (!in_array($col, $courseCols, true)) {
                $needsMigrate = true;
                if ($col === 'ho_share_with_material') {
                    $pdo->exec("ALTER TABLE courses ADD COLUMN ho_share_with_material DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'HO share when student takes course WITH material'");
                } elseif ($col === 'ho_share_without_material') {
                    $pdo->exec("ALTER TABLE courses ADD COLUMN ho_share_without_material DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'HO share when student takes course WITHOUT material'");
                } elseif ($col === 'dlc_share_with_material') {
                    $pdo->exec("ALTER TABLE courses ADD COLUMN dlc_share_with_material DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'DLC share when student takes course WITH material'");
                } else {
                    $pdo->exec("ALTER TABLE courses ADD COLUMN dlc_share_without_material DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'DLC share when student takes course WITHOUT material'");
                }
            }
        }

        // One-time migrate from legacy single ho_share + material_type (only if still needed)
        $pendingLegacy = (int)$pdo->query("
            SELECT COUNT(*) FROM courses
            WHERE COALESCE(ho_share, 0) > 0
              AND COALESCE(ho_share_with_material, 0) = 0
              AND COALESCE(ho_share_without_material, 0) = 0
        ")->fetchColumn();
        if ($pendingLegacy > 0) {
            $needsMigrate = true;
            $pdo->exec("
                UPDATE courses
                SET
                    ho_share_with_material = CASE
                        WHEN COALESCE(material_type, '') = 'With Material' THEN COALESCE(ho_share, 0)
                        ELSE ho_share_with_material
                    END,
                    ho_share_without_material = CASE
                        WHEN COALESCE(material_type, '') <> 'With Material' THEN COALESCE(ho_share, 0)
                        ELSE ho_share_without_material
                    END
                WHERE COALESCE(ho_share, 0) > 0
                  AND COALESCE(ho_share_with_material, 0) = 0
                  AND COALESCE(ho_share_without_material, 0) = 0
            ");
        }
    } catch (Exception $e) {
        error_log('[DualMaterial] courses schema: ' . $e->getMessage());
        return;
    }

    try {
        $admCols = $pdo->query("SHOW COLUMNS FROM admissions")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('dlc_share_snapshot', $admCols, true)) {
            $needsMigrate = true;
            $pdo->exec("ALTER TABLE admissions ADD COLUMN dlc_share_snapshot DECIMAL(10,2) DEFAULT NULL COMMENT 'DLC share locked at admission time'");
        }
    } catch (Exception $e) {
        error_log('[DualMaterial] admissions schema: ' . $e->getMessage());
    }

    try {
        $feeCols = $pdo->query("SHOW COLUMNS FROM atc_course_fees")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('fee_with_material', $feeCols, true)) {
            $needsMigrate = true;
            $pdo->exec("ALTER TABLE atc_course_fees ADD COLUMN fee_with_material DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'ATC selling fee WITH material'");
        }
        if (!in_array('fee_without_material', $feeCols, true)) {
            $needsMigrate = true;
            $pdo->exec("ALTER TABLE atc_course_fees ADD COLUMN fee_without_material DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'ATC selling fee WITHOUT material'");
        }

        $pendingFees = (int)$pdo->query("
            SELECT COUNT(*) FROM atc_course_fees
            WHERE COALESCE(final_fee, 0) > 0
              AND COALESCE(fee_with_material, 0) = 0
              AND COALESCE(fee_without_material, 0) = 0
        ")->fetchColumn();
        if ($pendingFees > 0) {
            $needsMigrate = true;
            $pdo->exec("
                UPDATE atc_course_fees acf
                INNER JOIN courses c ON c.id = acf.course_id
                SET
                    acf.fee_with_material = CASE
                        WHEN COALESCE(c.material_type, '') = 'With Material' THEN COALESCE(acf.final_fee, 0)
                        ELSE acf.fee_with_material
                    END,
                    acf.fee_without_material = CASE
                        WHEN COALESCE(c.material_type, '') <> 'With Material' THEN COALESCE(acf.final_fee, 0)
                        ELSE acf.fee_without_material
                    END
                WHERE COALESCE(acf.final_fee, 0) > 0
                  AND COALESCE(acf.fee_with_material, 0) = 0
                  AND COALESCE(acf.fee_without_material, 0) = 0
            ");
        }
    } catch (Exception $e) {
        error_log('[DualMaterial] atc_course_fees schema: ' . $e->getMessage());
        return;
    }

    // Mark done so future requests skip all of the above
    @file_put_contents($flagFile, date('c') . ($needsMigrate ? " migrated\n" : " ok\n"));
}

/**
 * HO share for a course + material choice.
 * Prefers dedicated columns; falls back to legacy ho_share.
 */
function getHoShareForCourse(PDO $pdo, string $courseName, string $materialType = 'Without Material'): ?float {
    try {
        ensureDualMaterialCourseSchema($pdo);
        $s = $pdo->prepare("
            SELECT ho_share, ho_share_with_material, ho_share_without_material
            FROM courses
            WHERE course_name = ? AND status = 'Active'
            LIMIT 1
        ");
        $s->execute([trim($courseName)]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $with    = (float)($row['ho_share_with_material'] ?? 0);
        $without = (float)($row['ho_share_without_material'] ?? 0);
        $legacy  = (float)($row['ho_share'] ?? 0);

        // Do not cross-fallback between With / Without — a ₹0 option must stay unavailable.
        if ($materialType === 'With Material') {
            if ($with > 0) {
                return $with;
            }
            // Legacy only when dual columns were never set
            if ($with <= 0 && $without <= 0 && $legacy > 0) {
                return $legacy;
            }
            return null;
        }

        if ($without > 0) {
            return $without;
        }
        if ($with <= 0 && $without <= 0 && $legacy > 0) {
            return $legacy;
        }
        return null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * DLC share for a course + material choice (Admin → DLC, per student).
 */
function getDlcShareForCourse(PDO $pdo, string $courseName, string $materialType = 'Without Material'): ?float {
    try {
        ensureDualMaterialCourseSchema($pdo);
        $s = $pdo->prepare("
            SELECT dlc_share_with_material, dlc_share_without_material
            FROM courses
            WHERE course_name = ? AND status = 'Active'
            LIMIT 1
        ");
        $s->execute([trim($courseName)]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $with    = (float)($row['dlc_share_with_material'] ?? 0);
        $without = (float)($row['dlc_share_without_material'] ?? 0);
        // No cross-fallback: DLC amount is per material choice
        if ($materialType === 'With Material') {
            return $with > 0 ? $with : null;
        }
        return $without > 0 ? $without : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Resolve a student's locked DLC share (snapshot preferred).
 */
function resolveStudentDlcShare(array $admission, PDO $pdo): float {
    $snap = isset($admission['dlc_share_snapshot']) ? (float)$admission['dlc_share_snapshot'] : 0;
    if ($snap > 0) {
        return $snap;
    }
    $course = trim((string)($admission['course'] ?? ''));
    if ($course === '') {
        return 0.0;
    }
    $mat = $admission['material_type'] ?? 'Without Material';
    return (float)(getDlcShareForCourse($pdo, $course, $mat) ?? 0);
}

/**
 * Build set of admission IDs whose HO share is paid (Completed share_payments).
 *
 * @return array<int,true>
 */
function getHoSharePaidAdmissionIds(PDO $pdo, ?int $atcId = null): array {
    $paid = [];
    try {
        if ($atcId) {
            $sp = $pdo->prepare("SELECT student_ids FROM share_payments WHERE atc_id = ? AND status = 'Completed'");
            $sp->execute([$atcId]);
        } else {
            $sp = $pdo->query("SELECT student_ids FROM share_payments WHERE status = 'Completed'");
        }
        foreach ($sp->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $ids = json_decode((string)$json, true);
            if (!is_array($ids)) {
                continue;
            }
            foreach ($ids as $id) {
                $paid[(int)$id] = true;
            }
        }
    } catch (Exception $e) {}
    return $paid;
}

/**
 * Calculate DLC earnings summary for one DLC office.
 * Due = sum of DLC share for HO-share-paid students under this DLC's ATCs.
 *
 * @param bool $includeStudents When false (dashboards), skip building the full student list.
 * @return array{due:float,paid:float,pending:float,student_count:int,students:list<array>}
 */
function calculateDlcShareSummary(PDO $pdo, int $dlcId, bool $includeStudents = true): array {
    ensureDualMaterialCourseSchema($pdo);
    $summary = ['due' => 0.0, 'paid' => 0.0, 'pending' => 0.0, 'student_count' => 0, 'students' => []];

    try {
        $paidStmt = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN status='Completed' THEN amount ELSE 0 END),0)
            FROM dlc_share_payments WHERE dlc_id = ?
        ");
        $paidStmt->execute([$dlcId]);
        $summary['paid'] = (float)$paidStmt->fetchColumn();
    } catch (Exception $e) {}

    try {
        $stmt = $pdo->prepare("
            SELECT a.id, a.roll_no, a.registration_id, a.course, a.material_type,
                   a.dlc_share_snapshot, a.admission_date, a.atc_id,
                   TRIM(CONCAT(a.first_name,' ',COALESCE(a.middle_name,''),' ',a.last_name)) AS student_name,
                   atc.name AS atc_name
            FROM admissions a
            INNER JOIN atc_centers atc ON atc.id = a.atc_id
            WHERE atc.dlc_id = ? AND a.status = 'Active'
            ORDER BY a.admission_date DESC, a.id DESC
        ");
        $stmt->execute([$dlcId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return $summary;
    }

    $paidMap = getHoSharePaidAdmissionIds($pdo);

    // Prefetch course DLC shares once (avoids N+1 getDlcShareForCourse)
    $courseShareCache = [];
    try {
        foreach ($pdo->query("SELECT course_name, dlc_share_with_material, dlc_share_without_material FROM courses WHERE status = 'Active'") as $cRow) {
            $ck = mb_strtolower(trim((string)$cRow['course_name']));
            $courseShareCache[$ck . '|with material']    = (float)($cRow['dlc_share_with_material'] ?? 0);
            $courseShareCache[$ck . '|without material'] = (float)($cRow['dlc_share_without_material'] ?? 0);
        }
    } catch (Exception $e) {}

    foreach ($rows as $row) {
        if (!isset($paidMap[(int)$row['id']])) {
            continue;
        }
        $share = isset($row['dlc_share_snapshot']) ? (float)$row['dlc_share_snapshot'] : 0.0;
        if ($share <= 0) {
            $ck = mb_strtolower(trim((string)($row['course'] ?? '')));
            $mat = mb_strtolower(trim((string)($row['material_type'] ?? 'Without Material')));
            $key = $ck . '|' . (($mat === 'with material') ? 'with material' : 'without material');
            $share = (float)($courseShareCache[$key] ?? 0);
        }
        if ($share <= 0) {
            continue;
        }
        $summary['due'] += $share;
        $summary['student_count']++;
        if ($includeStudents) {
            $summary['students'][] = [
                'id'            => (int)$row['id'],
                'roll_no'       => $row['roll_no'],
                'registration_id' => $row['registration_id'] ?? '',
                'student_name'  => trim(preg_replace('/\s+/', ' ', $row['student_name'] ?? '')),
                'course'        => $row['course'],
                'material_type' => $row['material_type'] ?? '',
                'atc_name'      => $row['atc_name'] ?? '',
                'dlc_share'     => $share,
                'admission_date'=> $row['admission_date'] ?? '',
            ];
        }
    }

    $summary['pending'] = max(0, $summary['due'] - $summary['paid']);
    return $summary;
}

/**
 * Master course types — also used as ATC center-type visibility.
 * Combo centers (e.g. "Abacus + IT") see every listed type they include.
 *
 * @return list<string>
 */
function masterCourseTypes(): array {
    return ['Abacus', 'Vedic Maths', 'IT'];
}

function getAtcCenterType(PDO $pdo, ?int $atcId): string {
    if (!$atcId) {
        return '';
    }
    static $cache = [];
    if (array_key_exists($atcId, $cache)) {
        return $cache[$atcId];
    }
    $st = $pdo->prepare('SELECT center_type FROM atc_centers WHERE id = ? LIMIT 1');
    $st->execute([$atcId]);
    $cache[$atcId] = (string)($st->fetchColumn() ?: '');
    return $cache[$atcId];
}

/**
 * Course types an ATC of this center_type is allowed to see.
 *
 * @return list<string>
 */
function courseTypesForCenter(?string $centerType): array {
    $raw = strtolower(trim((string)$centerType));
    if ($raw === '') {
        return [];
    }
    $types = [];
    if (str_contains($raw, 'abacus')) {
        $types[] = 'Abacus';
    }
    if (str_contains($raw, 'vedic')) {
        $types[] = 'Vedic Maths';
    }
    if (preg_match('/(^|[^a-z])it([^a-z]|$)/', $raw) || str_contains($raw, 'all three')) {
        $types[] = 'IT';
    }
    return $types;
}

/**
 * SQL fragment so ATCs only see courses for their center type.
 * Never falls back to showing every course.
 *
 * @return array{0:string,1:list<string>}
 */
function courseVisibilitySql(?string $centerType, string $column = 'c.course_type'): array {
    $types = courseTypesForCenter($centerType);
    if ($types === []) {
        return [' AND 1=0', []];
    }
    $ph = implode(',', array_fill(0, count($types), '?'));
    return [" AND {$column} IN ($ph)", $types];
}

function courseIsVisibleToCenter(?string $courseType, ?string $centerType): bool {
    $type = trim((string)$courseType);
    return $type !== '' && in_array($type, courseTypesForCenter($centerType), true);
}

/**
 * Build With/Without course dropdown options from an ATC fee row.
 * Only includes variants where the ATC fee is > 0.
 *
 * @return list<array{label:string,course_name:string,material_type:string,fee:float,language:?string,course_type:?string,duration:?string}>
 */
function buildCourseMaterialOptions(array $course): array {
    $name     = (string)($course['course_name'] ?? '');
    $duration = (string)($course['duration'] ?? '');
    $lang     = $course['material_language'] ?? 'English';
    $type     = $course['course_type'] ?? '';
    $feeWith  = (float)($course['fee_with_material'] ?? 0);
    $feeWithout = (float)($course['fee_without_material'] ?? 0);
    $legacyFee  = (float)($course['fees'] ?? $course['final_fee'] ?? 0);
    $hoWith     = (float)($course['ho_share_with_material'] ?? 0);
    $hoWithout  = (float)($course['ho_share_without_material'] ?? 0);
    $legacyHo   = (float)($course['ho_share'] ?? 0);

    // Legacy fallback: single final_fee mapped by old material_type
    if ($feeWith <= 0 && $feeWithout <= 0 && $legacyFee > 0) {
        if (($course['material_type'] ?? '') === 'With Material') {
            $feeWith = $legacyFee;
        } else {
            $feeWithout = $legacyFee;
        }
    }

    // Legacy HO share: only when dual HO columns were never set
    if ($hoWith <= 0 && $hoWithout <= 0 && $legacyHo > 0) {
        if (($course['material_type'] ?? '') === 'With Material') {
            $hoWith = $legacyHo;
        } else {
            $hoWithout = $legacyHo;
        }
    }

    $options = [];
    $suffix  = $duration !== '' ? " ({$duration})" : '';

    // Offer With Material only when ATC fee AND HO share for that option are both set (> 0)
    if ($feeWith > 0 && $hoWith > 0) {
        $options[] = [
            'label'         => $name . $suffix . ' — With Material (₹' . number_format($feeWith, 0) . ')',
            'course_name'   => $name,
            'material_type' => 'With Material',
            'fee'           => $feeWith,
            'language'      => $lang ?: 'English',
            'course_type'   => $type,
            'duration'      => $duration,
        ];
    }
    // Offer Without Material only when ATC fee AND HO share for that option are both set (> 0)
    if ($feeWithout > 0 && $hoWithout > 0) {
        $options[] = [
            'label'         => $name . $suffix . ' — Without Material (₹' . number_format($feeWithout, 0) . ')',
            'course_name'   => $name,
            'material_type' => 'Without Material',
            'fee'           => $feeWithout,
            'language'      => $lang ?: 'English',
            'course_type'   => $type,
            'duration'      => $duration,
        ];
    }

    return $options;
}

// ─────────────────────────────────────────────────────────────────────────────
// PAGINATION (server-side)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Read page/per_page from the query string.
 *
 * @param string $pageKey Query key for page number (use different keys when
 *                        multiple pagers share one page, e.g. atc_page / page).
 * @return array{page:int,per_page:int,offset:int,page_key:string}
 */
function paginationParams(int $defaultPerPage = 25, int $maxPerPage = 100, string $pageKey = 'page'): array {
    $page    = max(1, (int)($_GET[$pageKey] ?? 1));
    $perPage = (int)($_GET['per_page'] ?? $defaultPerPage);
    if ($perPage < 5) {
        $perPage = $defaultPerPage;
    }
    if ($perPage > $maxPerPage) {
        $perPage = $maxPerPage;
    }
    return [
        'page'     => $page,
        'per_page' => $perPage,
        'offset'   => ($page - 1) * $perPage,
        'page_key' => $pageKey,
    ];
}

/**
 * Build pagination meta from a total row count.
 *
 * @return array{page:int,per_page:int,offset:int,total:int,total_pages:int,from:int,to:int,page_key:string}
 */
function paginationMeta(int $total, ?array $params = null): array {
    $params = $params ?? paginationParams();
    $totalPages = max(1, (int)ceil($total / max(1, $params['per_page'])));
    $page = min($params['page'], $totalPages);
    $offset = ($page - 1) * $params['per_page'];
    $from = $total === 0 ? 0 : $offset + 1;
    $to   = min($total, $offset + $params['per_page']);

    return [
        'page'        => $page,
        'per_page'    => $params['per_page'],
        'offset'      => $offset,
        'total'       => $total,
        'total_pages' => $totalPages,
        'from'        => $from,
        'to'          => $to,
        'page_key'    => $params['page_key'] ?? 'page',
    ];
}

/**
 * Build a page URL while preserving current query params.
 */
function paginationUrl(int $page, array $extra = [], string $pageKey = 'page'): string {
    $query = array_merge($_GET, $extra, [$pageKey => $page]);
    // Drop empty noise
    foreach ($query as $k => $v) {
        if ($v === '' || $v === null) {
            unset($query[$k]);
        }
    }
    $qs = http_build_query($query);
    $path = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '';
    return $path . ($qs !== '' ? '?' . $qs : '');
}

/**
 * Render a compact pagination bar. Safe to echo directly.
 * Styles are injected once inline so every page looks correct
 * even if CSS files are cached or missing on the host.
 */
function renderPagination(array $meta, string $itemLabel = 'records'): string {
    if (($meta['total'] ?? 0) <= 0) {
        return '';
    }

    $page       = (int)$meta['page'];
    $totalPages = (int)$meta['total_pages'];
    $from       = (int)$meta['from'];
    $to         = (int)$meta['to'];
    $total      = (int)$meta['total'];
    $pageKey    = (string)($meta['page_key'] ?? 'page');

    static $pagerCssPrinted = false;
    $html = '';
    if (!$pagerCssPrinted) {
        $pagerCssPrinted = true;
        $html .= <<<'CSS'
<style id="gyanam-pager-css">
.pager{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-top:1rem;padding:.9rem 1.1rem;background:#fff;border:1.5px solid #e5e7eb;border-radius:12px;box-sizing:border-box}
.pager-info{font-size:.8rem;color:#6b7280;font-weight:600;line-height:1.4}
.pager-info strong{color:#1f2937;font-weight:800}
.pager-controls{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;margin-left:auto}
.pager .pager-btn,a.pager-btn,span.pager-btn{display:inline-flex!important;align-items:center;justify-content:center;min-width:36px;height:36px;padding:0 .75rem;border-radius:9px;border:1.5px solid #e5e7eb;background:#fff;color:#374151!important;font-size:.78rem;font-weight:700;text-decoration:none!important;font-family:inherit;line-height:1;box-sizing:border-box;cursor:pointer;transition:border-color .15s,background .15s,color .15s,box-shadow .15s}
a.pager-btn:hover{border-color:#a5b4fc!important;background:#eef2ff!important;color:#3730a3!important}
.pager .pager-btn.active,span.pager-btn.active{background:linear-gradient(135deg,#4361ee,#3730a3)!important;border-color:#3730a3!important;color:#fff!important;box-shadow:0 3px 10px rgba(67,97,238,.25);cursor:default}
.pager .pager-btn.disabled,span.pager-btn.disabled{opacity:.42;cursor:not-allowed;pointer-events:none;background:#f8fafc!important;color:#9ca3af!important;border-color:#e5e7eb!important}
.pager-ellipsis{color:#9ca3af;font-weight:700;padding:0 .15rem;user-select:none}
@media (max-width:640px){.pager{justify-content:center}.pager-info{width:100%;text-align:center}.pager-controls{margin-left:0;justify-content:center}}
</style>
CSS;
    }

    $html .= '<div class="pager" role="navigation" aria-label="Pagination">';
    $html .= '<div class="pager-info">Showing <strong>' . $from . '–' . $to . '</strong> of <strong>' . number_format($total) . '</strong> ' . htmlspecialchars($itemLabel) . '</div>';
    $html .= '<div class="pager-controls">';

    // Prev
    if ($page <= 1) {
        $html .= '<span class="pager-btn disabled" aria-disabled="true">‹ Prev</span>';
    } else {
        $html .= '<a class="pager-btn" href="' . htmlspecialchars(paginationUrl($page - 1, [], $pageKey)) . '">‹ Prev</a>';
    }

    // Page window (max ~7 numbers)
    $window = 2;
    $start  = max(1, $page - $window);
    $end    = min($totalPages, $page + $window);
    if ($start > 1) {
        $html .= '<a class="pager-btn" href="' . htmlspecialchars(paginationUrl(1, [], $pageKey)) . '">1</a>';
        if ($start > 2) {
            $html .= '<span class="pager-ellipsis">…</span>';
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        if ($i === $page) {
            $html .= '<span class="pager-btn active" aria-current="page">' . $i . '</span>';
        } else {
            $html .= '<a class="pager-btn" href="' . htmlspecialchars(paginationUrl($i, [], $pageKey)) . '">' . $i . '</a>';
        }
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<span class="pager-ellipsis">…</span>';
        }
        $html .= '<a class="pager-btn" href="' . htmlspecialchars(paginationUrl($totalPages, [], $pageKey)) . '">' . $totalPages . '</a>';
    }

    // Next
    if ($page >= $totalPages) {
        $html .= '<span class="pager-btn disabled" aria-disabled="true">Next ›</span>';
    } else {
        $html .= '<a class="pager-btn" href="' . htmlspecialchars(paginationUrl($page + 1, [], $pageKey)) . '">Next ›</a>';
    }

    $html .= '</div></div>';
    return $html;
}

// ─────────────────────────────────────────────────────────────────────────────
// AUTH CERTIFICATES (Abacus/Vedic vs IT by ATC center_type)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Which authorization certificate variants an ATC should receive.
 *
 * Rules:
 *  - Abacus and/or Vedic Maths only → Abacus (Gyanam) cert
 *  - IT only → IT (GIIT) cert
 *  - Any mix that includes IT + (Abacus|Vedic) → both certs
 *
 * @return list<array{variant:string,label:string,brand:string,course_line:string,code_prefix:string}>
 */
function atcAuthCertificateVariants(?string $centerType): array {
    $t = strtolower(trim((string)$centerType));
    $t = str_replace(['_', '/'], ['+', '+'], $t);
    $t = preg_replace('/\s+/', ' ', $t) ?? $t;

    $hasAbacus = str_contains($t, 'abacus');
    $hasVedic  = str_contains($t, 'vedic');
    $hasIt     = (bool)preg_match('/(?<![a-z])it(?![a-z])/', $t);

    // Normalize common labels
    if ($t === 'it' || $t === 'i.t' || $t === 'i.t.') {
        $hasIt = true;
    }

    $variants = [];

    if ($hasAbacus || $hasVedic) {
        $variants[] = [
            'variant'     => 'abacus',
            'label'       => 'Abacus / Vedic Maths Authorization',
            'brand'       => 'Gyanam Abacus',
            'course_line' => 'Conducting our Gyanam Abacus Academy',
            'code_prefix' => 'Gyanam ATC-',
        ];
    }

    if ($hasIt) {
        $variants[] = [
            'variant'     => 'it',
            'label'       => 'IT Authorization',
            'brand'       => 'GIIT',
            'course_line' => 'Conducting our IT Courses',
            'code_prefix' => 'GIIT ATC-',
        ];
    }

    // Unknown type → default IT (legacy behaviour)
    if (!$variants) {
        $variants[] = [
            'variant'     => 'it',
            'label'       => 'IT Authorization',
            'brand'       => 'GIIT',
            'course_line' => 'Conducting our IT Courses',
            'code_prefix' => 'GIIT ATC-',
        ];
    }

    return $variants;
}

/** Absolute path to PDF template for a cert variant, or null if missing. */
function atcAuthCertificateTemplatePath(string $variant): ?string {
    $base = __DIR__ . '/../assets/templates/';
    if ($variant === 'abacus') {
        foreach (['gyanam_abacus_auth_certificate.pdf', 'abacus_auth_certificate.pdf'] as $f) {
            if (is_file($base . $f)) {
                return $base . $f;
            }
        }
        return null;
    }
    // IT / GIIT
    $it = $base . 'giit_auth_certificate.pdf';
    return is_file($it) ? $it : null;
}

/**
 * Brand logo variant for printed forms (admission form, etc.).
 *
 * - Prefer course_type / course name when it clearly signals Abacus/Vedic vs IT
 * - Otherwise: Abacus/Vedic-only → abacus; IT-only → it; both → course then IT default
 *
 * @return 'it'|'abacus'
 */
function admissionFormBrandVariant(?string $centerType, ?string $courseName = null, ?string $courseType = null): string {
    $variants = atcAuthCertificateVariants($centerType);
    $codes = array_column($variants, 'variant');
    $hasIt = in_array('it', $codes, true);
    $hasAbacus = in_array('abacus', $codes, true);

    $hint = strtolower(trim((string)$courseType));
    if ($hint === '' && $courseName !== null && $courseName !== '') {
        $hint = strtolower($courseName);
    }

    $courseIsAbacus = $hint !== '' && (str_contains($hint, 'abacus') || str_contains($hint, 'vedic'));
    $courseIsIt = $hint !== '' && (
        $hint === 'it'
        || (bool)preg_match('/(?<![a-z])it(?![a-z])/', $hint)
        || (bool)preg_match('/\b(ccc|cccp|dca|ms\-?cit|tally|programming|computer|software|hardware|typing|excel|wordpress|python|java|c\+\+|html|css)\b/i', $hint)
    );

    // Dual center (or unknown): follow the course when we can tell
    if ($hasIt && $hasAbacus) {
        if ($courseIsAbacus) {
            return 'abacus';
        }
        if ($courseIsIt) {
            return 'it';
        }
        return 'it';
    }

    // Single-type centers still respect an obvious opposite course signal when both brands exist in variants list
    if ($courseIsAbacus && $hasAbacus) {
        return 'abacus';
    }
    if ($courseIsIt && $hasIt) {
        return 'it';
    }

    if ($hasAbacus && !$hasIt) {
        return 'abacus';
    }

    return 'it';
}

/**
 * Absolute filesystem path to GIIT or Gyanam Abacus logo (for embedding / print).
 */
function admissionFormBrandLogoPath(string $variant): string {
    $baseFs = __DIR__ . '/../assets/';
    $candidates = $variant === 'abacus'
        ? ['gyanam_abacus_logo.png', 'abacus_logo.png', 'logo.png']
        // Prefer cache-busted brand filename first, then legacy name
        : ['giit_brand_logo.png', 'giit_logo.png', 'logo.png'];

    foreach ($candidates as $file) {
        $path = $baseFs . $file;
        if (is_file($path)) {
            return $path;
        }
    }

    return $baseFs . 'logo.png';
}

/**
 * Web-relative path (from atc/ pages) to GIIT or Gyanam Abacus logo.
 * Prefers brand-specific assets; falls back to assets/logo.png.
 * Adds filemtime cache-buster so logo updates show immediately.
 */
function admissionFormBrandLogoUrl(string $variant, string $fromDir = 'atc'): string {
    $prefix = $fromDir === 'atc' ? '../assets/' : ($fromDir === 'admin' ? '../assets/' : 'assets/');
    $path = admissionFormBrandLogoPath($variant);
    $file = basename($path);
    $ver = @filemtime($path) ?: time();
    return $prefix . $file . '?v=' . $ver;
}

/**
 * data: URI for brand logo — reliable in print/PDF (no relative-path fetch).
 */
function admissionFormBrandLogoDataUri(string $variant): string {
    $path = admissionFormBrandLogoPath($variant);
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return admissionFormBrandLogoUrl($variant);
    }
    $mime = 'image/png';
    if (function_exists('mime_content_type')) {
        $detected = @mime_content_type($path);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
    }
    return 'data:' . $mime . ';base64,' . base64_encode($raw);
}

// ─────────────────────────────────────────────────────────────────────────────
// PERFORMANCE HELPERS (schema flags, image optimize, indexes)
// ─────────────────────────────────────────────────────────────────────────────

function schemaFlagPath(string $name): string {
    return __DIR__ . '/../config/.' . ltrim($name, '.');
}

function isSchemaFlagSet(string $name): bool {
    return is_file(schemaFlagPath($name));
}

function markSchemaFlag(string $name): void {
    @file_put_contents(schemaFlagPath($name), date('c') . "\n");
}

/** Allowed franchise payment modes on ATC create/edit. */
function atcFranchisePaymentModes(): array {
    return ['Cash', 'UPI', 'Cheque', 'Bank Transfer', 'Other'];
}

function parseOptionalMoney($raw): ?float {
    if ($raw === '' || $raw === null) {
        return null;
    }
    return (float)$raw;
}

/**
 * Ensure ATC franchise + DLC share columns exist (once via flag).
 */
function ensureAtcFranchisePaymentSchema(PDO $pdo): void {
    if (isSchemaFlagSet('schema_atc_franchise_pay_v2')) {
        return;
    }
    $cols = [
        'franchise_fees'             => 'DECIMAL(12,2) DEFAULT NULL',
        'franchise_amount_received'  => 'DECIMAL(12,2) DEFAULT NULL',
        'franchise_payment_mode'     => 'VARCHAR(30) DEFAULT NULL',
        'franchise_paid_date'        => 'DATE DEFAULT NULL',
        'franchise_payment_ref'      => 'VARCHAR(80) DEFAULT NULL',
        'dlc_share_amount'           => 'DECIMAL(12,2) DEFAULT NULL',
    ];
    try {
        $existing = $pdo->query('SHOW COLUMNS FROM atc_centers')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cols as $col => $def) {
            if (!in_array($col, $existing, true)) {
                $pdo->exec("ALTER TABLE atc_centers ADD COLUMN `$col` $def");
            }
        }
        markSchemaFlag('schema_atc_franchise_pay_v2');
    } catch (Exception $e) {
        error_log('[ATC franchise schema] ' . $e->getMessage());
    }
}

/**
 * Resize/compress an uploaded image in place (or to $destPath).
 * Max edge 1600px; JPEG quality 75. Returns final path on success.
 */
function optimizeUploadedImage(string $srcPath, ?string $destPath = null, int $maxEdge = 1600, int $quality = 75): ?string {
    $destPath = $destPath ?? $srcPath;
    if (!is_file($srcPath) || !function_exists('imagecreatefromjpeg')) {
        return is_file($srcPath) ? $destPath : null;
    }
    $info = @getimagesize($srcPath);
    if (!$info) {
        return $destPath;
    }
    [$w, $h] = $info;
    $type = $info[2] ?? 0;
    $src = null;
    if ($type === IMAGETYPE_JPEG) {
        $src = @imagecreatefromjpeg($srcPath);
    } elseif ($type === IMAGETYPE_PNG) {
        $src = @imagecreatefrompng($srcPath);
    } elseif ($type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
        $src = @imagecreatefromwebp($srcPath);
    } elseif ($type === IMAGETYPE_GIF) {
        $src = @imagecreatefromgif($srcPath);
    }
    if (!$src) {
        return $destPath;
    }

    $scale = 1.0;
    if ($w > $maxEdge || $h > $maxEdge) {
        $scale = min($maxEdge / max(1, $w), $maxEdge / max(1, $h));
    }
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    $ext = strtolower(pathinfo($destPath, PATHINFO_EXTENSION));
    $ok = false;
    if ($ext === 'png' && $type === IMAGETYPE_PNG) {
        $ok = imagepng($dst, $destPath, 6);
    } elseif ($ext === 'webp' && function_exists('imagewebp')) {
        $ok = imagewebp($dst, $destPath, $quality);
    } else {
        // Prefer JPEG for photos / large banners
        if ($ext !== 'jpg' && $ext !== 'jpeg') {
            $destPath = preg_replace('/\.[^.]+$/', '.jpg', $destPath) ?: ($destPath . '.jpg');
        }
        $ok = imagejpeg($dst, $destPath, $quality);
    }
    imagedestroy($dst);
    return $ok ? $destPath : null;
}

/** Cached: does fee_payments have atc_id? */
function feePaymentsHasAtcId(PDO $pdo): bool {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    if (isSchemaFlagSet('schema_fee_payments_atc_id')) {
        $cached = true;
        return true;
    }
    try {
        $has = $pdo->query("SHOW COLUMNS FROM fee_payments LIKE 'atc_id'")->rowCount() > 0;
        if ($has) {
            markSchemaFlag('schema_fee_payments_atc_id');
        }
        $cached = $has;
        return $has;
    } catch (Exception $e) {
        $cached = false;
        return false;
    }
}

function ensureHoShareSnapshotColumn(PDO $pdo): void {
    if (isSchemaFlagSet('schema_ho_share_snapshot')) {
        return;
    }
    try {
        $pdo->exec("ALTER TABLE admissions ADD COLUMN IF NOT EXISTS ho_share_snapshot DECIMAL(10,2) DEFAULT NULL COMMENT 'HO share rate locked at time of admission'");
        markSchemaFlag('schema_ho_share_snapshot');
    } catch (Exception $e) {
        // Column may already exist without IF NOT EXISTS support
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM admissions LIKE 'ho_share_snapshot'")->fetch();
            if ($cols) {
                markSchemaFlag('schema_ho_share_snapshot');
            }
        } catch (Exception $e2) {}
    }
}

/**
 * Ensure share payment schema: Failed/Cancelled statuses + admissions.ho_share_paid flag.
 */
function ensureSharePaymentSchema(PDO $pdo): void {
    if (isSchemaFlagSet('schema_share_payment_flow_v2')) {
        return;
    }
    try {
        // Widen status to include Failed / Cancelled (idempotent-ish)
        try {
            $pdo->exec("ALTER TABLE share_payments MODIFY COLUMN status ENUM('Pending','Completed','Failed','Cancelled') NOT NULL DEFAULT 'Pending'");
        } catch (Exception $e) {
            // Table may use VARCHAR already
        }

        // Optional notes / failure reason
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM share_payments LIKE 'failure_reason'")->fetch();
            if (!$cols) {
                $pdo->exec("ALTER TABLE share_payments ADD COLUMN failure_reason VARCHAR(255) DEFAULT NULL AFTER status");
            }
        } catch (Exception $e) {}

        // Offline / cash recording fields
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM share_payments LIKE 'payment_mode'")->fetch();
            if (!$cols) {
                $pdo->exec("ALTER TABLE share_payments ADD COLUMN payment_mode VARCHAR(32) NOT NULL DEFAULT 'Online' AFTER status");
            }
        } catch (Exception $e) {}
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM share_payments LIKE 'remarks'")->fetch();
            if (!$cols) {
                $pdo->exec("ALTER TABLE share_payments ADD COLUMN remarks VARCHAR(500) DEFAULT NULL AFTER failure_reason");
            }
        } catch (Exception $e) {}

        // Denormalized paid flag on admissions
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM admissions LIKE 'ho_share_paid'")->fetch();
            if (!$cols) {
                $pdo->exec("ALTER TABLE admissions ADD COLUMN ho_share_paid TINYINT(1) NOT NULL DEFAULT 0");
            }
        } catch (Exception $e) {}
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM admissions LIKE 'share_payment_date'")->fetch();
            if (!$cols) {
                $pdo->exec("ALTER TABLE admissions ADD COLUMN share_payment_date DATETIME DEFAULT NULL");
            }
        } catch (Exception $e) {}

        markSchemaFlag('schema_share_payment_flow_v1');
        markSchemaFlag('schema_share_payment_flow_v2');
        // Clear conflicting "missing" probe flags from older code
        @unlink(schemaFlagPath('schema_ho_share_paid_missing'));
        markSchemaFlag('schema_ho_share_paid_col');
    } catch (Exception $e) {
        error_log('ensureSharePaymentSchema: ' . $e->getMessage());
    }
}

/**
 * Build course → HO share amount map (with/without material keys).
 * @return array{map: array<string,float>, default: float}
 */
function buildHoShareAmountMap(PDO $pdo, ?int $atcId = null): array {
    ensureDualMaterialCourseSchema($pdo);
    $normalizedShareMap = [];
    $defaultShareAmount = 0.0;

    $rows = [];
    try {
        if ($atcId) {
            $shareStmt = $pdo->prepare("
                SELECT DISTINCT c.course_name,
                       c.ho_share, c.ho_share_with_material, c.ho_share_without_material, c.material_type
                FROM courses c
                WHERE c.status = 'Active'
                  AND EXISTS (
                      SELECT 1 FROM atc_course_fees acf
                      WHERE acf.course_id = c.id AND acf.atc_id = ?
                  )
                ORDER BY c.course_name ASC
            ");
            $shareStmt->execute([$atcId]);
            $rows = $shareStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if (empty($rows)) {
            $rows = $pdo->query("
                SELECT course_name, ho_share, ho_share_with_material, ho_share_without_material, material_type
                FROM courses WHERE status = 'Active' ORDER BY course_name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        try {
            $rows = $pdo->query("SELECT course_name, ho_share FROM courses WHERE status = 'Active'")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e2) {
            $rows = [];
        }
    }

    foreach ($rows as $row) {
        $name = trim((string)($row['course_name'] ?? ''));
        if ($name === '') continue;
        $with    = max(0, (float)($row['ho_share_with_material'] ?? 0));
        $without = max(0, (float)($row['ho_share_without_material'] ?? 0));
        $legacy  = max(0, (float)($row['ho_share'] ?? 0));
        if ($with <= 0 && $without <= 0 && $legacy > 0) {
            if (($row['material_type'] ?? '') === 'With Material') $with = $legacy;
            else $without = $legacy;
        }
        $displayShare = $without > 0 ? $without : $with;
        $key = mb_strtolower($name);
        $normalizedShareMap[$key] = $displayShare;
        $normalizedShareMap[$key . '|with material']    = $with > 0 ? $with : $displayShare;
        $normalizedShareMap[$key . '|without material'] = $without > 0 ? $without : $displayShare;
        if ($key === 'other') {
            $defaultShareAmount = $displayShare;
        }
    }

    return ['map' => $normalizedShareMap, 'default' => $defaultShareAmount];
}

/**
 * Resolve HO share for one admission (snapshot preferred).
 */
function resolveAdmissionHoShareAmount(
    string $courseName,
    array $normalizedShareMap,
    float $defaultShareAmount,
    $snapshot = null,
    ?string $materialType = null
): float {
    if ($snapshot !== null && (float)$snapshot > 0) {
        return (float)$snapshot;
    }
    $key = mb_strtolower(trim($courseName));
    if ($materialType) {
        $matKey = $key . '|' . mb_strtolower(trim($materialType));
        if ($matKey !== '' && array_key_exists($matKey, $normalizedShareMap)) {
            return (float)$normalizedShareMap[$matKey];
        }
    }
    if ($key !== '' && array_key_exists($key, $normalizedShareMap)) {
        return (float)$normalizedShareMap[$key];
    }
    return (float)$defaultShareAmount;
}

/**
 * Admin: record an offline (cash/bank/UPI) HO share payment for selected admissions.
 *
 * @param int[] $admissionIds
 * @return array{success:bool,message:string,payment_id?:int}
 */
function recordOfflineSharePayment(
    PDO $pdo,
    int $atcId,
    array $admissionIds,
    string $paymentMode = 'Cash',
    ?string $referenceNo = null,
    ?string $remarks = null,
    ?string $paidAt = null
): array {
    ensureSharePaymentSchema($pdo);

    $allowedModes = ['Cash', 'Bank Transfer', 'UPI', 'Cheque'];
    if (!in_array($paymentMode, $allowedModes, true)) {
        $paymentMode = 'Cash';
    }

    $admissionIds = array_values(array_unique(array_map('intval', $admissionIds)));
    $admissionIds = array_values(array_filter($admissionIds, fn($id) => $id > 0));
    if ($atcId <= 0 || empty($admissionIds)) {
        return ['success' => false, 'message' => 'Select an ATC and at least one unpaid student.'];
    }

    // Already-paid admissions for this ATC
    $paidMap = [];
    try {
        $sp = $pdo->prepare("SELECT student_ids FROM share_payments WHERE atc_id = ? AND status = 'Completed'");
        $sp->execute([$atcId]);
        foreach ($sp->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $ids = json_decode((string)$json, true);
            if (is_array($ids)) {
                foreach ($ids as $id) {
                    $paidMap[(int)$id] = true;
                }
            }
        }
    } catch (Exception $e) {}

    $sharePack = buildHoShareAmountMap($pdo, $atcId);
    $map = $sharePack['map'];
    $defaultShare = $sharePack['default'];

    $placeholders = implode(',', array_fill(0, count($admissionIds), '?'));
    $stmt = $pdo->prepare("
        SELECT id, course, material_type, COALESCE(ho_share_snapshot, 0) AS ho_share_snapshot
        FROM admissions
        WHERE atc_id = ? AND status = 'Active' AND id IN ($placeholders)
    ");
    $stmt->execute(array_merge([$atcId], $admissionIds));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== count($admissionIds)) {
        return ['success' => false, 'message' => 'One or more students are invalid for this ATC.'];
    }

    $validIds = [];
    $totalShare = 0.0;
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        if (isset($paidMap[$id])) {
            return ['success' => false, 'message' => 'Student admission #' . $id . ' is already share-paid.'];
        }
        $snap = ((float)$row['ho_share_snapshot'] > 0) ? (float)$row['ho_share_snapshot'] : null;
        $amt = resolveAdmissionHoShareAmount(
            (string)$row['course'],
            $map,
            $defaultShare,
            $snap,
            $row['material_type'] ?? null
        );
        $totalShare += $amt;
        $validIds[] = $id;
    }

    if ($totalShare <= 0) {
        return ['success' => false, 'message' => 'Total share amount must be greater than zero.'];
    }

    $refNote = trim((string)$referenceNo);
    $remarkText = trim((string)$remarks);
    $parts = array_filter([
        $paymentMode . ' (offline)',
        $refNote !== '' ? 'Ref: ' . $refNote : null,
        $remarkText !== '' ? $remarkText : null,
    ]);
    $combinedRemarks = implode(' · ', $parts);

    $paidAtSql = null;
    if ($paidAt && preg_match('/^\d{4}-\d{2}-\d{2}/', $paidAt)) {
        $paidAtSql = date('Y-m-d H:i:s', strtotime($paidAt));
    }

    try {
        $pdo->beginTransaction();
        $ins = $pdo->prepare("
            INSERT INTO share_payments
                (atc_id, student_ids, total_share_amount, transaction_fee, total_amount, status, payment_mode, remarks, created_at)
            VALUES (?, ?, ?, 0, ?, 'Pending', ?, ?, NOW())
        ");
        $ins->execute([
            $atcId,
            json_encode($validIds),
            $totalShare,
            $totalShare,
            $paymentMode,
            $combinedRemarks !== '' ? $combinedRemarks : null,
        ]);
        $paymentId = (int)$pdo->lastInsertId();
        $cashRef = 'CASH-' . $paymentId . ($refNote !== '' ? '-' . preg_replace('/[^A-Za-z0-9]/', '', substr($refNote, 0, 12)) : '');
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Retry without payment_mode/remarks if columns missing on old DB
        try {
            $ins = $pdo->prepare("
                INSERT INTO share_payments
                    (atc_id, student_ids, total_share_amount, transaction_fee, total_amount, status, failure_reason, created_at)
                VALUES (?, ?, ?, 0, ?, 'Pending', ?, NOW())
            ");
            $ins->execute([
                $atcId,
                json_encode($validIds),
                $totalShare,
                $totalShare,
                $combinedRemarks !== '' ? $combinedRemarks : null,
            ]);
            $paymentId = (int)$pdo->lastInsertId();
            $cashRef = 'CASH-' . $paymentId;
        } catch (Exception $e2) {
            return ['success' => false, 'message' => $e2->getMessage()];
        }
    }

    $done = completeSharePayment($pdo, $paymentId, $cashRef, null, null);
    if (!$done['success']) {
        return ['success' => false, 'message' => $done['message'] ?? 'Could not complete payment.'];
    }

    if ($paidAtSql) {
        try {
            $pdo->prepare("UPDATE share_payments SET paid_at = ? WHERE id = ?")->execute([$paidAtSql, $paymentId]);
            $pdo->prepare("
                UPDATE admissions SET share_payment_date = ?
                WHERE atc_id = ? AND id IN ($placeholders)
            ")->execute(array_merge([$paidAtSql, $atcId], $validIds));
        } catch (Exception $e) {}
    }

    return [
        'success' => true,
        'message' => 'Cash/offline share payment recorded for ' . count($validIds) . ' student(s).',
        'payment_id' => $paymentId,
        'total_share_amount' => $totalShare,
    ];
}

/**
 * Mark a share_payment Completed and set ho_share_paid on linked admissions.
 * Idempotent: safe if already Completed.
 *
 * @return array{success:bool,message:string,already_done?:bool}
 */
function completeSharePayment(
    PDO $pdo,
    int $paymentId,
    ?string $razorpayPaymentId = null,
    ?string $razorpayOrderId = null,
    ?string $razorpaySignature = null
): array {
    ensureSharePaymentSchema($pdo);

    $stmt = $pdo->prepare("SELECT * FROM share_payments WHERE id = ? LIMIT 1");
    $stmt->execute([$paymentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['success' => false, 'message' => 'Payment record not found'];
    }

    if (($row['status'] ?? '') === 'Completed') {
        // Still ensure admissions flags in case of partial prior write
        applyHoSharePaidForPayment($pdo, $row);
        return ['success' => true, 'message' => 'Already completed', 'already_done' => true];
    }

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare("
            UPDATE share_payments
            SET status = 'Completed',
                razorpay_payment_id = COALESCE(?, razorpay_payment_id),
                razorpay_order_id   = COALESCE(?, razorpay_order_id),
                razorpay_signature  = COALESCE(?, razorpay_signature),
                paid_at = COALESCE(paid_at, NOW()),
                failure_reason = NULL
            WHERE id = ? AND status IN ('Pending','Failed','Cancelled')
        ");
        $upd->execute([
            $razorpayPaymentId ?: null,
            $razorpayOrderId ?: null,
            $razorpaySignature ?: null,
            $paymentId,
        ]);

        $row['status'] = 'Completed';
        applyHoSharePaidForPayment($pdo, $row);
        $pdo->commit();
        return ['success' => true, 'message' => 'Payment verified and recorded successfully'];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Set admissions.ho_share_paid from share_payments.student_ids JSON.
 */
function applyHoSharePaidForPayment(PDO $pdo, array $paymentRow): void {
    $ids = json_decode((string)($paymentRow['student_ids'] ?? '[]'), true);
    if (!is_array($ids) || empty($ids)) {
        return;
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_filter($ids, fn($id) => $id > 0);
    if (empty($ids)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    // Scope to ATC when available
    $sql = "UPDATE admissions SET ho_share_paid = 1, share_payment_date = COALESCE(share_payment_date, NOW()) WHERE id IN ($placeholders)";
    if (!empty($paymentRow['atc_id'])) {
        $sql .= ' AND atc_id = ?';
        $params[] = (int)$paymentRow['atc_id'];
    }
    try {
        $pdo->prepare($sql)->execute($params);
    } catch (Exception $e) {
        // Column may not exist yet on very old DBs
        error_log('applyHoSharePaidForPayment: ' . $e->getMessage());
    }
}

/**
 * Mark share payment Failed or Cancelled (only from Pending).
 */
function markSharePaymentStatus(PDO $pdo, int $paymentId, string $status, ?int $atcId = null, ?string $reason = null): array {
    ensureSharePaymentSchema($pdo);
    $status = in_array($status, ['Failed', 'Cancelled'], true) ? $status : 'Failed';

    $sql = "UPDATE share_payments SET status = ?, failure_reason = ? WHERE id = ? AND status = 'Pending'";
    $params = [$status, $reason, $paymentId];
    if ($atcId) {
        $sql .= ' AND atc_id = ?';
        $params[] = $atcId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return [
        'success' => true,
        'message' => $stmt->rowCount() ? "Marked $status" : 'No pending payment to update',
        'updated' => $stmt->rowCount() > 0,
    ];
}

/**
 * Course completion exam grade from score (0–100).
 * Matches GIIT course certificate footer: A++ 90+, A+ 80–89, A 66–79, B 55–65, C 40–54.
 */
function courseExamGradeFromScore(int $score): string {
    if ($score >= 90) {
        return 'A++';
    }
    if ($score >= 80) {
        return 'A+';
    }
    if ($score >= 66) {
        return 'A';
    }
    if ($score >= 55) {
        return 'B';
    }
    if ($score >= 40) {
        return 'C';
    }
    return 'Fail';
}

/** Whether admission row has a usable passport photo on file. */
function admissionHasPhoto(array $student): bool
{
    return !empty($student['photo']) && trim((string)$student['photo']) !== '';
}

/**
 * HO share paid via Completed share_payments and/or admissions.ho_share_paid.
 */
function admissionHasHoSharePaid(PDO $pdo, int $admissionId, ?int $atcId = null, ?array $studentRow = null): bool
{
    ensureSharePaymentSchema($pdo);
    if ($studentRow !== null && !empty($studentRow['ho_share_paid'])) {
        return true;
    }
    $paidMap = getHoSharePaidAdmissionIds($pdo, $atcId);
    if (isset($paidMap[$admissionId])) {
        return true;
    }
    if ($studentRow !== null) {
        return false;
    }
    try {
        $st = $pdo->prepare('SELECT COALESCE(ho_share_paid, 0) FROM admissions WHERE id = ? LIMIT 1');
        $st->execute([$admissionId]);
        return (bool)$st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Validate local + exam-portal requirements before issuing a GIIT course certificate.
 *
 * @return array{eligible:bool,message:string,exam:?array}
 */
function validateCourseCertificateRequest(PDO $pdo, array $student, string $role): array
{
    if (!function_exists('examIntegrationReady')) {
        $examFile = __DIR__ . '/exam_integration.php';
        if (is_file($examFile)) {
            require_once $examFile;
        }
    }

    $atcRole = ($role === 'ATC CENTER');
    $admissionId = (int)($student['id'] ?? 0);
    $atcId = (int)($student['atc_id'] ?? 0);

    if ($atcRole && !admissionHasHoSharePaid($pdo, $admissionId, $atcId ?: null, $student)) {
        return [
            'eligible' => false,
            'message'  => 'HO share payment is required before a certificate can be issued. Complete Pay Share first.',
            'exam'     => null,
        ];
    }

    if ($atcRole && !admissionHasPhoto($student)) {
        return [
            'eligible' => false,
            'message'  => 'Upload the student photo before generating the certificate.',
            'exam'     => null,
        ];
    }

    if (!function_exists('examIntegrationReady') || !examIntegrationReady()) {
        return [
            'eligible' => false,
            'message'  => 'Exam portal is not connected. Certificate cannot be verified against exam results.',
            'exam'     => null,
        ];
    }

    $regId = trim((string)($student['registration_id'] ?? ''));
    if ($regId === '') {
        $regId = trim((string)($student['roll_no'] ?? ''));
    }
    if ($regId === '') {
        return [
            'eligible' => false,
            'message'  => 'Student registration ID is missing.',
            'exam'     => null,
        ];
    }

    $examPass = fetchStudentPassingExamResult($regId);
    if (!$examPass) {
        return [
            'eligible' => false,
            'message'  => 'No passing main exam result found in the Exam Portal for this student.',
            'exam'     => null,
        ];
    }

    if ($examPass['score'] < 40) {
        return [
            'eligible' => false,
            'message'  => 'Exam score is below the minimum passing grade (40%).',
            'exam'     => null,
        ];
    }

    return ['eligible' => true, 'message' => 'OK', 'exam' => $examPass];
}

/**
 * Course completion certificate brand: IT → GIIT, Abacus/Vedic → Gyanam Abacus.
 * Prefers the master course type; falls back to ATC center type / course name.
 *
 * @return 'it'|'abacus'
 */
function courseCertificateBrand(?string $courseType, ?string $centerType = null, ?string $courseName = null): string {
    $ct = strtolower(trim((string)$courseType));
    if ($ct === 'abacus' || str_contains($ct, 'vedic')) {
        return 'abacus';
    }
    if ($ct === 'it') {
        return 'it';
    }
    return admissionFormBrandVariant($centerType, $courseName, $courseType);
}

/**
 * Probe a PDF (FPDI) or PNG raster as the certificate background.
 *
 * @return array{type:'pdf'|'png', path:string, width:float, height:float}|null
 */
function courseCertificateTemplateFromFiles(string $pdfPath, string $pngPath): ?array {
    if (is_file($pdfPath)) {
        if (!class_exists(\setasign\Fpdi\Fpdi::class, false)) {
            $autoload = __DIR__ . '/../assets/fpdi/fpdi_autoload.php';
            if (is_file($autoload)) {
                require_once $autoload;
            }
        }
        if (class_exists(\setasign\Fpdi\Fpdi::class)) {
            try {
                $probe = new \setasign\Fpdi\Fpdi();
                $probe->setSourceFile($pdfPath);
                $tplId = $probe->importPage(1);
                $size  = $probe->getTemplateSize($tplId);
                return [
                    'type'   => 'pdf',
                    'path'   => $pdfPath,
                    'width'  => (float)$size['width'],
                    'height' => (float)$size['height'],
                ];
            } catch (\Throwable $e) {
                // PDF 1.5+ / compressed xref — fall back to PNG
            }
        }
    }

    if (is_file($pngPath)) {
        return ['type' => 'png', 'path' => $pngPath, 'width' => 210.0, 'height' => 298.0];
    }

    return is_file($pdfPath) ? ['type' => 'pdf', 'path' => $pdfPath, 'width' => 210.0, 'height' => 298.0] : null;
}

/**
 * Resolve course certificate background for GIIT (IT) or Gyanam Abacus.
 *
 * @param 'it'|'abacus' $variant
 * @return array{type:'pdf'|'png', path:string, width:float, height:float}|null
 */
function courseCertificateTemplateBackground(string $variant = 'it'): ?array {
    $base = __DIR__ . '/../assets/templates/';
    if ($variant === 'abacus') {
        foreach ([
            ['gyanam_abacus_course_certificate.pdf', 'gyanam_abacus_course_certificate.png'],
            ['gyanam_course_certificate.pdf', 'gyanam_course_certificate.png'],
        ] as [$pdf, $png]) {
            $found = courseCertificateTemplateFromFiles($base . $pdf, $base . $png);
            if ($found) {
                return $found;
            }
        }
        return null;
    }

    return courseCertificateTemplateFromFiles(
        $base . 'giit_course_certificate.pdf',
        $base . 'giit_course_certificate.png'
    );
}

/**
 * Drawn Gyanam Abacus completion certificate frame (used when no official PDF/PNG is uploaded).
 * Field positions match GIIT overlay coordinates in generate_course_certificate.php.
 */
function gyanamAbacusCourseCertificateDrawFrame($pdf, float $W, float $H): void {
    $red   = [196, 30, 58];
    $green = [22, 140, 62];
    $navy  = [30, 40, 70];
    $muted = [90, 90, 90];

    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect(0, 0, $W, $H, 'F');

    $pdf->SetDrawColor($red[0], $red[1], $red[2]);
    $pdf->SetLineWidth(1.4);
    $pdf->Rect(8, 8, $W - 16, $H - 16);
    $pdf->SetDrawColor($green[0], $green[1], $green[2]);
    $pdf->SetLineWidth(0.45);
    $pdf->Rect(11, 11, $W - 22, $H - 22);

    $logo = admissionFormBrandLogoPath('abacus');
    if (is_file($logo)) {
        try {
            $pdf->Image($logo, ($W - 62) / 2, 16, 62);
        } catch (\Throwable $e) {
            // skip logo
        }
    }

    $pdf->SetTextColor($muted[0], $muted[1], $muted[2]);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetXY(0, 38);
    $pdf->Cell($W, 4, 'Reg. under Udyam (MSME) No. MH-14-0160225', 0, 0, 'C');

    $pdf->SetTextColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetXY(0, 44);
    $pdf->Cell($W, 5, 'GYANAM INDIA EDUCATIONAL SERVICES', 0, 0, 'C');

    $pdf->SetTextColor($red[0], $red[1], $red[2]);
    $pdf->SetFont('Times', 'B', 20);
    $pdf->SetXY(0, 51);
    $pdf->Cell($W, 8, 'Gyanam Abacus Academy', 0, 0, 'C');

    $pdf->SetTextColor($green[0], $green[1], $green[2]);
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetXY(0, 59);
    $pdf->Cell($W, 5, 'Abacus & Vedic Maths', 0, 0, 'C');

    $pdf->SetFillColor($red[0], $red[1], $red[2]);
    $pdf->Rect(28, 70, $W - 56, 16, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Times', 'BI', 22);
    $pdf->SetXY(0, 73);
    $pdf->Cell($W, 10, 'Certificate', 0, 0, 'C');

    $pdf->SetTextColor(40, 40, 40);
    $pdf->SetFont('Times', 'I', 13);
    $pdf->SetXY(0, 96);
    $pdf->Cell($W, 6, 'This is to certify That', 0, 0, 'C');

    $pdf->SetXY(0, 136);
    $pdf->Cell($W, 6, 'Has Successfully completed', 0, 0, 'C');

    $pdf->SetXY(0, 153);
    $pdf->Cell($W, 6, 'Conducted at', 0, 0, 'C');

    // Photo frame (student photo is overlaid at 148, 118)
    $pdf->SetDrawColor(40, 40, 40);
    $pdf->SetLineWidth(0.35);
    $pdf->Rect(148, 118, 32, 38);

    $pdf->SetDrawColor(180, 180, 180);
    $pdf->SetLineWidth(0.3);
    $pdf->Line(48, 232, 90, 232);
    $pdf->Line($W - 90, 232, $W - 48, 232);

    $pdf->SetTextColor($red[0], $red[1], $red[2]);
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetXY(48, 234);
    $pdf->Cell(42, 4, 'Authorized Signatory', 0, 0, 'C');
    $pdf->SetXY($W - 90, 234);
    $pdf->Cell(42, 4, 'Authorized Signatory', 0, 0, 'C');
    $pdf->SetTextColor(40, 40, 40);
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetXY(0, 239);
    $pdf->Cell($W, 4, 'for GYANAM INDIA EDUCATIONAL SERVICES', 0, 0, 'C');

    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetXY(16, 247);
    $pdf->Cell(24, 4, 'Certificate No. :', 0, 0, 'L');
    $pdf->SetXY(16, 255);
    $pdf->Cell(24, 4, 'Date of issue :', 0, 0, 'L');

    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetXY(0, 272);
    $pdf->Cell($W, 4, '(Grade : A++: 90 & above, A+ : 80 to 89, A : 66 to 79, B : 55 to 65, C : 40 to 54)', 0, 0, 'C');
}

/** Active dashboard banners — lean columns, capped. $audience = ATC|DLC */
function getActiveAnnouncements(PDO $pdo, string $audience, int $limit = 8): array {
    $audience = strtoupper($audience) === 'DLC' ? 'DLC' : 'ATC';
    try {
        $stmt = $pdo->prepare("
            SELECT id, title, image_path, orientation, target_audience
            FROM announcements
            WHERE status = 'Active' AND target_audience IN ('All', ?)
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $audience);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * One-time helpful indexes for hot filters. Safe to call from dashboards.
 */
function ensurePerformanceIndexes(PDO $pdo): void {
    if (isSchemaFlagSet('schema_perf_indexes_v1')) {
        return;
    }
    $indexes = [
        ['admissions', 'idx_adm_atc_status', 'atc_id, status'],
        ['admissions', 'idx_adm_status', 'status'],
        ['notification_reads', 'idx_nr_user_notif', 'user_id, notification_id'],
        ['notifications', 'idx_notif_target', 'target_type, target_id'],
        ['share_payments', 'idx_sp_atc_status', 'atc_id, status'],
        ['fee_payments', 'idx_fp_adm', 'admission_id'],
        ['announcements', 'idx_ann_status_aud', 'status, target_audience'],
        ['atc_centers', 'idx_atc_dlc', 'dlc_id'],
    ];
    try {
        foreach ($indexes as [$table, $name, $cols]) {
            $exists = $pdo->prepare("
                SELECT 1 FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
                LIMIT 1
            ");
            $exists->execute([$table, $name]);
            if ($exists->fetchColumn()) {
                continue;
            }
            try {
                $pdo->exec("CREATE INDEX `{$name}` ON `{$table}` ({$cols})");
            } catch (Exception $e) {
                // ignore duplicate / permission
            }
        }
        markSchemaFlag('schema_perf_indexes_v1');
    } catch (Exception $e) {
        error_log('[PerfIndexes] ' . $e->getMessage());
    }
}

function ensureDuplicateCertTable(PDO $pdo): void {
    if (isSchemaFlagSet('schema_duplicate_cert_v1')) {
        return;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `duplicate_cert_requests` (
            `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `atc_id`       INT NOT NULL,
            `admission_id` INT NOT NULL,
            `student_name` VARCHAR(200) NOT NULL,
            `roll_no`      VARCHAR(50) DEFAULT NULL,
            `course`       VARCHAR(200) DEFAULT NULL,
            `cert_type`    ENUM('Course Completion Certificate','Exam Certificate') NOT NULL,
            `reason`       ENUM('Name Correction','Misplaced by Student','Damaged') NOT NULL,
            `remarks`      TEXT DEFAULT NULL,
            `status`       ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
            `admin_note`   TEXT DEFAULT NULL,
            `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `reviewed_at`  DATETIME DEFAULT NULL,
            `reviewed_by`  INT DEFAULT NULL,
            INDEX `idx_atc` (`atc_id`),
            INDEX `idx_admission` (`admission_id`),
            INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        markSchemaFlag('schema_duplicate_cert_v1');
    } catch (Exception $e) {
        error_log('[DupCertSchema] ' . $e->getMessage());
    }
}

function ensureDispatchTables(PDO $pdo): void {
    if (isSchemaFlagSet('schema_dispatch_tables_v1')) {
        return;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS material_dispatches (
                id INT AUTO_INCREMENT PRIMARY KEY,
                dispatch_id VARCHAR(50) NOT NULL,
                atc_id INT NOT NULL,
                postal_service VARCHAR(100),
                tracking_id VARCHAR(100),
                dispatch_date DATE,
                notes TEXT,
                status ENUM('Pending','Dispatched','Delivered') DEFAULT 'Dispatched',
                created_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS material_dispatch_students (
                id INT AUTO_INCREMENT PRIMARY KEY,
                dispatch_id INT NOT NULL,
                admission_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS dispatch_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                dispatch_id INT NOT NULL,
                admission_id INT NOT NULL,
                item_type VARCHAR(50) NOT NULL,
                item_detail VARCHAR(100),
                inventory_item_id INT DEFAULT NULL,
                quantity INT DEFAULT 1,
                status ENUM('Dispatched','Pending') DEFAULT 'Pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS dispatch_complaints (
                id INT AUTO_INCREMENT PRIMARY KEY,
                dispatch_id INT NOT NULL,
                atc_id INT NOT NULL,
                complaint_type ENUM('Wrong Materials','Damaged','Missing Items','Wrong Quantity','Other') DEFAULT 'Other',
                description TEXT,
                photo VARCHAR(255),
                status ENUM('Pending','Resolved','Rejected') DEFAULT 'Pending',
                admin_response TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                resolved_at TIMESTAMP NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        markSchemaFlag('schema_dispatch_tables_v1');
    } catch (Exception $e) {
        error_log('[DispatchSchema] ' . $e->getMessage());
    }
}

function ensureInventoryTables(PDO $pdo): void {
    // Always ensure category column can hold custom names + categories table
    if (!isSchemaFlagSet('schema_inventory_categories_v1')) {
        try {
            $pdo->query("SELECT 1 FROM inventory_items LIMIT 1");
            try {
                $col = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'category'")->fetch(PDO::FETCH_ASSOC);
                if ($col && stripos((string)($col['Type'] ?? ''), 'enum(') === 0) {
                    $pdo->exec("ALTER TABLE inventory_items MODIFY COLUMN category VARCHAR(100) NOT NULL DEFAULT 'Books'");
                }
            } catch (Exception $e) {}
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS inventory_categories (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(100) NOT NULL UNIQUE,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            } catch (Exception $e) {}
            // Seed defaults + sync from existing items
            $defaults = ['Books', 'T-Shirts', 'Certificates', 'Stationery', 'Other'];
            $insCat = $pdo->prepare("INSERT IGNORE INTO inventory_categories (name) VALUES (?)");
            foreach ($defaults as $d) { $insCat->execute([$d]); }
            try {
                $existing = $pdo->query("SELECT DISTINCT category FROM inventory_items WHERE category IS NOT NULL AND category <> ''")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($existing as $c) { $insCat->execute([trim($c)]); }
            } catch (Exception $e) {}
            markSchemaFlag('schema_inventory_categories_v1');
        } catch (Exception $e) {
            // inventory_items may not exist yet — created below
        }
    }

    if (isSchemaFlagSet('schema_inventory_tables_v1')) {
        return;
    }
    try {
        $pdo->query("SELECT 1 FROM inventory_items LIMIT 1");
        // Tables exist — still ensure optional columns once
        try { $pdo->exec("ALTER TABLE inventory_transactions ADD COLUMN IF NOT EXISTS rate_per_item DECIMAL(10,2) DEFAULT NULL AFTER quantity"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE inventory_transactions ADD COLUMN IF NOT EXISTS total_amount DECIMAL(12,2) DEFAULT NULL AFTER rate_per_item"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE inventory_transactions ADD COLUMN IF NOT EXISTS purchase_date DATE DEFAULT NULL AFTER supplier"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE inventory_items ADD COLUMN IF NOT EXISTS cost DECIMAL(10,2) DEFAULT NULL AFTER unit"); } catch (Exception $e) {}
        // Allow custom categories (ENUM → VARCHAR)
        try {
            $col = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'category'")->fetch(PDO::FETCH_ASSOC);
            if ($col && stripos((string)($col['Type'] ?? ''), 'enum(') === 0) {
                $pdo->exec("ALTER TABLE inventory_items MODIFY COLUMN category VARCHAR(100) NOT NULL DEFAULT 'Books'");
            }
        } catch (Exception $e) {}
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS inventory_categories (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL UNIQUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Exception $e) {}
        markSchemaFlag('schema_inventory_tables_v1');
        return;
    } catch (Exception $e) {
        // create below
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS inventory_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                item_name VARCHAR(150) NOT NULL,
                category VARCHAR(100) NOT NULL DEFAULT 'Books',
                unit VARCHAR(30) DEFAULT 'pcs',
                cost DECIMAL(10,2) DEFAULT NULL,
                current_stock INT DEFAULT 0,
                min_stock_level INT DEFAULT 10,
                description TEXT,
                status ENUM('Active','Inactive') DEFAULT 'Active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS inventory_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                item_id INT NOT NULL,
                type ENUM('Stock In','Stock Out','Adjustment','Dispatch','Return') NOT NULL,
                quantity INT NOT NULL,
                rate_per_item DECIMAL(10,2) DEFAULT NULL,
                total_amount DECIMAL(12,2) DEFAULT NULL,
                running_balance INT DEFAULT 0,
                reference_no VARCHAR(100),
                supplier VARCHAR(200),
                dispatch_id INT DEFAULT NULL,
                atc_id INT DEFAULT NULL,
                notes TEXT,
                created_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS inventory_categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        markSchemaFlag('schema_inventory_tables_v1');
    } catch (Exception $e) {
        error_log('[InventorySchema] ' . $e->getMessage());
    }
}

/**
 * Ensure course -> material inventory mapping schema exists.
 *
 * When a course is created with "With Material", admins can explicitly select
 * which inventory items (T-Shirts / Books) belong to that course.
 *
 * This lets dispatching match the course configuration instead of using only
 * student attributes (uniform_size / material_language).
 */
function ensureCourseMaterialItemsSchema(PDO $pdo): void {
    if (!isSchemaFlagSet('schema_course_material_items_v1')) {
        try {
            // Ensure FK target exists
            ensureInventoryTables($pdo);

            // Courses table flag: when 1 => use course_material_items mapping.
            // When 0 => legacy behavior (derive items from student attributes).
            try {
                $pdo->exec("ALTER TABLE courses ADD COLUMN with_material_configured TINYINT NOT NULL DEFAULT 0");
            } catch (Exception $e) {}

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS course_material_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    course_id INT NOT NULL,
                    material_variant ENUM('With Material','Without Material') NOT NULL DEFAULT 'With Material',
                    inventory_item_id INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_course_variant_item (course_id, material_variant, inventory_item_id),
                    FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            markSchemaFlag('schema_course_material_items_v1');
        } catch (Exception $e) {
            error_log('[CourseMaterialSchema] ' . $e->getMessage());
        }
    }
}

/**
 * Whether a mapped inventory item belongs on this student's kit.
 */
function courseMappedItemAppliesToStudent(array $item, array $student): bool {
    $cat  = (string)($item['category'] ?? '');
    $name = (string)($item['item_name'] ?? '');

    if ($cat === 'T-Shirts') {
        $size = trim((string)($student['uniform_size'] ?? ''));
        return $size !== '' && stripos($name, $size) !== false;
    }

    if ($cat === 'Books') {
        $lang = trim((string)($student['material_language'] ?? ''));
        $namedLang = (stripos($name, 'English') !== false || stripos($name, 'Marathi') !== false);
        if ($namedLang) {
            return $lang !== '' && stripos($name, $lang) !== false;
        }
        // Course kit book (e.g. "Level 1 Book A & B") — one per With-Material student.
        return true;
    }

    return true;
}

function dispatchMaterialTypeForCategory(string $category): string {
    if ($category === 'T-Shirts') return 'T-Shirt';
    if ($category === 'Books') return 'Book';
    if ($category === 'Certificates') return 'Certificate';
    return $category !== '' ? $category : 'Other';
}

function dispatchItemDetailForMappedItem(array $item, array $student): string {
    $cat = (string)($item['category'] ?? '');
    if ($cat === 'T-Shirts') {
        $size = trim((string)($student['uniform_size'] ?? ''));
        return $size !== '' ? ('Size ' . $size) : (string)$item['item_name'];
    }
    if ($cat === 'Certificates') {
        return (string)($student['course'] ?? $item['item_name'] ?? 'General');
    }
    return (string)($item['item_name'] ?? '');
}

/**
 * Pending kit lines for With-Material students.
 *
 * @return array{students:list<array>, totals:list<array>}
 */
function collectPendingAtcMaterials(PDO $pdo, int $atcId): array {
    ensureCourseMaterialItemsSchema($pdo);
    ensureInventoryTables($pdo);

    $stmt = $pdo->prepare("
        SELECT a.id, a.roll_no, a.registration_id,
               TRIM(CONCAT(a.first_name,' ',COALESCE(a.middle_name,''),' ',a.last_name)) AS student_name,
               a.course, a.uniform_size, a.material_language, a.material_type,
               a.admission_date
        FROM admissions a
        WHERE a.atc_id = ? AND a.status = 'Active' AND a.material_type = 'With Material'
        ORDER BY a.first_name ASC
    ");
    $stmt->execute([$atcId]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $invItemsLegacy = [];
    try {
        $invItemsLegacy = $pdo->query("SELECT id, item_name, category, current_stock FROM inventory_items WHERE status='Active'")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $courseNames = array_values(array_unique(array_filter(array_map(
        fn($x) => trim((string)($x['course'] ?? '')),
        $students
    ))));

    $courseMap = [];
    if (!empty($courseNames)) {
        $ph = implode(',', array_fill(0, count($courseNames), '?'));
        $cStmt = $pdo->prepare("SELECT id, course_name, with_material_configured FROM courses WHERE course_name IN ($ph)");
        $cStmt->execute($courseNames);
        foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $courseMap[(string)$c['course_name']] = [
                'course_id' => (int)$c['id'],
                'with_material_configured' => (int)($c['with_material_configured'] ?? 0),
            ];
        }
    }

    $configuredCourseIds = [];
    foreach ($courseMap as $row) {
        if (!empty($row['with_material_configured'])) {
            $configuredCourseIds[] = (int)$row['course_id'];
        }
    }
    $configuredCourseIds = array_values(array_unique($configuredCourseIds));

    $mappedInvItemsByCourseId = [];
    if (!empty($configuredCourseIds)) {
        $ph = implode(',', array_fill(0, count($configuredCourseIds), '?'));
        $mStmt = $pdo->prepare("
            SELECT cmi.course_id, ii.id as inventory_item_id, ii.item_name, ii.category, ii.current_stock
            FROM course_material_items cmi
            INNER JOIN inventory_items ii ON ii.id = cmi.inventory_item_id
            WHERE cmi.material_variant = 'With Material'
              AND cmi.course_id IN ($ph)
              AND ii.status = 'Active'
        ");
        $mStmt->execute($configuredCourseIds);
        foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $cid = (int)$m['course_id'];
            $mappedInvItemsByCourseId[$cid][] = [
                'id' => (int)$m['inventory_item_id'],
                'item_name' => $m['item_name'],
                'category' => $m['category'],
                'current_stock' => $m['current_stock'],
            ];
        }
    }

    $dispatchedMap = [];
    $dispatchedInv = [];
    $admIds = array_column($students, 'id');
    try {
        if (!empty($admIds)) {
            $placeholders = implode(',', array_fill(0, count($admIds), '?'));
            $diStmt = $pdo->prepare("SELECT admission_id, item_type, item_detail, inventory_item_id, status FROM dispatch_items WHERE admission_id IN ($placeholders)");
            $diStmt->execute($admIds);
            foreach ($diStmt->fetchAll(PDO::FETCH_ASSOC) as $di) {
                $key = $di['admission_id'] . '_' . $di['item_type'] . '_' . $di['item_detail'];
                $dispatchedMap[$key] = $di['status'];
                $iid = (int)($di['inventory_item_id'] ?? 0);
                if ($iid > 0 && $di['status'] === 'Dispatched') {
                    $dispatchedInv[(int)$di['admission_id']][$iid] = true;
                }
            }
        }
    } catch (Exception $e) {}

    $legacyDispatched = [];
    try {
        if (!empty($admIds)) {
            $placeholders = implode(',', array_fill(0, count($admIds), '?'));
            $legStmt = $pdo->prepare("SELECT DISTINCT admission_id FROM material_dispatch_students WHERE admission_id IN ($placeholders)");
            $legStmt->execute($admIds);
            $legacyDispatched = array_map('intval', $legStmt->fetchAll(PDO::FETCH_COLUMN));
        }
    } catch (Exception $e) {}

    $isLineDispatched = static function (
        array $s,
        string $type,
        string $detail,
        ?int $invId
    ) use ($dispatchedMap, $dispatchedInv, $legacyDispatched): bool {
        $admId = (int)$s['id'];
        $already = $dispatchedMap[$admId . '_' . $type . '_' . $detail] ?? null;
        if ($already === 'Dispatched') {
            return true;
        }
        if ($invId && !empty($dispatchedInv[$admId][$invId])) {
            return true;
        }
        if ($type === 'Book') {
            $lang = trim((string)($s['material_language'] ?? ''));
            if ($lang !== '' && ($dispatchedMap[$admId . '_Book_' . $lang] ?? null) === 'Dispatched') {
                $itemName = $detail;
                if (stripos($itemName, $lang) !== false) {
                    return true;
                }
            }
        }
        $isLegacy = in_array($admId, $legacyDispatched, true);
        if ($isLegacy && $already === null && !$invId) {
            return true;
        }
        if ($isLegacy && $already === null && $type === 'Certificate') {
            return true;
        }
        return false;
    };

    $lineStatus = static function (?string $alreadyStatus, int $stock): string {
        if ($alreadyStatus === 'Pending') {
            return 'pending_dispatch';
        }
        return $stock > 0 ? 'available' : 'out_of_stock';
    };

    $result = [];
    $totals = []; // invId => row

    foreach ($students as $s) {
        $courseName = (string)($s['course'] ?? '');
        $courseRow  = $courseMap[$courseName] ?? null;
        $isCourseConfigured = !empty($courseRow) && ((int)$courseRow['with_material_configured'] === 1);
        $courseIdForMapping  = !empty($courseRow) ? (int)$courseRow['course_id'] : 0;
        $mappedItems = $isCourseConfigured ? ($mappedInvItemsByCourseId[$courseIdForMapping] ?? []) : [];
        $materials = [];

        if ($isCourseConfigured) {
            $mappedHasCert = false;
            foreach ($mappedItems as $inv) {
                if (($inv['category'] ?? '') === 'Certificates') {
                    $mappedHasCert = true;
                }
                if (!courseMappedItemAppliesToStudent($inv, $s)) {
                    continue;
                }
                $type = dispatchMaterialTypeForCategory((string)$inv['category']);
                $detail = dispatchItemDetailForMappedItem($inv, $s);
                $invId = (int)$inv['id'];
                $already = $dispatchedMap[$s['id'] . '_' . $type . '_' . $detail] ?? null;
                if ($isLineDispatched($s, $type, $detail, $invId)) {
                    continue;
                }
                $stock = (int)$inv['current_stock'];
                $materials[] = [
                    'type' => $type,
                    'detail' => $detail,
                    'inventory_item_id' => $invId,
                    'inventory_item_name' => $inv['item_name'],
                    'stock' => $stock,
                    'status' => $lineStatus($already, $stock),
                    'pending_dispatch_id' => $already === 'Pending',
                ];
            }

            if (!$mappedHasCert) {
                $certKey = $s['id'] . '_Certificate_' . ($s['course'] ?? 'General');
                $certStatus = $dispatchedMap[$certKey] ?? null;
                if (!$isLineDispatched($s, 'Certificate', (string)($s['course'] ?? 'General'), null)) {
                    $matchedCert = null;
                    $matchedCertStock = 0;
                    foreach ($invItemsLegacy as $inv) {
                        if ($inv['category'] === 'Certificates' && stripos($inv['item_name'], 'Course Completion') !== false) {
                            $matchedCert = $inv;
                            $matchedCertStock = (int)$inv['current_stock'];
                            break;
                        }
                    }
                    $materials[] = [
                        'type' => 'Certificate',
                        'detail' => $s['course'] ?? 'General',
                        'inventory_item_id' => $matchedCert ? (int)$matchedCert['id'] : null,
                        'inventory_item_name' => $matchedCert['item_name'] ?? null,
                        'stock' => $matchedCertStock,
                        'status' => $lineStatus($certStatus, $matchedCertStock),
                        'pending_dispatch_id' => $certStatus === 'Pending',
                    ];
                }
            }
        } else {
            if (!empty($s['uniform_size'])) {
                $size = $s['uniform_size'];
                $tKey = $s['id'] . '_T-Shirt_Size ' . $size;
                $alreadyStatus = $dispatchedMap[$tKey] ?? null;
                if (!$isLineDispatched($s, 'T-Shirt', 'Size ' . $size, null)) {
                    $matchedItem = null;
                    $matchedStock = 0;
                    foreach ($invItemsLegacy as $inv) {
                        if ($inv['category'] === 'T-Shirts' && stripos($inv['item_name'], $size) !== false) {
                            $matchedItem = $inv;
                            $matchedStock = (int)$inv['current_stock'];
                            break;
                        }
                    }
                    $materials[] = [
                        'type' => 'T-Shirt',
                        'detail' => 'Size ' . $size,
                        'inventory_item_id' => $matchedItem ? (int)$matchedItem['id'] : null,
                        'inventory_item_name' => $matchedItem['item_name'] ?? null,
                        'stock' => $matchedStock,
                        'status' => $lineStatus($alreadyStatus, $matchedStock),
                        'pending_dispatch_id' => $alreadyStatus === 'Pending',
                    ];
                }
            }

            if (!empty($s['material_language'])) {
                $lang = $s['material_language'];
                $bKey = $s['id'] . '_Book_' . $lang;
                $alreadyStatus = $dispatchedMap[$bKey] ?? null;
                if (!$isLineDispatched($s, 'Book', $lang, null)) {
                    $matchedItem = null;
                    $matchedStock = 0;
                    foreach ($invItemsLegacy as $inv) {
                        if ($inv['category'] === 'Books' && stripos($inv['item_name'], $lang) !== false) {
                            $matchedItem = $inv;
                            $matchedStock = (int)$inv['current_stock'];
                            break;
                        }
                    }
                    $materials[] = [
                        'type' => 'Book',
                        'detail' => $lang,
                        'inventory_item_id' => $matchedItem ? (int)$matchedItem['id'] : null,
                        'inventory_item_name' => $matchedItem['item_name'] ?? null,
                        'stock' => $matchedStock,
                        'status' => $lineStatus($alreadyStatus, $matchedStock),
                        'pending_dispatch_id' => $alreadyStatus === 'Pending',
                    ];
                }
            }

            $certKey = $s['id'] . '_Certificate_' . ($s['course'] ?? 'General');
            $certStatus = $dispatchedMap[$certKey] ?? null;
            if (!$isLineDispatched($s, 'Certificate', (string)($s['course'] ?? 'General'), null)) {
                $matchedCert = null;
                $matchedCertStock = 0;
                foreach ($invItemsLegacy as $inv) {
                    if ($inv['category'] === 'Certificates' && stripos($inv['item_name'], 'Course Completion') !== false) {
                        $matchedCert = $inv;
                        $matchedCertStock = (int)$inv['current_stock'];
                        break;
                    }
                }
                $materials[] = [
                    'type' => 'Certificate',
                    'detail' => $s['course'] ?? 'General',
                    'inventory_item_id' => $matchedCert ? (int)$matchedCert['id'] : null,
                    'inventory_item_name' => $matchedCert['item_name'] ?? null,
                    'stock' => $matchedCertStock,
                    'status' => $lineStatus($certStatus, $matchedCertStock),
                    'pending_dispatch_id' => $certStatus === 'Pending',
                ];
            }
        }

        if (empty($materials)) {
            continue;
        }

        $result[] = [
            'id' => $s['id'],
            'student_name' => $s['student_name'],
            'roll_no' => $s['roll_no'],
            'registration_id' => $s['registration_id'],
            'course' => $s['course'],
            'admission_date' => $s['admission_date'],
            'materials' => $materials,
        ];

        foreach ($materials as $m) {
            $label = (string)($m['inventory_item_name'] ?: ($m['type'] . ' — ' . $m['detail']));
            $tid = $m['inventory_item_id'] ? ('id:' . $m['inventory_item_id']) : ('name:' . $label);
            if (!isset($totals[$tid])) {
                $totals[$tid] = [
                    'inventory_item_id' => $m['inventory_item_id'],
                    'item_name' => $label,
                    'category' => $m['type'],
                    'qty' => 0,
                    'stock' => (int)$m['stock'],
                ];
            }
            $totals[$tid]['qty']++;
        }
    }

    $totalsList = array_values($totals);
    usort($totalsList, fn($a, $b) => strcasecmp($a['item_name'], $b['item_name']));

    return ['students' => $result, 'totals' => $totalsList];
}

/**
 * ATC-wise pending material quantities for the dispatch report.
 *
 * @return list<array{atc_id:int,atc_name:string,student_count:int,item_count:int,items:list<array>}>
 */
function buildAtcMaterialRequirementReport(PDO $pdo, ?int $filterAtcId = null): array {
    $sql = "SELECT id, name FROM atc_centers WHERE status='Active'";
    $params = [];
    if ($filterAtcId) {
        $sql .= " AND id = ?";
        $params[] = $filterAtcId;
    }
    $sql .= " ORDER BY name ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $atcs = $st->fetchAll(PDO::FETCH_ASSOC);

    $report = [];
    foreach ($atcs as $atc) {
        $pack = collectPendingAtcMaterials($pdo, (int)$atc['id']);
        if (empty($pack['totals'])) {
            continue;
        }
        $itemCount = 0;
        foreach ($pack['totals'] as $t) {
            $itemCount += (int)$t['qty'];
        }
        $report[] = [
            'atc_id' => (int)$atc['id'],
            'atc_name' => (string)$atc['name'],
            'student_count' => count($pack['students']),
            'item_count' => $itemCount,
            'items' => $pack['totals'],
        ];
    }
    return $report;
}


