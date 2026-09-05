<?php
/** student/actions.php — student self-service actions (profile picture). */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/avatar.php';

$user = require_access('student', 'profile');
verify_csrf();
ensure_user_avatar_column();

$action = $_POST['action'] ?? '';
$pdo = db();
$uid = (int)$user['id'];

if ($action === 'upload_avatar') {
    $stmt = $pdo->prepare('SELECT avatar FROM users WHERE id = :id');
    $stmt->execute([':id' => $uid]);
    $old = $stmt->fetchColumn() ?: null;

    [$filename, $error] = store_avatar_upload($_FILES['avatar'] ?? [], $uid, $old);
    if ($error) {
        set_flash('error', $error);
        redirect('index.php');
    }

    $upd = $pdo->prepare('UPDATE users SET avatar = :a WHERE id = :id');
    $upd->execute([':a' => $filename, ':id' => $uid]);
    $_SESSION['user']['avatar'] = $filename;
    audit_log('AVATAR_UPDATED', 'student#' . $uid, 'Profile picture uploaded');
    set_flash('ok', 'Profile picture updated.');
    redirect('index.php');
}

if ($action === 'remove_avatar') {
    $stmt = $pdo->prepare('SELECT avatar FROM users WHERE id = :id');
    $stmt->execute([':id' => $uid]);
    $old = $stmt->fetchColumn() ?: null;
    delete_avatar_file($old);
    $pdo->prepare('UPDATE users SET avatar = NULL WHERE id = :id')->execute([':id' => $uid]);
    $_SESSION['user']['avatar'] = null;
    audit_log('AVATAR_REMOVED', 'student#' . $uid, 'Profile picture removed');
    set_flash('ok', 'Profile picture removed.');
    redirect('index.php');
}

redirect('index.php');
