-- TCH Placements — Migration 004
-- Public website: configurable per-region contact details + enquiry inbox.
--
-- This migration sets up the foundation for the public website to:
--   1. Pull all contact details (phone, email, address, service area) from
--      a `regions` table — so the same template can serve a future Western
--      Cape page, KwaZulu-Natal page, etc., each with its own contacts.
--   2. Capture client and caregiver enquiries from the public form into an
--      `enquiries` table that staff can review and action via an admin page.
--
-- Run against the dev DB:
--   mysql -u tch_admin -p tch_placements < database/004_regions_and_enquiries.sql

SET NAMES utf8mb4;

-- ============================================================
-- REGIONS
-- One row per geographic region TCH operates in. Currently: Gauteng only.
-- Future: Western Cape, KwaZulu-Natal, Eastern Cape etc.
-- The public homepage uses the row marked is_primary=1.
-- Per-region pages (e.g. /region/wc) will look up by code.
-- ============================================================
CREATE TABLE IF NOT EXISTS regions (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code                     VARCHAR(20)  NOT NULL UNIQUE COMMENT 'URL slug, e.g. gauteng, wc, kzn',
    name                     VARCHAR(100) NOT NULL COMMENT 'Display name, e.g. Gauteng',
    is_active                TINYINT(1)   NOT NULL DEFAULT 1,
    is_primary               TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Exactly one row should be primary',
    sort_order               SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    -- Contact details
    phone_primary            VARCHAR(30)  DEFAULT NULL,
    phone_secondary          VARCHAR(30)  DEFAULT NULL,
    email_primary            VARCHAR(150) DEFAULT NULL,
    email_secondary          VARCHAR(150) DEFAULT NULL,

    -- Physical / postal
    physical_address         VARCHAR(255) DEFAULT NULL,
    postal_address           VARCHAR(255) DEFAULT NULL,
    physical_postal_code     VARCHAR(10)  DEFAULT NULL,

    -- Service area + marketing copy
    service_area_description VARCHAR(255) DEFAULT NULL COMMENT 'e.g. "Within 25 miles of Pretoria"',
    hero_headline            VARCHAR(255) DEFAULT NULL COMMENT 'Optional override for the hero headline',
    hero_subhead             TEXT         DEFAULT NULL COMMENT 'Optional override for the hero subhead',

    -- Operational
    office_hours             VARCHAR(100) DEFAULT NULL COMMENT 'e.g. "Mon-Fri 8:00-17:00"',
    facebook_url             VARCHAR(255) DEFAULT NULL,
    instagram_url            VARCHAR(255) DEFAULT NULL,
    whatsapp_number          VARCHAR(30)  DEFAULT NULL,

    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_regions_active  (is_active),
    INDEX idx_regions_primary (is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: Gauteng (placeholder details until Ross provides real ones)
INSERT INTO regions (
    code, name, is_active, is_primary, sort_order,
    phone_primary, email_primary,
    physical_address, physical_postal_code,
    service_area_description, office_hours
) VALUES (
    'gauteng', 'Gauteng', 1, 1, 10,
    'XXX XXX XXXX',
    'hello@tch.intelligentae.co.uk',
    'Pretoria, Gauteng', NULL,
    'Within 25 miles of Pretoria — expanding across Gauteng',
    'Mon-Fri 8:00-17:00'
)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ============================================================
-- ENQUIRIES
-- Public form submissions. One row per enquiry.
-- Status field tracks the lifecycle (new -> contacted -> converted/closed).
-- ============================================================
CREATE TABLE IF NOT EXISTS enquiries (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    region_id       INT UNSIGNED DEFAULT NULL COMMENT 'Which region the enquiry is for',
    enquiry_type    ENUM('client','caregiver','general') NOT NULL DEFAULT 'client'
                    COMMENT 'client = needs a caregiver; caregiver = wants placement; general = other',

    -- Submitter details
    full_name       VARCHAR(200) NOT NULL,
    email           VARCHAR(150) DEFAULT NULL,
    phone           VARCHAR(30)  DEFAULT NULL,
    suburb_or_area  VARCHAR(150) DEFAULT NULL COMMENT 'Where the care is needed',

    -- For client enquiries
    care_type       VARCHAR(50)  DEFAULT NULL
                    COMMENT 'temporary / permanent / post_op / palliative / respite / errand / other',
    care_schedule   VARCHAR(100) DEFAULT NULL COMMENT 'e.g. "weekdays 8-5", "live-in"',
    urgency         VARCHAR(50)  DEFAULT NULL COMMENT 'e.g. immediate, within a week, planning ahead',

    -- Free text
    message         TEXT DEFAULT NULL,

    -- POPIA / consent
    consent_terms   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Acknowledged privacy/terms',
    consent_marketing TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Opted in to marketing follow-ups',

    -- Audit metadata
    source_page     VARCHAR(120) DEFAULT NULL COMMENT 'Which page the form was on',
    user_agent      VARCHAR(255) DEFAULT NULL,
    ip_address      VARCHAR(45)  DEFAULT NULL,
    referrer_url    VARCHAR(255) DEFAULT NULL,

    -- Workflow
    status          ENUM('new','contacted','converted','spam','closed') NOT NULL DEFAULT 'new',
    handled_by      VARCHAR(100) DEFAULT NULL,
    handled_at      TIMESTAMP NULL DEFAULT NULL,
    notes           TEXT DEFAULT NULL,

    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL,
    INDEX idx_enq_status  (status),
    INDEX idx_enq_created (created_at),
    INDEX idx_enq_region  (region_id),
    INDEX idx_enq_type    (enquiry_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
