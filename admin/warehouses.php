<?php
// admin/warehouses.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth(['admin','warehouse_manager']);

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

// Create Warehouse
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $warehouse_code = isset($_POST['warehouse_code']) ? trim($_POST['warehouse_code']) : '';
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $location = isset($_POST['location']) ? trim($_POST['location']) : '';
    $groupName = trim((string)($_POST['group_name'] ?? ''));
    $capacity = isset($_POST['capacity']) ? (int)$_POST['capacity'] : 0;
    $type = isset($_POST['type']) ? $_POST['type'] : 'standard';
    
    $errors = [];
    if (empty($warehouse_code)) $errors[] = 'Warehouse code is required';
    if (empty($name)) $errors[] = 'Warehouse name is required';
    if ($groupName === '') $errors[] = 'Group or department is required';
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $groupStmt = $pdo->prepare('SELECT id FROM inventory_groups WHERE LOWER(group_name) = LOWER(?)');
            $groupStmt->execute([$groupName]);
            $groupId = $groupStmt->fetchColumn();
            if (!$groupId) {
                $groupStmt = $pdo->prepare('INSERT INTO inventory_groups (group_name) VALUES (?)');
                $groupStmt->execute([$groupName]);
                $groupStmt = $pdo->prepare('SELECT id FROM inventory_groups WHERE LOWER(group_name) = LOWER(?)');
                $groupStmt->execute([$groupName]);
                $groupId = $groupStmt->fetchColumn();
            }
            $assignedStmt = $pdo->prepare('SELECT id FROM warehouses WHERE group_id = ? AND is_archived = 0 LIMIT 1');
            $assignedStmt->execute([$groupId]);
            if ($assignedStmt->fetchColumn()) throw new RuntimeException('This group already has a dedicated warehouse.');
            $stmt = $pdo->prepare("INSERT INTO warehouses (warehouse_code, name, location, capacity, group_id, type, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
            $stmt->execute([$warehouse_code, $name, $location, $capacity, $groupId, $type]);
            $pdo->commit();
            logAudit($_SESSION['user_id'], 'create_warehouse', 'warehouse', "Created warehouse: $name");
            $_SESSION['success'] = "Warehouse created successfully!";
            header('Location: warehouses.php');
            exit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Error creating warehouse: " . $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Error creating warehouse: " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// Update Warehouse
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $warehouse_code = isset($_POST['warehouse_code']) ? trim($_POST['warehouse_code']) : '';
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $location = isset($_POST['location']) ? trim($_POST['location']) : '';
    $groupName = trim((string)($_POST['group_name'] ?? ''));
    $capacity = isset($_POST['capacity']) ? (int)$_POST['capacity'] : 0;
    $type = isset($_POST['type']) ? $_POST['type'] : 'standard';
    $status = isset($_POST['status']) ? $_POST['status'] : 'active';

    if ($status !== 'active') {
        $stockCheck = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM warehouse_inventory WHERE warehouse_id = ?");
        $stockCheck->execute([$id]);
        if ((int)$stockCheck->fetchColumn() > 0) {
            $_SESSION['error'] = 'Move all stock out of this warehouse before marking it inactive or under maintenance.';
            header('Location: warehouses.php');
            exit();
        }
    }
    
    try {
        if ($groupName === '') throw new RuntimeException('Group or department is required.');
        $pdo->beginTransaction();
        $groupStmt = $pdo->prepare('SELECT id FROM inventory_groups WHERE LOWER(group_name) = LOWER(?)');
        $groupStmt->execute([$groupName]);
        $groupId = $groupStmt->fetchColumn();
        if (!$groupId) {
            $groupStmt = $pdo->prepare('INSERT INTO inventory_groups (group_name) VALUES (?)');
            $groupStmt->execute([$groupName]);
            $groupStmt = $pdo->prepare('SELECT id FROM inventory_groups WHERE LOWER(group_name) = LOWER(?)');
            $groupStmt->execute([$groupName]);
            $groupId = $groupStmt->fetchColumn();
        }
        $assignedStmt = $pdo->prepare('SELECT id FROM warehouses WHERE group_id = ? AND id <> ? AND is_archived = 0 LIMIT 1');
        $assignedStmt->execute([$groupId, $id]);
        if ($assignedStmt->fetchColumn()) throw new RuntimeException('This group already has a dedicated warehouse.');
        $stmt = $pdo->prepare("UPDATE warehouses SET warehouse_code = ?, name = ?, location = ?, capacity = ?, group_id = ?, type = ?, status = ? WHERE id = ?");
        $stmt->execute([$warehouse_code, $name, $location, $capacity, $groupId, $type, $status, $id]);
        $pdo->commit();
        logAudit($_SESSION['user_id'], 'update_warehouse', 'warehouse', "Updated warehouse: $name");
        $_SESSION['success'] = "Warehouse updated successfully!";
        header('Location: warehouses.php');
        exit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Error updating warehouse: " . $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Error updating warehouse: " . $e->getMessage();
    }
}

// Archive Warehouse
if ($action === 'archive' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM warehouse_inventory WHERE warehouse_id = ?");
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() > 0) {
        $_SESSION['error'] = 'Move all stock out of this warehouse before archiving it.';
    } else {
        archiveRecord('warehouses', $id);
        logAudit($_SESSION['user_id'], 'archive_warehouse', 'warehouse', "Archived warehouse ID: $id");
        $_SESSION['success'] = "Warehouse archived successfully!";
    }
    header('Location: warehouses.php');
    exit();
}

// Restore Warehouse
if ($action === 'restore' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    restoreRecord('warehouses', $id);
    logAudit($_SESSION['user_id'], 'restore_warehouse', 'warehouse', "Restored warehouse ID: $id");
    $_SESSION['success'] = "Warehouse restored successfully!";
    header('Location: warehouses.php?archived=1');
    exit();
}

// Delete Warehouse (Permanent)
if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM warehouse_inventory WHERE warehouse_id = ?");
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException('Move all stock out of this warehouse before deleting it.');
        }
        $stmt = $pdo->prepare("DELETE FROM warehouses WHERE id = ?");
        $stmt->execute([$id]);
        logAudit($_SESSION['user_id'], 'delete_warehouse', 'warehouse', "Permanently deleted warehouse ID: $id");
        $_SESSION['success'] = "Warehouse permanently deleted!";
    } catch (Throwable $e) {
        $_SESSION['error'] = "Error deleting warehouse: " . $e->getMessage();
    }
    header('Location: warehouses.php?archived=1');
    exit();
}

// Get warehouses
$showArchived = isset($_GET['archived']) ? 1 : 0;
try {
    $stmt = $pdo->prepare("SELECT w.*, g.group_name, COUNT(DISTINCT wi.product_id) AS stocked_products, COALESCE(SUM(wi.quantity), 0) AS stored_units
                           FROM warehouses w
                           LEFT JOIN inventory_groups g ON g.id = w.group_id
                           LEFT JOIN warehouse_inventory wi ON wi.warehouse_id = w.id
                           WHERE w.is_archived = ?
                           GROUP BY w.id, g.group_name
                           ORDER BY w.created_at DESC");
    $stmt->execute([$showArchived]);
    $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $warehouses = [];
    $error = "Error fetching warehouses: " . $e->getMessage();
}

// Get warehouse for edit
$editWarehouse = null;
if ($action === 'edit' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("SELECT w.*, g.group_name FROM warehouses w LEFT JOIN inventory_groups g ON g.id = w.group_id WHERE w.id = ?");
        $stmt->execute([$_GET['id']]);
        $editWarehouse = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Error fetching warehouse: " . $e->getMessage();
    }
}

// Get stats
$totalWarehouses = 0;
$activeWarehouses = 0;
$totalCapacity = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count, SUM(capacity) as total_capacity FROM warehouses WHERE is_archived = 0");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalWarehouses = $stats['count'] ?? 0;
    $totalCapacity = $stats['total_capacity'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM warehouses WHERE status = 'active' AND is_archived = 0");
    $activeWarehouses = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
} catch (PDOException $e) {
    // Stats will be 0
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouses - GlobalSCM</title>
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
            min-width: 700px;
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
        }
        table td { padding: 12px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        table tbody tr:hover { background: rgba(47, 128, 237, 0.04); }
        
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
        
        .status-active { background: #D1FAE5; color: #065F46; }
        .status-inactive { background: #FEE2E2; color: #DC2626; }
        .status-maintenance { background: #FEF3C7; color: #92400E; }
        
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
        
        .warehouse-type-badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            background: rgba(47, 128, 237, 0.08);
            color: var(--secondary-text);
            min-width: 50px;
            text-align: center;
        }
        .warehouse-type-badge.standard { background: #DBEAFE; color: #1E40AF; }
        .warehouse-type-badge.cold_chain { background: #D1FAE5; color: #065F46; }
        .warehouse-type-badge.hazmat { background: #FEE2E2; color: #DC2626; }
        
        .empty-state { text-align: center; padding: 40px; color: var(--secondary-text); }
        .empty-state i { font-size: 40px; display: block; margin-bottom: 10px; opacity: 0.3; }
        
        /* Modal */
        .modal-overlay {
            display: <?php echo ($editWarehouse || isset($error) || $action === 'create') ? 'flex' : 'none'; ?>;
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
            border-radius: var(--radius);
            padding: 30px;
            max-width: 500px;
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
        .modal .close-modal { margin-left: auto; background: none; border: none; font-size: 24px; color: var(--secondary-text); cursor: pointer; padding: 0 4px; }
        .modal .close-modal:hover { color: var(--text); }
        
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
        .form-group input:focus, .form-group select:focus { outline: none; border-color: var(--primary); }
        .form-actions { display: flex; gap: 10px; margin-top: 20px; }
        .form-actions .btn { flex: 1; justify-content: center; }
        
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
        }
        .sidebar-overlay.active { display: block; }
        
        @media (max-width: 768px) {
            .sidebar { width: 0; padding: 0; overflow: hidden; position: fixed; left: -320px; }
            .sidebar.open { left: 0; width: 300px; padding: 24px 16px; }
            .sidebar-toggle-btn { display: block; }
            .main-content { margin-left: 0; padding: 16px; width: 100%; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
            .stat-card { padding: 16px; }
            .stat-card .value { font-size: 22px; }
            .top-bar { flex-direction: column; align-items: stretch; }
            .top-bar-actions { justify-content: center; }
            .top-bar-actions .btn { flex: 1; min-width: 120px; justify-content: center; }
        }
        
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .top-bar-actions .btn { min-width: 100%; }
            .action-buttons { flex-direction: column; align-items: center; }
            .action-buttons .btn-sm { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <div class="admin-layout">
        <?php include 'partials/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="top-bar">
                <div class="page-title">
                    <h1>Warehouses</h1>
                    <p>Manage your warehouse facilities and zones</p>
                </div>
                <div class="top-bar-actions">
                    <button class="btn btn-primary" onclick="openCreateModal()">
                        <i class="fas fa-plus"></i> Add Warehouse
                    </button>
                    <a href="warehouse-zones.php" class="btn btn-success">
                        <i class="fas fa-layer-group"></i> Zone Management
                    </a>
                    <a href="warehouses.php<?php echo $showArchived ? '' : '?archived=1'; ?>" class="btn btn-outline">
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
                    <div class="label"><i class="fas fa-warehouse"></i> Total Warehouses</div>
                    <div class="value"><?php echo number_format($totalWarehouses); ?></div>
                </div>
                <div class="stat-card" style="border-color: var(--accent);">
                    <div class="label"><i class="fas fa-check-circle" style="color: var(--accent);"></i> Active</div>
                    <div class="value" style="color: var(--accent);"><?php echo number_format($activeWarehouses); ?></div>
                </div>
                <div class="stat-card">
                    <div class="label"><i class="fas fa-boxes"></i> Total Capacity</div>
                    <div class="value"><?php echo number_format($totalCapacity); ?></div>
                </div>
            </div>
            
            <div class="table-container">
                <div class="table-header">
                    <h2><?php echo $showArchived ? 'Archived Warehouses' : 'Active Warehouses'; ?></h2>
                    <span class="role-badge"><?php echo count($warehouses); ?> warehouses</span>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Warehouse Code</th>
                                <th>Name</th>
                                <th>Group</th>
                                <th>Location</th>
                                <th>Capacity</th>
                                <th>Products</th>
                                <th>Units Stored</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($warehouses)): ?>
                                <?php foreach ($warehouses as $warehouse): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($warehouse['warehouse_code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($warehouse['name']); ?></td>
                                    <td><?php echo htmlspecialchars($warehouse['group_name'] ?? 'Unassigned'); ?></td>
                                    <td><?php echo htmlspecialchars($warehouse['location'] ?? 'N/A'); ?></td>
                                    <td><?php echo number_format($warehouse['capacity']); ?></td>
                                    <td><?php echo number_format($warehouse['stocked_products']); ?></td>
                                    <td><?php echo number_format($warehouse['stored_units']); ?></td>
                                    <td>
                                        <span class="warehouse-type-badge <?php echo $warehouse['type']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $warehouse['type'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php 
                                            echo $warehouse['status'] === 'active' ? 'status-active' : 
                                                ($warehouse['status'] === 'maintenance' ? 'status-maintenance' : 'status-inactive'); 
                                        ?>">
                                            <?php echo ucfirst($warehouse['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if (!$showArchived): ?>
                                                <a href="inventory.php?warehouse_id=<?php echo (int)$warehouse['id']; ?>"
                                                   class="btn btn-primary btn-sm" title="View Warehouse Inventory">
                                                    <i class="fas fa-boxes-stacked"></i>
                                                </a>
                                                <a href="warehouse-zones.php?warehouse_id=<?php echo $warehouse['id']; ?>" 
                                                   class="btn btn-success btn-sm" title="View Zones">
                                                    <i class="fas fa-layer-group"></i>
                                                </a>
                                                <a href="warehouses.php?action=edit&id=<?php echo $warehouse['id']; ?>" 
                                                   class="btn btn-primary btn-sm" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="warehouses.php?action=archive&id=<?php echo $warehouse['id']; ?>" 
                                                   class="btn btn-warning btn-sm" 
                                                   onclick="return confirm('Archive this warehouse?');" title="Archive">
                                                    <i class="fas fa-archive"></i>
                                                </a>
                                            <?php else: ?>
                                                <a href="warehouses.php?action=restore&id=<?php echo $warehouse['id']; ?>" 
                                                   class="btn btn-success btn-sm"
                                                   onclick="return confirm('Restore this warehouse?');" title="Restore">
                                                    <i class="fas fa-undo"></i>
                                                </a>
                                                <a href="warehouses.php?action=delete&id=<?php echo $warehouse['id']; ?>" 
                                                   class="btn btn-danger btn-sm"
                                                   onclick="return confirm('⚠️ This will permanently delete this warehouse. Continue?');" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="empty-state">
                                        <i class="fas fa-warehouse"></i>
                                        <p>No warehouses found</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Create/Edit Modal -->
    <?php if ($action === 'create' || $editWarehouse): ?>
    <div class="modal-overlay" style="display: flex;" id="warehouseModal">
        <div class="modal">
            <h3>
                <i class="fas fa-<?php echo $editWarehouse ? 'edit' : 'warehouse'; ?>" style="color: var(--primary);"></i>
                <?php echo $editWarehouse ? 'Edit Warehouse' : 'Add New Warehouse'; ?>
                <button type="button" class="close-modal" onclick="closeCreateModal()">&times;</button>
            </h3>
            <form method="POST" action="warehouses.php?action=<?php echo $editWarehouse ? 'edit' : 'create'; ?>">
                <?php if ($editWarehouse): ?>
                <input type="hidden" name="id" value="<?php echo $editWarehouse['id']; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Warehouse Code *</label>
                    <input type="text" name="warehouse_code" required 
                           value="<?php echo isset($editWarehouse['warehouse_code']) ? htmlspecialchars($editWarehouse['warehouse_code']) : 'WH-' . date('Ymd') . '-' . str_pad(mt_rand(1, 99), 2, '0', STR_PAD_LEFT); ?>"
                           placeholder="e.g., WH-001">
                </div>
                
                <div class="form-group">
                    <label>Warehouse Name *</label>
                    <input type="text" name="name" required 
                           value="<?php echo isset($editWarehouse['name']) ? htmlspecialchars($editWarehouse['name']) : ''; ?>"
                           placeholder="e.g., Main Distribution Center">
                </div>

                <div class="form-group">
                    <label>Group / Department *</label>
                    <input type="text" name="group_name" required maxlength="150"
                           value="<?php echo isset($editWarehouse['group_name']) ? htmlspecialchars($editWarehouse['group_name']) : htmlspecialchars($groupName ?? ''); ?>"
                           placeholder="e.g., Tours, Maintenance, Administration">
                </div>
                
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" 
                           value="<?php echo isset($editWarehouse['location']) ? htmlspecialchars($editWarehouse['location']) : ''; ?>"
                           placeholder="e.g., 123 Logistics Ave, City">
                </div>
                
                <div class="form-group">
                    <label>Capacity (units)</label>
                    <input type="number" name="capacity" 
                           value="<?php echo isset($editWarehouse['capacity']) ? $editWarehouse['capacity'] : ''; ?>"
                           placeholder="e.g., 10000">
                </div>
                
                <div class="form-group">
                    <label>Type</label>
                    <select name="type">
                        <option value="standard" <?php echo (isset($editWarehouse['type']) && $editWarehouse['type'] === 'standard') ? 'selected' : ''; ?>>Standard</option>
                        <option value="cold_chain" <?php echo (isset($editWarehouse['type']) && $editWarehouse['type'] === 'cold_chain') ? 'selected' : ''; ?>>Cold Chain</option>
                        <option value="hazmat" <?php echo (isset($editWarehouse['type']) && $editWarehouse['type'] === 'hazmat') ? 'selected' : ''; ?>>Hazmat</option>
                    </select>
                </div>
                
                <?php if ($editWarehouse): ?>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?php echo (isset($editWarehouse['status']) && $editWarehouse['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="maintenance" <?php echo (isset($editWarehouse['status']) && $editWarehouse['status'] === 'maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                        <option value="inactive" <?php echo (isset($editWarehouse['status']) && $editWarehouse['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="form-actions">
                    <button type="button" onclick="closeCreateModal()" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?php echo $editWarehouse ? 'Update' : 'Create'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
        // ============================================
        // CREATE MODAL
        // ============================================
        function openCreateModal() {
            var modal = document.getElementById('warehouseModal');
            if (modal) {
                modal.style.display = 'flex';
            } else {
                // If modal doesn't exist, redirect to create page
                window.location.href = 'warehouses.php?action=create';
            }
        }
        
        function closeCreateModal() {
            var modal = document.getElementById('warehouseModal');
            if (modal) {
                modal.style.display = 'none';
                // Redirect to remove query params
                window.location.href = 'warehouses.php';
            }
        }
        
        // Close modal on background click
        document.addEventListener('DOMContentLoaded', function() {
            var modal = document.getElementById('warehouseModal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeCreateModal();
                    }
                });
            }
            
            // Escape key to close
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    var modal = document.getElementById('warehouseModal');
                    if (modal && modal.style.display === 'flex') {
                        closeCreateModal();
                    }
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