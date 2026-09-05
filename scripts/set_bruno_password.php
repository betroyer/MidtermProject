<?php
/**
 * One-off: set B_Delossantos password to 123 (as requested).
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();
$hash = password_hash('123', PASSWORD_BCRYPT);
$stmt = $pdo->prepare(
    'UPDATE users SET password_hash = :h WHERE username = :u AND role = "teacher"'
);
$stmt->execute([':h' => $hash, ':u' => 'B_Delossantos']);
echo $stmt->rowCount() > 0
    ? "Updated B_Delossantos password to 123.\n"
    : "No row updated (username not found).\n";

$check = $pdo->query("SELECT username, password_hash FROM users WHERE username='B_Delossantos'")->fetch(PDO::FETCH_ASSOC);
if ($check && password_verify('123', $check['password_hash'])) {
    echo "Verify OK: password_verify('123') passed.\n";
} else {
    echo "Verify FAILED.\n";
}
