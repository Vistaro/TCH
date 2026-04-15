<?php
$pageTitle = 'Clients';
$activeNav = 'clients';

$db = getDB();
$canCreate = userCan('client_view', 'create');

// Show archived rows? default: no
$showArchived = !empty($_GET['show_archived']);

$sql = "SELECT c.id, c.account_number, c.billing_entity,
               p.tch_id, p.full_name AS client_name, p.archived_at,
               pt.patient_name,
               (SELECT COUNT(*) FROM client_revenue cr WHERE cr.client_id = c.id) AS revenue_rows,
               (SELECT COALESCE(SUM(cr2.income), 0) FROM client_revenue cr2 WHERE cr2.client_id = c.id) AS total_billed,
               (SELECT COUNT(*) FROM daily_roster dr WHERE dr.client_id = c.id) AS shift_count,
               (SELECT COALESCE(SUM(dr2.cost_rate), 0) FROM daily_roster dr2 WHERE dr2.client_id = c.id) AS total_cost
        FROM clients c
        LEFT JOIN persons p ON p.id = c.person_id
        LEFT JOIN patients pt ON pt.person_id = c.person_id";
$sql .= $showArchived ? '' : ' WHERE p.archived_at IS NULL';
$sql .= ' ORDER BY p.full_name';
$rows = $db->query($sql)->fetchAll();

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <p style="color:#666;font-size:0.85rem;margin:0;"><?= count($rows) ?> client<?= count($rows) !== 1 ? 's' : '' ?><?= $showArchived ? ' (incl. archived)' : '' ?></p>
    <div>
        <a href="<?= APP_URL ?>/admin/clients<?= $showArchived ? '' : '?show_archived=1' ?>" class="btn btn-outline btn-sm">
            <?= $showArchived ? 'Hide archived' : 'Show archived' ?>
        </a>
        <?php if ($canCreate): ?>
            <a href="<?= APP_URL ?>/admin/clients/new" class="btn btn-primary btn-sm">+ New client</a>
        <?php endif; ?>
    </div>
</div>

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
<div class="report-table-wrap">
<table class="report-table tch-data-table">
    <thead><tr>
        <th class="center">Account</th><th>Client Name</th><th>Patient Name</th>
        <th class="center">Entity</th><th class="number">Revenue Months</th><th class="number">Total Billed</th>
        <th class="number">Shifts</th><th class="number">Total Cost</th><th class="number">Gross Margin</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $margin = (float)$r['total_billed'] - (float)$r['total_cost'];
        $rowStyle = $r['archived_at'] ? 'opacity:0.6;background:#f8f8f8;' : '';
    ?>
    <tr style="cursor:pointer;<?= $rowStyle ?>"
        onclick="window.location='<?= APP_URL ?>/admin/clients/<?= (int)$r['id'] ?>';">
        <td class="center"><code><?= htmlspecialchars($r['account_number'] ?? '') ?></code></td>
        <td>
            <a href="<?= APP_URL ?>/admin/clients/<?= (int)$r['id'] ?>" onclick="event.stopPropagation();">
                <?= htmlspecialchars($r['client_name'] ?? 'Company (no person)') ?>
            </a>
            <?php if ($r['archived_at']): ?> <span style="font-size:0.75rem;color:#856404;">(archived)</span><?php endif; ?>
        </td>
        <td><?= htmlspecialchars($r['patient_name'] ?? '') ?: '<span style="color:#ccc;">same</span>' ?></td>
        <td class="center"><?= htmlspecialchars($r['billing_entity'] ?? '') ?></td>
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
