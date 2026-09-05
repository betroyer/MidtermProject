<?php
/**
 * includes/curriculum.php — year-level curriculum, auto subject enrollment, class schedules.
 */

function year_level_options(): array
{
    return [
        1 => '1st Year',
        2 => '2nd Year',
        3 => '3rd Year',
        4 => '4th Year',
    ];
}

function year_level_label(?int $year): string
{
    $opts = year_level_options();
    return $opts[(int)$year] ?? '—';
}

function year_schedule_days(int $yearLevel): array
{
    // Fixed day sets per year (Mon=1 … Sat=6). Y3/Y4 use 3 days.
    if ($yearLevel <= 2) {
        return [1, 2, 5, 6]; // Mon, Tue, Fri, Sat
    }
    return [1, 3, 5]; // Mon, Wed, Fri — base; shuffled per block below
}

/**
 * Default subject → teacher username (specialty). Programming stays with B_Delossantos.
 * Used when creating curriculum offerings so the block adviser is not assigned every subject.
 *
 * @return array<string,string> subject_code => username
 */
function subject_teacher_specialty_map(): array
{
    return [
        // Programming specialty (Bruno)
        'IT112' => 'B_Delossantos',
        'IT101' => 'B_Delossantos',
        'IT212' => 'B_Delossantos',
        'IT102' => 'B_Delossantos',
        // Other IT majors
        'IT111' => 'C_Reyes',
        'IT113' => 'D_Garcia',
        'IT114' => 'E_Lopez',
        'IT211' => 'F_Ramos',
        'IT201' => 'F_Ramos',
        'IT213' => 'G_Torres',
        'IT202' => 'G_Torres',
        'IT214' => 'H_Villanueva',
        'IT301' => 'H_Villanueva',
        'IT215' => 'C_Reyes',
        'IT311' => 'D_Garcia',
        'IT312' => 'E_Lopez',
        'IT313' => 'F_Ramos',
        'IT411' => 'G_Torres',
        'IT412' => 'H_Villanueva',
        'IT413' => 'C_Reyes',
        // GE / PE
        'GE101' => 'G_Santos',
        'GE102' => 'G_Santos',
        'GE103' => 'G_Santos',
        'GE201' => 'G_Santos',
        'GE202' => 'G_Santos',
        'PE101' => 'P_Cruz',
        'PE201' => 'P_Cruz',
    ];
}

/**
 * Resolve which teacher should own offerings for a subject.
 * Prefer specialty map → existing offering teacher for that subject → fallback.
 */
function resolve_subject_teacher_id(PDO $pdo, int $subjectId, int $fallbackTeacherId = 0): int
{
    if ($subjectId <= 0) {
        return max(0, $fallbackTeacherId);
    }
    $stmt = $pdo->prepare('SELECT code FROM subjects WHERE id = :id');
    $stmt->execute([':id' => $subjectId]);
    $code = strtoupper(trim((string)$stmt->fetchColumn()));

    $map = subject_teacher_specialty_map();
    if ($code !== '' && isset($map[$code])) {
        $u = $pdo->prepare('SELECT id FROM users WHERE username = :u AND role = "teacher" LIMIT 1');
        $u->execute([':u' => $map[$code]]);
        $tid = (int)$u->fetchColumn();
        if ($tid > 0) {
            return $tid;
        }
    }

    $existing = $pdo->prepare(
        'SELECT teacher_id FROM subject_offerings
         WHERE subject_id = :s AND is_active = 1
         ORDER BY id ASC LIMIT 1'
    );
    $existing->execute([':s' => $subjectId]);
    $tid = (int)$existing->fetchColumn();
    if ($tid > 0) {
        return $tid;
    }

    return max(0, $fallbackTeacherId);
}

function weekday_name(int $dow): string
{
    $map = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    return $map[$dow] ?? 'Day ' . $dow;
}

function ensure_curriculum_schema(?PDO $pdo = null): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo = $pdo ?: db();

    $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('year_level', $cols, true)) {
        $pdo->exec('ALTER TABLE `users` ADD COLUMN `year_level` TINYINT UNSIGNED NULL AFTER `program_id`');
    }

    $subCols = $pdo->query('SHOW COLUMNS FROM subjects')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('kind', $subCols, true)) {
        $pdo->exec("ALTER TABLE `subjects` ADD COLUMN `kind` ENUM('major','minor') NOT NULL DEFAULT 'major' AFTER `units`");
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `curriculum_subjects` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `course_id` INT UNSIGNED NOT NULL,
            `year_level` TINYINT UNSIGNED NOT NULL,
            `subject_id` INT UNSIGNED NOT NULL,
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_curriculum` (`course_id`, `year_level`, `subject_id`),
            KEY `idx_curr_course_year` (`course_id`, `year_level`),
            CONSTRAINT `fk_curr_course` FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_curr_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `class_schedules` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `block_id` INT UNSIGNED NOT NULL,
            `year_level` TINYINT UNSIGNED NOT NULL,
            `offering_id` INT UNSIGNED NOT NULL,
            `day_of_week` TINYINT UNSIGNED NOT NULL,
            `start_time` TIME NOT NULL,
            `end_time` TIME NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_sched_slot` (`block_id`, `year_level`, `day_of_week`, `start_time`),
            KEY `idx_sched_block_year` (`block_id`, `year_level`),
            KEY `idx_sched_offering` (`offering_id`),
            CONSTRAINT `fk_sched_block` FOREIGN KEY (`block_id`) REFERENCES `blocks`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_sched_offering` FOREIGN KEY (`offering_id`) REFERENCES `subject_offerings`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    seed_curriculum_if_needed($pdo);
    $done = true;
}

function seed_curriculum_if_needed(PDO $pdo): void
{
    $count = (int)$pdo->query('SELECT COUNT(*) FROM curriculum_subjects')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $bsitId = (int)$pdo->query("SELECT id FROM courses WHERE code='BSIT' LIMIT 1")->fetchColumn();
    $cictId = (int)$pdo->query("SELECT id FROM departments WHERE code='CICT' LIMIT 1")->fetchColumn();
    if ($bsitId <= 0 || $cictId <= 0) {
        return;
    }

    // Year → [ [code, name, kind], ... ]  counts: 8 / 8 / 3 / 3
    $byYear = [
        1 => [
            ['IT111', 'Introduction to Computing', 'major'],
            ['IT112', 'Computer Programming 1', 'major'],
            ['IT113', 'Discrete Mathematics', 'major'],
            ['IT114', 'HCI Fundamentals', 'major'],
            ['GE101', 'Understanding the Self', 'minor'],
            ['GE102', 'Readings in Philippine History', 'minor'],
            ['GE103', 'Mathematics in the Modern World', 'minor'],
            ['PE101', 'Physical Education 1', 'minor'],
        ],
        2 => [
            ['IT211', 'Data Structures and Algorithms', 'major'],
            ['IT212', 'Object-Oriented Programming', 'major'],
            ['IT213', 'Database Management Systems', 'major'],
            ['IT214', 'Web Systems and Technologies', 'major'],
            ['IT215', 'Networking 1', 'major'],
            ['GE201', 'Purposive Communication', 'minor'],
            ['GE202', 'Science, Technology and Society', 'minor'],
            ['PE201', 'Physical Education 2', 'minor'],
        ],
        3 => [
            ['IT311', 'Software Engineering', 'major'],
            ['IT312', 'Information Assurance and Security', 'major'],
            ['IT313', 'Systems Integration and Architecture', 'major'],
        ],
        4 => [
            ['IT411', 'Capstone Project 1', 'major'],
            ['IT412', 'IT Project Management', 'major'],
            ['IT413', 'Practicum / OJT', 'major'],
        ],
    ];

    $insSub = $pdo->prepare(
        'INSERT INTO subjects (code, name, department_id, units, kind, is_active)
         VALUES (:c,:n,:d,3,:k,1)
         ON DUPLICATE KEY UPDATE name=VALUES(name), kind=VALUES(kind), department_id=VALUES(department_id), is_active=1'
    );
    $getSub = $pdo->prepare('SELECT id FROM subjects WHERE code = :c LIMIT 1');
    $insCur = $pdo->prepare(
        'INSERT IGNORE INTO curriculum_subjects (course_id, year_level, subject_id, sort_order)
         VALUES (:course,:y,:sid,:ord)'
    );

    foreach ($byYear as $year => $subjects) {
        foreach ($subjects as $i => [$code, $name, $kind]) {
            $insSub->execute([':c' => $code, ':n' => $name, ':d' => $cictId, ':k' => $kind]);
            $getSub->execute([':c' => $code]);
            $sid = (int)$getSub->fetchColumn();
            if ($sid > 0) {
                $insCur->execute([':course' => $bsitId, ':y' => $year, ':sid' => $sid, ':ord' => $i + 1]);
            }
        }
    }

    // Lightweight templates for other degree programs (same counts, unique codes)
    $courses = $pdo->query(
        "SELECT id, code, department_id FROM courses WHERE code <> 'BSIT' AND is_active = 1"
    )->fetchAll(PDO::FETCH_ASSOC);
    $counts = [1 => 8, 2 => 8, 3 => 3, 4 => 3];
    foreach ($courses as $course) {
        $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper($course['code']));
        $prefix = substr($prefix, 0, 6);
        $deptId = (int)$course['department_id'];
        foreach ($counts as $year => $n) {
            for ($i = 1; $i <= $n; $i++) {
                $code = sprintf('%s-Y%dS%02d', $prefix, $year, $i);
                $kind = ($i <= max(1, (int)ceil($n * 0.6))) ? 'major' : 'minor';
                $name = sprintf('%s Year %d Subject %d (%s)', $course['code'], $year, $i, $kind);
                $insSub->execute([':c' => $code, ':n' => $name, ':d' => $deptId ?: null, ':k' => $kind]);
                $getSub->execute([':c' => $code]);
                $sid = (int)$getSub->fetchColumn();
                if ($sid > 0) {
                    $insCur->execute([
                        ':course' => (int)$course['id'],
                        ':y' => $year,
                        ':sid' => $sid,
                        ':ord' => $i,
                    ]);
                }
            }
        }
    }
}

function curriculum_subjects_for(int $courseId, int $yearLevel): array
{
    ensure_curriculum_schema();
    $stmt = db()->prepare(
        'SELECT cs.*, s.code, s.name, s.kind, s.units
         FROM curriculum_subjects cs
         JOIN subjects s ON s.id = cs.subject_id
         WHERE cs.course_id = :c AND cs.year_level = :y AND s.is_active = 1
         ORDER BY cs.sort_order, s.code'
    );
    $stmt->execute([':c' => $courseId, ':y' => $yearLevel]);
    return $stmt->fetchAll();
}

/**
 * Ensure offerings exist for curriculum subjects (teacher = subject specialty, not block adviser),
 * enroll the student, and build a randomized block+year schedule.
 * @return array{enrolled:int,schedule:int}
 */
function enroll_student_year_curriculum(PDO $pdo, int $studentId, int $blockId, int $programId, int $yearLevel): array
{
    ensure_curriculum_schema($pdo);
    $result = ['enrolled' => 0, 'schedule' => 0];
    if ($studentId <= 0 || $blockId <= 0 || $programId <= 0 || $yearLevel < 1 || $yearLevel > 4) {
        return $result;
    }

    $subjects = curriculum_subjects_for($programId, $yearLevel);
    if (!$subjects) {
        // Fallback: old offering enroll if no curriculum
        $result['enrolled'] = auto_enroll_student_for_block($pdo, $studentId, $blockId);
        return $result;
    }

    $blk = $pdo->prepare('SELECT teacher_id, name FROM blocks WHERE id = :id');
    $blk->execute([':id' => $blockId]);
    $block = $blk->fetch();
    $fallbackTeacherId = (int)($block['teacher_id'] ?? 0);
    if ($fallbackTeacherId <= 0) {
        $any = $pdo->query(
            'SELECT id FROM users WHERE role="teacher" AND COALESCE(is_active,1)=1 ORDER BY id LIMIT 1'
        )->fetchColumn();
        $fallbackTeacherId = (int)$any;
    }
    if ($fallbackTeacherId <= 0) {
        return $result;
    }

    $findOff = $pdo->prepare(
        'SELECT id FROM subject_offerings WHERE subject_id = :s AND teacher_id = :t AND name = :n LIMIT 1'
    );
    $findAnySection = $pdo->prepare(
        'SELECT id, teacher_id FROM subject_offerings WHERE subject_id = :s AND name = :n AND is_active = 1 LIMIT 1'
    );
    $insOff = $pdo->prepare(
        'INSERT INTO subject_offerings (subject_id, teacher_id, name, is_active) VALUES (:s,:t,:n,1)'
    );
    $insEn = $pdo->prepare(
        'INSERT IGNORE INTO enrollments (student_id, offering_id) VALUES (:stu,:oid)'
    );

    $section = ($block['name'] ?? 'Section') . '-Y' . $yearLevel;
    $offeringIds = [];
    foreach ($subjects as $sub) {
        $sid = (int)$sub['subject_id'];
        $teacherId = resolve_subject_teacher_id($pdo, $sid, $fallbackTeacherId);
        if ($teacherId <= 0) {
            continue;
        }

        $findOff->execute([':s' => $sid, ':t' => $teacherId, ':n' => $section]);
        $oid = (int)$findOff->fetchColumn();
        if ($oid <= 0) {
            // Reuse existing section offering if present (reassign teacher to specialty if wrong)
            $findAnySection->execute([':s' => $sid, ':n' => $section]);
            $row = $findAnySection->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $oid = (int)$row['id'];
                if ((int)$row['teacher_id'] !== $teacherId) {
                    $pdo->prepare('UPDATE subject_offerings SET teacher_id = :t WHERE id = :id')
                        ->execute([':t' => $teacherId, ':id' => $oid]);
                }
            } else {
                $insOff->execute([':s' => $sid, ':t' => $teacherId, ':n' => $section]);
                $oid = (int)$pdo->lastInsertId();
            }
        }
        if ($oid > 0) {
            $offeringIds[] = $oid;
            $insEn->execute([':stu' => $studentId, ':oid' => $oid]);
            if ($insEn->rowCount() > 0) {
                $result['enrolled']++;
            }
        }
    }

    $result['schedule'] = ensure_block_year_schedule($pdo, $blockId, $yearLevel, $offeringIds);
    return $result;
}

/**
 * Build (or keep) a varied schedule for a block + year level within 07:00–20:30.
 */
function ensure_block_year_schedule(PDO $pdo, int $blockId, int $yearLevel, array $offeringIds): int
{
    ensure_curriculum_schema($pdo);
    $offeringIds = array_values(array_unique(array_filter(array_map('intval', $offeringIds))));
    if ($blockId <= 0 || !$offeringIds) {
        return 0;
    }

    $exists = $pdo->prepare(
        'SELECT COUNT(*) FROM class_schedules WHERE block_id = :b AND year_level = :y'
    );
    $exists->execute([':b' => $blockId, ':y' => $yearLevel]);
    if ((int)$exists->fetchColumn() > 0) {
        // Enroll-only path: schedule already built for this block+year
        return 0;
    }

    $days = year_schedule_days($yearLevel);
    // Shuffle day order uniquely per block (still same count)
    $seed = crc32($blockId . '-Y' . $yearLevel);
    mt_srand($seed);
    if ($yearLevel >= 3) {
        $pool = [1, 2, 3, 4, 5, 6];
        shuffle($pool);
        $days = array_slice($pool, 0, 3);
        sort($days);
    } else {
        $days = $days;
        // Keep Mon/Tue/Fri/Sat but permute which subjects land where via seed
    }

    $offerings = $offeringIds;
    shuffle($offerings);

    // Possible 60-minute starts from 07:00 to 19:30 (end <= 20:30)
    $slotStarts = [];
    for ($m = 7 * 60; $m + 60 <= 20 * 60 + 30; $m += 30) {
        $slotStarts[] = $m; // minutes from midnight
    }

    $ins = $pdo->prepare(
        'INSERT INTO class_schedules (block_id, year_level, offering_id, day_of_week, start_time, end_time)
         VALUES (:b,:y,:o,:d,:s,:e)'
    );

    $n = 0;
    $perDay = (int)ceil(count($offerings) / max(1, count($days)));
    $idx = 0;
    foreach ($days as $dayIndex => $dow) {
        $daySlots = $slotStarts;
        // Offset start window so blocks don't share identical timelines
        $offset = ($seed + $dayIndex * 17 + $blockId * 3) % max(1, count($daySlots) - $perDay * 2);
        if ($offset > 0) {
            $daySlots = array_merge(array_slice($daySlots, $offset), array_slice($daySlots, 0, $offset));
        }
        // Prefer spaced slots (every other / with gaps)
        $picked = [];
        $cursor = ($seed + $dow * 5) % 3;
        foreach ($daySlots as $i => $startMin) {
            if ($i % 2 !== $cursor % 2) {
                continue;
            }
            $picked[] = $startMin;
            if (count($picked) >= $perDay) {
                break;
            }
        }
        while (count($picked) < $perDay && count($daySlots) > count($picked)) {
            foreach ($daySlots as $startMin) {
                if (!in_array($startMin, $picked, true)) {
                    $picked[] = $startMin;
                }
                if (count($picked) >= $perDay) {
                    break;
                }
            }
        }
        sort($picked);

        foreach ($picked as $startMin) {
            if ($idx >= count($offerings)) {
                break 2;
            }
            $oid = $offerings[$idx++];
            $endMin = $startMin + 60;
            if ($endMin > 20 * 60 + 30) {
                continue;
            }
            $start = sprintf('%02d:%02d:00', intdiv($startMin, 60), $startMin % 60);
            $end = sprintf('%02d:%02d:00', intdiv($endMin, 60), $endMin % 60);
            try {
                $ins->execute([
                    ':b' => $blockId,
                    ':y' => $yearLevel,
                    ':o' => $oid,
                    ':d' => $dow,
                    ':s' => $start,
                    ':e' => $end,
                ]);
                if ($ins->rowCount() > 0) {
                    $n++;
                }
            } catch (Throwable $e) {
                // skip conflicts
            }
        }
    }

    mt_srand(); // restore
    return $n;
}

function student_schedule_rows(int $studentId): array
{
    ensure_curriculum_schema();
    $stmt = db()->prepare(
        'SELECT cs.day_of_week, cs.start_time, cs.end_time,
                s.code AS subject_code, s.name AS subject_name, s.kind,
                o.name AS section_name, b.name AS block_name,
                CONCAT(t.first_name, " ", t.last_name) AS teacher_name,
                u.year_level
         FROM users u
         JOIN class_schedules cs ON cs.block_id = u.block_id AND cs.year_level = u.year_level
         JOIN subject_offerings o ON o.id = cs.offering_id
         JOIN subjects s ON s.id = o.subject_id
         JOIN blocks b ON b.id = cs.block_id
         JOIN users t ON t.id = o.teacher_id
         JOIN enrollments e ON e.student_id = u.id AND e.offering_id = o.id
         WHERE u.id = :id AND u.role = "student"
         ORDER BY cs.day_of_week, cs.start_time'
    );
    $stmt->execute([':id' => $studentId]);
    return $stmt->fetchAll();
}

function format_time_ampm(string $time): string
{
    $ts = strtotime($time);
    return $ts ? date('g:i A', $ts) : $time;
}
