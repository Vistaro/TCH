-- ─────────────────────────────────────────────────────────────
--  028_client_defaults_off_persons.sql
--  D1 — Move billing defaults off `persons`, onto `clients`.
--
--  Rationale: billing config belongs to the contract (engagement)
--  with per-client defaults used to prefill new engagements. The
--  four fields on `persons` were leftover from the pre-engagements
--  model and already lied when a client had two engagements at
--  different rates.
--
--  Covered fields:
--    persons.day_rate        → clients.default_day_rate
--    persons.billing_freq    → clients.default_billing_freq
--      (replaces the existing clients.billing_freq — renamed,
--       existing values preferred on backfill conflict)
--    persons.shift_type      → clients.default_shift_type
--    persons.schedule        → clients.default_schedule
-- ─────────────────────────────────────────────────────────────

-- ── 1. Rename existing clients.billing_freq to default_billing_freq
ALTER TABLE clients
    CHANGE COLUMN billing_freq default_billing_freq VARCHAR(30) DEFAULT NULL
        COMMENT 'Prefill for new engagement billing frequency';

-- ── 2. Add new default_* columns on clients
ALTER TABLE clients
    ADD COLUMN default_day_rate   DECIMAL(10,2) DEFAULT NULL
        COMMENT 'Prefill for new engagement bill rate (ZAR/day)'
        AFTER default_billing_freq,
    ADD COLUMN default_shift_type VARCHAR(30)   DEFAULT NULL
        COMMENT 'Prefill for new engagement shift type'
        AFTER default_day_rate,
    ADD COLUMN default_schedule   VARCHAR(100)  DEFAULT NULL
        COMMENT 'Prefill for new engagement schedule'
        AFTER default_shift_type;

-- ── 3. Backfill from persons
--  Keep any existing clients.default_billing_freq value; only
--  fall back to persons.billing_freq if clients side is NULL.
UPDATE clients c
    JOIN persons p ON p.id = c.person_id
   SET c.default_day_rate     = p.day_rate,
       c.default_billing_freq = COALESCE(c.default_billing_freq, p.billing_freq),
       c.default_shift_type   = p.shift_type,
       c.default_schedule     = p.schedule;

-- ── 4. Drop the now-redundant fields from persons
ALTER TABLE persons
    DROP COLUMN day_rate,
    DROP COLUMN billing_freq,
    DROP COLUMN shift_type,
    DROP COLUMN schedule;
