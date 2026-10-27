<?php
/**
 * admin/tour-kits.php
 * Module 3: Tour Kit & Merchandise Kitting (BOM / Bundling)
 *
 * Core Functions:
 *  - Bill of Materials (BOM) management for tour bundles (e.g., Island Hopping Kit).
 *  - Light assembly & disassembly: consumes/returns component inventory with full FEFO/QC integration.
 *  - Bundled inventory reservation based on tour passenger counts.
 *  - Automatic de-kitting upon tour return: restocks reusable components (headsets, snorkeling gear),
 *    whilst accounting for expended consumables.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tour_ops.php';

$pdo = tourOpsBoot('tour-kits.php');
$products = tsProducts($pdo);
$productMap = tsProductMap($products);
$warehouses = tsWarehouses($pdo);
$warehouseMap = tsWarehouseMap($warehouses);

// ============================================================================
// POST Actions
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tsRequireCsrf('tour-kits.php');
    $subAction = (string)($_POST['sub_action'] ?? '');

    try {
        if ($subAction === 'save_kit') {
            $id = tsInt($_POST['id'] ?? 0);
            $kitCode = tsStr($_POST['kit_code'] ?? '', 60);
            $kitName = tsStr($_POST['kit_name'] ?? '', 200);
            $description = tsStr($_POST['description'] ?? '', 500);
            $tourType = tsStr($_POST['tour_type'] ?? 'General', 100);
            $kitsPerPax = tsNum($_POST['kits_per_pax'] ?? 1.0);
            $warehouseId = tsInt($_POST['warehouse_id'] ?? 0) ?: 1;

            if ($kitCode === '' || $kitName === '') {
                throw new RuntimeException('Kit code and Kit name are required.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE tour_kits SET kit_code = ?, kit_name = ?, description = ?, tour_type = ?, kits_per_pax = ?, warehouse_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$kitCode, $kitName, $description, $tourType, $kitsPerPax, $warehouseId, $id]);
                logAudit(tsUserId(), 'update_tour_kit', 'kitting', "Updated kit $kitCode ($kitName)");
                tsFlash('success', "Kit $kitCode updated.");
            } else {
                $stmt = $pdo->prepare("INSERT INTO tour_kits (kit_code, kit_name, description, tour_type, kits_per_pax, warehouse_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$kitCode, $kitName, $description, $tourType, $kitsPerPax, $warehouseId, tsUserId()]);
                logAudit(tsUserId(), 'create_tour_kit', 'kitting', "Created kit $kitCode ($kitName)");
                tsFlash('success', "New tour kit $kitCode created. You can now define its Bill of Materials.");
            }
            tsRedirect('tour-kits.php#kits');
        }

        if ($subAction === 'save_bom_component') {
            $kitId = tsInt($_POST['kit_id'] ?? 0);
            $productId = tsInt($_POST['product_id'] ?? 0);
            $quantity = tsInt($_POST['quantity'] ?? 1, 1);
            $isReusable = !empty($_POST['is_reusable']) ? 1 : 0;

            if ($kitId < 1 || $productId < 1) throw new RuntimeException('Kit and Product selection required.');

            // Upsert component
            $del = $pdo->prepare("DELETE FROM tour_kit_components WHERE kit_id = ? AND product_id = ?");
            $del->execute([$kitId, $productId]);

            $stmt = $pdo->prepare("INSERT INTO tour_kit_components (kit_id, product_id, quantity, is_reusable) VALUES (?, ?, ?, ?)");
            $stmt->execute([$kitId, $productId, $quantity, $isReusable]);
            tsFlash('success', 'BOM component added to kit recipe.');
            tsRedirect('tour-kits.php#kits');
        }

        if ($subAction === 'remove_bom_component') {
            $compKitId = tsInt($_POST['kit_id'] ?? 0);
            $compProdId = tsInt($_POST['product_id'] ?? 0);
            $pdo->prepare("DELETE FROM tour_kit_components WHERE kit_id = ? AND product_id = ?")->execute([$compKitId, $compProdId]);
            tsFlash('success', 'Component removed from BOM.');
            tsRedirect('tour-kits.php#kits');
        }

        if ($subAction === 'assemble_kits') {
            $kitId = tsInt($_POST['kit_id'] ?? 0);
            $quantity = tsInt($_POST['quantity'] ?? 1, 1);
            $notes = tsStr($_POST['notes'] ?? '', 255);

            $pdo->beginTransaction();
            $kit = $pdo->query("SELECT * FROM tour_kits WHERE id = $kitId FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
            if (!$kit) throw new RuntimeException('Kit not found.');

            $components = $pdo->query("SELECT * FROM tour_kit_components WHERE kit_id = $kitId")->fetchAll(PDO::FETCH_ASSOC);
            if (empty($components)) throw new RuntimeException('Cannot assemble a kit without Bill of Materials components.');

            $whId = (int)$kit['warehouse_id'];
            $ref = 'ASM-' . date('ymd-His');

            // Deduct each component from warehouse inventory via tsAdjustStock
            foreach ($components as $c) {
                $needed = (int)$c['quantity'] * $quantity;
                tsAdjustStock($pdo, (int)$c['product_id'], $whId, -$needed, 'issuance', "Kitting assembly for {$quantity}x {$kit['kit_code']}", $ref);
            }

            // Increment assembled kit count
            $pdo->prepare("UPDATE tour_kits SET assembled_qty = assembled_qty + ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$quantity, $kitId]);

            $pdo->prepare("INSERT INTO tour_kit_transactions (kit_id, txn_type, quantity, reference, notes, created_by) VALUES (?, 'assemble', ?, ?, ?, ?)")
                ->execute([$kitId, $quantity, $ref, $notes, tsUserId()]);

            $pdo->commit();
            logAudit(tsUserId(), 'assemble_tour_kit', 'kitting', "Assembled $quantity x {$kit['kit_code']}");
            tsFlash('success', "Successfully assembled $quantity x {$kit['kit_code']}. Raw components consumed from warehouse.");
            tsRedirect('tour-kits.php#kits');
        }

        if ($subAction === 'disassemble_kits') {
            $kitId = tsInt($_POST['kit_id'] ?? 0);
            $quantity = tsInt($_POST['quantity'] ?? 1, 1);
            $notes = tsStr($_POST['notes'] ?? '', 255);

            $pdo->beginTransaction();
            $kit = $pdo->query("SELECT * FROM tour_kits WHERE id = $kitId FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
            if (!$kit) throw new RuntimeException('Kit not found.');

            if ((int)$kit['assembled_qty'] < $quantity) {
                throw new RuntimeException("Cannot disassemble $quantity kits; only {$kit['assembled_qty']} assembled on hand.");
            }

            $components = $pdo->query("SELECT * FROM tour_kit_components WHERE kit_id = $kitId")->fetchAll(PDO::FETCH_ASSOC);
            $whId = (int)$kit['warehouse_id'];
            $ref = 'DISASM-' . date('ymd-His');

            // Return each component to warehouse stock
            foreach ($components as $c) {
                $returnQty = (int)$c['quantity'] * $quantity;
                tsAdjustStock($pdo, (int)$c['product_id'], $whId, $returnQty, 'receiving', "Kit disassembly of {$quantity}x {$kit['kit_code']}", $ref);
            }

            $pdo->prepare("UPDATE tour_kits SET assembled_qty = assembled_qty - ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$quantity, $kitId]);

            $pdo->prepare("INSERT INTO tour_kit_transactions (kit_id, txn_type, quantity, reference, notes, created_by) VALUES (?, 'disassemble', ?, ?, ?, ?)")
                ->execute([$kitId, $quantity, $ref, $notes, tsUserId()]);

            $pdo->commit();
            logAudit(tsUserId(), 'disassemble_tour_kit', 'kitting', "Disassembled $quantity x {$kit['kit_code']}");
            tsFlash('success', "Disassembled $quantity x {$kit['kit_code']}. Components restocked into warehouse.");
            tsRedirect('tour-kits.php#kits');
        }

        if ($subAction === 'create_reservation') {
            $kitId = tsInt($_POST['kit_id'] ?? 0);
            $tourRef = tsStr($_POST['tour_reference'] ?? '', 120);
            $depDate = tsDate($_POST['departure_date'] ?? '');
            $paxCount = tsInt($_POST['pax_count'] ?? 1, 1);
            $guideName = tsStr($_POST['guide_name'] ?? '', 150);
            $notes = tsStr($_POST['notes'] ?? '', 255);

            $kit = $pdo->query("SELECT * FROM tour_kits WHERE id = $kitId")->fetch(PDO::FETCH_ASSOC);
            if (!$kit) throw new RuntimeException('Kit not found.');

            $kitsNeeded = (int)ceil($paxCount * (float)$kit['kits_per_pax']);
            $resCode = 'RES-KIT-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));

            $stmt = $pdo->prepare("INSERT INTO tour_kit_reservations (reservation_code, kit_id, tour_reference, departure_date, pax_count, kits_reserved, guide_name, notes, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'reserved', ?)");
            $stmt->execute([$resCode, $kitId, $tourRef, $depDate, $paxCount, $kitsNeeded, $guideName, $notes, tsUserId()]);

            // Increment reserved_qty
            $pdo->prepare("UPDATE tour_kits SET reserved_qty = reserved_qty + ? WHERE id = ?")->execute([$kitsNeeded, $kitId]);

            logAudit(tsUserId(), 'reserve_tour_kit', 'kitting', "Reserved $kitsNeeded x {$kit['kit_code']} for $tourRef ($paxCount pax)");
            tsFlash('success', "Reserved $kitsNeeded kits for $tourRef (Reservation: $resCode).");
            tsRedirect('tour-kits.php#reservations');
        }

        if ($subAction === 'dispatch_reservation') {
            $resId = tsInt($_POST['reservation_id'] ?? 0);
            $pdo->beginTransaction();
            $res = $pdo->query("SELECT r.*, k.assembled_qty, k.kit_code FROM tour_kit_reservations r JOIN tour_kits k ON k.id = r.kit_id WHERE r.id = $resId FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
            if (!$res || $res['status'] !== 'reserved') throw new RuntimeException('Reservation not eligible for dispatch.');

            $qty = (int)$res['kits_reserved'];
            if ((int)$res['assembled_qty'] < $qty) {
                throw new RuntimeException("Insufficient assembled kits: need $qty, have {$res['assembled_qty']}. Assemble more kits first.");
            }

            $pdo->prepare("UPDATE tour_kits SET assembled_qty = assembled_qty - ?, reserved_qty = GREATEST(0, reserved_qty - ?), issued_qty = issued_qty + ? WHERE id = ?")
                ->execute([$qty, $qty, $qty, $res['kit_id']]);

            $pdo->prepare("UPDATE tour_kit_reservations SET status = 'issued', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$resId]);

            $pdo->commit();
            logAudit(tsUserId(), 'dispatch_tour_kit', 'kitting', "Dispatched {$res['reservation_code']} to {$res['guide_name']}");
            tsFlash('success', "Kits dispatched to {$res['guide_name']}. Ready for field operation.");
            tsRedirect('tour-kits.php#reservations');
        }

        if ($subAction === 'dekitting_return') {
            $resId = tsInt($_POST['reservation_id'] ?? 0);
            $kitsReturned = tsInt($_POST['kits_returned'] ?? 0, 0);
            $returnCondition = tsPick($_POST['condition'] ?? 'good', ['good', 'partial_damage'], 'good');
            $returnNotes = tsStr($_POST['notes'] ?? '', 255);

            $pdo->beginTransaction();
            $res = $pdo->query("SELECT r.*, k.warehouse_id, k.kit_code FROM tour_kit_reservations r JOIN tour_kits k ON k.id = r.kit_id WHERE r.id = $resId FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
            if (!$res || $res['status'] !== 'issued') throw new RuntimeException('Only issued reservations can be de-kitted upon return.');

            $whId = (int)$res['warehouse_id'];
            $components = $pdo->query("SELECT * FROM tour_kit_components WHERE kit_id = {$res['kit_id']}")->fetchAll(PDO::FETCH_ASSOC);
            $ref = 'DEKIT-' . date('ymd-His');

            // Automatic de-kitting: Reusable components get restocked into warehouse inventory!
            $reusableRestocked = 0;
            foreach ($components as $c) {
                if (!empty($c['is_reusable'])) {
                    $qtyToRestock = (int)$c['quantity'] * $kitsReturned;
                    tsAdjustStock($pdo, (int)$c['product_id'], $whId, $qtyToRestock, 'return', "De-kitting return for {$res['reservation_code']} ($kitsReturned kits)", $ref);
                    $reusableRestocked += $qtyToRestock;
                }
            }

            // Update kit balances
            $issuedTotal = (int)$res['kits_reserved'];
            $pdo->prepare("UPDATE tour_kits SET issued_qty = GREATEST(0, issued_qty - ?) WHERE id = ?")
                ->execute([$issuedTotal, $res['kit_id']]);

            $pdo->prepare("UPDATE tour_kit_reservations SET status = 'returned', kits_returned = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$kitsReturned, "Returned in $returnCondition condition. $returnNotes", $resId]);

            $pdo->commit();
            logAudit(tsUserId(), 'dekit_tour_kit', 'kitting', "De-kitted reservation {$res['reservation_code']}, returned $kitsReturned kits and restocked $reusableRestocked reusable units");
            tsFlash('success', "De-kitting complete. Reusable items automatically restocked to warehouse inventory. Consumables written off.");
            tsRedirect('tour-kits.php#reservations');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        tsFlash('error', $e->getMessage());
        tsRedirect('tour-kits.php');
    }
}

// ============================================================================
// Query Datasets
// ============================================================================
$kits = $pdo->query("
    SELECT k.*, w.name AS warehouse_name,
           (SELECT COUNT(*) FROM tour_kit_components WHERE kit_id = k.id) AS component_count
    FROM tour_kits k
    LEFT JOIN warehouses w ON w.id = k.warehouse_id
    WHERE k.is_archived = 0
    ORDER BY k.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Map BOM components by kit_id
$allComponents = $pdo->query("
    SELECT c.*, p.product_name, p.sku, p.current_stock, p.unit_price, p.unit_measure
    FROM tour_kit_components c
    JOIN products p ON p.id = c.product_id
    ORDER BY c.kit_id ASC, c.is_reusable DESC, p.product_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$kitBOM = [];
foreach ($allComponents as $c) {
    $kitBOM[$c['kit_id']][] = $c;
}

$reservations = $pdo->query("
    SELECT r.*, k.kit_code, k.kit_name, k.assembled_qty
    FROM tour_kit_reservations r
    JOIN tour_kits k ON k.id = r.kit_id
    ORDER BY (r.status = 'reserved') DESC, (r.status = 'issued') DESC, r.departure_date ASC
")->fetchAll(PDO::FETCH_ASSOC);

// KPIs
$totalKitsAssembled = 0;
$totalKitsReserved = 0;
$totalKitsIssued = 0;
foreach ($kits as $k) {
    $totalKitsAssembled += (int)$k['assembled_qty'];
    $totalKitsReserved += (int)$k['reserved_qty'];
    $totalKitsIssued += (int)$k['issued_qty'];
}
$activeResCount = 0;
foreach ($reservations as $r) {
    if (in_array($r['status'], ['reserved', 'issued'], true)) $activeResCount++;
}

// Render Header
tsRenderHeader([
    'title' => 'Tour Kit & Merchandise Kitting (BOM / Bundling)',
    'subtitle' => 'Bill of Materials (BOM), light assembly/disassembly, passenger bundle reservation & automatic de-kitting of reusable items.',
    'theme' => 'ts-theme-kits',
    'icon' => 'fa-toolbox',
    'hero_title' => 'Tour Kit Assembly & De-kitting Operations',
    'hero_text' => 'Tour operations bundle physical consumables and reusable assets into ready-to-issue expedition packs. Manage BOM components, auto-deduct raw inventory upon assembly, and return reusable items (headsets, snorkeling gear) seamlessly upon tour return.',
    'tags' => ['Bill of Materials', 'Light Assembly & FEFO Deduction', 'Pax-Based Reservation', 'Auto De-kitting Reusables'],
    'actions' => '
        <button class="ts-btn sm" data-ts-open="modalNewReservation"><i class="fas fa-calendar-plus"></i> Reserve Kit Bundle</button>
        <button class="ts-btn green sm" data-ts-open="modalCreateKit"><i class="fas fa-plus"></i> Create New Kit BOM</button>
    ',
]);
?>

<!-- KPIs -->
<div class="ts-kpis">
    <?php echo tsKpi('Active Kit Templates', (string)count($kits), 'fa-toolbox', 'green', 'Defined Bill of Materials'); ?>
    <?php echo tsKpi('Assembled In Warehouse', (string)$totalKitsAssembled, 'fa-boxes-packing', 'blue', 'Pre-bundled and ready to issue'); ?>
    <?php echo tsKpi('Reserved for Departures', (string)$totalKitsReserved, 'fa-bookmark', 'amber', "$activeResCount active tour reservations"); ?>
    <?php echo tsKpi('Issued in the Field', (string)$totalKitsIssued, 'fa-person-hiking', 'violet', 'Currently deployed with guides'); ?>
</div>

<!-- Tabs -->
<div class="ts-tabs" data-ts-tab-group="kits">
    <button class="ts-tab" data-tab="kits"><i class="fas fa-box-archive"></i> Tour Kits &amp; Bill of Materials (BOM) <span class="ts-count"><?php echo count($kits); ?></span></button>
    <button class="ts-tab" data-tab="reservations"><i class="fas fa-clipboard-check"></i> Passenger Reservations &amp; Field Dispatch <span class="ts-count"><?php echo count($reservations); ?></span></button>
</div>

<!-- ======================================================================
     PANEL 1: Kits Catalog & BOM
     ====================================================================== -->
<div class="ts-panel" data-panel-group="kits" data-panel="kits">
    <?php if (empty($kits)): ?>
    <div class="ts-card"><div class="ts-empty"><i class="fas fa-toolbox"></i>No tour kit templates created yet. Click "Create New Kit BOM" to start.</div></div>
    <?php else: foreach ($kits as $k):
        $bom = $kitBOM[$k['id']] ?? [];
        $costPerKit = 0.0;
        foreach ($bom as $c) $costPerKit += ((int)$c['quantity'] * (float)$c['unit_price']);
    ?>
    <div class="ts-card" style="margin-bottom:20px;">
        <div class="ts-card-header">
            <div>
                <strong class="ts-code" style="font-size:14px;"><?php echo tsh($k['kit_code']); ?></strong>
                <h3 style="display:inline; margin-left:8px;"><?php echo tsh($k['kit_name']); ?></h3>
                <span class="ts-badge blue" style="margin-left:8px;"><?php echo tsh($k['tour_type']); ?></span>
            </div>
            <div class="ts-card-tools">
                <span class="ts-badge green"><i class="fas fa-boxes-stacked"></i> <?php echo number_format($k['assembled_qty']); ?> Assembled</span>
                <span class="ts-badge amber"><i class="fas fa-clock"></i> <?php echo number_format($k['reserved_qty']); ?> Reserved</span>
                <span class="ts-badge violet"><i class="fas fa-person-walking-luggage"></i> <?php echo number_format($k['issued_qty']); ?> In Field</span>
                <button class="ts-btn sm green" data-ts-open="modalAssemble" data-ts-payload="<?php echo tsPayload(['kit_id' => $k['id'], '__title' => "Assemble {$k['kit_code']}"]); ?>">
                    <i class="fas fa-hammer"></i> Assemble
                </button>
                <button class="ts-btn sm amber" data-ts-open="modalDisassemble" data-ts-payload="<?php echo tsPayload(['kit_id' => $k['id'], '__title' => "Disassemble {$k['kit_code']}"]); ?>">
                    <i class="fas fa-box-open"></i> Disassemble
                </button>
                <button class="ts-btn sm ghost" data-ts-open="modalAddBom" data-ts-payload="<?php echo tsPayload(['kit_id' => $k['id'], '__title' => "Add BOM Component to {$k['kit_code']}"]); ?>">
                    <i class="fas fa-plus"></i> Add Item to BOM
                </button>
            </div>
        </div>

        <div style="padding:12px 20px; font-size:13px; color:var(--secondary-text); border-bottom:1px solid var(--border);">
            <?php echo tsh($k['description'] ?: 'No description provided.'); ?>
            <span style="margin-left:14px;"><strong>Warehouse:</strong> <?php echo tsh($k['warehouse_name'] ?: 'Primary WH'); ?></span>
            <span style="margin-left:14px;"><strong>Formula Cost:</strong> <?php echo tsMoney($costPerKit); ?> / bundle</span>
        </div>

        <!-- BOM Components Table -->
        <div class="ts-table-wrap">
            <table class="ts-table">
                <thead>
                    <tr>
                        <th>BOM Component Product</th>
                        <th>Classification</th>
                        <th class="ts-num">Qty per Bundle</th>
                        <th class="ts-num">Current Raw Stock</th>
                        <th class="ts-num">Unit Value</th>
                        <th class="ts-num">Total Component Cost</th>
                        <th style="text-align:right;">Remove</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bom)): ?>
                    <tr><td colspan="7" class="ts-empty">No components added to this kit yet. Click "Add Item to BOM" to configure items.</td></tr>
                    <?php else: foreach ($bom as $comp):
                        $lineCost = (int)$comp['quantity'] * (float)$comp['unit_price'];
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo tsh($comp['product_name']); ?></strong>
                            <div class="ts-muted">SKU: <?php echo tsh($comp['sku']); ?></div>
                        </td>
                        <td>
                            <?php if (!empty($comp['is_reusable'])): ?>
                            <span class="ts-badge green"><i class="fas fa-arrows-rotate"></i> REUSABLE (Restocked on return)</span>
                            <?php else: ?>
                            <span class="ts-badge amber"><i class="fas fa-fire"></i> CONSUMABLE (Expended in tour)</span>
                            <?php endif; ?>
                        </td>
                        <td class="ts-num"><strong><?php echo (int)$comp['quantity']; ?></strong> <?php echo tsh($comp['unit_measure'] ?: 'units'); ?></td>
                        <td class="ts-num"><?php echo number_format($comp['current_stock']); ?></td>
                        <td class="ts-num"><?php echo tsMoney($comp['unit_price']); ?></td>
                        <td class="ts-num"><strong><?php echo tsMoney($lineCost); ?></strong></td>
                        <td style="text-align:right;">
                            <form method="POST" action="tour-kits.php" style="display:inline;" onsubmit="return confirm('Remove this component from the kit BOM?');">
                                <?php echo tsCsrfField(); ?>
                                <input type="hidden" name="sub_action" value="remove_bom_component">
                                <input type="hidden" name="kit_id" value="<?php echo $k['id']; ?>">
                                <input type="hidden" name="product_id" value="<?php echo $comp['product_id']; ?>">
                                <button type="submit" class="ts-icon-btn red" title="Delete BOM Component"><i class="fas fa-trash-can"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>

<!-- ======================================================================
     PANEL 2: Passenger Reservations & Dispatch
     ====================================================================== -->
<div class="ts-panel" data-panel-group="kits" data-panel="reservations">
    <div class="ts-card">
        <div class="ts-card-header">
            <h3><i class="fas fa-clipboard-check"></i> Tour Passenger Reservations &amp; Field Dispatch</h3>
            <div class="ts-card-tools">
                <button class="ts-btn sm green" data-ts-open="modalNewReservation"><i class="fas fa-plus"></i> New Reservation</button>
            </div>
        </div>
        <div class="ts-table-wrap">
            <table class="ts-table">
                <thead>
                    <tr>
                        <th>Reservation Code</th>
                        <th>Kit Bundle</th>
                        <th>Tour Reference</th>
                        <th>Departure Date</th>
                        <th class="ts-num">Pax Count</th>
                        <th class="ts-num">Kits Allocated</th>
                        <th>Assigned Guide</th>
                        <th>Status</th>
                        <th>Returned Kits</th>
                        <th style="text-align:right;">Lifecycle Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reservations)): ?>
                    <tr><td colspan="10" class="ts-empty">No tour kit reservations recorded.</td></tr>
                    <?php else: foreach ($reservations as $r): ?>
                    <tr>
                        <td><strong class="ts-code"><?php echo tsh($r['reservation_code']); ?></strong></td>
                        <td>
                            <strong><?php echo tsh($r['kit_code']); ?></strong>
                            <div class="ts-muted"><?php echo tsh($r['kit_name']); ?></div>
                        </td>
                        <td><span class="ts-chip"><?php echo tsh($r['tour_reference'] ?: 'Direct'); ?></span></td>
                        <td><strong><?php echo tsFmtDate($r['departure_date']); ?></strong></td>
                        <td class="ts-num"><?php echo (int)$r['pax_count']; ?></td>
                        <td class="ts-num"><strong style="color:var(--primary);"><?php echo (int)$r['kits_reserved']; ?> kits</strong></td>
                        <td><i class="fas fa-user-tie" style="opacity:0.6; margin-right:4px;"></i><?php echo tsh($r['guide_name'] ?: 'Unassigned'); ?></td>
                        <td><?php echo tsBadge($r['status'], ['reserved'=>'amber', 'issued'=>'blue', 'returned'=>'green', 'cancelled'=>'red'], 'ghost'); ?></td>
                        <td>
                            <?php if ($r['status'] === 'returned'): ?>
                            <span class="ts-badge green"><i class="fas fa-check"></i> <?php echo (int)$r['kits_returned']; ?> returned</span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <?php if ($r['status'] === 'reserved'): ?>
                            <form method="POST" action="tour-kits.php" style="display:inline;" onsubmit="return confirm('Dispatch these pre-assembled kits to the tour guide?');">
                                <?php echo tsCsrfField(); ?>
                                <input type="hidden" name="sub_action" value="dispatch_reservation">
                                <input type="hidden" name="reservation_id" value="<?php echo $r['id']; ?>">
                                <button type="submit" class="ts-btn sm"><i class="fas fa-truck-fast"></i> Dispatch</button>
                            </form>
                            <?php elseif ($r['status'] === 'issued'): ?>
                            <button class="ts-btn sm green" data-ts-open="modalDekit" data-ts-payload="<?php echo tsPayload(['reservation_id' => $r['id'], 'kits_returned' => $r['kits_reserved'], '__title' => "De-kitting Return for {$r['reservation_code']}"]); ?>">
                                <i class="fas fa-boxes-packing"></i> De-kit &amp; Return
                            </button>
                            <?php else: ?>
                            <span class="ts-muted"><i class="fas fa-circle-check"></i> Completed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ======================================================================
     MODALS
     ====================================================================== -->

<!-- Modal: Create / Edit Kit -->
<div class="ts-modal-backdrop" id="modalCreateKit">
    <div class="ts-modal">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-toolbox"></i></div>
            <h3 data-ts-title>Create Tour Kit Template</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="tour-kits.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="save_kit">
            <input type="hidden" name="id" value="0">
            <div class="ts-modal-body">
                <div class="ts-grid">
                    <div class="ts-field">
                        <label>Kit Code <span class="req">*</span></label>
                        <input type="text" name="kit_code" required placeholder="e.g. KIT-ISLAND-01">
                    </div>
                    <div class="ts-field">
                        <label>Kit Bundle Name <span class="req">*</span></label>
                        <input type="text" name="kit_name" required placeholder="e.g. Island Hopping Explorer Kit">
                    </div>
                    <div class="ts-field">
                        <label>Associated Tour Circuit</label>
                        <input type="text" name="tour_type" value="Island Hopping" placeholder="e.g. Island Hopping, City Heritage">
                    </div>
                    <div class="ts-field">
                        <label>Kits per Passenger Ratio</label>
                        <input type="number" step="0.1" name="kits_per_pax" value="1.0" required>
                    </div>
                    <div class="ts-field span-2">
                        <label>Home Warehouse for Assembly</label>
                        <select name="warehouse_id">
                            <?php echo tsOptions($warehouseMap); ?>
                        </select>
                    </div>
                    <div class="ts-field span-2">
                        <label>Kit Description &amp; Traveler Guidelines</label>
                        <textarea name="description" placeholder="Specify contents and return instructions for tour guides"></textarea>
                    </div>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn green"><i class="fas fa-save"></i> Save Kit Template</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Add Item to BOM -->
<div class="ts-modal-backdrop" id="modalAddBom">
    <div class="ts-modal">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-plus"></i></div>
            <h3 data-ts-title>Add Component to Bill of Materials</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="tour-kits.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="save_bom_component">
            <input type="hidden" name="kit_id" value="0">
            <div class="ts-modal-body">
                <div class="ts-field" style="margin-bottom:12px;">
                    <label>Catalog Product <span class="req">*</span></label>
                    <select name="product_id" required>
                        <?php echo tsOptions($productMap, null, true, '— Select Product —'); ?>
                    </select>
                </div>
                <div class="ts-grid">
                    <div class="ts-field">
                        <label>Quantity per Kit <span class="req">*</span></label>
                        <input type="number" name="quantity" value="1" min="1" required>
                    </div>
                    <div class="ts-field" style="display:flex; flex-direction:column; justify-content:center;">
                        <label class="ts-check">
                            <input type="checkbox" name="is_reusable" value="1">
                            <span><strong>Reusable Item</strong> (e.g. Snorkel gear, audio headset)</span>
                        </label>
                        <div class="ts-hint">Reusable items are automatically de-kitted and restocked into warehouse inventory upon tour return.</div>
                    </div>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn green"><i class="fas fa-check"></i> Add to BOM</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Assemble Kits -->
<div class="ts-modal-backdrop" id="modalAssemble">
    <div class="ts-modal">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-hammer"></i></div>
            <h3 data-ts-title>Assemble Tour Kits</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="tour-kits.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="assemble_kits">
            <input type="hidden" name="kit_id" value="0">
            <div class="ts-modal-body">
                <div class="ts-field" style="margin-bottom:12px;">
                    <label>Bundles to Assemble <span class="req">*</span></label>
                    <input type="number" name="quantity" value="10" min="1" required>
                    <div class="ts-hint">Required BOM components will be automatically validated &amp; deducted from warehouse inventory.</div>
                </div>
                <div class="ts-field">
                    <label>Assembly Batch Notes</label>
                    <textarea name="notes" placeholder="Batch prepped for weekend departures"></textarea>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn green"><i class="fas fa-boxes-packing"></i> Assemble Bundles</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Disassemble Kits -->
<div class="ts-modal-backdrop" id="modalDisassemble">
    <div class="ts-modal">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-box-open"></i></div>
            <h3 data-ts-title>Disassemble Kits</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="tour-kits.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="disassemble_kits">
            <input type="hidden" name="kit_id" value="0">
            <div class="ts-modal-body">
                <div class="ts-field" style="margin-bottom:12px;">
                    <label>Bundles to Disassemble <span class="req">*</span></label>
                    <input type="number" name="quantity" value="5" min="1" required>
                    <div class="ts-hint">Raw components will be returned and restocked into warehouse inventory.</div>
                </div>
                <div class="ts-field">
                    <label>Reason for Disassembly</label>
                    <textarea name="notes" placeholder="e.g. Excess stock returned to bulk shelves"></textarea>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn amber"><i class="fas fa-box-open"></i> Confirm Disassembly</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: New Reservation -->
<div class="ts-modal-backdrop" id="modalNewReservation">
    <div class="ts-modal">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-bookmark"></i></div>
            <h3 data-ts-title>Reserve Kit Bundles for Departure</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="tour-kits.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="create_reservation">
            <div class="ts-modal-body">
                <div class="ts-field" style="margin-bottom:12px;">
                    <label>Kit Template <span class="req">*</span></label>
                    <select name="kit_id" required>
                        <?php foreach ($kits as $k): ?>
                        <option value="<?php echo $k['id']; ?>"><?php echo tsh("{$k['kit_code']} - {$k['kit_name']} (Assembled: {$k['assembled_qty']})"); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ts-grid">
                    <div class="ts-field">
                        <label>Tour Code / Booking Ref</label>
                        <input type="text" name="tour_reference" placeholder="e.g. TOUR-BOH-26">
                    </div>
                    <div class="ts-field">
                        <label>Departure Date <span class="req">*</span></label>
                        <input type="date" name="departure_date" required value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                    </div>
                    <div class="ts-field">
                        <label>Passenger Headcount (Pax) <span class="req">*</span></label>
                        <input type="number" name="pax_count" value="25" min="1" required>
                    </div>
                    <div class="ts-field">
                        <label>Assigned Tour Guide / Driver</label>
                        <input type="text" name="guide_name" placeholder="e.g. Guide Marlon Reyes">
                    </div>
                    <div class="ts-field span-2">
                        <label>Special Notes</label>
                        <textarea name="notes" placeholder="Deliver to boat slip by 07:00 AM"></textarea>
                    </div>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn green"><i class="fas fa-bookmark"></i> Confirm Reservation</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: De-kitting Return -->
<div class="ts-modal-backdrop" id="modalDekit">
    <div class="ts-modal">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-boxes-packing"></i></div>
            <h3 data-ts-title>De-kitting &amp; Reusable Gear Return</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="tour-kits.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="dekitting_return">
            <input type="hidden" name="reservation_id" value="0">
            <div class="ts-modal-body">
                <p style="font-size:13px; color:var(--secondary-text); margin-bottom:14px;">
                    When returned, reusable items (audio guide headsets, rented snorkeling masks) are automatically checked back into warehouse stock. Consumables (water bottles, towels) are accounted for as consumed during tour.
                </p>
                <div class="ts-grid">
                    <div class="ts-field">
                        <label>Kits Successfully Returned <span class="req">*</span></label>
                        <input type="number" name="kits_returned" value="25" min="0" required>
                    </div>
                    <div class="ts-field">
                        <label>Overall Equipment Condition</label>
                        <select name="condition">
                            <option value="good">All Good / Sanitized</option>
                            <option value="partial_damage">Minor Damage / Inspection Required</option>
                        </select>
                    </div>
                    <div class="ts-field span-2">
                        <label>Return Notes (e.g. Lost items, damages)</label>
                        <textarea name="notes" placeholder="All 25 headsets accounted for and tested."></textarea>
                    </div>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn green"><i class="fas fa-check"></i> Process De-kit &amp; Restock</button>
            </div>
        </form>
    </div>
</div>

<?php tsRenderFooter(); ?>
