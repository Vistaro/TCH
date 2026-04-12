<?php
$pageTitle = 'Client Profitability — Detail';
$activeNav = 'report-client-profitability';

$db = getDB();
$clientId = (int)($_GET['client_id'] ?? 0);

// Load client
$client = $db->prepare(
    "SELECT c.id, c.account_number, p.full_name AS client_name, pt.patient_name
     FROM clients c
     LEFT JOIN persons p ON p.id = c.person_id
     LEFT JOIN patients pt ON pt.person_id = c.person_id
     WHERE c.id = ?"
);
$client->execute([$clientId]);
$clientRow = $client->fetch(PDO::FETCH_ASSOC);
if (!$clientRow) {
    require APP_ROOT . '/templates/layouts/admin.php';
    echo '<p>Client not found. <a href="' . APP_URL . '/admin/reports/client-profitability">Back</a></p>';
    require APP_ROOT . '/templates/layouts/admin_footer.php';
    exit;
}

// Months column — use the months we have data for, chronological
$months = $db->query(
    "SELECT DISTINCT DATE_FORMAT(month_date, '%Y-%m') AS ym,
            DATE_FORMAT(month_date, '%b %Y') AS label
     FROM client_revenue WHERE month_date IS NOT NULL
     UNION
     SELECT DISTINCT DATE_FORMAT(roster_date, '%Y-%m'),
            DATE_FORMAT(roster_date, '%b %Y')
     FROM daily_roster
     ORDER BY ym"
)->fetchAll();

// Revenue per month
$revByMonth = [];
$stmt = $db->prepare(
    "SELECT DATE_FORMAT(month_date, '%Y-%m') AS ym, SUM(income) AS v
     FROM client_revenue WHERE client_id = ? GROUP BY ym"
);
$stmt->execute([$clientId]);
foreach ($stmt->fetchAll() as $r) $revByMonth[$r['ym']] = (float)$r['v'];

// Wages per caregiver per month (for this client)
$cgWages = []; // [cg_name => [ym => amount]]
$cgDaysByMonth = []; // [ym => days]
$stmt = $db->prepare(
    "SELECT DATE_FORMAT(dr.roster_date, '%Y-%m') AS ym,
            COALESCE(p.full_name, dr.caregiver_name) AS cg_name,
            SUM(dr.cost_rate) AS wages,
            COUNT(*) AS days
     FROM daily_roster dr
     LEFT JOIN persons p ON p.id = dr.caregiver_id
     WHERE dr.client_id = ? AND dr.status = 'delivered'
     GROUP BY ym, cg_name
     ORDER BY cg_name, ym"
);
$stmt->execute([$clientId]);
foreach ($stmt->fetchAll() as $r) {
    $ym = $r['ym']; $cg = $r['cg_name'];
    if (!isset($cgWages[$cg])) $cgWages[$cg] = [];
    $cgWages[$cg][$ym] = (float)$r['wages'];
    $cgDaysByMonth[$ym] = ($cgDaysByMonth[$ym] ?? 0) + (int)$r['days'];
}

// Expenses per month
$expByMonth = [];
$stmt = $db->prepare(
    "SELECT DATE_FORMAT(expense_date, '%Y-%m') AS ym, SUM(amount) AS v
     FROM patient_expenses WHERE client_id = ? GROUP BY ym"
);
$stmt->execute([$clientId]);
foreach ($stmt->fetchAll() as $r) $expByMonth[$r['ym']] = (float)$r['v'];

// Totals
$totalBilled = array_sum($revByMonth);
$totalWagesByMonth = [];
foreach ($months as $m) {
    $ym = $m['ym'];
    $totalWagesByMonth[$ym] = 0;
    foreach ($cgWages as $byMonth) {
        $totalWagesByMonth[$ym] += $byMonth[$ym] ?? 0;
    }
}
$totalWages = array_sum($totalWagesByMonth);
$totalExpenses = array_sum($expByMonth);
$totalDays = array_sum($cgDaysByMonth);
$totalGross = $totalBilled - $totalWages - $totalExpenses;
$totalMarginPct = $totalBilled > 0 ? ($totalGross / $totalBilled) * 100 : 0;

$displayName = $clientRow['client_name'];
if ($clientRow['patient_name'] && $clientRow['patient_name'] !== $clientRow['client_name']) {
    $displayName .= ' (for ' . $clientRow['patient_name'] . ')';
}

require APP_ROOT . '/templates/layouts/admin.php';
?>

<p style="margin-bottom:0.5rem;">
    <a href="<?= APP_URL ?>/admin/reports/client-profitability" style="font-size:0.85rem;">← Back to all clients</a>
</p>
<h2 style="margin:0 0 0.25rem 0;"><?= htmlspecialchars($displayName) ?></h2>
<p style="color:#666;font-size:0.85rem;margin-bottom:1rem;">
    Account: <code><?= htmlspecialchars($clientRow['account_number'] ?? '—') ?></code>
</p>

<div style="overflow-x:auto;">
<table class="report-table tch-data-table">
    <thead>
        <tr>
            <th></th>
            <?php foreach ($months as $m): ?>
            <th class="number" data-no-filter><?= htmlspecialchars($m['label']) ?></th>
            <?php endforeach; ?>
            <th class="number" data-no-filter style="border-left:2px solid #333;">Total</th>
        </tr>
    </thead>
    <tbody>
        <!-- Billed -->
        <tr style="background:#e7f1ff;">
            <td><strong>Billed</strong></td>
            <?php foreach ($months as $m):
                $v = $revByMonth[$m['ym']] ?? 0; ?>
            <td class="number"><?= $v > 0 ? 'R' . number_format($v, 0) : '—' ?></td>
            <?php endforeach; ?>
            <td class="number" style="border-left:2px solid #333;"><strong>R<?= number_format($totalBilled, 0) ?></strong></td>
        </tr>

        <!-- Caregiver wages — one row per caregiver -->
        <?php
        $totalByCg = [];
        foreach ($cgWages as $cg => $byMonth) $totalByCg[$cg] = array_sum($byMonth);
        uksort($cgWages, fn($a, $b) => $totalByCg[$b] <=> $totalByCg[$a]);
        ?>
        <?php foreach ($cgWages as $cg => $byMonth): ?>
        <tr>
            <td style="padding-left:1.5rem;font-size:0.9em;color:#555;"><?= htmlspecialchars($cg) ?></td>
            <?php foreach ($months as $m):
                $v = $byMonth[$m['ym']] ?? 0; ?>
            <td class="number" style="font-size:0.9em;color:#555;"><?= $v > 0 ? 'R' . number_format($v, 0) : '—' ?></td>
            <?php endforeach; ?>
            <td class="number" style="border-left:2px solid #333;font-size:0.9em;color:#555;">R<?= number_format($totalByCg[$cg], 0) ?></td>
        </tr>
        <?php endforeach; ?>

        <!-- Total Wages -->
        <tr>
            <td><strong>Total Wages</strong></td>
            <?php foreach ($months as $m):
                $v = $totalWagesByMonth[$m['ym']] ?? 0; ?>
            <td class="number"><?= $v > 0 ? 'R' . number_format($v, 0) : '—' ?></td>
            <?php endforeach; ?>
            <td class="number" style="border-left:2px solid #333;"><strong>R<?= number_format($totalWages, 0) ?></strong></td>
        </tr>

        <!-- Total Days -->
        <tr>
            <td><strong>Total Days</strong></td>
            <?php foreach ($months as $m):
                $v = $cgDaysByMonth[$m['ym']] ?? 0; ?>
            <td class="number"><?= $v > 0 ? (int)$v : '—' ?></td>
            <?php endforeach; ?>
            <td class="number" style="border-left:2px solid #333;"><strong><?= (int)$totalDays ?></strong></td>
        </tr>

        <!-- Expenses -->
        <tr>
            <td><strong>Expenses</strong></td>
            <?php foreach ($months as $m):
                $v = $expByMonth[$m['ym']] ?? 0; ?>
            <td class="number"><?= $v > 0 ? 'R' . number_format($v, 0) : '—' ?></td>
            <?php endforeach; ?>
            <td class="number" style="border-left:2px solid #333;"><strong><?= $totalExpenses > 0 ? 'R' . number_format($totalExpenses, 0) : '—' ?></strong></td>
        </tr>

        <!-- Gross Profit -->
        <tr style="background:#e8f5e9;">
            <td><strong>Gross Profit</strong></td>
            <?php foreach ($months as $m):
                $billed = $revByMonth[$m['ym']] ?? 0;
                $wages = $totalWagesByMonth[$m['ym']] ?? 0;
                $exp = $expByMonth[$m['ym']] ?? 0;
                $gp = $billed - $wages - $exp;
            ?>
            <td class="number" style="color:<?= $gp >= 0 ? '#198754' : '#dc3545' ?>"><?= $billed != 0 || $wages != 0 ? 'R' . number_format($gp, 0) : '—' ?></td>
            <?php endforeach; ?>
            <td class="number" style="border-left:2px solid #333;color:<?= $totalGross >= 0 ? '#198754' : '#dc3545' ?>"><strong>R<?= number_format($totalGross, 0) ?></strong></td>
        </tr>

        <!-- Margin % -->
        <tr style="background:#e8f5e9;">
            <td><strong>Margin %</strong></td>
            <?php foreach ($months as $m):
                $billed = $revByMonth[$m['ym']] ?? 0;
                $wages = $totalWagesByMonth[$m['ym']] ?? 0;
                $exp = $expByMonth[$m['ym']] ?? 0;
                $gp = $billed - $wages - $exp;
                $pct = $billed > 0 ? ($gp / $billed) * 100 : null;
            ?>
            <td class="number"><?= $pct !== null ? number_format($pct, 0) . '%' : '—' ?></td>
            <?php endforeach; ?>
            <td class="number" style="border-left:2px solid #333;"><strong><?= number_format($totalMarginPct, 0) ?>%</strong></td>
        </tr>
    </tbody>
</table>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
