<?php
/**
 * teacher/grade.php — legacy URL; grading opens as a modal from Class.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

require_access('teacher', 'grades');
$student_id = (int)($_GET['student_id'] ?? 0);
$offering_id = (int)($_GET['offering_id'] ?? 0);
$qs = [];
if ($student_id > 0) {
    $qs['open_grade'] = $student_id;
}
if ($offering_id > 0) {
    $qs['offering_id'] = $offering_id;
}
redirect('class.php' . ($qs ? '?' . http_build_query($qs) : ''));
