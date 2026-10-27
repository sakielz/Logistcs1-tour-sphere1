<?php
/**
 * admin/asset-tracking.php
 * Module 4: Asset Lifecycle, Tracking & Maintenance (ALTM)
 *
 * Core Functions:
 *  - Serialized asset tracking via Barcode, RFID & Serial Number for high-value non-consumable gear:
 *    satellite radios, first-aid oxygen sets, audio tour headsets, luggage trailers.
 *  - Real-time Check-out / Check-in custody logging assigned to tour guides or drivers.
 *  - Straight-line depreciation engine linked to General Ledger (GL Asset, Accum Depr, Expense).
 *  - Scheduled recalibration and safety maintenance audits.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tour_ops.php';

$pdo = tourOpsBoot('asset-tracking.php');

// ============================================================================
// POST Actions
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tsRequireCsrf('asset-tracking.php');
    $subAction = (string)($_POST['sub_action'] ?? '');

    try {
        if ($subAction === 'save_asset') {
            $id = tsInt($_POST['id'] ?? 0);
            $assetTag = tsStr($_POST['asset_tag'] ?? '', 60);
            $assetName = tsStr($_POST['asset_name'] ?? '', 200);
            $category = tsPick($_POST['category'] ?? '', ['electronics', 'safety', 'trailers', 'audio_guides', 'marine_gear', 'other'], 'other');
            $serialNumber = tsStr($_POST['serial_number'] ?? '', 120);
            $barcode = tsStr($_POST['barcode'] ?? '', 120);
            $rfidTag = tsStr($_POST['rfid_tag'] ?? '', 120);
            $homeLocation = tsStr($_POST['home_location'] ?? '', 150);
            $acqDate = tsDate($_POST['acquisition_date'] ?? date('Y-m-d'));
            $acqCost = tsNum($_POST['acquisition_cost'] ?? 0);
            $salvage = tsNum($_POST['salvage_value'] ?? 0);
            $usefulMonths = tsInt($_POST['useful_life_months'] ?? 60, 1);
            $glAsset = tsStr($_POST['gl_asset_account'] ?? '1500', 30);
            $glAccum = tsStr($_POST['gl_accum_account'] ?? '1590', 30);
            $glExpense = tsStr($_POST['gl_expense_account'] ?? '6400', 30);
            $status = tsPick($_POST['status'] ?? 'available', ['available', 'checked_out', 'maintenance', 'retired'], 'available');
            $condition = tsPick($_POST['condition_rating'] ?? 'good', ['excellent', 'good', 'fair', 'damaged'], 'good');
            $maintInterval = tsInt($_POST['maintenance_interval_days'] ?? 180, 0);
            $nextMaint = tsDate($_POST['next_maintenance_date'] ?? '');
            $notes = tsStr($_POST['notes'] ?? '', 500);

            if ($assetTag === '' || $assetName === '') throw new RuntimeException('Asset Tag and Name are required.');

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE assets_register SET asset_tag = ?, asset_name = ?, category = ?, serial_number = ?, barcode = ?, rfid_tag = ?, home_location = ?, acquisition_date = ?, acquisition_cost = ?, salvage_value = ?, useful_life_months = ?, gl_asset_account = ?, gl_accum_account = ?, gl_expense_account = ?, status = ?, condition_rating = ?, maintenance_interval_days = ?, next_maintenance_date = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$assetTag, $assetName, $category, $serialNumber, $barcode, $rfidTag, $homeLocation, $acqDate, $acqCost, $salvage, $usefulMonths, $glAsset, $glAccum, $glExpense, $status, $condition, $maintInterval, $nextMaint, $notes, $id]);
                logAudit(tsUserId(), 'update_asset', 'assets', "Updated asset $assetTag ($assetName)");
                tsFlash('success', "Asset $assetTag updated.");
            } else {
                $stmt = $pdo->prepare("INSERT INTO assets_register (asset_tag, asset_name, category, serial_number, barcode, rfid_tag, home_location, acquisition_date, acquisition_cost, salvage_value, useful_life_months, gl_asset_account, gl_accum_account, gl_expense_account, status, condition_rating, maintenance_interval_days, next_maintenance_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$assetTag, $assetName, $category, $serialNumber, $barcode, $rfidTag, $homeLocation, $acqDate, $acqCost, $salvage, $usefulMonths, $glAsset, $glAccum, $glExpense, $status, $condition, $maintInterval, $nextMaint, $notes]);
                logAudit(tsUserId(), 'create_asset', 'assets', "Created asset $assetTag ($assetName)");
                tsFlash('success', "Asset $assetTag registered.");
            }
            tsRedirect('asset-tracking.php#register');
        }

        if ($subAction === 'checkout_asset') {
            $scanInput = tsStr($_POST['scan_input'] ?? '');
            $assetId = tsInt($_POST['asset_id'] ?? 0);
            $assigneeName = tsStr($_POST['assignee_name'] ?? '', 150);
            $assigneeRole = tsPick($_POST['assignee_role'] ?? 'tour_guide', ['tour_guide', 'driver', 'divemaster', 'captain', 'operations_staff'], 'tour_guide');
            $tourRef = tsStr($_POST['tour_reference'] ?? '', 120);
            $expReturn = tsDate($_POST['expected_return'] ?? date('Y-m-d', strtotime('+1 day')));
            $conditionOut = tsPick($_POST['condition_out'] ?? 'good', ['excellent', 'good', 'fair'], 'good');
            $notes = tsStr($_POST['notes'] ?? '', 255);

            if ($assigneeName === '') throw new RuntimeException('Assignee Name is required.');

            // Find asset by id, or barcode, rfid, serial, tag if scanned
            if ($assetId < 1 && $scanInput !== '') {
                $find = $pdo->prepare("SELECT id FROM assets_register WHERE asset_tag = ? OR barcode = ? OR rfid_tag = ? OR serial_number = ? LIMIT 1");
                $find->execute([$scanInput, $scanInput, $scanInput, $scanInput]);
                $assetId = (int)$find->fetchColumn();
            }

            if ($assetId < 1) throw new RuntimeException('Please select or scan a valid asset.');

            $pdo->beginTransaction();
            $asset = $pdo->query("SELECT * FROM assets_register WHERE id = $assetId FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
            if (!$asset) throw new RuntimeException('Asset not found.');
            if ($asset['status'] === 'checked_out') {
                throw new RuntimeException("Asset {$asset['asset_tag']} is already checked out.");
            }

            $stmt = $pdo->prepare("INSERT INTO asset_checkouts (asset_id, assignee_name, assignee_role, tour_reference, expected_return, condition_out, notes, checked_out_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$assetId, $assigneeName, $assigneeRole, $tourRef, $expReturn, $conditionOut, $notes, tsUserId()]);

            $pdo->prepare("UPDATE assets_register SET status = 'checked_out', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$assetId]);

            $pdo->commit();
            logAudit(tsUserId(), 'checkout_asset', 'assets', "Checked out {$asset['asset_tag']} to $assigneeName ($tourRef)");
            tsFlash('success', "Asset {$asset['asset_tag']} checked out to $assigneeName.");
            tsRedirect('asset-tracking.php#custody');
        }

        if ($subAction === 'checkin_asset') {
            $checkoutId = tsInt($_POST['checkout_id'] ?? 0);
            $conditionIn = tsPick($_POST['condition_in'] ?? 'good', ['excellent', 'good', 'fair', 'damaged'], 'good');
            $notes = tsStr($_POST['notes'] ?? '', 255);

            $pdo->beginTransaction();
            $co = $pdo->query("SELECT c.*, a.asset_tag FROM asset_checkouts c JOIN assets_register a ON a.id = c.asset_id WHERE c.id = $checkoutId AND c.checked_in_at IS NULL FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
            if (!$co) throw new RuntimeException('Active checkout record not found or already checked in.');

            $pdo->prepare("UPDATE asset_checkouts SET checked_in_at = CURRENT_TIMESTAMP, condition_in = ?, notes = CASE WHEN notes IS NOT NULL THEN notes || ' | In: ' || ? ELSE ? END, checked_in_by = ? WHERE id = ?")
                ->execute([$conditionIn, $notes, $notes, tsUserId(), $checkoutId]);

            $newStatus = ($conditionIn === 'damaged') ? 'maintenance' : 'available';
            $pdo->prepare("UPDATE assets_register SET status = ?, condition_rating = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$newStatus, $conditionIn, $co['asset_id']]);

            $pdo->commit();
            logAudit(tsUserId(), 'checkin_asset', 'assets', "Checked in {$co['asset_tag']} from {$co['assignee_name']} (Condition: $conditionIn)");
            tsFlash('success', "Asset {$co['asset_tag']} checked back in. Status set to " . strtoupper($newStatus) . ".");
            tsRedirect('asset-tracking.php#custody');
        }

        if ($subAction === 'record_maintenance') {
            $assetId = tsInt($_POST['asset_id'] ?? 0);
            $maintType = tsPick($_POST['maint_type'] ?? 'inspection', ['inspection', 'recalibration', 'repair', 'battery_replacement', 'overhaul'], 'inspection');
            $scheduledDate = tsDate($_POST['scheduled_date'] ?? date('Y-m-d'));
            $completedDate = tsDate($_POST['completed_date'] ?? date('Y-m-d'));
            $performedBy = tsStr($_POST['performed_by'] ?? '', 150);
            $cost = tsNum($_POST['cost'] ?? 0);
            $result = tsPick($_POST['result'] ?? 'passed', ['passed', 'needs_repair', 'condemned', 'scheduled'], 'passed');
            $certRef = tsStr($_POST['certificate_ref'] ?? '', 120);
            $notes = tsStr($_POST['notes'] ?? '', 500);

            if ($assetId < 1) throw new RuntimeException('Asset selection is required.');

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO asset_maintenance (asset_id, maint_type, scheduled_date, completed_date, performed_by, cost, result, certificate_ref, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$assetId, $maintType, $scheduledDate, $completedDate, $performedBy, $cost, $result, $certRef, $notes, tsUserId()]);

            // Update asset maintenance date
            $asset = $pdo->query("SELECT maintenance_interval_days FROM assets_register WHERE id = $assetId")->fetch(PDO::FETCH_ASSOC);
            $intDays = (int)($asset['maintenance_interval_days'] ?? 180);
            $nextDate = date('Y-m-d', strtotime(($completedDate ?: date('Y-m-d')) . " +$intDays days"));

            $pdo->prepare("UPDATE assets_register SET next_maintenance_date = ?, status = CASE WHEN ? = 'passed' AND status = 'maintenance' THEN 'available' ELSE status END, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$nextDate, $result, $assetId]);

            $pdo->commit();
            logAudit(tsUserId(), 'maintenance_asset', 'assets', "Logged $maintType for asset #$assetId (Result: $result)");
            tsFlash('success', "Maintenance / audit logged. Next audit scheduled for $nextDate.");
            tsRedirect('asset-tracking.php#maintenance');
        }

        if ($subAction === 'post_depreciation') {
            $period = tsStr($_POST['period'] ?? date('Y-m'), 7); // e.g. 2026-10
            $pdo->beginTransaction();
            $allAssets = $pdo->query("SELECT * FROM assets_register WHERE status <> 'retired' AND is_archived = 0")->fetchAll(PDO::FETCH_ASSOC);
            $postedCount = 0;

            $insStmt = $pdo->prepare("
                INSERT INTO asset_depreciation_postings (asset_id, period, amount, accumulated, book_value, gl_debit, gl_credit, journal_ref, posted_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($allAssets as $a) {
                // Check if already posted for this period
                $chk = $pdo->prepare("SELECT 1 FROM asset_depreciation_postings WHERE asset_id = ? AND period = ?");
                $chk->execute([$a['id'], $period]);
                if ($chk->fetchColumn()) continue;

                $cost = (float)$a['acquisition_cost'];
                $salvage = (float)$a['salvage_value'];
                $months = max(1, (int)$a['useful_life_months']);
                $monthlyDepr = round(($cost - $salvage) / $months, 2);

                // Fetch prior accumulated depreciation
                $priorAccum = (float)($pdo->query("SELECT COALESCE(SUM(amount), 0) FROM asset_depreciation_postings WHERE asset_id = {$a['id']}")->fetchColumn() ?: 0);
                if ($priorAccum >= ($cost - $salvage)) {
                    continue; // fully depreciated
                }

                $amountToPost = min($monthlyDepr, ($cost - $salvage) - $priorAccum);
                $newAccum = $priorAccum + $amountToPost;
                $bookValue = max($salvage, $cost - $newAccum);
                $journalRef = "DEP-{$period}-" . str_pad((string)$a['id'], 3, '0', STR_PAD_LEFT);

                $insStmt->execute([
                    $a['id'], $period, $amountToPost, $newAccum, $bookValue,
                    $a['gl_expense_account'] ?: '6400',
                    $a['gl_accum_account'] ?: '1590',
                    $journalRef, tsUserId()
                ]);
                $postedCount++;
            }
            $pdo->commit();
            logAudit(tsUserId(), 'post_asset_depreciation', 'assets', "Posted monthly straight-line depreciation for $postedCount assets for period $period");
            tsFlash('success', "Posted straight-line depreciation for $postedCount assets (Period: $period). GL Journals generated.");
            tsRedirect('asset-tracking.php#depreciation');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        tsFlash('error', $e->getMessage());
        tsRedirect('asset-tracking.php');
    }
}

// ============================================================================
// Query Datasets
// ============================================================================
$assets = $pdo->query("
    SELECT a.*,
           (SELECT COALESCE(SUM(amount), 0) FROM asset_depreciation_postings WHERE asset_id = a.id) AS total_depreciated,
           (SELECT assignee_name FROM asset_checkouts WHERE asset_id = a.id AND checked_in_at IS NULL ORDER BY id DESC LIMIT 1) AS active_assignee,
           (SELECT tour_reference FROM asset_checkouts WHERE asset_id = a.id AND checked_in_at IS NULL ORDER BY id DESC LIMIT 1) AS active_tour
    FROM assets_register a
    WHERE a.is_archived = 0
    ORDER BY a.asset_tag ASC
")->fetchAll(PDO::FETCH_ASSOC);

$checkouts = $pdo->query("
    SELECT c.*, a.asset_tag, a.asset_name, a.category, a.barcode, a.serial_number
    FROM asset_checkouts c
    JOIN assets_register a ON a.id = c.asset_id
    ORDER BY (c.checked_in_at IS NULL) DESC, c.id DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

$maintenanceLogs = $pdo->query("
    SELECT m.*, a.asset_tag, a.asset_name
    FROM asset_maintenance m
    JOIN assets_register a ON a.id = m.asset_id
    ORDER BY m.completed_date DESC, m.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$depreciationPostings = $pdo->query("
    SELECT d.*, a.asset_tag, a.asset_name, a.acquisition_cost
    FROM asset_depreciation_postings d
    JOIN assets_register a ON a.id = d.asset_id
    ORDER BY d.period DESC, d.id DESC
    LIMIT 60
")->fetchAll(PDO::FETCH_ASSOC);

// KPIs
$totalAssetValue = 0.0;
$totalBookValue = 0.0;
$checkedOutCount = 0;
$maintDueCount = 0;

foreach ($assets as $a) {
    $cost = (float)$a['acquisition_cost'];
    $depr = (float)$a['total_depreciated'];
    $bv = max((float)$a['salvage_value'], $cost - $depr);
    $totalAssetValue += $cost;
    $totalBookValue += $bv;
    if ($a['status'] === 'checked_out') $checkedOutCount++;
    $days = tsDaysUntil($a['next_maintenance_date']);
    if ($days !== null && $days <= 30) $maintDueCount++;
}

// Render Header
tsRenderHeader([
    'title' => 'Asset Lifecycle, Tracking & Maintenance (ALTM)',
    'subtitle' => 'High-value serialized gear tracking, barcode/RFID custody logging, GL-linked depreciation & calibration audits.',
    'theme' => 'ts-theme-assets',
    'icon' => 'fa-barcode',
    'hero_title' => 'Asset Lifecycle & Field Custody Tracking',
    'hero_text' => 'High-value non-consumable equipment continuously moves across tour guides, transport drivers, and remote islands. Track satellite transceivers, emergency oxygen units, and tour trailers with barcode check-out logging and automated GL depreciation.',
    'tags' => ['Serialized Asset Tracking', 'Barcode / RFID Custody', 'Straight-Line GL Depreciation', 'Calibration Audits'],
    'actions' => '
        <button class="ts-btn sm" data-ts-open="modalCheckout"><i class="fas fa-arrow-right-from-bracket"></i> Check-Out Asset</button>
        <button class="ts-btn green sm" data-ts-open="modalAddAsset"><i class="fas fa-plus"></i> Register New Asset</button>
    ',
]);
?>

<!-- KPIs -->
<div class="ts-kpis">
    <?php echo tsKpi('Total Asset Gross Value', tsMoney($totalAssetValue), 'fa-vault', 'blue', count($assets) . ' registered capitalized assets'); ?>
    <?php echo tsKpi('Net Book Value (GL)', tsMoney($totalBookValue), 'fa-scale-balanced', 'green', 'Linked to GL Asset #1500 / #1590'); ?>
    <?php echo tsKpi('In Field Custody', "{$checkedOutCount} / " . count($assets), 'fa-person-walking-luggage', $checkedOutCount > 0 ? 'violet' : 'ghost', 'Checked out to guides & drivers'); ?>
    <?php echo tsKpi('Audits / Calibrations Due', (string)$maintDueCount, 'fa-stethoscope', $maintDueCount > 0 ? 'amber' : 'green', $maintDueCount > 0 ? 'Due within 30 days' : 'All certifications current'); ?>
</div>

<!-- Tabs -->
<div class="ts-tabs" data-ts-tab-group="assets">
    <button class="ts-tab" data-tab="register"><i class="fas fa-list-check"></i> Serialized Asset Register <span class="ts-count"><?php echo count($assets); ?></span></button>
    <button class="ts-tab" data-tab="custody"><i class="fas fa-arrow-right-arrow-left"></i> Check-Out / Check-In Custody <span class="ts-count"><?php echo count($checkouts); ?></span></button>
    <button class="ts-tab" data-tab="depreciation"><i class="fas fa-receipt"></i> GL Depreciation Schedules</button>
    <button class="ts-tab" data-tab="maintenance"><i class="fas fa-stethoscope"></i> Maintenance &amp; Recalibration Audits <span class="ts-count"><?php echo count($maintenanceLogs); ?></span></button>
</div>

<!-- ======================================================================
     PANEL 1: Asset Register
     ====================================================================== -->
<div class="ts-panel" data-panel-group="assets" data-panel="register">
    <div class="ts-card">
        <div class="ts-card-header">
            <h3><i class="fas fa-barcode"></i> Capitalized &amp; High-Value Serialized Equipment</h3>
            <div class="ts-card-tools">
                <div class="ts-search">
                    <i class="fas fa-magnifying-glass"></i>
                    <input type="text" placeholder="Search tag, serial, barcode, RFID..." data-ts-search="assetTable">
                </div>
            </div>
        </div>
        <div class="ts-table-wrap">
            <table class="ts-table" id="assetTable">
                <thead>
                    <tr>
                        <th>Asset Tag &amp; Name</th>
                        <th>Category</th>
                        <th>Identifiers (SN / Barcode / RFID)</th>
                        <th>Home Depot</th>
                        <th class="ts-num">Acquisition Cost</th>
                        <th class="ts-num">Net Book Value</th>
                        <th>Status</th>
                        <th>Condition</th>
                        <th>Current Custodian</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($assets)): ?>
                    <tr><td colspan="10" class="ts-empty">No assets registered yet.</td></tr>
                    <?php else: foreach ($assets as $a):
                        $cost = (float)$a['acquisition_cost'];
                        $depr = (float)$a['total_depreciated'];
                        $bv = max((float)$a['salvage_value'], $cost - $depr);
                    ?>
                    <tr>
                        <td>
                            <strong class="ts-code"><?php echo tsh($a['asset_tag']); ?></strong>
                            <div style="font-size:12.5px;"><?php echo tsh($a['asset_name']); ?></div>
                        </td>
                        <td><?php echo tsBadge($a['category'], ['electronics'=>'blue', 'safety'=>'red', 'trailers'=>'amber', 'audio_guides'=>'violet', 'marine_gear'=>'cyan'], 'ghost'); ?></td>
                        <td>
                            <div><strong style="font-size:11px;">SN:</strong> <?php echo tsh($a['serial_number'] ?: '—'); ?></div>
                            <div><span class="ts-chip">BAR: <?php echo tsh($a['barcode'] ?: '—'); ?></span> <span class="ts-chip">RFID: <?php echo tsh($a['rfid_tag'] ?: '—'); ?></span></div>
                        </td>
                        <td><span class="ts-muted"><?php echo tsh($a['home_location'] ?: 'Central HQ'); ?></span></td>
                        <td class="ts-num"><?php echo tsMoney($cost); ?></td>
                        <td class="ts-num"><strong style="color:var(--primary);"><?php echo tsMoney($bv); ?></strong></td>
                        <td><?php echo tsBadge($a['status'], ['available'=>'green', 'checked_out'=>'violet', 'maintenance'=>'amber', 'retired'=>'red'], 'ghost'); ?></td>
                        <td><?php echo tsBadge($a['condition_rating'], ['excellent'=>'green', 'good'=>'blue', 'fair'=>'amber', 'damaged'=>'red'], 'ghost'); ?></td>
                        <td>
                            <?php if ($a['status'] === 'checked_out' && $a['active_assignee']): ?>
                            <span class="ts-badge violet"><i class="fas fa-user"></i> <?php echo tsh($a['active_assignee']); ?></span>
                            <?php else: ?>
                            <span class="ts-muted">— In Depot —</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <div class="ts-row-actions">
                                <?php if ($a['status'] === 'available'): ?>
                                <button class="ts-icon-btn blue" title="Check-Out" data-ts-open="modalCheckout" data-ts-payload="<?php echo tsPayload(['asset_id' => $a['id'], '__title' => "Check-Out {$a['asset_tag']}"]); ?>">
                                    <i class="fas fa-arrow-right-from-bracket"></i>
                                </button>
                                <?php endif; ?>
                                <button class="ts-icon-btn green" title="Log Audit / Maintenance" data-ts-open="modalMaintenance" data-ts-payload="<?php echo tsPayload(['asset_id' => $a['id'], '__title' => "Log Audit for {$a['asset_tag']}"]); ?>">
                                    <i class="fas fa-stethoscope"></i>
                                </button>
                                <button class="ts-icon-btn" title="Edit Asset" data-ts-open="modalAddAsset" data-ts-payload="<?php echo tsPayload(array_merge($a, ['__title' => 'Edit Capital Asset'])); ?>">
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
     PANEL 2: Custody Logging (Check-Out / Check-In)
     ====================================================================== -->
<div class="ts-panel" data-panel-group="assets" data-panel="custody">
    <!-- Quick Scan Bar -->
    <div class="ts-scan">
        <i class="fas fa-barcode" style="font-size:24px; color:var(--primary);"></i>
        <form method="POST" action="asset-tracking.php" style="display:flex; gap:10px; flex:1;">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="checkout_asset">
            <input type="text" name="scan_input" placeholder="Scan Barcode / RFID Tag or enter Asset Tag to rapidly issue..." autofocus>
            <input type="text" name="assignee_name" required placeholder="Assignee (Guide / Driver Name)" style="width:240px; padding:11px 14px; border-radius:10px; border:1.5px solid var(--border);">
            <button type="submit" class="ts-btn"><i class="fas fa-bolt"></i> Quick Issue</button>
        </form>
    </div>

    <div class="ts-card">
        <div class="ts-card-header">
            <h3><i class="fas fa-arrow-right-arrow-left"></i> Equipment Custody Ledger (Field Allocations)</h3>
        </div>
        <div class="ts-table-wrap">
            <table class="ts-table">
                <thead>
                    <tr>
                        <th>Asset Tag &amp; Item</th>
                        <th>Custodian</th>
                        <th>Role</th>
                        <th>Tour Reference</th>
                        <th>Checked Out At</th>
                        <th>Expected Return</th>
                        <th>Return Status</th>
                        <th>Condition (Out &rarr; In)</th>
                        <th>Notes</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($checkouts)): ?>
                    <tr><td colspan="10" class="ts-empty">No checkout activity recorded.</td></tr>
                    <?php else: foreach ($checkouts as $co):
                        $isReturned = !empty($co['checked_in_at']);
                        $isOverdue = !$isReturned && tsDaysUntil($co['expected_return']) < 0;
                    ?>
                    <tr>
                        <td>
                            <strong class="ts-code"><?php echo tsh($co['asset_tag']); ?></strong>
                            <div class="ts-muted"><?php echo tsh($co['asset_name']); ?></div>
                        </td>
                        <td><strong><?php echo tsh($co['assignee_name']); ?></strong></td>
                        <td><?php echo tsBadge($co['assignee_role'], ['tour_guide'=>'blue', 'driver'=>'green', 'divemaster'=>'cyan'], 'ghost'); ?></td>
                        <td><span class="ts-chip"><?php echo tsh($co['tour_reference'] ?: 'Direct'); ?></span></td>
                        <td><?php echo tsFmtDate($co['checked_out_at'], 'M d, Y h:i A'); ?></td>
                        <td>
                            <?php echo tsFmtDate($co['expected_return']); ?>
                            <?php if ($isOverdue): ?><div class="ts-badge red" style="font-size:10px;">OVERDUE</div><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isReturned): ?>
                            <span class="ts-badge green"><i class="fas fa-check"></i> In Depot (<?php echo tsFmtDate($co['checked_in_at'], 'M d'); ?>)</span>
                            <?php else: ?>
                            <span class="ts-badge violet ts-pulse"><i class="fas fa-clock"></i> Active with Guide</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="ts-chip"><?php echo tsh($co['condition_out'] ?: 'good'); ?></span>
                            <?php if ($isReturned): ?>&rarr; <span class="ts-chip"><?php echo tsh($co['condition_in'] ?: 'good'); ?></span><?php endif; ?>
                        </td>
                        <td class="ts-muted"><?php echo tsh($co['notes'] ?: '—'); ?></td>
                        <td style="text-align:right;">
                            <?php if (!$isReturned): ?>
                            <button class="ts-btn sm green" data-ts-open="modalCheckin" data-ts-payload="<?php echo tsPayload(['checkout_id' => $co['id'], '__title' => "Check-In {$co['asset_tag']} from {$co['assignee_name']}"]); ?>">
                                <i class="fas fa-arrow-left-to-bracket"></i> Check-In
                            </button>
                            <?php else: ?><span class="ts-muted">Archived</span><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ======================================================================
     PANEL 3: GL Depreciation
     ====================================================================== -->
<div class="ts-panel" data-panel-group="assets" data-panel="depreciation">
    <div class="ts-card">
        <div class="ts-card-header">
            <h3><i class="fas fa-receipt"></i> Monthly Straight-Line Depreciation Engine (Linked to GL)</h3>
            <div class="ts-card-tools">
                <form method="POST" action="asset-tracking.php" style="display:inline-flex; gap:8px;">
                    <?php echo tsCsrfField(); ?>
                    <input type="hidden" name="sub_action" value="post_depreciation">
                    <input type="month" name="period" value="<?php echo date('Y-m'); ?>" style="padding:6px 10px; border-radius:8px; border:1px solid var(--border); font-size:12.5px;">
                    <button type="submit" class="ts-btn sm green"><i class="fas fa-calculator"></i> Run Month-End Posting</button>
                </form>
            </div>
        </div>
        <div class="ts-table-wrap">
            <table class="ts-table">
                <thead>
                    <tr>
                        <th>Period</th>
                        <th>Asset</th>
                        <th class="ts-num">Acquisition Cost</th>
                        <th class="ts-num">Monthly Depreciation</th>
                        <th class="ts-num">Accumulated Depr.</th>
                        <th class="ts-num">Carrying Book Value</th>
                        <th>GL Accounts (Dr / Cr)</th>
                        <th>Journal Reference</th>
                        <th>Posted At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($depreciationPostings)): ?>
                    <tr><td colspan="9" class="ts-empty">No monthly depreciation entries generated yet. Click "Run Month-End Posting" to calculate.</td></tr>
                    <?php else: foreach ($depreciationPostings as $dp): ?>
                    <tr>
                        <td><strong><?php echo tsh($dp['period']); ?></strong></td>
                        <td>
                            <strong class="ts-code"><?php echo tsh($dp['asset_tag']); ?></strong>
                            <div class="ts-muted"><?php echo tsh($dp['asset_name']); ?></div>
                        </td>
                        <td class="ts-num"><?php echo tsMoney($dp['acquisition_cost']); ?></td>
                        <td class="ts-num"><strong style="color:var(--danger);"><?php echo tsMoney($dp['amount']); ?></strong></td>
                        <td class="ts-num"><?php echo tsMoney($dp['accumulated']); ?></td>
                        <td class="ts-num"><strong><?php echo tsMoney($dp['book_value']); ?></strong></td>
                        <td>
                            <span class="ts-chip">Dr: <?php echo tsh($dp['gl_debit']); ?></span>
                            <span class="ts-chip">Cr: <?php echo tsh($dp['gl_credit']); ?></span>
                        </td>
                        <td><span class="ts-code"><?php echo tsh($dp['journal_ref']); ?></span></td>
                        <td><span class="ts-muted"><?php echo tsFmtDate($dp['posted_at'], 'M d, Y'); ?></span></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ======================================================================
     PANEL 4: Maintenance & Calibration Audits
     ====================================================================== -->
<div class="ts-panel" data-panel-group="assets" data-panel="maintenance">
    <div class="ts-card">
        <div class="ts-card-header">
            <h3><i class="fas fa-stethoscope"></i> Scheduled Recalibration, Safety &amp; Maintenance Audits</h3>
            <div class="ts-card-tools">
                <button class="ts-btn sm green" data-ts-open="modalMaintenance"><i class="fas fa-plus"></i> Record Audit</button>
            </div>
        </div>
        <div class="ts-table-wrap">
            <table class="ts-table">
                <thead>
                    <tr>
                        <th>Asset Tag &amp; Item</th>
                        <th>Audit / Maintenance Type</th>
                        <th>Date Completed</th>
                        <th>Certified Inspector / Shop</th>
                        <th class="ts-num">Audit Cost</th>
                        <th>Audit Result</th>
                        <th>Calibration Certificate Ref</th>
                        <th>Inspector Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($maintenanceLogs)): ?>
                    <tr><td colspan="8" class="ts-empty">No maintenance audits recorded.</td></tr>
                    <?php else: foreach ($maintenanceLogs as $ml): ?>
                    <tr>
                        <td>
                            <strong class="ts-code"><?php echo tsh($ml['asset_tag']); ?></strong>
                            <div class="ts-muted"><?php echo tsh($ml['asset_name']); ?></div>
                        </td>
                        <td><?php echo tsBadge($ml['maint_type'], ['recalibration'=>'violet', 'inspection'=>'blue', 'repair'=>'amber', 'overhaul'=>'red'], 'ghost'); ?></td>
                        <td><strong><?php echo tsFmtDate($ml['completed_date']); ?></strong></td>
                        <td><?php echo tsh($ml['performed_by'] ?: 'In-house Technician'); ?></td>
                        <td class="ts-num"><?php echo tsMoney($ml['cost']); ?></td>
                        <td><?php echo tsBadge($ml['result'], ['passed'=>'green', 'needs_repair'=>'amber', 'condemned'=>'red'], 'ghost'); ?></td>
                        <td><span class="ts-code"><?php echo tsh($ml['certificate_ref'] ?: 'N/A'); ?></span></td>
                        <td class="ts-muted"><?php echo tsh($ml['notes'] ?: '—'); ?></td>
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

<!-- Modal: Add / Edit Capital Asset -->
<div class="ts-modal-backdrop" id="modalAddAsset">
    <div class="ts-modal wide">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-barcode"></i></div>
            <h3 data-ts-title>Register Capital Equipment Asset</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="asset-tracking.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="save_asset">
            <input type="hidden" name="id" value="0">
            <div class="ts-modal-body">
                <div class="ts-grid">
                    <div class="ts-field">
                        <label>Asset Tag <span class="req">*</span></label>
                        <input type="text" name="asset_tag" required placeholder="e.g. AST-SAT-003, AST-MED-004">
                    </div>
                    <div class="ts-field">
                        <label>Asset Name <span class="req">*</span></label>
                        <input type="text" name="asset_name" required placeholder="e.g. Garmin inReach Explorer+">
                    </div>
                    <div class="ts-field">
                        <label>Category</label>
                        <select name="category">
                            <?php echo tsOptions(['electronics'=>'Electronics / Satellite Comm', 'safety'=>'Safety & First-Aid Oxygen Sets', 'trailers'=>'Luggage & Equipment Trailers', 'audio_guides'=>'Audio Tour Headset Sets', 'marine_gear'=>'Marine Diving Gear', 'other'=>'Other Capital Equipment']); ?>
                        </select>
                    </div>
                    <div class="ts-field">
                        <label>Manufacturer Serial Number</label>
                        <input type="text" name="serial_number" placeholder="SN-XXXXX">
                    </div>
                    <div class="ts-field">
                        <label>Barcode ID</label>
                        <input type="text" name="barcode" placeholder="BAR-AST-...">
                    </div>
                    <div class="ts-field">
                        <label>RFID Tag Code</label>
                        <input type="text" name="rfid_tag" placeholder="RFID-HEX-...">
                    </div>
                    <div class="ts-field">
                        <label>Home Location / Depot</label>
                        <input type="text" name="home_location" placeholder="e.g. Bohol Operations Center, Cebu HQ">
                    </div>
                    <div class="ts-field">
                        <label>Acquisition Date</label>
                        <input type="date" name="acquisition_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="ts-field">
                        <label>Acquisition Cost (PHP) <span class="req">*</span></label>
                        <input type="number" step="0.01" name="acquisition_cost" value="0.00" required>
                    </div>
                    <div class="ts-field">
                        <label>Estimated Salvage Value (PHP)</label>
                        <input type="number" step="0.01" name="salvage_value" value="0.00">
                    </div>
                    <div class="ts-field">
                        <label>Useful Life (Months)</label>
                        <input type="number" name="useful_life_months" value="36" min="1">
                    </div>
                    <div class="ts-field">
                        <label>GL Asset Account (Balance Sheet)</label>
                        <input type="text" name="gl_asset_account" value="1500" placeholder="1500 - Equipment">
                    </div>
                    <div class="ts-field">
                        <label>GL Accum. Depreciation Account</label>
                        <input type="text" name="gl_accum_account" value="1590" placeholder="1590 - Accum Depr">
                    </div>
                    <div class="ts-field">
                        <label>GL Depreciation Expense Account</label>
                        <input type="text" name="gl_expense_account" value="6400" placeholder="6400 - Depr Expense">
                    </div>
                    <div class="ts-field">
                        <label>Recalibration Audit Interval (Days)</label>
                        <input type="number" name="maintenance_interval_days" value="180">
                    </div>
                    <div class="ts-field">
                        <label>Next Recalibration Due Date</label>
                        <input type="date" name="next_maintenance_date" value="<?php echo date('Y-m-d', strtotime('+180 days')); ?>">
                    </div>
                    <div class="ts-field span-2">
                        <label>Asset Notes</label>
                        <textarea name="notes" placeholder="Battery life specs, carrying case id, firmware version"></textarea>
                    </div>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn green"><i class="fas fa-save"></i> Save Asset</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Check-Out Asset -->
<div class="ts-modal-backdrop" id="modalCheckout">
    <div class="ts-modal">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-arrow-right-from-bracket"></i></div>
            <h3 data-ts-title>Check-Out Asset to Guide / Driver</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="asset-tracking.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="checkout_asset">
            <div class="ts-modal-body">
                <div class="ts-field" style="margin-bottom:12px;">
                    <label>Select Equipment Asset <span class="req">*</span></label>
                    <select name="asset_id" required>
                        <option value="">— Select Available Asset —</option>
                        <?php foreach ($assets as $a): if ($a['status'] === 'available'): ?>
                        <option value="<?php echo $a['id']; ?>">
                            <?php echo tsh("{$a['asset_tag']} - {$a['asset_name']} (SN: {$a['serial_number']})"); ?>
                        </option>
                        <?php endif; endforeach; ?>
                    </select>
                </div>
                <div class="ts-grid">
                    <div class="ts-field">
                        <label>Assignee Name (Guide / Driver) <span class="req">*</span></label>
                        <input type="text" name="assignee_name" required placeholder="e.g. Lead Guide Rico">
                    </div>
                    <div class="ts-field">
                        <label>Assignee Role</label>
                        <select name="assignee_role">
                            <option value="tour_guide">Tour Guide</option>
                            <option value="driver">Van / Bus Driver</option>
                            <option value="divemaster">Divemaster</option>
                            <option value="captain">Boat Captain</option>
                        </select>
                    </div>
                    <div class="ts-field">
                        <label>Tour Code / Reference</label>
                        <input type="text" name="tour_reference" placeholder="e.g. EXP-PAL-2026-90">
                    </div>
                    <div class="ts-field">
                        <label>Expected Return Date <span class="req">*</span></label>
                        <input type="date" name="expected_return" required value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                    </div>
                    <div class="ts-field span-2">
                        <label>Condition at Check-Out</label>
                        <select name="condition_out">
                            <option value="excellent">Excellent - Fully Tested</option>
                            <option value="good" selected>Good - Normal Wear</option>
                            <option value="fair">Fair - Operational</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn"><i class="fas fa-check"></i> Complete Check-Out</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Check-In Asset -->
<div class="ts-modal-backdrop" id="modalCheckin">
    <div class="ts-modal">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-arrow-left-to-bracket"></i></div>
            <h3 data-ts-title>Check-In Equipment Return</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="asset-tracking.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="checkin_asset">
            <input type="hidden" name="checkout_id" value="0">
            <div class="ts-modal-body">
                <div class="ts-field" style="margin-bottom:12px;">
                    <label>Condition Upon Return <span class="req">*</span></label>
                    <select name="condition_in" required>
                        <option value="excellent">Excellent - Cleaned &amp; Tested</option>
                        <option value="good" selected>Good - Normal Operational</option>
                        <option value="fair">Fair - Needs Battery Recharge</option>
                        <option value="damaged">Damaged - Move to Maintenance</option>
                    </select>
                </div>
                <div class="ts-field">
                    <label>Inspection Notes</label>
                    <textarea name="notes" placeholder="Battery level, antenna integrity, strap condition"></textarea>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn green"><i class="fas fa-check"></i> Complete Check-In</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Record Maintenance -->
<div class="ts-modal-backdrop" id="modalMaintenance">
    <div class="ts-modal">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-stethoscope"></i></div>
            <h3 data-ts-title>Record Recalibration / Maintenance Audit</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="asset-tracking.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="record_maintenance">
            <div class="ts-modal-body">
                <div class="ts-field" style="margin-bottom:12px;">
                    <label>Equipment Asset <span class="req">*</span></label>
                    <select name="asset_id" required>
                        <?php foreach ($assets as $a): ?>
                        <option value="<?php echo $a['id']; ?>"><?php echo tsh("{$a['asset_tag']} - {$a['asset_name']}"); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ts-grid">
                    <div class="ts-field">
                        <label>Maintenance Type</label>
                        <select name="maint_type">
                            <option value="recalibration">Recalibration Audit</option>
                            <option value="inspection">Safety Audit &amp; Inspection</option>
                            <option value="battery_replacement">Battery Overhaul</option>
                            <option value="repair">Corrective Repair</option>
                        </select>
                    </div>
                    <div class="ts-field">
                        <label>Completion Date</label>
                        <input type="date" name="completed_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="ts-field">
                        <label>Certified Inspector / Lab</label>
                        <input type="text" name="performed_by" placeholder="e.g. Garmin Authorized Lab, Bureau of Fire">
                    </div>
                    <div class="ts-field">
                        <label>Service Cost (PHP)</label>
                        <input type="number" step="0.01" name="cost" value="0.00">
                    </div>
                    <div class="ts-field">
                        <label>Audit Result</label>
                        <select name="result">
                            <option value="passed">Passed Certification</option>
                            <option value="needs_repair">Conditional Pass / Minor Repair</option>
                            <option value="condemned">Failed / Decommission</option>
                        </select>
                    </div>
                    <div class="ts-field">
                        <label>Certificate / Compliance ID</label>
                        <input type="text" name="certificate_ref" placeholder="CERT-2026-XXXX">
                    </div>
                    <div class="ts-field span-2">
                        <label>Detailed Audit Findings</label>
                        <textarea name="notes" placeholder="Battery charge holding capacity 96%, signal strength verified."></textarea>
                    </div>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn green"><i class="fas fa-save"></i> Save Maintenance Record</button>
            </div>
        </form>
    </div>
</div>

<?php tsRenderFooter(); ?>
