<?php
/** admin/teachers.php — teachers by department; View / Add / Update in modals. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('admin', 'users');
$pdo = db();
ensure_user_active_column();
ensure_user_avatar_column();
ensure_academics_schema();
ensure_user_profile_schema($pdo);

$q = trim($_GET['q'] ?? '');
$blockFilter = (int)($_GET['block_id'] ?? 0);
$deptFilter = (int)($_GET['department_id'] ?? 0);

$departments = departments_list(true);
$blocks = $pdo->query(
    'SELECT b.id, b.name, d.code AS department_code, c.code AS course_code, c.name AS course_name
     FROM blocks b
     LEFT JOIN departments d ON d.id = b.department_id
     LEFT JOIN courses c ON c.id = b.course_id
     ORDER BY d.code IS NULL, d.code, b.name'
)->fetchAll();

$where = ['u.role = "teacher"'];
$params = [];

if ($q !== '') {
    $where[] = '(u.username LIKE :q OR u.first_name LIKE :q2 OR u.last_name LIKE :q3
                OR u.email LIKE :q4 OR u.phone LIKE :q5
                OR CONCAT(u.first_name, " ", u.last_name) LIKE :q6
                OR dept.code LIKE :q11 OR dept.name LIKE :q12
                OR EXISTS (
                    SELECT 1 FROM blocks b2
                    LEFT JOIN departments d2 ON d2.id = b2.department_id
                    LEFT JOIN courses c2 ON c2.id = b2.course_id
                    WHERE b2.teacher_id = u.id
                      AND (b2.name LIKE :q7 OR d2.code LIKE :q8 OR c2.code LIKE :q9 OR c2.name LIKE :q10)
                ))';
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
    $params[':q10'] = $like;
    $params[':q11'] = $like;
    $params[':q12'] = $like;
}

if ($blockFilter > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM blocks b3 WHERE b3.teacher_id = u.id AND b3.id = :bid)';
    $params[':bid'] = $blockFilter;
}

if ($deptFilter > 0) {
    $where[] = 'u.department_id = :did';
    $params[':did'] = $deptFilter;
}

$sql = 'SELECT u.id, u.username, u.first_name, u.last_name, u.email, u.phone, u.age, u.is_active, u.avatar,
               u.department_id,
               dept.code AS department_code, dept.name AS department_name,
               (SELECT GROUP_CONCAT(b.name ORDER BY b.name SEPARATOR ", ")
                FROM blocks b WHERE b.teacher_id = u.id) AS blocks,
               (SELECT MIN(b.name) FROM blocks b WHERE b.teacher_id = u.id) AS primary_block,
               (SELECT c.code FROM blocks b
                LEFT JOIN courses c ON c.id = b.course_id
                WHERE b.teacher_id = u.id ORDER BY b.name LIMIT 1) AS course_code,
               (SELECT c.name FROM blocks b
                LEFT JOIN courses c ON c.id = b.course_id
                WHERE b.teacher_id = u.id ORDER BY b.name LIMIT 1) AS course_name
        FROM users u
        LEFT JOIN departments dept ON dept.id = u.department_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY dept.code IS NULL, dept.code, u.is_active DESC, u.last_name, u.first_name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$teachers = $stmt->fetchAll();

$teachersByDept = [];
foreach ($teachers as $t) {
    $key = trim((string)($t['department_code'] ?? ''));
    if ($key === '') {
        $key = 'Unassigned';
        $label = 'Unassigned department';
    } else {
        $label = $t['department_code'] . ' — ' . ($t['department_name'] ?? '');
    }
    if (!isset($teachersByDept[$key])) {
        $teachersByDept[$key] = ['label' => $label, 'rows' => []];
    }
    $teachersByDept[$key]['rows'][] = $t;
}

render_header('Teachers', $user);
$modalCss = @filemtime(__DIR__ . '/../css/modal.css') ?: time();
$modalJs = @filemtime(__DIR__ . '/../js/ui-modal.js') ?: time();
echo '<link rel="stylesheet" href="../css/modal.css?v=' . (int)$modalCss . '">';
echo '<script src="../js/ui-modal.js?v=' . (int)$modalJs . '" defer></script>';
render_flash();
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Teachers</h1>
    <p class="page-desc">Browse by department. View and add teachers in a dialog — no extra page hops.</p>
  </div>
  <div class="actions">
    <button type="button" class="btn" id="btn-add-teacher" data-testid="open-add-teacher">Add teacher</button>
  </div>
</div>

<div class="card" data-testid="search-teachers-card">
  <h3>Search teachers</h3>
  <form method="get" action="teachers.php" class="search-bar">
    <div class="field grow">
      <label for="teacher-q">Keyword</label>
      <input class="input" id="teacher-q" name="q" value="<?= e($q) ?>"
             placeholder="Name, username, email, phone, block, dept, or course…" data-testid="teacher-search">
    </div>
    <div class="field">
      <label for="teacher-dept">Department</label>
      <select class="input" id="teacher-dept" name="department_id" data-testid="teacher-dept-filter">
        <option value="0">All departments</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= $deptFilter === (int)$d['id'] ? 'selected' : '' ?>>
            <?= e(department_option_label($d)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="teacher-block">Block</label>
      <select class="input" id="teacher-block" name="block_id" data-testid="teacher-block-filter">
        <option value="0">All blocks</option>
        <?php foreach ($blocks as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= $blockFilter === (int)$b['id'] ? 'selected' : '' ?>>
            <?= e(block_option_label($b)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field actions-inline">
      <button class="btn" type="submit" data-testid="teacher-search-submit">Search</button>
      <a class="btn-out" href="teachers.php">Clear</a>
    </div>
  </form>
</div>

<template id="tpl-add-teacher">
  <form method="post" action="actions.php" data-testid="add-teacher-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_teacher">
    <div class="form-grid">
      <div class="field"><label>First name</label>
        <input class="input" name="first_name" data-testid="t-first" required data-autofocus>
        <div class="help">Letters only.</div></div>
      <div class="field"><label>Last name</label>
        <input class="input" name="last_name" data-testid="t-last" required>
        <div class="help">Letters only.</div></div>
      <div class="field"><label>Email</label>
        <input class="input" name="email" type="email" placeholder="name@gmail.com" data-testid="t-email" required>
        <div class="help">Must be a @gmail.com account.</div></div>
      <div class="field"><label>Phone</label>
        <input class="input" name="phone" inputmode="numeric" placeholder="09xxxxxxxxx" data-testid="t-phone" required>
        <div class="help">Exactly 11 digits.</div></div>
      <div class="field"><label>Age</label>
        <input class="input" name="age" inputmode="numeric" data-testid="t-age" required>
        <div class="help">Whole number.</div></div>
      <div class="field"><label>School ID (password)</label>
        <input class="input" name="school_id" placeholder="2026-00200" data-testid="t-schoolid" required>
        <div class="help">Format 2026-00200 — also the login password.</div></div>
      <div class="field" style="grid-column:1/-1"><label>Department</label>
        <select class="input" name="department_id" data-testid="t-department" required>
          <option value="">— select department —</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= (int)$d['id'] ?>"><?= e(department_option_label($d)) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="help">Example: CICT for BSIT teachers.</div>
      </div>
    </div>
    <div data-modal-footer>
      <button type="button" class="btn-out" data-ui-modal-dismiss>Cancel</button>
      <button class="btn" type="submit" data-testid="t-submit">Create teacher</button>
    </div>
  </form>
</template>

<div class="card">
  <h3>Teachers by department (<?= count($teachers) ?>)</h3>
  <?php if ($teachers): ?>
  <div class="table-wrap">
    <table class="table table--aligned table--list table--teachers" data-testid="teachers-table">
      <colgroup>
        <col class="col-person">
        <col class="col-user">
        <col class="col-email">
        <col class="col-blocks">
        <col class="col-status">
        <col class="col-actions">
      </colgroup>
      <thead>
        <tr>
          <th>Name</th>
          <th>Username</th>
          <th>Email</th>
          <th>Blocks</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($teachersByDept as $group):
          $deptTeachers = $group['rows'];
      ?>
        <tr class="table-group-row">
          <td colspan="6">
            <span class="table-group-label"><?= e($group['label']) ?></span>
            <span class="help"> · <?= count($deptTeachers) ?> teacher<?= count($deptTeachers) === 1 ? '' : 's' ?></span>
          </td>
        </tr>
        <?php foreach ($deptTeachers as $t):
            $active = (int)($t['is_active'] ?? 1) === 1;
            $fullName = format_person_name($t);
        ?>
          <tr data-testid="teacher-row-<?= $t['id'] ?>" class="<?= $active ? '' : 'row-inactive' ?>">
            <td>
              <div class="person-cell">
                <?= avatar_img_tag($t['avatar'] ?? null, $fullName, 'avatar avatar--sm') ?>
                <span class="person-name"><?= e($fullName) ?></span>
              </div>
            </td>
            <td class="mono"><?= e($t['username']) ?></td>
            <td><?= e($t['email']) ?></td>
            <td><?= e($t['blocks'] ?: '—') ?></td>
            <td><span class="tag <?= $active ? 'tag--pass' : 'tag--fail' ?>"><?= $active ? 'Active' : 'Deactivated' ?></span></td>
            <td class="col-actions">
              <div class="row-actions">
                <button type="button" class="btn-out" data-open-teacher-view="<?= (int)$t['id'] ?>"
                        data-teacher-name="<?= e($fullName) ?>" data-testid="view-teacher-<?= (int)$t['id'] ?>">View</button>
                <button type="button" class="btn-out" data-open-teacher-edit="<?= (int)$t['id'] ?>"
                        data-teacher-name="<?= e($fullName) ?>" data-testid="edit-teacher-<?= (int)$t['id'] ?>">Update</button>
                <form method="post" action="actions.php">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="set_user_active">
                  <input type="hidden" name="id" value="<?= $t['id'] ?>">
                  <input type="hidden" name="is_active" value="<?= $active ? '0' : '1' ?>">
                  <input type="hidden" name="back" value="teachers.php">
                  <button class="<?= $active ? 'btn-danger' : 'btn-out' ?>" type="submit"
                          onclick="return confirm('<?= $active ? 'Deactivate' : 'Activate' ?> this teacher?');">
                    <?= $active ? 'Deactivate' : 'Activate' ?>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <p class="empty"><?= $q !== '' || $blockFilter > 0 || $deptFilter > 0 ? 'No teachers match your filters.' : 'No teachers yet. Use Add teacher to create the first account.' ?></p>
  <?php endif; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.UIModal) return;

  function openTeacher(id, mode, name) {
    var title = mode === 'edit' ? 'Update teacher' : 'Teacher details';
    UIModal.open({
      title: title,
      subtitle: name || '',
      wide: mode === 'edit',
      url: 'teacher_fragment.php?id=' + encodeURIComponent(id) + '&mode=' + encodeURIComponent(mode),
      onOpen: function (body) {
        body.addEventListener('click', function (e) {
          var editBtn = e.target.closest('[data-open-teacher-edit]');
          if (editBtn) {
            e.preventDefault();
            openTeacher(editBtn.getAttribute('data-open-teacher-edit'), 'edit', name);
          }
        });
      }
    });
  }

  document.getElementById('btn-add-teacher').addEventListener('click', function () {
    var tpl = document.getElementById('tpl-add-teacher');
    var wrap = document.createElement('div');
    wrap.appendChild(tpl.content.cloneNode(true));
    UIModal.open({
      title: 'Add teacher',
      subtitle: 'Creates a teacher account. School ID is also the login password.',
      wide: true,
      html: wrap.innerHTML
    });
  });

  document.addEventListener('click', function (e) {
    var view = e.target.closest('[data-open-teacher-view]');
    if (view) {
      openTeacher(view.getAttribute('data-open-teacher-view'), 'view', view.getAttribute('data-teacher-name'));
      return;
    }
    var edit = e.target.closest('[data-open-teacher-edit]');
    if (edit && !edit.closest('#ui-modal-root')) {
      openTeacher(edit.getAttribute('data-open-teacher-edit'), 'edit', edit.getAttribute('data-teacher-name'));
    }
  });
});
</script>
<?php render_footer(); ?>
