<?php
/**
 * One-shot recovery script for the patient dedup exercise.
 *
 * Context:
 *   The first two merges of Round 1 ran against an outdated
 *   activity_log_revert.php whitelist on the server (the local edit
 *   adding 'clients' hadn't been deployed yet). As a result:
 *
 *   - clients.id=3 "Andre Theron- monthly" was hard-deleted during
 *     manual recovery with NO audit entry captured.
 *   - clients.id=6 "Angela/ Dimitri Paoadopoulos- weekly" was
 *     repointed but the row still exists — activity_log_delete()
 *     rejected it.
 *   - The Papadopoulos rename on clients.id=5 never happened.
 *
 * This script:
 *   1. Backfills a synthetic record_deleted activity_log entry for
 *      id=3 using the row data recovered from
 *      `pre_persons_unification_2026-04-11.sql`.
 *   2. Properly deletes clients.id=6 via activity_log_delete() so
 *      the full row lands in the audit log.
 *   3. Renames clients.id=5 to "Angela/ Dimitri Papadopoulos" and
 *      writes the matching audit entry.
 *
 * Run ONCE then delete. This is not a generic helper.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));

require_once APP_ROOT . '/includes/config.php';
require_once APP_ROOT . '/includes/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/activity_log_revert.php';

// Fake the session user so logActivity() attributes entries to Ross
if (session_status() === PHP_SESSION_NONE) {
    $_SESSION = [];
}
$_SESSION['user_id'] = 1;

$db = getDB();

echo "══════════════════════════════════════════════════\n";
echo "  DEDUP RECOVERY — 2026-04-11\n";
echo "══════════════════════════════════════════════════\n\n";

// ── 1. Backfill audit for lost clients.id=3 ─────────────────────────────
echo "1. Backfilling audit entry for hard-deleted clients.id=3 (Andre Theron- monthly)\n";

$id3Row = [
    'id'              => 3,
    'account_number'  => 'TCH-C0003',
    'client_name'     => 'Andre Theron- monthly',
    'patient_name'    => null,
    'day_rate'        => null,
    'billing_freq'    => null,
    'shift_type'      => null,
    'schedule'        => null,
    'entity'          => null,
    'first_seen'      => '2026-01-01',
    'last_seen'       => '2026-03-01',
    'months_active'   => 3,
    'status'          => 'Active',
    'created_at'      => '2026-04-09 18:00:52',
    'updated_at'      => '2026-04-09 18:00:52',
];

// Guard: don't backfill if an audit entry already exists for this
// entity_id + action pair (re-run safety)
$exists = $db->prepare(
    "SELECT id FROM activity_log
     WHERE entity_type = 'clients' AND entity_id = 3 AND action = 'record_deleted'
     LIMIT 1"
);
$exists->execute();
if ($exists->fetchColumn()) {
    echo "   Already backfilled. Skipping.\n\n";
} else {
    $summary = 'Deleted loser clients.id 3 "Andre Theron- monthly" as part of '
             . 'merge into 2 (billing frequency suffix) '
             . '[BACKFILLED — original delete was not captured due to whitelist bug]';
    logActivity(
        'record_deleted',
        'dedup_clients',
        'clients',
        3,
        $summary,
        $id3Row,   // before = the full lost row
        null       // after = nothing
    );
    $newId = (int)$db->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    echo "   Audit entry written: activity_log.id={$newId}\n\n";
}

// ── 2. Properly delete orphaned clients.id=6 ────────────────────────────
echo "2. Deleting orphaned clients.id=6 (Angela/ Dimitri Paoadopoulos- weekly) via activity_log_delete()\n";

// Confirm it still exists and has zero dependents
$stillThere = $db->prepare('SELECT client_name FROM clients WHERE id = 6');
$stillThere->execute();
$name6 = $stillThere->fetchColumn();

if ($name6 === false) {
    echo "   clients.id=6 no longer exists. Skipping.\n\n";
} else {
    $rev6 = (int)$db->query('SELECT COUNT(*) FROM client_revenue WHERE client_id = 6')->fetchColumn();
    $ros6 = (int)$db->query('SELECT COUNT(*) FROM daily_roster WHERE client_id = 6')->fetchColumn();
    if ($rev6 > 0 || $ros6 > 0) {
        fwrite(STDERR, "   ABORT: clients.id=6 still has {$rev6} revenue + {$ros6} roster rows.\n");
        exit(3);
    }

    $delSummary = 'Deleted loser clients.id 6 "' . $name6 . '" as part of merge into 5 '
                . '(billing frequency suffix + Papadopoulos typo fix) '
                . '[RECOVERY RUN — original attempt hit whitelist bug]';

    $result = activity_log_delete('clients', 6, 'dedup_clients', $delSummary);
    if (!$result['ok']) {
        fwrite(STDERR, "   ABORT: " . $result['message'] . "\n");
        exit(3);
    }
    $newId = (int)$db->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    echo "   Deleted. Audit entry: activity_log.id={$newId}\n\n";
}

// ── 3. Rename clients.id=5 to fix the Papadopoulos typo ────────────────
echo "3. Renaming clients.id=5 Paoadopoulos → Papadopoulos\n";

$before5 = $db->prepare('SELECT client_name FROM clients WHERE id = 5');
$before5->execute();
$current = (string)$before5->fetchColumn();
$target  = 'Angela/ Dimitri Papadopoulos';

if ($current === '') {
    fwrite(STDERR, "   ABORT: clients.id=5 not found.\n");
    exit(3);
}
if ($current === $target) {
    echo "   Already at target name. Skipping.\n\n";
} else {
    $upd = $db->prepare('UPDATE clients SET client_name = ? WHERE id = ?');
    $upd->execute([$target, 5]);

    logActivity(
        'client_renamed',
        'dedup_clients',
        'clients',
        5,
        'Renamed "' . $current . '" → "' . $target . '" (typo fix during dedup)',
        ['client_name' => $current],
        ['client_name' => $target]
    );
    $newId = (int)$db->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    echo "   Renamed. Audit entry: activity_log.id={$newId}\n\n";
}

echo "══════════════════════════════════════════════════\n";
echo "  RECOVERY COMPLETE\n";
echo "══════════════════════════════════════════════════\n";
