<?php
/** admin/students.php — search, add, and manage authorized student records. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('admin', 'students');
$pdo = db();
ensure_user_active_column();
ensure_user_avatar_column();
ensure_academics_schema($pdo);

$q = trim($_GET['q'] ?? '');
$blockFilter = (int)($_GET['block_id'] ?? 0);
$locked = app_setting('records_locked', '0') === '1';

$blocks = $pdo->query(
    'SELECT b.id, b.name, d.code AS department_code, c.code AS course_code, c.name AS course_name
     FROM blocks b
     LEFT JOIN departments d ON d.id = b.department_id
     LEFT JOIN courses c ON c.id = b.course_id
     ORDER BY d.code IS NULL, d.code, b.name'
)->fetchAll();
$programs = courses_list(null, true);

$where = ['u.role = "student"'];
$params = [];
if ($q !== '') {
    $where[] = '(u.username LIKE :q OR u.first_name LIKE :q2 OR u.last_name LIKE :q3 OR u.email LIKE :q4 OR u.phone LIKE :q5
                OR CONCAT(u.first_name, " ", u.last_name) LIKE :q6 OR b.name LIKE :q7
                OR d.code LIKE :q8 OR c.code LIKE :q9 OR c.name LIKE :q10
                OR pd.code LIKE :q11 OR pc.code LIKE :q12 OR pc.name LIKE :q13)';
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
    $params[':q13'] = $like;
}
if ($blockFilter > 0) {
    $where[] = 'u.block_id = :bid';
    $params[':bid'] = $blockFilter;
}

$sql = 'SELECT u.id, u.username, u.first_name, u.last_name, u.email, u.phone, u.age, u.is_active,
               u.avatar, u.block_id, u.program_id, b.name AS block_name,
               d.code AS department_code, c.code AS course_code, c.name AS course_name,
               pd.code AS program_dept_code, pc.code AS program_code, pc.name AS program_name
        FROM users u
        LEFT JOIN blocks b ON b.id = u.block_id
        LEFT JOIN departments d ON d.id = b.department_id
        LEFT JOIN courses c ON c.id = b.course_id
        LEFT JOIN courses pc ON pc.id = u.program_id
        LEFT JOIN departments pd ON pd.id = pc.department_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY b.name IS NULL, d.code, c.code, b.name, u.is_active DESC, u.last_name, u.first_name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

$studByBlock = [];
foreach ($students as $s) {
    $key = $s['block_name'] ?: 'Unassigned';
    if (!isset($studByBlock[$key])) {
        $studByBlock[$key] = ['meta' => $s, 'rows' => []];
    }
    $studByBlock[$key]['rows'][] = $s;
}

render_header('Students', $user);
render_flash();
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Students</h1>
    <p class="page-desc">Create, update, and deactivate student accounts. Search and view authorized records.</p>
  </div>
</div>

<div class="card" data-testid="search-students-card">
  <h3>Search student records</h3>
  <form method="get" action="students.php" class="search-bar">
    <div class="field grow">
      <label for="student-q">Keyword</label>
      <input class="input" id="student-q" name="q" value="<?= e($q) ?>"
             placeholder="Name, username, email, phone, block, dept, or course…" data-testid="student-search">
    </div>
    <div class="field">
      <label for="student-block">Block</label>
      <select class="input" id="student-block" name="block_id" data-testid="student-block-filter">
        <option value="0">All blocks</option>
        <?php foreach ($blocks as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= $blockFilter === (int)$b['id'] ? 'selected' : '' ?>>
            <?= e($b['name'] . ' · ' . block_academic_label($b)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field actions-inline">
      <button class="btn" type="submit" data-testid="student-search-submit">Search</button>
      <a class="btn-out" href="students.php">Clear</a>
    </div>
  </form>
</div>

<?php if (!$locked): ?>
<div class="card" data-testid="add-student-card">
  <h3>Add or update permitted records</h3>
  <p class="page-desc" style="margin:0 0 14px">Create a new student account. To update an existing record, open it with <strong>View</strong> then <strong>Update record</strong>.</p>
  <?php if (!$blocks): ?>
    <p class="empty">Create a block first (Blocks tab) before adding students.</p>
  <?php else: ?>
  <form method="post" action="actions.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_student">
    <div class="form-grid">
      <div class="field"><label>First name</label>
        <input class="input" name="first_name" data-testid="s-first" required>
        <div class="help">Letters only.</div></div>
      <div class="field"><label>Last name</label>
        <input class="input" name="last_name" data-testid="s-last" required>
        <div class="help">Letters only.</div></div>
      <div class="field"><label>Email</label>
        <input class="input" name="email" type="email" placeholder="name@gmail.com" data-testid="s-email" required>
        <div class="help">Must be a @gmail.com account.</div></div>
      <div class="field"><label>Phone</label>
        <input class="input" name="phone" inputmode="numeric" placeholder="09xxxxxxxxx" data-testid="s-phone" required>
        <div class="help">Exactly 11 digits.</div></div>
      <div class="field"><label>Age</label>
        <input class="input" name="age" inputmode="numeric" data-testid="s-age" required>
        <div class="help">Whole number.</div></div>
      <div class="field"><label>School ID (password)</label>
        <input class="input" name="school_id" placeholder="2026-00101" data-testid="s-schoolid" required>
        <div class="help">Format 2026-00101 — stored only as a bcrypt hash.</div></div>
      <div class="field"><label>Block</label>
        <select class="input" name="block_id" data-testid="s-block" required>
          <option value="">— select block —</option>
          <?php foreach ($blocks as $b): ?>
            <option value="<?= $b['id'] ?>"><?= e($b['name'] . ' · ' . block_academic_label($b)) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>Degree program</label>
        <select class="input" name="program_id" data-testid="s-program" required>
          <option value="">— select program —</option>
          <?php foreach ($programs as $p): ?>
            <option value="<?= (int)$p['id'] ?>">
              <?= e($p['department_code'] . ' · ' . $p['code'] . ' — ' . $p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div class="actions"><button class="btn" type="submit" data-testid="s-submit">Create student</button></div>
  </form>
  <?php endif; ?>
</div>
<?php else: ?>
  <div class="flash flash--error">Records are locked in System Settings. You can still search and view authorized student information.</div>
<?php endif; ?>

<div class="card">
  <h3>Authorized student information (<?= count($students) ?>)</h3>
  <?php if ($students): ?>
  <div class="table-wrap">
    <table class="table table--aligned table--list" data-testid="students-table">
      <colgroup>
        <col class="col-person">
        <col class="col-user">
        <col class="col-email">
        <col class="col-phone">
        <col class="col-age">
        <col class="col-status">
        <col class="col-actions">
      </colgroup>
      <thead>
        <tr>
          <th>Name</th>
          <th>Username</th>
          <th>Email</th>
          <th>Phone</th>
          <th class="col-num">Age</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($studByBlock as $blockName => $group):
          $blockStudents = $group['rows'];
          $meta = $group['meta'];
      ?>
        <tr class="table-group-row">
          <td colspan="7">
            <span class="table-group-label"><?= e($blockName) ?></span>
            <?php if ($blockName !== 'Unassigned'): ?>
              <span class="tag tag--academic"><?= e(block_academic_label($meta)) ?></span>
            <?php endif; ?>
            <span class="help"> · <?= count($blockStudents) ?> student<?= count($blockStudents) === 1 ? '' : 's' ?></span>
          </td>
        </tr>
        <?php foreach ($blockStudents as $s):
            $active = (int)($s['is_active'] ?? 1) === 1;
            $fullName = $s['first_name'].' '.$s['last_name'];
        ?>
          <tr data-testid="student-row-<?= $s['id'] ?>" class="<?= $active ? '' : 'row-inactive' ?>">
            <td>
              <div class="person-cell">
                <?= avatar_img_tag($s['avatar'] ?? null, $fullName, 'avatar avatar--sm') ?>
                <span class="person-name"><?= e($fullName) ?></span>
              </div>
            </td>
            <td class="mono"><?= e($s['username']) ?></td>
            <td><?= e($s['email']) ?></td>
            <td class="mono"><?= e($s['phone']) ?></td>
            <td class="col-num"><?= e($s['age']) ?></td>
            <td><span class="tag <?= $active ? 'tag--pass' : 'tag--fail' ?>"><?= $active ? 'Active' : 'Deactivated' ?></span></td>
            <td class="col-actions">
              <div class="row-actions">
                <a class="btn-out" href="student_view.php?id=<?= (int)$s['id'] ?>" data-testid="view-student-<?= $s['id'] ?>">View</a>
                <?php if (!$locked): ?>
                  <a class="btn-out" href="student_view.php?id=<?= (int)$s['id'] ?>&mode=edit" data-testid="edit-student-<?= $s['id'] ?>">Update</a>
                  <form method="post" action="actions.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="set_user_active">
                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                    <input type="hidden" name="is_active" value="<?= $active ? '0' : '1' ?>">
                    <input type="hidden" name="back" value="students.php">
                    <button class="<?= $active ? 'btn-danger' : 'btn-out' ?>" type="submit"
                            data-testid="<?= $active ? 'deactivate' : 'activate' ?>-student-<?= $s['id'] ?>"
                            onclick="return confirm('<?= $active ? 'Deactivate' : 'Activate' ?> this student account?');">
                      <?= $active ? 'Deactivate' : 'Activate' ?>
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <p class="empty"><?= $q !== '' || $blockFilter > 0 ? 'No students match your search.' : 'No students yet.' ?></p>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
