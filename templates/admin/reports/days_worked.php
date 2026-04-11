<?php
/**
 * Days Worked by Caregiver — Matrix view (FR-0066).
 *
 * One row per caregiver (no duplicates), 12 month columns + total.
 * Each cell is a count of days worked that month (from daily_roster),
 * clickable to drill down into the specific dates + clients for
 * that cell.
 *
 * Same shape as caregiver_earnings.php (FR-0056) and
 * client_billing.php (FR-0057) — three matrix reports with different
 * underlying data tables.
 *
 * Permission: reports_days_worked.read
 */

$pageTitle = 'Days Worked by Caregiver';
$activeNav = 'report-days-worked';

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
// Last day of the anchor (current) month for the inclusive SQL upper bound
$lastMonth  = $anchor->modify('last day of this month')->format('Y-m-d');

// ── Tranche pre-filter (server-side) ────────────────────────────────────
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

// ── Aggregate days per caregiver per month ──────────────────────────────
// Group + pivot on the canonical `persons.full_name`, NOT the
// denormalised `daily_roster.caregiver_name` frozen at ingest time.
// Orphan rows (no caregiver_id match) fall back to the raw source name.
$sql = "SELECT dr.caregiver_id,
               COALESCE(cg.full_name, dr.caregiver_name) AS display_name,
               DATE_FORMAT(dr.roster_date, '%Y-%m') AS month_key,
               COUNT(*) AS days_worked,
               cg.tranche
        FROM daily_roster dr
        LEFT JOIN persons cg ON dr.caregiver_id = cg.id
        WHERE dr.roster_date >= ? AND dr.roster_date <= ?
              $extraWhere
        GROUP BY dr.caregiver_id, display_name, month_key, cg.tranche
        ORDER BY display_name, month_key";
$params = array_merge([$firstMonth, $lastMonth], $extraParams);
$stmt = $db->prepare($sql);
$stmt->execute($params);
$flatRows = $stmt->fetchAll();

// Pivot into matrix
$matrix = [];
foreach ($flatRows as $r) {
    $name = $r['display_name'];
    if (!isset($matrix[$name])) {
        $matrix[$name] = [
            'caregiver_id' => $r['caregiver_id'],
            'tranche'      => $r['tranche'],
            'months'       => array_fill_keys(array_column($months, 'key'), 0),
            'total'        => 0,
        ];
    }
    $monthKey = $r['month_key'];
    $days     = (int)$r['days_worked'];
    if (array_key_exists($monthKey, $matrix[$name]['months'])) {
        $matrix[$name]['months'][$monthKey] += $days;
        $matrix[$name]['total'] += $days;
    }
}
ksort($matrix);

$colTotals = array_fill_keys(array_column($months, 'key'), 0);
$grandTotal = 0;
foreach ($matrix as $row) {
    foreach ($row['months'] as $k => $v) {
        $colTotals[$k] += $v;
    }
    $grandTotal += $row['total'];
}

require APP_ROOT . '/templates/layouts/admin.php';

function days_cell(int $v): string {
    if ($v <= 0) return '<span style="color:#CCC;">—</span>';
    return (string)$v;
}
?>

<form method="GET" action="<?= APP_URL ?>/admin/reports/days-worked" class="report-filters">
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
        <a href="<?= APP_URL ?>/admin/reports/days-worked" class="btn btn-outline btn-sm">Clear</a>
    <?php endif; ?>
    <div style="margin-left:auto;color:#666;font-size:0.85rem;">
        <?= count($matrix) ?> caregiver<?= count($matrix) === 1 ? '' : 's' ?> &middot;
        window: <?= htmlspecialchars(end($months)['label']) ?> → <?= htmlspecialchars($months[0]['label']) ?>
    </div>
</form>

<p style="color:#666;font-size:0.85rem;margin:0 0 0.75rem 0;">
    Click any column header to sort. Click any cell value to see which clients
    the caregiver worked for that month and on which dates.
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
                <tr><td colspan="<?= 3 + count($months) ?>" style="text-align:center;color:#999;padding:2rem;">No roster records found in the 12-month window.</td></tr>
            <?php else: ?>
                <?php foreach ($matrix as $name => $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($name) ?></td>
                        <td><?= htmlspecialchars($row['tranche'] ?? '—') ?></td>
                        <?php foreach ($months as $m): ?>
                            <?php $val = (int)($row['months'][$m['key']] ?? 0); ?>
                            <td class="number">
                                <?php if ($val > 0): ?>
                                    <a href="#"
                                       class="drill-cell"
                                       data-report="days"
                                       data-entity-id="<?= (int)$row['caregiver_id'] ?>"
                                       data-entity-name="<?= htmlspecialchars($name, ENT_QUOTES) ?>"
                                       data-month="<?= htmlspecialchars($m['key']) ?>"
                                       data-month-label="<?= htmlspecialchars($m['label']) ?>">
                                        <?= days_cell($val) ?>
                                    </a>
                                <?php else: ?>
                                    <?= days_cell($val) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="number"><strong><?= days_cell((int)$row['total']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="2">Total</td>
                    <?php foreach ($months as $m): ?>
                        <td class="number"><?= days_cell((int)($colTotals[$m['key']] ?? 0)) ?></td>
                    <?php endforeach; ?>
                    <td class="number"><strong><?= days_cell((int)$grandTotal) ?></strong></td>
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

            fetch('<?= APP_URL ?>/ajax/report-drill?report=days&entity_id=' + encodeURIComponent(id) + '&month=' + encodeURIComponent(monthKey), {
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
