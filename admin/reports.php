<?php
// admin/dashboard.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    header('Location: ../login.php');
    exit();
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

// Get theme setting
$theme = getTheme();

// Define color constants if not already defined
if (!defined('COLOR_PRIMARY')) define('COLOR_PRIMARY', '#2F80ED');
if (!defined('COLOR_SECONDARY')) define('COLOR_SECONDARY', '#56CCF2');
if (!defined('COLOR_ACCENT')) define('COLOR_ACCENT', '#27AE60');
if (!defined('COLOR_BG')) define('COLOR_BG', '#F8FAFC');
if (!defined('COLOR_CARD')) define('COLOR_CARD', '#FFFFFF');
if (!defined('COLOR_TEXT')) define('COLOR_TEXT', '#1F2937');
if (!defined('COLOR_SECONDARY_TEXT')) define('COLOR_SECONDARY_TEXT', '#6B7280');
if (!defined('COLOR_BORDER')) define('COLOR_BORDER', '#EEF2F7');

$datePreset = isset($_GET['date_preset']) ? $_GET['date_preset'] : 'last_30';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';

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
if (!empty($_GET['date_from'])) $dateFrom = $_GET['date_from'];
if (!empty($_GET['date_to'])) $dateTo = $_GET['date_to'];

// ============================================
// STATS QUERIES
// ============================================

// Total Products
$stmt = $pdo->query("SELECT COUNT(*) as count FROM products WHERE is_archived = false");
$totalProducts = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

// Total Suppliers
$stmt = $pdo->query("SELECT COUNT(*) as count FROM suppliers WHERE is_archived = false");
$totalSuppliers = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

// Total Purchase Orders
$stmt = $pdo->query("SELECT COUNT(*) as count FROM purchase_orders WHERE is_archived = false");
$totalPOs = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

// Total Shipments
$stmt = $pdo->query("SELECT COUNT(*) as count FROM shipments WHERE is_archived = false");
$totalShipments = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

// Total Users
$stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE is_archived = false");
$totalUsers = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

// Inventory Value
$stmt = $pdo->query("SELECT SUM(unit_price * current_stock) as total_value FROM products WHERE is_archived = false");
$inventoryValue = $stmt->fetch(PDO::FETCH_ASSOC)['total_value'] ?? 0;

// Low Stock Items
$stmt = $pdo->query("SELECT COUNT(*) as count FROM products WHERE current_stock <= reorder_point AND is_archived = false");
$lowStock = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

// Out of Stock Items
$stmt = $pdo->query("SELECT COUNT(*) as count FROM products WHERE current_stock = 0 AND is_archived = false");
$outOfStock = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

// Pending POs
$stmt = $pdo->query("SELECT COUNT(*) as count FROM purchase_orders WHERE status IN ('pending', 'approved') AND is_archived = false");
$pendingPOs = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

// Received POs (last 30 days)
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM purchase_orders WHERE status = 'received' AND DATE(created_at) >= ? AND is_archived = false");
$stmt->execute([$dateFrom]);
$receivedPOs = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

// Total PO Value (last 30 days)
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total_value FROM purchase_orders WHERE DATE(created_at) >= ? AND is_archived = false");
$stmt->execute([$dateFrom]);
$totalPOValue = $stmt->fetch(PDO::FETCH_ASSOC)['total_value'] ?? 0;

// Stock Movements (last 30 days)
$stmt = $pdo->prepare("SELECT 
    SUM(CASE WHEN transaction_type = 'receiving' THEN quantity ELSE 0 END) as total_received,
    SUM(CASE WHEN transaction_type = 'issuance' THEN quantity ELSE 0 END) as total_issued
    FROM inventory_transactions 
    WHERE DATE(created_at) >= ?");
$stmt->execute([$dateFrom]);
$stockMovements = $stmt->fetch(PDO::FETCH_ASSOC);

// Top Products by Stock Value
$stmt = $pdo->query("SELECT product_name, sku, current_stock, unit_price, (current_stock * unit_price) as total_value 
                     FROM products 
                     WHERE is_archived = false AND current_stock > 0 
                     ORDER BY total_value DESC 
                     LIMIT 5");
$topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Supplier Performance
$stmt = $pdo->query("SELECT s.company_name, 
                     AVG(sp.on_time_delivery) as avg_on_time,
                     AVG(sp.quality_rate) as avg_quality,
                     AVG((sp.on_time_delivery + sp.quality_rate + sp.response_time)/3) as overall_score
                     FROM suppliers s
                     JOIN supplier_performance sp ON s.id = sp.supplier_id
                     WHERE s.is_archived = false
                     GROUP BY s.id
                     ORDER BY overall_score DESC
                     LIMIT 5");
$topSuppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent Activities
$stmt = $pdo->query("SELECT al.*, u.full_name as user_name 
                     FROM audit_logs al 
                     LEFT JOIN users u ON al.user_id = u.id 
                     ORDER BY al.created_at DESC 
                     LIMIT 10");
$recentActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Dashboard - GlobalSCM</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --bg: #0F172A;
            --card: #1E293B;
            --text: #E2E8F0;
            --secondary-text: #94A3B8;
            --border: #2D3748;
            --shadow: 0 1px 3px rgba(0,0,0,0.3);
            --shadow-lg: 0 10px 40px rgba(0,0,0,0.4);
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
        
        .top-bar-actions .dropdown {
            position: relative;
            display: inline-block;
        }
        
        .top-bar-actions .dropdown-content {
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
        
        .top-bar-actions .dropdown-content.show {
            display: block;
        }
        
        .top-bar-actions .dropdown-content a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 16px;
            color: var(--text);
            text-decoration: none;
            font-size: 13px;
            transition: var(--transition);
        }
        
        .top-bar-actions .dropdown-content a:hover {
            background: rgba(47, 128, 237, 0.05);
            color: var(--primary);
        }
        
        .top-bar-actions .dropdown-content a i {
            width: 18px;
            color: var(--secondary-text);
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
        
        /* ===== DATE PRESETS ===== */
        .date-presets {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .date-presets .preset-btn {
            padding: 6px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg);
            color: var(--text);
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-size: 12px;
            transition: var(--transition);
        }
        
        .date-presets .preset-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .date-presets .preset-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .date-presets input[type="date"] {
            padding: 6px 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg);
            color: var(--text);
            font-family: 'Poppins', sans-serif;
            font-size: 12px;
        }
        
        .date-presets input[type="date"]:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        /* ===== STATS GRID ===== */
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
        
        /* ===== CHARTS ===== */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 28px;
        }
        
        .chart-card {
            background: var(--card);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 20px;
            box-shadow: var(--shadow);
            transition: var(--transition);
        }
        
        .chart-card:hover {
            box-shadow: var(--shadow-lg);
        }
        
        .chart-card .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        
        .chart-card .chart-header h3 {
            font-size: 15px;
            font-weight: 600;
        }
        
        .chart-card canvas {
            max-height: 250px;
            width: 100% !important;
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
        }
        
        table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        
        table tbody tr {
            transition: var(--transition);
        }
        
        table tbody tr:hover {
            background: rgba(47, 128, 237, 0.04);
        }
        
        table tbody tr:last-child td {
            border-bottom: none;
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
        
        .status-active {
            background: #D1FAE5;
            color: #065F46;
        }
        
        .status-warning {
            background: #FEF3C7;
            color: #92400E;
        }
        
        .status-danger {
            background: #FEE2E2;
            color: #DC2626;
        }
        
        .role-badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            background: rgba(47, 128, 237, 0.1);
            color: var(--primary);
        }
        
        .activity-item {
            display: flex;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
            align-items: center;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(47, 128, 237, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            flex-shrink: 0;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-content .action {
            font-weight: 500;
            font-size: 14px;
        }
        
        .activity-content .details {
            font-size: 13px;
            color: var(--secondary-text);
        }
        
        .activity-content .time {
            font-size: 12px;
            color: var(--secondary-text);
        }
        
        .empty-state {
            text-align: center;
            padding: 30px;
            color: var(--secondary-text);
        }
        
        .empty-state i {
            font-size: 30px;
            display: block;
            margin-bottom: 10px;
            opacity: 0.3;
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
        
        /* ============================================
           RESPONSIVE
           ============================================ */
        
        @media (max-width: 1024px) {
            .main-content {
                padding: 20px 24px 32px;
                width: calc(100% - 280px);
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
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
            
            .date-presets {
                width: 100%;
                justify-content: center;
            }
            
            .date-presets .preset-btn {
                flex: 1;
                text-align: center;
                font-size: 11px;
                padding: 4px 8px;
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
            
            .charts-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            
            .chart-card {
                padding: 16px;
            }
            
            .chart-card canvas {
                max-height: 180px;
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            
            table {
                font-size: 13px;
            }
            
            table th,
            table td {
                padding: 10px 12px;
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
            
            .date-presets {
                flex-wrap: wrap;
            }
            
            .date-presets .preset-btn {
                flex: 1;
                min-width: 60px;
                font-size: 10px;
                padding: 4px 6px;
            }
            
            table th,
            table td {
                padding: 8px 10px;
                font-size: 12px;
            }
            
            .fullscreen-toggle {
                bottom: 12px;
                right: 12px;
                width: 40px;
                height: 40px;
                font-size: 16px;
            }
        }
        
        @media print {
            .sidebar,
            .top-bar-actions,
            .btn,
            .no-print,
            .fullscreen-toggle {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0 !important;
                padding: 20px !important;
                width: 100% !important;
            }
            
            .stat-card,
            .chart-card,
            .table-container {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            
            body {
                background: white !important;
                color: black !important;
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
                    <h1>Dashboard</h1>
                    <p>Supply chain analytics and insights overview</p>
                </div>
                <div class="top-bar-actions">
                    <!-- Back to Dashboard Button (already on dashboard, but shown as active) -->
                    <a href="dashboard.php" class="btn btn-back" style="border-color: var(--primary); color: var(--primary);">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                    
                    <!-- Date Presets -->
                    <div class="date-presets">
                        <button class="preset-btn <?php echo $datePreset === 'today' ? 'active' : ''; ?>" onclick="setDatePreset('today')">Today</button>
                        <button class="preset-btn <?php echo $datePreset === 'last_7' ? 'active' : ''; ?>" onclick="setDatePreset('last_7')">Last 7 Days</button>
                        <button class="preset-btn <?php echo $datePreset === 'this_month' ? 'active' : ''; ?>" onclick="setDatePreset('this_month')">This Month</button>
                        <button class="preset-btn <?php echo $datePreset === 'last_30' ? 'active' : ''; ?>" onclick="setDatePreset('last_30')">Last 30 Days</button>
                        <form method="GET" style="display: flex; gap: 4px; align-items: center;">
                            <input type="date" name="date_from" value="<?php echo $dateFrom; ?>" style="width: 120px;">
                            <span style="color: var(--secondary-text); font-size: 12px;">to</span>
                            <input type="date" name="date_to" value="<?php echo $dateTo; ?>" style="width: 120px;">
                            <input type="hidden" name="date_preset" value="custom">
                            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                        </form>
                    </div>
                    
                    <!-- Export Dropdown -->
                    <div class="dropdown">
                        <button class="btn btn-outline" onclick="toggleExportDropdown()">
                            <i class="fas fa-download"></i> Export Report
                        </button>
                        <div class="dropdown-content" id="exportDropdown">
                            <a href="#" onclick="exportReport('pdf')">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </a>
                            <a href="#" onclick="exportReport('csv')">
                                <i class="fas fa-file-csv"></i> Export CSV
                            </a>
                            <a href="#" onclick="exportReport('excel')">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </a>
                        </div>
                    </div>
                    
                    <a href="logout.php" class="btn btn-outline">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
            
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="label"><i class="fas fa-box"></i> Total Products</div>
                    <div class="value"><?php echo number_format($totalProducts); ?></div>
                    <div class="sub-value"><?php echo $outOfStock; ?> out of stock</div>
                </div>
                <div class="stat-card">
                    <div class="label"><i class="fas fa-truck"></i> Total Suppliers</div>
                    <div class="value"><?php echo number_format($totalSuppliers); ?></div>
                    <div class="sub-value">Active vendors</div>
                </div>
                <div class="stat-card">
                    <div class="label"><i class="fas fa-file-invoice"></i> Purchase Orders</div>
                    <div class="value"><?php echo number_format($totalPOs); ?></div>
                    <div class="sub-value"><?php echo $pendingPOs; ?> pending</div>
                </div>
                <div class="stat-card">
                    <div class="label"><i class="fas fa-warehouse"></i> Inventory Value</div>
                    <div class="value">₱<?php echo number_format($inventoryValue, 2); ?></div>
                    <div class="sub-value">Total stock value</div>
                </div>
            </div>
            
            <!-- Additional Stats Row -->
            <div class="stats-grid" style="margin-bottom: 28px;">
                <div class="stat-card" style="border-color: var(--accent);">
                    <div class="label"><i class="fas fa-arrow-down" style="color: var(--accent);"></i> Received</div>
                    <div class="value" style="color: var(--accent);"><?php echo number_format($stockMovements['total_received'] ?? 0); ?></div>
                    <div class="sub-value">Units received (<?php echo $datePreset; ?>)</div>
                </div>
                <div class="stat-card" style="border-color: #DC2626;">
                    <div class="label"><i class="fas fa-arrow-up" style="color: #DC2626;"></i> Issued</div>
                    <div class="value" style="color: #DC2626;"><?php echo number_format($stockMovements['total_issued'] ?? 0); ?></div>
                    <div class="sub-value">Units issued (<?php echo $datePreset; ?>)</div>
                </div>
                <div class="stat-card" style="border-color: #1E40AF;">
                    <div class="label"><i class="fas fa-ship" style="color: #1E40AF;"></i> Total Shipments</div>
                    <div class="value" style="color: #1E40AF;"><?php echo number_format($totalShipments); ?></div>
                    <div class="sub-value">All shipments</div>
                </div>
                <div class="stat-card" style="border-color: #F59E0B;">
                    <div class="label"><i class="fas fa-exclamation-triangle" style="color: #F59E0B;"></i> Low Stock Items</div>
                    <div class="value" style="color: #F59E0B;"><?php echo number_format($lowStock); ?></div>
                    <div class="sub-value">Items below reorder point</div>
                </div>
            </div>
            
            <!-- Charts -->
            <div class="charts-grid">
                <!-- Inventory Value Trend -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>Inventory Value Trend</h3>
                        <span class="role-badge">Top Products</span>
                    </div>
                    <canvas id="inventoryChart"></canvas>
                </div>
                
                <!-- Supplier Performance -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>Supplier Performance Scorecard</h3>
                        <span class="role-badge">Overall Score</span>
                    </div>
                    <canvas id="supplierChart"></canvas>
                </div>
            </div>
            
            <!-- Recent Activity & Top Products -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 28px;">
                <!-- Recent Activity -->
                <div class="table-container">
                    <div class="table-header">
                        <h2><i class="fas fa-history" style="color: var(--primary);"></i> Recent Activity</h2>
                    </div>
                    <div style="padding: 16px 24px;">
                        <?php if (!empty($recentActivities)): ?>
                            <?php foreach ($recentActivities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-circle" style="font-size: 10px;"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="action"><?php echo htmlspecialchars($activity['action']); ?></div>
                                    <div class="details"><?php echo htmlspecialchars($activity['description']); ?></div>
                                    <div class="time"><?php echo date('M d, Y h:i A', strtotime($activity['created_at'])); ?></div>
                                </div>
                                <span class="role-badge"><?php echo htmlspecialchars($activity['user_name'] ?? 'System'); ?></span>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-history"></i>
                                <p>No recent activity</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Top Products -->
                <div class="table-container">
                    <div class="table-header">
                        <h2><i class="fas fa-chart-line" style="color: var(--accent);"></i> Top Products by Value</h2>
                    </div>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Stock</th>
                                    <th>Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($topProducts)): ?>
                                    <?php foreach ($topProducts as $product): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($product['product_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($product['sku']); ?></td>
                                        <td><?php echo number_format($product['current_stock']); ?></td>
                                        <td>₱<?php echo number_format($product['total_value'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="empty-state">
                                            <i class="fas fa-box"></i>
                                            <p>No products found</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        // ============================================
        // DATE PRESETS
        // ============================================
        function setDatePreset(preset) {
            var url = new URL(window.location.href);
            url.searchParams.set('date_preset', preset);
            url.searchParams.delete('date_from');
            url.searchParams.delete('date_to');
            window.location.href = url.toString();
        }
        
        // ============================================
        // EXPORT DROPDOWN
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
        
        function exportReport(format) {
            var url = new URL(window.location.href);
            url.searchParams.set('action', 'export');
            url.searchParams.set('format', format);
            window.location.href = url.toString();
        }
        
        // ============================================
        // CHARTS
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            // Inventory Chart
            var ctx1 = document.getElementById('inventoryChart');
            if (ctx1) {
                var inventoryData = <?php 
                    $labels = [];
                    $values = [];
                    foreach ($topProducts as $product) {
                        $labels[] = $product['product_name'];
                        $values[] = $product['total_value'];
                    }
                    echo json_encode(['labels' => $labels, 'values' => $values]);
                ?>;
                
                new Chart(ctx1, {
                    type: 'bar',
                    data: {
                        labels: inventoryData.labels,
                        datasets: [{
                            label: 'Stock Value (₱)',
                            data: inventoryData.values,
                            backgroundColor: [
                                'rgba(47, 128, 237, 0.7)',
                                'rgba(86, 204, 242, 0.7)',
                                'rgba(39, 174, 96, 0.7)',
                                'rgba(245, 158, 11, 0.7)',
                                'rgba(220, 38, 38, 0.7)'
                            ],
                            borderColor: [
                                '#2F80ED',
                                '#56CCF2',
                                '#27AE60',
                                '#F59E0B',
                                '#DC2626'
                            ],
                            borderWidth: 2,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(0,0,0,0.05)'
                                },
                                ticks: {
                                    callback: function(value) {
                                        return '₱' + value.toLocaleString();
                                    }
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            }
            
            // Supplier Chart
            var ctx2 = document.getElementById('supplierChart');
            if (ctx2) {
                var supplierData = <?php 
                    $labels = [];
                    $scores = [];
                    foreach ($topSuppliers as $supplier) {
                        $labels[] = $supplier['company_name'];
                        $scores[] = round($supplier['overall_score'] ?? 0, 1);
                    }
                    echo json_encode(['labels' => $labels, 'scores' => $scores]);
                ?>;
                
                new Chart(ctx2, {
                    type: 'bar',
                    data: {
                        labels: supplierData.labels,
                        datasets: [{
                            label: 'Overall Score %',
                            data: supplierData.scores,
                            backgroundColor: [
                                'rgba(39, 174, 96, 0.7)',
                                'rgba(47, 128, 237, 0.7)',
                                'rgba(86, 204, 242, 0.7)',
                                'rgba(245, 158, 11, 0.7)',
                                'rgba(220, 38, 38, 0.7)'
                            ],
                            borderColor: [
                                '#27AE60',
                                '#2F80ED',
                                '#56CCF2',
                                '#F59E0B',
                                '#DC2626'
                            ],
                            borderWidth: 2,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100,
                                grid: {
                                    color: 'rgba(0,0,0,0.05)'
                                },
                                ticks: {
                                    callback: function(value) {
                                        return value + '%';
                                    }
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            }
        });
        
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
    </script>
</body>
</html>