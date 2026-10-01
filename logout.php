<?php
// logout.php
require_once __DIR__ . '/config/database.php';

// Log the logout action
if (isLoggedIn()) {
    logAudit($_SESSION['user_id'], 'logout', 'auth', 'User logged out');
}

// Destroy session
session_destroy();

// Clear session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Redirect to login (preserve timeout flag if present)
$suffix = (isset($_GET['timeout']) && $_GET['timeout'] === '1') ? '?timeout=1' : '';
header('Location: login.php' . $suffix);
exit();