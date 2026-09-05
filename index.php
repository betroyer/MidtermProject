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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '' || $password === '') {
        $error = 'Please enter both your username and password.';
    } else {
        $role = attempt_login($username, $password);
        if ($role) {
            redirect(home_for($role));
        }
        if (($_SESSION['login_deny'] ?? '') === 'deactivated') {
            unset($_SESSION['login_deny']);
            $error = 'This account has been deactivated. Contact the admin.';
        } else {
            $error = 'Invalid username or password.';
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
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= e(current_theme()) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login · <?= e($schoolName) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/app.css">
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
                 placeholder="e.g. B_Delossantos" data-testid="login-username" required>
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
</body>
</html>
