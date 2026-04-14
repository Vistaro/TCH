<?php
$pageTitle = 'Client Profitability';
$activeNav = 'report-client-profitability';

$db = getDB();

// ── Month filter ─────────────────────────────────────────────
// Single source of truth — daily_roster. client_revenue retained as
// historical read-only snapshot; D3 ingest is authoritative.
$availableMonths = $db->query(
    "SELECT DISTINCT DATE_FORMAT(roster_date, '%Y-%m') AS ym,
            DATE_FORMAT(roster_date, '%b %Y') AS label
     FROM daily_roster
     WHERE roster_date IS NOT NULL
     ORDER BY ym"
)->fetchAll();

$selectedMonths = $_GET['months'] ?? [];
if (!is_array($selectedMonths)) $selectedMonths = [$selectedMonths];
$selectedMonths = array_filter($selectedMonths);
$hasFilter = !empty($selectedMonths);

$rosterFilter = '';   // cost side — daily_roster
$billFilter   = '';   // revenue side — client_revenue
$expFilter    = '';
$params = [];
if ($hasFilter) {
    $ph = implode(',', array_fill(0, count($selectedMonths), '?'));
    $rosterFilter = " AND DATE_FORMAT(dr.roster_date, '%Y-%m') IN ($ph)";
    $billFilter   = " AND DATE_FORMAT(cr.month_date, '%Y-%m') IN ($ph)";
    $expFilter    = " AND DATE_FORMAT(pe.expense_date, '%Y-%m') IN ($ph)";
}

function monthToggleUrl(string $ym, array $selected): string {
    $new = in_array($ym, $selected)
        ? array_values(array_diff($selected, [$ym]))
        : array_merge($selected, [$ym]);
    if (empty($new)) return APP_URL . '/admin/reports/client-profitability';
    $p = [];
    foreach ($new as $m) $p[] = 'months[]=' . urlencode($m);
    return APP_URL . '/admin/reports/client-profitability?' . implode('&', $p);
}

// Per-client: billed from client_revenue (invoice grain), wages from
// daily_roster (cost grain), expenses from patient_expenses. Revenue and
// cost live at different grains — roster shouldn't carry bill amounts.
$stmt = $db->prepare(
    "SELECT c.id AS client_id,
            COALESCE(p.full_name, CONCAT('Client #', c.id)) AS client_name,
            pt.patient_name,
            c.account_number,
            COALESCE((SELECT SUM(cr.income) FROM client_revenue cr
                      WHERE cr.client_id = c.id $billFilter), 0) AS billed,
            COALESCE((SELECT SUM(dr.units * COALESCE(dr.cost_rate,0)) FROM daily_roster dr
                      WHERE dr.client_id = c.id AND dr.status = 'delivered' $rosterFilter), 0) AS wages,
            COALESCE((SELECT SUM(pe.amount) FROM patient_expenses pe
                      WHERE pe.client_id = c.id $expFilter), 0) AS expenses,
            (p.tch_id = 'TCH-UNBILLED') AS is_unbilled_umbrella
     FROM clients c
     LEFT JOIN persons p ON p.id = c.person_id
     LEFT JOIN patients pt ON pt.person_id = c.person_id
     ORDER BY is_unbilled_umbrella DESC, client_name"
);
// Params fire three times (once per subquery) when filter is active:
// billed (client_revenue), wages (daily_roster), expenses (patient_expenses)
$allParams = array_merge($selectedMonths, $selectedMonths, $selectedMonths);
$stmt->execute($allParams);
$rows = $stmt->fetchAll();

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

<div style="margin-bottom:1rem;">
    <div style="display:flex;flex-wrap:wrap;gap:0.4rem;align-items:center;">
        <span style="font-size:0.85rem;color:#666;margin-right:0.5rem;">Period:</span>
        <?php foreach ($availableMonths as $m):
            $isOn = in_array($m['ym'], $selectedMonths); ?>
        <a href="<?= monthToggleUrl($m['ym'], $selectedMonths) ?>"
           style="display:inline-block;padding:0.3rem 0.75rem;border-radius:20px;font-size:0.85rem;text-decoration:none;
                  <?= $isOn ? 'background:#0d6efd;color:white;' : 'background:#e9ecef;color:#495057;' ?>"
        ><?= htmlspecialchars($m['label']) ?></a>
        <?php endforeach; ?>
        <?php if ($hasFilter): ?>
        <a href="<?= APP_URL ?>/admin/reports/client-profitability"
           style="margin-left:0.5rem;font-size:0.8rem;color:#666;">Clear</a>
        <?php endif; ?>
    </div>
</div>

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
        <?php
            $detailUrl = APP_URL . '/admin/reports/client-profitability/' . (int)$r['client_id'];
            if ($hasFilter) {
                $q = [];
                foreach ($selectedMonths as $m) $q[] = 'months[]=' . urlencode($m);
                $detailUrl .= '?' . implode('&', $q);
            }
        ?>
        <tr style="cursor:pointer;<?= $r['is_unbilled_umbrella'] ? 'background:#f8d7da;color:#721c24;font-weight:600;border-left:4px solid #dc3545;' : '' ?>" onclick="window.location='<?= $detailUrl ?>'">
            <td><?= $r['is_unbilled_umbrella'] ? '⚠ ' : '' ?><?= $displayName ?></td>
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
