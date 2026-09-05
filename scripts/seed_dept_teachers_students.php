<?php
/**
 * scripts/seed_dept_teachers_students.php
 * Assigns existing CICT teachers to CICT department and seeds one demo
 * teacher + block + 2 students per other college (using catalog courses).
 *
 * Run: php scripts/seed_dept_teachers_students.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validation.php';

$pdo = db();
ensure_academics_schema($pdo);
ensure_user_profile_schema($pdo);
ensure_user_active_column($pdo);

$cictId = (int)$pdo->query("SELECT id FROM departments WHERE code='CICT'")->fetchColumn();
if ($cictId > 0) {
    $n = $pdo->exec(
        "UPDATE users u
         LEFT JOIN blocks b ON b.teacher_id = u.id
         SET u.department_id = {$cictId}
         WHERE u.role = 'teacher'
           AND (u.department_id IS NULL OR u.department_id = 0)
           AND (b.department_id = {$cictId} OR b.id IS NULL)"
    );
    echo "CICT department assigned on teachers (rows touched may include multi-join).\n";
    $pdo->prepare(
        'UPDATE users SET department_id = :d WHERE role = "teacher" AND username IN
         ("B_Delossantos","C_Reyes","D_Garcia","E_Lopez","F_Ramos","G_Torres","H_Villanueva")'
    )->execute([':d' => $cictId]);
}

$demos = [
    ['CBGG', 'BSAIS', 'CBGG-Block1', 'P_Reyes', 'Paula', 'Reyes', '2026-02001',
        [['Lara', 'Gomez'], ['Marco', 'Dizon']]],
    ['CTE', 'BEEd', 'CTE-Block1', 'N_Santos', 'Nina', 'Santos', '2026-02002',
        [['Ella', 'Cruz'], ['Paolo', 'Lim']]],
    ['CAF', 'BSFisheries', 'CAF-Block1', 'R_Villanueva', 'Ramon', 'Villanueva', '2026-02003',
        [['Ivy', 'Torres'], ['Joel', 'Navarro']]],
    ['CCJE', 'BSCrim', 'CCJE-Block1', 'S_Domingo', 'Sofia', 'Domingo', '2026-02004',
        [['Kara', 'Mendoza'], ['Luis', 'Aquino']]],
    ['DCE', 'BSCE-Structural', 'DCE-Block1', 'T_Garcia', 'Tomas', 'Garcia', '2026-02005',
        [['Mia', 'Fernandez'], ['Noel', 'Bautista']]],
];

function ensure_demo_user(PDO $pdo, string $role, array $data, ?int $blockId, ?string $sid, ?int $programId, ?int $deptId): int
{
    $chk = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $chk->execute([':u' => $data['username']]);
    $id = (int)$chk->fetchColumn();
    $hash = password_hash($sid ?: '2026-00000', PASSWORD_BCRYPT);
    if ($id > 0) {
        $pdo->prepare(
            'UPDATE users SET role=:r, password_hash=:h, school_id=:sid, first_name=:f, last_name=:l,
                    email=:e, phone=:p, age=:a, block_id=:b, program_id=:prog, department_id=:dept,
                    address=:addr, emergency_name=:en, emergency_relation=:er, emergency_address=:ea, emergency_phone=:ep,
                    is_active=1
             WHERE id=:id'
        )->execute([
            ':r' => $role, ':h' => $hash, ':sid' => $sid, ':f' => $data['first'], ':l' => $data['last'],
            ':e' => $data['email'], ':p' => $data['phone'], ':a' => $data['age'],
            ':b' => $blockId, ':prog' => $programId, ':dept' => $deptId,
            ':addr' => $data['address'] ?? 'Sample address, City',
            ':en' => $data['emergency_name'] ?? 'Emergency Contact',
            ':er' => $data['emergency_relation'] ?? 'parent',
            ':ea' => $data['emergency_address'] ?? 'Sample address, City',
            ':ep' => $data['emergency_phone'] ?? '09171234567',
            ':id' => $id,
        ]);
        return $id;
    }
    $pdo->prepare(
        'INSERT INTO users (role, username, password_hash, school_id, first_name, last_name, email, phone, age,
                            address, emergency_name, emergency_relation, emergency_address, emergency_phone,
                            block_id, program_id, department_id, is_active)
         VALUES (:r,:u,:h,:sid,:f,:l,:e,:p,:a,:addr,:en,:er,:ea,:ep,:b,:prog,:dept,1)'
    )->execute([
        ':r' => $role, ':u' => $data['username'], ':h' => $hash, ':sid' => $sid,
        ':f' => $data['first'], ':l' => $data['last'], ':e' => $data['email'],
        ':p' => $data['phone'], ':a' => $data['age'],
        ':addr' => $data['address'] ?? 'Sample address, City',
        ':en' => $data['emergency_name'] ?? 'Emergency Contact',
        ':er' => $data['emergency_relation'] ?? 'parent',
        ':ea' => $data['emergency_address'] ?? 'Sample address, City',
        ':ep' => $data['emergency_phone'] ?? '09171234567',
        ':b' => $blockId, ':prog' => $programId, ':dept' => $deptId,
    ]);
    return (int)$pdo->lastInsertId();
}

$seq = 301;
foreach ($demos as [$deptCode, $courseCode, $blockName, $tUser, $tFirst, $tLast, $tSid, $students]) {
    $deptId = (int)$pdo->query('SELECT id FROM departments WHERE code=' . $pdo->quote($deptCode))->fetchColumn();
    $courseId = (int)$pdo->query('SELECT id FROM courses WHERE code=' . $pdo->quote($courseCode))->fetchColumn();
    if ($deptId <= 0 || $courseId <= 0) {
        echo "SKIP {$deptCode}/{$courseCode} — catalog missing\n";
        continue;
    }

    $tid = ensure_demo_user($pdo, 'teacher', [
        'username' => $tUser,
        'first' => $tFirst,
        'last' => $tLast,
        'email' => strtolower($tFirst . $tLast) . '@gmail.com',
        'phone' => '0918' . str_pad((string)$seq, 7, '0', STR_PAD_LEFT),
        'age' => 30,
    ], null, $tSid, null, $deptId);

    $findB = $pdo->prepare('SELECT id FROM blocks WHERE name = :n');
    $findB->execute([':n' => $blockName]);
    $bid = (int)$findB->fetchColumn();
    if ($bid > 0) {
        $pdo->prepare(
            'UPDATE blocks SET teacher_id=:t, department_id=:d, course_id=:c WHERE id=:id'
        )->execute([':t' => $tid, ':d' => $deptId, ':c' => $courseId, ':id' => $bid]);
    } else {
        $pdo->prepare(
            'INSERT INTO blocks (name, teacher_id, department_id, course_id) VALUES (:n,:t,:d,:c)'
        )->execute([':n' => $blockName, ':t' => $tid, ':d' => $deptId, ':c' => $courseId]);
        $bid = (int)$pdo->lastInsertId();
    }
    $pdo->prepare('UPDATE users SET block_id = :b WHERE id = :id')->execute([':b' => $bid, ':id' => $tid]);

    foreach ($students as $i => [$sf, $sl]) {
        $seq++;
        $sid = sprintf('2026-%05d', 400 + $seq);
        $uname = strtoupper(substr($sf, 0, 1)) . '_' . ucfirst(strtolower($sl));
        $stuId = ensure_demo_user($pdo, 'student', [
            'username' => $uname,
            'first' => $sf,
            'last' => $sl,
            'email' => strtolower($sf . $sl) . '@gmail.com',
            'phone' => '0920' . str_pad((string)$seq, 7, '0', STR_PAD_LEFT),
            'age' => 18 + ($i % 3),
            'address' => $deptCode . ' student address, City',
            'emergency_name' => 'Parent ' . $sl,
            'emergency_relation' => $i === 0 ? 'parent' : 'guardian',
            'emergency_address' => $deptCode . ' emergency address, City',
            'emergency_phone' => '0917' . str_pad((string)$seq, 7, '0', STR_PAD_LEFT),
        ], $bid, $sid, $courseId, null);
        auto_enroll_student_for_block($pdo, $stuId, $bid);
        echo "Student {$uname} / {$sid} → {$blockName}\n";
    }
    echo "Teacher {$tUser} → {$deptCode} / {$blockName}\n";
}

echo "Done.\n";
