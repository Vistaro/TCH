-- ================================================================
--  018_password_history.sql
--  Track the last N password hashes per user so new-password choices
--  can refuse reuse. Pruning (keep most recent 5) is handled by
--  the app when inserting a new row.
-- ================================================================

START TRANSACTION;

CREATE TABLE IF NOT EXISTS user_password_history (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_uph_user_created (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
