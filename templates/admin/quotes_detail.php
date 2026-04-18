<?php
/**
 * Quote detail — /admin/quotes/{id}
 *
 * Read view of a draft/sent/accepted/rejected/expired contract presented
 * as a quote. Status-transition buttons live here (send / accept / reject
 * / mark expired). Content edits go through the builder at /edit.
 *
 * Accepted or otherwise, an accepted quote does NOT auto-create anything
 * further — the activate-as-live-contract step fires on the opportunity
 * Closed-Won transition (FR-L) OR manually via the "Convert to active
 * contract" button on this page when there's no opportunity.
 */
require_once APP_ROOT . '/includes/opportunities.php';

$db = getDB();
$canEdit = userCan('quotes', 'edit');

$quoteId = (int)($_GET['contract_id'] ?? 0);
if ($quoteId < 1) { http_response_code(404); die('Not found.'); }

$flash = '';
$flashType = 'success';

// ── Handle status transition POST ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        $validActions = ['send','accept','reject','expire','activate'];
        if (!in_array($action, $validActions, true)) {
            $flash = 'Unknown action.'; $flashType = 'error';
        } else {
            try {
                $db->beginTransaction();

                $before = $db->prepare("SELECT status, quote_reference FROM contracts WHERE id = ?");
                $before->execute([$quoteId]);
                $prev = $before->fetch(PDO::FETCH_ASSOC) ?: [];

                $method = $_POST['acceptance_method'] ?? null;
                $note   = trim($_POST['acceptance_note'] ?? '') ?: null;

                if ($action === 'send') {
                    $db->prepare(
                        "UPDATE contracts SET status = 'sent', sent_at = COALESCE(sent_at, NOW())
                          WHERE id = ? AND status IN ('draft','sent')"
                    )->execute([$quoteId]);
                } elseif ($action === 'accept') {
                    $validMethod = in_array($method, ['email','phone','in_person','portal','signed_pdf'], true);
                    if (!$validMethod) throw new RuntimeException('Acceptance method required.');
                    $db->prepare(
                        "UPDATE contracts
                            SET status = 'accepted',
                                accepted_at = NOW(),
                                acceptance_method = ?,
                                acceptance_note = ?
                          WHERE id = ?"
                    )->execute([$method, $note, $quoteId]);
                } elseif ($action === 'reject') {
                    $db->prepare("UPDATE contracts SET status = 'rejected' WHERE id = ?")
                       ->execute([$quoteId]);
                } elseif ($action === 'expire') {
                    $db->prepare("UPDATE contracts SET status = 'expired' WHERE id = ?")
                       ->execute([$quoteId]);
                } elseif ($action === 'activate') {
                    // Manual "accepted → active" — used when there's no opportunity
                    // (FR-L Closed-Won is the normal path).
                    $db->prepare(
                        "UPDATE contracts SET status = 'active' WHERE id = ? AND status = 'accepted'"
                    )->execute([$quoteId]);
                }

                $db->commit();

                logActivity('quote_' . $action, 'quotes', 'contracts', $quoteId,
                    ($prev['quote_reference'] ?? '#' . $quoteId) . ': ' . ($prev['status'] ?? '?') . ' → ' . $action,
                    ['status' => $prev['status'] ?? null],
                    ['status' => ($action === 'activate') ? 'active' : $action,
                     'acceptance_method' => $method, 'acceptance_note' => $note]);

                header('Location: ' . APP_URL . '/admin/quotes/' . $quoteId . '?msg=' . urlencode('Status updated.'));
                exit;
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $flash = 'Error: ' . $e->getMessage();
                $flashType = 'error';
            }
        }
    }
}

// ── Load ───────────────────────────────────────────────────────────
$stmt = $db->prepare(
    "SELECT c.*,
            pp.full_name AS patient_name, pp.tch_id AS patient_tch_id,
            cp.full_name AS client_name,
            cl.account_number,
            o.opp_ref AS opp_ref, o.title AS opp_title
       FROM contracts c
  LEFT JOIN persons pp ON pp.id = c.patient_person_id
  LEFT JOIN clients cl ON cl.id = c.client_id
  LEFT JOIN persons cp ON cp.id = cl.person_id
  LEFT JOIN opportunities o ON o.id = c.opportunity_id
      WHERE c.id = ?"
);
$stmt->execute([$quoteId]);
$q = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$q) { http_response_code(404); die('Quote not found.'); }

if (!in_array($q['status'], ['draft','sent','accepted','rejected','expired'], true)) {
    // This is a live contract now — redirect
    header('Location: ' . APP_URL . '/admin/contracts/' . $quoteId);
    exit;
}

$linesStmt = $db->prepare(
    "SELECT cl.*, p.name AS product_name, p.code AS product_code
       FROM contract_lines cl
       JOIN products p ON p.id = cl.product_id
      WHERE cl.contract_id = ?
      ORDER BY cl.id"
);
$linesStmt->execute([$quoteId]);
$lines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);

$periodTotal = 0;
foreach ($lines as $ln) $periodTotal += (float)$ln['bill_rate'] * (float)$ln['units_per_period'];

$pageTitle = ($q['quote_reference'] ?: '#' . $quoteId) . ' — Quote';
$activeNav = 'quotes';

$msg = $_GET['msg'] ?? '';

$statusColour = [
    'draft'    => '#6c757d',
    'sent'     => '#0d6efd',
    'accepted' => '#198754',
    'rejected' => '#dc3545',
    'expired'  => '#fd7e14',
][$q['status']] ?? '#6c757d';

require APP_ROOT . '/templates/layouts/admin.php';
?>

<?php if ($msg): ?>
<div style="padding:0.8rem 1rem;margin-bottom:1rem;border-radius:4px;background:#d1e7dd;color:#0f5132;">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<?php if ($flash): ?>
<div style="padding:0.8rem 1rem;margin-bottom:1rem;border-radius:4px;background:<?= $flashType === 'error' ? '#f8d7da' : '#d1e7dd' ?>;color:<?= $flashType === 'error' ? '#842029' : '#0f5132' ?>;">
    <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem;gap:1rem;flex-wrap:wrap;">
    <div>
        <?php if ($q['quote_reference']): ?>
            <code style="color:#6c757d;font-size:0.85rem;"><?= htmlspecialchars($q['quote_reference']) ?></code>
        <?php endif; ?>
        <h2 style="margin:0.2rem 0;">
            Quote for <?= htmlspecialchars($q['patient_name'] ?? '—') ?>
            <span style="background:<?= $statusColour ?>;color:#fff;padding:3px 10px;border-radius:10px;font-size:0.7em;text-transform:uppercase;letter-spacing:0.04em;margin-left:0.4rem;vertical-align:middle;">
                <?= htmlspecialchars($q['status']) ?>
            </span>
        </h2>
        <div style="color:#6c757d;font-size:0.9rem;">
            Billed to <?= htmlspecialchars($q['client_name'] ?? '—') ?>
            <?php if ($q['opp_ref']): ?>
                · Opportunity <a href="<?= APP_URL ?>/admin/opportunities/<?= (int)$q['opportunity_id'] ?>"><?= htmlspecialchars($q['opp_ref']) ?></a> <?= htmlspecialchars($q['opp_title']) ?>
            <?php endif; ?>
        </div>
    </div>
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
        <?php if ($canEdit && $q['status'] === 'draft'): ?>
            <a href="<?= APP_URL ?>/admin/quotes/<?= $quoteId ?>/edit" class="btn btn-sm btn-primary">Edit</a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/admin/quotes/<?= $quoteId ?>/print" target="_blank" class="btn btn-sm" style="background:#1e40af;color:#fff;border:0;">Download PDF</a>
        <a href="<?= APP_URL ?>/admin/quotes" class="btn btn-sm" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;">← List</a>
    </div>
</div>

<!-- Status transition bar -->
<?php if ($canEdit): ?>
<div style="background:#f8f9fa;padding:0.8rem 1rem;border-radius:4px;margin-bottom:1rem;border:1px solid #dee2e6;">
    <strong style="font-size:0.85rem;">Actions:</strong>
    <div style="display:flex;gap:0.4rem;flex-wrap:wrap;margin-top:0.4rem;">
        <?php if ($q['status'] === 'draft'): ?>
            <form method="post" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="send">
                <button type="submit" style="background:#0d6efd;color:#fff;border:0;padding:0.4rem 0.8rem;border-radius:4px;font-size:0.82rem;cursor:pointer;">
                    Mark as Sent
                </button>
            </form>
        <?php endif; ?>

        <?php if (in_array($q['status'], ['draft','sent'], true)): ?>
            <button type="button" onclick="document.getElementById('accept-dialog').style.display='flex';"
                    style="background:#198754;color:#fff;border:0;padding:0.4rem 0.8rem;border-radius:4px;font-size:0.82rem;cursor:pointer;">
                Record acceptance
            </button>

            <form method="post" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reject">
                <button type="submit" onclick="return confirm('Mark this quote as rejected?');"
                        style="background:#dc3545;color:#fff;border:0;padding:0.4rem 0.8rem;border-radius:4px;font-size:0.82rem;cursor:pointer;">
                    Mark as Rejected
                </button>
            </form>

            <form method="post" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="expire">
                <button type="submit" onclick="return confirm('Mark this quote as expired (no response, time ran out)?');"
                        style="background:#fd7e14;color:#fff;border:0;padding:0.4rem 0.8rem;border-radius:4px;font-size:0.82rem;cursor:pointer;">
                    Mark as Expired
                </button>
            </form>
        <?php endif; ?>

        <?php if ($q['status'] === 'accepted' && empty($q['opportunity_id'])): ?>
            <form method="post" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="activate">
                <button type="submit" onclick="return confirm('Activate this quote as a live contract? Care delivery + billing can begin once active.');"
                        style="background:#198754;color:#fff;border:0;padding:0.4rem 0.8rem;border-radius:4px;font-size:0.82rem;cursor:pointer;">
                    Activate as live contract
                </button>
            </form>
        <?php elseif ($q['status'] === 'accepted' && !empty($q['opportunity_id'])): ?>
            <span style="color:#6c757d;font-size:0.85rem;align-self:center;">
                Activation fires when the opportunity is moved to Closed — Won.
            </span>
        <?php endif; ?>
    </div>
</div>

<!-- Accept dialog -->
<div id="accept-dialog" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:1000;align-items:center;justify-content:center;">
    <form method="post" style="background:#fff;padding:1.5rem;max-width:480px;width:100%;border-radius:6px;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="accept">
        <h3 style="margin-top:0;">Record quote acceptance</h3>
        <p style="color:#6c757d;font-size:0.85rem;">How did the client confirm they're happy to proceed? This is audit-logged.</p>
        <label style="display:block;margin-bottom:0.8rem;">
            <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Method <span style="color:#dc3545;">*</span></span>
            <select name="acceptance_method" required style="width:100%;padding:0.4rem 0.6rem;">
                <option value="">— Pick one —</option>
                <option value="email">Email reply</option>
                <option value="phone">Phone call</option>
                <option value="in_person">In person</option>
                <option value="signed_pdf">Signed PDF returned</option>
                <option value="portal">Portal click-accept</option>
            </select>
        </label>
        <label style="display:block;margin-bottom:0.8rem;">
            <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Note (optional)</span>
            <textarea name="acceptance_note" rows="3" style="width:100%;padding:0.4rem 0.6rem;" placeholder="e.g. 'Confirmed on call with Mrs Smith, 18 Apr 15:30.'"></textarea>
        </label>
        <div style="display:flex;gap:0.5rem;justify-content:flex-end;">
            <button type="button" onclick="document.getElementById('accept-dialog').style.display='none';" class="btn" style="background:#f1f5f9;color:#334155;">Cancel</button>
            <button type="submit" class="btn" style="background:#198754;color:#fff;">Record acceptance</button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Summary + lines -->
<div style="background:#fff;border:1px solid #dee2e6;border-radius:4px;padding:1rem 1.2rem;margin-bottom:1rem;">
    <h3 style="margin-top:0;font-size:1rem;">Summary</h3>
    <dl style="margin:0;display:grid;grid-template-columns:auto 1fr auto 1fr;gap:0.3rem 0.8rem;font-size:0.9rem;">
        <dt style="color:#6c757d;">Start:</dt>
        <dd style="margin:0;"><?= htmlspecialchars($q['start_date']) ?></dd>
        <dt style="color:#6c757d;">End:</dt>
        <dd style="margin:0;"><?= $q['end_date'] ? htmlspecialchars($q['end_date']) : '<span style="color:#6c757d;">ongoing</span>' ?></dd>
        <dt style="color:#6c757d;">Sent:</dt>
        <dd style="margin:0;"><?= $q['sent_at'] ? htmlspecialchars(date('Y-m-d H:i', strtotime($q['sent_at']))) : '—' ?></dd>
        <dt style="color:#6c757d;">Accepted:</dt>
        <dd style="margin:0;"><?= $q['accepted_at'] ? htmlspecialchars(date('Y-m-d H:i', strtotime($q['accepted_at']))) : '—' ?></dd>
    </dl>
</div>

<div style="background:#fff;border:1px solid #dee2e6;border-radius:4px;padding:1rem 1.2rem;margin-bottom:1rem;">
    <h3 style="margin-top:0;font-size:1rem;">Line items</h3>
    <?php if (empty($lines)): ?>
        <p style="color:#6c757d;font-style:italic;margin:0;">No lines on this quote yet. <a href="<?= APP_URL ?>/admin/quotes/<?= $quoteId ?>/edit">Add some</a>.</p>
    <?php else: ?>
        <table class="report-table tch-data-table">
            <thead><tr>
                <th>Product</th>
                <th class="center">Unit</th>
                <th class="number">Rate</th>
                <th class="number">Qty</th>
                <th class="number">Line total</th>
                <th class="center">Start</th>
                <th class="center">End</th>
                <th>Notes</th>
            </tr></thead>
            <tbody>
            <?php foreach ($lines as $ln):
                $lineTotal = (float)$ln['bill_rate'] * (float)$ln['units_per_period'];
            ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($ln['product_name']) ?></strong>
                        <?php if (!empty($ln['rate_override_reason'])): ?>
                            <div style="font-size:0.76rem;color:#fd7e14;">Rate override: <?= htmlspecialchars($ln['rate_override_reason']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="center"><?= htmlspecialchars($ln['billing_freq']) ?></td>
                    <td class="number">R<?= number_format((float)$ln['bill_rate'], 2) ?></td>
                    <td class="number"><?= number_format((float)$ln['units_per_period'], 2) ?></td>
                    <td class="number"><strong>R<?= number_format($lineTotal, 2) ?></strong></td>
                    <td class="center"><?= htmlspecialchars((string)($ln['start_date'] ?? '')) ?: '—' ?></td>
                    <td class="center"><?= $ln['end_date'] ? htmlspecialchars($ln['end_date']) : '<span style="color:#6c757d;">ongoing</span>' ?></td>
                    <td><?= htmlspecialchars((string)($ln['notes'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="number" style="font-weight:600;">Quote total per period:</td>
                    <td class="number" style="font-weight:700;font-size:1.05rem;color:#15803d;">R<?= number_format($periodTotal, 2) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>
</div>

<?php if (!empty($q['notes'])): ?>
<div style="background:#fff;border:1px solid #dee2e6;border-radius:4px;padding:1rem 1.2rem;margin-bottom:1rem;">
    <h3 style="margin-top:0;font-size:1rem;">Notes</h3>
    <p style="white-space:pre-wrap;margin:0;"><?= htmlspecialchars($q['notes']) ?></p>
</div>
<?php endif; ?>

<?php if (!empty($q['accepted_at'])): ?>
<div style="background:#d1e7dd;border:1px solid #a3cfbb;border-radius:4px;padding:1rem 1.2rem;margin-bottom:1rem;color:#0f5132;">
    <strong>Accepted <?= htmlspecialchars(date('Y-m-d H:i', strtotime($q['accepted_at']))) ?>
        via <?= htmlspecialchars((string)$q['acceptance_method']) ?></strong>
    <?php if (!empty($q['acceptance_note'])): ?>
        <div style="margin-top:0.3rem;font-size:0.85rem;"><?= nl2br(htmlspecialchars($q['acceptance_note'])) ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
