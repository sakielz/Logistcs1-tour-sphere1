<?php
// admin/logs.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth('admin');

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

// Get filters
$moduleFilter = isset($_GET['module']) ? $_GET['module'] : '';
$userFilter = isset($_GET['user_id']) ? (int)$_GET['user_id'] : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-7 days'));
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

$query = "SELECT al.*, u.full_name as user_name, u.username 
          FROM audit_logs al 
          LEFT JOIN users u ON al.user_id = u.id 
          WHERE DATE(al.created_at) BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];

if (!empty($moduleFilter)) {
    $query .= " AND al.module = ?";
    $params[] = $moduleFilter;
}

if (!empty($userFilter)) {
    $query .= " AND al.user_id = ?";
    $params[] = $userFilter;
}

$query .= " ORDER BY al.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get users for filter
$stmt = $pdo->query("SELECT id, full_name, username FROM users WHERE is_archived = false ORDER BY full_name");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get modules for filter
$stmt = $pdo->query("SELECT DISTINCT module FROM audit_logs ORDER BY module");
$modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats - use the same filters as the activity log table
$statsQuery = "SELECT 
    COUNT(*) as total,
    COUNT(DISTINCT al.user_id) as unique_users,
    COUNT(DISTINCT al.module) as unique_modules
    FROM audit_logs al
    WHERE DATE(al.created_at) BETWEEN ? AND ?";
$statsParams = [$dateFrom, $dateTo];

if (!empty($moduleFilter)) {
    $statsQuery .= " AND al.module = ?";
    $statsParams[] = $moduleFilter;
}

if (!empty($userFilter)) {
    $statsQuery .= " AND al.user_id = ?";
    $statsParams[] = $userFilter;
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
    <title>Audit Logs - GlobalSCM</title>
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
        
        .sidebar-brand h2::after {
            content: '®';
            font-size: 10px;
            color: var(--secondary-text);
            font-weight: 400;
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
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .nav-section-title:hover {
            color: var(--text);
        }
        
        .nav-section-title .collapse-icon {
            font-size: 12px;
            transition: var(--transition);
        }
        
        .nav-section-title .collapse-icon.collapsed {
            transform: rotate(-90deg);
        }
        
        .nav-items {
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        
        .nav-items.collapsed {
            max-height: 0 !important;
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
        
        .nav-item:hover {
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
        
        .nav-item.active {
            background: rgba(47, 128, 237, 0.1);
            color: var(--primary);
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
        
        .sub-nav {
            padding-left: 45px !important;
            font-size: 13px !important;
            padding-top: 6px !important;
            padding-bottom: 6px !important;
            font-weight: 400 !important;
        }
        
        .sub-nav i {
            width: 18px !important;
            font-size: 13px !important;
        }
        
        /* Quick Create Button in Sidebar */
        .quick-create-section {
            margin-top: auto;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }
        
        .quick-create-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: var(--radius-sm);
            background: var(--primary);
            color: white;
            text-decoration: none;
            transition: var(--transition);
            font-weight: 500;
            font-size: 14px;
        }
        
        .quick-create-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(47, 128, 237, 0.3);
        }
        
        .quick-create-btn i {
            font-size: 16px;
        }
        
        .quick-create-dropdown {
            position: relative;
        }
        
        .quick-create-dropdown .dropdown-content {
            display: none;
            position: absolute;
            bottom: 100%;
            left: 0;
            background: var(--card);
            min-width: 200px;
            box-shadow: var(--shadow-lg);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            padding: 8px 0;
            z-index: 10;
        }
        
        .quick-create-dropdown .dropdown-content.show {
            display: block;
        }
        
        .quick-create-dropdown .dropdown-content a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 16px;
            color: var(--text);
            text-decoration: none;
            font-size: 13px;
            transition: var(--transition);
        }
        
        .quick-create-dropdown .dropdown-content a:hover {
            background: rgba(47, 128, 237, 0.05);
            color: var(--primary);
        }
        
        .quick-create-dropdown .dropdown-content a i {
            width: 18px;
            color: var(--secondary-text);
        }
        
        /* User Profile in Sidebar */
        .sidebar-profile {
            padding-top: 16px;
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
        
        /* ===== FILTER BAR ===== */
        .filter-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
            width: 100%;
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
            min-width: 150px;
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
            min-width: 800px;
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
        
        .module-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            background: rgba(47, 128, 237, 0.1);
            color: var(--primary);
        }
        
        .log-action {
            font-weight: 500;
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 12px;
        }
        
        .log-action.create { background: #D1FAE5; color: #065F46; }
        .log-action.update { background: #FEF3C7; color: #92400E; }
        .log-action.delete { background: #FEE2E2; color: #DC2626; }
        .log-action.login { background: #DBEAFE; color: #1E40AF; }
        .log-action.logout { background: #E5E7EB; color: #374151; }
        
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
            
            .filter-bar select,
            .filter-bar input[type="date"] {
                width: 100%;
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
        }
        
        @media print {
            .sidebar,
            .top-bar-actions,
            .btn,
            .no-print {
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
        }
    </style>
</head>
<body>
    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <div class="admin-layout">
        <?php include 'partials/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="top-bar">
                <div class="page-title">
                    <h1>Audit Logs</h1>
                    <p>System activity and compliance tracking</p>
                </div>
                <div class="top-bar-actions">
                    <a href="dashboard.php" class="btn btn-back">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <button class="btn btn-outline" onclick="window.print();">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
            
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="label"><i class="fas fa-history"></i> Total Activities</div>
                    <div class="value"><?php echo number_format($stats['total'] ?? 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="label"><i class="fas fa-users"></i> Unique Users</div>
                    <div class="value"><?php echo number_format($stats['unique_users'] ?? 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="label"><i class="fas fa-cubes"></i> Modules Used</div>
                    <div class="value"><?php echo number_format($stats['unique_modules'] ?? 0); ?></div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filter-bar">
                <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; width: 100%; align-items: center;">
                    <select name="module">
                        <option value="">All Modules</option>
                        <?php foreach ($modules as $module): ?>
                        <option value="<?php echo htmlspecialchars($module['module']); ?>" <?php echo $moduleFilter == $module['module'] ? 'selected' : ''; ?>>
                            <?php echo ucfirst(htmlspecialchars($module['module'])); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select name="user_id">
                        <option value="">All Users</option>
                        <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>" <?php echo $userFilter == $user['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['full_name'] . ' (' . $user['username'] . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <input type="date" name="date_from" value="<?php echo $dateFrom; ?>">
                    <input type="date" name="date_to" value="<?php echo $dateTo; ?>">
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <?php if (!empty($moduleFilter) || !empty($userFilter) || $dateFrom != date('Y-m-d', strtotime('-7 days')) || $dateTo != date('Y-m-d')): ?>
                        <a href="logs.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Reset
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <div class="table-container">
                <div class="table-header">
                    <h2>Activity Log</h2>
                    <span class="role-badge"><?php echo count($logs); ?> entries</span>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Date/Time</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Module</th>
                                <th>Description</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($logs)): ?>
                                <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td style="font-size: 13px; color: var(--secondary-text); white-space: nowrap;">
                                        <?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?>
                                    </td>
                                    <td>
                                        <div><strong><?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?></strong></div>
                                        <div style="font-size: 12px; color: var(--secondary-text);">
                                            <?php echo htmlspecialchars($log['username'] ?? ''); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="log-action <?php 
                                            echo strpos($log['action'], 'create') !== false ? 'create' : 
                                                (strpos($log['action'], 'update') !== false ? 'update' : 
                                                (strpos($log['action'], 'delete') !== false || strpos($log['action'], 'archive') !== false ? 'delete' : 
                                                (strpos($log['action'], 'login') !== false ? 'login' : 
                                                (strpos($log['action'], 'logout') !== false ? 'logout' : ''))));
                                        ?>">
                                            <?php echo htmlspecialchars($log['action']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="module-badge">
                                            <?php echo ucfirst(htmlspecialchars($log['module'])); ?>
                                        </span>
                                    </td>
                                    <td style="max-width: 300px; word-break: break-word;">
                                        <?php echo htmlspecialchars($log['description']); ?>
                                    </td>
                                    <td style="font-size: 13px; font-family: monospace; color: var(--secondary-text);">
                                        <?php echo htmlspecialchars($log['ip_address']); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="empty-state">
                                        <i class="fas fa-history"></i>
                                        <p>No activity logs found for this period</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
    
    <script>
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
        // SIDEBAR COLLAPSIBLE SECTIONS
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.nav-section-title').forEach(function(title) {
                var items = title.nextElementSibling;
                if (items && items.classList.contains('nav-items')) {
                    // Set initial max-height
                    items.style.maxHeight = items.scrollHeight + 'px';
                    
                    title.addEventListener('click', function() {
                        var isCollapsed = items.classList.toggle('collapsed');
                        if (isCollapsed) {
                            items.style.maxHeight = '0';
                        } else {
                            items.style.maxHeight = items.scrollHeight + 'px';
                        }
                        var icon = this.querySelector('.collapse-icon');
                        if (icon) {
                            icon.classList.toggle('collapsed');
                        }
                    });
                }
            });
        });
        
        // ============================================
        // QUICK CREATE DROPDOWN
        // ============================================
        function toggleQuickCreate() {
            var dropdown = document.getElementById('quickCreateDropdown');
            dropdown.classList.toggle('show');
        }
        
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.quick-create-dropdown')) {
                document.querySelectorAll('.quick-create-dropdown .dropdown-content').forEach(function(el) {
                    el.classList.remove('show');
                });
            }
        });
    </script>
</body>
</html>