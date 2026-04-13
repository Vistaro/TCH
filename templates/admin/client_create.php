<?php
/**
 * Create new client — /admin/clients/new
 *
 * Two-stage POST:
 *   1. User fills form → server runs dedup → if matches found, render
 *      them above the form with "Use existing / Merge / Create anyway".
 *   2. Form re-posts with dedup_confirmed=1 → straight insert.
 *
 * Permission: client_view.create.
 */

require_once APP_ROOT . '/includes/countries.php';
require_once APP_ROOT . '/includes/activities_render.php';
require_once APP_ROOT . '/includes/contact_methods.php';
require_once APP_ROOT . '/includes/dedup.php';

$pageTitle = 'New Client';
$activeNav = 'clients';

$db = getDB();
$flash = '';
$flashType = 'error';

$form = [
    'salutation'      => '', 'first_name' => '', 'middle_names' => '', 'last_name' => '',
    'known_as'        => '',
    'patient_name'    => '', // care recipient name when ≠ client name
    'gender'          => '', 'dob' => '', 'id_passport' => '', 'nationality' => 'South African',
    'mobile_dial'     => '+27', 'mobile_national' => '',
    'email'           => '',
    'street_address'  => '', 'suburb' => '', 'city' => '', 'province' => '',
    'postal_code'     => '', 'country' => 'South Africa', 'complex_estate' => '',
    'account_number'  => '',
    'also_patient'    => '0',  // tick = create the matching patient row in same txn
];
// Billing entity is fixed for now — TCH only. Stored as 'TCH' on every new client.
$BILLING_ENTITY = 'TCH';
$BILLING_ENTITY_LABEL = 'TCH Placements';

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

        // Compose full_name from the name parts. (No standalone full_name field.)
        $form['full_name'] = preg_replace('/\s+/', ' ', trim(
            ($form['salutation']   ? $form['salutation']   . ' ' : '')
          . ($form['first_name']   ? $form['first_name']   . ' ' : '')
          . ($form['middle_names'] ? $form['middle_names'] . ' ' : '')
          . ($form['last_name']    ?? '')
        ));

        $mobile = joinE164($form['mobile_dial'] ?: '+27', $form['mobile_national']);

        if ($form['first_name'] === '' || $form['last_name'] === '') {
            $flash = 'First name and last name are both required.';
        } else {
            // ── Dedup check (skipped if user already confirmed) ───────
            if (!$confirmed) {
                $dedupMatches = findPossibleDuplicates('client', [
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
                // ── Insert ─────────────────────────────────────────────
                try {
                    $db->beginTransaction();

                    // Allocate next TCH ID
                    $nextNum = (int)$db->query(
                        "SELECT COALESCE(MAX(CAST(SUBSTRING(tch_id,5) AS UNSIGNED)),0) + 1
                         FROM persons WHERE tch_id LIKE 'TCH-%'"
                    )->fetchColumn();
                    $tchId = 'TCH-' . str_pad((string)$nextNum, 6, '0', STR_PAD_LEFT);

                    // Allocate account_number if not provided
                    $accountNumber = $form['account_number'] ?: null;
                    if (!$accountNumber) {
                        $nextAcc = (int)$db->query(
                            "SELECT COALESCE(MAX(CAST(SUBSTRING(account_number, 6) AS UNSIGNED)),0) + 1
                             FROM persons WHERE account_number LIKE 'TCH-C%'"
                        )->fetchColumn();
                        $accountNumber = 'TCH-C' . str_pad((string)$nextAcc, 4, '0', STR_PAD_LEFT);
                    }

                    $personType = !empty($_POST['also_patient']) ? 'patient,client' : 'client';

                    $db->prepare(
                        "INSERT INTO persons
                            (person_type, tch_id, account_number, full_name,
                             salutation, first_name, middle_names, last_name,
                             known_as, patient_name, gender, dob, id_passport, nationality,
                             mobile, email, complex_estate, street_address, suburb, city,
                             province, postal_code, country, billing_entity, created_at)
                         VALUES (?,?,?,?, ?,?,?,?, ?,?,?,?,?,?, ?,?,?,?,?,?, ?,?,?,?, NOW())"
                    )->execute([
                        $personType, $tchId, $accountNumber, $form['full_name'],
                        $form['salutation'] ?: null, $form['first_name'] ?: null,
                        $form['middle_names'] ?: null, $form['last_name'] ?: null,
                        $form['known_as'] ?: null, $form['patient_name'] ?: null,
                        $form['gender'] ?: null, $form['dob'] ?: null,
                        $form['id_passport'] ?: null, $form['nationality'] ?: null,
                        $mobile ?: null, $form['email'] ?: null,
                        $form['complex_estate'] ?: null, $form['street_address'] ?: null,
                        $form['suburb'] ?: null, $form['city'] ?: null,
                        $form['province'] ?: null, $form['postal_code'] ?: null,
                        $form['country'] ?: 'South Africa', $BILLING_ENTITY,
                    ]);
                    $newId = (int)$db->lastInsertId();

                    // clients row — id = persons.id by convention (matches migration 009)
                    $db->prepare(
                        "INSERT INTO clients (id, person_id, account_number, billing_entity)
                         VALUES (?, ?, ?, ?)"
                    )->execute([$newId, $newId, $accountNumber, $BILLING_ENTITY]);

                    // Optional: also create patient row if "same person" ticked
                    if (!empty($_POST['also_patient'])) {
                        $db->prepare(
                            "INSERT INTO patients (person_id, client_id, patient_name)
                             VALUES (?, ?, ?)"
                        )->execute([$newId, $newId, $form['patient_name'] ?: $form['full_name']]);
                    }

                    // Multi-contact tables — write the primary entries via the helpers
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
                    // Address — write primary if any address field given
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
                    }

                    $me = currentEffectiveUser();
                    logActivity('client_created', 'client_view', 'persons', $newId,
                        'Created client ' . $form['full_name'] . ' (' . $tchId . ')',
                        null, ['full_name' => $form['full_name'], 'account_number' => $accountNumber]);
                    logSystemActivity('persons', $newId,
                        'Client created',
                        'Manually created by ' . ($me['full_name'] ?? '?') . ' on ' . date('d M Y H:i'),
                        'client_create', 'manual-' . date('Ymd'));

                    if (!empty($dedupMatches) && $confirmed) {
                        // Record the "checked: not duplicate" decision in the timeline
                        logSystemActivity('persons', $newId,
                            'Dedup: kept as new record',
                            'User reviewed ' . count($dedupMatches) . ' possible match(es) on create and chose to proceed.',
                            'client_create#dedup-decision', 'dedup-' . date('Ymd-His'));
                    }

                    $db->commit();
                    header('Location: ' . APP_URL . '/admin/clients/' . $newId
                           . '?msg=' . urlencode('Client created.') . '&type=success');
                    exit;
                } catch (Throwable $e) {
                    $db->rollBack();
                    $flash = 'Could not create client: ' . $e->getMessage();
                }
            }
        }
    }
}

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="margin-bottom:1rem;">
    <a href="<?= APP_URL ?>/admin/clients" class="btn btn-outline btn-sm">&larr; Back to clients</a>
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
                We searched the existing un-archived client records and found these possible matches.
                Pick one to open it, or confirm and create anyway.
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
                        <td>
                            <a class="btn btn-outline btn-sm"
                               href="<?= APP_URL ?>/admin/clients/<?= (int)$m['person_id'] ?>">Open</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="font-size:0.9rem;color:#6c757d;margin:0;">
                None of these are the same person? Tick <em>“Create anyway”</em> below the form and re-submit.
            </p>
        </div>
    </div>
<?php endif; ?>

<form method="POST">
    <?= csrfField() ?>

    <div class="person-card">
        <div class="person-card-header">
            <div class="person-card-title">
                <h2>New Client</h2>
                <div class="person-card-meta">
                    Fields marked <span style="color:#dc3545;">*</span> are required.
                    Account number is allocated automatically if you leave it blank.
                </div>
            </div>
        </div>

        <div class="person-card-grid">
            <div class="person-card-section">
                <h3>Personal</h3>
                <dl class="edit-dl">
                    <dt>Salutation</dt>    <dd><input class="form-control" name="salutation"   value="<?= htmlspecialchars($form['salutation']) ?>" placeholder="Mr / Mrs / Dr"></dd>
                    <dt>First Name <span style="color:#dc3545;">*</span></dt>
                                            <dd><input class="form-control" name="first_name" required value="<?= htmlspecialchars($form['first_name']) ?>"></dd>
                    <dt>Middle Name(s)</dt><dd><input class="form-control" name="middle_names" value="<?= htmlspecialchars($form['middle_names']) ?>"></dd>
                    <dt>Last Name <span style="color:#dc3545;">*</span></dt>
                                            <dd><input class="form-control" name="last_name" required value="<?= htmlspecialchars($form['last_name']) ?>"></dd>
                    <dt>Known As</dt>      <dd><input class="form-control" name="known_as"     value="<?= htmlspecialchars($form['known_as']) ?>"></dd>
                    <dt>Patient Name</dt>  <dd><input class="form-control" name="patient_name" value="<?= htmlspecialchars($form['patient_name']) ?>" placeholder="Leave blank if same as client"></dd>
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
                <h3>Billing</h3>
                <dl class="edit-dl">
                    <dt>Billing Entity</dt><dd><input class="form-control" value="<?= htmlspecialchars($BILLING_ENTITY_LABEL) ?>" disabled style="background:#e9ecef;color:#6c757d;"></dd>
                    <dt>Account #</dt>     <dd><input class="form-control" name="account_number" value="<?= htmlspecialchars($form['account_number']) ?>" placeholder="TCH-C0001 (auto if blank)"></dd>
                </dl>
                <label style="display:flex;align-items:center;gap:0.5rem;margin-top:0.75rem;font-weight:normal;">
                    <input type="checkbox" name="also_patient" value="1" <?= !empty($_POST['also_patient']) ? 'checked' : '' ?>>
                    Same person is also the patient — create the patient record too
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
        <a href="<?= APP_URL ?>/admin/clients" class="btn btn-link">Cancel</a>
        <button type="submit" class="btn btn-primary">Create client</button>
    </div>
</form>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
