<?php
/**
 * FX Rates admin — /admin/config/fx-rates
 *
 * View cached rates, refresh on demand. Permission: config_fx_rates.read
 * to view, .edit to refresh.
 */

require_once APP_ROOT . '/includes/currency.php';

$pageTitle = 'FX Rates';
$activeNav = 'config-fx-rates';

$db = getDB();
$canEdit = userCan('config_fx_rates', 'edit');
$flash = ''; $flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'refresh' && $canEdit
        && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $r = refreshFxRates();
    $flash = $r['msg']; $flashType = $r['ok'] ? 'success' : 'error';
    if ($r['ok']) {
        logActivity('fx_rates_refreshed', 'config_fx_rates', null, null,
            'Refreshed FX rates: ' . $r['count'] . ' currencies');
    }
}

$rates = $db->query(
    'SELECT currency_code, rate_per_zar, fetched_at, source FROM fx_rates ORDER BY currency_code'
)->fetchAll();

require APP_ROOT . '/templates/layouts/admin.php';
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flashType === 'error' ? 'error' : 'success') ?>" style="margin-bottom:1rem;">
        <?= htmlspecialchars($flash) ?>
    </div>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <p style="margin:0;color:#6c757d;">
        Live mid-rates from <code>api.exchangerate.host</code>. Base: <strong>ZAR</strong>.
        Used to display amounts in each user's preferred currency on the dashboard and reports.
    </p>
    <?php if ($canEdit): ?>
        <form method="POST" style="margin:0;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="refresh">
            <button class="btn btn-primary btn-sm">Refresh now</button>
        </form>
    <?php endif; ?>
</div>

<table class="report-table tch-data-table">
    <thead><tr>
        <th>Code</th>
        <th>Rate (1 ZAR =)</th>
        <th>Implied 1 unit in ZAR</th>
        <th>Last fetched</th>
        <th>Source</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rates as $r):
        $rate = (float)$r['rate_per_zar'];
        $invZar = $rate > 0 ? 1 / $rate : 0;
    ?>
        <tr>
            <td><strong><?= htmlspecialchars($r['currency_code']) ?></strong></td>
            <td class="number"><?= number_format($rate, 4) ?></td>
            <td class="number"><?= $rate > 0 ? 'R' . number_format($invZar, 2) : '—' ?></td>
            <td><?= htmlspecialchars($r['fetched_at']) ?></td>
            <td><?= htmlspecialchars($r['source']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
