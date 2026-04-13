-- ================================================================
--  020_users_currency_and_fx_rates.sql
--  - Add currency preference to users (default ZAR).
--  - New fx_rates table to cache live mid-rates from
--    api.exchangerate.host (free, no auth). Refreshed once a day
--    via the /admin/config/fx-rates page (manual or auto on stale).
--    Stored as 1 ZAR = N currency.
-- ================================================================

START TRANSACTION;

ALTER TABLE users
    ADD COLUMN currency_code CHAR(3) NOT NULL DEFAULT 'ZAR' AFTER avatar_path;

CREATE TABLE IF NOT EXISTS fx_rates (
    currency_code  CHAR(3) NOT NULL,
    rate_per_zar   DECIMAL(18, 8) NOT NULL,
    fetched_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source         VARCHAR(60) NOT NULL DEFAULT 'exchangerate.host',
    PRIMARY KEY (currency_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the base row so the lookup always finds ZAR.
INSERT IGNORE INTO fx_rates (currency_code, rate_per_zar, source)
VALUES ('ZAR', 1.00000000, 'base');

INSERT IGNORE INTO pages (code, label, section, description, sort_order) VALUES
    ('config_fx_rates', 'FX Rates', 'admin', 'Live foreign exchange rates (mid)', 95);

INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT 1, p.id, 1, 1, 1, 1
FROM pages p
WHERE p.code = 'config_fx_rates'
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = 1 AND rp.page_id = p.id);

COMMIT;
