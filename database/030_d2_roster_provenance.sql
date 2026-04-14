-- ─────────────────────────────────────────────────────────────
--  030_d2_roster_provenance.sql
--  D2 / D3 Phase 2 — Roster provenance + units + patient link.
--
--  Builds on migration 010 (engagement_id/product_id/cost_rate/bill_rate
--  /status/shift_start/end already exist). Adds what the single-source-of-
--  truth rebuild needs:
--
--    - patient_person_id  — bill-payer (client_id) may differ from care
--                           recipient (patient). Needed for D2 GP-by-patient.
--    - units              — 1 by default, 0.5 for half-day cells, etc.
--    - source_upload_id   — FK to timesheet_uploads (which workbook this
--                           row came from).
--    - source_alias_id    — FK to timesheet_name_aliases (which alias
--                           resolved the caregiver). Lets an alias
--                           re-map trigger backfill caregiver_id.
--    - source_cell        — Tab+cell reference for this shift
--                           (e.g. "Caregiver Jan 2026!J6").
--
--  Also: new `timesheet_uploads` table to track each workbook we ingest.
-- ─────────────────────────────────────────────────────────────

-- ── 1. timesheet_uploads — provenance anchor for every ingest
CREATE TABLE IF NOT EXISTS timesheet_uploads (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename            VARCHAR(255) NOT NULL,
    sha256              CHAR(64) NOT NULL,
    workbook_type       ENUM('timesheet','panel') NOT NULL
        COMMENT 'timesheet = Caregiver Timesheets (cost side); panel = Revenue to Clients (bill side)',
    months_covered      VARCHAR(255) DEFAULT NULL
        COMMENT 'JSON array of tab names ingested — e.g. ["Caregiver Nov 2025","Caregiver Dec 2025"]',
    uploaded_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    uploaded_by_user_id INT UNSIGNED DEFAULT NULL,
    dry_run_at          TIMESTAMP NULL DEFAULT NULL,
    ingested_at         TIMESTAMP NULL DEFAULT NULL,
    ingested_by_user_id INT UNSIGNED DEFAULT NULL,
    status              ENUM('uploaded','dry_run','ingested','reverted') NOT NULL DEFAULT 'uploaded',
    dry_run_report      MEDIUMTEXT DEFAULT NULL
        COMMENT 'JSON: totals by tab + discrepancies + unresolved-alias count',
    notes               TEXT DEFAULT NULL,
    UNIQUE KEY uk_sha (sha256),
    INDEX idx_upload_status (status),
    INDEX idx_upload_type (workbook_type, ingested_at),
    FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (ingested_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Extend daily_roster with provenance + patient + units
ALTER TABLE daily_roster
    ADD COLUMN patient_person_id   INT UNSIGNED DEFAULT NULL
        COMMENT 'Care recipient (persons.id) — may differ from client_id (bill-payer)'
        AFTER client_id,
    ADD COLUMN units                DECIMAL(5,2) NOT NULL DEFAULT 1.00
        COMMENT 'Shift units — 1.00 full day, 0.50 half day, etc.'
        AFTER product_id,
    ADD COLUMN source_upload_id    INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to timesheet_uploads — which workbook this row came from',
    ADD COLUMN source_alias_id      INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to timesheet_name_aliases — which alias resolved the caregiver. Re-maps re-point this row.',
    ADD COLUMN source_cell          VARCHAR(120) DEFAULT NULL
        COMMENT 'Provenance reference, e.g. "Caregiver Jan 2026!J6"',
    ADD INDEX idx_roster_patient (patient_person_id),
    ADD INDEX idx_roster_upload (source_upload_id),
    ADD INDEX idx_roster_alias (source_alias_id),
    ADD CONSTRAINT fk_roster_patient_person
        FOREIGN KEY (patient_person_id) REFERENCES persons(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_roster_upload
        FOREIGN KEY (source_upload_id) REFERENCES timesheet_uploads(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_roster_alias
        FOREIGN KEY (source_alias_id) REFERENCES timesheet_name_aliases(id) ON DELETE SET NULL;

-- ── 3. Register admin page for Timesheet management
INSERT INTO pages (code, label, section, description, sort_order)
VALUES ('timesheets', 'Timesheet Ingest',
        'data',
        'Upload + ingest Tuniti Caregiver Timesheets and the Revenue Panel workbook',
        45)
ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT r.id, p.id, 1, 1, 1, 1
FROM roles r
JOIN pages p ON p.code = 'timesheets'
WHERE r.slug = 'super_admin';
