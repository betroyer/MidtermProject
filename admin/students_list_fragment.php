<?php
/**
 * admin/students_list_fragment.php — authorized student roster for modal.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('admin', 'students');
$pdo = db();
ensure_user_active_column();
ensure_user_avatar_column();
ensure_academics_schema($pdo);

$q = trim($_GET['q'] ?? '');
$blockFilter = (int)($_GET['block_id'] ?? 0);
$deptFilter = (int)($_GET['department_id'] ?? 0);
$locked = app_setting('records_locked', '0') === '1';

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

$where = ['u.role = "student"'];
$params = [];
if ($q !== '') {
    $where[] = '(u.username LIKE :q OR u.first_name LIKE :q2 OR u.last_name LIKE :q3 OR u.email LIKE :q4 OR u.phone LIKE :q5
                OR CONCAT(u.first_name, " ", u.last_name) LIKE :q6 OR b.name LIKE :q7
                OR d.code LIKE :q8 OR c.code LIKE :q9 OR c.name LIKE :q10
                OR pd.code LIKE :q11 OR pc.code LIKE :q12 OR pc.name LIKE :q13)';
    $like = '%' . $q . '%';
    foreach ([':q', ':q2', ':q3', ':q4', ':q5', ':q6', ':q7', ':q8', ':q9', ':q10', ':q11', ':q12', ':q13'] as $k) {
        $params[$k] = $like;
    }
}
if ($blockFilter > 0) {
    $where[] = 'u.block_id = :bid';
    $params[':bid'] = $blockFilter;
}
if ($deptFilter > 0) {
    $where[] = 'b.department_id = :did';
    $params[':did'] = $deptFilter;
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

$filterBits = [];
if ($q !== '') {
    $filterBits[] = 'keyword “' . $q . '”';
}
if ($deptFilter > 0) {
    $filterBits[] = 'department filter';
}
if ($blockFilter > 0) {
    $filterBits[] = 'block filter';
}
?>
<?php if ($filterBits): ?>
  <p class="help" style="margin:0 0 12px">Showing results for <?= e(implode(' · ', $filterBits)) ?>.</p>
<?php endif; ?>

<?php if ($students): ?>
<div class="table-wrap">
  <table class="table table--aligned table--list" data-testid="students-table-modal">
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
          $fullName = format_person_name($s);
      ?>
        <tr data-testid="student-row-<?= (int)$s['id'] ?>" class="<?= $active ? '' : 'row-inactive' ?>">
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
              <button type="button" class="btn-out" data-open-student-view="<?= (int)$s['id'] ?>"
                      data-student-name="<?= e($fullName) ?>" data-testid="view-student-<?= (int)$s['id'] ?>">View</button>
              <?php if (!$locked): ?>
                <a class="btn-out" href="student_view.php?id=<?= (int)$s['id'] ?>&mode=edit" data-testid="edit-student-<?= (int)$s['id'] ?>">Update</a>
                <form method="post" action="actions.php">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="set_user_active">
                  <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                  <input type="hidden" name="is_active" value="<?= $active ? '0' : '1' ?>">
                  <input type="hidden" name="back" value="students.php">
                  <button class="<?= $active ? 'btn-danger' : 'btn-out' ?>" type="submit"
                          data-testid="<?= $active ? 'deactivate' : 'activate' ?>-student-<?= (int)$s['id'] ?>"
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
  <p class="empty"><?= $q !== '' || $blockFilter > 0 || $deptFilter > 0 ? 'No students match your search.' : 'No students yet.' ?></p>
<?php endif; ?>

<div data-modal-footer>
  <button type="button" class="btn-out" data-ui-modal-dismiss>Close</button>
</div>
