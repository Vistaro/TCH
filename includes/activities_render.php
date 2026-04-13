<?php
/**
 * Activities & Tasks timeline — render + save helpers.
 *
 * Mirrors Nexus CRM's pattern (see mailbox reply
 * msg-2026-04-13-0829-001): one unified `activities` table,
 * type is cosmetic, polymorphic via entity_type/entity_id.
 *
 * TCH adds source/source_ref/source_batch (migration 011) so
 * import-derived rows carry queryable provenance.
 *
 * Public API:
 *   renderActivityTimeline(string $entityType, int $entityId): void
 *       Echoes the panel HTML for the given entity.
 *
 *   saveActivityFromPost(string $entityType, int $entityId): array
 *       Validates POST, inserts an activity row, returns
 *       ['ok'=>bool, 'msg'=>string].
 *
 *   logSystemActivity(string $entityType, int $entityId, string $subject,
 *                     string $notes, string $sourceRef,
 *                     ?string $sourceBatch = null): int
 *       For server-side imports/workflows. Returns inserted id.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

/**
 * Fetch every activity row for an entity, newest first.
 * Joined to type lookup, creator, and assignee.
 */
function fetchActivitiesForEntity(string $entityType, int $entityId): array
{
    $sql = "SELECT a.*,
                   at.name  AS type_name,
                   at.icon  AS type_icon,
                   at.color AS type_color,
                   u.full_name  AS user_name,
                   au.full_name AS assigned_name
            FROM   activities a
            JOIN   activity_types at ON at.id = a.activity_type_id
            LEFT JOIN users u  ON u.id  = a.user_id
            LEFT JOIN users au ON au.id = a.assigned_to
            WHERE  a.entity_type = ? AND a.entity_id = ?
            ORDER BY a.activity_date DESC, a.created_at DESC";
    $stmt = getDB()->prepare($sql);
    $stmt->execute([$entityType, $entityId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Save an activity row from the inline form POST.
 * Returns ['ok'=>bool, 'msg'=>string].
 */
function saveActivityFromPost(string $entityType, int $entityId): array
{
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        return ['ok' => false, 'msg' => 'Invalid form submission.'];
    }
    $typeId   = (int)($_POST['activity_type_id'] ?? 0);
    $isTask   = !empty($_POST['is_task']) ? 1 : 0;
    $subject  = trim((string)($_POST['subject'] ?? ''));
    $notes    = trim((string)($_POST['notes']   ?? ''));
    $whenStr  = trim((string)($_POST['activity_date'] ?? ''));
    $assignTo = $_POST['assigned_to'] !== '' ? (int)$_POST['assigned_to'] : null;

    if ($typeId <= 0) {
        return ['ok' => false, 'msg' => 'Activity type is required.'];
    }
    if ($whenStr === '') {
        $whenStr = date('Y-m-d H:i:s');
    } else {
        // datetime-local arrives as 'YYYY-MM-DDTHH:MM'
        $whenStr = str_replace('T', ' ', $whenStr);
        if (strlen($whenStr) === 16) {
            $whenStr .= ':00';
        }
    }
    if ($subject === '' && $notes === '') {
        return ['ok' => false, 'msg' => 'Subject or notes is required.'];
    }

    $me = currentEffectiveUser();
    $userId = $me['id'] ?? null;

    $stmt = getDB()->prepare(
        'INSERT INTO activities
            (activity_type_id, entity_type, entity_id, user_id, subject, notes,
             source, activity_date, is_task, task_status, assigned_to)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $typeId, $entityType, $entityId, $userId,
        substr($subject, 0, 255),
        $notes !== '' ? $notes : null,
        'manual',
        $whenStr,
        $isTask,
        $isTask ? 'pending' : 'pending',
        $assignTo,
    ]);
    return ['ok' => true, 'msg' => $isTask ? 'Task added.' : 'Activity logged.'];
}

/**
 * Server-side helper for system / import insertion. Returns the new row id.
 *
 * @param string      $entityType   e.g. 'persons'
 * @param int         $entityId     PK of the entity
 * @param string      $subject      Short single-line summary
 * @param string      $notes        Body (longer description)
 * @param string      $sourceRef    e.g. 'Ross Intake 1-9.xlsx#Cohort 1!N5'
 * @param string|null $sourceBatch  e.g. 'tuniti-attendance-2026-04-13'
 */
function logSystemActivity(
    string $entityType,
    int $entityId,
    string $subject,
    string $notes,
    string $sourceRef,
    ?string $sourceBatch = null
): int {
    $db = getDB();
    // Resolve System type id (created in initial seed; fall back to lookup-or-insert)
    $row = $db->query("SELECT id FROM activity_types WHERE name = 'System' LIMIT 1")->fetch();
    if (!$row) {
        $db->prepare("INSERT INTO activity_types (name, icon, color, sort_order) VALUES ('System','fa-robot','#adb5bd',70)")->execute();
        $typeId = (int)$db->lastInsertId();
    } else {
        $typeId = (int)$row['id'];
    }
    // System user id — try claude-bot, fall back to NULL
    $row2 = $db->query("SELECT id FROM users WHERE email = 'claude-bot@tch' OR email = 'system@tch' LIMIT 1")->fetch();
    $userId = $row2 ? (int)$row2['id'] : null;

    $stmt = $db->prepare(
        'INSERT INTO activities
            (activity_type_id, entity_type, entity_id, user_id, subject, notes,
             source, source_ref, source_batch, activity_date, is_task, task_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0, "pending")'
    );
    $stmt->execute([
        $typeId, $entityType, $entityId, $userId,
        substr($subject, 0, 255),
        $notes !== '' ? $notes : null,
        'import', $sourceRef, $sourceBatch,
    ]);
    return (int)$db->lastInsertId();
}

/**
 * Echo the full Activities & Tasks panel for the given entity.
 *
 * $canPost gates the "+ Add Note" / "+ Add Task" buttons and the form.
 * Read-only viewers (admins with only .read) should pass false so they
 * don't see write controls that silently fail on submit.
 */
function renderActivityTimeline(string $entityType, int $entityId, bool $canPost = true): void
{
    $rows  = fetchActivitiesForEntity($entityType, $entityId);
    $types = getDB()->query('SELECT id, name FROM activity_types WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
    $users = getDB()->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll();
    $csrf  = csrfField();
    ?>
    <details class="card activity-panel" style="margin-top:1.5rem;">
        <summary style="cursor:pointer;padding:0.75rem 1rem;background:#f8f9fa;list-style:none;display:flex;justify-content:space-between;align-items:center;">
            <h3 style="margin:0;">Notes
                <span style="font-weight:400;font-size:0.85rem;color:#6c757d;margin-left:0.5rem;">
                    <?= count($rows) ?> entr<?= count($rows) === 1 ? 'y' : 'ies' ?>
                </span>
            </h3>
            <div onclick="event.stopPropagation();event.preventDefault();" style="display:flex;gap:0.5rem;align-items:center;">
                <?php if ($canPost): ?>
                    <button type="button" class="btn btn-primary btn-sm" onclick="event.stopPropagation();var p=this.closest('details');if(!p.open)p.open=true;document.getElementById('activity-form').style.display='block';document.getElementById('activity-is-task').value='0';document.getElementById('act-assign-row').style.display='none';">+ Add Note</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="event.stopPropagation();var p=this.closest('details');if(!p.open)p.open=true;document.getElementById('activity-form').style.display='block';document.getElementById('activity-is-task').value='1';document.getElementById('act-assign-row').style.display='';">+ Add Task</button>
                <?php endif; ?>
                <span style="color:#6c757d;font-size:0.85rem;margin-left:0.5rem;">▾</span>
            </div>
        </summary>

        <?php if ($canPost): ?>
        <form id="activity-form" method="POST" style="display:none;padding:1rem;border-bottom:1px solid #eee;background:#f8f9fa;">
            <?= $csrf ?>
            <input type="hidden" name="action" value="save_activity">
            <input type="hidden" name="is_task" id="activity-is-task" value="0">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem;">
                <div>
                    <label>Type</label>
                    <select name="activity_type_id" class="form-control" required>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Date &amp; Time</label>
                    <input type="datetime-local" name="activity_date" class="form-control"
                           value="<?= date('Y-m-d\TH:i') ?>" required>
                </div>
                <div id="act-assign-row" style="display:none;">
                    <label>Assign To</label>
                    <select name="assigned_to" class="form-control">
                        <option value="">— unassigned —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="margin-top:0.5rem;">
                <label>Subject</label>
                <input type="text" name="subject" class="form-control" maxlength="255">
            </div>
            <div style="margin-top:0.5rem;">
                <label>Notes</label>
                <textarea name="notes" class="form-control" rows="3"></textarea>
            </div>
            <div style="margin-top:0.5rem;text-align:right;">
                <button type="button" class="btn btn-link"
                        onclick="document.getElementById('activity-form').style.display='none';">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
        <?php endif; // $canPost ?>

        <div class="activity-list" style="padding:0.5rem 0;">
            <?php if (!$rows): ?>
                <p style="padding:1rem;color:#6c757d;margin:0;">No activities yet.</p>
            <?php else: foreach ($rows as $r):
                $color = $r['type_color'] ?: '#6c757d';
                $icon  = $r['type_icon']  ?: 'fa-circle';
                $when  = strtotime($r['activity_date']);
                $hdrDate = $when ? date('d M Y', $when) : '';
                $bodyDate = $when ? date('d M Y H:i', $when) : '';
                $status = $r['is_task']
                    ? ($r['task_status'] === 'completed' ? 'Completed' : ucfirst($r['task_status']))
                    : 'Logged';
                $statusClass = $r['is_task']
                    ? ($r['task_status'] === 'completed' ? 'success' : 'warning')
                    : 'secondary';
                $rowId = (int)$r['id'];
            ?>
                <div class="activity-row" style="display:flex;gap:0.75rem;padding:0.75rem 1rem;border-top:1px solid #f0f0f0;">
                    <div style="width:28px;height:28px;border-radius:50%;background:<?= htmlspecialchars($color) ?>;color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas <?= htmlspecialchars($icon) ?>" style="font-size:0.8rem;"></i>
                    </div>
                    <div style="flex:1;">
                        <div style="cursor:pointer;" onclick="var b=this.nextElementSibling;b.style.display=b.style.display==='none'?'':'none';">
                            <strong><?= htmlspecialchars($r['type_name']) ?></strong>
                            <?php if ($r['subject']): ?>
                                — <?= htmlspecialchars($r['subject']) ?>
                            <?php endif; ?>
                            <span class="badge badge-<?= $statusClass ?>" style="margin-left:0.5rem;"><?= $status ?></span>
                            <span style="float:right;color:#6c757d;font-size:0.85rem;"><?= $hdrDate ?></span>
                        </div>
                        <div style="display:none;margin-top:0.5rem;font-size:0.9rem;color:#495057;">
                            <div style="color:#6c757d;font-size:0.85rem;">
                                Logged <?= $bodyDate ?>
                                <?php if ($r['user_name']): ?> · by <strong><?= htmlspecialchars($r['user_name']) ?></strong><?php endif; ?>
                                <?php if ($r['assigned_name']): ?> · assigned to <strong><?= htmlspecialchars($r['assigned_name']) ?></strong><?php endif; ?>
                            </div>
                            <?php if ($r['notes']): ?>
                                <div style="margin-top:0.5rem;white-space:pre-wrap;"><?= nl2br(htmlspecialchars($r['notes'])) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($r['source_ref'])): ?>
                                <div style="margin-top:0.5rem;color:#6c757d;font-size:0.8rem;">
                                    <i class="fas fa-link"></i>
                                    Source: <code><?= htmlspecialchars($r['source_ref']) ?></code>
                                    <?php if (!empty($r['source_batch'])): ?>
                                        <span style="margin-left:0.5rem;">(batch: <?= htmlspecialchars($r['source_batch']) ?>)</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </details>
    <?php
}
