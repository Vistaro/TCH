<?php
/**
 * Users list — /admin/users
 *
 * Shows all users (visible to the current user via getVisibleUserIds()) with
 * filter by role + active status. Provides invite button + per-row deactivate
 * action.
 *
 * Permission: users.read (gated by requirePagePermission in front controller)
 * Mutations on this page: deactivate / reactivate (require users.edit)
 */

$pageTitle = 'Users';
$activeNav = 'users';

$db = getDB();
$me = currentEffectiveUser();
$canEdit   = userCan('users', 'edit');
$canCreate = userCan('users', 'create');
$canDelete = userCan('users', 'delete');

// ── Handle POST actions ─────────────────────────────────────────────────
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    $targetId = (int)($_POST['user_id'] ?? 0);

    if ($targetId > 0 && $canEdit) {
        // Don't let a user deactivate themselves
        if ($targetId === (int)$me['id'] && in_array($action, ['deactivate'], true)) {
            $flash = 'You cannot deactivate your own account.';
        } else {
            $target = fetchUserById($targetId);
            if ($target) {
                if ($action === 'deactivate' && (int)$target['is_active'] === 1) {
                    $stmt = $db->prepare('UPDATE users SET is_active = 0 WHERE id = ?');
                    $stmt->execute([$targetId]);
                    logActivity('user_deactivated', 'users', 'users', $targetId,
                        'Deactivated ' . $target['email'],
                        ['is_active' => 1],
                        ['is_active' => 0]);
                    $flash = 'User deactivated.';
                } elseif ($action === 'reactivate' && (int)$target['is_active'] === 0) {
                    $stmt = $db->prepare('UPDATE users SET is_active = 1, failed_login_count = 0, locked_until = NULL WHERE id = ?');
                    $stmt->execute([$targetId]);
                    logActivity('user_reactivated', 'users', 'users', $targetId,
                        'Reactivated ' . $target['email'],
                        ['is_active' => 0, 'failed_login_count' => (int)$target['failed_login_count'], 'locked_until' => $target['locked_until']],
                        ['is_active' => 1, 'failed_login_count' => 0, 'locked_until' => null]);
                    $flash = 'User reactivated.';
                }
            }
        }
    }
}

// ── Filters ─────────────────────────────────────────────────────────────
$filterRole   = $_GET['role']   ?? '';
$filterActive = $_GET['active'] ?? '';
$search       = trim($_GET['q'] ?? '');

$visible = getVisibleUserIds();
if (empty($visible)) {
    $visible = [0]; // never matches
}

$where  = ['u.id IN (' . implode(',', array_map('intval', $visible)) . ')'];
$params = [];

if ($filterRole !== '' && ctype_digit($filterRole)) {
    $where[]  = 'u.role_id = ?';
    $params[] = (int)$filterRole;
}
if ($filterActive === '1' || $filterActive === '0') {
    $where[]  = 'u.is_active = ?';
    $params[] = (int)$filterActive;
}
if ($search !== '') {
    $where[]  = '(u.email LIKE ? OR u.full_name LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$stmt = $db->prepare(
    "SELECT u.id, u.email, u.full_name, u.is_active, u.last_login, u.email_verified_at,
            u.failed_login_count, u.locked_until,
            r.name AS role_name, r.slug AS role_slug,
            mgr.email AS manager_email
     FROM users u
     LEFT JOIN roles r ON r.id = u.role_id
     LEFT JOIN users mgr ON mgr.id = u.manager_id
     $whereSQL
     ORDER BY u.is_active DESC, u.full_name ASC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$roles = $db->query('SELECT id, name FROM roles ORDER BY id')->fetchAll();
$totalUsers   = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$activeUsers  = (int)$db->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
$pendingInvites = (int)$db->query('SELECT COUNT(*) FROM user_invites WHERE used_at IS NULL AND expires_at > NOW()')->fetchColumn();

require APP_ROOT . '/templates/layouts/admin.php';
?>

<?php if ($flash): ?>
    <div class="alert alert-success" style="margin-bottom:1rem;"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="dash-cards" style="margin-bottom:1.5rem;">
    <div class="dash-card accent">
        <div class="dash-card-label">Active Users</div>
        <div class="dash-card-value"><?= $activeUsers ?></div>
        <div class="dash-card-sub">of <?= $totalUsers ?> total</div>
    </div>
    <div class="dash-card accent">
        <div class="dash-card-label">Pending Invites</div>
        <div class="dash-card-value"><?= $pendingInvites ?></div>
        <div class="dash-card-sub">Outstanding invitations</div>
    </div>
</div>

<div style="display:flex;justify-content:space-between;align-items:flex-end;gap:1rem;margin-bottom:1rem;flex-wrap:wrap;">
    <form method="GET" action="<?= APP_URL ?>/admin/users" class="report-filters" style="flex:1;">
        <div class="filter-group">
            <label>Search</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Email or name">
        </div>
        <div class="filter-group">
            <label>Role</label>
            <select name="role">
                <option value="">All roles</option>
                <?php foreach ($roles as $r): ?>
                    <option value="<?= (int)$r['id'] ?>" <?= $filterRole === (string)$r['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Status</label>
            <select name="active">
                <option value="">All</option>
                <option value="1" <?= $filterActive === '1' ? 'selected' : '' ?>>Active only</option>
                <option value="0" <?= $filterActive === '0' ? 'selected' : '' ?>>Inactive only</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($filterRole !== '' || $filterActive !== '' || $search !== ''): ?>
            <a href="<?= APP_URL ?>/admin/users" class="btn btn-outline btn-sm">Clear</a>
        <?php endif; ?>
    </form>
    <?php if ($canCreate): ?>
        <a href="<?= APP_URL ?>/admin/users/invite" class="btn btn-primary">+ Invite User</a>
    <?php endif; ?>
</div>

<div class="report-table-wrap">
    <table class="name-table tch-data-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Manager</th>
                <th>Status</th>
                <th>Last Login</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="7" style="text-align:center;color:#999;padding:2rem;">No users match the filter.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($r['full_name']) ?></strong></td>
                        <td><?= htmlspecialchars($r['email']) ?></td>
                        <td><?= htmlspecialchars($r['role_name'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($r['manager_email'] ?? '—') ?></td>
                        <td>
                            <?php if ((int)$r['is_active'] === 1): ?>
                                <?php if (!empty($r['locked_until']) && strtotime($r['locked_until']) > time()): ?>
                                    <span class="badge badge-warning">Locked</span>
                                <?php elseif (empty($r['email_verified_at'])): ?>
                                    <span class="badge badge-warning">Unverified</span>
                                <?php else: ?>
                                    <span class="badge badge-success">Active</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge badge-muted">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $r['last_login'] ? htmlspecialchars($r['last_login']) : '<span style="color:#999;">never</span>' ?></td>
                        <td>
                            <a href="<?= APP_URL ?>/admin/users/<?= (int)$r['id'] ?>" class="btn btn-outline btn-sm">View</a>
                            <?php if ($canEdit && (int)$r['id'] !== (int)$me['id']): ?>
                                <form method="POST" style="display:inline;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                                    <?php if ((int)$r['is_active'] === 1): ?>
                                        <button type="submit" name="action" value="deactivate" class="btn btn-danger btn-sm"
                                                onclick="return confirm('Deactivate <?= htmlspecialchars($r['email'], ENT_QUOTES) ?>?');">
                                            Deactivate
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" name="action" value="reactivate" class="btn btn-primary btn-sm">
                                            Reactivate
                                        </button>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
