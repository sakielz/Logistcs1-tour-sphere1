<?php
// admin/purchase-orders.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth(['admin','procurement_officer']);

// Define color constants if not already defined
if (!defined('COLOR_PRIMARY')) define('COLOR_PRIMARY', '#2F80ED');
if (!defined('COLOR_SECONDARY')) define('COLOR_SECONDARY', '#56CCF2');
if (!defined('COLOR_ACCENT')) define('COLOR_ACCENT', '#27AE60');
if (!defined('COLOR_BG')) define('COLOR_BG', '#F8FAFC');
if (!defined('COLOR_CARD')) define('COLOR_CARD', '#FFFFFF');
if (!defined('COLOR_TEXT')) define('COLOR_TEXT', '#1F2937');
if (!defined('COLOR_SECONDARY_TEXT')) define('COLOR_SECONDARY_TEXT', '#6B7280');
if (!defined('COLOR_BORDER')) define('COLOR_BORDER', '#EEF2F7');

$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// Get theme setting
$theme = getTheme();

// ============================================
// GET SUPPLIERS FOR DROPDOWN
// ============================================
try {
    $stmt = $pdo->query("SELECT id, company_name FROM suppliers WHERE status = 'active' AND is_archived = false ORDER BY company_name");
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $suppliers = [];
}

// ============================================
// GET PRODUCTS FOR DROPDOWN
// ============================================
try {
    $stmt = $pdo->query("SELECT id, sku, product_name, unit_price, current_stock FROM products WHERE status = 'active' AND is_archived = false ORDER BY product_name");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $products = [];
}

// ============================================
// EXPORT FUNCTIONALITY
// ============================================
if ($action === 'export' && isset($_GET['format'])) {
    $format = $_GET['format'];
    
    try {
        $query = "SELECT po.*, s.company_name, u.full_name as created_by_name 
                  FROM purchase_orders po 
                  LEFT JOIN suppliers s ON po.supplier_id = s.id 
                  LEFT JOIN users u ON po.created_by = u.id 
                  WHERE po.is_archived = false";
        $params = [];
        
        if (!empty($statusFilter)) {
            $query .= " AND po.status = ?";
            $params[] = $statusFilter;
        }
        if (!empty($search)) {
            $query .= " AND (po.po_number LIKE ? OR s.company_name LIKE ?)";
            $searchParam = "%$search%";
            $params[] = $searchParam;
            $params[] = $searchParam;
        }
        
        $query .= " ORDER BY po.created_at DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="purchase_orders_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['PO Number', 'Supplier', 'Date', 'Total Amount', 'Status', 'Approval', 'Created By']);
            foreach ($data as $row) {
                fputcsv($output, [
                    $row['po_number'],
                    $row['company_name'],
                    $row['order_date'],
                    $row['total_amount'],
                    $row['status'],
                    $row['approval_status'],
                    $row['created_by_name']
                ]);
            }
            fclose($output);
            exit();
        } elseif ($format === 'pdf') {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="purchase_orders_' . date('Y-m-d') . '.pdf"');
            
            echo '<html><head><style>
                body { font-family: Arial, sans-serif; font-size: 12px; }
                table { width: 100%; border-collapse: collapse; }
                th { background: #2F80ED; color: white; padding: 8px; text-align: left; }
                td { padding: 8px; border-bottom: 1px solid #ddd; }
                h1 { color: #1F2937; }
            </style></head><body>';
            echo '<h1>Purchase Orders</h1>';
            echo '<p>Generated: ' . date('Y-m-d H:i:s') . '</p>';
            echo '<table>';
            echo '<tr><th>PO Number</th><th>Supplier</th><th>Date</th><th>Total</th><th>Status</th><th>Approval</th></tr>';
            foreach ($data as $row) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($row['po_number']) . '</td>';
                echo '<td>' . htmlspecialchars($row['company_name']) . '</td>';
                echo '<td>' . htmlspecialchars($row['order_date']) . '</td>';
                echo '<td>' . number_format($row['total_amount'], 2) . '</td>';
                echo '<td>' . ucfirst($row['status']) . '</td>';
                echo '<td>' . ucfirst(str_replace('_', ' ', $row['approval_status'] ?? 'pending')) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            echo '</body></html>';
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Export failed: " . $e->getMessage();
        header('Location: purchase-orders.php');
        exit();
    }
}

// ============================================
// BULK APPROVE
// ============================================
if ($action === 'bulk_approve' && isset($_POST['ids'])) {
    $ids = array_map('intval', $_POST['ids']);
    if (!empty($ids)) {
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE purchase_orders SET approval_status = 'approved', status = 'approved', approved_by = ? WHERE id IN ($placeholders)");
            $stmt->execute(array_merge([$_SESSION['user_id']], $ids));
            logAudit($_SESSION['user_id'], 'bulk_approve_po', 'purchase_order', "Bulk approved " . count($ids) . " purchase orders");
            $_SESSION['success'] = "Successfully approved " . count($ids) . " purchase orders!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Bulk approve failed: " . $e->getMessage();
        }
    }
    header('Location: purchase-orders.php');
    exit();
}

// ============================================
// CREATE PURCHASE ORDER
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $supplier_id = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : 0;
    $order_date = isset($_POST['order_date']) ? $_POST['order_date'] : date('Y-m-d');
    $expected_delivery = isset($_POST['expected_delivery']) ? $_POST['expected_delivery'] : '';
    $shipping_address = isset($_POST['shipping_address']) ? trim($_POST['shipping_address']) : '';
    $terms = isset($_POST['terms']) ? trim($_POST['terms']) : '';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    
    // Generate PO number
    $po_number = 'PO-' . date('Ymd') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("INSERT INTO purchase_orders (po_number, supplier_id, order_date, expected_delivery, shipping_address, terms, notes, status, approval_status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 'pending_review', ?)");
        $stmt->execute([$po_number, $supplier_id, $order_date, $expected_delivery, $shipping_address, $terms, $notes, $_SESSION['user_id']]);
        $po_id = $pdo->lastInsertId();
        
        // Add items
        $items = isset($_POST['items']) ? $_POST['items'] : [];
        $total_amount = 0;
        
        foreach ($items as $item) {
            if (!empty($item['product_id']) && !empty($item['quantity']) && !empty($item['unit_price'])) {
                $product_id = $item['product_id'];
                $quantity = (int)$item['quantity'];
                $unit_price = (float)$item['unit_price'];
                $total_price = $quantity * $unit_price;
                $total_amount += $total_price;
                
                $stmt = $pdo->prepare("INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_price, total_price, expected_date) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$po_id, $product_id, $quantity, $unit_price, $total_price, $expected_delivery]);
            }
        }
        
        // Update total
        $stmt = $pdo->prepare("UPDATE purchase_orders SET total_amount = ? WHERE id = ?");
        $stmt->execute([$total_amount, $po_id]);
        
        $pdo->commit();
        
        logAudit($_SESSION['user_id'], 'create_po', 'purchase_order', "Created PO: $po_number");
        $_SESSION['success'] = "Purchase Order $po_number created successfully!";
        header('Location: purchase-orders.php');
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Error creating PO: " . $e->getMessage();
    }
}

// ============================================
// UPDATE PO STATUS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_status') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $status = isset($_POST['status']) ? $_POST['status'] : '';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    
    try {
        $stmt = $pdo->prepare("UPDATE purchase_orders SET status = ?, notes = ? WHERE id = ?");
        $stmt->execute([$status, $notes, $id]);
        
        if ($status === 'approved') {
            $stmt = $pdo->prepare("UPDATE purchase_orders SET approval_status = 'approved', approved_by = ? WHERE id = ?");
            $stmt->execute([$_SESSION['user_id'], $id]);
        } elseif ($status === 'rejected') {
            $stmt = $pdo->prepare("UPDATE purchase_orders SET approval_status = 'rejected' WHERE id = ?");
            $stmt->execute([$id]);
        } elseif ($status === 'received') {
            // Update stock when PO is received
            $stmt = $pdo->prepare("SELECT product_id, quantity FROM purchase_order_items WHERE po_id = ?");
            $stmt->execute([$id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($items as $item) {
                $stmt2 = $pdo->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?");
                $stmt2->execute([$item['quantity'], $item['product_id']]);
                
                // Log transaction
                $stmt2 = $pdo->prepare("INSERT INTO inventory_transactions (product_id, transaction_type, quantity, previous_balance, new_balance, reference_document, created_by) 
                                        SELECT ?, 'receiving', ?, current_stock, current_stock + ?, ?, ? FROM products WHERE id = ?");
                $stmt2->execute([$item['product_id'], $item['quantity'], $item['quantity'], $po_number, $_SESSION['user_id'], $item['product_id']]);
            }
        }
        
        logAudit($_SESSION['user_id'], 'update_po_status', 'purchase_order', "Updated PO ID $id to status: $status");
        $_SESSION['success'] = "PO status updated successfully!";
        header('Location: purchase-orders.php');
        exit();
    } catch (PDOException $e) {
        $error = "Error updating PO: " . $e->getMessage();
    }
}

// ============================================
// ARCHIVE PO
// ============================================
if ($action === 'archive' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    archiveRecord('purchase_orders', $id);
    logAudit($_SESSION['user_id'], 'archive_po', 'purchase_order', "Archived PO ID: $id");
    $_SESSION['success'] = "PO archived successfully!";
    header('Location: purchase-orders.php');
    exit();
}

// ============================================
// RESTORE PO
// ============================================
if ($action === 'restore' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    restoreRecord('purchase_orders', $id);
    logAudit($_SESSION['user_id'], 'restore_po', 'purchase_order', "Restored PO ID: $id");
    $_SESSION['success'] = "PO restored successfully!";
    header('Location: purchase-orders.php?archived=1');
    exit();
}

// ============================================
// VIEW PO
// ============================================
$viewPO = null;
if ($action === 'view' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("SELECT po.*, s.company_name, s.contact_person, s.email, s.phone, u.full_name as created_by_name 
                               FROM purchase_orders po 
                               JOIN suppliers s ON po.supplier_id = s.id 
                               LEFT JOIN users u ON po.created_by = u.id 
                               WHERE po.id = ?");
        $stmt->execute([$_GET['id']]);
        $viewPO = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($viewPO) {
            $stmt = $pdo->prepare("SELECT poi.*, p.sku, p.product_name, p.unit_measure 
                                   FROM purchase_order_items poi 
                                   JOIN products p ON poi.product_id = p.id 
                                   WHERE poi.po_id = ?");
            $stmt->execute([$viewPO['id']]);
            $viewPO['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error fetching PO: " . $e->getMessage();
        header('Location: purchase-orders.php');
        exit();
    }
}

// ============================================
// GET POs WITH FILTERS & SORTING
// ============================================
$showArchived = isset($_GET['archived']) ? 1 : 0;
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$approvalFilter = isset($_GET['approval']) ? $_GET['approval'] : '';
$supplierFilter = isset($_GET['supplier_id']) ? trim($_GET['supplier_id']) : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sortField = isset($_GET['sort']) ? $_GET['sort'] : 'created_at';
$sortOrder = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'ASC' : 'DESC';

$allowedSortFields = ['po_number', 'company_name', 'order_date', 'total_amount', 'status', 'approval_status', 'created_at'];
if (!in_array($sortField, $allowedSortFields)) {
    $sortField = 'created_at';
}

try {
    $query = "SELECT po.*, s.company_name, u.full_name as created_by_name 
              FROM purchase_orders po 
              LEFT JOIN suppliers s ON po.supplier_id = s.id 
              LEFT JOIN users u ON po.created_by = u.id 
              WHERE po.is_archived = ?";
    $params = [$showArchived];
    
    if (!empty($search)) {
        $query .= " AND (po.po_number LIKE ? OR s.company_name LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    if (!empty($statusFilter)) {
        $query .= " AND po.status = ?";
        $params[] = $statusFilter;
    }
    
    if (!empty($approvalFilter)) {
        $query .= " AND po.approval_status = ?";
        $params[] = $approvalFilter;
    }
    
    if (!empty($supplierFilter)) {
        $query .= " AND s.company_name LIKE ?";
        $params[] = "%$supplierFilter%";
    }
    
    if (!empty($dateFrom)) {
        $query .= " AND DATE(po.order_date) >= ?";
        $params[] = $dateFrom;
    }
    
    if (!empty($dateTo)) {
        $query .= " AND DATE(po.order_date) <= ?";
        $params[] = $dateTo;
    }
    
    $query .= " ORDER BY $sortField $sortOrder";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $pos = [];
    $error = "Error fetching POs: " . $e->getMessage();
}

// ============================================
// GET STATS
// ============================================
try {
    $stmt = $pdo->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status IN ('pending', 'approved') THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) as received,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM purchase_orders WHERE is_archived = 0");
    $poStats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $poStats = ['total' => 0, 'pending' => 0, 'received' => 0, 'completed' => 0, 'cancelled' => 0];
}

// Pagination
$itemsPerPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if ($itemsPerPage < 1) {
    $itemsPerPage = 10;
}
$totalItems = count($pos);
$totalPages = (int)ceil($totalItems / $itemsPerPage);
if ($totalPages < 1) {
    $totalPages = 1;
}
$pageNum = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$pageNum = (int)max(1, min($pageNum, $totalPages));
$offset = ($pageNum - 1) * $itemsPerPage;
$paginatedPos = array_slice($pos, $offset, $itemsPerPage);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders - GlobalSCM</title>
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
            --radius: 12px;
            --radius-sm: 8px;
            --shadow: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-lg: 0 10px 40px rgba(0,0,0,0.08);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --danger: #DC2626;
            --warning: #F59E0B;
        }
        
        [data-theme="dark"] {
            --bg: <?php echo COLOR_DARK_BG; ?>;
            --card: <?php echo COLOR_DARK_CARD; ?>;
            --text: <?php echo COLOR_DARK_TEXT; ?>;
            --secondary-text: <?php echo COLOR_DARK_SECONDARY_TEXT; ?>;
            --border: <?php echo COLOR_DARK_BORDER; ?>;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: var(--bg);
            color: var(--text);
            transition: var(--transition);
            line-height: 1.6;
            min-height: 100vh;
            width: 100%;
            overflow-x: hidden;
        }
        
        .admin-layout { display: flex; min-height: 100vh; width: 100%; }
        
        .sidebar {
            width: 280px;
            background: var(--card);
            border-right: 1px solid var(--border);
            padding: 24px 16px;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            overflow-y: auto;
            transition: var(--transition);
            z-index: 100;
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow);
        }
        
        .sidebar-brand {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
        }
        
        .sidebar-brand > div { display: flex; flex-direction: column; }
        .sidebar-brand h2 { font-size: 20px; font-weight: 700; color: var(--primary); }
        .sidebar-brand span { font-size: 11px; color: var(--secondary-text); font-weight: 400; letter-spacing: 1px; text-transform: uppercase; display: block; }
        
        .sidebar-toggle-btn {
            display: none;
            background: none;
            border: none;
            font-size: 20px;
            color: var(--secondary-text);
            cursor: pointer;
            padding: 4px 8px;
        }
        
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.4);
            z-index: 99;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
        }
        .sidebar-overlay.active { display: block; }
        
        .main-content {
            margin-left: 280px;
            padding: 24px 32px 40px;
            flex: 1;
            min-height: 100vh;
            width: calc(100% - 280px);
        }
        
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            padding: 16px 24px;
            background: var(--card);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            flex-wrap: wrap;
            gap: 12px;
            width: 100%;
        }
        
        .page-title h1 { 
            font-size: 22px; 
            font-weight: 600; 
            color: var(--text);
            display: flex;
            align-items: center;
        }
        
        .page-title h1 i {
            color: var(--primary);
            margin-right: 12px;
            font-size: 24px;
        }
        
        .page-title p { 
            color: var(--secondary-text); 
            font-size: 14px; 
            margin-top: 2px; 
            margin-left: 36px;
        }
        
        .top-bar-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 8px 18px;
            border: none;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            white-space: nowrap;
        }
        
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: #2563EB; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(47, 128, 237, 0.3); }
        .btn-success { background: var(--accent); color: white; }
        .btn-success:hover { background: #059669; transform: translateY(-1px); }
        .btn-danger { background: #DC2626; color: white; }
        .btn-danger:hover { background: #B91C1C; transform: translateY(-1px); }
        .btn-warning { background: #F59E0B; color: white; }
        .btn-warning:hover { background: #D97706; transform: translateY(-1px); }
        .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text); }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .btn-back { background: var(--bg); border: 1px solid var(--border); color: var(--text); }
        .btn-back:hover { border-color: var(--primary); color: var(--primary); }
        .btn-sm { padding: 4px 10px; font-size: 12px; border-radius: 6px; gap: 4px; }
        
        .dropdown {
            position: relative;
            display: inline-block;
        }
        
        .dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            background: var(--card);
            min-width: 150px;
            box-shadow: var(--shadow-lg);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            z-index: 10;
            padding: 6px 0;
        }
        
        .dropdown-content.show { display: block; }
        .dropdown-content a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 16px;
            color: var(--text);
            text-decoration: none;
            font-size: 13px;
            transition: var(--transition);
        }
        .dropdown-content a:hover { background: rgba(47, 128, 237, 0.05); color: var(--primary); }
        .dropdown-content a i { width: 18px; color: var(--secondary-text); }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 28px;
            width: 100%;
        }
        
        .stat-card {
            background: var(--card);
            padding: 20px 24px;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            transition: var(--transition);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--primary);
            opacity: 0.3;
        }
        
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); }
        .stat-card .label { font-size: 13px; color: var(--secondary-text); font-weight: 500; display: flex; align-items: center; gap: 8px; }
        .stat-card .label i { color: var(--primary); opacity: 0.5; }
        .stat-card .value { font-size: 28px; font-weight: 700; margin-top: 6px; color: var(--text); }
        
        .filter-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
            width: 100%;
        }
        
        .filter-bar .search-input {
            flex: 1;
            min-width: 180px;
            padding: 10px 16px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            background: var(--bg);
            color: var(--text);
            transition: var(--transition);
        }
        
        .filter-bar .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(47, 128, 237, 0.1);
        }
        
        .filter-bar select,
        .filter-bar input[type="date"] {
            padding: 10px 14px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            background: var(--bg);
            color: var(--text);
            min-width: 130px;
            transition: var(--transition);
        }
        
        .filter-bar select:focus,
        .filter-bar input[type="date"]:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .filter-bar .filter-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .batch-bar {
            display: none;
            gap: 10px;
            padding: 12px 16px;
            background: var(--bg);
            border-radius: var(--radius-sm);
            margin-bottom: 16px;
            flex-wrap: wrap;
            align-items: center;
            border: 1px dashed var(--border);
        }
        
        .batch-bar.show { display: flex; }
        .batch-bar .selected-info { font-size: 13px; color: var(--secondary-text); }
        .batch-bar .selected-info strong { color: var(--text); }
        
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 16px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-left: 4px solid transparent;
            width: 100%;
        }
        
        .alert-success { background: #D1FAE5; color: #065F46; border-left-color: var(--accent); }
        .alert-error { background: #FEE2E2; color: #DC2626; border-left-color: #DC2626; }
        
        .table-container {
            background: var(--card);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            overflow: hidden;
            box-shadow: var(--shadow);
            width: 100%;
        }
        
        .table-header {
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .table-header h2 { font-size: 16px; font-weight: 600; }
        
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            padding: 0;
            width: 100%;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            min-width: 900px;
        }
        
        table thead { background: var(--bg); }
        table th {
            padding: 12px 16px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: var(--secondary-text);
            border-bottom: 2px solid var(--border);
            font-weight: 600;
            white-space: nowrap;
            cursor: pointer;
            user-select: none;
            transition: var(--transition);
        }
        
        table th:hover { color: var(--primary); }
        table th .sort-icon { margin-left: 4px; opacity: 0.5; }
        table th.sorted .sort-icon { opacity: 1; color: var(--primary); }
        table th:first-child { text-align: center; width: 40px; }
        table td { padding: 12px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        table td:first-child { text-align: center; }
        table td:last-child { text-align: center; }
        table tbody tr { transition: var(--transition); }
        table tbody tr:hover { background: rgba(47, 128, 237, 0.04); }
        table tbody tr:last-child td { border-bottom: none; }
        
        .checkbox-cell input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
            cursor: pointer;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            min-width: 70px;
        }
        
        .po-draft { background: #E5E7EB; color: #374151; }
        .po-pending { background: #FEF3C7; color: #92400E; }
        .po-approved { background: #DBEAFE; color: #1E40AF; }
        .po-rejected { background: #FEE2E2; color: #DC2626; }
        .po-shipped { background: #DBEAFE; color: #1E40AF; }
        .po-received { background: #D1FAE5; color: #065F46; }
        .po-completed { background: #D1FAE5; color: #065F46; }
        .po-cancelled { background: #FEE2E2; color: #DC2626; }
        
        .approval-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .approval-pending_review { background: #FEF3C7; color: #92400E; }
        .approval-approved { background: #D1FAE5; color: #065F46; }
        .approval-rejected { background: #FEE2E2; color: #DC2626; }
        .approval-revision_requested { background: #FEF3C7; color: #92400E; }
        
        .role-badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            background: rgba(47, 128, 237, 0.1);
            color: var(--primary);
            min-width: 60px;
            text-align: center;
        }
        
        .action-buttons { display: flex; gap: 4px; flex-wrap: wrap; justify-content: center; }
        
        .empty-state { text-align: center; padding: 40px; color: var(--secondary-text); }
        .empty-state i { font-size: 40px; display: block; margin-bottom: 10px; opacity: 0.3; }
        
        .modal-overlay {
            display: <?php echo ($viewPO || $action === 'create' || isset($error)) ? 'flex' : 'none'; ?>;
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
            -webkit-backdrop-filter: blur(4px);
        }
        
        .modal {
            background: var(--card);
            border-radius: var(--radius);
            padding: 30px;
            max-width: 700px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalIn 0.3s ease;
            box-shadow: var(--shadow-lg);
        }
        
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95) translateY(-20px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        
        .modal h3 { font-size: 20px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .modal .close-modal { margin-left: auto; background: none; border: none; font-size: 24px; color: var(--secondary-text); cursor: pointer; padding: 0 4px; transition: var(--transition); }
        .modal .close-modal:hover { color: var(--text); }
        
        .modal .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }
        .modal .detail-row:last-child { border-bottom: none; }
        .modal .detail-row .label { font-weight: 500; color: var(--secondary-text); }
        .modal .detail-row .value { font-weight: 500; }
        
        .modal .item-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }
        .modal .item-row:last-child { border-bottom: none; }
        
        #statusModal .modal { max-width: 500px; }
        
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; font-weight: 500; margin-bottom: 5px; color: var(--text); }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            background: var(--bg);
            color: var(--text);
            transition: var(--transition);
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--primary); }
        .form-group textarea { resize: vertical; min-height: 60px; }
        .form-actions { display: flex; gap: 10px; margin-top: 20px; }
        .form-actions .btn { flex: 1; justify-content: center; }
        
        .pagination-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 24px;
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .pagination-bar .info { font-size: 13px; color: var(--secondary-text); }
        .pagination-bar .info strong { color: var(--text); }
        
        .pagination-controls { display: flex; gap: 4px; align-items: center; flex-wrap: wrap; }
        .pagination-controls .page-btn {
            padding: 6px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg);
            color: var(--text);
            cursor: pointer;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            min-width: 36px;
            text-align: center;
        }
        .pagination-controls .page-btn:hover:not(.active) { background: rgba(47, 128, 237, 0.05); border-color: var(--primary); }
        .pagination-controls .page-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
        .pagination-controls .page-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .pagination-controls select { padding: 6px 10px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--bg); color: var(--text); font-family: 'Poppins', sans-serif; font-size: 13px; }
        
        .fullscreen-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 50;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 50%;
            width: 48px;
            height: 48px;
            font-size: 20px;
            color: var(--text);
            cursor: pointer;
            transition: var(--transition);
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .fullscreen-toggle:hover { background: var(--primary); color: white; border-color: var(--primary); transform: scale(1.05); }
        
        @media (max-width: 1024px) {
            .main-content { padding: 20px 24px 32px; width: calc(100% - 280px); }
            .top-bar { flex-direction: column; align-items: stretch; }
            .top-bar-actions { justify-content: center; }
            .top-bar-actions .btn { flex: 1; justify-content: center; min-width: 120px; }
        }
        
        @media (max-width: 768px) {
            .sidebar { 
                width: 0; 
                padding: 0; 
                overflow: hidden; 
                position: fixed; 
                left: -320px; 
                transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
                box-shadow: var(--shadow-lg); 
            }
            .sidebar.open { left: 0; width: 300px; padding: 24px 16px; }
            .sidebar-toggle-btn { display: block; }
            .sidebar-overlay.active { display: block; }
            .main-content { margin-left: 0; padding: 16px; width: 100%; padding-top: 16px; }
            .top-bar { padding: 16px; gap: 12px; }
            .page-title h1 { font-size: 18px; }
            .page-title p { font-size: 13px; margin-left: 0; }
            .page-title h1 i { font-size: 20px; }
            .top-bar-actions { width: 100%; flex-wrap: wrap; }
            .top-bar-actions .btn { flex: 1; min-width: 100px; justify-content: center; font-size: 13px; padding: 8px 14px; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
            .stat-card { padding: 16px; }
            .stat-card .value { font-size: 22px; }
            .filter-bar { flex-direction: column; }
            .filter-bar .search-input { width: 100%; }
            .filter-bar select, .filter-bar input[type="date"] { width: 100%; }
            .table-header { flex-direction: column; align-items: flex-start; gap: 8px; }
            .table-header h2 { font-size: 15px; }
            table { font-size: 13px; min-width: 500px; }
            table th, table td { padding: 10px 12px; }
            .pagination-bar { flex-direction: column; align-items: stretch; gap: 8px; }
            .pagination-controls { justify-content: center; flex-wrap: wrap; }
            .modal { padding: 20px; margin: 10px; max-width: 100%; }
            .modal h3 { font-size: 18px; }
            .form-actions { flex-direction: column; }
            .form-actions .btn { width: 100%; }
            .fullscreen-toggle { bottom: 16px; right: 16px; width: 44px; height: 44px; font-size: 18px; }
        }
        
        @media (max-width: 480px) {
            .main-content { padding: 12px; }
            .top-bar { padding: 12px; }
            .top-bar-actions { flex-direction: column; align-items: stretch; }
            .top-bar-actions .btn { min-width: unset; width: 100%; justify-content: center; font-size: 13px; padding: 10px 14px; }
            .stats-grid { grid-template-columns: 1fr; }
            .table-wrapper { margin: 0 -12px; }
            table th, table td { padding: 8px 10px; font-size: 12px; }
            .action-buttons { flex-direction: column; align-items: center; gap: 4px; }
            .action-buttons .btn-sm { width: 100%; justify-content: center; padding: 6px 12px; }
            .status-badge { min-width: 60px; font-size: 11px; padding: 2px 10px; }
            .role-badge { min-width: 50px; font-size: 10px; padding: 2px 10px; }
            .modal { padding: 16px; margin: 8px; }
            .modal h3 { font-size: 16px; }
            .fullscreen-toggle { bottom: 12px; right: 12px; width: 40px; height: 40px; font-size: 16px; }
            .pagination-controls .page-btn { padding: 4px 8px; font-size: 12px; min-width: 30px; }
        }
        
        @media print {
            .sidebar, .top-bar-actions, .btn, .no-print, .fullscreen-toggle, .filter-bar, .batch-bar { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 20px !important; width: 100% !important; }
            .table-container { box-shadow: none !important; border: 1px solid #ddd !important; }
            .stat-card { box-shadow: none !important; border: 1px solid #ddd !important; }
            body { background: white !important; color: black !important; }
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <button class="fullscreen-toggle no-print" id="fullscreenToggle" title="Toggle Fullscreen">
        <i class="fas fa-expand"></i>
    </button>
    
    <div class="admin-layout">
        <?php include 'partials/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="top-bar">
                <div class="page-title">
                    <div>
                        <h1>
                            <i class="fas fa-file-invoice"></i>
                            Purchase Orders
                        </h1>
                        <p>Create and manage purchase orders</p>
                    </div>
                </div>
                <div class="top-bar-actions">
                    <a href="purchase-orders.php?action=create" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create PO
                    </a>
                    
                    <div class="dropdown">
                        <button class="btn btn-outline" onclick="toggleExportDropdown()">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <div class="dropdown-content" id="exportDropdown">
                            <a href="purchase-orders.php?action=export&format=csv<?php echo '&status=' . $statusFilter . '&search=' . urlencode($search); ?>">
                                <i class="fas fa-file-csv"></i> Export CSV
                            </a>
                            <a href="purchase-orders.php?action=export&format=pdf<?php echo '&status=' . $statusFilter . '&search=' . urlencode($search); ?>">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </a>
                        </div>
                    </div>
                    
                    <a href="purchase-orders.php<?php echo $showArchived ? '' : '?archived=1'; ?>" class="btn btn-outline">
                        <i class="fas fa-archive"></i> <?php echo $showArchived ? 'Active' : 'Archived'; ?>
                    </a>
                    <a href="dashboard.php" class="btn btn-back">
                        <i class="fas fa-arrow-left"></i> Dashboard
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
            
            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="label"><i class="fas fa-file-invoice"></i> Total POs</div>
                    <div class="value"><?php echo number_format($poStats['total'] ?? 0); ?></div>
                </div>
                <div class="stat-card" style="border-color: #F59E0B;">
                    <div class="label"><i class="fas fa-clock" style="color: #F59E0B;"></i> Pending</div>
                    <div class="value" style="color: #F59E0B;"><?php echo number_format($poStats['pending'] ?? 0); ?></div>
                </div>
                <div class="stat-card" style="border-color: var(--accent);">
                    <div class="label"><i class="fas fa-check-circle" style="color: var(--accent);"></i> Received</div>
                    <div class="value" style="color: var(--accent);"><?php echo number_format($poStats['received'] ?? 0); ?></div>
                </div>
                <div class="stat-card" style="border-color: #DC2626;">
                    <div class="label"><i class="fas fa-times-circle" style="color: #DC2626;"></i> Cancelled</div>
                    <div class="value" style="color: #DC2626;"><?php echo number_format($poStats['cancelled'] ?? 0); ?></div>
                </div>
            </div>
            
            <!-- Search & Filter Bar -->
            <div class="filter-bar">
                <form method="GET" style="display: flex; gap: 12px; flex-wrap: wrap; width: 100%; align-items: center;">
                    <input type="hidden" name="archived" value="<?php echo $showArchived; ?>">
                    
                    <input type="text" name="search" class="search-input" 
                           placeholder="Search by PO number or supplier..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    
                    <input type="text" name="supplier_id" placeholder="Supplier name..."
                           value="<?php echo htmlspecialchars($supplierFilter); ?>">
                    
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="draft" <?php echo $statusFilter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="shipped" <?php echo $statusFilter === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                        <option value="received" <?php echo $statusFilter === 'received' ? 'selected' : ''; ?>>Received</option>
                        <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                    
                    <select name="approval">
                        <option value="">All Approvals</option>
                        <option value="pending_review" <?php echo $approvalFilter === 'pending_review' ? 'selected' : ''; ?>>Pending Review</option>
                        <option value="approved" <?php echo $approvalFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $approvalFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="revision_requested" <?php echo $approvalFilter === 'revision_requested' ? 'selected' : ''; ?>>Revision Requested</option>
                    </select>
                    
                    <input type="date" name="date_from" placeholder="From" value="<?php echo $dateFrom; ?>">
                    <input type="date" name="date_to" placeholder="To" value="<?php echo $dateTo; ?>">
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <?php if (!empty($search) || !empty($statusFilter) || !empty($approvalFilter) || !empty($supplierFilter) || !empty($dateFrom) || !empty($dateTo)): ?>
                        <a href="purchase-orders.php<?php echo $showArchived ? '?archived=1' : ''; ?>" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <!-- Batch Operations Bar -->
            <div class="batch-bar" id="batchBar">
                <span class="selected-info">
                    <strong id="selectedCount">0</strong> POs selected
                </span>
                <button class="btn btn-success btn-sm" onclick="bulkApprove()">
                    <i class="fas fa-check"></i> Approve Selected
                </button>
                <button class="btn btn-outline btn-sm" onclick="clearSelection()">
                    <i class="fas fa-times"></i> Clear Selection
                </button>
            </div>
            
            <div class="table-container">
                <div class="table-header">
                    <h2><?php echo $showArchived ? 'Archived POs' : 'Purchase Orders'; ?></h2>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <span class="role-badge"><?php echo count($pos); ?> orders</span>
                        <span class="role-badge">Page <?php echo $pageNum; ?> of <?php echo $totalPages; ?></span>
                    </div>
                </div>
                <div class="table-wrapper">
                    <form id="bulkForm" method="POST">
                        <input type="hidden" name="action" id="bulkAction" value="bulk_approve">
                        <table>
                            <thead>
                                <tr>
                                    <th>
                                        <input type="checkbox" id="selectAll" onclick="toggleAll(this);">
                                    </th>
                                    <th onclick="sortTable('po_number')" class="<?php echo $sortField === 'po_number' ? 'sorted' : ''; ?>">
                                        PO Number <span class="sort-icon"><?php echo $sortField === 'po_number' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('company_name')" class="<?php echo $sortField === 'company_name' ? 'sorted' : ''; ?>">
                                        Supplier <span class="sort-icon"><?php echo $sortField === 'company_name' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('order_date')" class="<?php echo $sortField === 'order_date' ? 'sorted' : ''; ?>">
                                        Date <span class="sort-icon"><?php echo $sortField === 'order_date' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('total_amount')" class="<?php echo $sortField === 'total_amount' ? 'sorted' : ''; ?>">
                                        Total <span class="sort-icon"><?php echo $sortField === 'total_amount' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('status')" class="<?php echo $sortField === 'status' ? 'sorted' : ''; ?>">
                                        Status <span class="sort-icon"><?php echo $sortField === 'status' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('approval_status')" class="<?php echo $sortField === 'approval_status' ? 'sorted' : ''; ?>">
                                        Approval <span class="sort-icon"><?php echo $sortField === 'approval_status' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($paginatedPos)): ?>
                                    <?php foreach ($paginatedPos as $po): ?>
                                    <tr>
                                        <td class="checkbox-cell">
                                            <input type="checkbox" name="ids[]" value="<?php echo $po['id']; ?>" 
                                                   class="row-checkbox" onchange="updateSelection();">
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($po['company_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($po['order_date'])); ?></td>
                                        <td class="po-total">₱<?php echo number_format($po['total_amount'], 2); ?></td>
                                        <td>
                                            <span class="status-badge po-<?php echo $po['status']; ?>">
                                                <?php echo ucfirst($po['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="approval-badge approval-<?php echo $po['approval_status'] ?? 'pending_review'; ?>">
                                                <?php if ($po['approval_status'] === 'approved'): ?>
                                                    <i class="fas fa-check-circle"></i>
                                                <?php elseif ($po['approval_status'] === 'rejected'): ?>
                                                    <i class="fas fa-times-circle"></i>
                                                <?php elseif ($po['approval_status'] === 'revision_requested'): ?>
                                                    <i class="fas fa-edit"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-clock"></i>
                                                <?php endif; ?>
                                                <?php echo ucfirst(str_replace('_', ' ', $po['approval_status'] ?? 'pending_review')); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="purchase-orders.php?action=view&id=<?php echo $po['id']; ?>" 
                                                   class="btn btn-primary btn-sm" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if (!$showArchived && $po['status'] !== 'received' && $po['status'] !== 'cancelled' && $po['status'] !== 'completed'): ?>
                                                <button onclick="openStatusModal(<?php echo $po['id']; ?>, '<?php echo htmlspecialchars($po['po_number']); ?>')" 
                                                        class="btn btn-success btn-sm" title="Update Status">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php endif; ?>
                                                <?php if (!$showArchived): ?>
                                                <a href="purchase-orders.php?action=archive&id=<?php echo $po['id']; ?>" 
                                                   class="btn btn-warning btn-sm" 
                                                   onclick="return confirm('Archive this PO?');" title="Archive">
                                                    <i class="fas fa-archive"></i>
                                                </a>
                                                <?php else: ?>
                                                <a href="purchase-orders.php?action=restore&id=<?php echo $po['id']; ?>" 
                                                   class="btn btn-success btn-sm"
                                                   onclick="return confirm('Restore this PO?');" title="Restore">
                                                    <i class="fas fa-undo"></i>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="empty-state">
                                            <i class="fas fa-file-invoice"></i>
                                            <p>No purchase orders found</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </form>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalItems > 0): ?>
                <div class="pagination-bar">
                    <div class="info">
                        Showing <strong><?php echo $totalItems > 0 ? $offset + 1 : 0; ?></strong> 
                        to <strong><?php echo min($offset + $itemsPerPage, $totalItems); ?></strong> 
                        of <strong><?php echo $totalItems; ?></strong> orders
                    </div>
                    <div class="pagination-controls">
                        <select onchange="changePerPage(this.value);">
                            <option value="5" <?php echo $itemsPerPage == 5 ? 'selected' : ''; ?>>5</option>
                            <option value="10" <?php echo $itemsPerPage == 10 ? 'selected' : ''; ?>>10</option>
                            <option value="25" <?php echo $itemsPerPage == 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $itemsPerPage == 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $itemsPerPage == 100 ? 'selected' : ''; ?>>100</option>
                        </select>
                        <span style="margin: 0 8px; color: var(--secondary-text);">per page</span>
                        
                        <button class="page-btn" onclick="goToPage(<?php echo $pageNum - 1; ?>)" 
                                <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>>
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        
                        <?php for ($i = max(1, $pageNum - 2); $i <= min($totalPages, $pageNum + 2); $i++): ?>
                            <button class="page-btn <?php echo $i == $pageNum ? 'active' : ''; ?>" 
                                    onclick="goToPage(<?php echo $i; ?>);">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <button class="page-btn" onclick="goToPage(<?php echo $pageNum + 1; ?>)" 
                                <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>>
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- View PO Modal -->
    <?php if ($viewPO): ?>
    <div class="modal-overlay" style="display: flex;">
        <div class="modal">
            <h3>
                <i class="fas fa-file-invoice" style="color: var(--primary);"></i> 
                PO #<?php echo htmlspecialchars($viewPO['po_number']); ?>
                <button type="button" class="close-modal" onclick="window.location.href='purchase-orders.php'">&times;</button>
            </h3>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 20px 0; padding: 15px; background: var(--bg); border-radius: 10px;">
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Supplier</div>
                    <div><strong><?php echo htmlspecialchars($viewPO['company_name']); ?></strong></div>
                    <div style="font-size: 13px; color: var(--secondary-text);">
                        <?php echo htmlspecialchars($viewPO['contact_person'] ?? ''); ?>
                    </div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Order Date</div>
                    <div><?php echo date('M d, Y', strtotime($viewPO['order_date'])); ?></div>
                    <div style="font-size: 12px; color: var(--secondary-text);">
                        Expected: <?php echo date('M d, Y', strtotime($viewPO['expected_delivery'])); ?>
                    </div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Status</div>
                    <span class="status-badge po-<?php echo $viewPO['status']; ?>">
                        <?php echo ucfirst($viewPO['status']); ?>
                    </span>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Approval</div>
                    <span class="approval-badge approval-<?php echo $viewPO['approval_status'] ?? 'pending_review'; ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $viewPO['approval_status'] ?? 'pending_review')); ?>
                    </span>
                </div>
            </div>
            
            <h4 style="margin-bottom: 10px;">Items</h4>
            <?php foreach ($viewPO['items'] as $item): ?>
            <div class="item-row">
                <div>
                    <div><strong><?php echo htmlspecialchars($item['product_name']); ?></strong></div>
                    <div style="font-size: 13px; color: var(--secondary-text);">
                        SKU: <?php echo htmlspecialchars($item['sku']); ?>
                    </div>
                </div>
                <div style="text-align: right;">
                    <div><?php echo number_format($item['quantity']); ?> x ₱<?php echo number_format($item['unit_price'], 2); ?></div>
                    <div style="font-weight: 600;">₱<?php echo number_format($item['total_price'], 2); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <div style="margin-top: 15px; padding-top: 15px; border-top: 2px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Created By</div>
                    <div><?php echo htmlspecialchars($viewPO['created_by_name'] ?? 'N/A'); ?></div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 12px; color: var(--secondary-text);">Total Amount</div>
                    <div style="font-size: 24px; font-weight: 700;">₱<?php echo number_format($viewPO['total_amount'], 2); ?></div>
                </div>
            </div>
            
            <?php if ($viewPO['shipping_address']): ?>
            <div style="margin-top: 15px; padding: 10px; background: var(--bg); border-radius: 8px;">
                <div style="font-size: 12px; color: var(--secondary-text);">Shipping Address</div>
                <div><?php echo nl2br(htmlspecialchars($viewPO['shipping_address'])); ?></div>
            </div>
            <?php endif; ?>
            
            <div class="form-actions" style="margin-top: 20px;">
                <a href="purchase-orders.php" class="btn btn-outline">Close</a>
                <?php if ($viewPO['status'] !== 'received' && $viewPO['status'] !== 'cancelled'): ?>
                <button onclick="openStatusModal(<?php echo $viewPO['id']; ?>, '<?php echo htmlspecialchars($viewPO['po_number']); ?>')" 
                        class="btn btn-primary">
                    <i class="fas fa-edit"></i> Update Status
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Create PO Modal -->
    <?php if ($action === 'create'): ?>
    <div class="modal-overlay" id="createModal" style="display: flex;">
        <div class="modal" style="max-width: 700px; max-height: 90vh; overflow-y: auto;">
            <h3>
                <i class="fas fa-plus" style="color: var(--primary);"></i> Create Purchase Order
                <button type="button" class="close-modal" onclick="closeModal()">&times;</button>
            </h3>
            <form method="POST" action="purchase-orders.php?action=create" id="poForm" onsubmit="return validatePoForm()">
                <div class="form-group">
                    <label>Supplier *</label>
                    <input type="text" id="supplierSearch" list="supplierOptions" autocomplete="off"
                           placeholder="search supplier" oninput="resolveSupplier(this)" required>
                    <input type="hidden" name="supplier_id" id="supplierIdInput">
                    <datalist id="supplierOptions">
                        <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo htmlspecialchars($supplier['company_name']); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Order Date *</label>
                        <input type="date" name="order_date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label>Expected Delivery *</label>
                        <input type="date" name="expected_delivery" required value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Shipping Address</label>
                    <textarea name="shipping_address" rows="2"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Terms</label>
                    <input type="text" name="terms" placeholder="e.g., Net 30">
                </div>
                
                <h4 style="margin: 15px 0 10px;">Items</h4>
                <div id="itemsContainer">
                    <div class="item-row" style="display: grid; grid-template-columns: 2fr 1fr 1fr 0.5fr; gap: 10px; background: var(--bg); padding: 10px; border-radius: 8px; margin-bottom: 10px;">
                        <div>
                            <input type="text" class="product-search" list="productOptions" autocomplete="off"
                                   placeholder="search product" oninput="resolveProduct(this)" required
                                   style="width: 100%; padding: 10px 14px; border: 2px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text); box-sizing: border-box;">
                            <input type="hidden" name="items[0][product_id]" class="product-id-field">
                        </div>
                        <input type="number" name="items[0][quantity]" placeholder="Qty" required style="padding: 10px 14px; border: 2px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text);">
                        <input type="number" name="items[0][unit_price]" placeholder="Price" required step="0.01" style="padding: 10px 14px; border: 2px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text);">
                        <button type="button" onclick="removeItem(this)" class="btn btn-danger btn-sm" style="padding: 8px 12px;">×</button>
                    </div>
                </div>
                <datalist id="productOptions">
                    <?php foreach ($products as $product): ?>
                    <option value="<?php echo htmlspecialchars($product['sku'] . ' - ' . $product['product_name']); ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                
                <button type="button" onclick="addItem()" class="btn btn-outline" style="margin-top: 10px;">
                    <i class="fas fa-plus"></i> Add Item
                </button>
                
                <div class="form-group" style="margin-top: 15px;">
                    <label>Notes</label>
                    <textarea name="notes" rows="2"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" onclick="closeModal()" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Create PO
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Status Update Modal -->
    <div id="statusModal" class="modal-overlay" style="display: none;">
        <div class="modal">
            <h3>
                <i class="fas fa-edit" style="color: var(--primary);"></i> Update PO Status
                <button type="button" class="close-modal" onclick="closeStatusModal()">&times;</button>
            </h3>
            <form method="POST" action="purchase-orders.php?action=update_status">
                <input type="hidden" name="id" id="statusPoId">
                <div class="form-group">
                    <label>PO Number</label>
                    <p id="statusPoNumber" style="font-weight: 500;"></p>
                </div>
                <div class="form-group">
                    <label>New Status</label>
                    <select name="status" required>
                        <option value="draft">Draft</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="shipped">Shipped</option>
                        <option value="received">Received</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" rows="2"></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" onclick="closeStatusModal()" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // ============================================
        // MODAL FUNCTIONS
        // ============================================
        function openCreateModal() {
            var modal = document.getElementById('createModal');
            if (modal) {
                modal.style.display = 'flex';
            } else {
                window.location.href = 'purchase-orders.php?action=create';
            }
        }
        
        function closeModal() {
            var modal = document.getElementById('createModal');
            if (modal) {
                modal.style.display = 'none';
                var url = new URL(window.location.href);
                url.searchParams.delete('action');
                window.history.replaceState({}, '', url.toString());
            }
        }
        
        // Close modal on background click
        document.addEventListener('DOMContentLoaded', function() {
            var modal = document.getElementById('createModal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeModal();
                    }
                });
            }
            
            // Escape key to close
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    var modal = document.getElementById('createModal');
                    if (modal && modal.style.display === 'flex') {
                        closeModal();
                    }
                }
            });
        });
        
        // ============================================
        // DROPDOWN TOGGLES
        // ============================================
        function toggleExportDropdown() {
            var dropdown = document.getElementById('exportDropdown');
            dropdown.classList.toggle('show');
        }
        
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.dropdown')) {
                document.querySelectorAll('.dropdown-content').forEach(function(el) {
                    el.classList.remove('show');
                });
            }
        });
        
        // ============================================
        // ITEM MANAGEMENT
        // ============================================
        var itemCount = 1;
        
        // ============================================
        // SUPPLIER / PRODUCT NAME -> ID RESOLUTION
        // ============================================
        var suppliersMap = {};
        <?php foreach ($suppliers as $supplier): ?>
        suppliersMap[<?php echo json_encode($supplier['company_name']); ?>] = <?php echo json_encode((string)$supplier['id']); ?>;
        <?php endforeach; ?>
        
        var productsMap = {};
        <?php foreach ($products as $product): ?>
        productsMap[<?php echo json_encode($product['sku'] . ' - ' . $product['product_name']); ?>] = <?php echo json_encode((string)$product['id']); ?>;
        <?php endforeach; ?>
        
        function resolveSupplier(el) {
            var hidden = document.getElementById('supplierIdInput');
            hidden.value = suppliersMap[el.value] || '';
        }
        
        function resolveProduct(el) {
            var row = el.closest('.item-row');
            var hidden = row.querySelector('.product-id-field');
            hidden.value = productsMap[el.value] || '';
        }
        
        function validatePoForm() {
            var supplierId = document.getElementById('supplierIdInput').value;
            if (!supplierId) {
                alert('Please select a valid supplier from the list.');
                return false;
            }
            var rows = document.querySelectorAll('#itemsContainer .item-row');
            for (var i = 0; i < rows.length; i++) {
                var idField = rows[i].querySelector('.product-id-field');
                if (!idField || !idField.value) {
                    alert('Please select a valid product from the list for every item.');
                    return false;
                }
            }
            return true;
        }
        
        function addItem() {
            var container = document.getElementById('itemsContainer');
            var template = container.querySelector('.item-row').cloneNode(true);
            
            template.querySelectorAll('select, input').forEach(function(el) {
                var name = el.getAttribute('name');
                if (name) {
                    el.setAttribute('name', name.replace(/\[0\]/, '[' + itemCount + ']'));
                }
                if (el.tagName === 'SELECT' || el.type === 'number' || el.type === 'text' || el.type === 'hidden') {
                    el.value = '';
                }
            });
            
            container.appendChild(template);
            itemCount++;
        }
        
        function removeItem(btn) {
            var container = document.getElementById('itemsContainer');
            if (container.children.length > 1) {
                btn.closest('.item-row').remove();
            }
        }
        
        // ============================================
        // STATUS MODAL
        // ============================================
        function openStatusModal(id, number) {
            document.getElementById('statusPoId').value = id;
            document.getElementById('statusPoNumber').textContent = number;
            document.getElementById('statusModal').style.display = 'flex';
        }
        
        function closeStatusModal() {
            document.getElementById('statusModal').style.display = 'none';
        }
        
        // ============================================
        // BATCH OPERATIONS
        // ============================================
        function toggleAll(master) {
            var checkboxes = document.querySelectorAll('.row-checkbox');
            checkboxes.forEach(function(cb) {
                cb.checked = master.checked;
            });
            updateSelection();
        }
        
        function updateSelection() {
            var checkboxes = document.querySelectorAll('.row-checkbox:checked');
            var count = checkboxes.length;
            document.getElementById('selectedCount').textContent = count;
            var bar = document.getElementById('batchBar');
            if (count > 0) {
                bar.classList.add('show');
            } else {
                bar.classList.remove('show');
            }
        }
        
        function clearSelection() {
            document.querySelectorAll('.row-checkbox').forEach(function(cb) {
                cb.checked = false;
            });
            document.getElementById('selectAll').checked = false;
            updateSelection();
        }
        
        function bulkApprove() {
            var checkboxes = document.querySelectorAll('.row-checkbox:checked');
            var ids = [];
            checkboxes.forEach(function(cb) {
                ids.push(cb.value);
            });
            
            if (ids.length === 0) {
                alert('Please select at least one PO.');
                return;
            }
            
            if (confirm('Approve ' + ids.length + ' selected purchase orders?')) {
                var form = document.getElementById('bulkForm');
                document.getElementById('bulkAction').value = 'bulk_approve';
                document.querySelectorAll('#bulkForm input[name="ids[]"]').forEach(function(el) {
                    if (!el.classList.contains('row-checkbox')) {
                        el.remove();
                    }
                });
                ids.forEach(function(id) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'ids[]';
                    input.value = id;
                    form.appendChild(input);
                });
                form.submit();
            }
        }
        
        // ============================================
        // SORTING
        // ============================================
        function sortTable(field) {
            var currentSort = '<?php echo $sortField; ?>';
            var currentOrder = '<?php echo $sortOrder; ?>';
            var newOrder = (currentSort === field && currentOrder === 'ASC') ? 'DESC' : 'ASC';
            
            var url = new URL(window.location.href);
            url.searchParams.set('sort', field);
            url.searchParams.set('order', newOrder);
            window.location.href = url.toString();
        }
        
        // ============================================
        // PAGINATION
        // ============================================
        function goToPage(page) {
            var totalPages = <?php echo max(1, $totalPages); ?>;
            if (page < 1 || page > totalPages) return;
            var url = new URL(window.location.href);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }
        
        function changePerPage(value) {
            var url = new URL(window.location.href);
            url.searchParams.set('per_page', value);
            url.searchParams.set('page', 1);
            window.location.href = url.toString();
        }
        
        // ============================================
        // FULLSCREEN TOGGLE
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            var fullscreenBtn = document.getElementById('fullscreenToggle');
            var icon = fullscreenBtn.querySelector('i');
            
            fullscreenBtn.addEventListener('click', function() {
                if (!document.fullscreenElement) {
                    document.documentElement.requestFullscreen().catch(function(err) {
                        console.log('Fullscreen not supported');
                    });
                    icon.className = 'fas fa-compress';
                } else {
                    if (document.exitFullscreen) {
                        document.exitFullscreen();
                        icon.className = 'fas fa-expand';
                    }
                }
            });
            
            document.addEventListener('fullscreenchange', function() {
                if (document.fullscreenElement) {
                    icon.className = 'fas fa-compress';
                } else {
                    icon.className = 'fas fa-expand';
                }
            });
        });
        
        // ============================================
        // SIDEBAR TOGGLE (Mobile)
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            var sidebar = document.querySelector('.sidebar');
            var overlay = document.getElementById('sidebarOverlay');
            
            var brand = document.querySelector('.sidebar-brand');
            if (brand) {
                var toggleBtn = document.createElement('button');
                toggleBtn.className = 'sidebar-toggle-btn';
                toggleBtn.innerHTML = '<i class="fas fa-bars"></i>';
                toggleBtn.setAttribute('aria-label', 'Toggle Sidebar');
                toggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('open');
                    overlay.classList.toggle('active');
                });
                brand.appendChild(toggleBtn);
            }
            
            if (overlay) {
                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('active');
                });
            }
            
            window.addEventListener('resize', function() {
                if (window.innerWidth > 768 && sidebar.classList.contains('open')) {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('active');
                }
            });
        });
        
        // ============================================
        // MODAL CLOSE
        // ============================================
        document.querySelectorAll('.modal-overlay').forEach(function(modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.style.display = 'none';
                    if (this.id !== 'statusModal') {
                        window.location.href = 'purchase-orders.php';
                    }
                }
            });
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay').forEach(function(modal) {
                    if (modal.style.display === 'flex') {
                        modal.style.display = 'none';
                        if (modal.id !== 'statusModal') {
                            window.location.href = 'purchase-orders.php';
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>