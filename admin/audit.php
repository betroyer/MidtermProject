<?php
/** admin/audit.php — tamper-evident activity / security audit log. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('admin', 'audit_log');
$pdo = db();
ensure_audit_schema($pdo);

$q = trim($_GET['q'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$actionFilter = trim($_GET['action'] ?? '');

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(actor_username LIKE :q OR action LIKE :q2 OR target LIKE :q3 OR details LIKE :q4 OR ip_address LIKE :q5)';
    $like = '%' . $q . '%';
    $params[':q'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
    $params[':q4'] = $like;
    $params[':q5'] = $like;
}
if (in_array($roleFilter, ['admin', 'teacher', 'student', 'guest'], true)) {
    $where[] = 'actor_role = :role';
    $params[':role'] = $roleFilter;
}
if ($actionFilter !== '') {
    $where[] = 'action = :action';
    $params[':action'] = $actionFilter;
}

$sql = 'SELECT id, actor_id, actor_role, actor_username, action, target, details, ip_address, created_at
        FROM audit_log WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC LIMIT 300';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$actions = $pdo->query('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$total = (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();

render_header('Audit Logs', $user);
render_flash();
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Audit Logs</h1>
    <p class="page-desc">Tamper-evident record of logins, student changes, settings, and permission updates. Showing up to 300 newest of <?= $total ?> events.</p>
  </div>
</div>

<div class="card" data-testid="audit-filters">
  <h3>Search audit events</h3>
  <form method="get" action="audit.php" class="search-bar">
    <div class="field grow">
      <label for="audit-q">Keyword</label>
      <input class="input" id="audit-q" name="q" value="<?= e($q) ?>"
             placeholder="username, action, target, IP…" data-testid="audit-search">
    </div>
    <div class="field">
      <label for="audit-role">Role</label>
      <select class="input" id="audit-role" name="role" data-testid="audit-role">
        <option value="">All roles</option>
        <?php foreach (['admin', 'teacher', 'student', 'guest'] as $r): ?>
          <option value="<?= $r ?>" <?= $roleFilter === $r ? 'selected' : '' ?>><?= e(ucfirst($r)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="audit-action">Action</label>
      <select class="input" id="audit-action" name="action" data-testid="audit-action">
        <option value="">All actions</option>
        <?php foreach ($actions as $a): ?>
          <option value="<?= e($a) ?>" <?= $actionFilter === $a ? 'selected' : '' ?>><?= e($a) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field actions-inline">
      <button class="btn" type="submit" data-testid="audit-filter-submit">Filter</button>
      <a class="btn-out" href="audit.php">Clear</a>
    </div>
  </form>
</div>

<div class="card" data-testid="audit-table-card">
  <h3>Events (<?= count($rows) ?>)</h3>
  <?php if ($rows): ?>
  <div class="table-wrap">
    <table class="table" data-testid="audit-table">
      <thead>
        <tr>
          <th>When</th>
          <th>Actor</th>
          <th>Role</th>
          <th>Action</th>
          <th>Target</th>
          <th>Details</th>
          <th>IP</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr data-testid="audit-row-<?= (int)$row['id'] ?>">
          <td class="mono"><?= e($row['created_at']) ?></td>
          <td class="mono"><?= e($row['actor_username'] ?: '—') ?></td>
          <td><span class="actor-tag"><?= e($row['actor_role']) ?></span></td>
          <td><code class="mono"><?= e($row['action']) ?></code></td>
          <td class="mono"><?= e($row['target'] ?: '—') ?></td>
          <td><?= e($row['details'] ?: '—') ?></td>
          <td class="mono"><?= e($row['ip_address'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <p class="empty">No audit events match your filters.</p>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
