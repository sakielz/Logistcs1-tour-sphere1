<?php
// index.php
require_once __DIR__ . '/config/database.php';

// If bypass is active or user is logged in, redirect to admin dashboard
if ((defined('AUTH_BYPASS') && AUTH_BYPASS) || isLoggedIn()) {
    header('Location: admin/dashboard.php');
    exit();
}

// Otherwise, redirect to login page
header('Location: login.php');
exit();