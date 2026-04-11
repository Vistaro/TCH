<?php
/**
 * Email outbox list — /admin/email-log
 *
 * Lists every email queued through Mailer::send() most-recent-first.
 * Click into a row to see the full body — useful in dev when shared-host
 * mail() drops messages and you need to copy the link manually.
 *
 * Permission: email_log.read
 */

$pageTitle = 'Email Outbox';
$activeNav = 'email-log';

$db = getDB();

$filterStatus = trim($_GET['status'] ?? '');
$filterTpl    = trim($_GET['template'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];
if ($filterStatus !== '') {
    $where[]  = 'status = ?';
    $params[] = $filterStatus;
}
if ($filterTpl !== '') {
    $where[]  = 'template = ?';
    $params[] = $filterTpl;
}
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM email_log $whereSQL");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = $db->prepare(
    "SELECT id, to_email, to_name, subject, template, status, error_message, created_at, sent_at
     FROM email_log
     $whereSQL
     ORDER BY id DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$templates = $db->query("SELECT DISTINCT template FROM email_log WHERE template IS NOT NULL ORDER BY template")->fetchAll(PDO::FETCH_COLUMN);

require APP_ROOT . '/templates/layouts/admin.php';
?>

<p style="color:#666;margin-bottom:1rem;">
    Every email queued through the system is logged here. The body is preserved verbatim,
    so during dev you can copy reset / invite links from here even if the actual SMTP
    delivery failed silently.
</p>

<form method="GET" action="<?= APP_URL ?>/admin/email-log" class="report-filters">
    <div class="filter-group">
        <label>Status</label>
        <select name="status">
            <option value="">All</option>
            <option value="queued" <?= $filterStatus === 'queued' ? 'selected' : '' ?>>Queued</option>
            <option value="sent"   <?= $filterStatus === 'sent'   ? 'selected' : '' ?>>Sent</option>
            <option value="failed" <?= $filterStatus === 'failed' ? 'selected' : '' ?>>Failed</option>
        </select>
    </div>
    <div class="filter-group">
        <label>Template</label>
        <select name="template">
            <option value="">All</option>
            <?php foreach ($templates as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $filterTpl === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary">Filter</button>
    <a href="<?= APP_URL ?>/admin/email-log" class="btn btn-outline btn-sm">Clear</a>
</form>

<p style="color:#666;margin:0.75rem 0;">
    <?= number_format($total) ?> emails · page <?= $page ?> of <?= $totalPages ?>
</p>

<div class="report-table-wrap">
    <table class="name-table tch-data-table">
        <thead>
            <tr>
                <th>When</th>
                <th>To</th>
                <th>Subject</th>
                <th>Template</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="6" style="text-align:center;color:#999;padding:2rem;">No emails in the outbox.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['created_at']) ?></td>
                        <td>
                            <?= htmlspecialchars($r['to_name'] ?? '') ?>
                            <?php if ($r['to_name']): ?><br><?php endif; ?>
                            <small><?= htmlspecialchars($r['to_email']) ?></small>
                        </td>
                        <td><?= htmlspecialchars($r['subject']) ?></td>
                        <td><code><?= htmlspecialchars($r['template'] ?? '—') ?></code></td>
                        <td>
                            <?php if ($r['status'] === 'sent'): ?>
                                <span class="badge badge-success">Sent</span>
                            <?php elseif ($r['status'] === 'failed'): ?>
                                <span class="badge badge-danger" title="<?= htmlspecialchars($r['error_message'] ?? '') ?>">Failed</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Queued</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?= APP_URL ?>/admin/email-log/<?= (int)$r['id'] ?>" class="btn btn-outline btn-sm">View</a>
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
        <a href="<?= APP_URL ?>/admin/email-log?<?= htmlspecialchars($baseQs) ?>&page=<?= $page - 1 ?>" class="btn btn-outline btn-sm">&larr; Prev</a>
    <?php endif; ?>
    <?php if ($page < $totalPages): ?>
        <a href="<?= APP_URL ?>/admin/email-log?<?= htmlspecialchars($baseQs) ?>&page=<?= $page + 1 ?>" class="btn btn-outline btn-sm">Next &rarr;</a>
    <?php endif; ?>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
