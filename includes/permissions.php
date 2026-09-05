<?php
/**
 * includes/permissions.php — role-permission matrix for RBAC.
 * Tables are created automatically if missing (existing XAMPP DBs keep working).
 */

/** Permissions that cannot be taken away (prevents lock-out). */
function locked_permissions_for(string $role): array
{
    if ($role === 'admin') {
        return ['roles'];
    }
    return [];
}

function ensure_rbac_schema(?PDO $pdo = null): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    if ($pdo === null) {
        $pdo = db();
        if ($ready) {
            return;
        }
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `roles` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(60) NOT NULL,
            `code` VARCHAR(20) NOT NULL,
            `color` VARCHAR(16) NOT NULL,
            `description` VARCHAR(255) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_roles_code` (`code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `permissions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(40) NOT NULL,
            `label` VARCHAR(80) NOT NULL,
            `description` VARCHAR(255) NOT NULL,
            `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_permissions_code` (`code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `role_permissions` (
            `role_id` INT UNSIGNED NOT NULL,
            `permission_id` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`role_id`, `permission_id`),
            CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `app_settings` (
            `setting_key` VARCHAR(64) NOT NULL,
            `setting_value` VARCHAR(255) NOT NULL,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    if ((int)$pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn() === 0) {
        $pdo->exec(
            "INSERT INTO roles (id, name, code, color, description) VALUES
              (1, 'Administrator', 'admin',   '#ff4d5e', 'Full control: users, blocks, and system settings.'),
              (2, 'Teacher / Staff', 'teacher', '#4d9bff', 'Manages grades and reports for assigned blocks.'),
              (3, 'Student',        'student', '#43d17a', 'Views own profile and grades only.')"
        );
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn() === 0) {
        $pdo->exec(
            "INSERT INTO permissions (id, code, label, description, sort_order) VALUES
              (1, 'dashboard',  'Dashboard / Blocks', 'Admin landing: create and view class blocks.', 1),
              (2, 'profile',    'My Profile',         'Student profile page (own account only).', 2),
              (3, 'students',   'Students',           'Admin student accounts and teacher roster.', 3),
              (4, 'grades',     'Grades',             'Teacher grade computation and student grade view.', 4),
              (5, 'attendance', 'Attendance',         'Reserved module — also used by the 3D walkthrough.', 5),
              (6, 'reports',    'Reports',            'Teacher class reports.', 6),
              (7, 'users',      'Teachers / Users',   'Admin teacher account management.', 7),
              (8, 'roles',      'Roles & Permissions','Admin Settings: manage system options and RBAC.', 8),
              (9, 'audit_log',  'Audit Log',          'View tamper-evident security and activity events.', 9)"
        );
    } else {
        $pdo->exec("UPDATE permissions SET label='Audit Log', description='View tamper-evident security and activity events.' WHERE code='audit_log'");
        $pdo->exec("UPDATE permissions SET description='Admin Settings: manage system options and RBAC.' WHERE code='roles'");
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM role_permissions')->fetchColumn() === 0) {
        $pdo->exec(
            'INSERT INTO role_permissions (role_id, permission_id) VALUES
              (1,1),(1,2),(1,3),(1,4),(1,5),(1,6),(1,7),(1,8),(1,9),
              (2,1),(2,2),(2,3),(2,4),(2,5),(2,6),
              (3,1),(3,2),(3,4)'
        );
    } else {
        // Keep admin able to open Audit Logs on upgraded databases.
        $pdo->exec(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
             WHERE r.code = "admin" AND p.code = "audit_log"'
        );
    }

    $defaults = [
        'school_name'     => 'Secure SIMS',
        'school_year'     => '2025-2026',
        'support_email'   => 'admin@gmail.com',
        'login_message'   => 'Sign in with your username and school ID.',
        'records_locked'  => '0',
    ];
    $ins = $pdo->prepare(
        'INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES (:k, :v)'
    );
    foreach ($defaults as $key => $value) {
        $ins->execute([':k' => $key, ':v' => $value]);
    }

    $ready = true;
}

function app_setting(string $key, string $default = ''): string
{
    ensure_rbac_schema();
    $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :k');
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch();
    return $row ? (string)$row['setting_value'] : $default;
}

function set_app_setting(string $key, string $value): void
{
    ensure_rbac_schema();
    $stmt = db()->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = :v2'
    );
    $stmt->execute([':k' => $key, ':v' => $value, ':v2' => $value]);
}

/** Permission codes granted to a role (cached per request). */
function permissions_for(string $roleCode): array
{
    static $cache = [];
    if (isset($cache[$roleCode])) {
        return $cache[$roleCode];
    }
    ensure_rbac_schema();
    $stmt = db()->prepare(
        'SELECT p.code
         FROM role_permissions rp
         JOIN roles r ON r.id = rp.role_id
         JOIN permissions p ON p.id = rp.permission_id
         WHERE r.code = :c
         ORDER BY p.sort_order, p.id'
    );
    $stmt->execute([':c' => $roleCode]);
    $cache[$roleCode] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return $cache[$roleCode];
}

function role_can(string $roleCode, string $perm): bool
{
    if ($roleCode === 'admin' && $perm === 'roles') {
        return true;
    }
    return in_array($perm, permissions_for($roleCode), true);
}

function require_access(string $role, string $perm): array
{
    $u = require_role($role);
    if (role_can($role, $perm)) {
        return $u;
    }
    $home = home_for($role);
    $here = $role . '/' . basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($home === $here) {
        return $u;
    }
    set_flash('error', 'Your role does not have permission to open that page.');
    redirect('../' . $home);
}

/**
 * Nav entries: [file, label, permissionCode].
 * Items whose permission is off are omitted from the bar.
 */
function nav_items_for(string $role): array
{
    if ($role === 'admin') {
        $items = [
            ['index.php', 'Blocks', 'dashboard'],
            ['academics.php', 'Colleges & Programs', 'academics'],
            ['teachers.php', 'Teachers', 'users'],
            ['students.php', 'Students', 'students'],
            ['reports.php', 'Reports', 'reports'],
            ['audit.php', 'Audit Logs', 'audit_log'],
            ['settings.php', 'Settings', 'roles'],
        ];
    } elseif ($role === 'teacher') {
        $items = [
            ['index.php', 'Dashboard', 'dashboard'],
            ['class.php', 'Class', 'students'],
            ['profile.php', 'Profile', 'profile'],
            ['reports.php', 'Reports', 'reports'],
        ];
    } elseif ($role === 'student') {
        $items = [
            ['index.php', 'My Profile', 'profile'],
            ['schedule.php', 'My Schedule', 'profile'],
            ['grades.php', 'My Grades', 'grades'],
        ];
    } else {
        $items = [];
    }

    return array_values(array_filter($items, static function ($item) use ($role) {
        return role_can($role, $item[2]);
    }));
}
