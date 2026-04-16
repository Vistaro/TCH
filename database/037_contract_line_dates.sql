-- Migration 037 — per-line dates on contract_lines (FR-B).
--
-- A contract can bundle multiple products with different runs — e.g.
-- "Day Care from 1 May to 31 July, Errand Care from 1 June ongoing".
-- Previously the only start/end lived on contracts itself, forcing
-- every line to share the contract's window. Per FR-B in
-- docs/TCH_Quote_And_Portal_Plan.md, lines now carry their own dates;
-- end_date NULL = ongoing, same convention as the contracts column.
--
-- Backfill copies each existing line's dates from its parent contract
-- so the new fields are populated for every row at migration time.
-- Downstream queries can then switch over at their own pace.
--
-- Safe forward: adding nullable columns is metadata-only on InnoDB.
-- Backfill writes every existing row once inside a single transaction.
-- Non-destructive to roll back (drop columns, data on contracts is
-- untouched).

START TRANSACTION;

ALTER TABLE contract_lines
    ADD COLUMN start_date DATE DEFAULT NULL
        COMMENT 'Line start. Falls back to the parent contract start if NULL.'
        AFTER units_per_period,
    ADD COLUMN end_date   DATE DEFAULT NULL
        COMMENT 'Line end. NULL = ongoing (same convention as contracts.end_date).'
        AFTER start_date;

-- Backfill from parent contract dates so no line ships with blank dates.
UPDATE contract_lines cl
  JOIN contracts c ON c.id = cl.contract_id
   SET cl.start_date = c.start_date,
       cl.end_date   = c.end_date;

COMMIT;
