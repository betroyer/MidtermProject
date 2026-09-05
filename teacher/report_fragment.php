<?php
/**
 * teacher/report_fragment.php — modal: grade record only when every student in the block is graded.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('teacher', 'reports');
$pdo = db();
ensure_academics_schema($pdo);
$tid = (int)$user['id'];
$blockId = (int)($_GET['block_id'] ?? 0);

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

$offerings = offerings_list($tid, true);
$offeringIds = array_map('intval', array_column($offerings, 'id'));
$blocks = teacher_enrollment_blocks($pdo, $tid, $offeringIds);
$allowed = array_map('intval', array_column($blocks, 'id'));

if ($blockId <= 0 || !in_array($blockId, $allowed, true)) {
    http_response_code(403);
    echo '<div class="ui-modal-error" role="alert">You can only view grades for blocks that have students in your subject.</div>';
    exit;
}

$activeBlock = null;
foreach ($blocks as $b) {
    if ((int)$b['id'] === $blockId) {
        $activeBlock = $b;
        break;
    }
}
if (!$activeBlock) {
    http_response_code(404);
    echo '<div class="ui-modal-error" role="alert">Block not found.</div>';
    exit;
}

if (!$offeringIds) {
    echo '<p class="empty">You have no subject offerings yet.</p>';
    echo '<div data-modal-footer><button type="button" class="btn-out" data-ui-modal-dismiss>Close</button></div>';
    exit;
}

$completion = teacher_block_grade_completion($pdo, $tid, $blockId, $offeringIds);
$subjectCodes = trim((string)($activeBlock['subject_codes'] ?? ''));
?>
<p class="help" style="margin:0 0 12px">
  <?= e(block_academic_label($activeBlock)) ?>
  <?php if ($subjectCodes !== ''): ?>
    · Subject<?= (strpos($subjectCodes, ',') !== false) ? 's' : '' ?>: <strong><?= e($subjectCodes) ?></strong>
  <?php endif; ?>
</p>

<?php if ($completion['total'] <= 0): ?>
  <p class="empty">No students from this block are enrolled in your subject yet.</p>
<?php elseif (!$completion['complete']): ?>
  <div class="flash flash--error" role="status" style="margin-bottom:14px" data-testid="grade-record-incomplete">
    Grade record is hidden until every student in this block has a computed grade.
    Progress: <strong><?= (int)$completion['graded'] ?>/<?= (int)$completion['total'] ?></strong>.
  </div>
  <h3 style="margin:0 0 8px;font-size:14px">Students still missing a grade (<?= count($completion['missing']) ?>)</h3>
  <p class="help" style="margin:0 0 10px">Open Class, grade each student below, then return here to view the block record.</p>
  <div class="table-wrap">
    <table class="table table--aligned" data-testid="missing-grades-table">
      <thead>
        <tr>
          <th>Student</th>
          <th>Username</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($completion['missing'] as $m): ?>
        <tr>
          <td><?= e($m['name']) ?></td>
          <td class="mono"><?= e($m['username']) ?></td>
          <td>
            <a class="btn" href="class.php?open_grade=<?= (int)$m['student_id'] ?>&offering_id=<?= (int)($m['offering_id'] ?? 0) ?>&block_id=<?= (int)$blockId ?>">
              Grade now
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php else:
    $inOffer = implode(',', $offeringIds);
    $rowsSql = "SELECT u.id AS student_id, CONCAT(u.first_name,' ',u.last_name) AS name, u.username,
                       o.id AS offering_id, o.name AS section_name,
                       s.code AS subject_code, s.name AS subject_name,
                       g.midterm, g.final_exam, g.final_grade, g.grade_point, g.remark
                FROM users u
                JOIN enrollments e ON e.student_id = u.id
                JOIN subject_offerings o ON o.id = e.offering_id AND o.teacher_id = :tid AND o.is_active = 1
                JOIN subjects s ON s.id = o.subject_id
                JOIN grades g ON g.student_id = u.id AND g.offering_id = e.offering_id
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
                'rows' => [],
            ];
        }
        $byOffering[$oid]['rows'][] = $r;
    }
?>
  <div class="flash flash--ok" role="status" style="margin-bottom:14px" data-testid="grade-record-ready">
    All <?= (int)$completion['total'] ?> student grade<?= $completion['total'] === 1 ? '' : 's' ?> computed. Grade record is ready.
  </div>

  <div class="stat-row" style="margin-bottom:12px">
    <div class="stat"><div class="num"><?= count(array_unique(array_column($allRows, 'student_id'))) ?></div><div class="lbl">Students</div></div>
    <div class="stat"><div class="num"><?= count($byOffering) ?></div><div class="lbl">Offerings</div></div>
  </div>

  <?php foreach ($byOffering as $oid => $group):
      $rows = $group['rows'];
      $percents = array_map(static fn($r) => (float)$r['final_grade'], $rows);
      $avg = $percents ? round(array_sum($percents) / count($percents), 2) : 0;
  ?>
  <div style="margin-bottom:18px" data-testid="report-modal-offering-<?= (int)$oid ?>">
    <h3 style="margin:0 0 8px;font-size:14px"><?= e($group['label']) ?></h3>
    <p class="help" style="margin:0 0 8px">Class avg: <strong><?= e(number_format($avg, 2)) ?></strong>% · <?= count($rows) ?> student<?= count($rows) === 1 ? '' : 's' ?></p>
    <div class="table-wrap">
      <table class="table table--aligned">
        <thead>
          <tr>
            <th>Student</th>
            <th>Username</th>
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
                if (!$remark) {
                    $remark = $mapped;
                }
            }
        ?>
          <tr>
            <td><?= e($r['name']) ?></td>
            <td class="mono"><?= e($r['username']) ?></td>
            <td class="mono"><?= $r['midterm'] !== null && $r['midterm'] !== '' ? e(format_score_number((float)$r['midterm'])) : '—' ?></td>
            <td class="mono"><?= $r['final_exam'] !== null && $r['final_exam'] !== '' ? e(format_score_number((float)$r['final_exam'])) : '—' ?></td>
            <td><?= format_grade_cell($r['final_grade'], $gp) ?></td>
            <td><?php if ($remark): ?><span class="tag <?= grade_tag_class($remark, $gp) ?>"><?= e($remark) ?></span><?php else: ?>—<?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <form method="post" action="actions.php" style="margin-top:10px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="submit_grade_report">
      <input type="hidden" name="offering_id" value="<?= (int)$oid ?>">
      <input type="hidden" name="block_id" value="<?= (int)$blockId ?>">
      <div class="field">
        <label>Note to admin (optional)</label>
        <input class="input" name="note" maxlength="255" placeholder="e.g. <?= e($activeBlock['name']) ?> grades finalized">
      </div>
      <div class="actions" style="margin-top:8px">
        <button class="btn" type="submit"
                onclick="return confirm('Submit <?= e($activeBlock['name']) ?> grade report to the admin inbox?');">
          Submit report to admin
        </button>
      </div>
    </form>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<div data-modal-footer>
  <button type="button" class="btn-out" data-ui-modal-dismiss>Close</button>
  <?php if ($completion['complete']): ?>
    <a class="btn-out" href="reports.php?block_id=<?= (int)$blockId ?>&print=1" target="_blank" rel="noopener">Open printable page</a>
  <?php else: ?>
    <a class="btn" href="class.php?block_id=<?= (int)$blockId ?>">Open Class for this block</a>
  <?php endif; ?>
</div>
