-- ─────────────────────────────────────────────────────────────
--  029_timesheet_name_aliases.sql
--  Phase 1 of D3 — Timesheet ingest name alignment.
--
--  Tuniti's monthly Caregiver Timesheet workbook carries caregiver
--  column headers (row 1) and patient names in the shift cells.
--  None of these are guaranteed to match our canonical persons.id
--  records. Before we can ingest shifts we need a mapping table.
--
--  Same alias string can legitimately mean different persons
--  depending on role (first names collide across caregiver and
--  patient cells). Uniqueness is on (alias_text, person_role).
--
--  person_id = NULL means the alias is still in the unresolved
--  queue — the ingest blocker. Admin resolves these via
--  /admin/config/aliases (built in Milestone 1b).
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS timesheet_name_aliases (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alias_text        VARCHAR(200) NOT NULL
        COMMENT 'Raw cell / header text after stripping rate overrides, split markers and -half suffix',
    person_role       ENUM('caregiver','patient','client','student') NOT NULL
        COMMENT 'Role context — same string may map to different persons per role',
    person_id         INT UNSIGNED DEFAULT NULL
        COMMENT 'NULL = unresolved, in the queue',
    confidence        ENUM('auto_exact','auto_fuzzy','confirmed','unresolved') NOT NULL DEFAULT 'unresolved'
        COMMENT 'auto_exact=exact full_name match, auto_fuzzy=heuristic guess, confirmed=admin-signed-off, unresolved=queue',
    first_seen_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    mapped_at         TIMESTAMP NULL DEFAULT NULL,
    mapped_by_user_id INT UNSIGNED DEFAULT NULL,
    first_seen_source VARCHAR(200) DEFAULT NULL
        COMMENT 'Workbook + tab + cell where this alias was first discovered',
    notes             TEXT DEFAULT NULL,
    UNIQUE KEY uk_alias_role (alias_text, person_role),
    INDEX idx_alias_person (person_id),
    INDEX idx_alias_queue (person_role, confidence),
    FOREIGN KEY (person_id)         REFERENCES persons(id) ON DELETE SET NULL,
    FOREIGN KEY (mapped_by_user_id) REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register the admin page so permissions can be assigned
INSERT INTO pages (code, label, section, description, sort_order)
VALUES ('config_aliases', 'Timesheet Aliases',
        'config',
        'Map raw names from the Tuniti Caregiver Timesheet to canonical persons',
        32)
ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description);

-- Super Admin gets full CRUD
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT r.id, p.id, 1, 1, 1, 1
FROM roles r
JOIN pages p ON p.code = 'config_aliases'
WHERE r.slug = 'super_admin';
