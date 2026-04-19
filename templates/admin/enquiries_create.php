<?php
/**
 * Admin: Create new enquiry manually — /admin/enquiries/new
 *
 * For phone calls, walk-ins, referrals, and any other source that
 * doesn't go through the public website form. Captures the same
 * fields as the public form plus the enquiry_type selector (the
 * public form is client-only; admin can log caregiver / general
 * too).
 *
 * The saved enquiry lands in /admin/enquiries the same as public
 * submissions. Source page, IP, user agent metadata reflect the
 * admin session; handled_by is stamped so the list shows who logged it.
 */
$pageTitle = 'New Enquiry';
$activeNav = 'enquiries';

$db = getDB();
$user = currentUser();
$canCreate = userCan('enquiries', 'create') || userCan('enquiries', 'edit');

if (!$canCreate) {
    http_response_code(403);
    echo '<p>You do not have permission to create enquiries.</p>';
    return;
}

$flash = '';
$flashType = 'success';

$allowedTypes     = ['client', 'caregiver', 'general'];
$allowedCareTypes = ['permanent','temporary','post_op','palliative','respite','errand','other'];
$allowedUrgency   = ['immediate','this_week','within_month','planning_ahead'];

// Regions picker
$regions = $db->query(
    "SELECT id, name FROM regions WHERE is_active = 1 ORDER BY is_primary DESC, sort_order, name"
)->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } else {
        try {
            $type     = in_array($_POST['enquiry_type'] ?? '', $allowedTypes, true) ? $_POST['enquiry_type'] : 'client';
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $phone    = trim((string)($_POST['phone'] ?? '')) ?: null;
            $email    = trim((string)($_POST['email'] ?? '')) ?: null;
            $area     = trim((string)($_POST['suburb_or_area'] ?? '')) ?: null;
            $careType = $_POST['care_type'] ?? null;
            $urgency  = $_POST['urgency'] ?? null;
            $schedule = trim((string)($_POST['care_schedule'] ?? '')) ?: null;
            $message  = trim((string)($_POST['message'] ?? '')) ?: null;
            $regionId = $_POST['region_id'] !== '' ? (int)$_POST['region_id'] : null;
            $source   = trim((string)($_POST['source_page'] ?? '')) ?: null;
            $notes    = trim((string)($_POST['notes'] ?? '')) ?: null;
            $status   = in_array($_POST['status'] ?? 'new', ['new','contacted'], true) ? $_POST['status'] : 'new';

            if ($fullName === '') {
                throw new RuntimeException('Name is required.');
            }
            if ($phone === null && $email === null) {
                throw new RuntimeException('At least one contact method (phone or email) is required.');
            }
            if ($type !== 'client') {
                // Only client-type enquiries carry care fields; blank the rest to avoid mis-data.
                $careType = null;
                $urgency  = null;
                $schedule = null;
            } else {
                if ($careType !== null && $careType !== '' && !in_array($careType, $allowedCareTypes, true)) {
                    $careType = 'other';
                } elseif ($careType === '') {
                    $careType = null;
                }
                if ($urgency !== null && $urgency !== '' && !in_array($urgency, $allowedUrgency, true)) {
                    $urgency = null;
                } elseif ($urgency === '') {
                    $urgency = null;
                }
            }

            $stmt = $db->prepare(
                "INSERT INTO enquiries
                    (region_id, enquiry_type, full_name, email, phone, suburb_or_area,
                     care_type, care_schedule, urgency, message,
                     consent_terms, consent_marketing,
                     source_page, user_agent, ip_address, referrer_url,
                     status, handled_by, handled_at, notes)
                 VALUES
                    (?, ?, ?, ?, ?, ?,
                     ?, ?, ?, ?,
                     1, 0,
                     ?, ?, ?, NULL,
                     ?, ?, NOW(), ?)"
            );
            $stmt->execute([
                $regionId, $type, $fullName, $email, $phone, $area,
                $careType, $schedule, $urgency, $message,
                $source ?: 'admin:/admin/enquiries/new',
                mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $status,
                $user['email'] ?? $user['username'] ?? null,
                $notes,
            ]);
            $enqId = (int)$db->lastInsertId();

            logActivity(
                'enquiry_created_manual', 'enquiries', 'enquiries', $enqId,
                'Manual enquiry logged: ' . $fullName . ' (' . $type . ')',
                null,
                ['enquiry_type' => $type, 'full_name' => $fullName, 'care_type' => $careType, 'status' => $status]
            );

            header('Location: ' . APP_URL . '/admin/enquiries?id=' . $enqId);
            exit;
        } catch (Throwable $e) {
            $flash = 'Error: ' . $e->getMessage();
            $flashType = 'error';
        }
    }
}

$val = static fn (string $k, $default = ''): string => htmlspecialchars((string)($_POST[$k] ?? $default));
$sel = static fn (string $k, string $v): string => (($_POST[$k] ?? null) === $v) ? 'selected' : '';

require APP_ROOT . '/templates/layouts/admin.php';
?>

<?php if ($flash): ?>
    <div class="flash flash-<?= htmlspecialchars($flashType) ?>" style="padding:0.8rem 1rem;margin-bottom:1rem;border-radius:4px;background:<?= $flashType === 'error' ? '#f8d7da' : '#d1e7dd' ?>;color:<?= $flashType === 'error' ? '#842029' : '#0f5132' ?>;">
        <?= htmlspecialchars($flash) ?>
    </div>
<?php endif; ?>

<div style="margin-bottom:1rem;">
    <a href="<?= APP_URL ?>/admin/enquiries" class="btn btn-sm" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;">&larr; Back to inbox</a>
</div>

<p style="color:#64748b;font-size:0.9rem;max-width:820px;">
    Log an enquiry that didn't come through the public website form — phone
    calls, referrals, walk-ins. All the same fields; saved enquiries land
    in the inbox alongside public submissions.
</p>

<form method="post" style="max-width:820px;">
    <?= csrfField() ?>

    <fieldset style="border:1px solid #dee2e6;padding:1rem 1.2rem;margin-bottom:1rem;">
        <legend style="padding:0 0.4rem;font-size:0.85rem;color:#6c757d;">Contact</legend>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.8rem;">
            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Enquiry type <span style="color:#dc3545;">*</span></span>
                <select name="enquiry_type" required style="width:100%;padding:0.4rem 0.6rem;">
                    <option value="client"    <?= $sel('enquiry_type', 'client')    ?: 'selected' ?>>Client — needs a caregiver</option>
                    <option value="caregiver" <?= $sel('enquiry_type', 'caregiver') ?>>Caregiver — wants placement</option>
                    <option value="general"   <?= $sel('enquiry_type', 'general')   ?>>General — other enquiry</option>
                </select>
            </label>

            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Status</span>
                <select name="status" style="width:100%;padding:0.4rem 0.6rem;">
                    <option value="new"       <?= $sel('status', 'new')       ?: 'selected' ?>>New</option>
                    <option value="contacted" <?= $sel('status', 'contacted') ?>>Contacted</option>
                </select>
            </label>
        </div>

        <label style="display:block;margin-top:0.8rem;">
            <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Full name <span style="color:#dc3545;">*</span></span>
            <input type="text" name="full_name" required maxlength="200"
                   value="<?= $val('full_name') ?>"
                   style="width:100%;padding:0.4rem 0.6rem;">
        </label>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.8rem;margin-top:0.8rem;">
            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Phone</span>
                <input type="text" name="phone" maxlength="30"
                       value="<?= $val('phone') ?>"
                       style="width:100%;padding:0.4rem 0.6rem;">
            </label>

            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Email</span>
                <input type="email" name="email" maxlength="150"
                       value="<?= $val('email') ?>"
                       style="width:100%;padding:0.4rem 0.6rem;">
            </label>
        </div>
        <p style="color:#6c757d;font-size:0.78rem;margin:0.3rem 0 0 0;">At least one of phone or email is required.</p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.8rem;margin-top:0.8rem;">
            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Suburb / area</span>
                <input type="text" name="suburb_or_area" maxlength="150"
                       value="<?= $val('suburb_or_area') ?>"
                       style="width:100%;padding:0.4rem 0.6rem;">
            </label>

            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Region</span>
                <select name="region_id" style="width:100%;padding:0.4rem 0.6rem;">
                    <option value="">— Unknown —</option>
                    <?php foreach ($regions as $r): ?>
                        <option value="<?= (int)$r['id'] ?>" <?= $sel('region_id', (string)$r['id']) ?>><?= htmlspecialchars($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </fieldset>

    <fieldset style="border:1px solid #dee2e6;padding:1rem 1.2rem;margin-bottom:1rem;">
        <legend style="padding:0 0.4rem;font-size:0.85rem;color:#6c757d;">Care requirement (client type only)</legend>
        <p style="color:#6c757d;font-size:0.78rem;margin:0 0 0.6rem 0;">Only applies when the enquiry type is "Client". Ignored for caregiver / general enquiries.</p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.8rem;">
            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Care type</span>
                <select name="care_type" style="width:100%;padding:0.4rem 0.6rem;">
                    <option value="">— Not specified —</option>
                    <option value="permanent"  <?= $sel('care_type', 'permanent')  ?>>Permanent</option>
                    <option value="temporary"  <?= $sel('care_type', 'temporary')  ?>>Temporary</option>
                    <option value="post_op"    <?= $sel('care_type', 'post_op')    ?>>Post-operative</option>
                    <option value="palliative" <?= $sel('care_type', 'palliative') ?>>Palliative</option>
                    <option value="respite"    <?= $sel('care_type', 'respite')    ?>>Respite</option>
                    <option value="errand"     <?= $sel('care_type', 'errand')     ?>>Errand care</option>
                    <option value="other"      <?= $sel('care_type', 'other')      ?>>Other</option>
                </select>
            </label>

            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Urgency</span>
                <select name="urgency" style="width:100%;padding:0.4rem 0.6rem;">
                    <option value="">— Not specified —</option>
                    <option value="immediate"       <?= $sel('urgency', 'immediate')       ?>>Immediate</option>
                    <option value="this_week"       <?= $sel('urgency', 'this_week')       ?>>Within a week</option>
                    <option value="within_month"    <?= $sel('urgency', 'within_month')    ?>>Within a month</option>
                    <option value="planning_ahead"  <?= $sel('urgency', 'planning_ahead')  ?>>Planning ahead</option>
                </select>
            </label>
        </div>

        <label style="display:block;margin-top:0.8rem;">
            <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Schedule (free text)</span>
            <input type="text" name="care_schedule" maxlength="100"
                   placeholder="e.g. weekdays 8-5, live-in, weekends only"
                   value="<?= $val('care_schedule') ?>"
                   style="width:100%;padding:0.4rem 0.6rem;">
        </label>
    </fieldset>

    <fieldset style="border:1px solid #dee2e6;padding:1rem 1.2rem;margin-bottom:1rem;">
        <legend style="padding:0 0.4rem;font-size:0.85rem;color:#6c757d;">Message + source</legend>

        <label style="display:block;margin-bottom:0.8rem;">
            <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">What did they say?</span>
            <textarea name="message" rows="4" maxlength="2000"
                      placeholder="Quote them or summarise what they asked for. This ends up on the enquiry detail page."
                      style="width:100%;padding:0.4rem 0.6rem;"><?= $val('message') ?></textarea>
        </label>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.8rem;">
            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">How did they reach us?</span>
                <input type="text" name="source_page" maxlength="120"
                       placeholder="e.g. phone, walk-in, referral from X"
                       value="<?= $val('source_page') ?>"
                       style="width:100%;padding:0.4rem 0.6rem;">
            </label>

            <label>
                <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Internal notes</span>
                <input type="text" name="notes" maxlength="500"
                       placeholder="e.g. 'Left voicemail 10:30, callback agreed at 2pm'"
                       value="<?= $val('notes') ?>"
                       style="width:100%;padding:0.4rem 0.6rem;">
            </label>
        </div>
    </fieldset>

    <div style="display:flex;gap:0.6rem;">
        <button type="submit" class="btn btn-primary">Log enquiry</button>
        <a href="<?= APP_URL ?>/admin/enquiries" class="btn" style="background:#f1f5f9;color:#334155;">Cancel</a>
    </div>
</form>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
