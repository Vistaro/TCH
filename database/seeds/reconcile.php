<?php
/**
 * TCH Placements — Data Reconciliation Check
 * Verifies that totals balance across all tables.
 * Run: php database/seeds/reconcile.php
 */

declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__, 2));

require APP_ROOT . '/includes/config.php';
require APP_ROOT . '/includes/db.php';

$db = getDB();

echo "══════════════════════════════════════════\n";
echo "  TCH DATA RECONCILIATION\n";
echo "══════════════════════════════════════════\n\n";

$issues = [];

// ── 1. Grand totals: caregiver_costs vs daily_roster ──────

echo "── 1. CAREGIVER PAY: costs table vs daily roster ──\n\n";

$cc = $db->query("SELECT SUM(amount) AS total_amount, SUM(days_worked) AS total_days FROM caregiver_costs")->fetch();
$dr = $db->query("SELECT SUM(daily_rate) AS total_value, COUNT(*) AS total_shifts FROM daily_roster")->fetch();

$costTotal  = (float)$cc['total_amount'];
$costDays   = (int)$cc['total_days'];
$rosterTotal = (float)$dr['total_value'];
$rosterDays  = (int)$dr['total_shifts'];

echo "  caregiver_costs:  R" . number_format($costTotal, 2) . "  ({$costDays} days)\n";
echo "  daily_roster:     R" . number_format($rosterTotal, 2) . "  ({$rosterDays} shifts)\n";
$diff = $costTotal - $rosterTotal;
$dayDiff = $costDays - $rosterDays;
echo "  Amount diff:      R" . number_format($diff, 2) . "\n";
echo "  Days diff:        {$dayDiff}\n";
if (abs($diff) > 0.01 || $dayDiff !== 0) {
    $issues[] = "Caregiver costs vs roster: amount diff R" . number_format($diff, 2) . ", days diff {$dayDiff}";
}
echo "\n";

// ── 2. By-month: costs vs roster ──────────────────────────

echo "── 2. BY-MONTH: costs vs roster ──\n\n";

$ccByMonth = $db->query(
    "SELECT month, month_date, SUM(amount) AS total, SUM(days_worked) AS days
     FROM caregiver_costs GROUP BY month, month_date ORDER BY month_date"
)->fetchAll();

$drByMonth = $db->query(
    "SELECT DATE_FORMAT(roster_date, '%b %Y') AS month,
            SUM(daily_rate) AS total, COUNT(*) AS days
     FROM daily_roster GROUP BY month ORDER BY MIN(roster_date)"
)->fetchAll();

$drIdx = [];
foreach ($drByMonth as $r) {
    $drIdx[$r['month']] = $r;
}

printf("  %-12s %12s %6s %12s %6s %12s %6s\n", "Month", "Cost Amt", "Days", "Roster Amt", "Days", "Amt Diff", "Day Diff");
printf("  %-12s %12s %6s %12s %6s %12s %6s\n", "────────────", "────────────", "──────", "────────────", "──────", "────────────", "────────");

foreach ($ccByMonth as $c) {
    $r = $drIdx[$c['month']] ?? ['total' => 0, 'days' => 0];
    $aDiff = (float)$c['total'] - (float)$r['total'];
    $dDiff = (int)$c['days'] - (int)$r['days'];
    $flag = (abs($aDiff) > 0.01 || $dDiff !== 0) ? ' *' : '';
    printf("  %-12s %12s %6d %12s %6d %12s %6d%s\n",
        $c['month'],
        'R' . number_format((float)$c['total'], 0), (int)$c['days'],
        'R' . number_format((float)$r['total'], 0), (int)$r['days'],
        'R' . number_format($aDiff, 0), $dDiff, $flag
    );
    if ($flag) {
        $issues[] = "{$c['month']}: cost vs roster diff R" . number_format($aDiff, 0) . " / {$dDiff} days";
    }
}
echo "\n";

// ── 3. By-caregiver: costs vs roster ──────────────────────

echo "── 3. BY-CAREGIVER: costs vs roster (mismatches only) ──\n\n";

$ccByCg = $db->query(
    "SELECT caregiver_name, SUM(amount) AS total, SUM(days_worked) AS days
     FROM caregiver_costs GROUP BY caregiver_name ORDER BY caregiver_name"
)->fetchAll();

$drByCg = $db->query(
    "SELECT caregiver_name, SUM(daily_rate) AS total, COUNT(*) AS days
     FROM daily_roster GROUP BY caregiver_name ORDER BY caregiver_name"
)->fetchAll();

$drCgIdx = [];
foreach ($drByCg as $r) {
    $drCgIdx[$r['caregiver_name']] = $r;
}

$cgMismatches = 0;
foreach ($ccByCg as $c) {
    $r = $drCgIdx[$c['caregiver_name']] ?? ['total' => 0, 'days' => 0];
    $aDiff = (float)$c['total'] - (float)$r['total'];
    $dDiff = (int)$c['days'] - (int)$r['days'];
    if (abs($aDiff) > 0.01 || $dDiff !== 0) {
        if ($cgMismatches === 0) {
            printf("  %-30s %10s %6s %10s %6s %10s %6s\n", "Caregiver", "Cost", "Days", "Roster", "Days", "AmtDiff", "DayDiff");
            printf("  %-30s %10s %6s %10s %6s %10s %6s\n", "──────────────────────────────", "──────────", "──────", "──────────", "──────", "──────────", "───────");
        }
        printf("  %-30s %10s %6d %10s %6d %10s %6d\n",
            substr($c['caregiver_name'], 0, 30),
            'R' . number_format((float)$c['total'], 0), (int)$c['days'],
            'R' . number_format((float)$r['total'], 0), (int)$r['days'],
            'R' . number_format($aDiff, 0), $dDiff
        );
        $cgMismatches++;
        $issues[] = "{$c['caregiver_name']}: cost R" . number_format((float)$c['total'],0) . " vs roster R" . number_format((float)$r['total'],0);
    }
}
echo $cgMismatches === 0 ? "  All caregivers match.\n" : "  {$cgMismatches} caregivers with mismatches.\n";
echo "\n";

// ── 4. Caregivers in roster but not in costs (and vice versa) ──

echo "── 4. ORPHAN CHECKS ──\n\n";

$inRosterNotCosts = $db->query(
    "SELECT DISTINCT caregiver_name FROM daily_roster
     WHERE caregiver_name NOT IN (SELECT DISTINCT caregiver_name FROM caregiver_costs)"
)->fetchAll(PDO::FETCH_COLUMN);

$inCostsNotRoster = $db->query(
    "SELECT DISTINCT caregiver_name FROM caregiver_costs
     WHERE caregiver_name NOT IN (SELECT DISTINCT caregiver_name FROM daily_roster)"
)->fetchAll(PDO::FETCH_COLUMN);

echo "  In roster but NOT in costs: " . count($inRosterNotCosts) . "\n";
foreach ($inRosterNotCosts as $n) {
    echo "    - $n\n";
    $issues[] = "Orphan: $n in roster but not in costs";
}
echo "  In costs but NOT in roster: " . count($inCostsNotRoster) . "\n";
foreach ($inCostsNotRoster as $n) {
    echo "    - $n\n";
    $issues[] = "Orphan: $n in costs but not in roster";
}
echo "\n";

// ── 5. CLIENT REVENUE: summary vs margin_summary ──────────

echo "── 5. CLIENT REVENUE vs MARGIN SUMMARY ──\n\n";

$crByMonth = $db->query(
    "SELECT month, month_date, SUM(income) AS revenue, SUM(expense) AS expense
     FROM client_revenue GROUP BY month, month_date ORDER BY month_date"
)->fetchAll();

$msByMonth = $db->query(
    "SELECT month, month_date, total_revenue, total_cost, gross_margin
     FROM margin_summary ORDER BY month_date"
)->fetchAll();

$msIdx = [];
foreach ($msByMonth as $m) {
    $msIdx[$m['month']] = $m;
}

printf("  %-12s %12s %12s %12s %12s %12s\n", "Month", "CR Revenue", "CR Expense", "MS Revenue", "MS Cost", "Rev Diff");
printf("  %-12s %12s %12s %12s %12s %12s\n", "────────────", "────────────", "────────────", "────────────", "────────────", "────────────");

foreach ($crByMonth as $cr) {
    $ms = $msIdx[$cr['month']] ?? ['total_revenue' => 0, 'total_cost' => 0];
    $revDiff = (float)$cr['revenue'] - (float)$ms['total_revenue'];
    $flag = abs($revDiff) > 0.01 ? ' *' : '';
    printf("  %-12s %12s %12s %12s %12s %12s%s\n",
        $cr['month'],
        'R' . number_format((float)$cr['revenue'], 0),
        'R' . number_format((float)$cr['expense'], 0),
        'R' . number_format((float)$ms['total_revenue'], 0),
        'R' . number_format((float)$ms['total_cost'], 0),
        'R' . number_format($revDiff, 0), $flag
    );
    if ($flag) {
        $issues[] = "{$cr['month']}: CR revenue vs margin_summary diff R" . number_format($revDiff, 0);
    }
}
echo "\n";

// ── 6. MARGIN: revenue - costs = margin summary ───────────

echo "── 6. MARGIN CROSS-CHECK ──\n\n";

$totalRevenue = (float)$db->query("SELECT SUM(income) FROM client_revenue")->fetchColumn();
$totalCgCost  = (float)$db->query("SELECT SUM(amount) FROM caregiver_costs")->fetchColumn();
$totalMsRev   = (float)$db->query("SELECT SUM(total_revenue) FROM margin_summary")->fetchColumn();
$totalMsCost  = (float)$db->query("SELECT SUM(total_cost) FROM margin_summary")->fetchColumn();
$totalMsMargin = (float)$db->query("SELECT SUM(gross_margin) FROM margin_summary")->fetchColumn();

echo "  Total revenue (client_revenue):   R" . number_format($totalRevenue, 2) . "\n";
echo "  Total cost (caregiver_costs):     R" . number_format($totalCgCost, 2) . "\n";
echo "  Computed margin:                  R" . number_format($totalRevenue - $totalCgCost, 2) . "\n";
echo "  margin_summary total_revenue:     R" . number_format($totalMsRev, 2) . "\n";
echo "  margin_summary total_cost:        R" . number_format($totalMsCost, 2) . "\n";
echo "  margin_summary gross_margin:      R" . number_format($totalMsMargin, 2) . "\n";

$marginDiff = ($totalRevenue - $totalCgCost) - $totalMsMargin;
if (abs($marginDiff) > 0.01) {
    echo "  MARGIN DIFF: R" . number_format($marginDiff, 2) . " *\n";
    $issues[] = "Margin cross-check diff: R" . number_format($marginDiff, 2);
}
echo "\n";

// ── 7. RATE CONSISTENCY: roster rates vs caregiver standard rate ──

echo "── 7. RATE ANOMALIES (roster rate != standard rate) ──\n\n";

$rateIssues = $db->query(
    "SELECT dr.caregiver_name, cg.standard_daily_rate AS std_rate,
            MIN(dr.daily_rate) AS min_rate, MAX(dr.daily_rate) AS max_rate,
            COUNT(DISTINCT dr.daily_rate) AS distinct_rates
     FROM daily_roster dr
     LEFT JOIN caregivers cg ON dr.caregiver_id = cg.id
     WHERE cg.standard_daily_rate IS NOT NULL
     GROUP BY dr.caregiver_name, cg.standard_daily_rate
     HAVING COUNT(DISTINCT dr.daily_rate) > 1
     ORDER BY dr.caregiver_name"
)->fetchAll();

if (empty($rateIssues)) {
    echo "  No rate anomalies — each caregiver has a consistent rate in roster.\n";
} else {
    printf("  %-30s %10s %10s %10s %8s\n", "Caregiver", "Std Rate", "Min Rate", "Max Rate", "# Rates");
    printf("  %-30s %10s %10s %10s %8s\n", "──────────────────────────────", "──────────", "──────────", "──────────", "────────");
    foreach ($rateIssues as $ri) {
        printf("  %-30s %10s %10s %10s %8d\n",
            substr($ri['caregiver_name'], 0, 30),
            'R' . number_format((float)$ri['std_rate'], 0),
            'R' . number_format((float)$ri['min_rate'], 0),
            'R' . number_format((float)$ri['max_rate'], 0),
            $ri['distinct_rates']
        );
    }
}
echo "\n";

// ── 8. AUDIT TRAIL COVERAGE ───────────────────────────────

echo "── 8. AUDIT TRAIL COVERAGE ──\n\n";

$atClientCount = (int)$db->query("SELECT COUNT(*) FROM audit_trail WHERE record_type = 'client_revenue'")->fetchColumn();
$atCgCount     = (int)$db->query("SELECT COUNT(*) FROM audit_trail WHERE record_type = 'caregiver_cost'")->fetchColumn();
$crCount       = (int)$db->query("SELECT COUNT(*) FROM client_revenue")->fetchColumn();
$ccCount       = (int)$db->query("SELECT COUNT(*) FROM caregiver_costs")->fetchColumn();

echo "  Client revenue records:   $crCount | Audit trail entries: $atClientCount\n";
echo "  Caregiver cost records:   $ccCount | Audit trail entries: $atCgCount\n";

$crOrphans = (int)$db->query(
    "SELECT COUNT(*) FROM client_revenue cr
     WHERE cr.id NOT IN (SELECT record_id FROM audit_trail WHERE record_type = 'client_revenue')"
)->fetchColumn();
$ccOrphans = (int)$db->query(
    "SELECT COUNT(*) FROM caregiver_costs cc
     WHERE cc.id NOT IN (SELECT record_id FROM audit_trail WHERE record_type = 'caregiver_cost')"
)->fetchColumn();

echo "  Revenue records without audit trail: $crOrphans\n";
echo "  Cost records without audit trail:    $ccOrphans\n";
if ($crOrphans > 0) $issues[] = "$crOrphans revenue records lack audit trail";
if ($ccOrphans > 0) $issues[] = "$ccOrphans cost records lack audit trail";
echo "\n";

// ── SUMMARY ───────────────────────────────────────────────

echo "══════════════════════════════════════════\n";
if (empty($issues)) {
    echo "  ALL CHECKS PASSED — data reconciles.\n";
} else {
    echo "  " . count($issues) . " ISSUE(S) FOUND:\n";
    foreach ($issues as $i => $issue) {
        echo "  " . ($i + 1) . ". $issue\n";
    }
}
echo "══════════════════════════════════════════\n";
