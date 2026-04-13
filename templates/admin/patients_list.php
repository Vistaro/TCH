<?php
$pageTitle = 'Patients';
$activeNav = 'patients';

$db = getDB();

$sql = "SELECT pt.person_id, pt.patient_name, pt.client_id,
               p.tch_id, p.full_name,
               c_person.full_name AS client_name, c.account_number,
               (SELECT COUNT(*) FROM daily_roster dr WHERE dr.client_id = pt.person_id) AS shift_count,
               (SELECT MAX(dr2.roster_date) FROM daily_roster dr2 WHERE dr2.client_id = pt.person_id) AS last_shift
        FROM patients pt
        JOIN persons p ON p.id = pt.person_id
        JOIN clients c ON c.id = pt.client_id
        LEFT JOIN persons c_person ON c_person.id = c.person_id
        ORDER BY p.full_name";
$rows = $db->query($sql)->fetchAll();

require APP_ROOT . '/templates/layouts/admin.php';
?>

<p style="color:#666;font-size:0.85rem;margin-bottom:1rem;"><?= count($rows) ?> patient<?= count($rows) !== 1 ? 's' : '' ?></p>

<?php $totShifts = array_sum(array_map(fn($r) => (int)$r['shift_count'], $rows)); ?>
<div class="report-table-wrap">
<table class="report-table tch-data-table">
    <thead><tr>
        <th>TCH ID</th><th>Patient Name</th><th>Display Name</th>
        <th>Client (Billed To)</th><th>Account</th>
        <th>Shifts</th><th>Last Shift</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td><code><?= htmlspecialchars($r['tch_id']) ?></code></td>
        <td><?= htmlspecialchars($r['patient_name'] ?: $r['full_name']) ?></td>
        <td style="color:#666;"><?= $r['patient_name'] && $r['patient_name'] !== $r['full_name'] ? htmlspecialchars($r['full_name']) : '' ?></td>
        <td><?= htmlspecialchars($r['client_name'] ?? '') ?></td>
        <td><code><?= htmlspecialchars($r['account_number'] ?? '') ?></code></td>
        <td class="number"><?= (int)$r['shift_count'] ?></td>
        <td><?= $r['last_shift'] ? htmlspecialchars($r['last_shift']) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr class="totals-row">
            <td colspan="5">Total — <?= count($rows) ?> patient<?= count($rows) !== 1 ? 's' : '' ?></td>
            <td class="number"><?= number_format($totShifts) ?></td>
            <td></td>
        </tr>
    </tfoot>
</table>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
