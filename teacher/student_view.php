<?php
/**
 * teacher/student_view.php — legacy URL; profile now opens as a modal from Class.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

require_access('teacher', 'students');
$id = (int)($_GET['id'] ?? 0);
redirect('class.php' . ($id > 0 ? '?highlight=' . $id : ''));
