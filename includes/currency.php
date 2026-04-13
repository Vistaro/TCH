<?php
/**
 * Currency display + FOREX cache helpers.
 *
 * ZAR is the base. Every value stored in the system is in ZAR. Each user
 * may pick a display currency on their profile (default ZAR). When the
 * user's display currency differs from ZAR, money values render with the
 * ZAR figure on top and the converted figure smaller underneath.
 *
 * Rates: cached in `fx_rates` table as (currency_code, rate_per_zar).
 * `rate_per_zar` is the value of 1 ZAR in that currency
 * (e.g. for USD it's around 0.054 — meaning 1 ZAR = USD 0.054).
 *
 * Refresh: fetched from api.exchangerate.host (free, no API key) via
 * `refreshFxRates()`. Called manually from /admin/config/fx-rates or
 * automatically when rates are >24h old (refreshFxRatesIfStale()).
 *
 * Public API:
 *   userCurrency(): string                          — current user's pref ('ZAR' default)
 *   fxRate(string $code): ?float                    — lookup; returns NULL if unknown
 *   convertZarTo(float $zar, string $code): ?float
 *   formatMoney(float $zar, ?string $userCcy = null): string  — HTML
 *   refreshFxRates(): array                         — performs fetch, returns ['ok'=>bool,'msg'=>...]
 *   refreshFxRatesIfStale(int $maxHours = 24): void — background-friendly
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

const FX_API_URL = 'https://open.er-api.com/v6/latest/ZAR';

function userCurrency(): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    if (!isLoggedIn()) {
        $cached = 'ZAR';
        return $cached;
    }
    $stmt = getDB()->prepare('SELECT currency_code FROM users WHERE id = ?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $cached = strtoupper((string)($stmt->fetchColumn() ?: 'ZAR'));
    return $cached;
}

function fxRate(string $code): ?float {
    $code = strtoupper(trim($code));
    if ($code === 'ZAR') return 1.0;
    $stmt = getDB()->prepare('SELECT rate_per_zar FROM fx_rates WHERE currency_code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetchColumn();
    return $r === false ? null : (float)$r;
}

function convertZarTo(float $zar, string $code): ?float {
    $r = fxRate($code);
    return $r === null ? null : $zar * $r;
}

function _currencySymbol(string $code): string {
    return match (strtoupper($code)) {
        'ZAR' => 'R',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'JPY' => '¥',
        'AUD' => 'A$',
        'CAD' => 'C$',
        'CHF' => 'CHF ',
        'CNY' => '¥',
        'INR' => '₹',
        'NGN' => '₦',
        'KES' => 'KSh ',
        'GHS' => 'GH₵',
        default => $code . ' ',
    };
}

/**
 * Render a money value.
 * - Always shows ZAR with R prefix.
 * - If the (logged-in) user's preferred currency != ZAR AND we have a rate,
 *   shows the converted value smaller underneath.
 */
function formatMoney(float $zar, ?string $userCcy = null): string {
    $userCcy = $userCcy ?: userCurrency();
    $primary = 'R' . number_format($zar, 0);
    if ($userCcy === 'ZAR') {
        return '<span class="money">' . $primary . '</span>';
    }
    $converted = convertZarTo($zar, $userCcy);
    if ($converted === null) {
        return '<span class="money">' . $primary . '</span>';
    }
    return '<span class="money">' . $primary
         . '<small style="display:block;font-size:0.7em;color:#6c757d;">'
         . _currencySymbol($userCcy) . number_format($converted, 0)
         . '</small></span>';
}

/**
 * Fetch fresh rates from exchangerate.host. Returns ['ok','msg','count'].
 * Truncates and rewrites the fx_rates table (apart from the ZAR base row).
 */
function refreshFxRates(): array {
    $ctx = stream_context_create(['http' => ['timeout' => 8]]);
    $raw = @file_get_contents(FX_API_URL, false, $ctx);
    if ($raw === false) {
        return ['ok' => false, 'msg' => 'Could not reach exchangerate.host', 'count' => 0];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['rates'])) {
        return ['ok' => false, 'msg' => 'Unexpected response from FX provider', 'count' => 0];
    }
    $sourceLabel = is_string($data['provider'] ?? null) ? $data['provider'] : 'open.er-api.com';
    $db = getDB();
    $upd = $db->prepare(
        'INSERT INTO fx_rates (currency_code, rate_per_zar, source, fetched_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE rate_per_zar = VALUES(rate_per_zar),
                                 source = VALUES(source),
                                 fetched_at = NOW()'
    );
    $count = 0;
    foreach ($data['rates'] as $code => $rate) {
        $code = strtoupper((string)$code);
        if (!preg_match('/^[A-Z]{3}$/', $code)) continue;
        $upd->execute([$code, (float)$rate, substr($sourceLabel, 0, 60)]);
        $count++;
    }
    return ['ok' => true, 'msg' => "Refreshed $count rates", 'count' => $count];
}

function refreshFxRatesIfStale(int $maxHours = 24): void {
    $db = getDB();
    $oldest = $db->query(
        "SELECT MAX(fetched_at) FROM fx_rates WHERE currency_code != 'ZAR'"
    )->fetchColumn();
    if ($oldest === null || $oldest === false) {
        refreshFxRates();
        return;
    }
    if (strtotime((string)$oldest) < time() - $maxHours * 3600) {
        refreshFxRates();
    }
}
