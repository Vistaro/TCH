<?php
/**
 * Quotes list — /admin/quotes
 *
 * A quote is a contract in draft/sent/accepted/rejected/expired status.
 * This page is the quote-focused lens on the same `contracts` table —
 * contracts that have reached `active` or beyond are on /admin/contracts.
 */
$pageTitle = 'Quotes';
$activeNav = 'quotes';

$db = getDB();
$canCreate = userCan('quotes', 'create');

$filterStatus = $_GET['status'] ?? 'open';
$validStatuses = ['draft','sent','accepted','rejected','expired','all','open'];
if (!in_array($filterStatus, $validStatuses, true)) $filterStatus = 'open';

$where = [];
$params = [];
if ($filterStatus === 'open') {
    // Not-yet-active quotes: default view
    $where[] = "c.status IN ('draft','sent','accepted')";
} elseif ($filterStatus !== 'all') {
    $where[] = "c.status = ?";
    $params[] = $filterStatus;
} else {
    // 'all' quotes view excludes active/complete/cancelled — those are contracts
    $where[] = "c.status IN ('draft','sent','accepted','rejected','expired')";
}

$sql = "SELECT c.*,
               pp.full_name AS patient_name, pp.tch_id AS patient_tch_id,
               cp.full_name AS client_name,
               o.opp_ref AS opp_ref,
               o.title   AS opp_title,
               (SELECT COUNT(*) FROM contract_lines WHERE contract_id = c.id) AS line_count,
               (SELECT SUM(bill_rate * units_per_period) FROM contract_lines WHERE contract_id = c.id) AS period_total
          FROM contracts c
     LEFT JOIN persons pp ON pp.id = c.patient_person_id
     LEFT JOIN clients cl ON cl.id = c.client_id
     LEFT JOIN persons cp ON cp.id = cl.person_id
     LEFT JOIN opportunities o ON o.id = c.opportunity_id";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY c.status = 'draft' DESC, c.updated_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Tab counts
$counts = $db->query(
    "SELECT status, COUNT(*) n
       FROM contracts
      WHERE status IN ('draft','sent','accepted','rejected','expired')
   GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$totalAll = array_sum($counts);
$openCount = ($counts['draft'] ?? 0) + ($counts['sent'] ?? 0) + ($counts['accepted'] ?? 0);

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <p style="color:#666;margin:0;"><?= count($rows) ?> quote<?= count($rows) === 1 ? '' : 's' ?></p>
    <?php if ($canCreate): ?>
        <a href="<?= APP_URL ?>/admin/quotes/new" class="btn btn-primary btn-sm">+ New Quote</a>
    <?php endif; ?>
</div>

<div style="display:flex;gap:0.4rem;margin-bottom:1rem;flex-wrap:wrap;">
    <?php
    $tabs = [
        'open'     => ['Open',     '#198754', $openCount],
        'draft'    => ['Draft',    '#6c757d', (int)($counts['draft']    ?? 0)],
        'sent'     => ['Sent',     '#0d6efd', (int)($counts['sent']     ?? 0)],
        'accepted' => ['Accepted', '#198754', (int)($counts['accepted'] ?? 0)],
        'rejected' => ['Rejected', '#dc3545', (int)($counts['rejected'] ?? 0)],
        'expired'  => ['Expired',  '#fd7e14', (int)($counts['expired']  ?? 0)],
        'all'      => ['All',      '#212529', $totalAll],
    ];
    foreach ($tabs as $key => [$label, $colour, $count]):
        $active = ($filterStatus === $key);
    ?>
        <a href="?status=<?= $key ?>" style="padding:0.4rem 0.9rem;border-radius:5px;text-decoration:none;background:<?= $active ? $colour : '#f8f9fa' ?>;color:<?= $active ? '#fff' : '#495057' ?>;border:1px solid #dee2e6;font-size:0.85rem;">
            <?= $label ?> <span style="opacity:0.75;">(<?= $count ?>)</span>
        </a>
    <?php endforeach; ?>
</div>

<table class="report-table tch-data-table">
    <thead>
        <tr>
            <th>Ref</th>
            <th>Opportunity</th>
            <th>Client / Patient</th>
            <th class="center">Status</th>
            <th class="center" data-filterable="false">Updated</th>
            <th class="number" data-filterable="false">Lines</th>
            <th class="number" data-filterable="false">Period R</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $statusColour = [
            'draft'    => '#6c757d',
            'sent'     => '#0d6efd',
            'accepted' => '#198754',
            'rejected' => '#dc3545',
            'expired'  => '#fd7e14',
        ][$r['status']] ?? '#6c757d';
    ?>
        <tr style="cursor:pointer;" onclick="window.location='<?= APP_URL ?>/admin/quotes/<?= (int)$r['id'] ?>'">
            <td>
                <?php if (!empty($r['quote_reference'])): ?>
                    <code style="color:#6c757d;font-size:0.8rem;"><?= htmlspecialchars($r['quote_reference']) ?></code>
                <?php else: ?>
                    <span style="color:#adb5bd;font-size:0.8rem;">#<?= (int)$r['id'] ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($r['opp_ref']): ?>
                    <code style="color:#6c757d;font-size:0.78rem;"><?= htmlspecialchars($r['opp_ref']) ?></code>
                    <span style="display:block;font-size:0.85rem;"><?= htmlspecialchars($r['opp_title'] ?? '') ?></span>
                <?php else: ?>
                    <span style="color:#adb5bd;">— direct —</span>
                <?php endif; ?>
            </td>
            <td>
                <strong><?= htmlspecialchars($r['client_name'] ?? '—') ?></strong>
                <?php if ($r['patient_name'] && $r['patient_name'] !== $r['client_name']): ?>
                    <span style="display:block;font-size:0.82rem;color:#6c757d;">→ <?= htmlspecialchars($r['patient_name']) ?></span>
                <?php endif; ?>
            </td>
            <td class="center">
                <span style="background:<?= $statusColour ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">
                    <?= htmlspecialchars($r['status']) ?>
                </span>
            </td>
            <td class="center" style="color:#6c757d;font-size:0.82rem;">
                <?= htmlspecialchars(date('Y-m-d', strtotime($r['updated_at']))) ?>
            </td>
            <td class="number"><?= (int)$r['line_count'] ?></td>
            <td class="number"><?= $r['period_total'] !== null ? 'R' . number_format((float)$r['period_total'], 0) : '—' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?>
        <tr><td colspan="7" style="text-align:center;color:#6c757d;padding:2rem;">
            No quotes in this view.
            <?php if ($canCreate): ?>
                <a href="<?= APP_URL ?>/admin/quotes/new">Create the first one</a>
                or
                <a href="<?= APP_URL ?>/admin/pipeline">open an opportunity from the pipeline</a>.
            <?php endif; ?>
        </td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
