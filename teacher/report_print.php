<?php
/**
 * teacher/report_print.php — full-page printable grades for one block (opened from modal).
 * Included from reports.php when ?print=1&block_id= is set (variables already validated).
 */
if (!isset($pdo, $tid, $blockId, $activeBlock)) {
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/layout.php';
    $user = require_access('teacher', 'reports');
    $pdo = db();
    ensure_academics_schema($pdo);
    $tid = (int)$user['id'];
    $blockId = (int)($_GET['block_id'] ?? 0);
    $offerings = offerings_list($tid, true);
    $offeringIds = array_map('intval', array_column($offerings, 'id'));
    $blocks = teacher_enrollment_blocks($pdo, $tid, $offeringIds);
    $allowed = array_map('intval', array_column($blocks, 'id'));
    if ($blockId <= 0 || !in_array($blockId, $allowed, true)) {
        set_flash('error', 'You can only print grades for blocks that have students in your subject.');
        redirect('reports.php');
    }
    $activeBlock = null;
    foreach ($blocks as $b) {
        if ((int)$b['id'] === $blockId) {
            $activeBlock = $b;
            break;
        }
    }
} else {
    $offerings = offerings_list($tid, true);
    $offeringIds = array_map('intval', array_column($offerings, 'id'));
}

if (!$offeringIds || !$activeBlock) {
    set_flash('error', 'Nothing to print.');
    redirect('reports.php');
}

$inOffer = implode(',', $offeringIds);
$rowsSql = "SELECT u.id AS student_id, CONCAT(u.first_name,' ',u.last_name) AS name, u.username,
                   o.id AS offering_id, o.name AS section_name,
                   s.code AS subject_code, s.name AS subject_name,
                   g.midterm, g.final_exam, g.final_grade, g.grade_point, g.remark
            FROM users u
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
            'rows' => [],
        ];
    }
    $byOffering[$oid]['rows'][] = $r;
}

$subjectCodes = trim((string)($activeBlock['subject_codes'] ?? ''));

render_header('Print · ' . $activeBlock['name'], $user);
?>
<style>
@media print {
  .sidebar, .topbar, .no-print, .page-head .row-actions { display: none !important; }
  .main, .content { margin: 0 !important; padding: 0 !important; max-width: none !important; }
}
</style>
<div class="page-head">
  <div>
    <h1 class="page-title"><?= e($activeBlock['name']) ?> · grade report</h1>
    <p class="page-desc">
      <?= e(block_academic_label($activeBlock)) ?>
      <?php if ($subjectCodes !== ''): ?>
        · Subject<?= (strpos($subjectCodes, ',') !== false) ? 's' : '' ?>: <strong><?= e($subjectCodes) ?></strong>
      <?php endif; ?>
    </p>
  </div>
  <div class="row-actions no-print">
    <button type="button" class="btn" onclick="window.print()">Print</button>
    <a class="btn-out" href="reports.php">← Back to reports</a>
  </div>
</div>

<?php if (!$byOffering): ?>
  <p class="empty">No students from this block are enrolled in your subject yet.</p>
<?php else: ?>
  <?php foreach ($byOffering as $group):
      $rows = $group['rows'];
  ?>
  <div class="card" style="margin-bottom:16px">
    <h3><?= e($group['label']) ?></h3>
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
            $hasGrade = $r['final_grade'] !== null && $r['final_grade'] !== '';
        ?>
          <tr>
            <td><?= e($r['name']) ?></td>
            <td class="mono"><?= e($r['username']) ?></td>
            <td class="mono"><?= $r['midterm'] !== null && $r['midterm'] !== '' ? e(format_score_number((float)$r['midterm'])) : '—' ?></td>
            <td class="mono"><?= $r['final_exam'] !== null && $r['final_exam'] !== '' ? e(format_score_number((float)$r['final_exam'])) : '—' ?></td>
            <td><?= $hasGrade ? format_grade_cell($r['final_grade'], $gp) : '—' ?></td>
            <td><?php if ($remark): ?><span class="tag <?= grade_tag_class($remark, $gp) ?>"><?= e($remark) ?></span><?php else: ?>—<?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php render_footer(); ?>
