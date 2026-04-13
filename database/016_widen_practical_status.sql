-- ================================================================
--  016_widen_practical_status.sql
--  Tuniti facility names (e.g. 'Lonehill Manor Retirement Estate')
--  exceed the original varchar(30) limit. Widen to 100 to preserve
--  the full facility name imported from the attendance spreadsheet.
-- ================================================================

START TRANSACTION;
ALTER TABLE students MODIFY COLUMN practical_status VARCHAR(100) DEFAULT NULL;
COMMIT;
