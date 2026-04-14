-- ─────────────────────────────────────────────────────────────
--  031_contracts.sql
--  First-class contract model.
--
--  Today `engagements` conflates two ideas: the commercial contract
--  (client pays TCH for care to their patient at X rate) and the
--  operational assignment (caregiver Y delivers that care on date Z).
--  This migration separates them.
--
--    contracts         — the commercial entity (drives billing)
--                        one contract per patient, multiple lines
--    contract_lines    — product × rate × billing_freq within a contract
--    engagements       — caregiver assigned to fulfil a contract
--                        (now contract-scoped, was patient-scoped)
--    daily_roster      — per-shift delivery, gains contract_id
--    products          — gains default_billing_freq + default_min_term
--    caregivers        — gains working_pattern (default: 7 days)
--
--  Scheduling / caregiver availability lives in a subsequent migration
--  once Tuniti's contracts are ingested.
-- ─────────────────────────────────────────────────────────────

-- ── 1. Products gain billing frequency + minimum term
ALTER TABLE products
    ADD COLUMN default_billing_freq ENUM('monthly','weekly','daily','per_visit','upfront_only')
        NOT NULL DEFAULT 'monthly'
        COMMENT 'Default invoicing cadence when a product is added to a contract'
        AFTER sort_order,
    ADD COLUMN default_min_term_months TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Minimum commitment in months — 0 = no minimum. Warned at cancel, not enforced'
        AFTER default_billing_freq;

-- ── 2. Caregivers gain a working pattern
ALTER TABLE caregivers
    ADD COLUMN working_pattern VARCHAR(20) NOT NULL DEFAULT 'MON-SUN'
        COMMENT '7-char pattern of active days (Y/N) starting Monday, OR a short code like MON-FRI, MON-SUN, 4ON-3OFF. Simple MVP — refine later.';

-- ── 3. Contracts — the commercial entity
CREATE TABLE IF NOT EXISTS contracts (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id             INT UNSIGNED NOT NULL
        COMMENT 'Bill-payer — who the invoice goes to',
    patient_person_id     INT UNSIGNED NOT NULL
        COMMENT 'Care recipient',
    status                ENUM('draft','active','on_hold','cancelled','completed')
        NOT NULL DEFAULT 'draft',
    start_date            DATE NOT NULL,
    end_date              DATE DEFAULT NULL
        COMMENT 'NULL = ongoing until actively cancelled. Auto-renews monthly unless flagged.',
    auto_renew            TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Ongoing contracts auto-renew at each billing cycle. Flag for attention N days pre-renewal, never auto-cancels.',
    invoice_number        VARCHAR(60) DEFAULT NULL
        COMMENT 'Manual invoice reference (from Xero or external) — entered by business team',
    invoice_status        ENUM('none','raised','sent','paid','overdue','disputed')
        NOT NULL DEFAULT 'none',
    invoice_amount        DECIMAL(12,2) DEFAULT NULL,
    invoice_date          DATE DEFAULT NULL,
    superseded_by         INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to contracts.id — mid-contract product switch creates a successor, old one status=cancelled and superseded_by set',
    notes                 TEXT DEFAULT NULL,
    created_by_user_id    INT UNSIGNED DEFAULT NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    cancelled_at          TIMESTAMP NULL DEFAULT NULL,
    cancelled_by_user_id  INT UNSIGNED DEFAULT NULL,
    cancellation_reason   VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (client_id)            REFERENCES clients(id),
    FOREIGN KEY (patient_person_id)    REFERENCES persons(id),
    FOREIGN KEY (superseded_by)        REFERENCES contracts(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by_user_id)   REFERENCES users(id)     ON DELETE SET NULL,
    FOREIGN KEY (cancelled_by_user_id) REFERENCES users(id)     ON DELETE SET NULL,
    INDEX idx_contract_client   (client_id),
    INDEX idx_contract_patient  (patient_person_id),
    INDEX idx_contract_status   (status),
    INDEX idx_contract_dates    (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Contract lines — products within a contract
CREATE TABLE IF NOT EXISTS contract_lines (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id        INT UNSIGNED NOT NULL,
    product_id         INT UNSIGNED NOT NULL,
    billing_freq       ENUM('monthly','weekly','daily','per_visit','upfront_only')
        NOT NULL DEFAULT 'monthly',
    min_term_months    TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Override of product.default_min_term_months for this contract line',
    bill_rate          DECIMAL(10,2) NOT NULL
        COMMENT 'Rate per unit charged to client for this line',
    units_per_period   DECIMAL(6,2) NOT NULL DEFAULT 1.00
        COMMENT 'e.g. 5 day-units per week for weekday-only product',
    notes              VARCHAR(255) DEFAULT NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id)  REFERENCES products(id),
    INDEX idx_line_contract (contract_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. daily_roster gains contract_id (optional — ingested rows are NULL until
--      Tuniti creates the matching contracts)
ALTER TABLE daily_roster
    ADD COLUMN contract_id INT UNSIGNED DEFAULT NULL
        COMMENT 'Contract that this shift is delivering under. Optional — historic rows NULL until retroactively linked.'
        AFTER engagement_id,
    ADD INDEX idx_roster_contract (contract_id),
    ADD CONSTRAINT fk_roster_contract
        FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL;

-- ── 6. Register the admin page
INSERT INTO pages (code, label, section, description, sort_order)
VALUES ('contracts', 'Contracts',
        'records',
        'Commercial contract with a client for care to a specific patient. Drives invoicing and scheduling.',
        35)
ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT r.id, p.id, 1, 1, 1, 1
FROM roles r JOIN pages p ON p.code = 'contracts'
WHERE r.slug = 'super_admin';
