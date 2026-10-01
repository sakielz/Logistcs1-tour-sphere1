<?php
// admin/requisitions.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth(['admin','procurement_officer','employer']);

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
// GET USERS FOR DROPDOWN
// ============================================
try {
    $stmt = $pdo->query("SELECT id, full_name, username FROM users WHERE is_active = 1 AND is_archived = 0 ORDER BY full_name");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
}

// ============================================
// GET PRODUCTS FOR DROPDOWN
// ============================================
try {
    $stmt = $pdo->query("SELECT id, sku, product_name, unit_price, current_stock FROM products WHERE status = 'active' AND is_archived = 0 ORDER BY product_name");
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
        $query = "SELECT pr.*, u.full_name as requester_name, u2.full_name as creator_name 
                  FROM purchase_requisitions pr 
                  LEFT JOIN users u ON pr.requested_by = u.id 
                  LEFT JOIN users u2 ON pr.created_by = u2.id 
                  WHERE pr.is_archived = 0";
        $params = [];
        
        if (!empty($statusFilter)) {
            $query .= " AND pr.status = ?";
            $params[] = $statusFilter;
        }
        if (!empty($search)) {
            $query .= " AND (pr.pr_number LIKE ? OR pr.title LIKE ? OR u.full_name LIKE ?)";
            $searchParam = "%$search%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }
        
        $query .= " ORDER BY pr.created_at DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="requisitions_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['PR Number', 'Title', 'Requester', 'Department', 'Est. Total', 'Priority', 'Status', 'Created']);
            foreach ($data as $row) {
                fputcsv($output, [
                    $row['pr_number'],
                    $row['title'],
                    $row['requester_name'],
                    $row['department'],
                    $row['estimated_total'],
                    $row['priority'],
                    $row['status'],
                    $row['created_at']
                ]);
            }
            fclose($output);
            exit();
        } elseif ($format === 'pdf') {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="requisitions_' . date('Y-m-d') . '.pdf"');
            
            echo '<html><head><style>
                body { font-family: Arial, sans-serif; font-size: 12px; }
                table { width: 100%; border-collapse: collapse; }
                th { background: #2F80ED; color: white; padding: 8px; text-align: left; }
                td { padding: 8px; border-bottom: 1px solid #ddd; }
                h1 { color: #1F2937; }
            </style></head><body>';
            echo '<h1>Purchase Requisitions</h1>';
            echo '<p>Generated: ' . date('Y-m-d H:i:s') . '</p>';
            echo '<table>';
            echo '<tr><th>PR Number</th><th>Title</th><th>Requester</th><th>Department</th><th>Est. Total</th><th>Priority</th><th>Status</th></tr>';
            foreach ($data as $row) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($row['pr_number']) . '</td>';
                echo '<td>' . htmlspecialchars($row['title']) . '</td>';
                echo '<td>' . htmlspecialchars($row['requester_name']) . '</td>';
                echo '<td>' . htmlspecialchars($row['department']) . '</td>';
                echo '<td>' . number_format($row['estimated_total'], 2) . '</td>';
                echo '<td>' . ucfirst($row['priority']) . '</td>';
                echo '<td>' . ucfirst(str_replace('_', ' ', $row['status'])) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            echo '</body></html>';
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Export failed: " . $e->getMessage();
        header('Location: requisitions.php');
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
            $pdo->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE purchase_requisitions SET status = 'approved' WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            
            // Convert to PO for each approved requisition
            foreach ($ids as $id) {
                $stmt = $pdo->prepare("SELECT * FROM purchase_requisitions WHERE id = ?");
                $stmt->execute([$id]);
                $pr = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($pr) {
                    $po_number = 'PO-' . date('Ymd') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
                    
                    $stmt2 = $pdo->prepare("INSERT INTO purchase_orders (po_number, order_date, expected_delivery, status, approval_status, created_by) 
                                            VALUES (?, datetime('now'), datetime('now', '+14 days'), 'approved', 'approved', ?)");
                    $stmt2->execute([$po_number, $_SESSION['user_id']]);
                    $po_id = $pdo->lastInsertId();
                    
                    // Copy items
                    $stmt2 = $pdo->prepare("SELECT * FROM requisition_items WHERE requisition_id = ?");
                    $stmt2->execute([$id]);
                    $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($items as $item) {
                        $stmt3 = $pdo->prepare("INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_price, total_price) 
                                                VALUES (?, ?, ?, ?, ?)");
                        $total_price = $item['quantity'] * $item['unit_price'];
                        $stmt3->execute([$po_id, $item['product_id'], $item['quantity'], $item['unit_price'], $total_price]);
                    }
                    
                    // Update PO total
                    $stmt3 = $pdo->prepare("UPDATE purchase_orders SET total_amount = (SELECT SUM(total_price) FROM purchase_order_items WHERE po_id = ?) WHERE id = ?");
                    $stmt3->execute([$po_id, $po_id]);
                    
                    // Update requisition to converted
                    $stmt = $pdo->prepare("UPDATE purchase_requisitions SET status = 'converted_to_po' WHERE id = ?");
                    $stmt->execute([$id]);
                }
            }
            
            $pdo->commit();
            logAudit($_SESSION['user_id'], 'bulk_approve_requisition', 'procurement', "Bulk approved " . count($ids) . " requisitions");
            $_SESSION['success'] = "Successfully approved and converted " . count($ids) . " requisitions to POs!";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Bulk approve failed: " . $e->getMessage();
        }
    }
    header('Location: requisitions.php');
    exit();
}

// ============================================
// CREATE REQUISITION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $requested_by = isset($_POST['requested_by']) ? (int)$_POST['requested_by'] : 0;
    $department = isset($_POST['department']) ? trim($_POST['department']) : '';
    $required_date = isset($_POST['required_date']) ? $_POST['required_date'] : '';
    $priority = isset($_POST['priority']) ? $_POST['priority'] : 'medium';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    
    $errors = [];
    if (empty($title)) $errors[] = 'Title is required';
    if (empty($requested_by)) $errors[] = 'Requester is required';
    
    // Generate PR number
    $pr_number = 'PR-' . date('Ymd') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("INSERT INTO purchase_requisitions (pr_number, title, description, requested_by, department, required_date, priority, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending_review', ?)");
            $stmt->execute([$pr_number, $title, $description, $requested_by, $department, $required_date, $priority, $_SESSION['user_id']]);
            $pr_id = $pdo->lastInsertId();
            
            // Add items
            $items = isset($_POST['items']) ? $_POST['items'] : [];
            $estimated_total = 0;
            
            foreach ($items as $item) {
                if (!empty($item['product_id']) && !empty($item['quantity']) && !empty($item['unit_price'])) {
                    $product_id = $item['product_id'];
                    $quantity = (int)$item['quantity'];
                    $unit_price = (float)$item['unit_price'];
                    $total = $quantity * $unit_price;
                    $estimated_total += $total;
                    $item_notes = isset($item['notes']) ? trim($item['notes']) : '';
                    
                    $stmt = $pdo->prepare("INSERT INTO requisition_items (requisition_id, product_id, quantity, unit_price, notes) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$pr_id, $product_id, $quantity, $unit_price, $item_notes]);
                }
            }
            
            // Update estimated total
            $stmt = $pdo->prepare("UPDATE purchase_requisitions SET estimated_total = ? WHERE id = ?");
            $stmt->execute([$estimated_total, $pr_id]);
            
            $pdo->commit();
            
            logAudit($_SESSION['user_id'], 'create_requisition', 'procurement', "Created requisition: $pr_number");
            $_SESSION['success'] = "Requisition $pr_number created successfully!";
            header('Location: requisitions.php');
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Error creating requisition: " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// ============================================
// UPDATE REQUISITION STATUS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_status') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $status = isset($_POST['status']) ? $_POST['status'] : '';
    $review_notes = isset($_POST['review_notes']) ? trim($_POST['review_notes']) : '';
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("UPDATE purchase_requisitions SET status = ?, review_notes = ? WHERE id = ?");
        $stmt->execute([$status, $review_notes, $id]);
        
        if ($status === 'approved') {
            // Convert to PO
            $stmt = $pdo->prepare("SELECT * FROM purchase_requisitions WHERE id = ?");
            $stmt->execute([$id]);
            $pr = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($pr) {
                $po_number = 'PO-' . date('Ymd') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
                
                $stmt2 = $pdo->prepare("INSERT INTO purchase_orders (po_number, order_date, expected_delivery, status, approval_status, created_by) 
                                        VALUES (?, datetime('now'), datetime('now', '+14 days'), 'approved', 'approved', ?)");
                $stmt2->execute([$po_number, $_SESSION['user_id']]);
                $po_id = $pdo->lastInsertId();
                
                // Copy items
                $stmt2 = $pdo->prepare("SELECT * FROM requisition_items WHERE requisition_id = ?");
                $stmt2->execute([$id]);
                $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($items as $item) {
                    $stmt3 = $pdo->prepare("INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_price, total_price) 
                                            VALUES (?, ?, ?, ?, ?)");
                    $total_price = $item['quantity'] * $item['unit_price'];
                    $stmt3->execute([$po_id, $item['product_id'], $item['quantity'], $item['unit_price'], $total_price]);
                }
                
                // Update PO total
                $stmt3 = $pdo->prepare("UPDATE purchase_orders SET total_amount = (SELECT SUM(total_price) FROM purchase_order_items WHERE po_id = ?) WHERE id = ?");
                $stmt3->execute([$po_id, $po_id]);
                
                // Update requisition to converted
                $stmt = $pdo->prepare("UPDATE purchase_requisitions SET status = 'converted_to_po' WHERE id = ?");
                $stmt->execute([$id]);
            }
        }
        
        $pdo->commit();
        
        logAudit($_SESSION['user_id'], 'update_requisition_status', 'procurement', "Updated requisition ID $id to status: $status");
        $_SESSION['success'] = "Requisition status updated successfully!";
        header('Location: requisitions.php');
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Error updating requisition: " . $e->getMessage();
    }
}

// ============================================
// ARCHIVE REQUISITION
// ============================================
if ($action === 'archive' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    archiveRecord('purchase_requisitions', $id);
    logAudit($_SESSION['user_id'], 'archive_requisition', 'procurement', "Archived requisition ID: $id");
    $_SESSION['success'] = "Requisition archived successfully!";
    header('Location: requisitions.php');
    exit();
}

// ============================================
// RESTORE REQUISITION
// ============================================
if ($action === 'restore' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    restoreRecord('purchase_requisitions', $id);
    logAudit($_SESSION['user_id'], 'restore_requisition', 'procurement', "Restored requisition ID: $id");
    $_SESSION['success'] = "Requisition restored successfully!";
    header('Location: requisitions.php?archived=1');
    exit();
}

// ============================================
// VIEW REQUISITION
// ============================================
$viewRequisition = null;
if ($action === 'view' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("SELECT pr.*, u.full_name as requester_name, u2.full_name as creator_name 
                               FROM purchase_requisitions pr 
                               LEFT JOIN users u ON pr.requested_by = u.id 
                               LEFT JOIN users u2 ON pr.created_by = u2.id 
                               WHERE pr.id = ?");
        $stmt->execute([$_GET['id']]);
        $viewRequisition = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($viewRequisition) {
            $stmt = $pdo->prepare("SELECT ri.*, p.sku, p.product_name, p.unit_measure 
                                   FROM requisition_items ri 
                                   JOIN products p ON ri.product_id = p.id 
                                   WHERE ri.requisition_id = ?");
            $stmt->execute([$viewRequisition['id']]);
            $viewRequisition['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error fetching requisition: " . $e->getMessage();
        header('Location: requisitions.php');
        exit();
    }
}

// ============================================
// CONVERT TO PO
// ============================================
if ($action === 'convert_to_po' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT * FROM purchase_requisitions WHERE id = ? AND status = 'approved'");
        $stmt->execute([$id]);
        $pr = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($pr) {
            $po_number = 'PO-' . date('Ymd') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
            
            $stmt2 = $pdo->prepare("INSERT INTO purchase_orders (po_number, order_date, expected_delivery, status, approval_status, created_by) 
                                    VALUES (?, datetime('now'), datetime('now', '+14 days'), 'approved', 'approved', ?)");
            $stmt2->execute([$po_number, $_SESSION['user_id']]);
            $po_id = $pdo->lastInsertId();
            
            // Copy items
            $stmt2 = $pdo->prepare("SELECT * FROM requisition_items WHERE requisition_id = ?");
            $stmt2->execute([$id]);
            $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($items as $item) {
                $stmt3 = $pdo->prepare("INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_price, total_price) 
                                        VALUES (?, ?, ?, ?, ?)");
                $total_price = $item['quantity'] * $item['unit_price'];
                $stmt3->execute([$po_id, $item['product_id'], $item['quantity'], $item['unit_price'], $total_price]);
            }
            
            // Update PO total
            $stmt3 = $pdo->prepare("UPDATE purchase_orders SET total_amount = (SELECT SUM(total_price) FROM purchase_order_items WHERE po_id = ?) WHERE id = ?");
            $stmt3->execute([$po_id, $po_id]);
            
            // Update requisition to converted
            $stmt = $pdo->prepare("UPDATE purchase_requisitions SET status = 'converted_to_po' WHERE id = ?");
            $stmt->execute([$id]);
            
            $pdo->commit();
            
            logAudit($_SESSION['user_id'], 'convert_requisition_to_po', 'procurement', "Converted requisition $id to PO: $po_number");
            $_SESSION['success'] = "Requisition converted to PO successfully! PO #: $po_number";
        } else {
            $_SESSION['error'] = "Requisition not found or not approved.";
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error converting to PO: " . $e->getMessage();
    }
    header('Location: requisitions.php');
    exit();
}

// ============================================
// GET REQUISITIONS WITH FILTERS & SORTING
// ============================================
$showArchived = isset($_GET['archived']) ? 1 : 0;
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$priorityFilter = isset($_GET['priority']) ? $_GET['priority'] : '';
$departmentFilter = isset($_GET['department']) ? $_GET['department'] : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sortField = isset($_GET['sort']) ? $_GET['sort'] : 'created_at';
$sortOrder = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'ASC' : 'DESC';

$allowedSortFields = ['pr_number', 'title', 'requester_name', 'department', 'estimated_total', 'priority', 'status', 'created_at'];
if (!in_array($sortField, $allowedSortFields)) {
    $sortField = 'created_at';
}

try {
    $query = "SELECT pr.*, u.full_name as requester_name, u2.full_name as creator_name 
              FROM purchase_requisitions pr 
              LEFT JOIN users u ON pr.requested_by = u.id 
              LEFT JOIN users u2 ON pr.created_by = u2.id 
              WHERE pr.is_archived = ?";
    $params = [$showArchived];
    
    if (!empty($search)) {
        $query .= " AND (pr.pr_number LIKE ? OR pr.title LIKE ? OR u.full_name LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    if (!empty($statusFilter)) {
        $query .= " AND pr.status = ?";
        $params[] = $statusFilter;
    }
    
    if (!empty($priorityFilter)) {
        $query .= " AND pr.priority = ?";
        $params[] = $priorityFilter;
    }
    
    if (!empty($departmentFilter)) {
        $query .= " AND pr.department = ?";
        $params[] = $departmentFilter;
    }
    
    if (!empty($dateFrom)) {
        $query .= " AND DATE(pr.created_at) >= ?";
        $params[] = $dateFrom;
    }
    
    if (!empty($dateTo)) {
        $query .= " AND DATE(pr.created_at) <= ?";
        $params[] = $dateTo;
    }
    
    $query .= " ORDER BY $sortField $sortOrder";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $requisitions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $requisitions = [];
    $error = "Error fetching requisitions: " . $e->getMessage();
}

// ============================================
// GET STATS
// ============================================
try {
    $stmt = $pdo->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending_review' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN status = 'converted_to_po' THEN 1 ELSE 0 END) as converted
        FROM purchase_requisitions WHERE is_archived = 0");
    $prStats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $prStats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'converted' => 0];
}

// Pagination
$itemsPerPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$totalItems = count($requisitions);
$totalPages = ceil($totalItems / $itemsPerPage);
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$currentPage = max(1, min($currentPage, $totalPages));
$offset = ($currentPage - 1) * $itemsPerPage;
$paginatedRequisitions = array_slice($requisitions, $offset, $itemsPerPage);

// Get unique departments for filter
try {
    $stmt = $pdo->query("SELECT DISTINCT department FROM purchase_requisitions WHERE department IS NOT NULL AND department != '' ORDER BY department");
    $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $departments = [];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Requisitions - GlobalSCM</title>
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
        
        .page-title h1 { font-size: 22px; font-weight: 600; color: var(--text); }
        .page-title p { color: var(--secondary-text); font-size: 14px; margin-top: 2px; }
        
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
        
        /* ===== DROPDOWN ===== */
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
        
        .pr-draft { background: #E5E7EB; color: #374151; }
        .pr-pending_review { background: #FEF3C7; color: #92400E; }
        .pr-approved { background: #DBEAFE; color: #1E40AF; }
        .pr-rejected { background: #FEE2E2; color: #DC2626; }
        .pr-revision_requested { background: #FEF3C7; color: #92400E; }
        .pr-converted_to_po { background: #D1FAE5; color: #065F46; }
        
        .priority-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .priority-low { background: #D1FAE5; color: #065F46; }
        .priority-medium { background: #DBEAFE; color: #1E40AF; }
        .priority-high { background: #FEF3C7; color: #92400E; }
        .priority-urgent { background: #FEE2E2; color: #DC2626; }
        
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
            display: <?php echo ($viewRequisition || $action === 'create' || isset($error)) ? 'flex' : 'none'; ?>;
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
        
        .sidebar-toggle-btn {
            display: none;
            background: none;
            border: none;
            font-size: 20px;
            color: var(--secondary-text);
            cursor: pointer;
            padding: 4px 8px;
        }
        
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
            .sidebar { width: 0; padding: 0; overflow: hidden; position: fixed; left: -320px; transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: var(--shadow-lg); }
            .sidebar.open { left: 0; width: 300px; padding: 24px 16px; }
            .sidebar-toggle-btn { display: block; }
            .sidebar-overlay.active { display: block; }
            .main-content { margin-left: 0; padding: 16px; width: 100%; padding-top: 16px; }
            .top-bar { padding: 16px; gap: 12px; }
            .page-title h1 { font-size: 18px; }
            .page-title p { font-size: 13px; }
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
                    <h1>Purchase Requisitions</h1>
                    <p>Create and manage purchase requisitions</p>
                </div>
                <div class="top-bar-actions">
                    <a href="requisitions.php?action=create" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Requisition
                    </a>
                    
                    <!-- Export Dropdown -->
                    <div class="dropdown">
                        <button class="btn btn-outline" onclick="toggleExportDropdown()">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <div class="dropdown-content" id="exportDropdown">
                            <a href="requisitions.php?action=export&format=csv<?php echo '&status=' . $statusFilter . '&search=' . urlencode($search); ?>">
                                <i class="fas fa-file-csv"></i> Export CSV
                            </a>
                            <a href="requisitions.php?action=export&format=pdf<?php echo '&status=' . $statusFilter . '&search=' . urlencode($search); ?>">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </a>
                        </div>
                    </div>
                    
                    <a href="requisitions.php<?php echo $showArchived ? '' : '?archived=1'; ?>" class="btn btn-outline">
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
                    <div class="label"><i class="fas fa-clipboard-list"></i> Total Requisitions</div>
                    <div class="value"><?php echo number_format($prStats['total'] ?? 0); ?></div>
                </div>
                <div class="stat-card" style="border-color: #F59E0B;">
                    <div class="label"><i class="fas fa-clock" style="color: #F59E0B;"></i> Pending Review</div>
                    <div class="value" style="color: #F59E0B;"><?php echo number_format($prStats['pending'] ?? 0); ?></div>
                </div>
                <div class="stat-card" style="border-color: var(--accent);">
                    <div class="label"><i class="fas fa-check-circle" style="color: var(--accent);"></i> Approved</div>
                    <div class="value" style="color: var(--accent);"><?php echo number_format($prStats['approved'] ?? 0); ?></div>
                </div>
                <div class="stat-card" style="border-color: #DC2626;">
                    <div class="label"><i class="fas fa-times-circle" style="color: #DC2626;"></i> Rejected</div>
                    <div class="value" style="color: #DC2626;"><?php echo number_format($prStats['rejected'] ?? 0); ?></div>
                </div>
            </div>
            
            <!-- Search & Filter Bar -->
            <div class="filter-bar">
                <form method="GET" style="display: flex; gap: 12px; flex-wrap: wrap; width: 100%; align-items: center;">
                    <input type="hidden" name="archived" value="<?php echo $showArchived; ?>">
                    
                    <input type="text" name="search" class="search-input" 
                           placeholder="Search by PR number, title, or requester..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="draft" <?php echo $statusFilter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="pending_review" <?php echo $statusFilter === 'pending_review' ? 'selected' : ''; ?>>Pending Review</option>
                        <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="revision_requested" <?php echo $statusFilter === 'revision_requested' ? 'selected' : ''; ?>>Revision Requested</option>
                        <option value="converted_to_po" <?php echo $statusFilter === 'converted_to_po' ? 'selected' : ''; ?>>Converted to PO</option>
                    </select>
                    
                    <select name="priority">
                        <option value="">All Priorities</option>
                        <option value="low" <?php echo $priorityFilter === 'low' ? 'selected' : ''; ?>>Low</option>
                        <option value="medium" <?php echo $priorityFilter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="high" <?php echo $priorityFilter === 'high' ? 'selected' : ''; ?>>High</option>
                        <option value="urgent" <?php echo $priorityFilter === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                    </select>
                    
                    <select name="department">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $departmentFilter === $dept ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <input type="date" name="date_from" placeholder="From" value="<?php echo $dateFrom; ?>">
                    <input type="date" name="date_to" placeholder="To" value="<?php echo $dateTo; ?>">
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <?php if (!empty($search) || !empty($statusFilter) || !empty($priorityFilter) || !empty($departmentFilter) || !empty($dateFrom) || !empty($dateTo)): ?>
                        <a href="requisitions.php<?php echo $showArchived ? '?archived=1' : ''; ?>" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <!-- Batch Operations Bar -->
            <div class="batch-bar" id="batchBar">
                <span class="selected-info">
                    <strong id="selectedCount">0</strong> requisitions selected
                </span>
                <button class="btn btn-success btn-sm" onclick="bulkApprove()">
                    <i class="fas fa-check"></i> Approve & Convert to PO
                </button>
                <button class="btn btn-outline btn-sm" onclick="clearSelection()">
                    <i class="fas fa-times"></i> Clear Selection
                </button>
            </div>
            
            <div class="table-container">
                <div class="table-header">
                    <h2><?php echo $showArchived ? 'Archived Requisitions' : 'Purchase Requisitions'; ?></h2>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <span class="role-badge"><?php echo count($requisitions); ?> requisitions</span>
                        <span class="role-badge">Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>
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
                                    <th onclick="sortTable('pr_number')" class="<?php echo $sortField === 'pr_number' ? 'sorted' : ''; ?>">
                                        PR Number <span class="sort-icon"><?php echo $sortField === 'pr_number' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('title')" class="<?php echo $sortField === 'title' ? 'sorted' : ''; ?>">
                                        Title <span class="sort-icon"><?php echo $sortField === 'title' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('requester_name')" class="<?php echo $sortField === 'requester_name' ? 'sorted' : ''; ?>">
                                        Requester <span class="sort-icon"><?php echo $sortField === 'requester_name' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('department')" class="<?php echo $sortField === 'department' ? 'sorted' : ''; ?>">
                                        Department <span class="sort-icon"><?php echo $sortField === 'department' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('estimated_total')" class="<?php echo $sortField === 'estimated_total' ? 'sorted' : ''; ?>">
                                        Est. Total <span class="sort-icon"><?php echo $sortField === 'estimated_total' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('priority')" class="<?php echo $sortField === 'priority' ? 'sorted' : ''; ?>">
                                        Priority <span class="sort-icon"><?php echo $sortField === 'priority' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('status')" class="<?php echo $sortField === 'status' ? 'sorted' : ''; ?>">
                                        Status <span class="sort-icon"><?php echo $sortField === 'status' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($paginatedRequisitions)): ?>
                                    <?php foreach ($paginatedRequisitions as $pr): ?>
                                    <tr>
                                        <td class="checkbox-cell">
                                            <input type="checkbox" name="ids[]" value="<?php echo $pr['id']; ?>" 
                                                   class="row-checkbox" onchange="updateSelection();"
                                                   <?php echo $pr['status'] !== 'pending_review' ? 'disabled' : ''; ?>>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($pr['pr_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($pr['title']); ?></td>
                                        <td><?php echo htmlspecialchars($pr['requester_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($pr['department'] ?? 'N/A'); ?></td>
                                        <td>₱<?php echo number_format($pr['estimated_total'], 2); ?></td>
                                        <td>
                                            <span class="priority-badge priority-<?php echo $pr['priority']; ?>">
                                                <?php if ($pr['priority'] === 'urgent'): ?>
                                                    <i class="fas fa-exclamation-circle"></i>
                                                <?php elseif ($pr['priority'] === 'high'): ?>
                                                    <i class="fas fa-arrow-up"></i>
                                                <?php elseif ($pr['priority'] === 'medium'): ?>
                                                    <i class="fas fa-minus"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-arrow-down"></i>
                                                <?php endif; ?>
                                                <?php echo ucfirst($pr['priority']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge pr-<?php echo $pr['status']; ?>">
                                                <?php if ($pr['status'] === 'converted_to_po'): ?>
                                                    <i class="fas fa-check-circle"></i>
                                                <?php elseif ($pr['status'] === 'approved'): ?>
                                                    <i class="fas fa-check"></i>
                                                <?php elseif ($pr['status'] === 'rejected'): ?>
                                                    <i class="fas fa-times"></i>
                                                <?php elseif ($pr['status'] === 'revision_requested'): ?>
                                                    <i class="fas fa-edit"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-clock"></i>
                                                <?php endif; ?>
                                                <?php echo ucfirst(str_replace('_', ' ', $pr['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="requisitions.php?action=view&id=<?php echo $pr['id']; ?>" 
                                                   class="btn btn-primary btn-sm" title="View Request">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if (!$showArchived && $pr['status'] === 'pending_review'): ?>
                                                <button onclick="openStatusModal(<?php echo $pr['id']; ?>, '<?php echo htmlspecialchars($pr['pr_number']); ?>')" 
                                                        class="btn btn-success btn-sm" title="Review">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <?php endif; ?>
                                                <?php if (!$showArchived && $pr['status'] === 'approved'): ?>
                                                <a href="requisitions.php?action=convert_to_po&id=<?php echo $pr['id']; ?>" 
                                                   class="btn btn-warning btn-sm" 
                                                   onclick="return confirm('Convert this requisition to a Purchase Order?');" title="Convert to PO">
                                                    <i class="fas fa-exchange-alt"></i>
                                                </a>
                                                <?php endif; ?>
                                                <?php if (!$showArchived): ?>
                                                <a href="requisitions.php?action=archive&id=<?php echo $pr['id']; ?>" 
                                                   class="btn btn-danger btn-sm" 
                                                   onclick="return confirm('Archive this requisition?');" title="Archive">
                                                    <i class="fas fa-archive"></i>
                                                </a>
                                                <?php else: ?>
                                                <a href="requisitions.php?action=restore&id=<?php echo $pr['id']; ?>" 
                                                   class="btn btn-success btn-sm"
                                                   onclick="return confirm('Restore this requisition?');" title="Restore">
                                                    <i class="fas fa-undo"></i>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="empty-state">
                                            <i class="fas fa-clipboard-list"></i>
                                            <p>No requisitions found</p>
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
                        of <strong><?php echo $totalItems; ?></strong> requisitions
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
                        
                        <button class="page-btn" onclick="goToPage(<?php echo $currentPage - 1; ?>)" 
                                <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>>
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        
                        <?php for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++): ?>
                            <button class="page-btn <?php echo $i == $currentPage ? 'active' : ''; ?>" 
                                    onclick="goToPage(<?php echo $i; ?>);">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <button class="page-btn" onclick="goToPage(<?php echo $currentPage + 1; ?>)" 
                                <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>>
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- View Requisition Modal -->
    <?php if ($viewRequisition): ?>
    <div class="modal-overlay" style="display: flex;">
        <div class="modal">
            <h3>
                <i class="fas fa-clipboard-list" style="color: var(--primary);"></i> 
                PR #<?php echo htmlspecialchars($viewRequisition['pr_number']); ?>
                <button type="button" class="close-modal" onclick="window.location.href='requisitions.php'">&times;</button>
            </h3>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 20px 0; padding: 15px; background: var(--bg); border-radius: 10px;">
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Title</div>
                    <div><strong><?php echo htmlspecialchars($viewRequisition['title']); ?></strong></div>
                    <div style="font-size: 13px; color: var(--secondary-text);">
                        <?php echo htmlspecialchars($viewRequisition['description'] ?? ''); ?>
                    </div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Requester</div>
                    <div><?php echo htmlspecialchars($viewRequisition['requester_name'] ?? 'N/A'); ?></div>
                    <div style="font-size: 12px; color: var(--secondary-text);">
                        Department: <?php echo htmlspecialchars($viewRequisition['department'] ?? 'N/A'); ?>
                    </div>
                    <div style="font-size: 12px; color: var(--secondary-text);">
                        Required: <?php echo date('M d, Y', strtotime($viewRequisition['required_date'])); ?>
                    </div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Priority</div>
                    <span class="priority-badge priority-<?php echo $viewRequisition['priority']; ?>">
                        <?php echo ucfirst($viewRequisition['priority']); ?>
                    </span>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Status</div>
                    <span class="status-badge pr-<?php echo $viewRequisition['status']; ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $viewRequisition['status'])); ?>
                    </span>
                </div>
            </div>
            
            <h4 style="margin-bottom: 10px;">Items</h4>
            <?php foreach ($viewRequisition['items'] as $item): ?>
            <div class="item-row">
                <div>
                    <div><strong><?php echo htmlspecialchars($item['product_name']); ?></strong></div>
                    <div style="font-size: 13px; color: var(--secondary-text);">
                        SKU: <?php echo htmlspecialchars($item['sku']); ?>
                    </div>
                </div>
                <div style="text-align: right;">
                    <div><?php echo number_format($item['quantity']); ?> x ₱<?php echo number_format($item['unit_price'], 2); ?></div>
                    <div style="font-weight: 600;">₱<?php echo number_format($item['quantity'] * $item['unit_price'], 2); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <div style="margin-top: 15px; padding-top: 15px; border-top: 2px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Created By</div>
                    <div><?php echo htmlspecialchars($viewRequisition['creator_name'] ?? 'N/A'); ?></div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 12px; color: var(--secondary-text);">Estimated Total</div>
                    <div style="font-size: 24px; font-weight: 700;">₱<?php echo number_format($viewRequisition['estimated_total'], 2); ?></div>
                </div>
            </div>
            
            <?php if ($viewRequisition['review_notes']): ?>
            <div style="margin-top: 15px; padding: 10px; background: var(--bg); border-radius: 8px;">
                <div style="font-size: 12px; color: var(--secondary-text);">Review Notes</div>
                <div><?php echo nl2br(htmlspecialchars($viewRequisition['review_notes'])); ?></div>
            </div>
            <?php endif; ?>
            
            <div class="form-actions" style="margin-top: 20px;">
                <a href="requisitions.php" class="btn btn-outline">Close</a>
                <?php if ($viewRequisition['status'] === 'pending_review'): ?>
                <button onclick="openStatusModal(<?php echo $viewRequisition['id']; ?>, '<?php echo htmlspecialchars($viewRequisition['pr_number']); ?>')" 
                        class="btn btn-primary">
                    <i class="fas fa-check"></i> Review
                </button>
                <?php endif; ?>
                <?php if ($viewRequisition['status'] === 'approved'): ?>
                <a href="requisitions.php?action=convert_to_po&id=<?php echo $viewRequisition['id']; ?>" 
                   class="btn btn-warning" onclick="return confirm('Convert this requisition to a Purchase Order?');">
                    <i class="fas fa-exchange-alt"></i> Convert to PO
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Create Requisition Modal -->
    <?php if ($action === 'create'): ?>
    <div class="modal-overlay" id="createModal" style="display: flex;">
        <div class="modal" style="max-width: 700px; max-height: 90vh; overflow-y: auto;">
            <h3>
                <i class="fas fa-plus" style="color: var(--primary);"></i> Create Purchase Requisition
                <button type="button" class="close-modal" onclick="closeModal()">&times;</button>
            </h3>
            <form method="POST" action="requisitions.php?action=create" id="prForm">
                <div class="form-group">
                    <label>Title *</label>
                    <input type="text" name="title" required placeholder="e.g., Office Supplies Order">
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="2"></textarea>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Requester *</label>
                        <select name="requested_by" required>
                            <option value="">Select Requester</option>
                            <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>">
                                <?php echo htmlspecialchars($user['full_name'] . ' (' . $user['username'] . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Department</label>
                        <input type="text" name="department" placeholder="e.g., Operations">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Required Date *</label>
                        <input type="date" name="required_date" required value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                    </div>
                    <div class="form-group">
                        <label>Priority</label>
                        <select name="priority">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                </div>
                
                <h4 style="margin: 15px 0 10px;">Items</h4>
                <div id="itemsContainer">
                    <div class="item-row" style="display: grid; grid-template-columns: 2fr 1fr 1fr 0.5fr; gap: 10px; background: var(--bg); padding: 10px; border-radius: 8px; margin-bottom: 10px;">
                        <select name="items[0][product_id]" class="form-group" style="margin: 0;" required>
                            <option value="">Select Product</option>
                            <?php foreach ($products as $product): ?>
                            <option value="<?php echo $product['id']; ?>" data-price="<?php echo $product['unit_price']; ?>">
                                <?php echo htmlspecialchars($product['sku'] . ' - ' . $product['product_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="items[0][quantity]" placeholder="Qty" required style="padding: 10px 14px; border: 2px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text);">
                        <input type="number" name="items[0][unit_price]" placeholder="Price" required step="0.01" style="padding: 10px 14px; border: 2px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text);">
                        <button type="button" onclick="removeItem(this)" class="btn btn-danger btn-sm" style="padding: 8px 12px;">×</button>
                    </div>
                </div>
                
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
                        <i class="fas fa-save"></i> Create Requisition
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
                <i class="fas fa-check" style="color: var(--primary);"></i> Review Requisition
                <button type="button" class="close-modal" onclick="closeStatusModal()">&times;</button>
            </h3>
            <form method="POST" action="requisitions.php?action=update_status">
                <input type="hidden" name="id" id="statusPrId">
                <div class="form-group">
                    <label>Requisition Number</label>
                    <p id="statusPrNumber" style="font-weight: 500;"></p>
                </div>
                <div class="form-group">
                    <label>Action</label>
                    <select name="status" required>
                        <option value="approved">Approve & Convert to PO</option>
                        <option value="rejected">Reject</option>
                        <option value="revision_requested">Request Revision</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Review Notes</label>
                    <textarea name="review_notes" rows="3" placeholder="Provide feedback or reason for decision..."></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" onclick="closeStatusModal()" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Submit Review
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
                window.location.href = 'requisitions.php?action=create';
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
        
        function addItem() {
            var container = document.getElementById('itemsContainer');
            var template = container.querySelector('.item-row').cloneNode(true);
            
            template.querySelectorAll('select, input').forEach(function(el) {
                var name = el.getAttribute('name');
                if (name) {
                    el.setAttribute('name', name.replace(/\[0\]/, '[' + itemCount + ']'));
                }
                if (el.tagName === 'SELECT') {
                    el.value = '';
                } else if (el.type === 'number') {
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
            document.getElementById('statusPrId').value = id;
            document.getElementById('statusPrNumber').textContent = number;
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
                alert('Please select at least one requisition.');
                return;
            }
            
            if (confirm('Approve and convert ' + ids.length + ' selected requisitions to POs?')) {
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
                        window.location.href = 'requisitions.php';
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
                            window.location.href = 'requisitions.php';
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>