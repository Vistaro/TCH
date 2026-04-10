<?php
/**
 * Forgot Password — accept an email, create a password_resets row, send email.
 *
 * Anti-enumeration: we ALWAYS show the same success message regardless of
 * whether the email matches a real account. The email is only sent if the
 * account exists, but the response is identical so attackers cannot probe
 * for valid accounts.
 */
$pageTitle = 'Forgot Password';
initSession();

require_once APP_ROOT . '/includes/mailer.php';

$error = '';
$showSent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $user = fetchUserByEmail($email);
            if ($user && $user['is_active']) {
                // Generate token, store SHA-256 hash, email the raw token in the URL
                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresHours = 2;

                $db = getDB();
                $stmt = $db->prepare(
                    'INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip)
                     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR), ?)'
                );
                $stmt->execute([
                    (int)$user['id'],
                    $tokenHash,
                    $expiresHours,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]);

                $resetUrl = APP_URL . '/reset-password?token=' . $rawToken;

                Mailer::send('reset', $user['email'], $user['full_name'], [
                    'fullName'     => $user['full_name'],
                    'resetUrl'     => $resetUrl,
                    'expiresHours' => $expiresHours,
                    'requestIp'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ], (int)$user['id']);

                logActivity('password_reset_requested', null, 'users', (int)$user['id'],
                    'Reset link sent to ' . $user['email']);
            }
            // Always show success — anti-enumeration
            $showSent = true;
        }
    }
}
?>
<?php require APP_ROOT . '/templates/layouts/header.php'; ?>

<div class="auth-page">
    <div class="auth-card">
        <h1><span style="color:#10B2B4">TCH</span> Admin</h1>
        <p class="subtitle">Reset your password</p>

        <?php if ($showSent): ?>
            <div class="alert alert-success">
                If an account exists for that email address, a password reset
                link has been sent. Please check your inbox.
            </div>
            <p style="text-align:center;margin-top:1.5rem;">
                <a href="<?= APP_URL ?>/login">&larr; Back to login</a>
            </p>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <p style="font-size:0.95rem;color:#555;">
                Enter your email address and we'll send you a link to reset
                your password.
            </p>

            <form method="POST" action="<?= APP_URL ?>/forgot-password">
                <?= csrfField() ?>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" autocomplete="email" autofocus required>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:0.5rem;">Send Reset Link</button>
            </form>

            <p style="text-align:center;margin-top:1.5rem;">
                <a href="<?= APP_URL ?>/login" style="font-size:0.9rem;color:#666;">&larr; Back to login</a>
            </p>
        <?php endif; ?>
    </div>
</div>
