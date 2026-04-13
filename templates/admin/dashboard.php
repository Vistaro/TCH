<?php
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';

require_once APP_ROOT . '/includes/currency.php';
refreshFxRatesIfStale(24);  // auto-refresh once a day on first dashboard hit

$db = getDB();

// ── Month filter ─────────────────────────────────────────────
$availableMonths = $db->query(
    "SELECT DISTINCT DATE_FORMAT(month_date, '%Y-%m') AS ym,
            DATE_FORMAT(month_date, '%b %Y') AS label
     FROM client_revenue WHERE month_date IS NOT NULL
     UNION
     SELECT DISTINCT DATE_FORMAT(roster_date, '%Y-%m'),
            DATE_FORMAT(roster_date, '%b %Y')
     FROM daily_roster
     ORDER BY ym"
)->fetchAll();

$selectedMonths = $_GET['months'] ?? [];
if (!is_array($selectedMonths)) $selectedMonths = [$selectedMonths];
$selectedMonths = array_filter($selectedMonths);
$hasFilter = !empty($selectedMonths);

$revWhere = '';
$rosterWhere = '';
$revParams = [];
$rosterParams = [];

if ($hasFilter) {
    $ph = implode(',', array_fill(0, count($selectedMonths), '?'));
    $revWhere = " AND DATE_FORMAT(cr.month_date, '%Y-%m') IN ($ph)";
    $rosterWhere = " AND DATE_FORMAT(dr.roster_date, '%Y-%m') IN ($ph)";
    $revParams = $selectedMonths;
    $rosterParams = $selectedMonths;
}

// ── Stats ────────────────────────────────────────────────────
$stats = [];

try {
    $cgTotal = (int)$db->query("SELECT COUNT(*) FROM caregivers")->fetchColumn();

    // Active = has shifts in selected months (or EVER if no filter)
    if ($hasFilter) {
        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT dr.caregiver_id) FROM daily_roster dr
             WHERE DATE_FORMAT(dr.roster_date, '%Y-%m') IN ($ph)"
        );
        $stmt->execute($selectedMonths);
    } else {
        $stmt = $db->query("SELECT COUNT(DISTINCT caregiver_id) FROM daily_roster");
    }
    $cgActive = (int)$stmt->fetchColumn();
    $cgInactive = $cgTotal - $cgActive;

    // Clients
    if ($hasFilter) {
        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT cr.client_id) FROM client_revenue cr
             WHERE DATE_FORMAT(cr.month_date, '%Y-%m') IN ($ph)"
        );
        $stmt->execute($selectedMonths);
        $clientCount = (int)$stmt->fetchColumn();
        $activeClients = $clientCount;
    } else {
        $clientCount = (int)$db->query("SELECT COUNT(*) FROM clients")->fetchColumn();
        $activeClients = (int)$db->query(
            "SELECT COUNT(DISTINCT cr.client_id) FROM client_revenue cr
             INNER JOIN clients c ON c.id = cr.client_id
             WHERE cr.month_date >= DATE_SUB(DATE_FORMAT(CURRENT_DATE, '%Y-%m-01'), INTERVAL 2 MONTH)"
        )->fetchColumn();
    }

    // Revenue
    $stmt = $db->prepare("SELECT COALESCE(SUM(cr.income), 0) FROM client_revenue cr WHERE 1=1 $revWhere");
    $stmt->execute($revParams);
    $totalRevenue = (float)$stmt->fetchColumn();

    // Wages
    $stmt = $db->prepare("SELECT COALESCE(SUM(dr.cost_rate), 0) FROM daily_roster dr WHERE dr.status = 'delivered' $rosterWhere");
    $stmt->execute($rosterParams);
    $totalWages = (float)$stmt->fetchColumn();

    // Shifts
    $stmt = $db->prepare("SELECT COUNT(*) FROM daily_roster dr WHERE 1=1 $rosterWhere");
    $stmt->execute($rosterParams);
    $rosterShifts = (int)$stmt->fetchColumn();

} catch (\PDOException $e) {
    $cgTotal = $cgActive = $cgInactive = $clientCount = $activeClients = 0;
    $totalRevenue = $totalWages = 0;
    $rosterShifts = 0;
}

$grossMargin = $totalRevenue - $totalWages;
$cgActivePct = $cgTotal > 0 ? round($cgActive / $cgTotal * 100) : 0;
$cgInactivePct = $cgTotal > 0 ? round($cgInactive / $cgTotal * 100) : 0;

require APP_ROOT . '/templates/layouts/admin.php';

// Build URL toggling a single month on/off
function monthToggleUrl(string $ym, array $selected): string {
    $new = in_array($ym, $selected)
        ? array_values(array_diff($selected, [$ym]))
        : array_merge($selected, [$ym]);
    if (empty($new)) return APP_URL . '/admin/dashboard';
    $params = [];
    foreach ($new as $m) $params[] = 'months[]=' . urlencode($m);
    return APP_URL . '/admin/dashboard?' . implode('&', $params);
}
?>

<div style="margin-bottom:1.5rem;">
    <div style="display:flex;flex-wrap:wrap;gap:0.4rem;align-items:center;">
        <span style="font-size:0.85rem;color:#666;margin-right:0.5rem;">Period:</span>
        <?php foreach ($availableMonths as $m):
            $isOn = in_array($m['ym'], $selectedMonths);
        ?>
        <a href="<?= monthToggleUrl($m['ym'], $selectedMonths) ?>"
           style="display:inline-block;padding:0.3rem 0.75rem;border-radius:20px;font-size:0.85rem;text-decoration:none;
                  <?= $isOn
                      ? 'background:#0d6efd;color:white;'
                      : 'background:#e9ecef;color:#495057;' ?>"
        ><?= htmlspecialchars($m['label']) ?></a>
        <?php endforeach; ?>
        <?php if ($hasFilter): ?>
        <a href="<?= APP_URL ?>/admin/dashboard"
           style="margin-left:0.5rem;font-size:0.8rem;color:#666;">Clear</a>
        <?php endif; ?>
    </div>
</div>

<div class="dash-cards">
    <div class="dash-card accent">
        <div class="dash-card-label">Caregivers</div>
        <div class="dash-card-value"><?= $cgTotal ?></div>
        <div class="dash-card-sub">Total registered</div>
    </div>
    <div class="dash-card accent" style="border-left:3px solid #198754;">
        <div class="dash-card-label">Active</div>
        <div class="dash-card-value"><?= $cgActive ?></div>
        <div class="dash-card-sub"><?= $cgActivePct ?>% of total</div>
    </div>
    <div class="dash-card accent" style="border-left:3px solid #dc3545;">
        <div class="dash-card-label">Inactive</div>
        <div class="dash-card-value"><?= $cgInactive ?></div>
        <div class="dash-card-sub"><?= $cgInactivePct ?>% of total</div>
    </div>
    <div class="dash-card accent">
        <div class="dash-card-label">Client Accounts</div>
        <div class="dash-card-value"><?= $clientCount ?></div>
        <div class="dash-card-sub"><?= $activeClients ?> active</div>
    </div>
</div>

<div class="dash-cards">
    <div class="dash-card accent">
        <div class="dash-card-label">Total Revenue</div>
        <div class="dash-card-value"><?= formatMoney((float)$totalRevenue) ?></div>
    </div>
    <div class="dash-card accent">
        <div class="dash-card-label">Total Wages</div>
        <div class="dash-card-value"><?= formatMoney((float)$totalWages) ?></div>
    </div>
    <div class="dash-card accent">
        <div class="dash-card-label">Gross Margin</div>
        <div class="dash-card-value"><?= formatMoney((float)$grossMargin) ?></div>
        <div class="dash-card-sub"><?= $totalRevenue > 0 ? round($grossMargin / $totalRevenue * 100) . '%' : '—' ?></div>
    </div>
    <div class="dash-card accent">
        <div class="dash-card-label" title="One row per shift delivered: caregiver × patient × day">
            Roster Shifts
            <i class="fas fa-info-circle" style="font-size:0.7em;color:#6c757d;cursor:help;"
               title="One row per shift delivered: caregiver × patient × day"></i>
        </div>
        <div class="dash-card-value"><?= number_format($rosterShifts) ?></div>
        <div class="dash-card-sub">Days of care delivered</div>
    </div>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
