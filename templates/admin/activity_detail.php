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

// ── Revert / rollback handlers ──────────────────────────────────────────
// POST with action=revert_field    → single-field revert  (A2, Level 1)
// GET  with preview_rollback=1     → whole-record rollback preview (A3, Level 2)
// POST with action=apply_rollback  → whole-record rollback apply   (A3, Level 2)
//
// A2 is gated to activity_log.edit (Super Admin + Admin + Manager).
// A3 is gated to Super Admin only — rolling back a record can discard
// newer work on purpose, so the bar is higher.
$flash = null;
$flashType = null;
$canRevert   = userCan('activity_log', 'edit');
$canRollback = isSuperAdmin();

// Rollback preview state (populated on ?preview_rollback=1 for super admins)
$rollbackPlan    = null;
$showRollbackPreview = false;

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
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_rollback') {
    if (!$canRollback) {
        http_response_code(403);
        $flash = 'Only a Super Admin can roll back a whole record.';
        $flashType = 'error';
    } elseif (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.';
        $flashType = 'error';
    } elseif (($_POST['confirmed'] ?? '') !== '1') {
        $flash = 'Rollback not confirmed.';
        $flashType = 'error';
    } else {
        $result = activity_rollback_apply($activityId);
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'undelete') {
    // A4 — Undelete. Gated to Super Admin only, same as rollback.
    if (!$canRollback) {
        http_response_code(403);
        $flash = 'Only a Super Admin can undelete a record.';
        $flashType = 'error';
    } elseif (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.';
        $flashType = 'error';
    } else {
        $result = activity_undelete($activityId);
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
    }
} elseif ($canRollback && isset($_GET['preview_rollback'])) {
    $rollbackPlan = activity_rollback_compute_plan($activityId);
    $showRollbackPreview = true;
    if (!$rollbackPlan['ok']) {
        $flash = $rollbackPlan['message'];
        $flashType = 'error';
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

// Show the "Restore whole record to this point" button under the same
// gates plus the stronger Super Admin requirement. Exclude rollback log
// entries themselves and field-revert entries — users can reach them via
// the original entry if they want to rewind further.
$showRollbackButton = $canRollback
    && activity_revert_entity_is_supported($entityType)
    && !in_array($row['action'], ['field_reverted', 'record_rolled_back', 'record_deleted', 'record_undeleted'], true)
    && $before !== null;

// A4 — Undelete button. Only shown on record_deleted entries (the
// before_json on a delete carries the full captured row). Super Admin
// only. Suppressed if the record has already been restored at the same
// id (activity_undelete() will detect this at apply time anyway, but
// hiding the button is cleaner).
$showUndeleteButton = $canRollback
    && $row['action'] === 'record_deleted'
    && activity_revert_entity_is_supported($entityType)
    && $before !== null
    && !empty($before);

require APP_ROOT . '/templates/layouts/admin.php';
?>

<?php if ($flash !== null): ?>
    <div class="alert alert-<?= $flashType === 'success' ? 'success' : ($flashType === 'error' ? 'error' : 'info') ?>" style="margin-bottom:1rem;">
        <?= htmlspecialchars($flash) ?>
    </div>
<?php endif; ?>

<div style="margin-bottom:1rem;display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
    <a href="<?= APP_URL ?>/admin/activity" class="btn btn-outline btn-sm">&larr; Back to activity log</a>
    <?php if ($showRollbackButton && !$showRollbackPreview): ?>
        <a href="<?= APP_URL ?>/admin/activity/<?= (int)$row['id'] ?>?preview_rollback=1"
           class="btn btn-outline btn-sm"
           style="color:#B45309;border-color:#F59E0B;">
            Restore whole record to this point&hellip;
        </a>
    <?php endif; ?>
    <?php if ($showUndeleteButton): ?>
        <form method="POST"
              action="<?= APP_URL ?>/admin/activity/<?= (int)$row['id'] ?>"
              onsubmit="return confirm('Undelete this record? The row will be re-inserted with its original id. Related/child records that were deleted alongside the original are NOT restored — only the primary row. A new audit entry will be created.');"
              style="margin:0;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="undelete">
            <button type="submit" class="btn btn-outline btn-sm" style="color:#1E8449;border-color:#27AE60;">
                Undelete this record&hellip;
            </button>
        </form>
    <?php endif; ?>
</div>

<?php if ($showRollbackPreview && $rollbackPlan && $rollbackPlan['ok']): ?>
    <div class="person-card" style="border:2px solid #F59E0B;margin-bottom:1rem;">
        <div class="person-card-section">
            <h3 style="color:#B45309;margin-top:0;">Rollback preview</h3>
            <p>
                This will restore <strong><?= htmlspecialchars($rollbackPlan['entity_type']) ?>
                #<?= (int)$rollbackPlan['entity_id'] ?></strong> to the state it was in
                <em>before</em> activity log #<?= (int)$row['id'] ?> was applied.
            </p>

            <?php if (!empty($rollbackPlan['intermediate_edits'])): ?>
                <div class="alert alert-error" style="margin:0.5rem 0;">
                    <strong>Warning — newer edits will be lost.</strong><br>
                    The following fields have been edited in more than one log entry
                    since the target point. Rolling back will discard ALL of those
                    newer changes on these fields:
                    <ul style="margin:0.5rem 0 0 1rem;">
                        <?php foreach ($rollbackPlan['intermediate_edits'] as $f => $count): ?>
                            <li><strong><?= htmlspecialchars($f) ?></strong> —
                                touched by <?= (int)$count ?> log entries since</li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($rollbackPlan['dropped_fields'])): ?>
                <p style="color:#666;font-size:0.85rem;">
                    Ignored (synthetic diff fields not present as real columns):
                    <?= htmlspecialchars(implode(', ', $rollbackPlan['dropped_fields'])) ?>
                </p>
            <?php endif; ?>

            <h4>Fields that will change</h4>
            <table class="name-table">
                <thead>
                    <tr>
                        <th>Field</th>
                        <th>Current</th>
                        <th>After rollback</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rollbackPlan['target_state'] as $field => $target): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($field) ?></strong></td>
                            <td class="diff-was-cell"><?= activity_render_value($rollbackPlan['current_state'][$field] ?? null) ?></td>
                            <td class="diff-now-cell"><?= activity_render_value($target) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <form method="POST"
                  action="<?= APP_URL ?>/admin/activity/<?= (int)$row['id'] ?>"
                  onsubmit="return confirm('Apply this rollback? This will overwrite the listed fields with their at-that-time values. A new audit entry will be created. This cannot be undone by clicking the same button.');"
                  style="margin-top:1rem;display:flex;gap:0.5rem;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="apply_rollback">
                <input type="hidden" name="confirmed" value="1">
                <button type="submit" class="btn btn-primary" style="background:#B45309;border-color:#B45309;">
                    Apply rollback (<?= count($rollbackPlan['target_state']) ?> field<?= count($rollbackPlan['target_state']) === 1 ? '' : 's' ?>)
                </button>
                <a href="<?= APP_URL ?>/admin/activity/<?= (int)$row['id'] ?>" class="btn btn-outline">Cancel</a>
            </form>
        </div>
    </div>
<?php elseif ($showRollbackPreview && $rollbackPlan && empty($rollbackPlan['target_state'])): ?>
    <div class="alert alert-info" style="margin-bottom:1rem;">
        The record is already at the state it was in at this point — nothing to roll back.
        <a href="<?= APP_URL ?>/admin/activity/<?= (int)$row['id'] ?>">Close preview</a>
    </div>
<?php endif; ?>

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
