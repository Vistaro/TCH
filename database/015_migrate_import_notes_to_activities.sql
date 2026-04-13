-- ================================================================
--  015_migrate_import_notes_to_activities.sql
--  Move existing `persons.import_notes` (machine-generated audit
--  text from PDF parsing + approve/reject events) into the new
--  unified Notes timeline so a single source of truth remains.
--
--  Original column is kept (not dropped) for safety. Once the new
--  page is bedded in, drop in a follow-up migration.
--
--  Attribution: claude.bot (user id 3), type 'System' (id 7),
--  source 'import-history', source_batch 'pre-2026-04-13'.
-- ================================================================

START TRANSACTION;

-- ── Persons.import_notes → activities ───────────────────────────
INSERT INTO activities
    (activity_type_id, entity_type, entity_id, user_id,
     subject, notes, source, source_ref, source_batch,
     activity_date, is_task, task_status)
SELECT
    7,                                   -- System
    'persons',
    p.id,
    3,                                   -- claude.bot
    'Import history (migrated)',
    p.import_notes,
    'import-history',
    CONCAT('persons.import_notes#', p.id),
    'pre-2026-04-13',
    p.created_at,
    0,
    'pending'
FROM persons p
WHERE p.import_notes IS NOT NULL
  AND p.import_notes != ''
  AND NOT EXISTS (
      SELECT 1 FROM activities a
      WHERE a.entity_type = 'persons'
        AND a.entity_id = p.id
        AND a.source_batch = 'pre-2026-04-13'
  );

-- ── Students.import_notes → activities (where different) ─────────
-- Skip rows whose content already exists in persons.import_notes
-- to avoid duplicates.
INSERT INTO activities
    (activity_type_id, entity_type, entity_id, user_id,
     subject, notes, source, source_ref, source_batch,
     activity_date, is_task, task_status)
SELECT
    7,
    'persons',
    s.person_id,
    3,
    'Import notes from students table (migrated)',
    s.import_notes,
    'import-history',
    CONCAT('students.import_notes#', s.person_id),
    'pre-2026-04-13',
    s.created_at,
    0,
    'pending'
FROM students s
JOIN persons p ON p.id = s.person_id
WHERE s.import_notes IS NOT NULL
  AND s.import_notes != ''
  AND (p.import_notes IS NULL OR p.import_notes = '' OR p.import_notes != s.import_notes);

COMMIT;
