<?php
/**
 * Contract create / edit — /admin/contracts/new and /admin/contracts/{id}/edit
 *
 * Single form that handles both create and edit. Contract lines are
 * managed inline (JS-driven add/remove rows).
 */
$db = getDB();
$canEdit = userCan('contracts', 'edit');
$canCreate = userCan('contracts', 'create');

$contractId = (int)($_GET['contract_id'] ?? 0);
$isEdit = $contractId > 0;
$pageTitle = $isEdit ? 'Edit Contract' : 'New Contract';
$activeNav = 'contracts';

// ── Handle POST ────────────────────────────────────────────────────
$flash = ''; $flashType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($canCreate || $canEdit)) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } else {
        try {
            $db->beginTransaction();

            $clientId    = (int)$_POST['client_id'];
            $patientId   = (int)$_POST['patient_person_id'];
            $status      = $_POST['status'] ?? 'draft';
            $startDate   = $_POST['start_date'] ?: null;
            $endDate     = $_POST['end_date'] ?: null;
            $autoRenew   = !empty($_POST['auto_renew']) ? 1 : 0;
            $invNumber   = trim($_POST['invoice_number'] ?? '') ?: null;
            $invStatus   = $_POST['invoice_status'] ?? 'none';
            $invAmount   = $_POST['invoice_amount'] !== '' ? (float)$_POST['invoice_amount'] : null;
            $invDate     = $_POST['invoice_date'] ?: null;
            $notes       = trim($_POST['notes'] ?? '') ?: null;
            $uid         = (int)($_SESSION['user_id'] ?? 0) ?: null;

            if (!$clientId || !$patientId || !$startDate) {
                throw new RuntimeException('Client, patient and start date are required.');
            }

            if ($isEdit) {
                $stmt = $db->prepare(
                    "UPDATE contracts SET
                        client_id = ?, patient_person_id = ?, status = ?,
                        start_date = ?, end_date = ?, auto_renew = ?,
                        invoice_number = ?, invoice_status = ?, invoice_amount = ?, invoice_date = ?,
                        notes = ?
                     WHERE id = ?"
                );
                $stmt->execute([
                    $clientId, $patientId, $status,
                    $startDate, $endDate, $autoRenew,
                    $invNumber, $invStatus, $invAmount, $invDate,
                    $notes, $contractId,
                ]);
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO contracts
                        (client_id, patient_person_id, status,
                         start_date, end_date, auto_renew,
                         invoice_number, invoice_status, invoice_amount, invoice_date,
                         notes, created_by_user_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
                );
                $stmt->execute([
                    $clientId, $patientId, $status,
                    $startDate, $endDate, $autoRenew,
                    $invNumber, $invStatus, $invAmount, $invDate,
                    $notes, $uid,
                ]);
                $contractId = (int)$db->lastInsertId();
            }

            // Contract lines: wipe existing + re-insert from POST
            $db->prepare("DELETE FROM contract_lines WHERE contract_id = ?")->execute([$contractId]);
            $products     = $_POST['line_product_id']       ?? [];
            $freqs        = $_POST['line_billing_freq']     ?? [];
            $minTerms     = $_POST['line_min_term']         ?? [];
            $rates        = $_POST['line_bill_rate']        ?? [];
            $units        = $_POST['line_units_per_period'] ?? [];
            $lineNotes    = $_POST['line_notes']            ?? [];
            for ($i = 0; $i < count($products); $i++) {
                if (empty($products[$i])) continue;
                $db->prepare(
                    "INSERT INTO contract_lines
                        (contract_id, product_id, billing_freq, min_term_months,
                         bill_rate, units_per_period, notes)
                     VALUES (?,?,?,?,?,?,?)"
                )->execute([
                    $contractId, (int)$products[$i], $freqs[$i] ?? 'monthly',
                    (int)($minTerms[$i] ?? 0), (float)$rates[$i], (float)($units[$i] ?? 1.0),
                    trim($lineNotes[$i] ?? '') ?: null,
                ]);
            }

            logActivity(
                $isEdit ? 'contract_updated' : 'contract_created',
                'contracts', 'contracts', $contractId,
                ($isEdit ? 'Updated' : 'Created') . ' contract for patient_id=' . $patientId,
                null, ['status' => $status, 'start_date' => $startDate, 'end_date' => $endDate]
            );

            $db->commit();
            header('Location: ' . APP_URL . '/admin/contracts/' . $contractId . '?msg=' . urlencode($isEdit ? 'Contract updated.' : 'Contract created.'));
            exit;
        } catch (Throwable $e) {
            $db->rollBack();
            $flash = 'Error: ' . $e->getMessage(); $flashType = 'error';
        }
    }
}

// ── Load contract for edit ────────────────────────────────────────
$contract = null; $lines = [];
if ($isEdit) {
    $stmt = $db->prepare("SELECT * FROM contracts WHERE id = ?");
    $stmt->execute([$contractId]);
    $contract = $stmt->fetch();
    if (!$contract) { http_response_code(404); die('Contract not found.'); }
    $stmt = $db->prepare("SELECT * FROM contract_lines WHERE contract_id = ? ORDER BY id");
    $stmt->execute([$contractId]);
    $lines = $stmt->fetchAll();
}

// Dropdown data
$clients = $db->query(
    "SELECT c.id, COALESCE(p.full_name, CONCAT('Client #', c.id)) AS name, c.account_number, p.tch_id
     FROM clients c LEFT JOIN persons p ON p.id = c.person_id
     WHERE (p.archived_at IS NULL OR p.archived_at IS NOT NULL)
     ORDER BY name"
)->fetchAll();

$patients = $db->query(
    "SELECT pt.person_id, p.full_name AS name, p.tch_id,
            pt.client_id AS default_client_id
     FROM patients pt JOIN persons p ON p.id = pt.person_id
     WHERE p.archived_at IS NULL
     ORDER BY p.full_name"
)->fetchAll();

$products = $db->query(
    "SELECT id, code, name, default_price, default_billing_freq, default_min_term_months
     FROM products WHERE is_active = 1 ORDER BY sort_order"
)->fetchAll();

require APP_ROOT . '/templates/layouts/admin.php';
?>

<?php if ($flash): ?>
<div class="alert alert-<?= htmlspecialchars($flashType === 'error' ? 'error' : 'success') ?>" style="margin-bottom:1rem;">
    <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<form method="POST" style="max-width:1000px;">
    <?= csrfField() ?>

    <div style="background:#fff;padding:1rem;border:1px solid #dee2e6;border-radius:6px;margin-bottom:1rem;">
        <h3 style="margin-top:0;">Parties &amp; Term</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
            <div>
                <label>Patient <span style="color:#dc3545;">*</span></label>
                <select name="patient_person_id" id="patient_sel" required class="form-control">
                    <option value="">Pick a patient…</option>
                    <?php foreach ($patients as $pt): ?>
                        <option value="<?= (int)$pt['person_id'] ?>"
                                data-default-client="<?= (int)$pt['default_client_id'] ?>"
                                <?= ($contract['patient_person_id'] ?? 0) == $pt['person_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($pt['name']) ?> <?= $pt['tch_id'] ? '('.htmlspecialchars($pt['tch_id']).')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Bill-payer client <span style="color:#dc3545;">*</span></label>
                <select name="client_id" id="client_sel" required class="form-control">
                    <option value="">Pick a client…</option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= ($contract['client_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?>
                            <?= $c['account_number'] ? '('.htmlspecialchars($c['account_number']).')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="color:#6c757d;">Auto-fills to the patient's current bill-payer when a patient is picked.</small>
            </div>
            <div>
                <label>Start date <span style="color:#dc3545;">*</span></label>
                <input type="date" name="start_date" required class="form-control" value="<?= htmlspecialchars((string)($contract['start_date'] ?? date('Y-m-d'))) ?>">
            </div>
            <div>
                <label>End date</label>
                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars((string)($contract['end_date'] ?? '')) ?>">
                <small style="color:#6c757d;">Leave blank for "ongoing until actively cancelled".</small>
            </div>
            <div>
                <label>Status</label>
                <select name="status" class="form-control">
                    <?php foreach (['draft'=>'Draft','active'=>'Active','on_hold'=>'On hold','completed'=>'Completed','cancelled'=>'Cancelled'] as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= ($contract['status'] ?? 'draft') === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="padding-top:1.5rem;">
                <label style="font-weight:normal;">
                    <input type="checkbox" name="auto_renew" value="1" <?= !isset($contract['auto_renew']) || $contract['auto_renew'] ? 'checked' : '' ?>>
                    Auto-renew at each billing cycle (flag pre-renewal, never auto-cancel)
                </label>
            </div>
        </div>
    </div>

    <div style="background:#fff;padding:1rem;border:1px solid #dee2e6;border-radius:6px;margin-bottom:1rem;">
        <h3 style="margin-top:0;">Product Lines</h3>
        <p style="color:#6c757d;font-size:0.85rem;margin:0 0 0.5rem;">
            One line per product. Rate × units per period = what the client pays each billing cycle.
        </p>
        <table id="lines-table" style="width:100%;border-collapse:collapse;">
            <thead><tr style="background:#f8f9fa;">
                <th style="text-align:left;padding:0.4rem;">Product</th>
                <th style="text-align:left;padding:0.4rem;width:110px;">Billing Freq</th>
                <th style="text-align:right;padding:0.4rem;width:70px;">Min Term (mo)</th>
                <th style="text-align:right;padding:0.4rem;width:100px;">Bill Rate</th>
                <th style="text-align:right;padding:0.4rem;width:70px;">Units</th>
                <th style="text-align:left;padding:0.4rem;">Notes</th>
                <th style="width:40px;"></th>
            </tr></thead>
            <tbody id="lines-tbody">
            <?php
            $rowsToRender = !empty($lines) ? $lines : [null]; // at least one empty row on new
            foreach ($rowsToRender as $ln): ?>
                <tr class="line-row">
                    <td><select name="line_product_id[]" class="form-control form-control-sm product-sel">
                        <option value="">—</option>
                        <?php foreach ($products as $pr): ?>
                            <option value="<?= (int)$pr['id'] ?>"
                                    data-price="<?= (float)$pr['default_price'] ?>"
                                    data-freq="<?= htmlspecialchars($pr['default_billing_freq']) ?>"
                                    data-minterm="<?= (int)$pr['default_min_term_months'] ?>"
                                    <?= ($ln['product_id'] ?? 0) == $pr['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pr['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select></td>
                    <td><select name="line_billing_freq[]" class="form-control form-control-sm">
                        <?php foreach (['monthly','weekly','daily','per_visit','upfront_only'] as $f): ?>
                            <option value="<?= $f ?>" <?= ($ln['billing_freq'] ?? 'monthly') === $f ? 'selected' : '' ?>><?= htmlspecialchars($f) ?></option>
                        <?php endforeach; ?>
                    </select></td>
                    <td><input type="number" min="0" name="line_min_term[]" value="<?= (int)($ln['min_term_months'] ?? 0) ?>" class="form-control form-control-sm" style="text-align:right;"></td>
                    <td><input type="number" step="0.01" min="0" name="line_bill_rate[]" value="<?= htmlspecialchars((string)($ln['bill_rate'] ?? '')) ?>" class="form-control form-control-sm" style="text-align:right;"></td>
                    <td><input type="number" step="0.01" min="0" name="line_units_per_period[]" value="<?= htmlspecialchars((string)($ln['units_per_period'] ?? '1')) ?>" class="form-control form-control-sm" style="text-align:right;"></td>
                    <td><input type="text" name="line_notes[]" value="<?= htmlspecialchars((string)($ln['notes'] ?? '')) ?>" class="form-control form-control-sm"></td>
                    <td><button type="button" class="btn btn-sm btn-outline" onclick="this.closest('tr').remove()">×</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button type="button" class="btn btn-sm btn-outline" style="margin-top:0.4rem;" onclick="addLineRow()">+ Add line</button>
    </div>

    <div style="background:#fff;padding:1rem;border:1px solid #dee2e6;border-radius:6px;margin-bottom:1rem;">
        <h3 style="margin-top:0;">Invoice (manual for now)</h3>
        <p style="color:#6c757d;font-size:0.85rem;margin:0 0 0.6rem;">
            Invoice is always raised <strong>upfront</strong> (pay in advance). Log the invoice number / amount / date from whatever billing tool the business team uses today. Xero API integration lands later.
        </p>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:0.75rem;">
            <div>
                <label>Invoice number</label>
                <input type="text" name="invoice_number" value="<?= htmlspecialchars((string)($contract['invoice_number'] ?? '')) ?>" class="form-control">
            </div>
            <div>
                <label>Status</label>
                <select name="invoice_status" class="form-control">
                    <?php foreach (['none'=>'Not raised','raised'=>'Raised','sent'=>'Sent','paid'=>'Paid','overdue'=>'Overdue','disputed'=>'Disputed'] as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= ($contract['invoice_status'] ?? 'none') === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Amount (R)</label>
                <input type="number" step="0.01" min="0" name="invoice_amount" value="<?= htmlspecialchars((string)($contract['invoice_amount'] ?? '')) ?>" class="form-control">
            </div>
            <div>
                <label>Date</label>
                <input type="date" name="invoice_date" value="<?= htmlspecialchars((string)($contract['invoice_date'] ?? '')) ?>" class="form-control">
            </div>
        </div>
    </div>

    <div style="background:#fff;padding:1rem;border:1px solid #dee2e6;border-radius:6px;margin-bottom:1rem;">
        <label>Notes</label>
        <textarea name="notes" class="form-control" rows="3" placeholder="Anything else worth capturing — special conditions, quote reference, etc."><?= htmlspecialchars((string)($contract['notes'] ?? '')) ?></textarea>
    </div>

    <div style="display:flex;gap:0.5rem;">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create contract' ?></button>
        <a href="<?= APP_URL ?>/admin/contracts<?= $isEdit ? '/' . $contractId : '' ?>" class="btn btn-outline">Cancel</a>
    </div>
</form>

<script>
    // Prefill client dropdown from patient's patients.client_id
    document.getElementById('patient_sel')?.addEventListener('change', function () {
        const dflt = this.options[this.selectedIndex]?.dataset?.defaultClient;
        if (dflt && dflt !== '0') {
            const cs = document.getElementById('client_sel');
            if (cs && !cs.value) cs.value = dflt;
        }
    });
    // Prefill bill_rate + freq + min_term on product pick
    document.getElementById('lines-tbody')?.addEventListener('change', function (e) {
        if (!e.target.classList.contains('product-sel')) return;
        const opt = e.target.options[e.target.selectedIndex];
        const row = e.target.closest('tr');
        const freq = opt.dataset.freq || '';
        const price = parseFloat(opt.dataset.price || 0);
        const minterm = parseInt(opt.dataset.minterm || 0, 10);
        if (freq) row.querySelector('select[name="line_billing_freq[]"]').value = freq;
        if (price) row.querySelector('input[name="line_bill_rate[]"]').value = price.toFixed(2);
        row.querySelector('input[name="line_min_term[]"]').value = minterm;
    });
    function addLineRow() {
        const tbody = document.getElementById('lines-tbody');
        const firstRow = tbody.querySelector('tr.line-row');
        const clone = firstRow.cloneNode(true);
        clone.querySelectorAll('input').forEach(i => { if (i.type !== 'number' || i.name === 'line_min_term[]') i.value = i.name === 'line_units_per_period[]' ? '1' : ''; if (i.name === 'line_min_term[]') i.value = 0; });
        clone.querySelector('select[name="line_product_id[]"]').selectedIndex = 0;
        tbody.appendChild(clone);
    }
</script>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
