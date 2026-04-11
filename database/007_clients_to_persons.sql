-- ================================================================
--  007_clients_to_persons.sql
--  Move the 51 clean clients into the persons table as
--  person_type='patient,client', retire the clients table, and
--  retarget every FK pointing at clients to point at persons.
--
--  Applies the single-source-of-truth principle (standing order in
--  C:\ClaudeCode\CLAUDE.md) by NOT carrying across the four derived
--  fields on clients (first_seen, last_seen, months_active, status).
--  Those are now computed from client_revenue at query time. Only
--  genuinely non-derivable fields come across:
--    - account_number (identifier)
--    - patient_name   (independent attribute; the post-backfill pair)
--    - day_rate, billing_freq, shift_type, schedule, billing_entity
--      (service configuration — 3 of 51 rows populated, still worth
--      keeping so we don't have to re-enter for Berthe / Darren /
--      Julian Sam)
--
--  The 79 historical activity_log entries with entity_type='clients'
--  (dedup merges, renames, backfills, deletes from 2026-04-11) are
--  rewritten so both entity_type and entity_id point at the new
--  person rows. This preserves the undo/revert pathway for any
--  historical action.
--
--  users.linked_client_id is dropped entirely. The column was wired
--  for future client self-service login but has zero populated rows
--  today. It can be re-added later if/when client self-service
--  becomes real.
--
--  The old clients table is RENAMED to clients_deprecated_2026_04_11,
--  NOT dropped. It stays on disk as a last-resort rollback artefact.
--  In a future session once confidence is high, a follow-up migration
--  will drop it.
-- ================================================================

SET FOREIGN_KEY_CHECKS = 0;

START TRANSACTION;

-- ----------------------------------------------------------------
-- 1. Add the genuine non-derived client fields to persons
-- ----------------------------------------------------------------
ALTER TABLE persons
  ADD COLUMN account_number  VARCHAR(12)  DEFAULT NULL COMMENT 'Billing account ID, TCH-C0001 style (client-type persons only)' AFTER tch_id,
  ADD COLUMN patient_name    VARCHAR(150) DEFAULT NULL COMMENT 'Care recipient name when different from the person paying (client-type persons only)' AFTER known_as,
  ADD COLUMN day_rate        DECIMAL(10,2) DEFAULT NULL COMMENT 'Standard day rate for this client (client-type persons only)',
  ADD COLUMN billing_freq    VARCHAR(30)  DEFAULT NULL COMMENT 'Monthly / Weekly / As and when (client-type persons only)',
  ADD COLUMN shift_type      VARCHAR(30)  DEFAULT NULL COMMENT 'Day Shift / Night Shift / Live-In (client-type persons only)',
  ADD COLUMN schedule        VARCHAR(50)  DEFAULT NULL COMMENT 'Full Time / 3 days / Discuss Weekly / etc. (client-type persons only)',
  ADD COLUMN billing_entity  VARCHAR(10)  DEFAULT NULL COMMENT 'NPC or TCH — which legal entity bills this client (client-type persons only)',
  ADD UNIQUE KEY uk_persons_account_number (account_number);

-- Temporary column to carry the old clients.id through the insert so
-- we can rewire every FK afterwards. Dropped before COMMIT.
ALTER TABLE persons
  ADD COLUMN _legacy_client_id INT UNSIGNED DEFAULT NULL COMMENT 'TEMPORARY — dropped at end of migration 007';


-- ----------------------------------------------------------------
-- 2. Insert the 51 clean clients into persons as patient+client
--    full_name comes from clients.client_name; everything else
--    that's meaningful for a client row comes across, everything
--    else is NULL (e.g. no tranche, no dob, no caregiver-specific
--    fields for client-type rows).
--    NOTE: derived fields (first_seen, last_seen, months_active,
--    status) are NOT carried. They compute from client_revenue at
--    read time.
-- ----------------------------------------------------------------
INSERT INTO persons (
    person_type,
    full_name,
    account_number,
    patient_name,
    day_rate,
    billing_freq,
    shift_type,
    schedule,
    billing_entity,
    _legacy_client_id,
    import_notes,
    created_at,
    updated_at
)
SELECT
    'patient,client',
    c.client_name,
    c.account_number,
    c.patient_name,
    c.day_rate,
    c.billing_freq,
    c.shift_type,
    c.schedule,
    c.entity,
    c.id,
    CONCAT('Migrated from clients.id=', c.id, ' on 2026-04-11 via migration 007 (patient+client unification). '
           'Original clients.first_seen=', IFNULL(DATE_FORMAT(c.first_seen, '%Y-%m-%d'), 'NULL'),
           ', last_seen=', IFNULL(DATE_FORMAT(c.last_seen, '%Y-%m-%d'), 'NULL'),
           ', months_active=', IFNULL(c.months_active, 0),
           ', status=', c.status,
           ' (these derived fields dropped per single-source-of-truth principle; '
           'active state and revenue window now computed from client_revenue on read).'),
    c.created_at,
    c.updated_at
FROM clients c
ORDER BY c.id;


-- ----------------------------------------------------------------
-- 3. Generate tch_id values for the new client rows
--    Existing caregivers already have tch_id = TCH-000001 through
--    TCH-0001NN. New clients continue the sequence using their
--    new persons.id values.
-- ----------------------------------------------------------------
UPDATE persons
SET tch_id = CONCAT('TCH-', LPAD(id, 6, '0'))
WHERE _legacy_client_id IS NOT NULL;


-- ----------------------------------------------------------------
-- 4. Rewire client_revenue.client_id — old clients.id → new persons.id
-- ----------------------------------------------------------------
UPDATE client_revenue cr
JOIN persons p ON p._legacy_client_id = cr.client_id
SET   cr.client_id = p.id;


-- ----------------------------------------------------------------
-- 5. Rewire daily_roster.client_id — old clients.id → new persons.id
--    Note: ~1,200 daily_roster rows have client_id IS NULL (never
--    matched during ingest) and are not affected.
-- ----------------------------------------------------------------
UPDATE daily_roster dr
JOIN persons p ON p._legacy_client_id = dr.client_id
SET   dr.client_id = p.id;


-- ----------------------------------------------------------------
-- 6. Rewrite historical activity_log entries scoped to clients
--    so revert/undelete still dispatches correctly. 79 rows covering
--    the 2026-04-11 dedup exercise.
-- ----------------------------------------------------------------
UPDATE activity_log al
JOIN persons p ON p._legacy_client_id = al.entity_id
SET   al.entity_type = 'persons',
      al.entity_id   = p.id
WHERE al.entity_type = 'clients';


-- ----------------------------------------------------------------
-- 7. Retarget FKs on client_revenue + daily_roster from clients to
--    persons, so InnoDB enforces referential integrity against the
--    new table.
-- ----------------------------------------------------------------
ALTER TABLE client_revenue
  DROP FOREIGN KEY client_revenue_ibfk_1;
ALTER TABLE client_revenue
  ADD CONSTRAINT client_revenue_ibfk_1
      FOREIGN KEY (client_id) REFERENCES persons (id) ON DELETE SET NULL;

ALTER TABLE daily_roster
  DROP FOREIGN KEY daily_roster_ibfk_2;
ALTER TABLE daily_roster
  ADD CONSTRAINT daily_roster_ibfk_2
      FOREIGN KEY (client_id) REFERENCES persons (id) ON DELETE SET NULL;


-- ----------------------------------------------------------------
-- 8. Drop users.linked_client_id — zero populated rows, provisional
--    wiring never used. Can be re-added via a future migration if
--    client self-service login becomes a real feature.
-- ----------------------------------------------------------------
ALTER TABLE users
  DROP FOREIGN KEY fk_users_client;
ALTER TABLE users
  DROP COLUMN linked_client_id;


-- ----------------------------------------------------------------
-- 9. Drop the temporary _legacy_client_id column on persons
-- ----------------------------------------------------------------
ALTER TABLE persons
  DROP COLUMN _legacy_client_id;


-- ----------------------------------------------------------------
-- 10. Rename old clients table — preserve as cold storage.
--     Nothing queries it after this migration. Can be dropped in
--     a future session once confidence is high.
-- ----------------------------------------------------------------
RENAME TABLE clients TO clients_deprecated_2026_04_11;


COMMIT;

SET FOREIGN_KEY_CHECKS = 1;

-- ================================================================
--  ROLLBACK (if migration fails before COMMIT, the transaction
--  auto-rolls back — no action needed. If something breaks AFTER
--  COMMIT and we need to revert manually:)
--
--    START TRANSACTION;
--    RENAME TABLE clients_deprecated_2026_04_11 TO clients;
--    ALTER TABLE users
--      ADD COLUMN linked_client_id INT UNSIGNED DEFAULT NULL,
--      ADD CONSTRAINT fk_users_client
--          FOREIGN KEY (linked_client_id) REFERENCES clients(id) ON DELETE SET NULL;
--    ALTER TABLE daily_roster DROP FOREIGN KEY daily_roster_ibfk_2;
--    ALTER TABLE daily_roster ADD CONSTRAINT daily_roster_ibfk_2
--      FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;
--    ALTER TABLE client_revenue DROP FOREIGN KEY client_revenue_ibfk_1;
--    ALTER TABLE client_revenue ADD CONSTRAINT client_revenue_ibfk_1
--      FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;
--    -- For the revenue/roster/activity_log rewire you would need to
--    -- read p._legacy_client_id from the persons rows (but we've
--    -- dropped that column by this point, so restore the fresh
--    -- pre-007 backup instead).
--    DELETE FROM persons WHERE person_type LIKE '%client%';
--    ALTER TABLE persons
--      DROP COLUMN billing_entity, DROP COLUMN schedule,
--      DROP COLUMN shift_type, DROP COLUMN billing_freq,
--      DROP COLUMN day_rate, DROP COLUMN patient_name,
--      DROP INDEX uk_persons_account_number, DROP COLUMN account_number;
--    COMMIT;
--
--  In practice: if something breaks, restore from
--  database/backups/post_dedup_pre_migration_007_2026-04-11.sql
--  (taken just before this migration ran).
-- ================================================================
