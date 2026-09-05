<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = db();
ensure_academics_schema($pdo);

$bsit = (int)$pdo->query("SELECT id FROM courses WHERE code='BSIT' LIMIT 1")->fetchColumn();
echo "capacity=" . block_student_capacity() . PHP_EOL;

$a1 = assign_open_block_for_program($pdo, $bsit);
echo "pick1 id={$a1['id']} name={$a1['name']} created=" . ($a1['created'] ? '1' : '0')
    . " count=" . block_active_student_count($pdo, $a1['id']) . PHP_EOL;

// Simulate filling: report all BSIT blocks
$rows = $pdo->prepare(
    'SELECT b.id, b.name,
      (SELECT COUNT(*) FROM users u WHERE u.role="student" AND u.block_id=b.id AND COALESCE(u.is_active,1)=1) c
     FROM blocks b WHERE b.course_id=:c ORDER BY b.id'
);
$rows->execute([':c' => $bsit]);
foreach ($rows as $r) {
    echo "  {$r['name']}: {$r['c']}/" . block_student_capacity() . PHP_EOL;
}
echo "OK\n";
