<?php
/**
 * Task 3 subpage — caregiver working patterns.
 * Bulk table: one row per caregiver with checkboxes for days of the
 * week + radio for day/night/both + checkbox for live-in. working_pattern
 * is stored as a compact string e.g. "MON,TUE,WED,THU,FRI|DAY|LIVEIN".
 */
require_once APP_ROOT . '/includes/onboarding_tasks.php';
require_once APP_ROOT . '/includes/onboarding_upload.php';

$pageTitle = 'Caregiver working patterns';
$activeNav = 'onboarding';

$db      = getDB();
$uid     = (int)($_SESSION['user_id'] ?? 0);
$canEdit = userCan('caregiver_view', 'edit');

$flash = ''; $flashType = 'success';

// Upload handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'onboarding_upload') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } else {
        $res = onboardingHandleUpload($db, 'caregiver_patterns', $uid, $_FILES['file'] ?? []);
        $flash = $res['msg']; $flashType = $res['ok'] ? 'success' : 'error';
    }
    header('Location: ' . APP_URL . '/admin/onboarding/caregiver-patterns?msg=' . urlencode($flash) . '&type=' . urlencode($flashType));
    exit;
}

// Bulk save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit && ($_POST['action'] ?? '') === 'save_patterns') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } else {
        $patterns = $_POST['pattern'] ?? [];
        $validDays = ['MON','TUE','WED','THU','FRI','SAT','SUN'];
        $validShifts = ['DAY','NIGHT','BOTH'];
        $changed = 0;
        $db->beginTransaction();
        try {
            foreach ($patterns as $pid => $fields) {
                $pid = (int)$pid;
                if ($pid <= 0) continue;
                $days = array_values(array_intersect($validDays, (array)($fields['days'] ?? [])));
                $shift = in_array($fields['shift'] ?? '', $validShifts, true) ? $fields['shift'] : 'DAY';
                $liveIn = !empty($fields['livein']) ? 'LIVEIN' : '';
                $pattern = implode(',', $days) . '|' . $shift . ($liveIn ? '|' . $liveIn : '');
                // caregivers.working_pattern is VARCHAR(64) since migration 035.
                // Max realistic serialisation is 38 chars (full 7-day week +
                // NIGHT + LIVEIN). If we ever exceed the column width, fail
                // loudly — silent truncation corrupts parseable patterns and
                // loses the day/shift/live-in flags on the right.
                if (strlen($pattern) > 64) {
                    throw new RuntimeException(
                        'Pattern too long (' . strlen($pattern) . ' chars) for caregiver ' . $pid
                    );
                }
                $stmt = $db->prepare("UPDATE caregivers SET working_pattern = ? WHERE person_id = ?");
                $stmt->execute([$pattern, $pid]);
                if ($stmt->rowCount()) $changed++;
            }
            logActivity('caregiver_patterns_bulk', 'onboarding', 'caregivers', 0,
                'Saved working patterns for ' . $changed . ' caregiver(s)',
                null, ['changed' => $changed]);
            $db->commit();
            $flash = "Saved. {$changed} caregiver pattern(s) updated.";
        } catch (Throwable $e) {
            $db->rollBack();
            $flash = 'Error: ' . $e->getMessage(); $flashType = 'error';
        }
    }
    header('Location: ' . APP_URL . '/admin/onboarding/caregiver-patterns?msg=' . urlencode($flash) . '&type=' . urlencode($flashType));
    exit;
}

if ($flash === '' && isset($_GET['msg'])) {
    $flash = (string)$_GET['msg']; $flashType = (string)($_GET['type'] ?? 'success');
}

$rows = $db->query(
    "SELECT c.person_id, c.day_rate, c.status, c.working_pattern,
            p.full_name, p.tch_id
       FROM caregivers c
       JOIN persons p ON p.id = c.person_id
      WHERE p.archived_at IS NULL
   ORDER BY p.full_name"
)->fetchAll();

function parsePattern(string $pattern): array {
    $out = ['days' => ['MON','TUE','WED','THU','FRI','SAT','SUN'], 'shift' => 'DAY', 'livein' => false];
    if ($pattern === '' || $pattern === 'MON-SUN') return $out;
    $parts = explode('|', $pattern);
    if (!empty($parts[0])) {
        $days = array_filter(array_map('trim', explode(',', $parts[0])));
        if ($days) $out['days'] = $days;
    }
    if (!empty($parts[1])) $out['shift'] = $parts[1];
    if (!empty($parts[2])) $out['livein'] = ($parts[2] === 'LIVEIN');
    return $out;
}

$tasks = onboardingTasks();
$task = $tasks['caregiver_patterns'] ?? null;

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

<h2 style="margin:0 0 0.5rem 0;">Caregiver working patterns</h2>
<p style="color:#6c757d;margin-bottom:1.25rem;">
    For each caregiver, tick the days they work, pick day / night / both, and mark whether they accept live-in placements. Defaults to Mon–Sun + Day shift if not set.
</p>

<?= renderOnboardingUploadWidget('caregiver_patterns',
    $task['upload_hint'] ?? '',
    null) ?>

<form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_patterns">
    <table class="report-table tch-data-table">
        <thead>
            <tr>
                <th>Caregiver</th>
                <th class="center">Mon</th>
                <th class="center">Tue</th>
                <th class="center">Wed</th>
                <th class="center">Thu</th>
                <th class="center">Fri</th>
                <th class="center">Sat</th>
                <th class="center">Sun</th>
                <th class="center">Shift</th>
                <th class="center">Live-in</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $p = parsePattern((string)($r['working_pattern'] ?? 'MON-SUN'));
            $pid = (int)$r['person_id'];
        ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($r['full_name']) ?></strong>
                <code style="color:#6c757d;font-size:0.7rem;"><?= htmlspecialchars($r['tch_id']) ?></code>
            </td>
            <?php foreach (['MON','TUE','WED','THU','FRI','SAT','SUN'] as $d): ?>
                <td class="center"><input type="checkbox" name="pattern[<?= $pid ?>][days][]" value="<?= $d ?>" <?= in_array($d, $p['days'], true) ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>></td>
            <?php endforeach; ?>
            <td class="center">
                <select name="pattern[<?= $pid ?>][shift]" class="form-control form-control-sm" style="font-size:0.8rem;" <?= $canEdit ? '' : 'disabled' ?>>
                    <option value="DAY"   <?= $p['shift'] === 'DAY'   ? 'selected' : '' ?>>Day</option>
                    <option value="NIGHT" <?= $p['shift'] === 'NIGHT' ? 'selected' : '' ?>>Night</option>
                    <option value="BOTH"  <?= $p['shift'] === 'BOTH'  ? 'selected' : '' ?>>Both</option>
                </select>
            </td>
            <td class="center"><input type="checkbox" name="pattern[<?= $pid ?>][livein]" value="1" <?= $p['livein'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($canEdit): ?>
    <div style="margin-top:1rem;text-align:right;">
        <button class="btn btn-primary" type="submit">Save all</button>
    </div>
    <?php endif; ?>
</form>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
