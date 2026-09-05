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

/**
 * Confirm password to reveal masked school ID / QR on the digital badge.
 * Returns JSON for the modal UI.
 */
if ($action === 'reveal_id_card') {
    header('Content-Type: application/json; charset=UTF-8');
    $password = (string)($_POST['password'] ?? '');
    if ($password === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Please enter your password.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT password_hash, school_id FROM users WHERE id = :id AND role = "student" LIMIT 1');
    $stmt->execute([':id' => $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify($password, $row['password_hash'])) {
        audit_log('ID_REVEAL_DENIED', 'student#' . $uid, 'Wrong password for ID card reveal');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Incorrect password. Secure details stay hidden.']);
        exit;
    }

    $schoolId = trim((string)($row['school_id'] ?? ''));
    if ($schoolId === '') {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'No school ID is on file. Contact the admin.']);
        exit;
    }

    require_once __DIR__ . '/../includes/qr.php';
    $_SESSION['id_card_revealed_until'] = time() + 300; // 5 minutes
    audit_log('ID_REVEAL_OK', 'student#' . $uid, 'School ID revealed on digital badge');
    echo json_encode([
        'ok' => true,
        'school_id' => $schoolId,
        'qr_url' => school_id_qr_url($schoolId, 140),
        'expires_in' => 300,
    ]);
    exit;
}

redirect('index.php');
