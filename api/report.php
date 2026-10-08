<?php
// api/report.php
// AI-Powered Operations Reporting & Daily Narrative Synthesis Engine
// Cross-database compatible (SQLite & MySQL)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$action = $_GET['action'] ?? ($_GET['type'] ?? 'daily');
$targetDate = !empty($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
    $targetDate = date('Y-m-d');
}

$userId = $_SESSION['user_id'] ?? 1;

/**
 * Generate in-depth AI Daily Narrative Report based on data of a specific day
 */
function generateDailyNarrativeReport($pdo, $targetDate, $userId) {
    $formattedDate = date('l, F j, Y', strtotime($targetDate));
    $isToday = ($targetDate === date('Y-m-d'));

    // 1. Inventory Movements on Target Date
    $stmtMovements = $pdo->prepare("
        SELECT 
            it.transaction_type,
            COUNT(*) as tx_count,
            COALESCE(SUM(it.quantity), 0) as total_qty,
            p.product_name,
            p.sku
        FROM inventory_transactions it
        JOIN products p ON it.product_id = p.id
        WHERE DATE(it.created_at) = ?
        GROUP BY it.transaction_type, it.product_id
        ORDER BY total_qty DESC
    ");
    $stmtMovements->execute([$targetDate]);
    $movementRows = $stmtMovements->fetchAll();

    $receivedQty = 0;
    $issuedQty = 0;
    $adjustedQty = 0;
    $topMovedProducts = [];

    foreach ($movementRows as $m) {
        $type = strtolower($m['transaction_type']);
        $qty = (int)$m['total_qty'];
        if ($type === 'receiving') {
            $receivedQty += $qty;
        } elseif ($type === 'issuance') {
            $issuedQty += $qty;
        } elseif ($type === 'adjustment') {
            $adjustedQty += $qty;
        }
        $topMovedProducts[] = "{$m['product_name']} ({$m['sku']}) - {$qty} units ({$type})";
    }
    $netChange = $receivedQty - $issuedQty;

    // 2. Purchase Orders Activity on Target Date
    $stmtPOs = $pdo->prepare("
        SELECT 
            po.po_number,
            po.total_amount,
            po.status,
            po.approval_status,
            s.company_name as supplier_name
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        WHERE (DATE(po.order_date) = ? OR DATE(po.created_at) = ?) AND po.is_archived = 0
    ");
    $stmtPOs->execute([$targetDate, $targetDate]);
    $poRows = $stmtPOs->fetchAll();

    $totalPOSpend = 0.0;
    $poCount = count($poRows);
    $approvedPOCount = 0;
    $pendingPOCount = 0;
    $poHighlights = [];

    foreach ($poRows as $po) {
        $amt = (float)$po['total_amount'];
        $totalPOSpend += $amt;
        if ($po['approval_status'] === 'approved' || $po['status'] === 'approved') {
            $approvedPOCount++;
        } else {
            $pendingPOCount++;
        }
        $poHighlights[] = "PO #{$po['po_number']} with {$po['supplier_name']} (₱" . number_format($amt, 2) . ", Status: " . ucfirst($po['status']) . ")";
    }

    // 3. Logistics & Shipments Activity on Target Date
    $stmtShipments = $pdo->prepare("
        SELECT 
            shipment_id,
            carrier,
            origin,
            destination,
            mode,
            status,
            freight_cost
        FROM shipments
        WHERE (DATE(departure_date) = ? OR DATE(created_at) = ? OR DATE(expected_arrival) = ?) AND is_archived = 0
    ");
    $stmtShipments->execute([$targetDate, $targetDate, $targetDate]);
    $shipmentRows = $stmtShipments->fetchAll();
    $shipmentCount = count($shipmentRows);

    // 4. Current Stock Vulnerabilities & Low Stock
    $lowStockStmt = $pdo->query("
        SELECT sku, product_name, current_stock, reorder_point, min_stock 
        FROM products 
        WHERE current_stock <= reorder_point AND is_archived = 0
        ORDER BY current_stock ASC 
        LIMIT 10
    ");
    $lowStockItems = $lowStockStmt->fetchAll();
    $criticalOutOfStock = array_filter($lowStockItems, function($i) { return (int)$i['current_stock'] <= 0; });
    $lowStockCount = count($lowStockItems);
    $outOfStockCount = count($criticalOutOfStock);

    // 5. Total Active Fleet / Sourcing Bids
    $activeTendersCount = 0;
    try {
        $activeTendersCount = (int)$pdo->query("SELECT COUNT(*) FROM bidding_tenders WHERE status = 'open' AND is_archived = 0")->fetchColumn();
    } catch (Throwable $t) {}

    // ── Build Synthesized Daily Narrative Paragraphs ────────────────────────

    // Executive Summary
    if ($poCount === 0 && count($movementRows) === 0 && $shipmentCount === 0) {
        $execSummary = "On {$formattedDate}, supply chain operational activities proceeded at baseline volume with steady holding states across facilities. Core warehouse activities remained stable with no major unhandled escalations logged.";
    } else {
        $execSummary = "On {$formattedDate}, GlobalSCM recorded active operations featuring " . 
            number_format($receivedQty + $issuedQty) . " total units in warehouse motion, ₱" . 
            number_format($totalPOSpend, 2) . " across {$poCount} purchase order commitments, and {$shipmentCount} logistics transit movements. Net warehouse inventory experienced a " . 
            ($netChange >= 0 ? "positive expansion of +" . number_format($netChange) : "drawdown of " . number_format($netChange)) . " units.";
    }

    // Warehouse & Inventory Dynamics
    $whNarrative = "Warehouse operations on this date handled " . number_format($receivedQty) . " inbound units received into stock, while operational dispatches and consumption accounted for " . number_format($issuedQty) . " units. ";
    if ($adjustedQty > 0) {
        $whNarrative .= "Inventory cycle counts and reconciliation led to " . number_format($adjustedQty) . " units undergoing stock balance adjustments. ";
    }
    if (!empty($topMovedProducts)) {
        $whNarrative .= "Primary movement activity was concentrated on: " . implode('; ', array_slice($topMovedProducts, 0, 4)) . ".";
    } else {
        $whNarrative .= "No critical anomalous movement variances were detected during this operating window.";
    }

    // Procurement & Vendor Engagements
    $procNarrative = "In procurement sourcing, a total of {$poCount} purchase order(s) totaling ₱" . number_format($totalPOSpend, 2) . " were active. ";
    if ($approvedPOCount > 0) {
        $procNarrative .= "{$approvedPOCount} purchase order(s) achieved approved status for fulfillment. ";
    }
    if ($pendingPOCount > 0) {
        $procNarrative .= "{$pendingPOCount} PO(s) remain under review or awaiting supplier acknowledgement. ";
    }
    if (!empty($poHighlights)) {
        $procNarrative .= "Key commitments include: " . implode('; ', array_slice($poHighlights, 0, 3)) . ". ";
    } else {
        $procNarrative .= "No new capital commitments were recorded on this operational date.";
    }

    // Transportation, Freight & Logistics
    $logisticsNarrative = "Transportation and logistics dispatched/tracked {$shipmentCount} operational consignment(s). ";
    if ($shipmentCount > 0) {
        $shipHighlights = array_map(function($s) {
            return "Shipment #{$s['shipment_id']} via {$s['carrier']} ({$s['origin']} ➔ {$s['destination']}, Mode: " . ucfirst($s['mode']) . ", Status: " . ucfirst($s['status']) . ")";
        }, $shipmentRows);
        $logisticsNarrative .= "Transit legs active: " . implode('; ', array_slice($shipHighlights, 0, 3)) . ". ";
    } else {
        $logisticsNarrative .= "No shipments were slated for departure or arrival on this exact date. ";
    }
    if ($activeTendersCount > 0) {
        $logisticsNarrative .= "Additionally, {$activeTendersCount} competitive freight spot auctions / tenders remain live on the Logistics Bidding Module.";
    }

    // Risk Watchlist & Alerts
    $riskNarrative = "";
    if ($outOfStockCount > 0) {
        $stockoutNames = array_map(function($i) { return "{$i['product_name']} ({$i['sku']})"; }, array_slice($criticalOutOfStock, 0, 3));
        $riskNarrative .= "CRITICAL WATCH: {$outOfStockCount} SKU(s) are completely exhausted (0 on-hand), including: " . implode(', ', $stockoutNames) . ". Immediate expedited reordering required. ";
    }
    if ($lowStockCount > 0) {
        $riskNarrative .= "{$lowStockCount} inventory items have breached their established safety reorder thresholds. ";
    } else {
        $riskNarrative .= "Inventory health metrics are currently sound with all primary products maintaining stock above safety reorder points. ";
    }

    // Strategic Directives
    $directives = [];
    if ($outOfStockCount > 0) {
        $directives[] = "Urgent Procurement: Issue emergency purchase orders or execute spot freight tenders for the {$outOfStockCount} zero-stock SKUs.";
    }
    if ($pendingPOCount > 0) {
        $directives[] = "PO Clearance: Authorize pending purchase orders totaling ₱" . number_format($totalPOSpend, 2) . " to avoid upstream supplier lead-time slippage.";
    }
    if ($issuedQty > $receivedQty && $issuedQty > 0) {
        $directives[] = "Warehouse Balancing: Outbound velocity exceeded inbound replenishment by " . number_format(abs($netChange)) . " units; schedule receiving dock throughput.";
    }
    if (empty($directives)) {
        $directives[] = "Maintain standard cyclical stock inspections and audit warehouse zone environmental conditions.";
        $directives[] = "Verify carrier transit milestones for ongoing shipments in the Transportation Management System.";
    }

    // Markdown Narrative Full Document
    $fullMarkdown = "# GlobalSCM Operational Narrative Report\n";
    $fullMarkdown .= "**Report Date:** {$formattedDate}\n";
    $fullMarkdown .= "**Generated by:** GlobalSCM AI Assistant Engine\n\n";
    $fullMarkdown .= "### 1. Executive Overview\n{$execSummary}\n\n";
    $fullMarkdown .= "### 2. Warehouse & Material Influx\n{$whNarrative}\n\n";
    $fullMarkdown .= "### 3. Procurement & Commercial Spend\n{$procNarrative}\n\n";
    $fullMarkdown .= "### 4. Logistics, Carriers & In-Transit Network\n{$logisticsNarrative}\n\n";
    $fullMarkdown .= "### 5. Risk Matrix & Supply Vulnerabilities\n{$riskNarrative}\n\n";
    $fullMarkdown .= "### 6. Managerial Directives & Action Items\n";
    foreach ($directives as $idx => $d) {
        $fullMarkdown .= ($idx + 1) . ". {$d}\n";
    }

    return [
        'date' => $targetDate,
        'formatted_date' => $formattedDate,
        'is_today' => $isToday,
        'generated_at' => date('Y-m-d H:i:s'),
        'executive_summary' => $execSummary,
        'full_markdown' => $fullMarkdown,
        'sections' => [
            'executive' => [
                'title' => 'Executive Overview',
                'icon' => 'fas fa-newspaper',
                'content' => $execSummary
            ],
            'warehouse' => [
                'title' => 'Warehouse & Inventory Dynamics',
                'icon' => 'fas fa-warehouse',
                'content' => $whNarrative,
                'metrics' => [
                    'received' => $receivedQty,
                    'issued' => $issuedQty,
                    'net_change' => $netChange
                ]
            ],
            'procurement' => [
                'title' => 'Procurement & Spend Commitments',
                'icon' => 'fas fa-shopping-cart',
                'content' => $procNarrative,
                'metrics' => [
                    'po_count' => $poCount,
                    'total_spend' => $totalPOSpend,
                    'approved_count' => $approvedPOCount
                ]
            ],
            'logistics' => [
                'title' => 'Logistics & In-Transit Freight',
                'icon' => 'fas fa-truck-moving',
                'content' => $logisticsNarrative,
                'metrics' => [
                    'shipments' => $shipmentCount,
                    'active_bids' => $activeTendersCount
                ]
            ],
            'risk' => [
                'title' => 'Risk Matrix & Stockout Watchlist',
                'icon' => 'fas fa-exclamation-triangle',
                'content' => $riskNarrative,
                'metrics' => [
                    'out_of_stock' => $outOfStockCount,
                    'low_stock' => $lowStockCount
                ]
            ],
            'directives' => [
                'title' => 'Directives & Priority Action Items',
                'icon' => 'fas fa-tasks',
                'items' => $directives
            ]
        ],
        'raw_metrics' => [
            'received_qty' => $receivedQty,
            'issued_qty' => $issuedQty,
            'net_change' => $netChange,
            'po_count' => $poCount,
            'po_spend' => $totalPOSpend,
            'shipments_count' => $shipmentCount,
            'low_stock_count' => $lowStockCount,
            'out_of_stock_count' => $outOfStockCount
        ]
    ];
}

// ── Execute Request ──────────────────────────────────────────────────────────
try {
    if ($action === 'narrative' || $action === 'daily_narrative' || isset($_GET['narrative'])) {
        $report = generateDailyNarrativeReport($pdo, $targetDate, $userId);
        logAudit($userId, 'generate_narrative_report', 'ai_assistant', "Generated daily narrative report for $targetDate");
        echo json_encode([
            'success' => true,
            'report' => $report
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Legacy structured data fallback for dashboard/analytics charts
    $narrative = generateDailyNarrativeReport($pdo, $targetDate, $userId);
    echo json_encode([
        'success' => true,
        'report' => $narrative
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}