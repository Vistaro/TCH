<?php
/**
 * Sales Pipeline — Kanban view — /admin/pipeline
 *
 * Same data as /admin/opportunities, rendered as a Kanban board.
 * One column per active sales_stage; cards are open opportunities.
 * Drag-and-drop via native HTML5 events (no library).
 *
 * Move handler is an inline AJAX POST to /ajax/opp-stage-move
 * (see public/index.php route). Server enforces CSRF + permission
 * + reason-required-on-lost.
 *
 * Only open status=open opportunities appear. Closed-Won / Closed-Lost
 * cards disappear from the board once transitioned (they remain on the
 * list view, filterable by status=closed).
 */
require_once APP_ROOT . '/includes/opportunities.php';

$pageTitle = 'Sales Pipeline';
$activeNav = 'pipeline';

$db = getDB();
$canEdit = userCan('opportunities', 'edit');

$stages = fetchSalesStages($db, true);

// Owner filter
$filterOwner = isset($_GET['owner']) && $_GET['owner'] !== '' ? (int)$_GET['owner'] : 0;

$sql = "SELECT o.id, o.opp_ref, o.title, o.stage_id,
               o.expected_value_cents, o.expected_start_date,
               o.owner_user_id, o.contact_name,
               u.full_name AS owner_name,
               cp.full_name AS client_name,
               pp.full_name AS patient_name
          FROM opportunities o
     LEFT JOIN users    u   ON u.id = o.owner_user_id
     LEFT JOIN clients  cl  ON cl.id = o.client_id
     LEFT JOIN persons  cp  ON cp.id = cl.person_id
     LEFT JOIN persons  pp  ON pp.id = o.patient_person_id
         WHERE o.status = 'open'";
$params = [];
if ($filterOwner > 0) {
    $sql .= " AND o.owner_user_id = ?";
    $params[] = $filterOwner;
}
$sql .= " ORDER BY o.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$opps = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by stage_id
$byStage = [];
foreach ($stages as $id => $_s) $byStage[$id] = [];
foreach ($opps as $o) {
    $sid = (int)$o['stage_id'];
    if (!isset($byStage[$sid])) $byStage[$sid] = [];
    $byStage[$sid][] = $o;
}

// Owner picker
$owners = $db->query(
    "SELECT DISTINCT u.id, u.full_name, u.email
       FROM users u
       JOIN opportunities o ON o.owner_user_id = u.id
      WHERE o.status = 'open'
      ORDER BY u.full_name"
)->fetchAll(PDO::FETCH_ASSOC);

$csrf = generateCsrfToken();

require APP_ROOT . '/templates/layouts/admin.php';
?>

<style>
.kanban-board {
    display: flex;
    gap: 0.75rem;
    overflow-x: auto;
    padding-bottom: 1rem;
    align-items: flex-start;
}
.kanban-col {
    flex: 0 0 280px;
    background: #f1f5f9;
    border-radius: 6px;
    padding: 0.6rem 0.6rem 1rem 0.6rem;
    min-height: 200px;
}
.kanban-col-head {
    font-size: 0.85rem;
    font-weight: 600;
    color: #334155;
    margin-bottom: 0.6rem;
    padding: 0.2rem 0.4rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.kanban-col-head .kanban-col-total {
    font-weight: 400;
    color: #64748b;
    font-size: 0.78rem;
}
.kanban-col.drag-over { background: #dbeafe; outline: 2px dashed #60a5fa; }
.kanban-col.is-won  .kanban-col-head { color: #15803d; }
.kanban-col.is-lost .kanban-col-head { color: #991b1b; }

.kanban-card {
    background: #fff;
    border-radius: 4px;
    padding: 0.6rem 0.7rem;
    margin-bottom: 0.5rem;
    box-shadow: 0 1px 2px rgba(0,0,0,0.06);
    border-left: 3px solid #3b82f6;
    cursor: grab;
    font-size: 0.85rem;
}
.kanban-card.dragging { opacity: 0.4; }
.kanban-card:hover { box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
.kanban-card .kanban-ref { color: #64748b; font-size: 0.72rem; }
.kanban-card .kanban-title { font-weight: 600; margin: 0.15rem 0; }
.kanban-card .kanban-meta { color: #64748b; font-size: 0.75rem; }
.kanban-card .kanban-value { color: #15803d; font-weight: 600; font-size: 0.8rem; }

/* Closed-lost dialog */
#lost-dialog { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); z-index:1000; align-items:center; justify-content:center; }
#lost-dialog form { background:#fff; padding:1.5rem; max-width:480px; width:100%; border-radius:6px; }
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;gap:0.6rem;flex-wrap:wrap;">
    <p style="color:#666;margin:0;"><?= count($opps) ?> open opportunit<?= count($opps) === 1 ? 'y' : 'ies' ?></p>
    <div style="display:flex;gap:0.6rem;align-items:center;">
        <?php if (!empty($owners)): ?>
            <form method="get" style="margin:0;">
                <select name="owner" onchange="this.form.submit()" style="padding:0.35rem 0.5rem;font-size:0.85rem;">
                    <option value="">All owners</option>
                    <?php foreach ($owners as $o): ?>
                        <option value="<?= (int)$o['id'] ?>" <?= $filterOwner === (int)$o['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($o['full_name'] ?? $o['email']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/admin/opportunities" class="btn btn-sm" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;">☰ List view</a>
        <?php if (userCan('opportunities', 'create')): ?>
            <a href="<?= APP_URL ?>/admin/opportunities/new" class="btn btn-primary btn-sm">+ New Opportunity</a>
        <?php endif; ?>
    </div>
</div>

<div class="kanban-board" id="kanbanBoard">
    <?php foreach ($stages as $stage):
        $sid = (int)$stage['id'];
        $stageOpps = $byStage[$sid] ?? [];
        $total = 0; foreach ($stageOpps as $o) $total += (int)($o['expected_value_cents'] ?? 0);
        $stageClass = (int)$stage['is_closed_won'] === 1 ? 'is-won' : ((int)$stage['is_closed_lost'] === 1 ? 'is-lost' : '');
    ?>
        <div class="kanban-col <?= $stageClass ?>" data-stage-id="<?= $sid ?>" data-stage-slug="<?= htmlspecialchars($stage['slug']) ?>" data-is-lost="<?= (int)$stage['is_closed_lost'] ?>" data-is-won="<?= (int)$stage['is_closed_won'] ?>">
            <div class="kanban-col-head">
                <span><?= htmlspecialchars($stage['name']) ?> <span style="color:#94a3b8;font-weight:400;">(<?= count($stageOpps) ?>)</span></span>
                <span class="kanban-col-total">R<?= number_format($total / 100, 0) ?></span>
            </div>
            <?php foreach ($stageOpps as $o):
                $valStr = $o['expected_value_cents'] !== null
                    ? 'R' . number_format((int)$o['expected_value_cents'] / 100, 0) . '/mo'
                    : '';
                $who = $o['client_name'] ?: $o['patient_name'] ?: ($o['contact_name'] ?: '—');
            ?>
                <div class="kanban-card" draggable="<?= $canEdit ? 'true' : 'false' ?>" data-opp-id="<?= (int)$o['id'] ?>" onclick="window.location='<?= APP_URL ?>/admin/opportunities/<?= (int)$o['id'] ?>';">
                    <div class="kanban-ref"><?= htmlspecialchars($o['opp_ref']) ?></div>
                    <div class="kanban-title"><?= htmlspecialchars($o['title']) ?></div>
                    <div class="kanban-meta"><?= htmlspecialchars($who) ?></div>
                    <?php if ($valStr): ?>
                        <div class="kanban-value"><?= htmlspecialchars($valStr) ?></div>
                    <?php endif; ?>
                    <?php if ($o['owner_name']): ?>
                        <div class="kanban-meta" style="margin-top:0.2rem;">👤 <?= htmlspecialchars($o['owner_name']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (empty($stageOpps)): ?>
                <div style="color:#94a3b8;font-size:0.78rem;text-align:center;padding:1rem 0.4rem;font-style:italic;">Drop cards here</div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<!-- Closed-Lost reason dialog -->
<div id="lost-dialog">
    <form id="lost-form">
        <h3 style="margin-top:0;">Mark as Closed — Lost</h3>
        <p style="color:#64748b;font-size:0.85rem;">Reason is required. Feeds Acquire-phase reporting.</p>
        <label style="display:block;margin-bottom:0.8rem;">
            <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Reason <span style="color:#dc3545;">*</span></span>
            <select id="lost-reason" required style="width:100%;padding:0.4rem 0.6rem;">
                <option value="">— Pick one —</option>
                <?php foreach (reasonLostOptions() as $k => $lbl): ?>
                    <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="display:block;margin-bottom:0.8rem;">
            <span style="display:block;font-size:0.82rem;color:#495057;margin-bottom:0.2rem;">Note (optional)</span>
            <textarea id="lost-note" rows="3" style="width:100%;padding:0.4rem 0.6rem;"></textarea>
        </label>
        <div style="display:flex;gap:0.5rem;justify-content:flex-end;">
            <button type="button" class="btn" style="background:#f1f5f9;color:#334155;" onclick="TCH_cancelLost()">Cancel</button>
            <button type="submit" class="btn" style="background:#dc3545;color:#fff;">Mark as lost</button>
        </div>
    </form>
</div>

<script>
(function(){
    const CSRF = <?= json_encode($csrf) ?>;
    const MOVE_URL = <?= json_encode(APP_URL . '/ajax/opp-stage-move') ?>;
    const canEdit = <?= $canEdit ? 'true' : 'false' ?>;

    let draggedCard = null;
    let sourceCol = null;
    let pendingMove = null;  // {oppId, newStageId, oldCol, card}

    if (!canEdit) return;

    document.querySelectorAll('.kanban-card').forEach(card => {
        card.addEventListener('dragstart', e => {
            draggedCard = card;
            sourceCol = card.parentElement;
            card.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });
        card.addEventListener('dragend', () => {
            card.classList.remove('dragging');
        });
    });

    document.querySelectorAll('.kanban-col').forEach(col => {
        col.addEventListener('dragover', e => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            col.classList.add('drag-over');
        });
        col.addEventListener('dragleave', () => col.classList.remove('drag-over'));
        col.addEventListener('drop', e => {
            e.preventDefault();
            col.classList.remove('drag-over');
            if (!draggedCard) return;
            if (col === sourceCol) return;

            const oppId = parseInt(draggedCard.dataset.oppId, 10);
            const newStageId = parseInt(col.dataset.stageId, 10);
            const isLost = col.dataset.isLost === '1';
            const isWon  = col.dataset.isWon === '1';

            // Optimistic move
            col.insertBefore(draggedCard, col.querySelector('.kanban-card') || null);

            if (isLost) {
                pendingMove = { oppId, newStageId, oldCol: sourceCol, card: draggedCard };
                document.getElementById('lost-dialog').style.display = 'flex';
                document.getElementById('lost-reason').focus();
                return;
            }
            if (isWon) {
                if (!confirm('Mark as Closed — Won? Any linked draft contract will be flipped to active, and the card will leave the board.')) {
                    sourceCol.appendChild(draggedCard);
                    return;
                }
            }
            TCH_sendMove(oppId, newStageId, null, null, sourceCol, draggedCard, isWon || isLost);
        });
    });

    document.getElementById('lost-form').addEventListener('submit', e => {
        e.preventDefault();
        const reason = document.getElementById('lost-reason').value;
        const note = document.getElementById('lost-note').value;
        if (!reason) return;
        const m = pendingMove;
        document.getElementById('lost-dialog').style.display = 'none';
        TCH_sendMove(m.oppId, m.newStageId, reason, note, m.oldCol, m.card, true);
        pendingMove = null;
        document.getElementById('lost-reason').value = '';
        document.getElementById('lost-note').value = '';
    });

    window.TCH_cancelLost = function(){
        document.getElementById('lost-dialog').style.display = 'none';
        if (pendingMove) {
            pendingMove.oldCol.appendChild(pendingMove.card);
            pendingMove = null;
        }
    };

    function TCH_sendMove(oppId, newStageId, reason, note, oldCol, card, removeOnSuccess) {
        const body = new URLSearchParams();
        body.append('csrf_token', CSRF);
        body.append('opportunity_id', oppId);
        body.append('new_stage_id', newStageId);
        if (reason) body.append('reason_lost', reason);
        if (note)   body.append('reason_lost_note', note);

        fetch(MOVE_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                oldCol.appendChild(card);  // roll back
                alert(data.message || 'Move rejected.');
                return;
            }
            if (removeOnSuccess) {
                // Card leaves the open-pipeline board once closed
                card.remove();
            }
        })
        .catch(err => {
            oldCol.appendChild(card);
            alert('Network error: ' + err.message);
        });
    }
})();
</script>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
