<?php
/**
 * api/session-ping.php
 * Called by the client-side session-timeout JS every 30 s to keep the session
 * alive while the user is active.  Returns JSON.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['alive' => false, 'redirect' => '../login.php?timeout=1']);
    exit();
}

// Refresh last_activity
$_SESSION['last_activity'] = time();
echo json_encode(['alive' => true, 'remaining' => SESSION_TIMEOUT]);
