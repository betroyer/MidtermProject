<?php
/**
 * includes/auth.php — authentication + role guards.
 * Passwords are verified against bcrypt hashes (password_verify).
 */

require_once __DIR__ . '/bootstrap.php';

/** Idle session timeout: 5 minutes without activity. */
const SESSION_IDLE_SECONDS = 300;

function ensure_user_active_column(?PDO $pdo = null): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo = $pdo ?? db();
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_active'")->fetch();
    if (!$col) {
        $pdo->exec(
            "ALTER TABLE users ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1"
        );
    }
    $ready = true;
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/** Touch last-activity timestamp (server-side idle clock). */
function touch_session_activity(): void
{
    if (!empty($_SESSION['user'])) {
        $_SESSION['last_activity'] = time();
    }
}

/**
 * End the session if the user has been idle too long.
 * Returns true when the session was expired and cleared.
 */
function enforce_session_idle(): bool
{
    if (empty($_SESSION['user'])) {
        return false;
    }
    $last = (int)($_SESSION['last_activity'] ?? 0);
    if ($last <= 0) {
        touch_session_activity();
        return false;
    }
    if ((time() - $last) > SESSION_IDLE_SECONDS) {
        if (function_exists('audit_log')) {
            try {
                audit_log('SESSION_IDLE_LOGOUT', 'session', 'Signed out after ' . SESSION_IDLE_SECONDS . 's idle');
            } catch (Throwable $e) {
                // ignore
            }
        }
        logout();
        return true;
    }
    touch_session_activity();
    return false;
}

/**
 * Attempt to log a user in. Returns the role on success, null on failure.
 * Uses a PDO prepared statement (no SQL injection) + constant-time hash check.
 */
function attempt_login(string $username, string $password): ?string
{
    ensure_user_active_column();
    $stmt = db()->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $u = $stmt->fetch();

    if ($u && password_verify($password, $u['password_hash'])) {
        if (isset($u['is_active']) && (int)$u['is_active'] !== 1) {
            audit_log('LOGIN_DENIED', 'username:' . $username, 'Account deactivated');
            $_SESSION['login_deny'] = 'deactivated';
            return null;
        }
        unset($u['password_hash']);        // never keep the hash in session
        session_regenerate_id(true);       // prevent session fixation
        $_SESSION['user'] = $u;
        $_SESSION['last_activity'] = time();
        apply_user_theme($u);
        audit_log('LOGIN_SUCCESS', 'session', 'User signed in');
        return $u['role'];
    }
    audit_log('LOGIN_FAILED', 'username:' . $username, 'Invalid credentials');
    return null;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Guard a page to a single role. Called from subfolder pages, so redirects
 * use "../" to reach the login page at the app root.
 */
function require_role(string $role): array
{
    if (enforce_session_idle()) {
        redirect('../index.php?e=idle');
    }
    $u = current_user();
    if (!$u) {
        redirect('../index.php?e=login');
    }
    if ($u['role'] !== $role) {
        redirect('../index.php?e=forbidden');
    }
    // Soft-kick deactivated accounts that still have a session.
    if (isset($u['id'])) {
        ensure_user_active_column();
        $chk = db()->prepare('SELECT is_active FROM users WHERE id = :id');
        $chk->execute([':id' => (int)$u['id']]);
        $active = $chk->fetchColumn();
        if ($active !== false && (int)$active !== 1) {
            logout();
            redirect('../index.php?e=inactive');
        }
    }
    return $u;
}

/** Landing page for a given role (relative to app root). */
function home_for(string $role): string
{
    $map = [
        'admin' => [
            ['dashboard', 'admin/index.php'],
            ['academics', 'admin/academics.php'],
            ['reports', 'admin/reports.php'],
            ['users', 'admin/teachers.php'],
            ['students', 'admin/students.php'],
            ['audit_log', 'admin/audit.php'],
            ['roles', 'admin/settings.php'],
        ],
        'teacher' => [
            ['students', 'teacher/index.php'],
            ['reports', 'teacher/reports.php'],
        ],
        'student' => [
            ['profile', 'student/index.php'],
            ['grades', 'student/grades.php'],
        ],
    ];

    foreach ($map[$role] ?? [] as $entry) {
        if (role_can($role, $entry[0])) {
            return $entry[1];
        }
    }

    if ($role === 'admin') {
        return 'admin/settings.php';
    }
    if ($role === 'teacher') {
        return 'teacher/index.php';
    }
    if ($role === 'student') {
        return 'student/index.php';
    }
    return 'index.php';
}
