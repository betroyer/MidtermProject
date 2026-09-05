<?php
/**
 * Backfill year_level + curriculum enrollment + schedules for existing students.
 *
 * Year assignment (when year_level is NULL/0):
 * - Prefer age heuristic: <=18 → 1st, 19 → 2nd, 20 → 3rd, >=21 → 4th
 * - Smoke/test accounts keep any year already set
 * - Students already with year_level keep it and are re-enrolled
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = db();
ensure_academics_schema($pdo);
ensure_curriculum_schema($pdo);
ensure_user_profile_schema($pdo);

function infer_year_level(?int $age, ?int $existing): int
{
    if ($existing !== null && $existing >= 1 && $existing <= 4) {
        return $existing;
    }
    $age = (int)$age;
    if ($age <= 0) {
        return 1;
    }
    if ($age <= 18) {
        return 1;
    }
    if ($age === 19) {
        return 2;
    }
    if ($age === 20) {
        return 3;
    }
    return 4;
}

$students = $pdo->query(
    "SELECT u.id, u.username, u.first_name, u.last_name, u.school_id, u.age,
            u.year_level, u.block_id, u.program_id,
            b.name AS block_name, b.course_id AS block_course_id,
            c.code AS program_code
     FROM users u
     LEFT JOIN blocks b ON b.id = u.block_id
     LEFT JOIN courses c ON c.id = u.program_id
     WHERE u.role = 'student'
     ORDER BY u.id"
)->fetchAll(PDO::FETCH_ASSOC);

$bsit = (int)$pdo->query("SELECT id FROM courses WHERE code='BSIT' LIMIT 1")->fetchColumn();
$upd = $pdo->prepare('UPDATE users SET year_level = :y, program_id = COALESCE(program_id, :p) WHERE id = :id');

echo "Backfilling " . count($students) . " students...\n";

foreach ($students as $s) {
    $id = (int)$s['id'];
    $blockId = (int)($s['block_id'] ?? 0);
    $programId = (int)($s['program_id'] ?? 0);
    if ($programId <= 0) {
        $programId = (int)($s['block_course_id'] ?? 0) ?: $bsit;
    }
    if ($blockId <= 0 || $programId <= 0) {
        echo "SKIP #{$id} {$s['username']} — missing block or program\n";
        continue;
    }

    $year = infer_year_level(
        isset($s['age']) ? (int)$s['age'] : null,
        isset($s['year_level']) ? (int)$s['year_level'] : null
    );

    $upd->execute([':y' => $year, ':p' => $programId, ':id' => $id]);
    $r = enroll_student_year_curriculum($pdo, $id, $blockId, $programId, $year);
    $schedRows = count(student_schedule_rows($id));

    echo sprintf(
        "OK #%d %s → Y%d %s | new_enroll=%d sched_slots=%d visible_rows=%d\n",
        $id,
        $s['username'],
        $year,
        $s['block_name'] ?: 'block#' . $blockId,
        $r['enrolled'],
        $r['schedule'],
        $schedRows
    );
}

echo "Done.\n";
