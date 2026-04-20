<?php
/**
 * Geocoding helper — Nominatim (OpenStreetMap) backend.
 *
 * FR-N Phase 2. Populates persons.latitude / persons.longitude from
 * saved addresses so the distance math added in Phase 1 has real
 * inputs to work with.
 *
 * Backend: OpenStreetMap Nominatim public API.
 *   - Free tier. No API key.
 *   - Rate limit per their usage policy: ≤ 1 request/sec, identified
 *     User-Agent header required.
 *     https://operations.osmfoundation.org/policies/nominatim/
 *   - We honour 1 req/sec via a tiny file-lock throttle so concurrent
 *     web requests don't stampede.
 *
 * Not a heavy abstraction — one function, one call site. If we ever
 * switch to Google/MapQuest/etc., swap the body of geocodeAddress()
 * behind the same signature.
 */

if (!defined('APP_ROOT')) {
    die('Direct access not permitted.');
}

/**
 * Geocode a free-text address to (lat, lng).
 *
 * Returns:
 *   ['lat' => float, 'lng' => float, 'display_name' => string] on hit
 *   null on no result, API error, or empty input
 *
 * Honours Nominatim's 1-req/sec rate cap via a file-lock throttle.
 * Swallows transient errors (returns null); callers decide fallback.
 */
function geocodeAddress(string $addressText, string $countryCode = 'za'): ?array
{
    $addressText = trim($addressText);
    if ($addressText === '') return null;

    // Rate-limit: Nominatim asks for ≤ 1 req/sec. File-lock throttle.
    $lockFile = sys_get_temp_dir() . '/tch-nominatim-throttle.lock';
    $fp = fopen($lockFile, 'c');
    if ($fp === false) {
        error_log('geocodeAddress: failed to open throttle lockfile');
    } else {
        if (flock($fp, LOCK_EX)) {
            $lastTsStr = file_exists($lockFile) ? trim((string)@file_get_contents($lockFile)) : '0';
            $lastTs = (float)($lastTsStr ?: 0);
            $now = microtime(true);
            $delta = $now - $lastTs;
            if ($delta < 1.0 && $delta > 0) {
                usleep((int)((1.0 - $delta) * 1_000_000));
            }
            @file_put_contents($lockFile, (string)microtime(true));
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    $url = 'https://nominatim.openstreetmap.org/search'
         . '?format=json'
         . '&limit=1'
         . '&addressdetails=0'
         . '&countrycodes=' . urlencode($countryCode)
         . '&q=' . urlencode($addressText);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_USERAGENT      => 'TCH-Placements/1.0 (hello@tch.intelligentae.co.uk)',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_FAILONERROR    => false,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $httpCode !== 200) {
        error_log('geocodeAddress failed: HTTP ' . $httpCode . ' err=' . $err);
        return null;
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded) || empty($decoded)) return null;
    $first = $decoded[0] ?? null;
    if (!is_array($first) || !isset($first['lat'], $first['lon'])) return null;

    return [
        'lat' => (float)$first['lat'],
        'lng' => (float)$first['lon'],
        'display_name' => (string)($first['display_name'] ?? ''),
    ];
}

/**
 * Look up a person's address + write lat/lng back in one call.
 *
 * Returns true if lat/lng were updated, false on no-result.
 * Uses whatever address fields exist on the persons row (care_address,
 * home_address, address_line1, etc.) — first non-empty wins.
 *
 * Idempotent — won't re-geocode if coords already present and
 * $force === false.
 */
function geocodePersonAndSave(PDO $db, int $personId, bool $force = false): bool
{
    $row = $db->prepare("SELECT * FROM persons WHERE id = ?");
    $row->execute([$personId]);
    $person = $row->fetch(PDO::FETCH_ASSOC);
    if (!$person) return false;

    if (!$force
        && $person['latitude']  !== null
        && $person['longitude'] !== null) {
        return false; // already has coords; skip
    }

    // Compose the address from the standard TCH persons columns:
    // street_address + suburb + city + postal_code + ", South Africa".
    // Each piece only contributes if non-empty.
    $parts = [];
    foreach (['street_address', 'suburb', 'city', 'postal_code'] as $col) {
        $val = trim((string)($person[$col] ?? ''));
        if ($val !== '') $parts[] = $val;
    }
    if (empty($parts)) return false;
    $parts[] = 'South Africa';
    $addressText = implode(', ', $parts);

    $hit = geocodeAddress($addressText);
    if (!$hit) {
        // Stamp the attempt time anyway so we don't hammer a bad address
        $db->prepare("UPDATE persons SET geocoded_at = NOW() WHERE id = ?")->execute([$personId]);
        return false;
    }

    $db->prepare(
        "UPDATE persons
            SET latitude = ?, longitude = ?, geocoded_at = NOW()
          WHERE id = ?"
    )->execute([$hit['lat'], $hit['lng'], $personId]);
    return true;
}

/**
 * Find persons who need geocoding (lat/lng null OR stale and force-requested).
 * Useful for admin backfill sweeps.
 */
function listPersonsNeedingGeocode(PDO $db, int $limit = 25, bool $onlyPatients = true): array
{
    $sql = "SELECT p.id, p.full_name,
                   TRIM(CONCAT_WS(', ',
                        NULLIF(p.street_address, ''),
                        NULLIF(p.suburb, ''),
                        NULLIF(p.city, ''),
                        NULLIF(p.postal_code, ''))) AS address_hint
              FROM persons p";
    if ($onlyPatients) {
        $sql .= " JOIN patients pt ON pt.person_id = p.id";
    }
    $sql .= " WHERE p.latitude IS NULL AND p.longitude IS NULL
                AND p.archived_at IS NULL
              ORDER BY p.id
              LIMIT " . max(1, min(500, $limit));
    $stmt = $db->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
