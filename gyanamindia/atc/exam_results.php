<?php
/**
 * ATC Exam Results was removed. Results live on Student Marks.
 */
require_once __DIR__ . '/../includes/auth.php';
requireLogin(['ATC CENTER']);
header('Location: student_marks.php', true, 302);
exit;
