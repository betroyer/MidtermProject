<?php
/**
 * teacher/reports.php — block-scoped class reports.
 * Teachers only see blocks assigned to them (blocks.teacher_id).
 * Pick a block from the dropdown to open that block’s grade page.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('teacher', 'reports');
$pdo = db();
ensure_academics_schema($pdo);
$tid = (int)$user['id'];

$offerings = offerings_list($tid, true);
$offeringIds = array_map('intval', array_column($offerings, 'id'));

// Only blocks this teacher is assigned to handle
$handledBlocks = $pdo->prepare(
    'SELECT b.id, b.name, d.code AS department_code, c.code AS course_code, c.name AS course_name,
            (SELECT COUNT(*) FROM users u WHERE u.role = "student" AND u.block_id = b.id) AS student_count
     FROM blocks b
     LEFT JOIN departments d ON d.id = b.department_id
     LEFT JOIN courses c ON c.id = b.course_id
     WHERE b.teacher_id = :t
     ORDER BY b.name'
);
$handledBlocks->execute([':t' => $tid]);
$handledBlocks = $handledBlocks->fetchAll();
$handledIds = array_map('intval', array_column($handledBlocks, 'id'));

$blockId = (int)($_GET['block_id'] ?? 0);
if ($blockId > 0 && !in_array($blockId, $handledIds, true)) {
    set_flash('error', 'You can only view reports for blocks assigned to you.');
    redirect('reports.php');
}

$activeBlock = null;
foreach ($handledBlocks as $b) {
    if ((int)$b['id'] === $blockId) {
        $activeBlock = $b;
        break;
    }
}

$submitted = $pdo->prepare(
    'SELECT id, title, status, note, submitted_at FROM grade_reports
     WHERE teacher_id = :t ORDER BY submitted_at DESC LIMIT 20'
);
$submitted->execute([':t' => $tid]);
$submitted = $submitted->fetchAll();

render_header($activeBlock ? ('Report · ' . $activeBlock['name']) : 'Reports', $user);
render_flash();
?>
<div class="page-head">
  <div>
    <h1 class="page-title"><?= $activeBlock ? e($activeBlock['name']) . ' report' : 'Class Reports' ?></h1>
    <p class="page-desc">
      <?= $activeBlock
          ? 'Grades for students in this block who are enrolled in your subject(s).'
          : 'Choose a block you handle to open its grade report. You can only view blocks assigned to you.' ?>
    </p>
  </div>
  <div class="actions">
    <?php if ($activeBlock): ?>
      <a class="btn-out no-print" href="reports.php">← All my blocks</a>
      <button class="btn no-print" onclick="window.print()" data-testid="print-report">Print</button>
    <?php endif; ?>
  </div>
</div>

<?php if (!$handledBlocks): ?>
  <p class="empty">You are not assigned to any block yet. Ask the admin to assign you as the teacher of a block.</p>
  <?php render_footer(); return; ?>
<?php endif; ?>

<div class="card no-print" data-testid="report-block-picker">
  <h3>Your blocks</h3>
  <form method="get" class="search-bar" id="report-block-form">
    <div class="field grow">
      <label for="rblock">Open block report</label>
      <select class="input" id="rblock" name="block_id" data-testid="report-block-filter"
              onchange="this.form.submit()">
        <option value="0">— select a block —</option>
        <?php foreach ($handledBlocks as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= $blockId === (int)$b['id'] ? 'selected' : '' ?>>
            <?= e($b['name'] . ' · ' . block_academic_label($b) . ' (' . (int)$b['student_count'] . ' students)') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field actions-inline">
      <button class="btn" type="submit">Open</button>
    </div>
  </form>
</div>

<?php if (!$activeBlock): ?>
<div class="card" data-testid="handled-blocks-list">
  <h3>Blocks you handle (<?= count($handledBlocks) ?>)</h3>
  <div class="table-wrap">
    <table class="table table--aligned">
      <thead>
        <tr>
          <th>Block</th>
          <th>Program</th>
          <th class="col-num">Students</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($handledBlocks as $b): ?>
        <tr>
          <td><strong><?= e($b['name']) ?></strong></td>
          <td><?= e(block_academic_label($b)) ?></td>
          <td class="col-num"><?= (int)$b['student_count'] ?></td>
          <td>
            <a class="btn" href="reports.php?block_id=<?= (int)$b['id'] ?>" data-testid="open-block-<?= (int)$b['id'] ?>">
              View grades
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>

<?php if (!$offerings): ?>
  <p class="empty">You have no subject offerings yet. Ask the admin to create an offering for your subject and enroll this block’s students.</p>
<?php else: ?>

<?php
// Students in THIS handled block who are enrolled in any of this teacher's offerings
$inOffer = $offeringIds ? implode(',', $offeringIds) : '0';
$rowsSql = "SELECT u.id AS student_id, CONCAT(u.first_name,' ',u.last_name) AS name, u.username,
                   b.name AS block_name,
                   o.id AS offering_id, o.name AS section_name,
                   s.code AS subject_code, s.name AS subject_name,
                   g.subject, g.midterm, g.final_exam, g.final_grade, g.grade_point, g.remark
            FROM users u
            JOIN blocks b ON b.id = u.block_id
            JOIN enrollments e ON e.student_id = u.id
            JOIN subject_offerings o ON o.id = e.offering_id AND o.teacher_id = :tid AND o.is_active = 1
            JOIN subjects s ON s.id = o.subject_id
            LEFT JOIN grades g ON g.student_id = u.id AND g.offering_id = e.offering_id
            WHERE u.role = 'student' AND u.block_id = :bid AND o.id IN ($inOffer)
            ORDER BY s.code, o.name, u.last_name, u.first_name";
$stmt = $pdo->prepare($rowsSql);
$stmt->execute([':tid' => $tid, ':bid' => $blockId]);
$allRows = $stmt->fetchAll();

$byOffering = [];
foreach ($allRows as $r) {
    $oid = (int)$r['offering_id'];
    if (!isset($byOffering[$oid])) {
        $byOffering[$oid] = [
            'label' => $r['subject_code'] . ' — ' . $r['subject_name']
                . ($r['section_name'] !== '' ? ' · ' . $r['section_name'] : ''),
            'subject_name' => $r['subject_name'],
            'rows' => [],
        ];
    }
    $byOffering[$oid]['rows'][] = $r;
}

if (!$byOffering):
?>
  <p class="empty">No students from <?= e($activeBlock['name']) ?> are enrolled in your subject offerings yet.</p>
<?php else: ?>

<div class="stat-row">
  <div class="stat"><div class="num"><?= e($activeBlock['name']) ?></div><div class="lbl">Block</div></div>
  <div class="stat"><div class="num"><?= count(array_unique(array_column($allRows, 'student_id'))) ?></div><div class="lbl">Students graded here</div></div>
  <div class="stat"><div class="num"><?= count($byOffering) ?></div><div class="lbl">Subject offerings</div></div>
</div>

<?php foreach ($byOffering as $oid => $group):
    $rows = $group['rows'];
    $percents = array_values(array_filter(
        array_map(static fn($r) => $r['final_grade'], $rows),
        static fn($v) => $v !== null && $v !== ''
    ));
    $avg = $percents ? round(array_sum($percents) / count($percents), 2) : 0;
    $passed = 0;
    $failed = 0;
    foreach ($rows as $r) {
        if ($r['final_grade'] === null || $r['final_grade'] === '') continue;
        $gp = $r['grade_point'];
        if ($gp === null || $gp === '') {
            [$gp] = map_percent_to_scale((float)$r['final_grade']);
        }
        if (grade_point_is_passing((float)$gp)) $passed++;
        else $failed++;
    }
?>
  <div class="card" data-testid="report-block-offering-<?= (int)$oid ?>">
    <h3><?= e($group['label']) ?> · <?= e($activeBlock['name']) ?></h3>
    <p class="help" style="margin-top:-6px;margin-bottom:12px"><?= e(block_academic_label($activeBlock)) ?></p>
    <div class="stat-row">
      <div class="stat"><div class="num"><?= e(number_format($avg, 2)) ?></div><div class="lbl">Class avg %</div></div>
      <div class="stat"><div class="num" style="color:var(--ok)"><?= $passed ?></div><div class="lbl">Passing</div></div>
      <div class="stat"><div class="num" style="color:var(--danger)"><?= $failed ?></div><div class="lbl">Failing</div></div>
      <div class="stat"><div class="num"><?= count($rows) ?></div><div class="lbl">Students</div></div>
    </div>
    <table class="table table--aligned">
      <thead>
        <tr>
          <th>Student</th>
          <th>Username</th>
          <th>Block</th>
          <th>Subject</th>
          <th>Midterm</th>
          <th>Final</th>
          <th>Grade</th>
          <th>Remark</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
          $gp = $r['grade_point'];
          $remark = $r['remark'];
          if (($gp === null || $gp === '') && $r['final_grade'] !== null && $r['final_grade'] !== '') {
              [$gp, $mapped] = map_percent_to_scale((float)$r['final_grade']);
              if (!$remark) $remark = $mapped;
          }
          $hasGrade = $r['final_grade'] !== null && $r['final_grade'] !== '';
      ?>
        <tr>
          <td><?= e($r['name']) ?></td>
          <td class="mono"><?= e($r['username']) ?></td>
          <td><?= e($r['block_name'] ?: $activeBlock['name']) ?></td>
          <td><?= e($r['subject'] ?: $group['subject_name']) ?></td>
          <td class="mono"><?= $r['midterm'] !== null && $r['midterm'] !== '' ? e(format_score_number((float)$r['midterm'])) : '—' ?></td>
          <td class="mono"><?= $r['final_exam'] !== null && $r['final_exam'] !== '' ? e(format_score_number((float)$r['final_exam'])) : '—' ?></td>
          <td><?= $hasGrade ? format_grade_cell($r['final_grade'], $gp) : '—' ?></td>
          <td><?php if ($remark): ?><span class="tag <?= grade_tag_class($remark, $gp) ?>"><?= e($remark) ?></span><?php else: ?>—<?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <form method="post" action="actions.php" class="form-grid no-print" style="margin-top:14px;max-width:560px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="submit_grade_report">
      <input type="hidden" name="offering_id" value="<?= (int)$oid ?>">
      <input type="hidden" name="block_id" value="<?= $blockId ?>">
      <div class="field" style="grid-column:1/-1">
        <label>Note to admin (optional)</label>
        <input class="input" name="note" maxlength="255" placeholder="e.g. <?= e($activeBlock['name']) ?> midterm finalized">
      </div>
      <div class="actions" style="grid-column:1/-1">
        <button class="btn" type="submit"
                onclick="return confirm('Submit <?= e($activeBlock['name']) ?> grade report to the admin inbox?');">
          Submit <?= e($activeBlock['name']) ?> report to admin
        </button>
      </div>
    </form>
  </div>
<?php endforeach; ?>

<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<?php if ($submitted): ?>
<div class="card no-print">
  <h3>Recently submitted</h3>
  <table class="table">
    <thead><tr><th>Title</th><th>Status</th><th>Note</th><th>Submitted</th></tr></thead>
    <tbody>
    <?php foreach ($submitted as $r): ?>
      <tr>
        <td><?= e($r['title']) ?></td>
        <td><span class="tag tag--pass"><?= e($r['status']) ?></span></td>
        <td><?= e($r['note'] ?: '—') ?></td>
        <td class="mono"><?= e($r['submitted_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php render_footer(); ?>
