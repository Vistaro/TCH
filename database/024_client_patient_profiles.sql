-- ================================================================
--  024_client_patient_profiles.sql
--
--  Foundation schema for the Client + Patient profile build:
--    - person_phones  (multi-phone per person, primary flag)
--    - person_emails  (multi-email per person, primary flag)
--    - person_addresses (multi-address per person, primary flag)
--    - persons.salutation / first_name / middle_names / last_name
--      (name parts — full_name remains the canonical display string)
--    - persons.archived_at / archived_by_user_id / archived_reason
--      (soft-archive — never delete)
--    - pages: client_view, patient_view (for the new detail pages)
--
--  All backfills are best-effort and idempotent so this file can be
--  re-run safely. Existing persons.mobile / secondary_number / email
--  columns are LEFT IN PLACE for one release as a fallback while the
--  UI moves to the new tables.
-- ================================================================

SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

-- ── 1. person_phones ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS person_phones (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id    INT UNSIGNED NOT NULL,
    label        VARCHAR(40) DEFAULT NULL,
    phone        VARCHAR(40) NOT NULL COMMENT 'E.164',
    is_primary   TINYINT(1) NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_pp_person  (person_id),
    KEY idx_pp_phone   (phone),
    KEY idx_pp_primary (person_id, is_primary),
    CONSTRAINT fk_pp_person FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill from persons.mobile (idempotent: skip if a row already exists)
INSERT INTO person_phones (person_id, label, phone, is_primary)
SELECT p.id, 'Mobile', p.mobile, 1
FROM persons p
WHERE p.mobile IS NOT NULL
  AND p.mobile <> ''
  AND NOT EXISTS (
      SELECT 1 FROM person_phones pp
      WHERE pp.person_id = p.id AND pp.phone = p.mobile
  );

-- Backfill from persons.secondary_number
INSERT INTO person_phones (person_id, label, phone, is_primary)
SELECT p.id, 'Secondary', p.secondary_number, 0
FROM persons p
WHERE p.secondary_number IS NOT NULL
  AND p.secondary_number <> ''
  AND NOT EXISTS (
      SELECT 1 FROM person_phones pp
      WHERE pp.person_id = p.id AND pp.phone = p.secondary_number
  );


-- ── 2. person_emails ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS person_emails (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id    INT UNSIGNED NOT NULL,
    label        VARCHAR(40) DEFAULT NULL,
    email        VARCHAR(150) NOT NULL,
    is_primary   TINYINT(1) NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_pe_person  (person_id),
    KEY idx_pe_email   (email),
    KEY idx_pe_primary (person_id, is_primary),
    CONSTRAINT fk_pe_person FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO person_emails (person_id, label, email, is_primary)
SELECT p.id, 'Primary', p.email, 1
FROM persons p
WHERE p.email IS NOT NULL
  AND p.email <> ''
  AND NOT EXISTS (
      SELECT 1 FROM person_emails pe
      WHERE pe.person_id = p.id AND LOWER(pe.email) = LOWER(p.email)
  );


-- ── 3. person_addresses ─────────────────────────────────────────
-- Broken out from the start (Ross's design call) — cheaper to support
-- now than to migrate a single-address column out later.
CREATE TABLE IF NOT EXISTS person_addresses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id       INT UNSIGNED NOT NULL,
    label           VARCHAR(40) DEFAULT NULL COMMENT 'Home / Work / Billing / etc.',
    complex_estate  VARCHAR(150) DEFAULT NULL,
    street_address  VARCHAR(200) DEFAULT NULL,
    suburb          VARCHAR(100) DEFAULT NULL,
    city            VARCHAR(100) DEFAULT NULL,
    province        VARCHAR(100) DEFAULT NULL,
    postal_code     VARCHAR(20)  DEFAULT NULL,
    country         VARCHAR(60)  NOT NULL DEFAULT 'South Africa',
    latitude        DECIMAL(10,7) DEFAULT NULL,
    longitude       DECIMAL(10,7) DEFAULT NULL,
    is_primary      TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_pa_person  (person_id),
    KEY idx_pa_primary (person_id, is_primary),
    CONSTRAINT fk_pa_person FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill primary address from existing flat columns on persons.
INSERT INTO person_addresses
    (person_id, label, complex_estate, street_address, suburb, city, province, postal_code, country, is_primary)
SELECT p.id,
       'Primary',
       p.complex_estate, p.street_address, p.suburb, p.city, p.province, p.postal_code,
       COALESCE(p.country, 'South Africa'),
       1
FROM persons p
WHERE (p.street_address IS NOT NULL AND p.street_address <> '')
   OR (p.suburb         IS NOT NULL AND p.suburb         <> '')
   OR (p.city           IS NOT NULL AND p.city           <> '')
   OR (p.postal_code    IS NOT NULL AND p.postal_code    <> '')
   AND NOT EXISTS (
       SELECT 1 FROM person_addresses pa WHERE pa.person_id = p.id AND pa.is_primary = 1
   );


-- ── 4. Name parts on persons (idempotent ALTERs) ───────────────
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'persons' AND COLUMN_NAME = 'salutation');
SET @sql := IF(@c = 0,
    'ALTER TABLE persons ADD COLUMN salutation VARCHAR(20) NULL AFTER full_name',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'persons' AND COLUMN_NAME = 'first_name');
SET @sql := IF(@c = 0,
    'ALTER TABLE persons ADD COLUMN first_name VARCHAR(80) NULL AFTER salutation',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'persons' AND COLUMN_NAME = 'middle_names');
SET @sql := IF(@c = 0,
    'ALTER TABLE persons ADD COLUMN middle_names VARCHAR(120) NULL AFTER first_name',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'persons' AND COLUMN_NAME = 'last_name');
SET @sql := IF(@c = 0,
    'ALTER TABLE persons ADD COLUMN last_name VARCHAR(80) NULL AFTER middle_names',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Best-effort split of existing full_name where parts not yet set.
-- Single-token names → first_name = full_name.
-- Two-token names    → first_name + last_name.
-- Three+ tokens      → first_name + middle_names (everything between) + last_name.
UPDATE persons
SET first_name = SUBSTRING_INDEX(TRIM(full_name), ' ', 1),
    last_name  = CASE
                     WHEN LOCATE(' ', TRIM(full_name)) = 0 THEN NULL
                     ELSE SUBSTRING_INDEX(TRIM(full_name), ' ', -1)
                 END,
    middle_names = CASE
                       WHEN (LENGTH(TRIM(full_name)) - LENGTH(REPLACE(TRIM(full_name), ' ', ''))) < 2
                           THEN NULL
                       ELSE TRIM(SUBSTRING(
                           TRIM(full_name),
                           LENGTH(SUBSTRING_INDEX(TRIM(full_name), ' ', 1)) + 2,
                           LENGTH(TRIM(full_name))
                             - LENGTH(SUBSTRING_INDEX(TRIM(full_name), ' ', 1))
                             - LENGTH(SUBSTRING_INDEX(TRIM(full_name), ' ', -1))
                             - 2
                       ))
                   END
WHERE full_name IS NOT NULL
  AND full_name <> ''
  AND first_name IS NULL;


-- ── 5. Archive columns on persons ──────────────────────────────
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'persons' AND COLUMN_NAME = 'archived_at');
SET @sql := IF(@c = 0,
    'ALTER TABLE persons ADD COLUMN archived_at TIMESTAMP NULL AFTER updated_at',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'persons' AND COLUMN_NAME = 'archived_by_user_id');
SET @sql := IF(@c = 0,
    'ALTER TABLE persons ADD COLUMN archived_by_user_id INT UNSIGNED NULL AFTER archived_at',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'persons' AND COLUMN_NAME = 'archived_reason');
SET @sql := IF(@c = 0,
    'ALTER TABLE persons ADD COLUMN archived_reason VARCHAR(255) NULL AFTER archived_by_user_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'persons' AND INDEX_NAME = 'idx_persons_archived');
SET @sql := IF(@c = 0,
    'ALTER TABLE persons ADD INDEX idx_persons_archived (archived_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ── 6. Register new admin pages + grant Super Admin ───────────
INSERT IGNORE INTO pages (code, label, section, description, sort_order) VALUES
    ('client_view',  'Client profile',  'records', 'Client profile page (view + edit + archive)', 31),
    ('patient_view', 'Patient profile', 'records', 'Patient profile page (view + edit + archive)', 36);

INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT 1, p.id, 1, 1, 1, 1
FROM pages p
WHERE p.code IN ('client_view', 'patient_view')
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = 1 AND rp.page_id = p.id);


COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

-- ================================================================
--  ROLLBACK (manual, in this order):
--    DROP TABLE IF EXISTS person_addresses;
--    DROP TABLE IF EXISTS person_emails;
--    DROP TABLE IF EXISTS person_phones;
--    ALTER TABLE persons
--      DROP COLUMN salutation, DROP COLUMN first_name,
--      DROP COLUMN middle_names, DROP COLUMN last_name,
--      DROP COLUMN archived_at, DROP COLUMN archived_by_user_id,
--      DROP COLUMN archived_reason, DROP INDEX idx_persons_archived;
--    DELETE FROM pages WHERE code IN ('client_view','patient_view');
--  Existing persons.mobile/secondary_number/email + flat address
--  columns are intentionally LEFT IN PLACE — they remain the
--  fallback source while the UI rolls over.
-- ================================================================
