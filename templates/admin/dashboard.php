<?php
$pageTitle = 'Dashboard';
initSession();
$user = currentUser();

// Try to load live stats from DB, fall back to static placeholders
$stats = [
    'caregivers'    => 140,
    'placed'        => 0,
    'clients'       => 64,
    'active_clients'=> 0,
    'roster_shifts' => 0,
    'pending_names' => 0,
];

try {
    $db = getDB();
    $stats['caregivers']     = (int)$db->query('SELECT COUNT(*) FROM caregivers')->fetchColumn();
    $stats['placed']         = (int)$db->query("SELECT COUNT(*) FROM caregivers WHERE status = 'Placed'")->fetchColumn();
    $stats['clients']        = (int)$db->query('SELECT COUNT(*) FROM clients')->fetchColumn();
    $stats['active_clients'] = (int)$db->query("SELECT COUNT(*) FROM clients WHERE status = 'Active'")->fetchColumn();
    $stats['roster_shifts']  = (int)$db->query('SELECT COUNT(*) FROM daily_roster')->fetchColumn();
    $stats['pending_names']  = (int)$db->query('SELECT COUNT(*) FROM name_lookup WHERE approved = 0')->fetchColumn();
} catch (\PDOException $e) {
    // DB not yet set up — use placeholders
}
?>
<?php require APP_ROOT . '/templates/layouts/header.php'; ?>

<div class="admin-layout">
    <aside class="admin-sidebar">
        <div class="admin-sidebar-brand">
            <h2><span class="brand-tch">TCH</span> Admin</h2>
            <small>Placement Management</small>
        </div>
        <ul class="admin-nav">
            <li><a href="<?= APP_URL ?>/admin" class="active">&#9632; Dashboard</a></li>
            <li><a href="#">&#9632; Caregivers</a></li>
            <li><a href="#">&#9632; Clients</a></li>
            <li><a href="#">&#9632; Roster</a></li>
            <li><a href="#">&#9632; Revenue</a></li>
            <li><a href="#">&#9632; Name Reconciliation</a></li>
            <li style="margin-top:auto;border-top:1px solid rgba(255,255,255,0.1);padding-top:0.5rem;">
                <a href="<?= APP_URL ?>/logout">&#9632; Sign Out</a>
            </li>
        </ul>
    </aside>

    <main class="admin-content">
        <div class="admin-header">
            <h1>Dashboard</h1>
            <div class="admin-user">
                <span><?= htmlspecialchars($user['full_name'] ?? $user['username']) ?></span>
                <div class="admin-user-avatar"><?= strtoupper(substr($user['full_name'] ?? $user['username'], 0, 1)) ?></div>
            </div>
        </div>

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
                <div class="dash-card-label">Roster Shifts</div>
                <div class="dash-card-value"><?= number_format($stats['roster_shifts']) ?></div>
                <div class="dash-card-sub">Total recorded</div>
            </div>
            <div class="dash-card accent">
                <div class="dash-card-label">Names Pending Review</div>
                <div class="dash-card-value"><?= $stats['pending_names'] ?></div>
                <div class="dash-card-sub">Awaiting approval</div>
            </div>
        </div>

        <div class="card" style="padding:2rem;">
            <h3 style="margin-bottom:1rem;">Getting Started</h3>
            <p style="color:#666;line-height:1.8;">
                The data layer is built and ready. Once the database is populated via the ingestion script,
                this dashboard will show live figures. The sidebar sections (Caregivers, Clients, Roster,
                Revenue, Name Reconciliation) will be built out in the coming phases.
            </p>
        </div>
    </main>
</div>
