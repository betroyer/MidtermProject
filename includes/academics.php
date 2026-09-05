<?php
/**
 * includes/academics.php — colleges (departments), programs (courses),
 * subjects, offerings, enrollments, and grade-report snapshots.
 */

function ensure_academics_schema(?PDO $pdo = null): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo = $pdo ?? db();

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `departments` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(20) NOT NULL,
            `name` VARCHAR(160) NOT NULL,
            `description` VARCHAR(255) NOT NULL DEFAULT "",
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_departments_code` (`code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `courses` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `department_id` INT UNSIGNED NOT NULL,
            `code` VARCHAR(40) NOT NULL,
            `name` VARCHAR(200) NOT NULL,
            `description` VARCHAR(255) NOT NULL DEFAULT "",
            `units` DECIMAL(3,1) NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_courses_code` (`code`),
            KEY `idx_courses_dept` (`department_id`),
            CONSTRAINT `fk_courses_department` FOREIGN KEY (`department_id`)
              REFERENCES `departments`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    try {
        $pdo->exec('ALTER TABLE `departments` MODIFY `name` VARCHAR(160) NOT NULL');
        $pdo->exec('ALTER TABLE `courses` MODIFY `code` VARCHAR(40) NOT NULL');
        $pdo->exec('ALTER TABLE `courses` MODIFY `name` VARCHAR(200) NOT NULL');
    } catch (Throwable $e) {
        // ignore
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `subjects` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(40) NOT NULL,
            `name` VARCHAR(160) NOT NULL,
            `department_id` INT UNSIGNED NULL,
            `units` DECIMAL(3,1) NOT NULL DEFAULT 3.0,
            `description` VARCHAR(255) NOT NULL DEFAULT "",
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_subjects_code` (`code`),
            KEY `idx_subjects_dept` (`department_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `subject_offerings` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `subject_id` INT UNSIGNED NOT NULL,
            `teacher_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(80) NOT NULL DEFAULT "",
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_offerings_subject` (`subject_id`),
            KEY `idx_offerings_teacher` (`teacher_id`),
            CONSTRAINT `fk_offerings_subject` FOREIGN KEY (`subject_id`)
              REFERENCES `subjects`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_offerings_teacher` FOREIGN KEY (`teacher_id`)
              REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `enrollments` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `student_id` INT UNSIGNED NOT NULL,
            `offering_id` INT UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_enrollment_student_offering` (`student_id`, `offering_id`),
            KEY `idx_enroll_student` (`student_id`),
            KEY `idx_enroll_offering` (`offering_id`),
            CONSTRAINT `fk_enroll_student` FOREIGN KEY (`student_id`)
              REFERENCES `users`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_enroll_offering` FOREIGN KEY (`offering_id`)
              REFERENCES `subject_offerings`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `grade_reports` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `teacher_id` INT UNSIGNED NOT NULL,
            `offering_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(160) NOT NULL DEFAULT "",
            `status` VARCHAR(20) NOT NULL DEFAULT "submitted",
            `snapshot_json` LONGTEXT NOT NULL,
            `note` VARCHAR(255) NOT NULL DEFAULT "",
            `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_greports_teacher` (`teacher_id`),
            KEY `idx_greports_offering` (`offering_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $userCols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('program_id', $userCols, true)) {
        $pdo->exec('ALTER TABLE `users` ADD COLUMN `program_id` INT UNSIGNED NULL AFTER `block_id`');
    }

    $gradeCols = $pdo->query('SHOW COLUMNS FROM grades')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('offering_id', $gradeCols, true)) {
        $pdo->exec('ALTER TABLE `grades` ADD COLUMN `offering_id` INT UNSIGNED NULL AFTER `teacher_id`');
    }

    $pdo->exec(
        "INSERT IGNORE INTO permissions (id, code, label, description, sort_order) VALUES
          (10, 'academics', 'Colleges & Programs', 'Manage colleges, degree programs, and subjects.', 10),
          (6, 'reports', 'Reports', 'Teacher class reports and admin grade report inbox.', 6)"
    );
    $pdo->exec(
        "UPDATE permissions SET label = 'Colleges & Programs',
            description = 'Manage colleges, degree programs, and subjects.'
         WHERE code = 'academics'"
    );
    $pdo->exec(
        "INSERT IGNORE INTO role_permissions (role_id, permission_id)
         SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
         WHERE r.code = 'admin' AND p.code IN ('academics','reports')"
    );

    seed_college_catalog_if_needed($pdo);
    ensure_blocks_academics_schema($pdo);
    migrate_legacy_academics_links($pdo);
    $ready = true;
}

function seed_college_catalog_if_needed(PDO $pdo): void
{
    $hasCict = (int)$pdo->query("SELECT COUNT(*) FROM departments WHERE code='CICT'")->fetchColumn() > 0;
    if ($hasCict) {
        return;
    }

    $legacy = (int)$pdo->query(
        "SELECT COUNT(*) FROM departments WHERE code IN ('BSIT','BSCS','BSIS')"
    )->fetchColumn();
    if ($legacy > 0 && (int)$pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn() <= 3) {
        try {
            $pdo->exec('UPDATE blocks SET department_id = NULL, course_id = NULL');
            $pdo->exec('UPDATE users SET program_id = NULL');
        } catch (Throwable $e) {
            // ignore
        }
        $pdo->exec('DELETE FROM courses');
        $pdo->exec('DELETE FROM departments');
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn() > 0) {
        return;
    }

    $depts = [
        ['CBGG', 'College of Business and Good Governance', 'Business, hospitality, tourism, and social work programs.'],
        ['CTE', 'College of Teacher Education', 'Teacher education degree programs.'],
        ['CICT', 'College of Information and Communication Technology', 'IT, business analytics, and computer technology.'],
        ['CAF', 'College of Agriculture and Fisheries', 'Agriculture majors and fisheries.'],
        ['CCJE', 'College of Criminal Justice Education', 'Criminology programs.'],
        ['DCE', 'Department of Civil Engineering', 'Civil engineering with structural specialization.'],
    ];
    $insD = $pdo->prepare('INSERT INTO departments (code, name, description) VALUES (:c,:n,:d)');
    foreach ($depts as [$c, $n, $d]) {
        $insD->execute([':c' => $c, ':n' => $n, ':d' => $d]);
    }

    $idOf = static function (string $code) use ($pdo): int {
        return (int)$pdo->query('SELECT id FROM departments WHERE code=' . $pdo->quote($code))->fetchColumn();
    };

    $programs = [
        [$idOf('CBGG'), 'BPA', 'Bachelor of Public Administration', ''],
        [$idOf('CBGG'), 'BSAIS', 'BS in Accounting Information Systems', ''],
        [$idOf('CBGG'), 'BSHM', 'BS in Hospitality Management', 'With Associate options'],
        [$idOf('CBGG'), 'BSTM', 'BS in Tourism Management', ''],
        [$idOf('CBGG'), 'BSBA-MM', 'BS in Business Administration – Major in Marketing Management', ''],
        [$idOf('CBGG'), 'BSSW', 'BS in Social Work', ''],
        [$idOf('CTE'), 'BEEd', 'Bachelor of Elementary Education', ''],
        [$idOf('CTE'), 'BECEd', 'Bachelor of Early Childhood Education', ''],
        [$idOf('CTE'), 'BSEd-English', 'Bachelor of Secondary Education – Major in English', ''],
        [$idOf('CTE'), 'BSEd-Filipino', 'Bachelor of Secondary Education – Major in Filipino', ''],
        [$idOf('CTE'), 'BSEd-Math', 'Bachelor of Secondary Education – Major in Mathematics', ''],
        [$idOf('CTE'), 'BSEd-Science', 'Bachelor of Secondary Education – Major in Science', ''],
        [$idOf('CTE'), 'BSEd-SocStud', 'Bachelor of Secondary Education – Major in Social Studies', ''],
        [$idOf('CTE'), 'BTLEd-ICT', 'Bachelor of Technology and Livelihood Education – Major in ICT', ''],
        [$idOf('CICT'), 'BSIT', 'BS in Information Technology', ''],
        [$idOf('CICT'), 'BSIT-BA', 'BS in Information Technology – Business Analytics', ''],
        [$idOf('CICT'), 'ACT', 'Associate in Computer Technology', ''],
        [$idOf('CAF'), 'BSA-Animal', 'BS in Agriculture – Major in Animal Science', ''],
        [$idOf('CAF'), 'BSA-Crop', 'BS in Agriculture – Major in Crop Science', ''],
        [$idOf('CAF'), 'BSA-Hort', 'BS in Agriculture – Major in Horticulture', ''],
        [$idOf('CAF'), 'BSA-Plant', 'BS in Agriculture – Major in Plant Breeding/Genetics', ''],
        [$idOf('CAF'), 'BSFisheries', 'BS in Fisheries', ''],
        [$idOf('CCJE'), 'BSCrim', 'BS in Criminology', ''],
        [$idOf('DCE'), 'BSCE-Structural', 'BS in Civil Engineering – Structural Engineering', ''],
    ];
    $insC = $pdo->prepare(
        'INSERT INTO courses (department_id, code, name, description, units) VALUES (:d,:c,:n,:desc,0)'
    );
    foreach ($programs as [$d, $c, $n, $desc]) {
        if ($d > 0) {
            $insC->execute([':d' => $d, ':c' => $c, ':n' => $n, ':desc' => $desc]);
        }
    }

    $cict = $idOf('CICT');
    if ($cict > 0 && (int)$pdo->query('SELECT COUNT(*) FROM subjects')->fetchColumn() === 0) {
        $insS = $pdo->prepare(
            'INSERT INTO subjects (code, name, department_id, units, description) VALUES (:c,:n,:d,:u,:desc)'
        );
        foreach ([
            ['IT101', 'Programming 1', 3.0],
            ['IT102', 'Programming 2', 3.0],
            ['IT201', 'Data Structures', 3.0],
            ['IT202', 'Database Systems', 3.0],
            ['IT301', 'Web Development', 3.0],
        ] as [$c, $n, $u]) {
            $insS->execute([':c' => $c, ':n' => $n, ':d' => $cict, ':u' => $u, ':desc' => '']);
        }
    }
}

function ensure_blocks_academics_schema(?PDO $pdo = null): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo = $pdo ?? db();
    $cols = $pdo->query('SHOW COLUMNS FROM blocks')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('department_id', $cols, true)) {
        $pdo->exec('ALTER TABLE `blocks` ADD COLUMN `department_id` INT UNSIGNED NULL AFTER `teacher_id`');
    }
    if (!in_array('course_id', $cols, true)) {
        $pdo->exec('ALTER TABLE `blocks` ADD COLUMN `course_id` INT UNSIGNED NULL AFTER `department_id`');
    }
    $ready = true;
}

function migrate_legacy_academics_links(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $cict = (int)$pdo->query("SELECT id FROM departments WHERE code='CICT'")->fetchColumn();
    $bsit = (int)$pdo->query("SELECT id FROM courses WHERE code='BSIT'")->fetchColumn();
    if ($cict <= 0 || $bsit <= 0) {
        $done = true;
        return;
    }

    $pdo->prepare(
        'UPDATE blocks SET department_id = :d, course_id = :c
         WHERE name LIKE "BSIT%" OR department_id IS NULL OR course_id IS NULL'
    )->execute([':d' => $cict, ':c' => $bsit]);

    $pdo->exec(
        "UPDATE users u
         JOIN blocks b ON b.id = u.block_id
         SET u.program_id = b.course_id
         WHERE u.role = 'student' AND (u.program_id IS NULL OR u.program_id = 0)
           AND b.course_id IS NOT NULL"
    );

    $orphanGrades = $pdo->query(
        'SELECT g.id, g.student_id, g.teacher_id, g.subject
         FROM grades g
         WHERE g.offering_id IS NULL AND g.subject IS NOT NULL AND g.subject != ""
         LIMIT 500'
    )->fetchAll();
    if ($orphanGrades) {
        $findSub = $pdo->prepare('SELECT id FROM subjects WHERE name = :n OR code = :c LIMIT 1');
        $insSub = $pdo->prepare(
            'INSERT INTO subjects (code, name, department_id, units) VALUES (:c,:n,:d,3)'
        );
        $findOff = $pdo->prepare(
            'SELECT id FROM subject_offerings WHERE subject_id = :s AND teacher_id = :t LIMIT 1'
        );
        $insOff = $pdo->prepare(
            'INSERT INTO subject_offerings (subject_id, teacher_id, name) VALUES (:s,:t,:n)'
        );
        $updG = $pdo->prepare('UPDATE grades SET offering_id = :o WHERE id = :id');
        $insEn = $pdo->prepare(
            'INSERT IGNORE INTO enrollments (student_id, offering_id) VALUES (:s,:o)'
        );
        $n = 0;
        foreach ($orphanGrades as $g) {
            $subjName = trim($g['subject']);
            $findSub->execute([':n' => $subjName, ':c' => $subjName]);
            $sid = (int)$findSub->fetchColumn();
            if ($sid <= 0) {
                $code = 'SUB' . (++$n) . substr(preg_replace('/[^A-Za-z0-9]/', '', $subjName), 0, 8);
                try {
                    $insSub->execute([':c' => $code, ':n' => $subjName, ':d' => $cict]);
                    $sid = (int)$pdo->lastInsertId();
                } catch (Throwable $e) {
                    continue;
                }
            }
            $tid = (int)$g['teacher_id'];
            $findOff->execute([':s' => $sid, ':t' => $tid]);
            $oid = (int)$findOff->fetchColumn();
            if ($oid <= 0) {
                $insOff->execute([':s' => $sid, ':t' => $tid, ':n' => 'Section A']);
                $oid = (int)$pdo->lastInsertId();
            }
            $updG->execute([':o' => $oid, ':id' => (int)$g['id']]);
            $insEn->execute([':s' => (int)$g['student_id'], ':o' => $oid]);
        }
    }

    $done = true;
}

function block_academic_label(array $row): string
{
    $dept = trim((string)($row['department_code'] ?? ''));
    $ccode = trim((string)($row['course_code'] ?? ''));
    $cname = trim((string)($row['course_name'] ?? ''));
    if ($dept === '' && $ccode === '') {
        return 'No college / program';
    }
    $course = $ccode !== '' ? ($cname !== '' ? "{$ccode} — {$cname}" : $ccode) : 'No program';
    return ($dept !== '' ? $dept : '—') . ' · ' . $course;
}

function program_label(array $row): string
{
    $code = trim((string)($row['program_code'] ?? $row['course_code'] ?? ''));
    $name = trim((string)($row['program_name'] ?? $row['course_name'] ?? ''));
    $dept = trim((string)($row['department_code'] ?? ''));
    if ($code === '' && $name === '') {
        return 'No program';
    }
    $prog = $code !== '' ? ($name !== '' ? "{$code} - {$name}" : $code) : $name;
    return $dept !== '' ? "{$dept} · {$prog}" : $prog;
}

function departments_list(bool $activeOnly = false): array
{
    ensure_academics_schema();
    $sql = 'SELECT d.*,
                   (SELECT COUNT(*) FROM courses c WHERE c.department_id = d.id) AS course_count
            FROM departments d';
    if ($activeOnly) {
        $sql .= ' WHERE d.is_active = 1';
    }
    $sql .= ' ORDER BY d.code';
    return db()->query($sql)->fetchAll();
}

function courses_list(?int $departmentId = null, bool $activeOnly = false): array
{
    ensure_academics_schema();
    $sql = 'SELECT c.*, d.code AS department_code, d.name AS department_name
            FROM courses c
            JOIN departments d ON d.id = c.department_id
            WHERE 1=1';
    $params = [];
    if ($departmentId !== null && $departmentId > 0) {
        $sql .= ' AND c.department_id = :d';
        $params[':d'] = $departmentId;
    }
    if ($activeOnly) {
        $sql .= ' AND c.is_active = 1 AND d.is_active = 1';
    }
    $sql .= ' ORDER BY d.code, c.code';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function subjects_list(?int $departmentId = null, bool $activeOnly = false): array
{
    ensure_academics_schema();
    $sql = 'SELECT s.*, d.code AS department_code, d.name AS department_name
            FROM subjects s
            LEFT JOIN departments d ON d.id = s.department_id
            WHERE 1=1';
    $params = [];
    if ($departmentId !== null && $departmentId > 0) {
        $sql .= ' AND s.department_id = :d';
        $params[':d'] = $departmentId;
    }
    if ($activeOnly) {
        $sql .= ' AND s.is_active = 1';
    }
    $sql .= ' ORDER BY s.code';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function offerings_list(?int $teacherId = null, bool $activeOnly = true): array
{
    ensure_academics_schema();
    $sql = 'SELECT o.*, s.code AS subject_code, s.name AS subject_name,
                   CONCAT(t.first_name, " ", t.last_name) AS teacher_name, t.username AS teacher_username,
                   (SELECT COUNT(*) FROM enrollments e WHERE e.offering_id = o.id) AS enroll_count
            FROM subject_offerings o
            JOIN subjects s ON s.id = o.subject_id
            JOIN users t ON t.id = o.teacher_id
            WHERE 1=1';
    $params = [];
    if ($teacherId !== null && $teacherId > 0) {
        $sql .= ' AND o.teacher_id = :t';
        $params[':t'] = $teacherId;
    }
    if ($activeOnly) {
        $sql .= ' AND o.is_active = 1 AND s.is_active = 1';
    }
    $sql .= ' ORDER BY s.code, o.name';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function student_enrolled_with_teacher(int $studentId, int $teacherId): bool
{
    ensure_academics_schema();
    $stmt = db()->prepare(
        'SELECT 1 FROM enrollments e
         JOIN subject_offerings o ON o.id = e.offering_id
         WHERE e.student_id = :s AND o.teacher_id = :t AND o.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([':s' => $studentId, ':t' => $teacherId]);
    return (bool)$stmt->fetchColumn();
}

function teacher_offerings_for_student(int $teacherId, int $studentId): array
{
    ensure_academics_schema();
    $stmt = db()->prepare(
        'SELECT o.*, s.code AS subject_code, s.name AS subject_name
         FROM enrollments e
         JOIN subject_offerings o ON o.id = e.offering_id
         JOIN subjects s ON s.id = o.subject_id
         WHERE e.student_id = :s AND o.teacher_id = :t AND o.is_active = 1
         ORDER BY s.code'
    );
    $stmt->execute([':s' => $studentId, ':t' => $teacherId]);
    return $stmt->fetchAll();
}
