<?php
/**
 * teacher/profile.php — teacher profile information + avatar.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('teacher', 'profile');
$pdo = db();
ensure_academics_schema($pdo);
ensure_user_avatar_column($pdo);
$tid = (int)$user['id'];

$stmt = $pdo->prepare(
    'SELECT u.*,
            (SELECT GROUP_CONCAT(b.name ORDER BY b.name SEPARATOR ", ")
             FROM blocks b WHERE b.teacher_id = u.id) AS block_names
     FROM users u
     WHERE u.id = :id AND u.role = "teacher"'
);
$stmt->execute([':id' => $tid]);
$me = $stmt->fetch();
if (!$me) {
    set_flash('error', 'Profile not found.');
    redirect('index.php');
}
$_SESSION['user']['avatar'] = $me['avatar'] ?? null;

$blocks = $pdo->prepare(
    'SELECT b.id, b.name, d.code AS department_code, c.code AS course_code, c.name AS course_name,
            (SELECT COUNT(*) FROM users s WHERE s.role = "student" AND s.block_id = b.id) AS student_count
     FROM blocks b
     LEFT JOIN departments d ON d.id = b.department_id
     LEFT JOIN courses c ON c.id = b.course_id
     WHERE b.teacher_id = :t
     ORDER BY b.name'
);
$blocks->execute([':t' => $tid]);
$blocks = $blocks->fetchAll();

$offerings = offerings_list($tid, true);
$fullName = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
$schoolIdNo = trim((string)($me['school_id'] ?? ''));
if ($schoolIdNo === '') {
    $schoolIdNo = '—';
}

render_header('My Profile', $user);
render_flash();
?>
<div class="page-head">
  <div>
    <h1 class="page-title">My Profile</h1>
    <p class="page-desc">Your account details and the blocks you handle.</p>
  </div>
</div>

<div class="card profile-hero" data-testid="teacher-profile-card">
  <div class="profile-pfp">
    <?= avatar_img_tag($me['avatar'] ?? null, $fullName, 'avatar avatar--lg') ?>
    <div class="profile-pfp-meta">
      <h2 class="profile-name" style="margin:0 0 4px;font-size:22px"><?= e($fullName) ?></h2>
      <p class="mono page-desc" style="margin:0 0 12px"><?= e($me['username']) ?></p>
      <form method="post" action="actions.php" enctype="multipart/form-data" class="pfp-form" data-testid="teacher-avatar-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_avatar">
        <label class="field">
          <span>Update photo</span>
          <input class="input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required data-testid="teacher-avatar-file">
          <span class="help">JPG, PNG, or WebP · max 2&nbsp;MB</span>
        </label>
        <div class="actions">
          <button class="btn" type="submit">Save photo</button>
        </div>
      </form>
      <?php if (!empty($me['avatar'])): ?>
      <form method="post" action="actions.php" style="margin-top:8px" onsubmit="return confirm('Remove your profile picture?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="remove_avatar">
        <button class="btn-danger" type="submit" data-testid="teacher-avatar-remove">Remove photo</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card" data-testid="teacher-profile-details">
  <h3>Account details</h3>
  <dl class="info-grid profile-details-grid">
    <div><dt>Full name</dt><dd data-testid="tp-name"><?= e($fullName) ?></dd></div>
    <div><dt>Username</dt><dd class="mono" data-testid="tp-username"><?= e($me['username']) ?></dd></div>
    <div><dt>Email</dt><dd data-testid="tp-email"><?= e($me['email'] ?: '—') ?></dd></div>
    <div><dt>Phone</dt><dd class="mono" data-testid="tp-phone"><?= e($me['phone'] ?: '—') ?></dd></div>
    <div><dt>Age</dt><dd data-testid="tp-age"><?= e($me['age'] ?: '—') ?></dd></div>
    <div><dt>School ID</dt><dd class="mono" data-testid="tp-school-id"><?= e($schoolIdNo) ?></dd></div>
    <div><dt>Blocks handled</dt><dd data-testid="tp-blocks"><?= e($me['block_names'] ?: '—') ?></dd></div>
    <div><dt>Offerings</dt><dd data-testid="tp-offerings"><?= count($offerings) ?></dd></div>
  </dl>
</div>

<div class="card" data-testid="teacher-profile-blocks">
  <h3>Blocks you handle (<?= count($blocks) ?>)</h3>
  <?php if ($blocks): ?>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>Block</th><th>Program</th><th class="col-num">Students</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($blocks as $b): ?>
        <tr>
          <td><?= e($b['name']) ?></td>
          <td><?= e(block_academic_label($b)) ?></td>
          <td class="col-num"><?= (int)$b['student_count'] ?></td>
          <td><a class="btn-out" href="class.php?block_id=<?= (int)$b['id'] ?>">Open class</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <p class="empty">No blocks assigned. Contact the admin.</p>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
