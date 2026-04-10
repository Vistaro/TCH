-- TCH Placements — Migration 003
-- Person Database: unify student/caregiver into one Person record.
--
-- Changes in this migration:
--   1. New lookup tables: person_statuses, lead_sources, attachment_types
--      (replace hard-coded ENUMs, ready for the future config admin page)
--   2. New attachments table (PDF intake sheets, ID copies, photos, etc.)
--   3. Extend `caregivers` table with all PDF intake fields and new identifiers
--   4. Add `tch_id` as the immutable, human-facing person identifier
--   5. Add `import_review_state` for the staging/review workflow
--   6. Drop legacy `source` column (replaced by lead_source_id + tranche)
--
-- Conventions:
--   - Additive where possible. Only the legacy `source` column is dropped.
--   - DB stays permissive. NO new NOT NULL constraints added.
--   - Validation gates per status are app-layer (see TODO #12).
--
-- Run against the dev database:
--   mysql -u tch_admin -p tch_placements < database/003_person_database.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- LOOKUP: PERSON STATUSES
-- Replaces the hard-coded `caregivers.status` ENUM.
-- Editable via the future config admin page (TODO #11).
-- ============================================================
CREATE TABLE IF NOT EXISTS person_statuses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(30) NOT NULL UNIQUE COMMENT 'Stable code used in app logic',
    label           VARCHAR(60) NOT NULL COMMENT 'Display label',
    description     VARCHAR(255) DEFAULT NULL,
    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO person_statuses (code, label, sort_order, description) VALUES
    ('lead',         'Lead',         10, 'Initial contact, no application yet'),
    ('applicant',    'Applicant',    20, 'Has applied, not yet enrolled'),
    ('student',      'Student',      30, 'Enrolled, training not yet started'),
    ('in_training',  'In Training',  40, 'Currently undergoing training (existing value)'),
    ('qualified',    'Qualified',    50, 'Training complete, not yet placed'),
    ('available',    'Available',    60, 'Qualified and available for placement (existing value)'),
    ('placed',       'Placed',       70, 'Currently placed with a client (existing value)'),
    ('inactive',     'Inactive',     80, 'No longer active (existing value)')
ON DUPLICATE KEY UPDATE label = VALUES(label), sort_order = VALUES(sort_order);

-- ============================================================
-- LOOKUP: LEAD SOURCES
-- "How did we come across this person?" — separate from tranche.
-- Editable via the future config admin page (TODO #11).
-- ============================================================
CREATE TABLE IF NOT EXISTS lead_sources (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code                VARCHAR(30) NOT NULL UNIQUE,
    label               VARCHAR(60) NOT NULL,
    sort_order          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    requires_referrer   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'If 1, app should prompt for referrer details',
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO lead_sources (code, label, sort_order, requires_referrer) VALUES
    ('facebook',      'Facebook',       10, 0),
    ('tiktok',        'TikTok',         20, 0),
    ('instagram',     'Instagram',      30, 0),
    ('linkedin',      'LinkedIn',       40, 0),
    ('walk_in',       'Walked In',      50, 0),
    ('phone',         'Phoned Us',      60, 0),
    ('email',         'Emailed Us',     70, 0),
    ('referral',      'Referral',       80, 1),
    ('word_of_mouth', 'Word of Mouth',  90, 0),
    ('other',         'Other',         100, 0),
    ('unknown',       'Unknown',       110, 0)
ON DUPLICATE KEY UPDATE label = VALUES(label), sort_order = VALUES(sort_order);

-- ============================================================
-- LOOKUP: ATTACHMENT TYPES
-- Categories for files attached to a person record.
-- Editable via the future config admin page (TODO #11).
-- ============================================================
CREATE TABLE IF NOT EXISTS attachment_types (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(40) NOT NULL UNIQUE,
    label           VARCHAR(80) NOT NULL,
    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO attachment_types (code, label, sort_order) VALUES
    ('original_data_entry_sheet', 'Original Data Entry Sheet', 10),
    ('profile_photo',             'Profile Photo',             20),
    ('id_document',               'ID Document',               30),
    ('passport',                  'Passport',                  40),
    ('proof_of_address',          'Proof of Address',          50),
    ('qualification_certificate', 'Qualification Certificate', 60),
    ('other',                     'Other',                    100)
ON DUPLICATE KEY UPDATE label = VALUES(label), sort_order = VALUES(sort_order);

-- ============================================================
-- ATTACHMENTS
-- One row per file attached to a person.
-- Files live on disk under public/uploads/people/<tch_id>/
-- ============================================================
CREATE TABLE IF NOT EXISTS attachments (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id           INT UNSIGNED NOT NULL COMMENT 'FK to caregivers.id',
    attachment_type_id  INT UNSIGNED NOT NULL,
    file_path           VARCHAR(255) NOT NULL COMMENT 'Relative to public/uploads/',
    original_filename   VARCHAR(255) DEFAULT NULL,
    mime_type           VARCHAR(80) DEFAULT NULL,
    file_size_bytes     INT UNSIGNED DEFAULT NULL,
    source_pdf          VARCHAR(120) DEFAULT NULL COMMENT 'For Original Data Entry Sheet: which PDF',
    source_page         SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Page within source PDF',
    notes               TEXT DEFAULT NULL,
    uploaded_by         VARCHAR(100) DEFAULT NULL COMMENT 'Username, or NULL for system imports',
    uploaded_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_active           TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Soft delete flag',
    FOREIGN KEY (person_id)          REFERENCES caregivers(id)       ON DELETE CASCADE,
    FOREIGN KEY (attachment_type_id) REFERENCES attachment_types(id) ON DELETE RESTRICT,
    INDEX idx_att_person (person_id),
    INDEX idx_att_type   (attachment_type_id),
    INDEX idx_att_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- EXTEND CAREGIVERS
-- All additions are nullable. No new NOT NULL constraints.
-- ============================================================

-- Personal: title and initials (from PDF)
ALTER TABLE caregivers ADD COLUMN title    VARCHAR(10) DEFAULT NULL AFTER known_as;
ALTER TABLE caregivers ADD COLUMN initials VARCHAR(10) DEFAULT NULL AFTER title;

-- Contact: secondary number, complex/estate
ALTER TABLE caregivers ADD COLUMN secondary_number VARCHAR(30)  DEFAULT NULL AFTER mobile;
ALTER TABLE caregivers ADD COLUMN complex_estate   VARCHAR(150) DEFAULT NULL AFTER street_address;

-- Next of Kin: email, plus a 2nd NoK for fields with multiple entries
ALTER TABLE caregivers ADD COLUMN nok_email          VARCHAR(150) DEFAULT NULL AFTER nok_contact;
ALTER TABLE caregivers ADD COLUMN nok_2_name         VARCHAR(150) DEFAULT NULL AFTER nok_email;
ALTER TABLE caregivers ADD COLUMN nok_2_relationship VARCHAR(50)  DEFAULT NULL AFTER nok_2_name;
ALTER TABLE caregivers ADD COLUMN nok_2_contact      VARCHAR(30)  DEFAULT NULL AFTER nok_2_relationship;
ALTER TABLE caregivers ADD COLUMN nok_2_email        VARCHAR(150) DEFAULT NULL AFTER nok_2_contact;

-- Lead source + referrer (free-text for now; will become FK in TODO #13)
ALTER TABLE caregivers ADD COLUMN lead_source_id      INT UNSIGNED DEFAULT NULL AFTER tranche;
ALTER TABLE caregivers ADD COLUMN referred_by_name    VARCHAR(150) DEFAULT NULL AFTER lead_source_id;
ALTER TABLE caregivers ADD COLUMN referred_by_contact VARCHAR(50)  DEFAULT NULL AFTER referred_by_name;
ALTER TABLE caregivers ADD CONSTRAINT fk_cg_lead_source
    FOREIGN KEY (lead_source_id) REFERENCES lead_sources(id) ON DELETE SET NULL;

-- Status as FK (replaces ENUM further down)
ALTER TABLE caregivers ADD COLUMN status_id INT UNSIGNED DEFAULT NULL AFTER status;
ALTER TABLE caregivers ADD CONSTRAINT fk_cg_status
    FOREIGN KEY (status_id) REFERENCES person_statuses(id) ON DELETE RESTRICT;

-- Backfill status_id from existing ENUM values
UPDATE caregivers cg
JOIN person_statuses ps ON ps.code = LOWER(REPLACE(cg.status, ' ', '_'))
SET cg.status_id = ps.id
WHERE cg.status_id IS NULL;

-- Drop the old ENUM column. The FK is the source of truth from here.
ALTER TABLE caregivers DROP INDEX idx_cg_status;
ALTER TABLE caregivers DROP COLUMN status;
ALTER TABLE caregivers ADD INDEX idx_cg_status (status_id);

-- Notes: split into machine-generated import notes and human notes
ALTER TABLE caregivers ADD COLUMN import_notes TEXT DEFAULT NULL COMMENT 'Auto-populated by parser: assumptions, edge cases, source page' AFTER updated_at;
ALTER TABLE caregivers ADD COLUMN notes        TEXT DEFAULT NULL COMMENT 'Free-form human notes' AFTER import_notes;

-- Import review state — separate from lifecycle status (per agreed approach)
-- NULL = record not created via import (or already approved)
-- pending = waiting for human review
-- approved = reviewed and accepted (then set back to NULL or kept for audit?)
-- rejected = soft-rejected (treat as inactive)
ALTER TABLE caregivers ADD COLUMN import_review_state ENUM('pending','approved','rejected') DEFAULT NULL AFTER notes;
ALTER TABLE caregivers ADD INDEX idx_cg_review (import_review_state);

-- TCH_ID: immutable, human-facing person identifier.
-- Generated column derived from id. Always TCH-NNNNNN (zero-padded 6 digits).
-- Cannot be edited. Survives all name/personal-detail changes.
ALTER TABLE caregivers ADD COLUMN tch_id VARCHAR(20)
    GENERATED ALWAYS AS (CONCAT('TCH-', LPAD(id, 6, '0'))) STORED
    AFTER id;
ALTER TABLE caregivers ADD UNIQUE INDEX uk_cg_tch_id (tch_id);

-- Preserve any existing `source` values into import_notes BEFORE dropping
-- the column. This is a one-shot safety net for legacy rows that already
-- carry a source value (e.g. 'Tuniti'). New imports use lead_source_id + tranche.
UPDATE caregivers
SET import_notes = CONCAT_WS('\n\n',
        NULLIF(import_notes, ''),
        CONCAT('Legacy `source` value preserved from migration 003: ', source))
WHERE source IS NOT NULL AND source != '';

-- Drop legacy `source` column (replaced by lead_source_id + tranche).
-- Per session decision: option C — neither training_provider nor import_origin needed.
ALTER TABLE caregivers DROP COLUMN source;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- POST-MIGRATION NOTES
-- ============================================================
-- After this migration:
--   * caregivers.tch_id is the human-facing identifier (TCH-000001 etc.)
--   * caregivers.status is now status_id → person_statuses
--   * caregivers.source column is gone
--   * Photos belong in attachments (type = profile_photo), not as a column
--   * import_review_state filters the review queue
--
-- Code that still references the dropped `source` column or the old `status`
-- ENUM will need updating before this migration can be safely deployed.
