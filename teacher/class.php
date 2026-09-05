<?php
/**
 * teacher/class.php — Class module: students enrolled in teacher's subject(s), by block.
 * View opens in a modal. Blocks listed are those with students in your offerings only.
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
$uniqueSubjects = array_values(array_unique(array_column($offerings, 'subject_code')));
$singleSubject = count($uniqueSubjects) === 1;

// Subject-scoped blocks only (e.g. Programming teacher → blocks with that subject)
$blocksForFilter = teacher_enrollment_blocks($pdo, $tid, $offeringIds);
$blockIds = array_map('intval', array_column($blocksForFilter, 'id'));

$students = [];
if ($offeringIds) {
    $where = ['u.role = "student"', 'o.teacher_id = :tid', 'o.is_active = 1'];
    $params = [':tid' => $tid];

    if ($q !== '') {
        $where[] = '(u.username LIKE :q OR u.first_name LIKE :q2 OR u.last_name LIKE :q3
                    OR u.email LIKE :q4 OR u.phone LIKE :q5
                    OR CONCAT(u.first_name, " ", u.last_name) LIKE :q6 OR b.name LIKE :q7
                    OR s.code LIKE :q8 OR s.name LIKE :q9 OR o.name LIKE :q10
                    OR pc.code LIKE :q11 OR pc.name LIKE :q12
                    OR pd.code LIKE :q13)';
        $like = '%' . $q . '%';
        foreach ([':q', ':q2', ':q3', ':q4', ':q5', ':q6', ':q7', ':q8', ':q9', ':q10', ':q11', ':q12', ':q13'] as $k) {
            $params[$k] = $like;
        }
    }
    if ($offeringFilter > 0 && in_array($offeringFilter, $offeringIds, true)) {
        $where[] = 'e.offering_id = :oid';
        $params[':oid'] = $offeringFilter;
    }
    if ($blockFilter > 0 && in_array($blockFilter, $blockIds, true)) {
        $where[] = 'u.block_id = :bid';
        $params[':bid'] = $blockFilter;
    }

    $sql = 'SELECT DISTINCT u.id, u.username, u.first_name, u.last_name, u.middle_initial, u.email, u.phone, u.age, u.avatar,
                   u.block_id, b.name AS block_name,
                   d.code AS department_code, c.code AS course_code, c.name AS course_name,
                   pc.code AS program_code, pc.name AS program_name,
                   pd.code AS program_dept_code
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
if ($singleSubject && $offerings) {
    $o0 = $offerings[0];
    $subjectSummary = $o0['subject_code'] . ' — ' . $o0['subject_name'];
    if (count($offerings) > 1) {
        $subjectSummary .= ' (' . count($offerings) . ' sections)';
    }
} elseif (count($offerings) > 1) {
    $subjectSummary = count($offerings) . ' offerings';
}

$defaultOfferingId = 0;
if ($offeringFilter > 0) {
    $defaultOfferingId = $offeringFilter;
} elseif (count($offerings) === 1) {
    $defaultOfferingId = (int)$offerings[0]['id'];
}

render_header('Class', $user);
$modalCss = @filemtime(__DIR__ . '/../css/modal.css') ?: time();
$modalJs = @filemtime(__DIR__ . '/../js/ui-modal.js') ?: time();
echo '<link rel="stylesheet" href="../css/modal.css?v=' . (int)$modalCss . '">';
echo '<script src="../js/ui-modal.js?v=' . (int)$modalJs . '" defer></script>';
render_flash();
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Class</h1>
    <p class="page-desc">
      Students enrolled in your subject<?= $subjectSummary !== '' ? ' (<strong>' . e($subjectSummary) . '</strong>)' : '' ?>,
      grouped by block. Only blocks with your subject appear here
      (<?= count($blocksForFilter) ?> block<?= count($blocksForFilter) === 1 ? '' : 's' ?>).
    </p>
  </div>
</div>

<?php if (!$offerings): ?>
  <p class="empty">You have no subject offerings yet. Ask the admin to assign you an offering under Colleges &amp; Programs → Offerings.</p>
<?php else: ?>
<div class="card" data-testid="search-class-students">
  <h3>Search enrolled students</h3>
  <form method="get" action="class.php" class="search-bar">
    <div class="field grow">
      <label for="tq">Keyword</label>
      <input class="input" id="tq" name="q" value="<?= e($q) ?>"
             placeholder="Name, username, block, program…" data-testid="class-student-search">
    </div>
    <div class="field">
      <label for="tblock">Block (your subject)</label>
      <select class="input" id="tblock" name="block_id" data-testid="class-block-filter">
        <option value="0">All blocks</option>
        <?php foreach ($blocksForFilter as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= $blockFilter === (int)$b['id'] ? 'selected' : '' ?>>
            <?= e($b['name'] . ' · ' . block_academic_label($b) . ' (' . (int)$b['student_count'] . ')') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if (!$singleSubject && count($offerings) > 1): ?>
    <div class="field">
      <label for="toff">Subject / offering</label>
      <select class="input" id="toff" name="offering_id" data-testid="class-offering-filter">
        <option value="0">All my offerings</option>
        <?php foreach ($offerings as $o): ?>
          <option value="<?= (int)$o['id'] ?>" <?= $offeringFilter === (int)$o['id'] ? 'selected' : '' ?>>
            <?= e($o['subject_code'] . ' — ' . $o['subject_name'] . ($o['name'] !== '' ? ' · ' . $o['name'] : '')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="field actions-inline">
      <button class="btn" type="submit">Search</button>
      <a class="btn-out" href="class.php">Clear</a>
    </div>
  </form>
</div>

<div class="stat-row">
  <div class="stat"><div class="num"><?= count($students) ?></div><div class="lbl">Students</div></div>
  <div class="stat"><div class="num"><?= count($studByBlock) ?></div><div class="lbl">Blocks shown</div></div>
  <div class="stat"><div class="num"><?= count($blocksForFilter) ?></div><div class="lbl">Subject blocks</div></div>
  <div class="stat"><div class="num"><?= count($offerings) ?></div><div class="lbl">Offerings</div></div>
</div>

<div class="card">
  <h3>Enrolled students by block (<?= count($students) ?>)</h3>
  <?php if ($students): ?>
  <div class="table-wrap">
    <table class="table table--aligned table--list" data-testid="class-students-table">
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
            $fullName = format_person_name($s);
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
              <button type="button" class="btn-out"
                      data-open-student-view="<?= (int)$s['id'] ?>"
                      data-student-name="<?= e($fullName) ?>"
                      data-testid="view-student-<?= (int)$s['id'] ?>">View</button>
              <?php if ($canGrade): ?>
                <button type="button" class="btn"
                        data-open-grade="<?= (int)$s['id'] ?>"
                        data-student-name="<?= e($fullName) ?>"
                        data-offering-id="<?= (int)$defaultOfferingId ?>"
                        data-testid="grade-student-<?= (int)$s['id'] ?>">Grade</button>
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
    <p class="empty"><?= $q !== '' || $offeringFilter > 0 || $blockFilter > 0 ? 'No students match your filters.' : 'No students enrolled in your subject offerings yet.' ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.UIModal) return;

  function openGrade(studentId, studentName, offeringId) {
    var url = 'grade_fragment.php?student_id=' + encodeURIComponent(studentId);
    if (offeringId && offeringId !== '0') {
      url += '&offering_id=' + encodeURIComponent(offeringId);
    }
    UIModal.open({
      title: 'Grade computation',
      subtitle: studentName || '',
      wide: true,
      url: url,
      onOpen: function (body) {
        var sel = body.querySelector('[data-reload-grade-offering]');
        if (!sel) return;
        sel.addEventListener('change', function () {
          openGrade(sel.getAttribute('data-reload-grade-offering'), studentName, sel.value);
        });
      }
    });
  }

  document.addEventListener('click', function (e) {
    var viewBtn = e.target.closest('[data-open-student-view]');
    if (viewBtn) {
      UIModal.open({
        title: 'Student profile',
        subtitle: viewBtn.getAttribute('data-student-name') || '',
        wide: true,
        url: 'student_fragment.php?id=' + encodeURIComponent(viewBtn.getAttribute('data-open-student-view'))
      });
      return;
    }
    var gradeBtn = e.target.closest('[data-open-grade]');
    if (gradeBtn) {
      openGrade(
        gradeBtn.getAttribute('data-open-grade'),
        gradeBtn.getAttribute('data-student-name'),
        gradeBtn.getAttribute('data-offering-id') || '0'
      );
    }
  });

  <?php
  $openGradeId = (int)($_GET['open_grade'] ?? 0);
  $openOffering = (int)($_GET['offering_id'] ?? 0);
  if ($openGradeId > 0):
      $ogName = '';
      foreach ($students as $s) {
          if ((int)$s['id'] === $openGradeId) {
              $ogName = format_person_name($s);
              break;
          }
      }
  ?>
  openGrade(<?= (int)$openGradeId ?>, <?= json_encode($ogName) ?>, <?= (int)$openOffering ?>);
  <?php endif; ?>
});
</script>
<?php render_footer(); ?>
