<?php
// admin/shipments.php
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

$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$viewMode = isset($_GET['view']) ? $_GET['view'] : 'list';
$showArchived = isset($_GET['archived']) ? 1 : 0;
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$modeFilter = isset($_GET['mode']) ? trim((string)$_GET['mode']) : '';
$carrierFilter = isset($_GET['carrier']) ? trim((string)$_GET['carrier']) : '';
$originFilter = isset($_GET['origin']) ? trim((string)$_GET['origin']) : '';
$destinationFilter = isset($_GET['destination']) ? trim((string)$_GET['destination']) : '';
$dateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$sortField = isset($_GET['sort']) ? (string)$_GET['sort'] : 'created_at';
$sortOrder = (isset($_GET['order']) && strtolower((string)$_GET['order']) === 'asc') ? 'ASC' : 'DESC';


// ============================================
// GET POs FOR DROPDOWN
// ============================================
try {
    $stmt = $pdo->query("SELECT id, po_number FROM purchase_orders WHERE status NOT IN ('cancelled') AND is_archived = 0 ORDER BY po_number DESC");
    $pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $pos = [];
}

// ============================================
// GET CARRIERS FOR FILTER
// ============================================
try {
    $stmt = $pdo->query("SELECT DISTINCT carrier FROM shipments WHERE carrier IS NOT NULL AND carrier != '' ORDER BY carrier");
    $carriers = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $carriers = [];
}

// ============================================
// GET LOCATIONS FOR FILTER
// ============================================
try {
    $stmt = $pdo->query("SELECT DISTINCT origin FROM shipments WHERE origin IS NOT NULL AND origin != '' UNION SELECT DISTINCT destination FROM shipments WHERE destination IS NOT NULL AND destination != '' ORDER BY 1");
    $locations = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $locations = [];
}

// ============================================
// EXPORT FUNCTIONALITY
// ============================================
if ($action === 'export' && isset($_GET['format'])) {
    $format = strtolower((string)$_GET['format']);
    try {
        $query = "SELECT s.*, p.po_number, u.full_name as created_by_name
                  FROM shipments s
                  LEFT JOIN purchase_orders p ON s.po_id = p.id
                  LEFT JOIN users u ON s.created_by = u.id
                  WHERE s.is_archived = ?";
        $params = [$showArchived];
        foreach ([
            ['s.status', $statusFilter],
            ['s.mode', $modeFilter],
            ['s.carrier', $carrierFilter],
            ['s.origin', $originFilter],
            ['s.destination', $destinationFilter]
        ] as [$column, $value]) {
            if ($value !== '') { $query .= " AND {$column} = ?"; $params[] = $value; }
        }
        if ($dateFrom !== '') { $query .= " AND DATE(s.created_at) >= ?"; $params[] = $dateFrom; }
        if ($dateTo !== '') { $query .= " AND DATE(s.created_at) <= ?"; $params[] = $dateTo; }
        if ($search !== '') {
            $query .= " AND (s.shipment_id LIKE ? OR s.carrier LIKE ? OR s.tracking_number LIKE ?)";
            $q = "%{$search}%"; array_push($params, $q, $q, $q);
        }
        $query .= " ORDER BY s.created_at DESC";
        $stmt=$pdo->prepare($query); $stmt->execute($params); $data=$stmt->fetchAll(PDO::FETCH_ASSOC);
        require_once __DIR__ . '/../includes/report_export.php';
        exportTrackedReport($pdo, (int)$_SESSION['user_id'], 'shipments', 'Shipment and Logistics Report', $format, $data);
        while (ob_get_level()) ob_end_clean();

        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="shipments_'.date('Y-m-d').'.csv"');
            $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
            fputcsv($out,['Shipment ID','PO #','Carrier','Tracking','Mode','Origin','Destination','Status','Freight Cost','Created']);
            foreach($data as $r) fputcsv($out,[$r['shipment_id']??'',$r['po_number']??'',$r['carrier']??'',$r['tracking_number']??'',$r['mode']??'',$r['origin']??'',$r['destination']??'',$r['status']??'',$r['freight_cost']??'',$r['created_at']??'']);
            fclose($out); exit();
        }

        if ($format === 'pdf') {
            $esc=static function($v){$v=preg_replace('/[^\x20-\x7E]/',' ',(string)$v);return str_replace(['\\','(',')'],['\\\\','\(','\)'],$v);};
            $lines=['SHIPMENTS REPORT','Generated: '.date('Y-m-d H:i:s'),'Shipment ID | PO # | Carrier | Tracking | Mode | Status | Freight Cost'];
            foreach($data as $r) $lines[]=substr((string)($r['shipment_id']??''),0,18).' | '.substr((string)($r['po_number']??''),0,12).' | '.substr((string)($r['carrier']??''),0,15).' | '.substr((string)($r['tracking_number']??''),0,18).' | '.substr((string)($r['mode']??''),0,6).' | '.substr((string)($r['status']??''),0,12).' | '.number_format((float)($r['freight_cost']??0),2);
            $content="BT\n/F1 9 Tf\n40 760 Td\n";
            foreach($lines as $i=>$line){if($i)$content.="0 -14 Td\n";$content.="(".$esc($line).") Tj\n";}$content.="ET";
            $objs=[
                "<< /Type /Catalog /Pages 2 0 R >>",
                "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>",
                "<< /Length ".strlen($content)." >>\nstream\n".$content."\nendstream",
                "<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>"
            ];
            $pdf="%PDF-1.4\n";$off=[0];
            foreach($objs as $i=>$o){$n=$i+1;$off[$n]=strlen($pdf);$pdf.=$n." 0 obj\n".$o."\nendobj\n";}
            $xref=strlen($pdf);$pdf.="xref\n0 ".(count($objs)+1)."\n0000000000 65535 f \n";
            for($i=1;$i<=count($objs);$i++)$pdf.=sprintf("%010d 00000 n \n",$off[$i]);
            $pdf.="trailer\n<< /Size ".(count($objs)+1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
            header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="shipments_'.date('Y-m-d').'.pdf"'); header('Content-Length: '.strlen($pdf)); echo $pdf; exit();
        }
        $_SESSION['error']='Unsupported export format.';
    } catch (PDOException $e) { $_SESSION['error']='Export failed: '.$e->getMessage(); }
    header('Location: shipments.php'); exit();
}

// ============================================
// BULK IMPORT
// ============================================
if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploadError=$_FILES['import_file']['error']??UPLOAD_ERR_NO_FILE;
    if($uploadError===UPLOAD_ERR_OK){
        $file=$_FILES['import_file']['tmp_name']; $ext=strtolower(pathinfo($_FILES['import_file']['name'],PATHINFO_EXTENSION)); $imported=0;$skipped=0;$handle=false;
        try{
            if($ext!=='csv') throw new RuntimeException('Only CSV files are supported.');
            if(!is_uploaded_file($file)||!is_readable($file)) throw new RuntimeException('The uploaded CSV file could not be read.');
            $handle=fopen($file,'r'); if($handle===false) throw new RuntimeException('Unable to open the CSV file.');
            $headers=fgetcsv($handle); if($headers===false||!$headers) throw new RuntimeException('The CSV file is empty.');
            $headers=array_map(fn($h)=>preg_replace('/^\xEF\xBB\xBF/','',trim((string)$h)),$headers);
            foreach(['shipment_id','carrier','tracking_number'] as $req) if(!in_array($req,$headers,true)) throw new RuntimeException("Missing required CSV column: {$req}");
            while(($row=fgetcsv($handle))!==false){
                if(count($row)===1&&trim((string)$row[0])==='')continue;
                if(count($row)!==count($headers)){$skipped++;continue;}
                $d=array_combine($headers,$row);$sid=trim((string)($d['shipment_id']??''));$car=trim((string)($d['carrier']??''));$trk=trim((string)($d['tracking_number']??''));
                if($sid===''||$car===''||$trk===''){$skipped++;continue;}
                $mode=strtolower(trim((string)($d['mode']??'road')));if(!in_array($mode,['road','air','ocean','rail'],true))$mode='road';
                try{
                    $st=$pdo->prepare("INSERT INTO shipments (shipment_id, carrier, tracking_number, mode, origin, destination, status, freight_cost, created_by) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)");
                    $st->execute([$sid,$car,$trk,$mode,trim((string)($d['origin']??'')),trim((string)($d['destination']??'')),is_numeric($d['freight_cost']??null)?(float)$d['freight_cost']:0,$_SESSION['user_id']]);$imported++;
                }catch(PDOException $e){$skipped++;}
            }
            fclose($handle);logAudit($_SESSION['user_id'],'import_shipments','logistics',"Imported {$imported} shipments; skipped {$skipped} rows");
            $_SESSION['success']="Successfully imported {$imported} shipment(s).".($skipped?" {$skipped} row(s) were skipped.":'');
        }catch(Exception $e){if(is_resource($handle))fclose($handle);$_SESSION['error']='Import failed: '.$e->getMessage();}
    }else{
        $messages=[UPLOAD_ERR_INI_SIZE=>'The uploaded file is too large.',UPLOAD_ERR_FORM_SIZE=>'The uploaded file is too large.',UPLOAD_ERR_PARTIAL=>'The file upload was interrupted.',UPLOAD_ERR_NO_FILE=>'Please select a CSV file to import.',UPLOAD_ERR_NO_TMP_DIR=>'Server upload directory is missing.',UPLOAD_ERR_CANT_WRITE=>'The server could not save the uploaded file.',UPLOAD_ERR_EXTENSION=>'The upload was blocked by a server extension.'];
        $_SESSION['error']=$messages[$uploadError]??'Unable to upload the selected file.';
    }
    header('Location: shipments.php');exit();
}

// ============================================
// CREATE SHIPMENT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $shipment_id = isset($_POST['shipment_id']) ? trim($_POST['shipment_id']) : '';
    $po_id = isset($_POST['po_id']) && !empty($_POST['po_id']) ? (int)$_POST['po_id'] : null;
    $carrier = isset($_POST['carrier']) ? trim($_POST['carrier']) : '';
    $tracking_number = isset($_POST['tracking_number']) ? trim($_POST['tracking_number']) : '';
    $mode = isset($_POST['mode']) ? $_POST['mode'] : 'road';
    $origin = isset($_POST['origin']) ? trim($_POST['origin']) : '';
    $destination = isset($_POST['destination']) ? trim($_POST['destination']) : '';
    $departure_date = isset($_POST['departure_date']) ? $_POST['departure_date'] : null;
    $expected_arrival = isset($_POST['expected_arrival']) ? $_POST['expected_arrival'] : null;
    $freight_cost = isset($_POST['freight_cost']) ? (float)$_POST['freight_cost'] : 0;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    
    $errors = [];
    if (empty($shipment_id)) $errors[] = 'Shipment ID is required';
    if (empty($carrier)) $errors[] = 'Carrier is required';
    if (empty($tracking_number)) $errors[] = 'Tracking number is required';
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("INSERT INTO shipments (shipment_id, po_id, carrier, tracking_number, mode, origin, destination, departure_date, expected_arrival, freight_cost, notes, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)");
            $stmt->execute([$shipment_id, $po_id, $carrier, $tracking_number, $mode, $origin, $destination, $departure_date, $expected_arrival, $freight_cost, $notes, $_SESSION['user_id']]);
            
            // Update PO status
            if ($po_id) {
                $stmt = $pdo->prepare("UPDATE purchase_orders SET status = 'shipped' WHERE id = ?");
                $stmt->execute([$po_id]);
            }
            
            $pdo->commit();
            
            logAudit($_SESSION['user_id'], 'create_shipment', 'logistics', "Created shipment: $shipment_id");
            $_SESSION['success'] = "Shipment created successfully!";
            header('Location: shipments.php');
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Error creating shipment: " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// ============================================
// UPDATE SHIPMENT STATUS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_status') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $status = isset($_POST['status']) ? $_POST['status'] : '';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    
    if ($id > 0 && !empty($status)) {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("UPDATE shipments SET status = ?, notes = ? WHERE id = ?");
            $stmt->execute([$status, $notes, $id]);
            
            if ($status === 'delivered') {
                $stmt = $pdo->prepare("UPDATE shipments SET actual_arrival = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$id]);
                
                // Update PO status
                $stmt = $pdo->prepare("SELECT po_id FROM shipments WHERE id = ?");
                $stmt->execute([$id]);
                $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($shipment && !empty($shipment['po_id'])) {
                    $stmt = $pdo->prepare("UPDATE purchase_orders SET status = 'shipped' WHERE id = ? AND status NOT IN ('received', 'completed', 'cancelled')");
                    $stmt->execute([$shipment['po_id']]);
                }
            }
            
            $pdo->commit();
            
            logAudit($_SESSION['user_id'], 'update_shipment_status', 'logistics', "Updated shipment ID $id to status: $status");
            $_SESSION['success'] = "Shipment status updated successfully!";
            header('Location: shipments.php');
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Error updating shipment: " . $e->getMessage();
        }
    } else {
        $error = "Invalid shipment or status";
    }
}

// ============================================
// ARCHIVE SHIPMENT
// ============================================
if ($action === 'archive' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    archiveRecord('shipments', $id);
    logAudit($_SESSION['user_id'], 'archive_shipment', 'logistics', "Archived shipment ID: $id");
    $_SESSION['success'] = "Shipment archived successfully!";
    header('Location: shipments.php');
    exit();
}

// ============================================
// RESTORE SHIPMENT
// ============================================
if ($action === 'restore' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    restoreRecord('shipments', $id);
    logAudit($_SESSION['user_id'], 'restore_shipment', 'logistics', "Restored shipment ID: $id");
    $_SESSION['success'] = "Shipment restored successfully!";
    header('Location: shipments.php?archived=1');
    exit();
}

// ============================================
// VIEW SHIPMENT DETAILS
// ============================================
$viewShipment = null;
if ($action === 'view' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("SELECT s.*, p.po_number, u.full_name as created_by_name 
                               FROM shipments s 
                               LEFT JOIN purchase_orders p ON s.po_id = p.id 
                               LEFT JOIN users u ON s.created_by = u.id 
                               WHERE s.id = ?");
        $stmt->execute([$_GET['id']]);
        $viewShipment = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error fetching shipment: " . $e->getMessage();
        header('Location: shipments.php');
        exit();
    }
}

// ============================================
// GET SHIPMENTS WITH FILTERS & SORTING
// ============================================
$showArchived = isset($_GET['archived']) ? 1 : 0;
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$modeFilter = isset($_GET['mode']) ? $_GET['mode'] : '';
$carrierFilter = isset($_GET['carrier']) ? $_GET['carrier'] : '';
$originFilter = isset($_GET['origin']) ? $_GET['origin'] : '';
$destinationFilter = isset($_GET['destination']) ? $_GET['destination'] : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sortField = isset($_GET['sort']) ? $_GET['sort'] : 'created_at';
$sortOrder = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'ASC' : 'DESC';

$allowedSortFields = ['shipment_id', 'carrier', 'tracking_number', 'mode', 'status', 'created_at', 'expected_arrival'];
if (!in_array($sortField, $allowedSortFields)) {
    $sortField = 'created_at';
}

try {
    $query = "SELECT s.*, p.po_number, u.full_name as created_by_name 
              FROM shipments s 
              LEFT JOIN purchase_orders p ON s.po_id = p.id 
              LEFT JOIN users u ON s.created_by = u.id 
              WHERE s.is_archived = ?";
    $params = [$showArchived];
    
    if (!empty($search)) {
        $query .= " AND (s.shipment_id LIKE ? OR s.carrier LIKE ? OR s.tracking_number LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    if (!empty($statusFilter)) {
        $query .= " AND s.status = ?";
        $params[] = $statusFilter;
    }
    
    if (!empty($modeFilter)) {
        $query .= " AND s.mode = ?";
        $params[] = $modeFilter;
    }
    
    if (!empty($carrierFilter)) {
        $query .= " AND s.carrier = ?";
        $params[] = $carrierFilter;
    }
    
    if (!empty($originFilter)) {
        $query .= " AND s.origin = ?";
        $params[] = $originFilter;
    }
    
    if (!empty($destinationFilter)) {
        $query .= " AND s.destination = ?";
        $params[] = $destinationFilter;
    }
    
    if (!empty($dateFrom)) {
        $query .= " AND DATE(s.created_at) >= ?";
        $params[] = $dateFrom;
    }
    
    if (!empty($dateTo)) {
        $query .= " AND DATE(s.created_at) <= ?";
        $params[] = $dateTo;
    }
    
    $query .= " ORDER BY $sortField $sortOrder";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $shipments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $shipments = [];
    $error = "Error fetching shipments: " . $e->getMessage();
}

// ============================================
// GET STATS
// ============================================
try {
    $stmt = $pdo->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'in_transit' THEN 1 ELSE 0 END) as in_transit,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
        SUM(CASE WHEN status = 'delayed' THEN 1 ELSE 0 END) as delayed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM shipments WHERE is_archived = 0");
    $shipmentStats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $shipmentStats = ['total' => 0, 'pending' => 0, 'in_transit' => 0, 'delivered' => 0, 'delayed' => 0, 'cancelled' => 0];
}

// Pagination
// Always normalize pagination values to integers before doing arithmetic.
// This prevents PHP 8+ "Unsupported operand types: string - int" errors
// when page/per_page values arrive from the URL.
$itemsPerPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$allowedPerPage = [5, 10, 25, 50, 100];
if (!in_array($itemsPerPage, $allowedPerPage, true)) {
    $itemsPerPage = 10;
}

$totalItems = count($shipments);
$totalPages = max(1, (int)ceil($totalItems / $itemsPerPage));

$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$currentPage = max(1, min((int)$currentPage, $totalPages));

$offset = ((int)$currentPage - 1) * (int)$itemsPerPage;
$previousPage = max(1, (int)$currentPage - 1);
$nextPage = min($totalPages, (int)$currentPage + 1);

$paginatedShipments = array_slice($shipments, $offset, $itemsPerPage);

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
    <title>Shipment Tracking - GlobalSCM</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Leaflet Map CSS & JS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
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
        
        /* View Toggle */
        .view-toggle {
            display: flex;
            gap: 4px;
            background: var(--bg);
            border-radius: var(--radius-sm);
            padding: 4px;
            border: 1px solid var(--border);
        }
        
        .view-toggle .view-btn {
            padding: 6px 14px;
            border: none;
            border-radius: 4px;
            background: transparent;
            color: var(--secondary-text);
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .view-toggle .view-btn:hover {
            color: var(--text);
        }
        
        .view-toggle .view-btn.active {
            background: var(--card);
            color: var(--primary);
            box-shadow: var(--shadow);
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
            min-width: 1100px;
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
        
        .shipment-pending { background: #E5E7EB; color: #374151; }
        .shipment-in_transit { background: #DBEAFE; color: #1E40AF; }
        .shipment-delivered { background: #D1FAE5; color: #065F46; }
        .shipment-delayed { background: #FEF3C7; color: #92400E; }
        .shipment-cancelled { background: #FEE2E2; color: #DC2626; }
        
        /* Status Progress Bar */
        .status-progress {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
        }
        
        .status-progress .step {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 12px;
            background: var(--bg);
            color: var(--secondary-text);
            transition: var(--transition);
        }
        
        .status-progress .step.active {
            background: var(--primary);
            color: white;
        }
        
        .status-progress .step.completed {
            background: var(--accent);
            color: white;
        }
        
        .status-progress .step.delayed {
            background: var(--danger);
            color: white;
        }
        
        .status-progress .step i {
            font-size: 10px;
        }
        
        .status-progress .arrow {
            color: var(--secondary-text);
            font-size: 10px;
        }
        
        .mode-icon {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 12px;
            background: rgba(47, 128, 237, 0.1);
            color: var(--primary);
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
        
        .tracking-link {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            color: var(--primary);
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .tracking-link:hover {
            text-decoration: underline;
        }
        
        /* Exception Alert */
        .exception-alert {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .exception-alert.delayed {
            background: #FEE2E2;
            color: #DC2626;
        }
        
        .exception-alert.customs {
            background: #FEF3C7;
            color: #92400E;
        }
        
        .exception-alert.damaged {
            background: #FEE2E2;
            color: #DC2626;
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
            display: <?php echo ($viewShipment || $action === 'create' || isset($error)) ? 'flex' : 'none'; ?>;
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
        
        /* ===== FILE UPLOAD ===== */
        .file-upload-area {
            border: 2px dashed var(--border);
            border-radius: var(--radius-sm);
            padding: 30px;
            text-align: center;
            transition: var(--transition);
            cursor: pointer;
        }
        
        .file-upload-area:hover {
            border-color: var(--primary);
            background: rgba(47, 128, 237, 0.02);
        }
        
        .file-upload-area .icon {
            font-size: 40px;
            color: var(--secondary-text);
            margin-bottom: 10px;
        }
        
        .file-upload-area .text {
            font-size: 14px;
            color: var(--secondary-text);
        }
        
        .file-upload-area .text strong {
            color: var(--primary);
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
        
        /* Status Modal */
        #statusModal .modal {
            max-width: 500px;
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
            
            .status-progress {
                flex-direction: column;
                gap: 2px;
            }
            
            .status-progress .arrow {
                transform: rotate(90deg);
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
                    <h1>Shipment Tracking</h1>
                    <p>Manage and track logistics shipments</p>
                </div>
                <div class="top-bar-actions">
                    <a href="shipments.php?action=create" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Shipment
                    </a>

                    <div class="dropdown">
                        <button type="button" class="btn btn-outline" onclick="toggleImportDropdown(); return false;">
                            <i class="fas fa-upload"></i> Import
                        </button>
                        <div class="dropdown-content" id="importDropdown">
                            <a href="javascript:void(0)" onclick="triggerImportFile(); return false;">
                                <i class="fas fa-file-csv"></i> Import CSV
                            </a>
                            <a href="javascript:void(0)" onclick="downloadTemplate(); return false;">
                                <i class="fas fa-download"></i> Download Template
                            </a>
                        </div>
                    </div>

                    <div class="dropdown">
                        <button type="button" class="btn btn-outline" onclick="toggleExportDropdown(); return false;">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <div class="dropdown-content" id="exportDropdown">
                            <a href="shipments.php?action=export&amp;format=excel&amp;status=<?php echo urlencode($statusFilter); ?>&amp;mode=<?php echo urlencode($modeFilter); ?>&amp;carrier=<?php echo urlencode($carrierFilter); ?>&amp;origin=<?php echo urlencode($originFilter); ?>&amp;destination=<?php echo urlencode($destinationFilter); ?>&amp;date_from=<?php echo urlencode($dateFrom); ?>&amp;date_to=<?php echo urlencode($dateTo); ?>&amp;search=<?php echo urlencode($search); ?>">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </a>
                            <a href="shipments.php?action=export&amp;format=pdf&amp;status=<?php echo urlencode($statusFilter); ?>&amp;mode=<?php echo urlencode($modeFilter); ?>&amp;carrier=<?php echo urlencode($carrierFilter); ?>&amp;origin=<?php echo urlencode($originFilter); ?>&amp;destination=<?php echo urlencode($destinationFilter); ?>&amp;date_from=<?php echo urlencode($dateFrom); ?>&amp;date_to=<?php echo urlencode($dateTo); ?>&amp;search=<?php echo urlencode($search); ?>">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </a>
                        </div>
                    </div>

                    <!-- View Toggle -->
                    <div class="view-toggle no-print">
                        <button class="view-btn <?php echo $viewMode === 'list' ? 'active' : ''; ?>" onclick="setView('list')">
                            <i class="fas fa-list"></i> List
                        </button>
                        <button class="view-btn <?php echo $viewMode === 'map' ? 'active' : ''; ?>" onclick="setView('map')">
                            <i class="fas fa-map"></i> Map
                        </button>
                    </div>
                    
                    <a href="shipments.php<?php echo $showArchived ? '' : '?archived=1'; ?>" class="btn btn-outline">
                        <i class="fas fa-archive"></i> <?php echo $showArchived ? 'Active' : 'Archived'; ?>
                    </a>
                    <a href="dashboard.php" class="btn btn-back">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
            
            <!-- Import File Input (Hidden) -->
            <form id="importForm" method="POST" action="shipments.php?action=import" enctype="multipart/form-data" style="display:none;">
                <input type="file" id="importFileInput" name="import_file" accept=".csv">
            </form>
            
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
                    <div class="label"><i class="fas fa-ship"></i> Total Shipments</div>
                    <div class="value"><?php echo number_format($shipmentStats['total'] ?? 0); ?></div>
                </div>
                <div class="stat-card" style="border-color: #DBEAFE;">
                    <div class="label"><i class="fas fa-truck" style="color: #1E40AF;"></i> In Transit</div>
                    <div class="value" style="color: #1E40AF;"><?php echo number_format($shipmentStats['in_transit'] ?? 0); ?></div>
                </div>
                <div class="stat-card" style="border-color: var(--accent);">
                    <div class="label"><i class="fas fa-check-circle" style="color: var(--accent);"></i> Delivered</div>
                    <div class="value" style="color: var(--accent);"><?php echo number_format($shipmentStats['delivered'] ?? 0); ?></div>
                </div>
                <div class="stat-card" style="border-color: #F59E0B;">
                    <div class="label"><i class="fas fa-exclamation-triangle" style="color: #F59E0B;"></i> Delayed</div>
                    <div class="value" style="color: #F59E0B;"><?php echo number_format($shipmentStats['delayed'] ?? 0); ?></div>
                </div>
            </div>
            
            <!-- Search & Filter Bar -->
            <div class="filter-bar">
                <form method="GET" style="display: flex; gap: 12px; flex-wrap: wrap; width: 100%; align-items: center;">
                    <input type="hidden" name="archived" value="<?php echo $showArchived; ?>">
                    <input type="hidden" name="view" value="<?php echo $viewMode; ?>">
                    
                    <input type="text" name="search" class="search-input" 
                           placeholder="Search by shipment ID, carrier, or tracking..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="in_transit" <?php echo $statusFilter === 'in_transit' ? 'selected' : ''; ?>>In Transit</option>
                        <option value="delivered" <?php echo $statusFilter === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                        <option value="delayed" <?php echo $statusFilter === 'delayed' ? 'selected' : ''; ?>>Delayed</option>
                        <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                    
                    <select name="mode">
                        <option value="">All Modes</option>
                        <option value="air" <?php echo $modeFilter === 'air' ? 'selected' : ''; ?>>Air</option>
                        <option value="ocean" <?php echo $modeFilter === 'ocean' ? 'selected' : ''; ?>>Ocean</option>
                        <option value="rail" <?php echo $modeFilter === 'rail' ? 'selected' : ''; ?>>Rail</option>
                        <option value="road" <?php echo $modeFilter === 'road' ? 'selected' : ''; ?>>Road</option>
                    </select>
                    
                    <select name="carrier">
                        <option value="">All Carriers</option>
                        <?php foreach ($carriers as $carrier): ?>
                        <option value="<?php echo htmlspecialchars($carrier); ?>" <?php echo $carrierFilter === $carrier ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($carrier); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select name="origin">
                        <option value="">All Origins</option>
                        <?php foreach ($locations as $location): ?>
                        <option value="<?php echo htmlspecialchars($location); ?>" <?php echo $originFilter === $location ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($location); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select name="destination">
                        <option value="">All Destinations</option>
                        <?php foreach ($locations as $location): ?>
                        <option value="<?php echo htmlspecialchars($location); ?>" <?php echo $destinationFilter === $location ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($location); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <input type="date" name="date_from" placeholder="From" value="<?php echo $dateFrom; ?>">
                    <input type="date" name="date_to" placeholder="To" value="<?php echo $dateTo; ?>">
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <?php if (!empty($search) || !empty($statusFilter) || !empty($modeFilter) || !empty($carrierFilter) || !empty($originFilter) || !empty($destinationFilter) || !empty($dateFrom) || !empty($dateTo)): ?>
                        <a href="shipments.php<?php echo $showArchived ? '?archived=1' : ''; ?>" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <div class="table-container">
                <div class="table-header">
                    <h2><?php echo $showArchived ? 'Archived Shipments' : 'Shipments'; ?></h2>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <span class="role-badge"><?php echo count($shipments); ?> shipments</span>
                        <span class="role-badge">Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>
                    </div>
                </div>
                <div class="table-wrapper">
                    <?php if ($viewMode === 'map'): ?>
                    <!-- Interactive Leaflet Shipment Tracking Map -->
                    <div id="shipmentMapLayout" style="display: grid; grid-template-columns: 1fr 340px; gap: 16px; min-height: 600px; padding: 12px; background: var(--bg); border-radius: var(--radius); margin-bottom: 20px;">
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; background: var(--card); padding: 10px 16px; border-radius: var(--radius-sm); border: 1px solid var(--border); flex-wrap: wrap; gap: 10px;">
                                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                    <span style="font-weight: 600; font-size: 13px; color: var(--text);"><i class="fas fa-layer-group" style="color: var(--primary);"></i> Map Filter:</span>
                                    <button type="button" class="btn btn-primary btn-sm map-filter-btn" data-filter="all" onclick="filterMapMarkers('all', this)">All (<?php echo count($shipments); ?>)</button>
                                    <button type="button" class="btn btn-outline btn-sm map-filter-btn" data-filter="in_transit" onclick="filterMapMarkers('in_transit', this)">In Transit</button>
                                    <button type="button" class="btn btn-outline btn-sm map-filter-btn" data-filter="delivered" onclick="filterMapMarkers('delivered', this)">Delivered</button>
                                    <button type="button" class="btn btn-outline btn-sm map-filter-btn" data-filter="delayed" onclick="filterMapMarkers('delayed', this)">Delayed</button>
                                    <button type="button" class="btn btn-outline btn-sm map-filter-btn" data-filter="pending" onclick="filterMapMarkers('pending', this)">Pending</button>
                                </div>
                                <div style="font-size: 12px; color: var(--secondary-text);">
                                    <i class="fas fa-satellite" style="color: var(--accent);"></i> Real-Time OpenStreetMap Active
                                </div>
                            </div>
                            <div id="shipmentMap" style="width: 100%; height: 560px; border-radius: var(--radius-sm); border: 1px solid var(--border); z-index: 1;"></div>
                        </div>
                        
                        <!-- Map Sidebar of Active Shipments -->
                        <div style="display: flex; flex-direction: column; background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-sm); overflow: hidden;">
                            <div style="padding: 12px 16px; background: var(--bg); border-bottom: 1px solid var(--border); font-weight: 600; font-size: 13px; display: flex; justify-content: space-between; align-items: center;">
                                <span><i class="fas fa-route" style="color: var(--primary);"></i> Routes & Cargo</span>
                                <span class="role-badge" id="mapVisibleCount"><?php echo count($shipments); ?> on map</span>
                            </div>
                            <div id="mapShipmentsList" style="flex: 1; overflow-y: auto; max-height: 560px; padding: 10px; display: flex; flex-direction: column; gap: 10px;">
                                <?php if (!empty($shipments)): ?>
                                    <?php foreach ($shipments as $s): ?>
                                    <div class="map-shipment-card" 
                                         id="mapCard_<?php echo (int)$s['id']; ?>"
                                         data-id="<?php echo (int)$s['id']; ?>" 
                                         data-status="<?php echo htmlspecialchars($s['status']); ?>"
                                         onclick="focusShipmentOnMap(<?php echo (int)$s['id']; ?>)"
                                         style="padding: 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--bg); cursor: pointer; transition: all 0.2s ease;">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                            <strong style="color: var(--primary); font-size: 13px;"><?php echo htmlspecialchars($s['shipment_id']); ?></strong>
                                            <span class="status-badge shipment-<?php echo $s['status']; ?>" style="font-size: 10px; padding: 2px 6px;">
                                                <?php echo ucfirst(str_replace('_', ' ', $s['status'])); ?>
                                            </span>
                                        </div>
                                        <div style="font-size: 12px; color: var(--text); font-weight: 500;">
                                            <?php echo htmlspecialchars($s['origin'] ?: 'Hub Origin'); ?> &rarr; <?php echo htmlspecialchars($s['destination'] ?: 'Destination Port'); ?>
                                        </div>
                                        <div style="font-size: 11px; color: var(--secondary-text); margin-top: 4px; display: flex; justify-content: space-between;">
                                            <span><?php echo htmlspecialchars($s['carrier'] ?: 'Standard Logistics'); ?></span>
                                            <span>ETA: <?php echo htmlspecialchars($s['expected_arrival'] ?: 'TBD'); ?></span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="padding: 20px; text-align: center; color: var(--secondary-text); font-size: 13px;">No shipments available to display on map.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- List View -->
                    <table>
                        <thead>
                            <tr>
                                <th onclick="sortTable('shipment_id')" class="<?php echo $sortField === 'shipment_id' ? 'sorted' : ''; ?>">
                                    Shipment ID <span class="sort-icon"><?php echo $sortField === 'shipment_id' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th onclick="sortTable('po_number')" class="<?php echo $sortField === 'po_number' ? 'sorted' : ''; ?>">
                                    PO # <span class="sort-icon"><?php echo $sortField === 'po_number' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th onclick="sortTable('carrier')" class="<?php echo $sortField === 'carrier' ? 'sorted' : ''; ?>">
                                    Carrier <span class="sort-icon"><?php echo $sortField === 'carrier' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th onclick="sortTable('tracking_number')" class="<?php echo $sortField === 'tracking_number' ? 'sorted' : ''; ?>">
                                    Tracking <span class="sort-icon"><?php echo $sortField === 'tracking_number' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th onclick="sortTable('mode')" class="<?php echo $sortField === 'mode' ? 'sorted' : ''; ?>">
                                    Mode <span class="sort-icon"><?php echo $sortField === 'mode' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th onclick="sortTable('status')" class="<?php echo $sortField === 'status' ? 'sorted' : ''; ?>">
                                    Status <span class="sort-icon"><?php echo $sortField === 'status' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                </th>
                                <th>ETA</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($paginatedShipments)): ?>
                                <?php foreach ($paginatedShipments as $shipment): ?>
                                <?php 
                                // Determine status progress
                                $statusSteps = [
                                    'pending' => ['label' => 'Pending', 'icon' => 'fa-clock', 'class' => ''],
                                    'in_transit' => ['label' => 'In Transit', 'icon' => 'fa-truck', 'class' => ''],
                                    'delayed' => ['label' => 'Delayed', 'icon' => 'fa-exclamation-triangle', 'class' => 'delayed'],
                                    'delivered' => ['label' => 'Delivered', 'icon' => 'fa-check-circle', 'class' => 'completed'],
                                    'cancelled' => ['label' => 'Cancelled', 'icon' => 'fa-times-circle', 'class' => 'delayed']
                                ];
                                
                                $currentStep = $statusSteps[$shipment['status']] ?? $statusSteps['pending'];
                                $isDelayed = $shipment['status'] === 'delayed';
                                $isDelivered = $shipment['status'] === 'delivered';
                                
                                // Exception alerts
                                $exceptionClass = '';
                                $exceptionText = '';
                                if ($shipment['status'] === 'delayed') {
                                    $exceptionClass = 'delayed';
                                    $exceptionText = 'Delayed';
                                } elseif (strpos(strtolower($shipment['notes'] ?? ''), 'customs') !== false) {
                                    $exceptionClass = 'customs';
                                    $exceptionText = 'Customs Hold';
                                } elseif (strpos(strtolower($shipment['notes'] ?? ''), 'damage') !== false) {
                                    $exceptionClass = 'damaged';
                                    $exceptionText = 'Damaged';
                                }
                                
                                $trackingUrl = '#';
                                if (!empty($shipment['tracking_number'])) {
                                    // Default tracking link - in production, use carrier-specific URLs
                                    $trackingUrl = 'https://www.google.com/search?q=' . urlencode($shipment['tracking_number']);
                                }
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($shipment['shipment_id']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($shipment['po_number'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($shipment['carrier']); ?></td>
                                    <td>
                                        <a href="<?php echo $trackingUrl; ?>" target="_blank" class="tracking-link">
                                            <?php echo htmlspecialchars($shipment['tracking_number']); ?>
                                            <i class="fas fa-external-link-alt" style="font-size: 10px;"></i>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="mode-icon">
                                            <i class="fas fa-<?php 
                                                $modeIcon = $shipment['mode'] ?? 'road';
                                                echo $modeIcon === 'air' ? 'plane' : ($modeIcon === 'ocean' ? 'ship' : ($modeIcon === 'rail' ? 'train' : 'truck'));
                                            ?>"></i>
                                            <?php echo ucfirst($shipment['mode'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="status-progress">
                                            <?php
                                            $steps = ['pending', 'in_transit', 'delivered'];
                                            $currentStatus = $shipment['status'];
                                            $stepIndex = array_search($currentStatus, $steps);
                                            if ($stepIndex === false && $currentStatus === 'delayed') {
                                                // Show delayed status
                                                echo '<span class="step delayed"><i class="fas fa-exclamation-triangle"></i> Delayed</span>';
                                            } else {
                                                foreach ($steps as $index => $step):
                                                    $isActive = $index <= $stepIndex;
                                                    $isCompleted = $index < $stepIndex;
                                                    $stepLabels = ['pending' => 'Pending', 'in_transit' => 'In Transit', 'delivered' => 'Delivered'];
                                                    $stepIcons = ['pending' => 'fa-clock', 'in_transit' => 'fa-truck', 'delivered' => 'fa-check-circle'];
                                            ?>
                                                <span class="step <?php echo $isCompleted ? 'completed' : ($isActive ? 'active' : ''); ?>">
                                                    <i class="fas <?php echo $stepIcons[$step]; ?>"></i>
                                                    <?php echo $stepLabels[$step]; ?>
                                                </span>
                                                <?php if ($index < count($steps) - 1): ?>
                                                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                                                <?php endif; ?>
                                            <?php 
                                                endforeach;
                                            }
                                            ?>
                                        </div>
                                        <?php if (!empty($exceptionClass)): ?>
                                        <br><span class="exception-alert <?php echo $exceptionClass; ?>">
                                            <i class="fas fa-exclamation-circle"></i> <?php echo $exceptionText; ?>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size: 13px;">
                                        <?php if (!empty($shipment['expected_arrival'])): ?>
                                            <?php echo date('M d, Y', strtotime($shipment['expected_arrival'])); ?>
                                        <?php else: ?>
                                            <span style="color: var(--secondary-text);">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="shipments.php?action=view&id=<?php echo $shipment['id']; ?>" 
                                               class="btn btn-primary btn-sm" title="View Timeline">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if (!$showArchived && $shipment['status'] !== 'delivered' && $shipment['status'] !== 'cancelled'): ?>
                                            <button onclick="openStatusModal(<?php echo $shipment['id']; ?>, '<?php echo htmlspecialchars($shipment['shipment_id']); ?>')" 
                                                    class="btn btn-success btn-sm" title="Update Status">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if (!$showArchived): ?>
                                            <a href="shipments.php?action=archive&id=<?php echo $shipment['id']; ?>" 
                                               class="btn btn-warning btn-sm" 
                                               onclick="return confirm('Archive this shipment?');" title="Archive">
                                                <i class="fas fa-archive"></i>
                                            </a>
                                            <?php else: ?>
                                            <a href="shipments.php?action=restore&id=<?php echo $shipment['id']; ?>" 
                                               class="btn btn-success btn-sm"
                                               onclick="return confirm('Restore this shipment?');" title="Restore">
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
                                        <i class="fas fa-ship"></i>
                                        <p>No shipments found</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
                
                <!-- Pagination -->
                <div class="pagination-bar">
                    <div class="info">
                        Showing <strong><?php echo $totalItems > 0 ? $offset + 1 : 0; ?></strong> 
                        to <strong><?php echo min($offset + $itemsPerPage, $totalItems); ?></strong> 
                        of <strong><?php echo $totalItems; ?></strong> shipments
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
                        
                        <button type="button" class="page-btn" onclick="goToPage(<?php echo $previousPage; ?>)" 
                                <?php echo (int)$currentPage <= 1 ? 'disabled' : ''; ?>>
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        
                        <?php for ($i = max(1, (int)$currentPage - 2); $i <= min((int)$totalPages, (int)$currentPage + 2); $i++): ?>
                            <button class="page-btn <?php echo $i == $currentPage ? 'active' : ''; ?>" 
                                    onclick="goToPage(<?php echo $i; ?>);">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <button type="button" class="page-btn" onclick="goToPage(<?php echo $nextPage; ?>)" 
                                <?php echo (int)$currentPage >= (int)$totalPages ? 'disabled' : ''; ?>>
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- View Shipment Modal -->
    <?php if ($viewShipment): ?>
    <div class="modal-overlay" style="display: flex;">
        <div class="modal">
            <h3>
                <i class="fas fa-ship" style="color: var(--primary);"></i> 
                Shipment: <?php echo htmlspecialchars($viewShipment['shipment_id']); ?>
                <button type="button" class="close-modal" onclick="window.location.href='shipments.php'">&times;</button>
            </h3>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 20px 0; padding: 15px; background: var(--bg); border-radius: 10px;">
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Carrier</div>
                    <div><strong><?php echo htmlspecialchars($viewShipment['carrier']); ?></strong></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Tracking Number</div>
                    <div><strong><?php echo htmlspecialchars($viewShipment['tracking_number']); ?></strong></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Mode</div>
                    <div><strong><?php echo ucfirst($viewShipment['mode']); ?></strong></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Status</div>
                    <span class="status-badge shipment-<?php echo $viewShipment['status']; ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $viewShipment['status'])); ?>
                    </span>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Origin</div>
                    <div><?php echo htmlspecialchars($viewShipment['origin'] ?? 'N/A'); ?></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Destination</div>
                    <div><?php echo htmlspecialchars($viewShipment['destination'] ?? 'N/A'); ?></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Departure Date</div>
                    <div><?php echo !empty($viewShipment['departure_date']) ? date('M d, Y h:i A', strtotime($viewShipment['departure_date'])) : 'N/A'; ?></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Expected Arrival</div>
                    <div><?php echo !empty($viewShipment['expected_arrival']) ? date('M d, Y h:i A', strtotime($viewShipment['expected_arrival'])) : 'N/A'; ?></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">Freight Cost</div>
                    <div>₱<?php echo number_format($viewShipment['freight_cost'] ?? 0, 2); ?></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--secondary-text);">PO Number</div>
                    <div><?php echo htmlspecialchars($viewShipment['po_number'] ?? 'N/A'); ?></div>
                </div>
                <div style="grid-column: 1 / -1;">
                    <div style="font-size: 12px; color: var(--secondary-text);">Notes</div>
                    <div><?php echo nl2br(htmlspecialchars($viewShipment['notes'] ?? '')); ?></div>
                </div>
            </div>
            
            <div class="form-actions" style="margin-top: 20px;">
                <a href="shipments.php" class="btn btn-outline">Close</a>
                <?php if ($viewShipment['status'] !== 'delivered' && $viewShipment['status'] !== 'cancelled'): ?>
                <button onclick="openStatusModal(<?php echo $viewShipment['id']; ?>, '<?php echo htmlspecialchars($viewShipment['shipment_id']); ?>')" 
                        class="btn btn-primary">
                    <i class="fas fa-edit"></i> Update Status
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Create Shipment Modal -->
    <?php if ($action === 'create'): ?>
    <div class="modal-overlay" style="display: flex;">
        <div class="modal" style="max-width: 600px;">
            <h3>
                <i class="fas fa-plus" style="color: var(--primary);"></i> Create Shipment
                <button type="button" class="close-modal" onclick="window.location.href='shipments.php'">&times;</button>
            </h3>
            <form method="POST" action="shipments.php?action=create">
                <div class="form-group">
                    <label>Shipment ID *</label>
                    <input type="text" name="shipment_id" required 
                           value="SHP-<?php echo date('Ymd') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT); ?>"
                           placeholder="e.g., SHP-20240101-001">
                </div>
                
                <div class="form-group">
                    <label>Related PO</label>
                    <select name="po_id">
                        <option value="">None</option>
                        <?php foreach ($pos as $po): ?>
                        <option value="<?php echo $po['id']; ?>">
                            <?php echo htmlspecialchars($po['po_number']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Carrier *</label>
                        <input type="text" name="carrier" required placeholder="e.g., DHL">
                    </div>
                    <div class="form-group">
                        <label>Tracking Number *</label>
                        <input type="text" name="tracking_number" required placeholder="e.g., 1234567890">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Mode of Transport</label>
                    <select name="mode">
                        <option value="road">Road</option>
                        <option value="air">Air</option>
                        <option value="ocean">Ocean</option>
                        <option value="rail">Rail</option>
                    </select>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Origin</label>
                        <input type="text" name="origin" placeholder="e.g., China">
                    </div>
                    <div class="form-group">
                        <label>Destination</label>
                        <input type="text" name="destination" placeholder="e.g., Manila">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Departure Date</label>
                        <input type="datetime-local" name="departure_date" value="<?php echo date('Y-m-d\TH:i'); ?>">
                    </div>
                    <div class="form-group">
                        <label>Expected Arrival</label>
                        <input type="datetime-local" name="expected_arrival" value="<?php echo date('Y-m-d\TH:i', strtotime('+7 days')); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Freight Cost (₱)</label>
                    <input type="number" name="freight_cost" step="0.01" placeholder="0.00">
                </div>
                
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" rows="2"></textarea>
                </div>
                
                <div class="form-actions">
                    <a href="shipments.php" class="btn btn-outline">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Create Shipment
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
                <i class="fas fa-edit" style="color: var(--primary);"></i> Update Shipment Status
                <button type="button" class="close-modal" onclick="closeStatusModal()">h</button>
            </h3>
            <form method="POST" action="shipments.php?action=update_status">
                <input type="hidden" name="id" id="statusShipmentId">
                <div class="form-group">
                    <label>Shipment ID</label>
                    <p id="statusShipmentIdDisplay" style="font-weight: 500;"></p>
                </div>
                <div class="form-group">
                    <label>New Status</label>
                    <select name="status" required>
                        <option value="pending">Pending</option>
                        <option value="in_transit">In Transit</option>
                        <option value="delayed">Delayed</option>
                        <option value="delivered">Delivered</option>
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
        // DROPDOWN TOGGLES
        // ============================================
        function toggleImportDropdown() {
            var dropdown = document.getElementById('importDropdown');
            dropdown.classList.toggle('show');
            document.getElementById('exportDropdown').classList.remove('show');
        }
        
        function toggleExportDropdown() {
            var dropdown = document.getElementById('exportDropdown');
            dropdown.classList.toggle('show');
            document.getElementById('importDropdown').classList.remove('show');
        }
        
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.dropdown')) {
                document.querySelectorAll('.dropdown-content').forEach(function(el) {
                    el.classList.remove('show');
                });
            }
        });
        
        // ============================================
        // VIEW TOGGLE
        // ============================================
        function setView(view) {
            var url = new URL(window.location.href);
            url.searchParams.set('view', view);
            window.location.href = url.toString();
        }
        
        // ============================================
        // FILE IMPORT
        // ============================================
        function triggerImportFile() {
            var input=document.getElementById('importFileInput');
            if(input){ input.value=''; input.click(); }
        }

        document.addEventListener('DOMContentLoaded', function() {
            var input=document.getElementById('importFileInput');
            var form=document.getElementById('importForm');
            if(input&&form) input.addEventListener('change',function(){
                if(!this.files||!this.files.length)return;
                if(!/\.csv$/i.test(this.files[0].name)){alert('Please select a CSV file.');this.value='';return;}
                form.submit();
            });
        });

        // ============================================
        // STATUS MODAL
        // ============================================
        function openStatusModal(id, shipmentId) {
            document.getElementById('statusShipmentId').value = id;
            document.getElementById('statusShipmentIdDisplay').textContent = shipmentId;
            document.getElementById('statusModal').style.display = 'flex';
        }
        
        function closeStatusModal() {
            document.getElementById('statusModal').style.display = 'none';
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
                    if (this.id !== 'statusModal') {
                        window.location.href = 'shipments.php';
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
                            window.location.href = 'shipments.php';
                        }
                    }
                });
            }
        });
        // ============================================
        // LEAFLET SHIPMENT TRACKING MAP INITIALIZATION
        // ============================================
        var shipmentMapInstance = null;
        var mapMarkersLayer = null;
        var mapRoutesLayer = null;
        var shipmentMarkerRefs = {};

        var HUB_COORDS = {
            'manila': [14.5995, 120.9842],
            'metro manila': [14.5995, 120.9842],
            'port of manila': [14.5833, 120.9667],
            'cebu': [10.3157, 123.8854],
            'cebu port': [10.3000, 123.9000],
            'davao': [7.1907, 125.4553],
            'clark': [15.1856, 120.5599],
            'batangas': [13.7565, 121.0583],
            'subic': [14.8234, 120.2798],
            'cagayan de oro': [8.4542, 124.6319],
            'iloilo': [10.7202, 122.5621],
            'general santos': [6.1164, 125.1716],
            'zamboanga': [6.9214, 122.0790],
            'puerto princesa': [9.7392, 118.7353],
            'singapore': [1.3521, 103.8198],
            'hong kong': [22.3193, 114.1694],
            'tokyo': [35.6762, 139.6503],
            'shanghai': [31.2304, 121.4737],
            'los angeles': [33.7432, -118.2673]
        };

        function getCoordsForPlace(name, defaultIndex) {
            if (!name) return [14.5995 + (defaultIndex * 0.2), 120.9842 + (defaultIndex * 0.2)];
            var key = name.trim().toLowerCase();
            for (var hub in HUB_COORDS) {
                if (key.indexOf(hub) !== -1 || hub.indexOf(key) !== -1) {
                    return HUB_COORDS[hub];
                }
            }
            // Fallback: generate pseudo-consistent coordinate around Philippines / SE Asia
            var hash = 0;
            for (var i = 0; i < key.length; i++) {
                hash = ((hash << 5) - hash) + key.charCodeAt(i);
                hash |= 0;
            }
            var lat = 10.0 + (Math.abs(hash % 800) / 100.0);
            var lng = 120.0 + (Math.abs((hash >> 3) % 600) / 100.0);
            return [lat, lng];
        }

        function initShipmentMap() {
            var mapEl = document.getElementById('shipmentMap');
            if (!mapEl || typeof L === 'undefined') return;

            var shipmentsData = <?php echo json_encode($shipments, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || [];

            // Center around the Philippines / SE Asia
            shipmentMapInstance = L.map('shipmentMap').setView([13.0, 122.5], 6);

            // Add OpenStreetMap tiles
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 18,
                attribution: '&copy; <a href="https://openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(shipmentMapInstance);

            mapMarkersLayer = L.layerGroup().addTo(shipmentMapInstance);
            mapRoutesLayer = L.layerGroup().addTo(shipmentMapInstance);

            plotShipmentsOnMap(shipmentsData, 'all');
        }

        function plotShipmentsOnMap(shipmentsData, filterStatus) {
            if (!shipmentMapInstance || !mapMarkersLayer) return;

            mapMarkersLayer.clearLayers();
            mapRoutesLayer.clearLayers();
            shipmentMarkerRefs = {};

            var bounds = [];
            var count = 0;

            shipmentsData.forEach(function(s, index) {
                if (filterStatus !== 'all' && s.status !== filterStatus) return;

                count++;
                var originCoords = getCoordsForPlace(s.origin || 'Manila', index);
                var destCoords = getCoordsForPlace(s.destination || 'Cebu', index + 1);

                // Determine position based on status
                var midLat, midLng;
                var progress = 0.5;
                if (s.status === 'delivered') progress = 1.0;
                else if (s.status === 'pending') progress = 0.05;
                else if (s.status === 'delayed') progress = 0.4;
                else progress = 0.6; // in_transit

                midLat = originCoords[0] + (destCoords[0] - originCoords[0]) * progress;
                midLng = originCoords[1] + (destCoords[1] - originCoords[1]) * progress;
                var currentPos = [midLat, midLng];

                bounds.push(originCoords);
                bounds.push(destCoords);

                // Colors
                var color = '#2F80ED'; // in_transit
                if (s.status === 'delivered') color = '#27AE60';
                else if (s.status === 'delayed') color = '#DC2626';
                else if (s.status === 'pending') color = '#6B7280';

                // Mode icon
                var modeIcon = 'fa-truck';
                if (s.mode === 'air') modeIcon = 'fa-plane';
                else if (s.mode === 'ocean' || s.mode === 'sea') modeIcon = 'fa-ship';
                else if (s.mode === 'rail') modeIcon = 'fa-train';

                // Polyline Route
                var polyline = L.polyline([originCoords, currentPos, destCoords], {
                    color: color,
                    weight: 3,
                    opacity: 0.85,
                    dashArray: s.status === 'delivered' ? null : '6, 8'
                }).addTo(mapRoutesLayer);

                // Origin pin
                L.circleMarker(originCoords, {
                    radius: 5,
                    fillColor: '#10B981',
                    color: '#FFFFFF',
                    weight: 2,
                    fillOpacity: 1
                }).bindTooltip('Origin: ' + (s.origin || 'Origin Hub'), { permanent: false }).addTo(mapMarkersLayer);

                // Destination pin
                L.circleMarker(destCoords, {
                    radius: 5,
                    fillColor: '#EF4444',
                    color: '#FFFFFF',
                    weight: 2,
                    fillOpacity: 1
                }).bindTooltip('Destination: ' + (s.destination || 'Destination Port'), { permanent: false }).addTo(mapMarkersLayer);

                // Vehicle marker (Icon)
                var customIcon = L.divIcon({
                    className: 'custom-shipment-marker',
                    html: '<div style="background:' + color + '; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:white; box-shadow:0 3px 8px rgba(0,0,0,0.3); border:2px solid white;"><i class="fas ' + modeIcon + '" style="font-size:14px;"></i></div>',
                    iconSize: [32, 32],
                    iconAnchor: [16, 16]
                });

                var popupContent = [
                    '<div style="min-width:200px; font-family:Poppins, sans-serif; font-size:12px;">',
                    '  <div style="font-weight:700; font-size:14px; color:#1F2937; margin-bottom:4px;">' + escapeHtmlStr(s.shipment_id) + '</div>',
                    '  <div style="margin-bottom:6px;"><span class="status-badge shipment-' + s.status + '" style="font-size:10px; padding:2px 6px;">' + (s.status.toUpperCase().replace(/_/g, ' ')) + '</span></div>',
                    '  <div style="color:#4B5563; margin-bottom:2px;"><strong>Route:</strong> ' + escapeHtmlStr(s.origin || 'Origin') + ' &rarr; ' + escapeHtmlStr(s.destination || 'Destination') + '</div>',
                    '  <div style="color:#4B5563; margin-bottom:2px;"><strong>Carrier:</strong> ' + escapeHtmlStr(s.carrier || 'N/A') + ' (' + (s.mode || 'Road').toUpperCase() + ')</div>',
                    '  <div style="color:#4B5563; margin-bottom:6px;"><strong>ETA:</strong> ' + escapeHtmlStr(s.expected_arrival || 'TBD') + '</div>',
                    '  <a href="shipments.php?action=view&id=' + s.id + '" class="btn btn-primary btn-sm" style="display:inline-block; width:100%; text-align:center; padding:5px 8px; font-size:11px; margin-top:4px;">View Full Shipment Details</a>',
                    '</div>'
                ].join('');

                var marker = L.marker(currentPos, { icon: customIcon })
                    .bindPopup(popupContent)
                    .addTo(mapMarkersLayer);

                shipmentMarkerRefs[s.id] = { marker: marker, pos: currentPos };
            });

            // Update badge count
            var countEl = document.getElementById('mapVisibleCount');
            if (countEl) countEl.textContent = count + ' on map';

            // Fit map to visible bounds if any
            if (bounds.length > 0) {
                shipmentMapInstance.fitBounds(bounds, { padding: [40, 40], maxZoom: 8 });
            }
        }

        function filterMapMarkers(status, btn) {
            document.querySelectorAll('.map-filter-btn').forEach(function(b) {
                b.classList.remove('btn-primary');
                b.classList.add('btn-outline');
            });
            if (btn) {
                btn.classList.remove('btn-outline');
                btn.classList.add('btn-primary');
            }

            var shipmentsData = <?php echo json_encode($shipments, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || [];
            plotShipmentsOnMap(shipmentsData, status);

            // Filter side list cards
            document.querySelectorAll('.map-shipment-card').forEach(function(card) {
                var cardStatus = card.getAttribute('data-status');
                if (status === 'all' || cardStatus === status) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function focusShipmentOnMap(id) {
            if (!shipmentMarkerRefs[id] || !shipmentMapInstance) return;

            var ref = shipmentMarkerRefs[id];
            shipmentMapInstance.setView(ref.pos, 9, { animate: true });
            ref.marker.openPopup();

            // Highlight card
            document.querySelectorAll('.map-shipment-card').forEach(function(c) {
                c.style.borderColor = 'var(--border)';
                c.style.background = 'var(--bg)';
            });
            var activeCard = document.getElementById('mapCard_' + id);
            if (activeCard) {
                activeCard.style.borderColor = 'var(--primary)';
                activeCard.style.background = 'rgba(47, 128, 237, 0.08)';
            }
        }

        function escapeHtmlStr(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        document.addEventListener('DOMContentLoaded', function() {
            initShipmentMap();
        });
    </script>
</body>
</html>