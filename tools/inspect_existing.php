<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

echo "=== USERS ===\n";
foreach ($pdo->query("SELECT id, username, role, full_name FROM users ORDER BY id LIMIT 10") as $row) {
    echo "ID: {$row['id']} | User: {$row['username']} | Role: {$row['role']} | Name: {$row['full_name']}\n";
}

echo "\n=== SUPPLIERS ===\n";
foreach ($pdo->query("SELECT id, supplier_code, company_name, supplier_type, status FROM suppliers ORDER BY id") as $row) {
    echo "ID: {$row['id']} | Code: {$row['supplier_code']} | Name: {$row['company_name']} | Type: {$row['supplier_type']}\n";
}

echo "\n=== WAREHOUSES ===\n";
foreach ($pdo->query("SELECT id, warehouse_code, name, location FROM warehouses ORDER BY id") as $row) {
    echo "ID: {$row['id']} | Code: {$row['warehouse_code']} | Name: {$row['name']} | Location: {$row['location']}\n";
}

echo "\n=== PRODUCTS ===\n";
foreach ($pdo->query("SELECT id, sku, product_name, category, current_stock, unit_price FROM products ORDER BY id") as $row) {
    echo "ID: {$row['id']} | SKU: {$row['sku']} | Name: {$row['product_name']} | Cat: {$row['category']} | Stock: {$row['current_stock']}\n";
}

echo "\n=== PURCHASE ORDERS ===\n";
foreach ($pdo->query("SELECT id, po_number, supplier_id, warehouse_id, status, total_amount FROM purchase_orders ORDER BY id") as $row) {
    echo "ID: {$row['id']} | PO: {$row['po_number']} | SupID: {$row['supplier_id']} | WhID: {$row['warehouse_id']} | Status: {$row['status']}\n";
}

echo "\n=== CONTRACTS ===\n";
foreach ($pdo->query("SELECT id, contract_number, supplier_id, title, status FROM procurement_contracts ORDER BY id") as $row) {
    echo "ID: {$row['id']} | No: {$row['contract_number']} | Title: {$row['title']} | Status: {$row['status']}\n";
}

echo "\n=== BIDDING TENDERS ===\n";
foreach ($pdo->query("SELECT id, tender_code, title, status FROM bidding_tenders ORDER BY id") as $row) {
    echo "ID: {$row['id']} | Code: {$row['tender_code']} | Title: {$row['title']} | Status: {$row['status']}\n";
}
