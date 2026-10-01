<?php
/**
 * includes/auth.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Central authentication & role-based access control helper.
 *
 * USAGE (at the top of every admin page, after require_once config/database.php):
 *
 *   require_once __DIR__ . '/../includes/auth.php';
 *   requireAuth('admin');                        // only admin
 *   requireAuth(['admin','warehouse_manager']);  // multiple roles
 *   requireAuth();                               // any logged-in user
 *
 * SESSION TIMEOUT: 2 minutes of inactivity (server-side).
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── 1. Session timeout constant (seconds) ───────────────────────────────────
if (!defined('SESSION_TIMEOUT')) {
    define('SESSION_TIMEOUT', 120); // 2 minutes
}

// ── 2. Role → allowed pages / modules map ───────────────────────────────────
// Each role lists the admin PHP filenames it may access.
// 'admin' is always granted everything (handled separately).
if (!defined('ROLE_PAGE_MAP')) {
    define('ROLE_PAGE_MAP', [
        'admin' => '*', // wildcard – all pages

        'warehouse_manager' => [
            'dashboard.php',
            'warehouses.php',
            'warehouse-zones.php',
            'inventory.php',
            'stock-movements.php',
            'reports.php',
        ],

        'procurement_officer' => [
            'dashboard.php',
            'suppliers.php',
            'purchase-orders.php',
            'requisitions.php',
            'contracts.php',
            'shipments.php',
            'documents.php',
            'reports.php',
        ],

        'inventory_clerk' => [
            'dashboard.php',
            'inventory.php',
            'stock-movements.php',
            'reports.php',
        ],

        'employer' => [
            'dashboard.php',
            'requisitions.php',
        ],
    ]);
}

// ── 3. Module-level permission map (used by hasPermission()) ─────────────────
// Defined here so it is the single source of truth.
if (!defined('ROLE_MODULE_PERMS')) {
    define('ROLE_MODULE_PERMS', [
        'admin' => '*',

        'warehouse_manager' => [
            'warehouse'  => ['view', 'create', 'edit', 'delete'],
            'inventory'  => ['view', 'create', 'edit'],
            'stock'      => ['view', 'create', 'edit'],
            'report'     => ['view'],
        ],

        'procurement_officer' => [
            'supplier'       => ['view', 'create', 'edit'],
            'purchase_order' => ['view', 'create', 'edit', 'approve'],
            'requisition'    => ['view', 'create', 'edit', 'approve'],
            'contract'       => ['view', 'create', 'edit'],
            'shipment'       => ['view', 'create', 'edit'],
            'document'       => ['view', 'create', 'edit'],
            'report'         => ['view'],
        ],

        'inventory_clerk' => [
            'inventory' => ['view', 'edit'],
            'stock'     => ['view', 'edit'],
            'report'    => ['view'],
        ],

        'employer' => [
            'requisition' => ['view', 'create'],
        ],
    ]);
}

// ── 4. Core helpers ──────────────────────────────────────────────────────────

/**
 * Check inactivity timeout; destroy session and redirect to login if expired.
 */
function checkSessionTimeout() {
    if (!isset($_SESSION['user_id'])) {
        return; // not logged in – nothing to check
    }

    $now = time();

    if (isset($_SESSION['last_activity'])) {
        $idle = $now - (int)$_SESSION['last_activity'];
        if ($idle >= SESSION_TIMEOUT) {
            // Expire the session
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();

            // Respond based on request type
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Session expired', 'redirect' => '../login.php?timeout=1']);
                exit();
            }

            // Determine relative depth
            $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
            $root   = dirname(dirname(__FILE__)); // project root
            $rel    = str_replace('\\', '/', str_replace($root, '', $script));
            $depth  = substr_count(ltrim($rel, '/'), '/');
            $prefix = str_repeat('../', $depth);

            header('Location: ' . $prefix . 'login.php?timeout=1');
            exit();
        }
    }

    $_SESSION['last_activity'] = $now;
}

/**
 * Return true if the current user's role is allowed to access $page.
 *
 * @param string $page  Basename of the PHP file (e.g. 'inventory.php')
 * @param string $role  Role string from session
 */
function roleCanAccessPage(string $page, string $role): bool {
    $role = strtolower(trim($role));
    $map  = ROLE_PAGE_MAP;

    if (!isset($map[$role])) {
        return false; // unknown role → deny
    }

    if ($map[$role] === '*') {
        return true;  // admin wildcard
    }

    return in_array($page, $map[$role], true);
}

/**
 * Guard a page. Call at the very top of every admin page.
 *
 * @param string|string[]|null $allowedRoles  Role(s) allowed. null = any logged-in user.
 */
function requireAuth($allowedRoles = null) {
    // Ensure session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Timeout check first
    checkSessionTimeout();

    // Must be logged in
    if (empty($_SESSION['user_id'])) {
        $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
        $root   = dirname(dirname(__FILE__));
        $rel    = str_replace('\\', '/', str_replace($root, '', $script));
        $depth  = substr_count(ltrim($rel, '/'), '/');
        $prefix = str_repeat('../', $depth);
        header('Location: ' . $prefix . 'login.php');
        exit();
    }

    $userRole = strtolower(trim($_SESSION['role'] ?? 'employer'));

    // Admins bypass everything
    if ($userRole === 'admin') {
        return;
    }

    // Role restriction check
    if ($allowedRoles !== null) {
        $allowed = is_array($allowedRoles)
            ? array_map('strtolower', $allowedRoles)
            : [strtolower($allowedRoles)];

        if (!in_array($userRole, $allowed, true)) {
            accessDenied();
        }
        return;
    }

    // Page-level RBAC check (automatic based on current file)
    $page = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    if (!roleCanAccessPage($page, $userRole)) {
        accessDenied();
    }
}

/**
 * Show a 403 Access Denied page and stop execution.
 */
function accessDenied() {
    http_response_code(403);
    $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
    $root   = dirname(dirname(__FILE__));
    $rel    = str_replace('\\', '/', str_replace($root, '', $script));
    $depth  = substr_count(ltrim($rel, '/'), '/');
    $prefix = str_repeat('../', $depth);

    $role      = htmlspecialchars($_SESSION['role'] ?? 'unknown');
    $full_name = htmlspecialchars($_SESSION['full_name'] ?? 'User');
    $page      = htmlspecialchars(basename($_SERVER['SCRIPT_FILENAME'] ?? ''));
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied – GlobalSCM</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:'Poppins',sans-serif;
            background:linear-gradient(135deg,#0F172A 0%,#1E3A5F 100%);
            min-height:100vh; display:flex; align-items:center; justify-content:center;
        }
        .card {
            background:#1E293B; border-radius:24px; padding:60px 50px;
            max-width:480px; width:100%; text-align:center;
            border:1px solid rgba(255,255,255,0.08);
            box-shadow:0 25px 60px rgba(0,0,0,0.4);
            animation:fadeIn .4s ease;
        }
        @keyframes fadeIn { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
        .icon { font-size:70px; margin-bottom:24px; }
        h1 { color:#F1F5F9; font-size:28px; font-weight:700; margin-bottom:8px; }
        .code { color:#EF4444; font-size:14px; font-weight:600; letter-spacing:2px;
                text-transform:uppercase; margin-bottom:20px; }
        p  { color:#94A3B8; font-size:15px; line-height:1.7; margin-bottom:8px; }
        .badge {
            display:inline-block; background:rgba(59,130,246,.15); color:#60A5FA;
            border:1px solid rgba(59,130,246,.3); border-radius:20px;
            padding:4px 14px; font-size:13px; font-weight:500; margin:12px 0 24px;
        }
        .btn {
            display:inline-flex; align-items:center; gap:8px;
            padding:12px 28px; border-radius:12px; font-family:'Poppins',sans-serif;
            font-size:15px; font-weight:600; text-decoration:none; cursor:pointer;
            border:none; transition:all .3s; margin:6px;
        }
        .btn-primary { background:#2F80ED; color:#fff; }
        .btn-primary:hover { background:#2563EB; transform:translateY(-2px);
                             box-shadow:0 8px 25px rgba(47,128,237,.35); }
        .btn-secondary { background:rgba(255,255,255,.06); color:#CBD5E1;
                         border:1px solid rgba(255,255,255,.12); }
        .btn-secondary:hover { background:rgba(255,255,255,.1); }
    </style>
</head>
<body>
<div class="card">
    <div class="icon">🔒</div>
    <div class="code">403 – Access Denied</div>
    <h1>You don't have access here</h1>
    <p>Hi <strong style="color:#E2E8F0"><?= $full_name ?></strong>, your role</p>
    <div class="badge"><i class="fas fa-user-tag"></i> <?= $role ?></div>
    <p>does not have permission to view <strong style="color:#E2E8F0"><?= $page ?></strong>.</p>
    <p style="margin-top:16px">Please contact your system administrator if you believe this is an error.</p>
    <div style="margin-top:32px">
        <a href="<?= $prefix ?>admin/dashboard.php" class="btn btn-primary">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="<?= $prefix ?>logout.php" class="btn btn-secondary">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</div>
</body>
</html>
    <?php
    exit();
}

/**
 * Overrides hasPermission() from function.php with the ROLE_MODULE_PERMS map.
 * Only defined if not already defined.
 */
if (!function_exists('hasPermission')) {
    function hasPermission($module, $action = 'view') {
        $role = strtolower(trim($_SESSION['role'] ?? 'employer'));

        if ($role === 'admin') return true;

        $map = ROLE_MODULE_PERMS;
        if (!isset($map[$role])) return false;
        if ($map[$role] === '*') return true;

        if (!isset($map[$role][$module])) return false;
        return in_array($action, $map[$role][$module], true);
    }
}

/**
 * Session timeout ping endpoint — call via AJAX to keep session alive.
 * Only refreshes last_activity; does NOT change the actual PHP session lifetime.
 */
if (!function_exists('sessionPing')) {
    function sessionPing() {
        if (!empty($_SESSION['user_id'])) {
            $_SESSION['last_activity'] = time();
            return true;
        }
        return false;
    }
}
