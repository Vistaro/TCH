-- Migration 032 — Tuniti onboarding task dashboard + supporting tables.
-- Adds: onboarding_uploads, system_acknowledgements, timesheet_reconciliation_items.
-- Registers onboarding page codes with Super Admin + Admin access.

START TRANSACTION;

-- Shared upload tracking. Every onboarding task can attach files here.
CREATE TABLE IF NOT EXISTS onboarding_uploads (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_key            VARCHAR(60) NOT NULL,
  uploader_user_id    INT UNSIGNED,
  filename            VARCHAR(255) NOT NULL,
  stored_path         VARCHAR(500) NOT NULL,
  sha256              CHAR(64) NOT NULL,
  mime                VARCHAR(100),
  size_bytes          INT UNSIGNED,
  status              ENUM('uploaded','in_review','ingested','rejected') NOT NULL DEFAULT 'uploaded',
  notes               TEXT,
  uploaded_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ingested_at         TIMESTAMP NULL,
  ingested_by_user_id INT UNSIGNED,
  INDEX idx_task_status (task_key, status),
  INDEX idx_uploader (uploader_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One-shot acknowledgements (Tuniti "I have seen / accepted this").
CREATE TABLE IF NOT EXISTS system_acknowledgements (
  id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ack_key                 VARCHAR(60) NOT NULL UNIQUE,
  acknowledged_by_user_id INT UNSIGNED,
  acknowledged_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes                   TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 56-line timesheet reconciliation queue (seeded from the 2026-04-14 xlsx).
CREATE TABLE IF NOT EXISTS timesheet_reconciliation_items (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_batch        VARCHAR(60) NOT NULL,
  tab_name            VARCHAR(100),
  caregiver_col       VARCHAR(10),
  caregiver_name      VARCHAR(200),
  person_id           INT UNSIGNED,
  cells_n             SMALLINT UNSIGNED,
  units               DECIMAL(6,2),
  rate                DECIMAL(10,2),
  computed_zar        DECIMAL(12,2),
  sheet_total_zar     DECIMAL(12,2),
  diff_zar            DECIMAL(12,2),
  pattern             ENUM('LOAN_DEDUCTED_FROM_TOTAL','BONUS_ADDED_TO_TOTAL','MISSING_RATE','UNEXPLAINED'),
  money_added_zar     DECIMAL(12,2),
  money_borrowed_zar  DECIMAL(12,2),
  suggested_query     TEXT,
  resolution_status   ENUM(
                        'pending',
                        'accepted_loan',
                        'recorded_bonus',
                        'rate_corrected',
                        'accepted_unexplained',
                        'flagged',
                        'ignored'
                      ) NOT NULL DEFAULT 'pending',
  resolution_notes    TEXT,
  resolved_by_user_id INT UNSIGNED,
  resolved_at         TIMESTAMP NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_status (resolution_status),
  INDEX idx_source_batch (source_batch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Register onboarding pages in the pages permission registry.
INSERT INTO pages (code, label, section, description, sort_order) VALUES
  ('onboarding',        'Tuniti Onboarding',  'admin', 'Guided task list for Tuniti outstanding items',                   50),
  ('onboarding_review', 'Onboarding Review',  'admin', 'Queue of files uploaded by Tuniti awaiting extraction',           51)
ON DUPLICATE KEY UPDATE label=VALUES(label), description=VALUES(description), sort_order=VALUES(sort_order);

-- Grant Super Admin (1) + Admin (2) full CRUD on the onboarding pages.
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT r.id, p.id, 1, 1, 1, 1
  FROM roles r
 CROSS JOIN pages p
 WHERE r.name IN ('Super Admin','Admin')
   AND p.code IN ('onboarding','onboarding_review');

COMMIT;
