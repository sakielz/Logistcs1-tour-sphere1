<?php
/**
 * includes/tour_ops_roles.php
 * Role map for the Tour Operations extension modules.
 * Kept in its own lightweight file so the sidebar can check access
 * without loading the schema bootstrap.
 */

if (!defined('TOUR_OPS_PAGE_ROLES')) {
    define('TOUR_OPS_PAGE_ROLES', [
        'fleet-parts.php' => ['warehouse_manager', 'inventory_controller', 'inventory_clerk', 'logistics_coordinator'],
        'demand-forecast.php' => ['warehouse_manager', 'inventory_controller', 'inventory_clerk', 'procurement_officer', 'procurement_specialist'],
        'tour-kits.php' => ['warehouse_manager', 'inventory_controller', 'inventory_clerk', 'logistics_coordinator'],
        'asset-tracking.php' => ['warehouse_manager', 'inventory_controller', 'logistics_coordinator'],
        'quality-returns.php' => ['warehouse_manager', 'inventory_controller', 'procurement_officer', 'procurement_specialist'],
        'allotments.php' => ['procurement_officer', 'procurement_specialist', 'logistics_coordinator'],
    ]);
}

if (!function_exists('tourOpsRolesFor')) {
    function tourOpsRolesFor(string $page): array
    {
        return array_merge(['admin', 'super_admin'], TOUR_OPS_PAGE_ROLES[$page] ?? []);
    }
}

if (!function_exists('tourOpsCanAccess')) {
    function tourOpsCanAccess(string $page): bool
    {
        $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
        return in_array($role, tourOpsRolesFor($page), true);
    }
}
