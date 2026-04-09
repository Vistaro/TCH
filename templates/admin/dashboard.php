<?php
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';

$db = getDB();

$stats = [
    'caregivers'    => 0,
    'placed'        => 0,
    'clients'       => 0,
    'active_clients'=> 0,
    'roster_shifts' => 0,
    'pending_names' => 0,
    'total_revenue' => 0,
    'total_cost'    => 0,
];

try {
    $stats['caregivers']     = (int)$db->query('SELECT COUNT(*) FROM caregivers')->fetchColumn();
    $stats['placed']         = (int)$db->query("SELECT COUNT(*) FROM caregivers WHERE status = 'Placed'")->fetchColumn();
    $stats['clients']        = (int)$db->query('SELECT COUNT(*) FROM clients')->fetchColumn();
    $stats['active_clients'] = (int)$db->query("SELECT COUNT(*) FROM clients WHERE status = 'Active'")->fetchColumn();
    $stats['roster_shifts']  = (int)$db->query('SELECT COUNT(*) FROM daily_roster')->fetchColumn();
    $stats['pending_names']  = (int)$db->query('SELECT COUNT(*) FROM name_lookup WHERE approved = 0')->fetchColumn();
    $stats['total_revenue']  = (float)$db->query('SELECT COALESCE(SUM(income), 0) FROM client_revenue')->fetchColumn();
    $stats['total_cost']     = (float)$db->query('SELECT COALESCE(SUM(amount), 0) FROM caregiver_costs')->fetchColumn();
} catch (\PDOException $e) {
    // DB not available
}

$grossMargin = $stats['total_revenue'] - $stats['total_cost'];

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div class="dash-cards">
    <div class="dash-card accent">
        <div class="dash-card-label">Total Caregivers</div>
        <div class="dash-card-value"><?= $stats['caregivers'] ?></div>
        <div class="dash-card-sub"><?= $stats['placed'] ?> currently placed</div>
    </div>
    <div class="dash-card accent">
        <div class="dash-card-label">Client Accounts</div>
        <div class="dash-card-value"><?= $stats['clients'] ?></div>
        <div class="dash-card-sub"><?= $stats['active_clients'] ?> active</div>
    </div>
    <div class="dash-card accent">
        <div class="dash-card-label">Total Revenue</div>
        <div class="dash-card-value">R<?= number_format($stats['total_revenue'], 0) ?></div>
        <div class="dash-card-sub">All months combined</div>
    </div>
    <div class="dash-card accent">
        <div class="dash-card-label">Gross Margin</div>
        <div class="dash-card-value">R<?= number_format($grossMargin, 0) ?></div>
        <div class="dash-card-sub">Revenue less caregiver costs</div>
    </div>
</div>

<div class="dash-cards">
    <div class="dash-card accent">
        <div class="dash-card-label">Roster Shifts</div>
        <div class="dash-card-value"><?= number_format($stats['roster_shifts']) ?></div>
        <div class="dash-card-sub">Total recorded</div>
    </div>
    <div class="dash-card accent">
        <div class="dash-card-label">Names Pending Review</div>
        <div class="dash-card-value"><?= $stats['pending_names'] ?></div>
        <div class="dash-card-sub"><a href="<?= APP_URL ?>/admin/names">Review now</a></div>
    </div>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
