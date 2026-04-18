-- ─────────────────────────────────────────────────────────────
--  039_opportunities_and_pipeline.sql
--  Sales pipeline: opportunities + sales_stages lookup.
--
--  Pipeline shape:
--    enquiry (public form) → opportunity (qualified lead being
--    worked) → contract-as-quote (FR-C) → contract active → rostering.
--
--  Pattern lifted from Nexus-CRM's opportunity + sales_stages model:
--    - Stages as lookup table (not ENUM) so Ross can edit names +
--      reorder without schema changes.
--    - probability_percent + is_closed_won/is_closed_lost flags
--      drive pipeline KPIs (FR-M reporting).
--    - Soft-delete via status ENUM, never hard-delete (audit trail).
--
--  This migration is FR-L foundation (opportunities table + pipeline
--  view). Migration 040 adds contracts.opportunity_id so quotes can
--  link back to the originating opportunity.
--
--  Rollback (if ever needed):
--    DROP TABLE opportunities;
--    DROP TABLE sales_stages;
--    DELETE FROM role_permissions WHERE page_id IN
--      (SELECT id FROM pages WHERE code IN ('opportunities','pipeline'));
--    DELETE FROM pages WHERE code IN ('opportunities','pipeline');
-- ─────────────────────────────────────────────────────────────

SET NAMES utf8mb4;

-- ── 1. sales_stages — lookup for pipeline columns
-- One row per Kanban column. Drives /admin/pipeline ordering.
CREATE TABLE IF NOT EXISTS sales_stages (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(60)  NOT NULL
        COMMENT 'Display name on the Kanban column and in the list filter',
    slug                VARCHAR(40)  NOT NULL UNIQUE
        COMMENT 'Short machine key — stable across name changes. e.g. new, qualifying, quoted',
    sort_order          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    probability_percent TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT '0-100. Used for weighted pipeline value in FR-M Acquire reporting',
    is_closed_won       TINYINT(1)   NOT NULL DEFAULT 0
        COMMENT 'Exactly one stage should have this = 1',
    is_closed_lost      TINYINT(1)   NOT NULL DEFAULT 0
        COMMENT 'Exactly one stage should have this = 1',
    is_active           TINYINT(1)   NOT NULL DEFAULT 1
        COMMENT 'Soft-disable without deleting — preserves historical opps still pointing here',
    description         VARCHAR(255) DEFAULT NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_stages_sort   (sort_order),
    INDEX idx_stages_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: 6 default stages. Editable later.
INSERT INTO sales_stages (name, slug, sort_order, probability_percent, is_closed_won, is_closed_lost, description) VALUES
  ('New',          'new',          10,  10, 0, 0, 'Just converted from enquiry or logged direct — not yet contacted or qualified'),
  ('Qualifying',   'qualifying',   20,  25, 0, 0, 'Initial contact made; gathering care requirement detail'),
  ('Quoted',       'quoted',       30,  60, 0, 0, 'Quote built and sent to the client — awaiting response'),
  ('Negotiating',  'negotiating',  40,  80, 0, 0, 'Client engaged; working through terms / schedule / rate'),
  ('Closed — Won', 'closed_won',   50, 100, 1, 0, 'Deal won. Contract active; provisioning begins'),
  ('Closed — Lost','closed_lost',  60,   0, 0, 1, 'Deal lost. Reason captured on the opportunity')
ON DUPLICATE KEY UPDATE name=VALUES(name), sort_order=VALUES(sort_order),
    probability_percent=VALUES(probability_percent),
    is_closed_won=VALUES(is_closed_won), is_closed_lost=VALUES(is_closed_lost);

-- ── 2. opportunities — the sales pipeline record
CREATE TABLE IF NOT EXISTS opportunities (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    opp_ref               VARCHAR(20)  NOT NULL UNIQUE
        COMMENT 'Human-friendly reference like OPP-2026-0001. Generated on insert.',

    -- Origin
    source                ENUM('enquiry','referral','direct_call','walk_in','other')
        NOT NULL DEFAULT 'enquiry'
        COMMENT 'Where this opportunity came from. FK to enquiries only populated if source=enquiry.',
    source_enquiry_id     INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to enquiries — nullable; only set when source=enquiry',
    source_note           VARCHAR(255) DEFAULT NULL
        COMMENT 'Free text for non-enquiry sources, e.g. referrer name',

    -- Who it is for (nullable early — filled as we qualify)
    client_id             INT UNSIGNED DEFAULT NULL
        COMMENT 'Bill-payer once known. NULL while the opp is in New/Qualifying and we have not yet created the client record.',
    patient_person_id     INT UNSIGNED DEFAULT NULL
        COMMENT 'Care recipient once known. NULL while uncertain.',

    -- Contact snapshot (carried from enquiry or captured direct)
    contact_name          VARCHAR(200) DEFAULT NULL,
    contact_email         VARCHAR(150) DEFAULT NULL,
    contact_phone         VARCHAR(30)  DEFAULT NULL,

    -- What they want
    title                 VARCHAR(200) NOT NULL
        COMMENT 'Short label for the opportunity. Auto-generated on convert, editable.',
    care_summary          TEXT         DEFAULT NULL
        COMMENT 'What care they need — expanded from enquiry.message during qualifying',

    -- Pipeline position
    owner_user_id         INT UNSIGNED DEFAULT NULL
        COMMENT 'Sales owner — the user responsible for working this opp',
    stage_id              INT UNSIGNED NOT NULL
        COMMENT 'FK to sales_stages — Kanban column',

    -- Estimation (pre-quote guesses; real numbers live on the quote/contract)
    expected_value_cents  INT UNSIGNED DEFAULT NULL
        COMMENT 'Rough monthly value expected, in cents (ZAR). Used for pipeline totals.',
    expected_start_date   DATE         DEFAULT NULL
        COMMENT 'Roughly when care is expected to begin',

    -- Linking to the downstream quote / contract (populated by FR-C)
    contract_id           INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to contracts — the quote/contract built from this opportunity. One opp, one contract (v1).',

    -- Closure
    status                ENUM('open','closed','archived') NOT NULL DEFAULT 'open'
        COMMENT 'Soft-delete via status. Stage (closed_won/closed_lost) drives pipeline reporting; status tracks lifecycle of the record itself.',
    reason_lost           ENUM('price','timing','competitor','lost_contact','not_a_fit','other') DEFAULT NULL
        COMMENT 'Required when stage is closed_lost. Drives Acquire-phase reporting.',
    reason_lost_note      TEXT         DEFAULT NULL,
    closed_at             TIMESTAMP    NULL DEFAULT NULL,

    -- Admin notes + activity live on activity_log keyed by entity_type=opportunities
    notes                 TEXT         DEFAULT NULL,

    -- Audit
    created_by_user_id    INT UNSIGNED DEFAULT NULL,
    created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (source_enquiry_id)  REFERENCES enquiries(id)  ON DELETE SET NULL,
    FOREIGN KEY (client_id)          REFERENCES clients(id)    ON DELETE SET NULL,
    FOREIGN KEY (patient_person_id)  REFERENCES persons(id)    ON DELETE SET NULL,
    FOREIGN KEY (owner_user_id)      REFERENCES users(id)      ON DELETE SET NULL,
    FOREIGN KEY (stage_id)           REFERENCES sales_stages(id),
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)      ON DELETE SET NULL,

    INDEX idx_opp_stage        (stage_id),
    INDEX idx_opp_owner        (owner_user_id),
    INDEX idx_opp_status       (status),
    INDEX idx_opp_source       (source, source_enquiry_id),
    INDEX idx_opp_client       (client_id),
    INDEX idx_opp_patient      (patient_person_id),
    INDEX idx_opp_contract     (contract_id),
    INDEX idx_opp_created      (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Register the admin pages in the permission registry
INSERT INTO pages (code, label, section, description, sort_order) VALUES
  ('opportunities', 'Opportunities',    'records', 'Sales pipeline — enquiries promoted to opportunities worked toward Closed-Won or Closed-Lost.', 32),
  ('pipeline',      'Pipeline (Kanban)','records', 'Drag-and-drop Kanban view of open opportunities by stage. Same data as /admin/opportunities, different lens.',      33)
ON DUPLICATE KEY UPDATE label=VALUES(label), description=VALUES(description), sort_order=VALUES(sort_order);

-- Grant Super Admin full CRUD.
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT r.id, p.id, 1, 1, 1, 1
  FROM roles r
  JOIN pages p ON p.code IN ('opportunities','pipeline')
 WHERE r.slug = 'super_admin';

-- Admin role: read + create + edit, no delete (matches contracts pattern).
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT r.id, p.id, 1, 1, 1, 0
  FROM roles r
  JOIN pages p ON p.code IN ('opportunities','pipeline')
 WHERE r.slug = 'admin';
