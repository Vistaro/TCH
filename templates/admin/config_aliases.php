<?php
/**
 * Timesheet Aliases admin — /admin/config/aliases
 *
 * Maps raw cell / header names from the Tuniti Caregiver Timesheet
 * to canonical persons.id rows. Phase 1 of the D3 ingest plan —
 * until every alias resolves, no roster ingest can run.
 *
 * Actions:
 *   map_alias        : set alias.person_id = <existing person>
 *   unmap_alias      : clear alias.person_id, back to 'unresolved'
 *   create_and_map   : INSERT new persons row + map alias to it
 */

$pageTitle = 'Timesheet Aliases';
$activeNav = 'config-aliases';

$db      = getDB();
$canEdit = userCan('config_aliases', 'edit');
$flash = ''; $flashType = 'success';

// ── Handle POST actions ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } else {
        $action   = $_POST['action'] ?? '';
        $aliasId  = (int)($_POST['alias_id'] ?? 0);

        if ($action === 'map_alias') {
            $personId = (int)($_POST['person_id'] ?? 0);

            // Pre-fetch the alias + target person to see if promotion needed
            $aliasStmt = $db->prepare("SELECT person_role FROM timesheet_name_aliases WHERE id = ?");
            $aliasStmt->execute([$aliasId]);
            $aliasRole = $aliasStmt->fetchColumn();
            $personStmt = $db->prepare("SELECT person_type FROM persons WHERE id = ?");
            $personStmt->execute([$personId]);
            $personType = (string)$personStmt->fetchColumn();

            // Source info for the timeline note
            $srcStmt = $db->prepare("SELECT alias_text, first_seen_source FROM timesheet_name_aliases WHERE id = ?");
            $srcStmt->execute([$aliasId]);
            $srcInfo = $srcStmt->fetch(PDO::FETCH_ASSOC) ?: ['alias_text' => '', 'first_seen_source' => ''];

            $db->beginTransaction();
            try {
                // Auto-promote: caregiver-alias mapped to student-only person
                if ($aliasRole === 'caregiver'
                        && !str_contains($personType, 'caregiver')) {
                    $db->prepare(
                        "UPDATE persons
                            SET person_type = TRIM(BOTH ',' FROM CONCAT_WS(',', person_type, 'caregiver'))
                          WHERE id = ?"
                    )->execute([$personId]);
                    $db->prepare("INSERT IGNORE INTO caregivers (person_id) VALUES (?)")
                       ->execute([$personId]);
                    // Flip the student record to qualified
                    $db->prepare(
                        "UPDATE students
                            SET qualified = 'Yes — via Timesheet'
                          WHERE person_id = ?"
                    )->execute([$personId]);
                    logActivity('promoted_to_caregiver', 'config_aliases', 'persons', $personId,
                        'Promoted student to qualified caregiver via Timesheet alias',
                        ['person_type' => $personType, 'qualified' => null],
                        ['person_type' => trim($personType . ',caregiver', ','), 'qualified' => 'Yes — via Timesheet']);
                    // Timeline note on the person profile
                    if (function_exists('logSystemActivity')) {
                        $body = "Student was giving care per the Tuniti Caregiver Timesheet — "
                              . "first seen as \"" . $srcInfo['alias_text'] . "\" in "
                              . $srcInfo['first_seen_source'] . ". "
                              . "Assumed qualified on the basis that they were actively providing care. "
                              . "Student record.qualified set to 'Yes — via Timesheet'.";
                        logSystemActivity('persons', $personId,
                            'Auto-promoted to qualified caregiver',
                            $body,
                            'config_aliases#promote',
                            'alias-promote-' . $aliasId);
                    }
                }

                $stmt = $db->prepare(
                    "UPDATE timesheet_name_aliases
                        SET person_id = ?, confidence = 'confirmed',
                            mapped_at = NOW(), mapped_by_user_id = ?
                      WHERE id = ?"
                );
                $stmt->execute([$personId, (int)($_SESSION['user_id'] ?? 0), $aliasId]);

                // Re-map trigger — cascade to daily_roster rows that were
                // resolved via this alias. Source is the roster row's
                // source_alias_id FK. Handles caregiver- and patient-role
                // aliases; client-role re-derivation is via patients.client_id
                // which is its own separate flow, so not cascaded here.
                $cascadeRows = 0;
                if ($aliasRole === 'caregiver') {
                    $cg = $db->prepare(
                        "UPDATE daily_roster
                            SET caregiver_id = ?
                          WHERE source_alias_id = ?
                            AND (caregiver_id IS NULL OR caregiver_id <> ?)"
                    );
                    $cg->execute([$personId, $aliasId, $personId]);
                    $cascadeRows = $cg->rowCount();
                } elseif ($aliasRole === 'patient') {
                    $pt = $db->prepare(
                        "UPDATE daily_roster
                            SET patient_person_id = ?
                          WHERE source_alias_id = ?
                            AND (patient_person_id IS NULL OR patient_person_id <> ?)"
                    );
                    $pt->execute([$personId, $aliasId, $personId]);
                    $cascadeRows = $pt->rowCount();
                }

                logActivity('alias_mapped', 'config_aliases', 'timesheet_name_aliases', $aliasId,
                    'Mapped alias to person_id=' . $personId
                        . ($cascadeRows > 0 ? ' — cascaded to ' . $cascadeRows . ' roster rows' : ''),
                    null, ['person_id' => $personId, 'cascaded_roster_rows' => $cascadeRows]);
                $db->commit();
                $flash = 'Alias mapped.'
                    . ($cascadeRows > 0
                        ? ' ' . $cascadeRows . ' roster row' . ($cascadeRows === 1 ? '' : 's')
                            . ' re-pointed to the new canonical person.'
                        : '');
            } catch (Throwable $e) {
                $db->rollBack();
                $flash = 'Error: ' . $e->getMessage(); $flashType = 'error';
            }
        }

        elseif ($action === 'unmap_alias') {
            $stmt = $db->prepare(
                "UPDATE timesheet_name_aliases
                    SET person_id = NULL, confidence = 'unresolved',
                        mapped_at = NULL, mapped_by_user_id = NULL
                  WHERE id = ?"
            );
            $stmt->execute([$aliasId]);
            logActivity('alias_unmapped', 'config_aliases', 'timesheet_name_aliases', $aliasId,
                'Cleared alias mapping', null, null);
            $flash = 'Mapping cleared.';
        }

        elseif ($action === 'create_and_map') {
            $firstName = trim($_POST['new_first_name'] ?? '');
            $lastName  = trim($_POST['new_last_name']  ?? '');
            $role      = $_POST['new_role'] ?? '';
            $fullName  = trim($firstName . ' ' . $lastName);
            if ($fullName === '' || !in_array($role, ['caregiver','patient','client','student'], true)) {
                $flash = 'New person needs a name and valid role.'; $flashType = 'error';
            } else {
                $db->beginTransaction();
                try {
                    // Auto-assign next sequential TCH ID (same pattern as
                    // patient_create.php / client_create.php).
                    $nextNum = (int)$db->query(
                        "SELECT COALESCE(MAX(CAST(SUBSTRING(tch_id,5) AS UNSIGNED)),0) + 1
                           FROM persons
                          WHERE tch_id LIKE 'TCH-%' AND tch_id <> 'TCH-UNBILLED'"
                    )->fetchColumn();
                    $tchId = 'TCH-' . str_pad((string)$nextNum, 6, '0', STR_PAD_LEFT);

                    $ins = $db->prepare(
                        "INSERT INTO persons (full_name, first_name, last_name, person_type, tch_id, created_at)
                         VALUES (?, ?, ?, ?, ?, NOW())"
                    );
                    $ins->execute([$fullName, $firstName, $lastName, $role, $tchId]);
                    $newId = (int)$db->lastInsertId();

                    // Side-table row for the role
                    if ($role === 'caregiver') {
                        $db->prepare("INSERT IGNORE INTO caregivers (person_id) VALUES (?)")->execute([$newId]);
                    } elseif ($role === 'patient') {
                        // Patients need a client_id FK — park under client_id=NULL-placeholder (1) for now
                        // Caller will re-link via patient_view once proper bill-payer known.
                        $db->prepare("INSERT IGNORE INTO patients (person_id, client_id) VALUES (?, ?)")
                           ->execute([$newId, $newId]);
                        // Ensure a minimal clients shell exists for the self-link
                        $db->prepare("INSERT IGNORE INTO clients (id, person_id) VALUES (?, ?)")
                           ->execute([$newId, $newId]);
                    } elseif ($role === 'client') {
                        $db->prepare("INSERT IGNORE INTO clients (id, person_id) VALUES (?, ?)")
                           ->execute([$newId, $newId]);
                    }

                    $upd = $db->prepare(
                        "UPDATE timesheet_name_aliases
                            SET person_id = ?, confidence = 'confirmed',
                                mapped_at = NOW(), mapped_by_user_id = ?
                          WHERE id = ?"
                    );
                    $upd->execute([$newId, (int)($_SESSION['user_id'] ?? 0), $aliasId]);

                    logActivity('alias_created_person', 'config_aliases', 'persons', $newId,
                        'Created new ' . $role . ' "' . $fullName . '" via alias mapping', null,
                        ['full_name' => $fullName, 'role' => $role]);
                    $db->commit();
                    $flash = 'Created ' . $role . ' "' . $fullName . '" and mapped alias.';
                } catch (Throwable $e) {
                    $db->rollBack();
                    $flash = 'Error: ' . $e->getMessage(); $flashType = 'error';
                }
            }
        }
    }

    header('Location: ' . APP_URL . '/admin/config/aliases'
           . '?role=' . urlencode($_POST['return_role'] ?? 'all')
           . '&msg=' . urlencode($flash) . '&type=' . urlencode($flashType));
    exit;
}

// Pick up flash from URL
if ($flash === '' && isset($_GET['msg'])) {
    $flash = (string)$_GET['msg'];
    $flashType = (string)($_GET['type'] ?? 'success');
}

// ── Load list ─────────────────────────────────────────────────────────
// Filter groups: caregivers+students are a single person category in TCH's
// model (students giving care are promoted to caregivers).
$roleFilter = $_GET['role'] ?? 'all';
$filterGroups = [
    'cg_stu'  => ['caregiver', 'student'],
    'patient' => ['patient'],
    'client'  => ['client'],
];
if (!in_array($roleFilter, array_merge(['all'], array_keys($filterGroups)), true)) {
    $roleFilter = 'all';
}

$sql = "SELECT a.*, p.full_name AS matched_name, p.tch_id AS matched_tch_id
          FROM timesheet_name_aliases a
     LEFT JOIN persons p ON p.id = a.person_id";
$params = [];
if ($roleFilter !== 'all') {
    $roles = $filterGroups[$roleFilter];
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $sql .= " WHERE a.person_role IN ($placeholders)";
    $params = array_merge($params, $roles);
}

// Default server-side sort: unresolved first, then alias alphabetically.
// Runtime sort + filter handled client-side by tch-table.js — no custom
// anchor wrappers needed on the headers (they break the library's click
// handler).
$sql .= " ORDER BY (a.confidence = 'unresolved') DESC, a.alias_text ASC";
$stmt = $db->prepare($sql); $stmt->execute($params);
$aliases = $stmt->fetchAll();

// Counts by filter-group, not raw role
$rawCounts = $db->query(
    "SELECT person_role, confidence, COUNT(*) n
       FROM timesheet_name_aliases
     GROUP BY person_role, confidence"
)->fetchAll();
$countsByGroup = [];
foreach ($rawCounts as $c) {
    // Find which group this role belongs to
    foreach ($filterGroups as $groupKey => $roles) {
        if (in_array($c['person_role'], $roles, true)) {
            $countsByGroup[$groupKey][$c['confidence']] =
                ($countsByGroup[$groupKey][$c['confidence']] ?? 0) + (int)$c['n'];
            break;
        }
    }
}
$groupLabels = [
    'cg_stu'  => 'Caregivers & Students',
    'patient' => 'Patients',
    'client'  => 'Clients',
];

// Build suggestions for each unresolved alias — soundex + levenshtein ≤ 3
// (SQL-side since persons is only a few hundred rows)
$suggestionsCache = [];
function getSuggestions(PDO $db, array $a): array {
    $key = $a['person_role'] . '|' . $a['alias_text'];
    static $memo = [];
    if (isset($memo[$key])) return $memo[$key];

    $role = $a['person_role'];
    $txt  = $a['alias_text'];

    // Pull candidate persons matching the role.
    // For caregiver: also search students (who may be caregivers-in-training
    // not yet promoted). Map-handler will promote if needed.
    $roleWhere = '';
    if ($role === 'caregiver') $roleWhere = "(FIND_IN_SET('caregiver', p.person_type) OR FIND_IN_SET('student', p.person_type))";
    elseif ($role === 'patient') $roleWhere = "FIND_IN_SET('patient', p.person_type)";
    elseif ($role === 'client')  $roleWhere = "FIND_IN_SET('client',  p.person_type)";
    elseif ($role === 'student') $roleWhere = "FIND_IN_SET('student', p.person_type)";
    if (!$roleWhere) return $memo[$key] = [];

    // Split alias into tokens for per-word matching against first/last/known_as.
    // "Emily Mentula" should suggest "Emily Lebohang Mentula" via first+last hit.
    $tokens = preg_split('/\s+/', trim(preg_replace('/[^A-Za-z\s]/', ' ', $txt)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $firstTok = $tokens[0] ?? $txt;
    $lastTok  = $tokens[count($tokens) - 1] ?? $txt;

    $q = "SELECT p.id, p.full_name, p.known_as, p.tch_id, p.person_type
            FROM persons p
           WHERE $roleWhere
             AND (p.archived_at IS NULL)
             AND (
                  SOUNDEX(p.full_name) = SOUNDEX(?)
               OR LOWER(p.full_name) LIKE ?
               OR SOUNDEX(COALESCE(p.known_as,'')) = SOUNDEX(?)
               OR SOUNDEX(COALESCE(p.first_name,'')) = SOUNDEX(?)
               OR SOUNDEX(COALESCE(p.last_name,''))  = SOUNDEX(?)
               OR (LOWER(p.first_name) = ? AND LOWER(p.last_name) = ?)
             )
           LIMIT 20";
    $stmt = $db->prepare($q);
    $like = '%' . mb_strtolower($txt) . '%';
    $stmt->execute([
        $txt, $like, $txt,
        $firstTok, $lastTok,
        mb_strtolower($firstTok), mb_strtolower($lastTok),
    ]);
    $rows = $stmt->fetchAll();

    // Score by levenshtein on full_name
    foreach ($rows as &$r) {
        $r['_score'] = levenshtein(mb_strtolower($txt), mb_strtolower($r['full_name']));
    }
    usort($rows, fn($a, $b) => $a['_score'] <=> $b['_score']);
    return $memo[$key] = array_slice($rows, 0, 8);
}

// Full list of candidate persons for a role — used when auto-suggestions
// produce nothing and the user needs to pick manually.
function getAllCandidates(PDO $db, string $role): array {
    static $memo = [];
    if (isset($memo[$role])) return $memo[$role];
    $roleWhere = '';
    if ($role === 'caregiver')     $roleWhere = "(FIND_IN_SET('caregiver', person_type) OR FIND_IN_SET('student', person_type))";
    elseif ($role === 'patient')   $roleWhere = "FIND_IN_SET('patient', person_type)";
    elseif ($role === 'client')    $roleWhere = "FIND_IN_SET('client',  person_type)";
    elseif ($role === 'student')   $roleWhere = "FIND_IN_SET('student', person_type)";
    if (!$roleWhere) return $memo[$role] = [];
    $q = "SELECT id, full_name, tch_id
            FROM persons
           WHERE $roleWhere AND archived_at IS NULL
        ORDER BY full_name";
    return $memo[$role] = $db->query($q)->fetchAll();
}

require APP_ROOT . '/templates/layouts/admin.php';
?>

<?php if ($flash): ?>
<div class="alert alert-<?= htmlspecialchars($flashType === 'error' ? 'error' : 'success') ?>" style="margin-bottom:1rem;">
    <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<p style="color:#6c757d;max-width:960px;">
    Raw name strings from the Tuniti Caregiver Timesheet (column headers and shift cells)
    mapped to canonical persons records. Unresolved rows block the Timesheet ingest —
    every name has to point at a real person before we can load shifts.
</p>

<!-- Summary tiles -->
<div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">
<?php
$totalAll = 0; $totalUnres = 0;
foreach ($groupLabels as $groupKey => $label):
    $conf = $countsByGroup[$groupKey] ?? [];
    $tot = array_sum($conf); $totalAll += $tot;
    $unres = $conf['unresolved'] ?? 0; $totalUnres += $unres;
    if (!$tot) continue;
?>
    <a href="?role=<?= htmlspecialchars($groupKey) ?>" style="background:<?= $roleFilter === $groupKey ? '#0d6efd' : '#f8f9fa' ?>;color:<?= $roleFilter === $groupKey ? '#fff' : '#212529' ?>;padding:0.6rem 1rem;border-radius:6px;text-decoration:none;border:1px solid #dee2e6;min-width:160px;">
        <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;opacity:0.75;"><?= htmlspecialchars($label) ?></div>
        <div style="font-size:1.3rem;font-weight:600;"><?= $unres ?> / <?= $tot ?></div>
        <div style="font-size:0.75rem;opacity:0.75;">unresolved / total</div>
    </a>
<?php endforeach; ?>
    <a href="?role=all" style="background:<?= $roleFilter === 'all' ? '#212529' : '#f8f9fa' ?>;color:<?= $roleFilter === 'all' ? '#fff' : '#212529' ?>;padding:0.6rem 1rem;border-radius:6px;text-decoration:none;border:1px solid #dee2e6;min-width:140px;">
        <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;opacity:0.75;">All</div>
        <div style="font-size:1.3rem;font-weight:600;"><?= $totalUnres ?> / <?= $totalAll ?></div>
        <div style="font-size:0.75rem;opacity:0.75;">unresolved / total</div>
    </a>
</div>

<table class="report-table tch-data-table" style="table-layout:fixed;">
    <thead>
        <tr>
            <th style="width:22%;">Alias</th>
            <th style="width:10%;">Role</th>
            <th style="width:14%;">Status</th>
            <th style="width:30%;" data-filterable="false">Mapping / Suggestions</th>
            <th style="width:24%;" data-sortable="false" data-filterable="false">Action</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($aliases as $a): ?>
        <?php $isUnres = $a['confidence'] === 'unresolved'; ?>
        <tr style="background:<?= $isUnres ? '#fff8e1' : 'inherit' ?>;">
            <td><strong><?= htmlspecialchars($a['alias_text']) ?></strong>
                <?php if (!empty($a['matched_name'])): ?>
                <div style="font-size:0.75rem;color:#198754;">→ <?= htmlspecialchars($a['matched_name']) ?><?= $a['matched_tch_id'] ? ' (' . htmlspecialchars($a['matched_tch_id']) . ')' : '' ?></div>
                <?php endif; ?>
                <div style="font-size:0.7rem;color:#6c757d;word-break:break-all;"><?= htmlspecialchars($a['first_seen_source'] ?? '') ?></div>
            </td>
            <td><?= htmlspecialchars(ucfirst($a['person_role'])) ?></td>
            <td>
                <?php if ($a['confidence'] === 'auto_exact'): ?>
                    <span style="color:#198754;font-weight:600;">● Auto-matched</span>
                <?php elseif ($a['confidence'] === 'confirmed'): ?>
                    <span style="color:#0d6efd;font-weight:600;">● Confirmed</span>
                <?php elseif ($a['confidence'] === 'auto_fuzzy'): ?>
                    <span style="color:#fd7e14;font-weight:600;">● Fuzzy — confirm</span>
                <?php else: ?>
                    <span style="color:#dc3545;font-weight:600;">● Unresolved</span>
                <?php endif; ?>
            </td>
            <td>
            <?php if ($a['person_id']): ?>
                <a href="<?= APP_URL ?>/admin/<?= $a['person_role'] === 'client' ? 'clients' : ($a['person_role'] === 'patient' ? 'patients' : ($a['person_role'] === 'student' ? 'students' : 'caregivers')) ?>/<?= (int)$a['person_id'] ?>">
                    <?= htmlspecialchars($a['matched_name']) ?>
                </a>
                <code style="font-size:0.75rem;color:#6c757d;"><?= htmlspecialchars($a['matched_tch_id'] ?? '') ?></code>
            <?php else: ?>
                <?php $sugs = getSuggestions($db, $a); ?>
                <?php if ($sugs): ?>
                    <form method="POST" style="display:flex;gap:0.4rem;margin:0;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="map_alias">
                        <input type="hidden" name="alias_id" value="<?= (int)$a['id'] ?>">
                        <input type="hidden" name="return_role" value="<?= htmlspecialchars($roleFilter) ?>">
                        <select name="person_id" class="form-control form-control-sm" style="font-size:0.85rem;">
                            <option value="">Pick a match…</option>
                            <?php foreach ($sugs as $s): ?>
                            <?php
                            $types   = $s['person_type'] ?? '';
                            $isCare  = str_contains($types, 'caregiver');
                            $isStu   = str_contains($types, 'student');
                            $promote = ($a['person_role'] === 'caregiver' && !$isCare && $isStu);
                            ?>
                            <option value="<?= (int)$s['id'] ?>">
                                <?= htmlspecialchars($s['full_name']) ?>
                                <?= $s['tch_id'] ? ' (' . htmlspecialchars($s['tch_id']) . ')' : '' ?>
                                <?= $promote ? ' [STUDENT — will promote]' : '' ?>
                                — dist <?= $s['_score'] ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($canEdit): ?>
                        <button class="btn btn-sm btn-primary" type="submit">Map</button>
                        <?php endif; ?>
                    </form>
                <?php else: ?>
                    <?php $all = getAllCandidates($db, $a['person_role']); ?>
                    <?php if ($all): ?>
                    <div style="font-size:0.75rem;color:#6c757d;margin-bottom:0.2rem;">No auto-match — pick from full <?= htmlspecialchars($a['person_role']) ?> list:</div>
                    <form method="POST" style="display:flex;gap:0.4rem;margin:0;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="map_alias">
                        <input type="hidden" name="alias_id" value="<?= (int)$a['id'] ?>">
                        <input type="hidden" name="return_role" value="<?= htmlspecialchars($roleFilter) ?>">
                        <select name="person_id" class="form-control form-control-sm" style="font-size:0.85rem;" required>
                            <option value="">Pick manually…</option>
                            <?php foreach ($all as $s): ?>
                            <option value="<?= (int)$s['id'] ?>">
                                <?= htmlspecialchars($s['full_name']) ?><?= $s['tch_id'] ? ' (' . htmlspecialchars($s['tch_id']) . ')' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($canEdit): ?>
                        <button class="btn btn-sm btn-primary" type="submit">Map</button>
                        <?php endif; ?>
                    </form>
                    <?php else: ?>
                        <span style="color:#6c757d;">No <?= htmlspecialchars($a['person_role']) ?> persons exist — create one →</span>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
            </td>
            <td>
            <?php if ($canEdit): ?>
                <?php if ($a['person_id']): ?>
                    <form method="POST" style="display:inline;margin:0;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="unmap_alias">
                        <input type="hidden" name="alias_id" value="<?= (int)$a['id'] ?>">
                        <input type="hidden" name="return_role" value="<?= htmlspecialchars($roleFilter) ?>">
                        <button class="btn btn-sm btn-outline" onclick="return confirm('Clear this mapping?')">Unmap</button>
                    </form>
                <?php else: ?>
                    <details>
                        <summary style="cursor:pointer;font-size:0.85rem;">+ Create canonical</summary>
                        <form method="POST" style="margin-top:0.4rem;display:grid;gap:0.3rem;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="create_and_map">
                            <input type="hidden" name="alias_id" value="<?= (int)$a['id'] ?>">
                            <input type="hidden" name="return_role" value="<?= htmlspecialchars($roleFilter) ?>">
                            <input type="hidden" name="new_role" value="<?= htmlspecialchars($a['person_role']) ?>">
                            <input name="new_first_name" class="form-control form-control-sm" placeholder="First name" required>
                            <input name="new_last_name"  class="form-control form-control-sm" placeholder="Last name"  required>
                            <button class="btn btn-sm btn-primary" type="submit">Create &amp; map</button>
                        </form>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($aliases)): ?>
        <tr><td colspan="5" style="text-align:center;color:#6c757d;padding:2rem;">No aliases for this filter.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
