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

// ── Detail view: now lives at /admin/students/{id} ──────────────────────
// Single source of truth — the student detail page handles full profile,
// approve/reject, and notes timeline. Anything still landing here with
// ?id=N gets a clean redirect.
$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($detailId > 0) {
    header('Location: ' . APP_URL . '/admin/students/' . $detailId);
    exit;
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
                            <a href="<?= APP_URL ?>/admin/students/<?= (int)$r['id'] ?>"
                               class="btn btn-primary btn-sm">Open</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
