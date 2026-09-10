<?php
/**
 * Shared Utility Functions — Gyanam Portal
 */

/** India Standard Time for all portal dates/times (PHP + MySQL session). */
function ensureIndiaTimezone(?PDO $pdo = null): void {
    static $phpDone = false;
    static $pdoDone = false;
    if (!$phpDone) {
        date_default_timezone_set('Asia/Kolkata');
        $phpDone = true;
    }
    if ($pdo !== null && !$pdoDone) {
        try {
            $pdo->exec("SET time_zone = '+05:30'");
        } catch (Throwable $e) {
            // Host may not allow SET time_zone; PHP timestamps still use IST
        }
        $pdoDone = true;
    }
}

ensureIndiaTimezone();

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
/**
 * Look up courses.course_type by course name.
 */
function lookupCourseTypeByName(PDO $pdo, string $courseName): string {
    $courseName = trim($courseName);
    if ($courseName === '') {
        return '';
    }
    try {
        $st = $pdo->prepare("SELECT course_type FROM courses WHERE course_name = ? LIMIT 1");
        $st->execute([$courseName]);
        return trim((string)($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Student ID prefix by course brand:
 * - Abacus / Vedic Maths → GYANAM
 * - IT → GIIT
 */
function studentIdPrefixForCourse(?string $courseType = null, ?string $courseName = null, ?string $centerType = null): string {
    $brand = admissionFormBrandVariant($centerType, $courseName, $courseType);
    return $brand === 'it' ? 'GIIT' : 'GYANAM';
}

/**
 * Next global registration / roll ID for a course brand.
 * Abacus/Vedic → GYANAM1, GYANAM2, …
 * IT → GIIT1, GIIT2, …
 *
 * Sequences are separate per prefix. GYANAM continues from legacy GIES/gi* IDs.
 */
function generateRegistrationId(
    PDO $pdo,
    string $centerType = '',
    string $courseName = '',
    string $courseType = ''
): string {
    if ($courseType === '' && $courseName !== '') {
        $courseType = lookupCourseTypeByName($pdo, $courseName);
    }
    $prefix = studentIdPrefixForCourse($courseType, $courseName, $centerType);

    if ($prefix === 'GIIT') {
        $stmt = $pdo->query(
            "SELECT COALESCE(MAX(
                CAST(REGEXP_REPLACE(registration_id, '^GIIT', '') AS UNSIGNED)
            ), 0)
            FROM admissions
            WHERE registration_id REGEXP '^GIIT[0-9]+$'"
        );
    } else {
        // GYANAM series (includes legacy GIES / gi* so numbering stays continuous)
        $stmt = $pdo->query(
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
            WHERE registration_id REGEXP '^(GYANAM|GIES|gi[a-z]+)[0-9]+$'"
        );
    }
    $maxSeq = (int)($stmt ? $stmt->fetchColumn() : 0);
    return $prefix . ($maxSeq + 1);
}

/**
 * Next roll number — same branded series as registration_id (GYANAM# / GIIT#).
 * $atcId kept for backward-compatible call signatures.
 */
function generateNextRollNoSimple(
    PDO $pdo,
    int $atcId,
    string $centerType = '',
    string $courseName = '',
    string $courseType = ''
): string {
    return generateRegistrationId($pdo, $centerType, $courseName, $courseType);
}

/**
 * Allow one student (same roll/registration) to hold multiple course admission rows.
 */
function ensureAdmissionsAllowMultiCourse(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    foreach (['uk_roll_no', 'uq_registration_id', 'uk_registration_id', 'registration_id'] as $idx) {
        try {
            $chk = $pdo->query("SHOW INDEX FROM admissions WHERE Key_name = " . $pdo->quote($idx))->fetch();
            if ($chk && (int)($chk['Non_unique'] ?? 1) === 0) {
                // Only drop unique indexes that would block multi-course rows
                if (in_array($idx, ['uk_roll_no', 'uq_registration_id', 'uk_registration_id'], true)) {
                    $pdo->exec("ALTER TABLE admissions DROP INDEX `{$idx}`");
                }
            }
        } catch (Throwable $e) { /* ignore */ }
    }
}

/**
 * Resolve roll_no + registration_id when admitting a course.
 * If the same mobile already has an Active admission at this ATC, reuse identity
 * (re-enrollment / 2nd course). Otherwise mint new IDs.
 *
 * @return array{ok:bool,message?:string,roll_no?:string,registration_id?:string,is_re_enrollment?:bool,source?:?array}
 */
function resolveAdmissionIdentityForCourse(
    PDO $pdo,
    int $atcId,
    string $mobile,
    string $course,
    string $centerType = 'Other'
): array {
    $mobile = preg_replace('/\D+/', '', trim($mobile)) ?? '';
    $course = trim($course);
    if ($atcId <= 0 || $course === '') {
        return ['ok' => false, 'message' => 'ATC and course are required.'];
    }

    $source = null;
    if ($mobile !== '') {
        $st = $pdo->prepare("
            SELECT * FROM admissions
            WHERE atc_id = ? AND REPLACE(REPLACE(mobile,' ',''),'-','') = ?
              AND status = 'Active'
            ORDER BY id ASC
            LIMIT 1
        ");
        $st->execute([$atcId, $mobile]);
        $source = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($source) {
        $dup = $pdo->prepare("
            SELECT id FROM admissions
            WHERE atc_id = ? AND registration_id = ? AND course = ? AND status = 'Active'
            LIMIT 1
        ");
        $dup->execute([$atcId, $source['registration_id'], $course]);
        if ($dup->fetchColumn()) {
            return [
                'ok' => false,
                'message' => 'This student is already enrolled in "' . $course . '". Use Re-Admission only for a different course.',
            ];
        }
        ensureAdmissionsAllowMultiCourse($pdo);
        return [
            'ok' => true,
            'roll_no' => (string)$source['roll_no'],
            'registration_id' => (string)$source['registration_id'],
            'is_re_enrollment' => true,
            'source' => $source,
        ];
    }

    $courseType = lookupCourseTypeByName($pdo, $course);
    $registrationId = generateRegistrationId($pdo, $centerType, $course, $courseType);

    return [
        'ok' => true,
        // Roll No uses the same branded series as Registration ID (GYANAM# / GIIT#)
        'roll_no' => $registrationId,
        'registration_id' => $registrationId,
        'is_re_enrollment' => false,
        'source' => null,
    ];
}

/**
 * Converted inquiries that have no matching Active admission (mobile + course).
 * Used to repair cases where inquiry was marked Converted without a 2nd-course row.
 *
 * @return list<array{source:string,inquiry_id:int,first_name:string,last_name:string,mobile:string,course:string,created_at:?string}>
 */
function findConvertedInquiriesMissingAdmission(PDO $pdo, int $atcId): array {
    $out = [];
    $queries = [
        ['walkin', "SELECT id, first_name, middle_name, last_name, mobile, interested_course AS course, created_at
                    FROM inquiries WHERE atc_id = ? AND status = 'Converted'"],
        ['telephonic', "SELECT id, first_name, middle_name, last_name, mobile, interested_course AS course, created_at
                        FROM telephonic_inquiries WHERE atc_id = ? AND status = 'Converted'"],
    ];
    foreach ($queries as [$source, $sql]) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$atcId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $mobile = preg_replace('/\D+/', '', (string)($row['mobile'] ?? '')) ?? '';
                $course = trim((string)($row['course'] ?? ''));
                if ($mobile === '' || $course === '') {
                    continue;
                }
                $chk = $pdo->prepare("
                    SELECT COUNT(*) FROM admissions
                    WHERE atc_id = ?
                      AND REPLACE(REPLACE(mobile,' ',''),'-','') = ?
                      AND course = ?
                      AND status = 'Active'
                ");
                $chk->execute([$atcId, $mobile, $course]);
                if ((int)$chk->fetchColumn() > 0) {
                    continue;
                }
                $out[] = [
                    'source' => $source,
                    'inquiry_id' => (int)$row['id'],
                    'first_name' => (string)($row['first_name'] ?? ''),
                    'middle_name' => (string)($row['middle_name'] ?? ''),
                    'last_name' => (string)($row['last_name'] ?? ''),
                    'mobile' => $mobile,
                    'course' => $course,
                    'created_at' => $row['created_at'] ?? null,
                ];
            }
        } catch (Throwable $e) { /* table may not exist */ }
    }
    return $out;
}

/**
 * Create the missing Active admission for a converted inquiry (2nd course repair).
 *
 * @return array{success:bool,message:string,admission_id?:int}
 */
function createMissingAdmissionFromConvertedInquiry(
    PDO $pdo,
    int $atcId,
    int $inquiryId,
    string $source = 'walkin'
): array {
    ensureDualMaterialCourseSchema($pdo);
    $source = $source === 'telephonic' ? 'telephonic' : 'walkin';

    if ($source === 'telephonic') {
        $st = $pdo->prepare("SELECT * FROM telephonic_inquiries WHERE id = ? AND atc_id = ? AND status = 'Converted'");
    } else {
        $st = $pdo->prepare("SELECT * FROM inquiries WHERE id = ? AND atc_id = ? AND status = 'Converted'");
    }
    $st->execute([$inquiryId, $atcId]);
    $inq = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inq) {
        return ['success' => false, 'message' => 'Converted inquiry not found.'];
    }

    $mobile = preg_replace('/\D+/', '', (string)($inq['mobile'] ?? '')) ?? '';
    $course = trim((string)($inq['interested_course'] ?? ''));
    if ($mobile === '' || $course === '') {
        return ['success' => false, 'message' => 'Inquiry is missing mobile or course.'];
    }

    $atcStmt = $pdo->prepare("SELECT center_type FROM atc_centers WHERE id = ?");
    $atcStmt->execute([$atcId]);
    $centerType = (string)($atcStmt->fetchColumn() ?: 'Other');

    $ident = resolveAdmissionIdentityForCourse($pdo, $atcId, $mobile, $course, $centerType);
    if (empty($ident['ok'])) {
        return ['success' => false, 'message' => $ident['message'] ?? 'Could not resolve student identity.'];
    }

    $src = $ident['source'] ?? null;
    $matType = 'Without Material';
    if ($src && !empty($src['material_type'])) {
        $matType = (string)$src['material_type'];
    }
    $hoSnap = getHoShareForCourse($pdo, $course, $matType);
    $dlcSnap = function_exists('getDlcShareForCourse') ? getDlcShareForCourse($pdo, $course, $matType) : null;

    $fees = 0.0;
    try {
        // Prefer ATC fee for this course if configured
        $feeSt = $pdo->prepare("
            SELECT COALESCE(NULLIF(acf.fee_without_material,0), NULLIF(acf.final_fee,0), 0)
            FROM courses c
            INNER JOIN atc_course_fees acf ON acf.course_id = c.id AND acf.atc_id = ?
            WHERE c.course_name = ? AND c.status = 'Active'
            LIMIT 1
        ");
        $feeSt->execute([$atcId, $course]);
        $fees = (float)$feeSt->fetchColumn();
    } catch (Throwable $e) {}

    $fkInquiryId = ($source === 'telephonic') ? null : $inquiryId;

    $stmt = $pdo->prepare("
        INSERT INTO admissions (
            atc_id, inquiry_id, roll_no, registration_id,
            first_name, middle_name, last_name,
            gender, dob, qualification, course, photo, address, state, pin_code, city,
            mobile, phone, email, referenced_by, comment, admission_date,
            course_fees, discount_amount, installments, net_payable, fees_total, fees_pending,
            father_name, mother_name, material_type, material_language,
            ho_share_snapshot, dlc_share_snapshot, status
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'Active')
    ");
    $stmt->execute([
        $atcId,
        $fkInquiryId,
        $ident['roll_no'],
        $ident['registration_id'],
        $src['first_name'] ?? $inq['first_name'],
        $src['middle_name'] ?? ($inq['middle_name'] ?? null),
        $src['last_name'] ?? $inq['last_name'],
        $src['gender'] ?? ($inq['gender'] ?? null),
        $src['dob'] ?? ($inq['dob'] ?? null),
        $src['qualification'] ?? ($inq['qualification'] ?? null),
        $course,
        $src['photo'] ?? null,
        $src['address'] ?? ($inq['address'] ?? null),
        $src['state'] ?? ($inq['state'] ?? null),
        $src['pin_code'] ?? ($inq['pin_code'] ?? null),
        $src['city'] ?? ($inq['city'] ?? null),
        $mobile,
        $src['phone'] ?? ($inq['phone'] ?? null),
        $src['email'] ?? ($inq['email'] ?? null),
        !empty($ident['is_re_enrollment']) ? ('Re-Admission via converted inquiry #' . $inquiryId) : ($inq['referenced_by'] ?? null),
        'Auto-created missing course admission from converted inquiry',
        date('Y-m-d'),
        $fees,
        0,
        1,
        $fees,
        $fees,
        $fees,
        $src['father_name'] ?? '',
        $src['mother_name'] ?? '',
        $matType,
        $src['material_language'] ?? 'English',
        $hoSnap,
        $dlcSnap,
    ]);

    $admissionId = (int)$pdo->lastInsertId();
    try {
        $pdo->prepare("UPDATE atc_centers SET student_count = student_count + 1 WHERE id = ?")->execute([$atcId]);
    } catch (Throwable $e) {}

    return [
        'success' => true,
        'message' => (!empty($ident['is_re_enrollment']) ? 'Added 2nd course admission' : 'Created admission')
            . ' for ' . $course . ' (#' . $admissionId . ').',
        'admission_id' => $admissionId,
        'roll_no' => $ident['roll_no'],
        'registration_id' => $ident['registration_id'],
    ];
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
    return ['Abacus', 'Vedic Maths', 'IT', 'Typing'];
}

/**
 * Full ATC center_type dropdown values (master types + common combos).
 *
 * @return list<string>
 */
function atcCenterTypeOptions(): array {
    return [
        'Abacus',
        'Vedic Maths',
        'IT',
        'Typing',
        'Abacus + IT',
        'Abacus + Vedic Maths',
        'Vedic Maths + IT',
        'Abacus + Vedic Maths + IT',
        'Typing + IT',
    ];
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
    if (str_contains($raw, 'typing')) {
        $types[] = 'Typing';
    }
    // Word-boundary IT so "Typing" alone does not count as IT
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
function courseVisibilitySql(?string $centerType, string $column = 'c.course_type', ?int $atcId = null, ?PDO $pdo = null): array {
    $types = courseTypesForCenter($centerType);
    if ($types === []) {
        return [' AND 1=0', []];
    }
    $ph = implode(',', array_fill(0, count($types), '?'));
    $sql = " AND {$column} IN ($ph)";
    $params = $types;

    if ($atcId !== null && $atcId > 0) {
        if ($pdo instanceof PDO) {
            ensureCourseAtcVisibilitySchema($pdo);
        }
        // all (default) = every ATC of that center type; specific = only mapped ATCs
        $sql .= " AND (
            COALESCE(c.visibility_scope, 'all') <> 'specific'
            OR EXISTS (
                SELECT 1 FROM course_atc_visibility cav
                WHERE cav.course_id = c.id AND cav.atc_id = ?
            )
        )";
        $params[] = $atcId;
    }

    return [$sql, $params];
}

/**
 * Ensure courses.visibility_scope + course_atc_visibility mapping table.
 */
function ensureCourseAtcVisibilitySchema(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM courses')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('visibility_scope', $cols, true)) {
            $pdo->exec("ALTER TABLE courses ADD COLUMN visibility_scope VARCHAR(20) NOT NULL DEFAULT 'all' COMMENT 'all=all centers of type; specific=only mapped ATCs'");
        }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS course_atc_visibility (
                id INT AUTO_INCREMENT PRIMARY KEY,
                course_id INT NOT NULL,
                atc_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_course_atc (course_id, atc_id),
                KEY idx_cav_atc (atc_id),
                KEY idx_cav_course (course_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {
        error_log('[CourseAtcVis] ' . $e->getMessage());
    }
}

/**
 * Whether a course row is visible to a given ATC (center type + optional specific list).
 */
function courseIsVisibleToAtc(PDO $pdo, array $course, int $atcId, ?string $centerType = null): bool {
    ensureCourseAtcVisibilitySchema($pdo);
    if ($atcId <= 0) {
        return false;
    }
    if ($centerType === null) {
        $centerType = getAtcCenterType($pdo, $atcId);
    }
    if (!courseIsVisibleToCenter($course['course_type'] ?? '', $centerType)) {
        return false;
    }
    $scope = strtolower(trim((string)($course['visibility_scope'] ?? '')));
    $courseId = (int)($course['id'] ?? 0);
    if ($scope === '' && $courseId > 0) {
        try {
            $st = $pdo->prepare('SELECT visibility_scope FROM courses WHERE id = ? LIMIT 1');
            $st->execute([$courseId]);
            $scope = strtolower(trim((string)($st->fetchColumn() ?: 'all')));
        } catch (Exception $e) {
            $scope = 'all';
        }
    }
    if ($scope === '') {
        $scope = 'all';
    }
    if ($scope !== 'specific') {
        return true;
    }
    if ($courseId <= 0) {
        return false;
    }
    $st = $pdo->prepare('SELECT 1 FROM course_atc_visibility WHERE course_id = ? AND atc_id = ? LIMIT 1');
    $st->execute([$courseId, $atcId]);
    return (bool)$st->fetchColumn();
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
 *  - IT and/or Typing only → IT (GIIT) cert
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
    $hasTyping = str_contains($t, 'typing');
    $hasIt     = (bool)preg_match('/(?<![a-z])it(?![a-z])/', $t) || $hasTyping;

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
 * Admin ATC onboarding enquiries (convert → ATC center), similar to student inquiries.
 */
function ensureAtcOnboardingEnquirySchema(PDO $pdo): void {
    if (isSchemaFlagSet('schema_atc_onboarding_enq_v1')) {
        return;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS atc_onboarding_enquiries (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                center_name VARCHAR(150) NOT NULL,
                contact_person VARCHAR(100) DEFAULT NULL,
                mobile VARCHAR(15) DEFAULT NULL,
                alternate_mobile VARCHAR(15) DEFAULT NULL,
                email VARCHAR(100) DEFAULT NULL,
                interested_center_type VARCHAR(80) DEFAULT NULL,
                preferred_dlc_id INT DEFAULT NULL,
                address TEXT DEFAULT NULL,
                district VARCHAR(100) DEFAULT NULL,
                taluka VARCHAR(100) DEFAULT NULL,
                city VARCHAR(100) DEFAULT NULL,
                state VARCHAR(80) DEFAULT 'Maharashtra',
                pin_code VARCHAR(10) DEFAULT NULL,
                enquiry_source VARCHAR(40) DEFAULT 'Phone',
                enquiry_date DATE DEFAULT NULL,
                next_followup_date DATE DEFAULT NULL,
                next_followup_time VARCHAR(20) DEFAULT NULL,
                referenced_by VARCHAR(120) DEFAULT NULL,
                comment TEXT DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'New',
                converted_atc_id INT DEFAULT NULL,
                converted_at DATETIME DEFAULT NULL,
                created_by VARCHAR(100) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_atc_enq_status (status),
                INDEX idx_atc_enq_mobile (mobile),
                INDEX idx_atc_enq_followup (next_followup_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        markSchemaFlag('schema_atc_onboarding_enq_v1');
    } catch (Exception $e) {
        error_log('[ATC onboarding enquiry schema] ' . $e->getMessage());
    }
}

/**
 * Resize/compress an uploaded image in place (or to $destPath).
 * Max edge 1280px; JPEG quality 72. Returns final path on success.
 */
function optimizeUploadedImage(string $srcPath, ?string $destPath = null, int $maxEdge = 1280, int $quality = 72): ?string {
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
    if ($ext === 'png' && $type === IMAGETYPE_PNG && ($nw * $nh) < 400000) {
        // Keep small transparent PNGs; large PNGs become JPEG for dashboard weight
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

/**
 * Lightweight image URL for dashboard carousel (max ~1280px JPEG under _dash/).
 * Falls back to original when GD is unavailable or media is video.
 *
 * @param string $imagePath  Filename only (e.g. banner_….jpg)
 * @param string $webPrefix  Relative URL prefix ending with / (e.g. ../uploads/announcements/)
 */
function announcementDashboardMediaSrc(string $imagePath, string $webPrefix = '../uploads/announcements/'): string {
    $imagePath = ltrim(str_replace(['\\', '..'], ['/', ''], $imagePath), '/');
    if ($imagePath === '') {
        return $webPrefix;
    }
    $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
    if (in_array($ext, ['mp4', 'webm', 'ogg'], true)) {
        return $webPrefix . $imagePath;
    }

    $uploadsFs = dirname(__DIR__) . '/uploads/announcements/';
    $srcFs = $uploadsFs . $imagePath;
    $dashDir = $uploadsFs . '_dash/';
    $dashName = pathinfo($imagePath, PATHINFO_FILENAME) . '.jpg';
    $dashFs = $dashDir . $dashName;
    $dashUrl = $webPrefix . '_dash/' . $dashName;

    if (is_file($dashFs)) {
        // Rebuild if source is newer
        if (!is_file($srcFs) || filemtime($dashFs) >= filemtime($srcFs)) {
            return $dashUrl . '?v=' . filemtime($dashFs);
        }
    }

    if (is_file($srcFs) && function_exists('imagecreatefromjpeg')) {
        if (!is_dir($dashDir)) {
            @mkdir($dashDir, 0755, true);
        }
        $made = optimizeUploadedImage($srcFs, $dashFs, 1280, 72);
        if ($made && is_file($made)) {
            if (@realpath($made) && @realpath($dashFs) && realpath($made) !== realpath($dashFs)) {
                @rename($made, $dashFs);
            } elseif (!is_file($dashFs) && is_file($made)) {
                @rename($made, $dashFs);
            }
            if (is_file($dashFs)) {
                return $dashUrl . '?v=' . filemtime($dashFs);
            }
        }
    }

    // Fallback: original (still cache-bust)
    $v = is_file($srcFs) ? filemtime($srcFs) : time();
    return $webPrefix . $imagePath . '?v=' . $v;
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
    ensureIndiaTimezone($pdo);
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
    ?string $paidAt = null,
    float $transactionFee = 0.0
): array {
    ensureSharePaymentSchema($pdo);
    ensureIndiaTimezone($pdo);

    $allowedModes = ['Cash', 'Bank Transfer', 'UPI', 'Cheque', 'Razorpay'];
    if (!in_array($paymentMode, $allowedModes, true)) {
        $paymentMode = 'Cash';
    }
    $transactionFee = max(0, round($transactionFee, 2));

    $nowIst = date('Y-m-d H:i:s');
    // Form sends date only (Y-m-d). Keep that calendar day, attach current IST clock time
    // so receipts don't show 12:00 AM and match when HO recorded the payment.
    $paidAtSql = $nowIst;
    if ($paidAt && preg_match('/^(\d{4}-\d{2}-\d{2})/', trim((string)$paidAt), $m)) {
        $paidAtSql = $m[1] . ' ' . date('H:i:s');
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

    $totalAmount = $totalShare + $transactionFee;

    $refNote = trim((string)$referenceNo);
    $remarkText = trim((string)$remarks);
    $modeLabel = $paymentMode === 'Razorpay'
        ? 'Razorpay (recorded)'
        : ($paymentMode . ($transactionFee > 0 ? ' + txn fee' : ' (offline)'));
    $parts = array_filter([
        $modeLabel,
        $transactionFee > 0 ? ('Txn fee: ₹' . number_format($transactionFee, 2)) : null,
        $refNote !== '' ? 'Ref: ' . $refNote : null,
        $remarkText !== '' ? $remarkText : null,
    ]);
    $combinedRemarks = implode(' · ', $parts);

    try {
        $pdo->beginTransaction();
        $ins = $pdo->prepare("
            INSERT INTO share_payments
                (atc_id, student_ids, total_share_amount, transaction_fee, total_amount, status, payment_mode, remarks, created_at)
            VALUES (?, ?, ?, ?, ?, 'Pending', ?, ?, ?)
        ");
        $ins->execute([
            $atcId,
            json_encode($validIds),
            $totalShare,
            $transactionFee,
            $totalAmount,
            $paymentMode,
            $combinedRemarks !== '' ? $combinedRemarks : null,
            $nowIst,
        ]);
        $paymentId = (int)$pdo->lastInsertId();
        $cashRef = ($paymentMode === 'Razorpay' ? 'RZP-' : 'CASH-') . $paymentId
            . ($refNote !== '' ? '-' . preg_replace('/[^A-Za-z0-9_-]/', '', substr($refNote, 0, 20)) : '');
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
                VALUES (?, ?, ?, ?, ?, 'Pending', ?, ?)
            ");
            $ins->execute([
                $atcId,
                json_encode($validIds),
                $totalShare,
                $transactionFee,
                $totalAmount,
                $combinedRemarks !== '' ? $combinedRemarks : null,
                $nowIst,
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

    try {
        $pdo->prepare("UPDATE share_payments SET paid_at = ?, created_at = COALESCE(created_at, ?) WHERE id = ?")
            ->execute([$paidAtSql, $nowIst, $paymentId]);
        $pdo->prepare("
            UPDATE admissions SET share_payment_date = ?
            WHERE atc_id = ? AND id IN ($placeholders)
        ")->execute(array_merge([$paidAtSql, $atcId], $validIds));
    } catch (Exception $e) {}

    return [
        'success' => true,
        'message' => 'Share payment recorded for ' . count($validIds) . ' student(s)'
            . ($transactionFee > 0 ? ' (incl. ₹' . number_format($transactionFee, 0) . ' txn fee).' : '.'),
        'payment_id' => $paymentId,
        'total_share_amount' => $totalShare,
        'transaction_fee' => $transactionFee,
        'total_amount' => $totalAmount,
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
    ensureIndiaTimezone($pdo);
    $nowIst = date('Y-m-d H:i:s');

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
                paid_at = COALESCE(paid_at, ?),
                failure_reason = NULL
            WHERE id = ? AND status IN ('Pending','Failed','Cancelled')
        ");
        $upd->execute([
            $razorpayPaymentId ?: null,
            $razorpayOrderId ?: null,
            $razorpaySignature ?: null,
            $nowIst,
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
 * Call Razorpay REST API (GET).
 * @return array{ok:bool,http:int,data:?array,error:?string}
 */
function razorpayApiRequest(string $method, string $path, ?array $body = null): array {
    if (!defined('RAZORPAY_KEY_ID') || !defined('RAZORPAY_KEY_SECRET')) {
        $rzpFile = __DIR__ . '/../config/razorpay.php';
        if (is_file($rzpFile)) {
            require_once $rzpFile;
        }
    }
    if (!defined('RAZORPAY_KEY_ID') || !defined('RAZORPAY_KEY_SECRET')) {
        return ['ok' => false, 'http' => 0, 'data' => null, 'error' => 'Razorpay keys not configured'];
    }
    $url = 'https://api.razorpay.com/v1/' . ltrim($path, '/');
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
    ];
    $method = strtoupper($method);
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? []);
    } elseif ($method !== 'GET') {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) {
        return ['ok' => false, 'http' => 0, 'data' => null, 'error' => $err];
    }
    $data = json_decode((string)$resp, true);
    return [
        'ok'    => $http >= 200 && $http < 300,
        'http'  => $http,
        'data'  => is_array($data) ? $data : null,
        'error' => ($http >= 200 && $http < 300) ? null : (($data['error']['description'] ?? null) ?: "HTTP $http"),
    ];
}

/**
 * If Razorpay already captured money for this share_payment order, mark it Completed.
 * Use after checkout dismiss / failed verify / admin sync.
 *
 * @return array{success:bool,completed:bool,message:string,razorpay_status?:string}
 */
function reconcileSharePaymentFromRazorpay(PDO $pdo, int $paymentId, ?int $atcId = null): array {
    ensureSharePaymentSchema($pdo);

    $sql = 'SELECT * FROM share_payments WHERE id = ?';
    $params = [$paymentId];
    if ($atcId) {
        $sql .= ' AND atc_id = ?';
        $params[] = $atcId;
    }
    $sql .= ' LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['success' => false, 'completed' => false, 'message' => 'Payment record not found'];
    }

    if (($row['status'] ?? '') === 'Completed') {
        applyHoSharePaidForPayment($pdo, $row);
        return ['success' => true, 'completed' => true, 'message' => 'Already completed', 'razorpay_status' => 'completed'];
    }

    $orderId = trim((string)($row['razorpay_order_id'] ?? ''));
    $captured = null;

    if ($orderId !== '') {
        $api = razorpayApiRequest('GET', 'orders/' . rawurlencode($orderId) . '/payments');
        if (!$api['ok']) {
            // Still try notes-based fallback below
            $api = ['ok' => false, 'data' => null, 'error' => $api['error'] ?? 'unknown'];
        } else {
            $items = $api['data']['items'] ?? [];
            if (is_array($items)) {
                foreach ($items as $p) {
                    $stt = strtolower((string)($p['status'] ?? ''));
                    if (in_array($stt, ['captured', 'authorized'], true)) {
                        $captured = $p;
                        break;
                    }
                }
            }
        }
    }

    // Fallback: UPI may capture under a linked payment that notes our local payment_id
    if (!$captured) {
        $captured = findCapturedRazorpayPaymentForLocalId($paymentId);
    }

    if (!$captured) {
        if ($orderId === '') {
            return [
                'success' => true,
                'completed' => false,
                'message' => 'No Razorpay order linked — payment was never started at the gateway.',
                'razorpay_status' => 'no_order',
            ];
        }
        return [
            'success' => true,
            'completed' => false,
            'message' => 'No Razorpay payment found for this order (checkout was closed without paying).',
            'razorpay_status' => 'no_payment',
        ];
    }

    $rzpPayId = (string)($captured['id'] ?? '');
    $orderFromPay = (string)($captured['order_id'] ?? $orderId);
    $result = completeSharePayment($pdo, $paymentId, $rzpPayId !== '' ? $rzpPayId : null, $orderFromPay !== '' ? $orderFromPay : null, null);
    return [
        'success'   => (bool)($result['success'] ?? false),
        'completed' => (bool)($result['success'] ?? false),
        'message'   => $result['success']
            ? 'Payment found on Razorpay and marked Completed.'
            : ($result['message'] ?? 'Failed to complete payment'),
        'razorpay_status' => (string)($captured['status'] ?? 'captured'),
    ];
}

/**
 * Scan recent Razorpay payments for notes.payment_id = local share_payments.id
 */
function findCapturedRazorpayPaymentForLocalId(int $localPaymentId): ?array {
    if ($localPaymentId <= 0) {
        return null;
    }
    $api = razorpayApiRequest('GET', 'payments?count=50');
    if (!$api['ok'] || !is_array($api['data']['items'] ?? null)) {
        return null;
    }
    foreach ($api['data']['items'] as $p) {
        if (!is_array($p)) {
            continue;
        }
        $noteId = (int)($p['notes']['payment_id'] ?? 0);
        $stt = strtolower((string)($p['status'] ?? ''));
        if ($noteId === $localPaymentId && in_array($stt, ['captured', 'authorized'], true)) {
            return $p;
        }
    }
    return null;
}

/**
 * Admin/ATC recovery: complete a share payment using a Razorpay payment id (pay_xxx)
 * or by matching a recent captured payment to a local Pending/Cancelled row.
 *
 * @return array{success:bool,completed:bool,message:string,payment_id?:int}
 */
function completeSharePaymentFromRazorpayPaymentId(PDO $pdo, string $razorpayPaymentId, ?int $preferLocalId = null): array {
    ensureSharePaymentSchema($pdo);
    $razorpayPaymentId = trim($razorpayPaymentId);
    if ($razorpayPaymentId === '' || !preg_match('/^pay_[A-Za-z0-9]+$/', $razorpayPaymentId)) {
        return ['success' => false, 'completed' => false, 'message' => 'Enter a valid Razorpay payment id (starts with pay_).'];
    }

    // Already linked?
    $dup = $pdo->prepare("SELECT id, status FROM share_payments WHERE razorpay_payment_id = ? LIMIT 1");
    $dup->execute([$razorpayPaymentId]);
    $existing = $dup->fetch(PDO::FETCH_ASSOC);
    if ($existing && ($existing['status'] ?? '') === 'Completed') {
        return [
            'success' => true,
            'completed' => true,
            'message' => 'Already recorded as Completed (payment #' . $existing['id'] . ').',
            'payment_id' => (int)$existing['id'],
        ];
    }

    $api = razorpayApiRequest('GET', 'payments/' . rawurlencode($razorpayPaymentId));
    if (!$api['ok'] || !is_array($api['data'])) {
        return [
            'success' => false,
            'completed' => false,
            'message' => 'Razorpay lookup failed: ' . ($api['error'] ?? 'not found'),
        ];
    }
    $p = $api['data'];
    $stt = strtolower((string)($p['status'] ?? ''));
    if (!in_array($stt, ['captured', 'authorized'], true)) {
        return [
            'success' => false,
            'completed' => false,
            'message' => 'Razorpay payment status is "' . ($p['status'] ?? 'unknown') . '", not captured.',
        ];
    }

    $orderId = (string)($p['order_id'] ?? '');
    $noteLocalId = (int)($p['notes']['payment_id'] ?? 0);
    $localId = $preferLocalId ?: $noteLocalId;

    $row = null;
    if ($localId > 0) {
        $st = $pdo->prepare('SELECT * FROM share_payments WHERE id = ? LIMIT 1');
        $st->execute([$localId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$row && $orderId !== '') {
        $st = $pdo->prepare('SELECT * FROM share_payments WHERE razorpay_order_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$orderId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$row && $existing) {
        $st = $pdo->prepare('SELECT * FROM share_payments WHERE id = ? LIMIT 1');
        $st->execute([(int)$existing['id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$row) {
        return [
            'success' => false,
            'completed' => false,
            'message' => 'Found captured Razorpay payment, but no matching share_payments row. Use Record Cash Share with UTR instead.',
        ];
    }

    $result = completeSharePayment(
        $pdo,
        (int)$row['id'],
        $razorpayPaymentId,
        $orderId !== '' ? $orderId : null,
        null
    );
    return [
        'success' => (bool)($result['success'] ?? false),
        'completed' => (bool)($result['success'] ?? false),
        'message' => $result['success']
            ? ('Marked payment #' . (int)$row['id'] . ' as Completed from Razorpay.')
            : ($result['message'] ?? 'Failed'),
        'payment_id' => (int)$row['id'],
    ];
}

/**
 * Scan last N Razorpay captures and complete any matching local Pending/Cancelled rows.
 * @return array{success:bool,message:string,completed_ids:int[]}
 */
function healRecentSharePaymentsFromRazorpay(PDO $pdo, ?int $atcId = null, int $limit = 12): array {
    ensureSharePaymentSchema($pdo);
    $completedIds = [];

    $sql = "
        SELECT id FROM share_payments
        WHERE status IN ('Pending','Cancelled','Failed')
          AND created_at >= (NOW() - INTERVAL 14 DAY)
    ";
    $params = [];
    if ($atcId) {
        $sql .= ' AND atc_id = ?';
        $params[] = $atcId;
    }
    $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(30, $limit));
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);

    foreach ($ids as $id) {
        $r = reconcileSharePaymentFromRazorpay($pdo, $id, $atcId);
        if (!empty($r['completed'])) {
            $completedIds[] = $id;
        }
    }

    // Also walk recent Razorpay payments → local notes
    $api = razorpayApiRequest('GET', 'payments?count=40');
    if ($api['ok'] && is_array($api['data']['items'] ?? null)) {
        foreach ($api['data']['items'] as $p) {
            if (!is_array($p)) continue;
            $stt = strtolower((string)($p['status'] ?? ''));
            if (!in_array($stt, ['captured', 'authorized'], true)) continue;
            $noteId = (int)($p['notes']['payment_id'] ?? 0);
            $payId = (string)($p['id'] ?? '');
            if ($noteId > 0 && $payId !== '') {
                $r = completeSharePaymentFromRazorpayPaymentId($pdo, $payId, $noteId);
                if (!empty($r['completed']) && !empty($r['payment_id'])) {
                    $completedIds[] = (int)$r['payment_id'];
                }
            }
        }
    }

    $completedIds = array_values(array_unique($completedIds));
    return [
        'success' => true,
        'completed_ids' => $completedIds,
        'message' => $completedIds
            ? ('Completed ' . count($completedIds) . ' payment(s): #' . implode(', #', $completedIds))
            : 'No captured Razorpay payments found to link.',
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
 * Map admission_id => true for Active students at an ATC who passed the main exam.
 * Prefers Exam Portal results; falls back to exam_schedules.exam_status = Passed.
 *
 * @return array<int,true>
 */
function atcMainExamPassAdmissionMap(PDO $pdo, int $atcId, string $atcCode = ''): array
{
    $map = [];
    $byReg = [];
    try {
        $st = $pdo->prepare("
            SELECT a.id, a.registration_id, a.roll_no, COALESCE(es.exam_status, '') AS exam_status
            FROM admissions a
            LEFT JOIN exam_schedules es ON es.admission_id = a.id AND es.atc_id = a.atc_id
            WHERE a.atc_id = ? AND a.status = 'Active'
        ");
        $st->execute([$atcId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $admId = (int)$row['id'];
            $reg = trim((string)($row['registration_id'] ?? ''));
            if ($reg === '') {
                $reg = trim((string)($row['roll_no'] ?? ''));
            }
            if ($reg !== '') {
                $byReg[strtoupper($reg)] = $admId;
            }
            if (($row['exam_status'] ?? '') === 'Passed') {
                $map[$admId] = true;
            }
        }
    } catch (Exception $e) {
        return $map;
    }

    if ($atcCode === '') {
        try {
            $c = $pdo->prepare('SELECT atc_code FROM atc_centers WHERE id = ? LIMIT 1');
            $c->execute([$atcId]);
            $atcCode = trim((string)($c->fetchColumn() ?: ''));
        } catch (Exception $e) {}
    }

    if ($atcCode !== '' && function_exists('examIntegrationReady') && examIntegrationReady()
        && function_exists('fetchAllExamResultsComplete') && function_exists('examSubmissionPassRecord')) {
        $res = fetchAllExamResultsComplete();
        if (!empty($res['success']) && !empty($res['data']['submissions'])) {
            foreach ($res['data']['submissions'] as $sub) {
                if (($sub['centre_name'] ?? '') !== $atcCode) {
                    continue;
                }
                $rec = examSubmissionPassRecord($sub);
                if (!$rec) {
                    continue;
                }
                $key = strtoupper(trim((string)($rec['identifier'] ?? '')));
                if ($key !== '' && isset($byReg[$key])) {
                    $map[$byReg[$key]] = true;
                }
            }
        }
    }

    return $map;
}

/**
 * Physical certificate status for ATC (no soft-copy).
 * received = Certificate line dispatched AND shipment marked Delivered.
 *
 * @return 'received'|'not_received'|'awaiting_exam'
 */
function admissionPhysicalCertificateStatus(PDO $pdo, int $admissionId, bool $examPassed): string
{
    if ($admissionId <= 0) {
        return 'awaiting_exam';
    }
    if (!$examPassed) {
        return 'awaiting_exam';
    }
    ensureDispatchTables($pdo);
    try {
        $st = $pdo->prepare("
            SELECT di.status AS item_status, md.status AS dispatch_status
            FROM dispatch_items di
            INNER JOIN material_dispatches md ON md.id = di.dispatch_id
            WHERE di.admission_id = ? AND di.item_type = 'Certificate'
            ORDER BY
                CASE WHEN di.status = 'Dispatched' AND md.status = 'Delivered' THEN 0
                     WHEN di.status = 'Dispatched' THEN 1
                     ELSE 2 END,
                di.id DESC
            LIMIT 1
        ");
        $st->execute([$admissionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && ($row['item_status'] ?? '') === 'Dispatched' && ($row['dispatch_status'] ?? '') === 'Delivered') {
            return 'received';
        }
    } catch (Exception $e) {}
    return 'not_received';
}

/**
 * Batch physical certificate statuses for many admissions.
 *
 * @param list<int> $admissionIds
 * @param array<int,bool> $examPassByAdmission
 * @return array<int,'received'|'not_received'|'awaiting_exam'>
 */
function admissionPhysicalCertificateStatusMap(PDO $pdo, array $admissionIds, array $examPassByAdmission = []): array
{
    $out = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $admissionIds))));
    foreach ($ids as $id) {
        $passed = !empty($examPassByAdmission[$id]);
        $out[$id] = $passed ? 'not_received' : 'awaiting_exam';
    }
    if ($ids === []) {
        return $out;
    }
    ensureDispatchTables($pdo);
    try {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("
            SELECT di.admission_id, di.status AS item_status, md.status AS dispatch_status
            FROM dispatch_items di
            INNER JOIN material_dispatches md ON md.id = di.dispatch_id
            WHERE di.admission_id IN ($ph) AND di.item_type = 'Certificate'
        ");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $admId = (int)$row['admission_id'];
            if (empty($examPassByAdmission[$admId]) && ($out[$admId] ?? '') === 'awaiting_exam') {
                continue;
            }
            if (($row['item_status'] ?? '') === 'Dispatched' && ($row['dispatch_status'] ?? '') === 'Delivered') {
                $out[$admId] = 'received';
            } elseif (($out[$admId] ?? '') !== 'received') {
                $out[$admId] = 'not_received';
            }
        }
    } catch (Exception $e) {}
    return $out;
}

/**
 * Validate local + exam-portal requirements before issuing a GIIT course certificate.
 *
 * @return array{eligible:bool,message:string,exam:?array}
 */
function validateCourseCertificateRequest(PDO $pdo, array $student, string $role): array
{
    // Soft-copy PDFs are Admin/DLC only — ATCs receive physical certificates via dispatch.
    if ($role === 'ATC CENTER') {
        return [
            'eligible' => false,
            'message'  => 'Soft-copy certificates are not available to ATC centres. Physical certificates are sent by Head Office; check Received / Not Received status on Completion Certificate.',
            'exam'     => null,
        ];
    }

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
    if ($ct === 'it' || $ct === 'typing') {
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
 * Temporary: ATC codes allowed to issue course certificates without an exam result.
 * Edit this list to enable/disable centers. Empty = nobody.
 */
function manualCourseCertificateAllowedAtcCodes(): array
{
    return [
        '202600002', // Netview EduNxt — temp manual certificate (no exam)
    ];
}

function atcCanUseManualCourseCertificate(?int $atcId, ?string $atcCode = null): bool
{
    $codes = manualCourseCertificateAllowedAtcCodes();
    if (empty($codes)) {
        return false;
    }
    $code = trim((string)$atcCode);
    if ($code !== '' && in_array($code, $codes, true)) {
        return true;
    }
    // Resolve code from id when session code is missing
    if ($atcId && $atcId > 0) {
        try {
            $pdo = function_exists('getDBConnection') ? getDBConnection() : null;
            if ($pdo) {
                $st = $pdo->prepare('SELECT atc_code FROM atc_centers WHERE id = ? LIMIT 1');
                $st->execute([$atcId]);
                $dbCode = trim((string)($st->fetchColumn() ?: ''));
                if ($dbCode !== '' && in_array($dbCode, $codes, true)) {
                    return true;
                }
            }
        } catch (Exception $e) {
            // ignore
        }
    }
    return false;
}

/**
 * Allocate / peek IT (GIIT) certificate numbers: GIIT2026-1, GIIT2026-2, …
 * When $allocate is false (preview), returns the next number without consuming it.
 */
function nextGiitCertificateNumber(PDO $pdo, ?int $year = null, bool $allocate = true): string
{
    $year = $year ?: (int)date('Y');
    if ($year < 2000 || $year > 2100) {
        $year = (int)date('Y');
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS giit_cert_series (
            series_year INT NOT NULL PRIMARY KEY,
            last_no INT NOT NULL DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        if ($allocate) {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO giit_cert_series (series_year, last_no) VALUES (?, 0)
                           ON DUPLICATE KEY UPDATE series_year = series_year')->execute([$year]);
            $st = $pdo->prepare('SELECT last_no FROM giit_cert_series WHERE series_year = ? FOR UPDATE');
            $st->execute([$year]);
            $next = (int)$st->fetchColumn() + 1;
            $pdo->prepare('UPDATE giit_cert_series SET last_no = ? WHERE series_year = ?')->execute([$next, $year]);
            $pdo->commit();
            return 'GIIT' . $year . '-' . $next;
        }

        $pdo->prepare('INSERT IGNORE INTO giit_cert_series (series_year, last_no) VALUES (?, 0)')->execute([$year]);
        $st = $pdo->prepare('SELECT last_no FROM giit_cert_series WHERE series_year = ?');
        $st->execute([$year]);
        $next = (int)$st->fetchColumn() + 1;
        return 'GIIT' . $year . '-' . $next;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return 'GIIT' . $year . '-1';
    }
}

/**
 * Build certificate number for a course brand.
 * IT → GIIT{year}-{n}; Abacus/other → courseAbbrev-regId-### (legacy).
 */
function buildCourseCertificateNumber(
    PDO $pdo,
    string $brand,
    string $regId,
    string $courseName,
    ?int $issueYear = null,
    bool $allocate = true
): string {
    if ($brand === 'it' || $brand === '') {
        return nextGiitCertificateNumber($pdo, $issueYear, $allocate);
    }

    $courseAbv = strtoupper(preg_replace('/[^A-Z0-9]/i', '', substr($courseName, 0, 6)));
    $certBase = $courseAbv . '-' . strtoupper(preg_replace('/\s+/', '', $regId));
    $counter = 1;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cert_counters (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reg_id VARCHAR(50) NOT NULL,
            course VARCHAR(200) NOT NULL,
            counter INT NOT NULL DEFAULT 1,
            issued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_reg_course (reg_id, course)
        )");
        if ($allocate) {
            $pdo->prepare("INSERT INTO cert_counters (reg_id, course, counter)
                           VALUES (?, ?, 1)
                           ON DUPLICATE KEY UPDATE counter = counter + 1")->execute([$regId, $courseName]);
        } else {
            $pdo->prepare("INSERT IGNORE INTO cert_counters (reg_id, course, counter) VALUES (?, ?, 1)")
                ->execute([$regId, $courseName]);
        }
        $cRow = $pdo->prepare('SELECT counter FROM cert_counters WHERE reg_id=? AND course=?');
        $cRow->execute([$regId, $courseName]);
        $counter = (int)($cRow->fetchColumn() ?: 1);
    } catch (Exception $e) {
        $counter = 1;
    }
    return $certBase . '-' . str_pad((string)$counter, 3, '0', STR_PAD_LEFT);
}

/** Public site base URL (parent of admin/atc/dlc) for certificate verify links. */
function certificatePublicBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    // Honor reverse-proxy HTTPS
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $scheme = 'https';
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim(dirname($script), '/');
    if (preg_match('#/(admin|atc|dlc)$#i', $dir)) {
        $dir = dirname($dir);
    }
    if ($dir === '/' || $dir === '\\' || $dir === '.' || $dir === '') {
        $dir = '';
    }
    return $scheme . '://' . $host . $dir;
}

function ensureIssuedCertificatesTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS issued_certificates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        verify_token VARCHAR(64) NOT NULL,
        cert_no VARCHAR(80) NOT NULL,
        student_name VARCHAR(200) NOT NULL,
        reg_id VARCHAR(80) NOT NULL DEFAULT '',
        course VARCHAR(200) NOT NULL,
        atc_name VARCHAR(200) NOT NULL DEFAULT '',
        atc_code VARCHAR(50) NOT NULL DEFAULT '',
        score INT NOT NULL DEFAULT 0,
        grade VARCHAR(20) NOT NULL DEFAULT '',
        duration VARCHAR(80) NOT NULL DEFAULT '',
        issue_date DATE NOT NULL,
        brand VARCHAR(20) NOT NULL DEFAULT 'it',
        photo_path VARCHAR(255) NULL,
        admission_id INT NULL,
        issued_by_atc_id INT NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'exam',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_verify_token (verify_token),
        KEY idx_cert_no (cert_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM issued_certificates LIKE 'photo_path'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE issued_certificates ADD COLUMN photo_path VARCHAR(255) NULL AFTER brand");
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Persist a certificate issuance snapshot and return verify token + URL.
 *
 * @param array{
 *   cert_no:string, student_name:string, reg_id?:string, course:string,
 *   atc_name?:string, atc_code?:string, score:int, grade:string,
 *   duration?:string, issue_date:string, brand?:string, photo_path?:string,
 *   admission_id?:?int, issued_by_atc_id?:?int, source?:string
 * } $data
 * @return array{token:string, verify_url:string, cert_no:string, id:int}
 */
function issueCertificateRecord(PDO $pdo, array $data): array
{
    ensureIssuedCertificatesTable($pdo);
    $token = bin2hex(random_bytes(16));
    $certNo = trim((string)($data['cert_no'] ?? ''));
    $issueDate = trim((string)($data['issue_date'] ?? date('Y-m-d')));
    if (preg_match('#^\d{2}/\d{2}/\d{4}$#', $issueDate)) {
        $ts = DateTime::createFromFormat('d/m/Y', $issueDate);
        $issueDate = $ts ? $ts->format('Y-m-d') : date('Y-m-d');
    } elseif (strtotime($issueDate) !== false) {
        $issueDate = date('Y-m-d', strtotime($issueDate));
    } else {
        $issueDate = date('Y-m-d');
    }

    $photoPath = trim((string)($data['photo_path'] ?? ''));
    if ($photoPath === '' && !empty($data['admission_id'])) {
        try {
            $ps = $pdo->prepare('SELECT photo FROM admissions WHERE id = ? LIMIT 1');
            $ps->execute([(int)$data['admission_id']]);
            $photoPath = trim((string)($ps->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            $photoPath = '';
        }
    }

    $stmt = $pdo->prepare("INSERT INTO issued_certificates
        (verify_token, cert_no, student_name, reg_id, course, atc_name, atc_code,
         score, grade, duration, issue_date, brand, photo_path, admission_id, issued_by_atc_id, source)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $token,
        $certNo,
        trim((string)($data['student_name'] ?? '')),
        trim((string)($data['reg_id'] ?? '')),
        trim((string)($data['course'] ?? '')),
        trim((string)($data['atc_name'] ?? '')),
        trim((string)($data['atc_code'] ?? '')),
        (int)($data['score'] ?? 0),
        trim((string)($data['grade'] ?? '')),
        trim((string)($data['duration'] ?? '')),
        $issueDate,
        trim((string)($data['brand'] ?? 'it')) ?: 'it',
        $photoPath !== '' ? $photoPath : null,
        isset($data['admission_id']) && $data['admission_id'] ? (int)$data['admission_id'] : null,
        isset($data['issued_by_atc_id']) && $data['issued_by_atc_id'] ? (int)$data['issued_by_atc_id'] : null,
        trim((string)($data['source'] ?? 'exam')) ?: 'exam',
    ]);

    $verifyUrl = rtrim(certificatePublicBaseUrl(), '/') . '/verify_certificate.php?t=' . urlencode($token);
    return [
        'token' => $token,
        'verify_url' => $verifyUrl,
        'cert_no' => $certNo,
        'id' => (int)$pdo->lastInsertId(),
    ];
}

/**
 * Resolve a public web URL for an issued-certificate photo (relative uploads path).
 */
function issuedCertificatePhotoUrl(?array $record, PDO $pdo): string
{
    if (!$record) {
        return '';
    }
    $rel = trim((string)($record['photo_path'] ?? ''));
    if ($rel === '' && !empty($record['admission_id'])) {
        try {
            $st = $pdo->prepare('SELECT photo FROM admissions WHERE id = ? LIMIT 1');
            $st->execute([(int)$record['admission_id']]);
            $rel = trim((string)($st->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            $rel = '';
        }
    }
    if ($rel === '') {
        return '';
    }
    $fs = __DIR__ . '/../' . ltrim(str_replace('\\', '/', $rel), '/');
    if (!is_file($fs)) {
        return '';
    }
    $ver = @filemtime($fs) ?: time();
    return rtrim(certificatePublicBaseUrl(), '/') . '/' . ltrim(str_replace('\\', '/', $rel), '/') . '?v=' . $ver;
}

function findIssuedCertificateByToken(PDO $pdo, string $token): ?array
{
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{32}$/i', $token)) {
        return null;
    }
    ensureIssuedCertificatesTable($pdo);
    $st = $pdo->prepare('SELECT * FROM issued_certificates WHERE verify_token = ? LIMIT 1');
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function findIssuedCertificateByCertNo(PDO $pdo, string $certNo): ?array
{
    $certNo = trim($certNo);
    if ($certNo === '') {
        return null;
    }
    ensureIssuedCertificatesTable($pdo);
    $st = $pdo->prepare('SELECT * FROM issued_certificates WHERE cert_no = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$certNo]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Generate a QR for $url and draw it on the PDF as black modules only
 * (no white background card). Returns null (no temp file to clean).
 */
function embedCertificateVerifyQr($pdf, string $url, float $x = 168.0, float $y = 242.0, float $sizeMm = 24.0): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    $qrLib = __DIR__ . '/../assets/phpqrcode/qrcode.php';
    if (!is_file($qrLib)) {
        return null;
    }
    if (!class_exists('QRCode', false)) {
        require_once $qrLib;
    }

    try {
        $generator = new QRCode($url, ['s' => 'qrl']);
        $ref = new ReflectionClass($generator);
        $method = $ref->getMethod('dispatch_encode');
        $method->setAccessible(true);
        $code = $method->invoke($generator, $url, ['s' => 'qrl']);
        $matrix = $code['b'] ?? null;
        if (!is_array($matrix) || $matrix === []) {
            return null;
        }

        $rows = count($matrix);
        $cols = count($matrix[0]);
        if ($rows < 1 || $cols < 1) {
            return null;
        }

        $module = $sizeMm / max($rows, $cols);
        $drawW = $cols * $module;
        $drawH = $rows * $module;
        $ox = $x + ($sizeMm - $drawW) / 2;
        $oy = $y + ($sizeMm - $drawH) / 2;

        $pdf->SetFillColor(15, 15, 15);
        $pdf->SetDrawColor(15, 15, 15);
        $pdf->SetLineWidth(0);
        foreach ($matrix as $ry => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $rx => $dark) {
                if (!$dark) {
                    continue;
                }
                $pdf->Rect(
                    $ox + ((int)$rx) * $module,
                    $oy + ((int)$ry) * $module,
                    $module + 0.02,
                    $module + 0.02,
                    'F'
                );
            }
        }

        $pdf->SetTextColor(50, 50, 50);
        $pdf->SetFont('Helvetica', '', 5.5);
        $pdf->SetXY($x, $y + $sizeMm + 0.3);
        $pdf->Cell($sizeMm, 2.8, 'Scan to verify', 0, 0, 'C');
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Start a one-page A4 FPDI document for course certificates.
 * Forces A4 + disables auto page-break so Corel blanks (~185×275mm MediaBox)
 * cannot spill photo/QR/footer onto extra pages.
 *
 * @param array{type?:string,path?:string}|null $template
 * @return array{0:\setasign\Fpdi\Fpdi,1:float,2:float}
 */
function beginCourseCertificatePdf(?array $template): array
{
    if (!class_exists(\setasign\Fpdi\Fpdi::class, false)) {
        $autoload = __DIR__ . '/../assets/fpdi/fpdi_autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    $pdf = new \setasign\Fpdi\Fpdi();
    $W = 210.0;
    $H = 297.0;
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->AddPage('P', [$W, $H]);

    if ($template && ($template['type'] ?? '') === 'pdf' && !empty($template['path']) && is_file($template['path'])) {
        $pdf->setSourceFile($template['path']);
        $tplId = $pdf->importPage(1);
        // Stretch blank to A4 so overlay coords stay consistent
        $pdf->useTemplate($tplId, 0, 0, $W, $H);
    } elseif ($template && ($template['type'] ?? '') === 'png' && !empty($template['path']) && is_file($template['path'])) {
        $pdf->Image($template['path'], 0, 0, $W, $H, 'PNG');
    }

    return [$pdf, $W, $H];
}

/**
 * Overlay layout + typography for GIIT blank course certificate (A4 mm).
 * Full body block matches official sample line order.
 *
 * @return array<string,float|string>
 */
function courseCertificateOverlayLayout(): array
{
    return [
        // Body lines (top → bottom)
        'certify_y' => 128.0,
        'name_y' => 140.0,
        'completed_y' => 152.0,
        'course_y' => 164.0,
        'conducted_label_y' => 176.0,
        'atc_y' => 186.0,
        'duration_y' => 198.0,
        'grade_y' => 208.0,
        // Footer — keep above signature band; QR shares this row
        'cert_x' => 28.0,
        'cert_y' => 226.0,
        'date_y' => 234.0,
        'photo_x' => 162.0,
        'photo_y' => 102.0,
        'photo_w' => 28.0,
        'photo_h' => 34.0,
        'qr_x' => 168.0,
        'qr_y' => 224.0,
        'qr_size' => 20.0,
        // Typography (Times) — labels match ATC/duration/grade (navy bold)
        'label_size' => 13.0,
        'label_style' => 'B',
        'label_color' => '0,0,128',
        'name_size' => 20.0,
        'name_style' => 'B',
        'name_color' => '192,0,0',
        'course_size' => 15.0,
        'course_style' => 'B',
        'course_color' => '192,0,0',
        'meta_size' => 13.0,
        'meta_style' => 'B',
        'meta_color' => '0,0,128',
        'footer_size' => 11.0,
        'footer_style' => 'B',
        'footer_color' => '30,30,30',
    ];
}

/** Grade line text matching GIIT sample wording/quotes. */
function courseCertificateGradeLine(string $grade): string
{
    return 'and has passed the examination with "' . $grade . '" grade';
}

/**
 * Paint the centered certificate body text (labels + dynamic values) onto an FPDI page.
 *
 * @param callable(string,float,float,string,string):void $put Centered text helper
 */
function paintCourseCertificateBodyText(
    callable $put,
    string $fullName,
    string $courseName,
    string $conductedAt,
    string $durationLine,
    string $gradeLine,
    ?array $layout = null
): void {
    $L = $layout ?? courseCertificateOverlayLayout();
    $put('This is to certify That', (float)$L['certify_y'], (float)$L['label_size'], (string)$L['label_style'], (string)$L['label_color']);
    $put($fullName, (float)$L['name_y'], (float)$L['name_size'], (string)$L['name_style'], (string)$L['name_color']);
    $put('Has Successfully completed', (float)$L['completed_y'], (float)$L['label_size'], (string)$L['label_style'], (string)$L['label_color']);
    $put($courseName, (float)$L['course_y'], (float)$L['course_size'], (string)$L['course_style'], (string)$L['course_color']);
    $put('Conducted at', (float)$L['conducted_label_y'], (float)$L['label_size'], (string)$L['label_style'], (string)$L['label_color']);
    $put($conductedAt, (float)$L['atc_y'], (float)$L['meta_size'], (string)$L['meta_style'], (string)$L['meta_color']);
    $put($durationLine, (float)$L['duration_y'], (float)$L['meta_size'], (string)$L['meta_style'], (string)$L['meta_color']);
    $put($gradeLine, (float)$L['grade_y'], (float)$L['meta_size'], (string)$L['meta_style'], (string)$L['meta_color']);
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

/**
 * Allowed announcement audiences.
 *
 * @return list<string>
 */
function announcementAudienceOptions(): array {
    return ['All', 'Admin', 'ATC', 'DLC'];
}

/**
 * Normalize a posted/stored audience value to a known option.
 */
function normalizeAnnouncementAudience(?string $audience): string {
    $audience = trim((string)$audience);
    foreach (announcementAudienceOptions() as $opt) {
        if (strcasecmp($audience, $opt) === 0) {
            return $opt;
        }
    }
    return 'All';
}

/**
 * Ensure announcements columns support Admin audience + ATC visibility mapping.
 * Old ENUM(target_audience) without 'Admin' silently stored '' and hid banners everywhere.
 */
function ensureAnnouncementAtcVisibilitySchema(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $colMeta = $pdo->query('SHOW COLUMNS FROM announcements')->fetchAll(PDO::FETCH_ASSOC);
        $cols = array_column($colMeta, 'Field');
        $typeByField = [];
        foreach ($colMeta as $c) {
            $typeByField[$c['Field']] = strtolower((string)($c['Type'] ?? ''));
        }

        // Widen target_audience so Admin (and future values) can be stored
        $audType = $typeByField['target_audience'] ?? '';
        $needsWiden = $audType === ''
            || str_contains($audType, 'enum')
            || (str_contains($audType, 'varchar') && preg_match('/varchar\((\d+)\)/', $audType, $m) && (int)$m[1] < 20);
        if ($needsWiden || !in_array('target_audience', $cols, true)) {
            if (!in_array('target_audience', $cols, true)) {
                $pdo->exec("ALTER TABLE announcements ADD COLUMN target_audience VARCHAR(20) NOT NULL DEFAULT 'All'");
            } else {
                $pdo->exec("ALTER TABLE announcements MODIFY COLUMN target_audience VARCHAR(20) NOT NULL DEFAULT 'All'");
            }
        }

        if (!in_array('orientation', $cols, true)) {
            $pdo->exec("ALTER TABLE announcements ADD COLUMN orientation VARCHAR(20) NOT NULL DEFAULT 'horizontal'");
        }
        if (!in_array('visibility_scope', $cols, true)) {
            $pdo->exec("ALTER TABLE announcements ADD COLUMN visibility_scope VARCHAR(20) NOT NULL DEFAULT 'all' COMMENT 'all|type|specific'");
        }
        if (!in_array('center_types', $cols, true)) {
            $pdo->exec("ALTER TABLE announcements ADD COLUMN center_types VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'JSON list of master types when visibility_scope=type'");
        }

        // Repair rows corrupted by old ENUM (empty / unknown audience)
        $pdo->exec("
            UPDATE announcements
            SET target_audience = 'All'
            WHERE target_audience IS NULL
               OR TRIM(target_audience) = ''
               OR target_audience NOT IN ('All', 'Admin', 'ATC', 'DLC')
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS announcement_atc_visibility (
                id INT AUTO_INCREMENT PRIMARY KEY,
                announcement_id INT NOT NULL,
                atc_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_ann_atc (announcement_id, atc_id),
                KEY idx_aav_atc (atc_id),
                KEY idx_aav_ann (announcement_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {
        error_log('[AnnAtcVis] ' . $e->getMessage());
        // Allow retry on next request if migration partially failed
        $done = false;
    }
}

/**
 * Normalize visibility scope: all | type | specific
 */
function normalizeAnnouncementVisibilityScope(?string $scope): string {
    $scope = strtolower(trim((string)$scope));
    if ($scope === 'specific' || $scope === 'type') {
        return $scope;
    }
    return 'all';
}

/**
 * Normalize posted/stored center type list to master types only.
 *
 * @param mixed $raw
 * @return list<string>
 */
function normalizeAnnouncementCenterTypes($raw): array {
    $allowed = masterCourseTypes();
    $items = [];
    if (is_string($raw)) {
        $trim = trim($raw);
        if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
            $decoded = json_decode($trim, true);
            $items = is_array($decoded) ? $decoded : [];
        } else {
            $items = preg_split('/\s*,\s*/', $trim) ?: [];
        }
    } elseif (is_array($raw)) {
        $items = $raw;
    }
    $out = [];
    foreach ($items as $item) {
        $item = trim((string)$item);
        foreach ($allowed as $opt) {
            if (strcasecmp($item, $opt) === 0) {
                $out[$opt] = $opt;
                break;
            }
        }
    }
    return array_values($out);
}

/**
 * Encode center types for DB storage.
 *
 * @param list<string> $types
 */
function encodeAnnouncementCenterTypes(array $types): string {
    $types = normalizeAnnouncementCenterTypes($types);
    return $types === [] ? '' : json_encode($types, JSON_UNESCAPED_UNICODE);
}

/**
 * Decode center_types column.
 *
 * @return list<string>
 */
function decodeAnnouncementCenterTypes(?string $raw): array {
    return normalizeAnnouncementCenterTypes($raw);
}

/**
 * Whether an ATC center_type matches any selected banner center types.
 *
 * @param list<string> $selectedTypes
 */
function announcementMatchesCenterTypes(array $selectedTypes, ?string $atcCenterType): bool {
    $selectedTypes = normalizeAnnouncementCenterTypes($selectedTypes);
    if ($selectedTypes === []) {
        return false;
    }
    foreach ($selectedTypes as $t) {
        if (courseIsVisibleToCenter($t, $atcCenterType)) {
            return true;
        }
    }
    return false;
}

/**
 * Active dashboard banners — lean columns, capped.
 * $audience = Admin|ATC|DLC
 *  - All → every dashboard
 *  - Admin → Admin only
 *  - ATC → ATC (+ Admin HO overview)
 *  - DLC → DLC (+ Admin HO overview)
 *
 * ATC visibility_scope:
 *  - all = every ATC
 *  - type = ATCs matching center_types
 *  - specific = mapped ATCs only
 */
function getActiveAnnouncements(PDO $pdo, string $audience, int $limit = 8, ?int $atcId = null): array {
    $audience = normalizeAnnouncementAudience($audience);
    if ($audience === 'All') {
        $audience = 'Admin'; // treat unknown/All callers as HO overview
    }
    ensureAnnouncementAtcVisibilitySchema($pdo);
    $limit = max(1, min(20, (int)$limit));

    try {
        if ($audience === 'Admin') {
            // Head office sees every active banner (including Admin-only)
            $sql = "
                SELECT id, title, image_path, orientation, target_audience, visibility_scope, center_types, created_at
                FROM announcements
                WHERE status = 'Active'
                  AND target_audience IN ('All', 'Admin', 'ATC', 'DLC')
                ORDER BY created_at DESC
                LIMIT {$limit}
            ";
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $sql = "
            SELECT id, title, image_path, orientation, target_audience, visibility_scope, center_types, created_at
            FROM announcements
            WHERE status = 'Active' AND target_audience IN ('All', ?)
        ";
        $params = [$audience];

        // Pre-filter specific mappings in SQL; type matching done in PHP
        if ($audience === 'ATC' && $atcId !== null && $atcId > 0) {
            $sql .= "
                AND (
                    COALESCE(visibility_scope, 'all') IN ('all', 'type')
                    OR EXISTS (
                        SELECT 1 FROM announcement_atc_visibility aav
                        WHERE aav.announcement_id = announcements.id AND aav.atc_id = ?
                    )
                )
            ";
            $params[] = (int)$atcId;
        }

        $fetchLimit = ($audience === 'ATC' && $atcId) ? max($limit * 4, 24) : $limit;
        $sql .= " ORDER BY created_at DESC LIMIT {$fetchLimit}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($audience === 'ATC' && $atcId !== null && $atcId > 0) {
            $centerType = getAtcCenterType($pdo, (int)$atcId);
            $filtered = [];
            foreach ($rows as $row) {
                $scope = normalizeAnnouncementVisibilityScope($row['visibility_scope'] ?? 'all');
                if ($scope === 'type') {
                    $types = decodeAnnouncementCenterTypes($row['center_types'] ?? '');
                    if (!announcementMatchesCenterTypes($types, $centerType)) {
                        continue;
                    }
                }
                $filtered[] = $row;
                if (count($filtered) >= $limit) {
                    break;
                }
            }
            return $filtered;
        }

        return array_slice($rows, 0, $limit);
    } catch (Exception $e) {
        error_log('[getActiveAnnouncements] ' . $e->getMessage());
        return [];
    }
}

/**
 * Save ATC assignment for a banner. Ignored when audience is DLC/Admin-only.
 *
 * @param list<int> $atcIds
 * @param list<string> $centerTypes
 */
function saveAnnouncementAtcVisibility(
    PDO $pdo,
    int $announcementId,
    string $scope,
    array $atcIds,
    string $targetAudience = 'All',
    array $centerTypes = []
): void {
    ensureAnnouncementAtcVisibilitySchema($pdo);
    if ($announcementId <= 0) {
        return;
    }
    $audience = normalizeAnnouncementAudience($targetAudience);
    $scope = normalizeAnnouncementVisibilityScope($scope);
    $centerTypes = normalizeAnnouncementCenterTypes($centerTypes);

    // DLC / Admin-only banners do not use ATC mapping
    if ($audience === 'DLC' || $audience === 'Admin') {
        $scope = 'all';
        $atcIds = [];
        $centerTypes = [];
    }

    if ($scope === 'type') {
        $atcIds = [];
        if ($centerTypes === []) {
            $scope = 'all';
        }
    } elseif ($scope === 'specific') {
        $centerTypes = [];
        if ($atcIds === []) {
            $scope = 'all';
        }
    } else {
        $scope = 'all';
        $atcIds = [];
        $centerTypes = [];
    }

    $pdo->prepare('UPDATE announcements SET visibility_scope = ?, center_types = ? WHERE id = ?')
        ->execute([$scope, encodeAnnouncementCenterTypes($centerTypes), $announcementId]);
    $pdo->prepare('DELETE FROM announcement_atc_visibility WHERE announcement_id = ?')->execute([$announcementId]);

    if ($scope !== 'specific') {
        return;
    }
    $ins = $pdo->prepare('INSERT IGNORE INTO announcement_atc_visibility (announcement_id, atc_id) VALUES (?, ?)');
    foreach ($atcIds as $atcId) {
        $atcId = (int)$atcId;
        if ($atcId > 0) {
            $ins->execute([$announcementId, $atcId]);
        }
    }
}

/**
 * @return list<int>
 */
function getAnnouncementAssignedAtcIds(PDO $pdo, int $announcementId): array {
    ensureAnnouncementAtcVisibilitySchema($pdo);
    if ($announcementId <= 0) {
        return [];
    }
    try {
        $st = $pdo->prepare('SELECT atc_id FROM announcement_atc_visibility WHERE announcement_id = ?');
        $st->execute([$announcementId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Active banners an ATC may download from Downloads.
 *
 * @return list<array>
 */
function getDownloadableBannersForAtc(PDO $pdo, int $atcId): array {
    if ($atcId <= 0) {
        return [];
    }
    ensureAnnouncementAtcVisibilitySchema($pdo);
    try {
        $stmt = $pdo->prepare("
            SELECT id, title, image_path, orientation, target_audience, visibility_scope, center_types, created_at, status
            FROM announcements
            WHERE status = 'Active'
              AND target_audience IN ('All', 'ATC')
              AND (
                  COALESCE(visibility_scope, 'all') IN ('all', 'type')
                  OR EXISTS (
                      SELECT 1 FROM announcement_atc_visibility aav
                      WHERE aav.announcement_id = announcements.id AND aav.atc_id = ?
                  )
              )
            ORDER BY created_at DESC
        ");
        $stmt->execute([$atcId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $centerType = getAtcCenterType($pdo, $atcId);
        $out = [];
        foreach ($rows as $row) {
            $scope = normalizeAnnouncementVisibilityScope($row['visibility_scope'] ?? 'all');
            if ($scope === 'type') {
                $types = decodeAnnouncementCenterTypes($row['center_types'] ?? '');
                if (!announcementMatchesCenterTypes($types, $centerType)) {
                    continue;
                }
            }
            $out[] = $row;
        }
        return $out;
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

/** Display name for the course-kit T-shirt option (all sizes). */
function globalTshirtCourseMarkerName(): string {
    return 'Tshirts';
}

/**
 * System inventory row used on courses to mean "include a T-shirt (size per student)".
 */
function ensureGlobalTshirtCourseMarkerItem(PDO $pdo): int {
    ensureInventoryTables($pdo);
    $name = globalTshirtCourseMarkerName();
    try {
        $stmt = $pdo->prepare("
            SELECT id FROM inventory_items
            WHERE category = 'T-Shirts' AND LOWER(TRIM(item_name)) = LOWER(?)
            LIMIT 1
        ");
        $stmt->execute([$name]);
        $id = (int)$stmt->fetchColumn();
        if ($id > 0) {
            return $id;
        }

        $ins = $pdo->prepare("
            INSERT INTO inventory_items
                (item_name, category, unit, current_stock, min_stock_level, description, status)
            VALUES (?, 'T-Shirts', 'pcs', 0, 0, ?, 'Active')
        ");
        $ins->execute([
            $name,
            'System marker: course kit includes T-shirts; student size is chosen at dispatch.',
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        error_log('[GlobalTshirtMarker] ' . $e->getMessage());
        return 0;
    }
}

function isGlobalTshirtCourseMarkerItem(array $item): bool {
    if ((string)($item['category'] ?? '') !== 'T-Shirts') {
        return false;
    }
    return strcasecmp(trim((string)($item['item_name'] ?? '')), globalTshirtCourseMarkerName()) === 0;
}

function isSizeSpecificTshirtInventoryItem(array $item): bool {
    return (string)($item['category'] ?? '') === 'T-Shirts' && !isGlobalTshirtCourseMarkerItem($item);
}

/**
 * Pick the stocked inventory row for a student's T-shirt size.
 */
function resolveStudentTshirtInventoryItem(array $student, array $inventoryItems): ?array {
    $size = trim((string)($student['uniform_size'] ?? ''));
    if ($size === '') {
        return null;
    }

    foreach ($inventoryItems as $inv) {
        if (!isSizeSpecificTshirtInventoryItem($inv)) {
            continue;
        }
        if (stripos((string)($inv['item_name'] ?? ''), $size) !== false) {
            return $inv;
        }
    }

    return null;
}

/**
 * Resolve a mapped course item to the inventory row used for stock/dispatch.
 */
function resolveMappedInventoryItemForStudent(array $mappedItem, array $student, array $allInventoryItems): ?array {
    if (!courseMappedItemAppliesToStudent($mappedItem, $student)) {
        return null;
    }

    if (!isGlobalTshirtCourseMarkerItem($mappedItem)) {
        return $mappedItem;
    }

    $resolved = resolveStudentTshirtInventoryItem($student, $allInventoryItems);
    if ($resolved) {
        return array_merge($mappedItem, [
            'id' => (int)$resolved['id'],
            'item_name' => (string)$resolved['item_name'],
            'current_stock' => (int)($resolved['current_stock'] ?? 0),
        ]);
    }

    return array_merge($mappedItem, [
        'id' => null,
        'current_stock' => 0,
    ]);
}

/**
 * Whether a mapped inventory item belongs on this student's kit.
 */
function courseMappedItemAppliesToStudent(array $item, array $student): bool {
    $cat  = (string)($item['category'] ?? '');
    $name = (string)($item['item_name'] ?? '');

    if ($cat === 'T-Shirts') {
        if (isGlobalTshirtCourseMarkerItem($item)) {
            return trim((string)($student['uniform_size'] ?? '')) !== '';
        }
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
    ensureDispatchTables($pdo);

    if (!function_exists('examIntegrationReady')) {
        $examFile = __DIR__ . '/exam_integration.php';
        if (is_file($examFile)) {
            require_once $examFile;
        }
    }

    $atcCode = '';
    try {
        $c = $pdo->prepare('SELECT atc_code FROM atc_centers WHERE id = ? LIMIT 1');
        $c->execute([$atcId]);
        $atcCode = trim((string)($c->fetchColumn() ?: ''));
    } catch (Exception $e) {}

    $examPassMap = atcMainExamPassAdmissionMap($pdo, $atcId, $atcCode);

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
    ) use (&$dispatchedMap, &$dispatchedInv, &$legacyDispatched): bool {
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
            foreach ($mappedItems as $inv) {
                $resolvedInv = resolveMappedInventoryItemForStudent($inv, $s, $invItemsLegacy);
                if ($resolvedInv === null) {
                    continue;
                }

                $type = dispatchMaterialTypeForCategory((string)$inv['category']);
                // Certificates are exam-driven — never from kit mapping alone
                if ($type === 'Certificate') {
                    continue;
                }
                $detail = dispatchItemDetailForMappedItem($inv, $s);
                $invId = !empty($resolvedInv['id']) ? (int)$resolvedInv['id'] : null;
                $already = $dispatchedMap[$s['id'] . '_' . $type . '_' . $detail] ?? null;
                if ($isLineDispatched($s, $type, $detail, $invId)) {
                    continue;
                }
                $stock = (int)($resolvedInv['current_stock'] ?? 0);
                $materials[] = [
                    'type' => $type,
                    'detail' => $detail,
                    'inventory_item_id' => $invId,
                    'inventory_item_name' => (string)($resolvedInv['item_name'] ?? $inv['item_name'] ?? ''),
                    'stock' => $stock,
                    'status' => $lineStatus($already, $stock),
                    'pending_dispatch_id' => $already === 'Pending',
                ];
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

    // ── Exam-pass → pending Course Completion Certificate (physical print/dispatch) ──
    $resultById = [];
    foreach ($result as $idx => $row) {
        $resultById[(int)$row['id']] = $idx;
    }

    $passIds = array_keys($examPassMap);
    $passStudents = [];
    if (!empty($passIds)) {
        try {
            $ph = implode(',', array_fill(0, count($passIds), '?'));
            $ps = $pdo->prepare("
                SELECT a.id, a.roll_no, a.registration_id,
                       TRIM(CONCAT(a.first_name,' ',COALESCE(a.middle_name,''),' ',a.last_name)) AS student_name,
                       a.course, a.admission_date
                FROM admissions a
                WHERE a.atc_id = ? AND a.status = 'Active' AND a.id IN ($ph)
            ");
            $ps->execute(array_merge([$atcId], $passIds));
            $passStudents = $ps->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            $passStudents = [];
        }
    }

    // Refresh dispatched map for pass students not already in With Material set
    $extraIds = [];
    foreach ($passStudents as $psRow) {
        if (!isset($dispatchedMap[$psRow['id'] . '_Certificate_' . ($psRow['course'] ?? 'General')])) {
            $extraIds[] = (int)$psRow['id'];
        }
    }
    if (!empty($extraIds)) {
        try {
            $ph = implode(',', array_fill(0, count($extraIds), '?'));
            $diStmt = $pdo->prepare("SELECT admission_id, item_type, item_detail, inventory_item_id, status FROM dispatch_items WHERE admission_id IN ($ph) AND item_type = 'Certificate'");
            $diStmt->execute($extraIds);
            foreach ($diStmt->fetchAll(PDO::FETCH_ASSOC) as $di) {
                $key = $di['admission_id'] . '_' . $di['item_type'] . '_' . $di['item_detail'];
                $dispatchedMap[$key] = $di['status'];
                $iid = (int)($di['inventory_item_id'] ?? 0);
                if ($iid > 0 && $di['status'] === 'Dispatched') {
                    $dispatchedInv[(int)$di['admission_id']][$iid] = true;
                }
            }
        } catch (Exception $e) {}
    }

    $matchedCert = null;
    $matchedCertStock = 0;
    foreach ($invItemsLegacy as $inv) {
        if (($inv['category'] ?? '') === 'Certificates' && stripos((string)$inv['item_name'], 'Course Completion') !== false) {
            $matchedCert = $inv;
            $matchedCertStock = (int)$inv['current_stock'];
            break;
        }
    }

    foreach ($passStudents as $psRow) {
        $detail = (string)($psRow['course'] ?? 'General');
        $sStub = ['id' => (int)$psRow['id']];
        if ($isLineDispatched($sStub, 'Certificate', $detail, $matchedCert ? (int)$matchedCert['id'] : null)) {
            continue;
        }
        $certKey = $psRow['id'] . '_Certificate_' . $detail;
        $certStatus = $dispatchedMap[$certKey] ?? null;
        $certMaterial = [
            'type' => 'Certificate',
            'detail' => $detail,
            'inventory_item_id' => $matchedCert ? (int)$matchedCert['id'] : null,
            'inventory_item_name' => $matchedCert['item_name'] ?? 'Course Completion Certificate',
            'stock' => $matchedCertStock,
            'status' => $lineStatus($certStatus, $matchedCertStock),
            'pending_dispatch_id' => $certStatus === 'Pending',
            'reason' => 'exam_pass',
        ];

        $admId = (int)$psRow['id'];
        if (isset($resultById[$admId])) {
            $idx = $resultById[$admId];
            $result[$idx]['materials'][] = $certMaterial;
        } else {
            $resultById[$admId] = count($result);
            $result[] = [
                'id' => $admId,
                'student_name' => $psRow['student_name'],
                'roll_no' => $psRow['roll_no'],
                'registration_id' => $psRow['registration_id'],
                'course' => $psRow['course'],
                'admission_date' => $psRow['admission_date'],
                'materials' => [$certMaterial],
            ];
        }

        $label = (string)($certMaterial['inventory_item_name'] ?: ('Certificate — ' . $detail));
        $tid = $certMaterial['inventory_item_id'] ? ('id:' . $certMaterial['inventory_item_id']) : ('name:' . $label);
        if (!isset($totals[$tid])) {
            $totals[$tid] = [
                'inventory_item_id' => $certMaterial['inventory_item_id'],
                'item_name' => $label,
                'category' => 'Certificate',
                'qty' => 0,
                'stock' => (int)$certMaterial['stock'],
            ];
        }
        $totals[$tid]['qty']++;
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


