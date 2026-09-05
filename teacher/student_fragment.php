<?php
/**
 * teacher/student_fragment.php — modal HTML for viewing an enrolled student.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('teacher', 'students');
$pdo = db();
ensure_academics_schema($pdo);
$tid = (int)$user['id'];
$id = (int)($_GET['id'] ?? 0);
$canGrade = role_can('teacher', 'grades');

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (!student_enrolled_with_teacher($id, $tid)) {
    http_response_code(403);
    echo '<div class="ui-modal-error" role="alert">That student is not enrolled in your offerings.</div>';
    exit;
}

$stmt = $pdo->prepare(
    'SELECT u.id, u.username, u.first_name, u.last_name, u.middle_initial, u.email, u.phone, u.age,
            u.address, u.emergency_name, u.emergency_relation, u.emergency_address, u.emergency_phone,
            u.avatar, u.created_at, u.year_level,
            b.name AS block_name, pc.code AS program_code, pc.name AS program_name, pd.code AS department_code
     FROM users u
     LEFT JOIN blocks b ON b.id = u.block_id
     LEFT JOIN courses pc ON pc.id = u.program_id
     LEFT JOIN departments pd ON pd.id = pc.department_id
     WHERE u.id = :id AND u.role = "student"'
);
$stmt->execute([':id' => $id]);
$student = $stmt->fetch();
if (!$student) {
    http_response_code(404);
    echo '<div class="ui-modal-error" role="alert">Student not found.</div>';
    exit;
}

$myOfferings = teacher_offerings_for_student($tid, $id);
$grades = $pdo->prepare(
    'SELECT subject, midterm, midterm_max, final_exam, final_exam_max,
            final_grade, grade_point, remark, offering_id
     FROM grades WHERE student_id = :id AND teacher_id = :tid ORDER BY subject'
);
$grades->execute([':id' => $id, ':tid' => $tid]);
$grades = $grades->fetchAll();

audit_log('STUDENT_VIEWED', 'student#' . $id, $student['username'] . ' (teacher modal)');
?>
<div class="profile-head-row" style="margin-bottom:14px">
  <?= avatar_img_tag($student['avatar'] ?? null, format_person_name($student), 'avatar avatar--md') ?>
  <div>
    <div style="font-weight:700;font-size:16px"><?= e(format_person_name($student)) ?></div>
    <div class="help mono"><?= e($student['username']) ?>
      · <?= e($student['block_name'] ?: 'No block') ?>
      · <?= e(year_level_label(isset($student['year_level']) ? (int)$student['year_level'] : null)) ?>
    </div>
  </div>
</div>

<h3 style="margin:0 0 8px;font-size:14px">Profile</h3>
<dl class="info-grid" data-testid="teacher-modal-student-info">
  <div><dt>Full name</dt><dd><?= e(format_person_name($student)) ?></dd></div>
  <div><dt>Email</dt><dd><?= e($student['email'] ?: '—') ?></dd></div>
  <div><dt>Phone</dt><dd class="mono"><?= e($student['phone'] ?: '—') ?></dd></div>
  <div><dt>Age</dt><dd><?= e($student['age'] ?: '—') ?></dd></div>
  <div><dt>Address</dt><dd><?= e($student['address'] ?: '—') ?></dd></div>
  <div><dt>Block</dt><dd><?= e($student['block_name'] ?: '—') ?></dd></div>
  <div><dt>Program</dt><dd><?= e(program_label($student)) ?></dd></div>
</dl>

<h3 style="margin:16px 0 8px;font-size:14px">Your subject(s) with this student</h3>
<?php if ($myOfferings): ?>
<ul class="help" style="margin:0;padding-left:18px">
  <?php foreach ($myOfferings as $o): ?>
    <li><?= e($o['subject_code'] . ' — ' . $o['subject_name'] . ($o['name'] !== '' ? ' · ' . $o['name'] : '')) ?></li>
  <?php endforeach; ?>
</ul>
<?php else: ?>
  <p class="empty">No active offerings.</p>
<?php endif; ?>

<h3 style="margin:16px 0 8px;font-size:14px">Your grade records (<?= count($grades) ?>)</h3>
<?php if ($grades): ?>
<div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Subject</th>
        <th>Midterm</th>
        <th>Final</th>
        <th>Semestral</th>
        <th>Remarks</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($grades as $g):
      $sem = (float)$g['final_grade'];
      $isFail = $sem < PASSING_GRADE;
    ?>
      <tr>
        <td><?= e($g['subject']) ?></td>
        <td class="mono"><?= e(format_score_number(exam_as_percent($g['midterm'], $g['midterm_max'] ?? 100))) ?></td>
        <td class="mono"><?= e(format_score_number(exam_as_percent($g['final_exam'], $g['final_exam_max'] ?? 100))) ?></td>
        <td class="mono" style="<?= $isFail ? 'color:#FF0000;font-weight:600' : '' ?>"><?= e(number_format($sem, 2)) ?></td>
        <td><span class="tag <?= $isFail ? 'tag--fail' : 'tag--pass' ?>"><?= e($g['remark']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
  <p class="empty">No grades posted by you for this student yet.</p>
<?php endif; ?>

<div data-modal-footer>
  <button type="button" class="btn-out" data-ui-modal-dismiss>Close</button>
  <?php if ($canGrade): ?>
    <a class="btn" href="class.php?open_grade=<?= (int)$id ?>">Add / update grade</a>
  <?php endif; ?>
</div>
