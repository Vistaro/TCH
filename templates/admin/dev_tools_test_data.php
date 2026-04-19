<?php
/**
 * Dev Tools — Test Data seeder + wiper — /admin/dev-tools/test-data
 *
 * Super-admin only. Seeds synthetic enquiries + opportunities (with
 * supporting person/client/patient records) flagged is_test_data=1.
 * Wipe removes everything flagged, in FK-safe order.
 *
 * Safety rails (belt + braces):
 *   1. super_admin-only permission gate (server-side)
 *   2. Refuses to run on PROD (APP_ENV check at the top)
 *   3. CSRF on every mutation
 *   4. Double-confirm on wipe (browser confirm + visible count)
 *   5. All WHERE clauses filter on is_test_data = 1 — real rows
 *      (flag=0) cannot be touched even if the code has a bug
 *   6. Audit-log every seed + wipe event to activity_log
 *
 * Pattern adapted from Nexus-CRM's modules/config_admin/test_data.php
 * with the "add explicit env gate" improvement flagged by that review.
 */
$pageTitle = 'Test Data (Dev Tools)';
$activeNav = 'dev-tools-test-data';

$db = getDB();
$user = currentUser();

// ── Safety rail 1: permission gate
if (!userCan('dev_tools_test_data', 'edit')) {
    http_response_code(403);
    echo '<p>Dev tools are super-admin only.</p>';
    return;
}

// ── Safety rail 2: refuse on PROD
if (defined('APP_ENV') && APP_ENV === 'production') {
    http_response_code(403);
    require APP_ROOT . '/templates/layouts/admin.php';
    ?>
    <div style="background:#f8d7da;border:1px solid #f5c2c7;color:#842029;padding:1rem 1.2rem;border-radius:4px;max-width:700px;">
        <h3 style="margin-top:0;">Disabled on production</h3>
        <p style="margin:0.4rem 0 0 0;">This page only operates on non-production environments. Seeding or wiping test data on PROD is refused by the server.</p>
        <p style="margin:0.4rem 0 0 0;font-size:0.85rem;color:#58151c;">APP_ENV = <code><?= htmlspecialchars(APP_ENV) ?></code></p>
    </div>
    <?php
    require APP_ROOT . '/templates/layouts/admin_footer.php';
    return;
}

// ── Test-data generators — deliberately realistic but clearly synthetic
$FIRST_NAMES = [
    'Amara', 'Bongani', 'Chipo', 'Dineo', 'Esi', 'Fikile', 'Gugu', 'Hlengiwe',
    'Isaac', 'Jabu', 'Khanyi', 'Lerato', 'Mpho', 'Nandi', 'Olwethu', 'Palesa',
    'Queen', 'Refilwe', 'Sipho', 'Thabo', 'Unathi', 'Vuyo', 'Winnie', 'Xolani',
    'Yanga', 'Zinhle', 'Adam', 'Beth', 'Carla', 'Dan', 'Eve', 'Fran',
];
$LAST_NAMES = [
    'Naidoo', 'van der Merwe', 'Botha', 'Mokoena', 'Dlamini', 'Pillay',
    'Ncube', 'Khumalo', 'Venter', 'Smith', 'Jones', 'Ngcobo', 'Moyo',
    'de Klerk', 'Swanepoel', 'Mbeki', 'Gumede', 'Cele', 'Nair', 'Patel',
];
$CARE_TYPES   = ['permanent','temporary','post_op','palliative','respite','errand','other'];
$URGENCIES    = ['immediate','this_week','within_month','planning_ahead'];
$ENQUIRY_STATUSES  = ['new','contacted','converted'];
$OPP_SOURCES  = ['enquiry','referral','direct_call','walk_in','other'];

$MESSAGE_TEMPLATES = [
    "My mother is being discharged next week and needs post-op care for 6-8 weeks. She had a hip replacement.",
    "Looking for permanent live-in care for my father who has dementia. He needs 24/7 supervision.",
    "Need respite care — 2 weeks in June while I travel for work. My husband has MS and can't be left alone.",
    "Weekday daytime care for Mum, she's recovering from a stroke and lives alone. Just meals + companionship really.",
    "Palliative care needed for my grandmother. She's 92 and just wants to be comfortable at home.",
    "Looking into options — no immediate need but my father-in-law is deteriorating. Want to understand what's possible.",
    "Errand care only — shopping, doctor's appointments, light housekeeping. About 3 afternoons a week.",
];

$rand_name = fn () => $FIRST_NAMES[array_rand($FIRST_NAMES)] . ' ' . $LAST_NAMES[array_rand($LAST_NAMES)];
$rand_phone = function (): string {
    // SA-style: +27 XX XXX XXXX — but prefix obviously fake to prevent accidental dial
    return '+27-TEST-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
};
$rand_email = function (string $name): string {
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '.', $name));
    $slug = trim($slug, '.');
    return $slug . '+test' . random_int(100, 999) . '@tch-test.invalid';
};

$flash = ''; $flashType = 'success';

// ── POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'seed') {
            $count = max(1, min(50, (int)($_POST['count'] ?? 10)));
            try {
                $db->beginTransaction();

                // Load stage IDs so we can spread opportunities across the pipeline
                $stages = $db->query(
                    "SELECT id, slug FROM sales_stages
                      WHERE is_active = 1 AND is_closed_won = 0 AND is_closed_lost = 0
                   ORDER BY sort_order"
                )->fetchAll(PDO::FETCH_KEY_PAIR);
                $stageIds = array_keys($stages);
                if (empty($stageIds)) throw new RuntimeException('No open sales_stages available — run mig 039 first.');

                $uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
                $created = ['enquiries' => 0, 'opportunities' => 0, 'persons' => 0];

                // Pre-seed max TCH-C for client accounts + max OPP ref for this year
                $maxClient = (int)$db->query(
                    "SELECT COALESCE(MAX(CAST(SUBSTRING(account_number, 6) AS UNSIGNED)), 0)
                       FROM clients WHERE account_number REGEXP '^TCH-C[0-9]+$'"
                )->fetchColumn();
                $year = (int)date('Y');
                $oppPrefix = sprintf('OPP-%04d-', $year);
                $maxOpp = (int)$db->query(
                    "SELECT COALESCE(MAX(CAST(SUBSTRING(opp_ref, " . (strlen($oppPrefix) + 1) . ") AS UNSIGNED)), 0)
                       FROM opportunities WHERE opp_ref LIKE " . $db->quote($oppPrefix . '%')
                )->fetchColumn();

                for ($i = 0; $i < $count; $i++) {
                    // 1. Create an enquiry
                    $name  = $rand_name();
                    $email = $rand_email($name);
                    $phone = $rand_phone();
                    $msg   = $MESSAGE_TEMPLATES[array_rand($MESSAGE_TEMPLATES)];
                    $care  = $CARE_TYPES[array_rand($CARE_TYPES)];
                    $urg   = $URGENCIES[array_rand($URGENCIES)];
                    $enqStatus = $ENQUIRY_STATUSES[array_rand($ENQUIRY_STATUSES)];

                    $stmt = $db->prepare(
                        "INSERT INTO enquiries
                            (enquiry_type, full_name, email, phone, care_type, urgency, message,
                             consent_terms, source_page, status, is_test_data)
                         VALUES ('client', ?, ?, ?, ?, ?, ?, 1, 'admin:dev-tools-seed', ?, 1)"
                    );
                    $stmt->execute([$name, $email, $phone, $care, $urg, $msg, $enqStatus]);
                    $enquiryId = (int)$db->lastInsertId();
                    $created['enquiries']++;

                    // 2. For ~60% of enquiries, create a person + client + patient + opportunity
                    //    The rest stay as raw enquiries at various statuses.
                    if ($enqStatus === 'new' || random_int(1, 100) > 60) {
                        continue;
                    }

                    [$firstName, $lastName] = array_pad(preg_split('/\s+/', $name, 2), 2, null);

                    // 2a. Person
                    $maxClient++;
                    $acctNum = sprintf('TCH-C%04d', $maxClient);
                    $pstmt = $db->prepare(
                        "INSERT INTO persons
                            (person_type, full_name, first_name, last_name, account_number, is_test_data)
                         VALUES ('client,patient', ?, ?, ?, ?, 1)"
                    );
                    $pstmt->execute([$name, $firstName, $lastName, $acctNum]);
                    $personId = (int)$db->lastInsertId();
                    $created['persons']++;

                    // 2b. Client row
                    $db->prepare(
                        "INSERT INTO clients (id, person_id, account_number, is_test_data)
                         VALUES (?, ?, ?, 1)"
                    )->execute([$personId, $personId, $acctNum]);
                    $clientId = $personId;

                    // 2c. Patient row (self-patient — the person is both client and patient)
                    $db->prepare(
                        "INSERT INTO patients (person_id, client_id, patient_name, is_test_data)
                         VALUES (?, ?, NULL, 1)"
                    )->execute([$personId, $clientId]);

                    // 3. Opportunity
                    $maxOpp++;
                    $oppRef = sprintf('OPP-%04d-%04d', $year, $maxOpp);
                    $stageId = $stageIds[array_rand($stageIds)];
                    $expectedVal = random_int(5000, 60000) * 100; // Rand in cents
                    $title = 'Care enquiry — ' . $name;
                    $source = $OPP_SOURCES[array_rand($OPP_SOURCES)];

                    $ostmt = $db->prepare(
                        "INSERT INTO opportunities
                            (opp_ref, title, stage_id,
                             source, source_enquiry_id,
                             client_id, patient_person_id,
                             contact_name, contact_email, contact_phone,
                             owner_user_id, care_summary,
                             expected_value_cents, expected_start_date,
                             status, is_test_data, created_by_user_id)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'open', 1, ?)"
                    );
                    $ostmt->execute([
                        $oppRef, $title, $stageId,
                        $source === 'enquiry' ? 'enquiry' : $source,
                        $source === 'enquiry' ? $enquiryId : null,
                        $clientId, $personId,
                        $name, $email, $phone,
                        $uid, '[' . $care . '] ' . $msg,
                        $expectedVal, date('Y-m-d', strtotime('+' . random_int(7, 90) . ' days')),
                        $uid,
                    ]);
                    $created['opportunities']++;
                }

                $db->commit();

                logActivity('dev_tools_seed', 'dev_tools_test_data', 'dev_tools', null,
                    'Seeded ' . $created['enquiries'] . ' enquiries, '
                    . $created['opportunities'] . ' opportunities, ' . $created['persons'] . ' test persons',
                    null, $created);

                $flash = 'Created ' . $created['enquiries'] . ' enquiries, '
                       . $created['opportunities'] . ' opportunities, '
                       . $created['persons'] . ' supporting person records. All flagged is_test_data=1.';
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $flash = 'Seed failed: ' . $e->getMessage();
                $flashType = 'error';
            }
        } elseif ($action === 'wipe') {
            try {
                $db->beginTransaction();

                // FK-safe order: strip referencing tables before parents.
                // Every WHERE filters on is_test_data = 1 — real data untouched.
                $removed = [
                    'opportunities' => $db->exec("DELETE FROM opportunities WHERE is_test_data = 1"),
                    'patients'      => $db->exec("DELETE FROM patients      WHERE is_test_data = 1"),
                    'clients'       => $db->exec("DELETE FROM clients       WHERE is_test_data = 1"),
                    'enquiries'     => $db->exec("DELETE FROM enquiries     WHERE is_test_data = 1"),
                    'persons'       => $db->exec("DELETE FROM persons       WHERE is_test_data = 1"),
                ];

                $db->commit();

                logActivity('dev_tools_wipe', 'dev_tools_test_data', 'dev_tools', null,
                    'Wiped all is_test_data=1 rows', null, $removed);

                $flash = 'Wiped: ' . array_sum($removed) . ' rows total ('
                       . implode(', ', array_map(fn($k,$v) => "$v $k", array_keys($removed), $removed)) . ').';
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $flash = 'Wipe failed: ' . $e->getMessage() . ' — you may have real data referencing a test record. Check FKs.';
                $flashType = 'error';
            }
        }
    }
}

// ── Load current test-data counts for display
$counts = [
    'enquiries'     => (int)$db->query("SELECT COUNT(*) FROM enquiries WHERE is_test_data = 1")->fetchColumn(),
    'opportunities' => (int)$db->query("SELECT COUNT(*) FROM opportunities WHERE is_test_data = 1")->fetchColumn(),
    'persons'       => (int)$db->query("SELECT COUNT(*) FROM persons WHERE is_test_data = 1")->fetchColumn(),
    'clients'       => (int)$db->query("SELECT COUNT(*) FROM clients WHERE is_test_data = 1")->fetchColumn(),
    'patients'      => (int)$db->query("SELECT COUNT(*) FROM patients WHERE is_test_data = 1")->fetchColumn(),
];
$totalTest = array_sum($counts);

require APP_ROOT . '/templates/layouts/admin.php';
?>

<?php if ($flash): ?>
    <div style="padding:0.8rem 1rem;margin-bottom:1rem;border-radius:4px;background:<?= $flashType === 'error' ? '#f8d7da' : '#d1e7dd' ?>;color:<?= $flashType === 'error' ? '#842029' : '#0f5132' ?>;">
        <?= htmlspecialchars($flash) ?>
    </div>
<?php endif; ?>

<div style="background:#fff3cd;border:1px solid #ffecb5;color:#664d03;padding:0.8rem 1rem;border-radius:4px;margin-bottom:1rem;font-size:0.9rem;">
    <strong>Super-admin only.</strong> This page seeds and wipes synthetic test data on the current environment
    (<code><?= defined('APP_ENV') ? htmlspecialchars(APP_ENV) : 'unknown' ?></code>). Real data
    (<code>is_test_data = 0</code>) is filtered out of every operation and cannot be affected. Refused on production.
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;max-width:900px;">

    <!-- Current counts -->
    <div style="background:#fff;border:1px solid #dee2e6;border-radius:6px;padding:1rem 1.2rem;">
        <h3 style="margin-top:0;font-size:1rem;">Current test data</h3>
        <dl style="margin:0;display:grid;grid-template-columns:auto 1fr;gap:0.3rem 1rem;font-size:0.9rem;">
            <?php foreach ($counts as $label => $n): ?>
                <dt style="color:#6c757d;"><?= htmlspecialchars(ucfirst($label)) ?>:</dt>
                <dd style="margin:0;font-family:monospace;"><?= $n ?> row<?= $n === 1 ? '' : 's' ?></dd>
            <?php endforeach; ?>
            <dt style="color:#6c757d;border-top:1px solid #e2e8f0;padding-top:0.3rem;margin-top:0.3rem;"><strong>Total:</strong></dt>
            <dd style="margin:0;font-family:monospace;border-top:1px solid #e2e8f0;padding-top:0.3rem;margin-top:0.3rem;"><strong><?= $totalTest ?></strong></dd>
        </dl>
    </div>

    <!-- Seed form -->
    <div style="background:#fff;border:1px solid #dee2e6;border-radius:6px;padding:1rem 1.2rem;">
        <h3 style="margin-top:0;font-size:1rem;">Seed more</h3>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="seed">
            <label style="display:block;margin-bottom:0.8rem;">
                <span style="display:block;font-size:0.85rem;color:#495057;">How many enquiries to create?</span>
                <input type="number" name="count" value="10" min="1" max="50" required
                       style="width:100px;padding:0.3rem 0.5rem;font-size:0.95rem;">
                <span style="color:#6c757d;font-size:0.78rem;margin-left:0.4rem;">(1-50)</span>
            </label>
            <p style="font-size:0.82rem;color:#6c757d;margin:0.3rem 0 0.8rem 0;">
                Each enquiry gets a realistic name + <code>+test###@tch-test.invalid</code> email + <code>+27-TEST-####</code> phone.
                ~40% are promoted to a full person → client → patient → opportunity chain across open pipeline stages.
            </p>
            <button type="submit" class="btn btn-primary">Seed</button>
        </form>
    </div>

    <!-- Wipe form (spans both columns) -->
    <div style="background:#fff;border:1px solid #f5c2c7;border-radius:6px;padding:1rem 1.2rem;grid-column:1 / -1;">
        <h3 style="margin-top:0;font-size:1rem;color:#842029;">Wipe all test data</h3>
        <p style="font-size:0.88rem;color:#495057;margin:0 0 0.8rem 0;">
            Permanently deletes every row with <code>is_test_data = 1</code> across enquiries, opportunities, persons, clients, patients.
            Real data untouched. Cannot be undone (but pre-migration snapshots on the server provide rollback).
        </p>
        <form method="post" onsubmit="return confirm('Permanently delete <?= $totalTest ?> test rows? This cannot be undone here — restore from a pre-migration snapshot if needed.');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="wipe">
            <button type="submit" class="btn" style="background:#dc3545;color:#fff;border:0;padding:0.5rem 1rem;border-radius:4px;" <?= $totalTest === 0 ? 'disabled title="Nothing to wipe"' : '' ?>>
                Wipe all <?= $totalTest ?> test rows
            </button>
        </form>
    </div>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
