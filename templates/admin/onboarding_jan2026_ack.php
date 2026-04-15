<?php
/**
 * Task 6 subpage — Jan 2026 date-serial acknowledgement.
 * One-shot: Tuniti clicks a button to acknowledge the parser is
 * handling the Jan 2026 date-serial bug. Writes to system_acknowledgements.
 */
$pageTitle = 'Jan 2026 Timesheet dates — acknowledgement';
$activeNav = 'onboarding';

$db      = getDB();
$canEdit = userCan('onboarding', 'edit');
$uid     = (int)($_SESSION['user_id'] ?? 0);

$flash = ''; $flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } elseif (($_POST['action'] ?? '') === 'acknowledge') {
        $notes = trim($_POST['notes'] ?? '');
        $stmt = $db->prepare(
            "INSERT IGNORE INTO system_acknowledgements (ack_key, acknowledged_by_user_id, notes)
             VALUES ('jan2026_date_serials', ?, ?)"
        );
        $stmt->execute([$uid, $notes ?: null]);
        logActivity('ack_recorded', 'onboarding', 'system_acknowledgements', 0,
            'Acknowledged Jan 2026 date-serial fix', null, ['ack_key' => 'jan2026_date_serials']);
        $flash = 'Acknowledged. Thanks.';
    }
    header('Location: ' . APP_URL . '/admin/onboarding/jan2026-ack?msg=' . urlencode($flash) . '&type=' . urlencode($flashType));
    exit;
}

if ($flash === '' && isset($_GET['msg'])) {
    $flash = (string)$_GET['msg']; $flashType = (string)($_GET['type'] ?? 'success');
}

// Is it already acknowledged?
$ack = $db->query("SELECT * FROM system_acknowledgements WHERE ack_key = 'jan2026_date_serials'")
          ->fetch(PDO::FETCH_ASSOC);

require APP_ROOT . '/templates/layouts/admin.php';
?>

<?php if ($flash): ?>
<div class="alert alert-<?= htmlspecialchars($flashType === 'error' ? 'error' : 'success') ?>" style="margin-bottom:1rem;">
    <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<p style="margin-bottom:0.5rem;">
    <a href="<?= APP_URL ?>/admin/onboarding" style="font-size:0.85rem;">← Back to tasks</a>
</p>

<h2 style="margin:0 0 0.5rem 0;">Jan 2026 Timesheet tab — date-serial fix</h2>
<p style="color:#6c757d;margin-bottom:1rem;">
    A previous copy of the Timesheet workbook had the "Caregiver Jan 2026" tab populated with date serials from Jan <strong>2025</strong>, not Jan 2026.
    Without correction, shifts would have been filed under the wrong month and year.
</p>

<div style="background:#e7f0fb;border-left:4px solid #0d6efd;padding:0.9rem 1.1rem;border-radius:6px;margin-bottom:1.25rem;">
    <strong>What we changed</strong>
    <p style="margin:0.4rem 0 0 0;color:#333;font-size:0.92rem;">
        The ingest parser now forces the year and month of every shift to match the tab name (e.g. "Caregiver Jan 2026" is always treated as January 2026 regardless of what the date-serial cell actually says).
        This guards against the issue repeating with future copy-paste mistakes.
    </p>
</div>

<?php if ($ack): ?>
    <div style="background:#e7f5ee;border:1px solid #b6e0c8;padding:1rem 1.2rem;border-radius:6px;">
        <strong style="color:#165d36;">✓ Acknowledged.</strong>
        <div style="color:#495057;margin-top:0.3rem;font-size:0.9rem;">
            Recorded <?= htmlspecialchars($ack['acknowledged_at']) ?>.
            <?php if (!empty($ack['notes'])): ?>
                <div style="margin-top:0.3rem;font-style:italic;"><?= htmlspecialchars($ack['notes']) ?></div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <?php if ($canEdit): ?>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="acknowledge">
        <label style="display:block;font-weight:600;margin-bottom:0.3rem;">Optional notes</label>
        <textarea name="notes" class="form-control" rows="2" placeholder="e.g. 'Confirmed with Wayne at Tuniti — they will re-check next tab paste.'" style="margin-bottom:1rem;"></textarea>
        <button class="btn btn-primary" type="submit">I acknowledge this fix is in place</button>
    </form>
    <?php else: ?>
        <p style="color:#6c757d;">You don't have permission to acknowledge on this account.</p>
    <?php endif; ?>
<?php endif; ?>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
