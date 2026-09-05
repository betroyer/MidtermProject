<?php
/**
 * teacher/reports.php — subject-scoped blocks; grade records only when fully graded.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('teacher', 'reports');
$pdo = db();
ensure_academics_schema($pdo);
$tid = (int)$user['id'];

$offerings = offerings_list($tid, true);
$offeringIds = array_map('intval', array_column($offerings, 'id'));
$uniqueSubjects = array_values(array_unique(array_column($offerings, 'subject_code')));
$singleSubject = count($uniqueSubjects) === 1;
$subjectLabel = $singleSubject && $offerings
    ? ($offerings[0]['subject_code'] . ' — ' . $offerings[0]['subject_name'])
    : '';

$handledBlocks = teacher_enrollment_blocks($pdo, $tid, $offeringIds);
$handledIds = array_map('intval', array_column($handledBlocks, 'id'));

$readyBlocks = [];
$pendingBlocks = [];
foreach ($handledBlocks as $b) {
    $comp = teacher_block_grade_completion($pdo, $tid, (int)$b['id'], $offeringIds);
    $b['_completion'] = $comp;
    if ($comp['complete']) {
        $readyBlocks[] = $b;
    } else {
        $pendingBlocks[] = $b;
    }
}

$blockId = (int)($_GET['block_id'] ?? 0);
$wantPrint = (int)($_GET['print'] ?? 0) === 1;
if ($blockId > 0 && !in_array($blockId, $handledIds, true)) {
    set_flash('error', 'You can only view grades for blocks that have students in your subject.');
    redirect('reports.php');
}

$submitted = $pdo->prepare(
    'SELECT id, title, status, note, submitted_at FROM grade_reports
     WHERE teacher_id = :t ORDER BY submitted_at DESC LIMIT 20'
);
$submitted->execute([':t' => $tid]);
$submitted = $submitted->fetchAll();

// Printable only when the block grade record is complete
if ($wantPrint && $blockId > 0) {
    $printComp = teacher_block_grade_completion($pdo, $tid, $blockId, $offeringIds);
    if (!$printComp['complete']) {
        set_flash('error', 'Printable grade record is available only after every student in the block has a grade.');
        redirect('reports.php?block_id=' . $blockId);
    }
    $activeBlock = null;
    foreach ($handledBlocks as $b) {
        if ((int)$b['id'] === $blockId) {
            $activeBlock = $b;
            break;
        }
    }
    require __DIR__ . '/report_print.php';
    exit;
}

render_header('Reports', $user);
$modalCss = @filemtime(__DIR__ . '/../css/modal.css') ?: time();
$modalJs = @filemtime(__DIR__ . '/../js/ui-modal.js') ?: time();
echo '<link rel="stylesheet" href="../css/modal.css?v=' . (int)$modalCss . '">';
echo '<script src="../js/ui-modal.js?v=' . (int)$modalJs . '" defer></script>';
render_flash();
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Class Reports</h1>
    <p class="page-desc">
      Grade records appear only after you compute grades for <strong>every</strong> student in the block
      <?= $subjectLabel !== '' ? ' for <strong>' . e($subjectLabel) . '</strong>' : '' ?>.
      If one student is missing, the record stays hidden until you finish.
    </p>
  </div>
</div>

<?php if (!$offerings): ?>
  <p class="empty">You have no subject offerings yet. Ask the admin to create an offering for your subject and enroll students.</p>
<?php elseif (!$handledBlocks): ?>
  <p class="empty">No blocks yet for your subject. When students are enrolled in your offering, their blocks will appear here.</p>
<?php else: ?>

<?php if ($readyBlocks): ?>
<div class="card" data-testid="ready-grade-records">
  <h3>Grade records ready (<?= count($readyBlocks) ?>)</h3>
  <p class="help" style="margin-bottom:12px">All students in these blocks have computed grades for your subject.</p>
  <div class="table-wrap">
    <table class="table table--aligned table--reports">
      <colgroup>
        <col class="col-block">
        <col class="col-program">
        <col class="col-subject">
        <col class="col-num">
        <col class="col-status">
        <col class="col-actions">
      </colgroup>
      <thead>
        <tr>
          <th>Block</th>
          <th>Program</th>
          <th>Subject</th>
          <th class="col-num">Students</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($readyBlocks as $b):
          $c = $b['_completion'];
      ?>
        <tr>
          <td><strong><?= e($b['name']) ?></strong></td>
          <td title="<?= e(block_academic_label($b)) ?>"><?= e(block_academic_label($b)) ?></td>
          <td><?= e($b['subject_codes'] ?: '—') ?></td>
          <td class="col-num"><?= (int)$c['total'] ?></td>
          <td><span class="tag tag--pass">Complete</span></td>
          <td class="col-actions">
            <div class="row-actions">
              <button type="button" class="btn"
                      data-open-block-report="<?= (int)$b['id'] ?>"
                      data-block-name="<?= e($b['name']) ?>"
                      data-testid="open-block-<?= (int)$b['id'] ?>">
                View record
              </button>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($pendingBlocks): ?>
<div class="card" data-testid="pending-grade-blocks">
  <h3>Still grading (<?= count($pendingBlocks) ?>)</h3>
  <p class="help" style="margin-bottom:12px">
    These blocks are not in Records yet — at least one student still needs a grade. Review Class and finish everyone.
  </p>
  <div class="table-wrap">
    <table class="table table--aligned table--reports">
      <colgroup>
        <col class="col-block">
        <col class="col-program">
        <col class="col-subject">
        <col class="col-num">
        <col class="col-status">
        <col class="col-actions">
      </colgroup>
      <thead>
        <tr>
          <th>Block</th>
          <th>Program</th>
          <th>Subject</th>
          <th class="col-num">Progress</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($pendingBlocks as $b):
          $c = $b['_completion'];
          $miss = count($c['missing']);
      ?>
        <tr>
          <td><strong><?= e($b['name']) ?></strong></td>
          <td title="<?= e(block_academic_label($b)) ?>"><?= e(block_academic_label($b)) ?></td>
          <td><?= e($b['subject_codes'] ?: '—') ?></td>
          <td class="col-num"><?= (int)$c['graded'] ?>/<?= (int)$c['total'] ?></td>
          <td>
            <span class="tag tag--fail">Incomplete</span>
            <?php if ($miss > 0): ?>
              <div class="help"><?= (int)$miss ?> missing</div>
            <?php endif; ?>
          </td>
          <td class="col-actions">
            <div class="row-actions">
              <button type="button" class="btn-out"
                      data-open-block-report="<?= (int)$b['id'] ?>"
                      data-block-name="<?= e($b['name']) ?>"
                      data-testid="check-block-<?= (int)$b['id'] ?>">
                Who is missing
              </button>
              <a class="btn" href="class.php?block_id=<?= (int)$b['id'] ?>">Open Class</a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if (!$readyBlocks && !$pendingBlocks): ?>
  <p class="empty">No blocks to report yet.</p>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.UIModal) return;

  function openReport(blockId, blockName) {
    UIModal.open({
      title: (blockName || 'Block') + ' · grades',
      subtitle: 'Record appears only when every student is graded',
      wide: true,
      roster: true,
      url: 'report_fragment.php?block_id=' + encodeURIComponent(blockId)
    });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-open-block-report]');
    if (!btn) return;
    openReport(btn.getAttribute('data-open-block-report'), btn.getAttribute('data-block-name'));
  });

  <?php if ($blockId > 0): ?>
  openReport(<?= (int)$blockId ?>, <?= json_encode(
      (function () use ($handledBlocks, $blockId) {
          foreach ($handledBlocks as $b) {
              if ((int)$b['id'] === $blockId) {
                  return $b['name'];
              }
          }
          return 'Block';
      })()
  ) ?>);
  <?php endif; ?>
});
</script>
<?php render_footer(); ?>
