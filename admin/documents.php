<?php
ob_start();
// admin/documents.php
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

$action = isset($_GET['action']) ? trim((string)$_GET['action']) : 'list';
if ($action === 'create') {
    $action = 'upload';
}
$showArchived = isset($_GET['archived']) ? 1 : 0;
$typeFilter = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
$moduleFilter = isset($_GET['module']) ? trim((string)$_GET['module']) : '';
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$dateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

$documentTypes = [
    'bol' => 'Bill of Lading',
    'packing_list' => 'Packing List',
    'invoice' => 'Invoice',
    'customs' => 'Customs Document',
    'certificate' => 'Certificate',
    'contract' => 'Contract'
];

// ============================================
// UPLOAD HANDLING
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
    $document_number = isset($_POST['document_number']) ? trim($_POST['document_number']) : '';
    $document_type = isset($_POST['document_type']) ? $_POST['document_type'] : '';
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $related_module = isset($_POST['related_module']) ? $_POST['related_module'] : null;
    $related_id = isset($_POST['related_id']) ? (int)$_POST['related_id'] : null;
    $status = isset($_POST['status']) ? $_POST['status'] : 'draft';
    
    $file_path = '';
    $uploadError = '';
    
    // Handle file upload
    if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['document_file'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg', 'xlsx', 'xls', 'doc', 'docx', 'txt', 'csv'];
        
        if (!in_array($extension, $allowedExtensions)) {
            $uploadError = 'File type not allowed. Allowed: ' . implode(', ', $allowedExtensions);
        } elseif ($file['size'] > 10 * 1024 * 1024) {
            $uploadError = 'File size exceeds 10MB limit.';
        } else {
            $uploadDir = __DIR__ . '/../uploads/documents/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $filename = uniqid() . '.' . $extension;
            $targetPath = $uploadDir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $file_path = '/uploads/documents/' . $filename;
            } else {
                $uploadError = 'Failed to upload file.';
            }
        }
    }
    
    if (empty($uploadError)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO documents (document_number, document_type, title, description, related_module, related_id, status, file_path, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$document_number, $document_type, $title, $description, $related_module, $related_id, $status, $file_path, $_SESSION['user_id']]);
            
            logAudit($_SESSION['user_id'], 'upload_document', 'document', "Uploaded document: $document_number");
            $_SESSION['success'] = "Document uploaded successfully!";
            header('Location: documents.php');
            exit();
        } catch (PDOException $e) {
            $error = "Error uploading document: " . $e->getMessage();
        }
    } else {
        $error = $uploadError;
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
            $stmt = $pdo->prepare("UPDATE documents SET is_archived = 1 WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            logAudit($_SESSION['user_id'], 'bulk_archive_document', 'document', "Bulk archived " . count($ids) . " documents");
            $_SESSION['success'] = "Successfully archived " . count($ids) . " documents!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Bulk archive failed: " . $e->getMessage();
        }
    }
    header('Location: documents.php');
    exit();
}

// ============================================
// BULK STATUS UPDATE
// ============================================
if ($action === 'bulk_status' && isset($_POST['ids']) && isset($_POST['status'])) {
    $ids = array_map('intval', $_POST['ids']);
    $status = $_POST['status'];
    if (!empty($ids) && in_array($status, ['draft', 'pending', 'approved', 'rejected'])) {
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE documents SET status = ? WHERE id IN ($placeholders)");
            $stmt->execute(array_merge([$status], $ids));
            logAudit($_SESSION['user_id'], 'bulk_status_document', 'document', "Bulk updated status to $status for " . count($ids) . " documents");
            $_SESSION['success'] = "Successfully updated status to " . ucfirst($status) . " for " . count($ids) . " documents!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Bulk status update failed: " . $e->getMessage();
        }
    }
    header('Location: documents.php');
    exit();
}

// ============================================
// EXPORT
// ============================================
if ($action === 'export') {
    $format = isset($_GET['format']) ? strtolower(trim((string)$_GET['format'])) : '';

    if (!in_array($format, ['csv', 'pdf'], true)) {
        $_SESSION['error'] = 'Invalid export format.';
        header('Location: documents.php');
        exit();
    }

    try {
        $exportArchived = isset($_GET['archived']) ? 1 : 0;
        $exportType = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
        $exportModule = isset($_GET['module']) ? trim((string)$_GET['module']) : '';
        $exportStatus = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
        $exportDateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
        $exportDateTo = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';
        $exportSearch = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

        $query = "SELECT d.*, u.full_name AS created_by_name
                  FROM documents d
                  LEFT JOIN users u ON d.created_by = u.id
                  WHERE d.is_archived = ?";
        $params = [$exportArchived];

        if ($exportType !== '') {
            $query .= " AND d.document_type = ?";
            $params[] = $exportType;
        }
        if ($exportModule !== '') {
            $query .= " AND d.related_module = ?";
            $params[] = $exportModule;
        }
        if ($exportStatus !== '') {
            $query .= " AND d.status = ?";
            $params[] = $exportStatus;
        }
        if ($exportDateFrom !== '') {
            $query .= " AND DATE(d.created_at) >= ?";
            $params[] = $exportDateFrom;
        }
        if ($exportDateTo !== '') {
            $query .= " AND DATE(d.created_at) <= ?";
            $params[] = $exportDateTo;
        }
        if ($exportSearch !== '') {
            $query .= " AND (d.document_number LIKE ? OR d.title LIKE ? OR d.description LIKE ?)";
            $searchParam = '%' . $exportSearch . '%';
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        $query .= " ORDER BY d.created_at DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Discard anything emitted by included files before sending download headers.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $filenameDate = date('Y-m-d');

        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="documents_' . $filenameDate . '.csv"');
            header('Pragma: no-cache');
            header('Expires: 0');

            $output = fopen('php://output', 'w');
            // UTF-8 BOM for Excel compatibility.
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Document #', 'Type', 'Title', 'Module', 'Status', 'Created By', 'Created']);

            foreach ($data as $row) {
                fputcsv($output, [
                    $row['document_number'] ?? '',
                    $documentTypes[$row['document_type'] ?? ''] ?? ($row['document_type'] ?? ''),
                    $row['title'] ?? '',
                    $row['related_module'] ?? 'N/A',
                    $row['status'] ?? '',
                    $row['created_by_name'] ?? 'N/A',
                    $row['created_at'] ?? ''
                ]);
            }
            fclose($output);
            exit();
        }

        // Simple, dependency-free PDF export.
        $esc = static function ($value) {
            $value = preg_replace('/[^\x20-\x7E]/', ' ', (string)$value);
            return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
        };

        $lines = [
            'DOCUMENT REPORT',
            'Generated: ' . date('Y-m-d H:i:s'),
            'Documents: ' . count($data),
            ''
        ];
        foreach ($data as $row) {
            $lines[] = 'Document #: ' . ($row['document_number'] ?? '');
            $lines[] = 'Type: ' . ($documentTypes[$row['document_type'] ?? ''] ?? ($row['document_type'] ?? ''));
            $lines[] = 'Title: ' . ($row['title'] ?? '');
            $lines[] = 'Module: ' . ($row['related_module'] ?? 'N/A') . '    Status: ' . ($row['status'] ?? '');
            $lines[] = 'Created By: ' . ($row['created_by_name'] ?? 'N/A') . '    Created: ' . ($row['created_at'] ?? '');
            $lines[] = str_repeat('-', 90);
        }

        $content = "BT\n/F1 9 Tf\n40 760 Td\n";
        $lineIndex = 0;
        foreach ($lines as $line) {
            if ($lineIndex > 0) {
                $content .= "0 -14 Td\n";
            }
            $content .= '(' . $esc(substr($line, 0, 105)) . ") Tj\n";
            $lineIndex++;
            if ($lineIndex >= 52) {
                break;
            }
        }
        $content .= "ET";

        $objs = [
            "<< /Type /Catalog /Pages 2 0 R >>",
            "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>",
            "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream",
            "<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>"
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objs as $i => $obj) {
            $number = $i + 1;
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $obj . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objs); $i++) {
            $pdf .= sprintf('%010d 00000 n \n', $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="documents_' . $filenameDate . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $pdf;
        exit();
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Export failed: ' . $e->getMessage();
        header('Location: documents.php');
        exit();
    }
}

// ============================================
// UPDATE DOCUMENT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $document_number = isset($_POST['document_number']) ? trim($_POST['document_number']) : '';
    $document_type = isset($_POST['document_type']) ? $_POST['document_type'] : '';
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $related_module = isset($_POST['related_module']) ? $_POST['related_module'] : null;
    $related_id = isset($_POST['related_id']) ? (int)$_POST['related_id'] : null;
    $status = isset($_POST['status']) ? $_POST['status'] : 'draft';
    
    try {
        $stmt = $pdo->prepare("UPDATE documents SET document_number = ?, document_type = ?, title = ?, description = ?, related_module = ?, related_id = ?, status = ? WHERE id = ?");
        $stmt->execute([$document_number, $document_type, $title, $description, $related_module, $related_id, $status, $id]);
        logAudit($_SESSION['user_id'], 'update_document', 'document', "Updated document: $document_number");
        $_SESSION['success'] = "Document updated successfully!";
        header('Location: documents.php');
        exit();
    } catch (PDOException $e) {
        $error = "Error updating document: " . $e->getMessage();
    }
}

// ============================================
// ARCHIVE DOCUMENT
// ============================================
if ($action === 'archive' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    archiveRecord('documents', $id);
    logAudit($_SESSION['user_id'], 'archive_document', 'document', "Archived document ID: $id");
    $_SESSION['success'] = "Document archived successfully!";
    header('Location: documents.php');
    exit();
}

// ============================================
// RESTORE DOCUMENT
// ============================================
if ($action === 'restore' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    restoreRecord('documents', $id);
    logAudit($_SESSION['user_id'], 'restore_document', 'document', "Restored document ID: $id");
    $_SESSION['success'] = "Document restored successfully!";
    header('Location: documents.php?archived=1');
    exit();
}

// ============================================
// VIEW DOCUMENT (Preview)
// ============================================
$viewDocument = null;
if ($action === 'view' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("SELECT d.*, u.full_name as created_by_name 
                               FROM documents d 
                               LEFT JOIN users u ON d.created_by = u.id 
                               WHERE d.id = ?");
        $stmt->execute([$_GET['id']]);
        $viewDocument = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error fetching document: " . $e->getMessage();
        header('Location: documents.php');
        exit();
    }
}

// ============================================
// GET DOCUMENTS WITH FILTERS & SORTING
// ============================================
$sortField = isset($_GET['sort']) ? trim((string)$_GET['sort']) : 'created_at';
$sortOrder = isset($_GET['order']) && strtolower((string)$_GET['order']) === 'asc' ? 'ASC' : 'DESC';

$allowedSortFields = ['document_number', 'document_type', 'title', 'related_module', 'status', 'created_at'];
if (!in_array($sortField, $allowedSortFields)) {
    $sortField = 'created_at';
}

try {
    $query = "SELECT d.*, u.full_name as created_by_name 
              FROM documents d 
              LEFT JOIN users u ON d.created_by = u.id 
              WHERE d.is_archived = ?";
    $params = [$showArchived];
    
    if (!empty($search)) {
        $query .= " AND (d.document_number LIKE ? OR d.title LIKE ? OR d.description LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    if (!empty($typeFilter)) {
        $query .= " AND d.document_type = ?";
        $params[] = $typeFilter;
    }
    
    if (!empty($moduleFilter)) {
        $query .= " AND d.related_module = ?";
        $params[] = $moduleFilter;
    }
    
    if (!empty($statusFilter)) {
        $query .= " AND d.status = ?";
        $params[] = $statusFilter;
    }
    
    if (!empty($dateFrom)) {
        $query .= " AND DATE(d.created_at) >= ?";
        $params[] = $dateFrom;
    }
    
    if (!empty($dateTo)) {
        $query .= " AND DATE(d.created_at) <= ?";
        $params[] = $dateTo;
    }
    
    $query .= " ORDER BY $sortField $sortOrder";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $documents = [];
    $error = "Error fetching documents: " . $e->getMessage();
}

// ============================================
// GET STATS
// ============================================
try {
    $stmt = $pdo->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN document_type = 'bol' THEN 1 ELSE 0 END) as bol,
        SUM(CASE WHEN document_type = 'packing_list' THEN 1 ELSE 0 END) as packing_list,
        SUM(CASE WHEN document_type = 'invoice' THEN 1 ELSE 0 END) as invoice,
        SUM(CASE WHEN document_type = 'customs' THEN 1 ELSE 0 END) as customs,
        SUM(CASE WHEN document_type = 'certificate' THEN 1 ELSE 0 END) as certificate,
        SUM(CASE WHEN document_type = 'contract' THEN 1 ELSE 0 END) as contract
        FROM documents WHERE is_archived = 0");
    $docStats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $docStats = ['total' => 0, 'bol' => 0, 'packing_list' => 0, 'invoice' => 0, 'customs' => 0, 'certificate' => 0, 'contract' => 0];
}

// Get modules for filter
$modules = ['shipments' => 'Shipments', 'purchase_orders' => 'Purchase Orders', 'suppliers' => 'Suppliers', 'products' => 'Products', 'requisitions' => 'Requisitions'];

// Pagination
$itemsPerPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$allowedPerPage = [5, 10, 25, 50, 100];
if (!in_array($itemsPerPage, $allowedPerPage, true)) {
    $itemsPerPage = 10;
}
$totalItems = count($documents);
$totalPages = max(1, (int)ceil($totalItems / $itemsPerPage));
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$currentPage = (int)max(1, min((int)$currentPage, (int)$totalPages));
$offset = ($currentPage - 1) * $itemsPerPage;
$paginatedDocuments = array_slice($documents, $offset, $itemsPerPage);

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
    <title>Document Management - GlobalSCM</title>
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
            min-width: 220px;
            box-shadow: var(--shadow-lg);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            z-index: 9999;
            padding: 8px 0;
            pointer-events: auto;
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
        
        /* ===== STATS GRID ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
            width: 100%;
        }
        
        .stat-card {
            background: var(--card);
            padding: 18px 20px;
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
            font-size: 12px;
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
            font-size: 26px;
            font-weight: 700;
            margin-top: 4px;
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
        
        /* ===== BATCH BAR ===== */
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
        
        .batch-bar.show {
            display: flex;
        }
        
        .batch-bar .selected-info {
            font-size: 13px;
            color: var(--secondary-text);
        }
        
        .batch-bar .selected-info strong {
            color: var(--text);
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
        
        table th:first-child {
            text-align: center;
            width: 40px;
        }
        
        table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        
        table td:first-child {
            text-align: center;
        }
        
        table td:last-child {
            text-align: center;
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
        
        .checkbox-cell input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
            cursor: pointer;
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
        
        .doc-draft { background: #E5E7EB; color: #374151; }
        .doc-pending { background: #FEF3C7; color: #92400E; }
        .doc-approved { background: #D1FAE5; color: #065F46; }
        .doc-rejected { background: #FEE2E2; color: #DC2626; }
        
        .doc-type-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .doc-type-badge.bol { background: #DBEAFE; color: #1E40AF; }
        .doc-type-badge.packing_list { background: #D1FAE5; color: #065F46; }
        .doc-type-badge.invoice { background: #FEF3C7; color: #92400E; }
        .doc-type-badge.customs { background: #FCE4EC; color: #C62828; }
        .doc-type-badge.certificate { background: #E8EAF6; color: #283593; }
        .doc-type-badge.contract { background: #F3E5F5; color: #6A1B9A; }
        
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
        
        .file-icon {
            font-size: 28px;
            color: var(--primary);
            opacity: 0.5;
            width: 36px;
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
            display: <?php echo ($viewDocument || $action === 'upload' || $action === 'edit' || isset($error)) ? 'flex' : 'none'; ?>;
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
        
        /* ===== DROP ZONE ===== */
        .drop-zone {
            border: 2px dashed var(--border);
            border-radius: var(--radius-sm);
            padding: 40px 20px;
            text-align: center;
            transition: var(--transition);
            cursor: pointer;
            background: var(--bg);
        }
        
        .drop-zone:hover,
        .drop-zone.dragover {
            border-color: var(--primary);
            background: rgba(47, 128, 237, 0.02);
        }
        
        .drop-zone .icon {
            font-size: 48px;
            color: var(--secondary-text);
            margin-bottom: 12px;
        }
        
        .drop-zone .text {
            font-size: 14px;
            color: var(--secondary-text);
        }
        
        .drop-zone .text strong {
            color: var(--primary);
        }
        
        .drop-zone .sub-text {
            font-size: 12px;
            color: var(--secondary-text);
            margin-top: 4px;
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
                padding: 14px 16px;
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
            .filter-bar,
            .batch-bar {
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
    
    <!-- Fullscreen Toggle Button -->
    <button class="fullscreen-toggle no-print" id="fullscreenToggle" title="Toggle Fullscreen">
        <i class="fas fa-expand"></i>
    </button>
    
    <div class="admin-layout">
        <?php include 'partials/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="top-bar">
                <div class="page-title">
                    <h1>Document Management</h1>
                    <p>Manage BOL, Packing Lists, Invoices, and more</p>
                </div>
                <div class="top-bar-actions">
                    <a href="documents.php?action=create" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Document
                    </a>
                    
                    <!-- Export Dropdown -->
                    <div class="dropdown">
                        <button type="button" class="btn btn-outline" onclick="toggleExportDropdown(event); return false;">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <div class="dropdown-content" id="exportDropdown">
                            <a class="export-link" href="documents.php?action=export&amp;format=csv&amp;type=<?php echo urlencode($typeFilter); ?>&amp;module=<?php echo urlencode($moduleFilter); ?>&amp;status=<?php echo urlencode($statusFilter); ?>&amp;date_from=<?php echo urlencode($dateFrom); ?>&amp;date_to=<?php echo urlencode($dateTo); ?>&amp;search=<?php echo urlencode($search); ?>">
                                <i class="fas fa-file-csv"></i> Export CSV
                            </a>
                            <a class="export-link" href="documents.php?action=export&amp;format=pdf&amp;type=<?php echo urlencode($typeFilter); ?>&amp;module=<?php echo urlencode($moduleFilter); ?>&amp;status=<?php echo urlencode($statusFilter); ?>&amp;date_from=<?php echo urlencode($dateFrom); ?>&amp;date_to=<?php echo urlencode($dateTo); ?>&amp;search=<?php echo urlencode($search); ?>">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </a>
                        </div>
                    </div>
                    
                    <a href="documents.php<?php echo $showArchived ? '' : '?archived=1'; ?>" class="btn btn-outline">
                        <i class="fas fa-archive"></i> <?php echo $showArchived ? 'Active' : 'Archived'; ?>
                    </a>
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
                    <div class="label"><i class="fas fa-file-alt"></i> Total Documents</div>
                    <div class="value"><?php echo number_format($docStats['total'] ?? 0); ?></div>
                </div>
                <div class="stat-card" style="border-color: #DBEAFE;">
                    <div class="label"><i class="fas fa-ship" style="color: #1E40AF;"></i> BOL</div>
                    <div class="value" style="color: #1E40AF;"><?php echo number_format($docStats['bol'] ?? 0); ?></div>
                </div>
                <div class="stat-card" style="border-color: var(--accent);">
                    <div class="label"><i class="fas fa-box" style="color: var(--accent);"></i> Packing Lists</div>
                    <div class="value" style="color: var(--accent);"><?php echo number_format($docStats['packing_list'] ?? 0); ?></div>
                </div>
                <div class="stat-card" style="border-color: #F59E0B;">
                    <div class="label"><i class="fas fa-file-invoice" style="color: #F59E0B;"></i> Invoices</div>
                    <div class="value" style="color: #F59E0B;"><?php echo number_format($docStats['invoice'] ?? 0); ?></div>
                </div>
            </div>
            
            <!-- Search & Filter Bar -->
            <div class="filter-bar">
                <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; width: 100%; align-items: center;">
                    <input type="hidden" name="archived" value="<?php echo $showArchived; ?>">
                    
                    <input type="text" name="search" class="search-input" 
                           placeholder="Search by document number, title, or description..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    
                    <select name="type">
                        <option value="">All Types</option>
                        <?php foreach ($documentTypes as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo $typeFilter === $key ? 'selected' : ''; ?>>
                            <?php echo $label; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select name="module">
                        <option value="">All Modules</option>
                        <?php foreach ($modules as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo $moduleFilter === $key ? 'selected' : ''; ?>>
                            <?php echo $label; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="draft" <?php echo $statusFilter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                    
                    <input type="date" name="date_from" placeholder="From" value="<?php echo $dateFrom; ?>">
                    <input type="date" name="date_to" placeholder="To" value="<?php echo $dateTo; ?>">
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <?php if (!empty($search) || !empty($typeFilter) || !empty($moduleFilter) || !empty($statusFilter) || !empty($dateFrom) || !empty($dateTo)): ?>
                        <a href="documents.php<?php echo $showArchived ? '?archived=1' : ''; ?>" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <!-- Batch Operations Bar -->
            <div class="batch-bar" id="batchBar">
                <span class="selected-info">
                    <strong id="selectedCount">0</strong> documents selected
                </span>
                <button class="btn btn-warning btn-sm" onclick="bulkArchive()">
                    <i class="fas fa-archive"></i> Archive Selected
                </button>
                <select id="bulkStatusSelect" class="btn-sm" style="padding: 6px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--bg); color: var(--text);">
                    <option value="draft">Set Draft</option>
                    <option value="pending">Set Pending</option>
                    <option value="approved">Set Approved</option>
                    <option value="rejected">Set Rejected</option>
                </select>
                <button class="btn btn-primary btn-sm" onclick="bulkStatusUpdate()">
                    <i class="fas fa-sync"></i> Update Status
                </button>
                <button class="btn btn-outline btn-sm" onclick="clearSelection()">
                    <i class="fas fa-times"></i> Clear Selection
                </button>
            </div>
            
            <div class="table-container">
                <div class="table-header">
                    <h2><?php echo $showArchived ? 'Archived Documents' : 'Documents'; ?></h2>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <span class="role-badge"><?php echo count($documents); ?> documents</span>
                        <span class="role-badge">Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>
                    </div>
                </div>
                <div class="table-wrapper">
                    <form id="bulkForm" method="POST">
                        <input type="hidden" name="action" id="bulkAction" value="bulk_archive">
                        <input type="hidden" name="status" id="bulkStatus" value="">
                        <table>
                            <thead>
                                <tr>
                                    <th>
                                        <input type="checkbox" id="selectAll" onclick="toggleAll(this);">
                                    </th>
                                    <th onclick="sortTable('document_number')" class="<?php echo $sortField === 'document_number' ? 'sorted' : ''; ?>">
                                        Doc # <span class="sort-icon"><?php echo $sortField === 'document_number' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('document_type')" class="<?php echo $sortField === 'document_type' ? 'sorted' : ''; ?>">
                                        Type <span class="sort-icon"><?php echo $sortField === 'document_type' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('title')" class="<?php echo $sortField === 'title' ? 'sorted' : ''; ?>">
                                        Title <span class="sort-icon"><?php echo $sortField === 'title' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('related_module')" class="<?php echo $sortField === 'related_module' ? 'sorted' : ''; ?>">
                                        Module <span class="sort-icon"><?php echo $sortField === 'related_module' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('status')" class="<?php echo $sortField === 'status' ? 'sorted' : ''; ?>">
                                        Status <span class="sort-icon"><?php echo $sortField === 'status' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('created_at')" class="<?php echo $sortField === 'created_at' ? 'sorted' : ''; ?>">
                                        Created <span class="sort-icon"><?php echo $sortField === 'created_at' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($paginatedDocuments)): ?>
                                    <?php foreach ($paginatedDocuments as $doc): ?>
                                    <tr>
                                        <td class="checkbox-cell">
                                            <input type="checkbox" name="ids[]" value="<?php echo $doc['id']; ?>" 
                                                   class="row-checkbox" onchange="updateSelection();">
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($doc['document_number']); ?></strong></td>
                                        <td>
                                            <span class="doc-type-badge <?php echo $doc['document_type']; ?>">
                                                <?php echo $documentTypes[$doc['document_type']] ?? $doc['document_type']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($doc['title']); ?></div>
                                            <?php if ($doc['description']): ?>
                                            <div style="font-size: 12px; color: var(--secondary-text);">
                                                <?php echo htmlspecialchars(substr($doc['description'], 0, 50)) . (strlen($doc['description']) > 50 ? '...' : ''); ?>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($doc['related_module']): ?>
                                            <span class="role-badge">
                                                <?php echo ucfirst(str_replace('_', ' ', $doc['related_module'])); ?>
                                                #<?php echo $doc['related_id']; ?>
                                            </span>
                                            <?php else: ?>
                                            <span style="color: var(--secondary-text);">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge doc-<?php echo $doc['status']; ?>">
                                                <?php echo ucfirst($doc['status']); ?>
                                            </span>
                                        </td>
                                        <td style="font-size: 13px; color: var(--secondary-text);">
                                            <?php echo date('M d, Y', strtotime($doc['created_at'])); ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($doc['file_path']): ?>
                                                <a href="documents.php?action=view&id=<?php echo $doc['id']; ?>" 
                                                   class="btn btn-primary btn-sm" title="Preview">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" 
                                                   class="btn btn-success btn-sm" title="Download">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                                <?php endif; ?>
                                                <?php if (!$showArchived): ?>
                                                <a href="documents.php?action=edit&id=<?php echo $doc['id']; ?>" 
                                                   class="btn btn-warning btn-sm" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="documents.php?action=archive&id=<?php echo $doc['id']; ?>" 
                                                   class="btn btn-danger btn-sm" 
                                                   onclick="return confirm('Archive this document?');" title="Archive">
                                                    <i class="fas fa-archive"></i>
                                                </a>
                                                <?php else: ?>
                                                <a href="documents.php?action=restore&id=<?php echo $doc['id']; ?>" 
                                                   class="btn btn-success btn-sm"
                                                   onclick="return confirm('Restore this document?');" title="Restore">
                                                    <i class="fas fa-undo"></i>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="empty-state">
                                            <i class="fas fa-file-alt"></i>
                                            <p>No documents found</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </form>
                </div>
                
                <!-- Pagination -->
                <div class="pagination-bar">
                    <div class="info">
                        Showing <strong><?php echo $totalItems > 0 ? $offset + 1 : 0; ?></strong> 
                        to <strong><?php echo min($offset + $itemsPerPage, $totalItems); ?></strong> 
                        of <strong><?php echo $totalItems; ?></strong> documents
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
                        
                        <button class="page-btn" onclick="goToPage(<?php echo ((int)$currentPage - 1); ?>)" 
                                <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>>
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        
                        <?php for ($i = max(1, (int)$currentPage - 2); $i <= min((int)$totalPages, (int)$currentPage + 2); $i++): ?>
                            <button class="page-btn <?php echo $i == $currentPage ? 'active' : ''; ?>" 
                                    onclick="goToPage(<?php echo $i; ?>);">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <button class="page-btn" onclick="goToPage(<?php echo ((int)$currentPage + 1); ?>)" 
                                <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>>
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Upload/Edit Document Modal -->
    <?php if ($action === 'upload' || $action === 'edit'): ?>
    <?php 
    $editDocument = null;
    if ($action === 'edit' && isset($_GET['id'])) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            $editDocument = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $error = "Error fetching document: " . $e->getMessage();
        }
    }
    ?>
    <div class="modal-overlay" style="display: flex;">
        <div class="modal" style="max-width: 600px;">
            <h3>
                <i class="fas fa-<?php echo $editDocument ? 'edit' : 'upload'; ?>" style="color: var(--primary);"></i>
                <?php echo $editDocument ? 'Edit Document' : 'Upload New Document'; ?>
                <button type="button" class="close-modal" onclick="window.location.href='documents.php'">&times;</button>
            </h3>
            <form method="POST" action="documents.php?action=<?php echo $editDocument ? 'edit' : 'upload'; ?>" enctype="multipart/form-data">
                <?php if ($editDocument): ?>
                <input type="hidden" name="id" value="<?php echo $editDocument['id']; ?>">
                <?php endif; ?>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Document Number *</label>
                        <input type="text" name="document_number" required 
                               value="<?php echo isset($editDocument['document_number']) ? htmlspecialchars($editDocument['document_number']) : 'DOC-' . date('Ymd') . '-' . str_pad(mt_rand(1, 99), 2, '0', STR_PAD_LEFT); ?>">
                    </div>
                    <div class="form-group">
                        <label>Document Type *</label>
                        <select name="document_type" required>
                            <option value="">Select Type</option>
                            <?php foreach ($documentTypes as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo (isset($editDocument['document_type']) && $editDocument['document_type'] == $key) ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Title *</label>
                    <input type="text" name="title" required 
                           value="<?php echo isset($editDocument['title']) ? htmlspecialchars($editDocument['title']) : ''; ?>"
                           placeholder="e.g., BOL for Shipment #123">
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="2"><?php echo isset($editDocument['description']) ? htmlspecialchars($editDocument['description']) : ''; ?></textarea>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Related Module</label>
                        <select name="related_module">
                            <option value="">None</option>
                            <?php foreach ($modules as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo (isset($editDocument['related_module']) && $editDocument['related_module'] == $key) ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Related ID</label>
                        <input type="number" name="related_id" 
                               value="<?php echo isset($editDocument['related_id']) ? $editDocument['related_id'] : ''; ?>"
                               placeholder="e.g., 123">
                    </div>
                </div>
                
                <?php if ($editDocument): ?>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="draft" <?php echo (isset($editDocument['status']) && $editDocument['status'] === 'draft') ? 'selected' : ''; ?>>Draft</option>
                        <option value="pending" <?php echo (isset($editDocument['status']) && $editDocument['status'] === 'pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo (isset($editDocument['status']) && $editDocument['status'] === 'approved') ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo (isset($editDocument['status']) && $editDocument['status'] === 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <?php endif; ?>
                
                <?php if (!$editDocument): ?>
                <!-- Drop Zone for File Upload -->
                <div class="form-group">
                    <label>Upload File</label>
                    <div class="drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click();">
                        <div class="icon"><i class="fas fa-cloud-upload-alt"></i></div>
                        <div class="text">
                            <strong>Click to upload</strong> or drag and drop
                        </div>
                        <div class="sub-text">PDF, PNG, JPG, XLSX, DOC (Max 10MB)</div>
                    </div>
                    <input type="file" name="document_file" id="fileInput" style="display:none;" accept=".pdf,.png,.jpg,.jpeg,.xlsx,.xls,.doc,.docx,.txt,.csv" onchange="handleFileSelect(event)">
                    <div id="fileInfo" style="display:none; margin-top: 8px; padding: 8px 12px; background: var(--bg); border-radius: var(--radius-sm); font-size: 13px;">
                        <i class="fas fa-file"></i> <span id="fileName"></span> (<span id="fileSize"></span>)
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="form-actions">
                    <a href="documents.php" class="btn btn-outline">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?php echo $editDocument ? 'Update' : 'Upload'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- View Document Modal (Preview) -->
    <?php if ($viewDocument): ?>
    <div class="modal-overlay" style="display: flex;">
        <div class="modal" style="max-width: 800px;">
            <h3>
                <i class="fas fa-file-alt" style="color: var(--primary);"></i> 
                Document: <?php echo htmlspecialchars($viewDocument['document_number']); ?>
                <button type="button" class="close-modal" onclick="window.location.href='documents.php'">&times;</button>
            </h3>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 20px 0; padding: 15px; background: var(--bg); border-radius: 10px;">
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Title</div>
                    <div><strong><?php echo htmlspecialchars($viewDocument['title']); ?></strong></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Type</div>
                    <span class="doc-type-badge <?php echo $viewDocument['document_type']; ?>">
                        <?php echo $documentTypes[$viewDocument['document_type']] ?? $viewDocument['document_type']; ?>
                    </span>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Status</div>
                    <span class="status-badge doc-<?php echo $viewDocument['status']; ?>">
                        <?php echo ucfirst($viewDocument['status']); ?>
                    </span>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Created</div>
                    <div><?php echo date('M d, Y h:i A', strtotime($viewDocument['created_at'])); ?></div>
                </div>
                <?php if ($viewDocument['related_module']): ?>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Related Module</div>
                    <div><?php echo ucfirst(str_replace('_', ' ', $viewDocument['related_module'])); ?> #<?php echo $viewDocument['related_id']; ?></div>
                </div>
                <?php endif; ?>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Created By</div>
                    <div><?php echo htmlspecialchars($viewDocument['created_by_name'] ?? 'N/A'); ?></div>
                </div>
                <?php if ($viewDocument['description']): ?>
                <div style="grid-column: 1 / -1;">
                    <div style="font-size: 12px; color: var(--secondary-text);">Description</div>
                    <div><?php echo nl2br(htmlspecialchars($viewDocument['description'])); ?></div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($viewDocument['file_path']): ?>
            <div style="margin-bottom: 15px; padding: 15px; background: var(--bg); border-radius: var(--radius-sm); text-align: center;">
                <div style="font-size: 40px; color: var(--secondary-text); margin-bottom: 10px;">
                    <i class="fas fa-file-pdf"></i>
                </div>
                <div style="font-size: 13px; color: var(--secondary-text);">
                    <?php echo basename($viewDocument['file_path']); ?>
                </div>
                <div style="margin-top: 10px;">
                    <a href="<?php echo htmlspecialchars($viewDocument['file_path']); ?>" target="_blank" class="btn btn-primary">
                        <i class="fas fa-eye"></i> View Full Document
                    </a>
                    <a href="<?php echo htmlspecialchars($viewDocument['file_path']); ?>" download class="btn btn-success">
                        <i class="fas fa-download"></i> Download
                    </a>
                </div>
            </div>
            <?php else: ?>
            <div class="empty-state" style="padding: 20px;">
                <i class="fas fa-file" style="font-size: 30px;"></i>
                <p>No file attached to this document</p>
            </div>
            <?php endif; ?>
            
            <div class="form-actions" style="margin-top: 20px;">
                <a href="documents.php" class="btn btn-outline">Close</a>
                <?php if (!$showArchived): ?>
                <a href="documents.php?action=edit&id=<?php echo $viewDocument['id']; ?>" class="btn btn-warning">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
        // ============================================
        // DROPDOWN TOGGLES
        // ============================================
        function toggleExportDropdown(event) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }
            var dropdown = document.getElementById('exportDropdown');
            if (!dropdown) return;
            document.querySelectorAll('.dropdown-content').forEach(function(el) {
                if (el !== dropdown) el.classList.remove('show');
            });
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
        // DROP ZONE
        // ============================================
        var dropZone = document.getElementById('dropZone');
        var fileInput = document.getElementById('fileInput');
        
        if (dropZone) {
            dropZone.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.classList.add('dragover');
            });
            
            dropZone.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
            });
            
            dropZone.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
                
                if (e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    handleFileSelect(e);
                }
            });
        }
        
        function handleFileSelect(event) {
            var input = event.target || document.getElementById('fileInput');
            var file = input.files[0];
            
            if (file) {
                var fileInfo = document.getElementById('fileInfo');
                var fileName = document.getElementById('fileName');
                var fileSize = document.getElementById('fileSize');
                
                fileName.textContent = file.name;
                fileSize.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
                fileInfo.style.display = 'block';
            }
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
        
        function bulkArchive() {
            var checkboxes = document.querySelectorAll('.row-checkbox:checked');
            var ids = [];
            checkboxes.forEach(function(cb) {
                ids.push(cb.value);
            });
            
            if (ids.length === 0) {
                alert('Please select at least one document.');
                return;
            }
            
            if (confirm('Archive ' + ids.length + ' selected documents?')) {
                var form = document.getElementById('bulkForm');
                document.getElementById('bulkAction').value = 'bulk_archive';
                // Remove any existing hidden inputs
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
        
        function bulkStatusUpdate() {
            var checkboxes = document.querySelectorAll('.row-checkbox:checked');
            var ids = [];
            checkboxes.forEach(function(cb) {
                ids.push(cb.value);
            });
            
            if (ids.length === 0) {
                alert('Please select at least one document.');
                return;
            }
            
            var status = document.getElementById('bulkStatusSelect').value;
            if (confirm('Update status to ' + status + ' for ' + ids.length + ' selected documents?')) {
                var form = document.getElementById('bulkForm');
                document.getElementById('bulkAction').value = 'bulk_status';
                document.getElementById('bulkStatus').value = status;
                // Remove any existing hidden inputs
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
            var totalPages = <?php echo $totalPages; ?>;
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
                    window.location.href = 'documents.php';
                }
            });
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay').forEach(function(modal) {
                    if (modal.style.display === 'flex') {
                        modal.style.display = 'none';
                        window.location.href = 'documents.php';
                    }
                });
            }
        });
    </script>
</body>
</html> 