<?php
/**
 * admin/enrollments.php — removed from nav.
 * Subjects/blocks are applied automatically on student enroll (curriculum).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

require_access('admin', 'students');
set_flash('ok', 'The Enrollments page was removed. Subjects and block are assigned automatically when you enroll a student.');
redirect('students.php');
