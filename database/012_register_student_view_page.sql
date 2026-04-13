-- ================================================================
--  012_register_student_view_page.sql
--  Register the new "Student View" detail page so the permission
--  system recognises it (requirePagePermission('student_view',...)).
--  Super Admin (role_id 1) is auto-granted full CRUD.
-- ================================================================

START TRANSACTION;

INSERT IGNORE INTO pages (code, label, section, description, sort_order) VALUES
    ('student_view', 'Student Detail', 'records',
     'Single-student detail page with activity timeline', 16);

INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT 1, p.id, 1, 1, 1, 1
FROM pages p
WHERE p.code = 'student_view'
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = 1 AND rp.page_id = p.id);

COMMIT;
