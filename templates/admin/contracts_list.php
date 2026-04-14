<?php
/**
 * Contracts list — /admin/contracts
 *
 * Lists commercial care contracts. One row per contract. Click-through
 * to detail page.
 */
$pageTitle = 'Contracts';
$activeNav = 'contracts';
$db = getDB();
$canCreate = userCan('contracts', 'create');

$filterStatus = $_GET['status'] ?? 'active';
if (!in_array($filterStatus, ['all','draft','active','on_hold','cancelled','completed'], true)) $filterStatus = 'active';

$sql = "SELECT c.*,
               pp.full_name AS patient_name, pp.tch_id AS patient_tch_id,
               cp.full_name AS client_name,  cp.tch_id AS client_tch_id,
               cl.account_number,
               (SELECT COUNT(*) FROM contract_lines WHERE contract_id = c.id) AS line_count,
               (SELECT SUM(bill_rate * units_per_period) FROM contract_lines WHERE contract_id = c.id) AS period_total
          FROM contracts c
     LEFT JOIN persons pp ON pp.id = c.patient_person_id
     LEFT JOIN clients cl ON cl.id = c.client_id
     LEFT JOIN persons cp ON cp.id = cl.person_id";
$params = [];
if ($filterStatus !== 'all') { $sql .= " WHERE c.status = ?"; $params[] = $filterStatus; }
$sql .= " ORDER BY c.status = 'active' DESC, c.start_date DESC, c.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Count by status for tabs
$counts = $db->query(
    "SELECT status, COUNT(*) n FROM contracts GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$totalAll = array_sum($counts);

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <p style="color:#666;margin:0;"><?= count($rows) ?> contract<?= count($rows) === 1 ? '' : 's' ?></p>
    <?php if ($canCreate): ?>
        <a href="<?= APP_URL ?>/admin/contracts/new" class="btn btn-primary btn-sm">+ New Contract</a>
    <?php endif; ?>
</div>

<div style="display:flex;gap:0.4rem;margin-bottom:1rem;flex-wrap:wrap;">
    <?php
    $tabs = [
        'active'    => ['Active',    '#198754'],
        'draft'     => ['Draft',     '#6c757d'],
        'on_hold'   => ['On Hold',   '#fd7e14'],
        'completed' => ['Completed', '#0d6efd'],
        'cancelled' => ['Cancelled', '#dc3545'],
        'all'       => ['All',       '#212529'],
    ];
    foreach ($tabs as $key => [$label, $colour]):
        $count = ($key === 'all') ? $totalAll : (int)($counts[$key] ?? 0);
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
            <th>Patient</th>
            <th>Client (bill-payer)</th>
            <th class="center">Status</th>
            <th class="center" data-filterable="false">Start</th>
            <th class="center" data-filterable="false">End</th>
            <th class="number" data-filterable="false">Lines</th>
            <th class="number" data-filterable="false">Period R</th>
            <th class="center">Invoice #</th>
            <th class="center">Paid?</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $statusColour = [
            'draft' => '#6c757d', 'active' => '#198754', 'on_hold' => '#fd7e14',
            'cancelled' => '#dc3545', 'completed' => '#0d6efd',
        ][$r['status']] ?? '#6c757d';
        $invColour = [
            'none' => '#6c757d', 'raised' => '#0d6efd', 'sent' => '#0d6efd',
            'paid' => '#198754', 'overdue' => '#dc3545', 'disputed' => '#fd7e14',
        ][$r['invoice_status']] ?? '#6c757d';
    ?>
        <tr style="cursor:pointer;" onclick="window.location='<?= APP_URL ?>/admin/contracts/<?= (int)$r['id'] ?>'">
            <td><strong><?= htmlspecialchars((string)($r['patient_name'] ?? '—')) ?></strong>
                <code style="color:#6c757d;font-size:0.75rem;"><?= htmlspecialchars((string)($r['patient_tch_id'] ?? '')) ?></code>
            </td>
            <td><?= htmlspecialchars((string)($r['client_name'] ?? '—')) ?>
                <code style="color:#6c757d;font-size:0.75rem;"><?= htmlspecialchars((string)($r['client_tch_id'] ?? '')) ?></code>
            </td>
            <td class="center">
                <span style="background:<?= $statusColour ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">
                    <?= htmlspecialchars(str_replace('_', ' ', $r['status'])) ?>
                </span>
            </td>
            <td class="center"><?= htmlspecialchars($r['start_date']) ?></td>
            <td class="center"><?= $r['end_date'] ? htmlspecialchars($r['end_date']) : '<span style="color:#6c757d;">ongoing</span>' ?></td>
            <td class="number"><?= (int)$r['line_count'] ?></td>
            <td class="number"><?= $r['period_total'] !== null ? 'R' . number_format((float)$r['period_total'], 0) : '—' ?></td>
            <td class="center"><?= htmlspecialchars((string)($r['invoice_number'] ?? '—')) ?></td>
            <td class="center">
                <span style="color:<?= $invColour ?>;font-weight:600;text-transform:capitalize;">
                    <?= htmlspecialchars($r['invoice_status']) ?>
                </span>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?>
        <tr><td colspan="9" style="text-align:center;color:#6c757d;padding:2rem;">
            No contracts<?= $filterStatus !== 'all' ? ' with status "' . htmlspecialchars($filterStatus) . '"' : '' ?>.
            <?php if ($canCreate): ?>
                <a href="<?= APP_URL ?>/admin/contracts/new">Create the first one</a>.
            <?php endif; ?>
        </td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
