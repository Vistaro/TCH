<?php
/**
 * Task 2 subpage — product billing defaults.
 * Flat bulk-edit form over the products table. One row per active
 * product with default_billing_freq, default_min_term_months,
 * default_price. Save-all posts everything in one go.
 */
$pageTitle = 'Product billing defaults';
$activeNav = 'onboarding';

$db      = getDB();
$canEdit = userCan('products', 'edit');

$flash = ''; $flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } else {
        $updates = $_POST['product'] ?? [];
        $changed = 0;
        $db->beginTransaction();
        try {
            foreach ($updates as $pid => $fields) {
                $pid = (int)$pid;
                if ($pid <= 0) continue;
                $freq = $fields['billing_freq'] ?? '';
                $term = (int)($fields['min_term'] ?? 0);
                $price = (float)($fields['price'] ?? 0);
                if (!in_array($freq, ['hourly','daily','weekly','monthly','per_visit','upfront_only'], true)) continue;
                $stmt = $db->prepare(
                    "UPDATE products
                        SET default_billing_freq = ?, default_min_term_months = ?, default_price = ?
                      WHERE id = ?"
                );
                $stmt->execute([$freq, $term, $price, $pid]);
                $changed += $stmt->rowCount();
            }
            logActivity('products_bulk_defaults', 'onboarding', 'products', 0,
                'Updated billing defaults on ' . $changed . ' product(s)',
                null, ['changed' => $changed]);
            $db->commit();
            $flash = "Saved. {$changed} product(s) updated.";
        } catch (Throwable $e) {
            $db->rollBack();
            $flash = 'Error: ' . $e->getMessage(); $flashType = 'error';
        }
    }
    header('Location: ' . APP_URL . '/admin/onboarding/products?msg=' . urlencode($flash) . '&type=' . urlencode($flashType));
    exit;
}

if ($flash === '' && isset($_GET['msg'])) {
    $flash = (string)$_GET['msg']; $flashType = (string)($_GET['type'] ?? 'success');
}

$rows = $db->query(
    "SELECT id, code, name, description, default_price,
            default_billing_freq, default_min_term_months, is_active
       FROM products
      WHERE is_active = 1
      ORDER BY sort_order, name"
)->fetchAll();

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
    For each product we offer, confirm how we bill by default, the minimum commitment in the unit that matches the billing frequency (e.g. <em>hours</em> for hourly, <em>months</em> for monthly), and the default rate per billing unit. These are prefilled on every new contract line so you don't re-type them.
</p>

<form method="POST">
    <?= csrfField() ?>
    <table class="report-table tch-data-table">
        <thead>
            <tr>
                <th>Product</th>
                <th class="center">Billing freq</th>
                <th class="number">Min Requirement</th>
                <th class="number">Default rate (R)</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($r['name']) ?></strong>
                <?php if (!empty($r['description'])): ?>
                    <div style="color:#6c757d;font-size:0.75rem;"><?= htmlspecialchars($r['description']) ?></div>
                <?php endif; ?>
                <code style="font-size:0.7rem;color:#6c757d;"><?= htmlspecialchars($r['code']) ?></code>
            </td>
            <td class="center">
                <select name="product[<?= (int)$r['id'] ?>][billing_freq]" class="form-control form-control-sm" <?= $canEdit ? '' : 'disabled' ?>>
                    <?php foreach (['hourly','daily','weekly','monthly','per_visit','upfront_only'] as $opt): ?>
                        <option value="<?= $opt ?>" <?= $r['default_billing_freq'] === $opt ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $opt)) ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td class="number">
                <input type="number" min="0" max="60" step="1" name="product[<?= (int)$r['id'] ?>][min_term]"
                       value="<?= (int)$r['default_min_term_months'] ?>" class="form-control form-control-sm" style="width:90px;text-align:right;" <?= $canEdit ? '' : 'disabled' ?>>
            </td>
            <td class="number">
                <input type="number" min="0" step="0.01" name="product[<?= (int)$r['id'] ?>][price]"
                       value="<?= htmlspecialchars((string)($r['default_price'] ?? '')) ?>" class="form-control form-control-sm" style="width:110px;text-align:right;" <?= $canEdit ? '' : 'disabled' ?>>
            </td>
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
