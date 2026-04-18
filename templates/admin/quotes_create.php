<?php
/**
 * Quote builder — /admin/quotes/new and /admin/quotes/{id}/edit
 *
 * A quote IS a draft-status contract (single source of truth — contracts
 * table). This page is quote-focused:
 *   - Only draft / sent / accepted / rejected / expired statuses show up.
 *   - Line-items use product_billing_rates for unit + rate prefill.
 *   - FR-B per-line dates are exposed.
 *   - FR-E rate-override: rate input locked to product default unless the
 *     user has the `quotes_rate_override.edit` permission.
 *   - Running total computed live client-side; server recomputes on save.
 *   - Pre-fills from an opportunity via ?opportunity_id=N — stamps both
 *     sides of the opp↔contract link on first save.
 *
 * Transitions beyond draft (send, accept, reject) live on the detail
 * page as separate POST actions — this page is for editing content only.
 */
require_once APP_ROOT . '/includes/opportunities.php';

$db = getDB();
$canEdit   = userCan('quotes', 'edit');
$canCreate = userCan('quotes', 'create');
$canOverrideRate = userCan('quotes_rate_override', 'edit');

$quoteId = (int)($_GET['contract_id'] ?? 0);
$isEdit  = $quoteId > 0;

$pageTitle = $isEdit ? 'Edit Quote' : 'New Quote';
$activeNav = 'quotes';

$flash = '';
$flashType = 'success';

// ── Pre-fill from opportunity on create ─────────────────────────────
$preOppId = (!$isEdit && $_SERVER['REQUEST_METHOD'] !== 'POST')
    ? (int)($_GET['opportunity_id'] ?? 0)
    : (int)($_POST['opportunity_id'] ?? 0);
$preOpp = null;
if ($preOppId > 0) {
    $preOpp = fetchOpportunity($db, $preOppId);
    if ($preOpp && !$isEdit && !empty($preOpp['contract_id'])) {
        // Already has a quote — redirect to that one
        header('Location: ' . APP_URL . '/admin/quotes/' . (int)$preOpp['contract_id'] . '/edit');
        exit;
    }
}

// ── Handle POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($canCreate || $canEdit)) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } else {
        try {
            $db->beginTransaction();

            $clientId     = (int)$_POST['client_id'];
            $patientId    = (int)$_POST['patient_person_id'];
            $oppId        = $preOppId ?: (int)($_POST['opportunity_id'] ?? 0);
            $status       = in_array($_POST['status'] ?? 'draft', ['draft','sent','accepted','rejected','expired'], true)
                            ? $_POST['status'] : 'draft';
            $startDate    = $_POST['start_date'] ?: null;
            $endDate      = $_POST['end_date']   ?: null;
            $autoRenew    = !empty($_POST['auto_renew']) ? 1 : 0;
            $notes        = trim($_POST['notes'] ?? '') ?: null;
            $uid          = (int)($_SESSION['user_id'] ?? 0) ?: null;

            if (!$clientId || !$patientId || !$startDate) {
                throw new RuntimeException('Client, patient and start date are required.');
            }

            // Generate quote_reference on first save if missing
            $quoteRef = null;
            if ($isEdit) {
                $row = $db->prepare("SELECT quote_reference FROM contracts WHERE id = ?");
                $row->execute([$quoteId]);
                $quoteRef = $row->fetchColumn() ?: null;
            }
            if (!$quoteRef) {
                $year = (int)date('Y');
                $prefix = sprintf('Q-%04d-', $year);
                $stmt = $db->prepare(
                    "SELECT quote_reference FROM contracts
                      WHERE quote_reference LIKE ?
                      ORDER BY id DESC LIMIT 1"
                );
                $stmt->execute([$prefix . '%']);
                $last = $stmt->fetchColumn();
                $next = 1;
                if ($last && preg_match('/-(\d+)$/', (string)$last, $m)) {
                    $next = (int)$m[1] + 1;
                }
                $quoteRef = sprintf('%s%04d', $prefix, $next);
            }

            if ($isEdit) {
                $stmt = $db->prepare(
                    "UPDATE contracts SET
                        client_id = ?, patient_person_id = ?,
                        opportunity_id = ?, status = ?,
                        start_date = ?, end_date = ?, auto_renew = ?,
                        quote_reference = ?, notes = ?
                     WHERE id = ?"
                );
                $stmt->execute([
                    $clientId, $patientId,
                    $oppId ?: null, $status,
                    $startDate, $endDate, $autoRenew,
                    $quoteRef, $notes, $quoteId,
                ]);
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO contracts
                        (client_id, patient_person_id, opportunity_id, status,
                         start_date, end_date, auto_renew,
                         quote_reference, notes, created_by_user_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?)"
                );
                $stmt->execute([
                    $clientId, $patientId, $oppId ?: null, $status,
                    $startDate, $endDate, $autoRenew,
                    $quoteRef, $notes, $uid,
                ]);
                $quoteId = (int)$db->lastInsertId();

                // Stamp the opportunity's contract_id back-pointer
                if ($oppId > 0) {
                    $db->prepare(
                        "UPDATE opportunities SET contract_id = ? WHERE id = ? AND contract_id IS NULL"
                    )->execute([$quoteId, $oppId]);
                }
            }

            // Wipe + re-insert contract lines
            $db->prepare("DELETE FROM contract_lines WHERE contract_id = ?")->execute([$quoteId]);

            $products      = $_POST['line_product_id']       ?? [];
            $freqs         = $_POST['line_billing_freq']     ?? [];
            $minTerms      = $_POST['line_min_term']         ?? [];
            $rates         = $_POST['line_bill_rate']        ?? [];
            $units         = $_POST['line_units_per_period'] ?? [];
            $startDates    = $_POST['line_start_date']       ?? [];
            $endDates      = $_POST['line_end_date']         ?? [];
            $lineNotes     = $_POST['line_notes']            ?? [];
            $overrideReasons = $_POST['line_override_reason'] ?? [];
            $defaultRates  = $_POST['line_default_rate']     ?? []; // hidden — what the default was at render time

            $overrideCount = 0;
            for ($i = 0; $i < count($products); $i++) {
                if (empty($products[$i])) continue;
                $rate = (float)$rates[$i];
                $defaultRate = isset($defaultRates[$i]) ? (float)$defaultRates[$i] : null;
                $isOverride = $defaultRate !== null && abs($rate - $defaultRate) > 0.001;

                if ($isOverride) {
                    if (!$canOverrideRate) {
                        throw new RuntimeException(
                            'Rate differs from product default on line ' . ($i + 1)
                            . ' but you do not have the rate-override permission.'
                        );
                    }
                    $reason = trim($overrideReasons[$i] ?? '');
                    if ($reason === '') {
                        throw new RuntimeException(
                            'Line ' . ($i + 1) . ' uses a rate different from the product default — a reason is required.'
                        );
                    }
                    $overrideCount++;
                } else {
                    $overrideReasons[$i] = null; // clear any stray value
                }

                $db->prepare(
                    "INSERT INTO contract_lines
                        (contract_id, product_id, billing_freq, min_term_months,
                         bill_rate, units_per_period,
                         start_date, end_date,
                         notes, rate_override_reason)
                     VALUES (?,?,?,?,?,?,?,?,?,?)"
                )->execute([
                    $quoteId, (int)$products[$i], $freqs[$i] ?? 'monthly',
                    (int)($minTerms[$i] ?? 0),
                    $rate, (float)($units[$i] ?? 1.0),
                    $startDates[$i] ?: null, $endDates[$i] ?: null,
                    trim($lineNotes[$i] ?? '') ?: null,
                    $isOverride ? trim($overrideReasons[$i]) : null,
                ]);
            }

            logActivity(
                $isEdit ? 'quote_updated' : 'quote_created',
                'quotes', 'contracts', $quoteId,
                ($isEdit ? 'Updated' : 'Created') . ' quote ' . $quoteRef
                    . ($overrideCount > 0 ? ' (' . $overrideCount . ' rate override' . ($overrideCount === 1 ? '' : 's') . ')' : ''),
                null,
                ['quote_reference' => $quoteRef, 'status' => $status,
                 'opportunity_id' => $oppId ?: null,
                 'start_date' => $startDate, 'end_date' => $endDate,
                 'overrides' => $overrideCount]
            );

            $db->commit();
            header('Location: ' . APP_URL . '/admin/quotes/' . $quoteId . '?msg=' . urlencode($isEdit ? 'Quote updated.' : 'Quote created: ' . $quoteRef));
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $flash = 'Error: ' . $e->getMessage();
            $flashType = 'error';
        }
    }
}

// ── Load existing ──────────────────────────────────────────────────
$quote = null; $lines = [];
if ($isEdit) {
    $stmt = $db->prepare("SELECT * FROM contracts WHERE id = ?");
    $stmt->execute([$quoteId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quote) { http_response_code(404); die('Quote not found.'); }
    if (!in_array($quote['status'], ['draft','sent','accepted','rejected','expired'], true)) {
        // Not a quote — bounce to the contract edit page
        header('Location: ' . APP_URL . '/admin/contracts/' . $quoteId . '/edit');
        exit;
    }

    $stmt = $db->prepare("SELECT * FROM contract_lines WHERE contract_id = ? ORDER BY id");
    $stmt->execute([$quoteId]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($quote['opportunity_id']) && !$preOpp) {
        $preOpp = fetchOpportunity($db, (int)$quote['opportunity_id']);
    }
}

// ── Picker data ────────────────────────────────────────────────────
$clients = $db->query(
    "SELECT c.id, COALESCE(p.full_name, CONCAT('Client #', c.id)) AS name,
            c.account_number, p.tch_id
       FROM clients c
  LEFT JOIN persons p ON p.id = c.person_id
   ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);

$patients = $db->query(
    "SELECT pt.person_id, p.full_name AS name, p.tch_id, pt.client_id AS default_client_id
       FROM patients pt
       JOIN persons p ON p.id = pt.person_id
      WHERE p.archived_at IS NULL
   ORDER BY p.full_name"
)->fetchAll(PDO::FETCH_ASSOC);

$products = $db->query(
    "SELECT p.id, p.code, p.name, p.default_min_term_months
       FROM products p
      WHERE p.is_active = 1
   ORDER BY p.sort_order, p.name"
)->fetchAll(PDO::FETCH_ASSOC);

// All product_billing_rates rows, grouped by product — drives the unit
// dropdown narrowing and rate prefill on the client side.
$pbrRows = $db->query(
    "SELECT product_id, billing_freq, rate, is_default, is_active
       FROM product_billing_rates
      WHERE is_active = 1"
)->fetchAll(PDO::FETCH_ASSOC);
$ratesByProduct = [];
foreach ($pbrRows as $rr) {
    $ratesByProduct[(int)$rr['product_id']][$rr['billing_freq']] = [
        'rate' => (float)$rr['rate'],
        'is_default' => (int)$rr['is_default'],
    ];
}

// Initial quote values — used by the form
$initStart = $quote['start_date']
    ?? ($preOpp['expected_start_date'] ?? date('Y-m-d'));

require APP_ROOT . '/templates/layouts/admin.php';
?>

<?php if ($flash): ?>
<div style="padding:0.8rem 1rem;margin-bottom:1rem;border-radius:4px;background:<?= $flashType === 'error' ? '#f8d7da' : '#d1e7dd' ?>;color:<?= $flashType === 'error' ? '#842029' : '#0f5132' ?>;">
    <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<?php if ($preOpp && !$isEdit): ?>
<div style="padding:0.8rem 1rem;margin-bottom:1rem;border-radius:4px;background:#cff4fc;color:#055160;border:1px solid #b6effb;">
    Building quote for opportunity
    <a href="<?= APP_URL ?>/admin/opportunities/<?= (int)$preOpp['id'] ?>"><strong><?= htmlspecialchars($preOpp['opp_ref']) ?></strong></a>
    — <?= htmlspecialchars($preOpp['title']) ?>.
    Client + patient are pre-filled from the opportunity.
</div>
<?php endif; ?>

<form method="POST" style="max-width:1050px;">
    <?= csrfField() ?>
    <?php if ($preOppId): ?>
        <input type="hidden" name="opportunity_id" value="<?= (int)$preOppId ?>">
    <?php endif; ?>

    <div style="background:#fff;padding:1rem;border:1px solid #dee2e6;border-radius:6px;margin-bottom:1rem;">
        <h3 style="margin-top:0;">Parties &amp; Term</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
            <div>
                <label>Patient <span style="color:#dc3545;">*</span></label>
                <select name="patient_person_id" id="patient_sel" required class="form-control">
                    <option value="">Pick a patient…</option>
                    <?php
                    $selectedPatient = $quote['patient_person_id']
                        ?? $preOpp['patient_person_id']
                        ?? 0;
                    foreach ($patients as $pt): ?>
                        <option value="<?= (int)$pt['person_id'] ?>"
                                data-default-client="<?= (int)$pt['default_client_id'] ?>"
                                <?= (int)$selectedPatient === (int)$pt['person_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($pt['name']) ?> <?= $pt['tch_id'] ? '('.htmlspecialchars($pt['tch_id']).')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Bill-payer client <span style="color:#dc3545;">*</span></label>
                <select name="client_id" id="client_sel" required class="form-control">
                    <option value="">Pick a client…</option>
                    <?php
                    $selectedClient = $quote['client_id'] ?? $preOpp['client_id'] ?? 0;
                    foreach ($clients as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (int)$selectedClient === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?>
                            <?= $c['account_number'] ? '('.htmlspecialchars($c['account_number']).')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="color:#6c757d;">Auto-fills to the patient's current bill-payer when you pick a patient.</small>
            </div>
            <div>
                <label>Start date <span style="color:#dc3545;">*</span></label>
                <input type="date" name="start_date" required class="form-control" value="<?= htmlspecialchars((string)$initStart) ?>">
            </div>
            <div>
                <label>End date</label>
                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars((string)($quote['end_date'] ?? '')) ?>">
                <small style="color:#6c757d;">Blank = ongoing until cancelled.</small>
            </div>
            <div>
                <label>Status</label>
                <select name="status" class="form-control">
                    <?php foreach (['draft'=>'Draft','sent'=>'Sent','accepted'=>'Accepted','rejected'=>'Rejected','expired'=>'Expired'] as $k=>$v):
                        $sel = ($quote['status'] ?? 'draft') === $k;
                    ?>
                        <option value="<?= $k ?>" <?= $sel ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="color:#6c757d;">Keep as Draft while building. Move to Sent once emailed/given to client.</small>
            </div>
            <div style="padding-top:1.5rem;">
                <label style="font-weight:normal;">
                    <input type="checkbox" name="auto_renew" value="1" <?= !isset($quote['auto_renew']) || $quote['auto_renew'] ? 'checked' : '' ?>>
                    Auto-renew at each billing cycle
                </label>
            </div>
        </div>
    </div>

    <div style="background:#fff;padding:1rem;border:1px solid #dee2e6;border-radius:6px;margin-bottom:1rem;">
        <h3 style="margin-top:0;">Line items</h3>
        <p style="color:#6c757d;font-size:0.85rem;margin:0 0 0.5rem;">
            One line per product the client is buying. Pick a product first — the billing-unit dropdown + default rate then narrow to what that product supports.
            <?php if (!$canOverrideRate): ?>
                <br><em>Rate fields are locked to the product default for your role. Ask Ross for the rate-override permission to change them.</em>
            <?php endif; ?>
        </p>

        <table id="lines-table" style="width:100%;border-collapse:collapse;font-size:0.88rem;">
            <thead><tr style="background:#f8f9fa;">
                <th style="text-align:left;padding:0.4rem;">Product</th>
                <th style="text-align:left;padding:0.4rem;width:100px;">Unit</th>
                <th style="text-align:right;padding:0.4rem;width:90px;">Rate</th>
                <th style="text-align:right;padding:0.4rem;width:60px;">Qty</th>
                <th style="text-align:right;padding:0.4rem;width:80px;">Line total</th>
                <th style="text-align:left;padding:0.4rem;width:130px;">Start</th>
                <th style="text-align:left;padding:0.4rem;width:130px;">End</th>
                <th style="text-align:left;padding:0.4rem;">Notes</th>
                <th style="width:40px;"></th>
            </tr></thead>
            <tbody id="lines-tbody">
            <?php
            $rowsToRender = !empty($lines) ? $lines : [null];
            foreach ($rowsToRender as $ln):
                $ln = $ln ?: [
                    'product_id' => 0, 'billing_freq' => 'monthly', 'bill_rate' => 0.00,
                    'units_per_period' => 1.0, 'min_term_months' => 0,
                    'start_date' => $initStart, 'end_date' => null,
                    'notes' => null, 'rate_override_reason' => null,
                ];
                $defaultForLine = $ratesByProduct[(int)$ln['product_id']][$ln['billing_freq']]['rate'] ?? null;
                $isOverride = $defaultForLine !== null && abs((float)$ln['bill_rate'] - $defaultForLine) > 0.001;
            ?>
                <tr class="line-row">
                    <td>
                        <select name="line_product_id[]" class="form-control form-control-sm product-sel" onchange="TCH_onProductChange(this)">
                            <option value="">—</option>
                            <?php foreach ($products as $pr): ?>
                                <option value="<?= (int)$pr['id'] ?>" data-minterm="<?= (int)$pr['default_min_term_months'] ?>"
                                        <?= (int)$ln['product_id'] === (int)$pr['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($pr['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="line_billing_freq[]" class="form-control form-control-sm freq-sel" onchange="TCH_onUnitChange(this)">
                            <!-- Options populated by JS based on product + PBR rows -->
                            <option value="<?= htmlspecialchars($ln['billing_freq']) ?>"><?= htmlspecialchars($ln['billing_freq']) ?></option>
                        </select>
                    </td>
                    <td>
                        <input type="number" step="0.01" min="0" name="line_bill_rate[]"
                               value="<?= htmlspecialchars((string)$ln['bill_rate']) ?>"
                               class="form-control form-control-sm rate-input"
                               style="text-align:right;<?= $isOverride ? 'border-color:#fd7e14;' : '' ?>"
                               <?= $canOverrideRate ? '' : 'readonly' ?>
                               onchange="TCH_onRateChange(this)">
                        <input type="hidden" name="line_default_rate[]" class="default-rate" value="<?= htmlspecialchars((string)($defaultForLine ?? '')) ?>">
                    </td>
                    <td>
                        <input type="number" step="0.01" min="0" name="line_units_per_period[]"
                               value="<?= htmlspecialchars((string)$ln['units_per_period']) ?>"
                               class="form-control form-control-sm units-input"
                               style="text-align:right;" onchange="TCH_updateTotals()">
                    </td>
                    <td class="line-total" style="text-align:right;font-weight:600;">R0</td>
                    <td><input type="date" name="line_start_date[]" class="form-control form-control-sm"
                               value="<?= htmlspecialchars((string)($ln['start_date'] ?? '')) ?>"></td>
                    <td><input type="date" name="line_end_date[]" class="form-control form-control-sm"
                               value="<?= htmlspecialchars((string)($ln['end_date'] ?? '')) ?>"
                               title="Blank = ongoing"></td>
                    <td>
                        <input type="text" name="line_notes[]" value="<?= htmlspecialchars((string)($ln['notes'] ?? '')) ?>"
                               class="form-control form-control-sm">
                        <input type="number" name="line_min_term[]" value="<?= (int)$ln['min_term_months'] ?>" hidden>
                        <?php if ($canOverrideRate): ?>
                            <input type="text" name="line_override_reason[]"
                                   value="<?= htmlspecialchars((string)($ln['rate_override_reason'] ?? '')) ?>"
                                   class="form-control form-control-sm override-reason"
                                   placeholder="Reason if rate changed from default"
                                   style="margin-top:0.2rem;font-size:0.78rem;<?= $isOverride ? '' : 'display:none;' ?>">
                        <?php else: ?>
                            <input type="hidden" name="line_override_reason[]" value="">
                        <?php endif; ?>
                    </td>
                    <td><button type="button" class="btn btn-sm btn-outline" onclick="this.closest('tr').remove();TCH_updateTotals();">×</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#f8f9fa;">
                    <td colspan="4" style="text-align:right;padding:0.5rem;font-weight:600;">Quote total (per period):</td>
                    <td id="quote-total" style="text-align:right;font-weight:700;font-size:1.05rem;color:#15803d;">R0</td>
                    <td colspan="4"></td>
                </tr>
            </tfoot>
        </table>
        <button type="button" class="btn btn-sm btn-outline" style="margin-top:0.4rem;" onclick="TCH_addLineRow()">+ Add line</button>
    </div>

    <div style="background:#fff;padding:1rem;border:1px solid #dee2e6;border-radius:6px;margin-bottom:1rem;">
        <h3 style="margin-top:0;">Notes</h3>
        <textarea name="notes" rows="3" class="form-control" placeholder="Internal notes on this quote — client caveats, negotiation points, etc."><?= htmlspecialchars((string)($quote['notes'] ?? '')) ?></textarea>
    </div>

    <div style="display:flex;gap:0.6rem;">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create quote' ?></button>
        <a href="<?= APP_URL ?>/admin/quotes<?= $isEdit ? '/' . $quoteId : '' ?>" class="btn" style="background:#f1f5f9;color:#334155;">Cancel</a>
    </div>
</form>

<script>
const TCH_RATES = <?= json_encode($ratesByProduct, JSON_UNESCAPED_SLASHES) ?>;
const TCH_CAN_OVERRIDE = <?= $canOverrideRate ? 'true' : 'false' ?>;

function TCH_addLineRow() {
    const tpl = document.querySelector('#lines-tbody .line-row');
    const clone = tpl.cloneNode(true);
    clone.querySelectorAll('input, select').forEach(el => {
        if (el.name === 'line_start_date[]')     el.value = document.querySelector('[name=start_date]').value || '';
        else if (el.name === 'line_end_date[]')  el.value = '';
        else if (el.name === 'line_product_id[]') el.value = '';
        else if (el.name === 'line_bill_rate[]')  el.value = '0';
        else if (el.name === 'line_units_per_period[]') el.value = '1';
        else if (el.name === 'line_min_term[]') el.value = '0';
        else if (el.type === 'hidden') el.value = '';
        else if (el.type === 'text' && el.classList.contains('override-reason')) el.value = '';
        else if (el.type === 'text') el.value = '';
    });
    const reason = clone.querySelector('.override-reason');
    if (reason) reason.style.display = 'none';
    document.querySelector('#lines-tbody').appendChild(clone);
    TCH_rebuildUnitDropdown(clone.querySelector('.freq-sel'));
    TCH_updateTotals();
}

function TCH_onProductChange(sel) {
    const row = sel.closest('tr');
    TCH_rebuildUnitDropdown(row.querySelector('.freq-sel'));
    TCH_applyDefaultRate(row);
    TCH_updateTotals();
}

function TCH_onUnitChange(sel) {
    const row = sel.closest('tr');
    TCH_applyDefaultRate(row);
    TCH_updateTotals();
}

function TCH_onRateChange(input) {
    const row = input.closest('tr');
    const defaultRate = parseFloat(row.querySelector('.default-rate').value || '0');
    const newRate = parseFloat(input.value || '0');
    const isOverride = Math.abs(newRate - defaultRate) > 0.001;

    const reason = row.querySelector('.override-reason');
    if (reason) {
        reason.style.display = isOverride ? '' : 'none';
        if (isOverride) reason.required = true;
        else { reason.required = false; reason.value = ''; }
    }
    input.style.borderColor = isOverride ? '#fd7e14' : '';
    TCH_updateTotals();
}

function TCH_rebuildUnitDropdown(sel) {
    const row = sel.closest('tr');
    const prodId = parseInt(row.querySelector('.product-sel').value || '0', 10);
    const currentVal = sel.value;
    const rates = TCH_RATES[prodId] || {};
    sel.innerHTML = '';
    const keys = Object.keys(rates);
    if (keys.length === 0) {
        sel.innerHTML = '<option value="monthly">monthly</option>';
        return;
    }
    // Put the is_default first, then others
    keys.sort((a, b) => (rates[b].is_default - rates[a].is_default) || a.localeCompare(b));
    keys.forEach(k => {
        const opt = document.createElement('option');
        opt.value = k;
        opt.textContent = k + ' — R' + rates[k].rate.toFixed(2);
        if (k === currentVal) opt.selected = true;
        sel.appendChild(opt);
    });
    if (!keys.includes(currentVal)) sel.selectedIndex = 0;
}

function TCH_applyDefaultRate(row) {
    const prodId = parseInt(row.querySelector('.product-sel').value || '0', 10);
    const unit   = row.querySelector('.freq-sel').value;
    const rates  = TCH_RATES[prodId] || {};
    const defaultRate = rates[unit] ? rates[unit].rate : null;
    const defHidden = row.querySelector('.default-rate');
    defHidden.value = defaultRate !== null ? defaultRate : '';
    if (defaultRate !== null) {
        const rateInput = row.querySelector('.rate-input');
        // Only clobber the rate if user hasn't already typed an override
        const current = parseFloat(rateInput.value || '0');
        const wasOverride = Math.abs(current - (parseFloat(row.dataset.prevDefault || '0'))) > 0.001
                            && row.dataset.prevDefault;
        if (!wasOverride || !TCH_CAN_OVERRIDE) {
            rateInput.value = defaultRate.toFixed(2);
            const reason = row.querySelector('.override-reason');
            if (reason) { reason.style.display = 'none'; reason.value = ''; reason.required = false; }
            rateInput.style.borderColor = '';
        }
        row.dataset.prevDefault = defaultRate;
    }
}

function TCH_updateTotals() {
    let quoteTotal = 0;
    document.querySelectorAll('#lines-tbody .line-row').forEach(row => {
        const rate = parseFloat(row.querySelector('.rate-input').value || '0');
        const qty  = parseFloat(row.querySelector('.units-input').value || '0');
        const lineTotal = rate * qty;
        row.querySelector('.line-total').textContent = 'R' + lineTotal.toLocaleString('en-ZA', {maximumFractionDigits:2});
        quoteTotal += lineTotal;
    });
    document.getElementById('quote-total').textContent = 'R' + quoteTotal.toLocaleString('en-ZA', {maximumFractionDigits:2});
}

// Patient → auto-select default bill-payer
document.getElementById('patient_sel').addEventListener('change', function(){
    const def = this.selectedOptions[0]?.dataset?.defaultClient;
    if (def && def !== '0') {
        const clientSel = document.getElementById('client_sel');
        if (!clientSel.value) clientSel.value = def;
    }
});

// Initial render
document.querySelectorAll('#lines-tbody .line-row').forEach(row => {
    TCH_rebuildUnitDropdown(row.querySelector('.freq-sel'));
});
TCH_updateTotals();
</script>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
