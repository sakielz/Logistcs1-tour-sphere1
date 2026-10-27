<?php
/**
 * admin/demand-forecast.php
 * Module 2: Demand Forecasting & Seasonal Stock Planning
 *
 * Core Functions:
 *  - Ingests forward-booking curves from Customer Booking to forecast consumable requirements
 *    (water bottles, dry bags, safety gear, fuel reserves).
 *  - Computes dynamic safety stock levels based on seasonal lead-time multipliers & pickup factors.
 *  - Identifies stockout risks well ahead of peak departures and suggests replenishment quantities.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tour_ops.php';

$pdo = tourOpsBoot('demand-forecast.php');
$products = tsProducts($pdo);
$productMap = tsProductMap($products);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tsRequireCsrf('demand-forecast.php');
    $subAction = (string)($_POST['sub_action'] ?? '');

    try {
        if ($subAction === 'save_consumable') {
            $id = tsInt($_POST['id'] ?? 0);
            $productId = tsInt($_POST['product_id'] ?? 0) ?: null;
            $itemName = tsStr($_POST['item_name'] ?? '', 200);
            $unit = tsStr($_POST['unit'] ?? 'pcs', 30);
            $usagePerPax = tsNum($_POST['usage_per_pax'] ?? 1.0);
            $tourType = tsStr($_POST['tour_type'] ?? 'ALL', 100);
            $leadTime = tsInt($_POST['lead_time_days'] ?? 7, 1);
            $serviceLevel = tsNum($_POST['service_level'] ?? 95.0, 50.0);
            $manualStock = tsInt($_POST['manual_on_hand'] ?? 0);

            if ($itemName === '') throw new RuntimeException('Consumable name is required.');

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE forecast_consumables SET product_id = ?, item_name = ?, unit = ?, usage_per_pax = ?, tour_type = ?, lead_time_days = ?, service_level = ?, manual_on_hand = ? WHERE id = ?");
                $stmt->execute([$productId, $itemName, $unit, $usagePerPax, $tourType, $leadTime, $serviceLevel, $manualStock, $id]);
                tsFlash('success', "Consumable profile '$itemName' updated.");
            } else {
                $stmt = $pdo->prepare("INSERT INTO forecast_consumables (product_id, item_name, unit, usage_per_pax, tour_type, lead_time_days, service_level, manual_on_hand) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$productId, $itemName, $unit, $usagePerPax, $tourType, $leadTime, $serviceLevel, $manualStock]);
                tsFlash('success', "Consumable profile '$itemName' created.");
            }
            tsRedirect('demand-forecast.php#consumables');
        }

        if ($subAction === 'add_booking_entry') {
            $depDate = tsDate($_POST['departure_date'] ?? '');
            $tourType = tsStr($_POST['tour_type'] ?? 'Island Hopping', 100);
            $bookedPax = tsInt($_POST['booked_pax'] ?? 0);
            $expectedPax = tsInt($_POST['expected_pax'] ?? $bookedPax);
            $ref = tsStr($_POST['external_ref'] ?? '', 120);

            if (!$depDate) throw new RuntimeException('Departure date is required.');

            $stmt = $pdo->prepare("INSERT INTO forecast_booking_curve (departure_date, tour_type, booked_pax, expected_pax, source, external_ref) VALUES (?, ?, ?, ?, 'Manual Entry', ?)");
            $stmt->execute([$depDate, $tourType, $bookedPax, $expectedPax, $ref]);
            tsFlash('success', "Added forward booking entry for $depDate ($expectedPax projected pax).");
            tsRedirect('demand-forecast.php#curve');
        }

        if ($subAction === 'update_seasonality') {
            $months = $_POST['months'] ?? [];
            $stmt = $pdo->prepare("UPDATE forecast_seasons SET season = ?, pickup_factor = ?, lead_time_factor = ? WHERE month_no = ?");
            $pdo->beginTransaction();
            foreach ($months as $m => $cfg) {
                $monthNo = (int)$m;
                $season = tsPick($cfg['season'] ?? '', ['peak', 'shoulder', 'off'], 'shoulder');
                $pickup = tsNum($cfg['pickup_factor'] ?? 1.0, 0.5);
                $lead = tsNum($cfg['lead_time_factor'] ?? 1.0, 0.5);
                $stmt->execute([$season, $pickup, $lead, $monthNo]);
            }
            $pdo->commit();
            tsFlash('success', 'Tourism seasonality profile and volatility factors saved.');
            tsRedirect('demand-forecast.php#seasonality');
        }

        if ($subAction === 'regenerate_api_key') {
            $newKey = bin2hex(random_bytes(20));
            tsSetSetting($pdo, 'booking_ingest_api_key', $newKey, 'integration', 'API key for Customer Booking forward-curve ingestion');
            logAudit(tsUserId(), 'regenerate_booking_key', 'forecast', 'Rotated Customer Booking Ingestion API Key');
            tsFlash('success', 'Generated fresh API Key for Customer Booking system integration.');
            tsRedirect('demand-forecast.php#api');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        tsFlash('error', $e->getMessage());
        tsRedirect('demand-forecast.php');
    }
}

// ============================================================================
// Computational Engine: Dynamic Forecasting & Safety Stock
// ============================================================================
$currentMonth = (int)date('n');
$seasonInfo = $pdo->query("SELECT * FROM forecast_seasons ORDER BY month_no ASC")->fetchAll(PDO::FETCH_ASSOC);
$seasonMap = [];
foreach ($seasonInfo as $s) $seasonMap[(int)$s['month_no']] = $s;

$currentSeason = $seasonMap[$currentMonth] ?? ['season'=>'peak', 'pickup_factor'=>1.3, 'lead_time_factor'=>1.4];

// Horizon: Next 30 days forward bookings
$bookingCurve = $pdo->query("
    SELECT departure_date, tour_type, booked_pax, expected_pax, source, external_ref
    FROM forecast_booking_curve
    WHERE departure_date >= CURRENT_DATE
    ORDER BY departure_date ASC, tour_type ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Sum total expected pax by tour type across next 30 days
$paxByTour = ['ALL' => 0];
$totalPaxNext30 = 0;
foreach ($bookingCurve as $b) {
    $exp = (int)$b['expected_pax'];
    $t = $b['tour_type'];
    $paxByTour[$t] = ($paxByTour[$t] ?? 0) + $exp;
    $paxByTour['ALL'] += $exp;
    $totalPaxNext30 += $exp;
}

// Fetch consumables profile
$consumables = $pdo->query("
    SELECT c.*, p.current_stock AS catalog_stock, p.sku, p.unit_price
    FROM forecast_consumables c
    LEFT JOIN products p ON p.id = c.product_id
    WHERE c.is_archived = 0
    ORDER BY c.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Compute forecasted demand and dynamic safety stock for each consumable
$forecastCalculations = [];
$atRiskCount = 0;
$totalReplenishCost = 0.0;

foreach ($consumables as $item) {
    $applicablePax = $item['tour_type'] === 'ALL' ? $totalPaxNext30 : ($paxByTour[$item['tour_type']] ?? 0);
    $rawDemand = $applicablePax * (float)$item['usage_per_pax'];

    // Dynamic seasonal adjustments
    $pickupFactor = (float)($currentSeason['pickup_factor'] ?? 1.2);
    $leadFactor = (float)($currentSeason['lead_time_factor'] ?? 1.3);

    // Dynamic safety stock: Z * sqrt(LeadTime * LeadFactor) * DailyDemandVariation
    $baseLeadDays = (int)$item['lead_time_days'];
    $effectiveLeadDays = (int)ceil($baseLeadDays * $leadFactor);

    // Z-factor for service level
    $sl = (float)$item['service_level'];
    $z = $sl >= 99 ? 2.33 : ($sl >= 98 ? 2.05 : ($sl >= 95 ? 1.65 : 1.28));

    $avgDailyUsage = ($applicablePax > 0) ? ($rawDemand / 30) : 10;
    $dynamicSafetyStock = (int)ceil($z * sqrt(max(1, $effectiveLeadDays)) * ($avgDailyUsage * 0.45));

    $totalRequired30Days = (int)ceil($rawDemand * $pickupFactor);

    // Available inventory (linked product current_stock or manual fallback)
    $onHand = $item['product_id'] ? (int)$item['catalog_stock'] : (int)$item['manual_on_hand'];

    // Deficit / Reorder Recommendation
    $recommendedReorder = max(0, ($totalRequired30Days + $dynamicSafetyStock) - $onHand);
    $unitPrice = (float)($item['unit_price'] ?: 25.0);
    $estimatedCost = $recommendedReorder * $unitPrice;

    $isAtRisk = $onHand < ($totalRequired30Days + $dynamicSafetyStock);
    if ($isAtRisk) $atRiskCount++;
    $totalReplenishCost += $estimatedCost;

    $forecastCalculations[] = [
        'item' => $item,
        'applicable_pax' => $applicablePax,
        'projected_demand' => $totalRequired30Days,
        'effective_lead_days' => $effectiveLeadDays,
        'dynamic_safety_stock' => $dynamicSafetyStock,
        'on_hand' => $onHand,
        'recommended_reorder' => $recommendedReorder,
        'estimated_cost' => $estimatedCost,
        'is_at_risk' => $isAtRisk,
    ];
}

$apiKey = tsGetSetting($pdo, 'booking_ingest_api_key') ?: 'UNSET';

// Render Header
tsRenderHeader([
    'title' => 'Demand Forecasting & Seasonal Stock Planning',
    'subtitle' => 'Customer Booking forward-curve ingestion, peak/off-peak volatility modeling & dynamic safety stock recommendations.',
    'theme' => 'ts-theme-forecast',
    'icon' => 'fa-chart-line',
    'hero_title' => 'Tourism Volatility & Consumables Forecasting',
    'hero_text' => 'Static reorder points cause catastrophic stockouts during high season and costly carrying costs during low season. Tour-Sphere models forward booking curves to prescribe dynamic safety stock levels.',
    'tags' => ['Dynamic Safety Stock', 'Forward Curve Ingestion', 'Seasonal Lead Multipliers', 'Automated Reorder Prescriptions'],
    'chart' => true,
    'actions' => '
        <button class="ts-btn sm" data-ts-open="modalAddBooking"><i class="fas fa-ticket"></i> Log Forward Bookings</button>
        <button class="ts-btn green sm" data-ts-open="modalAddConsumable"><i class="fas fa-plus"></i> Add Consumable Profile</button>
    ',
]);
?>

<!-- KPIs -->
<div class="ts-kpis">
    <?php echo tsKpi('Active Season Status', strtoupper($currentSeason['season']) . ' SEASON', 'fa-sun', $currentSeason['season'] === 'peak' ? 'amber' : 'green', "Month {$currentMonth} • Lead Time +".round(($currentSeason['lead_time_factor']-1)*100)."%"); ?>
    <?php echo tsKpi('Forward Passenger Load', number_format($totalPaxNext30) . ' Pax', 'fa-users', 'blue', 'Projected across next 30 days'); ?>
    <?php echo tsKpi('Consumables At Risk', (string)$atRiskCount, 'fa-triangle-exclamation', $atRiskCount > 0 ? 'red' : 'green', $atRiskCount > 0 ? 'Stockouts projected in peak curve' : 'All buffer levels sufficient'); ?>
    <?php echo tsKpi('Prescribed Replenishment', tsMoney($totalReplenishCost), 'fa-cart-shopping', 'violet', 'Estimated budget for safety stock gap'); ?>
</div>

<!-- Tabs -->
<div class="ts-tabs" data-ts-tab-group="forecast">
    <button class="ts-tab" data-tab="forecast"><i class="fas fa-calculator"></i> Dynamic Stock Requirements <span class="ts-count"><?php echo count($forecastCalculations); ?></span></button>
    <button class="ts-tab" data-tab="curve"><i class="fas fa-chart-area"></i> Forward Booking Curve <span class="ts-count"><?php echo count($bookingCurve); ?></span></button>
    <button class="ts-tab" data-tab="consumables"><i class="fas fa-boxes-packing"></i> Consumable Formulas <span class="ts-count"><?php echo count($consumables); ?></span></button>
    <button class="ts-tab" data-tab="seasonality"><i class="fas fa-calendar-week"></i> Seasonality &amp; Volatility Profiles</button>
    <button class="ts-tab" data-tab="api"><i class="fas fa-plug"></i> Booking System Ingest API</button>
</div>

<!-- ======================================================================
     PANEL 1: Dynamic Stock Requirements
     ====================================================================== -->
<div class="ts-panel" data-panel-group="forecast" data-panel="forecast">
    <div class="ts-card">
        <div class="ts-card-header">
            <h3><i class="fas fa-chart-line"></i> Dynamic Safety Stock &amp; 30-Day Replenishment Prescriptions</h3>
            <div class="ts-card-tools">
                <span class="ts-badge blue"><i class="fas fa-sliders"></i> Adjusted for <?php echo strtoupper($currentSeason['season']); ?> Volatility</span>
            </div>
        </div>

        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border);">
            <div class="ts-chart-box">
                <canvas id="forecastChart"></canvas>
            </div>
        </div>

        <div class="ts-table-wrap">
            <table class="ts-table">
                <thead>
                    <tr>
                        <th>Consumable Item</th>
                        <th>Tour Type</th>
                        <th class="ts-num">On-Hand Stock</th>
                        <th class="ts-num">30-Day Demand</th>
                        <th class="ts-num">Dynamic Safety Buffer</th>
                        <th class="ts-num">Effective Lead Time</th>
                        <th>Coverage Health</th>
                        <th class="ts-num">Recommended Order</th>
                        <th class="ts-num">Est. Cost</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($forecastCalculations)): ?>
                    <tr><td colspan="10" class="ts-empty">No consumables configured. Add formulas to run forecast.</td></tr>
                    <?php else: foreach ($forecastCalculations as $fc):
                        $item = $fc['item'];
                        $onHand = $fc['on_hand'];
                        $target = $fc['projected_demand'] + $fc['dynamic_safety_stock'];
                        $pct = $target > 0 ? min(100, round(($onHand / $target) * 100)) : 100;
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo tsh($item['item_name']); ?></strong>
                            <?php if ($item['sku']): ?>
                            <div class="ts-muted">SKU: <?php echo tsh($item['sku']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="ts-badge ghost"><?php echo tsh($item['tour_type']); ?></span></td>
                        <td class="ts-num"><strong><?php echo number_format($onHand); ?></strong> <span class="ts-muted"><?php echo tsh($item['unit']); ?></span></td>
                        <td class="ts-num"><?php echo number_format($fc['projected_demand']); ?></td>
                        <td class="ts-num"><strong style="color:var(--primary);"><?php echo number_format($fc['dynamic_safety_stock']); ?></strong></td>
                        <td class="ts-num"><?php echo $fc['effective_lead_days']; ?> days <span class="ts-muted">(base <?php echo $item['lead_time_days']; ?>)</span></td>
                        <td>
                            <div class="ts-progress <?php echo $pct < 50 ? 'red' : ($pct < 90 ? 'amber' : 'green'); ?>" style="width:110px;">
                                <span data-w="<?php echo $pct; ?>"></span>
                            </div>
                            <div style="font-size:11px; margin-top:3px;"><?php echo $pct; ?>% buffered</div>
                        </td>
                        <td class="ts-num">
                            <?php if ($fc['recommended_reorder'] > 0): ?>
                            <span class="ts-badge red"><strong>+<?php echo number_format($fc['recommended_reorder']); ?></strong></span>
                            <?php else: ?>
                            <span class="ts-badge green"><i class="fas fa-check"></i> Sufficient</span>
                            <?php endif; ?>
                        </td>
                        <td class="ts-num"><?php echo tsMoney($fc['estimated_cost']); ?></td>
                        <td style="text-align:right;">
                            <?php if ($fc['recommended_reorder'] > 0): ?>
                            <a href="requisitions.php?action=create" class="ts-btn sm green" title="Create Purchase Requisition">
                                <i class="fas fa-file-invoice"></i> Requisition
                            </a>
                            <?php else: ?>
                            <span class="ts-muted">OK</span>
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
     PANEL 2: Forward Booking Curve
     ====================================================================== -->
<div class="ts-panel" data-panel-group="forecast" data-panel="curve">
    <div class="ts-card">
        <div class="ts-card-header">
            <h3><i class="fas fa-chart-area"></i> Ingested Forward Booking Curves</h3>
            <div class="ts-card-tools">
                <button class="ts-btn sm green" data-ts-open="modalAddBooking"><i class="fas fa-plus"></i> Add Entry</button>
            </div>
        </div>
        <div class="ts-table-wrap">
            <table class="ts-table">
                <thead>
                    <tr>
                        <th>Departure Date</th>
                        <th>Tour Circuit / Package</th>
                        <th class="ts-num">Firm Booked Pax</th>
                        <th class="ts-num">Projected Expected Pax</th>
                        <th>Source</th>
                        <th>External Booking Ref</th>
                        <th>Days to Departure</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bookingCurve)): ?>
                    <tr><td colspan="7" class="ts-empty">No forward bookings recorded.</td></tr>
                    <?php else: foreach ($bookingCurve as $bc):
                        $days = tsDaysUntil($bc['departure_date']);
                    ?>
                    <tr>
                        <td><strong><?php echo tsFmtDate($bc['departure_date']); ?></strong></td>
                        <td><span class="ts-badge blue"><?php echo tsh($bc['tour_type']); ?></span></td>
                        <td class="ts-num"><strong><?php echo number_format($bc['booked_pax']); ?></strong></td>
                        <td class="ts-num" style="color:var(--primary); font-weight:600;"><?php echo number_format($bc['expected_pax']); ?></td>
                        <td><span class="ts-chip"><?php echo tsh($bc['source']); ?></span></td>
                        <td><span class="ts-code"><?php echo tsh($bc['external_ref'] ?: '—'); ?></span></td>
                        <td>
                            <?php if ($days !== null): ?>
                            <span class="ts-badge <?php echo $days <= 7 ? 'amber' : 'ghost'; ?>">
                                in <?php echo $days; ?> days
                            </span>
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
     PANEL 3: Consumables Formulas
     ====================================================================== -->
<div class="ts-panel" data-panel-group="forecast" data-panel="consumables">
    <div class="ts-card">
        <div class="ts-card-header">
            <h3><i class="fas fa-boxes-packing"></i> Per-Passenger Consumables Consumption Formulas</h3>
            <div class="ts-card-tools">
                <button class="ts-btn sm green" data-ts-open="modalAddConsumable"><i class="fas fa-plus"></i> Add Formula</button>
            </div>
        </div>
        <div class="ts-table-wrap">
            <table class="ts-table">
                <thead>
                    <tr>
                        <th>Consumable Item</th>
                        <th>Unit</th>
                        <th>Usage Ratio per Passenger</th>
                        <th>Applies To Tour</th>
                        <th>Base Supplier Lead Time</th>
                        <th>Target Service Level</th>
                        <th>Linked Catalog Item</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($consumables as $c): ?>
                    <tr>
                        <td><strong><?php echo tsh($c['item_name']); ?></strong></td>
                        <td><span class="ts-chip"><?php echo tsh($c['unit']); ?></span></td>
                        <td><strong style="color:var(--primary);"><?php echo (float)$c['usage_per_pax']; ?></strong> <?php echo tsh($c['unit']); ?> / pax</td>
                        <td><span class="ts-badge ghost"><?php echo tsh($c['tour_type']); ?></span></td>
                        <td><?php echo (int)$c['lead_time_days']; ?> days</td>
                        <td><strong><?php echo (float)$c['service_level']; ?>%</strong></td>
                        <td><?php echo $c['sku'] ? tsh("{$c['sku']} (Stock: {$c['catalog_stock']})") : '<span class="ts-muted">Unlinked (Manual stock: ' . $c['manual_on_hand'] . ')</span>'; ?></td>
                        <td style="text-align:right;">
                            <button class="ts-icon-btn" title="Edit Formula" data-ts-open="modalAddConsumable" data-ts-payload="<?php echo tsPayload(array_merge($c, ['__title' => 'Edit Consumable Profile'])); ?>">
                                <i class="fas fa-pen-to-square"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ======================================================================
     PANEL 4: Seasonality Profile
     ====================================================================== -->
<div class="ts-panel" data-panel-group="forecast" data-panel="seasonality">
    <div class="ts-card">
        <div class="ts-card-header">
            <h3><i class="fas fa-calendar-week"></i> 12-Month Tourism Seasonality &amp; Dynamic Factors</h3>
        </div>
        <form method="POST" action="demand-forecast.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="update_seasonality">
            <div class="ts-table-wrap">
                <table class="ts-table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Tourism Season</th>
                            <th>Forward Pickup Multiplier</th>
                            <th>Lead Time Volatility Multiplier</th>
                            <th>Current Month</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $monthsNames = [1=>'January', 2=>'February', 3=>'March', 4=>'April', 5=>'May', 6=>'June', 7=>'July', 8=>'August', 9=>'September', 10=>'October', 11=>'November', 12=>'December'];
                        foreach ($monthsNames as $mNum => $mName):
                            $cfg = $seasonMap[$mNum] ?? ['season'=>'shoulder', 'pickup_factor'=>1.0, 'lead_time_factor'=>1.0];
                            $isCurrent = $mNum === $currentMonth;
                        ?>
                        <tr <?php if ($isCurrent) echo 'style="background:rgba(47,128,237,.06);"'; ?>>
                            <td><strong><?php echo $mName; ?></strong> (Month <?php echo $mNum; ?>)</td>
                            <td>
                                <select name="months[<?php echo $mNum; ?>][season]" style="padding:4px 8px; border-radius:6px; border:1px solid var(--border); font-size:12px;">
                                    <option value="peak" <?php echo $cfg['season'] === 'peak' ? 'selected' : ''; ?>>Peak Season (Dry / High Bookings)</option>
                                    <option value="shoulder" <?php echo $cfg['season'] === 'shoulder' ? 'selected' : ''; ?>>Shoulder Season (Moderate)</option>
                                    <option value="off" <?php echo $cfg['season'] === 'off' ? 'selected' : ''; ?>>Off-Peak Season (Monsoon / Low)</option>
                                </select>
                            </td>
                            <td>
                                <input type="number" step="0.05" name="months[<?php echo $mNum; ?>][pickup_factor]" value="<?php echo (float)$cfg['pickup_factor']; ?>" style="width:90px; padding:4px 8px; border-radius:6px; border:1px solid var(--border); font-size:12px;">
                            </td>
                            <td>
                                <input type="number" step="0.05" name="months[<?php echo $mNum; ?>][lead_time_factor]" value="<?php echo (float)$cfg['lead_time_factor']; ?>" style="width:90px; padding:4px 8px; border-radius:6px; border:1px solid var(--border); font-size:12px;">
                            </td>
                            <td>
                                <?php if ($isCurrent): ?>
                                <span class="ts-badge green"><i class="fas fa-check"></i> ACTIVE NOW</span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="padding:14px 20px; text-align:right; border-top:1px solid var(--border); background:var(--bg);">
                <button type="submit" class="ts-btn green"><i class="fas fa-save"></i> Save Seasonality Matrix</button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================================
     PANEL 5: API Integration
     ====================================================================== -->
<div class="ts-panel" data-panel-group="forecast" data-panel="api">
    <div class="ts-card">
        <div class="ts-card-header">
            <h3><i class="fas fa-plug"></i> Customer Booking Forward-Curve Ingestion API</h3>
        </div>
        <div class="ts-card-body">
            <p>External reservation systems (e.g. Tour-Sphere B2C portal, OTA booking engine) can automatically stream forward booking curves into this forecasting engine.</p>
            <div class="ts-field" style="margin-bottom:16px;">
                <label>Ingest API Endpoint</label>
                <input type="text" readonly value="<?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/api/booking-forecast.php'; ?>" style="font-family:'JetBrains Mono',monospace;">
            </div>
            <div class="ts-field" style="margin-bottom:16px;">
                <label>Authentication X-API-Key</label>
                <div style="display:flex; gap:10px;">
                    <input type="text" readonly value="<?php echo tsh($apiKey); ?>" style="font-family:'JetBrains Mono',monospace;">
                    <form method="POST" action="demand-forecast.php" onsubmit="return confirm('Rotate API Key?');">
                        <?php echo tsCsrfField(); ?>
                        <input type="hidden" name="sub_action" value="regenerate_api_key">
                        <button type="submit" class="ts-btn amber"><i class="fas fa-rotate"></i> Rotate Key</button>
                    </form>
                </div>
            </div>

            <div style="background:var(--bg); padding:14px; border-radius:10px; border:1px solid var(--border); font-size:12.5px;">
                <strong>Sample cURL Push:</strong>
                <pre style="margin-top:6px; font-family:'JetBrains Mono',monospace; overflow-x:auto;">
curl -X POST https://your-domain.com/api/booking-forecast.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: <?php echo tsh($apiKey); ?>" \
  -d '{
    "bookings": [
      {"departure_date": "<?php echo date('Y-m-d', strtotime('+7 days')); ?>", "tour_type": "Island Hopping", "booked_pax": 160, "expected_pax": 190, "external_ref": "BK-2026-991"}
    ]
  }'
                </pre>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================================
     MODALS
     ====================================================================== -->

<!-- Modal: Add Consumable -->
<div class="ts-modal-backdrop" id="modalAddConsumable">
    <div class="ts-modal">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-boxes-packing"></i></div>
            <h3 data-ts-title>Consumable Consumption Formula</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="demand-forecast.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="save_consumable">
            <input type="hidden" name="id" value="0">
            <div class="ts-modal-body">
                <div class="ts-grid">
                    <div class="ts-field span-2">
                        <label>Consumable Item Name <span class="req">*</span></label>
                        <input type="text" name="item_name" required placeholder="e.g. Bottled Water 500ml, Dry Bag 15L">
                    </div>
                    <div class="ts-field">
                        <label>Unit of Measure</label>
                        <input type="text" name="unit" value="pcs" placeholder="bottles, pcs, liters, packs">
                    </div>
                    <div class="ts-field">
                        <label>Usage per Passenger <span class="req">*</span></label>
                        <input type="number" step="0.1" name="usage_per_pax" value="1.0" required>
                    </div>
                    <div class="ts-field">
                        <label>Applies to Tour Circuit</label>
                        <input type="text" name="tour_type" value="ALL" placeholder="ALL, Island Hopping, Mountain Trek">
                    </div>
                    <div class="ts-field">
                        <label>Base Supplier Lead Time (Days)</label>
                        <input type="number" name="lead_time_days" value="7" min="1">
                    </div>
                    <div class="ts-field">
                        <label>Target Service Level (%)</label>
                        <select name="service_level">
                            <option value="99.5">99.5% (Mission-Critical / Safety Gear)</option>
                            <option value="98.0">98.0% (High - Water, Fuel Reserves)</option>
                            <option value="95.0" selected>95.0% (Standard Consumable)</option>
                            <option value="90.0">90.0% (Low Volatility)</option>
                        </select>
                    </div>
                    <div class="ts-field span-2">
                        <label>Link to Standard Inventory Catalog</label>
                        <select name="product_id">
                            <?php echo tsOptions($productMap, null, true, '— Unlinked (Track Manually) —'); ?>
                        </select>
                    </div>
                    <div class="ts-field span-2">
                        <label>Manual On-Hand Balance (if not linked to catalog)</label>
                        <input type="number" name="manual_on_hand" value="0" min="0">
                    </div>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn green"><i class="fas fa-save"></i> Save Formula</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Log Booking Entry -->
<div class="ts-modal-backdrop" id="modalAddBooking">
    <div class="ts-modal">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-ticket"></i></div>
            <h3 data-ts-title>Log Forward Booking Curve Entry</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="demand-forecast.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="add_booking_entry">
            <div class="ts-modal-body">
                <div class="ts-grid">
                    <div class="ts-field">
                        <label>Departure Date <span class="req">*</span></label>
                        <input type="date" name="departure_date" required value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                    </div>
                    <div class="ts-field">
                        <label>Tour Package / Type <span class="req">*</span></label>
                        <input type="text" name="tour_type" required value="Island Hopping">
                    </div>
                    <div class="ts-field">
                        <label>Firm Booked Passengers</label>
                        <input type="number" name="booked_pax" value="45" min="0">
                    </div>
                    <div class="ts-field">
                        <label>Expected Final Passengers</label>
                        <input type="number" name="expected_pax" value="55" min="0">
                    </div>
                    <div class="ts-field span-2">
                        <label>Booking Batch Reference</label>
                        <input type="text" name="external_ref" placeholder="e.g. BK-2026-OCT-W2">
                    </div>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn green"><i class="fas fa-save"></i> Log Forward Booking</button>
            </div>
        </form>
    </div>
</div>

<?php
$chartLabels = [];
$chartOnHand = [];
$chartProjected = [];
$chartSafety = [];
foreach ($forecastCalculations as $fc) {
    $chartLabels[] = $fc['item']['item_name'];
    $chartOnHand[] = $fc['on_hand'];
    $chartProjected[] = $fc['projected_demand'];
    $chartSafety[] = $fc['dynamic_safety_stock'];
}

$chartScript = "
<script>
document.addEventListener('DOMContentLoaded', function() {
    var ctx = document.getElementById('forecastChart');
    if (ctx && typeof Chart !== 'undefined') {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: " . json_encode($chartLabels) . ",
                datasets: [
                    {
                        label: 'Current On Hand',
                        data: " . json_encode($chartOnHand) . ",
                        backgroundColor: 'rgba(16, 185, 129, 0.75)',
                        borderColor: '#10B981',
                        borderWidth: 1.5
                    },
                    {
                        label: 'Projected 30-Day Demand',
                        data: " . json_encode($chartProjected) . ",
                        backgroundColor: 'rgba(47, 128, 237, 0.75)',
                        borderColor: '#2F80ED',
                        borderWidth: 1.5
                    },
                    {
                        label: 'Dynamic Safety Buffer',
                        data: " . json_encode($chartSafety) . ",
                        backgroundColor: 'rgba(245, 158, 11, 0.75)',
                        borderColor: '#F59E0B',
                        borderWidth: 1.5
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', labels: { font: { family: 'Poppins', size: 12 } } },
                    tooltip: { mode: 'index', intersect: false }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }
});
</script>
";

tsRenderFooter($chartScript);
?>
