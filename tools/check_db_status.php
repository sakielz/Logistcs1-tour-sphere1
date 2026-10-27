<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

echo "Connected Driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";

$tables = [
    'users',
    'suppliers',
    'supplier_performance',
    'supplier_privacy_acknowledgements',
    'products',
    'inventory_groups',
    'warehouses',
    'warehouse_zones',
    'warehouse_inventory',
    'purchase_orders',
    'purchase_order_items',
    'purchase_requisitions',
    'requisition_items',
    'procurement_contracts',
    'shipments',
    'documents',
    'inventory_transactions',
    'inventory_batches',
    'product_serial_numbers',
    'warehouse_bins',
    'requisition_quotes',
    'bidding_tenders',
    'bidding_bids',
    'audit_logs',
    'system_settings'
];

foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
        $count = $stmt->fetchColumn();
        echo "{$table}: {$count} rows\n";
    } catch (Throwable $e) {
        echo "{$table}: ERROR - " . $e->getMessage() . "\n";
    }
}
