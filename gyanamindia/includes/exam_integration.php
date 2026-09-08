<?php
/**
 * Exam Portal Integration â€” Gyanam India
 *
 * Helper functions for communicating with the Laravel-based Exam Portal API
 * at gyanamexam.labxco.in via server-to-server cURL requests.
 *
 * Results endpoints support pagination (?page=&per_page=&q=). Prefer paginated
 * fetches for large centres. See gyanamexam/docs/PRODUCTION_CAPACITY.md before
 * scheduling ~1000 concurrent examinees.
 */

/**
 * Generic authenticated request to the Exam Portal API.
 *
 * @param  string $method   HTTP method (GET, POST, PUT, DELETE)
 * @param  string $endpoint API endpoint (e.g. '/students')
 * @param  array  $data     Request body (for POST/PUT) or query params (for GET)
 * @return array  ['success' => bool, 'data' => mixed, 'error' => string|null, 'http_code' => int]
 */
/**
 * Session cache helper for GET Exam API responses (reduces repeated 15s cURL waits).
 */
function examApi_cacheGet(string $key, int $ttlSeconds = 90): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    $bucket = $_SESSION['exam_api_cache'][$key] ?? null;
    if (!is_array($bucket) || !isset($bucket['at'], $bucket['data'])) {
        return null;
    }
    if ((time() - (int)$bucket['at']) > $ttlSeconds) {
        return null;
    }
    return $bucket['data'];
}

function examApi_cacheSet(string $key, array $payload): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    if (!isset($_SESSION['exam_api_cache']) || !is_array($_SESSION['exam_api_cache'])) {
        $_SESSION['exam_api_cache'] = [];
    }
    $_SESSION['exam_api_cache'][$key] = ['at' => time(), 'data' => $payload];
    // Cap cache size to avoid session bloat
    if (count($_SESSION['exam_api_cache']) > 20) {
        array_shift($_SESSION['exam_api_cache']);
    }
}

function examApi_cacheForget(string $prefix = ''): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['exam_api_cache'])) {
        return;
    }
    if ($prefix === '') {
        unset($_SESSION['exam_api_cache']);
        return;
    }
    foreach (array_keys($_SESSION['exam_api_cache']) as $key) {
        if (str_starts_with((string)$key, $prefix)) {
            unset($_SESSION['exam_api_cache'][$key]);
        }
    }
}

function examApi_request(string $method, string $endpoint, array $data = [], bool $useCache = true, int $timeout = 8): array
{
    $method = strtoupper($method);
    $cacheKey = null;
    $timeout = max(3, $timeout);

    if ($method === 'GET' && $useCache) {
        $cacheKey = $method . ':' . $endpoint . ':' . md5(json_encode($data));
        $cached = examApi_cacheGet($cacheKey, 90);
        if ($cached !== null) {
            return $cached;
        }
    }

    $url = EXAM_API_URL . $endpoint;

    // For GET requests, append data as query params
    if ($method === 'GET' && !empty($data)) {
        $url .= '?' . http_build_query($data);
    }

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . EXAM_API_TOKEN,
        ],
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    // On shared hosting, SSL verification might need the CA bundle
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log("[ExamAPI] cURL error on {$method} {$endpoint}: {$curlErr}");
        return ['success' => false, 'data' => null, 'error' => 'Connection failed: ' . $curlErr, 'http_code' => 0];
    }

    $decoded = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        $ok = ['success' => true, 'data' => $decoded, 'error' => null, 'http_code' => $httpCode];
        if ($cacheKey !== null) {
            examApi_cacheSet($cacheKey, $ok);
        }
        // Mutating calls invalidate related GET caches
        if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
            examApi_cacheForget('GET:');
        }
        return $ok;
    }

    $errMsg = $decoded['message'] ?? $decoded['error'] ?? "HTTP {$httpCode}";
    error_log("[ExamAPI] Error on {$method} {$endpoint}: {$errMsg} (HTTP {$httpCode})");
    return ['success' => false, 'data' => $decoded, 'error' => $errMsg, 'http_code' => $httpCode];
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// STUDENT SYNC
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

/**
 * Build absolute public URL for an admission photo path (uploads/â€¦).
 */
function examPortalAbsolutePhotoUrl(?string $relativePhoto): ?string
{
    $rel = trim((string) $relativePhoto);
    if ($rel === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $rel)) {
        return $rel;
    }
    if (!function_exists('certificatePublicBaseUrl')) {
        return null;
    }
    return rtrim(certificatePublicBaseUrl(), '/') . '/' . ltrim(str_replace('\\', '/', $rel), '/');
}

/**
 * Register (or update) a student in the Exam Portal.
 *
 * @param  string      $registrationId  The globally unique GIES ID (e.g. "GIES15")
 * @param  string      $fullName        Student full name
 * @param  string      $atcCode         ATC centre code (e.g. "ATC1") â†’ maps to centre_name
 * @param  string      $examSlot        SLOT1 | SLOT2 | SLOT3
 * @param  string      $timeWindow      MORNING | AFTERNOON | EVENING
 * @param  string|null $photoUrl       Absolute photo URL (optional)
 * @param  string|null $course         Course name (optional)
 * @return array  API result
 */
function syncStudentToExamPortal(
    string $registrationId,
    string $fullName,
    string $atcCode,
    string $examSlot = 'SLOT1',
    string $timeWindow = 'MORNING',
    ?string $photoUrl = null,
    ?string $course = null
): array {
    $payload = [
        'identifier'  => $registrationId,
        'name'        => $fullName,
        'centre_name' => $atcCode,
        'exam_slot'   => $examSlot,
        'time_window' => $timeWindow,
    ];
    if ($photoUrl) {
        $payload['photo_url'] = $photoUrl;
    }
    if ($course) {
        $payload['course'] = $course;
    }
    return examApi_request('POST', '/students', $payload);
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// RESULTS
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

/**
 * Fetch exam results for a specific student from the Exam Portal.
 *
 * @param  string $registrationId  The GIES identifier
 * @return array  API result â€” ['success' => bool, 'data' => [...submissions...]]
 */
function fetchStudentExamResults(string $registrationId): array
{
    return examApi_request('GET', '/results', [
        'student_identifier' => $registrationId,
    ]);
}

/**
 * Fetch ALL exam results from the Exam Portal (admin sees all, ATC sees own centre).
 * The Exam Portal scopes results automatically based on the authenticated user's centre_id.
 *
 * @return array  API result â€” ['success' => bool, 'data' => ['submissions' => [...], 'stats' => [...]]]
 */
function fetchAllExamResults(int $page = 1, int $perPage = 100): array
{
    return examApi_request('GET', '/results', [
        'page'     => max(1, $page),
        'per_page' => max(1, min(200, $perPage)),
    ]);
}

/**
 * Whether Exam Portal API is configured for server-to-server calls.
 */
function examIntegrationReady(): bool
{
    return defined('EXAM_API_TOKEN')
        && EXAM_API_TOKEN !== 'PASTE_YOUR_TOKEN_HERE'
        && defined('EXAM_API_URL')
        && EXAM_API_URL !== '';
}

/** Demo/practice exams must not count toward official certificates. */
function examSubmissionIsDemo(array $sub): bool
{
    $examTitle = strtolower((string)($sub['exam_title'] ?? ($sub['exam']['title'] ?? '')));
    return str_contains($examTitle, 'demo');
}

/**
 * Normalize a passing, non-demo submission for certificate use.
 *
 * @return array{identifier:string,score:int,exam_date:string,exam_title:string,submitted_at:?string}|null
 */
function examSubmissionPassRecord(array $sub): ?array
{
    if (examSubmissionIsDemo($sub)) {
        return null;
    }
    if (strtolower((string)($sub['result'] ?? '')) !== 'pass') {
        return null;
    }
    $id = trim((string)($sub['student']['identifier'] ?? ''));
    if ($id === '') {
        return null;
    }
    $score = (int)($sub['score'] ?? 0);
    return [
        'identifier'   => $id,
        'score'        => $score,
        'exam_date'    => date('Y-m-d', strtotime((string)($sub['submitted_at'] ?? 'now'))),
        'exam_title'   => (string)($sub['exam_title'] ?? ($sub['exam']['title'] ?? '')),
        'submitted_at' => $sub['submitted_at'] ?? null,
    ];
}

/**
 * Fetch all exam results across paginated API pages (for certificates / dashboards).
 *
 * @return array Same shape as examApi_request â€” data.submissions holds every page merged.
 */
function fetchAllExamResultsComplete(int $perPage = 100): array
{
    if (!examIntegrationReady()) {
        return ['success' => false, 'data' => null, 'error' => 'Exam portal not configured', 'http_code' => 0];
    }

    $allSubs = [];
    $stats   = [];
    $page    = 1;
    $lastPage = 1;

    do {
        $res = fetchAllExamResults($page, $perPage);
        if (!$res['success']) {
            if ($page === 1) {
                return $res;
            }
            break;
        }
        $data = $res['data'] ?? [];
        $batch = $data['submissions'] ?? [];
        if (is_array($batch)) {
            $allSubs = array_merge($allSubs, $batch);
        }
        if (!empty($data['stats']) && is_array($data['stats'])) {
            $stats = $data['stats'];
        }
        $lastPage = max(1, (int)($data['pagination']['last_page'] ?? 1));
        $page++;
    } while ($page <= $lastPage && $page <= 100);

    return [
        'success'   => true,
        'data'      => [
            'submissions' => $allSubs,
            'stats'       => $stats,
            'pagination'  => [
                'page'      => 1,
                'per_page'  => count($allSubs),
                'total'     => count($allSubs),
                'last_page' => 1,
            ],
        ],
        'error'     => null,
        'http_code' => 200,
    ];
}

/**
 * Index best passing main-exam result per student identifier.
 *
 * @return array<string, array{identifier:string,score:int,exam_date:string,exam_title:string,submitted_at:?string}>
 */
function buildExamPassIndex(array $submissions): array
{
    $index = [];
    foreach ($submissions as $sub) {
        if (!is_array($sub)) {
            continue;
        }
        $rec = examSubmissionPassRecord($sub);
        if (!$rec) {
            continue;
        }
        $id = $rec['identifier'];
        if (!isset($index[$id]) || $rec['score'] > $index[$id]['score']) {
            $index[$id] = $rec;
        }
    }
    return $index;
}

/**
 * Authoritative pass record for one student from the Exam Portal (non-demo, passed).
 */
function fetchStudentPassingExamResult(string $registrationId): ?array
{
    if (!examIntegrationReady()) {
        return null;
    }
    $registrationId = trim($registrationId);
    if ($registrationId === '') {
        return null;
    }

    $res = fetchStudentExamResults($registrationId);
    if (!$res['success']) {
        return null;
    }

    $subs = $res['data']['submissions'] ?? [];
    if (!is_array($subs)) {
        return null;
    }

    $best = null;
    foreach ($subs as $sub) {
        if (!is_array($sub)) {
            continue;
        }
        $rec = examSubmissionPassRecord($sub);
        if (!$rec) {
            continue;
        }
        if ($best === null || $rec['score'] > $best['score']) {
            $best = $rec;
        }
    }
    return $best;
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// EXAM ASSIGNMENTS
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

/**
 * Fetch all active exams available from the Exam Portal.
 *
 * @return array API result â€” list of exam configs
 */
function fetchAvailableExams(): array
{
    return examApi_request('GET', '/assignments/exams');
}

/**
 * Fetch all students synced in the Exam Portal along with their assignments.
 *
 * @return array API result â€” list of students with assignments
 */
function fetchExamStudents(): array
{
    return examApi_request('GET', '/assignments/students');
}

/**
 * Assign an exam to a student in the Exam Portal.
 *
 * @param  int $examPortalStudentId  The student's internal ID in the Exam Portal
 * @param  int $examId              The exam config ID
 * @param  int $maxAttempts          Number of allowed attempts
 * @return array API result
 */
function assignExamToStudent(int $examPortalStudentId, int $examId, int $maxAttempts = 1): array
{
    return examApi_request('POST', '/assignments/assign', [
        'student_id'   => $examPortalStudentId,
        'exam_id'      => $examId,
        'max_attempts' => $maxAttempts,
    ]);
}

/**
 * Remove an exam assignment from a student.
 *
 * @param  int $examPortalStudentId  The student's internal ID in the Exam Portal
 * @param  int $examId              The exam config ID
 * @return array API result
 */
function unassignExamFromStudent(int $examPortalStudentId, int $examId): array
{
    return examApi_request('DELETE', "/assignments/{$examPortalStudentId}/exams/{$examId}");
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// PORTAL USER SYNC (ATC / DLC login accounts)
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

/**
 * Create or update an ATC/DLC user account in the Exam Portal.
 * Called whenever a user is added, edited, or has their password reset
 * in the Gyanam India admin panel.
 *
 * @param  string      $username   Portal username (must match main portal)
 * @param  string      $name       Full display name
 * @param  string|null $email      Email address (optional)
 * @param  string|null $password   Plain-text password (only passed when set/reset)
 * @param  string      $role       Main portal role: 'ATC CENTER' | 'DLC Office' | 'Admin'
 * @param  string|null $centreId   ATC code e.g. 'ATC1', or 'DLC{id}' for DLC users
 * @return array  API result
 */
function syncPortalUserToExam(
    string  $username,
    string  $name,
    ?string $email,
    ?string $password,
    string  $role,
    ?string $centreId = null
): array {
    // Map main portal roles â†’ exam portal roles
    $examRole = match ($role) {
        'ATC CENTER' => 'atc',
        'DLC Office' => 'dlc',
        'Admin'      => 'admin',
        default      => 'atc',
    };

    $payload = [
        'username'  => $username,
        'name'      => $name,
        'email'     => $email,
        'role'      => $examRole,
        'centre_id' => $centreId,
    ];

    // Only include password if one was actually provided (avoid overwriting with null)
    if (!empty($password)) {
        $payload['password'] = $password;
    }

    return examApi_request('POST', '/portal-users', $payload);
}

/**
 * Remove an ATC/DLC user account from the Exam Portal when deleted from main portal.
 *
 * @param  string $username  The username to delete
 * @return array  API result
 */
function deletePortalUserFromExam(string $username): array
{
    return examApi_request('DELETE', '/portal-users/' . urlencode($username));
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// COURSE SYNC
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

/**
 * Push courses from the main portal to the Exam Portal.
 * Includes Active and Inactive so exam QBs can target every course name.
 *
 * @param  PDO $pdo  Database connection to the main portal
 * @return array  API result
 */
function syncCoursesToExamPortal(PDO $pdo): array
{
    // Exam portal QB/exams use IT courses only â€” push all Active IT courses
    try {
        $stmt = $pdo->query("
            SELECT id, course_name, course_type, duration, status
            FROM courses
            WHERE status = 'Active'
              AND UPPER(TRIM(COALESCE(course_type, ''))) = 'IT'
            ORDER BY course_name ASC
        ");
    } catch (Throwable $e) {
        // Older DBs may lack status / course_type
        try {
            $stmt = $pdo->query("
                SELECT id, course_name, course_type, duration, 'Active' AS status
                FROM courses
                WHERE UPPER(TRIM(COALESCE(course_type, ''))) = 'IT'
                ORDER BY course_name ASC
            ");
        } catch (Throwable $e2) {
            $stmt = $pdo->query("SELECT id, course_name, course_type, duration FROM courses ORDER BY course_name ASC");
        }
    }
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalize + keep only IT Active (in case of fallback query)
    $normalized = [];
    foreach ($courses as $c) {
        $status = trim((string)($c['status'] ?? 'Active'));
        if ($status === '') {
            $status = 'Active';
        }
        $type = strtoupper(trim((string)($c['course_type'] ?? '')));
        if ($type !== 'IT') {
            continue;
        }
        if (strcasecmp($status, 'Active') !== 0) {
            continue;
        }
        $name = trim((string)($c['course_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $normalized[] = [
            'id'          => $c['id'] ?? null,
            'course_name' => $name,
            'course_type' => $c['course_type'] ?? 'IT',
            'duration'    => $c['duration'] ?? null,
            'status'      => 'Active',
        ];
    }
    $courses = $normalized;

    $sourceCount = count($courses);
    if ($sourceCount === 0) {
        return [
            'success'   => false,
            'data'      => ['source_count' => 0, 'count' => 0],
            'error'     => 'No Active IT courses found in the main portal database to sync.',
            'http_code' => 0,
        ];
    }

    // Longer timeout: course payloads can be large; skip GET response cache
    $result = examApi_request('POST', '/portal-courses', [
        'courses' => $courses,
    ], false, 45);

    if (empty($result['success'])) {
        $err = $result['error'] ?? 'Sync failed';
        $result['error'] = $err . " (tried to push {$sourceCount} Active IT course(s) from main portal DB)";
        $result['data'] = array_merge(is_array($result['data'] ?? null) ? $result['data'] : [], [
            'source_count' => $sourceCount,
        ]);
        return $result;
    }

    // Confirm the exam API actually stored the full list (catches silent write failures)
    $verify = examApi_request('GET', '/portal-courses?fresh=1', [], false, 20);
    $verifiedCount = null;
    if (!empty($verify['success']) && is_array($verify['data']['courses'] ?? null)) {
        $verifiedCount = count($verify['data']['courses']);
    }

    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    $data['source_count'] = $sourceCount;
    $data['count'] = $sourceCount;
    $data['verified_count'] = $verifiedCount;
    $data['message'] = "{$sourceCount} Active IT course(s) pushed to Exam Portal.";

    if ($verifiedCount === null) {
        $result['success'] = false;
        $result['error'] = "Pushed {$sourceCount} Active IT course(s), but could not verify exam portal storage. Check EXAM_API_URL / token.";
        $result['data'] = $data;
        return $result;
    }

    if ($verifiedCount < $sourceCount) {
        $result['success'] = false;
        $result['error'] = "Pushed {$sourceCount} Active IT course(s), but exam portal still has only {$verifiedCount}. "
            . 'Check write permissions on gyanam-backend/storage/app (portal_courses.json).';
        $result['data'] = $data;
        return $result;
    }

    $data['message'] = "{$verifiedCount} Active IT course(s) synced and verified on Exam Portal.";
    $result['data'] = $data;
    return $result;
}

/**
 * Fetch the list of courses synced in the Exam Portal.
 *
 * @return array  API result â€” ['success' => bool, 'data' => ['courses' => [...]]]
 */
function fetchPortalCourses(): array
{
    return examApi_request('GET', '/portal-courses');
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// ATC CENTRE METADATA SYNC
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

/**
 * Push all active ATC centre metadata (code, name, center_type, district, state)
 * to the Exam Portal so QB assignment can filter by centre type.
 * Called automatically on ATC center create / update / delete.
 *
 * @param  PDO $pdo  Database connection to the main portal
 * @return array  API result
 */
function syncATCCentresToExamPortal(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT atc_code AS code, name, center_type AS centre_type, district, state
        FROM atc_centers
        WHERE status = 'Active' AND atc_code IS NOT NULL AND atc_code != ''
        ORDER BY name
    ");
    $centres = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return examApi_request('POST', '/portal-atc-centres', [
        'centres' => $centres,
    ]);
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// QUESTION BANKS (ATC PDF downloads)
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

/**
 * Resolve ATC code for the logged-in ATC session.
 */
function examPortalAtcCodeFromSession(?PDO $pdo = null): string
{
    $code = trim((string)($_SESSION['atc_code'] ?? ''));
    if ($code !== '') {
        return $code;
    }
    $atcId = (int)($_SESSION['atc_id'] ?? 0);
    if ($atcId <= 0) {
        return '';
    }
    try {
        $db = $pdo ?? (function_exists('getDBConnection') ? getDBConnection() : null);
        if (!$db) {
            return '';
        }
        $st = $db->prepare('SELECT atc_code FROM atc_centers WHERE id = ? LIMIT 1');
        $st->execute([$atcId]);
        $code = trim((string)($st->fetchColumn() ?: ''));
        if ($code !== '') {
            $_SESSION['atc_code'] = $code;
        }
        return $code;
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * List question banks assigned to an ATC centre code.
 *
 * @return array{success:bool,banks:list<array>,error:?string}
 */
function fetchAssignedQuestionBanks(string $atcCode): array
{
    $atcCode = trim($atcCode);
    if ($atcCode === '') {
        return ['success' => false, 'banks' => [], 'error' => 'ATC code missing'];
    }
    if (!examIntegrationReady()) {
        return ['success' => false, 'banks' => [], 'error' => 'Exam portal API is not configured'];
    }

    $res = examApi_request('GET', '/question-banks/for-centre', [
        'centre_id' => $atcCode,
    ], true, 15);

    if (!$res['success']) {
        return [
            'success' => false,
            'banks'   => [],
            'error'   => $res['error'] ?? 'Failed to load question banks',
        ];
    }

    $banks = $res['data']['banks'] ?? [];
    if (!is_array($banks)) {
        $banks = [];
    }

    return ['success' => true, 'banks' => $banks, 'error' => null];
}

/**
 * Fetch full question bank export for PDF generation.
 *
 * @return array{success:bool,data:?array,error:?string}
 */
function fetchQuestionBankExport(string $atcCode, int $bankId, bool $includeAnswers = true): array
{
    $atcCode = trim($atcCode);
    if ($atcCode === '' || $bankId <= 0) {
        return ['success' => false, 'data' => null, 'error' => 'Invalid request'];
    }
    if (!examIntegrationReady()) {
        return ['success' => false, 'data' => null, 'error' => 'Exam portal API is not configured'];
    }

    $res = examApi_request('GET', '/question-banks/' . $bankId . '/export', [
        'centre_id'        => $atcCode,
        'include_answers'  => $includeAnswers ? '1' : '0',
    ], false, 30);

    if (!$res['success'] || !is_array($res['data'] ?? null)) {
        return [
            'success' => false,
            'data'    => null,
            'error'   => $res['error'] ?? 'Failed to export question bank',
        ];
    }

    return ['success' => true, 'data' => $res['data'], 'error' => null];
}

/**
 * Sanitize text for FPDF (Latin-1).
 */
function questionBankPdfText(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\r\n|\r|\n/", ' ', $text) ?? $text;
    $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
    return $converted !== false ? $converted : $text;
}

/**
 * Resolve GIIT letterhead + logo paths for question-bank PDFs.
 *
 * @return array{letterhead:?string,logo:?string}
 */
function questionBankPdfBrandPaths(): array
{
    $candidatesLetterhead = [
        __DIR__ . '/../assets/branding/giit_letterhead.png',
        __DIR__ . '/../assets/templates/giit_marksheet_header.png',
        __DIR__ . '/../../image.png',
    ];
    $candidatesLogo = [
        __DIR__ . '/../assets/giit_logo.png',
        __DIR__ . '/../assets/giit_brand_logo.png',
        __DIR__ . '/../assets/templates/giit_marksheet_logo.png',
    ];

    $letterhead = null;
    foreach ($candidatesLetterhead as $path) {
        if (is_file($path)) {
            $letterhead = $path;
            break;
        }
    }
    $logo = null;
    foreach ($candidatesLogo as $path) {
        if (is_file($path)) {
            $logo = $path;
            break;
        }
    }
    return ['letterhead' => $letterhead, 'logo' => $logo];
}

/**
 * Stream a branded tabular question bank PDF (FPDF).
 * Page 1: full GIIT letterhead. Every page: GIIT logo + question table.
 *
 * @param array $export Data from fetchQuestionBankExport()['data']
 */
function streamQuestionBankPdf(array $export, string $atcCode): void
{
    $autoload = __DIR__ . '/../assets/fpdi/fpdi_autoload.php';
    if (!file_exists($autoload)) {
        throw new RuntimeException('PDF library not found');
    }
    require_once $autoload;
    require_once __DIR__ . '/QuestionBankBrandedPdf.php';

    $title = (string)($export['title'] ?? 'Question Bank');
    $subject = (string)($export['subject'] ?? '');
    $includeAnswers = !empty($export['include_answers']);
    $questions = is_array($export['questions'] ?? null) ? $export['questions'] : [];
    $brand = questionBankPdfBrandPaths();

    $pdf = new QuestionBankBrandedPdf('L', 'mm', 'A4');
    $pdf->letterheadPath = $brand['letterhead'];
    $pdf->logoPath = $brand['logo'];
    $pdf->docTitle = $title;
    $pdf->atcCode = $atcCode;
    $pdf->subject = $subject;
    $pdf->includeAnswers = $includeAnswers;
    $pdf->AliasNbPages();
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 14);

    if ($includeAnswers) {
        $pdf->colWidths = [12, 78, 42, 42, 42, 42, 14];
    } else {
        $pdf->colWidths = [12, 84, 44, 44, 44, 44];
    }

    $pdf->AddPage();

    $pdf->SetFont('Arial', 'B', 13);
    $pdf->SetTextColor(197, 32, 38);
    $pdf->Cell(0, 6, questionBankPdfText($title), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 8);
    $metaBits = [];
    if ($subject !== '') {
        $metaBits[] = 'Course: ' . $subject;
    }
    $metaBits[] = 'ATC: ' . $atcCode;
    $metaBits[] = 'Total Questions: ' . count($questions);
    $metaBits[] = 'Generated: ' . date('d M Y H:i');
    $metaBits[] = $includeAnswers ? 'Copy: ATC staff (with answers)' : 'Copy: Practice (no answers)';
    $pdf->SetTextColor(70, 70, 70);
    $pdf->MultiCell(0, 4, questionBankPdfText(implode('   |   ', $metaBits)), 0, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2);

    $pdf->renderTableHeader();
    $pdf->drawTableHeaderNext = true;

    if (empty($questions)) {
        $pdf->SetFont('Arial', 'I', 10);
        $pdf->Cell(array_sum($pdf->colWidths), 10, 'No questions in this bank yet.', 1, 1, 'C');
    }

    foreach ($questions as $idx => $q) {
        $num = (int)($q['number'] ?? ($idx + 1));
        $text = questionBankPdfText((string)($q['text'] ?? ''));
        $options = is_array($q['options'] ?? null) ? $q['options'] : [];
        $correct = strtoupper(trim((string)($q['correct_answer'] ?? '')));

        $byId = ['A' => '', 'B' => '', 'C' => '', 'D' => ''];
        foreach ($options as $opt) {
            $oid = strtoupper(trim((string)($opt['id'] ?? '')));
            if ($oid !== '' && array_key_exists($oid, $byId)) {
                $byId[$oid] = questionBankPdfText((string)($opt['text'] ?? ''));
            }
        }
        if ($byId['A'] === '' && $byId['B'] === '' && count($options) >= 1) {
            $labels = ['A', 'B', 'C', 'D'];
            foreach ($options as $i => $opt) {
                if (!isset($labels[$i])) {
                    break;
                }
                $byId[$labels[$i]] = questionBankPdfText((string)($opt['text'] ?? ''));
            }
        }

        $cells = [
            (string)$num,
            $text,
            $byId['A'],
            $byId['B'],
            $byId['C'],
            $byId['D'],
        ];
        if ($includeAnswers) {
            $cells[] = $correct !== '' ? $correct : '-';
        }

        $pdf->drawDataRow($cells, $idx % 2 === 1);
    }

    $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $title) ?: 'question_bank';
    $filename = $safeName . '_' . $atcCode . '.pdf';

    $pdf->Output('D', $filename);
    exit;
}
