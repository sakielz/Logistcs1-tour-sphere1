<?php
declare(strict_types=1);

// Set up fake session for testing pages without redirects
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['role'] = 'admin';
$_SESSION['full_name'] = 'System Administrator';
$_SESSION['email'] = 'admin@globalscm.com';
$_SESSION['last_activity'] = time();

require_once __DIR__ . '/../config/database.php';

$adminPages = [
    'dashboard.php',
    'inventory.php',
    'suppliers.php',
    'users.php',
    'settings.php',
    'reports.php',
    'bidding.php',
    'contracts.php',
    'requisitions.php',
    'purchase-orders.php',
    'shipments.php',
    'warehouse-zones.php',
    'warehouses.php',
    'stock-movements.php',
    'security.php',
    'documents.php',
    'logs.php',
    'archive.php'
];

echo "Testing SQL queries on active driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

// Test basic queries for each module
$tests = [
    'users' => "SELECT count(*) FROM users",
    'system_settings' => "SELECT count(*) FROM system_settings",
    'suppliers' => "SELECT count(*) FROM suppliers",
    'inventory_groups' => "SELECT count(*) FROM inventory_groups",
    'products' => "SELECT count(*) FROM products",
    'purchase_orders' => "SELECT count(*) FROM purchase_orders",
    'warehouses' => "SELECT count(*) FROM warehouses",
    'warehouse_inventory' => "SELECT count(*) FROM warehouse_inventory",
    'bidding_tenders' => "SELECT count(*) FROM bidding_tenders",
    'bidding_bids' => "SELECT count(*) FROM bidding_bids",
    'documents' => "SELECT count(*) FROM documents",
    'audit_logs' => "SELECT count(*) FROM audit_logs",
    'user_recovery_codes' => "SELECT count(*) FROM user_recovery_codes",
];

foreach ($tests as $name => $query) {
    try {
        $count = $pdo->query($query)->fetchColumn();
        echo sprintf("  [OK] %-25s -> %d records\n", $name, $count);
    } catch (Throwable $e) {
        echo sprintf("  [FAIL] %-23s -> ERROR: %s\n", $name, $e->getMessage());
    }
}
