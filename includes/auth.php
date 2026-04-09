<?php
/**
 * Authentication and session management.
 * All password operations use bcrypt via password_hash/password_verify.
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not permitted.');
}

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
 * Attempt to log in a user. Returns user array on success, false on failure.
 */
function attemptLogin(string $username, string $password): array|false {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        // Log failed attempt
        logLoginAttempt($username, false);
        return false;
    }

    // Rehash if needed (algorithm upgrade)
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$newHash, $user['id']]);
    }

    // Update last login
    $stmt = $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
    $stmt->execute([$user['id']]);

    // Set session
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['_created'] = time();

    logLoginAttempt($username, true);

    return $user;
}

/**
 * Log a login attempt for auditing.
 */
function logLoginAttempt(string $username, bool $success): void {
    $db = getDB();
    $stmt = $db->prepare(
        'INSERT INTO login_log (username, ip_address, success, attempted_at) VALUES (?, ?, ?, NOW())'
    );
    $stmt->execute([
        $username,
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
 * Require the user to have a specific role.
 */
function requireRole(string $role): void {
    requireAuth();
    if ($_SESSION['role'] !== $role && $_SESSION['role'] !== 'admin') {
        http_response_code(403);
        include APP_ROOT . '/templates/errors/403.php';
        exit;
    }
}

/**
 * Log out the current user and destroy the session.
 */
function logout(): void {
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
 */
function currentUser(): ?array {
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'role' => $_SESSION['role'],
        'full_name' => $_SESSION['full_name'],
    ];
}
