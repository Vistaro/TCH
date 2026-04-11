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
        // Both 'caregivers' and 'persons' are listed: the `caregivers`
        // table is being renamed to `persons` in migration 006. Until
        // that migration runs, the server sees the old table name;
        // after it runs, the new name. Keeping both lets this file
        // deploy safely both before AND after the migration. Dead
        // entries do no harm (nothing selects by entity_type that
        // doesn't also match a real row). Remove 'caregivers' once
        // migration 006 is live and any residual code paths have been
        // cleared.
        'caregivers'  => ['table' => 'caregivers', 'pk' => 'id'],
        'persons'     => ['table' => 'persons',    'pk' => 'id'],
        'name_lookup' => ['table' => 'name_lookup', 'pk' => 'id'],
        // 'clients' was on the whitelist temporarily for the 2026-04-11
        // patient dedup exercise. Migration 007 has since moved the
        // clients into persons and rewritten every historical
        // activity_log entry so entity_type='persons' + the new
        // persons.id. The clients table itself is renamed to
        // clients_deprecated_2026_04_11 and nothing queries it.
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

// ═════════════════════════════════════════════════════════════════════════
// A3 — Whole-record rollback (Level 2)
// ═════════════════════════════════════════════════════════════════════════
//
// Restore a record to the state it was in just before a specific log entry
// was applied. This walks the activity_log forward from the chosen entry
// (inclusive) to the present for the same entity, takes the EARLIEST
// `before` value for each field that was touched, and builds that as the
// target state. Applying the plan sets every field in the target state
// back to its at-that-time value in one UPDATE, and records a single
// `record_rolled_back` audit entry.
//
// Semantics:
// - "Fields touched BY entry X": target value = X's before[field] (obviously)
// - "Fields touched AFTER X by a later entry": target value = that later
//   entry's before[field] — because the earliest before-value we see is
//   the state the field held BEFORE it was first touched post-X, which is
//   exactly state-at-time-of-X.
// - "Fields never touched from X onwards": not included in the plan —
//   they're still at their at-time-of-X value, no action needed.
//
// Intermediate-edit warning:
// - A field is flagged as "intermediate-edited" if it appears in more than
//   one log entry in the range [X, now]. Rolling back will undo ALL of
//   those changes, not just entry X's change. The UI shows this count so
//   Ross can see exactly what newer work he's about to discard.
//
// Safety:
// - Gated to Super Admin only (isSuperAdmin()) because A3 can discard
//   newer work.
// - Same entity-type whitelist as A2.
// - Same column-name whitelist as A2 — synthetic diff fields (e.g.
//   enquiries.note_appended) are silently dropped from the plan because
//   you can't UPDATE a column that doesn't exist.
// - The target record must still exist — otherwise we point at A4.
// - The rollback itself runs as a single UPDATE inside a transaction so
//   partial failures don't leave the record half-rolled-back.

/**
 * Compute a rollback plan for a given activity log entry.
 *
 * @param int $logId
 * @return array{
 *   ok: bool,
 *   message: string,
 *   log_row: array|null,
 *   entity_type: string|null,
 *   entity_id: int|null,
 *   table: string|null,
 *   pk: string|null,
 *   target_state: array<string,mixed>,   // field => target value
 *   current_state: array<string,mixed>,  // field => current live value
 *   intermediate_edits: array<string,int>, // field => entries-touching-count (>=2 means intermediate edits)
 *   dropped_fields: array<string>,       // fields skipped because they're synthetic (not real columns)
 * }
 */
function activity_rollback_compute_plan(int $logId): array {
    $db = getDB();

    $base = [
        'ok' => false,
        'message' => '',
        'log_row' => null,
        'entity_type' => null,
        'entity_id' => null,
        'table' => null,
        'pk' => null,
        'target_state' => [],
        'current_state' => [],
        'intermediate_edits' => [],
        'dropped_fields' => [],
    ];

    // 1. Load the source log row
    $stmt = $db->prepare('SELECT * FROM activity_log WHERE id = ?');
    $stmt->execute([$logId]);
    $log = $stmt->fetch();
    if (!$log) {
        return ['ok' => false, 'message' => 'Log entry not found.'] + $base;
    }
    $base['log_row'] = $log;

    // 2. Entity whitelist
    $entityType = $log['entity_type'] ?? null;
    $entityId   = $log['entity_id']   ?? null;
    if (!activity_revert_entity_is_supported($entityType)) {
        return ['ok' => false,
                'message' => 'This entity type (' . ($entityType ?: '—') . ') does not support whole-record rollback.'] + $base;
    }
    if (!$entityId) {
        return ['ok' => false, 'message' => 'Log entry has no target record id.'] + $base;
    }
    $map   = activity_revert_supported_entity_types();
    $table = $map[$entityType]['table'];
    $pkCol = $map[$entityType]['pk'];

    $base['entity_type'] = $entityType;
    $base['entity_id']   = (int)$entityId;
    $base['table']       = $table;
    $base['pk']          = $pkCol;

    // 3. Source log entry must carry a before snapshot
    $sourceBefore = activity_decode_snapshot($log['before_json'] ?? null);
    if ($sourceBefore === null) {
        return ['ok' => false,
                'message' => 'Log entry has no before-snapshot to roll back to.'] + $base;
    }

    // 4. Walk all entries for this entity from logId forward (inclusive).
    //    Oldest first — the earliest `before` value for each field wins.
    $walk = $db->prepare(
        'SELECT id, before_json, after_json
         FROM activity_log
         WHERE entity_type = ? AND entity_id = ? AND id >= ?
         ORDER BY id ASC'
    );
    $walk->execute([$entityType, (int)$entityId, (int)$logId]);
    $entries = $walk->fetchAll();

    $targetState       = [];
    $touchCounts       = [];
    $droppedFields     = [];

    foreach ($entries as $e) {
        $b = activity_decode_snapshot($e['before_json'] ?? null);
        if ($b === null) {
            continue;
        }
        foreach ($b as $field => $oldVal) {
            // Count every touch so we can flag intermediate edits
            $touchCounts[$field] = ($touchCounts[$field] ?? 0) + 1;

            // Earliest-before wins
            if (!array_key_exists($field, $targetState)) {
                // Drop synthetic diff fields that aren't real columns on the
                // target table — they can't be rolled back.
                if (!activity_field_is_valid_column($table, (string)$field)) {
                    if (!in_array($field, $droppedFields, true)) {
                        $droppedFields[] = $field;
                    }
                    continue;
                }
                $targetState[$field] = $oldVal;
            }
        }
    }

    if (empty($targetState)) {
        return ['ok' => false,
                'message' => 'No revertable fields in the log range — nothing to roll back.'] + $base;
    }

    // 5. Load the live record
    $liveStmt = $db->prepare("SELECT * FROM `$table` WHERE `$pkCol` = ? LIMIT 1");
    $liveStmt->execute([$entityId]);
    $live = $liveStmt->fetch();
    if (!$live) {
        return ['ok' => false,
                'message' => "The $entityType record #$entityId no longer exists. Use undelete (A4) once it's available."] + $base;
    }

    // Build the current-state slice (only the fields we're planning to touch)
    $currentState = [];
    foreach ($targetState as $field => $_) {
        $currentState[$field] = $live[$field] ?? null;
    }

    // Prune fields that are already at their target value — no point
    // updating them, and they'll clutter the preview.
    foreach ($targetState as $field => $target) {
        if ((string)($currentState[$field] ?? '') === (string)($target ?? '')) {
            unset($targetState[$field]);
            unset($currentState[$field]);
        }
    }

    if (empty($targetState)) {
        return ['ok' => true,
                'message' => 'The record is already at this state — nothing to change.',
                'log_row' => $log,
                'entity_type' => $entityType,
                'entity_id' => (int)$entityId,
                'table' => $table,
                'pk' => $pkCol,
                'target_state' => [],
                'current_state' => [],
                'intermediate_edits' => [],
                'dropped_fields' => $droppedFields];
    }

    // 6. Intermediate-edit map — only for fields that are actually in the plan
    $intermediate = [];
    foreach ($targetState as $field => $_) {
        $count = $touchCounts[$field] ?? 1;
        if ($count >= 2) {
            $intermediate[$field] = $count;
        }
    }

    return [
        'ok' => true,
        'message' => '',
        'log_row' => $log,
        'entity_type' => $entityType,
        'entity_id' => (int)$entityId,
        'table' => $table,
        'pk' => $pkCol,
        'target_state' => $targetState,
        'current_state' => $currentState,
        'intermediate_edits' => $intermediate,
        'dropped_fields' => $droppedFields,
    ];
}

/**
 * Apply a rollback plan: UPDATE the record, write a single
 * `record_rolled_back` audit entry, return the result.
 *
 * Plan is recomputed from scratch (not trusted from POST) to avoid TOCTOU
 * between preview and apply — if the record changed between preview and
 * apply, the new plan may differ and we want the fresh one.
 *
 * @param int $logId
 * @return array{ok: bool, message: string}
 */
function activity_rollback_apply(int $logId): array {
    $plan = activity_rollback_compute_plan($logId);
    if (!$plan['ok']) {
        return ['ok' => false, 'message' => $plan['message']];
    }
    if (empty($plan['target_state'])) {
        return ['ok' => true, 'message' => 'Record already at target state — no rollback needed.'];
    }

    $db          = getDB();
    $table       = $plan['table'];
    $pkCol       = $plan['pk'];
    $entityId    = $plan['entity_id'];
    $entityType  = $plan['entity_type'];
    $targetState = $plan['target_state'];
    $currentState = $plan['current_state'];

    // Build the UPDATE with a parameterised SET clause. Field names are
    // already whitelisted by INFORMATION_SCHEMA (happens inside
    // activity_rollback_compute_plan); values are bound.
    $setParts = [];
    $params   = [];
    foreach ($targetState as $field => $target) {
        $setParts[] = "`$field` = ?";
        $params[]   = $target;
    }
    $params[] = $entityId;
    $sql = "UPDATE `$table` SET " . implode(', ', $setParts) . " WHERE `$pkCol` = ?";

    try {
        $db->beginTransaction();
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return [
            'ok' => false,
            'message' => 'Database rejected the rollback: ' . $e->getMessage(),
        ];
    }

    // Audit entry — one row per rollback, carrying the full before/after
    // snapshot so the activity log detail page can render the diff with
    // exactly the same machinery as every other mutation.
    $summary = sprintf(
        'Rolled back %s #%d to the state before activity log #%d (%d field%s)',
        $entityType,
        (int)$entityId,
        (int)$logId,
        count($targetState),
        count($targetState) === 1 ? '' : 's'
    );
    logActivity(
        'record_rolled_back',
        'activity_log',
        $entityType,
        (int)$entityId,
        $summary,
        $currentState, // before = what it was just before this rollback
        $targetState   // after = where the rollback sent it
    );

    return [
        'ok' => true,
        'message' => 'Rolled back ' . count($targetState) . ' field(s). A new audit entry records the rollback.',
    ];
}

// ═════════════════════════════════════════════════════════════════════════
// A4 — Undelete (Level 3)
// ═════════════════════════════════════════════════════════════════════════
//
// Two pieces to this feature:
//
// 1. activity_log_delete(): a convenience helper that future delete
//    handlers MUST use. It loads the full row of the record being deleted,
//    writes a `record_deleted` activity_log entry with the full row as
//    before_json, then runs the DELETE inside a transaction. After commit,
//    an undelete is possible by reading the log entry and re-inserting.
//
// 2. activity_undelete(): reads a `record_deleted` log entry and
//    re-inserts the row using the captured before_json. Refuses if:
//    - the log entry isn't a record_deleted action
//    - the entity_type isn't in the whitelist
//    - the target PK is already occupied (a new row has since taken it)
//    - any column in before_json isn't a real column on the table (schema
//      has drifted); the undelete will skip those columns and continue,
//      unless they're NOT NULL and have no default, in which case the
//      INSERT will fail and we roll back and report it.
//
// Limitations — stated loudly in the UI and the CHANGELOG:
//
// - Undelete ONLY works for records deleted AFTER v0.9.6-dev goes live.
//   Pre-existing deletes (none in TCH today) are not recoverable this way.
// - Only the primary row is restored. Related/cascaded children stay
//   gone. If you deleted an enquiry and the enquiry had notes stored in
//   a child table with ON DELETE CASCADE, the notes are gone. The row
//   comes back empty of those children.
// - Auto-increment counters are NOT reset on re-insert. MySQL is happy
//   with this — the next new insert just gets a higher id — but it means
//   undeleted rows live in a "gap" in the id sequence.
// - The restored row keeps its original created_at / updated_at values.
//   A fresh record_undeleted audit entry records when the restore
//   happened and who triggered it.

/**
 * Hard-delete helper — the standard way to delete a record in TCH.
 *
 * Loads the full row, writes a record_deleted audit entry with the full
 * row as before_json, then runs the DELETE in a transaction. If the
 * DELETE fails, the transaction rolls back and no audit entry is written.
 * If the audit write fails, logActivity() swallows it (same behaviour as
 * every other log call) so delete-with-silent-audit-failure is impossible
 * to introduce accidentally but also can't break delivery.
 *
 * Usage from a delete handler:
 *
 *     $result = activity_log_delete(
 *         'enquiries',          // entity_type (must be in whitelist)
 *         (int)$enqId,          // primary key value
 *         'enquiries',          // page_code for the audit entry
 *         'Deleted enquiry #' . $enqId . ' (' . $reason . ')'
 *     );
 *     if (!$result['ok']) { // handle failure — $result['message'] }
 *
 * @param string $entityType Must be in activity_revert_supported_entity_types()
 * @param int    $entityId   PK of the row to delete
 * @param string $pageCode   Page where the delete was triggered
 * @param string $summary    Human-readable one-liner for the audit entry
 * @return array{ok: bool, message: string, captured_row: array|null}
 */
function activity_log_delete(
    string $entityType,
    int $entityId,
    string $pageCode,
    string $summary
): array {
    if (!activity_revert_entity_is_supported($entityType)) {
        return [
            'ok' => false,
            'message' => 'Entity type "' . $entityType . '" is not on the delete whitelist. ' .
                         'Add it to activity_revert_supported_entity_types() first.',
            'captured_row' => null,
        ];
    }

    $map   = activity_revert_supported_entity_types();
    $table = $map[$entityType]['table'];
    $pkCol = $map[$entityType]['pk'];

    $db = getDB();

    // 1. Snapshot the full row BEFORE deleting
    $sel = $db->prepare("SELECT * FROM `$table` WHERE `$pkCol` = ? LIMIT 1");
    $sel->execute([$entityId]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [
            'ok' => false,
            'message' => "$entityType #$entityId does not exist — nothing to delete.",
            'captured_row' => null,
        ];
    }

    // 2. Run the DELETE in a transaction
    try {
        $db->beginTransaction();
        $del = $db->prepare("DELETE FROM `$table` WHERE `$pkCol` = ?");
        $del->execute([$entityId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return [
            'ok' => false,
            'message' => 'Database rejected the delete: ' . $e->getMessage(),
            'captured_row' => null,
        ];
    }

    // 3. Log AFTER commit (standing rule). before = captured row, after = null.
    //    An undelete pathway reads this entry and re-inserts the row.
    logActivity(
        'record_deleted',
        $pageCode,
        $entityType,
        $entityId,
        $summary,
        $row,  // captured full row
        null   // after = nothing (it's gone)
    );

    return [
        'ok' => true,
        'message' => 'Deleted ' . $entityType . ' #' . $entityId . '. Undelete remains available via the activity log.',
        'captured_row' => $row,
    ];
}

/**
 * Restore a previously-deleted record from a record_deleted log entry.
 *
 * @param int $logId The activity_log entry whose before_json carries the captured row
 * @return array{ok: bool, message: string}
 */
function activity_undelete(int $logId): array {
    $db = getDB();

    // 1. Load the log entry
    $stmt = $db->prepare('SELECT * FROM activity_log WHERE id = ?');
    $stmt->execute([$logId]);
    $log = $stmt->fetch();
    if (!$log) {
        return ['ok' => false, 'message' => 'Log entry not found.'];
    }

    // 2. Must be a record_deleted action
    if (($log['action'] ?? '') !== 'record_deleted') {
        return [
            'ok' => false,
            'message' => 'This log entry is not a delete (' . ($log['action'] ?? '—') .
                         '). Only record_deleted entries can be undeleted.',
        ];
    }

    // 3. Entity whitelist
    $entityType = $log['entity_type'] ?? null;
    $entityId   = $log['entity_id']   ?? null;
    if (!activity_revert_entity_is_supported($entityType)) {
        return [
            'ok' => false,
            'message' => 'Entity type "' . ($entityType ?: '—') . '" is not on the undelete whitelist.',
        ];
    }
    if (!$entityId) {
        return ['ok' => false, 'message' => 'Log entry has no target record id.'];
    }

    // 4. Decode the captured row
    $captured = activity_decode_snapshot($log['before_json'] ?? null);
    if ($captured === null || empty($captured)) {
        return [
            'ok' => false,
            'message' => 'Log entry has no captured row to restore — it was logged before A4 was wired up.',
        ];
    }

    $map   = activity_revert_supported_entity_types();
    $table = $map[$entityType]['table'];
    $pkCol = $map[$entityType]['pk'];

    // 5. PK must not already be occupied
    $chk = $db->prepare("SELECT 1 FROM `$table` WHERE `$pkCol` = ? LIMIT 1");
    $chk->execute([$entityId]);
    if ($chk->fetchColumn()) {
        return [
            'ok' => false,
            'message' => "Cannot undelete: $entityType #$entityId already exists. " .
                         "The original id has been taken by a new record since the delete.",
        ];
    }

    // 6. Filter captured fields to real columns on the current schema.
    //    Drop anything that isn't a column today — schema may have drifted.
    $cols = [];
    $vals = [];
    $skipped = [];
    foreach ($captured as $field => $val) {
        if (activity_field_is_valid_column($table, (string)$field)) {
            $cols[] = "`$field`";
            $vals[] = $val;
        } else {
            $skipped[] = (string)$field;
        }
    }
    if (empty($cols)) {
        return [
            'ok' => false,
            'message' => 'No fields in the captured row match the current schema — nothing to restore.',
        ];
    }

    // 7. Re-INSERT in a transaction. If any NOT NULL column with no
    //    default is missing from the captured row, the INSERT will fail
    //    and we surface the error.
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $sql = "INSERT INTO `$table` (" . implode(', ', $cols) . ") VALUES ($placeholders)";

    try {
        $db->beginTransaction();
        $ins = $db->prepare($sql);
        $ins->execute($vals);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return [
            'ok' => false,
            'message' => 'Database rejected the undelete: ' . $e->getMessage(),
        ];
    }

    // 8. Audit the undelete itself. after = captured row (what's back in
    //    the DB now); before = null (there was nothing). This makes the
    //    detail page render a "(empty) → full row" diff, which is the
    //    mirror image of the original record_deleted entry.
    $summaryMsg = sprintf(
        'Undeleted %s #%d (from activity log #%d)',
        $entityType,
        (int)$entityId,
        (int)$logId
    );
    if (!empty($skipped)) {
        $summaryMsg .= ' — skipped obsolete columns: ' . implode(', ', $skipped);
    }
    logActivity(
        'record_undeleted',
        'activity_log',
        $entityType,
        (int)$entityId,
        $summaryMsg,
        null,
        $captured
    );

    $msg = 'Record restored. A new audit entry records the undelete.';
    if (!empty($skipped)) {
        $msg .= ' Note: ' . count($skipped) . ' captured field(s) were skipped because they no longer exist on the table (' . implode(', ', $skipped) . ').';
    }
    return ['ok' => true, 'message' => $msg];
}
