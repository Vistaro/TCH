-- ─────────────────────────────────────────────────────────────
--  041_quotes_and_rate_override.sql
--  Quote builder (FR-C) + rate-override permission (FR-E).
--
--  Quotes share the contracts / contract_lines tables (a draft-status
--  contract IS a quote — single source of truth). What this migration
--  adds:
--    - contract_lines.rate_override_reason — free text captured when
--      the quoter sets a rate different from product_billing_rates.rate
--      for that unit. Required-when-different, audit-logged.
--    - pages entries for 'quotes' (the quote-building surface) and
--      'quotes_rate_override' (gate on editing a rate away from the
--      product default). Role grants follow the contracts pattern:
--      Super Admin everything, Admin everything except delete, but
--      rate-override stays Super-Admin-only until Ross grants it
--      to specific roles.
--
--  Rollback:
--    ALTER TABLE contract_lines DROP COLUMN rate_override_reason;
--    DELETE FROM role_permissions WHERE page_id IN
--      (SELECT id FROM pages WHERE code IN ('quotes','quotes_rate_override'));
--    DELETE FROM pages WHERE code IN ('quotes','quotes_rate_override');
-- ─────────────────────────────────────────────────────────────

SET NAMES utf8mb4;

-- 1. contract_lines gain a rate_override_reason column.
-- Nullable. Only populated when the quoter overrides the product's
-- default rate for the chosen billing unit. UI validation makes it
-- required when the rate differs from the default; DB just stores.
ALTER TABLE contract_lines
    ADD COLUMN rate_override_reason VARCHAR(255) DEFAULT NULL
        COMMENT 'Captured when the quoter sets bill_rate different from product_billing_rates.rate for the chosen billing_freq. Audit-logged on save. NULL = no override.'
        AFTER notes;

-- 2. Register the new admin pages.
INSERT INTO pages (code, label, section, description, sort_order) VALUES
  ('quotes',                 'Quotes',              'records', 'Quote builder — draft a quote for a client/patient, email or portal-send for acceptance. Accepted quotes become active contracts.', 34),
  ('quotes_rate_override',   'Rate override',       'records', 'Permission gate. Users with edit here can set a quote-line rate different from the product standard rate for the chosen billing unit. Every override captures a reason and is audit-logged.', 41)
ON DUPLICATE KEY UPDATE label=VALUES(label), description=VALUES(description), sort_order=VALUES(sort_order);

-- 3. Grant quotes page: Super Admin full CRUD; Admin read + create + edit (no delete).
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT r.id, p.id, 1, 1, 1, 1
  FROM roles r
  JOIN pages p ON p.code = 'quotes'
 WHERE r.slug = 'super_admin';

INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT r.id, p.id, 1, 1, 1, 0
  FROM roles r
  JOIN pages p ON p.code = 'quotes'
 WHERE r.slug = 'admin';

-- 4. Grant quotes_rate_override: Super Admin only. Admin does NOT get it
--    by default — Ross can extend to specific users via the Roles matrix
--    when ready.
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT r.id, p.id, 1, 1, 1, 1
  FROM roles r
  JOIN pages p ON p.code = 'quotes_rate_override'
 WHERE r.slug = 'super_admin';
