<?php
/**
 * Contract detail — /admin/contracts/{id}
 */
$contractId = (int)($_GET['contract_id'] ?? 0);
$db = getDB();
$canEdit = userCan('contracts', 'edit');

$stmt = $db->prepare(
    "SELECT c.*,
            pp.full_name AS patient_name, pp.tch_id AS patient_tch_id,
            cp.full_name AS client_name,  cp.tch_id AS client_tch_id,
            cl.account_number, cl.default_billing_freq,
            u_created.first_name AS created_by_first, u_created.last_name AS created_by_last
     FROM contracts c
LEFT JOIN persons pp ON pp.id = c.patient_person_id
LEFT JOIN clients cl ON cl.id = c.client_id
LEFT JOIN persons cp ON cp.id = cl.person_id
LEFT JOIN users u_created ON u_created.id = c.created_by_user_id
    WHERE c.id = ?"
);
$stmt->execute([$contractId]);
$c = $stmt->fetch();
if (!$c) { http_response_code(404); die('Contract not found.'); }

$pageTitle = 'Contract #' . $contractId;
$activeNav = 'contracts';

$stmt = $db->prepare(
    "SELECT cl.*, p.name AS product_name, p.code AS product_code
     FROM contract_lines cl
     JOIN products p ON p.id = cl.product_id
     WHERE cl.contract_id = ?
     ORDER BY cl.id"
);
$stmt->execute([$contractId]);
$lines = $stmt->fetchAll();

// Related roster shifts
$stmt = $db->prepare(
    "SELECT COUNT(*) shifts, COALESCE(SUM(units),0) units,
            COALESCE(SUM(units*cost_rate),0) cost,
            COALESCE(SUM(units*bill_rate),0) bill,
            MIN(roster_date) first_shift, MAX(roster_date) last_shift
     FROM daily_roster WHERE contract_id = ?"
);
$stmt->execute([$contractId]);
$rosterSummary = $stmt->fetch() ?: [];

$flash = $_GET['msg'] ?? '';
$flashType = $_GET['type'] ?? 'success';

$statusColour = [
    'draft' => '#6c757d', 'active' => '#198754', 'on_hold' => '#fd7e14',
    'cancelled' => '#dc3545', 'completed' => '#0d6efd',
][$c['status']] ?? '#6c757d';

// Period total
$periodTotal = 0.0;
foreach ($lines as $ln) $periodTotal += (float)$ln['bill_rate'] * (float)$ln['units_per_period'];

require APP_ROOT . '/templates/layouts/admin.php';
?>

<?php if ($flash): ?>
<div class="alert alert-<?= htmlspecialchars($flashType === 'error' ? 'error' : 'success') ?>" style="margin-bottom:1rem;">
    <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <div>
        <div style="font-size:0.85rem;color:#6c757d;">Contract #<?= $contractId ?></div>
        <h2 style="margin:0;">
            <?= htmlspecialchars((string)($c['patient_name'] ?? '—')) ?>
            <span style="background:<?= $statusColour ?>;color:#fff;padding:2px 10px;border-radius:10px;font-size:0.7em;text-transform:uppercase;letter-spacing:0.04em;vertical-align:middle;margin-left:0.4rem;">
                <?= htmlspecialchars(str_replace('_', ' ', $c['status'])) ?>
            </span>
        </h2>
        <div style="color:#6c757d;">billed to <?= htmlspecialchars((string)($c['client_name'] ?? '—')) ?></div>
    </div>
    <?php if ($canEdit): ?>
        <a href="<?= APP_URL ?>/admin/contracts/<?= $contractId ?>/edit" class="btn btn-primary btn-sm">Edit</a>
    <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
    <div style="background:#fff;padding:1rem;border:1px solid #dee2e6;border-radius:6px;">
        <h3 style="margin-top:0;">Parties &amp; term</h3>
        <dl style="display:grid;grid-template-columns:auto 1fr;gap:0.3rem 0.75rem;margin:0;">
            <dt>Patient</dt><dd>
                <a href="<?= APP_URL ?>/admin/patients/<?= (int)$c['patient_person_id'] ?>">
                    <?= htmlspecialchars((string)$c['patient_name']) ?>
                </a>
                <code style="color:#6c757d;font-size:0.75rem;"><?= htmlspecialchars((string)($c['patient_tch_id'] ?? '')) ?></code>
            </dd>
            <dt>Client</dt><dd>
                <a href="<?= APP_URL ?>/admin/clients/<?= (int)$c['client_id'] ?>">
                    <?= htmlspecialchars((string)$c['client_name']) ?>
                </a>
                <code style="color:#6c757d;font-size:0.75rem;"><?= htmlspecialchars((string)($c['client_tch_id'] ?? '')) ?></code>
            </dd>
            <dt>Start</dt><dd><?= htmlspecialchars($c['start_date']) ?></dd>
            <dt>End</dt><dd><?= $c['end_date'] ? htmlspecialchars($c['end_date']) : '<em style="color:#6c757d;">ongoing</em>' ?></dd>
            <dt>Auto-renew</dt><dd><?= $c['auto_renew'] ? 'Yes (flag pre-renewal)' : 'No' ?></dd>
            <?php if ($c['superseded_by']): ?>
                <dt>Superseded by</dt><dd><a href="<?= APP_URL ?>/admin/contracts/<?= (int)$c['superseded_by'] ?>">Contract #<?= (int)$c['superseded_by'] ?></a></dd>
            <?php endif; ?>
        </dl>
    </div>

    <div style="background:#fff;padding:1rem;border:1px solid #dee2e6;border-radius:6px;">
        <h3 style="margin-top:0;">Invoice</h3>
        <dl style="display:grid;grid-template-columns:auto 1fr;gap:0.3rem 0.75rem;margin:0;">
            <dt>Number</dt><dd><?= htmlspecialchars((string)($c['invoice_number'] ?? '—')) ?></dd>
            <dt>Status</dt><dd style="text-transform:capitalize;"><?= htmlspecialchars($c['invoice_status']) ?></dd>
            <dt>Amount</dt><dd><?= $c['invoice_amount'] !== null ? 'R' . number_format((float)$c['invoice_amount'], 2) : '—' ?></dd>
            <dt>Date</dt><dd><?= htmlspecialchars((string)($c['invoice_date'] ?? '—')) ?></dd>
        </dl>
    </div>
</div>

<div style="background:#fff;padding:1rem;border:1px solid #dee2e6;border-radius:6px;margin-bottom:1rem;">
    <h3 style="margin-top:0;">Product lines</h3>
    <?php if (empty($lines)): ?>
        <p style="color:#6c757d;">No product lines configured.</p>
    <?php else: ?>
    <table class="report-table" style="margin-bottom:0;">
        <thead><tr>
            <th>Product</th>
            <th class="center">Billing freq</th>
            <th class="center">Min term</th>
            <th class="number">Bill rate</th>
            <th class="number">Units / period</th>
            <th class="number">Period total</th>
            <th class="center">Starts</th>
            <th class="center">Ends</th>
            <th>Notes</th>
        </tr></thead>
        <tbody>
        <?php foreach ($lines as $ln):
            $lineTotal   = (float)$ln['bill_rate'] * (float)$ln['units_per_period'];
            // Fall through to the parent contract's dates if this line
            // pre-dates migration 037 and has not been individually set.
            $lineStart   = $ln['start_date'] ?: $c['start_date'];
            $lineEnd     = $ln['end_date']   ?: $c['end_date'];
        ?>
            <tr>
                <td><strong><?= htmlspecialchars($ln['product_name']) ?></strong> <code style="color:#6c757d;font-size:0.75rem;"><?= htmlspecialchars($ln['product_code']) ?></code></td>
                <td class="center"><?= htmlspecialchars($ln['billing_freq']) ?></td>
                <td class="center"><?= (int)$ln['min_term_months'] ? $ln['min_term_months'] . ' mo' : '—' ?></td>
                <td class="number">R<?= number_format((float)$ln['bill_rate'], 2) ?></td>
                <td class="number"><?= rtrim(rtrim(number_format((float)$ln['units_per_period'], 2), '0'), '.') ?></td>
                <td class="number">R<?= number_format($lineTotal, 2) ?></td>
                <td class="center"><?= $lineStart ? htmlspecialchars($lineStart) : '—' ?></td>
                <td class="center"><?= $lineEnd ? htmlspecialchars($lineEnd) : '<em style="color:#6c757d;">ongoing</em>' ?></td>
                <td><?= htmlspecialchars((string)($ln['notes'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="font-weight:600;border-top:2px solid #333;">
                <td colspan="5">Total per billing period</td>
                <td class="number">R<?= number_format($periodTotal, 2) ?></td>
                <td colspan="3"></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>
</div>

<?php if (($rosterSummary['shifts'] ?? 0) > 0): ?>
<div style="background:#fff;padding:1rem;border:1px solid #dee2e6;border-radius:6px;margin-bottom:1rem;">
    <h3 style="margin-top:0;">Delivery to date</h3>
    <dl style="display:grid;grid-template-columns:auto 1fr;gap:0.3rem 0.75rem;margin:0;max-width:500px;">
        <dt>Shifts delivered</dt><dd><?= (int)$rosterSummary['shifts'] ?></dd>
        <dt>Units</dt><dd><?= rtrim(rtrim(number_format((float)$rosterSummary['units'], 1), '0'), '.') ?></dd>
        <dt>Cost of delivery</dt><dd>R<?= number_format((float)$rosterSummary['cost'], 0) ?></dd>
        <dt>Billed on shifts</dt><dd>R<?= number_format((float)$rosterSummary['bill'], 0) ?></dd>
        <dt>First shift</dt><dd><?= htmlspecialchars((string)($rosterSummary['first_shift'] ?? '—')) ?></dd>
        <dt>Last shift</dt><dd><?= htmlspecialchars((string)($rosterSummary['last_shift'] ?? '—')) ?></dd>
    </dl>
</div>
<?php endif; ?>

<?php if (!empty($c['notes'])): ?>
<div style="background:#fff;padding:1rem;border:1px solid #dee2e6;border-radius:6px;margin-bottom:1rem;">
    <h3 style="margin-top:0;">Notes</h3>
    <p style="white-space:pre-wrap;margin:0;"><?= htmlspecialchars($c['notes']) ?></p>
</div>
<?php endif; ?>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
