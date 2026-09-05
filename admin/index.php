<?php
/**
 * admin/index.php — Blocks overview (each block: 1 teacher, many students).
 * Default list shows 2 blocks; use search to find others.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('admin', 'dashboard');
$pdo = db();

$q = trim($_GET['q'] ?? '');
$defaultLimit = 2;
ensure_academics_schema();

$where = '1=1';
$params = [];
if ($q !== '') {
    $where = '(b.name LIKE :q OR CONCAT(t.first_name, " ", t.last_name) LIKE :q2
              OR t.username LIKE :q3 OR t.first_name LIKE :q4 OR t.last_name LIKE :q5
              OR d.code LIKE :q6 OR d.name LIKE :q7 OR c.code LIKE :q8 OR c.name LIKE :q9)';
    $like = '%' . $q . '%';
    $params = [
        ':q' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like, ':q5' => $like,
        ':q6' => $like, ':q7' => $like, ':q8' => $like, ':q9' => $like,
    ];
}

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM blocks b
     LEFT JOIN users t ON t.id = b.teacher_id
     LEFT JOIN departments d ON d.id = b.department_id
     LEFT JOIN courses c ON c.id = b.course_id
     WHERE {$where}"
);
$countStmt->execute($params);
$matchCount = (int)$countStmt->fetchColumn();
$totalBlocks = (int)$pdo->query('SELECT COUNT(*) FROM blocks')->fetchColumn();

$sql = "SELECT b.id, b.name, b.teacher_id, b.department_id, b.course_id,
               CONCAT(t.first_name, ' ', t.last_name) AS teacher_name, t.username AS teacher_username,
               t.avatar AS teacher_avatar,
               d.code AS department_code, d.name AS department_name,
               c.code AS course_code, c.name AS course_name
        FROM blocks b
        LEFT JOIN users t ON t.id = b.teacher_id
        LEFT JOIN departments d ON d.id = b.department_id
        LEFT JOIN courses c ON c.id = b.course_id
        WHERE {$where}
        ORDER BY d.code IS NULL, d.code, c.code, b.name";
if ($q === '') {
    $sql .= ' LIMIT ' . (int)$defaultLimit;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$blocks = $stmt->fetchAll();

$departments = departments_list(true);
$courses = courses_list(null, true);

$studByBlock = [];
if ($blocks) {
    $ids = array_map(static fn($b) => (int)$b['id'], $blocks);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sstmt = $pdo->prepare(
        "SELECT id, first_name, last_name, username, avatar, block_id FROM users
         WHERE role = 'student' AND block_id IN ({$in})
         ORDER BY last_name, first_name"
    );
    $sstmt->execute($ids);
    foreach ($sstmt->fetchAll() as $s) {
        $studByBlock[(int)$s['block_id']][] = $s;
    }
}

$teachers = $pdo->query(
    'SELECT id, first_name, last_name, username FROM users
     WHERE role="teacher" AND COALESCE(is_active,1)=1 ORDER BY last_name'
)->fetchAll();

$counts = [
    'teachers' => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role="teacher"')->fetchColumn(),
    'students' => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role="student"')->fetchColumn(),
    'blocks'   => $totalBlocks,
];

render_header('Blocks', $user);
render_flash();

$adminMe = $pdo->prepare('SELECT avatar, first_name, last_name FROM users WHERE id = :id');
$adminMe->execute([':id' => (int)$user['id']]);
$adminMe = $adminMe->fetch() ?: $user;
$_SESSION['user']['avatar'] = $adminMe['avatar'] ?? null;
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Blocks</h1>
    <p class="page-desc">Each block is an optional section group tied to a college and degree program. Subject teachers grade via offerings, not the block adviser alone.</p>
  </div>
</div>

<div class="card profile-hero" data-testid="admin-pfp-card">
  <div class="profile-pfp">
    <?= avatar_img_tag($adminMe['avatar'] ?? null, ($adminMe['first_name'] ?? '') . ' ' . ($adminMe['last_name'] ?? ''), 'avatar avatar--md') ?>
    <div class="profile-pfp-meta">
      <h3>Your profile picture</h3>
      <p class="page-desc" style="margin:0">JPG, PNG, or WebP · max 2&nbsp;MB</p>
      <form method="post" action="actions.php" enctype="multipart/form-data" class="pfp-form" data-testid="admin-avatar-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_avatar">
        <input class="input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required>
        <div class="actions">
          <button class="btn" type="submit">Upload picture</button>
        </div>
      </form>
      <?php if (!empty($adminMe['avatar'])): ?>
      <form method="post" action="actions.php" style="margin-top:8px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="remove_avatar">
        <button class="btn-danger" type="submit">Remove picture</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="stat-row">
  <div class="stat"><div class="num" data-testid="stat-blocks"><?= $counts['blocks'] ?></div><div class="lbl">Blocks</div></div>
  <div class="stat"><div class="num" data-testid="stat-teachers"><?= $counts['teachers'] ?></div><div class="lbl">Teachers</div></div>
  <div class="stat"><div class="num" data-testid="stat-students"><?= $counts['students'] ?></div><div class="lbl">Students</div></div>
</div>

<div class="card" data-testid="search-blocks-card">
  <h3>Search blocks</h3>
  <form method="get" action="index.php" class="search-bar">
    <div class="field grow">
      <label for="block-q">Keyword</label>
      <input class="input" id="block-q" name="q" value="<?= e($q) ?>"
             placeholder="Block, teacher, college, or program…" data-testid="block-search">
    </div>
    <div class="field actions-inline">
      <button class="btn" type="submit" data-testid="block-search-submit">Search</button>
      <a class="btn-out" href="index.php">Clear</a>
    </div>
  </form>
  <p class="help" style="margin-top:10px">
    <?php if ($q === ''): ?>
      Showing <?= count($blocks) ?> of <?= (int)$totalBlocks ?> blocks. Search by department or course to find other blocks.
    <?php else: ?>
      Found <?= (int)$matchCount ?> block<?= $matchCount === 1 ? '' : 's' ?> matching “<?= e($q) ?>”.
    <?php endif; ?>
  </p>
</div>

<div class="card" data-testid="add-block-card">
  <h3>Create a block</h3>
  <form method="post" action="actions.php" class="form-grid" id="create-block-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_block">
    <div class="field">
      <label>Block name</label>
      <input class="input" name="name" placeholder="e.g. BSIT-Block1" data-testid="block-name" required>
    </div>
    <div class="field">
      <label>Assign teacher</label>
      <select class="input" name="teacher_id" data-testid="block-teacher" required>
        <option value="">— select teacher —</option>
        <?php foreach ($teachers as $t): ?>
          <option value="<?= $t['id'] ?>"><?= e($t['first_name'].' '.$t['last_name'].' ('.$t['username'].')') ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>College</label>
      <select class="input" name="department_id" id="block-dept" data-testid="block-dept" required>
        <option value="">— select college —</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= (int)$d['id'] ?>"><?= e($d['code'] . ' — ' . $d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Program</label>
      <select class="input" name="course_id" id="block-course" data-testid="block-course" required>
        <option value="">— select program —</option>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" data-dept="<?= (int)$c['department_id'] ?>">
            <?= e($c['department_code'] . ' · ' . $c['code'] . ' — ' . $c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="actions"><button class="btn" type="submit" data-testid="block-submit">Create block</button></div>
  </form>
</div>

<?php foreach ($blocks as $b): $students = $studByBlock[$b['id']] ?? []; ?>
  <div class="card" data-testid="block-card-<?= $b['id'] ?>">
    <h3 class="block-card-title">
      <span><?= e($b['name']) ?></span>
      <span class="tag tag--academic" title="<?= e(($b['department_name'] ?? '') . ' / ' . ($b['course_name'] ?? '')) ?>">
        <?= e(block_academic_label($b)) ?>
      </span>
      <?php if ($b['teacher_name']): ?>
        <span class="block-teacher person-cell">
          <?= avatar_img_tag($b['teacher_avatar'] ?? null, $b['teacher_name'], 'avatar avatar--sm') ?>
          <span><?= e($b['teacher_name']) ?> <span class="mono">(<?= e($b['teacher_username']) ?>)</span></span>
        </span>
      <?php else: ?>
        <span class="block-teacher" style="color:var(--warn)">· no teacher assigned</span>
      <?php endif; ?>
      <span class="help"> · <?= count($students) ?> student<?= count($students) === 1 ? '' : 's' ?></span>
    </h3>
    <form method="post" action="actions.php" class="block-academics-form search-bar" data-testid="block-academics-<?= (int)$b['id'] ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_block_academics">
      <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
      <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
      <div class="field">
        <label>College</label>
        <select class="input block-dept-select" name="department_id" required>
          <option value="">— select —</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= (int)$d['id'] ?>" <?= (int)($b['department_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>>
              <?= e($d['code'] . ' — ' . $d['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field grow">
        <label>Program</label>
        <select class="input block-course-select" name="course_id" required>
          <option value="">— select —</option>
          <?php foreach ($courses as $c): ?>
            <option value="<?= (int)$c['id'] ?>" data-dept="<?= (int)$c['department_id'] ?>"
              <?= (int)($b['course_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
              <?= e($c['department_code'] . ' · ' . $c['code'] . ' — ' . $c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field actions-inline">
        <button class="btn-out" type="submit">Save program</button>
      </div>
    </form>
    <?php if ($students): ?>
      <div class="table-wrap">
      <table class="table table--aligned table--simple">
        <thead>
          <tr>
            <th class="col-person">Student</th>
            <th class="col-user">Username</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($students as $s):
          $fullName = $s['first_name'].' '.$s['last_name'];
        ?>
          <tr>
            <td class="col-person">
              <div class="person-cell">
                <?= avatar_img_tag($s['avatar'] ?? null, $fullName, 'avatar avatar--sm') ?>
                <span class="person-name"><?= e($fullName) ?></span>
              </div>
            </td>
            <td class="col-user mono"><?= e($s['username']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php else: ?>
      <p class="empty">No students in this block yet.</p>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php if (!$blocks): ?>
  <p class="empty"><?= $q !== '' ? 'No blocks match your search.' : 'No blocks yet — create one above.' ?></p>
<?php endif; ?>
<script>
(function () {
  function filterCourses(deptSelect, courseSelect) {
    var dept = deptSelect.value;
    var options = courseSelect.querySelectorAll('option[data-dept]');
    var keep = courseSelect.value;
    options.forEach(function (opt) {
      var show = !dept || opt.getAttribute('data-dept') === dept;
      opt.hidden = !show;
      opt.disabled = !show;
    });
    var selected = courseSelect.options[courseSelect.selectedIndex];
    if (selected && selected.disabled) {
      courseSelect.value = '';
    }
    if (keep) {
      var still = courseSelect.querySelector('option[value="' + keep + '"]:not([disabled])');
      if (still) courseSelect.value = keep;
    }
  }
  document.querySelectorAll('.block-academics-form, #create-block-form').forEach(function (form) {
    var dept = form.querySelector('select[name="department_id"]');
    var course = form.querySelector('select[name="course_id"]');
    if (!dept || !course) return;
    filterCourses(dept, course);
    dept.addEventListener('change', function () { filterCourses(dept, course); });
  });
})();
</script>
<?php render_footer(); ?>
