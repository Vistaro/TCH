-- ================================================================
--  013_persons_country.sql
--  Add explicit country column to persons. Default 'South Africa'
--  for backfill (every existing record is SA).
-- ================================================================

START TRANSACTION;

ALTER TABLE persons
    ADD COLUMN country VARCHAR(60) NOT NULL DEFAULT 'South Africa' AFTER postal_code;

-- Belt-and-braces: ensure no NULLs even though the column is NOT NULL DEFAULT.
UPDATE persons SET country = 'South Africa' WHERE country IS NULL OR country = '';

COMMIT;
