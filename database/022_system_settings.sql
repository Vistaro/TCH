-- ================================================================
--  022_system_settings.sql
--  Generic key/value store for app-level settings (Tuniti GPS,
--  default rates, brand colours, etc.). One source of truth, edited
--  via the future /admin/config/settings page.
-- ================================================================

START TRANSACTION;

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   VARCHAR(80) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    label         VARCHAR(120) NULL,
    description   VARCHAR(255) NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by_user_id INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_settings (setting_key, setting_value, label, description) VALUES
    ('tuniti.office.lat', '-25.861855576655785', 'Tuniti office latitude',  'Reference point for caregiver / patient distance calculations'),
    ('tuniti.office.lng', '28.258634056391095',  'Tuniti office longitude', 'Reference point for caregiver / patient distance calculations'),
    ('tuniti.office.label', 'Tuniti HQ',         'Tuniti office label',     'Display name shown next to distance figures')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

COMMIT;
