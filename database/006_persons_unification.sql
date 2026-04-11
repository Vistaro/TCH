-- ================================================================
--  006_persons_unification.sql
--  Unify person-shaped records (caregivers, patients, clients) into
--  one `persons` table, and add a universal Activities & Tasks
--  timeline that any entity can hang off.
--
--  Context:
--    Until now TCH has held person records in the `caregivers` table.
--    We're about to load ~50 deduped client/patient records into the
--    same shape, so the table needs a more honest name and a type
--    discriminator. We're also adding a timeline feature (notes +
--    tasks) that will eventually cover every entity that has a
--    record card — persons first, enquiries next.
--
--  Alignment:
--    The `activities` + `activity_types` tables follow Nexus CRM's
--    canonical schema verbatim so both products speak the same
--    language. Field names, enum values, and the seed activity_types
--    list all match nexus-crm-github exactly. See mailbox thread
--    `activities-tasks-schema-2026-04-11` for the reasoning.
--
--  FK auto-retargeting:
--    `RENAME TABLE caregivers TO persons` silently updates every
--    existing FK constraint (caregiver_banking, caregiver_costs,
--    caregiver_rate_history, daily_roster, name_lookup, attachments)
--    to reference `persons(id)` instead. The FK *column names* stay
--    as `caregiver_id` / `client_id` on purpose — they encode the
--    role the person is playing in that relationship, not the table
--    they live in.
--
--  Scope note:
--    This migration is pure schema prep. It does NOT touch the
--    `clients` table, `client_revenue`, or `daily_roster` data. That
--    happens in migration 007 after the patient dedup chat is done
--    and we know which client rows are survivors.
-- ================================================================

SET FOREIGN_KEY_CHECKS = 0;

START TRANSACTION;

-- ----------------------------------------------------------------
-- 1. Rename caregivers → persons
--    FKs on caregiver_banking, caregiver_costs,
--    caregiver_rate_history, daily_roster, name_lookup, attachments
--    are auto-retargeted by InnoDB.
-- ----------------------------------------------------------------
RENAME TABLE caregivers TO persons;


-- ----------------------------------------------------------------
-- 2. Add person_type discriminator
--    SET (not ENUM) so one row can hold multiple labels — e.g.
--    today's patients are also their own clients until a corporate
--    payer arrives, so the record is marked 'patient,client'.
--    Every existing row is a caregiver; DEFAULT backfills them.
-- ----------------------------------------------------------------
ALTER TABLE persons
  ADD COLUMN person_type SET('patient','caregiver','client') NOT NULL DEFAULT 'caregiver'
  AFTER id;


-- ----------------------------------------------------------------
-- 3. activity_types — lookup table (NOT an enum) so new types can
--    be added by an admin via the config UI without a migration.
--    Matches Nexus CRM canonical schema exactly.
-- ----------------------------------------------------------------
CREATE TABLE activity_types (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(80)  NOT NULL,
    icon        VARCHAR(60)  NOT NULL DEFAULT 'fa-circle' COMMENT 'FontAwesome class',
    color       VARCHAR(20)  NOT NULL DEFAULT '#6c757d'   COMMENT 'Hex colour for timeline dot',
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_activity_type_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed rows:
--   First six match Nexus CRM verbatim (keeps labels identical across
--   both products). Seventh ('System') is TCH-specific for auto-
--   generated timeline entries — merge notes, archives, etc.
INSERT INTO activity_types (name, icon, color, sort_order) VALUES
    ('Email',      'fa-envelope',    '#0d6efd', 10),
    ('Phone Call', 'fa-phone',       '#198754', 20),
    ('Meeting',    'fa-users',       '#6f42c1', 30),
    ('Demo',       'fa-desktop',     '#fd7e14', 40),
    ('Follow-up',  'fa-reply',       '#20c997', 50),
    ('Note',       'fa-sticky-note', '#6c757d', 60),
    ('System',     'fa-robot',       '#adb5bd', 70);


-- ----------------------------------------------------------------
-- 4. activities — universal Activities & Tasks timeline
--    Polymorphic via entity_type + entity_id so any record card
--    can hang a timeline off this one table. A "task" is just an
--    activity with is_task=1; activity_date doubles as due date.
--    Schema matches Nexus CRM verbatim. See mailbox thread.
-- ----------------------------------------------------------------
CREATE TABLE activities (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    activity_type_id  INT UNSIGNED NOT NULL,
    entity_type       ENUM('persons','enquiries') NOT NULL,
    entity_id         INT UNSIGNED NOT NULL,
    user_id           INT UNSIGNED NOT NULL        COMMENT 'Author / who logged it',
    subject           VARCHAR(255) NOT NULL DEFAULT '',
    notes             TEXT                         COMMENT 'Body paragraph (NOT a JSON blob)',
    activity_date     DATETIME     NOT NULL        COMMENT 'When it happened; doubles as due date when is_task=1',
    is_task           TINYINT(1)   NOT NULL DEFAULT 0,
    task_status       ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
    assigned_to       INT UNSIGNED NULL            COMMENT 'Task owner. NULL for logged activities.',
    completed_at      DATETIME     NULL            COMMENT 'Distinct from updated_at so we know when a task was resolved vs when any field was touched',
    is_test_data      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the row was inserted (the "Logged 10 Apr 2026 18:10" line in the UI)',
    updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_entity        (entity_type, entity_id),
    KEY idx_assigned      (assigned_to),
    KEY idx_task_status   (is_task, task_status),
    KEY idx_activity_date (activity_date),
    CONSTRAINT fk_act_type   FOREIGN KEY (activity_type_id) REFERENCES activity_types (id),
    CONSTRAINT fk_act_user   FOREIGN KEY (user_id)          REFERENCES users (id),
    CONSTRAINT fk_act_assign FOREIGN KEY (assigned_to)      REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------
-- 5. Page registration for the new admin config page
--    (CRUD on activity_types, gated to Super Admin only initially)
-- ----------------------------------------------------------------
INSERT IGNORE INTO pages (code, label, section, description, sort_order)
VALUES (
    'config_activity_types',
    'Activity Types',
    'admin',
    'Manage the list of Activity & Task types (Email, Phone Call, Meeting, etc.) rendered on every entity timeline.',
    250
);

-- Grant Super Admin full CRUD on the new page
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT r.id, p.id, 1, 1, 1, 1
FROM roles r
JOIN pages p ON p.code = 'config_activity_types'
WHERE r.slug = 'super_admin';


COMMIT;

SET FOREIGN_KEY_CHECKS = 1;

-- ================================================================
--  ROLLBACK (if anything goes wrong before code is deployed):
--    DROP TABLE activities;
--    DROP TABLE activity_types;
--    ALTER TABLE persons DROP COLUMN person_type;
--    RENAME TABLE persons TO caregivers;
--    DELETE FROM pages WHERE code = 'config_activity_types';
-- ================================================================
