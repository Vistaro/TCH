<?php
/**
 * Task 5 subpage — 56-line timesheet reconciliation.
 * For each discrepancy row, Tuniti picks a resolution:
 *   - Accept as loan deduction (we'll track the loan)
 *   - Record as a bonus (we'll note it on the caregiver)
 *   - Confirm rate correction (we'll use the new rate)
 *   - Accept unexplained (close without action, notes required)
 *   - Flag for investigation (parks the row, returns later)
 *
 * Resolution writes the row status. Caregiver loan ledger wiring is
 * deferred to the caregiver loans build; for now we just persist the
 * decision + notes so the 56-item queue empties.
 */
$pageTitle = 'Timesheet reconciliation — pay discrepancies';
$activeNav = 'onboarding';

$db      = getDB();
$uid     = (int)($_SESSION['user_id'] ?? 0);
$canEdit = userCan('caregiver_view', 'edit');

$flash = ''; $flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } elseif (($_POST['action'] ?? '') === 'resolve') {
        $itemId  = (int)($_POST['item_id'] ?? 0);
        $status  = $_POST['resolution_status'] ?? '';
        $notes   = trim($_POST['resolution_notes'] ?? '');
        $allowed = ['accepted_loan','recorded_bonus','rate_corrected','accepted_unexplained','flagged','ignored'];
        if ($itemId && in_array($status, $allowed, true)) {
            $stmt = $db->prepare(
                "UPDATE timesheet_reconciliation_items
                    SET resolution_status = ?, resolution_notes = ?,
                        resolved_by_user_id = ?, resolved_at = NOW()
                  WHERE id = ?"
            );
            $stmt->execute([$status, $notes, $uid, $itemId]);
            logActivity('recon_resolved', 'onboarding', 'timesheet_reconciliation_items', $itemId,
                'Resolved reconciliation item → ' . $status, null,
                ['status' => $status, 'notes' => $notes]);
            $flash = 'Resolved. ' . (($status === 'flagged') ? 'Parked for investigation.' : '');
        } else {
            $flash = 'Invalid resolution.'; $flashType = 'error';
        }
    }
    header('Location: ' . APP_URL . '/admin/onboarding/reconciliation?msg=' . urlencode($flash) . '&type=' . urlencode($flashType));
    exit;
}

if ($flash === '' && isset($_GET['msg'])) {
    $flash = (string)$_GET['msg']; $flashType = (string)($_GET['type'] ?? 'success');
}

$filter = $_GET['status'] ?? 'pending';
$where  = '';
if     ($filter === 'pending')  $where = "WHERE resolution_status = 'pending'";
elseif ($filter === 'resolved') $where = "WHERE resolution_status != 'pending'";
else                              $where = '';

$rows = $db->query(
    "SELECT * FROM timesheet_reconciliation_items $where
   ORDER BY
     CASE WHEN resolution_status = 'pending' THEN 0 ELSE 1 END,
     ABS(diff_zar) DESC,
     tab_name, caregiver_name"
)->fetchAll();

$counts = $db->query(
    "SELECT resolution_status, COUNT(*) n FROM timesheet_reconciliation_items GROUP BY resolution_status"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$totalPending = (int)($counts['pending'] ?? 0);
$totalResolved = array_sum($counts) - $totalPending;

$patternColour = [
    'LOAN_DEDUCTED_FROM_TOTAL' => '#fd7e14',
    'BONUS_ADDED_TO_TOTAL'     => '#198754',
    'MISSING_RATE'             => '#dc3545',
    'UNEXPLAINED'              => '#6c757d',
];

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

<h2 style="margin:0 0 0.5rem 0;">Timesheet reconciliation — pay discrepancies</h2>
<p style="color:#6c757d;margin-bottom:1.25rem;">
    56 caregiver-month items from the Apr-26 Timesheet where the cell-by-cell total doesn't match the sheet's Caregiver Price row. Pick the reason for each one — the business implication drives how it's recorded going forward.
</p>

<div style="display:flex;gap:0.5rem;margin-bottom:1rem;flex-wrap:wrap;">
    <a href="?status=pending"  style="padding:0.4rem 0.9rem;border-radius:5px;text-decoration:none;background:<?= $filter === 'pending' ? '#0d6efd' : '#f8f9fa' ?>;color:<?= $filter === 'pending' ? '#fff' : '#495057' ?>;border:1px solid #dee2e6;font-size:0.85rem;">Pending (<?= number_format($totalPending) ?>)</a>
    <a href="?status=resolved" style="padding:0.4rem 0.9rem;border-radius:5px;text-decoration:none;background:<?= $filter === 'resolved' ? '#198754' : '#f8f9fa' ?>;color:<?= $filter === 'resolved' ? '#fff' : '#495057' ?>;border:1px solid #dee2e6;font-size:0.85rem;">Resolved (<?= number_format($totalResolved) ?>)</a>
    <a href="?status=all"      style="padding:0.4rem 0.9rem;border-radius:5px;text-decoration:none;background:<?= $filter === 'all' ? '#212529' : '#f8f9fa' ?>;color:<?= $filter === 'all' ? '#fff' : '#495057' ?>;border:1px solid #dee2e6;font-size:0.85rem;">All</a>
</div>

<?php foreach ($rows as $r):
    $pColour = $patternColour[$r['pattern']] ?? '#6c757d';
    $isPending = $r['resolution_status'] === 'pending';
?>
<div style="background:#fff;border:1px solid #dee2e6;border-left:4px solid <?= $pColour ?>;border-radius:8px;padding:1rem 1.25rem;margin-bottom:0.75rem;<?= $isPending ? '' : 'opacity:0.7;' ?>">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
        <div style="flex:1;min-width:250px;">
            <div style="font-weight:600;font-size:0.95rem;"><?= htmlspecialchars($r['caregiver_name']) ?></div>
            <div style="color:#6c757d;font-size:0.8rem;">
                <?= htmlspecialchars($r['tab_name']) ?> · column <?= htmlspecialchars($r['caregiver_col']) ?>
                &middot; <?= (int)$r['cells_n'] ?> cells × R<?= $r['rate'] === null ? '—' : number_format((float)$r['rate'], 0) ?>
            </div>
        </div>
        <div style="text-align:right;">
            <div>Computed: <strong>R<?= number_format((float)$r['computed_zar'], 0) ?></strong></div>
            <div>Sheet total: <strong>R<?= number_format((float)$r['sheet_total_zar'], 0) ?></strong></div>
            <div>Δ: <strong style="color:<?= $r['diff_zar'] > 0 ? '#198754' : '#dc3545' ?>">R<?= number_format((float)$r['diff_zar'], 0) ?></strong></div>
        </div>
        <div style="min-width:140px;">
            <span style="background:<?= $pColour ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">
                <?= htmlspecialchars(str_replace('_', ' ', $r['pattern'] ?? '')) ?>
            </span>
        </div>
    </div>
    <details style="margin-top:0.5rem;">
        <summary style="cursor:pointer;color:#0d6efd;font-size:0.85rem;">Suggested Tuniti query</summary>
        <div style="margin-top:0.4rem;padding:0.6rem;background:#f8f9fa;border-radius:4px;font-size:0.85rem;color:#495057;">
            <?= htmlspecialchars($r['suggested_query'] ?? '') ?>
        </div>
    </details>

    <?php if ($isPending && $canEdit): ?>
    <form method="POST" style="margin-top:0.75rem;display:flex;gap:0.5rem;align-items:flex-start;flex-wrap:wrap;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="resolve">
        <input type="hidden" name="item_id" value="<?= (int)$r['id'] ?>">
        <select name="resolution_status" class="form-control form-control-sm" required style="min-width:200px;">
            <option value="">Pick resolution…</option>
            <option value="accepted_loan">Accept as loan deduction</option>
            <option value="recorded_bonus">Record as bonus</option>
            <option value="rate_corrected">Confirm rate correction</option>
            <option value="accepted_unexplained">Accept unexplained — close</option>
            <option value="flagged">Flag for investigation</option>
            <option value="ignored">Ignore — not actionable</option>
        </select>
        <input type="text" name="resolution_notes" class="form-control form-control-sm" placeholder="Notes (required for 'unexplained' or 'flagged')" style="flex:1;min-width:250px;">
        <button class="btn btn-primary btn-sm" type="submit">Resolve</button>
    </form>
    <?php elseif (!$isPending): ?>
    <div style="margin-top:0.5rem;font-size:0.85rem;color:#495057;">
        <strong>Resolved:</strong> <?= htmlspecialchars(str_replace('_', ' ', $r['resolution_status'])) ?>
        <?php if (!empty($r['resolution_notes'])): ?>
            <em style="color:#6c757d;"> — <?= htmlspecialchars($r['resolution_notes']) ?></em>
        <?php endif; ?>
        <span style="color:#6c757d;"> · <?= htmlspecialchars($r['resolved_at']) ?></span>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php if (empty($rows)): ?>
    <p style="color:#6c757d;padding:2rem;text-align:center;">No items match this filter.</p>
<?php endif; ?>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
