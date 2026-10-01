<?php
// admin/inventory.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

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
// NO DUPLICATE FUNCTIONS HERE
// All functions are in config/database.php
// generateSerialNumber() and generateBarcode() are already defined there
// ============================================

$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// Create Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $sku = isset($_POST['sku']) ? trim($_POST['sku']) : '';
    $product_name = isset($_POST['product_name']) ? trim($_POST['product_name']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $category = isset($_POST['category']) ? trim($_POST['category']) : '';
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
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("INSERT INTO products (sku, product_name, description, category, unit_measure, unit_price, reorder_point, reorder_quantity, current_stock, min_stock, max_stock, barcode, serial_number_prefix, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$sku, $product_name, $description, $category, $unit_measure, $unit_price, $reorder_point, $reorder_quantity, $current_stock, $min_stock, $max_stock, $barcode, $serial_number_prefix, $_SESSION['user_id']]);
        
        $product_id = $pdo->lastInsertId();
        
        // Generate initial serial number - USING THE FUNCTION FROM DATABASE.PHP
        generateSerialNumber($product_id);
        
        // Log initial stock
        if ($current_stock > 0) {
            $stmt = $pdo->prepare("INSERT INTO inventory_transactions (product_id, transaction_type, quantity, previous_balance, new_balance, created_by) VALUES (?, 'receiving', ?, 0, ?, ?)");
            $stmt->execute([$product_id, $current_stock, $current_stock, $_SESSION['user_id']]);
        }
        
        $pdo->commit();
        
        logAudit($_SESSION['user_id'], 'create_product', 'inventory', "Created product: $product_name (SKU: $sku)");
        $_SESSION['success'] = "Product created successfully! Barcode: $barcode";
        header('Location: inventory.php');
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Error creating product: " . $e->getMessage();
    }
}

// Update Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $sku = isset($_POST['sku']) ? trim($_POST['sku']) : '';
    $product_name = isset($_POST['product_name']) ? trim($_POST['product_name']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $category = isset($_POST['category']) ? trim($_POST['category']) : '';
    $unit_measure = isset($_POST['unit_measure']) ? trim($_POST['unit_measure']) : '';
    $unit_price = isset($_POST['unit_price']) ? (float)$_POST['unit_price'] : 0;
    $reorder_point = isset($_POST['reorder_point']) ? (int)$_POST['reorder_point'] : 0;
    $reorder_quantity = isset($_POST['reorder_quantity']) ? (int)$_POST['reorder_quantity'] : 0;
    $min_stock = isset($_POST['min_stock']) ? (int)$_POST['min_stock'] : 0;
    $max_stock = isset($_POST['max_stock']) ? (int)$_POST['max_stock'] : 0;
    $status = isset($_POST['status']) ? $_POST['status'] : 'active';
    
    try {
        $stmt = $pdo->prepare("UPDATE products SET sku = ?, product_name = ?, description = ?, category = ?, unit_measure = ?, unit_price = ?, reorder_point = ?, reorder_quantity = ?, min_stock = ?, max_stock = ?, status = ? WHERE id = ?");
        $stmt->execute([$sku, $product_name, $description, $category, $unit_measure, $unit_price, $reorder_point, $reorder_quantity, $min_stock, $max_stock, $status, $id]);
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
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    
    try {
        // Get current stock
        $stmt = $pdo->prepare("SELECT current_stock FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_stock = $product['current_stock'];
        
        // Calculate new balance
        if ($adjustment_type === 'add') {
            $new_stock = $current_stock + $quantity;
        } else if ($adjustment_type === 'remove') {
            $new_stock = max(0, $current_stock - $quantity);
        } else {
            $new_stock = $quantity; // Set exact
        }
        
        // Update product
        $stmt = $pdo->prepare("UPDATE products SET current_stock = ? WHERE id = ?");
        $stmt->execute([$new_stock, $id]);
        
        // Log transaction
        $stmt = $pdo->prepare("INSERT INTO inventory_transactions (product_id, transaction_type, quantity, previous_balance, new_balance, notes, created_by) VALUES (?, 'adjustment', ?, ?, ?, ?, ?)");
        $stmt->execute([$id, abs($new_stock - $current_stock), $current_stock, $new_stock, $notes, $_SESSION['user_id']]);
        
        logAudit($_SESSION['user_id'], 'adjust_stock', 'inventory', "Adjusted stock for product ID: $id");
        $_SESSION['success'] = "Stock adjusted successfully!";
        header('Location: inventory.php');
        exit();
    } catch (PDOException $e) {
        $error = "Error adjusting stock: " . $e->getMessage();
    }
}

// Get products
$showArchived = isset($_GET['archived']) ? 1 : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';

$query = "SELECT p.*, u.full_name as created_by_name 
          FROM products p 
          LEFT JOIN users u ON p.created_by = u.id 
          WHERE p.is_archived = ?";
$params = [$showArchived];

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

$query .= " ORDER BY p.created_at DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $products = [];
    $error = "Error fetching products: " . $e->getMessage();
}

// Get categories for filter
try {
    $stmt = $pdo->query("SELECT DISTINCT category FROM products WHERE is_archived = 0 AND category IS NOT NULL ORDER BY category");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
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
    $stmt = $pdo->query("SELECT COUNT(*) as total, SUM(current_stock) as total_stock, SUM(unit_price * current_stock) as total_value FROM products WHERE is_archived = 0");
    $stockStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT COUNT(*) as low_stock FROM products WHERE current_stock <= reorder_point AND is_archived = 0");
    $lowStock = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
} catch (PDOException $e) {
    $stockStats = ['total' => 0, 'total_stock' => 0, 'total_value' => 0];
    $lowStock = 0;
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
            </div>
            
            <!-- Search -->
            <div class="search-bar">
                <form method="GET" style="display: flex; gap: 12px; flex: 1; flex-wrap: wrap;">
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
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <?php if (!empty($search) || !empty($category)): ?>
                    <a href="inventory.php" class="btn btn-outline">
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
                            <th>Stock</th>
                            <th>Price</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($product['sku']); ?></strong></td>
                            <td>
                                <div><?php echo htmlspecialchars($product['product_name']); ?></div>
                                <div style="font-size: 12px; color: var(--secondary-text);">
                                    <?php echo htmlspecialchars($product['description'] ?? ''); ?>
                                </div>
                            </td>
                            <td>
                                <span class="barcode-display"><?php echo htmlspecialchars($product['barcode']); ?></span>
                            </td>
                            <td>
                                <span class="role-badge"><?php echo htmlspecialchars($product['category'] ?? 'N/A'); ?></span>
                            </td>
                            <td>
                                <div class="stock-indicator">
                                    <?php 
                                    $stockPercent = $product['max_stock'] > 0 ? ($product['current_stock'] / $product['max_stock']) * 100 : 0;
                                    $dotClass = $product['current_stock'] <= $product['reorder_point'] ? 'red' : 
                                               ($stockPercent < 30 ? 'yellow' : 'green');
                                    ?>
                                    <span class="stock-dot <?php echo $dotClass; ?>"></span>
                                    <?php echo number_format($product['current_stock']); ?>
                                    <?php if ($product['unit_measure']): ?>
                                    <span style="font-size: 12px; color: var(--secondary-text);">
                                        <?php echo htmlspecialchars($product['unit_measure']); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>₱<?php echo number_format($product['unit_price'], 2); ?></td>
                            <td>
                                <span class="status-badge <?php echo $product['status'] === 'active' ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo ucfirst($product['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <?php if (!$showArchived): ?>
                                    <button onclick="openStockAdjust(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['product_name']); ?>', <?php echo $product['current_stock']; ?>)" 
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
                        <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px; color: var(--secondary-text);">
                                <i class="fas fa-box" style="font-size: 40px; display: block; margin-bottom: 10px;"></i>
                                No products found
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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
                    <label>SKU *</label>
                    <input type="text" name="sku" required 
                           value="<?php echo isset($editProduct['sku']) ? htmlspecialchars($editProduct['sku']) : ''; ?>"
                           placeholder="e.g., SKU-001">
                </div>
                
                <div class="form-group">
                    <label>Product Name *</label>
                    <input type="text" name="product_name" required 
                           value="<?php echo isset($editProduct['product_name']) ? htmlspecialchars($editProduct['product_name']) : ''; ?>"
                           placeholder="e.g., Wireless Mouse">
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="2"><?php echo isset($editProduct['description']) ? htmlspecialchars($editProduct['description']) : ''; ?></textarea>
                </div>
                
                <div class="form-group">
                    <label>Category</label>
                    <input type="text" name="category" 
                           value="<?php echo isset($editProduct['category']) ? htmlspecialchars($editProduct['category']) : ''; ?>"
                           placeholder="e.g., Electronics">
                </div>
                
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
        function openStockAdjust(id, name, stock) {
            document.getElementById('stockProductId').value = id;
            document.getElementById('stockProductName').textContent = name;
            document.getElementById('stockCurrentStock').textContent = stock;
            document.getElementById('stockModal').style.display = 'flex';
        }
        
        function closeStockModal() {
            document.getElementById('stockModal').style.display = 'none';
        }
        
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