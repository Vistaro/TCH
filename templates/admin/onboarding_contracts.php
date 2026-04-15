<?php
/**
 * Task 1 subpage — active contracts confirmation.
 * Lists contracts currently in status='draft' (needing Tuniti to confirm
 * and activate) + gives a shortcut to create new contracts. The heavy
 * lift is the existing /admin/contracts/new form.
 *
 * Also shows the count of uploaded-but-not-yet-ingested contract files
 * from the review queue, so Tuniti doesn't re-upload something we still
 * have to process.
 */
require_once APP_ROOT . '/includes/onboarding_tasks.php';
require_once APP_ROOT . '/includes/onboarding_upload.php';

$pageTitle = 'Active contracts to confirm';
$activeNav = 'onboarding';

$db  = getDB();
$uid = (int)($_SESSION['user_id'] ?? 0);

$flash = ''; $flashType = 'success';

// Handle POSTed file upload via the shared widget
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } elseif (($_POST['action'] ?? '') === 'onboarding_upload' && !empty($_FILES['file']['name'])) {
        $res = onboardingHandleUpload($db, 'contracts', $uid, $_FILES['file']);
        $flash = $res['msg']; $flashType = $res['ok'] ? 'success' : 'error';
    }
    header('Location: ' . APP_URL . '/admin/onboarding/contracts?msg=' . urlencode($flash) . '&type=' . urlencode($flashType));
    exit;
}

if ($flash === '' && isset($_GET['msg'])) {
    $flash = (string)$_GET['msg']; $flashType = (string)($_GET['type'] ?? 'success');
}

$drafts = $db->query(
    "SELECT c.id, c.start_date, c.status, c.invoice_status,
            COALESCE(pp.full_name, CONCAT('Patient #', c.patient_person_id)) AS patient_name,
            pp.tch_id AS patient_tch_id,
            COALESCE(pc.full_name, CONCAT('Client #', c.client_id)) AS client_name,
            pc.tch_id AS client_tch_id
       FROM contracts c
  LEFT JOIN persons pp ON pp.id = c.patient_person_id
  LEFT JOIN clients  cli ON cli.id = c.client_id
  LEFT JOIN persons pc ON pc.id = cli.person_id
      WHERE c.status = 'draft'
   ORDER BY c.start_date DESC
      LIMIT 200"
)->fetchAll();

$pendingUploads = (int)$db->query(
    "SELECT COUNT(*) FROM onboarding_uploads
      WHERE task_key = 'contracts' AND status IN ('uploaded','in_review')"
)->fetchColumn();

$tasks = onboardingTasks();
$task = $tasks['contracts'] ?? null;

require APP_ROOT . '/templates/layouts/admin.php';
?>

<p style="margin-bottom:0.5rem;">
    <a href="<?= APP_URL ?>/admin/onboarding" style="font-size:0.85rem;">← Back to tasks</a>
</p>

<h2 style="margin:0 0 0.5rem 0;">Active contracts to confirm</h2>
<p style="color:#6c757d;margin-bottom:1.25rem;">
    Add each live caregiver-to-client commercial agreement so shifts can be matched to a contract for billing. Drafts below are contracts already in the system awaiting finalisation; you can add new ones or attach a document.
</p>

<?= renderOnboardingUploadWidget('contracts',
    $task['upload_hint'] ?? 'Spreadsheet, Word doc, PDF — whatever format you keep contracts in.',
    ($flashType === 'success' && $flash) ? $flash : null) ?>

<?php if ($pendingUploads > 0): ?>
<div style="background:#fff4e5;border-left:4px solid #fd7e14;padding:0.7rem 1rem;border-radius:6px;margin-bottom:1rem;font-size:0.9rem;">
    <strong><?= (int)$pendingUploads ?></strong> contract file<?= $pendingUploads === 1 ? '' : 's' ?> already uploaded and waiting for us to process.
    <a href="<?= APP_URL ?>/admin/onboarding/review?status=active" style="margin-left:0.4rem;">View review queue →</a>
</div>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin:1.5rem 0 0.5rem 0;">
    <h3 style="margin:0;font-size:1.05rem;">Draft contracts — <?= count($drafts) ?></h3>
    <a href="<?= APP_URL ?>/admin/contracts/new" class="btn btn-primary btn-sm">+ New contract</a>
</div>

<?php if (empty($drafts)): ?>
    <p style="color:#6c757d;padding:1.5rem;text-align:center;background:#f8f9fa;border-radius:6px;">
        No draft contracts. Click "New contract" above to add one.
    </p>
<?php else: ?>
<table class="report-table tch-data-table">
    <thead>
        <tr>
            <th>Patient</th>
            <th>Client (bill-payer)</th>
            <th class="center">Start date</th>
            <th class="center">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($drafts as $d): ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($d['patient_name']) ?></strong>
                <code style="color:#6c757d;font-size:0.75rem;"><?= htmlspecialchars($d['patient_tch_id'] ?? '') ?></code>
            </td>
            <td>
                <?= htmlspecialchars($d['client_name']) ?>
                <code style="color:#6c757d;font-size:0.75rem;"><?= htmlspecialchars($d['client_tch_id'] ?? '') ?></code>
            </td>
            <td class="center"><?= htmlspecialchars($d['start_date']) ?></td>
            <td class="center">
                <a href="<?= APP_URL ?>/admin/contracts/<?= (int)$d['id'] ?>" class="btn btn-outline btn-sm">Open →</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
