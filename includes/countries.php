<?php
/**
 * Countries + dialing-code lookup.
 *
 * For now: South Africa (default) + every African country, A→Z.
 * Rest-of-world expansion deferred — extend the array when needed.
 *
 * Each entry: ['name' => 'South Africa', 'code' => 'ZA', 'dial' => '+27']
 *
 * Public API:
 *   countriesList(): array       — full list, SA first then alphabetical Africa
 *   countriesGrouped(): array    — ['South Africa' => [SA row], 'Africa' => [...rest...]]
 *   defaultCountry(): array      — the SA row (default for new records)
 *   dialPrefix(string $name): ?string  — e.g. 'South Africa' → '+27'
 *   renderCountrySelect(string $name, ?string $selected = null,
 *                       string $id = '', array $attrs = []): void
 *       Echoes a <select> with the SA-then-Africa grouping.
 *   renderDialPrefixSelect(string $name, ?string $selected = '+27',
 *                          string $id = ''): void
 *       Echoes a <select> of just the +xx dial prefixes for phone fields.
 */

function _countriesAfrica(): array
{
    // 53 African countries A→Z, with ISO-2 + dial code.
    return [
        ['name' => 'Algeria',                    'code' => 'DZ', 'dial' => '+213'],
        ['name' => 'Angola',                     'code' => 'AO', 'dial' => '+244'],
        ['name' => 'Benin',                      'code' => 'BJ', 'dial' => '+229'],
        ['name' => 'Botswana',                   'code' => 'BW', 'dial' => '+267'],
        ['name' => 'Burkina Faso',               'code' => 'BF', 'dial' => '+226'],
        ['name' => 'Burundi',                    'code' => 'BI', 'dial' => '+257'],
        ['name' => 'Cabo Verde',                 'code' => 'CV', 'dial' => '+238'],
        ['name' => 'Cameroon',                   'code' => 'CM', 'dial' => '+237'],
        ['name' => 'Central African Republic',   'code' => 'CF', 'dial' => '+236'],
        ['name' => 'Chad',                       'code' => 'TD', 'dial' => '+235'],
        ['name' => 'Comoros',                    'code' => 'KM', 'dial' => '+269'],
        ['name' => 'Congo (Brazzaville)',        'code' => 'CG', 'dial' => '+242'],
        ['name' => 'Congo (DRC)',                'code' => 'CD', 'dial' => '+243'],
        ['name' => "Côte d'Ivoire",              'code' => 'CI', 'dial' => '+225'],
        ['name' => 'Djibouti',                   'code' => 'DJ', 'dial' => '+253'],
        ['name' => 'Egypt',                      'code' => 'EG', 'dial' => '+20'],
        ['name' => 'Equatorial Guinea',          'code' => 'GQ', 'dial' => '+240'],
        ['name' => 'Eritrea',                    'code' => 'ER', 'dial' => '+291'],
        ['name' => 'Eswatini',                   'code' => 'SZ', 'dial' => '+268'],
        ['name' => 'Ethiopia',                   'code' => 'ET', 'dial' => '+251'],
        ['name' => 'Gabon',                      'code' => 'GA', 'dial' => '+241'],
        ['name' => 'Gambia',                     'code' => 'GM', 'dial' => '+220'],
        ['name' => 'Ghana',                      'code' => 'GH', 'dial' => '+233'],
        ['name' => 'Guinea',                     'code' => 'GN', 'dial' => '+224'],
        ['name' => 'Guinea-Bissau',              'code' => 'GW', 'dial' => '+245'],
        ['name' => 'Kenya',                      'code' => 'KE', 'dial' => '+254'],
        ['name' => 'Lesotho',                    'code' => 'LS', 'dial' => '+266'],
        ['name' => 'Liberia',                    'code' => 'LR', 'dial' => '+231'],
        ['name' => 'Libya',                      'code' => 'LY', 'dial' => '+218'],
        ['name' => 'Madagascar',                 'code' => 'MG', 'dial' => '+261'],
        ['name' => 'Malawi',                     'code' => 'MW', 'dial' => '+265'],
        ['name' => 'Mali',                       'code' => 'ML', 'dial' => '+223'],
        ['name' => 'Mauritania',                 'code' => 'MR', 'dial' => '+222'],
        ['name' => 'Mauritius',                  'code' => 'MU', 'dial' => '+230'],
        ['name' => 'Morocco',                    'code' => 'MA', 'dial' => '+212'],
        ['name' => 'Mozambique',                 'code' => 'MZ', 'dial' => '+258'],
        ['name' => 'Namibia',                    'code' => 'NA', 'dial' => '+264'],
        ['name' => 'Niger',                      'code' => 'NE', 'dial' => '+227'],
        ['name' => 'Nigeria',                    'code' => 'NG', 'dial' => '+234'],
        ['name' => 'Rwanda',                     'code' => 'RW', 'dial' => '+250'],
        ['name' => 'São Tomé and Príncipe',      'code' => 'ST', 'dial' => '+239'],
        ['name' => 'Senegal',                    'code' => 'SN', 'dial' => '+221'],
        ['name' => 'Seychelles',                 'code' => 'SC', 'dial' => '+248'],
        ['name' => 'Sierra Leone',               'code' => 'SL', 'dial' => '+232'],
        ['name' => 'Somalia',                    'code' => 'SO', 'dial' => '+252'],
        ['name' => 'South Sudan',                'code' => 'SS', 'dial' => '+211'],
        ['name' => 'Sudan',                      'code' => 'SD', 'dial' => '+249'],
        ['name' => 'Tanzania',                   'code' => 'TZ', 'dial' => '+255'],
        ['name' => 'Togo',                       'code' => 'TG', 'dial' => '+228'],
        ['name' => 'Tunisia',                    'code' => 'TN', 'dial' => '+216'],
        ['name' => 'Uganda',                     'code' => 'UG', 'dial' => '+256'],
        ['name' => 'Zambia',                     'code' => 'ZM', 'dial' => '+260'],
        ['name' => 'Zimbabwe',                   'code' => 'ZW', 'dial' => '+263'],
    ];
}

function defaultCountry(): array
{
    return ['name' => 'South Africa', 'code' => 'ZA', 'dial' => '+27'];
}

function countriesList(): array
{
    return array_merge([defaultCountry()], _countriesAfrica());
}

function countriesGrouped(): array
{
    return [
        'South Africa' => [defaultCountry()],
        'Africa'       => _countriesAfrica(),
    ];
}

function dialPrefix(string $countryName): ?string
{
    foreach (countriesList() as $c) {
        if (strcasecmp($c['name'], $countryName) === 0) {
            return $c['dial'];
        }
    }
    return null;
}

function renderCountrySelect(string $name, ?string $selected = null, string $id = '', array $attrs = []): void
{
    $selected = $selected ?: 'South Africa';
    $idAttr = $id !== '' ? ' id="' . htmlspecialchars($id) . '"' : '';
    $extra = '';
    foreach ($attrs as $k => $v) {
        $extra .= ' ' . htmlspecialchars($k) . '="' . htmlspecialchars($v) . '"';
    }
    echo '<select name="' . htmlspecialchars($name) . '"' . $idAttr . ' class="form-control"' . $extra . '>';
    foreach (countriesGrouped() as $group => $rows) {
        echo '<optgroup label="' . htmlspecialchars($group) . '">';
        foreach ($rows as $c) {
            $sel = (strcasecmp($c['name'], $selected) === 0) ? ' selected' : '';
            echo '<option value="' . htmlspecialchars($c['name']) . '"' . $sel . '>'
               . htmlspecialchars($c['name']) . '</option>';
        }
        echo '</optgroup>';
    }
    echo '</select>';
}

/**
 * Format an E.164 phone for display with readable spacing.
 * SA numbers (+27): "+27 63 239 9863" (2-3-4 grouping for the national
 * number). Other countries: "+DC 123 456 7890" (3-3-4 grouping) as a
 * sensible default. Falls back to raw value when the input isn't E.164.
 */
function formatPhoneForDisplay(?string $phone): string {
    if ($phone === null || $phone === '') {
        return '';
    }
    $p = trim($phone);
    if ($p === '' || $p[0] !== '+') {
        return $p;
    }
    [$dial, $nat] = splitE164($p);
    $nat = preg_replace('/\D/', '', $nat);
    if ($nat === '') {
        return $dial;
    }
    if ($dial === '+27') {
        // SA mobile: 9 digits, show 2-3-4 (mobile prefix + sub + line)
        if (strlen($nat) === 9) {
            return sprintf('%s %s %s %s', $dial,
                substr($nat, 0, 2), substr($nat, 2, 3), substr($nat, 5));
        }
    }
    // Generic: chunks of 3-3-rest
    $groups = [];
    $remaining = $nat;
    while (strlen($remaining) > 4) {
        $groups[] = substr($remaining, 0, 3);
        $remaining = substr($remaining, 3);
    }
    $groups[] = $remaining;
    return $dial . ' ' . implode(' ', $groups);
}

/**
 * Split an E.164 phone (e.g. '+27632399863') into [dial, national_number].
 * Falls back to ['+27', as-is] if no '+' present.
 */
function splitE164(?string $phone): array
{
    if ($phone === null || $phone === '') {
        return ['+27', ''];
    }
    if ($phone[0] !== '+') {
        return ['+27', preg_replace('/\D/', '', $phone)];
    }
    foreach (countriesList() as $c) {
        $dial = $c['dial'];
        if (str_starts_with($phone, $dial)) {
            return [$dial, substr($phone, strlen($dial))];
        }
    }
    return ['+27', substr($phone, 1)];
}

/**
 * Combine dial + national digits → E.164. Strips spaces and non-digits
 * from the national portion. Returns NULL if national is empty.
 */
function joinE164(string $dial, string $national): ?string
{
    $national = preg_replace('/\D/', '', $national);
    if ($national === '') {
        return null;
    }
    // SA legacy entries arrive with leading 0 — drop it before joining
    if ($dial === '+27' && str_starts_with($national, '0')) {
        $national = substr($national, 1);
    }
    return $dial . $national;
}

function renderDialPrefixSelect(string $name, ?string $selected = '+27', string $id = ''): void
{
    $idAttr = $id !== '' ? ' id="' . htmlspecialchars($id) . '"' : '';
    echo '<select name="' . htmlspecialchars($name) . '"' . $idAttr . ' class="form-control" style="max-width:140px;">';
    foreach (countriesGrouped() as $group => $rows) {
        echo '<optgroup label="' . htmlspecialchars($group) . '">';
        foreach ($rows as $c) {
            $sel = ($c['dial'] === $selected) ? ' selected' : '';
            echo '<option value="' . htmlspecialchars($c['dial']) . '"' . $sel . '>'
               . htmlspecialchars($c['code']) . ' ' . htmlspecialchars($c['dial']) . '</option>';
        }
        echo '</optgroup>';
    }
    echo '</select>';
}
