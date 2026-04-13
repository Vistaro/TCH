<?php
/**
 * App-level settings (system_settings table).
 *
 *   getSetting('tuniti.office.lat')           — string or null
 *   getSetting('tuniti.office.lat', 0.0)      — typed default
 *   setSetting('key', 'value', $userId = null)
 *   tunitiOfficeCoords(): ?array              — ['lat'=>float,'lng'=>float,'label'=>string]
 */

require_once __DIR__ . '/db.php';

function getSetting(string $key, $default = null) {
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $stmt = getDB()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        $cache[$key] = ($v === false) ? null : $v;
    }
    return $cache[$key] ?? $default;
}

function setSetting(string $key, ?string $value, ?int $userId = null): void {
    getDB()->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, updated_by_user_id)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                 updated_by_user_id = VALUES(updated_by_user_id)'
    )->execute([$key, $value, $userId]);
}

function tunitiOfficeCoords(): ?array {
    $lat = getSetting('tuniti.office.lat');
    $lng = getSetting('tuniti.office.lng');
    if ($lat === null || $lng === null || $lat === '' || $lng === '') return null;
    return [
        'lat'   => (float)$lat,
        'lng'   => (float)$lng,
        'label' => (string)getSetting('tuniti.office.label', 'Tuniti HQ'),
    ];
}

/**
 * Haversine distance in km between two lat/lng pairs.
 */
function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}
