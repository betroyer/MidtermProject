<?php
/** student/grades.php — the student's own grades (read only), multi-teacher. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('student', 'grades');
$pdo = db();
ensure_grading_schema();
ensure_academics_schema();

$prog = $pdo->prepare(
    'SELECT pd.code AS department_code, pc.code AS program_code, pc.name AS program_name
     FROM users u
     LEFT JOIN courses pc ON pc.id = u.program_id
     LEFT JOIN departments pd ON pd.id = pc.department_id
     WHERE u.id = :id'
);
$prog->execute([':id' => (int)$user['id']]);
$prog = $prog->fetch() ?: [];

$stmt = $pdo->prepare(
    'SELECT g.*, CONCAT(t.first_name," ",t.last_name) AS teacher_name,
            o.name AS section_name, s.code AS subject_code
     FROM grades g
     LEFT JOIN users t ON t.id = g.teacher_id
     LEFT JOIN subject_offerings o ON o.id = g.offering_id
     LEFT JOIN subjects s ON s.id = o.subject_id
     WHERE g.student_id = :id
     ORDER BY g.subject'
);
$stmt->execute([':id' => (int)$user['id']]);
$grades = $stmt->fetchAll();

$points = [];
foreach ($grades as $g) {
    if ($g['grade_point'] !== null && $g['grade_point'] !== '') {
        $points[] = (float)$g['grade_point'];
    } else {
        [$p] = map_percent_to_scale((float)$g['final_grade']);
        $points[] = $p;
    }
}
$gwa = $points ? round(array_sum($points) / count($points), 2) : null;

render_header('My Grades', $user);
render_flash();
?>
<div class="page-head">
  <div><h1 class="page-title">My Grades</h1>
  <p class="page-desc">Grades from your subject teachers.
    <?php if (!empty($prog['program_code'])): ?>
      Program: <?= e(program_label($prog)) ?>.
    <?php endif; ?>
  </p></div>
</div>

<?php if ($gwa !== null): ?>
<div class="stat-row">
  <div class="stat"><div class="num" data-testid="gwa"><?= e(number_format($gwa, 2)) ?></div><div class="lbl">GWA (grade point)</div></div>
  <div class="stat"><div class="num"><?= count($grades) ?></div><div class="lbl">Subjects</div></div>
</div>
<?php endif; ?>

<div class="card">
  <?php if ($grades): ?>
  <table class="table" data-testid="grades-table">
    <thead>
      <tr>
        <th>Subject</th>
        <th>Teacher</th>
        <th>Midterm Grade</th>
        <th>Final Grade</th>
        <th>Semestral Grade</th>
        <th>Remarks</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($grades as $g):
      $gp = $g['grade_point'];
      $remark = $g['remark'];
      $sem = (float)$g['final_grade'];
      if ($gp === null || $gp === '') {
          [$gp] = map_percent_to_scale($sem);
      }
      if (!$remark) {
          $remark = $sem >= PASSING_GRADE ? 'Passed' : 'Failed';
      }
      $isFail = strcasecmp((string)$remark, 'Failed') === 0 || $sem < PASSING_GRADE;
      $subj = $g['subject'];
      if (!empty($g['subject_code'])) {
          $subj = $g['subject_code'] . ' — ' . $g['subject'];
      }
    ?>
      <tr data-testid="grade-row">
        <td><?= e($subj) ?><?php if (!empty($g['section_name'])): ?> <span class="help">(<?= e($g['section_name']) ?>)</span><?php endif; ?></td>
        <td><?= e($g['teacher_name'] ?: '—') ?></td>
        <td class="mono"><?= e(format_score_number(exam_as_percent($g['midterm'], $g['midterm_max'] ?? 100))) ?></td>
        <td class="mono"><?= e(format_score_number(exam_as_percent($g['final_exam'], $g['final_exam_max'] ?? 100))) ?></td>
        <td class="mono" style="<?= $isFail ? 'color:#FF0000;font-weight:600' : '' ?>"><?= e(number_format($sem, 2)) ?></td>
        <td><span class="tag <?= $isFail ? 'tag--fail' : 'tag--pass' ?>"><?= e($remark) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="empty">No grades have been posted yet.</p>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
