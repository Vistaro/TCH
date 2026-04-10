<?php
/**
 * Impersonation start — /admin/users/{id}/impersonate
 *
 * Two-step flow:
 *   GET  → render the re-auth form ("enter your password to impersonate X")
 *   POST → call startImpersonation(targetId, reauthPassword); on success,
 *          redirect to /admin (where the persistent banner will appear)
 *
 * Restrictions enforced by includes/auth.php startImpersonation():
 *   - Only Super Admin can impersonate
 *   - Cannot already be impersonating
 *   - Cannot impersonate yourself
 *   - Re-auth: own current password
 */

$pageTitle = 'Impersonate User';
$activeNav = 'users';

$targetId = (int)($_GET['user_id'] ?? 0);
$db = getDB();
$me = currentEffectiveUser();

$target = fetchUserById($targetId);
if (!$target) {
    http_response_code(404);
    require APP_ROOT . '/templates/layouts/admin.php';
    echo '<p>No user with id ' . (int)$targetId . '.</p>';
    require APP_ROOT . '/templates/layouts/admin_footer.php';
    return;
}

// Hard checks before showing the form
if (!isSuperAdmin()) {
    http_response_code(403);
    require APP_ROOT . '/templates/layouts/admin.php';
    echo '<div class="alert alert-error">Only Super Admins can impersonate other users.</div>';
    echo '<p><a href="' . APP_URL . '/admin/users/' . (int)$targetId . '">Back</a></p>';
    require APP_ROOT . '/templates/layouts/admin_footer.php';
    return;
}

if (isImpersonating()) {
    require APP_ROOT . '/templates/layouts/admin.php';
    echo '<div class="alert alert-error">You are already impersonating another user. End that session first.</div>';
    echo '<p><a href="' . APP_URL . '/admin/impersonate/stop" class="btn btn-primary">End current impersonation</a></p>';
    require APP_ROOT . '/templates/layouts/admin_footer.php';
    return;
}

if ((int)$targetId === (int)$me['id']) {
    require APP_ROOT . '/templates/layouts/admin.php';
    echo '<div class="alert alert-error">You cannot impersonate yourself.</div>';
    echo '<p><a href="' . APP_URL . '/admin/users">Back to users</a></p>';
    require APP_ROOT . '/templates/layouts/admin_footer.php';
    return;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission.';
    } else {
        $reauthPassword = $_POST['password'] ?? '';
        if ($reauthPassword === '') {
            $error = 'Please enter your password to confirm.';
        } else {
            $ok = startImpersonation((int)$targetId, $reauthPassword);
            if ($ok) {
                header('Location: ' . APP_URL . '/admin');
                exit;
            }
            $error = 'Re-authentication failed. Check your password and try again.';
        }
    }
}

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="margin-bottom:1rem;">
    <a href="<?= APP_URL ?>/admin/users/<?= (int)$targetId ?>" class="btn btn-outline btn-sm">&larr; Cancel</a>
</div>

<div class="person-card" style="max-width:560px;">
    <div class="person-card-section">
        <h2>Impersonate User</h2>
        <p>You are about to impersonate:</p>
        <dl>
            <dt>Name</dt><dd><strong><?= htmlspecialchars($target['full_name']) ?></strong></dd>
            <dt>Email</dt><dd><?= htmlspecialchars($target['email']) ?></dd>
            <dt>Role</dt><dd><?= htmlspecialchars($target['role_name'] ?? '—') ?></dd>
        </dl>

        <div class="alert alert-info" style="margin:1rem 0;">
            While impersonating, you will see exactly what this user sees and can take actions
            on their behalf. Every action will be recorded in the activity log against
            <strong>both</strong> identities — yours and theirs. A persistent banner will appear
            across every page until you end the session.
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= csrfField() ?>
            <p>Re-enter <strong>your own</strong> password to confirm:</p>
            <div class="form-group">
                <label for="password">Your password</label>
                <input type="password" id="password" name="password" class="form-control" autocomplete="current-password" autofocus required>
            </div>
            <button type="submit" class="btn btn-danger">Confirm &amp; Start Impersonation</button>
            <a href="<?= APP_URL ?>/admin/users/<?= (int)$targetId ?>" class="btn btn-outline">Cancel</a>
        </form>
    </div>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
