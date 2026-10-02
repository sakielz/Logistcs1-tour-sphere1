<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

try {
    $stats = [];
    $statQueries = [
        'users' => 'SELECT COUNT(*) FROM users WHERE is_archived = false',
        'active_users' => 'SELECT COUNT(*) FROM users WHERE is_active = true AND is_archived = false',
        'products' => 'SELECT COUNT(*) FROM products WHERE is_archived = false',
        'pending_approvals' => "SELECT COUNT(*) FROM purchase_orders WHERE approval_status = 'pending_review' AND is_archived = false",
        'suppliers' => 'SELECT COUNT(*) FROM suppliers WHERE is_archived = false',
        'ongoing_shipments' => "SELECT COUNT(*) FROM shipments WHERE status IN ('pending', 'in_transit') AND is_archived = false",
        'pending_requisitions' => "SELECT COUNT(*) FROM purchase_requisitions WHERE status = 'pending_review' AND is_archived = false",
        'rejected_requisitions' => "SELECT COUNT(*) FROM purchase_requisitions WHERE status = 'rejected' AND is_archived = false"
    ];

    foreach ($statQueries as $key => $sql) {
        $stats[$key] = (int)$pdo->query($sql)->fetchColumn();
    }

    $stmt = $pdo->query('SELECT status, COUNT(*) AS total FROM suppliers WHERE is_archived = false GROUP BY status');
    $supplierStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query('SELECT status, COUNT(*) AS total FROM purchase_requisitions WHERE is_archived = false GROUP BY status');
    $requisitionStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $moduleQueries = [
        'Users' => 'SELECT COUNT(*) FROM users WHERE is_archived = false',
        'Suppliers' => 'SELECT COUNT(*) FROM suppliers WHERE is_archived = false',
        'Products' => 'SELECT COUNT(*) FROM products WHERE is_archived = false',
        'Purchase Orders' => 'SELECT COUNT(*) FROM purchase_orders WHERE is_archived = false',
        'Requisitions' => 'SELECT COUNT(*) FROM purchase_requisitions WHERE is_archived = false',
        'Shipments' => 'SELECT COUNT(*) FROM shipments WHERE is_archived = false',
        'Warehouses' => 'SELECT COUNT(*) FROM warehouses WHERE is_archived = false',
        'Warehouse Zones' => 'SELECT COUNT(*) FROM warehouse_zones',
        'Stock Movements' => 'SELECT COUNT(*) FROM inventory_transactions',
        'Contracts' => 'SELECT COUNT(*) FROM procurement_contracts WHERE is_archived = false',
        'Documents' => 'SELECT COUNT(*) FROM documents WHERE is_archived = false',
        'Activity Logs' => 'SELECT COUNT(*) FROM audit_logs',
        'Report Runs' => "SELECT COUNT(*) FROM audit_logs WHERE module = 'reporting'",
        'Settings' => 'SELECT COUNT(*) FROM system_settings',
        'Archived Records' => 'SELECT (SELECT COUNT(*) FROM users WHERE is_archived = true) + (SELECT COUNT(*) FROM suppliers WHERE is_archived = true) + (SELECT COUNT(*) FROM products WHERE is_archived = true) + (SELECT COUNT(*) FROM purchase_orders WHERE is_archived = true) + (SELECT COUNT(*) FROM purchase_requisitions WHERE is_archived = true) + (SELECT COUNT(*) FROM shipments WHERE is_archived = true) + (SELECT COUNT(*) FROM warehouses WHERE is_archived = true) + (SELECT COUNT(*) FROM procurement_contracts WHERE is_archived = true) + (SELECT COUNT(*) FROM documents WHERE is_archived = true)'
    ];
    $moduleCounts = [];
    foreach ($moduleQueries as $label => $sql) {
        $moduleCounts[] = ['label' => $label, 'total' => (int)$pdo->query($sql)->fetchColumn()];
    }

    $stmt = $pdo->query("SELECT COALESCE(NULLIF(TRIM(category), ''), 'Uncategorized') AS category, SUM(unit_price * current_stock) AS total_value FROM products WHERE is_archived = false GROUP BY COALESCE(NULLIF(TRIM(category), ''), 'Uncategorized') ORDER BY total_value DESC");
    $inventoryCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($inventoryCategories as &$category) {
        $category['total_value'] = (float)($category['total_value'] ?? 0);
    }
    unset($category);

    $startDate = date('Y-m-d', strtotime('-6 days'));
    $movementByDate = [];
    for ($offset = 0; $offset < 7; $offset++) {
        $date = date('Y-m-d', strtotime($startDate . ' +' . $offset . ' days'));
        $movementByDate[$date] = ['date' => $date, 'received' => 0, 'issued' => 0];
    }

    $stmt = $pdo->prepare("SELECT DATE(created_at) AS movement_date, SUM(CASE WHEN transaction_type = 'receiving' THEN quantity ELSE 0 END) AS received, SUM(CASE WHEN transaction_type = 'issuance' THEN quantity ELSE 0 END) AS issued FROM inventory_transactions WHERE DATE(created_at) >= ? GROUP BY DATE(created_at) ORDER BY movement_date ASC");
    $stmt->execute([$startDate]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $movement) {
        if (isset($movementByDate[$movement['movement_date']])) {
            $movementByDate[$movement['movement_date']]['received'] = (int)$movement['received'];
            $movementByDate[$movement['movement_date']]['issued'] = (int)$movement['issued'];
        }
    }

    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'supplier_status' => $supplierStatus,
        'requisition_status' => $requisitionStatus,
        'module_counts' => $moduleCounts,
        'inventory_categories' => $inventoryCategories,
        'stock_movements' => array_values($movementByDate),
        'updated_at' => date(DATE_ATOM)
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load dashboard data']);
}