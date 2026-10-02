<?php
// admin/stock-movements.php
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

// ============================================
// DEFINE ACTION - Moved before export
// ============================================
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// ============================================
// GET FILTERS - Moved before export
// ============================================
$productFilter = isset($_GET['product_id']) ? (int)$_GET['product_id'] : '';
$typeFilter = isset($_GET['type']) ? $_GET['type'] : '';
$warehouseFilter = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
$userFilter = isset($_GET['user_id']) ? (int)$_GET['user_id'] : '';
$datePreset = isset($_GET['date_preset']) ? $_GET['date_preset'] : 'last_30';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

try {
    $stmt = $pdo->query("SELECT id, name, warehouse_code FROM warehouses WHERE is_archived = 0 AND status = 'active' ORDER BY name");
    $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $warehouses = [];
}

// Set date range based on preset
switch ($datePreset) {
    case 'today':
        $dateFrom = date('Y-m-d');
        $dateTo = date('Y-m-d');
        break;
    case 'last_7':
        $dateFrom = date('Y-m-d', strtotime('-7 days'));
        $dateTo = date('Y-m-d');
        break;
    case 'this_month':
        $dateFrom = date('Y-m-01');
        $dateTo = date('Y-m-d');
        break;
    case 'custom':
        $dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
        $dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
        break;
    default: // last_30
        $dateFrom = date('Y-m-d', strtotime('-30 days'));
        $dateTo = date('Y-m-d');
}

// Override with custom dates if provided
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : $dateFrom;
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : $dateTo;

// ============================================
// EXPORT FUNCTIONALITY
// ============================================
if ($action === 'export' && isset($_GET['format'])) {
    $format = $_GET['format'];
    
    try {
        $query = "SELECT it.*, p.sku, p.product_name, u.full_name as user_name, w.name as warehouse_name
                  FROM inventory_transactions it 
                  JOIN products p ON it.product_id = p.id 
              LEFT JOIN warehouses w ON it.warehouse_id = w.id
                  LEFT JOIN users u ON it.created_by = u.id 
                  WHERE DATE(it.created_at) BETWEEN ? AND ?";
        $params = [$dateFrom, $dateTo];
        
        if (!empty($typeFilter)) {
            $query .= " AND it.transaction_type = ?";
            $params[] = $typeFilter;
        }
        if (!empty($productFilter)) {
            $query .= " AND it.product_id = ?";
            $params[] = $productFilter;
        }
        if ($warehouseFilter > 0) {
            $query .= " AND it.warehouse_id = ?";
            $params[] = $warehouseFilter;
        }
        if (!empty($userFilter)) {
            $query .= " AND it.created_by = ?";
            $params[] = $userFilter;
        }
        if (!empty($search)) {
            $query .= " AND (p.sku LIKE ? OR p.product_name LIKE ? OR it.reference_document LIKE ?)";
            $searchParam = "%$search%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }
        
        $query .= " ORDER BY it.created_at DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        require_once __DIR__ . '/../includes/report_export.php';
        exportTrackedReport($pdo, (int)$_SESSION['user_id'], 'stock_movements', 'Warehouse Stock Movements', $format, $data);
        
        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="stock_movements_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Date/Time', 'Product', 'SKU', 'Type', 'Quantity', 'Previous', 'New', 'User', 'Notes', 'Reference']);
            foreach ($data as $row) {
                fputcsv($output, [
                    $row['created_at'],
                    $row['product_name'],
                    $row['sku'],
                    $row['transaction_type'],
                    $row['quantity'],
                    $row['previous_balance'],
                    $row['new_balance'],
                    $row['user_name'],
                    $row['notes'],
                    $row['reference_document']
                ]);
            }
            fclose($output);
            exit();
        } elseif ($format === 'excel') {
            header('Content-Type: application/vnd.ms-excel');
            header('Content-Disposition: attachment; filename="stock_movements_' . date('Y-m-d') . '.xls"');
            
            echo "<table border='1'>";
            echo "<tr><th>Date/Time</th><th>Product</th><th>SKU</th><th>Type</th><th>Quantity</th><th>Previous</th><th>New</th><th>User</th><th>Notes</th><th>Reference</th></tr>";
            foreach ($data as $row) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
                echo "<td>" . htmlspecialchars($row['product_name']) . "</td>";
                echo "<td>" . htmlspecialchars($row['sku']) . "</td>";
                echo "<td>" . htmlspecialchars($row['transaction_type']) . "</td>";
                echo "<td>" . htmlspecialchars($row['quantity']) . "</td>";
                echo "<td>" . htmlspecialchars($row['previous_balance']) . "</td>";
                echo "<td>" . htmlspecialchars($row['new_balance']) . "</td>";
                echo "<td>" . htmlspecialchars($row['user_name']) . "</td>";
                echo "<td>" . htmlspecialchars($row['notes']) . "</td>";
                echo "<td>" . htmlspecialchars($row['reference_document']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Export failed: " . $e->getMessage();
        header('Location: stock-movements.php');
        exit();
    }
}

// ============================================
// REPORT DAMAGE OR CUSTOMER RETURN
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'report_incident') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);
    $issueType = (string)($_POST['issue_type'] ?? '');
    $description = trim((string)($_POST['description'] ?? ''));

    try {
        if ($productId < 1 || $warehouseId < 1 || $quantity < 1 || !in_array($issueType, ['damaged', 'customer_return'], true)) {
            throw new RuntimeException('Choose an item, warehouse, valid quantity, and issue type.');
        }
        $warehouseStmt = $pdo->prepare("SELECT id FROM warehouses WHERE id = ? AND status = 'active' AND is_archived = false");
        $warehouseStmt->execute([$warehouseId]);
        if (!$warehouseStmt->fetchColumn()) throw new RuntimeException('Select an active warehouse.');

        $pdo->beginTransaction();
        $incidentNumber = 'INC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $pdo->prepare("INSERT INTO inventory_incidents (incident_number, product_id, warehouse_id, quantity, issue_type, description, status, created_by) VALUES (?, ?, ?, ?, ?, ?, 'reported', ?)");
        $stmt->execute([$incidentNumber, $productId, $warehouseId, $quantity, $issueType, $description, $_SESSION['user_id']]);
        $incidentId = (int)$pdo->lastInsertId();
        $documentStmt = $pdo->prepare("INSERT INTO documents (document_number, document_type, title, description, related_module, related_id, status, created_by) VALUES (?, 'report', ?, ?, 'inventory_incidents', ?, 'pending', ?)");
        $documentStmt->execute(['DOC-' . $incidentNumber, 'Inventory Incident ' . $incidentNumber, $description, $incidentId, $_SESSION['user_id']]);

        if ($issueType === 'damaged') {
            validateAndConsumeInventoryBatches($pdo, $productId, $warehouseId, $quantity, true);
            $stockStmt = $pdo->prepare("SELECT quantity FROM warehouse_inventory WHERE product_id = ? AND warehouse_id = ?");
            $stockStmt->execute([$productId, $warehouseId]);
            $previousBalance = (int)($stockStmt->fetchColumn() ?: 0);
            if ($previousBalance < $quantity) {
                throw new RuntimeException('The reported damaged quantity cannot exceed stock currently assigned to that warehouse.');
            }
            $newBalance = $previousBalance - $quantity;
            $stockStmt = $pdo->prepare("UPDATE warehouse_inventory SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE product_id = ? AND warehouse_id = ?");
            $stockStmt->execute([$newBalance, $productId, $warehouseId]);
            $stockStmt = $pdo->prepare("UPDATE products SET current_stock = (SELECT COALESCE(SUM(quantity), 0) FROM warehouse_inventory WHERE product_id = ?) WHERE id = ?");
            $stockStmt->execute([$productId, $productId]);
            $stockStmt = $pdo->prepare("INSERT INTO inventory_transactions (product_id, transaction_type, quantity, previous_balance, new_balance, warehouse_id, notes, reference_document, created_by) VALUES (?, 'adjustment', ?, ?, ?, ?, ?, ?, ?)");
            $stockStmt->execute([$productId, $quantity, $previousBalance, $newBalance, $warehouseId, 'Damaged stock quarantined for incident ' . $incidentNumber, $incidentNumber, $_SESSION['user_id']]);
        }

        $pdo->commit();
        logAudit($_SESSION['user_id'], 'report_inventory_incident', 'inventory', 'Reported ' . $issueType . ' incident ' . $incidentNumber);
        $_SESSION['success'] = 'Incident ' . $incidentNumber . ' reported. Resolve it after inspection.';
        header('Location: stock-movements.php');
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'Could not report incident: ' . $e->getMessage();
        $action = 'report_incident';
    }
}

// ============================================
// RESOLVE INVENTORY INCIDENT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'resolve_incident') {
    $incidentId = (int)($_POST['incident_id'] ?? 0);
    $resolution = (string)($_POST['resolution'] ?? '');

    try {
        if ($incidentId < 1 || !in_array($resolution, ['restock', 'supplier_return', 'write_off'], true)) {
            throw new RuntimeException('Choose a valid incident resolution.');
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM inventory_incidents WHERE id = ? AND status = 'reported'");
        $stmt->execute([$incidentId]);
        $incident = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$incident) throw new RuntimeException('The incident was already resolved or does not exist.');

        $quantity = (int)$incident['quantity'];
        $productId = (int)$incident['product_id'];
        $warehouseId = (int)$incident['warehouse_id'];
        $changesStock = $resolution === 'restock';

        if ($changesStock) {
            $balanceStmt = $pdo->prepare("SELECT quantity FROM warehouse_inventory WHERE product_id = ? AND warehouse_id = ?");
            $balanceStmt->execute([$productId, $warehouseId]);
            $balanceValue = $balanceStmt->fetchColumn();
            $previousBalance = (int)($balanceValue ?: 0);
            if ($incident['issue_type'] === 'damaged' && $previousBalance < $quantity) {
                throw new RuntimeException('Warehouse stock changed since the incident was reported; reconcile it before resolving.');
            }
            $newBalance = $previousBalance + $quantity;

            if ($balanceValue !== false) {
                $stmt = $pdo->prepare("UPDATE warehouse_inventory SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE product_id = ? AND warehouse_id = ?");
                $stmt->execute([$newBalance, $productId, $warehouseId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO warehouse_inventory (product_id, warehouse_id, quantity) VALUES (?, ?, ?)");
                $stmt->execute([$productId, $warehouseId, $newBalance]);
            }

            $stmt = $pdo->prepare("UPDATE products SET current_stock = (SELECT COALESCE(SUM(quantity), 0) FROM warehouse_inventory WHERE product_id = ?) WHERE id = ?");
            $stmt->execute([$productId, $productId]);
            $transactionType = 'return';
            $stmt = $pdo->prepare("INSERT INTO inventory_transactions (product_id, transaction_type, quantity, previous_balance, new_balance, warehouse_id, notes, reference_document, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $movementNote = 'Incident ' . $incident['incident_number'] . ' resolved as ' . $resolution;
            $stmt->execute([$productId, $transactionType, $quantity, $previousBalance, $newBalance, $warehouseId, $movementNote, $incident['incident_number'], $_SESSION['user_id']]);
        }

        $stmt = $pdo->prepare("UPDATE inventory_incidents SET status = 'resolved', resolution = ?, resolved_by = ?, resolved_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$resolution, $_SESSION['user_id'], $incidentId]);
        $stmt = $pdo->prepare("UPDATE documents SET status = 'approved', updated_at = CURRENT_TIMESTAMP WHERE related_module = 'inventory_incidents' AND related_id = ?");
        $stmt->execute([$incidentId]);
        $pdo->commit();
        logAudit($_SESSION['user_id'], 'resolve_inventory_incident', 'inventory', 'Resolved incident ' . $incident['incident_number'] . ' as ' . $resolution);
        $_SESSION['success'] = 'Incident resolved.';
        header('Location: stock-movements.php');
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = 'Could not resolve incident: ' . $e->getMessage();
        header('Location: stock-movements.php');
        exit();
    }
}

// ============================================
// HANDLE MANUAL ADJUSTMENT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'adjust') {
    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 0;
    $adjustment_type = isset($_POST['adjustment_type']) ? $_POST['adjustment_type'] : '';
    $warehouse_id = isset($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : 0;
    $destination_warehouse_id = isset($_POST['destination_warehouse_id']) ? (int)$_POST['destination_warehouse_id'] : 0;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    $reference = isset($_POST['reference']) ? trim($_POST['reference']) : '';
    
    if ($product_id > 0 && $quantity > 0 && in_array($adjustment_type, ['add', 'remove', 'transfer'], true) && $warehouse_id > 0) {
        try {
            $pdo->beginTransaction();

            $warehouseStmt = $pdo->prepare("SELECT id FROM warehouses WHERE id = ? AND status = 'active' AND is_archived = false");
            $warehouseStmt->execute([$warehouse_id]);
            if (!$warehouseStmt->fetchColumn()) {
                throw new RuntimeException('Select an active warehouse.');
            }
            
            $stmt = $pdo->prepare("SELECT p.product_name, COALESCE(wi.quantity, 0) AS current_stock FROM products p LEFT JOIN warehouse_inventory wi ON wi.product_id = p.id AND wi.warehouse_id = ? WHERE p.id = ?");
            $stmt->execute([$warehouse_id, $product_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                throw new Exception('Product not found');
            }

            if ($adjustment_type === 'transfer') {
                if ($destination_warehouse_id < 1 || $destination_warehouse_id === $warehouse_id) {
                    throw new RuntimeException('Choose a different destination warehouse.');
                }
                $destinationCheck = $pdo->prepare("SELECT id, name, warehouse_code FROM warehouses WHERE id = ? AND status = 'active' AND is_archived = false");
                $destinationCheck->execute([$destination_warehouse_id]);
                $destinationWarehouse = $destinationCheck->fetch(PDO::FETCH_ASSOC);
                if (!$destinationWarehouse) throw new RuntimeException('Select an active destination warehouse.');

                $sourceBalance = (int)$product['current_stock'];
                if ($sourceBalance < $quantity) throw new RuntimeException('Transfer quantity exceeds stock in the source warehouse.');
                moveAvailableInventoryBatches($pdo, $product_id, $warehouse_id, $destination_warehouse_id, $quantity);

                $destinationStmt = $pdo->prepare("SELECT quantity FROM warehouse_inventory WHERE product_id = ? AND warehouse_id = ?");
                $destinationStmt->execute([$product_id, $destination_warehouse_id]);
                $destinationValue = $destinationStmt->fetchColumn();
                $destinationBalance = (int)($destinationValue ?: 0);
                $sourceAfter = $sourceBalance - $quantity;
                $destinationAfter = $destinationBalance + $quantity;

                $sourceUpdate = $pdo->prepare("UPDATE warehouse_inventory SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE product_id = ? AND warehouse_id = ?");
                $sourceUpdate->execute([$sourceAfter, $product_id, $warehouse_id]);
                if ($destinationValue !== false) {
                    $destinationUpdate = $pdo->prepare("UPDATE warehouse_inventory SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE product_id = ? AND warehouse_id = ?");
                    $destinationUpdate->execute([$destinationAfter, $product_id, $destination_warehouse_id]);
                } else {
                    $destinationUpdate = $pdo->prepare("INSERT INTO warehouse_inventory (product_id, warehouse_id, quantity) VALUES (?, ?, ?)");
                    $destinationUpdate->execute([$product_id, $destination_warehouse_id, $destinationAfter]);
                }

                $sourceNameStmt = $pdo->prepare("SELECT name, warehouse_code FROM warehouses WHERE id = ?");
                $sourceNameStmt->execute([$warehouse_id]);
                $sourceWarehouse = $sourceNameStmt->fetch(PDO::FETCH_ASSOC);
                $sourceLabel = $sourceWarehouse['name'] . ' (' . $sourceWarehouse['warehouse_code'] . ')';
                $destinationLabel = $destinationWarehouse['name'] . ' (' . $destinationWarehouse['warehouse_code'] . ')';
                $reference = $reference !== '' ? $reference : 'TR-' . date('YmdHis');
                $transactionStmt = $pdo->prepare("INSERT INTO inventory_transactions (product_id, transaction_type, quantity, previous_balance, new_balance, warehouse_id, notes, reference_document, created_by) VALUES (?, 'transfer', ?, ?, ?, ?, ?, ?, ?)");
                $transactionStmt->execute([$product_id, $quantity, $sourceBalance, $sourceAfter, $warehouse_id, 'Transfer out to ' . $destinationLabel . '. ' . $notes, $reference, $_SESSION['user_id']]);
                $transactionStmt->execute([$product_id, $quantity, $destinationBalance, $destinationAfter, $destination_warehouse_id, 'Transfer in from ' . $sourceLabel . '. ' . $notes, $reference, $_SESSION['user_id']]);

                $aggregateStmt = $pdo->prepare("UPDATE products SET current_stock = (SELECT COALESCE(SUM(quantity), 0) FROM warehouse_inventory WHERE product_id = ?) WHERE id = ?");
                $aggregateStmt->execute([$product_id, $product_id]);
                $pdo->commit();
                logAudit($_SESSION['user_id'], 'transfer_stock', 'inventory', 'Transferred ' . $quantity . ' of ' . $product['product_name'] . ' from ' . $sourceLabel . ' to ' . $destinationLabel);
                $_SESSION['success'] = 'Stock transferred between warehouses.';
                header('Location: stock-movements.php');
                exit();
            }
            
            $current_stock = (int)$product['current_stock'];
            $new_stock = $adjustment_type === 'add' ? $current_stock + $quantity : max(0, $current_stock - $quantity);
            $actualQuantity = abs($new_stock - $current_stock);
            if ($adjustment_type === 'remove' && $actualQuantity > 0) {
                validateAndConsumeInventoryBatches($pdo, $product_id, $warehouse_id, $actualQuantity);
            }
            
            $stmt = $pdo->prepare("SELECT id FROM warehouse_inventory WHERE product_id = ? AND warehouse_id = ?");
            $stmt->execute([$product_id, $warehouse_id]);
            $inventoryId = $stmt->fetchColumn();
            if ($inventoryId) {
                $stmt = $pdo->prepare("UPDATE warehouse_inventory SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$new_stock, $inventoryId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO warehouse_inventory (product_id, warehouse_id, quantity) VALUES (?, ?, ?)");
                $stmt->execute([$product_id, $warehouse_id, $new_stock]);
            }

            $stmt = $pdo->prepare("UPDATE products SET current_stock = (SELECT COALESCE(SUM(quantity), 0) FROM warehouse_inventory WHERE product_id = ?) WHERE id = ?");
            $stmt->execute([$product_id, $product_id]);
            
            // Log transaction
            $transaction_type = $adjustment_type === 'add' ? 'receiving' : 'issuance';
            $stmt = $pdo->prepare("INSERT INTO inventory_transactions (product_id, transaction_type, quantity, previous_balance, new_balance, warehouse_id, notes, reference_document, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$product_id, $transaction_type, $actualQuantity, $current_stock, $new_stock, $warehouse_id, $notes, $reference, $_SESSION['user_id']]);
            
            $pdo->commit();
            
            logAudit($_SESSION['user_id'], 'manual_stock_adjustment', 'inventory', "Manual adjustment for: " . $product['product_name'] . " ($adjustment_type $quantity)");
            $_SESSION['success'] = "Stock adjusted successfully!";
            header('Location: stock-movements.php');
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Error adjusting stock: " . $e->getMessage();
            $action = 'adjust';
        }
    } else {
        $error = "Please fill in all required fields accurately.";
        $action = 'adjust';
    }
}

// ============================================
// BUILD MAIN QUERY
// ============================================

// Sorting
$sortField = isset($_GET['sort']) ? $_GET['sort'] : 'created_at';
$sortOrder = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'ASC' : 'DESC';
$allowedSortFields = ['created_at', 'product_name', 'sku', 'transaction_type', 'warehouse_name', 'reference_document', 'quantity', 'previous_balance', 'new_balance', 'user_name'];
if (!in_array($sortField, $allowedSortFields)) {
    $sortField = 'created_at';
}

// Pagination
$itemsPerPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if ($itemsPerPage < 1) {
    $itemsPerPage = 10; // guard against per_page=0 or a non-numeric value, which would divide by zero below
}
$pageNum = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($pageNum < 1) {
    $pageNum = 1;
}
$offset = ($pageNum - 1) * $itemsPerPage;

// Build query
$query = "SELECT it.*, p.sku, p.product_name, u.full_name as user_name, w.name as warehouse_name 
          FROM inventory_transactions it 
          JOIN products p ON it.product_id = p.id 
          LEFT JOIN warehouses w ON it.warehouse_id = w.id
          LEFT JOIN users u ON it.created_by = u.id 
          WHERE DATE(it.created_at) BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];

if (!empty($productFilter)) {
    $query .= " AND it.product_id = ?";
    $params[] = $productFilter;
}

if ($warehouseFilter > 0) {
    $query .= " AND it.warehouse_id = ?";
    $params[] = $warehouseFilter;
}

if (!empty($typeFilter)) {
    $query .= " AND it.transaction_type = ?";
    $params[] = $typeFilter;
}

if (!empty($userFilter)) {
    $query .= " AND it.created_by = ?";
    $params[] = $userFilter;
}

if (!empty($search)) {
    $query .= " AND (p.sku LIKE ? OR p.product_name LIKE ? OR it.reference_document LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

// Count total records
$countQuery = str_replace("SELECT it.*, p.sku, p.product_name, u.full_name as user_name, w.name as warehouse_name", "SELECT COUNT(*) as total", $query);
$stmt = $pdo->prepare($countQuery);
$stmt->execute($params);
$totalItems = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
$totalPages = (int)ceil($totalItems / $itemsPerPage);
if ($totalPages < 1) {
    $totalPages = 1;
}
$pageNum = (int)max(1, min($pageNum, $totalPages));
$offset = ($pageNum - 1) * $itemsPerPage;

// Get paginated data
$query .= " ORDER BY $sortField $sortOrder LIMIT ? OFFSET ?";
$params[] = $itemsPerPage;
$params[] = $offset;

$stmt = $pdo->prepare($query);
// Bind limit/offset parameters as integers
foreach ($params as $key => $val) {
    $type = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
    $stmt->bindValue($key + 1, $val, $type);
}
$stmt->execute();
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$incidentQuery = "SELECT ii.*, p.sku, p.product_name, w.name AS warehouse_name, reporter.full_name AS reporter_name
                  FROM inventory_incidents ii
                  JOIN products p ON p.id = ii.product_id
                  JOIN warehouses w ON w.id = ii.warehouse_id
                  LEFT JOIN users reporter ON reporter.id = ii.created_by
                  WHERE 1 = 1";
$incidentParams = [];
if ($warehouseFilter > 0) {
    $incidentQuery .= " AND ii.warehouse_id = ?";
    $incidentParams[] = $warehouseFilter;
}
$incidentQuery .= " ORDER BY CASE WHEN ii.status = 'reported' THEN 0 ELSE 1 END, ii.created_at DESC LIMIT 50";
$incidentStmt = $pdo->prepare($incidentQuery);
$incidentStmt->execute($incidentParams);
$incidents = $incidentStmt->fetchAll(PDO::FETCH_ASSOC);

// Get products for filter
try {
    $stmt = $pdo->query("SELECT id, sku, product_name FROM products WHERE is_archived = 0 ORDER BY product_name");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $products = [];
}

// Get users for filter
try {
    $stmt = $pdo->query("SELECT id, full_name, username FROM users WHERE is_archived = 0 ORDER BY full_name");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
}

// Stats
$statsQuery = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN transaction_type = 'receiving' THEN quantity ELSE 0 END) as total_received,
    SUM(CASE WHEN transaction_type = 'issuance' THEN quantity ELSE 0 END) as total_issued,
    SUM(CASE WHEN transaction_type = 'adjustment' THEN ABS(quantity) ELSE 0 END) as total_adjusted
    FROM inventory_transactions 
    WHERE DATE(created_at) BETWEEN ? AND ?";
$statsParams = [$dateFrom, $dateTo];

if (!empty($productFilter)) {
    $statsQuery .= " AND product_id = ?";
    $statsParams[] = $productFilter;
}

$stmt = $pdo->prepare($statsQuery);
$stmt->execute($statsParams);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Movements - GlobalSCM</title>
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
            --bg: <?php echo defined('COLOR_DARK_BG') ? COLOR_DARK_BG : '#121212'; ?>;
            --card: <?php echo defined('COLOR_DARK_CARD') ? COLOR_DARK_CARD : '#1E1E1E'; ?>;
            --text: <?php echo defined('COLOR_DARK_TEXT') ? COLOR_DARK_TEXT : '#E0E0E0'; ?>;
            --secondary-text: <?php echo defined('COLOR_DARK_SECONDARY_TEXT') ? COLOR_DARK_SECONDARY_TEXT : '#A0A0A0'; ?>;
            --border: <?php echo defined('COLOR_DARK_BORDER') ? COLOR_DARK_BORDER : '#2C2C2C'; ?>;
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
            transition: var(--transition);
            line-height: 1.6;
            min-height: 100vh;
            width: 100%;
            overflow-x: hidden;
        }
        
        .admin-layout {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }
        
        /* ===== SIDEBAR ===== */
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
        
        .sidebar-brand > div {
            display: flex;
            flex-direction: column;
        }
        
        .sidebar-brand h2 {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
        }
        
        .sidebar-brand span {
            font-size: 11px;
            color: var(--secondary-text);
            font-weight: 400;
            letter-spacing: 1px;
            text-transform: uppercase;
            display: block;
        }
        
        .sidebar-toggle-btn {
            display: none;
            background: none;
            border: none;
            font-size: 20px;
            color: var(--secondary-text);
            cursor: pointer;
            padding: 4px 8px;
            transition: var(--transition);
        }
        
        .sidebar-toggle-btn:hover {
            color: var(--text);
        }
        
        .nav-section {
            margin-bottom: 24px;
        }
        
        .nav-section-title {
            font-size: 10px;
            text-transform: uppercase;
            color: var(--secondary-text);
            font-weight: 600;
            letter-spacing: 1.2px;
            margin-bottom: 8px;
            padding: 0 12px;
        }
        
        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: var(--radius-sm);
            color: var(--secondary-text);
            text-decoration: none;
            transition: var(--transition);
            margin-bottom: 2px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            position: relative;
        }
        
        .nav-item:hover,
        .nav-item.active {
            background: rgba(47, 128, 237, 0.08);
            color: var(--primary);
        }
        
        .nav-item i {
            width: 20px;
            font-size: 16px;
            text-align: center;
            flex-shrink: 0;
        }
        
        .nav-item .badge {
            margin-left: auto;
            background: var(--primary);
            color: white;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            min-width: 20px;
            text-align: center;
        }
        
        .nav-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 28px;
            background: var(--primary);
            border-radius: 0 4px 4px 0;
        }
        
        .sidebar-profile {
            margin-top: auto;
            padding-top: 20px;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 12px 0;
        }
        
        .sidebar-profile .user-avatar {
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
            flex-shrink: 0;
        }
        
        .sidebar-profile .user-info {
            flex: 1;
            min-width: 0;
        }
        
        .sidebar-profile .user-info .name {
            font-size: 14px;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .sidebar-profile .user-info .role {
            font-size: 12px;
            color: var(--secondary-text);
        }
        
        .sidebar-profile .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--accent);
            flex-shrink: 0;
        }
        
        /* ===== MAIN CONTENT ===== */
        .main-content {
            margin-left: 280px;
            padding: 24px 32px 40px;
            flex: 1;
            min-height: 100vh;
            width: calc(100% - 280px);
            max-width: 100%;
            transition: var(--transition);
        }
        
        /* ===== TOP BAR ===== */
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
        }
        
        .page-title p {
            color: var(--secondary-text);
            font-size: 14px;
            margin-top: 2px;
        }
        
        .top-bar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        /* ===== BUTTONS ===== */
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
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        .btn-primary:hover:not(:disabled) {
            background: #2563EB;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(47, 128, 237, 0.3);
        }
        
        .btn-success {
            background: var(--accent);
            color: white;
        }
        .btn-success:hover:not(:disabled) {
            background: #059669;
            transform: translateY(-1px);
        }
        
        .btn-danger {
            background: #DC2626;
            color: white;
        }
        .btn-danger:hover:not(:disabled) {
            background: #B91C1C;
            transform: translateY(-1px);
        }
        
        .btn-warning {
            background: #F59E0B;
            color: white;
        }
        .btn-warning:hover:not(:disabled) {
            background: #D97706;
            transform: translateY(-1px);
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text);
        }
        .btn-outline:hover:not(:disabled) {
            border-color: var(--primary);
            color: var(--primary);
            background: rgba(47, 128, 237, 0.04);
        }
        
        .btn-back {
            background: var(--bg);
            border: 1px solid var(--border);
            color: var(--text);
        }
        .btn-back:hover:not(:disabled) {
            border-color: var(--primary);
            color: var(--primary);
            background: rgba(47, 128, 237, 0.04);
        }
        
        .btn-sm {
            padding: 4px 10px;
            font-size: 12px;
            border-radius: 6px;
            gap: 4px;
        }
        
        .btn-fullscreen {
            background: var(--bg);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 8px 14px;
        }
        .btn-fullscreen:hover:not(:disabled) {
            border-color: var(--primary);
            color: var(--primary);
            background: rgba(47, 128, 237, 0.04);
        }
        
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
            min-width: 180px;
            box-shadow: var(--shadow-lg);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            z-index: 10;
            padding: 8px 0;
        }
        
        .dropdown-content.show {
            display: block;
        }
        
        .dropdown-content a,
        .dropdown-content button {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 16px;
            color: var(--text);
            text-decoration: none;
            font-size: 13px;
            transition: var(--transition);
            background: none;
            border: none;
            width: 100%;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
        }
        
        .dropdown-content a:hover,
        .dropdown-content button:hover {
            background: rgba(47, 128, 237, 0.05);
            color: var(--primary);
        }
        
        .dropdown-content a i,
        .dropdown-content button i {
            width: 18px;
            color: var(--secondary-text);
        }
        
        /* ===== STATS GRID ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
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
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-card .label {
            font-size: 13px;
            color: var(--secondary-text);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .stat-card .label i {
            color: var(--primary);
            opacity: 0.5;
        }
        
        .stat-card .value {
            font-size: 28px;
            font-weight: 700;
            margin-top: 6px;
            color: var(--text);
        }
        
        .stat-card .sub-value {
            font-size: 13px;
            color: var(--secondary-text);
            margin-top: 4px;
        }
        
        /* ===== FILTER BAR ===== */
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
            min-width: 200px;
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
        
        .filter-bar select {
            padding: 10px 14px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            background: var(--bg);
            color: var(--text);
            min-width: 140px;
            transition: var(--transition);
        }
        
        .filter-bar select:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .filter-bar .date-presets {
            display: flex;
            gap: 4px;
        }
        
        .filter-bar .date-presets .preset-btn {
            padding: 6px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg);
            color: var(--text);
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-size: 12px;
            transition: var(--transition);
        }
        
        .filter-bar .date-presets .preset-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .filter-bar .date-presets .preset-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .filter-bar .filter-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        /* ===== ALERTS ===== */
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
        
        .alert-success {
            background: #D1FAE5;
            color: #065F46;
            border-left-color: var(--accent);
        }
        
        .alert-error {
            background: #FEE2E2;
            color: #DC2626;
            border-left-color: #DC2626;
        }
        
        /* ===== TABLE ===== */
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
        
        .table-header h2 {
            font-size: 16px;
            font-weight: 600;
        }
        
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
        
        table thead {
            background: var(--bg);
        }
        
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
        
        table th:hover {
            color: var(--primary);
        }
        
        table th .sort-icon {
            margin-left: 4px;
            opacity: 0.5;
        }
        
        table th.sorted .sort-icon {
            opacity: 1;
            color: var(--primary);
        }
        
        table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
            cursor: pointer;
        }
        
        table tbody tr {
            transition: var(--transition);
            cursor: pointer;
        }
        
        table tbody tr:hover {
            background: rgba(47, 128, 237, 0.04);
        }
        
        table tbody tr:last-child td {
            border-bottom: none;
        }
        
        /* ===== BADGES ===== */
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
        
        .movement-receiving {
            background: #D1FAE5;
            color: #065F46;
        }
        
        .movement-issuance {
            background: #FEE2E2;
            color: #DC2626;
        }
        
        .movement-adjustment {
            background: #FEF3C7;
            color: #92400E;
        }
        
        .movement-transfer {
            background: #DBEAFE;
            color: #1E40AF;
        }
        
        .movement-return {
            background: #E8EAF6;
            color: #283593;
        }
        
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
        
        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--secondary-text);
        }
        
        .empty-state i {
            font-size: 40px;
            display: block;
            margin-bottom: 10px;
            opacity: 0.3;
        }
        
        /* ===== MODAL ===== */
        .modal-overlay {
            display: <?php echo ($action === 'adjust' || isset($error)) ? 'flex' : 'none'; ?>;
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
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalIn 0.3s ease;
            box-shadow: var(--shadow-lg);
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
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal .close-modal {
            margin-left: auto;
            background: none;
            border: none;
            font-size: 24px;
            color: var(--secondary-text);
            cursor: pointer;
            padding: 0 4px;
            transition: var(--transition);
        }
        
        .modal .close-modal:hover {
            color: var(--text);
        }
        
        .modal .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }
        
        .modal .detail-row:last-child {
            border-bottom: none;
        }
        
        .modal .detail-row .label {
            font-weight: 500;
            color: var(--secondary-text);
        }
        
        .modal .detail-row .value {
            font-weight: 500;
        }
        
        /* ===== FORMS ===== */
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 5px;
            color: var(--text);
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
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
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(47, 128, 237, 0.1);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 60px;
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
        
        /* ===== PAGINATION ===== */
        .pagination-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 24px;
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .pagination-bar .info {
            font-size: 13px;
            color: var(--secondary-text);
        }
        
        .pagination-bar .info strong {
            color: var(--text);
        }
        
        .pagination-controls {
            display: flex;
            gap: 4px;
            align-items: center;
            flex-wrap: wrap;
        }
        
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
        
        .pagination-controls .page-btn:hover:not(.active) {
            background: rgba(47, 128, 237, 0.05);
            border-color: var(--primary);
        }
        
        .pagination-controls .page-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .pagination-controls .page-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .pagination-controls select {
            padding: 6px 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg);
            color: var(--text);
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
        }
        
        /* ===== FULLSCREEN TOGGLE ===== */
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
        
        .fullscreen-toggle:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            transform: scale(1.05);
        }
        
        /* Transaction Detail Modal */
        #transactionModal .modal {
            max-width: 700px;
        }
        
        /* ============================================
           RESPONSIVE
           ============================================ */
        
        @media (max-width: 1024px) {
            .main-content {
                padding: 20px 24px 32px;
                width: calc(100% - 280px);
            }
            
            .top-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .top-bar-actions {
                justify-content: center;
            }
            
            .top-bar-actions .btn {
                flex: 1;
                justify-content: center;
                min-width: 120px;
            }
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
            
            .sidebar.open {
                left: 0;
                width: 300px;
                padding: 24px 16px;
            }
            
            .sidebar-toggle-btn {
                display: block;
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
            
            .sidebar-overlay.active {
                display: block;
            }
            
            .main-content {
                margin-left: 0;
                padding: 16px;
                width: 100%;
                padding-top: 16px;
            }
            
            .top-bar {
                padding: 16px;
                gap: 12px;
            }
            
            .page-title h1 {
                font-size: 18px;
            }
            
            .page-title p {
                font-size: 13px;
            }
            
            .top-bar-actions {
                width: 100%;
                flex-wrap: wrap;
            }
            
            .top-bar-actions .btn {
                flex: 1;
                min-width: 100px;
                justify-content: center;
                font-size: 13px;
                padding: 8px 14px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
            
            .stat-card {
                padding: 16px;
            }
            
            .stat-card .value {
                font-size: 22px;
            }
            
            .filter-bar {
                flex-direction: column;
            }
            
            .filter-bar .search-input {
                width: 100%;
            }
            
            .filter-bar select {
                width: 100%;
            }
            
            .filter-bar .date-presets {
                width: 100%;
                flex-wrap: wrap;
            }
            
            .filter-bar .date-presets .preset-btn {
                flex: 1;
                text-align: center;
                min-width: 60px;
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            
            .table-header h2 {
                font-size: 15px;
            }
            
            table {
                font-size: 13px;
                min-width: 500px;
            }
            
            table th,
            table td {
                padding: 10px 12px;
            }
            
            .pagination-bar {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }
            
            .pagination-controls {
                justify-content: center;
                flex-wrap: wrap;
            }
            
            .modal {
                padding: 20px;
                margin: 10px;
                max-width: 100%;
            }
            
            .modal h3 {
                font-size: 18px;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .form-actions .btn {
                width: 100%;
            }
            
            .fullscreen-toggle {
                bottom: 16px;
                right: 16px;
                width: 44px;
                height: 44px;
                font-size: 18px;
            }
        }
        
        @media (max-width: 480px) {
            .main-content {
                padding: 12px;
            }
            
            .top-bar {
                padding: 12px;
            }
            
            .top-bar-actions {
                flex-direction: column;
                align-items: stretch;
            }
            
            .top-bar-actions .btn {
                min-width: unset;
                width: 100%;
                justify-content: center;
                font-size: 13px;
                padding: 10px 14px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .table-wrapper {
                margin: 0 -12px;
            }
            
            table th,
            table td {
                padding: 8px 10px;
                font-size: 12px;
            }
            
            .status-badge {
                min-width: 60px;
                font-size: 11px;
                padding: 2px 10px;
            }
            
            .role-badge {
                min-width: 50px;
                font-size: 10px;
                padding: 2px 10px;
            }
            
            .modal {
                padding: 16px;
                margin: 8px;
            }
            
            .modal h3 {
                font-size: 16px;
            }
            
            .fullscreen-toggle {
                bottom: 12px;
                right: 12px;
                width: 40px;
                height: 40px;
                font-size: 16px;
            }
            
            .pagination-controls .page-btn {
                padding: 4px 8px;
                font-size: 12px;
                min-width: 30px;
            }
        }
        
        @media print {
            .sidebar,
            .top-bar-actions,
            .btn,
            .no-print,
            .fullscreen-toggle,
            .filter-bar {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0 !important;
                padding: 20px !important;
                width: 100% !important;
            }
            
            .table-container {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            
            .stat-card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            
            body {
                background: white !important;
                color: black !important;
            }
            
            .status-badge {
                background: #f0f0f0 !important;
                color: #333 !important;
                border: 1px solid #ccc !important;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Fullscreen Toggle Button -->
    <button class="fullscreen-toggle no-print" id="fullscreenToggle" title="Toggle Fullscreen">
        <i class="fas fa-expand"></i>
    </button>
    
    <div class="admin-layout">
        <?php include 'partials/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="top-bar">
                <div class="page-title">
                    <h1>Stock Movements</h1>
                    <p>Inventory transaction history and audit trail</p>
                </div>
                <div class="top-bar-actions">
                    <a href="stock-movements.php?action=adjust" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Record Movement
                    </a>
                    <a href="stock-movements.php?action=report_incident" class="btn btn-warning">
                        <i class="fas fa-triangle-exclamation"></i> Report Damage / Return
                    </a>
                    
                    <!-- Export Dropdown -->
                    <div class="dropdown">
                        <button class="btn btn-outline" onclick="toggleExportDropdown()">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <div class="dropdown-content" id="exportDropdown">
                            <a href="stock-movements.php?action=export&format=pdf<?php echo '&date_from=' . $dateFrom . '&date_to=' . $dateTo . (!empty($typeFilter) ? '&type=' . $typeFilter : '') . (!empty($productFilter) ? '&product_id=' . $productFilter : '') . (!empty($warehouseFilter) ? '&warehouse_id=' . $warehouseFilter : '') . (!empty($userFilter) ? '&user_id=' . $userFilter : '') . (!empty($search) ? '&search=' . urlencode($search) : ''); ?>">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </a>
                            <a href="stock-movements.php?action=export&format=excel<?php echo '&date_from=' . $dateFrom . '&date_to=' . $dateTo . (!empty($typeFilter) ? '&type=' . $typeFilter : '') . (!empty($productFilter) ? '&product_id=' . $productFilter : '') . (!empty($warehouseFilter) ? '&warehouse_id=' . $warehouseFilter : '') . (!empty($userFilter) ? '&user_id=' . $userFilter : '') . (!empty($search) ? '&search=' . urlencode($search) : ''); ?>">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </a>
                        </div>
                    </div>
                    
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
            
            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="label"><i class="fas fa-exchange-alt"></i> Total Transactions</div>
                    <div class="value"><?php echo number_format($stats['total'] ?? 0); ?></div>
                </div>
                <div class="stat-card" style="border-color: var(--accent);">
                    <div class="label"><i class="fas fa-arrow-down" style="color: var(--accent);"></i> Received</div>
                    <div class="value" style="color: var(--accent);"><?php echo number_format($stats['total_received'] ?? 0); ?></div>
                </div>
                <div class="stat-card" style="border-color: #DC2626;">
                    <div class="label"><i class="fas fa-arrow-up" style="color: #DC2626;"></i> Issued</div>
                    <div class="value" style="color: #DC2626;"><?php echo number_format($stats['total_issued'] ?? 0); ?></div>
                </div>
                <div class="stat-card" style="border-color: #F59E0B;">
                    <div class="label"><i class="fas fa-edit" style="color: #F59E0B;"></i> Adjusted</div>
                    <div class="value" style="color: #F59E0B;"><?php echo number_format($stats['total_adjusted'] ?? 0); ?></div>
                </div>
            </div>
            
            <!-- Search & Filter Bar -->
            <div class="filter-bar">
                <form method="GET" style="display: flex; gap: 12px; flex-wrap: wrap; width: 100%;">
                    <input type="hidden" name="date_preset" id="datePresetInput" value="<?php echo $datePreset; ?>">
                    <input type="hidden" name="date_from" id="dateFromInput" value="<?php echo $dateFrom; ?>">
                    <input type="hidden" name="date_to" id="dateToInput" value="<?php echo $dateTo; ?>">
                    
                    <input type="text" name="search" class="search-input" 
                           placeholder="Search by SKU, product, or reference..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    
                    <select name="product_id">
                        <option value="">All Products</option>
                        <?php foreach ($products as $product): ?>
                        <option value="<?php echo $product['id']; ?>" <?php echo $productFilter == $product['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($product['sku'] . ' - ' . $product['product_name']); ?>
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
                    
                    <select name="type">
                        <option value="">All Types</option>
                        <option value="receiving" <?php echo $typeFilter === 'receiving' ? 'selected' : ''; ?>>Receiving</option>
                        <option value="issuance" <?php echo $typeFilter === 'issuance' ? 'selected' : ''; ?>>Issuance</option>
                        <option value="adjustment" <?php echo $typeFilter === 'adjustment' ? 'selected' : ''; ?>>Adjustment</option>
                        <option value="transfer" <?php echo $typeFilter === 'transfer' ? 'selected' : ''; ?>>Transfer</option>
                        <option value="return" <?php echo $typeFilter === 'return' ? 'selected' : ''; ?>>Return</option>
                    </select>
                    
                    <select name="user_id">
                        <option value="">All Users</option>
                        <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>" <?php echo $userFilter == $user['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['full_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <div class="date-presets">
                        <button type="button" class="preset-btn <?php echo $datePreset === 'today' ? 'active' : ''; ?>" onclick="setDatePreset('today')">Today</button>
                        <button type="button" class="preset-btn <?php echo $datePreset === 'last_7' ? 'active' : ''; ?>" onclick="setDatePreset('last_7')">Last 7 Days</button>
                        <button type="button" class="preset-btn <?php echo $datePreset === 'this_month' ? 'active' : ''; ?>" onclick="setDatePreset('this_month')">This Month</button>
                        <button type="button" class="preset-btn <?php echo $datePreset === 'last_30' ? 'active' : ''; ?>" onclick="setDatePreset('last_30')">Last 30 Days</button>
                        <button type="button" class="preset-btn <?php echo $datePreset === 'custom' ? 'active' : ''; ?>" onclick="showCustomDate()">Custom</button>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <?php if (!empty($search) || !empty($productFilter) || !empty($typeFilter) || !empty($warehouseFilter) || !empty($userFilter) || $datePreset !== 'last_30'): ?>
                        <a href="stock-movements.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <div class="table-container">
                <div class="table-header">
                    <h2><i class="fas fa-clipboard-check"></i> Damage and Return Incidents</h2>
                    <span class="role-badge"><?php echo count(array_filter($incidents, static function ($incident) { return $incident['status'] === 'reported'; })); ?> awaiting review</span>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Incident</th>
                                <th>Reported</th>
                                <th>Item</th>
                                <th>Issue</th>
                                <th>Qty</th>
                                <th onclick="sortTable('warehouse_name')" class="<?php echo $sortField === 'warehouse_name' ? 'sorted' : ''; ?>">
                                    Warehouse <span class="sort-icon"><?php echo $sortField === 'warehouse_name' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th onclick="sortTable('reference_document')" class="<?php echo $sortField === 'reference_document' ? 'sorted' : ''; ?>">
                                    Reference <span class="sort-icon"><?php echo $sortField === 'reference_document' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th>Description</th>
                                <th>Status / Resolution</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($incidents): ?>
                                <?php foreach ($incidents as $incident): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($incident['incident_number']); ?></strong></td>
                                    <td><?php echo date('M d, Y', strtotime($incident['created_at'])); ?><br><small><?php echo htmlspecialchars($incident['reporter_name'] ?? 'Unknown'); ?></small></td>
                                    <td><?php echo htmlspecialchars($incident['sku'] . ' - ' . $incident['product_name']); ?></td>
                                    <td><?php echo $incident['issue_type'] === 'damaged' ? 'Damaged in warehouse' : 'Customer return'; ?></td>
                                    <td><?php echo number_format($incident['quantity']); ?></td>
                                    <td><?php echo htmlspecialchars($incident['warehouse_name']); ?></td>
                                    <td><?php echo htmlspecialchars($incident['description'] ?? ''); ?></td>
                                    <td>
                                        <?php if ($incident['status'] === 'reported'): ?>
                                        <form method="POST" action="stock-movements.php?action=resolve_incident" style="display:flex;gap:6px;min-width:210px;">
                                            <input type="hidden" name="incident_id" value="<?php echo (int)$incident['id']; ?>">
                                            <select name="resolution" required aria-label="Incident resolution">
                                                <option value="">Resolve as...</option>
                                                <option value="restock">Return to usable stock</option>
                                                <option value="supplier_return">Return to supplier</option>
                                                <option value="write_off">Write off</option>
                                            </select>
                                            <button type="submit" class="btn btn-primary btn-sm" title="Resolve incident"><i class="fas fa-check"></i></button>
                                        </form>
                                        <?php else: ?>
                                        <span class="status-badge status-active">Resolved: <?php echo htmlspecialchars(str_replace('_', ' ', $incident['resolution'] ?? '')); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="empty-state">No damage or return incidents reported.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="table-container">
                <div class="table-header">
                    <h2>Transaction History</h2>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <span class="role-badge"><?php echo count($transactions); ?> transactions</span>
                        <span class="role-badge">Page <?php echo $pageNum; ?> of <?php echo max(1, $totalPages); ?></span>
                    </div>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th onclick="sortTable('created_at')" class="<?php echo $sortField === 'created_at' ? 'sorted' : ''; ?>">
                                    Date/Time <span class="sort-icon"><?php echo $sortField === 'created_at' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th onclick="sortTable('product_name')" class="<?php echo $sortField === 'product_name' ? 'sorted' : ''; ?>">
                                    Product <span class="sort-icon"><?php echo $sortField === 'product_name' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th onclick="sortTable('sku')" class="<?php echo $sortField === 'sku' ? 'sorted' : ''; ?>">
                                    SKU <span class="sort-icon"><?php echo $sortField === 'sku' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th onclick="sortTable('transaction_type')" class="<?php echo $sortField === 'transaction_type' ? 'sorted' : ''; ?>">
                                    Type <span class="sort-icon"><?php echo $sortField === 'transaction_type' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th>Warehouse</th>
                                <th onclick="sortTable('quantity')" class="<?php echo $sortField === 'quantity' ? 'sorted' : ''; ?>">
                                    Qty <span class="sort-icon"><?php echo $sortField === 'quantity' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th onclick="sortTable('previous_balance')" class="<?php echo $sortField === 'previous_balance' ? 'sorted' : ''; ?>">
                                    Previous <span class="sort-icon"><?php echo $sortField === 'previous_balance' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th onclick="sortTable('new_balance')" class="<?php echo $sortField === 'new_balance' ? 'sorted' : ''; ?>">
                                    New <span class="sort-icon"><?php echo $sortField === 'new_balance' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th onclick="sortTable('user_name')" class="<?php echo $sortField === 'user_name' ? 'sorted' : ''; ?>">
                                    User <span class="sort-icon"><?php echo $sortField === 'user_name' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($transactions)): ?>
                                <?php foreach ($transactions as $t): ?>
                                <tr onclick="openTransactionDetail(<?php echo htmlspecialchars(json_encode($t)); ?>)">
                                    <td style="font-size: 13px; color: var(--secondary-text); white-space: nowrap;">
                                        <?php echo date('M d, Y h:i A', strtotime($t['created_at'])); ?>
                                    </td>
                                    <td>
                                        <div><strong><?php echo htmlspecialchars($t['product_name']); ?></strong></div>
                                    </td>
                                    <td style="font-family: monospace; font-size: 13px;">
                                        <?php echo htmlspecialchars($t['sku']); ?>
                                    </td>
                                    <td>
                                        <span class="status-badge movement-<?php echo $t['transaction_type']; ?>">
                                            <?php echo ucfirst($t['transaction_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($t['warehouse_name'] ?? 'Unassigned'); ?></td>
                                    <td><?php echo htmlspecialchars($t['reference_document'] ?? ''); ?></td>
                                    <?php $isOutMovement = $t['transaction_type'] === 'issuance' || (int)$t['new_balance'] < (int)$t['previous_balance']; ?>
                                    <td style="font-weight: 600; color: <?php echo $isOutMovement ? '#DC2626' : (in_array($t['transaction_type'], ['receiving', 'return', 'transfer'], true) ? 'var(--accent)' : '#F59E0B'); ?>;">
                                        <?php echo $isOutMovement ? '-' : '+'; ?>
                                        <?php echo number_format($t['quantity']); ?>
                                    </td>
                                    <td><?php echo number_format($t['previous_balance']); ?></td>
                                    <td><?php echo number_format($t['new_balance']); ?></td>
                                    <td><?php echo htmlspecialchars($t['user_name'] ?? 'System'); ?></td>
                                    <td style="font-size: 13px; color: var(--secondary-text); max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?php echo htmlspecialchars($t['notes'] ?? ''); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" class="empty-state">
                                        <i class="fas fa-exchange-alt"></i>
                                        <p>No transactions found for this period</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalItems > 0): ?>
                <div class="pagination-bar">
                    <div class="info">
                        Showing <strong><?php echo $totalItems > 0 ? $offset + 1 : 0; ?></strong> 
                        to <strong><?php echo min($offset + $itemsPerPage, $totalItems); ?></strong> 
                        of <strong><?php echo $totalItems; ?></strong> transactions
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

    <div id="incidentModal" class="modal-overlay" style="display: <?php echo $action === 'report_incident' ? 'flex' : 'none'; ?>;">
        <div class="modal">
            <h3><i class="fas fa-triangle-exclamation" style="color:#D97706;"></i> Report Damaged Item / Return</h3>
            <?php if ($action === 'report_incident' && isset($error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST" action="stock-movements.php?action=report_incident">
                <div class="form-group">
                    <label>Warehouse *</label>
                    <select name="warehouse_id" required>
                        <?php foreach ($warehouses as $warehouse): ?>
                        <option value="<?php echo (int)$warehouse['id']; ?>" <?php echo $warehouseFilter === (int)$warehouse['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($warehouse['name'] . ' (' . $warehouse['warehouse_code'] . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Item *</label>
                    <select name="product_id" required>
                        <option value="">Select item</option>
                        <?php foreach ($products as $product): ?>
                        <option value="<?php echo (int)$product['id']; ?>"><?php echo htmlspecialchars($product['sku'] . ' - ' . $product['product_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Issue Type *</label>
                    <select name="issue_type" required>
                        <option value="damaged">Damaged in warehouse</option>
                        <option value="customer_return">Customer return</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Quantity *</label>
                    <input type="number" name="quantity" min="1" required>
                </div>
                <div class="form-group">
                    <label>Description / reference</label>
                    <textarea name="description" rows="3" placeholder="Describe the condition and reference the return or inspection record."></textarea>
                </div>
                <div class="form-actions">
                    <a href="stock-movements.php" class="btn btn-outline">Cancel</a>
                    <button type="submit" class="btn btn-warning"><i class="fas fa-flag"></i> Report Incident</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Adjust Stock Modal -->
    <div id="adjustModal" class="modal-overlay" style="display: <?php echo ($action === 'adjust' || isset($error)) ? 'flex' : 'none'; ?>;">
        <div class="modal">
            <h3>
                <i class="fas fa-edit" style="color: var(--primary);"></i>
                Manual Stock Adjustment
                <button type="button" class="close-modal" onclick="closeAdjustModal()">&times;</button>
            </h3>
            <form method="POST" action="stock-movements.php?action=adjust" onsubmit="return validateAdjustForm()">
                <div class="form-group">
                    <label>Warehouse *</label>
                    <select name="warehouse_id" required>
                        <?php foreach ($warehouses as $warehouse): ?>
                        <option value="<?php echo (int)$warehouse['id']; ?>" <?php echo $warehouseFilter === (int)$warehouse['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($warehouse['name'] . ' (' . $warehouse['warehouse_code'] . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Product *</label>
                    <input type="text" id="productSearch" list="productOptions" autocomplete="off"
                           placeholder="search product" oninput="resolveProduct(this)" required>
                    <input type="hidden" name="product_id" id="productIdInput">
                    <datalist id="productOptions">
                        <?php foreach ($products as $product): ?>
                        <option value="<?php echo htmlspecialchars($product['sku'] . ' - ' . $product['product_name']); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                
                <div class="form-group">
                    <label>Adjustment Type *</label>
                    <select name="adjustment_type" required onchange="toggleTransferWarehouse(this)">
                        <option value="add">Add Stock (Receiving)</option>
                        <option value="remove">Remove Stock (Issuance)</option>
                        <option value="transfer">Transfer to Another Warehouse</option>
                    </select>
                </div>

                <div class="form-group" id="destinationWarehouseGroup" style="display:none;">
                    <label>Destination Warehouse *</label>
                    <select name="destination_warehouse_id" id="destinationWarehouseId" disabled>
                        <option value="">Select destination</option>
                        <?php foreach ($warehouses as $warehouse): ?>
                        <option value="<?php echo (int)$warehouse['id']; ?>">
                            <?php echo htmlspecialchars($warehouse['name'] . ' (' . $warehouse['warehouse_code'] . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Quantity *</label>
                    <input type="number" name="quantity" required min="1" placeholder="Enter quantity">
                </div>
                
                <div class="form-group">
                    <label>Reference Document</label>
                    <input type="text" name="reference" placeholder="e.g., PO-001, SO-001">
                </div>
                
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" rows="3" placeholder="Reason for adjustment..."></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-outline" onclick="closeAdjustModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Apply Adjustment
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Transaction Detail Modal -->
    <div id="transactionModal" class="modal-overlay" style="display: none;">
        <div class="modal">
            <h3>
                <i class="fas fa-info-circle" style="color: var(--primary);"></i>
                Transaction Details
                <button type="button" class="close-modal" onclick="closeTransactionDetail()">&times;</button>
            </h3>
            <div id="transactionDetailContent">
                <!-- Populated by JavaScript -->
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-outline" onclick="closeTransactionDetail()">Close</button>
            </div>
        </div>
    </div>
    
    <script>
        // ============================================
        // PRODUCT NAME -> ID RESOLUTION
        // ============================================
        var productsMap = {};
        <?php foreach ($products as $product): ?>
        productsMap[<?php echo json_encode($product['sku'] . ' - ' . $product['product_name']); ?>] = <?php echo json_encode((string)$product['id']); ?>;
        <?php endforeach; ?>
        
        function resolveProduct(el) {
            var hidden = document.getElementById('productIdInput');
            hidden.value = productsMap[el.value] || '';
        }
        
        function validateAdjustForm() {
            var productId = document.getElementById('productIdInput').value;
            if (!productId) {
                alert('Please select a valid product from the list.');
                return false;
            }
            var adjustmentType = document.querySelector('#adjustModal select[name="adjustment_type"]').value;
            if (adjustmentType === 'transfer') {
                var source = document.querySelector('#adjustModal select[name="warehouse_id"]').value;
                var destination = document.getElementById('destinationWarehouseId').value;
                if (!destination || source === destination) {
                    alert('Select a different destination warehouse.');
                    return false;
                }
            }
            return true;
        }

        function toggleTransferWarehouse(select) {
            var group = document.getElementById('destinationWarehouseGroup');
            var destination = document.getElementById('destinationWarehouseId');
            var visible = select.value === 'transfer';
            group.style.display = visible ? 'block' : 'none';
            destination.disabled = !visible;
            destination.required = visible;
            if (!visible) destination.value = '';
        }

        function closeAdjustModal() {
            var modal = document.getElementById('adjustModal');
            if (modal) {
                modal.style.display = 'none';
            }
            var url = new URL(window.location.href);
            url.searchParams.delete('action');
            window.history.replaceState({}, document.title, url.toString());
        }
        
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
        // DATE PRESETS
        // ============================================
        function setDatePreset(preset) {
            document.getElementById('datePresetInput').value = preset;
            
            var today = new Date();
            var dateFrom, dateTo;
            
            switch(preset) {
                case 'today':
                    dateFrom = formatDate(today);
                    dateTo = formatDate(today);
                    break;
                case 'last_7':
                    var last7 = new Date(today);
                    last7.setDate(last7.getDate() - 7);
                    dateFrom = formatDate(last7);
                    dateTo = formatDate(today);
                    break;
                case 'this_month':
                    dateFrom = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-01';
                    dateTo = formatDate(today);
                    break;
                case 'last_30':
                    var last30 = new Date(today);
                    last30.setDate(last30.getDate() - 30);
                    dateFrom = formatDate(last30);
                    dateTo = formatDate(today);
                    break;
                case 'custom':
                    var fromInput = prompt('Enter start date (YYYY-MM-DD):', document.getElementById('dateFromInput').value);
                    var toInput = prompt('Enter end date (YYYY-MM-DD):', document.getElementById('dateToInput').value);
                    if (!fromInput || !toInput) return;
                    dateFrom = fromInput;
                    dateTo = toInput;
                    break;
            }
            
            document.getElementById('dateFromInput').value = dateFrom;
            document.getElementById('dateToInput').value = dateTo;
            
            // Submit the form
            document.querySelector('.filter-bar form').submit();
        }
        
        function formatDate(date) {
            var year = date.getFullYear();
            var month = String(date.getMonth() + 1).padStart(2, '0');
            var day = String(date.getDate()).padStart(2, '0');
            return year + '-' + month + '-' + day;
        }
        
        function showCustomDate() {
            setDatePreset('custom');
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
        // TRANSACTION DETAIL MODAL
        // ============================================
        function openTransactionDetail(transaction) {
            var modal = document.getElementById('transactionModal');
            var content = document.getElementById('transactionDetailContent');
            
            var typeColors = {
                'receiving': 'var(--accent)',
                'issuance': '#DC2626',
                'adjustment': '#F59E0B',
                'transfer': '#1E40AF',
                'return': '#283593'
            };
            
            var typeIcon = {
                'receiving': 'fa-arrow-down',
                'issuance': 'fa-arrow-up',
                'adjustment': 'fa-edit',
                'transfer': 'fa-exchange-alt',
                'return': 'fa-undo'
            };
            
            var isOutMovement = transaction.transaction_type === 'issuance' || Number(transaction.new_balance) < Number(transaction.previous_balance);
            var typeColor = isOutMovement ? '#DC2626' : (['receiving', 'return', 'transfer'].includes(transaction.transaction_type) ? 'var(--accent)' : (typeColors[transaction.transaction_type] || 'var(--text)'));
            var icon = typeIcon[transaction.transaction_type] || 'fa-circle';
            
            content.innerHTML = `
                <div style="margin-bottom: 20px; padding: 16px; background: var(--bg); border-radius: var(--radius-sm); border-left: 4px solid ${typeColor};">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <i class="fas ${icon}" style="color: ${typeColor}; font-size: 24px;"></i>
                        <div>
                            <div style="font-size: 18px; font-weight: 600;">${transaction.product_name}</div>
                            <div style="font-size: 13px; color: var(--secondary-text);">SKU: ${transaction.sku}</div>
                        </div>
                    </div>
                </div>
                
                <div class="detail-row">
                    <span class="label">Date/Time</span>
                    <span class="value">${new Date(transaction.created_at).toLocaleString()}</span>
                </div>
                <div class="detail-row">
                    <span class="label">Transaction Type</span>
                    <span class="value" style="color: ${typeColor};">
                        <span class="status-badge movement-${transaction.transaction_type}">
                            ${transaction.transaction_type.charAt(0).toUpperCase() + transaction.transaction_type.slice(1)}
                        </span>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="label">Quantity</span>
                    <span class="value" style="color: ${transaction.transaction_type === 'receiving' ? 'var(--accent)' : '#DC2626'};">
                        ${isOutMovement ? '-' : '+'} ${Number(transaction.quantity).toLocaleString()}
                    </span>
                </div>
                <div class="detail-row">
                    <span class="label">Previous Balance</span>
                    <span class="value">${Number(transaction.previous_balance).toLocaleString()}</span>
                </div>
                <div class="detail-row">
                    <span class="label">New Balance</span>
                    <span class="value">${Number(transaction.new_balance).toLocaleString()}</span>
                </div>
                <div class="detail-row">
                    <span class="label">User</span>
                    <span class="value">${transaction.user_name || 'System'}</span>
                </div>
                ${transaction.reference_document ? `
                <div class="detail-row">
                    <span class="label">Reference Document</span>
                    <span class="value">${transaction.reference_document}</span>
                </div>
                ` : ''}
                ${transaction.notes ? `
                <div class="detail-row">
                    <span class="label">Notes</span>
                    <span class="value">${transaction.notes}</span>
                </div>
                ` : ''}
                <div class="detail-row">
                    <span class="label">Transaction ID</span>
                    <span class="value" style="font-family: monospace; font-size: 13px;">#${transaction.id}</span>
                </div>
            `;
            
            modal.style.display = 'flex';
        }
        
        function closeTransactionDetail() {
            document.getElementById('transactionModal').style.display = 'none';
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
                    if (this.id === 'adjustModal') {
                        closeAdjustModal();
                    } else {
                        this.style.display = 'none';
                    }
                }
            });
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay').forEach(function(modal) {
                    if (modal.style.display === 'flex') {
                        if (modal.id === 'adjustModal') {
                            closeAdjustModal();
                        } else {
                            modal.style.display = 'none';
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>