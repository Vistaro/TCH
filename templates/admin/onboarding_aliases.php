<?php
/**
 * Task 4 subpage — alias disambiguation.
 * Thin redirect / summary. The heavy-lift is already on
 * /admin/config/aliases. This subpage shows the count of unresolved
 * aliases and deep-links straight into the alias admin.
 */
$pageTitle = 'Alias disambiguation';
$activeNav = 'onboarding';

$db = getDB();

$unresolved = $db->query(
    "SELECT person_role, COUNT(*) n
       FROM timesheet_name_aliases
      WHERE confidence = 'unresolved'
   GROUP BY person_role"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$total = array_sum($unresolved);

require APP_ROOT . '/templates/layouts/admin.php';
?>

<p style="margin-bottom:0.5rem;">
    <a href="<?= APP_URL ?>/admin/onboarding" style="font-size:0.85rem;">← Back to tasks</a>
</p>

<h2 style="margin:0 0 0.5rem 0;">Alias disambiguation</h2>
<p style="color:#6c757d;margin-bottom:1rem;">
    When names in the source workbooks don't match a known person, they sit unresolved until a human confirms who they belong to. Once resolved, the alias is remembered — the system won't ask again.
</p>

<?php if ($total === 0): ?>
    <div style="background:#e7f5ee;border:1px solid #b6e0c8;padding:1.2rem 1.5rem;border-radius:6px;">
        <strong style="color:#165d36;">✓ Nothing to resolve right now.</strong>
        <div style="color:#495057;margin-top:0.3rem;font-size:0.9rem;">Every alias in the system is mapped to a canonical person. New unresolved aliases will appear here as new workbooks are ingested.</div>
    </div>
<?php else: ?>
    <div style="background:#fff4e5;border-left:4px solid #fd7e14;padding:0.9rem 1.1rem;border-radius:6px;margin-bottom:1.25rem;">
        <strong><?= number_format($total) ?> unresolved alias<?= $total === 1 ? '' : 'es' ?></strong>
        <div style="margin-top:0.4rem;color:#495057;font-size:0.9rem;">
            <?php foreach ($unresolved as $role => $n): ?>
                <span style="display:inline-block;margin-right:1rem;">
                    <strong><?= number_format($n) ?></strong> <?= htmlspecialchars($role) ?><?= $n === 1 ? '' : 's' ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>

    <p>Work through the unresolved rows in the alias admin — each one takes a few seconds once the correct canonical is picked.</p>
    <p style="margin-top:1rem;">
        <a href="<?= APP_URL ?>/admin/config/aliases" class="btn btn-primary">Open alias admin →</a>
    </p>
<?php endif; ?>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
