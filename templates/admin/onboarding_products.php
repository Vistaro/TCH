<?php
/**
 * Task 2 subpage — product billing defaults (FR-A multi-unit pricing).
 * One form row per active product. Each product shows six billing-freq
 * rows (hourly / daily / weekly / monthly / per-visit / upfront) as a
 * fixed grid — tick the ones the product supports, fill the rate, and
 * mark one as the default. Default row is the prefill for new quote
 * / contract lines.
 *
 * Reads + writes against product_billing_rates (migration 036).
 * products.default_billing_freq / .default_price remain in place as
 * backwards-compat until the follow-up retirement migration.
 */
$pageTitle = 'Product billing defaults';
$activeNav = 'onboarding';

$db      = getDB();
$canEdit = userCan('products', 'edit');

$flash = ''; $flashType = 'success';

$ALL_FREQS = ['hourly','daily','weekly','monthly','per_visit','upfront_only'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } else {
        $updates = $_POST['product'] ?? [];
        $changed = 0;
        $logEntries = [];

        $db->beginTransaction();
        try {
            foreach ($updates as $pid => $fields) {
                $pid = (int)$pid;
                if ($pid <= 0) continue;

                // Snapshot pre-change state for per-product audit log
                $beforeStmt = $db->prepare(
                    "SELECT billing_freq, rate, currency_code, is_default, is_active
                       FROM product_billing_rates WHERE product_id = ?
                   ORDER BY billing_freq"
                );
                $beforeStmt->execute([$pid]);
                $before = [
                    'min_term' => (int)$db->query(
                        "SELECT default_min_term_months FROM products WHERE id = " . (int)$pid
                    )->fetchColumn(),
                    'rates' => $beforeStmt->fetchAll(PDO::FETCH_ASSOC),
                ];

                // Min term — still on products.default_min_term_months
                $minTerm = (int)($fields['min_term'] ?? 0);
                $db->prepare("UPDATE products SET default_min_term_months = ? WHERE id = ?")
                   ->execute([$minTerm, $pid]);

                // The radio value names the chosen default billing_freq; blank
                // means no default picked (product not set up yet).
                $defaultFreq = $fields['default'] ?? '';
                if (!in_array($defaultFreq, $ALL_FREQS, true)) $defaultFreq = '';

                // Walk all six possible freqs. For each: active-or-not, rate, default-or-not.
                $rates = $fields['rate'] ?? [];
                foreach ($ALL_FREQS as $freq) {
                    $row       = $rates[$freq] ?? [];
                    $isActive  = !empty($row['active']) ? 1 : 0;
                    $rate      = (float)($row['value'] ?? 0);
                    $isDefault = ($defaultFreq === $freq && $isActive) ? 1 : 0;

                    if ($isActive) {
                        // Upsert with the submitted values
                        $db->prepare(
                            "INSERT INTO product_billing_rates
                               (product_id, billing_freq, rate, currency_code, is_default, is_active)
                             VALUES (?, ?, ?, 'ZAR', ?, 1)
                             ON DUPLICATE KEY UPDATE
                               rate       = VALUES(rate),
                               is_default = VALUES(is_default),
                               is_active  = 1"
                        )->execute([$pid, $freq, $rate, $isDefault]);
                    } else {
                        // Deactivate any existing row; preserve the history instead of deleting
                        $db->prepare(
                            "UPDATE product_billing_rates
                                SET is_active  = 0,
                                    is_default = 0
                              WHERE product_id = ? AND billing_freq = ?"
                        )->execute([$pid, $freq]);
                    }
                }

                // Belt-and-braces: if the radio picked an inactive row (which
                // the UI shouldn't allow, but defensive server-side), no row
                // ends up as default and the task counter will flag it pending.

                // Keep products.default_billing_freq / .default_price loosely in
                // sync with the new default row so any code still reading those
                // columns doesn't fall off a cliff mid-migration. This mirror
                // retires with the legacy columns in the follow-up migration.
                if ($defaultFreq !== '') {
                    $defaultRate = (float)($rates[$defaultFreq]['value'] ?? 0);
                    $db->prepare(
                        "UPDATE products
                            SET default_billing_freq = ?, default_price = ?
                          WHERE id = ?"
                    )->execute([$defaultFreq, $defaultRate, $pid]);
                }

                // Snapshot after-state
                $afterStmt = $db->prepare(
                    "SELECT billing_freq, rate, currency_code, is_default, is_active
                       FROM product_billing_rates WHERE product_id = ?
                   ORDER BY billing_freq"
                );
                $afterStmt->execute([$pid]);
                $after = [
                    'min_term' => $minTerm,
                    'rates'    => $afterStmt->fetchAll(PDO::FETCH_ASSOC),
                ];

                $logEntries[] = [
                    'product_id' => $pid,
                    'before'     => $before,
                    'after'      => $after,
                ];
                $changed++;
            }
            $db->commit();
            $flash = "Saved. {$changed} product(s) updated.";
        } catch (Throwable $e) {
            $db->rollBack();
            $flash = 'Error: ' . $e->getMessage(); $flashType = 'error';
            $logEntries = [];
        }

        // Standing rule — logActivity() fires AFTER commit so rollback
        // paths don't leave behind orphan audit entries for a mutation
        // that never happened.
        foreach ($logEntries as $entry) {
            logActivity(
                'products_billing_rates_save',
                'onboarding',
                'products',
                $entry['product_id'],
                'Updated billing rates for product ' . $entry['product_id'],
                $entry['before'],
                $entry['after']
            );
        }
    }
    header('Location: ' . APP_URL . '/admin/onboarding/products?msg=' . urlencode($flash) . '&type=' . urlencode($flashType));
    exit;
}

if ($flash === '' && isset($_GET['msg'])) {
    $flash = (string)$_GET['msg']; $flashType = (string)($_GET['type'] ?? 'success');
}

// Read: products + any existing rate rows, indexed by (product_id, billing_freq)
$products = $db->query(
    "SELECT id, code, name, description, default_min_term_months
       FROM products
      WHERE is_active = 1
      ORDER BY sort_order, name"
)->fetchAll();

$rateRows = $db->query(
    "SELECT product_id, billing_freq, rate, currency_code, is_default, is_active
       FROM product_billing_rates"
)->fetchAll();

$ratesByProduct = [];
foreach ($rateRows as $r) {
    $ratesByProduct[(int)$r['product_id']][$r['billing_freq']] = $r;
}

$freqLabels = [
    'hourly'       => 'Hourly',
    'daily'        => 'Daily',
    'weekly'       => 'Weekly',
    'monthly'      => 'Monthly',
    'per_visit'    => 'Per visit',
    'upfront_only' => 'Upfront only',
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

<h2 style="margin:0 0 0.5rem 0;">Product billing defaults</h2>
<p style="color:#6c757d;margin-bottom:1rem;">
    For each product, tick the billing units it can be sold in and set the standard rate for each — hourly, daily, weekly, monthly, per-visit, or upfront. Pick one of the active units as the default; it prefills new quote and contract lines. The "minimum commitment" is read in the unit of the default.
</p>

<form method="POST">
    <?= csrfField() ?>

    <?php foreach ($products as $p):
        $pid = (int)$p['id'];
        $productRates = $ratesByProduct[$pid] ?? [];
    ?>
    <div style="background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:1rem 1.25rem;margin-bottom:1rem;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:0.75rem;">
            <div style="flex:1;min-width:260px;">
                <div style="font-weight:600;font-size:1rem;"><?= htmlspecialchars($p['name']) ?></div>
                <?php if (!empty($p['description'])): ?>
                    <div style="color:#6c757d;font-size:0.8rem;line-height:1.35;margin-top:0.15rem;"><?= htmlspecialchars($p['description']) ?></div>
                <?php endif; ?>
                <code style="color:#6c757d;font-size:0.7rem;"><?= htmlspecialchars($p['code']) ?></code>
            </div>
            <div style="min-width:160px;">
                <label style="display:block;font-size:0.8rem;color:#495057;margin-bottom:0.2rem;">Min Requirement</label>
                <input type="number" min="0" max="120" step="1"
                       name="product[<?= $pid ?>][min_term]"
                       value="<?= (int)$p['default_min_term_months'] ?>"
                       class="form-control form-control-sm"
                       style="width:100%;text-align:right;"
                       <?= $canEdit ? '' : 'disabled' ?>>
                <div style="font-size:0.7rem;color:#6c757d;margin-top:0.2rem;">In the unit of the default billing freq.</div>
            </div>
        </div>

        <table class="report-table" style="margin:0;">
            <thead>
                <tr>
                    <th class="center" style="width:70px;">Active</th>
                    <th>Billing unit</th>
                    <th class="number" style="width:140px;">Rate (ZAR)</th>
                    <th class="center" style="width:80px;">Default</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ALL_FREQS as $freq):
                    $existing  = $productRates[$freq] ?? null;
                    $isActive  = $existing ? (int)$existing['is_active'] : 0;
                    $rateValue = $existing && $existing['rate'] !== null ? (float)$existing['rate'] : null;
                    $isDefault = $existing ? (int)$existing['is_default'] : 0;
                ?>
                <tr>
                    <td class="center">
                        <input type="checkbox"
                               name="product[<?= $pid ?>][rate][<?= $freq ?>][active]"
                               value="1"
                               <?= $isActive ? 'checked' : '' ?>
                               <?= $canEdit ? '' : 'disabled' ?>>
                    </td>
                    <td><?= htmlspecialchars($freqLabels[$freq]) ?></td>
                    <td class="number">
                        <input type="number" min="0" step="0.01"
                               name="product[<?= $pid ?>][rate][<?= $freq ?>][value]"
                               value="<?= $rateValue !== null ? htmlspecialchars(number_format($rateValue, 2, '.', '')) : '' ?>"
                               class="form-control form-control-sm"
                               style="width:100%;text-align:right;"
                               <?= $canEdit ? '' : 'disabled' ?>>
                    </td>
                    <td class="center">
                        <input type="radio"
                               name="product[<?= $pid ?>][default]"
                               value="<?= $freq ?>"
                               <?= $isDefault ? 'checked' : '' ?>
                               <?= $canEdit ? '' : 'disabled' ?>>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <?php if ($canEdit): ?>
        <div style="margin-top:1rem;text-align:right;">
            <button class="btn btn-primary" type="submit">Save all</button>
        </div>
    <?php endif; ?>
</form>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
