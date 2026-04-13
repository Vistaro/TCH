<?php
$pageTitle = 'Caregivers';
$activeNav = 'caregivers';

$db = getDB();

$filterCohort = $_GET['cohort'] ?? '';
$filterStatus = $_GET['status'] ?? '';

$where = [];
$params = [];
if ($filterCohort) { $where[] = 's.cohort = ?'; $params[] = $filterCohort; }
if ($filterStatus) { $where[] = 'cg.status = ?'; $params[] = $filterStatus; }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT p.id, p.tch_id, p.full_name, p.known_as, p.mobile, p.email,
               cg.day_rate, cg.status AS cg_status,
               s.cohort, s.student_id,
               (SELECT COUNT(*) FROM daily_roster dr WHERE dr.caregiver_id = p.id) AS shift_count,
               (SELECT COALESCE(SUM(dr2.cost_rate), 0) FROM daily_roster dr2 WHERE dr2.caregiver_id = p.id) AS total_earned
        FROM caregivers cg
        JOIN persons p ON p.id = cg.person_id
        LEFT JOIN students s ON s.person_id = cg.person_id
        $whereSQL
        ORDER BY p.full_name";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$cohorts = $db->query("SELECT DISTINCT cohort FROM students WHERE cohort IS NOT NULL AND cohort != '' ORDER BY cohort")->fetchAll(PDO::FETCH_COLUMN);

require APP_ROOT . '/templates/layouts/admin.php';
?>

<form method="GET" class="report-filters" style="margin-bottom:1rem;">
    <div style="display:flex;gap:0.75rem;align-items:end;">
        <div>
            <label>Cohort</label>
            <select name="cohort" onchange="this.form.submit()" class="form-control">
                <option value="">All Cohorts</option>
                <?php foreach ($cohorts as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $filterCohort === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Status</label>
            <select name="status" onchange="this.form.submit()" class="form-control">
                <option value="">All</option>
                <option value="available" <?= $filterStatus === 'available' ? 'selected' : '' ?>>Available</option>
                <option value="placed" <?= $filterStatus === 'placed' ? 'selected' : '' ?>>Placed</option>
                <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div style="color:#666;font-size:0.85rem;"><?= count($rows) ?> caregiver<?= count($rows) !== 1 ? 's' : '' ?></div>
    </div>
</form>

<?php
// Column totals
$totShifts = 0; $totEarned = 0;
foreach ($rows as $r) {
    $totShifts += (int)$r['shift_count'];
    $totEarned += (float)$r['total_earned'];
}
?>
<div class="report-table-scroll">
<table class="report-table tch-data-table">
    <thead><tr>
        <th>TCH ID</th><th>Name</th><th>Known As</th><th>Cohort</th>
        <th>Status</th><th>Day Rate</th><th>Shifts</th><th>Total Earned</th>
        <th>Mobile</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td><code><?= htmlspecialchars($r['tch_id']) ?></code></td>
        <td><a href="<?= APP_URL ?>/admin/students/<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['full_name']) ?></a></td>
        <td><?= htmlspecialchars($r['known_as'] ?? '') ?></td>
        <td><?= htmlspecialchars($r['cohort'] ?? 'N/K') ?></td>
        <td><span class="badge badge-<?= $r['cg_status'] === 'placed' ? 'success' : ($r['cg_status'] === 'available' ? 'info' : 'muted') ?>"><?= ucfirst($r['cg_status']) ?></span></td>
        <td class="number"><?= $r['day_rate'] ? 'R' . number_format((float)$r['day_rate'], 0) : '—' ?></td>
        <td class="number"><?= (int)$r['shift_count'] ?></td>
        <td class="number"><?= (float)$r['total_earned'] > 0 ? 'R' . number_format((float)$r['total_earned'], 0) : '—' ?></td>
        <td><?= htmlspecialchars($r['mobile'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr class="totals-row">
            <td colspan="6">Total — <?= count($rows) ?> caregiver<?= count($rows) !== 1 ? 's' : '' ?></td>
            <td class="number"><?= number_format($totShifts) ?></td>
            <td class="number">R<?= number_format($totEarned, 0) ?></td>
            <td></td>
        </tr>
    </tfoot>
</table>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
