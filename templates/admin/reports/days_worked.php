<?php
$pageTitle = 'Days Worked by Caregiver';
$activeNav = 'report-days-worked';

$db = getDB();

// Get filter options
$caregivers = $db->query(
    "SELECT DISTINCT caregiver_name FROM daily_roster ORDER BY caregiver_name"
)->fetchAll(PDO::FETCH_COLUMN);

$clients = $db->query(
    "SELECT DISTINCT client_assigned FROM daily_roster ORDER BY client_assigned"
)->fetchAll(PDO::FETCH_COLUMN);

$tranches = $db->query(
    "SELECT DISTINCT tranche FROM caregivers WHERE tranche IS NOT NULL AND tranche != '' ORDER BY tranche"
)->fetchAll(PDO::FETCH_COLUMN);

// Apply filters
$where = [];
$params = [];

$filterCaregiver = $_GET['caregiver'] ?? '';
$filterClient    = $_GET['client'] ?? '';
$filterTranche   = $_GET['tranche'] ?? '';
$filterFrom      = $_GET['from'] ?? '';
$filterTo        = $_GET['to'] ?? '';

if ($filterCaregiver !== '') {
    $where[] = 'dr.caregiver_name = ?';
    $params[] = $filterCaregiver;
}
if ($filterClient !== '') {
    $where[] = 'dr.client_assigned = ?';
    $params[] = $filterClient;
}
if ($filterTranche !== '') {
    $where[] = 'cg.tranche = ?';
    $params[] = $filterTranche;
}
if ($filterFrom !== '') {
    $where[] = 'dr.roster_date >= ?';
    $params[] = $filterFrom . '-01';
}
if ($filterTo !== '') {
    // Last day of selected month
    $where[] = 'dr.roster_date < ? + INTERVAL 1 MONTH';
    $params[] = $filterTo . '-01';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Summary: days worked per caregiver per month
$sql = "SELECT dr.caregiver_name,
               DATE_FORMAT(dr.roster_date, '%Y-%m') AS month_key,
               DATE_FORMAT(dr.roster_date, '%b %Y') AS month_label,
               MIN(dr.roster_date) AS month_date,
               COUNT(*) AS days_worked,
               COUNT(DISTINCT dr.client_assigned) AS clients_served,
               SUM(dr.daily_rate) AS total_value,
               ROUND(AVG(dr.daily_rate), 0) AS avg_rate,
               cg.tranche
        FROM daily_roster dr
        LEFT JOIN caregivers cg ON dr.caregiver_id = cg.id
        $whereSQL
        GROUP BY dr.caregiver_name, month_key, cg.tranche
        ORDER BY dr.caregiver_name, month_key";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Drill-down: individual days
$drillData = [];
foreach ($rows as $idx => $r) {
    $drillWhere = ['dr.caregiver_name = ?', "DATE_FORMAT(dr.roster_date, '%Y-%m') = ?"];
    $drillParams = [$r['caregiver_name'], $r['month_key']];

    if ($filterClient !== '') {
        $drillWhere[] = 'dr.client_assigned = ?';
        $drillParams[] = $filterClient;
    }

    $drillSQL = "SELECT dr.roster_date, dr.day_of_week, dr.client_assigned, dr.daily_rate
                 FROM daily_roster dr
                 WHERE " . implode(' AND ', $drillWhere) . "
                 ORDER BY dr.roster_date";

    $drillStmt = $db->prepare($drillSQL);
    $drillStmt->execute($drillParams);
    $drillData[$idx] = $drillStmt->fetchAll();
}

$totalDays  = array_sum(array_column($rows, 'days_worked'));
$totalValue = array_sum(array_column($rows, 'total_value'));

require APP_ROOT . '/templates/layouts/admin.php';
?>

<form method="GET" action="<?= APP_URL ?>/admin/reports/days-worked" class="report-filters">
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
        <label>Client</label>
        <select name="client">
            <option value="">All Clients</option>
            <?php foreach ($clients as $cl): ?>
                <option value="<?= htmlspecialchars($cl) ?>" <?= $filterClient === $cl ? 'selected' : '' ?>><?= htmlspecialchars($cl) ?></option>
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
        <a href="<?= APP_URL ?>/admin/reports/days-worked" class="btn btn-outline btn-sm">Clear</a>
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
                <th class="number">Clients</th>
                <th class="number">Avg Rate</th>
                <th class="number">Total Value (ZAR)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="7" style="text-align:center;color:#999;padding:2rem;">No records found.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $idx => $r): ?>
                    <?php $drills = $drillData[$idx] ?? []; ?>
                    <tr class="<?= $drills ? 'drill-toggle' : '' ?>" data-drill="drill-dw-<?= $idx ?>">
                        <td><?= htmlspecialchars($r['caregiver_name']) ?></td>
                        <td><?= htmlspecialchars($r['tranche'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($r['month_label']) ?></td>
                        <td class="number"><?= $r['days_worked'] ?></td>
                        <td class="number"><?= $r['clients_served'] ?></td>
                        <td class="number">R<?= number_format((float)$r['avg_rate'], 0) ?></td>
                        <td class="number">R<?= number_format((float)$r['total_value'], 0) ?></td>
                    </tr>
                    <?php foreach ($drills as $d): ?>
                        <tr class="drill-row" data-parent="drill-dw-<?= $idx ?>">
                            <td><?= htmlspecialchars($d['roster_date']) ?></td>
                            <td></td>
                            <td><?= htmlspecialchars($d['day_of_week']) ?></td>
                            <td></td>
                            <td><?= htmlspecialchars($d['client_assigned']) ?></td>
                            <td></td>
                            <td class="number">R<?= number_format((float)$d['daily_rate'], 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="3">Total</td>
                    <td class="number"><?= number_format($totalDays) ?></td>
                    <td></td>
                    <td></td>
                    <td class="number">R<?= number_format($totalValue, 0) ?></td>
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
