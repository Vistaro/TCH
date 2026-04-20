-- ─────────────────────────────────────────────────────────────
--  048_patient_care_needs_and_emergency_contacts.sql
--
--  Phase 4.4 of the Tuniti proposal — comprehensive patient care-
--  needs profile. Previously a "major gap" in the scope table.
--
--  Two new tables:
--
--  1. patient_care_needs — one row per patient, TEXT columns per
--     category. Pragmatic single-row-per-patient structure; normalise
--     into lookup tables later if structured querying becomes needed
--     (allergies-to-drug-X reporting, etc.). For now, free-text per
--     category is enough to capture what Tuniti records today.
--
--  2. patient_emergency_contacts — many-per-patient, with sort_order
--     so the primary contact sits on top.
--
--  Both keyed to patients.person_id. Cascade on patient delete.
--
--  Admin UI lands on the existing patient detail page as new
--  collapsible sections. Release-gated super_admin-only for v1 —
--  admin role picks it up once Ross has reviewed field labels /
--  wording / privacy and greenlights.
--
--  Rollback:
--    DROP TABLE patient_emergency_contacts;
--    DROP TABLE patient_care_needs;
-- ─────────────────────────────────────────────────────────────

SET NAMES utf8mb4;

-- ── 1. patient_care_needs (one row per patient)
CREATE TABLE IF NOT EXISTS patient_care_needs (
    person_id                INT UNSIGNED NOT NULL PRIMARY KEY,

    -- Medical
    medical_conditions       TEXT DEFAULT NULL
        COMMENT 'Free text. Diagnoses + severity. Multiple entries OK on separate lines.',
    allergies                TEXT DEFAULT NULL
        COMMENT 'Drug / food / environmental. List severity if life-threatening.',
    medications              TEXT DEFAULT NULL
        COMMENT 'Current medications + dose + frequency. Does NOT substitute for a script.',
    dnr_status               ENUM('unknown','no_dnr','dnr_in_place') NOT NULL DEFAULT 'unknown'
        COMMENT 'Do Not Resuscitate directive. Displayed prominently when in_place.',
    dnr_notes                VARCHAR(500) DEFAULT NULL,

    -- Physical / functional
    mobility_notes           TEXT DEFAULT NULL
        COMMENT 'Walking aids, wheelchair, bed-bound, fall risk.',
    hygiene_notes            TEXT DEFAULT NULL
        COMMENT 'Continence, bathing assistance, dressing needs.',

    -- Cognitive / emotional
    cognitive_notes          TEXT DEFAULT NULL
        COMMENT 'Dementia stage, confusion, behaviours to expect.',
    emotional_notes          TEXT DEFAULT NULL
        COMMENT 'Anxiety, depression, known triggers, calming strategies.',

    -- Preferences (non-medical)
    dietary_notes            TEXT DEFAULT NULL
        COMMENT 'Dietary restrictions, preferences, allergies already listed above.',
    recreational_notes       TEXT DEFAULT NULL
        COMMENT 'Hobbies, favourite music, TV programmes, routines.',
    language_notes           TEXT DEFAULT NULL
        COMMENT 'Preferred languages for carer interaction (distinct from FR-4.1 caregiver languages).',

    -- Care plan summary
    care_summary             TEXT DEFAULT NULL
        COMMENT 'Top-line summary the quoter / scheduler reads first. Keep concise.',

    -- Metadata
    last_reviewed_date       DATE DEFAULT NULL
        COMMENT 'When the clinical detail was last sense-checked with family / GP.',
    last_reviewed_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id)                REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (last_reviewed_by_user_id) REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. patient_emergency_contacts (many per patient)
CREATE TABLE IF NOT EXISTS patient_emergency_contacts (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id          INT UNSIGNED NOT NULL COMMENT 'FK → persons.id of the PATIENT',
    full_name          VARCHAR(200) NOT NULL,
    relationship       VARCHAR(50)  DEFAULT NULL
        COMMENT 'spouse, son, daughter, GP, neighbour, etc.',
    phone              VARCHAR(30)  DEFAULT NULL,
    alt_phone          VARCHAR(30)  DEFAULT NULL,
    email              VARCHAR(150) DEFAULT NULL,
    is_primary         TINYINT(1)   NOT NULL DEFAULT 0
        COMMENT 'Exactly one primary should exist per patient — first one called in an incident.',
    has_power_of_attorney TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Flag for whoever can make care decisions on the patient''s behalf.',
    notes              VARCHAR(500) DEFAULT NULL,
    sort_order         SMALLINT UNSIGNED NOT NULL DEFAULT 10
        COMMENT 'Lower number = displayed first. Primary contact should be 10.',
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    INDEX idx_ec_person (person_id, sort_order),
    INDEX idx_ec_primary (person_id, is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Register + grant the pages
INSERT INTO pages (code, label, section, description, sort_order) VALUES
  ('patient_care_needs',        'Patient care needs',        'records', 'Clinical + care-preference profile per patient. Edit via patient detail page.', 25),
  ('patient_emergency_contacts','Emergency contacts',        'records', 'Emergency contact list per patient. Edit via patient detail page.', 26)
ON DUPLICATE KEY UPDATE label=VALUES(label), description=VALUES(description), sort_order=VALUES(sort_order);

-- super_admin: full CRUD
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT r.id, p.id, 1, 1, 1, 1
  FROM roles r
  JOIN pages p ON p.code IN ('patient_care_needs','patient_emergency_contacts')
 WHERE r.slug = 'super_admin';

-- admin role: NOT granted yet. Release-gated until Ross reviews
-- field wording + privacy model.
