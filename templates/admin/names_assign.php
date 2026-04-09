<?php
/**
 * Handles assigning an unmatched billing name to a canonical name lookup record.
 * Updates the name_lookup.billing_name and re-links caregiver_costs records.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/admin/names');
    exit;
}

initSession();

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    header('Location: ' . APP_URL . '/admin/names');
    exit;
}

$db = getDB();
$lookupId    = (int)($_POST['lookup_id'] ?? 0);
$billingName = trim($_POST['billing_name'] ?? '');

if ($lookupId <= 0 || $billingName === '') {
    header('Location: ' . APP_URL . '/admin/names');
    exit;
}

// Get the lookup record
$stmt = $db->prepare('SELECT * FROM name_lookup WHERE id = ?');
$stmt->execute([$lookupId]);
$lookup = $stmt->fetch();

if (!$lookup) {
    header('Location: ' . APP_URL . '/admin/names');
    exit;
}

// Update the billing name on the lookup record
$stmt = $db->prepare('UPDATE name_lookup SET billing_name = ?, updated_at = NOW() WHERE id = ?');
$stmt->execute([$billingName, $lookupId]);

// If this lookup has a linked caregiver_id, update caregiver_costs records
if ($lookup['caregiver_id']) {
    $stmt = $db->prepare('UPDATE caregiver_costs SET caregiver_id = ? WHERE caregiver_name = ? AND caregiver_id IS NULL');
    $stmt->execute([$lookup['caregiver_id'], $billingName]);

    // Also update daily_roster
    $stmt = $db->prepare('UPDATE daily_roster SET caregiver_id = ? WHERE caregiver_name = ? AND caregiver_id IS NULL');
    $stmt->execute([$lookup['caregiver_id'], $billingName]);

    // And banking
    $stmt = $db->prepare('UPDATE caregiver_banking SET caregiver_id = ? WHERE id IN (
        SELECT id FROM (SELECT cb.id FROM caregiver_banking cb WHERE cb.caregiver_id IS NULL) tmp
    ) AND ? != ""');
    // Banking matches by name not stored here — skip for now
}

header('Location: ' . APP_URL . '/admin/names');
exit;
