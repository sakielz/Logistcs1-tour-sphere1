<?php
// admin/partials/sidebar.php
// Single source of truth for navigation across all admin pages

// Get module visibility settings
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'show_%'");
    $visibility = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $visibility[$row['setting_key']] = $row['setting_value'] === 'true';
    }
} catch (Exception $e) {
    $visibility = [];
}

// Default to true if not set
$showWarehousing = isset($visibility['show_warehousing']) ? $visibility['show_warehousing'] : true;
$showInventory = isset($visibility['show_inventory']) ? $visibility['show_inventory'] : true;
$showProcurement = isset($visibility['show_procurement']) ? $visibility['show_procurement'] : true;
$showSuppliers = isset($visibility['show_suppliers']) ? $visibility['show_suppliers'] : true;
$showPurchaseOrders = isset($visibility['show_purchase_orders']) ? $visibility['show_purchase_orders'] : true;
$showLogistics = isset($visibility['show_logistics']) ? $visibility['show_logistics'] : true;

// Get theme
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

// Get current page for active state
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!-- Tailwind CSS (Scoped with Preflight disabled to preserve existing UI) -->
<script src="https://cdn.tailwindcss.com"></script>
<script>
    if (typeof tailwind !== 'undefined') {
        tailwind.config = {
            corePlugins: {
                preflight: false,
            },
            theme: {
                extend: {
                    colors: {
                        primary: '#2F80ED',
                        secondary: '#56CCF2',
                        accent: '#10B981',
                        danger: '#EF4444',
                        warning: '#F59E0B',
                    }
                }
            }
        };
    }
</script>

<nav class="sidebar" id="sidebar">
    <!-- Sidebar Brand -->
    <div class="sidebar-brand">
        <div>
            <h2>GlobalSCM</h2>
            <span>Supply Chain Management</span>
        </div>
        <button class="sidebar-close" id="sidebarClose" aria-label="Close Sidebar" type="button">
            <i class="fas fa-times"></i>
        </button>
    </div>
    
    <!-- ===== MAIN NAVIGATION ===== -->
    <div class="nav-section">
        <div class="nav-section-title" onclick="toggleSection(this)">
            Main
            <span class="collapse-icon"><i class="fas fa-chevron-down"></i></span>
        </div>
        <div class="nav-items">
            <a href="dashboard.php" class="nav-item <?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <?php if (isAdmin()): ?>
            <a href="users.php" class="nav-item <?php echo $currentPage === 'users.php' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> User Management
            </a>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- ===== WAREHOUSING MODULE ===== -->
    <?php if ($showWarehousing): ?>
    <div class="nav-section">
        <div class="nav-section-title" onclick="toggleSection(this)">
            Warehousing
            <span class="collapse-icon"><i class="fas fa-chevron-down"></i></span>
        </div>
        <div class="nav-items">
            <a href="warehouses.php" class="nav-item <?php echo $currentPage === 'warehouses.php' ? 'active' : ''; ?>">
                <i class="fas fa-warehouse"></i> Warehouses
                <?php
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM warehouses WHERE is_archived = 0");
                    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                    if ($count > 0): ?>
                    <span class="badge"><?php echo $count; ?></span>
                    <?php endif;
                } catch (Exception $e) {}
                ?>
            </a>
            <a href="warehouse-zones.php" class="nav-item sub-nav <?php echo $currentPage === 'warehouse-zones.php' ? 'active' : ''; ?>">
                <i class="fas fa-layer-group"></i> Zone Management
                <?php
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM warehouse_zones wz JOIN warehouses w ON wz.warehouse_id = w.id WHERE w.is_archived = 0");
                    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                    if ($count > 0): ?>
                    <span class="badge"><?php echo $count; ?></span>
                    <?php endif;
                } catch (Exception $e) {}
                ?>
            </a>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- ===== INVENTORY MODULE ===== -->
    <?php if ($showInventory): ?>
    <div class="nav-section">
        <div class="nav-section-title" onclick="toggleSection(this)">
            Inventory
            <span class="collapse-icon"><i class="fas fa-chevron-down"></i></span>
        </div>
        <div class="nav-items">
            <a href="inventory.php" class="nav-item <?php echo $currentPage === 'inventory.php' ? 'active' : ''; ?>">
                <i class="fas fa-boxes"></i> Products
                <?php
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM products WHERE is_archived = 0");
                    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                    if ($count > 0): ?>
                    <span class="badge"><?php echo $count; ?></span>
                    <?php endif;
                } catch (Exception $e) {}
                ?>
            </a>
            <a href="stock-movements.php" class="nav-item <?php echo $currentPage === 'stock-movements.php' ? 'active' : ''; ?>">
                <i class="fas fa-exchange-alt"></i> Stock Movements
            </a>
            <?php
            try {
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM products WHERE current_stock <= reorder_point AND is_archived = 0");
                $lowStock = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                if ($lowStock > 0): ?>
                <a href="inventory.php?filter=low_stock" class="nav-item sub-nav" style="color: #DC2626;">
                    <i class="fas fa-exclamation-triangle"></i> Low Stock Alert
                    <span class="badge" style="background: #DC2626;"><?php echo $lowStock; ?></span>
                </a>
                <?php endif;
            } catch (Exception $e) {}
            ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- ===== PROCUREMENT MODULE ===== -->
    <?php if ($showProcurement): ?>
    <div class="nav-section">
        <div class="nav-section-title" onclick="toggleSection(this)">
            Procurement
            <span class="collapse-icon"><i class="fas fa-chevron-down"></i></span>
        </div>
        <div class="nav-items">
            <?php if ($showSuppliers): ?>
            <a href="suppliers.php" class="nav-item <?php echo $currentPage === 'suppliers.php' ? 'active' : ''; ?>">
                <i class="fas fa-truck"></i> Suppliers
                <?php
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM suppliers WHERE is_archived = 0");
                    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                    if ($count > 0): ?>
                    <span class="badge"><?php echo $count; ?></span>
                    <?php endif;
                } catch (Exception $e) {}
                ?>
            </a>
            <?php endif; ?>
            
            <?php if ($showPurchaseOrders): ?>
            <a href="purchase-orders.php" class="nav-item <?php echo $currentPage === 'purchase-orders.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-invoice"></i> Purchase Orders
                <?php
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM purchase_orders WHERE status IN ('pending', 'approved') AND is_archived = 0");
                    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                    if ($count > 0): ?>
                    <span class="badge" style="background: #F59E0B;"><?php echo $count; ?></span>
                    <?php endif;
                } catch (Exception $e) {}
                ?>
            </a>
            <?php endif; ?>
            
            <a href="requisitions.php" class="nav-item <?php echo $currentPage === 'requisitions.php' ? 'active' : ''; ?>">
                <i class="fas fa-clipboard-list"></i> Requisitions
                <?php
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM purchase_requisitions WHERE status = 'pending_review' AND is_archived = 0");
                    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                    if ($count > 0): ?>
                    <span class="badge" style="background: #F59E0B;"><?php echo $count; ?></span>
                    <?php endif;
                } catch (Exception $e) {}
                ?>
            </a>
            
            <a href="contracts.php" class="nav-item <?php echo $currentPage === 'contracts.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-signature"></i> Contracts
                <?php
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM procurement_contracts WHERE status = 'active' AND is_archived = 0");
                    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                    if ($count > 0): ?>
                    <span class="badge" style="background: var(--accent);"><?php echo $count; ?></span>
                    <?php endif;
                } catch (Exception $e) {}
                ?>
            </a>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- ===== LOGISTICS MODULE ===== -->
    <?php if ($showLogistics): ?>
    <div class="nav-section">
        <div class="nav-section-title" onclick="toggleSection(this)">
            Logistics
            <span class="collapse-icon"><i class="fas fa-chevron-down"></i></span>
        </div>
        <div class="nav-items">
            <a href="shipments.php" class="nav-item <?php echo $currentPage === 'shipments.php' ? 'active' : ''; ?>">
                <i class="fas fa-ship"></i> Shipments
                <?php
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM shipments WHERE status IN ('pending', 'in_transit') AND is_archived = 0");
                    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                    if ($count > 0): ?>
                    <span class="badge" style="background: #1E40AF;"><?php echo $count; ?></span>
                    <?php endif;
                } catch (Exception $e) {}
                ?>
            </a>
            <a href="documents.php" class="nav-item <?php echo $currentPage === 'documents.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-alt"></i> Documents
                <?php
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM documents WHERE status = 'pending' AND is_archived = 0");
                    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                    if ($count > 0): ?>
                    <span class="badge" style="background: #F59E0B;"><?php echo $count; ?></span>
                    <?php endif;
                } catch (Exception $e) {}
                ?>
            </a>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- ===== ANALYTICS ===== -->
    <div class="nav-section">
        <div class="nav-section-title" onclick="toggleSection(this)">
            Analytics
            <span class="collapse-icon"><i class="fas fa-chevron-down"></i></span>
        </div>
        <div class="nav-items">
            <a href="reports.php" class="nav-item <?php echo $currentPage === 'reports.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar"></i> Reports
                <?php
                try {
                    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'report_ai_enabled'");
                    $aiEnabled = $stmt->fetch(PDO::FETCH_ASSOC)['setting_value'] ?? 'true';
                    if ($aiEnabled === 'true'): ?>
                    <span class="badge" style="background: var(--accent);">AI</span>
                    <?php endif;
                } catch (Exception $e) {}
                ?>
            </a>
            <a href="logs.php" class="nav-item <?php echo $currentPage === 'logs.php' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i> Audit Logs
            </a>
        </div>
    </div>
    
    <!-- ===== SYSTEM ===== -->
    <div class="nav-section">
        <div class="nav-section-title" onclick="toggleSection(this)">
            System
            <span class="collapse-icon"><i class="fas fa-chevron-down"></i></span>
        </div>
        <div class="nav-items">
            <?php if (isAdmin()): ?>
            <a href="archive.php" class="nav-item <?php echo $currentPage === 'archive.php' ? 'active' : ''; ?>">
                <i class="fas fa-archive"></i> Archive
                <?php
                try {
                    $sidebarArchiveTables = ['users', 'suppliers', 'products', 'warehouses', 'purchase_orders', 'shipments', 'purchase_requisitions', 'procurement_contracts', 'documents'];
                    $sidebarArchiveCount = 0;
                    foreach ($sidebarArchiveTables as $archTable) {
                        try {
                            $stmt = $pdo->query("SELECT COUNT(*) as count FROM $archTable WHERE is_archived = 1");
                            $sidebarArchiveCount += (int)($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);
                        } catch (Exception $e) {}
                    }
                    if ($sidebarArchiveCount > 0): ?>
                    <span class="badge" style="background: #6B7280;"><?php echo $sidebarArchiveCount; ?></span>
                    <?php endif;
                } catch (Exception $e) {}
                ?>
            </a>
            <a href="settings.php" class="nav-item <?php echo $currentPage === 'settings.php' ? 'active' : ''; ?>">
                <i class="fas fa-cog"></i> Settings
            </a>
            <?php endif; ?>
            <a href="../logout.php" class="nav-item" onclick="return confirm('Are you sure you want to logout?');">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
    
    <!-- ===== QUICK CREATE SECTION ===== -->
    <div class="quick-create-section">
        <div class="quick-create-dropdown">
            <button class="quick-create-btn" onclick="toggleQuickCreate()" type="button">
                <i class="fas fa-plus-circle"></i> Create New
                <i class="fas fa-chevron-down" style="font-size: 11px; margin-left: auto;"></i>
            </button>
            <div class="dropdown-content" id="quickCreateDropdown">
                <a href="purchase-orders.php?action=create">
                    <i class="fas fa-file-invoice"></i> Purchase Order
                </a>
                <a href="requisitions.php?action=create">
                    <i class="fas fa-clipboard-list"></i> Requisition
                </a>
                <a href="inventory.php?action=create">
                    <i class="fas fa-box"></i> Product
                </a>
                <a href="suppliers.php?action=create">
                    <i class="fas fa-truck"></i> Supplier
                </a>
                <a href="warehouses.php?action=create">
                    <i class="fas fa-warehouse"></i> Warehouse
                </a>
                <a href="shipments.php?action=create">
                    <i class="fas fa-ship"></i> Shipment
                </a>
            </div>
        </div>
    </div>
</nav>

<!-- ===== SIDEBAR OVERLAY (Mobile) ===== -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<style>
    /* ============================================
       GLOBAL MINIMALISTIC DESIGN & LAYOUT SYSTEM
       ============================================ */
    body {
        font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif !important;
        background: var(--bg) !important;
        color: var(--text) !important;
        margin: 0;
        padding: 0;
        line-height: 1.5;
        -webkit-font-smoothing: antialiased;
    }

    .admin-layout {
        display: flex !important;
        min-height: 100vh !important;
        width: 100% !important;
        background: var(--bg) !important;
    }

    /* ============================================
       SIDEBAR - MINIMALISTIC & CONSISTENT
       ============================================ */
    .sidebar {
        display: flex !important;
        flex-direction: column !important;
        width: 260px !important;
        height: 100vh !important;
        background: var(--card) !important;
        border-right: 1px solid var(--border) !important;
        padding: 20px 14px 16px !important;
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        bottom: 0 !important;
        overflow-y: auto !important;
        z-index: 100 !important;
        box-shadow: none !important;
        box-sizing: border-box !important;
    }

    .sidebar::-webkit-scrollbar {
        width: 4px;
    }
    .sidebar::-webkit-scrollbar-thumb {
        background: var(--border);
        border-radius: 4px;
    }

    /* Sidebar Brand */
    .sidebar-brand {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        margin-bottom: 20px !important;
        padding-bottom: 14px !important;
        padding-left: 6px !important;
        padding-right: 6px !important;
        border-bottom: 1px solid var(--border) !important;
    }

    .sidebar-brand h2 {
        font-size: 20px !important;
        font-weight: 700 !important;
        color: var(--primary) !important;
        margin: 0 !important;
        letter-spacing: -0.2px !important;
        line-height: 1.2 !important;
    }

    .sidebar-brand h2::after {
        content: none !important;
        display: none !important;
    }

    .sidebar-brand span {
        font-size: 11.5px !important;
        color: var(--secondary-text) !important;
        font-weight: 400 !important;
        letter-spacing: normal !important;
        text-transform: none !important;
        display: block !important;
        margin-top: 2px !important;
    }

    .sidebar-close {
        display: none;
        background: none;
        border: none;
        font-size: 16px;
        color: var(--secondary-text);
        cursor: pointer;
        padding: 4px;
        border-radius: 6px;
    }
    .sidebar-close:hover {
        color: var(--text);
    }

    /* Nav Sections */
    .nav-section {
        margin-bottom: 14px !important;
    }

    .nav-section-title {
        font-size: 10.5px !important;
        text-transform: uppercase !important;
        color: var(--secondary-text) !important;
        font-weight: 600 !important;
        letter-spacing: 0.8px !important;
        margin-bottom: 4px !important;
        padding: 4px 10px !important;
        cursor: pointer !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        user-select: none !important;
        border-radius: 6px !important;
        transition: color 0.15s ease !important;
        background: none !important;
    }

    .nav-section-title:hover {
        color: var(--text) !important;
        background: none !important;
    }

    .nav-section-title .collapse-icon {
        font-size: 9px !important;
        opacity: 0.6;
        transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
    }

    .nav-section-title .collapse-icon.collapsed {
        transform: rotate(-90deg) !important;
    }

    .nav-items {
        overflow: hidden !important;
        max-height: 280px !important;
        opacity: 1 !important;
        transform: translateY(0) !important;
        transition: max-height 0.22s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.18s ease, transform 0.22s cubic-bezier(0.16, 1, 0.3, 1) !important;
    }

    .nav-items.collapsed {
        max-height: 0 !important;
        opacity: 0 !important;
        transform: translateY(-4px) !important;
        pointer-events: none !important;
    }

    /* Nav Items */
    .nav-item {
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
        padding: 8.5px 12px !important;
        border-radius: 8px !important;
        color: var(--secondary-text) !important;
        text-decoration: none !important;
        transition: background 0.15s ease, color 0.15s ease !important;
        margin-bottom: 2px !important;
        cursor: pointer !important;
        font-size: 13.5px !important;
        font-weight: 500 !important;
        position: relative !important;
        box-sizing: border-box !important;
    }

    .nav-item:hover {
        background: rgba(47, 128, 237, 0.07) !important;
        color: var(--primary) !important;
    }

    .nav-item.active {
        background: rgba(47, 128, 237, 0.12) !important;
        color: var(--primary) !important;
        font-weight: 600 !important;
    }

    .nav-item.active::before,
    .nav-item::before {
        display: none !important;
        content: none !important;
    }

    .nav-item i {
        width: 18px !important;
        font-size: 14.5px !important;
        text-align: center !important;
        flex-shrink: 0 !important;
    }

    .sub-nav {
        padding-left: 34px !important;
        font-size: 13px !important;
        font-weight: 400 !important;
    }

    .sub-nav i {
        font-size: 12px !important;
        width: 14px !important;
    }

    .nav-item .badge {
        margin-left: auto !important;
        background: var(--primary) !important;
        color: #fff !important;
        padding: 1.5px 7px !important;
        border-radius: 12px !important;
        font-size: 10.5px !important;
        font-weight: 600 !important;
        min-width: 18px !important;
        text-align: center !important;
        line-height: 1.3 !important;
    }

    /* Quick Create Dropdown */
    .quick-create-section {
        margin-top: auto !important;
        padding-top: 14px !important;
        border-top: 1px solid var(--border) !important;
    }

    .quick-create-btn {
        display: flex !important;
        align-items: center !important;
        gap: 8px !important;
        padding: 8.5px 12px !important;
        border-radius: 8px !important;
        background: var(--primary) !important;
        color: white !important;
        border: none !important;
        width: 100% !important;
        cursor: pointer !important;
        font-family: inherit !important;
        font-weight: 500 !important;
        font-size: 13px !important;
        transition: opacity 0.15s ease !important;
    }

    .quick-create-btn:hover {
        opacity: 0.92;
    }

    .quick-create-btn i {
        font-size: 14px !important;
    }

    .quick-create-dropdown {
        position: relative;
    }

    .quick-create-dropdown .dropdown-content {
        display: none;
        position: absolute;
        bottom: calc(100% + 8px);
        left: 0;
        right: 0;
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 10px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        padding: 6px;
        z-index: 150;
        transition: none !important;
        animation: none !important;
    }

    .quick-create-dropdown .dropdown-content.show {
        display: block !important;
    }

    .quick-create-dropdown .dropdown-content a {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 12px;
        color: var(--text);
        text-decoration: none;
        font-size: 13px;
        border-radius: 6px;
        transition: background 0.15s ease;
    }

    .quick-create-dropdown .dropdown-content a:hover {
        background: rgba(47, 128, 237, 0.08);
        color: var(--primary);
    }

    /* ====================================================
       SMOOTH & RESPONSIVE DROPDOWNS ACROSS ALL MODULES
       (Fluid micro-animation with immediate response)
       ==================================================== */
    .dropdown,
    .top-bar-actions .dropdown {
        position: relative !important;
    }

    @keyframes dropdownFadeIn {
        0% {
            opacity: 0;
            transform: translateY(-6px) scale(0.98);
        }
        100% {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    @keyframes quickCreateFadeUp {
        0% {
            opacity: 0;
            transform: translateY(6px) scale(0.98);
        }
        100% {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .dropdown-content,
    .top-bar-actions .dropdown-content {
        display: none !important;
        transform-origin: top right !important;
        will-change: transform, opacity !important;
    }

    .dropdown-content.show,
    .top-bar-actions .dropdown-content.show {
        display: block !important;
        animation: dropdownFadeIn 0.16s cubic-bezier(0.16, 1, 0.3, 1) forwards !important;
    }

    .quick-create-dropdown .dropdown-content.show {
        display: block !important;
        animation: quickCreateFadeUp 0.16s cubic-bezier(0.16, 1, 0.3, 1) forwards !important;
    }

    /* ====================================================
       MINIMALISTIC SYSTEM ACTION BUTTONS (REPLACES CHUNKY BLOCKS)
       ==================================================== */
    .action-buttons {
        display: inline-flex !important;
        align-items: center !important;
        gap: 6px !important;
        flex-wrap: nowrap !important;
        justify-content: center !important;
    }

    .action-buttons .btn,
    .action-buttons a,
    .action-buttons button,
    .action-buttons .btn-sm,
    .action-buttons .btn-xs {
        width: 32px !important;
        height: 32px !important;
        min-width: 32px !important;
        max-width: 32px !important;
        min-height: 32px !important;
        max-height: 32px !important;
        padding: 0 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        border-radius: 8px !important;
        font-size: 13px !important;
        border: 1px solid transparent !important;
        transition: all 0.15s cubic-bezier(0.4, 0, 0.2, 1) !important;
        box-shadow: none !important;
        transform: none !important;
        cursor: pointer !important;
        text-decoration: none !important;
        box-sizing: border-box !important;
    }

    .action-buttons .btn i,
    .action-buttons a i,
    .action-buttons button i {
        font-size: 13px !important;
        margin: 0 !important;
        line-height: 1 !important;
    }

    /* Minimalist Primary (Edit, View) */
    .action-buttons .btn-primary,
    .action-buttons a[class*="btn-primary"] {
        background: rgba(47, 128, 237, 0.08) !important;
        color: #2F80ED !important;
        border-color: rgba(47, 128, 237, 0.2) !important;
    }
    .action-buttons .btn-primary:hover,
    .action-buttons a[class*="btn-primary"]:hover {
        background: #2F80ED !important;
        color: #ffffff !important;
        border-color: #2F80ED !important;
        box-shadow: 0 2px 6px rgba(47, 128, 237, 0.25) !important;
    }

    /* Minimalist Success (Restore, Zones, Approve) */
    .action-buttons .btn-success,
    .action-buttons a[class*="btn-success"] {
        background: rgba(16, 185, 129, 0.08) !important;
        color: #10B981 !important;
        border-color: rgba(16, 185, 129, 0.22) !important;
    }
    .action-buttons .btn-success:hover,
    .action-buttons a[class*="btn-success"]:hover {
        background: #10B981 !important;
        color: #ffffff !important;
        border-color: #10B981 !important;
        box-shadow: 0 2px 6px rgba(16, 185, 129, 0.25) !important;
    }

    /* Minimalist Danger (Delete, Terminate) */
    .action-buttons .btn-danger,
    .action-buttons a[class*="btn-danger"] {
        background: rgba(239, 68, 68, 0.08) !important;
        color: #EF4444 !important;
        border-color: rgba(239, 68, 68, 0.22) !important;
    }
    .action-buttons .btn-danger:hover,
    .action-buttons a[class*="btn-danger"]:hover {
        background: #EF4444 !important;
        color: #ffffff !important;
        border-color: #EF4444 !important;
        box-shadow: 0 2px 6px rgba(239, 68, 68, 0.25) !important;
    }

    /* Minimalist Warning (Archive, Block) */
    .action-buttons .btn-warning,
    .action-buttons a[class*="btn-warning"] {
        background: rgba(245, 158, 11, 0.08) !important;
        color: #F59E0B !important;
        border-color: rgba(245, 158, 11, 0.22) !important;
    }
    .action-buttons .btn-warning:hover,
    .action-buttons a[class*="btn-warning"]:hover {
        background: #F59E0B !important;
        color: #ffffff !important;
        border-color: #F59E0B !important;
        box-shadow: 0 2px 6px rgba(245, 158, 11, 0.25) !important;
    }

    /* Minimalist Outline (Details, Download) */
    .action-buttons .btn-outline,
    .action-buttons a[class*="btn-outline"] {
        background: var(--bg) !important;
        color: var(--secondary-text) !important;
        border-color: var(--border) !important;
    }
    .action-buttons .btn-outline:hover,
    .action-buttons a[class*="btn-outline"]:hover {
        background: rgba(47, 128, 237, 0.08) !important;
        color: var(--primary) !important;
        border-color: var(--primary) !important;
    }

    /* Dark Mode Minimalist Buttons */
    [data-theme="dark"] .action-buttons .btn-primary {
        background: rgba(47, 128, 237, 0.16) !important;
        border-color: rgba(47, 128, 237, 0.32) !important;
    }
    [data-theme="dark"] .action-buttons .btn-success {
        background: rgba(16, 185, 129, 0.16) !important;
        border-color: rgba(16, 185, 129, 0.32) !important;
    }
    [data-theme="dark"] .action-buttons .btn-danger {
        background: rgba(239, 68, 68, 0.16) !important;
        border-color: rgba(239, 68, 68, 0.32) !important;
    }
    [data-theme="dark"] .action-buttons .btn-warning {
        background: rgba(245, 158, 11, 0.16) !important;
        border-color: rgba(245, 158, 11, 0.32) !important;
    }

    /* ============================================
       CANONICAL MAIN CONTENT & HEADBAR (TOP BAR)
       ============================================ */
    .main-content {
        margin-left: 260px !important;
        width: calc(100% - 260px) !important;
        padding: 24px 30px 40px !important;
        min-height: 100vh !important;
        box-sizing: border-box !important;
        transition: margin-left 0.25s ease, width 0.25s ease !important;
        max-width: 100% !important;
    }

    .top-bar {
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        margin-bottom: 24px !important;
        padding: 14px 22px !important;
        background: var(--card) !important;
        border-radius: 12px !important;
        border: 1px solid var(--border) !important;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04) !important;
        min-height: 64px !important;
        gap: 14px !important;
        flex-wrap: wrap !important;
        box-sizing: border-box !important;
    }

    .top-bar-left {
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
        min-width: 0 !important;
    }

    .page-title {
        min-width: 0 !important;
    }

    .page-title h1 {
        font-size: 20px !important;
        font-weight: 600 !important;
        color: var(--text) !important;
        margin: 0 !important;
        line-height: 1.25 !important;
        letter-spacing: -0.2px !important;
    }

    .page-title p {
        font-size: 13px !important;
        color: var(--secondary-text) !important;
        margin-top: 3px !important;
        margin-bottom: 0 !important;
        font-weight: 400 !important;
        line-height: 1.3 !important;
    }

    .top-bar-actions {
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
        margin-left: auto !important;
        flex-wrap: wrap !important;
    }

    .topbar-divider {
        width: 1px !important;
        height: 26px !important;
        background: var(--border) !important;
        margin: 0 4px !important;
        flex-shrink: 0 !important;
    }

    .top-bar-actions .topbar-divider:first-child {
        display: none !important;
    }

    /* Standard Button Styles */
    .top-bar-actions .btn {
        font-size: 13px !important;
        font-weight: 500 !important;
        padding: 7px 14px !important;
        border-radius: 8px !important;
        height: 36px !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 6px !important;
        text-decoration: none !important;
        box-sizing: border-box !important;
        cursor: pointer !important;
        transition: all 0.15s ease !important;
    }

    /* Headbar Controls */
    .theme-toggle {
        background: var(--bg) !important;
        border: 1px solid var(--border) !important;
        border-radius: 8px !important;
        width: 36px !important;
        height: 36px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        cursor: pointer !important;
        color: var(--text) !important;
        font-size: 14px !important;
        padding: 0 !important;
        transition: border-color 0.2s ease, color 0.2s ease !important;
        flex-shrink: 0 !important;
    }

    .theme-toggle:hover {
        border-color: var(--primary) !important;
        color: var(--primary) !important;
    }

    .user-profile {
        display: flex !important;
        align-items: center !important;
        gap: 9px !important;
        padding: 3px 12px 3px 3px !important;
        border-radius: 24px !important;
        background: var(--bg) !important;
        border: 1px solid var(--border) !important;
        cursor: default !important;
        flex-shrink: 0 !important;
    }

    .user-avatar {
        width: 30px !important;
        height: 30px !important;
        border-radius: 50% !important;
        background: var(--primary) !important;
        color: #fff !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-weight: 600 !important;
        font-size: 12.5px !important;
        flex-shrink: 0 !important;
    }

    .user-info {
        display: flex !important;
        flex-direction: column !important;
        text-align: left !important;
    }

    .user-info .name {
        font-size: 12.5px !important;
        font-weight: 600 !important;
        color: var(--text) !important;
        line-height: 1.2 !important;
        white-space: nowrap !important;
    }

    .user-info .role {
        font-size: 10.5px !important;
        color: var(--secondary-text) !important;
        line-height: 1.2 !important;
    }

    .sidebar-toggle-btn {
        display: none;
        background: var(--bg);
        border: 1px solid var(--border);
        border-radius: 8px;
        width: 36px;
        height: 36px;
        align-items: center;
        justify-content: center;
        font-size: 15px;
        color: var(--text);
        cursor: pointer;
        padding: 0;
        flex-shrink: 0;
    }

    .sidebar-toggle-btn:hover {
        border-color: var(--primary);
        color: var(--primary);
    }

    /* Mobile Responsive */
    @media (max-width: 992px) {
        .main-content {
            margin-left: 0 !important;
            width: 100% !important;
            padding: 16px !important;
        }

        .sidebar {
            transform: translateX(-100%) !important;
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
            position: fixed !important;
            z-index: 1000 !important;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15) !important;
        }

        .sidebar.open {
            transform: translateX(0) !important;
        }

        .sidebar-close {
            display: block !important;
        }

        .sidebar-toggle-btn {
            display: inline-flex !important;
        }

        .sidebar-overlay.active {
            display: block !important;
        }

        .user-info {
            display: none !important;
        }

        .user-profile {
            padding: 2px !important;
        }
    }
</style>

<script>
    // ============================================
    // UNIVERSAL THEME TOGGLE & HEADBAR SYNC
    // ============================================
    function setupThemeToggle() {
        var toggleBtns = document.querySelectorAll('#themeToggle, .theme-toggle');
        toggleBtns.forEach(function(btn) {
            btn.onclick = function(e) {
                e.preventDefault();
                var html = document.documentElement;
                var currentTheme = html.getAttribute('data-theme') || 'light';
                var nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
                html.setAttribute('data-theme', nextTheme);
                localStorage.setItem('globalscm_theme', nextTheme);

                // Update all theme button icons
                document.querySelectorAll('#themeToggle i, .theme-toggle i').forEach(function(icon) {
                    icon.className = nextTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
                });

                // Persist via backend API
                fetch('/api/settings.php?action=theme', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'theme=' + nextTheme
                }).catch(function() {});
            };
        });
    }

    // Apply stored theme if present
    (function() {
        var savedTheme = localStorage.getItem('globalscm_theme');
        if (savedTheme) {
            document.documentElement.setAttribute('data-theme', savedTheme);
        }
    })();

    // ============================================
    // ENSURE HEADBAR HAS USER PROFILE & CONTROLS
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        setupThemeToggle();

        var topBar = document.querySelector('.top-bar');
        var topBarActions = document.querySelector('.top-bar-actions');

        if (topBar && topBarActions) {
            // Check if user profile already exists
            if (!topBarActions.querySelector('.user-profile')) {
                var currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
                var iconClass = currentTheme === 'dark' ? 'fa-sun' : 'fa-moon';
                var fullName = '<?php echo addslashes(htmlspecialchars($_SESSION['full_name'] ?? 'Admin')); ?>';
                var userRole = '<?php echo addslashes(htmlspecialchars(ucfirst($_SESSION['role'] ?? 'Admin'))); ?>';
                var initial = fullName.charAt(0) || 'A';

                var wrapper = document.createElement('div');
                wrapper.style.display = 'contents';
                wrapper.innerHTML = 
                    '<div class="topbar-divider"></div>' +
                    '<button class="theme-toggle" id="themeToggle" type="button" title="Toggle Theme" aria-label="Toggle Theme">' +
                        '<i class="fas ' + iconClass + '"></i>' +
                    '</button>' +
                    '<div class="user-profile">' +
                        '<div class="user-avatar">' + initial + '</div>' +
                        '<div class="user-info">' +
                            '<div class="name">' + fullName + '</div>' +
                            '<div class="role">' + userRole + '</div>' +
                        '</div>' +
                    '</div>';

                topBarActions.appendChild(wrapper);
                setupThemeToggle();
            }

            // Ensure mobile menu button exists
            if (!topBar.querySelector('.sidebar-toggle-btn')) {
                var pageTitle = topBar.querySelector('.page-title');
                if (pageTitle) {
                    var toggleBtn = document.createElement('button');
                    toggleBtn.className = 'sidebar-toggle-btn';
                    toggleBtn.type = 'button';
                    toggleBtn.innerHTML = '<i class="fas fa-bars"></i>';
                    toggleBtn.setAttribute('aria-label', 'Toggle Sidebar');
                    toggleBtn.onclick = function() {
                        var sb = document.getElementById('sidebar');
                        var ov = document.getElementById('sidebarOverlay');
                        if (sb) sb.classList.toggle('open');
                        if (ov) ov.classList.toggle('active');
                    };
                    topBar.insertBefore(toggleBtn, pageTitle);
                }
            }
        }
    });

    // ============================================
    // SIDEBAR COLLAPSIBLE SECTIONS
    // ============================================
    function toggleSection(title) {
        var items = title.nextElementSibling;
        if (items && items.classList.contains('nav-items')) {
            var isCollapsed = items.classList.toggle('collapsed');
            var icon = title.querySelector('.collapse-icon');
            if (icon) {
                icon.classList.toggle('collapsed');
            }
            var sectionText = title.textContent.trim();
            localStorage.setItem('sidebar_section_' + sectionText, isCollapsed ? 'collapsed' : 'expanded');
        }
    }

    // Restore section states
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.nav-section-title').forEach(function(title) {
            var sectionText = title.textContent.trim();
            var state = localStorage.getItem('sidebar_section_' + sectionText);
            var items = title.nextElementSibling;
            if (state === 'collapsed' && items && items.classList.contains('nav-items')) {
                items.classList.add('collapsed');
                var icon = title.querySelector('.collapse-icon');
                if (icon) {
                    icon.classList.add('collapsed');
                }
            }
        });
    });

    // ============================================
    // QUICK CREATE DROPDOWN
    // ============================================
    function toggleQuickCreate() {
        var dropdown = document.getElementById('quickCreateDropdown');
        if (dropdown) dropdown.classList.toggle('show');
    }

    document.addEventListener('click', function(event) {
        if (!event.target.closest('.quick-create-dropdown')) {
            document.querySelectorAll('.quick-create-dropdown .dropdown-content').forEach(function(el) {
                el.classList.remove('show');
            });
        }
    });

    // ============================================
    // MOBILE CLOSE / OVERLAY HANDLERS
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        var sidebar = document.getElementById('sidebar');
        var overlay = document.getElementById('sidebarOverlay');
        var closeBtn = document.getElementById('sidebarClose');

        function closeNav() {
            if (sidebar) sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        if (closeBtn) closeBtn.onclick = closeNav;
        if (overlay) overlay.onclick = closeNav;

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeNav();
        });
    });
</script>

<?php
// Universal Minimalist Modal (Replaces browser "localhost says" dialogs)
include_once __DIR__ . '/modal.php';
?>