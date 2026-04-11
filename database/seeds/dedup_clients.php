<?php
/**
 * TCH Placements — Patient Dedup Helper (one-shot merge tool)
 * ----------------------------------------------------------
 *
 * Merges two `clients` rows that refer to the same real patient
 * (typically caused by billing-frequency suffix duplication like
 * "Andre Theron" + "Andre Theron- monthly", or source-data typos).
 *
 * Each invocation merges exactly ONE pair:
 *   - Loads the survivor + loser rows and prints them.
 *   - Counts the affected client_revenue + daily_roster rows.
 *   - Opens a transaction:
 *       1. Repoints client_revenue.client_id  loser → survivor
 *       2. Repoints daily_roster.client_id    loser → survivor
 *       3. Logs the merge as a `client_merged` activity_log entry
 *          with before/after JSON snapshots.
 *       4. Deletes the loser row via activity_log_delete() so the
 *          full row is captured in the audit log (undeletable from
 *          the log entry later if we get it wrong).
 *   - Commits and reports counts.
 *
 * Usage (from the dev-TCH webroot on the server):
 *
 *     php database/seeds/dedup_clients.php \
 *         --loser=3 --survivor=2 \
 *         --reason="billing frequency suffix"
 *
 * Optional:
 *
 *     --dry-run           Show what would happen without writing.
 *     --user-id=N         Attribute the audit entries to user N.
 *                         Defaults to 1 (Ross, seed super admin).
 *     --survivor-name=... Also rename the survivor's client_name to
 *                         this canonical spelling as part of the
 *                         merge (captured as a separate UPDATE with
 *                         its own audit entry). Handy for fixing
 *                         typos like "Elizabth" → "Elizabeth" at
 *                         the same time as the merge.
 *
 * Exit codes:
 *   0  success
 *   1  usage / argument error
 *   2  row not found or invalid
 *   3  DB error (transaction rolled back)
 *
 * This script is a ONE-SHOT CLEANUP. It will be deleted once the
 * patient dedup exercise is complete and migration 007 has moved
 * the surviving client rows into `persons` as `person_type='patient,client'`.
 */

declare(strict_types=1);

// ── Bootstrap ────────────────────────────────────────────────────────────
define('APP_ROOT', dirname(__DIR__, 2));

require_once APP_ROOT . '/includes/config.php';
require_once APP_ROOT . '/includes/db.php';
require_once APP_ROOT . '/includes/auth.php';              // also loads permissions.php
require_once APP_ROOT . '/includes/activity_log_revert.php'; // also loads activity_log_render.php

// ── Parse CLI args ───────────────────────────────────────────────────────
function arg(string $name, ?string $default = null): ?string {
    global $argv;
    foreach ($argv as $a) {
        if (strpos($a, "--{$name}=") === 0) {
            return substr($a, strlen($name) + 3);
        }
        if ($a === "--{$name}") {
            return '1';
        }
    }
    return $default;
}

$loserId       = (int)(arg('loser', '0') ?? 0);
$survivorId    = (int)(arg('survivor', '0') ?? 0);
$reason        = (string)(arg('reason', '') ?? '');
$userId        = (int)(arg('user-id', '1') ?? 1);
$survivorName  = arg('survivor-name');
$dryRun        = (arg('dry-run') !== null);

function fail(int $code, string $msg): void {
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit($code);
}

if ($loserId <= 0 || $survivorId <= 0) {
    fail(1, 'Both --loser=N and --survivor=N are required.');
}
if ($loserId === $survivorId) {
    fail(1, 'Loser and survivor cannot be the same id.');
}
if ($reason === '') {
    fail(1, '--reason="..." is required (becomes the audit entry summary).');
}

// ── Fake a session so logActivity()/activity_log_delete() pick up the
//    acting user. Normally logActivity reads $_SESSION['user_id']; in CLI
//    there's no session, so we set it explicitly to the --user-id arg.
// ────────────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    // Don't actually start a session cookie — just populate $_SESSION in-memory
    $_SESSION = [];
}
$_SESSION['user_id'] = $userId;

$db = getDB();

// ── 1. Load both rows ────────────────────────────────────────────────────
$survivor = $db->prepare('SELECT * FROM clients WHERE id = ?');
$survivor->execute([$survivorId]);
$survivorRow = $survivor->fetch(PDO::FETCH_ASSOC);
if (!$survivorRow) {
    fail(2, "Survivor client id={$survivorId} not found.");
}

$loser = $db->prepare('SELECT * FROM clients WHERE id = ?');
$loser->execute([$loserId]);
$loserRow = $loser->fetch(PDO::FETCH_ASSOC);
if (!$loserRow) {
    fail(2, "Loser client id={$loserId} not found.");
}

// ── 2. Count affected dependent rows ─────────────────────────────────────
$revCount = (int)$db->query(
    "SELECT COUNT(*) FROM client_revenue WHERE client_id = {$loserId}"
)->fetchColumn();

$rosCount = (int)$db->query(
    "SELECT COUNT(*) FROM daily_roster WHERE client_id = {$loserId}"
)->fetchColumn();

// ── 3. Print the plan ────────────────────────────────────────────────────
echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║  CLIENT MERGE PLAN" . ($dryRun ? " (DRY RUN)" : "") . "\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "  SURVIVOR  id={$survivorId}\n";
echo "    account:      " . (string)$survivorRow['account_number'] . "\n";
echo "    client_name:  " . (string)$survivorRow['client_name']    . "\n";
if ($survivorName !== null && $survivorName !== (string)$survivorRow['client_name']) {
    echo "    RENAME TO:    {$survivorName}\n";
}
echo "\n";
echo "  LOSER     id={$loserId}\n";
echo "    account:      " . (string)$loserRow['account_number']    . "\n";
echo "    client_name:  " . (string)$loserRow['client_name']       . "\n";
echo "\n";
echo "  DEPENDENT ROWS TO REPOINT\n";
echo "    client_revenue:  {$revCount}\n";
echo "    daily_roster:    {$rosCount}\n";
echo "\n";
echo "  REASON           {$reason}\n";
echo "  ACTING USER      id={$userId}\n";
echo "\n";

if ($dryRun) {
    echo "  DRY RUN — no changes applied. Re-run without --dry-run to commit.\n";
    exit(0);
}

// ── 4. Transaction: repoint + log + delete loser ─────────────────────────
try {
    $db->beginTransaction();

    // 4a. Repoint client_revenue
    $upd1 = $db->prepare(
        'UPDATE client_revenue SET client_id = ? WHERE client_id = ?'
    );
    $upd1->execute([$survivorId, $loserId]);
    $revRepointed = $upd1->rowCount();

    // 4b. Repoint daily_roster
    $upd2 = $db->prepare(
        'UPDATE daily_roster SET client_id = ? WHERE client_id = ?'
    );
    $upd2->execute([$survivorId, $loserId]);
    $rosRepointed = $upd2->rowCount();

    // 4c. Optional: rename the survivor (handles typo fix in the same step)
    $rename = null;
    if ($survivorName !== null && $survivorName !== (string)$survivorRow['client_name']) {
        $renameStmt = $db->prepare(
            'UPDATE clients SET client_name = ? WHERE id = ?'
        );
        $renameStmt->execute([$survivorName, $survivorId]);
        $rename = [
            'from' => (string)$survivorRow['client_name'],
            'to'   => $survivorName,
        ];
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fail(3, 'DB error during repoint, transaction rolled back: ' . $e->getMessage());
}

// ── 5. Write the merge audit entry (AFTER commit, per standing rule) ─────
$mergeSummary = sprintf(
    'Merged clients.id %d "%s" → %d "%s" (%s)',
    $loserId,
    (string)$loserRow['client_name'],
    $survivorId,
    (string)$survivorRow['client_name'],
    $reason
);

$beforeSnap = [
    'loser_id'          => $loserId,
    'loser_name'        => (string)$loserRow['client_name'],
    'loser_account'     => (string)$loserRow['account_number'],
    'survivor_id'       => $survivorId,
    'survivor_name'     => (string)$survivorRow['client_name'],
    'reason'            => $reason,
];
$afterSnap = [
    'survivor_id'       => $survivorId,
    'survivor_name'     => $rename['to'] ?? (string)$survivorRow['client_name'],
    'client_revenue_repointed' => $revRepointed,
    'daily_roster_repointed'   => $rosRepointed,
];
if ($rename !== null) {
    $afterSnap['rename_from'] = $rename['from'];
    $afterSnap['rename_to']   = $rename['to'];
}

logActivity(
    'client_merged',
    'dedup_clients',
    'clients',
    $survivorId,
    $mergeSummary,
    $beforeSnap,
    $afterSnap
);
$mergeLogId = (int)$db->query('SELECT LAST_INSERT_ID()')->fetchColumn();

// ── 6. Delete the loser row via the audit-aware helper ───────────────────
//     Captures the full loser row as before_json in a record_deleted audit
//     entry so the row is recoverable via activity_undelete() if we merged
//     wrong.
$deleteSummary = sprintf(
    'Deleted loser clients.id %d "%s" as part of merge into %d (%s)',
    $loserId,
    (string)$loserRow['client_name'],
    $survivorId,
    $reason
);
$delResult = activity_log_delete(
    'clients',
    $loserId,
    'dedup_clients',
    $deleteSummary
);

if (!$delResult['ok']) {
    fwrite(STDERR, "WARNING: Loser repointed OK but activity_log_delete failed: " . $delResult['message'] . "\n");
    fwrite(STDERR, "         The loser row still exists with client_id={$loserId} and no dependent rows.\n");
    fwrite(STDERR, "         Run: DELETE FROM clients WHERE id={$loserId}; manually once resolved.\n");
    exit(3);
}

$deleteLogId = (int)$db->query('SELECT LAST_INSERT_ID()')->fetchColumn();

// ── 7. Report ────────────────────────────────────────────────────────────
echo "  COMMITTED ✓\n";
echo "    client_revenue rows repointed:  {$revRepointed}\n";
echo "    daily_roster rows repointed:    {$rosRepointed}\n";
if ($rename !== null) {
    echo "    survivor renamed:               \"{$rename['from']}\" → \"{$rename['to']}\"\n";
}
echo "    merge audit entry:              activity_log.id={$mergeLogId}\n";
echo "    loser captured + deleted:       activity_log.id={$deleteLogId} (undeletable from this entry)\n";
echo "\n";
echo "  Done. Move to the next pair.\n";

exit(0);
