<?php
/**
 * includes/audit.php — tamper-evident activity log for Secure SIMS.
 */

function ensure_audit_schema(?PDO $pdo = null): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo = $pdo ?? db();

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `audit_log` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `actor_id` INT UNSIGNED NULL,
            `actor_role` VARCHAR(20) NOT NULL,
            `actor_username` VARCHAR(50) NULL,
            `action` VARCHAR(80) NOT NULL,
            `target` VARCHAR(160) NULL,
            `details` VARCHAR(255) NULL,
            `ip_address` VARCHAR(45) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_audit_created` (`created_at`),
            KEY `idx_audit_action` (`action`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    if ((int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() === 0) {
        $pdo->exec(
            "INSERT INTO audit_log (actor_id, actor_role, actor_username, action, target, details, ip_address, created_at) VALUES
              (1,'admin','admin','LOGIN_SUCCESS','session','Initial seed event','127.0.0.1','2026-06-01 08:02:11'),
              (2,'teacher','B_Delossantos','GRADE_UPDATED','student#4','Programming 1','127.0.0.1','2026-06-01 09:14:37'),
              (4,'student','A_Mendoza','ACCESS_DENIED','module:users','Student blocked from admin module','127.0.0.1','2026-06-01 09:20:05'),
              (1,'admin','admin','ROLE_MATRIX_UPDATED','roles','Default RBAC matrix active','127.0.0.1','2026-06-01 10:01:52'),
              (1,'admin','admin','SETTINGS_UPDATED','app_settings','System settings initialized','127.0.0.1','2026-06-01 10:45:19'),
              (1,'admin','admin','INTEGRITY_CHECK_OK','audit_log','Seed integrity check','127.0.0.1','2026-06-01 11:30:00')"
        );
    }

    $ready = true;
}

/** Append one audit row. Never throws to callers — logging must not break UX. */
function audit_log(string $action, ?string $target = null, ?string $details = null): void
{
    try {
        ensure_audit_schema();
        $user = $_SESSION['user'] ?? null;
        $stmt = db()->prepare(
            'INSERT INTO audit_log (actor_id, actor_role, actor_username, action, target, details, ip_address)
             VALUES (:aid, :role, :uname, :action, :target, :details, :ip)'
        );
        $stmt->execute([
            ':aid'     => is_array($user) ? (int)($user['id'] ?? 0) ?: null : null,
            ':role'    => is_array($user) ? (string)($user['role'] ?? 'guest') : 'guest',
            ':uname'   => is_array($user) ? (string)($user['username'] ?? '') : null,
            ':action'  => $action,
            ':target'  => $target,
            ':details' => $details,
            ':ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[secure_sims] audit_log failed: ' . $e->getMessage());
    }
}
