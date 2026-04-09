<?php
$pageTitle = 'Caregiver Earnings by Month';
$activeNav = 'report-cg-earnings';

$db = getDB();

// Get filter options
$caregivers = $db->query(
    "SELECT DISTINCT cc.caregiver_name FROM caregiver_costs cc ORDER BY cc.caregiver_name"
)->fetchAll(PDO::FETCH_COLUMN);

$tranches = $db->query(
    "SELECT DISTINCT tranche FROM caregivers WHERE tranche IS NOT NULL AND tranche != '' ORDER BY tranche"
)->fetchAll(PDO::FETCH_COLUMN);

// Apply filters
$where = [];
$params = [];

$filterCaregiver = $_GET['caregiver'] ?? '';
$filterTranche   = $_GET['tranche'] ?? '';
$filterFrom      = $_GET['from'] ?? '';
$filterTo        = $_GET['to'] ?? '';

if ($filterCaregiver !== '') {
    $where[] = 'cc.caregiver_name = ?';
    $params[] = $filterCaregiver;
}
if ($filterTranche !== '') {
    $where[] = 'cg.tranche = ?';
    $params[] = $filterTranche;
}
if ($filterFrom !== '') {
    $where[] = 'cc.month_date >= ?';
    $params[] = $filterFrom . '-01';
}
if ($filterTo !== '') {
    $where[] = 'cc.month_date <= ?';
    $params[] = $filterTo . '-01';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Summary query
$sql = "SELECT cc.caregiver_name, cc.month, cc.month_date, cc.amount, cc.days_worked, cc.daily_rate,
               cg.tranche, cc.id AS cost_id
        FROM caregiver_costs cc
        LEFT JOIN caregivers cg ON cc.caregiver_id = cg.id
        $whereSQL
        ORDER BY cc.caregiver_name, cc.month_date";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Get drill-down data (daily roster) for all matching caregivers/months
$drillData = [];
foreach ($rows as $r) {
    $drillStmt = $db->prepare(
        "SELECT roster_date, day_of_week, client_assigned, daily_rate
         FROM daily_roster
         WHERE caregiver_name = ? AND roster_date >= ? AND roster_date < ? + INTERVAL 1 MONTH
         ORDER BY roster_date"
    );
    $drillStmt->execute([$r['caregiver_name'], $r['month_date'], $r['month_date']]);
    $drillData[$r['cost_id']] = $drillStmt->fetchAll();
}

// Totals
$totalAmount = array_sum(array_column($rows, 'amount'));
$totalDays   = array_sum(array_column($rows, 'days_worked'));

require APP_ROOT . '/templates/layouts/admin.php';
?>

<form method="GET" action="<?= APP_URL ?>/admin/reports/caregiver-earnings" class="report-filters">
    <div class="filter-group">
        <label>Caregiver</label>
        <select name="caregiver">
            <option value="">All Caregivers</option>
            <?php foreach ($caregivers as $cg): ?>
                <option value="<?= htmlspecialchars($cg) ?>" <?= $filterCaregiver === $cg ? 'selected' : '' ?>><?= htmlspecialchars($cg) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>Tranche</label>
        <select name="tranche">
            <option value="">All Tranches</option>
            <?php foreach ($tranches as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $filterTranche === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>From</label>
        <input type="month" name="from" value="<?= htmlspecialchars($filterFrom) ?>">
    </div>
    <div class="filter-group">
        <label>To</label>
        <input type="month" name="to" value="<?= htmlspecialchars($filterTo) ?>">
    </div>
    <button type="submit" class="btn btn-primary">Filter</button>
    <?php if ($where): ?>
        <a href="<?= APP_URL ?>/admin/reports/caregiver-earnings" class="btn btn-outline btn-sm">Clear</a>
    <?php endif; ?>
</form>

<div class="report-table-wrap">
    <table class="report-table">
        <thead>
            <tr>
                <th>Caregiver</th>
                <th>Tranche</th>
                <th>Month</th>
                <th class="number">Days</th>
                <th class="number">Daily Rate</th>
                <th class="number">Amount (ZAR)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="6" style="text-align:center;color:#999;padding:2rem;">No records found.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <?php $drills = $drillData[$r['cost_id']] ?? []; ?>
                    <tr class="<?= $drills ? 'drill-toggle' : '' ?>" data-drill="drill-<?= $r['cost_id'] ?>">
                        <td><?= htmlspecialchars($r['caregiver_name']) ?></td>
                        <td><?= htmlspecialchars($r['tranche'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($r['month']) ?></td>
                        <td class="number"><?= $r['days_worked'] ?? '—' ?></td>
                        <td class="number"><?= $r['daily_rate'] ? 'R' . number_format((float)$r['daily_rate'], 0) : '—' ?></td>
                        <td class="number">R<?= number_format((float)$r['amount'], 0) ?></td>
                    </tr>
                    <?php foreach ($drills as $d): ?>
                        <tr class="drill-row" data-parent="drill-<?= $r['cost_id'] ?>">
                            <td><?= htmlspecialchars($d['roster_date']) ?></td>
                            <td></td>
                            <td><?= htmlspecialchars($d['day_of_week']) ?></td>
                            <td></td>
                            <td><?= htmlspecialchars($d['client_assigned']) ?></td>
                            <td class="number">R<?= number_format((float)$d['daily_rate'], 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="3">Total</td>
                    <td class="number"><?= number_format($totalDays) ?></td>
                    <td></td>
                    <td class="number">R<?= number_format($totalAmount, 0) ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
document.querySelectorAll('.drill-toggle').forEach(function(row) {
    row.addEventListener('click', function() {
        var id = this.dataset.drill;
        var drills = document.querySelectorAll('[data-parent="' + id + '"]');
        var isOpen = this.classList.toggle('open');
        drills.forEach(function(dr) {
            dr.classList.toggle('show', isOpen);
        });
    });
});
</script>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
