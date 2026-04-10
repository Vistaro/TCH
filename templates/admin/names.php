<?php
$pageTitle = 'Name Reconciliation';
$activeNav = 'names';

$db = getDB();
$currentUserData = currentEffectiveUser();

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrfToken($_POST['csrf_token'] ?? '') && userCan('names_reconcile', 'edit')) {
    $action = $_POST['action'] ?? '';
    $lookupId = (int)($_POST['lookup_id'] ?? 0);

    if ($lookupId > 0) {
        $actorLabel = $currentUserData['email'] ?? $currentUserData['username'] ?? 'unknown';

        if ($action === 'approve') {
            $stmt = $db->prepare(
                "UPDATE name_lookup SET approved = 1, approved_by = ?, approved_at = NOW() WHERE id = ?"
            );
            $stmt->execute([$actorLabel, $lookupId]);

            logActivity('name_lookup_approved', 'names_reconcile', 'name_lookup', $lookupId,
                'Approved name lookup #' . $lookupId,
                ['approved' => 0],
                ['approved' => 1, 'approved_by' => $actorLabel]);
        } elseif ($action === 'reject') {
            $stmt = $db->prepare("UPDATE name_lookup SET approved = 0, approved_by = NULL, approved_at = NULL WHERE id = ?");
            $stmt->execute([$lookupId]);

            logActivity('name_lookup_rejected', 'names_reconcile', 'name_lookup', $lookupId,
                'Rejected name lookup #' . $lookupId,
                ['approved' => 1],
                ['approved' => 0]);
        }
    }

    header('Location: ' . APP_URL . '/admin/names?' . http_build_query($_GET));
    exit;
}

// Get filter options
$tranches = $db->query(
    "SELECT DISTINCT tranche FROM name_lookup WHERE tranche IS NOT NULL AND tranche != '' ORDER BY tranche"
)->fetchAll(PDO::FETCH_COLUMN);

// Apply filters
$where = [];
$params = [];

$filterStatus  = $_GET['status'] ?? '';
$filterTranche = $_GET['tranche'] ?? '';
$filterSearch  = $_GET['search'] ?? '';

if ($filterStatus === 'pending') {
    $where[] = 'nl.approved = 0';
} elseif ($filterStatus === 'approved') {
    $where[] = 'nl.approved = 1';
}
if ($filterTranche !== '') {
    $where[] = 'nl.tranche = ?';
    $params[] = $filterTranche;
}
if ($filterSearch !== '') {
    $where[] = '(nl.canonical_name LIKE ? OR nl.pdf_name LIKE ? OR nl.training_name LIKE ? OR nl.billing_name LIKE ?)';
    $search = '%' . $filterSearch . '%';
    $params = array_merge($params, [$search, $search, $search, $search]);
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT nl.*, ps.label AS cg_status
        FROM name_lookup nl
        LEFT JOIN caregivers cg     ON nl.caregiver_id = cg.id
        LEFT JOIN person_statuses ps ON ps.id = cg.status_id
        $whereSQL
        ORDER BY nl.approved ASC, nl.canonical_name";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Stats
$totalPending  = (int)$db->query("SELECT COUNT(*) FROM name_lookup WHERE approved = 0")->fetchColumn();
$totalApproved = (int)$db->query("SELECT COUNT(*) FROM name_lookup WHERE approved = 1")->fetchColumn();

// Find unmatched billing names (in caregiver_costs but not in name_lookup.billing_name)
$unmatchedBilling = $db->query(
    "SELECT DISTINCT cc.caregiver_name
     FROM caregiver_costs cc
     WHERE cc.caregiver_id IS NULL
       AND cc.caregiver_name NOT IN (SELECT COALESCE(billing_name, '') FROM name_lookup WHERE billing_name IS NOT NULL)
     ORDER BY cc.caregiver_name"
)->fetchAll(PDO::FETCH_COLUMN);

// Unmatched banking names
$unmatchedBanking = $db->query(
    "SELECT DISTINCT cbd.bank_name AS source, 'banking' AS type
     FROM caregiver_banking cbd
     WHERE cbd.caregiver_id IS NULL"
)->fetchAll();

// Get all canonical names for the assign dropdown
$canonicalNames = $db->query(
    "SELECT id, canonical_name FROM name_lookup ORDER BY canonical_name"
)->fetchAll();

require APP_ROOT . '/templates/layouts/admin.php';
?>

<!-- Stats -->
<div class="dash-cards" style="margin-bottom:1.5rem;">
    <div class="dash-card accent">
        <div class="dash-card-label">Pending Review</div>
        <div class="dash-card-value"><?= $totalPending ?></div>
    </div>
    <div class="dash-card" style="border-left:4px solid #059669;">
        <div class="dash-card-label">Approved</div>
        <div class="dash-card-value"><?= $totalApproved ?></div>
    </div>
    <div class="dash-card" style="border-left:4px solid #D97706;">
        <div class="dash-card-label">Unmatched Billing Names</div>
        <div class="dash-card-value"><?= count($unmatchedBilling) ?></div>
    </div>
</div>

<?php if ($unmatchedBilling): ?>
<div class="name-card">
    <div class="name-card-header">
        <h3>Unmatched Billing Names</h3>
        <span class="badge badge-warning"><?= count($unmatchedBilling) ?> names need assignment</span>
    </div>
    <table class="name-table">
        <thead>
            <tr>
                <th>Billing Name (from payroll)</th>
                <th>Assign to Canonical Name</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($unmatchedBilling as $uname): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($uname) ?></strong></td>
                    <td>
                        <form method="POST" action="<?= APP_URL ?>/admin/names/assign" style="display:flex;gap:0.5rem;align-items:center;">
                            <?= csrfField() ?>
                            <input type="hidden" name="billing_name" value="<?= htmlspecialchars($uname) ?>">
                            <select name="lookup_id" style="flex:1;">
                                <option value="">— Select canonical name —</option>
                                <?php foreach ($canonicalNames as $cn): ?>
                                    <option value="<?= $cn['id'] ?>"><?= htmlspecialchars($cn['canonical_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-primary btn-sm">Assign</button>
                        </form>
                    </td>
                    <td></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Filters -->
<form method="GET" action="<?= APP_URL ?>/admin/names" class="report-filters">
    <div class="filter-group">
        <label>Status</label>
        <select name="status">
            <option value="">All</option>
            <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="approved" <?= $filterStatus === 'approved' ? 'selected' : '' ?>>Approved</option>
        </select>
    </div>
    <div class="filter-group">
        <label>Tranche</label>
        <select name="tranche">
            <option value="">All Tranches</option>
            <?php foreach ($tranches as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $filterTranche === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>Search</label>
        <input type="text" name="search" value="<?= htmlspecialchars($filterSearch) ?>" placeholder="Search any name...">
    </div>
    <button type="submit" class="btn btn-primary">Filter</button>
    <?php if ($where): ?>
        <a href="<?= APP_URL ?>/admin/names" class="btn btn-outline btn-sm">Clear</a>
    <?php endif; ?>
</form>

<!-- Main name lookup table -->
<div class="report-table-wrap">
    <table class="name-table">
        <thead>
            <tr>
                <th>Canonical Name</th>
                <th>Training Name</th>
                <th>PDF / Legal Name</th>
                <th>Billing Name</th>
                <th>Tranche</th>
                <th>PDF Score</th>
                <th>Billing Score</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="9" style="text-align:center;color:#999;padding:2rem;">No records found.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($r['canonical_name']) ?></strong></td>
                        <td><?= htmlspecialchars($r['training_name'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($r['pdf_name'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($r['billing_name'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($r['tranche'] ?: '—') ?></td>
                        <td><?php
                            $ps = $r['pdf_match_score'];
                            if ($ps !== null) {
                                $cls = $ps >= 90 ? 'score-high' : ($ps >= 70 ? 'score-med' : 'score-low');
                                echo '<span class="score ' . $cls . '">' . (int)$ps . '%</span>';
                            } else {
                                echo '—';
                            }
                        ?></td>
                        <td><?php
                            $bs = $r['billing_match_score'];
                            if ($bs !== null) {
                                $cls = $bs >= 90 ? 'score-high' : ($bs >= 70 ? 'score-med' : 'score-low');
                                echo '<span class="score ' . $cls . '">' . (int)$bs . '%</span>';
                            } else {
                                echo '—';
                            }
                        ?></td>
                        <td>
                            <?php if ($r['approved']): ?>
                                <span class="badge badge-success">Approved</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" class="name-actions" style="display:inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="lookup_id" value="<?= $r['id'] ?>">
                                <?php if (!$r['approved']): ?>
                                    <button type="submit" name="action" value="approve" class="btn btn-primary btn-sm">Approve</button>
                                <?php else: ?>
                                    <button type="submit" name="action" value="reject" class="btn btn-outline btn-sm" style="color:#DC2626;border-color:#DC2626;">Revoke</button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($rows): ?>
    <p style="margin-top:1rem;font-size:0.85rem;color:#999;">
        Showing <?= count($rows) ?> records. <?= $totalPending ?> pending approval.
        <?php if ($r['notes'] ?? ''): ?>Notes are available in the database.<?php endif; ?>
    </p>
<?php endif; ?>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
