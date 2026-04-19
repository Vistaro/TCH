-- ─────────────────────────────────────────────────────────────
--  042_backfill_client_account_numbers.sql
--
--  Backfill missing `clients.account_number` values.
--
--  Context: the phase-1 data-cleanup on 2026-04-14 created 12
--  placeholder client rows ("Not Known" split-offs from conflated
--  persons + the Unbilled Care sentinel) without auto-assigning
--  a TCH-C#### account number. They surface on `/admin/clients`
--  with a blank Account column, which is confusing.
--
--  Fix: assign them the next sequential TCH-C#### references in
--  `id` order, starting from one above the current MAX.
--
--  This is idempotent. Re-running does nothing because the WHERE
--  clause filters to rows where account_number IS NULL only.
--
--  Rollback: set the affected rows back to NULL by id.
-- ─────────────────────────────────────────────────────────────

SET NAMES utf8mb4;

-- Seed the counter with the current highest numeric suffix.
-- Pattern: TCH-C0001, TCH-C0002, ... TCH-C9999.
SELECT
    COALESCE(MAX(CAST(SUBSTRING(account_number, 6) AS UNSIGNED)), 0)
  INTO @next_n
  FROM clients
 WHERE account_number REGEXP '^TCH-C[0-9]+$';

-- Assign TCH-C#### to every NULL row in id order. @next_n
-- pre-increments inside the CONCAT so the first NULL row gets
-- MAX+1, the next gets MAX+2, etc.
UPDATE clients
   SET account_number = CONCAT('TCH-C', LPAD(@next_n := @next_n + 1, 4, '0'))
 WHERE account_number IS NULL
 ORDER BY id;
