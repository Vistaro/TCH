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

// ─── EARNINGS / DAYS drill ────────────────────────────────────────────
// Show every daily_roster row for this caregiver in the month, with
// the client they served, day of week, and the daily rate.
// Match on caregiver_id (reliable — every daily_roster row has it set)
// with a caregiver_name fallback as defence-in-depth.
if ($report === 'earnings' || $report === 'days') {
    // Look up the caregiver's canonical name in case we need the fallback
    $cgName = $db->prepare('SELECT full_name FROM persons WHERE id = ?');
    $cgName->execute([$entityId]);
    $caregiverName = (string)($cgName->fetchColumn() ?: '');

    $stmt = $db->prepare(
        "SELECT dr.roster_date, dr.day_of_week, dr.client_assigned, dr.daily_rate
         FROM daily_roster dr
         WHERE (dr.caregiver_id = ? OR (dr.caregiver_id IS NULL AND dr.caregiver_name = ?))
           AND dr.roster_date >= ?
           AND dr.roster_date <= ?
         ORDER BY dr.roster_date"
    );
    $stmt->execute([$entityId, $caregiverName, $monthStart, $monthEnd]);
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
// caregiver who served them and the daily rate.
// Post name-normalisation (v0.9.12+): every daily_roster row has a
// valid client_id FK. No fuzzy name matching needed — just JOIN on id.
if ($report === 'billing') {
    $client = $db->prepare(
        "SELECT p.full_name FROM clients c
         JOIN persons p ON p.id = c.person_id
         WHERE c.id = ?"
    );
    $client->execute([$entityId]);
    $clientName = $client->fetchColumn();
    if (!$clientName) {
        echo '<p style="color:#999;">Client #' . (int)$entityId . ' not found.</p>';
        exit;
    }

    $stmt = $db->prepare(
        "SELECT dr.roster_date, dr.day_of_week,
                COALESCE(p_cg.full_name, dr.caregiver_name) AS caregiver_name,
                dr.client_assigned,
                dr.cost_rate, dr.bill_rate, dr.daily_rate,
                dr.status AS shift_status,
                dr.source_ref
         FROM daily_roster dr
         LEFT JOIN persons p_cg ON p_cg.id = dr.caregiver_id
         WHERE dr.client_id = ?
           AND dr.roster_date >= ?
           AND dr.roster_date <= ?
         ORDER BY dr.roster_date, caregiver_name"
    );
    $stmt->execute([$entityId, $monthStart, $monthEnd]);
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        // Honest empty state — this is the 78% case. Show why, not just "none".
        // Pull the billing record itself so the user at least sees what
        // they were drilling into.
        $billing = $db->prepare(
            'SELECT client_name, income, expense, margin, month FROM client_revenue
             WHERE client_id = ? AND month_date >= ? AND month_date <= ?'
        );
        $billing->execute([$entityId, $monthStart, $monthEnd]);
        $billingRow = $billing->fetch();

        echo '<p style="color:#999;">No roster shifts recorded for ' . htmlspecialchars($clientName) . ' in this month.</p>';
        exit;
    }

    $totalDays = count($rows);
    $totalCost = 0.0;
    $totalBill = 0.0;
    foreach ($rows as $r) {
        $totalCost += (float)($r['cost_rate'] ?? $r['daily_rate'] ?? 0);
        $totalBill += (float)($r['bill_rate'] ?? 0);
    }

    echo '<table class="report-table tch-data-table" style="margin:0;">';
    echo '<thead><tr>';
    echo '<th>Date</th>';
    echo '<th>Day</th>';
    echo '<th>Caregiver</th>';
    echo '<th class="number" data-no-filter>Cost (R)</th>';
    echo '<th class="number" data-no-filter>Bill (R)</th>';
    echo '<th>Status</th>';
    echo '<th style="font-size:0.75rem;color:#999;">Source</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $cost = (float)($r['cost_rate'] ?? $r['daily_rate'] ?? 0);
        $bill = (float)($r['bill_rate'] ?? 0);
        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['roster_date']) . '</td>';
        echo '<td>' . htmlspecialchars($r['day_of_week']) . '</td>';
        echo '<td>' . htmlspecialchars($r['caregiver_name'] ?? '—') . '</td>';
        echo '<td class="number">' . ($cost > 0 ? 'R' . number_format($cost, 0) : '—') . '</td>';
        echo '<td class="number">' . ($bill > 0 ? 'R' . number_format($bill, 0) : '—') . '</td>';
        echo '<td><span class="badge badge-' . ($r['shift_status'] === 'delivered' ? 'success' : 'muted') . '">' . ucfirst($r['shift_status'] ?? 'delivered') . '</span></td>';
        echo '<td style="font-size:0.75rem;color:#999;">' . htmlspecialchars($r['source_ref'] ?? '') . '</td>';
        echo '</tr>';
    }
    echo '<tr class="total-row">';
    echo '<td colspan="2">Total</td>';
    echo '<td>' . $totalDays . ' day' . ($totalDays === 1 ? '' : 's') . '</td>';
    echo '<td class="number"><strong>R' . number_format($totalCost, 0) . '</strong></td>';
    echo '<td class="number"><strong>' . ($totalBill > 0 ? 'R' . number_format($totalBill, 0) : '—') . '</strong></td>';
    echo '<td colspan="2"></td>';
    echo '</tr>';
    echo '</tbody></table>';
    exit;
}

// Should never reach here
http_response_code(400);
echo '<span style="color:#c00;">Unhandled report type.</span>';
