<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$pdo = db();
ensure_academics_schema($pdo);
ensure_user_profile_schema($pdo);
$sql = "UPDATE users SET
  address = IF(address IS NULL OR address = '', 'Sample student address, City', address),
  emergency_name = IF(emergency_name IS NULL OR emergency_name = '', CONCAT('Parent ', last_name), emergency_name),
  emergency_relation = IF(emergency_relation IS NULL OR emergency_relation = '', 'parent', emergency_relation),
  emergency_address = IF(emergency_address IS NULL OR emergency_address = '', 'Sample emergency address, City', emergency_address),
  emergency_phone = IF(emergency_phone IS NULL OR emergency_phone = '', '09171234567', emergency_phone)
WHERE role = 'student'";
$n = $pdo->exec($sql);
echo "Backfilled {$n} student profile row(s).\n";
