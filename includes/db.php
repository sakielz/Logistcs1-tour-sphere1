<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$rootDir = dirname(__DIR__);

// Use the central database configuration (Supabase PostgreSQL / Cloud DB)
require_once $rootDir . '/config/database.php';

$GLOBALS['pdo'] = $pdo;

require_once __DIR__ . '/function.php';
