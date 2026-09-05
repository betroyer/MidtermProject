<?php
/**
 * One-shot: enroll every active student into every active subject offering.
 * Run: php scripts/enroll_all_students.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = db();
ensure_academics_schema($pdo);
ensure_user_active_column($pdo);

$students = $pdo->query(
    'SELECT id, username FROM users WHERE role = "student" AND COALESCE(is_active, 1) = 1 ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC);

$offerings = $pdo->query(
    'SELECT id FROM subject_offerings WHERE is_active = 1 ORDER BY id'
)->fetchAll(PDO::FETCH_COLUMN);

if (!$students) {
    fwrite(STDERR, "No active students found.\n");
    exit(1);
}
if (!$offerings) {
    fwrite(STDERR, "No active offerings found. Create offerings under Colleges & Programs first.\n");
    exit(1);
}

$ins = $pdo->prepare(
    'INSERT IGNORE INTO enrollments (student_id, offering_id) VALUES (:s, :o)'
);

$created = 0;
$skipped = 0;
foreach ($students as $s) {
    foreach ($offerings as $oid) {
        $ins->execute([':s' => (int)$s['id'], ':o' => (int)$oid]);
        if ($ins->rowCount() > 0) {
            $created++;
        } else {
            $skipped++;
        }
    }
}

$total = $pdo->query('SELECT COUNT(*) FROM enrollments')->fetchColumn();
echo sprintf(
    "Students: %d | Offerings: %d | New enrollments: %d | Already existed: %d | Total enrollments now: %d\n",
    count($students),
    count($offerings),
    $created,
    $skipped,
    (int)$total
);
