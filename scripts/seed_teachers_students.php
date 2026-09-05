<?php
/**
 * scripts/seed_teachers_students.php
 * Ensures 7 teachers (2 existing + 5 new), each with a BSIT block and 10 students.
 * Creates CICT subject offerings with different teachers and enrolls block students.
 * Password for every teacher/student = their school ID (bcrypt).
 *
 * Run: php scripts/seed_teachers_students.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();
ensure_academics_schema($pdo);

$teachers = [
    ['username' => 'B_Delossantos', 'first' => 'Bruno', 'last' => 'Delossantos', 'email' => 'brunodelossantos@gmail.com', 'phone' => '09171234567', 'age' => 34, 'sid' => '2026-00200', 'block' => 'BSIT-Block1'],
    ['username' => 'C_Reyes', 'first' => 'Carla', 'last' => 'Reyes', 'email' => 'carlareyes@gmail.com', 'phone' => '09181234567', 'age' => 29, 'sid' => '2026-00300', 'block' => 'BSIT-Block2'],
    ['username' => 'D_Garcia', 'first' => 'Diego', 'last' => 'Garcia', 'email' => 'diegogarcia@gmail.com', 'phone' => '09191234567', 'age' => 31, 'sid' => '2026-00400', 'block' => 'BSIT-Block3'],
    ['username' => 'E_Lopez', 'first' => 'Elena', 'last' => 'Lopez', 'email' => 'elenalopez@gmail.com', 'phone' => '09201234001', 'age' => 28, 'sid' => '2026-00500', 'block' => 'BSIT-Block4'],
    ['username' => 'F_Ramos', 'first' => 'Felix', 'last' => 'Ramos', 'email' => 'felixramos@gmail.com', 'phone' => '09201234002', 'age' => 35, 'sid' => '2026-00600', 'block' => 'BSIT-Block5'],
    ['username' => 'G_Torres', 'first' => 'Grace', 'last' => 'Torres', 'email' => 'gracetorres@gmail.com', 'phone' => '09201234003', 'age' => 27, 'sid' => '2026-00700', 'block' => 'BSIT-Block6'],
    ['username' => 'H_Villanueva', 'first' => 'Hector', 'last' => 'Villanueva', 'email' => 'hectorvillanueva@gmail.com', 'phone' => '09201234004', 'age' => 33, 'sid' => '2026-00800', 'block' => 'BSIT-Block7'],
];

$studentsByBlock = [
    'BSIT-Block1' => [
        ['Alice', 'Mendoza'], ['John', 'Santos'], ['Maria', 'Cruz'], ['Nina', 'Bautista'], ['Oscar', 'Diaz'],
        ['Paula', 'Fernandez'], ['Quinn', 'Gomez'], ['Rita', 'Hernandez'], ['Sam', 'Ibarra'], ['Tina', 'Jimenez'],
    ],
    'BSIT-Block2' => [
        ['Kevin', 'Tan'], ['Liza', 'Ong'], ['Marco', 'Perez'], ['Nora', 'Quinto'], ['Owen', 'Reyes'],
        ['Pia', 'Salazar'], ['Rico', 'Torres'], ['Sara', 'Umandap'], ['Troy', 'Valdez'], ['Una', 'Wong'],
    ],
    'BSIT-Block3' => [
        ['Ana', 'Aquino'], ['Ben', 'Bernardo'], ['Cora', 'Castillo'], ['Dan', 'Domingo'], ['Eva', 'Espino'],
        ['Faye', 'Flores'], ['Gabe', 'Gutierrez'], ['Hana', 'Herrera'], ['Ian', 'Ignacio'], ['Jade', 'Javier'],
    ],
    'BSIT-Block4' => [
        ['Kara', 'Lim'], ['Leo', 'Manalo'], ['Mia', 'Navarro'], ['Ned', 'Ocampo'], ['Olive', 'Padilla'],
        ['Pete', 'Quiambao'], ['Rose', 'Rivera'], ['Sean', 'Santiago'], ['Tess', 'Tuazon'], ['Uri', 'Uy'],
    ],
    'BSIT-Block5' => [
        ['Vera', 'Abad'], ['Will', 'Bondoc'], ['Xena', 'Cortez'], ['Yuri', 'Delacruz'], ['Zara', 'Espiritu'],
        ['Aria', 'Fajardo'], ['Blake', 'Gonzales'], ['Cleo', 'Hopkinson'], ['Drew', 'Isidro'], ['Elle', 'Jacinto'],
    ],
    'BSIT-Block6' => [
        ['Finn', 'Katigbak'], ['Gina', 'Labrador'], ['Hugo', 'Mercado'], ['Ivy', 'Nolasco'], ['Joel', 'Ortega'],
        ['Kate', 'Pascual'], ['Luis', 'Quizon'], ['Mona', 'Ramos'], ['Nick', 'Soriano'], ['Opal', 'Tolentino'],
    ],
    'BSIT-Block7' => [
        ['Pam', 'Urbano'], ['Quin', 'Velasco'], ['Rae', 'Wagan'], ['Sid', 'Xavier'], ['Tia', 'Yap'],
        ['Ulys', 'Zamora'], ['Vee', 'Alonzo'], ['Wes', 'Briones'], ['Xio', 'Cabrera'], ['Yen', 'Dizon'],
    ],
];

function ensure_user(PDO $pdo, string $role, array $u, ?int $blockId, string $schoolId, ?int $programId = null): int
{
    $find = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $find->execute([':u' => $u['username']]);
    $id = $find->fetchColumn();
    $hash = password_hash($schoolId, PASSWORD_BCRYPT);

    if ($id) {
        $upd = $pdo->prepare(
            'UPDATE users SET role=:r, password_hash=:h, school_id=:sid, first_name=:f, last_name=:l, email=:e,
                    phone=:p, age=:a, block_id=:b, program_id=:prog, is_active=1 WHERE id=:id'
        );
        $upd->execute([
            ':r' => $role, ':h' => $hash, ':sid' => $schoolId, ':f' => $u['first'], ':l' => $u['last'],
            ':e' => $u['email'], ':p' => $u['phone'], ':a' => $u['age'], ':b' => $blockId,
            ':prog' => $programId, ':id' => $id,
        ]);
        return (int)$id;
    }

    $ins = $pdo->prepare(
        'INSERT INTO users (role, username, password_hash, school_id, first_name, last_name, email, phone, age, block_id, program_id, is_active)
         VALUES (:r,:u,:h,:sid,:f,:l,:e,:p,:a,:b,:prog,1)'
    );
    $ins->execute([
        ':r' => $role, ':u' => $u['username'], ':h' => $hash, ':sid' => $schoolId, ':f' => $u['first'], ':l' => $u['last'],
        ':e' => $u['email'], ':p' => $u['phone'], ':a' => $u['age'], ':b' => $blockId, ':prog' => $programId,
    ]);
    return (int)$pdo->lastInsertId();
}

function make_username(string $first, string $last): string
{
    $initial = strtoupper(substr(preg_replace('/\s+/', '', $first), 0, 1));
    $lastClean = ucfirst(strtolower(preg_replace('/\s+/', '', $last)));
    return $initial . '_' . $lastClean;
}

$cictId = (int)$pdo->query("SELECT id FROM departments WHERE code='CICT'")->fetchColumn();
$bsitId = (int)$pdo->query("SELECT id FROM courses WHERE code='BSIT'")->fetchColumn();
if ($cictId <= 0 || $bsitId <= 0) {
    fwrite(STDERR, "CICT/BSIT catalog missing. Open any admin page once or fix departments/courses.\n");
    exit(1);
}

// Ensure starter CICT subjects exist
$subjectDefs = [
    ['IT101', 'Programming 1'],
    ['IT102', 'Programming 2'],
    ['IT201', 'Data Structures'],
    ['IT202', 'Database Systems'],
    ['IT301', 'Web Development'],
];
$insSub = $pdo->prepare(
    'INSERT IGNORE INTO subjects (code, name, department_id, units) VALUES (:c,:n,:d,3)'
);
foreach ($subjectDefs as [$code, $name]) {
    $insSub->execute([':c' => $code, ':n' => $name, ':d' => $cictId]);
}
$subjectIds = [];
foreach ($subjectDefs as [$code]) {
    $subjectIds[$code] = (int)$pdo->query('SELECT id FROM subjects WHERE code=' . $pdo->quote($code))->fetchColumn();
}

$pdo->beginTransaction();
try {
    $teacherIds = [];
    $blockIds = [];
    $teacherIdList = [];

    foreach ($teachers as $t) {
        $teacherIds[$t['block']] = ensure_user($pdo, 'teacher', [
            'username' => $t['username'],
            'first' => $t['first'],
            'last' => $t['last'],
            'email' => $t['email'],
            'phone' => $t['phone'],
            'age' => $t['age'],
        ], null, $t['sid'], null);
        $teacherIdList[] = $teacherIds[$t['block']];
    }

    foreach ($teachers as $t) {
        $name = $t['block'];
        $tid = $teacherIds[$name];
        $find = $pdo->prepare('SELECT id FROM blocks WHERE name = :n LIMIT 1');
        $find->execute([':n' => $name]);
        $bid = $find->fetchColumn();
        if ($bid) {
            $pdo->prepare(
                'UPDATE blocks SET teacher_id = :t, department_id = :d, course_id = :c WHERE id = :id'
            )->execute([':t' => $tid, ':d' => $cictId, ':c' => $bsitId, ':id' => $bid]);
            $blockIds[$name] = (int)$bid;
        } else {
            $pdo->prepare(
                'INSERT INTO blocks (name, teacher_id, department_id, course_id) VALUES (:n,:t,:d,:c)'
            )->execute([':n' => $name, ':t' => $tid, ':d' => $cictId, ':c' => $bsitId]);
            $blockIds[$name] = (int)$pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE users SET block_id = :b WHERE id = :id')->execute([':b' => $blockIds[$name], ':id' => $tid]);
    }

    // Companion block per teacher (1 teacher → many blocks demo)
    foreach ($teachers as $t) {
        $primary = $t['block'];
        $name = $primary . 'B';
        $tid = $teacherIds[$primary];
        $find = $pdo->prepare('SELECT id FROM blocks WHERE name = :n LIMIT 1');
        $find->execute([':n' => $name]);
        $bid = $find->fetchColumn();
        if ($bid) {
            $pdo->prepare(
                'UPDATE blocks SET teacher_id = :t, department_id = :d, course_id = :c WHERE id = :id'
            )->execute([':t' => $tid, ':d' => $cictId, ':c' => $bsitId, ':id' => $bid]);
        } else {
            $pdo->prepare(
                'INSERT INTO blocks (name, teacher_id, department_id, course_id) VALUES (:n,:t,:d,:c)'
            )->execute([':n' => $name, ':t' => $tid, ':d' => $cictId, ':c' => $bsitId]);
        }
        echo "Block {$name} → teacher {$t['username']}\n";
    }

    // One offering per subject, taught by rotating teachers (multi-teacher demo)
    $offeringIds = [];
    $i = 0;
    foreach ($subjectDefs as [$code, $name]) {
        $sid = $subjectIds[$code];
        $tid = $teacherIdList[$i % count($teacherIdList)];
        $section = 'BSIT-' . chr(65 + ($i % 7));
        $find = $pdo->prepare(
            'SELECT id FROM subject_offerings WHERE subject_id = :s AND teacher_id = :t LIMIT 1'
        );
        $find->execute([':s' => $sid, ':t' => $tid]);
        $oid = (int)$find->fetchColumn();
        if ($oid <= 0) {
            $pdo->prepare(
                'INSERT INTO subject_offerings (subject_id, teacher_id, name, is_active) VALUES (:s,:t,:n,1)'
            )->execute([':s' => $sid, ':t' => $tid, ':n' => $section]);
            $oid = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare(
                'UPDATE subject_offerings SET name = :n, is_active = 1 WHERE id = :id'
            )->execute([':n' => $section, ':id' => $oid]);
        }
        $offeringIds[] = $oid;
        echo "Offering {$code} / {$section} → teacher#{$tid}\n";
        $i++;
    }

    $schoolSeq = 101;
    $enroll = $pdo->prepare(
        'INSERT IGNORE INTO enrollments (student_id, offering_id) VALUES (:s,:o)'
    );

    foreach ($studentsByBlock as $blockName => $roster) {
        $bid = $blockIds[$blockName];
        $usedUsernames = [];
        foreach ($roster as $si => [$first, $last]) {
            $base = make_username($first, $last);
            $username = $base;
            $n = 1;
            while (true) {
                $chk = $pdo->prepare('SELECT id FROM users WHERE username = :u');
                $chk->execute([':u' => $username]);
                $exists = $chk->fetchColumn();
                if (!$exists && !isset($usedUsernames[$username])) {
                    break;
                }
                if ($exists) {
                    $roleChk = $pdo->prepare('SELECT role FROM users WHERE id = :id');
                    $roleChk->execute([':id' => $exists]);
                    if ($roleChk->fetchColumn() === 'student') {
                        break;
                    }
                }
                $n++;
                $username = $base . $n;
            }
            $usedUsernames[$username] = true;
            $sid = sprintf('2026-%05d', $schoolSeq++);
            $phone = sprintf('0920%07d', 1000000 + $schoolSeq);
            $studentId = ensure_user($pdo, 'student', [
                'username' => $username,
                'first' => $first,
                'last' => $last,
                'email' => strtolower(preg_replace('/\s+/', '', $first . $last)) . '@gmail.com',
                'phone' => substr($phone, 0, 11),
                'age' => 18 + ($si % 5),
            ], $bid, $sid, $bsitId);

            // Enroll each student in 3 offerings with different teachers
            $pick = [
                $offeringIds[$si % count($offeringIds)],
                $offeringIds[($si + 1) % count($offeringIds)],
                $offeringIds[($si + 2) % count($offeringIds)],
            ];
            foreach (array_unique($pick) as $oid) {
                $enroll->execute([':s' => $studentId, ':o' => $oid]);
            }
            echo "Student {$username} / {$sid} → {$blockName} (BSIT, 3 offerings)\n";
        }
    }

    // Demo grades for every enrollment (so teacher reports are fully populated)
    require_once __DIR__ . '/../includes/validation.php';
    $miss = $pdo->query(
        'SELECT e.student_id, e.offering_id, o.teacher_id, s.name AS subject_name
         FROM enrollments e
         JOIN subject_offerings o ON o.id = e.offering_id
         JOIN subjects s ON s.id = o.subject_id
         LEFT JOIN grades g ON g.student_id = e.student_id AND g.offering_id = e.offering_id
         WHERE g.id IS NULL'
    )->fetchAll(PDO::FETCH_ASSOC);
    $insG = $pdo->prepare(
        "INSERT INTO grades (student_id, teacher_id, offering_id, subject, quiz_scores, activity_scores,
          midterm, midterm_max, final_exam, final_exam_max, quiz_avg, activity_avg, final_grade, grade_point, remark)
         VALUES (:sid,:tid,:oid,:subj,'[]','[]',:mid,100,:fin,100,0,0,:fg,:gp,:rem)"
    );
    $gradeCount = 0;
    foreach ($miss as $row) {
        $seed = ((int)$row['student_id'] * 17 + (int)$row['offering_id'] * 13) % 21;
        $mid = min(100, 75 + $seed);
        $fin = min(100, 78 + (($seed * 3) % 18));
        [, , $sem, $remark, $point] = compute_grade([], [], (float)$mid, (float)$fin);
        try {
            $insG->execute([
                ':sid' => $row['student_id'], ':tid' => $row['teacher_id'], ':oid' => $row['offering_id'],
                ':subj' => $row['subject_name'], ':mid' => $mid, ':fin' => $fin,
                ':fg' => $sem, ':gp' => $point, ':rem' => $remark,
            ]);
            $gradeCount++;
        } catch (Throwable $e) {
            // ignore unique conflicts
        }
    }

    $pdo->commit();
    echo "Done. Teachers: " . count($teacherIds) . ", Blocks: " . count($blockIds)
        . ", Offerings: " . count($offeringIds) . ", Students seeded per block: 10, Grades filled: {$gradeCount}\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
