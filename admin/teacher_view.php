<?php
/**
 * admin/teacher_view.php — view / update teacher account information.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('admin', 'users');
$pdo = db();
ensure_user_active_column();
ensure_academics_schema($pdo);
ensure_user_profile_schema($pdo);
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT u.*, d.code AS department_code, d.name AS department_name,
            (SELECT GROUP_CONCAT(b.name SEPARATOR ", ") FROM blocks b WHERE b.teacher_id = u.id) AS blocks
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE u.id = :id AND u.role = "teacher"'
);
$stmt->execute([':id' => $id]);
$teacher = $stmt->fetch();
if (!$teacher) {
    set_flash('error', 'Teacher account not found.');
    redirect('teachers.php');
}

$departments = departments_list(true);
$mode = ($_GET['mode'] ?? 'view') === 'edit' ? 'edit' : 'view';
$isActive = (int)($teacher['is_active'] ?? 1) === 1;
$deptLine = trim((string)($teacher['department_code'] ?? '')) !== ''
    ? ($teacher['department_code'] . ' — ' . ($teacher['department_name'] ?? ''))
    : '—';

audit_log('TEACHER_VIEWED', 'teacher#' . $id, $teacher['username']);

render_header($mode === 'edit' ? 'Update Teacher' : 'Teacher Information', $user);
render_flash();
?>
<div class="page-head">
  <div class="profile-head-row">
    <?= avatar_img_tag($teacher['avatar'] ?? null, format_person_name($teacher), 'avatar avatar--md') ?>
    <div>
      <h1 class="page-title"><?= e(format_person_name($teacher)) ?></h1>
      <p class="page-desc">
        Teacher account · <span class="mono"><?= e($teacher['username']) ?></span>
        · <span class="tag <?= $isActive ? 'tag--pass' : 'tag--fail' ?>"><?= $isActive ? 'Active' : 'Deactivated' ?></span>
      </p>
    </div>
  </div>
  <div class="actions">
    <a class="btn-out" href="teachers.php">← Back to teachers</a>
    <?php if ($mode === 'view'): ?>
      <a class="btn" href="teacher_view.php?id=<?= $id ?>&mode=edit" data-testid="edit-teacher">Update</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($mode === 'edit'): ?>
<div class="card" data-testid="update-teacher-card">
  <h3>Update teacher information</h3>
  <form method="post" action="actions.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_teacher">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="form-grid">
      <div class="field"><label>First name</label>
        <input class="input" name="first_name" value="<?= e($teacher['first_name']) ?>" required>
        <div class="help">Letters only.</div></div>
      <div class="field"><label>Last name</label>
        <input class="input" name="last_name" value="<?= e($teacher['last_name']) ?>" required>
        <div class="help">Letters only.</div></div>
      <div class="field"><label>Email</label>
        <input class="input" name="email" type="email" value="<?= e($teacher['email']) ?>" required>
        <div class="help">Must be @gmail.com.</div></div>
      <div class="field"><label>Phone</label>
        <input class="input" name="phone" inputmode="numeric" value="<?= e($teacher['phone']) ?>" required>
        <div class="help">Exactly 11 digits.</div></div>
      <div class="field"><label>Age</label>
        <input class="input" name="age" inputmode="numeric" value="<?= e($teacher['age']) ?>" required></div>
      <div class="field"><label>Department</label>
        <select class="input" name="department_id" required data-testid="t-department-edit">
          <option value="">— select —</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= (int)$d['id'] ?>" <?= (int)($teacher['department_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>>
              <?= e(department_option_label($d)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>New school ID (optional)</label>
        <input class="input" name="school_id" placeholder="Leave blank to keep current password">
        <div class="help">Format 2026-00200 — stored only as bcrypt if changed.</div></div>
    </div>
    <div class="actions">
      <button class="btn" type="submit" data-testid="save-teacher">Save changes</button>
      <a class="btn-out" href="teacher_view.php?id=<?= $id ?>">Cancel</a>
    </div>
  </form>
</div>
<?php else: ?>
<div class="card" data-testid="teacher-info-card">
  <h3>Teacher information</h3>
  <dl class="info-grid">
    <div><dt>Full name</dt><dd><?= e(format_person_name($teacher)) ?></dd></div>
    <div><dt>Username</dt><dd class="mono"><?= e($teacher['username']) ?></dd></div>
    <div><dt>School ID (password)</dt><dd class="mono"><?= e(trim((string)($teacher['school_id'] ?? '')) !== '' ? $teacher['school_id'] : '—') ?></dd></div>
    <div><dt>Email</dt><dd><?= e($teacher['email'] ?: '—') ?></dd></div>
    <div><dt>Phone</dt><dd class="mono"><?= e($teacher['phone'] ?: '—') ?></dd></div>
    <div><dt>Age</dt><dd><?= e($teacher['age'] ?: '—') ?></dd></div>
    <div><dt>Department</dt><dd><?= e($deptLine) ?></dd></div>
    <div><dt>Blocks</dt><dd><?= e($teacher['blocks'] ?: '—') ?></dd></div>
    <div><dt>Status</dt><dd><?= $isActive ? 'Active' : 'Deactivated' ?></dd></div>
    <div><dt>Created</dt><dd class="mono"><?= e($teacher['created_at']) ?></dd></div>
  </dl>
</div>

<div class="card">
  <h3>Account status</h3>
  <form method="post" action="actions.php" class="actions">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="set_user_active">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="back" value="teacher_view.php?id=<?= $id ?>">
    <?php if ($isActive): ?>
      <input type="hidden" name="is_active" value="0">
      <button class="btn-danger" type="submit" data-testid="deactivate-teacher"
              onclick="return confirm('Deactivate this teacher account? They will not be able to sign in.');">Deactivate account</button>
    <?php else: ?>
      <input type="hidden" name="is_active" value="1">
      <button class="btn" type="submit" data-testid="activate-teacher">Activate account</button>
    <?php endif; ?>
  </form>
</div>
<?php endif; ?>
<?php render_footer(); ?>
