<?php
/**
 * Opportunity detail — /admin/opportunities/{id}
 *
 * Single-page record view with:
 *   - Header block (ref, title, stage, owner, close actions)
 *   - Who-it's-for panel (client + patient link or contact snapshot)
 *   - Source panel (enquiry backlink if any)
 *   - Care summary + estimate
 *   - Linked contract (quote) when FR-C has built one
 *   - Activity timeline (from activity_log, entity_type=opportunities)
 *
 * Closed-Won / Closed-Lost transitions live here as POST actions —
 * Closed-Won flips any linked draft contract to active; Closed-Lost
 * requires a reason.
 */
require_once APP_ROOT . '/includes/opportunities.php';
require_once APP_ROOT . '/includes/activities_render.php';

$db = getDB();
$canEdit = userCan('opportunities', 'edit');

$oppId = (int)($_GET['opp_id'] ?? 0);
if ($oppId < 1) { http_response_code(404); echo '<p>Not found.</p>'; return; }

$flash = '';
$flashType = 'success';

// ── Handle Notes form POST (shared activities panel) ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'save_activity' && $canEdit) {
    $res = saveActivityFromPost('opportunities', $oppId);
    if ($res['ok']) {
        header('Location: ' . APP_URL . '/admin/opportunities/' . $oppId . '?msg=' . urlencode($res['msg']));
        exit;
    }
    $flash = $res['msg']; $flashType = 'error';
}

// ── Handle POST actions (stage transition / close / reopen) ──────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit && ($_POST['action'] ?? '') !== 'save_activity') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'advance_stage') {
            $res = advanceOpportunityStage(
                $db, $oppId, (int)$_POST['new_stage_id'],
                'detail',
                $_POST['reason_lost']      ?? null,
                trim($_POST['reason_lost_note'] ?? '') ?: null
            );
            if ($res['ok']) {
                header('Location: ' . APP_URL . '/admin/opportunities/' . $oppId . '?msg=' . urlencode($res['message']));
                exit;
            }
            $flash = $res['message']; $flashType = 'error';
        } elseif ($action === 'reopen') {
            try {
                $db->beginTransaction();
                // Find a sensible open stage — default to Qualifying, else first open stage in sort order
                $openStage = $db->query(
                    "SELECT id FROM sales_stages
                      WHERE slug = 'qualifying' AND is_active = 1 LIMIT 1"
                )->fetchColumn();
                if (!$openStage) {
                    $openStage = $db->query(
                        "SELECT id FROM sales_stages
                          WHERE is_closed_won = 0 AND is_closed_lost = 0 AND is_active = 1
                          ORDER BY sort_order LIMIT 1"
                    )->fetchColumn();
                }
                if (!$openStage) throw new RuntimeException('No open stage available.');
                $prevStatusRow = $db->prepare("SELECT status FROM opportunities WHERE id = ?");
                $prevStatusRow->execute([$oppId]);
                $prevStatus = $prevStatusRow->fetchColumn();
                $db->prepare(
                    "UPDATE opportunities
                        SET stage_id = ?, status = 'open',
                            reason_lost = NULL, reason_lost_note = NULL,
                            closed_at = NULL
                      WHERE id = ?"
                )->execute([(int)$openStage, $oppId]);
                $db->commit();
                logActivity('opportunity_reopened', 'opportunities', 'opportunities', $oppId,
                    'Reopened', ['status' => $prevStatus ?: null], ['status' => 'open']);
                header('Location: ' . APP_URL . '/admin/opportunities/' . $oppId . '?msg=' . urlencode('Opportunity reopened.'));
                exit;
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $flash = 'Error: ' . $e->getMessage(); $flashType = 'error';
            }
        } elseif ($action === 'archive') {
            try {
                $prevStatusRow = $db->prepare("SELECT status FROM opportunities WHERE id = ?");
                $prevStatusRow->execute([$oppId]);
                $prevStatus = $prevStatusRow->fetchColumn();
                $db->prepare("UPDATE opportunities SET status = 'archived' WHERE id = ?")->execute([$oppId]);
                logActivity('opportunity_archived', 'opportunities', 'opportunities', $oppId,
                    'Archived', ['status' => $prevStatus ?: null], ['status' => 'archived']);
                header('Location: ' . APP_URL . '/admin/opportunities?status=archived');
                exit;
            } catch (Throwable $e) {
                $flash = 'Error: ' . $e->getMessage(); $flashType = 'error';
            }
        } else {
            $flash = 'Unknown action.'; $flashType = 'error';
        }
    }
}

// ── Load opp ───────────────────────────────────────────────────────
$opp = fetchOpportunity($db, $oppId);
if (!$opp) { http_response_code(404); echo '<p>Opportunity not found.</p>'; return; }

$stages = fetchSalesStages($db, true);

$pageTitle = htmlspecialchars($opp['opp_ref'] . ' — ' . $opp['title']);
$activeNav = 'opportunities';

$msg = $_GET['msg'] ?? '';

require APP_ROOT . '/templates/layouts/admin.php';
?>

<?php if ($msg): ?>
    <div class="flash flash-success" style="padding:0.8rem 1rem;margin-bottom:1rem;border-radius:4px;background:#d1e7dd;color:#0f5132;">
        <?= htmlspecialchars($msg) ?>
    </div>
<?php endif; ?>

<?php if ($flash): ?>
    <div class="flash flash-<?= htmlspecialchars($flashType) ?>" style="padding:0.8rem 1rem;margin-bottom:1rem;border-radius:4px;background:<?= $flashType === 'error' ? '#f8d7da' : '#d1e7dd' ?>;color:<?= $flashType === 'error' ? '#842029' : '#0f5132' ?>;">
        <?= htmlspecialchars($flash) ?>
    </div>
<?php endif; ?>

<?php
$stageColour = (int)$opp['is_closed_won'] === 1 ? '#198754'
             : ((int)$opp['is_closed_lost'] === 1 ? '#dc3545' : '#0d6efd');
$isClosed = $opp['status'] === 'closed' || (int)$opp['is_closed_won'] === 1 || (int)$opp['is_closed_lost'] === 1;
?>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem;gap:1rem;flex-wrap:wrap;">
    <div>
        <code style="color:#6c757d;font-size:0.85rem;"><?= htmlspecialchars($opp['opp_ref']) ?></code>
        <?php if (!empty($opp['is_test_data'])): ?>
            <span style="background:#fbbf24;color:#78350f;padding:2px 8px;border-radius:4px;font-size:0.7rem;font-weight:700;letter-spacing:0.05em;margin-left:0.4rem;">TEST</span>
        <?php endif; ?>
        <h2 style="margin:0.2rem 0;"><?= htmlspecialchars($opp['title']) ?></h2>
        <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
            <span style="background:<?= $stageColour ?>;color:#fff;padding:3px 10px;border-radius:10px;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">
                <?= htmlspecialchars($opp['stage_name']) ?> (<?= (int)$opp['probability_percent'] ?>%)
            </span>
            <?php if ($opp['owner_name']): ?>
                <span style="font-size:0.82rem;color:#495057;">Owner: <strong><?= htmlspecialchars($opp['owner_name']) ?></strong></span>
            <?php endif; ?>
            <?php if ($opp['status'] === 'archived'): ?>
                <span style="color:#adb5bd;font-size:0.8rem;">Archived</span>
            <?php endif; ?>
        </div>
    </div>
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
        <?php if ($canEdit): ?>
            <a href="<?= APP_URL ?>/admin/opportunities/<?= $oppId ?>/edit" class="btn btn-sm" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;">Edit</a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/admin/opportunities" class="btn btn-sm" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;">← List</a>
        <a href="<?= APP_URL ?>/admin/pipeline" class="btn btn-sm" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;">Pipeline</a>
    </div>
</div>

<!-- Stage transition bar -->
<?php if ($canEdit && !$isClosed): ?>
<div style="background:#f8f9fa;padding:0.8rem 1rem;border-radius:4px;margin-bottom:1rem;border:1px solid #dee2e6;">
    <strong style="font-size:0.85rem;">Move to stage:</strong>
    <div style="display:flex;gap:0.4rem;flex-wrap:wrap;margin-top:0.4rem;">
        <?php foreach ($stages as $s):
            if ((int)$s['id'] === (int)$opp['stage_id']) continue;
            $isLost = (int)$s['is_closed_lost'] === 1;
            $isWon  = (int)$s['is_closed_won']  === 1;
            $bgColour = $isWon ? '#198754' : ($isLost ? '#dc3545' : '#0d6efd');
        ?>
            <?php if ($isLost): ?>
                <button type="button" onclick="document.getElementById('lost-dialog').style.display='block';"
                        style="background:<?= $bgColour ?>;color:#fff;border:0;padding:0.4rem 0.8rem;border-radius:4px;font-size:0.82rem;cursor:pointer;">
                    → <?= htmlspecialchars($s['name']) ?>
                </button>
                <!-- Closed-Lost dialog -->
                <div id="lost-dialog" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:1000;align-items:center;justify-content:center;">
                    <form method="post" style="background:#fff;padding:1.5rem;max-width:480px;width:100%;border-radius:6px;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="advance_stage">
                        <input type="hidden" name="new_stage_id" value="<?= (int)$s['id'] ?>">
                        <h3 style="margin-top:0;">Mark as Closed — Lost</h3>
                        <p style="color:#6c757d;font-size:0.85rem;">Reason is required. This feeds Acquire-phase reporting.</p>
                        <label style="display:block;margin-bottom:0.8rem;">
                            <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Reason <span style="color:#dc3545;">*</span></span>
                            <select name="reason_lost" required style="width:100%;padding:0.4rem 0.6rem;">
                                <option value="">— Pick one —</option>
                                <?php foreach (reasonLostOptions() as $k => $lbl): ?>
                                    <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label style="display:block;margin-bottom:0.8rem;">
                            <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Note (optional)</span>
                            <textarea name="reason_lost_note" rows="3" style="width:100%;padding:0.4rem 0.6rem;"></textarea>
                        </label>
                        <div style="display:flex;gap:0.5rem;justify-content:flex-end;">
                            <button type="button" onclick="document.getElementById('lost-dialog').style.display='none';" class="btn" style="background:#f1f5f9;color:#334155;">Cancel</button>
                            <button type="submit" class="btn" style="background:#dc3545;color:#fff;">Mark as lost</button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <form method="post" style="display:inline;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="advance_stage">
                    <input type="hidden" name="new_stage_id" value="<?= (int)$s['id'] ?>">
                    <button type="submit"
                            <?= $isWon ? 'onclick="return confirm(\'Mark as Closed — Won? Any linked draft contract will be flipped to active.\');"' : '' ?>
                            style="background:<?= $bgColour ?>;color:#fff;border:0;padding:0.4rem 0.8rem;border-radius:4px;font-size:0.82rem;cursor:pointer;">
                        → <?= htmlspecialchars($s['name']) ?>
                    </button>
                </form>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
<?php elseif ($canEdit && $isClosed): ?>
<div style="background:#f8f9fa;padding:0.8rem 1rem;border-radius:4px;margin-bottom:1rem;border:1px solid #dee2e6;">
    <?php if ($opp['status'] === 'closed'): ?>
        <form method="post" style="display:inline;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reopen">
            <button type="submit" class="btn btn-sm" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;">Reopen opportunity</button>
        </form>
    <?php endif; ?>
    <?php if ($opp['status'] !== 'archived'): ?>
        <form method="post" style="display:inline;margin-left:0.5rem;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="archive">
            <button type="submit" onclick="return confirm('Archive this opportunity? It will be hidden from the default lists but kept for reporting.');" class="btn btn-sm" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;">Archive</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:1rem;">
<div>

<!-- Who it's for -->
<div style="background:#fff;border:1px solid #dee2e6;border-radius:4px;padding:1rem 1.2rem;margin-bottom:1rem;">
    <h3 style="margin-top:0;font-size:1rem;">Who it's for</h3>
    <?php if ($opp['client_name'] || $opp['patient_name']): ?>
        <dl style="margin:0;display:grid;grid-template-columns:auto 1fr;gap:0.3rem 0.8rem;font-size:0.9rem;">
            <?php if ($opp['client_name']): ?>
                <dt style="color:#6c757d;">Client:</dt>
                <dd style="margin:0;"><a href="<?= APP_URL ?>/admin/clients/<?= (int)$opp['client_id'] ?>"><?= htmlspecialchars($opp['client_name']) ?></a></dd>
            <?php endif; ?>
            <?php if ($opp['patient_name']): ?>
                <dt style="color:#6c757d;">Patient:</dt>
                <dd style="margin:0;"><a href="<?= APP_URL ?>/admin/patients/<?= (int)$opp['patient_person_id'] ?>"><?= htmlspecialchars($opp['patient_name']) ?></a> <code style="color:#6c757d;"><?= htmlspecialchars($opp['patient_tch_id'] ?? '') ?></code></dd>
            <?php endif; ?>
        </dl>
    <?php else: ?>
        <p style="color:#6c757d;font-style:italic;margin:0;">Not yet linked to a client/patient record.</p>
    <?php endif; ?>

    <?php if ($opp['contact_name'] || $opp['contact_email'] || $opp['contact_phone']): ?>
        <hr style="margin:0.8rem 0;border:0;border-top:1px solid #dee2e6;">
        <div style="font-size:0.9rem;">
            <strong style="color:#6c757d;font-weight:500;">Contact snapshot:</strong><br>
            <?= htmlspecialchars($opp['contact_name'] ?? '—') ?>
            <?php if ($opp['contact_email']): ?> · <a href="mailto:<?= htmlspecialchars($opp['contact_email']) ?>"><?= htmlspecialchars($opp['contact_email']) ?></a><?php endif; ?>
            <?php if ($opp['contact_phone']): ?> · <a href="tel:<?= htmlspecialchars($opp['contact_phone']) ?>"><?= htmlspecialchars($opp['contact_phone']) ?></a><?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Care requirement + estimate -->
<div style="background:#fff;border:1px solid #dee2e6;border-radius:4px;padding:1rem 1.2rem;margin-bottom:1rem;">
    <h3 style="margin-top:0;font-size:1rem;">Care requirement</h3>
    <?php if ($opp['care_summary']): ?>
        <p style="white-space:pre-wrap;margin:0 0 0.8rem 0;"><?= htmlspecialchars($opp['care_summary']) ?></p>
    <?php else: ?>
        <p style="color:#6c757d;font-style:italic;margin:0 0 0.8rem 0;">No care summary captured yet.</p>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.8rem;font-size:0.9rem;">
        <div>
            <span style="color:#6c757d;font-size:0.82rem;">Expected value / month:</span><br>
            <strong><?= $opp['expected_value_cents'] !== null ? 'R' . number_format((int)$opp['expected_value_cents'] / 100, 0) : '—' ?></strong>
        </div>
        <div>
            <span style="color:#6c757d;font-size:0.82rem;">Expected start:</span><br>
            <strong><?= $opp['expected_start_date'] ? htmlspecialchars($opp['expected_start_date']) : '—' ?></strong>
        </div>
    </div>
</div>

<!-- Notes -->
<?php if ($opp['notes']): ?>
<div style="background:#fff;border:1px solid #dee2e6;border-radius:4px;padding:1rem 1.2rem;margin-bottom:1rem;">
    <h3 style="margin-top:0;font-size:1rem;">Notes</h3>
    <p style="white-space:pre-wrap;margin:0;"><?= htmlspecialchars($opp['notes']) ?></p>
</div>
<?php endif; ?>

<!-- Linked quote / contract -->
<div style="background:#fff;border:1px solid #dee2e6;border-radius:4px;padding:1rem 1.2rem;margin-bottom:1rem;">
    <h3 style="margin-top:0;font-size:1rem;">Quote / contract</h3>
    <?php
    $canBuildQuote = userCan('quotes', 'create');
    $isQuoteStatus = $opp['contract_id'] && in_array($opp['contract_status'], ['draft','sent','accepted','rejected','expired'], true);
    $isLiveContract = $opp['contract_id'] && in_array($opp['contract_status'], ['active','on_hold','cancelled','completed'], true);
    ?>
    <?php if ($isQuoteStatus): ?>
        <p style="margin:0 0 0.4rem 0;">
            Quote
            <a href="<?= APP_URL ?>/admin/quotes/<?= (int)$opp['contract_id'] ?>">
                <strong>#<?= (int)$opp['contract_id'] ?></strong>
            </a>
            — status: <strong><?= htmlspecialchars((string)$opp['contract_status']) ?></strong>.
        </p>
        <?php if ($canBuildQuote): ?>
            <a href="<?= APP_URL ?>/admin/quotes/<?= (int)$opp['contract_id'] ?>/edit" class="btn btn-sm btn-primary">Continue editing quote</a>
        <?php endif; ?>
    <?php elseif ($isLiveContract): ?>
        <p style="margin:0;">
            Active contract
            <a href="<?= APP_URL ?>/admin/contracts/<?= (int)$opp['contract_id'] ?>">#<?= (int)$opp['contract_id'] ?></a>
            — status: <strong><?= htmlspecialchars((string)$opp['contract_status']) ?></strong>
            <?php if ($opp['contract_start_date']): ?>, starts <?= htmlspecialchars($opp['contract_start_date']) ?><?php endif; ?>.
        </p>
    <?php else: ?>
        <p style="color:#6c757d;margin:0 0 0.6rem 0;font-style:italic;">No quote built yet.</p>
        <?php if ($canBuildQuote && (int)$opp['is_closed_won'] !== 1 && (int)$opp['is_closed_lost'] !== 1): ?>
            <a href="<?= APP_URL ?>/admin/quotes/new?opportunity_id=<?= (int)$oppId ?>" class="btn btn-sm btn-primary" style="background:#15803d;border-color:#15803d;">
                + Build quote
            </a>
            <p style="color:#6c757d;font-size:0.85rem;margin:0.4rem 0 0 0;">Pre-fills client, patient, and expected start from this opportunity.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

</div>
<div>

<!-- Side panel: source, key dates, close info -->
<div style="background:#fff;border:1px solid #dee2e6;border-radius:4px;padding:1rem 1.2rem;margin-bottom:1rem;">
    <h3 style="margin-top:0;font-size:1rem;">Source</h3>
    <p style="margin:0 0 0.4rem 0;font-size:0.9rem;">
        <strong><?= htmlspecialchars(oppSourceOptions()[$opp['source']] ?? $opp['source']) ?></strong>
    </p>
    <?php if ($opp['source_enquiry_id']): ?>
        <p style="margin:0;font-size:0.9rem;">
            <a href="<?= APP_URL ?>/admin/enquiries?id=<?= (int)$opp['source_enquiry_id'] ?>">View original enquiry →</a>
            <?php if ($opp['enquiry_created_at']): ?>
                <br><span style="color:#6c757d;font-size:0.8rem;">Submitted <?= htmlspecialchars(date('Y-m-d', strtotime($opp['enquiry_created_at']))) ?></span>
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if ($opp['source_note']): ?>
        <p style="margin:0.4rem 0 0 0;font-size:0.85rem;color:#495057;"><?= htmlspecialchars($opp['source_note']) ?></p>
    <?php endif; ?>
</div>

<div style="background:#fff;border:1px solid #dee2e6;border-radius:4px;padding:1rem 1.2rem;margin-bottom:1rem;font-size:0.88rem;">
    <h3 style="margin-top:0;font-size:1rem;">Key dates</h3>
    <dl style="margin:0;display:grid;grid-template-columns:auto 1fr;gap:0.3rem 0.8rem;">
        <dt style="color:#6c757d;">Created:</dt>
        <dd style="margin:0;"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($opp['created_at']))) ?></dd>
        <dt style="color:#6c757d;">Updated:</dt>
        <dd style="margin:0;"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($opp['updated_at']))) ?></dd>
        <?php if ($opp['closed_at']): ?>
            <dt style="color:#6c757d;">Closed:</dt>
            <dd style="margin:0;"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($opp['closed_at']))) ?></dd>
        <?php endif; ?>
    </dl>
</div>

<?php if ($opp['reason_lost']): ?>
<div style="background:#fff5f5;border:1px solid #fecdd3;border-radius:4px;padding:1rem 1.2rem;margin-bottom:1rem;">
    <h3 style="margin-top:0;font-size:1rem;color:#991b1b;">Reason lost</h3>
    <p style="margin:0;font-size:0.9rem;"><strong><?= htmlspecialchars(reasonLostOptions()[$opp['reason_lost']] ?? $opp['reason_lost']) ?></strong></p>
    <?php if ($opp['reason_lost_note']): ?>
        <p style="margin:0.4rem 0 0 0;font-size:0.85rem;color:#495057;white-space:pre-wrap;"><?= htmlspecialchars($opp['reason_lost_note']) ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>

</div>
</div>

<!-- Notes + Tasks panel (shared component — same pattern as client/patient detail pages) -->
<?php renderActivityTimeline('opportunities', $oppId, $canEdit); ?>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
