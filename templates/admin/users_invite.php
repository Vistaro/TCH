<?php
/**
 * Invite a new user — /admin/users/invite
 *
 * Creates a row in user_invites with a SHA-256 token hash and emails the
 * raw token in a setup-password URL. The recipient picks their own password.
 *
 * Permission: users.create
 */

require_once APP_ROOT . '/includes/mailer.php';

$pageTitle = 'Invite User';
$activeNav = 'users';

$db = getDB();
$me = currentEffectiveUser();

$error = '';
$success = false;
$inviteUrl = null; // shown on screen as a fallback in case mail() fails

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission.';
    } else {
        $email     = strtolower(trim($_POST['email'] ?? ''));
        $fullName  = trim($_POST['full_name'] ?? '');
        $roleId    = (int)($_POST['role_id'] ?? 0);
        $managerId = $_POST['manager_id'] !== '' ? (int)$_POST['manager_id'] : null;
        $linkCgId  = $_POST['linked_caregiver_id'] !== '' ? (int)$_POST['linked_caregiver_id'] : null;
        $linkClId  = $_POST['linked_client_id'] !== '' ? (int)$_POST['linked_client_id'] : null;

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif ($fullName === '') {
            $error = 'Please enter the full name.';
        } elseif ($roleId === 0) {
            $error = 'Please select a role.';
        } else {
            // Refuse if email already belongs to an active user
            $stmt = $db->prepare('SELECT id, is_active FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $existing = $stmt->fetch();
            if ($existing && (int)$existing['is_active'] === 1) {
                $error = 'A user with that email already exists.';
            } else {
                // Generate token (raw + hash)
                $rawToken  = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresHours = 72; // 3 days

                $ins = $db->prepare(
                    'INSERT INTO user_invites
                        (email, full_name, role_id, manager_id, linked_caregiver_id, linked_client_id,
                         token_hash, expires_at, created_by, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR), ?, NOW())'
                );
                $ins->execute([
                    $email, $fullName, $roleId, $managerId, $linkCgId, $linkClId,
                    $tokenHash, $expiresHours, (int)$me['id'],
                ]);
                $inviteId = (int)$db->lastInsertId();

                $inviteUrl = APP_URL . '/setup-password?token=' . $rawToken;

                // Get role name for the email body
                $r = $db->prepare('SELECT name FROM roles WHERE id = ?');
                $r->execute([$roleId]);
                $roleName = $r->fetchColumn() ?: 'User';

                Mailer::send('invite', $email, $fullName, [
                    'fullName'     => $fullName,
                    'inviterName'  => $me['full_name'] ?? $me['email'],
                    'roleName'     => $roleName,
                    'setupUrl'     => $inviteUrl,
                    'expiresHours' => $expiresHours,
                ]);

                logActivity('user_invited', 'users', 'user_invites', $inviteId,
                    'Invited ' . $email . ' as ' . $roleName);

                $success = true;
            }
        }
    }
}

$roles = $db->query('SELECT id, name FROM roles ORDER BY id')->fetchAll();
$potentialManagers = $db->query(
    'SELECT id, full_name, email FROM users
     WHERE is_active = 1 AND role_id IN (1, 2, 3)
     ORDER BY full_name'
)->fetchAll();

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="margin-bottom:1rem;">
    <a href="<?= APP_URL ?>/admin/users" class="btn btn-outline btn-sm">&larr; Back to users</a>
</div>

<?php if ($success): ?>
    <div class="alert alert-success">
        <strong>Invitation sent.</strong>
        An email has been sent to the recipient with a setup link valid for 72 hours.
    </div>
    <?php if ($inviteUrl): ?>
        <div class="alert alert-info" style="margin-top:0.5rem;">
            <strong>Dev fallback link</strong> (in case mail() doesn't deliver):<br>
            <code style="word-break:break-all;"><?= htmlspecialchars($inviteUrl) ?></code>
        </div>
    <?php endif; ?>
    <p style="margin-top:1rem;">
        <a href="<?= APP_URL ?>/admin/users" class="btn btn-primary">Back to users</a>
        <a href="<?= APP_URL ?>/admin/users/invite" class="btn btn-outline">Invite another</a>
    </p>
<?php else: ?>
    <?php if ($error): ?>
        <div class="alert alert-error" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= APP_URL ?>/admin/users/invite" class="enquiry-form" style="max-width:640px;">
        <?= csrfField() ?>

        <div class="form-group">
            <label for="email">Email Address <span class="required">*</span></label>
            <input type="email" id="email" name="email" class="form-control"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <label for="full_name">Full Name <span class="required">*</span></label>
            <input type="text" id="full_name" name="full_name" class="form-control"
                   value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <label for="role_id">Role <span class="required">*</span></label>
            <select id="role_id" name="role_id" class="form-control" required>
                <option value="">— Select role —</option>
                <?php foreach ($roles as $r): ?>
                    <option value="<?= (int)$r['id'] ?>" <?= (int)($_POST['role_id'] ?? 0) === (int)$r['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="manager_id">Manager (optional)</label>
            <select id="manager_id" name="manager_id" class="form-control">
                <option value="">— No manager (top of hierarchy) —</option>
                <?php foreach ($potentialManagers as $m): ?>
                    <option value="<?= (int)$m['id'] ?>" <?= (string)($_POST['manager_id'] ?? '') === (string)$m['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($m['full_name']) ?> (<?= htmlspecialchars($m['email']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <small style="color:#666;">Determines which records this user can see via hierarchy.</small>
        </div>

        <div class="form-group">
            <label for="linked_caregiver_id">Linked Caregiver (optional)</label>
            <input type="number" id="linked_caregiver_id" name="linked_caregiver_id" class="form-control"
                   value="<?= htmlspecialchars($_POST['linked_caregiver_id'] ?? '') ?>" min="0" placeholder="Caregiver ID">
            <small style="color:#666;">For Caregiver role: links the user to their own caregiver record.</small>
        </div>

        <div class="form-group">
            <label for="linked_client_id">Linked Client (optional)</label>
            <input type="number" id="linked_client_id" name="linked_client_id" class="form-control"
                   value="<?= htmlspecialchars($_POST['linked_client_id'] ?? '') ?>" min="0" placeholder="Client ID">
            <small style="color:#666;">For Client role: links the user to their own client record.</small>
        </div>

        <button type="submit" class="btn btn-primary">Send Invitation</button>
        <a href="<?= APP_URL ?>/admin/users" class="btn btn-outline">Cancel</a>
    </form>
<?php endif; ?>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
