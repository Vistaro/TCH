-- ================================================================
--  019_users_avatar.sql
--  Add `avatar_path` column on users — relative path under
--  public/uploads/users/<user_id>/avatar_<ts>.<ext>. Optional.
-- ================================================================

START TRANSACTION;

ALTER TABLE users
    ADD COLUMN avatar_path VARCHAR(255) NULL DEFAULT NULL AFTER full_name;

COMMIT;
