<?php
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';

$db = getDB();

// ── Month filter (multi-select) ──────────────────────────────
// Available months from revenue + roster combined
$availableMonths = $db->query(
    "SELECT DISTINCT DATE_FORMAT(month_date, '%Y-%m') AS ym,
            DATE_FORMAT(month_date, '%b %Y') AS label
     FROM client_revenue
     WHERE month_date IS NOT NULL
     UNION
     SELECT DISTINCT DATE_FORMAT(roster_date, '%Y-%m'),
            DATE_FORMAT(roster_date, '%b %Y')
     FROM daily_roster
     ORDER BY ym"
)->fetchAll();

$selectedMonths = $_GET['months'] ?? [];
if (!is_array($selectedMonths)) $selectedMonths = [$selectedMonths];
$selectedMonths = array_filter($selectedMonths);

// Build date WHERE clauses
$revWhere = '';
$rosterWhere = '';
$revParams = [];
$rosterParams = [];
$filterLabel = 'All months';

if (!empty($selectedMonths)) {
    $placeholders = implode(',', array_fill(0, count($selectedMonths), '?'));
    $revWhere = " AND DATE_FORMAT(cr.month_date, '%Y-%m') IN ($placeholders)";
    $rosterWhere = " AND DATE_FORMAT(dr.roster_date, '%Y-%m') IN ($placeholders)";
    $revParams = $selectedMonths;
    $rosterParams = $selectedMonths;
    $filterLabel = count($selectedMonths) . ' month' . (count($selectedMonths) !== 1 ? 's' : '') . ' selected';
}

// ── Stats ────────────────────────────────────────────────────
$stats = [
    'cg_total'      => 0,
    'cg_active'     => 0,
    'cg_inactive'   => 0,
    'clients'       => 0,
    'active_clients'=> 0,
    'roster_shifts' => 0,
    'total_revenue' => 0,
    'total_wages'   => 0,
];

try {
    $stats['cg_total'] = (int)$db->query("SELECT COUNT(*) FROM caregivers")->fetchColumn();

    // Active caregivers = those with roster shifts in selected period (or last 3 months if no filter)
    if (empty($selectedMonths)) {
        $stats['cg_active'] = (int)$db->query(
            "SELECT COUNT(DISTINCT dr.caregiver_id) FROM daily_roster dr
             WHERE dr.roster_date >= DATE_SUB(DATE_FORMAT(CURRENT_DATE, '%Y-%m-01'), INTERVAL 3 MONTH)"
        )->fetchColumn();
    } else {
        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT dr.caregiver_id) FROM daily_roster dr
             WHERE DATE_FORMAT(dr.roster_date, '%Y-%m') IN ($placeholders)"
        );
        $stmt->execute($selectedMonths);
        $stats['cg_active'] = (int)$stmt->fetchColumn();
    }
    $stats['cg_inactive'] = $stats['cg_total'] - $stats['cg_active'];

    // Clients
    if (empty($selectedMonths)) {
        $stats['clients'] = (int)$db->query("SELECT COUNT(*) FROM clients")->fetchColumn();
        $stats['active_clients'] = (int)$db->query(
            "SELECT COUNT(DISTINCT cr.client_id) FROM client_revenue cr
             INNER JOIN clients c ON c.id = cr.client_id
             WHERE cr.month_date >= DATE_SUB(DATE_FORMAT(CURRENT_DATE, '%Y-%m-01'), INTERVAL 2 MONTH)"
        )->fetchColumn();
    } else {
        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT cr.client_id) FROM client_revenue cr
             WHERE DATE_FORMAT(cr.month_date, '%Y-%m') IN ($placeholders)"
        );
        $stmt->execute($selectedMonths);
        $stats['clients'] = (int)$stmt->fetchColumn();
        $stats['active_clients'] = $stats['clients'];
    }

    // Revenue
    $stmt = $db->prepare("SELECT COALESCE(SUM(cr.income), 0) FROM client_revenue cr WHERE 1=1 $revWhere");
    $stmt->execute($revParams);
    $stats['total_revenue'] = (float)$stmt->fetchColumn();

    // Wages (from roster cost_rate)
    $stmt = $db->prepare("SELECT COALESCE(SUM(dr.cost_rate), 0) FROM daily_roster dr WHERE dr.status = 'delivered' $rosterWhere");
    $stmt->execute($rosterParams);
    $stats['total_wages'] = (float)$stmt->fetchColumn();

    // Shifts
    $stmt = $db->prepare("SELECT COUNT(*) FROM daily_roster dr WHERE 1=1 $rosterWhere");
    $stmt->execute($rosterParams);
    $stats['roster_shifts'] = (int)$stmt->fetchColumn();

} catch (\PDOException $e) {
    // DB not available
}

$grossMargin = $stats['total_revenue'] - $stats['total_wages'];

require APP_ROOT . '/templates/layouts/admin.php';
?>

<form method="GET" style="margin-bottom:1.5rem;">
    <div style="display:flex;gap:0.75rem;align-items:end;">
        <div>
            <label style="font-size:0.85rem;color:#666;">Filter by month</label>
            <select name="months[]" multiple size="<?= min(count($availableMonths), 6) ?>"
                    class="form-control" style="min-width:200px;"
                    onchange="this.form.submit()">
                <?php foreach ($availableMonths as $m): ?>
                <option value="<?= htmlspecialchars($m['ym']) ?>"
                    <?= in_array($m['ym'], $selectedMonths) ? 'selected' : '' ?>
                ><?= htmlspecialchars($m['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="font-size:0.85rem;color:#666;padding-bottom:0.3rem;">
            <?= $filterLabel ?>
            <?php if (!empty($selectedMonths)): ?>
                · <a href="<?= APP_URL ?>/admin/dashboard">Clear</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="dash-cards">
    <div class="dash-card accent">
        <div class="dash-card-label">Caregivers</div>
        <div class="dash-card-value"><?= $stats['cg_total'] ?></div>
        <div class="dash-card-sub">
            <?= $stats['cg_active'] ?> active (3mths) · <?= $stats['cg_inactive'] ?> inactive
        </div>
    </div>
    <div class="dash-card accent">
        <div class="dash-card-label">Client Accounts</div>
        <div class="dash-card-value"><?= $stats['clients'] ?></div>
        <div class="dash-card-sub"><?= $stats['active_clients'] ?> active</div>
    </div>
    <div class="dash-card accent">
        <div class="dash-card-label">Total Revenue</div>
        <div class="dash-card-value">R<?= number_format($stats['total_revenue'], 0) ?></div>
        <div class="dash-card-sub"><?= $filterLabel ?></div>
    </div>
    <div class="dash-card accent">
        <div class="dash-card-label">Gross Margin</div>
        <div class="dash-card-value">R<?= number_format($grossMargin, 0) ?></div>
        <div class="dash-card-sub">Revenue less caregiver wages</div>
    </div>
</div>

<div class="dash-cards">
    <div class="dash-card accent">
        <div class="dash-card-label">Total Wages</div>
        <div class="dash-card-value">R<?= number_format($stats['total_wages'], 0) ?></div>
        <div class="dash-card-sub"><?= $filterLabel ?></div>
    </div>
    <div class="dash-card accent">
        <div class="dash-card-label">Roster Shifts</div>
        <div class="dash-card-value"><?= number_format($stats['roster_shifts']) ?></div>
        <div class="dash-card-sub"><?= $filterLabel ?></div>
    </div>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
