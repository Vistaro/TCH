<?php
/**
 * Activity log rendering helpers.
 *
 * Shared between the /admin/activity list and detail views so the field-level
 * diff is computed and rendered consistently. Keeps the audit trail readable
 * at a glance (inline on the list) and in full (on the detail page).
 *
 * All helpers are pure / side-effect-free. HTML they emit is already escaped.
 */

/**
 * Decode a before/after JSON column to an associative array.
 * Returns null for empty, invalid, or non-array JSON.
 */
function activity_decode_snapshot(?string $json): ?array {
    if ($json === null || $json === '') {
        return null;
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Render a single field value for display in a diff cell.
 *
 * Output is pre-escaped HTML: safe to echo directly. Nulls, empty strings,
 * bools, arrays and objects are normalised so the caller never has to guess
 * at what it's getting out of the JSON blob.
 */
function activity_render_value($v): string {
    if ($v === null) {
        return '<em style="color:#999;">(empty)</em>';
    }
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    if (is_array($v) || is_object($v)) {
        return '<code>' . htmlspecialchars(
            json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ) . '</code>';
    }
    $s = (string)$v;
    if ($s === '') {
        return '<em style="color:#999;">(empty)</em>';
    }
    return htmlspecialchars($s);
}

/**
 * Compute a field-level diff between two decoded snapshots.
 *
 * Returns an associative array: field_name => [old_value, new_value].
 * Only fields whose value actually changed are included. Accepts null for
 * either side (create = before null, delete = after null).
 */
function activity_compute_diff(?array $before, ?array $after): array {
    if ($before === null && $after === null) {
        return [];
    }
    $allKeys = array_unique(array_merge(
        $before !== null ? array_keys($before) : [],
        $after  !== null ? array_keys($after)  : []
    ));
    $diff = [];
    foreach ($allKeys as $k) {
        $b = $before[$k] ?? null;
        $a = $after[$k]  ?? null;
        if ($b !== $a) {
            $diff[$k] = [$b, $a];
        }
    }
    return $diff;
}

/**
 * Render a compact inline diff as a collapsible <details> block.
 *
 * Used on the activity-log list view so each row shows "N field(s) changed"
 * with click-to-expand Was → Now lines. When $diff is empty, returns ''.
 *
 * The output uses <del class="diff-was"> (red strikethrough) and
 * <ins class="diff-now"> (green), styled in public/assets/css/style.css.
 */
function activity_render_inline_diff(array $diff): string {
    if (empty($diff)) {
        return '';
    }
    $count = count($diff);
    $label = $count === 1 ? '1 field changed' : $count . ' fields changed';

    $rows = '';
    foreach ($diff as $field => [$wasValue, $nowValue]) {
        $rows .= '<div class="diff-row">'
              .  '<strong>' . htmlspecialchars((string)$field) . ':</strong> '
              .  '<del class="diff-was">' . activity_render_value($wasValue) . '</del>'
              .  ' <span class="diff-arrow">&rarr;</span> '
              .  '<ins class="diff-now">' . activity_render_value($nowValue) . '</ins>'
              .  '</div>';
    }

    return '<details class="activity-inline-diff">'
         . '<summary>' . htmlspecialchars($label) . '</summary>'
         . $rows
         . '</details>';
}
