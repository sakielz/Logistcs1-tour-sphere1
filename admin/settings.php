<?php
// admin/settings.php
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

// Define color constants if not already defined
if (!defined('COLOR_PRIMARY')) define('COLOR_PRIMARY', '#2F80ED');
if (!defined('COLOR_SECONDARY')) define('COLOR_SECONDARY', '#56CCF2');
if (!defined('COLOR_ACCENT')) define('COLOR_ACCENT', '#27AE60');
if (!defined('COLOR_BG')) define('COLOR_BG', '#F8FAFC');
if (!defined('COLOR_CARD')) define('COLOR_CARD', '#FFFFFF');
if (!defined('COLOR_TEXT')) define('COLOR_TEXT', '#1F2937');
if (!defined('COLOR_SECONDARY_TEXT')) define('COLOR_SECONDARY_TEXT', '#6B7280');
if (!defined('COLOR_BORDER')) define('COLOR_BORDER', '#EEF2F7');

// ============================================
// DATABASE BACKUP ACTION
// ============================================
$action = isset($_GET['action']) ? $_GET['action'] : 'update';

if ($action === 'backup_db') {
    requireAuth('admin');
    try {
        $rootDir = dirname(__DIR__);
        $sqlitePath = $rootDir . '/database/database.sqlite';
        $timestamp = date('Y-m-d_His');
        $backupFilename = 'logistics_backup_' . $timestamp . '.sqlite';
        $backupDir = $rootDir . '/database/backups';

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0777, true);
        }

        if (is_file($sqlitePath)) {
            $destination = $backupDir . '/' . $backupFilename;
            copy($sqlitePath, $destination);

            if (function_exists('logAudit')) {
                logAudit($_SESSION['user_id'], 'backup_database', 'system', "Created database backup: $backupFilename");
            }

            header('Content-Description: File Transfer');
            header('Content-Type: application/x-sqlite3');
            header('Content-Disposition: attachment; filename="' . $backupFilename . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($destination));
            readfile($destination);
            exit();
        } else {
            $_SESSION['error'] = 'Database file not found at: ' . $sqlitePath;
            header('Location: settings.php');
            exit();
        }
    } catch (Exception $e) {
        $_SESSION['error'] = 'Database backup failed: ' . $e->getMessage();
        header('Location: settings.php');
        exit();
    }
}

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = isset($_POST['settings']) ? (array)$_POST['settings'] : [];
    
    if ($action === 'update') {
        try {
            $pdo->beginTransaction();

            // Handle file upload for company logo
            if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['company_logo'];
                $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'];
                
                if (in_array($extension, $allowedExtensions, true) && $file['size'] <= 5 * 1024 * 1024) {
                    $uploadDir = __DIR__ . '/../uploads/logo/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $filename = 'company_logo_' . time() . '.' . $extension;
                    $targetPath = $uploadDir . $filename;
                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                        $settings['company_logo'] = '../uploads/logo/' . $filename;
                    }
                }
            }

            $driverName = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

            foreach ($settings as $key => $value) {
                if ($driverName === 'sqlite') {
                    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'general') ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value");
                    $stmt->execute([$key, (string)$value]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'general') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    $stmt->execute([$key, (string)$value]);
                }
            }
            
            $pdo->commit();
            
            if (function_exists('logAudit')) {
                logAudit($_SESSION['user_id'], 'update_settings', 'system', 'Updated system settings');
            }
            $_SESSION['success'] = "Settings and company profile updated successfully!";
            header('Location: settings.php');
            exit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Error updating settings: " . $e->getMessage();
        }
    }
}

// Get all settings
try {
    $stmt = $pdo->query("SELECT * FROM system_settings ORDER BY setting_group, setting_key");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row;
    }
} catch (PDOException $e) {
    $settings = [];
}

// Get module visibility settings
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'show_%'");
    $visibility = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $visibility[$row['setting_key']] = $row['setting_value'] === 'true';
    }
} catch (PDOException $e) {
    $visibility = [];
}

// Get notification settings
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'notify_%'");
    $notifications = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $notifications[$row['setting_key']] = $row['setting_value'] === 'true';
    }
} catch (PDOException $e) {
    $notifications = [];
}

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
    <title>System Settings - GlobalSCM</title>
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
            padding-bottom: 100px;
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
        
        /* ===== SETTINGS GRID ===== */
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 24px;
            margin-bottom: 20px;
        }
        
        .settings-card {
            background: var(--card);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 24px;
            box-shadow: var(--shadow);
            transition: var(--transition);
        }
        
        .settings-card:hover {
            box-shadow: var(--shadow-lg);
        }
        
        .settings-card .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }
        
        .settings-card .card-header h3 {
            font-size: 16px;
            font-weight: 600;
        }
        
        .settings-card .card-header .card-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(47, 128, 237, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 16px;
            flex-shrink: 0;
        }
        
        /* ===== SETTING ITEMS ===== */
        .setting-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 0;
            border-bottom: 1px solid var(--border);
            gap: 16px;
            min-height: 60px;
        }
        
        .setting-item:last-child {
            border-bottom: none;
        }
        
        .setting-item .info {
            flex: 1;
            min-width: 0;
            padding-right: 12px;
        }
        
        .setting-item .info .label {
            font-size: 14px;
            font-weight: 500;
            color: var(--text);
        }
        
        .setting-item .info .desc {
            font-size: 12px;
            color: var(--secondary-text);
            margin-top: 2px;
        }
        
        .setting-item .control {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: flex-end;
        }
        
        .setting-item input[type="text"],
        .setting-item input[type="number"],
        .setting-item input[type="date"],
        .setting-item input[type="file"],
        .setting-item select {
            padding: 8px 14px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            background: var(--bg);
            color: var(--text);
            width: 160px;
            transition: var(--transition);
        }
        
        .setting-item input[type="text"]:focus,
        .setting-item input[type="number"]:focus,
        .setting-item select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(47, 128, 237, 0.1);
        }
        
        .setting-item input[type="file"] {
            padding: 6px 10px;
            width: 120px;
        }
        
        /* ===== TOGGLE SWITCH ===== */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 52px;
            height: 28px;
            background: #CBD5E1;
            border: 1px solid #94A3B8;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            flex-shrink: 0;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .toggle-switch.active {
            background: var(--primary);
            border-color: var(--primary);
        }
        
        .toggle-switch::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 22px;
            height: 22px;
            background: white;
            border-radius: 50%;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 2px 5px rgba(0,0,0,0.25);
        }
        
        .toggle-switch.active::after {
            transform: translateX(24px);
        }
        
        .toggle-input {
            display: none;
        }
        
        /* ===== STICKY FOOTER ===== */
        .sticky-footer {
            position: fixed;
            bottom: 0;
            left: 280px;
            right: 0;
            background: var(--card);
            border-top: 2px solid var(--border);
            padding: 16px 32px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            z-index: 50;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.05);
        }
        
        .sticky-footer .btn {
            min-width: 120px;
        }
        
        /* ===== LOGO PREVIEW ===== */
        .logo-preview {
            width: 60px;
            height: 60px;
            border-radius: var(--radius-sm);
            border: 2px dashed var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: var(--bg);
            flex-shrink: 0;
        }
        
        .logo-preview img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        
        .logo-preview .placeholder {
            color: var(--secondary-text);
            font-size: 12px;
            text-align: center;
        }
        
        /* ===== FULLSCREEN TOGGLE ===== */
        .fullscreen-toggle {
            position: fixed;
            bottom: 80px;
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
            .settings-grid {
                grid-template-columns: 1fr;
            }
            
            .sticky-footer {
                left: 280px;
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
                padding-bottom: 100px;
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
            
            .settings-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            
            .settings-card {
                padding: 16px;
            }
            
            .setting-item {
                flex-direction: row;
                align-items: center;
                gap: 10px;
            }
            
            .setting-item .control {
                width: auto;
            }
            
            .setting-item input[type="text"],
            .setting-item input[type="number"],
            .setting-item select,
            .setting-item input[type="file"] {
                width: 100%;
            }
            
            .sticky-footer {
                left: 0;
                padding: 12px 16px;
                flex-direction: column;
            }
            
            .sticky-footer .btn {
                width: 100%;
                justify-content: center;
            }
            
            .fullscreen-toggle {
                bottom: 76px;
                right: 16px;
                width: 44px;
                height: 44px;
                font-size: 18px;
            }
            
            .logo-preview {
                width: 50px;
                height: 50px;
            }
        }
        
        @media (max-width: 480px) {
            .main-content {
                padding: 12px;
                padding-bottom: 100px;
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
            
            .settings-card {
                padding: 12px;
            }
            
            .setting-item .info .label {
                font-size: 13px;
            }
            
            .sticky-footer {
                padding: 10px 12px;
            }
            
            .fullscreen-toggle {
                bottom: 70px;
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
            .fullscreen-toggle,
            .sticky-footer {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0 !important;
                padding: 20px !important;
                width: 100% !important;
            }
            
            .settings-card {
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
                    <h1>System Settings</h1>
                    <p>Configure system-wide settings and preferences</p>
                </div>
                <div class="top-bar-actions">
                    <a href="dashboard.php" class="btn btn-back">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <a href="settings.php?action=audit" class="btn btn-outline">
                        <i class="fas fa-history"></i> Audit Logs
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
            
            <form method="POST" enctype="multipart/form-data">
                <div class="settings-grid">
                    
                    <!-- ===== GENERAL SETTINGS ===== -->
                    <div class="settings-card">
                        <div class="card-header">
                            <div class="card-icon"><i class="fas fa-globe"></i></div>
                            <h3>General Settings</h3>
                        </div>
                        
                        <div class="setting-item">
                            <div class="info">
                                <div class="label">Currency Symbol</div>
                                <div class="desc">Displayed throughout the system</div>
                            </div>
                            <div class="control">
                                <input type="text" name="settings[currency_symbol]" 
                                       value="<?php echo htmlspecialchars($settings['currency_symbol']['setting_value'] ?? '₱'); ?>"
                                       style="width: 60px; text-align: center;">
                            </div>
                        </div>
                        
                        <div class="setting-item">
                            <div class="info">
                                <div class="label">Currency Code</div>
                                <div class="desc">ISO currency code</div>
                            </div>
                            <div class="control">
                                <input type="text" name="settings[currency_code]" 
                                       value="<?php echo htmlspecialchars($settings['currency_code']['setting_value'] ?? 'PHP'); ?>"
                                       style="width: 80px; text-transform: uppercase;">
                            </div>
                        </div>
                        
                        <div class="setting-item">
                            <div class="info">
                                <div class="label">Barcode Format</div>
                                <div class="desc">Default barcode format for products</div>
                            </div>
                            <div class="control">
                                <select name="settings[barcode_format]">
                                    <option value="CODE128" <?php echo ($settings['barcode_format']['setting_value'] ?? '') === 'CODE128' ? 'selected' : ''; ?>>CODE128</option>
                                    <option value="CODE39" <?php echo ($settings['barcode_format']['setting_value'] ?? '') === 'CODE39' ? 'selected' : ''; ?>>CODE39</option>
                                    <option value="EAN13" <?php echo ($settings['barcode_format']['setting_value'] ?? '') === 'EAN13' ? 'selected' : ''; ?>>EAN-13</option>
                                    <option value="UPC" <?php echo ($settings['barcode_format']['setting_value'] ?? '') === 'UPC' ? 'selected' : ''; ?>>UPC</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="setting-item">
                            <div class="info">
                                <div class="label">Date Format</div>
                                <div class="desc">Display format for dates</div>
                            </div>
                            <div class="control">
                                <select name="settings[date_format]">
                                    <option value="Y-m-d" <?php echo ($settings['date_format']['setting_value'] ?? '') === 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                                    <option value="d/m/Y" <?php echo ($settings['date_format']['setting_value'] ?? '') === 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY</option>
                                    <option value="m/d/Y" <?php echo ($settings['date_format']['setting_value'] ?? '') === 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY</option>
                                    <option value="d M, Y" <?php echo ($settings['date_format']['setting_value'] ?? '') === 'd M, Y' ? 'selected' : ''; ?>>DD Mon, YYYY</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="setting-item">
                            <div class="info">
                                <div class="label">Time Zone</div>
                                <div class="desc">System time zone</div>
                            </div>
                            <div class="control">
                                <select name="settings[timezone]">
                                    <option value="Asia/Manila" <?php echo ($settings['timezone']['setting_value'] ?? '') === 'Asia/Manila' ? 'selected' : ''; ?>>Asia/Manila (GMT+8)</option>
                                    <option value="UTC" <?php echo ($settings['timezone']['setting_value'] ?? '') === 'UTC' ? 'selected' : ''; ?>>UTC</option>
                                    <option value="America/New_York" <?php echo ($settings['timezone']['setting_value'] ?? '') === 'America/New_York' ? 'selected' : ''; ?>>America/New_York</option>
                                    <option value="Europe/London" <?php echo ($settings['timezone']['setting_value'] ?? '') === 'Europe/London' ? 'selected' : ''; ?>>Europe/London</option>
                                    <option value="Asia/Singapore" <?php echo ($settings['timezone']['setting_value'] ?? '') === 'Asia/Singapore' ? 'selected' : ''; ?>>Asia/Singapore</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="setting-item">
                            <div class="info">
                                <div class="label">Theme</div>
                                <div class="desc">System theme preference</div>
                            </div>
                            <div class="control">
                                <select name="settings[theme]">
                                    <option value="light" <?php echo ($settings['theme']['setting_value'] ?? '') === 'light' ? 'selected' : ''; ?>>Light</option>
                                    <option value="dark" <?php echo ($settings['theme']['setting_value'] ?? '') === 'dark' ? 'selected' : ''; ?>>Dark</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ===== COMPANY PROFILE OVERVIEW & DETAILED INFO ===== -->
                    <div class="settings-card" style="grid-column: 1 / -1;">
                        <div class="card-header" style="justify-content: space-between;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div class="card-icon" style="background: rgba(47, 128, 237, 0.15); color: var(--primary);">
                                    <i class="fas fa-building"></i>
                                </div>
                                <div>
                                    <h3 style="margin: 0; font-size: 16px;">Company Profile & Detailed Information</h3>
                                    <p style="margin: 0; font-size: 12px; color: var(--secondary-text);">Organization identity and official credentials used across purchase orders, reports, and invoices</p>
                                </div>
                            </div>
                            <span class="role-badge" style="background: rgba(39, 174, 96, 0.15); color: var(--accent); font-size: 11px;">
                                <i class="fas fa-check-circle"></i> Verified Entity
                            </span>
                        </div>
                        
                        <!-- Detailed Info Overview Bar -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; padding: 18px; background: var(--bg); border-radius: var(--radius-sm); border: 1px solid var(--border); margin-bottom: 24px;">
                            <div style="display: flex; gap: 14px; align-items: center;">
                                <div style="width: 56px; height: 56px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--card); display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0;">
                                    <?php if (!empty($settings['company_logo']['setting_value'])): ?>
                                        <img src="<?php echo htmlspecialchars($settings['company_logo']['setting_value']); ?>" alt="Company Logo" style="width: 100%; height: 100%; object-fit: contain;">
                                    <?php else: ?>
                                        <i class="fas fa-building" style="font-size: 24px; color: var(--primary);"></i>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div style="font-size: 11px; text-transform: uppercase; color: var(--secondary-text); font-weight: 600;">Organization</div>
                                    <strong style="font-size: 14px; color: var(--text);"><?php echo htmlspecialchars($settings['company_name']['setting_value'] ?? 'GlobalSCM Inc.'); ?></strong>
                                    <div style="font-size: 11px; color: var(--secondary-text);"><?php echo htmlspecialchars($settings['company_industry']['setting_value'] ?? 'Tours & Supply Chain Logistics'); ?></div>
                                </div>
                            </div>
                            <div>
                                <div style="font-size: 11px; text-transform: uppercase; color: var(--secondary-text); font-weight: 600;">Tax & Registration</div>
                                <div style="font-size: 13px; color: var(--text); font-weight: 500;">TIN: <?php echo htmlspecialchars($settings['tax_id']['setting_value'] ?? 'TIN-009-876-543-000'); ?></div>
                                <div style="font-size: 11px; color: var(--secondary-text);">Reg: <?php echo htmlspecialchars($settings['company_registration_no']['setting_value'] ?? 'SEC-CS2026-88712'); ?></div>
                            </div>
                            <div>
                                <div style="font-size: 11px; text-transform: uppercase; color: var(--secondary-text); font-weight: 600;">Official Contacts</div>
                                <div style="font-size: 13px; color: var(--text); font-weight: 500;"><i class="fas fa-envelope" style="color: var(--primary); font-size: 11px;"></i> <?php echo htmlspecialchars($settings['company_email']['setting_value'] ?? 'admin@toursphere.com'); ?></div>
                                <div style="font-size: 11px; color: var(--secondary-text);"><i class="fas fa-phone" style="color: var(--accent); font-size: 10px;"></i> <?php echo htmlspecialchars($settings['company_phone']['setting_value'] ?? '+63 (02) 8888-7777'); ?></div>
                            </div>
                            <div>
                                <div style="font-size: 11px; text-transform: uppercase; color: var(--secondary-text); font-weight: 600;">Address & Portal</div>
                                <div style="font-size: 12px; color: var(--text);"><?php echo htmlspecialchars($settings['company_address']['setting_value'] ?? 'Corporate Tower, Bonifacio Global City, Taguig'); ?></div>
                                <div style="font-size: 11px; color: var(--primary);"><i class="fas fa-globe"></i> <?php echo htmlspecialchars($settings['company_website']['setting_value'] ?? 'https://toursphere.com'); ?></div>
                            </div>
                        </div>

                        <!-- Editable Fields Grid -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 20px;">
                            
                            <!-- Left Column: Identity & Logo -->
                            <div>
                                <!-- Clickable Company Logo UI -->
                                <div class="setting-item" style="padding-top: 0;">
                                    <div class="info">
                                        <div class="label"><i class="fas fa-image" style="color: var(--primary);"></i> Company Logo</div>
                                        <div class="desc">Click the logo box or icon to browse a new image (PNG, JPG, SVG up to 5MB)</div>
                                    </div>
                                    <div class="control">
                                        <div style="display: flex; align-items: center; gap: 14px;">
                                            <div id="companyLogoBox" 
                                                 onclick="document.getElementById('companyLogoFileInput').click();"
                                                 title="Click to choose new company logo"
                                                 style="position: relative; width: 72px; height: 72px; border-radius: var(--radius-sm); border: 2px dashed var(--primary); background: var(--bg); display: flex; align-items: center; justify-content: center; cursor: pointer; overflow: hidden; transition: all 0.2s ease;">
                                                <?php if (!empty($settings['company_logo']['setting_value'])): ?>
                                                    <img id="logoPreviewImg" src="<?php echo htmlspecialchars($settings['company_logo']['setting_value']); ?>" alt="Company Logo" style="width: 100%; height: 100%; object-fit: contain;">
                                                    <div id="logoPlaceholderIcon" style="display: none; text-align: center; color: var(--primary);">
                                                        <i class="fas fa-cloud-upload-alt" style="font-size: 24px;"></i>
                                                        <span style="display: block; font-size: 9px; font-weight: 600; text-transform: uppercase;">Upload</span>
                                                    </div>
                                                <?php else: ?>
                                                    <img id="logoPreviewImg" src="" alt="Company Logo" style="display: none; width: 100%; height: 100%; object-fit: contain;">
                                                    <div id="logoPlaceholderIcon" style="text-align: center; color: var(--primary);">
                                                        <i class="fas fa-cloud-upload-alt" style="font-size: 24px;"></i>
                                                        <span style="display: block; font-size: 9px; font-weight: 600; text-transform: uppercase;">Upload</span>
                                                    </div>
                                                <?php endif; ?>
                                                <div style="position: absolute; inset: 0; background: rgba(0,0,0,0.45); display: flex; flex-direction: column; align-items: center; justify-content: center; color: white; opacity: 0; transition: opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0'">
                                                    <i class="fas fa-camera" style="font-size: 16px;"></i>
                                                    <span style="font-size: 9px;">Change</span>
                                                </div>
                                            </div>
                                            <input type="file" name="company_logo" id="companyLogoFileInput" accept="image/*" style="display: none;" onchange="previewSelectedLogo(this);">
                                            <div>
                                                <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('companyLogoFileInput').click();" style="display: inline-flex; align-items: center; gap: 6px;">
                                                    <i class="fas fa-upload"></i> Upload Logo
                                                </button>
                                                <div id="selectedLogoName" style="font-size: 11px; color: var(--secondary-text); margin-top: 4px;">Directly clickable</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="setting-item">
                                    <div class="info">
                                        <div class="label">Company Display Name *</div>
                                        <div class="desc">Public trade name used across navigation</div>
                                    </div>
                                    <div class="control">
                                        <input type="text" name="settings[company_name]" 
                                               value="<?php echo htmlspecialchars($settings['company_name']['setting_value'] ?? 'GlobalSCM Inc.'); ?>"
                                               style="width: 220px;" required>
                                    </div>
                                </div>

                                <div class="setting-item">
                                    <div class="info">
                                        <div class="label">Registered Legal Name</div>
                                        <div class="desc">Official registered entity name for legal contracts</div>
                                    </div>
                                    <div class="control">
                                        <input type="text" name="settings[company_legal_name]" 
                                               value="<?php echo htmlspecialchars($settings['company_legal_name']['setting_value'] ?? 'Toursphere Travel & Tours Logistics Corporation'); ?>"
                                               style="width: 220px;">
                                    </div>
                                </div>

                                <div class="setting-item">
                                    <div class="info">
                                        <div class="label">Industry / Business Nature</div>
                                        <div class="desc">Core sector for supply chain categorization</div>
                                    </div>
                                    <div class="control">
                                        <input type="text" name="settings[company_industry]" 
                                               value="<?php echo htmlspecialchars($settings['company_industry']['setting_value'] ?? 'Travel & Tourism Logistics'); ?>"
                                               style="width: 220px;">
                                    </div>
                                </div>
                            </div>

                            <!-- Right Column: Official Details & Contact -->
                            <div>
                                <div class="setting-item" style="padding-top: 0;">
                                    <div class="info">
                                        <div class="label">Tax ID / VAT / TIN</div>
                                        <div class="desc">Tax identification number for purchase orders</div>
                                    </div>
                                    <div class="control">
                                        <input type="text" name="settings[tax_id]" 
                                               value="<?php echo htmlspecialchars($settings['tax_id']['setting_value'] ?? 'TIN-009-876-543-000'); ?>"
                                               style="width: 220px;">
                                    </div>
                                </div>

                                <div class="setting-item">
                                    <div class="info">
                                        <div class="label">SEC / DTI Registration No.</div>
                                        <div class="desc">Official registration license identifier</div>
                                    </div>
                                    <div class="control">
                                        <input type="text" name="settings[company_registration_no]" 
                                               value="<?php echo htmlspecialchars($settings['company_registration_no']['setting_value'] ?? 'SEC-CS2026-88712'); ?>"
                                               style="width: 220px;">
                                    </div>
                                </div>

                                <div class="setting-item">
                                    <div class="info">
                                        <div class="label">Official Email Address</div>
                                        <div class="desc">Procurement communication address</div>
                                    </div>
                                    <div class="control">
                                        <input type="email" name="settings[company_email]" 
                                               value="<?php echo htmlspecialchars($settings['company_email']['setting_value'] ?? 'admin@toursphere.com'); ?>"
                                               style="width: 220px;">
                                    </div>
                                </div>

                                <div class="setting-item">
                                    <div class="info">
                                        <div class="label">Official Phone / Hotline</div>
                                        <div class="desc">Support & carrier contact line</div>
                                    </div>
                                    <div class="control">
                                        <input type="text" name="settings[company_phone]" 
                                               value="<?php echo htmlspecialchars($settings['company_phone']['setting_value'] ?? '+63 (02) 8888-7777'); ?>"
                                               style="width: 220px;">
                                    </div>
                                </div>

                                <div class="setting-item">
                                    <div class="info">
                                        <div class="label">Official Website URL</div>
                                        <div class="desc">Public website and client portal URL</div>
                                    </div>
                                    <div class="control">
                                        <input type="text" name="settings[company_website]" 
                                               value="<?php echo htmlspecialchars($settings['company_website']['setting_value'] ?? 'https://toursphere.com'); ?>"
                                               style="width: 220px;">
                                    </div>
                                </div>

                                <div class="setting-item">
                                    <div class="info">
                                        <div class="label">Registered Physical Address</div>
                                        <div class="desc">Headquarters address printed on documents</div>
                                    </div>
                                    <div class="control">
                                        <input type="text" name="settings[company_address]" 
                                               value="<?php echo htmlspecialchars($settings['company_address']['setting_value'] ?? 'Corporate Tower, Bonifacio Global City, Taguig'); ?>"
                                               style="width: 220px;">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===== DATABASE BACKUP & MAINTENANCE CARD ===== -->
                    <div class="settings-card" style="border-color: #10B981;">
                        <div class="card-header">
                            <div class="card-icon" style="background: rgba(16, 185, 129, 0.15); color: #10B981;">
                                <i class="fas fa-database"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0;">Database Backup & Maintenance</h3>
                                <p style="margin: 0; font-size: 12px; color: var(--secondary-text);">Securely download or archive your operational database snapshot</p>
                            </div>
                        </div>

                        <?php
                        $dbFileSize = 'N/A';
                        $dbFilePath = dirname(__DIR__) . '/database/database.sqlite';
                        if (is_file($dbFilePath)) {
                            $bytes = filesize($dbFilePath);
                            $dbFileSize = round($bytes / 1024, 2) . ' KB (' . number_format($bytes) . ' bytes)';
                        }
                        ?>

                        <div class="setting-item">
                            <div class="info">
                                <div class="label">Database Engine</div>
                                <div class="desc">Active database connection driver</div>
                            </div>
                            <div class="control">
                                <span class="role-badge" style="background: rgba(16, 185, 129, 0.15); color: #10B981; font-weight: 600;">
                                    <i class="fas fa-server"></i> SQLite WAL Mode
                                </span>
                            </div>
                        </div>

                        <div class="setting-item">
                            <div class="info">
                                <div class="label">Database Storage Size</div>
                                <div class="desc">Current operational data footprint</div>
                            </div>
                            <div class="control">
                                <strong style="font-size: 13px; color: var(--text);"><?php echo $dbFileSize; ?></strong>
                            </div>
                        </div>

                        <div class="setting-item" style="border-bottom: none; padding-top: 18px;">
                            <div class="info">
                                <div class="label">Download Database Backup</div>
                                <div class="desc">Generates a complete standalone timestamped .sqlite database archive</div>
                            </div>
                            <div class="control">
                                <a href="settings.php?action=backup_db" class="btn btn-success" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600; padding: 10px 18px;">
                                    <i class="fas fa-download"></i> Download Database Backup
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ===== MODULE VISIBILITY ===== -->
                    <div class="settings-card">
                        <div class="card-header">
                            <div class="card-icon"><i class="fas fa-eye"></i></div>
                            <h3>Module Visibility</h3>
                        </div>
                        
                        <?php
                        $modules = [
                            'show_warehousing' => 'Smart Warehousing',
                            'show_inventory' => 'Inventory Management',
                            'show_procurement' => 'Procurement & Sourcing',
                            'show_suppliers' => 'Supplier Management',
                            'show_purchase_orders' => 'Purchase Orders',
                            'show_logistics' => 'Logistics & Documents'
                        ];
                        foreach ($modules as $key => $label):
                            $value = isset($visibility[$key]) ? $visibility[$key] : true;
                        ?>
                        <div class="setting-item">
                            <div class="info">
                                <div class="label"><?php echo $label; ?></div>
                                <div class="desc">Show/hide <?php echo strtolower($label); ?> module</div>
                            </div>
                            <div class="control">
                                <label class="toggle-switch <?php echo $value ? 'active' : ''; ?>">
                                    <input type="hidden" name="settings[<?php echo $key; ?>]" value="false">
                                    <input type="checkbox" name="settings[<?php echo $key; ?>]" value="true" 
                                           class="toggle-input" <?php echo $value ? 'checked' : ''; ?>>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- ===== AI & AUTOMATION ===== -->
                    <div class="settings-card">
                        <div class="card-header">
                            <div class="card-icon"><i class="fas fa-robot"></i></div>
                            <h3>AI & Automation</h3>
                        </div>
                        
                        <div class="setting-item">
                            <div class="info">
                                <div class="label">AI Report Generator</div>
                                <div class="desc">Enable AI-powered daily reports</div>
                            </div>
                            <div class="control">
                                <?php $aiEnabled = ($settings['report_ai_enabled']['setting_value'] ?? 'true') === 'true'; ?>
                                <label class="toggle-switch <?php echo $aiEnabled ? 'active' : ''; ?>">
                                    <input type="hidden" name="settings[report_ai_enabled]" value="false">
                                    <input type="checkbox" name="settings[report_ai_enabled]" value="true" 
                                           class="toggle-input" <?php echo $aiEnabled ? 'checked' : ''; ?>>
                                </label>
                            </div>
                        </div>
                        
                        <div class="setting-item">
                            <div class="info">
                                <div class="label">Auto Serial Number</div>
                                <div class="desc">Automatically generate serial numbers</div>
                            </div>
                            <div class="control">
                                <?php $autoSerial = ($settings['auto_serial_number']['setting_value'] ?? 'true') === 'true'; ?>
                                <label class="toggle-switch <?php echo $autoSerial ? 'active' : ''; ?>">
                                    <input type="hidden" name="settings[auto_serial_number]" value="false">
                                    <input type="checkbox" name="settings[auto_serial_number]" value="true" 
                                           class="toggle-input" <?php echo $autoSerial ? 'checked' : ''; ?>>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ===== NOTIFICATION PREFERENCES ===== -->
                    <div class="settings-card">
                        <div class="card-header">
                            <div class="card-icon"><i class="fas fa-bell"></i></div>
                            <h3>Notification Preferences</h3>
                        </div>
                        
                        <?php
                        $notificationsConfig = [
                            'notify_low_stock' => 'Low Stock Alerts',
                            'notify_pending_pr' => 'Pending Purchase Requisitions',
                            'notify_contract_expiry' => 'Contract Expirations',
                            'notify_po_approval' => 'PO Approval Requests',
                            'notify_shipment_updates' => 'Shipment Status Updates'
                        ];
                        foreach ($notificationsConfig as $key => $label):
                            $value = isset($notifications[$key]) ? $notifications[$key] : true;
                        ?>
                        <div class="setting-item">
                            <div class="info">
                                <div class="label"><?php echo $label; ?></div>
                                <div class="desc">Email and in-app notifications</div>
                            </div>
                            <div class="control">
                                <label class="toggle-switch <?php echo $value ? 'active' : ''; ?>">
                                    <input type="hidden" name="settings[<?php echo $key; ?>]" value="false">
                                    <input type="checkbox" name="settings[<?php echo $key; ?>]" value="true" 
                                           class="toggle-input" <?php echo $value ? 'checked' : ''; ?>>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- ===== RETENTION SETTINGS ===== -->
                    <div class="settings-card">
                        <div class="card-header">
                            <div class="card-icon"><i class="fas fa-clock"></i></div>
                            <h3>Data Retention</h3>
                        </div>
                        
                        <div class="setting-item">
                            <div class="info">
                                <div class="label">Archive Retention Days</div>
                                <div class="desc">Auto-purge items archived for more than X days</div>
                            </div>
                            <div class="control">
                                <input type="number" name="settings[archive_retention_days]" 
                                       value="<?php echo htmlspecialchars($settings['archive_retention_days']['setting_value'] ?? '90'); ?>"
                                       style="width: 80px;" min="1" max="365">
                                <span style="font-size: 13px; color: var(--secondary-text); margin-left: 6px;">days</span>
                            </div>
                        </div>
                        
                        <div class="setting-item">
                            <div class="info">
                                <div class="label">Audit Log Retention</div>
                                <div class="desc">Auto-purge audit logs older than X days</div>
                            </div>
                            <div class="control">
                                <input type="number" name="settings[audit_retention_days]" 
                                       value="<?php echo htmlspecialchars($settings['audit_retention_days']['setting_value'] ?? '365'); ?>"
                                       style="width: 80px;" min="1" max="730">
                                <span style="font-size: 13px; color: var(--secondary-text); margin-left: 6px;">days</span>
                            </div>
                        </div>
                    </div>
                    
                </div>
                
                <!-- Sticky Footer -->
                <div class="sticky-footer no-print">
                    <button type="reset" class="btn btn-outline">Reset Changes</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                </div>
            </form>
        </main>
    </div>
    
    <script>
        // ============================================
        // TOGGLE SWITCH HANDLING
        // ============================================
        document.querySelectorAll('.toggle-switch').forEach(function(toggle) {
            toggle.addEventListener('click', function(e) {
                var checkbox = this.querySelector('.toggle-input');
                if (checkbox) {
                    checkbox.checked = !checkbox.checked;
                    this.classList.toggle('active', checkbox.checked);
                    
                    // Update hidden input
                    var hidden = this.querySelector('input[type="hidden"]');
                    if (hidden) {
                        hidden.value = checkbox.checked ? 'true' : 'false';
                    }
                }
            });
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