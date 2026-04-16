-- Migration 035 — widen caregivers.working_pattern from VARCHAR(20) to VARCHAR(64).
-- The original width (migration 031) was too narrow: a realistic 7-day pattern
-- serialises as "MON,TUE,WED,THU,FRI,SAT,SUN|NIGHT|LIVEIN" = 38 chars, which
-- the save handler was silently truncating to 20, losing the shift + live-in
-- suffix and corrupting parseability. 64 chars is 2× the max realistic length.
--
-- Safe forward: widening a VARCHAR on InnoDB is a metadata-only change; no row
-- data is rewritten. NOT NULL + existing DEFAULT 'MON-SUN' preserved.
--
-- Destructive if rolled back: rolling to VARCHAR(20) would truncate any row
-- with a post-widening pattern > 20 chars. Verify row distribution before any
-- rollback.

START TRANSACTION;

ALTER TABLE caregivers
    MODIFY COLUMN working_pattern VARCHAR(64) NOT NULL DEFAULT 'MON-SUN';

COMMIT;
