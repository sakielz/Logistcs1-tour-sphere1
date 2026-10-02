<?php
// admin/dashboard.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

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
    } else {
        $debug['access_result'] = 'ACCESS_ALLOWED_ROLE_' . strtoupper($_SESSION['role'] ?? 'unknown');
    }

    // Show the dashboard normally in debug mode so we can see whether
    // the session and authorization actually survive the redirect.
} else {
    requireAuth();
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

$stmt = $pdo->query("SELECT COUNT(*) as total FROM shipments WHERE status IN ('pending', 'in_transit') AND is_archived = false");
$stats['ongoing_shipments'] = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM purchase_requisitions WHERE status = 'pending_review' AND is_archived = false");
$stats['pending_requisitions'] = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM purchase_requisitions WHERE status = 'rejected' AND is_archived = false");
$stats['rejected_requisitions'] = $stmt->fetch()['total'];

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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .analytics-card {
            min-width: 0;
            padding: 22px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
        }

        .analytics-card-wide {
            grid-column: 1 / -1;
        }

        .analytics-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 14px;
        }

        .analytics-header h2 {
            font-size: 16px;
            font-weight: 600;
        }

        .analytics-source,
        .dashboard-updated {
            color: var(--secondary-text);
            font-size: 12px;
        }

        .analytics-source {
            display: inline-block;
            margin-top: 3px;
        }

        .chart-plot {
            position: relative;
            height: 280px;
        }

        .analytics-card-wide .chart-plot {
            height: 390px;
        }

        .supplier-status-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 18px;
            margin-top: 12px;
            list-style: none;
            font-size: 13px;
        }

        .supplier-status-list li {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .supplier-status-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
        }

        .pyramid-chart {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
            min-height: 280px;
            padding: 10px 0;
        }

        .pyramid-level {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            min-height: 42px;
            padding: 0 18px;
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            clip-path: polygon(7% 0, 93% 0, 100% 100%, 0 100%);
        }

        .pyramid-empty {
            color: var(--secondary-text);
            padding: 30px 0;
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

            .analytics-grid {
                grid-template-columns: 1fr;
            }

            .analytics-card-wide {
                grid-column: auto;
            }

            .chart-plot {
                height: 250px;
            }

            .analytics-card-wide .chart-plot {
                height: 360px;
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
                    <div class="value" data-live-stat="users"><?php echo $stats['users']; ?></div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-user-check icon"></i>
                    <div class="label">Active Users</div>
                    <div class="value" data-live-stat="active_users"><?php echo $stats['active_users']; ?></div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-box icon"></i>
                    <div class="label">Products</div>
                    <div class="value" data-live-stat="products"><?php echo $stats['products']; ?></div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-clock icon"></i>
                    <div class="label">Pending Approvals</div>
                    <div class="value" style="color: <?php echo $stats['pending_approvals'] > 0 ? '#DC2626' : 'var(--accent)'; ?>;">
                        <span data-live-stat="pending_approvals"><?php echo $stats['pending_approvals']; ?></span>
                    </div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-truck icon"></i>
                    <div class="label">Total Suppliers</div>
                    <div class="value" data-live-stat="suppliers"><?php echo $stats['suppliers']; ?></div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-ship icon"></i>
                    <div class="label">Ongoing Shipments</div>
                    <div class="value" data-live-stat="ongoing_shipments"><?php echo $stats['ongoing_shipments']; ?></div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-clipboard-list icon"></i>
                    <div class="label">Pending Requisitions</div>
                    <div class="value" data-live-stat="pending_requisitions"><?php echo $stats['pending_requisitions']; ?></div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-circle-xmark icon"></i>
                    <div class="label">Rejected Requisitions</div>
                    <div class="value" data-live-stat="rejected_requisitions"><?php echo $stats['rejected_requisitions']; ?></div>
                </div>
            </div>

            <div class="analytics-grid">
                <section class="analytics-card analytics-card-wide">
                    <div class="analytics-header">
                        <div>
                            <h2>All-Module Summary</h2>
                            <span class="analytics-source">Operational, reporting, settings, and archive records</span>
                        </div>
                        <span class="dashboard-updated" id="dashboardUpdated">Loading live data...</span>
                    </div>
                    <div class="chart-plot"><canvas id="moduleSummaryChart"></canvas></div>
                </section>

                <section class="analytics-card">
                    <div class="analytics-header">
                        <div>
                            <h2>Inventory Value by Category</h2>
                            <span class="analytics-source">products: category × unit price × current stock</span>
                        </div>
                    </div>
                    <div class="chart-plot"><canvas id="inventoryCategoryChart"></canvas></div>
                </section>

                <section class="analytics-card">
                    <div class="analytics-header">
                        <div>
                            <h2>Supplier Status</h2>
                            <span class="analytics-source">suppliers: status counts</span>
                        </div>
                    </div>
                    <div class="chart-plot"><canvas id="supplierStatusChart"></canvas></div>
                    <ul class="supplier-status-list" id="supplierStatusList"></ul>
                </section>

                <section class="analytics-card">
                    <div class="analytics-header">
                        <div>
                            <h2>Stock Movement</h2>
                            <span class="analytics-source">inventory_transactions: received and issued, last 7 days</span>
                        </div>
                    </div>
                    <div class="chart-plot"><canvas id="stockMovementChart"></canvas></div>
                </section>

                <section class="analytics-card">
                    <div class="analytics-header">
                        <div>
                            <h2>Requisition Status Pyramid</h2>
                            <span class="analytics-source">purchase_requisitions: status counts, sorted by volume</span>
                        </div>
                    </div>
                    <div class="pyramid-chart" id="requisitionPyramid" aria-live="polite">
                        <span class="pyramid-empty">Loading requisition status...</span>
                    </div>
                </section>
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

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var chartColors = ['#2F80ED', '#27AE60', '#F59E0B', '#DC2626', '#56CCF2', '#7C3AED', '#EC4899', '#6B7280', '#0F766E'];
            var chartFont = { family: 'Poppins, sans-serif' };
            var moduleCanvas = document.getElementById('moduleSummaryChart');
            var categoryCanvas = document.getElementById('inventoryCategoryChart');
            var supplierCanvas = document.getElementById('supplierStatusChart');
            var movementCanvas = document.getElementById('stockMovementChart');
            var moduleChart = null;
            var categoryChart = null;
            var supplierChart = null;
            var movementChart = null;

            if (window.Chart) {
                moduleChart = new Chart(moduleCanvas, {
                    type: 'bar',
                    data: { labels: [], datasets: [{ label: 'Records', data: [], backgroundColor: '#2F80ED', borderRadius: 4 }] },
                    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { bodyFont: chartFont } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: { ticks: { font: chartFont } } } }
                });
                categoryChart = new Chart(categoryCanvas, {
                    type: 'pie',
                    data: { labels: [], datasets: [{ data: [], backgroundColor: chartColors, borderColor: '#FFFFFF', borderWidth: 2 }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: chartFont } } } }
                });
                supplierChart = new Chart(supplierCanvas, {
                    type: 'doughnut',
                    data: { labels: [], datasets: [{ data: [], backgroundColor: chartColors, borderColor: '#FFFFFF', borderWidth: 2 }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: chartFont } } } }
                });
                movementChart = new Chart(movementCanvas, {
                    type: 'line',
                    data: { labels: [], datasets: [
                        { label: 'Received', data: [], borderColor: '#27AE60', backgroundColor: 'rgba(39,174,96,.14)', fill: true, tension: .3 },
                        { label: 'Issued', data: [], borderColor: '#DC2626', backgroundColor: 'rgba(220,38,38,.10)', fill: true, tension: .3 }
                    ] },
                    options: { responsive: true, maintainAspectRatio: false, interaction: { intersect: false, mode: 'index' }, plugins: { legend: { position: 'bottom', labels: { font: chartFont } } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
                });
            }

            function updateRequisitionPyramid(statusRows) {
                var labels = {
                    draft: 'Draft',
                    pending_review: 'Pending Review',
                    approved: 'Approved',
                    rejected: 'Rejected',
                    converted_to_po: 'Converted to PO'
                };
                var colors = ['#2F80ED', '#56CCF2', '#27AE60', '#DC2626', '#F59E0B'];
                var counts = {};
                statusRows.forEach(function(row) {
                    counts[String(row.status || 'draft').toLowerCase()] = Number(row.total) || 0;
                });

                var levels = Object.keys(labels).map(function(key) {
                    return { key: key, label: labels[key], count: counts[key] || 0 };
                }).sort(function(a, b) { return b.count - a.count; });
                var maximum = Math.max(1, ...levels.map(function(level) { return level.count; }));
                var container = document.getElementById('requisitionPyramid');
                container.textContent = '';

                levels.forEach(function(level, index) {
                    var row = document.createElement('div');
                    row.className = 'pyramid-level';
                    row.style.width = Math.max(60, (level.count / maximum) * 100) + '%';
                    row.style.backgroundColor = colors[index % colors.length];

                    var label = document.createElement('span');
                    label.textContent = level.label;
                    var value = document.createElement('span');
                    value.textContent = level.count.toLocaleString();
                    row.appendChild(label);
                    row.appendChild(value);
                    container.appendChild(row);
                });
            }

            function updateSupplierStatus(statusRows) {
                var labels = statusRows.map(function(row) {
                    return String(row.status || 'Unknown').replace(/_/g, ' ').replace(/\b\w/g, function(letter) { return letter.toUpperCase(); });
                });
                var values = statusRows.map(function(row) { return Number(row.total) || 0; });
                if (supplierChart) {
                    supplierChart.data.labels = labels;
                    supplierChart.data.datasets[0].data = values;
                    supplierChart.update('none');
                }

                var list = document.getElementById('supplierStatusList');
                list.textContent = '';
                statusRows.forEach(function(row, index) {
                    var item = document.createElement('li');
                    var dot = document.createElement('span');
                    dot.className = 'supplier-status-dot';
                    dot.style.backgroundColor = chartColors[index % chartColors.length];
                    var text = document.createElement('span');
                    text.textContent = (labels[index] || 'Unknown') + ': ' + (Number(row.total) || 0).toLocaleString();
                    item.appendChild(dot);
                    item.appendChild(text);
                    list.appendChild(item);
                });
            }

            function refreshDashboard() {
                fetch('../api/dashboard.php', { credentials: 'same-origin', cache: 'no-store' })
                    .then(function(response) {
                        if (!response.ok) throw new Error('Dashboard data request failed');
                        return response.json();
                    })
                    .then(function(data) {
                        if (!data.success) throw new Error(data.error || 'Dashboard data unavailable');

                        document.querySelectorAll('[data-live-stat]').forEach(function(element) {
                            var key = element.dataset.liveStat;
                            if (Object.prototype.hasOwnProperty.call(data.stats, key)) {
                                element.textContent = Number(data.stats[key]).toLocaleString();
                            }
                        });

                        if (moduleChart) {
                            moduleChart.data.labels = data.module_counts.map(function(item) { return item.label; });
                            moduleChart.data.datasets[0].data = data.module_counts.map(function(item) { return Number(item.total) || 0; });
                            moduleChart.update('none');
                        }
                        if (categoryChart) {
                            categoryChart.data.labels = data.inventory_categories.map(function(item) { return item.category; });
                            categoryChart.data.datasets[0].data = data.inventory_categories.map(function(item) { return Number(item.total_value) || 0; });
                            categoryChart.update('none');
                        }
                        if (movementChart) {
                            movementChart.data.labels = data.stock_movements.map(function(item) { return item.date; });
                            movementChart.data.datasets[0].data = data.stock_movements.map(function(item) { return Number(item.received) || 0; });
                            movementChart.data.datasets[1].data = data.stock_movements.map(function(item) { return Number(item.issued) || 0; });
                            movementChart.update('none');
                        }

                        updateSupplierStatus(data.supplier_status || []);
                        updateRequisitionPyramid(data.requisition_status || []);
                        document.getElementById('dashboardUpdated').textContent = 'Updated ' + new Date(data.updated_at).toLocaleTimeString();
                    })
                    .catch(function(error) {
                        console.error(error);
                        document.getElementById('dashboardUpdated').textContent = 'Live data unavailable';
                    });
            }

            refreshDashboard();
            window.setInterval(refreshDashboard, 30000);
        });
    </script>
    
</body>
</html>