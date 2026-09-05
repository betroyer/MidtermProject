<?php
/**
 * student/schedule.php — weekly class schedule for the student's block + year.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('student', 'profile');
$pdo = db();
ensure_academics_schema($pdo);
ensure_curriculum_schema($pdo);

$uid = (int)$user['id'];
$me = $pdo->prepare(
    'SELECT u.year_level, u.block_id, b.name AS block_name, c.code AS program_code, c.name AS program_name
     FROM users u
     LEFT JOIN blocks b ON b.id = u.block_id
     LEFT JOIN courses c ON c.id = u.program_id
     WHERE u.id = :id'
);
$me->execute([':id' => $uid]);
$me = $me->fetch() ?: [];
$rows = student_schedule_rows($uid);

$byDay = [];
foreach ($rows as $r) {
    $dow = (int)$r['day_of_week'];
    $byDay[$dow][] = $r;
}
ksort($byDay);

render_header('My Schedule', $user);
render_flash();
?>
<div class="page-head">
  <div>
    <h1 class="page-title">My Schedule</h1>
    <p class="page-desc">
      Class timetable for
      <?= e($me['block_name'] ?: 'your block') ?>
      · <?= e(year_level_label(isset($me['year_level']) ? (int)$me['year_level'] : null)) ?>
      <?php if (!empty($me['program_code'])): ?>
        · <?= e($me['program_code']) ?>
      <?php endif; ?>
      . Classes run between 7:00 AM and 8:30 PM.
    </p>
  </div>
</div>

<?php if (!$rows): ?>
  <p class="empty">No schedule yet. Ask the admin to enroll you with a year level so subjects and a timetable are generated.</p>
<?php else: ?>
  <?php foreach ($byDay as $dow => $slots): ?>
  <div class="card" data-testid="schedule-day-<?= (int)$dow ?>">
    <h3><?= e(weekday_name((int)$dow)) ?> (<?= count($slots) ?> class<?= count($slots) === 1 ? '' : 'es' ?>)</h3>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Time</th>
            <th>Subject</th>
            <th>Type</th>
            <th>Section</th>
            <th>Teacher</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($slots as $s): ?>
          <tr>
            <td class="mono">
              <?= e(format_time_ampm($s['start_time'])) ?>
              –
              <?= e(format_time_ampm($s['end_time'])) ?>
            </td>
            <td>
              <strong><?= e($s['subject_code']) ?></strong>
              — <?= e($s['subject_name']) ?>
            </td>
            <td><span class="tag"><?= e(ucfirst((string)$s['kind'])) ?></span></td>
            <td><?= e($s['section_name'] ?: '—') ?></td>
            <td><?= e($s['teacher_name'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php render_footer(); ?>
