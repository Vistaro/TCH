-- TCH Placements — Phase 1 Schema
-- Creates the full data layer for caregiver placement operations.
--
-- Run against a fresh database:
--   mysql -u root -p tch_placements < database/001_schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- CLIENTS
-- Master client list with auto-generated account numbers.
-- ============================================================
CREATE TABLE IF NOT EXISTS clients (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_number  VARCHAR(12) NOT NULL UNIQUE COMMENT 'e.g. TCH-C0001',
    client_name     VARCHAR(150) NOT NULL,
    patient_name    VARCHAR(150) DEFAULT NULL,
    day_rate        DECIMAL(10,2) DEFAULT NULL COMMENT 'Standard day rate (ZAR)',
    billing_freq    VARCHAR(30) DEFAULT NULL COMMENT 'Monthly / Weekly',
    shift_type      VARCHAR(30) DEFAULT NULL COMMENT 'Day Shift / Live-In',
    schedule        VARCHAR(50) DEFAULT NULL COMMENT 'Mon-Fri / Full Time etc.',
    entity          VARCHAR(10) DEFAULT NULL COMMENT 'NPC or TCH',
    first_seen      DATE DEFAULT NULL,
    last_seen       DATE DEFAULT NULL,
    months_active   TINYINT UNSIGNED DEFAULT 0,
    status          ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_client_status (status),
    INDEX idx_client_name (client_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CAREGIVERS
-- Full caregiver profiles including personal, training, and status.
-- ============================================================
CREATE TABLE IF NOT EXISTS caregivers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(200) NOT NULL COMMENT 'Canonical name — system-wide identifier',
    student_id      VARCHAR(20) DEFAULT NULL,
    known_as        VARCHAR(100) DEFAULT NULL,
    tranche         VARCHAR(30) DEFAULT NULL COMMENT 'e.g. 1st Intake, Tranche 1',
    source          VARCHAR(50) DEFAULT NULL COMMENT 'e.g. Tuniti',
    gender          ENUM('Male','Female','Other') DEFAULT NULL,
    dob             DATE DEFAULT NULL,
    nationality     VARCHAR(60) DEFAULT NULL,
    id_passport     VARCHAR(50) DEFAULT NULL,
    home_language   VARCHAR(50) DEFAULT NULL,
    other_language  VARCHAR(100) DEFAULT NULL,
    mobile          VARCHAR(30) DEFAULT NULL,
    email           VARCHAR(150) DEFAULT NULL,
    street_address  VARCHAR(200) DEFAULT NULL,
    suburb          VARCHAR(100) DEFAULT NULL,
    city            VARCHAR(100) DEFAULT NULL,
    province        VARCHAR(50) DEFAULT NULL,
    postal_code     VARCHAR(10) DEFAULT NULL,
    nok_name        VARCHAR(150) DEFAULT NULL COMMENT 'Next of kin',
    nok_relationship VARCHAR(50) DEFAULT NULL,
    nok_contact     VARCHAR(30) DEFAULT NULL,
    course_start    DATE DEFAULT NULL,
    available_from  DATE DEFAULT NULL,
    avg_score       DECIMAL(5,4) DEFAULT NULL COMMENT 'Training assessment average (0-1)',
    practical_status VARCHAR(30) DEFAULT NULL COMMENT 'Completed / In Progress / etc.',
    qualified       VARCHAR(50) DEFAULT NULL COMMENT 'Completed 288 Hours / Yes / No',
    standard_daily_rate DECIMAL(10,2) DEFAULT NULL COMMENT 'Current standard rate for billing comparison',
    total_billed    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status          ENUM('In Training','Available','Placed','Inactive') NOT NULL DEFAULT 'In Training',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cg_status (status),
    INDEX idx_cg_name (full_name),
    INDEX idx_cg_tranche (tranche)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CAREGIVER BANKING DETAILS
-- Sensitive — restrict access in Phase 2 (finance role only).
-- ============================================================
CREATE TABLE IF NOT EXISTS caregiver_banking (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    caregiver_id    INT UNSIGNED NOT NULL,
    bank_name       VARCHAR(80) NOT NULL,
    account_number  VARCHAR(30) NOT NULL,
    account_type    VARCHAR(30) NOT NULL COMMENT 'Savings / Current',
    rate_note       VARCHAR(50) DEFAULT NULL COMMENT 'Daily Rate / Set Rate etc.',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (caregiver_id) REFERENCES caregivers(id) ON DELETE CASCADE,
    INDEX idx_bank_cg (caregiver_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- NAME LOOKUP / RECONCILIATION
-- Maps name variants to a canonical caregiver identity.
-- Nothing becomes active without human approval.
-- ============================================================
CREATE TABLE IF NOT EXISTS name_lookup (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    caregiver_id        INT UNSIGNED DEFAULT NULL COMMENT 'FK once approved and linked',
    canonical_name      VARCHAR(200) NOT NULL COMMENT 'System-wide display name',
    pdf_name            VARCHAR(200) DEFAULT NULL COMMENT 'Legal / ID document name',
    training_name       VARCHAR(200) DEFAULT NULL COMMENT 'Intake sheet name',
    billing_name        VARCHAR(200) DEFAULT NULL COMMENT 'Payroll name',
    tranche             VARCHAR(30) DEFAULT NULL,
    source              VARCHAR(50) DEFAULT NULL,
    pdf_match_score     DECIMAL(5,2) DEFAULT NULL COMMENT 'Fuzzy match score 0-100',
    billing_match_score DECIMAL(5,2) DEFAULT NULL COMMENT 'Fuzzy match score 0-100',
    approved            TINYINT(1) NOT NULL DEFAULT 0,
    approved_by         VARCHAR(100) DEFAULT NULL,
    approved_at         TIMESTAMP NULL DEFAULT NULL,
    notes               TEXT DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (caregiver_id) REFERENCES caregivers(id) ON DELETE SET NULL,
    INDEX idx_nl_canonical (canonical_name),
    INDEX idx_nl_billing (billing_name),
    INDEX idx_nl_approved (approved)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CLIENT REVENUE
-- Monthly income/expense/margin per client with source traceability.
-- ============================================================
CREATE TABLE IF NOT EXISTS client_revenue (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED DEFAULT NULL,
    client_name     VARCHAR(150) NOT NULL COMMENT 'Original name from source data',
    month           VARCHAR(10) NOT NULL COMMENT 'e.g. Nov 2025',
    month_date      DATE DEFAULT NULL COMMENT 'First of month for sorting/filtering',
    income          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    expense         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    margin          DECIMAL(12,2) DEFAULT NULL,
    margin_pct      DECIMAL(5,2) DEFAULT NULL,
    source_sheet    VARCHAR(50) DEFAULT NULL COMMENT 'Original Excel tab name',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    INDEX idx_cr_client (client_id),
    INDEX idx_cr_month (month_date),
    INDEX idx_cr_name (client_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CAREGIVER COSTS
-- Monthly pay per caregiver with source traceability.
-- ============================================================
CREATE TABLE IF NOT EXISTS caregiver_costs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    caregiver_id    INT UNSIGNED DEFAULT NULL,
    caregiver_name  VARCHAR(200) NOT NULL COMMENT 'Original billing name from source',
    month           VARCHAR(10) NOT NULL COMMENT 'e.g. Nov 2025',
    month_date      DATE DEFAULT NULL COMMENT 'First of month for sorting/filtering',
    amount          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    days_worked     SMALLINT UNSIGNED DEFAULT NULL,
    daily_rate      DECIMAL(10,2) DEFAULT NULL,
    source_sheet    VARCHAR(50) DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (caregiver_id) REFERENCES caregivers(id) ON DELETE SET NULL,
    INDEX idx_cc_cg (caregiver_id),
    INDEX idx_cc_month (month_date),
    INDEX idx_cc_name (caregiver_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DAILY ROSTER
-- Individual shift records: who worked where, at what rate.
-- ============================================================
CREATE TABLE IF NOT EXISTS daily_roster (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    caregiver_id    INT UNSIGNED DEFAULT NULL,
    client_id       INT UNSIGNED DEFAULT NULL,
    roster_date     DATE NOT NULL,
    day_of_week     VARCHAR(10) NOT NULL,
    caregiver_name  VARCHAR(200) NOT NULL COMMENT 'Original name from source',
    client_assigned VARCHAR(150) NOT NULL COMMENT 'Original client name from source',
    daily_rate      DECIMAL(10,2) DEFAULT NULL,
    source_sheet    VARCHAR(50) DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (caregiver_id) REFERENCES caregivers(id) ON DELETE SET NULL,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    INDEX idx_dr_date (roster_date),
    INDEX idx_dr_cg (caregiver_id),
    INDEX idx_dr_client (client_id),
    INDEX idx_dr_cg_name (caregiver_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CAREGIVER RATE HISTORY
-- Tracks rate changes over time for billing comparison.
-- ============================================================
CREATE TABLE IF NOT EXISTS caregiver_rate_history (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    caregiver_id    INT UNSIGNED NOT NULL,
    daily_rate      DECIMAL(10,2) NOT NULL,
    effective_from  DATE NOT NULL,
    effective_to    DATE DEFAULT NULL COMMENT 'NULL = current rate',
    source          VARCHAR(100) DEFAULT NULL COMMENT 'How this rate was determined',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (caregiver_id) REFERENCES caregivers(id) ON DELETE CASCADE,
    INDEX idx_rh_cg (caregiver_id),
    INDEX idx_rh_dates (effective_from, effective_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- AUDIT TRAIL
-- Preserves the provenance link from summary figures back to
-- their raw source location in TCH_Payroll_Analysis_v5.xlsx.
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_trail (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    record_type     ENUM('client_revenue','caregiver_cost') NOT NULL,
    record_id       INT UNSIGNED NOT NULL COMMENT 'FK to client_revenue.id or caregiver_costs.id',
    summary_sheet   VARCHAR(50) NOT NULL COMMENT 'e.g. Client Summary',
    summary_cell    VARCHAR(10) NOT NULL COMMENT 'e.g. B4',
    source_sheet    VARCHAR(50) NOT NULL COMMENT 'Raw data sheet name',
    source_location TEXT NOT NULL COMMENT 'Full comment text with block/row/col references',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_at_record (record_type, record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- MARGIN SUMMARY
-- Consolidated P&L view per month.
-- ============================================================
CREATE TABLE IF NOT EXISTS margin_summary (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    month           VARCHAR(10) NOT NULL,
    month_date      DATE DEFAULT NULL,
    total_revenue   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_cost      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    gross_margin    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    gross_margin_pct DECIMAL(5,2) DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_month (month_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
