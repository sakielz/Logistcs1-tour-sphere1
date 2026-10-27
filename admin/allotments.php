<?php
/**
 * admin/allotments.php
 * Module 6: Service Contracting & Allotment Management
 *
 * Core Functions:
 *  - Master Service Contracts: Non-physical capacity commitments (hotel rooms, ferry passes, guide days).
 *  - Tiered Rate Sheets: Peak, shoulder, off-peak rates and tiered volume pricing.
 *  - Allotment Inventory & Cut-Offs: Daily inventory reservation, release cut-off alerts, single-click return of unsold units.
 *  - Bulk Allotment Calendar Generator: Populates forward service schedules with automated release date derivation.
 *  - Attrition & Penalty Analysis: Tracks committed vs consumed capacity and calculates financial penalty exposure.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tour_ops.php';

$pdo = tourOpsBoot('allotments.php');
$suppliers = tsSuppliers($pdo);
$supplierMap = tsSupplierMap($suppliers);

// ============================================================================
// POST Actions
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tsRequireCsrf('allotments.php');
    $subAction = (string)($_POST['sub_action'] ?? '');

    try {
        // --- 1. Save Master Service Contract ---
        if ($subAction === 'save_contract') {
            $id = tsInt($_POST['id'] ?? 0);
            $code = tsStr($_POST['contract_code'] ?? tsNextNumber('SC'), 60);
            $supplierId = tsInt($_POST['supplier_id'] ?? 0) ?: null;
            $serviceType = tsPick($_POST['service_type'] ?? 'hotel_room', ['hotel_room', 'ferry_pass', 'guide_service', 'vehicle_charter', 'attraction_entry'], 'hotel_room');
            $serviceName = tsStr($_POST['service_name'] ?? '', 200);
            $unitLabel = tsStr($_POST['unit_label'] ?? 'units', 40);
            $startDate = tsDate($_POST['start_date'] ?? date('Y-01-01'));
            $endDate = tsDate($_POST['end_date'] ?? date('Y-12-31'));
            $releaseDays = tsInt($_POST['release_days'] ?? 14);
            $committedUnits = tsInt($_POST['committed_units'] ?? 0);
            $attritionPct = tsNum($_POST['attrition_pct'] ?? 10.0);
            $penaltyPct = tsNum($_POST['penalty_pct'] ?? 100.0);
            $currency = tsStr($_POST['currency'] ?? 'PHP', 10);
            $status = tsPick($_POST['status'] ?? 'active', ['active', 'pending', 'expired', 'terminated'], 'active');
            $notes = tsStr($_POST['notes'] ?? '', 500);

            if ($serviceName === '') {
                throw new RuntimeException('Service name is required.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE service_contracts SET contract_code = ?, supplier_id = ?, service_type = ?, service_name = ?, unit_label = ?, start_date = ?, end_date = ?, release_days = ?, committed_units = ?, attrition_pct = ?, penalty_pct = ?, currency = ?, status = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$code, $supplierId, $serviceType, $serviceName, $unitLabel, $startDate, $endDate, $releaseDays, $committedUnits, $attritionPct, $penaltyPct, $currency, $status, $notes, $id]);
                logAudit(tsUserId(), 'update_service_contract', 'contract', "Updated service contract $code ($serviceName)");
                tsFlash('success', "Service contract $code updated.");
            } else {
                $stmt = $pdo->prepare("INSERT INTO service_contracts (contract_code, supplier_id, service_type, service_name, unit_label, start_date, end_date, release_days, committed_units, attrition_pct, penalty_pct, currency, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$code, $supplierId, $serviceType, $serviceName, $unitLabel, $startDate, $endDate, $releaseDays, $committedUnits, $attritionPct, $penaltyPct, $currency, $status, $notes, tsUserId()]);
                logAudit(tsUserId(), 'create_service_contract', 'contract', "Created service contract $code ($serviceName)");
                tsFlash('success', "Service contract $code created.");
            }
            tsRedirect('allotments.php#contracts');
        }

        // --- 2. Save Rate Tier ---
        if ($subAction === 'save_rate_tier') {
            $id = tsInt($_POST['id'] ?? 0);
            $contractId = tsInt($_POST['contract_id'] ?? 0);
            $tierName = tsStr($_POST['tier_name'] ?? '', 100);
            $season = tsPick($_POST['season'] ?? 'all', ['all', 'peak', 'off', 'shoulder'], 'all');
            $minUnits = tsInt($_POST['min_units'] ?? 1, 1);
            $maxUnits = tsInt($_POST['max_units'] ?? 0) ?: null;
            $rate = tsNum($_POST['rate'] ?? 0);
            $validFrom = tsDate($_POST['valid_from'] ?? null);
            $validTo = tsDate($_POST['valid_to'] ?? null);

            if ($contractId < 1 || $tierName === '' || $rate <= 0) {
                throw new RuntimeException('Contract, Tier Name, and a positive Rate are required.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE service_rate_tiers SET contract_id = ?, tier_name = ?, season = ?, min_units = ?, max_units = ?, rate = ?, valid_from = ?, valid_to = ? WHERE id = ?");
                $stmt->execute([$contractId, $tierName, $season, $minUnits, $maxUnits, $rate, $validFrom, $validTo, $id]);
                tsFlash('success', "Rate tier $tierName updated.");
            } else {
                $stmt = $pdo->prepare("INSERT INTO service_rate_tiers (contract_id, tier_name, season, min_units, max_units, rate, valid_from, valid_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$contractId, $tierName, $season, $minUnits, $maxUnits, $rate, $validFrom, $validTo]);
                tsFlash('success', "Rate tier $tierName registered.");
            }
            tsRedirect('allotments.php#rate_tiers');
        }

        // --- 3. Save Single Allotment Day ---
        if ($subAction === 'save_allotment') {
            $id = tsInt($_POST['id'] ?? 0);
            $contractId = tsInt($_POST['contract_id'] ?? 0);
            $serviceDate = tsDate($_POST['service_date'] ?? date('Y-m-d'));
            $unitsAllotted = tsInt($_POST['units_allotted'] ?? 0);
            $unitsSold = tsInt($_POST['units_sold'] ?? 0);
            $unitsReleased = tsInt($_POST['units_released'] ?? 0);
            $rate = tsNum($_POST['rate'] ?? 0);
            $rateTierId = tsInt($_POST['rate_tier_id'] ?? 0) ?: null;
            $status = tsPick($_POST['status'] ?? 'open', ['open', 'released', 'closed', 'penalty_incurred'], 'open');
            $notes = tsStr($_POST['notes'] ?? '', 255);

            if ($contractId < 1 || !$serviceDate) {
                throw new RuntimeException('Contract and Service Date are required.');
            }

            // Derive release date from contract's release_days notice period
            $cStmt = $pdo->prepare("SELECT release_days FROM service_contracts WHERE id = ?");
            $cStmt->execute([$contractId]);
            $relDays = (int)($cStmt->fetchColumn() ?: 14);
            $releaseDate = tsDate($_POST['release_date'] ?? '') ?: date('Y-m-d', strtotime("$serviceDate - $relDays days"));

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE service_allotments SET contract_id = ?, service_date = ?, units_allotted = ?, units_sold = ?, units_released = ?, release_date = ?, rate_tier_id = ?, rate = ?, status = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$contractId, $serviceDate, $unitsAllotted, $unitsSold, $unitsReleased, $releaseDate, $rateTierId, $rate, $status, $notes, $id]);
                tsFlash('success', "Allotment schedule for $serviceDate updated.");
            } else {
                $stmt = $pdo->prepare("INSERT INTO service_allotments (contract_id, service_date, units_allotted, units_sold, units_released, release_date, rate_tier_id, rate, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$contractId, $serviceDate, $unitsAllotted, $unitsSold, $unitsReleased, $releaseDate, $rateTierId, $rate, $status, $notes]);
                tsFlash('success', "Allotment schedule for $serviceDate created.");
            }
            tsRedirect('allotments.php#allotments');
        }

        // --- 4. Bulk Generate Allotments Calendar ---
        if ($subAction === 'bulk_generate_allotments') {
            $contractId = tsInt($_POST['contract_id'] ?? 0);
            $startDate = tsDate($_POST['start_date'] ?? '');
            $endDate = tsDate($_POST['end_date'] ?? '');
            $dailyUnits = tsInt($_POST['daily_units'] ?? 10, 1);
            $rate = tsNum($_POST['rate'] ?? 0);
            $daysSelected = (array)($_POST['days'] ?? [1, 2, 3, 4, 5, 6, 7]); // 1=Mon, 7=Sun

            if ($contractId < 1 || !$startDate || !$endDate) {
                throw new RuntimeException('Please specify contract, start date, and end date.');
            }
            if (strtotime($startDate) > strtotime($endDate)) {
                throw new RuntimeException('Start date cannot be after end date.');
            }

            $cStmt = $pdo->prepare("SELECT release_days FROM service_contracts WHERE id = ?");
            $cStmt->execute([$contractId]);
            $relDays = (int)($cStmt->fetchColumn() ?: 14);

            $insStmt = $pdo->prepare("INSERT INTO service_allotments (contract_id, service_date, units_allotted, units_sold, units_released, release_date, rate, status, notes) VALUES (?, ?, ?, 0, 0, ?, ?, 'open', 'Bulk schedule generation')");
            $checkStmt = $pdo->prepare("SELECT id FROM service_allotments WHERE contract_id = ? AND service_date = ?");

            $curr = strtotime($startDate);
            $end = strtotime($endDate);
            $countCreated = 0;

            $pdo->beginTransaction();
            while ($curr <= $end) {
                $dayOfWeek = (int)date('N', $curr);
                if (in_array($dayOfWeek, $daysSelected, false)) {
                    $sDate = date('Y-m-d', $curr);
                    $checkStmt->execute([$contractId, $sDate]);
                    if (!$checkStmt->fetchColumn()) {
                        $rDate = date('Y-m-d', strtotime("$sDate - $relDays days"));
                        $insStmt->execute([$contractId, $sDate, $dailyUnits, $rDate, $rate]);
                        $countCreated++;
                    }
                }
                $curr = strtotime('+1 day', $curr);
            }
            $pdo->commit();

            logAudit(tsUserId(), 'bulk_generate_allotments', 'contract', "Bulk generated $countCreated allotment dates for Contract #$contractId");
            tsFlash('success', "Generated $countCreated allotment dates with automated release cut-offs.");
            tsRedirect('allotments.php#allotments');
        }

        // --- 5. Quick Release Unsold Units (Avoid Penalty) ---
        if ($subAction === 'quick_release') {
            $id = tsInt($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT a.*, c.contract_code, c.service_name FROM service_allotments a JOIN service_contracts c ON a.contract_id = c.id WHERE a.id = ?");
            $stmt->execute([$id]);
            $allotment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$allotment) throw new RuntimeException('Allotment schedule not found.');

            $unsold = max(0, (int)$allotment['units_allotted'] - (int)$allotment['units_sold'] - (int)$allotment['units_released']);
            if ($unsold <= 0) {
                throw new RuntimeException('No unsold units remaining to release.');
            }

            $newReleased = (int)$allotment['units_released'] + $unsold;
            $newStatus = ((int)$allotment['units_sold'] + $newReleased >= (int)$allotment['units_allotted']) ? 'released' : 'open';

            $upStmt = $pdo->prepare("UPDATE service_allotments SET units_released = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $upStmt->execute([$newReleased, $newStatus, $id]);

            logAudit(tsUserId(), 'release_allotment', 'contract', "Released $unsold unsold units for {$allotment['service_name']} ({$allotment['service_date']}) to avoid cancellation penalty.");
            tsFlash('success', "Successfully returned $unsold unsold units to supplier without penalty. Allotment marked as RELEASED.");
            tsRedirect('allotments.php#allotments');
        }

        // --- 6. Quick Update Sold Units ---
        if ($subAction === 'quick_update_sold') {
            $id = tsInt($_POST['id'] ?? 0);
            $sold = tsInt($_POST['units_sold'] ?? 0);

            $stmt = $pdo->prepare("SELECT units_allotted, units_released FROM service_allotments WHERE id = ?");
            $stmt->execute([$id]);
            $allot = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$allot) throw new RuntimeException('Allotment not found.');

            $allotted = (int)$allot['units_allotted'];
            $status = ($sold >= $allotted) ? 'closed' : 'open';

            $pdo->prepare("UPDATE service_allotments SET units_sold = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$sold, $status, $id]);

            tsFlash('success', "Updated consumed units to $sold.");
            tsRedirect('allotments.php#allotments');
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        tsFlash('error', $e->getMessage());
        tsRedirect('allotments.php');
    }
}

// ============================================================================
// Data Queries
// ============================================================================
$today = date('Y-m-d');
$critCutoffDate = date('Y-m-d', strtotime('+5 days'));

// 1. Contracts
$contracts = $pdo->query("
    SELECT c.*, s.company_name as supplier_name,
           (SELECT COUNT(*) FROM service_rate_tiers WHERE contract_id = c.id) as tier_count,
           (SELECT COALESCE(SUM(units_allotted), 0) FROM service_allotments WHERE contract_id = c.id) as total_allotted,
           (SELECT COALESCE(SUM(units_sold), 0) FROM service_allotments WHERE contract_id = c.id) as total_sold,
           (SELECT COALESCE(SUM(units_released), 0) FROM service_allotments WHERE contract_id = c.id) as total_released
    FROM service_contracts c
    LEFT JOIN suppliers s ON c.supplier_id = s.id
    WHERE c.is_archived = 0
    ORDER BY c.status = 'active' DESC, c.end_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$contractMap = [];
foreach ($contracts as $c) {
    $contractMap[$c['id']] = $c['contract_code'] . ' - ' . $c['service_name'];
}

// 2. Rate Tiers
$rateTiers = $pdo->query("
    SELECT t.*, c.contract_code, c.service_name, c.service_type, c.unit_label
    FROM service_rate_tiers t
    JOIN service_contracts c ON t.contract_id = c.id
    ORDER BY c.contract_code, t.rate ASC
")->fetchAll(PDO::FETCH_ASSOC);

// 3. Allotment Schedules with Cut-off Analysis
$filterContract = tsInt($_GET['contract_id'] ?? 0);
$filterStatus = tsStr($_GET['status'] ?? '');
$filterType = tsStr($_GET['service_type'] ?? '');

$where = ["c.is_archived = 0"];
$params = [];
if ($filterContract > 0) {
    $where[] = "a.contract_id = ?";
    $params[] = $filterContract;
}
if ($filterStatus !== '') {
    $where[] = "a.status = ?";
    $params[] = $filterStatus;
}
if ($filterType !== '') {
    $where[] = "c.service_type = ?";
    $params[] = $filterType;
}
$whereSql = implode(' AND ', $where);

$allotmentsStmt = $pdo->prepare("
    SELECT a.*, c.contract_code, c.service_name, c.service_type, c.unit_label, c.release_days, c.penalty_pct, s.company_name as supplier_name
    FROM service_allotments a
    JOIN service_contracts c ON a.contract_id = c.id
    LEFT JOIN suppliers s ON c.supplier_id = s.id
    WHERE $whereSql
    ORDER BY a.service_date ASC
    LIMIT 300
");
$allotmentsStmt->execute($params);
$allotments = $allotmentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate KPIs
$kpiActiveContracts = 0;
$kpiTotalAllotted = 0;
$kpiTotalSold = 0;
$kpiCriticalCutoffs = 0;
$kpiPenaltyExposure = 0.0;

foreach ($contracts as $c) {
    if ($c['status'] === 'active') $kpiActiveContracts++;
    $kpiTotalAllotted += (int)$c['total_allotted'];
    $kpiTotalSold += (int)$c['total_sold'];
}

foreach ($allotments as $al) {
    $unsold = max(0, (int)$al['units_allotted'] - (int)$al['units_sold'] - (int)$al['units_released']);
    $rDate = $al['release_date'] ?: date('Y-m-d', strtotime("{$al['service_date']} - {$al['release_days']} days"));
    $daysLeft = (int)floor((strtotime($rDate) - strtotime($today)) / 86400);

    if ($al['status'] === 'open' && $unsold > 0) {
        if ($daysLeft <= 5) {
            $kpiCriticalCutoffs++;
        }
        if ($daysLeft < 0) {
            // Already past cut-off without being released: subject to penalty!
            $kpiPenaltyExposure += ($unsold * (float)$al['rate'] * ((float)$al['penalty_pct'] / 100));
        }
    }
}

$utilizationPct = $kpiTotalAllotted > 0 ? round(($kpiTotalSold / $kpiTotalAllotted) * 100, 1) : 0;

// Header Render
tsRenderHeader([
    'title' => 'Service Contracting & Allotments',
    'subtitle' => 'Module 6: Non-physical inventory procurement, room blocks, transport allocations & release deadlines.',
    'theme' => 'blue',
    'icon' => 'fa-calendar-check',
    'hero_title' => 'Service Contracts & Allotment Cut-Offs',
    'hero_text' => 'Manage perishable non-physical capacity commitments (hotel room blocks, ferry seats, divemaster guide retains) with automated release cut-off alerts, attrition monitoring, and penalty avoidance workflows.',
    'tags' => ['Non-Physical Inventory', 'Release Cut-Off Countdown', 'B2B Block Allotments', 'Attrition Risk Engine', 'Tiered Rates'],
    'actions' => '
        <button class="ts-btn secondary" onclick="tsOpenModal(\'modalBulkAllotment\')"><i class="fas fa-calendar-plus"></i> Bulk Allotments</button>
        <button class="ts-btn primary" onclick="tsOpenModal(\'modalContract\')"><i class="fas fa-file-signature"></i> New Contract</button>
    '
]);
?>

<!-- KPI STRIP -->
<div class="ts-kpi-grid">
    <?php
    echo tsKpi('Active Service Contracts', (string)$kpiActiveContracts, 'fa-file-contract', 'blue', count($contracts) . ' registered contracts');
    echo tsKpi('Total Allotted Capacity', number_format($kpiTotalAllotted), 'fa-cubes-stacked', 'cyan', 'Blocked service units');
    echo tsKpi('Consumed / Sold Units', number_format($kpiTotalSold) . " ({$utilizationPct}%)", 'fa-ticket', 'green', number_format($kpiTotalAllotted - $kpiTotalSold) . ' unsold units');
    echo tsKpi('Critical Cut-Off Alerts', (string)$kpiCriticalCutoffs, 'fa-bell', $kpiCriticalCutoffs > 0 ? 'red' : 'green', 'Due in ≤ 5 days or past deadline');
    echo tsKpi('Penalty Risk Exposure', tsMoney($kpiPenaltyExposure), 'fa-triangle-exclamation', $kpiPenaltyExposure > 0 ? 'red' : 'purple', 'Unreleased units past cut-off');
    ?>
</div>

<!-- TABS NAVIGATION -->
<div class="ts-tabs">
    <button class="ts-tab-btn active" data-tab="allotments"><i class="fas fa-calendar-days"></i> Allotment Schedule &amp; Cut-Offs (<?php echo count($allotments); ?>)</button>
    <button class="ts-tab-btn" data-tab="contracts"><i class="fas fa-handshake"></i> Master Service Contracts (<?php echo count($contracts); ?>)</button>
    <button class="ts-tab-btn" data-tab="rate_tiers"><i class="fas fa-tags"></i> Tiered Rate Sheets (<?php echo count($rateTiers); ?>)</button>
    <button class="ts-tab-btn" data-tab="attrition"><i class="fas fa-chart-pie"></i> Commitment &amp; Attrition Analysis</button>
</div>

<!-- ========================================================================= -->
<!-- TAB 1: ALLOTMENTS & CUT-OFF CALENDAR -->
<!-- ========================================================================= -->
<div class="ts-tab-pane active" id="pane-allotments">
    <div class="ts-card">
        <div class="ts-card-header">
            <div>
                <h3 class="ts-card-title"><i class="fas fa-calendar-check" style="color:var(--ts-primary);"></i> Daily Capacity Allotments &amp; Release Deadlines</h3>
                <p class="ts-card-desc">Monitor forward allotments and return unsold rooms or seats to vendors before the cut-off notice deadline to avoid cancellation penalties.</p>
            </div>
            <div style="display:flex; gap:8px;">
                <button class="ts-btn secondary small" onclick="tsOpenModal('modalBulkAllotment')"><i class="fas fa-wand-magic-sparkles"></i> Bulk Populate</button>
                <button class="ts-btn primary small" onclick="tsOpenModal('modalAllotment')"><i class="fas fa-plus"></i> Add Allotment Day</button>
            </div>
        </div>

        <!-- Filter bar -->
        <div class="ts-filters" style="margin-bottom: 16px;">
            <div class="ts-search-bar" style="flex: 1; max-width: 320px;">
                <i class="fas fa-search"></i>
                <input type="text" id="allotmentSearch" placeholder="Search service, supplier, date...">
            </div>
            <select onchange="location.href='allotments.php?contract_id='+this.value+'&status=<?php echo urlencode($filterStatus); ?>&service_type=<?php echo urlencode($filterType); ?>#allotments'">
                <option value="0">All Contracts</option>
                <?php foreach ($contracts as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo $filterContract === (int)$c['id'] ? 'selected' : ''; ?>>
                        <?php echo tsh($c['contract_code'] . ' - ' . $c['service_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select onchange="location.href='allotments.php?contract_id=<?php echo $filterContract; ?>&status='+this.value+'&service_type=<?php echo urlencode($filterType); ?>#allotments'">
                <option value="">All Statuses</option>
                <option value="open" <?php echo $filterStatus === 'open' ? 'selected' : ''; ?>>Open</option>
                <option value="released" <?php echo $filterStatus === 'released' ? 'selected' : ''; ?>>Released Back to Vendor</option>
                <option value="closed" <?php echo $filterStatus === 'closed' ? 'selected' : ''; ?>>Closed / Full</option>
            </select>
            <select onchange="location.href='allotments.php?contract_id=<?php echo $filterContract; ?>&status=<?php echo urlencode($filterStatus); ?>&service_type='+this.value+'#allotments'">
                <option value="">All Service Types</option>
                <option value="hotel_room" <?php echo $filterType === 'hotel_room' ? 'selected' : ''; ?>>Hotel Rooms</option>
                <option value="ferry_pass" <?php echo $filterType === 'ferry_pass' ? 'selected' : ''; ?>>Ferry Passes</option>
                <option value="guide_service" <?php echo $filterType === 'guide_service' ? 'selected' : ''; ?>>Guide Services</option>
                <option value="vehicle_charter" <?php echo $filterType === 'vehicle_charter' ? 'selected' : ''; ?>>Vehicle Charters</option>
            </select>
            <?php if ($filterContract > 0 || $filterStatus !== '' || $filterType !== ''): ?>
                <a href="allotments.php#allotments" class="ts-btn secondary small"><i class="fas fa-times"></i> Clear</a>
            <?php endif; ?>
        </div>

        <div class="ts-table-wrap">
            <table class="ts-table" id="allotmentTable">
                <thead>
                    <tr>
                        <th>Service Date</th>
                        <th>Contract &amp; Supplier</th>
                        <th>Type</th>
                        <th style="text-align:right;">Allotted</th>
                        <th style="text-align:right;">Sold</th>
                        <th style="text-align:right;">Released</th>
                        <th style="text-align:right;">Unsold</th>
                        <th>Release Cut-Off</th>
                        <th>Cut-Off Status</th>
                        <th style="text-align:right;">Unit Rate</th>
                        <th>Status</th>
                        <th style="text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($allotments)): ?>
                    <tr><td colspan="12" style="text-align:center; padding:36px; color:var(--ts-muted);">No allotment schedules found matching filter criteria.</td></tr>
                <?php else: ?>
                    <?php foreach ($allotments as $row):
                        $allotted = (int)$row['units_allotted'];
                        $sold = (int)$row['units_sold'];
                        $released = (int)$row['units_released'];
                        $unsold = max(0, $allotted - $sold - $released);
                        $rDate = $row['release_date'] ?: date('Y-m-d', strtotime("{$row['service_date']} - {$row['release_days']} days"));
                        $daysLeft = (int)floor((strtotime($rDate) - strtotime($today)) / 86400);

                        // Urgency evaluation
                        if ($row['status'] === 'released') {
                            $urgencyBadge = '<span class="ts-badge purple"><i class="fas fa-arrow-rotate-left"></i> Released Back</span>';
                        } elseif ($row['status'] === 'closed' || $sold >= $allotted) {
                            $urgencyBadge = '<span class="ts-badge green"><i class="fas fa-circle-check"></i> 100% Filled</span>';
                        } elseif ($daysLeft < 0) {
                            $urgencyBadge = '<span class="ts-badge red"><i class="fas fa-triangle-exclamation"></i> OVERDUE (' . abs($daysLeft) . 'd past)</span>';
                        } elseif ($daysLeft <= 5) {
                            $urgencyBadge = '<span class="ts-badge amber"><i class="fas fa-clock"></i> CRITICAL (' . $daysLeft . 'd left)</span>';
                        } else {
                            $urgencyBadge = '<span class="ts-badge blue"><i class="fas fa-hourglass-half"></i> ' . $daysLeft . 'd notice</span>';
                        }
                    ?>
                    <tr>
                        <td>
                            <strong style="color:var(--ts-primary); font-size:13.5px;"><?php echo tsFmtDate($row['service_date'], 'D, M d, Y'); ?></strong>
                        </td>
                        <td>
                            <div style="font-weight:600;"><?php echo tsh($row['service_name']); ?></div>
                            <div style="font-size:11.5px; color:var(--ts-muted);"><?php echo tsh($row['contract_code']); ?> &bull; <?php echo tsh($row['supplier_name'] ?: 'Direct Supplier'); ?></div>
                        </td>
                        <td>
                            <?php
                            $typeIcon = match($row['service_type']) {
                                'hotel_room' => 'fa-hotel',
                                'ferry_pass' => 'fa-ship',
                                'guide_service' => 'fa-user-tie',
                                'vehicle_charter' => 'fa-bus',
                                default => 'fa-ticket'
                            };
                            ?>
                            <span class="ts-badge light"><i class="fas <?php echo $typeIcon; ?>"></i> <?php echo tsh(tsLabel($row['service_type'])); ?></span>
                        </td>
                        <td style="text-align:right; font-weight:600;"><?php echo $allotted; ?> <span style="font-size:11px; color:var(--ts-muted);"><?php echo tsh($row['unit_label']); ?></span></td>
                        <td style="text-align:right; font-weight:600; color:var(--ts-success);"><?php echo $sold; ?></td>
                        <td style="text-align:right; font-weight:600; color:var(--ts-purple);"><?php echo $released; ?></td>
                        <td style="text-align:right;">
                            <?php if ($unsold > 0): ?>
                                <span style="font-weight:700; color:<?php echo $daysLeft <= 5 ? 'var(--ts-danger)' : 'var(--ts-text)'; ?>;"><?php echo $unsold; ?></span>
                            <?php else: ?>
                                <span style="color:var(--ts-muted);">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-size:12.5px;"><?php echo tsFmtDate($rDate, 'M d, Y'); ?></div>
                            <div style="font-size:11px; color:var(--ts-muted);"><?php echo (int)$row['release_days']; ?>-day notice cut-off</div>
                        </td>
                        <td><?php echo $urgencyBadge; ?></td>
                        <td style="text-align:right; font-family:'JetBrains Mono', monospace; font-size:12.5px;"><?php echo tsMoney($row['rate']); ?></td>
                        <td>
                            <?php echo tsBadge($row['status'], [
                                'open' => 'blue',
                                'released' => 'purple',
                                'closed' => 'green',
                                'penalty_incurred' => 'red'
                            ]); ?>
                        </td>
                        <td style="text-align:center;">
                            <div style="display:inline-flex; gap:4px;">
                                <?php if ($unsold > 0 && $row['status'] === 'open'): ?>
                                    <form method="POST" action="allotments.php" style="display:inline;" onsubmit="return confirm('Release all <?php echo $unsold; ?> unsold units back to supplier to avoid cancellation penalty?');">
                                        <?php echo tsCsrfField(); ?>
                                        <input type="hidden" name="sub_action" value="quick_release">
                                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" class="ts-btn purple small" title="Release unsold units without penalty" style="padding:4px 8px; font-size:11px;">
                                            <i class="fas fa-arrow-rotate-left"></i> Return <?php echo $unsold; ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <button type="button" class="ts-btn secondary small" style="padding:4px 8px; font-size:11px;" title="Quick Update Sold Units" onclick="quickUpdateSold(<?php echo $row['id']; ?>, <?php echo $sold; ?>, <?php echo $allotted; ?>, '<?php echo tsh($row['service_name']); ?>')">
                                    <i class="fas fa-pencil"></i>
                                </button>
                                <button type="button" class="ts-btn secondary small" style="padding:4px 8px; font-size:11px;" title="Edit Full Allotment" onclick="editAllotment(<?php echo tsPayload($row); ?>)">
                                    <i class="fas fa-sliders"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- TAB 2: MASTER SERVICE CONTRACTS -->
<!-- ========================================================================= -->
<div class="ts-tab-pane" id="pane-contracts">
    <div class="ts-card">
        <div class="ts-card-header">
            <div>
                <h3 class="ts-card-title"><i class="fas fa-file-signature" style="color:var(--ts-primary);"></i> Supplier Service Contracts &amp; Capacity Commitments</h3>
                <p class="ts-card-desc">Contractual terms, notice periods, allowed attrition percentages, and cancellation penalties for transport, lodging, and guide capacity.</p>
            </div>
            <button class="ts-btn primary small" onclick="tsOpenModal('modalContract')"><i class="fas fa-plus"></i> New Contract</button>
        </div>

        <div class="ts-table-wrap">
            <table class="ts-table" id="contractTable">
                <thead>
                    <tr>
                        <th>Contract Code</th>
                        <th>Supplier</th>
                        <th>Service Name &amp; Unit</th>
                        <th>Type</th>
                        <th>Validity Period</th>
                        <th style="text-align:right;">Committed Capacity</th>
                        <th style="text-align:right;">Notice Period</th>
                        <th style="text-align:right;">Attrition Allow.</th>
                        <th style="text-align:right;">Penalty %</th>
                        <th>Status</th>
                        <th style="text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($contracts)): ?>
                    <tr><td colspan="11" style="text-align:center; padding:36px; color:var(--ts-muted);">No service contracts on file.</td></tr>
                <?php else: ?>
                    <?php foreach ($contracts as $c): ?>
                    <tr>
                        <td>
                            <strong style="color:var(--ts-primary); font-family:'JetBrains Mono', monospace; font-size:12.5px;"><?php echo tsh($c['contract_code']); ?></strong>
                        </td>
                        <td>
                            <div style="font-weight:600;"><?php echo tsh($c['supplier_name'] ?: 'Direct Vendor'); ?></div>
                            <div style="font-size:11.5px; color:var(--ts-muted);"><?php echo tsh($c['currency'] ?: 'PHP'); ?> pricing</div>
                        </td>
                        <td>
                            <div style="font-weight:600;"><?php echo tsh($c['service_name']); ?></div>
                            <div style="font-size:11.5px; color:var(--ts-muted);"><?php echo tsh($c['unit_label']); ?></div>
                        </td>
                        <td><span class="ts-badge light"><?php echo tsh(tsLabel($c['service_type'])); ?></span></td>
                        <td>
                            <div style="font-size:12.5px;"><?php echo tsFmtDate($c['start_date'], 'M d, Y'); ?> &rarr; <?php echo tsFmtDate($c['end_date'], 'M d, Y'); ?></div>
                        </td>
                        <td style="text-align:right; font-weight:700;">
                            <?php echo number_format($c['committed_units']); ?>
                            <div style="font-size:11px; color:var(--ts-muted);"><?php echo number_format($c['total_sold']); ?> consumed</div>
                        </td>
                        <td style="text-align:right; font-weight:600;">
                            <?php echo (int)$c['release_days']; ?> days
                        </td>
                        <td style="text-align:right; font-weight:600; color:var(--ts-success);">
                            <?php echo number_format($c['attrition_pct'], 1); ?>%
                        </td>
                        <td style="text-align:right; font-weight:600; color:var(--ts-danger);">
                            <?php echo number_format($c['penalty_pct'], 1); ?>%
                        </td>
                        <td>
                            <?php echo tsBadge($c['status'], [
                                'active' => 'green',
                                'pending' => 'amber',
                                'expired' => 'red',
                                'terminated' => 'red'
                            ]); ?>
                        </td>
                        <td style="text-align:center;">
                            <button type="button" class="ts-btn secondary small" onclick="editContract(<?php echo tsPayload($c); ?>)">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- TAB 3: TIERED RATE SHEETS -->
<!-- ========================================================================= -->
<div class="ts-tab-pane" id="pane-rate_tiers">
    <div class="ts-card">
        <div class="ts-card-header">
            <div>
                <h3 class="ts-card-title"><i class="fas fa-tags" style="color:var(--ts-primary);"></i> Tiered Rate Sheets &amp; Seasonal Pricing</h3>
                <p class="ts-card-desc">Define wholesale negotiated rates based on volume thresholds and tourism seasonality (peak, shoulder, off-peak).</p>
            </div>
            <button class="ts-btn primary small" onclick="tsOpenModal('modalRateTier')"><i class="fas fa-plus"></i> New Rate Tier</button>
        </div>

        <div class="ts-table-wrap">
            <table class="ts-table" id="rateTierTable">
                <thead>
                    <tr>
                        <th>Contract</th>
                        <th>Tier Name</th>
                        <th>Season</th>
                        <th style="text-align:right;">Volume Range</th>
                        <th style="text-align:right;">Contracted Rate</th>
                        <th>Validity Window</th>
                        <th style="text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rateTiers)): ?>
                    <tr><td colspan="7" style="text-align:center; padding:36px; color:var(--ts-muted);">No rate tiers defined yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($rateTiers as $t): ?>
                    <tr>
                        <td>
                            <strong><?php echo tsh($t['contract_code']); ?></strong>
                            <div style="font-size:11.5px; color:var(--ts-muted);"><?php echo tsh($t['service_name']); ?></div>
                        </td>
                        <td><strong style="color:var(--ts-primary);"><?php echo tsh($t['tier_name']); ?></strong></td>
                        <td>
                            <?php echo tsBadge($t['season'], [
                                'peak' => 'red',
                                'shoulder' => 'amber',
                                'off' => 'green',
                                'all' => 'blue'
                            ]); ?>
                        </td>
                        <td style="text-align:right; font-weight:600;">
                            <?php echo (int)$t['min_units']; ?> <?php echo $t['max_units'] ? ('- ' . (int)$t['max_units']) : '+'; ?> <?php echo tsh($t['unit_label']); ?>
                        </td>
                        <td style="text-align:right; font-family:'JetBrains Mono', monospace; font-weight:700; font-size:13px; color:var(--ts-primary);">
                            <?php echo tsMoney($t['rate']); ?>
                        </td>
                        <td>
                            <?php if ($t['valid_from'] || $t['valid_to']): ?>
                                <?php echo tsFmtDate($t['valid_from']); ?> &rarr; <?php echo tsFmtDate($t['valid_to']); ?>
                            <?php else: ?>
                                <span style="color:var(--ts-muted);">Contract Term</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <button type="button" class="ts-btn secondary small" onclick="editRateTier(<?php echo tsPayload($t); ?>)">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- TAB 4: ATTRITION & SUPPLIER COMMITMENT ANALYSIS -->
<!-- ========================================================================= -->
<div class="ts-tab-pane" id="pane-attrition">
    <div class="ts-card">
        <div class="ts-card-header">
            <div>
                <h3 class="ts-card-title"><i class="fas fa-chart-pie" style="color:var(--ts-primary);"></i> Supplier Commitment &amp; Attrition Exposure Engine</h3>
                <p class="ts-card-desc">Real-time financial exposure tracking: monitors whether your tour bookings satisfy minimum contracted volumes or breach attrition allowances.</p>
            </div>
        </div>

        <div class="ts-table-wrap">
            <table class="ts-table">
                <thead>
                    <tr>
                        <th>Contract &amp; Supplier</th>
                        <th style="text-align:right;">Committed Target</th>
                        <th style="text-align:right;">Allotted to Date</th>
                        <th style="text-align:right;">Sold / Consumed</th>
                        <th style="width:180px;">Utilization %</th>
                        <th style="text-align:right;">Allowed Attrition</th>
                        <th style="text-align:right;">Shortfall Exposure</th>
                        <th style="text-align:right;">Est. Penalty Liability</th>
                        <th>Risk Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($contracts as $c):
                    $committed = (int)$c['committed_units'];
                    $sold = (int)$c['total_sold'];
                    $allotted = (int)$c['total_allotted'];
                    $utilPct = $committed > 0 ? round(($sold / $committed) * 100, 1) : 0;
                    $allowableAttritionUnits = round($committed * ((float)$c['attrition_pct'] / 100));
                    $minimumRequired = max(0, $committed - $allowableAttritionUnits);
                    $shortfall = max(0, $minimumRequired - $sold);

                    // Estimate average rate from tiers or allotments
                    $avgRate = 2500.0;
                    $rStmt = $pdo->prepare("SELECT AVG(rate) FROM service_rate_tiers WHERE contract_id = ?");
                    $rStmt->execute([$c['id']]);
                    $tierAvg = $rStmt->fetchColumn();
                    if ($tierAvg > 0) $avgRate = (float)$tierAvg;

                    $liability = $shortfall * $avgRate * ((float)$c['penalty_pct'] / 100);

                    if ($utilPct >= 80) {
                        $riskBadge = '<span class="ts-badge green"><i class="fas fa-circle-check"></i> On Target</span>';
                    } elseif ($utilPct >= 50) {
                        $riskBadge = '<span class="ts-badge amber"><i class="fas fa-triangle-exclamation"></i> Moderate Risk</span>';
                    } else {
                        $riskBadge = '<span class="ts-badge red"><i class="fas fa-circle-xmark"></i> High Penalty Risk</span>';
                    }
                ?>
                <tr>
                    <td>
                        <strong><?php echo tsh($c['service_name']); ?></strong>
                        <div style="font-size:11.5px; color:var(--ts-muted);"><?php echo tsh($c['contract_code']); ?> &bull; <?php echo tsh($c['supplier_name'] ?: 'Direct'); ?></div>
                    </td>
                    <td style="text-align:right; font-weight:700;"><?php echo number_format($committed); ?> <span style="font-size:11px; color:var(--ts-muted);"><?php echo tsh($c['unit_label']); ?></span></td>
                    <td style="text-align:right; font-weight:600;"><?php echo number_format($allotted); ?></td>
                    <td style="text-align:right; font-weight:700; color:var(--ts-success);"><?php echo number_format($sold); ?></td>
                    <td>
                        <div style="display:flex; justify-content:space-between; font-size:11px; margin-bottom:4px;">
                            <span><?php echo $utilPct; ?>%</span>
                            <span style="color:var(--ts-muted);"><?php echo $committed - $sold; ?> remaining</span>
                        </div>
                        <div class="ts-progress-bar">
                            <div class="fill" style="width:<?php echo min(100, $utilPct); ?>%; background:<?php echo $utilPct >= 80 ? 'var(--ts-success)' : ($utilPct >= 50 ? 'var(--ts-warning)' : 'var(--ts-danger)'); ?>;"></div>
                        </div>
                    </td>
                    <td style="text-align:right; color:var(--ts-muted); font-size:12.5px;">
                        <?php echo $allowableAttritionUnits; ?> units (<?php echo (float)$c['attrition_pct']; ?>%)
                    </td>
                    <td style="text-align:right; font-weight:700; color:<?php echo $shortfall > 0 ? 'var(--ts-danger)' : 'var(--ts-success)'; ?>;">
                        <?php echo $shortfall > 0 ? ('-' . number_format($shortfall)) : '0'; ?>
                    </td>
                    <td style="text-align:right; font-family:'JetBrains Mono', monospace; font-weight:700; color:<?php echo $liability > 0 ? 'var(--ts-danger)' : 'var(--ts-text)'; ?>;">
                        <?php echo tsMoney($liability); ?>
                    </td>
                    <td><?php echo $riskBadge; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODALS -->
<!-- ========================================================================= -->

<!-- Modal 1: Contract Edit / Add -->
<div class="ts-modal-overlay" id="modalContract">
    <div class="ts-modal" style="max-width: 650px;">
        <div class="ts-modal-header">
            <h3 class="ts-modal-title" id="modalContractTitle"><i class="fas fa-file-signature"></i> Master Service Contract</h3>
            <button class="ts-modal-close" onclick="tsCloseModal('modalContract')">&times;</button>
        </div>
        <form method="POST" action="allotments.php">
            <div class="ts-modal-body">
                <?php echo tsCsrfField(); ?>
                <input type="hidden" name="sub_action" value="save_contract">
                <input type="hidden" name="id" id="contract_id" value="0">

                <div class="ts-form-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="ts-form-group">
                        <label class="ts-label">Contract Code *</label>
                        <input type="text" name="contract_code" id="contract_code" class="ts-input" required value="<?php echo tsh(tsNextNumber('SC')); ?>">
                    </div>
                    <div class="ts-form-group">
                        <label class="ts-label">Supplier / Partner *</label>
                        <select name="supplier_id" id="contract_supplier_id" class="ts-select">
                            <option value="">— Select Supplier —</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo tsh($s['company_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="ts-form-group">
                    <label class="ts-label">Service Description / Block Name *</label>
                    <input type="text" name="service_name" id="contract_service_name" class="ts-input" placeholder="e.g. Bohol Beach Club Oceanfront Deluxe Block" required>
                </div>

                <div class="ts-form-grid" style="grid-template-columns: 1fr 1fr 1fr;">
                    <div class="ts-form-group">
                        <label class="ts-label">Service Type</label>
                        <select name="service_type" id="contract_service_type" class="ts-select">
                            <option value="hotel_room">Hotel Room Block</option>
                            <option value="ferry_pass">Ferry Fast-Craft Seats</option>
                            <option value="guide_service">Guide Services</option>
                            <option value="vehicle_charter">Vehicle Charter</option>
                            <option value="attraction_entry">Attraction Entry</option>
                        </select>
                    </div>
                    <div class="ts-form-group">
                        <label class="ts-label">Unit Label</label>
                        <input type="text" name="unit_label" id="contract_unit_label" class="ts-input" value="room-night">
                    </div>
                    <div class="ts-form-group">
                        <label class="ts-label">Committed Volume</label>
                        <input type="number" name="committed_units" id="contract_committed_units" class="ts-input" value="30">
                    </div>
                </div>

                <div class="ts-form-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="ts-form-group">
                        <label class="ts-label">Contract Start Date</label>
                        <input type="date" name="start_date" id="contract_start_date" class="ts-input" value="<?php echo date('Y-01-01'); ?>">
                    </div>
                    <div class="ts-form-group">
                        <label class="ts-label">Contract End Date</label>
                        <input type="date" name="end_date" id="contract_end_date" class="ts-input" value="<?php echo date('Y-12-31'); ?>">
                    </div>
                </div>

                <div class="ts-form-grid" style="grid-template-columns: 1fr 1fr 1fr;">
                    <div class="ts-form-group">
                        <label class="ts-label">Release Days (Cut-Off)</label>
                        <input type="number" name="release_days" id="contract_release_days" class="ts-input" value="14" title="Days prior to service date to return unsold units">
                    </div>
                    <div class="ts-form-group">
                        <label class="ts-label">Attrition Allowance %</label>
                        <input type="number" step="0.1" name="attrition_pct" id="contract_attrition_pct" class="ts-input" value="10.0" title="Percentage of unsold units permitted without penalty">
                    </div>
                    <div class="ts-form-group">
                        <label class="ts-label">Penalty Rate %</label>
                        <input type="number" step="0.1" name="penalty_pct" id="contract_penalty_pct" class="ts-input" value="100.0" title="Percentage penalty applied on shortfalls">
                    </div>
                </div>

                <div class="ts-form-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="ts-form-group">
                        <label class="ts-label">Status</label>
                        <select name="status" id="contract_status" class="ts-select">
                            <option value="active">Active</option>
                            <option value="pending">Pending Approval</option>
                            <option value="expired">Expired</option>
                            <option value="terminated">Terminated</option>
                        </select>
                    </div>
                    <div class="ts-form-group">
                        <label class="ts-label">Currency</label>
                        <input type="text" name="currency" id="contract_currency" class="ts-input" value="PHP">
                    </div>
                </div>

                <div class="ts-form-group">
                    <label class="ts-label">Notes &amp; Terms</label>
                    <textarea name="notes" id="contract_notes" class="ts-textarea" rows="2" placeholder="Special terms, block blackout dates, cancellation contact..."></textarea>
                </div>
            </div>
            <div class="ts-modal-footer">
                <button type="button" class="ts-btn secondary" onclick="tsCloseModal('modalContract')">Cancel</button>
                <button type="submit" class="ts-btn primary"><i class="fas fa-save"></i> Save Contract</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Single Allotment Day Add / Edit -->
<div class="ts-modal-overlay" id="modalAllotment">
    <div class="ts-modal" style="max-width: 550px;">
        <div class="ts-modal-header">
            <h3 class="ts-modal-title" id="modalAllotmentTitle"><i class="fas fa-calendar-day"></i> Allotment Schedule Day</h3>
            <button class="ts-modal-close" onclick="tsCloseModal('modalAllotment')">&times;</button>
        </div>
        <form method="POST" action="allotments.php">
            <div class="ts-modal-body">
                <?php echo tsCsrfField(); ?>
                <input type="hidden" name="sub_action" value="save_allotment">
                <input type="hidden" name="id" id="allot_id" value="0">

                <div class="ts-form-group">
                    <label class="ts-label">Contract *</label>
                    <select name="contract_id" id="allot_contract_id" class="ts-select" required onchange="updateAllotmentCutoffDefault()">
                        <option value="">— Select Service Contract —</option>
                        <?php foreach ($contracts as $c): ?>
                            <option value="<?php echo $c['id']; ?>" data-release-days="<?php echo (int)$c['release_days']; ?>">
                                <?php echo tsh($c['contract_code'] . ' - ' . $c['service_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ts-form-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="ts-form-group">
                        <label class="ts-label">Service Date *</label>
                        <input type="date" name="service_date" id="allot_service_date" class="ts-input" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required onchange="updateAllotmentCutoffDefault()">
                    </div>
                    <div class="ts-form-group">
                        <label class="ts-label">Release Cut-Off Date</label>
                        <input type="date" name="release_date" id="allot_release_date" class="ts-input" title="Notice cut-off to return without penalty">
                    </div>
                </div>

                <div class="ts-form-grid" style="grid-template-columns: 1fr 1fr 1fr;">
                    <div class="ts-form-group">
                        <label class="ts-label">Units Allotted *</label>
                        <input type="number" name="units_allotted" id="allot_units_allotted" class="ts-input" value="10" required>
                    </div>
                    <div class="ts-form-group">
                        <label class="ts-label">Units Sold</label>
                        <input type="number" name="units_sold" id="allot_units_sold" class="ts-input" value="0">
                    </div>
                    <div class="ts-form-group">
                        <label class="ts-label">Units Released</label>
                        <input type="number" name="units_released" id="allot_units_released" class="ts-input" value="0">
                    </div>
                </div>

                <div class="ts-form-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="ts-form-group">
                        <label class="ts-label">Unit Rate (₱)</label>
                        <input type="number" step="0.01" name="rate" id="allot_rate" class="ts-input" value="4800.00">
                    </div>
                    <div class="ts-form-group">
                        <label class="ts-label">Status</label>
                        <select name="status" id="allot_status" class="ts-select">
                            <option value="open">Open</option>
                            <option value="released">Released Back</option>
                            <option value="closed">Closed / 100% Sold</option>
                            <option value="penalty_incurred">Penalty Incurred</option>
                        </select>
                    </div>
                </div>

                <div class="ts-form-group">
                    <label class="ts-label">Notes</label>
                    <input type="text" name="notes" id="allot_notes" class="ts-input" placeholder="Optional notes...">
                </div>
            </div>
            <div class="ts-modal-footer">
                <button type="button" class="ts-btn secondary" onclick="tsCloseModal('modalAllotment')">Cancel</button>
                <button type="submit" class="ts-btn primary"><i class="fas fa-save"></i> Save Allotment</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 3: Bulk Allotment Calendar Generator -->
<div class="ts-modal-overlay" id="modalBulkAllotment">
    <div class="ts-modal" style="max-width: 600px;">
        <div class="ts-modal-header">
            <h3 class="ts-modal-title"><i class="fas fa-wand-magic-sparkles"></i> Bulk Allotment Calendar Generator</h3>
            <button class="ts-modal-close" onclick="tsCloseModal('modalBulkAllotment')">&times;</button>
        </div>
        <form method="POST" action="allotments.php">
            <div class="ts-modal-body">
                <?php echo tsCsrfField(); ?>
                <input type="hidden" name="sub_action" value="bulk_generate_allotments">

                <div class="ts-alert info" style="margin-bottom:14px;">
                    <i class="fas fa-info-circle"></i>
                    <div>This tool will generate daily allotment schedule records for the selected date range and auto-derive release cut-off dates from the contract's notice period. Existing dates will be skipped.</div>
                </div>

                <div class="ts-form-group">
                    <label class="ts-label">Service Contract *</label>
                    <select name="contract_id" class="ts-select" required>
                        <option value="">— Select Service Contract —</option>
                        <?php foreach ($contracts as $c): ?>
                            <option value="<?php echo $c['id']; ?>">
                                <?php echo tsh($c['contract_code'] . ' - ' . $c['service_name'] . ' (' . $c['release_days'] . 'd notice)'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ts-form-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="ts-form-group">
                        <label class="ts-label">Start Date *</label>
                        <input type="date" name="start_date" class="ts-input" value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
                    </div>
                    <div class="ts-form-group">
                        <label class="ts-label">End Date *</label>
                        <input type="date" name="end_date" class="ts-input" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                    </div>
                </div>

                <div class="ts-form-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="ts-form-group">
                        <label class="ts-label">Daily Allotted Units *</label>
                        <input type="number" name="daily_units" class="ts-input" value="15" required>
                    </div>
                    <div class="ts-form-group">
                        <label class="ts-label">Contracted Unit Rate (₱)</label>
                        <input type="number" step="0.01" name="rate" class="ts-input" value="4800.00">
                    </div>
                </div>

                <div class="ts-form-group">
                    <label class="ts-label">Include Days of Week</label>
                    <div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:6px;">
                        <?php
                        $days = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
                        foreach ($days as $num => $dayName):
                        ?>
                        <label style="display:inline-flex; align-items:center; gap:5px; font-size:12.5px; cursor:pointer;">
                            <input type="checkbox" name="days[]" value="<?php echo $num; ?>" checked> <?php echo $dayName; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="ts-modal-footer">
                <button type="button" class="ts-btn secondary" onclick="tsCloseModal('modalBulkAllotment')">Cancel</button>
                <button type="submit" class="ts-btn primary"><i class="fas fa-play"></i> Generate Calendar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 4: Rate Tier Add / Edit -->
<div class="ts-modal-overlay" id="modalRateTier">
    <div class="ts-modal" style="max-width: 500px;">
        <div class="ts-modal-header">
            <h3 class="ts-modal-title" id="modalRateTierTitle"><i class="fas fa-tag"></i> Tiered Rate Sheet</h3>
            <button class="ts-modal-close" onclick="tsCloseModal('modalRateTier')">&times;</button>
        </div>
        <form method="POST" action="allotments.php">
            <div class="ts-modal-body">
                <?php echo tsCsrfField(); ?>
                <input type="hidden" name="sub_action" value="save_rate_tier">
                <input type="hidden" name="id" id="tier_id" value="0">

                <div class="ts-form-group">
                    <label class="ts-label">Contract *</label>
                    <select name="contract_id" id="tier_contract_id" class="ts-select" required>
                        <option value="">— Select Contract —</option>
                        <?php foreach ($contracts as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo tsh($c['contract_code'] . ' - ' . $c['service_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ts-form-group">
                    <label class="ts-label">Tier Name *</label>
                    <input type="text" name="tier_name" id="tier_name" class="ts-input" placeholder="e.g. Peak Season Allotment Rate" required>
                </div>

                <div class="ts-form-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="ts-form-group">
                        <label class="ts-label">Season</label>
                        <select name="season" id="tier_season" class="ts-select">
                            <option value="all">All Seasons</option>
                            <option value="peak">Peak Season</option>
                            <option value="shoulder">Shoulder Season</option>
                            <option value="off">Off-Peak Season</option>
                        </select>
                    </div>
                    <div class="ts-form-group">
                        <label class="ts-label">Unit Rate (₱) *</label>
                        <input type="number" step="0.01" name="rate" id="tier_rate" class="ts-input" placeholder="4800.00" required>
                    </div>
                </div>

                <div class="ts-form-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="ts-form-group">
                        <label class="ts-label">Min Volume</label>
                        <input type="number" name="min_units" id="tier_min_units" class="ts-input" value="1">
                    </div>
                    <div class="ts-form-group">
                        <label class="ts-label">Max Volume (blank = unlimited)</label>
                        <input type="number" name="max_units" id="tier_max_units" class="ts-input" placeholder="e.g. 50">
                    </div>
                </div>
            </div>
            <div class="ts-modal-footer">
                <button type="button" class="ts-btn secondary" onclick="tsCloseModal('modalRateTier')">Cancel</button>
                <button type="submit" class="ts-btn primary"><i class="fas fa-save"></i> Save Rate</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 5: Quick Update Sold Units -->
<div class="ts-modal-overlay" id="modalQuickSold">
    <div class="ts-modal" style="max-width: 420px;">
        <div class="ts-modal-header">
            <h3 class="ts-modal-title"><i class="fas fa-ticket"></i> Update Sold Units</h3>
            <button class="ts-modal-close" onclick="tsCloseModal('modalQuickSold')">&times;</button>
        </div>
        <form method="POST" action="allotments.php">
            <div class="ts-modal-body">
                <?php echo tsCsrfField(); ?>
                <input type="hidden" name="sub_action" value="quick_update_sold">
                <input type="hidden" name="id" id="quick_sold_id" value="0">

                <p id="quick_sold_service_label" style="font-weight:600; color:var(--ts-primary); margin-bottom:12px;"></p>

                <div class="ts-form-group">
                    <label class="ts-label">Units Sold / Consumed *</label>
                    <input type="number" name="units_sold" id="quick_sold_qty" class="ts-input" min="0" required>
                    <small style="color:var(--ts-muted);" id="quick_sold_max_hint"></small>
                </div>
            </div>
            <div class="ts-modal-footer">
                <button type="button" class="ts-btn secondary" onclick="tsCloseModal('modalQuickSold')">Cancel</button>
                <button type="submit" class="ts-btn primary"><i class="fas fa-check"></i> Update</button>
            </div>
        </form>
    </div>
</div>

<?php
$extraScript = <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', () => {
    tsSetupSearch('allotmentSearch', 'allotmentTable');
});

function editContract(data) {
    document.getElementById('modalContractTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Service Contract';
    document.getElementById('contract_id').value = data.id || 0;
    document.getElementById('contract_code').value = data.contract_code || '';
    document.getElementById('contract_supplier_id').value = data.supplier_id || '';
    document.getElementById('contract_service_name').value = data.service_name || '';
    document.getElementById('contract_service_type').value = data.service_type || 'hotel_room';
    document.getElementById('contract_unit_label').value = data.unit_label || 'units';
    document.getElementById('contract_committed_units').value = data.committed_units || 0;
    document.getElementById('contract_start_date').value = (data.start_date || '').substring(0, 10);
    document.getElementById('contract_end_date').value = (data.end_date || '').substring(0, 10);
    document.getElementById('contract_release_days').value = data.release_days || 14;
    document.getElementById('contract_attrition_pct').value = data.attrition_pct || 10.0;
    document.getElementById('contract_penalty_pct').value = data.penalty_pct || 100.0;
    document.getElementById('contract_currency').value = data.currency || 'PHP';
    document.getElementById('contract_status').value = data.status || 'active';
    document.getElementById('contract_notes').value = data.notes || '';
    tsOpenModal('modalContract');
}

function editAllotment(data) {
    document.getElementById('modalAllotmentTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Allotment Day';
    document.getElementById('allot_id').value = data.id || 0;
    document.getElementById('allot_contract_id').value = data.contract_id || '';
    document.getElementById('allot_service_date').value = (data.service_date || '').substring(0, 10);
    document.getElementById('allot_release_date').value = (data.release_date || '').substring(0, 10);
    document.getElementById('allot_units_allotted').value = data.units_allotted || 0;
    document.getElementById('allot_units_sold').value = data.units_sold || 0;
    document.getElementById('allot_units_released').value = data.units_released || 0;
    document.getElementById('allot_rate').value = data.rate || 0;
    document.getElementById('allot_status').value = data.status || 'open';
    document.getElementById('allot_notes').value = data.notes || '';
    tsOpenModal('modalAllotment');
}

function editRateTier(data) {
    document.getElementById('modalRateTierTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Rate Tier';
    document.getElementById('tier_id').value = data.id || 0;
    document.getElementById('tier_contract_id').value = data.contract_id || '';
    document.getElementById('tier_name').value = data.tier_name || '';
    document.getElementById('tier_season').value = data.season || 'all';
    document.getElementById('tier_rate').value = data.rate || 0;
    document.getElementById('tier_min_units').value = data.min_units || 1;
    document.getElementById('tier_max_units').value = data.max_units || '';
    tsOpenModal('modalRateTier');
}

function quickUpdateSold(id, sold, allotted, serviceName) {
    document.getElementById('quick_sold_id').value = id;
    document.getElementById('quick_sold_qty').value = sold;
    document.getElementById('quick_sold_service_label').textContent = serviceName + ' (Allotted: ' + allotted + ')';
    document.getElementById('quick_sold_max_hint').textContent = 'Currently ' + sold + ' / ' + allotted + ' sold';
    tsOpenModal('modalQuickSold');
}

function updateAllotmentCutoffDefault() {
    const sel = document.getElementById('allot_contract_id');
    const opt = sel.options[sel.selectedIndex];
    const sDate = document.getElementById('allot_service_date').value;
    if (!opt || !sDate) return;
    const relDays = parseInt(opt.getAttribute('data-release-days') || '14', 10);
    const d = new Date(sDate);
    d.setDate(d.getDate() - relDays);
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    document.getElementById('allot_release_date').value = `${yyyy}-${mm}-${dd}`;
}
</script>
JS;

tsRenderFooter($extraScript);
