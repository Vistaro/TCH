<?php
/**
 * Opportunities list — /admin/opportunities
 *
 * Sales pipeline record list. One row per opportunity. Filterable
 * by stage, owner, and status (open/closed/archived). Click-through
 * to detail page. Kanban view of the same data lives at
 * /admin/pipeline.
 */
require_once APP_ROOT . '/includes/opportunities.php';

$pageTitle = 'Opportunities';
$activeNav = 'opportunities';

$db = getDB();
$canCreate = userCan('opportunities', 'create');

$stages = fetchSalesStages($db, false);

// ── Filters ────────────────────────────────────────────────────
$filterStage  = $_GET['stage']  ?? 'open';   // 'open' = any non-closed stage
$filterOwner  = isset($_GET['owner'])  && $_GET['owner']  !== '' ? (int)$_GET['owner']  : 0;
$filterStatus = $_GET['status'] ?? 'open';

if (!in_array($filterStatus, ['open','closed','archived','all'], true)) $filterStatus = 'open';

$where = [];
$params = [];

if ($filterStatus !== 'all') {
    $where[] = 'o.status = ?';
    $params[] = $filterStatus;
}

if (ctype_digit((string)$filterStage)) {
    $where[] = 'o.stage_id = ?';
    $params[] = (int)$filterStage;
} elseif ($filterStage === 'open') {
    // Shortcut: exclude closed-won / closed-lost stages
    $where[] = 's.is_closed_won = 0 AND s.is_closed_lost = 0';
}

if ($filterOwner > 0) {
    $where[] = 'o.owner_user_id = ?';
    $params[] = $filterOwner;
}

$sql = "SELECT o.*,
               s.name AS stage_name, s.slug AS stage_slug,
               s.is_closed_won, s.is_closed_lost,
               cp.full_name AS client_name,
               pp.full_name AS patient_name,
               u.full_name  AS owner_name
          FROM opportunities o
     LEFT JOIN sales_stages s ON s.id = o.stage_id
     LEFT JOIN clients    cl  ON cl.id = o.client_id
     LEFT JOIN persons    cp  ON cp.id = cl.person_id
     LEFT JOIN persons    pp  ON pp.id = o.patient_person_id
     LEFT JOIN users      u   ON u.id  = o.owner_user_id";

if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY s.sort_order, o.created_at DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Owner picker options (any user who currently owns an opp, plus current user)
$owners = $db->query(
    "SELECT DISTINCT u.id, u.full_name, u.email
       FROM users u
       JOIN opportunities o ON o.owner_user_id = u.id
      ORDER BY u.full_name"
)->fetchAll(PDO::FETCH_ASSOC);

// Headline counts for the top tabs
$statusCounts = $db->query("SELECT status, COUNT(*) n FROM opportunities GROUP BY status")
                   ->fetchAll(PDO::FETCH_KEY_PAIR);
$totalAll = array_sum($statusCounts);

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <p style="color:#666;margin:0;"><?= count($rows) ?> opportunit<?= count($rows) === 1 ? 'y' : 'ies' ?></p>
    <div style="display:flex;gap:0.5rem;">
        <a href="<?= APP_URL ?>/admin/pipeline" class="btn btn-sm" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;">⬛ Kanban view</a>
        <?php if ($canCreate): ?>
            <a href="<?= APP_URL ?>/admin/opportunities/new" class="btn btn-primary btn-sm">+ New Opportunity</a>
        <?php endif; ?>
    </div>
</div>

<!-- Status tabs -->
<div style="display:flex;gap:0.4rem;margin-bottom:1rem;flex-wrap:wrap;">
    <?php
    $statusTabs = [
        'open'     => ['Open',     '#198754'],
        'closed'   => ['Closed',   '#6c757d'],
        'archived' => ['Archived', '#adb5bd'],
        'all'      => ['All',      '#212529'],
    ];
    foreach ($statusTabs as $key => [$label, $colour]):
        $count = ($key === 'all') ? $totalAll : (int)($statusCounts[$key] ?? 0);
        $active = ($filterStatus === $key);
        $qs = http_build_query(array_filter(['status'=>$key, 'stage'=>$filterStage, 'owner'=>$filterOwner ?: null]));
    ?>
        <a href="?<?= $qs ?>" style="padding:0.4rem 0.9rem;border-radius:5px;text-decoration:none;background:<?= $active ? $colour : '#f8f9fa' ?>;color:<?= $active ? '#fff' : '#495057' ?>;border:1px solid #dee2e6;font-size:0.85rem;">
            <?= $label ?> <span style="opacity:0.75;">(<?= $count ?>)</span>
        </a>
    <?php endforeach; ?>
</div>

<!-- Stage + owner filters -->
<form method="get" style="display:flex;gap:0.6rem;margin-bottom:1rem;flex-wrap:wrap;align-items:end;">
    <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
    <label style="font-size:0.85rem;">
        <span style="display:block;color:#6c757d;font-size:0.75rem;">Stage</span>
        <select name="stage" onchange="this.form.submit()" style="padding:0.35rem 0.5rem;">
            <option value="open" <?= $filterStage === 'open' ? 'selected' : '' ?>>All open stages</option>
            <?php foreach ($stages as $s):
                if (!$s['is_active']) continue; ?>
                <option value="<?= (int)$s['id'] ?>" <?= $filterStage == $s['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($s['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <?php if (!empty($owners)): ?>
    <label style="font-size:0.85rem;">
        <span style="display:block;color:#6c757d;font-size:0.75rem;">Owner</span>
        <select name="owner" onchange="this.form.submit()" style="padding:0.35rem 0.5rem;">
            <option value="">Anyone</option>
            <?php foreach ($owners as $o): ?>
                <option value="<?= (int)$o['id'] ?>" <?= $filterOwner === (int)$o['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($o['full_name'] ?? $o['email']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php endif; ?>

    <?php if ($filterStage !== 'open' || $filterOwner): ?>
        <a href="?status=<?= htmlspecialchars($filterStatus) ?>" style="font-size:0.85rem;color:#0d6efd;">Clear filters</a>
    <?php endif; ?>
</form>

<table class="report-table tch-data-table">
    <thead>
        <tr>
            <th>Ref</th>
            <th>Title</th>
            <th>Stage</th>
            <th>Owner</th>
            <th>Client / Patient</th>
            <th class="center" data-filterable="false">Expected start</th>
            <th class="number" data-filterable="false">Est. value</th>
            <th class="center" data-filterable="false">Created</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $closed = (int)$r['is_closed_won'] === 1 || (int)$r['is_closed_lost'] === 1;
        $stageColour = (int)$r['is_closed_won'] === 1 ? '#198754'
                     : ((int)$r['is_closed_lost'] === 1 ? '#dc3545' : '#0d6efd');
    ?>
        <tr style="cursor:pointer;<?= $closed ? 'opacity:0.75;' : '' ?>"
            onclick="window.location='<?= APP_URL ?>/admin/opportunities/<?= (int)$r['id'] ?>'">
            <td>
                <code style="color:#6c757d;font-size:0.8rem;"><?= htmlspecialchars($r['opp_ref']) ?></code>
                <?php if (!empty($r['is_test_data'])): ?>
                    <span style="background:#fbbf24;color:#78350f;padding:1px 5px;border-radius:3px;font-size:0.65rem;font-weight:700;letter-spacing:0.05em;vertical-align:middle;">TEST</span>
                <?php endif; ?>
            </td>
            <td><strong><?= htmlspecialchars($r['title']) ?></strong></td>
            <td>
                <span style="background:<?= $stageColour ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">
                    <?= htmlspecialchars($r['stage_name'] ?? '—') ?>
                </span>
            </td>
            <td><?= htmlspecialchars($r['owner_name'] ?? '—') ?></td>
            <td>
                <?php if ($r['client_name']): ?>
                    <?= htmlspecialchars($r['client_name']) ?>
                    <?php if ($r['patient_name'] && $r['patient_name'] !== $r['client_name']): ?>
                        <span style="color:#6c757d;font-size:0.82rem;">→ <?= htmlspecialchars($r['patient_name']) ?></span>
                    <?php endif; ?>
                <?php elseif ($r['contact_name']): ?>
                    <span style="color:#6c757d;"><?= htmlspecialchars($r['contact_name']) ?> <em>(not yet linked)</em></span>
                <?php else: ?>
                    <span style="color:#adb5bd;">—</span>
                <?php endif; ?>
            </td>
            <td class="center">
                <?= $r['expected_start_date'] ? htmlspecialchars($r['expected_start_date']) : '<span style="color:#adb5bd;">—</span>' ?>
            </td>
            <td class="number">
                <?= $r['expected_value_cents'] !== null
                    ? 'R' . number_format((int)$r['expected_value_cents'] / 100, 0)
                    : '<span style="color:#adb5bd;">—</span>' ?>
            </td>
            <td class="center" style="color:#6c757d;font-size:0.82rem;">
                <?= htmlspecialchars(date('Y-m-d', strtotime($r['created_at']))) ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?>
        <tr><td colspan="8" style="text-align:center;color:#6c757d;padding:2rem;">
            No opportunities match these filters.
            <?php if ($canCreate): ?>
                <a href="<?= APP_URL ?>/admin/opportunities/new">Create the first one</a>
                or
                <a href="<?= APP_URL ?>/admin/enquiries">convert an enquiry</a>.
            <?php endif; ?>
        </td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
