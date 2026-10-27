<?php
/**
 * admin/quality-returns.php
 * Module 5: Quality Inspection & Returns Management (RMA)
 *
 * Core Functions:
 *  - Goods Receipt Inspection: Pass/fail criteria & AQL sample evaluation for inbound supplier batches.
 *  - Return Merchandise Authorization (RMA): Guest & field operator defective returns tracking.
 *  - Vendor Dispute Management: Claims for damaged/substandard supplies with settlement tracking.
 *  - Credit Note Reconciliation with Accounts Payable (AP): Tracks applied credits against vendor payables.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tour_ops.php';

$pdo = tourOpsBoot('quality-returns.php');
$suppliers = tsSuppliers($pdo);
$supplierMap = tsSupplierMap($suppliers);
$products = tsProducts($pdo);
$productMap = tsProductMap($products);

// ============================================================================
// POST Actions
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tsRequireCsrf('quality-returns.php');
    $subAction = (string)($_POST['sub_action'] ?? '');

    try {
        if ($subAction === 'save_inspection') {
            $inspNo = tsStr($_POST['inspection_number'] ?? tsNextNumber('QC'), 60);
            $poId = tsInt($_POST['po_id'] ?? 0) ?: null;
            $productId = tsInt($_POST['product_id'] ?? 0) ?: null;
            $supplierId = tsInt($_POST['supplier_id'] ?? 0) ?: null;
            $lotQty = tsInt($_POST['lot_quantity'] ?? 0);
            $sampleSize = tsInt($_POST['sample_size'] ?? 0);
            $defects = tsInt($_POST['defects_found'] ?? 0);
            $acceptance = tsInt($_POST['acceptance_number'] ?? 1);
            $result = $defects <= $acceptance ? 'passed' : 'failed';
            $disposition = tsPick($_POST['disposition'] ?? ($result === 'passed' ? 'accepted' : 'vendor_return'), ['accepted', 'quarantine', 'vendor_return', 'scrap'], 'accepted');
            $inspector = tsStr($_POST['inspector_name'] ?? ($_SESSION['full_name'] ?? 'Quality Inspector'), 150);
            $notes = tsStr($_POST['notes'] ?? '', 500);

            $stmt = $pdo->prepare("INSERT INTO qc_inspections (inspection_number, po_id, product_id, supplier_id, lot_quantity, sample_size, defects_found, acceptance_number, result, disposition, inspector_name, notes, inspected_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$inspNo, $poId, $productId, $supplierId, $lotQty, $sampleSize, $defects, $acceptance, $result, $disposition, $inspector, $notes, tsUserId()]);

            // If failed and vendor_return, automatically create a linked dispute draft
            if ($result === 'failed' && $supplierId > 0) {
                $dispNo = tsNextNumber('DISP');
                $pdo->prepare("INSERT INTO vendor_disputes (dispute_number, supplier_id, inspection_id, po_id, dispute_type, description, status, opened_by) VALUES (?, ?, (SELECT id FROM qc_inspections WHERE inspection_number = ?), ?, 'quality', ?, 'open', ?)")
                    ->execute([$dispNo, $supplierId, $inspNo, $poId, "Defective batch rejected under inspection $inspNo ($defects defects found in sample $sampleSize)", tsUserId()]);
            }

            logAudit(tsUserId(), 'create_qc_inspection', 'quality', "Logged inspection $inspNo ($result, $disposition)");
            tsFlash('success', "Goods receipt inspection $inspNo recorded ($result). Disposition: " . strtoupper($disposition));
            tsRedirect('quality-returns.php#inspections');
        }

        if ($subAction === 'save_rma') {
            $id = tsInt($_POST['id'] ?? 0);
            $rmaNo = tsStr($_POST['rma_number'] ?? tsNextNumber('RMA'), 60);
            $source = tsPick($_POST['source'] ?? 'tour_guest', ['tour_guest', 'tour_guide', 'warehouse', 'transport_driver'], 'tour_guest');
            $requester = tsStr($_POST['requester_name'] ?? '', 150);
            $contact = tsStr($_POST['contact'] ?? '', 150);
            $tourRef = tsStr($_POST['tour_reference'] ?? '', 120);
            $productId = tsInt($_POST['product_id'] ?? 0) ?: null;
            $supplierId = tsInt($_POST['supplier_id'] ?? 0) ?: null;
            $quantity = tsInt($_POST['quantity'] ?? 1, 1);
            $reason = tsPick($_POST['reason'] ?? 'defective', ['defective', 'damaged', 'wrong_item', 'safety_recall', 'unsatisfactory'], 'defective');
            $description = tsStr($_POST['description'] ?? '', 500);
            $status = tsPick($_POST['status'] ?? 'requested', ['requested', 'received', 'under_review', 'approved', 'rejected', 'closed'], 'requested');
            $creditExp = tsNum($_POST['credit_expected'] ?? 0);
            $notes = tsStr($_POST['resolution_notes'] ?? '', 500);

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE rma_requests SET source = ?, requester_name = ?, contact = ?, tour_reference = ?, product_id = ?, supplier_id = ?, quantity = ?, reason = ?, description = ?, status = ?, credit_expected = ?, resolution_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$source, $requester, $contact, $tourRef, $productId, $supplierId, $quantity, $reason, $description, $status, $creditExp, $notes, $id]);
                logAudit(tsUserId(), 'update_rma', 'quality', "Updated RMA $rmaNo (Status: $status)");
                tsFlash('success', "RMA $rmaNo updated.");
            } else {
                $stmt = $pdo->prepare("INSERT INTO rma_requests (rma_number, source, requester_name, contact, tour_reference, product_id, supplier_id, quantity, reason, description, status, credit_expected, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$rmaNo, $source, $requester, $contact, $tourRef, $productId, $supplierId, $quantity, $reason, $description, $status, $creditExp, tsUserId()]);
                logAudit(tsUserId(), 'create_rma', 'quality', "Created RMA $rmaNo for $requester");
                tsFlash('success', "Return Authorization $rmaNo created.");
            }
            tsRedirect('quality-returns.php#rma');
        }

        if ($subAction === 'save_dispute') {
            $id = tsInt($_POST['id'] ?? 0);
            $dispNo = tsStr($_POST['dispute_number'] ?? tsNextNumber('DISP'), 60);
            $supplierId = tsInt($_POST['supplier_id'] ?? 0);
            $rmaId = tsInt($_POST['rma_id'] ?? 0) ?: null;
            $type = tsPick($_POST['dispute_type'] ?? 'quality', ['quality', 'short_shipment', 'pricing_error', 'damaged_transit', 'non_compliance'], 'quality');
            $claimed = tsNum($_POST['amount_claimed'] ?? 0);
            $settled = tsNum($_POST['amount_settled'] ?? 0);
            $desc = tsStr($_POST['description'] ?? '', 500);
            $status = tsPick($_POST['status'] ?? 'open', ['open', 'in_negotiation', 'resolved', 'escalated'], 'open');
            $notes = tsStr($_POST['resolution_notes'] ?? '', 500);

            if ($supplierId < 1) throw new RuntimeException('Supplier is required for a dispute claim.');

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE vendor_disputes SET supplier_id = ?, rma_id = ?, dispute_type = ?, amount_claimed = ?, amount_settled = ?, description = ?, status = ?, resolution_notes = ?, resolved_at = CASE WHEN ? = 'resolved' THEN CURRENT_TIMESTAMP ELSE resolved_at END WHERE id = ?");
                $stmt->execute([$supplierId, $rmaId, $type, $claimed, $settled, $desc, $status, $notes, $status, $id]);
                logAudit(tsUserId(), 'update_dispute', 'quality', "Updated dispute $dispNo ($status)");
                tsFlash('success', "Vendor dispute $dispNo updated.");
            } else {
                $stmt = $pdo->prepare("INSERT INTO vendor_disputes (dispute_number, supplier_id, rma_id, dispute_type, amount_claimed, amount_settled, description, status, opened_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$dispNo, $supplierId, $rmaId, $type, $claimed, $settled, $desc, $status, tsUserId()]);
                logAudit(tsUserId(), 'create_dispute', 'quality', "Created vendor dispute $dispNo");
                tsFlash('success', "Vendor dispute claim $dispNo filed.");
            }
            tsRedirect('quality-returns.php#disputes');
        }

        if ($subAction === 'save_credit_note') {
            $id = tsInt($_POST['id'] ?? 0);
            $cnNo = tsStr($_POST['credit_note_number'] ?? '', 80);
            $supplierId = tsInt($_POST['supplier_id'] ?? 0);
            $disputeId = tsInt($_POST['dispute_id'] ?? 0) ?: null;
            $rmaId = tsInt($_POST['rma_id'] ?? 0) ?: null;
            $amount = tsNum($_POST['amount'] ?? 0);
            $issueDate = tsDate($_POST['issue_date'] ?? date('Y-m-d'));
            $apRef = tsStr($_POST['ap_reference'] ?? '', 100);
            $applied = tsNum($_POST['amount_applied'] ?? 0);
            $apStatus = tsPick($_POST['ap_status'] ?? 'pending', ['pending', 'partially_applied', 'reconciled'], 'pending');
            $notes = tsStr($_POST['notes'] ?? '', 255);

            if ($cnNo === '' || $supplierId < 1) throw new RuntimeException('Credit Note Number and Supplier are required.');

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE credit_notes SET credit_note_number = ?, supplier_id = ?, dispute_id = ?, rma_id = ?, amount = ?, issue_date = ?, ap_reference = ?, amount_applied = ?, ap_status = ?, notes = ?, reconciled_at = CASE WHEN ? = 'reconciled' THEN CURRENT_TIMESTAMP ELSE reconciled_at END WHERE id = ?");
                $stmt->execute([$cnNo, $supplierId, $disputeId, $rmaId, $amount, $issueDate, $apRef, $applied, $apStatus, $notes, $apStatus, $id]);
                logAudit(tsUserId(), 'update_credit_note', 'quality', "Updated credit note $cnNo (AP: $apStatus)");
                tsFlash('success', "Credit note $cnNo updated.");
            } else {
                $stmt = $pdo->prepare("INSERT INTO credit_notes (credit_note_number, supplier_id, dispute_id, rma_id, amount, issue_date, ap_reference, amount_applied, ap_status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$cnNo, $supplierId, $disputeId, $rmaId, $amount, $issueDate, $apRef, $applied, $apStatus, $notes, tsUserId()]);
                logAudit(tsUserId(), 'create_credit_note', 'quality', "Logged credit note $cnNo for PHP $amount");
                tsFlash('success', "Credit note $cnNo registered.");
            }
            tsRedirect('quality-returns.php#credit_notes');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        tsFlash('error', $e->getMessage());
        tsRedirect('quality-returns.php');
    }
}

// ============================================================================
// Query Datasets
// ============================================================================
$inspections = $pdo->query("
    SELECT i.*, p.product_name, p.sku, s.company_name AS supplier_name
    FROM qc_inspections i
    LEFT JOIN products p ON p.id = i.product_id
    LEFT JOIN suppliers s ON s.id = i.supplier_id
    ORDER BY i.inspected_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$rmas = $pdo->query("
    SELECT r.*, p.product_name, p.sku, s.company_name AS supplier_name
    FROM rma_requests r
    LEFT JOIN products p ON p.id = r.product_id
    LEFT JOIN suppliers s ON s.id = r.supplier_id
    ORDER BY (r.status IN ('requested', 'received', 'under_review')) DESC, r.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$disputes = $pdo->query("
    SELECT d.*, s.company_name AS supplier_name, r.rma_number
    FROM vendor_disputes d
    JOIN suppliers s ON s.id = d.supplier_id
    LEFT JOIN rma_requests r ON r.id = d.rma_id
    ORDER BY (d.status = 'open') DESC, d.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$creditNotes = $pdo->query("
    SELECT cn.*, s.company_name AS supplier_name, d.dispute_number
    FROM credit_notes cn
    JOIN suppliers s ON s.id = cn.supplier_id
    LEFT JOIN vendor_disputes d ON d.id = cn.dispute_id
    ORDER BY (cn.ap_status = 'pending') DESC, cn.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// KPIs
$failedQcCount = 0;
foreach ($inspections as $i) { if ($i['result'] === 'failed') $failedQcCount++; }

$openRmaCount = 0;
foreach ($rmas as $r) { if (in_array($r['status'], ['requested', 'received', 'under_review'], true)) $openRmaCount++; }

$openDisputeAmount = 0.0;
foreach ($disputes as $d) { if ($d['status'] === 'open' || $d['status'] === 'in_negotiation') $openDisputeAmount += (float)$d['amount_claimed']; }

$pendingCreditAmount = 0.0;
foreach ($creditNotes as $cn) { if ($cn['ap_status'] !== 'reconciled') $pendingCreditAmount += ((float)$cn['amount'] - (float)$cn['amount_applied']); }

// Render Header
tsRenderHeader([
    'title' => 'Quality Inspection & Returns Management (RMA)',
    'subtitle' => 'Inbound Goods Receipt inspection criteria, RMA guest/field workflows, vendor disputes & AP credit reconciliation.',
    'theme' => 'ts-theme-quality',
    'icon' => 'fa-clipboard-check',
    'hero_title' => 'Quality Assurance & Returns (RMA) Operations',
    'hero_text' => 'The system evaluates inbound supplier goods against AQL pass/fail criteria, logs defective field and guest returns, handles vendor financial claims, and reconciles credit notes with Accounts Payable.',
    'tags' => ['AQL Acceptance Criteria', 'Guest & Field RMA', 'Supplier Dispute Claims', 'AP Credit Reconciliation'],
    'actions' => '
        <button class="ts-btn sm" data-ts-open="modalNewRma"><i class="fas fa-rotate-left"></i> Issue RMA Return</button>
        <button class="ts-btn green sm" data-ts-open="modalNewInspection"><i class="fas fa-clipboard-check"></i> Inbound Goods Inspection</button>
        <button class="ts-btn amber sm" data-ts-open="modalNewDispute"><i class="fas fa-handshake-angle"></i> File Dispute</button>
    ',
]);
?>

<!-- KPIs -->
<div class="ts-kpis">
    <?php echo tsKpi('Inspection Failure Rate', "{$failedQcCount} / " . count($inspections), 'fa-flask-vial', $failedQcCount > 0 ? 'red' : 'green', 'Inbound supplier batches flagged'); ?>
    <?php echo tsKpi('Open RMA Requests', (string)$openRmaCount, 'fa-arrow-rotate-left', $openRmaCount > 0 ? 'amber' : 'green', 'Guest & field defective units'); ?>
    <?php echo tsKpi('Active Vendor Claims', tsMoney($openDisputeAmount), 'fa-handshake-angle', 'violet', count($disputes) . ' dispute cases'); ?>
    <?php echo tsKpi('Pending AP Credit Notes', tsMoney($pendingCreditAmount), 'fa-receipt', 'green', 'Awaiting offset against Payables'); ?>
</div>

<!-- Tabs -->
<div class="ts-tabs" data-ts-tab-group="quality">
    <button class="ts-tab" data-tab="inspections"><i class="fas fa-vial-circle-check"></i> Goods Receipt Inspections <span class="ts-count"><?php echo count($inspections); ?></span></button>
    <button class="ts-tab" data-tab="rma"><i class="fas fa-arrows-rotate"></i> RMA Returns (Guest &amp; Field) <span class="ts-count"><?php echo count($rmas); ?></span></button>
    <button class="ts-tab" data-tab="disputes"><i class="fas fa-scale-unbalanced"></i> Vendor Disputes &amp; Claims <span class="ts-count"><?php echo count($disputes); ?></span></button>
    <button class="ts-tab" data-tab="credit_notes"><i class="fas fa-receipt"></i> AP Credit Note Reconciliation <span class="ts-count"><?php echo count($creditNotes); ?></span></button>
</div>

<!-- ======================================================================
     PANEL 1: Goods Receipt Inspections
     ====================================================================== -->
<div class="ts-panel" data-panel-group="quality" data-panel="inspections">
    <div class="ts-card">
        <div class="ts-card-header">
            <h3><i class="fas fa-vial-circle-check"></i> Inbound Goods Receipt Quality Inspections</h3>
            <div class="ts-card-tools">
                <button class="ts-btn sm green" data-ts-open="modalNewInspection"><i class="fas fa-plus"></i> New Inspection</button>
            </div>
        </div>
        <div class="ts-table-wrap">
            <table class="ts-table">
                <thead>
                    <tr>
                        <th>Inspection #</th>
                        <th>Product Item</th>
                        <th>Supplier</th>
                        <th class="ts-num">Lot Qty</th>
                        <th class="ts-num">Sample Size</th>
                        <th class="ts-num">Defects</th>
                        <th class="ts-num">Acceptance #</th>
                        <th>Inspection Result</th>
                        <th>Disposition</th>
                        <th>Inspector</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($inspections)): ?>
                    <tr><td colspan="11" class="ts-empty">No goods inspections recorded yet.</td></tr>
                    <?php else: foreach ($inspections as $i): ?>
                    <tr>
                        <td><strong class="ts-code"><?php echo tsh($i['inspection_number']); ?></strong></td>
                        <td>
                            <strong><?php echo tsh($i['product_name'] ?: 'Batch Item'); ?></strong>
                            <?php if ($i['sku']): ?><div class="ts-muted">SKU: <?php echo tsh($i['sku']); ?></div><?php endif; ?>
                        </td>
                        <td><?php echo tsh($i['supplier_name'] ?: 'Direct Supplier'); ?></td>
                        <td class="ts-num"><?php echo number_format($i['lot_quantity']); ?></td>
                        <td class="ts-num"><strong><?php echo number_format($i['sample_size']); ?></strong></td>
                        <td class="ts-num"><strong style="<?php echo (int)$i['defects_found'] > (int)$i['acceptance_number'] ? 'color:var(--danger);' : ''; ?>"><?php echo (int)$i['defects_found']; ?></strong></td>
                        <td class="ts-num ts-muted">Ac &le; <?php echo (int)$i['acceptance_number']; ?></td>
                        <td><?php echo tsBadge($i['result'], ['passed'=>'green', 'failed'=>'red', 'pending'=>'amber'], 'ghost'); ?></td>
                        <td><?php echo tsBadge($i['disposition'], ['accepted'=>'green', 'quarantine'=>'amber', 'vendor_return'=>'red', 'scrap'=>'red'], 'ghost'); ?></td>
                        <td><span class="ts-muted"><?php echo tsh($i['inspector_name']); ?></span></td>
                        <td><span class="ts-muted"><?php echo tsFmtDate($i['inspected_at'], 'M d, Y'); ?></span></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ======================================================================
     PANEL 2: RMA Requests
     ====================================================================== -->
<div class="ts-panel" data-panel-group="quality" data-panel="rma">
    <div class="ts-card">
        <div class="ts-card-header">
            <h3><i class="fas fa-arrows-rotate"></i> Return Merchandise Authorization (Guest &amp; Field Returns)</h3>
            <div class="ts-card-tools">
                <button class="ts-btn sm green" data-ts-open="modalNewRma"><i class="fas fa-plus"></i> Create RMA</button>
            </div>
        </div>
        <div class="ts-table-wrap">
            <table class="ts-table">
                <thead>
                    <tr>
                        <th>RMA Number</th>
                        <th>Origin / Source</th>
                        <th>Requester &amp; Contact</th>
                        <th>Tour Reference</th>
                        <th>Defective Product</th>
                        <th class="ts-num">Quantity</th>
                        <th>Failure Reason</th>
                        <th>Status</th>
                        <th class="ts-num">Expected Credit</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rmas)): ?>
                    <tr><td colspan="10" class="ts-empty">No RMA requests logged.</td></tr>
                    <?php else: foreach ($rmas as $r): ?>
                    <tr>
                        <td><strong class="ts-code"><?php echo tsh($r['rma_number']); ?></strong></td>
                        <td><?php echo tsBadge($r['source'], ['tour_guest'=>'violet', 'tour_guide'=>'blue', 'warehouse'=>'green', 'transport_driver'=>'amber'], 'ghost'); ?></td>
                        <td>
                            <strong><?php echo tsh($r['requester_name']); ?></strong>
                            <div class="ts-muted"><?php echo tsh($r['contact']); ?></div>
                        </td>
                        <td><span class="ts-chip"><?php echo tsh($r['tour_reference'] ?: 'Direct'); ?></span></td>
                        <td>
                            <strong><?php echo tsh($r['product_name'] ?: 'Item #' . $r['product_id']); ?></strong>
                            <div class="ts-muted"><?php echo tsh($r['description']); ?></div>
                        </td>
                        <td class="ts-num"><strong><?php echo (int)$r['quantity']; ?></strong></td>
                        <td><?php echo tsBadge($r['reason'], ['defective'=>'red', 'damaged'=>'amber', 'wrong_item'=>'blue', 'safety_recall'=>'red'], 'ghost'); ?></td>
                        <td><?php echo tsBadge($r['status'], ['requested'=>'amber', 'received'=>'blue', 'under_review'=>'violet', 'approved'=>'green', 'rejected'=>'red', 'closed'=>'ghost'], 'ghost'); ?></td>
                        <td class="ts-num"><?php echo tsMoney($r['credit_expected']); ?></td>
                        <td style="text-align:right;">
                            <button class="ts-icon-btn green" title="Edit / Advance RMA" data-ts-open="modalNewRma" data-ts-payload="<?php echo tsPayload(array_merge($r, ['__title' => "Manage RMA {$r['rma_number']}"])); ?>">
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
     PANEL 3: Vendor Disputes
     ====================================================================== -->
<div class="ts-panel" data-panel-group="quality" data-panel="disputes">
    <div class="ts-card">
        <div class="ts-card-header">
            <h3><i class="fas fa-scale-unbalanced"></i> Supplier Financial Disputes &amp; Quality Claims</h3>
            <div class="ts-card-tools">
                <button class="ts-btn sm amber" data-ts-open="modalNewDispute"><i class="fas fa-plus"></i> File Dispute</button>
            </div>
        </div>
        <div class="ts-table-wrap">
            <table class="ts-table">
                <thead>
                    <tr>
                        <th>Dispute Claim #</th>
                        <th>Supplier</th>
                        <th>Linked RMA</th>
                        <th>Dispute Type</th>
                        <th class="ts-num">Claimed Amount</th>
                        <th class="ts-num">Settled Amount</th>
                        <th>Status</th>
                        <th>Description / Settlement Terms</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($disputes)): ?>
                    <tr><td colspan="9" class="ts-empty">No active supplier disputes.</td></tr>
                    <?php else: foreach ($disputes as $d): ?>
                    <tr>
                        <td><strong class="ts-code"><?php echo tsh($d['dispute_number']); ?></strong></td>
                        <td><strong><?php echo tsh($d['supplier_name']); ?></strong></td>
                        <td><?php echo $d['rma_number'] ? '<span class="ts-code">' . tsh($d['rma_number']) . '</span>' : '—'; ?></td>
                        <td><?php echo tsBadge($d['dispute_type'], ['quality'=>'red', 'short_shipment'=>'amber', 'pricing_error'=>'blue'], 'ghost'); ?></td>
                        <td class="ts-num"><strong style="color:var(--danger);"><?php echo tsMoney($d['amount_claimed']); ?></strong></td>
                        <td class="ts-num"><strong style="color:var(--accent);"><?php echo tsMoney($d['amount_settled']); ?></strong></td>
                        <td><?php echo tsBadge($d['status'], ['open'=>'red', 'in_negotiation'=>'amber', 'resolved'=>'green', 'escalated'=>'violet'], 'ghost'); ?></td>
                        <td>
                            <div style="font-size:12px; max-width:280px;"><?php echo tsh($d['description']); ?></div>
                            <?php if ($d['resolution_notes']): ?><div class="ts-muted">Notes: <?php echo tsh($d['resolution_notes']); ?></div><?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <button class="ts-icon-btn green" title="Edit Claim" data-ts-open="modalNewDispute" data-ts-payload="<?php echo tsPayload(array_merge($d, ['__title' => "Update Dispute {$d['dispute_number']}"])); ?>">
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
     PANEL 4: AP Credit Notes
     ====================================================================== -->
<div class="ts-panel" data-panel-group="quality" data-panel="credit_notes">
    <div class="ts-card">
        <div class="ts-card-header">
            <h3><i class="fas fa-receipt"></i> Credit Note Reconciliation with Accounts Payable (AP)</h3>
            <div class="ts-card-tools">
                <button class="ts-btn sm green" data-ts-open="modalNewCreditNote"><i class="fas fa-plus"></i> Register Credit Note</button>
            </div>
        </div>
        <div class="ts-table-wrap">
            <table class="ts-table">
                <thead>
                    <tr>
                        <th>Credit Note #</th>
                        <th>Supplier</th>
                        <th>Dispute Claim</th>
                        <th>Issue Date</th>
                        <th class="ts-num">Credit Note Total</th>
                        <th class="ts-num">Applied to AP</th>
                        <th class="ts-num">Pending Balance</th>
                        <th>AP Voucher / Invoice Offset</th>
                        <th>Reconciliation Status</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($creditNotes)): ?>
                    <tr><td colspan="10" class="ts-empty">No credit notes recorded.</td></tr>
                    <?php else: foreach ($creditNotes as $cn):
                        $pendingBal = max(0, (float)$cn['amount'] - (float)$cn['amount_applied']);
                    ?>
                    <tr>
                        <td><strong class="ts-code"><?php echo tsh($cn['credit_note_number']); ?></strong></td>
                        <td><strong><?php echo tsh($cn['supplier_name']); ?></strong></td>
                        <td><?php echo $cn['dispute_number'] ? '<span class="ts-code">' . tsh($cn['dispute_number']) . '</span>' : '—'; ?></td>
                        <td><?php echo tsFmtDate($cn['issue_date']); ?></td>
                        <td class="ts-num"><strong><?php echo tsMoney($cn['amount']); ?></strong></td>
                        <td class="ts-num"><?php echo tsMoney($cn['amount_applied']); ?></td>
                        <td class="ts-num"><strong style="<?php echo $pendingBal > 0 ? 'color:var(--primary);' : ''; ?>"><?php echo tsMoney($pendingBal); ?></strong></td>
                        <td><span class="ts-chip"><?php echo tsh($cn['ap_reference'] ?: 'Pending Offset'); ?></span></td>
                        <td><?php echo tsBadge($cn['ap_status'], ['pending'=>'amber', 'partially_applied'=>'blue', 'reconciled'=>'green'], 'ghost'); ?></td>
                        <td style="text-align:right;">
                            <button class="ts-icon-btn green" title="Reconcile Credit Note" data-ts-open="modalNewCreditNote" data-ts-payload="<?php echo tsPayload(array_merge($cn, ['__title' => "Reconcile {$cn['credit_note_number']}"])); ?>">
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

<!-- Modal: Inbound Goods Inspection -->
<div class="ts-modal-backdrop" id="modalNewInspection">
    <div class="ts-modal wide">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-vial-circle-check"></i></div>
            <h3 data-ts-title>Goods Receipt Quality Inspection</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="quality-returns.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="save_inspection">
            <div class="ts-modal-body">
                <div class="ts-grid">
                    <div class="ts-field">
                        <label>Inspection Number</label>
                        <input type="text" name="inspection_number" value="<?php echo tsNextNumber('QC'); ?>" readonly>
                    </div>
                    <div class="ts-field">
                        <label>Supplier <span class="req">*</span></label>
                        <select name="supplier_id" required>
                            <?php echo tsOptions($supplierMap, null, true, '— Select Supplier —'); ?>
                        </select>
                    </div>
                    <div class="ts-field span-2">
                        <label>Product Item Inspected</label>
                        <select name="product_id">
                            <?php echo tsOptions($productMap, null, true, '— Select Product —'); ?>
                        </select>
                    </div>
                    <div class="ts-field">
                        <label>Received Batch Lot Size</label>
                        <input type="number" name="lot_quantity" value="500" min="1" required>
                    </div>
                    <div class="ts-field">
                        <label>AQL Sample Size Drawn</label>
                        <input type="number" name="sample_size" value="50" min="1" required>
                    </div>
                    <div class="ts-field">
                        <label>Defective Units Found</label>
                        <input type="number" name="defects_found" value="0" min="0" required>
                    </div>
                    <div class="ts-field">
                        <label>Acceptance Threshold (Ac)</label>
                        <input type="number" name="acceptance_number" value="1" min="0" required>
                        <div class="ts-hint">If defects &gt; Ac, the batch fails automatically.</div>
                    </div>
                    <div class="ts-field">
                        <label>Inventory Disposition</label>
                        <select name="disposition">
                            <option value="accepted">Accepted &rarr; Release to Shelves</option>
                            <option value="quarantine">Quarantine for Secondary Testing</option>
                            <option value="vendor_return">Vendor Return &rarr; Initiate Dispute</option>
                            <option value="scrap">Scrap &rarr; Supplier Reimbursed</option>
                        </select>
                    </div>
                    <div class="ts-field">
                        <label>Inspector Name</label>
                        <input type="text" name="inspector_name" value="<?php echo tsh($_SESSION['full_name'] ?? 'Quality Officer'); ?>">
                    </div>
                    <div class="ts-field span-2">
                        <label>Inspection Criteria Notes &amp; Failure Modes</label>
                        <textarea name="notes" placeholder="Seam integrity, hydrostatic pressure test, label compliance, barcode scannability"></textarea>
                    </div>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn green"><i class="fas fa-check"></i> Submit QC Report</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: New / Edit RMA -->
<div class="ts-modal-backdrop" id="modalNewRma">
    <div class="ts-modal wide">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-rotate-left"></i></div>
            <h3 data-ts-title>Return Merchandise Authorization (RMA)</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="quality-returns.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="save_rma">
            <input type="hidden" name="id" value="0">
            <div class="ts-modal-body">
                <div class="ts-grid">
                    <div class="ts-field">
                        <label>RMA Reference</label>
                        <input type="text" name="rma_number" value="<?php echo tsNextNumber('RMA'); ?>">
                    </div>
                    <div class="ts-field">
                        <label>Return Source</label>
                        <select name="source">
                            <option value="tour_guest">Tour Guest (Defective / Dissatisfied)</option>
                            <option value="tour_guide">Tour Guide (Field Failure)</option>
                            <option value="warehouse">Warehouse Damaged / Expired</option>
                            <option value="transport_driver">Transport Driver</option>
                        </select>
                    </div>
                    <div class="ts-field">
                        <label>Requester Name <span class="req">*</span></label>
                        <input type="text" name="requester_name" required placeholder="Guest or Staff Name">
                    </div>
                    <div class="ts-field">
                        <label>Contact Info (Phone / Email)</label>
                        <input type="text" name="contact" placeholder="Phone or email">
                    </div>
                    <div class="ts-field">
                        <label>Tour Code / Booking Reference</label>
                        <input type="text" name="tour_reference" placeholder="e.g. TOUR-BOH-26">
                    </div>
                    <div class="ts-field">
                        <label>Defective Product Item</label>
                        <select name="product_id">
                            <?php echo tsOptions($productMap, null, true, '— Select Product —'); ?>
                        </select>
                    </div>
                    <div class="ts-field">
                        <label>Quantity Returned <span class="req">*</span></label>
                        <input type="number" name="quantity" value="1" min="1" required>
                    </div>
                    <div class="ts-field">
                        <label>Failure Reason</label>
                        <select name="reason">
                            <option value="defective">Defective / Manufacturing Flaw</option>
                            <option value="damaged">Damaged in Transit / Tour</option>
                            <option value="wrong_item">Wrong Item Delivered</option>
                            <option value="safety_recall">Safety Hazard / Recall</option>
                            <option value="unsatisfactory">Unsatisfactory Quality</option>
                        </select>
                    </div>
                    <div class="ts-field">
                        <label>RMA Workflow Status</label>
                        <select name="status">
                            <option value="requested">Requested by Field / Guest</option>
                            <option value="received">Item Received at Depot</option>
                            <option value="under_review">Under Technical Review</option>
                            <option value="approved">Approved for Credit / Replacement</option>
                            <option value="rejected">Rejected (User Mishandling)</option>
                            <option value="closed">Closed &amp; Settled</option>
                        </select>
                    </div>
                    <div class="ts-field">
                        <label>Expected Credit / Refund (PHP)</label>
                        <input type="number" step="0.01" name="credit_expected" value="0.00">
                    </div>
                    <div class="ts-field span-2">
                        <label>Defect Description</label>
                        <textarea name="description" placeholder="Specify failure mode (leaked valve, torn seam, broken buckle)"></textarea>
                    </div>
                    <div class="ts-field span-2">
                        <label>Resolution Notes &amp; Actions</label>
                        <textarea name="resolution_notes" placeholder="Replacement kit issued immediately from boat backup inventory"></textarea>
                    </div>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn green"><i class="fas fa-save"></i> Save RMA Record</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: New / Edit Dispute -->
<div class="ts-modal-backdrop" id="modalNewDispute">
    <div class="ts-modal">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-handshake-angle"></i></div>
            <h3 data-ts-title>File Supplier Dispute Claim</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="quality-returns.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="save_dispute">
            <input type="hidden" name="id" value="0">
            <div class="ts-modal-body">
                <div class="ts-grid">
                    <div class="ts-field">
                        <label>Dispute Claim #</label>
                        <input type="text" name="dispute_number" value="<?php echo tsNextNumber('DISP'); ?>" readonly>
                    </div>
                    <div class="ts-field">
                        <label>Vendor / Supplier <span class="req">*</span></label>
                        <select name="supplier_id" required>
                            <?php echo tsOptions($supplierMap, null, true, '— Select Supplier —'); ?>
                        </select>
                    </div>
                    <div class="ts-field">
                        <label>Claim Type</label>
                        <select name="dispute_type">
                            <option value="quality">Quality / High Defect Rate</option>
                            <option value="short_shipment">Short Shipment (Missing Units)</option>
                            <option value="pricing_error">Invoice Pricing Error</option>
                            <option value="damaged_transit">Damaged in Transit</option>
                        </select>
                    </div>
                    <div class="ts-field">
                        <label>Status</label>
                        <select name="status">
                            <option value="open">Open Claim</option>
                            <option value="in_negotiation">In Negotiation</option>
                            <option value="resolved">Resolved / Credit Agreed</option>
                            <option value="escalated">Escalated</option>
                        </select>
                    </div>
                    <div class="ts-field">
                        <label>Amount Claimed (PHP) <span class="req">*</span></label>
                        <input type="number" step="0.01" name="amount_claimed" value="0.00" required>
                    </div>
                    <div class="ts-field">
                        <label>Amount Settled (PHP)</label>
                        <input type="number" step="0.01" name="amount_settled" value="0.00">
                    </div>
                    <div class="ts-field span-2">
                        <label>Dispute Details</label>
                        <textarea name="description" placeholder="Summary of batch defects and replacement costs incurred"></textarea>
                    </div>
                    <div class="ts-field span-2">
                        <label>Settlement Terms</label>
                        <textarea name="resolution_notes" placeholder="Credit note agreed to be applied on next PO voucher"></textarea>
                    </div>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn green"><i class="fas fa-save"></i> Save Dispute</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: New / Edit Credit Note -->
<div class="ts-modal-backdrop" id="modalNewCreditNote">
    <div class="ts-modal">
        <div class="ts-modal-head">
            <div class="ts-modal-icon"><i class="fas fa-receipt"></i></div>
            <h3 data-ts-title>Register Supplier Credit Note (AP)</h3>
            <button type="button" class="ts-modal-close" data-ts-close>&times;</button>
        </div>
        <form method="POST" action="quality-returns.php">
            <?php echo tsCsrfField(); ?>
            <input type="hidden" name="sub_action" value="save_credit_note">
            <input type="hidden" name="id" value="0">
            <div class="ts-modal-body">
                <div class="ts-grid">
                    <div class="ts-field">
                        <label>Supplier Credit Note # <span class="req">*</span></label>
                        <input type="text" name="credit_note_number" required placeholder="e.g. CN-VEND-88190">
                    </div>
                    <div class="ts-field">
                        <label>Supplier <span class="req">*</span></label>
                        <select name="supplier_id" required>
                            <?php echo tsOptions($supplierMap, null, true, '— Select Supplier —'); ?>
                        </select>
                    </div>
                    <div class="ts-field">
                        <label>Issue Date</label>
                        <input type="date" name="issue_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="ts-field">
                        <label>Total Credit Value (PHP) <span class="req">*</span></label>
                        <input type="number" step="0.01" name="amount" required placeholder="0.00">
                    </div>
                    <div class="ts-field">
                        <label>Amount Applied to AP (PHP)</label>
                        <input type="number" step="0.01" name="amount_applied" value="0.00">
                    </div>
                    <div class="ts-field">
                        <label>AP Reconciliation Status</label>
                        <select name="ap_status">
                            <option value="pending">Pending AP Offset</option>
                            <option value="partially_applied">Partially Applied</option>
                            <option value="reconciled">Fully Reconciled &amp; Settled</option>
                        </select>
                    </div>
                    <div class="ts-field span-2">
                        <label>AP Invoice / Voucher Offset Reference</label>
                        <input type="text" name="ap_reference" placeholder="e.g. Offset against Invoice INV-2026-9901">
                    </div>
                    <div class="ts-field span-2">
                        <label>Reconciliation Notes</label>
                        <textarea name="notes"></textarea>
                    </div>
                </div>
            </div>
            <div class="ts-modal-foot">
                <button type="button" class="ts-btn ghost" data-ts-close>Cancel</button>
                <button type="submit" class="ts-btn green"><i class="fas fa-save"></i> Save Credit Note</button>
            </div>
        </form>
    </div>
</div>

<?php tsRenderFooter(); ?>
