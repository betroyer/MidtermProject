<?php
/**
 * scripts/ensure_teacher_multi_blocks.php
 * Ensures every teacher handles at least 2 blocks (1 teacher : many blocks).
 * Creates a companion block "{Primary}B" when a teacher only has one.
 *
 * Run: php scripts/ensure_teacher_multi_blocks.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();
ensure_academics_schema($pdo);
ensure_blocks_academics_schema($pdo);

$teachers = $pdo->query(
    'SELECT id, username, first_name, last_name FROM users WHERE role = "teacher" AND COALESCE(is_active, 1) = 1 ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC);

if (!$teachers) {
    fwrite(STDERR, "No active teachers found.\n");
    exit(1);
}

$findBlocks = $pdo->prepare(
    'SELECT id, name, department_id, course_id FROM blocks WHERE teacher_id = :t ORDER BY id'
);
$findByName = $pdo->prepare('SELECT id FROM blocks WHERE name = :n LIMIT 1');
$insert = $pdo->prepare(
    'INSERT INTO blocks (name, teacher_id, department_id, course_id) VALUES (:n, :t, :d, :c)'
);
$updateTeacherHome = $pdo->prepare(
    'UPDATE users SET block_id = :b WHERE id = :id AND role = "teacher" AND (block_id IS NULL OR block_id = 0)'
);

$created = 0;
foreach ($teachers as $t) {
    $tid = (int)$t['id'];
    $findBlocks->execute([':t' => $tid]);
    $blocks = $findBlocks->fetchAll(PDO::FETCH_ASSOC);
    $count = count($blocks);

    if ($count >= 2) {
        echo "OK  {$t['username']}: already handles {$count} blocks\n";
        $updateTeacherHome->execute([':b' => (int)$blocks[0]['id'], ':id' => $tid]);
        continue;
    }

    if ($count === 0) {
        $baseName = 'Block-' . preg_replace('/[^A-Za-z0-9]/', '', $t['username']);
        $nameA = $baseName . '-A';
        $nameB = $baseName . '-B';
        foreach ([$nameA, $nameB] as $i => $name) {
            $findByName->execute([':n' => $name]);
            if ($findByName->fetchColumn()) {
                $pdo->prepare('UPDATE blocks SET teacher_id = :t WHERE name = :n')->execute([':t' => $tid, ':n' => $name]);
            } else {
                $insert->execute([':n' => $name, ':t' => $tid, ':d' => null, ':c' => null]);
                $created++;
            }
        }
        $findBlocks->execute([':t' => $tid]);
        $blocks = $findBlocks->fetchAll(PDO::FETCH_ASSOC);
        echo "NEW {$t['username']}: created/assigned " . count($blocks) . " blocks\n";
        if ($blocks) {
            $updateTeacherHome->execute([':b' => (int)$blocks[0]['id'], ':id' => $tid]);
        }
        continue;
    }

    // Exactly one block — add companion "{name}B"
    $primary = $blocks[0];
    $companion = $primary['name'] . 'B';
    $findByName->execute([':n' => $companion]);
    $existingId = $findByName->fetchColumn();
    if ($existingId) {
        $pdo->prepare('UPDATE blocks SET teacher_id = :t WHERE id = :id')->execute([
            ':t' => $tid,
            ':id' => (int)$existingId,
        ]);
        echo "LINK {$t['username']}: linked existing {$companion}\n";
    } else {
        $insert->execute([
            ':n' => $companion,
            ':t' => $tid,
            ':d' => $primary['department_id'] ?: null,
            ':c' => $primary['course_id'] ?: null,
        ]);
        $created++;
        echo "ADD {$t['username']}: + {$companion} (now 2 blocks)\n";
    }
    $updateTeacherHome->execute([':b' => (int)$primary['id'], ':id' => $tid]);
}

echo "Done. Companion blocks created this run: {$created}\n";

$summary = $pdo->query(
    'SELECT u.username, COUNT(b.id) AS block_count,
            GROUP_CONCAT(b.name ORDER BY b.name SEPARATOR ", ") AS blocks
     FROM users u
     LEFT JOIN blocks b ON b.teacher_id = u.id
     WHERE u.role = "teacher" AND COALESCE(u.is_active, 1) = 1
     GROUP BY u.id
     ORDER BY u.username'
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($summary as $row) {
    echo "  {$row['username']}: {$row['block_count']} — {$row['blocks']}\n";
}
