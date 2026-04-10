-- TCH Placements — Migration 003a (completion patch)
--
-- Migration 003 partially applied on 2026-04-10 — it failed at the tch_id
-- generated-column step because MariaDB 10.6 forbids generated columns that
-- reference an AUTO_INCREMENT source. This patch:
--
--   1. Adds tch_id as a regular VARCHAR column (no GENERATED clause)
--   2. Backfills it for the 140 existing caregivers
--   3. Runs the source-preservation UPDATE that hadn't run yet
--   4. Drops the legacy source column
--
-- Application code is now responsible for setting tch_id at INSERT time
-- (immediately after LAST_INSERT_ID is known). All 140 existing caregivers
-- already have Tuniti source values which the preservation step copies into
-- import_notes before the column is dropped.

SET NAMES utf8mb4;

-- 1. tch_id column
ALTER TABLE caregivers ADD COLUMN tch_id VARCHAR(20) DEFAULT NULL AFTER id;
ALTER TABLE caregivers ADD UNIQUE INDEX uk_cg_tch_id (tch_id);

-- 2. Backfill tch_id from id for the existing rows
UPDATE caregivers
SET tch_id = CONCAT('TCH-', LPAD(id, 6, '0'))
WHERE tch_id IS NULL;

-- 3. Preserve source values into import_notes before drop
UPDATE caregivers
SET import_notes = CONCAT_WS('\n\n',
        NULLIF(import_notes, ''),
        CONCAT('Legacy `source` value preserved from migration 003: ', source))
WHERE source IS NOT NULL AND source != '';

-- 4. Drop the legacy source column
ALTER TABLE caregivers DROP COLUMN source;
