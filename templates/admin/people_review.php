<?php
/**
 * Imported People Review
 * ----------------------
 * Lists caregivers in `import_review_state = 'pending'` (people who have been
 * created or enriched via PDF import) and lets Ross approve or reject each
 * one. Single file handles both the list view and the per-person detail card.
 *
 * - List view:    /admin/people/review
 * - Detail view:  /admin/people/review?id=N
 *
 * The detail card mirrors the layout of the original Tuniti intake PDF
 * (photo top-left, two-column field block, NoK block, attachments list,
 * import_notes panel).
 */

$pageTitle = 'Person Review';
$activeNav = 'people-review';

$db = getDB();
$user = currentUser();

// ── Handle POST actions ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $action    = $_POST['action'] ?? '';
    $personId  = (int)($_POST['person_id'] ?? 0);

    if ($personId > 0 && in_array($action, ['approve', 'reject'], true) && userCan('people_review', 'edit')) {
        $newState = $action === 'approve' ? null : 'rejected';
        $stmt = $db->prepare('UPDATE persons SET import_review_state = ? WHERE id = ?');
        $stmt->execute([$newState, $personId]);

        // Append an audit line to import_notes
        $actorLabel = $user['email'] ?? $user['username'] ?? 'unknown';
        $auditLine = sprintf(
            "Review action: %s by %s at %s.",
            $action,
            $actorLabel,
            date('Y-m-d H:i:s')
        );
        $stmt = $db->prepare(
            "UPDATE persons
             SET import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''), ?)
             WHERE id = ?"
        );
        $stmt->execute([$auditLine, $personId]);

        logActivity(
            $action === 'approve' ? 'person_approved' : 'person_rejected',
            'people_review',
            'persons',
            $personId,
            ucfirst($action) . " caregiver #" . $personId,
            ['import_review_state' => 'pending'],
            ['import_review_state' => $newState]
        );
    }

    // After acting, return to whichever view we came from
    $back = $_POST['return_to'] ?? 'list';
    if ($back === 'detail') {
        header('Location: ' . APP_URL . '/admin/people/review?id=' . $personId);
    } else {
        header('Location: ' . APP_URL . '/admin/people/review');
    }
    exit;
}

// ── Detail view ──────────────────────────────────────────────────────────
$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($detailId > 0) {
    $stmt = $db->prepare(
        "SELECT cg.*,
                ps.label AS status_label,
                ls.label AS lead_source_label
         FROM persons cg
         LEFT JOIN students st     ON st.person_id = cg.id
         LEFT JOIN person_statuses ps ON ps.id = st.status_id
         LEFT JOIN lead_sources    ls ON ls.id = st.lead_source_id
         WHERE cg.id = ?"
    );
    $stmt->execute([$detailId]);
    $person = $stmt->fetch();

    if (!$person) {
        http_response_code(404);
        $pageTitle = 'Person Not Found';
        require APP_ROOT . '/templates/layouts/admin.php';
        echo '<p>No person record with id ' . (int)$detailId . '.</p>';
        echo '<p><a href="' . APP_URL . '/admin/people/review">Back to review queue</a></p>';
        require APP_ROOT . '/templates/layouts/admin_footer.php';
        return;
    }

    // Load attachments
    $stmt = $db->prepare(
        "SELECT a.*, at.code AS type_code, at.label AS type_label
         FROM attachments a
         JOIN attachment_types at ON at.id = a.attachment_type_id
         WHERE a.person_id = ? AND a.is_active = 1
         ORDER BY at.sort_order, a.uploaded_at"
    );
    $stmt->execute([$detailId]);
    $attachments = $stmt->fetchAll();

    // Find the profile photo (most recent active)
    $photoPath = null;
    foreach ($attachments as $a) {
        if ($a['type_code'] === 'profile_photo') {
            $photoPath = $a['file_path'];
            break;
        }
    }

    require APP_ROOT . '/templates/layouts/admin.php';
    ?>

    <div style="margin-bottom:1rem;">
        <a href="<?= APP_URL ?>/admin/people/review" class="btn btn-outline btn-sm">&larr; Back to queue</a>
    </div>

    <div class="person-card">
        <div class="person-card-header">
            <?php if ($photoPath): ?>
                <img class="person-photo"
                     src="<?= APP_URL ?>/uploads/<?= htmlspecialchars($photoPath) ?>"
                     alt="<?= htmlspecialchars($person['full_name']) ?>">
            <?php else: ?>
                <div class="person-photo person-photo-placeholder">No photo</div>
            <?php endif; ?>
            <div class="person-card-title">
                <h2><?= htmlspecialchars($person['full_name']) ?></h2>
                <div class="person-card-tch-id"><?= htmlspecialchars($person['tch_id'] ?? '—') ?></div>
                <div class="person-card-meta">
                    <?php if ($person['student_id']): ?>
                        Student ID: <strong><?= htmlspecialchars($person['student_id']) ?></strong> &middot;
                    <?php endif; ?>
                    <?php if ($person['cohort']): ?>
                        Cohort: <strong><?= htmlspecialchars($person['cohort']) ?></strong> &middot;
                    <?php endif; ?>
                    Status: <strong><?= htmlspecialchars($person['status_label'] ?? '—') ?></strong>
                </div>
            </div>
        </div>

        <div class="person-card-grid">
            <!-- Personal block -->
            <div class="person-card-section">
                <h3>Personal</h3>
                <dl>
                    <dt>Known As</dt>     <dd><?= htmlspecialchars($person['known_as']    ?? '') ?: '—' ?></dd>
                    <dt>Title</dt>        <dd><?= htmlspecialchars($person['title']       ?? '') ?: '—' ?></dd>
                    <dt>Initials</dt>     <dd><?= htmlspecialchars($person['initials']    ?? '') ?: '—' ?></dd>
                    <dt>ID / Passport</dt><dd><?= htmlspecialchars($person['id_passport'] ?? '') ?: '—' ?></dd>
                    <dt>Date of Birth</dt><dd><?= htmlspecialchars($person['dob']         ?? '') ?: '—' ?></dd>
                    <dt>Gender</dt>       <dd><?= htmlspecialchars($person['gender']      ?? '') ?: '—' ?></dd>
                    <dt>Nationality</dt>  <dd><?= htmlspecialchars($person['nationality'] ?? '') ?: '—' ?></dd>
                    <dt>Home Lang</dt>    <dd><?= htmlspecialchars($person['home_language']  ?? '') ?: '—' ?></dd>
                    <dt>Other Lang</dt>   <dd><?= htmlspecialchars($person['other_language'] ?? '') ?: '—' ?></dd>
                </dl>
            </div>

            <!-- Contact block -->
            <div class="person-card-section">
                <h3>Contact</h3>
                <dl>
                    <dt>Mobile</dt>       <dd><?= htmlspecialchars($person['mobile']           ?? '') ?: '—' ?></dd>
                    <dt>Secondary</dt>    <dd><?= htmlspecialchars($person['secondary_number'] ?? '') ?: '—' ?></dd>
                    <dt>Email</dt>        <dd><?= htmlspecialchars($person['email']            ?? '') ?: '—' ?></dd>
                    <dt>Lead Source</dt>  <dd><?= htmlspecialchars($person['lead_source_label'] ?? '') ?: '—' ?></dd>
                    <?php if ($person['referred_by_name']): ?>
                    <dt>Referred By</dt>  <dd><?= htmlspecialchars($person['referred_by_name']) ?>
                                              <?= $person['referred_by_contact'] ? '(' . htmlspecialchars($person['referred_by_contact']) . ')' : '' ?></dd>
                    <?php endif; ?>
                </dl>
            </div>

            <!-- Address block -->
            <div class="person-card-section">
                <h3>Home Address</h3>
                <dl>
                    <dt>Complex/Estate</dt><dd><?= htmlspecialchars($person['complex_estate'] ?? '') ?: '—' ?></dd>
                    <dt>Street</dt>        <dd><?= htmlspecialchars($person['street_address'] ?? '') ?: '—' ?></dd>
                    <dt>Suburb</dt>        <dd><?= htmlspecialchars($person['suburb']         ?? '') ?: '—' ?></dd>
                    <dt>City</dt>          <dd><?= htmlspecialchars($person['city']           ?? '') ?: '—' ?></dd>
                    <dt>Province</dt>      <dd><?= htmlspecialchars($person['province']       ?? '') ?: '—' ?></dd>
                    <dt>Postal Code</dt>   <dd><?= htmlspecialchars($person['postal_code']    ?? '') ?: '—' ?></dd>
                </dl>
            </div>

            <!-- NoK block -->
            <div class="person-card-section">
                <h3>Emergency Contact</h3>
                <dl>
                    <dt>Name</dt>         <dd><?= htmlspecialchars($person['nok_name']         ?? '') ?: '—' ?></dd>
                    <dt>Relationship</dt> <dd><?= htmlspecialchars($person['nok_relationship'] ?? '') ?: '—' ?></dd>
                    <dt>Contact</dt>      <dd><?= htmlspecialchars($person['nok_contact']      ?? '') ?: '—' ?></dd>
                    <dt>Email</dt>        <dd><?= htmlspecialchars($person['nok_email']        ?? '') ?: '—' ?></dd>
                </dl>
                <?php if ($person['nok_2_name']): ?>
                <h3 style="margin-top:1rem;">Emergency Contact (2nd)</h3>
                <dl>
                    <dt>Name</dt>         <dd><?= htmlspecialchars($person['nok_2_name'])         ?></dd>
                    <dt>Relationship</dt> <dd><?= htmlspecialchars($person['nok_2_relationship'] ?? '') ?: '—' ?></dd>
                    <dt>Contact</dt>      <dd><?= htmlspecialchars($person['nok_2_contact']      ?? '') ?: '—' ?></dd>
                    <dt>Email</dt>        <dd><?= htmlspecialchars($person['nok_2_email']        ?? '') ?: '—' ?></dd>
                </dl>
                <?php endif; ?>
            </div>
        </div>

        <!-- Attachments -->
        <div class="person-card-section">
            <h3>Attachments</h3>
            <?php if (empty($attachments)): ?>
                <p style="color:#999;">No attachments.</p>
            <?php else: ?>
                <ul class="attachment-list">
                    <?php foreach ($attachments as $a): ?>
                        <li>
                            <strong><?= htmlspecialchars($a['type_label']) ?></strong>
                            &mdash;
                            <a href="<?= APP_URL ?>/uploads/<?= htmlspecialchars($a['file_path']) ?>"
                               target="_blank" rel="noopener">
                                <?= htmlspecialchars($a['original_filename'] ?: basename($a['file_path'])) ?>
                            </a>
                            <?php if ($a['source_page']): ?>
                                <span style="color:#999;">(page <?= (int)$a['source_page'] ?>)</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Import notes (machine-generated audit) -->
        <?php if ($person['import_notes']): ?>
        <div class="person-card-section">
            <h3>Import Notes (audit)</h3>
            <pre class="import-notes"><?= htmlspecialchars($person['import_notes']) ?></pre>
        </div>
        <?php endif; ?>

        <!-- Human notes -->
        <?php if ($person['notes']): ?>
        <div class="person-card-section">
            <h3>Notes</h3>
            <p><?= nl2br(htmlspecialchars($person['notes'])) ?></p>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <?php if ($person['import_review_state'] === 'pending'): ?>
        <div class="person-card-actions">
            <form method="POST" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="person_id" value="<?= (int)$person['id'] ?>">
                <input type="hidden" name="return_to" value="list">
                <button type="submit" name="action" value="approve" class="btn btn-primary">
                    Approve &amp; Return to Queue
                </button>
                <button type="submit" name="action" value="reject" class="btn btn-danger"
                        onclick="return confirm('Reject this import? Person row stays in DB but is marked rejected.');">
                    Reject
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <?php
    require APP_ROOT . '/templates/layouts/admin_footer.php';
    return;
}

// ── List view ────────────────────────────────────────────────────────────

// Filter
$filterCohort = $_GET['cohort'] ?? '';
$where  = ["st.import_review_state = 'pending'"];
$params = [];
if ($filterCohort !== '') {
    $where[]  = 'st.cohort = ?';
    $params[] = $filterCohort;
}
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$sql = "SELECT cg.id, cg.tch_id, cg.full_name, cg.known_as, st.student_id, st.cohort,
               st.import_notes IS NOT NULL AND st.import_notes != '' AS has_notes,
               (SELECT COUNT(*) FROM attachments a
                WHERE a.person_id = cg.id AND a.is_active = 1) AS attachment_count
        FROM persons cg
        LEFT JOIN students st ON st.person_id = cg.id
        $whereSQL
        ORDER BY st.cohort, cg.id";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$cohorts = $db->query(
    "SELECT DISTINCT cohort FROM students
     WHERE import_review_state = 'pending' AND cohort IS NOT NULL
     ORDER BY cohort"
)->fetchAll(PDO::FETCH_COLUMN);

$totalPending = (int)$db->query(
    "SELECT COUNT(*) FROM students WHERE import_review_state = 'pending'"
)->fetchColumn();

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div class="dash-cards" style="margin-bottom:1.5rem;">
    <div class="dash-card accent">
        <div class="dash-card-label">Pending Review</div>
        <div class="dash-card-value"><?= $totalPending ?></div>
        <div class="dash-card-sub">People imported from PDFs awaiting human approval</div>
    </div>
</div>

<form method="GET" action="<?= APP_URL ?>/admin/people/review" class="report-filters">
    <div class="filter-group">
        <label>Cohort</label>
        <select name="cohort">
            <option value="">All Cohorts</option>
            <?php foreach ($cohorts as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $filterCohort === $t ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary">Filter</button>
    <?php if ($filterCohort): ?>
        <a href="<?= APP_URL ?>/admin/people/review" class="btn btn-outline btn-sm">Clear</a>
    <?php endif; ?>
</form>

<div class="report-table-wrap">
    <table class="name-table tch-data-table">
        <thead>
            <tr>
                <th>Photo</th>
                <th>TCH ID</th>
                <th>Full Name</th>
                <th>Known As</th>
                <th>Student ID</th>
                <th>Cohort</th>
                <th>Attachments</th>
                <th>Notes?</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="9" style="text-align:center;color:#999;padding:2rem;">
                    No people pending review.
                </td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <?php
                    // Look up the photo path for the thumb
                    $tphStmt = $db->prepare(
                        "SELECT file_path FROM attachments
                         WHERE person_id = ? AND is_active = 1
                         AND attachment_type_id = (SELECT id FROM attachment_types WHERE code='profile_photo')
                         ORDER BY uploaded_at DESC LIMIT 1"
                    );
                    $tphStmt->execute([$r['id']]);
                    $thumbPath = $tphStmt->fetchColumn();
                    ?>
                    <tr>
                        <td>
                            <?php if ($thumbPath): ?>
                                <img src="<?= APP_URL ?>/uploads/<?= htmlspecialchars($thumbPath) ?>"
                                     alt="" style="width:48px;height:48px;border-radius:50%;object-fit:cover;">
                            <?php else: ?>
                                <div style="width:48px;height:48px;border-radius:50%;background:#eee;
                                            display:inline-block;"></div>
                            <?php endif; ?>
                        </td>
                        <td><code><?= htmlspecialchars($r['tch_id']) ?></code></td>
                        <td><strong><?= htmlspecialchars($r['full_name']) ?></strong></td>
                        <td><?= htmlspecialchars($r['known_as'] ?? '') ?: '—' ?></td>
                        <td><?= htmlspecialchars($r['student_id'] ?? '') ?: '—' ?></td>
                        <td><?= htmlspecialchars($r['cohort'] ?? '') ?: '—' ?></td>
                        <td><?= (int)$r['attachment_count'] ?></td>
                        <td><?= $r['has_notes'] ? '<span class="badge badge-warning">Yes</span>' : '—' ?></td>
                        <td>
                            <a href="<?= APP_URL ?>/admin/people/review?id=<?= (int)$r['id'] ?>"
                               class="btn btn-primary btn-sm">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
