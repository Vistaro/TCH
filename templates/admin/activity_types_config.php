<?php
$pageTitle = 'Activity Types';
$activeNav = 'config-activity-types';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' && userCan('config_activity_types', 'create')) {
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? 'fa-circle');
        $color = trim($_POST['color'] ?? '#6c757d');
        if ($name) {
            $db->prepare('INSERT INTO activity_types (name, icon, color, sort_order) VALUES (?, ?, ?, (SELECT COALESCE(MAX(t.sort_order),0)+10 FROM activity_types t))')
               ->execute([$name, $icon, $color]);
            logActivity('activity_type_created', 'config_activity_types', 'activity_types',
                (int)$db->lastInsertId(), "Created activity type: $name", null,
                ['name' => $name, 'icon' => $icon, 'color' => $color]);
        }
    } elseif ($action === 'edit' && userCan('config_activity_types', 'edit')) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '');
        $color = trim($_POST['color'] ?? '');
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($id && $name) {
            $before = $db->prepare('SELECT * FROM activity_types WHERE id = ?');
            $before->execute([$id]);
            $db->prepare('UPDATE activity_types SET name = ?, icon = ?, color = ?, is_active = ? WHERE id = ?')
               ->execute([$name, $icon, $color, $active, $id]);
            logActivity('activity_type_updated', 'config_activity_types', 'activity_types', $id,
                "Updated activity type: $name", $before->fetch(PDO::FETCH_ASSOC),
                ['name' => $name, 'icon' => $icon, 'color' => $color, 'is_active' => $active]);
        }
    }
    header('Location: ' . APP_URL . '/admin/config/activity-types');
    exit;
}

$types = $db->query('SELECT * FROM activity_types ORDER BY sort_order')->fetchAll();

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <p style="color:#666;font-size:0.85rem;"><?= count($types) ?> type<?= count($types) !== 1 ? 's' : '' ?></p>
    <?php if (userCan('config_activity_types', 'create')): ?>
    <button class="btn btn-primary" onclick="document.getElementById('create-form').style.display='block'">+ Add Type</button>
    <?php endif; ?>
</div>

<div id="create-form" style="display:none;background:#f8f9fa;padding:1rem;border-radius:8px;margin-bottom:1.5rem;">
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:0.75rem;align-items:end;">
            <div><label>Name</label><input type="text" name="name" required class="form-control" placeholder="Phone Call"></div>
            <div><label>Icon (FA class)</label><input type="text" name="icon" value="fa-circle" class="form-control"></div>
            <div><label>Colour</label><input type="color" name="color" value="#6c757d" class="form-control"></div>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:0.75rem;">Create</button>
        <button type="button" class="btn" onclick="this.closest('#create-form').style.display='none'">Cancel</button>
    </form>
</div>

<table class="report-table tch-data-table">
    <thead><tr><th>Name</th><th>Icon</th><th>Colour</th><th>Active</th><th>Order</th>
    <?php if (userCan('config_activity_types', 'edit')): ?><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($types as $t): ?>
    <tr>
        <td><i class="fa <?= htmlspecialchars($t['icon']) ?>" style="color:<?= htmlspecialchars($t['color']) ?>;margin-right:0.5rem;"></i><?= htmlspecialchars($t['name']) ?></td>
        <td><code><?= htmlspecialchars($t['icon']) ?></code></td>
        <td><span style="display:inline-block;width:16px;height:16px;border-radius:3px;background:<?= htmlspecialchars($t['color']) ?>;vertical-align:middle;"></span> <?= htmlspecialchars($t['color']) ?></td>
        <td><?= $t['is_active'] ? '✓' : '—' ?></td>
        <td><?= (int)$t['sort_order'] ?></td>
        <?php if (userCan('config_activity_types', 'edit')): ?>
        <td><button class="btn btn-sm" onclick="editType(<?= $t['id'] ?>,'<?= htmlspecialchars($t['name'],ENT_QUOTES) ?>','<?= htmlspecialchars($t['icon'],ENT_QUOTES) ?>','<?= htmlspecialchars($t['color'],ENT_QUOTES) ?>',<?= $t['is_active'] ?>)">Edit</button></td>
        <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<div id="edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:white;padding:1.5rem;border-radius:8px;width:400px;max-width:90vw;">
        <h3 style="margin-top:0;">Edit Activity Type</h3>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="et-id">
            <div style="margin-bottom:0.75rem;"><label>Name</label><input type="text" name="name" id="et-name" required class="form-control"></div>
            <div style="margin-bottom:0.75rem;"><label>Icon</label><input type="text" name="icon" id="et-icon" class="form-control"></div>
            <div style="margin-bottom:0.75rem;"><label>Colour</label><input type="color" name="color" id="et-color" class="form-control"></div>
            <div style="margin-bottom:0.75rem;"><label><input type="checkbox" name="is_active" id="et-active" value="1"> Active</label></div>
            <button type="submit" class="btn btn-primary">Save</button>
            <button type="button" class="btn" onclick="document.getElementById('edit-modal').style.display='none'">Cancel</button>
        </form>
    </div>
</div>
<script>
function editType(id,name,icon,color,active){
    document.getElementById('et-id').value=id;
    document.getElementById('et-name').value=name;
    document.getElementById('et-icon').value=icon;
    document.getElementById('et-color').value=color;
    document.getElementById('et-active').checked=!!active;
    document.getElementById('edit-modal').style.display='flex';
}
</script>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
