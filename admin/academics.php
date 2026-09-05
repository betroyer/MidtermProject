<?php
/**
 * admin/academics.php — colleges, programs, subjects, and offerings.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('admin', 'academics');
$pdo = db();
ensure_academics_schema($pdo);

$section = $_GET['section'] ?? 'departments';
if (!in_array($section, ['departments', 'courses', 'subjects', 'offerings'], true)) {
    $section = 'departments';
}

$deptFilter = (int)($_GET['department_id'] ?? 0);
$q = trim($_GET['q'] ?? '');

$departments = departments_list(false);
$courses = courses_list($deptFilter > 0 ? $deptFilter : null, false);
$subjects = subjects_list($deptFilter > 0 ? $deptFilter : null, false);
$offerings = offerings_list(null, false);
$teachers = $pdo->query(
    'SELECT id, first_name, last_name, username FROM users WHERE role = "teacher" AND is_active = 1
     ORDER BY last_name, first_name'
)->fetchAll();

if ($q !== '') {
    $ql = mb_strtolower($q);
    if ($section === 'departments') {
        $departments = array_values(array_filter($departments, static function ($d) use ($ql) {
            return str_contains(mb_strtolower($d['code'] . ' ' . $d['name'] . ' ' . $d['description']), $ql);
        }));
    } elseif ($section === 'courses') {
        $courses = array_values(array_filter($courses, static function ($c) use ($ql) {
            return str_contains(mb_strtolower(
                $c['code'] . ' ' . $c['name'] . ' ' . $c['description'] . ' ' . $c['department_code']
            ), $ql);
        }));
    } elseif ($section === 'subjects') {
        $subjects = array_values(array_filter($subjects, static function ($s) use ($ql) {
            return str_contains(mb_strtolower(
                $s['code'] . ' ' . $s['name'] . ' ' . ($s['department_code'] ?? '')
            ), $ql);
        }));
    } else {
        $offerings = array_values(array_filter($offerings, static function ($o) use ($ql) {
            return str_contains(mb_strtolower(
                $o['subject_code'] . ' ' . $o['subject_name'] . ' ' . $o['name'] . ' ' . $o['teacher_name']
            ), $ql);
        }));
    }
}

$editDept = null;
$editCourse = null;
$editSubject = null;
$editOffering = null;

if ($section === 'departments' && isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    foreach (departments_list(false) as $d) {
        if ((int)$d['id'] === $eid) {
            $editDept = $d;
            break;
        }
    }
}
if ($section === 'courses' && isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    foreach (courses_list(null, false) as $c) {
        if ((int)$c['id'] === $eid) {
            $editCourse = $c;
            break;
        }
    }
}
if ($section === 'subjects' && isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    foreach (subjects_list(null, false) as $s) {
        if ((int)$s['id'] === $eid) {
            $editSubject = $s;
            break;
        }
    }
}
if ($section === 'offerings' && isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    foreach (offerings_list(null, false) as $o) {
        if ((int)$o['id'] === $eid) {
            $editOffering = $o;
            break;
        }
    }
}

render_header('Colleges & Programs', $user);
render_flash();
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Colleges &amp; Programs</h1>
    <p class="page-desc">Colleges house degree programs. Subjects are classes; each offering assigns a teacher and section.</p>
  </div>
</div>

<nav class="settings-subnav" data-testid="academics-subnav">
  <a class="settings-tab<?= $section === 'departments' ? ' active' : '' ?>"
     href="academics.php?section=departments" data-testid="tab-departments">Colleges</a>
  <a class="settings-tab<?= $section === 'courses' ? ' active' : '' ?>"
     href="academics.php?section=courses" data-testid="tab-courses">Programs</a>
  <a class="settings-tab<?= $section === 'subjects' ? ' active' : '' ?>"
     href="academics.php?section=subjects" data-testid="tab-subjects">Subjects</a>
  <a class="settings-tab<?= $section === 'offerings' ? ' active' : '' ?>"
     href="academics.php?section=offerings" data-testid="tab-offerings">Offerings</a>
</nav>

<div class="stat-row">
  <div class="stat"><div class="num"><?= count(departments_list(false)) ?></div><div class="lbl">Colleges</div></div>
  <div class="stat"><div class="num"><?= count(courses_list(null, false)) ?></div><div class="lbl">Programs</div></div>
  <div class="stat"><div class="num"><?= count(subjects_list(null, false)) ?></div><div class="lbl">Subjects</div></div>
  <div class="stat"><div class="num"><?= count(offerings_list(null, false)) ?></div><div class="lbl">Offerings</div></div>
</div>

<?php if ($section === 'departments'): ?>
<div class="card" data-testid="dept-form-card">
  <h3><?= $editDept ? 'Update college' : 'Add college' ?></h3>
  <form method="post" action="actions.php" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editDept ? 'update_department' : 'add_department' ?>">
    <?php if ($editDept): ?>
      <input type="hidden" name="id" value="<?= (int)$editDept['id'] ?>">
    <?php endif; ?>
    <div class="field">
      <label>Code</label>
      <input class="input" name="code" maxlength="20" required
             value="<?= e($editDept['code'] ?? '') ?>" placeholder="e.g. CICT" data-testid="dept-code">
    </div>
    <div class="field">
      <label>Name</label>
      <input class="input" name="name" maxlength="160" required
             value="<?= e($editDept['name'] ?? '') ?>" placeholder="e.g. College of Information and Communication Technology" data-testid="dept-name">
    </div>
    <div class="field" style="grid-column:1/-1">
      <label>Description</label>
      <input class="input" name="description" maxlength="255"
             value="<?= e($editDept['description'] ?? '') ?>" placeholder="Optional" data-testid="dept-desc">
    </div>
    <?php if ($editDept): ?>
    <div class="field">
      <label class="check-row">
        <input type="checkbox" name="is_active" value="1" <?= (int)($editDept['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
        Active
      </label>
    </div>
    <?php endif; ?>
    <div class="actions" style="grid-column:1/-1">
      <button class="btn" type="submit" data-testid="dept-save"><?= $editDept ? 'Save changes' : 'Add college' ?></button>
      <?php if ($editDept): ?>
        <a class="btn-out" href="academics.php?section=departments">Cancel</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h3>Colleges (<?= count($departments) ?>)</h3>
  <form method="get" class="search-bar" style="margin-bottom:14px">
    <input type="hidden" name="section" value="departments">
    <div class="field grow">
      <label for="dept-q">Search</label>
      <input class="input" id="dept-q" name="q" value="<?= e($q) ?>" placeholder="Code or name…">
    </div>
    <div class="field actions-inline">
      <button class="btn" type="submit">Search</button>
      <a class="btn-out" href="academics.php?section=departments">Clear</a>
    </div>
  </form>
  <?php if ($departments): ?>
  <div class="table-wrap">
    <table class="table table--aligned" data-testid="departments-table">
      <thead>
        <tr>
          <th class="col-user">Code</th>
          <th class="col-person">Name</th>
          <th class="col-email">Description</th>
          <th class="col-num">Programs</th>
          <th class="col-status">Status</th>
          <th class="col-actions"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($departments as $d):
        $active = (int)$d['is_active'] === 1;
      ?>
        <tr>
          <td class="col-user mono"><?= e($d['code']) ?></td>
          <td class="col-person"><?= e($d['name']) ?></td>
          <td class="col-email"><?= e($d['description'] ?: '—') ?></td>
          <td class="col-num"><?= (int)$d['course_count'] ?></td>
          <td class="col-status"><span class="tag <?= $active ? 'tag--pass' : 'tag--fail' ?>"><?= $active ? 'Active' : 'Inactive' ?></span></td>
          <td class="col-actions">
            <div class="row-actions">
              <a class="btn-out" href="academics.php?section=departments&edit=<?= (int)$d['id'] ?>">Update</a>
              <a class="btn-out" href="academics.php?section=courses&department_id=<?= (int)$d['id'] ?>">Programs</a>
              <form method="post" action="actions.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_department">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button class="btn-danger" type="submit"
                        onclick="return confirm('Delete college <?= e($d['code']) ?> and its programs?');">Delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <p class="empty"><?= $q !== '' ? 'No colleges match your search.' : 'No colleges yet.' ?></p>
  <?php endif; ?>
</div>

<?php elseif ($section === 'courses'): ?>
<div class="card" data-testid="course-form-card">
  <h3><?= $editCourse ? 'Update program' : 'Add program' ?></h3>
  <?php if (count(departments_list(false)) === 0): ?>
    <p class="empty">Add a college first before creating programs.</p>
  <?php else: ?>
  <form method="post" action="actions.php" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editCourse ? 'update_course' : 'add_course' ?>">
    <?php if ($editCourse): ?>
      <input type="hidden" name="id" value="<?= (int)$editCourse['id'] ?>">
    <?php endif; ?>
    <div class="field">
      <label>College</label>
      <select class="input" name="department_id" required data-testid="course-dept">
        <option value="">— select —</option>
        <?php foreach (departments_list(false) as $d): ?>
          <option value="<?= (int)$d['id'] ?>"
            <?= (int)($editCourse['department_id'] ?? $deptFilter) === (int)$d['id'] ? 'selected' : '' ?>>
            <?= e($d['code'] . ' — ' . $d['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Code</label>
      <input class="input" name="code" maxlength="40" required
             value="<?= e($editCourse['code'] ?? '') ?>" placeholder="e.g. BSIT" data-testid="course-code">
    </div>
    <div class="field">
      <label>Name</label>
      <input class="input" name="name" maxlength="200" required
             value="<?= e($editCourse['name'] ?? '') ?>" placeholder="e.g. BS in Information Technology" data-testid="course-name">
    </div>
    <div class="field">
      <label>Units</label>
      <input class="input" type="number" name="units" step="0.5" min="0" max="12"
             value="<?= e(number_format((float)($editCourse['units'] ?? 0), 1, '.', '')) ?>" data-testid="course-units">
    </div>
    <div class="field" style="grid-column:1/-1">
      <label>Description</label>
      <input class="input" name="description" maxlength="255"
             value="<?= e($editCourse['description'] ?? '') ?>" placeholder="Optional">
    </div>
    <?php if ($editCourse): ?>
    <div class="field">
      <label class="check-row">
        <input type="checkbox" name="is_active" value="1" <?= (int)($editCourse['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
        Active
      </label>
    </div>
    <?php endif; ?>
    <div class="actions" style="grid-column:1/-1">
      <button class="btn" type="submit" data-testid="course-save"><?= $editCourse ? 'Save changes' : 'Add program' ?></button>
      <?php if ($editCourse): ?>
        <a class="btn-out" href="academics.php?section=courses">Cancel</a>
      <?php endif; ?>
    </div>
  </form>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Programs (<?= count($courses) ?>)</h3>
  <form method="get" class="search-bar" style="margin-bottom:14px">
    <input type="hidden" name="section" value="courses">
    <div class="field grow">
      <label for="course-q">Search</label>
      <input class="input" id="course-q" name="q" value="<?= e($q) ?>" placeholder="Code, name, or college…">
    </div>
    <div class="field">
      <label for="course-dept-filter">College</label>
      <select class="input" id="course-dept-filter" name="department_id">
        <option value="0">All colleges</option>
        <?php foreach (departments_list(false) as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= $deptFilter === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['code']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field actions-inline">
      <button class="btn" type="submit">Search</button>
      <a class="btn-out" href="academics.php?section=courses">Clear</a>
    </div>
  </form>
  <?php if ($courses): ?>
  <div class="table-wrap">
    <table class="table table--aligned" data-testid="courses-table">
      <thead>
        <tr>
          <th class="col-user">Code</th>
          <th class="col-person">Program</th>
          <th class="col-blocks">College</th>
          <th class="col-num">Units</th>
          <th class="col-status">Status</th>
          <th class="col-actions"></th>
        </tr>
      </thead>
      <tbody>
      <?php
      $byDept = [];
      foreach ($courses as $c) {
          $byDept[$c['department_code']][] = $c;
      }
      foreach ($byDept as $deptCode => $list):
      ?>
        <tr><td colspan="6" style="padding-top:16px"><strong class="block-section-title" style="margin:0"><?= e($deptCode) ?></strong></td></tr>
        <?php foreach ($list as $c):
          $active = (int)$c['is_active'] === 1;
        ?>
        <tr>
          <td class="col-user mono"><?= e($c['code']) ?></td>
          <td class="col-person">
            <div><?= e($c['name']) ?></div>
            <?php if ($c['description']): ?><div class="help"><?= e($c['description']) ?></div><?php endif; ?>
          </td>
          <td class="col-blocks"><?= e($c['department_code']) ?></td>
          <td class="col-num"><?= e(number_format((float)$c['units'], 1)) ?></td>
          <td class="col-status"><span class="tag <?= $active ? 'tag--pass' : 'tag--fail' ?>"><?= $active ? 'Active' : 'Inactive' ?></span></td>
          <td class="col-actions">
            <div class="row-actions">
              <a class="btn-out" href="academics.php?section=courses&edit=<?= (int)$c['id'] ?>">Update</a>
              <form method="post" action="actions.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_course">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="btn-danger" type="submit"
                        onclick="return confirm('Delete program <?= e($c['code']) ?>?');">Delete</button>
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
    <p class="empty"><?= $q !== '' || $deptFilter > 0 ? 'No programs match your filters.' : 'No programs yet.' ?></p>
  <?php endif; ?>
</div>

<?php elseif ($section === 'subjects'): ?>
<div class="card" data-testid="subject-form-card">
  <h3><?= $editSubject ? 'Update subject' : 'Add subject' ?></h3>
  <form method="post" action="actions.php" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editSubject ? 'update_subject' : 'add_subject' ?>">
    <?php if ($editSubject): ?>
      <input type="hidden" name="id" value="<?= (int)$editSubject['id'] ?>">
    <?php endif; ?>
    <div class="field">
      <label>College (optional)</label>
      <select class="input" name="department_id" data-testid="subject-dept">
        <option value="0">— any / global —</option>
        <?php foreach (departments_list(false) as $d): ?>
          <option value="<?= (int)$d['id'] ?>"
            <?= (int)($editSubject['department_id'] ?? $deptFilter) === (int)$d['id'] ? 'selected' : '' ?>>
            <?= e($d['code'] . ' — ' . $d['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Code</label>
      <input class="input" name="code" maxlength="40" required
             value="<?= e($editSubject['code'] ?? '') ?>" placeholder="e.g. IT101" data-testid="subject-code">
    </div>
    <div class="field">
      <label>Name</label>
      <input class="input" name="name" maxlength="160" required
             value="<?= e($editSubject['name'] ?? '') ?>" placeholder="e.g. Programming 1" data-testid="subject-name">
    </div>
    <div class="field">
      <label>Units</label>
      <input class="input" type="number" name="units" step="0.5" min="0.5" max="12"
             value="<?= e(number_format((float)($editSubject['units'] ?? 3), 1, '.', '')) ?>" data-testid="subject-units">
    </div>
    <?php if ($editSubject): ?>
    <div class="field">
      <label class="check-row">
        <input type="checkbox" name="is_active" value="1" <?= (int)($editSubject['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
        Active
      </label>
    </div>
    <?php endif; ?>
    <div class="actions" style="grid-column:1/-1">
      <button class="btn" type="submit" data-testid="subject-save"><?= $editSubject ? 'Save changes' : 'Add subject' ?></button>
      <?php if ($editSubject): ?>
        <a class="btn-out" href="academics.php?section=subjects">Cancel</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h3>Subjects (<?= count($subjects) ?>)</h3>
  <form method="get" class="search-bar" style="margin-bottom:14px">
    <input type="hidden" name="section" value="subjects">
    <div class="field grow">
      <label for="subject-q">Search</label>
      <input class="input" id="subject-q" name="q" value="<?= e($q) ?>" placeholder="Code or name…">
    </div>
    <div class="field">
      <label for="subject-dept-filter">College</label>
      <select class="input" id="subject-dept-filter" name="department_id">
        <option value="0">All</option>
        <?php foreach (departments_list(false) as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= $deptFilter === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['code']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field actions-inline">
      <button class="btn" type="submit">Search</button>
      <a class="btn-out" href="academics.php?section=subjects">Clear</a>
    </div>
  </form>
  <?php if ($subjects): ?>
  <div class="table-wrap">
    <table class="table table--aligned" data-testid="subjects-table">
      <thead>
        <tr>
          <th class="col-user">Code</th>
          <th class="col-person">Subject</th>
          <th class="col-blocks">College</th>
          <th class="col-num">Units</th>
          <th class="col-status">Status</th>
          <th class="col-actions"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($subjects as $s):
        $active = (int)$s['is_active'] === 1;
      ?>
        <tr>
          <td class="col-user mono"><?= e($s['code']) ?></td>
          <td class="col-person"><?= e($s['name']) ?></td>
          <td class="col-blocks"><?= e($s['department_code'] ?: '—') ?></td>
          <td class="col-num"><?= e(number_format((float)$s['units'], 1)) ?></td>
          <td class="col-status"><span class="tag <?= $active ? 'tag--pass' : 'tag--fail' ?>"><?= $active ? 'Active' : 'Inactive' ?></span></td>
          <td class="col-actions">
            <div class="row-actions">
              <a class="btn-out" href="academics.php?section=subjects&edit=<?= (int)$s['id'] ?>">Update</a>
              <form method="post" action="actions.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_subject">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button class="btn-danger" type="submit"
                        onclick="return confirm('Delete subject <?= e($s['code']) ?>?');">Delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <p class="empty"><?= $q !== '' || $deptFilter > 0 ? 'No subjects match your filters.' : 'No subjects yet.' ?></p>
  <?php endif; ?>
</div>

<?php else: /* offerings */ ?>
<div class="card" data-testid="offering-form-card">
  <h3><?= $editOffering ? 'Update offering' : 'Add offering' ?></h3>
  <?php if (count(subjects_list(null, true)) === 0 || !$teachers): ?>
    <p class="empty">Add subjects and teachers before creating offerings.</p>
  <?php else: ?>
  <form method="post" action="actions.php" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editOffering ? 'update_offering' : 'add_offering' ?>">
    <?php if ($editOffering): ?>
      <input type="hidden" name="id" value="<?= (int)$editOffering['id'] ?>">
    <?php endif; ?>
    <div class="field">
      <label>Subject</label>
      <select class="input" name="subject_id" required data-testid="offering-subject">
        <option value="">— select —</option>
        <?php foreach (subjects_list(null, false) as $s): ?>
          <option value="<?= (int)$s['id'] ?>"
            <?= (int)($editOffering['subject_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
            <?= e($s['code'] . ' — ' . $s['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Teacher</label>
      <select class="input" name="teacher_id" required data-testid="offering-teacher">
        <option value="">— select —</option>
        <?php foreach ($teachers as $t): ?>
          <option value="<?= (int)$t['id'] ?>"
            <?= (int)($editOffering['teacher_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>>
            <?= e($t['last_name'] . ', ' . $t['first_name'] . ' (' . $t['username'] . ')') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Section label</label>
      <input class="input" name="name" maxlength="80"
             value="<?= e($editOffering['name'] ?? '') ?>" placeholder="e.g. BSIT-A" data-testid="offering-name">
    </div>
    <?php if ($editOffering): ?>
    <div class="field">
      <label class="check-row">
        <input type="checkbox" name="is_active" value="1" <?= (int)($editOffering['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
        Active
      </label>
    </div>
    <?php endif; ?>
    <div class="actions" style="grid-column:1/-1">
      <button class="btn" type="submit" data-testid="offering-save"><?= $editOffering ? 'Save changes' : 'Add offering' ?></button>
      <?php if ($editOffering): ?>
        <a class="btn-out" href="academics.php?section=offerings">Cancel</a>
      <?php endif; ?>
    </div>
  </form>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Subject offerings (<?= count($offerings) ?>)</h3>
  <form method="get" class="search-bar" style="margin-bottom:14px">
    <input type="hidden" name="section" value="offerings">
    <div class="field grow">
      <label for="offering-q">Search</label>
      <input class="input" id="offering-q" name="q" value="<?= e($q) ?>" placeholder="Subject, section, or teacher…">
    </div>
    <div class="field actions-inline">
      <button class="btn" type="submit">Search</button>
      <a class="btn-out" href="academics.php?section=offerings">Clear</a>
    </div>
  </form>
  <?php if ($offerings): ?>
  <div class="table-wrap">
    <table class="table table--aligned" data-testid="offerings-table">
      <thead>
        <tr>
          <th class="col-user">Subject</th>
          <th class="col-person">Section</th>
          <th class="col-email">Teacher</th>
          <th class="col-num">Enrolled</th>
          <th class="col-status">Status</th>
          <th class="col-actions"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($offerings as $o):
        $active = (int)$o['is_active'] === 1;
      ?>
        <tr>
          <td class="col-user mono"><?= e($o['subject_code']) ?></td>
          <td class="col-person">
            <div><?= e($o['subject_name']) ?></div>
            <div class="help"><?= e($o['name'] ?: '—') ?></div>
          </td>
          <td class="col-email"><?= e($o['teacher_name']) ?></td>
          <td class="col-num"><?= (int)$o['enroll_count'] ?></td>
          <td class="col-status"><span class="tag <?= $active ? 'tag--pass' : 'tag--fail' ?>"><?= $active ? 'Active' : 'Inactive' ?></span></td>
          <td class="col-actions">
            <div class="row-actions">
              <a class="btn-out" href="academics.php?section=offerings&edit=<?= (int)$o['id'] ?>">Update</a>
              <form method="post" action="actions.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_offering">
                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                <button class="btn-danger" type="submit"
                        onclick="return confirm('Delete this offering?');">Delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <p class="empty"><?= $q !== '' ? 'No offerings match your search.' : 'No offerings yet.' ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php render_footer(); ?>
