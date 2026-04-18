<?php
/**
 * Shared helpers for the sales pipeline (FR-L).
 *
 * Kept in one place so opportunities.php, opportunities_detail.php,
 * pipeline.php, the AJAX move handler, and enquiries.php (Convert to
 * Opportunity button) all speak the same vocabulary.
 */

if (!defined('APP_ROOT')) {
    die('Direct access not permitted.');
}

/**
 * Generate the next opp_ref in the OPP-YYYY-NNNN series.
 *
 * Single source of truth: the max existing ref for the current year.
 * No cached counter — the ref is derived at insert time.
 */
function nextOppRef(PDO $db): string {
    $year = (int)date('Y');
    $prefix = sprintf('OPP-%04d-', $year);
    $stmt = $db->prepare(
        "SELECT opp_ref FROM opportunities
          WHERE opp_ref LIKE ?
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last && preg_match('/-(\d+)$/', (string)$last, $m)) {
        $next = (int)$m[1] + 1;
    }
    return sprintf('%s%04d', $prefix, $next);
}

/**
 * Fetch all active stages ordered for Kanban display.
 * Returns rows keyed by id for O(1) lookup.
 */
function fetchSalesStages(PDO $db, bool $onlyActive = true): array {
    $sql = "SELECT * FROM sales_stages";
    if ($onlyActive) $sql .= " WHERE is_active = 1";
    $sql .= " ORDER BY sort_order, id";
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[(int)$r['id']] = $r;
    return $out;
}

/**
 * Load an opportunity by id with its joined display fields.
 * Returns null if not found.
 */
function fetchOpportunity(PDO $db, int $id): ?array {
    $stmt = $db->prepare(
        "SELECT o.*,
                s.name AS stage_name, s.slug AS stage_slug,
                s.is_closed_won, s.is_closed_lost, s.probability_percent,
                cl.account_number AS client_account_number,
                cp.full_name AS client_name,
                pp.full_name AS patient_name,
                pp.tch_id   AS patient_tch_id,
                u.full_name AS owner_name,
                u.email     AS owner_email,
                e.full_name AS enquiry_submitter,
                e.created_at AS enquiry_created_at,
                c.status    AS contract_status,
                c.start_date AS contract_start_date
           FROM opportunities o
      LEFT JOIN sales_stages s ON s.id = o.stage_id
      LEFT JOIN clients    cl  ON cl.id = o.client_id
      LEFT JOIN persons    cp  ON cp.id = cl.person_id
      LEFT JOIN persons    pp  ON pp.id = o.patient_person_id
      LEFT JOIN users      u   ON u.id  = o.owner_user_id
      LEFT JOIN enquiries  e   ON e.id  = o.source_enquiry_id
      LEFT JOIN contracts  c   ON c.id  = o.contract_id
          WHERE o.id = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Reason-lost options (for closed_lost transitions).
 * Keys match the ENUM in the opportunities table.
 */
function reasonLostOptions(): array {
    return [
        'price'         => 'Price — too expensive',
        'timing'        => 'Timing — not ready now',
        'competitor'    => 'Lost to competitor',
        'lost_contact'  => 'Lost contact with client',
        'not_a_fit'     => 'Not a fit for our service',
        'other'         => 'Other (see note)',
    ];
}

/**
 * Source options (for opportunity creation).
 */
function oppSourceOptions(): array {
    return [
        'enquiry'     => 'Enquiry (public form)',
        'referral'    => 'Referral',
        'direct_call' => 'Direct call',
        'walk_in'     => 'Walk-in',
        'other'       => 'Other',
    ];
}

/**
 * Move an opportunity to a new stage, applying the closure side-effects
 * (Closed-Lost reason capture, Closed-Won contract-activation).
 *
 * Shared between the Kanban AJAX move handler and the opportunity-detail
 * button bar — both callers produce the same DB state for the same input,
 * only differing in how they present errors (JSON vs HTML flash).
 *
 * Opens and commits its own transaction. Caller must NOT already be in
 * a transaction. Writes an audit-log entry on success.
 *
 * Returns an array:
 *   [
 *     'ok'          => bool,
 *     'message'     => string   // user-facing error on failure, success line on ok
 *     'activated_contract_id' => int|null  // set when a Closed-Won flipped a contract
 *   ]
 *
 * On validation failure (bad stage / missing reason / opp not found) the
 * function rolls back, returns ok=false, and does NOT throw — callers can
 * decide how to surface the message.
 *
 * @param PDO     $db         live connection
 * @param int     $oppId      opportunity.id being moved
 * @param int     $newStageId sales_stages.id target (must be is_active = 1)
 * @param string  $source     short token included in the audit log summary
 *                            e.g. 'kanban' / 'detail' — helps tell which UI
 *                            surface triggered the change
 * @param ?string $reasonLost required iff target stage is_closed_lost = 1
 * @param ?string $reasonNote optional free-text note for Closed-Lost
 */
function advanceOpportunityStage(
    PDO $db,
    int $oppId,
    int $newStageId,
    string $source = '',
    ?string $reasonLost = null,
    ?string $reasonNote = null
): array {
    try {
        $db->beginTransaction();

        $oppStmt = $db->prepare(
            "SELECT o.*, s.slug AS stage_slug
               FROM opportunities o
          LEFT JOIN sales_stages s ON s.id = o.stage_id
              WHERE o.id = ?"
        );
        $oppStmt->execute([$oppId]);
        $opp = $oppStmt->fetch(PDO::FETCH_ASSOC);
        if (!$opp) {
            $db->rollBack();
            return ['ok' => false, 'message' => 'Opportunity not found.', 'activated_contract_id' => null];
        }

        $stageStmt = $db->prepare("SELECT * FROM sales_stages WHERE id = ? AND is_active = 1");
        $stageStmt->execute([$newStageId]);
        $newStage = $stageStmt->fetch(PDO::FETCH_ASSOC);
        if (!$newStage) {
            $db->rollBack();
            return ['ok' => false, 'message' => 'Target stage not available.', 'activated_contract_id' => null];
        }

        $activatedContractId = null;

        if ((int)$newStage['is_closed_lost'] === 1) {
            if (!$reasonLost || !array_key_exists($reasonLost, reasonLostOptions())) {
                $db->rollBack();
                return ['ok' => false, 'message' => 'A reason is required to mark an opportunity lost.', 'activated_contract_id' => null];
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
                $activatedContractId = $cid;
            }
        } else {
            $db->prepare("UPDATE opportunities SET stage_id = ? WHERE id = ?")
               ->execute([$newStageId, $oppId]);
        }

        $db->commit();

        $summary = 'Stage: ' . ($opp['stage_slug'] ?? '?') . ' → ' . $newStage['slug']
                   . ($source !== '' ? ' (' . $source . ')' : '');
        logActivity(
            'opportunity_stage_changed',
            $source === 'kanban' ? 'pipeline' : 'opportunities',
            'opportunities', $oppId,
            $summary,
            ['stage_id' => (int)$opp['stage_id']],
            ['stage_id' => $newStageId]
        );

        return [
            'ok' => true,
            'message' => 'Stage updated to ' . $newStage['name'] . '.',
            'activated_contract_id' => $activatedContractId,
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('advanceOpportunityStage failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Server error moving stage.', 'activated_contract_id' => null];
    }
}
