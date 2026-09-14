<?php
/**
 * Gyanam India — Statement of Marks (MCCE layout, GIIT / Gyanam branding)
 * Typing courses use the detailed particulars table (speed / data entry / …).
 *
 * URL: generate_marksheet.php?reg_id=STUDENT_REG&preview=1
 * Sample: generate_marksheet.php?sample=1&brand=typing&preview=1
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/statement_of_marks_pdf.php';
if (file_exists(__DIR__ . '/../includes/exam_integration.php')) {
    require_once __DIR__ . '/../includes/exam_integration.php';
}
requireLogin(['Admin', 'DLC']);

$pdo = getDBConnection();
$sessionRole  = (string)(getUserRole() ?? '');
$sessionAtcId = intval($_SESSION['atc_id'] ?? 0);
$isSample     = isset($_GET['sample']) && (string)$_GET['sample'] === '1';
if ($isSample && $sessionRole !== 'Admin') {
    http_response_code(403);
    die('Sample marksheet is only available to Admin.');
}

$loadStudentByReg = static function (PDO $pdo, string $regId): ?array {
    $sql = "
        SELECT a.*,
               atc.name AS atc_name, atc.city AS atc_city, atc.district AS atc_district,
               atc.atc_code, atc.id AS atc_id, atc.center_type,
               c.duration AS course_duration, c.course_type AS course_type,
               c.course_content
        FROM admissions a
        LEFT JOIN atc_centers atc ON atc.id = a.atc_id
        LEFT JOIN courses c ON c.course_name = a.course AND c.status = 'Active'
        WHERE a.registration_id = ?
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$regId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }
    $sql2 = str_replace('a.registration_id = ?', 'a.roll_no = ?', $sql);
    $stmt = $pdo->prepare($sql2);
    $stmt->execute([$regId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
};

$regId   = trim($_GET['reg_id'] ?? '');
$student = null;
if ($regId !== '') {
    $student = $loadStudentByReg($pdo, $regId);
}

$forceBrand = strtolower(trim((string)($_GET['brand'] ?? '')));

if ($isSample && !$student) {
    $sampleAtcId = (int)($_GET['atc_id'] ?? 0);
    $atcRow = null;
    if ($sampleAtcId > 0) {
        $as = $pdo->prepare("SELECT id, name, city, district, atc_code, center_type FROM atc_centers WHERE id = ? LIMIT 1");
        $as->execute([$sampleAtcId]);
        $atcRow = $as->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $isAbacus = $forceBrand === 'abacus';
    $isTypingSample = $forceBrand === 'typing';
    if ($isTypingSample) {
        $student = [
            'first_name'      => 'Ayush',
            'middle_name'     => 'Samadhan',
            'last_name'       => 'Shingote',
            'course'          => 'Computer Typing & Data Entry Course (Beginner-English)',
            'course_type'     => 'Typing',
            'course_content'  => typingMarksheetDefaultContents(30),
            'course_duration' => '3 months',
            'atc_name'        => $atcRow['name'] ?? 'Matrix Computer Institute',
            'atc_city'        => $atcRow['city'] ?? 'Bhusawal',
            'atc_district'    => $atcRow['district'] ?? '',
            'atc_code'        => $atcRow['atc_code'] ?? '911014',
            'atc_id'          => (int)($atcRow['id'] ?? 0),
            'center_type'     => $atcRow['center_type'] ?? 'IT',
            'registration_id' => 'GIIT' . date('Y') . '1',
            'roll_no'         => 'GIIT' . date('Y') . '1',
        ];
    } else {
        $student = [
            'first_name'      => 'Sample',
            'middle_name'     => '',
            'last_name'       => 'Student',
            'course'          => $isAbacus ? 'Abacus Level 1' : 'MS-CIT',
            'course_type'     => $isAbacus ? 'Abacus' : 'IT',
            'course_content'  => $isAbacus
                ? 'Abacus basics, visualization, speed and accuracy drills'
                : 'MS Office, Internet, Digital Literacy',
            'course_duration' => $isAbacus ? '3 Months' : '2 Months',
            'atc_name'        => $atcRow['name'] ?? 'Sample ATC',
            'atc_city'        => $atcRow['city'] ?? 'Pune',
            'atc_district'    => $atcRow['district'] ?? '',
            'atc_code'        => $atcRow['atc_code'] ?? '202600001',
            'atc_id'          => (int)($atcRow['id'] ?? 0),
            'center_type'     => $atcRow['center_type'] ?? ($isAbacus ? 'Abacus' : 'IT'),
            'registration_id' => $isAbacus ? ('GYANAM' . date('Y') . '1') : ('GIIT' . date('Y') . '1'),
            'roll_no'         => $isAbacus ? ('GYANAM' . date('Y') . '1') : ('GIIT' . date('Y') . '1'),
        ];
    }
}

if (!$student) {
    http_response_code($regId === '' ? 400 : 404);
    die($regId === '' ? '<b>Error:</b> Missing <code>reg_id</code> parameter.' : '<b>Error:</b> Student not found.');
}

$lookupId = trim((string)($student['registration_id'] ?? '')) ?: trim((string)($student['roll_no'] ?? $regId));
$exam = null;
if ($isSample) {
    $exam = [
        'identifier'      => $lookupId ?: ('GIIT' . date('Y') . '1'),
        'score'           => $forceBrand === 'typing' ? 83 : 82,
        'exam_date'       => date('Y-m-d'),
        'result'          => 'pass',
        'correct_answers' => $forceBrand === 'typing' ? 83 : 32,
        'total_questions' => $forceBrand === 'typing' ? 100 : 40,
        'exam_40'         => $forceBrand === 'typing' ? 33 : 32,
    ];
} else {
    if (!function_exists('examIntegrationReady') || !examIntegrationReady()) {
        http_response_code(403);
        die('<b>Marksheet not available:</b> Exam portal is not connected.');
    }
    $exam = function_exists('fetchStudentPassingExamResult') ? fetchStudentPassingExamResult($lookupId) : null;
    if (!$exam) {
        $res = fetchStudentExamResults($lookupId);
        $subs = $res['success'] ? ($res['data']['submissions'] ?? []) : [];
        $best = null;
        foreach ($subs as $sub) {
            if (!is_array($sub) || examSubmissionIsDemo($sub)) {
                continue;
            }
            $id = trim((string)($sub['student']['identifier'] ?? ''));
            if ($id === '') {
                continue;
            }
            $rec = examSubmissionPassRecord($sub);
            if (!$rec) {
                // allow latest non-pass for marksheet preview of failed? Plan requires pass for IT. Keep best any:
                $correct = (int)($sub['correct_answers'] ?? $sub['correct'] ?? 0);
                $total = (int)($sub['total_questions'] ?? $sub['total'] ?? 0);
                $rec = [
                    'identifier'      => $id,
                    'score'           => (int)($sub['score'] ?? 0),
                    'exam_date'       => date('Y-m-d', strtotime((string)($sub['submitted_at'] ?? 'now'))),
                    'exam_title'      => (string)($sub['exam_title'] ?? ($sub['exam']['title'] ?? '')),
                    'submitted_at'    => $sub['submitted_at'] ?? null,
                    'result'          => strtolower((string)($sub['result'] ?? '')),
                    'correct_answers' => $correct,
                    'total_questions' => $total,
                    'exam_40'         => scaleExamMarksTo40($correct, $total > 0 ? $total : 100),
                ];
            }
            if ($best === null || strtotime((string)$rec['submitted_at']) > strtotime((string)($best['submitted_at'] ?? '0'))) {
                $best = $rec;
            }
        }
        $exam = $best;
    }
    if (!$exam) {
        http_response_code(403);
        die('<b>Marksheet not available:</b> No exam result found for this student.');
    }
}

$score = (int)($exam['score'] ?? 0);
$examDate = (string)($exam['exam_date'] ?? date('Y-m-d'));
$resultFlag = strtolower((string)($exam['result'] ?? ''));
if ($resultFlag === 'absent' || $resultFlag === 'ab') {
    $grade = 'AB';
} else {
    $grade = courseExamGradeFromScore($score);
}

$fullName = trim(
    ($student['first_name'] ?? '') . ' ' .
    (!empty($student['middle_name']) ? $student['middle_name'] . ' ' : '') .
    ($student['last_name'] ?? '')
);
$courseName = trim($student['course'] ?? 'N/A');
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

$isItSplit = false;
$exam40 = (int)($exam['exam_40'] ?? 0);
$atc60 = 0;
if (!$isSample && isGiitItCourse($student['course_type'] ?? null, $courseName)) {
    $admissionId = (int)($student['id'] ?? 0);
    $atcMarksRow = getAdmissionAtcMarks($pdo, $admissionId);
    if ($atcMarksRow === null) {
        http_response_code(403);
        die('<b>Marksheet not available:</b> ATC internal marks (out of 60) are required for IT courses.');
    }
    $composed = composeItCertificateScores($exam, (int)$atcMarksRow['atc_marks']);
    if (!$composed['complete']) {
        http_response_code(403);
        die('<b>Marksheet not available:</b> Incomplete IT marks (Exam/40 + ATC/60).');
    }
    $score = (int)$composed['total'];
    $grade = $composed['grade'];
    $exam40 = (int)$composed['exam_40'];
    $atc60 = (int)$composed['atc_60'];
    $isItSplit = true;
} elseif ($isSample && $forceBrand === 'it') {
    $isItSplit = true;
    $exam40 = 32;
    $atc60 = 50;
    $score = 82;
    $grade = courseExamGradeFromScore($score);
}

$atcCity = trim($student['atc_city'] ?? $student['atc_district'] ?? '');
$atcName = trim($student['atc_name'] ?? 'N/A') . ($atcCity ? ', ' . $atcCity : '');
$duration = trim((string)($student['course_duration'] ?? '')) ?: '—';
$contents = trim((string)($student['course_content'] ?? ''));
if ($contents === '') {
    $contents = '—';
}
$contents = preg_replace('/\s+/', ' ', $contents);
if (function_exists('mb_strlen') && mb_strlen($contents) > 420) {
    $contents = mb_substr($contents, 0, 417) . '…';
}

$monthYear = date('F-Y', strtotime($examDate ?: 'now'));
$centerCode = trim((string)($student['atc_code'] ?? '')) ?: '—';
$studentId = trim((string)($student['registration_id'] ?? $lookupId));

$brand = courseCertificateBrand(
    $student['course_type'] ?? null,
    $student['center_type'] ?? null,
    $courseName
);
$isTyping = isTypingCourse($student['course_type'] ?? null, $courseName);
if ($isSample && ($forceBrand === 'abacus' || $forceBrand === 'it' || $forceBrand === 'typing')) {
    if ($forceBrand === 'abacus') {
        $brand = 'abacus';
        $isTyping = false;
    } elseif ($forceBrand === 'typing') {
        $brand = 'it';
        $isTyping = true;
    } else {
        $brand = 'it';
        $isTyping = false;
    }
}

$speeds = typingMarksheetSpeedDefaults($courseName);
if ($isTyping && ($contents === '—' || $contents === '')) {
    $contents = typingMarksheetDefaultContents($speeds['wpm']);
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
    'is_it_split'     => $isItSplit && !$isTyping,
    'exam_40'         => $exam40,
    'atc_60'          => $atc60,
    'wpm'             => $speeds['wpm'],
    'kph'             => $speeds['kph'],
    'preview'         => isset($_GET['preview']),
]);
