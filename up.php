<?php
// up.php - System & Container Health Check and Self-Healing
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');

$status = [
    'status' => 'ok',
    'app' => 'GlobalSCM Logistics 1',
    'build_version' => '2026-10-09-v2',
    'timestamp' => date('c'),
    'php_version' => PHP_VERSION,
    'database' => 'unknown',
    'driver' => null,
    'host' => null,
    'db_name' => null,
    'user_count' => 0,
    'users' => [],
];

try {
    require_once __DIR__ . '/config/database.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        $driverName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $status['database'] = 'connected (' . $driverName . ')';
        $status['driver'] = $driverName;
        $status['host'] = $host ?? 'unknown';
        $status['db_name'] = $dbName ?? 'unknown';

        $st = $pdo->query("SELECT id, username, email, role, is_active FROM users ORDER BY id");
        $users = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        if (empty($users)) {
            // Self-heal: seed admin account immediately
            $adminHash = '$2y$10$WcY/9xLuH2YbFNcIa0vY4uSVJNclz85LYJwPuAfiU/0pRAzS1x3LS'; // admin@08
            try {
                $ins = $pdo->prepare("INSERT INTO users (username, email, password, role, full_name, is_active, is_archived, two_factor_enabled) VALUES (?, ?, ?, ?, ?, 1, 0, 0)");
                $ins->execute(['admin', 'admin@globalscm.com', $adminHash, 'admin', 'System Administrator']);
            } catch (Throwable $eIns) {}
            $st = $pdo->query("SELECT id, username, email, role, is_active FROM users ORDER BY id");
            $users = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        }
        $status['user_count'] = count($users);
        $status['users'] = array_map(function($u) {
            return $u['username'] . ' (' . $u['email'] . ')';
        }, $users);
    }
} catch (Throwable $e) {
    $status['database'] = 'error: ' . $e->getMessage();
}

echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit();
