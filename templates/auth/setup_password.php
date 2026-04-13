<?php
/**
 * Setup Password — accept ?token= from a user_invites row, create the user
 * account, set their password, and mark email_verified.
 *
 * On success the user_invites row's used_at and created_user_id are filled.
 * Identical token semantics to reset_password.php (single-use, time-limited).
 */
$pageTitle = 'Set Password';
initSession();

require_once APP_ROOT . '/includes/mailer.php';
require_once APP_ROOT . '/includes/password_policy.php';

$rawToken = $_GET['token'] ?? $_POST['token'] ?? '';
$rawToken = trim($rawToken);
$tokenHash = $rawToken !== '' ? hash('sha256', $rawToken) : '';

$error = '';
$success = false;
$tokenValid = false;
$invite = null;

if ($tokenHash !== '') {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT * FROM user_invites
         WHERE token_hash = ?
         LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $invite = $stmt->fetch();

    if ($invite
        && $invite['used_at'] === null
        && strtotime($invite['expires_at']) > time()
    ) {
        $tokenValid = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        $policyErr = validatePasswordPolicy($password, [
            'email'     => $invite['email'],
            'full_name' => $invite['full_name'],
        ]);
        if ($policyErr !== null) {
            $error = $policyErr;
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db = getDB();
            $db->beginTransaction();
            try {
                // Does a user with this email already exist? If so, we update them
                // (rare race; usually shouldn't happen since invite UI checks).
                $existing = fetchUserByEmail($invite['email']);
                if ($existing) {
                    $upd = $db->prepare(
                        'UPDATE users
                         SET password_hash = ?, full_name = ?, role_id = ?, manager_id = ?,
                             linked_caregiver_id = ?, linked_client_id = ?,
                             email_verified_at = NOW(), is_active = 1,
                             failed_login_count = 0, locked_until = NULL, must_reset_password = 0
                         WHERE id = ?'
                    );
                    $upd->execute([
                        $hash,
                        $invite['full_name'],
                        (int)$invite['role_id'],
                        $invite['manager_id'] !== null ? (int)$invite['manager_id'] : null,
                        $invite['linked_caregiver_id'] !== null ? (int)$invite['linked_caregiver_id'] : null,
                        $invite['linked_client_id'] !== null ? (int)$invite['linked_client_id'] : null,
                        (int)$existing['id'],
                    ]);
                    $newUserId = (int)$existing['id'];
                } else {
                    // Create the user. username column is legacy — store the email
                    // there too so the unique constraint is satisfied.
                    $ins = $db->prepare(
                        'INSERT INTO users
                            (username, password_hash, full_name, email, role, role_id,
                             manager_id, linked_caregiver_id, linked_client_id,
                             is_active, email_verified_at, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())'
                    );
                    // Look up role slug for the legacy `role` string column
                    $r = $db->prepare('SELECT slug FROM roles WHERE id = ?');
                    $r->execute([(int)$invite['role_id']]);
                    $roleSlug = $r->fetchColumn() ?: 'admin';

                    $ins->execute([
                        substr($invite['email'], 0, 50),
                        $hash,
                        $invite['full_name'],
                        $invite['email'],
                        $roleSlug,
                        (int)$invite['role_id'],
                        $invite['manager_id'] !== null ? (int)$invite['manager_id'] : null,
                        $invite['linked_caregiver_id'] !== null ? (int)$invite['linked_caregiver_id'] : null,
                        $invite['linked_client_id'] !== null ? (int)$invite['linked_client_id'] : null,
                    ]);
                    $newUserId = (int)$db->lastInsertId();
                }

                $mark = $db->prepare(
                    'UPDATE user_invites SET used_at = NOW(), created_user_id = ? WHERE id = ?'
                );
                $mark->execute([$newUserId, (int)$invite['id']]);

                recordPasswordInHistory($newUserId, $hash, $db);

                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            Mailer::send('reset_confirm', $invite['email'], $invite['full_name'], [
                'fullName'  => $invite['full_name'],
                'loginUrl'  => APP_URL . '/login',
                'eventTime' => gmdate('Y-m-d H:i'),
                'eventIp'   => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ], $newUserId);

            logActivity('user_invite_accepted', null, 'users', $newUserId,
                'Invite accepted for ' . $invite['email']);

            $success = true;
        }
    }
}
?>
<?php require APP_ROOT . '/templates/layouts/header.php'; ?>

<div class="auth-page">
    <div class="auth-card">
        <h1><span style="color:#10B2B4">TCH</span> Admin</h1>
        <p class="subtitle">Welcome — set your password</p>

        <?php if ($success): ?>
            <div class="alert alert-success">
                Your account is ready. You can now sign in with your email
                and the password you just set.
            </div>
            <p style="text-align:center;margin-top:1.5rem;">
                <a href="<?= APP_URL ?>/login?reset=1" class="btn btn-primary" style="display:inline-block;">Go to Login</a>
            </p>
        <?php elseif (!$tokenValid): ?>
            <div class="alert alert-error">
                This invitation link is invalid or has expired.
                Please contact your administrator to be re-invited.
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <p style="font-size:0.95rem;color:#555;">
                Welcome, <strong><?= htmlspecialchars($invite['full_name']) ?></strong>.
                Setting up the account for <strong><?= htmlspecialchars($invite['email']) ?></strong>.
            </p>
            <p style="font-size:0.85rem;color:#6c757d;background:#f8f9fa;padding:0.5rem 0.75rem;border-radius:4px;">
                <strong>Password rules:</strong> <?= htmlspecialchars(passwordPolicyRulesText()) ?>
            </p>

            <form method="POST" action="<?= APP_URL ?>/setup-password">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($rawToken) ?>">
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" autocomplete="new-password" required minlength="10" autofocus>
                </div>
                <div class="form-group">
                    <label for="password_confirm">Confirm Password</label>
                    <input type="password" id="password_confirm" name="password_confirm" class="form-control" autocomplete="new-password" required minlength="10">
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:0.5rem;">Set Password & Activate</button>
            </form>
        <?php endif; ?>
    </div>
</div>
