<?php
/**
 * Patient detail page — /admin/patients/{id}
 *
 * Mirrors client_view.php with patient-specific tweaks:
 *   - Header shows the bill-paying Client (clickable)
 *   - No billing section (billing config lives on the Client)
 *   - "Same person" toggle creates a clients row for this person instead
 *
 * Permission: patient_view.read for view; patient_view.edit for inline
 * edits, archive, and "Same person" toggle.
 */

require_once APP_ROOT . '/includes/activities_render.php';
require_once APP_ROOT . '/includes/countries.php';
require_once APP_ROOT . '/includes/contact_methods.php';

$pageTitle = 'Patient';
$activeNav = 'patients';

$personId = (int)($_GET['patient_id'] ?? 0);
$db       = getDB();
$canEdit  = userCan('patient_view', 'edit');

$editableSections = [
    'personal' => ['table' => 'persons', 'cols' => ['salutation','first_name','middle_names','last_name','known_as','title','initials','id_passport','dob','gender','nationality','home_language','other_language']],
    'address'  => ['table' => 'persons', 'cols' => ['complex_estate','street_address','suburb','city','province','postal_code','country']],
    'nok'      => ['table' => 'persons', 'cols' => ['nok_name','nok_relationship','nok_contact','nok_email']],
];

$flash = ''; $flashType = 'success';

$redirectWithFlash = function (int $personId, string $msg, string $type = 'success'): void {
    header('Location: ' . APP_URL . '/admin/patients/' . $personId
           . '?msg=' . urlencode($msg) . '&type=' . urlencode($type));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'save_activity' && $canEdit) {
    $res = saveActivityFromPost('persons', $personId);
    if ($res['ok']) $redirectWithFlash($personId, $res['msg'], 'success');
    $flash = $res['msg']; $flashType = 'error';
}

// ── Save care-needs profile (UPSERT on patient_care_needs) ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'save_care_needs'
        && $canEdit && userCan('patient_care_needs', 'edit')) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $redirectWithFlash($personId, 'Invalid form submission.', 'error');

    $fields = ['medical_conditions','allergies','medications','dnr_status','dnr_notes',
               'mobility_notes','hygiene_notes','cognitive_notes','emotional_notes',
               'dietary_notes','recreational_notes','language_notes','care_summary'];
    $validDnr = ['unknown','no_dnr','dnr_in_place'];
    $values = [];
    foreach ($fields as $f) {
        $v = trim((string)($_POST[$f] ?? ''));
        if ($f === 'dnr_status') $v = in_array($v, $validDnr, true) ? $v : 'unknown';
        else                     $v = $v !== '' ? $v : null;
        $values[$f] = $v;
    }
    $reviewDate = $_POST['last_reviewed_date'] ?? null;
    $reviewDate = $reviewDate && $reviewDate !== '' ? $reviewDate : null;
    $uid = (int)($_SESSION['user_id'] ?? 0) ?: null;

    $db->prepare(
        "INSERT INTO patient_care_needs
            (person_id, medical_conditions, allergies, medications, dnr_status, dnr_notes,
             mobility_notes, hygiene_notes, cognitive_notes, emotional_notes,
             dietary_notes, recreational_notes, language_notes, care_summary,
             last_reviewed_date, last_reviewed_by_user_id)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
             medical_conditions = VALUES(medical_conditions),
             allergies          = VALUES(allergies),
             medications        = VALUES(medications),
             dnr_status         = VALUES(dnr_status),
             dnr_notes          = VALUES(dnr_notes),
             mobility_notes     = VALUES(mobility_notes),
             hygiene_notes      = VALUES(hygiene_notes),
             cognitive_notes    = VALUES(cognitive_notes),
             emotional_notes    = VALUES(emotional_notes),
             dietary_notes      = VALUES(dietary_notes),
             recreational_notes = VALUES(recreational_notes),
             language_notes     = VALUES(language_notes),
             care_summary       = VALUES(care_summary),
             last_reviewed_date = VALUES(last_reviewed_date),
             last_reviewed_by_user_id = VALUES(last_reviewed_by_user_id)"
    )->execute([
        $personId,
        $values['medical_conditions'], $values['allergies'], $values['medications'],
        $values['dnr_status'], $values['dnr_notes'],
        $values['mobility_notes'], $values['hygiene_notes'],
        $values['cognitive_notes'], $values['emotional_notes'],
        $values['dietary_notes'], $values['recreational_notes'],
        $values['language_notes'], $values['care_summary'],
        $reviewDate, $uid,
    ]);

    logActivity('care_needs_saved', 'patient_view', 'patient_care_needs', $personId,
        'Care-needs profile updated', null, $values);
    $redirectWithFlash($personId, 'Care-needs profile saved.', 'success');
}

// ── Save / add emergency contact ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'save_emergency_contact'
        && $canEdit && userCan('patient_emergency_contacts', 'edit')) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $redirectWithFlash($personId, 'Invalid form submission.', 'error');

    $contactId = (int)($_POST['contact_id'] ?? 0);
    $fullName = trim((string)($_POST['ec_full_name'] ?? ''));
    if ($fullName === '') $redirectWithFlash($personId, 'Contact name required.', 'error');

    $relationship = trim((string)($_POST['ec_relationship'] ?? '')) ?: null;
    $phone        = trim((string)($_POST['ec_phone'] ?? '')) ?: null;
    $altPhone     = trim((string)($_POST['ec_alt_phone'] ?? '')) ?: null;
    $email        = trim((string)($_POST['ec_email'] ?? '')) ?: null;
    $isPrimary    = !empty($_POST['ec_is_primary']) ? 1 : 0;
    $hasPoa       = !empty($_POST['ec_has_power_of_attorney']) ? 1 : 0;
    $notes        = trim((string)($_POST['ec_notes'] ?? '')) ?: null;
    $sortOrder    = max(0, min(999, (int)($_POST['ec_sort_order'] ?? 10)));

    if ($isPrimary) {
        // Demote any other primary for this patient
        $db->prepare("UPDATE patient_emergency_contacts SET is_primary = 0 WHERE person_id = ? AND id <> ?")
           ->execute([$personId, $contactId]);
    }

    if ($contactId > 0) {
        $db->prepare(
            "UPDATE patient_emergency_contacts
                SET full_name = ?, relationship = ?, phone = ?, alt_phone = ?,
                    email = ?, is_primary = ?, has_power_of_attorney = ?, notes = ?, sort_order = ?
              WHERE id = ? AND person_id = ?"
        )->execute([$fullName, $relationship, $phone, $altPhone, $email,
                    $isPrimary, $hasPoa, $notes, $sortOrder, $contactId, $personId]);
    } else {
        $db->prepare(
            "INSERT INTO patient_emergency_contacts
                (person_id, full_name, relationship, phone, alt_phone, email,
                 is_primary, has_power_of_attorney, notes, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        )->execute([$personId, $fullName, $relationship, $phone, $altPhone, $email,
                    $isPrimary, $hasPoa, $notes, $sortOrder]);
        $contactId = (int)$db->lastInsertId();
    }

    logActivity('emergency_contact_saved', 'patient_view', 'patient_emergency_contacts',
        $contactId, 'Saved emergency contact: ' . $fullName);
    $redirectWithFlash($personId, 'Emergency contact saved.', 'success');
}

// ── Delete emergency contact ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'delete_emergency_contact'
        && $canEdit && userCan('patient_emergency_contacts', 'delete')) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $redirectWithFlash($personId, 'Invalid form submission.', 'error');

    $contactId = (int)($_POST['contact_id'] ?? 0);
    if ($contactId > 0) {
        $db->prepare("DELETE FROM patient_emergency_contacts WHERE id = ? AND person_id = ?")
           ->execute([$contactId, $personId]);
        logActivity('emergency_contact_deleted', 'patient_view', 'patient_emergency_contacts',
            $contactId, 'Deleted emergency contact #' . $contactId);
    }
    $redirectWithFlash($personId, 'Emergency contact deleted.', 'success');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'upload_photo' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $redirectWithFlash($personId, 'Invalid form submission.', 'error');
    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) $redirectWithFlash($personId, 'No file uploaded.', 'error');
    $tmp  = $_FILES['photo']['tmp_name'];
    $size = (int)$_FILES['photo']['size'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) $redirectWithFlash($personId, 'Photo must be JPG, PNG or WebP.', 'error');
    if ($size > 5 * 1024 * 1024) $redirectWithFlash($personId, 'Photo must be 5 MB or smaller.', 'error');
    $tchRow = $db->prepare('SELECT tch_id, full_name FROM persons WHERE id = ?');
    $tchRow->execute([$personId]);
    $tchInfo = $tchRow->fetch() ?: [];
    $tchId = $tchInfo['tch_id'] ?? ('id-' . $personId);
    $ext = $allowed[$mime];
    $filename = 'profile_' . date('Ymd-His') . '.' . $ext;
    $relPath  = "people/{$tchId}/{$filename}";
    $absDir   = APP_ROOT . '/public/uploads/people/' . $tchId;
    if (!is_dir($absDir)) @mkdir($absDir, 0755, true);
    if (!move_uploaded_file($tmp, $absDir . '/' . $filename)) $redirectWithFlash($personId, 'Failed to save the uploaded file.', 'error');
    $db->prepare(
        "UPDATE attachments SET is_active = 0
         WHERE person_id = ?
           AND attachment_type_id = (SELECT id FROM attachment_types WHERE code='profile_photo')
           AND is_active = 1"
    )->execute([$personId]);
    $db->prepare(
        "INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, mime_type, file_size_bytes, is_active, uploaded_at)
         VALUES (?, (SELECT id FROM attachment_types WHERE code='profile_photo'), ?, ?, ?, ?, 1, NOW())"
    )->execute([$personId, $relPath, $_FILES['photo']['name'], $mime, $size]);
    logActivity('photo_uploaded', 'patient_view', 'persons', $personId,
        'New profile photo uploaded for ' . ($tchInfo['full_name'] ?? '?'),
        null, ['file_path' => $relPath]);
    logSystemActivity('persons', $personId, 'Profile photo updated',
        'New photo uploaded by ' . (currentEffectiveUser()['full_name'] ?? '?'),
        'patient_view#photo', 'photo-' . date('Ymd'));
    $redirectWithFlash($personId, 'New photo saved.', 'success');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'save_phones' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $redirectWithFlash($personId, 'Invalid form submission.', 'error');
    $before = getPersonPhones($personId);
    $newPhones = parsePhonesFromPost();
    savePersonPhones($personId, $newPhones);
    $after = getPersonPhones($personId);
    logActivity('edit', 'patient_view', 'persons', $personId,
        'Updated phones (' . count($after) . ' total)',
        ['phones' => array_column($before, 'phone')],
        ['phones' => array_column($after,  'phone')]);
    logSystemActivity('persons', $personId, 'Phones updated', count($after) . ' on file',
        'patient_view#phones', 'edit-' . date('Ymd-His'));
    $redirectWithFlash($personId, 'Phones saved.', 'success');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'save_emails' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $redirectWithFlash($personId, 'Invalid form submission.', 'error');
    $before = getPersonEmails($personId);
    $newEmails = parseEmailsFromPost();
    savePersonEmails($personId, $newEmails);
    $after = getPersonEmails($personId);
    logActivity('edit', 'patient_view', 'persons', $personId,
        'Updated emails (' . count($after) . ' total)',
        ['emails' => array_column($before, 'email')],
        ['emails' => array_column($after,  'email')]);
    logSystemActivity('persons', $personId, 'Emails updated', count($after) . ' on file',
        'patient_view#emails', 'edit-' . date('Ymd-His'));
    $redirectWithFlash($personId, 'Emails saved.', 'success');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'archive' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $redirectWithFlash($personId, 'Invalid form submission.', 'error');
    $reason = trim((string)($_POST['reason'] ?? '')) ?: null;
    $me = currentEffectiveUser();
    $db->prepare(
        'UPDATE persons SET archived_at = NOW(), archived_by_user_id = ?, archived_reason = ?
         WHERE id = ? AND archived_at IS NULL'
    )->execute([(int)($me['id'] ?? 0) ?: null, $reason, $personId]);
    logActivity('archived', 'patient_view', 'persons', $personId, 'Archived patient',
        ['archived_at' => null], ['archived_at' => date('Y-m-d H:i:s'), 'reason' => $reason]);
    logSystemActivity('persons', $personId, 'Record archived',
        'Archived by ' . ($me['full_name'] ?? '?') . ($reason ? ' — ' . $reason : ''),
        'patient_view#archive', 'archive-' . date('Ymd'));
    $redirectWithFlash($personId, 'Patient archived.', 'success');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'unarchive' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $redirectWithFlash($personId, 'Invalid form submission.', 'error');
    $db->prepare(
        'UPDATE persons SET archived_at = NULL, archived_by_user_id = NULL, archived_reason = NULL
         WHERE id = ?'
    )->execute([$personId]);
    logActivity('unarchived', 'patient_view', 'persons', $personId, 'Unarchived patient', null, null);
    logSystemActivity('persons', $personId, 'Record unarchived',
        'Restored by ' . (currentEffectiveUser()['full_name'] ?? '?'),
        'patient_view#unarchive', 'unarchive-' . date('Ymd'));
    $redirectWithFlash($personId, 'Patient restored.', 'success');
}

// Same-person toggle: create client row (and self-bill the patient)
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'same_person' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $redirectWithFlash($personId, 'Invalid form submission.', 'error');
    $stmt = $db->prepare('SELECT COUNT(*) FROM clients WHERE id = ?');
    $stmt->execute([$personId]);
    if ((int)$stmt->fetchColumn() === 0) {
        // Allocate account number
        $nextAcc = (int)$db->query(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(account_number, 6) AS UNSIGNED)),0) + 1
             FROM persons WHERE account_number LIKE 'TCH-C%'"
        )->fetchColumn();
        $accountNumber = 'TCH-C' . str_pad((string)$nextAcc, 4, '0', STR_PAD_LEFT);
        $db->prepare(
            "INSERT INTO clients (id, person_id, account_number) VALUES (?, ?, ?)"
        )->execute([$personId, $personId, $accountNumber]);
        $db->prepare("UPDATE persons SET account_number = ? WHERE id = ?")
           ->execute([$accountNumber, $personId]);
    }
    // Re-point this patient to bill themselves
    $db->prepare("UPDATE patients SET client_id = ? WHERE person_id = ?")->execute([$personId, $personId]);
    // Add 'client' to person_type SET
    $db->prepare(
        "UPDATE persons
         SET person_type = TRIM(BOTH ',' FROM
                            CONCAT_WS(',', person_type, IF(FIND_IN_SET('client', person_type)=0, 'client', NULL)))
         WHERE id = ?"
    )->execute([$personId]);
    logActivity('same_person_set', 'patient_view', 'persons', $personId,
        'Marked as also a client (same person)', null, ['person_type' => 'patient,client']);
    logSystemActivity('persons', $personId, 'Same person — client record added',
        'Patient is also the client. Created clients row and re-billed to themselves.',
        'patient_view#same-person', 'same-person-' . date('Ymd'));
    $redirectWithFlash($personId, 'Marked as also a client.', 'success');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'change_client' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $redirectWithFlash($personId, 'Invalid form submission.', 'error');
    $newClientId = (int)($_POST['client_id'] ?? 0);
    if ($newClientId <= 0) $redirectWithFlash($personId, 'Pick a valid client.', 'error');
    $stmt = $db->prepare('SELECT client_id FROM patients WHERE person_id = ?');
    $stmt->execute([$personId]);
    $oldClientId = (int)$stmt->fetchColumn();
    if ($oldClientId === $newClientId) $redirectWithFlash($personId, 'No change — already billed to that client.', 'info');
    $reason = trim((string)($_POST['reason'] ?? '')) ?: null;
    $me = currentEffectiveUser();
    $db->beginTransaction();
    try {
        // Phase-1 semantics: this is a data-error correction, applied
        // retroactively. UPDATE the open history row (do NOT close it
        // and open a new one — that's Phase-2 behaviour, see TODO #15).
        $db->prepare(
            'UPDATE patient_client_history
             SET client_id = ?, changed_by_user_id = ?, reason = ?
             WHERE patient_person_id = ? AND valid_to IS NULL'
        )->execute([$newClientId, (int)($me['id'] ?? 0) ?: null, $reason, $personId]);
        // If no open row existed (shouldn't happen post-seed, but defensive)
        if ($db->prepare('SELECT COUNT(*) FROM patient_client_history WHERE patient_person_id = ? AND valid_to IS NULL')
               ->execute([$personId]) && $db->query('SELECT ROW_COUNT()')->fetchColumn() === 0) {
            $db->prepare(
                'INSERT INTO patient_client_history (patient_person_id, client_id, valid_from, valid_to, changed_by_user_id, reason)
                 VALUES (?, ?, NULL, NULL, ?, ?)'
            )->execute([$personId, $newClientId, (int)($me['id'] ?? 0) ?: null, $reason]);
        }
        // Denormalised current pointer
        $db->prepare('UPDATE patients SET client_id = ? WHERE person_id = ?')
           ->execute([$newClientId, $personId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        $redirectWithFlash($personId, 'Could not re-assign: ' . $e->getMessage(), 'error');
    }
    logActivity('edit', 'patient_view', 'persons', $personId,
        'Re-assigned bill-paying client (Phase-1 retroactive correction)',
        ['client_id' => $oldClientId], ['client_id' => $newClientId, 'reason' => $reason]);
    logSystemActivity('persons', $personId, 'Bill-paying client changed (retroactive)',
        'Was client #' . $oldClientId . ', now client #' . $newClientId
        . ($reason ? ' — ' . $reason : '')
        . '. Phase-1 mode: change applies to all historic shifts. Once data is locked, this becomes time-stamped (TODO #15).',
        'patient_view#change-client', 'reassign-' . date('Ymd'));
    $redirectWithFlash($personId, 'Patient re-assigned (retroactive — applies to all historic shifts).', 'success');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'save_section' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $redirectWithFlash($personId, 'Invalid form submission.', 'error');
    $section = $_POST['section'] ?? '';
    if (!isset($editableSections[$section])) $redirectWithFlash($personId, 'Unknown section.', 'error');
    $secDef = $editableSections[$section];
    $stmt = $db->prepare("SELECT * FROM persons WHERE id = ?");
    $stmt->execute([$personId]);
    $beforeRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $newValues = [];
    foreach ($secDef['cols'] as $col) {
        $val = $_POST[$col] ?? null;
        if (is_string($val)) $val = trim($val);
        if ($val === '') $val = null;
        $newValues[$col] = $val;
    }
    if ($section === 'personal') {
        $full = trim(implode(' ', array_filter([
            $newValues['salutation'] ?? null,
            $newValues['first_name'] ?? null,
            $newValues['middle_names'] ?? null,
            $newValues['last_name'] ?? null,
        ])));
        if ($full !== '') {
            $db->prepare('UPDATE persons SET full_name = ? WHERE id = ?')
               ->execute([$full, $personId]);
        }
    }
    $changed = [];
    foreach ($newValues as $col => $newVal) {
        $oldVal = $beforeRow[$col] ?? null;
        if ((string)$oldVal !== (string)$newVal) {
            $changed[$col] = ['from' => $oldVal, 'to' => $newVal];
        }
    }
    if (!$changed) $redirectWithFlash($personId, 'No changes to save.', 'info');

    $set = []; $params = [];
    foreach ($newValues as $col => $val) { $set[] = "`$col` = ?"; $params[] = $val; }
    $params[] = $personId;
    $db->prepare("UPDATE persons SET " . implode(',', $set) . " WHERE id = ?")
       ->execute($params);

    if ($section === 'address') {
        savePrimaryAddress($personId, $newValues);
        // Re-geocode on any address change. Fire-and-forget — Nominatim
        // is rate-limited so this call can take up to ~1s but doesn't
        // block the redirect completing.
        require_once APP_ROOT . '/includes/geocode.php';
        geocodePersonAndSave($db, $personId, force: true);
    }

    logActivity('edit', 'patient_view', 'persons', $personId,
        'Updated ' . $section . ' (' . count($changed) . ' field' . (count($changed)===1?'':'s') . ')',
        array_combine(array_keys($changed), array_column($changed, 'from')),
        array_combine(array_keys($changed), array_column($changed, 'to')));
    foreach ($changed as $col => $diff) {
        $label = ucwords(str_replace('_', ' ', $col));
        $body  = "Was: " . ($diff['from'] ?? '(empty)') . "\nNow: " . ($diff['to'] ?? '(empty)');
        logSystemActivity('persons', $personId, $label . ' updated', $body,
            'patient_view#' . $section, 'edit-' . date('Ymd-His'));
    }
    $redirectWithFlash($personId, count($changed) . ' field' . (count($changed)===1?'':'s') . ' updated.', 'success');
}

if ($flash === '' && isset($_GET['msg'])) {
    $flash = (string)$_GET['msg'];
    $flashType = (string)($_GET['type'] ?? 'success');
}

// ── Load person + patient + bill-paying client ─────────────────────────
$stmt = $db->prepare(
    "SELECT p.*,
            pt.client_id, pt.patient_name AS pt_patient_name,
            cp.full_name AS bill_client_name,
            c.account_number AS bill_account_number
     FROM persons p
     LEFT JOIN patients pt ON pt.person_id = p.id
     LEFT JOIN clients c ON c.id = pt.client_id
     LEFT JOIN persons cp ON cp.id = c.person_id
     WHERE p.id = ? AND FIND_IN_SET('patient', p.person_type)"
);
$stmt->execute([$personId]);
$person = $stmt->fetch();

if (!$person) {
    require APP_ROOT . '/templates/layouts/admin.php';
    echo '<p>No patient with id ' . (int)$personId . '.</p>';
    echo '<p><a href="' . APP_URL . '/admin/patients">Back to patients</a></p>';
    require APP_ROOT . '/templates/layouts/admin_footer.php';
    return;
}

$phones    = getPersonPhones($personId);
$emails    = getPersonEmails($personId);
$addresses = getPersonAddresses($personId);

$stmt = $db->prepare(
    "SELECT a.*, at.code AS type_code FROM attachments a
     JOIN attachment_types at ON at.id = a.attachment_type_id
     WHERE a.person_id = ? AND a.is_active = 1
     ORDER BY at.sort_order, a.uploaded_at"
);
$stmt->execute([$personId]);
$attachments = $stmt->fetchAll();
$photoPath = null;
foreach ($attachments as $a) { if ($a['type_code'] === 'profile_photo') { $photoPath = $a['file_path']; break; } }

// Other clients (for re-assign dropdown)
$otherClients = $db->query(
    "SELECT c.id, p.full_name AS client_name, c.account_number
     FROM clients c
     JOIN persons p ON p.id = c.person_id
     WHERE p.archived_at IS NULL
     ORDER BY p.full_name"
)->fetchAll();

// Billing-history rows (patient_client_history) — small panel.
// Phase-1 mode shows one row per patient; Phase-2 will show the timeline.
$stmt = $db->prepare(
    "SELECT pch.id, pch.client_id, pch.valid_from, pch.valid_to, pch.reason, pch.created_at,
            p.full_name AS client_name, c.account_number,
            u.full_name AS changed_by_name
     FROM patient_client_history pch
     LEFT JOIN clients c ON c.id = pch.client_id
     LEFT JOIN persons p ON p.id = c.person_id
     LEFT JOIN users   u ON u.id = pch.changed_by_user_id
     WHERE pch.patient_person_id = ?
     ORDER BY COALESCE(pch.valid_from, pch.created_at) DESC, pch.id DESC"
);
$stmt->execute([$personId]);
$billingHistory = $stmt->fetchAll();

$editSection = $_GET['edit'] ?? '';
if (!isset($editableSections[$editSection]) || !$canEdit) $editSection = '';
$editPhones = $canEdit && (($_GET['edit'] ?? '') === 'phones');
$editEmails = $canEdit && (($_GET['edit'] ?? '') === 'emails');
$editClient = $canEdit && (($_GET['edit'] ?? '') === 'client');

// _esc + _editLink + _formActions: declared in client_view.php scope but
// each request only loads ONE of these templates — re-declare here.
if (!function_exists('_esc')) {
    function _esc($v) { return htmlspecialchars((string)($v ?? '')); }
}
if (!function_exists('_p_editLink')) {
    function _p_editLink(int $personId, string $section, bool $canEdit): string {
        if (!$canEdit) return '';
        return '<a href="' . APP_URL . '/admin/patients/' . $personId
             . '?edit=' . $section . '" class="btn btn-link btn-sm" style="float:right;padding:0;">Edit</a>';
    }
}
if (!function_exists('_p_formActions')) {
    function _p_formActions(int $personId): string {
        return '<div style="margin-top:0.75rem;text-align:right;">'
             . '<a href="' . APP_URL . '/admin/patients/' . $personId . '" class="btn btn-link">Cancel</a>'
             . ' <button type="submit" class="btn btn-primary btn-sm">Save</button></div>';
    }
}

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center;">
    <a href="<?= APP_URL ?>/admin/patients" class="btn btn-outline btn-sm">&larr; Back to patients</a>
    <?php if ($canEdit && empty($person['archived_at'])): ?>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this patient?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="archive">
            <input type="text" name="reason" placeholder="reason (optional)" class="form-control" style="display:inline-block;width:auto;">
            <button type="submit" class="btn btn-outline btn-sm">Archive</button>
        </form>
    <?php elseif ($canEdit && !empty($person['archived_at'])): ?>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Restore this archived patient?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="unarchive">
            <button type="submit" class="btn btn-primary btn-sm">Restore from archive</button>
        </form>
    <?php endif; ?>
</div>

<?php if ($flash): ?>
    <div class="flash" style="margin-bottom:1rem;padding:0.5rem 1rem;border-radius:4px;background:<?= $flashType === 'error' ? '#f8d7da' : ($flashType === 'info' ? '#cce5ff' : '#d4edda') ?>;">
        <?= _esc($flash) ?>
    </div>
<?php endif; ?>

<?php if (!empty($person['archived_at'])): ?>
    <div style="background:#fff3cd;color:#856404;padding:0.5rem 1rem;border-radius:4px;margin-bottom:1rem;">
        <strong>ARCHIVED</strong> on <?= _esc($person['archived_at']) ?><?= $person['archived_reason'] ? ' — ' . _esc($person['archived_reason']) : '' ?>
    </div>
<?php endif; ?>

<?php
  // "Same person" only when this patient's bill-paying client_id points
  // back at themselves AND the patient_name is empty or matches the
  // person's full_name. If patient_name differs, this is the legacy
  // case where one persons row carried a different recipient name.
  $isSelfBilled = ((int)$person['client_id'] === $personId);
  $ptName = trim((string)($person['pt_patient_name'] ?? ''));
  $namesMatch = ($ptName === '' || strcasecmp($ptName, (string)$person['full_name']) === 0);
?>
<?php if ($isSelfBilled && $namesMatch): ?>
    <div style="background:#cce5ff;color:#004085;padding:0.6rem 1rem;border-radius:4px;margin-bottom:1rem;border-left:4px solid #0d6efd;">
        <strong>Same person</strong> — this patient is also the client
        (<a href="<?= APP_URL ?>/admin/clients/<?= $personId ?>" style="color:#004085;text-decoration:underline;">open client view</a>).
        Edits to personal, contact and address fields apply to both records — there is only one underlying person.
    </div>
<?php elseif ($isSelfBilled && !$namesMatch): ?>
    <div style="background:#fff3cd;color:#856404;padding:0.6rem 1rem;border-radius:4px;margin-bottom:1rem;border-left:4px solid #ffc107;">
        <strong>Legacy data — recipient name differs from client name.</strong>
        Patient name (<em><?= _esc($ptName) ?></em>) doesn't match the underlying person record
        (<em><?= _esc($person['full_name']) ?></em>). Likely two different humans sharing one record — should be split into two `persons` rows. Edits here will affect the client view too.
    </div>
<?php endif; ?>

<div class="person-card">
    <div class="person-card-header">
        <div style="display:flex;flex-direction:column;align-items:center;gap:0.4rem;">
            <?php if ($photoPath): ?>
                <img class="person-photo" src="<?= APP_URL ?>/uploads/<?= _esc($photoPath) ?>" alt="<?= _esc($person['full_name']) ?>">
            <?php else: ?>
                <div class="person-photo person-photo-placeholder">No photo</div>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <form method="POST" enctype="multipart/form-data" style="margin:0;text-align:center;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="upload_photo">
                    <label class="btn btn-outline btn-sm" style="margin:0;cursor:pointer;">
                        <i class="fas fa-camera"></i> Replace photo
                        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="this.form.submit();">
                    </label>
                </form>
            <?php endif; ?>
        </div>
        <div class="person-card-title">
            <h2><?= _esc($person['full_name']) ?></h2>
            <div class="person-card-tch-id"><?= _esc($person['tch_id'] ?? '—') ?></div>
            <div class="person-card-meta">
                Type: <strong><?= _esc($person['person_type']) ?></strong>
                <?php if ($person['client_id']): ?>
                    &middot; Billed to:
                    <strong>
                        <a href="<?= APP_URL ?>/admin/clients/<?= (int)$person['client_id'] ?>"><?= _esc($person['bill_client_name'] ?? '#' . (int)$person['client_id']) ?></a>
                    </strong>
                    <?php if ($person['bill_account_number']): ?>
                        <code style="font-size:0.8rem;">(<?= _esc($person['bill_account_number']) ?>)</code>
                    <?php endif; ?>
                    <?php if ($canEdit): ?>
                        <a href="<?= APP_URL ?>/admin/patients/<?= $personId ?>?edit=client" style="font-size:0.85rem;">change</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($editClient): ?>
        <div style="padding:0.75rem 1rem;background:#f8f9fa;border-top:1px solid #eee;">
            <div style="background:#fff3cd;color:#856404;padding:0.5rem 0.75rem;border-radius:4px;border-left:4px solid #ffc107;margin-bottom:0.75rem;font-size:0.9rem;">
                <strong>⚠ Data-cleanup phase — re-assigns rewrite history.</strong>
                Right now, changing the bill-paying client applies to <em>all</em> historic shifts for this patient.
                Use this only to correct data errors (e.g. shifts were tagged to the wrong client all along).
                Once historic data is locked down, this will switch to time-stamped mode where re-assigns only affect future shifts (TODO #15).
            </div>
            <form method="POST" style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="change_client">
                <label style="margin:0;">Re-assign to client</label>
                <select class="form-control" name="client_id" style="max-width:340px;">
                    <?php foreach ($otherClients as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === (int)$person['client_id'] ? 'selected' : '' ?>>
                            <?= _esc($c['client_name']) ?> <?= $c['account_number'] ? '(' . _esc($c['account_number']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-primary btn-sm" type="submit">Re-assign</button>
                <a href="<?= APP_URL ?>/admin/patients/<?= $personId ?>" class="btn btn-link btn-sm">Cancel</a>
                <input type="text" name="reason" placeholder="reason (optional)" class="form-control" style="max-width:240px;">
            </form>
        </div>
    <?php endif; ?>

    <div class="person-card-grid">

        <!-- Personal -->
        <div class="person-card-section">
            <h3>Personal <?= _p_editLink($personId, 'personal', $canEdit && $editSection !== 'personal') ?></h3>
            <?php if ($editSection === 'personal'): ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_section">
                    <input type="hidden" name="section" value="personal">
                    <dl class="edit-dl">
                        <dt>Salutation</dt>    <dd><input class="form-control" name="salutation"   value="<?= _esc($person['salutation']) ?>"></dd>
                        <dt>First Name</dt>    <dd><input class="form-control" name="first_name"   value="<?= _esc($person['first_name']) ?>"></dd>
                        <dt>Middle Name(s)</dt><dd><input class="form-control" name="middle_names" value="<?= _esc($person['middle_names']) ?>"></dd>
                        <dt>Last Name</dt>     <dd><input class="form-control" name="last_name"    value="<?= _esc($person['last_name']) ?>"></dd>
                        <dt>Known As</dt>      <dd><input class="form-control" name="known_as"     value="<?= _esc($person['known_as']) ?>"></dd>
                        <dt>ID / Passport</dt> <dd><input class="form-control" name="id_passport"  value="<?= _esc($person['id_passport']) ?>"></dd>
                        <dt>Date of Birth</dt> <dd><input class="form-control" type="date" name="dob" value="<?= _esc($person['dob']) ?>"></dd>
                        <dt>Gender</dt>        <dd>
                            <select class="form-control" name="gender">
                                <option value="">—</option>
                                <?php foreach (['Male','Female','Other'] as $g): ?>
                                    <option value="<?= $g ?>" <?= ($person['gender'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
                                <?php endforeach; ?>
                            </select>
                        </dd>
                        <dt>Nationality</dt>   <dd><input class="form-control" name="nationality"   value="<?= _esc($person['nationality']) ?>"></dd>
                    </dl>
                    <?= _p_formActions($personId) ?>
                </form>
            <?php else: ?>
                <dl>
                    <dt>Salutation</dt>    <dd><?= _esc($person['salutation']) ?: '—' ?></dd>
                    <dt>First Name</dt>    <dd><?= _esc($person['first_name']) ?: '—' ?></dd>
                    <dt>Middle Name(s)</dt><dd><?= _esc($person['middle_names']) ?: '—' ?></dd>
                    <dt>Last Name</dt>     <dd><?= _esc($person['last_name']) ?: '—' ?></dd>
                    <dt>Known As</dt>      <dd><?= _esc($person['known_as']) ?: '—' ?></dd>
                    <dt>ID / Passport</dt> <dd><?= _esc($person['id_passport']) ?: '—' ?></dd>
                    <dt>Date of Birth</dt> <dd><?= _esc($person['dob']) ?: '—' ?></dd>
                    <dt>Gender</dt>        <dd><?= _esc($person['gender']) ?: '—' ?></dd>
                    <dt>Nationality</dt>   <dd><?= _esc($person['nationality']) ?: '—' ?></dd>
                </dl>
            <?php endif; ?>
        </div>

        <!-- Phones -->
        <div class="person-card-section">
            <h3>Phones
                <?php if ($canEdit && !$editPhones): ?>
                    <a href="<?= APP_URL ?>/admin/patients/<?= $personId ?>?edit=phones" class="btn btn-link btn-sm" style="float:right;padding:0;">Edit</a>
                <?php endif; ?>
            </h3>
            <?php if ($editPhones): ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_phones">
                    <table style="width:100%;font-size:0.9rem;"><thead><tr>
                        <th style="text-align:left;">Label</th><th style="text-align:left;">Number</th><th>Primary</th><th></th>
                    </tr></thead><tbody id="phones-tbody">
                    <?php
                    $rows = !empty($phones) ? $phones : [['label'=>'Mobile','phone'=>'','is_primary'=>1]];
                    $i = 0;
                    foreach ($rows as $ph): [$dial, $nat] = splitE164($ph['phone'] ?? ''); ?>
                        <tr>
                            <td><input class="form-control" name="phones_label[]" value="<?= _esc($ph['label']) ?>" placeholder="Mobile / Work…" style="min-width:120px;"></td>
                            <td><div style="display:flex;gap:0.3rem;">
                                <?php renderDialPrefixSelect('phones_dial[]', $dial); ?>
                                <input class="form-control" name="phones_national[]" value="<?= _esc($nat) ?>" placeholder="national">
                            </div></td>
                            <td style="text-align:center;"><input type="radio" name="phones_primary" value="<?= $i ?>" <?= !empty($ph['is_primary']) ? 'checked' : '' ?>></td>
                            <td><button type="button" class="btn btn-link btn-sm" onclick="this.closest('tr').remove();">Remove</button></td>
                        </tr>
                    <?php $i++; endforeach; ?>
                    </tbody></table>
                    <button type="button" class="btn btn-outline btn-sm" style="margin-top:0.5rem;" onclick="
                        var t=document.getElementById('phones-tbody');
                        var r=t.rows[0].cloneNode(true);
                        r.querySelectorAll('input[type=text],input:not([type]),input[type=tel]').forEach(function(e){e.value='';});
                        var radio=r.querySelector('input[type=radio]'); if(radio){radio.checked=false; radio.value=t.rows.length;}
                        t.appendChild(r);
                    ">+ Add phone</button>
                    <?= _p_formActions($personId) ?>
                </form>
            <?php elseif (empty($phones)): ?>
                <p style="color:#6c757d;margin:0;">No phones on file.</p>
            <?php else: ?>
                <ul style="list-style:none;padding:0;margin:0;">
                <?php foreach ($phones as $ph): ?>
                    <li style="padding:0.25rem 0;">
                        <strong><?= _esc(formatPhoneForDisplay($ph['phone'])) ?></strong>
                        <?php if ($ph['label']): ?><span style="color:#6c757d;font-size:0.85rem;">— <?= _esc($ph['label']) ?></span><?php endif; ?>
                        <?php if (!empty($ph['is_primary'])): ?><span style="background:#cce5ff;font-size:0.75rem;padding:0.1rem 0.4rem;border-radius:3px;margin-left:0.4rem;">primary</span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Emails -->
        <div class="person-card-section">
            <h3>Emails
                <?php if ($canEdit && !$editEmails): ?>
                    <a href="<?= APP_URL ?>/admin/patients/<?= $personId ?>?edit=emails" class="btn btn-link btn-sm" style="float:right;padding:0;">Edit</a>
                <?php endif; ?>
            </h3>
            <?php if ($editEmails): ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_emails">
                    <table style="width:100%;font-size:0.9rem;"><thead><tr>
                        <th style="text-align:left;">Label</th><th style="text-align:left;">Address</th><th>Primary</th><th></th>
                    </tr></thead><tbody id="emails-tbody">
                    <?php
                    $rows = !empty($emails) ? $emails : [['label'=>'Primary','email'=>'','is_primary'=>1]];
                    $i = 0;
                    foreach ($rows as $em): ?>
                        <tr>
                            <td><input class="form-control" name="emails_label[]" value="<?= _esc($em['label']) ?>" placeholder="Primary / Work…" style="min-width:120px;"></td>
                            <td><input class="form-control" type="email" name="emails_address[]" value="<?= _esc($em['email']) ?>"></td>
                            <td style="text-align:center;"><input type="radio" name="emails_primary" value="<?= $i ?>" <?= !empty($em['is_primary']) ? 'checked' : '' ?>></td>
                            <td><button type="button" class="btn btn-link btn-sm" onclick="this.closest('tr').remove();">Remove</button></td>
                        </tr>
                    <?php $i++; endforeach; ?>
                    </tbody></table>
                    <button type="button" class="btn btn-outline btn-sm" style="margin-top:0.5rem;" onclick="
                        var t=document.getElementById('emails-tbody');
                        var r=t.rows[0].cloneNode(true);
                        r.querySelectorAll('input[type=text],input[type=email]').forEach(function(e){e.value='';});
                        var radio=r.querySelector('input[type=radio]'); if(radio){radio.checked=false; radio.value=t.rows.length;}
                        t.appendChild(r);
                    ">+ Add email</button>
                    <?= _p_formActions($personId) ?>
                </form>
            <?php elseif (empty($emails)): ?>
                <p style="color:#6c757d;margin:0;">No emails on file.</p>
            <?php else: ?>
                <ul style="list-style:none;padding:0;margin:0;">
                <?php foreach ($emails as $em): ?>
                    <li style="padding:0.25rem 0;">
                        <strong><?= _esc($em['email']) ?></strong>
                        <?php if ($em['label']): ?><span style="color:#6c757d;font-size:0.85rem;">— <?= _esc($em['label']) ?></span><?php endif; ?>
                        <?php if (!empty($em['is_primary'])): ?><span style="background:#cce5ff;font-size:0.75rem;padding:0.1rem 0.4rem;border-radius:3px;margin-left:0.4rem;">primary</span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Address -->
        <div class="person-card-section">
            <h3>Address <?= _p_editLink($personId, 'address', $canEdit && $editSection !== 'address') ?></h3>
            <?php if ($editSection === 'address'): ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_section">
                    <input type="hidden" name="section" value="address">
                    <dl class="edit-dl">
                        <dt>Complex/Estate</dt><dd><input class="form-control" name="complex_estate" value="<?= _esc($person['complex_estate']) ?>"></dd>
                        <dt>Street</dt>        <dd><input class="form-control" name="street_address" value="<?= _esc($person['street_address']) ?>"></dd>
                        <dt>Suburb</dt>        <dd><input class="form-control" name="suburb"         value="<?= _esc($person['suburb']) ?>"></dd>
                        <dt>City</dt>          <dd><input class="form-control" name="city"           value="<?= _esc($person['city']) ?>"></dd>
                        <dt>Province</dt>      <dd><input class="form-control" name="province"       value="<?= _esc($person['province']) ?>"></dd>
                        <dt>Postal Code</dt>   <dd><input class="form-control" name="postal_code"    value="<?= _esc($person['postal_code']) ?>"></dd>
                        <dt>Country</dt>       <dd><?php renderCountrySelect('country', $person['country'] ?? 'South Africa'); ?></dd>
                    </dl>
                    <?= _p_formActions($personId) ?>
                </form>
            <?php else: ?>
                <dl>
                    <dt>Complex/Estate</dt><dd><?= _esc($person['complex_estate']) ?: '—' ?></dd>
                    <dt>Street</dt>        <dd><?= _esc($person['street_address']) ?: '—' ?></dd>
                    <dt>Suburb</dt>        <dd><?= _esc($person['suburb']) ?: '—' ?></dd>
                    <dt>City</dt>          <dd><?= _esc($person['city']) ?: '—' ?></dd>
                    <dt>Province</dt>      <dd><?= _esc($person['province']) ?: '—' ?></dd>
                    <dt>Postal Code</dt>   <dd><?= _esc($person['postal_code']) ?: '—' ?></dd>
                    <dt>Country</dt>       <dd><?= _esc($person['country'] ?? 'South Africa') ?></dd>
                </dl>
            <?php endif; ?>
        </div>

        <!-- Billing history -->
        <div class="person-card-section">
            <h3>Billing history</h3>
            <?php if (empty($billingHistory)): ?>
                <p style="color:#6c757d;margin:0;">No billing history yet.</p>
            <?php else: ?>
                <table style="width:100%;font-size:0.85rem;border-collapse:collapse;">
                    <thead><tr style="border-bottom:1px solid #eee;color:#6c757d;">
                        <th style="text-align:left;padding:0.25rem 0.4rem;">From</th>
                        <th style="text-align:left;padding:0.25rem 0.4rem;">To</th>
                        <th style="text-align:left;padding:0.25rem 0.4rem;">Client</th>
                        <th style="text-align:left;padding:0.25rem 0.4rem;">Reason</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($billingHistory as $h): ?>
                        <tr style="border-bottom:1px solid #f5f5f5;">
                            <td style="padding:0.3rem 0.4rem;">
                                <?= $h['valid_from']
                                    ? htmlspecialchars(date('d M Y', strtotime($h['valid_from'])))
                                    : '<span style="color:#6c757d;">since record began</span>' ?>
                            </td>
                            <td style="padding:0.3rem 0.4rem;">
                                <?= $h['valid_to']
                                    ? htmlspecialchars(date('d M Y', strtotime($h['valid_to'])))
                                    : '<span style="background:#d4edda;color:#155724;font-size:0.75rem;padding:0.1rem 0.4rem;border-radius:3px;">current</span>' ?>
                            </td>
                            <td style="padding:0.3rem 0.4rem;">
                                <a href="<?= APP_URL ?>/admin/clients/<?= (int)$h['client_id'] ?>"><?= _esc($h['client_name'] ?? '#' . (int)$h['client_id']) ?></a>
                                <?php if ($h['account_number']): ?><code style="color:#6c757d;font-size:0.75rem;">(<?= _esc($h['account_number']) ?>)</code><?php endif; ?>
                            </td>
                            <td style="padding:0.3rem 0.4rem;color:#6c757d;"><?= _esc($h['reason']) ?: '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="color:#6c757d;font-size:0.8rem;margin-top:0.5rem;">
                    Phase 1 (now): re-assigns rewrite the open row. Phase 2 (post data-cleanup): re-assigns close the open row at the change-date and open a new one — historic shifts stay billed to the previous client.
                </p>
            <?php endif; ?>
        </div>

        <!-- Same-person toggle -->
        <?php if ($canEdit): ?>
            <?php
              $stmt = $db->prepare('SELECT COUNT(*) FROM clients WHERE id = ?');
              $stmt->execute([$personId]);
              $alreadyClient = ((int)$stmt->fetchColumn() > 0);
            ?>
            <?php if (!$alreadyClient): ?>
                <div class="person-card-section">
                    <h3>Identity</h3>
                    <p style="color:#6c757d;font-size:0.9rem;">
                        This patient is currently billed to a separate client record. If the patient pays for their own care, mark them as also being the client.
                    </p>
                    <form method="POST" onsubmit="return confirm('Make this patient their own client too? They will be billed to themselves and a new client record will be created.');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="same_person">
                        <button class="btn btn-outline btn-sm" type="submit">Same person — make this patient the client too</button>
                    </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</div>

<?php
// ─────────────────────────────────────────────────────────────
// Patient care-needs profile (Phase 4.4)
// ─────────────────────────────────────────────────────────────
$canSeeCareNeeds = userCan('patient_care_needs', 'read');
$canEditCareNeeds = userCan('patient_care_needs', 'edit');
if ($canSeeCareNeeds):
    $cn = $db->prepare("SELECT * FROM patient_care_needs WHERE person_id = ?");
    $cn->execute([$personId]);
    $careNeeds = $cn->fetch(PDO::FETCH_ASSOC) ?: [];
    $editCareNeeds = !empty($_GET['edit']) && $_GET['edit'] === 'care_needs' && $canEditCareNeeds;
    $dnr = $careNeeds['dnr_status'] ?? 'unknown';
?>
<details class="card" style="margin-top:1.5rem;" <?= empty($careNeeds) ? '' : 'open' ?>>
    <summary style="cursor:pointer;padding:0.75rem 1rem;background:#f8f9fa;list-style:none;display:flex;justify-content:space-between;align-items:center;">
        <h3 style="margin:0;">
            Care needs
            <?php if ($dnr === 'dnr_in_place'): ?>
                <span style="background:#dc3545;color:#fff;padding:2px 8px;border-radius:3px;font-size:0.7em;font-weight:700;letter-spacing:0.05em;margin-left:0.4rem;vertical-align:middle;">DNR</span>
            <?php endif; ?>
            <?php if (empty($careNeeds)): ?>
                <span style="font-weight:400;font-size:0.82rem;color:#6c757d;margin-left:0.5rem;">(not yet captured)</span>
            <?php elseif (!empty($careNeeds['last_reviewed_date'])): ?>
                <span style="font-weight:400;font-size:0.82rem;color:#6c757d;margin-left:0.5rem;">(last reviewed <?= _esc($careNeeds['last_reviewed_date']) ?>)</span>
            <?php endif; ?>
        </h3>
        <?php if ($canEditCareNeeds && !$editCareNeeds): ?>
            <a href="?patient_id=<?= $personId ?>&edit=care_needs" onclick="event.stopPropagation();" class="btn btn-outline btn-sm">Edit</a>
        <?php endif; ?>
    </summary>

    <div style="padding:1rem 1.2rem;">
    <?php if ($editCareNeeds): ?>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_care_needs">

            <details open style="margin-bottom:0.6rem;border:1px solid #e2e8f0;border-radius:4px;">
                <summary style="padding:0.4rem 0.8rem;background:#fff5f5;cursor:pointer;font-weight:600;color:#842029;">
                    Medical &amp; critical
                </summary>
                <div style="padding:0.8rem;">
                    <label style="display:block;margin-bottom:0.6rem;">
                        <span style="font-size:0.85rem;color:#495057;">Medical conditions</span>
                        <textarea name="medical_conditions" rows="3" class="form-control"><?= _esc($careNeeds['medical_conditions'] ?? '') ?></textarea>
                    </label>
                    <label style="display:block;margin-bottom:0.6rem;">
                        <span style="font-size:0.85rem;color:#495057;">Allergies</span>
                        <textarea name="allergies" rows="2" class="form-control" placeholder="Drug / food / environmental. Flag severity if life-threatening."><?= _esc($careNeeds['allergies'] ?? '') ?></textarea>
                    </label>
                    <label style="display:block;margin-bottom:0.6rem;">
                        <span style="font-size:0.85rem;color:#495057;">Medications</span>
                        <textarea name="medications" rows="3" class="form-control" placeholder="Drug + dose + frequency. Does not substitute for a script."><?= _esc($careNeeds['medications'] ?? '') ?></textarea>
                    </label>
                    <div style="display:grid;grid-template-columns:1fr 2fr;gap:0.6rem;">
                        <label>
                            <span style="font-size:0.85rem;color:#495057;">DNR status</span>
                            <select name="dnr_status" class="form-control">
                                <option value="unknown"      <?= $dnr === 'unknown'      ? 'selected' : '' ?>>Unknown</option>
                                <option value="no_dnr"       <?= $dnr === 'no_dnr'       ? 'selected' : '' ?>>No DNR</option>
                                <option value="dnr_in_place" <?= $dnr === 'dnr_in_place' ? 'selected' : '' ?>>DNR in place</option>
                            </select>
                        </label>
                        <label>
                            <span style="font-size:0.85rem;color:#495057;">DNR notes (if in place)</span>
                            <input type="text" name="dnr_notes" class="form-control" maxlength="500"
                                   value="<?= _esc($careNeeds['dnr_notes'] ?? '') ?>">
                        </label>
                    </div>
                </div>
            </details>

            <details style="margin-bottom:0.6rem;border:1px solid #e2e8f0;border-radius:4px;">
                <summary style="padding:0.4rem 0.8rem;background:#f1f5f9;cursor:pointer;font-weight:600;">Physical &amp; functional</summary>
                <div style="padding:0.8rem;">
                    <label style="display:block;margin-bottom:0.6rem;">
                        <span style="font-size:0.85rem;color:#495057;">Mobility</span>
                        <textarea name="mobility_notes" rows="2" class="form-control" placeholder="Walking aids, wheelchair, bed-bound, fall risk."><?= _esc($careNeeds['mobility_notes'] ?? '') ?></textarea>
                    </label>
                    <label style="display:block;margin-bottom:0.6rem;">
                        <span style="font-size:0.85rem;color:#495057;">Hygiene</span>
                        <textarea name="hygiene_notes" rows="2" class="form-control" placeholder="Continence, bathing, dressing assistance."><?= _esc($careNeeds['hygiene_notes'] ?? '') ?></textarea>
                    </label>
                </div>
            </details>

            <details style="margin-bottom:0.6rem;border:1px solid #e2e8f0;border-radius:4px;">
                <summary style="padding:0.4rem 0.8rem;background:#f1f5f9;cursor:pointer;font-weight:600;">Cognitive &amp; emotional</summary>
                <div style="padding:0.8rem;">
                    <label style="display:block;margin-bottom:0.6rem;">
                        <span style="font-size:0.85rem;color:#495057;">Cognitive</span>
                        <textarea name="cognitive_notes" rows="2" class="form-control" placeholder="Dementia stage, confusion, behaviours to expect."><?= _esc($careNeeds['cognitive_notes'] ?? '') ?></textarea>
                    </label>
                    <label style="display:block;margin-bottom:0.6rem;">
                        <span style="font-size:0.85rem;color:#495057;">Emotional</span>
                        <textarea name="emotional_notes" rows="2" class="form-control" placeholder="Anxiety, depression, triggers, calming strategies."><?= _esc($careNeeds['emotional_notes'] ?? '') ?></textarea>
                    </label>
                </div>
            </details>

            <details style="margin-bottom:0.6rem;border:1px solid #e2e8f0;border-radius:4px;">
                <summary style="padding:0.4rem 0.8rem;background:#f1f5f9;cursor:pointer;font-weight:600;">Preferences</summary>
                <div style="padding:0.8rem;">
                    <label style="display:block;margin-bottom:0.6rem;">
                        <span style="font-size:0.85rem;color:#495057;">Dietary</span>
                        <textarea name="dietary_notes" rows="2" class="form-control"><?= _esc($careNeeds['dietary_notes'] ?? '') ?></textarea>
                    </label>
                    <label style="display:block;margin-bottom:0.6rem;">
                        <span style="font-size:0.85rem;color:#495057;">Recreational</span>
                        <textarea name="recreational_notes" rows="2" class="form-control" placeholder="Hobbies, favourite music, routines."><?= _esc($careNeeds['recreational_notes'] ?? '') ?></textarea>
                    </label>
                    <label style="display:block;margin-bottom:0.6rem;">
                        <span style="font-size:0.85rem;color:#495057;">Language preference</span>
                        <textarea name="language_notes" rows="2" class="form-control" placeholder="Preferred languages for carer interaction."><?= _esc($careNeeds['language_notes'] ?? '') ?></textarea>
                    </label>
                </div>
            </details>

            <label style="display:block;margin-bottom:0.6rem;">
                <span style="font-size:0.85rem;color:#495057;">Top-line care summary (what the quoter / scheduler reads first)</span>
                <textarea name="care_summary" rows="3" class="form-control"><?= _esc($careNeeds['care_summary'] ?? '') ?></textarea>
            </label>

            <label style="display:block;margin-bottom:0.8rem;max-width:300px;">
                <span style="font-size:0.85rem;color:#495057;">Last reviewed date</span>
                <input type="date" name="last_reviewed_date" class="form-control"
                       value="<?= _esc($careNeeds['last_reviewed_date'] ?? '') ?>">
            </label>

            <div style="display:flex;gap:0.5rem;">
                <button type="submit" class="btn btn-primary">Save care needs</button>
                <a href="?patient_id=<?= $personId ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    <?php elseif (empty($careNeeds)): ?>
        <p style="color:#6c757d;font-style:italic;">No care-needs profile captured yet.
            <?php if ($canEditCareNeeds): ?>
                <a href="?patient_id=<?= $personId ?>&edit=care_needs">Add one</a>.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <?php if (!empty($careNeeds['care_summary'])): ?>
            <p style="background:#f0fdf4;border-left:3px solid #15803d;padding:0.5rem 0.8rem;margin:0 0 0.8rem 0;white-space:pre-wrap;"><?= _esc($careNeeds['care_summary']) ?></p>
        <?php endif; ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.8rem;font-size:0.9rem;">
            <?php
            $cnRender = function (string $label, ?string $val, string $colour = '#1e293b') {
                if (empty(trim((string)$val))) return;
                echo '<div><strong style="color:' . $colour . ';">' . htmlspecialchars($label) . ':</strong><br>';
                echo '<span style="white-space:pre-wrap;">' . htmlspecialchars($val) . '</span></div>';
            };
            $cnRender('Medical conditions', $careNeeds['medical_conditions'] ?? null, '#842029');
            $cnRender('Allergies',          $careNeeds['allergies']          ?? null, '#842029');
            $cnRender('Medications',        $careNeeds['medications']        ?? null, '#842029');
            $cnRender('Mobility',           $careNeeds['mobility_notes']     ?? null);
            $cnRender('Hygiene',            $careNeeds['hygiene_notes']      ?? null);
            $cnRender('Cognitive',          $careNeeds['cognitive_notes']    ?? null);
            $cnRender('Emotional',          $careNeeds['emotional_notes']    ?? null);
            $cnRender('Dietary',            $careNeeds['dietary_notes']      ?? null);
            $cnRender('Recreational',       $careNeeds['recreational_notes'] ?? null);
            $cnRender('Language',           $careNeeds['language_notes']     ?? null);
            if (($careNeeds['dnr_status'] ?? 'unknown') === 'dnr_in_place') {
                echo '<div><strong style="color:#842029;">DNR in place:</strong><br>';
                echo '<span style="white-space:pre-wrap;">' . _esc($careNeeds['dnr_notes']) . '</span></div>';
            }
            ?>
        </div>
    <?php endif; ?>
    </div>
</details>
<?php endif; // canSeeCareNeeds ?>

<?php
// ─────────────────────────────────────────────────────────────
// Emergency contacts (Phase 4.5)
// ─────────────────────────────────────────────────────────────
$canSeeEC  = userCan('patient_emergency_contacts', 'read');
$canEditEC = userCan('patient_emergency_contacts', 'edit');
if ($canSeeEC):
    $contacts = $db->prepare(
        "SELECT * FROM patient_emergency_contacts
          WHERE person_id = ?
       ORDER BY is_primary DESC, sort_order, id"
    );
    $contacts->execute([$personId]);
    $contacts = $contacts->fetchAll(PDO::FETCH_ASSOC);
    $editECId = !empty($_GET['edit']) && $_GET['edit'] === 'ec'
                ? (int)($_GET['contact_id'] ?? 0) : null;
    $addingEC = !empty($_GET['edit']) && $_GET['edit'] === 'ec_new' && $canEditEC;
    $editingContact = null;
    if ($editECId !== null && $editECId > 0) {
        foreach ($contacts as $c) if ((int)$c['id'] === $editECId) { $editingContact = $c; break; }
    }
?>
<details class="card" style="margin-top:1rem;" <?= empty($contacts) ? '' : 'open' ?>>
    <summary style="cursor:pointer;padding:0.75rem 1rem;background:#f8f9fa;list-style:none;display:flex;justify-content:space-between;align-items:center;">
        <h3 style="margin:0;">
            Emergency contacts
            <span style="font-weight:400;font-size:0.82rem;color:#6c757d;margin-left:0.5rem;">
                (<?= count($contacts) ?>)
            </span>
        </h3>
        <?php if ($canEditEC && !$addingEC && !$editingContact): ?>
            <a href="?patient_id=<?= $personId ?>&edit=ec_new" onclick="event.stopPropagation();" class="btn btn-primary btn-sm">+ Add contact</a>
        <?php endif; ?>
    </summary>

    <div style="padding:1rem 1.2rem;">
    <?php if ($addingEC || $editingContact): $c = $editingContact ?? []; ?>
        <form method="POST" style="background:#f8fafc;border:1px solid #e2e8f0;padding:0.8rem 1rem;border-radius:4px;margin-bottom:0.8rem;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_emergency_contact">
            <input type="hidden" name="contact_id" value="<?= (int)($c['id'] ?? 0) ?>">
            <h4 style="margin-top:0;"><?= $editingContact ? 'Edit' : 'Add' ?> emergency contact</h4>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.6rem;">
                <label><span style="font-size:0.85rem;">Full name <span style="color:#dc3545;">*</span></span>
                    <input type="text" name="ec_full_name" required maxlength="200" class="form-control"
                           value="<?= _esc($c['full_name'] ?? '') ?>"></label>
                <label><span style="font-size:0.85rem;">Relationship</span>
                    <input type="text" name="ec_relationship" maxlength="50" class="form-control"
                           placeholder="spouse, son, daughter, GP, neighbour"
                           value="<?= _esc($c['relationship'] ?? '') ?>"></label>
                <label><span style="font-size:0.85rem;">Phone</span>
                    <input type="text" name="ec_phone" maxlength="30" class="form-control"
                           value="<?= _esc($c['phone'] ?? '') ?>"></label>
                <label><span style="font-size:0.85rem;">Alt. phone</span>
                    <input type="text" name="ec_alt_phone" maxlength="30" class="form-control"
                           value="<?= _esc($c['alt_phone'] ?? '') ?>"></label>
                <label><span style="font-size:0.85rem;">Email</span>
                    <input type="email" name="ec_email" maxlength="150" class="form-control"
                           value="<?= _esc($c['email'] ?? '') ?>"></label>
                <label><span style="font-size:0.85rem;">Sort order (lower = first)</span>
                    <input type="number" name="ec_sort_order" min="0" max="999" class="form-control"
                           value="<?= _esc($c['sort_order'] ?? '10') ?>"></label>
            </div>
            <label style="display:block;margin-top:0.6rem;">
                <input type="checkbox" name="ec_is_primary" value="1" <?= !empty($c['is_primary']) ? 'checked' : '' ?>>
                Primary contact — first to call in an incident (only one per patient)
            </label>
            <label style="display:block;">
                <input type="checkbox" name="ec_has_power_of_attorney" value="1" <?= !empty($c['has_power_of_attorney']) ? 'checked' : '' ?>>
                Has power of attorney — can make care decisions on patient's behalf
            </label>
            <label style="display:block;margin-top:0.6rem;">
                <span style="font-size:0.85rem;">Notes</span>
                <textarea name="ec_notes" rows="2" maxlength="500" class="form-control"><?= _esc($c['notes'] ?? '') ?></textarea>
            </label>
            <div style="display:flex;gap:0.5rem;margin-top:0.6rem;">
                <button type="submit" class="btn btn-primary">Save contact</button>
                <a href="?patient_id=<?= $personId ?>" class="btn btn-outline">Cancel</a>
                <?php if ($editingContact && userCan('patient_emergency_contacts', 'delete')): ?>
                    <form method="POST" style="display:inline;margin-left:auto;" onsubmit="return confirm('Delete this contact? Cannot be undone here.');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete_emergency_contact">
                        <input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
                        <button type="submit" class="btn" style="background:#dc3545;color:#fff;">Delete</button>
                    </form>
                <?php endif; ?>
            </div>
        </form>
    <?php endif; ?>

    <?php if (empty($contacts) && !$addingEC): ?>
        <p style="color:#6c757d;font-style:italic;">No emergency contacts on file.</p>
    <?php else: ?>
        <table class="report-table tch-data-table" style="font-size:0.9rem;">
            <thead><tr>
                <th>Name</th>
                <th>Relationship</th>
                <th>Phone</th>
                <th>Email</th>
                <th class="center">Flags</th>
                <th class="center" data-filterable="false"></th>
            </tr></thead>
            <tbody>
                <?php foreach ($contacts as $c): ?>
                    <tr>
                        <td>
                            <strong><?= _esc($c['full_name']) ?></strong>
                            <?php if (!empty($c['is_primary'])): ?>
                                <span style="background:#15803d;color:#fff;padding:1px 6px;border-radius:3px;font-size:0.7em;margin-left:0.3rem;">PRIMARY</span>
                            <?php endif; ?>
                        </td>
                        <td><?= _esc($c['relationship']) ?: '—' ?></td>
                        <td>
                            <?php if (!empty($c['phone'])): ?>
                                <a href="tel:<?= _esc($c['phone']) ?>"><?= _esc($c['phone']) ?></a>
                            <?php endif; ?>
                            <?php if (!empty($c['alt_phone'])): ?>
                                <div style="font-size:0.8rem;color:#6c757d;">alt: <?= _esc($c['alt_phone']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($c['email'])): ?>
                                <a href="mailto:<?= _esc($c['email']) ?>"><?= _esc($c['email']) ?></a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="center">
                            <?php if (!empty($c['has_power_of_attorney'])): ?>
                                <span style="background:#7c3aed;color:#fff;padding:1px 6px;border-radius:3px;font-size:0.7em;">POA</span>
                            <?php endif; ?>
                        </td>
                        <td class="center">
                            <?php if ($canEditEC): ?>
                                <a href="?patient_id=<?= $personId ?>&edit=ec&contact_id=<?= (int)$c['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>
</details>
<?php endif; // canSeeEC ?>

<?php renderActivityTimeline('persons', $personId, $canEdit); ?>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
