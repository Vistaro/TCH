<?php
$pageTitle = 'Roster Input';
$activeNav = 'roster-input';

$db = getDB();
$user = currentEffectiveUser();

// ── Handle new shift ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_shift' && userCan('roster_input', 'create')) {
        $engagementId = (int)($_POST['engagement_id'] ?? 0);
        $rosterDate   = $_POST['roster_date'] ?? '';
        $status       = $_POST['status'] ?? 'delivered';

        if ($engagementId && $rosterDate) {
            // Pull engagement details
            $eng = $db->prepare('SELECT * FROM engagements WHERE id = ?');
            $eng->execute([$engagementId]);
            $engagement = $eng->fetch(PDO::FETCH_ASSOC);

            if ($engagement) {
                $cgName = $db->query("SELECT full_name FROM persons WHERE id = " . (int)$engagement['caregiver_person_id'])->fetchColumn();
                $ptName = $db->query("SELECT full_name FROM persons WHERE id = " . (int)$engagement['patient_person_id'])->fetchColumn();
                $dayOfWeek = date('l', strtotime($rosterDate));

                $stmt = $db->prepare(
                    'INSERT INTO daily_roster (caregiver_id, client_id, roster_date, day_of_week,
                     caregiver_name, client_assigned, daily_rate, cost_rate, bill_rate,
                     engagement_id, product_id, status, source_sheet, source_ref,
                     created_by_user_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $engagement['caregiver_person_id'],
                    $engagement['patient_person_id'],
                    $rosterDate, $dayOfWeek,
                    $cgName, $ptName,
                    $engagement['cost_rate'],
                    $engagement['cost_rate'],
                    $engagement['bill_rate'],
                    $engagementId,
                    $engagement['product_id'],
                    $status,
                    'Manual entry',
                    'Admin roster input',
                    $user['id'] ?? null
                ]);

                logActivity('shift_created', 'roster_input', 'daily_roster',
                    (int)$db->lastInsertId(),
                    "Shift: $cgName at $ptName on $rosterDate (eng #$engagementId)",
                    null,
                    ['date' => $rosterDate, 'caregiver' => $cgName, 'patient' => $ptName,
                     'cost' => $engagement['cost_rate'], 'bill' => $engagement['bill_rate']]);
            }
        }
    } elseif ($action === 'update_status' && userCan('roster_input', 'edit')) {
        $shiftId = (int)($_POST['shift_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        if ($shiftId && in_array($newStatus, ['planned','delivered','cancelled','disputed'])) {
            $db->prepare('UPDATE daily_roster SET status = ? WHERE id = ?')->execute([$newStatus, $shiftId]);
            logActivity('shift_status_changed', 'roster_input', 'daily_roster', $shiftId,
                "Status changed to $newStatus", null, ['status' => $newStatus]);
        }
    }
    header('Location: ' . APP_URL . '/admin/roster/input');
    exit;
}

// ── Data ─────────────────────────────────────────────────────
$activeEngagements = $db->query(
    "SELECT e.id, p_cg.full_name AS cg_name, p_pt.full_name AS pt_name,
            prod.name AS product_name, e.cost_rate, e.bill_rate
     FROM engagements e
     JOIN persons p_cg ON p_cg.id = e.caregiver_person_id
     JOIN persons p_pt ON p_pt.id = e.patient_person_id
     JOIN products prod ON prod.id = e.product_id
     WHERE e.status = 'active'
     ORDER BY p_cg.full_name"
)->fetchAll();

// Recent shifts (last 30 days)
$recentShifts = $db->query(
    "SELECT dr.id, dr.roster_date, dr.day_of_week, dr.status,
            dr.cost_rate, dr.bill_rate,
            p_cg.full_name AS cg_name, p_pt.full_name AS pt_name,
            prod.name AS product_name, e.id AS eng_id
     FROM daily_roster dr
     LEFT JOIN persons p_cg ON p_cg.id = dr.caregiver_id
     LEFT JOIN persons p_pt ON p_pt.id = dr.client_id
     LEFT JOIN products prod ON prod.id = dr.product_id
     LEFT JOIN engagements e ON e.id = dr.engagement_id
     WHERE dr.roster_date >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)
     ORDER BY dr.roster_date DESC, p_cg.full_name
     LIMIT 100"
)->fetchAll();

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <p style="color:#666;font-size:0.85rem;">Recent 30 days: <?= count($recentShifts) ?> shift<?= count($recentShifts) !== 1 ? 's' : '' ?></p>
    <?php if (userCan('roster_input', 'create')): ?>
    <button class="btn btn-primary" onclick="document.getElementById('create-form').style.display='block'">+ Record Shift</button>
    <?php endif; ?>
</div>

<?php if (empty($activeEngagements)): ?>
<div style="background:#fff3cd;padding:1rem;border-radius:8px;margin-bottom:1rem;">
    No active engagements. <a href="<?= APP_URL ?>/admin/engagements">Create one first</a> to assign a caregiver to a patient.
</div>
<?php else: ?>
<div id="create-form" style="display:none;background:#f8f9fa;padding:1rem;border-radius:8px;margin-bottom:1.5rem;">
    <h3 style="margin-top:0;">Record Shift</h3>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_shift">
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:0.75rem;align-items:end;">
            <div>
                <label>Engagement</label>
                <select name="engagement_id" required class="form-control">
                    <option value="">Select...</option>
                    <?php foreach ($activeEngagements as $e): ?>
                    <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['cg_name']) ?> → <?= htmlspecialchars($e['pt_name']) ?> (<?= htmlspecialchars($e['product_name']) ?>, R<?= number_format((float)$e['cost_rate'],0) ?>/R<?= number_format((float)$e['bill_rate'],0) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>Date</label><input type="date" name="roster_date" required class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div>
                <label>Status</label>
                <select name="status" class="form-control">
                    <option value="delivered">Delivered</option>
                    <option value="planned">Planned</option>
                </select>
            </div>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:0.75rem;">Record</button>
        <button type="button" class="btn" onclick="this.closest('#create-form').style.display='none'">Cancel</button>
    </form>
</div>
<?php endif; ?>

<table class="report-table tch-data-table">
    <thead><tr>
        <th>Date</th><th>Day</th><th>Caregiver</th><th>Patient</th>
        <th>Product</th><th>Cost</th><th>Bill</th><th>Status</th>
        <th>Eng#</th>
        <?php if (userCan('roster_input', 'edit')): ?><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($recentShifts as $s): ?>
    <tr>
        <td><?= htmlspecialchars($s['roster_date']) ?></td>
        <td><?= htmlspecialchars(substr($s['day_of_week'] ?? '',0,3)) ?></td>
        <td><?= htmlspecialchars($s['cg_name'] ?? '') ?></td>
        <td><?= htmlspecialchars($s['pt_name'] ?? '') ?></td>
        <td><?= htmlspecialchars($s['product_name'] ?? 'Day Rate') ?></td>
        <td class="number"><?= $s['cost_rate'] ? 'R'.number_format((float)$s['cost_rate'],0) : '—' ?></td>
        <td class="number"><?= $s['bill_rate'] ? 'R'.number_format((float)$s['bill_rate'],0) : '—' ?></td>
        <td><span class="badge badge-<?= $s['status']==='delivered'?'success':($s['status']==='planned'?'warning':($s['status']==='cancelled'?'muted':'danger')) ?>"><?= ucfirst($s['status']) ?></span></td>
        <td><?= $s['eng_id'] ? '#'.$s['eng_id'] : '—' ?></td>
        <?php if (userCan('roster_input', 'edit') && $s['status'] !== 'cancelled'): ?>
        <td>
            <form method="POST" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="shift_id" value="<?= $s['id'] ?>">
                <input type="hidden" name="new_status" value="cancelled">
                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Cancel this shift?')">Cancel</button>
            </form>
        </td>
        <?php else: ?><td></td><?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
