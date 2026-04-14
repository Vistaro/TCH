<?php
/**
 * Care without matching invoice — /admin/unbilled-care
 *
 * Surfaces cost of care where no `client_revenue` row exists for the
 * effective client in the shift's month. Two flavours:
 *
 *   - Mapping gap — cost-side ingest couldn't resolve client_id and
 *     dumped the shift to the Unbilled sentinel. Fix is in the alias
 *     admin (resolve the missing alias) or the patient profile
 *     (establish bill-payer).
 *
 *   - Un-invoiced — client is known and correct, but no invoice exists
 *     in the Panel workbook for that client in that month. Business
 *     decision needed: raise the invoice, write off, or record why.
 *
 * No stored sentinel-based filter — queries are computed live from
 * `daily_roster` + `patients` + `client_revenue`. Nothing is hidden
 * behind a cached state column.
 */
$pageTitle = 'Care without matching invoice';
$activeNav = 'unbilled-care';
$db = getDB();

// Tab — 'gap' (mapping gap) or 'uninv' (un-invoiced). Default: gap.
$tab = $_GET['tab'] ?? 'gap';
if (!in_array($tab, ['gap','uninv'], true)) $tab = 'gap';

// Derive the effective client for each shift:
//   - sentinel-overwritten rows (legacy): fall back to patient's clients.id
//     via the patients side-table
//   - normal rows: r.client_id is truthful
// Then LEFT JOIN client_revenue on (effective_client_id, shift's month).
// No match → this row is "cost without matching invoice." Group by
// patient × client × month for the drill list.
$sqlBase = "
    SELECT
        r.patient_person_id,
        COALESCE(pp.full_name, r.client_assigned) AS patient_name,
        pp.tch_id AS patient_tch_id,
        CASE WHEN sc.tch_id = 'TCH-UNBILLED'
             THEN ppat.client_id
             ELSE r.client_id END AS effective_client_id,
        (sc.tch_id = 'TCH-UNBILLED') AS is_mapping_gap,
        DATE_FORMAT(r.roster_date, '%Y-%m-01') AS month_date,
        DATE_FORMAT(r.roster_date, '%b %Y')  AS month_label,
        COUNT(*)                              AS shifts,
        SUM(r.units)                          AS units,
        SUM(r.units * COALESCE(r.cost_rate, 0)) AS cost,
        MIN(r.roster_date) AS first_date,
        MAX(r.roster_date) AS last_date
    FROM daily_roster r
    LEFT JOIN persons   pp   ON pp.id   = r.patient_person_id
    LEFT JOIN persons   sc   ON sc.id   = r.client_id
    LEFT JOIN patients  ppat ON ppat.person_id = r.patient_person_id
    LEFT JOIN client_revenue cr
           ON cr.client_id =
              CASE WHEN sc.tch_id = 'TCH-UNBILLED' THEN ppat.client_id ELSE r.client_id END
          AND cr.month_date = DATE_FORMAT(r.roster_date, '%Y-%m-01')
    WHERE r.status = 'delivered'
      AND cr.id IS NULL
    GROUP BY r.patient_person_id, patient_name, patient_tch_id,
             effective_client_id, is_mapping_gap, month_date, month_label
";

$rowsAll = $db->query($sqlBase . " ORDER BY cost DESC")->fetchAll();

$rowsGap = array_values(array_filter($rowsAll, fn($r) => (int)$r['is_mapping_gap'] === 1));
$rowsInv = array_values(array_filter($rowsAll, fn($r) => (int)$r['is_mapping_gap'] === 0));

$sum = function(array $rows): array {
    $c = 0.0; $s = 0;
    foreach ($rows as $r) { $c += (float)$r['cost']; $s += (int)$r['shifts']; }
    return ['cost' => $c, 'shifts' => $s];
};
$totAll = $sum($rowsAll);
$totGap = $sum($rowsGap);
$totInv = $sum($rowsInv);

$rows = $tab === 'gap' ? $rowsGap : $rowsInv;

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">
    <a href="?tab=gap" style="background:<?= $tab==='gap' ? '#dc3545' : '#f8f9fa' ?>;color:<?= $tab==='gap' ? '#fff' : '#212529' ?>;padding:0.6rem 1rem;border-radius:6px;text-decoration:none;border:1px solid #dee2e6;min-width:200px;">
        <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;opacity:0.8;">Mapping gap</div>
        <div style="font-size:1.3rem;font-weight:600;">R<?= number_format($totGap['cost'], 0) ?></div>
        <div style="font-size:0.75rem;opacity:0.8;"><?= number_format($totGap['shifts']) ?> shift<?= $totGap['shifts'] === 1 ? '' : 's' ?> — client_id dumped to sentinel by ingest</div>
    </a>
    <a href="?tab=uninv" style="background:<?= $tab==='uninv' ? '#fd7e14' : '#f8f9fa' ?>;color:<?= $tab==='uninv' ? '#fff' : '#212529' ?>;padding:0.6rem 1rem;border-radius:6px;text-decoration:none;border:1px solid #dee2e6;min-width:200px;">
        <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;opacity:0.8;">Un-invoiced</div>
        <div style="font-size:1.3rem;font-weight:600;">R<?= number_format($totInv['cost'], 0) ?></div>
        <div style="font-size:0.75rem;opacity:0.8;"><?= number_format($totInv['shifts']) ?> shift<?= $totInv['shifts'] === 1 ? '' : 's' ?> — client known, no invoice for that month</div>
    </a>
    <div style="padding:0.6rem 1rem;border-radius:6px;border:1px solid #dee2e6;min-width:180px;background:#fff;">
        <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;color:#6c757d;">Combined</div>
        <div style="font-size:1.3rem;font-weight:600;">R<?= number_format($totAll['cost'], 0) ?></div>
        <div style="font-size:0.75rem;color:#6c757d;"><?= number_format($totAll['shifts']) ?> shift<?= $totAll['shifts'] === 1 ? '' : 's' ?></div>
    </div>
</div>

<?php if ($tab === 'gap'): ?>
<div style="background:#f8d7da;border-left:4px solid #dc3545;padding:1rem;margin-bottom:1.25rem;color:#721c24;">
    <strong>Mapping gap.</strong> Cost-side ingest couldn't identify the client, so the shift's <code>client_id</code> was dumped to the Unbilled sentinel. Fix: complete the missing client-role alias at <a href="<?= APP_URL ?>/admin/config/aliases?role=client" style="color:#721c24;text-decoration:underline;">/admin/config/aliases</a>, then re-run the Panel ingest — or open each patient below to set their bill-payer directly.
</div>
<?php else: ?>
<div style="background:#fff3cd;border-left:4px solid #fd7e14;padding:1rem;margin-bottom:1.25rem;color:#664d03;">
    <strong>Un-invoiced.</strong> Client is known and correct — there simply isn't a <code>client_revenue</code> row for that client in that month. Either (a) invoice hasn't been raised yet (timing), (b) care was intentionally uncharged, or (c) invoice is missing and needs raising. Each row links back to the patient profile.
</div>
<?php endif; ?>

<?php if (empty($rows)): ?>
    <p style="color:#6c757d;padding:2rem;text-align:center;">None. 🎉</p>
<?php else: ?>
<table class="report-table tch-data-table">
    <thead>
        <tr>
            <th style="width:22%;">Patient</th>
            <th class="center" style="width:10%;">TCH ID</th>
            <th class="center" style="width:12%;">Month</th>
            <th class="number" style="width:7%;" data-filterable="false">Shifts</th>
            <th class="number" style="width:7%;" data-filterable="false">Units</th>
            <th class="number" style="width:11%;" data-filterable="false">Cost (R)</th>
            <th class="center" style="width:11%;" data-filterable="false">First</th>
            <th class="center" style="width:11%;" data-filterable="false">Last</th>
            <th class="center" style="width:9%;" data-sortable="false" data-filterable="false">Action</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><strong><?= htmlspecialchars((string)($r['patient_name'] ?? '—')) ?></strong></td>
            <td class="center"><code><?= htmlspecialchars((string)($r['patient_tch_id'] ?? '')) ?></code></td>
            <td class="center"><?= htmlspecialchars((string)($r['month_label'] ?? '')) ?></td>
            <td class="number"><?= number_format((int)$r['shifts']) ?></td>
            <td class="number"><?= rtrim(rtrim(number_format((float)$r['units'], 1), '0'), '.') ?></td>
            <td class="number">R<?= number_format((float)$r['cost'], 0) ?></td>
            <td class="center"><?= htmlspecialchars((string)($r['first_date'] ?? '')) ?></td>
            <td class="center"><?= htmlspecialchars((string)($r['last_date'] ?? '')) ?></td>
            <td class="center">
            <?php if ($r['patient_person_id']): ?>
                <a href="<?= APP_URL ?>/admin/patients/<?= (int)$r['patient_person_id'] ?>" class="btn btn-sm btn-outline">Open →</a>
            <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr style="font-weight:600;border-top:2px solid #333;">
            <td colspan="3">Total</td>
            <td class="number"><?= number_format($tab === 'gap' ? $totGap['shifts'] : $totInv['shifts']) ?></td>
            <td></td>
            <td class="number">R<?= number_format($tab === 'gap' ? $totGap['cost'] : $totInv['cost'], 0) ?></td>
            <td colspan="3" class="center"></td>
        </tr>
    </tfoot>
</table>
<?php endif; ?>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
