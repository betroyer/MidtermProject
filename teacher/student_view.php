<?php
/**
 * teacher/student_view.php — view students enrolled in this teacher's offerings.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('teacher', 'students');
$pdo = db();
ensure_academics_schema($pdo);
$tid = (int)$user['id'];
$id = (int)($_GET['id'] ?? 0);
$canGrade = role_can('teacher', 'grades');

if (!student_enrolled_with_teacher($id, $tid)) {
    set_flash('error', 'That student is not enrolled in your offerings — access denied.');
    audit_log('ACCESS_DENIED', 'student#' . $id, 'Teacher tried to view unauthorized student');
    redirect('index.php');
}

$stmt = $pdo->prepare(
    'SELECT u.id, u.username, u.first_name, u.last_name, u.email, u.phone, u.age, u.avatar, u.created_at,
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
    set_flash('error', 'Student not found.');
    redirect('index.php');
}

$myOfferings = teacher_offerings_for_student($tid, $id);

$grades = $pdo->prepare(
    'SELECT subject, midterm, midterm_max, final_exam, final_exam_max,
            final_grade, grade_point, remark, updated_at, offering_id
     FROM grades WHERE student_id = :id AND teacher_id = :tid ORDER BY subject'
);
$grades->execute([':id' => $id, ':tid' => $tid]);
$grades = $grades->fetchAll();

audit_log('STUDENT_VIEWED', 'student#' . $id, $student['username'] . ' (teacher)');

render_header('Student Information', $user);
render_flash();
?>
<div class="page-head">
  <div class="profile-head-row">
    <?= avatar_img_tag($student['avatar'] ?? null, $student['first_name'] . ' ' . $student['last_name'], 'avatar avatar--md') ?>
    <div>
      <h1 class="page-title"><?= e($student['first_name'] . ' ' . $student['last_name']) ?></h1>
      <p class="page-desc">View authorized student information · <span class="mono"><?= e($student['username']) ?></span></p>
    </div>
  </div>
  <div class="actions">
    <a class="btn-out" href="index.php">← Back to search</a>
    <?php if ($canGrade): ?>
      <a class="btn" href="grade.php?student_id=<?= $id ?>" data-testid="add-update-grade">Add / update grade</a>
    <?php endif; ?>
  </div>
</div>

<div class="card" data-testid="teacher-student-info">
  <h3>View authorized student information</h3>
  <dl class="info-grid">
    <div><dt>Username</dt><dd class="mono"><?= e($student['username']) ?></dd></div>
    <div><dt>Email</dt><dd><?= e($student['email'] ?: '—') ?></dd></div>
    <div><dt>Phone</dt><dd class="mono"><?= e($student['phone'] ?: '—') ?></dd></div>
    <div><dt>Age</dt><dd><?= e($student['age'] ?: '—') ?></dd></div>
    <div><dt>Block</dt><dd><?= e($student['block_name'] ?: '—') ?></dd></div>
    <div><dt>Program</dt><dd><?= e(program_label($student)) ?></dd></div>
    <div><dt>Enrolled</dt><dd class="mono"><?= e($student['created_at']) ?></dd></div>
  </dl>
</div>

<div class="card">
  <h3>Your subject offerings with this student</h3>
  <?php if ($myOfferings): ?>
  <ul class="help" style="margin:0;padding-left:18px">
    <?php foreach ($myOfferings as $o): ?>
      <li><?= e($o['subject_code'] . ' — ' . $o['subject_name'] . ($o['name'] !== '' ? ' · ' . $o['name'] : '')) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?>
    <p class="empty">No active offerings.</p>
  <?php endif; ?>
</div>

<div class="card" data-testid="teacher-student-grades">
  <h3>Your grade records (<?= count($grades) ?>)</h3>
  <?php if ($grades): ?>
  <table class="table">
    <thead>
      <tr>
        <th>Subject</th>
        <th>Midterm Grade</th>
        <th>Final Grade</th>
        <th>Semestral Grade</th>
        <th>Remarks</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($grades as $g):
      $sem = (float)$g['final_grade'];
      $isFail = $sem < PASSING_GRADE;
      $oid = (int)($g['offering_id'] ?? 0);
    ?>
      <tr>
        <td><?= e($g['subject']) ?></td>
        <td class="mono"><?= e(format_score_number(exam_as_percent($g['midterm'], $g['midterm_max'] ?? 100))) ?></td>
        <td class="mono"><?= e(format_score_number(exam_as_percent($g['final_exam'], $g['final_exam_max'] ?? 100))) ?></td>
        <td class="mono" style="<?= $isFail ? 'color:#FF0000;font-weight:600' : '' ?>"><?= e(number_format($sem, 2)) ?></td>
        <td><span class="tag <?= $isFail ? 'tag--fail' : 'tag--pass' ?>"><?= e($g['remark']) ?></span></td>
        <td>
          <?php if ($canGrade && $oid > 0): ?>
            <a href="grade.php?student_id=<?= $id ?>&offering_id=<?= $oid ?>">Edit</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="empty">No grades posted by you for this student yet.</p>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
