-- ================================================================
--  017_users_linked_client_id.sql
--  Add the missing `linked_client_id` column on users.
--  Admin code (users_detail.php) and the invite setup_password.php
--  both reference this column in UPDATE/INSERT statements — its
--  absence was causing HTTP 500 on every invite-acceptance (logged
--  as BUG-setup-pw 2026-04-12).
--
--  Mirrors linked_caregiver_id: nullable, FK to clients.id.
-- ================================================================

START TRANSACTION;

ALTER TABLE users
    ADD COLUMN linked_client_id INT UNSIGNED NULL DEFAULT NULL AFTER linked_caregiver_id,
    ADD KEY idx_users_linked_client (linked_client_id);

COMMIT;
