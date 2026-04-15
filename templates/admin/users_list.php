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

    // Global force-reset-all — Super Admin only. Excludes the triggering user
    // so they can't lock themselves out mid-session. Re-auth required.
    if (($_POST['action'] ?? '') === 'force_reset_all'
            && (int)($me['role_id'] ?? 0) === 1) {
        $reauthPw = $_POST['reauth_password'] ?? '';
        $meFull = fetchUserById((int)$me['id']);
        if (!$meFull || !password_verify($reauthPw, $meFull['password_hash'])) {
            $flash = 'Password confirmation failed — nothing changed.';
        } else {
            $stmt = $db->prepare(
                'SELECT id, email FROM users
                 WHERE id != ? AND is_active = 1 AND must_reset_password = 0'
            );
            $stmt->execute([(int)$me['id']]);
            $targets = $stmt->fetchAll();
            if ($targets) {
                $ids = array_column($targets, 'id');
                $ph  = implode(',', array_fill(0, count($ids), '?'));
                $db->prepare("UPDATE users SET must_reset_password = 1 WHERE id IN ($ph)")
                   ->execute($ids);
                logActivity(
                    'password_reset_forced_bulk', 'users', 'users', null,
                    'Force-reset triggered for ' . count($targets) . ' users by ' . ($me['email'] ?? '?'),
                    null,
                    ['affected_user_ids' => $ids]
                );
                $flash = 'Force-reset set for ' . count($targets)
                       . ' user(s). They will be required to change password on next login.';
            } else {
                $flash = 'No eligible users to force-reset.';
            }
        }
    }

    // Revoke a pending invite
    if (($_POST['action'] ?? '') === 'revoke_invite' && $canEdit) {
        $inviteId = (int)($_POST['invite_id'] ?? 0);
        if ($inviteId > 0) {
            $stmt = $db->prepare(
                'SELECT * FROM user_invites WHERE id = ? AND used_at IS NULL'
            );
            $stmt->execute([$inviteId]);
            $inv = $stmt->fetch();
            if ($inv) {
                // Marking expired rather than deleting — keeps the audit trail intact
                $db->prepare('UPDATE user_invites SET expires_at = NOW() WHERE id = ?')->execute([$inviteId]);
                logActivity(
                    'user_invite_revoked', 'users', 'user_invites', $inviteId,
                    'Revoked invite for ' . $inv['email'],
                    ['expires_at' => $inv['expires_at']],
                    ['expires_at' => date('Y-m-d H:i:s')]
                );
                $flash = 'Invite to ' . $inv['email'] . ' revoked.';
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
$pendingInviteRows = $db->query(
    "SELECT i.id, i.email, i.full_name, i.expires_at,
            r.name AS role_name,
            cu.full_name AS invited_by_name
     FROM user_invites i
     LEFT JOIN roles r ON r.id = i.role_id
     LEFT JOIN users cu ON cu.id = i.created_by
     WHERE i.used_at IS NULL AND i.expires_at > NOW()
     ORDER BY i.created_at DESC"
)->fetchAll();

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
    <div class="dash-card accent" style="flex:2;">
        <div class="dash-card-label">Pending Invites</div>
        <div style="display:flex;align-items:flex-start;gap:1rem;">
            <div class="dash-card-value"><?= $pendingInvites ?></div>
            <?php if ($pendingInviteRows): ?>
                <div style="flex:1;font-size:0.85rem;">
                    <?php foreach ($pendingInviteRows as $inv):
                        $expiresTs = strtotime($inv['expires_at']);
                        $daysLeft = max(0, (int)ceil(($expiresTs - time()) / 86400));
                    ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:0.25rem 0;border-bottom:1px dotted #eee;">
                            <div>
                                <strong><?= htmlspecialchars($inv['full_name']) ?></strong>
                                &middot; <?= htmlspecialchars($inv['email']) ?>
                                &middot; <?= htmlspecialchars($inv['role_name'] ?? '—') ?>
                                <span style="color:#6c757d;">(invited by <?= htmlspecialchars($inv['invited_by_name'] ?? 'unknown') ?>, expires in <?= $daysLeft ?>d)</span>
                            </div>
                            <?php if ($canEdit): ?>
                                <form method="POST" style="display:inline;margin-left:0.5rem;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="revoke_invite">
                                    <input type="hidden" name="invite_id" value="<?= (int)$inv['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm"
                                            onclick="return confirm('Revoke the invite for <?= htmlspecialchars(addslashes($inv['email'])) ?>?');">
                                        Revoke
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="dash-card-sub" style="padding-top:0.5rem;">No outstanding invitations</div>
            <?php endif; ?>
        </div>
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
        <?php if ((int)($me['role_id'] ?? 0) === 1): ?>
            <button type="button" class="btn btn-outline btn-sm" style="margin-left:0.5rem;"
                    onclick="document.getElementById('force-reset-all-form').style.display='block';">
                Force password reset — all users
            </button>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ((int)($me['role_id'] ?? 0) === 1): ?>
<form id="force-reset-all-form" method="POST"
      style="display:none;margin:0 0 1rem;padding:1rem;background:#fff3cd;border:1px solid #ffeeba;border-radius:4px;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="force_reset_all">
    <h4 style="margin:0 0 0.5rem;color:#856404;">Force password reset — all users</h4>
    <p style="font-size:0.9rem;margin:0 0 0.75rem;">
        Every active user except you will be required to choose a new password at
        their next sign-in. Your own account is excluded so you cannot lock
        yourself out. Confirm your current password to proceed.
    </p>
    <div style="display:flex;gap:0.5rem;align-items:center;">
        <input type="password" name="reauth_password" class="form-control"
               placeholder="Your current password" required style="max-width:260px;">
        <button type="submit" class="btn btn-danger btn-sm"
                onclick="return confirm('Are you sure? Every other active user will be forced to change their password on next login.');">
            Yes — force reset all
        </button>
        <a href="#" class="btn btn-link" onclick="document.getElementById('force-reset-all-form').style.display='none';return false;">Cancel</a>
    </div>
</form>
<?php endif; ?>

<div class="report-table-wrap">
    <table class="name-table tch-data-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th class="center">Role</th>
                <th>Manager</th>
                <th class="center">Status</th>
                <th class="center">Last Login</th>
                <th class="center">Actions</th>
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
                        <td class="center"><?= htmlspecialchars($r['role_name'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($r['manager_email'] ?? '—') ?></td>
                        <td class="center">
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
                        <td class="center"><?= $r['last_login'] ? htmlspecialchars($r['last_login']) : '<span style="color:#999;">never</span>' ?></td>
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
