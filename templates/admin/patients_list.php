<?php
$pageTitle = 'Patients';
$activeNav = 'patients';

$db = getDB();
$canCreate = userCan('patient_view', 'create');

$showArchived = !empty($_GET['show_archived']);

$sql = "SELECT pt.person_id, pt.patient_name, pt.client_id,
               p.tch_id, p.full_name, p.archived_at,
               c_person.full_name AS client_name, c.account_number,
               (SELECT COUNT(*) FROM daily_roster dr WHERE dr.client_id = pt.person_id) AS shift_count,
               (SELECT MAX(dr2.roster_date) FROM daily_roster dr2 WHERE dr2.client_id = pt.person_id) AS last_shift
        FROM patients pt
        JOIN persons p ON p.id = pt.person_id
        JOIN clients c ON c.id = pt.client_id
        LEFT JOIN persons c_person ON c_person.id = c.person_id";
$sql .= $showArchived ? '' : ' WHERE p.archived_at IS NULL';
$sql .= ' ORDER BY p.full_name';
$rows = $db->query($sql)->fetchAll();

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <p style="color:#666;font-size:0.85rem;margin:0;"><?= count($rows) ?> patient<?= count($rows) !== 1 ? 's' : '' ?><?= $showArchived ? ' (incl. archived)' : '' ?></p>
    <div>
        <a href="<?= APP_URL ?>/admin/patients<?= $showArchived ? '' : '?show_archived=1' ?>" class="btn btn-outline btn-sm">
            <?= $showArchived ? 'Hide archived' : 'Show archived' ?>
        </a>
        <?php if ($canCreate): ?>
            <a href="<?= APP_URL ?>/admin/patients/new" class="btn btn-primary btn-sm">+ New patient</a>
        <?php endif; ?>
    </div>
</div>

<?php $totShifts = array_sum(array_map(fn($r) => (int)$r['shift_count'], $rows)); ?>
<div class="report-table-wrap">
<table class="report-table tch-data-table">
    <thead><tr>
        <th>TCH ID</th><th>Patient Name</th><th>Display Name</th>
        <th>Client (Billed To)</th><th>Account</th>
        <th>Shifts</th><th>Last Shift</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $rowStyle = $r['archived_at'] ? 'opacity:0.6;background:#f8f8f8;' : '';
    ?>
    <tr style="cursor:pointer;<?= $rowStyle ?>"
        onclick="window.location='<?= APP_URL ?>/admin/patients/<?= (int)$r['person_id'] ?>';">
        <td><code><?= htmlspecialchars($r['tch_id']) ?></code></td>
        <td>
            <a href="<?= APP_URL ?>/admin/patients/<?= (int)$r['person_id'] ?>" onclick="event.stopPropagation();">
                <?= htmlspecialchars($r['patient_name'] ?: $r['full_name']) ?>
            </a>
            <?php if ($r['archived_at']): ?><span style="font-size:0.75rem;color:#856404;">(archived)</span><?php endif; ?>
        </td>
        <td style="color:#666;"><?= $r['patient_name'] && $r['patient_name'] !== $r['full_name'] ? htmlspecialchars($r['full_name']) : '' ?></td>
        <td>
            <a href="<?= APP_URL ?>/admin/clients/<?= (int)$r['client_id'] ?>" onclick="event.stopPropagation();">
                <?= htmlspecialchars($r['client_name'] ?? '') ?>
            </a>
        </td>
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
