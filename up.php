<?php
// up.php - System & Container Health Check for Render / Docker
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');

$status = [
    'status' => 'ok',
    'app' => 'GlobalSCM Logistics 1',
    'timestamp' => date('c'),
    'php_version' => PHP_VERSION,
    'database' => 'unknown'
];

try {
    require_once __DIR__ . '/config/database.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        $st = $pdo->query("SELECT 1");
        $status['database'] = 'connected (' . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . ')';
    }
} catch (Throwable $e) {
    $status['database'] = 'error: ' . $e->getMessage();
}

echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit();
