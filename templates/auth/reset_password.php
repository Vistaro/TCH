<?php
/**
 * Reset Password — accept ?token=, validate, let user set a new password.
 *
 * Token is single-use: marked used_at on success.
 * Expired or used tokens show a clear error.
 * Successful reset clears any locked_until and failed_login_count.
 */
$pageTitle = 'Reset Password';
initSession();

require_once APP_ROOT . '/includes/mailer.php';
require_once APP_ROOT . '/includes/password_policy.php';

$rawToken = $_GET['token'] ?? $_POST['token'] ?? '';
$rawToken = trim($rawToken);
$tokenHash = $rawToken !== '' ? hash('sha256', $rawToken) : '';

$error = '';
$success = false;
$tokenValid = false;
$user = null;
$resetRow = null;

if ($tokenHash !== '') {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT pr.*, u.id AS user_id, u.email, u.full_name, u.is_active
         FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash = ?
         LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $resetRow = $stmt->fetch();

    if ($resetRow
        && $resetRow['used_at'] === null
        && strtotime($resetRow['expires_at']) > time()
        && (int)$resetRow['is_active'] === 1
    ) {
        $tokenValid = true;
        $user = ['id' => (int)$resetRow['user_id'], 'email' => $resetRow['email'], 'full_name' => $resetRow['full_name']];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        $db = getDB();
        $policyErr = validatePasswordPolicy($password, [
            'email'     => $user['email'],
            'full_name' => $user['full_name'],
        ], $db, (int)$user['id']);
        if ($policyErr !== null) {
            $error = $policyErr;
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->beginTransaction();
            try {
                $upd = $db->prepare(
                    'UPDATE users
                     SET password_hash = ?, failed_login_count = 0, locked_until = NULL,
                         must_reset_password = 0, email_verified_at = COALESCE(email_verified_at, NOW())
                     WHERE id = ?'
                );
                $upd->execute([$hash, $user['id']]);

                $mark = $db->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?');
                $mark->execute([(int)$resetRow['id']]);

                // Invalidate all other outstanding reset tokens for this user
                $cleanup = $db->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
                $cleanup->execute([$user['id']]);

                recordPasswordInHistory((int)$user['id'], $hash, $db);

                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            // Confirmation email (failure to send is not blocking)
            Mailer::send('reset_confirm', $user['email'], $user['full_name'], [
                'fullName'  => $user['full_name'],
                'loginUrl'  => APP_URL . '/login',
                'eventTime' => gmdate('Y-m-d H:i'),
                'eventIp'   => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ], $user['id']);

            logActivity('password_reset_completed', null, 'users', $user['id'],
                'Password reset completed for ' . $user['email']);

            $success = true;
        }
    }
}
?>
<?php require APP_ROOT . '/templates/layouts/header.php'; ?>

<div class="auth-page">
    <div class="auth-card">
        <h1><span style="color:#10B2B4">TCH</span> Admin</h1>
        <p class="subtitle">Set a new password</p>

        <?php if ($success): ?>
            <div class="alert alert-success">
                Your password has been updated. You can now sign in.
            </div>
            <p style="text-align:center;margin-top:1.5rem;">
                <a href="<?= APP_URL ?>/login?reset=1" class="btn btn-primary" style="display:inline-block;">Go to Login</a>
            </p>
        <?php elseif (!$tokenValid): ?>
            <div class="alert alert-error">
                This password reset link is invalid or has expired.
                Please request a new one.
            </div>
            <p style="text-align:center;margin-top:1.5rem;">
                <a href="<?= APP_URL ?>/forgot-password">Request new reset link</a>
            </p>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!empty($_GET['forced'])): ?>
                <div class="alert alert-info" style="background:#fff3cd;color:#856404;padding:0.5rem 0.75rem;border-radius:4px;margin-bottom:0.75rem;">
                    Your administrator has required you to set a new password before signing in.
                </div>
            <?php endif; ?>
            <p style="font-size:0.95rem;color:#555;">
                Setting a new password for <strong><?= htmlspecialchars($user['email']) ?></strong>.
            </p>
            <p style="font-size:0.85rem;color:#6c757d;background:#f8f9fa;padding:0.5rem 0.75rem;border-radius:4px;">
                <strong>Password rules:</strong> <?= htmlspecialchars(passwordPolicyRulesText()) ?>
            </p>

            <form method="POST" action="<?= APP_URL ?>/reset-password">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($rawToken) ?>">
                <div class="form-group">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" class="form-control" autocomplete="new-password" required minlength="10">
                </div>
                <div class="form-group">
                    <label for="password_confirm">Confirm New Password</label>
                    <input type="password" id="password_confirm" name="password_confirm" class="form-control" autocomplete="new-password" required minlength="10">
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:0.5rem;">Set Password</button>
            </form>
        <?php endif; ?>
    </div>
</div>
