<?php
/** admin/settings.php — manage system settings + Roles & Permissions. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('admin', 'roles');
$pdo = db();
ensure_rbac_schema($pdo);

$section = $_GET['section'] ?? 'general';
if (!in_array($section, ['general', 'grading', 'roles'], true)) {
    $section = 'general';
}

$roles = $pdo->query('SELECT id, name, code, color, description FROM roles ORDER BY id')->fetchAll();
$permissions = $pdo->query(
    'SELECT id, code, label, description FROM permissions ORDER BY sort_order, id'
)->fetchAll();

$matrix = [];
foreach ($roles as $r) {
    $matrix[$r['code']] = permissions_for($r['code']);
}

$counts = [
    'admins'   => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role="admin"')->fetchColumn(),
    'teachers' => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role="teacher"')->fetchColumn(),
    'students' => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role="student"')->fetchColumn(),
];

$settings = [
    'school_name'    => app_setting('school_name', 'Secure SIMS'),
    'school_year'    => app_setting('school_year', '2025-2026'),
    'support_email'  => app_setting('support_email', 'admin@gmail.com'),
    'login_message'  => app_setting('login_message', 'Sign in with your username and school ID.'),
    'records_locked' => app_setting('records_locked', '0'),
];
$weights = grading_weights();
$scaleRows = grading_scale_rows();
$passingPoint = passing_grade_point();

render_header('Settings', $user);
render_flash();
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Settings</h1>
    <p class="page-desc">Manage system settings, grading system, and role-based access.</p>
  </div>
</div>

<nav class="settings-subnav" data-testid="settings-subnav">
  <a class="settings-tab<?= $section === 'general' ? ' active' : '' ?>"
     href="settings.php?section=general" data-testid="settings-tab-general">Manage System Settings</a>
  <a class="settings-tab<?= $section === 'grading' ? ' active' : '' ?>"
     href="settings.php?section=grading" data-testid="settings-tab-grading">Grading System</a>
  <a class="settings-tab<?= $section === 'roles' ? ' active' : '' ?>"
     href="settings.php?section=roles" data-testid="settings-tab-roles">Roles &amp; Permissions</a>
</nav>

<?php if ($section === 'general'): ?>
  <div class="card" data-testid="settings-general">
    <h3>Manage System Settings</h3>
    <form method="post" action="actions.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_general">
      <div class="form-grid">
        <div class="field">
          <label for="school_name">System name</label>
          <input class="input" id="school_name" name="school_name" maxlength="80"
                 value="<?= e($settings['school_name']) ?>" data-testid="setting-school-name" required>
          <div class="help">Shown on the login screen and in every panel header.</div>
        </div>
        <div class="field">
          <label for="school_year">School year</label>
          <input class="input" id="school_year" name="school_year" maxlength="20"
                 value="<?= e($settings['school_year']) ?>" data-testid="setting-school-year" required>
          <div class="help">Academic year label for reports and dashboards.</div>
        </div>
        <div class="field">
          <label for="support_email">Support email</label>
          <input class="input" id="support_email" name="support_email" type="email" maxlength="120"
                 value="<?= e($settings['support_email']) ?>" data-testid="setting-support-email" required>
          <div class="help">Contact address shown to users who need help.</div>
        </div>
        <div class="field">
          <label for="login_message">Login message</label>
          <input class="input" id="login_message" name="login_message" maxlength="160"
                 value="<?= e($settings['login_message']) ?>" data-testid="setting-login-message" required>
          <div class="help">Short hint under the login title.</div>
        </div>
      </div>
      <div class="field" style="margin-top:8px">
        <label class="check-row">
          <input type="checkbox" name="records_locked" value="1"
                 <?= $settings['records_locked'] === '1' ? 'checked' : '' ?>
                 data-testid="setting-records-locked">
          Lock student record edits (view &amp; search still allowed)
        </label>
        <div class="help">When locked, admins cannot add, update, or delete student accounts.</div>
      </div>
      <div class="actions"><button class="btn" type="submit" data-testid="save-general">Save system settings</button></div>
    </form>
  </div>

  <div class="stat-row">
    <div class="stat"><div class="num"><?= $counts['admins'] ?></div><div class="lbl">Admins</div></div>
    <div class="stat"><div class="num"><?= $counts['teachers'] ?></div><div class="lbl">Teachers</div></div>
    <div class="stat"><div class="num"><?= $counts['students'] ?></div><div class="lbl">Students</div></div>
  </div>
<?php elseif ($section === 'grading'): ?>
  <?php
    $targetGuide = array_values(array_filter(
        $scaleRows,
        static fn($r) => (float)$r['grade_point'] >= 1.0 && (float)$r['grade_point'] <= 3.0
    ));
    usort($targetGuide, static fn($a, $b) => (float)$b['grade_point'] <=> (float)$a['grade_point']);
  ?>
  <form method="post" action="actions.php" data-testid="grading-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_grading">

    <div class="card" data-testid="settings-grading-weights">
      <h3>Component weights (%)</h3>
      <p class="page-desc" style="margin:0 0 14px">
        Semestral Grade = (Midterm + Final) / 2 (default 50% / 50%).
        Input grades must be 50–100. Passed if semestral ≥ 74.5.
      </p>
      <input type="hidden" name="weight_quiz" value="0">
      <input type="hidden" name="weight_activity" value="0">
      <div class="form-grid">
        <div class="field">
          <label>Midterm</label>
          <input class="input" type="number" step="0.01" min="0" max="100" name="weight_midterm"
                 value="<?= e($weights['midterm_pct']) ?>" required data-testid="weight-midterm">
        </div>
        <div class="field">
          <label>Final exam</label>
          <input class="input" type="number" step="0.01" min="0" max="100" name="weight_final"
                 value="<?= e($weights['final_pct']) ?>" required data-testid="weight-final">
        </div>
        <div class="field">
          <label>Passing grade point (≤)</label>
          <input class="input" type="number" step="0.01" min="1" max="5" name="passing_point"
                 value="<?= e(number_format($passingPoint, 2, '.', '')) ?>" required data-testid="passing-point">
          <div class="help">Grade points at or below this value are passing (default 3.00).</div>
        </div>
      </div>
    </div>

    <div class="card" data-testid="settings-grading-targets">
      <h3>Target scores for 3.0 → 1.0</h3>
      <p class="page-desc" style="margin:0 0 14px">
        After weights are applied, the student’s final subject % must fall in these bands to earn each grade point.
        Edit the rating scale below, then save, to change these targets.
      </p>
      <div class="table-wrap">
        <table class="table" data-testid="grading-target-guide">
          <thead>
            <tr>
              <th>Grade point</th>
              <th>Target final %</th>
              <th>Description</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($targetGuide as $row):
            $min = number_format((float)$row['min_percent'], 0);
            $max = number_format((float)$row['max_percent'], 0);
            $range = ((float)$row['min_percent'] === (float)$row['max_percent'])
              ? $min . '%'
              : $min . '–' . $max . '%';
          ?>
            <tr>
              <td><strong><?= e(number_format((float)$row['grade_point'], 2)) ?></strong></td>
              <td class="mono"><?= e($range) ?></td>
              <td><?= e($row['description'] !== '' ? $row['description'] : '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card" data-testid="settings-grading-scale">
      <h3>Rating scale</h3>
      <p class="page-desc" style="margin:0 0 14px">
        Percent score from the formula is mapped to a grade point using these bands (like your grading chart).
        Change Min % / Max % here to set the targets for 3.0 through 1.0.
      </p>
      <div class="table-wrap">
        <table class="table" data-testid="grading-scale-table">
          <thead>
            <tr>
              <th>Grade</th>
              <th>Min %</th>
              <th>Max %</th>
              <th>Grade description</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="scaleBody">
          <?php foreach ($scaleRows as $i => $row): ?>
            <tr>
              <td><input class="input" type="number" step="0.01" min="1" max="5" name="scale_point[]"
                         value="<?= e($row['grade_point']) ?>" required></td>
              <td><input class="input" type="number" step="0.01" min="0" max="100" name="scale_min[]"
                         value="<?= e($row['min_percent']) ?>" required></td>
              <td><input class="input" type="number" step="0.01" min="0" max="100" name="scale_max[]"
                         value="<?= e($row['max_percent']) ?>" required></td>
              <td><input class="input" type="text" maxlength="40" name="scale_desc[]"
                         value="<?= e($row['description']) ?>" placeholder="e.g. Excellent"></td>
              <td><button type="button" class="btn-danger" onclick="this.closest('tr').remove()">Remove</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="actions" style="margin-top:12px">
        <button type="button" class="btn-ghost" id="addScaleRow" data-testid="add-scale-row">+ Add row</button>
        <button class="btn" type="submit" data-testid="save-grading">Save grading system</button>
      </div>
    </div>
  </form>
  <script>
  document.getElementById('addScaleRow').addEventListener('click', function () {
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><input class="input" type="number" step="0.01" min="1" max="5" name="scale_point[]" value="3.00" required></td>' +
      '<td><input class="input" type="number" step="0.01" min="0" max="100" name="scale_min[]" value="75" required></td>' +
      '<td><input class="input" type="number" step="0.01" min="0" max="100" name="scale_max[]" value="75" required></td>' +
      '<td><input class="input" type="text" maxlength="40" name="scale_desc[]" placeholder="e.g. Excellent"></td>' +
      '<td><button type="button" class="btn-danger" onclick="this.closest(\'tr\').remove()">Remove</button></td>';
    document.getElementById('scaleBody').appendChild(tr);
  });
  </script>
<?php else: ?>
  <div class="role-legend">
    <?php foreach ($roles as $r): ?>
      <div class="role-legend-card" style="--role-color:<?= e($r['color']) ?>">
        <div class="role-legend-name"><?= e($r['name']) ?></div>
        <div class="role-legend-desc"><?= e($r['description']) ?></div>
        <div class="role-legend-count"><?= count($matrix[$r['code']] ?? []) ?> permissions on</div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card" data-testid="settings-roles">
    <h3>Roles &amp; Permissions</h3>
    <p class="page-desc" style="margin:0 0 16px">
      Tick a box to grant that module to a role. Untick to revoke it.
      Nav items and pages update immediately after you save.
      Admin <strong>Roles &amp; Permissions</strong> cannot be turned off (so you cannot lock yourself out).
    </p>
    <form method="post" action="actions.php" data-testid="permissions-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_permissions">
      <div class="perm-wrap">
        <table class="table perm-table" data-testid="perm-table">
          <thead>
            <tr>
              <th>Permission</th>
              <?php foreach ($roles as $r): ?>
                <th class="perm-col" style="color:<?= e($r['color']) ?>"><?= e($r['name']) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($permissions as $p): ?>
            <tr data-testid="perm-row-<?= e($p['code']) ?>">
              <td class="perm-label">
                <div class="perm-name"><?= e($p['label']) ?></div>
                <div class="help"><?= e($p['description']) ?></div>
              </td>
              <?php foreach ($roles as $r):
                  $locked = in_array($p['code'], locked_permissions_for($r['code']), true);
                  $on = $locked || in_array($p['code'], $matrix[$r['code']] ?? [], true);
              ?>
                <td class="perm-cell">
                  <?php if ($locked): ?>
                    <input type="hidden" name="perm[<?= e($r['code']) ?>][]" value="<?= e($p['code']) ?>">
                  <?php endif; ?>
                  <input type="checkbox"
                         name="perm[<?= e($r['code']) ?>][]"
                         value="<?= e($p['code']) ?>"
                         <?= $on ? 'checked' : '' ?>
                         <?= $locked ? 'disabled' : '' ?>
                         data-testid="perm-<?= e($r['code']) ?>-<?= e($p['code']) ?>">
                  <?php if ($locked): ?><div class="perm-lock">Required</div><?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="actions" style="margin-top:16px">
        <button class="btn" type="submit" data-testid="save-permissions">Save permissions</button>
      </div>
    </form>
  </div>
<?php endif; ?>
<?php render_footer(); ?>
