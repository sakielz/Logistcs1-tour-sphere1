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

try {
    $stmt = $pdo->query("SELECT id, name, warehouse_code FROM warehouses WHERE status = 'active' AND is_archived = false ORDER BY name");
    $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $warehouses = [];
}

// ============================================
// GET PRODUCTS FOR DROPDOWN
// ============================================
try {
    $stmt = $pdo->query("SELECT id, sku, product_name, unit_price, current_stock, reorder_quantity, reorder_point, max_stock FROM products WHERE status = 'active' AND is_archived = false ORDER BY product_name");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $products = [];
}

$supplierBatchSuggestions = [];
try {
    $suggestionStmt = $pdo->query("SELECT supplier_id, product_id, ROUND(AVG(received_quantity)) AS suggested_quantity
                                  FROM inventory_batches
                                  WHERE quality_status = 'accepted' AND received_quantity > 0
                                  GROUP BY supplier_id, product_id");
    foreach ($suggestionStmt->fetchAll(PDO::FETCH_ASSOC) as $suggestion) {
        $supplierBatchSuggestions[(string)$suggestion['supplier_id']][(string)$suggestion['product_id']] = (int)$suggestion['suggested_quantity'];
    }
} catch (PDOException $e) {
    $supplierBatchSuggestions = [];
}

$prefillProduct = null;
$prefillQuantity = max(1, (int)($_GET['quantity'] ?? 1));
foreach ($products as $product) {
    if ((int)$product['id'] === (int)($_GET['product_id'] ?? 0)) {
        $prefillProduct = $product;
        if (!isset($_GET['quantity'])) {
            $prefillQuantity = max(1, (int)$product['reorder_quantity']);
        }
        break;
    }
}

// ============================================
// EXPORT FUNCTIONALITY
// ============================================
if ($action === 'export_receipt' && isset($_GET['format'], $_GET['receipt_number'])) {
    $receiptNumber = trim((string)$_GET['receipt_number']);
    $stmt = $pdo->prepare("SELECT b.receipt_number, po.po_number, s.company_name AS supplier, p.sku, p.product_name, b.brand_snapshot, b.batch_number, b.received_quantity, b.expiry_date, b.quality_status, b.quality_notes, w.name AS warehouse, b.received_at
                           FROM inventory_batches b
                           JOIN purchase_orders po ON po.id = b.po_id
                           LEFT JOIN suppliers s ON s.id = b.supplier_id
                           JOIN products p ON p.id = b.product_id
                           JOIN warehouses w ON w.id = po.warehouse_id
                           WHERE b.receipt_number = ? AND b.origin_batch_id IS NULL ORDER BY b.id");
    $stmt->execute([$receiptNumber]);
    $receiptData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$receiptData) {
        $_SESSION['error'] = 'Goods receipt not found.';
        header('Location: purchase-orders.php');
        exit();
    }
    require_once __DIR__ . '/../includes/report_export.php';
    exportTrackedReport($pdo, (int)$_SESSION['user_id'], 'inventory_receipts', 'Goods Receipt ' . $receiptNumber, strtolower(trim((string)$_GET['format'])), $receiptData);
}

if ($action === 'export' && isset($_GET['format'])) {
    $format = $_GET['format'];
    $statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
    $paymentMethodFilter = isset($_GET['payment_method']) ? trim($_GET['payment_method']) : '';
    $minAmount = isset($_GET['min_amount']) && $_GET['min_amount'] !== '' ? max(0, (float)$_GET['min_amount']) : null;
    $maxAmount = isset($_GET['max_amount']) && $_GET['max_amount'] !== '' ? max(0, (float)$_GET['max_amount']) : null;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
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
        if ($paymentMethodFilter !== '') {
            $query .= ' AND po.payment_method = ?';
            $params[] = $paymentMethodFilter;
        }
        if ($minAmount !== null) {
            $query .= ' AND po.total_amount >= ?';
            $params[] = $minAmount;
        }
        if ($maxAmount !== null) {
            $query .= ' AND po.total_amount <= ?';
            $params[] = $maxAmount;
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

        require_once __DIR__ . '/../includes/report_export.php';
        exportTrackedReport($pdo, (int)$_SESSION['user_id'], 'purchase_orders', 'Purchase Orders', $format, $data);
        
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
    $warehouse_id = isset($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : 0;
    $payment_method = trim((string)($_POST['payment_method'] ?? ''));
    $terms = isset($_POST['terms']) ? trim($_POST['terms']) : '';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    
    // Generate PO number
    $po_number = 'PO-' . date('Ymd') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
    
    try {
        if (!in_array($payment_method, ['cash', 'digital_cash', 'credit'], true)) {
            throw new RuntimeException('Choose cash, digital cash, or credits as the payment method.');
        }
        $warehouseCheck = $pdo->prepare("SELECT id FROM warehouses WHERE id = ? AND status = 'active' AND is_archived = false");
        $warehouseCheck->execute([$warehouse_id]);
        if (!$warehouseCheck->fetchColumn()) {
            throw new RuntimeException('Select an active receiving warehouse.');
        }

        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("INSERT INTO purchase_orders (po_number, supplier_id, warehouse_id, order_date, expected_delivery, shipping_address, payment_method, terms, notes, status, approval_status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending_review', ?)");
        $stmt->execute([$po_number, $supplier_id, $warehouse_id, $order_date, $expected_delivery, $shipping_address, $payment_method, $terms, $notes, $_SESSION['user_id']]);
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
        
        logAudit($_SESSION['user_id'], 'create_po', 'purchase_order', "Created PO: $po_number using payment method: $payment_method");
        $_SESSION['success'] = "Purchase Order $po_number created successfully!";
        header('Location: purchase-orders.php');
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Error creating PO: " . $e->getMessage();
    }
}

// ============================================
// UPDATE PO STATUS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'receive') {
    $id = (int)($_POST['id'] ?? 0);
    $receiptNumber = 'GRN-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));

    try {
        $pdo->beginTransaction();
        $poStmt = $pdo->prepare("SELECT po_number, supplier_id, warehouse_id, status FROM purchase_orders WHERE id = ?");
        $poStmt->execute([$id]);
        $po = $poStmt->fetch(PDO::FETCH_ASSOC);
        if (!$po || !in_array($po['status'], ['approved', 'shipped'], true)) {
            throw new RuntimeException('Only approved or shipped purchase orders can be received.');
        }

        $itemsStmt = $pdo->prepare("SELECT poi.id, poi.product_id, poi.quantity, poi.received_quantity, p.brand, p.product_name
                                    FROM purchase_order_items poi JOIN products p ON p.id = poi.product_id
                                    WHERE poi.po_id = ?");
        $itemsStmt->execute([$id]);
        $poItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$poItems) throw new RuntimeException('This purchase order has no items.');

        $receiptLines = [];
        $acceptedTotal = 0;
        $firstBatchId = null;
        foreach ($poItems as $poItem) {
            $line = $_POST['items'][(int)$poItem['id']] ?? [];
            $quantity = max(0, (int)($line['quantity'] ?? 0));
            if ($quantity === 0) continue;

            $remaining = (int)$poItem['quantity'] - (int)$poItem['received_quantity'];
            $batchNumber = trim((string)($line['batch_number'] ?? ''));
            $expiryDate = trim((string)($line['expiry_date'] ?? ''));
            $qualityStatus = (string)($line['quality_status'] ?? 'rejected');
            $qualityNotes = trim((string)($line['quality_notes'] ?? ''));
            if ($quantity > $remaining) throw new RuntimeException('Received quantity exceeds the outstanding quantity for ' . $poItem['product_name'] . '.');
            if ($batchNumber === '') throw new RuntimeException('Enter a supplier batch number for each received line.');
            if (!in_array($qualityStatus, ['accepted', 'rejected'], true)) throw new RuntimeException('Choose an inspection result for each received line.');
            if ($expiryDate !== '' && !DateTime::createFromFormat('!Y-m-d', $expiryDate)) throw new RuntimeException('Enter a valid expiry date.');
            if ($qualityStatus === 'accepted' && $expiryDate !== '' && $expiryDate < date('Y-m-d')) {
                throw new RuntimeException('Expired batches cannot be accepted into usable stock.');
            }

            $availableQuantity = $qualityStatus === 'accepted' ? $quantity : 0;
            $batchStmt = $pdo->prepare("INSERT INTO inventory_batches (receipt_number, po_id, po_item_id, product_id, supplier_id, warehouse_id, batch_number, received_quantity, available_quantity, expiry_date, brand_snapshot, quality_status, quality_notes, received_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $batchStmt->execute([$receiptNumber, $id, $poItem['id'], $poItem['product_id'], $po['supplier_id'], $po['warehouse_id'], $batchNumber, $quantity, $availableQuantity, $expiryDate !== '' ? $expiryDate : null, $poItem['brand'], $qualityStatus, $qualityNotes, $_SESSION['user_id']]);
            $batchId = (int)$pdo->lastInsertId();
            if ($firstBatchId === null) $firstBatchId = $batchId;

            $receivedStmt = $pdo->prepare('UPDATE purchase_order_items SET received_quantity = received_quantity + ? WHERE id = ?');
            $receivedStmt->execute([$quantity, $poItem['id']]);

            if ($availableQuantity > 0) {
                $balanceStmt = $pdo->prepare('SELECT quantity FROM warehouse_inventory WHERE product_id = ? AND warehouse_id = ?');
                $balanceStmt->execute([$poItem['product_id'], $po['warehouse_id']]);
                $balanceValue = $balanceStmt->fetchColumn();
                $previousBalance = (int)($balanceValue ?: 0);
                $newBalance = $previousBalance + $availableQuantity;
                if ($balanceValue === false) {
                    $balanceStmt = $pdo->prepare('INSERT INTO warehouse_inventory (product_id, warehouse_id, quantity) VALUES (?, ?, ?)');
                    $balanceStmt->execute([$poItem['product_id'], $po['warehouse_id'], $newBalance]);
                } else {
                    $balanceStmt = $pdo->prepare('UPDATE warehouse_inventory SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE product_id = ? AND warehouse_id = ?');
                    $balanceStmt->execute([$newBalance, $poItem['product_id'], $po['warehouse_id']]);
                }
                $balanceStmt = $pdo->prepare('UPDATE products SET current_stock = (SELECT COALESCE(SUM(quantity), 0) FROM warehouse_inventory WHERE product_id = ?) WHERE id = ?');
                $balanceStmt->execute([$poItem['product_id'], $poItem['product_id']]);
                $movementStmt = $pdo->prepare("INSERT INTO inventory_transactions (product_id, transaction_type, quantity, previous_balance, new_balance, reference_document, warehouse_id, notes, created_by) VALUES (?, 'receiving', ?, ?, ?, ?, ?, ?, ?)");
                $movementStmt->execute([$poItem['product_id'], $availableQuantity, $previousBalance, $newBalance, $receiptNumber, $po['warehouse_id'], 'Accepted batch ' . $batchNumber . ' from ' . $po['po_number'], $_SESSION['user_id']]);
                $acceptedTotal += $availableQuantity;
            }

            $receiptLines[] = $poItem['product_name'] . ': ' . $quantity . ' (' . $qualityStatus . ')';
        }

        if (!$receiptLines) throw new RuntimeException('Enter a received quantity for at least one item.');
        $remainingStmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_order_items WHERE po_id = ? AND received_quantity < quantity');
        $remainingStmt->execute([$id]);
        $nextStatus = (int)$remainingStmt->fetchColumn() === 0 ? 'received' : 'shipped';
        $updatePo = $pdo->prepare('UPDATE purchase_orders SET status = ? WHERE id = ?');
        $updatePo->execute([$nextStatus, $id]);

        $description = 'Receipt ' . $receiptNumber . ' for ' . $po['po_number'] . '. Accepted units: ' . $acceptedTotal . '. ' . implode('; ', $receiptLines);
        $documentStmt = $pdo->prepare("INSERT INTO documents (document_number, document_type, title, description, related_module, related_id, status, created_by) VALUES (?, 'report', ?, ?, 'inventory_batches', ?, 'approved', ?)");
        $documentStmt->execute([$receiptNumber, 'Goods Receipt ' . $receiptNumber, $description, $firstBatchId, $_SESSION['user_id']]);
        $pdo->commit();

        logAudit($_SESSION['user_id'], 'receive_purchase_order', 'purchase_order', 'Recorded ' . $receiptNumber . ' against PO ' . $po['po_number']);
        header('Location: purchase-orders.php?action=receipt&receipt_number=' . urlencode($receiptNumber));
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = 'Could not record receipt: ' . $e->getMessage();
        header('Location: purchase-orders.php?action=receive&id=' . $id);
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_status') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $status = isset($_POST['status']) ? $_POST['status'] : '';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    
    try {
        $pdo->beginTransaction();
        $poLookup = $pdo->prepare("SELECT status, warehouse_id, po_number FROM purchase_orders WHERE id = ?");
        $poLookup->execute([$id]);
        $existingPO = $poLookup->fetch(PDO::FETCH_ASSOC);
        if (!$existingPO) throw new RuntimeException('Purchase order not found.');
        if ($status === 'received') throw new RuntimeException('Use the receiving workflow to record quantities and inspection results.');

        $stmt = $pdo->prepare("UPDATE purchase_orders SET status = ?, notes = ? WHERE id = ?");
        $stmt->execute([$status, $notes, $id]);
        
        if ($status === 'approved') {
            $stmt = $pdo->prepare("UPDATE purchase_orders SET approval_status = 'approved', approved_by = ? WHERE id = ?");
            $stmt->execute([$_SESSION['user_id'], $id]);
        } elseif ($status === 'rejected') {
            $stmt = $pdo->prepare("UPDATE purchase_orders SET approval_status = 'rejected' WHERE id = ?");
            $stmt->execute([$id]);
        }

        $pdo->commit();
        
        logAudit($_SESSION['user_id'], 'update_po_status', 'purchase_order', "Updated PO ID $id to status: $status");
        $_SESSION['success'] = "PO status updated successfully!";
        header('Location: purchase-orders.php');
        exit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Error updating PO: " . $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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
$receivePO = null;
$receiveItems = [];
$receiptRows = [];
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

if ($action === 'receive' && isset($_GET['id'])) {
    $receiveStmt = $pdo->prepare("SELECT po.*, s.company_name, w.name AS warehouse_name
                                  FROM purchase_orders po
                                  LEFT JOIN suppliers s ON s.id = po.supplier_id
                                  LEFT JOIN warehouses w ON w.id = po.warehouse_id
                                  WHERE po.id = ?");
    $receiveStmt->execute([(int)$_GET['id']]);
    $receivePO = $receiveStmt->fetch(PDO::FETCH_ASSOC);
    if ($receivePO && in_array($receivePO['status'], ['approved', 'shipped'], true)) {
        $receiveStmt = $pdo->prepare("SELECT poi.id, poi.quantity, poi.received_quantity, p.sku, p.product_name, p.brand
                                      FROM purchase_order_items poi JOIN products p ON p.id = poi.product_id
                                      WHERE poi.po_id = ? AND poi.received_quantity < poi.quantity ORDER BY poi.id");
        $receiveStmt->execute([$receivePO['id']]);
        $receiveItems = $receiveStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if ($action === 'receipt' && isset($_GET['receipt_number'])) {
    $receiptStmt = $pdo->prepare("SELECT b.*, po.po_number, s.company_name AS supplier, p.sku, p.product_name, w.name AS warehouse_name
                                  FROM inventory_batches b
                                  JOIN purchase_orders po ON po.id = b.po_id
                                  LEFT JOIN suppliers s ON s.id = b.supplier_id
                                  JOIN products p ON p.id = b.product_id
                                  JOIN warehouses w ON w.id = po.warehouse_id
                                  WHERE b.receipt_number = ? AND b.origin_batch_id IS NULL ORDER BY b.id");
    $receiptStmt->execute([trim((string)$_GET['receipt_number'])]);
    $receiptRows = $receiptStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// GET POs WITH FILTERS & SORTING
// ============================================
$showArchived = isset($_GET['archived']) ? 1 : 0;
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$paymentMethodFilter = isset($_GET['payment_method']) ? trim($_GET['payment_method']) : '';
$minAmount = isset($_GET['min_amount']) && $_GET['min_amount'] !== '' ? max(0, (float)$_GET['min_amount']) : null;
$maxAmount = isset($_GET['max_amount']) && $_GET['max_amount'] !== '' ? max(0, (float)$_GET['max_amount']) : null;
$approvalFilter = isset($_GET['approval']) ? $_GET['approval'] : '';
$supplierFilter = isset($_GET['supplier_id']) ? trim($_GET['supplier_id']) : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sortField = isset($_GET['sort']) ? $_GET['sort'] : 'created_at';
$sortOrder = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'ASC' : 'DESC';

$allowedSortFields = ['po_number', 'company_name', 'order_date', 'total_amount', 'payment_method', 'status', 'approval_status', 'created_at'];
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

    if ($paymentMethodFilter !== '') {
        $query .= ' AND po.payment_method = ?';
        $params[] = $paymentMethodFilter;
    }

    if ($minAmount !== null) {
        $query .= ' AND po.total_amount >= ?';
        $params[] = $minAmount;
    }

    if ($maxAmount !== null) {
        $query .= ' AND po.total_amount <= ?';
        $params[] = $maxAmount;
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
            body.receipt-print .main-content { display: block !important; margin: 0 !important; padding: 0 !important; width: 100% !important; }
            body.receipt-print .main-content > :not(.modal-overlay) { display: none !important; }
            body.receipt-print .main-content > .modal-overlay { position: static !important; display: block !important; padding: 0 !important; background: transparent !important; }
            body.receipt-print .sidebar, body.receipt-print .fullscreen-toggle { display: none !important; }
            body.receipt-print .admin-layout { display: block !important; }
            body.receipt-print .receipt-modal { position: static !important; display: block !important; width: 100% !important; max-width: none !important; max-height: none !important; overflow: visible !important; box-shadow: none !important; border: 0 !important; }
            body.receipt-print .receipt-paper { color: #111 !important; background: #fff !important; }
            body.receipt-print .receipt-paper table { width: 100%; border-collapse: collapse; }
            body.receipt-print .receipt-paper th, body.receipt-print .receipt-paper td { border: 1px solid #888; padding: 6px; }
        }
    </style>
</head>
<body class="<?php echo $action === 'receipt' ? 'receipt-print' : ''; ?>">
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
                            <a href="purchase-orders.php?action=export&format=excel<?php echo '&status=' . urlencode($statusFilter) . '&payment_method=' . urlencode($paymentMethodFilter) . '&min_amount=' . urlencode((string)$minAmount) . '&max_amount=' . urlencode((string)$maxAmount) . '&search=' . urlencode($search); ?>">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </a>
                            <a href="purchase-orders.php?action=export&format=pdf<?php echo '&status=' . urlencode($statusFilter) . '&payment_method=' . urlencode($paymentMethodFilter) . '&min_amount=' . urlencode((string)$minAmount) . '&max_amount=' . urlencode((string)$maxAmount) . '&search=' . urlencode($search); ?>">
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

                    <select name="payment_method">
                        <option value="">All Payment Methods</option>
                        <option value="cash" <?php echo $paymentMethodFilter === 'cash' ? 'selected' : ''; ?>>Cash</option>
                        <option value="digital_cash" <?php echo $paymentMethodFilter === 'digital_cash' ? 'selected' : ''; ?>>Digital Cash</option>
                        <option value="credit" <?php echo $paymentMethodFilter === 'credit' ? 'selected' : ''; ?>>Credits</option>
                    </select>
                    <input type="number" name="min_amount" min="0" step="0.01" placeholder="Min total"
                           value="<?php echo $minAmount !== null ? htmlspecialchars((string)$minAmount) : ''; ?>">
                    <input type="number" name="max_amount" min="0" step="0.01" placeholder="Max total"
                           value="<?php echo $maxAmount !== null ? htmlspecialchars((string)$maxAmount) : ''; ?>">
                    
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
                        <?php if (!empty($search) || !empty($statusFilter) || !empty($paymentMethodFilter) || $minAmount !== null || $maxAmount !== null || !empty($approvalFilter) || !empty($supplierFilter) || !empty($dateFrom) || !empty($dateTo)): ?>
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
                                    <th onclick="sortTable('payment_method')" class="<?php echo $sortField === 'payment_method' ? 'sorted' : ''; ?>">
                                        Payment Method <span class="sort-icon"><?php echo $sortField === 'payment_method' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
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
                                        <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $po['payment_method'] ?? 'not recorded'))); ?></td>
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
                                                <?php if (!$showArchived && in_array($po['status'], ['approved', 'shipped'], true)): ?>
                                                <a href="purchase-orders.php?action=receive&id=<?php echo (int)$po['id']; ?>"
                                                   class="btn btn-success btn-sm" title="Record receipt">
                                                    <i class="fas fa-box-open"></i>
                                                </a>
                                                <?php endif; ?>
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
                                        <td colspan="9" class="empty-state">
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
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Payment Method</div>
                    <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $viewPO['payment_method'] ?? 'not recorded'))); ?></strong>
                </div>
            </div>

            <?php if (in_array($viewPO['payment_method'] ?? '', ['digital_cash', 'credit'], true)): ?>
            <div style="margin: 16px 0; padding: 16px; background: rgba(47, 128, 237, 0.05); border: 2px dashed var(--primary); border-radius: var(--radius-sm); text-align: center;">
                <div style="font-weight: 600; font-size: 14px; color: var(--text); margin-bottom: 4px;">
                    <i class="fas fa-qrcode" style="color: var(--primary);"></i> Transaction QR Code (<?php echo ucwords(str_replace('_', ' ', $viewPO['payment_method'])); ?>)
                </div>
                <p style="font-size: 12px; color: var(--secondary-text); margin-bottom: 10px;">Scan to review or settle payment for this purchase order</p>
                <div style="display: inline-block; background: white; padding: 12px; border-radius: 8px; box-shadow: var(--shadow);">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode('GLOBALSCM|PO:' . $viewPO['po_number'] . '|TOTAL:' . $viewPO['total_amount'] . '|METHOD:' . $viewPO['payment_method']); ?>" alt="Transaction QR" style="width: 150px; height: 150px; display: block; margin: 0 auto;">
                </div>
                <div style="margin-top: 8px; font-size: 12px; font-family: monospace; color: var(--text); font-weight: 600;">PO: <?php echo htmlspecialchars($viewPO['po_number']); ?> • Total: ₱<?php echo number_format($viewPO['total_amount'], 2); ?></div>
            </div>
            <?php endif; ?>
            
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
                    <div><?php echo number_format($item['quantity']); ?> ordered · <?php echo number_format($item['received_quantity'] ?? 0); ?> received</div>
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

    <?php if ($action === 'receive' && $receivePO && $receiveItems): ?>
    <div class="modal-overlay" style="display:flex;">
        <div class="modal" style="max-width:900px;max-height:90vh;overflow-y:auto;">
            <h3><i class="fas fa-box-open" style="color:var(--primary);"></i> Receive <?php echo htmlspecialchars($receivePO['po_number']); ?></h3>
            <p>Supplier: <strong><?php echo htmlspecialchars($receivePO['company_name'] ?? 'N/A'); ?></strong> · Warehouse: <strong><?php echo htmlspecialchars($receivePO['warehouse_name'] ?? 'N/A'); ?></strong></p>
            <form method="POST" action="purchase-orders.php?action=receive">
                <input type="hidden" name="id" value="<?php echo (int)$receivePO['id']; ?>">
                <?php foreach ($receiveItems as $receiveItem): $remainingQuantity = (int)$receiveItem['quantity'] - (int)$receiveItem['received_quantity']; ?>
                <section class="item-row" style="display:grid;grid-template-columns:1.4fr repeat(3,minmax(110px,1fr));gap:10px;padding:14px 0;border-bottom:1px solid var(--border);">
                    <div>
                        <strong><?php echo htmlspecialchars($receiveItem['product_name']); ?></strong><br>
                        <small><?php echo htmlspecialchars($receiveItem['sku']); ?><?php if (!empty($receiveItem['brand'])): ?> · <?php echo htmlspecialchars($receiveItem['brand']); ?><?php endif; ?></small><br>
                        <small>Ordered <?php echo (int)$receiveItem['quantity']; ?> · Previously received <?php echo (int)$receiveItem['received_quantity']; ?> · Remaining <?php echo $remainingQuantity; ?></small>
                    </div>
                    <input type="number" name="items[<?php echo (int)$receiveItem['id']; ?>][quantity]" min="0" max="<?php echo $remainingQuantity; ?>" value="0" aria-label="Received quantity">
                    <input type="text" name="items[<?php echo (int)$receiveItem['id']; ?>][batch_number]" maxlength="100" placeholder="Supplier batch #" aria-label="Supplier batch number">
                    <input type="date" name="items[<?php echo (int)$receiveItem['id']; ?>][expiry_date]" aria-label="Expiry date">
                    <select name="items[<?php echo (int)$receiveItem['id']; ?>][quality_status]" aria-label="Inspection result">
                        <option value="">Inspection result</option>
                        <option value="accepted">Accepted for use</option>
                        <option value="rejected">Rejected / quarantined</option>
                    </select>
                    <input type="text" name="items[<?php echo (int)$receiveItem['id']; ?>][quality_notes]" placeholder="Inspection notes" aria-label="Inspection notes">
                </section>
                <?php endforeach; ?>
                <div class="form-actions" style="margin-top:20px;">
                    <a href="purchase-orders.php" class="btn btn-outline">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-clipboard-check"></i> Record inspected receipt</button>
                </div>
            </form>
        </div>
    </div>
    <?php elseif ($action === 'receive' && (!$receivePO || !$receiveItems)): ?>
    <div class="modal-overlay" style="display:flex;"><div class="modal"><h3>Nothing to receive</h3><p>This PO must be approved or shipped and have outstanding quantities.</p><a href="purchase-orders.php" class="btn btn-outline">Back to purchase orders</a></div></div>
    <?php endif; ?>

    <?php if ($action === 'receipt' && $receiptRows): $receiptHeader = $receiptRows[0]; ?>
    <div class="modal-overlay" style="display:flex;">
        <div class="modal receipt-modal" style="max-width:900px;max-height:90vh;overflow-y:auto;">
            <div class="receipt-paper">
                <h2>Goods Receipt Note</h2>
                <p><strong>Receipt:</strong> <?php echo htmlspecialchars($receiptHeader['receipt_number']); ?> · <strong>PO:</strong> <?php echo htmlspecialchars($receiptHeader['po_number']); ?></p>
                <p><strong>Supplier:</strong> <?php echo htmlspecialchars($receiptHeader['supplier'] ?? 'N/A'); ?> · <strong>Warehouse:</strong> <?php echo htmlspecialchars($receiptHeader['warehouse_name']); ?></p>
                <p><strong>Received:</strong> <?php echo htmlspecialchars($receiptHeader['received_at']); ?></p>
                <table>
                    <thead><tr><th>Item / SKU</th><th>Brand</th><th>Batch</th><th>Qty</th><th>Expiry</th><th>Inspection</th><th>Notes</th></tr></thead>
                    <tbody><?php foreach ($receiptRows as $receiptRow): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($receiptRow['product_name'] . ' / ' . $receiptRow['sku']); ?></td>
                            <td><?php echo htmlspecialchars($receiptRow['brand_snapshot'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($receiptRow['batch_number']); ?></td>
                            <td><?php echo (int)$receiptRow['received_quantity']; ?></td>
                            <td><?php echo htmlspecialchars($receiptRow['expiry_date'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($receiptRow['quality_status'])); ?></td>
                            <td><?php echo htmlspecialchars($receiptRow['quality_notes'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table>
            </div>
            <div class="form-actions no-print" style="margin-top:18px;">
                <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print receipt</button>
                <a class="btn btn-outline" href="purchase-orders.php?action=export_receipt&amp;format=pdf&amp;receipt_number=<?php echo urlencode($receiptHeader['receipt_number']); ?>"><i class="fas fa-file-pdf"></i> PDF</a>
                <a class="btn btn-outline" href="purchase-orders.php?action=export_receipt&amp;format=excel&amp;receipt_number=<?php echo urlencode($receiptHeader['receipt_number']); ?>"><i class="fas fa-file-excel"></i> Excel</a>
                <a href="purchase-orders.php" class="btn btn-outline">Close</a>
            </div>
        </div>
    </div>
    <?php elseif ($action === 'receipt'): ?>
    <div class="modal-overlay" style="display:flex;"><div class="modal"><h3>Receipt not found</h3><a href="purchase-orders.php" class="btn btn-outline">Back to purchase orders</a></div></div>
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
                    <label>Receiving Warehouse *</label>
                    <select name="warehouse_id" required>
                        <?php foreach ($warehouses as $warehouse): ?>
                        <option value="<?php echo (int)$warehouse['id']; ?>">
                            <?php echo htmlspecialchars($warehouse['name'] . ' (' . $warehouse['warehouse_code'] . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Payment Method *</label>
                    <select name="payment_method" id="poPaymentMethod" required onchange="handlePaymentMethodChange(this.value)">
                        <option value="">Choose a payment method</option>
                        <option value="cash">Cash</option>
                        <option value="digital_cash">Digital Cash</option>
                        <option value="credit">Credits</option>
                    </select>
                </div>

                <!-- Dynamic QR Code Container for Digital Cash / Credit Payment -->
                <div id="paymentQrBox" style="display: none; margin: 15px 0; padding: 18px; background: rgba(47, 128, 237, 0.05); border: 2px dashed var(--primary); border-radius: var(--radius-sm); text-align: center; transition: all 0.3s ease;">
                    <div style="font-weight: 600; font-size: 14px; color: var(--text); margin-bottom: 4px;">
                        <i class="fas fa-qrcode" style="color: var(--primary);"></i> <span id="qrHeading">Scan QR Code for Payment Transaction</span>
                    </div>
                    <p id="qrSubheading" style="font-size: 12px; color: var(--secondary-text); margin-bottom: 12px;">
                        Scan with GCash, Maya, or any QRPH-compliant banking app.
                    </p>
                    <div style="display: inline-block; background: white; padding: 12px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                        <img id="paymentQrImg" src="" alt="Payment Transaction QR Code" style="width: 170px; height: 170px; display: block; margin: 0 auto;">
                    </div>
                    <div id="qrRefText" style="margin-top: 10px; font-size: 12px; font-family: monospace; color: var(--text); font-weight: 600;">
                        REF: PO-TXN-PENDING
                    </div>
                    <div style="margin-top: 6px; font-size: 11px; color: #27AE60; font-weight: 500;">
                        <i class="fas fa-shield-alt"></i> Verified Merchant QR • Ready for Transaction
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Payment Terms</label>
                    <input type="text" name="terms" placeholder="e.g., due on receipt, Net 30">
                </div>
                
                <h4 style="margin: 15px 0 10px;">Items</h4>
                <div id="itemsContainer">
                    <div class="item-row" style="display: grid; grid-template-columns: 2fr 1fr 1fr 0.5fr; gap: 10px; background: var(--bg); padding: 10px; border-radius: 8px; margin-bottom: 10px;">
                        <div>
                            <input type="text" class="product-search" list="productOptions" autocomplete="off"
                                   placeholder="search product" oninput="resolveProduct(this)" required
                                   value="<?php echo $prefillProduct ? htmlspecialchars($prefillProduct['sku'] . ' - ' . $prefillProduct['product_name'], ENT_QUOTES) : ''; ?>"
                                   style="width: 100%; padding: 10px 14px; border: 2px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text); box-sizing: border-box;">
                            <input type="hidden" name="items[0][product_id]" class="product-id-field" value="<?php echo $prefillProduct ? (int)$prefillProduct['id'] : ''; ?>">
                        </div>
                        <input type="number" class="quantity-field" name="items[0][quantity]" placeholder="Qty" required min="1" value="<?php echo $prefillProduct ? $prefillQuantity : ''; ?>" style="padding: 10px 14px; border: 2px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text);">
                        <input type="number" name="items[0][unit_price]" placeholder="Price" required step="0.01" value="<?php echo $prefillProduct ? htmlspecialchars((string)$prefillProduct['unit_price'], ENT_QUOTES) : ''; ?>" style="padding: 10px 14px; border: 2px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text);">
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
        
        var supplierBatchSuggestions = <?php echo json_encode($supplierBatchSuggestions); ?>;
        var productReorderMap = {};
        <?php foreach ($products as $product): ?>
        productReorderMap[<?php echo json_encode((string)$product['id']); ?>] = <?php echo json_encode(max(1, (int)$product['reorder_quantity'])); ?>;
        <?php endforeach; ?>

        function resolveSupplier(el) {
            var hidden = document.getElementById('supplierIdInput');
            hidden.value = suppliersMap[el.value] || '';
            document.querySelectorAll('#itemsContainer .product-search').forEach(function(productInput) {
                if (productInput.value) resolveProduct(productInput);
            });
        }
        
        function resolveProduct(el) {
            var row = el.closest('.item-row');
            var hidden = row.querySelector('.product-id-field');
            hidden.value = productsMap[el.value] || '';
            if (hidden.value) {
                var supplierId = document.getElementById('supplierIdInput').value;
                var suggestion = supplierBatchSuggestions[supplierId] && supplierBatchSuggestions[supplierId][hidden.value];
                var quantityField = row.querySelector('.quantity-field');
                if (quantityField) quantityField.value = suggestion || productReorderMap[hidden.value] || 1;
            }
        function handlePaymentMethodChange(method) {
            var qrBox = document.getElementById('paymentQrBox');
            var qrImg = document.getElementById('paymentQrImg');
            var qrHeading = document.getElementById('qrHeading');
            var qrSubheading = document.getElementById('qrSubheading');
            var qrRefText = document.getElementById('qrRefText');

            if (!qrBox) return;

            if (method === 'digital_cash' || method === 'credit') {
                var randomRef = 'TXN-' + Math.floor(100000 + Math.random() * 900000);
                if (method === 'digital_cash') {
                    qrHeading.textContent = 'Scan QR Code for Digital Cash Payment';
                    qrSubheading.textContent = 'Scan via GCash, Maya, or any QRPH-compliant mobile banking app.';
                    qrRefText.textContent = 'REF: ' + randomRef + ' • DIGITAL-CASH';
                } else {
                    qrHeading.textContent = 'Scan QR Code for Credit Transaction Verification';
                    qrSubheading.textContent = 'Scan with credit authorization scanner to verify credit line allocation.';
                    qrRefText.textContent = 'REF: ' + randomRef + ' • CREDIT-AUTH';
                }

                var qrPayload = encodeURIComponent('GLOBALSCM|PO|' + method.toUpperCase() + '|' + randomRef + '|TIME:' + Date.now());
                qrImg.src = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + qrPayload;
                qrBox.style.display = 'block';
            } else {
                qrBox.style.display = 'none';
            }
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