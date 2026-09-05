<?php
/**
 * includes/layout.php — dashboard chrome (header/nav/footer) for role pages.
 * Role pages live one level deep, so links use "../".
 */

function render_header(string $title, array $user): void
{
    $role = $user['role'];
    $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $brand = app_setting('school_name', 'Secure SIMS');

    $nav = nav_items_for($role);

    $current = basename($_SERVER['SCRIPT_NAME']);
    echo '<!DOCTYPE html><html lang="en" data-theme="' . e(current_theme()) . '"><head>';
    echo '<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . e($title) . ' · ' . e($brand) . '</title>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">';
    $cssVer = @filemtime(__DIR__ . '/../css/app.css') ?: time();
    echo '<link rel="stylesheet" href="../css/app.css?v=' . (int)$cssVer . '">';
    echo '</head><body data-testid="app-body">';

    echo '<header class="app-top" data-testid="app-topbar">';
    echo '<div class="app-brand"><span class="app-dot app-dot--' . e($role) . '"></span>';
    echo '<div><div class="app-brand-title">' . e($brand) . '</div>';
    echo '<div class="app-brand-sub">' . e(ucfirst($role)) . ' Panel</div></div></div>';

    echo '<nav class="app-nav" data-testid="app-nav">';
    foreach ($nav as $item) {
        $active = $item[0] === $current ? ' active' : '';
        echo '<a class="nav-link' . $active . '" data-testid="nav-' . e(strtolower(preg_replace('/\s+/', '-', $item[1]))) . '" href="' . e($item[0]) . '">' . e($item[1]) . '</a>';
    }
    echo '</nav>';

    echo '<div class="app-user">';
    echo theme_switch_form('../theme.php');
    echo avatar_img_tag($user['avatar'] ?? null, $name, 'avatar avatar--sm');
    echo '<span class="app-user-name" data-testid="current-user">' . e($name) . '</span>';
    echo '<span class="role-pill role-pill--' . e($role) . '">' . e(ucfirst($role)) . '</span>';
    echo '<a class="btn-out" data-testid="logout-btn" href="../logout.php">Logout</a></div>';
    echo '</header>';

    echo '<main class="app-main">';
}

function render_footer(): void
{
    echo '</main>';
    echo '<footer class="app-foot">Secure SIMS · passwords stored as bcrypt hashes · all queries use PDO prepared statements · ';
    echo '<a href="../walkthrough.php">View 3D Security Walkthrough →</a></footer>';
    $jsVer = @filemtime(__DIR__ . '/../js/idle-logout.js') ?: time();
    $idleMs = defined('SESSION_IDLE_SECONDS') ? (SESSION_IDLE_SECONDS * 1000) : 300000;
    echo '<script>window.SIMS_IDLE=' . json_encode([
        'idleMs' => $idleMs,
        'warnMs' => max(60000, $idleMs - 60000),
        'logoutUrl' => '../logout.php?reason=idle',
        'pingUrl' => '../api/session_ping.php',
    ], JSON_UNESCAPED_SLASHES) . ';</script>';
    echo '<script src="../js/idle-logout.js?v=' . (int)$jsVer . '" defer></script>';
    echo '</body></html>';
}

/** Flash message helper (stored in session, shown once). */
function set_flash(string $type, string $msg): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function render_flash(): void
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="flash flash--' . e($f['type']) . '" data-testid="flash">' . e($f['msg']) . '</div>';
    }
}
