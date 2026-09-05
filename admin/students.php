<?php
/** admin/students.php — search, add, and manage authorized student records. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('admin', 'students');
$pdo = db();
ensure_user_active_column();
ensure_user_avatar_column();
ensure_academics_schema($pdo);
ensure_curriculum_schema($pdo);

$q = trim($_GET['q'] ?? '');
$blockFilter = (int)($_GET['block_id'] ?? 0);
$deptFilter = (int)($_GET['department_id'] ?? 0);
$locked = app_setting('records_locked', '0') === '1';
$nextSchoolIdPreview = function_exists('generate_next_school_id')
    ? generate_next_school_id($pdo)
    : '';

$departments = departments_list(true);
$blocks = $pdo->query(
    'SELECT b.id, b.name, b.department_id, d.code AS department_code, c.code AS course_code, c.name AS course_name
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
if ($deptFilter > 0) {
    $where[] = 'b.department_id = :did';
    $params[':did'] = $deptFilter;
}

$sql = 'SELECT COUNT(*) FROM users u
        LEFT JOIN blocks b ON b.id = u.block_id
        LEFT JOIN departments d ON d.id = b.department_id
        LEFT JOIN courses c ON c.id = b.course_id
        LEFT JOIN courses pc ON pc.id = u.program_id
        LEFT JOIN departments pd ON pd.id = pc.department_id
        WHERE ' . implode(' AND ', $where);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$studentCount = (int)$stmt->fetchColumn();

$blockCountSql = 'SELECT COUNT(DISTINCT COALESCE(u.block_id, 0)) FROM users u
        LEFT JOIN blocks b ON b.id = u.block_id
        LEFT JOIN departments d ON d.id = b.department_id
        LEFT JOIN courses c ON c.id = b.course_id
        LEFT JOIN courses pc ON pc.id = u.program_id
        LEFT JOIN departments pd ON pd.id = pc.department_id
        WHERE ' . implode(' AND ', $where);
$bc = $pdo->prepare($blockCountSql);
$bc->execute($params);
$blockGroupCount = (int)$bc->fetchColumn();

$listQuery = [];
if ($q !== '') {
    $listQuery['q'] = $q;
}
if ($blockFilter > 0) {
    $listQuery['block_id'] = $blockFilter;
}
if ($deptFilter > 0) {
    $listQuery['department_id'] = $deptFilter;
}
$listQs = http_build_query($listQuery);

render_header('Students', $user);
$modalCss = @filemtime(__DIR__ . '/../css/modal.css') ?: time();
$modalJs = @filemtime(__DIR__ . '/../js/ui-modal.js') ?: time();
echo '<link rel="stylesheet" href="../css/modal.css?v=' . (int)$modalCss . '">';
echo '<script src="../js/ui-modal.js?v=' . (int)$modalJs . '" defer></script>';
render_flash();
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Students</h1>
    <p class="page-desc">Enroll students and search records. Open the roster in a dialog so this page stays compact.</p>
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
      <label for="student-dept">Department</label>
      <select class="input" id="student-dept" name="department_id" data-testid="student-dept-filter">
        <option value="0">All departments</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= $deptFilter === (int)$d['id'] ? 'selected' : '' ?>>
            <?= e(department_option_label($d)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="student-block">Block</label>
      <select class="input" id="student-block" name="block_id" data-testid="student-block-filter">
        <option value="0">All blocks</option>
        <?php foreach ($blocks as $b): ?>
          <?php if ($deptFilter > 0 && (int)($b['department_id'] ?? 0) !== $deptFilter) continue; ?>
          <option value="<?= (int)$b['id'] ?>" <?= $blockFilter === (int)$b['id'] ? 'selected' : '' ?>>
            <?= e(block_option_label($b)) ?>
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
  <h3>Enroll student</h3>
  <p class="page-desc" style="margin:0 0 14px">Enter student details and emergency contact. Block is assigned automatically (max <?= (int)block_student_capacity() ?> students per block).</p>
  <?php if (!$programs): ?>
    <p class="empty">Create a degree program first before adding students.</p>
  <?php else: ?>
  <form method="post" action="actions.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_student">
    <div class="form-grid">
      <div class="field"><label>Last name</label>
        <input class="input" name="last_name" data-testid="s-last" required>
        <div class="help">Letters only.</div></div>
      <div class="field"><label>First name</label>
        <input class="input" name="first_name" data-testid="s-first" required>
        <div class="help">Letters only.</div></div>
      <div class="field"><label>M.I.</label>
        <input class="input" name="middle_initial" maxlength="5" placeholder="e.g. A" data-testid="s-mi">
        <div class="help">Optional middle initial.</div></div>
      <div class="field"><label>Age</label>
        <input class="input" name="age" inputmode="numeric" data-testid="s-age" required>
        <div class="help">Whole number.</div></div>
      <div class="field" style="grid-column:1/-1"><label>Address</label>
        <input class="input" name="address" placeholder="House / street / barangay / city" data-testid="s-address" required>
      </div>
      <div class="field"><label>Email</label>
        <input class="input" name="email" type="email" placeholder="name@gmail.com" data-testid="s-email" required>
        <div class="help">Must be a @gmail.com account.</div></div>
      <div class="field"><label>Phone number</label>
        <input class="input" name="phone" inputmode="numeric" placeholder="09xxxxxxxxx" data-testid="s-phone" required>
        <div class="help">Exactly 11 digits.</div></div>
      <div class="field"><label>School ID (password)</label>
        <input class="input" name="school_id" id="s-schoolid" placeholder="2026-00101" data-testid="s-schoolid" required>
        <div class="help" id="s-schoolid-help">Format 2026-00101 — also used as login password.</div></div>
      <div class="field"><label>Department</label>
        <select class="input" id="s-dept" data-testid="s-dept" required>
          <option value="">— select department —</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= (int)$d['id'] ?>"><?= e(department_option_label($d)) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="help">CICT, CBGG, CTE, CCJE, CAF, etc.</div>
      </div>
      <div class="field"><label>Degree program</label>
        <select class="input" name="program_id" id="s-program" data-testid="s-program" required>
          <option value="">— select program —</option>
          <?php foreach ($programs as $p): ?>
            <option value="<?= (int)$p['id'] ?>" data-dept="<?= (int)$p['department_id'] ?>">
              <?= e($p['code'] . ' — ' . $p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="help">Block is auto-assigned for this program (max <?= (int)block_student_capacity() ?> / block).</div>
      </div>
      <div class="field"><label>Year level</label>
        <select class="input" name="year_level" id="s-year" data-testid="s-year" required>
          <option value="">— select year —</option>
          <?php foreach (year_level_options() as $y => $label): ?>
            <option value="<?= (int)$y ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="help">1st year auto-assigns School ID + password. 1st/2nd = 8 subjects (4 days). 3rd/4th = 3 subjects (3 days).</div>
      </div>
    </div>

    <h3 style="margin:22px 0 12px;font-size:15px">Emergency contact</h3>
    <div class="form-grid">
      <div class="field"><label>Contact name</label>
        <input class="input" name="emergency_name" data-testid="s-em-name" required>
        <div class="help">Letters only.</div></div>
      <div class="field"><label>Relationship</label>
        <select class="input" name="emergency_relation" data-testid="s-em-relation" required>
          <option value="">— select —</option>
          <?php foreach (emergency_relation_options() as $code => $label): ?>
            <option value="<?= e($code) ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field" style="grid-column:1/-1"><label>Contact address</label>
        <input class="input" name="emergency_address" data-testid="s-em-address" required>
      </div>
      <div class="field"><label>Contact phone</label>
        <input class="input" name="emergency_phone" inputmode="numeric" placeholder="09xxxxxxxxx" data-testid="s-em-phone" required>
        <div class="help">Exactly 11 digits.</div></div>
    </div>
    <p class="help" style="margin:12px 0 0">After enroll, year-level subjects (major/minor) and a class schedule (7:00 AM–8:30 PM) are applied automatically. The student can open <strong>My Schedule</strong>.</p>
    <div class="actions"><button class="btn" type="submit" data-testid="s-submit">Enroll student</button></div>
  </form>
  <?php endif; ?>
</div>
<?php else: ?>
  <div class="flash flash--error">Records are locked in System Settings. You can still search and view authorized student information.</div>
<?php endif; ?>

<div class="card" data-testid="authorized-students-summary">
  <h3>Authorized student information</h3>
  <p class="help" style="margin:0 0 14px">
    <?= $studentCount ?> student<?= $studentCount === 1 ? '' : 's' ?>
    across <?= $blockGroupCount ?> block<?= $blockGroupCount === 1 ? '' : 's' ?>
    <?= ($q !== '' || $blockFilter > 0 || $deptFilter > 0) ? ' (matching your search)' : '' ?>.
    Browse the full list in a dialog to save space on this page.
  </p>
  <div class="actions">
    <button type="button" class="btn" id="btn-open-student-roster"
            data-list-url="students_list_fragment.php<?= $listQs !== '' ? '?' . e($listQs) : '' ?>"
            data-student-count="<?= (int)$studentCount ?>"
            data-testid="open-student-roster"
            <?= $studentCount <= 0 && $q === '' && $blockFilter <= 0 && $deptFilter <= 0 ? 'disabled' : '' ?>>
      View authorized students<?= $studentCount > 0 ? ' (' . (int)$studentCount . ')' : '' ?>
    </button>
  </div>
  <?php if ($studentCount <= 0): ?>
    <p class="empty" style="margin-top:12px"><?= $q !== '' || $blockFilter > 0 || $deptFilter > 0 ? 'No students match your search.' : 'No students yet.' ?></p>
  <?php endif; ?>
</div>
<script>
(function () {
  function filterByDept(deptSelect, targetSelect, keepSelected) {
    if (!deptSelect || !targetSelect) return;
    var dept = deptSelect.value;
    var opts = targetSelect.querySelectorAll('option[data-dept]');
    opts.forEach(function (opt) {
      var match = !dept || opt.getAttribute('data-dept') === dept;
      opt.hidden = !match;
      opt.disabled = !match;
      if (!match && opt.selected) opt.selected = false;
    });
    if (keepSelected && targetSelect.value) {
      var cur = targetSelect.options[targetSelect.selectedIndex];
      if (cur && cur.disabled) targetSelect.value = '';
    }
  }
  var dept = document.getElementById('s-dept');
  var prog = document.getElementById('s-program');
  function sync() {
    filterByDept(dept, prog, true);
  }
  if (dept) {
    dept.addEventListener('change', sync);
    sync();
  }

  var year = document.getElementById('s-year');
  var sid = document.getElementById('s-schoolid');
  var sidHelp = document.getElementById('s-schoolid-help');
  var preview = <?= json_encode($nextSchoolIdPreview) ?>;
  var manualValue = '';

  function syncSchoolId() {
    if (!year || !sid) return;
    var isFirst = year.value === '1';
    if (isFirst) {
      if (!sid.readOnly) manualValue = sid.value;
      sid.value = preview || 'Auto-generated on save';
      sid.readOnly = true;
      sid.required = false;
      sid.classList.add('input--disabled');
      sid.setAttribute('aria-disabled', 'true');
      if (sidHelp) {
        sidHelp.textContent = '1st year: School ID is auto-generated and used as the login password. Final ID is assigned on save.';
      }
    } else {
      sid.readOnly = false;
      sid.required = true;
      sid.classList.remove('input--disabled');
      sid.removeAttribute('aria-disabled');
      if (sid.value === preview || sid.value.indexOf('Auto-generated') === 0) {
        sid.value = manualValue || '';
      }
      if (sidHelp) {
        sidHelp.textContent = 'Format 2026-00101 — also used as login password. Required for 2nd–4th year (existing ID).';
      }
    }
  }
  if (year) {
    year.addEventListener('change', syncSchoolId);
    syncSchoolId();
  }

  function openStudentRoster() {
    if (!window.UIModal) return;
    var btn = document.getElementById('btn-open-student-roster');
    if (!btn) return;
    var url = btn.getAttribute('data-list-url') || 'students_list_fragment.php';
    var count = btn.getAttribute('data-student-count') || '0';
    UIModal.open({
      title: 'Authorized student information',
      subtitle: count + ' student' + (count === '1' ? '' : 's'),
      wide: true,
      roster: true,
      url: url,
      onOpen: function (body) {
        body.onclick = function (e) {
          var viewBtn = e.target.closest('[data-open-student-view]');
          if (!viewBtn) return;
          e.preventDefault();
          UIModal.open({
            title: 'Student details',
            subtitle: viewBtn.getAttribute('data-student-name') || '',
            wide: true,
            url: 'student_fragment.php?id=' + encodeURIComponent(viewBtn.getAttribute('data-open-student-view')),
            onClose: openStudentRoster
          });
        };
      }
    });
  }

  var rosterBtn = document.getElementById('btn-open-student-roster');
  if (rosterBtn) {
    rosterBtn.addEventListener('click', openStudentRoster);
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-open-student-view]');
    if (!btn || btn.closest('#ui-modal-body')) return;
    if (!window.UIModal) return;
    UIModal.open({
      title: 'Student details',
      subtitle: btn.getAttribute('data-student-name') || '',
      wide: true,
      url: 'student_fragment.php?id=' + encodeURIComponent(btn.getAttribute('data-open-student-view'))
    });
  });
})();
</script>
<?php render_footer(); ?>
