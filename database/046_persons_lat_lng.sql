-- ─────────────────────────────────────────────────────────────
--  046_persons_lat_lng.sql
--
--  FR-N Phase 1 foundation: lat/lng on persons for distance from
--  Tuniti office calculations. Tuniti GPS already in system_settings
--  (tuniti.office.lat / .lng); this adds the other side of the pair.
--
--  Geocoding is NOT wired in this migration — columns start NULL and
--  get populated by a future geocoding job (FR-N Phase 2). Patient
--  list page uses whatever's populated; NULL rows show "—".
--
--  Additional system_settings: operations.accepted_radius_km + warning
--  threshold. Defaults Ross-adjustable via admin UI when ready.
--
--  Rollback:
--    ALTER TABLE persons DROP COLUMN latitude, DROP COLUMN longitude,
--      DROP COLUMN geocoded_at;
--    DELETE FROM system_settings WHERE setting_key LIKE 'operations.%';
-- ─────────────────────────────────────────────────────────────

SET NAMES utf8mb4;

ALTER TABLE persons
    ADD COLUMN latitude  DECIMAL(10,7) DEFAULT NULL
        COMMENT 'WGS84 latitude. NULL until geocoded. Computed from care_address or home_address.'
        AFTER id_passport,
    ADD COLUMN longitude DECIMAL(10,7) DEFAULT NULL
        COMMENT 'WGS84 longitude. NULL until geocoded.'
        AFTER latitude,
    ADD COLUMN geocoded_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'When the lat/lng was last resolved. Re-geocode if address changes after this timestamp.'
        AFTER longitude;

-- Operating-radius settings
INSERT INTO system_settings (setting_key, setting_value, label, description) VALUES
  ('operations.accepted_radius_km', '25', 'Accepted operating radius (km)',
   'Patients within this straight-line distance from Tuniti office are inside the standard accept band. Beyond this triggers a warning on scheduling but is not hard-blocked.'),
  ('operations.warning_radius_km', '15', 'Warning radius (km)',
   'Patients within this distance are green (inside comfort zone). Between warning and accepted is amber. Beyond accepted is red.'),
  ('operations.travel_surcharge_per_km_rand', '0', 'Travel surcharge per km (R)',
   'Per-km travel surcharge rate applied when a quote line needs a travel component. Set to 0 for no charge; non-zero to price travel into quotes.')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
