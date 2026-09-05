<?php
/**
 * teacher/index.php — Dashboard: block/student counts + own audit activity.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('teacher', 'dashboard');
$pdo = db();
ensure_academics_schema($pdo);
ensure_audit_schema($pdo);
ensure_user_active_column($pdo);
$tid = (int)$user['id'];

$adviserBlocks = $pdo->prepare(
    'SELECT b.id, b.name,
            d.code AS department_code, c.code AS course_code, c.name AS course_name,
            (SELECT COUNT(*) FROM users u
             WHERE u.role = "student" AND u.block_id = b.id AND COALESCE(u.is_active, 1) = 1) AS student_count
     FROM blocks b
     LEFT JOIN departments d ON d.id = b.department_id
     LEFT JOIN courses c ON c.id = b.course_id
     WHERE b.teacher_id = :t
     ORDER BY b.name'
);
$adviserBlocks->execute([':t' => $tid]);
$adviserBlocks = $adviserBlocks->fetchAll();
$blockCount = count($adviserBlocks);
$blockIds = array_map('intval', array_column($adviserBlocks, 'id'));

// Subject-scoped blocks only (same as Class / Reports)
$classBlocks = teacher_enrollment_blocks($pdo, $tid);
$classBlockCount = count($classBlocks);

$offerings = offerings_list($tid, true);
$uniqueSubjects = array_values(array_unique(array_column($offerings, 'subject_code')));
$subjectHint = count($uniqueSubjects) === 1
    ? $uniqueSubjects[0]
    : (count($uniqueSubjects) > 1 ? implode(', ', $uniqueSubjects) : '');

$studentsInBlocks = 0;
if ($blockIds) {
    $in = implode(',', $blockIds);
    $studentsInBlocks = (int)$pdo->query(
        "SELECT COUNT(*) FROM users
         WHERE role = 'student' AND COALESCE(is_active, 1) = 1 AND block_id IN ($in)"
    )->fetchColumn();
}

$enStmt = $pdo->prepare(
    'SELECT COUNT(DISTINCT e.student_id)
     FROM enrollments e
     JOIN subject_offerings o ON o.id = e.offering_id
     WHERE o.teacher_id = :t AND o.is_active = 1'
);
$enStmt->execute([':t' => $tid]);
$enrolledCount = (int)$enStmt->fetchColumn();

$audit = $pdo->prepare(
    'SELECT id, action, target, details, ip_address, created_at
     FROM audit_log
     WHERE actor_id = :id
     ORDER BY created_at DESC, id DESC
     LIMIT 40'
);
$audit->execute([':id' => $tid]);
$auditRows = $audit->fetchAll();

$me = $pdo->prepare(
    'SELECT first_name, last_name, username, avatar FROM users WHERE id = :id'
);
$me->execute([':id' => $tid]);
$me = $me->fetch() ?: $user;
$_SESSION['user']['avatar'] = $me['avatar'] ?? null;

render_header('Dashboard', $user);
render_flash();
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Dashboard</h1>
    <p class="page-desc">
      Overview of blocks for your subject<?= $subjectHint !== '' ? ' (<strong>' . e($subjectHint) . '</strong>)' : '' ?>,
      class size, and recent activity.
    </p>
  </div>
  <div class="row-actions">
    <a class="btn" href="class.php" data-testid="dash-goto-class">Open Class</a>
    <a class="btn-out" href="profile.php">My Profile</a>
  </div>
</div>

<div class="stat-row" data-testid="teacher-dash-stats">
  <div class="stat"><div class="num"><?= $blockCount ?></div><div class="lbl">Blocks you advise</div></div>
  <div class="stat"><div class="num"><?= $classBlockCount ?></div><div class="lbl">Subject blocks</div></div>
  <div class="stat"><div class="num"><?= $studentsInBlocks ?></div><div class="lbl">Students in advised blocks</div></div>
  <div class="stat"><div class="num"><?= $enrolledCount ?></div><div class="lbl">Enrolled in your offerings</div></div>
</div>

<div class="card" data-testid="teacher-blocks-card">
  <h3>Blocks for your subject (<?= $classBlockCount ?>)</h3>
  <p class="help" style="margin-bottom:12px">
    Only blocks with students enrolled in your subject offerings
    <?= $subjectHint !== '' ? '(e.g. ' . e($subjectHint) . ')' : '' ?> — same list as Class and Reports.
  </p>
  <?php if ($classBlocks): ?>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Block</th>
          <th>Program</th>
          <th class="col-num">Students</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($classBlocks as $b): ?>
        <tr>
          <td><?= e($b['name']) ?></td>
          <td><?= e(block_academic_label($b)) ?></td>
          <td class="col-num"><?= (int)$b['student_count'] ?></td>
          <td>
            <a class="btn-out" href="class.php?block_id=<?= (int)$b['id'] ?>">View class</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <p class="empty">No subject blocks yet. When students are enrolled in your offerings, their blocks will appear here.</p>
  <?php endif; ?>
</div>

<div class="card" data-testid="teacher-audit-card">
  <h3>Your activity log</h3>
  <p class="help" style="margin-bottom:12px">Recent audit events for your account (logins, grades, reports, profile changes).</p>
  <?php if ($auditRows): ?>
  <div class="table-wrap">
    <table class="table" data-testid="teacher-audit-table">
      <thead>
        <tr>
          <th>When</th>
          <th>Action</th>
          <th>Target</th>
          <th>Details</th>
          <th>IP</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($auditRows as $r): ?>
        <tr>
          <td class="mono"><?= e($r['created_at']) ?></td>
          <td><span class="tag"><?= e($r['action']) ?></span></td>
          <td class="mono"><?= e($r['target'] ?: '—') ?></td>
          <td><?= e($r['details'] ?: '—') ?></td>
          <td class="mono"><?= e($r['ip_address'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <p class="empty">No audit events for your account yet.</p>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
