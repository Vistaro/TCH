-- ─────────────────────────────────────────────────────────────
--  044_test_data_flags_and_dev_tools.sql
--
--  Test-data infrastructure per the CLAUDE.md Standing Order
--  "Flag test data. Use a marker (is_test_data etc.); delete after
--  verification." Pattern adapted from Nexus-CRM
--  (/c/ClaudeCode/Nexus-CRM/migrations/003_test_data_flag.sql).
--
--  Tables flagged:
--    enquiries       — Ross specified
--    opportunities   — Ross specified
--    persons         — needed so test-person records get wiped too
--    clients         — test clients wiped on wipe
--    patients        — test patients wiped on wipe
--
--  Each column:
--    is_test_data TINYINT(1) NOT NULL DEFAULT 0
--  with an index for fast filtering.
--
--  Real data (is_test_data = 0) is never touched by the wipe or
--  seed functions — the WHERE clause filters on the flag, so even
--  a buggy seeder can't corrupt production rows. That said,
--  belt-and-braces: the dev-tools page also env-gates against
--  APP_ENV = 'production' (per Nexus-CRM's "if rebuilding, add this"
--  lesson).
--
--  Registers two pages:
--    dev_tools           — Dev Tools landing (super_admin only)
--    dev_tools_test_data — Test-data seeder + wiper (super_admin only)
--
--  Admin role gets NO access to either (per FR-R release-gating —
--  dev tools are never released to Tuniti).
--
--  Rollback:
--    ALTER TABLE <each> DROP COLUMN is_test_data;
--    DELETE FROM pages WHERE code IN ('dev_tools','dev_tools_test_data');
-- ─────────────────────────────────────────────────────────────

SET NAMES utf8mb4;

-- ── 1. is_test_data columns + indexes
ALTER TABLE enquiries
    ADD COLUMN is_test_data TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Flag for seeded test data. Filtered on wipe; never set on real rows.',
    ADD INDEX idx_enq_test_data (is_test_data);

ALTER TABLE opportunities
    ADD COLUMN is_test_data TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Flag for seeded test data. Filtered on wipe; never set on real rows.',
    ADD INDEX idx_opp_test_data (is_test_data);

ALTER TABLE persons
    ADD COLUMN is_test_data TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Flag for seeded test data. Filtered on wipe; never set on real rows.',
    ADD INDEX idx_persons_test_data (is_test_data);

ALTER TABLE clients
    ADD COLUMN is_test_data TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Flag for seeded test data. Filtered on wipe; never set on real rows.',
    ADD INDEX idx_clients_test_data (is_test_data);

ALTER TABLE patients
    ADD COLUMN is_test_data TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Flag for seeded test data. Filtered on wipe; never set on real rows.',
    ADD INDEX idx_patients_test_data (is_test_data);

-- ── 2. Register dev-tools pages
INSERT INTO pages (code, label, section, description, sort_order) VALUES
  ('dev_tools',           'Dev Tools',       'admin', 'Internal developer utilities — never released to Tuniti.',                                              90),
  ('dev_tools_test_data', 'Test Data',       'admin', 'Seed + wipe synthetic test data (enquiries, opportunities). Super-admin only, env-gated against PROD.', 91)
ON DUPLICATE KEY UPDATE label=VALUES(label), description=VALUES(description), sort_order=VALUES(sort_order);

-- ── 3. Grant super_admin full CRUD. Admin role gets NOTHING (FR-R).
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT r.id, p.id, 1, 1, 1, 1
  FROM roles r
  JOIN pages p ON p.code IN ('dev_tools','dev_tools_test_data')
 WHERE r.slug = 'super_admin';
