<?php
/**
 * Manage release notes — /admin/releases
 *
 * Super Admin only (gated by releases_admin permission). Used to seed
 * the "What's New" feed before a deploy.
 */

$pageTitle = 'Manage Releases';
$activeNav = '';

$db = getDB();
$me = currentEffectiveUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'create' && userCan('releases_admin', 'create')) {
        $version = trim($_POST['version'] ?? '');
        $title   = trim($_POST['title'] ?? '');
        $summary = trim($_POST['summary'] ?? '');
        $known   = trim($_POST['known_issues'] ?? '');
        $pub     = isset($_POST['is_published']) ? 1 : 0;
        if ($version && $title) {
            $db->prepare(
                'INSERT INTO releases (version, title, summary, known_issues, is_published, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$version, $title, $summary ?: null, $known ?: null, $pub, (int)($me['id'] ?? 0)]);
            $newId = (int)$db->lastInsertId();
            logActivity('release_created', 'releases_admin', 'releases', $newId,
                "Created release $version", null,
                ['version'=>$version,'title'=>$title,'is_published'=>$pub]);
        }
    } elseif ($action === 'edit' && userCan('releases_admin', 'edit')) {
        $id      = (int)($_POST['id'] ?? 0);
        $version = trim($_POST['version'] ?? '');
        $title   = trim($_POST['title'] ?? '');
        $summary = trim($_POST['summary'] ?? '');
        $known   = trim($_POST['known_issues'] ?? '');
        $pub     = isset($_POST['is_published']) ? 1 : 0;
        if ($id && $version && $title) {
            $stmt = $db->prepare('SELECT * FROM releases WHERE id = ?');
            $stmt->execute([$id]);
            $before = $stmt->fetch(PDO::FETCH_ASSOC);
            $db->prepare(
                'UPDATE releases SET version = ?, title = ?, summary = ?, known_issues = ?, is_published = ? WHERE id = ?'
            )->execute([$version, $title, $summary ?: null, $known ?: null, $pub, $id]);
            logActivity('release_updated', 'releases_admin', 'releases', $id,
                "Updated release $version", $before,
                ['version'=>$version,'title'=>$title,'summary'=>$summary,'known_issues'=>$known,'is_published'=>$pub]);
        }
    }
    header('Location: ' . APP_URL . '/admin/releases');
    exit;
}

$releases = $db->query('SELECT * FROM releases ORDER BY released_at DESC, id DESC')->fetchAll();

$editing = null;
if (!empty($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM releases WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <p style="color:#666;font-size:0.85rem;margin:0;"><?= count($releases) ?> release<?= count($releases) === 1 ? '' : 's' ?></p>
    <a href="<?= APP_URL ?>/admin/whats-new" class="btn btn-outline btn-sm">Preview What's New</a>
</div>

<div class="person-card" style="margin-bottom:1.5rem;">
    <div class="person-card-header">
        <div class="person-card-title">
            <h2><?= $editing ? 'Edit release' : 'New release' ?></h2>
            <div class="person-card-meta">
                Markdown-ish supported — <code>## heading</code>, <code>- bullet</code>, <code>**bold**</code>, <code>*italic*</code>.
            </div>
        </div>
    </div>
    <form method="POST" style="padding:1rem;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'create' ?>">
        <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
        <?php endif; ?>
        <div style="display:grid;grid-template-columns:1fr 3fr 1fr;gap:0.75rem;align-items:end;">
            <div><label>Version <span style="color:#dc3545;">*</span></label>
                <input class="form-control" name="version" required value="<?= htmlspecialchars($editing['version'] ?? '') ?>" placeholder="0.9.21"></div>
            <div><label>Title <span style="color:#dc3545;">*</span></label>
                <input class="form-control" name="title" required value="<?= htmlspecialchars($editing['title'] ?? '') ?>" placeholder="Short headline"></div>
            <div><label style="display:flex;align-items:center;gap:0.4rem;"><input type="checkbox" name="is_published" value="1" <?= ($editing && empty($editing['is_published'])) ? '' : 'checked' ?>> Published</label></div>
        </div>
        <div style="margin-top:0.75rem;">
            <label>What's changed (Summary)</label>
            <textarea class="form-control" name="summary" rows="8" placeholder="## Section&#10;- Bullet&#10;- **bold** highlight"><?= htmlspecialchars($editing['summary'] ?? '') ?></textarea>
        </div>
        <div style="margin-top:0.75rem;">
            <label>Known issues / in flight</label>
            <textarea class="form-control" name="known_issues" rows="5" placeholder="- BUG-X — description&#10;- FR-Y — being scoped"><?= htmlspecialchars($editing['known_issues'] ?? '') ?></textarea>
        </div>
        <div style="margin-top:1rem;text-align:right;">
            <?php if ($editing): ?>
                <a href="<?= APP_URL ?>/admin/releases" class="btn btn-link">Cancel</a>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Create release' ?></button>
        </div>
    </form>
</div>

<table class="report-table tch-data-table">
    <thead><tr>
        <th>Version</th><th>Title</th><th>Released</th><th>Published</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($releases as $r): ?>
        <tr>
            <td><code><?= htmlspecialchars($r['version']) ?></code></td>
            <td><?= htmlspecialchars($r['title']) ?></td>
            <td><?= htmlspecialchars(date('d M Y H:i', strtotime($r['released_at']))) ?></td>
            <td><?= $r['is_published'] ? '✓' : '—' ?></td>
            <td><a href="<?= APP_URL ?>/admin/releases?edit=<?= (int)$r['id'] ?>" class="btn btn-link btn-sm">Edit</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
