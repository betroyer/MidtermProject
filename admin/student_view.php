<?php
/**
 * admin/student_view.php — view / update authorized student information + enrollments.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('admin', 'students');
$pdo = db();
ensure_academics_schema($pdo);
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT u.*, b.name AS block_name,
            CONCAT(t.first_name, " ", t.last_name) AS teacher_name,
            pd.code AS department_code, pc.code AS program_code, pc.name AS program_name
     FROM users u
     LEFT JOIN blocks b ON b.id = u.block_id
     LEFT JOIN users t ON t.id = b.teacher_id
     LEFT JOIN courses pc ON pc.id = u.program_id
     LEFT JOIN departments pd ON pd.id = pc.department_id
     WHERE u.id = :id AND u.role = "student"'
);
$stmt->execute([':id' => $id]);
$student = $stmt->fetch();
if (!$student) {
    set_flash('error', 'Student record not found or not authorized.');
    redirect('students.php');
}

$blocks = $pdo->query(
    'SELECT b.id, b.name, d.code AS department_code, c.code AS course_code, c.name AS course_name
     FROM blocks b
     LEFT JOIN departments d ON d.id = b.department_id
     LEFT JOIN courses c ON c.id = b.course_id
     ORDER BY b.name'
)->fetchAll();
$programs = courses_list(null, true);
$offerings = offerings_list(null, true);

$enrolled = $pdo->prepare(
    'SELECT e.offering_id, o.name AS section_name, s.code AS subject_code, s.name AS subject_name,
            CONCAT(t.first_name, " ", t.last_name) AS teacher_name
     FROM enrollments e
     JOIN subject_offerings o ON o.id = e.offering_id
     JOIN subjects s ON s.id = o.subject_id
     JOIN users t ON t.id = o.teacher_id
     WHERE e.student_id = :id
     ORDER BY s.code'
);
$enrolled->execute([':id' => $id]);
$enrolled = $enrolled->fetchAll();
$enrolledIds = array_map('intval', array_column($enrolled, 'offering_id'));

$grades = $pdo->prepare(
    'SELECT g.subject, g.final_grade, g.grade_point, g.remark, g.updated_at,
            CONCAT(t.first_name, " ", t.last_name) AS teacher_name
     FROM grades g
     LEFT JOIN users t ON t.id = g.teacher_id
     WHERE g.student_id = :id ORDER BY g.subject'
);
$grades->execute([':id' => $id]);
$grades = $grades->fetchAll();

$mode = ($_GET['mode'] ?? 'view') === 'edit' ? 'edit' : 'view';
$locked = app_setting('records_locked', '0') === '1';
$isActive = (int)($student['is_active'] ?? 1) === 1;
$programLine = program_label([
    'department_code' => $student['department_code'] ?? '',
    'program_code' => $student['program_code'] ?? '',
    'program_name' => $student['program_name'] ?? '',
]);

audit_log('STUDENT_VIEWED', 'student#' . $id, $student['username']);

render_header($mode === 'edit' ? 'Update Student' : 'Student Information', $user);
render_flash();
?>
<div class="page-head">
  <div class="profile-head-row">
    <?= avatar_img_tag($student['avatar'] ?? null, $student['first_name'] . ' ' . $student['last_name'], 'avatar avatar--md') ?>
    <div>
      <h1 class="page-title"><?= e($student['first_name'] . ' ' . $student['last_name']) ?></h1>
      <p class="page-desc">Authorized student record · username <span class="mono"><?= e($student['username']) ?></span>
        · <span class="tag <?= $isActive ? 'tag--pass' : 'tag--fail' ?>"><?= $isActive ? 'Active' : 'Deactivated' ?></span>
      </p>
    </div>
  </div>
  <div class="actions">
    <a class="btn-out" href="students.php">← Back to search</a>
    <?php if ($mode === 'view' && !$locked): ?>
      <a class="btn" href="student_view.php?id=<?= $id ?>&mode=edit" data-testid="edit-student">Update record</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($locked): ?>
  <div class="flash flash--error">Student records are locked in System Settings. Viewing is allowed; updates are disabled.</div>
<?php endif; ?>

<?php if ($mode === 'edit' && !$locked): ?>
<div class="card" data-testid="update-student-card">
  <h3>Add or update permitted fields</h3>
  <form method="post" action="actions.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_student">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="form-grid">
      <div class="field"><label>First name</label>
        <input class="input" name="first_name" value="<?= e($student['first_name']) ?>" required>
        <div class="help">Letters only.</div></div>
      <div class="field"><label>Last name</label>
        <input class="input" name="last_name" value="<?= e($student['last_name']) ?>" required>
        <div class="help">Letters only.</div></div>
      <div class="field"><label>Email</label>
        <input class="input" name="email" type="email" value="<?= e($student['email']) ?>" required>
        <div class="help">Must be @gmail.com.</div></div>
      <div class="field"><label>Phone</label>
        <input class="input" name="phone" inputmode="numeric" value="<?= e($student['phone']) ?>" required>
        <div class="help">Exactly 11 digits.</div></div>
      <div class="field"><label>Age</label>
        <input class="input" name="age" inputmode="numeric" value="<?= e($student['age']) ?>" required></div>
      <div class="field"><label>Block</label>
        <select class="input" name="block_id" required>
          <?php foreach ($blocks as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= (int)$student['block_id'] === (int)$b['id'] ? 'selected' : '' ?>>
              <?= e($b['name'] . ' · ' . block_academic_label($b)) ?>
            </option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>Degree program</label>
        <select class="input" name="program_id" required>
          <option value="">— select —</option>
          <?php foreach ($programs as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= (int)($student['program_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
              <?= e($p['department_code'] . ' · ' . $p['code'] . ' — ' . $p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>New school ID (optional)</label>
        <input class="input" name="school_id" placeholder="Leave blank to keep current password">
        <div class="help">Format 2026-00101 — stored only as bcrypt if changed.</div></div>
    </div>
    <div class="actions">
      <button class="btn" type="submit" data-testid="save-student">Save changes</button>
      <a class="btn-out" href="student_view.php?id=<?= $id ?>">Cancel</a>
    </div>
  </form>
</div>
<?php else: ?>
<div class="card" data-testid="student-info-card">
  <h3>View authorized student information</h3>
  <dl class="info-grid">
    <div><dt>Username</dt><dd class="mono"><?= e($student['username']) ?></dd></div>
    <div><dt>Email</dt><dd><?= e($student['email'] ?: '—') ?></dd></div>
    <div><dt>Phone</dt><dd class="mono"><?= e($student['phone'] ?: '—') ?></dd></div>
    <div><dt>Age</dt><dd><?= e($student['age'] ?: '—') ?></dd></div>
    <div><dt>Block</dt><dd><?= e($student['block_name'] ?: '—') ?></dd></div>
    <div><dt>Program</dt><dd><?= e($programLine) ?></dd></div>
    <div><dt>Block adviser</dt><dd><?= e($student['teacher_name'] ?: '—') ?></dd></div>
    <div><dt>Theme</dt><dd><?= e(ucfirst($student['theme'] ?? 'dark')) ?></dd></div>
    <div><dt>Status</dt><dd><?= $isActive ? 'Active' : 'Deactivated' ?></dd></div>
    <div><dt>Created</dt><dd class="mono"><?= e($student['created_at']) ?></dd></div>
  </dl>
</div>

<?php if (!$locked): ?>
<div class="card">
  <h3>Account status</h3>
  <form method="post" action="actions.php" class="actions">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="set_user_active">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="back" value="student_view.php?id=<?= $id ?>">
    <?php if ($isActive): ?>
      <input type="hidden" name="is_active" value="0">
      <button class="btn-danger" type="submit" data-testid="deactivate-student"
              onclick="return confirm('Deactivate this student account? They will not be able to sign in.');">Deactivate account</button>
    <?php else: ?>
      <input type="hidden" name="is_active" value="1">
      <button class="btn" type="submit" data-testid="activate-student">Activate account</button>
    <?php endif; ?>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if (!$locked): ?>
<div class="card" data-testid="enrollments-card">
  <h3>Subject enrollments</h3>
  <p class="help" style="margin-bottom:12px">Each offering has its own teacher who can grade this student for that subject.</p>
  <?php if ($enrolled): ?>
  <table class="table" style="margin-bottom:16px">
    <thead><tr><th>Subject</th><th>Section</th><th>Teacher</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($enrolled as $en): ?>
      <tr>
        <td><?= e($en['subject_code'] . ' — ' . $en['subject_name']) ?></td>
        <td><?= e($en['section_name'] ?: '—') ?></td>
        <td><?= e($en['teacher_name']) ?></td>
        <td>
          <form method="post" action="actions.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="unenroll_student">
            <input type="hidden" name="student_id" value="<?= $id ?>">
            <input type="hidden" name="offering_id" value="<?= (int)$en['offering_id'] ?>">
            <button class="btn-danger" type="submit" onclick="return confirm('Remove this enrollment?');">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="empty">Not enrolled in any subject offerings yet.</p>
  <?php endif; ?>

  <?php
  $available = array_values(array_filter($offerings, static function ($o) use ($enrolledIds) {
      return !in_array((int)$o['id'], $enrolledIds, true);
  }));
  ?>
  <?php if ($available): ?>
  <form method="post" action="actions.php" class="form-grid" style="max-width:640px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="enroll_student">
    <input type="hidden" name="student_id" value="<?= $id ?>">
    <div class="field" style="grid-column:1/-1">
      <label>Enroll in offering</label>
      <select class="input" name="offering_id" required data-testid="enroll-offering">
        <option value="">— select —</option>
        <?php foreach ($available as $o): ?>
          <option value="<?= (int)$o['id'] ?>">
            <?= e($o['subject_code'] . ' — ' . $o['subject_name'] . ' · ' . ($o['name'] ?: 'Section') . ' · ' . $o['teacher_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="actions" style="grid-column:1/-1">
      <button class="btn" type="submit" data-testid="enroll-submit">Enroll</button>
    </div>
  </form>
  <?php elseif (!$offerings): ?>
    <p class="empty">Create subject offerings under Colleges &amp; Programs first.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h3>Grade records (<?= count($grades) ?>)</h3>
  <?php if ($grades): ?>
  <table class="table">
    <thead><tr><th>Subject</th><th>Teacher</th><th>Grade</th><th>Remark</th><th>Updated</th></tr></thead>
    <tbody>
    <?php foreach ($grades as $g):
        $gp = $g['grade_point'];
        $remark = $g['remark'];
        if ($gp === null || $gp === '') {
            [$gp, $mapped] = map_percent_to_scale((float)$g['final_grade']);
            if (!$remark || in_array($remark, ['Passed', 'Failed'], true)) $remark = $mapped;
        }
    ?>
      <tr>
        <td><?= e($g['subject']) ?></td>
        <td><?= e($g['teacher_name'] ?: '—') ?></td>
        <td><?= format_grade_cell($g['final_grade'], $gp) ?></td>
        <td><span class="tag <?= grade_tag_class($remark, $gp) ?>"><?= e($remark) ?></span></td>
        <td class="mono"><?= e($g['updated_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="empty">No grade records for this student yet.</p>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
