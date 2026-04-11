<?php
/**
 * Permissions, hierarchy visibility, and activity logging.
 *
 * Page-level permissions are CRUD verbs (read / create / edit / delete) per
 * page per role. The matrix lives in the `role_permissions` table and is
 * configurable via the admin matrix UI (Session B).
 *
 * Hierarchy applies to RECORD visibility (caregiver/client/billing/roster
 * data), NOT to admin pages — anyone with permission sees the full admin
 * page; the hierarchy filters which records they see within it.
 *
 * Activity logging records mutations only (login, logout, create, edit,
 * delete, status_change, approve, reject, impersonate_start/stop).
 */

if (!defined('APP_ROOT')) {
    die('Direct access not permitted.');
}

/**
 * Check whether the current effective user has a CRUD permission on a page.
 *
 * @param string $pageCode  Code from the `pages` table (e.g. 'caregivers')
 * @param string $action    'read' | 'create' | 'edit' | 'delete'
 */
function userCan(string $pageCode, string $action = 'read'): bool {
    if (!isLoggedIn()) {
        return false;
    }
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($roleId === 0) {
        return false;
    }

    $column = match ($action) {
        'read'   => 'can_read',
        'create' => 'can_create',
        'edit'   => 'can_edit',
        'delete' => 'can_delete',
        default  => null,
    };
    if ($column === null) {
        return false;
    }

    $db = getDB();
    $stmt = $db->prepare(
        "SELECT rp.$column
         FROM role_permissions rp
         JOIN pages p ON p.id = rp.page_id
         WHERE rp.role_id = ? AND p.code = ?
         LIMIT 1"
    );
    $stmt->execute([$roleId, $pageCode]);
    $val = $stmt->fetchColumn();
    return $val !== false && (int)$val === 1;
}

/**
 * Require permission on a page; redirect / 403 if missing.
 *
 * Calls requireAuth() first, so this is a complete gate for protected pages.
 */
function requirePagePermission(string $pageCode, string $action = 'read'): void {
    requireAuth();
    if (!userCan($pageCode, $action)) {
        http_response_code(403);
        include APP_ROOT . '/templates/errors/403.php';
        exit;
    }
}

/**
 * Returns true if the current real user (not effective) is Super Admin.
 * Used to gate impersonation start.
 */
function isSuperAdmin(): bool {
    if (!isLoggedIn()) {
        return false;
    }
    $realId = (int)($_SESSION['real_user_id'] ?? $_SESSION['user_id']);
    $real = fetchUserById($realId);
    return $real && (int)$real['role_id'] === 1;
}

/**
 * Recursively walk users.manager_id descendants to find every user_id
 * that the given manager can see. Includes the manager themselves.
 *
 * Super Admin and Admin (role_id 1, 2) bypass the hierarchy and see all users.
 */
function getVisibleUserIds(?int $forUserId = null): array {
    if ($forUserId === null) {
        if (!isLoggedIn()) {
            return [];
        }
        $forUserId = (int)$_SESSION['user_id'];
    }

    $user = fetchUserById($forUserId);
    if (!$user) {
        return [];
    }

    // Super Admin / Admin see everyone
    if (in_array((int)$user['role_id'], [1, 2], true)) {
        $db = getDB();
        return array_map('intval', $db->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN));
    }

    // BFS down the manager_id tree
    $visible = [$forUserId];
    $frontier = [$forUserId];
    $db = getDB();
    while ($frontier) {
        $placeholders = implode(',', array_fill(0, count($frontier), '?'));
        $stmt = $db->prepare("SELECT id FROM users WHERE manager_id IN ($placeholders)");
        $stmt->execute($frontier);
        $next = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $next = array_diff($next, $visible);
        if (!$next) {
            break;
        }
        $visible = array_merge($visible, $next);
        $frontier = $next;
    }
    return $visible;
}

/**
 * Get every caregiver_id visible to the given user via hierarchy.
 *
 * Super Admin / Admin see all caregivers.
 * Other roles see caregivers linked to any user in their visible-user set.
 * Caregivers themselves see only their own linked caregiver row.
 */
function getVisibleCaregiverIds(?int $forUserId = null): array {
    if ($forUserId === null) {
        if (!isLoggedIn()) {
            return [];
        }
        $forUserId = (int)$_SESSION['user_id'];
    }

    $user = fetchUserById($forUserId);
    if (!$user) {
        return [];
    }

    // Super Admin / Admin see all
    if (in_array((int)$user['role_id'], [1, 2], true)) {
        $db = getDB();
        return array_map('intval', $db->query(
            "SELECT id FROM persons WHERE FIND_IN_SET('caregiver', person_type)"
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    // Caregiver self-service: only own record
    if ((int)$user['role_id'] === 4) {
        return $user['linked_caregiver_id'] ? [(int)$user['linked_caregiver_id']] : [];
    }

    // Manager: caregivers linked to any user in their hierarchy
    $visibleUsers = getVisibleUserIds($forUserId);
    if (!$visibleUsers) {
        return [];
    }
    $db = getDB();
    $placeholders = implode(',', array_fill(0, count($visibleUsers), '?'));
    $stmt = $db->prepare("SELECT linked_caregiver_id FROM users WHERE id IN ($placeholders) AND linked_caregiver_id IS NOT NULL");
    $stmt->execute($visibleUsers);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Get every client_id visible to the given user via hierarchy.
 */
function getVisibleClientIds(?int $forUserId = null): array {
    if ($forUserId === null) {
        if (!isLoggedIn()) {
            return [];
        }
        $forUserId = (int)$_SESSION['user_id'];
    }

    $user = fetchUserById($forUserId);
    if (!$user) {
        return [];
    }

    if (in_array((int)$user['role_id'], [1, 2], true)) {
        $db = getDB();
        return array_map('intval', $db->query('SELECT id FROM clients')->fetchAll(PDO::FETCH_COLUMN));
    }

    if ((int)$user['role_id'] === 5) {
        return $user['linked_client_id'] ? [(int)$user['linked_client_id']] : [];
    }

    $visibleUsers = getVisibleUserIds($forUserId);
    if (!$visibleUsers) {
        return [];
    }
    $db = getDB();
    $placeholders = implode(',', array_fill(0, count($visibleUsers), '?'));
    $stmt = $db->prepare("SELECT linked_client_id FROM users WHERE id IN ($placeholders) AND linked_client_id IS NOT NULL");
    $stmt->execute($visibleUsers);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Record a mutation in the activity log.
 *
 * @param string      $action      e.g. 'login', 'create', 'edit', 'delete'
 * @param string|null $pageCode    Page where the action originated
 * @param string|null $entityType  e.g. 'caregivers'
 * @param int|null    $entityId    PK of the affected row
 * @param string|null $summary     Human-readable one-liner
 * @param array|null  $before      Snapshot before the change
 * @param array|null  $after       Snapshot after the change
 */
function logActivity(
    string $action,
    ?string $pageCode = null,
    ?string $entityType = null,
    ?int $entityId = null,
    ?string $summary = null,
    ?array $before = null,
    ?array $after = null
): void {
    try {
        $db = getDB();
        // Audit columns:
        //   real_user_id          = the effective identity (the actor as it appears in the log)
        //   impersonator_user_id  = the human at the keyboard, only set when they differ
        //                           from real_user_id (i.e. when impersonation is active)
        // Normal session: real_user_id = $_SESSION['user_id'], impersonator_user_id = NULL
        // Impersonating:  real_user_id = $_SESSION['user_id'] (the impersonated target),
        //                 impersonator_user_id = $_SESSION['real_user_id'] (the Super Admin)
        if (!isLoggedIn()) {
            $realId = null;
            $impId  = null;
        } elseif (!empty($_SESSION['impersonator_user_id'])) {
            $realId = (int)$_SESSION['user_id'];          // impersonated target
            $impId  = (int)$_SESSION['impersonator_user_id']; // Super Admin
        } else {
            $realId = (int)$_SESSION['user_id'];
            $impId  = null;
        }

        $stmt = $db->prepare(
            'INSERT INTO activity_log
                (real_user_id, impersonator_user_id, action, page_code, entity_type, entity_id,
                 summary, before_json, after_json, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $realId,
            $impId,
            $action,
            $pageCode,
            $entityType,
            $entityId,
            $summary !== null ? substr($summary, 0, 255) : null,
            $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $after  !== null ? json_encode($after,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
        ]);
    } catch (Throwable $e) {
        // Audit failures must not break the user-facing flow.
        // In dev they will surface in PHP error log.
        error_log('logActivity failed: ' . $e->getMessage());
    }
}
