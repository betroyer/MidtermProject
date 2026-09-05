<?php
/**
 * includes/theme.php — Dark / Light appearance for every user.
 * Cookie applies immediately (login + dashboards). Logged-in users also
 * store the choice on their account so it follows them on the next login.
 */

function ensure_user_theme_column(?PDO $pdo = null): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo = $pdo ?? db();
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'theme'")->fetch();
    if (!$col) {
        $pdo->exec(
            "ALTER TABLE users ADD COLUMN `theme` ENUM('dark','light') NOT NULL DEFAULT 'dark'"
        );
    }
    $ready = true;
}

function normalize_theme(?string $theme): string
{
    return $theme === 'light' ? 'light' : 'dark';
}

function current_theme(): string
{
    $cookie = $_COOKIE['sims_theme'] ?? '';
    if ($cookie === 'light' || $cookie === 'dark') {
        return $cookie;
    }
    $user = $_SESSION['user'] ?? null;
    if (is_array($user) && isset($user['theme'])) {
        return normalize_theme((string)$user['theme']);
    }
    return 'dark';
}

function persist_theme_cookie(string $theme): void
{
    $theme = normalize_theme($theme);
    $_COOKIE['sims_theme'] = $theme;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie('sims_theme', $theme, [
        'expires'  => time() + 86400 * 400,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

/** Save theme for this browser and, if logged in, on the user row. */
function set_current_theme(string $theme): void
{
    $theme = normalize_theme($theme);
    persist_theme_cookie($theme);

    $user = $_SESSION['user'] ?? null;
    if (!is_array($user) || empty($user['id'])) {
        return;
    }
    ensure_user_theme_column();
    $stmt = db()->prepare('UPDATE users SET theme = :t WHERE id = :id');
    $stmt->execute([':t' => $theme, ':id' => (int)$user['id']]);
    $_SESSION['user']['theme'] = $theme;
}

function apply_user_theme(array $user): void
{
    if (isset($user['theme'])) {
        persist_theme_cookie(normalize_theme((string)$user['theme']));
    }
}

/**
 * Path back to the current page, relative to the app root (theme.php).
 * Avoids absolute "/..." URLs that some shells rewrite.
 */
function current_app_relative(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    $file = basename($script);
    $folder = basename(dirname($script));
    if (in_array($folder, ['admin', 'teacher', 'student'], true)) {
        $rel = $folder . '/' . $file;
    } else {
        $rel = $file !== '' ? $file : 'index.php';
    }
    $query = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
    if (is_string($query) && $query !== '') {
        $rel .= '?' . $query;
    }
    return $rel;
}

/**
 * Dark / Light control. $action is the form action (theme.php or ../theme.php).
 */
function theme_switch_form(string $action, string $extraClass = ''): string
{
    $theme = current_theme();
    $back = current_app_relative();
    $html = '<form method="post" action="' . e($action) . '" class="theme-switch'
        . ($extraClass !== '' ? ' ' . e($extraClass) : '')
        . '" data-testid="theme-switch">';
    $html .= csrf_field();
    $html .= '<input type="hidden" name="back" value="' . e($back) . '">';
    foreach (['dark' => 'Dark', 'light' => 'Light'] as $value => $label) {
        $active = $theme === $value ? ' active' : '';
        $html .= '<button type="submit" name="theme" value="' . $value . '"'
            . ' class="theme-btn' . $active . '"'
            . ' data-testid="theme-' . $value . '"'
            . ' aria-pressed="' . ($theme === $value ? 'true' : 'false') . '">'
            . $label . '</button>';
    }
    $html .= '</form>';
    return $html;
}
