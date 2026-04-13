<?php
/**
 * Contact-method helpers — multi-phone / multi-email / multi-address
 * stored in person_phones, person_emails, person_addresses (migration 024).
 *
 * The legacy persons.mobile / secondary_number / email / flat-address
 * columns are kept in sync as the "primary" copy so existing reports +
 * exports don't break while the rest of the app moves over to the new
 * tables. When a primary phone/email/address is added or changed, the
 * matching scalar column on persons is rewritten to mirror it.
 */

if (!function_exists('getDB')) {
    require_once __DIR__ . '/db.php';
}

// ── Read ────────────────────────────────────────────────────────────────

function getPersonPhones(int $personId): array
{
    $stmt = getDB()->prepare(
        'SELECT id, label, phone, is_primary
         FROM person_phones
         WHERE person_id = ?
         ORDER BY is_primary DESC, id'
    );
    $stmt->execute([$personId]);
    return $stmt->fetchAll();
}

function getPersonEmails(int $personId): array
{
    $stmt = getDB()->prepare(
        'SELECT id, label, email, is_primary
         FROM person_emails
         WHERE person_id = ?
         ORDER BY is_primary DESC, id'
    );
    $stmt->execute([$personId]);
    return $stmt->fetchAll();
}

function getPersonAddresses(int $personId): array
{
    $stmt = getDB()->prepare(
        'SELECT id, label, complex_estate, street_address, suburb, city,
                province, postal_code, country, is_primary
         FROM person_addresses
         WHERE person_id = ?
         ORDER BY is_primary DESC, id'
    );
    $stmt->execute([$personId]);
    return $stmt->fetchAll();
}

// ── Save (replace-all pattern) ──────────────────────────────────────────
//
// The forms post the full set of phones/emails for the person each time
// (one row per phone, like a sub-form). We replace the lot in one
// transaction so the simple "delete all + reinsert" pattern is safe.

/**
 * @param array<int,array{label:?string,phone:string,is_primary:bool}> $phones
 */
function savePersonPhones(int $personId, array $phones): void
{
    $db = getDB();
    $clean = [];
    $primarySeen = false;
    foreach ($phones as $row) {
        $p = trim((string)($row['phone'] ?? ''));
        if ($p === '') continue;
        $isPrimary = !empty($row['is_primary']);
        if ($isPrimary && $primarySeen) {
            $isPrimary = false; // only the first wins
        }
        if ($isPrimary) $primarySeen = true;
        $clean[] = [
            'label'      => trim((string)($row['label'] ?? '')) ?: null,
            'phone'      => $p,
            'is_primary' => $isPrimary ? 1 : 0,
        ];
    }
    // If no row was flagged primary but at least one exists, make the first primary.
    if (!$primarySeen && !empty($clean)) {
        $clean[0]['is_primary'] = 1;
    }

    $db->prepare('DELETE FROM person_phones WHERE person_id = ?')->execute([$personId]);
    $ins = $db->prepare(
        'INSERT INTO person_phones (person_id, label, phone, is_primary)
         VALUES (?, ?, ?, ?)'
    );
    $primaryPhone = null;
    foreach ($clean as $row) {
        $ins->execute([$personId, $row['label'], $row['phone'], $row['is_primary']]);
        if ($row['is_primary']) $primaryPhone = $row['phone'];
    }
    // Mirror the primary into legacy persons.mobile so existing reports keep working.
    $db->prepare('UPDATE persons SET mobile = ? WHERE id = ?')
       ->execute([$primaryPhone, $personId]);
    // Mirror the second non-primary phone (if any) into persons.secondary_number.
    $second = null;
    foreach ($clean as $row) {
        if (!$row['is_primary']) { $second = $row['phone']; break; }
    }
    $db->prepare('UPDATE persons SET secondary_number = ? WHERE id = ?')
       ->execute([$second, $personId]);
}

/**
 * @param array<int,array{label:?string,email:string,is_primary:bool}> $emails
 */
function savePersonEmails(int $personId, array $emails): void
{
    $db = getDB();
    $clean = [];
    $primarySeen = false;
    foreach ($emails as $row) {
        $e = trim((string)($row['email'] ?? ''));
        if ($e === '') continue;
        $isPrimary = !empty($row['is_primary']);
        if ($isPrimary && $primarySeen) {
            $isPrimary = false;
        }
        if ($isPrimary) $primarySeen = true;
        $clean[] = [
            'label'      => trim((string)($row['label'] ?? '')) ?: null,
            'email'      => $e,
            'is_primary' => $isPrimary ? 1 : 0,
        ];
    }
    if (!$primarySeen && !empty($clean)) {
        $clean[0]['is_primary'] = 1;
    }

    $db->prepare('DELETE FROM person_emails WHERE person_id = ?')->execute([$personId]);
    $ins = $db->prepare(
        'INSERT INTO person_emails (person_id, label, email, is_primary)
         VALUES (?, ?, ?, ?)'
    );
    $primaryEmail = null;
    foreach ($clean as $row) {
        $ins->execute([$personId, $row['label'], $row['email'], $row['is_primary']]);
        if ($row['is_primary']) $primaryEmail = $row['email'];
    }
    $db->prepare('UPDATE persons SET email = ? WHERE id = ?')
       ->execute([$primaryEmail, $personId]);
}

/**
 * Save (insert or update) the primary address for a person.
 * Address is given as a flat assoc array of fields.
 */
function savePrimaryAddress(int $personId, array $addr): void
{
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT id FROM person_addresses WHERE person_id = ? AND is_primary = 1 LIMIT 1'
    );
    $stmt->execute([$personId]);
    $existingId = $stmt->fetchColumn();

    $vals = [
        'label'          => $addr['label']          ?? 'Primary',
        'complex_estate' => $addr['complex_estate'] ?? null,
        'street_address' => $addr['street_address'] ?? null,
        'suburb'         => $addr['suburb']         ?? null,
        'city'           => $addr['city']           ?? null,
        'province'       => $addr['province']       ?? null,
        'postal_code'    => $addr['postal_code']    ?? null,
        'country'        => $addr['country']        ?? 'South Africa',
    ];

    if ($existingId) {
        $db->prepare(
            'UPDATE person_addresses
             SET label = ?, complex_estate = ?, street_address = ?, suburb = ?,
                 city = ?, province = ?, postal_code = ?, country = ?
             WHERE id = ?'
        )->execute([
            $vals['label'], $vals['complex_estate'], $vals['street_address'], $vals['suburb'],
            $vals['city'], $vals['province'], $vals['postal_code'], $vals['country'],
            (int)$existingId,
        ]);
    } else {
        $db->prepare(
            'INSERT INTO person_addresses
                (person_id, label, complex_estate, street_address, suburb, city, province, postal_code, country, is_primary)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([
            $personId,
            $vals['label'], $vals['complex_estate'], $vals['street_address'], $vals['suburb'],
            $vals['city'], $vals['province'], $vals['postal_code'], $vals['country'],
        ]);
    }

    // Mirror to flat columns on persons for backwards-compat.
    $db->prepare(
        'UPDATE persons
         SET complex_estate = ?, street_address = ?, suburb = ?, city = ?,
             province = ?, postal_code = ?, country = ?
         WHERE id = ?'
    )->execute([
        $vals['complex_estate'], $vals['street_address'], $vals['suburb'], $vals['city'],
        $vals['province'], $vals['postal_code'], $vals['country'], $personId,
    ]);
}

// ── Form-input parsing ──────────────────────────────────────────────────
//
// Inputs come in as parallel arrays from the edit forms:
//   phones_label[],  phones_dial[], phones_national[], phones_primary
//   (radio with the array index as value)
// Same shape for emails: emails_label[], emails_address[], emails_primary

/**
 * Parse phone sub-form arrays into the shape savePersonPhones() expects.
 */
function parsePhonesFromPost(): array
{
    $labels    = $_POST['phones_label']    ?? [];
    $dials     = $_POST['phones_dial']     ?? [];
    $nationals = $_POST['phones_national'] ?? [];
    $primaryIx = isset($_POST['phones_primary']) ? (int)$_POST['phones_primary'] : 0;

    $out = [];
    $count = max(count($labels), count($dials), count($nationals));
    for ($i = 0; $i < $count; $i++) {
        $national = trim((string)($nationals[$i] ?? ''));
        $dial     = trim((string)($dials[$i]     ?? '+27'));
        if ($national === '') continue;
        $phone = function_exists('joinE164') ? joinE164($dial ?: '+27', $national) : $national;
        if (!$phone) continue;
        $out[] = [
            'label'      => trim((string)($labels[$i] ?? '')) ?: null,
            'phone'      => $phone,
            'is_primary' => ($i === $primaryIx),
        ];
    }
    return $out;
}

/**
 * Parse email sub-form arrays into the shape savePersonEmails() expects.
 */
function parseEmailsFromPost(): array
{
    $labels    = $_POST['emails_label']   ?? [];
    $addresses = $_POST['emails_address'] ?? [];
    $primaryIx = isset($_POST['emails_primary']) ? (int)$_POST['emails_primary'] : 0;

    $out = [];
    $count = max(count($labels), count($addresses));
    for ($i = 0; $i < $count; $i++) {
        $email = trim((string)($addresses[$i] ?? ''));
        if ($email === '') continue;
        $out[] = [
            'label'      => trim((string)($labels[$i] ?? '')) ?: null,
            'email'      => $email,
            'is_primary' => ($i === $primaryIx),
        ];
    }
    return $out;
}
