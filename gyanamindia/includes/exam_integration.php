<?php
/**
 * Exam Portal Integration — Gyanam India
 * 
 * Helper functions for communicating with the Laravel-based Exam Portal API
 * at gyanamexam.labxco.in via server-to-server cURL requests.
 */

/**
 * Generic authenticated request to the Exam Portal API.
 *
 * @param  string $method   HTTP method (GET, POST, PUT, DELETE)
 * @param  string $endpoint API endpoint (e.g. '/students')
 * @param  array  $data     Request body (for POST/PUT) or query params (for GET)
 * @return array  ['success' => bool, 'data' => mixed, 'error' => string|null, 'http_code' => int]
 */
function examApi_request(string $method, string $endpoint, array $data = []): array
{
    $url = EXAM_API_URL . $endpoint;

    // For GET requests, append data as query params
    if ($method === 'GET' && !empty($data)) {
        $url .= '?' . http_build_query($data);
    }

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
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
        return ['success' => true, 'data' => $decoded, 'error' => null, 'http_code' => $httpCode];
    }

    $errMsg = $decoded['message'] ?? $decoded['error'] ?? "HTTP {$httpCode}";
    error_log("[ExamAPI] Error on {$method} {$endpoint}: {$errMsg} (HTTP {$httpCode})");
    return ['success' => false, 'data' => $decoded, 'error' => $errMsg, 'http_code' => $httpCode];
}

// ─────────────────────────────────────────────────────────────────────────────
// STUDENT SYNC
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Register (or update) a student in the Exam Portal.
 *
 * @param  string $registrationId  The globally unique GIES ID (e.g. "GIES15")
 * @param  string $fullName        Student full name
 * @param  string $atcCode         ATC centre code (e.g. "ATC1") → maps to centre_name
 * @param  string $examSlot        SLOT1 | SLOT2 | SLOT3
 * @param  string $timeWindow      MORNING | AFTERNOON | EVENING
 * @return array  API result
 */
function syncStudentToExamPortal(
    string $registrationId,
    string $fullName,
    string $atcCode,
    string $examSlot = 'SLOT1',
    string $timeWindow = 'MORNING'
): array {
    return examApi_request('POST', '/students', [
        'identifier'  => $registrationId,
        'name'        => $fullName,
        'centre_name' => $atcCode,
        'exam_slot'   => $examSlot,
        'time_window' => $timeWindow,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// RESULTS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fetch exam results for a specific student from the Exam Portal.
 *
 * @param  string $registrationId  The GIES identifier
 * @return array  API result — ['success' => bool, 'data' => [...submissions...]]
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
 * @return array  API result — ['success' => bool, 'data' => ['submissions' => [...], 'stats' => [...]]]
 */
function fetchAllExamResults(): array
{
    return examApi_request('GET', '/results');
}

// ─────────────────────────────────────────────────────────────────────────────
// EXAM ASSIGNMENTS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fetch all active exams available from the Exam Portal.
 *
 * @return array API result — list of exam configs
 */
function fetchAvailableExams(): array
{
    return examApi_request('GET', '/assignments/exams');
}

/**
 * Fetch all students synced in the Exam Portal along with their assignments.
 *
 * @return array API result — list of students with assignments
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

// ─────────────────────────────────────────────────────────────────────────────
// PORTAL USER SYNC (ATC / DLC login accounts)
// ─────────────────────────────────────────────────────────────────────────────

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
    // Map main portal roles → exam portal roles
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

// ─────────────────────────────────────────────────────────────────────────────
// COURSE SYNC
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Push all active courses from the main portal to the Exam Portal.
 * Called manually or on course create/update to keep QBs in sync.
 *
 * @param  PDO $pdo  Database connection to the main portal
 * @return array  API result
 */
function syncCoursesToExamPortal(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, course_name, course_type, duration, status FROM courses WHERE status = 'Active' ORDER BY course_name");
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return examApi_request('POST', '/portal-courses', [
        'courses' => $courses,
    ]);
}

/**
 * Fetch the list of courses synced in the Exam Portal.
 *
 * @return array  API result — ['success' => bool, 'data' => ['courses' => [...]]]
 */
function fetchPortalCourses(): array
{
    return examApi_request('GET', '/portal-courses');
}

// ─────────────────────────────────────────────────────────────────────────────
// ATC CENTRE METADATA SYNC
// ─────────────────────────────────────────────────────────────────────────────

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
