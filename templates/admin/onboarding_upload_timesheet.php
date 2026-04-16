<?php
/**
 * Onboarding — upload the latest Caregiver Timesheet workbook.
 * Monthly cadence. Uses the shared onboarding_uploads pipeline.
 * Format documentation + (future) screenshot live here so Tuniti
 * can see the expected shape before she uploads.
 */
require_once APP_ROOT . '/includes/onboarding_tasks.php';
require_once APP_ROOT . '/includes/onboarding_upload.php';

$pageTitle = 'Upload Caregiver Timesheet';
$activeNav = 'onboarding';

$db  = getDB();
$uid = (int)($_SESSION['user_id'] ?? 0);

$flash = ''; $flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } elseif (($_POST['action'] ?? '') === 'onboarding_upload' && !empty($_FILES['file']['name'])) {
        $res = onboardingHandleUpload($db, 'periodic_timesheet_upload', $uid, $_FILES['file']);
        $flash = $res['msg']; $flashType = $res['ok'] ? 'success' : 'error';
    }
    header('Location: ' . APP_URL . '/admin/onboarding/upload-timesheet?msg=' . urlencode($flash) . '&type=' . urlencode($flashType));
    exit;
}

if ($flash === '' && isset($_GET['msg'])) {
    $flash = (string)$_GET['msg']; $flashType = (string)($_GET['type'] ?? 'success');
}

$history = $db->query(
    "SELECT u.*, p.full_name AS uploader_name
       FROM onboarding_uploads u
  LEFT JOIN users up_u ON up_u.id = u.uploader_user_id
  LEFT JOIN persons p ON p.id = up_u.person_id
      WHERE u.task_key = 'periodic_timesheet_upload'
   ORDER BY u.uploaded_at DESC
      LIMIT 12"
)->fetchAll();

$screenshot = APP_ROOT . '/public/assets/img/onboarding/timesheet_format.png';
$screenshotUrl = file_exists($screenshot) ? (APP_URL . '/assets/img/onboarding/timesheet_format.png') : null;

require APP_ROOT . '/templates/layouts/admin.php';
?>

<?php if ($flash): ?>
<div class="alert alert-<?= htmlspecialchars($flashType === 'error' ? 'error' : 'success') ?>" style="margin-bottom:1rem;">
    <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<p style="margin-bottom:0.5rem;">
    <a href="<?= APP_URL ?>/admin/onboarding" style="font-size:0.85rem;">← Back to tasks</a>
</p>

<h2 style="margin:0 0 0.5rem 0;">Upload latest Caregiver Timesheet workbook</h2>
<p style="color:#6c757d;margin-bottom:1rem;">
    Each month, Tuniti provides the caregiver attendance Excel. Upload the latest copy here — we ingest it and update the per-shift cost data.
</p>

<?= renderOnboardingUploadWidget(
    'periodic_timesheet_upload',
    'Tuniti Caregiver Timesheets Apr-26.xlsx (or the latest month). One sheet per month — columns for dates, caregivers along the top, cell contents = patient/shift data.',
    null
) ?>

<div style="background:#f8f9fa;border:1px solid #e0e4e8;border-radius:8px;padding:1rem 1.25rem;margin-bottom:1.25rem;">
    <h3 style="margin:0 0 0.4rem 0;font-size:1.05rem;">Expected file format</h3>
    <ul style="margin:0.4rem 0 0.6rem 1.1rem;padding:0;color:#495057;font-size:0.9rem;line-height:1.55;">
        <li>Excel (.xlsx) workbook with one tab per month, named e.g. <code>Caregiver Nov 2025</code>, <code>Caregiver Mar 2026</code>.</li>
        <li>Row 1: caregiver names across the columns (column B onwards).</li>
        <li>Row 2: "Caregiver Price" daily rate for each caregiver (optional per column).</li>
        <li>Column A: dates (as Excel date serials), one per row.</li>
        <li>Cell contents: patient name or abbreviation. Half-days marked with a trailing <code>-half</code> or as fractions.</li>
        <li>Below the date block: a <code>Total amount</code> row with per-caregiver monthly totals in column B.</li>
    </ul>
    <?php if ($screenshotUrl): ?>
        <img src="<?= htmlspecialchars($screenshotUrl) ?>" alt="Expected Timesheet format" style="max-width:100%;border:1px solid #ccc;border-radius:6px;margin-top:0.5rem;">
    <?php else: ?>
        <div style="background:#fff4e5;border:1px dashed #fd7e14;padding:0.75rem;border-radius:6px;font-size:0.85rem;color:#664d03;">
            <em>Screenshot placeholder.</em> Drop a reference image at
            <code>public/assets/img/onboarding/timesheet_format.png</code> on the server and it will appear here automatically.
        </div>
    <?php endif; ?>
</div>

<h3 style="margin:0 0 0.4rem 0;font-size:1.05rem;">Recent uploads</h3>
<?php if (empty($history)): ?>
    <p style="color:#6c757d;padding:1rem;text-align:center;background:#f8f9fa;border-radius:6px;">No uploads yet for this task.</p>
<?php else: ?>
<table class="report-table tch-data-table">
    <thead>
        <tr>
            <th class="center">Uploaded</th>
            <th>Filename</th>
            <th>Uploader</th>
            <th class="center">Status</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($history as $h): ?>
    <tr>
        <td class="center" style="font-size:0.85rem;"><?= htmlspecialchars($h['uploaded_at']) ?></td>
        <td><?= htmlspecialchars($h['filename']) ?></td>
        <td><?= htmlspecialchars($h['uploader_name'] ?? '—') ?></td>
        <td class="center">
            <span style="background:<?= [
                'uploaded'  => '#fd7e14',
                'in_review' => '#0d6efd',
                'ingested'  => '#198754',
                'rejected'  => '#dc3545',
            ][$h['status']] ?? '#6c757d' ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;"><?= htmlspecialchars(str_replace('_', ' ', $h['status'])) ?></span>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
