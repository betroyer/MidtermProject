<?php
require_once __DIR__ . '/includes/auth.php';

$reason = $_GET['reason'] ?? '';
$wasUser = !empty($_SESSION['user']);

if ($wasUser && $reason === 'idle' && function_exists('audit_log')) {
    try {
        audit_log('SESSION_IDLE_LOGOUT', 'session', 'Client idle timeout (5 minutes)');
    } catch (Throwable $e) {
        // ignore
    }
}

logout();

if ($reason === 'idle') {
    redirect('index.php?e=idle');
}
redirect('index.php');
