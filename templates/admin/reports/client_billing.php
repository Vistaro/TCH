<?php
$pageTitle = 'Client Billing by Month';
$activeNav = 'report-client-billing';

$db = getDB();

// Get filter options
$clients = $db->query(
    "SELECT DISTINCT client_name FROM client_revenue ORDER BY client_name"
)->fetchAll(PDO::FETCH_COLUMN);

// Apply filters
$where = [];
$params = [];

$filterClient = $_GET['client'] ?? '';
$filterFrom   = $_GET['from'] ?? '';
$filterTo     = $_GET['to'] ?? '';

if ($filterClient !== '') {
    $where[] = 'cr.client_name = ?';
    $params[] = $filterClient;
}
if ($filterFrom !== '') {
    $where[] = 'cr.month_date >= ?';
    $params[] = $filterFrom . '-01';
}
if ($filterTo !== '') {
    $where[] = 'cr.month_date <= ?';
    $params[] = $filterTo . '-01';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT cr.id, cr.client_name, cr.month, cr.month_date, cr.income, cr.expense, cr.margin, cr.margin_pct,
               c.account_number, c.status AS client_status
        FROM client_revenue cr
        LEFT JOIN clients c ON cr.client_id = c.id
        $whereSQL
        ORDER BY cr.client_name, cr.month_date";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Drill-down: which caregivers worked for this client that month
$drillData = [];
foreach ($rows as $r) {
    // Match client name in roster (try exact and partial)
    $clientName = $r['client_name'];
    $baseName = preg_replace('/[-\s]*monthly$/i', '', $clientName);

    $drillStmt = $db->prepare(
        "SELECT roster_date, day_of_week, caregiver_name, daily_rate
         FROM daily_roster
         WHERE (client_assigned = ? OR client_assigned = ?)
           AND roster_date >= ? AND roster_date < ? + INTERVAL 1 MONTH
         ORDER BY roster_date, caregiver_name"
    );
    $drillStmt->execute([$clientName, trim($baseName), $r['month_date'], $r['month_date']]);
    $drillData[$r['id']] = $drillStmt->fetchAll();
}

$totalIncome  = array_sum(array_column($rows, 'income'));
$totalExpense = array_sum(array_column($rows, 'expense'));
$totalMargin  = $totalIncome - $totalExpense;

require APP_ROOT . '/templates/layouts/admin.php';
?>

<form method="GET" action="<?= APP_URL ?>/admin/reports/client-billing" class="report-filters">
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
        <label>From</label>
        <input type="month" name="from" value="<?= htmlspecialchars($filterFrom) ?>">
    </div>
    <div class="filter-group">
        <label>To</label>
        <input type="month" name="to" value="<?= htmlspecialchars($filterTo) ?>">
    </div>
    <button type="submit" class="btn btn-primary">Filter</button>
    <?php if ($where): ?>
        <a href="<?= APP_URL ?>/admin/reports/client-billing" class="btn btn-outline btn-sm">Clear</a>
    <?php endif; ?>
</form>

<div class="report-table-wrap">
    <table class="report-table">
        <thead>
            <tr>
                <th>Client</th>
                <th>Account</th>
                <th>Month</th>
                <th class="number">Income (ZAR)</th>
                <th class="number">Expense (ZAR)</th>
                <th class="number">Margin (ZAR)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="6" style="text-align:center;color:#999;padding:2rem;">No records found.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <?php $drills = $drillData[$r['id']] ?? []; ?>
                    <tr class="<?= $drills ? 'drill-toggle' : '' ?>" data-drill="drill-cr-<?= $r['id'] ?>">
                        <td><?= htmlspecialchars($r['client_name']) ?></td>
                        <td><?= htmlspecialchars($r['account_number'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($r['month']) ?></td>
                        <td class="number">R<?= number_format((float)$r['income'], 0) ?></td>
                        <td class="number">R<?= number_format((float)$r['expense'], 0) ?></td>
                        <td class="number"><?php
                            $margin = (float)$r['income'] - (float)$r['expense'];
                            echo ($margin >= 0 ? '' : '-') . 'R' . number_format(abs($margin), 0);
                        ?></td>
                    </tr>
                    <?php foreach ($drills as $d): ?>
                        <tr class="drill-row" data-parent="drill-cr-<?= $r['id'] ?>">
                            <td><?= htmlspecialchars($d['roster_date']) ?></td>
                            <td><?= htmlspecialchars($d['day_of_week']) ?></td>
                            <td><?= htmlspecialchars($d['caregiver_name']) ?></td>
                            <td></td>
                            <td></td>
                            <td class="number">R<?= number_format((float)$d['daily_rate'], 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="3">Total</td>
                    <td class="number">R<?= number_format($totalIncome, 0) ?></td>
                    <td class="number">R<?= number_format($totalExpense, 0) ?></td>
                    <td class="number">R<?= number_format($totalMargin, 0) ?></td>
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
