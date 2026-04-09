<?php
$pageTitle = 'Login';
initSession();

// Handle login POST
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Please enter both username and password.';
        } else {
            $user = attemptLogin($username, $password);
            if ($user) {
                header('Location: ' . APP_URL . '/admin');
                exit;
            }
            $error = 'Invalid username or password.';
        }
    }
}

$loggedOut = isset($_GET['logged_out']);
?>
<?php require APP_ROOT . '/templates/layouts/header.php'; ?>

<div class="auth-page">
    <div class="auth-card">
        <h1><span style="color:#10B2B4">TCH</span> Admin</h1>
        <p class="subtitle">Sign in to manage placements</p>

        <?php if ($loggedOut): ?>
            <div class="alert alert-success">You have been logged out.</div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= APP_URL ?>/login">
            <?= csrfField() ?>
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" class="form-control"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autocomplete="username" autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:0.5rem;">Sign In</button>
        </form>

        <p style="text-align:center;margin-top:1.5rem;">
            <a href="<?= APP_URL ?>/" style="font-size:0.9rem;color:#666;">&larr; Back to site</a>
        </p>
    </div>
</div>
