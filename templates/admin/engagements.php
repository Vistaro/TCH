<?php
$pageTitle = 'Care Scheduling';
$activeNav = 'engagements';

$db = getDB();
$user = currentEffectiveUser();

$flash = '';
$flashType = 'error';
$patientLinkUrl = null;

// ── Handle create ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' && userCan('engagements', 'create')) {
        $cgId      = (int)($_POST['caregiver_id'] ?? 0);
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $productId = (int)($_POST['product_id'] ?? 0);
        $costRate  = (float)($_POST['cost_rate'] ?? 0);
        $billRate  = (float)($_POST['bill_rate'] ?? 0);
        $startDate = $_POST['start_date'] ?? '';
        $endDate   = $_POST['end_date'] ?? '';
        $notes     = trim($_POST['notes'] ?? '');

        if (!($cgId && $patientId && $productId && $costRate > 0 && $billRate > 0 && $startDate)) {
            $flash = 'Please fill in caregiver, patient, product, both rates, and a start date.';
        } else {
            // ── Bill-payer guardrail: patient MUST have a client linked ──
            // Rule: liability is confirmed at scheduling time, never later.
            // If the patient has no client on file, block the create and
            // point the user at the patient profile to fix it.
            $stmt = $db->prepare('SELECT client_id FROM patients WHERE person_id = ?');
            $stmt->execute([$patientId]);
            $clientId = (int)$stmt->fetchColumn();

            if ($clientId <= 0) {
                $flash = 'Cannot schedule care for this patient — no bill-paying client is linked. '
                       . 'Open the patient profile and set the client first.';
                $patientLinkUrl = APP_URL . '/admin/patients/' . $patientId . '?edit=client';
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO engagements (caregiver_person_id, patient_person_id, client_id, product_id,
                     cost_rate, bill_rate, start_date, end_date, notes, created_by_user_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$cgId, $patientId, $clientId, $productId,
                                $costRate, $billRate, $startDate, $endDate ?: null, $notes,
                                $user['id'] ?? null]);
                $engId = (int)$db->lastInsertId();
                logActivity('engagement_created', 'engagements', 'engagements', $engId,
                    "Created engagement #$engId", null,
                    ['caregiver' => $cgId, 'patient' => $patientId, 'client' => $clientId, 'product' => $productId,
                     'cost_rate' => $costRate, 'bill_rate' => $billRate, 'start' => $startDate]);
                header('Location: ' . APP_URL . '/admin/engagements');
                exit;
            }
        }
    } elseif ($action === 'update_status' && userCan('engagements', 'edit')) {
        $engId = (int)($_POST['engagement_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        if ($engId && in_array($newStatus, ['active','completed','cancelled'])) {
            $db->prepare('UPDATE engagements SET status = ? WHERE id = ?')->execute([$newStatus, $engId]);
            logActivity('engagement_status_changed', 'engagements', 'engagements', $engId,
                "Status changed to $newStatus", null, ['status' => $newStatus]);
        }
        header('Location: ' . APP_URL . '/admin/engagements');
        exit;
    }
    // Fall through to re-render the page with $flash set
}

// ── Data ─────────────────────────────────────────────────────
$engagements = $db->query(
    "SELECT e.*,
            p_cg.full_name AS caregiver_name, p_cg.tch_id AS cg_tch_id,
            p_pt.full_name AS patient_name, p_pt.tch_id AS pt_tch_id,
            c_person.full_name AS client_name,
            prod.name AS product_name
     FROM engagements e
     JOIN persons p_cg ON p_cg.id = e.caregiver_person_id
     JOIN persons p_pt ON p_pt.id = e.patient_person_id
     JOIN clients c ON c.id = e.client_id
     LEFT JOIN persons c_person ON c_person.id = c.person_id
     JOIN products prod ON prod.id = e.product_id
     ORDER BY e.status = 'active' DESC, e.start_date DESC"
)->fetchAll();

$caregiverOptions = $db->query(
    "SELECT cg.person_id, p.full_name, cg.day_rate FROM caregivers cg JOIN persons p ON p.id = cg.person_id ORDER BY p.full_name"
)->fetchAll();

$patientOptions = $db->query(
    "SELECT pt.person_id, p.full_name, pt.patient_name,
            c.default_day_rate AS client_default_rate
     FROM patients pt
     JOIN persons p  ON p.id = pt.person_id
     LEFT JOIN clients c ON c.id = pt.client_id
     ORDER BY p.full_name"
)->fetchAll();

$productOptions = $db->query("SELECT * FROM products WHERE is_active = 1 ORDER BY sort_order")->fetchAll();

require APP_ROOT . '/templates/layouts/admin.php';
?>

<?php $createFormOpen = !empty($flash); ?>

<?php if ($flash): ?>
    <div style="background:<?= $flashType === 'error' ? '#f8d7da' : '#cce5ff' ?>;color:<?= $flashType === 'error' ? '#721c24' : '#004085' ?>;padding:0.75rem 1rem;border-radius:4px;margin-bottom:1rem;border-left:4px solid <?= $flashType === 'error' ? '#dc3545' : '#0d6efd' ?>;">
        <?= htmlspecialchars($flash) ?>
        <?php if (!empty($patientLinkUrl)): ?>
            <div style="margin-top:0.5rem;">
                <a href="<?= htmlspecialchars($patientLinkUrl) ?>" class="btn btn-outline btn-sm">Link a client to this patient &rarr;</a>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <p style="color:#666;font-size:0.85rem;"><?= count($engagements) ?> care schedule<?= count($engagements) !== 1 ? 's' : '' ?></p>
    <?php if (userCan('engagements', 'create')): ?>
    <button class="btn btn-primary" onclick="document.getElementById('create-form').style.display='block'">+ New Care Schedule</button>
    <?php endif; ?>
</div>

<div id="create-form" style="display:<?= $createFormOpen ? 'block' : 'none' ?>;background:#f8f9fa;padding:1rem;border-radius:8px;margin-bottom:1.5rem;">
    <h3 style="margin-top:0;">Create Care Schedule</h3>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem;">
            <div>
                <label>Caregiver</label>
                <select name="caregiver_id" required class="form-control" id="eng-cg-select">
                    <option value="">Select...</option>
                    <?php foreach ($caregiverOptions as $o): ?>
                    <option value="<?= $o['person_id'] ?>" data-rate="<?= (float)$o['day_rate'] ?>"><?= htmlspecialchars($o['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Patient</label>
                <select name="patient_id" required class="form-control" id="eng-pt-select">
                    <option value="">Select...</option>
                    <?php foreach ($patientOptions as $o): ?>
                    <option value="<?= $o['person_id'] ?>" data-bill-rate="<?= $o['client_default_rate'] !== null ? (float)$o['client_default_rate'] : '' ?>"><?= htmlspecialchars($o['patient_name'] ?: $o['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Product</label>
                <select name="product_id" required class="form-control">
                    <?php foreach ($productOptions as $o): ?>
                    <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:0.75rem;margin-top:0.75rem;">
            <div><label>Cost Rate (R/day)</label><input type="number" step="0.01" name="cost_rate" id="eng-cost-rate" required class="form-control"></div>
            <div><label>Bill Rate (R/day)</label><input type="number" step="0.01" name="bill_rate" id="eng-bill-rate" required class="form-control"></div>
            <div><label>Start Date</label><input type="date" name="start_date" required class="form-control"></div>
            <div><label>End Date (optional)</label><input type="date" name="end_date" class="form-control"></div>
        </div>
        <div style="margin-top:0.75rem;"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
        <button type="submit" class="btn btn-primary" style="margin-top:0.75rem;">Create Care Schedule</button>
        <button type="button" class="btn" onclick="this.closest('#create-form').style.display='none'">Cancel</button>
    </form>
</div>

<script>
document.getElementById('eng-cg-select')?.addEventListener('change', function() {
    const rate = this.options[this.selectedIndex].dataset.rate;
    if (rate) document.getElementById('eng-cost-rate').value = rate;
});
document.getElementById('eng-pt-select')?.addEventListener('change', function() {
    const billRate = this.options[this.selectedIndex].dataset.billRate;
    if (billRate) document.getElementById('eng-bill-rate').value = billRate;
});
</script>

<?php if (empty($engagements)): ?>
<p style="color:#999;">No care schedules yet. Create one to assign a caregiver to a patient.</p>
<?php else: ?>
<table class="report-table tch-data-table">
    <thead><tr>
        <th>#</th><th>Caregiver</th><th>Patient</th><th>Client</th>
        <th>Product</th><th>Cost</th><th>Bill</th><th>Margin</th>
        <th>Start</th><th>End</th><th>Status</th>
        <?php if (userCan('engagements', 'edit')): ?><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($engagements as $e):
        $margin = (float)$e['bill_rate'] - (float)$e['cost_rate'];
    ?>
    <tr>
        <td><?= $e['id'] ?></td>
        <td><?= htmlspecialchars($e['caregiver_name']) ?></td>
        <td><?= htmlspecialchars($e['patient_name']) ?></td>
        <td><?= htmlspecialchars($e['client_name'] ?? '') ?></td>
        <td><?= htmlspecialchars($e['product_name']) ?></td>
        <td class="number">R<?= number_format((float)$e['cost_rate'], 0) ?></td>
        <td class="number">R<?= number_format((float)$e['bill_rate'], 0) ?></td>
        <td class="number" style="color:<?= $margin >= 0 ? '#198754' : '#dc3545' ?>">R<?= number_format($margin, 0) ?></td>
        <td><?= htmlspecialchars($e['start_date']) ?></td>
        <td><?= $e['end_date'] ? htmlspecialchars($e['end_date']) : 'Ongoing' ?></td>
        <td><span class="badge badge-<?= $e['status'] === 'active' ? 'success' : ($e['status'] === 'completed' ? 'info' : 'muted') ?>"><?= ucfirst($e['status']) ?></span></td>
        <?php if (userCan('engagements', 'edit')): ?>
        <td>
            <?php if ($e['status'] === 'active'): ?>
            <form method="POST" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="engagement_id" value="<?= $e['id'] ?>">
                <input type="hidden" name="new_status" value="completed">
                <button type="submit" class="btn btn-sm" onclick="return confirm('Mark this care schedule complete?')">Complete</button>
            </form>
            <?php endif; ?>
        </td>
        <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
