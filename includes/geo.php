<?php
/**
 * Geo helpers — distance calculations, operating-radius zones.
 *
 * Part of FR-N (geo + operating radius + travel charging). Phase 1
 * (this file) covers the pure-math distance primitives. Phase 2 will
 * add geocoding integration (OpenStreetMap Nominatim) that populates
 * persons.latitude / persons.longitude from saved addresses.
 */

if (!defined('APP_ROOT')) {
    die('Direct access not permitted.');
}

/**
 * Haversine straight-line distance between two WGS84 coordinates, in km.
 *
 * Accurate to within ~0.5% for distances relevant to TCH (< 100km).
 * Returns null if any input is null — caller decides how to render that.
 */
function haversineKm(?float $lat1, ?float $lon1, ?float $lat2, ?float $lon2): ?float
{
    if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) {
        return null;
    }
    $earthRadiusKm = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * $earthRadiusKm * asin(min(1.0, sqrt($a)));
}

/**
 * Distance from Tuniti's office to a (lat, lng) pair, in km. Null if
 * either Tuniti's coords or the target coords are missing.
 */
function distanceFromTunitiKm(?float $lat, ?float $lng): ?float
{
    require_once APP_ROOT . '/includes/settings.php';
    $office = tunitiOfficeCoords();
    if (!$office || $lat === null || $lng === null) {
        return null;
    }
    return haversineKm((float)$office['lat'], (float)$office['lng'], $lat, $lng);
}

/**
 * Classify a distance against the operating-radius settings.
 *
 * Returns one of:
 *   'unknown' — no coords available
 *   'green'   — within warning_radius_km (comfort zone)
 *   'amber'   — between warning_radius and accepted_radius
 *   'red'     — beyond accepted_radius (scheduling flags a warning)
 */
function distanceBand(?float $km): string
{
    if ($km === null) return 'unknown';
    require_once APP_ROOT . '/includes/settings.php';
    $warn     = (float)getSetting('operations.warning_radius_km',  '15');
    $accepted = (float)getSetting('operations.accepted_radius_km', '25');
    if ($km <= $warn)     return 'green';
    if ($km <= $accepted) return 'amber';
    return 'red';
}

/**
 * Render a distance + band as a small UI chip. Safe to echo.
 */
function renderDistanceBadge(?float $km): string
{
    $band  = distanceBand($km);
    $label = $km === null ? '—' : number_format($km, 1) . ' km';
    $colour = match ($band) {
        'green'   => '#198754',
        'amber'   => '#fd7e14',
        'red'     => '#dc3545',
        default   => '#adb5bd',
    };
    $title = match ($band) {
        'green'   => 'Inside comfort radius',
        'amber'   => 'Inside accepted radius (outside comfort zone)',
        'red'     => 'Beyond accepted operating radius — scheduling will warn',
        default   => 'No coordinates on file — needs geocoding',
    };
    return '<span title="' . htmlspecialchars($title) . '" style="color:' . $colour . ';font-variant-numeric:tabular-nums;">' . htmlspecialchars($label) . '</span>';
}
