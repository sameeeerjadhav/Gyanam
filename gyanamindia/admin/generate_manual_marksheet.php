<?php
/**
 * Admin-only: Statement of Marks without exam portal.
 * POST/GET: admission_id + score [, preview=1]
 * Typing courses use the detailed particulars table.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/statement_of_marks_pdf.php';
requireLogin(['Admin']);

$pdo = getDBConnection();

$src = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
$preview = isset($src['preview']) || isset($_GET['preview']);
$score = (int)($src['score'] ?? 0);
$admissionId = (int)($src['admission_id'] ?? 0);

if ($admissionId <= 0) {
    http_response_code(400);
    die('<b>Error:</b> Select a student first.');
}

$exam40Pre = isset($src['exam_40']) ? (int)$src['exam_40'] : null;
$atc60Pre = isset($src['atc_marks']) ? (int)$src['atc_marks'] : null;
if ($exam40Pre !== null && $atc60Pre !== null) {
    $score = max(0, min(40, $exam40Pre)) + max(0, min(60, $atc60Pre));
}

if ($score < 40 || $score > 100) {
    http_response_code(400);
    die('<b>Error:</b> Score must be between 40 and 100.');
}

$st = $pdo->prepare("
    SELECT a.*,
           atc.name AS atc_name, atc.city AS atc_city, atc.district AS atc_district,
           atc.atc_code, atc.id AS atc_id, atc.center_type,
           c.duration AS course_duration, c.course_type AS course_type,
           c.course_content
    FROM admissions a
    LEFT JOIN atc_centers atc ON atc.id = a.atc_id
    LEFT JOIN courses c ON c.course_name = a.course AND c.status = 'Active'
    WHERE a.id = ?
    LIMIT 1
");
$st->execute([$admissionId]);
$student = $st->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    http_response_code(404);
    die('<b>Error:</b> Student not found.');
}

$grade = courseExamGradeFromScore($score);
if ($grade === 'Fail') {
    http_response_code(400);
    die('<b>Error:</b> Marksheet cannot be issued — score is below passing grade.');
}

$examDate = date('Y-m-d');
$issueDateRaw = trim((string)($src['issue_date'] ?? ''));
if ($issueDateRaw !== '' && strtotime($issueDateRaw) !== false) {
    $examDate = date('Y-m-d', strtotime($issueDateRaw));
}

$fullName = trim(
    ($student['first_name'] ?? '') . ' ' .
    (!empty($student['middle_name']) ? $student['middle_name'] . ' ' : '') .
    ($student['last_name'] ?? '')
);
$courseName = trim((string)($student['course'] ?? 'N/A'));

if (empty($student['course_type']) && $courseName !== '' && $courseName !== 'N/A') {
    try {
        $ctSt = $pdo->prepare("SELECT course_type, course_content, duration FROM courses WHERE status = 'Active' AND (course_name = ? OR ? LIKE CONCAT(course_name, '%')) LIMIT 1");
        $ctSt->execute([$courseName, $courseName]);
        $crow = $ctSt->fetch(PDO::FETCH_ASSOC);
        if ($crow) {
            $student['course_type'] = $crow['course_type'] ?? $student['course_type'];
            if (empty($student['course_content'])) {
                $student['course_content'] = $crow['course_content'] ?? '';
            }
            if (empty($student['course_duration'])) {
                $student['course_duration'] = $crow['duration'] ?? '';
            }
        }
    } catch (Exception $e) {
    }
}

$atcCity = trim((string)($student['atc_city'] ?? $student['atc_district'] ?? ''));
$atcName = trim((string)($student['atc_name'] ?? 'N/A')) . ($atcCity !== '' ? ', ' . $atcCity : '');
$duration = trim((string)($student['course_duration'] ?? '')) ?: '—';
$contents = trim((string)($student['course_content'] ?? ''));
if ($contents === '') {
    $contents = '—';
}
$contents = preg_replace('/\s+/', ' ', $contents);
if (function_exists('mb_strlen') && mb_strlen($contents) > 420) {
    $contents = mb_substr($contents, 0, 417) . '…';
} elseif (strlen($contents) > 420) {
    $contents = substr($contents, 0, 417) . '…';
}

$monthYear = date('F-Y', strtotime($examDate));
$centerCode = trim((string)($student['atc_code'] ?? '')) ?: '—';
$studentId = trim((string)($student['registration_id'] ?? ''));
if ($studentId === '') {
    $studentId = trim((string)($student['roll_no'] ?? ('ADM-' . $admissionId)));
}

$brand = courseCertificateBrand(
    $student['course_type'] ?? null,
    $student['center_type'] ?? null,
    $courseName
);
$isTyping = isTypingCourse($student['course_type'] ?? null, $courseName);
$speeds = typingMarksheetSpeedDefaults($courseName);
if ($isTyping && ($contents === '—' || $contents === '')) {
    $contents = typingMarksheetDefaultContents($speeds['wpm']);
}

$isItSplit = false;
$exam40 = 0;
$atc60 = 0;
if (isGiitItCourse($student['course_type'] ?? null, $courseName)) {
    $exam40In = isset($src['exam_40']) ? (int)$src['exam_40'] : null;
    $atc60In = isset($src['atc_marks']) ? (int)$src['atc_marks'] : null;
    if ($atc60In === null) {
        $saved = getAdmissionAtcMarks($pdo, $admissionId);
        if ($saved !== null) {
            $atc60In = (int)$saved['atc_marks'];
        }
    }
    if ($exam40In !== null && $atc60In !== null) {
        $exam40 = max(0, min(40, $exam40In));
        $atc60 = max(0, min(60, $atc60In));
        $score = $exam40 + $atc60;
        $grade = courseExamGradeFromScore($score);
        $isItSplit = true;
        upsertAdmissionAtcMarks(
            $pdo,
            $admissionId,
            $atc60,
            (int)($student['atc_id'] ?? 0) ?: null,
            (int)($_SESSION['user_id'] ?? 0) ?: null,
            'Admin'
        );
        if ($grade === 'Fail') {
            http_response_code(400);
            die('<b>Error:</b> Combined IT marks are below passing grade.');
        }
    }
}

outputStatementOfMarksPdf([
    'student_id'      => $studentId,
    'full_name'       => $fullName,
    'atc_name'        => $atcName,
    'course_name'     => $courseName,
    'course_contents' => $contents,
    'duration'        => $duration,
    'month_year'      => $monthYear,
    'center_code'     => $centerCode,
    'score'           => $score,
    'grade'           => $grade,
    'brand'           => $brand,
    'is_typing'       => $isTyping,
    'is_it_split'     => $isItSplit,
    'exam_40'         => $exam40,
    'atc_60'          => $atc60,
    'wpm'             => $speeds['wpm'],
    'kph'             => $speeds['kph'],
    'preview'         => $preview,
]);
