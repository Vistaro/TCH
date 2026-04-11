<?php
/**
 * Caregiver Earnings by Month — Matrix view (FR-0056).
 *
 * One row per caregiver (no duplicates), 12 month columns across
 * (current month + previous 11), plus a Total column at the end.
 * Each cell shows ZAR earned that month, clickable to drill down
 * into the daily roster rows that make up the cell's total.
 *
 * Sortable by any column (name, any month, total) via the shared
 * tch-table.js component. Caregiver column filterable by text.
 * Month columns are NOT filterable (numeric columns don't benefit
 * from text-contains filters — use sort instead).
 *
 * Still supports the existing tranche dropdown at the top as a
 * pre-filter applied server-side.
 *
 * Permission: reports_caregiver_earnings.read
 */

$pageTitle = 'Caregiver Earnings by Month';
$activeNav = 'report-cg-earnings';

$db = getDB();

// ── Build the 12-month window ───────────────────────────────────────────
// Anchor month = current month. Previous 11 + current = 12 columns.
// Columns are NEWEST-FIRST — current month sits immediately right of the
// caregiver name, prior months fan out to the right. Labels are MMM-YY
// so the year is always visible (e.g. "Apr-26").
$anchor = new DateTimeImmutable('first day of this month');
$months = [];
for ($i = 0; $i < 12; $i++) {
    $d = $anchor->modify("-{$i} months");
    $months[] = [
        'key'   => $d->format('Y-m'),
        'label' => $d->format('M-y'),  // e.g. "Apr-26"
    ];
}
// SQL window: oldest month first day → current month first day
$firstMonth = $anchor->modify('-11 months')->format('Y-m-01');
$lastMonth  = $anchor->format('Y-m-01');

// ── Server-side pre-filter (tranche dropdown) ───────────────────────────
$tranches = $db->query(
    "SELECT DISTINCT tranche FROM persons WHERE tranche IS NOT NULL AND tranche != '' ORDER BY tranche"
)->fetchAll(PDO::FETCH_COLUMN);

$filterTranche = $_GET['tranche'] ?? '';

$extraWhere = '';
$extraParams = [];
if ($filterTranche !== '') {
    $extraWhere = ' AND cg.tranche = ?';
    $extraParams[] = $filterTranche;
}

// ── Fetch flat rows, pivot in PHP ───────────────────────────────────────
// One row in caregiver_costs per caregiver × month. We fetch the 12-month
// window, then group client-side into caregiver → month → amount.
// Pivot on the canonical `persons.full_name`, NOT the denormalised
// `caregiver_costs.caregiver_name` frozen at ingest time, so renames on
// a caregiver's full_name reflect immediately without a re-ingest.
// Orphan rows (caregiver_id IS NULL) fall back to the raw source name.
$sql = "SELECT cc.caregiver_id,
               COALESCE(cg.full_name, cc.caregiver_name) AS display_name,
               cc.month_date, cc.amount, cc.days_worked,
               cg.tranche
        FROM caregiver_costs cc
        LEFT JOIN persons cg ON cc.caregiver_id = cg.id
        WHERE cc.month_date >= ? AND cc.month_date <= ?
              $extraWhere
        ORDER BY display_name, cc.month_date";
$params = array_merge([$firstMonth, $lastMonth], $extraParams);
$stmt = $db->prepare($sql);
$stmt->execute($params);
$flatRows = $stmt->fetchAll();

// Pivot: display_name → [ month_key => amount, __total__, __tranche__, __id__ ]
$matrix = [];
foreach ($flatRows as $r) {
    $name = $r['display_name'];
    if (!isset($matrix[$name])) {
        $matrix[$name] = [
            'caregiver_id' => $r['caregiver_id'],
            'tranche'      => $r['tranche'],
            'months'       => array_fill_keys(array_column($months, 'key'), 0.0),
            'total'        => 0.0,
        ];
    }
    $monthKey = substr($r['month_date'], 0, 7);
    $amount   = (float)$r['amount'];
    if (array_key_exists($monthKey, $matrix[$name]['months'])) {
        $matrix[$name]['months'][$monthKey] += $amount;
        $matrix[$name]['total'] += $amount;
    }
}
ksort($matrix);

// Column totals (footer row)
$colTotals = array_fill_keys(array_column($months, 'key'), 0.0);
$grandTotal = 0.0;
foreach ($matrix as $row) {
    foreach ($row['months'] as $k => $v) {
        $colTotals[$k] += $v;
    }
    $grandTotal += $row['total'];
}

require APP_ROOT . '/templates/layouts/admin.php';

/**
 * Render a ZAR amount compactly for the matrix cells.
 * Zero renders as an em-dash so the eye skips over empty cells.
 */
function zar_cell(float $v): string {
    if ($v <= 0) return '<span style="color:#CCC;">—</span>';
    return 'R' . number_format($v, 0);
}
?>

<form method="GET" action="<?= APP_URL ?>/admin/reports/caregiver-earnings" class="report-filters">
    <div class="filter-group">
        <label>Tranche</label>
        <select name="tranche">
            <option value="">All Tranches</option>
            <?php foreach ($tranches as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $filterTranche === $t ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary">Filter</button>
    <?php if ($filterTranche !== ''): ?>
        <a href="<?= APP_URL ?>/admin/reports/caregiver-earnings" class="btn btn-outline btn-sm">Clear</a>
    <?php endif; ?>
    <div style="margin-left:auto;color:#666;font-size:0.85rem;">
        <?= count($matrix) ?> caregiver<?= count($matrix) === 1 ? '' : 's' ?> &middot;
        window: <?= htmlspecialchars(end($months)['label']) ?> → <?= htmlspecialchars($months[0]['label']) ?>
    </div>
</form>

<p style="color:#666;font-size:0.85rem;margin:0 0 0.75rem 0;">
    Click any column header to sort. Click any cell value to see which clients
    contributed to that caregiver's earnings that month.
</p>

<div class="report-table-wrap">
    <table class="report-table tch-data-table">
        <thead>
            <tr>
                <th>Caregiver</th>
                <th data-no-filter>Tranche</th>
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
                    <?php $rowKey = 'cg-' . (int)$row['caregiver_id']; ?>
                    <tr>
                        <td><?= htmlspecialchars($name) ?></td>
                        <td><?= htmlspecialchars($row['tranche'] ?? '—') ?></td>
                        <?php foreach ($months as $m): ?>
                            <?php $val = $row['months'][$m['key']] ?? 0; ?>
                            <td class="number">
                                <?php if ($val > 0): ?>
                                    <a href="#"
                                       class="drill-cell"
                                       data-report="earnings"
                                       data-entity-id="<?= (int)$row['caregiver_id'] ?>"
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

<!-- Drill-down panel: renders below the table on click ────────────────── -->
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
            var entity = a.dataset.entityName;
            var month  = a.dataset.monthLabel;
            var id     = a.dataset.entityId;
            var monthKey = a.dataset.month;

            title.textContent = entity + ' — ' + month;
            body.innerHTML = 'Loading…';
            panel.style.display = '';
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });

            fetch('<?= APP_URL ?>/ajax/report-drill?report=earnings&entity_id=' + encodeURIComponent(id) + '&month=' + encodeURIComponent(monthKey), {
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
