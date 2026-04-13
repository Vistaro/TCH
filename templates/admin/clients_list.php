<?php
$pageTitle = 'Clients';
$activeNav = 'clients';

$db = getDB();

$sql = "SELECT c.id, c.account_number, c.billing_entity,
               p.tch_id, p.full_name AS client_name,
               pt.patient_name,
               (SELECT COUNT(*) FROM client_revenue cr WHERE cr.client_id = c.id) AS revenue_rows,
               (SELECT COALESCE(SUM(cr2.income), 0) FROM client_revenue cr2 WHERE cr2.client_id = c.id) AS total_billed,
               (SELECT COUNT(*) FROM daily_roster dr WHERE dr.client_id = c.id) AS shift_count,
               (SELECT COALESCE(SUM(dr2.cost_rate), 0) FROM daily_roster dr2 WHERE dr2.client_id = c.id) AS total_cost
        FROM clients c
        LEFT JOIN persons p ON p.id = c.person_id
        LEFT JOIN patients pt ON pt.person_id = c.person_id
        ORDER BY p.full_name";
$rows = $db->query($sql)->fetchAll();

require APP_ROOT . '/templates/layouts/admin.php';
?>

<p style="color:#666;font-size:0.85rem;margin-bottom:1rem;"><?= count($rows) ?> client<?= count($rows) !== 1 ? 's' : '' ?></p>

<?php
$totRev=0; $totCost=0; $totShifts=0; $totMonths=0;
foreach ($rows as $r) {
    $totRev    += (float)$r['total_billed'];
    $totCost   += (float)$r['total_cost'];
    $totShifts += (int)$r['shift_count'];
    $totMonths += (int)$r['revenue_rows'];
}
$totMargin = $totRev - $totCost;
?>
<div class="report-table-scroll">
<table class="report-table tch-data-table">
    <thead><tr>
        <th>Account</th><th>Client Name</th><th>Patient Name</th>
        <th>Entity</th><th>Revenue Months</th><th>Total Billed</th>
        <th>Shifts</th><th>Total Cost</th><th>Gross Margin</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $margin = (float)$r['total_billed'] - (float)$r['total_cost'];
    ?>
    <tr>
        <td><code><?= htmlspecialchars($r['account_number'] ?? '') ?></code></td>
        <td><?= htmlspecialchars($r['client_name'] ?? 'Company (no person)') ?></td>
        <td><?= htmlspecialchars($r['patient_name'] ?? '') ?: '<span style="color:#ccc;">same</span>' ?></td>
        <td><?= htmlspecialchars($r['billing_entity'] ?? '') ?></td>
        <td class="number"><?= (int)$r['revenue_rows'] ?></td>
        <td class="number"><?= (float)$r['total_billed'] > 0 ? 'R' . number_format((float)$r['total_billed'], 0) : '—' ?></td>
        <td class="number"><?= (int)$r['shift_count'] ?></td>
        <td class="number"><?= (float)$r['total_cost'] > 0 ? 'R' . number_format((float)$r['total_cost'], 0) : '—' ?></td>
        <td class="number" style="<?= $margin >= 0 ? 'color:#198754' : 'color:#dc3545' ?>"><?= $margin != 0 ? 'R' . number_format($margin, 0) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr class="totals-row">
            <td colspan="4">Total — <?= count($rows) ?> client<?= count($rows) !== 1 ? 's' : '' ?></td>
            <td class="number"><?= number_format($totMonths) ?></td>
            <td class="number">R<?= number_format($totRev, 0) ?></td>
            <td class="number"><?= number_format($totShifts) ?></td>
            <td class="number">R<?= number_format($totCost, 0) ?></td>
            <td class="number" style="<?= $totMargin >= 0 ? 'color:#198754' : 'color:#dc3545' ?>">R<?= number_format($totMargin, 0) ?></td>
        </tr>
    </tfoot>
</table>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
