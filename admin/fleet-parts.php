<?php
/**
 * admin/fleet-parts.php
 * Module 1: Fleet & Maintenance Spare Parts Inventory
 *
 * Core Functions:
 *  - Vehicle-specific part numbers, OEM cross-references, and compatible transport units (tour buses, vans, speedboats).
 *  - Minimum critical spare thresholds with real-time stockout alerts.
 *  - Preventative replacement schedules based on odometer / engine-hour intervals.
 *  - Core return tracking (used alternators/starters/props returned to suppliers for credit).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tour_ops.php';

$pdo = tourOpsBoot('fleet-parts.php');
$suppliers = tsSuppliers($pdo);
$supplierMap = tsSupplierMap($suppliers);

// ============================================================================
// POST Actions
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tsRequireCsrf('fleet-parts.php');
    $subAction = (string)($_POST['sub_action'] ?? '');

    try {
        if ($subAction === 'save_part') {
            $id = tsInt($_POST['id'] ?? 0);
            $partNumber = tsStr($_POST['part_number'] ?? '', 80);
            $partName = tsStr($_POST['part_name'] ?? '', 200);
            $category = tsPick($_POST['category'] ?? '', ['brakes', 'electrical', 'engine', 'tires', 'marine', 'filters', 'suspension', 'general'], 'general');
            $oemNumber = tsStr($_POST['oem_number'] ?? '', 100);
            $crossRefs = tsStr($_POST['cross_references'] ?? '', 500);
            $compatible = tsStr($_POST['compatible_models'] ?? '', 500);
            $unitTypes = tsStr($_POST['unit_types'] ?? '', 120);
            $onHand = tsInt($_POST['on_hand'] ?? 0);
            $minCritical = tsInt($_POST['min_critical'] ?? 0);
            $reorderQty = tsInt($_POST['reorder_qty'] ?? 0);
            $unitCost = tsNum($_POST['unit_cost'] ?? 0);
            $supplierId = tsInt($_POST['supplier_id'] ?? 0) ?: null;
            $binLocation = tsStr($_POST['bin_location'] ?? '', 80);
            $isCoreTracked = !empty($_POST['is_core_tracked']) ? 1 : 0;
            $coreCharge = tsNum($_POST['core_charge'] ?? 0);

            if ($partNumber === '' || $partName === '') {
                throw new RuntimeException('Part Number and Part Name are required.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE fleet_parts SET part_number = ?, part_name = ?, category = ?, oem_number = ?, cross_references = ?, compatible_models = ?, unit_types = ?, on_hand = ?, min_critical = ?, reorder_qty = ?, unit_cost = ?, supplier_id = ?, bin_location = ?, is_core_tracked = ?, core_charge = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$partNumber, $partName, $category, $oemNumber, $crossRefs, $compatible, $unitTypes, $onHand, $minCritical, $reorderQty, $unitCost, $supplierId, $binLocation, $isCoreTracked, $coreCharge, $id]);
                logAudit(tsUserId(), 'update_fleet_part', 'fleet', "Updated fleet part: $partNumber ($partName)");
                tsFlash('success', "Part {$partNumber} updated successfully.");
            } else {
                $stmt = $pdo->prepare("INSERT INTO fleet_parts (part_number, part_name, category, oem_number, cross_references, compatible_models, unit_types, on_hand, min_critical, reorder_qty, unit_cost, supplier_id, bin_location, is_core_tracked, core_charge) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$partNumber, $partName, $category, $oemNumber, $crossRefs, $compatible, $unitTypes, $onHand, $minCritical, $reorderQty, $unitCost, $supplierId, $binLocation, $isCoreTracked, $coreCharge]);
                logAudit(tsUserId(), 'create_fleet_part', 'fleet', "Created fleet part: $partNumber ($partName)");
                tsFlash('success', "New fleet part {$partNumber} registered.");
            }
            tsRedirect('fleet-parts.php#parts');
        }

        if ($subAction === 'save_vehicle') {
            $id = tsInt($_POST['id'] ?? 0);
            $unitCode = tsStr($_POST['unit_code'] ?? '', 50);
            $unitType = tsPick($_POST['unit_type'] ?? '', ['tour_bus', 'van', 'speedboat', 'luggage_truck', 'other'], 'tour_bus');
            $make = tsStr($_POST['make'] ?? '', 100);
            $model = tsStr($_POST['model'] ?? '', 100);
            $year = tsInt($_POST['model_year'] ?? date('Y'));
            $reg = tsStr($_POST['registration'] ?? '', 60);
            $meter = tsInt($_POST['meter_reading'] ?? 0);
            $meterUnit = tsPick($_POST['meter_unit'] ?? '', ['km', 'hours', 'miles'], 'km');
            $status = tsPick($_POST['status'] ?? '', ['active', 'maintenance', 'standby', 'retired'], 'active');
            $notes = tsStr($_POST['notes'] ?? '', 500);

            if ($unitCode === '') throw new RuntimeException('Unit code is required.');

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE fleet_vehicles SET unit_code = ?, unit_type = ?, make = ?, model = ?, model_year = ?, registration = ?, meter_reading = ?, meter_unit = ?, status = ?, notes = ? WHERE id = ?");
                $stmt->execute([$unitCode, $unitType, $make, $model, $year, $reg, $meter, $meterUnit, $status, $notes, $id]);
                logAudit(tsUserId(), 'update_fleet_vehicle', 'fleet', "Updated fleet unit: $unitCode");
                tsFlash('success', "Fleet unit {$unitCode} updated.");
            } else {
                $stmt = $pdo->prepare("INSERT INTO fleet_vehicles (unit_code, unit_type, make, model, model_year, registration, meter_reading, meter_unit, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$unitCode, $unitType, $make, $model, $year, $reg, $meter, $meterUnit, $status, $notes]);
                logAudit(tsUserId(), 'create_fleet_vehicle', 'fleet', "Created fleet unit: $unitCode");
                tsFlash('success', "Transport unit {$unitCode} registered.");
            }
            tsRedirect('fleet-parts.php#vehicles');
        }

        if ($subAction === 'issue_part') {
            $partId = tsInt($_POST['part_id'] ?? 0);
            $vehicleId = tsInt($_POST['vehicle_id'] ?? 0);
            $quantity = tsInt($_POST['quantity'] ?? 1, 1);
            $meterAtIssue = tsInt($_POST['current_meter'] ?? 0);
            $notes = tsStr($_POST['notes'] ?? '', 255);

            $pdo->beginTransaction();
            $part = $pdo->query("SELECT * FROM fleet_parts WHERE id = $partId FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
            if (!$part) throw new RuntimeException('Selected spare part does not exist.');
            if ((int)$part['on_hand'] < $quantity) {
                throw new RuntimeException("Insufficient stock: on-hand is {$part['on_hand']}, requested $quantity.");
            }

            $newStock = (int)$part['on_hand'] - $quantity;
            $pdo->prepare("UPDATE fleet_parts SET on_hand = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$newStock, $partId]);

            // Record transaction
            $vStmt = $pdo->prepare("SELECT unit_code, meter_unit FROM fleet_vehicles WHERE id = ?");
            $vStmt->execute([$vehicleId]);
            $veh = $vStmt->fetch(PDO::FETCH_ASSOC);
            $vLabel = $veh ? "Unit {$veh['unit_code']}" : "Unassigned";

            $ref = 'ISSUE-' . date('ymd-His');
            $pdo->prepare("INSERT INTO fleet_part_transactions (part_id, vehicle_id, txn_type, quantity, balance_after, reference, notes, created_by) VALUES (?, ?, 'issue', ?, ?, ?, ?, ?)")
                ->execute([$partId, $vehicleId ?: null, $quantity, $newStock, $ref, "Fitted to $vLabel. $notes", tsUserId()]);

            // Update vehicle meter if provided
            if ($vehicleId > 0 && $meterAtIssue > 0) {
                $pdo->prepare("UPDATE fleet_vehicles SET meter_reading = GREATEST(meter_reading, ?) WHERE id = ?")->execute([$meterAtIssue, $vehicleId]);
            }

            // If core-tracked, automatically create a pending core return record
            if (!empty($part['is_core_tracked'])) {
                $coreRef = 'CORE-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
                $pdo->prepare("INSERT INTO fleet_core_returns (core_ref, part_id, vehicle_id, supplier_id, quantity, core_charge, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)")
                    ->execute([$coreRef, $partId, $vehicleId ?: null, $part['supplier_id'], $quantity, $part['core_charge'], "Core returned from $vLabel replacement", tsUserId()]);
            }

            $pdo->commit();
            logAudit(tsUserId(), 'issue_fleet_part', 'fleet', "Issued $quantity x {$part['part_number']} to $vLabel");
            tsFlash('success', "Issued $quantity x {$part['part_number']} to $vLabel. Core tracking updated.");
            tsRedirect('fleet-parts.php#parts');
        }

        if ($subAction === 'save_schedule') {
            $id = tsInt($_POST['id'] ?? 0);
            $vehicleId = tsInt($_POST['vehicle_id'] ?? 0);
            $partId = tsInt($_POST['part_id'] ?? 0) ?: null;
            $taskName = tsStr($_POST['task_name'] ?? '', 200);
            $partsPer = tsInt($_POST['parts_per_service'] ?? 1, 1);
            $intMeter = tsInt($_POST['interval_meter'] ?? 0);
            $intDays = tsInt($_POST['interval_days'] ?? 0);
            $lastDate = tsDate($_POST['last_service_date'] ?? '');
            $lastMeter = tsInt($_POST['last_service_meter'] ?? 0);
            $nextDate = tsDate($_POST['next_due_date'] ?? '');
            $nextMeter = tsInt($_POST['next_due_meter'] ?? 0);
            $notes = tsStr($_POST['notes'] ?? '', 500);

            if ($vehicleId < 1 || $taskName === '') {
                throw new RuntimeException('Transport unit and Task name are required.');
            }

            // Auto-calculate next due if not specified
            if (empty($nextDate) && $lastDate && $intDays > 0) {
                $nextDate = date('Y-m-d', strtotime($lastDate . " +$intDays days"));
            }
            if ($nextMeter <= 0 && $intMeter > 0) {
                $nextMeter = $lastMeter + $intMeter;
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE fleet_service_schedules SET vehicle_id = ?, part_id = ?, task_name = ?, parts_per_service = ?, interval_meter = ?, interval_days = ?, last_service_date = ?, last_service_meter = ?, next_due_date = ?, next_due_meter = ?, notes = ? WHERE id = ?");
                $stmt->execute([$vehicleId, $partId, $taskName, $partsPer, $intMeter, $intDays, $lastDate, $lastMeter, $nextDate, $nextMeter, $notes, $id]);
                tsFlash('success', 'Maintenance schedule updated.');
            } else {
                $stmt = $pdo->prepare("INSERT INTO fleet_service_schedules (vehicle_id, part_id, task_name, parts_per_service, interval_meter, interval_days, last_service_date, last_service_meter, next_due_date, next_due_meter, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$vehicleId, $partId, $taskName, $partsPer, $intMeter, $intDays, $lastDate, $lastMeter, $nextDate, $nextMeter, $notes]);
                tsFlash('success', 'Preventative maintenance schedule created.');
            }
            tsRedirect('fleet-parts.php#schedules');
        }

        if ($subAction === 'update_core_return') {
            $id = tsInt($_POST['id'] ?? 0);
            $status = tsPick($_POST['status'] ?? '', ['pending', 'shipped', 'credited', 'rejected'], 'pending');
            $creditAmount = tsNum($_POST['credit_amount'] ?? 0);
            $creditRef = tsStr($_POST['credit_reference'] ?? '', 100);
            $shippedDate = tsDate($_POST['shipped_date'] ?? '');
            $notes = tsStr($_POST['notes'] ?? '', 255);

            $stmt = $pdo->prepare("UPDATE fleet_core_returns SET status = ?, credit_amount = ?, credit_reference = ?, shipped_date = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$status, $creditAmount, $creditRef, $shippedDate, $notes, $id]);
            logAudit(tsUserId(), 'update_core_return', 'fleet', "Updated core return #$id status to $status");
            tsFlash('success', 'Core return status recorded.');
            tsRedirect('fleet-parts.php#cores');
        }

        if ($subAction === 'restock_part') {
            $partId = tsInt($_POST['part_id'] ?? 0);
            $quantity = tsInt($_POST['quantity'] ?? 1, 1);
            $unitCost = tsNum($_POST['unit_cost'] ?? 0);
            $ref = tsStr($_POST['reference'] ?? 'RECEIPT-' . date('ymd-His'), 120);

            $pdo->beginTransaction();
            $part = $pdo->query("SELECT * FROM fleet_parts WHERE id = $partId FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
            if (!$part) throw new RuntimeException('Part not found.');

            $newOnHand = (int)$part['on_hand'] + $quantity;
            $pdo->prepare("UPDATE fleet_parts SET on_hand = ?, unit_cost = CASE WHEN ? > 0 THEN ? ELSE unit_cost END, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$newOnHand, $unitCost, $unitCost, $partId]);

            $pdo->prepare("INSERT INTO fleet_part_transactions (part_id, txn_type, quantity, balance_after, reference, notes, created_by) VALUES (?, 'receive', ?, ?, ?, ?, ?)")
                ->execute([$partId, $quantity, $newOnHand, $ref, "Restocked into {$part['bin_location']}", tsUserId()]);
            $pdo->commit();

            logAudit(tsUserId(), 'restock_fleet_part', 'fleet', "Restocked $quantity x {$part['part_number']}");
            tsFlash('success', "Restocked $quantity x {$part['part_number']}. Current stock: $newOnHand.");
            tsRedirect('fleet-parts.php#parts');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        tsFlash('error', $e->getMessage());
        tsRedirect('fleet-parts.php');
    }
}

// ============================================================================
// Query Datasets
// ============================================================================
$parts = $pdo->query("SELECT p.*, s.company_name AS supplier_name FROM fleet_parts p LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE p.is_archived = 0 ORDER BY (p.on_hand <= p.min_critical) DESC, p.part_number ASC")->fetchAll(PDO::FETCH_ASSOC);

$vehicles = $pdo->query("SELECT v.*, (SELECT COUNT(*) FROM fleet_service_schedules WHERE vehicle_id = v.id) AS schedule_count FROM fleet_vehicles v WHERE v.is_archived = 0 ORDER BY v.unit_code ASC")->fetchAll(PDO::FETCH_ASSOC);
$vehicleMap = [];
foreach ($vehicles as $v) $vehicleMap[$v['id']] = "{$v['unit_code']} - {$v['make']} {$v['model']}";

$schedules = $pdo->query("
    SELECT s.*, v.unit_code, v.make, v.model, v.meter_reading, v.meter_unit, p.part_number, p.part_name, p.on_hand
    FROM fleet_service_schedules s
    JOIN fleet_vehicles v ON v.id = s.vehicle_id
    LEFT JOIN fleet_parts p ON p.id = s.part_id
    ORDER BY s.next_due_date ASC, s.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$cores = $pdo->query("
    SELECT c.*, p.part_number, p.part_name, v.unit_code, s.company_name AS supplier_name
    FROM fleet_core_returns c
    JOIN fleet_parts p ON p.id = c.part_id
    LEFT JOIN fleet_vehicles v ON v.id = c.vehicle_id
    LEFT JOIN suppliers s ON s.id = c.supplier_id
    ORDER BY (c.status = 'pending') DESC, c.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// KPIs
$criticalCount = 0;
$totalValuation = 0;
foreach ($parts as $p) {
    if ((int)$p['on_hand'] <= (int)$p['min_critical']) $criticalCount++;
    $totalValuation += ((int)$p['on_hand'] * (float)$p['unit_cost']);
}
$activeUnits = 0;
foreach ($vehicles as $v) {
    if ($v['status'] === 'active') $activeUnits++;
}
$pendingCoresCount = 0;
$pendingCoreAmount = 0.0;
foreach ($cores as $c) {
    if ($c['status'] === 'pending') {
        $pendingCoresCount++;
        $pendingCoreAmount += (float)$c['core_charge'];
    }
}

// Render Header
tsRenderHeader([
    'title' => 'Fleet & Maintenance Spare Parts Inventory',
    'subtitle' => 'Vehicle-specific part numbers, OEM cross-references, preventative replacement schedules & core return tracking.',
    'theme' => 'ts-theme-fleet',
    'icon' => 'fa-bus',
    'hero_title' => 'Fleet Maintenance & Spare Parts Management',
    'hero_text' => 'Standard retail inventory lacks transport cross-references and core charge workflows. Track critical spares for tour buses, commuter vans, and offshore speedboats with preventative service alerts.',
    'tags' => ['OEM Cross-Ref', 'Core Credit Exchange', 'Odometer & Hour Triggers', 'Critical Minimum Alerts'],
    'actions' => '
        <button class="ts-btn sm" data-ts-open="modalIssuePart"><i class="fas fa-wrench"></i> Issue to Vehicle</button>
        <button class="ts-btn green sm" data-ts-open="modalAddPart"><i class="fas fa-plus"></i> Add Spare Part</button>
        <button class="ts-btn violet sm" data-ts-open="modalAddVehicle"><i class="fas fa-truck-pickup"></i> Add Fleet Unit</button>
    ',
]);
?>

<!-- KPIs -->
<div class="ts-kpis">
    <?php echo tsKpi('Critical Spares Alert', (string)$criticalCount, 'fa-triangle-exclamation', $criticalCount > 0 ? 'red' : 'green', $criticalCount > 0 ? 'Parts below threshold' : 'All stocks healthy'); ?>
    <?php echo tsKpi('Active Transport Fleet', "{$activeUnits} / " . count($vehicles), 'fa-bus', 'green', 'Buses, vans & speedboats'); ?>
    <?php echo tsKpi('Inventory Value', tsMoney($totalValuation), 'fa-boxes-stacked', 'blue', count($parts) . ' unique part numbers'); ?>
    <?php echo tsKpi('Pending Core Credits', tsMoney($pendingCoreAmount), 'fa-rotate-left', $pendingCoresCount > 0 ? 'amber' : 'green', "$pendingCoresCount cores awaiting credit note"); ?>
</div>

<!-- Tabs -->
<div class="ts-tabs" data-ts-tab-group="fleet">
    <button class="ts-tab" data-tab="parts"><i class="fas fa-gears"></i> Spare Parts Inventory <span class="ts-count"><?php echo count($parts); ?></span></button>
    <button class="ts-tab" data-tab="vehicles"><i class="fas fa-van-shuttle"></i> Transport Fleet Units <span class="ts-count"><?php echo count($vehicles); ?></span></button>
    <button class="ts-tab" data-tab="schedules"><i class="fas fa-calendar-check"></i> Preventative Schedules <span class="ts-count"><?php echo count($schedules); ?></span></button>
    <button class="ts-tab" data-tab="cores"><i class="fas fa-arrows-rotate"></i> Core Return Tracking <span class="ts-count"><?php echo count($cores); ?></span></button>
</div>

<!-- ======================================================================
     PANEL 1: Spare Parts
     ====================================================================== -->
<div class="ts-panel" data-panel-group="fleet" data-panel="parts">
    <div class="ts-card">
        <div class="ts-card-header">
            <h3><i class="fas fa-gears"></i> Vehicle-Specific Spare Parts Catalog</h3>
            <div class="ts-card-tools">
                <div class="ts-search">
                    <i class="fas fa-magnifying-glass"></i>
                    <input type="text" placeholder="Search part #, OEM, model..." data-ts-search="partsTable">
                </div>
            </div>
        </div>
        <div class="ts-table-wrap">
            <table class="ts-table" id="partsTable">
                <thead>
                    <tr>
                        <th>Part Number &amp; Name</th>
                        <th>Category</th>
                        <th>OEM &amp; Cross-References</th>
                        <th>Compatible Transport Models</th>
                        <th>Unit Type</th>
                        <th class="ts-num">On Hand</th>
                        <th class="ts-num">Critical Min</th>
                        <th class="ts-num">Unit Cost</th>
                        <th>Core Tracked</th>
                        <th>Location</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($parts)): ?>
                    <tr><td colspan="11" class="ts-empty"><i class="fas fa-gears"></i>No spare parts registered yet.</td></tr>
                    <?php else: foreach ($parts as $p):
                        $isCritical = (int)$p['on_hand'] <= (int)$p['min_critical'];
                    ?>
                    <tr>
                        <td>
                            <strong class="ts-code"><?php echo tsh($p['part_number']); ?></strong>
                            <div style="font-size:12.5px;"><?php echo tsh($p['part_name']); ?></div>
                        </td>
                        <td><?php echo tsBadge($p['category'], ['brakes'=>'red', 'electrical'=>'amber', 'marine'=>'cyan', 'tires'=>'blue', 'filters'=>'green'], 'ghost'); ?></td>
                        <td>
                            <div><strong style="font-size:11.5px;">OEM:</strong> <?php echo tsh($p['oem_number'] ?: '—'); ?></div>
                            <?php if ($p['cross_references']): ?>
                            <div class="ts-muted"><i class="fas fa-shuffle"></i> <?php echo tsh($p['cross_references']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><div style="max-width:240px; font-size:12px;"><?php echo tsh($p['compatible_models'] ?: 'Universal'); ?></div></td>
                        <td><?php echo tsBadge($p['unit_types'], ['tour_bus'=>'blue', 'van'=>'green', 'speedboat'=>'cyan'], 'ghost'); ?></td>
                        <td class="ts-num">
                            <span class="<?php echo $isCritical ? 'ts-badge red' : 'ts-badge green'; ?>">
                                <?php if ($isCritical): ?><i class="fas fa-triangle-exclamation"></i><?php endif; ?>
                                <strong><?php echo number_format($p['on_hand']); ?></strong>
                            </span>
                        </td>
                        <td class="ts-num ts-muted"><?php echo number_format($p['min_critical']); ?></td>
                        <td class="ts-num"><?php echo tsMoney($p['unit_cost']); ?></td>
                        <td>
                            <?php if (!empty($p['is_core_tracked'])): ?>
                            <span class="ts-badge amber" title="Refundable Core: <?php echo tsMoney($p['core_charge']); ?>">
                                <i class="fas fa-rotate"></i> <?php echo tsMoney($p['core_charge']); ?>
                            </span>
                            <?php else: ?><span class="ts-muted">—</span><?php endif; ?>
                        </td>
                        <td><span class="ts-chip"><?php echo tsh($p['bin_location'] ?: 'Unassigned'); ?></span></td>
                        <td style="text-align:right;">
                            <div class="ts-row-actions">
                                <button class="ts-icon-btn blue" title="Issue to Vehicle" data-ts-open="modalIssuePart" data-ts-payload="<?php echo tsPayload(['part_id' => $p['id'], '__title' => "Issue {$p['part_number']}"]); ?>">
                                    <i class="fas fa-wrench"></i>
                                </button>
                                <button class="ts-icon-btn green" title="Restock" data-ts-open="modalRestockPart" data-ts-payload="<?php echo tsPayload(['part_id' => $p['id'], 'unit_cost' => $p['unit_cost'], '__title' => "Restock {$p['part_number']}"]); ?>">
                                    <i class="fas fa-arrow-down"></i>
                                </button>
                                <button class="ts-icon-btn" title="Edit Part" data-ts-open="modalAddPart" data-ts-payload="<?php echo tsPayload(array_merge($p, ['__title' => 'Edit Spare Part'])); ?>">
                                    <i class="fas fa-pen-to-square"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ======================================================================
     PANEL 2: Transport Vehicles
     ====================================================================== -->
<div class="ts-panel" data-panel-group="fleet" data-panel="vehicles">
    <div class="ts-card">
        <div class="ts-card-header">
            <h3><i class="fas fa-van-shuttle"></i> Active Fleet Transport Units</h3>
            <div class="ts-card-tools">
                <button class="ts-btn sm green" data-ts-open="modalAddVehicle"><i class="fas fa-plus"></i> New Unit</button>
            </div>
        </div>
        <div class="ts-table-wrap">
            <table class="ts-table">
                <thead>
                    <tr>
                        <th>Unit Code</th>
                        <th>Type</th>
                        <th>Make &amp; Model</th>
                        <th>Year</th>
                        <th>Registration / Plate</th>
                        <th>Current Meter Reading</th>
                        <th>Status</th>
                        <th>Schedules</th>
                        <th>Notes</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vehicles)): ?>
                    <tr><td colspan="10" class="ts-empty">No vehicles recorded.</td></tr>
                    <?php else: foreach ($vehicles as $v): ?>
                    <tr>
                        <td><strong class="ts-code" style="font-size:13px;"><?php echo tsh($v['unit_code']); ?></strong></td>
                        <td><?php echo tsBadge($v['unit_type'], ['tour_bus'=>'blue', 'van'=>'green', 'speedboat'=>'cyan', 'luggage_truck'=>'amber'], 'ghost'); ?></td>
                        <td><strong><?php echo tsh($v['make']); ?></strong> <?php echo tsh($v['model']); ?></td>
                        <td><?php echo tsh($v['model_year'] ?: '—'); ?></td>
                        <td><span class="ts-chip"><?php echo tsh($v['registration'] ?: 'Pending'); ?></span></td>
                        <td><strong><?php echo number_format($v['meter_reading']); ?></strong> <span class="ts-muted"><?php echo tsh($v['meter_unit']); ?></span></td>
                        <td><?php echo tsBadge($v['status'], ['active'=>'green', 'maintenance'=>'amber', 'standby'=>'blue', 'retired'=>'red'], 'ghost'); ?></td>
                        <td><span class="ts-badge ghost"><?php echo (int)$v['schedule_count']; ?> active</span></td>
                        <td class="ts-muted" style="max-width:240px;"><?php echo tsh($v['notes'] ?: '—'); ?></td>
                        <td style="text-align:right;">
                            <div class="ts-row-actions">
                                <button class="ts-icon-btn" title="Edit Vehicle" data-ts-open="modalAddVehicle" data-ts-payload="<?php echo tsPayload(array_merge($v, ['__title' => 'Edit Fleet Unit'])); ?>">
                                    <i class="fas fa-pen-to-square"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ======================================================================
     PANEL 3: Preventative Maintenance Schedules
     ====================================================================== -->
<div class="ts-panel" data-panel-group="fleet" data-panel="schedules">
    <div class="ts-card">
        <div class="ts-card-header">
            <h3><i class="fas fa-calendar-check"></i> Preventative Maintenance &amp; Replacement Schedules</h3>
            <div class="ts-card-tools">
                <button class="ts-btn sm green" data-ts-open="modalAddSchedule"><i class="fas fa-plus"></i> New Schedule</button>
            </div>
        </div>
        <div class="ts-table-wrap">
            <table class="ts-table">
                <thead>
                    <tr>
                        <th>Unit</th>
                        <th>Task Name</th>
                        <th>Required Part</th>
                        <th>Part Stock</th>
                        <th>Interval</th>
                        <th>Last Service</th>
                        <th>Next Due Date</th>
                        <th>Next Meter Due</th>
                        <th>Urgency</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($schedules)): ?>
                    <tr><td colspan="10" class="ts-empty">No preventative schedules setup.</td></tr>
                    <?php else: foreach ($schedules as $s):
                        $daysLeft = tsDaysUntil($s['next_due_date']);
                        $meterLeft = $s['next_due_meter'] !== null ? ((int)$s['next_due_meter'] - (int)$s['meter_reading']) : null;
                        $isOverdue = ($daysLeft !== null && $daysLeft < 0) || ($meterLeft !== null && $meterLeft <= 0);
                        $isSoon = ($daysLeft !== null && $daysLeft >= 0 && $daysLeft <= 15) || ($meterLeft !== null && $meterLeft > 0 && $meterLeft <= 1000);
                    ?>
                    <tr>
                        <td><strong class="ts-code"><?php echo tsh($s['unit_code']); ?></strong><div class="ts-muted"><?php echo tsh($s['make'] . ' ' . $s['model']); ?></div></td>
                        <td><strong><?php echo tsh($s['task_name']); ?></strong></td>
                        <td>
                            <?php if ($s['part_number']): ?>
                            <span class="ts-code"><?php echo tsh($s['part_number']); ?></span>
                            <div class="ts-muted"><?php echo tsh($s['part_name']); ?> (x<?php echo (int)$s['parts_per_service']; ?>)</div>
                            <?php else: ?><span class="ts-muted">Inspection only</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($s['part_number']): ?>
                            <span class="<?php echo ((int)$s['on_hand'] < (int)$s['parts_per_service']) ? 'ts-badge red' : 'ts-badge green'; ?>">
                                <?php echo (int)$s['on_hand']; ?> avail
                            </span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="ts-muted">
                            <?php if ($s['interval_meter']): ?>Every <?php echo number_format($s['interval_meter']) . ' ' . tsh($s['meter_unit']); ?><br><?php endif; ?>
                            <?php if ($s['interval_days']): ?>Every <?php echo (int)$s['interval_days']; ?> days<?php endif; ?>
                        </td>
                        <td>
                            <div><?php echo tsFmtDate($s['last_service_date']); ?></div>
                            <div class="ts-muted"><?php echo number_format($s['last_service_meter']) . ' ' . tsh($s['meter_unit']); ?></div>
                        </td>
                        <td>
                            <strong><?php echo tsFmtDate($s['next_due_date']); ?></strong>
                            <?php if ($daysLeft !== null): ?>
                            <div class="ts-muted"><?php echo $daysLeft < 0 ? abs($daysLeft) . ' days overdue' : "in $daysLeft days"; ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($s['next_due_meter']): ?>
                            <div><?php echo number_format($s['next_due_meter']) . ' ' . tsh($s['meter_unit']); ?></div>
                            <div class="ts-muted"><?php echo $meterLeft <= 0 ? abs($meterLeft) . ' ' . tsh($s['meter_unit']) . ' past' : "$meterLeft " . tsh($s['meter_unit']) . ' remaining'; ?></div>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isOverdue): ?>
                            <span class="ts-badge red ts-pulse"><i class="fas fa-circle-exclamation"></i> OVERDUE</span>
                            <?php elseif ($isSoon): ?>
                            <span class="ts-badge amber"><i class="fas fa-clock"></i> DUE SOON</span>
                            <?php else: ?>
                            <span class="ts-badge green"><i class="fas fa-check"></i> NORMAL</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <div class="ts-row-actions">
                                <button class="ts-icon-btn" title="Edit Schedule" data-ts-open="modalAddSchedule" data-ts-payload="<?php echo tsPayload(array_merge($s, ['__title' => 'Edit Service Schedule'])); ?>">
                                    <i class="fas fa-pen-to-square"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ======================================================================
     PANEL 4: Core Return Tracking
     ====================================================================== -->
<div class="ts-panel" data-panel-group="fleet" data-panel="cores">
    <div class="ts-card">
        <div class="ts-card-header">
            <h3><i class="fas fa-arrows-rotate"></i> Core Return Tracking (Used Parts Returned for Supplier Credit)</h3>
        </div>
        <div class="ts-table-wrap">
            <table class="ts-table">
                <thead>
                    <tr>
                        <th>Core Reference</th>
                        <th>Part</th>
                        <th>Source Unit</th>
                        <th>Supplier</th>
                        <th class="ts-num">Core Deposit</th>
                        <th>Status</th>
                        <th class="ts-num">Credit Realized</th>
                        <th>Credit Note Ref</th>
                        <th>Notes</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cores)): ?>
                    <tr><td colspan="10" class="ts-empty">No core return records logged.</td></tr>
                    <?php else: foreach ($cores as $c): ?>
                    <tr>
                        <td><strong class="ts-code"><?php echo tsh($c['core_ref']); ?></strong></td>
                        <td>
                            <strong style="font-size:12.5px;"><?php echo tsh($c['part_number']); ?></strong>
                            <div class="ts-muted"><?php echo tsh($c['part_name']); ?></div>
                        </td>
                        <td><?php echo tsh($c['unit_code'] ? "Unit {$c['unit_code']}" : 'Shop replacement'); ?></td>
                        <td><?php echo tsh($c['supplier_name'] ?: 'OEM Supplier'); ?></td>
                        <td class="ts-num"><strong><?php echo tsMoney($c['core_charge']); ?></strong></td>
                        <td><?php echo tsBadge($c['status'], ['pending'=>'amber', 'shipped'=>'blue', 'credited'=>'green', 'rejected'=>'red'], 'ghost'); ?></td>
                        <td class="ts-num"><?php echo tsMoney($c['credit_amount']); ?></td>
                        <td><span class="ts-chip"><?php echo tsh($c['credit_reference'] ?: 'Pending'); ?></span></td>
                        <td class="ts-muted"><?php echo tsh($c['notes'] ?: '—'); ?></td>
                        <td style="text-align:right;">
                            <button class="ts-icon-btn green" title="Update Core Status" data-ts-open="modalUpdateCore" data-ts-payload="<?php echo tsPayload($c); ?>">
                                <i class="fas fa-pen-to-square"></i>
                            </button>
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

<!-- Modal: Add / Edit Part -->
<div class="ts-modal-backdrop" id="modalAddPart">
    <div class="ts-modal wide">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-gears"></i></div>
            <h3 data-ts-title>Register Spare Part</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="fleet-parts.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="save_part">
            <input type="hidden" name="id" value="0">
            <div class="ts-modal-body">
                <div class="ts-grid">
                    <div class="ts-field">
                        <label>Part Number <span class="req">*</span></label>
                        <input type="text" name="part_number" required placeholder="e.g. BP-SC-202">
                    </div>
                    <div class="ts-field">
                        <label>Part Name <span class="req">*</span></label>
                        <input type="text" name="part_name" required placeholder="e.g. Heavy Duty Ceramic Brake Pads">
                    </div>
                    <div class="ts-field">
                        <label>Category</label>
                        <select name="category">
                            <?php echo tsOptions(['brakes'=>'Brake System', 'electrical'=>'Electrical & Starter/Alt', 'engine'=>'Engine Components', 'tires'=>'Tires & Wheels', 'marine'=>'Marine & Outboard', 'filters'=>'Filters & Fluids', 'suspension'=>'Suspension & Steering', 'general'=>'General Maintenance']); ?>
                        </select>
                    </div>
                    <div class="ts-field">
                        <label>OEM Part Number</label>
                        <input type="text" name="oem_number" placeholder="Manufacturer Original #">
                    </div>
                    <div class="ts-field span-2">
                        <label>OEM Cross-References</label>
                        <input type="text" name="cross_references" placeholder="Comma-separated cross-references (e.g. BENDIX-HD-992, FERODO-FRP412)">
                        <div class="ts-hint">Allows mechanics to cross-match interchangeable aftermarket spares.</div>
                    </div>
                    <div class="ts-field span-2">
                        <label>Compatible Vehicle Models</label>
                        <input type="text" name="compatible_models" placeholder="e.g. Scania K410, Hino RM 2P, Toyota HiAce">
                    </div>
                    <div class="ts-field">
                        <label>Target Transport Types</label>
                        <select name="unit_types">
                            <?php echo tsOptions(['tour_bus'=>'Tour Bus Coach', 'van'=>'Commuter Van', 'speedboat'=>'Speedboat / Watercraft', 'universal'=>'Universal Fleet']); ?>
                        </select>
                    </div>
                    <div class="ts-field">
                        <label>Storage Bin / Rack Location</label>
                        <input type="text" name="bin_location" placeholder="e.g. BIN-FL-A01">
                    </div>
                    <div class="ts-field">
                        <label>Current On Hand</label>
                        <input type="number" name="on_hand" value="0" min="0">
                    </div>
                    <div class="ts-field">
                        <label>Critical Minimum Threshold <span class="req">*</span></label>
                        <input type="number" name="min_critical" value="2" min="0">
                        <div class="ts-hint">Generates automatic red stockout alert when reached.</div>
                    </div>
                    <div class="ts-field">
                        <label>Recommended Reorder Quantity</label>
                        <input type="number" name="reorder_qty" value="5" min="0">
                    </div>
                    <div class="ts-field">
                        <label>Unit Replacement Cost (PHP)</label>
                        <input type="number" step="0.01" name="unit_cost" value="0.00">
                    </div>
                    <div class="ts-field">
                        <label>Preferred Supplier</label>
                        <select name="supplier_id">
                            <?php echo tsOptions($supplierMap, null, true, '— Unassigned —'); ?>
                        </select>
                    </div>
                    <div class="ts-field" style="display:flex; flex-direction:column; justify-content:center;">
                        <label class="ts-check">
                            <input type="checkbox" name="is_core_tracked" value="1">
                            <span>Track Rebuildable Core Deposit</span>
                        </label>
                    </div>
                    <div class="ts-field">
                        <label>Core Deposit Charge (PHP)</label>
                        <input type="number" step="0.01" name="core_charge" value="0.00" placeholder="Refund amount">
                    </div>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn green"><i class="fas fa-save"></i> Save Spare Part</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Issue to Vehicle -->
<div class="ts-modal-backdrop" id="modalIssuePart">
    <div class="ts-modal">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-wrench"></i></div>
            <h3 data-ts-title>Issue Spare Part to Vehicle</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="fleet-parts.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="issue_part">
            <div class="ts-modal-body">
                <div class="ts-field" style="margin-bottom:12px;">
                    <label>Spare Part <span class="req">*</span></label>
                    <select name="part_id" required>
                        <?php foreach ($parts as $p): ?>
                        <option value="<?php echo $p['id']; ?>">
                            <?php echo tsh($p['part_number'] . ' - ' . $p['part_name'] . ' (Avail: ' . $p['on_hand'] . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ts-field" style="margin-bottom:12px;">
                    <label>Target Transport Unit <span class="req">*</span></label>
                    <select name="vehicle_id" required>
                        <?php echo tsOptions($vehicleMap, null, true, '— Select Transport Unit —'); ?>
                    </select>
                </div>
                <div class="ts-grid" style="margin-bottom:12px;">
                    <div class="ts-field">
                        <label>Quantity to Issue <span class="req">*</span></label>
                        <input type="number" name="quantity" value="1" min="1" required>
                    </div>
                    <div class="ts-field">
                        <label>Vehicle Meter at Replacement</label>
                        <input type="number" name="current_meter" placeholder="Current km / hours">
                    </div>
                </div>
                <div class="ts-field">
                    <label>Work Order / Mechanic Notes</label>
                    <textarea name="notes" placeholder="e.g. Replaced worn front left pads during scheduled 50k service"></textarea>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn"><i class="fas fa-check"></i> Confirm Issuance</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Restock Part -->
<div class="ts-modal-backdrop" id="modalRestockPart">
    <div class="ts-modal">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-arrow-down"></i></div>
            <h3 data-ts-title>Restock Spare Part</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="fleet-parts.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="restock_part">
            <input type="hidden" name="part_id" value="0">
            <div class="ts-modal-body">
                <div class="ts-grid">
                    <div class="ts-field">
                        <label>Received Quantity <span class="req">*</span></label>
                        <input type="number" name="quantity" value="4" min="1" required>
                    </div>
                    <div class="ts-field">
                        <label>Unit Cost (PHP)</label>
                        <input type="number" step="0.01" name="unit_cost" value="0">
                    </div>
                    <div class="ts-field span-2">
                        <label>Invoice / Delivery Reference</label>
                        <input type="text" name="reference" placeholder="e.g. PO-2026-442">
                    </div>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn green"><i class="fas fa-plus"></i> Add to Inventory</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Add / Edit Transport Vehicle -->
<div class="ts-modal-backdrop" id="modalAddVehicle">
    <div class="ts-modal">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-van-shuttle"></i></div>
            <h3 data-ts-title>Register Fleet Unit</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="fleet-parts.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="save_vehicle">
            <input type="hidden" name="id" value="0">
            <div class="ts-modal-body">
                <div class="ts-grid">
                    <div class="ts-field">
                        <label>Unit Code <span class="req">*</span></label>
                        <input type="text" name="unit_code" required placeholder="e.g. TB-03, VN-09, SB-04">
                    </div>
                    <div class="ts-field">
                        <label>Transport Type</label>
                        <select name="unit_type">
                            <?php echo tsOptions(['tour_bus'=>'Tour Bus (Coach)', 'van'=>'Commuter Van', 'speedboat'=>'Speedboat / Marine', 'luggage_truck'=>'Luggage Truck', 'other'=>'Other Unit']); ?>
                        </select>
                    </div>
                    <div class="ts-field">
                        <label>Make</label>
                        <input type="text" name="make" placeholder="e.g. Scania, Toyota, Yamaha">
                    </div>
                    <div class="ts-field">
                        <label>Model</label>
                        <input type="text" name="model" placeholder="e.g. K410 45-seater">
                    </div>
                    <div class="ts-field">
                        <label>Model Year</label>
                        <input type="number" name="model_year" value="<?php echo date('Y'); ?>">
                    </div>
                    <div class="ts-field">
                        <label>Registration / Plate / MARINA</label>
                        <input type="text" name="registration" placeholder="e.g. NAA-8821">
                    </div>
                    <div class="ts-field">
                        <label>Current Meter Reading</label>
                        <input type="number" name="meter_reading" value="0">
                    </div>
                    <div class="ts-field">
                        <label>Meter Unit</label>
                        <select name="meter_unit">
                            <option value="km">Kilometers (km)</option>
                            <option value="hours">Engine Hours (hours)</option>
                            <option value="miles">Miles</option>
                        </select>
                    </div>
                    <div class="ts-field span-2">
                        <label>Operational Status</label>
                        <select name="status">
                            <option value="active">Active (In Service)</option>
                            <option value="maintenance">Under Maintenance</option>
                            <option value="standby">Standby Reserve</option>
                            <option value="retired">Retired</option>
                        </select>
                    </div>
                    <div class="ts-field span-2">
                        <label>Notes &amp; Equipment Specifications</label>
                        <textarea name="notes" placeholder="Restroom equipped, twin outboard engines, GPS tracker id, etc."></textarea>
                    </div>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn green"><i class="fas fa-save"></i> Save Fleet Unit</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Add / Edit Preventative Schedule -->
<div class="ts-modal-backdrop" id="modalAddSchedule">
    <div class="ts-modal wide">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-calendar-check"></i></div>
            <h3 data-ts-title>Preventative Maintenance Schedule</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="fleet-parts.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="save_schedule">
            <input type="hidden" name="id" value="0">
            <div class="ts-modal-body">
                <div class="ts-grid">
                    <div class="ts-field">
                        <label>Target Vehicle <span class="req">*</span></label>
                        <select name="vehicle_id" required>
                            <?php echo tsOptions($vehicleMap, null, true, '— Select Transport Unit —'); ?>
                        </select>
                    </div>
                    <div class="ts-field">
                        <label>Linked Replacement Part</label>
                        <select name="part_id">
                            <option value="">— None (Inspection Only) —</option>
                            <?php foreach ($parts as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo tsh("{$p['part_number']} - {$p['part_name']}"); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ts-field span-2">
                        <label>Task Description <span class="req">*</span></label>
                        <input type="text" name="task_name" required placeholder="e.g. Front & Rear Axle Brake Relining">
                    </div>
                    <div class="ts-field">
                        <label>Parts Required per Service</label>
                        <input type="number" name="parts_per_service" value="1" min="1">
                    </div>
                    <div class="ts-field">
                        <label>Interval (Meter: km or hours)</label>
                        <input type="number" name="interval_meter" placeholder="e.g. 20000">
                    </div>
                    <div class="ts-field">
                        <label>Interval (Days)</label>
                        <input type="number" name="interval_days" placeholder="e.g. 180">
                    </div>
                    <div class="ts-field">
                        <label>Last Service Date</label>
                        <input type="date" name="last_service_date">
                    </div>
                    <div class="ts-field">
                        <label>Last Service Meter</label>
                        <input type="number" name="last_service_meter" value="0">
                    </div>
                    <div class="ts-field">
                        <label>Next Due Date (Target)</label>
                        <input type="date" name="next_due_date">
                    </div>
                    <div class="ts-field">
                        <label>Next Due Meter (Target)</label>
                        <input type="number" name="next_due_meter" placeholder="Auto-calculated if blank">
                    </div>
                    <div class="ts-field span-2">
                        <label>Service Instructions / Notes</label>
                        <textarea name="notes" placeholder="Check torque on lug nuts, inspect caliper seals for hydraulic leaks."></textarea>
                    </div>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn green"><i class="fas fa-save"></i> Save Schedule</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Update Core Return -->
<div class="ts-modal-backdrop" id="modalUpdateCore">
    <div class="ts-modal">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-arrows-rotate"></i></div>
            <h3 data-ts-title>Update Core Return Status</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="fleet-parts.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="update_core_return">
            <input type="hidden" name="id" value="0">
            <div class="ts-modal-body">
                <div class="ts-grid">
                    <div class="ts-field span-2">
                        <label>Core Status</label>
                        <select name="status">
                            <option value="pending">Pending Shipment to Supplier</option>
                            <option value="shipped">Shipped / In Transit to Rebuilder</option>
                            <option value="credited">Credit Received &amp; Reconciled</option>
                            <option value="rejected">Rejected by Rebuilder</option>
                        </select>
                    </div>
                    <div class="ts-field">
                        <label>Date Shipped</label>
                        <input type="date" name="shipped_date">
                    </div>
                    <div class="ts-field">
                        <label>Credit Amount Received (PHP)</label>
                        <input type="number" step="0.01" name="credit_amount" placeholder="0.00">
                    </div>
                    <div class="ts-field span-2">
                        <label>Supplier Credit Note #</label>
                        <input type="text" name="credit_reference" placeholder="e.g. CN-BOSCH-88192">
                    </div>
                    <div class="ts-field span-2">
                        <label>Notes</label>
                        <textarea name="notes"></textarea>
                    </div>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn green"><i class="fas fa-save"></i> Update Status</button>
            </div>
        </form>
    </div>
</div>

<?php tsRenderFooter(); ?>
