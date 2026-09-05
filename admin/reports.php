<?php
/** admin/reports.php — inbox of grade reports submitted by teachers. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('admin', 'reports');
$pdo = db();
ensure_academics_schema($pdo);

$viewId = (int)($_GET['id'] ?? 0);
$teacherFilter = (int)($_GET['teacher_id'] ?? 0);
$q = trim($_GET['q'] ?? '');
$detail = null;
$snapshot = [];

if ($viewId > 0) {
    $stmt = $pdo->prepare(
        'SELECT r.*, CONCAT(t.first_name," ",t.last_name) AS teacher_name, t.username AS teacher_username,
                s.code AS subject_code, s.name AS subject_name, o.name AS section_name
         FROM grade_reports r
         JOIN users t ON t.id = r.teacher_id
         LEFT JOIN subject_offerings o ON o.id = r.offering_id
         LEFT JOIN subjects s ON s.id = o.subject_id
         WHERE r.id = :id'
    );
    $stmt->execute([':id' => $viewId]);
    $detail = $stmt->fetch();
    if ($detail) {
        $decoded = json_decode((string)$detail['snapshot_json'], true);
        $snapshot = is_array($decoded) ? $decoded : [];
    }
}

$teachers = $pdo->query(
    'SELECT DISTINCT u.id, u.first_name, u.last_name, u.username
     FROM grade_reports r
     JOIN users u ON u.id = r.teacher_id
     ORDER BY u.last_name, u.first_name'
)->fetchAll();

$list = [];
if (!$detail) {
    $sql = 'SELECT r.id, r.title, r.status, r.note, r.submitted_at, r.snapshot_json, r.teacher_id,
                   CONCAT(t.first_name," ",t.last_name) AS teacher_name, t.username AS teacher_username,
                   s.code AS subject_code, s.name AS subject_name, o.name AS section_name
            FROM grade_reports r
            JOIN users t ON t.id = r.teacher_id
            LEFT JOIN subject_offerings o ON o.id = r.offering_id
            LEFT JOIN subjects s ON s.id = o.subject_id
            WHERE 1=1';
    $params = [];
    if ($teacherFilter > 0) {
        $sql .= ' AND r.teacher_id = :tid';
        $params[':tid'] = $teacherFilter;
    }
    if ($q !== '') {
        $sql .= ' AND (r.title LIKE :q OR r.note LIKE :q2 OR t.first_name LIKE :q3 OR t.last_name LIKE :q4
                       OR t.username LIKE :q5 OR s.code LIKE :q6 OR s.name LIKE :q7 OR o.name LIKE :q8
                       OR CONCAT(t.first_name, " ", t.last_name) LIKE :q9)';
        $like = '%' . $q . '%';
        $params[':q'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
        $params[':q4'] = $like;
        $params[':q5'] = $like;
        $params[':q6'] = $like;
        $params[':q7'] = $like;
        $params[':q8'] = $like;
        $params[':q9'] = $like;
    }
    $sql .= ' ORDER BY r.submitted_at DESC LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll();
    foreach ($list as &$row) {
        $decoded = json_decode((string)$row['snapshot_json'], true);
        $row['student_count'] = is_array($decoded) ? count($decoded) : 0;
        unset($row['snapshot_json']);
    }
    unset($row);
}

render_header($detail ? 'Grade Report' : 'Grade Reports', $user);
render_flash();
?>
<div class="page-head">
  <div>
    <h1 class="page-title"><?= $detail ? 'Grade report' : 'Grade reports inbox' ?></h1>
    <p class="page-desc"><?= $detail
        ? 'Frozen snapshot submitted by a teacher for admin review (read-only).'
        : 'Reports submitted by teachers from their subject offerings. View and print; live grades stay editable.' ?></p>
  </div>
  <div class="actions">
    <?php if ($detail): ?>
      <a class="btn-out no-print" href="reports.php<?= $q !== '' || $teacherFilter > 0 ? '?' . http_build_query(array_filter(['q' => $q ?: null, 'teacher_id' => $teacherFilter ?: null])) : '' ?>">← Inbox</a>
      <button class="btn no-print" onclick="window.print()" data-testid="print-admin-report">Print</button>
    <?php endif; ?>
  </div>
</div>

<?php if ($detail): ?>
<div class="card" data-testid="report-detail">
  <h3><?= e($detail['title']) ?></h3>
  <dl class="info-grid" style="margin-bottom:16px">
    <div><dt>Teacher</dt><dd><?= e($detail['teacher_name']) ?> <span class="mono">(<?= e($detail['teacher_username']) ?>)</span></dd></div>
    <div><dt>Subject</dt><dd><?= e(trim(($detail['subject_code'] ?? '') . ' — ' . ($detail['subject_name'] ?? ''), ' —') ?: '—') ?><?php if (!empty($detail['section_name'])): ?> · <?= e($detail['section_name']) ?><?php endif; ?></dd></div>
    <div><dt>Status</dt><dd><span class="tag tag--pass"><?= e($detail['status']) ?></span></dd></div>
    <div><dt>Students</dt><dd><?= count($snapshot) ?></dd></div>
    <div><dt>Submitted</dt><dd class="mono"><?= e($detail['submitted_at']) ?></dd></div>
    <div><dt>Note</dt><dd><?= e($detail['note'] ?: '—') ?></dd></div>
  </dl>
  <table class="table">
    <thead><tr><th>Student</th><th>Username</th><th>Block</th><th>Subject</th><th>Midterm</th><th>Final</th><th>Semestral</th><th>Remark</th></tr></thead>
    <tbody>
    <?php foreach ($snapshot as $row):
        $gp = $row['grade_point'] ?? null;
        $remark = $row['remark'] ?? null;
        $fg = $row['final_grade'] ?? null;
    ?>
      <tr>
        <td><?= e($row['name'] ?? '—') ?></td>
        <td class="mono"><?= e($row['username'] ?? '—') ?></td>
        <td><?= e($row['block_name'] ?? '—') ?></td>
        <td><?= e($row['subject'] ?? '—') ?></td>
        <td class="mono"><?= isset($row['midterm']) && $row['midterm'] !== null && $row['midterm'] !== '' ? e(format_score_number((float)$row['midterm'])) : '—' ?></td>
        <td class="mono"><?= isset($row['final_exam']) && $row['final_exam'] !== null && $row['final_exam'] !== '' ? e(format_score_number((float)$row['final_exam'])) : '—' ?></td>
        <td><?= $fg !== null && $fg !== '' ? format_grade_cell($fg, $gp) : '—' ?></td>
        <td><?php if ($remark): ?><span class="tag <?= grade_tag_class($remark, $gp) ?>"><?= e($remark) ?></span><?php else: ?>—<?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$snapshot): ?>
      <tr><td colspan="8" class="empty">Empty snapshot.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<div class="card" data-testid="admin-reports-filters">
  <h3>Search reports</h3>
  <form method="get" class="search-bar">
    <div class="field grow">
      <label for="report-q">Keyword</label>
      <input class="input" id="report-q" name="q" value="<?= e($q) ?>"
             placeholder="Title, teacher, subject, note…" data-testid="report-search">
    </div>
    <div class="field">
      <label for="report-teacher">Teacher</label>
      <select class="input" id="report-teacher" name="teacher_id" data-testid="report-teacher-filter">
        <option value="0">All teachers</option>
        <?php foreach ($teachers as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= $teacherFilter === (int)$t['id'] ? 'selected' : '' ?>>
            <?= e($t['last_name'] . ', ' . $t['first_name'] . ' (' . $t['username'] . ')') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field actions-inline">
      <button class="btn" type="submit">Search</button>
      <a class="btn-out" href="reports.php">Clear</a>
    </div>
  </form>
</div>

<div class="card">
  <h3>Submitted reports (<?= count($list) ?>)</h3>
  <?php if ($list): ?>
  <table class="table" data-testid="admin-reports-table">
    <thead>
      <tr>
        <th>Title / offering</th>
        <th>Teacher</th>
        <th>Students</th>
        <th>Status</th>
        <th>Note</th>
        <th>Submitted</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($list as $r):
      $offeringLabel = trim(($r['subject_code'] ?? '') . ' — ' . ($r['subject_name'] ?? ''), ' —');
      if (!empty($r['section_name'])) {
          $offeringLabel .= ($offeringLabel !== '' ? ' · ' : '') . $r['section_name'];
      }
    ?>
      <tr>
        <td>
          <div><?= e($r['title']) ?></div>
          <?php if ($offeringLabel !== ''): ?><div class="help"><?= e($offeringLabel) ?></div><?php endif; ?>
        </td>
        <td><?= e($r['teacher_name']) ?> <span class="mono">(<?= e($r['teacher_username']) ?>)</span></td>
        <td class="mono"><?= (int)$r['student_count'] ?></td>
        <td><span class="tag tag--pass"><?= e($r['status']) ?></span></td>
        <td><?= e($r['note'] ?: '—') ?></td>
        <td class="mono"><?= e($r['submitted_at']) ?></td>
        <td><a class="btn-out" href="reports.php?id=<?= (int)$r['id'] ?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="empty"><?= $q !== '' || $teacherFilter > 0 ? 'No reports match your filters.' : 'No grade reports submitted yet.' ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php render_footer(); ?>
