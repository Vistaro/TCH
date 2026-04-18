<?php
/**
 * AJAX handler for Kanban drag-drop stage moves.
 * POST /ajax/opp-stage-move
 *
 * Params:
 *   csrf_token        (required)
 *   opportunity_id    (required int)
 *   new_stage_id      (required int — must exist in sales_stages and be active)
 *   reason_lost       (required when target stage is_closed_lost = 1)
 *   reason_lost_note  (optional)
 *
 * Returns JSON:
 *   { success: true } on move.
 *   { success: false, message: "..." } on reject.
 *
 * Permission: opportunities.edit. 403 JSON if the caller lacks it.
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

$db = getDB();

try {
    $db->beginTransaction();

    // Snapshot current
    $stmt = $db->prepare(
        "SELECT o.*, s.slug AS stage_slug
           FROM opportunities o
      LEFT JOIN sales_stages s ON s.id = o.stage_id
          WHERE o.id = ?"
    );
    $stmt->execute([$oppId]);
    $opp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$opp) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Opportunity not found.']);
        exit;
    }

    $newStageRow = $db->prepare("SELECT * FROM sales_stages WHERE id = ? AND is_active = 1");
    $newStageRow->execute([$newStageId]);
    $newStage = $newStageRow->fetch(PDO::FETCH_ASSOC);
    if (!$newStage) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Target stage not available.']);
        exit;
    }

    $reasonLost = $_POST['reason_lost'] ?? null;
    $reasonNote = trim((string)($_POST['reason_lost_note'] ?? '')) ?: null;

    if ((int)$newStage['is_closed_lost'] === 1) {
        if (!$reasonLost || !array_key_exists($reasonLost, reasonLostOptions())) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'A reason is required to mark an opportunity lost.']);
            exit;
        }
        $db->prepare(
            "UPDATE opportunities
                SET stage_id = ?, status = 'closed',
                    reason_lost = ?, reason_lost_note = ?,
                    closed_at = NOW()
              WHERE id = ?"
        )->execute([$newStageId, $reasonLost, $reasonNote, $oppId]);
    } elseif ((int)$newStage['is_closed_won'] === 1) {
        $db->prepare(
            "UPDATE opportunities
                SET stage_id = ?, status = 'closed', closed_at = NOW()
              WHERE id = ?"
        )->execute([$newStageId, $oppId]);

        $cid = (int)($opp['contract_id'] ?? 0);
        if ($cid > 0) {
            $db->prepare(
                "UPDATE contracts
                    SET status = 'active',
                        accepted_at = COALESCE(accepted_at, NOW()),
                        acceptance_method = COALESCE(acceptance_method, 'phone')
                  WHERE id = ? AND status IN ('draft','sent','accepted')"
            )->execute([$cid]);
        }
    } else {
        $db->prepare("UPDATE opportunities SET stage_id = ? WHERE id = ?")
           ->execute([$newStageId, $oppId]);
    }

    $db->commit();

    logActivity(
        'opportunity_stage_changed', 'pipeline', 'opportunities', $oppId,
        'Stage: ' . ($opp['stage_slug'] ?? '?') . ' → ' . $newStage['slug'] . ' (kanban)',
        ['stage_id' => (int)$opp['stage_id']],
        ['stage_id' => $newStageId]
    );

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('opp_stage_move_handler: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}
