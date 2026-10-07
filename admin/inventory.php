<?php
// admin/inventory.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/barcode.php';
require_once __DIR__ . '/../includes/function.php';

requireAuth(['admin','warehouse_manager','inventory_clerk']);

// Define color constants if not already defined
if (!defined('COLOR_PRIMARY')) define('COLOR_PRIMARY', '#2F80ED');
if (!defined('COLOR_SECONDARY')) define('COLOR_SECONDARY', '#56CCF2');
if (!defined('COLOR_ACCENT')) define('COLOR_ACCENT', '#27AE60');
if (!defined('COLOR_BG')) define('COLOR_BG', '#F8FAFC');
if (!defined('COLOR_CARD')) define('COLOR_CARD', '#FFFFFF');
if (!defined('COLOR_TEXT')) define('COLOR_TEXT', '#1F2937');
if (!defined('COLOR_SECONDARY_TEXT')) define('COLOR_SECONDARY_TEXT', '#6B7280');
if (!defined('COLOR_BORDER')) define('COLOR_BORDER', '#EEF2F7');

// Get theme setting
$theme = getTheme();

// admin/inventory.php
// Add this at the top after require_once

// Define color constants if not already defined
if (!defined('COLOR_PRIMARY')) define('COLOR_PRIMARY', '#2F80ED');
if (!defined('COLOR_SECONDARY')) define('COLOR_SECONDARY', '#56CCF2');
if (!defined('COLOR_ACCENT')) define('COLOR_ACCENT', '#27AE60');
if (!defined('COLOR_BG')) define('COLOR_BG', '#F8FAFC');
if (!defined('COLOR_CARD')) define('COLOR_CARD', '#FFFFFF');
if (!defined('COLOR_TEXT')) define('COLOR_TEXT', '#1F2937');
if (!defined('COLOR_SECONDARY_TEXT')) define('COLOR_SECONDARY_TEXT', '#6B7280');
if (!defined('COLOR_BORDER')) define('COLOR_BORDER', '#EEF2F7');

// Get theme setting at the top
$theme = 'light';
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'theme'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $theme = $result['setting_value'];
    }
} catch (Exception $e) {
    $theme = 'light';
}
// Define color constants if not already defined
if (!defined('COLOR_PRIMARY')) define('COLOR_PRIMARY', '#2F80ED');
if (!defined('COLOR_SECONDARY')) define('COLOR_SECONDARY', '#56CCF2');
if (!defined('COLOR_ACCENT')) define('COLOR_ACCENT', '#27AE60');
if (!defined('COLOR_BG')) define('COLOR_BG', '#F8FAFC');
if (!defined('COLOR_CARD')) define('COLOR_CARD', '#FFFFFF');
if (!defined('COLOR_TEXT')) define('COLOR_TEXT', '#1F2937');
if (!defined('COLOR_SECONDARY_TEXT')) define('COLOR_SECONDARY_TEXT', '#6B7280');
if (!defined('COLOR_BORDER')) define('COLOR_BORDER', '#EEF2F7');

// ============================================
// generateBarcode() and generateSerialNumber() are defined in includes/barcode.php
// validateAndConsumeInventoryBatches() is defined in includes/function.php
// ============================================

/**
 * Generate a unique SKU code like SKU-00042
 */
if (!function_exists('generateUniqueSKU')) {
    function generateUniqueSKU(PDO $pdo): string {
        $prefix = 'SKU';
        try {
            $stmt = $pdo->query(
                "SELECT sku FROM products WHERE sku LIKE 'SKU-%' ORDER BY id DESC LIMIT 1"
            );
            $last = $stmt->fetchColumn();
            if ($last && preg_match('/SKU-(\d+)$/', (string)$last, $m)) {
                $next = (int)$m[1] + 1;
            } else {
                $countStmt = $pdo->query("SELECT COUNT(*) FROM products");
                $next = (int)$countStmt->fetchColumn() + 1;
            }
        } catch (Exception $e) {
            $next = mt_rand(1, 9999);
        }
        return $prefix . '-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }
}

$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// ── AJAX: Generate unique SKU ─────────────────────────────────────────────
if ($action === 'generate_sku' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    echo json_encode(['sku' => generateUniqueSKU($pdo)]);
    exit();
}

try {
    $stmt = $pdo->query("SELECT w.id, w.name, w.warehouse_code, w.group_id, g.group_name FROM warehouses w LEFT JOIN inventory_groups g ON g.id = w.group_id WHERE w.is_archived = 0 AND w.status = 'active' ORDER BY g.group_name, w.name");
    $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $warehouses = [];
}
$selectedWarehouse = null;
foreach ($warehouses as $warehouse) {
    if ((int)$warehouse['id'] === (int)($_GET['warehouse_id'] ?? 0)) {
        $selectedWarehouse = $warehouse;
        break;
    }
}

// Create Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $sku = isset($_POST['sku']) ? trim($_POST['sku']) : '';
    $product_name = isset($_POST['product_name']) ? trim($_POST['product_name']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $brand = isset($_POST['brand']) ? trim($_POST['brand']) : '';
    // Support custom category typed in the text fallback field
    $category = isset($_POST['category']) ? trim($_POST['category']) : '';
    if ($category === '' && !empty($_POST['category_custom'])) {
        $category = trim($_POST['category_custom']);
    }
    // Support custom item_type typed in the text fallback field
    $item_type = isset($_POST['item_type']) ? trim($_POST['item_type']) : 'general';
    if (($item_type === '' || $item_type === '__custom__') && !empty($_POST['item_type_custom'])) {
        $item_type = strtolower(trim(str_replace(' ', '_', $_POST['item_type_custom'])));
    }
    if ($item_type === '' || $item_type === '__custom__') $item_type = 'general';
    // Auto-generate SKU if left empty
    if ($sku === '') {
        $sku = generateUniqueSKU($pdo);
    }
    $warehouse_id = isset($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : 0;
    $unit_measure = isset($_POST['unit_measure']) ? trim($_POST['unit_measure']) : '';
    $unit_price = isset($_POST['unit_price']) ? (float)$_POST['unit_price'] : 0;
    $reorder_point = isset($_POST['reorder_point']) ? (int)$_POST['reorder_point'] : 0;
    $reorder_quantity = isset($_POST['reorder_quantity']) ? (int)$_POST['reorder_quantity'] : 0;
    $current_stock = isset($_POST['current_stock']) ? (int)$_POST['current_stock'] : 0;
    $min_stock = isset($_POST['min_stock']) ? (int)$_POST['min_stock'] : 0;
    $max_stock = isset($_POST['max_stock']) ? (int)$_POST['max_stock'] : 0;
    $serial_number_prefix = isset($_POST['serial_number_prefix']) ? trim($_POST['serial_number_prefix']) : 'SN';
    
    // Generate barcode - USING THE FUNCTION FROM DATABASE.PHP
    $barcode = generateBarcode($sku);
    
    try {
        $warehouseCheck = $pdo->prepare("SELECT id FROM warehouses WHERE id = ? AND is_archived = 0 AND status = 'active'");
        $warehouseCheck->execute([$warehouse_id]);
        if (!$warehouseCheck->fetchColumn()) {
            throw new RuntimeException('Select an active warehouse for this item.');
        }

        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("INSERT INTO products (sku, product_name, description, brand, category, item_type, unit_measure, unit_price, reorder_point, reorder_quantity, current_stock, min_stock, max_stock, barcode, serial_number_prefix, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$sku, $product_name, $description, $brand, $category, $item_type, $unit_measure, $unit_price, $reorder_point, $reorder_quantity, $current_stock, $min_stock, $max_stock, $barcode, $serial_number_prefix, $_SESSION['user_id']]);
        
        $product_id = $pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO warehouse_inventory (product_id, warehouse_id, quantity) VALUES (?, ?, ?)");
        $stmt->execute([$product_id, $warehouse_id, $current_stock]);
        
        // Generate initial serial number - USING THE FUNCTION FROM DATABASE.PHP
        generateSerialNumber($product_id);
        
        // Log initial stock
        if ($current_stock > 0) {
            $stmt = $pdo->prepare("INSERT INTO inventory_transactions (product_id, transaction_type, quantity, previous_balance, new_balance, warehouse_id, created_by) VALUES (?, 'receiving', ?, 0, ?, ?, ?)");
            $stmt->execute([$product_id, $current_stock, $current_stock, $warehouse_id, $_SESSION['user_id']]);
        }
        
        $pdo->commit();
        
        logAudit($_SESSION['user_id'], 'create_product', 'inventory', "Created product: $product_name (SKU: $sku)");
        $_SESSION['success'] = "Product created successfully! Barcode: $barcode";
        header('Location: inventory.php');
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Error creating product: " . $e->getMessage();
    }
}

// Update Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $sku = isset($_POST['sku']) ? trim($_POST['sku']) : '';
    $product_name = isset($_POST['product_name']) ? trim($_POST['product_name']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $brand = isset($_POST['brand']) ? trim($_POST['brand']) : '';
    $category = isset($_POST['category']) ? trim($_POST['category']) : '';
    $item_type = isset($_POST['item_type']) ? trim($_POST['item_type']) : 'general';
    $unit_measure = isset($_POST['unit_measure']) ? trim($_POST['unit_measure']) : '';
    $unit_price = isset($_POST['unit_price']) ? (float)$_POST['unit_price'] : 0;
    $reorder_point = isset($_POST['reorder_point']) ? (int)$_POST['reorder_point'] : 0;
    $reorder_quantity = isset($_POST['reorder_quantity']) ? (int)$_POST['reorder_quantity'] : 0;
    $min_stock = isset($_POST['min_stock']) ? (int)$_POST['min_stock'] : 0;
    $max_stock = isset($_POST['max_stock']) ? (int)$_POST['max_stock'] : 0;
    $status = isset($_POST['status']) ? $_POST['status'] : 'active';
    
    try {
        $stmt = $pdo->prepare("UPDATE products SET sku = ?, product_name = ?, description = ?, brand = ?, category = ?, item_type = ?, unit_measure = ?, unit_price = ?, reorder_point = ?, reorder_quantity = ?, min_stock = ?, max_stock = ?, status = ? WHERE id = ?");
        $stmt->execute([$sku, $product_name, $description, $brand, $category, $item_type, $unit_measure, $unit_price, $reorder_point, $reorder_quantity, $min_stock, $max_stock, $status, $id]);
        logAudit($_SESSION['user_id'], 'update_product', 'inventory', "Updated product: $product_name");
        $_SESSION['success'] = "Product updated successfully!";
        header('Location: inventory.php');
        exit();
    } catch (PDOException $e) {
        $error = "Error updating product: " . $e->getMessage();
    }
}

// Archive Product
if ($action === 'archive' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    archiveRecord('products', $id);
    logAudit($_SESSION['user_id'], 'archive_product', 'inventory', "Archived product ID: $id");
    $_SESSION['success'] = "Product archived successfully!";
    header('Location: inventory.php');
    exit();
}

// Restore Product
if ($action === 'restore' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    restoreRecord('products', $id);
    logAudit($_SESSION['user_id'], 'restore_product', 'inventory', "Restored product ID: $id");
    $_SESSION['success'] = "Product restored successfully!";
    header('Location: inventory.php?archived=1');
    exit();
}

// Stock Adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'adjust_stock') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $adjustment_type = isset($_POST['adjustment_type']) ? $_POST['adjustment_type'] : '';
    $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 0;
    $warehouse_id = isset($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : 0;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    
    try {
        if ($id < 1 || $quantity < 0 || !in_array($adjustment_type, ['add', 'remove', 'set'], true)) {
            throw new RuntimeException('Provide a valid product, adjustment type, and non-negative quantity.');
        }

        $stmt = $pdo->prepare("SELECT id FROM warehouses WHERE id = ? AND is_archived = 0 AND status = 'active'");
        $stmt->execute([$warehouse_id]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Select an active warehouse for this adjustment.');
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT p.product_name, COALESCE(wi.quantity, 0) AS current_stock FROM products p LEFT JOIN warehouse_inventory wi ON wi.product_id = p.id AND wi.warehouse_id = ? WHERE p.id = ?");
        $stmt->execute([$warehouse_id, $id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) throw new RuntimeException('Product not found.');
        $current_stock = (int)$product['current_stock'];
        
        // Calculate new balance
        if ($adjustment_type === 'add') {
            $new_stock = $current_stock + $quantity;
        } else if ($adjustment_type === 'remove') {
            $new_stock = max(0, $current_stock - $quantity);
        } else {
            $new_stock = $quantity; // Set exact
        }
        if ($new_stock < $current_stock) {
            validateAndConsumeInventoryBatches($pdo, $id, $warehouse_id, $current_stock - $new_stock);
        }
        
        $stmt = $pdo->prepare("SELECT id FROM warehouse_inventory WHERE product_id = ? AND warehouse_id = ?");
        $stmt->execute([$id, $warehouse_id]);
        $inventoryRowId = $stmt->fetchColumn();
        if ($inventoryRowId) {
            $stmt = $pdo->prepare("UPDATE warehouse_inventory SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$new_stock, $inventoryRowId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO warehouse_inventory (product_id, warehouse_id, quantity) VALUES (?, ?, ?)");
            $stmt->execute([$id, $warehouse_id, $new_stock]);
        }

        $stmt = $pdo->prepare("UPDATE products SET current_stock = (SELECT COALESCE(SUM(quantity), 0) FROM warehouse_inventory WHERE product_id = ?) WHERE id = ?");
        $stmt->execute([$id, $id]);
        
        // Log transaction
        $stmt = $pdo->prepare("INSERT INTO inventory_transactions (product_id, transaction_type, quantity, previous_balance, new_balance, warehouse_id, notes, created_by) VALUES (?, 'adjustment', ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id, abs($new_stock - $current_stock), $current_stock, $new_stock, $warehouse_id, $notes, $_SESSION['user_id']]);
        
        logAudit($_SESSION['user_id'], 'adjust_stock', 'inventory', "Adjusted stock for product ID: $id");
        $pdo->commit();
        $_SESSION['success'] = "Stock adjusted successfully!";
        header('Location: inventory.php');
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Error adjusting stock: " . $e->getMessage();
    }
}

// Get products
$showArchived = isset($_GET['archived']) ? 1 : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$itemType = isset($_GET['item_type']) ? trim($_GET['item_type']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$minPrice = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? max(0, (float)$_GET['min_price']) : null;
$maxPrice = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? max(0, (float)$_GET['max_price']) : null;
$warehouseFilter = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
$groupFilter = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
if ($groupFilter > 0) {
    foreach ($warehouses as $warehouse) {
        if ((int)$warehouse['group_id'] === $groupFilter) {
            $warehouseFilter = (int)$warehouse['id'];
            $selectedWarehouse = $warehouse;
            break;
        }
    }
}
$lowStockOnly = !$showArchived && ($_GET['filter'] ?? '') === 'low_stock';
$obsoleteOnly = !$showArchived && ($_GET['filter'] ?? '') === 'obsolete';
$expiringOnly = !$showArchived && ($_GET['filter'] ?? '') === 'expiring';
$staleDays = 180;
$staleCutoff = date('Y-m-d', strtotime('-' . $staleDays . ' days'));
$expiryCutoff = date('Y-m-d', strtotime('+30 days'));

try {
    $stmt = $pdo->query("SELECT id, group_name FROM inventory_groups ORDER BY group_name");
    $inventoryGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $inventoryGroups = [];
}

$expiryWarehouseCondition = $warehouseFilter > 0 ? ' AND ib.warehouse_id = ' . (int)$warehouseFilter : '';
$query = "SELECT p.*, u.full_name as created_by_name, COALESCE(wi.warehouse_stock, p.current_stock, 0) AS current_stock,
          (SELECT MIN(ib.expiry_date) FROM inventory_batches ib WHERE ib.product_id = p.id AND ib.quality_status = 'accepted' AND ib.available_quantity > 0 AND ib.expiry_date IS NOT NULL" . $expiryWarehouseCondition . ") AS next_expiry
          FROM products p 
          LEFT JOIN users u ON p.created_by = u.id 
          LEFT JOIN " . ($warehouseFilter > 0
              ? "(SELECT product_id, quantity AS warehouse_stock FROM warehouse_inventory WHERE warehouse_id = ?) wi ON wi.product_id = p.id"
              : "(SELECT product_id, SUM(quantity) AS warehouse_stock FROM warehouse_inventory GROUP BY product_id) wi ON wi.product_id = p.id") . "
          WHERE p.is_archived = ?";
$params = $warehouseFilter > 0 ? [$warehouseFilter, $showArchived] : [$showArchived];

if ($warehouseFilter > 0) {
    $query .= " AND wi.product_id IS NOT NULL";
}

if (!empty($search)) {
    $query .= " AND (p.sku LIKE ? OR p.product_name LIKE ? OR p.barcode LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if (!empty($category)) {
    $query .= " AND p.category = ?";
    $params[] = $category;
}

if ($itemType !== '') {
    $query .= " AND p.item_type = ?";
    $params[] = $itemType;
}

if ($statusFilter !== '') {
    $query .= " AND p.status = ?";
    $params[] = $statusFilter;
}

if ($minPrice !== null) {
    $query .= " AND p.unit_price >= ?";
    $params[] = $minPrice;
}

if ($maxPrice !== null) {
    $query .= " AND p.unit_price <= ?";
    $params[] = $maxPrice;
}

if ($expiringOnly) {
    $query .= " AND EXISTS (SELECT 1 FROM inventory_batches ib WHERE ib.product_id = p.id AND ib.quality_status = 'accepted' AND ib.available_quantity > 0 AND ib.expiry_date <= ?" . $expiryWarehouseCondition . ")";
    $params[] = $expiryCutoff;
}

if ($lowStockOnly) {
    $query .= " AND COALESCE(wi.warehouse_stock, p.current_stock, 0) <= p.reorder_point";
}

if ($obsoleteOnly) {
    $query .= " AND p.status = 'active' AND COALESCE(wi.warehouse_stock, p.current_stock, 0) > 0
                AND NOT EXISTS (SELECT 1 FROM inventory_transactions it WHERE it.product_id = p.id AND DATE(it.created_at) >= ?";
    $params[] = $staleCutoff;
    if ($warehouseFilter > 0) {
        $query .= " AND it.warehouse_id = ?";
        $params[] = $warehouseFilter;
    }
    $query .= ")";
}

$query .= " ORDER BY p.category ASC, p.created_at DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($products) {
        $productIds = array_column($products, 'id');
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stockStmt = $pdo->prepare("SELECT product_id, warehouse_id, quantity FROM warehouse_inventory WHERE product_id IN ($placeholders)");
        $stockStmt->execute($productIds);
        $warehouseStocks = [];
        foreach ($stockStmt->fetchAll(PDO::FETCH_ASSOC) as $stockRow) {
            $warehouseStocks[$stockRow['product_id']][(string)$stockRow['warehouse_id']] = (int)$stockRow['quantity'];
        }
        foreach ($products as &$product) {
            $product['warehouse_stocks'] = $warehouseStocks[$product['id']] ?? [];
        }
        unset($product);
    }
} catch (PDOException $e) {
    $products = [];
    $error = "Error fetching products: " . $e->getMessage();
}

if ($action === 'export') {
    $exportData = array_map(static function ($product) {
        return [
            'sku' => $product['sku'],
            'product_name' => $product['product_name'],
            'brand' => $product['brand'] ?? '',
            'category' => $product['category'] ?? '',
            'item_type' => $product['item_type'] ?? '',
            'current_stock' => $product['current_stock'],
            'unit_price' => $product['unit_price'],
            'status' => $product['status'],
            'next_expiry' => $product['next_expiry'] ?? '',
        ];
    }, $products);
    require_once __DIR__ . '/../includes/report_export.php';
    exportTrackedReport($pdo, (int)$_SESSION['user_id'], 'inventory', 'Inventory Register', strtolower(trim((string)($_GET['format'] ?? ''))), $exportData);
}

$productsByCategory = [];
foreach ($products as $product) {
    $categoryLabel = trim((string)($product['category'] ?? ''));
    if ($categoryLabel === '') {
        $categoryLabel = 'Uncategorized';
    }
    $categoryKey = strtolower($categoryLabel);
    if (!isset($productsByCategory[$categoryKey])) {
        $productsByCategory[$categoryKey] = ['label' => $categoryLabel, 'products' => []];
    }
    $productsByCategory[$categoryKey]['products'][] = $product;
}

// Get categories for filter
try {
    $stmt = $pdo->query("SELECT DISTINCT category FROM products WHERE is_archived = 0 AND category IS NOT NULL ORDER BY category");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
}

try {
    $stmt = $pdo->query("SELECT DISTINCT item_type FROM products WHERE is_archived = 0 AND item_type IS NOT NULL ORDER BY item_type");
    $itemTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $itemTypes = [];
}

// Get product for edit
$editProduct = null;
if ($action === 'edit' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $editProduct = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Error fetching product: " . $e->getMessage();
    }
}

// Get stock stats
try {
    if ($warehouseFilter > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total, COALESCE(SUM(wi.quantity), 0) as total_stock, COALESCE(SUM(p.unit_price * wi.quantity), 0) as total_value FROM warehouse_inventory wi JOIN products p ON p.id = wi.product_id WHERE p.is_archived = 0 AND wi.warehouse_id = ?");
        $stmt->execute([$warehouseFilter]);
        $stockStats = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT COUNT(*) as low_stock, SUM(CASE WHEN wi.quantity <= 0 THEN 1 ELSE 0 END) as critical_stock FROM warehouse_inventory wi JOIN products p ON p.id = wi.product_id WHERE wi.quantity <= p.reorder_point AND p.is_archived = 0 AND wi.warehouse_id = ?");
        $stmt->execute([$warehouseFilter]);
        $stockAlertStats = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT p.id, p.sku, p.product_name, wi.quantity as current_stock, p.reorder_point, p.reorder_quantity, p.max_stock, p.unit_price FROM warehouse_inventory wi JOIN products p ON p.id = wi.product_id WHERE wi.quantity <= p.reorder_point AND p.is_archived = 0 AND wi.warehouse_id = ? ORDER BY wi.quantity ASC, p.product_name ASC LIMIT 8");
        $stmt->execute([$warehouseFilter]);
    } else {
        $stmt = $pdo->query("SELECT COUNT(*) as total, SUM(current_stock) as total_stock, SUM(unit_price * current_stock) as total_value FROM products WHERE is_archived = 0");
        $stockStats = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->query("SELECT COUNT(*) as low_stock, SUM(CASE WHEN current_stock <= 0 THEN 1 ELSE 0 END) as critical_stock FROM products WHERE current_stock <= reorder_point AND is_archived = 0");
        $stockAlertStats = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->query("SELECT id, sku, product_name, current_stock, reorder_point, reorder_quantity, max_stock, unit_price FROM products WHERE current_stock <= reorder_point AND is_archived = 0 ORDER BY current_stock ASC, product_name ASC LIMIT 8");
    }
    $lowStock = (int)($stockAlertStats['low_stock'] ?? 0);
    $criticalStock = (int)($stockAlertStats['critical_stock'] ?? 0);
    $lowStockProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($warehouseFilter > 0) {
        $obsoleteSql = "SELECT COUNT(*) FROM warehouse_inventory wi JOIN products p ON p.id = wi.product_id WHERE p.is_archived = 0 AND p.status = 'active' AND wi.quantity > 0 AND wi.warehouse_id = ? AND NOT EXISTS (SELECT 1 FROM inventory_transactions it WHERE it.product_id = p.id AND it.warehouse_id = wi.warehouse_id AND DATE(it.created_at) >= ?)";
        $obsoleteStmt = $pdo->prepare($obsoleteSql);
        $obsoleteStmt->execute([$warehouseFilter, $staleCutoff]);
    } else {
        $obsoleteSql = "SELECT COUNT(*) FROM products p WHERE p.is_archived = 0 AND p.status = 'active' AND p.current_stock > 0 AND NOT EXISTS (SELECT 1 FROM inventory_transactions it WHERE it.product_id = p.id AND DATE(it.created_at) >= ?)";
        $obsoleteStmt = $pdo->prepare($obsoleteSql);
        $obsoleteStmt->execute([$staleCutoff]);
    }
    $obsoleteCount = (int)$obsoleteStmt->fetchColumn();
} catch (PDOException $e) {
    $stockStats = ['total' => 0, 'total_stock' => 0, 'total_value' => 0];
    $lowStock = 0;
    $criticalStock = 0;
    $lowStockProducts = [];
    $obsoleteCount = 0;
}

try {
    $expirySql = "SELECT COUNT(DISTINCT product_id) FROM inventory_batches WHERE quality_status = 'accepted' AND available_quantity > 0 AND expiry_date IS NOT NULL AND expiry_date <= ?";
    $expiryParams = [$expiryCutoff];
    if ($warehouseFilter > 0) {
        $expirySql .= ' AND warehouse_id = ?';
        $expiryParams[] = $warehouseFilter;
    }
    $expiryStmt = $pdo->prepare($expirySql);
    $expiryStmt->execute($expiryParams);
    $expiringCount = (int)$expiryStmt->fetchColumn();
} catch (PDOException $e) {
    $expiringCount = 0;
}

try {
    $batchSql = "SELECT ib.*, p.sku, p.product_name, w.name AS warehouse_name
                 FROM inventory_batches ib
                 JOIN products p ON p.id = ib.product_id
                 JOIN warehouses w ON w.id = ib.warehouse_id";
    $batchParams = [];
    if ($warehouseFilter > 0) {
        $batchSql .= ' WHERE ib.warehouse_id = ?';
        $batchParams[] = $warehouseFilter;
    }
    $batchSql .= ' ORDER BY CASE WHEN ib.expiry_date IS NULL THEN 1 ELSE 0 END, ib.expiry_date ASC, ib.received_at DESC LIMIT 100';
    $batchStmt = $pdo->prepare($batchSql);
    $batchStmt->execute($batchParams);
    $inventoryBatches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $inventoryBatches = [];
}

// Get theme setting
$theme = 'light';
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'theme'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $theme = $result['setting_value'];
    }
} catch (Exception $e) {
    $theme = 'light';
}
$inventoryExportParams = $_GET;
unset($inventoryExportParams['action'], $inventoryExportParams['format']);
$inventoryExportQuery = http_build_query($inventoryExportParams);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - GlobalSCM</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: <?php echo COLOR_PRIMARY; ?>;
            --secondary: <?php echo COLOR_SECONDARY; ?>;
            --accent: <?php echo COLOR_ACCENT; ?>;
            --bg: <?php echo COLOR_BG; ?>;
            --card: <?php echo COLOR_CARD; ?>;
            --text: <?php echo COLOR_TEXT; ?>;
            --secondary-text: <?php echo COLOR_SECONDARY_TEXT; ?>;
            --border: <?php echo COLOR_BORDER; ?>;
        }
        
        [data-theme="dark"] {
            --bg: #0F172A;
            --card: #1E293B;
            --text: #E2E8F0;
            --secondary-text: #94A3B8;
            --border: #2D3748;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: var(--bg);
            color: var(--text);
            transition: all 0.3s;
        }
        
        .admin-layout {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 280px;
            background: var(--card);
            border-right: 1px solid var(--border);
            padding: 25px 20px;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            overflow-y: auto;
            transition: all 0.3s;
            z-index: 100;
        }
        
        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
        }
        
        .sidebar-brand h2 {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
        }
        
        .sidebar-brand span {
            font-size: 12px;
            color: var(--secondary-text);
            font-weight: 400;
            display: block;
        }
        
        .nav-section {
            margin-bottom: 25px;
        }
        
        .nav-section-title {
            font-size: 11px;
            text-transform: uppercase;
            color: var(--secondary-text);
            font-weight: 600;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }
        
        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 10px;
            color: var(--secondary-text);
            text-decoration: none;
            transition: all 0.3s;
            margin-bottom: 4px;
            cursor: pointer;
        }
        
        .nav-item:hover,
        .nav-item.active {
            background: rgba(47, 128, 237, 0.08);
            color: var(--primary);
        }
        
        .nav-item i {
            width: 20px;
            font-size: 16px;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 30px;
            flex: 1;
        }
        
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 15px 25px;
            background: var(--card);
            border-radius: 12px;
            border: 1px solid var(--border);
        }
        
        .page-title h1 {
            font-size: 24px;
            font-weight: 600;
        }
        
        .page-title p {
            color: var(--secondary-text);
            font-size: 14px;
        }
        
        .top-bar-actions {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 8px 20px;
            border: none;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: #2563EB;
            transform: translateY(-1px);
        }
        
        .btn-success {
            background: var(--accent);
            color: white;
        }
        
        .btn-success:hover {
            background: #059669;
            transform: translateY(-1px);
        }
        
        .btn-warning {
            background: #F59E0B;
            color: white;
        }
        
        .btn-warning:hover {
            background: #D97706;
            transform: translateY(-1px);
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text);
        }
        
        .btn-outline:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .btn-sm {
            padding: 4px 12px;
            font-size: 12px;
            border-radius: 6px;
        }
        
        .btn-back {
            background: var(--bg);
            border: 1px solid var(--border);
            color: var(--text);
        }
        
        .btn-back:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: rgba(47, 128, 237, 0.04);
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #D1FAE5;
            color: #065F46;
            border-left: 4px solid var(--accent);
        }
        
        .alert-error {
            background: #FEE2E2;
            color: #DC2626;
            border-left: 4px solid #DC2626;
        }

        .stock-alert {
            padding: 16px 20px;
            margin-bottom: 20px;
            border: 1px solid #FED7AA;
            border-left: 4px solid #D97706;
            border-radius: 8px;
            background: #FFF7ED;
            color: #7C2D12;
        }

        .stock-alert-critical {
            border-color: #FECACA;
            border-left-color: #DC2626;
            background: #FEF2F2;
            color: #7F1D1D;
        }

        .stock-alert-header {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 12px;
            align-items: baseline;
            margin-bottom: 10px;
        }

        .stock-alert-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            list-style: none;
            margin-bottom: 10px;
        }

        .stock-alert-list li {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 5px 9px;
            border: 1px solid currentColor;
            border-radius: 6px;
            font-size: 13px;
        }

        .stock-alert-level {
            font-weight: 700;
            text-transform: uppercase;
            font-size: 10px;
        }

        .stock-status-badge {
            display: inline-block;
            margin-top: 4px;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .stock-status-critical {
            background: #FEE2E2;
            color: #B91C1C;
        }

        .stock-status-low {
            background: #FEF3C7;
            color: #92400E;
        }

        .stock-status-normal {
            background: #D1FAE5;
            color: #065F46;
        }

        .category-group-row td {
            padding: 10px 20px;
            background: rgba(47, 128, 237, 0.08);
            color: var(--text);
            font-weight: 600;
        }

        .category-group-count {
            margin-left: 8px;
            color: var(--secondary-text);
            font-size: 12px;
            font-weight: 400;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--card);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid var(--border);
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.06);
        }
        
        .stat-card .label {
            font-size: 13px;
            color: var(--secondary-text);
            font-weight: 500;
        }
        
        .stat-card .value {
            font-size: 28px;
            font-weight: 700;
            margin-top: 5px;
        }
        
        .search-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .search-bar input,
        .search-bar select {
            padding: 10px 16px;
            border: 2px solid var(--border);
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            background: var(--bg);
            color: var(--text);
            flex: 1;
            min-width: 150px;
        }
        
        .search-bar input:focus,
        .search-bar select:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .table-container {
            background: var(--card);
            border-radius: 12px;
            border: 1px solid var(--border);
            overflow: hidden;
        }
        
        .table-header {
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
        }
        
        .table-header h2 {
            font-size: 16px;
            font-weight: 600;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        table th {
            padding: 12px 20px;
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--secondary-text);
            border-bottom: 1px solid var(--border);
            font-weight: 600;
        }
        
        table td {
            padding: 12px 20px;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
            vertical-align: middle;
        }
        
        table tr:hover td {
            background: rgba(47, 128, 237, 0.02);
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-active {
            background: #D1FAE5;
            color: #065F46;
        }
        
        .status-inactive {
            background: #FEE2E2;
            color: #DC2626;
        }
        
        .role-badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 12px;
            background: rgba(47, 128, 237, 0.1);
            color: var(--primary);
        }
        
        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        
        .barcode-display {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            letter-spacing: 2px;
            background: var(--bg);
            padding: 2px 10px;
            border-radius: 4px;
            display: inline-block;
        }
        
        .stock-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .stock-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }
        
        .stock-dot.green { background: var(--accent); }
        .stock-dot.yellow { background: #F59E0B; }
        .stock-dot.red { background: #DC2626; }
        
        .modal-overlay {
            display: <?php echo ($editProduct || isset($error) || $action === 'adjust_stock') ? 'flex' : 'none'; ?>;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            backdrop-filter: blur(4px);
        }
        
        .modal {
            background: var(--card);
            border-radius: 16px;
            padding: 30px;
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalIn 0.3s ease;
        }
        
        @keyframes modalIn {
            from {
                opacity: 0;
                transform: scale(0.95) translateY(-20px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }
        
        .modal h3 {
            font-size: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            background: var(--bg);
            color: var(--text);
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .form-actions .btn {
            flex: 1;
            justify-content: center;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
        }
        
        .user-info {
            font-size: 14px;
        }
        
        .user-info .name {
            font-weight: 500;
        }
        
        .user-info .role {
            font-size: 12px;
            color: var(--secondary-text);
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 0;
                padding: 0;
                overflow: hidden;
                position: fixed;
            }
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            .search-bar {
                flex-direction: column;
            }
            .search-bar input,
            .search-bar select {
                flex: none;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'partials/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="top-bar">
                <div class="page-title">
                    <h1>Inventory Management</h1>
                    <p>Manage products, stock levels, and barcodes</p>
                </div>
                <div class="top-bar-actions">
                    <a href="inventory.php?action=export&amp;format=pdf<?php echo $inventoryExportQuery !== '' ? '&amp;' . htmlspecialchars($inventoryExportQuery, ENT_QUOTES) : ''; ?>" class="btn btn-outline" title="Export filtered inventory as PDF"><i class="fas fa-file-pdf"></i> PDF</a>
                    <a href="inventory.php?action=export&amp;format=excel<?php echo $inventoryExportQuery !== '' ? '&amp;' . htmlspecialchars($inventoryExportQuery, ENT_QUOTES) : ''; ?>" class="btn btn-outline" title="Export filtered inventory as Excel"><i class="fas fa-file-excel"></i> Excel</a>
                    <a href="inventory.php?action=create" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Product
                    </a>
                    <a href="inventory.php<?php echo $showArchived ? '' : '?archived=1'; ?>" class="btn btn-outline">
                        <i class="fas fa-archive"></i> <?php echo $showArchived ? 'Active' : 'Archived'; ?>
                    </a>
                    <a href="dashboard.php" class="btn btn-back">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <?php if (!$showArchived && $lowStock > 0): ?>
                <div class="stock-alert <?php echo $criticalStock > 0 ? 'stock-alert-critical' : ''; ?>" role="alert" aria-live="polite">
                    <div class="stock-alert-header">
                        <strong><i class="fas fa-exclamation-triangle"></i> <?php echo $criticalStock > 0 ? 'Critical stock alert' : 'Low stock alert'; ?></strong>
                        <span><?php echo number_format($lowStock); ?> item<?php echo $lowStock === 1 ? '' : 's'; ?> at or below reorder point<?php if ($criticalStock > 0): ?>, including <?php echo number_format($criticalStock); ?> out of stock<?php endif; ?>.</span>
                    </div>
                    <ul class="stock-alert-list">
                        <?php foreach ($lowStockProducts as $alertProduct): ?>
                        <li>
                            <span class="stock-alert-level"><?php echo (int)$alertProduct['current_stock'] <= 0 ? 'Critical' : 'Low'; ?></span>
                            <span><?php echo htmlspecialchars($alertProduct['product_name']); ?>: <?php echo number_format($alertProduct['current_stock']); ?> on hand, reorder at <?php echo number_format($alertProduct['reorder_point']); ?></span>
                            <?php
                            $restockTarget = (int)$alertProduct['max_stock'] > (int)$alertProduct['current_stock']
                                ? (int)$alertProduct['max_stock']
                                : (int)$alertProduct['reorder_point'] + max(1, (int)$alertProduct['reorder_quantity']);
                            $suggestedQuantity = max(1, $restockTarget - (int)$alertProduct['current_stock']);
                            ?>
                            <a href="purchase-orders.php?action=create&amp;product_id=<?php echo (int)$alertProduct['id']; ?>&amp;quantity=<?php echo $suggestedQuantity; ?>">Create PO (+<?php echo number_format($suggestedQuantity); ?>)</a>
                        </li>
                        <?php endforeach; ?>
                        <?php if ($lowStock > count($lowStockProducts)): ?>
                        <li>+<?php echo number_format($lowStock - count($lowStockProducts)); ?> more</li>
                        <?php endif; ?>
                    </ul>
                    <a href="inventory.php?filter=low_stock<?php echo $warehouseFilter > 0 ? '&amp;warehouse_id=' . $warehouseFilter : ''; ?>">View low-stock products</a>
                </div>
            <?php endif; ?>
            
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="label">Total Products</div>
                    <div class="value"><?php echo number_format($stockStats['total'] ?? 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Total Stock Value</div>
                    <div class="value">₱<?php echo number_format($stockStats['total_value'] ?? 0, 2); ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Total Units</div>
                    <div class="value"><?php echo number_format($stockStats['total_stock'] ?? 0); ?></div>
                </div>
                <div class="stat-card" style="border-color: <?php echo $lowStock > 0 ? '#DC2626' : 'var(--border)'; ?>;">
                    <div class="label">Low Stock Items</div>
                    <div class="value" style="color: <?php echo $lowStock > 0 ? '#DC2626' : 'var(--accent)'; ?>;">
                        <?php echo $lowStock; ?>
                    </div>
                </div>
                <div class="stat-card" style="border-color: #7C3AED;">
                    <div class="label">Obsolete Stock (No Movement <?php echo $staleDays; ?> days)</div>
                    <div class="value" style="color: #7C3AED;"><?php echo number_format($obsoleteCount); ?></div>
                    <?php if ($obsoleteCount > 0): ?>
                    <a href="inventory.php?filter=obsolete<?php echo $warehouseFilter > 0 ? '&amp;warehouse_id=' . $warehouseFilter : ''; ?>" style="font-size:13px;color:#7C3AED;">
                        <i class="fas fa-eye"></i> Review Obsolete Stock
                    </a>
                    <?php else: ?>
                    <span style="font-size:12px;color:var(--secondary-text);">No stagnant items</span>
                    <?php endif; ?>
                </div>
                <div class="stat-card" style="border-color: #D97706;">
                    <div class="label">Expired / Expiring in 30 Days</div>
                    <div class="value" style="color:#D97706;"><?php echo number_format($expiringCount); ?></div>
                    <?php if ($expiringCount > 0): ?>
                    <a href="inventory.php?filter=expiring<?php echo $warehouseFilter > 0 ? '&amp;warehouse_id=' . $warehouseFilter : ''; ?>" style="font-size:13px;color:#D97706;">
                        <i class="fas fa-layer-group"></i> Review Expiring Batches
                    </a>
                    <?php else: ?>
                    <span style="font-size:12px;color:var(--secondary-text);">No expiring items</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Search -->
            <div class="search-bar">
                <form method="GET" style="display: flex; gap: 12px; flex: 1; flex-wrap: wrap;">
                    <?php if ($lowStockOnly || $obsoleteOnly || $expiringOnly): ?>
                    <input type="hidden" name="filter" value="<?php echo $lowStockOnly ? 'low_stock' : ($obsoleteOnly ? 'obsolete' : 'expiring'); ?>">
                    <?php endif; ?>
                    <input type="text" name="search" placeholder="Search by SKU, name, or barcode..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <select name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat['category']); ?>" 
                                <?php echo $category === $cat['category'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['category']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="item_type">
                        <option value="">All Item Types</option>
                        <?php foreach ($itemTypes as $type): ?>
                        <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $itemType === $type ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $type))); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status">
                        <option value="">All Item Statuses</option>
                        <?php foreach (['active', 'inactive', 'discontinued'] as $statusOption): ?>
                        <option value="<?php echo $statusOption; ?>" <?php echo $statusFilter === $statusOption ? 'selected' : ''; ?>>
                            <?php echo ucfirst($statusOption); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="min_price" min="0" step="0.01" placeholder="Min price"
                           value="<?php echo $minPrice !== null ? htmlspecialchars((string)$minPrice) : ''; ?>">
                    <input type="number" name="max_price" min="0" step="0.01" placeholder="Max price"
                           value="<?php echo $maxPrice !== null ? htmlspecialchars((string)$maxPrice) : ''; ?>">
                    <select name="group_id">
                        <option value="">All Groups</option>
                        <?php foreach ($inventoryGroups as $inventoryGroup): ?>
                        <option value="<?php echo (int)$inventoryGroup['id']; ?>" <?php echo $groupFilter === (int)$inventoryGroup['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($inventoryGroup['group_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="warehouse_id">
                        <option value="">All Warehouses</option>
                        <?php foreach ($warehouses as $warehouse): ?>
                        <option value="<?php echo (int)$warehouse['id']; ?>" <?php echo $warehouseFilter === (int)$warehouse['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($warehouse['name'] . ' (' . $warehouse['warehouse_code'] . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <?php if (!empty($search) || !empty($category) || $itemType !== '' || $statusFilter !== '' || $minPrice !== null || $maxPrice !== null || $groupFilter > 0 || $warehouseFilter > 0 || $lowStockOnly || $obsoleteOnly || $expiringOnly): ?>
                    <a href="inventory.php<?php echo $lowStockOnly ? '?filter=low_stock' : ''; ?>" class="btn btn-outline">
                        <i class="fas fa-times"></i> Clear
                    </a>
                    <?php endif; ?>
                </form>
            </div>
            
            <div class="table-container">
                <div class="table-header">
                    <h2><?php echo $showArchived ? 'Archived Products' : 'Active Products'; ?></h2>
                    <span class="role-badge"><?php echo count($products); ?> products</span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Product</th>
                            <th>Barcode</th>
                            <th>Category</th>
                            <th>Item Type</th>
                            <th>Warehouse</th>
                            <th>Stock</th>
                            <th>Price</th>
                            <th>Next Expiry</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($productsByCategory as $categoryGroup): ?>
                        <tr class="category-group-row">
                            <td colspan="11">
                                <?php echo htmlspecialchars($categoryGroup['label']); ?>
                                <span class="category-group-count"><?php echo count($categoryGroup['products']); ?> product<?php echo count($categoryGroup['products']) === 1 ? '' : 's'; ?></span>
                            </td>
                        </tr>
                        <?php foreach ($categoryGroup['products'] as $product): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($product['sku']); ?></strong></td>
                            <td>
                                <div><?php echo htmlspecialchars($product['product_name']); ?></div>
                                <div style="font-size: 12px; color: var(--secondary-text);">
                                    <?php if (!empty($product['brand'])): ?>Brand: <?php echo htmlspecialchars($product['brand']); ?> &middot; <?php endif; ?>
                                    <?php echo htmlspecialchars($product['description'] ?? ''); ?>
                                </div>
                            </td>
                            <td>
                                <span class="barcode-display"><?php echo htmlspecialchars($product['barcode']); ?></span>
                            </td>
                            <td>
                                <span class="role-badge"><?php echo htmlspecialchars($product['category'] ?? 'N/A'); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $product['item_type'] ?? 'general'))); ?></td>
                            <td><?php echo $warehouseFilter > 0 ? htmlspecialchars((string)($selectedWarehouse['name'] ?? 'Selected warehouse')) : 'All warehouses'; ?></td>
                            <td>
                                <div class="stock-indicator">
                                    <?php 
                                    $stockPercent = $product['max_stock'] > 0 ? ($product['current_stock'] / $product['max_stock']) * 100 : 100;
                                    $isCriticalStock = (int)$product['current_stock'] <= 0;
                                    $isLowStock = (int)$product['current_stock'] <= (int)$product['reorder_point'];
                                    $dotClass = $isLowStock ? 'red' : 
                                               ($stockPercent < 30 ? 'yellow' : 'green');
                                    ?>
                                    <span class="stock-dot <?php echo $dotClass; ?>"></span>
                                    <?php echo number_format($product['current_stock']); ?>
                                    <?php if ($product['unit_measure']): ?>
                                    <span style="font-size: 12px; color: var(--secondary-text);">
                                        <?php echo htmlspecialchars($product['unit_measure']); ?>
                                    </span>
                                    <?php endif; ?>
                                    <span class="stock-status-badge <?php echo $isCriticalStock ? 'stock-status-critical' : ($isLowStock ? 'stock-status-low' : 'stock-status-normal'); ?>">
                                        <?php echo $isCriticalStock ? 'Critical: Out of stock' : ($isLowStock ? 'Low stock' : 'In stock'); ?>
                                    </span>
                                </div>
                            </td>
                            <td>₱<?php echo number_format($product['unit_price'], 2); ?></td>
                            <td>
                                <?php if (!empty($product['next_expiry'])): ?>
                                    <?php $expiryIsPast = $product['next_expiry'] < date('Y-m-d'); ?>
                                    <span class="status-badge <?php echo $expiryIsPast ? 'status-inactive' : 'status-maintenance'; ?>">
                                        <?php echo $expiryIsPast ? 'Expired' : 'Expires'; ?> <?php echo htmlspecialchars($product['next_expiry']); ?>
                                    </span>
                                <?php else: ?>N/A<?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $product['status'] === 'active' ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo ucfirst($product['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <?php if (!$showArchived): ?>
                                        <button onclick="openStockAdjust(<?php echo (int)$product['id']; ?>, this)"
                                            data-product-name="<?php echo htmlspecialchars($product['product_name'], ENT_QUOTES); ?>"
                                            data-warehouse-stocks="<?php echo htmlspecialchars(json_encode($product['warehouse_stocks']), ENT_QUOTES); ?>"
                                            class="btn btn-success btn-sm" title="Adjust Stock">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="inventory.php?action=edit&id=<?php echo $product['id']; ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    <a href="inventory.php?action=archive&id=<?php echo $product['id']; ?>" 
                                       class="btn btn-warning btn-sm" 
                                       onclick="return confirm('Archive this product?');">
                                        <i class="fas fa-archive"></i>
                                    </a>
                                    <?php else: ?>
                                    <a href="inventory.php?action=restore&id=<?php echo $product['id']; ?>" 
                                       class="btn btn-success btn-sm"
                                       onclick="return confirm('Restore this product?');">
                                        <i class="fas fa-undo"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                        <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="11" style="text-align: center; padding: 40px; color: var(--secondary-text);">
                                <i class="fas fa-box" style="font-size: 40px; display: block; margin-bottom: 10px;"></i>
                                No products found
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="table-container" style="margin-top:24px;">
                <div class="table-header">
                    <h2>Batch, Expiry &amp; Quality Register</h2>
                    <span class="role-badge">Latest <?php echo count($inventoryBatches); ?> batches</span>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Item</th><th>Brand</th><th>Batch</th><th>Warehouse</th><th>Available</th><th>Expiry</th><th>Inspection</th><th>Notes</th><th>Receipt</th></tr></thead>
                        <tbody>
                            <?php foreach ($inventoryBatches as $batch): ?>
                            <?php $batchExpired = !empty($batch['expiry_date']) && $batch['expiry_date'] < date('Y-m-d'); ?>
                            <tr>
                                <td><?php echo htmlspecialchars($batch['product_name'] . ' (' . $batch['sku'] . ')'); ?></td>
                                <td><?php echo htmlspecialchars($batch['brand_snapshot'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($batch['batch_number']); ?></td>
                                <td><?php echo htmlspecialchars($batch['warehouse_name']); ?></td>
                                <td><?php echo number_format((int)$batch['available_quantity']); ?></td>
                                <td>
                                    <?php if ($batchExpired && $batch['quality_status'] === 'accepted'): ?>
                                    <span class="status-badge status-inactive">Expired</span>
                                    <?php elseif (!empty($batch['expiry_date'])): ?>
                                    <?php echo htmlspecialchars($batch['expiry_date']); ?>
                                    <?php else: ?>N/A<?php endif; ?>
                                </td>
                                <td><span class="status-badge <?php echo $batch['quality_status'] === 'accepted' ? 'status-active' : 'status-inactive'; ?>"><?php echo ucfirst($batch['quality_status']); ?></span></td>
                                <td><?php echo htmlspecialchars($batch['quality_notes'] ?? ''); ?></td>
                                <td><a href="purchase-orders.php?action=receipt&amp;receipt_number=<?php echo urlencode($batch['receipt_number']); ?>"><?php echo htmlspecialchars($batch['receipt_number']); ?></a></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$inventoryBatches): ?><tr><td colspan="9" class="empty-state">No received batches recorded.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Stock Adjustment Modal -->
    <div id="stockModal" class="modal-overlay" style="display: none;">
        <div class="modal">
            <h3><i class="fas fa-edit"></i> Adjust Stock</h3>
            <form method="POST" action="inventory.php?action=adjust_stock">
                <input type="hidden" name="id" id="stockProductId">
                <div class="form-group">
                    <label>Product</label>
                    <p id="stockProductName" style="font-weight: 500;"></p>
                </div>
                <div class="form-group">
                    <label>Current Stock</label>
                    <p id="stockCurrentStock" style="font-weight: 500;"></p>
                    <small id="stockWarehouseName" style="color: var(--secondary-text);"></small>
                </div>
                <div class="form-group">
                    <label>Warehouse</label>
                    <select name="warehouse_id" id="stockWarehouseId" required>
                        <?php foreach ($warehouses as $warehouse): ?>
                        <option value="<?php echo (int)$warehouse['id']; ?>" <?php echo ((int)($selectedWarehouse['id'] ?? ($warehouses[0]['id'] ?? 0)) === (int)$warehouse['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($warehouse['name'] . ' (' . $warehouse['warehouse_code'] . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Adjustment Type</label>
                    <select name="adjustment_type" required>
                        <option value="add">Add Stock</option>
                        <option value="remove">Remove Stock</option>
                        <option value="set">Set Exact Quantity</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="quantity" required min="0">
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" rows="2"></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" onclick="closeStockModal()" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Apply Adjustment
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Create/Edit Modal -->
    <?php if ($action === 'create' || $editProduct): ?>
    <div class="modal-overlay" style="display: flex;">
        <div class="modal" style="max-width: 700px;">
            <h3>
                <i class="fas fa-<?php echo $editProduct ? 'edit' : 'box'; ?>" style="color: var(--primary);"></i>
                <?php echo $editProduct ? 'Edit Product' : 'Add New Product'; ?>
            </h3>
            <form method="POST" action="inventory.php?action=<?php echo $editProduct ? 'edit' : 'create'; ?>">
                <?php if ($editProduct): ?>
                <input type="hidden" name="id" value="<?php echo $editProduct['id']; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label>SKU * <span style="font-size:11px;color:var(--secondary-text);font-weight:400;">(auto-generated or enter manually)</span></label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="text" name="sku" id="skuField" required
                               value="<?php echo isset($editProduct['sku']) ? htmlspecialchars($editProduct['sku']) : htmlspecialchars(generateUniqueSKU($pdo)); ?>"
                               placeholder="e.g., SKU-00001"
                               style="flex:1;">
                        <?php if (!$editProduct): ?>
                        <button type="button" onclick="regenerateSKU()" class="btn btn-outline" style="white-space:nowrap;padding:10px 14px;">
                            <i class="fas fa-sync-alt"></i> Generate
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Product Name *</label>
                    <input type="text" name="product_name" required 
                           value="<?php echo isset($editProduct['product_name']) ? htmlspecialchars($editProduct['product_name']) : ''; ?>"
                           placeholder="e.g., Wireless Mouse">
                </div>

                <div class="form-group">
                    <label>Brand</label>
                    <input type="text" name="brand" maxlength="150"
                           value="<?php echo isset($editProduct['brand']) ? htmlspecialchars($editProduct['brand']) : ''; ?>"
                           placeholder="e.g., manufacturer or brand">
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="2"><?php echo isset($editProduct['description']) ? htmlspecialchars($editProduct['description']) : ''; ?></textarea>
                </div>
                
                <div class="form-group">
                    <label>Category</label>
                    <select name="category" id="categorySelect" onchange="handleCategoryChange(this)">
                        <option value="">-- Select Category --</option>
                        <?php
                        $existingCategoryValues = array_column($categories, 'category');
                        $defaultCategories = ['Electronics', 'Office Supply', 'Tour Equipment', 'Safety Kit', 'Consumable', 'Furniture', 'Apparel', 'Machinery', 'Vehicle Part', 'Medical'];
                        $allCategoryOptions = array_unique(array_merge($defaultCategories, $existingCategoryValues));
                        sort($allCategoryOptions);
                        $selectedCategory = isset($editProduct['category']) ? $editProduct['category'] : '';
                        foreach ($allCategoryOptions as $catOpt):
                        ?>
                        <option value="<?php echo htmlspecialchars($catOpt); ?>"
                            <?php echo $selectedCategory === $catOpt ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($catOpt); ?>
                        </option>
                        <?php endforeach; ?>
                        <option value="__custom__">+ Add custom category...</option>
                    </select>
                    <input type="text" id="categoryCustomInput" name="category_custom"
                           placeholder="Type new category name"
                           style="display:none;margin-top:6px;"
                           value="<?php echo (isset($editProduct['category']) && !in_array($editProduct['category'], $allCategoryOptions ?? [])) ? htmlspecialchars($editProduct['category']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label>Item Type</label>
                    <select name="item_type" id="itemTypeSelect" required onchange="handleItemTypeChange(this)">
                        <?php
                        $defaultItemTypes = ['general', 'tour_equipment', 'office_supply', 'safety_kit', 'consumable', 'machinery', 'vehicle_part', 'medical', 'furniture', 'apparel'];
                        $allItemTypeOptions = array_unique(array_merge($defaultItemTypes, $itemTypes));
                        sort($allItemTypeOptions);
                        $selectedItemType = isset($editProduct['item_type']) ? $editProduct['item_type'] : 'general';
                        foreach ($allItemTypeOptions as $typeOpt):
                        ?>
                        <option value="<?php echo htmlspecialchars($typeOpt); ?>"
                            <?php echo $selectedItemType === $typeOpt ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $typeOpt))); ?>
                        </option>
                        <?php endforeach; ?>
                        <option value="__custom__">+ Add custom type...</option>
                    </select>
                    <input type="text" id="itemTypeCustomInput" name="item_type_custom"
                           placeholder="Type new item type (use_underscores)"
                           style="display:none;margin-top:6px;"
                           value="<?php echo (isset($editProduct['item_type']) && !in_array($editProduct['item_type'], $allItemTypeOptions ?? [])) ? htmlspecialchars($editProduct['item_type']) : ''; ?>">
                </div>

                <?php if (!$editProduct): ?>
                <div class="form-group">
                    <label>Initial Warehouse *</label>
                    <select name="warehouse_id" required>
                        <?php foreach ($warehouses as $warehouse): ?>
                        <option value="<?php echo (int)$warehouse['id']; ?>" <?php echo ((int)($selectedWarehouse['id'] ?? ($warehouses[0]['id'] ?? 0)) === (int)$warehouse['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($warehouse['name'] . ' (' . $warehouse['warehouse_code'] . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Unit Measure</label>
                    <input type="text" name="unit_measure" 
                           value="<?php echo isset($editProduct['unit_measure']) ? htmlspecialchars($editProduct['unit_measure']) : ''; ?>"
                           placeholder="e.g., pcs, kg, box">
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Unit Price (₱)</label>
                        <input type="number" name="unit_price" step="0.01" 
                               value="<?php echo isset($editProduct['unit_price']) ? $editProduct['unit_price'] : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Serial Number Prefix</label>
                        <input type="text" name="serial_number_prefix" 
                               value="<?php echo isset($editProduct['serial_number_prefix']) ? htmlspecialchars($editProduct['serial_number_prefix']) : 'SN'; ?>">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Initial Stock</label>
                        <input type="number" name="current_stock" 
                               value="<?php echo isset($editProduct) ? $editProduct['current_stock'] : 0; ?>">
                    </div>
                    <div class="form-group">
                        <label>Min Stock</label>
                        <input type="number" name="min_stock" 
                               value="<?php echo isset($editProduct['min_stock']) ? $editProduct['min_stock'] : 0; ?>">
                    </div>
                    <div class="form-group">
                        <label>Max Stock</label>
                        <input type="number" name="max_stock" 
                               value="<?php echo isset($editProduct['max_stock']) ? $editProduct['max_stock'] : 0; ?>">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Reorder Point</label>
                        <input type="number" name="reorder_point" 
                               value="<?php echo isset($editProduct['reorder_point']) ? $editProduct['reorder_point'] : 0; ?>">
                    </div>
                    <div class="form-group">
                        <label>Reorder Quantity</label>
                        <input type="number" name="reorder_quantity" 
                               value="<?php echo isset($editProduct['reorder_quantity']) ? $editProduct['reorder_quantity'] : 0; ?>">
                    </div>
                </div>
                
                <?php if ($editProduct): ?>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?php echo ($editProduct['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($editProduct['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="discontinued" <?php echo ($editProduct['status'] ?? '') === 'discontinued' ? 'selected' : ''; ?>>Discontinued</option>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="form-actions">
                    <a href="inventory.php" class="btn btn-outline">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?php echo $editProduct ? 'Update' : 'Create'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
        // ── Stock Adjust Modal ───────────────────────────────────────────────
        function openStockAdjust(id, button) {
            var warehouseStocks = JSON.parse(button.dataset.warehouseStocks || '{}');
            document.getElementById('stockProductId').value = id;
            document.getElementById('stockProductName').textContent = button.dataset.productName;
            var warehouseSelect = document.getElementById('stockWarehouseId');
            var currentStock = document.getElementById('stockCurrentStock');
            var warehouseName = document.getElementById('stockWarehouseName');
            function updateWarehouseBalance() {
                var selectedOption = warehouseSelect.options[warehouseSelect.selectedIndex];
                currentStock.textContent = Number(warehouseStocks[warehouseSelect.value] || 0).toLocaleString();
                warehouseName.textContent = selectedOption ? selectedOption.textContent : '';
            }
            warehouseSelect.onchange = updateWarehouseBalance;
            updateWarehouseBalance();
            document.getElementById('stockModal').style.display = 'flex';
        }

        function closeStockModal() {
            document.getElementById('stockModal').style.display = 'none';
        }

        // ── SKU Auto-Generate ────────────────────────────────────────────────
        function regenerateSKU() {
            var btn = event.currentTarget;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            fetch('<?php echo htmlspecialchars($_SERVER["PHP_SELF"] ?? "inventory.php"); ?>?action=generate_sku', {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.ok ? r.json() : Promise.reject(r); })
            .then(function(data) {
                if (data.sku) {
                    document.getElementById('skuField').value = data.sku;
                }
            })
            .catch(function() {
                // Fallback: generate client-side timestamp SKU
                var ts = 'SKU-' + String(Date.now()).slice(-5).padStart(5,'0');
                document.getElementById('skuField').value = ts;
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-sync-alt"></i> Generate';
            });
        }

        // ── Category Custom Input Toggle ─────────────────────────────────────
        function handleCategoryChange(sel) {
            var customInput = document.getElementById('categoryCustomInput');
            var hiddenSel   = document.querySelector('select[name="category"]');
            if (sel.value === '__custom__') {
                customInput.style.display = 'block';
                customInput.required = true;
                customInput.focus();
            } else {
                customInput.style.display = 'none';
                customInput.required = false;
                customInput.value = '';
            }
        }

        // ── Item Type Custom Input Toggle ────────────────────────────────────
        function handleItemTypeChange(sel) {
            var customInput = document.getElementById('itemTypeCustomInput');
            if (sel.value === '__custom__') {
                customInput.style.display = 'block';
                customInput.required = true;
                customInput.focus();
            } else {
                customInput.style.display = 'none';
                customInput.required = false;
                customInput.value = '';
            }
        }

        // ── Form submit: merge custom category/itemtype back into named fields ─
        document.addEventListener('DOMContentLoaded', function() {
            var productForm = document.querySelector('form[action*="create"], form[action*="edit"]');
            if (productForm) {
                productForm.addEventListener('submit', function(e) {
                    // Category
                    var catSel    = document.getElementById('categorySelect');
                    var catCustom = document.getElementById('categoryCustomInput');
                    if (catSel && catSel.value === '__custom__') {
                        if (!catCustom.value.trim()) {
                            e.preventDefault();
                            catCustom.focus();
                            catCustom.style.borderColor = '#DC2626';
                            return;
                        }
                        // Inject a hidden input with the real value
                        var hCat = document.createElement('input');
                        hCat.type  = 'hidden';
                        hCat.name  = 'category';
                        hCat.value = catCustom.value.trim();
                        productForm.appendChild(hCat);
                        catSel.removeAttribute('name');  // prevent duplicate
                    }

                    // Item Type
                    var typeSel    = document.getElementById('itemTypeSelect');
                    var typeCustom = document.getElementById('itemTypeCustomInput');
                    if (typeSel && typeSel.value === '__custom__') {
                        if (!typeCustom.value.trim()) {
                            e.preventDefault();
                            typeCustom.focus();
                            typeCustom.style.borderColor = '#DC2626';
                            return;
                        }
                        var hType = document.createElement('input');
                        hType.type  = 'hidden';
                        hType.name  = 'item_type';
                        hType.value = typeCustom.value.trim().toLowerCase().replace(/\s+/g,'_');
                        productForm.appendChild(hType);
                        typeSel.removeAttribute('name');
                    }
                });
            }
        });

        // ── Close modal on overlay click ─────────────────────────────────────
        document.querySelectorAll('.modal-overlay').forEach(function(modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.style.display = 'none';
                    if (!this.querySelector('form')?.action?.includes('adjust_stock')) {
                        window.location.href = 'inventory.php';
                    }
                }
            });
        });
    </script>
</body>
</html>