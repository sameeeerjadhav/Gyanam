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
 * Safe to call repeatedly (idempotent). Migrates legacy single columns once.
 */
function ensureDualMaterialCourseSchema(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $courseCols = $pdo->query("SHOW COLUMNS FROM courses")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('ho_share_with_material', $courseCols, true)) {
            $pdo->exec("ALTER TABLE courses ADD COLUMN ho_share_with_material DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'HO share when student takes course WITH material'");
        }
        if (!in_array('ho_share_without_material', $courseCols, true)) {
            $pdo->exec("ALTER TABLE courses ADD COLUMN ho_share_without_material DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'HO share when student takes course WITHOUT material'");
        }
        if (!in_array('dlc_share_with_material', $courseCols, true)) {
            $pdo->exec("ALTER TABLE courses ADD COLUMN dlc_share_with_material DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'DLC share when student takes course WITH material'");
        }
        if (!in_array('dlc_share_without_material', $courseCols, true)) {
            $pdo->exec("ALTER TABLE courses ADD COLUMN dlc_share_without_material DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'DLC share when student takes course WITHOUT material'");
        }

        // One-time migrate from legacy single ho_share + material_type
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
    } catch (Exception $e) {
        error_log('[DualMaterial] courses schema: ' . $e->getMessage());
    }

    // Snapshot columns on admissions
    try {
        $admCols = $pdo->query("SHOW COLUMNS FROM admissions")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('dlc_share_snapshot', $admCols, true)) {
            $pdo->exec("ALTER TABLE admissions ADD COLUMN dlc_share_snapshot DECIMAL(10,2) DEFAULT NULL COMMENT 'DLC share locked at admission time'");
        }
    } catch (Exception $e) {
        error_log('[DualMaterial] admissions schema: ' . $e->getMessage());
    }

    try {
        $feeCols = $pdo->query("SHOW COLUMNS FROM atc_course_fees")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('fee_with_material', $feeCols, true)) {
            $pdo->exec("ALTER TABLE atc_course_fees ADD COLUMN fee_with_material DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'ATC selling fee WITH material'");
        }
        if (!in_array('fee_without_material', $feeCols, true)) {
            $pdo->exec("ALTER TABLE atc_course_fees ADD COLUMN fee_without_material DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'ATC selling fee WITHOUT material'");
        }

        // Migrate legacy final_fee into the matching material column via master course type
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
    } catch (Exception $e) {
        error_log('[DualMaterial] atc_course_fees schema: ' . $e->getMessage());
    }
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
 * @return array{due:float,paid:float,pending:float,student_count:int,students:list<array>}
 */
function calculateDlcShareSummary(PDO $pdo, int $dlcId): array {
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

    // Collect ATC ids then paid map (global is fine; keyed by admission id)
    $paidMap = getHoSharePaidAdmissionIds($pdo);

    foreach ($rows as $row) {
        if (!isset($paidMap[(int)$row['id']])) {
            continue; // DLC share only after ATC has paid HO share
        }
        $share = resolveStudentDlcShare($row, $pdo);
        if ($share <= 0) {
            continue;
        }
        $summary['due'] += $share;
        $summary['student_count']++;
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

    $summary['pending'] = max(0, $summary['due'] - $summary['paid']);
    return $summary;
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
 * @return array{page:int,per_page:int,offset:int}
 */
function paginationParams(int $defaultPerPage = 25, int $maxPerPage = 100): array {
    $page    = max(1, (int)($_GET['page'] ?? 1));
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
    ];
}

/**
 * Build pagination meta from a total row count.
 *
 * @return array{page:int,per_page:int,offset:int,total:int,total_pages:int,from:int,to:int}
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
    ];
}

/**
 * Build a page URL while preserving current query params (except page).
 */
function paginationUrl(int $page, array $extra = []): string {
    $query = array_merge($_GET, $extra, ['page' => $page]);
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
        $html .= '<a class="pager-btn" href="' . htmlspecialchars(paginationUrl($page - 1)) . '">‹ Prev</a>';
    }

    // Page window (max ~7 numbers)
    $window = 2;
    $start  = max(1, $page - $window);
    $end    = min($totalPages, $page + $window);
    if ($start > 1) {
        $html .= '<a class="pager-btn" href="' . htmlspecialchars(paginationUrl(1)) . '">1</a>';
        if ($start > 2) {
            $html .= '<span class="pager-ellipsis">…</span>';
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        if ($i === $page) {
            $html .= '<span class="pager-btn active" aria-current="page">' . $i . '</span>';
        } else {
            $html .= '<a class="pager-btn" href="' . htmlspecialchars(paginationUrl($i)) . '">' . $i . '</a>';
        }
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<span class="pager-ellipsis">…</span>';
        }
        $html .= '<a class="pager-btn" href="' . htmlspecialchars(paginationUrl($totalPages)) . '">' . $totalPages . '</a>';
    }

    // Next
    if ($page >= $totalPages) {
        $html .= '<span class="pager-btn disabled" aria-disabled="true">Next ›</span>';
    } else {
        $html .= '<a class="pager-btn" href="' . htmlspecialchars(paginationUrl($page + 1)) . '">Next ›</a>';
    }

    $html .= '</div></div>';
    return $html;
}
