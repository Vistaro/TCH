<?php
/**
 * Create new student — /admin/students/new
 *
 * Creates a `persons` row (person_type='caregiver'), matching `students`
 * row, and an initial `student_enrollments` row, in one transaction.
 * Redirects to the new student's detail page on success.
 *
 * Permission: student_view.create.
 */

require_once APP_ROOT . '/includes/countries.php';
require_once APP_ROOT . '/includes/activities_render.php';

$pageTitle = 'New Student';
$activeNav = 'student-tracking';

$db = getDB();
$flash = '';
$flashType = 'error';

// Cohort dropdown — distinct cohorts already in the system + a "new" option
$existingCohorts = $db->query(
    "SELECT DISTINCT cohort FROM students WHERE cohort IS NOT NULL ORDER BY cohort"
)->fetchAll(PDO::FETCH_COLUMN);

$form = [
    'full_name'    => '', 'known_as' => '', 'gender' => '', 'dob' => '',
    'id_passport'  => '', 'nationality' => 'South African',
    'mobile_dial'  => '+27', 'mobile_national' => '',
    'email'        => '', 'cohort' => '', 'cohort_new' => '',
    'course_start' => date('Y-m-d'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.';
    } else {
        foreach (array_keys($form) as $k) {
            $form[$k] = trim((string)($_POST[$k] ?? ''));
        }

        $fullName = $form['full_name'];
        $cohort   = $form['cohort'] === '__new__' ? $form['cohort_new'] : $form['cohort'];
        $cohort   = trim($cohort);
        $mobile   = joinE164($form['mobile_dial'] ?: '+27', $form['mobile_national']);

        if ($fullName === '') {
            $flash = 'Full name is required.';
        } elseif ($cohort === '') {
            $flash = 'Cohort is required (pick one or enter a new label).';
        } else {
            try {
                $db->beginTransaction();

                // Allocate next TCH ID
                $nextNum = (int)$db->query("SELECT COALESCE(MAX(CAST(SUBSTRING(tch_id,5) AS UNSIGNED)),0) + 1 FROM persons WHERE tch_id LIKE 'TCH-%'")->fetchColumn();
                $tchId = 'TCH-' . str_pad((string)$nextNum, 6, '0', STR_PAD_LEFT);

                $db->prepare(
                    "INSERT INTO persons
                        (person_type, tch_id, full_name, known_as, gender, dob,
                         nationality, id_passport, mobile, email, cohort, country, created_at)
                     VALUES ('caregiver', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'South Africa', NOW())"
                )->execute([
                    $tchId, $fullName, $form['known_as'] ?: null,
                    $form['gender'] ?: null, $form['dob'] ?: null,
                    $form['nationality'] ?: null, $form['id_passport'] ?: null,
                    $mobile ?: null, $form['email'] ?: null, $cohort,
                ]);
                $newId = (int)$db->lastInsertId();

                $db->prepare(
                    "INSERT INTO students (person_id, cohort, course_start, import_review_state)
                     VALUES (?, ?, ?, NULL)"
                )->execute([$newId, $cohort, $form['course_start'] ?: null]);

                $db->prepare(
                    "INSERT INTO student_enrollments
                        (student_person_id, cohort, enrolled_at, status)
                     VALUES (?, ?, ?, 'enrolled')"
                )->execute([$newId, $cohort, $form['course_start'] ?: date('Y-m-d')]);

                $me = currentEffectiveUser();
                logActivity('student_created', 'student_view', 'persons', $newId,
                    'Created student ' . $fullName . ' (' . $tchId . ')',
                    null, ['full_name' => $fullName, 'cohort' => $cohort]);
                logSystemActivity('persons', $newId,
                    'Student created',
                    'Manually created by ' . ($me['full_name'] ?? '?') . ' on ' . date('d M Y H:i'),
                    'student_create', 'manual-' . date('Ymd'));

                $db->commit();
                header('Location: ' . APP_URL . '/admin/students/' . $newId
                       . '?msg=' . urlencode('Student created.') . '&type=success');
                exit;
            } catch (Throwable $e) {
                $db->rollBack();
                $flash = 'Could not create student: ' . $e->getMessage();
            }
        }
    }
}

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="margin-bottom:1rem;">
    <a href="<?= APP_URL ?>/admin/students" class="btn btn-outline btn-sm">&larr; Back to students</a>
</div>

<?php if ($flash): ?>
    <div class="flash flash-<?= htmlspecialchars($flashType) ?>" style="margin-bottom:1rem;padding:0.5rem 1rem;background:#f8d7da;border-radius:4px;">
        <?= htmlspecialchars($flash) ?>
    </div>
<?php endif; ?>

<div class="card" style="max-width:760px;">
    <div class="card-header"><h3 style="margin:0;">New Student</h3></div>
    <form method="POST" style="padding:1rem;">
        <?= csrfField() ?>
        <p style="color:#6c757d;font-size:0.9rem;margin-top:0;">
            Required: name + cohort. Everything else can be added later via the
            student record. A TCH ID is allocated automatically.
        </p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label>Full name *</label>
                <input class="form-control" name="full_name" required value="<?= htmlspecialchars($form['full_name']) ?>">
            </div>
            <div>
                <label>Known as</label>
                <input class="form-control" name="known_as" value="<?= htmlspecialchars($form['known_as']) ?>">
            </div>
            <div>
                <label>Cohort *</label>
                <select class="form-control" name="cohort" id="cohort-pick" required onchange="document.getElementById('cohort-new-row').style.display = this.value==='__new__' ? '' : 'none';">
                    <option value="">— pick —</option>
                    <?php foreach ($existingCohorts as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= $form['cohort']===$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                    <option value="__new__" <?= $form['cohort']==='__new__'?'selected':'' ?>>+ New cohort…</option>
                </select>
            </div>
            <div id="cohort-new-row" style="<?= $form['cohort']==='__new__'?'':'display:none;' ?>">
                <label>New cohort label</label>
                <input class="form-control" name="cohort_new" value="<?= htmlspecialchars($form['cohort_new']) ?>" placeholder="e.g. Cohort 10">
            </div>
            <div>
                <label>Course start</label>
                <input class="form-control" type="date" name="course_start" value="<?= htmlspecialchars($form['course_start']) ?>">
            </div>
            <div>
                <label>Gender</label>
                <select class="form-control" name="gender">
                    <option value="">—</option>
                    <?php foreach (['Female','Male','Other'] as $g): ?>
                        <option value="<?= $g ?>" <?= $form['gender']===$g?'selected':'' ?>><?= $g ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Date of birth</label>
                <input class="form-control" type="date" name="dob" value="<?= htmlspecialchars($form['dob']) ?>">
            </div>
            <div>
                <label>ID / Passport</label>
                <input class="form-control" name="id_passport" value="<?= htmlspecialchars($form['id_passport']) ?>">
            </div>
            <div>
                <label>Nationality</label>
                <input class="form-control" name="nationality" value="<?= htmlspecialchars($form['nationality']) ?>">
            </div>
            <div>
                <label>Mobile</label>
                <div style="display:flex;gap:0.4rem;">
                    <?php renderDialPrefixSelect('mobile_dial', $form['mobile_dial']); ?>
                    <input class="form-control" name="mobile_national" value="<?= htmlspecialchars($form['mobile_national']) ?>" placeholder="national number">
                </div>
            </div>
            <div>
                <label>Email</label>
                <input class="form-control" type="email" name="email" value="<?= htmlspecialchars($form['email']) ?>">
            </div>
        </div>

        <div style="margin-top:1rem;text-align:right;">
            <a href="<?= APP_URL ?>/admin/students" class="btn btn-link">Cancel</a>
            <button type="submit" class="btn btn-primary">Create student</button>
        </div>
    </form>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
