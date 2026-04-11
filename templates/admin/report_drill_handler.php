<?php
/**
 * AJAX drill-down handler for the matrix reports.
 *
 *   GET /ajax/report-drill?report={earnings|billing|days}&entity_id=<int>&month=<YYYY-MM>
 *
 * Returns an HTML fragment for inline rendering below the matrix.
 * No JSON envelope — the caller just drops the response into an
 * innerHTML slot.
 *
 * Auth + permission gated per-report to match the parent report's
 * page-permission code so a user who can see the matrix can also
 * drill into its cells.
 */

if (!defined('APP_ROOT')) {
    die('Direct access not permitted.');
}

initSession();

if (!isLoggedIn()) {
    http_response_code(401);
    echo '<span style="color:#c00;">Not authenticated.</span>';
    exit;
}

$report   = (string)($_GET['report']   ?? '');
$entityId = (int)($_GET['entity_id']   ?? 0);
$month    = (string)($_GET['month']    ?? '');

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    http_response_code(400);
    echo '<span style="color:#c00;">Invalid month parameter.</span>';
    exit;
}
if ($entityId <= 0) {
    http_response_code(400);
    echo '<span style="color:#c00;">Invalid entity id.</span>';
    exit;
}

// Permission gate per report
$permissionMap = [
    'earnings' => 'reports_caregiver_earnings',
    'billing'  => 'reports_client_billing',
    'days'     => 'reports_days_worked',
];
if (!isset($permissionMap[$report])) {
    http_response_code(400);
    echo '<span style="color:#c00;">Unknown report type.</span>';
    exit;
}
if (!userCan($permissionMap[$report], 'read')) {
    http_response_code(403);
    echo '<span style="color:#c00;">You do not have permission to view this report.</span>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');

$db = getDB();

// Build the date window for the month
$monthStart = $month . '-01';
$monthEnd   = (new DateTimeImmutable($monthStart))
    ->modify('last day of this month')
    ->format('Y-m-d');

// ─── EARNINGS drill ───────────────────────────────────────────────────
// Show every daily_roster row for this caregiver in the month, with
// the client they served, day of week, and the daily rate.
if ($report === 'earnings' || $report === 'days') {
    $stmt = $db->prepare(
        "SELECT dr.roster_date, dr.day_of_week, dr.client_assigned, dr.daily_rate
         FROM daily_roster dr
         WHERE dr.caregiver_id = ?
           AND dr.roster_date >= ?
           AND dr.roster_date <= ?
         ORDER BY dr.roster_date"
    );
    $stmt->execute([$entityId, $monthStart, $monthEnd]);
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        echo '<p style="color:#999;">No roster records found for this caregiver in this month.</p>';
        exit;
    }

    $totalDays = count($rows);
    $totalRate = 0.0;
    foreach ($rows as $r) {
        $totalRate += (float)$r['daily_rate'];
    }

    echo '<table class="report-table tch-data-table" style="margin:0;">';
    echo '<thead><tr>';
    echo '<th>Date</th>';
    echo '<th>Day</th>';
    echo '<th>Client</th>';
    echo '<th class="number" data-no-filter>Rate (ZAR)</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['roster_date']) . '</td>';
        echo '<td>' . htmlspecialchars($r['day_of_week']) . '</td>';
        echo '<td>' . htmlspecialchars($r['client_assigned'] ?? '—') . '</td>';
        echo '<td class="number">R' . number_format((float)$r['daily_rate'], 0) . '</td>';
        echo '</tr>';
    }
    echo '<tr class="total-row">';
    echo '<td colspan="2">Total</td>';
    echo '<td>' . $totalDays . ' day' . ($totalDays === 1 ? '' : 's') . '</td>';
    echo '<td class="number"><strong>R' . number_format($totalRate, 0) . '</strong></td>';
    echo '</tr>';
    echo '</tbody></table>';
    exit;
}

// ─── BILLING drill ────────────────────────────────────────────────────
// Show every daily_roster row for this client in the month, with the
// caregiver who served them and the daily rate. The entity_id is a
// client_id so we need to look up the client_name to match against
// daily_roster.client_assigned (which stores a name, not a FK).
if ($report === 'billing') {
    $client = $db->prepare('SELECT client_name FROM clients WHERE id = ?');
    $client->execute([$entityId]);
    $clientName = $client->fetchColumn();
    if (!$clientName) {
        echo '<p style="color:#999;">Client #' . (int)$entityId . ' not found.</p>';
        exit;
    }

    // Match on exact name OR trimmed "- monthly" suffix, to match the
    // existing drill-matching logic from the old flat report.
    $baseName = preg_replace('/[-\s]*monthly$/i', '', $clientName);

    $stmt = $db->prepare(
        "SELECT dr.roster_date, dr.day_of_week, dr.caregiver_name, dr.daily_rate
         FROM daily_roster dr
         WHERE (dr.client_assigned = ? OR dr.client_assigned = ?)
           AND dr.roster_date >= ?
           AND dr.roster_date <= ?
         ORDER BY dr.roster_date, dr.caregiver_name"
    );
    $stmt->execute([$clientName, trim($baseName), $monthStart, $monthEnd]);
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        echo '<p style="color:#999;">No roster records found for this client in this month.</p>';
        exit;
    }

    $totalDays = count($rows);
    $totalRate = 0.0;
    foreach ($rows as $r) {
        $totalRate += (float)$r['daily_rate'];
    }

    echo '<table class="report-table tch-data-table" style="margin:0;">';
    echo '<thead><tr>';
    echo '<th>Date</th>';
    echo '<th>Day</th>';
    echo '<th>Caregiver</th>';
    echo '<th class="number" data-no-filter>Rate (ZAR)</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['roster_date']) . '</td>';
        echo '<td>' . htmlspecialchars($r['day_of_week']) . '</td>';
        echo '<td>' . htmlspecialchars($r['caregiver_name'] ?? '—') . '</td>';
        echo '<td class="number">R' . number_format((float)$r['daily_rate'], 0) . '</td>';
        echo '</tr>';
    }
    echo '<tr class="total-row">';
    echo '<td colspan="2">Total</td>';
    echo '<td>' . $totalDays . ' day' . ($totalDays === 1 ? '' : 's') . '</td>';
    echo '<td class="number"><strong>R' . number_format($totalRate, 0) . '</strong></td>';
    echo '</tr>';
    echo '</tbody></table>';
    exit;
}

// Should never reach here
http_response_code(400);
echo '<span style="color:#c00;">Unhandled report type.</span>';
