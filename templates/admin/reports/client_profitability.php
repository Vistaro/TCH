<?php
$pageTitle = 'Client Profitability';
$activeNav = 'report-client-profitability';

$db = getDB();

// Per-client: billed (revenue), wages (roster cost), expenses (patient_expenses), margin %
$rows = $db->query(
    "SELECT c.id AS client_id,
            COALESCE(p.full_name, CONCAT('Client #', c.id)) AS client_name,
            pt.patient_name,
            c.account_number,
            COALESCE((SELECT SUM(cr.income) FROM client_revenue cr WHERE cr.client_id = c.id), 0) AS billed,
            COALESCE((SELECT SUM(dr.cost_rate) FROM daily_roster dr
                      WHERE dr.client_id = c.id AND dr.status = 'delivered'), 0) AS wages,
            COALESCE((SELECT SUM(pe.amount) FROM patient_expenses pe
                      WHERE pe.client_id = c.id), 0) AS expenses
     FROM clients c
     LEFT JOIN persons p ON p.id = c.person_id
     LEFT JOIN patients pt ON pt.person_id = c.person_id
     ORDER BY client_name"
)->fetchAll();

$grandBilled = 0.0; $grandWages = 0.0; $grandExpenses = 0.0;
foreach ($rows as &$r) {
    $r['gross'] = (float)$r['billed'] - (float)$r['wages'] - (float)$r['expenses'];
    $r['margin_pct'] = (float)$r['billed'] > 0 ? ($r['gross'] / (float)$r['billed']) * 100 : 0;
    $grandBilled   += (float)$r['billed'];
    $grandWages    += (float)$r['wages'];
    $grandExpenses += (float)$r['expenses'];
}
unset($r);
$grandGross = $grandBilled - $grandWages - $grandExpenses;
$grandMarginPct = $grandBilled > 0 ? ($grandGross / $grandBilled) * 100 : 0;

require APP_ROOT . '/templates/layouts/admin.php';
?>

<p style="color:#666;font-size:0.85rem;margin-bottom:1rem;">
    <?= count($rows) ?> client<?= count($rows) !== 1 ? 's' : '' ?>. Click any row for the monthly breakdown.
</p>

<table class="report-table tch-data-table">
    <thead>
        <tr>
            <th>Client / Patient</th>
            <th class="number" data-no-filter>Total Billed</th>
            <th class="number" data-no-filter>Total Wages</th>
            <th class="number" data-no-filter>Expenses</th>
            <th class="number" data-no-filter>Gross Profit</th>
            <th class="number" data-no-filter>GP Margin</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $r):
            if ((float)$r['billed'] == 0 && (float)$r['wages'] == 0) continue; // hide zero-activity clients
            $displayName = $r['client_name'];
            if ($r['patient_name'] && $r['patient_name'] !== $r['client_name']) {
                $displayName .= ' <span style="color:#999;font-size:0.85em;">(for ' . htmlspecialchars($r['patient_name']) . ')</span>';
            }
        ?>
        <tr style="cursor:pointer;" onclick="window.location='<?= APP_URL ?>/admin/reports/client-profitability/<?= (int)$r['client_id'] ?>'">
            <td><?= $displayName ?></td>
            <td class="number"><?= (float)$r['billed'] > 0 ? 'R' . number_format((float)$r['billed'], 0) : '—' ?></td>
            <td class="number"><?= (float)$r['wages'] > 0 ? 'R' . number_format((float)$r['wages'], 0) : '—' ?></td>
            <td class="number"><?= (float)$r['expenses'] > 0 ? 'R' . number_format((float)$r['expenses'], 0) : '—' ?></td>
            <td class="number" style="color:<?= $r['gross'] >= 0 ? '#198754' : '#dc3545' ?>">
                R<?= number_format($r['gross'], 0) ?>
            </td>
            <td class="number"><?= (float)$r['billed'] > 0 ? number_format($r['margin_pct'], 0) . '%' : '—' ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr class="total-row" style="font-weight:bold;border-top:2px solid #333;">
            <td>Total</td>
            <td class="number">R<?= number_format($grandBilled, 0) ?></td>
            <td class="number">R<?= number_format($grandWages, 0) ?></td>
            <td class="number"><?= $grandExpenses > 0 ? 'R' . number_format($grandExpenses, 0) : '—' ?></td>
            <td class="number" style="color:<?= $grandGross >= 0 ? '#198754' : '#dc3545' ?>">
                R<?= number_format($grandGross, 0) ?>
            </td>
            <td class="number"><?= number_format($grandMarginPct, 0) ?>%</td>
        </tr>
    </tfoot>
</table>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
