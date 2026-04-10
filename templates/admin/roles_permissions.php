<?php
/**
 * Per-role permission matrix — /admin/roles/{id}/permissions
 *
 * Renders pages × CRUD checkbox grid. POST upserts every page row.
 *
 * Permission: roles.edit (gated in front controller)
 *
 * Safety guard: the Super Admin role (id 1) cannot have its permissions
 * reduced through this UI — Super Admin is intentionally hardcoded as
 * full-access. The form will display but POSTs against role_id=1 are
 * rejected to prevent locking everyone out.
 */

$pageTitle = 'Edit Permissions';
$activeNav = 'roles';

$db = getDB();
$roleId = (int)($_GET['role_id'] ?? 0);

$role = $db->prepare('SELECT * FROM roles WHERE id = ?');
$role->execute([$roleId]);
$role = $role->fetch();
if (!$role) {
    http_response_code(404);
    require APP_ROOT . '/templates/layouts/admin.php';
    echo '<p>No role with id ' . $roleId . '.</p>';
    require APP_ROOT . '/templates/layouts/admin_footer.php';
    return;
}

$flash = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.';
        $flashType = 'error';
    } elseif ($roleId === 1) {
        $flash = 'The Super Admin role cannot be modified — it is hardcoded as full-access.';
        $flashType = 'error';
    } elseif (!userCan('roles', 'edit')) {
        $flash = 'You do not have permission to edit role permissions.';
        $flashType = 'error';
    } else {
        $perms = $_POST['perm'] ?? [];
        $pages = $db->query('SELECT id, code FROM pages')->fetchAll();

        /**
         * Snapshot the current matrix as a flat dict of "pagecode.verb" → 0|1
         * so the activity log diff can render one row per changed permission.
         */
        $snapshotMatrix = function(PDO $db, int $roleId): array {
            $stmt = $db->prepare(
                'SELECT p.code,
                        COALESCE(rp.can_read,   0) AS can_read,
                        COALESCE(rp.can_create, 0) AS can_create,
                        COALESCE(rp.can_edit,   0) AS can_edit,
                        COALESCE(rp.can_delete, 0) AS can_delete
                 FROM pages p
                 LEFT JOIN role_permissions rp
                     ON rp.page_id = p.id AND rp.role_id = ?
                 ORDER BY p.sort_order, p.id'
            );
            $stmt->execute([$roleId]);
            $flat = [];
            foreach ($stmt->fetchAll() as $row) {
                $flat[$row['code'] . '.read']   = (int)$row['can_read'];
                $flat[$row['code'] . '.create'] = (int)$row['can_create'];
                $flat[$row['code'] . '.edit']   = (int)$row['can_edit'];
                $flat[$row['code'] . '.delete'] = (int)$row['can_delete'];
            }
            return $flat;
        };

        $before = $snapshotMatrix($db, $roleId);

        $db->beginTransaction();
        try {
            $upsert = $db->prepare(
                'INSERT INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE can_read=VALUES(can_read), can_create=VALUES(can_create),
                                         can_edit=VALUES(can_edit), can_delete=VALUES(can_delete)'
            );
            foreach ($pages as $p) {
                $pid = (int)$p['id'];
                $row = $perms[$pid] ?? [];
                $upsert->execute([
                    $roleId,
                    $pid,
                    isset($row['read']) ? 1 : 0,
                    isset($row['create']) ? 1 : 0,
                    isset($row['edit']) ? 1 : 0,
                    isset($row['delete']) ? 1 : 0,
                ]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $after = $snapshotMatrix($db, $roleId);

        // Count actual diffs for the summary line
        $changeCount = 0;
        foreach ($after as $k => $v) {
            if (($before[$k] ?? null) !== $v) {
                $changeCount++;
            }
        }

        logActivity('role_permissions_updated', 'roles', 'roles', $roleId,
            sprintf('Updated permission matrix for %s (%d field changes)', $role['name'], $changeCount),
            $before,
            $after);

        $flash = 'Permissions updated.';
    }
}

// Load current matrix
$pages = $db->query('SELECT id, code, label, section, sort_order FROM pages ORDER BY sort_order, id')->fetchAll();
$current = $db->prepare(
    'SELECT page_id, can_read, can_create, can_edit, can_delete
     FROM role_permissions WHERE role_id = ?'
);
$current->execute([$roleId]);
$matrix = [];
foreach ($current->fetchAll() as $row) {
    $matrix[(int)$row['page_id']] = $row;
}

$canEdit = userCan('roles', 'edit') && $roleId !== 1;

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="margin-bottom:1rem;">
    <a href="<?= APP_URL ?>/admin/roles" class="btn btn-outline btn-sm">&larr; Back to roles</a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flashType === 'error' ? 'error' : 'success' ?>" style="margin-bottom:1rem;">
        <?= htmlspecialchars($flash) ?>
    </div>
<?php endif; ?>

<h2><?= htmlspecialchars($role['name']) ?></h2>
<p style="color:#666;margin-bottom:1.5rem;"><?= htmlspecialchars($role['description']) ?></p>

<?php if ($roleId === 1): ?>
    <div class="alert alert-info">
        Super Admin permissions are hardcoded as full-access. The matrix below is shown for reference
        but cannot be edited.
    </div>
<?php endif; ?>

<form method="POST">
    <?= csrfField() ?>
    <div class="report-table-wrap">
        <table class="name-table">
            <thead>
                <tr>
                    <th>Page</th>
                    <th>Section</th>
                    <th style="text-align:center;">Read</th>
                    <th style="text-align:center;">Create</th>
                    <th style="text-align:center;">Edit</th>
                    <th style="text-align:center;">Delete</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pages as $p):
                    $pid = (int)$p['id'];
                    $row = $matrix[$pid] ?? ['can_read'=>0,'can_create'=>0,'can_edit'=>0,'can_delete'=>0];
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($p['label']) ?></strong><br><code style="font-size:0.8rem;color:#999;"><?= htmlspecialchars($p['code']) ?></code></td>
                        <td><?= htmlspecialchars($p['section']) ?></td>
                        <td style="text-align:center;">
                            <input type="checkbox" name="perm[<?= $pid ?>][read]"
                                <?= (int)$row['can_read'] ? 'checked' : '' ?>
                                <?= $canEdit ? '' : 'disabled' ?>>
                        </td>
                        <td style="text-align:center;">
                            <input type="checkbox" name="perm[<?= $pid ?>][create]"
                                <?= (int)$row['can_create'] ? 'checked' : '' ?>
                                <?= $canEdit ? '' : 'disabled' ?>>
                        </td>
                        <td style="text-align:center;">
                            <input type="checkbox" name="perm[<?= $pid ?>][edit]"
                                <?= (int)$row['can_edit'] ? 'checked' : '' ?>
                                <?= $canEdit ? '' : 'disabled' ?>>
                        </td>
                        <td style="text-align:center;">
                            <input type="checkbox" name="perm[<?= $pid ?>][delete]"
                                <?= (int)$row['can_delete'] ? 'checked' : '' ?>
                                <?= $canEdit ? '' : 'disabled' ?>>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($canEdit): ?>
        <div style="margin-top:1rem;">
            <button type="submit" class="btn btn-primary">Save Permissions</button>
            <a href="<?= APP_URL ?>/admin/roles" class="btn btn-outline">Cancel</a>
        </div>
    <?php endif; ?>
</form>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
