<?php
$pageTitle = 'Products';
$activeNav = 'products';

$db = getDB();
$user = currentEffectiveUser();

// ── Handle create / edit / delete ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' && userCan('products', 'create')) {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($code && $name) {
            $stmt = $db->prepare('INSERT INTO products (code, name, description) VALUES (?, ?, ?)');
            $stmt->execute([$code, $name, $desc]);
            $newId = (int)$db->lastInsertId();
            logActivity('product_created', 'products', 'products', $newId,
                "Created product: $name ($code)", null,
                ['code' => $code, 'name' => $name, 'description' => $desc]);
        }
    } elseif ($action === 'edit' && userCan('products', 'edit')) {
        $id = (int)($_POST['id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($id && $code && $name) {
            $before = $db->prepare('SELECT * FROM products WHERE id = ?');
            $before->execute([$id]);
            $beforeRow = $before->fetch(PDO::FETCH_ASSOC);
            $db->prepare('UPDATE products SET code = ?, name = ?, description = ?, is_active = ? WHERE id = ?')
               ->execute([$code, $name, $desc, $active, $id]);
            logActivity('product_updated', 'products', 'products', $id,
                "Updated product: $name", $beforeRow,
                ['code' => $code, 'name' => $name, 'description' => $desc, 'is_active' => $active]);
        }
    }
    header('Location: ' . APP_URL . '/admin/products');
    exit;
}

$products = $db->query('SELECT * FROM products ORDER BY sort_order, name')->fetchAll();

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <p style="color:#666;font-size:0.85rem;"><?= count($products) ?> product<?= count($products) !== 1 ? 's' : '' ?></p>
    <?php if (userCan('products', 'create')): ?>
    <button class="btn btn-primary" onclick="document.getElementById('create-form').style.display='block'">+ Add Product</button>
    <?php endif; ?>
</div>

<div id="create-form" style="display:none;background:#f8f9fa;padding:1rem;border-radius:8px;margin-bottom:1.5rem;">
    <h3 style="margin-top:0;">New Product</h3>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div style="display:grid;grid-template-columns:1fr 2fr 3fr;gap:0.75rem;align-items:end;">
            <div><label>Code</label><input type="text" name="code" required placeholder="day_rate" class="form-control"></div>
            <div><label>Name</label><input type="text" name="name" required placeholder="Day Rate" class="form-control"></div>
            <div><label>Description</label><input type="text" name="description" placeholder="Standard day shift care" class="form-control"></div>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:0.75rem;">Create</button>
        <button type="button" class="btn" onclick="this.closest('#create-form').style.display='none'">Cancel</button>
    </form>
</div>

<table class="report-table tch-data-table">
    <thead><tr>
        <th>Code</th><th>Name</th><th>Description</th><th>Active</th>
        <?php if (userCan('products', 'edit')): ?><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($products as $p): ?>
        <tr>
            <td><code><?= htmlspecialchars($p['code']) ?></code></td>
            <td><?= htmlspecialchars($p['name']) ?></td>
            <td style="color:#666;font-size:0.85rem;"><?= htmlspecialchars($p['description'] ?? '') ?></td>
            <td><?= $p['is_active'] ? '✓' : '—' ?></td>
            <?php if (userCan('products', 'edit')): ?>
            <td>
                <button class="btn btn-sm" onclick="editProduct(<?= $p['id'] ?>, '<?= htmlspecialchars($p['code'], ENT_QUOTES) ?>', '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($p['description'] ?? '', ENT_QUOTES) ?>', <?= $p['is_active'] ?>)">Edit</button>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<div id="edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;display:none;align-items:center;justify-content:center;">
    <div style="background:white;padding:1.5rem;border-radius:8px;width:500px;max-width:90vw;">
        <h3 style="margin-top:0;">Edit Product</h3>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit-id">
            <div style="margin-bottom:0.75rem;"><label>Code</label><input type="text" name="code" id="edit-code" required class="form-control"></div>
            <div style="margin-bottom:0.75rem;"><label>Name</label><input type="text" name="name" id="edit-name" required class="form-control"></div>
            <div style="margin-bottom:0.75rem;"><label>Description</label><input type="text" name="description" id="edit-desc" class="form-control"></div>
            <div style="margin-bottom:0.75rem;"><label><input type="checkbox" name="is_active" id="edit-active" value="1"> Active</label></div>
            <button type="submit" class="btn btn-primary">Save</button>
            <button type="button" class="btn" onclick="document.getElementById('edit-modal').style.display='none'">Cancel</button>
        </form>
    </div>
</div>

<script>
function editProduct(id, code, name, desc, active) {
    document.getElementById('edit-id').value = id;
    document.getElementById('edit-code').value = code;
    document.getElementById('edit-name').value = name;
    document.getElementById('edit-desc').value = desc;
    document.getElementById('edit-active').checked = !!active;
    document.getElementById('edit-modal').style.display = 'flex';
}
</script>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
