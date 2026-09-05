<?php
/**
 * api/data.php
 *
 * Returns roles, permissions, the RBAC matrix, sample students, audit-log
 * entries, architecture nodes and security concepts as JSON.
 *
 * Data is pulled from MySQL via PDO prepared statements. If the database is
 * unavailable, we return an identical set of built-in fallback data so the
 * 3D walkthrough always has something to render (demo never blank-screens).
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/db.php';

/**
 * Built-in fallback data (mirrors database.sql seed).
 * Used when MySQL cannot be reached.
 */
function fallback_payload(): array
{
    $roles = [
        ['id' => 1, 'name' => 'Administrator', 'code' => 'admin',   'color' => '#ff4d5e', 'description' => 'Full control of the system: manages users, roles, and all academic records.'],
        ['id' => 2, 'name' => 'Teacher / Staff', 'code' => 'teacher', 'color' => '#4d9bff', 'description' => 'Manages grades and attendance for their classes and views reports.'],
        ['id' => 3, 'name' => 'Student',        'code' => 'student', 'color' => '#43d17a', 'description' => 'Views their own profile and grades. No access to other records.'],
    ];

    $permissions = [
        ['id' => 1, 'code' => 'dashboard',  'label' => 'Dashboard',   'description' => 'Overview landing area available to every authenticated role.'],
        ['id' => 2, 'code' => 'profile',    'label' => 'My Profile',  'description' => 'View and edit your own account information.'],
        ['id' => 3, 'code' => 'students',   'label' => 'Students',    'description' => 'Browse and manage the student directory.'],
        ['id' => 4, 'code' => 'grades',     'label' => 'Grades',      'description' => 'Record and review academic grades.'],
        ['id' => 5, 'code' => 'attendance', 'label' => 'Attendance',  'description' => 'Track daily attendance for classes.'],
        ['id' => 6, 'code' => 'reports',    'label' => 'Reports',     'description' => 'Generate summary reports and analytics.'],
        ['id' => 7, 'code' => 'users',      'label' => 'User Admin',  'description' => 'Create, disable and manage user accounts.'],
        ['id' => 8, 'code' => 'roles',      'label' => 'Role Admin',  'description' => 'Assign roles and configure permissions.'],
        ['id' => 9, 'code' => 'audit_log',  'label' => 'Audit Log',   'description' => 'Read the tamper-evident record of security events.'],
    ];

    // role code => permission codes the role may open
    $matrix = [
        'admin'   => ['dashboard', 'profile', 'students', 'grades', 'attendance', 'reports', 'users', 'roles', 'audit_log'],
        'teacher' => ['dashboard', 'profile', 'students', 'grades', 'attendance', 'reports'],
        'student' => ['dashboard', 'profile', 'grades'],
    ];

    $students = [
        ['id' => 1, 'name' => 'Aisha Rahman',   'email' => 'aisha.r@school.edu',  'grade_level' => 'Grade 11', 'gpa' => 3.92],
        ['id' => 2, 'name' => 'Diego Martinez', 'email' => 'diego.m@school.edu',  'grade_level' => 'Grade 10', 'gpa' => 3.45],
        ['id' => 3, 'name' => 'Mei Lin',        'email' => 'mei.l@school.edu',    'grade_level' => 'Grade 12', 'gpa' => 3.78],
        ['id' => 4, 'name' => 'Samuel Osei',    'email' => 'samuel.o@school.edu', 'grade_level' => 'Grade 11', 'gpa' => 3.10],
        ['id' => 5, 'name' => 'Priya Nair',     'email' => 'priya.n@school.edu',  'grade_level' => 'Grade 9',  'gpa' => 4.00],
    ];

    $audit = [
        ['id' => 1, 'actor_role' => 'admin',   'action' => 'LOGIN_SUCCESS',      'target' => 'session',        'created_at' => '2025-06-01 08:02:11'],
        ['id' => 2, 'actor_role' => 'teacher', 'action' => 'GRADE_UPDATED',      'target' => 'student#2',      'created_at' => '2025-06-01 09:14:37'],
        ['id' => 3, 'actor_role' => 'student', 'action' => 'ACCESS_DENIED',      'target' => 'module:users',  'created_at' => '2025-06-01 09:20:05'],
        ['id' => 4, 'actor_role' => 'admin',   'action' => 'ROLE_ASSIGNED',      'target' => 'user#7:teacher','created_at' => '2025-06-01 10:01:52'],
        ['id' => 5, 'actor_role' => 'teacher', 'action' => 'ATTENDANCE_MARKED',  'target' => 'class:11-B',    'created_at' => '2025-06-01 10:45:19'],
        ['id' => 6, 'actor_role' => 'admin',   'action' => 'INTEGRITY_CHECK_OK', 'target' => 'audit_log',     'created_at' => '2025-06-01 11:30:00'],
    ];

    $architecture = [
        ['code' => 'browser', 'title' => 'Browser (Client)', 'caption' => 'The client device. Sends HTTPS requests and renders responses. All user input is treated as untrusted until validated on the server.'],
        ['code' => 'php',     'title' => 'PHP Application',   'caption' => 'The application server. Authenticates users, enforces Role-Based Access Control, validates input, and talks to MySQL using PDO prepared statements.'],
        ['code' => 'mysql',   'title' => 'MySQL Database',    'caption' => 'Persistent storage for users, roles, permissions, students and the audit log. Reached only through least-privilege accounts and parameterised queries.'],
    ];

    $security = [
        ['code' => 'confidentiality', 'title' => 'Confidentiality', 'caption' => 'Only authorised parties can read the data. Packets travel inside an encrypted shield; unauthorised "peek" attempts simply bounce off.'],
        ['code' => 'integrity',       'title' => 'Integrity',       'caption' => 'Data cannot be altered undetected. Each packet carries a checksum seal — tampering cracks the seal and instantly raises an alert.'],
        ['code' => 'availability',    'title' => 'Availability',    'caption' => 'Authorised users get reliable, timely access. The system stays lit and responsive as requests keep flowing without interruption.'],
        ['code' => 'rbac',            'title' => 'Role-Based Access Control', 'caption' => 'Every user is assigned a role, and roles grant permissions. A user can only open the module "doors" their role permits — everything else stays locked.'],
    ];

    return [
        'source'       => 'fallback',
        'hint'         => 'drag to rotate • scroll to zoom • click glowing parts',
        'roles'        => $roles,
        'permissions'  => $permissions,
        'matrix'       => $matrix,
        'students'     => $students,
        'audit'        => $audit,
        'architecture' => $architecture,
        'security'     => $security,
    ];
}

/**
 * Load the same payload from MySQL using prepared statements.
 */
function db_payload(PDO $pdo): array
{
    $roles = $pdo->query('SELECT id, name, code, color, description FROM roles ORDER BY id')->fetchAll();

    $permissions = $pdo->query('SELECT id, code, label, description FROM permissions ORDER BY sort_order, id')->fetchAll();

    // Build the RBAC matrix: role code => [permission codes]
    $stmt = $pdo->prepare(
        'SELECT r.code AS role_code, p.code AS perm_code
         FROM role_permissions rp
         JOIN roles r ON r.id = rp.role_id
         JOIN permissions p ON p.id = rp.permission_id
         ORDER BY r.id, p.id'
    );
    $stmt->execute();
    $matrix = [];
    foreach ($stmt->fetchAll() as $row) {
        $matrix[$row['role_code']][] = $row['perm_code'];
    }

    $fb = fallback_payload();

    $students = $fb['students'];
    try {
        $students = $pdo->query('SELECT id, name, email, grade_level, gpa FROM students ORDER BY id')->fetchAll();
        foreach ($students as &$s) {
            $s['gpa'] = (float)$s['gpa'];
        }
        unset($s);
    } catch (Throwable $e) {
        $students = $fb['students'];
    }

    $audit = $fb['audit'];
    try {
        $auditStmt = $pdo->prepare('SELECT id, actor_role, action, target, created_at FROM audit_log ORDER BY created_at DESC, id DESC LIMIT :lim');
        $auditStmt->bindValue(':lim', 12, PDO::PARAM_INT);
        $auditStmt->execute();
        $audit = $auditStmt->fetchAll();
    } catch (Throwable $e) {
        $audit = $fb['audit'];
    }

    return [
        'source'       => 'mysql',
        'hint'         => $fb['hint'],
        'roles'        => $roles,
        'permissions'  => $permissions,
        'matrix'       => $matrix,
        'students'     => $students,
        'audit'        => $audit,
        'architecture' => $fb['architecture'],
        'security'     => $fb['security'],
    ];
}

$pdo = db_connect();

try {
    if ($pdo instanceof PDO) {
        $payload = db_payload($pdo);
    } else {
        $payload = fallback_payload();
    }
} catch (Throwable $e) {
    error_log('[secure_sims] Query failed, using fallback: ' . $e->getMessage());
    $payload = fallback_payload();
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
