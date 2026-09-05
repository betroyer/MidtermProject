<?php
/**
 * teacher/index.php — roster from subject enrollments, grouped by student block.
 * Typical case: one subject taught across several blocks (sections).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('teacher', 'students');
$pdo = db();
ensure_academics_schema($pdo);
$tid = (int)$user['id'];
$canGrade = role_can('teacher', 'grades');

$q = trim($_GET['q'] ?? '');
$offeringFilter = (int)($_GET['offering_id'] ?? 0);
$blockFilter = (int)($_GET['block_id'] ?? 0);

$offerings = offerings_list($tid, true);
$offeringIds = array_map('intval', array_column($offerings, 'id'));

// Blocks that appear among this teacher's enrolled students
$blocksForTeacher = [];
if ($offeringIds) {
    $in = implode(',', $offeringIds);
    $blocksForTeacher = $pdo->query(
        "SELECT DISTINCT b.id, b.name,
                d.code AS department_code, c.code AS course_code, c.name AS course_name
         FROM enrollments e
         JOIN subject_offerings o ON o.id = e.offering_id
         JOIN users u ON u.id = e.student_id
         LEFT JOIN blocks b ON b.id = u.block_id
         LEFT JOIN departments d ON d.id = b.department_id
         LEFT JOIN courses c ON c.id = b.course_id
         WHERE o.teacher_id = {$tid} AND o.is_active = 1 AND b.id IS NOT NULL
         ORDER BY b.name"
    )->fetchAll();
}
$blockIds = array_map('intval', array_column($blocksForTeacher, 'id'));

$students = [];
if ($offeringIds) {
    $where = ['u.role = "student"', 'o.teacher_id = :tid', 'o.is_active = 1'];
    $params = [':tid' => $tid];

    if ($q !== '') {
        $where[] = '(u.username LIKE :q OR u.first_name LIKE :q2 OR u.last_name LIKE :q3
                    OR u.email LIKE :q4 OR u.phone LIKE :q5
                    OR CONCAT(u.first_name, " ", u.last_name) LIKE :q6 OR b.name LIKE :q7
                    OR s.code LIKE :q8 OR s.name LIKE :q9 OR o.name LIKE :q10
                    OR pc.code LIKE :q11 OR pc.name LIKE :q12)';
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
    if ($offeringFilter > 0 && in_array($offeringFilter, $offeringIds, true)) {
        $where[] = 'e.offering_id = :oid';
        $params[':oid'] = $offeringFilter;
    }
    if ($blockFilter > 0 && in_array($blockFilter, $blockIds, true)) {
        $where[] = 'u.block_id = :bid';
        $params[':bid'] = $blockFilter;
    }

    $sql = 'SELECT DISTINCT u.id, u.username, u.first_name, u.last_name, u.email, u.phone, u.age, u.avatar,
                   u.block_id, b.name AS block_name,
                   d.code AS department_code, c.code AS course_code, c.name AS course_name,
                   pc.code AS program_code, pc.name AS program_name,
                   pd.code AS program_dept_code,
                   (SELECT COUNT(*) FROM grades g
                    JOIN subject_offerings go ON go.id = g.offering_id
                    WHERE g.student_id = u.id AND go.teacher_id = :tid2) AS subjects
            FROM enrollments e
            JOIN subject_offerings o ON o.id = e.offering_id
            JOIN subjects s ON s.id = o.subject_id
            JOIN users u ON u.id = e.student_id
            LEFT JOIN blocks b ON b.id = u.block_id
            LEFT JOIN departments d ON d.id = b.department_id
            LEFT JOIN courses c ON c.id = b.course_id
            LEFT JOIN courses pc ON pc.id = u.program_id
            LEFT JOIN departments pd ON pd.id = pc.department_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY b.name IS NULL, b.name, u.last_name, u.first_name';
    $params[':tid2'] = $tid;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();
}

$studByBlock = [];
foreach ($students as $s) {
    $key = $s['block_name'] ?: 'Unassigned';
    if (!isset($studByBlock[$key])) {
        $studByBlock[$key] = ['meta' => $s, 'rows' => []];
    }
    $studByBlock[$key]['rows'][] = $s;
}

$subjectSummary = '';
if (count($offerings) === 1) {
    $o0 = $offerings[0];
    $subjectSummary = $o0['subject_code'] . ' — ' . $o0['subject_name'];
} elseif (count($offerings) > 1) {
    $codes = array_unique(array_column($offerings, 'subject_code'));
    $subjectSummary = count($codes) === 1
        ? $offerings[0]['subject_code'] . ' — ' . $offerings[0]['subject_name'] . ' (' . count($offerings) . ' sections)'
        : count($offerings) . ' offerings';
}

$defaultOfferingId = 0;
if ($offeringFilter > 0) {
    $defaultOfferingId = $offeringFilter;
} elseif (count($offerings) === 1) {
    $defaultOfferingId = (int)$offerings[0]['id'];
}

$me = $pdo->prepare('SELECT avatar, first_name, last_name FROM users WHERE id = :id');
$me->execute([':id' => $tid]);
$me = $me->fetch() ?: $user;
$_SESSION['user']['avatar'] = $me['avatar'] ?? null;

render_header('My Students', $user);
render_flash();
?>
<div class="page-head">
  <div>
    <h1 class="page-title">My Students</h1>
    <p class="page-desc">
      Students enrolled in your subject<?= $subjectSummary !== '' ? ' (' . e($subjectSummary) . ')' : '' ?>,
      listed by block. Filter by block or offering when you teach more than one section.
    </p>
  </div>
</div>

<div class="card profile-hero" data-testid="teacher-pfp-card">
  <div class="profile-pfp">
    <?= avatar_img_tag($me['avatar'] ?? null, ($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''), 'avatar avatar--md') ?>
    <div class="profile-pfp-meta">
      <h3>Your profile picture</h3>
      <p class="page-desc" style="margin:0">Shown in admin teacher lists. JPG, PNG, or WebP · max 2&nbsp;MB</p>
      <form method="post" action="actions.php" enctype="multipart/form-data" class="pfp-form" data-testid="teacher-avatar-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_avatar">
        <input class="input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required>
        <div class="actions">
          <button class="btn" type="submit">Upload picture</button>
        </div>
      </form>
      <?php if (!empty($me['avatar'])): ?>
      <form method="post" action="actions.php" style="margin-top:8px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="remove_avatar">
        <button class="btn-danger" type="submit">Remove picture</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (!$offerings): ?>
  <p class="empty">You have no subject offerings yet. Ask the admin to assign you an offering under Colleges &amp; Programs → Offerings.</p>
<?php else: ?>
<div class="card" data-testid="search-teacher-students">
  <h3>Search enrolled students</h3>
  <form method="get" class="search-bar">
    <div class="field grow">
      <label for="tq">Keyword</label>
      <input class="input" id="tq" name="q" value="<?= e($q) ?>"
             placeholder="Name, username, block, program…" data-testid="teacher-student-search">
    </div>
    <div class="field">
      <label for="tblock">Block</label>
      <select class="input" id="tblock" name="block_id" data-testid="teacher-block-filter">
        <option value="0">All blocks</option>
        <?php foreach ($blocksForTeacher as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= $blockFilter === (int)$b['id'] ? 'selected' : '' ?>>
            <?= e($b['name'] . ' · ' . block_academic_label($b)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="toff">Subject / offering</label>
      <select class="input" id="toff" name="offering_id">
        <option value="0">All my offerings</option>
        <?php foreach ($offerings as $o): ?>
          <option value="<?= (int)$o['id'] ?>" <?= $offeringFilter === (int)$o['id'] ? 'selected' : '' ?>>
            <?= e($o['subject_code'] . ' — ' . $o['subject_name'] . ($o['name'] !== '' ? ' · ' . $o['name'] : '')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field actions-inline">
      <button class="btn" type="submit">Search</button>
      <a class="btn-out" href="index.php">Clear</a>
    </div>
  </form>
</div>

<div class="stat-row">
  <div class="stat"><div class="num"><?= count($students) ?></div><div class="lbl">Students</div></div>
  <div class="stat"><div class="num"><?= count($studByBlock) ?></div><div class="lbl">Blocks</div></div>
  <div class="stat"><div class="num"><?= count($offerings) ?></div><div class="lbl">Offerings</div></div>
</div>

<div class="card">
  <h3>Enrolled students by block (<?= count($students) ?>)</h3>
  <?php if ($students): ?>
  <div class="table-wrap">
    <table class="table table--aligned table--list" data-testid="teacher-students-table">
      <colgroup>
        <col class="col-person">
        <col class="col-user">
        <col class="col-email">
        <col class="col-phone">
        <col class="col-age">
        <col class="col-blocks">
        <col class="col-actions">
      </colgroup>
      <thead>
        <tr>
          <th>Name</th>
          <th>Username</th>
          <th>Email</th>
          <th>Phone</th>
          <th class="col-num">Age</th>
          <th>Program</th>
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
            $fullName = $s['first_name'] . ' ' . $s['last_name'];
            $gradeHref = 'grade.php?student_id=' . (int)$s['id'];
            if ($defaultOfferingId > 0) {
                $gradeHref .= '&offering_id=' . $defaultOfferingId;
            }
        ?>
        <tr data-testid="student-row-<?= (int)$s['id'] ?>">
          <td class="col-person">
            <div class="person-cell">
              <?= avatar_img_tag($s['avatar'] ?? null, $fullName, 'avatar avatar--sm') ?>
              <span class="person-name"><?= e($fullName) ?></span>
            </div>
          </td>
          <td class="col-user mono"><?= e($s['username']) ?></td>
          <td class="col-email"><?= e($s['email'] ?: '—') ?></td>
          <td class="col-phone mono"><?= e($s['phone'] ?: '—') ?></td>
          <td class="col-age col-num"><?= e($s['age'] ?: '—') ?></td>
          <td class="col-blocks"><?= e(program_label([
              'department_code' => $s['program_dept_code'] ?? $s['department_code'] ?? '',
              'program_code' => $s['program_code'] ?? '',
              'program_name' => $s['program_name'] ?? '',
          ])) ?></td>
          <td class="col-actions">
            <div class="row-actions">
              <a class="btn-out" href="student_view.php?id=<?= (int)$s['id'] ?>">View</a>
              <?php if ($canGrade): ?>
                <a class="btn" href="<?= e($gradeHref) ?>">Grade</a>
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
    <p class="empty"><?= $q !== '' || $offeringFilter > 0 || $blockFilter > 0 ? 'No students match your filters.' : 'No students enrolled in your offerings yet.' ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php render_footer(); ?>
