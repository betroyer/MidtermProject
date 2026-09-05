<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$pdo = db();
ensure_user_school_id_column($pdo);
$rows = $pdo->query(
    "SELECT id, username FROM users WHERE role IN ('student','teacher') AND (school_id IS NULL OR school_id='')"
)->fetchAll(PDO::FETCH_ASSOC);
$upd = $pdo->prepare('UPDATE users SET school_id = :s WHERE id = :id');
$n = 0;
foreach ($rows as $r) {
    $sid = generate_next_school_id($pdo);
    $upd->execute([':s' => $sid, ':id' => (int)$r['id']]);
    echo $r['username'] . ' => ' . $sid . PHP_EOL;
    $n++;
}
echo "Filled {$n} missing school_id(s).\n";
