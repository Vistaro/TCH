<?php
/**
 * Client Billing by Month — Matrix view (FR-0057).
 *
 * One row per client, 12 month columns (current + previous 11) plus
 * a Total column. Each cell shows income (R), clickable to drill
 * down into which caregivers provided care to that client that
 * month with individual roster dates and daily rates.
 *
 * Matches the shape of caregiver_earnings.php (FR-0056) and
 * days_worked.php (FR-0066) — three views of the same matrix idea
 * with different underlying data tables.
 *
 * Permission: reports_client_billing.read
 */

$pageTitle = 'Client Billing by Month';
$activeNav = 'report-client-billing';

$db = getDB();

// ── 12-month window ─────────────────────────────────────────────────────
// Newest-first ordering. Labels are MMM-YY (e.g. "Apr-26").
$anchor = new DateTimeImmutable('first day of this month');
$months = [];
for ($i = 0; $i < 12; $i++) {
    $d = $anchor->modify("-{$i} months");
    $months[] = [
        'key'   => $d->format('Y-m'),
        'label' => $d->format('M-y'),
    ];
}
$firstMonth = $anchor->modify('-11 months')->format('Y-m-01');
$lastMonth  = $anchor->format('Y-m-01');

// ── Fetch + pivot ───────────────────────────────────────────────────────
// Pivot on the canonical `clients.client_name`, NOT on the denormalised
// `client_revenue.client_name` that was frozen at ingest time. This is
// what makes merges and renames on the clients table reflect immediately
// on this report instead of showing pre-dedup ghosts. Revenue rows
// whose client_id can't be joined (orphans) fall back to their raw
// source name via COALESCE.
$sql = "SELECT cr.client_id,
               COALESCE(c.client_name, cr.client_name) AS display_name,
               cr.month_date, cr.income,
               c.account_number, c.status AS client_status
        FROM client_revenue cr
        LEFT JOIN clients c ON cr.client_id = c.id
        WHERE cr.month_date >= ? AND cr.month_date <= ?
        ORDER BY display_name, cr.month_date";
$stmt = $db->prepare($sql);
$stmt->execute([$firstMonth, $lastMonth]);
$flatRows = $stmt->fetchAll();

$matrix = [];
foreach ($flatRows as $r) {
    $name = $r['display_name'];
    if (!isset($matrix[$name])) {
        $matrix[$name] = [
            'client_id'      => $r['client_id'],
            'account_number' => $r['account_number'],
            'status'         => $r['client_status'],
            'months'         => array_fill_keys(array_column($months, 'key'), 0.0),
            'total'          => 0.0,
        ];
    }
    $monthKey = substr($r['month_date'], 0, 7);
    $income   = (float)$r['income'];
    if (array_key_exists($monthKey, $matrix[$name]['months'])) {
        $matrix[$name]['months'][$monthKey] += $income;
        $matrix[$name]['total'] += $income;
    }
}
ksort($matrix);

$colTotals = array_fill_keys(array_column($months, 'key'), 0.0);
$grandTotal = 0.0;
foreach ($matrix as $row) {
    foreach ($row['months'] as $k => $v) {
        $colTotals[$k] += $v;
    }
    $grandTotal += $row['total'];
}

require APP_ROOT . '/templates/layouts/admin.php';

function zar_cell(float $v): string {
    if ($v <= 0) return '<span style="color:#CCC;">—</span>';
    return 'R' . number_format($v, 0);
}
?>

<form method="GET" action="<?= APP_URL ?>/admin/reports/client-billing" class="report-filters" style="justify-content:space-between;">
    <div style="color:#666;font-size:0.85rem;">
        <?= count($matrix) ?> client<?= count($matrix) === 1 ? '' : 's' ?> &middot;
        window: <?= htmlspecialchars(end($months)['label']) ?> → <?= htmlspecialchars($months[0]['label']) ?>
    </div>
</form>

<p style="color:#666;font-size:0.85rem;margin:0 0 0.75rem 0;">
    Click any column header to sort. Click any cell value to see which caregivers
    served that client that month.
</p>

<div class="report-table-wrap">
    <table class="report-table tch-data-table">
        <thead>
            <tr>
                <th>Client</th>
                <th data-no-filter>Account</th>
                <?php foreach ($months as $m): ?>
                    <th class="number" data-no-filter><?= htmlspecialchars($m['label']) ?></th>
                <?php endforeach; ?>
                <th class="number" data-no-filter>Total</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($matrix)): ?>
                <tr><td colspan="<?= 3 + count($months) ?>" style="text-align:center;color:#999;padding:2rem;">No records found in the 12-month window.</td></tr>
            <?php else: ?>
                <?php foreach ($matrix as $name => $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($name) ?></td>
                        <td><?= htmlspecialchars($row['account_number'] ?? '—') ?></td>
                        <?php foreach ($months as $m): ?>
                            <?php $val = $row['months'][$m['key']] ?? 0; ?>
                            <td class="number">
                                <?php if ($val > 0): ?>
                                    <a href="#"
                                       class="drill-cell"
                                       data-report="billing"
                                       data-entity-id="<?= (int)$row['client_id'] ?>"
                                       data-entity-name="<?= htmlspecialchars($name, ENT_QUOTES) ?>"
                                       data-month="<?= htmlspecialchars($m['key']) ?>"
                                       data-month-label="<?= htmlspecialchars($m['label']) ?>">
                                        <?= zar_cell((float)$val) ?>
                                    </a>
                                <?php else: ?>
                                    <?= zar_cell((float)$val) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="number"><strong><?= zar_cell((float)$row['total']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="2">Total</td>
                    <?php foreach ($months as $m): ?>
                        <td class="number"><?= zar_cell((float)($colTotals[$m['key']] ?? 0)) ?></td>
                    <?php endforeach; ?>
                    <td class="number"><strong><?= zar_cell((float)$grandTotal) ?></strong></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="drill-panel" style="display:none;margin-top:1.5rem;background:#fff;border:1px solid #eee;border-radius:10px;padding:1.25rem 1.5rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
        <h3 id="drill-title" style="margin:0;"></h3>
        <button type="button" id="drill-close" class="btn btn-outline btn-sm">Close</button>
    </div>
    <div id="drill-body" style="color:#555;">Loading…</div>
</div>

<script>
(function () {
    var panel = document.getElementById('drill-panel');
    var title = document.getElementById('drill-title');
    var body  = document.getElementById('drill-body');
    var close = document.getElementById('drill-close');

    document.querySelectorAll('a.drill-cell').forEach(function (a) {
        a.addEventListener('click', function (ev) {
            ev.preventDefault();
            var entity   = a.dataset.entityName;
            var month    = a.dataset.monthLabel;
            var id       = a.dataset.entityId;
            var monthKey = a.dataset.month;

            title.textContent = entity + ' — ' + month;
            body.innerHTML = 'Loading…';
            panel.style.display = '';
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });

            fetch('<?= APP_URL ?>/ajax/report-drill?report=billing&entity_id=' + encodeURIComponent(id) + '&month=' + encodeURIComponent(monthKey), {
                credentials: 'same-origin'
            })
            .then(function (r) { return r.text(); })
            .then(function (html) { body.innerHTML = html; })
            .catch(function () { body.innerHTML = '<span style="color:#c00;">Failed to load drill-down detail. Please retry.</span>'; });
        });
    });

    close.addEventListener('click', function () {
        panel.style.display = 'none';
    });
}());
</script>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
