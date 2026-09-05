<?php
/**
 * includes/bootstrap.php — session start, DB accessor, helpers, CSRF.
 * Included by every dynamic page.
 */

if (session_status() === PHP_SESSION_NONE) {
    // Keep one session cookie for the whole site (spaces in folder names are fine).
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/../config/db.php';

/** Shared PDO instance (dies with a friendly message if DB is down). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = db_connect();
        if (!$pdo) {
            http_response_code(500);
            die('Database connection failed. Make sure MySQL is running in XAMPP and that database.sql has been imported.');
        }
        if (function_exists('ensure_rbac_schema')) {
            ensure_rbac_schema($pdo);
        }
        if (function_exists('ensure_user_theme_column')) {
            ensure_user_theme_column($pdo);
        }
        if (function_exists('ensure_user_avatar_column')) {
            ensure_user_avatar_column($pdo);
        }
        if (function_exists('ensure_user_school_id_column')) {
            ensure_user_school_id_column($pdo);
        }
        if (function_exists('ensure_user_active_column')) {
            ensure_user_active_column($pdo);
        }
        if (function_exists('ensure_audit_schema')) {
            ensure_audit_schema($pdo);
        }
        if (function_exists('ensure_grading_schema')) {
            ensure_grading_schema($pdo);
        }
    }
    return $pdo;
}

/** HTML-escape helper. */
function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** Redirect + stop. */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** CSRF token for this session. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

/** Reject POSTs without a valid CSRF token. */
function verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $token = $_POST['csrf'] ?? '';
    $session = $_SESSION['csrf'] ?? '';
    if ($session === '' || $token === '' || !hash_equals($session, $token)) {
        http_response_code(419);
        $back = htmlspecialchars($_SERVER['HTTP_REFERER'] ?? '../index.php', ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Form expired</title>';
        echo '<link rel="stylesheet" href="' . (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/teacher/') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/student/') ? '../css/app.css' : 'css/app.css') . '">';
        echo '</head><body style="padding:40px;font-family:Space Grotesk,sans-serif">';
        echo '<div class="flash flash--error">Invalid or expired form token. Please go back, refresh the page, and try again.</div>';
        echo '<p><a class="btn" href="' . $back . '">Go back</a></p></body></html>';
        exit;
    }
}

require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/theme.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/avatar.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/grading.php';
require_once __DIR__ . '/academics.php';

// Ensure academic catalog + academics permission exist for admin nav.
if (function_exists('ensure_academics_schema')) {
    try {
        ensure_academics_schema();
    } catch (Throwable $e) {
        // DB may not be ready during first install
    }
}
