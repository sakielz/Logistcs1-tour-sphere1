<?php
// admin/warehouse-zones.php
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

$theme = getTheme();

// Get all warehouses for dropdown
try {
    $stmt = $pdo->query("SELECT id, name, warehouse_code FROM warehouses WHERE is_archived = 0 ORDER BY name");
    $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $warehouses = [];
}

// Handle zone creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $warehouse_id = isset($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : 0;
    $zone_code = isset($_POST['zone_code']) ? trim($_POST['zone_code']) : '';
    $zone_name = isset($_POST['zone_name']) ? trim($_POST['zone_name']) : '';
    $zone_type = isset($_POST['zone_type']) ? $_POST['zone_type'] : 'standard';
    $capacity = isset($_POST['capacity']) ? (int)$_POST['capacity'] : 0;
    $is_hazmat = isset($_POST['is_hazmat']) ? 1 : 0;
    $is_cold_chain = isset($_POST['is_cold_chain']) ? 1 : 0;
    $temperature = isset($_POST['temperature']) ? (float)$_POST['temperature'] : null;
    
    $errors = [];
    if (empty($warehouse_id)) $errors[] = 'Warehouse is required';
    if (empty($zone_code)) $errors[] = 'Zone code is required';
    if (empty($zone_name)) $errors[] = 'Zone name is required';
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO warehouse_zones (warehouse_id, zone_code, zone_name, zone_type, capacity, is_hazmat, is_cold_chain, temperature) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$warehouse_id, $zone_code, $zone_name, $zone_type, $capacity, $is_hazmat, $is_cold_chain, $temperature]);
            logAudit($_SESSION['user_id'], 'create_zone', 'warehouse', "Created zone: $zone_name");
            $_SESSION['success'] = "Zone created successfully!";
            header('Location: warehouse-zones.php');
            exit();
        } catch (PDOException $e) {
            $error = "Error creating zone: " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// Handle zone update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $warehouse_id = isset($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : 0;
    $zone_code = isset($_POST['zone_code']) ? trim($_POST['zone_code']) : '';
    $zone_name = isset($_POST['zone_name']) ? trim($_POST['zone_name']) : '';
    $zone_type = isset($_POST['zone_type']) ? $_POST['zone_type'] : 'standard';
    $capacity = isset($_POST['capacity']) ? (int)$_POST['capacity'] : 0;
    $is_hazmat = isset($_POST['is_hazmat']) ? 1 : 0;
    $is_cold_chain = isset($_POST['is_cold_chain']) ? 1 : 0;
    $temperature = isset($_POST['temperature']) ? (float)$_POST['temperature'] : null;
    
    try {
        $stmt = $pdo->prepare("UPDATE warehouse_zones SET warehouse_id = ?, zone_code = ?, zone_name = ?, zone_type = ?, capacity = ?, is_hazmat = ?, is_cold_chain = ?, temperature = ? WHERE id = ?");
        $stmt->execute([$warehouse_id, $zone_code, $zone_name, $zone_type, $capacity, $is_hazmat, $is_cold_chain, $temperature, $id]);
        logAudit($_SESSION['user_id'], 'update_zone', 'warehouse', "Updated zone: $zone_name");
        $_SESSION['success'] = "Zone updated successfully!";
        header('Location: warehouse-zones.php');
        exit();
    } catch (PDOException $e) {
        $error = "Error updating zone: " . $e->getMessage();
    }
}

// Handle zone deletion
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM warehouse_zones WHERE id = ?");
        $stmt->execute([$id]);
        logAudit($_SESSION['user_id'], 'delete_zone', 'warehouse', "Deleted zone ID: $id");
        $_SESSION['success'] = "Zone deleted successfully!";
        header('Location: warehouse-zones.php');
        exit();
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error deleting zone: " . $e->getMessage();
        header('Location: warehouse-zones.php');
        exit();
    }
}

// Handle Bin creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_bin') {
    $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
    $zone_id = !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null;
    $bin_number = trim($_POST['bin_number'] ?? '');
    $location_name = trim($_POST['location_name'] ?? '');
    $storage_capacity = (int)($_POST['storage_capacity'] ?? 100);
    $current_stock_levels = (int)($_POST['current_stock_levels'] ?? 0);
    $status = $_POST['status'] ?? 'available';

    if (empty($warehouse_id) || empty($bin_number)) {
        $error = "Warehouse and Bin Number are required.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO warehouse_bins (warehouse_id, zone_id, bin_number, location_name, storage_capacity, current_stock_levels, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$warehouse_id, $zone_id, $bin_number, $location_name, $storage_capacity, $current_stock_levels, $status]);
            logAudit($_SESSION['user_id'], 'create_bin', 'warehouse', "Created storage bin: $bin_number");
            $_SESSION['success'] = "Bin $bin_number created successfully!";
            header('Location: warehouse-zones.php?view=bins&warehouse_id=' . $warehouse_id);
            exit();
        } catch (Throwable $e) {
            $error = "Error creating bin: " . $e->getMessage();
        }
    }
}

// Handle Bin update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_bin') {
    $bin_id = (int)($_POST['bin_id'] ?? 0);
    $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
    $zone_id = !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null;
    $bin_number = trim($_POST['bin_number'] ?? '');
    $location_name = trim($_POST['location_name'] ?? '');
    $storage_capacity = (int)($_POST['storage_capacity'] ?? 100);
    $current_stock_levels = (int)($_POST['current_stock_levels'] ?? 0);
    $status = $_POST['status'] ?? 'available';

    try {
        $stmt = $pdo->prepare("UPDATE warehouse_bins SET warehouse_id = ?, zone_id = ?, bin_number = ?, location_name = ?, storage_capacity = ?, current_stock_levels = ?, status = ? WHERE id = ?");
        $stmt->execute([$warehouse_id, $zone_id, $bin_number, $location_name, $storage_capacity, $current_stock_levels, $status, $bin_id]);
        logAudit($_SESSION['user_id'], 'update_bin', 'warehouse', "Updated storage bin: $bin_number");
        $_SESSION['success'] = "Bin $bin_number updated successfully!";
        header('Location: warehouse-zones.php?view=bins&warehouse_id=' . $warehouse_id);
        exit();
    } catch (Throwable $e) {
        $error = "Error updating bin: " . $e->getMessage();
    }
}

// Handle Bin deletion
if (isset($_GET['delete_bin']) && isset($_GET['id'])) {
    $bin_id = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM warehouse_bins WHERE id = ?");
        $stmt->execute([$bin_id]);
        logAudit($_SESSION['user_id'], 'delete_bin', 'warehouse', "Deleted bin ID: $bin_id");
        $_SESSION['success'] = "Bin deleted successfully!";
        header('Location: warehouse-zones.php?view=bins');
        exit();
    } catch (Throwable $e) {
        $_SESSION['error'] = "Error deleting bin: " . $e->getMessage();
        header('Location: warehouse-zones.php?view=bins');
        exit();
    }
}

// Get zone for edit
$editZone = null;
if (isset($_GET['edit']) && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM warehouse_zones WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $editZone = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Error fetching zone: " . $e->getMessage();
    }
}

// Get bin for edit
$editBin = null;
if (isset($_GET['edit_bin']) && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM warehouse_bins WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $editBin = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Error fetching bin: " . $e->getMessage();
    }
}

// Auto-seed initial travel & tours sample bins if table is empty
try {
    $binCountCheck = (int)$pdo->query("SELECT COUNT(*) FROM warehouse_bins")->fetchColumn();
    if ($binCountCheck === 0 && !empty($warehouses)) {
        $firstWhId = (int)$warehouses[0]['id'];
        $sampleBins = [
            ['BIN-MKT-01', 'Rack A - Shelf 1 (Marketing Kits & Flyers)', 250, 45, 'available'],
            ['BIN-UNI-02', 'Rack B - Tier 2 (Staff Uniforms & Raincoats)', 300, 120, 'available'],
            ['BIN-PRT-03', 'Bay C - Bin 14 (Vehicle Spare Parts & Filters)', 150, 38, 'available'],
            ['BIN-EMG-04', 'Secure Locker 1 (First Aid & Emergency Flares)', 100, 25, 'available'],
            ['BIN-MCH-05', 'Display Rack 4 (Tour Merchandise & Bags)', 200, 80, 'available'],
        ];
        $seedStmt = $pdo->prepare("INSERT INTO warehouse_bins (warehouse_id, bin_number, location_name, storage_capacity, current_stock_levels, status) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($sampleBins as $sb) {
            $seedStmt->execute([$firstWhId, $sb[0], $sb[1], $sb[2], $sb[3], $sb[4]]);
        }
    }
} catch (Throwable $ignore) {}

$activeTab = $_GET['view'] ?? (isset($_GET['edit_bin']) ? 'bins' : 'zones');

// Get zones with warehouse info
try {
    $query = "SELECT wz.*, w.name as warehouse_name, w.warehouse_code 
              FROM warehouse_zones wz 
              JOIN warehouses w ON wz.warehouse_id = w.id 
              WHERE w.is_archived = 0";
    
    // Filter by warehouse if specified
    if (isset($_GET['warehouse_id']) && !empty($_GET['warehouse_id'])) {
        $query .= " AND wz.warehouse_id = " . (int)$_GET['warehouse_id'];
    }
    
    $query .= " ORDER BY w.name, wz.zone_code";
    
    $stmt = $pdo->query($query);
    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $zones = [];
}

// Get bins with warehouse info
try {
    $binQuery = "SELECT b.*, w.name AS warehouse_name, w.warehouse_code, wz.zone_name, wz.zone_code
                 FROM warehouse_bins b
                 JOIN warehouses w ON b.warehouse_id = w.id
                 LEFT JOIN warehouse_zones wz ON b.zone_id = wz.id
                 WHERE w.is_archived = 0";
    if (isset($_GET['warehouse_id']) && !empty($_GET['warehouse_id'])) {
        $binQuery .= " AND b.warehouse_id = " . (int)$_GET['warehouse_id'];
    }
    $binQuery .= " ORDER BY w.name, b.bin_number";
    $bins = $pdo->query($binQuery)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $bins = [];
}

// Get stats
$totalZones = count($zones);
$totalBins = count($bins);
$hazmatCount = 0;
$coldCount = 0;
foreach ($zones as $z) {
    if ($z['is_hazmat']) $hazmatCount++;
    if ($z['is_cold_chain']) $coldCount++;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zone Management - GlobalSCM</title>
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
            gap: 10px;
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
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
            width: 100%;
        }
        
        .filter-bar select {
            padding: 10px 14px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            background: var(--bg);
            color: var(--text);
            min-width: 180px;
            transition: var(--transition);
        }
        
        .filter-bar select:focus {
            outline: none;
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
            min-width: 700px;
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
        
        .badge-hazmat {
            background: #FEE2E2;
            color: #DC2626;
        }
        
        .badge-cold {
            background: #DBEAFE;
            color: #1E40AF;
        }
        
        .badge-standard {
            background: #D1FAE5;
            color: #065F46;
        }
        
        .badge-high-velocity {
            background: #FEF3C7;
            color: #92400E;
        }
        
        .badge-bulk {
            background: #E8EAF6;
            color: #283593;
        }
        
        .badge-returns {
            background: #FCE4EC;
            color: #C62828;
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
        
        .action-buttons {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            justify-content: center;
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
            display: <?php echo ($editZone || isset($error)) ? 'flex' : 'none'; ?>;
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
            max-width: 550px;
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
        .form-group select {
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
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(47, 128, 237, 0.1);
        }
        
        .form-group .checkbox-group {
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .form-group .checkbox-group label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 400;
            cursor: pointer;
            font-size: 13px;
        }
        
        .form-group .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
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
        
        /* ===== SIDEBAR OVERLAY ===== */
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
            
            .filter-bar select {
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
            
            .action-buttons {
                flex-direction: column;
                align-items: center;
                gap: 4px;
            }
            
            .action-buttons .btn-sm {
                width: 100%;
                justify-content: center;
                padding: 6px 12px;
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
            
            .form-group .checkbox-group {
                flex-direction: column;
                align-items: flex-start;
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
                    <h1><i class="fas fa-boxes-stacked" style="color: var(--primary);"></i> Smart Warehousing System (SWS)</h1>
                    <p>Multi-location warehouse/bin tracking, stock intake/dispatch logging, QR/barcode scanning, and movement history</p>
                </div>
                <div class="top-bar-actions">
                    <button class="btn btn-primary" onclick="openCreateBinModal()" style="background: #2563EB; border-color: #2563EB;">
                        <i class="fas fa-box-open"></i> Add Storage Bin
                    </button>
                    <button class="btn btn-outline" onclick="openCreateModal()">
                        <i class="fas fa-layer-group"></i> Add Zone
                    </button>
                    <button class="btn btn-outline" onclick="openBarcodeScannerModal()">
                        <i class="fas fa-qrcode"></i> Scan QR / Barcode
                    </button>
                    <a href="stock-movements.php" class="btn btn-outline" title="Stock intake/dispatch logging">
                        <i class="fas fa-exchange-alt"></i> Movement History
                    </a>
                    <a href="warehouses.php" class="btn btn-back">
                        <i class="fas fa-warehouse"></i> Warehouses
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
            
            <!-- SWS Core Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="label"><i class="fas fa-cubes"></i> Total Storage Bins</div>
                    <div class="value"><?php echo number_format($totalBins); ?></div>
                    <div style="font-size:12px; color:var(--secondary-text); margin-top:4px;">Multi-location bin locations</div>
                </div>
                <div class="stat-card" style="border-color: #2563EB;">
                    <div class="label"><i class="fas fa-layer-group" style="color: #2563EB;"></i> Warehouse Zones</div>
                    <div class="value" style="color: #2563EB;"><?php echo number_format($totalZones); ?></div>
                    <div style="font-size:12px; color:var(--secondary-text); margin-top:4px;">Staging, Bulk &amp; Specialty</div>
                </div>
                <div class="stat-card" style="border-color: #DC2626;">
                    <div class="label"><i class="fas fa-exclamation-triangle" style="color: #DC2626;"></i> Hazmat &amp; Cold Chain</div>
                    <div class="value" style="color: #DC2626;"><?php echo number_format($hazmatCount + $coldCount); ?></div>
                    <div style="font-size:12px; color:var(--secondary-text); margin-top:4px;">Controlled climate/hazard</div>
                </div>
                <div class="stat-card" style="border-color: var(--accent);">
                    <div class="label"><i class="fas fa-warehouse" style="color: var(--accent);"></i> Active Warehouses</div>
                    <div class="value" style="color: var(--accent);"><?php echo count($warehouses); ?></div>
                    <div style="font-size:12px; color:var(--secondary-text); margin-top:4px;">Distribution network</div>
                </div>
            </div>

            <!-- View Switcher Tabs -->
            <div style="display:flex; gap:12px; margin-bottom: 20px; border-bottom: 2px solid var(--border); padding-bottom: 1px;">
                <a href="warehouse-zones.php?view=bins<?php echo isset($_GET['warehouse_id']) ? '&warehouse_id=' . (int)$_GET['warehouse_id'] : ''; ?>" 
                   style="padding: 10px 20px; font-weight: 600; font-size: 14px; text-decoration: none; border-bottom: 3px solid <?php echo $activeTab === 'bins' ? 'var(--primary)' : 'transparent'; ?>; color: <?php echo $activeTab === 'bins' ? 'var(--primary)' : 'var(--secondary-text)'; ?>; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-cubes"></i> Bin Tracking (SWS)
                    <span class="badge" style="background: <?php echo $activeTab === 'bins' ? 'var(--primary)' : '#9CA3AF'; ?>; color:#fff; font-size:11px;"><?php echo $totalBins; ?></span>
                </a>
                <a href="warehouse-zones.php?view=zones<?php echo isset($_GET['warehouse_id']) ? '&warehouse_id=' . (int)$_GET['warehouse_id'] : ''; ?>" 
                   style="padding: 10px 20px; font-weight: 600; font-size: 14px; text-decoration: none; border-bottom: 3px solid <?php echo $activeTab === 'zones' ? 'var(--primary)' : 'transparent'; ?>; color: <?php echo $activeTab === 'zones' ? 'var(--primary)' : 'var(--secondary-text)'; ?>; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-layer-group"></i> Warehouse Zones
                    <span class="badge" style="background: <?php echo $activeTab === 'zones' ? 'var(--primary)' : '#9CA3AF'; ?>; color:#fff; font-size:11px;"><?php echo $totalZones; ?></span>
                </a>
            </div>
            
            <!-- Filter Bar -->
            <?php if (!empty($warehouses)): ?>
            <div class="filter-bar">
                <form method="GET" style="display: flex; gap: 12px; flex-wrap: wrap; width: 100%; align-items: center;">
                    <input type="hidden" name="view" value="<?php echo htmlspecialchars($activeTab); ?>">
                    <select name="warehouse_id" onchange="this.form.submit()">
                        <option value="">All Warehouses</option>
                        <?php foreach ($warehouses as $w): ?>
                        <option value="<?php echo $w['id']; ?>" <?php echo (isset($_GET['warehouse_id']) && $_GET['warehouse_id'] == $w['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($w['name']); ?> (<?php echo htmlspecialchars($w['warehouse_code']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($_GET['warehouse_id']) && !empty($_GET['warehouse_id'])): ?>
                    <a href="warehouse-zones.php?view=<?php echo htmlspecialchars($activeTab); ?>" class="btn btn-outline">
                        <i class="fas fa-times"></i> Clear Filter
                    </a>
                    <?php endif; ?>
                </form>
            </div>
            <?php endif; ?>
            
            <?php if ($activeTab === 'bins'): ?>
            <!-- ============================================
                 STORAGE BINS TABLE (SWS REQUIREMENT)
                 Key Fields: Warehouse_ID, Location_Name, Bin_Number, Current_Stock_Levels, Storage_Capacity
                 ============================================ -->
            <div class="table-container">
                <div class="table-header">
                    <h2><i class="fas fa-cubes" style="color:var(--primary); margin-right:8px;"></i> Storage Bins &amp; Multi-Location Tracking</h2>
                    <span class="role-badge"><?php echo count($bins); ?> active bins</span>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Warehouse_ID</th>
                                <th>Bin_Number</th>
                                <th>Location_Name</th>
                                <th>Current_Stock_Levels</th>
                                <th>Storage_Capacity</th>
                                <th>Utilization</th>
                                <th>Status</th>
                                <th>QR / Barcode</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($bins)): ?>
                                <?php foreach ($bins as $bin): 
                                    $cap = max(1, (int)$bin['storage_capacity']);
                                    $cur = (int)$bin['current_stock_levels'];
                                    $pct = min(100, round(($cur / $cap) * 100));
                                    $barColor = $pct >= 90 ? '#DC2626' : ($pct >= 70 ? '#F59E0B' : '#10B981');
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($bin['warehouse_code'] ?? 'WH'); ?></strong>
                                        <div style="font-size:11px; color:var(--secondary-text);"><?php echo htmlspecialchars($bin['warehouse_name']); ?></div>
                                    </td>
                                    <td>
                                        <span style="font-family:monospace; font-weight:700; color:var(--primary); background:rgba(47,128,237,0.08); padding:3px 8px; border-radius:6px;">
                                            <?php echo htmlspecialchars($bin['bin_number']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($bin['location_name'] ?? 'General Bin'); ?></strong>
                                        <?php if (!empty($bin['zone_name'])): ?>
                                            <div style="font-size:11px; color:var(--secondary-text);">Zone: <?php echo htmlspecialchars($bin['zone_name']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong style="font-size:15px; color:var(--text);"><?php echo number_format($cur); ?></strong>
                                        <span style="font-size:11px; color:var(--secondary-text);">units</span>
                                    </td>
                                    <td>
                                        <strong><?php echo number_format($cap); ?></strong>
                                        <span style="font-size:11px; color:var(--secondary-text);">units max</span>
                                    </td>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <div style="flex:1; height:8px; background:rgba(0,0,0,0.06); border-radius:4px; overflow:hidden; min-width:60px;">
                                                <div style="width:<?php echo $pct; ?>%; height:100%; background:<?php echo $barColor; ?>; border-radius:4px;"></div>
                                            </div>
                                            <span style="font-size:11px; font-weight:600; color:<?php echo $barColor; ?>;"><?php echo $pct; ?>%</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $bin['status'] === 'available' ? 'status-active' : ($bin['status'] === 'full' ? 'status-inactive' : 'status-maintenance'); ?>">
                                            <?php echo ucfirst($bin['status'] ?? 'Available'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-outline btn-sm" onclick="showBinBarcode('<?php echo htmlspecialchars($bin['bin_number'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($bin['location_name'] ?? '', ENT_QUOTES); ?>')" title="View Barcode &amp; QR Code">
                                            <i class="fas fa-qrcode"></i> QR / Barcode
                                        </button>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="warehouse-zones.php?edit_bin=1&id=<?php echo $bin['id']; ?>&view=bins" 
                                               class="btn btn-primary btn-sm" title="Edit Bin">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="stock-movements.php?warehouse_id=<?php echo $bin['warehouse_id']; ?>" 
                                               class="btn btn-outline btn-sm" title="Log Intake / Dispatch">
                                                <i class="fas fa-exchange-alt"></i>
                                            </a>
                                            <a href="warehouse-zones.php?delete_bin=1&id=<?php echo $bin['id']; ?>" 
                                               class="btn btn-danger btn-sm" 
                                               onclick="return confirm('Delete bin <?php echo htmlspecialchars($bin['bin_number']); ?>?');" title="Delete Bin">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="empty-state">
                                        <i class="fas fa-cubes"></i>
                                        <p>No storage bins configured yet.</p>
                                        <p style="margin-top:8px;">
                                            <a href="#" onclick="openCreateBinModal();" style="color:var(--primary); font-weight:500;">
                                                <i class="fas fa-plus"></i> Add your first warehouse bin
                                            </a>
                                        </p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php else: ?>
            <!-- ============================================
                 WAREHOUSE ZONES TABLE
                 ============================================ -->
            <div class="table-container">
                <div class="table-header">
                    <h2><i class="fas fa-layer-group" style="color:var(--primary); margin-right:8px;"></i> Warehouse Zones</h2>
                    <span class="role-badge"><?php echo count($zones); ?> zones</span>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Zone Code</th>
                                <th>Zone Name</th>
                                <th>Warehouse</th>
                                <th>Type</th>
                                <th>Capacity</th>
                                <th>Features</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($zones)): ?>
                                <?php foreach ($zones as $zone): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($zone['zone_code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($zone['zone_name']); ?></td>
                                    <td><?php echo htmlspecialchars($zone['warehouse_name']); ?></td>
                                    <td>
                                        <span class="status-badge <?php 
                                            echo $zone['zone_type'] === 'hazmat' ? 'badge-hazmat' : 
                                                ($zone['zone_type'] === 'cold_chain' ? 'badge-cold' : 
                                                ($zone['zone_type'] === 'high_velocity' ? 'badge-high-velocity' :
                                                ($zone['zone_type'] === 'bulk' ? 'badge-bulk' :
                                                ($zone['zone_type'] === 'returns' ? 'badge-returns' : 'badge-standard'))));
                                        ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $zone['zone_type'] ?? 'Standard')); ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format($zone['capacity'] ?? 0); ?></td>
                                    <td>
                                        <?php if ($zone['is_hazmat']): ?>
                                            <span class="status-badge badge-hazmat" style="font-size: 10px; padding: 1px 8px;">
                                                <i class="fas fa-exclamation-triangle"></i> Hazmat
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($zone['is_cold_chain']): ?>
                                            <span class="status-badge badge-cold" style="font-size: 10px; padding: 1px 8px;">
                                                <i class="fas fa-snowflake"></i> Cold Chain
                                                <?php if ($zone['temperature'] !== null): ?>
                                                    (<?php echo $zone['temperature']; ?>°C)
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!$zone['is_hazmat'] && !$zone['is_cold_chain']): ?>
                                            <span class="status-badge badge-standard" style="font-size: 10px; padding: 1px 8px;">
                                                <i class="fas fa-check"></i> Standard
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="warehouse-zones.php?edit=1&id=<?php echo $zone['id']; ?>&view=zones" 
                                               class="btn btn-primary btn-sm" title="Edit Zone">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="warehouse-zones.php?delete=1&id=<?php echo $zone['id']; ?>&view=zones" 
                                               class="btn btn-danger btn-sm" 
                                               onclick="return confirm('Delete this zone?');" title="Delete Zone">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="empty-state">
                                        <i class="fas fa-layer-group"></i>
                                        <p>No zones found.</p>
                                        <?php if (!empty($warehouses)): ?>
                                        <p style="margin-top: 8px;">
                                            <a href="#" onclick="openCreateModal();" style="color: var(--primary); font-weight: 500;">
                                                <i class="fas fa-plus"></i> Create your first zone
                                            </a>
                                        </p>
                                        <?php else: ?>
                                        <p style="margin-top: 8px; color: var(--secondary-text);">
                                            <i class="fas fa-info-circle"></i> Please create a warehouse first.
                                            <a href="warehouses.php?action=create" style="color: var(--primary);">Create Warehouse</a>
                                        </p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
    
    <!-- Create/Edit Zone Modal -->
    <div id="zoneModal" class="modal-overlay" style="display: <?php echo ($editZone || isset($error)) ? 'flex' : 'none'; ?>;">
        <div class="modal">
            <h3>
                <i class="fas fa-<?php echo $editZone ? 'edit' : 'plus'; ?>" style="color: var(--primary);"></i>
                <?php echo $editZone ? 'Edit Zone' : 'Add New Zone'; ?>
                <button type="button" class="close-modal" onclick="closeZoneModal()">&times;</button>
            </h3>
            <form method="POST" action="warehouse-zones.php">
                <input type="hidden" name="action" value="<?php echo $editZone ? 'edit' : 'create'; ?>">
                <?php if ($editZone): ?>
                <input type="hidden" name="id" value="<?php echo $editZone['id']; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Warehouse *</label>
                    <select name="warehouse_id" required>
                        <option value="">Select Warehouse</option>
                        <?php foreach ($warehouses as $w): ?>
                        <option value="<?php echo $w['id']; ?>" <?php echo ($editZone && $editZone['warehouse_id'] == $w['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($w['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Zone Code *</label>
                    <input type="text" name="zone_code" required 
                           value="<?php echo $editZone ? htmlspecialchars($editZone['zone_code']) : 'ZN-' . date('Ymd') . '-' . str_pad(mt_rand(1, 99), 2, '0', STR_PAD_LEFT); ?>"
                           placeholder="e.g., ZN-A1">
                </div>
                
                <div class="form-group">
                    <label>Zone Name *</label>
                    <input type="text" name="zone_name" required 
                           value="<?php echo $editZone ? htmlspecialchars($editZone['zone_name']) : ''; ?>"
                           placeholder="e.g., Standard Storage A1">
                </div>
                
                <div class="form-group">
                    <label>Zone Type</label>
                    <select name="zone_type">
                        <option value="standard" <?php echo ($editZone && $editZone['zone_type'] === 'standard') ? 'selected' : ''; ?>>Standard</option>
                        <option value="cold_chain" <?php echo ($editZone && $editZone['zone_type'] === 'cold_chain') ? 'selected' : ''; ?>>Cold Chain</option>
                        <option value="hazmat" <?php echo ($editZone && $editZone['zone_type'] === 'hazmat') ? 'selected' : ''; ?>>Hazmat</option>
                        <option value="high_velocity" <?php echo ($editZone && $editZone['zone_type'] === 'high_velocity') ? 'selected' : ''; ?>>High Velocity</option>
                        <option value="bulk" <?php echo ($editZone && $editZone['zone_type'] === 'bulk') ? 'selected' : ''; ?>>Bulk Storage</option>
                        <option value="returns" <?php echo ($editZone && $editZone['zone_type'] === 'returns') ? 'selected' : ''; ?>>Returns</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Capacity (units)</label>
                    <input type="number" name="capacity" 
                           value="<?php echo $editZone ? $editZone['capacity'] : ''; ?>"
                           placeholder="e.g., 1000">
                </div>
                
                <div class="form-group">
                    <label>Features</label>
                    <div class="checkbox-group">
                        <label>
                            <input type="checkbox" name="is_hazmat" value="1" <?php echo ($editZone && $editZone['is_hazmat']) ? 'checked' : ''; ?>>
                            <i class="fas fa-exclamation-triangle" style="color: #DC2626;"></i> Hazmat
                        </label>
                        <label>
                            <input type="checkbox" name="is_cold_chain" value="1" id="coldChainCheck" <?php echo ($editZone && $editZone['is_cold_chain']) ? 'checked' : ''; ?>>
                            <i class="fas fa-snowflake" style="color: #1E40AF;"></i> Cold Chain
                        </label>
                    </div>
                </div>
                
                <div class="form-group" id="temperatureGroup" style="display: <?php echo ($editZone && $editZone['is_cold_chain']) ? 'block' : 'none'; ?>;">
                    <label>Temperature (°C)</label>
                    <input type="number" name="temperature" step="0.1" 
                           value="<?php echo $editZone ? $editZone['temperature'] : ''; ?>"
                           placeholder="e.g., -18">
                </div>
                
                <div class="form-actions">
    <!-- Create/Edit Bin Modal (SWS) -->
    <div id="binModal" class="modal-overlay" style="display: <?php echo ($editBin) ? 'flex' : 'none'; ?>;">
        <div class="modal">
            <h3>
                <i class="fas fa-<?php echo $editBin ? 'edit' : 'plus'; ?>" style="color: var(--primary);"></i>
                <?php echo $editBin ? 'Edit Storage Bin' : 'Add New Storage Bin (SWS)'; ?>
                <button type="button" class="close-modal" onclick="closeBinModal()">&times;</button>
            </h3>
            <form method="POST" action="warehouse-zones.php">
                <input type="hidden" name="action" value="<?php echo $editBin ? 'edit_bin' : 'create_bin'; ?>">
                <?php if ($editBin): ?>
                <input type="hidden" name="bin_id" value="<?php echo $editBin['id']; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Warehouse *</label>
                    <select name="warehouse_id" required>
                        <option value="">Select Warehouse</option>
                        <?php foreach ($warehouses as $w): ?>
                        <option value="<?php echo $w['id']; ?>" <?php echo ($editBin && $editBin['warehouse_id'] == $w['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($w['name']); ?> (<?php echo htmlspecialchars($w['warehouse_code']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Zone (Optional)</label>
                    <select name="zone_id">
                        <option value="">No Specific Zone</option>
                        <?php foreach ($zones as $z): ?>
                        <option value="<?php echo $z['id']; ?>" <?php echo ($editBin && $editBin['zone_id'] == $z['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($z['zone_name']); ?> (<?php echo htmlspecialchars($z['zone_code']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Bin Number *</label>
                    <input type="text" name="bin_number" required 
                           value="<?php echo $editBin ? htmlspecialchars($editBin['bin_number']) : 'BIN-' . strtoupper(substr(uniqid(), -6)); ?>"
                           placeholder="e.g., BIN-A101, BIN-MKT-01">
                </div>
                
                <div class="form-group">
                    <label>Location Name / Description *</label>
                    <input type="text" name="location_name" required 
                           value="<?php echo $editBin ? htmlspecialchars($editBin['location_name']) : ''; ?>"
                           placeholder="e.g., Rack A - Shelf 2 (Tour Marketing Kits)">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div class="form-group">
                        <label>Storage Capacity (units) *</label>
                        <input type="number" name="storage_capacity" min="1" required
                               value="<?php echo $editBin ? (int)$editBin['storage_capacity'] : '150'; ?>"
                               placeholder="e.g., 150">
                    </div>
                    <div class="form-group">
                        <label>Current Stock Levels</label>
                        <input type="number" name="current_stock_levels" min="0"
                               value="<?php echo $editBin ? (int)$editBin['current_stock_levels'] : '0'; ?>"
                               placeholder="e.g., 25">
                    </div>
                </div>

                <div class="form-group">
                    <label>Bin Status</label>
                    <select name="status">
                        <option value="available" <?php echo ($editBin && $editBin['status'] === 'available') ? 'selected' : ''; ?>>Available</option>
                        <option value="full" <?php echo ($editBin && $editBin['status'] === 'full') ? 'selected' : ''; ?>>Full</option>
                        <option value="reserved" <?php echo ($editBin && $editBin['status'] === 'reserved') ? 'selected' : ''; ?>>Reserved</option>
                        <option value="maintenance" <?php echo ($editBin && $editBin['status'] === 'maintenance') ? 'selected' : ''; ?>>Under Maintenance</option>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="button" onclick="closeBinModal()" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background:#2563EB; border-color:#2563EB;">
                        <i class="fas fa-save"></i> <?php echo $editBin ? 'Update Bin' : 'Save Bin'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- QR & Barcode Preview Modal -->
    <div id="barcodeModal" class="modal-overlay" style="display:none;">
        <div class="modal" style="max-width: 420px; text-align: center;">
            <h3 style="justify-content: center; position: relative;">
                <i class="fas fa-qrcode" style="color: var(--primary);"></i> Storage Bin QR &amp; Barcode
                <button type="button" class="close-modal" onclick="closeBarcodeModal()" style="position: absolute; right: 0;">&times;</button>
            </h3>
            <div id="barcodePrintArea" style="background: #ffffff; padding: 24px; border: 1.5px dashed var(--border); border-radius: 12px; margin: 16px 0;">
                <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; color: #6B7280; margin-bottom: 6px;">Tour-Sphere SWS</div>
                <h4 id="barcodeBinNum" style="font-size: 22px; font-weight: 800; color: #111827; margin: 0 0 4px 0; font-family: monospace;">BIN-00000</h4>
                <p id="barcodeBinLoc" style="font-size: 12px; color: #4B5563; margin: 0 0 16px 0;">Rack Location</p>
                
                <!-- QR Code Image -->
                <div style="display: flex; justify-content: center; margin-bottom: 14px;">
                    <img id="barcodeQrImg" src="" alt="Bin QR Code" style="width: 140px; height: 140px; border-radius: 8px; border: 1px solid #E5E7EB;">
                </div>
                
                <div style="font-size: 10px; color: #9CA3AF; letter-spacing: 0.5px;">SMART WAREHOUSING SYSTEM • BIN IDENTIFIER</div>
            </div>
            <div class="form-actions" style="justify-content: center; gap: 10px;">
                <button type="button" onclick="printBarcodeLabel()" class="btn btn-primary" style="background: #2563EB; border-color: #2563EB;">
                    <i class="fas fa-print"></i> Print Bin Label
                </button>
                <button type="button" onclick="closeBarcodeModal()" class="btn btn-outline">Close</button>
            </div>
        </div>
    </div>

    <!-- Barcode Scanner Modal -->
    <div id="scannerModal" class="modal-overlay" style="display:none;">
        <div class="modal" style="max-width: 480px;">
            <h3>
                <i class="fas fa-barcode" style="color: var(--primary);"></i> QR / Barcode Scanner (SWS)
                <button type="button" class="close-modal" onclick="closeScannerModal()">&times;</button>
            </h3>
            <p style="font-size: 13px; color: var(--secondary-text); margin-bottom: 16px;">
                Scan a warehouse bin or SKU barcode using your scanner gun or type the code below for rapid lookup.
            </p>
            <div class="form-group">
                <label>Manual Barcode / QR Code Entry</label>
                <div style="display: flex; gap: 8px;">
                    <input type="text" id="manualBarcodeInput" placeholder="Scan or type e.g. BIN-MKT-01, SKU-00001" style="flex: 1;" onkeypress="if(event.key==='Enter'){event.preventDefault();processScanLookup();}">
                    <button type="button" class="btn btn-primary" onclick="processScanLookup()" style="background:#2563EB; border-color:#2563EB;">
                        <i class="fas fa-search"></i> Find
                    </button>
                </div>
            </div>
            <div id="scanResult" style="padding: 14px; border-radius: 8px; background: rgba(0,0,0,0.03); border: 1px solid var(--border); font-size: 13px; min-height: 50px; display: flex; align-items: center; justify-content: center; color: var(--secondary-text);">
                Awaiting scan or input...
            </div>
            <div class="form-actions" style="margin-top: 20px;">
                <button type="button" onclick="closeScannerModal()" class="btn btn-outline">Close</button>
            </div>
        </div>
    </div>
    
    <script>
        // ============================================
        // CREATE ZONE MODAL
        // ============================================
        function openCreateModal() {
            var url = new URL(window.location.href);
            url.searchParams.delete('edit');
            url.searchParams.delete('id');
            window.history.replaceState({}, '', url.toString());
            
            var modal = document.getElementById('zoneModal');
            modal.style.display = 'flex';
            
            var form = modal.querySelector('form');
            if (form) {
                form.reset();
                var idField = form.querySelector('input[name="id"]');
                if (idField) idField.remove();
                var actionField = form.querySelector('input[name="action"]');
                if (actionField) actionField.value = 'create';
                var title = modal.querySelector('h3');
                if (title) {
                    title.innerHTML = '<i class="fas fa-plus" style="color: var(--primary);"></i> Add New Zone <button type="button" class="close-modal" onclick="closeZoneModal()">&times;</button>';
                }
                var submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.innerHTML = '<i class="fas fa-save"></i> Create Zone';
                }
                document.getElementById('temperatureGroup').style.display = 'none';
                document.getElementById('coldChainCheck').checked = false;
            }
        }
        
        function closeZoneModal() {
            var modal = document.getElementById('zoneModal');
            if (modal) modal.style.display = 'none';
            var url = new URL(window.location.href);
            url.searchParams.delete('edit');
            url.searchParams.delete('id');
            window.history.replaceState({}, '', url.toString());
        }

        // ============================================
        // CREATE / EDIT BIN MODAL
        // ============================================
        function openCreateBinModal() {
            var url = new URL(window.location.href);
            url.searchParams.delete('edit_bin');
            url.searchParams.delete('id');
            window.history.replaceState({}, '', url.toString());
            
            var modal = document.getElementById('binModal');
            modal.style.display = 'flex';
            
            var form = modal.querySelector('form');
            if (form) {
                form.reset();
                var idField = form.querySelector('input[name="bin_id"]');
                if (idField) idField.remove();
                var actionField = form.querySelector('input[name="action"]');
                if (actionField) actionField.value = 'create_bin';
                var binNumInput = form.querySelector('input[name="bin_number"]');
                if (binNumInput) {
                    binNumInput.value = 'BIN-' + Math.random().toString(36).substring(2, 7).toUpperCase();
                }
            }
        }

        function closeBinModal() {
            var modal = document.getElementById('binModal');
            if (modal) modal.style.display = 'none';
            var url = new URL(window.location.href);
            url.searchParams.delete('edit_bin');
            url.searchParams.delete('id');
            window.history.replaceState({}, '', url.toString());
        }

        // ============================================
        // QR & BARCODE MODAL
        // ============================================
        function showBinBarcode(binNumber, locationName) {
            document.getElementById('barcodeBinNum').textContent = binNumber;
            document.getElementById('barcodeBinLoc').textContent = locationName || 'Warehouse Storage Bin';
            
            // Generate clean QR Code via QuickChart
            var qrUrl = 'https://quickchart.io/qr?text=' + encodeURIComponent(binNumber) + '&size=200&ecLevel=M&margin=1';
            document.getElementById('barcodeQrImg').src = qrUrl;
            
            document.getElementById('barcodeModal').style.display = 'flex';
        }

        function closeBarcodeModal() {
            document.getElementById('barcodeModal').style.display = 'none';
        }

        function printBarcodeLabel() {
            var printContents = document.getElementById('barcodePrintArea').innerHTML;
            var win = window.open('', '_blank', 'width=500,height=500');
            win.document.write('<html><head><title>Print Bin Label</title><style>body{font-family:sans-serif;text-align:center;padding:20px;}</style></head><body>' + printContents + '</body></html>');
            win.document.close();
            win.focus();
            setTimeout(function() { win.print(); win.close(); }, 300);
        }

        // ============================================
        // BARCODE SCANNER MODAL
        // ============================================
        function openBarcodeScannerModal() {
            document.getElementById('scannerModal').style.display = 'flex';
            document.getElementById('manualBarcodeInput').value = '';
            document.getElementById('scanResult').innerHTML = 'Ready. Scan with handheld barcode gun or enter code.';
            document.getElementById('manualBarcodeInput').focus();
        }

        function closeScannerModal() {
            document.getElementById('scannerModal').style.display = 'none';
        }

        function processScanLookup() {
            var code = document.getElementById('manualBarcodeInput').value.trim();
            if (!code) {
                document.getElementById('scanResult').innerHTML = '<span style="color:#DC2626;"><i class="fas fa-exclamation-triangle"></i> Please enter a barcode or bin number.</span>';
                return;
            }

            document.getElementById('scanResult').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching inventory &amp; bins...';
            
            // Check if matches a bin on this page
            var upper = code.toUpperCase();
            var foundBin = null;
            var binLinks = document.querySelectorAll('span');
            binLinks.forEach(function(el) {
                if (el.textContent.trim().toUpperCase() === upper) {
                    foundBin = el.textContent.trim();
                }
            });

            if (foundBin) {
                document.getElementById('scanResult').innerHTML = '<div style="color:#10B981; font-weight:600;"><i class="fas fa-check-circle"></i> Storage Bin Found: ' + foundBin + '</div><div style="margin-top:6px;"><a href="warehouse-zones.php?view=bins" class="btn btn-outline btn-sm">View in Bin Tracker</a></div>';
            } else {
                document.getElementById('scanResult').innerHTML = '<div style="color:#2563EB;"><i class="fas fa-qrcode"></i> Code Recognized: <strong>' + code + '</strong></div><div style="margin-top:8px; display:flex; gap:8px; justify-content:center;"><a href="inventory.php?search=' + encodeURIComponent(code) + '" class="btn btn-primary btn-sm">Lookup Product</a><a href="stock-movements.php?search=' + encodeURIComponent(code) + '" class="btn btn-outline btn-sm">Movement History</a></div>';
            }
        }
        
        // Close modals on background click or Escape
        document.addEventListener('DOMContentLoaded', function() {
            ['zoneModal', 'binModal', 'barcodeModal', 'scannerModal'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) {
                    el.addEventListener('click', function(e) {
                        if (e.target === this) {
                            this.style.display = 'none';
                        }
                    });
                }
            });
            
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    ['zoneModal', 'binModal', 'barcodeModal', 'scannerModal'].forEach(function(id) {
                        var el = document.getElementById(id);
                        if (el) el.style.display = 'none';
                    });
                }
            });
        });
        
        // Cold chain temperature toggle
        document.getElementById('coldChainCheck')?.addEventListener('change', function() {
            var tempGroup = document.getElementById('temperatureGroup');
            if (this.checked) {
                tempGroup.style.display = 'block';
            } else {
                tempGroup.style.display = 'none';
            }
        });
    </script>
</body>
</html>