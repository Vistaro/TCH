<?php
/**
 * Student detail page — /admin/students/{id}
 *
 * Single-student landing page. Shows core profile, training summary,
 * placement, attachments, and the Notes timeline.
 *
 * Permission: student_view.read for view, student_view.edit for the
 * inline edit forms and Notes form to actually save.
 */

require_once APP_ROOT . '/includes/activities_render.php';
require_once APP_ROOT . '/includes/countries.php';

$pageTitle = 'Student';
$activeNav = 'student-tracking';

$personId = (int)($_GET['student_id'] ?? 0);
$db       = getDB();
$canEdit  = userCan('student_view', 'edit');

// ── Editable section whitelist ───────────────────────────────────────────
// Each section maps to a target table + column list. Sections writing to
// `persons` and `students` use the same save pipeline.
$editableSections = [
    'personal' => ['table' => 'persons',  'cols' => ['known_as','title','initials','id_passport','dob','gender','nationality','home_language','other_language']],
    'contact'  => ['table' => 'persons',  'cols' => ['mobile','secondary_number','email']],
    'address'  => ['table' => 'persons',  'cols' => ['complex_estate','street_address','suburb','city','province','postal_code','country']],
    'nok'      => ['table' => 'persons',  'cols' => ['nok_name','nok_relationship','nok_contact','nok_email']],
    'nok2'     => ['table' => 'persons',  'cols' => ['nok_2_name','nok_2_relationship','nok_2_contact','nok_2_email']],
    'training' => ['table' => 'students', 'cols' => ['course_start','avg_score','practical_status','qualified']],
];

$flash = '';
$flashType = 'success';

// ── Handle Notes form POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'save_activity' && $canEdit) {
    $res = saveActivityFromPost('persons', $personId);
    $flash = $res['msg'];
    $flashType = $res['ok'] ? 'success' : 'error';
    if ($res['ok']) {
        header('Location: ' . APP_URL . '/admin/students/' . $personId);
        exit;
    }
}

// ── Handle Photo Upload POST ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'upload_photo' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } elseif (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        $flash = 'No file uploaded or upload failed.'; $flashType = 'error';
    } else {
        $tmp  = $_FILES['photo']['tmp_name'];
        $size = (int)$_FILES['photo']['size'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) {
            $flash = 'Photo must be JPG, PNG or WebP.'; $flashType = 'error';
        } elseif ($size > 5 * 1024 * 1024) {
            $flash = 'Photo must be 5 MB or smaller.'; $flashType = 'error';
        } else {
            // Look up the person's tch_id for the upload path
            $tchId = $person['tch_id'] ?? ('id-' . $personId);
            $ext = $allowed[$mime];
            $filename = 'profile_' . date('Ymd-His') . '.' . $ext;
            $relPath  = "people/{$tchId}/{$filename}";
            $absDir   = APP_ROOT . '/public/uploads/people/' . $tchId;
            if (!is_dir($absDir)) {
                @mkdir($absDir, 0755, true);
            }
            if (!move_uploaded_file($tmp, $absDir . '/' . $filename)) {
                $flash = 'Failed to save the uploaded file.'; $flashType = 'error';
            } else {
                // Mark older profile_photo rows as inactive
                $db->prepare(
                    "UPDATE attachments SET is_active = 0
                     WHERE person_id = ?
                     AND attachment_type_id = (SELECT id FROM attachment_types WHERE code='profile_photo')
                     AND is_active = 1"
                )->execute([$personId]);

                // Insert new
                $db->prepare(
                    "INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, mime_type, file_size_bytes, is_active, uploaded_at)
                     VALUES (?, (SELECT id FROM attachment_types WHERE code='profile_photo'), ?, ?, ?, ?, 1, NOW())"
                )->execute([$personId, $relPath, $_FILES['photo']['name'], $mime, $size]);

                logActivity('photo_uploaded', 'student_view', 'persons', $personId,
                    'New profile photo uploaded for ' . $person['full_name'],
                    null, ['file_path' => $relPath]);
                logSystemActivity('persons', $personId, 'Profile photo updated',
                    'New photo uploaded by ' . (currentEffectiveUser()['full_name'] ?? '?')
                    . ' (' . $size . ' bytes, ' . $mime . ')',
                    'student_view#photo', 'photo-' . date('Ymd'));

                $flash = 'New photo saved.'; $flashType = 'success';
            }
        }
    }
    header('Location: ' . APP_URL . '/admin/students/' . $personId
           . '?msg=' . urlencode($flash) . '&type=' . urlencode($flashType));
    exit;
}

// ── Handle Mark-Graduated POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'mark_graduated' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } else {
        $stmt = $db->prepare(
            'SELECT id, status, graduated_at FROM student_enrollments
             WHERE student_person_id = ? ORDER BY enrolled_at DESC LIMIT 1'
        );
        $stmt->execute([$personId]);
        $enr = $stmt->fetch();
        if (!$enr) {
            $flash = 'No enrollment record for this student.'; $flashType = 'error';
        } else {
            $before = ['status' => $enr['status'], 'graduated_at' => $enr['graduated_at']];
            $db->prepare(
                "UPDATE student_enrollments
                 SET status = 'graduated',
                     graduated_at = COALESCE(graduated_at, CURDATE())
                 WHERE id = ?"
            )->execute([(int)$enr['id']]);
            $me = currentEffectiveUser();
            logActivity('student_graduated', 'student_view', 'student_enrollments', (int)$enr['id'],
                'Marked ' . $person['full_name'] . ' as graduated',
                $before, ['status' => 'graduated', 'graduated_at' => date('Y-m-d')]);
            logSystemActivity('persons', $personId,
                'Marked as graduated',
                'Graduated on ' . date('d M Y') . ' by ' . ($me['full_name'] ?? '?'),
                'student_view#graduated',
                'graduation-' . date('Ymd'));
            $flash = 'Student marked as graduated.'; $flashType = 'success';
        }
    }
    header('Location: ' . APP_URL . '/admin/students/' . $personId
           . '?msg=' . urlencode($flash) . '&type=' . urlencode($flashType));
    exit;
}

// ── Handle Approve POST ─────────────────────────────────────────────────
// Reject removed by design — wrong data is fixed via Edit, then approved.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'approve_import' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.';
        $flashType = 'error';
    } else {
        $stmt = $db->prepare('SELECT import_review_state FROM students WHERE person_id = ?');
        $stmt->execute([$personId]);
        $oldState = $stmt->fetchColumn();

        $stmt = $db->prepare('UPDATE students SET import_review_state = NULL WHERE person_id = ?');
        $stmt->execute([$personId]);

        $me = currentEffectiveUser();
        $actorLabel = $me['full_name'] ?? $me['username'] ?? 'unknown';

        logActivity(
            'person_approved', 'student_view', 'persons', $personId,
            'Student record approved',
            ['import_review_state' => $oldState],
            ['import_review_state' => null]
        );

        logSystemActivity(
            'persons', $personId,
            'Record approved',
            'Tuniti import record was approved by ' . $actorLabel . ' on ' . date('d M Y H:i'),
            'student_view#approval',
            'approval-' . date('Ymd')
        );

        $flash = 'Record approved.';
        $flashType = 'success';
    }
    header('Location: ' . APP_URL . '/admin/students/' . $personId
           . '?msg=' . urlencode($flash) . '&type=' . urlencode($flashType));
    exit;
}

// ── Handle section-edit POST ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'save_section' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.';
        $flashType = 'error';
    } else {
        $section = $_POST['section'] ?? '';
        if (!isset($editableSections[$section])) {
            $flash = 'Unknown section.';
            $flashType = 'error';
        } else {
            $secDef    = $editableSections[$section];
            $table     = $secDef['table'];
            $colList   = $secDef['cols'];
            $pkCol     = ($table === 'students') ? 'person_id' : 'id';

            $stmt = $db->prepare("SELECT * FROM `$table` WHERE `$pkCol` = ?");
            $stmt->execute([$personId]);
            $beforeRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $newValues = [];
            foreach ($colList as $col) {
                $val = $_POST[$col] ?? null;
                if (is_string($val)) $val = trim($val);
                if ($val === '') $val = null;
                // avg_score input is shown as percent (0..100); store as 0..1 decimal
                if ($section === 'training' && $col === 'avg_score' && $val !== null) {
                    $val = round((float)$val / 100, 4);
                }
                $newValues[$col] = $val;
            }

            // Phone columns: rebuild E.164 from dial + national pair
            $phoneCols = [
                'mobile'           => ['mobile_dial', 'mobile_national'],
                'secondary_number' => ['secondary_number_dial', 'secondary_number_national'],
                'nok_contact'      => ['nok_contact_dial', 'nok_contact_national'],
                'nok_2_contact'    => ['nok_2_contact_dial', 'nok_2_contact_national'],
            ];
            foreach ($phoneCols as $col => [$dialKey, $natKey]) {
                if (in_array($col, $colList, true) && isset($_POST[$dialKey], $_POST[$natKey])) {
                    $newValues[$col] = joinE164(
                        (string)$_POST[$dialKey],
                        (string)$_POST[$natKey]
                    );
                }
            }

            // Diff before/after
            $changed = [];
            foreach ($newValues as $col => $newVal) {
                $oldVal = $beforeRow[$col] ?? null;
                if ((string)$oldVal !== (string)$newVal) {
                    $changed[$col] = ['from' => $oldVal, 'to' => $newVal];
                }
            }

            if (!$changed) {
                $flash = 'No changes to save.';
                $flashType = 'info';
            } else {
                $set = [];
                $params = [];
                foreach ($newValues as $col => $val) {
                    $set[] = "`{$col}` = ?";
                    $params[] = $val;
                }
                $params[] = $personId;
                $sql = "UPDATE `$table` SET " . implode(',', $set) . " WHERE `$pkCol` = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);

                // Audit log row (one entry per save, with full changed-fields snapshot)
                logActivity(
                    'edit',
                    'student_view',
                    'persons',
                    $personId,
                    'Updated ' . $section . ' section (' . count($changed) . ' field' . (count($changed) === 1 ? '' : 's') . ')',
                    array_combine(array_keys($changed), array_column($changed, 'from')),
                    array_combine(array_keys($changed), array_column($changed, 'to'))
                );

                // Notes timeline entries — one per changed field for visibility
                foreach ($changed as $col => $diff) {
                    $label = ucwords(str_replace('_', ' ', $col));
                    $body  = "Was: " . ($diff['from'] ?? '(empty)') . "\nNow: " . ($diff['to'] ?? '(empty)');
                    logSystemActivity(
                        'persons',
                        $personId,
                        $label . ' updated',
                        $body,
                        'student_view#' . $section,
                        'edit-' . date('Ymd-His')
                    );
                }

                $flash = count($changed) . ' field' . (count($changed) === 1 ? '' : 's') . ' updated.';
                $flashType = 'success';
            }
        }
    }
    header('Location: ' . APP_URL . '/admin/students/' . $personId
           . ($flash !== '' ? '?msg=' . urlencode($flash) . '&type=' . urlencode($flashType) : ''));
    exit;
}

// Pick up flash forwarded in URL (after redirect)
if ($flash === '' && isset($_GET['msg'])) {
    $flash = (string)$_GET['msg'];
    $flashType = (string)($_GET['type'] ?? 'success');
}

// ── Load person + student + enrollment ──────────────────────────────────
$stmt = $db->prepare(
    "SELECT p.*, s.cohort, s.course_start, s.avg_score, s.practical_status,
            s.qualified, s.import_notes, s.import_review_state,
            ps.label AS status_label,
            ls.label AS lead_source_label
     FROM persons p
     LEFT JOIN students s ON s.person_id = p.id
     LEFT JOIN person_statuses ps ON ps.id = s.status_id
     LEFT JOIN lead_sources ls ON ls.id = s.lead_source_id
     WHERE p.id = ? AND FIND_IN_SET('caregiver', p.person_type)"
);
$stmt->execute([$personId]);
$person = $stmt->fetch();

if (!$person) {
    require APP_ROOT . '/templates/layouts/admin.php';
    echo '<p>No student with id ' . (int)$personId . '.</p>';
    echo '<p><a href="' . APP_URL . '/admin/students">Back to students</a></p>';
    require APP_ROOT . '/templates/layouts/admin_footer.php';
    return;
}

$stmt = $db->prepare(
    'SELECT id, cohort, enrolled_at, graduated_at, dropped_at, status
     FROM student_enrollments
     WHERE student_person_id = ?
     ORDER BY enrolled_at DESC LIMIT 1'
);
$stmt->execute([$personId]);
$enrol = $stmt->fetch() ?: [];

$stmt = $db->prepare(
    "SELECT a.*, at.code AS type_code, at.label AS type_label
     FROM attachments a
     JOIN attachment_types at ON at.id = a.attachment_type_id
     WHERE a.person_id = ? AND a.is_active = 1
     ORDER BY at.sort_order, a.uploaded_at"
);
$stmt->execute([$personId]);
$attachments = $stmt->fetchAll();

// ── Course attendance grid (per-week P/A) ────────────────────────────
$stmt = $db->prepare(
    "SELECT attendance_date, attendance_type, notes
     FROM training_attendance
     WHERE student_person_id = ?
     ORDER BY attendance_date, id"
);
$stmt->execute([$personId]);
$attendanceRows = $stmt->fetchAll();

// ── Tuniti source-data rows (cell ↔ value pairs from the import) ─────
$stmt = $db->prepare(
    "SELECT subject, notes, source_ref, activity_date
     FROM activities
     WHERE entity_type = 'persons' AND entity_id = ?
       AND source = 'import'
       AND source_ref LIKE 'Ross Intake 1-9%'
     ORDER BY source_ref"
);
$stmt->execute([$personId]);
$sourceRows = $stmt->fetchAll();

$photoPath = null;
foreach ($attachments as $a) {
    if ($a['type_code'] === 'profile_photo') {
        $photoPath = $a['file_path'];
        break;
    }
}

// Which section (if any) is in edit mode?
$editSection = $_GET['edit'] ?? '';
if (!isset($editableSections[$editSection]) || !$canEdit) {
    $editSection = '';
}

// Helpers — short, inline
function _esc($v) { return htmlspecialchars((string)($v ?? '')); }
function _editLink(int $personId, string $section, bool $canEdit): string {
    if (!$canEdit) return '';
    return '<a href="' . APP_URL . '/admin/students/' . $personId
         . '?edit=' . $section . '" class="btn btn-link btn-sm" style="float:right;padding:0;">Edit</a>';
}
function _formActions(int $personId): string {
    return '<div style="margin-top:0.75rem;text-align:right;">'
         . '<a href="' . APP_URL . '/admin/students/' . $personId . '" class="btn btn-link">Cancel</a>'
         . ' <button type="submit" class="btn btn-primary btn-sm">Save</button></div>';
}

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center;">
    <div>
        <a href="<?= APP_URL ?>/admin/students" class="btn btn-outline btn-sm">&larr; Back to students</a>
        <a href="<?= APP_URL ?>/admin/students/<?= $personId ?>/print" target="_blank" class="btn btn-outline btn-sm">
            <i class="fas fa-print"></i> Print / PDF
        </a>
    </div>
    <?php if ($canEdit && ($person['import_review_state'] ?? null) === 'pending'): ?>
        <form method="POST" style="display:inline;">
            <?= csrfField() ?>
            <span style="background:#fff3cd;color:#856404;padding:0.4rem 0.75rem;border-radius:4px;margin-right:0.5rem;">
                <i class="fas fa-clock"></i> Pending Tuniti approval
            </span>
            <button type="submit" name="action" value="approve_import" class="btn btn-primary btn-sm">
                Approve
            </button>
            <span style="margin-left:0.5rem;color:#6c757d;font-size:0.85rem;">
                (if details are wrong, edit them above first, then approve)
            </span>
        </form>
    <?php endif; ?>
</div>

<?php if ($flash): ?>
    <div class="flash flash-<?= _esc($flashType) ?>" style="margin-bottom:1rem;padding:0.5rem 1rem;border-radius:4px;background:<?= $flashType === 'error' ? '#f8d7da' : ($flashType === 'info' ? '#cce5ff' : '#d4edda') ?>;">
        <?= _esc($flash) ?>
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
                        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp"
                               style="display:none;" onchange="this.form.submit();">
                    </label>
                </form>
            <?php endif; ?>
        </div>
        <div class="person-card-title">
            <h2><?= _esc($person['full_name']) ?></h2>
            <div class="person-card-tch-id"><?= _esc($person['tch_id'] ?? '—') ?></div>
            <div class="person-card-meta">
                <?php if ($person['known_as']): ?>
                    Known as: <strong><?= _esc($person['known_as']) ?></strong> &middot;
                <?php endif; ?>
                <?php if ($enrol['cohort'] ?? null): ?>
                    Cohort: <strong><?= _esc($enrol['cohort']) ?></strong> &middot;
                <?php endif; ?>
                Status: <strong><?= _esc($enrol['status'] ?? ($person['status_label'] ?? '—')) ?></strong>
            </div>
        </div>
    </div>

    <div class="person-card-grid">

        <!-- Training -->
        <div class="person-card-section">
            <h3>Training <?= _editLink($personId, 'training', $canEdit && $editSection !== 'training') ?></h3>
            <?php if ($editSection === 'training'): ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_section">
                    <input type="hidden" name="section" value="training">
                    <dl class="edit-dl">
                        <dt>Course Start</dt>    <dd><input class="form-control" type="date" name="course_start" value="<?= _esc($person['course_start']) ?>"></dd>
                        <dt>Average Score (%)</dt><dd><input class="form-control" type="number" step="0.1" min="0" max="100" name="avg_score" value="<?= $person['avg_score'] !== null ? round((float)$person['avg_score'] * 100, 1) : '' ?>"></dd>
                        <dt>Practical / OJT</dt> <dd><input class="form-control" name="practical_status" value="<?= _esc($person['practical_status']) ?>"></dd>
                        <dt>Qualified</dt>       <dd><input class="form-control" name="qualified" value="<?= _esc($person['qualified']) ?>"></dd>
                    </dl>
                    <?= _formActions($personId) ?>
                </form>
            <?php else: ?>
                <dl>
                    <dt>Cohort</dt>          <dd><?= _esc($enrol['cohort']        ?? '—') ?></dd>
                    <dt>Enrolled</dt>        <dd><?= _esc($enrol['enrolled_at']   ?? '—') ?></dd>
                    <dt>Course Start</dt>    <dd><?= _esc($person['course_start'] ?? '—') ?></dd>
                    <dt>Average Score</dt>   <dd><?= $person['avg_score'] !== null
                                                    ? number_format((float)$person['avg_score'] * 100, 1) . '%'
                                                    : '—' ?></dd>
                    <dt>Practical / OJT</dt> <dd><?= _esc($person['practical_status'] ?? '—') ?: '—' ?></dd>
                    <dt>Qualified</dt>       <dd><?= _esc($person['qualified']        ?? '—') ?: '—' ?></dd>
                    <dt>Graduated</dt>       <dd>
                        <?= _esc($enrol['graduated_at'] ?? '') ?: '—' ?>
                        <?php if ($canEdit && empty($enrol['graduated_at'])): ?>
                            <form method="POST" style="display:inline;margin-left:0.5rem;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="mark_graduated">
                                <button type="submit" class="btn btn-outline btn-sm"
                                        onclick="return confirm('Mark this student as graduated today?');">
                                    Mark as Graduated
                                </button>
                            </form>
                        <?php endif; ?>
                    </dd>
                </dl>
            <?php endif; ?>
        </div>

        <!-- Personal -->
        <div class="person-card-section">
            <h3>Personal <?= _editLink($personId, 'personal', $canEdit && $editSection !== 'personal') ?></h3>
            <?php if ($editSection === 'personal'): ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_section">
                    <input type="hidden" name="section" value="personal">
                    <dl class="edit-dl">
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
                        <dt>Home Language</dt> <dd><input class="form-control" name="home_language" value="<?= _esc($person['home_language']) ?>"></dd>
                        <dt>Other Languages</dt><dd><input class="form-control" name="other_language" value="<?= _esc($person['other_language']) ?>"></dd>
                    </dl>
                    <?= _formActions($personId) ?>
                </form>
            <?php else: ?>
                <dl>
                    <dt>Known As</dt>      <dd><?= _esc($person['known_as']) ?: '—' ?></dd>
                    <dt>Title</dt>         <dd><?= _esc($person['title']) ?: '—' ?></dd>
                    <dt>Initials</dt>      <dd><?= _esc($person['initials']) ?: '—' ?></dd>
                    <dt>ID / Passport</dt> <dd><?= _esc($person['id_passport']) ?: '—' ?></dd>
                    <dt>Date of Birth</dt> <dd><?= _esc($person['dob']) ?: '—' ?></dd>
                    <dt>Gender</dt>        <dd><?= _esc($person['gender']) ?: '—' ?></dd>
                    <dt>Nationality</dt>   <dd><?= _esc($person['nationality']) ?: '—' ?></dd>
                    <dt>Home Lang</dt>     <dd><?= _esc($person['home_language']) ?: '—' ?></dd>
                    <dt>Other Lang</dt>    <dd><?= _esc($person['other_language']) ?: '—' ?></dd>
                </dl>
            <?php endif; ?>
        </div>

        <!-- Contact -->
        <div class="person-card-section">
            <h3>Contact <?= _editLink($personId, 'contact', $canEdit && $editSection !== 'contact') ?></h3>
            <?php if ($editSection === 'contact'):
                [$mobDial, $mobNat] = splitE164($person['mobile']);
                [$secDial, $secNat] = splitE164($person['secondary_number']);
            ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_section">
                    <input type="hidden" name="section" value="contact">
                    <dl class="edit-dl">
                        <dt>Mobile</dt>
                        <dd style="display:flex;gap:0.4rem;">
                            <?php renderDialPrefixSelect('mobile_dial', $mobDial); ?>
                            <input class="form-control" name="mobile_national" value="<?= _esc($mobNat) ?>" placeholder="national number">
                        </dd>
                        <dt>Secondary</dt>
                        <dd style="display:flex;gap:0.4rem;">
                            <?php renderDialPrefixSelect('secondary_number_dial', $secDial); ?>
                            <input class="form-control" name="secondary_number_national" value="<?= _esc($secNat) ?>" placeholder="national number">
                        </dd>
                        <dt>Email</dt>
                        <dd><input class="form-control" type="email" name="email" value="<?= _esc($person['email']) ?>"></dd>
                    </dl>
                    <?= _formActions($personId) ?>
                </form>
            <?php else: ?>
                <dl>
                    <dt>Mobile</dt>    <dd><?= _esc(formatPhoneForDisplay($person['mobile'])) ?: '—' ?></dd>
                    <dt>Secondary</dt> <dd><?= _esc(formatPhoneForDisplay($person['secondary_number'])) ?: '—' ?></dd>
                    <dt>Email</dt>     <dd><?= _esc($person['email']) ?: '—' ?></dd>
                </dl>
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

        <!-- Emergency contact -->
        <div class="person-card-section">
            <h3>Emergency Contact <?= _editLink($personId, 'nok', $canEdit && $editSection !== 'nok') ?></h3>
            <?php if ($editSection === 'nok'):
                [$nokDial, $nokNat] = splitE164($person['nok_contact']);
            ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_section">
                    <input type="hidden" name="section" value="nok">
                    <dl class="edit-dl">
                        <dt>Name</dt>         <dd><input class="form-control" name="nok_name"         value="<?= _esc($person['nok_name']) ?>"></dd>
                        <dt>Relationship</dt> <dd><input class="form-control" name="nok_relationship" value="<?= _esc($person['nok_relationship']) ?>"></dd>
                        <dt>Contact</dt>
                        <dd style="display:flex;gap:0.4rem;">
                            <?php renderDialPrefixSelect('nok_contact_dial', $nokDial); ?>
                            <input class="form-control" name="nok_contact_national" value="<?= _esc($nokNat) ?>">
                        </dd>
                        <dt>Email</dt>        <dd><input class="form-control" type="email" name="nok_email" value="<?= _esc($person['nok_email']) ?>"></dd>
                    </dl>
                    <?= _formActions($personId) ?>
                </form>
            <?php else: ?>
                <dl>
                    <dt>Name</dt>         <dd><?= _esc($person['nok_name']) ?: '—' ?></dd>
                    <dt>Relationship</dt> <dd><?= _esc($person['nok_relationship']) ?: '—' ?></dd>
                    <dt>Contact</dt>      <dd><?= _esc(formatPhoneForDisplay($person['nok_contact'])) ?: '—' ?></dd>
                    <dt>Email</dt>        <dd><?= _esc($person['nok_email']) ?: '—' ?></dd>
                </dl>
            <?php endif; ?>
        </div>

        <!-- Emergency contact 2 -->
        <div class="person-card-section">
            <h3>Emergency Contact (2nd) <?= _editLink($personId, 'nok2', $canEdit && $editSection !== 'nok2') ?></h3>
            <?php if ($editSection === 'nok2'):
                [$nok2Dial, $nok2Nat] = splitE164($person['nok_2_contact']);
            ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_section">
                    <input type="hidden" name="section" value="nok2">
                    <dl class="edit-dl">
                        <dt>Name</dt>         <dd><input class="form-control" name="nok_2_name"         value="<?= _esc($person['nok_2_name']) ?>"></dd>
                        <dt>Relationship</dt> <dd><input class="form-control" name="nok_2_relationship" value="<?= _esc($person['nok_2_relationship']) ?>"></dd>
                        <dt>Contact</dt>
                        <dd style="display:flex;gap:0.4rem;">
                            <?php renderDialPrefixSelect('nok_2_contact_dial', $nok2Dial); ?>
                            <input class="form-control" name="nok_2_contact_national" value="<?= _esc($nok2Nat) ?>">
                        </dd>
                        <dt>Email</dt>        <dd><input class="form-control" type="email" name="nok_2_email" value="<?= _esc($person['nok_2_email']) ?>"></dd>
                    </dl>
                    <?= _formActions($personId) ?>
                </form>
            <?php else: ?>
                <dl>
                    <dt>Name</dt>         <dd><?= _esc($person['nok_2_name']) ?: '—' ?></dd>
                    <dt>Relationship</dt> <dd><?= _esc($person['nok_2_relationship']) ?: '—' ?></dd>
                    <dt>Contact</dt>      <dd><?= _esc(formatPhoneForDisplay($person['nok_2_contact'])) ?: '—' ?></dd>
                    <dt>Email</dt>        <dd><?= _esc($person['nok_2_email']) ?: '—' ?></dd>
                </dl>
            <?php endif; ?>
        </div>

    </div>

    <div class="person-card-section" style="padding:1rem;">
        <h3>Attachments</h3>
        <?php if (!$attachments): ?>
            <p style="color:#999;">No attachments.</p>
        <?php else: ?>
            <ul style="list-style:none;padding:0;">
                <?php foreach ($attachments as $a): ?>
                    <li style="padding:0.25rem 0;">
                        <i class="fas fa-paperclip"></i>
                        <a href="<?= APP_URL ?>/uploads/<?= _esc($a['file_path']) ?>" target="_blank">
                            <?= _esc($a['type_label']) ?>
                        </a>
                        <span style="color:#6c757d;font-size:0.85rem;">
                            uploaded <?= _esc($a['uploaded_at']) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php if ($attendanceRows):
    $present = 0; $absent = 0;
    foreach ($attendanceRows as $a) {
        if (str_contains($a['notes'] ?? '', 'Present')) $present++;
        elseif (str_contains($a['notes'] ?? '', 'Absent')) $absent++;
    }
    $totalPA = $present + $absent;
    $rate    = $totalPA ? round($present * 100 / $totalPA) : 0;
?>
<details class="card" style="margin-top:1.5rem;">
    <summary style="cursor:pointer;padding:0.75rem 1rem;background:#f8f9fa;list-style:none;display:flex;justify-content:space-between;align-items:center;">
        <h3 style="margin:0;">Course Attendance
            <span style="font-weight:400;font-size:0.85rem;color:#6c757d;margin-left:0.5rem;">
                <?= count($attendanceRows) ?> weeks · <?= $present ?> present · <?= $absent ?> absent<?= $totalPA ? ' · ' . $rate . '%' : '' ?>
            </span>
        </h3>
        <span style="color:#6c757d;font-size:0.85rem;">▾ click to expand</span>
    </summary>
    <table class="report-table tch-data-table" style="margin:0;">
        <thead><tr>
            <th style="width:130px;">Date</th>
            <th>Module</th>
            <th style="width:100px;">Type</th>
            <th style="width:100px;text-align:center;">Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($attendanceRows as $att):
            // Notes have shape "Module — Present|Absent (from sheet!cell)"
            $module = $att['notes'] ?? '';
            $status = '';
            if (preg_match('/^(.*?)\s+[-—]\s+(Present|Absent)/u', $module, $m)) {
                $module = trim($m[1]);
                $status = $m[2];
            }
            $statusBadge = $status === 'Present'
                ? '<span style="color:#198754;font-weight:600;">✓ Present</span>'
                : ($status === 'Absent'
                    ? '<span style="color:#dc3545;font-weight:600;">✗ Absent</span>'
                    : '—');
        ?>
            <tr>
                <td><?= _esc(date('D d M Y', strtotime($att['attendance_date']))) ?></td>
                <td><?= _esc($module) ?></td>
                <td><?= _esc(ucfirst($att['attendance_type'])) ?></td>
                <td style="text-align:center;"><?= $statusBadge ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</details>
<?php endif; ?>

<?php if ($sourceRows):
    // Group by tab name (parsed from source_ref like "file.xlsx#1st Intake!N3")
    $byTab = [];
    foreach ($sourceRows as $sr) {
        $tab = '(unknown tab)';
        $cell = '';
        if (preg_match('/#([^!]+)!([A-Z]+\d+)/', $sr['source_ref'], $m)) {
            $tab = $m[1]; $cell = $m[2];
        }
        $byTab[$tab][] = ['cell' => $cell] + $sr;
    }
?>
<details class="card" style="margin-top:1.5rem;">
    <summary style="cursor:pointer;padding:0.75rem 1rem;background:#f8f9fa;list-style:none;display:flex;justify-content:space-between;align-items:center;">
        <h3 style="margin:0;">Tuniti Source Data
            <span style="font-weight:400;font-size:0.85rem;color:#6c757d;margin-left:0.5rem;">
                <?= count($sourceRows) ?> values from <?= count($byTab) ?> tab<?= count($byTab) === 1 ? '' : 's' ?>
            </span>
        </h3>
        <span style="color:#6c757d;font-size:0.85rem;">▾ click to expand</span>
    </summary>
    <?php foreach ($byTab as $tab => $rows): ?>
        <div style="padding:0.5rem 1rem;border-top:1px solid #f0f0f0;">
            <div style="font-weight:600;color:#495057;margin-bottom:0.3rem;">
                <i class="fas fa-table"></i> Tab: <?= _esc($tab) ?>
                <span style="font-weight:400;color:#6c757d;font-size:0.85rem;">
                    (<?= count($rows) ?> cell<?= count($rows) === 1 ? '' : 's' ?>)
                </span>
            </div>
            <table style="width:100%;font-size:0.9rem;">
                <thead><tr style="background:#f8f9fa;">
                    <th style="text-align:left;padding:0.3rem;width:80px;">Cell</th>
                    <th style="text-align:left;padding:0.3rem;width:200px;">Field</th>
                    <th style="text-align:left;padding:0.3rem;">Value imported</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr style="border-top:1px solid #f0f0f0;">
                        <td style="padding:0.3rem;"><code><?= _esc($r['cell']) ?></code></td>
                        <td style="padding:0.3rem;"><?= _esc($r['subject']) ?></td>
                        <td style="padding:0.3rem;"><?= _esc($r['notes']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
    <div style="padding:0.5rem 1rem;background:#f8f9fa;border-top:1px solid #eee;font-size:0.85rem;color:#6c757d;">
        Source workbook: <code>Ross Intake 1-9 (3).xlsx</code> &middot; imported 13 Apr 2026
    </div>
</details>
<?php endif; ?>

<?php renderActivityTimeline('persons', $personId); ?>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
