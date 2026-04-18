<?php
/**
 * Opportunity create / edit — /admin/opportunities/new and /{id}/edit
 *
 * Single form handles both create and edit. Client + patient fields
 * are optional (nullable early; filled as the opp qualifies). If the
 * user picks a client + patient, they become linked; otherwise we
 * keep the contact snapshot (name/email/phone) carried from enquiry.
 */
require_once APP_ROOT . '/includes/opportunities.php';

$db = getDB();
$canEdit   = userCan('opportunities', 'edit');
$canCreate = userCan('opportunities', 'create');

$oppId  = (int)($_GET['opp_id'] ?? 0);
$isEdit = $oppId > 0;

$pageTitle = $isEdit ? 'Edit Opportunity' : 'New Opportunity';
$activeNav = 'opportunities';

$flash = '';
$flashType = 'success';

// Convert-from-enquiry prefill (only used when ?from_enquiry=N and no POST yet)
$fromEnquiryId = (!$isEdit && $_SERVER['REQUEST_METHOD'] !== 'POST')
    ? (int)($_GET['from_enquiry'] ?? 0)
    : 0;
$enquiryPrefill = null;
if ($fromEnquiryId > 0) {
    $stmt = $db->prepare("SELECT * FROM enquiries WHERE id = ?");
    $stmt->execute([$fromEnquiryId]);
    $enquiryPrefill = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Handle POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($canCreate || $canEdit)) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } else {
        try {
            $db->beginTransaction();

            $stageId      = (int)$_POST['stage_id'];
            $title        = trim((string)($_POST['title'] ?? ''));
            $source       = $_POST['source'] ?? 'enquiry';
            $sourceEnqId  = $_POST['source_enquiry_id'] !== '' ? (int)$_POST['source_enquiry_id'] : null;
            $sourceNote   = trim($_POST['source_note'] ?? '') ?: null;
            $clientId     = $_POST['client_id']          !== '' ? (int)$_POST['client_id']         : null;
            $patientId    = $_POST['patient_person_id']  !== '' ? (int)$_POST['patient_person_id'] : null;
            $contactName  = trim($_POST['contact_name']  ?? '') ?: null;
            $contactEmail = trim($_POST['contact_email'] ?? '') ?: null;
            $contactPhone = trim($_POST['contact_phone'] ?? '') ?: null;
            $ownerId      = $_POST['owner_user_id']      !== '' ? (int)$_POST['owner_user_id']     : null;
            $careSummary  = trim($_POST['care_summary']  ?? '') ?: null;
            $expectedVal  = $_POST['expected_value_rand'] !== ''
                            ? (int)round(((float)$_POST['expected_value_rand']) * 100)
                            : null;
            $expectedStart= $_POST['expected_start_date'] ?: null;
            $notes        = trim($_POST['notes'] ?? '') ?: null;
            $uid          = (int)($_SESSION['user_id'] ?? 0) ?: null;

            if ($title === '' || !$stageId) {
                throw new RuntimeException('Title and stage are required.');
            }
            // If the source is enquiry, an FK must be set
            if ($source === 'enquiry' && !$sourceEnqId) {
                // Not fatal — allow typing a source note instead; but clear the source to other
                $source = 'other';
            }

            if ($isEdit) {
                // Snapshot before
                $stmtB = $db->prepare("SELECT stage_id, title, owner_user_id, client_id, patient_person_id,
                                              expected_value_cents, expected_start_date, status
                                         FROM opportunities WHERE id = ?");
                $stmtB->execute([$oppId]);
                $before = $stmtB->fetch(PDO::FETCH_ASSOC) ?: [];

                $stmt = $db->prepare(
                    "UPDATE opportunities SET
                        title = ?, stage_id = ?,
                        source = ?, source_enquiry_id = ?, source_note = ?,
                        client_id = ?, patient_person_id = ?,
                        contact_name = ?, contact_email = ?, contact_phone = ?,
                        owner_user_id = ?,
                        care_summary = ?,
                        expected_value_cents = ?, expected_start_date = ?,
                        notes = ?
                     WHERE id = ?"
                );
                $stmt->execute([
                    $title, $stageId,
                    $source, $sourceEnqId, $sourceNote,
                    $clientId, $patientId,
                    $contactName, $contactEmail, $contactPhone,
                    $ownerId,
                    $careSummary,
                    $expectedVal, $expectedStart,
                    $notes, $oppId,
                ]);

                $after = [
                    'stage_id' => $stageId, 'title' => $title,
                    'owner_user_id' => $ownerId, 'client_id' => $clientId,
                    'patient_person_id' => $patientId,
                    'expected_value_cents' => $expectedVal,
                    'expected_start_date' => $expectedStart,
                ];

                $db->commit();

                logActivity(
                    'opportunity_updated', 'opportunities', 'opportunities', $oppId,
                    'Updated ' . $title, $before, $after
                );

                header('Location: ' . APP_URL . '/admin/opportunities/' . $oppId . '?msg=' . urlencode('Opportunity updated.'));
                exit;
            } else {
                $oppRef = nextOppRef($db);

                $stmt = $db->prepare(
                    "INSERT INTO opportunities
                        (opp_ref, title, stage_id,
                         source, source_enquiry_id, source_note,
                         client_id, patient_person_id,
                         contact_name, contact_email, contact_phone,
                         owner_user_id,
                         care_summary,
                         expected_value_cents, expected_start_date,
                         notes, created_by_user_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                );
                $stmt->execute([
                    $oppRef, $title, $stageId,
                    $source, $sourceEnqId, $sourceNote,
                    $clientId, $patientId,
                    $contactName, $contactEmail, $contactPhone,
                    $ownerId ?: $uid,       // default owner = creator
                    $careSummary,
                    $expectedVal, $expectedStart,
                    $notes, $uid,
                ]);
                $oppId = (int)$db->lastInsertId();

                // If converted from an enquiry, stamp the enquiry as converted
                if ($sourceEnqId) {
                    $db->prepare(
                        "UPDATE enquiries
                            SET status = 'converted',
                                handled_by = COALESCE(handled_by, ?),
                                handled_at = COALESCE(handled_at, NOW())
                          WHERE id = ? AND status IN ('new','contacted')"
                    )->execute([$_SESSION['email'] ?? null, $sourceEnqId]);
                }

                $db->commit();

                logActivity(
                    'opportunity_created', 'opportunities', 'opportunities', $oppId,
                    'Created ' . $oppRef . ' — ' . $title,
                    null,
                    ['opp_ref' => $oppRef, 'title' => $title, 'stage_id' => $stageId,
                     'source' => $source, 'source_enquiry_id' => $sourceEnqId]
                );

                header('Location: ' . APP_URL . '/admin/opportunities/' . $oppId . '?msg=' . urlencode('Opportunity created: ' . $oppRef));
                exit;
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $flash = 'Error: ' . $e->getMessage();
            $flashType = 'error';
        }
    }
}

// ── Load opp for edit ──────────────────────────────────────────────
$opp = null;
if ($isEdit) {
    $opp = fetchOpportunity($db, $oppId);
    if (!$opp) {
        http_response_code(404);
        echo '<p>Opportunity not found.</p>';
        return;
    }
}

// ── Picker data ────────────────────────────────────────────────────
$stages = fetchSalesStages($db, true);

$clients = $db->query(
    "SELECT cl.id, p.full_name, p.tch_id, cl.account_number
       FROM clients cl
       JOIN persons p ON p.id = cl.person_id
   ORDER BY p.full_name"
)->fetchAll(PDO::FETCH_ASSOC);

$patients = $db->query(
    "SELECT p.id AS person_id, p.full_name, p.tch_id
       FROM persons p
      WHERE FIND_IN_SET('patient', p.person_type)
   ORDER BY p.full_name"
)->fetchAll(PDO::FETCH_ASSOC);

$users = $db->query(
    "SELECT id, full_name, email FROM users WHERE is_active = 1 ORDER BY full_name, email"
)->fetchAll(PDO::FETCH_ASSOC);

// Initial values — three-way precedence: posted form > edit record > enquiry prefill > defaults
$val = function($key, $default = '') use ($opp, $enquiryPrefill) {
    if (isset($_POST[$key])) return $_POST[$key];
    if ($opp && isset($opp[$key])) return $opp[$key];
    if ($enquiryPrefill) {
        // Map enquiry fields to opp fields
        $map = [
            'contact_name'  => $enquiryPrefill['full_name'] ?? '',
            'contact_email' => $enquiryPrefill['email']     ?? '',
            'contact_phone' => $enquiryPrefill['phone']     ?? '',
            'care_summary'  => trim(($enquiryPrefill['care_type'] ? '[' . $enquiryPrefill['care_type'] . '] ' : '')
                                  . ($enquiryPrefill['message'] ?? '')),
            'title'         => $enquiryPrefill['full_name']
                ? 'Care enquiry — ' . $enquiryPrefill['full_name']
                : '',
        ];
        if (isset($map[$key]) && $map[$key] !== '') return $map[$key];
    }
    return $default;
};

require APP_ROOT . '/templates/layouts/admin.php';
?>

<?php if ($flash): ?>
    <div class="flash flash-<?= htmlspecialchars($flashType) ?>" style="padding:0.8rem 1rem;margin-bottom:1rem;border-radius:4px;background:<?= $flashType === 'error' ? '#f8d7da' : '#d1e7dd' ?>;color:<?= $flashType === 'error' ? '#842029' : '#0f5132' ?>;">
        <?= htmlspecialchars($flash) ?>
    </div>
<?php endif; ?>

<?php if ($enquiryPrefill): ?>
    <div style="padding:0.8rem 1rem;margin-bottom:1rem;border-radius:4px;background:#cff4fc;color:#055160;border:1px solid #b6effb;">
        Converting enquiry
        <strong>#<?= (int)$enquiryPrefill['id'] ?></strong>
        from <strong><?= htmlspecialchars($enquiryPrefill['full_name']) ?></strong>
        (<?= htmlspecialchars($enquiryPrefill['created_at']) ?>).
        The enquiry will be marked as <em>converted</em> once you save.
    </div>
<?php endif; ?>

<form method="post" style="max-width:820px;">
    <?= csrfField() ?>
    <?php if ($enquiryPrefill): ?>
        <input type="hidden" name="source" value="enquiry">
        <input type="hidden" name="source_enquiry_id" value="<?= (int)$enquiryPrefill['id'] ?>">
    <?php endif; ?>

    <fieldset style="border:1px solid #dee2e6;padding:1rem 1.2rem;margin-bottom:1rem;">
        <legend style="padding:0 0.4rem;font-size:0.85rem;color:#6c757d;">Opportunity</legend>

        <label style="display:block;margin-bottom:0.8rem;">
            <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Title <span style="color:#dc3545;">*</span></span>
            <input type="text" name="title" required maxlength="200"
                   value="<?= htmlspecialchars((string)$val('title')) ?>"
                   style="width:100%;padding:0.4rem 0.6rem;">
        </label>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.8rem;">
            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Stage <span style="color:#dc3545;">*</span></span>
                <select name="stage_id" required style="width:100%;padding:0.4rem 0.6rem;">
                    <?php foreach ($stages as $s):
                        $sel = ($opp ? (int)$opp['stage_id'] === (int)$s['id'] : $s['slug'] === 'new');
                        if (isset($_POST['stage_id'])) $sel = (int)$_POST['stage_id'] === (int)$s['id'];
                    ?>
                        <option value="<?= (int)$s['id'] ?>" <?= $sel ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['name']) ?> (<?= (int)$s['probability_percent'] ?>%)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Owner</span>
                <select name="owner_user_id" style="width:100%;padding:0.4rem 0.6rem;">
                    <option value="">— Unassigned —</option>
                    <?php foreach ($users as $u):
                        $sel = $opp ? (int)$opp['owner_user_id'] === (int)$u['id'] : (int)($_SESSION['user_id'] ?? 0) === (int)$u['id'];
                        if (isset($_POST['owner_user_id'])) $sel = (int)$_POST['owner_user_id'] === (int)$u['id'];
                    ?>
                        <option value="<?= (int)$u['id'] ?>" <?= $sel ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['full_name'] ?? $u['email']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </fieldset>

    <fieldset style="border:1px solid #dee2e6;padding:1rem 1.2rem;margin-bottom:1rem;">
        <legend style="padding:0 0.4rem;font-size:0.85rem;color:#6c757d;">Source</legend>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.8rem;">
            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Where did this come from?</span>
                <select name="source" style="width:100%;padding:0.4rem 0.6rem;" <?= $enquiryPrefill ? 'disabled' : '' ?>>
                    <?php foreach (oppSourceOptions() as $k => $lbl):
                        $sel = $opp ? $opp['source'] === $k : ($enquiryPrefill ? $k === 'enquiry' : $k === 'direct_call');
                        if (isset($_POST['source'])) $sel = $_POST['source'] === $k;
                    ?>
                        <option value="<?= htmlspecialchars($k) ?>" <?= $sel ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Source note (e.g. referrer name)</span>
                <input type="text" name="source_note" maxlength="255"
                       value="<?= htmlspecialchars((string)$val('source_note')) ?>"
                       style="width:100%;padding:0.4rem 0.6rem;">
            </label>
        </div>
    </fieldset>

    <fieldset style="border:1px solid #dee2e6;padding:1rem 1.2rem;margin-bottom:1rem;">
        <legend style="padding:0 0.4rem;font-size:0.85rem;color:#6c757d;">Who it's for</legend>
        <p style="color:#6c757d;font-size:0.82rem;margin:0 0 0.8rem 0;">
            Link the client and patient record once known. Leave blank during early qualification — the contact snapshot below is enough to work the opportunity.
        </p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.8rem;margin-bottom:0.8rem;">
            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Client (bill-payer)</span>
                <select name="client_id" style="width:100%;padding:0.4rem 0.6rem;">
                    <option value="">— Not yet linked —</option>
                    <?php foreach ($clients as $c):
                        $sel = $opp && (int)$opp['client_id'] === (int)$c['id'];
                        if (isset($_POST['client_id'])) $sel = (int)$_POST['client_id'] === (int)$c['id'];
                    ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $sel ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['full_name']) ?>
                            <?php if (!empty($c['account_number'])): ?>(<?= htmlspecialchars($c['account_number']) ?>)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Patient (care recipient)</span>
                <select name="patient_person_id" style="width:100%;padding:0.4rem 0.6rem;">
                    <option value="">— Not yet linked —</option>
                    <?php foreach ($patients as $p):
                        $sel = $opp && (int)$opp['patient_person_id'] === (int)$p['person_id'];
                        if (isset($_POST['patient_person_id'])) $sel = (int)$_POST['patient_person_id'] === (int)$p['person_id'];
                    ?>
                        <option value="<?= (int)$p['person_id'] ?>" <?= $sel ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['full_name']) ?> <?= !empty($p['tch_id']) ? '(' . htmlspecialchars($p['tch_id']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div style="display:grid;grid-template-columns:2fr 2fr 1fr;gap:0.8rem;">
            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Contact name</span>
                <input type="text" name="contact_name" maxlength="200"
                       value="<?= htmlspecialchars((string)$val('contact_name')) ?>"
                       style="width:100%;padding:0.4rem 0.6rem;">
            </label>
            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Email</span>
                <input type="email" name="contact_email" maxlength="150"
                       value="<?= htmlspecialchars((string)$val('contact_email')) ?>"
                       style="width:100%;padding:0.4rem 0.6rem;">
            </label>
            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Phone</span>
                <input type="text" name="contact_phone" maxlength="30"
                       value="<?= htmlspecialchars((string)$val('contact_phone')) ?>"
                       style="width:100%;padding:0.4rem 0.6rem;">
            </label>
        </div>
    </fieldset>

    <fieldset style="border:1px solid #dee2e6;padding:1rem 1.2rem;margin-bottom:1rem;">
        <legend style="padding:0 0.4rem;font-size:0.85rem;color:#6c757d;">Care requirement + estimate</legend>

        <label style="display:block;margin-bottom:0.8rem;">
            <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Care summary</span>
            <textarea name="care_summary" rows="3" style="width:100%;padding:0.4rem 0.6rem;"><?= htmlspecialchars((string)$val('care_summary')) ?></textarea>
        </label>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.8rem;">
            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Expected value (R / month)</span>
                <input type="number" name="expected_value_rand" step="1" min="0" max="1000000"
                       value="<?= $opp && $opp['expected_value_cents'] !== null ? htmlspecialchars((string)round((int)$opp['expected_value_cents'] / 100)) : htmlspecialchars((string)($_POST['expected_value_rand'] ?? '')) ?>"
                       style="width:100%;padding:0.4rem 0.6rem;">
            </label>
            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Expected start date</span>
                <input type="date" name="expected_start_date"
                       value="<?= htmlspecialchars((string)$val('expected_start_date')) ?>"
                       style="width:100%;padding:0.4rem 0.6rem;">
            </label>
        </div>
    </fieldset>

    <fieldset style="border:1px solid #dee2e6;padding:1rem 1.2rem;margin-bottom:1rem;">
        <legend style="padding:0 0.4rem;font-size:0.85rem;color:#6c757d;">Notes</legend>
        <textarea name="notes" rows="4" style="width:100%;padding:0.4rem 0.6rem;"
                  placeholder="Internal notes — conversation log, next steps, blockers"><?= htmlspecialchars((string)$val('notes')) ?></textarea>
    </fieldset>

    <div style="display:flex;gap:0.6rem;">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create opportunity' ?></button>
        <a href="<?= APP_URL ?>/admin/opportunities<?= $isEdit ? '/' . $oppId : '' ?>" class="btn" style="background:#f1f5f9;color:#334155;">Cancel</a>
    </div>
</form>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
