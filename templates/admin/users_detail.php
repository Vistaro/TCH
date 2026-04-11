<?php
/**
 * User detail / edit — /admin/users/{id}
 *
 * View + edit a single user. Allowed actions:
 *   - Edit role, manager, linked caregiver/client, full name
 *   - Force password reset (creates a password_resets row, emails the link)
 *   - Impersonate (Super Admin only — opens the re-auth page)
 *   - Deactivate / reactivate (already on the list page; mirrored here)
 *
 * Permission: users.read to view, users.edit to mutate
 */

require_once APP_ROOT . '/includes/mailer.php';

$pageTitle = 'User';
$activeNav = 'users';

$userId = (int)($_GET['user_id'] ?? 0);
$db = getDB();
$me = currentEffectiveUser();
$canEdit = userCan('users', 'edit');

$target = fetchUserById($userId);
if (!$target) {
    http_response_code(404);
    require APP_ROOT . '/templates/layouts/admin.php';
    echo '<p>No user with id ' . (int)$userId . '.</p>';
    echo '<p><a href="' . APP_URL . '/admin/users">Back to users</a></p>';
    require APP_ROOT . '/templates/layouts/admin_footer.php';
    return;
}

$flash = '';
$flashType = 'success';

// ── Handle POST actions ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_profile') {
            $newFullName = trim($_POST['full_name'] ?? '');
            $newRoleId   = (int)($_POST['role_id'] ?? 0);
            $newMgrId    = $_POST['manager_id'] !== '' ? (int)$_POST['manager_id'] : null;
            $newCgId     = $_POST['linked_caregiver_id'] !== '' ? (int)$_POST['linked_caregiver_id'] : null;
            $newClId     = $_POST['linked_client_id'] !== '' ? (int)$_POST['linked_client_id'] : null;

            if ($newFullName === '' || $newRoleId === 0) {
                $flash = 'Full name and role are required.';
                $flashType = 'error';
            } elseif ($newMgrId !== null && $newMgrId === $userId) {
                $flash = 'A user cannot be their own manager.';
                $flashType = 'error';
            } else {
                // Look up role slug for the legacy `role` string column
                $r = $db->prepare('SELECT slug FROM roles WHERE id = ?');
                $r->execute([$newRoleId]);
                $roleSlug = $r->fetchColumn() ?: 'admin';

                $before = [
                    'full_name'           => $target['full_name'],
                    'role_id'             => (int)$target['role_id'],
                    'manager_id'          => $target['manager_id'] !== null ? (int)$target['manager_id'] : null,
                    'linked_caregiver_id' => $target['linked_caregiver_id'] !== null ? (int)$target['linked_caregiver_id'] : null,
                    'linked_client_id'    => $target['linked_client_id'] !== null ? (int)$target['linked_client_id'] : null,
                ];
                $after = [
                    'full_name'           => $newFullName,
                    'role_id'             => $newRoleId,
                    'manager_id'          => $newMgrId,
                    'linked_caregiver_id' => $newCgId,
                    'linked_client_id'    => $newClId,
                ];

                $stmt = $db->prepare(
                    'UPDATE users
                     SET full_name = ?, role_id = ?, role = ?, manager_id = ?,
                         linked_caregiver_id = ?, linked_client_id = ?
                     WHERE id = ?'
                );
                $stmt->execute([
                    $newFullName, $newRoleId, $roleSlug, $newMgrId, $newCgId, $newClId, $userId
                ]);

                logActivity('user_edited', 'users', 'users', $userId,
                    'Edited ' . $target['email'], $before, $after);

                $flash = 'User updated.';
                $target = fetchUserById($userId);
            }
        } elseif ($action === 'force_reset') {
            // Send the user a password-reset link
            $rawToken  = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);

            $stmt = $db->prepare(
                'INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), ?)'
            );
            $stmt->execute([$userId, $tokenHash, $_SERVER['REMOTE_ADDR'] ?? null]);

            $resetUrl = APP_URL . '/reset-password?token=' . $rawToken;

            Mailer::send('reset', $target['email'], $target['full_name'], [
                'fullName'     => $target['full_name'],
                'resetUrl'     => $resetUrl,
                'expiresHours' => 24,
                'requestIp'    => 'admin: ' . ($me['email'] ?? ''),
            ], $userId);

            // Force a reset on next login — snapshot the flag flip so the
            // audit log shows what changed, not just that the action ran.
            $wasForced = (int)($target['must_reset_password'] ?? 0);
            $stmt = $db->prepare('UPDATE users SET must_reset_password = 1 WHERE id = ?');
            $stmt->execute([$userId]);

            logActivity(
                'password_reset_forced', 'users', 'users', $userId,
                'Admin forced password reset for ' . $target['email'],
                ['must_reset_password' => $wasForced],
                ['must_reset_password' => 1]
            );

            $flash = 'Password reset link sent. Dev fallback URL: ' . $resetUrl;
        } elseif ($action === 'unlock') {
            // Snapshot the lock fields BEFORE clearing them so the activity
            // log detail page shows exactly what state was cleared.
            $unlockBefore = [
                'failed_login_count' => (int)$target['failed_login_count'],
                'locked_until'       => $target['locked_until'],
            ];
            $stmt = $db->prepare('UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = ?');
            $stmt->execute([$userId]);
            logActivity(
                'user_unlocked', 'users', 'users', $userId,
                'Unlocked ' . $target['email'],
                $unlockBefore,
                ['failed_login_count' => 0, 'locked_until' => null]
            );
            $flash = 'User unlocked.';
            $target = fetchUserById($userId);
        }
    }
}

// Reload roles + potential managers
$roles = $db->query('SELECT id, name FROM roles ORDER BY id')->fetchAll();
$potentialManagers = $db->query(
    'SELECT id, full_name, email FROM users
     WHERE is_active = 1 AND role_id IN (1, 2, 3) AND id <> ' . (int)$userId . '
     ORDER BY full_name'
)->fetchAll();

// Recent activity for this user
$actStmt = $db->prepare(
    'SELECT id, action, page_code, entity_type, entity_id, summary, created_at
     FROM activity_log
     WHERE real_user_id = ? OR impersonator_user_id = ?
     ORDER BY id DESC LIMIT 20'
);
$actStmt->execute([$userId, $userId]);
$activity = $actStmt->fetchAll();

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="margin-bottom:1rem;">
    <a href="<?= APP_URL ?>/admin/users" class="btn btn-outline btn-sm">&larr; Back to users</a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flashType === 'error' ? 'error' : 'success' ?>" style="margin-bottom:1rem;">
        <?= htmlspecialchars($flash) ?>
    </div>
<?php endif; ?>

<div class="person-card">
    <div class="person-card-header">
        <div class="person-photo person-photo-placeholder">
            <?= strtoupper(substr($target['full_name'] ?? $target['email'], 0, 1)) ?>
        </div>
        <div class="person-card-title">
            <h2><?= htmlspecialchars($target['full_name']) ?></h2>
            <div class="person-card-tch-id"><?= htmlspecialchars($target['email']) ?></div>
            <div class="person-card-meta">
                Role: <strong><?= htmlspecialchars($target['role_name'] ?? '—') ?></strong> &middot;
                Status:
                <?php if ((int)$target['is_active'] === 1): ?>
                    <?php if (!empty($target['locked_until']) && strtotime($target['locked_until']) > time()): ?>
                        <span class="badge badge-warning">Locked until <?= htmlspecialchars($target['locked_until']) ?></span>
                    <?php elseif (empty($target['email_verified_at'])): ?>
                        <span class="badge badge-warning">Unverified</span>
                    <?php else: ?>
                        <span class="badge badge-success">Active</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="badge badge-muted">Inactive</span>
                <?php endif; ?>
                &middot; Last login: <?= $target['last_login'] ? htmlspecialchars($target['last_login']) : '<span style="color:#999;">never</span>' ?>
            </div>
        </div>
    </div>

    <div class="person-card-grid">
        <!-- Profile edit form -->
        <div class="person-card-section">
            <h3>Profile</h3>
            <?php if ($canEdit): ?>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_profile">

                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" class="form-control" required
                           value="<?= htmlspecialchars($target['full_name']) ?>">
                </div>

                <div class="form-group">
                    <label>Email (login)</label>
                    <input type="email" class="form-control" value="<?= htmlspecialchars($target['email']) ?>" disabled>
                    <small style="color:#666;">Email changes are not supported in this UI.</small>
                </div>

                <div class="form-group">
                    <label>Role</label>
                    <select name="role_id" class="form-control" required>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= (int)$r['id'] ?>" <?= (int)$target['role_id'] === (int)$r['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($r['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Manager</label>
                    <select name="manager_id" class="form-control">
                        <option value="">— No manager —</option>
                        <?php foreach ($potentialManagers as $m): ?>
                            <option value="<?= (int)$m['id'] ?>"
                                <?= (int)($target['manager_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m['full_name']) ?> (<?= htmlspecialchars($m['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Linked Caregiver ID</label>
                    <input type="number" name="linked_caregiver_id" class="form-control" min="0"
                           value="<?= htmlspecialchars((string)($target['linked_caregiver_id'] ?? '')) ?>">
                </div>

                <div class="form-group">
                    <label>Linked Client ID</label>
                    <input type="number" name="linked_client_id" class="form-control" min="0"
                           value="<?= htmlspecialchars((string)($target['linked_client_id'] ?? '')) ?>">
                </div>

                <button type="submit" class="btn btn-primary">Save Changes</button>
            </form>
            <?php else: ?>
                <dl>
                    <dt>Full Name</dt><dd><?= htmlspecialchars($target['full_name']) ?></dd>
                    <dt>Email</dt><dd><?= htmlspecialchars($target['email']) ?></dd>
                    <dt>Role</dt><dd><?= htmlspecialchars($target['role_name'] ?? '—') ?></dd>
                </dl>
            <?php endif; ?>
        </div>

        <!-- Account actions -->
        <div class="person-card-section">
            <h3>Account Actions</h3>

            <?php if ($canEdit): ?>
                <form method="POST" style="margin-bottom:0.75rem;">
                    <?= csrfField() ?>
                    <button type="submit" name="action" value="force_reset" class="btn btn-outline"
                            onclick="return confirm('Send a password reset link to <?= htmlspecialchars($target['email'], ENT_QUOTES) ?>?');">
                        Send Password Reset Email
                    </button>
                </form>

                <?php if (!empty($target['locked_until']) && strtotime($target['locked_until']) > time()): ?>
                    <form method="POST" style="margin-bottom:0.75rem;">
                        <?= csrfField() ?>
                        <button type="submit" name="action" value="unlock" class="btn btn-primary">
                            Unlock Account
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (isSuperAdmin() && (int)$target['id'] !== (int)$me['id'] && (int)$target['is_active'] === 1): ?>
                <a href="<?= APP_URL ?>/admin/users/<?= (int)$target['id'] ?>/impersonate" class="btn btn-danger">
                    Impersonate User
                </a>
                <p style="color:#999;font-size:0.85rem;margin-top:0.5rem;">
                    Re-authentication required. Both your identity and the impersonated identity
                    will be recorded against every action.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent activity -->
    <div class="person-card-section">
        <h3>Recent Activity</h3>
        <?php if (empty($activity)): ?>
            <p style="color:#999;">No activity recorded.</p>
        <?php else: ?>
            <table class="name-table">
                <thead>
                    <tr><th>When</th><th>Action</th><th>Entity</th><th>Summary</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($activity as $a): ?>
                        <tr>
                            <td><?= htmlspecialchars($a['created_at']) ?></td>
                            <td><code><?= htmlspecialchars($a['action']) ?></code></td>
                            <td><?= htmlspecialchars(($a['entity_type'] ?? '') . ($a['entity_id'] ? '#' . $a['entity_id'] : '')) ?></td>
                            <td><?= htmlspecialchars($a['summary'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
