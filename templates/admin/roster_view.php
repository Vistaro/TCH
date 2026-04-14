<?php
/**
 * Roster View — /admin/roster
 *
 * Patient-centric monthly roster grid. Rows = patients, columns = days
 * of the selected month, cells = caregiver who delivered the shift.
 * Replaces the at-a-glance function of the Tuniti Caregiver Timesheet
 * Excel workbook.
 *
 * Filters (URL-param driven):
 *   month=YYYY-MM        — default: current month
 *   caregiver=<id>       — filter to rows where this caregiver attended
 *   patient=<query>      — text LIKE filter on patient name / TCH ID
 *   cohort=<code>        — filter caregiver dropdown
 *   group=client         — rows grouped by bill-payer client
 *
 * Export:
 *   /admin/roster/export.csv?<same params>
 */

$pageTitle = 'Roster View';
$activeNav = 'roster';
$db = getDB();

// ── Month ──────────────────────────────────────────────────────────
$monthStr = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $monthStr)) $monthStr = date('Y-m');
$monthStart = $monthStr . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$daysInMonth = (int)date('t', strtotime($monthStart));
$monthLabel  = date('F Y', strtotime($monthStart));

// Prev / next month URLs
$prev = date('Y-m', strtotime($monthStart . ' -1 month'));
$next = date('Y-m', strtotime($monthStart . ' +1 month'));

// ── Filters ────────────────────────────────────────────────────────
$filterCaregiver = (int)($_GET['caregiver'] ?? 0);
$filterPatient   = trim((string)($_GET['patient'] ?? ''));
$filterCohort    = trim((string)($_GET['cohort']  ?? ''));
$groupByClient   = ($_GET['group'] ?? '') === 'client';

// Caregivers available in this month (for the dropdown)
$cgOpts = $db->prepare(
    "SELECT DISTINCT p.id, p.full_name, p.tch_id,
            s.cohort
     FROM daily_roster r
     JOIN persons p ON p.id = r.caregiver_id
     LEFT JOIN students s ON s.person_id = p.id
     WHERE r.roster_date >= ? AND r.roster_date <= ?
       AND r.caregiver_id IS NOT NULL
     ORDER BY p.full_name"
);
$cgOpts->execute([$monthStart, $monthEnd]);
$caregivers = $cgOpts->fetchAll(PDO::FETCH_ASSOC);

// Cohorts (for cohort filter)
$cohorts = $db->query(
    "SELECT DISTINCT cohort FROM students WHERE cohort IS NOT NULL AND cohort <> '' ORDER BY cohort"
)->fetchAll(PDO::FETCH_COLUMN);

// ── Shifts for the month ──────────────────────────────────────────
$sql = "SELECT r.roster_date, r.caregiver_id, r.patient_person_id, r.client_id,
               r.units, r.cost_rate, r.bill_rate, r.source_cell, r.status,
               p_pt.full_name  AS patient_name,
               p_pt.tch_id     AS patient_tch_id,
               p_pt.archived_at AS patient_archived,
               p_cg.full_name  AS caregiver_name,
               p_cl.full_name  AS client_name,
               p_cl.tch_id     AS client_tch_id,
               s.cohort        AS cg_cohort
        FROM daily_roster r
   LEFT JOIN persons p_pt ON p_pt.id = r.patient_person_id
   LEFT JOIN persons p_cg ON p_cg.id = r.caregiver_id
   LEFT JOIN persons p_cl ON p_cl.id = r.client_id
   LEFT JOIN students s ON s.person_id = r.caregiver_id
        WHERE r.roster_date >= ? AND r.roster_date <= ?
          AND r.status = 'delivered'";
$params = [$monthStart, $monthEnd];

if ($filterCaregiver > 0) { $sql .= " AND r.caregiver_id = ?";   $params[] = $filterCaregiver; }
if ($filterPatient !== '') {
    $sql .= " AND (p_pt.full_name LIKE ? OR p_pt.tch_id LIKE ?)";
    $params[] = "%{$filterPatient}%"; $params[] = "%{$filterPatient}%";
}
if ($filterCohort !== '') { $sql .= " AND s.cohort = ?"; $params[] = $filterCohort; }

$sql .= " ORDER BY p_pt.full_name, r.roster_date";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Build grid matrix ──────────────────────────────────────────────
// patients[patient_id] = { name, tch_id, client_id, client_name, shifts[day] = {caregiver, units, ...} }
$patients = [];
$caregiverIds = [];       // id → name (for legend)
foreach ($shifts as $r) {
    $pid = (int)$r['patient_person_id'];
    if (!$pid) continue;
    $day = (int)date('j', strtotime($r['roster_date']));

    if (!isset($patients[$pid])) {
        $patients[$pid] = [
            'id'           => $pid,
            'name'         => $r['patient_name'] ?? 'Unknown',
            'tch_id'       => $r['patient_tch_id'],
            'archived'     => (bool)$r['patient_archived'],
            'client_id'    => (int)$r['client_id'],
            'client_name'  => $r['client_name'] ?? 'Unknown',
            'client_tch_id'=> $r['client_tch_id'],
            'shifts'       => [],
            'day_count'    => 0,
            'units'        => 0,
        ];
    }
    $patients[$pid]['shifts'][$day][] = [
        'caregiver_id'   => (int)$r['caregiver_id'],
        'caregiver_name' => $r['caregiver_name'] ?? '',
        'units'          => (float)$r['units'],
        'cost_rate'      => (float)$r['cost_rate'],
        'bill_rate'      => (float)$r['bill_rate'],
        'source_cell'    => $r['source_cell'],
    ];
    $patients[$pid]['units'] += (float)$r['units'];
    $patients[$pid]['day_count']++;

    if (!empty($r['caregiver_id'])) $caregiverIds[(int)$r['caregiver_id']] = $r['caregiver_name'];
}

// Sort patients by name (or, if grouped, by client_name then patient_name)
if ($groupByClient) {
    uasort($patients, function ($a, $b) {
        return [$a['client_name'], $a['name']] <=> [$b['client_name'], $b['name']];
    });
} else {
    uasort($patients, fn($a,$b) => $a['name'] <=> $b['name']);
}

// ── Colour assignment per caregiver (stable hue from PHP crc32) ────
function caregiverColour(int $id): array {
    $h = crc32((string)$id) % 360;   // hue
    $s = 55;                          // saturation
    $l_bg = 86;                       // background lightness (soft)
    $l_fg = 22;                       // text lightness (readable on soft bg)
    return [
        'bg' => "hsl($h, {$s}%, {$l_bg}%)",
        'fg' => "hsl($h, {$s}%, {$l_fg}%)",
    ];
}

// Days-per-day tally (coverage row)
$coverage = array_fill(1, $daysInMonth, 0);
foreach ($patients as $p) {
    foreach ($p['shifts'] as $d => $list) $coverage[$d] += count($list);
}

// Build a "days in month" meta array (date, day-of-week initial, is-weekend)
$dayMeta = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $dt = strtotime("$monthStart +".($d-1)." day");
    $dow = (int)date('N', $dt); // 1=Mon, 7=Sun
    $dayMeta[$d] = [
        'd'        => $d,
        'date'     => date('Y-m-d', $dt),
        'dow'      => ['', 'M','T','W','T','F','S','S'][$dow],
        'weekend'  => $dow >= 6,
        'today'    => date('Y-m-d', $dt) === date('Y-m-d'),
    ];
}

// ── Stats for header bar ───────────────────────────────────────────
$totalPatients  = count($patients);
$totalShifts    = array_sum(array_column($patients, 'day_count'));
$totalUnits     = array_sum(array_column($patients, 'units'));
$uniqueCgs      = count($caregiverIds);

// Filter URL builder
function filterUrl(array $changes, array $current): string {
    $p = array_merge($current, $changes);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    return APP_URL . '/admin/roster' . (empty($p) ? '' : '?' . http_build_query($p));
}
$curParams = [
    'month' => $monthStr,
    'caregiver' => $filterCaregiver ?: null,
    'patient'   => $filterPatient,
    'cohort'    => $filterCohort,
    'group'     => $groupByClient ? 'client' : null,
];

require APP_ROOT . '/templates/layouts/admin.php';
?>

<style>
    .roster-grid { border-collapse: collapse; font-size: 0.78rem; }
    .roster-grid th, .roster-grid td {
        border: 1px solid #e7eaed; padding: 3px 4px; text-align: center;
        min-width: 32px;
    }
    .roster-grid thead th {
        background: #f4f6f8; position: sticky; top: 0; z-index: 2;
        font-weight: 600;
    }
    .roster-grid th.col-patient,
    .roster-grid td.col-patient {
        text-align: left;
        min-width: 200px; max-width: 280px; white-space: nowrap;
        overflow: hidden; text-overflow: ellipsis;
        position: sticky; left: 0; z-index: 1;
        background: #fff;
    }
    .roster-grid thead th.col-patient { z-index: 3; background: #f4f6f8; }
    .roster-grid tr:hover td.col-patient { background: #fffbea; }

    .roster-grid th.weekend, .roster-grid td.weekend { background: #fafafa; }
    .roster-grid th.today, .roster-grid td.today {
        border-left: 2px solid #0d6efd; border-right: 2px solid #0d6efd;
    }
    .roster-grid td.cell-shift { font-weight: 600; cursor: default; position: relative; }

    /* CSS-only tooltip — shown instantly on hover, no browser delay */
    .roster-grid td.cell-shift:hover::after {
        content: attr(data-tip);
        position: absolute; bottom: 100%; left: 50%;
        transform: translateX(-50%);
        white-space: pre;
        background: #1f2d3d; color: #fff;
        padding: 6px 10px; border-radius: 6px;
        font-size: 0.72rem; font-weight: 500;
        box-shadow: 0 4px 14px rgba(0,0,0,0.2);
        z-index: 10; pointer-events: none;
        line-height: 1.35;
    }
    .roster-grid td.cell-shift:hover::before {
        content: ''; position: absolute;
        bottom: 100%; left: 50%; transform: translateX(-50%);
        border: 5px solid transparent; border-top-color: #1f2d3d;
        margin-bottom: -5px; z-index: 11;
    }
    .roster-grid tr.client-header td {
        background: #eef2f7; font-weight: 700; text-align: left;
        letter-spacing: 0.03em; color: #31497a;
    }
    .roster-grid tr.coverage-row td {
        background: #fafafa; font-weight: 600; color: #4a5660;
    }
    .roster-grid td.row-total { background: #f4f6f8; font-weight: 600; }
    .roster-grid td.archived { opacity: 0.5; font-style: italic; }

    .roster-wrapper { overflow-x: auto; max-height: 75vh; overflow-y: auto; }

    .roster-filters {
        display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: flex-end;
        margin-bottom: 0.75rem;
    }
    .roster-filters .filter-group label { font-size: 0.75rem; color: #6c757d; display: block; }
    .roster-filters .filter-group input,
    .roster-filters .filter-group select { padding: 3px 6px; font-size: 0.85rem; }

    .month-scrubber {
        display: inline-flex; align-items: center; gap: 0.4rem; font-weight: 600;
    }
    .month-scrubber a {
        padding: 2px 8px; border: 1px solid #dee2e6; border-radius: 4px;
        text-decoration: none; color: #495057;
    }

    .caregiver-legend {
        margin-top: 0.75rem; display: flex; flex-wrap: wrap; gap: 0.4rem;
        font-size: 0.8rem;
    }
    .caregiver-legend .chip {
        padding: 2px 8px; border-radius: 10px; border: 1px solid rgba(0,0,0,0.08);
    }

    @media print {
        .admin-sidebar, .admin-topbar, .roster-filters, .caregiver-legend a { display: none !important; }
        .roster-wrapper { max-height: none; overflow: visible; }
        .roster-grid { font-size: 0.6rem; }
        .roster-grid th.col-patient, .roster-grid td.col-patient { min-width: 140px; max-width: 180px; }
    }
</style>

<div class="roster-filters">
    <div class="month-scrubber">
        <a href="<?= htmlspecialchars(filterUrl(['month' => $prev], $curParams)) ?>">‹</a>
        <span><?= htmlspecialchars($monthLabel) ?></span>
        <a href="<?= htmlspecialchars(filterUrl(['month' => $next], $curParams)) ?>">›</a>
    </div>

    <form method="GET" style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:flex-end;margin:0;">
        <input type="hidden" name="month" value="<?= htmlspecialchars($monthStr) ?>">
        <div class="filter-group">
            <label>Caregiver</label>
            <select name="caregiver">
                <option value="">All caregivers</option>
                <?php foreach ($caregivers as $cg): ?>
                <option value="<?= (int)$cg['id'] ?>" <?= $filterCaregiver === (int)$cg['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cg['full_name']) ?><?= $cg['cohort'] ? ' ('.htmlspecialchars($cg['cohort']).')' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Patient</label>
            <input type="text" name="patient" value="<?= htmlspecialchars($filterPatient) ?>" placeholder="name or TCH-...">
        </div>
        <div class="filter-group">
            <label>Cohort</label>
            <select name="cohort">
                <option value="">All</option>
                <?php foreach ($cohorts as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $filterCohort === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>&nbsp;</label>
            <label><input type="checkbox" name="group" value="client" <?= $groupByClient ? 'checked' : '' ?>> Group by client</label>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
        <?php if ($filterCaregiver || $filterPatient || $filterCohort || $groupByClient): ?>
            <a href="<?= APP_URL ?>/admin/roster?month=<?= htmlspecialchars($monthStr) ?>" class="btn btn-outline btn-sm">Clear</a>
        <?php endif; ?>
    </form>

    <div style="margin-left:auto;display:flex;gap:0.4rem;">
        <a href="<?= APP_URL ?>/admin/roster/export.csv?<?= http_build_query(array_filter($curParams, fn($v)=>$v!==null && $v!==''))  ?>" class="btn btn-outline btn-sm">Export CSV</a>
        <button class="btn btn-outline btn-sm" onclick="window.print()">Print</button>
    </div>
</div>

<div style="display:flex;gap:1rem;margin-bottom:0.5rem;color:#6c757d;font-size:0.85rem;">
    <span><strong><?= $totalPatients ?></strong> patients</span>
    <span><strong><?= $totalShifts ?></strong> shifts</span>
    <span><strong><?= rtrim(rtrim(number_format($totalUnits, 1), '0'), '.') ?></strong> units</span>
    <span><strong><?= $uniqueCgs ?></strong> unique caregivers</span>
</div>

<div class="roster-wrapper">
<table class="roster-grid">
    <thead>
        <tr>
            <th class="col-patient">Patient</th>
            <?php foreach ($dayMeta as $dm): ?>
                <th class="<?= $dm['weekend'] ? 'weekend' : '' ?> <?= $dm['today'] ? 'today' : '' ?>" title="<?= htmlspecialchars($dm['date']) ?>">
                    <?= $dm['d'] ?><br><small><?= $dm['dow'] ?></small>
                </th>
            <?php endforeach; ?>
            <th>Days</th>
        </tr>
    </thead>
    <tbody>
    <?php
    $lastClient = null;
    foreach ($patients as $p):
        if ($groupByClient && $p['client_id'] !== $lastClient):
            $lastClient = $p['client_id'];
            ?>
            <tr class="client-header">
                <td colspan="<?= $daysInMonth + 2 ?>">
                    <?= htmlspecialchars($p['client_name']) ?>
                    <?php if ($p['client_tch_id']): ?><code style="font-weight:normal;color:#6c757d;font-size:0.75rem;">(<?= htmlspecialchars($p['client_tch_id']) ?>)</code><?php endif; ?>
                </td>
            </tr>
    <?php endif; ?>
        <tr>
            <td class="col-patient <?= $p['archived'] ? 'archived' : '' ?>">
                <a href="<?= APP_URL ?>/admin/patients/<?= (int)$p['id'] ?>" style="color:inherit;">
                    <?= htmlspecialchars($p['name']) ?>
                </a>
                <?php if ($p['tch_id']): ?><code style="color:#6c757d;font-size:0.7rem;"><?= htmlspecialchars($p['tch_id']) ?></code><?php endif; ?>
            </td>
            <?php foreach ($dayMeta as $dm): ?>
                <?php
                $list = $p['shifts'][$dm['d']] ?? [];
                $classes = [];
                if ($dm['weekend']) $classes[] = 'weekend';
                if ($dm['today']) $classes[] = 'today';
                if ($list) $classes[] = 'cell-shift';

                $style = ''; $text = ''; $title = '';
                if ($list) {
                    // Multiple caregivers on same day (split cells): show both, colour by first
                    $cgId = $list[0]['caregiver_id'];
                    $col = caregiverColour($cgId);
                    $style = "background:{$col['bg']};color:{$col['fg']};";
                    $texts = [];
                    $titleParts = [];
                    foreach ($list as $entry) {
                        $name = $entry['caregiver_name'] ?? '?';
                        $parts = preg_split('/\s+/', trim($name));
                        $surname = end($parts);
                        $abbr = mb_strtoupper(mb_substr($surname, 0, 3));
                        $texts[] = $entry['units'] < 1 ? $abbr.'½' : $abbr;
                        $titleParts[] = $name
                            . ($entry['units'] < 1 ? ' (half)' : '')
                            . ' — cost R'.number_format($entry['cost_rate'], 0)
                            . ($entry['bill_rate'] ? ', bill R'.number_format($entry['bill_rate'], 0) : ', not billed');
                    }
                    $text = implode('/', $texts);
                    $title = implode("\n", $titleParts);
                }
                ?>
                <td class="<?= implode(' ', $classes) ?>" style="<?= $style ?>" data-tip="<?= $title ? htmlspecialchars($title, ENT_QUOTES) : '' ?>">
                    <?= htmlspecialchars($text) ?>
                </td>
            <?php endforeach; ?>
            <td class="row-total"><?= rtrim(rtrim(number_format($p['units'], 1), '0'), '.') ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($patients)): ?>
        <tr><td colspan="<?= $daysInMonth + 2 ?>" style="text-align:center;color:#6c757d;padding:2rem;">No shifts found for <?= htmlspecialchars($monthLabel) ?> with the current filters.</td></tr>
    <?php endif; ?>
    </tbody>
    <?php if (!empty($patients)): ?>
    <tfoot>
        <tr class="coverage-row">
            <td class="col-patient">Coverage (shifts/day)</td>
            <?php foreach ($dayMeta as $dm): ?>
                <td class="<?= $dm['weekend'] ? 'weekend' : '' ?>"><?= $coverage[$dm['d']] ?: '' ?></td>
            <?php endforeach; ?>
            <td class="row-total"><?= $totalShifts ?></td>
        </tr>
    </tfoot>
    <?php endif; ?>
</table>
</div>

<?php if (!empty($caregiverIds)): ?>
<div class="caregiver-legend">
    <strong style="align-self:center;">Caregivers this month:</strong>
    <?php
    $sortedCgIds = $caregiverIds;
    asort($sortedCgIds);
    foreach ($sortedCgIds as $cgId => $cgName):
        $col = caregiverColour($cgId);
        $parts = preg_split('/\s+/', trim($cgName));
        $surname = end($parts);
        $abbr = mb_strtoupper(mb_substr($surname, 0, 3));
    ?>
        <a href="<?= htmlspecialchars(filterUrl(['caregiver' => $cgId], $curParams)) ?>" class="chip" style="background:<?= $col['bg'] ?>;color:<?= $col['fg'] ?>;text-decoration:none;">
            <strong><?= htmlspecialchars($abbr) ?></strong> <?= htmlspecialchars($cgName) ?>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
