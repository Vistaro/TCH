<?php
/**
 * Unbilled Care drill-down — /admin/unbilled-care
 *
 * Surfaces the cost of care delivered to patients whose bill-payer
 * isn't known (no matching Panel invoice), grouped by patient and by
 * month. Every row links straight to the patient profile so the admin
 * can set the real bill-paying client.
 */
$pageTitle = 'Unbilled Care';
$activeNav = 'unbilled-care';
$db = getDB();

// Unbilled Care sentinel
$umbrellaId = (int)$db->query(
    "SELECT id FROM persons WHERE tch_id = 'TCH-UNBILLED' LIMIT 1"
)->fetchColumn();

$rows = [];
if ($umbrellaId) {
    $stmt = $db->prepare(
        "SELECT r.patient_person_id,
                COALESCE(pp.full_name, r.client_assigned) AS patient_name,
                pp.tch_id AS patient_tch_id,
                COUNT(*) AS shifts,
                SUM(r.units) AS units,
                SUM(r.units * COALESCE(r.cost_rate, 0)) AS cost,
                MIN(r.roster_date) AS first_date,
                MAX(r.roster_date) AS last_date
         FROM daily_roster r
    LEFT JOIN persons pp ON pp.id = r.patient_person_id
         WHERE r.client_id = ? AND r.status = 'delivered'
         GROUP BY r.patient_person_id, patient_name, patient_tch_id
         ORDER BY cost DESC"
    );
    $stmt->execute([$umbrellaId]);
    $rows = $stmt->fetchAll();
}

$totalCost = 0.0; $totalShifts = 0;
foreach ($rows as $r) { $totalCost += (float)$r['cost']; $totalShifts += (int)$r['shifts']; }

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="background:#f8d7da;border-left:4px solid #dc3545;padding:1rem;margin-bottom:1.25rem;color:#721c24;">
    <h3 style="margin:0 0 0.4rem;">⚠ Care delivered with no invoice raised</h3>
    <p style="margin:0;">
        <strong><?= number_format($totalShifts) ?> shifts</strong> delivered, totalling
        <strong>R<?= number_format($totalCost, 0) ?></strong> of caregiver cost —
        no matching invoice in the Revenue Panel. Each patient below needs either
        (a) its real bill-paying client linked on the patient profile, OR
        (b) an invoice raised in the next Panel update, OR
        (c) marked as intentionally uncharged (family favour / introductory /
        staff benefit) with a reason note.
    </p>
</div>

<?php if (empty($rows)): ?>
    <p style="color:#6c757d;padding:2rem;text-align:center;">No unbilled care at the moment. 🎉</p>
<?php else: ?>
<table class="report-table tch-data-table">
    <thead>
        <tr>
            <th style="width:22%;">Patient</th>
            <th style="width:10%;">TCH ID</th>
            <th class="number" style="width:7%;" data-filterable="false">Shifts</th>
            <th class="number" style="width:7%;" data-filterable="false">Units</th>
            <th class="number" style="width:11%;" data-filterable="false">Cost (R)</th>
            <th style="width:11%;" data-filterable="false">First shift</th>
            <th style="width:11%;" data-filterable="false">Last shift</th>
            <th style="width:21%;" data-sortable="false" data-filterable="false">Action</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><strong><?= htmlspecialchars((string)($r['patient_name'] ?? '—')) ?></strong></td>
            <td><code><?= htmlspecialchars((string)($r['patient_tch_id'] ?? '')) ?></code></td>
            <td class="number"><?= number_format((int)$r['shifts']) ?></td>
            <td class="number"><?= rtrim(rtrim(number_format((float)$r['units'], 1), '0'), '.') ?></td>
            <td class="number">R<?= number_format((float)$r['cost'], 0) ?></td>
            <td><?= htmlspecialchars((string)($r['first_date'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($r['last_date'] ?? '')) ?></td>
            <td>
            <?php if ($r['patient_person_id']): ?>
                <a href="<?= APP_URL ?>/admin/patients/<?= (int)$r['patient_person_id'] ?>" class="btn btn-sm btn-outline">Open patient →</a>
            <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr style="font-weight:600;border-top:2px solid #721c24;background:#f8d7da;color:#721c24;">
            <td colspan="2">Total</td>
            <td class="number"><?= number_format($totalShifts) ?></td>
            <td></td>
            <td class="number">R<?= number_format($totalCost, 0) ?></td>
            <td colspan="3"></td>
        </tr>
    </tfoot>
</table>
<?php endif; ?>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
