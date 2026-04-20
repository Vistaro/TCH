<?php
/**
 * Create new patient — /admin/patients/new
 *
 * A patient is a persons row + a patients row (which holds the FK to
 * the bill-paying client). One client per patient.
 *
 * Two-stage POST as in client_create: dedup screen first, then commit.
 *
 * Permission: patient_view.create.
 */

require_once APP_ROOT . '/includes/countries.php';
require_once APP_ROOT . '/includes/activities_render.php';
require_once APP_ROOT . '/includes/contact_methods.php';
require_once APP_ROOT . '/includes/dedup.php';

$pageTitle = 'New Patient';
$activeNav = 'patients';

$db = getDB();
$flash = '';
$flashType = 'error';

// Load the client dropdown — un-archived clients only.
$clients = $db->query(
    "SELECT c.id, p.full_name AS client_name, c.account_number, p.tch_id
     FROM clients c
     JOIN persons p ON p.id = c.person_id
     WHERE p.archived_at IS NULL
     ORDER BY p.full_name"
)->fetchAll();

$form = [
    'salutation' => '', 'first_name' => '', 'middle_names' => '', 'last_name' => '',
    'known_as'   => '',
    'gender'     => '', 'dob' => '', 'id_passport' => '', 'nationality' => 'South African',
    'mobile_dial' => '+27', 'mobile_national' => '',
    'email' => '',
    'street_address' => '', 'suburb' => '', 'city' => '', 'province' => '',
    'postal_code' => '', 'country' => 'South Africa', 'complex_estate' => '',
    'client_id' => '',          // billed-to client (required)
    'create_new_client' => '0', // tick = also create the matching client row
];

$dedupMatches = [];
$confirmed    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.';
    } else {
        foreach (array_keys($form) as $k) {
            $form[$k] = trim((string)($_POST[$k] ?? $form[$k]));
        }
        $confirmed = !empty($_POST['dedup_confirmed']);

        $form['full_name'] = preg_replace('/\s+/', ' ', trim(
            ($form['salutation']   ? $form['salutation']   . ' ' : '')
          . ($form['first_name']   ? $form['first_name']   . ' ' : '')
          . ($form['middle_names'] ? $form['middle_names'] . ' ' : '')
          . ($form['last_name']    ?? '')
        ));

        $mobile  = joinE164($form['mobile_dial'] ?: '+27', $form['mobile_national']);
        $newClient = !empty($_POST['create_new_client']);

        if ($form['first_name'] === '' || $form['last_name'] === '') {
            $flash = 'First name and last name are both required.';
        } elseif (!$newClient && empty($form['client_id'])) {
            $flash = 'Pick a client to bill this patient to (or tick "Create a new client at the same time").';
        } else {
            if (!$confirmed) {
                $dedupMatches = findPossibleDuplicates('patient', [
                    'full_name'   => $form['full_name'],
                    'phones'      => $mobile ? [$mobile] : [],
                    'emails'      => $form['email'] ? [$form['email']] : [],
                    'id_passport' => $form['id_passport'] ?: null,
                ]);
            }

            if (!empty($dedupMatches) && !$confirmed) {
                $flash = count($dedupMatches) . ' possibly matching record(s) found — review below.';
                $flashType = 'info';
            } else {
                try {
                    $db->beginTransaction();

                    $nextNum = (int)$db->query(
                        "SELECT COALESCE(MAX(CAST(SUBSTRING(tch_id,5) AS UNSIGNED)),0) + 1
                         FROM persons WHERE tch_id LIKE 'TCH-%'"
                    )->fetchColumn();
                    $tchId = 'TCH-' . str_pad((string)$nextNum, 6, '0', STR_PAD_LEFT);

                    $personType = $newClient ? 'patient,client' : 'patient';

                    $accountNumber = null;
                    if ($newClient) {
                        $nextAcc = (int)$db->query(
                            "SELECT COALESCE(MAX(CAST(SUBSTRING(account_number, 6) AS UNSIGNED)),0) + 1
                             FROM persons WHERE account_number LIKE 'TCH-C%'"
                        )->fetchColumn();
                        $accountNumber = 'TCH-C' . str_pad((string)$nextAcc, 4, '0', STR_PAD_LEFT);
                    }

                    $db->prepare(
                        "INSERT INTO persons
                            (person_type, tch_id, account_number, full_name,
                             salutation, first_name, middle_names, last_name,
                             known_as, gender, dob, id_passport, nationality,
                             mobile, email, complex_estate, street_address, suburb, city,
                             province, postal_code, country, created_at)
                         VALUES (?,?,?,?, ?,?,?,?, ?,?,?,?,?, ?,?,?,?,?,?, ?,?,?, NOW())"
                    )->execute([
                        $personType, $tchId, $accountNumber, $form['full_name'],
                        $form['salutation'] ?: null, $form['first_name'] ?: null,
                        $form['middle_names'] ?: null, $form['last_name'] ?: null,
                        $form['known_as'] ?: null, $form['gender'] ?: null,
                        $form['dob'] ?: null, $form['id_passport'] ?: null,
                        $form['nationality'] ?: null,
                        $mobile ?: null, $form['email'] ?: null,
                        $form['complex_estate'] ?: null, $form['street_address'] ?: null,
                        $form['suburb'] ?: null, $form['city'] ?: null,
                        $form['province'] ?: null, $form['postal_code'] ?: null,
                        $form['country'] ?: 'South Africa',
                    ]);
                    $newId = (int)$db->lastInsertId();

                    // If creating a matching client (same person is both):
                    $clientId = (int)($form['client_id'] ?: 0);
                    if ($newClient) {
                        $db->prepare(
                            "INSERT INTO clients (id, person_id, account_number)
                             VALUES (?, ?, ?)"
                        )->execute([$newId, $newId, $accountNumber]);
                        $clientId = $newId;
                    }

                    $db->prepare(
                        "INSERT INTO patients (person_id, client_id, patient_name)
                         VALUES (?, ?, ?)"
                    )->execute([$newId, $clientId, $form['full_name']]);

                    if ($mobile) {
                        savePersonPhones($newId, [
                            ['label' => 'Mobile', 'phone' => $mobile, 'is_primary' => true],
                        ]);
                    }
                    if ($form['email']) {
                        savePersonEmails($newId, [
                            ['label' => 'Primary', 'email' => $form['email'], 'is_primary' => true],
                        ]);
                    }
                    if ($form['street_address'] || $form['suburb'] || $form['city'] || $form['postal_code']) {
                        savePrimaryAddress($newId, [
                            'label'          => 'Primary',
                            'complex_estate' => $form['complex_estate'] ?: null,
                            'street_address' => $form['street_address'] ?: null,
                            'suburb'         => $form['suburb'] ?: null,
                            'city'           => $form['city'] ?: null,
                            'province'       => $form['province'] ?: null,
                            'postal_code'    => $form['postal_code'] ?: null,
                            'country'        => $form['country'] ?: 'South Africa',
                        ]);
                        // FR-N Phase 2 auto-geocode on initial save. Silent on failure —
                        // backfill page picks up anything missed.
                        require_once APP_ROOT . '/includes/geocode.php';
                        geocodePersonAndSave($db, $newId, force: true);
                    }

                    $me = currentEffectiveUser();
                    logActivity('patient_created', 'patient_view', 'persons', $newId,
                        'Created patient ' . $form['full_name'] . ' (' . $tchId . ')',
                        null, ['full_name' => $form['full_name'], 'client_id' => $clientId]);
                    logSystemActivity('persons', $newId,
                        'Patient created',
                        'Manually created by ' . ($me['full_name'] ?? '?') . ' on ' . date('d M Y H:i'),
                        'patient_create', 'manual-' . date('Ymd'));

                    if (!empty($dedupMatches) && $confirmed) {
                        logSystemActivity('persons', $newId,
                            'Dedup: kept as new record',
                            'User reviewed ' . count($dedupMatches) . ' possible match(es) on create and chose to proceed.',
                            'patient_create#dedup-decision', 'dedup-' . date('Ymd-His'));
                    }

                    $db->commit();
                    header('Location: ' . APP_URL . '/admin/patients/' . $newId
                           . '?msg=' . urlencode('Patient created.') . '&type=success');
                    exit;
                } catch (Throwable $e) {
                    $db->rollBack();
                    $flash = 'Could not create patient: ' . $e->getMessage();
                }
            }
        }
    }
}

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="margin-bottom:1rem;">
    <a href="<?= APP_URL ?>/admin/patients" class="btn btn-outline btn-sm">&larr; Back to patients</a>
</div>

<?php if ($flash): ?>
    <div class="flash" style="margin-bottom:1rem;padding:0.5rem 1rem;border-radius:4px;background:<?= $flashType === 'error' ? '#f8d7da' : ($flashType === 'info' ? '#cce5ff' : '#d4edda') ?>;">
        <?= htmlspecialchars($flash) ?>
    </div>
<?php endif; ?>

<?php if (!empty($dedupMatches)): ?>
    <div class="card" style="max-width:760px;margin-bottom:1rem;border-left:3px solid #ffc107;">
        <div class="card-header" style="background:#fff8e1;"><h3 style="margin:0;">Possible matches</h3></div>
        <div style="padding:1rem;">
            <p style="margin-top:0;color:#6c757d;">
                We searched the existing un-archived patient records and found these possible matches.
            </p>
            <table class="report-table tch-data-table" style="margin-bottom:1rem;">
                <thead><tr><th>TCH ID</th><th>Name</th><th>Type</th><th>Why matched</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($dedupMatches as $m): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($m['tch_id'] ?? '—') ?></code></td>
                        <td><?= htmlspecialchars($m['full_name']) ?></td>
                        <td style="font-size:0.85rem;color:#6c757d;"><?= htmlspecialchars($m['person_type']) ?></td>
                        <td style="font-size:0.85rem;"><?= htmlspecialchars(implode(' · ', $m['reasons'])) ?></td>
                        <td><a class="btn btn-outline btn-sm" href="<?= APP_URL ?>/admin/patients/<?= (int)$m['person_id'] ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<form method="POST">
    <?= csrfField() ?>

    <div class="person-card">
        <div class="person-card-header">
            <div class="person-card-title">
                <h2>New Patient</h2>
                <div class="person-card-meta">
                    Fields marked <span style="color:#dc3545;">*</span> are required.
                </div>
            </div>
        </div>

        <div class="person-card-grid">
            <div class="person-card-section">
                <h3>Personal</h3>
                <dl class="edit-dl">
                    <dt>Salutation</dt>    <dd><input class="form-control" name="salutation"   value="<?= htmlspecialchars($form['salutation']) ?>"></dd>
                    <dt>First Name <span style="color:#dc3545;">*</span></dt>
                                            <dd><input class="form-control" name="first_name" required value="<?= htmlspecialchars($form['first_name']) ?>"></dd>
                    <dt>Middle Name(s)</dt><dd><input class="form-control" name="middle_names" value="<?= htmlspecialchars($form['middle_names']) ?>"></dd>
                    <dt>Last Name <span style="color:#dc3545;">*</span></dt>
                                            <dd><input class="form-control" name="last_name" required value="<?= htmlspecialchars($form['last_name']) ?>"></dd>
                    <dt>Known As</dt>      <dd><input class="form-control" name="known_as"     value="<?= htmlspecialchars($form['known_as']) ?>"></dd>
                    <dt>Date of Birth</dt> <dd><input class="form-control" type="date" name="dob" value="<?= htmlspecialchars($form['dob']) ?>"></dd>
                    <dt>Gender</dt>        <dd>
                        <select class="form-control" name="gender">
                            <option value="">—</option>
                            <?php foreach (['Female','Male','Other'] as $g): ?>
                                <option value="<?= $g ?>" <?= $form['gender']===$g?'selected':'' ?>><?= $g ?></option>
                            <?php endforeach; ?>
                        </select>
                    </dd>
                    <dt>ID / Passport</dt> <dd><input class="form-control" name="id_passport"  value="<?= htmlspecialchars($form['id_passport']) ?>"></dd>
                    <dt>Nationality</dt>   <dd><input class="form-control" name="nationality"  value="<?= htmlspecialchars($form['nationality']) ?>"></dd>
                </dl>
            </div>

            <div class="person-card-section">
                <h3>Contact</h3>
                <dl class="edit-dl">
                    <dt>Mobile</dt>
                    <dd style="display:flex;gap:0.4rem;">
                        <?php renderDialPrefixSelect('mobile_dial', $form['mobile_dial']); ?>
                        <input class="form-control" name="mobile_national" value="<?= htmlspecialchars($form['mobile_national']) ?>" placeholder="national number">
                    </dd>
                    <dt>Email</dt>
                    <dd><input class="form-control" type="email" name="email" value="<?= htmlspecialchars($form['email']) ?>"></dd>
                </dl>
                <p style="color:#6c757d;font-size:0.85rem;margin:0.5rem 0 0;">
                    Add more phones / emails on the profile page after creating the record.
                </p>
            </div>

            <div class="person-card-section">
                <h3>Address</h3>
                <dl class="edit-dl">
                    <dt>Complex/Estate</dt><dd><input class="form-control" name="complex_estate" value="<?= htmlspecialchars($form['complex_estate']) ?>"></dd>
                    <dt>Street</dt>        <dd><input class="form-control" name="street_address" value="<?= htmlspecialchars($form['street_address']) ?>"></dd>
                    <dt>Suburb</dt>        <dd><input class="form-control" name="suburb"         value="<?= htmlspecialchars($form['suburb']) ?>"></dd>
                    <dt>City</dt>          <dd><input class="form-control" name="city"           value="<?= htmlspecialchars($form['city']) ?>"></dd>
                    <dt>Province</dt>      <dd><input class="form-control" name="province"       value="<?= htmlspecialchars($form['province']) ?>"></dd>
                    <dt>Postal Code</dt>   <dd><input class="form-control" name="postal_code"    value="<?= htmlspecialchars($form['postal_code']) ?>"></dd>
                    <dt>Country</dt>       <dd><?php renderCountrySelect('country', $form['country'] ?: 'South Africa'); ?></dd>
                </dl>
            </div>

            <div class="person-card-section">
                <h3>Billed To</h3>
                <dl class="edit-dl">
                    <dt>Client <span style="color:#dc3545;">*</span></dt>
                    <dd>
                        <select class="form-control" name="client_id">
                            <option value="">— pick existing client —</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= ((int)$form['client_id'] === (int)$c['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['client_name']) ?>
                                    <?= $c['account_number'] ? ' (' . htmlspecialchars($c['account_number']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </dd>
                </dl>
                <label style="display:flex;align-items:center;gap:0.5rem;margin-top:0.75rem;font-weight:normal;">
                    <input type="checkbox" name="create_new_client" value="1" <?= !empty($_POST['create_new_client']) ? 'checked' : '' ?>>
                    Same person is also the client — create both records together
                </label>
            </div>
        </div>
    </div>

    <?php if (!empty($dedupMatches)): ?>
        <div style="margin-top:1rem;padding:0.75rem;background:#fff8e1;border-radius:4px;">
            <label style="display:flex;align-items:center;gap:0.5rem;">
                <input type="checkbox" name="dedup_confirmed" value="1">
                <strong>None of those matches are this person — create anyway</strong>
            </label>
        </div>
    <?php endif; ?>

    <div style="margin-top:1rem;text-align:right;">
        <a href="<?= APP_URL ?>/admin/patients" class="btn btn-link">Cancel</a>
        <button type="submit" class="btn btn-primary">Create patient</button>
    </div>
</form>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
