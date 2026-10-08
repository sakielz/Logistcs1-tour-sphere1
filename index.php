<?php
// index.php
require_once __DIR__ . '/config/database.php';

// If user is logged in, redirect to admin dashboard
if (isLoggedIn()) {
    header('Location: admin/dashboard.php');
    exit();
}

// Otherwise, redirect to login page
header('Location: login.php');
exit();