<?php
/**
 * One-off smoke test: year curriculum enroll + schedule.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = db();
ensure_academics_schema($pdo);
ensure_curriculum_schema($pdo);

$bsit = (int)$pdo->query("SELECT id FROM courses WHERE code='BSIT' LIMIT 1")->fetchColumn();
echo "BSIT curriculum by year:\n";
$st = $pdo->prepare(
    'SELECT year_level, COUNT(*) c FROM curriculum_subjects WHERE course_id=? GROUP BY year_level ORDER BY year_level'
);
$st->execute([$bsit]);
foreach ($st as $r) {
    echo ' Y' . $r['year_level'] . ': ' . $r['c'] . "\n";
}

$block = (int)$pdo->query("SELECT id FROM blocks WHERE name LIKE 'BSIT%' ORDER BY id LIMIT 1")->fetchColumn();
if (!$block) {
    $block = (int)$pdo->query('SELECT id FROM blocks ORDER BY id LIMIT 1')->fetchColumn();
}
echo "block=$block\n";

function upsert_test_student(PDO $pdo, string $user, string $sid, int $block, int $prog, int $year): int
{
    $id = (int)$pdo->query("SELECT id FROM users WHERE username=" . $pdo->quote($user) . " LIMIT 1")->fetchColumn();
    if ($id <= 0) {
        $h = password_hash($sid, PASSWORD_DEFAULT);
        $pdo->prepare(
            'INSERT INTO users (username,password_hash,role,first_name,last_name,school_id,block_id,program_id,year_level,is_active)
             VALUES (?,?,?,?,?,?,?,?,?,1)'
        )->execute([$user, $h, 'student', 'Smoke', 'Test', $sid, $block, $prog, $year]);
        return (int)$pdo->lastInsertId();
    }
    $pdo->prepare('UPDATE users SET block_id=?, program_id=?, year_level=? WHERE id=?')
        ->execute([$block, $prog, $year, $id]);
    return $id;
}

$stu = upsert_test_student($pdo, 'Y1_SchedTest', '2026-TEST01', $block, $bsit, 1);
$r = enroll_student_year_curriculum($pdo, $stu, $block, $bsit, 1);
echo "Y1 enrolled={$r['enrolled']} schedule_slots={$r['schedule']}\n";

$rows = student_schedule_rows($stu);
echo 'Y1 schedule_rows=' . count($rows) . "\n";
$days = [];
foreach ($rows as $row) {
    $days[(int)$row['day_of_week']] = true;
    echo weekday_name((int)$row['day_of_week']) . ' '
        . format_time_ampm($row['start_time']) . '-' . format_time_ampm($row['end_time']) . ' '
        . $row['subject_code'] . "\n";
}
echo 'Y1 unique_days=' . count($days) . "\n";

$block2 = (int)$pdo->query('SELECT id FROM blocks WHERE id<>' . (int)$block . ' ORDER BY id LIMIT 1')->fetchColumn();
if (!$block2) {
    $block2 = $block;
}

$stu3 = upsert_test_student($pdo, 'Y3_SchedTest', '2026-TEST03', $block2, $bsit, 3);
$r3 = enroll_student_year_curriculum($pdo, $stu3, $block2, $bsit, 3);
echo "Y3 enrolled={$r3['enrolled']} sched={$r3['schedule']}\n";
$rows3 = student_schedule_rows($stu3);
$d3 = [];
foreach ($rows3 as $row) {
    $d3[(int)$row['day_of_week']] = true;
}
$dayNames = array_map('weekday_name', array_keys($d3));
echo 'Y3 rows=' . count($rows3) . ' days=' . count($d3) . ' (' . implode(',', $dayNames) . ")\n";

$stu2 = upsert_test_student($pdo, 'Y2_SchedTest', '2026-TEST02', $block, $bsit, 2);
$r2 = enroll_student_year_curriculum($pdo, $stu2, $block, $bsit, 2);
echo "Y2 enrolled={$r2['enrolled']} sched={$r2['schedule']} rows=" . count(student_schedule_rows($stu2)) . "\n";

$stu4 = upsert_test_student($pdo, 'Y4_SchedTest', '2026-TEST04', $block2, $bsit, 4);
$r4 = enroll_student_year_curriculum($pdo, $stu4, $block2, $bsit, 4);
echo "Y4 enrolled={$r4['enrolled']} sched={$r4['schedule']} rows=" . count(student_schedule_rows($stu4)) . "\n";

echo "OK\n";
