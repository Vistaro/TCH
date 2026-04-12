-- ================================================================
--  009_table_decomposition.sql
--  Split persons into identity + role tables.
--
--  Approach: ADDITIVE. No existing FKs are repointed. The role
--  tables are extension tables that JOIN to persons via person_id.
--  Existing code keeps working — new code JOINs through the role
--  tables to access role-specific fields. The role-specific columns
--  are then dropped from persons in a follow-up once all code paths
--  have been migrated.
--
--  Tables created:
--    students   — Tuniti training pipeline
--    caregivers — TCH placement role
--    clients    — billing entity (can be a company, person_id nullable)
--    patients   — care recipient, linked to a client
--    products   — what TCH sells
-- ================================================================

SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

-- ── 1. Students ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS students (
    person_id           INT UNSIGNED NOT NULL,
    student_id          VARCHAR(20) DEFAULT NULL,
    cohort              VARCHAR(30) DEFAULT NULL,
    lead_source_id      INT UNSIGNED DEFAULT NULL,
    referred_by_name    VARCHAR(150) DEFAULT NULL,
    referred_by_contact VARCHAR(50) DEFAULT NULL,
    course_start        DATE DEFAULT NULL,
    available_from      DATE DEFAULT NULL,
    avg_score           DECIMAL(5,4) DEFAULT NULL,
    practical_status    VARCHAR(30) DEFAULT NULL,
    qualified           VARCHAR(50) DEFAULT NULL,
    status_id           INT UNSIGNED DEFAULT NULL,
    import_review_state ENUM('pending','approved','rejected') DEFAULT NULL,
    import_notes        TEXT DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (person_id),
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (lead_source_id) REFERENCES lead_sources(id) ON DELETE SET NULL,
    FOREIGN KEY (status_id) REFERENCES person_statuses(id) ON DELETE SET NULL,
    INDEX idx_stu_cohort (cohort),
    INDEX idx_stu_status (status_id),
    INDEX idx_stu_review (import_review_state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Populate from persons where they have student-like data
INSERT INTO students (person_id, student_id, cohort, lead_source_id,
    referred_by_name, referred_by_contact, course_start, available_from,
    avg_score, practical_status, qualified, status_id,
    import_review_state, import_notes)
SELECT id, student_id, cohort, lead_source_id,
    referred_by_name, referred_by_contact, course_start, available_from,
    avg_score, practical_status, qualified, status_id,
    import_review_state, import_notes
FROM persons
WHERE FIND_IN_SET('caregiver', person_type)
  AND (cohort IS NOT NULL OR student_id IS NOT NULL
       OR import_review_state IS NOT NULL);


-- ── 2. Caregivers ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS caregivers (
    person_id       INT UNSIGNED NOT NULL,
    day_rate        DECIMAL(10,2) DEFAULT NULL COMMENT 'Current daily cost rate',
    status          ENUM('available','placed','inactive') NOT NULL DEFAULT 'available',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (person_id),
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Populate: every caregiver-type person gets a row.
-- day_rate comes from the most recent caregiver_rate_history entry.
INSERT INTO caregivers (person_id, day_rate, status)
SELECT p.id,
    (SELECT crh.daily_rate FROM caregiver_rate_history crh
     WHERE crh.caregiver_id = p.id
     ORDER BY crh.effective_from DESC LIMIT 1),
    CASE
        WHEN ps.code = 'placed' THEN 'placed'
        WHEN ps.code = 'inactive' THEN 'inactive'
        ELSE 'available'
    END
FROM persons p
LEFT JOIN person_statuses ps ON ps.id = p.status_id
WHERE FIND_IN_SET('caregiver', p.person_type);


-- ── 3. Clients ──────────────────────────────────────────────
-- Separate auto-increment id because clients can be companies
-- (person_id nullable). For individual clients, person_id links
-- to persons.id.
CREATE TABLE IF NOT EXISTS clients (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id       INT UNSIGNED DEFAULT NULL COMMENT 'NULL for company clients',
    account_number  VARCHAR(12) DEFAULT NULL,
    billing_entity  VARCHAR(10) DEFAULT NULL COMMENT 'NPC or TCH',
    billing_freq    VARCHAR(30) DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE SET NULL,
    UNIQUE KEY uk_client_account (account_number),
    INDEX idx_client_person (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Populate from persons where they have client-type data.
-- Use persons.id as the explicit clients.id to keep FK alignment
-- with client_revenue.client_id and daily_roster.client_id which
-- currently point at persons.id values.
INSERT INTO clients (id, person_id, account_number, billing_entity, billing_freq)
SELECT id, id, account_number, billing_entity, billing_freq
FROM persons
WHERE FIND_IN_SET('client', person_type);


-- ── 4. Patients ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS patients (
    person_id       INT UNSIGNED NOT NULL,
    client_id       INT UNSIGNED NOT NULL COMMENT 'Which client pays for this patient',
    patient_name    VARCHAR(150) DEFAULT NULL COMMENT 'Name of care recipient when different from the person',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (person_id),
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Populate: every patient-type person gets a row.
-- client_id = their own persons.id (because today patient=client
-- for most rows). The clients table was populated with id=persons.id
-- above, so this FK is valid.
INSERT INTO patients (person_id, client_id, patient_name)
SELECT id, id, patient_name
FROM persons
WHERE FIND_IN_SET('patient', person_type);


-- ── 5. Products ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS products (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(30) NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_product_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO products (code, name, description, sort_order) VALUES
    ('day_rate', 'Day Rate', 'Standard day shift care — the only product today. All roster rows are implicitly this product.', 10);


-- ── 6. Register new admin pages ─────────────────────────────
INSERT IGNORE INTO pages (code, label, section, description, sort_order) VALUES
    ('caregivers_list', 'Caregivers', 'records', 'Manage caregiver records', 20),
    ('clients_list', 'Clients', 'records', 'Manage client records', 30),
    ('patients_list', 'Patients', 'records', 'Manage patient records', 35),
    ('products', 'Products', 'admin', 'Manage product catalogue', 260);

-- Grant Super Admin full CRUD
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT 1, p.id, 1, 1, 1, 1
FROM pages p
WHERE p.code IN ('caregivers_list', 'clients_list', 'patients_list', 'products')
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = 1 AND rp.page_id = p.id);


COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

-- ================================================================
--  NOTE: persons table columns are NOT dropped in this migration.
--  The role-specific columns stay on persons temporarily so existing
--  code paths that haven't been updated yet don't break. A follow-up
--  migration (010) will strip persons down to identity-only once all
--  code is reading from the role tables.
--
--  ROLLBACK:
--    DROP TABLE IF EXISTS patients;
--    DROP TABLE IF EXISTS clients;
--    DROP TABLE IF EXISTS caregivers;
--    DROP TABLE IF EXISTS students;
--    DROP TABLE IF EXISTS products;
--    DELETE FROM pages WHERE code IN ('caregivers_list','clients_list','patients_list','products');
-- ================================================================
