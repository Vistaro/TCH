<?php
/**
 * Authentication and session management.
 *
 * Identity model (post-migration 005):
 *   - Email is the canonical login identifier.
 *   - Roles live in the `roles` table; the user row has a role_id FK.
 *   - Hierarchy is via users.manager_id (recursive).
 *   - Caregiver/client self-service users link via linked_caregiver_id / linked_client_id.
 *
 * Impersonation:
 *   - Only Super Admin can impersonate.
 *   - Re-auth (own password) is required to start.
 *   - Session keeps both real_user_id and impersonator_user_id.
 *   - currentEffectiveUser() returns the impersonated identity for permission checks
 *     and UI rendering. currentRealUser() always returns the human at the keyboard.
 *
 * All password operations use password_hash / password_verify (bcrypt).
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not permitted.');
}

// Permission helpers (CRUD checks, hierarchy visibility, logActivity)
require_once APP_ROOT . '/includes/permissions.php';

/**
 * Initialise a secure session.
 */
function initSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);

    session_start();

    // Regenerate session ID periodically to prevent fixation
    if (!isset($_SESSION['_created'])) {
        $_SESSION['_created'] = time();
    } elseif (time() - $_SESSION['_created'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['_created'] = time();
    }
}

/**
 * Generate a CSRF token and store it in the session.
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a submitted CSRF token against the session token.
 */
function validateCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Render a hidden CSRF input field for forms.
 */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Fetch a full user row joined with the role.
 * Returns null if not found.
 */
function fetchUserById(int $userId): ?array {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT u.*, r.slug AS role_slug, r.name AS role_name
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Fetch a user by email (canonical login identifier).
 */
function fetchUserByEmail(string $email): ?array {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT u.*, r.slug AS role_slug, r.name AS role_name
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Attempt to log in by email + password.
 * Returns user array on success, false on failure.
 */
function attemptLogin(string $email, string $password): array|false {
    $email = strtolower(trim($email));
    $user = fetchUserByEmail($email);

    if (!$user || !$user['is_active']) {
        logLoginAttempt($email, false);
        return false;
    }

    // Lockout check
    if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        logLoginAttempt($email, false);
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        // Increment failed counter; lock after 10 failures for 15 min.
        // Super Admin (role_id 1) is exempt from lockout per the spec.
        $db = getDB();
        if ((int)$user['role_id'] !== 1) {
            $newCount = (int)$user['failed_login_count'] + 1;
            if ($newCount >= 10) {
                $stmt = $db->prepare('UPDATE users SET failed_login_count = ?, locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?');
                $stmt->execute([$newCount, $user['id']]);
            } else {
                $stmt = $db->prepare('UPDATE users SET failed_login_count = ? WHERE id = ?');
                $stmt->execute([$newCount, $user['id']]);
            }
        }
        logLoginAttempt($email, false);
        return false;
    }

    $db = getDB();

    // Rehash if needed (algorithm upgrade)
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$newHash, $user['id']]);
    }

    // Reset failed counter, clear lockout, update last_login
    $stmt = $db->prepare('UPDATE users SET failed_login_count = 0, locked_until = NULL, last_login = NOW() WHERE id = ?');
    $stmt->execute([$user['id']]);

    // Set session
    session_regenerate_id(true);
    $_SESSION['user_id']             = (int)$user['id'];
    $_SESSION['real_user_id']        = (int)$user['id'];
    $_SESSION['impersonator_user_id'] = null;
    $_SESSION['email']               = $user['email'];
    $_SESSION['username']            = $user['username']; // legacy compat
    $_SESSION['role']                = $user['role_slug']; // legacy compat
    $_SESSION['role_id']             = (int)$user['role_id'];
    $_SESSION['role_slug']           = $user['role_slug'];
    $_SESSION['role_name']           = $user['role_name'];
    $_SESSION['full_name']           = $user['full_name'];
    $_SESSION['_created']            = time();

    logLoginAttempt($email, true);
    logActivity('login', null, null, null, 'User logged in: ' . $email);

    return $user;
}

/**
 * Log a login attempt for auditing.
 */
function logLoginAttempt(string $identifier, bool $success): void {
    $db = getDB();
    $stmt = $db->prepare(
        'INSERT INTO login_log (username, ip_address, success, attempted_at) VALUES (?, ?, ?, NOW())'
    );
    $stmt->execute([
        substr($identifier, 0, 50),
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $success ? 1 : 0,
    ]);
}

/**
 * Check if the current session is authenticated.
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

/**
 * Require the user to be logged in; redirect to login if not.
 *
 * Note: this is the legacy auth gate. New pages should additionally call
 * requirePagePermission($pageCode, $action) to enforce CRUD permissions.
 */
function requireAuth(): void {
    initSession();
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/login');
        exit;
    }

    // Check session timeout
    if (isset($_SESSION['_last_activity']) && (time() - $_SESSION['_last_activity'] > SESSION_LIFETIME)) {
        logout();
        header('Location: ' . APP_URL . '/login?timeout=1');
        exit;
    }
    $_SESSION['_last_activity'] = time();
}

/**
 * Require the user to have a specific role (legacy shim).
 * Prefer requirePagePermission() for new code.
 */
function requireRole(string $role): void {
    requireAuth();
    $current = $_SESSION['role_slug'] ?? $_SESSION['role'] ?? '';
    if ($current !== $role && $current !== 'admin' && $current !== 'super_admin') {
        http_response_code(403);
        include APP_ROOT . '/templates/errors/403.php';
        exit;
    }
}

/**
 * Log out the current user and destroy the session.
 */
function logout(): void {
    if (isLoggedIn()) {
        logActivity('logout', null, null, null, 'User logged out: ' . ($_SESSION['email'] ?? ''));
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Get current user data from session.
 *
 * Returns the EFFECTIVE user — i.e. the impersonated user if impersonation
 * is active, otherwise the real user. Use currentRealUser() to always get
 * the human at the keyboard.
 */
function currentUser(): ?array {
    return currentEffectiveUser();
}

/**
 * Returns the user whose identity is currently in effect (impersonated, or real).
 */
function currentEffectiveUser(): ?array {
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'id'        => (int)$_SESSION['user_id'],
        'email'     => $_SESSION['email'] ?? '',
        'username'  => $_SESSION['username'] ?? '',
        'role'      => $_SESSION['role_slug'] ?? '',
        'role_id'   => (int)($_SESSION['role_id'] ?? 0),
        'role_slug' => $_SESSION['role_slug'] ?? '',
        'role_name' => $_SESSION['role_name'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
    ];
}

/**
 * Returns the real human at the keyboard, regardless of impersonation.
 */
function currentRealUser(): ?array {
    if (!isLoggedIn()) {
        return null;
    }
    $realId = (int)($_SESSION['real_user_id'] ?? $_SESSION['user_id']);
    return fetchUserById($realId);
}

/**
 * True if the current session is in impersonation mode.
 */
function isImpersonating(): bool {
    return isLoggedIn() && !empty($_SESSION['impersonator_user_id']);
}

/**
 * Begin impersonating another user.
 *
 * Restrictions:
 *   - Only Super Admin (role_id 1) can impersonate.
 *   - Re-auth: caller must supply their own password.
 *   - Cannot impersonate yourself.
 *   - Cannot start impersonation while already impersonating.
 *
 * Returns true on success, false on failure.
 */
function startImpersonation(int $targetUserId, string $reauthPassword): bool {
    if (!isLoggedIn() || isImpersonating()) {
        return false;
    }

    $real = currentRealUser();
    if (!$real || (int)$real['role_id'] !== 1) {
        return false;
    }

    if ($targetUserId === (int)$real['id']) {
        return false;
    }

    // Re-auth
    if (!password_verify($reauthPassword, $real['password_hash'])) {
        logActivity('impersonate_start_failed', null, 'users', $targetUserId, 'Re-auth failed');
        return false;
    }

    $target = fetchUserById($targetUserId);
    if (!$target || !$target['is_active']) {
        return false;
    }

    // Switch session identity to target
    $_SESSION['user_id']              = (int)$target['id'];
    $_SESSION['email']                = $target['email'];
    $_SESSION['username']             = $target['username'];
    $_SESSION['role']                 = $target['role_slug'];
    $_SESSION['role_id']              = (int)$target['role_id'];
    $_SESSION['role_slug']            = $target['role_slug'];
    $_SESSION['role_name']            = $target['role_name'];
    $_SESSION['full_name']            = $target['full_name'];
    $_SESSION['impersonator_user_id'] = (int)$real['id'];
    // real_user_id stays as the original (Super Admin)

    logActivity('impersonate_start', null, 'users', (int)$target['id'],
        'Impersonation started: ' . $real['email'] . ' -> ' . $target['email']);

    return true;
}

/**
 * End impersonation and return to the real user identity.
 */
function stopImpersonation(): void {
    if (!isLoggedIn() || !isImpersonating()) {
        return;
    }

    $impersonatedEmail = $_SESSION['email'] ?? '';
    $realId = (int)$_SESSION['real_user_id'];
    $real = fetchUserById($realId);
    if (!$real) {
        // Defensive: real user vanished — log out entirely
        logout();
        return;
    }

    $_SESSION['user_id']              = (int)$real['id'];
    $_SESSION['email']                = $real['email'];
    $_SESSION['username']             = $real['username'];
    $_SESSION['role']                 = $real['role_slug'];
    $_SESSION['role_id']              = (int)$real['role_id'];
    $_SESSION['role_slug']            = $real['role_slug'];
    $_SESSION['role_name']            = $real['role_name'];
    $_SESSION['full_name']            = $real['full_name'];
    $_SESSION['impersonator_user_id'] = null;

    logActivity('impersonate_stop', null, 'users', (int)$real['id'],
        'Impersonation stopped: was ' . $impersonatedEmail);
}
