<?php
/** admin/teachers.php — search, create, update, and deactivate teacher accounts. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('admin', 'users');
$pdo = db();
ensure_user_active_column();
ensure_user_avatar_column();
ensure_academics_schema();

$q = trim($_GET['q'] ?? '');
$blockFilter = (int)($_GET['block_id'] ?? 0);

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
}

if ($blockFilter > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM blocks b3 WHERE b3.teacher_id = u.id AND b3.id = :bid)';
    $params[':bid'] = $blockFilter;
}

$sql = 'SELECT u.id, u.username, u.first_name, u.last_name, u.email, u.phone, u.age, u.is_active, u.avatar,
               (SELECT GROUP_CONCAT(b.name ORDER BY b.name SEPARATOR ", ")
                FROM blocks b WHERE b.teacher_id = u.id) AS blocks,
               (SELECT MIN(b.name) FROM blocks b WHERE b.teacher_id = u.id) AS primary_block,
               (SELECT d.code FROM blocks b
                LEFT JOIN departments d ON d.id = b.department_id
                WHERE b.teacher_id = u.id ORDER BY b.name LIMIT 1) AS department_code,
               (SELECT c.code FROM blocks b
                LEFT JOIN courses c ON c.id = b.course_id
                WHERE b.teacher_id = u.id ORDER BY b.name LIMIT 1) AS course_code,
               (SELECT c.name FROM blocks b
                LEFT JOIN courses c ON c.id = b.course_id
                WHERE b.teacher_id = u.id ORDER BY b.name LIMIT 1) AS course_name
        FROM users u
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY (SELECT MIN(b.name) FROM blocks b WHERE b.teacher_id = u.id) IS NULL,
                 (SELECT MIN(b.name) FROM blocks b WHERE b.teacher_id = u.id),
                 u.is_active DESC, u.last_name, u.first_name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$teachers = $stmt->fetchAll();

$teachersByBlock = [];
foreach ($teachers as $t) {
    $key = $t['primary_block'] ?: 'Unassigned';
    if (!isset($teachersByBlock[$key])) {
        $teachersByBlock[$key] = ['meta' => $t, 'rows' => []];
    }
    $teachersByBlock[$key]['rows'][] = $t;
}

render_header('Teachers', $user);
render_flash();
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Teachers</h1>
    <p class="page-desc">Search, create, update, and deactivate teacher accounts. Username is auto-generated; password is the school ID.</p>
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
      <label for="teacher-block">Block</label>
      <select class="input" id="teacher-block" name="block_id" data-testid="teacher-block-filter">
        <option value="0">All blocks</option>
        <?php foreach ($blocks as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= $blockFilter === (int)$b['id'] ? 'selected' : '' ?>>
            <?= e($b['name'] . ' · ' . block_academic_label($b)) ?>
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

<div class="card" data-testid="add-teacher-card">
  <h3>Create teacher account</h3>
  <form method="post" action="actions.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_teacher">
    <div class="form-grid">
      <div class="field"><label>First name</label>
        <input class="input" name="first_name" data-testid="t-first" required>
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
        <div class="help">Format 2026-00200 — stored only as a bcrypt hash.</div></div>
    </div>
    <div class="actions"><button class="btn" type="submit" data-testid="t-submit">Create teacher</button></div>
  </form>
</div>

<div class="card">
  <h3>Teachers (<?= count($teachers) ?>)</h3>
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
      <?php foreach ($teachersByBlock as $blockName => $group):
          $blockTeachers = $group['rows'];
          $meta = $group['meta'];
      ?>
        <tr class="table-group-row">
          <td colspan="6">
            <span class="table-group-label"><?= e($blockName) ?></span>
            <?php if ($blockName !== 'Unassigned'): ?>
              <span class="tag tag--academic"><?= e(block_academic_label($meta)) ?></span>
            <?php endif; ?>
            <span class="help"> · <?= count($blockTeachers) ?> teacher<?= count($blockTeachers) === 1 ? '' : 's' ?></span>
          </td>
        </tr>
        <?php foreach ($blockTeachers as $t):
            $active = (int)($t['is_active'] ?? 1) === 1;
            $fullName = $t['first_name'].' '.$t['last_name'];
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
                <a class="btn-out" href="teacher_view.php?id=<?= (int)$t['id'] ?>" data-testid="view-teacher-<?= $t['id'] ?>">View</a>
                <a class="btn-out" href="teacher_view.php?id=<?= (int)$t['id'] ?>&mode=edit" data-testid="edit-teacher-<?= $t['id'] ?>">Update</a>
                <form method="post" action="actions.php">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="set_user_active">
                  <input type="hidden" name="id" value="<?= $t['id'] ?>">
                  <input type="hidden" name="is_active" value="<?= $active ? '0' : '1' ?>">
                  <input type="hidden" name="back" value="teachers.php<?= $q !== '' || $blockFilter > 0 ? '?' . http_build_query(array_filter(['q' => $q, 'block_id' => $blockFilter ?: null])) : '' ?>">
                  <button class="<?= $active ? 'btn-danger' : 'btn-out' ?>" type="submit"
                          data-testid="<?= $active ? 'deactivate' : 'activate' ?>-teacher-<?= $t['id'] ?>"
                          onclick="return confirm('<?= $active ? 'Deactivate' : 'Activate' ?> this teacher account?');">
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
    <p class="empty"><?= $q !== '' || $blockFilter > 0 ? 'No teachers match your search.' : 'No teachers yet.' ?></p>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
