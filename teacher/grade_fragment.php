<?php
/**
 * teacher/grade_fragment.php — modal HTML: compute grade for one student.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('teacher', 'grades');
$pdo = db();
ensure_grading_schema($pdo);
ensure_academics_schema($pdo);
$tid = (int)$user['id'];
$student_id = (int)($_GET['student_id'] ?? 0);
$offeringPref = (int)($_GET['offering_id'] ?? 0);

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (!student_enrolled_with_teacher($student_id, $tid)) {
    http_response_code(403);
    echo '<div class="ui-modal-error" role="alert">That student is not enrolled in any of your subject offerings.</div>';
    exit;
}

$stmt = $pdo->prepare(
    'SELECT u.id, u.first_name, u.last_name, u.username, b.name AS block_name,
            pc.code AS program_code, pc.name AS program_name, pd.code AS department_code
     FROM users u
     LEFT JOIN blocks b ON b.id = u.block_id
     LEFT JOIN courses pc ON pc.id = u.program_id
     LEFT JOIN departments pd ON pd.id = pc.department_id
     WHERE u.id = :sid AND u.role = "student"'
);
$stmt->execute([':sid' => $student_id]);
$student = $stmt->fetch();
if (!$student) {
    http_response_code(404);
    echo '<div class="ui-modal-error" role="alert">Student not found.</div>';
    exit;
}

$myOfferings = teacher_offerings_for_student($tid, $student_id);
$g = $pdo->prepare(
    'SELECT g.* FROM grades g
     WHERE g.student_id = :sid AND g.teacher_id = :tid
     ORDER BY g.subject'
);
$g->execute([':sid' => $student_id, ':tid' => $tid]);
$existing = $g->fetchAll();

$pref = [
    'offering_id' => $offeringPref,
    'midterm' => '',
    'final_exam' => '',
];
if ($offeringPref <= 0 && $myOfferings) {
    $pref['offering_id'] = (int)$myOfferings[0]['id'];
}
foreach ($existing as $row) {
    if ((int)($row['offering_id'] ?? 0) === (int)$pref['offering_id'] && (int)$pref['offering_id'] > 0) {
        $pref['midterm'] = exam_as_percent($row['midterm'], $row['midterm_max'] ?? 100);
        $pref['final_exam'] = exam_as_percent($row['final_exam'], $row['final_exam_max'] ?? 100);
        break;
    }
}

$studentName = trim($student['first_name'] . ' ' . $student['last_name']);
?>
<p class="help" style="margin:0 0 12px">
  <?= e($studentName) ?>
  <span class="mono">(<?= e($student['username']) ?>)</span>
  · <?= e(program_label($student)) ?>
  <?php if (!empty($student['block_name'])): ?> · <?= e($student['block_name']) ?><?php endif; ?>
</p>

<p class="help" style="margin:0 0 14px">
  <strong>Semestral Grade</strong> = (Midterm + Final) / 2.
  Scores must be <strong>50–100</strong>.
  Passed if semestral ≥ <?= e(number_format(PASSING_GRADE, 1)) ?>.
</p>

<?php if (!$myOfferings): ?>
  <p class="empty">This student has no active enrollments under your offerings.</p>
<?php else: ?>
<form method="post" action="actions.php" id="ui-modal-grade-form" data-testid="grade-compute-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_grade">
  <input type="hidden" name="student_id" value="<?= (int)$student_id ?>">
  <input type="hidden" name="return_to" value="class">

  <div class="field">
    <label>Subject offering</label>
    <select class="input" name="offering_id" required data-testid="grade-offering"
            data-reload-grade-offering="<?= (int)$student_id ?>">
      <?php foreach ($myOfferings as $o): ?>
        <option value="<?= (int)$o['id'] ?>" <?= (int)$pref['offering_id'] === (int)$o['id'] ? 'selected' : '' ?>>
          <?= e($o['subject_code'] . ' — ' . $o['subject_name'] . ($o['name'] !== '' ? ' · ' . $o['name'] : '')) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="form-grid" style="margin-top:12px">
    <div class="field">
      <label>Midterm Grade <span class="help">(50–100)</span></label>
      <input class="input" type="number" step="0.01" min="50" max="100" name="midterm"
             value="<?= e($pref['midterm']) ?>" placeholder="e.g. 100" data-testid="midterm-input" required data-autofocus>
    </div>
    <div class="field">
      <label>Final Grade <span class="help">(50–100)</span></label>
      <input class="input" type="number" step="0.01" min="50" max="100" name="final_exam"
             value="<?= e($pref['final_exam']) ?>" placeholder="e.g. 79" data-testid="final-input" required>
    </div>
  </div>
</form>
<?php endif; ?>

<?php if ($existing): ?>
<div style="margin-top:16px">
  <h3 style="margin:0 0 8px;font-size:14px">Saved grades for this student</h3>
  <div class="table-wrap">
    <table class="table" data-testid="existing-grades">
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
      <?php foreach ($existing as $row):
        $sem = (float)$row['final_grade'];
        $isFail = $sem < PASSING_GRADE;
      ?>
        <tr>
          <td><?= e($row['subject']) ?></td>
          <td class="mono"><?= e(format_score_number(exam_as_percent($row['midterm'], $row['midterm_max'] ?? 100))) ?></td>
          <td class="mono"><?= e(format_score_number(exam_as_percent($row['final_exam'], $row['final_exam_max'] ?? 100))) ?></td>
          <td class="mono" style="<?= $isFail ? 'color:#FF0000;font-weight:600' : '' ?>"><?= e(number_format($sem, 2)) ?></td>
          <td><span class="tag <?= $isFail ? 'tag--fail' : 'tag--pass' ?>"><?= e($row['remark']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div data-modal-footer>
  <button type="button" class="btn-out" data-ui-modal-dismiss>Close</button>
  <?php if ($myOfferings): ?>
    <button class="btn" type="submit" form="ui-modal-grade-form" name="btnCompute" data-testid="save-grade">Compute</button>
  <?php endif; ?>
</div>
