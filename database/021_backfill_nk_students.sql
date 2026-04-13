-- ================================================================
--  021_backfill_nk_students.sql
--  Reconcile caregiver/student/enrollment counts so every caregiver
--  in `persons` has a matching `students` row + at least one
--  `student_enrollments` row. Newly-created rows are flagged
--  import_review_state='pending' so Tuniti can complete the detail.
-- ================================================================

START TRANSACTION;

INSERT INTO students (person_id, cohort, import_review_state)
SELECT p.id, 'N/K', 'pending'
FROM persons p
WHERE FIND_IN_SET('caregiver', p.person_type) > 0
  AND NOT EXISTS (SELECT 1 FROM students s WHERE s.person_id = p.id);

UPDATE students SET import_review_state = 'pending'
WHERE cohort = 'N/K' AND import_review_state IS NULL;

INSERT INTO student_enrollments (student_person_id, cohort, enrolled_at, status, notes)
SELECT s.person_id, COALESCE(s.cohort, 'N/K'), CURDATE(), 'enrolled',
       'Placeholder enrollment created 2026-04-13 - awaiting Tuniti detail'
FROM students s
JOIN persons p ON p.id = s.person_id
WHERE FIND_IN_SET('caregiver', p.person_type) > 0
  AND NOT EXISTS (SELECT 1 FROM student_enrollments e WHERE e.student_person_id = s.person_id);

COMMIT;
