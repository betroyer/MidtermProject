<?php
/**
 * index.php — Login page (front page). Authenticates admin / teacher / student
 * and redirects to the matching dashboard.
 */
require_once __DIR__ . '/includes/auth.php';

$schoolName = app_setting('school_name', 'Secure SIMS');
$loginMessage = app_setting('login_message', 'Sign in with your username and school ID.');
$schoolYear = app_setting('school_year', '2025-2026');
$supportEmail = app_setting('support_email', 'admin@gmail.com');

// Already logged in? Go straight to the dashboard (unless session went idle).
$u = current_user();
if ($u) {
    if (enforce_session_idle()) {
        redirect('index.php?e=idle');
    }
    redirect(home_for($u['role']));
}

$error = '';
$showLockModal = false;
$lockUntil = '';
$attemptsLeft = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '' || $password === '') {
        $error = 'Please enter both your username and password.';
    } else {
        // Pre-check lock so we can show the modal even before attempt_login
        $pre = login_lockout_status($username);
        if ($pre['locked']) {
            $showLockModal = true;
            $lockUntil = (string)($pre['until'] ?? '');
            $error = 'This account is temporarily locked after too many failed sign-in attempts.';
        } else {
            $role = attempt_login($username, $password);
            if ($role) {
                redirect(home_for($role));
            }
            $deny = $_SESSION['login_deny'] ?? '';
            unset($_SESSION['login_deny']);
            if ($deny === 'deactivated') {
                $error = 'This account has been deactivated. Contact the admin.';
            } elseif ($deny === 'locked') {
                $showLockModal = true;
                $lockUntil = (string)($_SESSION['login_locked_until'] ?? '');
                unset($_SESSION['login_locked_until']);
                $error = 'Too many failed attempts. This account is locked for 24 hours.';
            } elseif ($deny === 'failed') {
                $attemptsLeft = isset($_SESSION['login_attempts_left'])
                    ? (int)$_SESSION['login_attempts_left']
                    : null;
                unset($_SESSION['login_attempts_left']);
                if ($attemptsLeft !== null && $attemptsLeft > 0) {
                    $error = 'Invalid username or password. '
                        . $attemptsLeft . ' attempt' . ($attemptsLeft === 1 ? '' : 's')
                        . ' remaining before a 24-hour lock.';
                } else {
                    $error = 'Invalid username or password.';
                }
            } else {
                $error = 'Invalid username or password.';
            }
        }
    }
} elseif (($_GET['e'] ?? '') === 'forbidden') {
    $error = 'You are not allowed to open that page with your role.';
} elseif (($_GET['e'] ?? '') === 'inactive') {
    $error = 'This account has been deactivated. Contact the admin.';
} elseif (($_GET['e'] ?? '') === 'idle') {
    $error = 'You were signed out after 5 minutes of inactivity. Please sign in again.';
} elseif (($_GET['e'] ?? '') === 'login') {
    $error = 'Please sign in to continue.';
}

$postedUser = trim($_POST['username'] ?? '');
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= e(current_theme()) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login · <?= e($schoolName) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/app.css">
  <link rel="stylesheet" href="css/modal.css?v=<?= (int)(@filemtime(__DIR__ . '/css/modal.css') ?: time()) ?>">
</head>
<body data-testid="login-body">
  <?= theme_switch_form('theme.php', 'theme-switch--login') ?>
  <div class="login-wrap">
    <div class="login-card" data-testid="login-card">
      <div class="login-logo"><span class="dot"></span>
        <h1 class="login-title"><?= e($schoolName) ?></h1>
      </div>
      <p class="login-sub"><?= e($loginMessage) ?></p>
      <p class="login-meta">School year <?= e($schoolYear) ?> · Support: <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a></p>

      <?php if ($error): ?>
        <p class="error-text" data-testid="login-error"><?= e($error) ?></p>
      <?php endif; ?>

      <form method="post" action="index.php" data-testid="login-form" autocomplete="off">
        <?= csrf_field() ?>
        <div class="field">
          <label for="username">Username</label>
          <input class="input" id="username" name="username" type="text"
                 value="<?= e($postedUser) ?>"
                 placeholder="e.g. B_Delossantos" data-testid="login-username" required
                 <?= $showLockModal ? '' : '' ?>>
        </div>
        <div class="field">
          <label for="password">Password (School ID)</label>
          <input class="input" id="password" name="password" type="password"
                 placeholder="e.g. 2026-00200" data-testid="login-password" required>
        </div>
        <button class="btn btn-block" type="submit" data-testid="login-submit">Sign in</button>
      </form>

      <div class="login-foot"><a href="walkthrough.php" data-testid="walkthrough-link">View 3D Security Walkthrough →</a></div>
    </div>
  </div>

<?php if ($showLockModal): ?>
  <div class="ui-modal-root" id="lockout-modal" data-testid="login-lockout-modal" role="presentation">
    <button type="button" class="ui-modal-backdrop" aria-label="Close dialog" data-lock-dismiss></button>
    <div class="ui-modal-panel" role="dialog" aria-modal="true" aria-labelledby="lockout-title" tabindex="-1">
      <div class="ui-modal-head">
        <div>
          <h2 class="ui-modal-title" id="lockout-title">Account temporarily locked</h2>
          <p class="ui-modal-sub">Too many failed sign-in attempts</p>
        </div>
        <button type="button" class="ui-modal-close" aria-label="Close" data-lock-dismiss data-testid="lockout-close">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
      </div>
      <div class="ui-modal-body">
        <p style="margin:0 0 12px;line-height:1.5">
          You entered the wrong credentials <strong>3 times</strong>.
          For security, this account is locked for <strong>24 hours</strong>.
        </p>
        <p style="margin:0;line-height:1.5;color:var(--ink-dim)">
          Please wait 24 hours, then you can log in again
          <?php if ($lockUntil !== ''): ?>
            (available after <strong class="mono"><?= e($lockUntil) ?></strong>)
          <?php endif; ?>.
          If you need help sooner, contact support at
          <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a>.
        </p>
      </div>
      <div class="ui-modal-foot">
        <button type="button" class="btn" data-lock-dismiss data-testid="lockout-ok">I understand</button>
      </div>
    </div>
  </div>
  <script>
  (function () {
    var root = document.getElementById('lockout-modal');
    if (!root) return;
    document.body.classList.add('ui-modal-open');
    var panel = root.querySelector('.ui-modal-panel');
    if (panel) panel.focus();
    function close() {
      root.hidden = true;
      document.body.classList.remove('ui-modal-open');
    }
    root.querySelectorAll('[data-lock-dismiss]').forEach(function (el) {
      el.addEventListener('click', close);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !root.hidden) close();
    });
  })();
  </script>
<?php endif; ?>
</body>
</html>
