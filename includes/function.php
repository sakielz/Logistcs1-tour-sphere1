<?php
/**
 * includes/functions.php
 * Global helper functions for the application
 */

// Color constants
if (!defined('COLOR_PRIMARY')) define('COLOR_PRIMARY', '#2F80ED');
if (!defined('COLOR_SECONDARY')) define('COLOR_SECONDARY', '#56CCF2');
if (!defined('COLOR_ACCENT')) define('COLOR_ACCENT', '#27AE60');
if (!defined('COLOR_BG')) define('COLOR_BG', '#F8FAFC');
if (!defined('COLOR_CARD')) define('COLOR_CARD', '#FFFFFF');
if (!defined('COLOR_TEXT')) define('COLOR_TEXT', '#1F2937');
if (!defined('COLOR_SECONDARY_TEXT')) define('COLOR_SECONDARY_TEXT', '#6B7280');
if (!defined('COLOR_BORDER')) define('COLOR_BORDER', '#EEF2F7');
if (!defined('COLOR_DARK_BG')) define('COLOR_DARK_BG', '#0F172A');
if (!defined('COLOR_DARK_CARD')) define('COLOR_DARK_CARD', '#111827');
if (!defined('COLOR_DARK_TEXT')) define('COLOR_DARK_TEXT', '#E5E7EB');
if (!defined('COLOR_DARK_SECONDARY_TEXT')) define('COLOR_DARK_SECONDARY_TEXT', '#94A3B8');
if (!defined('COLOR_DARK_BORDER')) define('COLOR_DARK_BORDER', '#1F2937');

if (!function_exists('getTheme')) {
    function getTheme() {
        global $pdo;
        if (isset($_SESSION['theme'])) {
            return $_SESSION['theme'];
        }
        if ($pdo) {
            try {
                $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'theme' LIMIT 1");
                $val = $stmt ? $stmt->fetchColumn() : null;
                if ($val) {
                    return $val;
                }
            } catch (Throwable $e) {}
        }
        return 'light';
    }
}

// ============================================
// 1. Security Functions
// ============================================

/**
 * Sanitize input data
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Validate email
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate password strength
 */
function validatePassword($password, $minLength = 8) {
    $errors = [];
    
    if (strlen($password) < $minLength) {
        $errors[] = "Password must be at least {$minLength} characters long";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = "Password must contain at least one special character";
    }
    
    return $errors;
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate random string
 */
function generateRandomString($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

// ============================================
// 2. User & Session Functions
// ============================================

/**
 * Start the session when helper functions are loaded.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Normalize a role to a consistent string.
 */
function normalizeRole($role) {
    return strtolower(trim((string) ($role ?? '')));
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] !== '' && !empty($_SESSION['user_id']);
}

/**
 * Check if the current user has a specific role.
 */
function hasRole($role) {
    if (!isLoggedIn()) {
        return false;
    }

    return normalizeRole($_SESSION['role'] ?? '') === normalizeRole($role);
}

/**
 * Check if user is admin
 */
function isAdmin() {
    return hasRole('admin');
}

/**
 * Get current user data
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Check if user has permission
 */
function hasPermission($module, $action = 'view') {
    if (isAdmin()) {
        return true;
    }
    
    // Check user role permissions
    $rolePermissions = [
        'warehouse_manager' => [
            'warehouse' => ['view', 'edit', 'create'],
            'inventory' => ['view', 'edit', 'create'],
            'stock' => ['view', 'edit', 'create']
        ],
        'procurement_officer' => [
            'supplier' => ['view', 'edit', 'create'],
            'purchase_order' => ['view', 'edit', 'create', 'approve'],
            'requisition' => ['view', 'edit', 'create']
        ],
        'inventory_clerk' => [
            'inventory' => ['view', 'edit'],
            'stock' => ['view', 'edit']
        ],
        'employer' => [
            'requisition' => ['view', 'create']
        ]
    ];
    
    $role = isset($_SESSION['role']) ? $_SESSION['role'] : 'employer';
    if (isset($rolePermissions[$role]) && isset($rolePermissions[$role][$module])) {
        return in_array($action, $rolePermissions[$role][$module]);
    }
    
    return false;
}

// ============================================
// 3. Formatting Functions
// ============================================

/**
 * Format currency (Peso)
 */
function formatCurrency($amount) {
    $symbol = getSetting('currency_symbol', '₱');
    return $symbol . number_format((float)$amount, 2, '.', ',');
}

/**
 * Format date
 */
function formatDate($date, $format = 'M d, Y') {
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return 'N/A';
    }
    return date($format, strtotime($date));
}

/**
 * Format datetime
 */
function formatDateTime($date, $format = 'M d, Y h:i A') {
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return 'N/A';
    }
    return date($format, strtotime($date));
}

/**
 * Format file size
 */
function formatFileSize($bytes) {
    if ($bytes === 0 || empty($bytes)) {
        return '0 B';
    }
    
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

/**
 * Truncate text
 */
function truncateText($text, $length = 100, $suffix = '...') {
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . $suffix;
}

// ============================================
// 4. Database Helper Functions
// ============================================

/**
 * Get the active UI theme.
 */
function getTheme() {
    global $pdo;

    try {
        if (!$pdo) {
            return 'light';
        }

        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute(['theme']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $theme = $result['setting_value'] ?? 'light';
        return in_array($theme, ['light', 'dark'], true) ? $theme : 'light';
    } catch (Throwable $e) {
        return 'light';
    }
}

/**
 * Get a setting value
 */
function getSetting($key, $default = null) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Update a setting
 */
function updateSetting($key, $value) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
        return $stmt->execute([$value, $key]);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Archive a record (soft delete)
 */
function archiveRecord($table, $id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("UPDATE {$table} SET is_archived = 1 WHERE id = ?");
        return $stmt->execute([$id]);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Restore a record (undo soft delete)
 */
function restoreRecord($table, $id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("UPDATE {$table} SET is_archived = 0 WHERE id = ?");
        return $stmt->execute([$id]);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Log audit action
 */
function logAudit($userId, $action, $module, $description = '') {
    global $pdo;
    
    try {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, module, description, ip_address) VALUES (?, ?, ?, ?, ?)");
        return $stmt->execute([$userId, $action, $module, $description, $ip]);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get record count
 */
function getRecordCount($table, $where = '') {
    global $pdo;
    
    try {
        $query = "SELECT COUNT(*) as count FROM {$table}";
        if (!empty($where)) {
            $query .= " WHERE {$where}";
        }
        $stmt = $pdo->query($query);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['count'] : 0;
    } catch (Exception $e) {
        return 0;
    }
}

// ============================================
// 5. Notification Functions
// ============================================

/**
 * Set a flash message
 */
function setFlash($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Get and clear flash message
 */
function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Display flash message
 */
function displayFlash() {
    $flash = getFlash();
    if ($flash) {
        $type = $flash['type'];
        $message = htmlspecialchars($flash['message']);
        $icon = '';
        if ($type === 'success') {
            $icon = '✅';
        } elseif ($type === 'error') {
            $icon = '❌';
        } elseif ($type === 'warning') {
            $icon = '⚠️';
        } elseif ($type === 'info') {
            $icon = 'ℹ️';
        }
        echo '<div class="alert alert-' . $type . '">' . $icon . ' ' . $message . '</div>';
    }
}

// ============================================
// 6. File & Upload Functions
// ============================================

/**
 * Upload a file
 */
function uploadFile($file, $targetDir, $allowedTypes = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'png']) {
    $errors = [];
    
    // Check if file was uploaded
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload failed';
        return ['success' => false, 'errors' => $errors];
    }
    
    // Check file size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        $errors[] = 'File size exceeds 5MB limit';
    }
    
    // Check file type
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedTypes)) {
        $errors[] = 'File type not allowed. Allowed: ' . implode(', ', $allowedTypes);
    }
    
    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }
    
    // Generate unique filename
    $filename = uniqid() . '.' . $extension;
    $targetPath = rtrim($targetDir, '/') . '/' . $filename;
    
    // Create directory if it doesn't exist
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    
    // Move file
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return [
            'success' => true,
            'filename' => $filename,
            'path' => $targetPath,
            'original_name' => $file['name']
        ];
    }
    
    $errors[] = 'Failed to move uploaded file';
    return ['success' => false, 'errors' => $errors];
}

// ============================================
// 7. Pagination Function
// ============================================

/**
 * Generate pagination HTML
 */
function pagination($currentPage, $totalPages, $baseUrl = '') {
    if ($totalPages <= 1) {
        return '';
    }
    
    $html = '<div class="pagination">';
    $html .= '<ul class="pagination-list">';
    
    // Previous
    if ($currentPage > 1) {
        $html .= '<li><a href="' . $baseUrl . '?page=' . ($currentPage - 1) . '">&laquo; Previous</a></li>';
    }
    
    // Page numbers
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);
    
    if ($start > 1) {
        $html .= '<li><a href="' . $baseUrl . '?page=1">1</a></li>';
        if ($start > 2) {
            $html .= '<li><span>...</span></li>';
        }
    }
    
    for ($i = $start; $i <= $end; $i++) {
        if ($i == $currentPage) {
            $html .= '<li><span class="active">' . $i . '</span></li>';
        } else {
            $html .= '<li><a href="' . $baseUrl . '?page=' . $i . '">' . $i . '</a></li>';
        }
    }
    
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<li><span>...</span></li>';
        }
        $html .= '<li><a href="' . $baseUrl . '?page=' . $totalPages . '">' . $totalPages . '</a></li>';
    }
    
    // Next
    if ($currentPage < $totalPages) {
        $html .= '<li><a href="' . $baseUrl . '?page=' . ($currentPage + 1) . '">Next &raquo;</a></li>';
    }
    
    $html .= '</ul>';
    $html .= '</div>';
    
    return $html;
}

// ============================================
// 8. API Response Functions
// ============================================

/**
 * Send JSON response
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

/**
 * Send success response
 */
function successResponse($data = null, $message = 'Success') {
    $response = ['success' => true, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    jsonResponse($response);
}

/**
 * Send error response
 */
function errorResponse($message, $statusCode = 400) {
    jsonResponse(['success' => false, 'error' => $message], $statusCode);
}

// ============================================
// 9. Helper Functions
// ============================================

/**
 * Get client IP address
 */
function getClientIP() {
    $ip = '0.0.0.0';
    
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    
    return $ip;
}

/**
 * Get user agent
 */
function getUserAgent() {
    return isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown';
}

/**
 * Check if request is AJAX
 */
function isAjaxRequest() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Redirect to URL
 */
function appRedirect($url) {
    header('Location: ' . $url);
    exit();
}

/**
 * Get current URL
 */
function getCurrentURL() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    return $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
}