-- ================================================================
--  026_releases_whats_new.sql
--
--  "What's new" feature — surface release notes on first login after
--  a deploy, so users (and Tuniti during UAT) see what changed plus
--  the list of known issues being worked.
--
--    releases               — one row per published release/changelog entry
--    users.last_release_seen_id — pointer to the newest release the user
--                             has acknowledged (NULL = never seen one)
--
--  Page registrations:
--    'whats_new'      — granted to every role (read-only)
--    'releases_admin' — granted to Super Admin (CRUD)
-- ================================================================

SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

-- ── 1. releases table ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS releases (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version       VARCHAR(20) NOT NULL,
    title         VARCHAR(200) NOT NULL,
    summary       TEXT NULL COMMENT 'Markdown-ish — what changed in this release (user-facing)',
    known_issues  TEXT NULL COMMENT 'Markdown-ish — bugs / FRs in flight that users should know about',
    released_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_published  TINYINT(1) NOT NULL DEFAULT 1,
    created_by_user_id INT UNSIGNED NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_release_version (version),
    KEY idx_release_published (is_published, released_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. last_release_seen_id on users ───────────────────────────
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_release_seen_id');
SET @sql := IF(@c = 0,
    'ALTER TABLE users ADD COLUMN last_release_seen_id INT UNSIGNED NULL AFTER must_reset_password',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 3. Register pages + grant ──────────────────────────────────
INSERT IGNORE INTO pages (code, label, section, description, sort_order) VALUES
    ('whats_new',       'What''s New',          'core',  'Release notes shown after deploys', 11),
    ('releases_admin',  'Manage Releases',      'admin', 'Create / edit release notes',       250);

-- Grant 'whats_new' read to ALL roles (every authenticated user should see release notes)
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT r.id, p.id, 1, 0, 0, 0
FROM roles r CROSS JOIN pages p
WHERE p.code = 'whats_new'
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.page_id = p.id);

-- Grant 'releases_admin' full CRUD to Super Admin only
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT 1, p.id, 1, 1, 1, 1
FROM pages p
WHERE p.code = 'releases_admin'
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = 1 AND rp.page_id = p.id);

-- ── 4. Seed the current release so the gate has something to fire on ──
INSERT IGNORE INTO releases (version, title, summary, known_issues, released_at, is_published) VALUES
('0.9.20-dev',
 'Client + Patient profiles, dedup, archive, products with default price',
 '## Client and Patient profile pages\n- Full edit-in-place profile pages at /admin/clients/{id} and /admin/patients/{id}\n- Multi-phone, multi-email and multi-address per person — primary flag, +Add row\n- Section-by-section edit (Personal, Contact, Address, Billing) matching the Student profile pattern\n- Photo replace + Notes timeline + activity-log audit on every save\n\n## Create flow with duplicate detection\n- /admin/clients/new and /admin/patients/new\n- Server-side dedup checks exact phone, exact email, exact ID/passport, and Levenshtein/soundex name match against existing un-archived persons\n- If matches found, the form re-renders with a yellow "Possible matches" panel — open the existing record, or tick "create anyway" to proceed\n\n## Archive (no delete)\n- Archive button on every profile, with optional reason\n- Default lists hide archived records; "Show archived" toggle reveals them muted\n- Restore is one click\n\n## "Same person" toggle and warning banner\n- Mark a client as also being the patient (or vice versa) — creates the matching role row pointing at the same person\n- Blue "Same person" banner on profile pages where the client and patient are genuinely one human\n- Yellow "Legacy data" banner where a client record carries a different recipient name (legacy artefact, flagged for cleanup)\n\n## Products\n- Products page now has a Default Price column — pre-fills new bookings, can be overridden per customer or per shift\n\n## Lists\n- Clickable rows on Clients and Patients lists\n- "+ New" button top-right\n- "Show archived" toggle',
 '## In flight / known\n- **DEV and PROD share one database** — separation scheduled before Tuniti UAT (TODO #13)\n- **Legacy patient/client conflation** — some records (e.g. Androilla → Praxia) have a recipient name different from the client name on the same persons row. Cleanup migration TODO #14.\n- **BUG-sticky-header** — sticky table headers may scroll out of view on long lists; hard refresh first; if still broken, fallback is numbered pagination.\n- **UAT-product-remap** — 1,619 historical roster shifts still tagged "Day Rate"; Tuniti to walk through and reclassify.\n- **FR-roster-rebuild** — single source of truth for caregiver wages (planned, see TODO).',
 NOW(),
 1);

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

-- ROLLBACK:
--   DROP TABLE IF EXISTS releases;
--   ALTER TABLE users DROP COLUMN last_release_seen_id;
--   DELETE FROM pages WHERE code IN ('whats_new', 'releases_admin');
