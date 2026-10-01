<?php
// admin/contracts.php
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
// EXPORT FUNCTIONALITY
// ============================================
if ($action === 'export' && isset($_GET['format'])) {
    $format = $_GET['format'];
    
    try {
        $query = "SELECT c.*, s.company_name, u.full_name as created_by_name 
                  FROM procurement_contracts c 
                  LEFT JOIN suppliers s ON c.supplier_id = s.id 
                  LEFT JOIN users u ON c.created_by = u.id 
                  WHERE c.is_archived = false";
        $params = [];
        
        if (!empty($statusFilter)) {
            $query .= " AND c.status = ?";
            $params[] = $statusFilter;
        }
        if (!empty($supplierFilter)) {
            $query .= " AND s.company_name LIKE ?";
            $params[] = "%$supplierFilter%";
        }
        if (!empty($search)) {
            $query .= " AND (c.contract_number LIKE ? OR c.title LIKE ? OR s.company_name LIKE ?)";
            $searchParam = "%$search%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }
        
        $query .= " ORDER BY c.created_at DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="contracts_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Contract #', 'Title', 'Supplier', 'Start Date', 'End Date', 'Total Value', 'Status', 'Created By']);
            foreach ($data as $row) {
                fputcsv($output, [
                    $row['contract_number'],
                    $row['title'],
                    $row['company_name'],
                    $row['start_date'],
                    $row['end_date'],
                    $row['total_value'],
                    $row['status'],
                    $row['created_by_name']
                ]);
            }
            fclose($output);
            exit();
        } elseif ($format === 'pdf') {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="contracts_' . date('Y-m-d') . '.pdf"');
            
            echo '<html><head><style>
                body { font-family: Arial, sans-serif; font-size: 12px; }
                table { width: 100%; border-collapse: collapse; }
                th { background: #2F80ED; color: white; padding: 8px; text-align: left; }
                td { padding: 8px; border-bottom: 1px solid #ddd; }
                h1 { color: #1F2937; }
            </style></head><body>';
            echo '<h1>Procurement Contracts</h1>';
            echo '<p>Generated: ' . date('Y-m-d H:i:s') . '</p>';
            echo '<table>';
            echo '<tr><th>Contract #</th><th>Title</th><th>Supplier</th><th>Start Date</th><th>End Date</th><th>Total Value</th><th>Status</th></tr>';
            foreach ($data as $row) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($row['contract_number']) . '</td>';
                echo '<td>' . htmlspecialchars($row['title']) . '</td>';
                echo '<td>' . htmlspecialchars($row['company_name']) . '</td>';
                echo '<td>' . htmlspecialchars($row['start_date']) . '</td>';
                echo '<td>' . htmlspecialchars($row['end_date']) . '</td>';
                echo '<td>' . number_format($row['total_value'], 2) . '</td>';
                echo '<td>' . ucfirst($row['status']) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            echo '</body></html>';
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Export failed: " . $e->getMessage();
        header('Location: contracts.php');
        exit();
    }
}

// ============================================
// BULK ARCHIVE
// ============================================
if ($action === 'bulk_archive' && isset($_POST['ids'])) {
    $ids = array_map('intval', $_POST['ids']);
    if (!empty($ids)) {
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE procurement_contracts SET is_archived = 1 WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            logAudit($_SESSION['user_id'], 'bulk_archive_contract', 'procurement', "Bulk archived " . count($ids) . " contracts");
            $_SESSION['success'] = "Successfully archived " . count($ids) . " contracts!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Bulk archive failed: " . $e->getMessage();
        }
    }
    header('Location: contracts.php');
    exit();
}

// ============================================
// CREATE CONTRACT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $contract_number = isset($_POST['contract_number']) ? trim($_POST['contract_number']) : '';
    $supplier_id = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : 0;
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : '';
    $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : '';
    $total_value = isset($_POST['total_value']) ? (float)$_POST['total_value'] : 0;
    $document_path = isset($_POST['document_path']) ? trim($_POST['document_path']) : '';
    
    $errors = [];
    if (empty($contract_number)) $errors[] = 'Contract number is required';
    if (empty($supplier_id)) $errors[] = 'Supplier is required';
    if (empty($title)) $errors[] = 'Title is required';
    if (empty($start_date)) $errors[] = 'Start date is required';
    if (empty($end_date)) $errors[] = 'End date is required';
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO procurement_contracts (contract_number, supplier_id, title, description, start_date, end_date, total_value, document_path, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)");
            $stmt->execute([$contract_number, $supplier_id, $title, $description, $start_date, $end_date, $total_value, $document_path, $_SESSION['user_id']]);
            logAudit($_SESSION['user_id'], 'create_contract', 'procurement', "Created contract: $contract_number");
            $_SESSION['success'] = "Contract created successfully!";
            header('Location: contracts.php');
            exit();
        } catch (PDOException $e) {
            $error = "Error creating contract: " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// ============================================
// UPDATE CONTRACT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $contract_number = isset($_POST['contract_number']) ? trim($_POST['contract_number']) : '';
    $supplier_id = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : 0;
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : '';
    $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : '';
    $total_value = isset($_POST['total_value']) ? (float)$_POST['total_value'] : 0;
    $status = isset($_POST['status']) ? $_POST['status'] : 'draft';
    $document_path = isset($_POST['document_path']) ? trim($_POST['document_path']) : '';
    
    try {
        $stmt = $pdo->prepare("UPDATE procurement_contracts SET contract_number = ?, supplier_id = ?, title = ?, description = ?, start_date = ?, end_date = ?, total_value = ?, status = ?, document_path = ? WHERE id = ?");
        $stmt->execute([$contract_number, $supplier_id, $title, $description, $start_date, $end_date, $total_value, $status, $document_path, $id]);
        logAudit($_SESSION['user_id'], 'update_contract', 'procurement', "Updated contract: $contract_number");
        $_SESSION['success'] = "Contract updated successfully!";
        header('Location: contracts.php');
        exit();
    } catch (PDOException $e) {
        $error = "Error updating contract: " . $e->getMessage();
    }
}

// ============================================
// RENEW CONTRACT
// ============================================
if ($action === 'renew' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM procurement_contracts WHERE id = ?");
        $stmt->execute([$id]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($contract) {
            // Create new contract based on existing one
            $new_contract_number = $contract['contract_number'] . '-R' . date('Y');
            $new_start_date = date('Y-m-d');
            $new_end_date = date('Y-m-d', strtotime('+1 year'));
            
            $stmt = $pdo->prepare("INSERT INTO procurement_contracts (contract_number, supplier_id, title, description, start_date, end_date, total_value, document_path, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_approval', ?)");
            $stmt->execute([$new_contract_number, $contract['supplier_id'], $contract['title'] . ' (Renewed)', $contract['description'], $new_start_date, $new_end_date, $contract['total_value'], $contract['document_path'], $_SESSION['user_id']]);
            
            logAudit($_SESSION['user_id'], 'renew_contract', 'procurement', "Renewed contract $id to $new_contract_number");
            $_SESSION['success'] = "Contract renewed successfully! New contract: $new_contract_number";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error renewing contract: " . $e->getMessage();
    }
    header('Location: contracts.php');
    exit();
}

// ============================================
// TERMINATE CONTRACT
// ============================================
if ($action === 'terminate' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("UPDATE procurement_contracts SET status = 'terminated' WHERE id = ?");
        $stmt->execute([$id]);
        logAudit($_SESSION['user_id'], 'terminate_contract', 'procurement', "Terminated contract ID: $id");
        $_SESSION['success'] = "Contract terminated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error terminating contract: " . $e->getMessage();
    }
    header('Location: contracts.php');
    exit();
}

// ============================================
// ARCHIVE CONTRACT
// ============================================
if ($action === 'archive' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    archiveRecord('procurement_contracts', $id);
    logAudit($_SESSION['user_id'], 'archive_contract', 'procurement', "Archived contract ID: $id");
    $_SESSION['success'] = "Contract archived successfully!";
    header('Location: contracts.php');
    exit();
}

// ============================================
// RESTORE CONTRACT
// ============================================
if ($action === 'restore' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    restoreRecord('procurement_contracts', $id);
    logAudit($_SESSION['user_id'], 'restore_contract', 'procurement', "Restored contract ID: $id");
    $_SESSION['success'] = "Contract restored successfully!";
    header('Location: contracts.php?archived=1');
    exit();
}

// ============================================
// VIEW CONTRACT
// ============================================
$viewContract = null;
if ($action === 'view' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("SELECT c.*, s.company_name, s.contact_person, s.email, u.full_name as created_by_name 
                               FROM procurement_contracts c 
                               JOIN suppliers s ON c.supplier_id = s.id 
                               LEFT JOIN users u ON c.created_by = u.id 
                               WHERE c.id = ?");
        $stmt->execute([$_GET['id']]);
        $viewContract = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error fetching contract: " . $e->getMessage();
        header('Location: contracts.php');
        exit();
    }
}

// ============================================
// GET CONTRACTS WITH FILTERS & SORTING
// ============================================
$showArchived = isset($_GET['archived']) ? 1 : 0;
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$supplierFilter = isset($_GET['supplier_id']) ? trim($_GET['supplier_id']) : '';
$expiryFilter = isset($_GET['expiry']) ? $_GET['expiry'] : '';
$minValue = isset($_GET['min_value']) ? (float)$_GET['min_value'] : '';
$maxValue = isset($_GET['max_value']) ? (float)$_GET['max_value'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sortField = isset($_GET['sort']) ? $_GET['sort'] : 'created_at';
$sortOrder = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'ASC' : 'DESC';

$allowedSortFields = ['contract_number', 'title', 'company_name', 'start_date', 'end_date', 'total_value', 'status', 'created_at'];
if (!in_array($sortField, $allowedSortFields)) {
    $sortField = 'created_at';
}

try {
    $query = "SELECT c.*, s.company_name, u.full_name as created_by_name 
              FROM procurement_contracts c 
              LEFT JOIN suppliers s ON c.supplier_id = s.id 
              LEFT JOIN users u ON c.created_by = u.id 
              WHERE c.is_archived = ?";
    $params = [$showArchived];
    
    if (!empty($search)) {
        $query .= " AND (c.contract_number LIKE ? OR c.title LIKE ? OR s.company_name LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    if (!empty($statusFilter)) {
        $query .= " AND c.status = ?";
        $params[] = $statusFilter;
    }
    
    if (!empty($supplierFilter)) {
        $query .= " AND s.company_name LIKE ?";
        $params[] = "%$supplierFilter%";
    }
    
    if (!empty($expiryFilter)) {
        $today = date('Y-m-d');
        $expiryDate = date('Y-m-d', strtotime("+$expiryFilter days"));
        $query .= " AND c.end_date BETWEEN ? AND ?";
        $params[] = $today;
        $params[] = $expiryDate;
    }
    
    if (!empty($minValue)) {
        $query .= " AND c.total_value >= ?";
        $params[] = $minValue;
    }
    
    if (!empty($maxValue)) {
        $query .= " AND c.total_value <= ?";
        $params[] = $maxValue;
    }
    
    $query .= " ORDER BY $sortField $sortOrder";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $contracts = [];
    $error = "Error fetching contracts: " . $e->getMessage();
}

// ============================================
// GET STATS
// ============================================
try {
    $stmt = $pdo->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
        SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired,
        SUM(CASE WHEN status = 'pending_approval' THEN 1 ELSE 0 END) as pending_approval
        FROM procurement_contracts WHERE is_archived = 0");
    $contractStats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $contractStats = ['total' => 0, 'active' => 0, 'draft' => 0, 'expired' => 0, 'pending_approval' => 0];
}

// Get expiring soon count (30 days)
try {
    $today = date('Y-m-d');
    $expiringSoon = date('Y-m-d', strtotime('+30 days'));
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM procurement_contracts WHERE end_date BETWEEN ? AND ? AND status = 'active' AND is_archived = 0");
    $stmt->execute([$today, $expiringSoon]);
    $expiringCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
} catch (PDOException $e) {
    $expiringCount = 0;
}

// Pagination
$itemsPerPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if ($itemsPerPage < 1) {
    $itemsPerPage = 10;
}
$totalItems = count($contracts);
$totalPages = (int)ceil($totalItems / $itemsPerPage);
if ($totalPages < 1) {
    $totalPages = 1;
}
$pageNum = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$pageNum = (int)max(1, min($pageNum, $totalPages));
$offset = ($pageNum - 1) * $itemsPerPage;
$paginatedContracts = array_slice($contracts, $offset, $itemsPerPage);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement Contracts - GlobalSCM</title>
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
        .stat-card .sub-value { font-size: 13px; color: var(--secondary-text); margin-top: 4px; }
        
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
        .filter-bar input[type="number"],
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
        .filter-bar input:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .filter-bar .value-range {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .filter-bar .value-range input { width: 100px; }
        
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
        .alert-warning { background: #FEF3C7; color: #92400E; border-left-color: #F59E0B; }
        
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
            min-width: 950px;
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
        
        .contract-draft { background: #E5E7EB; color: #374151; }
        .contract-pending_approval { background: #FEF3C7; color: #92400E; }
        .contract-active { background: #D1FAE5; color: #065F46; }
        .contract-expired { background: #FEE2E2; color: #DC2626; }
        .contract-terminated { background: #FEE2E2; color: #DC2626; }
        
        .renewal-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .renewal-warning { background: #FEF3C7; color: #92400E; }
        .renewal-danger { background: #FEE2E2; color: #DC2626; }
        .renewal-success { background: #D1FAE5; color: #065F46; }
        
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
            display: <?php echo ($viewContract || $action === 'create' || $action === 'edit' || isset($error)) ? 'flex' : 'none'; ?>;
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
            .filter-bar select, .filter-bar input { width: 100%; }
            .filter-bar .value-range { width: 100%; }
            .filter-bar .value-range input { width: 50%; }
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
                    <h1>Procurement Contracts</h1>
                    <p>Manage supplier contracts and agreements</p>
                </div>
                <div class="top-bar-actions">
                    <a href="contracts.php?action=create" class="btn btn-primary" onclick="openCreateModal()">
                        <i class="fas fa-plus"></i> Add Contract
                    </a>
                    
                    <!-- Export Dropdown -->
                    <div class="dropdown">
                        <button class="btn btn-outline" onclick="toggleExportDropdown()">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <div class="dropdown-content" id="exportDropdown">
                            <a href="contracts.php?action=export&format=csv<?php echo '&status=' . $statusFilter . '&search=' . urlencode($search); ?>">
                                <i class="fas fa-file-csv"></i> Export CSV
                            </a>
                            <a href="contracts.php?action=export&format=pdf<?php echo '&status=' . $statusFilter . '&search=' . urlencode($search); ?>">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </a>
                        </div>
                    </div>
                    
                    <a href="contracts.php<?php echo $showArchived ? '' : '?archived=1'; ?>" class="btn btn-outline">
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
            
            <!-- Expiring Soon Alert -->
            <?php if ($expiringCount > 0): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong><?php echo $expiringCount; ?> contract(s)</strong> are expiring within the next 30 days. Please review and take action.
            </div>
            <?php endif; ?>
            
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="label"><i class="fas fa-file-signature"></i> Total Contracts</div>
                    <div class="value"><?php echo number_format($contractStats['total'] ?? 0); ?></div>
                </div>
                <div class="stat-card" style="border-color: var(--accent);">
                    <div class="label"><i class="fas fa-check-circle" style="color: var(--accent);"></i> Active</div>
                    <div class="value" style="color: var(--accent);"><?php echo number_format($contractStats['active'] ?? 0); ?></div>
                </div>
                <div class="stat-card" style="border-color: #F59E0B;">
                    <div class="label"><i class="fas fa-pen" style="color: #F59E0B;"></i> Draft</div>
                    <div class="value" style="color: #F59E0B;"><?php echo number_format($contractStats['draft'] ?? 0); ?></div>
                </div>
                <div class="stat-card" style="border-color: #DC2626;">
                    <div class="label"><i class="fas fa-clock" style="color: #DC2626;"></i> Expired</div>
                    <div class="value" style="color: #DC2626;"><?php echo number_format($contractStats['expired'] ?? 0); ?></div>
                </div>
            </div>
            
            <!-- Search & Filter Bar -->
            <div class="filter-bar">
                <form method="GET" style="display: flex; gap: 12px; flex-wrap: wrap; width: 100%; align-items: center;">
                    <input type="hidden" name="archived" value="<?php echo $showArchived; ?>">
                    
                    <input type="text" name="search" class="search-input" 
                           placeholder="Search by contract number, title, or supplier..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    
                    <input type="text" name="supplier_id" placeholder="Supplier name..."
                           value="<?php echo htmlspecialchars($supplierFilter); ?>">
                    
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="draft" <?php echo $statusFilter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="pending_approval" <?php echo $statusFilter === 'pending_approval' ? 'selected' : ''; ?>>Pending Approval</option>
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                        <option value="terminated" <?php echo $statusFilter === 'terminated' ? 'selected' : ''; ?>>Terminated</option>
                    </select>
                    
                    <select name="expiry">
                        <option value="">Expiration</option>
                        <option value="30" <?php echo $expiryFilter == '30' ? 'selected' : ''; ?>>Expiring in 30 Days</option>
                        <option value="60" <?php echo $expiryFilter == '60' ? 'selected' : ''; ?>>Expiring in 60 Days</option>
                        <option value="90" <?php echo $expiryFilter == '90' ? 'selected' : ''; ?>>Expiring in 90 Days</option>
                    </select>
                    
                    <div class="value-range">
                        <span style="font-size: 13px; color: var(--secondary-text);">Value:</span>
                        <input type="number" name="min_value" placeholder="Min" value="<?php echo $minValue; ?>" style="width: 90px;">
                        <span style="color: var(--secondary-text);">-</span>
                        <input type="number" name="max_value" placeholder="Max" value="<?php echo $maxValue; ?>" style="width: 90px;">
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <?php if (!empty($search) || !empty($statusFilter) || !empty($supplierFilter) || !empty($expiryFilter) || !empty($minValue) || !empty($maxValue)): ?>
                        <a href="contracts.php<?php echo $showArchived ? '?archived=1' : ''; ?>" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <!-- Batch Operations Bar -->
            <div class="batch-bar" id="batchBar">
                <span class="selected-info">
                    <strong id="selectedCount">0</strong> contracts selected
                </span>
                <button class="btn btn-warning btn-sm" onclick="bulkArchive()">
                    <i class="fas fa-archive"></i> Archive Selected
                </button>
                <button class="btn btn-outline btn-sm" onclick="clearSelection()">
                    <i class="fas fa-times"></i> Clear Selection
                </button>
            </div>
            
            <div class="table-container">
                <div class="table-header">
                    <h2><?php echo $showArchived ? 'Archived Contracts' : 'Procurement Contracts'; ?></h2>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <span class="role-badge"><?php echo count($contracts); ?> contracts</span>
                        <span class="role-badge">Page <?php echo $pageNum; ?> of <?php echo $totalPages; ?></span>
                    </div>
                </div>
                <div class="table-wrapper">
                    <form id="bulkForm" method="POST">
                        <input type="hidden" name="action" id="bulkAction" value="bulk_archive">
                        <table>
                            <thead>
                                <tr>
                                    <th>
                                        <input type="checkbox" id="selectAll" onclick="toggleAll(this);">
                                    </th>
                                    <th onclick="sortTable('contract_number')" class="<?php echo $sortField === 'contract_number' ? 'sorted' : ''; ?>">
                                        Contract # <span class="sort-icon"><?php echo $sortField === 'contract_number' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('title')" class="<?php echo $sortField === 'title' ? 'sorted' : ''; ?>">
                                        Title <span class="sort-icon"><?php echo $sortField === 'title' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('company_name')" class="<?php echo $sortField === 'company_name' ? 'sorted' : ''; ?>">
                                        Supplier <span class="sort-icon"><?php echo $sortField === 'company_name' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('start_date')" class="<?php echo $sortField === 'start_date' ? 'sorted' : ''; ?>">
                                        Start Date <span class="sort-icon"><?php echo $sortField === 'start_date' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('end_date')" class="<?php echo $sortField === 'end_date' ? 'sorted' : ''; ?>">
                                        End Date <span class="sort-icon"><?php echo $sortField === 'end_date' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('total_value')" class="<?php echo $sortField === 'total_value' ? 'sorted' : ''; ?>">
                                        Total Value <span class="sort-icon"><?php echo $sortField === 'total_value' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('status')" class="<?php echo $sortField === 'status' ? 'sorted' : ''; ?>">
                                        Status <span class="sort-icon"><?php echo $sortField === 'status' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($paginatedContracts)): ?>
                                    <?php foreach ($paginatedContracts as $contract): ?>
                                    <?php 
                                    // Calculate days until expiry
                                    $daysUntilExpiry = 0;
                                    $expiryBadge = '';
                                    if ($contract['status'] === 'active' && !empty($contract['end_date'])) {
                                        $today = new DateTime();
                                        $endDate = new DateTime($contract['end_date']);
                                        $daysUntilExpiry = $today->diff($endDate)->days;
                                        if ($today > $endDate) {
                                            $daysUntilExpiry = -$daysUntilExpiry;
                                        }
                                        
                                        if ($daysUntilExpiry <= 0) {
                                            $expiryBadge = '<span class="renewal-badge renewal-danger"><i class="fas fa-exclamation-circle"></i> Expired</span>';
                                        } elseif ($daysUntilExpiry <= 30) {
                                            $expiryBadge = '<span class="renewal-badge renewal-danger"><i class="fas fa-clock"></i> ' . $daysUntilExpiry . ' days</span>';
                                        } elseif ($daysUntilExpiry <= 60) {
                                            $expiryBadge = '<span class="renewal-badge renewal-warning"><i class="fas fa-clock"></i> ' . $daysUntilExpiry . ' days</span>';
                                        } else {
                                            $expiryBadge = '<span class="renewal-badge renewal-success"><i class="fas fa-check"></i> ' . $daysUntilExpiry . ' days</span>';
                                        }
                                    }
                                    ?>
                                    <tr>
                                        <td class="checkbox-cell">
                                            <input type="checkbox" name="ids[]" value="<?php echo $contract['id']; ?>" 
                                                   class="row-checkbox" onchange="updateSelection();">
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($contract['contract_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($contract['title']); ?></td>
                                        <td><?php echo htmlspecialchars($contract['company_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($contract['start_date'])); ?></td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($contract['end_date'])); ?>
                                            <?php if (!empty($expiryBadge)): ?>
                                            <br><span style="font-size: 11px;"><?php echo $expiryBadge; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>₱<?php echo number_format($contract['total_value'], 2); ?></td>
                                        <td>
                                            <span class="status-badge contract-<?php echo $contract['status']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $contract['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="contracts.php?action=view&id=<?php echo $contract['id']; ?>" 
                                                   class="btn btn-primary btn-sm" title="View Document">
                                                    <i class="fas fa-file-pdf"></i>
                                                </a>
                                                <?php if (!$showArchived): ?>
                                                <a href="contracts.php?action=edit&id=<?php echo $contract['id']; ?>" 
                                                   class="btn btn-warning btn-sm" title="Edit Terms">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <?php if ($contract['status'] === 'active' || $contract['status'] === 'expired'): ?>
                                                <a href="contracts.php?action=renew&id=<?php echo $contract['id']; ?>" 
                                                   class="btn btn-success btn-sm" 
                                                   onclick="return confirm('Renew this contract?');" title="Renew Contract">
                                                    <i class="fas fa-sync"></i>
                                                </a>
                                                <?php endif; ?>
                                                <?php if ($contract['status'] !== 'terminated'): ?>
                                                <a href="contracts.php?action=terminate&id=<?php echo $contract['id']; ?>" 
                                                   class="btn btn-danger btn-sm" 
                                                   onclick="return confirm('Terminate this contract?');" title="Terminate">
                                                    <i class="fas fa-times-circle"></i>
                                                </a>
                                                <?php endif; ?>
                                                <a href="contracts.php?action=archive&id=<?php echo $contract['id']; ?>" 
                                                   class="btn btn-secondary btn-sm" 
                                                   style="background: #6B7280; color: white;"
                                                   onclick="return confirm('Archive this contract?');" title="Archive">
                                                    <i class="fas fa-archive"></i>
                                                </a>
                                                <?php else: ?>
                                                <a href="contracts.php?action=restore&id=<?php echo $contract['id']; ?>" 
                                                   class="btn btn-success btn-sm"
                                                   onclick="return confirm('Restore this contract?');" title="Restore">
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
                                            <i class="fas fa-file-signature"></i>
                                            <p>No contracts found</p>
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
                        of <strong><?php echo $totalItems; ?></strong> contracts
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
    
    <!-- View Contract Modal -->
    <?php if ($viewContract): ?>
    <div class="modal-overlay" style="display: flex;">
        <div class="modal">
            <h3>
                <i class="fas fa-file-signature" style="color: var(--primary);"></i> 
                <?php echo htmlspecialchars($viewContract['contract_number']); ?>
                <button type="button" class="close-modal" onclick="window.location.href='contracts.php'">&times;</button>
            </h3>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 20px 0; padding: 15px; background: var(--bg); border-radius: 10px;">
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Title</div>
                    <div><strong><?php echo htmlspecialchars($viewContract['title']); ?></strong></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Supplier</div>
                    <div><strong><?php echo htmlspecialchars($viewContract['company_name']); ?></strong></div>
                    <div style="font-size: 13px; color: var(--secondary-text);">
                        <?php echo htmlspecialchars($viewContract['contact_person'] ?? ''); ?>
                    </div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Start Date</div>
                    <div><?php echo date('M d, Y', strtotime($viewContract['start_date'])); ?></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">End Date</div>
                    <div><?php echo date('M d, Y', strtotime($viewContract['end_date'])); ?></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Total Value</div>
                    <div style="font-size: 18px; font-weight: 700; color: var(--primary);">
                        ₱<?php echo number_format($viewContract['total_value'], 2); ?>
                    </div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Status</div>
                    <span class="status-badge contract-<?php echo $viewContract['status']; ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $viewContract['status'])); ?>
                    </span>
                </div>
            </div>
            
            <?php if ($viewContract['description']): ?>
            <div style="margin-bottom: 15px;">
                <div style="font-size: 12px; color: var(--secondary-text);">Description</div>
                <div style="padding: 10px; background: var(--bg); border-radius: 8px;">
                    <?php echo nl2br(htmlspecialchars($viewContract['description'])); ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($viewContract['document_path']): ?>
            <div style="margin-bottom: 15px;">
                <div style="font-size: 12px; color: var(--secondary-text);">Attached Document</div>
                <div style="padding: 10px; background: var(--bg); border-radius: 8px;">
                    <a href="<?php echo htmlspecialchars($viewContract['document_path']); ?>" target="_blank" class="btn btn-primary btn-sm">
                        <i class="fas fa-file-pdf"></i> Download PDF
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="form-actions" style="margin-top: 20px;">
                <a href="contracts.php" class="btn btn-outline">Close</a>
                <?php if ($viewContract['status'] === 'active' || $viewContract['status'] === 'expired'): ?>
                <a href="contracts.php?action=renew&id=<?php echo $viewContract['id']; ?>" 
                   class="btn btn-success" onclick="return confirm('Renew this contract?');">
                    <i class="fas fa-sync"></i> Renew
                </a>
                <?php endif; ?>
                <?php if ($viewContract['status'] !== 'terminated'): ?>
                <a href="contracts.php?action=terminate&id=<?php echo $viewContract['id']; ?>" 
                   class="btn btn-danger" onclick="return confirm('Terminate this contract?');">
                    <i class="fas fa-times-circle"></i> Terminate
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Create/Edit Modal -->
    <?php if ($action === 'create' || $action === 'edit'): ?>
    <?php 
    $editContract = null;
    $editSupplierName = '';
    if ($action === 'edit' && isset($_GET['id'])) {
        try {
            $stmt = $pdo->prepare("SELECT c.*, s.company_name AS supplier_company_name FROM procurement_contracts c LEFT JOIN suppliers s ON c.supplier_id = s.id WHERE c.id = ?");
            $stmt->execute([$_GET['id']]);
            $editContract = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($editContract && !empty($editContract['supplier_company_name'])) {
                $editSupplierName = $editContract['supplier_company_name'];
            }
        } catch (PDOException $e) {
            $error = "Error fetching contract: " . $e->getMessage();
        }
    }
    ?>
    <div class="modal-overlay" id="createModal" style="display: flex;">
        <div class="modal">
            <h3>
                <i class="fas fa-<?php echo $editContract ? 'edit' : 'file-signature'; ?>" style="color: var(--primary);"></i>
                <?php echo $editContract ? 'Edit Contract' : 'Add New Contract'; ?>
                <button type="button" class="close-modal" onclick="closeModal()">&times;</button>
            </h3>
            <form method="POST" action="contracts.php?action=<?php echo $editContract ? 'edit' : 'create'; ?>" onsubmit="return validateContractForm()">
                <?php if ($editContract): ?>
                <input type="hidden" name="id" value="<?php echo $editContract['id']; ?>">
                <?php endif; ?>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Contract Number *</label>
                        <input type="text" name="contract_number" required 
                               value="<?php echo isset($editContract['contract_number']) ? htmlspecialchars($editContract['contract_number']) : 'CTR-' . date('Ymd') . '-' . str_pad(mt_rand(1, 99), 2, '0', STR_PAD_LEFT); ?>">
                    </div>
                    <div class="form-group">
                        <label>Supplier *</label>
                        <input type="text" id="supplierSearch" list="supplierOptions" autocomplete="off"
                               placeholder="search supplier" oninput="resolveSupplier(this)" required
                               value="<?php echo htmlspecialchars($editSupplierName); ?>">
                        <input type="hidden" name="supplier_id" id="supplierIdInput"
                               value="<?php echo isset($editContract['supplier_id']) ? htmlspecialchars($editContract['supplier_id']) : ''; ?>">
                        <datalist id="supplierOptions">
                            <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo htmlspecialchars($supplier['company_name']); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Title *</label>
                    <input type="text" name="title" required 
                           value="<?php echo isset($editContract['title']) ? htmlspecialchars($editContract['title']) : ''; ?>"
                           placeholder="e.g., Annual Supply Agreement">
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="2"><?php echo isset($editContract['description']) ? htmlspecialchars($editContract['description']) : ''; ?></textarea>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Start Date *</label>
                        <input type="date" name="start_date" required 
                               value="<?php echo isset($editContract['start_date']) ? $editContract['start_date'] : date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label>End Date *</label>
                        <input type="date" name="end_date" required 
                               value="<?php echo isset($editContract['end_date']) ? $editContract['end_date'] : date('Y-m-d', strtotime('+1 year')); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Total Value (₱)</label>
                    <input type="number" name="total_value" step="0.01" 
                           value="<?php echo isset($editContract['total_value']) ? $editContract['total_value'] : ''; ?>"
                           placeholder="e.g., 1000000">
                </div>
                
                <div class="form-group">
                    <label>Document Path</label>
                    <input type="text" name="document_path" 
                           value="<?php echo isset($editContract['document_path']) ? htmlspecialchars($editContract['document_path']) : ''; ?>"
                           placeholder="e.g., /uploads/contracts/contract_001.pdf">
                </div>
                
                <?php if ($editContract): ?>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="draft" <?php echo (isset($editContract['status']) && $editContract['status'] === 'draft') ? 'selected' : ''; ?>>Draft</option>
                        <option value="pending_approval" <?php echo (isset($editContract['status']) && $editContract['status'] === 'pending_approval') ? 'selected' : ''; ?>>Pending Approval</option>
                        <option value="active" <?php echo (isset($editContract['status']) && $editContract['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="expired" <?php echo (isset($editContract['status']) && $editContract['status'] === 'expired') ? 'selected' : ''; ?>>Expired</option>
                        <option value="terminated" <?php echo (isset($editContract['status']) && $editContract['status'] === 'terminated') ? 'selected' : ''; ?>>Terminated</option>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="form-actions">
                    <button type="button" onclick="closeModal()" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?php echo $editContract ? 'Update' : 'Create'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
        // ============================================
        // SUPPLIER NAME -> ID RESOLUTION
        // ============================================
        var suppliersMap = {};
        <?php foreach ($suppliers as $supplier): ?>
        suppliersMap[<?php echo json_encode($supplier['company_name']); ?>] = <?php echo json_encode((string)$supplier['id']); ?>;
        <?php endforeach; ?>
        
        function resolveSupplier(el) {
            var hidden = document.getElementById('supplierIdInput');
            hidden.value = suppliersMap[el.value] || '';
        }
        
        function validateContractForm() {
            var supplierId = document.getElementById('supplierIdInput').value;
            if (!supplierId) {
                alert('Please select a valid supplier from the list.');
                return false;
            }
            return true;
        }
        
        // ============================================
        // MODAL FUNCTIONS
        // ============================================
        function openCreateModal() {
            var modal = document.getElementById('createModal');
            if (modal) {
                modal.style.display = 'flex';
            } else {
                window.location.href = 'contracts.php?action=create';
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
        
        function bulkArchive() {
            var checkboxes = document.querySelectorAll('.row-checkbox:checked');
            var ids = [];
            checkboxes.forEach(function(cb) {
                ids.push(cb.value);
            });
            
            if (ids.length === 0) {
                alert('Please select at least one contract.');
                return;
            }
            
            if (confirm('Archive ' + ids.length + ' selected contracts?')) {
                var form = document.getElementById('bulkForm');
                document.getElementById('bulkAction').value = 'bulk_archive';
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
                    if (this.id !== 'createModal') {
                        window.location.href = 'contracts.php';
                    }
                }
            });
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                var modal = document.getElementById('createModal');
                if (modal && modal.style.display === 'flex') {
                    closeModal();
                }
            }
        });
    </script>
</body>
</html>