<?php
/**
 * admin/student_fragment.php — HTML fragment for student view modal.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('admin', 'students');
$pdo = db();
ensure_academics_schema($pdo);
ensure_curriculum_schema($pdo);
ensure_user_profile_schema($pdo);

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT u.*, b.name AS block_name,
            CONCAT(t.first_name, " ", t.last_name) AS teacher_name,
            pd.code AS department_code, pc.code AS program_code, pc.name AS program_name
     FROM users u
     LEFT JOIN blocks b ON b.id = u.block_id
     LEFT JOIN users t ON t.id = b.teacher_id
     LEFT JOIN courses pc ON pc.id = u.program_id
     LEFT JOIN departments pd ON pd.id = pc.department_id
     WHERE u.id = :id AND u.role = "student"'
);
$stmt->execute([':id' => $id]);
$student = $stmt->fetch();
if (!$student) {
    http_response_code(404);
    echo '<div class="ui-modal-error" role="alert">Student record not found.</div>';
    exit;
}

$isActive = (int)($student['is_active'] ?? 1) === 1;
$programLine = trim((string)($student['department_code'] ?? '')) !== ''
    ? ($student['department_code'] . ' - ' . ($student['program_code'] ?? '') . ' - ' . ($student['program_name'] ?? ''))
    : (($student['program_code'] ?? '') . ' - ' . ($student['program_name'] ?? ''));
$programLine = trim($programLine, ' -') ?: '—';
$schoolId = trim((string)($student['school_id'] ?? ''));

audit_log('STUDENT_VIEWED', 'student#' . $id, $student['username']);

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
?>
<div class="profile-head-row" style="margin-bottom:14px">
  <?= avatar_img_tag($student['avatar'] ?? null, format_person_name($student), 'avatar avatar--md') ?>
  <div>
    <div style="font-weight:700;font-size:16px"><?= e(format_person_name($student)) ?></div>
    <div class="help mono"><?= e($student['username']) ?>
      · <span class="tag <?= $isActive ? 'tag--pass' : 'tag--fail' ?>"><?= $isActive ? 'Active' : 'Deactivated' ?></span>
    </div>
  </div>
</div>
<dl class="info-grid" data-testid="modal-student-info">
  <div><dt>Full name</dt><dd><?= e(format_person_name($student)) ?></dd></div>
  <div><dt>Username</dt><dd class="mono"><?= e($student['username']) ?></dd></div>
  <div><dt>School ID (password)</dt><dd class="mono"><?= e($schoolId !== '' ? $schoolId : '—') ?></dd></div>
  <div><dt>Email</dt><dd><?= e($student['email'] ?: '—') ?></dd></div>
  <div><dt>Phone</dt><dd class="mono"><?= e($student['phone'] ?: '—') ?></dd></div>
  <div><dt>Age</dt><dd><?= e($student['age'] ?: '—') ?></dd></div>
  <div><dt>Address</dt><dd><?= e($student['address'] ?: '—') ?></dd></div>
  <div><dt>Block</dt><dd><?= e($student['block_name'] ?: '—') ?></dd></div>
  <div><dt>Program</dt><dd><?= e($programLine) ?></dd></div>
  <div><dt>Year level</dt><dd><?= e(year_level_label(isset($student['year_level']) ? (int)$student['year_level'] : null)) ?></dd></div>
  <div><dt>Block adviser</dt><dd><?= e($student['teacher_name'] ?: '—') ?></dd></div>
  <div><dt>Status</dt><dd><?= $isActive ? 'Active' : 'Deactivated' ?></dd></div>
</dl>
<div data-modal-footer>
  <button type="button" class="btn-out" data-ui-modal-dismiss>Close</button>
  <a class="btn" href="student_view.php?id=<?= (int)$id ?>&mode=edit" data-testid="modal-student-edit">Update record</a>
</div>
