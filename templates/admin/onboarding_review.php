<?php
/**
 * /admin/onboarding/review — queue of files uploaded by Tuniti
 * across all onboarding tasks, awaiting extraction.
 *
 * Admin processes each file: opens it, extracts structured data into
 * the right task's underlying tables, marks the upload as ingested.
 */
require_once APP_ROOT . '/includes/onboarding_tasks.php';
require_once APP_ROOT . '/includes/onboarding_upload.php';

$pageTitle = 'Onboarding — Review Queue';
$activeNav = 'onboarding';

$db      = getDB();
$canEdit = userCan('onboarding_review', 'edit');

$flash = ''; $flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } else {
        $action  = $_POST['action'] ?? '';
        $id      = (int)($_POST['upload_id'] ?? 0);
        $uid     = (int)($_SESSION['user_id'] ?? 0);
        $notes   = trim($_POST['notes'] ?? '');

        $allowed = ['in_review','ingested','rejected','uploaded'];
        if ($action === 'update_status' && $id) {
            $newStatus = $_POST['new_status'] ?? '';
            if (in_array($newStatus, $allowed, true)) {
                $stmt = $db->prepare(
                    "UPDATE onboarding_uploads
                        SET status = ?, notes = ?,
                            ingested_at = CASE WHEN ? IN ('ingested','rejected') THEN NOW() ELSE ingested_at END,
                            ingested_by_user_id = CASE WHEN ? IN ('ingested','rejected') THEN ? ELSE ingested_by_user_id END
                      WHERE id = ?"
                );
                $stmt->execute([$newStatus, $notes, $newStatus, $newStatus, $uid, $id]);
                logActivity('onboarding_upload_update', 'onboarding_review',
                    'onboarding_uploads', $id,
                    'Upload status → ' . $newStatus, null,
                    ['status' => $newStatus, 'notes' => $notes]);
                $flash = 'Upload updated.';
            } else {
                $flash = 'Unknown status.'; $flashType = 'error';
            }
        }
    }
    header('Location: ' . APP_URL . '/admin/onboarding/review?msg=' . urlencode($flash) . '&type=' . urlencode($flashType));
    exit;
}

if ($flash === '' && isset($_GET['msg'])) {
    $flash = (string)$_GET['msg']; $flashType = (string)($_GET['type'] ?? 'success');
}

$statusFilter = $_GET['status'] ?? 'active';
$where = '';
if     ($statusFilter === 'active')   $where = "WHERE u.status IN ('uploaded','in_review')";
elseif ($statusFilter === 'ingested') $where = "WHERE u.status = 'ingested'";
elseif ($statusFilter === 'rejected') $where = "WHERE u.status = 'rejected'";
else                                   $where = "";

$stmt = $db->query(
    "SELECT u.*,
            up.full_name AS uploader_name,
            ip.full_name AS ingested_by_name
       FROM onboarding_uploads u
  LEFT JOIN users up_u ON up_u.id = u.uploader_user_id
  LEFT JOIN persons up ON up.id = up_u.person_id
  LEFT JOIN users ip_u ON ip_u.id = u.ingested_by_user_id
  LEFT JOIN persons ip ON ip.id = ip_u.person_id
       $where
   ORDER BY u.uploaded_at DESC
      LIMIT 200"
);
$rows = $stmt->fetchAll();

$tasks = onboardingTasks();

require APP_ROOT . '/templates/layouts/admin.php';
?>

<?php if ($flash): ?>
<div class="alert alert-<?= htmlspecialchars($flashType === 'error' ? 'error' : 'success') ?>" style="margin-bottom:1rem;">
    <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<div style="display:flex;gap:0.5rem;margin-bottom:1rem;flex-wrap:wrap;">
    <?php foreach (['active' => 'Active', 'ingested' => 'Ingested', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $lbl):
        $active = ($statusFilter === $k);
    ?>
        <a href="?status=<?= $k ?>" style="padding:0.4rem 0.9rem;border-radius:5px;text-decoration:none;background:<?= $active ? '#0d6efd' : '#f8f9fa' ?>;color:<?= $active ? '#fff' : '#495057' ?>;border:1px solid #dee2e6;font-size:0.85rem;">
            <?= htmlspecialchars($lbl) ?>
        </a>
    <?php endforeach; ?>
    <a href="<?= APP_URL ?>/admin/onboarding" style="margin-left:auto;font-size:0.85rem;color:#0d6efd;">← Back to tasks</a>
</div>

<table class="report-table tch-data-table">
    <thead>
        <tr>
            <th class="center">Uploaded</th>
            <th>Task</th>
            <th>File</th>
            <th>Uploaded by</th>
            <th class="center">Size</th>
            <th class="center">Status</th>
            <th class="center">Action</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $taskTitle = $tasks[$r['task_key']]['title'] ?? $r['task_key'];
        $statusColour = [
            'uploaded'   => '#fd7e14',
            'in_review'  => '#0d6efd',
            'ingested'   => '#198754',
            'rejected'   => '#dc3545',
        ][$r['status']] ?? '#6c757d';
    ?>
    <tr>
        <td class="center" style="font-size:0.85rem;"><?= htmlspecialchars($r['uploaded_at']) ?></td>
        <td><?= htmlspecialchars($taskTitle) ?></td>
        <td>
            <strong><?= htmlspecialchars($r['filename']) ?></strong>
            <div style="color:#6c757d;font-size:0.75rem;"><?= htmlspecialchars($r['mime'] ?? '') ?></div>
        </td>
        <td><?= htmlspecialchars($r['uploader_name'] ?? '—') ?></td>
        <td class="center"><?= number_format((int)$r['size_bytes'] / 1024, 0) ?> KB</td>
        <td class="center">
            <span style="background:<?= $statusColour ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">
                <?= htmlspecialchars(str_replace('_', ' ', $r['status'])) ?>
            </span>
            <?php if (!empty($r['notes'])): ?>
                <div style="font-size:0.7rem;color:#6c757d;margin-top:0.2rem;max-width:200px;"><?= htmlspecialchars($r['notes']) ?></div>
            <?php endif; ?>
        </td>
        <td class="center">
        <?php if ($canEdit): ?>
            <details>
                <summary style="cursor:pointer;font-size:0.85rem;">Update</summary>
                <form method="POST" style="margin-top:0.4rem;display:grid;gap:0.3rem;max-width:260px;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="upload_id" value="<?= (int)$r['id'] ?>">
                    <select name="new_status" class="form-control form-control-sm">
                        <option value="uploaded"  <?= $r['status'] === 'uploaded'  ? 'selected' : '' ?>>Uploaded (new)</option>
                        <option value="in_review" <?= $r['status'] === 'in_review' ? 'selected' : '' ?>>In review</option>
                        <option value="ingested"  <?= $r['status'] === 'ingested'  ? 'selected' : '' ?>>Ingested</option>
                        <option value="rejected"  <?= $r['status'] === 'rejected'  ? 'selected' : '' ?>>Rejected</option>
                    </select>
                    <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="Notes (e.g. 'extracted 14 contracts from sheet 1')"><?= htmlspecialchars($r['notes'] ?? '') ?></textarea>
                    <button class="btn btn-sm btn-primary" type="submit">Save</button>
                </form>
            </details>
        <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?>
        <tr><td colspan="7" style="text-align:center;color:#999;padding:2rem;">No uploads match this filter.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
