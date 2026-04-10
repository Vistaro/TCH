<?php
/**
 * Roles list — /admin/roles
 *
 * Lists all roles with user count + link to per-role permission matrix.
 *
 * Permission: roles.read
 *
 * NB role creation/deletion is NOT in v1 — the 5 system roles are fixed.
 * What IS editable is the permission matrix per role (see roles_permissions.php).
 */

$pageTitle = 'Roles & Permissions';
$activeNav = 'roles';

$db = getDB();

$roles = $db->query(
    'SELECT r.*,
            (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count,
            (SELECT COUNT(*) FROM role_permissions rp
             WHERE rp.role_id = r.id AND (rp.can_read=1 OR rp.can_create=1 OR rp.can_edit=1 OR rp.can_delete=1)
            ) AS pages_with_access
     FROM roles r
     ORDER BY r.id'
)->fetchAll();

$totalPages = (int)$db->query('SELECT COUNT(*) FROM pages')->fetchColumn();

require APP_ROOT . '/templates/layouts/admin.php';
?>

<p style="color:#666;margin-bottom:1.5rem;">
    The five seeded roles are fixed for v1 — you can't create or delete roles, but you can
    edit the permission matrix per role. Click "Edit Permissions" on any role to change
    which pages they can read, create, edit, or delete.
</p>

<div class="report-table-wrap">
    <table class="name-table">
        <thead>
            <tr>
                <th>Role</th>
                <th>Slug</th>
                <th>Description</th>
                <th>Users</th>
                <th>Pages with access</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($roles as $r): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                    <td><code><?= htmlspecialchars($r['slug']) ?></code></td>
                    <td><?= htmlspecialchars($r['description']) ?></td>
                    <td><?= (int)$r['user_count'] ?></td>
                    <td><?= (int)$r['pages_with_access'] ?> / <?= $totalPages ?></td>
                    <td>
                        <?php if (userCan('roles', 'edit')): ?>
                            <a href="<?= APP_URL ?>/admin/roles/<?= (int)$r['id'] ?>/permissions" class="btn btn-primary btn-sm">
                                Edit Permissions
                            </a>
                        <?php else: ?>
                            <a href="<?= APP_URL ?>/admin/roles/<?= (int)$r['id'] ?>/permissions" class="btn btn-outline btn-sm">
                                View Permissions
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
