<?php
/**
 * Activity log viewer — /admin/activity
 *
 * Filters: action, user (real), date range, entity_type. Pagination via offset.
 *
 * Permission: activity_log.read
 */

$pageTitle = 'Activity Log';
$activeNav = 'activity';

require_once APP_ROOT . '/includes/activity_log_render.php';

$db = getDB();

$filterAction  = trim($_GET['action'] ?? '');
$filterUserId  = $_GET['user_id'] ?? '';
$filterEntity  = trim($_GET['entity'] ?? '');
$filterFrom    = trim($_GET['from'] ?? '');
$filterTo      = trim($_GET['to'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = 50;
$offset        = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($filterAction !== '') {
    $where[]  = 'al.action = ?';
    $params[] = $filterAction;
}
if ($filterUserId !== '' && ctype_digit($filterUserId)) {
    $where[]  = '(al.real_user_id = ? OR al.impersonator_user_id = ?)';
    $params[] = (int)$filterUserId;
    $params[] = (int)$filterUserId;
}
if ($filterEntity !== '') {
    $where[]  = 'al.entity_type = ?';
    $params[] = $filterEntity;
}
if ($filterFrom !== '') {
    $where[]  = 'al.created_at >= ?';
    $params[] = $filterFrom . ' 00:00:00';
}
if ($filterTo !== '') {
    $where[]  = 'al.created_at <= ?';
    $params[] = $filterTo . ' 23:59:59';
}
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM activity_log al $whereSQL");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = $db->prepare(
    "SELECT al.*,
            u_real.email AS real_email, u_real.full_name AS real_name,
            u_imp.email  AS imp_email,  u_imp.full_name  AS imp_name
     FROM activity_log al
     LEFT JOIN users u_real ON u_real.id = al.real_user_id
     LEFT JOIN users u_imp  ON u_imp.id  = al.impersonator_user_id
     $whereSQL
     ORDER BY al.id DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Distinct lists for filter dropdowns
$actions = $db->query("SELECT DISTINCT action FROM activity_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$entities = $db->query("SELECT DISTINCT entity_type FROM activity_log WHERE entity_type IS NOT NULL ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);

require APP_ROOT . '/templates/layouts/admin.php';
?>

<form method="GET" action="<?= APP_URL ?>/admin/activity" class="report-filters">
    <div class="filter-group">
        <label>Action</label>
        <select name="action">
            <option value="">All actions</option>
            <?php foreach ($actions as $a): ?>
                <option value="<?= htmlspecialchars($a) ?>" <?= $filterAction === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>Entity</label>
        <select name="entity">
            <option value="">All entities</option>
            <?php foreach ($entities as $e): ?>
                <option value="<?= htmlspecialchars($e) ?>" <?= $filterEntity === $e ? 'selected' : '' ?>><?= htmlspecialchars($e) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>User ID</label>
        <input type="number" name="user_id" value="<?= htmlspecialchars($filterUserId) ?>" min="0" placeholder="Real or impersonator">
    </div>
    <div class="filter-group">
        <label>From</label>
        <input type="date" name="from" value="<?= htmlspecialchars($filterFrom) ?>">
    </div>
    <div class="filter-group">
        <label>To</label>
        <input type="date" name="to" value="<?= htmlspecialchars($filterTo) ?>">
    </div>
    <button type="submit" class="btn btn-primary">Filter</button>
    <a href="<?= APP_URL ?>/admin/activity" class="btn btn-outline btn-sm">Clear</a>
</form>

<p style="color:#666;margin:0.75rem 0;">
    <?= number_format($total) ?> entries · page <?= $page ?> of <?= $totalPages ?>
</p>

<div class="report-table-wrap">
    <table class="name-table tch-data-table">
        <thead>
            <tr>
                <th class="center">When</th>
                <th>Actor</th>
                <th>Impersonator</th>
                <th class="center">Action</th>
                <th class="center">Page</th>
                <th>Entity</th>
                <th>Summary</th>
                <th class="center">IP</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="9" style="text-align:center;color:#999;padding:2rem;">No log entries match the filter.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="center"><?= htmlspecialchars($r['created_at']) ?></td>
                        <td>
                            <?php if ($r['real_user_id']): ?>
                                <?= htmlspecialchars($r['real_email'] ?? '#'.$r['real_user_id']) ?>
                            <?php else: ?>
                                <span style="color:#999;">anonymous</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['impersonator_user_id']): ?>
                                <span class="badge badge-warning"><?= htmlspecialchars($r['imp_email'] ?? '#'.$r['impersonator_user_id']) ?></span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="center"><code><?= htmlspecialchars($r['action']) ?></code></td>
                        <td class="center"><?= htmlspecialchars($r['page_code'] ?? '—') ?></td>
                        <td>
                            <?= htmlspecialchars($r['entity_type'] ?? '—') ?>
                            <?= $r['entity_id'] ? '#' . (int)$r['entity_id'] : '' ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($r['summary'] ?? '') ?>
                            <?php
                            // Inline field-level diff: collapsible <details> showing
                            // Was -> Now per changed field. Empty string when there
                            // are no snapshots (login/logout/public actions) or when
                            // before == after.
                            $rowBefore = activity_decode_snapshot($r['before_json'] ?? null);
                            $rowAfter  = activity_decode_snapshot($r['after_json']  ?? null);
                            $rowDiff   = activity_compute_diff($rowBefore, $rowAfter);
                            echo activity_render_inline_diff($rowDiff);
                            ?>
                        </td>
                        <td class="center"><?= htmlspecialchars($r['ip_address'] ?? '—') ?></td>
                        <td>
                            <a href="<?= APP_URL ?>/admin/activity/<?= (int)$r['id'] ?>" class="btn btn-outline btn-sm">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
$qs = $_GET;
unset($qs['page']);
$baseQs = http_build_query($qs);
?>
<div style="display:flex;gap:0.5rem;justify-content:center;margin-top:1rem;">
    <?php if ($page > 1): ?>
        <a href="<?= APP_URL ?>/admin/activity?<?= htmlspecialchars($baseQs) ?>&page=<?= $page - 1 ?>" class="btn btn-outline btn-sm">&larr; Prev</a>
    <?php endif; ?>
    <?php if ($page < $totalPages): ?>
        <a href="<?= APP_URL ?>/admin/activity?<?= htmlspecialchars($baseQs) ?>&page=<?= $page + 1 ?>" class="btn btn-outline btn-sm">Next &rarr;</a>
    <?php endif; ?>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
