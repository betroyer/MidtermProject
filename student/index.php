<?php
/** student/index.php — profile details (left) + interactive school ID badge (right). */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_access('student', 'profile');
$pdo = db();
ensure_user_avatar_column();
ensure_academics_schema();

$stmt = $pdo->prepare(
    'SELECT u.*, b.name AS block_name,
            d.code AS department_code, d.name AS department_name,
            c.code AS course_code, c.name AS course_name,
            pd.code AS program_dept_code, pd.name AS program_dept_name,
            pc.code AS program_code, pc.name AS program_name,
            CONCAT(t.first_name," ",t.last_name) AS teacher_name
     FROM users u
     LEFT JOIN blocks b ON b.id = u.block_id
     LEFT JOIN departments d ON d.id = b.department_id
     LEFT JOIN courses c ON c.id = b.course_id
     LEFT JOIN courses pc ON pc.id = u.program_id
     LEFT JOIN departments pd ON pd.id = pc.department_id
     LEFT JOIN users t ON t.id = b.teacher_id
     WHERE u.id = :id'
);
$stmt->execute([':id' => (int)$user['id']]);
$me = $stmt->fetch();
$_SESSION['user']['avatar'] = $me['avatar'] ?? null;

$fullName = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
$schoolName = app_setting('school_name', 'Secure SIMS');
$schoolYear = app_setting('school_year', '');
$progCode = trim((string)($me['program_code'] ?? ''));
$progName = trim((string)($me['program_name'] ?? ''));
$collegeCode = trim((string)($me['program_dept_code'] ?? $me['department_code'] ?? ''));
if ($progCode !== '' && $progName !== '') {
    $courseLine = $progCode . ' - ' . $progName;
} elseif ($progName !== '') {
    $courseLine = $progName;
} elseif ($progCode !== '') {
    $courseLine = $progCode;
} elseif ($collegeCode !== '') {
    $courseLine = $collegeCode;
} else {
    $courseLine = $me['block_name'] ?: '—';
}
$collegeLine = $collegeCode !== ''
    ? ($collegeCode . (trim((string)($me['program_dept_name'] ?? $me['department_name'] ?? '')) !== ''
        ? ' · ' . trim((string)($me['program_dept_name'] ?? $me['department_name'] ?? ''))
        : ''))
    : '—';
$schoolIdNo = trim((string)($me['school_id'] ?? ''));
if ($schoolIdNo === '') {
    $schoolIdNo = '—';
}
$avatarUrl = avatar_url($me['avatar'] ?? null, '../');
$initial = strtoupper(substr($fullName !== '' ? $fullName : '?', 0, 1));
$lc = static function (string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
};

render_header('My Profile', $user);
$schoolIdCss = @filemtime(__DIR__ . '/../css/school-id.css') ?: time();
echo '<link rel="stylesheet" href="../css/school-id.css?v=' . (int)$schoolIdCss . '">';
render_flash();
?>
<div class="page-head">
  <div>
    <h1 class="page-title">My Profile</h1>
    <p class="page-desc">Account information on the left. Your digital school ID hangs on the right — click the badge to swing it, or use the flip control for contact details.</p>
  </div>
</div>

<section class="profile-layout" data-testid="profile-layout">
  <div class="profile-main">
    <div class="card profile-identity" data-testid="profile-card">
      <div class="profile-identity-top">
        <?= avatar_img_tag($me['avatar'] ?? null, $fullName, 'avatar avatar--lg') ?>
        <div class="profile-identity-copy">
          <h2 class="profile-name"><?= e($fullName) ?></h2>
          <p class="profile-login mono"><?= e($me['username']) ?></p>
          <?php if (!empty($me['block_name']) || $courseLine !== '—'): ?>
            <p class="profile-program"><?= e($me['block_name'] ?: 'Section') ?> · <?= e($courseLine) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <form method="post" action="actions.php" enctype="multipart/form-data" class="pfp-form profile-upload" data-testid="avatar-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_avatar">
        <label class="field">
          <span>Update photo</span>
          <input class="input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required data-testid="avatar-file">
          <span class="help">JPG, PNG, or WebP · max 2&nbsp;MB. Shown on your school ID.</span>
        </label>
        <div class="actions">
          <button class="btn" type="submit" data-testid="avatar-upload">Save photo</button>
        </div>
      </form>
      <?php if (!empty($me['avatar'])): ?>
      <form method="post" action="actions.php" class="profile-remove-form" onsubmit="return confirm('Remove your profile picture?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="remove_avatar">
        <button class="btn-danger" type="submit" data-testid="avatar-remove">Remove photo</button>
      </form>
      <?php endif; ?>
    </div>

    <div class="card" data-testid="profile-details">
      <h3>Account details</h3>
      <dl class="info-grid profile-details-grid">
        <div><dt>Full name</dt><dd data-testid="p-name"><?= e($fullName) ?></dd></div>
        <div><dt>Username</dt><dd class="mono" data-testid="p-username"><?= e($me['username']) ?></dd></div>
        <div><dt>Email</dt><dd data-testid="p-email"><?= e($me['email'] ?: '—') ?></dd></div>
        <div><dt>Phone</dt><dd class="mono" data-testid="p-phone"><?= e($me['phone'] ?: '—') ?></dd></div>
        <div><dt>Age</dt><dd data-testid="p-age"><?= e($me['age'] ?: '—') ?></dd></div>
        <div><dt>Block</dt><dd data-testid="p-block"><?= e($me['block_name'] ?: '—') ?></dd></div>
        <div><dt>College</dt><dd data-testid="p-college"><?= e($collegeLine) ?></dd></div>
        <div><dt>Program</dt><dd data-testid="p-course"><?= e($courseLine) ?></dd></div>
        <div><dt>School ID no.</dt><dd class="mono" data-testid="p-school-id"><?= e($schoolIdNo) ?></dd></div>
        <div><dt>Block adviser</dt><dd data-testid="p-teacher"><?= e($me['teacher_name'] ?: '—') ?></dd></div>
      </dl>
    </div>
  </div>

  <aside class="profile-badge-col" aria-label="Digital school ID">
      <div class="id-scene" id="id-scene" data-testid="school-id-scene">
        <div class="id-anchor" aria-hidden="true"></div>
        <div class="id-pivot" id="id-pivot">
          <div class="id-lanyard" aria-hidden="true"></div>
          <div class="id-clip" aria-hidden="true"></div>
          <div class="id-card" id="id-card" role="button" tabindex="0"
               aria-label="School ID for <?= e($fullName) ?>. Click to swing. Use flip control for the back.">
            <div class="id-card-inner" id="id-card-inner">
                <div class="id-face id-face--front">
                <p class="id-school"><?= e($lc($schoolName)) ?></p>
                <div class="id-photo<?= $avatarUrl === '' ? ' id-photo--empty' : '' ?>">
                  <?php if ($avatarUrl !== ''): ?>
                    <img src="<?= e($avatarUrl) ?>" alt="" width="64" height="64" decoding="async">
                  <?php else: ?>
                    <svg class="id-photo-icon" viewBox="0 0 24 24" fill="none" stroke="#185fa5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                      <circle cx="12" cy="8" r="4"></circle>
                      <path d="M4 21c0-4 4-6 8-6s8 2 8 6"></path>
                    </svg>
                  <?php endif; ?>
                </div>
                <p class="id-name"><?= e($lc($fullName)) ?></p>
                <p class="id-meta id-meta--course"><?= e($courseLine) ?></p>
                <p class="id-meta id-meta--sid">id <?= e($schoolIdNo) ?></p>
                <div class="id-barcode" aria-hidden="true"></div>
                <button type="button" class="id-flip" id="id-flip-front" aria-label="Flip school ID to back">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M17 2.1l4 4-4 4"></path>
                    <path d="M3 12.1v-2a4 4 0 0 1 4-4h14"></path>
                    <path d="M7 21.9l-4-4 4-4"></path>
                    <path d="M21 11.9v2a4 4 0 0 1-4 4H3"></path>
                  </svg>
                </button>
              </div>
              <div class="id-face id-face--back">
                <p class="id-back-title">emergency contact</p>
                <div class="id-contact">
                  <p class="id-label">email</p>
                  <p class="id-value"><?= e($lc($me['email'] ?: '—')) ?></p>
                </div>
                <div class="id-contact">
                  <p class="id-label">phone</p>
                  <p class="id-value"><?= e($me['phone'] ?: '—') ?></p>
                </div>
                <button type="button" class="id-flip" id="id-flip-back" aria-label="Flip school ID to front">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M17 2.1l4 4-4 4"></path>
                    <path d="M3 12.1v-2a4 4 0 0 1 4-4h14"></path>
                    <path d="M7 21.9l-4-4 4-4"></path>
                    <path d="M21 11.9v2a4 4 0 0 1-4 4H3"></path>
                  </svg>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
  </aside>
</section>

<script>
(function () {
  var pivot = document.getElementById('id-pivot');
  var card = document.getElementById('id-card');
  var cardInner = document.getElementById('id-card-inner');
  if (!pivot || !card || !cardInner) return;

  var angle = 0;
  var vel = 0;
  var stiffness = 0.012;
  var damping = 0.985;
  var raf = null;
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function render() {
    if (reduceMotion) {
      pivot.style.transform = '';
      return;
    }
    pivot.style.transform = 'rotateZ(' + angle + 'rad) rotateY(' + (angle * 12) + 'deg)';
    pivot.style.transformOrigin = '0 0';
  }

  function step() {
    var accel = -stiffness * angle - (1 - damping) * vel;
    vel += accel;
    vel *= damping;
    angle += vel;
    render();
    if (Math.abs(vel) > 0.0002 || Math.abs(angle) > 0.0005) {
      raf = requestAnimationFrame(step);
    } else {
      angle = 0;
      vel = 0;
      render();
      raf = null;
    }
  }

  function kick(clientX) {
    if (reduceMotion) return;
    var rect = card.getBoundingClientRect();
    var center = rect.left + rect.width / 2;
    var offset = (clientX - center) / (rect.width / 2);
    vel += offset * 0.09 + (Math.random() - 0.5) * 0.03;
    if (!raf) raf = requestAnimationFrame(step);
  }

  card.addEventListener('click', function (e) {
    if (e.target.closest('.id-flip')) return;
    kick(e.clientX);
  });

  card.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      kick(card.getBoundingClientRect().left + card.getBoundingClientRect().width * 0.35);
    }
  });

  function toggleFlip(e) {
    e.preventDefault();
    e.stopPropagation();
    cardInner.classList.toggle('is-flipped');
  }

  var flipFront = document.getElementById('id-flip-front');
  var flipBack = document.getElementById('id-flip-back');
  if (flipFront) flipFront.addEventListener('click', toggleFlip);
  if (flipBack) flipBack.addEventListener('click', toggleFlip);

  render();
})();
</script>
<?php render_footer(); ?>
