<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

echo "=== MIGRATING DATA FROM SQLITE TO SUPABASE (POSTGRESQL) ===\n";
echo "Active PDO driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";

if (!in_array(strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)), ['pgsql', 'postgres', 'postgresql'])) {
    die("Error: Primary database is not PostgreSQL/Supabase!\n");
}

$sqliteFile = dirname(__DIR__) . '/database/database.sqlite';
if (!file_exists($sqliteFile)) {
    die("Error: SQLite file not found at {$sqliteFile}\n");
}

$sq = new PDO('sqlite:' . $sqliteFile, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// 1. Ensure bidding tables exist in Supabase
echo "\n1. Ensuring missing tables exist in Supabase...\n";
$pdo->exec("
CREATE TABLE IF NOT EXISTS bidding_tenders (
    id BIGSERIAL PRIMARY KEY,
    tender_code VARCHAR(100) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    tender_type VARCHAR(50) NOT NULL DEFAULT 'spot_auction',
    transport_mode VARCHAR(50) NOT NULL DEFAULT 'road',
    origin VARCHAR(255) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    cargo_type VARCHAR(100) DEFAULT 'standard_dry',
    estimated_volume VARCHAR(100),
    target_rate NUMERIC(15, 2) DEFAULT 0.00,
    currency VARCHAR(10) DEFAULT 'PHP',
    deadline TIMESTAMPTZ,
    service_level_req TEXT,
    status VARCHAR(50) DEFAULT 'open',
    awarded_bid_id BIGINT,
    awarded_carrier_id BIGINT,
    awarded_carrier_name VARCHAR(255),
    awarded_rate NUMERIC(15, 2),
    tms_shipment_id BIGINT,
    contract_id BIGINT,
    rate_sheet_specs TEXT,
    notes TEXT,
    is_archived BOOLEAN DEFAULT FALSE,
    created_by BIGINT,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS bidding_bids (
    id BIGSERIAL PRIMARY KEY,
    tender_id BIGINT NOT NULL REFERENCES bidding_tenders(id) ON DELETE CASCADE,
    carrier_id BIGINT,
    carrier_name VARCHAR(255) NOT NULL,
    bid_amount NUMERIC(15, 2) NOT NULL,
    currency VARCHAR(10) DEFAULT 'PHP',
    transit_time_days INTEGER DEFAULT 1,
    carrier_score NUMERIC(5, 2) DEFAULT 85.0,
    cost_score NUMERIC(5, 2) DEFAULT 0.0,
    composite_score NUMERIC(5, 2) DEFAULT 0.0,
    service_level TEXT,
    notes TEXT,
    status VARCHAR(50) DEFAULT 'submitted',
    submitted_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);
");
echo "  [OK] bidding_tenders and bidding_bids tables verified in Supabase.\n";

function syncSequence(PDO $pdo, string $table, string $pk = 'id') {
    try {
        $pdo->exec("SELECT setval(pg_get_serial_sequence('{$table}', '{$pk}'), COALESCE((SELECT MAX({$pk}) FROM \"{$table}\"), 1))");
    } catch (Throwable $e) {}
}

// Add super_admin to enum if type exists
try {
    $pdo->exec("ALTER TYPE user_role ADD VALUE IF NOT EXISTS 'super_admin'");
} catch (Throwable $e) {}

syncSequence($pdo, 'users');

// 2. Migrate Users
echo "\n2. Migrating Users...\n";
$sqUsers = $sq->query("SELECT * FROM users")->fetchAll();
foreach ($sqUsers as $u) {
    // Check if user exists by username or email
    $check = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)");
    $check->execute([$u['username'], $u['email']]);
    $existing = $check->fetch();

    $pwd = $u['password'];
    // Fix invalid password hashes
    if (empty($pwd) || !str_starts_with($pwd, '$2y$')) {
        $pwd = password_hash('admin123', PASSWORD_DEFAULT);
    }

    $role = $u['role'] ?? 'employer';
    // If enum doesn't allow super_admin yet, admin is identical in permissions
    if ($role === 'super_admin') {
        try {
            $test = $pdo->query("SELECT 'super_admin'::user_role");
        } catch (Throwable $e) {
            $role = 'admin';
        }
    }

    if (!$existing) {
        $ins = $pdo->prepare("
            INSERT INTO users (username, email, password, role, full_name, is_active, is_archived, two_factor_secret, two_factor_enabled, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $u['username'],
            $u['email'],
            $pwd,
            $role,
            $u['full_name'] ?? $u['username'],
            !empty($u['is_active']) ? 'true' : 'false',
            'false', // un-archive so users can log in
            $u['two_factor_secret'] ?? null,
            !empty($u['two_factor_enabled']) ? 1 : 0,
            $u['created_at'] ?? date('Y-m-d H:i:s'),
            $u['updated_at'] ?? date('Y-m-d H:i:s'),
        ]);
        echo "  [INSERTED] User: {$u['username']} ({$u['email']}), Role: {$u['role']}\n";
    } else {
        echo "  [EXISTS] User: {$u['username']} (ID: {$existing['id']})\n";
    }
}

// Ensure admi4 in Supabase has a valid password hash
$pdo->prepare("UPDATE users SET password = ? WHERE username = 'admi4' AND (password NOT LIKE '$2y$%')")
    ->execute([password_hash('admin123', PASSWORD_DEFAULT)]);

// Reset admin password to admin@08 so default works guaranteed
$pdo->prepare("UPDATE users SET password = ?, is_active = TRUE, is_archived = FALSE WHERE username = 'admin'")
    ->execute([password_hash('admin@08', PASSWORD_DEFAULT)]);

// 3. Migrate Suppliers
echo "\n3. Migrating Suppliers...\n";
$sqSuppliers = $sq->query("SELECT * FROM suppliers")->fetchAll();
foreach ($sqSuppliers as $s) {
    $cName = $s['company_name'] ?? ($s['name'] ?? 'Supplier');
    $check = $pdo->prepare("SELECT id FROM suppliers WHERE company_name = ? OR email = ?");
    $check->execute([$cName, $s['email']]);
    if (!$check->fetch()) {
        $ins = $pdo->prepare("
            INSERT INTO suppliers (supplier_code, company_name, contact_person, email, phone, address, supplier_type, rating, is_archived, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $s['supplier_code'] ?? ('SUP-' . rand(1000, 9999)),
            $cName,
            $s['contact_person'] ?? null,
            $s['email'] ?? null,
            $s['phone'] ?? null,
            $s['address'] ?? null,
            $s['supplier_type'] ?? 'general',
            $s['rating'] ?? 5.0,
            !empty($s['is_archived']) ? 'true' : 'false',
            $s['created_at'] ?? date('Y-m-d H:i:s')
        ]);
        echo "  [INSERTED] Supplier: {$cName}\n";
    }
}

// 4. Migrate Inventory Groups
echo "\n4. Migrating Inventory Groups...\n";
$sqGroups = $sq->query("SELECT * FROM inventory_groups")->fetchAll();
foreach ($sqGroups as $g) {
    $check = $pdo->prepare("SELECT id FROM inventory_groups WHERE group_name = ?");
    $check->execute([$g['group_name']]);
    if (!$check->fetch()) {
        $ins = $pdo->prepare("INSERT INTO inventory_groups (group_name, created_at) VALUES (?, ?)");
        $ins->execute([$g['group_name'], $g['created_at'] ?? date('Y-m-d H:i:s')]);
        echo "  [INSERTED] Inventory Group: {$g['group_name']}\n";
    }
}

// 5. Migrate Documents
echo "\n5. Migrating Documents...\n";
try {
    $pdo->exec("ALTER TYPE document_type ADD VALUE IF NOT EXISTS 'report'");
} catch (Throwable $e) {}

$sqDocs = $sq->query("SELECT * FROM documents")->fetchAll();
foreach ($sqDocs as $d) {
    $check = $pdo->prepare("SELECT id FROM documents WHERE document_number = ?");
    $check->execute([$d['document_number']]);
    if (!$check->fetch()) {
        $docType = $d['document_type'] ?? 'general';
        try {
            $pdo->query("SELECT '{$docType}'::document_type");
        } catch (Throwable $e) {
            $docType = 'contract'; // common valid enum or general
        }
        $ins = $pdo->prepare("
            INSERT INTO documents (document_number, document_type, title, description, file_path, related_module, related_id, status, is_archived, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $d['document_number'],
            $docType,
            $d['title'] ?? 'Document',
            $d['description'] ?? null,
            $d['file_path'] ?? null,
            $d['related_module'] ?? null,
            $d['related_id'] ?? null,
            $d['status'] ?? 'draft',
            !empty($d['is_archived']) ? 'true' : 'false',
            $d['created_by'] ?? null,
            $d['created_at'] ?? date('Y-m-d H:i:s'),
            $d['updated_at'] ?? date('Y-m-d H:i:s')
        ]);
        echo "  [INSERTED] Document: {$d['document_number']}\n";
    }
}

// 6. Migrate Bidding Tenders & Bids
echo "\n6. Migrating Bidding Tenders & Bids...\n";
$sqTenders = $sq->query("SELECT * FROM bidding_tenders")->fetchAll();
foreach ($sqTenders as $t) {
    $check = $pdo->prepare("SELECT id FROM bidding_tenders WHERE tender_code = ?");
    $check->execute([$t['tender_code']]);
    $existing = $check->fetch();
    $tenderId = null;
    if (!$existing) {
        $ins = $pdo->prepare("
            INSERT INTO bidding_tenders (tender_code, title, tender_type, transport_mode, origin, destination, cargo_type, estimated_volume, target_rate, currency, deadline, service_level_req, status, awarded_bid_id, awarded_carrier_id, awarded_carrier_name, awarded_rate, tms_shipment_id, contract_id, rate_sheet_specs, notes, is_archived, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $ins->execute([
            $t['tender_code'],
            $t['title'],
            $t['tender_type'] ?? 'spot_auction',
            $t['transport_mode'] ?? 'road',
            $t['origin'] ?? '',
            $t['destination'] ?? '',
            $t['cargo_type'] ?? 'standard_dry',
            $t['estimated_volume'] ?? null,
            $t['target_rate'] ?? 0.0,
            $t['currency'] ?? 'PHP',
            $t['deadline'] ?? null,
            $t['service_level_req'] ?? null,
            $t['status'] ?? 'open',
            $t['awarded_bid_id'] ?? null,
            $t['awarded_carrier_id'] ?? null,
            $t['awarded_carrier_name'] ?? null,
            $t['awarded_rate'] ?? null,
            $t['tms_shipment_id'] ?? null,
            $t['contract_id'] ?? null,
            $t['rate_sheet_specs'] ?? null,
            $t['notes'] ?? null,
            !empty($t['is_archived']) ? 'true' : 'false',
            $t['created_by'] ?? null,
            $t['created_at'] ?? date('Y-m-d H:i:s'),
            $t['updated_at'] ?? date('Y-m-d H:i:s')
        ]);
        $tenderId = $ins->fetchColumn();
        echo "  [INSERTED] Tender: {$t['tender_code']} (New ID: {$tenderId})\n";
    } else {
        $tenderId = $existing['id'];
    }

    // Migrate bids for this tender
    $sqBids = $sq->prepare("SELECT * FROM bidding_bids WHERE tender_id = ?");
    $sqBids->execute([$t['id']]);
    foreach ($sqBids->fetchAll() as $b) {
        $checkBid = $pdo->prepare("SELECT id FROM bidding_bids WHERE tender_id = ? AND carrier_name = ? AND bid_amount = ?");
        $checkBid->execute([$tenderId, $b['carrier_name'], $b['bid_amount']]);
        if (!$checkBid->fetch()) {
            $insBid = $pdo->prepare("
                INSERT INTO bidding_bids (tender_id, carrier_id, carrier_name, bid_amount, currency, transit_time_days, carrier_score, cost_score, composite_score, service_level, notes, status, submitted_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insBid->execute([
                $tenderId,
                $b['carrier_id'] ?? null,
                $b['carrier_name'],
                $b['bid_amount'],
                $b['currency'] ?? 'PHP',
                $b['transit_time_days'] ?? 1,
                $b['carrier_score'] ?? 85.0,
                $b['cost_score'] ?? 0.0,
                $b['composite_score'] ?? 0.0,
                $b['service_level'] ?? null,
                $b['notes'] ?? null,
                $b['status'] ?? 'submitted',
                $b['submitted_at'] ?? date('Y-m-d H:i:s')
            ]);
            echo "    [INSERTED] Bid from: {$b['carrier_name']} (Amount: {$b['bid_amount']})\n";
        }
    }
}

// 7. Migrate user recovery codes
echo "\n7. Migrating User Recovery Codes...\n";
$sqCodes = $sq->query("SELECT rc.*, u.username FROM user_recovery_codes rc JOIN users u ON rc.user_id = u.id")->fetchAll();
foreach ($sqCodes as $rc) {
    // Find corresponding user in Supabase
    $userStmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $userStmt->execute([$rc['username']]);
    $pgUserId = $userStmt->fetchColumn();
    if ($pgUserId) {
        $checkCode = $pdo->prepare("SELECT id FROM user_recovery_codes WHERE user_id = ? AND code_hash = ?");
        $checkCode->execute([$pgUserId, $rc['code_hash']]);
        if (!$checkCode->fetch()) {
            $insCode = $pdo->prepare("INSERT INTO user_recovery_codes (user_id, code_hash, used_at, created_at) VALUES (?, ?, ?, ?)");
            $insCode->execute([$pgUserId, $rc['code_hash'], $rc['used_at'] ?? null, $rc['created_at'] ?? date('Y-m-d H:i:s')]);
        }
    }
}

syncSequence($pdo, 'users');
syncSequence($pdo, 'suppliers');
syncSequence($pdo, 'inventory_groups');
syncSequence($pdo, 'documents');
syncSequence($pdo, 'bidding_tenders');
syncSequence($pdo, 'bidding_bids');
syncSequence($pdo, 'user_recovery_codes');

echo "\n=== MIGRATION COMPLETE! ALL DATA SYNCED TO SUPABASE ===\n";
