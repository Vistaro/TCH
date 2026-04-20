-- ─────────────────────────────────────────────────────────────
--  047_caregiver_loans_table.sql
--
--  Phase 6.4 — caregiver loan ledger. Page was registered in
--  migration 010 but the table was never created. This migration
--  builds the table + registers the create/detail sub-pages.
--
--  Model (event-sourced):
--    caregiver_loans — one row per loan event. Two kinds of event:
--      'advance'   — a loan paid out to the caregiver (positive amount)
--      'repayment' — money deducted from caregiver pay (positive amount,
--                    different event_type)
--    Running balance per caregiver = SUM(advance amounts) − SUM(repayment amounts).
--    Oldest-advance-first allocation is a read-side computation, not
--    stored — keeps the ledger simple and auditable.
--
--  Per the proposal Phase 6 scope:
--    "Caregiver loan ledger — advances recorded, repayments tracked
--     against the oldest advance first, running balance visible on
--     caregiver profile."
--
--  Release-gating: page registered with super_admin grants only
--  (admin role does NOT get this yet — Tuniti sees balances once Ross
--  greenlights, via a release-log entry).
--
--  Rollback: DROP TABLE caregiver_loans;
-- ─────────────────────────────────────────────────────────────

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS caregiver_loans (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    caregiver_id           INT UNSIGNED NOT NULL,
    event_type             ENUM('advance','repayment') NOT NULL
        COMMENT 'advance = money out to caregiver; repayment = money deducted from pay',
    amount_cents           INT UNSIGNED NOT NULL
        COMMENT 'Always positive. event_type determines direction.',
    event_date             DATE NOT NULL
        COMMENT 'When the advance was disbursed or the repayment was deducted',
    reason                 VARCHAR(255) DEFAULT NULL
        COMMENT 'Free-text context — "emergency cash", "month X payroll deduction", etc.',
    payroll_run_month      CHAR(7) DEFAULT NULL
        COMMENT 'YYYY-MM — populated on repayments when tied to a specific payroll run. NULL for advances.',
    notes                  TEXT DEFAULT NULL,
    created_by_user_id     INT UNSIGNED DEFAULT NULL,
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (caregiver_id)       REFERENCES caregivers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)      ON DELETE SET NULL,
    INDEX idx_loans_caregiver_date (caregiver_id, event_date),
    INDEX idx_loans_event_type     (event_type),
    INDEX idx_loans_payroll_month  (payroll_run_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure super_admin has the loans page. Migration 010 used role_id=1
-- literally which is fragile if role IDs ever shift — re-INSERT via slug
-- to be safe.
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT r.id, p.id, 1, 1, 1, 1
  FROM roles r
  JOIN pages p ON p.code = 'caregiver_loans'
 WHERE r.slug = 'super_admin';

-- admin role does NOT get this page yet (FR-R release-gating).
