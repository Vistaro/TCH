<?php
/**
 * AJAX handler for Kanban drag-drop stage moves.
 * POST /ajax/opp-stage-move
 *
 * Params:
 *   csrf_token        (required)
 *   opportunity_id    (required int)
 *   new_stage_id      (required int — must be an active sales_stages row)
 *   reason_lost       (required when target stage is_closed_lost = 1)
 *   reason_lost_note  (optional)
 *
 * Returns JSON:
 *   { success: true } on move.
 *   { success: false, message: "..." } on reject.
 *
 * Permission: opportunities.edit. 403 JSON if the caller lacks it.
 *
 * The actual state change (Closed-Lost reason capture, Closed-Won
 * contract activation, audit log) lives in
 * advanceOpportunityStage() in includes/opportunities.php — shared with
 * the opportunity-detail button-bar path so both surfaces behave
 * identically.
 */
header('Content-Type: application/json; charset=utf-8');

initSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only.']);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

if (!userCan('opportunities', 'edit')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to move opportunities.']);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token. Refresh the page.']);
    exit;
}

require_once APP_ROOT . '/includes/opportunities.php';

$oppId      = (int)($_POST['opportunity_id'] ?? 0);
$newStageId = (int)($_POST['new_stage_id'] ?? 0);
if ($oppId < 1 || $newStageId < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing opportunity or stage.']);
    exit;
}

$res = advanceOpportunityStage(
    getDB(),
    $oppId,
    $newStageId,
    'kanban',
    $_POST['reason_lost'] ?? null,
    trim((string)($_POST['reason_lost_note'] ?? '')) ?: null
);

if (!$res['ok']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $res['message']]);
    exit;
}

echo json_encode(['success' => true, 'message' => $res['message']]);
