<?php
/**
 * admin/teacher_fragment.php — HTML fragment for teacher view/edit modals.
 * Query: id (required for view/edit), mode=view|edit
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('admin', 'users');
$pdo = db();
ensure_user_active_column();
ensure_academics_schema($pdo);
ensure_user_profile_schema($pdo);

$id = (int)($_GET['id'] ?? 0);
$mode = ($_GET['mode'] ?? 'view') === 'edit' ? 'edit' : 'view';

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
    http_response_code(404);
    echo '<div class="ui-modal-error" role="alert">Teacher account not found.</div>';
    exit;
}

$departments = departments_list(true);
$isActive = (int)($teacher['is_active'] ?? 1) === 1;
$deptLine = trim((string)($teacher['department_code'] ?? '')) !== ''
    ? ($teacher['department_code'] . ' — ' . ($teacher['department_name'] ?? ''))
    : '—';
$schoolId = trim((string)($teacher['school_id'] ?? ''));

audit_log('TEACHER_VIEWED', 'teacher#' . $id, $teacher['username']);

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if ($mode === 'edit'): ?>
<form method="post" action="actions.php" id="teacher-edit-form" data-testid="modal-teacher-edit">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="update_teacher">
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <input type="hidden" name="return_to" value="teachers.php">
  <div class="form-grid">
    <div class="field"><label>First name</label>
      <input class="input" name="first_name" value="<?= e($teacher['first_name']) ?>" required data-autofocus>
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
      <select class="input" name="department_id" required>
        <option value="">— select —</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= (int)($teacher['department_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>>
            <?= e(department_option_label($d)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Current School ID</label>
      <input class="input input--disabled mono" value="<?= e($schoolId !== '' ? $schoolId : '—') ?>" readonly>
    </div>
    <div class="field"><label>New school ID (optional)</label>
      <input class="input" name="school_id" placeholder="Leave blank to keep current">
      <div class="help">Also resets login password.</div></div>
  </div>
  <div data-modal-footer>
    <button type="button" class="btn-out" data-ui-modal-dismiss>Cancel</button>
    <button class="btn" type="submit" data-testid="modal-save-teacher">Save changes</button>
  </div>
</form>
<?php else: ?>
<div class="profile-head-row" style="margin-bottom:14px">
  <?= avatar_img_tag($teacher['avatar'] ?? null, format_person_name($teacher), 'avatar avatar--md') ?>
  <div>
    <div style="font-weight:700;font-size:16px"><?= e(format_person_name($teacher)) ?></div>
    <div class="help mono"><?= e($teacher['username']) ?>
      · <span class="tag <?= $isActive ? 'tag--pass' : 'tag--fail' ?>"><?= $isActive ? 'Active' : 'Deactivated' ?></span>
    </div>
  </div>
</div>
<dl class="info-grid" data-testid="modal-teacher-info">
  <div><dt>Full name</dt><dd><?= e(format_person_name($teacher)) ?></dd></div>
  <div><dt>Username</dt><dd class="mono"><?= e($teacher['username']) ?></dd></div>
  <div><dt>School ID (password)</dt><dd class="mono"><?= e($schoolId !== '' ? $schoolId : '—') ?></dd></div>
  <div><dt>Email</dt><dd><?= e($teacher['email'] ?: '—') ?></dd></div>
  <div><dt>Phone</dt><dd class="mono"><?= e($teacher['phone'] ?: '—') ?></dd></div>
  <div><dt>Age</dt><dd><?= e($teacher['age'] ?: '—') ?></dd></div>
  <div><dt>Department</dt><dd><?= e($deptLine) ?></dd></div>
  <div><dt>Blocks</dt><dd><?= e($teacher['blocks'] ?: '—') ?></dd></div>
  <div><dt>Status</dt><dd><?= $isActive ? 'Active' : 'Deactivated' ?></dd></div>
  <div><dt>Created</dt><dd class="mono"><?= e($teacher['created_at']) ?></dd></div>
</dl>
<div data-modal-footer>
  <button type="button" class="btn-out" data-ui-modal-dismiss>Close</button>
  <button type="button" class="btn" data-open-teacher-edit="<?= (int)$id ?>" data-testid="modal-goto-edit">Update</button>
</div>
<?php endif; ?>
