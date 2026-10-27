<?php
declare(strict_types=1);

/**
 * GlobalSCM / Tour-Sphere — Comprehensive Data Seeder
 * Seeds comprehensive, high-quality, realistic logistics and supply chain data
 * across EVERY module:
 *  - Warehouses & Warehouse Zones
 *  - Inventory Groups, Products, Warehouse Inventory, Serial Numbers
 *  - Suppliers, Privacy Acknowledgements, Supplier Performance
 *  - Purchase Requisitions & Requisition Items
 *  - Purchase Orders & Purchase Order Items
 *  - Inventory Batches, Stock Movements (Transactions), Incidents
 *  - Procurement Contracts
 *  - Freight Bidding Tenders & Carrier Bids
 *  - Logistics Shipments & Tracking
 *  - Supply Chain Documents & Rate Sheets
 *  - Audit Logs
 */

require_once __DIR__ . '/../includes/db.php';

echo "=======================================================\n";
echo "Tour-Sphere Logistics — Database Seeder Starting\n";
echo "Active Driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";
echo "=======================================================\n\n";

$isPgsql = in_array(strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)), ['pgsql', 'postgres', 'postgresql'], true);

// Helper function to sync sequence in PostgreSQL
function syncSequence(PDO $pdo, string $table, bool $isPgsql): void {
    if ($isPgsql) {
        try {
            $pdo->exec("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 1))");
        } catch (Throwable $e) {
            // Sequence may not follow standard pattern or table empty
        }
    }
}

// Ensure default admin user ID
$adminUserId = 1;
try {
    $uStmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
    $foundAdmin = $uStmt->fetchColumn();
    if ($foundAdmin) {
        $adminUserId = (int)$foundAdmin;
    }
} catch (Throwable $e) {}

// ==============================================================================
// 1. INVENTORY GROUPS
// ==============================================================================
echo "[1/12] Seeding Inventory Groups...\n";
$groups = [
    'Electric Vehicles & Mobility',
    'Spare Parts & Batteries',
    'Safety & Protective Gear',
    'Warehouse Equipment & Packaging',
    'Fleet Telematics & Electronics'
];

$groupIds = [];
foreach ($groups as $gName) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM inventory_groups WHERE group_name = ?");
        $stmt->execute([$gName]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            $ins = $pdo->prepare("INSERT INTO inventory_groups (group_name) VALUES (?)");
            $ins->execute([$gName]);
            $id = $pdo->lastInsertId();
        }
        $groupIds[$gName] = (int)$id;
    } catch (Throwable $e) {
        echo "  Notice (Inventory Group {$gName}): " . $e->getMessage() . "\n";
    }
}
syncSequence($pdo, 'inventory_groups', $isPgsql);

// ==============================================================================
// 2. WAREHOUSES & WAREHOUSE ZONES
// ==============================================================================
echo "[2/12] Seeding Warehouses & Warehouse Zones...\n";
$warehousesData = [
    [
        'code' => 'WH-MNL-01',
        'name' => 'Metro Manila Central Distribution Hub',
        'location' => 'Km 18 West Service Road, Parañaque City, Metro Manila',
        'capacity' => 50000,
        'utilization' => 32000,
        'group_id' => $groupIds['Warehouse Equipment & Packaging'] ?? null,
        'type' => 'central_hub',
        'status' => 'active'
    ],
    [
        'code' => 'WH-CRK-02',
        'name' => 'Clark Freeport Regional Logistics Depot',
        'location' => 'Clark Aviation Complex, Clark Freeport Zone, Pampanga',
        'capacity' => 35000,
        'utilization' => 19500,
        'group_id' => $groupIds['Electric Vehicles & Mobility'] ?? null,
        'type' => 'bonded_warehouse',
        'status' => 'active'
    ],
    [
        'code' => 'WH-CEB-03',
        'name' => 'Cebu Inter-Island Maritime Terminal',
        'location' => 'Reclamation Area, North Harbor, Cebu City, Visayas',
        'capacity' => 28000,
        'utilization' => 16400,
        'group_id' => $groupIds['Spare Parts & Batteries'] ?? null,
        'type' => 'transit_hub',
        'status' => 'active'
    ],
    [
        'code' => 'WH-DVO-04',
        'name' => 'Davao Southern Cross-Dock Facility',
        'location' => 'Sasa Port Commercial Zone, Davao City, Mindanao',
        'capacity' => 22000,
        'utilization' => 11200,
        'group_id' => $groupIds['Fleet Telematics & Electronics'] ?? null,
        'type' => 'standard',
        'status' => 'active'
    ],
    [
        'code' => 'WH-BTG-05',
        'name' => 'Batangas Port Ro-Ro Consolidation Depot',
        'location' => 'Sta. Clara, Batangas City Port Access, Batangas',
        'capacity' => 30000,
        'utilization' => 14000,
        'group_id' => $groupIds['Safety & Protective Gear'] ?? null,
        'type' => 'port_depot',
        'status' => 'active'
    ]
];

$warehouseIds = [];
foreach ($warehousesData as $w) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM warehouses WHERE warehouse_code = ?");
        $stmt->execute([$w['code']]);
        $wId = $stmt->fetchColumn();
        if (!$wId) {
            $ins = $pdo->prepare("
                INSERT INTO warehouses (warehouse_code, name, location, capacity, current_utilization, group_id, type, status, is_archived)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, " . ($isPgsql ? 'FALSE' : '0') . ")
            ");
            $ins->execute([$w['code'], $w['name'], $w['location'], $w['capacity'], $w['utilization'], $w['group_id'], $w['type'], $w['status']]);
            $wId = $pdo->lastInsertId();
        }
        $warehouseIds[$w['code']] = (int)$wId;
    } catch (Throwable $e) {
        echo "  Notice (Warehouse {$w['code']}): " . $e->getMessage() . "\n";
    }
}
syncSequence($pdo, 'warehouses', $isPgsql);

// Seed zones for each warehouse
$zonesData = [
    // Manila Hub
    ['wh' => 'WH-MNL-01', 'code' => 'Z-MNL-A1', 'name' => 'Staging & Cross-Dock Bay A', 'type' => 'cross_dock', 'cap' => 8000, 'haz' => 0, 'cold' => 0, 'temp' => null],
    ['wh' => 'WH-MNL-01', 'code' => 'Z-MNL-B2', 'name' => 'Heavy Racking & Pallet Vault', 'type' => 'high_rack', 'cap' => 18000, 'haz' => 0, 'cold' => 0, 'temp' => null],
    ['wh' => 'WH-MNL-01', 'code' => 'Z-MNL-C3', 'name' => 'Hazmat Li-Ion Battery Bunker', 'type' => 'hazmat', 'cap' => 6000, 'haz' => 1, 'cold' => 0, 'temp' => 22.0],
    ['wh' => 'WH-MNL-01', 'code' => 'Z-MNL-D4', 'name' => 'Climate-Controlled Electronics Lab', 'type' => 'cold_chain', 'cap' => 4000, 'haz' => 0, 'cold' => 1, 'temp' => 18.5],
    // Clark Hub
    ['wh' => 'WH-CRK-02', 'code' => 'Z-CRK-A1', 'name' => 'Aviation Cargo Inbound Staging', 'type' => 'receiving', 'cap' => 10000, 'haz' => 0, 'cold' => 0, 'temp' => null],
    ['wh' => 'WH-CRK-02', 'code' => 'Z-CRK-B2', 'name' => 'Bonded Vehicle Assembly Deck', 'type' => 'bonded', 'cap' => 15000, 'haz' => 0, 'cold' => 0, 'temp' => null],
    // Cebu Depot
    ['wh' => 'WH-CEB-03', 'code' => 'Z-CEB-A1', 'name' => 'Maritime Container Marshalling', 'type' => 'shipping', 'cap' => 12000, 'haz' => 0, 'cold' => 0, 'temp' => null],
    ['wh' => 'WH-CEB-03', 'code' => 'Z-CEB-B2', 'name' => 'Parts & Gear Bulk Storage', 'type' => 'standard', 'cap' => 10000, 'haz' => 0, 'cold' => 0, 'temp' => null],
    // Davao Facility
    ['wh' => 'WH-DVO-04', 'code' => 'Z-DVO-A1', 'name' => 'Mindanao Ground Fleet Staging', 'type' => 'ground_fleet', 'cap' => 9000, 'haz' => 0, 'cold' => 0, 'temp' => null],
    // Batangas Depot
    ['wh' => 'WH-BTG-05', 'code' => 'Z-BTG-A1', 'name' => 'Ro-Ro Maritime Transfer Dock', 'type' => 'port_dock', 'cap' => 14000, 'haz' => 0, 'cold' => 0, 'temp' => null]
];

foreach ($zonesData as $zd) {
    $whId = $warehouseIds[$zd['wh']] ?? null;
    if (!$whId) continue;
    try {
        $chk = $pdo->prepare("SELECT id FROM warehouse_zones WHERE warehouse_id = ? AND zone_code = ?");
        $chk->execute([$whId, $zd['code']]);
        if (!$chk->fetchColumn()) {
            $ins = $pdo->prepare("
                INSERT INTO warehouse_zones (warehouse_id, zone_code, zone_name, zone_type, capacity, is_hazmat, is_cold_chain, temperature)
                VALUES (?, ?, ?, ?, ?, " . ($isPgsql ? ($zd['haz'] ? 'TRUE' : 'FALSE') : $zd['haz']) . ", " . ($isPgsql ? ($zd['cold'] ? 'TRUE' : 'FALSE') : $zd['cold']) . ", ?)
            ");
            $ins->execute([$whId, $zd['code'], $zd['name'], $zd['type'], $zd['cap'], $zd['temp']]);
        }
    } catch (Throwable $e) {
        echo "  Notice (Zone {$zd['code']}): " . $e->getMessage() . "\n";
    }
}
syncSequence($pdo, 'warehouse_zones', $isPgsql);

// ==============================================================================
// 3. SUPPLIERS, PRIVACY ACKNOWLEDGEMENTS & PERFORMANCE
// ==============================================================================
echo "[3/12] Seeding Suppliers, Privacy Acknowledgements & Performance...\n";
$suppliersData = [
    [
        'code' => 'SUP-NWOW-01',
        'name' => 'NWOW Electric Mobility Philippines Inc.',
        'type' => 'vehicle_manufacturer',
        'contact' => 'Michael Angelo Reyes',
        'email' => 'corporate.sales@nwow-ev.ph',
        'phone' => '+63 2 8842 1190',
        'address' => 'Lot 4 Block 9 First Cavite Industrial Estate, Dasmariñas, Cavite',
        'tax_id' => '009-842-192-000',
        'bank' => 'BDO Unibank - Acct # 1048-2918-4412',
        'terms' => 'Net 30 Days',
        'status' => 'active',
        'rating' => 4.85
    ],
    [
        'code' => 'SUP-CAR-01',
        'name' => 'FastCargo Logistics Express Corp.',
        'type' => 'carrier',
        'contact' => 'Captain Eduardo Bautista',
        'email' => 'dispatch@fastcargoph.com',
        'phone' => '+63 2 8551 7730',
        'address' => 'FastCargo Freight Terminal, Domestic Road, Pasay City',
        'tax_id' => '004-912-883-000',
        'bank' => 'BPI Family Bank - Acct # 0921-4458-12',
        'terms' => 'Net 15 Days',
        'status' => 'active',
        'rating' => 4.90
    ],
    [
        'code' => 'SUP-CAR-02',
        'name' => 'TransLuzon Freight Haulers Inc.',
        'type' => 'carrier',
        'contact' => 'Bernardo Santos Jr.',
        'email' => 'ops@transluzonfreight.com',
        'phone' => '+63 45 499 2201',
        'address' => 'North Luzon Expressway Logistics Park, Balagtas, Bulacan',
        'tax_id' => '007-331-509-000',
        'bank' => 'Metrobank - Acct # 203-7-203881-9',
        'terms' => 'Net 30 Days',
        'status' => 'active',
        'rating' => 4.65
    ],
    [
        'code' => 'SUP-TEL-03',
        'name' => 'Telematics Global GPS Systems Inc.',
        'type' => 'electronics',
        'contact' => 'Danilo Lim',
        'email' => 'support@telematicsgps.com.ph',
        'phone' => '+63 2 8920 4455',
        'address' => 'Unit 1402 Cyberpod Two, Eastwood City, Libis, Quezon City',
        'tax_id' => '008-112-984-000',
        'bank' => 'Security Bank - Acct # 0000-0294-8172',
        'terms' => 'Net 45 Days',
        'status' => 'active',
        'rating' => 4.75
    ],
    [
        'code' => 'SUP-LIO-04',
        'name' => 'PowerVolt Industrial Lithium Battery Corp.',
        'type' => 'batteries_energy',
        'contact' => 'Engr. Sarah Jane Villanueva',
        'email' => 'b2b@powervolt-lithium.com',
        'phone' => '+63 49 545 8890',
        'address' => 'Light Industry & Science Park II, Calamba City, Laguna',
        'tax_id' => '006-724-119-000',
        'bank' => 'UnionBank of the Philippines - Acct # 1094-8839-2019',
        'terms' => 'Net 30 Days',
        'status' => 'active',
        'rating' => 4.92
    ],
    [
        'code' => 'SUP-SFT-05',
        'name' => 'Apex Tactical & Safety Gear Manufacturing',
        'type' => 'safety_apparel',
        'contact' => 'Clarisse Mendoza',
        'email' => 'procurement@apexsafetygear.ph',
        'phone' => '+63 2 8711 0033',
        'address' => 'Industrial Valley Complex, Marikina City, Metro Manila',
        'tax_id' => '003-881-420-000',
        'bank' => 'RCBC Commercial - Acct # 902-881-2291',
        'terms' => 'Net 30 Days',
        'status' => 'active',
        'rating' => 4.80
    ],
    [
        'code' => 'SUP-PKG-06',
        'name' => 'EcoBox Industrial Crates & Logistics Supplies',
        'type' => 'packaging',
        'contact' => 'Rodrigo Tan',
        'email' => 'orders@ecoboxph.com',
        'phone' => '+63 2 8366 1200',
        'address' => 'Paso de Blas Road, Valenzuela City, Metro Manila',
        'tax_id' => '001-923-458-000',
        'bank' => 'China Bank - Acct # 128-093-4410',
        'terms' => 'Net 15 Days',
        'status' => 'active',
        'rating' => 4.60
    ]
];

$supplierIds = [];
foreach ($suppliersData as $s) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE supplier_code = ? OR company_name = ?");
        $stmt->execute([$s['code'], $s['name']]);
        $sId = $stmt->fetchColumn();
        if (!$sId) {
            $ins = $pdo->prepare("
                INSERT INTO suppliers (supplier_code, company_name, supplier_type, contact_person, email, phone, address, tax_id, bank_details, payment_terms, status, rating, is_archived, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($isPgsql ? 'FALSE' : '0') . ", ?)
            ");
            $ins->execute([$s['code'], $s['name'], $s['type'], $s['contact'], $s['email'], $s['phone'], $s['address'], $s['tax_id'], $s['bank'], $s['terms'], $s['status'], $s['rating'], $adminUserId]);
            $sId = $pdo->lastInsertId();
        } else {
            // Update details
            $up = $pdo->prepare("UPDATE suppliers SET supplier_type = ?, rating = ?, status = 'active' WHERE id = ?");
            $up->execute([$s['type'], $s['rating'], $sId]);
        }
        $supplierIds[$s['code']] = (int)$sId;
    } catch (Throwable $e) {
        echo "  Notice (Supplier {$s['code']}): " . $e->getMessage() . "\n";
    }
}
syncSequence($pdo, 'suppliers', $isPgsql);

// Seed Privacy Acknowledgements and Performance for suppliers
foreach ($supplierIds as $sCode => $sId) {
    try {
        // Privacy Ack
        $chkAck = $pdo->prepare("SELECT id FROM supplier_privacy_acknowledgements WHERE supplier_id = ?");
        $chkAck->execute([$sId]);
        if (!$chkAck->fetchColumn()) {
            $insAck = $pdo->prepare("
                INSERT INTO supplier_privacy_acknowledgements (supplier_id, acknowledged_by, acknowledgement_text, policy_version, recorded_by, acknowledged_at)
                VALUES (?, 'Official Company Representative', 'Acknowledged compliance with Philippine Data Privacy Act RA 10173 and Tour-Sphere Logistics Vendor Privacy Guidelines.', '2026-v1.0', ?, CURRENT_TIMESTAMP)
            ");
            $insAck->execute([$sId, $adminUserId]);
        }

        // Supplier Performance across quarters
        $chkPerf = $pdo->prepare("SELECT id FROM supplier_performance WHERE supplier_id = ?");
        $chkPerf->execute([$sId]);
        if (!$chkPerf->fetchColumn()) {
            $periods = [
                ['period' => '2026-Q1', 'ontime' => 96.5, 'qual' => 98.0, 'resp' => 95.0],
                ['period' => '2026-Q2', 'ontime' => 97.8, 'qual' => 99.2, 'resp' => 96.5],
                ['period' => '2026-Q3', 'ontime' => 98.4, 'qual' => 98.8, 'resp' => 97.2],
            ];
            foreach ($periods as $p) {
                $insPerf = $pdo->prepare("
                    INSERT INTO supplier_performance (supplier_id, period, on_time_delivery, quality_rate, response_time)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $insPerf->execute([$sId, $p['period'], $p['ontime'], $p['qual'], $p['resp']]);
            }
        }
    } catch (Throwable $e) {
        echo "  Notice (Supplier Aux {$sCode}): " . $e->getMessage() . "\n";
    }
}
syncSequence($pdo, 'supplier_performance', $isPgsql);
syncSequence($pdo, 'supplier_privacy_acknowledgements', $isPgsql);

// ==============================================================================
// 4. PRODUCTS & WAREHOUSE INVENTORY
// ==============================================================================
echo "[4/12] Seeding Products, Inventory Allocations & Serial Numbers...\n";
$productsData = [
    [
        'sku' => 'SKU-EV-001',
        'name' => 'Tour-Sphere Urban E-Scooter Cruiser 500W',
        'desc' => 'Long-range electric scooter for tourist city excursions with dual disc brakes',
        'brand' => 'NWOW',
        'cat' => 'Electric Vehicles',
        'type' => 'vehicle',
        'unit' => 'unit',
        'price' => 28500.00,
        'reorder_pt' => 10,
        'reorder_qty' => 25,
        'stock' => 45,
        'min' => 5,
        'max' => 100,
        'barcode' => '893450010012',
        'serial_prefix' => 'TSEV-'
    ],
    [
        'sku' => 'SKU-EV-002',
        'name' => 'Tour-Sphere Heavy Cargo E-Trike 1200W',
        'desc' => 'High-capacity electric cargo trike with insulated rear parcel box',
        'brand' => 'NWOW',
        'cat' => 'Electric Vehicles',
        'type' => 'vehicle',
        'unit' => 'unit',
        'price' => 64500.00,
        'reorder_pt' => 5,
        'reorder_qty' => 15,
        'stock' => 18,
        'min' => 3,
        'max' => 40,
        'barcode' => '893450010029',
        'serial_prefix' => 'TSCT-'
    ],
    [
        'sku' => 'SKU-BAT-001',
        'name' => '60V 30Ah Lithium-Ion Power Pack Module',
        'desc' => 'IP67 waterproof high-density battery cell with smart thermal BMS protection',
        'brand' => 'PowerVolt',
        'cat' => 'Batteries & Energy',
        'type' => 'hazardous_goods',
        'unit' => 'piece',
        'price' => 16500.00,
        'reorder_pt' => 15,
        'reorder_qty' => 40,
        'stock' => 62,
        'min' => 10,
        'max' => 150,
        'barcode' => '893450020011',
        'serial_prefix' => 'PVBAT-'
    ],
    [
        'sku' => 'SKU-GPS-001',
        'name' => 'FleetTrack 4G LTE IoT GPS Tracker',
        'desc' => 'Real-time multi-constellation GPS/GLONASS unit with engine immobilizer and BLE beacons',
        'brand' => 'Telematics Global',
        'cat' => 'Telematics & Electronics',
        'type' => 'electronics',
        'unit' => 'piece',
        'price' => 3450.00,
        'reorder_pt' => 20,
        'reorder_qty' => 60,
        'stock' => 110,
        'min' => 15,
        'max' => 300,
        'barcode' => '893450030010',
        'serial_prefix' => 'TG4G-'
    ],
    [
        'sku' => 'SKU-SFT-001',
        'name' => 'DOT-Approved Aerodynamic Tour Safety Helmet',
        'desc' => 'Impact-resistant polycarbonate shell with quick-release chin strap and anti-glare visor',
        'brand' => 'Apex Tactical',
        'cat' => 'Safety & Protective Gear',
        'type' => 'safety',
        'unit' => 'piece',
        'price' => 1850.00,
        'reorder_pt' => 30,
        'reorder_qty' => 100,
        'stock' => 180,
        'min' => 25,
        'max' => 500,
        'barcode' => '893450040019',
        'serial_prefix' => 'APXHL-'
    ],
    [
        'sku' => 'SKU-SFT-002',
        'name' => 'High-Visibility Neon Safety Vest with Reflective Strips',
        'desc' => 'Class 2 breathable mesh safety vest with radio clip and transparent ID badge holder',
        'brand' => 'Apex Tactical',
        'cat' => 'Safety & Protective Gear',
        'type' => 'safety',
        'unit' => 'piece',
        'price' => 380.00,
        'reorder_pt' => 50,
        'reorder_qty' => 200,
        'stock' => 340,
        'min' => 40,
        'max' => 800,
        'barcode' => '893450040026',
        'serial_prefix' => 'APXVT-'
    ],
    [
        'sku' => 'SKU-WHS-001',
        'name' => 'Industrial Hydraulic Hand Pallet Truck 3000kg',
        'desc' => 'Heavy-duty steel fork pallet jack with polyurethane non-marking steer wheels',
        'brand' => 'GlobalSCM Heavy',
        'cat' => 'Warehouse Equipment',
        'type' => 'equipment',
        'unit' => 'unit',
        'price' => 18900.00,
        'reorder_pt' => 4,
        'reorder_qty' => 10,
        'stock' => 12,
        'min' => 2,
        'max' => 25,
        'barcode' => '893450050018',
        'serial_prefix' => 'GSPT-'
    ],
    [
        'sku' => 'SKU-WHS-002',
        'name' => 'Wireless 2D Handheld Barcode & QR Code Scanner',
        'desc' => 'Rugged Bluetooth 5.0 barcode scanner with 100m range and drop-proof casing',
        'brand' => 'Telematics Global',
        'cat' => 'Warehouse Equipment',
        'type' => 'electronics',
        'unit' => 'piece',
        'price' => 4200.00,
        'reorder_pt' => 8,
        'reorder_qty' => 25,
        'stock' => 28,
        'min' => 5,
        'max' => 80,
        'barcode' => '893450050025',
        'serial_prefix' => 'TGSC-'
    ],
    [
        'sku' => 'SKU-PKG-001',
        'name' => 'Heavy-Duty Reinforced Corrugated Shipping Crates',
        'desc' => 'Double-wall stackable export grade packaging box (600x400x350mm)',
        'brand' => 'EcoBox',
        'cat' => 'Packaging Materials',
        'type' => 'packaging',
        'unit' => 'bundle (25pcs)',
        'price' => 1450.00,
        'reorder_pt' => 40,
        'reorder_qty' => 150,
        'stock' => 210,
        'min' => 30,
        'max' => 600,
        'barcode' => '893450060017',
        'serial_prefix' => null
    ],
    [
        'sku' => 'SKU-PRT-001',
        'name' => 'Pneumatic All-Terrain Tire 10x2.5 inch Tubeless',
        'desc' => 'Puncture-resistant anti-skid pneumatic rubber replacement tire for tour scooters',
        'brand' => 'NWOW',
        'cat' => 'Spare Parts',
        'type' => 'spare_parts',
        'unit' => 'piece',
        'price' => 950.00,
        'reorder_pt' => 25,
        'reorder_qty' => 80,
        'stock' => 85,
        'min' => 20,
        'max' => 250,
        'barcode' => '893450070016',
        'serial_prefix' => null
    ]
];

$productIds = [];
$primaryWhId = reset($warehouseIds);

foreach ($productsData as $p) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
        $stmt->execute([$p['sku']]);
        $pId = $stmt->fetchColumn();
        if (!$pId) {
            $ins = $pdo->prepare("
                INSERT INTO products (sku, product_name, description, brand, category, item_type, unit_measure, unit_price, reorder_point, reorder_quantity, current_stock, min_stock, max_stock, barcode, serial_number_prefix, status, is_archived, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', " . ($isPgsql ? 'FALSE' : '0') . ", ?)
            ");
            $ins->execute([$p['sku'], $p['name'], $p['desc'], $p['brand'], $p['cat'], $p['type'], $p['unit'], $p['price'], $p['reorder_pt'], $p['reorder_qty'], $p['stock'], $p['min'], $p['max'], $p['barcode'], $p['serial_prefix'], $adminUserId]);
            $pId = $pdo->lastInsertId();
        } else {
            $up = $pdo->prepare("UPDATE products SET current_stock = ?, unit_price = ?, status = 'active' WHERE id = ?");
            $up->execute([$p['stock'], $p['price'], $pId]);
        }
        $productIds[$p['sku']] = (int)$pId;

        // Allocate stock across warehouses
        $stockRem = $p['stock'];
        foreach ($warehouseIds as $wCode => $wId) {
            $alloc = (int)ceil($stockRem * 0.4);
            if ($alloc > $stockRem) $alloc = $stockRem;
            $stockRem -= $alloc;
            if ($alloc <= 0) $alloc = 2; // minimum demo presence

            if ($isPgsql) {
                $insWi = $pdo->prepare("
                    INSERT INTO warehouse_inventory (product_id, warehouse_id, quantity)
                    VALUES (?, ?, ?)
                    ON CONFLICT (product_id, warehouse_id) DO UPDATE SET quantity = EXCLUDED.quantity
                ");
                $insWi->execute([$pId, $wId, $alloc]);
            } else {
                $insWi = $pdo->prepare("
                    INSERT OR REPLACE INTO warehouse_inventory (product_id, warehouse_id, quantity)
                    VALUES (?, ?, ?)
                ");
                $insWi->execute([$pId, $wId, $alloc]);
            }
        }

        // Add serial numbers if traceable item
        if (!empty($p['serial_prefix'])) {
            for ($snIdx = 1; $snIdx <= 5; $snIdx++) {
                $snStr = $p['serial_prefix'] . str_pad((string)$snIdx, 5, '0', STR_PAD_LEFT);
                $chkSn = $pdo->prepare("SELECT id FROM product_serial_numbers WHERE serial_number = ?");
                $chkSn->execute([$snStr]);
                if (!$chkSn->fetchColumn()) {
                    $insSn = $pdo->prepare("
                        INSERT INTO product_serial_numbers (product_id, serial_number, lot_number, status, location, warehouse_id)
                        VALUES (?, ?, 'LOT-2026-A1', 'available', 'Staging Shelf A-01', ?)
                    ");
                    $insSn->execute([$pId, $snStr, $primaryWhId]);
                }
            }
        }
    } catch (Throwable $e) {
        echo "  Notice (Product {$p['sku']}): " . $e->getMessage() . "\n";
    }
}
syncSequence($pdo, 'products', $isPgsql);
syncSequence($pdo, 'warehouse_inventory', $isPgsql);
syncSequence($pdo, 'product_serial_numbers', $isPgsql);

// ==============================================================================
// 5. PURCHASE REQUISITIONS & REQUISITION ITEMS
// ==============================================================================
echo "[5/12] Seeding Purchase Requisitions & Items...\n";
$reqsData = [
    [
        'pr_num' => 'PR-2026-001',
        'title' => 'Q4 Fleet Replenishment: Electric Cruisers & Smart GPS Modules',
        'desc' => 'Urgent procurement for expansion of Intramuros and Clark Heritage Tour circuits.',
        'dept' => 'Fleet Operations',
        'req_date' => date('Y-m-d', strtotime('+15 days')),
        'pay_method' => 'Bank Transfer',
        'priority' => 'high',
        'total' => 604500.00,
        'status' => 'approved',
        'items' => [
            ['sku' => 'SKU-EV-001', 'qty' => 18, 'price' => 28500.00, 'note' => 'Tour fleet units for Manila hub'],
            ['sku' => 'SKU-GPS-001', 'qty' => 25, 'price' => 3450.00, 'note' => 'Telematics modules for live monitoring']
        ]
    ],
    [
        'pr_num' => 'PR-2026-002',
        'title' => 'Warehouse Safety Compliance & Protective Gear Re-stock',
        'desc' => 'Mandatory annual PPE refresh for warehouse handlers across Manila, Clark, and Cebu.',
        'dept' => 'Warehouse Logistics',
        'req_date' => date('Y-m-d', strtotime('+10 days')),
        'pay_method' => 'Net 30 Days',
        'priority' => 'medium',
        'total' => 148500.00,
        'status' => 'pending_review',
        'items' => [
            ['sku' => 'SKU-SFT-001', 'qty' => 60, 'price' => 1850.00, 'note' => 'Protective helmets for tour staff'],
            ['sku' => 'SKU-SFT-002', 'qty' => 100, 'price' => 380.00, 'note' => 'High-visibility safety vests']
        ]
    ],
    [
        'pr_num' => 'PR-2026-003',
        'title' => 'Emergency Battery Spares & Hydraulic Pallet Truck Order',
        'desc' => 'Backup energy buffer for cold and hilly tour itineraries in Northern Luzon.',
        'dept' => 'Technical Maintenance',
        'req_date' => date('Y-m-d', strtotime('+7 days')),
        'pay_method' => 'Corporate PO',
        'priority' => 'urgent',
        'total' => 367800.00,
        'status' => 'approved',
        'items' => [
            ['sku' => 'SKU-BAT-001', 'qty' => 20, 'price' => 16500.00, 'note' => '60V Lithium Power Packs'],
            ['sku' => 'SKU-WHS-001', 'qty' => 2, 'price' => 18900.00, 'note' => 'Heavy Pallet Jacks for Clark depot']
        ]
    ],
    [
        'pr_num' => 'PR-2026-004',
        'title' => 'Logistics Barcode Scanners & Packaging Materials',
        'desc' => 'Inbound processing upgrades for Batangas Port and Cebu distribution channels.',
        'dept' => 'Supply Chain IT',
        'req_date' => date('Y-m-d', strtotime('+20 days')),
        'pay_method' => 'Net 15 Days',
        'priority' => 'low',
        'total' => 104500.00,
        'status' => 'draft',
        'items' => [
            ['sku' => 'SKU-WHS-002', 'qty' => 15, 'price' => 4200.00, 'note' => 'Handheld Bluetooth 2D barcode scanners'],
            ['sku' => 'SKU-PKG-001', 'qty' => 28, 'price' => 1450.00, 'note' => 'Reinforced corrugated export shipping boxes']
        ]
    ],
    [
        'pr_num' => 'PR-2026-005',
        'title' => 'High-Capacity Cargo E-Trikes for Inter-Terminal Baggage',
        'desc' => 'Subic Bay cruise terminal guest luggage transfer units.',
        'dept' => 'Ground Hospitality Logistics',
        'req_date' => date('Y-m-d', strtotime('+30 days')),
        'pay_method' => 'Bank Transfer',
        'priority' => 'medium',
        'total' => 387000.00,
        'status' => 'pending_review',
        'items' => [
            ['sku' => 'SKU-EV-002', 'qty' => 6, 'price' => 64500.00, 'note' => 'Heavy electric cargo trikes with locks']
        ]
    ]
];

$prIds = [];
foreach ($reqsData as $req) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM purchase_requisitions WHERE pr_number = ?");
        $stmt->execute([$req['pr_num']]);
        $prId = $stmt->fetchColumn();
        if (!$prId) {
            $ins = $pdo->prepare("
                INSERT INTO purchase_requisitions (pr_number, title, description, requested_by, department, required_date, payment_method, priority, estimated_total, status, is_archived, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($isPgsql ? 'FALSE' : '0') . ", ?)
            ");
            $ins->execute([$req['pr_num'], $req['title'], $req['desc'], $adminUserId, $req['dept'], $req['req_date'], $req['pay_method'], $req['priority'], $req['total'], $req['status'], $adminUserId]);
            $prId = $pdo->lastInsertId();
        }
        $prIds[$req['pr_num']] = (int)$prId;

        // Items
        foreach ($req['items'] as $item) {
            $prodId = $productIds[$item['sku']] ?? null;
            if (!$prodId) continue;
            $chkItem = $pdo->prepare("SELECT id FROM requisition_items WHERE requisition_id = ? AND product_id = ?");
            $chkItem->execute([$prId, $prodId]);
            if (!$chkItem->fetchColumn()) {
                $insItem = $pdo->prepare("
                    INSERT INTO requisition_items (requisition_id, product_id, quantity, unit_price, notes)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $insItem->execute([$prId, $prodId, $item['qty'], $item['price'], $item['note']]);
            }
        }
    } catch (Throwable $e) {
        echo "  Notice (Requisition {$req['pr_num']}): " . $e->getMessage() . "\n";
    }
}
syncSequence($pdo, 'purchase_requisitions', $isPgsql);
syncSequence($pdo, 'requisition_items', $isPgsql);

// ==============================================================================
// 6. PURCHASE ORDERS, PO ITEMS & INVENTORY BATCHES
// ==============================================================================
echo "[6/12] Seeding Purchase Orders, PO Items & Goods Receipt Batches...\n";
$posData = [
    [
        'po_num' => 'PO-2026-101',
        'supplier_code' => 'SUP-NWOW-01',
        'wh_code' => 'WH-MNL-01',
        'order_date' => date('Y-m-d H:i:s', strtotime('-12 days')),
        'deliv_date' => date('Y-m-d H:i:s', strtotime('+5 days')),
        'terms' => 'Net 30 Days',
        'payment_method' => 'bank_transfer',
        'status' => 'approved',
        'approval' => 'approved',
        'total' => 570000.00,
        'items' => [
            ['sku' => 'SKU-EV-001', 'qty' => 20, 'rec_qty' => 0, 'price' => 28500.00]
        ]
    ],
    [
        'po_num' => 'PO-2026-102',
        'supplier_code' => 'SUP-LIO-04',
        'wh_code' => 'WH-CRK-02',
        'order_date' => date('Y-m-d H:i:s', strtotime('-25 days')),
        'deliv_date' => date('Y-m-d H:i:s', strtotime('-5 days')),
        'terms' => 'Net 30 Days',
        'payment_method' => 'bank_transfer',
        'status' => 'received',
        'approval' => 'approved',
        'total' => 495000.00,
        'items' => [
            ['sku' => 'SKU-BAT-001', 'qty' => 30, 'rec_qty' => 30, 'price' => 16500.00]
        ],
        'batch' => [
            'receipt_num' => 'GRN-2026-0081',
            'batch_num' => 'BAT-2026-09A',
            'qty' => 30,
            'expiry' => date('Y-m-d', strtotime('+730 days')),
            'brand' => 'PowerVolt Heavy Cell',
            'quality' => 'accepted',
            'notes' => 'Passed full BMS voltage and insulation resistance bench testing'
        ]
    ],
    [
        'po_num' => 'PO-2026-103',
        'supplier_code' => 'SUP-TEL-03',
        'wh_code' => 'WH-MNL-01',
        'order_date' => date('Y-m-d H:i:s', strtotime('-18 days')),
        'deliv_date' => date('Y-m-d H:i:s', strtotime('-2 days')),
        'terms' => 'Net 45 Days',
        'payment_method' => 'corporate_check',
        'status' => 'received',
        'approval' => 'approved',
        'total' => 172500.00,
        'items' => [
            ['sku' => 'SKU-GPS-001', 'qty' => 50, 'rec_qty' => 50, 'price' => 3450.00]
        ],
        'batch' => [
            'receipt_num' => 'GRN-2026-0092',
            'batch_num' => 'TEL-IOT-26B',
            'qty' => 50,
            'expiry' => null,
            'brand' => 'Telematics Global 4G',
            'quality' => 'accepted',
            'notes' => 'IMEI activation and firmware sync verified OK'
        ]
    ],
    [
        'po_num' => 'PO-2026-104',
        'supplier_code' => 'SUP-SFT-05',
        'wh_code' => 'WH-CEB-03',
        'order_date' => date('Y-m-d H:i:s', strtotime('-5 days')),
        'deliv_date' => date('Y-m-d H:i:s', strtotime('+8 days')),
        'terms' => 'Net 30 Days',
        'payment_method' => 'bank_transfer',
        'status' => 'pending',
        'approval' => 'pending_review',
        'total' => 167500.00,
        'items' => [
            ['sku' => 'SKU-SFT-001', 'qty' => 50, 'rec_qty' => 0, 'price' => 1850.00],
            ['sku' => 'SKU-SFT-002', 'qty' => 197, 'rec_qty' => 0, 'price' => 380.00]
        ]
    ],
    [
        'po_num' => 'PO-2026-105',
        'supplier_code' => 'SUP-PKG-06',
        'wh_code' => 'WH-BTG-05',
        'order_date' => date('Y-m-d H:i:s', strtotime('-3 days')),
        'deliv_date' => date('Y-m-d H:i:s', strtotime('+12 days')),
        'terms' => 'Net 15 Days',
        'payment_method' => 'bank_transfer',
        'status' => 'pending',
        'approval' => 'pending_review',
        'total' => 87000.00,
        'items' => [
            ['sku' => 'SKU-PKG-001', 'qty' => 60, 'rec_qty' => 0, 'price' => 1450.00]
        ]
    ]
];

$poIds = [];
foreach ($posData as $po) {
    $sId = $supplierIds[$po['supplier_code']] ?? null;
    $wId = $warehouseIds[$po['wh_code']] ?? $primaryWhId;
    if (!$sId) continue;

    try {
        $stmt = $pdo->prepare("SELECT id FROM purchase_orders WHERE po_number = ?");
        $stmt->execute([$po['po_num']]);
        $poId = $stmt->fetchColumn();
        if (!$poId) {
            $ins = $pdo->prepare("
                INSERT INTO purchase_orders (po_number, supplier_id, warehouse_id, order_date, expected_delivery, terms, payment_method, status, approval_status, total_amount, is_archived, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($isPgsql ? 'FALSE' : '0') . ", ?)
            ");
            $ins->execute([$po['po_num'], $sId, $wId, $po['order_date'], $po['deliv_date'], $po['terms'], $po['payment_method'], $po['status'], $po['approval'], $po['total'], $adminUserId]);
            $poId = $pdo->lastInsertId();
        }
        $poIds[$po['po_num']] = (int)$poId;

        // PO items
        $firstPoItemId = null;
        foreach ($po['items'] as $item) {
            $pId = $productIds[$item['sku']] ?? null;
            if (!$pId) continue;
            $chkItem = $pdo->prepare("SELECT id FROM purchase_order_items WHERE po_id = ? AND product_id = ?");
            $chkItem->execute([$poId, $pId]);
            $poItemId = $chkItem->fetchColumn();
            if (!$poItemId) {
                $totalPrice = $item['qty'] * $item['price'];
                $insItem = $pdo->prepare("
                    INSERT INTO purchase_order_items (po_id, product_id, quantity, received_quantity, unit_price, total_price, expected_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $insItem->execute([$poId, $pId, $item['qty'], $item['rec_qty'], $item['price'], $totalPrice, date('Y-m-d', strtotime($po['deliv_date']))]);
                $poItemId = $pdo->lastInsertId();
            }
            if ($firstPoItemId === null) $firstPoItemId = (int)$poItemId;
        }

        // Inventory Batch if goods received
        if (!empty($po['batch']) && $firstPoItemId) {
            $bInfo = $po['batch'];
            $chkBatch = $pdo->prepare("SELECT id FROM inventory_batches WHERE receipt_number = ?");
            $chkBatch->execute([$bInfo['receipt_num']]);
            if (!$chkBatch->fetchColumn()) {
                $firstPId = $productIds[$po['items'][0]['sku']] ?? 1;
                $insBatch = $pdo->prepare("
                    INSERT INTO inventory_batches (receipt_number, po_id, po_item_id, product_id, supplier_id, warehouse_id, batch_number, received_quantity, available_quantity, expiry_date, brand_snapshot, quality_status, quality_notes, received_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insBatch->execute([
                    $bInfo['receipt_num'],
                    $poId,
                    $firstPoItemId,
                    $firstPId,
                    $sId,
                    $wId,
                    $bInfo['batch_num'],
                    $bInfo['qty'],
                    $bInfo['qty'],
                    $bInfo['expiry'],
                    $bInfo['brand'],
                    $bInfo['quality'],
                    $bInfo['notes'],
                    $adminUserId
                ]);
            }
        }
    } catch (Throwable $e) {
        echo "  Notice (PO {$po['po_num']}): " . $e->getMessage() . "\n";
    }
}
syncSequence($pdo, 'purchase_orders', $isPgsql);
syncSequence($pdo, 'purchase_order_items', $isPgsql);
syncSequence($pdo, 'inventory_batches', $isPgsql);

// ==============================================================================
// 7. INVENTORY TRANSACTIONS & INCIDENTS
// ==============================================================================
echo "[7/12] Seeding Inventory Stock Movements & Incidents...\n";
$firstProdId = reset($productIds);
$secondProdId = next($productIds) ?: $firstProdId;

$transactionsData = [
    [
        'prod_id' => $firstProdId,
        'type' => 'receiving',
        'qty' => 25,
        'prev' => 20,
        'new' => 45,
        'ref' => 'GRN-2026-0081',
        'wh_id' => $primaryWhId,
        'notes' => 'Received from NWOW Assembly Facility under PO-2026-101'
    ],
    [
        'prod_id' => $secondProdId,
        'type' => 'issuance',
        'qty' => 4,
        'prev' => 22,
        'new' => 18,
        'ref' => 'DISP-MNL-409',
        'wh_id' => $primaryWhId,
        'notes' => 'Dispatched for Intramuros Tourist Circuit weekend tour operations'
    ],
    [
        'prod_id' => $firstProdId,
        'type' => 'transfer',
        'qty' => 5,
        'prev' => 45,
        'new' => 40,
        'ref' => 'TRF-MNL-CRK-01',
        'wh_id' => $primaryWhId,
        'notes' => 'Inter-branch stock rebalance to Clark Freeport Depot'
    ],
    [
        'prod_id' => $secondProdId,
        'type' => 'adjustment',
        'qty' => 1,
        'prev' => 17,
        'new' => 18,
        'ref' => 'AUDIT-ADJ-2026-09',
        'wh_id' => $primaryWhId,
        'notes' => 'Reconciliation after quarterly physical cycle count'
    ]
];

foreach ($transactionsData as $t) {
    try {
        $chk = $pdo->prepare("SELECT id FROM inventory_transactions WHERE reference_document = ?");
        $chk->execute([$t['ref']]);
        if (!$chk->fetchColumn()) {
            $ins = $pdo->prepare("
                INSERT INTO inventory_transactions (product_id, transaction_type, quantity, previous_balance, new_balance, reference_document, warehouse_id, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([$t['prod_id'], $t['type'], $t['qty'], $t['prev'], $t['new'], $t['ref'], $t['wh_id'], $t['notes'], $adminUserId]);
        }
    } catch (Throwable $e) {
        echo "  Notice (Transaction {$t['ref']}): " . $e->getMessage() . "\n";
    }
}
syncSequence($pdo, 'inventory_transactions', $isPgsql);

// Incidents
$incidentsData = [
    [
        'inc_num' => 'INC-2026-001',
        'prod_id' => $firstProdId,
        'wh_id' => $primaryWhId,
        'qty' => 1,
        'issue' => 'transit_damage',
        'desc' => 'Front handlebar acrylic visor scuffed during inter-island Ro-Ro sea crossing',
        'status' => 'resolved',
        'resolution' => 'Replaced visor assembly from backup spare bin; unit returned to active pool',
        'res_by' => $adminUserId
    ],
    [
        'inc_num' => 'INC-2026-002',
        'prod_id' => $secondProdId,
        'wh_id' => $primaryWhId,
        'qty' => 2,
        'issue' => 'packaging_defect',
        'desc' => 'Minor carton seal puncture on shipment pallet; items uninjured after inspection',
        'status' => 'resolved',
        'resolution' => 'Repackaged with reinforced strapping and approved for deployment',
        'res_by' => $adminUserId
    ],
    [
        'inc_num' => 'INC-2026-003',
        'prod_id' => $firstProdId,
        'wh_id' => $warehouseIds['WH-CRK-02'] ?? $primaryWhId,
        'qty' => 1,
        'issue' => 'sensor_calibration',
        'desc' => 'Speed sensor showing intermittent reading after high-humidity storage',
        'status' => 'reported',
        'resolution' => null,
        'res_by' => null
    ]
];

foreach ($incidentsData as $inc) {
    try {
        $chk = $pdo->prepare("SELECT id FROM inventory_incidents WHERE incident_number = ?");
        $chk->execute([$inc['inc_num']]);
        if (!$chk->fetchColumn()) {
            $ins = $pdo->prepare("
                INSERT INTO inventory_incidents (incident_number, product_id, warehouse_id, quantity, issue_type, description, status, resolution, created_by, resolved_by, resolved_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($inc['status'] === 'resolved' ? 'CURRENT_TIMESTAMP' : 'NULL') . ")
            ");
            $ins->execute([$inc['inc_num'], $inc['prod_id'], $inc['wh_id'], $inc['qty'], $inc['issue'], $inc['desc'], $inc['status'], $inc['resolution'], $adminUserId, $inc['res_by']]);
        }
    } catch (Throwable $e) {
        echo "  Notice (Incident {$inc['inc_num']}): " . $e->getMessage() . "\n";
    }
}
syncSequence($pdo, 'inventory_incidents', $isPgsql);

// ==============================================================================
// 8. PROCUREMENT CONTRACTS
// ==============================================================================
echo "[8/12] Seeding Procurement Contracts...\n";
$contractsData = [
    [
        'num' => 'CTR-2026-NWOW-01',
        'sup_code' => 'SUP-NWOW-01',
        'title' => 'Master Fleet Supply & Warranty Maintenance Framework 2026-2027',
        'desc' => 'Exclusive procurement agreement for 120 e-mobility vehicles with manufacturer parts warranty.',
        'start' => date('Y-m-d', strtotime('-60 days')),
        'end' => date('Y-m-d', strtotime('+305 days')),
        'value' => 3850000.00,
        'status' => 'active',
        'approval' => 'approved'
    ],
    [
        'num' => 'CTR-2026-FAST-02',
        'sup_code' => 'SUP-CAR-01',
        'title' => 'Inter-Island Freight Transportation Service Level Agreement',
        'desc' => 'Scheduled nationwide 3PL linehaul coverage between Luzon, Visayas, and Mindanao hubs.',
        'start' => date('Y-m-d', strtotime('-30 days')),
        'end' => date('Y-m-d', strtotime('+335 days')),
        'value' => 2200000.00,
        'status' => 'active',
        'approval' => 'approved'
    ],
    [
        'num' => 'CTR-2026-LIO-03',
        'sup_code' => 'SUP-LIO-04',
        'title' => 'Li-Ion Battery Cell Consignment & Recycling Contract',
        'desc' => 'Supply and circular lifecycle end-of-life battery pack recycling management.',
        'start' => date('Y-m-d', strtotime('-15 days')),
        'end' => date('Y-m-d', strtotime('+350 days')),
        'value' => 1450000.00,
        'status' => 'active',
        'approval' => 'approved'
    ],
    [
        'num' => 'CTR-2026-TEL-04',
        'sup_code' => 'SUP-TEL-03',
        'title' => 'Fleet IoT Telematics & eSIM Connectivity Master Agreement',
        'desc' => 'Cloud platform tracking integration and hardware support for 500 active assets.',
        'start' => date('Y-m-d'),
        'end' => date('Y-m-d', strtotime('+365 days')),
        'value' => 680000.00,
        'status' => 'draft',
        'approval' => 'pending_review'
    ]
];

$contractIds = [];
foreach ($contractsData as $ctr) {
    $sId = $supplierIds[$ctr['sup_code']] ?? null;
    if (!$sId) continue;
    try {
        $stmt = $pdo->prepare("SELECT id FROM procurement_contracts WHERE contract_number = ?");
        $stmt->execute([$ctr['num']]);
        $cId = $stmt->fetchColumn();
        if (!$cId) {
            $ins = $pdo->prepare("
                INSERT INTO procurement_contracts (contract_number, supplier_id, title, description, start_date, end_date, total_value, status, approval_status, is_archived, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($isPgsql ? 'FALSE' : '0') . ", ?)
            ");
            $ins->execute([$ctr['num'], $sId, $ctr['title'], $ctr['desc'], $ctr['start'], $ctr['end'], $ctr['value'], $ctr['status'], $ctr['approval'], $adminUserId]);
            $cId = $pdo->lastInsertId();
        }
        $contractIds[$ctr['num']] = (int)$cId;
    } catch (Throwable $e) {
        echo "  Notice (Contract {$ctr['num']}): " . $e->getMessage() . "\n";
    }
}
syncSequence($pdo, 'procurement_contracts', $isPgsql);

// ==============================================================================
// 9. LOGISTICS FREIGHT BIDDING TENDERS & CARRIER BIDS
// ==============================================================================
echo "[9/12] Seeding Freight Bidding Tenders & Carrier Proposals...\n";
$tendersData = [
    [
        'code' => 'SPOT-2026-001',
        'title' => 'Manila Distribution Hub to Clark Logistics Hub Run',
        'type' => 'spot_auction',
        'mode' => 'road',
        'origin' => 'Metro Manila Central Hub, Parañaque',
        'dest' => 'Clark Freeport Depot, Pampanga',
        'cargo' => 'Electric Vehicles & Batteries (Hazmat Compliant)',
        'volume' => '40ft Wing-Van Trailer (18 Tons)',
        'target_rate' => 38000.00,
        'status' => 'open',
        'bids' => [
            ['sup_code' => 'SUP-CAR-01', 'name' => 'FastCargo Logistics Express', 'amount' => 36500.00, 'days' => 1, 'score' => 96.0, 'notes' => 'Dedicated wing van with hazmat certified driver'],
            ['sup_code' => 'SUP-CAR-02', 'name' => 'TransLuzon Freight Haulers', 'amount' => 37800.00, 'days' => 1, 'score' => 91.5, 'notes' => 'Standard trailer with GPS seal tracking']
        ]
    ],
    [
        'code' => 'TND-2026-Q4',
        'title' => 'Batangas Port to Cebu Harbour Inter-Island Ro-Ro Lane',
        'type' => 'term_tender',
        'mode' => 'sea',
        'origin' => 'Batangas Port Ro-Ro Terminal',
        'dest' => 'Cebu Port Authority Terminal Pier 4',
        'cargo' => 'Assembled Tour Scooters and Spare Parts Cages',
        'volume' => '2x 40ft High-Cube Maritime Containers',
        'target_rate' => 85000.00,
        'status' => 'under_evaluation',
        'bids' => [
            ['sup_code' => 'SUP-CAR-01', 'name' => 'FastCargo Logistics Express', 'amount' => 81500.00, 'days' => 2, 'score' => 95.0, 'notes' => 'Direct weekly sailing booking with real-time AIS marine tracking'],
            ['sup_code' => 'SUP-CAR-02', 'name' => 'TransLuzon Freight Haulers', 'amount' => 84000.00, 'days' => 3, 'score' => 88.0, 'notes' => 'Includes drayage at both port gates']
        ]
    ],
    [
        'code' => 'SPOT-2026-DVO',
        'title' => 'Cebu to Davao Regional Cross-Dock Scheduled Feeder',
        'type' => 'spot_auction',
        'mode' => 'sea_road',
        'origin' => 'Cebu Inter-Island Depot, Cebu City',
        'dest' => 'Davao Southern Cross-Dock Facility, Sasa',
        'cargo' => 'GPS Trackers, Electronic Consoles & Helmets',
        'volume' => '24 Pallets (Standard Euro)',
        'target_rate' => 52000.00,
        'status' => 'open',
        'bids' => [
            ['sup_code' => 'SUP-CAR-01', 'name' => 'FastCargo Logistics Express', 'amount' => 49500.00, 'days' => 2, 'score' => 94.0, 'notes' => 'Guaranteed 48hr turnaround from wharf to cross-dock warehouse']
        ]
    ]
];

$tenderIds = [];
foreach ($tendersData as $td) {
    try {
        $chk = $pdo->prepare("SELECT id FROM bidding_tenders WHERE tender_code = ?");
        $chk->execute([$td['code']]);
        $tId = $chk->fetchColumn();
        if (!$tId) {
            $ins = $pdo->prepare("
                INSERT INTO bidding_tenders (tender_code, title, tender_type, transport_mode, origin, destination, cargo_type, estimated_volume, target_rate, currency, deadline, status, is_archived, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PHP', ?, ?, " . ($isPgsql ? 'FALSE' : '0') . ", ?)
            ");
            $deadline = date('Y-m-d H:i:s', strtotime('+14 days'));
            $ins->execute([$td['code'], $td['title'], $td['type'], $td['mode'], $td['origin'], $td['dest'], $td['cargo'], $td['volume'], $td['target_rate'], $deadline, $td['status'], $adminUserId]);
            $tId = $pdo->lastInsertId();
        }
        $tenderIds[$td['code']] = (int)$tId;

        // Bids
        foreach ($td['bids'] as $bid) {
            $cId = $supplierIds[$bid['sup_code']] ?? null;
            $chkB = $pdo->prepare("SELECT id FROM bidding_bids WHERE tender_id = ? AND carrier_name = ?");
            $chkB->execute([$tId, $bid['name']]);
            if (!$chkB->fetchColumn()) {
                $insB = $pdo->prepare("
                    INSERT INTO bidding_bids (tender_id, carrier_id, carrier_name, bid_amount, currency, transit_time_days, carrier_score, notes, status)
                    VALUES (?, ?, ?, ?, 'PHP', ?, ?, ?, 'submitted')
                ");
                $insB->execute([$tId, $cId, $bid['name'], $bid['amount'], $bid['days'], $bid['score'], $bid['notes']]);
            }
        }
    } catch (Throwable $e) {
        echo "  Notice (Tender {$td['code']}): " . $e->getMessage() . "\n";
    }
}
syncSequence($pdo, 'bidding_tenders', $isPgsql);
syncSequence($pdo, 'bidding_bids', $isPgsql);

// ==============================================================================
// 10. LOGISTICS SHIPMENTS & DISPATCH
// ==============================================================================
echo "[10/12] Seeding Logistics Shipments...\n";
$firstPoId = reset($poIds);
$secondPoId = next($poIds) ?: $firstPoId;

$shipmentsData = [
    [
        'ship_id' => 'SHP-2026-001',
        'po_id' => $firstPoId,
        'carrier' => 'FastCargo Logistics Express Corp.',
        'tracking' => 'FCX-PH-9920148',
        'mode' => 'road',
        'origin' => 'Metro Manila Central Hub, Parañaque',
        'dest' => 'Clark Freeport Regional Depot, Pampanga',
        'dept_date' => date('Y-m-d H:i:s', strtotime('-2 days')),
        'exp_arr' => date('Y-m-d H:i:s', strtotime('+1 days')),
        'status' => 'in_transit',
        'cost' => 36500.00,
        'notes' => 'Linehaul transport carrying 20 electric scooters under protective tarpaulin'
    ],
    [
        'ship_id' => 'SHP-2026-002',
        'po_id' => $secondPoId,
        'carrier' => 'TransLuzon Freight Haulers Inc.',
        'tracking' => 'TLZ-8831920',
        'mode' => 'sea',
        'origin' => 'Batangas Port Ro-Ro Terminal',
        'dest' => 'Cebu Inter-Island Maritime Terminal',
        'dept_date' => date('Y-m-d H:i:s', strtotime('-6 days')),
        'exp_arr' => date('Y-m-d H:i:s', strtotime('-3 days')),
        'act_arr' => date('Y-m-d H:i:s', strtotime('-3 days')),
        'status' => 'delivered',
        'cost' => 54000.00,
        'notes' => 'Delivered to Cebu Pier 4 and checked in by warehouse receiver'
    ],
    [
        'ship_id' => 'SHP-2026-003',
        'po_id' => $firstPoId,
        'carrier' => '2GO Freight Sea Cargo',
        'tracking' => '2GO-M2D-55018',
        'mode' => 'sea',
        'origin' => 'Manila North Harbor PIER 16',
        'dest' => 'Davao Southern Cross-Dock Facility, Sasa',
        'dept_date' => date('Y-m-d H:i:s', strtotime('-1 days')),
        'exp_arr' => date('Y-m-d H:i:s', strtotime('+3 days')),
        'status' => 'in_transit',
        'cost' => 68500.00,
        'notes' => 'Inter-island vessel voyage #2026-S1 carrying spare batteries and helmets'
    ],
    [
        'ship_id' => 'SHP-2026-004',
        'po_id' => $secondPoId,
        'carrier' => 'FastCargo Logistics Express Corp.',
        'tracking' => 'FCX-PH-9920391',
        'mode' => 'express',
        'origin' => 'Quezon City Tech Center',
        'dest' => 'Metro Manila Central Hub',
        'dept_date' => date('Y-m-d H:i:s', strtotime('+1 days')),
        'exp_arr' => date('Y-m-d H:i:s', strtotime('+2 days')),
        'status' => 'pending',
        'cost' => 8500.00,
        'notes' => 'Scheduled courier dispatch for GPS IoT tracking gateways'
    ]
];

$shipmentIds = [];
foreach ($shipmentsData as $s) {
    try {
        $chk = $pdo->prepare("SELECT id FROM shipments WHERE shipment_id = ?");
        $chk->execute([$s['ship_id']]);
        $sId = $chk->fetchColumn();
        if (!$sId) {
            $ins = $pdo->prepare("
                INSERT INTO shipments (shipment_id, po_id, carrier, tracking_number, mode, origin, destination, departure_date, expected_arrival, actual_arrival, status, freight_cost, notes, is_archived, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($isPgsql ? 'FALSE' : '0') . ", ?)
            ");
            $ins->execute([
                $s['ship_id'],
                $s['po_id'],
                $s['carrier'],
                $s['tracking'],
                $s['mode'],
                $s['origin'],
                $s['dest'],
                $s['dept_date'],
                $s['exp_arr'],
                $s['act_arr'] ?? null,
                $s['status'],
                $s['cost'],
                $s['notes'],
                $adminUserId
            ]);
            $sId = $pdo->lastInsertId();
        }
        $shipmentIds[$s['ship_id']] = (int)$sId;
    } catch (Throwable $e) {
        echo "  Notice (Shipment {$s['ship_id']}): " . $e->getMessage() . "\n";
    }
}
syncSequence($pdo, 'shipments', $isPgsql);

// ==============================================================================
// 11. SUPPLY CHAIN DOCUMENTS & DIGITAL ARCHIVES
// ==============================================================================
echo "[11/12] Seeding Supply Chain Documents...\n";
$firstShipId = reset($shipmentIds);

$docsData = [
    [
        'doc_num' => 'DOC-BOL-2026-001',
        'type' => 'bol',
        'title' => 'Master Bill of Lading - Manila to Clark Fleet Transfer',
        'desc' => 'Certified multimodal bill of lading for 20 electric scooters with seal integrity check.',
        'path' => 'uploads/documents/BOL_SHP_2026_001.pdf',
        'mod' => 'shipments',
        'rel_id' => $firstShipId,
        'status' => 'approved'
    ],
    [
        'doc_num' => 'DOC-INV-2026-042',
        'type' => 'invoice',
        'title' => 'Commercial Tax Invoice - NWOW Order PO-2026-101',
        'desc' => 'Official BIR-registered commercial sales invoice with VAT computation.',
        'path' => 'uploads/documents/INV_PO_2026_101.pdf',
        'mod' => 'purchase_orders',
        'rel_id' => $firstPoId,
        'status' => 'approved'
    ],
    [
        'doc_num' => 'DOC-PKL-2026-088',
        'type' => 'packing_list',
        'title' => 'Detailed Container Packing List & Weight Certificate',
        'desc' => 'Itemized pallet count with individual gross and net weight certification.',
        'path' => 'uploads/documents/PKL_2026_088.pdf',
        'mod' => 'shipments',
        'rel_id' => $firstShipId,
        'status' => 'approved'
    ],
    [
        'doc_num' => 'DOC-COA-2026-015',
        'type' => 'certificate',
        'title' => 'UN38.3 Lithium Battery Safety Transport Certificate',
        'desc' => 'Official safety validation certificate for 60V lithium power modules under aviation & Ro-Ro rules.',
        'path' => 'uploads/documents/COA_UN383_BAT.pdf',
        'mod' => 'products',
        'rel_id' => $firstProdId,
        'status' => 'approved'
    ],
    [
        'doc_num' => 'DOC-CTR-2026-001',
        'type' => 'contract',
        'title' => 'Executed SLA Agreement - FastCargo Logistics',
        'desc' => 'Countersigned digital copy of the 2026 linehaul service level agreement.',
        'path' => 'uploads/documents/CTR_FastCargo_Executed.pdf',
        'mod' => 'procurement_contracts',
        'rel_id' => reset($contractIds) ?: 1,
        'status' => 'approved'
    ],
    [
        'doc_num' => 'DOC-RPT-2026-091',
        'type' => 'report',
        'title' => 'Quarterly Inbound Inventory Quality Audit Report',
        'desc' => 'Compiled acceptance inspection metrics and defect rates for Q3 2026 shipments.',
        'path' => 'uploads/documents/QA_Audit_Q3_2026.pdf',
        'mod' => 'reports',
        'rel_id' => 1,
        'status' => 'approved'
    ]
];

foreach ($docsData as $d) {
    try {
        $chk = $pdo->prepare("SELECT id FROM documents WHERE document_number = ?");
        $chk->execute([$d['doc_num']]);
        if (!$chk->fetchColumn()) {
            $ins = $pdo->prepare("
                INSERT INTO documents (document_number, document_type, title, description, file_path, related_module, related_id, status, is_archived, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, " . ($isPgsql ? 'FALSE' : '0') . ", ?)
            ");
            $ins->execute([$d['doc_num'], $d['type'], $d['title'], $d['desc'], $d['path'], $d['mod'], $d['rel_id'], $d['status'], $adminUserId]);
        }
    } catch (Throwable $e) {
        echo "  Notice (Document {$d['doc_num']}): " . $e->getMessage() . "\n";
    }
}
syncSequence($pdo, 'documents', $isPgsql);

// ==============================================================================
// 12. AUDIT LOGS
// ==============================================================================
echo "[12/12] Generating Rich System Audit Logs...\n";
$auditLogs = [
    ['action' => 'login', 'mod' => 'auth', 'desc' => 'Admin user authenticated successfully from 192.168.1.100'],
    ['action' => 'create_warehouse', 'mod' => 'warehouse', 'desc' => 'Created logistics facility: Metro Manila Central Distribution Hub (WH-MNL-01)'],
    ['action' => 'create_zone', 'mod' => 'warehouse', 'desc' => 'Allocated zone Z-MNL-C3 (Hazmat Li-Ion Battery Bunker)'],
    ['action' => 'create_product', 'mod' => 'inventory', 'desc' => 'Created catalog asset: Tour-Sphere Urban E-Scooter Cruiser (SKU-EV-001)'],
    ['action' => 'create_requisition', 'mod' => 'procurement', 'desc' => 'Created purchase requisition PR-2026-001 for Fleet Operations'],
    ['action' => 'approve_requisition', 'mod' => 'procurement', 'desc' => 'Approved purchase requisition PR-2026-001 with total PHP 604,500.00'],
    ['action' => 'create_po', 'mod' => 'procurement', 'desc' => 'Generated Purchase Order PO-2026-101 for NWOW Electric Mobility Inc.'],
    ['action' => 'receive_goods', 'mod' => 'inventory', 'desc' => 'Processed goods receipt GRN-2026-0081 for PO-2026-102 into WH-CRK-02'],
    ['action' => 'create_tender', 'mod' => 'bidding', 'desc' => 'Published freight tender SPOT-2026-001 for linehaul transit'],
    ['action' => 'dispatch_shipment', 'mod' => 'shipments', 'desc' => 'Dispatched shipment SHP-2026-001 with tracking FCX-PH-9920148 via FastCargo'],
    ['action' => 'upload_document', 'mod' => 'documents', 'desc' => 'Archived Bill of Lading DOC-BOL-2026-001 for SHP-2026-001']
];

foreach ($auditLogs as $al) {
    try {
        $ins = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, module, description, ip_address, created_at)
            VALUES (?, ?, ?, ?, '127.0.0.1', CURRENT_TIMESTAMP)
        ");
        $ins->execute([$adminUserId, $al['action'], $al['mod'], $al['desc']]);
    } catch (Throwable $e) {}
}
syncSequence($pdo, 'audit_logs', $isPgsql);

echo "\n=======================================================\n";
echo "SUCCESS! Comprehensive data successfully seeded across all modules!\n";
echo "=======================================================\n";
