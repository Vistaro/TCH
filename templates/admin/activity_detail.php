<?php
/**
 * Activity log entry detail — /admin/activity/{id}
 *
 * Renders a single activity_log row in full, including a field-level
 * diff between before_json and after_json when both are present.
 *
 * Permission: activity_log.read
 */

$pageTitle = 'Activity Detail';
$activeNav = 'activity';

require_once APP_ROOT . '/includes/activity_log_render.php';
require_once APP_ROOT . '/includes/activity_log_revert.php';

$db = getDB();
$activityId = (int)($_GET['activity_id'] ?? 0);

// ── Revert handler ──────────────────────────────────────────────────────
// POST with action=revert_field triggers a single-field revert (Level 1).
// Gated to users with activity_log.edit permission. CSRF checked.
// Flash messages are passed to the page render via $flash / $flashType.
$flash = null;
$flashType = null;
$canRevert = userCan('activity_log', 'edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revert_field') {
    if (!$canRevert) {
        http_response_code(403);
        $flash = 'You do not have permission to revert fields.';
        $flashType = 'error';
    } elseif (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.';
        $flashType = 'error';
    } else {
        $fieldToRevert = (string)($_POST['field'] ?? '');
        $result = activity_revert_field($activityId, $fieldToRevert);
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
    }
}

$stmt = $db->prepare(
    'SELECT al.*,
            u_real.email AS real_email, u_real.full_name AS real_name,
            u_imp.email  AS imp_email,  u_imp.full_name  AS imp_name
     FROM activity_log al
     LEFT JOIN users u_real ON u_real.id = al.real_user_id
     LEFT JOIN users u_imp  ON u_imp.id  = al.impersonator_user_id
     WHERE al.id = ?'
);
$stmt->execute([$activityId]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    require APP_ROOT . '/templates/layouts/admin.php';
    echo '<p>No activity log entry with id ' . (int)$activityId . '.</p>';
    echo '<p><a href="' . APP_URL . '/admin/activity">Back to activity log</a></p>';
    require APP_ROOT . '/templates/layouts/admin_footer.php';
    return;
}

$before = activity_decode_snapshot($row['before_json']);
$after  = activity_decode_snapshot($row['after_json']);
$diff   = activity_compute_diff($before, $after);

// Show revert controls only when every gate aligns: user has permission,
// entity type is in the whitelist, the log entry isn't already a revert
// (don't chain revert-of-revert through the UI), and we have real diff
// data to work with.
$entityType = $row['entity_type'] ?? null;
$showRevertColumn = $canRevert
    && activity_revert_entity_is_supported($entityType)
    && $row['action'] !== 'field_reverted'
    && !empty($diff);

require APP_ROOT . '/templates/layouts/admin.php';
?>

<?php if ($flash !== null): ?>
    <div class="alert alert-<?= $flashType === 'success' ? 'success' : ($flashType === 'error' ? 'error' : 'info') ?>" style="margin-bottom:1rem;">
        <?= htmlspecialchars($flash) ?>
    </div>
<?php endif; ?>

<div style="margin-bottom:1rem;">
    <a href="<?= APP_URL ?>/admin/activity" class="btn btn-outline btn-sm">&larr; Back to activity log</a>
</div>

<div class="person-card">
    <div class="person-card-section">
        <h2>Activity #<?= (int)$row['id'] ?></h2>
        <dl>
            <dt>When</dt>
            <dd><?= htmlspecialchars($row['created_at']) ?></dd>

            <dt>Action</dt>
            <dd><code><?= htmlspecialchars($row['action']) ?></code></dd>

            <dt>Actor (real)</dt>
            <dd>
                <?php if ($row['real_user_id']): ?>
                    <strong><?= htmlspecialchars($row['real_email'] ?? '#'.$row['real_user_id']) ?></strong>
                    <?php if ($row['real_name']): ?> &mdash; <?= htmlspecialchars($row['real_name']) ?><?php endif; ?>
                <?php else: ?>
                    <span style="color:#999;">anonymous</span>
                    (public form, token-based, or CLI)
                <?php endif; ?>
            </dd>

            <?php if ($row['impersonator_user_id']): ?>
                <dt>Impersonator</dt>
                <dd>
                    <span class="badge badge-warning">
                        <?= htmlspecialchars($row['imp_email'] ?? '#'.$row['impersonator_user_id']) ?>
                    </span>
                    &mdash; the human at the keyboard during this action
                </dd>
            <?php endif; ?>

            <dt>Page</dt>
            <dd><?= htmlspecialchars($row['page_code'] ?? '—') ?></dd>

            <dt>Entity</dt>
            <dd>
                <?= htmlspecialchars($row['entity_type'] ?? '—') ?>
                <?php if ($row['entity_id']): ?>
                    #<?= (int)$row['entity_id'] ?>
                    <?php if ($row['entity_type'] === 'users'): ?>
                        &mdash; <a href="<?= APP_URL ?>/admin/users/<?= (int)$row['entity_id'] ?>">view user</a>
                    <?php elseif ($row['entity_type'] === 'enquiries'): ?>
                        &mdash; <a href="<?= APP_URL ?>/admin/enquiries?id=<?= (int)$row['entity_id'] ?>">view enquiry</a>
                    <?php endif; ?>
                <?php endif; ?>
            </dd>

            <dt>Summary</dt>
            <dd><?= htmlspecialchars($row['summary'] ?? '') ?></dd>

            <dt>IP address</dt>
            <dd><?= htmlspecialchars($row['ip_address'] ?? '—') ?></dd>

            <dt>User agent</dt>
            <dd><small><?= htmlspecialchars($row['user_agent'] ?? '—') ?></small></dd>
        </dl>
    </div>

    <?php if (!empty($diff)): ?>
        <div class="person-card-section">
            <h3>Changes</h3>
            <?php if ($showRevertColumn): ?>
                <p style="color:#666;font-size:0.85rem;margin:0 0 0.5rem 0;">
                    You can revert any individual field back to its previous value.
                    The revert will be recorded as a new audit entry. If the field
                    has been changed again since this action, the revert will be
                    refused so newer work isn't overwritten.
                </p>
            <?php endif; ?>
            <table class="name-table">
                <thead>
                    <tr>
                        <th>Field</th>
                        <th>Was</th>
                        <th>Now</th>
                        <?php if ($showRevertColumn): ?><th style="width:110px;">Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($diff as $field => [$wasValue, $nowValue]): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($field) ?></strong></td>
                            <td class="diff-was-cell"><?= activity_render_value($wasValue) ?></td>
                            <td class="diff-now-cell"><?= activity_render_value($nowValue) ?></td>
                            <?php if ($showRevertColumn): ?>
                                <td>
                                    <form method="POST"
                                          action="<?= APP_URL ?>/admin/activity/<?= (int)$row['id'] ?>"
                                          onsubmit="return confirm('Revert field &quot;<?= htmlspecialchars($field, ENT_QUOTES) ?>&quot; back to its previous value? A new audit entry will be created.');"
                                          style="margin:0;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="revert_field">
                                        <input type="hidden" name="field" value="<?= htmlspecialchars($field, ENT_QUOTES) ?>">
                                        <button type="submit" class="btn btn-outline btn-sm">Revert</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($before !== null || $after !== null): ?>
        <div class="person-card-section">
            <h3>Changes</h3>
            <p style="color:#999;">
                Before/after snapshots are present but identical — no fields actually changed.
            </p>
        </div>
    <?php else: ?>
        <div class="person-card-section">
            <h3>Changes</h3>
            <p style="color:#999;">
                This action did not capture a field-level diff (e.g. login, logout, status
                action without before/after, public submission, or token-based flow).
            </p>
        </div>
    <?php endif; ?>

    <?php if ($before !== null || $after !== null): ?>
        <details class="person-card-section">
            <summary style="cursor:pointer;font-weight:600;">Raw JSON snapshots</summary>
            <?php if ($before !== null): ?>
                <h4 style="margin-top:0.75rem;">Before</h4>
                <pre class="import-notes" style="white-space:pre-wrap;word-wrap:break-word;"><?= htmlspecialchars(json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
            <?php endif; ?>
            <?php if ($after !== null): ?>
                <h4>After</h4>
                <pre class="import-notes" style="white-space:pre-wrap;word-wrap:break-word;"><?= htmlspecialchars(json_encode($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
            <?php endif; ?>
        </details>
    <?php endif; ?>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
