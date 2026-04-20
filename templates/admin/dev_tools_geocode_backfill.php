<?php
/**
 * Dev Tools — Geocode backfill — /admin/dev-tools/geocode-backfill
 *
 * FR-N Phase 2. Batch-geocodes persons whose lat/lng is still NULL.
 * Nominatim rate limit is 1 req/sec, so batches of 25 take ~25s.
 *
 * Super-admin only. No env gate (safe to run on PROD — only populates
 * data, never deletes; can re-run without harm because the helper
 * skips rows that already have coords).
 */
require_once APP_ROOT . '/includes/geocode.php';

$pageTitle = 'Geocode backfill';
$activeNav = 'dev-tools-geocode';

$db = getDB();
$user = currentUser();

if (!userCan('dev_tools_test_data', 'edit')) {
    http_response_code(403);
    echo '<p>Dev tools are super-admin only.</p>';
    return;
}

$flash = ''; $flashType = 'success';
$runResults = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } else {
        $batchSize = max(1, min(50, (int)($_POST['batch_size'] ?? 10)));
        $todo = listPersonsNeedingGeocode($db, $batchSize, onlyPatients: true);
        $hits = 0; $misses = 0;
        foreach ($todo as $p) {
            $ok = geocodePersonAndSave($db, (int)$p['id']);
            $runResults[] = [
                'id' => (int)$p['id'],
                'name' => $p['full_name'],
                'address' => $p['address_hint'],
                'hit' => $ok,
            ];
            if ($ok) $hits++; else $misses++;
        }
        logActivity('dev_tools_geocode_batch', 'dev_tools_test_data', 'dev_tools', null,
            "Batch: $hits hits, $misses misses across " . count($todo) . ' candidates',
            null, ['batch_size' => $batchSize, 'hits' => $hits, 'misses' => $misses]);
        $flash = "Processed " . count($todo) . " rows — $hits geocoded, $misses with no result / no address.";
        if ($misses > 0 && $hits === 0) $flashType = 'error';
    }
}

// ── Current state summary
$stats = $db->query(
    "SELECT
        COUNT(*) AS total_patients,
        SUM(CASE WHEN p.latitude IS NOT NULL THEN 1 ELSE 0 END) AS with_coords,
        SUM(CASE WHEN p.latitude IS NULL
                  AND TRIM(CONCAT_WS(' ',
                        NULLIF(p.street_address, ''),
                        NULLIF(p.suburb, ''),
                        NULLIF(p.city, ''))) <> '' THEN 1 ELSE 0 END) AS needs_geocode_with_address,
        SUM(CASE WHEN p.latitude IS NULL
                  AND TRIM(CONCAT_WS(' ',
                        NULLIF(p.street_address, ''),
                        NULLIF(p.suburb, ''),
                        NULLIF(p.city, ''))) = '' THEN 1 ELSE 0 END) AS needs_geocode_no_address
       FROM patients pt
       JOIN persons p ON p.id = pt.person_id
      WHERE p.archived_at IS NULL"
)->fetch(PDO::FETCH_ASSOC);

require APP_ROOT . '/templates/layouts/admin.php';
?>

<?php if ($flash): ?>
    <div style="padding:0.8rem 1rem;margin-bottom:1rem;border-radius:4px;background:<?= $flashType === 'error' ? '#f8d7da' : '#d1e7dd' ?>;color:<?= $flashType === 'error' ? '#842029' : '#0f5132' ?>;">
        <?= htmlspecialchars($flash) ?>
    </div>
<?php endif; ?>

<div style="background:#fff3cd;border:1px solid #ffecb5;color:#664d03;padding:0.8rem 1rem;border-radius:4px;margin-bottom:1rem;font-size:0.9rem;">
    <strong>Super-admin only.</strong> Backfills <code>persons.latitude</code> + <code>persons.longitude</code>
    for existing patients using OpenStreetMap Nominatim. Rate-limited to 1 request per second (their usage
    policy) — a batch of 25 takes ~25 seconds. New patients auto-geocode on address save; this page is for
    the one-shot catch-up.
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;max-width:900px;">

    <div style="background:#fff;border:1px solid #dee2e6;border-radius:6px;padding:1rem 1.2rem;">
        <h3 style="margin-top:0;font-size:1rem;">Current patient state</h3>
        <dl style="margin:0;display:grid;grid-template-columns:auto 1fr;gap:0.3rem 1rem;font-size:0.9rem;">
            <dt style="color:#6c757d;">Total patients:</dt>
            <dd style="margin:0;font-family:monospace;"><?= (int)$stats['total_patients'] ?></dd>
            <dt style="color:#6c757d;">Already geocoded:</dt>
            <dd style="margin:0;font-family:monospace;color:#198754;"><?= (int)$stats['with_coords'] ?></dd>
            <dt style="color:#6c757d;">Need geocoding:</dt>
            <dd style="margin:0;font-family:monospace;color:#fd7e14;"><?= (int)$stats['needs_geocode_with_address'] ?></dd>
            <dt style="color:#6c757d;">No address on file:</dt>
            <dd style="margin:0;font-family:monospace;color:#adb5bd;"><?= (int)$stats['needs_geocode_no_address'] ?></dd>
        </dl>
    </div>

    <div style="background:#fff;border:1px solid #dee2e6;border-radius:6px;padding:1rem 1.2rem;">
        <h3 style="margin-top:0;font-size:1rem;">Run a batch</h3>
        <form method="post">
            <?= csrfField() ?>
            <label style="display:block;margin-bottom:0.8rem;">
                <span style="display:block;font-size:0.85rem;color:#495057;">How many to process?</span>
                <input type="number" name="batch_size" value="10" min="1" max="50" required
                       style="width:100px;padding:0.3rem 0.5rem;font-size:0.95rem;">
                <span style="color:#6c757d;font-size:0.78rem;margin-left:0.4rem;">(1–50, ~1 sec each)</span>
            </label>
            <p style="font-size:0.8rem;color:#6c757d;margin:0 0 0.8rem 0;">
                Only patients with a non-empty address are attempted. Rows with no address are counted above but skipped here.
            </p>
            <button type="submit" class="btn btn-primary">Geocode next batch</button>
        </form>
    </div>

    <?php if (!empty($runResults)): ?>
    <div style="background:#fff;border:1px solid #dee2e6;border-radius:6px;padding:1rem 1.2rem;grid-column:1 / -1;">
        <h3 style="margin-top:0;font-size:1rem;">Last run results</h3>
        <table class="report-table tch-data-table" style="font-size:0.85rem;">
            <thead><tr>
                <th class="center" data-filterable="false">ID</th>
                <th>Name</th>
                <th>Address used</th>
                <th class="center" data-filterable="false">Result</th>
            </tr></thead>
            <tbody>
                <?php foreach ($runResults as $r): ?>
                    <tr>
                        <td class="center"><code><?= (int)$r['id'] ?></code></td>
                        <td><?= htmlspecialchars($r['name']) ?></td>
                        <td style="color:#6c757d;"><?= htmlspecialchars($r['address'] ?? '') ?: '<em>(none)</em>' ?></td>
                        <td class="center">
                            <?php if ($r['hit']): ?>
                                <span style="color:#198754;font-weight:600;">✓ geocoded</span>
                            <?php else: ?>
                                <span style="color:#6c757d;">— no match</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
