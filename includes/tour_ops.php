<?php
/**
 * includes/tour_ops.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Shared bootstrap for the Tour Operations extension modules:
 *   1. Fleet & Maintenance Spare Parts Inventory      (admin/fleet-parts.php)
 *   2. Demand Forecasting & Seasonal Stock Planning   (admin/demand-forecast.php)
 *   3. Tour Kit & Merchandise Kitting (BOM)           (admin/tour-kits.php)
 *   4. Asset Lifecycle, Tracking & Maintenance (ALTM) (admin/asset-tracking.php)
 *   5. Quality Inspection & Returns Management (RMA)  (admin/quality-returns.php)
 *   6. Service Contracting & Allotment Management     (admin/allotments.php)
 *
 * Provides: portable schema bootstrap (PostgreSQL / SQLite / MySQL),
 * stock-movement helper that respects the existing batch/FEFO rules,
 * and a shared page layout renderer.
 * ─────────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/tour_ops_roles.php';

if (!defined('TOUR_OPS_SCHEMA_VERSION')) {
    define('TOUR_OPS_SCHEMA_VERSION', '1');
}

// ============================================================================
// 1. Small helpers
// ============================================================================

function tsh($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function tsPayload(array $data): string
{
    return htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), ENT_QUOTES, 'UTF-8');
}

function tsDate($value): ?string
{
    $value = trim((string)($value ?? ''));
    if ($value === '') return null;
    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : null;
}

function tsInt($value, int $min = 0): int
{
    return max($min, (int)($value ?? 0));
}

function tsNum($value, float $min = 0.0): float
{
    return max($min, round((float)($value ?? 0), 4));
}

function tsStr($value, int $max = 255): string
{
    return mb_substr(trim((string)($value ?? '')), 0, $max);
}

function tsPick($value, array $allowed, string $default): string
{
    $value = (string)($value ?? '');
    return in_array($value, $allowed, true) ? $value : $default;
}

function tsMoney($amount): string
{
    if (function_exists('formatCurrency')) return formatCurrency($amount);
    return '₱' . number_format((float)$amount, 2);
}

function tsFmtDate($date, string $format = 'M d, Y'): string
{
    if (empty($date)) return '—';
    $ts = strtotime((string)$date);
    return $ts ? date($format, $ts) : '—';
}

function tsLabel(string $value): string
{
    return ucwords(str_replace(['_', '-'], ' ', $value));
}

function tsNextNumber(string $prefix): string
{
    return strtoupper($prefix) . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

function tsUserId(): ?int
{
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function tsDaysUntil(?string $date): ?int
{
    if (empty($date)) return null;
    $target = strtotime(substr((string)$date, 0, 10));
    if (!$target) return null;
    return (int)floor(($target - strtotime(date('Y-m-d'))) / 86400);
}

function tsFlash(string $type, string $message): void
{
    $_SESSION[$type === 'success' ? 'success' : 'error'] = $message;
}

function tsRedirect(string $url): void
{
    header('Location: ' . $url);
    exit();
}

function tsCsrfField(): string
{
    return '<input type="hidden" name="_token" value="' . tsh(generateCSRFToken()) . '">';
}

/** Validate CSRF for POST requests; on failure flash + redirect back. */
function tsRequireCsrf(string $redirect): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $token = (string)($_POST['_token'] ?? '');
    if ($token === '' || !verifyCSRFToken($token)) {
        tsFlash('error', 'Your session token expired. Please try again.');
        tsRedirect($redirect);
    }
}

function tsDriver(PDO $pdo): string
{
    $d = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if (in_array($d, ['pgsql', 'postgres', 'postgresql'], true)) return 'pgsql';
    if (in_array($d, ['mysql', 'mariadb'], true)) return 'mysql';
    return 'sqlite';
}

// ============================================================================
// 2. Lookup lists (fail-soft)
// ============================================================================

function tsSuppliers(PDO $pdo): array
{
    try {
        return $pdo->query("SELECT id, company_name, supplier_type FROM suppliers WHERE is_archived = 0 ORDER BY company_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function tsProducts(PDO $pdo): array
{
    try {
        return $pdo->query("SELECT id, sku, product_name, unit_measure, current_stock, reorder_point, unit_price FROM products WHERE is_archived = 0 ORDER BY product_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function tsWarehouses(PDO $pdo): array
{
    try {
        return $pdo->query("SELECT id, warehouse_code, name FROM warehouses WHERE is_archived = 0 AND status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function tsIndexBy(array $rows, string $key = 'id'): array
{
    $out = [];
    foreach ($rows as $r) $out[$r[$key]] = $r;
    return $out;
}

// ============================================================================
// 3. Settings helper
// ============================================================================

function tsSetSetting(PDO $pdo, string $key, string $value, string $group = 'tour_ops', string $desc = ''): void
{
    $st = $pdo->prepare('UPDATE system_settings SET setting_value = ? WHERE setting_key = ?');
    $st->execute([$value, $key]);
    if ($st->rowCount() === 0) {
        $chk = $pdo->prepare('SELECT 1 FROM system_settings WHERE setting_key = ?');
        $chk->execute([$key]);
        if (!$chk->fetchColumn()) {
            $ins = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, setting_group, description) VALUES (?, ?, ?, ?)');
            $ins->execute([$key, $value, $group, $desc]);
        }
    }
}

function tsGetSetting(PDO $pdo, string $key, ?string $default = null): ?string
{
    try {
        $st = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : (string)$v;
    } catch (Throwable $e) {
        return $default;
    }
}

// ============================================================================
// 4. Schema bootstrap (idempotent, version-gated so it runs once)
// ============================================================================

function tourOpsEnsureSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    if (tsGetSetting($pdo, 'tour_ops_schema_version') === TOUR_OPS_SCHEMA_VERSION) {
        return;
    }

    $driver = tsDriver($pdo);
    $types = [
        'pgsql' => ['{ID}' => 'BIGSERIAL PRIMARY KEY', '{TS}' => 'TIMESTAMPTZ', '{DATE}' => 'DATE', '{NUM}' => 'NUMERIC(15,2)', '{DEC}' => 'NUMERIC(12,4)'],
        'mysql' => ['{ID}' => 'INT AUTO_INCREMENT PRIMARY KEY', '{TS}' => 'TIMESTAMP NULL', '{DATE}' => 'DATE', '{NUM}' => 'DECIMAL(15,2)', '{DEC}' => 'DECIMAL(12,4)'],
        'sqlite' => ['{ID}' => 'INTEGER PRIMARY KEY AUTOINCREMENT', '{TS}' => 'TEXT', '{DATE}' => 'TEXT', '{NUM}' => 'REAL', '{DEC}' => 'REAL'],
    ][$driver];

    $ddl = [
        // ---- 1. Fleet & Maintenance Spare Parts ------------------------------
        "CREATE TABLE IF NOT EXISTS fleet_vehicles (
            id {ID},
            unit_code VARCHAR(50) NOT NULL UNIQUE,
            unit_type VARCHAR(30) DEFAULT 'tour_bus',
            make VARCHAR(100),
            model VARCHAR(100),
            model_year INTEGER,
            registration VARCHAR(60),
            meter_reading INTEGER DEFAULT 0,
            meter_unit VARCHAR(10) DEFAULT 'km',
            status VARCHAR(30) DEFAULT 'active',
            notes TEXT,
            is_archived INTEGER DEFAULT 0,
            created_at {TS} DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS fleet_parts (
            id {ID},
            part_number VARCHAR(80) NOT NULL UNIQUE,
            part_name VARCHAR(200) NOT NULL,
            category VARCHAR(50) DEFAULT 'general',
            oem_number VARCHAR(100),
            cross_references TEXT,
            compatible_models TEXT,
            unit_types VARCHAR(120),
            on_hand INTEGER DEFAULT 0,
            min_critical INTEGER DEFAULT 0,
            reorder_qty INTEGER DEFAULT 0,
            unit_cost {NUM} DEFAULT 0,
            supplier_id INTEGER,
            bin_location VARCHAR(80),
            is_core_tracked INTEGER DEFAULT 0,
            core_charge {NUM} DEFAULT 0,
            is_archived INTEGER DEFAULT 0,
            created_at {TS} DEFAULT CURRENT_TIMESTAMP,
            updated_at {TS} DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS fleet_part_transactions (
            id {ID},
            part_id INTEGER NOT NULL,
            vehicle_id INTEGER,
            txn_type VARCHAR(30) NOT NULL,
            quantity INTEGER NOT NULL,
            balance_after INTEGER DEFAULT 0,
            reference VARCHAR(120),
            notes TEXT,
            created_by INTEGER,
            created_at {TS} DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS fleet_service_schedules (
            id {ID},
            vehicle_id INTEGER NOT NULL,
            part_id INTEGER,
            task_name VARCHAR(200) NOT NULL,
            parts_per_service INTEGER DEFAULT 1,
            interval_meter INTEGER DEFAULT 0,
            interval_days INTEGER DEFAULT 0,
            last_service_date {DATE},
            last_service_meter INTEGER DEFAULT 0,
            next_due_date {DATE},
            next_due_meter INTEGER,
            status VARCHAR(20) DEFAULT 'active',
            notes TEXT,
            created_at {TS} DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS fleet_core_returns (
            id {ID},
            core_ref VARCHAR(60) NOT NULL UNIQUE,
            part_id INTEGER NOT NULL,
            vehicle_id INTEGER,
            supplier_id INTEGER,
            quantity INTEGER DEFAULT 1,
            core_charge {NUM} DEFAULT 0,
            status VARCHAR(20) DEFAULT 'pending',
            shipped_date {DATE},
            credit_amount {NUM} DEFAULT 0,
            credit_reference VARCHAR(100),
            notes TEXT,
            created_by INTEGER,
            created_at {TS} DEFAULT CURRENT_TIMESTAMP,
            updated_at {TS} DEFAULT CURRENT_TIMESTAMP
        )",

        // ---- 2. Demand Forecasting -------------------------------------------
        "CREATE TABLE IF NOT EXISTS forecast_booking_curve (
            id {ID},
            departure_date {DATE} NOT NULL,
            tour_type VARCHAR(100) NOT NULL,
            booked_pax INTEGER DEFAULT 0,
            expected_pax INTEGER DEFAULT 0,
            source VARCHAR(40) DEFAULT 'manual',
            external_ref VARCHAR(120),
            snapshot_date {DATE},
            created_at {TS} DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS forecast_consumables (
            id {ID},
            product_id INTEGER,
            item_name VARCHAR(200) NOT NULL,
            unit VARCHAR(30) DEFAULT 'pcs',
            usage_per_pax {DEC} DEFAULT 1,
            tour_type VARCHAR(100) DEFAULT 'ALL',
            lead_time_days INTEGER DEFAULT 7,
            service_level {DEC} DEFAULT 95,
            manual_on_hand INTEGER DEFAULT 0,
            is_archived INTEGER DEFAULT 0,
            created_at {TS} DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS forecast_seasons (
            id {ID},
            month_no INTEGER NOT NULL UNIQUE,
            season VARCHAR(20) DEFAULT 'shoulder',
            pickup_factor {DEC} DEFAULT 1,
            lead_time_factor {DEC} DEFAULT 1
        )",

        // ---- 3. Tour Kits / BOM ----------------------------------------------
        "CREATE TABLE IF NOT EXISTS tour_kits (
            id {ID},
            kit_code VARCHAR(60) NOT NULL UNIQUE,
            kit_name VARCHAR(200) NOT NULL,
            description TEXT,
            tour_type VARCHAR(100),
            kits_per_pax {DEC} DEFAULT 1,
            warehouse_id INTEGER,
            assembled_qty INTEGER DEFAULT 0,
            reserved_qty INTEGER DEFAULT 0,
            issued_qty INTEGER DEFAULT 0,
            status VARCHAR(20) DEFAULT 'active',
            is_archived INTEGER DEFAULT 0,
            created_by INTEGER,
            created_at {TS} DEFAULT CURRENT_TIMESTAMP,
            updated_at {TS} DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS tour_kit_components (
            id {ID},
            kit_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL DEFAULT 1,
            is_reusable INTEGER DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS tour_kit_reservations (
            id {ID},
            reservation_code VARCHAR(60) NOT NULL UNIQUE,
            kit_id INTEGER NOT NULL,
            tour_reference VARCHAR(120),
            departure_date {DATE},
            pax_count INTEGER DEFAULT 0,
            kits_reserved INTEGER DEFAULT 0,
            kits_returned INTEGER DEFAULT 0,
            status VARCHAR(20) DEFAULT 'reserved',
            guide_name VARCHAR(150),
            notes TEXT,
            created_by INTEGER,
            created_at {TS} DEFAULT CURRENT_TIMESTAMP,
            updated_at {TS} DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS tour_kit_transactions (
            id {ID},
            kit_id INTEGER NOT NULL,
            txn_type VARCHAR(30) NOT NULL,
            quantity INTEGER NOT NULL,
            reference VARCHAR(120),
            notes TEXT,
            created_by INTEGER,
            created_at {TS} DEFAULT CURRENT_TIMESTAMP
        )",

        // ---- 4. Asset Lifecycle, Tracking & Maintenance ----------------------
        "CREATE TABLE IF NOT EXISTS assets_register (
            id {ID},
            asset_tag VARCHAR(60) NOT NULL UNIQUE,
            asset_name VARCHAR(200) NOT NULL,
            category VARCHAR(50) DEFAULT 'other',
            serial_number VARCHAR(120),
            barcode VARCHAR(120),
            rfid_tag VARCHAR(120),
            home_location VARCHAR(150),
            acquisition_date {DATE},
            acquisition_cost {NUM} DEFAULT 0,
            salvage_value {NUM} DEFAULT 0,
            useful_life_months INTEGER DEFAULT 60,
            depreciation_method VARCHAR(30) DEFAULT 'straight_line',
            gl_asset_account VARCHAR(30) DEFAULT '1500',
            gl_accum_account VARCHAR(30) DEFAULT '1590',
            gl_expense_account VARCHAR(30) DEFAULT '6400',
            status VARCHAR(30) DEFAULT 'available',
            condition_rating VARCHAR(20) DEFAULT 'good',
            maintenance_interval_days INTEGER DEFAULT 180,
            next_maintenance_date {DATE},
            notes TEXT,
            is_archived INTEGER DEFAULT 0,
            created_at {TS} DEFAULT CURRENT_TIMESTAMP,
            updated_at {TS} DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS asset_checkouts (
            id {ID},
            asset_id INTEGER NOT NULL,
            assignee_name VARCHAR(150) NOT NULL,
            assignee_role VARCHAR(40) DEFAULT 'tour_guide',
            tour_reference VARCHAR(120),
            checked_out_at {TS} DEFAULT CURRENT_TIMESTAMP,
            expected_return {DATE},
            checked_in_at {TS},
            condition_out VARCHAR(20),
            condition_in VARCHAR(20),
            notes TEXT,
            checked_out_by INTEGER,
            checked_in_by INTEGER
        )",
        "CREATE TABLE IF NOT EXISTS asset_maintenance (
            id {ID},
            asset_id INTEGER NOT NULL,
            maint_type VARCHAR(40) DEFAULT 'inspection',
            scheduled_date {DATE},
            completed_date {DATE},
            performed_by VARCHAR(150),
            cost {NUM} DEFAULT 0,
            result VARCHAR(20) DEFAULT 'scheduled',
            certificate_ref VARCHAR(120),
            notes TEXT,
            created_by INTEGER,
            created_at {TS} DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS asset_depreciation_postings (
            id {ID},
            asset_id INTEGER NOT NULL,
            period VARCHAR(7) NOT NULL,
            amount {NUM} DEFAULT 0,
            accumulated {NUM} DEFAULT 0,
            book_value {NUM} DEFAULT 0,
            gl_debit VARCHAR(30),
            gl_credit VARCHAR(30),
            journal_ref VARCHAR(60),
            posted_by INTEGER,
            posted_at {TS} DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (asset_id, period)
        )",

        // ---- 5. Quality Inspection & RMA -------------------------------------
        "CREATE TABLE IF NOT EXISTS qc_inspections (
            id {ID},
            inspection_number VARCHAR(60) NOT NULL UNIQUE,
            po_id INTEGER,
            batch_id INTEGER,
            product_id INTEGER,
            supplier_id INTEGER,
            lot_quantity INTEGER DEFAULT 0,
            sample_size INTEGER DEFAULT 0,
            defects_found INTEGER DEFAULT 0,
            acceptance_number INTEGER DEFAULT 0,
            criteria_results TEXT,
            result VARCHAR(20) DEFAULT 'pending',
            disposition VARCHAR(30),
            inspector_name VARCHAR(150),
            notes TEXT,
            inspected_by INTEGER,
            inspected_at {TS} DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS rma_requests (
            id {ID},
            rma_number VARCHAR(60) NOT NULL UNIQUE,
            source VARCHAR(30) DEFAULT 'tour_guest',
            requester_name VARCHAR(150),
            contact VARCHAR(150),
            tour_reference VARCHAR(120),
            product_id INTEGER,
            supplier_id INTEGER,
            warehouse_id INTEGER,
            quantity INTEGER DEFAULT 1,
            reason VARCHAR(40) DEFAULT 'defective',
            description TEXT,
            status VARCHAR(20) DEFAULT 'requested',
            disposition VARCHAR(30),
            credit_expected {NUM} DEFAULT 0,
            resolution_notes TEXT,
            created_by INTEGER,
            created_at {TS} DEFAULT CURRENT_TIMESTAMP,
            updated_at {TS} DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS vendor_disputes (
            id {ID},
            dispute_number VARCHAR(60) NOT NULL UNIQUE,
            supplier_id INTEGER NOT NULL,
            rma_id INTEGER,
            inspection_id INTEGER,
            po_id INTEGER,
            dispute_type VARCHAR(40) DEFAULT 'quality',
            amount_claimed {NUM} DEFAULT 0,
            amount_settled {NUM} DEFAULT 0,
            description TEXT,
            status VARCHAR(20) DEFAULT 'open',
            resolution_notes TEXT,
            opened_by INTEGER,
            opened_at {TS} DEFAULT CURRENT_TIMESTAMP,
            resolved_at {TS}
        )",
        "CREATE TABLE IF NOT EXISTS credit_notes (
            id {ID},
            credit_note_number VARCHAR(80) NOT NULL,
            supplier_id INTEGER NOT NULL,
            dispute_id INTEGER,
            rma_id INTEGER,
            po_id INTEGER,
            amount {NUM} DEFAULT 0,
            issue_date {DATE},
            ap_reference VARCHAR(100),
            amount_applied {NUM} DEFAULT 0,
            ap_status VARCHAR(20) DEFAULT 'pending',
            reconciled_at {TS},
            reconciled_by INTEGER,
            notes TEXT,
            created_by INTEGER,
            created_at {TS} DEFAULT CURRENT_TIMESTAMP
        )",

        // ---- 6. Service Contracting & Allotments -----------------------------
        "CREATE TABLE IF NOT EXISTS service_contracts (
            id {ID},
            contract_code VARCHAR(60) NOT NULL UNIQUE,
            supplier_id INTEGER,
            procurement_contract_id INTEGER,
            service_type VARCHAR(40) DEFAULT 'hotel_room',
            service_name VARCHAR(200) NOT NULL,
            unit_label VARCHAR(40) DEFAULT 'room-night',
            start_date {DATE},
            end_date {DATE},
            release_days INTEGER DEFAULT 14,
            committed_units INTEGER DEFAULT 0,
            attrition_pct {DEC} DEFAULT 0,
            penalty_pct {DEC} DEFAULT 100,
            currency VARCHAR(10) DEFAULT 'PHP',
            status VARCHAR(20) DEFAULT 'active',
            notes TEXT,
            is_archived INTEGER DEFAULT 0,
            created_by INTEGER,
            created_at {TS} DEFAULT CURRENT_TIMESTAMP,
            updated_at {TS} DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS service_rate_tiers (
            id {ID},
            contract_id INTEGER NOT NULL,
            tier_name VARCHAR(100) NOT NULL,
            season VARCHAR(20) DEFAULT 'all',
            min_units INTEGER DEFAULT 0,
            max_units INTEGER,
            rate {NUM} DEFAULT 0,
            valid_from {DATE},
            valid_to {DATE}
        )",
        "CREATE TABLE IF NOT EXISTS service_allotments (
            id {ID},
            contract_id INTEGER NOT NULL,
            service_date {DATE} NOT NULL,
            units_allotted INTEGER DEFAULT 0,
            units_sold INTEGER DEFAULT 0,
            units_released INTEGER DEFAULT 0,
            release_date {DATE},
            rate_tier_id INTEGER,
            rate {NUM} DEFAULT 0,
            status VARCHAR(20) DEFAULT 'open',
            notes TEXT,
            created_at {TS} DEFAULT CURRENT_TIMESTAMP,
            updated_at {TS} DEFAULT CURRENT_TIMESTAMP
        )",
    ];

    $indexes = [
        'CREATE INDEX IF NOT EXISTS idx_fpt_part ON fleet_part_transactions (part_id)',
        'CREATE INDEX IF NOT EXISTS idx_fss_vehicle ON fleet_service_schedules (vehicle_id)',
        'CREATE INDEX IF NOT EXISTS idx_fbc_departure ON forecast_booking_curve (departure_date, tour_type)',
        'CREATE INDEX IF NOT EXISTS idx_tkc_kit ON tour_kit_components (kit_id)',
        'CREATE INDEX IF NOT EXISTS idx_tkr_kit ON tour_kit_reservations (kit_id, status)',
        'CREATE INDEX IF NOT EXISTS idx_aco_asset ON asset_checkouts (asset_id)',
        'CREATE INDEX IF NOT EXISTS idx_ama_asset ON asset_maintenance (asset_id)',
        'CREATE INDEX IF NOT EXISTS idx_rma_status ON rma_requests (status)',
        'CREATE INDEX IF NOT EXISTS idx_sal_contract ON service_allotments (contract_id, service_date)',
        'CREATE INDEX IF NOT EXISTS idx_srt_contract ON service_rate_tiers (contract_id)',
    ];

    try {
        foreach ($ddl as $sql) {
            $pdo->exec(strtr($sql, $types));
        }
        foreach ($indexes as $sql) {
            try { $pdo->exec($sql); } catch (Throwable $ignored) {}
        }

        // Seed a default tourism seasonality profile (Philippine tourism pattern):
        // dry-season peak Dec–May, wet-season trough Jun–Oct, shoulder Nov.
        $seasonCount = (int)$pdo->query('SELECT COUNT(*) FROM forecast_seasons')->fetchColumn();
        if ($seasonCount === 0) {
            $profile = [
                1 => ['peak', 1.35, 1.50], 2 => ['shoulder', 1.20, 1.25], 3 => ['peak', 1.35, 1.50],
                4 => ['peak', 1.40, 1.60], 5 => ['peak', 1.30, 1.40], 6 => ['off', 1.05, 1.00],
                7 => ['off', 1.05, 1.00], 8 => ['off', 1.05, 1.10], 9 => ['off', 1.00, 1.10],
                10 => ['off', 1.05, 1.00], 11 => ['shoulder', 1.15, 1.20], 12 => ['peak', 1.45, 1.70],
            ];
            $ins = $pdo->prepare('INSERT INTO forecast_seasons (month_no, season, pickup_factor, lead_time_factor) VALUES (?, ?, ?, ?)');
            foreach ($profile as $m => [$season, $pickup, $lead]) {
                $ins->execute([$m, $season, $pickup, $lead]);
            }
        }

        if (tsGetSetting($pdo, 'booking_ingest_api_key') === null) {
            tsSetSetting($pdo, 'booking_ingest_api_key', bin2hex(random_bytes(20)), 'integration', 'API key for Customer Booking forward-curve ingestion');
        }
        tsSetSetting($pdo, 'show_tour_operations', 'true', 'ui', 'Show/hide tour operations extension modules');

        tourOpsSeedDemoData($pdo);

        tsSetSetting($pdo, 'tour_ops_schema_version', TOUR_OPS_SCHEMA_VERSION, 'system', 'Tour operations extension schema version');
    } catch (Throwable $e) {
        error_log('[TOUR OPS SCHEMA] ' . $e->getMessage());
    }
}

function tourOpsSeedDemoData(PDO $pdo): void
{
    $d = function(string $mod) { return date('Y-m-d', strtotime($mod)); };

    try {
        // 1. Fleet Vehicles & Parts
        $vCount = (int)$pdo->query("SELECT COUNT(*) FROM fleet_vehicles")->fetchColumn();
        if ($vCount === 0) {
            $pdo->exec("
                INSERT INTO fleet_vehicles (unit_code, unit_type, make, model, model_year, registration, meter_reading, meter_unit, status, notes) VALUES
                ('TB-01', 'tour_bus', 'Scania', 'K410 Touring Coach (45-seater)', 2023, 'NAA-4821', 48200, 'km', 'active', 'Primary northern circuit bus with restroom & panoramic roof'),
                ('TB-02', 'tour_bus', 'Hino', 'RM 2P Luxury Coach (49-seater)', 2022, 'NBB-3914', 78400, 'km', 'active', 'Secondary inter-island transfer coach'),
                ('VN-04', 'van', 'Toyota', 'HiAce Grandia GL (12-seater)', 2024, 'NCC-1102', 18600, 'km', 'active', 'Airport VIP & private island hopping transfers'),
                ('VN-07', 'van', 'Nissan', 'NV350 Urvan Premium (15-seater)', 2023, 'NDD-8820', 36200, 'km', 'active', 'Mountain trekking trailhead shuttle'),
                ('SB-02', 'speedboat', 'Twin-Hull Marine', 'Offshore 38 (28-pax speedcraft)', 2023, 'MARINA-BOH-09', 640, 'hours', 'active', 'Twin Yamaha 250HP outboards; Balicasag marine sanctuary runs')
            ");
        }

        $pCount = (int)$pdo->query("SELECT COUNT(*) FROM fleet_parts")->fetchColumn();
        if ($pCount === 0) {
            $pdo->exec("
                INSERT INTO fleet_parts (part_number, part_name, category, oem_number, cross_references, compatible_models, unit_types, on_hand, min_critical, reorder_qty, unit_cost, bin_location, is_core_tracked, core_charge) VALUES
                ('BP-SC-202', 'Heavy Duty Ceramic Brake Pad Set', 'brakes', 'SC-2194038', 'BENDIX-HD-992, FERODO-FRP412', 'Scania K410, Hino RM 2P', 'tour_bus', 4, 6, 8, 4850.00, 'BIN-FL-A01', 0, 0),
                ('ALT-24V-150A', '24V 150A Alternator Assembly', 'electrical', 'BOSCH-0124655', 'DELCO-24SI-150, DENSO-24-15', 'Scania K410, Hino RM 2P', 'tour_bus', 1, 2, 3, 18500.00, 'BIN-FL-E04', 1, 3500.00),
                ('TIRE-295-80R22', 'Bridgestone R168 295/80R22.5 Radial', 'tires', 'BS-R168-295', 'MICHELIN-XZE2, GOODYEAR-KMAX', 'Scania K410, Hino RM 2P', 'tour_bus', 3, 4, 6, 16200.00, 'RACK-TIRE-02', 0, 0),
                ('PROP-YAM-250SS', 'Stainless Steel 3-Blade Marine Propeller 15x21', 'marine', 'YAM-6E5-45941', 'SOLAS-LEXOR-21, MERC-MIRAGE-PLUS', 'Twin Yamaha 250HP (Offshore 38)', 'speedboat', 2, 2, 4, 28900.00, 'BIN-MAR-01', 1, 4500.00),
                ('FF-TOY-HIACE', 'Fuel & Water Separator Filter Element', 'filters', 'TOY-23390-0L050', 'VIC-FC-184, SAKURA-FC-1114', 'Toyota HiAce Grandia, Nissan NV350', 'van', 14, 5, 20, 850.00, 'BIN-FL-F02', 0, 0)
            ");

            // Service schedules
            $pdo->exec("
                INSERT INTO fleet_service_schedules (vehicle_id, part_id, task_name, parts_per_service, interval_meter, interval_days, last_service_date, last_service_meter, next_due_date, next_due_meter, status) VALUES
                (1, 1, 'Front Axle Brake Inspection & Relining', 1, 20000, 180, '{$d('-150 days')}', 30000, '{$d('+30 days')}', 50000, 'active'),
                (1, 2, '24V Alternator & Belt Tension Overhaul', 1, 60000, 365, '{$d('-300 days')}', 0, '{$d('+65 days')}', 60000, 'active'),
                (3, 5, 'Diesel Fuel Filter & Water Trap Replacement', 1, 10000, 90, '{$d('-80 days')}', 10000, '{$d('+10 days')}', 20000, 'active'),
                (5, 4, 'Starboard Outboard Lower Unit & Propeller Check', 1, 200, 60, '{$d('-45 days')}', 500, '{$d('+15 days')}', 700, 'active')
            ");

            // Core return tracking
            $pdo->exec("
                INSERT INTO fleet_core_returns (core_ref, part_id, vehicle_id, supplier_id, quantity, core_charge, status, notes) VALUES
                ('CORE-26-001', 2, 2, 1, 1, 3500.00, 'pending', 'Replaced alternator unit from TB-02; ready for supplier credit exchange')
            ");
        }

        // 2. Demand Forecast Forward Bookings & Consumables
        $fCount = (int)$pdo->query("SELECT COUNT(*) FROM forecast_consumables")->fetchColumn();
        if ($fCount === 0) {
            $pdo->exec("
                INSERT INTO forecast_consumables (item_name, unit, usage_per_pax, tour_type, lead_time_days, service_level, manual_on_hand) VALUES
                ('Mineral Water Bottles 500ml', 'bottles', 2.0, 'ALL', 3, 98.0, 1200),
                ('Heavy Duty Waterproof Dry Bag 15L', 'pcs', 0.2, 'Island Hopping', 14, 95.0, 180),
                ('Snorkel Sanitized Silicone Sets', 'sets', 1.0, 'Island Hopping', 7, 99.0, 320),
                ('Marine Fuel Reserve (95 Octane)', 'liters', 3.5, 'Island Hopping', 2, 99.5, 850),
                ('Electrolyte Hydration Packs', 'sachets', 1.0, 'Mountain Trek', 5, 95.0, 450)
            ");

            $pdo->exec("
                INSERT INTO forecast_booking_curve (departure_date, tour_type, booked_pax, expected_pax, source, external_ref) VALUES
                ('{$d('+7 days')}', 'Island Hopping', 148, 180, 'Customer Booking API', 'BK-2026-WK43-01'),
                ('{$d('+14 days')}', 'Island Hopping', 195, 230, 'Customer Booking API', 'BK-2026-WK44-01'),
                ('{$d('+21 days')}', 'Island Hopping', 160, 210, 'Customer Booking API', 'BK-2026-WK45-01'),
                ('{$d('+7 days')}', 'Mountain Trek', 45, 60, 'Customer Booking API', 'BK-2026-TRK-01'),
                ('{$d('+14 days')}', 'Mountain Trek', 52, 70, 'Customer Booking API', 'BK-2026-TRK-02')
            ");
        }

        // 3. Tour Kits (BOM)
        $tkCount = (int)$pdo->query("SELECT COUNT(*) FROM tour_kits")->fetchColumn();
        if ($tkCount === 0) {
            $firstWh = (int)($pdo->query("SELECT id FROM warehouses WHERE is_archived = 0 ORDER BY id LIMIT 1")->fetchColumn() ?: 1);
            $pdo->exec("
                INSERT INTO tour_kits (kit_code, kit_name, description, tour_type, kits_per_pax, warehouse_id, assembled_qty, reserved_qty, issued_qty, status) VALUES
                ('KIT-ISLAND-01', 'Island Hopping Explorer Kit', 'Standard bundle for marine tours: 1 Dry bag, 1 Branded beach towel, 1 Snorkel set, 2 Water bottles', 'Island Hopping', 1.0, $firstWh, 85, 30, 20, 'active'),
                ('KIT-AUDIO-02', 'Historical City Audio Tour Pack', 'Reusable tourist bundle: 1 High-fidelity receiver headset, 1 Lanyard, 1 Printed heritage map', 'City Heritage', 1.0, $firstWh, 120, 45, 0, 'active')
            ");

            $pdo->exec("
                INSERT INTO tour_kit_reservations (reservation_code, kit_id, tour_reference, departure_date, pax_count, kits_reserved, guide_name, notes, status) VALUES
                ('RES-KIT-8801', 1, 'TOUR-BOH-2026-102', '{$d('+2 days')}', 30, 30, 'Captain Marlon Reyes', 'Reserved for 30 pax island hopping group', 'reserved')
            ");
        }

        // 4. Asset Lifecycle & Tracking (ALTM)
        $aCount = (int)$pdo->query("SELECT COUNT(*) FROM assets_register")->fetchColumn();
        if ($aCount === 0) {
            $pdo->exec("
                INSERT INTO assets_register (asset_tag, asset_name, category, serial_number, barcode, rfid_tag, home_location, acquisition_date, acquisition_cost, salvage_value, useful_life_months, status, condition_rating, maintenance_interval_days, next_maintenance_date) VALUES
                ('AST-SAT-001', 'Garmin inReach Explorer+ Satellite Communicator', 'electronics', 'SN-GRM-94821', 'BAR-AST-SAT-001', 'RFID-88102', 'Bohol Operations Center', '2025-01-15', 32000.00, 3200.00, 36, 'checked_out', 'excellent', 90, '{$d('+45 days')}'),
                ('AST-SAT-002', 'Iridium Extreme 9575 Satellite Phone', 'electronics', 'SN-IRD-10928', 'BAR-AST-SAT-002', 'RFID-88103', 'Cebu Main HQ', '2024-06-10', 88500.00, 8850.00, 48, 'available', 'good', 180, '{$d('+70 days')}'),
                ('AST-MED-003', 'Philips HeartStart FRx AED & Oxygen Delivery Pack', 'safety', 'SN-PHL-77401', 'BAR-AST-MED-003', 'RFID-99014', 'Balicasag Dive Boat Dock', '2024-11-20', 125000.00, 12500.00, 60, 'available', 'excellent', 90, '{$d('+20 days')}'),
                ('AST-TRL-001', 'Dual-Axle Covered Heavy Luggage Trailer (2.5 Ton)', 'trailers', 'VIN-TRL-PH-2023-01', 'BAR-AST-TRL-001', 'RFID-77190', 'Main Motorpool Yard', '2023-08-01', 245000.00, 24500.00, 84, 'available', 'good', 180, '{$d('+40 days')}'),
                ('AST-AUD-101', 'Sennheiser 20-Channel TourGuide Audio Master Transmitter', 'audio_guides', 'SN-SEN-55912', 'BAR-AST-AUD-101', 'RFID-44012', 'Intramuros Heritage Booth', '2025-03-01', 68000.00, 6800.00, 36, 'checked_out', 'good', 180, '{$d('+120 days')}')
            ");

            $pdo->exec("
                INSERT INTO asset_checkouts (asset_id, assignee_name, assignee_role, tour_reference, expected_return, condition_out, notes) VALUES
                (1, 'Lead Guide Rico Alcantara', 'tour_guide', 'EXP-PAL-2026-90', '{$d('+3 days')}', 'excellent', 'Issued for El Nido off-grid expedition'),
                (5, 'Guide Elena Morales', 'tour_guide', 'HRT-MNL-2026-11', '{$d('+1 days')}', 'good', 'Issued for Intramuros walking tour')
            ");
        }

        // 5. Quality Inspection & RMA
        $qCount = (int)$pdo->query("SELECT COUNT(*) FROM qc_inspections")->fetchColumn();
        if ($qCount === 0) {
            $pdo->exec("
                INSERT INTO qc_inspections (inspection_number, lot_quantity, sample_size, defects_found, acceptance_number, result, disposition, inspector_name, notes) VALUES
                ('QC-2026-001', 500, 50, 1, 2, 'passed', 'accepted', 'Engr. D. Santos', 'Batch inspection for 500 pcs Dry Bags; seams and clips passed tensile test.'),
                ('QC-2026-002', 120, 20, 5, 1, 'failed', 'vendor_return', 'Engr. D. Santos', 'Batch of 120 snorkel silicone purge valves failed pressure seal check. RMA initiated.')
            ");

            $pdo->exec("
                INSERT INTO rma_requests (rma_number, source, requester_name, contact, tour_reference, quantity, reason, description, status, credit_expected) VALUES
                ('RMA-2026-081', 'tour_guest', 'Guest Sarah Jenkins', '+61-491-570-159', 'TOUR-BOH-2026-102', 1, 'defective', 'Snorkel valve leaked saltwater during Coral Reef tour. Replacement issued on boat.', 'under_review', 1250.00),
                ('RMA-2026-082', 'warehouse', 'Main Distribution Center', 'internal@globalscm.com', 'PO-2026-044', 35, 'damaged', 'Defective purge valves identified during incoming goods receipt QC-2026-002.', 'requested', 31500.00)
            ");

            $pdo->exec("
                INSERT INTO vendor_disputes (dispute_number, supplier_id, rma_id, dispute_type, amount_claimed, amount_settled, description, status) VALUES
                ('DISP-2026-01', 1, 2, 'quality', 31500.00, 0, 'Claim for 35 defective snorkel units from Batch SNK-2026-03 pending credit note.', 'open')
            ");
        }

        // 6. Service Contracting & Allotments
        $scCount = (int)$pdo->query("SELECT COUNT(*) FROM service_contracts")->fetchColumn();
        if ($scCount === 0) {
            $pdo->exec("
                INSERT INTO service_contracts (contract_code, service_type, service_name, unit_label, start_date, end_date, release_days, committed_units, attrition_pct, penalty_pct, status) VALUES
                ('SC-HTL-BBC-26', 'hotel_room', 'Bohol Beach Club Deluxe Oceanfront Allotment', 'room-night', '2026-01-01', '2026-12-31', 14, 25, 10.0, 100.0, 'active'),
                ('SC-FRY-OCJ-26', 'ferry_pass', 'OceanJet Fast Ferry Cebu-Tagbilaran Tourist Class', 'passenger-ticket', '2026-01-01', '2026-12-31', 7, 50, 15.0, 50.0, 'active'),
                ('SC-GD-DIV-26', 'guide_service', 'Certified PADI Divemaster Retainer Services', 'guide-day', '2026-01-01', '2026-12-31', 3, 4, 0.0, 100.0, 'active')
            ");

            $pdo->exec("
                INSERT INTO service_rate_tiers (contract_id, tier_name, season, min_units, max_units, rate) VALUES
                (1, 'Standard Peak Season Rate', 'peak', 1, 30, 4800.00),
                (1, 'Off-Peak Group Discount', 'off', 1, 30, 3600.00),
                (2, 'Fixed Wholesale Ferry Seat Rate', 'all', 1, 100, 750.00)
            ");

            $pdo->exec("
                INSERT INTO service_allotments (contract_id, service_date, units_allotted, units_sold, units_released, release_date, rate, status) VALUES
                (1, '{$d('+12 days')}', 25, 20, 0, '{$d('-2 days')}', 4800.00, 'open'),
                (1, '{$d('+18 days')}', 25, 14, 0, '{$d('+4 days')}', 4800.00, 'open'),
                (2, '{$d('+5 days')}', 50, 48, 0, '{$d('-2 days')}', 750.00, 'open'),
                (3, '{$d('+8 days')}', 4, 4, 0, '{$d('+5 days')}', 3500.00, 'closed')
            ");
        }
    } catch (Throwable $e) {
        error_log('[TOUR OPS SEEDER] ' . $e->getMessage());
    }
}

// ============================================================================
// 5. Stock movement helper – mirrors admin/stock-movements.php semantics
// ============================================================================

/**
 * Move product stock in a warehouse by $delta (negative = issue, positive = return/receive).
 * Issues go through validateAndConsumeInventoryBatches() so FEFO / QC / expiry rules
 * remain enforced. Must be called inside an open transaction.
 */
function tsAdjustStock(PDO $pdo, int $productId, int $warehouseId, int $delta, string $txnType, string $note, string $reference): void
{
    if ($delta === 0) return;
    if ($productId < 1 || $warehouseId < 1) {
        throw new RuntimeException('A valid product and warehouse are required for stock movements.');
    }

    $balStmt = $pdo->prepare('SELECT quantity FROM warehouse_inventory WHERE product_id = ? AND warehouse_id = ?');
    $balStmt->execute([$productId, $warehouseId]);
    $balValue = $balStmt->fetchColumn();
    $previous = (int)($balValue ?: 0);

    if ($delta < 0) {
        $qty = -$delta;
        if ($previous < $qty) {
            $nameStmt = $pdo->prepare('SELECT product_name FROM products WHERE id = ?');
            $nameStmt->execute([$productId]);
            $name = (string)($nameStmt->fetchColumn() ?: ('#' . $productId));
            throw new RuntimeException("Insufficient stock for {$name}: need {$qty}, warehouse has {$previous}.");
        }
        if (function_exists('validateAndConsumeInventoryBatches')) {
            validateAndConsumeInventoryBatches($pdo, $productId, $warehouseId, $qty);
        }
    }

    $new = $previous + $delta;
    if ($balValue !== false) {
        $up = $pdo->prepare('UPDATE warehouse_inventory SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE product_id = ? AND warehouse_id = ?');
        $up->execute([$new, $productId, $warehouseId]);
    } else {
        $ins = $pdo->prepare('INSERT INTO warehouse_inventory (product_id, warehouse_id, quantity) VALUES (?, ?, ?)');
        $ins->execute([$productId, $warehouseId, $new]);
    }

    $agg = $pdo->prepare('UPDATE products SET current_stock = (SELECT COALESCE(SUM(quantity), 0) FROM warehouse_inventory WHERE product_id = ?) WHERE id = ?');
    $agg->execute([$productId, $productId]);

    $txn = $pdo->prepare('INSERT INTO inventory_transactions (product_id, transaction_type, quantity, previous_balance, new_balance, warehouse_id, notes, reference_document, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $txn->execute([$productId, $txnType, abs($delta), $previous, $new, $warehouseId, $note, $reference, tsUserId()]);
}

// ============================================================================
// 6. Layout renderer
// ============================================================================

/**
 * Render the shared page shell (head, sidebar, top bar, flashes, hero).
 * Options: title, subtitle, theme, icon, hero_title, hero_text, tags[], actions (html), chart (bool)
 */
function tsRenderHeader(array $o): void
{
    global $pdo;
    $theme = function_exists('getTheme') ? getTheme() : 'light';
    $title = $o['title'] ?? 'Tour Operations';
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo tsh($theme); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo tsh($title); ?> - Tour-Sphere SCM</title>
    <meta name="description" content="<?php echo tsh($o['subtitle'] ?? ''); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/tour-ops.css?v=<?php echo TOUR_OPS_SCHEMA_VERSION; ?>">
    <?php if (!empty($o['chart'])): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <?php endif; ?>
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . '/../admin/partials/sidebar.php'; ?>
    <main class="main-content">
        <header class="top-bar">
            <div class="page-title">
                <h1><?php echo tsh($title); ?></h1>
                <p><?php echo tsh($o['subtitle'] ?? ''); ?></p>
            </div>
            <div class="top-bar-actions">
                <?php echo $o['actions'] ?? ''; ?>
            </div>
        </header>

        <?php if (!empty($_SESSION['success'])): ?>
            <div class="ts-alert success" data-autohide><i class="fas fa-circle-check"></i><div><?php echo tsh($_SESSION['success']); ?></div></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['error'])): ?>
            <div class="ts-alert error"><i class="fas fa-circle-exclamation"></i><div><?php echo tsh($_SESSION['error']); ?></div></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (!empty($o['hero_title'])): ?>
        <section class="ts-hero <?php echo tsh($o['theme'] ?? ''); ?>">
            <div class="ts-hero-inner">
                <div class="ts-hero-icon"><i class="fas <?php echo tsh($o['icon'] ?? 'fa-cubes'); ?>"></i></div>
                <div style="flex:1; min-width:240px;">
                    <h2><?php echo tsh($o['hero_title']); ?></h2>
                    <p><?php echo tsh($o['hero_text'] ?? ''); ?></p>
                    <?php if (!empty($o['tags'])): ?>
                    <div class="ts-hero-tags">
                        <?php foreach ($o['tags'] as $tag): ?><span><?php echo tsh($tag); ?></span><?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>
    <?php
}

function tsRenderFooter(string $extraScript = ''): void
{
    ?>
    </main>
</div>
<script src="../assets/js/tour-ops.js?v=<?php echo TOUR_OPS_SCHEMA_VERSION; ?>"></script>
<?php echo $extraScript; ?>
</body>
</html>
    <?php
}

/** Render a small KPI card. */
function tsKpi(string $label, string $value, string $icon, string $tone = '', string $sub = ''): string
{
    return '<div class="ts-kpi ' . tsh($tone) . '"><div class="ts-kpi-icon"><i class="fas ' . tsh($icon) . '"></i></div>'
        . '<div class="ts-kpi-label">' . tsh($label) . '</div><div class="ts-kpi-value">' . tsh($value) . '</div>'
        . ($sub !== '' ? '<div class="ts-kpi-sub">' . tsh($sub) . '</div>' : '') . '</div>';
}

/** Render a status badge using a value→tone map. */
function tsBadge(string $value, array $tones = [], string $fallback = ''): string
{
    $tone = $tones[$value] ?? $fallback;
    return '<span class="ts-badge ' . tsh($tone) . '">' . tsh(tsLabel($value)) . '</span>';
}

/** Render <option> list. $rows may be [value => label] or list of rows with $valKey/$labelFn. */
function tsOptions(array $map, $selected = null, bool $blank = false, string $blankLabel = '— Select —'): string
{
    $html = $blank ? '<option value="">' . tsh($blankLabel) . '</option>' : '';
    foreach ($map as $val => $label) {
        $sel = ((string)$selected !== '' && (string)$selected === (string)$val) ? ' selected' : '';
        $html .= '<option value="' . tsh($val) . '"' . $sel . '>' . tsh($label) . '</option>';
    }
    return $html;
}

function tsSupplierMap(array $suppliers): array
{
    $m = [];
    foreach ($suppliers as $s) $m[$s['id']] = $s['company_name'];
    return $m;
}

function tsProductMap(array $products): array
{
    $m = [];
    foreach ($products as $p) $m[$p['id']] = $p['product_name'] . ' (' . ($p['sku'] ?: 'no SKU') . ')';
    return $m;
}

function tsWarehouseMap(array $warehouses): array
{
    $m = [];
    foreach ($warehouses as $w) $m[$w['id']] = $w['name'] . ' (' . $w['warehouse_code'] . ')';
    return $m;
}

/**
 * Standard page bootstrap: DB + auth + schema.
 */
function tourOpsBoot(string $page): PDO
{
    global $pdo;
    requireAuth(tourOpsRolesFor($page));
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection unavailable.');
    }
    tourOpsEnsureSchema($pdo);
    return $pdo;
}
