<?php
/**
 * Single email body view — /admin/email-log/{id}
 *
 * Shows the full email envelope + body verbatim. Useful for grabbing reset
 * links during dev when SMTP delivery isn't reliable.
 *
 * Permission: email_log.read
 */

$pageTitle = 'Email';
$activeNav = 'email-log';

$db = getDB();
$emailId = (int)($_GET['email_id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM email_log WHERE id = ?');
$stmt->execute([$emailId]);
$email = $stmt->fetch();

if (!$email) {
    http_response_code(404);
    require APP_ROOT . '/templates/layouts/admin.php';
    echo '<p>No email with id ' . (int)$emailId . '.</p>';
    require APP_ROOT . '/templates/layouts/admin_footer.php';
    return;
}

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="margin-bottom:1rem;">
    <a href="<?= APP_URL ?>/admin/email-log" class="btn btn-outline btn-sm">&larr; Back to outbox</a>
</div>

<div class="person-card" style="max-width:800px;">
    <div class="person-card-section">
        <h2><?= htmlspecialchars($email['subject']) ?></h2>
        <dl>
            <dt>Status</dt>
            <dd>
                <?php if ($email['status'] === 'sent'): ?>
                    <span class="badge badge-success">Sent</span> at <?= htmlspecialchars($email['sent_at'] ?? '') ?>
                <?php elseif ($email['status'] === 'failed'): ?>
                    <span class="badge badge-danger">Failed</span> — <?= htmlspecialchars($email['error_message'] ?? '') ?>
                <?php else: ?>
                    <span class="badge badge-warning">Queued</span>
                <?php endif; ?>
            </dd>
            <dt>From</dt>
            <dd><?= htmlspecialchars(($email['from_name'] ? $email['from_name'] . ' <' : '') . $email['from_email'] . ($email['from_name'] ? '>' : '')) ?></dd>
            <dt>To</dt>
            <dd><?= htmlspecialchars(($email['to_name'] ? $email['to_name'] . ' <' : '') . $email['to_email'] . ($email['to_name'] ? '>' : '')) ?></dd>
            <dt>Template</dt>
            <dd><code><?= htmlspecialchars($email['template'] ?? '—') ?></code></dd>
            <dt>Created</dt>
            <dd><?= htmlspecialchars($email['created_at']) ?></dd>
        </dl>
    </div>

    <div class="person-card-section">
        <h3>Body</h3>
        <pre class="import-notes" style="white-space:pre-wrap;word-wrap:break-word;"><?= htmlspecialchars($email['body_text']) ?></pre>
    </div>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
