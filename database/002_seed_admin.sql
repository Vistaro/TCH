-- TCH Placements — Seed admin user
-- Creates the users table and inserts the admin account.
--
-- IMPORTANT: After running this, change the password immediately
-- via the application or by updating the password_hash directly.
--
-- Default credentials:
--   Username: ross
--   Password: (set via the PHP seed script below — do NOT store in SQL)

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(50) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    full_name       VARCHAR(150) NOT NULL,
    email           VARCHAR(150) DEFAULT NULL,
    role            VARCHAR(30) NOT NULL DEFAULT 'admin',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    last_login      TIMESTAMP NULL DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(50) NOT NULL,
    ip_address      VARCHAR(45) NOT NULL,
    success         TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ll_user (username),
    INDEX idx_ll_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
