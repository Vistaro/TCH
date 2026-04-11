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
//
// NOTE on the 78% miss rate:
// Only ~22% of daily_roster rows have client_id set. The other 76%
// (1224 of 1619 at time of writing) have client_id = NULL and a
// string client_assigned that uses a different naming convention from
// client_revenue. A full fix requires a client name reconciliation
// pass equivalent to the existing name_lookup table for caregivers —
// that's tracked as FR-0069 on the Hub ("Client name reconciliation").
// Until that lands this drill does the best it can:
//   1. Match on daily_roster.client_id = ?  (covers the 22% that ARE linked)
//   2. Fall back to exact client_assigned = clients.client_name
//   3. Fall back to LIKE 'first-word%' in case the roster name is a
//      shortened variant
//   4. If nothing matches, return an honest empty state explaining
//      the mismatch and pointing at FR-0069.
if ($report === 'billing') {
    $client = $db->prepare('SELECT client_name FROM clients WHERE id = ?');
    $client->execute([$entityId]);
    $clientName = $client->fetchColumn();
    if (!$clientName) {
        echo '<p style="color:#999;">Client #' . (int)$entityId . ' not found.</p>';
        exit;
    }

    // Build a tolerant match: client_id = ? OR client_assigned in a set
    // of reasonable name variants.
    $baseName    = trim((string)preg_replace('/[-\s]*monthly$/i', '', $clientName));
    $baseWeekly  = trim((string)preg_replace('/[-\s]*weekly$/i', '', $clientName));
    $firstWord   = '';
    if (preg_match('/^([^\s\/\-]+)/', $clientName, $m)) {
        $firstWord = $m[1];
    }

    $stmt = $db->prepare(
        "SELECT dr.roster_date, dr.day_of_week, dr.caregiver_name, dr.client_assigned, dr.daily_rate,
                CASE
                    WHEN dr.client_id = ?                                    THEN 'id'
                    WHEN dr.client_assigned = ?                              THEN 'exact'
                    WHEN dr.client_assigned = ?                              THEN 'monthly'
                    WHEN dr.client_assigned = ?                              THEN 'weekly'
                    WHEN ? <> '' AND dr.client_assigned LIKE CONCAT(?, '%')  THEN 'first-word'
                    ELSE NULL
                END AS match_type
         FROM daily_roster dr
         WHERE (
                   dr.client_id = ?
                OR dr.client_assigned = ?
                OR dr.client_assigned = ?
                OR dr.client_assigned = ?
                OR (? <> '' AND dr.client_assigned LIKE CONCAT(?, '%'))
               )
           AND dr.roster_date >= ?
           AND dr.roster_date <= ?
         ORDER BY dr.roster_date, dr.caregiver_name"
    );
    $stmt->execute([
        // CASE expressions
        $entityId, $clientName, $baseName, $baseWeekly, $firstWord, $firstWord,
        // WHERE clause
        $entityId, $clientName, $baseName, $baseWeekly, $firstWord, $firstWord,
        // Date range
        $monthStart, $monthEnd,
    ]);
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

        echo '<div style="color:#555;line-height:1.6;">';
        echo '<p><strong>No matching roster entries for this client in this month.</strong></p>';
        if ($billingRow) {
            echo '<p>What we <em>do</em> know, from the billing ledger (<code>client_revenue</code>):</p>';
            echo '<ul style="margin:0.25rem 0 0.75rem 1rem;">';
            echo '<li>Client: <strong>' . htmlspecialchars($billingRow['client_name']) . '</strong></li>';
            echo '<li>Month: ' . htmlspecialchars($billingRow['month']) . '</li>';
            echo '<li>Income: <strong>R' . number_format((float)$billingRow['income'], 0) . '</strong></li>';
            if (isset($billingRow['expense'])) {
                echo '<li>Expense: R' . number_format((float)$billingRow['expense'], 0) . '</li>';
            }
            if (isset($billingRow['margin'])) {
                echo '<li>Margin: R' . number_format((float)$billingRow['margin'], 0) . '</li>';
            }
            echo '</ul>';
        }
        echo '<p style="color:#666;font-size:0.9em;">';
        echo '<strong>Why:</strong> the billing system (<code>client_revenue</code>) and the roster system '
            . '(<code>daily_roster</code>) use different client name conventions, and only ~22% of roster rows '
            . 'are currently linked to a canonical client record. For the other ~78%, no day-level breakdown '
            . 'can be shown until a name reconciliation pass matches them up.';
        echo '</p>';
        echo '<p style="color:#666;font-size:0.9em;">';
        echo 'This is tracked as <strong>FR-0069</strong> on the Nexus Hub — "Client name reconciliation". ';
        echo 'Once that lands, every billing client will have its matching roster rows linked and this drill ';
        echo 'will fill in completely.';
        echo '</p>';
        echo '</div>';
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
