<?php
/**
 * Activity log revert helpers (Level 1 — single-field revert).
 *
 * Used by the activity log detail page to let an authorised user revert a
 * single field of a record back to the value it held before a specific
 * logged change. Writes a NEW activity_log entry so the revert itself is
 * part of the audit trail — the original entry is never mutated.
 *
 * Safety checks:
 * - Only whitelisted entity_types can be reverted. Anything synthetic
 *   (role_permissions matrix, user_invites, email_log, activity_log itself)
 *   is excluded.
 * - Only real columns on the target table can be reverted. Synthetic diff
 *   fields (e.g. enquiries.note_appended) are rejected.
 * - The current value of the field on the live record MUST match the
 *   "after" value captured in the log. If the field has been changed
 *   *again* since the logged event, the revert is refused with a warning
 *   so we don't silently overwrite newer work.
 * - The live record must still exist. Deleted records are handled by
 *   A4 (undelete), not A2.
 * - Callers must enforce the permission gate before invoking the helper.
 */

if (!defined('APP_ROOT')) {
    die('Direct access not permitted.');
}

require_once APP_ROOT . '/includes/activity_log_render.php';

/**
 * Map of entity_type → [table, primary_key_column].
 *
 * Only entity types in this map can be reverted. Add new rows only after
 * confirming: (a) the table has a single-column integer PK, (b) all fields
 * logged for this entity_type are real columns on the table (not synthetic
 * diff fields), and (c) reverting a single field makes semantic sense for
 * this entity.
 */
function activity_revert_supported_entity_types(): array {
    return [
        'users'       => ['table' => 'users',       'pk' => 'id'],
        'enquiries'   => ['table' => 'enquiries',   'pk' => 'id'],
        'caregivers'  => ['table' => 'caregivers',  'pk' => 'id'],
        'name_lookup' => ['table' => 'name_lookup', 'pk' => 'id'],
    ];
}

/**
 * True if the given entity_type is eligible for single-field revert.
 */
function activity_revert_entity_is_supported(?string $entityType): bool {
    if ($entityType === null || $entityType === '') {
        return false;
    }
    return array_key_exists($entityType, activity_revert_supported_entity_types());
}

/**
 * True if $field is a real column on $table. Uses INFORMATION_SCHEMA so we
 * can't be SQL-injected via the field name (no string interpolation in the
 * actual UPDATE — the whitelist result controls which literal column name
 * the caller builds the query with).
 */
function activity_field_is_valid_column(string $table, string $field): bool {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1'
    );
    $stmt->execute([$table, $field]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Attempt to revert a single field on the record pointed to by an
 * activity_log entry, back to the "before" value captured at the time.
 *
 * @param int    $logId  PK of the activity_log row
 * @param string $field  Field name to revert
 * @return array{ok: bool, message: string, new_log_id: int|null}
 *         ok=true  → the field was reverted; new_log_id is the id of the
 *                    audit entry written for the revert action.
 *         ok=false → message explains why. No DB change was made.
 */
function activity_revert_field(int $logId, string $field): array {
    $db = getDB();

    // 1. Load the target log row
    $stmt = $db->prepare('SELECT * FROM activity_log WHERE id = ?');
    $stmt->execute([$logId]);
    $log = $stmt->fetch();
    if (!$log) {
        return ['ok' => false, 'message' => 'Log entry not found.', 'new_log_id' => null];
    }

    // 2. Entity_type must be in the whitelist
    $entityType = $log['entity_type'] ?? null;
    $entityId   = $log['entity_id']   ?? null;
    if (!activity_revert_entity_is_supported($entityType)) {
        return [
            'ok' => false,
            'message' => 'This entity type (' . ($entityType ?: '—') . ') does not support single-field revert.',
            'new_log_id' => null,
        ];
    }
    if (!$entityId) {
        return ['ok' => false, 'message' => 'Log entry has no target record id.', 'new_log_id' => null];
    }

    $map   = activity_revert_supported_entity_types();
    $table = $map[$entityType]['table'];
    $pkCol = $map[$entityType]['pk'];

    // 3. Field must be a real column on the target table
    if (!activity_field_is_valid_column($table, $field)) {
        return [
            'ok' => false,
            'message' => 'Field "' . $field . '" is not a real column on ' . $table . '. ' .
                         '(Synthetic diff fields like "note_appended" cannot be reverted.)',
            'new_log_id' => null,
        ];
    }

    // 4. Decode the before/after snapshots and make sure the field is present
    $before = activity_decode_snapshot($log['before_json'] ?? null);
    $after  = activity_decode_snapshot($log['after_json']  ?? null);
    if ($before === null || $after === null) {
        return [
            'ok' => false,
            'message' => 'Log entry has no before/after snapshot to revert from.',
            'new_log_id' => null,
        ];
    }
    if (!array_key_exists($field, $before) || !array_key_exists($field, $after)) {
        return [
            'ok' => false,
            'message' => 'Field "' . $field . '" was not captured in this log entry.',
            'new_log_id' => null,
        ];
    }
    $oldValue = $before[$field]; // what we want to restore
    $newValue = $after[$field];  // what was set at the time of the logged action

    // 5. Load the live record and confirm it still exists
    //    Table name comes from a hardcoded whitelist (not user input), so
    //    interpolation is safe here; the column name comes from a whitelist
    //    too (activity_field_is_valid_column checked it above).
    $liveStmt = $db->prepare("SELECT * FROM `$table` WHERE `$pkCol` = ? LIMIT 1");
    $liveStmt->execute([$entityId]);
    $live = $liveStmt->fetch();
    if (!$live) {
        return [
            'ok' => false,
            'message' => "The $entityType record #$entityId no longer exists. Use undelete (A4) once it's available.",
            'new_log_id' => null,
        ];
    }

    // 6. Intermediate-edit check: live value MUST still equal $newValue
    //    (string compare, same as activity_compute_diff uses). If the field
    //    has been edited AGAIN since the logged action, the live value won't
    //    match and we refuse — we don't want to silently clobber newer work.
    $liveValue = $live[$field] ?? null;
    if ((string)($liveValue ?? '') !== (string)($newValue ?? '')) {
        return [
            'ok' => false,
            'message' => 'This field has been changed since the logged action. ' .
                         'Current value: "' . (string)($liveValue ?? '') . '" — ' .
                         'expected to find: "' . (string)($newValue ?? '') . '". ' .
                         'Refusing to overwrite newer work.',
            'new_log_id' => null,
        ];
    }

    // 7. Apply the revert. Use the whitelisted table + column in the SQL.
    //    $oldValue is parameterised so it can be any literal value safely.
    try {
        $upd = $db->prepare("UPDATE `$table` SET `$field` = ? WHERE `$pkCol` = ?");
        $upd->execute([$oldValue, $entityId]);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'message' => 'Database rejected the revert: ' . $e->getMessage(),
            'new_log_id' => null,
        ];
    }

    // 8. Write the revert as its own activity_log entry so the audit trail
    //    preserves BOTH the original action and the revert action. The
    //    before/after on this new entry shows the revert itself — current
    //    (before revert) → old (after revert).
    $summary = sprintf(
        'Reverted %s #%d field "%s" (from activity log #%d)',
        $entityType,
        (int)$entityId,
        $field,
        (int)$logId
    );
    logActivity(
        'field_reverted',
        'activity_log',
        $entityType,
        (int)$entityId,
        $summary,
        [$field => $newValue],
        [$field => $oldValue]
    );

    // logActivity() swallows its own errors, so we can't get the new id
    // back from it. Fetch the most recent activity_log row written by the
    // current session as a best-effort id for the return value. This is
    // only used for the flash message; correctness doesn't depend on it.
    $newLogId = (int)$db->query('SELECT LAST_INSERT_ID()')->fetchColumn();

    return [
        'ok' => true,
        'message' => 'Field "' . $field . '" reverted. A new audit entry was created recording the revert.',
        'new_log_id' => $newLogId ?: null,
    ];
}
