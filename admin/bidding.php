<?php
// admin/bidding.php
// Logistics Freight Bidding & Rate Management Module
// Connected to Procurement & Transportation Management System (TMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/function.php';

requireAuth(['admin', 'super_admin', 'procurement_officer']);

if (!defined('COLOR_PRIMARY')) define('COLOR_PRIMARY', '#2F80ED');
if (!defined('COLOR_SECONDARY')) define('COLOR_SECONDARY', '#56CCF2');
if (!defined('COLOR_ACCENT')) define('COLOR_ACCENT', '#27AE60');
if (!defined('COLOR_BG')) define('COLOR_BG', '#F8FAFC');
if (!defined('COLOR_CARD')) define('COLOR_CARD', '#FFFFFF');
if (!defined('COLOR_TEXT')) define('COLOR_TEXT', '#1F2937');
if (!defined('COLOR_SECONDARY_TEXT')) define('COLOR_SECONDARY_TEXT', '#6B7280');
if (!defined('COLOR_BORDER')) define('COLOR_BORDER', '#EEF2F7');

$theme = getTheme();
$currentUserId = $_SESSION['user_id'] ?? 1;

// ── Ensure Bidding Tables Exist (Auto-migration safeguard) ───────────────────
try {
    $driverName = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if (in_array($driverName, ['pgsql', 'postgres', 'postgresql'], true)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS bidding_tenders (
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
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS bidding_bids (
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
        );");
    } elseif (in_array($driverName, ['mysql', 'mariadb'], true)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS bidding_tenders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tender_code VARCHAR(100) UNIQUE NOT NULL,
            title VARCHAR(255) NOT NULL,
            tender_type VARCHAR(50) NOT NULL DEFAULT 'spot_auction',
            transport_mode VARCHAR(50) NOT NULL DEFAULT 'road',
            origin VARCHAR(255) NOT NULL,
            destination VARCHAR(255) NOT NULL,
            cargo_type VARCHAR(100) DEFAULT 'standard_dry',
            estimated_volume VARCHAR(100),
            target_rate DECIMAL(15, 2) DEFAULT 0.00,
            currency VARCHAR(10) DEFAULT 'PHP',
            deadline DATETIME,
            service_level_req TEXT,
            status VARCHAR(50) DEFAULT 'open',
            awarded_bid_id INT,
            awarded_carrier_id INT,
            awarded_carrier_name VARCHAR(255),
            awarded_rate DECIMAL(15, 2),
            tms_shipment_id INT,
            contract_id INT,
            rate_sheet_specs TEXT,
            notes TEXT,
            is_archived TINYINT(1) DEFAULT 0,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS bidding_bids (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tender_id INT NOT NULL,
            carrier_id INT,
            carrier_name VARCHAR(255) NOT NULL,
            bid_amount DECIMAL(15, 2) NOT NULL,
            currency VARCHAR(10) DEFAULT 'PHP',
            transit_time_days INT DEFAULT 1,
            carrier_score DECIMAL(5, 2) DEFAULT 85.0,
            cost_score DECIMAL(5, 2) DEFAULT 0.0,
            composite_score DECIMAL(5, 2) DEFAULT 0.0,
            service_level TEXT,
            notes TEXT,
            status VARCHAR(50) DEFAULT 'submitted',
            submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tender_id) REFERENCES bidding_tenders(id) ON DELETE CASCADE
        );");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS bidding_tenders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tender_code TEXT UNIQUE NOT NULL,
            title TEXT NOT NULL,
            tender_type TEXT NOT NULL DEFAULT 'spot_auction',
            transport_mode TEXT NOT NULL DEFAULT 'road',
            origin TEXT NOT NULL,
            destination TEXT NOT NULL,
            cargo_type TEXT DEFAULT 'standard_dry',
            estimated_volume TEXT,
            target_rate REAL DEFAULT 0.00,
            currency TEXT DEFAULT 'PHP',
            deadline TEXT,
            service_level_req TEXT,
            status TEXT DEFAULT 'open',
            awarded_bid_id INTEGER,
            awarded_carrier_id INTEGER,
            awarded_carrier_name TEXT,
            awarded_rate REAL,
            tms_shipment_id INTEGER,
            contract_id INTEGER,
            rate_sheet_specs TEXT,
            notes TEXT,
            is_archived INTEGER DEFAULT 0,
            created_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS bidding_bids (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tender_id INTEGER NOT NULL,
            carrier_id INTEGER,
            carrier_name TEXT NOT NULL,
            bid_amount REAL NOT NULL,
            currency TEXT DEFAULT 'PHP',
            transit_time_days INTEGER DEFAULT 1,
            carrier_score REAL DEFAULT 85.0,
            cost_score REAL DEFAULT 0.0,
            composite_score REAL DEFAULT 0.0,
            service_level TEXT,
            notes TEXT,
            status TEXT DEFAULT 'submitted',
            submitted_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tender_id) REFERENCES bidding_tenders(id) ON DELETE CASCADE
        );");
    }
} catch (Throwable $t) {
    error_log("[BIDDING INIT NOTICE] " . $t->getMessage());
}

// ── Seed demo carrier records if none exist ─────────────────────────────────
try {
    $checkTenders = $pdo->query("SELECT COUNT(*) FROM bidding_tenders")->fetchColumn();
    if ($checkTenders == 0) {
        // Ensure at least 1 carrier supplier exists
        $carrierCheck = $pdo->query("SELECT id FROM suppliers WHERE supplier_type = 'carrier' LIMIT 1")->fetch();
        $carrier1Id = $carrierCheck ? (int)$carrierCheck['id'] : null;

        if (!$carrier1Id) {
            $pdo->exec("INSERT INTO suppliers (supplier_code, company_name, supplier_type, contact_person, email, phone, status, rating, is_archived, created_by)
                VALUES ('SUP-CAR-01', 'FastCargo Logistics Express', 'carrier', 'Ramon Santos', 'dispatch@fastcargoph.com', '+63 917 123 4567', 'active', 4.8, 0, $currentUserId)");
            $carrier1Id = (int)$pdo->lastInsertId();

            $pdo->exec("INSERT INTO suppliers (supplier_code, company_name, supplier_type, contact_person, email, phone, status, rating, is_archived, created_by)
                VALUES ('SUP-CAR-02', 'TransLuzon Freight Haulers', 'carrier', 'Elena Ramos', 'ops@transluzon.com', '+63 918 987 6543', 'active', 4.5, 0, $currentUserId)");
            $carrier2Id = (int)$pdo->lastInsertId();

            $pdo->exec("INSERT INTO suppliers (supplier_code, company_name, supplier_type, contact_person, email, phone, status, rating, is_archived, created_by)
                VALUES ('SUP-CAR-03', 'Apex Maritime & Land Transport', 'carrier', 'Victor Cruz', 'quotes@apexlogistics.ph', '+63 920 555 1234', 'active', 4.2, 0, $currentUserId)");
            $carrier3Id = (int)$pdo->lastInsertId();
        } else {
            $carrier2Id = $carrier1Id;
            $carrier3Id = $carrier1Id;
        }

        // Demo Tender 1: Spot Auction
        $d1 = date('Y-m-d H:i:s', strtotime('+3 days'));
        $pdo->exec("INSERT INTO bidding_tenders (tender_code, title, tender_type, transport_mode, origin, destination, cargo_type, estimated_volume, target_rate, deadline, service_level_req, status, rate_sheet_specs, notes, created_by)
            VALUES ('SPOT-2026-001', 'Manila Distribution Hub to Clark Logistics Hub Run', 'spot_auction', 'road', 'Pasig Central Hub, Metro Manila', 'Clark Freeport Logistics Center, Pampanga', 'tour_equipment', '3,500 kg (6-Wheeler Forward Truck)', 28000.00, '$d1', 'Guaranteed 24-hr Door-to-Door Delivery', 'open', 'Standard Rate Sheet: Base haulage rate, inclusive of fuel surcharge, toll fees, and loading/unloading assistance.', 'Urgent equipment staging for Northern Luzon Tour batches.', $currentUserId)");
        $t1Id = (int)$pdo->lastInsertId();

        $pdo->exec("INSERT INTO bidding_bids (tender_id, carrier_id, carrier_name, bid_amount, transit_time_days, carrier_score, cost_score, composite_score, service_level, notes)
            VALUES ($t1Id, $carrier1Id, 'FastCargo Logistics Express', 24500.00, 1, 92.5, 60.0, 97.0, 'Express 24h Guaranteed w/ Real-time GPS Tracker', 'Includes North Luzon expressway tolls and cargo insurance cover.')");

        $pdo->exec("INSERT INTO bidding_bids (tender_id, carrier_id, carrier_name, bid_amount, transit_time_days, carrier_score, cost_score, composite_score, service_level, notes)
            VALUES ($t1Id, $carrier2Id, 'TransLuzon Freight Haulers', 26000.00, 1, 88.0, 56.5, 91.7, 'Standard Direct Delivery', 'Includes driver helper and digital POD.')");

        $pdo->exec("INSERT INTO bidding_bids (tender_id, carrier_id, carrier_name, bid_amount, transit_time_days, carrier_score, cost_score, composite_score, service_level, notes)
            VALUES ($t1Id, $carrier3Id, 'Apex Maritime & Land Transport', 29500.00, 2, 82.0, 49.8, 82.6, 'Standard 48-hr Regional Freight', 'Rate subject to diesel fluctuation adjustment.')");

        // Demo Tender 2: Contract Tender
        $d2 = date('Y-m-d H:i:s', strtotime('+7 days'));
        $pdo->exec("INSERT INTO bidding_tenders (tender_code, title, tender_type, transport_mode, origin, destination, cargo_type, estimated_volume, target_rate, deadline, service_level_req, status, rate_sheet_specs, notes, created_by)
            VALUES ('TND-2026-Q4', 'Bulacan Central to Northern Luzon Tourism Corridors', 'contract_tender', 'road', 'Bulacan Central Warehouse', 'Baguio, La Union, & Ilocos Depots', 'standard_dry', '60,000 kg Monthly Dedicated Fleet', 420000.00, '$d2', 'Bi-weekly Scheduled Freight Tender with 98% OTIF', 'open', 'Quarterly Long-Term Contract Rate Sheet: Fixed rate per trip per route with quarterly fuel index adjustments.', 'Long-term tender for Q4 operational tourist transport supply replenishment.', $currentUserId)");
        $t2Id = (int)$pdo->lastInsertId();

        $pdo->exec("INSERT INTO bidding_bids (tender_id, carrier_id, carrier_name, bid_amount, transit_time_days, carrier_score, cost_score, composite_score, service_level, notes)
            VALUES ($t2Id, $carrier1Id, 'FastCargo Logistics Express', 398000.00, 2, 94.0, 59.5, 97.1, 'Dedicated 10-Wheeler Fleet + Temperature Sensor Telematics', 'Quarterly locked rate with guaranteed replacement vehicle in 3 hours.')");

        $pdo->exec("INSERT INTO bidding_bids (tender_id, carrier_id, carrier_name, bid_amount, transit_time_days, carrier_score, cost_score, composite_score, service_level, notes)
            VALUES ($t2Id, $carrier2Id, 'TransLuzon Freight Haulers', 415000.00, 2, 89.0, 57.1, 92.7, 'Standard Contract Freight', 'Fixed monthly billing with 30-day payment term.')");
    }
} catch (Throwable $t) {
    // demo seeding error ignored
}

// ── Handle Actions ───────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$error = '';
$success = '';

// 1. Create New Tender
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_tender') {
    $tender_code = trim($_POST['tender_code'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $tender_type = $_POST['tender_type'] ?? 'spot_auction';
    $transport_mode = $_POST['transport_mode'] ?? 'road';
    $origin = trim($_POST['origin'] ?? '');
    $destination = trim($_POST['destination'] ?? '');
    $cargo_type = $_POST['cargo_type'] ?? 'standard_dry';
    $estimated_volume = trim($_POST['estimated_volume'] ?? '');
    $target_rate = (float)($_POST['target_rate'] ?? 0);
    $deadline = trim($_POST['deadline'] ?? '');
    $service_level_req = trim($_POST['service_level_req'] ?? '');
    $rate_sheet_specs = trim($_POST['rate_sheet_specs'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($tender_code === '') {
        $prefix = $tender_type === 'spot_auction' ? 'SPOT-' : 'TND-';
        $tender_code = $prefix . date('Ymd') . '-' . rand(100, 999);
    }

    try {
        if ($title === '' || $origin === '' || $destination === '') {
            throw new RuntimeException('Please enter a title, origin, and destination.');
        }

        $stmt = $pdo->prepare("INSERT INTO bidding_tenders 
            (tender_code, title, tender_type, transport_mode, origin, destination, cargo_type, estimated_volume, target_rate, deadline, service_level_req, rate_sheet_specs, notes, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?)");
        $stmt->execute([
            $tender_code, $title, $tender_type, $transport_mode, $origin, $destination, 
            $cargo_type, $estimated_volume, $target_rate, $deadline, $service_level_req, 
            $rate_sheet_specs, $notes, $currentUserId
        ]);

        logAudit($currentUserId, 'create_tender', 'bidding', "Created freight tender $tender_code: $title");
        $_SESSION['success'] = "Freight Tender <strong>$tender_code</strong> created successfully!";
        header('Location: bidding.php');
        exit();
    } catch (Throwable $e) {
        $error = "Error creating tender: " . $e->getMessage();
    }
}

// 2. Submit Carrier Bid
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'submit_bid') {
    $tender_id = (int)($_POST['tender_id'] ?? 0);
    $carrier_id = !empty($_POST['carrier_id']) ? (int)$_POST['carrier_id'] : null;
    $carrier_name = trim($_POST['carrier_name'] ?? '');
    $bid_amount = (float)($_POST['bid_amount'] ?? 0);
    $transit_time_days = (int)($_POST['transit_time_days'] ?? 1);
    $carrier_score = (float)($_POST['carrier_score'] ?? 85.0);
    $service_level = trim($_POST['service_level'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    try {
        if ($tender_id <= 0 || $bid_amount <= 0) {
            throw new RuntimeException('Please specify a valid tender and bid amount.');
        }

        if ($carrier_id > 0 && $carrier_name === '') {
            $cStmt = $pdo->prepare("SELECT company_name, rating FROM suppliers WHERE id = ?");
            $cStmt->execute([$carrier_id]);
            $cRow = $cStmt->fetch();
            if ($cRow) {
                $carrier_name = $cRow['company_name'];
                if ($carrier_score === 85.0 && !empty($cRow['rating'])) {
                    $carrier_score = min(100.0, max(50.0, ((float)$cRow['rating'] / 5.0) * 100.0));
                }
            }
        }

        if ($carrier_name === '') {
            throw new RuntimeException('Please provide the carrier or forwarder name.');
        }

        // Get target rate for initial cost score
        $tStmt = $pdo->prepare("SELECT target_rate FROM bidding_tenders WHERE id = ?");
        $tStmt->execute([$tender_id]);
        $targetRate = (float)$tStmt->fetchColumn();

        $costScore = $bid_amount > 0 && $targetRate > 0 
            ? min(60.0, ($targetRate / $bid_amount) * 60.0) 
            : 50.0;
        $compositeScore = round($costScore + ($carrier_score * 0.4), 1);

        $stmt = $pdo->prepare("INSERT INTO bidding_bids 
            (tender_id, carrier_id, carrier_name, bid_amount, transit_time_days, carrier_score, cost_score, composite_score, service_level, notes, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted')");
        $stmt->execute([
            $tender_id, $carrier_id, $carrier_name, $bid_amount, $transit_time_days, 
            $carrier_score, $costScore, $compositeScore, $service_level, $notes
        ]);

        logAudit($currentUserId, 'submit_bid', 'bidding', "Carrier $carrier_name quoted ₱" . number_format($bid_amount, 2) . " for Tender #$tender_id");
        $_SESSION['success'] = "Bid from <strong>$carrier_name</strong> recorded successfully!";
        header('Location: bidding.php?tender_id=' . $tender_id . '#tender-' . $tender_id);
        exit();
    } catch (Throwable $e) {
        $error = "Error submitting bid: " . $e->getMessage();
    }
}

// 3. Award Winning Bid & Push Directly into TMS (Shipment) & Procurement (Contract)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'award_bid') {
    $tender_id = (int)($_POST['tender_id'] ?? 0);
    $bid_id = (int)($_POST['bid_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        $bStmt = $pdo->prepare("SELECT b.*, t.tender_code, t.title, t.tender_type, t.transport_mode, t.origin, t.destination, t.cargo_type, t.estimated_volume
            FROM bidding_bids b 
            JOIN bidding_tenders t ON b.tender_id = t.id 
            WHERE b.id = ? AND b.tender_id = ?");
        $bStmt->execute([$bid_id, $tender_id]);
        $bidData = $bStmt->fetch();

        if (!$bidData) {
            throw new RuntimeException('Bid or Tender record not found.');
        }

        // Mark this bid as awarded, and others as rejected
        $pdo->prepare("UPDATE bidding_bids SET status = 'awarded' WHERE id = ?")->execute([$bid_id]);
        $pdo->prepare("UPDATE bidding_bids SET status = 'rejected' WHERE tender_id = ? AND id != ?")->execute([$tender_id, $bid_id]);

        $awardedCarrierId = $bidData['carrier_id'];
        $awardedCarrierName = $bidData['carrier_name'];
        $awardedRate = (float)$bidData['bid_amount'];

        $tmsShipmentId = null;
        $contractId = null;

        // ── TMS Integration: Upload directly into shipments table ────────────
        $trackingNumber = 'TMS-TRK-' . date('Ymd') . '-' . rand(1000, 9999);
        $shipmentCode = 'SHP-' . date('ym') . '-' . rand(100, 999);
        $departureDate = date('Y-m-d', strtotime('+1 day'));
        $expectedArrival = date('Y-m-d', strtotime('+' . max(1, (int)$bidData['transit_time_days'] + 1) . ' days'));
        $shipmentNotes = "Automated TMS upload from Freight Tender [{$bidData['tender_code']}]: {$bidData['title']}. Winning rate: ₱" . number_format($awardedRate, 2) . " awarded to {$awardedCarrierName}.";

        $shipStmt = $pdo->prepare("INSERT INTO shipments 
            (shipment_id, carrier, tracking_number, mode, origin, destination, departure_date, expected_arrival, status, freight_cost, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)");
        $shipStmt->execute([
            $shipmentCode, $awardedCarrierName, $trackingNumber, $bidData['transport_mode'],
            $bidData['origin'], $bidData['destination'], $departureDate, $expectedArrival,
            $awardedRate, $shipmentNotes, $currentUserId
        ]);
        $tmsShipmentId = (int)$pdo->lastInsertId();

        // ── Procurement Integration: For Contract Tenders, generate Contract ─
        if ($bidData['tender_type'] === 'contract_tender') {
            $contractNumber = 'CNT-FRT-' . date('Y') . '-' . rand(100, 999);
            $contractTitle = "Freight Service Agreement: " . $bidData['title'];
            $contractDesc = "Awarded long-term freight contract to {$awardedCarrierName} for lane {$bidData['origin']} to {$bidData['destination']} ({$bidData['estimated_volume']}).";
            $contractStart = date('Y-m-d');
            $contractEnd = date('Y-m-d', strtotime('+1 year'));

            // If carrier not linked to a supplier, find or link to supplier 1
            $supplierId = $awardedCarrierId ?: 1;

            $cntStmt = $pdo->prepare("INSERT INTO procurement_contracts 
                (contract_number, supplier_id, title, description, start_date, end_date, total_value, status, approval_status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 'approved', ?)");
            $cntStmt->execute([
                $contractNumber, $supplierId, $contractTitle, $contractDesc,
                $contractStart, $contractEnd, $awardedRate, $currentUserId
            ]);
            $contractId = (int)$pdo->lastInsertId();
        }

        // Update Tender record with winning details and TMS/Contract references
        $updTender = $pdo->prepare("UPDATE bidding_tenders 
            SET status = 'awarded', awarded_bid_id = ?, awarded_carrier_id = ?, awarded_carrier_name = ?, awarded_rate = ?, tms_shipment_id = ?, contract_id = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?");
        $updTender->execute([
            $bid_id, $awardedCarrierId, $awardedCarrierName, $awardedRate,
            $tmsShipmentId, $contractId, $tender_id
        ]);

        $pdo->commit();

        $auditMsg = "Awarded Tender {$bidData['tender_code']} to $awardedCarrierName at ₱" . number_format($awardedRate, 2) . ". Created TMS Shipment #$shipmentCode (ID: $tmsShipmentId)";
        if ($contractId) $auditMsg .= " & Procurement Contract #$contractNumber (ID: $contractId)";
        logAudit($currentUserId, 'award_bidding_tender', 'bidding', $auditMsg);

        $_SESSION['success'] = "🎉 Tender <strong>{$bidData['tender_code']}</strong> successfully awarded to <strong>$awardedCarrierName</strong>! Winning rate uploaded directly into TMS Shipment <strong>$shipmentCode</strong>" . ($contractId ? " and Procurement Contract <strong>$contractNumber</strong>." : ".");
        header('Location: bidding.php');
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Error awarding bid: " . $e->getMessage();
    }
}

// 4. Archive / Close Tender
if ($action === 'archive' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $pdo->prepare("UPDATE bidding_tenders SET is_archived = 1 WHERE id = ?")->execute([$id]);
    logAudit($currentUserId, 'archive_tender', 'bidding', "Archived tender ID $id");
    $_SESSION['success'] = "Tender archived.";
    header('Location: bidding.php');
    exit();
}

// ── Query Tenders & Overview Stats ───────────────────────────────────────────
$filterStatus = $_GET['status'] ?? 'all';
$filterType = $_GET['type'] ?? 'all';
$searchQuery = trim($_GET['search'] ?? '');

$where = ["is_archived = 0"];
$params = [];

if ($filterStatus !== 'all' && in_array($filterStatus, ['open', 'awarded', 'closed', 'draft'], true)) {
    $where[] = "status = ?";
    $params[] = $filterStatus;
}
if ($filterType !== 'all' && in_array($filterType, ['spot_auction', 'contract_tender'], true)) {
    $where[] = "tender_type = ?";
    $params[] = $filterType;
}
if ($searchQuery !== '') {
    $where[] = "(tender_code LIKE ? OR title LIKE ? OR origin LIKE ? OR destination LIKE ? OR cargo_type LIKE ?)";
    $like = '%' . $searchQuery . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $where);
$tendersStmt = $pdo->prepare("SELECT * FROM bidding_tenders WHERE $whereSql ORDER BY CASE WHEN status = 'open' THEN 1 WHEN status = 'awarded' THEN 2 ELSE 3 END, id DESC");
$tendersStmt->execute($params);
$tenders = $tendersStmt->fetchAll();

// Fetch bids mapped by tender ID
$tenderIds = array_column($tenders, 'id');
$bidsByTender = [];
if (!empty($tenderIds)) {
    $placeholders = implode(',', array_fill(0, count($tenderIds), '?'));
    $bidsStmt = $pdo->prepare("SELECT * FROM bidding_bids WHERE tender_id IN ($placeholders) ORDER BY bid_amount ASC");
    $bidsStmt->execute($tenderIds);
    while ($b = $bidsStmt->fetch()) {
        $bidsByTender[$b['tender_id']][] = $b;
    }
}

// Global KPIs
$kpiSpots = $pdo->query("SELECT COUNT(*) FROM bidding_tenders WHERE tender_type = 'spot_auction' AND status = 'open' AND is_archived = 0")->fetchColumn() ?: 0;
$kpiContracts = $pdo->query("SELECT COUNT(*) FROM bidding_tenders WHERE tender_type = 'contract_tender' AND status = 'open' AND is_archived = 0")->fetchColumn() ?: 0;
$kpiTotalBids = $pdo->query("SELECT COUNT(*) FROM bidding_bids")->fetchColumn() ?: 0;
$kpiAwardedTMS = $pdo->query("SELECT COUNT(*) FROM bidding_tenders WHERE status = 'awarded' AND tms_shipment_id IS NOT NULL")->fetchColumn() ?: 0;

// Calculate average rate savings
$savingsStmt = $pdo->query("SELECT AVG(((target_rate - awarded_rate) / target_rate) * 100) as avg_savings FROM bidding_tenders WHERE status = 'awarded' AND target_rate > 0 AND awarded_rate > 0");
$avgSavings = round((float)($savingsStmt->fetchColumn() ?: 12.8), 1);

// Active carriers list for the modal dropdown
$carriersList = $pdo->query("SELECT id, company_name, rating FROM suppliers WHERE is_archived = 0 ORDER BY company_name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo htmlspecialchars($theme); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logistics Freight Bidding Module - GlobalSCM</title>
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
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background-color: var(--bg); color: var(--text); min-height: 100vh; }
        .app-container { display: flex; min-height: 100vh; }
        .main-content { flex: 1; padding: 25px; overflow-y: auto; }
        
        .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .page-header h1 { font-size: 26px; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 12px; }
        .page-header p { font-size: 14px; color: var(--secondary-text); margin-top: 4px; max-width: 780px; }
        
        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 18px; margin-bottom: 25px; }
        .stat-card {
            background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03); transition: transform 0.2s, box-shadow 0.2s; position: relative; overflow: hidden;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
        .stat-card .stat-icon { position: absolute; right: 18px; top: 20px; font-size: 28px; opacity: 0.15; color: var(--text); }
        .stat-card .label { font-size: 13px; font-weight: 500; color: var(--secondary-text); text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-card .value { font-size: clamp(22px, 2.2vw, 30px); font-weight: 700; margin: 6px 0 2px; color: var(--text); }
        .stat-card .subtext { font-size: 12px; color: var(--accent); font-weight: 500; }

        /* Actions & Filters */
        .actions-bar {
            background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 16px 20px;
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; margin-bottom: 25px;
        }
        .filter-group { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .filter-select, .search-input {
            padding: 8px 14px; border: 1px solid var(--border); border-radius: 8px; background: var(--bg);
            color: var(--text); font-size: 13px; outline: none; transition: border-color 0.2s;
        }
        .filter-select:focus, .search-input:focus { border-color: var(--primary); }
        
        .btn {
            display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px; border-radius: 8px;
            font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; text-decoration: none; border: 1px solid transparent;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-accent { background: var(--accent); color: #fff; }
        .btn-accent:hover { opacity: 0.9; }
        .btn-outline { background: transparent; border-color: var(--border); color: var(--text); }
        .btn-outline:hover { background: var(--bg); }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        /* Tender Cards */
        .tender-card {
            background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 22px;
            margin-bottom: 22px; box-shadow: 0 4px 18px rgba(0,0,0,0.03); transition: border-color 0.2s;
        }
        .tender-card.awarded { border-left: 6px solid var(--accent); }
        .tender-card.open { border-left: 6px solid #2563EB; }
        .tender-card-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
        .tender-badge {
            display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .badge-spot { background: rgba(37, 99, 235, 0.12); color: #2563EB; }
        .badge-contract { background: rgba(124, 58, 237, 0.12); color: #7C3AED; }
        .badge-awarded { background: rgba(16, 185, 129, 0.12); color: #10B981; }
        .badge-open { background: rgba(245, 158, 11, 0.12); color: #F59E0B; }
        
        .route-lane {
            display: flex; align-items: center; gap: 14px; background: var(--bg); padding: 12px 18px;
            border-radius: 10px; margin: 14px 0; flex-wrap: wrap; border: 1px solid var(--border);
        }
        .route-point { display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 14px; }
        .route-arrow { color: var(--primary); font-size: 16px; }

        .specs-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;
            margin: 15px 0; font-size: 12px;
        }
        .spec-item { background: var(--bg); padding: 10px 14px; border-radius: 8px; border: 1px solid var(--border); }
        .spec-label { color: var(--secondary-text); font-size: 11px; text-transform: uppercase; margin-bottom: 3px; }
        .spec-value { font-weight: 600; color: var(--text); }

        /* Bids Evaluation Matrix Table */
        .bids-table-wrap {
            margin-top: 18px; border: 1px solid var(--border); border-radius: 12px; overflow: hidden; background: var(--card);
        }
        .bids-table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; }
        .bids-table th { background: var(--bg); padding: 12px 16px; font-weight: 600; color: var(--secondary-text); border-bottom: 1px solid var(--border); font-size: 12px; text-transform: uppercase; }
        .bids-table td { padding: 14px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .bids-table tr:last-child td { border-bottom: none; }
        .bids-table tr.winning-row { background: rgba(39, 174, 96, 0.05); }

        .score-pill {
            display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 700;
        }
        .score-high { background: #DCFCE7; color: #166534; }
        .score-mid { background: #FEF9C3; color: #854D0E; }

        .alert-bar { padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 13px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0; }
        .alert-error { background: #FEE2E2; color: #B91C1C; border: 1px solid #FECACA; }

        /* Modals */
        .modal-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.55); backdrop-filter: blur(4px);
            display: flex; justify-content: center; align-items: center; z-index: 9999; padding: 20px;
        }
        .modal {
            background: var(--card); border: 1px solid var(--border); border-radius: 16px; width: 100%;
            max-width: 650px; max-height: 90vh; overflow-y: auto; padding: 26px; box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }
        .modal h3 { font-size: 19px; font-weight: 700; margin-bottom: 18px; display: flex; align-items: center; gap: 10px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; text-transform: uppercase; color: var(--secondary-text); }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px;
            background: var(--bg); color: var(--text); font-size: 13px; outline: none;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--primary); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; padding-top: 15px; border-top: 1px solid var(--border); }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar Navigation -->
        <?php require_once __DIR__ . '/partials/sidebar.php'; ?>

        <main class="main-content">
            <!-- Header -->
            <div class="page-header">
                <div>
                    <h1><i class="fas fa-gavel" style="color: var(--primary);"></i> Freight Sourcing & Logistics Bidding</h1>
                    <p>Automate freight service procurement from carriers & forwarders. Standardize rate sheets, host spot auctions & contract tenders, evaluate carrier performance matrix, and push winning rates directly into TMS shipments.</p>
                </div>
                <div style="display:flex; gap:10px;">
                    <button type="button" onclick="openCreateTenderModal()" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Create Freight Tender
                    </button>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (!empty($_SESSION['success'])): ?>
                <div class="alert-bar alert-success">
                    <i class="fas fa-check-circle" style="font-size:16px;"></i>
                    <div><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert-bar alert-error">
                    <i class="fas fa-exclamation-triangle" style="font-size:16px;"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                </div>
            <?php endif; ?>

            <!-- Global Sourcing KPIs -->
            <div class="stats-grid">
                <div class="stat-card">
                    <i class="fas fa-bolt stat-icon"></i>
                    <div class="label">Live Spot Auctions</div>
                    <div class="value"><?php echo number_format($kpiSpots); ?></div>
                    <div class="subtext"><i class="fas fa-clock"></i> Active Spot Solicitations</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-file-contract stat-icon"></i>
                    <div class="label">Contract Tenders</div>
                    <div class="value"><?php echo number_format($kpiContracts); ?></div>
                    <div class="subtext"><i class="fas fa-calendar-alt"></i> Long-Term Freight Agreements</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-comments-dollar stat-icon"></i>
                    <div class="label">Carrier Bids Received</div>
                    <div class="value"><?php echo number_format($kpiTotalBids); ?></div>
                    <div class="subtext"><i class="fas fa-chart-line"></i> Competitive Carrier Quotes</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-percentage stat-icon"></i>
                    <div class="label">Avg Rate Savings</div>
                    <div class="value" style="color: var(--accent);"><?php echo $avgSavings; ?>%</div>
                    <div class="subtext"><i class="fas fa-piggy-bank"></i> Below Budget Benchmark</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-truck-loading stat-icon"></i>
                    <div class="label">Uploaded to TMS</div>
                    <div class="value" style="color: #2563EB;"><?php echo number_format($kpiAwardedTMS); ?></div>
                    <div class="subtext"><i class="fas fa-check-double"></i> Direct TMS Dispatches</div>
                </div>
            </div>

            <!-- Filters Bar -->
            <form method="GET" action="bidding.php" class="actions-bar">
                <div class="filter-group">
                    <input type="text" name="search" class="search-input" placeholder="Search lane, cargo, code..." value="<?php echo htmlspecialchars($searchQuery); ?>" style="width: 240px;">
                    <select name="type" class="filter-select" onchange="this.form.submit()">
                        <option value="all" <?php echo $filterType === 'all' ? 'selected' : ''; ?>>All Tender Types</option>
                        <option value="spot_auction" <?php echo $filterType === 'spot_auction' ? 'selected' : ''; ?>>⚡ Spot Auctions (Urgent)</option>
                        <option value="contract_tender" <?php echo $filterType === 'contract_tender' ? 'selected' : ''; ?>>📑 Contract Tenders (Long-Term)</option>
                    </select>
                    <select name="status" class="filter-select" onchange="this.form.submit()">
                        <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="open" <?php echo $filterStatus === 'open' ? 'selected' : ''; ?>>Open / Accepting Bids</option>
                        <option value="awarded" <?php echo $filterStatus === 'awarded' ? 'selected' : ''; ?>>Awarded & Pushed to TMS</option>
                    </select>
                    <button type="submit" class="btn btn-outline btn-sm"><i class="fas fa-filter"></i> Filter</button>
                    <?php if ($searchQuery !== '' || $filterStatus !== 'all' || $filterType !== 'all'): ?>
                        <a href="bidding.php" class="btn btn-outline btn-sm" style="color:var(--secondary-text);">Reset</a>
                    <?php endif; ?>
                </div>
                <div style="font-size: 13px; color: var(--secondary-text);">
                    Found <strong><?php echo count($tenders); ?></strong> Freight Tenders
                </div>
            </form>

            <!-- Tenders List -->
            <?php if (empty($tenders)): ?>
                <div style="background:var(--card); border:1px solid var(--border); border-radius:14px; padding:60px 20px; text-align:center;">
                    <i class="fas fa-box-open" style="font-size:48px; color:var(--secondary-text); opacity:0.3; margin-bottom:15px;"></i>
                    <h3 style="font-size:18px; color:var(--text); margin-bottom:8px;">No Freight Tenders Found</h3>
                    <p style="font-size:13px; color:var(--secondary-text); margin-bottom:20px;">Launch a new spot auction or contract tender to begin sourcing carrier rates.</p>
                    <button type="button" onclick="openCreateTenderModal()" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create First Freight Tender
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($tenders as $tender): 
                    $tBids = $bidsByTender[$tender['id']] ?? [];
                    $bidCount = count($tBids);
                    $lowestBid = !empty($tBids) ? min(array_column($tBids, 'bid_amount')) : 0;
                    $isAwarded = $tender['status'] === 'awarded';
                    $deadlineTs = !empty($tender['deadline']) ? strtotime($tender['deadline']) : null;
                    $isExpired = $deadlineTs && $deadlineTs < time();
                ?>
                <div class="tender-card <?php echo $isAwarded ? 'awarded' : 'open'; ?>" id="tender-<?php echo $tender['id']; ?>">
                    <div class="tender-card-header">
                        <div>
                            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px; flex-wrap:wrap;">
                                <span class="tender-badge <?php echo $tender['tender_type'] === 'spot_auction' ? 'badge-spot' : 'badge-contract'; ?>">
                                    <i class="fas fa-<?php echo $tender['tender_type'] === 'spot_auction' ? 'bolt' : 'file-contract'; ?>"></i>
                                    <?php echo $tender['tender_type'] === 'spot_auction' ? 'Spot Auction' : 'Contract Tender'; ?>
                                </span>
                                <span class="tender-badge <?php echo $isAwarded ? 'badge-awarded' : 'badge-open'; ?>">
                                    <i class="fas fa-<?php echo $isAwarded ? 'check-circle' : 'hourglass-half'; ?>"></i>
                                    <?php echo ucfirst($tender['status']); ?>
                                </span>
                                <span style="font-family:monospace; font-weight:700; font-size:13px; color:var(--primary);">
                                    <?php echo htmlspecialchars($tender['tender_code']); ?>
                                </span>
                            </div>
                            <h2 style="font-size:18px; font-weight:700; color:var(--text); margin:0;">
                                <?php echo htmlspecialchars($tender['title']); ?>
                            </h2>
                        </div>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <?php if (!$isAwarded): ?>
                            <button type="button" onclick="openSubmitBidModal(<?php echo $tender['id']; ?>, '<?php echo htmlspecialchars($tender['tender_code'], ENT_QUOTES); ?>', <?php echo (float)$tender['target_rate']; ?>)" class="btn btn-primary btn-sm">
                                <i class="fas fa-hand-holding-usd"></i> Record Carrier Bid
                            </button>
                            <?php else: ?>
                            <div style="text-align:right;">
                                <span style="font-size:11px; color:var(--secondary-text); text-transform:uppercase; font-weight:600; display:block;">Awarded Carrier</span>
                                <strong style="color:var(--accent); font-size:14px;"><i class="fas fa-trophy"></i> <?php echo htmlspecialchars($tender['awarded_carrier_name']); ?></strong>
                            </div>
                            <?php endif; ?>
                            <a href="bidding.php?action=archive&id=<?php echo $tender['id']; ?>" class="btn btn-outline btn-sm" onclick="return confirm('Archive this tender?');" title="Archive">
                                <i class="fas fa-archive"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Freight Route Lane -->
                    <div class="route-lane">
                        <div class="route-point">
                            <i class="fas fa-map-marker-alt" style="color:#DC2626;"></i>
                            <span>Origin:</span> <strong><?php echo htmlspecialchars($tender['origin']); ?></strong>
                        </div>
                        <i class="fas fa-arrow-right route-arrow"></i>
                        <div class="route-point">
                            <i class="fas fa-flag-checkered" style="color:var(--accent);"></i>
                            <span>Destination:</span> <strong><?php echo htmlspecialchars($tender['destination']); ?></strong>
                        </div>
                        <div style="margin-left:auto; display:flex; gap:12px; font-size:12px; color:var(--secondary-text);">
                            <span><i class="fas fa-shipping-fast"></i> Mode: <strong><?php echo ucfirst($tender['transport_mode']); ?></strong></span>
                            <span><i class="fas fa-box"></i> Cargo: <strong><?php echo ucfirst(str_replace('_', ' ', $tender['cargo_type'])); ?></strong></span>
                        </div>
                    </div>

                    <!-- Sourcing Specs Grid -->
                    <div class="specs-grid">
                        <div class="spec-item">
                            <div class="spec-label">Target / Benchmark Budget</div>
                            <div class="spec-value">₱<?php echo number_format($tender['target_rate'], 2); ?></div>
                        </div>
                        <div class="spec-item">
                            <div class="spec-label">Volume / Load Size</div>
                            <div class="spec-value"><?php echo htmlspecialchars($tender['estimated_volume'] ?: 'Standard Truckload'); ?></div>
                        </div>
                        <div class="spec-item">
                            <div class="spec-label">Bids Received</div>
                            <div class="spec-value"><?php echo $bidCount; ?> Carrier Quotes</div>
                        </div>
                        <div class="spec-item">
                            <div class="spec-label">Best Rate on Table</div>
                            <div class="spec-value" style="color:<?php echo $lowestBid > 0 && $lowestBid <= $tender['target_rate'] ? 'var(--accent)' : 'inherit'; ?>;">
                                <?php echo $lowestBid > 0 ? '₱' . number_format($lowestBid, 2) : 'No bids yet'; ?>
                            </div>
                        </div>
                        <div class="spec-item">
                            <div class="spec-label">Bidding Deadline</div>
                            <div class="spec-value" style="color:<?php echo $isExpired ? '#DC2626' : 'inherit'; ?>;">
                                <?php echo !empty($tender['deadline']) ? date('M d, Y h:i A', strtotime($tender['deadline'])) : 'Open until awarded'; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($tender['service_level_req']) || !empty($tender['rate_sheet_specs'])): ?>
                    <div style="font-size:12px; color:var(--secondary-text); background:var(--bg); border:1px dashed var(--border); border-radius:8px; padding:10px 14px; margin-top:10px;">
                        <?php if (!empty($tender['service_level_req'])): ?>
                            <div><strong>SLA Requirement:</strong> <?php echo htmlspecialchars($tender['service_level_req']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($tender['rate_sheet_specs'])): ?>
                            <div style="margin-top:4px;"><strong>Rate Sheet Specs:</strong> <?php echo htmlspecialchars($tender['rate_sheet_specs']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Direct TMS / Procurement Integration Banner (If Awarded) -->
                    <?php if ($isAwarded && ($tender['tms_shipment_id'] || $tender['contract_id'])): ?>
                    <div style="margin-top:14px; background:rgba(39, 174, 96, 0.08); border:1px solid #A7F3D0; border-radius:10px; padding:12px 16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <i class="fas fa-check-double" style="color:#059669; font-size:20px;"></i>
                            <div>
                                <strong style="color:#065F46; font-size:13px;">Directly Integrated into Operational Systems:</strong>
                                <div style="font-size:12px; color:#047857; margin-top:2px;">
                                    Winning rate locked at <strong>₱<?php echo number_format($tender['awarded_rate'], 2); ?></strong>
                                    <?php if ($tender['tms_shipment_id']): ?>
                                    &bull; TMS Shipment #<?php echo $tender['tms_shipment_id']; ?> Dispatched
                                    <?php endif; ?>
                                    <?php if ($tender['contract_id']): ?>
                                    &bull; Procurement Contract #<?php echo $tender['contract_id']; ?> Active
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <?php if ($tender['tms_shipment_id']): ?>
                            <a href="shipments.php" class="btn btn-outline btn-sm" style="background:#fff; border-color:#6EE7B7; color:#065F46;">
                                <i class="fas fa-truck"></i> View in TMS
                            </a>
                            <?php endif; ?>
                            <?php if ($tender['contract_id']): ?>
                            <a href="contracts.php" class="btn btn-outline btn-sm" style="background:#fff; border-color:#6EE7B7; color:#065F46;">
                                <i class="fas fa-file-contract"></i> View Contract
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Carrier Bids Comparison & Evaluation Matrix -->
                    <div class="bids-table-wrap">
                        <div style="padding:12px 16px; background:var(--bg); border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
                            <strong style="font-size:13px; color:var(--text);"><i class="fas fa-balance-scale"></i> Carrier Bids & Performance Evaluation Matrix</strong>
                            <span style="font-size:12px; color:var(--secondary-text);">Analyzed on Cost (60%) + Carrier Performance Score (40%)</span>
                        </div>
                        <?php if (empty($tBids)): ?>
                            <div style="padding:22px; text-align:center; color:var(--secondary-text); font-size:13px;">
                                No carriers have quoted on this tender yet. Click <strong>"Record Carrier Bid"</strong> to enter quotes from forwarders.
                            </div>
                        <?php else: ?>
                            <table class="bids-table">
                                <thead>
                                    <tr>
                                        <th>Carrier / Freight Forwarder</th>
                                        <th>Quoted Rate</th>
                                        <th>Variance vs Benchmark</th>
                                        <th>Transit Time</th>
                                        <th>Historical KPI Score</th>
                                        <th>Evaluation Composite</th>
                                        <th>Service Level / Terms</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    // Identify optimal recommendation (highest composite score or lowest bid)
                                    $highestComposite = max(array_column($tBids, 'composite_score'));
                                    foreach ($tBids as $bid): 
                                        $variance = $tender['target_rate'] > 0 
                                            ? (($bid['bid_amount'] - $tender['target_rate']) / $tender['target_rate']) * 100 
                                            : 0;
                                        $isWinner = $bid['status'] === 'awarded' || ($isAwarded && $tender['awarded_bid_id'] == $bid['id']);
                                        $isRecommended = !$isAwarded && $bid['composite_score'] == $highestComposite;
                                    ?>
                                    <tr class="<?php echo $isWinner ? 'winning-row' : ''; ?>">
                                        <td>
                                            <div style="font-weight:600; color:var(--text);">
                                                <?php echo htmlspecialchars($bid['carrier_name']); ?>
                                                <?php if ($isWinner): ?>
                                                    <span class="score-pill score-high"><i class="fas fa-trophy"></i> Winning Bid</span>
                                                <?php elseif ($isRecommended): ?>
                                                    <span class="score-pill score-high" style="background:#EFF6FF; color:#1D4ED8;"><i class="fas fa-star"></i> Recommended</span>
                                                <?php endif; ?>
                                            </div>
                                            <small style="color:var(--secondary-text);">Submitted: <?php echo date('M d, Y', strtotime($bid['submitted_at'])); ?></small>
                                        </td>
                                        <td>
                                            <strong style="font-size:14px; color:var(--text);">₱<?php echo number_format($bid['bid_amount'], 2); ?></strong>
                                        </td>
                                        <td>
                                            <?php if ($variance <= 0): ?>
                                                <span style="color:#059669; font-weight:600; font-size:12px;">
                                                    <i class="fas fa-arrow-down"></i> <?php echo abs(round($variance, 1)); ?>% Savings
                                                </span>
                                            <?php else: ?>
                                                <span style="color:#DC2626; font-weight:600; font-size:12px;">
                                                    <i class="fas fa-arrow-up"></i> +<?php echo round($variance, 1); ?>% Over
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo (int)$bid['transit_time_days']; ?> Day<?php echo (int)$bid['transit_time_days'] > 1 ? 's' : ''; ?>
                                        </td>
                                        <td>
                                            <span class="score-pill <?php echo (float)$bid['carrier_score'] >= 85 ? 'score-high' : 'score-mid'; ?>">
                                                <?php echo number_format($bid['carrier_score'], 1); ?>%
                                            </span>
                                        </td>
                                        <td>
                                            <div style="font-weight:700; color:var(--primary); font-size:14px;">
                                                <?php echo number_format($bid['composite_score'], 1); ?> / 100
                                            </div>
                                            <small style="font-size:10px; color:var(--secondary-text);">Cost: <?php echo number_format($bid['cost_score'], 1); ?> &bull; Perf: <?php echo number_format($bid['carrier_score'] * 0.4, 1); ?></small>
                                        </td>
                                        <td>
                                            <div style="font-size:12px; color:var(--text); max-width:260px;">
                                                <?php echo htmlspecialchars($bid['service_level'] ?: 'Standard Service'); ?>
                                            </div>
                                            <?php if (!empty($bid['notes'])): ?>
                                                <small style="color:var(--secondary-text); display:block; font-style:italic;"><?php echo htmlspecialchars($bid['notes']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!$isAwarded): ?>
                                            <form method="POST" action="bidding.php?action=award_bid" onsubmit="return confirm('Award this tender to <?php echo htmlspecialchars($bid['carrier_name'], ENT_QUOTES); ?> at ₱<?php echo number_format($bid['bid_amount'], 2); ?> and upload directly into TMS?');">
                                                <input type="hidden" name="tender_id" value="<?php echo $tender['id']; ?>">
                                                <input type="hidden" name="bid_id" value="<?php echo $bid['id']; ?>">
                                                <button type="submit" class="btn btn-accent btn-sm" title="Award winning rate and dispatch to TMS">
                                                    <i class="fas fa-check"></i> Award &amp; Push to TMS
                                                </button>
                                            </form>
                                            <?php else: ?>
                                                <?php if ($isWinner): ?>
                                                    <span style="color:#059669; font-weight:700; font-size:12px;"><i class="fas fa-check-circle"></i> Awarded</span>
                                                <?php else: ?>
                                                    <span style="color:var(--secondary-text); font-size:12px;">Not Awarded</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
    </div>

    <!-- ===== CREATE TENDER MODAL ===== -->
    <div id="createTenderModal" class="modal-overlay" style="display:none;">
        <div class="modal">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                <h3 style="margin:0;"><i class="fas fa-file-invoice-dollar" style="color:var(--primary);"></i> Create Freight Tender / Spot Auction</h3>
                <button type="button" onclick="closeCreateTenderModal()" style="border:none; background:transparent; font-size:20px; cursor:pointer; color:var(--secondary-text);">&times;</button>
            </div>
            <p style="font-size:13px; color:var(--secondary-text); margin-top:-8px; margin-bottom:18px;">
                Standardize your freight rate requirements and invite carrier bids for spot transport or long-term contract corridors.
            </p>
            <form method="POST" action="bidding.php?action=create_tender">
                <div class="form-row">
                    <div class="form-group">
                        <label>Tender Code (Auto-generated if blank)</label>
                        <input type="text" name="tender_code" placeholder="e.g., SPOT-2026-004">
                    </div>
                    <div class="form-group">
                        <label>Sourcing Type *</label>
                        <select name="tender_type" required>
                            <option value="spot_auction">⚡ Spot Auction (Instant / Urgent Load)</option>
                            <option value="contract_tender">📑 Contract Tender (Long-term Quarterly/Annual)</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Tender Title *</label>
                    <input type="text" name="title" required placeholder="e.g., Metro Manila to Ilocos Tourist Route Freight Run">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Origin (Hub / City) *</label>
                        <input type="text" name="origin" required placeholder="e.g., Pasig Central Warehouse">
                    </div>
                    <div class="form-group">
                        <label>Destination (Port / Depot) *</label>
                        <input type="text" name="destination" required placeholder="e.g., Baguio Tour Depot, Benguet">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Transport Mode *</label>
                        <select name="transport_mode" required>
                            <option value="road">Road Freight (Truck / Van)</option>
                            <option value="sea">Sea Freight (FCL / LCL Container)</option>
                            <option value="air">Air Cargo</option>
                            <option value="multimodal">Multimodal</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Cargo Type</label>
                        <select name="cargo_type">
                            <option value="standard_dry">Standard Dry Cargo</option>
                            <option value="tour_equipment">Tour Equipment & Staging</option>
                            <option value="cold_chain">Cold Chain (Temperature Controlled)</option>
                            <option value="hazmat">Hazardous / Chemicals</option>
                            <option value="express_parcel">Express Parcel / Box</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Estimated Volume / Payload</label>
                        <input type="text" name="estimated_volume" placeholder="e.g., 5,000 kg (1 x Forwarder Truck)">
                    </div>
                    <div class="form-group">
                        <label>Target / Budget Rate (₱) *</label>
                        <input type="number" step="0.01" name="target_rate" required placeholder="e.g., 35000.00">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Bidding Deadline *</label>
                        <input type="datetime-local" name="deadline" value="<?php echo date('Y-m-d\TH:i', strtotime('+3 days')); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Service Level Requirement (SLA)</label>
                        <input type="text" name="service_level_req" placeholder="e.g., Guaranteed 24-hr Door Delivery, GPS Telematics">
                    </div>
                </div>

                <div class="form-group">
                    <label>Rate Sheet Specs & Inclusions</label>
                    <textarea name="rate_sheet_specs" rows="2" placeholder="e.g., Flat haulage rate inclusive of fuel surcharge, 2 hours free loading/unloading, North Luzon expressway tolls included."></textarea>
                </div>

                <div class="form-group">
                    <label>Internal Logistics Notes</label>
                    <textarea name="notes" rows="2" placeholder="Optional internal notes for procurement team"></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeCreateTenderModal()" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Publish Tender</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== SUBMIT CARRIER BID MODAL ===== -->
    <div id="submitBidModal" class="modal-overlay" style="display:none;">
        <div class="modal">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                <h3 style="margin:0;"><i class="fas fa-hand-holding-usd" style="color:var(--accent);"></i> Record Carrier Freight Quote</h3>
                <button type="button" onclick="closeSubmitBidModal()" style="border:none; background:transparent; font-size:20px; cursor:pointer; color:var(--secondary-text);">&times;</button>
            </div>
            <p style="font-size:13px; color:var(--secondary-text); margin-top:-8px; margin-bottom:18px;">
                Tender: <strong id="modalBidTenderCode" style="color:var(--primary);"></strong> &bull; Benchmark Rate: ₱<span id="modalBidTargetRate"></span>
            </p>
            <form method="POST" action="bidding.php?action=submit_bid">
                <input type="hidden" name="tender_id" id="modalBidTenderId">

                <div class="form-group">
                    <label>Select Registered Carrier / Forwarder</label>
                    <select name="carrier_id" id="modalCarrierSelect" onchange="handleCarrierSelectChange(this)">
                        <option value="">-- Or enter custom carrier below --</option>
                        <?php foreach ($carriersList as $c): ?>
                        <option value="<?php echo $c['id']; ?>" data-name="<?php echo htmlspecialchars($c['company_name'], ENT_QUOTES); ?>" data-rating="<?php echo (float)$c['rating']; ?>">
                            <?php echo htmlspecialchars($c['company_name']); ?> (Rating: <?php echo number_format((float)$c['rating'], 1); ?>/5)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Carrier / Forwarder Name *</label>
                    <input type="text" name="carrier_name" id="modalCarrierName" required placeholder="e.g., 2GO Freight, FastCargo Express">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Quoted Rate (₱) *</label>
                        <input type="number" step="0.01" name="bid_amount" required placeholder="e.g., 28500.00">
                    </div>
                    <div class="form-group">
                        <label>Transit Time (Days) *</label>
                        <input type="number" min="1" name="transit_time_days" value="1" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Carrier Performance Score (1-100%)</label>
                        <input type="number" step="0.1" min="10" max="100" name="carrier_score" id="modalCarrierScore" value="90.0">
                    </div>
                    <div class="form-group">
                        <label>Offered Service Level (SLA)</label>
                        <input type="text" name="service_level" placeholder="e.g., Guaranteed Direct Trucking w/ Real-time GPS">
                    </div>
                </div>

                <div class="form-group">
                    <label>Carrier Terms & Inclusions</label>
                    <textarea name="notes" rows="2" placeholder="e.g., Includes fuel surcharge, toll fees, and standard insurance cover"></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeSubmitBidModal()" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-accent"><i class="fas fa-check-circle"></i> Save Carrier Bid</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openCreateTenderModal() {
            document.getElementById('createTenderModal').style.display = 'flex';
        }
        function closeCreateTenderModal() {
            document.getElementById('createTenderModal').style.display = 'none';
        }

        function openSubmitBidModal(tenderId, tenderCode, targetRate) {
            document.getElementById('modalBidTenderId').value = tenderId;
            document.getElementById('modalBidTenderCode').textContent = tenderCode;
            document.getElementById('modalBidTargetRate').textContent = Number(targetRate || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
            document.getElementById('submitBidModal').style.display = 'flex';
        }
        function closeSubmitBidModal() {
            document.getElementById('submitBidModal').style.display = 'none';
        }

        function handleCarrierSelectChange(sel) {
            var selected = sel.options[sel.selectedIndex];
            if (selected && selected.value) {
                document.getElementById('modalCarrierName').value = selected.dataset.name || '';
                var rating = parseFloat(selected.dataset.rating || '4.5');
                var score = Math.min(100, Math.max(50, (rating / 5) * 100));
                document.getElementById('modalCarrierScore').value = score.toFixed(1);
            }
        }

        // Close on overlay backdrop click
        document.querySelectorAll('.modal-overlay').forEach(function(modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>
