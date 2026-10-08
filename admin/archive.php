<?php
// admin/archive.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth('admin');

// ============================================
// COLOR CONSTANTS
// ============================================
if (!defined('COLOR_PRIMARY')) define('COLOR_PRIMARY', '#2F80ED');
if (!defined('COLOR_SECONDARY')) define('COLOR_SECONDARY', '#56CCF2');
if (!defined('COLOR_ACCENT')) define('COLOR_ACCENT', '#27AE60');
if (!defined('COLOR_BG')) define('COLOR_BG', '#F8FAFC');
if (!defined('COLOR_CARD')) define('COLOR_CARD', '#FFFFFF');
if (!defined('COLOR_TEXT')) define('COLOR_TEXT', '#1F2937');
if (!defined('COLOR_SECONDARY_TEXT')) define('COLOR_SECONDARY_TEXT', '#6B7280');
if (!defined('COLOR_BORDER')) define('COLOR_BORDER', '#EEF2F7');
if (!defined('COLOR_DARK_BG')) define('COLOR_DARK_BG', '#0F172A');
if (!defined('COLOR_DARK_CARD')) define('COLOR_DARK_CARD', '#1E293B');
if (!defined('COLOR_DARK_TEXT')) define('COLOR_DARK_TEXT', '#F1F5F9');
if (!defined('COLOR_DARK_SECONDARY_TEXT')) define('COLOR_DARK_SECONDARY_TEXT', '#94A3B8');
if (!defined('COLOR_DARK_BORDER')) define('COLOR_DARK_BORDER', '#334155');

// ============================================
// REQUEST PARAMETERS
// ============================================
$action     = $_GET['action']  ?? 'list';
$tab        = $_GET['tab']     ?? 'all';
$search     = isset($_GET['search']) ? trim($_GET['search']) : '';
$dateFrom   = $_GET['date_from'] ?? '';
$dateTo     = $_GET['date_to']   ?? '';
$sortField  = $_GET['sort']      ?? 'created_at';
$sortOrder  = (isset($_GET['order']) && strtolower($_GET['order']) === 'asc') ? 'ASC' : 'DESC';

$theme = function_exists('getTheme') ? getTheme() : 'light';
$currentUserId = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? $_SESSION['id'] ?? 0;

// ============================================
// TABLE REGISTRY (single source of truth)
// ============================================
$tables = [
    'users'                 => ['label' => 'Users',              'icon' => 'fa-users',           'name_field' => 'full_name',       'identifier' => 'username',          'module' => 'User Management', 'color' => '#6366F1'],
    'suppliers'             => ['label' => 'Suppliers',          'icon' => 'fa-truck',           'name_field' => 'company_name',    'identifier' => 'supplier_code',     'module' => 'Procurement',     'color' => '#F59E0B'],
    'products'              => ['label' => 'Products',           'icon' => 'fa-box',             'name_field' => 'product_name',    'identifier' => 'sku',               'module' => 'Inventory',       'color' => '#27AE60'],
    'warehouses'            => ['label' => 'Warehouses',         'icon' => 'fa-warehouse',       'name_field' => 'name',            'identifier' => 'warehouse_code',    'module' => 'Warehousing',     'color' => '#56CCF2'],
    'purchase_orders'       => ['label' => 'Purchase Orders',    'icon' => 'fa-file-invoice',    'name_field' => 'po_number',       'identifier' => 'po_number',         'module' => 'Procurement',     'color' => '#8B5CF6'],
    'shipments'             => ['label' => 'Shipments',          'icon' => 'fa-ship',            'name_field' => 'shipment_id',     'identifier' => 'tracking_number',   'module' => 'Logistics',       'color' => '#1E40AF'],
    'purchase_requisitions' => ['label' => 'Requisitions',       'icon' => 'fa-clipboard-list',  'name_field' => 'pr_number',       'identifier' => 'pr_number',         'module' => 'Procurement',     'color' => '#EC4899'],
    'procurement_contracts' => ['label' => 'Contracts',          'icon' => 'fa-file-signature',  'name_field' => 'contract_number', 'identifier' => 'contract_number',   'module' => 'Procurement',     'color' => '#7C3AED'],
    'documents'             => ['label' => 'Documents',          'icon' => 'fa-file-alt',        'name_field' => 'document_number', 'identifier' => 'document_number',   'module' => 'Logistics',       'color' => '#DC2626'],
];

// Use the registry keys as the whitelist — never let them get out of sync
$allowedTables = array_keys($tables);

// ============================================
// DYNAMIC SCHEMA DISCOVERY (per-table)
// ============================================
// Cache the discovered schema so we don't hit SHOW COLUMNS repeatedly.
$schemaCache = [];
function getTableSchema(PDO $pdo, string $table, array &$cache): array {
    if (isset($cache[$table])) return $cache[$table];

    $info = [
        'exists'          => false,
        'columns'         => [],
        'has_archived'    => false,
        'has_created_at'  => false,
        'has_updated_at'  => false,
        'has_deleted_at'  => false,
        'has_created_by'  => false,
        'order_column'    => 'id',
        'archive_column'  => null,
    ];

    try {
        $driverName = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driverName === 'sqlite') {
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
            $stmt->execute([$table]);
            if (!$stmt->fetchColumn()) {
                return $cache[$table] = $info;
            }

            $cleanTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            $stmt = $pdo->query("PRAGMA table_info($cleanTable)");
            $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
            if (empty($cols)) {
                return $cache[$table] = $info;
            }
        } elseif (in_array($driverName, ['pgsql', 'postgres', 'postgresql'], true)) {
            $stmt = $pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?");
            $stmt->execute([$table]);
            if (!$stmt->fetchColumn()) {
                return $cache[$table] = $info;
            }

            $stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? ORDER BY ordinal_position");
            $stmt->execute([$table]);
            $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            if (empty($cols)) {
                return $cache[$table] = $info;
            }
        } else {
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            if (!$stmt->fetchColumn()) {
                return $cache[$table] = $info;
            }

            $cleanTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            $stmt = $pdo->query("SHOW COLUMNS FROM $cleanTable");
            $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            if (empty($cols)) {
                return $cache[$table] = $info;
            }
        }

        $info['exists']         = true;
        $info['columns']        = $cols;
        $info['has_created_at'] = in_array('created_at', $cols, true);
        $info['has_updated_at'] = in_array('updated_at', $cols, true);
        $info['has_deleted_at'] = in_array('deleted_at', $cols, true);
        $info['has_created_by'] = in_array('created_by', $cols, true);

        // Detect the archive flag (supports multiple naming conventions)
        foreach (['is_archived', 'archived', 'is_deleted', 'deleted'] as $candidate) {
            if (in_array($candidate, $cols, true)) {
                $info['has_archived']   = true;
                $info['archive_column'] = $candidate;
                break;
            }
        }

        $info['order_column'] = $info['has_created_at'] ? 'created_at' : 'id';
    } catch (PDOException $e) {
        error_log("[ARCHIVE] schema discovery failed for $table: " . $e->getMessage());
    }

    return $cache[$table] = $info;
}

// ============================================
// RETENTION POLICY
// ============================================
$retentionDays = 90;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute(['archive_retention_days']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && is_numeric($row['setting_value'])) {
        $retentionDays = max(1, (int)$row['setting_value']);
    }
} catch (Exception $e) {
    // Table may not exist yet; fall back to default
    error_log("[ARCHIVE] retention lookup failed: " . $e->getMessage());
}

// ============================================
// HELPER: build the WHERE clause for archive queries
// ============================================
function buildArchiveWhere(PDO $pdo, string $table, array $schema, string $search, string $dateFrom, string $dateTo, array &$params): string {
    // The archive column MUST exist for the table to participate
    $col = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$schema['archive_column']);
    $where = "($col = 1 OR $col = '1' OR $col = 'true' OR $col = TRUE)";
    $params = [];

    if ($search !== '') {
        $searchClauses = [];
        foreach ($schema['columns'] as $c) {
            $cleanC = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$c);
            // Only search TEXT-ish columns; skip binary and numeric-only for perf
            $searchClauses[] = "CAST($cleanC AS TEXT) LIKE ?";
            $params[] = '%' . $search . '%';
        }
        if (!empty($searchClauses)) {
            $where .= ' AND (' . implode(' OR ', $searchClauses) . ')';
        }
    }

    if ($dateFrom !== '' && $schema['has_created_at']) {
        $where .= ' AND created_at >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '' && $schema['has_created_at']) {
        $where .= ' AND created_at <= ?';
        $params[] = $dateTo;
    }

    return $where;
}

// ============================================
// ACTION: PURGE (auto-delete old archived records)
// ============================================
if ($action === 'purge' && isset($_GET['table'])) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$_GET['table']);
    $days  = isset($_GET['days']) ? max(1, (int)$_GET['days']) : $retentionDays;

    $schema = getTableSchema($pdo, $table, $schemaCache);

    if (in_array($table, $allowedTables, true) && $schema['exists'] && $schema['has_archived'] && $schema['has_created_at']) {
        try {
            $col = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$schema['archive_column']);
            $purgeDate = date('Y-m-d H:i:s', strtotime("-$days days"));
            $stmt = $pdo->prepare("DELETE FROM $table WHERE $col = 1 AND created_at < ?");
            $stmt->execute([$purgeDate]);
            $deleted = $stmt->rowCount();

            if (function_exists('logAudit')) {
                logAudit($currentUserId, 'auto_purge_archive', 'archive',
                    "Purged $deleted records from $table older than $days days");
            }
            $_SESSION['success'] = "Successfully purged $deleted records from {$tables[$table]['label']}!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error purging records: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Cannot purge $table — the table is missing an archive column or created_at column.";
    }
    header('Location: archive.php?tab=' . urlencode($tab));
    exit();
}

// ============================================
// ACTION: BULK RESTORE
// ============================================
if ($action === 'bulk_restore' && isset($_POST['ids'], $_POST['table'])) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$_POST['table']);
    $ids   = array_values(array_filter(array_map('intval', (array)$_POST['ids'])));
    $schema = getTableSchema($pdo, $table, $schemaCache);

    if (in_array($table, $allowedTables, true) && $schema['exists'] && $schema['has_archived'] && !empty($ids)) {
        try {
            $col = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$schema['archive_column']);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE $table SET $col = 0 WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $restored = $stmt->rowCount();

            if (function_exists('logAudit')) {
                logAudit($currentUserId, 'bulk_restore_archive', 'archive',
                    "Bulk restored $restored records from $table");
            }
            $_SESSION['success'] = "Successfully restored $restored records!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error restoring records: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Bulk restore failed — invalid table or no IDs selected.";
    }
    header('Location: archive.php?tab=' . urlencode($tab));
    exit();
}

// ============================================
// ACTION: EXPORT CSV
// ============================================
if ($action === 'export' && isset($_GET['table'])) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$_GET['table']);
    $schema = getTableSchema($pdo, $table, $schemaCache);

    if (in_array($table, $allowedTables, true) && $schema['exists'] && $schema['has_archived']) {
        try {
            $params = [];
            $where = buildArchiveWhere($pdo, $table, $schema, $search, $dateFrom, $dateTo, $params);
            $orderCol = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$schema['order_column']);
            $sql = "SELECT * FROM $table WHERE $where ORDER BY $orderCol DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="archive_' . $table . '_' . date('Y-m-d') . '.csv"');

            $output = fopen('php://output', 'w');
            if (!empty($data)) {
                fputcsv($output, array_keys($data[0]));
                foreach ($data as $row) fputcsv($output, $row);
            } else {
                fputcsv($output, ['No records']);
            }
            fclose($output);
            exit();
        } catch (PDOException $e) {
            $_SESSION['error'] = "Export failed: " . $e->getMessage();
            header('Location: archive.php?tab=' . urlencode($tab));
            exit();
        }
    }
    $_SESSION['error'] = "Export failed: invalid table.";
    header('Location: archive.php?tab=' . urlencode($tab));
    exit();
}

// ============================================
// ACTION: RESTORE SINGLE
// ============================================
if ($action === 'restore' && isset($_GET['table'], $_GET['id'])) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$_GET['table']);
    $id    = (int)$_GET['id'];
    $schema = getTableSchema($pdo, $table, $schemaCache);

    if (in_array($table, $allowedTables, true) && $schema['exists'] && $schema['has_archived']) {
        try {
            $col = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$schema['archive_column']);
            $stmt = $pdo->prepare("UPDATE $table SET $col = 0 WHERE id = ?");
            $stmt->execute([$id]);
            if (function_exists('logAudit')) {
                logAudit($currentUserId, 'restore_record', 'archive',
                    "Restored record from $table with ID $id");
            }
            $_SESSION['success'] = "Record restored successfully!";
        } catch (Exception $e) {
            $_SESSION['error'] = "Error restoring record: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Cannot restore — invalid table.";
    }
    header('Location: archive.php?tab=' . urlencode($tab));
    exit();
}

// ============================================
// ACTION: PERMANENT DELETE
// ============================================
if ($action === 'delete' && isset($_GET['table'], $_GET['id'])) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$_GET['table']);
    $id    = (int)$_GET['id'];
    $schema = getTableSchema($pdo, $table, $schemaCache);

    if (in_array($table, $allowedTables, true) && $schema['exists']) {
        try {
            $stmt = $pdo->prepare("DELETE FROM $table WHERE id = ?");
            $stmt->execute([$id]);
            if (function_exists('logAudit')) {
                logAudit($currentUserId, 'permanent_delete', 'archive',
                    "Permanently deleted record from $table with ID $id");
            }
            $_SESSION['success'] = "Record permanently deleted!";
        } catch (Exception $e) {
            $_SESSION['error'] = "Error deleting record: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Cannot delete — invalid table.";
    }
    header('Location: archive.php?tab=' . urlencode($tab));
    exit();
}

// ============================================
// ACTION: VIEW SINGLE
// ============================================
$viewRecord = null;
$viewTable  = null;
if ($action === 'view' && isset($_GET['table'], $_GET['id'])) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$_GET['table']);
    $id    = (int)$_GET['id'];
    $schema = getTableSchema($pdo, $table, $schemaCache);

    if (in_array($table, $allowedTables, true) && $schema['exists'] && $schema['has_archived']) {
        try {
            $col = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$schema['archive_column']);
            $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ? AND ($col = 1 OR $col = '1' OR $col = 'true' OR $col = TRUE)");
            $stmt->execute([$id]);
            $viewRecord = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $viewTable  = $viewRecord ? $table : null;
        } catch (Exception $e) {
            $_SESSION['error'] = "Error fetching record details: " . $e->getMessage();
        }
    }
}

// ============================================
// FETCH ALL ARCHIVED RECORDS
// ============================================
$archivedData = [];
$totalArchived = 0;
$tabCounts = [];
$tableErrors = [];
$tableStatus = []; // diagnostic per-table status

foreach ($tables as $table => $info) {
    $cleanTable = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $schema = getTableSchema($pdo, $cleanTable, $schemaCache);
    $tableStatus[$cleanTable] = $schema;

    // If table doesn't exist or has no archive column, skip cleanly
    if (!$schema['exists']) {
        $archivedData[$cleanTable] = [];
        $tabCounts[$cleanTable] = 0;
        $tableErrors[$cleanTable] = "Table does not exist";
        continue;
    }
    if (!$schema['has_archived']) {
        $archivedData[$cleanTable] = [];
        $tabCounts[$cleanTable] = 0;
        $tableErrors[$cleanTable] = "No archive column (expected is_archived)";
        continue;
    }

    try {
        $params = [];
        $where  = buildArchiveWhere($pdo, $cleanTable, $schema, $search, $dateFrom, $dateTo, $params);
        $orderCol = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$schema['order_column']);
        $sql = "SELECT * FROM $cleanTable WHERE $where ORDER BY $orderCol DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $archivedData[$cleanTable] = $records;
        $tabCounts[$cleanTable] = count($records);
        $totalArchived += count($records);
    } catch (PDOException $e) {
        error_log("[ARCHIVE] fetch failed for $table: " . $e->getMessage());
        $archivedData[$table] = [];
        $tabCounts[$table] = 0;
        $tableErrors[$table] = $e->getMessage();
    }
}

// Compute immutable grand total across all tables to avoid partial sidebar collision
$grandTotalArchived = array_sum($tabCounts);

// ============================================
// BUILD DISPLAY ROWS
// ============================================
$currentTable = ($tab !== 'all' && isset($archivedData[$tab])) ? $tab : null;

if ($tab === 'all') {
    $allRecords = [];
    foreach ($archivedData as $table => $records) {
        foreach ($records as $record) {
            $record['_table']       = $table;
            $record['_label']       = $tables[$table]['label'];
            $record['_icon']        = $tables[$table]['icon'];
            $record['_name_field']  = $tables[$table]['name_field'];
            $record['_identifier']  = $tables[$table]['identifier'] ?? 'id';
            $record['_module']      = $tables[$table]['module'];
            $record['_color']       = $tables[$table]['color'];
            $allRecords[] = $record;
        }
    }

    usort($allRecords, function ($a, $b) use ($sortField, $sortOrder) {
        // Module-based sort resolves against the synthetic _label field
        $field = ($sortField === '_module' || $sortField === '_label') ? '_label' : $sortField;
        $va = $a[$field] ?? '';
        $vb = $b[$field] ?? '';
        // Numeric compare if both are numeric
        if (is_numeric($va) && is_numeric($vb)) {
            $cmp = $va <=> $vb;
        } else {
            $cmp = strcmp((string)$va, (string)$vb);
        }
        return $sortOrder === 'ASC' ? $cmp : -$cmp;
    });

    $displayData  = $allRecords;
    $displayCount = count($allRecords);
} else {
    $displayData  = $archivedData[$currentTable] ?? [];
    $displayCount = count($displayData);
}

// ============================================
// PAGINATION
// ============================================
$itemsPerPage = isset($_GET['per_page']) ? max(1, min(500, (int)$_GET['per_page'])) : 10;
$totalItems   = $displayCount;
$totalPages   = max(1, (int)ceil($totalItems / $itemsPerPage));
$currentPage  = isset($_GET['page']) ? max(1, min((int)$_GET['page'], $totalPages)) : 1;
$offset       = ($currentPage - 1) * $itemsPerPage;

if (!empty($displayData)) {
    $displayData = array_slice($displayData, $offset, $itemsPerPage);
}

// ============================================
// HELPER: pick a display name from a record
// ============================================
function getRecordName(array $record, ?string $tableName, array $tables): string {
    if ($tableName && isset($tables[$tableName]['name_field'])) {
        $f = $tables[$tableName]['name_field'];
        if (!empty($record[$f])) return (string)$record[$f];
    }
    $fallbacks = ['full_name','company_name','product_name','name','po_number','shipment_id',
                  'pr_number','contract_number','document_number','username','title','sku'];
    foreach ($fallbacks as $f) {
        if (!empty($record[$f])) return (string)$record[$f];
    }
    return 'N/A';
}

// ============================================
// DIAGNOSTIC BANNER (remove when stable)
// ============================================
$showDiagnostics = isset($_GET['debug']) && $_GET['debug'] === '1';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo htmlspecialchars($theme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Archive Management - GlobalSCM</title>
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
}
.page-title h1 {
    font-size: 22px;
    font-weight: 600;
    display: flex;
    align-items: center;
}
.page-title h1 i { color: var(--primary); margin-right: 12px; font-size: 24px; }
.page-title p { color: var(--secondary-text); font-size: 14px; margin-top: 2px; margin-left: 36px; }
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
.btn-primary:hover { background: #2563EB; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(47,128,237,0.3); }
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
.btn-xs { padding: 2px 8px; font-size: 11px; border-radius: 4px; gap: 3px; }
.dropdown { position: relative; display: inline-block; }
.dropdown-content {
    display: none;
    position: absolute;
    right: 0; top: 100%;
    background: var(--card);
    min-width: 200px;
    max-height: 400px;
    overflow-y: auto;
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
.dropdown-content a:hover { background: rgba(47,128,237,0.05); color: var(--primary); }
.dropdown-content a i { width: 18px; color: var(--secondary-text); }
.dropdown-content a.disabled { opacity: 0.45; pointer-events: none; }
.tabs-container {
    display: flex;
    gap: 4px;
    margin-bottom: 20px;
    flex-wrap: wrap;
    background: var(--card);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    padding: 6px 12px;
    box-shadow: var(--shadow);
}
.tab-btn {
    padding: 8px 16px;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--secondary-text);
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
    font-weight: 500;
    transition: var(--transition);
    display: flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
}
.tab-btn:hover { color: var(--text); background: rgba(47,128,237,0.04); }
.tab-btn.active { background: var(--primary); color: white; }
.tab-btn .badge {
    background: var(--bg);
    color: var(--secondary-text);
    padding: 0 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 600;
    min-width: 18px;
    text-align: center;
}
.tab-btn.active .badge { background: rgba(255,255,255,0.2); color: white; }
.filter-bar { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; align-items: center; }
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
.filter-bar .search-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px rgba(47,128,237,0.1); }
.filter-bar input[type="date"] {
    padding: 10px 14px;
    border: 2px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    background: var(--bg);
    color: var(--text);
    min-width: 150px;
}
.filter-bar .filter-actions { display: flex; gap: 8px; flex-wrap: wrap; }
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
    align-items: flex-start;
    gap: 10px;
    border-left: 4px solid transparent;
}
.alert-success { background: #D1FAE5; color: #065F46; border-left-color: var(--accent); }
.alert-error { background: #FEE2E2; color: #DC2626; border-left-color: #DC2626; }
.alert-warning { background: #FEF3C7; color: #92400E; border-left-color: #F59E0B; }
.alert-info { background: #DBEAFE; color: #1E40AF; border-left-color: #3B82F6; }
.alert ul { margin: 6px 0 0 20px; }
.alert code { background: rgba(0,0,0,0.08); padding: 1px 6px; border-radius: 4px; font-size: 12px; }
.retention-settings {
    background: var(--card);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    padding: 16px 24px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}
.retention-settings .info { font-size: 13px; color: var(--secondary-text); }
.retention-settings .info strong { color: var(--text); }
.table-container {
    background: var(--card);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    overflow: hidden;
    box-shadow: var(--shadow);
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
.table-header h2 { font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
.table-wrapper { overflow-x: auto; -webkit-overflow-scrolling: touch; width: 100%; }
table { width: 100%; border-collapse: collapse; font-size: 14px; min-width: 900px; }
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
}
table th:hover { color: var(--primary); }
table th .sort-icon { margin-left: 4px; opacity: 0.5; }
table th.sorted .sort-icon { opacity: 1; color: var(--primary); }
table th:first-child { text-align: center; width: 40px; }
table td { padding: 12px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
table td:first-child { text-align: center; }
table td:last-child { text-align: center; }
table tbody tr { transition: var(--transition); }
table tbody tr:hover { background: rgba(47,128,237,0.04); }
table tbody tr:last-child td { border-bottom: none; }
.checkbox-cell input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--primary); cursor: pointer; }
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
.status-archived { background: #E5E7EB; color: #374151; }
.module-badge-custom {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}
.role-badge {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 500;
    background: rgba(47,128,237,0.1);
    color: var(--primary);
    min-width: 60px;
    text-align: center;
}
.action-buttons { display: flex; gap: 4px; flex-wrap: wrap; justify-content: center; }
.empty-state { text-align: center; padding: 40px; color: var(--secondary-text); }
.empty-state i { font-size: 40px; display: block; margin-bottom: 10px; opacity: 0.3; }
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
.pagination-controls .page-btn:hover:not(.active):not(:disabled) { background: rgba(47,128,237,0.05); border-color: var(--primary); }
.pagination-controls .page-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
.pagination-controls .page-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.pagination-controls select {
    padding: 6px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--bg);
    color: var(--text);
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
}
.fullscreen-toggle {
    position: fixed;
    bottom: 20px; right: 20px;
    z-index: 50;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 50%;
    width: 48px; height: 48px;
    font-size: 20px;
    color: var(--text);
    cursor: pointer;
    box-shadow: var(--shadow-lg);
    display: flex;
    align-items: center;
    justify-content: center;
}
.fullscreen-toggle:hover { background: var(--primary); color: white; border-color: var(--primary); transform: scale(1.05); }
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 20px;
    backdrop-filter: blur(4px);
}
.modal-overlay.show { display: flex; }
.modal {
    background: var(--card);
    border-radius: var(--radius);
    padding: 30px;
    max-width: 700px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow-lg);
}
.modal h3 { font-size: 20px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
.modal .close-modal {
    margin-left: auto;
    background: none;
    border: none;
    font-size: 24px;
    color: var(--secondary-text);
    cursor: pointer;
    padding: 0 4px;
}
.modal .close-modal:hover { color: var(--text); }
.modal .detail-row {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid var(--border);
    gap: 12px;
}
.modal .detail-row:last-child { border-bottom: none; }
.modal .detail-row .label { font-weight: 500; color: var(--secondary-text); flex: 0 0 40%; }
.modal .detail-row .value { font-weight: 500; flex: 1; word-break: break-word; }
@media (max-width: 1024px) {
    .main-content { padding: 20px 24px 32px; width: calc(100% - 280px); }
    .top-bar { flex-direction: column; align-items: stretch; }
    .top-bar-actions { justify-content: center; }
}
@media (max-width: 768px) {
    .sidebar { width: 0; padding: 0; overflow: hidden; left: -320px; transition: left 0.3s ease; }
    .sidebar.open { left: 0; width: 300px; padding: 24px 16px; }
    .sidebar-toggle-btn { display: block; }
    .main-content { margin-left: 0; padding: 16px; width: 100%; }
    .page-title h1 { font-size: 18px; }
    .page-title p { font-size: 13px; margin-left: 0; }
    .top-bar-actions { width: 100%; flex-wrap: wrap; }
    .top-bar-actions .btn { flex: 1; min-width: 100px; justify-content: center; font-size: 13px; }
    .tabs-container { flex-wrap: nowrap; overflow-x: auto; padding: 4px 8px; }
    .tab-btn { font-size: 12px; padding: 6px 12px; }
    .filter-bar { flex-direction: column; }
    .filter-bar .search-input, .filter-bar input[type="date"] { width: 100%; }
    .table-header { flex-direction: column; align-items: flex-start; }
    table { font-size: 13px; min-width: 500px; }
    table th, table td { padding: 10px 12px; }
    .pagination-bar { flex-direction: column; align-items: stretch; }
    .pagination-controls { justify-content: center; flex-wrap: wrap; }
    .modal { padding: 20px; margin: 10px; }
}
@media (max-width: 480px) {
    .main-content { padding: 12px; }
    .top-bar { padding: 12px; }
    .top-bar-actions { flex-direction: column; }
    .top-bar-actions .btn { width: 100%; }
    .table-wrapper { margin: 0 -12px; }
    table th, table td { padding: 8px 10px; font-size: 12px; }
    .action-buttons { flex-direction: column; }
    .action-buttons .btn-xs { width: 100%; justify-content: center; }
    .modal { padding: 16px; }
    .modal h3 { font-size: 16px; }
}
@media print {
    .sidebar, .top-bar-actions, .btn, .no-print, .fullscreen-toggle,
    .filter-bar, .batch-bar, .tabs-container, .retention-settings { display: none !important; }
    .main-content { margin-left: 0 !important; padding: 20px !important; width: 100% !important; }
    .table-container { box-shadow: none !important; border: 1px solid #ddd !important; }
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
    <?php if (file_exists(__DIR__ . '/partials/sidebar.php')) include 'partials/sidebar.php'; ?>

    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1>Archive Management</h1>
                <p>View, restore, and manage all archived records across all modules</p>
            </div>
            <div class="top-bar-actions">
                <div class="dropdown">
                    <button class="btn btn-outline" onclick="toggleExportDropdown(event)" type="button">
                        <i class="fas fa-download"></i> Export Logs <i class="fas fa-chevron-down" style="font-size:10px;margin-left:4px;"></i>
                    </button>
                    <div class="dropdown-content" id="exportDropdown">
                        <?php foreach ($tables as $table => $info): ?>
                            <?php $can = ($tableStatus[$table]['exists'] ?? false) && ($tableStatus[$table]['has_archived'] ?? false); ?>
                            <a class="<?php echo $can ? '' : 'disabled'; ?>"
                               href="<?php echo $can ? 'archive.php?action=export&table=' . urlencode($table) . '&tab=' . urlencode($tab)
                                    . (!empty($search)   ? '&search='    . urlencode($search) : '')
                                    . (!empty($dateFrom) ? '&date_from=' . urlencode($dateFrom) : '')
                                    . (!empty($dateTo)   ? '&date_to='   . urlencode($dateTo) : '') : '#'; ?>">
                                <i class="fas fa-file-csv"></i> <?php echo htmlspecialchars($info['label']); ?>
                                <?php if (!$can): ?>
                                    <span style="font-size:10px;color:var(--secondary-text);margin-left:auto;">n/a</span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <a href="settings.php#retention" class="btn btn-outline">
                    <i class="fas fa-cog"></i> Retention Settings
                </a>
                <a href="dashboard.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
                <?php include 'partials/headbar_actions.php'; ?>
            </div>
        </div>

        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($tableErrors)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Some tables could not be read:</strong>
                    <ul>
                        <?php foreach ($tableErrors as $t => $err): ?>
                            <li><code><?php echo htmlspecialchars($t); ?></code> — <?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <div style="margin-top:6px;font-size:12px;">
                        Add <code>&amp;debug=1</code> to the URL for full schema diagnostics.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($showDiagnostics): ?>
            <div class="alert alert-info">
                <i class="fas fa-bug"></i>
                <div style="width:100%;">
                    <strong>Schema Diagnostics</strong>
                    <table style="min-width:auto;margin-top:8px;font-size:12px;">
                        <thead>
                            <tr><th>Table</th><th>Exists</th><th>Archive Col</th><th>created_at</th><th>Archived Count</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tables as $t => $info): $s = $tableStatus[$t]; ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($t); ?></td>
                                    <td><?php echo $s['exists'] ? '✅' : '❌'; ?></td>
                                    <td><?php echo $s['archive_column'] ? '<code>' . htmlspecialchars($s['archive_column']) . '</code>' : '❌ missing'; ?></td>
                                    <td><?php echo $s['has_created_at'] ? '✅' : '❌'; ?></td>
                                    <td><?php echo (int)($tabCounts[$t] ?? 0); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Retention Settings -->
        <div class="retention-settings">
            <div class="info">
                <i class="fas fa-clock" style="color: var(--primary); margin-right: 6px;"></i>
                <strong>Retention Policy:</strong> Records archived for more than <strong><?php echo (int)$retentionDays; ?> days</strong> are eligible for auto-purge.
            </div>
            <div class="dropdown" style="margin-left: auto;">
                <button type="button" class="btn btn-outline" onclick="togglePurgeDropdown(event)" style="border-color: #EF4444; color: #DC2626;">
                    <i class="fas fa-trash-alt"></i> Purge Expired Records <i class="fas fa-chevron-down" style="font-size:10px;margin-left:4px;"></i>
                </button>
                <div class="dropdown-content" id="purgeDropdown" style="right: 0; left: auto; min-width: 220px;">
                    <?php foreach ($tables as $table => $info):
                        $can = ($tableStatus[$table]['has_archived'] ?? false) && ($tableStatus[$table]['has_created_at'] ?? false);
                    ?>
                        <?php if ($can): ?>
                            <a href="archive.php?action=purge&table=<?php echo urlencode($table); ?>&days=<?php echo (int)$retentionDays; ?>&tab=<?php echo urlencode($tab); ?>"
                               onclick="return confirm('⚠️ This will permanently delete all archived records from <?php echo htmlspecialchars($info['label'], ENT_QUOTES); ?> older than <?php echo (int)$retentionDays; ?> days. Continue?');">
                                <i class="fas fa-trash" style="color: #DC2626;"></i> Purge <?php echo htmlspecialchars($info['label']); ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs-container">
            <button class="tab-btn <?php echo $tab === 'all' ? 'active' : ''; ?>" onclick="switchTab('all')">
                <i class="fas fa-archive"></i> All
                <span class="badge"><?php echo (int)$grandTotalArchived; ?></span>
            </button>
            <?php foreach ($tables as $table => $info): ?>
                <button class="tab-btn <?php echo $tab === $table ? 'active' : ''; ?>" onclick="switchTab('<?php echo $table; ?>')">
                    <i class="fas <?php echo htmlspecialchars($info['icon']); ?>"></i> <?php echo htmlspecialchars($info['label']); ?>
                    <span class="badge"><?php echo (int)($tabCounts[$table] ?? 0); ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- Search & Filter Bar -->
        <div class="filter-bar">
            <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; width: 100%; align-items: center;">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
                <input type="text" name="search" class="search-input"
                       placeholder="Search across all archived records (ID, name, code, keyword...)"
                       value="<?php echo htmlspecialchars($search); ?>">
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                <input type="date" name="date_to"   value="<?php echo htmlspecialchars($dateTo); ?>">
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
                    <?php if ($search !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
                        <a href="archive.php?tab=<?php echo urlencode($tab); ?>" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Batch Bar -->
        <div class="batch-bar" id="batchBar">
            <span class="selected-info"><strong id="selectedCount">0</strong> records selected</span>
            <button type="button" class="btn btn-success btn-sm" onclick="bulkRestore()">
                <i class="fas fa-undo"></i> Restore Selected
            </button>
            <button type="button" class="btn btn-outline btn-sm" onclick="clearSelection()">
                <i class="fas fa-times"></i> Clear Selection
            </button>
        </div>

        <div class="table-container">
            <div class="table-header">
                <h2>
                    <?php if ($tab !== 'all' && isset($tables[$tab])): ?>
                        <i class="fas <?php echo htmlspecialchars($tables[$tab]['icon']); ?>" style="color: <?php echo htmlspecialchars($tables[$tab]['color']); ?>;"></i>
                        <?php echo htmlspecialchars($tables[$tab]['label']); ?>
                    <?php else: ?>
                        <i class="fas fa-archive" style="color: var(--primary);"></i>
                        All Archived Records
                    <?php endif; ?>
                </h2>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <span class="role-badge"><?php echo (int)$totalItems; ?> records</span>
                    <?php if ($totalItems > 0): ?>
                        <span class="role-badge">Page <?php echo (int)$currentPage; ?> of <?php echo (int)$totalPages; ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="table-wrapper">
                <?php if ($totalItems > 0): ?>
                    <form id="bulkForm" method="POST">
                        <input type="hidden" name="action" value="bulk_restore">
                        <input type="hidden" name="table" id="bulkTable" value="<?php echo htmlspecialchars($currentTable ?: 'users'); ?>">
                        <table>
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="selectAll" onclick="toggleAll(this);"></th>
                                    <th onclick="sortTable('_module')" class="<?php echo $sortField === '_module' ? 'sorted' : ''; ?>">
                                        Module <span class="sort-icon"><?php echo $sortField === '_module' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th onclick="sortTable('id')" class="<?php echo $sortField === 'id' ? 'sorted' : ''; ?>">
                                        Record <span class="sort-icon"><?php echo $sortField === 'id' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th>Archived By</th>
                                    <th onclick="sortTable('created_at')" class="<?php echo $sortField === 'created_at' ? 'sorted' : ''; ?>">
                                        Archived Date <span class="sort-icon"><?php echo $sortField === 'created_at' ? ($sortOrder === 'ASC' ? '▲' : '▼') : '⇅'; ?></span>
                                    </th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($displayData as $record):
                                    $tableName      = $record['_table'] ?? '';
                                    $moduleLabel    = $record['_module'] ?? 'Record';
                                    $icon           = $record['_icon'] ?? 'fa-file';
                                    $color          = $record['_color'] ?? '#6B7280';
                                    $identifier     = $record['_identifier'] ?? 'id';
                                    $identifierValue= $record[$identifier] ?? 'N/A';
                                    $recordName     = getRecordName($record, $tableName, $tables);
                                    $createdBy      = !empty($record['created_by']) ? 'User #' . $record['created_by'] : 'System';
                                    $createdDate    = !empty($record['created_at']) ? date('M d, Y', strtotime($record['created_at'])) : 'N/A';
                                    $tableForAction = $tableName ?: 'users';
                                ?>
                                    <tr>
                                        <td class="checkbox-cell">
                                            <input type="checkbox" name="ids[]" value="<?php echo (int)$record['id']; ?>"
                                                   class="row-checkbox" data-table="<?php echo htmlspecialchars($tableName); ?>"
                                                   onchange="updateSelection();">
                                        </td>
                                        <td>
                                            <span class="module-badge-custom" style="background: <?php echo htmlspecialchars($color); ?>20; color: <?php echo htmlspecialchars($color); ?>;">
                                                <i class="fas <?php echo htmlspecialchars($icon); ?>"></i>
                                                <?php echo htmlspecialchars($moduleLabel); ?>
                                            </span>
                                            <?php if ($identifierValue !== 'N/A'): ?>
                                                <div style="font-size: 10px; color: var(--secondary-text); margin-top: 2px;">
                                                    <?php echo htmlspecialchars(strtoupper(str_replace('_', ' ', $identifier))); ?>:
                                                    <?php echo htmlspecialchars(mb_substr((string)$identifierValue, 0, 30)); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong>#<?php echo (int)$record['id']; ?></strong>
                                            <div style="font-size: 12px; color: var(--secondary-text);">
                                                <?php echo htmlspecialchars(mb_substr($recordName, 0, 40)) . (mb_strlen($recordName) > 40 ? '…' : ''); ?>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($createdBy); ?></td>
                                        <td style="font-size: 13px; color: var(--secondary-text);"><?php echo htmlspecialchars($createdDate); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="archive.php?action=view&table=<?php echo urlencode($tableForAction); ?>&id=<?php echo (int)$record['id']; ?>&tab=<?php echo urlencode($tab); ?>"
                                                   class="btn btn-primary btn-xs" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="archive.php?action=restore&table=<?php echo urlencode($tableForAction); ?>&id=<?php echo (int)$record['id']; ?>&tab=<?php echo urlencode($tab); ?>"
                                                   class="btn btn-success btn-xs"
                                                   onclick="return confirm('Restore this record?');" title="Restore">
                                                    <i class="fas fa-undo"></i>
                                                </a>
                                                <a href="archive.php?action=delete&table=<?php echo urlencode($tableForAction); ?>&id=<?php echo (int)$record['id']; ?>&tab=<?php echo urlencode($tab); ?>"
                                                   class="btn btn-danger btn-xs"
                                                   onclick="return confirm('⚠️ This will permanently delete this record. Continue?');" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </form>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-archive"></i>
                        <p>No archived records found</p>
                        <?php if ($search !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
                            <p style="font-size:12px;margin-top:8px;">
                                Filters are active. <a href="archive.php?tab=<?php echo urlencode($tab); ?>">Clear filters</a>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($totalItems > 0 && $totalPages > 1): ?>
                <div class="pagination-bar">
                    <div class="info">
                        Showing <strong><?php echo $offset + 1; ?></strong>
                        to <strong><?php echo min($offset + $itemsPerPage, $totalItems); ?></strong>
                        of <strong><?php echo $totalItems; ?></strong> records
                    </div>
                    <div class="pagination-controls">
                        <select onchange="changePerPage(this.value);">
                            <?php foreach ([5, 10, 25, 50, 100] as $n): ?>
                                <option value="<?php echo $n; ?>" <?php echo $itemsPerPage == $n ? 'selected' : ''; ?>><?php echo $n; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span style="margin: 0 8px; color: var(--secondary-text);">per page</span>

                        <button class="page-btn" onclick="goToPage(<?php echo $currentPage - 1; ?>)" <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>>
                            <i class="fas fa-chevron-left"></i>
                        </button>

                        <?php
                        $startPage = max(1, $currentPage - 2);
                        $endPage   = min($totalPages, $currentPage + 2);
                        if ($startPage > 1) echo '<button class="page-btn" onclick="goToPage(1);">1</button>' . ($startPage > 2 ? '<span style="padding:0 6px;">…</span>' : '');
                        for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <button class="page-btn <?php echo $i == $currentPage ? 'active' : ''; ?>" onclick="goToPage(<?php echo $i; ?>);">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor;
                        if ($endPage < $totalPages) echo ($endPage < $totalPages - 1 ? '<span style="padding:0 6px;">…</span>' : '') . '<button class="page-btn" onclick="goToPage(' . $totalPages . ');">' . $totalPages . '</button>';
                        ?>

                        <button class="page-btn" onclick="goToPage(<?php echo $currentPage + 1; ?>)" <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>>
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- View Record Modal -->
<?php if ($viewRecord && $viewTable): ?>
    <div class="modal-overlay show" id="viewModal">
        <div class="modal">
            <h3>
                <i class="fas fa-eye" style="color: var(--primary);"></i>
                Record Details
                <button type="button" class="close-modal" onclick="closeViewModal()">&times;</button>
            </h3>
            <div style="margin-bottom: 20px; padding: 15px; background: var(--bg); border-radius: var(--radius-sm);">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                    <span class="module-badge-custom" style="background: <?php echo htmlspecialchars($tables[$viewTable]['color']); ?>20; color: <?php echo htmlspecialchars($tables[$viewTable]['color']); ?>; padding: 4px 14px; font-size: 13px;">
                        <i class="fas <?php echo htmlspecialchars($tables[$viewTable]['icon']); ?>"></i>
                        <?php echo htmlspecialchars($tables[$viewTable]['label']); ?>
                    </span>
                    <span class="status-badge status-archived"><i class="fas fa-archive"></i> Archived</span>
                </div>
                <div style="font-size: 12px; color: var(--secondary-text);">
                    Archived on:
                    <?php echo !empty($viewRecord['created_at']) ? htmlspecialchars(date('M d, Y h:i A', strtotime($viewRecord['created_at']))) : 'N/A'; ?>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <?php
                $excludeFields = ['password', 'is_archived', 'token', 'remember_token'];
                $longFields    = ['description', 'notes', 'address', 'shipping_address', 'remarks', 'comments'];
                foreach ($viewRecord as $key => $value):
                    if (in_array($key, $excludeFields, true)) continue;
                    $label = ucwords(str_replace('_', ' ', $key));
                    $displayValue = is_null($value)
                        ? '<em style="color:var(--secondary-text);">—</em>'
                        : htmlspecialchars((string)$value);
                    $span = in_array($key, $longFields, true) ? 'grid-column: 1 / -1;' : '';
                ?>
                    <div class="detail-row" style="<?php echo $span; ?>">
                        <span class="label"><?php echo htmlspecialchars($label); ?></span>
                        <span class="value"><?php echo $displayValue; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap;">
                <button type="button" class="btn btn-outline" onclick="closeViewModal()">Close</button>
                <a href="archive.php?action=restore&table=<?php echo urlencode($viewTable); ?>&id=<?php echo (int)$viewRecord['id']; ?>&tab=<?php echo urlencode($tab); ?>"
                   class="btn btn-success" onclick="return confirm('Restore this record?');">
                    <i class="fas fa-undo"></i> Restore
                </a>
                <a href="archive.php?action=delete&table=<?php echo urlencode($viewTable); ?>&id=<?php echo (int)$viewRecord['id']; ?>&tab=<?php echo urlencode($tab); ?>"
                   class="btn btn-danger" onclick="return confirm('⚠️ This will permanently delete this record. Continue?');">
                    <i class="fas fa-trash"></i> Delete
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
// ============================================
// TAB SWITCHING
// ============================================
function switchTab(tab) {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    url.searchParams.set('page', 1);
    window.location.href = url.toString();
}

// ============================================
// DROPDOWN
// ============================================
function toggleExportDropdown(e) {
    if (e) e.stopPropagation();
    var p = document.getElementById('purgeDropdown');
    if (p) p.classList.remove('show');
    document.getElementById('exportDropdown').classList.toggle('show');
}
function togglePurgeDropdown(e) {
    if (e) e.stopPropagation();
    var exp = document.getElementById('exportDropdown');
    if (exp) exp.classList.remove('show');
    document.getElementById('purgeDropdown').classList.toggle('show');
}
document.addEventListener('click', function (event) {
    if (!event.target.closest('.dropdown')) {
        document.querySelectorAll('.dropdown-content').forEach(el => el.classList.remove('show'));
    }
});

// ============================================
// SORTING
// ============================================
function sortTable(field) {
    const currentSort  = '<?php echo addslashes($sortField); ?>';
    const currentOrder = '<?php echo addslashes($sortOrder); ?>';
    const newOrder = (currentSort === field && currentOrder === 'ASC') ? 'DESC' : 'ASC';
    const url = new URL(window.location.href);
    url.searchParams.set('sort', field);
    url.searchParams.set('order', newOrder);
    window.location.href = url.toString();
}

// ============================================
// SELECTION / BATCH OPERATIONS
// ============================================
function toggleAll(master) {
    document.querySelectorAll('.row-checkbox').forEach(cb => { cb.checked = master.checked; });
    updateSelection();
}
function updateSelection() {
    const count = document.querySelectorAll('.row-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = count;
    document.getElementById('batchBar').classList.toggle('show', count > 0);
}
function clearSelection() {
    document.querySelectorAll('.row-checkbox').forEach(cb => { cb.checked = false; });
    const sa = document.getElementById('selectAll'); if (sa) sa.checked = false;
    updateSelection();
}

function bulkRestore() {
    const selected = document.querySelectorAll('.row-checkbox:checked');
    if (selected.length === 0) { alert('Please select at least one record.'); return; }

    // Group selected records by their source table — a mixed selection
    // in "All" tab requires one POST per table.
    const grouped = {};
    selected.forEach(cb => {
        const t = cb.dataset.table || 'users';
        (grouped[t] = grouped[t] || []).push(cb.value);
    });

    const tablesToProcess = Object.keys(grouped);
    if (tablesToProcess.length === 1) {
        submitBulkRestore(tablesToProcess[0], grouped[tablesToProcess[0]]);
    } else {
        if (!confirm('Records from ' + tablesToProcess.length + ' different tables are selected. Restore them all?')) return;
        // Sequential POSTs via hidden iframes — simplest reliable approach
        tablesToProcess.forEach((tbl, idx) => {
            setTimeout(() => submitBulkRestore(tbl, grouped[tbl], true), idx * 300);
        });
        setTimeout(() => { window.location.href = 'archive.php?tab=<?php echo urlencode($tab); ?>'; }, tablesToProcess.length * 300 + 500);
    }
}

function submitBulkRestore(table, ids, silent) {
    const form = document.getElementById('bulkForm');
    form.querySelectorAll('input[name="ids[]"]').forEach(el => el.remove());
    document.getElementById('bulkTable').value = table;
    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'ids[]';
        input.value = id;
        form.appendChild(input);
    });
    if (silent) {
        form.target = 'bulkFrame_' + table;
        if (!document.getElementById('bulkFrame_' + table)) {
            const f = document.createElement('iframe');
            f.name = 'bulkFrame_' + table;
            f.id = 'bulkFrame_' + table;
            f.style.display = 'none';
            document.body.appendChild(f);
        }
        form.submit();
    } else {
        form.submit();
    }
}

// ============================================
// PAGINATION
// ============================================
function goToPage(page) {
    const totalPages = <?php echo (int)$totalPages; ?>;
    if (page < 1 || page > totalPages) return;
    const url = new URL(window.location.href);
    url.searchParams.set('page', page);
    window.location.href = url.toString();
}
function changePerPage(value) {
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', value);
    url.searchParams.set('page', 1);
    window.location.href = url.toString();
}

// ============================================
// FULLSCREEN
// ============================================
document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('fullscreenToggle');
    if (!btn) return;
    const icon = btn.querySelector('i');
    btn.addEventListener('click', function () {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen().catch(() => {});
        } else if (document.exitFullscreen) {
            document.exitFullscreen();
        }
    });
    document.addEventListener('fullscreenchange', function () {
        icon.className = document.fullscreenElement ? 'fas fa-compress' : 'fas fa-expand';
    });
});

// ============================================
// MODAL
// ============================================
function closeViewModal() {
    const url = new URL(window.location.href);
    url.searchParams.delete('action');
    url.searchParams.delete('table');
    url.searchParams.delete('id');
    window.location.href = url.toString();
}
document.addEventListener('DOMContentLoaded', function () {
    const overlay = document.getElementById('viewModal');
    if (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeViewModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeViewModal();
        });
    }
});

// ============================================
// SIDEBAR (Mobile)
// ============================================
document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (!sidebar) return;
    const brand = document.querySelector('.sidebar-brand');
    if (brand && !brand.querySelector('.sidebar-toggle-btn')) {
        const btn = document.createElement('button');
        btn.className = 'sidebar-toggle-btn';
        btn.innerHTML = '<i class="fas fa-bars"></i>';
        btn.setAttribute('aria-label', 'Toggle Sidebar');
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            sidebar.classList.toggle('open');
            if (overlay) overlay.classList.toggle('active');
        });
        brand.appendChild(btn);
    }
    if (overlay) {
        overlay.addEventListener('click', function () {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
        });
    }
    window.addEventListener('resize', function () {
        if (window.innerWidth > 768 && sidebar.classList.contains('open')) {
            sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('active');
        }
    });
});
</script>
</body>
</html>