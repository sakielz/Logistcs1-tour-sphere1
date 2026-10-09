<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
echo "Active PDO driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";
$st = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'users' ORDER BY ordinal_position");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "COL: {$c['column_name']} -> {$c['data_type']}\n";
}

$stmt = $pdo->query("SELECT id, username, email, role, is_active, is_archived, two_factor_enabled, password FROM users");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
$candidates = ['admin@08', 'admin123', 'admin', 'password', '123456', 'admin1234', 'password123', 'root', 'secret', 'admin@123'];
foreach ($users as $u) {
    echo "ID: {$u['id']}, User: {$u['username']}, Email: {$u['email']}, Role: {$u['role']}, Active: {$u['is_active']}, 2FA: {$u['two_factor_enabled']}\n";
    $matched = false;
    foreach ($candidates as $cand) {
        if (password_verify($cand, $u['password'] ?? '')) {
            echo "  --> Password is: '{$cand}'\n";
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        echo "  --> Hash: " . substr($u['password'] ?? '', 0, 20) . "...\n";
    }
}

echo "\n--- SQLite Comparison ---\n";
if (file_exists(__DIR__ . '/../database/database.sqlite')) {
    $sq = new PDO('sqlite:' . __DIR__ . '/../database/database.sqlite');
    $sqUsers = $sq->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
    echo "SQLite users count: " . count($sqUsers) . "\n";
    foreach ($sqUsers as $u) {
        echo "  [SQLite] ID: {$u['id']}, User: {$u['username']}, Email: {$u['email']}, Role: {$u['role']}\n";
        foreach ($candidates as $cand) {
            if (password_verify($cand, $u['password'] ?? '')) {
                echo "    --> Password matches: '{$cand}'\n";
                break;
            }
        }
    }
    $tables = $sq->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);

    echo "\n=== TABLE COUNTS (SQLite vs Supabase) ===\n";
    foreach ($tables as $t) {
        $sqCount = $sq->query("SELECT count(*) FROM \"$t\"")->fetchColumn();
        try {
            $pgCount = $pdo->query("SELECT count(*) FROM \"$t\"")->fetchColumn();
            echo sprintf("%-25s SQLite: %-6d Supabase: %-6d\n", $t, $sqCount, $pgCount);
        } catch (Throwable $e) {
            echo sprintf("%-25s SQLite: %-6d Supabase: TABLE MISSING (%s)\n", $t, $sqCount, $e->getMessage());
        }
    }
}


