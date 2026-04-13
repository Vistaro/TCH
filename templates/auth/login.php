<?php
$pageTitle = 'Login';
initSession();

// Handle login POST
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $error = 'Please enter both email and password.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $user = attemptLogin($email, $password);
            if ($user) {
                // Honour "must reset password" — issue a fresh reset token
                // and redirect to the reset flow before letting them in.
                if (!empty($user['must_reset_password'])) {
                    $raw  = bin2hex(random_bytes(32));
                    $hash = hash('sha256', $raw);
                    $db   = getDB();
                    $db->prepare(
                        'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at)
                         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())'
                    )->execute([(int)$user['id'], $hash]);
                    // Log the user out of the session; the reset page is anonymous
                    $_SESSION = [];
                    header('Location: ' . APP_URL . '/reset-password?token=' . $raw . '&forced=1');
                    exit;
                }
                header('Location: ' . APP_URL . '/admin');
                exit;
            }
            $error = 'Invalid email or password.';
        }
    }
}

$loggedOut = isset($_GET['logged_out']);
$timedOut  = isset($_GET['timeout']);
$resetOk   = isset($_GET['reset']);
?>
<?php require APP_ROOT . '/templates/layouts/header.php'; ?>

<div class="auth-page">
    <div class="auth-card">
        <h1><span style="color:#10B2B4">TCH</span> Admin</h1>
        <p class="subtitle">Sign in to manage placements</p>

        <?php if ($loggedOut): ?>
            <div class="alert alert-success">You have been logged out.</div>
        <?php endif; ?>

        <?php if ($timedOut): ?>
            <div class="alert alert-error">Your session timed out. Please sign in again.</div>
        <?php endif; ?>

        <?php if ($resetOk): ?>
            <div class="alert alert-success">Your password was set. You can now sign in.</div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= APP_URL ?>/login">
            <?= csrfField() ?>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" class="form-control"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" autocomplete="username" autofocus required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:0.5rem;">Sign In</button>
        </form>

        <p style="text-align:center;margin-top:1rem;">
            <a href="<?= APP_URL ?>/forgot-password" style="font-size:0.9rem;color:#666;">Forgot your password?</a>
        </p>
        <p style="font-size:0.8rem;color:#888;text-align:center;margin-top:0.75rem;line-height:1.4;">
            If this is your first sign-in, your password was set via the
            invitation email. Lost it? Use "Forgot your password?" above.
        </p>

        <p style="text-align:center;margin-top:1.5rem;">
            <a href="<?= APP_URL ?>/" style="font-size:0.9rem;color:#666;">&larr; Back to site</a>
        </p>
    </div>
</div>
