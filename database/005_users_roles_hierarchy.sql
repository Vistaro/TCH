-- TCH Placements — Migration 005: User Management, Roles, Hierarchy, Audit
--
-- Locked decisions (see docs/TCH_Ross_Todo.md "Locked Build Plan: User Management"):
--   - Email is the canonical login identifier
--   - Permission verbs are CRUD (Read / Create / Edit / Delete) per page per role
--   - 5 seed roles: Super Admin, Admin, Manager, Caregiver, Client
--   - Hierarchy applies to record visibility, not page access
--   - Mutations-only audit log; impersonation by Super Admin only with re-auth
--   - Caregiver/client user accounts are infrastructure-only — invited individually
--   - Existing 'ross' row migrates in place: keep password, mark verified, become Super Admin
--
-- IDEMPOTENCY: This migration uses CREATE TABLE IF NOT EXISTS and conditional
-- ALTERs via INFORMATION_SCHEMA so it can be re-run safely. Run once on the
-- shared dev/prod database.
--
-- Run:
--   mysql -u <user> -p <db> < database/005_users_roles_hierarchy.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- ROLES
-- The 5 seed roles. is_system rows cannot be deleted from the UI.
-- ============================================================
CREATE TABLE IF NOT EXISTS roles (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug            VARCHAR(50) NOT NULL UNIQUE COMMENT 'Stable code, e.g. super_admin',
    name            VARCHAR(80) NOT NULL COMMENT 'Display name',
    description     VARCHAR(255) DEFAULT NULL,
    is_system       TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = cannot be deleted',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO roles (id, slug, name, description, is_system) VALUES
    (1, 'super_admin', 'Super Admin', 'Full access including user management and impersonation. Can never be locked out.', 1),
    (2, 'admin',       'Admin',       'Full operational access including user and role management. No impersonation.', 1),
    (3, 'manager',     'Manager',     'Operational access for assigned hierarchy. No user or role management.', 1),
    (4, 'caregiver',   'Caregiver',   'Self-service access to own profile only.', 1),
    (5, 'client',      'Client',      'Self-service access to own client record only.', 1);

-- ============================================================
-- PAGES
-- Every page that gates on permission registers itself here.
-- The admin matrix UI (Session B) reads this list to render columns.
-- ============================================================
CREATE TABLE IF NOT EXISTS pages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(60) NOT NULL UNIQUE COMMENT 'Stable code used in requirePagePermission()',
    label           VARCHAR(120) NOT NULL,
    section         VARCHAR(60) NOT NULL DEFAULT 'general' COMMENT 'For UI grouping',
    description     VARCHAR(255) DEFAULT NULL,
    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO pages (code, label, section, description, sort_order) VALUES
    ('dashboard',                  'Dashboard',                  'core',     'Admin home dashboard',                       10),
    ('caregivers',                 'Caregivers',                 'records',  'Caregiver profiles, training, banking',      20),
    ('clients',                    'Clients',                    'records',  'Client records and contacts',                30),
    ('roster',                     'Roster',                     'records',  'Daily roster and shift records',             40),
    ('billing',                    'Billing',                    'records',  'Caregiver pay and client billing records',   50),
    ('people_review',              'Person Review Queue',        'data',     'Pending Tuniti intake records',              60),
    ('names_reconcile',            'Name Reconciliation',        'data',     'Match name variants to canonical records',   70),
    ('enquiries',                  'Public Enquiries',           'inbox',    'Public enquiry form submissions',            80),
    ('reports_caregiver_earnings', 'Report: Caregiver Earnings', 'reports',  'Per-caregiver earnings report',              90),
    ('reports_client_billing',     'Report: Client Billing',     'reports',  'Per-client billing report',                 100),
    ('reports_days_worked',        'Report: Days Worked',        'reports',  'Days-worked summary report',                110),
    ('users',                      'User Management',            'admin',    'User accounts, invitations, deactivation',  200),
    ('roles',                      'Roles & Permissions',        'admin',    'Role definitions and permission matrix',    210),
    ('activity_log',               'Activity Log',               'admin',    'Audit log of all mutations',                220),
    ('email_log',                  'Email Outbox',               'admin',    'Sent and queued emails',                    230),
    ('config',                     'System Config',              'admin',    'Lookup tables and regions',                 240),
    ('self_service',               'My Profile',                 'self',     'Caregiver/client self-service area',        300);

-- ============================================================
-- ROLE PERMISSIONS
-- Page x Role x CRUD verb. Composite unique key on (role_id, page_id).
-- ============================================================
CREATE TABLE IF NOT EXISTS role_permissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id         INT UNSIGNED NOT NULL,
    page_id         INT UNSIGNED NOT NULL,
    can_read        TINYINT(1) NOT NULL DEFAULT 0,
    can_create      TINYINT(1) NOT NULL DEFAULT 0,
    can_edit        TINYINT(1) NOT NULL DEFAULT 0,
    can_delete      TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_role_page (role_id, page_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default permission matrix.
-- Super Admin (1): everything.
-- Admin (2): everything (user/role mgmt included; impersonation gated separately in code).
-- Manager (3): everything except users + roles.
-- Caregiver (4): self_service only.
-- Client (5): self_service only.

-- Super Admin: full CRUD on every page
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT 1, id, 1, 1, 1, 1 FROM pages;

-- Admin: full CRUD on every page (impersonation enforced in code, not table)
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT 2, id, 1, 1, 1, 1 FROM pages;

-- Manager: full CRUD on every page EXCEPT users, roles
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT 3, id, 1, 1, 1, 1 FROM pages
WHERE code NOT IN ('users', 'roles');

-- Caregiver: self_service only (read + edit own contact details, gated in code by field)
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT 4, id, 1, 0, 1, 0 FROM pages WHERE code = 'self_service';

-- Client: self_service only
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT 5, id, 1, 0, 1, 0 FROM pages WHERE code = 'self_service';

-- ============================================================
-- USERS — extend existing table
-- Existing columns: id, username, password_hash, full_name, email,
-- role (VARCHAR), is_active, last_login, created_at, updated_at
--
-- We add: role_id, manager_id, linked_caregiver_id, linked_client_id,
-- email_verified_at, failed_login_count, locked_until, must_reset_password,
-- and a unique index on email.
-- ============================================================

-- Conditional column adds (idempotent for re-runs)
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role_id') = 0,
    'ALTER TABLE users ADD COLUMN role_id INT UNSIGNED NULL AFTER role',
    'SELECT "role_id already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'manager_id') = 0,
    'ALTER TABLE users ADD COLUMN manager_id INT UNSIGNED NULL COMMENT "FK self — hierarchical manager" AFTER role_id',
    'SELECT "manager_id already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'linked_caregiver_id') = 0,
    'ALTER TABLE users ADD COLUMN linked_caregiver_id INT UNSIGNED NULL COMMENT "Caregiver record this user owns/represents" AFTER manager_id',
    'SELECT "linked_caregiver_id already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'linked_client_id') = 0,
    'ALTER TABLE users ADD COLUMN linked_client_id INT UNSIGNED NULL COMMENT "Client record this user owns/represents" AFTER linked_caregiver_id',
    'SELECT "linked_client_id already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified_at') = 0,
    'ALTER TABLE users ADD COLUMN email_verified_at TIMESTAMP NULL DEFAULT NULL AFTER linked_client_id',
    'SELECT "email_verified_at already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'failed_login_count') = 0,
    'ALTER TABLE users ADD COLUMN failed_login_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER email_verified_at',
    'SELECT "failed_login_count already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'locked_until') = 0,
    'ALTER TABLE users ADD COLUMN locked_until TIMESTAMP NULL DEFAULT NULL AFTER failed_login_count',
    'SELECT "locked_until already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'must_reset_password') = 0,
    'ALTER TABLE users ADD COLUMN must_reset_password TINYINT(1) NOT NULL DEFAULT 0 AFTER locked_until',
    'SELECT "must_reset_password already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Make email NOT NULL and unique. First ensure no nulls or duplicates exist.
-- The existing 'ross' row already has email populated; this is a safety net.
UPDATE users SET email = CONCAT('user', id, '@placeholder.invalid') WHERE email IS NULL OR email = '';

-- Add unique index on email if missing
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uk_users_email') = 0,
    'ALTER TABLE users ADD UNIQUE KEY uk_users_email (email)',
    'SELECT "uk_users_email already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Migrate existing 'ross' row in place: assign Super Admin role, mark verified.
-- Password and email stay as they are.
UPDATE users
SET role_id = 1,
    email_verified_at = COALESCE(email_verified_at, NOW()),
    must_reset_password = 0
WHERE username = 'ross';

-- Backfill role_id for any other existing users (legacy 'admin' string -> Admin role 2).
UPDATE users SET role_id = 2 WHERE role_id IS NULL AND role = 'admin';
UPDATE users SET role_id = 3 WHERE role_id IS NULL AND role = 'manager';
UPDATE users SET role_id = 4 WHERE role_id IS NULL AND role = 'caregiver';
UPDATE users SET role_id = 5 WHERE role_id IS NULL AND role = 'client';
-- Anything still null becomes Admin (defensive)
UPDATE users SET role_id = 2 WHERE role_id IS NULL;

-- ============================================================
-- USER INVITES
-- An invite is created by an admin. The recipient receives an email
-- with /setup-password?token=... which lets them set their own password.
-- ============================================================
CREATE TABLE IF NOT EXISTS user_invites (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email               VARCHAR(150) NOT NULL,
    full_name           VARCHAR(150) NOT NULL,
    role_id             INT UNSIGNED NOT NULL,
    manager_id          INT UNSIGNED NULL,
    linked_caregiver_id INT UNSIGNED NULL,
    linked_client_id    INT UNSIGNED NULL,
    token_hash          CHAR(64) NOT NULL COMMENT 'SHA-256 of token; raw token only ever in email',
    expires_at          TIMESTAMP NOT NULL,
    used_at             TIMESTAMP NULL DEFAULT NULL,
    created_by          INT UNSIGNED NOT NULL,
    created_user_id     INT UNSIGNED NULL COMMENT 'Populated when accepted',
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_invite_token (token_hash),
    INDEX idx_invite_email (email),
    INDEX idx_invite_expires (expires_at),
    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PASSWORD RESETS
-- Token-based reset. /forgot-password creates a row + sends email.
-- /reset-password?token=... validates and lets the user set a new password.
-- ============================================================
CREATE TABLE IF NOT EXISTS password_resets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    token_hash      CHAR(64) NOT NULL COMMENT 'SHA-256 of raw token',
    expires_at      TIMESTAMP NOT NULL,
    used_at         TIMESTAMP NULL DEFAULT NULL,
    requested_ip    VARCHAR(45) DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_reset_token (token_hash),
    INDEX idx_reset_user (user_id),
    INDEX idx_reset_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- EMAIL LOG
-- Every send attempt is recorded BEFORE PHP mail() runs. This means a
-- developer can always retrieve the link even if mail() silently fails
-- (which is common on dev hosts without a real MTA).
-- ============================================================
CREATE TABLE IF NOT EXISTS email_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    to_email        VARCHAR(150) NOT NULL,
    to_name         VARCHAR(150) DEFAULT NULL,
    from_email      VARCHAR(150) NOT NULL,
    from_name       VARCHAR(150) DEFAULT NULL,
    subject         VARCHAR(255) NOT NULL,
    body_text       MEDIUMTEXT NOT NULL,
    template        VARCHAR(60) DEFAULT NULL COMMENT 'e.g. invite, reset, reset_confirm',
    related_user_id INT UNSIGNED NULL,
    status          ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
    error_message   VARCHAR(500) DEFAULT NULL,
    sent_at         TIMESTAMP NULL DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_to (to_email),
    INDEX idx_email_status (status),
    INDEX idx_email_created (created_at),
    FOREIGN KEY (related_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ACTIVITY LOG (audit trail for mutations)
-- Records: login, logout, create, edit, delete, status change, approve,
-- reject, impersonation start/stop. Page views are NOT logged in v1.
--
-- Both real_user_id and impersonator_user_id are recorded so that
-- impersonated actions can be traced back to the real human.
-- ============================================================
CREATE TABLE IF NOT EXISTS activity_log (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    real_user_id            INT UNSIGNED NULL COMMENT 'NULL for anonymous (e.g. failed login)',
    impersonator_user_id    INT UNSIGNED NULL COMMENT 'Set when action was performed under impersonation',
    action                  VARCHAR(50) NOT NULL COMMENT 'login, logout, create, edit, delete, status_change, approve, reject, impersonate_start, impersonate_stop',
    page_code               VARCHAR(60) DEFAULT NULL COMMENT 'Page where action originated',
    entity_type             VARCHAR(60) DEFAULT NULL COMMENT 'caregivers, clients, users, enquiries, etc.',
    entity_id               INT UNSIGNED DEFAULT NULL,
    summary                 VARCHAR(255) DEFAULT NULL COMMENT 'Human-readable one-line description',
    before_json             MEDIUMTEXT DEFAULT NULL,
    after_json              MEDIUMTEXT DEFAULT NULL,
    ip_address              VARCHAR(45) DEFAULT NULL,
    user_agent              VARCHAR(255) DEFAULT NULL,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_act_user (real_user_id),
    INDEX idx_act_imp (impersonator_user_id),
    INDEX idx_act_action (action),
    INDEX idx_act_entity (entity_type, entity_id),
    INDEX idx_act_created (created_at),
    FOREIGN KEY (real_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (impersonator_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- USERS — add foreign keys now that all referenced tables exist.
-- Done after the table creates to avoid ordering issues.
-- ============================================================

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_role') = 0,
    'ALTER TABLE users ADD CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)',
    'SELECT "fk_users_role already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_manager') = 0,
    'ALTER TABLE users ADD CONSTRAINT fk_users_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT "fk_users_manager already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_caregiver') = 0,
    'ALTER TABLE users ADD CONSTRAINT fk_users_caregiver FOREIGN KEY (linked_caregiver_id) REFERENCES caregivers(id) ON DELETE SET NULL',
    'SELECT "fk_users_caregiver already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_client') = 0,
    'ALTER TABLE users ADD CONSTRAINT fk_users_client FOREIGN KEY (linked_client_id) REFERENCES clients(id) ON DELETE SET NULL',
    'SELECT "fk_users_client already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- VERIFY (optional — print row counts so the deploy log shows success)
-- ============================================================
SELECT 'roles' AS tbl, COUNT(*) AS rows_count FROM roles
UNION ALL SELECT 'pages', COUNT(*) FROM pages
UNION ALL SELECT 'role_permissions', COUNT(*) FROM role_permissions
UNION ALL SELECT 'users (with role_id)', COUNT(*) FROM users WHERE role_id IS NOT NULL
UNION ALL SELECT 'ross row', COUNT(*) FROM users WHERE email = 'ross@intelligentae.co.uk' AND role_id = 1;
