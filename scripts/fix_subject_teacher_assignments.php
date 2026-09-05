<?php
/**
 * scripts/fix_subject_teacher_assignments.php
 *
 * Problem: curriculum enroll used to assign EVERY subject offering to the block adviser
 * (e.g. B_Delossantos saw GE101…PE201). Teachers should only own their specialty
 * (Bruno → Programming only).
 *
 * This script:
 *  1) Creates missing specialty teachers (school ID = password)
 *  2) Reassigns subject_offerings (+ grades.teacher_id) per specialty map
 *  3) Merges duplicate section offerings when needed
 *
 * Run: php scripts/fix_subject_teacher_assignments.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/curriculum.php';

$pdo = db();
ensure_academics_schema($pdo);
ensure_curriculum_schema($pdo);

$teachersToEnsure = [
    ['username' => 'B_Delossantos', 'first' => 'Bruno', 'last' => 'Delossantos', 'email' => 'brunodelossantos@gmail.com', 'phone' => '09171234567', 'age' => 34, 'sid' => '2026-00200'],
    ['username' => 'C_Reyes', 'first' => 'Carla', 'last' => 'Reyes', 'email' => 'carlareyes@gmail.com', 'phone' => '09181234567', 'age' => 29, 'sid' => '2026-00300'],
    ['username' => 'D_Garcia', 'first' => 'Diego', 'last' => 'Garcia', 'email' => 'diegogarcia@gmail.com', 'phone' => '09191234567', 'age' => 31, 'sid' => '2026-00400'],
    ['username' => 'E_Lopez', 'first' => 'Elena', 'last' => 'Lopez', 'email' => 'elenalopez@gmail.com', 'phone' => '09201234001', 'age' => 28, 'sid' => '2026-00500'],
    ['username' => 'F_Ramos', 'first' => 'Felix', 'last' => 'Ramos', 'email' => 'felixramos@gmail.com', 'phone' => '09201234002', 'age' => 35, 'sid' => '2026-00600'],
    ['username' => 'G_Torres', 'first' => 'Grace', 'last' => 'Torres', 'email' => 'gracetorres@gmail.com', 'phone' => '09201234003', 'age' => 27, 'sid' => '2026-00700'],
    ['username' => 'H_Villanueva', 'first' => 'Hector', 'last' => 'Villanueva', 'email' => 'hectorvillanueva@gmail.com', 'phone' => '09201234004', 'age' => 33, 'sid' => '2026-00800'],
    ['username' => 'G_Santos', 'first' => 'Gina', 'last' => 'Santos', 'email' => 'ginasantos@gmail.com', 'phone' => '09201234005', 'age' => 30, 'sid' => '2026-00900'],
    ['username' => 'P_Cruz', 'first' => 'Paolo', 'last' => 'Cruz', 'email' => 'paolocruz@gmail.com', 'phone' => '09201234006', 'age' => 32, 'sid' => '2026-01000'],
];

function ensure_teacher(PDO $pdo, array $t): int
{
    $find = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $find->execute([':u' => $t['username']]);
    $id = (int)$find->fetchColumn();
    $hash = password_hash($t['sid'], PASSWORD_BCRYPT);
    if ($id > 0) {
        // Keep existing password (Bruno may use 123); only ensure role/active/profile basics
        $pdo->prepare(
            'UPDATE users SET role="teacher", first_name=:f, last_name=:l, email=:e, phone=:p, age=:a,
                    school_id=COALESCE(NULLIF(school_id,""), :sid), is_active=1 WHERE id=:id'
        )->execute([
            ':f' => $t['first'], ':l' => $t['last'], ':e' => $t['email'],
            ':p' => $t['phone'], ':a' => $t['age'], ':sid' => $t['sid'], ':id' => $id,
        ]);
        return $id;
    }
    $pdo->prepare(
        'INSERT INTO users (role, username, password_hash, school_id, first_name, last_name, email, phone, age, is_active)
         VALUES ("teacher",:u,:h,:sid,:f,:l,:e,:p,:a,1)'
    )->execute([
        ':u' => $t['username'], ':h' => $hash, ':sid' => $t['sid'],
        ':f' => $t['first'], ':l' => $t['last'], ':e' => $t['email'],
        ':p' => $t['phone'], ':a' => $t['age'],
    ]);
    return (int)$pdo->lastInsertId();
}

echo "Ensuring specialty teachers…\n";
foreach ($teachersToEnsure as $t) {
    $id = ensure_teacher($pdo, $t);
    echo "  {$t['username']} → #{$id}\n";
}

$map = subject_teacher_specialty_map();
$offerings = $pdo->query(
    'SELECT o.id, o.subject_id, o.teacher_id, o.name, o.is_active, s.code, s.name AS subject_name
     FROM subject_offerings o
     JOIN subjects s ON s.id = o.subject_id
     ORDER BY s.code, o.name, o.id'
)->fetchAll(PDO::FETCH_ASSOC);

$moved = 0;
$merged = 0;
$skipped = 0;

$pdo->beginTransaction();
try {
    foreach ($offerings as $o) {
        $code = strtoupper(trim((string)$o['code']));
        if (!isset($map[$code])) {
            $skipped++;
            continue;
        }
        $wantUser = $map[$code];
        $wantId = (int)$pdo->query(
            'SELECT id FROM users WHERE username=' . $pdo->quote($wantUser) . ' AND role="teacher" LIMIT 1'
        )->fetchColumn();
        if ($wantId <= 0) {
            echo "  WARN: missing teacher {$wantUser} for {$code}\n";
            continue;
        }
        if ((int)$o['teacher_id'] === $wantId) {
            continue;
        }

        $oid = (int)$o['id'];
        $sid = (int)$o['subject_id'];
        $section = (string)$o['name'];

        // If target already has same subject+section, merge into that offering
        $dup = $pdo->prepare(
            'SELECT id FROM subject_offerings
             WHERE subject_id = :s AND teacher_id = :t AND name = :n AND id <> :id LIMIT 1'
        );
        $dup->execute([':s' => $sid, ':t' => $wantId, ':n' => $section, ':id' => $oid]);
        $targetOid = (int)$dup->fetchColumn();

        if ($targetOid > 0) {
            // Move enrollments
            $ens = $pdo->prepare('SELECT student_id FROM enrollments WHERE offering_id = :o');
            $ens->execute([':o' => $oid]);
            $insEn = $pdo->prepare('INSERT IGNORE INTO enrollments (student_id, offering_id) VALUES (:s,:o)');
            foreach ($ens->fetchAll(PDO::FETCH_COLUMN) as $stu) {
                $insEn->execute([':s' => (int)$stu, ':o' => $targetOid]);
            }
            $pdo->prepare('DELETE FROM enrollments WHERE offering_id = :o')->execute([':o' => $oid]);

            // Point schedules / grades / reports at target
            try {
                $pdo->prepare('UPDATE class_schedules SET offering_id = :n WHERE offering_id = :o')
                    ->execute([':n' => $targetOid, ':o' => $oid]);
            } catch (Throwable $e) {
                // table may not exist on older DB
            }
            $pdo->prepare('UPDATE grades SET offering_id = :n, teacher_id = :t WHERE offering_id = :o')
                ->execute([':n' => $targetOid, ':t' => $wantId, ':o' => $oid]);
            $pdo->prepare('UPDATE grade_reports SET offering_id = :n WHERE offering_id = :o')
                ->execute([':n' => $targetOid, ':o' => $oid]);
            $pdo->prepare('DELETE FROM subject_offerings WHERE id = :id')->execute([':id' => $oid]);
            echo "  MERGE {$code} / {$section} #{$oid} → #{$targetOid} ({$wantUser})\n";
            $merged++;
        } else {
            $pdo->prepare('UPDATE subject_offerings SET teacher_id = :t WHERE id = :id')
                ->execute([':t' => $wantId, ':id' => $oid]);
            $pdo->prepare('UPDATE grades SET teacher_id = :t WHERE offering_id = :o')
                ->execute([':t' => $wantId, ':o' => $oid]);
            echo "  MOVE {$code} / {$section} #{$oid} → {$wantUser}\n";
            $moved++;
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\nDone. Moved={$moved}, Merged={$merged}, Skipped(no specialty)={$skipped}\n\n";
echo "Offerings per teacher (after):\n";
foreach ($pdo->query(
    "SELECT t.username, COUNT(*) c, GROUP_CONCAT(DISTINCT s.code ORDER BY s.code SEPARATOR ', ') codes
     FROM subject_offerings o
     JOIN users t ON t.id = o.teacher_id
     JOIN subjects s ON s.id = o.subject_id
     WHERE o.is_active = 1
     GROUP BY t.id, t.username
     ORDER BY t.username"
) as $r) {
    echo "  {$r['username']}: {$r['c']} → {$r['codes']}\n";
}

$bruno = $pdo->query(
    "SELECT GROUP_CONCAT(DISTINCT s.code ORDER BY s.code SEPARATOR ', ')
     FROM subject_offerings o
     JOIN subjects s ON s.id = o.subject_id
     JOIN users t ON t.id = o.teacher_id
     WHERE t.username='B_Delossantos' AND o.is_active=1"
)->fetchColumn();
echo "\nB_Delossantos subjects now: " . ($bruno ?: '(none)') . "\n";
echo "Login tip: new teachers use School ID as password (e.g. G_Santos / 2026-00900).\n";
echo "B_Delossantos may still use password 123 if previously set.\n";
