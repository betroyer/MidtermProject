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

$subjectsStmt = $pdo->prepare(
    'SELECT o.name AS section_name, s.code AS subject_code, s.name AS subject_name, s.kind,
            CONCAT(t.first_name, " ", t.last_name) AS teacher_name
     FROM enrollments e
     JOIN subject_offerings o ON o.id = e.offering_id
     JOIN subjects s ON s.id = o.subject_id
     JOIN users t ON t.id = o.teacher_id
     WHERE e.student_id = :id AND o.is_active = 1 AND s.is_active = 1
     ORDER BY s.code'
);
$subjectsStmt->execute([':id' => (int)$user['id']]);
$mySubjects = $subjectsStmt->fetchAll();

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
    // Last resort: assign an ID so the badge never shows blank
    require_once __DIR__ . '/../includes/validation.php';
    $schoolIdNo = generate_next_school_id($pdo);
    $pdo->prepare('UPDATE users SET school_id = :s WHERE id = :id AND (school_id IS NULL OR school_id = "")')
        ->execute([':s' => $schoolIdNo, ':id' => (int)$me['id']]);
    $me['school_id'] = $schoolIdNo;
}
require_once __DIR__ . '/../includes/qr.php';
$qrUrl = school_id_qr_url($schoolIdNo, 140);
$idCardRevealed = !empty($_SESSION['id_card_revealed_until'])
    && (int)$_SESSION['id_card_revealed_until'] > time();
if (!$idCardRevealed) {
    unset($_SESSION['id_card_revealed_until']);
}
$displayLast = strtoupper(trim((string)($me['last_name'] ?? '')));
$displayFirst = trim((string)($me['first_name'] ?? ''));
$idTitle = $progCode !== ''
    ? ($progCode . ($progName !== '' ? ' · ' . $progName : ''))
    : ($courseLine !== '—' ? $courseLine : 'Student');
$yearLabel = year_level_label(isset($me['year_level']) ? (int)$me['year_level'] : null);
if ($schoolYear === '') {
    $schoolYear = (string)date('Y');
}
$avatarUrl = avatar_url($me['avatar'] ?? null, '../');
$initial = strtoupper(substr($fullName !== '' ? $fullName : '?', 0, 1));
$lc = static function (string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
};

render_header('My Profile', $user);
$schoolIdCss = @filemtime(__DIR__ . '/../css/school-id.css') ?: time();
$modalCss = @filemtime(__DIR__ . '/../css/modal.css') ?: time();
echo '<link rel="stylesheet" href="../css/school-id.css?v=' . (int)$schoolIdCss . '">';
echo '<link rel="stylesheet" href="../css/modal.css?v=' . (int)$modalCss . '">';
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
        <div><dt>Full name</dt><dd data-testid="p-name"><?= e(format_person_name($me)) ?></dd></div>
        <div><dt>Username</dt><dd class="mono" data-testid="p-username"><?= e($me['username']) ?></dd></div>
        <div><dt>Email</dt><dd data-testid="p-email"><?= e($me['email'] ?: '—') ?></dd></div>
        <div><dt>Phone</dt><dd class="mono" data-testid="p-phone"><?= e($me['phone'] ?: '—') ?></dd></div>
        <div><dt>Age</dt><dd data-testid="p-age"><?= e($me['age'] ?: '—') ?></dd></div>
        <div><dt>Address</dt><dd data-testid="p-address"><?= e($me['address'] ?: '—') ?></dd></div>
        <div><dt>Block</dt><dd data-testid="p-block"><?= e($me['block_name'] ?: '—') ?></dd></div>
        <div><dt>College</dt><dd data-testid="p-college"><?= e($collegeLine) ?></dd></div>
        <div><dt>Program</dt><dd data-testid="p-course"><?= e($courseLine) ?></dd></div>
        <div><dt>Year level</dt><dd data-testid="p-year"><?= e(year_level_label(isset($me['year_level']) ? (int)$me['year_level'] : null)) ?></dd></div>
        <div><dt>School ID no.</dt><dd class="mono" data-testid="p-school-id"><?= $idCardRevealed ? e($schoolIdNo) : '••••••••••' ?>
          <?php if (!$idCardRevealed): ?>
            <button type="button" class="btn-out" style="margin-left:8px;padding:4px 10px;font-size:12px" id="p-reveal-btn" data-testid="p-reveal-btn">Reveal</button>
          <?php endif; ?>
        </dd></div>
        <div><dt>Block adviser</dt><dd data-testid="p-teacher"><?= e($me['teacher_name'] ?: '—') ?></dd></div>
      </dl>
    </div>

    <div class="card" data-testid="emergency-contact-card">
      <h3>Emergency contact</h3>
      <dl class="info-grid profile-details-grid">
        <div><dt>Name</dt><dd><?= e($me['emergency_name'] ?: '—') ?></dd></div>
        <div><dt>Relationship</dt><dd><?= e(emergency_relation_label($me['emergency_relation'] ?? '')) ?></dd></div>
        <div><dt>Address</dt><dd><?= e($me['emergency_address'] ?: '—') ?></dd></div>
        <div><dt>Phone</dt><dd class="mono"><?= e($me['emergency_phone'] ?: '—') ?></dd></div>
      </dl>
    </div>

    <div class="card" data-testid="my-subjects-card">
      <h3>My Subjects</h3>
      <p class="help" style="margin-bottom:12px">
        Year-level subjects applied at enrollment.
        Open <a href="schedule.php">My Schedule</a> for class times.
      </p>
      <?php if ($mySubjects): ?>
      <div class="table-wrap">
        <table class="table" data-testid="my-subjects-table">
          <thead>
            <tr><th>Subject</th><th>Type</th><th>Section</th><th>Teacher</th></tr>
          </thead>
          <tbody>
          <?php foreach ($mySubjects as $sub): ?>
            <tr>
              <td><?= e($sub['subject_code'] . ' — ' . $sub['subject_name']) ?></td>
              <td><span class="tag"><?= e(ucfirst((string)($sub['kind'] ?? 'major'))) ?></span></td>
              <td><?= e($sub['section_name'] ?: '—') ?></td>
              <td><?= e($sub['teacher_name']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <p class="empty">You are not enrolled in any subject offerings yet.</p>
      <?php endif; ?>
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
                <div class="id-corner id-corner--tl" aria-hidden="true"></div>
                <div class="id-corner id-corner--br" aria-hidden="true"></div>
                <p class="id-logo"><?= e($schoolName) ?></p>
                <div class="id-photo<?= $avatarUrl === '' ? ' id-photo--empty' : '' ?>">
                  <?php if ($avatarUrl !== ''): ?>
                    <img src="<?= e($avatarUrl) ?>" alt="" width="88" height="88" decoding="async">
                  <?php else: ?>
                    <svg class="id-photo-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                      <circle cx="12" cy="8" r="4"></circle>
                      <path d="M4 21c0-4 4-6 8-6s8 2 8 6"></path>
                    </svg>
                  <?php endif; ?>
                </div>
                <p class="id-name"><?= e($displayLast !== '' ? $displayLast : strtoupper($fullName)) ?></p>
                <p class="id-title"><?= e($idTitle) ?></p>
                <div class="id-fields id-secure-block<?= $idCardRevealed ? ' is-revealed' : '' ?>" id="id-secure-block" data-testid="id-secure-block">
                  <?php if ($idCardRevealed): ?>
                    <p><span>ID No</span> : <strong class="mono" id="id-secure-sid"><?= e($schoolIdNo) ?></strong></p>
                    <p><span>Year</span> : <strong id="id-secure-year"><?= e($yearLabel) ?></strong></p>
                  <?php else: ?>
                    <p><span>ID No</span> : <strong class="mono id-masked" id="id-secure-sid">••••••••••</strong></p>
                    <p><span>Year</span> : <strong class="id-masked" id="id-secure-year">••••</strong></p>
                    <button type="button" class="id-reveal-btn" id="id-reveal-btn" data-testid="id-reveal-btn">
                      Reveal secure details
                    </button>
                  <?php endif; ?>
                </div>
                <div class="id-qr-wrap<?= $idCardRevealed ? '' : ' id-qr-wrap--locked' ?>" id="id-qr-wrap">
                  <?php if ($idCardRevealed): ?>
                    <img class="id-qr" id="id-qr-img" src="<?= e($qrUrl) ?>" width="72" height="72"
                         alt="QR code for school ID"
                         loading="lazy" decoding="async" data-testid="id-qr">
                  <?php else: ?>
                    <div class="id-qr-lock" id="id-qr-lock" aria-hidden="true" data-testid="id-qr-locked">
                      <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="5" y="11" width="14" height="10" rx="2"></rect>
                        <path d="M8 11V8a4 4 0 0 1 8 0v3"></path>
                      </svg>
                      <span>Hidden</span>
                    </div>
                    <img class="id-qr" id="id-qr-img" hidden width="72" height="72" alt="QR code for school ID" data-testid="id-qr">
                  <?php endif; ?>
                </div>
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
                <div class="id-corner id-corner--tl" aria-hidden="true"></div>
                <div class="id-corner id-corner--br" aria-hidden="true"></div>
                <p class="id-logo"><?= e($schoolName) ?></p>
                <ul class="id-bullets">
                  <li>Present this ID when requested on campus.</li>
                  <li>Lost card? Report to the registrar immediately.</li>
                </ul>
                <div class="id-back-fields">
                  <p><span>SY</span> <strong><?= e($schoolYear) ?></strong></p>
                  <p><span>Block</span> <strong><?= e($me['block_name'] ?: '—') ?></strong></p>
                  <p><span>Phone</span> <strong class="mono"><?= e($me['phone'] ?: '—') ?></strong></p>
                  <p><span>E-mail</span> <strong><?= e($me['email'] ?: '—') ?></strong></p>
                </div>
                <div class="id-sign">
                  <p class="id-sign-name"><?= e($displayFirst . ' ' . $displayLast) ?></p>
                  <p class="id-sign-label">Student</p>
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

<div class="ui-modal-root" id="id-reveal-modal" hidden data-testid="id-reveal-modal">
  <button type="button" class="ui-modal-backdrop" aria-label="Close dialog" data-reveal-dismiss></button>
  <div class="ui-modal-panel" role="dialog" aria-modal="true" aria-labelledby="id-reveal-title" tabindex="-1">
    <div class="ui-modal-head">
      <div>
        <h2 class="ui-modal-title" id="id-reveal-title">Password confirmation</h2>
        <p class="ui-modal-sub">High-security check before showing ID details</p>
      </div>
      <button type="button" class="ui-modal-close" aria-label="Close" data-reveal-dismiss>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    </div>
    <form id="id-reveal-form" class="ui-modal-body" autocomplete="off">
      <p style="margin:0 0 14px;line-height:1.5">Please enter your password to view secure details.</p>
      <div class="field">
        <label for="id-reveal-password">Password</label>
        <input class="input" type="password" id="id-reveal-password" name="password" required
               data-autofocus data-testid="id-reveal-password" placeholder="Your login password">
      </div>
      <p class="error-text" id="id-reveal-error" hidden data-testid="id-reveal-error" style="margin-top:10px"></p>
    </form>
    <div class="ui-modal-foot">
      <button type="button" class="btn-out" data-reveal-dismiss>Cancel</button>
      <button type="submit" form="id-reveal-form" class="btn" data-testid="id-reveal-submit">Confirm &amp; reveal</button>
    </div>
  </div>
</div>

<script>
(function () {
  var pivot = document.getElementById('id-pivot');
  var card = document.getElementById('id-card');
  var cardInner = document.getElementById('id-card-inner');
  if (!pivot || !card || !cardInner) return;

  var angle = 0;
  var vel = 0;
  var stiffness = 0.014;
  var damping = 0.982;
  var maxAngle = 0.42; // ~24deg
  var raf = null;
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var lastScrollY = window.scrollY || window.pageYOffset || 0;
  var scrollTicking = false;

  function render() {
    if (reduceMotion) {
      pivot.style.transform = '';
      return;
    }
    pivot.style.transform = 'rotateZ(' + angle + 'rad) rotateY(' + (angle * 10) + 'deg)';
    pivot.style.transformOrigin = '0 0';
  }

  function ensureLoop() {
    if (!raf) raf = requestAnimationFrame(step);
  }

  function step() {
    var accel = -stiffness * angle - (1 - damping) * vel;
    vel += accel;
    vel *= damping;
    angle += vel;
    if (angle > maxAngle) { angle = maxAngle; vel *= 0.4; }
    if (angle < -maxAngle) { angle = -maxAngle; vel *= 0.4; }
    render();
    if (Math.abs(vel) > 0.00015 || Math.abs(angle) > 0.0004) {
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
    var offset = (clientX - center) / Math.max(1, rect.width / 2);
    vel += offset * 0.09 + (Math.random() - 0.5) * 0.03;
    ensureLoop();
  }

  function onScrollImpulse() {
    scrollTicking = false;
    if (reduceMotion) return;
    var y = window.scrollY || window.pageYOffset || 0;
    var dy = y - lastScrollY;
    lastScrollY = y;
    if (Math.abs(dy) < 0.4) return;
    vel += dy * 0.0022;
    if (vel > 0.16) vel = 0.16;
    if (vel < -0.16) vel = -0.16;
    ensureLoop();
  }

  window.addEventListener('scroll', function () {
    if (scrollTicking || reduceMotion) return;
    scrollTicking = true;
    requestAnimationFrame(onScrollImpulse);
  }, { passive: true });

  card.addEventListener('click', function (e) {
    if (e.target.closest('.id-flip') || e.target.closest('.id-reveal-btn')) return;
    kick(e.clientX);
  });

  card.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ' ') {
      if (document.activeElement && document.activeElement.id === 'id-reveal-btn') return;
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

(function () {
  var modal = document.getElementById('id-reveal-modal');
  var form = document.getElementById('id-reveal-form');
  var passInput = document.getElementById('id-reveal-password');
  var errEl = document.getElementById('id-reveal-error');
  var csrf = <?= json_encode(csrf_token()) ?>;
  var yearLabel = <?= json_encode($yearLabel) ?>;
  if (!modal || !form) return;

  function openReveal(e) {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }
    modal.hidden = false;
    document.body.classList.add('ui-modal-open');
    if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
    if (passInput) { passInput.value = ''; passInput.focus(); }
  }

  function closeReveal() {
    modal.hidden = true;
    document.body.classList.remove('ui-modal-open');
  }

  var revealBtn = document.getElementById('id-reveal-btn');
  var pReveal = document.getElementById('p-reveal-btn');
  if (revealBtn) revealBtn.addEventListener('click', openReveal);
  if (pReveal) pReveal.addEventListener('click', openReveal);

  modal.querySelectorAll('[data-reveal-dismiss]').forEach(function (el) {
    el.addEventListener('click', closeReveal);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) closeReveal();
  });

  function applyReveal(data) {
    var block = document.getElementById('id-secure-block');
    var sidEl = document.getElementById('id-secure-sid');
    var yearEl = document.getElementById('id-secure-year');
    var wrap = document.getElementById('id-qr-wrap');
    var lock = document.getElementById('id-qr-lock');
    var img = document.getElementById('id-qr-img');
    var btn = document.getElementById('id-reveal-btn');
    var pSid = document.querySelector('[data-testid="p-school-id"]');

    if (sidEl) {
      sidEl.textContent = data.school_id;
      sidEl.classList.remove('id-masked');
    }
    if (yearEl) {
      yearEl.textContent = yearLabel;
      yearEl.classList.remove('id-masked');
    }
    if (block) block.classList.add('is-revealed');
    if (btn) btn.remove();
    if (wrap) wrap.classList.remove('id-qr-wrap--locked');
    if (lock) lock.remove();
    if (img) {
      img.hidden = false;
      img.src = data.qr_url;
    }
    if (pSid) {
      pSid.textContent = data.school_id;
      var pr = document.getElementById('p-reveal-btn');
      if (pr) pr.remove();
    }
    closeReveal();
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
    var fd = new FormData();
    fd.append('action', 'reveal_id_card');
    fd.append('csrf', csrf);
    fd.append('password', passInput ? passInput.value : '');
    var submitBtn = document.querySelector('[data-testid="id-reveal-submit"]');
    if (submitBtn) submitBtn.disabled = true;

    fetch('actions.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (res) {
        return res.json().then(function (data) {
          return { status: res.status, data: data };
        });
      })
      .then(function (result) {
        if (submitBtn) submitBtn.disabled = false;
        if (!result.data || !result.data.ok) {
          if (errEl) {
            errEl.textContent = (result.data && result.data.error) || 'Could not verify password.';
            errEl.hidden = false;
          }
          return;
        }
        applyReveal(result.data);
      })
      .catch(function () {
        if (submitBtn) submitBtn.disabled = false;
        if (errEl) {
          errEl.textContent = 'Network error. Try again.';
          errEl.hidden = false;
        }
      });
  });
})();
</script>
<?php render_footer(); ?>
