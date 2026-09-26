<?php
// admin/dashboard.php
require_once __DIR__ . '/../config/database.php';

// ============================================================
// DASHBOARD ACCESS DEBUG MODE
// Add ?debug=1 to this URL temporarily to diagnose login/session
// and role authorization without immediately redirecting to login.
// ============================================================
$dashboardDebug = isset($_GET['debug']) && $_GET['debug'] === '1';

if ($dashboardDebug) {
    $debug = [];

    $debug['session_status'] = session_status() === PHP_SESSION_ACTIVE ? 'ACTIVE' : 'NOT_ACTIVE';
    $debug['session_id_exists'] = session_id() !== '' ? 'YES' : 'NO';
    $debug['is_logged_in'] = isLoggedIn() ? 'TRUE' : 'FALSE';
    $debug['is_admin'] = isAdmin() ? 'TRUE' : 'FALSE';
    $debug['session_user_id'] = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : '(not set)';
    $debug['session_username'] = isset($_SESSION['username']) ? $_SESSION['username'] : '(not set)';
    $debug['session_role'] = isset($_SESSION['role']) ? $_SESSION['role'] : '(not set)';
    $debug['session_full_name'] = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : '(not set)';
    $debug['session_email'] = isset($_SESSION['email']) ? $_SESSION['email'] : '(not set)';

    if ($debug['is_logged_in'] !== 'TRUE') {
        $debug['access_result'] = 'NOT_LOGGED_IN';
    } elseif ($debug['is_admin'] !== 'TRUE') {
        $debug['access_result'] = 'LOGGED_IN_BUT_NOT_ADMIN';
    } else {
        $debug['access_result'] = 'ADMIN_ACCESS_ALLOWED';
    }

    // Show the dashboard normally in debug mode so we can see whether
    // the session and authorization actually survive the redirect.
} elseif (!isLoggedIn() || !isAdmin()) {
    header('Location: ../login.php');
    exit();
}
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


// Get statistics
$stats = [];

// Total users
$stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE is_archived = false");
$stats['users'] = $stmt->fetch()['total'];

// Total suppliers
$stmt = $pdo->query("SELECT COUNT(*) as total FROM suppliers WHERE is_archived = false");
$stats['suppliers'] = $stmt->fetch()['total'];

// Total products
$stmt = $pdo->query("SELECT COUNT(*) as total FROM products WHERE is_archived = false");
$stats['products'] = $stmt->fetch()['total'];

// Pending approvals
$stmt = $pdo->query("SELECT COUNT(*) as total FROM purchase_orders WHERE approval_status = 'pending_review' AND is_archived = false");
$stats['pending_approvals'] = $stmt->fetch()['total'];

// Active users
$stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE is_active = true AND is_archived = false");
$stats['active_users'] = $stmt->fetch()['total'];

// Recent activity
$stmt = $pdo->query("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 10");
$recentActivity = $stmt->fetchAll();

// Theme setting
$stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'theme'");
$theme = $stmt->fetch()['setting_value'] ?? 'light';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - GlobalSCM</title>
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
        }
        
        [data-theme="dark"] {
            --bg: #1a1a2e;
            --card: #16213e;
            --text: #e2e8f0;
            --secondary-text: #a0aec0;
            --border: #2d3748;
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
            transition: all 0.3s;
        }
        
        .admin-layout {
            display: flex;
            min-height: 100vh;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--card);
            padding: 20px 25px;
            border-radius: 12px;
            border: 1px solid var(--border);
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.06);
        }
        
        .stat-card .label {
            font-size: 13px;
            color: var(--secondary-text);
            font-weight: 500;
        }
        
        .stat-card .value {
            font-size: 28px;
            font-weight: 700;
            margin: 8px 0;
        }
        
        .stat-card .icon {
            float: right;
            font-size: 28px;
            color: var(--primary);
            opacity: 0.3;
        }
        
        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
        }
        
        .dashboard-card {
            background: var(--card);
            border-radius: 12px;
            border: 1px solid var(--border);
            padding: 25px;
        }
        
        .dashboard-card .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .dashboard-card .card-header h3 {
            font-size: 16px;
            font-weight: 600;
        }
        
        .dashboard-card .card-header a {
            color: var(--primary);
            text-decoration: none;
            font-size: 13px;
        }
        
        .activity-item {
            display: flex;
            gap: 15px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(47, 128, 237, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            flex-shrink: 0;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-content .action {
            font-weight: 500;
            font-size: 14px;
        }
        
        .activity-content .details {
            font-size: 13px;
            color: var(--secondary-text);
        }
        
        .activity-content .time {
            font-size: 12px;
            color: var(--secondary-text);
        }
        
     /* Quick Actions */
.quick-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.quick-action-btn {
    padding: 15px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--bg);
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
    text-decoration: none;
    color: var(--text);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}

.quick-action-btn:hover {
    border-color: var(--primary);
    background: rgba(47, 128, 237, 0.05);
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.quick-action-btn i {
    font-size: 24px;
    color: var(--primary);
}

.quick-action-btn span {
    font-size: 13px;
    font-weight: 500;
}

@media (max-width: 480px) {
    .quick-actions {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    
    .quick-action-btn {
        padding: 12px;
    }
    
    .quick-action-btn i {
        font-size: 20px;
    }
    
    .quick-action-btn span {
        font-size: 12px;
    }
}
        
        @media (max-width: 1024px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 0;
                padding: 0;
                overflow: hidden;
            }
            
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <!-- Sidebar -->
        <?php include 'partials/sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <?php if ($dashboardDebug): ?>
            <div style="background:#FFF7ED;border:1px solid #FDBA74;border-radius:12px;padding:20px;margin-bottom:25px;font-family:monospace;">
                <div style="font-family:'Poppins',sans-serif;font-size:18px;font-weight:700;color:#9A3412;margin-bottom:12px;">
                    <i class="fas fa-bug"></i> DASHBOARD / SESSION DEBUG
                </div>
                <div style="font-family:'Poppins',sans-serif;color:#7C2D12;margin-bottom:15px;">
                    This temporary panel checks whether the login session reached dashboard.php and whether the current account passes the admin-role check.
                </div>
                <?php foreach ($debug as $key => $value): ?>
                    <div style="padding:5px 0;border-bottom:1px solid rgba(154,52,18,.12);">
                        <strong><?php echo htmlspecialchars((string)$key); ?>:</strong>
                        <?php echo htmlspecialchars((string)$value); ?>
                    </div>
                <?php endforeach; ?>
                <div style="font-family:'Poppins',sans-serif;margin-top:15px;padding:12px;background:#FFFFFF;border-radius:8px;color:#7C2D12;">
                    <strong>Important:</strong> This debug panel does not display your password or password hash.
                </div>
            </div>
            <?php endif; ?>
            <!-- Top Bar -->
            <div class="top-bar">
                <div class="page-title">
                    <h1>Admin Dashboard</h1>
                    <p>Welcome back, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Unknown User'); ?>!</p>
                </div>
                <div class="top-bar-actions">
                    <?php include 'partials/headbar_actions.php'; ?>
                </div>
            </div>
            
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <i class="fas fa-users icon"></i>
                    <div class="label">Total Users</div>
                    <div class="value"><?php echo $stats['users']; ?></div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-user-check icon"></i>
                    <div class="label">Active Users</div>
                    <div class="value"><?php echo $stats['active_users']; ?></div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-box icon"></i>
                    <div class="label">Products</div>
                    <div class="value"><?php echo $stats['products']; ?></div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-clock icon"></i>
                    <div class="label">Pending Approvals</div>
                    <div class="value" style="color: <?php echo $stats['pending_approvals'] > 0 ? '#DC2626' : 'var(--accent)'; ?>;">
                        <?php echo $stats['pending_approvals']; ?>
                    </div>
                </div>
            </div>
            
            <!-- Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Recent Activity -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-history" style="color: var(--primary);"></i> Recent Activity</h3>
                        <a href="logs.php">View All</a>
                    </div>
                    <?php foreach ($recentActivity as $activity): ?>
                    <div class="activity-item">
                        <div class="activity-icon">
                            <i class="fas fa-circle" style="font-size: 10px;"></i>
                        </div>
                        <div class="activity-content">
                            <div class="action"><?php echo htmlspecialchars($activity['action']); ?></div>
                            <div class="details"><?php echo htmlspecialchars($activity['description']); ?></div>
                            <div class="time"><?php echo date('M d, Y h:i A', strtotime($activity['created_at'])); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($recentActivity)): ?>
                    <p style="color: var(--secondary-text); text-align: center; padding: 20px;">No recent activity</p>
                    <?php endif; ?>
                </div>
                
                <!-- Quick Actions -->
               <!-- Quick Actions -->
<div class="dashboard-card">
    <div class="card-header">
        <h3><i class="fas fa-bolt" style="color: var(--primary);"></i> Quick Actions</h3>
    </div>
    <div class="quick-actions">
        <a href="users.php?action=create" class="quick-action-btn">
            <i class="fas fa-user-plus"></i>
            <span>Add User</span>
        </a>
        <a href="warehouses.php?action=create" class="quick-action-btn">
            <i class="fas fa-warehouse"></i>
            <span>Add Warehouse</span>
        </a>
        <a href="inventory.php?action=create" class="quick-action-btn">
            <i class="fas fa-box"></i>
            <span>Add Product</span>
        </a>
        <a href="suppliers.php?action=create" class="quick-action-btn">
            <i class="fas fa-truck"></i>
            <span>Add Supplier</span>
        </a>
        <a href="purchase-orders.php?action=create" class="quick-action-btn">
            <i class="fas fa-file-invoice"></i>
            <span>Create PO</span>
        </a>
        <a href="requisitions.php?action=create" class="quick-action-btn">
            <i class="fas fa-clipboard-list"></i>
            <span>Create Requisition</span>
        </a>
        <a href="reports.php?ai=1" class="quick-action-btn" style="border-color: var(--accent);">
            <i class="fas fa-robot" style="color: var(--accent);"></i>
            <span>AI Report</span>
        </a>
        <a href="archive.php" class="quick-action-btn">
            <i class="fas fa-archive"></i>
            <span>View Archive</span>
        </a>
    </div>
</div>
            </div>
        </main>
    </div>
    
</body>
</html>