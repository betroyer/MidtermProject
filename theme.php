<?php
/**
 * theme.php — POST handler for Dark / Light. Works from login and every role.
 */
require_once __DIR__ . '/includes/auth.php';

verify_csrf();

if (!empty($_SESSION['user']) && enforce_session_idle()) {
    redirect('index.php?e=idle');
}
if (!empty($_SESSION['user'])) {
    touch_session_activity();
}

$theme = normalize_theme($_POST['theme'] ?? 'dark');
set_current_theme($theme);

$back = $_POST['back'] ?? 'index.php';
if (
    !is_string($back)
    || $back === ''
    || str_contains($back, '..')
    || preg_match('#^(https?:)?//#i', $back)
    || str_starts_with($back, '/')
    || preg_match('#^[a-zA-Z]:#', $back)
    || strpbrk($back, "\r\n") !== false
) {
    $back = 'index.php';
}

redirect($back);
