<?php
/**
 * Admin: Enquiries inbox
 *
 * Lists all public enquiries in `import_review_state`-style workflow:
 * new → contacted → converted / closed / spam.
 *
 * Single file handles list and detail views (?id=N).
 */
$pageTitle = 'Enquiries';
$activeNav = 'enquiries';

$db   = getDB();
$user = currentUser();

// ── Handle status updates / notes ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $enqId = (int)($_POST['enquiry_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $allowedStatuses = ['new', 'contacted', 'converted', 'spam', 'closed'];

    if ($enqId > 0 && $action === 'set_status' && userCan('enquiries', 'edit')) {
        $newStatus = $_POST['status'] ?? '';
        if (in_array($newStatus, $allowedStatuses, true)) {
            // Snapshot old status for audit
            $oldRow = $db->prepare('SELECT status FROM enquiries WHERE id = ?');
            $oldRow->execute([$enqId]);
            $oldStatus = $oldRow->fetchColumn() ?: null;

            $stmt = $db->prepare(
                "UPDATE enquiries
                 SET status = ?, handled_by = ?, handled_at = NOW()
                 WHERE id = ?"
            );
            $stmt->execute([$newStatus, $user['email'] ?? $user['username'] ?? null, $enqId]);

            logActivity('enquiry_status_changed', 'enquiries', 'enquiries', $enqId,
                "Status: {$oldStatus} -> {$newStatus}",
                ['status' => $oldStatus],
                ['status' => $newStatus]);
        }
    } elseif ($enqId > 0 && $action === 'add_note' && userCan('enquiries', 'edit')) {
        $note = trim((string)($_POST['note'] ?? ''));
        if ($note !== '') {
            $auditLine = sprintf("[%s by %s] %s",
                date('Y-m-d H:i'),
                $user['email'] ?? $user['username'] ?? 'unknown',
                $note
            );
            $stmt = $db->prepare(
                "UPDATE enquiries
                 SET notes = CONCAT_WS('\n\n', NULLIF(notes, ''), ?)
                 WHERE id = ?"
            );
            $stmt->execute([$auditLine, $enqId]);

            // Notes are append-only — capture the appended line itself in the diff
            // so "Was: (empty)  Now: <text>" is meaningful, instead of duplicating
            // the entire growing notes column.
            logActivity('enquiry_note_added', 'enquiries', 'enquiries', $enqId,
                'Note added: ' . substr($note, 0, 80),
                ['note_appended' => null],
                ['note_appended' => $auditLine]);
        }
    }

    header('Location: ' . APP_URL . '/admin/enquiries' . ($enqId ? '?id=' . $enqId : ''));
    exit;
}

// ── Detail view ────────────────────────────────────────────────────────
$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($detailId > 0) {
    $stmt = $db->prepare(
        "SELECT e.*, r.name AS region_name
         FROM enquiries e
         LEFT JOIN regions r ON r.id = e.region_id
         WHERE e.id = ?"
    );
    $stmt->execute([$detailId]);
    $enq = $stmt->fetch();

    if (!$enq) {
        http_response_code(404);
        require APP_ROOT . '/templates/layouts/admin.php';
        echo '<p>Enquiry not found.</p><p><a href="' . APP_URL . '/admin/enquiries">Back to list</a></p>';
        require APP_ROOT . '/templates/layouts/admin_footer.php';
        return;
    }

    require APP_ROOT . '/templates/layouts/admin.php';
    ?>
    <div style="margin-bottom:1rem;display:flex;gap:0.5rem;justify-content:space-between;align-items:center;flex-wrap:wrap;">
        <a href="<?= APP_URL ?>/admin/enquiries" class="btn btn-outline btn-sm">&larr; Back to inbox</a>
        <?php
        // Already-converted detection — avoid building a duplicate opp.
        $existingOpp = null;
        if (userCan('opportunities', 'read')) {
            $oppStmt = $db->prepare(
                "SELECT id, opp_ref, title FROM opportunities
                  WHERE source_enquiry_id = ?
                  ORDER BY id DESC LIMIT 1"
            );
            $oppStmt->execute([(int)$enq['id']]);
            $existingOpp = $oppStmt->fetch() ?: null;
        }
        ?>
        <?php if ($existingOpp): ?>
            <a href="<?= APP_URL ?>/admin/opportunities/<?= (int)$existingOpp['id'] ?>" class="btn btn-primary btn-sm">
                View opportunity <?= htmlspecialchars($existingOpp['opp_ref']) ?> →
            </a>
        <?php elseif (userCan('opportunities', 'create') && $enq['enquiry_type'] === 'client'): ?>
            <a href="<?= APP_URL ?>/admin/opportunities/new?from_enquiry=<?= (int)$enq['id'] ?>"
               class="btn btn-primary btn-sm" style="background:#15803d;border-color:#15803d;">
                + Convert to Opportunity
            </a>
        <?php endif; ?>
    </div>

    <div class="person-card">
        <div class="person-card-header">
            <div class="person-card-title">
                <h2><?= htmlspecialchars($enq['full_name']) ?></h2>
                <div class="person-card-tch-id">Enquiry #<?= (int)$enq['id'] ?></div>
                <div class="person-card-meta">
                    <?= htmlspecialchars(ucfirst($enq['enquiry_type'])) ?> &middot;
                    Status: <strong><?= htmlspecialchars(ucfirst($enq['status'])) ?></strong> &middot;
                    Received <?= htmlspecialchars($enq['created_at']) ?>
                    <?php if (!empty($enq['region_name'])): ?>
                        &middot; Region: <?= htmlspecialchars($enq['region_name']) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="person-card-grid">
            <div class="person-card-section">
                <h3>Contact</h3>
                <dl>
                    <dt>Phone</dt><dd><?= htmlspecialchars($enq['phone'] ?? '') ?: '—' ?></dd>
                    <dt>Email</dt><dd><?= htmlspecialchars($enq['email'] ?? '') ?: '—' ?></dd>
                    <dt>Area</dt><dd><?= htmlspecialchars($enq['suburb_or_area'] ?? '') ?: '—' ?></dd>
                </dl>
            </div>
            <div class="person-card-section">
                <h3>Care Needed</h3>
                <dl>
                    <dt>Type</dt><dd><?= htmlspecialchars($enq['care_type'] ?? '') ?: '—' ?></dd>
                    <dt>Urgency</dt><dd><?= htmlspecialchars($enq['urgency'] ?? '') ?: '—' ?></dd>
                    <dt>Schedule</dt><dd><?= htmlspecialchars($enq['care_schedule'] ?? '') ?: '—' ?></dd>
                </dl>
            </div>
        </div>

        <?php if (!empty($enq['message'])): ?>
        <div class="person-card-section">
            <h3>Their Message</h3>
            <p style="white-space:pre-wrap;"><?= htmlspecialchars($enq['message']) ?></p>
        </div>
        <?php endif; ?>

        <div class="person-card-section">
            <h3>Audit</h3>
            <dl>
                <dt>Source page</dt>  <dd><?= htmlspecialchars($enq['source_page'] ?? '') ?: '—' ?></dd>
                <dt>Referrer</dt>     <dd><?= htmlspecialchars($enq['referrer_url'] ?? '') ?: '—' ?></dd>
                <dt>IP</dt>           <dd><?= htmlspecialchars($enq['ip_address'] ?? '') ?: '—' ?></dd>
                <dt>User agent</dt>   <dd><small><?= htmlspecialchars($enq['user_agent'] ?? '') ?: '—' ?></small></dd>
                <dt>Consent terms</dt><dd><?= $enq['consent_terms'] ? 'Yes' : 'No' ?></dd>
                <dt>Marketing OK</dt> <dd><?= $enq['consent_marketing'] ? 'Yes' : 'No' ?></dd>
            </dl>
        </div>

        <?php if (!empty($enq['notes'])): ?>
        <div class="person-card-section">
            <h3>Notes</h3>
            <pre class="import-notes"><?= htmlspecialchars($enq['notes']) ?></pre>
        </div>
        <?php endif; ?>

        <div class="person-card-section">
            <h3>Actions</h3>
            <form method="POST" style="margin-bottom:1rem;">
                <?= csrfField() ?>
                <input type="hidden" name="enquiry_id" value="<?= (int)$enq['id'] ?>">
                <input type="hidden" name="action" value="set_status">
                <label>Update status:
                    <select name="status">
                        <?php foreach (['new','contacted','converted','spam','closed'] as $s): ?>
                            <option value="<?= $s ?>" <?= $enq['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="btn btn-primary btn-sm">Save status</button>
            </form>

            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="enquiry_id" value="<?= (int)$enq['id'] ?>">
                <input type="hidden" name="action" value="add_note">
                <div class="form-group">
                    <label for="enq_note">Add a note (audit-stamped)</label>
                    <textarea id="enq_note" name="note" rows="3" style="width:100%;"></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Add note</button>
            </form>
        </div>
    </div>

    <?php
    require APP_ROOT . '/templates/layouts/admin_footer.php';
    return;
}

// ── List view ──────────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? 'new';
$where  = [];
$params = [];
if ($filterStatus !== '' && $filterStatus !== 'all') {
    $where[] = 'status = ?';
    $params[] = $filterStatus;
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare(
    "SELECT e.*, r.name AS region_name
     FROM enquiries e
     LEFT JOIN regions r ON r.id = e.region_id
     $whereSQL
     ORDER BY e.created_at DESC
     LIMIT 200"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$counts = [];
foreach ($db->query("SELECT status, COUNT(*) AS n FROM enquiries GROUP BY status") as $r) {
    $counts[$r['status']] = (int)$r['n'];
}

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;gap:1rem;flex-wrap:wrap;">
    <p style="color:#64748b;margin:0;font-size:0.9rem;">Every public enquiry + every enquiry logged by hand lands here. Open one to work it through to conversion.</p>
    <?php if (userCan('enquiries', 'create')): ?>
        <a href="<?= APP_URL ?>/admin/enquiries/new" class="btn btn-primary btn-sm">+ New Enquiry</a>
    <?php endif; ?>
</div>

<div class="dash-cards" style="margin-bottom:1.5rem;">
    <?php foreach (['new','contacted','converted','closed','spam'] as $s): ?>
        <div class="dash-card<?= $s === 'new' ? ' accent' : '' ?>">
            <div class="dash-card-label"><?= ucfirst($s) ?></div>
            <div class="dash-card-value"><?= (int)($counts[$s] ?? 0) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<form method="GET" action="<?= APP_URL ?>/admin/enquiries" class="report-filters">
    <div class="filter-group">
        <label>Status</label>
        <select name="status">
            <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All</option>
            <?php foreach (['new','contacted','converted','closed','spam'] as $s): ?>
                <option value="<?= $s ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary">Filter</button>
</form>

<div class="report-table-wrap">
    <table class="name-table tch-data-table">
        <thead>
            <tr>
                <th>Received</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Care Type</th>
                <th>Urgency</th>
                <th>Area</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="8" style="text-align:center;color:#999;padding:2rem;">
                    No enquiries.
                </td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($r['created_at']))) ?></td>
                        <td><strong><?= htmlspecialchars($r['full_name']) ?></strong></td>
                        <td><?= htmlspecialchars($r['phone'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['care_type'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['urgency'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['suburb_or_area'] ?? '') ?></td>
                        <td>
                            <span class="badge badge-<?= $r['status'] === 'new' ? 'warning' : 'success' ?>">
                                <?= htmlspecialchars(ucfirst($r['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?= APP_URL ?>/admin/enquiries?id=<?= (int)$r['id'] ?>" class="btn btn-primary btn-sm">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
