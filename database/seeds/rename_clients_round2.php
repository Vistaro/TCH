<?php
/**
 * One-shot rename helper for patient dedup Round 2.
 *
 * Applies five client_name renames (no merges) with full audit entries:
 *
 *   id=19  "Gildenhyus"          → "Gildenhuys"          (typo)
 *   id=24  "Ishaan/Elizabth"     → "Ishaan/Elizabeth"    (typo)
 *   id=51  "Oosthuizen- Weekly"  → "Oosthuizen"          (suffix strip)
 *   id=54  "Roux- Esme"          → "Esme Roux"           (re-order)
 *   id=63  "Webb- Sonja"         → "Sonja Webb"          (re-order)
 *
 * Each rename writes a `client_renamed` activity_log entry with
 * before/after so the change is auditable and revertable.
 *
 * Idempotent: if a row is already at its target name, skip it.
 *
 * Run ONCE then delete.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));

require_once APP_ROOT . '/includes/config.php';
require_once APP_ROOT . '/includes/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/activity_log_revert.php';

if (session_status() === PHP_SESSION_NONE) {
    $_SESSION = [];
}
$_SESSION['user_id'] = 1;

$db = getDB();

$renames = [
    ['id' => 19, 'target' => 'Gildenhuys',       'reason' => 'typo fix'],
    ['id' => 24, 'target' => 'Ishaan/Elizabeth', 'reason' => 'typo fix (Elizabth → Elizabeth)'],
    ['id' => 51, 'target' => 'Oosthuizen',       'reason' => 'strip "- Weekly" billing suffix'],
    ['id' => 54, 'target' => 'Esme Roux',        'reason' => 'reorder "Roux- Esme" to FirstName Surname'],
    ['id' => 63, 'target' => 'Sonja Webb',       'reason' => 'reorder "Webb- Sonja" to FirstName Surname'],
];

echo "══════════════════════════════════════════════════\n";
echo "  ROUND 2 — CLIENT RENAMES\n";
echo "══════════════════════════════════════════════════\n\n";

$sel = $db->prepare('SELECT client_name FROM clients WHERE id = ?');
$upd = $db->prepare('UPDATE clients SET client_name = ? WHERE id = ?');

foreach ($renames as $r) {
    $sel->execute([$r['id']]);
    $current = $sel->fetchColumn();

    if ($current === false) {
        fwrite(STDERR, "  id={$r['id']} NOT FOUND — skipping\n");
        continue;
    }
    if ($current === $r['target']) {
        echo "  id={$r['id']} already at '{$r['target']}' — skipping\n";
        continue;
    }

    $upd->execute([$r['target'], $r['id']]);
    logActivity(
        'client_renamed',
        'dedup_clients',
        'clients',
        (int)$r['id'],
        sprintf('Renamed "%s" → "%s" (%s)', $current, $r['target'], $r['reason']),
        ['client_name' => $current],
        ['client_name' => $r['target']]
    );
    $logId = (int)$db->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    echo "  id={$r['id']} \"{$current}\" → \"{$r['target']}\"  [audit={$logId}]\n";
}

echo "\nDONE.\n";
