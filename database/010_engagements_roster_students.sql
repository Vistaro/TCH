-- ================================================================
--  010_engagements_roster_students.sql
--  Phase 4+5+6 schema: engagements, roster dual rates, student
--  enrollment tracking.
-- ================================================================

SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

-- ── 1. Caregiver-product qualifications ─────────────────────
CREATE TABLE IF NOT EXISTS caregiver_products (
    caregiver_person_id INT UNSIGNED NOT NULL,
    product_id          INT UNSIGNED NOT NULL,
    qualified_at        DATE DEFAULT NULL,
    notes               TEXT DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (caregiver_person_id, product_id),
    FOREIGN KEY (caregiver_person_id) REFERENCES caregivers(person_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: all current caregivers are qualified for Day Rate (product 1)
INSERT INTO caregiver_products (caregiver_person_id, product_id, qualified_at, notes)
SELECT person_id, 1, CURRENT_DATE, 'Auto-seeded: all existing caregivers assumed Day Rate qualified'
FROM caregivers;


-- ── 2. Patient-product needs ────────────────────────────────
CREATE TABLE IF NOT EXISTS patient_products (
    patient_person_id   INT UNSIGNED NOT NULL,
    product_id          INT UNSIGNED NOT NULL,
    notes               TEXT DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (patient_person_id, product_id),
    FOREIGN KEY (patient_person_id) REFERENCES patients(person_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: all current patients need Day Rate
INSERT INTO patient_products (patient_person_id, product_id, notes)
SELECT person_id, 1, 'Auto-seeded: all existing patients assumed Day Rate'
FROM patients;


-- ── 3. Engagements — the contract ───────────────────────────
CREATE TABLE IF NOT EXISTS engagements (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    caregiver_person_id INT UNSIGNED NOT NULL,
    patient_person_id   INT UNSIGNED NOT NULL,
    client_id           INT UNSIGNED NOT NULL,
    product_id          INT UNSIGNED NOT NULL,
    cost_rate           DECIMAL(10,2) NOT NULL COMMENT 'What we pay the caregiver per unit',
    bill_rate           DECIMAL(10,2) NOT NULL COMMENT 'What we charge the client per unit',
    start_date          DATE NOT NULL,
    end_date            DATE DEFAULT NULL COMMENT 'NULL = open-ended / ongoing',
    status              ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
    notes               TEXT DEFAULT NULL,
    created_by_user_id  INT UNSIGNED DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (caregiver_person_id) REFERENCES caregivers(person_id),
    FOREIGN KEY (patient_person_id) REFERENCES patients(person_id),
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_eng_cg (caregiver_person_id),
    INDEX idx_eng_patient (patient_person_id),
    INDEX idx_eng_client (client_id),
    INDEX idx_eng_status (status),
    INDEX idx_eng_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 4. Add dual rates + status + engagement link to daily_roster
ALTER TABLE daily_roster
    ADD COLUMN engagement_id    INT UNSIGNED DEFAULT NULL COMMENT 'Contract this shift was delivered under',
    ADD COLUMN product_id       INT UNSIGNED DEFAULT NULL COMMENT 'What product was delivered',
    ADD COLUMN cost_rate        DECIMAL(10,2) DEFAULT NULL COMMENT 'What we paid the caregiver for this shift',
    ADD COLUMN bill_rate        DECIMAL(10,2) DEFAULT NULL COMMENT 'What we charged the client for this shift',
    ADD COLUMN status           ENUM('planned','delivered','cancelled','disputed') NOT NULL DEFAULT 'delivered' COMMENT 'Existing rows are all delivered historical shifts',
    ADD COLUMN shift_start      DATETIME DEFAULT NULL,
    ADD COLUMN shift_end        DATETIME DEFAULT NULL,
    ADD COLUMN created_by_user_id INT UNSIGNED DEFAULT NULL,
    ADD COLUMN confirmed_by_user_id INT UNSIGNED DEFAULT NULL,
    ADD COLUMN confirmed_at     TIMESTAMP NULL DEFAULT NULL;

-- Backfill: existing roster rows get cost_rate = daily_rate, product = Day Rate (id 1)
UPDATE daily_roster SET cost_rate = daily_rate, product_id = 1 WHERE daily_rate IS NOT NULL;

-- Add FK constraints for new columns
ALTER TABLE daily_roster
    ADD CONSTRAINT fk_roster_engagement FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_roster_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_roster_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_roster_confirmed_by FOREIGN KEY (confirmed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD INDEX idx_roster_engagement (engagement_id),
    ADD INDEX idx_roster_status (status),
    ADD INDEX idx_roster_product (product_id);


-- ── 5. Student enrollment tracking (Phase 6 foundation) ─────
CREATE TABLE IF NOT EXISTS student_enrollments (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_person_id   INT UNSIGNED NOT NULL,
    cohort              VARCHAR(30) NOT NULL,
    enrolled_at         DATE NOT NULL,
    graduated_at        DATE DEFAULT NULL,
    dropped_at          DATE DEFAULT NULL,
    status              ENUM('enrolled','in_training','ojt','qualified','graduated','dropped') NOT NULL DEFAULT 'enrolled',
    notes               TEXT DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_person_id) REFERENCES students(person_id) ON DELETE CASCADE,
    INDEX idx_enroll_student (student_person_id),
    INDEX idx_enroll_cohort (cohort),
    INDEX idx_enroll_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed enrollments from existing student data
INSERT INTO student_enrollments (student_person_id, cohort, enrolled_at, status)
SELECT person_id, cohort, COALESCE(course_start, CURRENT_DATE),
    CASE
        WHEN qualified LIKE '%Yes%' OR qualified LIKE '%Completed%' THEN 'graduated'
        WHEN practical_status LIKE '%Completed%' THEN 'qualified'
        WHEN practical_status IS NOT NULL THEN 'ojt'
        ELSE 'in_training'
    END
FROM students
WHERE cohort IS NOT NULL AND cohort != '' AND cohort != 'N/K';


-- ── 6. Training attendance (Phase 6) ────────────────────────
CREATE TABLE IF NOT EXISTS training_attendance (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_person_id   INT UNSIGNED NOT NULL,
    enrollment_id       INT UNSIGNED NOT NULL,
    attendance_date     DATE NOT NULL,
    attendance_type     ENUM('classroom','practical','ojt') NOT NULL DEFAULT 'classroom',
    hours               DECIMAL(4,1) DEFAULT NULL,
    notes               TEXT DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_person_id) REFERENCES students(person_id) ON DELETE CASCADE,
    FOREIGN KEY (enrollment_id) REFERENCES student_enrollments(id) ON DELETE CASCADE,
    INDEX idx_ta_student (student_person_id),
    INDEX idx_ta_date (attendance_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 7. Course/module scores (Phase 6) ───────────────────────
CREATE TABLE IF NOT EXISTS student_scores (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_person_id   INT UNSIGNED NOT NULL,
    enrollment_id       INT UNSIGNED NOT NULL,
    module_name         VARCHAR(100) NOT NULL,
    score               DECIMAL(5,2) DEFAULT NULL COMMENT 'Percentage 0-100',
    assessed_at         DATE DEFAULT NULL,
    assessor            VARCHAR(100) DEFAULT NULL,
    notes               TEXT DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_person_id) REFERENCES students(person_id) ON DELETE CASCADE,
    FOREIGN KEY (enrollment_id) REFERENCES student_enrollments(id) ON DELETE CASCADE,
    INDEX idx_ss_student (student_person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 8. Patient expenses (non-shift costs like Uber) ─────────
CREATE TABLE IF NOT EXISTS patient_expenses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_person_id INT UNSIGNED NOT NULL,
    client_id       INT UNSIGNED NOT NULL,
    expense_date    DATE NOT NULL,
    description     VARCHAR(255) NOT NULL,
    amount          DECIMAL(10,2) NOT NULL,
    is_recharged    TINYINT(1) DEFAULT NULL COMMENT 'NULL=unknown, 1=recharged to client, 0=absorbed by TCH',
    source_ref      VARCHAR(100) DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_person_id) REFERENCES patients(person_id),
    FOREIGN KEY (client_id) REFERENCES clients(id),
    INDEX idx_pe_patient (patient_person_id),
    INDEX idx_pe_date (expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 9. Register new admin pages ─────────────────────────────
INSERT IGNORE INTO pages (code, label, section, description, sort_order) VALUES
    ('engagements', 'Engagements', 'records', 'Manage caregiver-patient care contracts', 45),
    ('roster_input', 'Roster Input', 'records', 'Record and confirm daily shifts', 42),
    ('student_tracking', 'Student Tracking', 'records', 'Enrollment, attendance, scores, graduation', 15),
    ('patient_expenses', 'Patient Expenses', 'records', 'Non-shift costs (Uber, equipment etc.)', 55),
    ('caregiver_loans', 'Caregiver Loans', 'records', 'Loans and repayments to caregivers', 56);

INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT 1, p.id, 1, 1, 1, 1
FROM pages p
WHERE p.code IN ('engagements','roster_input','student_tracking','patient_expenses','caregiver_loans')
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = 1 AND rp.page_id = p.id);


COMMIT;
SET FOREIGN_KEY_CHECKS = 1;
