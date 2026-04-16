-- Migration 038 — quote-workflow schema prep (FR-C, FR-D) + contract_line
-- billing_freq widening (FR-A follow-up).
--
-- This migration prepares the contracts table to act as both a quote and
-- a contract (same record, status-machine driven per FR-D in
-- docs/TCH_Quote_And_Portal_Plan.md) and widens the contract_lines
-- billing_freq ENUM so 'hourly' is accepted once product_billing_rates
-- starts offering hourly options.
--
-- Three changes bundled:
--   1. contract_lines.billing_freq ENUM widens to add 'hourly'. Migration
--      034 already widened products.default_billing_freq; this closes the
--      matching gap on the child table. Pure widening, no row data coerced.
--   2. contracts.status ENUM widens to add 'sent','accepted','rejected',
--      'expired'. Existing values (draft, active, on_hold, cancelled,
--      completed) are preserved. The new values flesh out the quote state
--      machine: draft -> sent -> accepted -> active for the happy path.
--   3. contracts gains five columns for the quote-as-record shape:
--        - quote_reference   VARCHAR(30) UNIQUE NULL — external id
--          (e.g. "Q-2026-0001"); nullable because not every contract
--          starts life as a quote.
--        - sent_at           TIMESTAMP NULL — set when status goes draft->sent
--        - accepted_at       TIMESTAMP NULL — set when status goes sent->accepted
--        - acceptance_method ENUM('email','phone','in_person','portal','signed_pdf') NULL
--          — how the client said yes
--        - acceptance_note   TEXT — optional free text about the acceptance
--
-- Safe forward on InnoDB: all three changes are metadata-only (ENUM
-- widening, nullable column additions).
--
-- Destructive if rolled back:
--   - ENUM-widen rollback fails if any row uses a value added by this
--     migration (i.e. any contract with status IN ('sent','accepted',
--     'rejected','expired'), any contract_line with billing_freq='hourly').
--     Verify no PROD rows use the new values before any rollback.
--   - Dropping the new columns loses any data written to them.

START TRANSACTION;

-- 1. Widen contract_lines.billing_freq to include 'hourly'.
ALTER TABLE contract_lines
    MODIFY COLUMN billing_freq
      ENUM('hourly','monthly','weekly','daily','per_visit','upfront_only')
      NOT NULL DEFAULT 'monthly';

-- 2. Widen contracts.status to the quote state-machine values.
ALTER TABLE contracts
    MODIFY COLUMN status
      ENUM('draft','sent','accepted','rejected','expired',
           'active','on_hold','cancelled','completed')
      NOT NULL DEFAULT 'draft';

-- 3. Quote-as-record columns on contracts.
ALTER TABLE contracts
    ADD COLUMN quote_reference   VARCHAR(30) DEFAULT NULL
        COMMENT 'External reference like Q-2026-0001. NULL for contracts not originated as quotes.'
        AFTER status,
    ADD UNIQUE KEY uq_quote_reference (quote_reference),
    ADD COLUMN sent_at           TIMESTAMP NULL DEFAULT NULL
        COMMENT 'Stamped when the quote transitions draft->sent.'
        AFTER quote_reference,
    ADD COLUMN accepted_at       TIMESTAMP NULL DEFAULT NULL
        COMMENT 'Stamped when the quote transitions sent->accepted.'
        AFTER sent_at,
    ADD COLUMN acceptance_method
        ENUM('email','phone','in_person','portal','signed_pdf') DEFAULT NULL
        COMMENT 'How the client indicated acceptance. NULL until accepted.'
        AFTER accepted_at,
    ADD COLUMN acceptance_note   TEXT DEFAULT NULL
        COMMENT 'Optional free text — the admin note on how/why acceptance happened.'
        AFTER acceptance_method;

COMMIT;
