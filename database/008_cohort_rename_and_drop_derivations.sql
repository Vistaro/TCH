-- ================================================================
--  008_cohort_rename_and_drop_derivations.sql
--
--  1. Rename tranche → cohort (column + data values + UI labels)
--  2. Drop stored derivations per single-source-of-truth rule:
--     - persons.total_billed (derivation of caregiver_costs)
--     - persons.standard_daily_rate (derivation of rate_history)
--     - margin_summary table (entirely derived from client_revenue
--       + caregiver_costs)
-- ================================================================

SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

-- ── 1. Rename tranche → cohort ──────────────────────────────
ALTER TABLE persons CHANGE COLUMN tranche cohort VARCHAR(30) DEFAULT NULL;
UPDATE persons SET cohort = REPLACE(cohort, 'Tranche', 'Cohort') WHERE cohort LIKE 'Tranche%';
UPDATE persons SET cohort = 'N/K' WHERE cohort = 'N/K';

-- Also update name_lookup which has a tranche column
ALTER TABLE name_lookup CHANGE COLUMN tranche cohort VARCHAR(30) DEFAULT NULL;
UPDATE name_lookup SET cohort = REPLACE(cohort, 'Tranche', 'Cohort') WHERE cohort LIKE 'Tranche%';

-- ── 2. Drop persons.total_billed ────────────────────────────
ALTER TABLE persons DROP COLUMN total_billed;

-- ── 3. Drop persons.standard_daily_rate ─────────────────────
ALTER TABLE persons DROP COLUMN standard_daily_rate;

-- ── 4. Drop margin_summary table ────────────────────────────
DROP TABLE IF EXISTS margin_summary;

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

-- ================================================================
--  ROLLBACK:
--    ALTER TABLE persons ADD COLUMN total_billed DECIMAL(12,2) NOT NULL DEFAULT 0.00;
--    ALTER TABLE persons ADD COLUMN standard_daily_rate DECIMAL(10,2) DEFAULT NULL;
--    -- Recreate margin_summary from backup
--    ALTER TABLE persons CHANGE COLUMN cohort tranche VARCHAR(30) DEFAULT NULL;
--    UPDATE persons SET tranche = REPLACE(tranche, 'Cohort', 'Tranche') WHERE tranche LIKE 'Cohort%';
--    ALTER TABLE name_lookup CHANGE COLUMN cohort tranche VARCHAR(30) DEFAULT NULL;
--    UPDATE name_lookup SET cohort = REPLACE(cohort, 'Cohort', 'Tranche') WHERE cohort LIKE 'Tranche%';
-- ================================================================
