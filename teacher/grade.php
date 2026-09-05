<?php
/**
 * teacher/grade.php — PE3 grade computation for one enrolled student/offering.
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

if (!student_enrolled_with_teacher($student_id, $tid)) {
    set_flash('error', 'That student is not enrolled in any of your subject offerings.');
    redirect('index.php');
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
    set_flash('error', 'Student not found.');
    redirect('index.php');
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

$studentName = $student['first_name'] . ' ' . $student['last_name'];

render_header('Grade Computation', $user);
render_flash();
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Grade Computation</h1>
    <p class="page-desc">Student: <?= e($studentName) ?>
      <span class="mono">(<?= e($student['username']) ?>)</span>
      · <?= e(program_label($student)) ?>
      <?php if (!empty($student['block_name'])): ?> · <?= e($student['block_name']) ?><?php endif; ?>
    </p>
  </div>
  <div class="actions">
    <a class="btn-out" href="student_view.php?id=<?= $student_id ?>">← Student info</a>
    <a class="btn-out" href="index.php">Search list</a>
  </div>
</div>

<div class="card">
  <h3>Compute grade</h3>
  <p class="help" style="margin-bottom:16px">
    <strong>Semestral Grade</strong> = (Midterm Grade + Final Grade) / 2.
    Midterm and Final must be <strong>50–100</strong>.
    Remarks: <strong>Passed</strong> if semestral ≥ <?= e(number_format(PASSING_GRADE, 1)) ?>, otherwise <strong>Failed</strong>.
  </p>
  <?php if (!$myOfferings): ?>
    <p class="empty">This student has no active enrollments under your offerings.</p>
  <?php else: ?>
  <form method="post" action="actions.php" data-testid="grade-compute-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_grade">
    <input type="hidden" name="student_id" value="<?= $student_id ?>">

    <div class="field" style="max-width:420px">
      <label>Subject offering</label>
      <select class="input" name="offering_id" required data-testid="grade-offering"
              onchange="location.href='grade.php?student_id=<?= $student_id ?>&offering_id='+this.value">
        <?php foreach ($myOfferings as $o): ?>
          <option value="<?= (int)$o['id'] ?>" <?= (int)$pref['offering_id'] === (int)$o['id'] ? 'selected' : '' ?>>
            <?= e($o['subject_code'] . ' — ' . $o['subject_name'] . ($o['name'] !== '' ? ' · ' . $o['name'] : '')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field" style="max-width:320px">
      <label>Student Name</label>
      <input class="input" value="<?= e($studentName) ?>" readonly>
    </div>

    <div class="form-grid" style="max-width:520px">
      <div class="field">
        <label>Midterm Grade <span class="help">(50–100)</span></label>
        <input class="input" type="number" step="0.01" min="50" max="100" name="midterm"
               value="<?= e($pref['midterm']) ?>" placeholder="e.g. 100" data-testid="midterm-input" required>
      </div>
      <div class="field">
        <label>Final Grade <span class="help">(50–100)</span></label>
        <input class="input" type="number" step="0.01" min="50" max="100" name="final_exam"
               value="<?= e($pref['final_exam']) ?>" placeholder="e.g. 79" data-testid="final-input" required>
      </div>
    </div>

    <div class="actions">
      <button class="btn" type="submit" name="btnCompute" data-testid="save-grade">Compute</button>
      <a class="btn-out" href="grade.php?student_id=<?= $student_id ?>&offering_id=<?= (int)$pref['offering_id'] ?>">Clear</a>
    </div>
  </form>
  <?php endif; ?>
</div>

<?php if ($existing): ?>
<div class="card">
  <h3>Your grade records for this student</h3>
  <table class="table" data-testid="existing-grades">
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
    <?php foreach ($existing as $row):
      $sem = (float)$row['final_grade'];
      $isFail = $sem < PASSING_GRADE;
      $oid = (int)($row['offering_id'] ?? 0);
    ?>
      <tr>
        <td><?= e($row['subject']) ?></td>
        <td class="mono"><?= e(format_score_number(exam_as_percent($row['midterm'], $row['midterm_max'] ?? 100))) ?></td>
        <td class="mono"><?= e(format_score_number(exam_as_percent($row['final_exam'], $row['final_exam_max'] ?? 100))) ?></td>
        <td class="mono" style="<?= $isFail ? 'color:#FF0000;font-weight:600' : '' ?>">
          <?= e(number_format($sem, 2)) ?>
        </td>
        <td>
          <span class="tag <?= $isFail ? 'tag--fail' : 'tag--pass' ?>"><?= e($row['remark']) ?></span>
          <?php if ($row['grade_point'] !== null && $row['grade_point'] !== ''): ?>
            <span class="grade-pct mono">(<?= e(number_format((float)$row['grade_point'], 2)) ?>)</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($oid > 0): ?>
            <a href="grade.php?student_id=<?= $student_id ?>&offering_id=<?= $oid ?>">Edit</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php render_footer(); ?>
