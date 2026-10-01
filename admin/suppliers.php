<?php
// admin/suppliers.php
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
// EXPORT FUNCTIONALITY
// ============================================
if ($action === 'export' && isset($_GET['format'])) {
    $format = $_GET['format'];
    
    try {
        $query = "SELECT s.*, 
                  (SELECT AVG((on_time_delivery + quality_rate + response_time)/3) FROM supplier_performance WHERE supplier_id = s.id) as avg_score
                  FROM suppliers s 
                  WHERE s.is_archived = 0";
        $params = [];
        
        if (!empty($search)) {
            $query .= " AND (s.company_name LIKE ? OR s.supplier_code LIKE ? OR s.contact_person LIKE ?)";
            $searchParam = "%$search%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }
        
        $query .= " ORDER BY s.company_name";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="suppliers_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Code', 'Company', 'Contact', 'Email', 'Phone', 'Address', 'Tax ID', 'Payment Terms', 'Rating', 'Status']);
            foreach ($data as $row) {
                fputcsv($output, [
                    $row['supplier_code'],
                    $row['company_name'],
                    $row['contact_person'],
                    $row['email'],
                    $row['phone'],
                    $row['address'],
                    $row['tax_id'],
                    $row['payment_terms'],
                    $row['rating'],
                    $row['status']
                ]);
            }
            fclose($output);
            exit();
        } elseif ($format === 'pdf') {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="suppliers_' . date('Y-m-d') . '.pdf"');
            
            echo '<html><head><style>
                body { font-family: Arial, sans-serif; font-size: 12px; }
                table { width: 100%; border-collapse: collapse; }
                th { background: #2F80ED; color: white; padding: 8px; text-align: left; }
                td { padding: 8px; border-bottom: 1px solid #ddd; }
                h1 { color: #1F2937; }
            </style></head><body>';
            echo '<h1>Supplier List</h1>';
            echo '<p>Generated: ' . date('Y-m-d H:i:s') . '</p>';
            echo '<table>';
            echo '<tr><th>Code</th><th>Company</th><th>Contact</th><th>Email</th><th>Phone</th><th>Rating</th><th>Status</th></tr>';
            foreach ($data as $row) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($row['supplier_code']) . '</td>';
                echo '<td>' . htmlspecialchars($row['company_name']) . '</td>';
                echo '<td>' . htmlspecialchars($row['contact_person']) . '</td>';
                echo '<td>' . htmlspecialchars($row['email']) . '</td>';
                echo '<td>' . htmlspecialchars($row['phone']) . '</td>';
                echo '<td>' . number_format($row['rating'], 1) . '</td>';
                echo '<td>' . ucfirst($row['status']) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            echo '</body></html>';
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Export failed: " . $e->getMessage();
        header('Location: suppliers.php');
        exit();
    }
}

// ============================================
// BULK IMPORT
// ============================================
if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['import_file']['tmp_name'];
        $extension = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
        
        $imported = 0;
        $errors = [];
        
        try {
            if ($extension === 'csv') {
                $handle = fopen($file, 'r');
                $headers = fgetcsv($handle);
                
                while (($row = fgetcsv($handle)) !== false) {
                    $data = array_combine($headers, $row);
                    if (!empty($data['code']) && !empty($data['company'])) {
                        $stmt = $pdo->prepare("INSERT INTO suppliers (supplier_code, company_name, contact_person, email, phone, address, tax_id, payment_terms, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $data['code'],
                            $data['company'],
                            $data['contact'] ?? '',
                            $data['email'] ?? '',
                            $data['phone'] ?? '',
                            $data['address'] ?? '',
                            $data['tax_id'] ?? '',
                            $data['payment_terms'] ?? 'Net 30',
                            $data['status'] ?? 'active',
                            $_SESSION['user_id']
                        ]);
                        $imported++;
                    }
                }
                fclose($handle);
            }
            
            logAudit($_SESSION['user_id'], 'import_suppliers', 'supplier', "Imported $imported suppliers");
            $_SESSION['success'] = "Successfully imported $imported suppliers!";
        } catch (Exception $e) {
            $_SESSION['error'] = "Import failed: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Please select a file to import.";
    }
    header('Location: suppliers.php');
    exit();
}

// ============================================
// CREATE SUPPLIER
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $supplier_code = isset($_POST['supplier_code']) ? trim($_POST['supplier_code']) : '';
    $company_name = isset($_POST['company_name']) ? trim($_POST['company_name']) : '';
    $contact_person = isset($_POST['contact_person']) ? trim($_POST['contact_person']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    $tax_id = isset($_POST['tax_id']) ? trim($_POST['tax_id']) : '';
    $bank_details = isset($_POST['bank_details']) ? trim($_POST['bank_details']) : '';
    $payment_terms = isset($_POST['payment_terms']) ? trim($_POST['payment_terms']) : '';
    
    $errors = [];
    if (empty($supplier_code)) $errors[] = 'Supplier code is required';
    if (empty($company_name)) $errors[] = 'Company name is required';
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO suppliers (supplier_code, company_name, contact_person, email, phone, address, tax_id, bank_details, payment_terms, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$supplier_code, $company_name, $contact_person, $email, $phone, $address, $tax_id, $bank_details, $payment_terms, $_SESSION['user_id']]);
            logAudit($_SESSION['user_id'], 'create_supplier', 'supplier', "Created supplier: $company_name");
            $_SESSION['success'] = "Supplier created successfully!";
            header('Location: suppliers.php');
            exit();
        } catch (PDOException $e) {
            $error = "Error creating supplier: " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// ============================================
// UPDATE SUPPLIER
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $supplier_code = isset($_POST['supplier_code']) ? trim($_POST['supplier_code']) : '';
    $company_name = isset($_POST['company_name']) ? trim($_POST['company_name']) : '';
    $contact_person = isset($_POST['contact_person']) ? trim($_POST['contact_person']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    $tax_id = isset($_POST['tax_id']) ? trim($_POST['tax_id']) : '';
    $bank_details = isset($_POST['bank_details']) ? trim($_POST['bank_details']) : '';
    $payment_terms = isset($_POST['payment_terms']) ? trim($_POST['payment_terms']) : '';
    $status = isset($_POST['status']) ? $_POST['status'] : 'active';
    
    try {
        $stmt = $pdo->prepare("UPDATE suppliers SET supplier_code = ?, company_name = ?, contact_person = ?, email = ?, phone = ?, address = ?, tax_id = ?, bank_details = ?, payment_terms = ?, status = ? WHERE id = ?");
        $stmt->execute([$supplier_code, $company_name, $contact_person, $email, $phone, $address, $tax_id, $bank_details, $payment_terms, $status, $id]);
        logAudit($_SESSION['user_id'], 'update_supplier', 'supplier', "Updated supplier: $company_name");
        $_SESSION['success'] = "Supplier updated successfully!";
        header('Location: suppliers.php');
        exit();
    } catch (PDOException $e) {
        $error = "Error updating supplier: " . $e->getMessage();
    }
}

// ============================================
// ADD PERFORMANCE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_performance') {
    $supplier_id = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : 0;
    $period = isset($_POST['period']) ? trim($_POST['period']) : '';
    $on_time_delivery = isset($_POST['on_time_delivery']) ? (float)$_POST['on_time_delivery'] : 0;
    $quality_rate = isset($_POST['quality_rate']) ? (float)$_POST['quality_rate'] : 0;
    $response_time = isset($_POST['response_time']) ? (float)$_POST['response_time'] : 0;
    
    try {
        $stmt = $pdo->prepare("INSERT INTO supplier_performance (supplier_id, period, on_time_delivery, quality_rate, response_time) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$supplier_id, $period, $on_time_delivery, $quality_rate, $response_time]);
        
        // Update supplier rating
        $stmt = $pdo->prepare("UPDATE suppliers SET rating = (SELECT AVG((on_time_delivery + quality_rate + response_time)/3) FROM supplier_performance WHERE supplier_id = ?) WHERE id = ?");
        $stmt->execute([$supplier_id, $supplier_id]);
        
        logAudit($_SESSION['user_id'], 'add_performance', 'supplier', "Added performance record for supplier ID: $supplier_id");
        $_SESSION['success'] = "Performance record added successfully!";
        header('Location: suppliers.php');
        exit();
    } catch (PDOException $e) {
        $error = "Error adding performance: " . $e->getMessage();
    }
}

// ============================================
// ARCHIVE SUPPLIER
// ============================================
if ($action === 'archive' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    archiveRecord('suppliers', $id);
    logAudit($_SESSION['user_id'], 'archive_supplier', 'supplier', "Archived supplier ID: $id");
    $_SESSION['success'] = "Supplier archived successfully!";
    header('Location: suppliers.php');
    exit();
}

// ============================================
// BLOCK/UNBLOCK SUPPLIER
// ============================================
if ($action === 'block' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("UPDATE suppliers SET status = 'suspended' WHERE id = ?");
        $stmt->execute([$id]);
        logAudit($_SESSION['user_id'], 'block_supplier', 'supplier', "Blocked supplier ID: $id");
        $_SESSION['success'] = "Supplier blocked successfully!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error blocking supplier: " . $e->getMessage();
    }
    header('Location: suppliers.php');
    exit();
}

if ($action === 'unblock' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("UPDATE suppliers SET status = 'active' WHERE id = ?");
        $stmt->execute([$id]);
        logAudit($_SESSION['user_id'], 'unblock_supplier', 'supplier', "Unblocked supplier ID: $id");
        $_SESSION['success'] = "Supplier unblocked successfully!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error unblocking supplier: " . $e->getMessage();
    }
    header('Location: suppliers.php');
    exit();
}

// ============================================
// RESTORE SUPPLIER
// ============================================
if ($action === 'restore' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    restoreRecord('suppliers', $id);
    logAudit($_SESSION['user_id'], 'restore_supplier', 'supplier', "Restored supplier ID: $id");
    $_SESSION['success'] = "Supplier restored successfully!";
    header('Location: suppliers.php?archived=1');
    exit();
}

// ============================================
// DELETE SUPPLIER (Permanent)
// ============================================
if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ?");
        $stmt->execute([$id]);
        logAudit($_SESSION['user_id'], 'delete_supplier', 'supplier', "Permanently deleted supplier ID: $id");
        $_SESSION['success'] = "Supplier permanently deleted!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error deleting supplier: " . $e->getMessage();
    }
    header('Location: suppliers.php?archived=1');
    exit();
}

// ============================================
// GET SUPPLIERS WITH FILTERS & SORTING
// ============================================
$showArchived = isset($_GET['archived']) ? 1 : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$ratingFilter = isset($_GET['rating']) ? (float)$_GET['rating'] : '';
$sortField = isset($_GET['sort']) ? $_GET['sort'] : 'company_name';
$sortOrder = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'ASC' : 'DESC';

$allowedSortFields = ['supplier_code', 'company_name', 'contact_person', 'email', 'rating', 'status', 'created_at'];
if (!in_array($sortField, $allowedSortFields)) {
    $sortField = 'company_name';
}

try {
    $query = "SELECT s.*, 
              (SELECT AVG((on_time_delivery + quality_rate + response_time)/3) FROM supplier_performance WHERE supplier_id = s.id) as avg_score
              FROM suppliers s 
              WHERE s.is_archived = ?";
    $params = [$showArchived];
    
    if (!empty($search)) {
        $query .= " AND (s.company_name LIKE ? OR s.supplier_code LIKE ? OR s.contact_person LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    if (!empty($statusFilter)) {
        $query .= " AND s.status = ?";
        $params[] = $statusFilter;
    }
    
    if (!empty($ratingFilter)) {
        $query .= " AND s.rating >= ?";
        $params[] = $ratingFilter;
    }
    
    $query .= " ORDER BY $sortField $sortOrder";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $suppliers = [];
    $error = "Error fetching suppliers: " . $e->getMessage();
}

// ============================================
// GET SUPPLIER FOR EDIT
// ============================================
$editSupplier = null;
if ($action === 'edit' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $editSupplier = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Error fetching supplier: " . $e->getMessage();
    }
}

// ============================================
// GET STATS
// ============================================
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total, AVG(rating) as avg_rating FROM suppliers WHERE is_archived = 0");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalSuppliers = $stats['total'] ?? 0;
    $avgRating = $stats['avg_rating'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM suppliers WHERE status = 'active' AND is_archived = 0");
    $activeSuppliers = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // Active POs count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM purchase_orders WHERE status IN ('pending', 'approved') AND is_archived = 0");
    $activePOs = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // On-time delivery rate (average)
    $stmt = $pdo->query("SELECT AVG(on_time_delivery) as avg_otif FROM supplier_performance");
    $otifResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $avgOTIF = $otifResult['avg_otif'] ?? 0;
} catch (PDOException $e) {
    $totalSuppliers = 0;
    $avgRating = 0;
    $activeSuppliers = 0;
    $activePOs = 0;
    $avgOTIF = 0;
}

// Pagination
$itemsPerPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if ($itemsPerPage < 1) {
    $itemsPerPage = 10;
}
$totalItems = count($suppliers);
$totalPages = (int)ceil($totalItems / $itemsPerPage);
if ($totalPages < 1) {
    $totalPages = 1;
}
$pageNum = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$pageNum = (int)max(1, min($pageNum, $totalPages));
$offset = ($pageNum - 1) * $itemsPerPage;
$paginatedSuppliers = array_slice($suppliers, $offset, $itemsPerPage);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Management - GlobalSCM</title>
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
        .stat-card .rating-stars { color: #F59E0B; font-size: 16px; margin-top: 4px; }
        
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
            min-width: 800px;
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
        table td { padding: 12px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        table td:last-child { text-align: center; }
        table tbody tr { transition: var(--transition); }
        table tbody tr:hover { background: rgba(47, 128, 237, 0.04); }
        table tbody tr:last-child td { border-bottom: none; }
        
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
        .status-suspended { background: #FEF3C7; color: #92400E; }
        
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
        .rating-stars { color: #F59E0B; font-size: 14px; letter-spacing: 1px; }
        
        .empty-state { text-align: center; padding: 40px; color: var(--secondary-text); }
        .empty-state i { font-size: 40px; display: block; margin-bottom: 10px; opacity: 0.3; }
        
        .modal-overlay {
            display: <?php echo ($action === 'create' || $editSupplier || isset($error)) ? 'flex' : 'none'; ?>;
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
            from { opacity: 0; transform: scale(0.95) translateY(-20px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        
        .modal h3 { font-size: 20px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .modal .close-modal { margin-left: auto; background: none; border: none; font-size: 24px; color: var(--secondary-text); cursor: pointer; padding: 0 4px; transition: var(--transition); }
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
            .filter-bar select { width: 100%; }
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
            .sidebar, .top-bar-actions, .btn, .no-print, .fullscreen-toggle, .filter-bar { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 20px !important; width: 100% !important; }
            .table-container { box-shadow: none !important; border: 1px solid #ddd !important; }
            .stat-card { box-shadow: none !important; border: 1px solid #ddd !important; }
            body { background: white !important; color: black !important; }
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
                    <h1>Supplier Management</h1>
                    <p>Manage vendors, track performance, and maintain relationships</p>
                </div>
                <div class="top-bar-actions">
                    <a href="suppliers.php?action=create" class="btn btn-primary" onclick="openCreateModal()">
                        <i class="fas fa-plus"></i> Add Supplier
                    </a>
                    <a href="suppliers.php?action=export&format=csv" class="btn btn-outline">
                        <i class="fas fa-download"></i> Export
                    </a>
                    <a href="suppliers.php<?php echo $showArchived ? '' : '?archived=1'; ?>" class="btn btn-outline">
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
                    <div class="label"><i class="fas fa-truck"></i> Total Suppliers</div>
                    <div class="value"><?php echo number_format($totalSuppliers); ?></div>
                </div>
                <div class="stat-card" style="border-color: var(--accent);">
                    <div class="label"><i class="fas fa-check-circle" style="color: var(--accent);"></i> Active</div>
                    <div class="value" style="color: var(--accent);"><?php echo number_format($activeSuppliers); ?></div>
                </div>
                <div class="stat-card">
                    <div class="label"><i class="fas fa-star" style="color: #F59E0B;"></i> Avg Rating</div>
                    <div class="value">
                        <?php 
                        $avg = round($avgRating, 1);
                        echo $avg > 0 ? number_format($avg, 1) : 'N/A';
                        ?>
                    </div>
                    <?php if ($avg > 0): ?>
                    <div class="rating-stars">
                        <?php 
                        $stars = round($avg);
                        for ($i = 1; $i <= 5; $i++) {
                            echo $i <= $stars ? '★' : '☆';
                        }
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="stat-card" style="border-color: #1E40AF;">
                    <div class="label"><i class="fas fa-file-invoice" style="color: #1E40AF;"></i> Active POs</div>
                    <div class="value" style="color: #1E40AF;"><?php echo number_format($activePOs); ?></div>
                </div>
            </div>
            
            <!-- Search & Filter Bar -->
            <div class="filter-bar">
                <form method="GET" style="display: flex; gap: 12px; flex-wrap: wrap; width: 100%;">
                    <input type="hidden" name="archived" value="<?php echo $showArchived; ?>">
                    <input type="text" name="search" class="search-input" 
                           placeholder="Search by company, code, or contact..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="suspended" <?php echo $statusFilter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                    </select>
                    
                    <select name="rating">
                        <option value="">All Ratings</option>
                        <option value="4" <?php echo $ratingFilter == 4 ? 'selected' : ''; ?>>4+ Stars</option>
                        <option value="3" <?php echo $ratingFilter == 3 ? 'selected' : ''; ?>>3+ Stars</option>
                        <option value="2" <?php echo $ratingFilter == 2 ? 'selected' : ''; ?>>2+ Stars</option>
                    </select>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <?php if (!empty($search) || !empty($statusFilter) || !empty($ratingFilter)): ?>
                        <a href="suppliers.php<?php echo $showArchived ? '?archived=1' : ''; ?>" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <div class="table-container">
                <div class="table-header">
                    <h2><?php echo $showArchived ? 'Archived Suppliers' : 'Active Suppliers'; ?></h2>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <span class="role-badge"><?php echo count($suppliers); ?> suppliers</span>
                        <span class="role-badge">Page <?php echo $pageNum; ?> of <?php echo $totalPages; ?></span>
                    </div>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th onclick="sortTable('supplier_code')" class="<?php echo $sortField === 'supplier_code' ? 'sorted' : ''; ?>">
                                    Code <span class="sort-icon"><?php echo $sortField === 'supplier_code' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th onclick="sortTable('company_name')" class="<?php echo $sortField === 'company_name' ? 'sorted' : ''; ?>">
                                    Company <span class="sort-icon"><?php echo $sortField === 'company_name' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th onclick="sortTable('contact_person')" class="<?php echo $sortField === 'contact_person' ? 'sorted' : ''; ?>">
                                    Contact <span class="sort-icon"><?php echo $sortField === 'contact_person' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th onclick="sortTable('email')" class="<?php echo $sortField === 'email' ? 'sorted' : ''; ?>">
                                    Email <span class="sort-icon"><?php echo $sortField === 'email' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th onclick="sortTable('rating')" class="<?php echo $sortField === 'rating' ? 'sorted' : ''; ?>">
                                    Rating <span class="sort-icon"><?php echo $sortField === 'rating' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th onclick="sortTable('status')" class="<?php echo $sortField === 'status' ? 'sorted' : ''; ?>">
                                    Status <span class="sort-icon"><?php echo $sortField === 'status' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($paginatedSuppliers)): ?>
                                <?php foreach ($paginatedSuppliers as $supplier): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($supplier['supplier_code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($supplier['company_name']); ?></td>
                                    <td><?php echo htmlspecialchars($supplier['contact_person'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($supplier['email']); ?></td>
                                    <td>
                                        <?php if ($supplier['rating'] > 0): ?>
                                        <span class="rating-stars">
                                            <?php 
                                            $stars = round($supplier['rating']);
                                            for ($i = 1; $i <= 5; $i++) {
                                                echo $i <= $stars ? '★' : '☆';
                                            }
                                            ?>
                                        </span>
                                        <div style="font-size: 12px; color: var(--secondary-text);">
                                            <?php echo number_format($supplier['rating'], 1); ?>
                                        </div>
                                        <?php else: ?>
                                        <span style="color: var(--secondary-text);">No rating</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php 
                                            echo $supplier['status'] === 'active' ? 'status-active' : 
                                                ($supplier['status'] === 'suspended' ? 'status-suspended' : 'status-inactive'); 
                                        ?>">
                                            <?php echo ucfirst($supplier['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if (!$showArchived): ?>
                                                <a href="suppliers.php?action=edit&id=<?php echo $supplier['id']; ?>" 
                                                   class="btn btn-primary btn-sm" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <?php if ($supplier['status'] === 'suspended'): ?>
                                                <a href="suppliers.php?action=unblock&id=<?php echo $supplier['id']; ?>" 
                                                   class="btn btn-success btn-sm" 
                                                   onclick="return confirm('Unblock this supplier?');" title="Unblock">
                                                    <i class="fas fa-unlock"></i>
                                                </a>
                                                <?php else: ?>
                                                <a href="suppliers.php?action=block&id=<?php echo $supplier['id']; ?>" 
                                                   class="btn btn-warning btn-sm" 
                                                   onclick="return confirm('Block this supplier?');" title="Block">
                                                    <i class="fas fa-ban"></i>
                                                </a>
                                                <?php endif; ?>
                                                <a href="suppliers.php?action=archive&id=<?php echo $supplier['id']; ?>" 
                                                   class="btn btn-danger btn-sm" 
                                                   onclick="return confirm('Archive this supplier?');" title="Archive">
                                                    <i class="fas fa-archive"></i>
                                                </a>
                                            <?php else: ?>
                                                <a href="suppliers.php?action=restore&id=<?php echo $supplier['id']; ?>" 
                                                   class="btn btn-success btn-sm"
                                                   onclick="return confirm('Restore this supplier?');" title="Restore">
                                                    <i class="fas fa-undo"></i>
                                                </a>
                                                <a href="suppliers.php?action=delete&id=<?php echo $supplier['id']; ?>" 
                                                   class="btn btn-danger btn-sm"
                                                   onclick="return confirm('⚠️ This will permanently delete this supplier. Continue?');" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="empty-state">
                                        <i class="fas fa-truck"></i>
                                        <p>No suppliers found</p>
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
                        of <strong><?php echo $totalItems; ?></strong> suppliers
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
    
    <!-- Create/Edit Modal -->
    <?php if ($action === 'create' || $editSupplier): ?>
    <div class="modal-overlay" id="createModal" style="display: flex;">
        <div class="modal">
            <h3>
                <i class="fas fa-<?php echo $editSupplier ? 'edit' : 'truck'; ?>" style="color: var(--primary);"></i>
                <?php echo $editSupplier ? 'Edit Supplier' : 'Add New Supplier'; ?>
                <button type="button" class="close-modal" onclick="closeModal()">&times;</button>
            </h3>
            <form method="POST" action="suppliers.php?action=<?php echo $editSupplier ? 'edit' : 'create'; ?>">
                <?php if ($editSupplier): ?>
                <input type="hidden" name="id" value="<?php echo $editSupplier['id']; ?>">
                <?php endif; ?>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Supplier Code *</label>
                        <input type="text" name="supplier_code" required 
                               value="<?php echo isset($editSupplier['supplier_code']) ? htmlspecialchars($editSupplier['supplier_code']) : 'SUP-' . date('Ymd') . '-' . str_pad(mt_rand(1, 99), 2, '0', STR_PAD_LEFT); ?>">
                    </div>
                    <div class="form-group">
                        <label>Company Name *</label>
                        <input type="text" name="company_name" required 
                               value="<?php echo isset($editSupplier['company_name']) ? htmlspecialchars($editSupplier['company_name']) : ''; ?>">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Contact Person</label>
                        <input type="text" name="contact_person" 
                               value="<?php echo isset($editSupplier['contact_person']) ? htmlspecialchars($editSupplier['contact_person']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" 
                               value="<?php echo isset($editSupplier['email']) ? htmlspecialchars($editSupplier['email']) : ''; ?>">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" 
                               value="<?php echo isset($editSupplier['phone']) ? htmlspecialchars($editSupplier['phone']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Tax ID</label>
                        <input type="text" name="tax_id" 
                               value="<?php echo isset($editSupplier['tax_id']) ? htmlspecialchars($editSupplier['tax_id']) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Address</label>
                    <textarea name="address" rows="2"><?php echo isset($editSupplier['address']) ? htmlspecialchars($editSupplier['address']) : ''; ?></textarea>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Bank Details</label>
                        <input type="text" name="bank_details" 
                               value="<?php echo isset($editSupplier['bank_details']) ? htmlspecialchars($editSupplier['bank_details']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Payment Terms</label>
                        <input type="text" name="payment_terms" 
                               value="<?php echo isset($editSupplier['payment_terms']) ? htmlspecialchars($editSupplier['payment_terms']) : 'Net 30'; ?>">
                    </div>
                </div>
                
                <?php if ($editSupplier): ?>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?php echo (isset($editSupplier['status']) && $editSupplier['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo (isset($editSupplier['status']) && $editSupplier['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        <option value="suspended" <?php echo (isset($editSupplier['status']) && $editSupplier['status'] === 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="form-actions">
                    <button type="button" onclick="closeModal()" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?php echo $editSupplier ? 'Update' : 'Create'; ?>
                    </button>
                </div>
            </form>
            
            <?php if ($editSupplier): ?>
            <hr style="margin: 20px 0; border-color: var(--border);">
            <h4 style="margin-bottom: 15px;">Add Performance Record</h4>
            <form method="POST" action="suppliers.php?action=add_performance">
                <input type="hidden" name="supplier_id" value="<?php echo $editSupplier['id']; ?>">
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Period</label>
                        <input type="text" name="period" placeholder="Q1 2024" required>
                    </div>
                    <div class="form-group">
                        <label>On-Time Delivery %</label>
                        <input type="number" name="on_time_delivery" placeholder="95" required>
                    </div>
                    <div class="form-group">
                        <label>Quality Rate %</label>
                        <input type="number" name="quality_rate" placeholder="98" required>
                    </div>
                    <div class="form-group">
                        <label>Response Time %</label>
                        <input type="number" name="response_time" placeholder="90" required>
                    </div>
                    <div class="form-actions" style="grid-column: 1 / -1;">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-plus"></i> Add Performance
                        </button>
                    </div>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
        // ============================================
        // MODAL FUNCTIONS
        // ============================================
        function openCreateModal() {
            var modal = document.getElementById('createModal');
            if (modal) {
                modal.style.display = 'flex';
            } else {
                window.location.href = 'suppliers.php?action=create';
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
    </script>
</body>
</html>