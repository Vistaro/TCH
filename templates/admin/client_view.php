<?php
/**
 * Client detail page — /admin/clients/{id}
 *
 * Mirrors student_view.php in shape: section-by-section edit-in-place,
 * Notes timeline, photo replace, archive, plus client-specific bits
 * (multi-phone/email render, billing entity, linked patients).
 *
 * Permission: client_view.read for view; client_view.edit for inline
 * edits, archive, and "Same person" toggle.
 */

require_once APP_ROOT . '/includes/activities_render.php';
require_once APP_ROOT . '/includes/countries.php';
require_once APP_ROOT . '/includes/contact_methods.php';

$pageTitle = 'Client';
$activeNav = 'clients';

$personId = (int)($_GET['client_id'] ?? 0);
$db       = getDB();
$canEdit  = userCan('client_view', 'edit');

// Sections that map to direct UPDATE on persons (one save endpoint).
$editableSections = [
    'personal' => ['table' => 'persons', 'cols' => ['salutation','first_name','middle_names','last_name','known_as','title','initials','id_passport','dob','gender','nationality','home_language','other_language']],
    'address'  => ['table' => 'persons', 'cols' => ['complex_estate','street_address','suburb','city','province','postal_code','country']],
    'billing'  => ['table' => 'persons', 'cols' => ['billing_freq','shift_type','schedule','day_rate']],
    'nok'      => ['table' => 'persons', 'cols' => ['nok_name','nok_relationship','nok_contact','nok_email']],
];

$flash = '';
$flashType = 'success';

// ── Helper: redirect with flash ────────────────────────────────────────
$redirectWithFlash = function (int $personId, string $msg, string $type = 'success'): void {
    header('Location: ' . APP_URL . '/admin/clients/' . $personId
           . '?msg=' . urlencode($msg) . '&type=' . urlencode($type));
    exit;
};

// ── Handle Notes form POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'save_activity' && $canEdit) {
    $res = saveActivityFromPost('persons', $personId);
    if ($res['ok']) $redirectWithFlash($personId, $res['msg'], 'success');
    $flash = $res['msg']; $flashType = 'error';
}

// ── Handle Photo Upload POST ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'upload_photo' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $redirectWithFlash($personId, 'Invalid form submission.', 'error');
    }
    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        $redirectWithFlash($personId, 'No file uploaded or upload failed.', 'error');
    }
    $tmp  = $_FILES['photo']['tmp_name'];
    $size = (int)$_FILES['photo']['size'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        $redirectWithFlash($personId, 'Photo must be JPG, PNG or WebP.', 'error');
    }
    if ($size > 5 * 1024 * 1024) {
        $redirectWithFlash($personId, 'Photo must be 5 MB or smaller.', 'error');
    }
    $tchRow = $db->prepare('SELECT tch_id, full_name FROM persons WHERE id = ?');
    $tchRow->execute([$personId]);
    $tchInfo = $tchRow->fetch() ?: [];
    $tchId = $tchInfo['tch_id'] ?? ('id-' . $personId);
    $ext = $allowed[$mime];
    $filename = 'profile_' . date('Ymd-His') . '.' . $ext;
    $relPath  = "people/{$tchId}/{$filename}";
    $absDir   = APP_ROOT . '/public/uploads/people/' . $tchId;
    if (!is_dir($absDir)) @mkdir($absDir, 0755, true);
    if (!move_uploaded_file($tmp, $absDir . '/' . $filename)) {
        $redirectWithFlash($personId, 'Failed to save the uploaded file.', 'error');
    }
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
    logActivity('photo_uploaded', 'client_view', 'persons', $personId,
        'New profile photo uploaded for ' . ($tchInfo['full_name'] ?? '?'),
        null, ['file_path' => $relPath]);
    logSystemActivity('persons', $personId, 'Profile photo updated',
        'New photo uploaded by ' . (currentEffectiveUser()['full_name'] ?? '?')
        . ' (' . $size . ' bytes, ' . $mime . ')',
        'client_view#photo', 'photo-' . date('Ymd'));
    $redirectWithFlash($personId, 'New photo saved.', 'success');
}

// ── Handle Save Phones POST (multi-row) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'save_phones' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $redirectWithFlash($personId, 'Invalid form submission.', 'error');
    $before = getPersonPhones($personId);
    $newPhones = parsePhonesFromPost();
    savePersonPhones($personId, $newPhones);
    $after = getPersonPhones($personId);
    logActivity('edit', 'client_view', 'persons', $personId,
        'Updated phones (' . count($after) . ' total)',
        ['phones' => array_column($before, 'phone')],
        ['phones' => array_column($after,  'phone')]);
    logSystemActivity('persons', $personId, 'Phones updated',
        count($after) . ' phone number(s) on file',
        'client_view#phones', 'edit-' . date('Ymd-His'));
    $redirectWithFlash($personId, 'Phones saved.', 'success');
}

// ── Handle Save Emails POST (multi-row) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'save_emails' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $redirectWithFlash($personId, 'Invalid form submission.', 'error');
    $before = getPersonEmails($personId);
    $newEmails = parseEmailsFromPost();
    savePersonEmails($personId, $newEmails);
    $after = getPersonEmails($personId);
    logActivity('edit', 'client_view', 'persons', $personId,
        'Updated emails (' . count($after) . ' total)',
        ['emails' => array_column($before, 'email')],
        ['emails' => array_column($after,  'email')]);
    logSystemActivity('persons', $personId, 'Emails updated',
        count($after) . ' email address(es) on file',
        'client_view#emails', 'edit-' . date('Ymd-His'));
    $redirectWithFlash($personId, 'Emails saved.', 'success');
}

// ── Handle Archive POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'archive' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $redirectWithFlash($personId, 'Invalid form submission.', 'error');
    $reason = trim((string)($_POST['reason'] ?? '')) ?: null;
    $me = currentEffectiveUser();
    $db->prepare(
        'UPDATE persons SET archived_at = NOW(), archived_by_user_id = ?, archived_reason = ?
         WHERE id = ? AND archived_at IS NULL'
    )->execute([(int)($me['id'] ?? 0) ?: null, $reason, $personId]);
    logActivity('archived', 'client_view', 'persons', $personId,
        'Archived client',
        ['archived_at' => null], ['archived_at' => date('Y-m-d H:i:s'), 'reason' => $reason]);
    logSystemActivity('persons', $personId, 'Record archived',
        'Archived by ' . ($me['full_name'] ?? '?') . ($reason ? ' — ' . $reason : ''),
        'client_view#archive', 'archive-' . date('Ymd'));
    $redirectWithFlash($personId, 'Client archived.', 'success');
}

// ── Handle Unarchive POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'unarchive' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $redirectWithFlash($personId, 'Invalid form submission.', 'error');
    $db->prepare(
        'UPDATE persons SET archived_at = NULL, archived_by_user_id = NULL, archived_reason = NULL
         WHERE id = ?'
    )->execute([$personId]);
    logActivity('unarchived', 'client_view', 'persons', $personId, 'Unarchived client', null, null);
    logSystemActivity('persons', $personId, 'Record unarchived',
        'Restored by ' . (currentEffectiveUser()['full_name'] ?? '?'),
        'client_view#unarchive', 'unarchive-' . date('Ymd'));
    $redirectWithFlash($personId, 'Client restored.', 'success');
}

// ── Handle Link Existing Patient POST (move a patient to bill this client) ───
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'link_existing_patient' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $redirectWithFlash($personId, 'Invalid form submission.', 'error');
    $patientId = (int)($_POST['patient_id'] ?? 0);
    if ($patientId <= 0) $redirectWithFlash($personId, 'Pick a patient.', 'error');
    $stmt = $db->prepare('SELECT client_id FROM patients WHERE person_id = ?');
    $stmt->execute([$patientId]);
    $oldClientId = (int)$stmt->fetchColumn();
    if ($oldClientId === $personId) $redirectWithFlash($personId, 'That patient is already billed to this client.', 'info');
    $me = currentEffectiveUser();
    $db->beginTransaction();
    try {
        $db->prepare(
            'UPDATE patient_client_history
             SET client_id = ?, changed_by_user_id = ?, reason = ?
             WHERE patient_person_id = ? AND valid_to IS NULL'
        )->execute([$personId, (int)($me['id'] ?? 0) ?: null,
                    'Linked from client profile', $patientId]);
        $db->prepare('UPDATE patients SET client_id = ? WHERE person_id = ?')
           ->execute([$personId, $patientId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        $redirectWithFlash($personId, 'Could not link patient: ' . $e->getMessage(), 'error');
    }
    logActivity('edit', 'client_view', 'persons', $patientId,
        'Linked patient to bill this client (Phase-1 retroactive)',
        ['client_id' => $oldClientId], ['client_id' => $personId]);
    logSystemActivity('persons', $patientId, 'Bill-paying client changed (retroactive)',
        'Patient moved from client #' . $oldClientId . ' to client #' . $personId
        . ' via Link from client profile. Phase-1 mode: applies to all historic shifts.',
        'client_view#link-patient', 'link-' . date('Ymd'));
    $redirectWithFlash($personId, 'Patient linked to this client (retroactive).', 'success');
}

// ── Handle Same-Person POST (set this client as also a patient) ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'same_person' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $redirectWithFlash($personId, 'Invalid form submission.', 'error');
    // Already a patient?
    $stmt = $db->prepare('SELECT COUNT(*) FROM patients WHERE person_id = ?');
    $stmt->execute([$personId]);
    if ((int)$stmt->fetchColumn() === 0) {
        // Patient row billed to themselves
        $db->prepare(
            "INSERT INTO patients (person_id, client_id, patient_name)
             SELECT ?, ?, full_name FROM persons WHERE id = ?"
        )->execute([$personId, $personId, $personId]);
    }
    // Add 'patient' to person_type SET
    $db->prepare(
        "UPDATE persons
         SET person_type = TRIM(BOTH ',' FROM
                            CONCAT_WS(',', person_type, IF(FIND_IN_SET('patient', person_type)=0, 'patient', NULL)))
         WHERE id = ?"
    )->execute([$personId]);
    logActivity('same_person_set', 'client_view', 'persons', $personId,
        'Marked as also a patient (same person)', null, ['person_type' => 'patient,client']);
    logSystemActivity('persons', $personId, 'Same person — patient record added',
        'Client is also the patient. Created patient row billed to themselves.',
        'client_view#same-person', 'same-person-' . date('Ymd'));
    $redirectWithFlash($personId, 'Marked as also a patient.', 'success');
}

// ── Handle Section-Edit POST (mirror student_view.php) ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'save_section' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $redirectWithFlash($personId, 'Invalid form submission.', 'error');
    }
    $section = $_POST['section'] ?? '';
    if (!isset($editableSections[$section])) {
        $redirectWithFlash($personId, 'Unknown section.', 'error');
    }
    $secDef  = $editableSections[$section];
    $table   = $secDef['table'];
    $colList = $secDef['cols'];
    $pkCol   = ($table === 'students') ? 'person_id' : 'id';

    $stmt = $db->prepare("SELECT * FROM `$table` WHERE `$pkCol` = ?");
    $stmt->execute([$personId]);
    $beforeRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $newValues = [];
    foreach ($colList as $col) {
        $val = $_POST[$col] ?? null;
        if (is_string($val)) $val = trim($val);
        if ($val === '') $val = null;
        $newValues[$col] = $val;
    }
    // Recompose full_name when name parts edited
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

    if (!$changed) {
        $redirectWithFlash($personId, 'No changes to save.', 'info');
    }

    $set = []; $params = [];
    foreach ($newValues as $col => $val) {
        $set[] = "`$col` = ?";
        $params[] = $val;
    }
    $params[] = $personId;
    $db->prepare("UPDATE `$table` SET " . implode(',', $set) . " WHERE `$pkCol` = ?")
       ->execute($params);

    // Mirror address into person_addresses primary if address section edited
    if ($section === 'address') {
        savePrimaryAddress($personId, $newValues);
    }

    logActivity('edit', 'client_view', 'persons', $personId,
        'Updated ' . $section . ' section (' . count($changed) . ' field' . (count($changed) === 1 ? '' : 's') . ')',
        array_combine(array_keys($changed), array_column($changed, 'from')),
        array_combine(array_keys($changed), array_column($changed, 'to')));

    foreach ($changed as $col => $diff) {
        $label = ucwords(str_replace('_', ' ', $col));
        $body  = "Was: " . ($diff['from'] ?? '(empty)') . "\nNow: " . ($diff['to'] ?? '(empty)');
        logSystemActivity('persons', $personId, $label . ' updated', $body,
            'client_view#' . $section, 'edit-' . date('Ymd-His'));
    }
    $redirectWithFlash($personId, count($changed) . ' field' . (count($changed) === 1 ? '' : 's') . ' updated.', 'success');
}

// ── Pick up flash from URL ─────────────────────────────────────────────
if ($flash === '' && isset($_GET['msg'])) {
    $flash = (string)$_GET['msg'];
    $flashType = (string)($_GET['type'] ?? 'success');
}

// ── Load person + client + linked patients ─────────────────────────────
$stmt = $db->prepare(
    "SELECT p.*, c.id AS client_id, c.account_number AS c_account_number,
            c.billing_entity AS c_billing_entity, c.billing_freq AS c_billing_freq
     FROM persons p
     LEFT JOIN clients c ON c.id = p.id
     WHERE p.id = ? AND FIND_IN_SET('client', p.person_type)"
);
$stmt->execute([$personId]);
$person = $stmt->fetch();

if (!$person) {
    require APP_ROOT . '/templates/layouts/admin.php';
    echo '<p>No client with id ' . (int)$personId . '.</p>';
    echo '<p><a href="' . APP_URL . '/admin/clients">Back to clients</a></p>';
    require APP_ROOT . '/templates/layouts/admin_footer.php';
    return;
}

$phones    = getPersonPhones($personId);
$emails    = getPersonEmails($personId);
$addresses = getPersonAddresses($personId);

// Linked patients (patients billed to this client)
$stmt = $db->prepare(
    "SELECT pt.person_id, pt.patient_name,
            p.full_name, p.tch_id, p.archived_at
     FROM patients pt
     JOIN persons p ON p.id = pt.person_id
     WHERE pt.client_id = ?
     ORDER BY p.full_name"
);
$stmt->execute([$personId]);
$linkedPatients = $stmt->fetchAll();

$stmt = $db->prepare(
    "SELECT a.*, at.code AS type_code
     FROM attachments a
     JOIN attachment_types at ON at.id = a.attachment_type_id
     WHERE a.person_id = ? AND a.is_active = 1
     ORDER BY at.sort_order, a.uploaded_at"
);
$stmt->execute([$personId]);
$attachments = $stmt->fetchAll();
$photoPath = null;
foreach ($attachments as $a) {
    if ($a['type_code'] === 'profile_photo') { $photoPath = $a['file_path']; break; }
}

$editSection = $_GET['edit'] ?? '';
if (!isset($editableSections[$editSection]) || !$canEdit) $editSection = '';
$editPhones = $canEdit && (($_GET['edit'] ?? '') === 'phones');
$editEmails = $canEdit && (($_GET['edit'] ?? '') === 'emails');

function _esc($v) { return htmlspecialchars((string)($v ?? '')); }
function _editLink(int $personId, string $section, bool $canEdit): string {
    if (!$canEdit) return '';
    return '<a href="' . APP_URL . '/admin/clients/' . $personId
         . '?edit=' . $section . '" class="btn btn-link btn-sm" style="float:right;padding:0;">Edit</a>';
}
function _formActions(int $personId): string {
    return '<div style="margin-top:0.75rem;text-align:right;">'
         . '<a href="' . APP_URL . '/admin/clients/' . $personId . '" class="btn btn-link">Cancel</a>'
         . ' <button type="submit" class="btn btn-primary btn-sm">Save</button></div>';
}

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center;">
    <a href="<?= APP_URL ?>/admin/clients" class="btn btn-outline btn-sm">&larr; Back to clients</a>
    <?php if ($canEdit && empty($person['archived_at'])): ?>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this client? They’ll hide from the default list but remain restorable.');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="archive">
            <input type="text" name="reason" placeholder="reason (optional)" class="form-control" style="display:inline-block;width:auto;">
            <button type="submit" class="btn btn-outline btn-sm">Archive</button>
        </form>
    <?php elseif ($canEdit && !empty($person['archived_at'])): ?>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Restore this archived client?');">
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
  // "Same person" check — true only when a patient row exists for this
  // person AND its patient_name is empty or matches the person's
  // full_name. If patient_name differs (legacy data: one persons row
  // labelled with the client's name but the recipient was someone
  // different), DO NOT claim same-person — call it out as a legacy
  // recipient-name mismatch instead.
  $selfPatient = null;
  $diffPatient = null;
  foreach ($linkedPatients as $pt) {
      if ((int)$pt['person_id'] === $personId) {
          $ptName = trim((string)($pt['patient_name'] ?? ''));
          if ($ptName === '' || strcasecmp($ptName, (string)$person['full_name']) === 0) {
              $selfPatient = $pt;
          } else {
              $diffPatient = $pt;
          }
          break;
      }
  }
?>
<?php if ($selfPatient): ?>
    <div style="background:#cce5ff;color:#004085;padding:0.6rem 1rem;border-radius:4px;margin-bottom:1rem;border-left:4px solid #0d6efd;">
        <strong>Same person</strong> — this client is also the patient
        (<a href="<?= APP_URL ?>/admin/patients/<?= $personId ?>" style="color:#004085;text-decoration:underline;">open patient view</a>).
        Edits to personal, contact and address fields apply to both records — there is only one underlying person.
    </div>
<?php elseif ($diffPatient): ?>
    <div style="background:#fff3cd;color:#856404;padding:0.6rem 1rem;border-radius:4px;margin-bottom:1rem;border-left:4px solid #ffc107;">
        <strong>Legacy data — recipient name differs from client name.</strong>
        This client record is also flagged as a patient, but the patient row carries a different recipient name
        (<em><?= _esc($diffPatient['patient_name']) ?></em> vs <em><?= _esc($person['full_name']) ?></em>).
        These are likely two different people sharing one record — should be split into two `persons` rows.
        Logged for cleanup. Edits to this record will affect both views.
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
            <div class="person-card-tch-id">
                <?= _esc($person['tch_id'] ?? '—') ?>
                <?php if ($person['account_number']): ?>
                    &middot; <code><?= _esc($person['account_number']) ?></code>
                <?php endif; ?>
            </div>
            <div class="person-card-meta">
                Type: <strong><?= _esc($person['person_type']) ?></strong>
                <?php if ($person['c_billing_entity']): ?>
                    &middot; Entity: <strong><?= _esc($person['c_billing_entity']) ?></strong>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="person-card-grid">

        <!-- Personal -->
        <div class="person-card-section">
            <h3>Personal <?= _editLink($personId, 'personal', $canEdit && $editSection !== 'personal') ?></h3>
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
                        <dt>Title</dt>         <dd><input class="form-control" name="title"        value="<?= _esc($person['title']) ?>"></dd>
                        <dt>Initials</dt>      <dd><input class="form-control" name="initials"     value="<?= _esc($person['initials']) ?>"></dd>
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
                        <dt>Home Lang</dt>     <dd><input class="form-control" name="home_language" value="<?= _esc($person['home_language']) ?>"></dd>
                        <dt>Other Lang</dt>    <dd><input class="form-control" name="other_language" value="<?= _esc($person['other_language']) ?>"></dd>
                    </dl>
                    <?= _formActions($personId) ?>
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

        <!-- Phones (multi-row) -->
        <div class="person-card-section">
            <h3>Phones
                <?php if ($canEdit && !$editPhones): ?>
                    <a href="<?= APP_URL ?>/admin/clients/<?= $personId ?>?edit=phones" class="btn btn-link btn-sm" style="float:right;padding:0;">Edit</a>
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
                    foreach ($rows as $ph):
                        [$dial, $nat] = splitE164($ph['phone'] ?? '');
                    ?>
                        <tr>
                            <td><input class="form-control" name="phones_label[]" value="<?= _esc($ph['label']) ?>" placeholder="Mobile / Work…" style="min-width:120px;"></td>
                            <td>
                                <div style="display:flex;gap:0.3rem;">
                                    <?php renderDialPrefixSelect('phones_dial[]', $dial); ?>
                                    <input class="form-control" name="phones_national[]" value="<?= _esc($nat) ?>" placeholder="national">
                                </div>
                            </td>
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
                    <?= _formActions($personId) ?>
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

        <!-- Emails (multi-row) -->
        <div class="person-card-section">
            <h3>Emails
                <?php if ($canEdit && !$editEmails): ?>
                    <a href="<?= APP_URL ?>/admin/clients/<?= $personId ?>?edit=emails" class="btn btn-link btn-sm" style="float:right;padding:0;">Edit</a>
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
                    foreach ($rows as $em):
                    ?>
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
                    <?= _formActions($personId) ?>
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
            <h3>Address <?= _editLink($personId, 'address', $canEdit && $editSection !== 'address') ?></h3>
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
                    <?= _formActions($personId) ?>
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

        <!-- Billing -->
        <div class="person-card-section">
            <h3>Billing <?= _editLink($personId, 'billing', $canEdit && $editSection !== 'billing') ?></h3>
            <?php if ($editSection === 'billing'): ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_section">
                    <input type="hidden" name="section" value="billing">
                    <dl class="edit-dl">
                        <dt>Billing Entity</dt><dd>
                            <input class="form-control" value="TCH Placements" disabled style="background:#e9ecef;color:#6c757d;">
                        </dd>
                        <dt>Billing Frequency</dt><dd><input class="form-control" name="billing_freq" value="<?= _esc($person['billing_freq']) ?>" placeholder="Monthly / Weekly"></dd>
                        <dt>Shift Type</dt>      <dd><input class="form-control" name="shift_type"   value="<?= _esc($person['shift_type']) ?>" placeholder="Day / Night / Live-In"></dd>
                        <dt>Schedule</dt>        <dd><input class="form-control" name="schedule"     value="<?= _esc($person['schedule']) ?>"></dd>
                        <dt>Day Rate (R)</dt>    <dd><input class="form-control" type="number" step="0.01" name="day_rate" value="<?= _esc($person['day_rate']) ?>"></dd>
                    </dl>
                    <?= _formActions($personId) ?>
                </form>
            <?php else: ?>
                <dl>
                    <dt>Account #</dt>       <dd><code><?= _esc($person['account_number']) ?: '—' ?></code></dd>
                    <dt>Billing Entity</dt>  <dd>TCH Placements</dd>
                    <dt>Billing Freq</dt>    <dd><?= _esc($person['billing_freq']) ?: '—' ?></dd>
                    <dt>Shift Type</dt>      <dd><?= _esc($person['shift_type']) ?: '—' ?></dd>
                    <dt>Schedule</dt>        <dd><?= _esc($person['schedule']) ?: '—' ?></dd>
                    <dt>Day Rate</dt>        <dd><?= $person['day_rate'] !== null ? 'R ' . number_format((float)$person['day_rate'], 2) : '—' ?></dd>
                </dl>
            <?php endif; ?>
        </div>

        <!-- Linked patients -->
        <div class="person-card-section">
            <h3>Patients billed to this client</h3>
            <?php if (empty($linkedPatients)): ?>
                <p style="color:#6c757d;margin:0;">No patients linked yet.</p>
            <?php else: ?>
                <ul style="list-style:none;padding:0;margin:0;">
                <?php foreach ($linkedPatients as $pt): ?>
                    <li style="padding:0.25rem 0;">
                        <a href="<?= APP_URL ?>/admin/patients/<?= (int)$pt['person_id'] ?>"><?= _esc($pt['patient_name'] ?: $pt['full_name']) ?></a>
                        <code style="color:#6c757d;font-size:0.8rem;"><?= _esc($pt['tch_id']) ?></code>
                        <?php if ($pt['archived_at']): ?><span style="color:#856404;font-size:0.75rem;">(archived)</span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <div style="margin-top:0.75rem;display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
                    <a class="btn btn-outline btn-sm" href="<?= APP_URL ?>/admin/patients/new?client_id=<?= $personId ?>">+ Add new patient under this client</a>
                    <?php
                      // Patients NOT already billed to this client (un-archived only)
                      $stmt = $db->prepare(
                          "SELECT pt.person_id, p.full_name, p.tch_id
                           FROM patients pt
                           JOIN persons p ON p.id = pt.person_id
                           WHERE p.archived_at IS NULL
                             AND pt.client_id <> ?
                           ORDER BY p.full_name LIMIT 500"
                      );
                      $stmt->execute([$personId]);
                      $linkable = $stmt->fetchAll();
                    ?>
                    <?php if (!empty($linkable)): ?>
                        <form method="POST" style="display:flex;gap:0.4rem;align-items:center;"
                              onsubmit="return confirm('Move that patient so this client pays for them? Phase-1 mode: applies to all historic shifts.');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="link_existing_patient">
                            <select name="patient_id" class="form-control" style="max-width:280px;">
                                <option value="">— link existing patient —</option>
                                <?php foreach ($linkable as $lp): ?>
                                    <option value="<?= (int)$lp['person_id'] ?>">
                                        <?= htmlspecialchars($lp['full_name']) ?> (<?= htmlspecialchars($lp['tch_id']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-outline btn-sm" type="submit">Link</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php
                  // Show "Same person" toggle if this client isn't already a patient
                  $alreadyPatient = false;
                  foreach ($linkedPatients as $pt) {
                      if ((int)$pt['person_id'] === $personId) { $alreadyPatient = true; break; }
                  }
                ?>
                <?php if (!$alreadyPatient): ?>
                    <form method="POST" style="margin-top:0.5rem;" onsubmit="return confirm('Mark this client as also being the patient?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="same_person">
                        <button type="submit" class="btn btn-link btn-sm" style="padding:0;">Same person — make this client the patient too</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Notes timeline -->
<?php renderActivityTimeline('persons', $personId, $canEdit); ?>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
