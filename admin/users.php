<?php
// admin/users.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth(['admin', 'super_admin']);

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

// Handle actions
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// ============================================
// VALID ROLES - single source of truth used for both
// server-side validation and the dropdown below, so the
// two can never drift out of sync with each other.
// NOTE: these string values must exactly match whatever
// your `users.role` column accepts (its ENUM/CHECK values
// if you have one). If a role still fails to save after
// this fix, the value below almost certainly doesn't match
// what the database itself allows - check that column's
// definition in Supabase.
// ============================================
$VALID_ROLES = [
    'super_admin' => 'Super Admin',
    'admin' => 'Admin',
    'procurement_officer' => 'Procurement Officer (Can Input Suppliers)',
    'warehouse_manager' => 'Warehouse Manager',
    'inventory_clerk' => 'Inventory Clerk',
    'supplier' => 'Supplier',
];

// Create User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $role = isset($_POST['role']) ? $_POST['role'] : 'inventory_clerk';
    if (!array_key_exists($role, $VALID_ROLES)) {
        $role = 'inventory_clerk';
    }
    
    $errors = [];
    
    if (empty($username)) $errors[] = 'Username is required';
    if (empty($email)) $errors[] = 'Email is required';
    if (empty($full_name)) $errors[] = 'Full name is required';

    // Strong Password Validation: min 8 chars, uppercase, lowercase, number, special character
    if (empty($password)) {
        $errors[] = 'Password is required';
    } else {
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter (A-Z)';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter (a-z)';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number (0-9)';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character (!@#$%^&*()-_+= etc.)';
        }
    }
    
    if (empty($errors)) {
        try {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, full_name, role, is_active, is_archived) VALUES (?, ?, ?, ?, ?, TRUE, FALSE)");
            $stmt->execute([$username, $email, $hashedPassword, $full_name, $role]);
            logAudit($_SESSION['user_id'], 'create_user', 'user_management', "Created user: $username ($role)");
            $_SESSION['success'] = "User created successfully!";
            header('Location: users.php');
            exit();
        } catch (PDOException $e) {
            $error = "Error creating user: " . $e->getMessage() . " (SQLSTATE " . $e->getCode() . ")";
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// Update User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $role = isset($_POST['role']) ? $_POST['role'] : 'inventory_clerk';
    if (!array_key_exists($role, $VALID_ROLES)) {
        $role = 'inventory_clerk';
    }
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    try {
        $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, full_name = ?, role = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$username, $email, $full_name, $role, $is_active, $id]);
        logAudit($_SESSION['user_id'], 'update_user', 'user_management', "Updated user: $username");
        $_SESSION['success'] = "User updated successfully!";
        header('Location: users.php');
        exit();
    } catch (PDOException $e) {
        $error = "Error updating user: " . $e->getMessage() . " (SQLSTATE " . $e->getCode() . ")";
    }
}

// Archive User (Soft Delete)
if ($action === 'archive' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id != $_SESSION['user_id']) {
        archiveRecord('users', $id);
        logAudit($_SESSION['user_id'], 'archive_user', 'user_management', "Archived user ID: $id");
        $_SESSION['success'] = "User archived successfully!";
    } else {
        $_SESSION['error'] = "You cannot archive your own account!";
    }
    header('Location: users.php');
    exit();
}

// Restore User
if ($action === 'restore' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    restoreRecord('users', $id);
    logAudit($_SESSION['user_id'], 'restore_user', 'user_management', "Restored user ID: $id");
    $_SESSION['success'] = "User restored successfully!";
    header('Location: users.php?archived=1');
    exit();
}

// Delete User (Permanent)
if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id != $_SESSION['user_id']) {
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);
            logAudit($_SESSION['user_id'], 'delete_user', 'user_management', "Permanently deleted user ID: $id");
            $_SESSION['success'] = "User permanently deleted!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error deleting user: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "You cannot delete your own account!";
    }
    header('Location: users.php?archived=1');
    exit();
}

// Get users list
$showArchived = isset($_GET['archived']) ? 1 : 0;
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE is_archived = ? ORDER BY created_at DESC");
    $stmt->execute([$showArchived]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
    $error = "Error fetching users: " . $e->getMessage();
}

// Get user for edit
$editUser = null;
if ($action === 'edit' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Error fetching user: " . $e->getMessage();
    }
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
    <title>User Management - GlobalSCM</title>
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
        
        /* ============================================
           SIDEBAR - Collapsible
           ============================================ */
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
        
        /* Sidebar Profile */
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
        
        /* ============================================
           MAIN CONTENT - Full Width
           ============================================ */
        .main-content {
            margin-left: 280px;
            padding: 24px 32px 40px;
            flex: 1;
            min-height: 100vh;
            width: calc(100% - 280px);
            max-width: 100%;
            transition: var(--transition);
        }
        
        /* ============================================
           TOP BAR - Full Width
           ============================================ */
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
        
        /* ============================================
           BUTTONS
           ============================================ */
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
        
        /* ============================================
           ALERTS
           ============================================ */
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
        
        /* ============================================
           TABLE - Full Width
           ============================================ */
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
            min-width: 600px;
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
            background: rgba(47, 128, 237, 0.02);
        }
        
        table tbody tr:last-child td {
            border-bottom: none;
        }
        
        /* Center table content */
        table th:last-child,
        table td:last-child {
            text-align: center;
        }
        
        /* ============================================
           BADGES
           ============================================ */
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
        
        .status-inactive {
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
            min-width: 60px;
            text-align: center;
        }
        
        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        /* ============================================
           EMPTY STATE
           ============================================ */
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
           MODAL
           ============================================ */
        .modal-overlay {
            display: <?php echo ($editUser || isset($error)) ? 'flex' : 'none'; ?>;
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
            max-width: 500px;
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
        
        /* ============================================
           FORMS
           ============================================ */
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
        
        .form-group .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        
        .form-group .checkbox-label input[type="checkbox"] {
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
        
        /* ============================================
           FULLSCREEN TOGGLE
           ============================================ */
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
           RESPONSIVE BREAKPOINTS
           ============================================ */
        
        /* Tablets & Small Laptops */
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
        
        /* Mobile */
        @media (max-width: 768px) {
            /* Sidebar - Collapsible */
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
            
            /* Main Content - Full Width */
            .main-content {
                margin-left: 0;
                padding: 16px;
                width: 100%;
                padding-top: 16px;
            }
            
            /* Top Bar - Full Width */
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
            
            /* Table - Full Width */
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
            
            /* Modal */
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
            
            /* Fullscreen Toggle */
            .fullscreen-toggle {
                bottom: 16px;
                right: 16px;
                width: 44px;
                height: 44px;
                font-size: 18px;
            }
        }
        
        /* Small Mobile */
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
        }
        
        /* ============================================
           PRINT
           ============================================ */
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
            
            .table-container {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            
            body {
                background: white !important;
                color: black !important;
            }
            
            table {
                font-size: 12px !important;
            }
            
            .status-badge {
                background: #f0f0f0 !important;
                color: #333 !important;
                border: 1px solid #ccc !important;
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
                    <h1>User Management</h1>
                    <p>Manage system users and their permissions</p>
                </div>
                <div class="top-bar-actions">
                    <a href="users.php?action=create" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add User
                    </a>
                    <a href="users.php<?php echo $showArchived ? '' : '?archived=1'; ?>" class="btn btn-outline">
                        <i class="fas fa-archive"></i> <?php echo $showArchived ? 'Active Users' : 'Archived'; ?>
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
            
            <div class="table-container">
                <div class="table-header">
                    <h2><?php echo $showArchived ? 'Archived Users' : 'Active Users'; ?></h2>
                    <span class="role-badge"><?php echo count($users); ?> users</span>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($users)): ?>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($user['full_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <span class="role-badge">
                                            <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $user['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if (!$showArchived): ?>
                                                <a href="users.php?action=edit&id=<?php echo $user['id']; ?>" 
                                                   class="btn btn-primary btn-sm" title="Edit User">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                <a href="users.php?action=archive&id=<?php echo $user['id']; ?>" 
                                                   class="btn btn-warning btn-sm" 
                                                   onclick="return confirm('Archive this user?');" title="Archive User">
                                                    <i class="fas fa-archive"></i>
                                                </a>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <a href="users.php?action=restore&id=<?php echo $user['id']; ?>" 
                                                   class="btn btn-success btn-sm"
                                                   onclick="return confirm('Restore this user?');" title="Restore User">
                                                    <i class="fas fa-undo"></i>
                                                </a>
                                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                <a href="users.php?action=delete&id=<?php echo $user['id']; ?>" 
                                                   class="btn btn-danger btn-sm"
                                                   onclick="return confirm('⚠️ This will permanently delete this user. Continue?');" title="Delete Permanently">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="empty-state">
                                        <i class="fas fa-users"></i>
                                        <p>No users found</p>
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
    <?php if ($action === 'create' || $editUser): ?>
    <div class="modal-overlay" style="display: flex;">
        <div class="modal">
            <h3>
                <i class="fas fa-<?php echo $editUser ? 'edit' : 'user-plus'; ?>" style="color: var(--primary);"></i>
                <?php echo $editUser ? 'Edit User' : 'Create New User'; ?>
                <button type="button" class="close-modal" onclick="window.location.href='users.php'">&times;</button>
            </h3>
            <form method="POST" action="users.php?action=<?php echo $editUser ? 'edit' : 'create'; ?>">
                <?php if ($editUser): ?>
                <input type="hidden" name="id" value="<?php echo $editUser['id']; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" required 
                           value="<?php echo isset($editUser['full_name']) ? htmlspecialchars($editUser['full_name']) : ''; ?>"
                           placeholder="Enter full name">
                </div>
                
                <div class="form-group">
                    <label>Username *</label>
                    <input type="text" name="username" required 
                           value="<?php echo isset($editUser['username']) ? htmlspecialchars($editUser['username']) : ''; ?>"
                           placeholder="Enter username">
                </div>
                
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" required 
                           value="<?php echo isset($editUser['email']) ? htmlspecialchars($editUser['email']) : ''; ?>"
                           placeholder="Enter email address">
                </div>
                
                <?php if (!$editUser): ?>
                <div class="form-group">
                    <label>Password * <span style="font-size:11px;color:var(--secondary-text);font-weight:400;">(Min 8 chars, uppercase, lowercase, number, special char)</span></label>
                    <input type="password" name="password" id="userPasswordInput" required 
                           pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}"
                           placeholder="e.g. Strong@2026Pass"
                           title="Must contain at least 8 characters, including uppercase, lowercase, number, and special character">
                    <div style="margin-top:6px;font-size:12px;display:grid;grid-template-columns:1fr 1fr;gap:4px;color:var(--secondary-text);">
                        <span id="pwReqLen"><i class="fas fa-circle-notch"></i> 8+ Characters</span>
                        <span id="pwReqUpper"><i class="fas fa-circle-notch"></i> Uppercase (A-Z)</span>
                        <span id="pwReqLower"><i class="fas fa-circle-notch"></i> Lowercase (a-z)</span>
                        <span id="pwReqNum"><i class="fas fa-circle-notch"></i> Number (0-9)</span>
                        <span id="pwReqSpecial" style="grid-column: span 2;"><i class="fas fa-circle-notch"></i> Special Character (!@#$%^&*)</span>
                    </div>
                </div>
                <script>
                document.getElementById('userPasswordInput')?.addEventListener('input', function() {
                    var v = this.value;
                    function setReq(id, valid) {
                        var el = document.getElementById(id);
                        if (!el) return;
                        el.style.color = valid ? '#10B981' : '#EF4444';
                        el.querySelector('i').className = valid ? 'fas fa-check-circle' : 'fas fa-times-circle';
                    }
                    setReq('pwReqLen', v.length >= 8);
                    setReq('pwReqUpper', /[A-Z]/.test(v));
                    setReq('pwReqLower', /[a-z]/.test(v));
                    setReq('pwReqNum', /[0-9]/.test(v));
                    setReq('pwReqSpecial', /[^A-Za-z0-9]/.test(v));
                });
                </script>
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Role</label>
                    <select name="role">
                        <?php foreach ($VALID_ROLES as $roleValue => $roleLabel): ?>
                            <?php if ($roleValue === 'admin' && !isAdmin()) continue; ?>
                            <option value="<?php echo htmlspecialchars($roleValue); ?>" <?php echo (isset($editUser['role']) && $editUser['role'] === $roleValue) ? 'selected' : ''; ?>><?php echo htmlspecialchars($roleLabel); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if ($editUser): ?>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_active" <?php echo ($editUser['is_active'] ?? 0) ? 'checked' : ''; ?>>
                        Active Account
                    </label>
                </div>
                <?php endif; ?>
                
                <div class="form-actions">
                    <a href="users.php" class="btn btn-outline">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?php echo $editUser ? 'Update' : 'Create'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
        // ============================================
        // FULLSCREEN TOGGLE
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            const fullscreenBtn = document.getElementById('fullscreenToggle');
            const icon = fullscreenBtn.querySelector('i');
            
            fullscreenBtn.addEventListener('click', function() {
                if (!document.fullscreenElement) {
                    document.documentElement.requestFullscreen().catch(err => {
                        // Fallback for browsers that don't support fullscreen
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
            
            // Update icon when fullscreen changes
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
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            // Create toggle button if not exists
            const brand = document.querySelector('.sidebar-brand');
            if (brand) {
                const toggleBtn = document.createElement('button');
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
            
            // Close sidebar on overlay click
            if (overlay) {
                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('active');
                });
            }
            
            // Close sidebar on window resize (if going from mobile to desktop)
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
                    window.location.href = 'users.php';
                }
            });
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay').forEach(function(modal) {
                    if (modal.style.display === 'flex') {
                        modal.style.display = 'none';
                        window.location.href = 'users.php';
                    }
                });
            }
        });
    </script>
</body>
</html>