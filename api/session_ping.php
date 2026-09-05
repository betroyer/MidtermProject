<?php
/**
 * api/session_ping.php — keep session alive while the user is actively using the UI.
 * Called by js/idle-logout.js on mouse/keyboard/form activity.
 */
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'reason' => 'logged_out']);
    exit;
}

if (enforce_session_idle()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'reason' => 'idle']);
    exit;
}

echo json_encode([
    'ok' => true,
    'idle_seconds' => SESSION_IDLE_SECONDS,
    'server_time' => time(),
]);
