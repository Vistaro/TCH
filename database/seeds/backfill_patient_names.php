<?php
/**
 * One-shot backfill of clients.patient_name (2026-04-11).
 *
 * Rules (per Ross):
 *   - If patient_name is already set (non-NULL, non-empty) → leave alone.
 *   - Else if client_name contains "/" → split:
 *       client_name  = everything BEFORE the first slash (trimmed)
 *       patient_name = everything AFTER the first slash (trimmed)
 *   - Else → patient_name = client_name (same person)
 *
 * Every update is logged via logActivity() so the full change is
 * auditable and revertable. Action = 'client_patient_backfilled'.
 *
 * Idempotent: rows that already have patient_name set are skipped, so
 * re-running is harmless.
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

echo "══════════════════════════════════════════════════\n";
echo "  PATIENT NAME BACKFILL — 2026-04-11\n";
echo "══════════════════════════════════════════════════\n\n";

// Pull every row where patient_name is NULL or empty.
$rows = $db->query(
    "SELECT id, client_name, patient_name
     FROM clients
     WHERE patient_name IS NULL OR patient_name = ''
     ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

echo "Rows to process: " . count($rows) . "\n\n";

$splitCount   = 0;
$mirrorCount  = 0;
$skippedCount = 0;

$upd = $db->prepare(
    'UPDATE clients
     SET client_name = ?, patient_name = ?
     WHERE id = ?'
);

foreach ($rows as $row) {
    $id          = (int)$row['id'];
    $origClient  = (string)$row['client_name'];
    $origPatient = $row['patient_name']; // null or ''

    if (strpos($origClient, '/') !== false) {
        // Split on the first slash
        [$leftRaw, $rightRaw] = explode('/', $origClient, 2);
        $newClient  = trim($leftRaw);
        $newPatient = trim($rightRaw);

        if ($newClient === '' || $newPatient === '') {
            echo "  id={$id}  SKIP (empty side after split): \"{$origClient}\"\n";
            $skippedCount++;
            continue;
        }

        $upd->execute([$newClient, $newPatient, $id]);
        logActivity(
            'client_patient_backfilled',
            'dedup_clients',
            'clients',
            $id,
            sprintf(
                'Split "%s" → client "%s", patient "%s" (slash-split backfill)',
                $origClient, $newClient, $newPatient
            ),
            ['client_name' => $origClient, 'patient_name' => $origPatient],
            ['client_name' => $newClient,  'patient_name' => $newPatient]
        );
        $logId = (int)$db->query('SELECT LAST_INSERT_ID()')->fetchColumn();
        echo "  id={$id}  SPLIT   \"{$origClient}\" → client=\"{$newClient}\", patient=\"{$newPatient}\"  [audit={$logId}]\n";
        $splitCount++;
    } else {
        // No slash → patient is the same as client
        $newPatient = $origClient;
        $upd->execute([$origClient, $newPatient, $id]);
        logActivity(
            'client_patient_backfilled',
            'dedup_clients',
            'clients',
            $id,
            sprintf('Mirrored client_name "%s" to patient_name', $origClient),
            ['client_name' => $origClient, 'patient_name' => $origPatient],
            ['client_name' => $origClient, 'patient_name' => $newPatient]
        );
        $mirrorCount++;
    }
}

echo "\n──────────────────────────────────────────────────\n";
echo "  Split rows:    {$splitCount}\n";
echo "  Mirror rows:   {$mirrorCount}\n";
echo "  Skipped:       {$skippedCount}\n";
echo "  Untouched (already had patient_name): "
     . ((int)$db->query("SELECT COUNT(*) FROM clients WHERE patient_name IS NOT NULL AND patient_name != ''")->fetchColumn() - $splitCount - $mirrorCount)
     . "\n";
echo "══════════════════════════════════════════════════\n";
