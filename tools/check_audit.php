<?php
require_once __DIR__ . '/../config/database.php';
global $pdo;

$logs = $pdo->query('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
echo "=== LATEST 20 AUDIT LOGS IN ACTIVE DATABASE ===\n";
foreach ($logs as $l) {
    echo sprintf("[%s] ID: %d, UserID: %s, Action: %s, Details: %s, IP: %s\n",
        $l['created_at'],
        $l['id'],
        $l['user_id'] ?? 'null',
        $l['action'],
        $l['details'],
        $l['ip_address']
    );
}

$users = $pdo->query('SELECT id, username, email, role, is_active, is_archived, password, two_factor_enabled FROM users ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== ALL USERS IN ACTIVE DATABASE ===\n";
foreach ($users as $u) {
    echo sprintf("ID: %-3d User: %-12s Email: %-25s Active: %-5s Archived: %-5s 2FA: %s PassHashLen: %d HashPrefix: %s\n",
        $u['id'],
        $u['username'],
        $u['email'],
        $u['is_active'] ? 'true' : 'false',
        $u['is_archived'] ? 'true' : 'false',
        $u['two_factor_enabled'],
        strlen($u['password']),
        substr($u['password'], 0, 7)
    );
}
