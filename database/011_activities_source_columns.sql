-- ================================================================
--  011_activities_source_columns.sql
--  Add structured source-citation columns to activities table so
--  system / import-derived rows can carry a queryable provenance
--  (which file, which tab, which cell, which import batch) instead
--  of stuffing it into free-text notes.
--
--  Driven by: Tuniti attendance import (Apr 2026). Every value
--  written to a student record from "Ross Intake 1-9.xlsx" lands as
--  one activity row with:
--    source       = 'import'
--    source_ref   = 'Ross Intake 1-9.xlsx#Cohort 1!N5'
--    source_batch = 'tuniti-attendance-2026-04-13'
--
--  Pattern recommended by Nexus CRM agent (mailbox reply
--  msg-2026-04-13-0829-001) — CRM hasn't built this yet, TCH first.
-- ================================================================

START TRANSACTION;

ALTER TABLE activities
    ADD COLUMN source       VARCHAR(40)  NULL DEFAULT NULL AFTER notes,
    ADD COLUMN source_ref   VARCHAR(255) NULL DEFAULT NULL AFTER source,
    ADD COLUMN source_batch VARCHAR(64)  NULL DEFAULT NULL AFTER source_ref,
    ADD KEY idx_source        (source),
    ADD KEY idx_source_batch  (source_batch);

COMMIT;
